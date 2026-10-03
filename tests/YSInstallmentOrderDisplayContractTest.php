<?php
/**
 * Order payment summary must display the locally stored installment selection.
 *
 * @package YangSheep\ShoplinePayment\Tests
 */

declare(strict_types=1);

use YangSheep\ShoplinePayment\Admin\YSOrderPaymentAdmin;
use YangSheep\ShoplinePayment\Utils\YSOrderMeta;

final class YS_Installment_Display_Test_Order extends WC_Order {

	public array $meta = array();
	public string $gateway = 'ys_shopline_credit_installment';
	public int $save_count = 0;

	public function get_meta( string $key ) {
		return $this->meta[ $key ] ?? '';
	}

	public function get_payment_method(): string {
		return $this->gateway;
	}

	public function get_payment_method_title(): string {
		return 'SHOPLINE payment';
	}

	public function get_status(): string {
		return 'processing';
	}

	public function save(): void {
		++$this->save_count;
	}
}

function ys_run_installment_order_display_contract(): void {
	echo "== Installment order display: persisted selection and gateway isolation ==\n";

	$admin  = new YSOrderPaymentAdmin();
	$method = new ReflectionMethod( $admin, 'get_order_payment_rows' );
	$order  = new YS_Installment_Display_Test_Order();
	$order->meta = array(
		YSOrderMeta::PAYMENT_STATUS => 'SUCCEEDED',
		YSOrderMeta::TRADE_ORDER_ID => 'test-installment-trade',
	);
	$original_rows = $method->invoke( $admin, $order );
	YS_Assert::eq( 'summary without a selection retains the three original rows', array( '付款方式', '付款狀態', '付款編號' ), array_keys( $original_rows ) );

	foreach ( array( 3, '6', 12, '24' ) as $count ) {
		$order->meta[ YSOrderMeta::INSTALLMENT ] = $count;
		$rows = $method->invoke( $admin, $order );
		YS_Assert::eq( 'credit installment displays ' . $count . ' terms', $count . ' 期', $rows['分期期數'] ?? null );
		unset( $rows['分期期數'] );
		YS_Assert::eq( 'installment preserves method, status and transaction ID', $original_rows, $rows );
	}

	$order->gateway = 'ys_shopline_bnpl';
	$order->meta[ YSOrderMeta::BNPL_INSTALLMENT ] = '36';
	YS_Assert::eq( 'BNPL displays its own 36-term selection rather than stale credit terms', '36 期', $method->invoke( $admin, $order )['分期期數'] ?? null );
	unset( $order->meta[ YSOrderMeta::BNPL_INSTALLMENT ] );
	YS_Assert::eq( 'BNPL never falls back to unrelated credit terms', false, isset( $method->invoke( $admin, $order )['分期期數'] ) );

	$order->gateway = 'ys_shopline_credit_installment';
	foreach ( array( '', 0, '0', -3, '-3', 3.5, '3.5', true, array( 6 ), '6<script>', '99999999999999999999999999999' ) as $invalid ) {
		$order->meta[ YSOrderMeta::INSTALLMENT ] = $invalid;
		YS_Assert::eq( 'invalid or one-time installment selection is not displayed: ' . json_encode( $invalid ), false, isset( $method->invoke( $admin, $order )['分期期數'] ) );
	}

	$order->meta[ YSOrderMeta::INSTALLMENT ] = 12;
	$order->meta[ YSOrderMeta::BNPL_INSTALLMENT ] = 36;
	foreach ( array( 'ys_shopline_credit', 'ys_shopline_atm', 'ys_shopline_linepay', 'ys_shopline_applepay', 'ys_shopline_jkopay', 'ys_shopline_credit_subscription', 'bacs' ) as $gateway ) {
		$order->gateway = $gateway;
		YS_Assert::eq( $gateway . ' never displays stale installment metadata', false, isset( $method->invoke( $admin, $order )['分期期數'] ) );
	}

	$order->gateway = 'ys_shopline_credit_installment';
	$before = $order->meta;
	$render = new ReflectionMethod( $admin, 'render_order_payment_overview' );
	ob_start();
	$render->invoke( $admin, $order, false );
	$html = (string) ob_get_clean();
	YS_Assert::is_true( 'actual admin table renders the installment label', false !== strpos( $html, '<th>分期期數</th>' ) );
	YS_Assert::is_true( 'actual admin table renders the selected number of terms', false !== strpos( $html, '12 期' ) );
	YS_Assert::eq( 'display does not mutate persisted payment data', $before, $order->meta );
	YS_Assert::eq( 'display never saves the order', 0, $order->save_count );
}
