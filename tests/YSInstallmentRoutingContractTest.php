<?php
/**
 * Contract tests for backend paymentBehavior routing (v3.5.38).
 *
 * These tests execute the real YSGatewayBase::prepare_payment_data() method.
 * They lock the server-side authority boundary independently from frontend mode
 * production: installment new-card requests must remain Regular, while saved
 * cards retain QuickPayment. Main credit and subscription behavior are regression
 * controls.
 *
 * @package YangSheep\ShoplinePayment\Tests
 */

declare(strict_types=1);

use YangSheep\ShoplinePayment\Gateways\YSGatewayBase;

final class YS_Installment_Routing_Test_Order {
	public array $meta = array();

	public function get_id(): int {
		return 9401;
	}

	public function get_user_id(): int {
		return 77;
	}

	public function get_total(): float {
		return 100.0;
	}

	public function get_currency(): string {
		return 'TWD';
	}

	public function get_shipping_method(): string {
		return 'Test shipping';
	}

	public function get_shipping_total(): float {
		return 0.0;
	}

	public function update_meta_data( string $key, $value ): void {
		$this->meta[ $key ] = $value;
	}

	public function save(): void {}
}

final class YS_Installment_Routing_Test_Gateway extends YSGatewayBase {
	private bool $subscription;

	public function __construct( string $gateway_id, bool $subscription = false ) {
		$this->id           = $gateway_id;
		$this->subscription = $subscription;
	}

	public function get_payment_method() {
		return 'CreditCard';
	}

	public function expose_prepare_payment_data( $order, string $pay_session ): array {
		return $this->prepare_payment_data( $order, $pay_session );
	}

	protected function order_contains_subscription( $order ) {
		return $this->subscription;
	}

	protected function get_shopline_customer_id( $user_id ) {
		return 'customer-77';
	}

	protected function build_personal_info( $order, $type = 'billing' ) {
		return array( 'firstName' => 'Test' );
	}

	protected function build_address( $order, $type = 'billing' ) {
		return array( 'city' => 'Taipei' );
	}

	protected function build_products( $order ) {
		return array(
			array(
				'name'   => 'Contract product',
				'amount' => array( 'value' => 10000, 'currency' => 'TWD' ),
			),
		);
	}

	protected function get_client_ip() {
		return '127.0.0.1';
	}

	protected function build_client_info( $client_ip ) {
		return array( 'ip' => $client_ip );
	}

	protected function get_return_url( $order = null ) {
		return 'https://example.test/return';
	}

	protected function generate_reference_order_id( $order ) {
		return '9401_1';
	}

	protected function get_shopline_language() {
		return 'zh-TW';
	}
}

function ys_prepare_routing_case( string $gateway_id, string $mode, bool $subscription = false ): array {
	$_POST['ys_shopline_payment_instrument_mode'] = $mode;
	$_POST['ys_shopline_bind_card_enabled']       = '1';
	unset( $_POST['ys_shopline_payment_instrument_id'], $_POST['ys_shopline_saved_card_last4'] );

	$gateway = new YS_Installment_Routing_Test_Gateway( $gateway_id, $subscription );
	$data    = $gateway->expose_prepare_payment_data( new YS_Installment_Routing_Test_Order(), 'session-1' );

	unset( $_POST['ys_shopline_payment_instrument_mode'], $_POST['ys_shopline_bind_card_enabled'] );

	return $data;
}

function ys_run_installment_routing_contract(): void {
	echo "== Installment backend routing: paymentBehavior authority ==\n";

	foreach ( array( 'new', '' ) as $mode ) {
		$data = ys_prepare_routing_case( 'ys_shopline_credit_installment', $mode );
		$label = '' === $mode ? 'missing mode' : $mode;
		YS_Assert::eq( "installment {$label} -> Regular", 'Regular', $data['confirm']['paymentBehavior'] ?? null );
		YS_Assert::eq( "installment {$label} has no savePaymentInstrument", false, isset( $data['confirm']['paymentInstrument']['savePaymentInstrument'] ) );
	}

	$data = ys_prepare_routing_case( 'ys_shopline_credit_installment', 'new_save' );
	YS_Assert::eq( 'installment new_save -> CardBindPayment', 'CardBindPayment', $data['confirm']['paymentBehavior'] ?? null );
	YS_Assert::eq( 'installment new_save keeps savePaymentInstrument', true, $data['confirm']['paymentInstrument']['savePaymentInstrument'] ?? false );

	$data = ys_prepare_routing_case( 'ys_shopline_credit_installment', 'saved' );
	YS_Assert::eq( 'installment saved -> QuickPayment', 'QuickPayment', $data['confirm']['paymentBehavior'] ?? null );
	YS_Assert::eq( 'installment saved keeps paymentCustomerId', 'customer-77', $data['confirm']['paymentCustomerId'] ?? null );

	$data = ys_prepare_routing_case( 'ys_shopline_credit', 'new_save' );
	YS_Assert::eq( 'main credit new_save -> CardBindPayment', 'CardBindPayment', $data['confirm']['paymentBehavior'] ?? null );
	YS_Assert::eq( 'main credit new_save keeps savePaymentInstrument', true, $data['confirm']['paymentInstrument']['savePaymentInstrument'] ?? false );

	$data = ys_prepare_routing_case( 'ys_shopline_credit', 'new' );
	YS_Assert::eq( 'main credit new -> Regular', 'Regular', $data['confirm']['paymentBehavior'] ?? null );

	$data = ys_prepare_routing_case( 'ys_shopline_credit_subscription', 'new_save', true );
	YS_Assert::eq( 'subscription new_save -> CardBindPayment', 'CardBindPayment', $data['confirm']['paymentBehavior'] ?? null );
	YS_Assert::eq( 'subscription new_save keeps savePaymentInstrument', true, $data['confirm']['paymentInstrument']['savePaymentInstrument'] ?? false );
}
