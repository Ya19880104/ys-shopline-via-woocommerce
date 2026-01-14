<?php
/**
 * API class for YS Shopline Payment.
 *
 * @package YS_Shopline_Payment
 */

defined( 'ABSPATH' ) || exit;

/**
 * YS_Shopline_API Class.
 *
 * Handles all API communications with Shopline Payment.
 */
class YS_Shopline_API {

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

        YS_Shopline_Logger::debug( "API Request to $url", array(
            'method'     => $method,
            'request_id' => $request_id,
            'data'       => $data,
        ) );

        $response = wp_remote_request( $url, $args );

        if ( is_wp_error( $response ) ) {
            YS_Shopline_Logger::error( 'API Request Error: ' . $response->get_error_message() );
            return $response;
        }

        $body = wp_remote_retrieve_body( $response );
        $code = wp_remote_retrieve_response_code( $response );

        YS_Shopline_Logger::debug( "API Response ($code)", array(
            'request_id' => $request_id,
            'body'       => $body,
        ) );

        $decoded_body = json_decode( $body, true );

        if ( $code >= 400 ) {
            $error_message = isset( $decoded_body['message'] ) ? $decoded_body['message'] : "API request failed with code $code";
            $error_code    = isset( $decoded_body['code'] ) ? $decoded_body['code'] : 'api_error';

            YS_Shopline_Logger::error( "API Error: $error_message (Code: $error_code)" );

            return new WP_Error( $error_code, $error_message );
        }

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
     * @param array $data Customer data.
     * @return array|WP_Error
     */
    public function create_customer( $data ) {
        return $this->request( '/customer-paymentInstrument/customer/create', $data );
    }

    /**
     * Get customer token.
     *
     * @param string $payment_customer_id Payment customer ID.
     * @return array|WP_Error
     */
    public function get_customer_token( $payment_customer_id ) {
        return $this->request( '/customer-paymentInstrument/customer/getToken', array( 'paymentCustomerId' => $payment_customer_id ) );
    }

    /**
     * Get payment instruments for customer.
     *
     * @param string $payment_customer_id Payment customer ID.
     * @return array|WP_Error
     */
    public function get_payment_instruments( $payment_customer_id ) {
        return $this->request( '/customer-paymentInstrument/paymentInstrument/list', array( 'paymentCustomerId' => $payment_customer_id ) );
    }

    /**
     * Delete payment instrument.
     *
     * @param string $payment_instrument_id Payment instrument ID.
     * @return array|WP_Error
     */
    public function delete_payment_instrument( $payment_instrument_id ) {
        return $this->request( '/customer-paymentInstrument/paymentInstrument/delete', array( 'paymentInstrumentId' => $payment_instrument_id ) );
    }

    /**
     * Set default payment instrument.
     *
     * @param string $payment_customer_id   Payment customer ID.
     * @param string $payment_instrument_id Payment instrument ID.
     * @return array|WP_Error
     */
    public function set_default_payment_instrument( $payment_customer_id, $payment_instrument_id ) {
        return $this->request( '/customer-paymentInstrument/paymentInstrument/setDefault', array(
            'paymentCustomerId'   => $payment_customer_id,
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
