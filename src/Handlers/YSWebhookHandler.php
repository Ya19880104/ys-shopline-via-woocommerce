<?php
/**
 * Webhook Handler
 *
 * @package YangSheep\ShoplinePayment\Handlers
 */

declare(strict_types=1);

namespace YangSheep\ShoplinePayment\Handlers;

use YangSheep\ShoplinePayment\DTOs\YSPaymentDTO;
use YangSheep\ShoplinePayment\Utils\YSLogger;
use YangSheep\ShoplinePayment\Utils\YSOrderMeta;
use YangSheep\ShoplinePayment\Utils\YSSignatureVerifier;

defined( 'ABSPATH' ) || exit;

/**
 * Webhook 處理器
 *
 * 處理來自 Shopline Payment 的 Webhook 通知
 */
final class YSWebhookHandler {

    /** @var string Webhook 端點 */
    public const WEBHOOK_ENDPOINT = 'ys-shopline-webhook';

    /** @var YSSignatureVerifier */
    private YSSignatureVerifier $signature_verifier;

    /**
     * Constructor
     */
    public function __construct() {
        $this->signature_verifier = new YSSignatureVerifier();
    }

    /**
     * 初始化
     */
    public static function init(): void {
        $instance = new self();
        $instance->register_hooks();
    }

    /**
     * 註冊 Hooks
     */
    private function register_hooks(): void {
        // 註冊 REST API 端點
        add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );

        // 相容舊版 WC API
        add_action( 'woocommerce_api_' . self::WEBHOOK_ENDPOINT, [ $this, 'handle_webhook' ] );
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
        $body      = $request->get_body();
        $signature = $request->get_header( 'X-Shopline-Signature' );

        $result = $this->process_webhook( $body, $signature );

        if ( $result['success'] ) {
            return new \WP_REST_Response( [ 'status' => 'ok' ], 200 );
        }

