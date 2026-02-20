<?php
/**
 * JKOPay Gateway for YS Shopline Payment.
 *
 * @package YangSheep\ShoplinePayment\Gateways
 */

namespace YangSheep\ShoplinePayment\Gateways;

defined( 'ABSPATH' ) || exit;

/**
 * YSJKOPay Class.
 *
 * JKOPay (街口支付) payment gateway.
 */
class YSJKOPay extends YSGatewayBase {

    /**
     * Constructor.
     */
    public function __construct() {
        $this->id                 = 'ys_shopline_jkopay';
        $this->icon               = '';
        $this->has_fields         = true;
        $this->method_title       = __( 'SHOPLINE 街口支付', 'ys-shopline-via-woocommerce' );
        $this->method_description = __( '透過 SHOPLINE Payment 街口支付付款', 'ys-shopline-via-woocommerce' );

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
        return 'JKOPay';
    }

    /**
     * Payment fields.
     */
    public function payment_fields() {
        if ( $this->description ) {
            echo wpautop( wp_kses_post( $this->description ) );
        }

        // Info message
        echo '<p class="ys-shopline-jkopay-notice">';
        echo '<small>';
        esc_html_e( '點擊結帳後將顯示 QR Code 或開啟街口支付 APP 完成付款。', 'ys-shopline-via-woocommerce' );
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

        // 街口支付使用一般付款，不需要卡片綁定
        $data['confirm']['paymentBehavior'] = 'Regular';
        unset( $data['confirm']['paymentInstrument'] );

        return $data;
    }

    /**
     * Get icon HTML.
     *
     * @return string
     */
    public function get_icon() {
        $icon_url = $this->icon;

        if ( empty( $icon_url ) ) {
            // Use CDN fallback for JKOPay icon
            $icon_url = 'https://www.jkopay.com/static/images/logo.svg';
        }

        $icon = '<img src="' . esc_url( $icon_url ) . '" alt="街口支付" style="max-height: 28px; margin-right: 5px;" />';

        return apply_filters( 'woocommerce_gateway_icon', $icon, $this->id );
    }
}
