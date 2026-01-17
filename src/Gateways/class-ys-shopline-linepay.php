<?php
/**
 * LINE Pay Gateway for YS Shopline Payment.
 *
 * @package YS_Shopline_Payment
 */

defined( 'ABSPATH' ) || exit;

/**
 * YS_Shopline_LinePay Class.
 *
 * LINE Pay payment gateway.
 */
class YS_Shopline_LinePay extends YS_Shopline_Gateway_Base {

    /**
     * Constructor.
     */
    public function __construct() {
        $this->id                 = 'ys_shopline_linepay';
        $this->icon               = '';
        $this->has_fields         = true;
        $this->method_title       = __( 'SHOPLINE LINE Pay', 'ys-shopline-via-woocommerce' );
        $this->method_description = __( '透過 SHOPLINE Payment LINE Pay 付款', 'ys-shopline-via-woocommerce' );

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
        return 'LinePay';
    }

    /**
     * Payment fields.
     */
    public function payment_fields() {
        if ( $this->description ) {
            echo wpautop( wp_kses_post( $this->description ) );
        }

        // Info message
        echo '<p class="ys-shopline-linepay-notice">';
        echo '<small>';
        esc_html_e( '點擊結帳後將顯示 QR Code 或開啟 LINE Pay 完成付款。', 'ys-shopline-via-woocommerce' );
        echo '</small>';
        echo '</p>';

        // Container for SDK
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

        // LINE Pay doesn't support card binding
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
            // Default LINE Pay icon
            $icon_url = 'https://cdn.shoplinepayments.com/assets/images/line-pay-logo.png';
        }

        $icon = '<img src="' . WC_HTTPS::force_https_url( $icon_url ) . '" alt="LINE Pay" style="max-height: 28px; margin-right: 5px;" />';

        return apply_filters( 'woocommerce_gateway_icon', $icon, $this->id );
    }
}
