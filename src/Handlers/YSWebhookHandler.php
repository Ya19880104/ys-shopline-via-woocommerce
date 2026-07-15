<?php
/**
 * Webhook Handler
 *
 * 統一 Webhook Handler（前身為 YS_Shopline_Webhook_Handler）。
 * 同時支援 REST API 和 WC API 端點，統一處理所有 Shopline Payment 事件。
 *
 * @package YangSheep\ShoplinePayment\Handlers
 */

declare(strict_types=1);

namespace YangSheep\ShoplinePayment\Handlers;

use YangSheep\ShoplinePayment\Utils\YSLogger;
use YangSheep\ShoplinePayment\Utils\YSOrderMeta;
use YangSheep\ShoplinePayment\Utils\YSSignatureVerifier;

defined( 'ABSPATH' ) || exit;

/**
 * Webhook 處理器
 *
 * 處理所有 Shopline Payment 傳入的 Webhook 請求。
 * 支援兩種端點格式：
 * - REST API: /wp-json/ys-shopline/v1/webhook
 * - WC API:   /?wc-api=ys-shopline-webhook 或 ys_shopline_webhook
 */
final class YSWebhookHandler {

    /** @var string WC API 端點（新格式） */
    public const WEBHOOK_ENDPOINT = 'ys-shopline-webhook';

    /** @var string WC API 端點（Legacy 格式） */
    private const LEGACY_ENDPOINT = 'ys_shopline_webhook';

    /**
     * 初始化處理器
     */
    public static function init(): void {
        $instance = new self();
        $instance->register_hooks();
    }

    /**
     * 註冊 Hooks
     */
    private function register_hooks(): void {
        // REST API 端點
        add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );

