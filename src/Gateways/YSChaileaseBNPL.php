<?php
/**
 * Chailease BNPL Gateway for YS Shopline Payment.
 *
 * @package YangSheep\ShoplinePayment\Gateways
 */

namespace YangSheep\ShoplinePayment\Gateways;

defined( 'ABSPATH' ) || exit;

use WC_HTTPS;
use YangSheep\ShoplinePayment\Utils\YSOrderMeta;

/**
 * YSChaileaseBNPL Class.
 *
 * Chailease zingla BNPL (中租銀角零卡) payment gateway.
 */
class YSChaileaseBNPL extends YSGatewayBase {

    /**
     * Constructor.
     */
    public function __construct() {
        $this->id                 = 'ys_shopline_bnpl';
        $this->icon               = '';
        $this->has_fields         = true;
        $this->method_title       = __( 'SHOPLINE 中租 zingla 銀角零卡', 'ys-shopline-via-woocommerce' );
        $this->method_description = __( '透過 SHOPLINE Payment 中租 zingla BNPL 先買後付', 'ys-shopline-via-woocommerce' );

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
        return 'ChaileaseBNPL';
    }

    /**
     * Initialize gateway settings form fields.
     */
    public function init_form_fields() {
        parent::init_form_fields();

        $this->form_fields['installments'] = array(
            'title'       => __( '分期期數', 'ys-shopline-via-woocommerce' ),
            'type'        => 'multiselect',
            'class'       => 'wc-enhanced-select',
            'description' => __( '選擇要提供的分期期數選項。', 'ys-shopline-via-woocommerce' ),
            'default'     => array( '3', '6', '12' ),
            'options'     => array(
                '1'  => __( '一次付清', 'ys-shopline-via-woocommerce' ),
                '3'  => __( '3 期', 'ys-shopline-via-woocommerce' ),
                '6'  => __( '6 期', 'ys-shopline-via-woocommerce' ),
                '12' => __( '12 期', 'ys-shopline-via-woocommerce' ),
                '18' => __( '18 期', 'ys-shopline-via-woocommerce' ),
                '24' => __( '24 期', 'ys-shopline-via-woocommerce' ),
                '30' => __( '30 期', 'ys-shopline-via-woocommerce' ),
                '36' => __( '36 期', 'ys-shopline-via-woocommerce' ),
            ),
            'desc_tip'    => true,
        );

        $this->form_fields['min_amount'] = array(
            'title'       => __( '最低消費金額', 'ys-shopline-via-woocommerce' ),
            'type'        => 'number',
            'description' => __( '訂單金額達到此金額時才顯示此付款方式。', 'ys-shopline-via-woocommerce' ),
            'default'     => '0',
            'desc_tip'    => true,
            'custom_attributes' => array(
                'min'  => '0',
                'step' => '100',
            ),
        );

        $this->form_fields['max_amount'] = array(
            'title'       => __( '最高消費金額', 'ys-shopline-via-woocommerce' ),
            'type'        => 'number',
            'description' => __( '訂單金額超過此金額時將隱藏此付款方式。設為 0 表示無上限。', 'ys-shopline-via-woocommerce' ),
            'default'     => '0',
            'desc_tip'    => true,
            'custom_attributes' => array(
                'min'  => '0',
                'step' => '100',
            ),
        );
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

        // Check cart total against min/max amount
        if ( WC()->cart ) {
            $cart_total = WC()->cart->get_total( 'edit' );
            $min_amount = floatval( $this->get_option( 'min_amount', '0' ) );
            $max_amount = floatval( $this->get_option( 'max_amount', '0' ) );

            if ( $min_amount > 0 && $cart_total < $min_amount ) {
                return false;
            }

            if ( $max_amount > 0 && $cart_total > $max_amount ) {
                return false;
            }
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
        echo '<p class="ys-shopline-bnpl-notice">';
        echo '<small>';
        esc_html_e( '中租 zingla 銀角零卡 - 先買後付，支援分期付款。', 'ys-shopline-via-woocommerce' );
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

        // BNPL 使用一般付款，不需要卡片綁定
        $data['confirm']['paymentBehavior'] = 'Regular';
        unset( $data['confirm']['paymentInstrument'] );

        // Add installment data if selected
        $installment = isset( $_POST['ys_shopline_bnpl_installment'] ) ? absint( $_POST['ys_shopline_bnpl_installment'] ) : 0;

        if ( $installment > 0 ) {
            $data['confirm']['installment'] = $installment;
            $order->update_meta_data( YSOrderMeta::BNPL_INSTALLMENT, $installment );
            $order->save();
        }

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
            $icon_url = 'https://cdn.shoplinepayments.com/assets/images/zingla-logo.png';
        }

        $icon = '<img src="' . WC_HTTPS::force_https_url( $icon_url ) . '" alt="中租 zingla" style="max-height: 28px; margin-right: 5px;" />';

        return apply_filters( 'woocommerce_gateway_icon', $icon, $this->id );
    }
}
