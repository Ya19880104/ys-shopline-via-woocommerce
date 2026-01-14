<?php
/**
 * Shopline API Client
 *
 * @package YangSheep\ShoplinePayment\Api
 */

declare(strict_types=1);

namespace YangSheep\ShoplinePayment\Api;

use YangSheep\ShoplinePayment\DTOs\YSAmountDTO;
use YangSheep\ShoplinePayment\DTOs\YSSessionDTO;
use YangSheep\ShoplinePayment\DTOs\YSPaymentDTO;
use YangSheep\ShoplinePayment\DTOs\YSRefundDTO;
use YangSheep\ShoplinePayment\DTOs\YSCustomerDTO;
use YangSheep\ShoplinePayment\DTOs\YSPaymentInstrumentDTO;

defined( 'ABSPATH' ) || exit;

/**
 * Shopline Payment API 客戶端
 *
 * 提供所有 API 操作的高階介面
 */
final class YSShoplineClient {

    /** @var YSShoplineRequester */
    private YSShoplineRequester $requester;

    /** @var \WC_Order|null */
    private ?\WC_Order $order;

    /**
     * Constructor
     *
     * @param \WC_Order|null $order 訂單物件（可選）
     */
    public function __construct( ?\WC_Order $order = null ) {
        $this->requester = new YSShoplineRequester();
        $this->order     = $order;
    }

    // ==========================================
    // Session API (跳轉支付)
    // ==========================================

    /**
     * 建立結帳交易 Session
     *
     * @param \WC_Order $order      訂單物件
     * @param string    $return_url 返回 URL
     * @return YSSessionDTO
     * @throws \Exception 如果建立失敗
     *
     * @see https://docs.shoplinepayments.com/api/trade/session/
     */
    public function create_session( \WC_Order $order, string $return_url ): YSSessionDTO {
        $amount = YSAmountDTO::from_order( $order );

        $data = [
            'amount' => $amount->to_array(),
            'order'  => [
                'referenceOrderId' => (string) $order->get_id(),
            ],
            'returnUrl' => $return_url,
        ];

        // 添加 Webhook URL
        $webhook_url = $this->get_webhook_url();
        if ( $webhook_url ) {
            $data['webhookUrl'] = $webhook_url;
        }

        $response = $this->requester->post( '/trade/sessions/create', $data );

        return YSSessionDTO::from_response( $response );
    }

    /**
     * 查詢結帳交易 Session
     *
     * @param string $session_id Session ID
     * @return YSSessionDTO
     * @throws \Exception 如果查詢失敗
     *
     * @see https://docs.shoplinepayments.com/api/trade/sessionQuery/
     */
    public function query_session( string $session_id ): YSSessionDTO {
        $response = $this->requester->post( '/trade/sessions/query', [
            'sessionId' => $session_id,
        ] );

        return YSSessionDTO::from_response( $response );
    }

    // ==========================================
    // Payment API (付款交易)
    // ==========================================

    /**
     * 建立付款交易
     *
     * @param array<string, mixed> $data 付款資料
     * @return YSPaymentDTO
     * @throws \Exception 如果建立失敗
     *
     * @see https://docs.shoplinepayments.com/api/trade/create/
     */
    public function create_payment( array $data ): YSPaymentDTO {
        $response = $this->requester->post( '/trade/payment/create', $data );
        return YSPaymentDTO::from_response( $response );
    }

    /**
     * 查詢付款交易
     *
     * @param string $trade_order_id 交易訂單 ID
     * @return YSPaymentDTO
     * @throws \Exception 如果查詢失敗
     *
     * @see https://docs.shoplinepayments.com/api/trade/query/
     */
    public function query_payment( string $trade_order_id ): YSPaymentDTO {
        $response = $this->requester->post( '/trade/payment/get', [
            'tradeOrderId' => $trade_order_id,
        ] );

        return YSPaymentDTO::from_response( $response );
    }

    /**
     * 取消付款交易
     *
     * @param string $trade_order_id 交易訂單 ID
     * @return array<string, mixed>
     * @throws \Exception 如果取消失敗
     *
     * @see https://docs.shoplinepayments.com/api/trade/cancel/
     */
    public function cancel_payment( string $trade_order_id ): array {
        return $this->requester->post( '/trade/payment/cancel', [
            'tradeOrderId' => $trade_order_id,
        ] );
    }

    /**
     * 請款（Capture）
     *
     * @param string $trade_order_id 交易訂單 ID
     * @param int    $amount         請款金額
     * @param string $currency       幣別
     * @return array<string, mixed>
     * @throws \Exception 如果請款失敗
     *
     * @see https://docs.shoplinepayments.com/api/trade/capture/
     */
    public function capture_payment( string $trade_order_id, int $amount, string $currency = 'TWD' ): array {
        return $this->requester->post( '/trade/payment/capture', [
            'tradeOrderId' => $trade_order_id,
            'amount'       => [
                'value'    => $amount,
                'currency' => $currency,
            ],
        ] );
    }

