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

        if ( $this->is_order_pay_request() ) {
            $order = $this->get_order_pay_order();
            if ( ! $order || ! $this->order_contains_subscription( $order ) ) {
                $this->hide_subscription_gateway( $available_gateways );
            }

            return $available_gateways;
        }

        if ( ! WC()->cart ) {
            return $available_gateways;
        }

        $cart_contains_subscription = class_exists( 'WC_Subscriptions_Cart' ) && \WC_Subscriptions_Cart::cart_contains_subscription();

        // Hide subscription gateway if cart doesn't contain subscription
        if ( ! $cart_contains_subscription ) {
            $this->hide_subscription_gateway( $available_gateways );
        }

        return $available_gateways;
    }

    /**
     * Check whether the current checkout context is a pay-for-order request.
     *
     * @return bool
     */
    private function is_order_pay_request() {
        return is_wc_endpoint_url( 'order-pay' ) || 0 < absint( get_query_var( 'order-pay' ) );
    }

    /**
     * Resolve the order currently being paid.
     *
     * @return \WC_Order|null
     */
    private function get_order_pay_order() {
        $order_id = absint( get_query_var( 'order-pay' ) );

        if ( ! $order_id ) {
            global $wp;
            $order_id = isset( $wp->query_vars['order-pay'] ) ? absint( $wp->query_vars['order-pay'] ) : 0;
        }

        return $order_id ? wc_get_order( $order_id ) : null;
    }

    /**
     * Check if the order is a subscription payment context.
     *
     * @param \WC_Order $order Order object.
     * @return bool
     */
    private function order_contains_subscription( $order ) {
        return function_exists( 'wcs_order_contains_subscription' ) && wcs_order_contains_subscription( $order );
    }

    /**
     * Remove the SHOPLINE subscription gateway from the available list.
     *
     * @param array $available_gateways Available payment gateways.
     * @return void
     */
    private function hide_subscription_gateway( &$available_gateways ) {
        if ( isset( $available_gateways['ys_shopline_credit_subscription'] ) ) {
            unset( $available_gateways['ys_shopline_credit_subscription'] );
        }
    }
}
