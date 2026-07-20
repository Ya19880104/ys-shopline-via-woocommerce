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
	public string $payment_method = 'ys_shopline_credit';
	public $date_paid = null;

	public function get_id(): int {
		return 9101;
	}

	public function get_status(): string {
		return $this->status;
	}

	public function get_payment_method(): string {
		return $this->payment_method;
	}

	public function get_user_id(): int {
		return 77;
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

	public function get_date_paid() {
		return $this->date_paid;
	}

	public function payment_complete( string $transaction_id = '' ): void {
		$this->paid = true;
		$this->date_paid = '2026-07-20 10:53:00';
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
	public array $prior_responses = array();
	public $cancel_response = true;
	public int $query_calls = 0;
	public int $cancel_calls = 0;

	public function create_payment_trade( array $data, string $idempotent_key ) {
		$this->create_calls++;
		return $this->response;
	}

	public function get_payment_trade( string $trade_order_id ) {
		$this->query_calls++;
		return array_shift( $this->prior_responses );
	}

	public function cancel_payment_by_ids( string $trade_order_id, string $reference_order_id ) {
		$this->cancel_calls++;
		return $this->cancel_response;
	}
}

final class YS_Gateway_Test_Gateway extends YSGatewayBase {
	private string $test_payment_method;

	public function __construct( $response, string $gateway_id = 'ys_shopline_credit', string $payment_method = 'CreditCard' ) {
		$this->id = $gateway_id;
		$this->test_payment_method = $payment_method;
		$this->api = new YS_Gateway_Test_Api();
		$this->api->response = $response;
	}

	public function get_payment_method() {
		return $this->test_payment_method;
	}

	public function get_return_url( $order = null ) {
		return 'https://example.test/thank-you';
	}

	public function get_create_calls(): int {
		return $this->api->create_calls;
	}

	public function configure_prior_trade( array $responses, $cancel_response = true ): void {
		$this->api->prior_responses = $responses;
		$this->api->cancel_response = $cancel_response;
	}

	public function get_query_calls(): int {
		return $this->api->query_calls;
	}

	public function get_cancel_calls(): int {
		return $this->api->cancel_calls;
	}

	protected function prepare_payment_data( $order, $pay_session ) {
		$order->update_meta_data( YSOrderMeta::REFERENCE_ORDER_ID, '9101_1' );
		$customer_id = (string) get_user_meta( $order->get_user_id(), YSOrderMeta::CUSTOMER_ID, true );
		return array(
			'paySession'       => $pay_session,
			'referenceOrderId' => '9101_1',
			'amount'           => array( 'value' => 1000, 'currency' => 'TWD' ),
			'confirm'          => array(
				'paymentMethod'     => 'CreditCard',
				'paymentCustomerId' => $customer_id,
			),
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
	$GLOBALS['ys_test_logs'] = array();
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

	list( $result, $order ) = ys_gateway_process_response(
		array(
			'status'      => 'SUCCEEDED',
			'paymentMsg'  => array( 'code' => '4458', 'msg' => 'still processing' ),
			'nextAction'  => array( 'type' => 'Redirect' ),
		)
	);
	YS_Assert::eq( 'missing trade ID -> unknown', 'unknown', $result['remote_outcome'] );
	YS_Assert::eq( 'missing trade ID writes indeterminate marker', '9101_1', $order->get_meta( YSOrderMeta::INDETERMINATE_REF ) );
	YS_Assert::eq( 'missing trade ID enters payment confirmation', 'ys-confirming', $order->status );
	$missing_id_logs = wp_json_encode( $GLOBALS['ys_test_logs'] );
	YS_Assert::is_true( 'missing trade ID log records response keys', str_contains( $missing_id_logs, 'response_keys' ) && str_contains( $missing_id_logs, 'paymentMsg' ) );
	YS_Assert::is_true( 'missing trade ID log records envelope flags', str_contains( $missing_id_logs, 'has_next_action' ) && str_contains( $missing_id_logs, 'has_trade_order_id' ) );
	YS_Assert::is_true( 'missing trade ID log records payment error details', str_contains( $missing_id_logs, 'payment_error_code' ) && str_contains( $missing_id_logs, '4458' ) );

	$empty_calls_before = WC()->cart->empty_calls;
	list( $result, $order, $gateway ) = ys_gateway_process_response(
		new WP_Error(
			'http_request_failed',
			'Connection timed out after the request was sent.',
			array(
				'http_status'    => 0,
				'request_id'     => 'req-timeout-2',
				'response_keys'  => array(),
				'transport_error' => 'Connection timed out after the request was sent.',
			)
		)
	);
	YS_Assert::eq( 'transport unknown creates exactly one remote request', 1, $gateway->get_create_calls() );
	YS_Assert::eq( 'transport unknown returns success for thank-you redirect', 'success', $result['result'] );
	YS_Assert::eq( 'transport unknown keeps tri-state outcome', 'unknown', $result['remote_outcome'] );
	YS_Assert::eq( 'transport unknown redirects to neutral confirmation page', 'https://example.test/thank-you', $result['redirect'] ?? '' );
	YS_Assert::eq( 'transport unknown enters payment confirmation', 'ys-confirming', $order->status );
	YS_Assert::eq( 'transport unknown preserves exact indeterminate reference', '9101_1', $order->get_meta( YSOrderMeta::INDETERMINATE_REF ) );
	YS_Assert::eq( 'transport unknown empties cart after durable confirmation state', $empty_calls_before + 1, WC()->cart->empty_calls );
	$transport_logs = wp_json_encode( $GLOBALS['ys_test_logs'] );
	YS_Assert::is_true( 'transport unknown log records HTTP and request identity', str_contains( $transport_logs, 'http_status' ) && str_contains( $transport_logs, 'req-timeout-2' ) );
	YS_Assert::is_true( 'transport unknown log records exact transport error', str_contains( $transport_logs, 'transport_error' ) && str_contains( $transport_logs, 'Connection timed out after the request was sent.' ) );

	$retry_gateway = new YS_Gateway_Test_Gateway( array( 'tradeOrderId' => 'must-not-create', 'status' => 'SUCCEEDED' ) );
	$retry_result  = $retry_gateway->process_payment( $order->get_id() );
	YS_Assert::eq( 'immediate retry while confirming never calls create API', 0, $retry_gateway->get_create_calls() );
	YS_Assert::eq( 'immediate retry remains fail-closed unknown', 'unknown', $retry_result['remote_outcome'] );
	YS_Assert::eq( 'immediate retry redirects to existing confirmation page', 'https://example.test/thank-you', $retry_result['redirect'] ?? '' );
	YS_Assert::eq( 'immediate retry does not replace indeterminate reference', '9101_1', $order->get_meta( YSOrderMeta::INDETERMINATE_REF ) );

	echo "== YSGatewayBase: customer-pending re-query remains fail-closed ==\n";
	$prior_order = new YS_Gateway_Test_Order();
	$prior_order->update_meta_data( YSOrderMeta::TRADE_ORDER_ID, 'wallet-trade-1' );
	$prior_order->update_meta_data( YSOrderMeta::REFERENCE_ORDER_ID, '9101_1' );
	$GLOBALS['ys_test_order'] = $prior_order;
	$GLOBALS['ys_test_notices'] = array();
	$prior_gateway = new YS_Gateway_Test_Gateway( array( 'tradeOrderId' => 'must-not-create', 'status' => 'SUCCEEDED' ) );
	$prior_gateway->configure_prior_trade(
		array(
			array( 'tradeOrderId' => 'wallet-trade-1', 'status' => 'CUSTOMER_ACTION', 'paymentMethod' => 'LinePay' ),
			new WP_Error( 'http_request_failed', 'query timeout' ),
		),
		new WP_Error( 'cannot_cancel', 'can not cancel' )
	);
	$resolution = $prior_gateway->resolve_prior_trade( $prior_order );
	YS_Assert::eq( 'customer-pending re-query error stays blocked', 'blocked', $resolution['action'] ?? '' );
	YS_Assert::eq( 'customer-pending re-query error uses one-minute neutral notice', '暫時無法確認前次付款狀態，請稍候約 1 分鐘後再試。若持續發生請聯繫客服。', $resolution['message'] ?? '' );
	YS_Assert::eq( 'customer-pending re-query error preserves prior trade', 'wallet-trade-1', $prior_order->get_meta( YSOrderMeta::TRADE_ORDER_ID ) );
	YS_Assert::eq( 'customer-pending re-query error performs query-cancel-query', 2, $prior_gateway->get_query_calls() );
	YS_Assert::eq( 'customer-pending re-query error attempts one cancellation', 1, $prior_gateway->get_cancel_calls() );
	YS_Assert::eq( 'customer-pending re-query error never creates a new trade', 0, $prior_gateway->get_create_calls() );

	echo "== YSGatewayBase: stale customer rejection recovery ==\n";
	$GLOBALS['ys_test_user_meta'][77] = array(
		YSOrderMeta::CUSTOMER_ID      => 'stale-customer-77',
		YSOrderMeta::INSTRUMENTS_CACHE => array( 'cached' => true ),
	);
	list( $result, $stale_order, $stale_gateway ) = ys_gateway_process_response(
		new WP_Error(
			'1005',
			'Enable Customer not found,customerId=stale-customer-77',
			array( 'http_status' => 400, 'payment_error_message' => 'Enable Customer not found,customerId=stale-customer-77' )
		)
	);
	YS_Assert::eq( 'stale customer rejection stays retryable rejected', 'rejected', $result['remote_outcome'] ?? '' );
	YS_Assert::eq( 'stale customer rejection creates only one trade request', 1, $stale_gateway->get_create_calls() );
	YS_Assert::eq( 'stale customer rejection clears exact customer mapping', '', get_user_meta( 77, YSOrderMeta::CUSTOMER_ID, true ) );
	YS_Assert::eq( 'stale customer rejection clears instruments cache', '', get_user_meta( 77, YSOrderMeta::INSTRUMENTS_CACHE, true ) );

	$GLOBALS['ys_test_user_meta'][77] = array(
		YSOrderMeta::CUSTOMER_ID      => 'keep-customer-77',
		YSOrderMeta::INSTRUMENTS_CACHE => array( 'cached' => true ),
	);
	ys_gateway_process_response( new WP_Error( '1005', 'Other validation check failed' ) );
	YS_Assert::eq( 'unrelated 1005 keeps customer mapping', 'keep-customer-77', get_user_meta( 77, YSOrderMeta::CUSTOMER_ID, true ) );
	YS_Assert::eq( 'unrelated 1005 keeps instruments cache', array( 'cached' => true ), get_user_meta( 77, YSOrderMeta::INSTRUMENTS_CACHE, true ) );

	echo "== YSGatewayBase: active confirmation blocks every replacement gateway ==\n";
	$replacement_gateways = array(
		'ys_shopline_credit'              => 'CreditCard',
		'ys_shopline_credit_installment'  => 'CreditCard',
		'ys_shopline_credit_subscription' => 'CreditCard',
		'ys_shopline_atm'                 => 'VirtualAccount',
		'ys_shopline_jkopay'              => 'JKOPay',
		'ys_shopline_applepay'            => 'ApplePay',
		'ys_shopline_linepay'             => 'LinePay',
		'ys_shopline_bnpl'                => 'ChaileaseBNPL',
	);
	foreach ( $replacement_gateways as $gateway_id => $shopline_method ) {
		$locked = new YS_Gateway_Test_Order();
		$locked->status = 'ys-confirming';
		$locked->payment_method = $gateway_id;
		$locked->meta[ YSOrderMeta::CONFIRMATION_DATA ] = array(
			'reference'       => '9101_wallet_1',
			'trade_order_id'  => 'wallet-possibly-paid',
			'session_id'      => 'wallet-session',
			'gateway'         => 'ys_shopline_linepay',
			'shopline_method' => 'LinePay',
			'amount'          => 1000,
			'currency'        => 'TWD',
			'reason'          => 'indeterminate',
			'remote_status'   => 'CUSTOMER_ACTION',
			'started_at'      => time(),
			'stage'           => 0,
		);
		$GLOBALS['ys_test_order'] = $locked;
		$GLOBALS['ys_test_notices'] = array();
		$replacement = new YS_Gateway_Test_Gateway(
			array( 'tradeOrderId' => 'must-not-create', 'status' => 'SUCCEEDED' ),
			$gateway_id,
			$shopline_method
		);
		$blocked = $replacement->process_payment( $locked->get_id() );
		YS_Assert::eq( "{$gateway_id}: active wallet attempt blocks replacement create", 0, $replacement->get_create_calls() );
		YS_Assert::eq( "{$gateway_id}: replacement remains fail-closed", 'unknown', $blocked['remote_outcome'] ?? '' );
		YS_Assert::eq( "{$gateway_id}: active wallet attempt remains confirming", 'ys-confirming', $locked->status );
	}

	$custom_paid = new YS_Gateway_Test_Order();
	$custom_paid->status = 'shipped';
	$custom_paid->paid = false;
	$custom_paid->date_paid = '2026-07-20 10:53:00';
	$GLOBALS['ys_test_order'] = $custom_paid;
	$paid_retry = new YS_Gateway_Test_Gateway( array( 'tradeOrderId' => 'must-not-create', 'status' => 'SUCCEEDED' ) );
	$paid_retry_result = $paid_retry->process_payment( $custom_paid->get_id() );
	YS_Assert::eq( 'date-paid custom status blocks a new create before API', 0, $paid_retry->get_create_calls() );
	YS_Assert::eq( 'date-paid custom status returns accepted existing payment', 'accepted', $paid_retry_result['remote_outcome'] ?? '' );
	YS_Assert::eq( 'date-paid custom status is retained by pre-create guard', 'shipped', $custom_paid->status );
}
