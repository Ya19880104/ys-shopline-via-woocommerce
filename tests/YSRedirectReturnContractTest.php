<?php
/**
 * Contract tests for wallet return-page status convergence.
 *
 * @package YangSheep\ShoplinePayment\Tests
 */

declare(strict_types=1);

use YangSheep\ShoplinePayment\Handlers\YSRedirectHandler;
use YangSheep\ShoplinePayment\Handlers\YSPaymentConfirmation;
use YangSheep\ShoplinePayment\Utils\YSOrderMeta;

final class YS_Redirect_Return_Test_Order extends WC_Order {
	public array $meta = array();
	public array $notes = array();
	public string $status = 'pending';
	public bool $paid = false;
	public $date_paid = null;
	public int $payment_complete_count = 0;

	public function __construct( private string $gateway_id = 'ys_shopline_linepay' ) {
		$shopline_method = array(
			'ys_shopline_linepay'  => 'LinePay',
			'ys_shopline_applepay' => 'ApplePay',
			'ys_shopline_atm'      => 'VirtualAccount',
		)[ $gateway_id ] ?? 'LinePay';
		$this->meta[ YSOrderMeta::TRADE_ORDER_ID ]     = 'wallet-trade-3433';
		$this->meta[ YSOrderMeta::REFERENCE_ORDER_ID ] = '3433_1';
		$this->meta[ YSOrderMeta::PAYMENT_ATTEMPT_DATA ] = array(
			'reference'       => '3433_1',
			'trade_order_id'  => 'wallet-trade-3433',
			'session_id'      => 'wallet-session-3433',
			'gateway'         => $gateway_id,
			'shopline_method' => $shopline_method,
			'amount'          => 22000,
			'currency'        => 'TWD',
			'started_at'      => time(),
		);
	}

	public function get_id(): int { return 3433; }
	public function get_status(): string { return $this->status; }
	public function get_payment_method(): string { return $this->gateway_id; }
	public function get_total(): float { return 220.0; }
	public function get_currency(): string { return 'TWD'; }
	public function is_paid(): bool { return $this->paid; }
	public function get_date_paid() { return $this->date_paid; }
	public function get_meta( string $key ) { return $this->meta[ $key ] ?? ''; }
	public function update_meta_data( string $key, $value ): void { $this->meta[ $key ] = $value; }
	public function delete_meta_data( string $key ): void { unset( $this->meta[ $key ] ); }
	public function add_order_note( string $note ): void { $this->notes[] = $note; }
	public function update_status( string $status, string $note = '' ): void {
		$this->status = $status;
		if ( '' !== $note ) { $this->notes[] = $note; }
	}
	public function save(): void {}
	public function save_meta_data(): void {}
	public function payment_complete( string $trade_order_id = '' ): bool {
		$this->payment_complete_count++;
		$this->paid = true;
		$this->date_paid = '2026-07-20 10:53:00';
		$this->status = 'processing';
		return true;
	}
}

final class YS_Redirect_Return_Test_Api {
	public int $query_calls = 0;
	public function __construct( private string $status = 'CUSTOMER_ACTION', private string $method = 'LinePay' ) {}

	public function get_payment_trade( string $trade_order_id ): array {
		$this->query_calls++;
		return array(
			'tradeOrderId'    => $trade_order_id,
			'referenceOrderId'=> '3433_1',
			'status'           => $this->status,
			'paymentMethod'    => $this->method,
			'amount'           => array( 'value' => 22000, 'currency' => 'TWD' ),
		);
	}
}

function ys_run_redirect_return_contract(): void {
	echo "== Redirect return: wallet customer-pending display state ==\n";
	$order = new YS_Redirect_Return_Test_Order();
	$api   = new YS_Redirect_Return_Test_Api();
	YSShoplinePayment::$test_api = $api;
	$GLOBALS['ys_test_scheduled_actions'] = array();

	$method = new ReflectionMethod( YSRedirectHandler::class, 'check_and_update_order' );
	$method->invoke( null, $order, 'wallet-trade-3433' );

	$attempt = $order->get_meta( YSOrderMeta::CONFIRMATION_DATA );
	YS_Assert::eq( 'wallet return performs one status query', 1, $api->query_calls );
	YS_Assert::eq( 'wallet CUSTOMER_ACTION return enters payment confirmation', YSPaymentConfirmation::STATUS_KEY, $order->get_status() );
	YS_Assert::eq( 'wallet return preserves exact reference', '3433_1', $attempt['reference'] ?? '' );
	YS_Assert::eq( 'wallet return preserves exact trade ID', 'wallet-trade-3433', $attempt['trade_order_id'] ?? '' );
	YS_Assert::eq( 'wallet return stores remote customer-pending state', 'CUSTOMER_ACTION', $attempt['remote_status'] ?? '' );
	YS_Assert::eq( 'wallet return schedules confirmation reconciliation', 1, count( $GLOBALS['ys_test_scheduled_actions'] ) );

	$apple_order = new YS_Redirect_Return_Test_Order( 'ys_shopline_applepay' );
	YSShoplinePayment::$test_api = new YS_Redirect_Return_Test_Api( 'CREATED', 'ApplePay' );
	$GLOBALS['ys_test_scheduled_actions'] = array();
	$method->invoke( null, $apple_order, 'wallet-trade-3433' );
	YS_Assert::eq( 'Apple Pay CREATED return enters payment confirmation', YSPaymentConfirmation::STATUS_KEY, $apple_order->get_status() );
	YS_Assert::eq( 'Apple Pay return schedules confirmation reconciliation', 1, count( $GLOBALS['ys_test_scheduled_actions'] ) );

	$atm_order = new YS_Redirect_Return_Test_Order( 'ys_shopline_atm' );
	YSShoplinePayment::$test_api = new YS_Redirect_Return_Test_Api( 'CREATED', 'VirtualAccount' );
	$GLOBALS['ys_test_scheduled_actions'] = array();
	$method->invoke( null, $atm_order, 'wallet-trade-3433' );
	YS_Assert::eq( 'ATM CREATED return keeps the existing pending flow', 'pending', $atm_order->get_status() );
	YS_Assert::eq( 'ATM CREATED return does not schedule confirmation polling', 0, count( $GLOBALS['ys_test_scheduled_actions'] ) );

	$custom_paid = new YS_Redirect_Return_Test_Order();
	$custom_paid->status = 'shipped';
	$custom_paid->date_paid = '2026-07-20 10:53:00';
	$GLOBALS['ys_test_order'] = $custom_paid;
	YSShoplinePayment::$test_api = new YS_Redirect_Return_Test_Api( 'SUCCEEDED', 'LinePay' );
	$method->invoke( null, $custom_paid, 'wallet-trade-3433' );
	YS_Assert::eq( 'return query never replays payment_complete after date_paid', 0, $custom_paid->payment_complete_count );
	YS_Assert::eq( 'return query retains custom paid status', 'shipped', $custom_paid->status );
}
