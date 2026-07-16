<?php
/**
 * Contract tests for failed-order stock restoration scoping (v3.5.38).
 *
 * WooCommerce core 只在 cancelled／pending 掛回補；SHOPLINE 自身會把訂單轉 failed。
 * 契約：YSStatusManager 於 failed 轉換時呼叫 wc_maybe_increase_stock_levels()，且
 *   1. **僅限 SHOPLINE 金流訂單**（不得影響其他金流）；
 *   2. **僅限未實收訂單**（`date_paid` 為空）——已付款訂單轉 failed 不得釋放庫存
 *      （款在貨放＝超賣風險；覆核修正，不可用 is_paid()，轉 failed 後其已回 false）；
 *   3. 其他狀態轉換不得觸發。
 *
 * @package YangSheep\ShoplinePayment\Tests
 */

declare(strict_types=1);

use YangSheep\ShoplinePayment\Handlers\YSStatusManager;

final class YS_Failed_Restock_Test_Order extends WC_Order {
	private string $payment_method;
	private $date_paid;

	public function __construct( string $payment_method, $date_paid = null ) {
		$this->payment_method = $payment_method;
		$this->date_paid      = $date_paid;
	}

	public function get_payment_method(): string {
		return $this->payment_method;
	}

	public function get_date_paid() {
		return $this->date_paid;
	}
}

function ys_failed_restock_reset(): void {
	$GLOBALS['ys_test_stock_calls'] = array(
		'direct'   => 0,
		'maybe'    => 0,
		'increase' => 0,
	);
}

function ys_run_failed_restock_contract(): void {
	echo "== Failed-order restock: SHOPLINE-only + unpaid-only scoping ==\n";

	$manager = new YSStatusManager();

	// 未付款 SHOPLINE：pending→failed → 回補一次
	ys_failed_restock_reset();
	$manager->handle_order_status_change( 9301, 'pending', 'failed', new YS_Failed_Restock_Test_Order( 'ys_shopline_atm' ) );
	YS_Assert::eq( 'unpaid SHOPLINE pending→failed restocks once', 1, $GLOBALS['ys_test_stock_calls']['increase'] );

	// 未付款 SHOPLINE：on-hold→failed（webhook expired 路徑）→ 回補一次
	ys_failed_restock_reset();
	$manager->handle_order_status_change( 9302, 'on-hold', 'failed', new YS_Failed_Restock_Test_Order( 'ys_shopline_credit' ) );
	YS_Assert::eq( 'unpaid SHOPLINE on-hold→failed restocks once', 1, $GLOBALS['ys_test_stock_calls']['increase'] );

	// 未付款 SHOPLINE：自訂狀態→failed → 回補一次
	ys_failed_restock_reset();
	$manager->handle_order_status_change( 9303, 'wc-custom-hold', 'failed', new YS_Failed_Restock_Test_Order( 'ys_shopline_linepay' ) );
	YS_Assert::eq( 'unpaid SHOPLINE custom-status→failed restocks once', 1, $GLOBALS['ys_test_stock_calls']['increase'] );

	// 前綴相符的 legacy gateway（未付款）→ 回補一次（is_shopline_order 前綴分支）
	ys_failed_restock_reset();
	$manager->handle_order_status_change( 9304, 'pending', 'failed', new YS_Failed_Restock_Test_Order( 'ys_shopline_legacy_gw' ) );
	YS_Assert::eq( 'unpaid ys_shopline_ prefix gateway restocks once', 1, $GLOBALS['ys_test_stock_calls']['increase'] );

	// 🔴 已付款（date_paid 非空）：processing→failed → 不得回補（款在貨放＝超賣）
	ys_failed_restock_reset();
	$manager->handle_order_status_change( 9305, 'processing', 'failed', new YS_Failed_Restock_Test_Order( 'ys_shopline_credit', '2026-07-16 10:00:00' ) );
	YS_Assert::eq( 'PAID processing→failed never restocks', 0, $GLOBALS['ys_test_stock_calls']['increase'] );

	// 🔴 已付款（date_paid 非空）＋非常態舊狀態：pending→failed → 仍不得回補（以 date_paid 為準，非 old_status）
	ys_failed_restock_reset();
	$manager->handle_order_status_change( 9306, 'pending', 'failed', new YS_Failed_Restock_Test_Order( 'ys_shopline_atm', '2026-07-16 10:00:00' ) );
	YS_Assert::eq( 'PAID (date_paid set) never restocks regardless of old status', 0, $GLOBALS['ys_test_stock_calls']['increase'] );

	// 非 SHOPLINE 金流（cod）→ 不得觸發（不影響其他金流）
	ys_failed_restock_reset();
	$manager->handle_order_status_change( 9307, 'pending', 'failed', new YS_Failed_Restock_Test_Order( 'cod' ) );
	YS_Assert::eq( 'non-SHOPLINE failed never restocks', 0, $GLOBALS['ys_test_stock_calls']['increase'] );

	// 空 payment method → 不得觸發
	ys_failed_restock_reset();
	$manager->handle_order_status_change( 9308, 'pending', 'failed', new YS_Failed_Restock_Test_Order( '' ) );
	YS_Assert::eq( 'empty payment method never restocks', 0, $GLOBALS['ys_test_stock_calls']['increase'] );

	// SHOPLINE 訂單轉入其他狀態（pending→on-hold）→ 不得觸發
	ys_failed_restock_reset();
	$manager->handle_order_status_change( 9309, 'pending', 'on-hold', new YS_Failed_Restock_Test_Order( 'ys_shopline_atm' ) );
	YS_Assert::eq( 'non-failed transition never restocks', 0, $GLOBALS['ys_test_stock_calls']['increase'] );
}
