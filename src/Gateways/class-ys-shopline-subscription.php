<?php
/**
 * Subscription Handler for YS Shopline Payment.
 *
 * @package YS_Shopline_Payment
 */

defined( 'ABSPATH' ) || exit;

// Don't load if WooCommerce Subscriptions is not active
if ( ! class_exists( 'WC_Subscriptions' ) ) {
    return;
}

/**
 * YS_Shopline_Subscription Class.
 *
 * Handles subscription-related hooks and logic.
 */
class YS_Shopline_Subscription {

    /**
     * Initialize subscription handler.
     */
    public static function init() {
        $instance = new self();

        // Conditional payment gateway display
        add_filter( 'woocommerce_available_payment_gateways', array( $instance, 'conditional_payment_gateways' ), 10, 1 );

        // Handle subscription renewal failure
        add_action( 'woocommerce_subscription_renewal_payment_failed', array( $instance, 'subscription_fail_handler' ), 99, 2 );

        // Copy meta data to renewal order
        add_filter( 'wcs_renewal_order_meta', array( $instance, 'copy_meta_to_renewal' ), 10, 3 );

        // Admin scripts
        add_action( 'admin_enqueue_scripts', array( $instance, 'admin_scripts' ) );
    }

    /**
     * Conditionally show/hide payment gateways based on cart contents.
     *
     * @param array $available_gateways Available payment gateways.
     * @return array
     */
    public function conditional_payment_gateways( $available_gateways ) {
        if ( ! is_checkout() && ! is_add_payment_method_page() ) {
            return $available_gateways;
        }

        if ( ! WC()->cart ) {
            return $available_gateways;
        }

        $cart_contains_subscription = class_exists( 'WC_Subscriptions_Cart' ) && WC_Subscriptions_Cart::cart_contains_subscription();

        // Hide subscription gateway if cart doesn't contain subscription
        if ( ! $cart_contains_subscription && isset( $available_gateways['ys_shopline_credit_subscription'] ) ) {
            unset( $available_gateways['ys_shopline_credit_subscription'] );
        }

        // Hide regular credit card if cart contains subscription (optional - can show both)
        // Uncomment below if you want to force subscription gateway for subscription products
        /*
        if ( $cart_contains_subscription && isset( $available_gateways['ys_shopline_credit'] ) ) {
            unset( $available_gateways['ys_shopline_credit'] );
        }
        */

        return $available_gateways;
    }

    /**
     * Handle subscription payment failure.
     *
     * @param WC_Subscription $subscription Subscription object.
     * @param WC_Order        $last_order   Last renewal order.
     */
    public function subscription_fail_handler( $subscription, $last_order ) {
        $order = $last_order;

        // Only handle our gateway
        if ( 'ys_shopline_credit_subscription' !== $order->get_payment_method() ) {
            return;
        }

        // Check if order is actually failed
        if ( 'failed' !== $order->get_status() ) {
            return;
        }

        // Check if payment was actually successful (might be misreported)
        $status = $order->get_meta( '_ys_shopline_payment_status' );
        if ( 'SUCCESS' === $status ) {
            return;
        }

        // Update order to pending for retry
        $order->update_status( 'pending', __( 'Subscription renewal payment failed. Awaiting retry.', 'ys-shopline-via-woocommerce' ) );

        YS_Shopline_Logger::warning( 'Subscription renewal failed for order #' . $order->get_id() );
    }

    /**
     * Copy relevant meta data to renewal order.
     *
     * @param array           $meta         Meta data to copy.
     * @param WC_Order        $to_order     New renewal order.
     * @param WC_Subscription $subscription Subscription object.
     * @return array
     */
    public function copy_meta_to_renewal( $meta, $to_order, $subscription ) {
        // Meta keys to copy from parent order to renewal
        $keys_to_copy = array(
            '_ys_shopline_customer_id',
            '_ys_shopline_token_id',
        );

        foreach ( $keys_to_copy as $key ) {
            $value = $subscription->get_meta( $key );
            if ( $value ) {
                $to_order->update_meta_data( $key, $value );
            }
        }

        return $meta;
    }

    /**
     * Enqueue admin scripts.
     */
    public function admin_scripts() {
        $screen = get_current_screen();

        if ( ! $screen || 'shop_subscription' !== $screen->id ) {
            return;
        }

        // Can add admin scripts for subscription management here
    }

    /**
     * Get subscription's parent order payment method token.
     *
     * @param WC_Subscription $subscription Subscription object.
     * @return string|false Token or false if not found.
     */
    public static function get_subscription_token( $subscription ) {
        // First try subscription meta
        $token_id = $subscription->get_meta( '_ys_shopline_token_id' );

        if ( $token_id ) {
            return $token_id;
        }

        // Try parent order
        $parent_order = $subscription->get_parent();
        if ( $parent_order ) {
            $token_id = $parent_order->get_meta( '_ys_shopline_token_id' );
            if ( $token_id ) {
                return $token_id;
            }
        }

        // Try WC Payment Tokens
        $user_id = $subscription->get_user_id();
        if ( $user_id ) {
            $tokens = WC_Payment_Tokens::get_customer_tokens( $user_id, 'ys_shopline_credit_subscription' );
            if ( ! empty( $tokens ) ) {
                // Get default or first token
                foreach ( $tokens as $token ) {
                    if ( $token->is_default() ) {
                        return $token->get_token();
                    }
                }
                $first_token = reset( $tokens );
                return $first_token->get_token();
            }
        }

        return false;
    }

    /**
     * Check if a subscription can be paid with saved payment method.
     *
     * @param WC_Subscription $subscription Subscription object.
     * @return bool
     */
    public static function can_charge_subscription( $subscription ) {
        return false !== self::get_subscription_token( $subscription );
    }
}
