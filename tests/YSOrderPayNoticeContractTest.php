<?php

declare(strict_types=1);

use YangSheep\ShoplinePayment\Frontend\YSOrderDisplay;
use YangSheep\ShoplinePayment\Utils\YSOrderMeta;

final class YS_Order_Pay_Notice_Order extends WC_Order {
	public function __construct(
		private int $id,
		private string $status,
		private array $meta
	) {}

	public function get_id(): int {
		return $this->id;
	}

	public function get_payment_method(): string {
		return 'ys_shopline_credit';
	}

	public function get_status(): string {
		return $this->status;
	}

	public function get_meta( string $key ) {
		return $this->meta[ $key ] ?? '';
	}
}

function ys_run_order_pay_notice_contract(): void {
	echo "== Order-pay: terminal confirmation notice ==\n";
	$GLOBALS['ys_test_actions'] = array();
	$display = YSOrderDisplay::instance();

	$hooks = array_values(
		array_filter(
			$GLOBALS['ys_test_actions'],
			static fn( array $action ): bool => 'woocommerce_pay_order_before_payment' === $action['hook']
		)
	);
	YS_Assert::eq( 'order-pay notice uses WooCommerce standard pre-payment hook', 1, count( $hooks ) );
	YS_Assert::is_true( 'order-pay notice handler is public and callable', method_exists( $display, 'display_order_pay_confirmation_notice' ) );

	if ( ! method_exists( $display, 'display_order_pay_confirmation_notice' ) ) {
		return;
	}

	$reference = 'contract-terminal-1';
	$GLOBALS['ys_test_query_vars']['order-pay'] = 9901;
	$GLOBALS['ys_test_order'] = new YS_Order_Pay_Notice_Order(
		9901,
		'pending',
		array(
			YSOrderMeta::REFERENCE_ORDER_ID   => $reference,
			YSOrderMeta::CONFIRMATION_HISTORY => array(
				array(
					'reference'     => $reference,
					'remote_status' => 'FAILED',
				),
			),
		)
	);

	ob_start();
	$display->display_order_pay_confirmation_notice();
	$output = (string) ob_get_clean();
	YS_Assert::is_true( 'terminal order-pay renders neutral title', str_contains( $output, '付款確認未完成' ) );
	YS_Assert::is_true( 'terminal order-pay renders bank-hold neutral copy', str_contains( $output, '銀行端仍顯示保留款' ) );
	YS_Assert::is_true( 'order-pay notice does not render a duplicate payment button', ! str_contains( $output, 'ys-shopline-pay-button' ) );

	$GLOBALS['ys_test_order'] = new YS_Order_Pay_Notice_Order( 9901, 'pending', array() );
	ob_start();
	$display->display_order_pay_confirmation_notice();
	$empty_output = (string) ob_get_clean();
	YS_Assert::eq( 'ordinary pending order has no confirmation-terminal notice', '', trim( $empty_output ) );
}