    // ==========================================
    // Refund API (退款)
    // ==========================================

    /**
     * 建立退款
     *
     * @param string $trade_order_id 交易訂單 ID
     * @param float  $amount         退款金額
     * @param string $reason         退款原因
     * @param string $currency       幣別
     * @return YSRefundDTO
     * @throws \Exception 如果退款失敗
     *
     * @see https://docs.shoplinepayments.com/api/trade/refund/
     */
    public function create_refund(
        string $trade_order_id,
        float $amount,
        string $reason = '',
        string $currency = 'TWD'
    ): YSRefundDTO {
        $amount_value = YSAmountDTO::format_amount( $amount, $currency );

        $data = [
            'tradeOrderId' => $trade_order_id,
            'amount'       => [
                'value'    => $amount_value,
                'currency' => $currency,
            ],
        ];

        if ( ! empty( $reason ) ) {
            $data['reason'] = $reason;
        }

        $response = $this->requester->post( '/trade/refund/create', $data );

        return YSRefundDTO::from_response( $response );
    }

    /**
     * 查詢退款
     *
     * @param string $refund_order_id 退款訂單 ID
     * @return YSRefundDTO
     * @throws \Exception 如果查詢失敗
     *
     * @see https://docs.shoplinepayments.com/api/trade/refundQuery/
     */
    public function query_refund( string $refund_order_id ): YSRefundDTO {
        $response = $this->requester->post( '/trade/refund/get', [
            'refundOrderId' => $refund_order_id,
        ] );

        return YSRefundDTO::from_response( $response );
    }

    // ==========================================
    // Customer API (會員管理)
    // ==========================================

    /**
     * 建立會員
     *
     * @param int $user_id WordPress 用戶 ID
     * @return YSCustomerDTO
     * @throws \Exception 如果建立失敗
     *
     * @see https://docs.shoplinepayments.com/api/customer-paymentInstrument/customer/create/
     */
    public function create_customer( int $user_id ): YSCustomerDTO {
        $data     = YSCustomerDTO::create_request_from_user( $user_id );
        $response = $this->requester->post( '/customer-paymentInstrument/customer/create', $data );

        return YSCustomerDTO::from_response( $response );
    }

    /**
     * 取得會員 Token
     *
     * @param string $customer_id Shopline 會員 ID
     * @return array<string, mixed>
     * @throws \Exception 如果取得失敗
     *
     * @see https://docs.shoplinepayments.com/api/customer-paymentInstrument/customer/getToken/
     */
    public function get_customer_token( string $customer_id ): array {
        return $this->requester->post( '/customer-paymentInstrument/customer/getToken', [
            'paymentCustomerId' => $customer_id,
        ] );
    }

    // ==========================================
    // Payment Instrument API (付款工具/Token)
    // ==========================================

    /**
     * 查詢付款工具列表
     *
     * @param string $customer_id Shopline 會員 ID
     * @param array  $filters     篩選條件
     * @return array<int, YSPaymentInstrumentDTO>
     * @throws \Exception 如果查詢失敗
     *
     * @see https://docs.shoplinepayments.com/api/customer-paymentInstrument/paymentInstrument/query/
     */
    public function query_payment_instruments( string $customer_id, array $filters = [] ): array {
        $data = [
            'customerId' => $customer_id,
        ];

        if ( ! empty( $filters ) ) {
            $data['paymentInstrument'] = $filters;
        }

        $response = $this->requester->post( '/customer-paymentInstrument/paymentInstrument/query', $data );

        $instruments = $response['paymentInstruments'] ?? [];

        return YSPaymentInstrumentDTO::from_response_array( $instruments );
    }

    /**
     * 解綁付款工具
     *
     * @param string $customer_id   Shopline 會員 ID
     * @param string $instrument_id 付款工具 ID
     * @return array<string, mixed>
     * @throws \Exception 如果解綁失敗
     *
     * @see https://docs.shoplinepayments.com/api/customer-paymentInstrument/paymentInstrument/unbind/
     */
    public function unbind_payment_instrument( string $customer_id, string $instrument_id ): array {
        return $this->requester->post( '/customer-paymentInstrument/paymentInstrument/unbind', [
            'customerId'          => $customer_id,
            'paymentInstrumentId' => $instrument_id,
        ] );
    }

    // ==========================================
    // Helper Methods
    // ==========================================

    /**
     * 取得 Webhook URL
     *
     * @return string
     */
    public function get_webhook_url(): string {
        return get_rest_url( null, 'ys-shopline/v1/webhook' );
    }

    /**
     * 取得請求器
     *
     * @return YSShoplineRequester
     */
    public function get_requester(): YSShoplineRequester {
        return $this->requester;
    }

    /**
     * 檢查 API 憑證是否已設定
     *
     * @return bool
     */
    public function has_credentials(): bool {
        return $this->requester->has_credentials();
    }
}
