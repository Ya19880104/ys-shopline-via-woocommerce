<?php
/**
 * Credit Card Gateway for YS Shopline Payment.
 *
 * @package YS_Shopline_Payment
 */

defined( 'ABSPATH' ) || exit;

/**
 * YS_Shopline_Credit_Card Class.
 *
 * Credit card payment gateway with installment support.
 */
class YS_Shopline_Credit_Card extends YS_Shopline_Gateway_Base {

    /**
     * Constructor.
     */
    public function __construct() {
        $this->id                 = 'ys_shopline_credit';
        $this->icon               = '';
        $this->has_fields         = true;
        $this->method_title       = __( 'SHOPLINE 信用卡', 'ys-shopline-via-woocommerce' );
        $this->method_description = __( '透過 SHOPLINE Payment 信用卡付款（支援分期）', 'ys-shopline-via-woocommerce' );

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
        return 'CreditCard';
    }

    /**
     * Initialize gateway settings form fields.
     */
    public function init_form_fields() {
        parent::init_form_fields();

        // Add installment options
        $this->form_fields['installments'] = array(
            'title'       => __( '分期期數', 'ys-shopline-via-woocommerce' ),
            'type'        => 'multiselect',
            'class'       => 'wc-enhanced-select',
            'description' => __( '選擇要提供的分期期數選項。', 'ys-shopline-via-woocommerce' ),
            'default'     => array(),
            'options'     => array(
                '3'  => __( '3 期', 'ys-shopline-via-woocommerce' ),
                '6'  => __( '6 期', 'ys-shopline-via-woocommerce' ),
                '9'  => __( '9 期', 'ys-shopline-via-woocommerce' ),
                '12' => __( '12 期', 'ys-shopline-via-woocommerce' ),
                '18' => __( '18 期', 'ys-shopline-via-woocommerce' ),
                '24' => __( '24 期', 'ys-shopline-via-woocommerce' ),
            ),
            'desc_tip'    => true,
        );

        $this->form_fields['min_installment_amount'] = array(
            'title'       => __( '分期最低金額', 'ys-shopline-via-woocommerce' ),
            'type'        => 'number',
            'description' => __( '訂單金額達到此金額時才顯示分期選項。', 'ys-shopline-via-woocommerce' ),
            'default'     => '3000',
            'desc_tip'    => true,
            'custom_attributes' => array(
                'min'  => '0',
                'step' => '100',
            ),
        );
    }

    /**
     * Get SDK configuration.
     *
     * @return array
     */
    public function get_sdk_config() {
        $config = parent::get_sdk_config();

        // Add installment configuration
        $config['paymentInstrument'] = array(
            'bindCard' => array(
                'enable'   => true,
                'protocol' => array(
                    'switchVisible'       => true,
                    'defaultSwitchStatus' => true,
                    'mustAccept'          => false,
                ),
            ),
        );

        return $config;
    }

    /**
     * Payment fields.
     */
    public function payment_fields() {
        if ( $this->description ) {
            echo wpautop( wp_kses_post( $this->description ) );
        }

        // Container for SDK
        printf(
            '<div id="%s_container" class="ys-shopline-payment-container" data-gateway="%s" data-payment-method="%s" style="min-height: 150px;"></div>',
            esc_attr( $this->id ),
            esc_attr( $this->id ),
            esc_attr( $this->get_payment_method() )
        );
    }

    /**
     * Get icon HTML.
     *
     * @return string
     */
    public function get_icon() {
        $icons = array();

        $icons[] = '<img src="' . WC_HTTPS::force_https_url( WC()->plugin_url() . '/assets/images/icons/credit-cards/visa.svg' ) . '" alt="Visa" width="32" />';
        $icons[] = '<img src="' . WC_HTTPS::force_https_url( WC()->plugin_url() . '/assets/images/icons/credit-cards/mastercard.svg' ) . '" alt="Mastercard" width="32" />';
        $icons[] = '<img src="' . WC_HTTPS::force_https_url( WC()->plugin_url() . '/assets/images/icons/credit-cards/jcb.svg' ) . '" alt="JCB" width="32" />';

        return apply_filters( 'woocommerce_gateway_icon', implode( ' ', $icons ), $this->id );
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

        // Add installment data if selected
        $installment = isset( $_POST['ys_shopline_installment'] ) ? absint( $_POST['ys_shopline_installment'] ) : 0;

        if ( $installment > 0 ) {
            $data['confirm']['installment'] = $installment;
            $order->update_meta_data( '_ys_shopline_installment', $installment );
            $order->save();
        }

        return $data;
    }
}
