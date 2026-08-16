<?php
/**
 * Contract tests for scheduled subscription renewal retry policy.
 *
 * @package YangSheep\ShoplinePayment\Tests
 */

declare(strict_types=1);

use YangSheep\ShoplinePayment\Gateways\YSCreditSubscription;
use YangSheep\ShoplinePayment\Utils\YSOrderMeta;

final class YS_Subscription_Test_Order extends WC_Order {
	public array $meta = array();
	public array $notes = array();
	public string $status = 'pending';
	public bool $paid = false;
	public string $date_paid = '';
	public int $payment_complete_count = 0;

	public function get_id(): int {
		return 9001;
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

	public function get_status(): string {
		return $this->status;
	}

	public function is_paid(): bool {
		return $this->paid;
	}

	public function get_date_paid(): string {
		return $this->date_paid;
	}

	public function payment_complete( string $trade_order_id = '' ): bool {
		$this->payment_complete_count++;
		$this->paid      = true;
		$this->date_paid = '2026-07-20 12:00:00';
		$this->status    = 'processing';
		return true;
	}

	public function save(): void {}
}

final class YS_Subscription_Test_Api {
	public $prior_response = array( 'tradeOrderId' => 'prior', 'status' => 'PROCESSING' );
	public int $get_calls = 0;

	public function get_payment_trade( string $trade_id ) {
		$this->get_calls++;
		return $this->prior_response;
	}
}

final class YS_Subscription_Test_Gateway extends YSCreditSubscription {
	public array $responses = array();
	public array $charge_instruments = array();
	public array $handled = array();
	public array $unknown = array();
	public string $bound = 'bound-card';
	public string $fallback = 'fallback-card';
	public bool $use_real_guard = false;

	public function __construct() {
		$this->id  = 'ys_shopline_credit_subscription';
		$this->api = new YS_Subscription_Test_Api();
	}

	public function api(): YS_Subscription_Test_Api {
		return $this->api;
	}

	protected function can_start_recurring_payment( $order ) {
		return $this->use_real_guard ? parent::can_start_recurring_payment( $order ) : true;
	}

	protected function get_shopline_customer_id( $user_id ) {
		return 'customer-1';
	}

	protected function get_bound_subscription_instrument_id( $order ) {
		return '' !== $this->bound ? $this->bound : false;
	}

	protected function get_user_default_instrument_id( $user_id ) {
		return '' !== $this->fallback ? $this->fallback : false;
	}

	protected function try_recurring_charge( $order, $amount, $customer_id, $instrument_id ) {
		$this->charge_instruments[] = $instrument_id;
		$response = array_shift( $this->responses );

		return array(
			'response'       => $response,
			'payment_data'   => array(
				'referenceOrderId' => '9001_' . count( $this->charge_instruments ),
				'amount'           => array( 'value' => 1000, 'currency' => 'TWD' ),
				'confirm'          => array( 'paymentMethod' => 'CreditCard' ),
			),
			'idempotent_key' => 'key-' . count( $this->charge_instruments ),
		);
	}

	protected function handle_recurring_response( $order, $response ) {
		$this->handled[] = $response;
	}

	protected function handle_recurring_unknown( $order, array $attempt, $instrument_id ) {
		$this->unknown[] = $instrument_id;
	}

	protected function log( $message, $level = 'info' ) {}
}

final class YS_Subscription_Response_Gateway extends YSCreditSubscription {
	public function __construct() {
		$this->id = 'ys_shopline_credit_subscription';
	}

	public function handle_for_test( YS_Subscription_Test_Order $order, array $response ): void {
		$this->handle_recurring_response( $order, $response );
	}

