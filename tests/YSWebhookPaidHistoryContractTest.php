<?php
/**
 * Contract tests for late non-paid webhooks after an order has a paid date.
 *
 * @package YangSheep\ShoplinePayment\Tests
 */

declare(strict_types=1);

use YangSheep\ShoplinePayment\Handlers\YSWebhookHandler;
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

	$GLOBALS['ys_test_orders'] = array();
}
