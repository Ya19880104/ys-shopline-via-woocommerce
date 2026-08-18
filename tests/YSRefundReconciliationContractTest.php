<?php
/**
 * Contract tests for the asynchronous SHOPLINE refund lifecycle.
 *
 * @package YangSheep\ShoplinePayment\Tests
 */

declare(strict_types=1);

use YangSheep\ShoplinePayment\Handlers\YSRefundReconciliation;
use YangSheep\ShoplinePayment\Utils\YSOrderMeta;
use YangSheep\ShoplinePayment\Admin\YSOrderPaymentAdmin;

final class YS_Refund_Test_Order extends WC_Order {
	public array $meta = array();
	public array $notes = array();
	public int $save_count = 0;
	public string $payment_method = 'ys_shopline_bnpl';
	public float $total = 100.0;
	public float $remaining_refund = 100.0;
	public array $refunds = array();

	public function get_id(): int {
		return 9201;
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

	public function save(): void {
		$this->save_count++;
	}

	public function add_order_note( string $note ): void {
		$this->notes[] = $note;
	}

	public function get_payment_method(): string {
		return $this->payment_method;
	}

	public function get_currency(): string {
		return 'TWD';
	}

	public function get_total(): float {
		return $this->total;
	}

	public function get_remaining_refund_amount(): float {
		return $this->remaining_refund;
	}

	public function get_refunds(): array {
		return $this->refunds;
	}
}

final class YS_Refund_Test_Api {
	public int $create_calls = 0;
	public int $query_calls = 0;
	public array $create_keys = array();
	public array $create_responses = array();
	public array $query_responses = array();
	public bool $attempt_existed_before_create = false;

	public function __construct( private YS_Refund_Test_Order $order ) {}

	public function create_refund( array $data, string $idempotent_key = '' ) {
		$this->create_calls++;
		$this->create_keys[] = $idempotent_key;
		$this->attempt_existed_before_create = is_array( $this->order->get_meta( YSOrderMeta::REFUND_CONFIRMATION_DATA ) );

		$response = array_shift( $this->create_responses );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return array_merge(
			array(
			'refundOrderId'   => 'refund-remote-1',
			'referenceOrderId'=> $data['referenceOrderId'],
			'tradeOrderId'    => $data['tradeOrderId'],
			'amount'          => $data['amount'],
			'status'          => 'CREATED',
			),
			is_array( $response ) ? $response : array()
		);
	}

	public function query_refund( string $refund_order_id ) {
		$this->query_calls++;
		return array_shift( $this->query_responses );
	}
}

final class YS_Refund_Test_Gateway extends \YangSheep\ShoplinePayment\Gateways\YSGatewayBase {
	public function __construct( $api, string $gateway_id = 'ys_shopline_bnpl', private string $shopline_method = 'ChaileaseBNPL' ) {
		$this->id  = $gateway_id;
		$this->api = $api;
	}

