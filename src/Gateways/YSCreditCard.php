<?php
/**
 * Credit Card Gateway for YS Shopline Payment.
 *
 * @package YangSheep\ShoplinePayment\Gateways
 */

namespace YangSheep\ShoplinePayment\Gateways;

defined( 'ABSPATH' ) || exit;

use WC_HTTPS;

/**
 * YSCreditCard Class.
 *
 * Credit card single payment gateway (no installment).
 */
class YSCreditCard extends YSGatewayBase {

    /**
     * Constructor.
     */
    public function __construct() {
        $this->id                 = 'ys_shopline_credit';
        $this->icon               = '';
        $this->has_fields         = true;
        $this->method_title       = __( 'SHOPLINE 信用卡', 'ys-shopline-via-woocommerce' );
        $this->method_description = __( '透過 SHOPLINE Payment 信用卡一次付清', 'ys-shopline-via-woocommerce' );

        // Supports
        $this->supports = array(
            'products',
            'refunds',
            'tokenization',
            'add_payment_method',
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
     * Get SDK configuration.
     *
     * @return array
     */
    public function get_sdk_config() {
        $config = parent::get_sdk_config();

        // Add bind card configuration
        // 只有已登入用戶且有 customerToken 時才啟用儲存卡片功能
        $has_customer_token = isset( $config['customerToken'] ) && ! empty( $config['customerToken'] );

        if ( $has_customer_token ) {
            $config['paymentInstrument'] = array(
                'bindCard' => array(
                    'enable'   => true,
                    'protocol' => array(
                        'switchVisible'       => true,
                        'defaultSwitchStatus' => false,
                        'mustAccept'          => false,
                    ),
                ),
            );
        }

        return $config;
    }

    /**
     * Payment fields.
     */
    public function payment_fields() {
        if ( $this->description ) {
            echo wpautop( wp_kses_post( $this->description ) );
        }

        // WC 原生 tokenization：顯示已綁卡 radio（若有）+ 「使用新卡」選項
        if ( $this->supports( 'tokenization' ) && is_checkout() ) {
            $this->tokenization_script();
            $this->saved_payment_methods();
        }

        // SDK 容器（選「使用新卡」時顯示）
        printf(
            '<div id="%s_container" class="ys-shopline-payment-container ys-shopline-new-card-container" data-gateway="%s" data-payment-method="%s" style="min-height: 150px;"></div>',
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
}
