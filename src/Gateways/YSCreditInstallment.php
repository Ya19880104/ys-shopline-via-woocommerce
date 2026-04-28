<?php
/**
 * Credit Card Installment Gateway for YS Shopline Payment.
 *
 * @package YangSheep\ShoplinePayment\Gateways
 */

namespace YangSheep\ShoplinePayment\Gateways;

defined( 'ABSPATH' ) || exit;

use WC_HTTPS;
use YangSheep\ShoplinePayment\Utils\YSOrderMeta;

/**
 * YSCreditInstallment Class.
 *
 * Credit card installment payment gateway.
 */
class YSCreditInstallment extends YSGatewayBase {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id                 = 'ys_shopline_credit_installment';
		$this->icon               = '';
		$this->has_fields         = true;
		$this->method_title       = __( 'SHOPLINE 信用卡分期付款', 'ys-shopline-via-woocommerce' );
		$this->method_description = __( '透過 SHOPLINE Payment 信用卡分期付款', 'ys-shopline-via-woocommerce' );

		// Supports
		$this->supports = array(
			'products',
			'refunds',
			'tokenization',
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
	 * Get the total used to decide whether installmentCounts should be exposed.
	 *
	 * @return float
	 */
	protected function get_installment_context_total() {
		$order = $this->get_installment_context_order();
		if ( $order ) {
			return (float) $order->get_total();
		}

		return WC()->cart ? (float) WC()->cart->get_total( 'edit' ) : 0;
	}

	/**
	 * Resolve the current order for pay-for-order SDK config requests.
	 *
	 * @return \WC_Order|null
	 */
	private function get_installment_context_order() {
		$order_id = 0;

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( isset( $_POST['order_id'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$order_id = absint( wp_unslash( $_POST['order_id'] ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! $order_id && isset( $_GET['pay_for_order'] ) && isset( $_GET['key'] ) ) {
			global $wp;
			$order_id = isset( $wp->query_vars['order-pay'] ) ? absint( $wp->query_vars['order-pay'] ) : 0;
		}

		if ( ! $order_id ) {
			return null;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return null;
		}

		$order_key = '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( isset( $_POST['order_key'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$order_key = sanitize_text_field( wp_unslash( $_POST['order_key'] ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		} elseif ( isset( $_GET['key'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$order_key = sanitize_text_field( wp_unslash( $_GET['key'] ) );
		}

		$current_user_id = get_current_user_id();
		$is_owner        = $current_user_id && (int) $order->get_user_id() === (int) $current_user_id;
		$is_key_valid    = $order_key && hash_equals( $order->get_order_key(), $order_key );

		return ( $is_owner || $is_key_valid ) ? $order : null;
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
			'description' => __( '選擇要提供的分期期數選項。可加入「一次付清」讓客戶保有選擇。', 'ys-shopline-via-woocommerce' ),
			'default'     => array( '3', '6' ),
			'options'     => array(
				'0'  => __( '一次付清', 'ys-shopline-via-woocommerce' ),
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
	 * Check if the gateway is available.
	 *
	 * 訂單金額未達分期最低金額時，隱藏此付款方式。
	 *
	 * @return bool
	 */
	public function is_available() {
		if ( ! parent::is_available() ) {
			return false;
		}

		// 新增付款方式頁面：分期付款不適用（用戶無購物金額可分期）
		if ( function_exists( 'is_add_payment_method_page' ) && is_add_payment_method_page() ) {
			return false;
		}

		$min_amount = (float) $this->get_option( 'min_installment_amount', 3000 );
		$cart_total = $this->get_installment_context_total();

		if ( $cart_total > 0 && $cart_total < $min_amount ) {
			return false;
		}

		return true;
	}

	/**
	 * Get SDK configuration.
	 *
	 * @return array
	 */
	public function get_sdk_config() {
		$config = parent::get_sdk_config();

		// Add installment configuration
		$installments = $this->get_option( 'installments', array() );
		$min_amount   = (float) $this->get_option( 'min_installment_amount', 3000 );
		$cart_total   = $this->get_installment_context_total();

		if ( ! empty( $installments ) && $cart_total >= $min_amount ) {
			$config['installmentCounts'] = array_values( $installments );
		}

		// Add bind card configuration for logged-in users
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
	 * @param \WC_Order $order       Order object.
	 * @param string    $pay_session Pay session from SDK.
	 * @return array
	 */
	protected function prepare_payment_data( $order, $pay_session ) {
		$data = parent::prepare_payment_data( $order, $pay_session );

		// Add installment data if selected (0 = 一次付清，不帶 installment)
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$installment = isset( $_POST['ys_shopline_installment'] ) ? absint( $_POST['ys_shopline_installment'] ) : 0;

		if ( $installment > 0 ) {
			$data['confirm']['paymentMethodOptions'] = array(
				'installments' => array(
					'count' => (string) $installment,
				),
			);
			$order->update_meta_data( YSOrderMeta::INSTALLMENT, $installment );
			$order->save();
		}

		return $data;
	}
}