	public function get_payment_method() {
		return $this->shopline_method;
	}
}

function ys_refund_snapshot_args(): array {
	return array(
		'order_id'       => 9201,
		'amount'         => 100.0,
		'reason'         => 'Customer requested refund',
		'line_items'     => array(
			12 => array(
				'qty'          => 2,
				'refund_total' => '80.00',
				'refund_tax'   => array( 1 => '4.00' ),
			),
		),
		'refund_payment' => true,
		'restock_items'  => true,
	);
}

function ys_refund_capture( float $amount = 100.0, array $override = array() ): YS_Test_Refund_Object {
	$args = array_replace_recursive( ys_refund_snapshot_args(), $override );
	$args['amount'] = $amount;
	$refund = new YS_Test_Refund_Object();
	YSRefundReconciliation::capture_refund_request( $refund, $args );
	return $refund;
}

function ys_refund_test_order( string $gateway = 'ys_shopline_bnpl', float $remaining = 100.0 ): YS_Refund_Test_Order {
	$order = new YS_Refund_Test_Order();
	$order->payment_method = $gateway;
	$order->remaining_refund = $remaining;
	$order->meta[ YSOrderMeta::TRADE_ORDER_ID ] = 'trade-refund-1';
	// 退款前提：訂單必須已結算。未結算時 SHOPLINE 只回通用 1008，故服務層會前置擋下。
	$order->meta[ YSOrderMeta::PAYMENT_STATUS ] = 'SUCCEEDED';
	$GLOBALS['ys_test_order'] = $order;
	$GLOBALS['ys_test_scheduled_actions'] = array();
	$GLOBALS['ys_test_schedule_result'] = null;
	$GLOBALS['wpdb'] = new wpdb();
	return $order;
}

function ys_refund_response( string $status ): array {
	return array(
		'refundOrderId'    => 'refund-remote-1',
		'referenceOrderId' => '9201_refund_1',
		'tradeOrderId'     => 'trade-refund-1',
		'amount'           => array( 'value' => 10000, 'currency' => 'TWD' ),
		'status'           => $status,
	);
}

function ys_refund_error_code( $result ): string {
	return is_wp_error( $result ) ? $result->get_error_code() : 'not_wp_error';
}

function ys_run_refund_reconciliation_contract(): void {
	echo "== Refund reconciliation: durable in-flight attempt ==\n";

	YS_Assert::eq( 'refund reconciliation service is available', true, class_exists( YSRefundReconciliation::class ) );
	if ( ! class_exists( YSRefundReconciliation::class ) ) {
		return;
	}

	$order = ys_refund_test_order();

	ys_refund_capture();
	$api = new YS_Refund_Test_Api( $order );
	$api->query_responses = array_fill(
		0,
		3,
		array(
			'refundOrderId'    => 'refund-remote-1',
			'referenceOrderId' => '9201_refund_1',
			'tradeOrderId'     => 'trade-refund-1',
			'amount'           => array( 'value' => 10000, 'currency' => 'TWD' ),
			'status'           => 'PROCESSING',
		)
	);

	$result = YSRefundReconciliation::process(
		$order,
		100.0,
		'Customer requested refund',
		$api,
		'ys_shopline_bnpl',
		'ChaileaseBNPL'
	);
	$attempt = $order->get_meta( YSOrderMeta::REFUND_CONFIRMATION_DATA );

	YS_Assert::eq( 'in-flight refund returns WP_Error so Woo deletes temporary refund', true, is_wp_error( $result ) );
	YS_Assert::eq( 'in-flight refund uses merchant-facing pending error code', 'ys_shopline_refund_pending', $result->get_error_code() );
	YS_Assert::eq( 'attempt exists before remote create call', true, $api->attempt_existed_before_create );
	YS_Assert::eq( 'one remote refund is created', 1, $api->create_calls );
	// v3.6.7 定案：同步階段只做「單次立即查詢」，不得 sleep／阻塞 admin request。
	// 收斂的正確性只由排程／webhook 保證，故此處恆為 1 次。
	YS_Assert::eq( 'synchronous stage performs exactly one immediate query', 1, $api->query_calls );
	YS_Assert::eq( 'attempt keeps persistent reference', '9201_refund_1', $attempt['refund_reference'] ?? '' );
	YS_Assert::eq( 'attempt keeps non-empty idempotency key', true, ! empty( $attempt['idempotent_key'] ) );
	YS_Assert::eq( 'create receives the persisted idempotency key', $attempt['idempotent_key'] ?? '', $api->create_keys[0] ?? '' );
	YS_Assert::eq( 'attempt keeps remote refund order ID', 'refund-remote-1', $attempt['refund_order_id'] ?? '' );
	YS_Assert::eq( 'attempt keeps exact minor-unit amount', 10000, $attempt['amount'] ?? 0 );
	YS_Assert::eq( 'snapshot keeps amount', 100.0, $attempt['snapshot']['amount'] ?? null );
	YS_Assert::eq( 'snapshot keeps reason', 'Customer requested refund', $attempt['snapshot']['reason'] ?? '' );
	YS_Assert::eq( 'snapshot keeps line quantity', 2, $attempt['snapshot']['line_items'][12]['qty'] ?? null );
	YS_Assert::eq( 'snapshot keeps line refund total', '80.00', $attempt['snapshot']['line_items'][12]['refund_total'] ?? null );
	YS_Assert::eq( 'snapshot keeps line refund tax', array( 1 => '4.00' ), $attempt['snapshot']['line_items'][12]['refund_tax'] ?? null );
	YS_Assert::eq( 'snapshot keeps restock decision', true, $attempt['snapshot']['restock_items'] ?? false );
	YS_Assert::eq( 'one exact reconciliation action is scheduled', 1, count( $GLOBALS['ys_test_scheduled_actions'] ) );
	YS_Assert::eq( 'scheduled action is scoped to refund reference', '9201_refund_1', $GLOBALS['ys_test_scheduled_actions'][0]['args'][1] ?? '' );

	echo "== Refund reconciliation: gateway and outcome policy ==\n";

	$partial = ys_refund_test_order( 'ys_shopline_bnpl', 100.0 );
	ys_refund_capture( 50.0 );
	$partial_api = new YS_Refund_Test_Api( $partial );
	$partial_api->query_responses = array_fill( 0, 3, ys_refund_response( 'PROCESSING' ) );
	$partial_result = YSRefundReconciliation::process( $partial, 50.0, 'Partial', $partial_api, 'ys_shopline_bnpl', 'ChaileaseBNPL' );
	YS_Assert::eq( 'Chailease partial refund is rejected before API', 'ys_shopline_chailease_partial_refund', ys_refund_error_code( $partial_result ) );
	YS_Assert::eq( 'Chailease partial refund makes zero create calls', 0, $partial_api->create_calls );
	YS_Assert::eq( 'Chailease partial rejection leaves no active attempt', '', $partial->get_meta( YSOrderMeta::REFUND_CONFIRMATION_DATA ) );

	// 尚未結算：不得送出遠端退款（實測未收款交易只會拿到通用 1008 Status error）
	$unsettled = ys_refund_test_order( 'ys_shopline_credit' );
	unset( $unsettled->meta[ YSOrderMeta::PAYMENT_STATUS ] );
	ys_refund_capture();
	$unsettled_api = new YS_Refund_Test_Api( $unsettled );
	$unsettled_result = YSRefundReconciliation::process( $unsettled, 100.0, 'Too early', $unsettled_api, 'ys_shopline_credit', 'CreditCard' );
	YS_Assert::eq( 'unsettled order is rejected before the API call', 'ys_shopline_refund_not_settled', ys_refund_error_code( $unsettled_result ) );
	YS_Assert::eq( 'unsettled order makes zero create calls', 0, $unsettled_api->create_calls );
	YS_Assert::eq( 'unsettled order makes zero query calls', 0, $unsettled_api->query_calls );
	YS_Assert::eq( 'unsettled rejection leaves no active attempt', '', $unsettled->get_meta( YSOrderMeta::REFUND_CONFIRMATION_DATA ) );

	$high_partial = ys_refund_test_order( 'ys_shopline_bnpl', 100.0 );
	ys_refund_capture( 60.0 );
	$high_partial_api = new YS_Refund_Test_Api( $high_partial );
	$high_partial_result = YSRefundReconciliation::process( $high_partial, 60.0, 'High partial', $high_partial_api, 'ys_shopline_bnpl', 'ChaileaseBNPL' );
	YS_Assert::eq( 'Chailease high partial remains blocked after Woo temporary refund affects remaining amount', 'ys_shopline_chailease_partial_refund', ys_refund_error_code( $high_partial_result ) );
	YS_Assert::eq( 'Chailease high partial makes zero create calls', 0, $high_partial_api->create_calls );

	$snapshot_mismatch = ys_refund_test_order( 'ys_shopline_credit' );
	ys_refund_capture( 100.0 );
	$snapshot_mismatch_api = new YS_Refund_Test_Api( $snapshot_mismatch );
	$snapshot_mismatch_result = YSRefundReconciliation::process( $snapshot_mismatch, 90.0, 'Mismatch', $snapshot_mismatch_api, 'ys_shopline_credit', 'CreditCard' );
	YS_Assert::eq( 'snapshot amount mismatch fails before remote refund', 'ys_shopline_refund_snapshot_mismatch', ys_refund_error_code( $snapshot_mismatch_result ) );
	YS_Assert::eq( 'snapshot amount mismatch makes zero create calls', 0, $snapshot_mismatch_api->create_calls );

	$credit = ys_refund_test_order( 'ys_shopline_credit', 100.0 );
	$credit->meta[ YSOrderMeta::REFUND_REVIEW ] = array( 'type' => 'reconcile_schedule_failed' );
	$credit_temporary_refund = ys_refund_capture( 50.0, array( 'line_items' => array(), 'restock_items' => false ) );
	$credit_api = new YS_Refund_Test_Api( $credit );
	$credit_api->create_responses[] = array(
		'status' => 'SUCCEEDED',
		'amount' => array( 'value' => 5000, 'currency' => 'TWD' ),
	);
	$credit_result = YSRefundReconciliation::process( $credit, 50.0, 'Credit partial', $credit_api, 'ys_shopline_credit', 'CreditCard' );
	YS_Assert::eq( 'credit-card partial refund remains supported', true, $credit_result );
	YS_Assert::eq( 'credit-card partial refund creates one remote refund', 1, $credit_api->create_calls );
	YS_Assert::eq( 'confirmed synchronous success clears active attempt', '', $credit->get_meta( YSOrderMeta::REFUND_CONFIRMATION_DATA ) );
	YS_Assert::eq( 'confirmed synchronous success clears stale refund review', '', $credit->get_meta( YSOrderMeta::REFUND_REVIEW ) );
	YS_Assert::eq( 'synchronous Woo refund is tagged with the persistent SHOPLINE reference', '9201_refund_1', $credit_temporary_refund->get_meta( YSOrderMeta::REFUND_REFERENCE ) );

	// 立即查詢即確認成功 → Woo 保留暫存退款（回 true），且只查一次。
	$polled = ys_refund_test_order();
	ys_refund_capture();
	$polled_api = new YS_Refund_Test_Api( $polled );
	$polled_api->query_responses = array( ys_refund_response( 'SUCCEEDED' ) );
	$polled_result = YSRefundReconciliation::process( $polled, 100.0, 'Immediate success', $polled_api, 'ys_shopline_bnpl', 'ChaileaseBNPL' );
	YS_Assert::eq( 'immediate query success lets Woo retain its temporary refund', true, $polled_result );
	YS_Assert::eq( 'immediate query success needs only one query', 1, $polled_api->query_calls );
	YS_Assert::eq( 'immediate query success clears active attempt', '', $polled->get_meta( YSOrderMeta::REFUND_CONFIRMATION_DATA ) );

	// 立即查詢仍在途 → 絕不繼續輪詢（不得 sleep 佔住 admin request），一律交排程收斂。
	$still_pending = ys_refund_test_order();
	ys_refund_capture();
	$still_pending_api = new YS_Refund_Test_Api( $still_pending );
	$still_pending_api->query_responses = array( ys_refund_response( 'PROCESSING' ), ys_refund_response( 'SUCCEEDED' ) );
	$still_pending_result = YSRefundReconciliation::process( $still_pending, 100.0, 'Still pending', $still_pending_api, 'ys_shopline_bnpl', 'ChaileaseBNPL' );
	YS_Assert::eq( 'in-flight immediate query never blocks for a second poll', 1, $still_pending_api->query_calls );
	YS_Assert::eq( 'in-flight immediate query returns the pending error', 'ys_shopline_refund_pending', ys_refund_error_code( $still_pending_result ) );
	YS_Assert::eq( 'in-flight immediate query keeps the active attempt for scheduled convergence', true, ! empty( $still_pending->get_meta( YSOrderMeta::REFUND_CONFIRMATION_DATA ) ) );

	$failed = ys_refund_test_order();
	ys_refund_capture();
	$failed_api = new YS_Refund_Test_Api( $failed );
	$failed_api->create_responses[] = array( 'status' => 'FAILED' );
	$failed_result = YSRefundReconciliation::process( $failed, 100.0, 'Fail', $failed_api, 'ys_shopline_bnpl', 'ChaileaseBNPL' );
	YS_Assert::eq( 'confirmed remote failure returns WP_Error', 'ys_shopline_refund_failed', ys_refund_error_code( $failed_result ) );
	YS_Assert::eq( 'confirmed remote failure clears active attempt', '', $failed->get_meta( YSOrderMeta::REFUND_CONFIRMATION_DATA ) );

	echo "== Refund reconciliation: idempotent retry and active-attempt guard ==\n";

	$unknown = ys_refund_test_order();
	ys_refund_capture();
	$unknown_api = new YS_Refund_Test_Api( $unknown );
	$unknown_api->create_responses = array(
		new WP_Error( 'http_request_failed', 'timeout', array( 'http_status' => 0 ) ),
		array( 'status' => 'SUCCEEDED' ),
	);
	$first_unknown = YSRefundReconciliation::process( $unknown, 100.0, 'Unknown', $unknown_api, 'ys_shopline_bnpl', 'ChaileaseBNPL' );
	$first_key = $unknown_api->create_keys[0] ?? '';
	ys_refund_capture();
	$second_unknown = YSRefundReconciliation::process( $unknown, 100.0, 'Unknown', $unknown_api, 'ys_shopline_bnpl', 'ChaileaseBNPL' );
	YS_Assert::eq( 'transport unknown first call remains pending', 'ys_shopline_refund_pending', ys_refund_error_code( $first_unknown ) );
	YS_Assert::eq( 'transport unknown retry replays create with same attempt', 2, $unknown_api->create_calls );
	YS_Assert::eq( 'transport unknown retry keeps idempotency key', $first_key, $unknown_api->create_keys[1] ?? '' );
	YS_Assert::eq( 'idempotent replay success can complete current Woo refund', true, $second_unknown );

	$duplicate = ys_refund_test_order();
	ys_refund_capture();
	$duplicate_api = new YS_Refund_Test_Api( $duplicate );
	$duplicate_api->query_responses = array_fill( 0, 4, ys_refund_response( 'PROCESSING' ) );
	YSRefundReconciliation::process( $duplicate, 100.0, 'Duplicate', $duplicate_api, 'ys_shopline_bnpl', 'ChaileaseBNPL' );
	$queries_before_retry = $duplicate_api->query_calls;
	ys_refund_capture();
	$duplicate_retry = YSRefundReconciliation::process( $duplicate, 100.0, 'Duplicate', $duplicate_api, 'ys_shopline_bnpl', 'ChaileaseBNPL' );
	YS_Assert::eq( 'active attempt retry remains pending', 'ys_shopline_refund_pending', ys_refund_error_code( $duplicate_retry ) );
	YS_Assert::eq( 'active attempt retry does not create another refund', 1, $duplicate_api->create_calls );
	YS_Assert::eq( 'active attempt retry queries current remote refund first', $queries_before_retry + 1, $duplicate_api->query_calls );

	$locked = ys_refund_test_order();
	$GLOBALS['wpdb'] = new wpdb( false );
	ys_refund_capture();
	$locked_api = new YS_Refund_Test_Api( $locked );
	$locked_result = YSRefundReconciliation::process( $locked, 100.0, 'Locked', $locked_api, 'ys_shopline_bnpl', 'ChaileaseBNPL' );
	YS_Assert::eq( 'lock contention fails closed', 'ys_shopline_refund_locked', ys_refund_error_code( $locked_result ) );
	YS_Assert::eq( 'lock contention makes zero remote calls', 0, $locked_api->create_calls );

	echo "== Refund reconciliation: Woo gateway delegation ==\n";

	$gateway_order = ys_refund_test_order();
	ys_refund_capture();
	$gateway_api = new YS_Refund_Test_Api( $gateway_order );
	$gateway_api->query_responses = array_fill( 0, 3, ys_refund_response( 'PROCESSING' ) );
	$gateway = new YS_Refund_Test_Gateway( $gateway_api );
	$gateway_result = $gateway->process_refund( $gateway_order->get_id(), 100.0, 'Gateway delegation' );
	YS_Assert::eq( 'gateway delegates in-flight refund to lifecycle service', 'ys_shopline_refund_pending', ys_refund_error_code( $gateway_result ) );
	YS_Assert::eq( 'gateway delegation performs exactly one immediate query', 1, $gateway_api->query_calls );
	YS_Assert::eq( 'gateway delegation persists active attempt', true, is_array( $gateway_order->get_meta( YSOrderMeta::REFUND_CONFIRMATION_DATA ) ) );

	echo "== Refund reconciliation: manual recheck (admin button) ==\n";

	// 手動重查在途 → 不得推進 stage、不得改動排程（否則商家按一次就吃掉一個自動確認階段）
	$manual = ys_refund_test_order();
	ys_refund_capture();
	$manual_api = new YS_Refund_Test_Api( $manual );
	$manual_api->query_responses = array( ys_refund_response( 'PROCESSING' ), ys_refund_response( 'PROCESSING' ) );
	YSRefundReconciliation::process( $manual, 100.0, 'Manual', $manual_api, 'ys_shopline_bnpl', 'ChaileaseBNPL' );
	$stage_before     = (int) ( $manual->get_meta( YSOrderMeta::REFUND_CONFIRMATION_DATA )['stage'] ?? -1 );
	$scheduled_before = count( $GLOBALS['ys_test_scheduled_actions'] );
	\YSShoplinePayment::$test_api = $manual_api;
	$manual_result = YSRefundReconciliation::manual_recheck( 9201 );
	$stage_after   = (int) ( $manual->get_meta( YSOrderMeta::REFUND_CONFIRMATION_DATA )['stage'] ?? -1 );
	YS_Assert::eq( 'manual recheck reports still pending', 'pending', $manual_result['state'] ?? '' );
	YS_Assert::eq( 'manual recheck does not advance the scheduled stage', $stage_before, $stage_after );
	YS_Assert::eq( 'manual recheck does not add extra scheduled jobs', $scheduled_before, count( $GLOBALS['ys_test_scheduled_actions'] ) );
	YS_Assert::eq( 'manual recheck keeps the active attempt', true, ! empty( $manual->get_meta( YSOrderMeta::REFUND_CONFIRMATION_DATA ) ) );

	// 手動重查取得 SUCCEEDED → 立即補建本地退款並清除 attempt
	$manual_ok = ys_refund_test_order();
	ys_refund_capture();
	$manual_ok_api = new YS_Refund_Test_Api( $manual_ok );
	$manual_ok_api->query_responses = array( ys_refund_response( 'PROCESSING' ), ys_refund_response( 'SUCCEEDED' ) );
	YSRefundReconciliation::process( $manual_ok, 100.0, 'Manual ok', $manual_ok_api, 'ys_shopline_bnpl', 'ChaileaseBNPL' );
	$GLOBALS['ys_test_refund_creations'] = array();
	\YSShoplinePayment::$test_api = $manual_ok_api;
	$manual_ok_result = YSRefundReconciliation::manual_recheck( 9201 );
	YS_Assert::eq( 'manual recheck converges on confirmed success', 'succeeded', $manual_ok_result['state'] ?? '' );
	YS_Assert::eq( 'manual recheck creates exactly one local refund', 1, count( $GLOBALS['ys_test_refund_creations'] ) );
	YS_Assert::eq( 'manual recheck local refund skips the gateway', false, $GLOBALS['ys_test_refund_creations'][0]['refund_payment'] ?? true );
	YS_Assert::eq( 'manual recheck clears the active attempt', '', $manual_ok->get_meta( YSOrderMeta::REFUND_CONFIRMATION_DATA ) );

	// 無在途退款時按按鈕 → 不打遠端
	$manual_none     = ys_refund_test_order();
	$manual_none_api = new YS_Refund_Test_Api( $manual_none );
	\YSShoplinePayment::$test_api = $manual_none_api;
	$manual_none_result = YSRefundReconciliation::manual_recheck( 9201 );
	YS_Assert::eq( 'manual recheck without an attempt reports none', 'none', $manual_none_result['state'] ?? '' );
	YS_Assert::eq( 'manual recheck without an attempt makes zero API calls', 0, $manual_none_api->query_calls + $manual_none_api->create_calls );

	echo "== Refund reconciliation: scheduled exact-once reconstruction ==\n";

	$async = ys_refund_test_order();
	$GLOBALS['ys_test_refund_creations'] = array();
	$GLOBALS['ys_test_refund_creation_result'] = null;
	ys_refund_capture();
	$async_api = new YS_Refund_Test_Api( $async );
	$async_api->query_responses = array( ys_refund_response( 'PROCESSING' ), ys_refund_response( 'SUCCEEDED' ) );
	$pending = YSRefundReconciliation::process( $async, 100.0, 'Customer requested refund', $async_api, 'ys_shopline_bnpl', 'ChaileaseBNPL' );
	$async_attempt = $async->get_meta( YSOrderMeta::REFUND_CONFIRMATION_DATA );
	\YSShoplinePayment::$test_api = $async_api;
	YSRefundReconciliation::reconcile( 9201, (string) $async_attempt['refund_reference'], 0 );

	$creation = $GLOBALS['ys_test_refund_creations'][0] ?? array();
	$local_refund = $async->refunds[0] ?? null;
	YS_Assert::eq( 'async setup remains pending before scheduled query', 'ys_shopline_refund_pending', ys_refund_error_code( $pending ) );
	YS_Assert::eq( 'scheduled success creates one local Woo refund', 1, count( $GLOBALS['ys_test_refund_creations'] ) );
	YS_Assert::eq( 'scheduled local refund never calls gateway again', false, $creation['refund_payment'] ?? true );
	YS_Assert::eq( 'scheduled local refund keeps original amount', 100.0, $creation['amount'] ?? null );
	YS_Assert::eq( 'scheduled local refund keeps original reason', 'Customer requested refund', $creation['reason'] ?? '' );
	YS_Assert::eq( 'scheduled local refund keeps original line items', ys_refund_snapshot_args()['line_items'], $creation['line_items'] ?? array() );
	YS_Assert::eq( 'scheduled local refund keeps restock choice', true, $creation['restock_items'] ?? false );
	YS_Assert::eq( 'local refund is tagged before its first save', (string) $async_attempt['refund_reference'], $local_refund ? $local_refund->get_meta( YSOrderMeta::REFUND_REFERENCE ) : '' );
	YS_Assert::eq( 'scheduled success clears active attempt', '', $async->get_meta( YSOrderMeta::REFUND_CONFIRMATION_DATA ) );

	YSRefundReconciliation::reconcile( 9201, (string) $async_attempt['refund_reference'], 0 );
	YS_Assert::eq( 'duplicate scheduled convergence never creates a second local refund', 1, count( $GLOBALS['ys_test_refund_creations'] ) );

	$unknown_async = ys_refund_test_order( 'ys_shopline_credit' );
	$GLOBALS['ys_test_refund_creations'] = array();
	ys_refund_capture();
	$unknown_async_api = new YS_Refund_Test_Api( $unknown_async );
	$unknown_async_api->create_responses = array(
		new WP_Error( 'http_request_failed', 'timeout', array( 'http_status' => 0 ) ),
		array( 'status' => 'SUCCEEDED' ),
	);
	$unknown_async_pending = YSRefundReconciliation::process( $unknown_async, 100.0, 'Unknown async', $unknown_async_api, 'ys_shopline_credit', 'CreditCard' );
	$unknown_async_attempt = $unknown_async->get_meta( YSOrderMeta::REFUND_CONFIRMATION_DATA );
	$unknown_async_key = $unknown_async_api->create_keys[0] ?? '';
	\YSShoplinePayment::$test_api = $unknown_async_api;
	YSRefundReconciliation::reconcile( 9201, (string) $unknown_async_attempt['refund_reference'], 0 );
	YS_Assert::eq( 'scheduled transport-unknown replay starts from a pending Woo result', 'ys_shopline_refund_pending', ys_refund_error_code( $unknown_async_pending ) );
	YS_Assert::eq( 'scheduled transport-unknown replay reuses the original idempotency key', $unknown_async_key, $unknown_async_api->create_keys[1] ?? '' );
	YS_Assert::eq( 'scheduled idempotent replay success creates one local refund', 1, count( $GLOBALS['ys_test_refund_creations'] ) );
	YS_Assert::eq( 'scheduled idempotent replay clears the active attempt', '', $unknown_async->get_meta( YSOrderMeta::REFUND_CONFIRMATION_DATA ) );

	$adopted = ys_refund_test_order();
	$GLOBALS['ys_test_refund_creations'] = array();
	$existing_refund = new YS_Test_Refund_Object();
	$existing_refund->update_meta_data( YSOrderMeta::REFUND_REFERENCE, '9201_refund_1' );
	$existing_refund->save();
	$adopted->refunds[] = $existing_refund;
	$adopted->meta[ YSOrderMeta::REFUND_CONFIRMATION_DATA ] = array(
		'refund_reference' => '9201_refund_1',
		'idempotent_key'    => 'stable-key',
		'refund_order_id'   => 'refund-remote-1',
		'trade_order_id'    => 'trade-refund-1',
		'amount'            => 10000,
		'currency'          => 'TWD',
		'gateway'           => 'ys_shopline_bnpl',
		'shopline_method'   => 'ChaileaseBNPL',
		'snapshot'          => ys_refund_snapshot_args(),
		'stage'             => 0,
		'started_at'        => time(),
		'last_status'       => 'SUCCEEDED',
	);
	YSRefundReconciliation::reconcile( 9201, '9201_refund_1', 0 );
	YS_Assert::eq( 'crash recovery adopts an already tagged local refund', 0, count( $GLOBALS['ys_test_refund_creations'] ) );
	YS_Assert::eq( 'adopted local refund clears the active attempt', '', $adopted->get_meta( YSOrderMeta::REFUND_CONFIRMATION_DATA ) );

	echo "== Refund reconciliation: local failure and bounded review ==\n";

	$local_failure = ys_refund_test_order();
	$GLOBALS['ys_test_refund_creations'] = array();
	$GLOBALS['ys_test_refund_creation_result'] = new WP_Error( 'local_db_error', 'Local refund save failed' );
	ys_refund_capture();
	$local_failure_api = new YS_Refund_Test_Api( $local_failure );
	$local_failure_api->query_responses = array( ys_refund_response( 'PROCESSING' ), ys_refund_response( 'SUCCEEDED' ) );
	YSRefundReconciliation::process( $local_failure, 100.0, 'Local failure', $local_failure_api, 'ys_shopline_bnpl', 'ChaileaseBNPL' );
	$local_failure_attempt = $local_failure->get_meta( YSOrderMeta::REFUND_CONFIRMATION_DATA );
	\YSShoplinePayment::$test_api = $local_failure_api;
	YSRefundReconciliation::reconcile( 9201, (string) $local_failure_attempt['refund_reference'], 0 );
	$remote_calls_after_failure = $local_failure_api->create_calls + $local_failure_api->query_calls;
	$local_creates_after_failure = count( $GLOBALS['ys_test_refund_creations'] );
	YSRefundReconciliation::reconcile( 9201, (string) $local_failure_attempt['refund_reference'], 0 );
	$local_review = $local_failure->get_meta( YSOrderMeta::REFUND_REVIEW );
	YS_Assert::eq( 'local reconstruction failure raises manual review', 'local_refund_creation_failed', $local_review['type'] ?? '' );
	YS_Assert::eq( 'local reconstruction failure does not repeat remote calls', $remote_calls_after_failure, $local_failure_api->create_calls + $local_failure_api->query_calls );
	YS_Assert::eq( 'manual-review state does not auto-retry local creation', $local_creates_after_failure, count( $GLOBALS['ys_test_refund_creations'] ) );

	$local_throwable = ys_refund_test_order();
	$GLOBALS['ys_test_refund_creations'] = array();
	$GLOBALS['ys_test_refund_creation_result'] = null;
	ys_refund_capture();
	$local_throwable_api = new YS_Refund_Test_Api( $local_throwable );
	$local_throwable_api->query_responses = array( ys_refund_response( 'PROCESSING' ), ys_refund_response( 'SUCCEEDED' ) );
	YSRefundReconciliation::process( $local_throwable, 100.0, 'Local throwable', $local_throwable_api, 'ys_shopline_bnpl', 'ChaileaseBNPL' );
	$local_throwable_attempt = $local_throwable->get_meta( YSOrderMeta::REFUND_CONFIRMATION_DATA );
	$GLOBALS['ys_test_refund_creation_result'] = new RuntimeException( 'Unexpected local failure' );
	\YSShoplinePayment::$test_api = $local_throwable_api;
	$local_throwable_escaped = false;
	try {
		YSRefundReconciliation::reconcile( 9201, (string) $local_throwable_attempt['refund_reference'], 0 );
	} catch ( Throwable $error ) {
		$local_throwable_escaped = true;
	}
	$local_throwable_review = $local_throwable->get_meta( YSOrderMeta::REFUND_REVIEW );
	YS_Assert::eq( 'local reconstruction Throwable is contained by the refund lifecycle', false, $local_throwable_escaped );
	YS_Assert::eq( 'local reconstruction Throwable raises manual review', 'local_refund_creation_failed', $local_throwable_review['type'] ?? '' );

	$bounded = ys_refund_test_order();
	$GLOBALS['ys_test_refund_creation_result'] = null;
	$GLOBALS['ys_test_scheduled_actions'] = array();
	ys_refund_capture();
	$bounded_api = new YS_Refund_Test_Api( $bounded );
	$bounded_api->query_responses = array_fill( 0, 5, ys_refund_response( 'PROCESSING' ) );
	YSRefundReconciliation::process( $bounded, 100.0, 'Bounded', $bounded_api, 'ys_shopline_bnpl', 'ChaileaseBNPL' );
	$bounded_attempt = $bounded->get_meta( YSOrderMeta::REFUND_CONFIRMATION_DATA );
	\YSShoplinePayment::$test_api = $bounded_api;
	$queries_before_stage = $bounded_api->query_calls;
	YSRefundReconciliation::reconcile( 9201, (string) $bounded_attempt['refund_reference'], 0 );
	$stage_one = $bounded->get_meta( YSOrderMeta::REFUND_CONFIRMATION_DATA );
	YS_Assert::eq( 'first scheduled in-flight query advances to stage one', 1, $stage_one['stage'] ?? -1 );
	YS_Assert::eq( 'stage one uses cumulative one-minute target', 60, ( $GLOBALS['ys_test_scheduled_actions'][1]['timestamp'] ?? 0 ) - (int) $stage_one['started_at'] );

	YSRefundReconciliation::reconcile( 9201, (string) $bounded_attempt['refund_reference'], 0 );
	YS_Assert::eq( 'stale scheduled stage performs no query', $queries_before_stage + 1, $bounded_api->query_calls );

	YSRefundReconciliation::reconcile( 9201, (string) $bounded_attempt['refund_reference'], 1 );
	YSRefundReconciliation::reconcile( 9201, (string) $bounded_attempt['refund_reference'], 2 );
	YSRefundReconciliation::reconcile( 9201, (string) $bounded_attempt['refund_reference'], 3 );
	YSRefundReconciliation::reconcile( 9201, (string) $bounded_attempt['refund_reference'], 4 );
	YSRefundReconciliation::reconcile( 9201, (string) $bounded_attempt['refund_reference'], 5 );
	YSRefundReconciliation::reconcile( 9201, (string) $bounded_attempt['refund_reference'], 6 );
	$bounded_review = $bounded->get_meta( YSOrderMeta::REFUND_REVIEW );
	YS_Assert::eq( 'final unresolved stage raises fail-closed review', 'remote_status_unresolved', $bounded_review['type'] ?? '' );
	// 節奏 30s/1m/3m/5m/10m/1h/6h：前段密集讓多數退款在商家還在後台時就收斂，
	// 末點 6h 與付款端卡類觀察窗一致，給足通路收斂空間才進人工。
	YS_Assert::eq( 'seven cumulative reconciliation jobs are scheduled', 7, count( $GLOBALS['ys_test_scheduled_actions'] ) );

	echo "== Refund reconciliation: confirmed API rejection ==\n";

	$rejected = ys_refund_test_order( 'ys_shopline_credit' );
	$GLOBALS['ys_test_scheduled_actions'] = array();
	ys_refund_capture();
	$rejected_api = new YS_Refund_Test_Api( $rejected );
	$rejected_api->create_responses[] = new WP_Error( '1004', 'Refund rejected', array( 'http_status' => 400 ) );
	$rejected_result = YSRefundReconciliation::process( $rejected, 100.0, 'Rejected', $rejected_api, 'ys_shopline_credit', 'CreditCard' );
	YS_Assert::eq( 'confirmed HTTP 400 returns original business error', '1004', ys_refund_error_code( $rejected_result ) );
	YS_Assert::eq( 'confirmed HTTP 400 clears active attempt', '', $rejected->get_meta( YSOrderMeta::REFUND_CONFIRMATION_DATA ) );
	YS_Assert::eq( 'confirmed HTTP 400 schedules no reconciliation', 0, count( $GLOBALS['ys_test_scheduled_actions'] ) );

	$generic_rejected = ys_refund_test_order( 'ys_shopline_credit' );
	ys_refund_capture();
	$generic_rejected_api = new YS_Refund_Test_Api( $generic_rejected );
	$generic_rejected_api->create_responses[] = new WP_Error( 'api_error', 'Bad request', array( 'http_status' => 400 ) );
	$generic_rejected_result = YSRefundReconciliation::process( $generic_rejected, 100.0, 'Rejected', $generic_rejected_api, 'ys_shopline_credit', 'CreditCard' );
	YS_Assert::eq( 'generic API error with definitive HTTP 400 is not treated as transport unknown', 'api_error', ys_refund_error_code( $generic_rejected_result ) );
	YS_Assert::eq( 'generic HTTP 400 schedules no reconciliation', 0, count( $GLOBALS['ys_test_scheduled_actions'] ) );

	echo "== Refund reconciliation: scheduler durability ==\n";

	$lock_retry = ys_refund_test_order();
	$lock_retry->meta[ YSOrderMeta::REFUND_CONFIRMATION_DATA ] = array(
		'refund_reference' => '9201_refund_1',
		'idempotent_key'    => 'stable-key',
		'refund_order_id'   => 'refund-remote-1',
		'trade_order_id'    => 'trade-refund-1',
		'amount'            => 10000,
		'currency'          => 'TWD',
		'gateway'           => 'ys_shopline_bnpl',
		'shopline_method'   => 'ChaileaseBNPL',
		'snapshot'          => ys_refund_snapshot_args(),
		'stage'             => 0,
		'started_at'        => time(),
		'last_status'       => 'PROCESSING',
	);
	$GLOBALS['wpdb'] = new wpdb( false );
	YSRefundReconciliation::reconcile( 9201, '9201_refund_1', 0 );
	YS_Assert::eq( 'scheduled lock contention requeues the same stage', 1, count( $GLOBALS['ys_test_scheduled_actions'] ) );
	YS_Assert::eq( 'lock-contention retry keeps exact stage', 0, $GLOBALS['ys_test_scheduled_actions'][0]['args'][2] ?? -1 );

	$schedule_failure = ys_refund_test_order();
	$GLOBALS['ys_test_schedule_result'] = 0;
	ys_refund_capture();
	$schedule_failure_api = new YS_Refund_Test_Api( $schedule_failure );
	$schedule_failure_api->query_responses = array_fill( 0, 3, ys_refund_response( 'PROCESSING' ) );
	$schedule_failure_result = YSRefundReconciliation::process( $schedule_failure, 100.0, 'Schedule failure', $schedule_failure_api, 'ys_shopline_bnpl', 'ChaileaseBNPL' );
	$schedule_failure_review = $schedule_failure->get_meta( YSOrderMeta::REFUND_REVIEW );
	YS_Assert::eq( 'scheduler insertion failure remains fail-closed', 'ys_shopline_refund_pending', ys_refund_error_code( $schedule_failure_result ) );
	YS_Assert::eq( 'scheduler insertion failure raises manual review immediately', 'reconcile_schedule_failed', $schedule_failure_review['type'] ?? '' );
	YS_Assert::eq( 'refund review appears in the existing admin review queue', true, YSOrderPaymentAdmin::has_open_refund_review( $schedule_failure ) );
	$review_admin = new YSOrderPaymentAdmin();
	$review_actions = $review_admin->add_resolve_review_order_action( array(), $schedule_failure );
	YS_Assert::eq( 'refund review exposes the existing manual-resolution order action', true, isset( $review_actions['ys_shopline_resolve_review'] ) );
	$review_admin->handle_resolve_review_order_action( $schedule_failure );
	YS_Assert::eq( 'resolved refund review leaves the active admin queue', false, YSOrderPaymentAdmin::has_open_refund_review( $schedule_failure ) );
	YS_Assert::eq( 'manual refund review resolution clears the active attempt', '', $schedule_failure->get_meta( YSOrderMeta::REFUND_CONFIRMATION_DATA ) );
	$schedule_failure_history = $schedule_failure->get_meta( YSOrderMeta::REFUND_HISTORY );
	YS_Assert::eq( 'manual refund review resolution preserves an audit history event', 'manual_review_resolved', $schedule_failure_history[0]['event'] ?? '' );
	$GLOBALS['ys_test_schedule_result'] = null;

	$scheduled_reject = ys_refund_test_order();
	$scheduled_reject->meta[ YSOrderMeta::REFUND_CONFIRMATION_DATA ] = array(
		'refund_reference' => '9201_refund_1',
		'idempotent_key'    => 'stable-key',
		'refund_order_id'   => '',
		'trade_order_id'    => 'trade-refund-1',
		'amount'            => 10000,
		'currency'          => 'TWD',
		'gateway'           => 'ys_shopline_bnpl',
		'shopline_method'   => 'ChaileaseBNPL',
		'snapshot'          => ys_refund_snapshot_args(),
		'stage'             => 0,
		'started_at'        => time(),
		'last_status'       => 'UNKNOWN',
	);
	$scheduled_reject_api = new YS_Refund_Test_Api( $scheduled_reject );
	$scheduled_reject_api->create_responses[] = new WP_Error( '1004', 'Refund rejected', array( 'http_status' => 400 ) );
	\YSShoplinePayment::$test_api = $scheduled_reject_api;
	YSRefundReconciliation::reconcile( 9201, '9201_refund_1', 0 );
	YS_Assert::eq( 'scheduled idempotent replay clears a definitive create rejection', '', $scheduled_reject->get_meta( YSOrderMeta::REFUND_CONFIRMATION_DATA ) );
	YS_Assert::eq( 'scheduled definitive rejection does not enqueue another query', 0, count( $GLOBALS['ys_test_scheduled_actions'] ) );

	echo "== Refund reconciliation: API envelope integrity ==\n";

	$create_mismatch = ys_refund_test_order( 'ys_shopline_credit' );
	ys_refund_capture();
	$create_mismatch_api = new YS_Refund_Test_Api( $create_mismatch );
	$create_mismatch_api->create_responses[] = array(
		'status' => 'SUCCEEDED',
		'amount' => array( 'value' => 9999, 'currency' => 'TWD' ),
	);
	$create_mismatch_result = YSRefundReconciliation::process( $create_mismatch, 100.0, 'Mismatch', $create_mismatch_api, 'ys_shopline_credit', 'CreditCard' );
	YS_Assert::eq( 'mismatched create success is fail-closed', 'ys_shopline_refund_response_mismatch', ys_refund_error_code( $create_mismatch_result ) );
	YS_Assert::eq( 'mismatched create success raises review', 'remote_envelope_mismatch', $create_mismatch->get_meta( YSOrderMeta::REFUND_REVIEW )['type'] ?? '' );

	$query_mismatch = ys_refund_test_order( 'ys_shopline_credit' );
	ys_refund_capture();
	$query_mismatch_api = new YS_Refund_Test_Api( $query_mismatch );
	$query_mismatch_api->query_responses[] = array_merge(
		ys_refund_response( 'SUCCEEDED' ),
		array( 'amount' => array( 'value' => 10000, 'currency' => 'USD' ) )
	);
	$query_mismatch_result = YSRefundReconciliation::process( $query_mismatch, 100.0, 'Mismatch', $query_mismatch_api, 'ys_shopline_credit', 'CreditCard' );
	YS_Assert::eq( 'mismatched query success is fail-closed', 'ys_shopline_refund_response_mismatch', ys_refund_error_code( $query_mismatch_result ) );
	YS_Assert::eq( 'mismatched query success keeps active attempt for audit', true, is_array( $query_mismatch->get_meta( YSOrderMeta::REFUND_CONFIRMATION_DATA ) ) );

	$query_id_mismatch = ys_refund_test_order( 'ys_shopline_credit' );
	ys_refund_capture();
	$query_id_mismatch_api = new YS_Refund_Test_Api( $query_id_mismatch );
	$query_id_mismatch_api->query_responses[] = array_merge(
		ys_refund_response( 'SUCCEEDED' ),
		array( 'refundOrderId' => 'different-refund-order' )
	);
	$query_id_mismatch_result = YSRefundReconciliation::process( $query_id_mismatch, 100.0, 'Mismatch', $query_id_mismatch_api, 'ys_shopline_credit', 'CreditCard' );
	YS_Assert::eq( 'query response for a different refund order is fail-closed', 'ys_shopline_refund_response_mismatch', ys_refund_error_code( $query_id_mismatch_result ) );
	YS_Assert::eq( 'different refund order never becomes a successful Woo refund', '', $query_id_mismatch->get_meta( YSOrderMeta::REFUND_DETAIL ) );
}