        return new \WP_REST_Response( [ 'error' => $result['message'] ], 400 );
    }

    /**
     * 處理 WC API Webhook（相容舊版）
     */
    public function handle_webhook(): void {
        $body      = file_get_contents( 'php://input' );
        $signature = $_SERVER['HTTP_X_SHOPLINE_SIGNATURE'] ?? '';

        $result = $this->process_webhook( $body, $signature );

        if ( $result['success'] ) {
            wp_send_json( [ 'status' => 'ok' ], 200 );
        } else {
            wp_send_json_error( [ 'error' => $result['message'] ], 400 );
        }
    }

    /**
     * 處理 Webhook
     *
     * @param string $body 請求內容
     * @param string $signature 簽章
     * @return array{success: bool, message: string}
     */
    private function process_webhook( string $body, string $signature ): array {
        YSLogger::info( 'Webhook 收到請求', [
            'body_length' => strlen( $body ),
            'has_signature' => ! empty( $signature ),
        ] );

        // 解析 JSON
        $data = json_decode( $body, true );

        if ( json_last_error() !== JSON_ERROR_NONE ) {
            YSLogger::error( 'Webhook JSON 解析失敗', [
                'error' => json_last_error_msg(),
            ] );
            return [ 'success' => false, 'message' => 'Invalid JSON' ];
        }

        // 取得訂單
        $order = $this->get_order_from_webhook( $data );

        if ( ! $order ) {
            YSLogger::error( 'Webhook 找不到訂單', [ 'data' => $data ] );
            return [ 'success' => false, 'message' => 'Order not found' ];
        }

        // 驗證簽章（使用訂單的 API Key）
        $api_key = $this->get_api_key_for_order( $order );

        if ( ! $this->signature_verifier->verify( $body, $signature, $api_key ) ) {
            YSLogger::error( 'Webhook 簽章驗證失敗', [
                'order_id' => $order->get_id(),
            ] );
            return [ 'success' => false, 'message' => 'Invalid signature' ];
        }

        // 處理不同事件類型
        $event_type = $data['eventType'] ?? '';

        try {
            $this->handle_event( $order, $event_type, $data );

            YSLogger::info( 'Webhook 處理成功', [
                'order_id'   => $order->get_id(),
                'event_type' => $event_type,
            ] );

            return [ 'success' => true, 'message' => 'OK' ];
        } catch ( \Throwable $e ) {
            YSLogger::error( "Webhook 處理失敗: {$e->getMessage()}", [
                'order_id'   => $order->get_id(),
                'event_type' => $event_type,
            ] );
            return [ 'success' => false, 'message' => $e->getMessage() ];
        }
    }

    /**
     * 從 Webhook 資料取得訂單
     *
     * @param array $data Webhook 資料
     * @return \WC_Order|null
     */
    private function get_order_from_webhook( array $data ): ?\WC_Order {
        // 嘗試從 merchantTradeNo 取得訂單
        $merchant_trade_no = $data['merchantTradeNo'] ?? '';

        if ( $merchant_trade_no ) {
            // merchantTradeNo 格式：{order_id}_{timestamp}
            $parts = explode( '_', $merchant_trade_no );
            $order_id = (int) ( $parts[0] ?? 0 );

            if ( $order_id ) {
                $order = wc_get_order( $order_id );
                if ( $order ) {
                    return $order;
                }
            }
        }

        // 嘗試從 tradeOrderId 查詢
        $trade_order_id = $data['tradeOrderId'] ?? '';

        if ( $trade_order_id ) {
            $orders = wc_get_orders( [
                'meta_key'   => YSOrderMeta::TRADE_ORDER_ID,
                'meta_value' => $trade_order_id,
                'limit'      => 1,
            ] );

            if ( ! empty( $orders ) ) {
                return $orders[0];
            }
        }

        return null;
    }

    /**
     * 取得訂單的 API Key
     *
     * @param \WC_Order $order 訂單
     * @return string
     */
    private function get_api_key_for_order( \WC_Order $order ): string {
        // 從閘道設定取得
        $gateway_id = $order->get_payment_method();
        $gateways   = WC()->payment_gateways()->get_available_payment_gateways();

        if ( isset( $gateways[ $gateway_id ] ) ) {
            $gateway = $gateways[ $gateway_id ];
            return $gateway->get_option( 'api_key', '' );
        }

        return '';
    }

    /**
     * 處理事件
     *
     * @param \WC_Order $order 訂單
     * @param string $event_type 事件類型
     * @param array $data 事件資料
     */
    private function handle_event( \WC_Order $order, string $event_type, array $data ): void {
        switch ( $event_type ) {
            case 'payment.success':
                $this->handle_payment_success( $order, $data );
                break;

            case 'payment.failed':
                $this->handle_payment_failed( $order, $data );
                break;

            case 'payment.cancelled':
                $this->handle_payment_cancelled( $order, $data );
                break;

            case 'refund.success':
                $this->handle_refund_success( $order, $data );
                break;

            case 'refund.failed':
                $this->handle_refund_failed( $order, $data );
                break;

            default:
                YSLogger::info( "未處理的事件類型: {$event_type}", [
                    'order_id' => $order->get_id(),
                ] );
        }
    }

    /**
     * 處理付款成功
     *
     * @param \WC_Order $order 訂單
     * @param array $data 事件資料
     */
    private function handle_payment_success( \WC_Order $order, array $data ): void {
        // 避免重複處理
        if ( $order->is_paid() ) {
            return;
        }

        // 儲存付款資訊
        $payment_dto = YSPaymentDTO::from_response( $data );

        $order->update_meta_data( YSOrderMeta::TRADE_ORDER_ID, $payment_dto->trade_order_id );
        $order->update_meta_data( YSOrderMeta::PAYMENT_METHOD, $payment_dto->payment_method );
        $order->update_meta_data( YSOrderMeta::PAYMENT_STATUS, $payment_dto->payment_status );
        $order->update_meta_data( YSOrderMeta::PAYMENT_DETAIL, $payment_dto->to_array() );

        // 標記付款完成
        $order->payment_complete( $payment_dto->trade_order_id );

        // 新增訂單備註
        $order->add_order_note(
            sprintf(
                /* translators: 1: Payment method 2: Trade order ID */
                __( 'Shopline Payment 付款成功。付款方式：%1$s，交易編號：%2$s', 'ys-shopline-payment' ),
                $payment_dto->get_payment_method_display(),
                $payment_dto->trade_order_id
            )
        );

        $order->save();
    }

    /**
     * 處理付款失敗
     *
     * @param \WC_Order $order 訂單
     * @param array $data 事件資料
     */
    private function handle_payment_failed( \WC_Order $order, array $data ): void {
        $error_code = $data['errorCode'] ?? '';
        $error_msg  = $data['errorMessage'] ?? '';

        $order->update_status( 'failed', sprintf(
            /* translators: 1: Error code 2: Error message */
            __( 'Shopline Payment 付款失敗。錯誤代碼：%1$s，錯誤訊息：%2$s', 'ys-shopline-payment' ),
            $error_code,
            $error_msg
        ) );
    }

    /**
     * 處理付款取消
     *
     * @param \WC_Order $order 訂單
     * @param array $data 事件資料
     */
    private function handle_payment_cancelled( \WC_Order $order, array $data ): void {
        if ( ! in_array( $order->get_status(), [ 'pending', 'on-hold' ], true ) ) {
            return;
        }

        $order->update_status( 'cancelled', __( 'Shopline Payment 付款已取消', 'ys-shopline-payment' ) );
    }

    /**
     * 處理退款成功
     *
     * @param \WC_Order $order 訂單
     * @param array $data 事件資料
     */
    private function handle_refund_success( \WC_Order $order, array $data ): void {
        $refund_amount = (float) ( $data['refundAmount'] ?? 0 );
        $refund_id     = $data['refundId'] ?? '';

        $order->add_order_note(
            sprintf(
                /* translators: 1: Refund amount 2: Refund ID */
                __( 'Shopline Payment 退款成功。退款金額：%1$s，退款編號：%2$s', 'ys-shopline-payment' ),
                wc_price( $refund_amount ),
                $refund_id
            )
        );
    }

    /**
     * 處理退款失敗
     *
     * @param \WC_Order $order 訂單
     * @param array $data 事件資料
     */
    private function handle_refund_failed( \WC_Order $order, array $data ): void {
        $error_code = $data['errorCode'] ?? '';
        $error_msg  = $data['errorMessage'] ?? '';

        $order->add_order_note(
            sprintf(
                /* translators: 1: Error code 2: Error message */
                __( 'Shopline Payment 退款失敗。錯誤代碼：%1$s，錯誤訊息：%2$s', 'ys-shopline-payment' ),
                $error_code,
                $error_msg
            )
        );
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
