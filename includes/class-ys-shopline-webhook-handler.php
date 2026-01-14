<?php
/**
 * Webhook Handler for YS Shopline Payment.
 *
 * @package YS_Shopline_Payment
 */

defined( 'ABSPATH' ) || exit;

/**
 * YS_Shopline_Webhook_Handler Class.
 *
 * Handles incoming webhooks from Shopline Payment.
 */
class YS_Shopline_Webhook_Handler {

    /**
     * Constructor.
     */
    public function __construct() {
        add_action( 'woocommerce_api_ys_shopline_webhook', array( $this, 'handle_webhook' ) );
    }

    /**
     * Handle incoming webhook.
     */
    public function handle_webhook() {
        if ( 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
            wp_die( 'Invalid request method', 'Shopline Webhook', array( 'response' => 405 ) );
        }

        $body = file_get_contents( 'php://input' );
        $json = json_decode( $body, true );

        YS_Shopline_Logger::info( 'Webhook received', array( 'body' => $body ) );

        if ( ! $json ) {
            YS_Shopline_Logger::error( 'Invalid JSON in webhook body' );
            status_header( 400 );
            exit;
        }

        // Verify signature
        if ( ! $this->verify_signature( $body ) ) {
            YS_Shopline_Logger::error( 'Webhook signature verification failed' );
            status_header( 401 );
            exit;
        }

        // Process the event
        $this->process_event( $json );

        status_header( 200 );
        exit;
    }

    /**
     * Verify webhook signature.
     *
     * @param string $body Raw request body.
     * @return bool
     */
    private function verify_signature( $body ) {
        // Get sign key based on test mode
        $test_mode = 'yes' === get_option( 'ys_shopline_testmode', 'yes' );
        $sign_key  = $test_mode
            ? get_option( 'ys_shopline_sandbox_sign_key', '' )
            : get_option( 'ys_shopline_sign_key', '' );

        if ( empty( $sign_key ) ) {
            YS_Shopline_Logger::warning( 'No sign key configured, skipping verification' );
            return true; // Allow if no key configured (not recommended for production)
        }

        // Get headers
        $headers = $this->get_headers();

        $sign      = isset( $headers['sign'] ) ? $headers['sign'] : '';
        $timestamp = isset( $headers['timestamp'] ) ? $headers['timestamp'] : '';

        if ( empty( $sign ) || empty( $timestamp ) ) {
            YS_Shopline_Logger::error( 'Missing signature headers', array(
                'sign'      => ! empty( $sign ),
                'timestamp' => ! empty( $timestamp ),
            ) );
            return false;
        }

        // Check timestamp freshness (5 minutes)
        $current_time = time() * 1000;
        $diff         = abs( $current_time - (float) $timestamp );

        if ( $diff > 300000 ) {
            YS_Shopline_Logger::error( 'Webhook timestamp expired', array(
                'timestamp'    => $timestamp,
                'current_time' => $current_time,
                'diff'         => $diff,
            ) );
            return false;
        }

        // Calculate signature
        $payload         = $timestamp . '.' . $body;
        $calculated_sign = hash_hmac( 'sha256', $payload, $sign_key );

        return hash_equals( $calculated_sign, $sign );
    }

    /**
     * Get request headers.
     *
     * @return array
     */
    private function get_headers() {
        $headers = array();

        if ( function_exists( 'getallheaders' ) ) {
            $headers = array_change_key_case( getallheaders(), CASE_LOWER );
        } else {
            // Fallback for servers without getallheaders
            foreach ( $_SERVER as $key => $value ) {
                if ( 'HTTP_' === substr( $key, 0, 5 ) ) {
                    $header_name             = str_replace( '_', '-', strtolower( substr( $key, 5 ) ) );
                    $headers[ $header_name ] = $value;
                }
            }
        }

        return $headers;
    }

    /**
     * Process webhook event.
     *
     * @param array $event Event data.
     */
    private function process_event( $event ) {
        $type = isset( $event['type'] ) ? $event['type'] : '';

        YS_Shopline_Logger::info( "Processing webhook event: $type" );

        switch ( $type ) {
            case 'trade.succeeded':
                $this->handle_trade_succeeded( $event );
                break;

            case 'trade.failed':
                $this->handle_trade_failed( $event );
                break;

            case 'trade.authorized':
                $this->handle_trade_authorized( $event );
                break;

            case 'trade.captured':
                $this->handle_trade_captured( $event );
                break;

            case 'paymentInstrument.created':
                $this->handle_payment_instrument_created( $event );
                break;

            case 'paymentInstrument.deleted':
                $this->handle_payment_instrument_deleted( $event );
                break;

            case 'refund.succeeded':
                $this->handle_refund_succeeded( $event );
                break;

            case 'refund.failed':
                $this->handle_refund_failed( $event );
                break;

            default:
                YS_Shopline_Logger::info( "Unhandled webhook event type: $type" );
                break;
        }

        do_action( 'ys_shopline_webhook_processed', $type, $event );
    }

