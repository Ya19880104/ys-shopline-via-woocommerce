<?php
/**
 * Credit Card Subscription Gateway for YS Shopline Payment.
 *
 * @package YS_Shopline_Payment
 */

defined( 'ABSPATH' ) || exit;

/**
 * YS_Shopline_Credit_Subscription Class.
 *
 * Credit card payment gateway with subscription support.
 */
class YS_Shopline_Credit_Subscription extends YS_Shopline_Gateway_Base {

    /**
     * Constructor.
     */
    public function __construct() {
        $this->id                 = 'ys_shopline_credit_subscription';
        $this->icon               = '';
        $this->has_fields         = true;
        $this->method_title       = __( 'SHOPLINE 信用卡定期定額', 'ys-shopline-via-woocommerce' );
        $this->method_description = __( '透過 SHOPLINE Payment 信用卡進行定期定額付款（WooCommerce Subscriptions）', 'ys-shopline-via-woocommerce' );

        // Supports
        $this->supports = array(
            'products',
            'refunds',
            'tokenization',
            'subscriptions',
            'subscription_cancellation',
            'subscription_suspension',
            'subscription_reactivation',
            'subscription_amount_changes',
            'subscription_date_changes',
            'subscription_payment_method_change',
            'subscription_payment_method_change_customer',
            'subscription_payment_method_change_admin',
            'multiple_subscriptions',
        );

        parent::__construct();

        // Subscription hooks
        add_action( 'woocommerce_scheduled_subscription_payment_' . $this->id, array( $this, 'process_subscription_payment' ), 10, 2 );
        add_action( 'woocommerce_subscription_failing_payment_method_updated_' . $this->id, array( $this, 'update_failing_payment_method' ), 10, 2 );
    }

    /**
     * Get payment method for SDK.
     *
     * @return string
     */
    public function get_payment_method() {
        return 'CreditCard';
    }

    /**
     * Get SDK configuration.
     *
     * @return array
     */
    public function get_sdk_config() {
        $config = parent::get_sdk_config();

        // Force save card for subscriptions
        $config['forceSaveCard'] = true;
        $config['paymentInstrument'] = array(
            'bindCard' => array(
                'enable'   => true,
                'protocol' => array(
                    'switchVisible'       => false, // Hide toggle, always save
                    'defaultSwitchStatus' => true,
                    'mustAccept'          => true,
                ),
            ),
        );

        return $config;
    }

    /**
     * Prepare payment data.
     *
     * @param WC_Order $order       Order object.
     * @param string   $pay_session Pay session from SDK.
     * @return array
     */
    protected function prepare_payment_data( $order, $pay_session ) {
        $data = parent::prepare_payment_data( $order, $pay_session );

        // Force CardBindPayment for subscriptions
        $data['confirm']['paymentBehavior'] = 'CardBindPayment';
        $data['confirm']['paymentInstrument']['savePaymentInstrument'] = true;

        return $data;
    }

    /**
     * Process subscription payment.
     *
     * @param float    $amount Renewal amount.
     * @param WC_Order $order  Renewal order.
     */
    public function process_subscription_payment( $amount, $order ) {
        $this->log( 'Processing subscription payment for order #' . $order->get_id() );

        // Get user ID
        $user_id = $order->get_user_id();
        if ( ! $user_id ) {
            $this->log( 'No user ID found for order #' . $order->get_id(), 'error' );
            $order->update_status( 'failed', __( 'Subscription payment failed: No user found.', 'ys-shopline-via-woocommerce' ) );
            return;
        }

        // Get saved token
        $token = $this->get_customer_default_token( $user_id );

        if ( ! $token ) {
            $this->log( 'No payment token found for user #' . $user_id, 'error' );
            $order->update_status( 'failed', __( 'Subscription payment failed: No saved payment method.', 'ys-shopline-via-woocommerce' ) );
            return;
        }

        // Check API
        if ( ! $this->api ) {
            $this->log( 'API not configured', 'error' );
            $order->update_status( 'failed', __( 'Subscription payment failed: Gateway not configured.', 'ys-shopline-via-woocommerce' ) );
            return;
        }

        // Get customer ID
        $customer_id = $this->get_shopline_customer_id( $user_id );

        // Prepare recurring payment data
        $data = array(
            'amount' => array(
                'value'    => YS_Shopline_Payment::get_formatted_amount( $amount, $order->get_currency() ),
                'currency' => $order->get_currency(),
            ),
            'confirm' => array(
                'paymentBehavior'   => 'Recurring',
                'paymentCustomerId' => $customer_id,
                'paymentInstrument' => array(
                    'paymentInstrumentId' => $token->get_token(),
                ),
                'autoConfirm' => true,
                'autoCapture' => true,
            ),
            'order' => array(
                'referenceOrderId' => (string) $order->get_id(),
            ),
        );

        // Make API call
        $response = $this->api->create_payment_trade( $data );

        if ( is_wp_error( $response ) ) {
            $this->log( 'Subscription payment failed: ' . $response->get_error_message(), 'error' );
            $order->update_status( 'failed', __( 'Subscription payment failed: ', 'ys-shopline-via-woocommerce' ) . $response->get_error_message() );
            return;
        }

        // Payment successful
        $trade_order_id = isset( $response['tradeOrderId'] ) ? $response['tradeOrderId'] : '';
        $order->update_meta_data( '_ys_shopline_trade_order_id', $trade_order_id );
        $order->save();

        $order->payment_complete( $trade_order_id );
        $order->add_order_note(
            sprintf(
                /* translators: %s: Trade order ID */
                __( 'Subscription payment completed. Trade ID: %s', 'ys-shopline-via-woocommerce' ),
                $trade_order_id
            )
        );

        $this->log( 'Subscription payment completed for order #' . $order->get_id() );
    }

