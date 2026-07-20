<?php
/**
 * Contract tests for late non-paid webhooks after an order has a paid date.
 *
 * @package YangSheep\ShoplinePayment\Tests
 */

declare(strict_types=1);

use YangSheep\ShoplinePayment\Handlers\YSWebhookHandler;
use YangSheep\ShoplinePayment\Handlers\YSPaymentConfirmation;
use YangSheep\ShoplinePayment\Utils\YSOrderMeta;

/** Invoke one private webhook event handler. */
function ys_invoke_webhook_handler( YSWebhookHandler $handler, string $method, array $payload ): void {
	$reflection = new ReflectionMethod( $handler, $method );
	$reflection->invoke( $handler, $payload );
}

function ys_run_webhook_paid_history_contract(): void {
	echo "== Webhook: paid-history guard for late non-paid events ==\n";

	$handler = new YSWebhookHandler();
	$events  = array(
		'handle_trade_authorized'      => 'AUTHORIZED',
		'handle_trade_processing'      => 'PROCESSING',
		'handle_trade_customer_action' => 'CUSTOMER_ACTION',
		'handle_trade_failed'          => 'FAILED',
		'handle_payment_cancelled'     => 'CANCELLED',
		'handle_trade_expired'         => 'EXPIRED',
	);

	foreach ( $events as $method => $remote_status ) {
		$order = new YS_Confirmation_Test_Order();
		$order->date_paid = '2026-07-17 12:00:00';
		$order->status = 'pending';
		$order->meta[ YSOrderMeta::PAYMENT_STATUS ] = 'SUCCEEDED';
		$GLOBALS['ys_test_orders'] = array( $order );

		ys_invoke_webhook_handler(
			$handler,
			$method,
			array(
				'tradeOrderId' => 'trade-confirm-1',
				'status'       => $remote_status,
				'subStatus'    => 'AUTHORIZED',
			)
		);

		YS_Assert::eq( "late {$remote_status} keeps paid-history order status", 'pending', $order->status );
		YS_Assert::eq( "late {$remote_status} keeps paid metadata", 'SUCCEEDED', $order->get_meta( YSOrderMeta::PAYMENT_STATUS ) );
	}

	echo "== Webhook: paid events remain idempotent for custom paid statuses ==\n";
	foreach ( array( 'handle_trade_succeeded' => 'SUCCEEDED', 'handle_trade_captured' => 'CAPTURED' ) as $method => $remote_status ) {
		$order = new YS_Confirmation_Test_Order();
		$order->date_paid = '2026-07-20 10:53:00';
		$order->paid = false;
		$order->status = 'shipped';
		$order->meta[ YSOrderMeta::TRADE_ORDER_ID ] = 'trade-confirm-1';
		$order->meta[ YSOrderMeta::PAYMENT_STATUS ] = 'SUCCEEDED';
		$GLOBALS['ys_test_order'] = $order;
		$GLOBALS['ys_test_orders'] = array( $order );
		ys_invoke_webhook_handler(
			$handler,
			$method,
			array(
				'tradeOrderId'     => 'trade-confirm-1',
				'referenceOrderId' => '9601_1',
				'status'           => $remote_status,
				'paymentMethod'    => 'CreditCard',
				'amount'           => array( 'value' => 10000, 'currency' => 'TWD' ),
			)
		);
		YS_Assert::eq( "{$remote_status} webhook never replays payment_complete after date_paid", 0, $order->payment_complete_count );
		YS_Assert::eq( "{$remote_status} webhook retains custom paid status", 'shipped', $order->status );
	}

	echo "== Webhook: customer-action convergence after unknown wallet result ==\n";

	$customer_action = new YS_Confirmation_Test_Order();
	$GLOBALS['ys_test_order'] = $customer_action;
	$GLOBALS['ys_test_orders'] = array(); // Unknown create response has no stored trade ID yet.
	YSPaymentConfirmation::enter_confirmation(
		$customer_action,
		ys_confirmation_attempt(
			array(
				'trade_order_id'  => '',
				'gateway'         => 'ys_shopline_linepay',
				'shopline_method' => 'LinePay',
				'reason'          => 'indeterminate',
				'remote_status'   => 'INDETERMINATE',
			)
		),
		'gateway_unknown'
	);

	ys_invoke_webhook_handler(
		$handler,
		'handle_trade_customer_action',
		array(
			'referenceOrderId' => '9601_1',
			'tradeOrderId'     => 'linepay-cancelled-1',
			'status'           => 'CUSTOMER_ACTION',
			'payment'          => array(
				'paymentMethod' => 'LinePay',
			),
			'order'            => array(
				'amount' => array( 'value' => 10000, 'currency' => 'TWD' ),
			),
		)
	);

	YS_Assert::eq( 'exact wallet customer-action webhook reopens payment immediately', 'pending', $customer_action->status );
	YS_Assert::eq( 'wallet customer-action webhook keeps trade for prior-trade resolver', 'linepay-cancelled-1', $customer_action->get_meta( YSOrderMeta::TRADE_ORDER_ID ) );
	YS_Assert::eq( 'wallet customer-action webhook clears confirmation lock', '', $customer_action->get_meta( YSOrderMeta::CONFIRMATION_DATA ) );

	$apple_action = new YS_Confirmation_Test_Order();
	$GLOBALS['ys_test_order'] = $apple_action;
	YSPaymentConfirmation::enter_confirmation(
		$apple_action,
		ys_confirmation_attempt(
			array(
				'trade_order_id'  => '',
				'gateway'         => 'ys_shopline_applepay',
				'shopline_method' => 'ApplePay',
				'reason'          => 'indeterminate',
				'remote_status'   => 'INDETERMINATE',
			)
		),
		'gateway_unknown'
	);
	$apple_payload = array(
		'referenceOrderId' => '9601_1',
		'tradeOrderId'     => 'applepay-cancelled-1',
		'payment'          => array( 'paymentMethod' => 'ApplePay' ),
		'order'            => array( 'amount' => array( 'value' => 10000, 'currency' => 'TWD' ) ),
	);
	YS_Assert::eq(
		'exact Apple Pay customer-action webhook is accepted',
		true,
		YSPaymentConfirmation::handle_customer_pending_webhook( $apple_action, $apple_payload, 'CUSTOMER_ACTION' )
	);
	YS_Assert::eq( 'Apple Pay customer-action reopens payment', 'pending', $apple_action->status );

	$mismatch = new YS_Confirmation_Test_Order();
	$GLOBALS['ys_test_order'] = $mismatch;
	YSPaymentConfirmation::enter_confirmation(
		$mismatch,
		ys_confirmation_attempt(
			array(
				'trade_order_id'  => '',
				'gateway'         => 'ys_shopline_linepay',
				'shopline_method' => 'LinePay',
				'reason'          => 'indeterminate',
				'remote_status'   => 'INDETERMINATE',
			)
		),
		'gateway_unknown'
	);
	$mismatch_payload = array(
		'referenceOrderId' => '9601_1',
		'tradeOrderId'     => 'linepay-wrong-1',
		'payment'          => array( 'paymentMethod' => 'LinePay' ),
		'order'            => array( 'amount' => array( 'value' => 10001, 'currency' => 'TWD' ) ),
	);
	YS_Assert::eq(
		'wallet customer-action amount mismatch is rejected',
		false,
		YSPaymentConfirmation::handle_customer_pending_webhook( $mismatch, $mismatch_payload, 'CUSTOMER_ACTION' )
	);
	YS_Assert::eq( 'mismatched customer-action remains locked', YSPaymentConfirmation::STATUS_KEY, $mismatch->status );
	YS_Assert::eq( 'mismatched customer-action keeps active attempt', '9601_1', $mismatch->get_meta( YSOrderMeta::CONFIRMATION_DATA )['reference'] ?? '' );

	$GLOBALS['ys_test_orders'] = array();
}
