<?php
/**
 * Contract tests for the ATM nextAction response envelope (v3.6.9).
 *
 * 客訴：ATM 在特定情況下拿不到虛擬帳號。SHOPLINE 回覆「你們沒有做 payment.pay()」。
 *
 * 根因之一（必定重現，與瀏覽器中斷無關）：order-pay 的 ajax_pay_for_order() 以
 * remote_outcome 三態分流，**缺值一律 fail-closed**。YSGatewayBase::handle_next_action()
 * 一直都回傳 remote_outcome 與 failureUrl，只有 YSVirtualAccount 的覆寫版沒有——
 * 於是顧客從訂單付款頁重試 ATM 時，交易已在遠端建立，前端卻收到 failure，
 * processNextAction() 不執行，payment.pay() 從不送出，號碼從不產生。
 *
 * 本檔以「ATM 必須與 base gateway 同構」為斷言，守的是既有機制的一致性。
 *
 * 依賴 YSStockReductionContractTest.php 的測試替身（run.php 已先行 require）。
 *
 * @package YangSheep\ShoplinePayment\Tests
 */

declare(strict_types=1);

/**
 * Run ATM nextAction response envelope contract cases.
 *
 * @return void
 */
function ys_run_virtual_account_response_contract(): void {
	echo "== ATM nextAction response envelope: parity with the base gateway ==\n";

	$credit_result = ( new YS_Stock_Test_Gateway() )->run_next_action( new YS_Stock_Test_Order() );
	$atm_result    = ( new YS_Stock_Test_Virtual_Account() )->run_next_action( new YS_Stock_Test_Order() );

	// base gateway 的既有行為（對照組——若這兩條紅了，代表 base 被改壞，不是 ATM 的問題）
	YS_Assert::eq( 'base gateway declares an accepted outcome', 'accepted', $credit_result['remote_outcome'] ?? '' );
	YS_Assert::is_true( 'base gateway hands the frontend a retry URL', ! empty( $credit_result['failureUrl'] ) );

	// 🔴 ATM 必須同構。缺 remote_outcome ＝ order-pay 重試被 fail-closed 判成失敗，
	// payment.pay() 從不送出，虛擬帳號從不產生。
	YS_Assert::eq( 'ATM declares an accepted outcome so order-pay does not fail closed', 'accepted', $atm_result['remote_outcome'] ?? '' );
	YS_Assert::is_true( 'ATM hands the frontend a retry URL', ! empty( $atm_result['failureUrl'] ) );
	YS_Assert::eq( 'ATM retry URL points at the order-pay page', 'https://example.test/order-pay/9201', $atm_result['failureUrl'] ?? '' );

	// 既有回傳內容不得因此變動
	YS_Assert::eq( 'ATM still returns success', 'success', $atm_result['result'] ?? '' );
	YS_Assert::eq( 'ATM still hands nextAction to the SDK', 'Confirm', $atm_result['nextAction']['type'] ?? '' );
	YS_Assert::eq( 'ATM still returns the thank-you URL', 'https://example.test/thank-you', $atm_result['returnUrl'] ?? '' );
	YS_Assert::eq( 'ATM still returns the order ID', 9201, $atm_result['orderId'] ?? 0 );
}
