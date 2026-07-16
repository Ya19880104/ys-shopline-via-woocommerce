<?php
/**
 * Contract tests for order-level stock reduction bookkeeping.
 *
 * @package YangSheep\ShoplinePayment\Tests
 */

declare(strict_types=1);

use YangSheep\ShoplinePayment\Gateways\YSGatewayBase;
use YangSheep\ShoplinePayment\Gateways\YSVirtualAccount;

final class YS_Stock_Test_Order {
	public array $meta = array();
	public array $notes = array();
	public string $status = 'pending';

	public function get_id(): int {
		return 9201;
	}

	public function update_meta_data( string $key, $value ): void {
		$this->meta[ $key ] = $value;
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

	public function save(): void {}

	public function get_checkout_payment_url(): string {
		return 'https://example.test/order-pay/9201';
	}
}

final class YS_Stock_Test_Gateway extends YSGatewayBase {
	public function __construct() {
		$this->id = 'ys_shopline_credit';
	}

	public function get_payment_method() {
		return 'CreditCard';
	}

	public function get_return_url( $order = null ) {
		return 'https://example.test/thank-you';
	}

	public function run_next_action( $order ): array {
		return $this->handle_next_action(
			$order,
			array(
				'tradeOrderId' => 'trade-credit-1',
				'nextAction'   => array( 'type' => 'Confirm' ),
			)
		);
	}
}

final class YS_Stock_Test_Virtual_Account extends YSVirtualAccount {
	public function __construct() {
		$this->id = 'ys_shopline_atm';
	}

	public function get_return_url( $order = null ) {
		return 'https://example.test/thank-you';
	}

	public function run_next_action( $order ): array {
		return $this->handle_next_action(
			$order,
			array(
				'tradeOrderId' => 'trade-atm-1',
				'nextAction'   => array( 'type' => 'Confirm' ),
			)
		);
	}
}

function ys_stock_reset_calls(): void {
	$GLOBALS['ys_test_stock_calls'] = array(
		'direct' => 0,
		'maybe'  => 0,
	);
}

function ys_run_stock_reduction_contract(): void {
	echo "== Stock reduction: order-level bookkeeping contract ==\n";

	ys_stock_reset_calls();
	$credit = new YS_Stock_Test_Gateway();
	$credit->run_next_action( new YS_Stock_Test_Order() );
	YS_Assert::eq( 'credit nextAction uses wc_maybe_reduce_stock_levels', 1, $GLOBALS['ys_test_stock_calls']['maybe'] );
	YS_Assert::eq( 'credit nextAction never calls wc_reduce_stock_levels directly', 0, $GLOBALS['ys_test_stock_calls']['direct'] );

	ys_stock_reset_calls();
	$atm = new YS_Stock_Test_Virtual_Account();
	$atm->run_next_action( new YS_Stock_Test_Order() );
	YS_Assert::eq( 'ATM nextAction uses wc_maybe_reduce_stock_levels', 1, $GLOBALS['ys_test_stock_calls']['maybe'] );
	YS_Assert::eq( 'ATM nextAction never calls wc_reduce_stock_levels directly', 0, $GLOBALS['ys_test_stock_calls']['direct'] );
}