    /**
     * Handle trade succeeded event.
     *
     * @param array $event Event data.
     */
    private function handle_trade_succeeded( $event ) {
        $data = isset( $event['data'] ) ? $event['data'] : array();

        if ( empty( $data ) ) {
            return;
        }

        $trade_order_id = isset( $data['tradeOrderId'] ) ? $data['tradeOrderId'] : '';

        if ( ! $trade_order_id ) {
            return;
        }

        $order = $this->get_order_by_trade_id( $trade_order_id );

        if ( ! $order ) {
            YS_Shopline_Logger::error( "Order not found for trade ID: $trade_order_id" );
            return;
        }

        // Check if already processed
        if ( $order->is_paid() ) {
            YS_Shopline_Logger::info( 'Order already paid, skipping: ' . $order->get_id() );
            return;
        }

        // Complete payment
        $order->payment_complete( $trade_order_id );
        $order->add_order_note(
            sprintf(
                /* translators: %s: Trade order ID */
                __( 'Shopline payment confirmed via webhook. Trade ID: %s', 'ys-shopline-via-woocommerce' ),
                $trade_order_id
            )
        );

        // Store additional data
        if ( isset( $data['paymentMethod'] ) ) {
            $order->update_meta_data( '_ys_shopline_payment_method', $data['paymentMethod'] );
        }

        $order->update_meta_data( '_ys_shopline_payment_status', 'SUCCESS' );
        $order->save();

        YS_Shopline_Logger::info( 'Trade succeeded processed for order: ' . $order->get_id() );
    }

    /**
     * Handle trade failed event.
     *
     * @param array $event Event data.
     */
    private function handle_trade_failed( $event ) {
        $data = isset( $event['data'] ) ? $event['data'] : array();

        if ( empty( $data ) ) {
            return;
        }

        $trade_order_id = isset( $data['tradeOrderId'] ) ? $data['tradeOrderId'] : '';

        if ( ! $trade_order_id ) {
            return;
        }

        $order = $this->get_order_by_trade_id( $trade_order_id );

        if ( ! $order ) {
            return;
        }

        // Don't update if already completed
        if ( $order->is_paid() ) {
            return;
        }

        $error_message = isset( $data['errorMessage'] ) ? $data['errorMessage'] : __( 'Payment failed', 'ys-shopline-via-woocommerce' );

        $order->update_status( 'failed', $error_message );
        $order->update_meta_data( '_ys_shopline_payment_status', 'FAILED' );
        $order->save();

        YS_Shopline_Logger::info( 'Trade failed processed for order: ' . $order->get_id() );
    }

    /**
     * Handle trade authorized event.
     *
     * @param array $event Event data.
     */
    private function handle_trade_authorized( $event ) {
        $data = isset( $event['data'] ) ? $event['data'] : array();

        if ( empty( $data ) ) {
            return;
        }

        $trade_order_id = isset( $data['tradeOrderId'] ) ? $data['tradeOrderId'] : '';

        if ( ! $trade_order_id ) {
            return;
        }

        $order = $this->get_order_by_trade_id( $trade_order_id );

        if ( ! $order ) {
            return;
        }

        $order->add_order_note( __( 'Shopline payment authorized, awaiting capture.', 'ys-shopline-via-woocommerce' ) );
        $order->update_meta_data( '_ys_shopline_payment_status', 'AUTHORIZED' );
        $order->save();
    }

    /**
     * Handle trade captured event.
     *
     * @param array $event Event data.
     */
    private function handle_trade_captured( $event ) {
        $data = isset( $event['data'] ) ? $event['data'] : array();

        if ( empty( $data ) ) {
            return;
        }

        $trade_order_id = isset( $data['tradeOrderId'] ) ? $data['tradeOrderId'] : '';

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
        $order->update_meta_data( '_ys_shopline_payment_status', 'CAPTURED' );
        $order->save();
    }

