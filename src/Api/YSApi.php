<?php
/**
 * API class for YS Shopline Payment.
 *
 * 對外維持 array|WP_Error 契約，內部委託 YSShoplineRequester 執行 HTTP 請求。
 *
 * @package YangSheep\ShoplinePayment\Api
 */

namespace YangSheep\ShoplinePayment\Api;

defined( 'ABSPATH' ) || exit;

use YangSheep\ShoplinePayment\DTOs\YSSessionDTO;
use YangSheep\ShoplinePayment\DTOs\YSPaymentDTO;
use YangSheep\ShoplinePayment\Utils\YSLogger;
use WP_Error;

/**
 * YSApi Class.
 *
 * Handles all API communications with Shopline Payment.
 */
class YSApi {

    /**
     * HTTP 請求器
     *
     * @var YSShoplineRequester
     */
    private $requester;

    /**
     * Constructor.
     *
     * 所有參數均為 optional，預設從 Requester 的 option 讀取。
     *
     * @param string|null $merchant_id  Merchant ID.
     * @param string|null $api_key      API Key.
     * @param bool|null   $is_test_mode Test mode flag.
     */
    public function __construct( $merchant_id = null, $api_key = null, $is_test_mode = null ) {
        $this->requester = new YSShoplineRequester( $is_test_mode, $merchant_id, $api_key );
    }

    /**
     * 發送 API 請求並將 Exception 映射為 WP_Error。
     *
     * @param string $endpoint       API 端點
     * @param array  $data           請求資料
     * @param string $method         HTTP 方法
     * @param string $idempotent_key 冪等鍵
     * @return array|WP_Error
     */
    private function request( $endpoint, $data = array(), $method = 'POST', $idempotent_key = '' ) {
        try {
            if ( 'GET' === $method ) {
                return $this->requester->get( $endpoint, $data );
            }

            return $this->requester->post( $endpoint, $data, $idempotent_key );
        } catch ( YSApiPartialSuccessException $e ) {
            // 400 但有 tradeOrderId / nextAction → 回傳 body 繼續處理（3DS 等流程）
            return $e->get_response_body();
        } catch ( YSApiException $e ) {
            // Requester 的結構化錯誤（攜帶精確 error code：http_request_failed / json_decode_error / empty_response / 1999 / …）
            return new WP_Error( $e->get_api_code(), $e->getMessage() );
        } catch ( \Throwable $e ) {
            // 未預期的錯誤（TypeError 等）— 不對外洩漏內部訊息
            YSLogger::error( 'API 未預期錯誤: ' . $e->getMessage(), [
                'endpoint' => $endpoint,
                'trace'    => $e->getTraceAsString(),
            ] );
            return new WP_Error( 'api_error', __( '付款系統發生錯誤，請稍後重試。', 'ys-shopline-via-woocommerce' ) );
        }
    }

    /**
     * Create payment trade.
     *
     * @param array  $data           Payment data.
     * @param string $idempotent_key Idempotent key（同一筆重送相同 key，API 只處理一次）。
     * @return array|WP_Error
     */
    public function create_payment_trade( $data, $idempotent_key = '' ) {
        return $this->request( '/trade/payment/create', $data, 'POST', $idempotent_key );
    }

    /**
     * Get payment trade.
     *
     * @param string $trade_order_id Trade order ID.
     * @return array|WP_Error
     */
    public function get_payment_trade( $trade_order_id ) {
        return $this->request( '/trade/payment/get', array( 'tradeOrderId' => $trade_order_id ) );
    }

    /**
     * Create refund.
     *
     * @param array $data Refund data.
     * @return array|WP_Error
     */
    public function create_refund( $data ) {
        return $this->request( '/trade/refund/create', $data );
    }

    /**
     * Create customer.
     *
     * 新版 API 端點：/api/v1/customer/create
     * 回應欄位為 customerId（不是 paymentCustomerId）
     *
     * @param array $data Customer data.
     * @return array|WP_Error
     */
    public function create_customer( $data ) {
        return $this->request( '/customer/create', $data );
    }

    /**
     * Get customer token.
     *
     * 新版 API 端點：/api/v1/customer/token
     * 請求欄位為 customerId（不是 paymentCustomerId）
     *
     * @param string $customer_id Customer ID.
     * @return array|WP_Error
     */
    public function get_customer_token( $customer_id ) {
        return $this->request( '/customer/token', array( 'customerId' => $customer_id ) );
    }

    /**
     * Get payment instruments for customer.
     *
     * API 端點：/api/v1/customer/paymentInstrument/query
     *
     * @param string $customer_id Customer ID.
     * @param array  $filters     Optional filters (instrumentStatusList, etc.).
     * @return array|WP_Error
     */
    public function get_payment_instruments( $customer_id, $filters = array() ) {
        $data = array( 'customerId' => $customer_id );

        if ( ! empty( $filters ) ) {
            $data['paymentInstrument'] = $filters;
        }

        return $this->request( '/customer/paymentInstrument/query', $data );
    }

    /**
     * Unbind (delete) payment instrument.
     *
     * API 端點：/api/v1/customer/paymentInstrument/unbind
     *
     * @param string $customer_id           Customer ID.
     * @param string $payment_instrument_id Payment instrument ID.
     * @return array|WP_Error
     */
    public function delete_payment_instrument( $customer_id, $payment_instrument_id ) {
        return $this->request( '/customer/paymentInstrument/unbind', array(
            'customerId'          => $customer_id,
            'paymentInstrumentId' => $payment_instrument_id,
        ) );
    }

    // ==========================================
    // Query 方法
    // ==========================================

    /**
     * 查詢結帳交易 Session
     *
     * @param string $session_id Session ID
     * @return YSSessionDTO|WP_Error
     */
    public function query_session( $session_id ) {
        $response = $this->request( '/trade/sessions/query', array( 'sessionId' => $session_id ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        try {
            return YSSessionDTO::from_response( $response );
        } catch ( \Throwable $e ) {
            return new WP_Error( 'dto_error', $e->getMessage() );
        }
    }

    /**
     * 查詢付款交易（回傳 DTO）
     *
     * @param string $trade_order_id 交易訂單 ID
     * @return YSPaymentDTO|WP_Error
     */
    public function query_payment( $trade_order_id ) {
        $response = $this->get_payment_trade( $trade_order_id );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        try {
            return YSPaymentDTO::from_response( $response );
        } catch ( \Throwable $e ) {
            return new WP_Error( 'dto_error', $e->getMessage() );
        }
    }

    /**
     * 以指定 ID 取消付款交易
     *
     * @param string $trade_order_id     交易訂單 ID
     * @param string $reference_order_id 特店訂單號
     * @return array|WP_Error
     */
    public function cancel_payment_by_ids( $trade_order_id, $reference_order_id ) {
        $data = array(
            'tradeOrderId' => $trade_order_id,
        );

        if ( '' !== $reference_order_id ) {
            $data['referenceOrderId'] = $reference_order_id;
        }

        return $this->request( '/trade/payment/cancel', $data );
    }

    /**
     * 檢查 API 憑證是否已設定
     *
     * @return bool
     */
    public function has_credentials() {
        return $this->requester->has_credentials();
    }
}
