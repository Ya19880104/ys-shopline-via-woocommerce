<?php
/**
 * Apple Pay Gateway for YS Shopline Payment.
 *
 * @package YangSheep\ShoplinePayment\Gateways
 */

namespace YangSheep\ShoplinePayment\Gateways;

defined( 'ABSPATH' ) || exit;

use WC_HTTPS;

/**
 * YSApplePay Class.
 *
 * Apple Pay payment gateway.
 */
class YSApplePay extends YSGatewayBase {

    /**
     * Constructor.
     */
    public function __construct() {
        $this->id                 = 'ys_shopline_applepay';
        $this->icon               = '';
        $this->has_fields         = true;
        $this->method_title       = __( 'SHOPLINE Apple Pay', 'ys-shopline-via-woocommerce' );
        $this->method_description = __( '透過 SHOPLINE Payment Apple Pay 付款', 'ys-shopline-via-woocommerce' );

        // Supports
        $this->supports = array(
            'products',
            'refunds',
        );

        parent::__construct();
    }

    /**
     * Get payment method for SDK.
     *
     * @return string
     */
    public function get_payment_method() {
        return 'ApplePay';
    }

    /**
     * Check if gateway is available.
     *
     * @return bool
     */
    public function is_available() {
        if ( ! parent::is_available() ) {
            return false;
        }

        // Apple Pay requires HTTPS
        if ( ! is_ssl() && ! $this->testmode ) {
            return false;
        }

        return true;
    }

    /**
     * Payment fields.
     */
    public function payment_fields() {
        if ( $this->description ) {
            echo wpautop( wp_kses_post( $this->description ) );
        }

        // Info message
        echo '<p class="ys-shopline-applepay-notice">';
        echo '<small>';
        esc_html_e( '支援 iPhone、iPad、Mac 及其他裝置掃碼付款。', 'ys-shopline-via-woocommerce' );
        echo '</small>';
        echo '</p>';

        // Container for SDK - Apple Pay button will be rendered here
        printf(
            '<div id="%s_container" class="ys-shopline-payment-container" data-gateway="%s" data-payment-method="%s" style="min-height: 50px;"></div>',
            esc_attr( $this->id ),
            esc_attr( $this->id ),
            esc_attr( $this->get_payment_method() )
        );
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

        // Apple Pay doesn't support card binding
        $data['confirm']['paymentBehavior'] = 'QuickPayment';
        unset( $data['confirm']['paymentInstrument']['savePaymentInstrument'] );

        return $data;
    }

    /**
     * Get icon HTML.
     *
     * @return string
     */
    public function get_icon() {
        $icon_url = $this->icon;

        if ( ! $icon_url ) {
            // Default Apple Pay icon
            $icon_url = 'https://cdn.shoplinepayments.com/assets/images/apple-pay-mark.svg';
        }

        $icon = '<img src="' . WC_HTTPS::force_https_url( $icon_url ) . '" alt="Apple Pay" style="max-height: 32px; margin-right: 5px;" />';

        return apply_filters( 'woocommerce_gateway_icon', $icon, $this->id );
    }
}
