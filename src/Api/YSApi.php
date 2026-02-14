<?php
/**
 * API class for YS Shopline Payment.
 *
 * @package YangSheep\ShoplinePayment\Api
 */

namespace YangSheep\ShoplinePayment\Api;

defined( 'ABSPATH' ) || exit;

use YangSheep\ShoplinePayment\Utils\YSLogger;
use WP_Error;

/**
 * YSApi Class.
 *
 * Handles all API communications with Shopline Payment.
 */
class YSApi {

    /**
     * Merchant ID.
     *
     * @var string
     */
    private $merchant_id;

    /**
     * API Key.
     *
     * @var string
     */
    private $api_key;

    /**
     * Test mode flag.
     *
     * @var bool
     */
    private $is_test_mode;

    /**
     * API base URL.
     *
     * @var string
     */
    private $api_url;

    /**
     * Constructor.
     *
     * @param string $merchant_id  Merchant ID.
     * @param string $api_key      API Key.
     * @param bool   $is_test_mode Test mode flag.
     */
    public function __construct( $merchant_id, $api_key, $is_test_mode = false ) {
        $this->merchant_id  = $merchant_id;
        $this->api_key      = $api_key;
        $this->is_test_mode = $is_test_mode;
        $this->api_url      = $is_test_mode
            ? 'https://api-sandbox.shoplinepayments.com/api/v1'
            : 'https://api.shoplinepayments.com/api/v1';
    }

    /**
     * Send request to Shopline API.
     *
     * @param string $endpoint API endpoint.
     * @param array  $data     Request data.
     * @param string $method   HTTP method.
     * @return array|WP_Error
     */
    private function request( $endpoint, $data = array(), $method = 'POST' ) {
        $url = $this->api_url . $endpoint;

        $request_id = $this->generate_request_id();

        $headers = array(
            'Content-Type' => 'application/json',
            'merchantId'   => $this->merchant_id,
            'apiKey'       => $this->api_key,
            'requestId'    => $request_id,
        );

        $args = array(
            'method'  => $method,
            'headers' => $headers,
            'timeout' => 45,
        );

        if ( ! empty( $data ) && 'GET' !== $method ) {
            $args['body'] = wp_json_encode( $data );
        }

        // 記錄簡要資訊
        YSLogger::debug( "API Request to $url", array(
            'method'     => $method,
            'request_id' => $request_id,
        ) );

        // 記錄完整請求資料（用於除錯 1999 錯誤）
        YSLogger::debug( 'API Request full data', array(
            'endpoint'             => $endpoint,
            'data_json'            => wp_json_encode( $data ),
            'paySession_type'      => isset( $data['paySession'] ) ? gettype( $data['paySession'] ) : 'not_set',
            'paySession_is_json'   => isset( $data['paySession'] ) ? ( json_decode( $data['paySession'] ) !== null ? 'yes' : 'no' ) : 'not_set',
        ) );

        $response = wp_remote_request( $url, $args );

        if ( is_wp_error( $response ) ) {
            YSLogger::error( 'API Request Error: ' . $response->get_error_message() );
            return $response;
        }

        $body = wp_remote_retrieve_body( $response );
        $code = wp_remote_retrieve_response_code( $response );

        YSLogger::debug( "API Response ($code)", array(
            'request_id' => $request_id,
            'body'       => $body,
        ) );

        $decoded_body = json_decode( $body, true );

        // SHOPLINE 有時會在 400 錯誤中仍然返回有效的交易資訊
        // 如果有 tradeOrderId 或 nextAction，視為成功（部分成功）
        $has_trade_info = isset( $decoded_body['tradeOrderId'] ) || isset( $decoded_body['nextAction'] );

        if ( $code >= 400 && ! $has_trade_info ) {
            // SHOPLINE API 使用 'msg' 而非 'message'
            $error_message = isset( $decoded_body['msg'] ) ? $decoded_body['msg'] : ( isset( $decoded_body['message'] ) ? $decoded_body['message'] : "API request failed with code $code" );
            $error_code    = isset( $decoded_body['code'] ) ? $decoded_body['code'] : 'api_error';

            // 1999 是 SHOPLINE 內部伺服器錯誤，提供更多資訊給用戶
            if ( '1999' === $error_code ) {
                $error_message = __( 'SHOPLINE 伺服器錯誤 (1999)。請稍後重試或聯繫客服。', 'ys-shopline-via-woocommerce' );
                YSLogger::error( 'SHOPLINE Server Error 1999 - 可能需要聯繫 SHOPLINE 技術支援', array(
                    'http_code'  => $code,
                    'request_id' => $request_id,
                    'endpoint'   => $endpoint,
                    'response'   => $decoded_body,
                    'hint'       => '這通常是 SHOPLINE 內部處理錯誤，可能與 3DS 驗證流程有關',
                ) );
            } else {
                YSLogger::error( "API Error: $error_message (Code: $error_code)", array(
                    'http_code' => $code,
                    'response'  => $decoded_body,
                ) );
            }

            return new WP_Error( $error_code, $error_message );
        }

        // 即使是 400 錯誤，如果有交易資訊，記錄警告但繼續處理
        if ( $code >= 400 && $has_trade_info ) {
            YSLogger::warning( 'API returned error code but has trade info', array(
                'http_code'    => $code,
                'error_code'   => isset( $decoded_body['code'] ) ? $decoded_body['code'] : 'none',
                'error_msg'    => isset( $decoded_body['msg'] ) ? $decoded_body['msg'] : 'none',
                'tradeOrderId' => isset( $decoded_body['tradeOrderId'] ) ? $decoded_body['tradeOrderId'] : 'none',
                'has_nextAction' => isset( $decoded_body['nextAction'] ) ? 'yes' : 'no',
            ) );
        }

        // 檢查是否有 nextAction（即使 HTTP 200，也要記錄成功資訊）
        YSLogger::debug( 'API Success', array(
            'http_code'      => $code,
            'has_nextAction' => isset( $decoded_body['nextAction'] ) ? 'yes' : 'no',
            'status'         => isset( $decoded_body['status'] ) ? $decoded_body['status'] : 'unknown',
            'tradeOrderId'   => isset( $decoded_body['tradeOrderId'] ) ? $decoded_body['tradeOrderId'] : 'none',
        ) );

        return $decoded_body;
    }

