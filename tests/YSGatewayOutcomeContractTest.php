<?php
/**
 * Contract tests for YSGatewayBase create-trade outcome consumption.
 *
 * @package YangSheep\ShoplinePayment\Tests
 */

declare(strict_types=1);

use YangSheep\ShoplinePayment\Gateways\YSGatewayBase;
use YangSheep\ShoplinePayment\Utils\YSOrderMeta;

final class YS_Gateway_Test_Order {
	public array $meta = array();
	public array $notes = array();
	public string $status = 'pending';
	public bool $paid = false;
	public string $transaction_id = '';

	public function get_id(): int {
		return 9101;
	}

	public function get_status(): string {
		return $this->status;
	}

	public function get_meta( string $key ) {
		return $this->meta[ $key ] ?? '';
	}

	public function update_meta_data( string $key, $value ): void {
		$this->meta[ $key ] = $value;
	}

	public function delete_meta_data( string $key ): void {
		unset( $this->meta[ $key ] );
	}

	public function add_order_note( string $note ): void {
		$this->notes[] = $note;
	}

	public function update_status( string $status, string $note = '' ): void {
		$this->status = $status;
		if ( '' !== $note ) {
			$this->notes[] = $note;
		}
	}

	public function is_paid(): bool {
		return $this->paid;
	}

	public function payment_complete( string $transaction_id = '' ): void {
		$this->paid = true;
		$this->status = 'processing';
		$this->transaction_id = $transaction_id;
	}

	public function get_checkout_payment_url(): string {
		return 'https://example.test/order-pay/9101';
	}

	public function get_total(): float {
		return 10.0;
	}

	public function get_currency(): string {
		return 'TWD';
	}

	public function save(): void {}
}

final class YS_Gateway_Test_Api {
	public $response;

	public function create_payment_trade( array $data, string $idempotent_key ) {
		return $this->response;
	}
}

final class YS_Gateway_Test_Gateway extends YSGatewayBase {
	public function __construct( $response ) {
		$this->id = 'ys_shopline_credit';
		$this->api = new YS_Gateway_Test_Api();
		$this->api->response = $response;
	}

	public function get_payment_method() {
		return 'CreditCard';
	}

	public function get_return_url( $order = null ) {
		return 'https://example.test/thank-you';
	}

	protected function prepare_payment_data( $order, $pay_session ) {
		$order->update_meta_data( YSOrderMeta::REFERENCE_ORDER_ID, '9101_1' );
		return array(
			'paySession'       => $pay_session,
			'referenceOrderId' => '9101_1',
			'amount'           => array( 'value' => 1000, 'currency' => 'TWD' ),
			'confirm'          => array( 'paymentMethod' => 'CreditCard' ),
		);
	}
}

/**
 * Execute one gateway result through the real process_payment() method.
 *
 * @param mixed $response API response.
 * @return array{0:array,1:YS_Gateway_Test_Order}
 */
function ys_gateway_process_response( $response ): array {
	$order = new YS_Gateway_Test_Order();
	$GLOBALS['ys_test_order'] = $order;
	$GLOBALS['ys_test_notices'] = array();
	$_POST['ys_shopline_pay_session'] = '{"sessionId":"s-1"}';

	$gateway = new YS_Gateway_Test_Gateway( $response );
	$result = $gateway->process_payment( $order->get_id() );

	return array( $result, $order );
}

/**
 * Run base gateway outcome contract cases.
 *
 * @return void
 */
function ys_run_gateway_outcome_contract(): void {
	echo "== YSGatewayBase: tri-state response consumption ==\n";

	list( $result, $order ) = ys_gateway_process_response( array( 'tradeOrderId' => 'failed-1', 'status' => 'FAILED' ) );
	YS_Assert::eq( 'terminal array response -> rejected', 'rejected', $result['remote_outcome'] );
	YS_Assert::eq( 'terminal array response does not complete payment', false, $order->paid );

	list( $result, $order ) = ys_gateway_process_response( array( 'tradeOrderId' => 'auth-1', 'status' => 'AUTHORIZED' ) );
	YS_Assert::eq( 'AUTHORIZED array response -> accepted', 'accepted', $result['remote_outcome'] );
	YS_Assert::eq( 'AUTHORIZED moves order on-hold', 'on-hold', $order->status );

	list( $result, $order ) = ys_gateway_process_response( array( 'tradeOrderId' => 'unknown-1', 'status' => 'WHAT' ) );
	YS_Assert::eq( 'unknown status with trade ID -> unknown', 'unknown', $result['remote_outcome'] );
	YS_Assert::eq( 'unknown status does not complete payment', false, $order->paid );

	list( $result, $order ) = ys_gateway_process_response( array( 'tradeOrderId' => 'paid-1', 'status' => 'SUCCEEDED' ) );
	YS_Assert::eq( 'paid array response -> accepted', 'accepted', $result['remote_outcome'] );
	YS_Assert::eq( 'paid array response completes payment', 'paid-1', $order->transaction_id );

	list( $result, $order ) = ys_gateway_process_response( array( 'status' => 'SUCCEEDED' ) );
	YS_Assert::eq( 'missing trade ID -> unknown', 'unknown', $result['remote_outcome'] );
	YS_Assert::eq( 'missing trade ID writes indeterminate marker', '9101_1', $order->get_meta( YSOrderMeta::INDETERMINATE_REF ) );
}