    /**
     * Handle payment instrument created event.
     *
     * @param array $event Event data.
     */
    private function handle_payment_instrument_created( $event ) {
        $data = isset( $event['data'] ) ? $event['data'] : array();

        if ( empty( $data ) ) {
            return;
        }

        $payment_instrument_id = isset( $data['id'] ) ? $data['id'] : '';
        $payment_customer_id   = isset( $data['paymentCustomerId'] ) ? $data['paymentCustomerId'] : '';
        $card_info             = isset( $data['card'] ) ? $data['card'] : array();

        if ( ! $payment_instrument_id || ! $payment_customer_id ) {
            return;
        }

        // Find user by customer ID
        $users = get_users( array(
            'meta_key'   => '_ys_shopline_customer_id',
            'meta_value' => $payment_customer_id,
            'number'     => 1,
        ) );

        if ( empty( $users ) ) {
            YS_Shopline_Logger::error( "User not found for customer ID: $payment_customer_id" );
            return;
        }

        $user_id = $users[0]->ID;

        // Check if token already exists
        $existing_tokens = WC_Payment_Tokens::get_customer_tokens( $user_id, 'ys_shopline_credit_subscription' );

        foreach ( $existing_tokens as $existing_token ) {
            if ( $existing_token->get_token() === $payment_instrument_id ) {
                return; // Token already exists
            }
        }

        // Create new token
        $token = new WC_Payment_Token_CC();
        $token->set_token( $payment_instrument_id );
        $token->set_gateway_id( 'ys_shopline_credit_subscription' );
        $token->set_card_type( strtolower( isset( $card_info['brand'] ) ? $card_info['brand'] : 'card' ) );
        $token->set_last4( isset( $card_info['last4'] ) ? $card_info['last4'] : '****' );
        $token->set_expiry_month( isset( $card_info['expMonth'] ) ? $card_info['expMonth'] : '' );
        $token->set_expiry_year( isset( $card_info['expYear'] ) ? $card_info['expYear'] : '' );
        $token->set_user_id( $user_id );

        // Set as default if first token
        if ( empty( $existing_tokens ) ) {
            $token->set_default( true );
        }

        $token->save();

        YS_Shopline_Logger::info( "Payment token created for user: $user_id" );
    }

    /**
     * Handle payment instrument deleted event.
     *
     * @param array $event Event data.
     */
    private function handle_payment_instrument_deleted( $event ) {
        $data = isset( $event['data'] ) ? $event['data'] : array();

        if ( empty( $data ) ) {
            return;
        }

        $payment_instrument_id = isset( $data['id'] ) ? $data['id'] : '';

        if ( ! $payment_instrument_id ) {
            return;
        }

        // Find and delete the token
        global $wpdb;

        $token_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT token_id FROM {$wpdb->prefix}woocommerce_payment_tokens WHERE token = %s",
                $payment_instrument_id
            )
        );

        if ( $token_id ) {
            WC_Payment_Tokens::delete( $token_id );
            YS_Shopline_Logger::info( "Payment token deleted: $payment_instrument_id" );
        }
    }

    /**
     * Handle refund succeeded event.
     *
     * @param array $event Event data.
     */
    private function handle_refund_succeeded( $event ) {
        $data = isset( $event['data'] ) ? $event['data'] : array();

        if ( empty( $data ) ) {
            return;
        }

        $refund_order_id = isset( $data['refundOrderId'] ) ? $data['refundOrderId'] : '';
        $trade_order_id  = isset( $data['tradeOrderId'] ) ? $data['tradeOrderId'] : '';

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

        YS_Shopline_Logger::info( 'Refund succeeded for order: ' . $order->get_id() );
    }

    /**
     * Handle refund failed event.
     *
     * @param array $event Event data.
     */
    private function handle_refund_failed( $event ) {
        $data = isset( $event['data'] ) ? $event['data'] : array();

        if ( empty( $data ) ) {
            return;
        }

        $trade_order_id = isset( $data['tradeOrderId'] ) ? $data['tradeOrderId'] : '';

        if ( ! $trade_order_id ) {
            return;
        }

        $order = $this->get_order_by_trade_id( $trade_order_id );

        if ( ! $order ) {
            return;
        }

        $error_message = isset( $data['errorMessage'] ) ? $data['errorMessage'] : __( 'Refund failed', 'ys-shopline-via-woocommerce' );

        $order->add_order_note( __( 'Shopline refund failed: ', 'ys-shopline-via-woocommerce' ) . $error_message );

        YS_Shopline_Logger::error( 'Refund failed for order: ' . $order->get_id() . ' - ' . $error_message );
    }

    /**
     * Get order by trade ID.
     *
     * @param string $trade_order_id Trade order ID.
     * @return WC_Order|false
     */
    private function get_order_by_trade_id( $trade_order_id ) {
        $orders = wc_get_orders( array(
            'limit'      => 1,
            'meta_key'   => '_ys_shopline_trade_order_id',
            'meta_value' => $trade_order_id,
        ) );

        return ! empty( $orders ) ? $orders[0] : false;
    }
}
