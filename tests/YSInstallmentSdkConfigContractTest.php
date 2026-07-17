<?php
/**
 * Contract tests for installment SDK config（v3.5.38 分期 bindCard mismatch 修正）.
 *
 * 分期需要同一個 CreditCard SDK 同時承載一般付款、綁卡付款與快捷付款。
 * paymentInstrument.bindCard 顯示既有卡與可選的儲存開關；前端必須在
 * createPayment() 改寫 DOM 前保存使用者選擇，後端再依 mode 對應三態。
 *
 * 契約：會員分期啟用可選 bindCard，儲存開關預設關閉；訪客不啟用。
 *
 * @package YangSheep\ShoplinePayment\Tests
 */

declare(strict_types=1);

use YangSheep\ShoplinePayment\Gateways\YSCreditInstallment;

final class YS_Installment_Test_Gateway extends YSCreditInstallment {
	private $stub_token;

	public function __construct( $stub_token = false ) {
		$this->id         = 'ys_shopline_credit_installment';
		$this->testmode   = true;
		$this->stub_token = $stub_token;
	}

	protected function get_customer_token( $user_id ) {
		return $this->stub_token;
	}
}

function ys_run_installment_sdk_contract(): void {
	echo "== Installment SDK config: optional bindCard with saved cards ==\n";

	// 會員（有 customerToken）：顯示既有卡與可選儲存，預設不儲存。
	$GLOBALS['ys_test_user_id'] = 77;
	$config = ( new YS_Installment_Test_Gateway( 'tok-123' ) )->get_sdk_config();
	YS_Assert::eq( 'member keeps customerToken', 'tok-123', $config['customerToken'] ?? null );
	YS_Assert::eq( 'member bindCard enabled', true, $config['paymentInstrument']['bindCard']['enable'] ?? false );
	YS_Assert::eq( 'member bindCard switch visible', true, $config['paymentInstrument']['bindCard']['protocol']['switchVisible'] ?? false );
	YS_Assert::eq( 'member bindCard defaults off', false, $config['paymentInstrument']['bindCard']['protocol']['defaultSwitchStatus'] ?? null );
	YS_Assert::eq( 'member config has NO forceSaveCard', false, array_key_exists( 'forceSaveCard', $config ) );

	// 訪客：無 token、亦無 paymentInstrument
	$GLOBALS['ys_test_user_id'] = 0;
	$config = ( new YS_Installment_Test_Gateway( false ) )->get_sdk_config();
	YS_Assert::eq( 'guest config has NO customerToken', false, array_key_exists( 'customerToken', $config ) );
	YS_Assert::eq( 'guest config has NO paymentInstrument', false, array_key_exists( 'paymentInstrument', $config ) );

	// 分期不出現在新增付款方式頁；若被直接呼叫，仍不得留下強制綁卡設定。
	$GLOBALS['ys_test_user_id']       = 77;
	$_POST['is_add_payment_method']   = '1';
	$config = ( new YS_Installment_Test_Gateway( 'tok-123' ) )->get_sdk_config();
	unset( $_POST['is_add_payment_method'] );
	YS_Assert::eq( 'bind-only context strips paymentInstrument', false, array_key_exists( 'paymentInstrument', $config ) );
	YS_Assert::eq( 'bind-only context still strips forceSaveCard', false, array_key_exists( 'forceSaveCard', $config ) );

	// installmentCounts 行為不受影響（設定有值且金額達門檻時輸出）
	$GLOBALS['ys_test_user_id'] = 77;
	$gw = new YS_Installment_Test_Gateway( 'tok-123' );
	$gw->test_options = array(
		'installments'           => array( '3', '6' ),
		'min_installment_amount' => 3000,
	);
	$config = $gw->get_sdk_config();
	YS_Assert::eq( 'installmentCounts preserved', array( '3', '6' ), $config['installmentCounts'] ?? null );

	$GLOBALS['ys_test_user_id'] = 0;
}
