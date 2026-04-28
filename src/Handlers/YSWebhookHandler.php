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
     * 處理付款成功
     */
    private function handle_trade_succeeded( array $data ): void {
        $trade_order_id = $data['tradeOrderId'] ?? '';

        if ( ! $trade_order_id ) {
            return;
        }

        $order = $this->get_order_by_trade_id( $trade_order_id );

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
        $order->update_meta_data( YSOrderMeta::PAYMENT_STATUS, $status );
        $order->update_meta_data( YSOrderMeta::PAYMENT_DETAIL, $data );
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
        $order->update_meta_data( YSOrderMeta::PAYMENT_DETAIL, $data );
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

        $order->add_order_note( __( 'Shopline payment authorized, awaiting capture.', 'ys-shopline-via-woocommerce' ) );
        $order->update_meta_data( YSOrderMeta::PAYMENT_STATUS, 'AUTHORIZED' );
        $order->update_meta_data( YSOrderMeta::PAYMENT_DETAIL, $data );
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

        $order = $this->get_order_by_trade_id( $trade_order_id );

        if ( ! $order ) {
            return;
        }

        if ( ! $order->is_paid() ) {
            $order->payment_complete( $trade_order_id );
        }

        $order->add_order_note( __( 'Shopline payment captured.', 'ys-shopline-via-woocommerce' ) );
        $order->update_meta_data( YSOrderMeta::PAYMENT_STATUS, 'CAPTURED' );
        $order->update_meta_data( YSOrderMeta::PAYMENT_DETAIL, $data );
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

        if ( ! $order || ! in_array( $order->get_status(), [ 'pending', 'on-hold' ], true ) ) {
            return;
        }

        $order->update_status( 'cancelled', __( 'Shopline payment cancelled by customer.', 'ys-shopline-via-woocommerce' ) );
        $order->update_meta_data( YSOrderMeta::PAYMENT_STATUS, 'CANCELLED' );
        $order->update_meta_data( YSOrderMeta::PAYMENT_DETAIL, $data );
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

        $order = $this->get_order_by_trade_id( $trade_order_id );

        if ( ! $order || ! in_array( $order->get_status(), [ 'pending', 'on-hold' ], true ) ) {
            return;
        }

        $order->update_status( 'failed', __( 'Shopline payment expired.', 'ys-shopline-via-woocommerce' ) );
        $order->update_meta_data( YSOrderMeta::PAYMENT_STATUS, 'EXPIRED' );
        $order->update_meta_data( YSOrderMeta::PAYMENT_DETAIL, $data );
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

        $order->update_status( 'on-hold', __( 'Shopline payment processing.', 'ys-shopline-via-woocommerce' ) );
        $order->update_meta_data( YSOrderMeta::PAYMENT_STATUS, 'PROCESSING' );
        $order->update_meta_data( YSOrderMeta::PAYMENT_DETAIL, $data );

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
