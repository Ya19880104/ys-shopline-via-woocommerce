<?php
/**
 * Contract tests for the credit-card installment count allowlist (v3.6.11).
 *
 * 店家在閘道設定勾選的分期期數，是唯一可以送往 SHOPLINE 的期數。前端送上來的
 * `ys_shopline_installment` 只是顧客的選擇，伺服器端必須對照店家設定與分期最低金額：
 * 不在清單內的請求要在建立交易前擋下，也不得出現在付款請求裡。
 *
 * 這些測試執行真實的 YSGatewayBase::process_payment() 與
 * YSCreditInstallment::prepare_payment_data()。
 *
 * @package YangSheep\ShoplinePayment\Tests
 */

declare(strict_types=1);

use YangSheep\ShoplinePayment\Gateways\YSCreditInstallment;
use YangSheep\ShoplinePayment\Utils\YSOrderMeta;

final class YS_Installment_Allowlist_Order extends WC_Order {
	public array $meta = array();
	public array $notes = array();
	public string $status = 'pending';
	public bool $paid = false;

	public function __construct( private float $total ) {}

	public function get_id(): int {
		return 9501;
	}

	public function get_status(): string {
		return $this->status;
	}

	public function get_payment_method(): string {
		return 'ys_shopline_credit_installment';
	}

	public function get_user_id(): int {
		return 0;
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

	public function is_paid(): bool {
		return $this->paid;
	}

	public function get_date_paid() {
		return null;
	}

	public function payment_complete( string $transaction_id = '' ): void {
		$this->paid   = true;
		$this->status = 'processing';
	}

	public function get_checkout_payment_url(): string {
		return 'https://example.test/order-pay/9501';
	}

	public function get_total(): float {
		return $this->total;
	}

	public function get_currency(): string {
		return 'TWD';
	}

	public function get_shipping_method(): string {
		return 'Test shipping';
	}

	public function get_shipping_total(): float {
		return 0.0;
	}

	public function save(): void {}
}

final class YS_Installment_Allowlist_Api {
	public int $create_calls = 0;
	public array $last_payload = array();

	public function create_payment_trade( array $data, string $idempotent_key ) {
		$this->create_calls++;
		$this->last_payload = $data;
		return array(
			'tradeOrderId' => 'allowlist-1',
			'status'       => 'CREATED',
		);
	}

	public function get_payment_trade( string $trade_order_id ) {
		return array();
	}

	public function cancel_payment_by_ids( string $trade_order_id, string $reference_order_id ) {
		return true;
	}
}

final class YS_Installment_Allowlist_Gateway extends YSCreditInstallment {

	public function __construct( array $options ) {
		$this->id           = 'ys_shopline_credit_installment';
		$this->testmode     = true;
		$this->test_options = $options;
		$this->api          = new YS_Installment_Allowlist_Api();
	}

	public function api_stub(): YS_Installment_Allowlist_Api {
		return $this->api;
	}

	public function expose_prepare_payment_data( $order, string $pay_session ): array {
		return $this->prepare_payment_data( $order, $pay_session );
	}

	public function get_return_url( $order = null ) {
		return 'https://example.test/thank-you';
	}

	protected function order_contains_subscription( $order ) {
		return false;
	}

	protected function get_shopline_customer_id( $user_id ) {
		return false;
	}

	protected function build_personal_info( $order, $type = 'billing' ) {
		return array( 'firstName' => 'Test' );
	}

	protected function build_address( $order, $type = 'billing' ) {
		return array( 'city' => 'Taipei' );
	}

	protected function build_products( $order ) {
		return array(
			array(
				'name'   => 'Contract product',
				'amount' => array( 'value' => 500000, 'currency' => 'TWD' ),
			),
		);
	}

	protected function get_client_ip() {
		return '127.0.0.1';
	}

	protected function build_client_info( $client_ip ) {
		return array( 'ip' => $client_ip );
	}

	protected function generate_reference_order_id( $order ) {
		return '9501_1';
	}