        // WC API 端點（同時相容新舊格式）
        add_action( 'woocommerce_api_' . self::WEBHOOK_ENDPOINT, [ $this, 'handle_wc_api_webhook' ] );
        add_action( 'woocommerce_api_' . self::LEGACY_ENDPOINT, [ $this, 'handle_wc_api_webhook' ] );
    }

    /**
     * 註冊 REST API 路由
     */
    public function register_rest_routes(): void {
        register_rest_route( 'ys-shopline/v1', '/webhook', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'handle_rest_webhook' ],
            'permission_callback' => '__return_true',
        ] );
    }

    /**
     * 處理 REST API Webhook
     *
     * @param \WP_REST_Request $request 請求物件
     * @return \WP_REST_Response
     */
    public function handle_rest_webhook( \WP_REST_Request $request ): \WP_REST_Response {
        $body = $request->get_body();

        // 從 request 取得簽章 headers
        $sign      = $request->get_header( 'sign' ) ?? '';
        $timestamp = $request->get_header( 'timestamp' ) ?? '';

        $result = $this->process_webhook( $body, $sign, $timestamp );

        $status_code = $result['success'] ? 200 : 400;
        return new \WP_REST_Response( $result, $status_code );
    }

    /**
     * 處理 WC API Webhook
     */
    public function handle_wc_api_webhook(): void {
        YSLogger::info( 'WC API Webhook 端點收到請求' );

        if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
            wp_die( 'Invalid request method', 'Shopline Webhook', [ 'response' => 405 ] );
        }

        $body    = file_get_contents( 'php://input' );
        $headers = $this->get_request_headers();

        $sign      = $headers['sign'] ?? '';
        $timestamp = $headers['timestamp'] ?? '';

        $result = $this->process_webhook( $body, $sign, $timestamp );

        status_header( $result['success'] ? 200 : 400 );
        echo wp_json_encode( $result );
        exit;
    }

    /**
     * 處理 Webhook（核心邏輯）
     *
     * @param string $body      請求內容
     * @param string $sign      簽章值
     * @param string $timestamp 時間戳
     * @return array{success: bool, message: string}
     */
    private function process_webhook( string $body, string $sign, string $timestamp ): array {
        YSLogger::info( 'Webhook 收到請求', [
            'body_length'   => strlen( $body ),
            'has_sign'      => ! empty( $sign ),
            'has_timestamp' => ! empty( $timestamp ),
        ] );

        // 解析 JSON
        $data = json_decode( $body, true );

        if ( json_last_error() !== JSON_ERROR_NONE ) {
            YSLogger::error( 'Webhook JSON 解析失敗', [ 'error' => json_last_error_msg() ] );
            return [ 'success' => false, 'message' => 'Invalid JSON' ];
        }

        // 驗證簽章
        if ( ! YSSignatureVerifier::verify_raw( $body, $sign, $timestamp ) ) {
            YSLogger::error( 'Webhook 簽章驗證失敗' );
            return [ 'success' => false, 'message' => 'Invalid signature' ];
        }

        // 取得事件類型（同時相容兩種格式）
        $event_type = $data['type'] ?? $data['eventType'] ?? '';
        $event_data = $data['data'] ?? $data;

        YSLogger::info( "Webhook 處理事件: {$event_type}" );

        try {
            $this->handle_event( $event_type, $event_data, $data );

            YSLogger::info( 'Webhook 處理完成', [ 'event_type' => $event_type ] );

            return [ 'success' => true, 'message' => 'OK' ];
        } catch ( \Throwable $e ) {
            YSLogger::error( "Webhook 處理失敗: {$e->getMessage()}", [
                'event_type' => $event_type,
            ] );
            return [ 'success' => false, 'message' => 'Internal processing error' ];
        }
    }

    /**
     * 處理事件
     *
     * 事件名稱依照 Shopline API 文件，同時保留舊格式相容：
     * - 付款: trade.succeeded, trade.failed, trade.cancelled
     * - 退款: trade.refund.succeeded, trade.refund.failed
     * - 綁卡: customer.instrument.binded, customer.instrument.unbinded
     * - 其他: trade.expired, trade.processing, trade.customer_action
     *
     * @param string $event_type 事件類型
     * @param array  $data       事件資料
     * @param array  $raw_event  原始事件（含 type/eventType 欄位）
     */
    private function handle_event( string $event_type, array $data, array $raw_event ): void {
        switch ( $event_type ) {
            // 付款成功
            case 'trade.succeeded':
            case 'payment.success':       // 舊格式相容
                $this->handle_trade_succeeded( $data );
                break;

            // 付款失敗
            case 'trade.failed':
            case 'payment.failed':        // 舊格式相容
                $this->handle_trade_failed( $data );
                break;

            // 授權成功
            case 'trade.authorized':
                $this->handle_trade_authorized( $data );
                break;

            // 請款成功
            case 'trade.captured':
            case 'manual.trade.capture.succeeded':
                $this->handle_trade_captured( $data );
                break;

            // 付款取消
            case 'trade.cancelled':
            case 'payment.cancelled':     // 舊格式相容
            case 'manual.trade.cancel.succeeded':
                $this->handle_payment_cancelled( $data );
                break;

            // 付款逾時
            case 'trade.expired':
                $this->handle_trade_expired( $data );
                break;

            // 付款處理中
            case 'trade.processing':
                $this->handle_trade_processing( $data );
                break;

            // 等待顧客付款確認（ATM 虛擬帳號 Confirm 完成後觸發）
            case 'trade.customer_action':
                $this->handle_trade_customer_action( $data );
                break;

            // 綁定付款工具（建立 WC Token）
            case 'customer.instrument.binded':
            case 'paymentInstrument.created': // 舊格式相容
                $this->handle_payment_instrument_created( $data );
                break;

            // 更新付款工具
            case 'customer.instrument.updated':
                YSLogger::info( '付款工具已更新', [ 'data' => \YangSheep\ShoplinePayment\Api\YSShoplineRequester::redact_sensitive( $data ) ] );
                break;

            // 解綁付款工具
            case 'customer.instrument.unbinded':
            case 'paymentInstrument.deleted':  // 舊格式相容
                $this->handle_payment_instrument_deleted( $data );
                break;

            // 退款成功
            case 'trade.refund.succeeded':
            case 'refund.succeeded':      // 舊格式相容
            case 'refund.success':        // 舊格式相容
                $this->handle_refund_succeeded( $data );
                break;

            // 退款失敗
            case 'trade.refund.failed':
            case 'refund.failed':         // 舊格式相容
                $this->handle_refund_failed( $data );
                break;

            default:
                YSLogger::info( "未處理的 Webhook 事件類型: {$event_type}" );
                break;
        }

        /**
         * Webhook 事件處理完成後觸發
         *
         * @param string $event_type 事件類型
         * @param array  $raw_event  原始事件資料
         */
        do_action( 'ys_shopline_webhook_processed', $event_type, $raw_event );
    }

    // ==========================================
    // 事件處理方法
    // ==========================================

    /**
     * Claim the payment completion lock and return a fresh order object.
     *
     * @param \WC_Order $order   Order object.
     * @param string    $context Webhook context for logs.
     * @return \WC_Order|null Fresh order if this request owns completion.
     */
    private function claim_payment_complete_lock( \WC_Order $order, string $context ): ?\WC_Order {
        $process_id = wp_generate_uuid4();
        $order->update_meta_data( YSOrderMeta::PAYMENT_COMPLETE_LOCK, $process_id );
        $order->save_meta_data();

        $fresh = wc_get_order( $order->get_id() );
        if ( ! $fresh ) {
            YSLogger::warning( 'Webhook: Payment complete lock could not reload order', array(
                'order_id' => $order->get_id(),
                'context'  => $context,
            ) );
            return null;
        }

        if ( (string) $fresh->get_meta( YSOrderMeta::PAYMENT_COMPLETE_LOCK ) !== $process_id ) {
            YSLogger::info( 'Webhook: Payment complete lock lost', array(
                'order_id' => $order->get_id(),
                'context'  => $context,
            ) );
            return null;
        }

        if ( $fresh->is_paid() ) {
            YSLogger::info( 'Webhook: Payment complete already completed', array(
                'order_id' => $fresh->get_id(),
                'context'  => $context,
            ) );
            return null;
        }

        return $fresh;
    }

    /**
     * 處理付款成功
     */
    private function handle_trade_succeeded( array $data ): void {
        $trade_order_id = $data['tradeOrderId'] ?? '';

        if ( ! $trade_order_id ) {
            return;
        }

        // v3.5.36 P0-A+：收斂 indeterminate（前次不明付款）——以 exact reference＋gateway＋金額幣別
        // 核對後接管交易（設 TRADE_ORDER_ID、解除封鎖），後續由 get_order_by_trade_id 正常入帳。
        $this->converge_indeterminate_paid( (string) $trade_order_id, $data );

        // v3.5.35 Review P1-2：命中已棄用交易 → 記重複收款警示、不覆寫現行交易 meta
        if ( $this->guard_abandoned_trade_paid( (string) $trade_order_id, $data ) ) {
            return;
        }

        $order = $this->get_order_for_paid_trade_webhook( $data );

        if ( ! $order ) {
            // v3.5.2: Codex F7 — 改走 context，讓 YSLogger 集中遮罩生效
            YSLogger::error( 'Webhook: 找不到對應訂單', array( 'trade_order_id' => $trade_order_id ) );
            return;
        }

        // 檢查是否已付款
        if ( $order->is_paid() ) {
            YSLogger::info( 'Webhook: 訂單已付款，跳過處理', array( 'order_id' => $order->get_id() ) );
            return;
        }

        // 完成付款流程
        $fresh = $this->claim_payment_complete_lock( $order, 'trade.succeeded' );
        if ( ! $fresh ) {
            return;
        }

        $order = $fresh;
        $order->payment_complete( $trade_order_id );

        // v3.5.11: 清除 PROCESSING/AUTHORIZED 中間態 meta
        // 訂閱續扣 / hitrust 3DS 中間態 → webhook 來確認後解除「等待 capture」狀態
        if ( 'yes' === $order->get_meta( YSOrderMeta::PAYMENT_AUTHORIZED_PENDING ) ) {
            $order->delete_meta_data( YSOrderMeta::PAYMENT_AUTHORIZED_PENDING );
            $order->add_order_note( __( 'Shopline 金流：webhook capture 完成，已授權中間態解除。', 'ys-shopline-via-woocommerce' ) );
        }

        $order->add_order_note(
            sprintf(
                /* translators: %s: Trade order ID */
                __( 'Shopline payment confirmed via webhook. Trade ID: %s', 'ys-shopline-via-woocommerce' ),
                $trade_order_id
            )
        );

        // 儲存付款資料
        $payment_method = $data['paymentMethod'] ?? ( $data['payment']['paymentMethod'] ?? null );
        if ( is_string( $payment_method ) && '' !== $payment_method ) {
            $order->update_meta_data( YSOrderMeta::PAYMENT_METHOD, $payment_method );
        }

        // v3.5.11: 不在此處呼叫 maybe_note_bind_card_not_persisted。
        //
        // 原因（codex review P2 race condition）：
        //   SHOPLINE 綁卡涉及兩個 webhook：
        //     1. trade.succeeded / payment.success (付款 webhook)
        //     2. customer.instrument.binded / paymentInstrument.created (綁卡 webhook)
        //   兩者非同時到。如果 #1 先到、#2 還沒到，這裡判斷 paymentInstrumentId 為空 →
        //   錯誤標記 BIND_CARD_NOT_PERSISTED；等 #2 來時 token 雖然會建好，但 admin
        //   notes 已經留錯誤警告。
        //
        // 改採：只在 redirect handler 同步流程（已有完整 GET response）寫 warning。
        // webhook 路徑不寫，由 trade.succeeded 觸發 sync_payment_token 自然處理 token 建立。

        $status = $this->normalize_payment_status( (string) ( $data['status'] ?? 'SUCCEEDED' ) );
        $order->update_meta_data( YSOrderMeta::TRADE_ORDER_ID, $trade_order_id );
        if ( ! empty( $data['referenceOrderId'] ) ) {
            $order->update_meta_data( YSOrderMeta::REFERENCE_ORDER_ID, (string) $data['referenceOrderId'] );
        }
        $order->update_meta_data( YSOrderMeta::PAYMENT_STATUS, $status );
        $order->update_meta_data( YSOrderMeta::PAYMENT_DETAIL, YSOrderMeta::sanitize_payment_detail( $data ) );
        $order->save();
    }

    /**
     * 處理付款失敗
     */
    private function handle_trade_failed( array $data ): void {
        $trade_order_id = $data['tradeOrderId'] ?? '';

        if ( ! $trade_order_id ) {
            return;
        }

        // v3.5.36 P0-A+：對應 indeterminate 訂單失敗＝該不明交易未收款 → exact reference 解除鎖定、允許重試
        $this->release_indeterminate_terminal( $data, 'FAILED' );

        $order = $this->get_order_by_trade_id( $trade_order_id );

        if ( ! $order || $order->is_paid() ) {
            return;
        }

        $raw_msg      = $data['errorMessage'] ?? ( $data['paymentMsg']['msg'] ?? __( 'Payment failed', 'ys-shopline-via-woocommerce' ) );
        $error_code   = $data['paymentMsg']['code'] ?? '';
        $friendly_msg = YSRedirectHandler::humanize_error_message( $raw_msg );

        $order->update_status( 'failed', $raw_msg );
        $order->update_meta_data( YSOrderMeta::PAYMENT_STATUS, 'FAILED' );
        $order->update_meta_data( YSOrderMeta::ERROR_CODE, $error_code );
        $order->update_meta_data( YSOrderMeta::ERROR_MESSAGE, $friendly_msg );
        $order->update_meta_data( YSOrderMeta::PAYMENT_DETAIL, YSOrderMeta::sanitize_payment_detail( $data ) );
        $order->save();
    }

    /**
     * 處理授權成功
     */
    private function handle_trade_authorized( array $data ): void {
        $trade_order_id = $data['tradeOrderId'] ?? '';

        if ( ! $trade_order_id ) {
            return;
        }

        $order = $this->get_order_by_trade_id( $trade_order_id );

        if ( ! $order ) {
            return;
        }

        if ( ! $order->is_paid() && 'on-hold' !== $order->get_status() ) {
            $order->update_status( 'on-hold', __( 'SHOPLINE 付款已授權，等待金流回傳確認。', 'ys-shopline-via-woocommerce' ) );
        }

        $order->add_order_note( __( 'Shopline payment authorized, awaiting capture.', 'ys-shopline-via-woocommerce' ) );
        $order->update_meta_data( YSOrderMeta::PAYMENT_STATUS, 'AUTHORIZED' );
        $order->update_meta_data( YSOrderMeta::PAYMENT_AUTHORIZED_PENDING, 'yes' );
        $order->update_meta_data( YSOrderMeta::PAYMENT_DETAIL, YSOrderMeta::sanitize_payment_detail( $data ) );
        $order->save();
    }

    /**
     * 處理請款成功
     */
    private function handle_trade_captured( array $data ): void {
        $trade_order_id = $data['tradeOrderId'] ?? '';

        if ( ! $trade_order_id ) {
            return;
        }

        // v3.5.36 P0-A+：收斂 indeterminate（前次不明付款）→ 接管交易，後續正常入帳。
        $this->converge_indeterminate_paid( (string) $trade_order_id, $data );

        // v3.5.35 Review P1-2：命中已棄用交易 → 記重複收款警示、不覆寫現行交易 meta
        if ( $this->guard_abandoned_trade_paid( (string) $trade_order_id, $data ) ) {
            return;
        }

        $order = $this->get_order_for_paid_trade_webhook( $data );

        if ( ! $order ) {
            return;
        }

        if ( ! $order->is_paid() ) {
            $fresh = $this->claim_payment_complete_lock( $order, 'trade.captured' );
            if ( ! $fresh ) {
                return;
            }

            $order = $fresh;
            $order->payment_complete( $trade_order_id );
        }

        if ( 'yes' === $order->get_meta( YSOrderMeta::PAYMENT_AUTHORIZED_PENDING ) ) {
            $order->delete_meta_data( YSOrderMeta::PAYMENT_AUTHORIZED_PENDING );
        }

        $order->add_order_note( __( 'Shopline payment captured.', 'ys-shopline-via-woocommerce' ) );
        $order->update_meta_data( YSOrderMeta::TRADE_ORDER_ID, $trade_order_id );
        if ( ! empty( $data['referenceOrderId'] ) ) {
            $order->update_meta_data( YSOrderMeta::REFERENCE_ORDER_ID, (string) $data['referenceOrderId'] );
        }
        $order->update_meta_data( YSOrderMeta::PAYMENT_STATUS, 'CAPTURED' );
        $order->update_meta_data( YSOrderMeta::PAYMENT_DETAIL, YSOrderMeta::sanitize_payment_detail( $data ) );
        $order->save();
    }

    /**
     * 處理付款取消
     */
    private function handle_payment_cancelled( array $data ): void {
        $trade_order_id = $data['tradeOrderId'] ?? '';

        if ( ! $trade_order_id ) {
            return;
        }

        $order = $this->get_order_by_trade_id( $trade_order_id );

        if ( ! $order ) {
            return;
        }

        // v3.5.34: 訂單已取消（例如取消 API 回 PROCESSING 後的最終 webhook）
        // → 只補付款狀態 meta 讓其收斂，不再動訂單狀態
        if ( 'cancelled' === $order->get_status() ) {
            $prior = strtoupper( (string) $order->get_meta( YSOrderMeta::PAYMENT_STATUS ) );
            if ( ! in_array( $prior, [ 'CANCELLED', 'CANCELED' ], true ) ) {
                $order->update_meta_data( YSOrderMeta::PAYMENT_STATUS, 'CANCELLED' );
                $order->update_meta_data( YSOrderMeta::PAYMENT_DETAIL, YSOrderMeta::sanitize_payment_detail( $data ) );
                $order->add_order_note( __( 'Shopline 金流：SHOPLINE 已確認交易取消（webhook）。', 'ys-shopline-via-woocommerce' ) );
                $order->save();
            }
            return;
        }

        if ( ! in_array( $order->get_status(), [ 'pending', 'on-hold' ], true ) ) {
            return;
        }

        $order->update_status( 'cancelled', __( 'Shopline payment cancelled by customer.', 'ys-shopline-via-woocommerce' ) );
        $order->update_meta_data( YSOrderMeta::PAYMENT_STATUS, 'CANCELLED' );
        $order->update_meta_data( YSOrderMeta::PAYMENT_DETAIL, YSOrderMeta::sanitize_payment_detail( $data ) );
        $order->save();
    }

    /**
     * 處理付款逾時
     */
    private function handle_trade_expired( array $data ): void {
        $trade_order_id = $data['tradeOrderId'] ?? '';

        if ( ! $trade_order_id ) {
            return;
        }

        // v3.5.36 P0-A+：對應 indeterminate 訂單過期＝該不明交易未收款 → exact reference 解除鎖定、允許重試
        $this->release_indeterminate_terminal( $data, 'EXPIRED' );

        $order = $this->get_order_by_trade_id( $trade_order_id );

        if ( ! $order || ! in_array( $order->get_status(), [ 'pending', 'on-hold' ], true ) ) {
            return;
        }

        $order->update_status( 'failed', __( 'Shopline payment expired.', 'ys-shopline-via-woocommerce' ) );
        $order->update_meta_data( YSOrderMeta::PAYMENT_STATUS, 'EXPIRED' );
        $order->update_meta_data( YSOrderMeta::PAYMENT_DETAIL, YSOrderMeta::sanitize_payment_detail( $data ) );
        $order->save();
    }

    /**
     * 處理等待顧客付款確認（ATM Confirm 後觸發）
     *
     * ATM 付款流程：SDK confirm 後 SHOPLINE 觸發此事件，攜帶虛擬帳號資訊。
     */
    private function handle_trade_customer_action( array $data ): void {
        $trade_order_id = $data['tradeOrderId'] ?? '';

        if ( ! $trade_order_id ) {
            return;
        }

        $order = $this->get_order_by_trade_id( $trade_order_id );

        if ( ! $order ) {
            YSLogger::info( '等待顧客付款確認（找不到訂單）', [ 'trade_order_id' => $trade_order_id ] );
            return;
        }

        // 嘗試儲存 ATM 虛擬帳號資訊
        $this->maybe_store_virtual_account_info( $order, $data );

        $order->update_meta_data( YSOrderMeta::PAYMENT_STATUS, 'CUSTOMER_ACTION' );
        $order->save();

        YSLogger::info( '等待顧客付款確認', [
            'order_id'       => $order->get_id(),
            'trade_order_id' => $trade_order_id,
        ] );
    }

    /**
     * 處理付款處理中
     */
    private function handle_trade_processing( array $data ): void {
        $trade_order_id = $data['tradeOrderId'] ?? '';

        if ( ! $trade_order_id ) {
            return;
        }

        $order = $this->get_order_by_trade_id( $trade_order_id );

        if ( ! $order || 'pending' !== $order->get_status() ) {
            return;
        }

        $sub_status = strtoupper( (string) ( $data['subStatus'] ?? '' ) );

        if ( in_array( $sub_status, array( 'AUTHORIZED', 'CAPTURING' ), true ) ) {
            $order->update_status( 'on-hold', __( 'SHOPLINE 付款已授權，等待金流回傳確認。', 'ys-shopline-via-woocommerce' ) );
            $order->update_meta_data( YSOrderMeta::PAYMENT_STATUS, 'AUTHORIZED' );
            $order->update_meta_data( YSOrderMeta::PAYMENT_AUTHORIZED_PENDING, 'yes' );
        } else {
            $order->update_status( 'on-hold', __( 'Shopline payment processing.', 'ys-shopline-via-woocommerce' ) );
            $order->update_meta_data( YSOrderMeta::PAYMENT_STATUS, 'PROCESSING' );
        }

        $order->update_meta_data( YSOrderMeta::PAYMENT_DETAIL, YSOrderMeta::sanitize_payment_detail( $data ) );

        // 嘗試儲存 ATM 虛擬帳號資訊
        $this->maybe_store_virtual_account_info( $order, $data );

        $order->save();
    }

    /**
     * 處理綁定付款工具（建立 WC Token）
     *
     * API 文件結構: data.customerId, data.paymentInstrument.instrumentId, data.paymentInstrument.instrumentCard
     */
    private function handle_payment_instrument_created( array $data ): void {
        $pi                    = $data['paymentInstrument'] ?? [];
        $payment_instrument_id = $pi['instrumentId'] ?? '';
        $payment_customer_id   = $data['customerId'] ?? '';
        $card_info             = $pi['instrumentCard'] ?? [];

        // v3.5.2: Codex F5 — is_scalar guard，避免 malformed payload 觸發 Array to string conversion warning
        $raw_last  = is_array( $card_info ) && isset( $card_info['last'] ) ? $card_info['last'] : '';
        $raw_brand = is_array( $card_info ) && isset( $card_info['brand'] ) ? $card_info['brand'] : '';
        YSLogger::info( 'BindCard webhook: customer.instrument.binded', array(
            'customer_id'   => $payment_customer_id,
            'instrument_id' => $payment_instrument_id,
            'last4'         => is_scalar( $raw_last ) ? (string) $raw_last : '(non-scalar)',
            'brand'         => is_scalar( $raw_brand ) ? (string) $raw_brand : '(non-scalar)',
        ) );

        if ( ! $payment_instrument_id || ! $payment_customer_id ) {
            return;
        }

        // 透過 Customer ID 找到使用者
        $users = get_users( [
            'meta_key'   => YSOrderMeta::CUSTOMER_ID,
            'meta_value' => $payment_customer_id,
            'number'     => 1,
        ] );

        if ( empty( $users ) ) {
            YSLogger::error( 'Webhook: 找不到對應使用者', array( 'customer_id' => $payment_customer_id ) );
            return;
        }

        $user_id = $users[0]->ID;

        // 檢查 Token 是否已存在
        $existing_tokens = \WC_Payment_Tokens::get_customer_tokens( $user_id, YSOrderMeta::CREDIT_GATEWAY_ID );

        foreach ( $existing_tokens as $existing_token ) {
            if ( $existing_token->get_token() === $payment_instrument_id ) {
                return; // 已存在，跳過
            }
        }

        // v3.5.1: 共用 normalizer 統一驗證
        $normalized = \YangSheep\ShoplinePayment\Customer\YSCustomer::normalize_card_payload( $card_info );
        if ( ! $normalized ) {
            YSLogger::warning( 'Webhook: instrumentCard could not be normalized, skipping token creation', array(
                'user_id'       => $user_id,
                'instrument_id' => $payment_instrument_id,
            ) );
            return;
        }

        try {
            $token = new \WC_Payment_Token_CC();
            $token->set_token( $payment_instrument_id );
            $token->set_gateway_id( YSOrderMeta::CREDIT_GATEWAY_ID );
            $token->set_card_type( $normalized['brand'] );
            $token->set_last4( $normalized['last4'] );
            $token->set_expiry_month( $normalized['expiry_month'] );
            $token->set_expiry_year( $normalized['expiry_year'] );
            $token->set_user_id( $user_id );

            if ( empty( $existing_tokens ) ) {
                $token->set_default( true );
            }

            if ( $token->save() ) {
                YSLogger::info( 'Webhook: Payment Token created', array(
                    'user_id'       => $user_id,
                    'token_id'      => $token->get_id(),
                    'instrument_id' => $payment_instrument_id,
                ) );
            } else {
                YSLogger::error( 'Webhook: token->save() returned false', array(
                    'user_id'       => $user_id,
                    'instrument_id' => $payment_instrument_id,
                ) );
            }
        } catch ( \Throwable $e ) {
            YSLogger::error( 'Webhook: token creation threw', array(
                'user_id'       => $user_id,
                'instrument_id' => $payment_instrument_id,
                'error'         => $e->getMessage(),
            ) );
            return;
        }

        // 綁卡成功後，更新尚未綁定 instrument 的 subscription
        $this->update_pending_subscriptions_instrument( $user_id, $payment_instrument_id );
    }

    /**
     * 更新尚未綁定 instrument ID 的 subscription。
     *
     * 在綁卡 Webhook 觸發時，將新的 instrument ID 寫入
     * 使用本閘道但尚未設定 instrument 的 subscription。
     *
     * @param int    $user_id       WordPress user ID.
     * @param string $instrument_id Shopline payment instrument ID.
     */
    private function update_pending_subscriptions_instrument( int $user_id, string $instrument_id ): void {
        if ( ! function_exists( 'wcs_get_users_subscriptions' ) ) {
            return;
        }

        $subscriptions = wcs_get_users_subscriptions( $user_id );

        foreach ( $subscriptions as $subscription ) {
            if ( 'ys_shopline_credit_subscription' !== $subscription->get_payment_method() ) {
                continue;
            }

            // 只更新尚未綁定的 subscription
            if ( ! empty( $subscription->get_meta( YSOrderMeta::PAYMENT_INSTRUMENT_ID ) ) ) {
                continue;
            }

            $subscription->update_meta_data( YSOrderMeta::PAYMENT_INSTRUMENT_ID, $instrument_id );
            $subscription->save();

            YSLogger::info( 'Webhook: 更新 subscription 的 instrument ID', [
                'subscription_id' => $subscription->get_id(),
                'instrument_id'   => $instrument_id,
            ] );
        }
    }

    /**
     * 處理解綁付款工具
     *
     * API 文件結構: data.paymentInstrument.instrumentId
     */
    private function handle_payment_instrument_deleted( array $data ): void {
        $pi                    = $data['paymentInstrument'] ?? [];
        $payment_instrument_id = $pi['instrumentId'] ?? '';

        if ( ! $payment_instrument_id ) {
            return;
        }

        global $wpdb;

        $token_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT token_id FROM {$wpdb->prefix}woocommerce_payment_tokens WHERE token = %s AND gateway_id LIKE %s",
                $payment_instrument_id,
                'ys_shopline_%'
            )
        );

        if ( $token_id ) {
            \WC_Payment_Tokens::delete( (int) $token_id );
            // v3.4.15: instrument_id 屬敏感識別碼，log 只保留末 6 碼
            YSLogger::info( 'Webhook: Payment Token deleted', array(
                'token_id'      => (int) $token_id,
                'instrument_id' => '*' . substr( (string) $payment_instrument_id, -6 ),
            ) );
        }
    }

    /**
     * 處理退款成功
     */
    private function handle_refund_succeeded( array $data ): void {
        $refund_order_id = $data['refundOrderId'] ?? $data['refundId'] ?? '';
        $trade_order_id  = $data['tradeOrderId'] ?? '';

        if ( ! $trade_order_id ) {
            return;
        }

        $order = $this->get_order_by_trade_id( $trade_order_id );

        if ( ! $order ) {
            return;
        }

        $order->add_order_note(
            sprintf(
                /* translators: %s: Refund order ID */
                __( 'Shopline refund confirmed via webhook. Refund ID: %s', 'ys-shopline-via-woocommerce' ),
                $refund_order_id
            )
        );
    }

    /**
     * 處理退款失敗
     */
    private function handle_refund_failed( array $data ): void {
        $trade_order_id = $data['tradeOrderId'] ?? '';

        if ( ! $trade_order_id ) {
            return;
        }

        $order = $this->get_order_by_trade_id( $trade_order_id );

        if ( ! $order ) {
            return;
        }

        $error_message = $data['errorMessage'] ?? __( 'Refund failed', 'ys-shopline-via-woocommerce' );

        $order->add_order_note(
            __( 'Shopline refund failed: ', 'ys-shopline-via-woocommerce' ) . $error_message
        );

        YSLogger::error( 'Refund failed for order: ' . $order->get_id(), [ 'error' => $error_message ] );
    }

    // ==========================================
    // 輔助方法
    // ==========================================

    /**
     * 嘗試從 API 回應或 Webhook 資料中提取並儲存 ATM 虛擬帳號資訊。
     *
     * 僅在訂單為 ATM 付款方式且尚未儲存帳號時才寫入。
     * 支援多種資料路徑：payment.virtualAccount 或 virtualAccount（根層級）。
     *
     * @param \WC_Order $order 訂單物件
     * @param array     $data  API 回應或 Webhook 事件資料
     */
    private function maybe_store_virtual_account_info( \WC_Order $order, array $data ): void {
        if ( 'ys_shopline_atm' !== $order->get_payment_method() ) {
            return;
        }

        // 已有帳號，不覆蓋
        if ( $order->get_meta( YSOrderMeta::VA_ACCOUNT ) ) {
            return;
        }

        // 嘗試多種路徑取得 VA 資料
        $va = $data['payment']['virtualAccount']
            ?? $data['virtualAccount']
            ?? null;

        if ( ! $va || empty( $va['recipientAccountNum'] ) ) {
            YSLogger::debug( 'Webhook: ATM 訂單但無虛擬帳號資料', [
                'order_id'     => $order->get_id(),
                'data_keys'    => array_keys( $data ),
                'payment_keys' => isset( $data['payment'] ) ? array_keys( $data['payment'] ) : [],
            ] );
            return;
        }

        $bank_code = $va['recipientBankCode'] ?? '';
        $account   = $va['recipientAccountNum'] ?? '';
        $expire    = $va['dueDate'] ?? '';

        $order->update_meta_data( YSOrderMeta::VA_BANK_CODE, $bank_code );
        $order->update_meta_data( YSOrderMeta::VA_ACCOUNT, $account );
        $order->update_meta_data( YSOrderMeta::VA_EXPIRE, $expire );

        // 寫入訂單備註
        $note_parts = [];
        if ( $bank_code ) {
            $note_parts[] = sprintf( __( '銀行代碼：%s', 'ys-shopline-via-woocommerce' ), $bank_code );
        }
        $note_parts[] = sprintf( __( '虛擬帳號：%s', 'ys-shopline-via-woocommerce' ), $account );
        $note_parts[] = sprintf( __( '轉帳金額：%s', 'ys-shopline-via-woocommerce' ), $order->get_formatted_order_total() );
        if ( $expire ) {
            $note_parts[] = sprintf( __( '繳費期限：%s', 'ys-shopline-via-woocommerce' ), $expire );
        }

        $order->add_order_note(
            __( 'ATM 虛擬帳號已產生（Webhook）', 'ys-shopline-via-woocommerce' ) . "\n" . implode( "\n", $note_parts )
        );

        YSLogger::info( 'Webhook: ATM 虛擬帳號資訊已儲存', [
            'order_id'  => $order->get_id(),
            'bank_code' => $bank_code,
            'account'   => $account ? substr( $account, 0, 4 ) . '****' : '',
            'expire'    => $expire,
        ] );
    }

    /**
     * 透過 Trade ID 查詢訂單
     *
     * @param string $trade_order_id Shopline 交易 ID
     * @return \WC_Order|null
     */
    private function get_order_by_trade_id( string $trade_order_id ): ?\WC_Order {
        $orders = wc_get_orders( [
            'limit'      => 1,
            'meta_key'   => YSOrderMeta::TRADE_ORDER_ID,
            'meta_value' => $trade_order_id,
        ] );

        return ! empty( $orders ) ? $orders[0] : null;
    }

    /**
     * Resolve an order for a paid trade webhook.
     *
     * ATM customers can pay an older virtual account after the order has been
     * re-issued with a newer trade id. Only paid events use reference fallback;
     * failed/expired/cancelled old attempts must not affect the current attempt.
     *
     * @param array $data Webhook payload.
     * @return \WC_Order|null
     */
    /**
     * v3.5.35 Review P1-2：攔截「已棄用交易」的付款成功／請款通知。
     *
     * 顧客未完成的舊交易被 resolve_prior_trade 棄用（歸檔進
     * ABANDONED_TRADE_IDS）後，若顧客事後仍完成該筆舊流程，其 paid webhook
     * 會攜帶舊 tradeOrderId：
     *   - 舊 tradeOrderId 已非現行交易（TRADE_ORDER_ID 已清），get_order_by_trade_id 查無；
     *   - 非 ATM 會被 reference fallback 拒絕而「靜默丟棄」；
     *   - ATM 反而可能經 fallback 入帳 → 覆寫現行交易 meta / 重複標記已付款。
     * 一律在此攔下（v3.5.36 Review 強化）：以 referenceOrderId 反推訂單、比對歸檔清單，命中則：
     *   - 冪等前置：重送同一筆 → 只記 INFO、不重複 note/告警/ERROR log；
     *   - 區分「有現行交易(純重複→退舊)」vs「無現行交易(顧客付了但訂單無現行交易→更急)」；
     *   - 持久化人工審核旗標（後台可追蹤/查詢）＋分流 note＋ERROR log；
     *   - **不觸發 payment_complete、不覆寫現行交易**，保留人工入帳/退款判斷。
     *
     * @param string $trade_order_id 傳入交易 ID。
     * @param array  $data           Webhook payload。
     * @return bool 是否為已棄用交易（true 代表呼叫端應提前 return，不做一般處理）。
     */
    private function guard_abandoned_trade_paid( string $trade_order_id, array $data ): bool {
        $order = $this->find_order_with_abandoned_trade( $trade_order_id, $data );
        if ( ! $order ) {
            return false;
        }

        // 冪等/backfill 前置：只有「這筆棄用交易已建立過人工審核明細」才跳過。
        // v3.5.35 只寫了 ABANDONED_TRADE_PAID_IDS 而無 MANUAL_REVIEW_DATA（例如 #11824），
        // 那類「在 paid-list 但缺 review data」要補寫一次（backfill），不能提早 return。
        $review_data = (array) $order->get_meta( YSOrderMeta::MANUAL_REVIEW_DATA );
        foreach ( $review_data as $rd ) {
            if ( is_array( $rd ) && isset( $rd['abandoned_trade'] ) && (string) $rd['abandoned_trade'] === $trade_order_id ) {
                YSLogger::info( 'Webhook: abandoned-trade paid notice re-sent, already reviewed (idempotent skip)', array(
                    'order_id'        => $order->get_id(),
                    'abandoned_trade' => $trade_order_id,
                ) );
                return true;
            }
        }

        $current_trade = (string) $order->get_meta( YSOrderMeta::TRADE_ORDER_ID );
        $has_current   = ( '' !== $current_trade && $current_trade !== $trade_order_id );
        $already_paid  = $order->is_paid();

        // v3.5.36 三分類（用 already_paid 區分，避免把「現行交易只是待付款」誤導成退舊交易）：
        if ( $already_paid ) {
            $review_type = 'duplicate_paid';               // 現行已付款＋棄用也收款＝重複收款
        } elseif ( $has_current ) {
            // v3.5.36 Review P1-4：僅知「訂單另有一筆交易」，其狀態（pending/failed/authorized…）未查證，
            // 不可宣稱「現行待付款/未收款」以免誤導退款。中性分類，要求人工核對現行交易狀態。
            $review_type = 'abandoned_paid_current_exists';
        } else {
            $review_type = 'paid_no_current_trade';          // 棄用是唯一收款、訂單未入帳
        }

        // 持久化：已收款棄用清單（去重）＋人工審核旗標＋結構化明細（後台可追蹤/查詢）；新事件清 resolved
        $paid_abandoned   = (array) $order->get_meta( YSOrderMeta::ABANDONED_TRADE_PAID_IDS );
        $paid_abandoned[] = $trade_order_id;
        $order->update_meta_data( YSOrderMeta::ABANDONED_TRADE_PAID_IDS, array_values( array_unique( array_filter( $paid_abandoned ) ) ) );

        $review_data[] = array(
            'type'            => $review_type,
            'abandoned_trade' => $trade_order_id,
            'current_trade'   => $current_trade,
            'order_paid'      => $already_paid ? 'yes' : 'no',
            'ts'              => time(),
        );
        $order->update_meta_data( YSOrderMeta::MANUAL_REVIEW_DATA, $review_data );
        $order->update_meta_data( YSOrderMeta::MANUAL_REVIEW_FLAG, $review_type );
        $order->delete_meta_data( YSOrderMeta::MANUAL_REVIEW_RESOLVED );

        if ( 'duplicate_paid' === $review_type ) {
            $note = sprintf(
                /* translators: 1: abandoned trade id, 2: current trade id */
                __( '🔴 Shopline 金流【需人工處理・重複收款】：現行交易 %2$s 已付款，棄用交易 %1$s 事後也完成收款＝重複收款。**已阻擋自動入帳**——請對「棄用交易 %1$s」辦理退款。', 'ys-shopline-via-woocommerce' ),
                $trade_order_id,
                $current_trade
            );
        } elseif ( 'abandoned_paid_current_exists' === $review_type ) {
            $note = sprintf(
                /* translators: 1: abandoned trade id, 2: current trade id */
                __( '🔴 Shopline 金流【需人工處理・棄用實收/現行另有交易】：棄用交易 %1$s 已實際收款，訂單另有一筆現行交易 %2$s（**其狀態請人工至 SHOPLINE 後台核對**）。棄用交易才是已確認的實際收款——**請勿盲目退舊**；請核對現行交易 %2$s 實際狀態後，決定以哪一筆入帳、另一筆退款。', 'ys-shopline-via-woocommerce' ),
                $trade_order_id,
                $current_trade
            );
        } else {
            $note = sprintf(
                /* translators: %s: abandoned trade id */
                __( '🔴 Shopline 金流【需人工處理・已付款未入帳】：訂單目前無現行有效交易，但棄用交易 %s 事後已完成收款。顧客實際已付款、訂單卻未入帳。**本外掛不自動 payment_complete**——請人工決定：確認入帳或辦理退款。', 'ys-shopline-via-woocommerce' ),
                $trade_order_id
            );
        }
        $order->add_order_note( $note );
        $order->save();

        YSLogger::error( 'Webhook: abandoned trade paid, manual review required', array(
            'order_id'           => $order->get_id(),
            'abandoned_trade'    => $trade_order_id,
            'current_trade'      => $current_trade,
            'review_type'        => $review_type,
            'order_already_paid' => $already_paid ? 'yes' : 'no',
        ) );

        return true;
    }

    /**
     * v3.5.35 Review P1-2：以 referenceOrderId 反推訂單，判斷傳入交易是否為其歸檔的棄用交易。
     *
     * referenceOrderId 格式為 {order_id}_{attempt}（見 generate_reference_order_id），
     * 直接反推 order id 載入訂單，再比對 ABANDONED_TRADE_IDS，避免對序列化 meta 做 LIKE 查詢。
     *
     * @param string $trade_order_id 傳入交易 ID。
     * @param array  $data           Webhook payload。
     * @return \WC_Order|null 命中則回傳該訂單，否則 null。
     */
    private function find_order_with_abandoned_trade( string $trade_order_id, array $data ): ?\WC_Order {
        if ( '' === $trade_order_id ) {
            return null;
        }

        $reference = (string) ( $data['referenceOrderId'] ?? '' );
        $order_id  = $this->extract_order_id_from_reference_order_id( $reference );
        if ( ! $order_id ) {
            return null;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return null;
        }

        $abandoned = (array) $order->get_meta( YSOrderMeta::ABANDONED_TRADE_IDS );
        return in_array( $trade_order_id, $abandoned, true ) ? $order : null;
    }

    /**
     * v3.5.36 P0-A+：收斂 indeterminate（前次不明付款）訂單的收款 webhook（全金流，不限 ATM）。
     *
     * 以 exact referenceOrderId 命中 INDETERMINATE_REF，核對 gateway＋金額＋幣別後接管：
     * 設 TRADE_ORDER_ID＝本次交易、清除封鎖標記；後續由 get_order_by_trade_id 正常入帳。
     * 核對不符則不接管（fail-closed），避免張冠李戴。
     *
     * @param string $trade_order_id 交易 ID。
     * @param array  $data           Webhook payload。
     * @return void
     */
    private function converge_indeterminate_paid( string $trade_order_id, array $data ): void {
        $order = $this->find_indeterminate_order( $data );
        if ( ! $order ) {
            return;
        }
        if ( ! $this->validate_indeterminate_match( $order, $data ) ) {
            return;
        }

        $order->update_meta_data( YSOrderMeta::TRADE_ORDER_ID, $trade_order_id );
        $order->delete_meta_data( YSOrderMeta::INDETERMINATE_REF );
        $order->delete_meta_data( YSOrderMeta::INDETERMINATE_DATA );
        if ( 'INDETERMINATE' === (string) $order->get_meta( YSOrderMeta::PAYMENT_STATUS ) ) {
            $order->delete_meta_data( YSOrderMeta::PAYMENT_STATUS );
        }
        $order->add_order_note( sprintf(
            /* translators: %s: adopted trade id */
            __( 'Shopline 金流：webhook 確認前次不明付款已建立並收款（交易 %s），已接管入帳。', 'ys-shopline-via-woocommerce' ),
            $trade_order_id
        ) );
        $order->save();
        YSLogger::info( 'Webhook: converged indeterminate order via exact reference', array(
            'order_id'       => $order->get_id(),
            'trade_order_id' => $trade_order_id,
        ) );
    }

    /**
     * v3.5.36 P0-A+：indeterminate 訂單的對應交易終結（failed/expired）→ 解除封鎖、允許重試。
     *
     * @param array  $data  Webhook payload。
     * @param string $event 終結事件（FAILED/EXPIRED）。
     * @return void
     */
    private function release_indeterminate_terminal( array $data, string $event ): void {
        $order = $this->find_indeterminate_order( $data );
        if ( ! $order ) {
            return;
        }

        $order->delete_meta_data( YSOrderMeta::INDETERMINATE_REF );
        $order->delete_meta_data( YSOrderMeta::INDETERMINATE_DATA );
        if ( 'INDETERMINATE' === (string) $order->get_meta( YSOrderMeta::PAYMENT_STATUS ) ) {
            $order->delete_meta_data( YSOrderMeta::PAYMENT_STATUS );
        }
        $order->add_order_note( sprintf(
            /* translators: %s: terminal event */
            __( 'Shopline 金流：webhook 確認前次不明付款交易已 %s（未收款），已解除鎖定、可重新付款。', 'ys-shopline-via-woocommerce' ),
            $event
        ) );
        $order->save();
        YSLogger::info( 'Webhook: released indeterminate lock on terminal event', array(
            'order_id' => $order->get_id(),
            'event'    => $event,
        ) );
    }

    /**
     * v3.5.36 P0-A+：以 exact referenceOrderId 反查「處於 indeterminate 封鎖」的訂單。
     *
     * @param array $data Webhook payload。
     * @return \WC_Order|null
     */
    private function find_indeterminate_order( array $data ): ?\WC_Order {
        $reference = (string) ( $data['referenceOrderId'] ?? '' );
        if ( '' === $reference ) {
            return null;
        }
        $order_id = $this->extract_order_id_from_reference_order_id( $reference );
        if ( ! $order_id ) {
            return null;
        }
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return null;
        }
        $marker = (string) $order->get_meta( YSOrderMeta::INDETERMINATE_REF );
        return ( '' !== $marker && $marker === $reference ) ? $order : null;
    }

    /**
     * v3.5.36 P0-A+：接管 indeterminate 交易前核對 gateway＋金額＋幣別（fail-closed）。
     *
     * @param \WC_Order $order 訂單。
     * @param array     $data  Webhook payload。
     * @return bool
     */
    private function validate_indeterminate_match( \WC_Order $order, array $data ): bool {
        $meta       = (array) $order->get_meta( YSOrderMeta::INDETERMINATE_DATA );
        $exp_method = isset( $meta['shopline_method'] ) ? (string) $meta['shopline_method'] : '';
        $exp_amt    = isset( $meta['amount'] ) ? (int) $meta['amount'] : 0;
        $exp_cur    = isset( $meta['currency'] ) ? (string) $meta['currency'] : '';

        // v3.5.36 Review P2：核對「預期 SHOPLINE payment method」與 webhook payload 的 payment.paymentMethod
        //（非訂單本地 WC gateway；兩者詞彙不同）。
        $webhook_method = (string) ( $data['paymentMethod'] ?? ( $data['payment']['paymentMethod'] ?? '' ) );
        // v3.5.36 Review P1：官方成功 webhook 必含 payment.paymentMethod；缺失視為 malformed → 拒絕，
        // 不得僅憑 reference／金額／幣別就清 marker 並入帳。
        if ( '' === $webhook_method ) {
            YSLogger::warning( 'Indeterminate converge rejected: webhook missing paymentMethod (malformed)', array(
                'order_id' => $order->get_id(),
            ) );
            return false;
        }
        if ( '' !== $exp_method && $exp_method !== $webhook_method ) {
            YSLogger::warning( 'Indeterminate converge rejected: payment method mismatch', array(
                'order_id'        => $order->get_id(),
                'expected_method' => $exp_method,
                'webhook_method'  => $webhook_method,
            ) );
            return false;
        }

        $amount_value = $data['payment']['paidAmount']['value'] ?? ( $data['order']['amount']['value'] ?? null );
        $currency     = (string) ( $data['payment']['paidAmount']['currency'] ?? ( $data['order']['amount']['currency'] ?? '' ) );
        if ( ! is_numeric( $amount_value ) || '' === $currency ) {
            YSLogger::warning( 'Indeterminate converge rejected: missing amount/currency', array( 'order_id' => $order->get_id() ) );
            return false;
        }
        if ( (int) $amount_value !== $exp_amt || $currency !== $exp_cur ) {
            YSLogger::warning( 'Indeterminate converge rejected: amount/currency mismatch', array(
                'order_id'  => $order->get_id(),
                'expected'  => $exp_amt . $exp_cur,
                'got'       => $amount_value . $currency,
            ) );
            return false;
        }
        return true;
    }

    private function get_order_for_paid_trade_webhook( array $data ): ?\WC_Order {
        $trade_order_id = (string) ( $data['tradeOrderId'] ?? '' );
        if ( '' !== $trade_order_id ) {
            $order = $this->get_order_by_trade_id( $trade_order_id );
            if ( $order ) {
                return $order;
            }
        }

        $reference_order_id = (string) ( $data['referenceOrderId'] ?? '' );
        if ( '' === $reference_order_id ) {
            return null;
        }

        $order = $this->get_order_by_reference_order_id( $reference_order_id );
        if ( ! $order ) {
            return null;
        }

        // v3.5.34: fallback 只憑 reference 前綴命中（舊 ATM 入帳情境），
        // 入帳前必須核對歸屬（gateway）與金額/幣別，否則拒絕自動入帳
        if ( ! $this->validate_fallback_paid_order( $order, $data, $reference_order_id ) ) {
            return null;
        }

        return $order;
    }

    /**
     * 核對 reference fallback 命中的訂單可否接受此筆入帳。
     *
     * 訂單金額可能曾被後台修改、reference 也可能屬於他站/他外掛，
     * 只驗前綴就 payment_complete() 會把錯誤金額的訂單標成已付款。
     * fail-closed：webhook 未帶金額或任一核對不符 → 拒絕（留 ERROR + 訂單備註供人工核對）。
     *
     * @param \WC_Order $order              fallback 命中的訂單。
     * @param array     $data               Webhook payload。
     * @param string    $reference_order_id webhook 的 referenceOrderId。
     * @return bool
     */
    private function validate_fallback_paid_order( \WC_Order $order, array $data, string $reference_order_id ): bool {
        $context = [
            'order_id'           => $order->get_id(),
            'reference_order_id' => $reference_order_id,
        ];

        // 1. 必須是本外掛的訂單
        if ( 0 !== strpos( (string) $order->get_payment_method(), 'ys_shopline' ) ) {
            YSLogger::error( 'Webhook fallback rejected: order not paid via ys_shopline gateway', $context + [
                'gateway' => (string) $order->get_payment_method(),
            ] );
            return false;
        }

        // 2. 此 fallback 僅服務「舊 ATM 虛擬帳號入帳」情境（重開新交易後客戶匯舊帳號）
        //    → 非 VirtualAccount 一律拒絕走 fallback
        $payment_method = (string) ( $data['paymentMethod'] ?? ( $data['payment']['paymentMethod'] ?? '' ) );
        if ( 'VirtualAccount' !== $payment_method ) {
            YSLogger::error( 'Webhook fallback rejected: not a VirtualAccount payment', $context + [
                'payment_method' => $payment_method,
            ] );
            return false;
        }

        // 3. 金額核對（官方 trade.succeeded 電文金額＝payment.paidAmount，備援 order.amount；單位「分」）。
        //    未帶金額或幣別一律拒絕，fail-closed。
        $amount_value = $data['payment']['paidAmount']['value'] ?? ( $data['order']['amount']['value'] ?? null );
        $currency     = (string) ( $data['payment']['paidAmount']['currency'] ?? ( $data['order']['amount']['currency'] ?? '' ) );
        $expected     = (int) round( (float) $order->get_total() * 100 );

        if ( ! is_numeric( $amount_value ) || '' === $currency ) {
            YSLogger::error( 'Webhook fallback rejected: no amount/currency in payload to verify against', $context + [
                'has_amount'   => is_numeric( $amount_value ) ? 'yes' : 'no',
                'has_currency' => '' !== $currency ? 'yes' : 'no',
            ] );
            $order->add_order_note( sprintf(
                /* translators: %s: reference order id */
                __( 'Shopline 金流：⚠️ 收到無金額/幣別資訊的付款通知（reference %s，以舊帳號比對命中），已拒絕自動入帳，請人工核對。', 'ys-shopline-via-woocommerce' ),
                $reference_order_id
            ) );
            $order->save();
            return false;
        }

        if ( (int) $amount_value !== $expected || $order->get_currency() !== $currency ) {
            YSLogger::error( 'Webhook fallback rejected: amount/currency mismatch', $context + [
                'webhook_amount'  => (int) $amount_value,
                'expected_amount' => $expected,
                'webhook_currency' => $currency,
                'order_currency'  => $order->get_currency(),
            ] );
            $order->add_order_note( sprintf(
                /* translators: 1: webhook amount, 2: order amount, 3: reference order id */
                __( 'Shopline 金流：⚠️ 付款通知金額（%1$s 分）與訂單金額（%2$s 分）不符（reference %3$s），已拒絕自動入帳，請人工核對此筆款項。', 'ys-shopline-via-woocommerce' ),
                (string) (int) $amount_value,
                (string) $expected,
                $reference_order_id
            ) );
            $order->save();
            return false;
        }

        return true;
    }

    /**
     * Resolve a WooCommerce order from the plugin-generated referenceOrderId.
     *
     * @param string $reference_order_id SHOPLINE referenceOrderId.
     * @return \WC_Order|null
     */
    private function get_order_by_reference_order_id( string $reference_order_id ): ?\WC_Order {
        $order_id = $this->extract_order_id_from_reference_order_id( $reference_order_id );
        if ( ! $order_id ) {
            return null;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return null;
        }

        YSLogger::info( 'Webhook: resolved paid trade by referenceOrderId fallback', [
            'order_id'           => $order_id,
            'reference_order_id' => $reference_order_id,
        ] );

        return $order;
    }

    /**
     * Extract the WooCommerce order id from references like 2486_2.
     *
     * @param string $reference_order_id SHOPLINE referenceOrderId.
     * @return int
     */
    private function extract_order_id_from_reference_order_id( string $reference_order_id ): int {
        if ( ! preg_match( '/^(\d+)_(\d+)$/', $reference_order_id, $matches ) ) {
            return 0;
        }

        return (int) $matches[1];
    }

    /**
     * Normalize webhook payment status and keep legacy compatibility.
     *
     * @param string $status Raw status.
     * @return string
     */
    private function normalize_payment_status( string $status ): string {
        $normalized = strtoupper( trim( $status ) );
        return 'SUCCESS' === $normalized ? 'SUCCEEDED' : $normalized;
    }

    /**
     * Read HTTP headers with broad server compatibility.
     *
     * @return array<string, string>
     */
    private function get_request_headers(): array {
        if ( function_exists( 'getallheaders' ) ) {
            return array_change_key_case( getallheaders(), CASE_LOWER );
        }

        $headers = [];
        foreach ( $_SERVER as $key => $value ) {
            if ( str_starts_with( $key, 'HTTP_' ) ) {
                $header_name             = str_replace( '_', '-', strtolower( substr( $key, 5 ) ) );
                $headers[ $header_name ] = $value;
            }
        }

        return $headers;
    }

    /**
     * 取得 Webhook URL
     *
     * @return string
     */
    public static function get_webhook_url(): string {
        return rest_url( 'ys-shopline/v1/webhook' );
    }
}
