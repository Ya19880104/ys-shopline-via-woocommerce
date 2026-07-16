<?php
/**
 * Contract tests for installment SDK config（v3.5.38 分期 bindCard mismatch 修正）.
 *
 * 官方定位：信用卡分期＝一般付款，不提供新卡儲存；會員既有卡列表只需 customerToken，
 * 毋須 paymentInstrument.bindCard。SDK 啟用 bindCard＋API Regular 會觸發
 * 「User authorization verification failed」（客戶站六筆異常訂單根因）。
 *
 * 契約：**分期 get_sdk_config() 產出永不包含 paymentInstrument 與 forceSaveCard**——
 * 含 parent 會主動設定它們的 bind-only 情境；customerToken 有無皆然。
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
	echo "== Installment SDK config: no bindCard, customerToken only ==\n";

	// 會員（有 customerToken）：token 保留、paymentInstrument／forceSaveCard 絕不出現
	$GLOBALS['ys_test_user_id'] = 77;
	$config = ( new YS_Installment_Test_Gateway( 'tok-123' ) )->get_sdk_config();
	YS_Assert::eq( 'member keeps customerToken', 'tok-123', $config['customerToken'] ?? null );
	YS_Assert::eq( 'member config has NO paymentInstrument', false, array_key_exists( 'paymentInstrument', $config ) );
	YS_Assert::eq( 'member config has NO forceSaveCard', false, array_key_exists( 'forceSaveCard', $config ) );

	// 訪客：無 token、亦無 paymentInstrument
	$GLOBALS['ys_test_user_id'] = 0;
	$config = ( new YS_Installment_Test_Gateway( false ) )->get_sdk_config();
	YS_Assert::eq( 'guest config has NO customerToken', false, array_key_exists( 'customerToken', $config ) );
	YS_Assert::eq( 'guest config has NO paymentInstrument', false, array_key_exists( 'paymentInstrument', $config ) );

	// bind-only 情境（parent 會主動設 paymentInstrument.bindCard＋forceSaveCard）→ 分期覆寫必須剝除
	$GLOBALS['ys_test_user_id']       = 77;
	$_POST['is_add_payment_method']   = '1';
	$config = ( new YS_Installment_Test_Gateway( 'tok-123' ) )->get_sdk_config();
	unset( $_POST['is_add_payment_method'] );
	YS_Assert::eq( 'bind-only context still strips paymentInstrument', false, array_key_exists( 'paymentInstrument', $config ) );
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