	protected function get_shopline_language() {
		return 'zh-TW';
	}
}

/**
 * Run one request through the real process_payment().
 *
 * @param array       $options Gateway options.
 * @param float       $total   Order total.
 * @param mixed       $posted  Posted installment count; null = field absent.
 * @return array{0:array,1:YS_Installment_Allowlist_Order,2:YS_Installment_Allowlist_Gateway}
 */
function ys_installment_allowlist_process( array $options, float $total, $posted ): array {
	$previous_session = $_POST['ys_shopline_pay_session'] ?? null;
	$previous_user    = $GLOBALS['ys_test_user_id'] ?? 0;

	$order = new YS_Installment_Allowlist_Order( $total );
	$GLOBALS['ys_test_order']         = $order;
	$GLOBALS['ys_test_notices']       = array();
	$GLOBALS['ys_test_logs']          = array();
	$GLOBALS['ys_test_user_id']       = 0;
	$_POST['ys_shopline_pay_session'] = '{"sessionId":"s-allowlist"}';
	unset( $_POST['ys_shopline_installment'] );
	if ( null !== $posted ) {
		$_POST['ys_shopline_installment'] = $posted;
	}

	$gateway = new YS_Installment_Allowlist_Gateway( $options );
	$result  = $gateway->process_payment( $order->get_id() );

	unset( $_POST['ys_shopline_installment'] );
	if ( null === $previous_session ) {
		unset( $_POST['ys_shopline_pay_session'] );
	} else {
		$_POST['ys_shopline_pay_session'] = $previous_session;
	}
	$GLOBALS['ys_test_user_id'] = $previous_user;

	return array( $result, $order, $gateway );
}

/**
 * Build the payment payload directly, bypassing process_payment().
 *
 * @param array       $options Gateway options.
 * @param float       $total   Order total.
 * @param string|null $posted  Posted installment count; null = field absent.
 * @return array{0:array,1:YS_Installment_Allowlist_Order}
 */
function ys_installment_allowlist_prepare( array $options, float $total, ?string $posted ): array {
	$previous_user              = $GLOBALS['ys_test_user_id'] ?? 0;
	$GLOBALS['ys_test_user_id'] = 0;
	unset( $_POST['ys_shopline_installment'] );
	if ( null !== $posted ) {
		$_POST['ys_shopline_installment'] = $posted;
	}

	$order   = new YS_Installment_Allowlist_Order( $total );
	$gateway = new YS_Installment_Allowlist_Gateway( $options );
	$data    = $gateway->expose_prepare_payment_data( $order, 'session-allowlist' );

	unset( $_POST['ys_shopline_installment'] );
	$GLOBALS['ys_test_user_id'] = $previous_user;

	return array( $data, $order );
}

function ys_run_installment_count_allowlist_contract(): void {
	echo "== Installment count: only merchant-enabled plans reach SHOPLINE ==\n";

	$options = array(
		'installments'           => array( '3', '6' ),
		'min_installment_amount' => 3000,
	);
	$error_notices = static function (): array {
		return array_values(
			array_filter(
				$GLOBALS['ys_test_notices'],
				static fn( array $notice ): bool => 'error' === $notice[0]
			)
		);
	};

	// 店家有開的期數：照常送出。
	list( $result, $order, $gateway ) = ys_installment_allowlist_process( $options, 5000.0, '3' );
	YS_Assert::eq( 'enabled count creates one trade', 1, $gateway->api_stub()->create_calls );
	YS_Assert::eq( 'enabled count is accepted', 'accepted', $result['remote_outcome'] ?? '' );
	YS_Assert::eq( 'enabled count is sent to SHOPLINE', '3', $gateway->api_stub()->last_payload['confirm']['paymentMethodOptions']['installments']['count'] ?? null );
	YS_Assert::eq( 'enabled count is stored on the order', 3, $order->get_meta( YSOrderMeta::INSTALLMENT ) );

	// 店家沒開的期數：建立交易前擋下。
	list( $result, $order, $gateway ) = ys_installment_allowlist_process( $options, 5000.0, '24' );
	YS_Assert::eq( 'count outside the merchant list creates no trade', 0, $gateway->api_stub()->create_calls );
	YS_Assert::eq( 'count outside the merchant list fails the request', 'failure', $result['result'] ?? '' );
	YS_Assert::eq( 'count outside the merchant list is a retryable rejection', 'rejected', $result['remote_outcome'] ?? '' );
	YS_Assert::eq( 'count outside the merchant list shows one error notice', 1, count( $error_notices() ) );
	YS_Assert::eq( 'count outside the merchant list is not stored on the order', '', $order->get_meta( YSOrderMeta::INSTALLMENT ) );
	YS_Assert::eq( 'local rejection leaves the payment status untouched', '', $order->get_meta( YSOrderMeta::PAYMENT_STATUS ) );

	// 金額未達分期最低金額：期數沒有提供給顧客，送上來也不接受。
	list( $result, , $gateway ) = ys_installment_allowlist_process( $options, 2999.0, '3' );
	YS_Assert::eq( 'below the minimum amount creates no trade', 0, $gateway->api_stub()->create_calls );
	YS_Assert::eq( 'below the minimum amount is rejected', 'rejected', $result['remote_outcome'] ?? '' );

	// 剛好達到最低金額：與 get_sdk_config() 的「>=」一致。
	list( $result, , $gateway ) = ys_installment_allowlist_process( $options, 3000.0, '6' );
	YS_Assert::eq( 'exactly the minimum amount creates one trade', 1, $gateway->api_stub()->create_calls );
	YS_Assert::eq( 'exactly the minimum amount sends the count', '6', $gateway->api_stub()->last_payload['confirm']['paymentMethodOptions']['installments']['count'] ?? null );

	// 沒有選分期（一次付清）：不受影響。
	list( $result, $order, $gateway ) = ys_installment_allowlist_process( $options, 5000.0, null );
	YS_Assert::eq( 'no installment field creates one trade', 1, $gateway->api_stub()->create_calls );
	YS_Assert::eq( 'no installment field sends no installment option', false, isset( $gateway->api_stub()->last_payload['confirm']['paymentMethodOptions'] ) );
	YS_Assert::eq( 'no installment field shows no error notice', 0, count( $error_notices() ) );

	list( $result, , $gateway ) = ys_installment_allowlist_process(
		array(
			'installments'           => array( '0', '3' ),
			'min_installment_amount' => 3000,
		),
		5000.0,
		'0'
	);
	YS_Assert::eq( 'pay-in-full (0) creates one trade', 1, $gateway->api_stub()->create_calls );
	YS_Assert::eq( 'pay-in-full (0) sends no installment option', false, isset( $gateway->api_stub()->last_payload['confirm']['paymentMethodOptions'] ) );

	// 店家把分期選項清空：任何期數都不接受。
	list( $result, , $gateway ) = ys_installment_allowlist_process(
		array(
			'installments'           => array(),
			'min_installment_amount' => 3000,
		),
		5000.0,
		'3'
	);
	YS_Assert::eq( 'empty merchant list creates no trade', 0, $gateway->api_stub()->create_calls );

	// Invalid values must not be coerced into an enabled term or a full charge.
	foreach ( array( '6abc', '6.5', '-6', 'invalid', '', array(), array( '6' ) ) as $posted ) {
		$label = json_encode( $posted );
		list( $result, $order, $gateway ) = ys_installment_allowlist_process( $options, 5000.0, $posted );
		YS_Assert::eq( 'malformed installment ' . $label . ' creates no trade', 0, $gateway->api_stub()->create_calls );
		YS_Assert::eq( 'malformed installment ' . $label . ' is rejected', 'rejected', $result['remote_outcome'] ?? '' );
		YS_Assert::eq( 'malformed installment ' . $label . ' preserves payment state', '', $order->get_meta( YSOrderMeta::PAYMENT_STATUS ) );
	}

	// 第二道防線：即使繞過 process_payment()，付款請求也不得帶未啟用的期數。
	list( $data, $order ) = ys_installment_allowlist_prepare( $options, 5000.0, '24' );
	YS_Assert::eq( 'payload never carries a count outside the merchant list', false, isset( $data['confirm']['paymentMethodOptions'] ) );
	YS_Assert::eq( 'payload builder does not store a count outside the merchant list', '', $order->get_meta( YSOrderMeta::INSTALLMENT ) );

	list( $data ) = ys_installment_allowlist_prepare( $options, 5000.0, '6' );
	YS_Assert::eq( 'payload carries an enabled count', '6', $data['confirm']['paymentMethodOptions']['installments']['count'] ?? null );
}