    /**
     * Generate unique request ID.
     *
     * @return string
     */
    private function generate_request_id() {
        return round( microtime( true ) * 1000 ) . wp_rand( 1000, 9999 );
    }

    /**
     * Create payment trade.
     *
     * @param array $data Payment data.
     * @return array|WP_Error
     */
    public function create_payment_trade( $data ) {
        return $this->request( '/trade/payment/create', $data );
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
     * Capture payment.
     *
     * @param array $data Capture data.
     * @return array|WP_Error
     */
    public function capture_payment( $data ) {
        return $this->request( '/trade/payment/capture', $data );
    }

    /**
     * Cancel payment.
     *
     * @param array $data Cancel data.
     * @return array|WP_Error
     */
    public function cancel_payment( $data ) {
        return $this->request( '/trade/payment/cancel', $data );
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
     * Get refund.
     *
     * @param string $refund_order_id Refund order ID.
     * @return array|WP_Error
     */
    public function get_refund( $refund_order_id ) {
        return $this->request( '/trade/refund/get', array( 'refundOrderId' => $refund_order_id ) );
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

    /**
     * Check API credentials.
     *
     * @return bool
     */
    public function check_credentials() {
        if ( empty( $this->merchant_id ) || empty( $this->api_key ) ) {
            return false;
        }

        // Try a simple API call to verify credentials
        $response = $this->get_customer_token( 'test_' . time() );

        // We expect an error since the customer doesn't exist,
        // but we should get an API response rather than a connection error
        if ( is_wp_error( $response ) ) {
            $error_code = $response->get_error_code();
            // Connection errors indicate invalid credentials or network issues
            if ( in_array( $error_code, array( 'http_request_failed', 'api_error' ), true ) ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get API URL.
     *
     * @return string
     */
    public function get_api_url() {
        return $this->api_url;
    }

    /**
     * Is test mode.
     *
     * @return bool
     */
    public function is_test_mode() {
        return $this->is_test_mode;
    }
}
