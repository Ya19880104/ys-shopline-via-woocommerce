<?php
/**
 * 契約測試：YSTradeStatus（狀態分類器 + select_representative_trade_id）。
 *
 * 鎖定 P0 雙扣防護的核心規則，防未來回歸。經 CODEX 覆核三～五輪反覆修正後定案：
 *   - 分類器 TERMINAL_SAFE / PAID_RISK / CUSTOMER_PENDING / IN_FLIGHT（不分大小寫）。
 *   - selector：忽略「欄位完整且 terminal-safe」；已收款/顧客處理中/在途皆 active；
 *     未知狀態與 malformed（非陣列/缺 tradeOrderId/缺 status）一律 fail-closed 回 ''；
 *     distinct tradeOrderId 去重後恰好一筆 active 才回，零筆或多筆回 ''。
 *
 * @package YangSheep\ShoplinePayment\Tests
 */

declare(strict_types=1);

use YangSheep\ShoplinePayment\Utils\YSTradeStatus;

/**
 * 執行 YSTradeStatus 契約案。
 *
 * @return void
 */
function ys_run_tradestatus_contract(): void {
	$S = static fn( array $d ): string => YSTradeStatus::select_representative_trade_id( $d );
	$D = static fn( string $id, string $st ): array => array( 'tradeOrderId' => $id, 'status' => $st );

	echo "== YSTradeStatus: 分類器 ==\n";
	foreach ( array( 'EXPIRED', 'FAILED', 'CANCELLED', 'CANCELED', 'REFUNDED', 'expired', 'failed' ) as $s ) {
		YS_Assert::is_true( "is_terminal_safe($s)", YSTradeStatus::is_terminal_safe( $s ) );
	}
	foreach ( array( 'SUCCEEDED', 'CREATED', 'PROCESSING', 'AUTHORIZED', 'PENDING', 'FOO', '' ) as $s ) {
		YS_Assert::eq( "!is_terminal_safe($s)", false, YSTradeStatus::is_terminal_safe( $s ) );
	}
	foreach ( array( 'SUCCEEDED', 'SUCCESS', 'CAPTURED', 'PARTIALLY_REFUND', 'PARTIALLY_REFUNDED', 'succeeded' ) as $s ) {
		YS_Assert::is_true( "is_paid($s)", YSTradeStatus::is_paid( $s ) );
	}
	foreach ( array( 'CREATED', 'PROCESSING', 'FAILED', 'FOO', '' ) as $s ) {
		YS_Assert::eq( "!is_paid($s)", false, YSTradeStatus::is_paid( $s ) );
	}
	foreach ( array( 'CREATED', 'CUSTOMER_ACTION', 'created' ) as $s ) {
		YS_Assert::is_true( "is_customer_pending($s)", YSTradeStatus::is_customer_pending( $s ) );
	}
	foreach ( array( 'PROCESSING', 'SUCCEEDED', 'FOO' ) as $s ) {
		YS_Assert::eq( "!is_customer_pending($s)", false, YSTradeStatus::is_customer_pending( $s ) );
	}
	foreach ( array( 'PROCESSING', 'AUTHORIZED', 'PENDING', 'processing' ) as $s ) {
		YS_Assert::is_true( "is_in_flight($s)", YSTradeStatus::is_in_flight( $s ) );
	}
	foreach ( array( 'CREATED', 'SUCCEEDED', 'FAILED', 'FOO', '' ) as $s ) {
		YS_Assert::eq( "!is_in_flight($s)", false, YSTradeStatus::is_in_flight( $s ) );
	}

	echo "== YSTradeStatus: select_representative_trade_id — 基本 ==\n";
	YS_Assert::eq( 'single paid → paid', 'T1', $S( array( $D( 'T1', 'SUCCEEDED' ) ) ) );
	YS_Assert::eq( 'terminal(complete)+paid → paid', 'Tp', $S( array( $D( 'Tf', 'FAILED' ), $D( 'Tp', 'SUCCEEDED' ) ) ) );
	YS_Assert::eq( 'paid 順序反轉仍選 paid', 'Tp', $S( array( $D( 'Tp', 'SUCCEEDED' ), $D( 'Tf', 'FAILED' ) ) ) );
	YS_Assert::eq( 'two distinct paid → ""', '', $S( array( $D( 'Ta', 'SUCCEEDED' ), $D( 'Tb', 'CAPTURED' ) ) ) );
	YS_Assert::eq( 'distinct dedup 同 id 兩筆 → 該 id', 'Tx', $S( array( $D( 'Tx', 'SUCCEEDED' ), $D( 'Tx', 'CAPTURED' ) ) ) );
	YS_Assert::eq( 'single customer-pending', 'Tc', $S( array( $D( 'Tc', 'CREATED' ) ) ) );
	YS_Assert::eq( 'two customer-pending → ""', '', $S( array( $D( 'Tc1', 'CREATED' ), $D( 'Tc2', 'CUSTOMER_ACTION' ) ) ) );
	YS_Assert::eq( 'all terminal → ""', '', $S( array( $D( 'Tf', 'FAILED' ), $D( 'Te', 'EXPIRED' ) ) ) );
	YS_Assert::eq( 'empty → ""', '', $S( array() ) );
	YS_Assert::eq( 'lowercase paid 仍選', 'Tlow', $S( array( $D( 'Tlow', 'succeeded' ) ) ) );

	echo "== YSTradeStatus: select — 在途（供狀態同步追蹤） ==\n";
	YS_Assert::eq( 'single PROCESSING', 'Tpr', $S( array( $D( 'Tpr', 'PROCESSING' ) ) ) );
	YS_Assert::eq( 'single AUTHORIZED', 'Tau', $S( array( $D( 'Tau', 'AUTHORIZED' ) ) ) );
	YS_Assert::eq( 'single PENDING', 'Tpe', $S( array( $D( 'Tpe', 'PENDING' ) ) ) );
	YS_Assert::eq( 'terminal(complete)+in-flight → in-flight', 'Tpr', $S( array( $D( 'Tf', 'EXPIRED' ), $D( 'Tpr', 'PROCESSING' ) ) ) );

	echo "== YSTradeStatus: select — 多筆 active / 未知 → \"\" ==\n";
	YS_Assert::eq( 'paid+in-flight → ""', '', $S( array( $D( 'Tp', 'SUCCEEDED' ), $D( 'Tpr', 'PROCESSING' ) ) ) );
	YS_Assert::eq( 'customer-pending+in-flight → ""', '', $S( array( $D( 'Tc', 'CUSTOMER_ACTION' ), $D( 'Tpr', 'PROCESSING' ) ) ) );
	YS_Assert::eq( 'paid+customer-pending → ""', '', $S( array( $D( 'Tp', 'SUCCEEDED' ), $D( 'Tc', 'CREATED' ) ) ) );
	YS_Assert::eq( 'unknown status 單筆 → ""', '', $S( array( $D( 'Tu', 'WHAT' ) ) ) );
	YS_Assert::eq( 'paid+unknown → ""', '', $S( array( $D( 'Tp', 'SUCCEEDED' ), $D( 'Tu', 'WHAT' ) ) ) );

	echo "== YSTradeStatus: select — malformed 一律 fail-closed ==\n";
	YS_Assert::eq( 'non-array element → ""', '', $S( array( 'garbage', $D( 'Tok', 'SUCCEEDED' ) ) ) );
	YS_Assert::eq( 'int element → ""', '', $S( array( 123, $D( 'Tok', 'SUCCEEDED' ) ) ) );
	YS_Assert::eq( 'missing tradeOrderId → ""', '', $S( array( array( 'status' => 'SUCCEEDED' ), $D( 'Tok', 'SUCCEEDED' ) ) ) );
	YS_Assert::eq( 'missing status → ""', '', $S( array( $D( 'Tp', 'SUCCEEDED' ), array( 'tradeOrderId' => 'Tno' ) ) ) );
	YS_Assert::eq( 'terminal 缺 tradeOrderId（仍 malformed）→ ""', '', $S( array( $D( 'Tp', 'SUCCEEDED' ), array( 'status' => 'FAILED' ) ) ) );
	YS_Assert::eq( 'CODEX-P0 反例 paid + in-flight 缺 tid → ""', '', $S( array( $D( 'paid-a', 'SUCCEEDED' ), array( 'status' => 'PROCESSING' ) ) ) );
	YS_Assert::eq( '單一 non-array → ""', '', $S( array( 'x' ) ) );
}
