<?php
/**
 * Subscription Handler for YS Shopline Payment.
 *
 * @package YangSheep\ShoplinePayment\Gateways
 */

namespace YangSheep\ShoplinePayment\Gateways;

defined( 'ABSPATH' ) || exit;

/**
 * YSSubscription Class.
 *
 * Handles subscription-related display logic.
 */
class YSSubscription {

    /**
     * Initialize subscription handler.
     */
    public static function init() {
        $instance = new self();

        // Conditional payment gateway display
        add_filter( 'woocommerce_available_payment_gateways', array( $instance, 'conditional_payment_gateways' ), 10, 1 );
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

        $cart_contains_subscription = class_exists( 'WC_Subscriptions_Cart' ) && \WC_Subscriptions_Cart::cart_contains_subscription();

        // Hide subscription gateway if cart doesn't contain subscription
        if ( ! $cart_contains_subscription && isset( $available_gateways['ys_shopline_credit_subscription'] ) ) {
            unset( $available_gateways['ys_shopline_credit_subscription'] );
        }

        return $available_gateways;
    }
}
