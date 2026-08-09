<?php
/**
 * Contract tests for WCS activation after asynchronous payment confirmation.
 *
 * @package YangSheep\ShoplinePayment\Tests
 */

declare(strict_types=1);

use YangSheep\ShoplinePayment\Handlers\YSPaymentConfirmation;

final class YS_Subscription_Activation_Test_Order extends WC_Order {
	public bool $paid = false;
	public $date_paid = null;

	public function get_id(): int {
		return 9910;
	}

	public function is_paid(): bool {
		return $this->paid;
	}

	public function get_date_paid() {
		return $this->date_paid;
	}
}

function ys_run_subscription_activation_contract(): void {
	echo "== WooCommerce Subscriptions: confirming parent activation ==\n";

	if ( ! method_exists( YSPaymentConfirmation::class, 'treat_confirming_transition_as_completed' ) ) {
		YS_Assert::is_true( 'WCS confirmation transition callback exists', false );
		return;
	}

	$order = new YS_Subscription_Activation_Test_Order();

	YS_Assert::eq(
		'native WCS completion decision is never reversed',
		true,
		YSPaymentConfirmation::treat_confirming_transition_as_completed(
			true,
			'processing',
			'pending',
			array(),
			$order
		)
	);

	$order->paid      = true;
	$order->date_paid = '2026-08-09 10:00:00';
	YS_Assert::eq(
		'paid confirming parent transition activates WCS',
		true,
		YSPaymentConfirmation::treat_confirming_transition_as_completed(
			false,
			'processing',
			YSPaymentConfirmation::STATUS_KEY,
			array(),
			$order
		)
	);

	$order->paid      = false;
	$order->date_paid = null;
	YS_Assert::eq(
		'unpaid confirming transition is not promoted',
		false,
		YSPaymentConfirmation::treat_confirming_transition_as_completed(
			false,
			'processing',
			YSPaymentConfirmation::STATUS_KEY,
			array(),
			$order
		)
	);

	$order->paid      = true;
	$order->date_paid = '2026-08-09 10:00:00';
	YS_Assert::eq(
		'non-confirming source status is not promoted',
		false,
		YSPaymentConfirmation::treat_confirming_transition_as_completed(
			false,
			'processing',
			'pending',
			array(),
			$order
		)
	);
	YS_Assert::eq(
		'non-paid destination status is not promoted',
		false,
		YSPaymentConfirmation::treat_confirming_transition_as_completed(
			false,
			'failed',
			YSPaymentConfirmation::STATUS_KEY,
			array(),
			$order
		)
	);

	$previous_filters = $GLOBALS['ys_test_filters']['woocommerce_payment_complete_order_status'] ?? array();
	$GLOBALS['ys_test_filters']['woocommerce_payment_complete_order_status'] = array(
		static fn( $status ) => 'paid-custom',
	);
	YS_Assert::eq(
		'custom payment-complete status is recognized',
		true,
		YSPaymentConfirmation::treat_confirming_transition_as_completed(
			false,
			'paid-custom',
			YSPaymentConfirmation::STATUS_KEY,
			array(),
			$order
		)
	);
	$GLOBALS['ys_test_filters']['woocommerce_payment_complete_order_status'] = $previous_filters;
}