	protected function log( $message, $level = 'info' ) {}
}

/**
 * Run scheduled-renewal contract cases.
 *
 * @return void
 */
function ys_run_subscription_renewal_contract(): void {
	echo "== Subscription renewal: alternate-card retry policy ==\n";

	$order = new YS_Subscription_Test_Order();
	$gw = new YS_Subscription_Test_Gateway();
	$gw->responses = array( new WP_Error( 'http_request_failed', 'timeout' ) );
	$gw->process_subscription_payment( 10, $order );
	YS_Assert::eq( 'transport unknown calls create once', array( 'bound-card' ), $gw->charge_instruments );
	YS_Assert::eq( 'transport unknown never reaches fallback', array( 'bound-card' ), $gw->unknown );

	foreach ( array( '1001', '4003' ) as $code ) {
		$order = new YS_Subscription_Test_Order();
		$gw = new YS_Subscription_Test_Gateway();
		$gw->responses = array( new WP_Error( $code, 'ambiguous remote outcome' ) );
		$gw->process_subscription_payment( 10, $order );
		YS_Assert::eq( "business code {$code} unknown never reaches fallback", array( 'bound-card' ), $gw->charge_instruments );
		YS_Assert::eq( "business code {$code} records bound-card unknown", array( 'bound-card' ), $gw->unknown );
	}

	$order = new YS_Subscription_Test_Order();
	$gw = new YS_Subscription_Test_Gateway();
	$gw->responses = array( array( 'status' => 'SUCCEEDED' ) );
	$gw->process_subscription_payment( 10, $order );
	YS_Assert::eq( 'missing trade ID calls create once', array( 'bound-card' ), $gw->charge_instruments );
	YS_Assert::eq( 'missing trade ID becomes unknown', array( 'bound-card' ), $gw->unknown );

	$order = new YS_Subscription_Test_Order();
	$gw = new YS_Subscription_Test_Gateway();
	$gw->responses = array( array( 'tradeOrderId' => 't1', 'status' => 'PROCESSING' ) );
	$gw->process_subscription_payment( 10, $order );
	YS_Assert::eq( 'accepted in-flight calls create once', array( 'bound-card' ), $gw->charge_instruments );
	YS_Assert::eq( 'accepted in-flight is handled without fallback', 1, count( $gw->handled ) );

	$order = new YS_Subscription_Test_Order();
	$gw = new YS_Subscription_Test_Gateway();
	$gw->responses = array(
		new WP_Error( '4451', 'issuer declined' ),
		array( 'tradeOrderId' => 't2', 'status' => 'SUCCEEDED' ),
	);
	$gw->process_subscription_payment( 10, $order );
	YS_Assert::eq( 'explicit decline may use alternate card', array( 'bound-card', 'fallback-card' ), $gw->charge_instruments );
	YS_Assert::eq( 'alternate accepted response handled once', 1, count( $gw->handled ) );

	foreach ( array( '4450', '4900' ) as $code ) {
		$order = new YS_Subscription_Test_Order();
		$gw = new YS_Subscription_Test_Gateway();
		$gw->responses = array(
			new WP_Error( $code, 'definitive rejection' ),
			array( 'tradeOrderId' => 'fallback-ok', 'status' => 'SUCCEEDED' ),
		);
		$gw->process_subscription_payment( 10, $order );
		YS_Assert::eq( "explicit rejection {$code} may use alternate card", array( 'bound-card', 'fallback-card' ), $gw->charge_instruments );
	}

	$order = new YS_Subscription_Test_Order();
	$gw = new YS_Subscription_Test_Gateway();
	$gw->fallback = 'bound-card';
	$gw->responses = array( new WP_Error( '4451', 'issuer declined' ) );
	$gw->process_subscription_payment( 10, $order );
	YS_Assert::eq( 'same instrument is never retried as fallback', array( 'bound-card' ), $gw->charge_instruments );

	echo "== Subscription renewal: pre-create guards ==\n";

	$order = new YS_Subscription_Test_Order();
	$order->meta[ YSOrderMeta::INDETERMINATE_REF ] = '9001_1';
	$order->meta[ YSOrderMeta::INDETERMINATE_DATA ] = array( 'session_id' => '' );
	$gw = new YS_Subscription_Test_Gateway();
	$gw->use_real_guard = true;
	$gw->process_subscription_payment( 10, $order );
	YS_Assert::eq( 'indeterminate marker blocks before create', 0, count( $gw->charge_instruments ) );
	YS_Assert::eq( 'indeterminate renewal moves to on-hold', 'on-hold', $order->status );

	$order = new YS_Subscription_Test_Order();
	$order->meta[ YSOrderMeta::TRADE_ORDER_ID ] = 'prior-active';
	$order->meta[ YSOrderMeta::REFERENCE_ORDER_ID ] = '9001_1';
	$gw = new YS_Subscription_Test_Gateway();
	$gw->use_real_guard = true;
	$gw->api()->prior_response = array( 'tradeOrderId' => 'prior-active', 'status' => 'PROCESSING' );
	$gw->process_subscription_payment( 10, $order );
	YS_Assert::eq( 'existing in-flight trade blocks before create', 0, count( $gw->charge_instruments ) );
	YS_Assert::eq( 'existing in-flight trade is handled once', 1, count( $gw->handled ) );

	$order = new YS_Subscription_Test_Order();
	$order->meta[ YSOrderMeta::TRADE_ORDER_ID ] = 'prior-failed';
	$order->meta[ YSOrderMeta::REFERENCE_ORDER_ID ] = '9001_1';
	$gw = new YS_Subscription_Test_Gateway();
	$gw->use_real_guard = true;
	$gw->api()->prior_response = array( 'tradeOrderId' => 'prior-failed', 'status' => 'FAILED' );
	$gw->responses = array( array( 'tradeOrderId' => 'new-trade', 'status' => 'SUCCEEDED' ) );
	$gw->process_subscription_payment( 10, $order );
	YS_Assert::eq( 'terminal prior trade permits one new create', array( 'bound-card' ), $gw->charge_instruments );

	$order = new YS_Subscription_Test_Order();
	$order->meta[ YSOrderMeta::TRADE_ORDER_ID ] = 'copied-old-trade';
	$order->meta[ YSOrderMeta::REFERENCE_ORDER_ID ] = '8123_1';
	$gw = new YS_Subscription_Test_Gateway();
	$gw->use_real_guard = true;
	$gw->responses = array( array( 'tradeOrderId' => 'new-trade', 'status' => 'SUCCEEDED' ) );
	$gw->process_subscription_payment( 10, $order );
	YS_Assert::eq( 'copied prior-cycle meta does not block current renewal', array( 'bound-card' ), $gw->charge_instruments );
	YS_Assert::eq( 'copied prior-cycle trade is not queried as current', 0, $gw->api()->get_calls );

	$order = new YS_Subscription_Test_Order();
	$order->meta[ YSOrderMeta::TRADE_ORDER_ID ] = 'prefix-collision-trade';
	$order->meta[ YSOrderMeta::REFERENCE_ORDER_ID ] = '90011_1';
	$gw = new YS_Subscription_Test_Gateway();
	$gw->use_real_guard = true;
	$gw->responses = array( array( 'tradeOrderId' => 'new-trade', 'status' => 'SUCCEEDED' ) );
	$gw->process_subscription_payment( 10, $order );
	YS_Assert::eq( 'reference 90011_ is not treated as order 9001_ prefix', 0, $gw->api()->get_calls );
	YS_Assert::eq( 'prefix collision meta permits one current renewal create', array( 'bound-card' ), $gw->charge_instruments );

	foreach (
		array(
			'PARTIALLY_REFUNDED' => array( false, true ),
			'REFUNDED'           => array( true, true ),
			''                   => array( false, false ),
			'WHAT'               => array( false, false ),
		) as $status => $expectation
	) {
		$order = new YS_Subscription_Test_Order();
		$order->meta[ YSOrderMeta::TRADE_ORDER_ID ] = 'prior-boundary';
		$order->meta[ YSOrderMeta::REFERENCE_ORDER_ID ] = '9001_1';
		$gw = new YS_Subscription_Test_Gateway();
		$gw->use_real_guard = true;
		$gw->api()->prior_response = array( 'tradeOrderId' => 'prior-boundary', 'status' => $status );
		if ( $expectation[0] ) {
			$gw->responses = array( array( 'tradeOrderId' => 'new-trade', 'status' => 'SUCCEEDED' ) );
		}
		$gw->process_subscription_payment( 10, $order );
		YS_Assert::eq(
			"prior status [{$status}] create count",
			$expectation[0] ? 1 : 0,
			count( $gw->charge_instruments )
		);
		YS_Assert::eq(
			"prior status [{$status}] handled count",
			$expectation[1] ? 1 : 0,
			count( $gw->handled )
		);
	}

	echo "== Subscription renewal: copied meta exclusion ==\n";
	$sql = YSCreditSubscription::exclude_order_specific_meta_from_renewals( 'SELECT * FROM wp_postmeta WHERE 1=1', 9001, 8001 );
	YS_Assert::is_true( 'renewal copy excludes trade ID', str_contains( $sql, YSOrderMeta::TRADE_ORDER_ID ) );
	YS_Assert::is_true( 'renewal copy excludes reference ID', str_contains( $sql, YSOrderMeta::REFERENCE_ORDER_ID ) );
	YS_Assert::is_true( 'renewal copy excludes indeterminate marker', str_contains( $sql, YSOrderMeta::INDETERMINATE_REF ) );
	YS_Assert::is_true( 'renewal copy excludes exact payment attempt envelope', str_contains( $sql, YSOrderMeta::PAYMENT_ATTEMPT_DATA ) );
	YS_Assert::is_true( 'renewal copy excludes active confirmation lock', str_contains( $sql, YSOrderMeta::CONFIRMATION_DATA ) );
	YS_Assert::is_true( 'renewal copy excludes confirmation history', str_contains( $sql, YSOrderMeta::CONFIRMATION_HISTORY ) );
	YS_Assert::is_true( 'renewal copy excludes confirmation mail map', str_contains( $sql, YSOrderMeta::CONFIRMATION_MAIL_SENT ) );
	YS_Assert::is_true( 'renewal copy excludes confirmation review marker', str_contains( $sql, YSOrderMeta::CONFIRMATION_REVIEW ) );
	YS_Assert::is_true( 'renewal copy excludes active refund attempt', str_contains( $sql, YSOrderMeta::REFUND_CONFIRMATION_DATA ) );
	YS_Assert::is_true( 'renewal copy excludes refund history', str_contains( $sql, YSOrderMeta::REFUND_HISTORY ) );
	YS_Assert::is_true( 'renewal copy excludes refund review marker', str_contains( $sql, YSOrderMeta::REFUND_REVIEW ) );
	YS_Assert::eq( 'renewal copy keeps subscription instrument ID', false, str_contains( $sql, YSOrderMeta::PAYMENT_INSTRUMENT_ID ) );

	echo "== Subscription renewal: payment completion convergence ==\n";
	$GLOBALS['wpdb'] = null;
	$completion_order = new YS_Subscription_Test_Order();
	$GLOBALS['ys_test_order'] = $completion_order;
	$response_gateway = new YS_Subscription_Response_Gateway();
	$paid_response = array( 'tradeOrderId' => 'renewal-paid-1', 'status' => 'SUCCEEDED' );
	$response_gateway->handle_for_test( $completion_order, $paid_response );
	$response_gateway->handle_for_test( $completion_order, $paid_response );
	YS_Assert::eq( 'recurring response completes payment only once', 1, $completion_order->payment_complete_count );
	YS_Assert::eq( 'recurring response writes one completion note', 1, count( $completion_order->notes ) );

	$custom_paid = new YS_Subscription_Test_Order();
	$custom_paid->status = 'custom-paid';
	$custom_paid->date_paid = '2026-07-20 12:00:00';
	$GLOBALS['ys_test_order'] = $custom_paid;
	$response_gateway->handle_for_test( $custom_paid, $paid_response );
	YS_Assert::eq( 'recurring response respects custom paid history', 0, $custom_paid->payment_complete_count );
	YS_Assert::eq( 'custom paid renewal retains its status', 'custom-paid', $custom_paid->status );
}