    /**
     * Get customer's default payment token.
     *
     * @param int $user_id User ID.
     * @return WC_Payment_Token|false
     */
    protected function get_customer_default_token( $user_id ) {
        $tokens = WC_Payment_Tokens::get_customer_tokens( $user_id, $this->id );

        if ( empty( $tokens ) ) {
            return false;
        }

        // Try to get default token first
        foreach ( $tokens as $token ) {
            if ( $token->is_default() ) {
                return $token;
            }
        }

        // Return first available token
        return reset( $tokens );
    }

    /**
     * Update failing payment method.
     *
     * @param WC_Subscription $subscription       Subscription object.
     * @param WC_Order        $renewal_order      Renewal order.
     */
    public function update_failing_payment_method( $subscription, $renewal_order ) {
        // Update subscription meta with new payment token if available
        $new_token_id = isset( $_POST['wc-' . $this->id . '-payment-token'] ) ? absint( $_POST['wc-' . $this->id . '-payment-token'] ) : '';

        if ( $new_token_id ) {
            $token = WC_Payment_Tokens::get( $new_token_id );
            if ( $token && $token->get_user_id() === $subscription->get_user_id() ) {
                $subscription->update_meta_data( '_ys_shopline_token_id', $token->get_token() );
                $subscription->save();
            }
        }
    }

    /**
     * Payment fields.
     */
    public function payment_fields() {
        if ( $this->description ) {
            echo wpautop( wp_kses_post( $this->description ) );
        }

        // Show saved cards for logged in users
        if ( is_user_logged_in() ) {
            $this->saved_payment_methods();
        }

        // Container for SDK
        printf(
            '<div id="%s_container" class="ys-shopline-payment-container" data-gateway="%s" data-payment-method="%s" data-force-save="true" style="min-height: 150px;"></div>',
            esc_attr( $this->id ),
            esc_attr( $this->id ),
            esc_attr( $this->get_payment_method() )
        );

        // Info message
        echo '<p class="ys-shopline-subscription-notice">';
        echo '<small>';
        esc_html_e( '此付款方式會儲存您的信用卡資訊以供定期扣款使用。', 'ys-shopline-via-woocommerce' );
        echo '</small>';
        echo '</p>';
    }

    /**
     * Process payment for zero amount subscriptions.
     *
     * @param int $order_id Order ID.
     * @return array
     */
    public function process_payment( $order_id ) {
        $order = wc_get_order( $order_id );

        if ( ! $order ) {
            wc_add_notice( __( 'Order not found.', 'ys-shopline-via-woocommerce' ), 'error' );
            return array( 'result' => 'failure' );
        }

        $order_total = (float) $order->get_total();

        // Handle zero amount subscription (free trial, etc.)
        if ( 0 === $order_total || 0.0 === $order_total ) {
            return $this->process_zero_amount_subscription( $order );
        }

        // Regular payment
        return parent::process_payment( $order_id );
    }

    /**
     * Process zero amount subscription.
     *
     * @param WC_Order $order Order object.
     * @return array
     */
    protected function process_zero_amount_subscription( $order ) {
        $user_id = $order->get_user_id();

        // Check if user already has a saved token
        $token = $this->get_customer_default_token( $user_id );

        if ( $token ) {
            // User has a saved card, complete the order
            $order->payment_complete();
            $order->add_order_note( __( 'Zero amount subscription started with saved payment method.', 'ys-shopline-via-woocommerce' ) );

            WC()->cart->empty_cart();

            return array(
                'result'   => 'success',
                'redirect' => $this->get_return_url( $order ),
            );
        }

        // Need to collect card for future payments
        // Process as regular payment to save the card
        return parent::process_payment( $order->get_id() );
    }
}
