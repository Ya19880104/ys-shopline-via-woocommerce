<?php
/**
 * Contract tests for status-sync ordering after an order has paid history.
 *
 * @package YangSheep\ShoplinePayment\Tests
 */

declare(strict_types=1);

use YangSheep\ShoplinePayment\DTOs\YSPaymentDTO;
use YangSheep\ShoplinePayment\Handlers\YSStatusManager;
use YangSheep\ShoplinePayment\Utils\YSOrderMeta;

final class YS_Status_Sync_Paid_Order extends WC_Order {
	public array $meta = array();
	public array $notes = array();
	public string $status = 'completed';
	public string $payment_method = 'ys_shopline_credit';
	public bool $paid = true;
	public $date_paid = '2026-07-20 10:53:00';
	public int $payment_complete_count = 0;
	public int $save_count = 0;

	public function get_id(): int { return 9639; }
	public function get_status(): string { return $this->status; }
	public function get_payment_method(): string { return $this->payment_method; }
	public function get_date_paid() { return $this->date_paid; }
	public function is_paid(): bool { return $this->paid; }
	public function get_meta( string $key ) { return $this->meta[ $key ] ?? ''; }
	public function update_meta_data( string $key, $value ): void { $this->meta[ $key ] = $value; }
	public function delete_meta_data( string $key ): void { unset( $this->meta[ $key ] ); }
	public function update_status( string $status, string $note = '' ): void {
		$this->status = $status;
		if ( '' !== $note ) {
			$this->notes[] = $note;
		}
	}
	public function add_order_note( string $note ): void { $this->notes[] = $note; }
	public function payment_complete( string $trade_order_id = '' ): bool {
		$this->payment_complete_count++;
		$this->paid = true;
		$this->status = 'processing';
		$this->date_paid = '2026-07-20 10:53:00';
		return true;
	}
	public function save(): void { $this->save_count++; }
}

final class YS_Status_Sync_Api {
	public function __construct( public YSPaymentDTO $result ) {}
	public function query_payment( string $trade_order_id ): YSPaymentDTO { return $this->result; }
}

function ys_status_sync_dto( string $status, string $method = 'CreditCard' ): YSPaymentDTO {
	return new YSPaymentDTO(
		trade_order_id: 'trade-paid-history',
		status: $status,
		payment_method: $method,
		amount: array( 'value' => 10000, 'currency' => 'TWD' ),
		raw_data: array(
			'tradeOrderId' => 'trade-paid-history',
			'status' => $status,
			'paymentMethod' => $method,
			'amount' => array( 'value' => 10000, 'currency' => 'TWD' ),
		)
	);
}

function ys_status_sync_paid_order( string $gateway = 'ys_shopline_credit' ): YS_Status_Sync_Paid_Order {
	$order = new YS_Status_Sync_Paid_Order();
	$order->payment_method = $gateway;
	$order->meta[ YSOrderMeta::TRADE_ORDER_ID ] = 'trade-paid-history';
	$order->meta[ YSOrderMeta::PAYMENT_STATUS ] = 'SUCCEEDED';
	return $order;
}

