<?php
/**
 * Contract tests for YSGatewayBase create-trade outcome consumption.
 *
 * @package YangSheep\ShoplinePayment\Tests
 */

declare(strict_types=1);

use YangSheep\ShoplinePayment\Gateways\YSGatewayBase;
use YangSheep\ShoplinePayment\Utils\YSOrderMeta;

final class YS_Gateway_Test_Order extends WC_Order {
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

	public function get_payment_method(): string {
		return 'ys_shopline_credit';
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
	public int $create_calls = 0;

	public function create_payment_trade( array $data, string $idempotent_key ) {
		$this->create_calls++;
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

	public function get_create_calls(): int {
		return $this->api->create_calls;
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
 * @return array{0:array,1:YS_Gateway_Test_Order,2:YS_Gateway_Test_Gateway}
 */
function ys_gateway_process_response( $response ): array {
	$order = new YS_Gateway_Test_Order();
	$GLOBALS['ys_test_order'] = $order;
	$GLOBALS['ys_test_notices'] = array();
	$_POST['ys_shopline_pay_session'] = '{"sessionId":"s-1"}';

	$gateway = new YS_Gateway_Test_Gateway( $response );
	$result = $gateway->process_payment( $order->get_id() );

	return array( $result, $order, $gateway );
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

	list( $result, $order ) = ys_gateway_process_response( array( 'tradeOrderId' => 'created-1', 'status' => 'CREATED' ) );
	YS_Assert::eq( 'CREATED array response -> accepted', 'accepted', $result['remote_outcome'] );
	YS_Assert::eq( 'CREATED remains customer-pending', 'pending', $order->status );
	YS_Assert::eq( 'CREATED never enters confirmation', '', $order->get_meta( YSOrderMeta::CONFIRMATION_DATA ) );

	list( $result, $order ) = ys_gateway_process_response( array( 'tradeOrderId' => 'auth-1', 'status' => 'AUTHORIZED' ) );
	YS_Assert::eq( 'AUTHORIZED array response -> accepted', 'accepted', $result['remote_outcome'] );
	YS_Assert::eq( 'AUTHORIZED moves order to payment confirmation', 'ys-confirming', $order->status );
	YS_Assert::eq( 'create path stores exact request amount envelope', 1000, $order->get_meta( '_ys_shopline_payment_attempt_data' )['amount'] ?? -1 );
	YS_Assert::eq( 'create path stores session identity without raw paySession', 's-1', $order->get_meta( '_ys_shopline_payment_attempt_data' )['session_id'] ?? '' );

	list( $result, $order ) = ys_gateway_process_response( array( 'tradeOrderId' => 'unknown-1', 'status' => 'WHAT' ) );
	YS_Assert::eq( 'unknown status with trade ID -> unknown', 'unknown', $result['remote_outcome'] );
	YS_Assert::eq( 'unknown status does not complete payment', false, $order->paid );
	YS_Assert::eq( 'unknown status enters payment confirmation', 'ys-confirming', $order->status );

	list( $result, $order ) = ys_gateway_process_response( array( 'tradeOrderId' => 'paid-1', 'status' => 'SUCCEEDED' ) );
	YS_Assert::eq( 'paid array response -> accepted', 'accepted', $result['remote_outcome'] );
	YS_Assert::eq( 'paid array response completes payment', 'paid-1', $order->transaction_id );

	list( $result, $order ) = ys_gateway_process_response( array( 'status' => 'SUCCEEDED' ) );
	YS_Assert::eq( 'missing trade ID -> unknown', 'unknown', $result['remote_outcome'] );
	YS_Assert::eq( 'missing trade ID writes indeterminate marker', '9101_1', $order->get_meta( YSOrderMeta::INDETERMINATE_REF ) );
	YS_Assert::eq( 'missing trade ID enters payment confirmation', 'ys-confirming', $order->status );

	$empty_calls_before = WC()->cart->empty_calls;
	list( $result, $order, $gateway ) = ys_gateway_process_response(
		new WP_Error( 'http_request_failed', 'Connection timed out after the request was sent.' )
	);
	YS_Assert::eq( 'transport unknown creates exactly one remote request', 1, $gateway->get_create_calls() );
	YS_Assert::eq( 'transport unknown returns success for thank-you redirect', 'success', $result['result'] );
	YS_Assert::eq( 'transport unknown keeps tri-state outcome', 'unknown', $result['remote_outcome'] );
	YS_Assert::eq( 'transport unknown redirects to neutral confirmation page', 'https://example.test/thank-you', $result['redirect'] ?? '' );
	YS_Assert::eq( 'transport unknown enters payment confirmation', 'ys-confirming', $order->status );
	YS_Assert::eq( 'transport unknown preserves exact indeterminate reference', '9101_1', $order->get_meta( YSOrderMeta::INDETERMINATE_REF ) );
	YS_Assert::eq( 'transport unknown empties cart after durable confirmation state', $empty_calls_before + 1, WC()->cart->empty_calls );

	$retry_gateway = new YS_Gateway_Test_Gateway( array( 'tradeOrderId' => 'must-not-create', 'status' => 'SUCCEEDED' ) );
	$retry_result  = $retry_gateway->process_payment( $order->get_id() );
	YS_Assert::eq( 'immediate retry while confirming never calls create API', 0, $retry_gateway->get_create_calls() );
	YS_Assert::eq( 'immediate retry remains fail-closed unknown', 'unknown', $retry_result['remote_outcome'] );
	YS_Assert::eq( 'immediate retry redirects to existing confirmation page', 'https://example.test/thank-you', $retry_result['redirect'] ?? '' );
	YS_Assert::eq( 'immediate retry does not replace indeterminate reference', '9101_1', $order->get_meta( YSOrderMeta::INDETERMINATE_REF ) );
}