function ys_run_status_sync_paid_history_contract(): void {
	echo "== Status sync: paid-history ordering guard ==\n";

	$gateways = array(
		'ys_shopline_credit'              => 'CreditCard',
		'ys_shopline_credit_installment'  => 'CreditCard',
		'ys_shopline_credit_subscription' => 'CreditCard',
		'ys_shopline_atm'                 => 'VirtualAccount',
		'ys_shopline_jkopay'              => 'JKOPay',
		'ys_shopline_applepay'            => 'ApplePay',
		'ys_shopline_linepay'             => 'LinePay',
		'ys_shopline_bnpl'                => 'ChaileaseBNPL',
	);

	foreach ( $gateways as $gateway => $method ) {
		$order = ys_status_sync_paid_order( $gateway );
		YSShoplinePayment::$test_api = new YS_Status_Sync_Api( ys_status_sync_dto( 'PROCESSING', $method ) );
		( new YSStatusManager() )->sync_payment_status( $order );
		YS_Assert::eq( "{$gateway}: late PROCESSING never downgrades completed", 'completed', $order->status );
		YS_Assert::eq( "{$gateway}: late PROCESSING keeps paid metadata", 'SUCCEEDED', $order->get_meta( YSOrderMeta::PAYMENT_STATUS ) );
	}

	foreach ( array( 'AUTHORIZED', 'PENDING', 'CREATED', 'CUSTOMER_ACTION', 'FAILED', 'CANCELLED', 'EXPIRED', 'UNKNOWN_VENDOR_STATE' ) as $remote_status ) {
		$order = ys_status_sync_paid_order();
		YSShoplinePayment::$test_api = new YS_Status_Sync_Api( ys_status_sync_dto( $remote_status ) );
		( new YSStatusManager() )->sync_payment_status( $order );
		YS_Assert::eq( "late {$remote_status} never changes paid WC status", 'completed', $order->status );
		YS_Assert::eq( "late {$remote_status} never replaces paid metadata", 'SUCCEEDED', $order->get_meta( YSOrderMeta::PAYMENT_STATUS ) );
	}

	$custom_paid = ys_status_sync_paid_order();
	$custom_paid->status = 'shipped';
	$custom_paid->paid = false;
	YSShoplinePayment::$test_api = new YS_Status_Sync_Api( ys_status_sync_dto( 'SUCCEEDED' ) );
	( new YSStatusManager() )->sync_payment_status( $custom_paid );
	YS_Assert::eq( 'date-paid custom status never reruns payment_complete', 0, $custom_paid->payment_complete_count );
	YS_Assert::eq( 'date-paid custom status is not downgraded by duplicate success', 'shipped', $custom_paid->status );

	$query_first = ys_status_sync_paid_order();
	$query_first->status = 'pending';
	$query_first->paid = false;
	$query_first->date_paid = null;
	$query_first->meta[ YSOrderMeta::PAYMENT_STATUS ] = 'PENDING';
	YSShoplinePayment::$test_api = new YS_Status_Sync_Api( ys_status_sync_dto( 'SUCCEEDED' ) );
	$manager = new YSStatusManager();
	$manager->sync_payment_status( $query_first );
	$manager->sync_payment_status( $query_first );
	YS_Assert::eq( 'query success followed by another query completes exactly once', 1, $query_first->payment_complete_count );
	YS_Assert::eq( 'duplicate paid query keeps processing status', 'processing', $query_first->status );

	$webhook_first = ys_status_sync_paid_order();
	$webhook_first->payment_complete_count = 1;
	YSShoplinePayment::$test_api = new YS_Status_Sync_Api( ys_status_sync_dto( 'CAPTURED' ) );
	( new YSStatusManager() )->sync_payment_status( $webhook_first );
	YS_Assert::eq( 'webhook-completed order followed by query stays completed once', 1, $webhook_first->payment_complete_count );
	YS_Assert::eq( 'webhook-completed order followed by query keeps completed status', 'completed', $webhook_first->status );

	$partial = ys_status_sync_paid_order();
	YSShoplinePayment::$test_api = new YS_Status_Sync_Api( ys_status_sync_dto( 'PARTIALLY_REFUND' ) );
	( new YSStatusManager() )->sync_payment_status( $partial );
	YS_Assert::eq( 'partial refund never downgrades completed WC status', 'completed', $partial->status );
	YS_Assert::eq( 'partial refund still updates SHOPLINE metadata', 'PARTIALLY_REFUND', $partial->get_meta( YSOrderMeta::PAYMENT_STATUS ) );

	$refunded = ys_status_sync_paid_order();
	YSShoplinePayment::$test_api = new YS_Status_Sync_Api( ys_status_sync_dto( 'REFUNDED' ) );
	( new YSStatusManager() )->sync_payment_status( $refunded );
	YS_Assert::eq( 'full refund remains an allowed post-payment transition', 'refunded', $refunded->status );
	YS_Assert::eq( 'full refund updates SHOPLINE metadata', 'REFUNDED', $refunded->get_meta( YSOrderMeta::PAYMENT_STATUS ) );
}
