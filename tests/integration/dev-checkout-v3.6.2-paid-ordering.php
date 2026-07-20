<?php
/**
 * dev-checkout integration probe for v3.6.2 paid-order monotonicity.
 *
 * Run with: wp eval-file /tmp/dev-checkout-v3.6.2-paid-ordering.php
 */

declare(strict_types=1);

use YangSheep\ShoplinePayment\Handlers\YSPaymentConfirmation;
use YangSheep\ShoplinePayment\Handlers\YSStatusManager;
use YangSheep\ShoplinePayment\Handlers\YSWebhookHandler;
use YangSheep\ShoplinePayment\Utils\YSOrderMeta;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	throw new RuntimeException( 'This probe must run under WP-CLI.' );
}

$passes         = 0;
$failures       = array();
$order_ids      = array();
$product_id     = 0;
$query_payload  = array();
$query_calls    = 0;
$create_calls   = 0;
$original_post  = $_POST;
$old_paid_state = get_option( 'ys_shopline_order_status_paid', null );

$check = static function ( string $label, bool $condition ) use ( &$passes, &$failures ): void {
	if ( $condition ) {
		++$passes;
		WP_CLI::log( 'PASS | ' . $label );
		return;
	}
	$failures[] = $label;
	WP_CLI::warning( 'FAIL | ' . $label );
};

$http_filter = static function ( $preempt, $request, $url ) use ( &$query_payload, &$query_calls, &$create_calls ) {
	$url = (string) $url;
	if ( false !== strpos( $url, '/trade/payment/create' ) ) {
		++$create_calls;
		return new WP_Error( 'integration_create_reached', 'A replacement create request must not leave WordPress.' );
	}
	if ( false === strpos( $url, '/trade/payment/get' ) ) {
		return $preempt;
	}
	++$query_calls;
	return array(
		'headers'  => array( 'content-type' => 'application/json' ),
		'body'     => wp_json_encode( $query_payload ),
		'response' => array( 'code' => 200, 'message' => 'OK' ),
		'cookies'  => array(),
		'filename' => null,
	);
};

$create_order = static function ( string $gateway_id, string $trade_id, string $reference ) use ( &$order_ids, &$product_id ): WC_Order {
	$order = wc_create_order();
	if ( is_wp_error( $order ) ) {
		throw new RuntimeException( $order->get_error_message() );
	}
	$order_ids[] = (int) $order->get_id();
	$product = wc_get_product( $product_id );
	$order->set_payment_method( $gateway_id );
	$order->set_billing_first_name( 'Paid Ordering' );
	$order->set_billing_last_name( 'Probe' );
	$order->set_billing_email( 'paid-ordering@example.invalid' );
	$order->add_product( $product, 1 );
	$order->calculate_totals();
	$order->update_meta_data( YSOrderMeta::TRADE_ORDER_ID, $trade_id );
	$order->update_meta_data( YSOrderMeta::REFERENCE_ORDER_ID, $reference );
	$order->update_meta_data( YSOrderMeta::PAYMENT_STATUS, 'CREATED' );
	$order->save();
	return $order;
};

$payment_payload = static function ( WC_Order $order, string $trade_id, string $reference, string $method, string $status ): array {
	$value = (int) round( (float) $order->get_total() * 100 );
	return array(
		'tradeOrderId'     => $trade_id,
		'referenceOrderId' => $reference,
		'status'           => $status,
		'paymentMethod'    => $method,
		'amount'           => array( 'value' => $value, 'currency' => $order->get_currency() ),
		'payment'          => array(
			'paymentMethod' => $method,
			'paidAmount'    => array( 'value' => $value, 'currency' => $order->get_currency() ),
		),
	);
};

add_filter( 'pre_http_request', $http_filter, 999, 3 );

try {
	update_option( 'ys_shopline_order_status_paid', 'completed' );

	$product = new WC_Product_Simple();
	$product->set_name( 'YS 3.6.2 Paid Ordering Fixture' );
	$product->set_status( 'draft' );
	$product->set_regular_price( '220' );
	$product->set_price( '220' );
	$product->set_catalog_visibility( 'hidden' );
	$product_id = (int) $product->save();

	$gateways = WC()->payment_gateways()->payment_gateways();
	$methods  = array(
		'ys_shopline_credit'              => 'CreditCard',
		'ys_shopline_credit_installment'  => 'CreditCard',
		'ys_shopline_credit_subscription' => 'CreditCard',
		'ys_shopline_atm'                 => 'VirtualAccount',
		'ys_shopline_jkopay'              => 'JKOPay',
		'ys_shopline_applepay'            => 'ApplePay',
		'ys_shopline_linepay'             => 'LinePay',
		'ys_shopline_bnpl'                => 'ChaileaseBNPL',
	);

	// One unresolved LINE Pay attempt must block every replacement gateway,
	// including ATM, before any create API request is possible.
	$lock_order = $create_order( 'ys_shopline_linepay', 'wallet-open', 'wallet-open-ref' );
	$attempt = array(
		'reference'       => 'wallet-open-ref',
		'trade_order_id'  => 'wallet-open',
		'session_id'      => 'wallet-session',
		'gateway'         => 'ys_shopline_linepay',
		'shopline_method' => 'LinePay',
		'amount'          => 22000,
		'currency'        => 'TWD',
		'remote_status'   => 'CUSTOMER_ACTION',
		'reason'          => 'customer_pending',
		'started_at'      => time(),
		'stage'           => 0,
	);
	YSPaymentConfirmation::enter_confirmation( $lock_order, $attempt, 'integration' );
	$scheduled_at = function_exists( 'as_next_scheduled_action' )
		? as_next_scheduled_action( YSPaymentConfirmation::SCHEDULE_HOOK, array( $lock_order->get_id(), 'wallet-open-ref', 0 ), 'ys-shopline-payment-confirmation' )
		: false;
	$delay = is_numeric( $scheduled_at ) ? (int) $scheduled_at - time() : -1;
	$check( 'LINE Pay first recheck is scheduled near 120 seconds', $delay >= 100 && $delay <= 140 );

	$_POST = array( 'ys_shopline_pay_session' => '{"sessionId":"must-not-create"}' );
	foreach ( $methods as $gateway_id => $method ) {
		$check( $gateway_id . ' is registered for replacement-lock probe', isset( $gateways[ $gateway_id ] ) );
		if ( ! isset( $gateways[ $gateway_id ] ) ) {
			continue;
		}
		$result = $gateways[ $gateway_id ]->process_payment( $lock_order->get_id() );
		$check( $gateway_id . ' replacement remains fail-closed', 'unknown' === ( $result['remote_outcome'] ?? '' ) );
		$check( $gateway_id . ' replacement keeps order confirming', YSPaymentConfirmation::STATUS_KEY === wc_get_order( $lock_order->get_id() )->get_status() );
	}
	$check( 'all replacement gateways are blocked before create API', 0 === $create_calls );

	$webhook = new YSWebhookHandler();
	$handle_succeeded = new ReflectionMethod( YSWebhookHandler::class, 'handle_trade_succeeded' );
	$handle_succeeded->setAccessible( true );
	$manager = new YSStatusManager();

	// Query first, webhook second: payment_complete hooks must run once and the
	// configured completed status must remain completed.
	$query_first = $create_order( 'ys_shopline_linepay', 'query-first-trade', 'query-first-ref' );
	$query_pre = 0;
	$query_complete = 0;
	$query_pre_hook = static function ( $order_id ) use ( $query_first, &$query_pre ): void {
		if ( (int) $order_id === $query_first->get_id() ) {
			++$query_pre;
		}
	};
	$query_complete_hook = static function ( $order_id ) use ( $query_first, &$query_complete ): void {
		if ( (int) $order_id === $query_first->get_id() ) {
			++$query_complete;
		}
	};
	add_action( 'woocommerce_pre_payment_complete', $query_pre_hook, 999, 3 );
	add_action( 'woocommerce_payment_complete', $query_complete_hook, 999, 1 );
	$query_payload = $payment_payload( $query_first, 'query-first-trade', 'query-first-ref', 'LinePay', 'SUCCEEDED' );
	$manager->sync_payment_status( $query_first );
	$handle_succeeded->invoke( $webhook, $query_payload );
	$query_first_fresh = wc_get_order( $query_first->get_id() );
	$check( 'query-first then webhook calls pre-payment-complete once', 1 === $query_pre );
	$check( 'query-first then webhook calls payment-complete once', 1 === $query_complete );
	$check( 'query-first then webhook retains completed status', 'completed' === $query_first_fresh->get_status() );
	remove_action( 'woocommerce_pre_payment_complete', $query_pre_hook, 999 );
	remove_action( 'woocommerce_payment_complete', $query_complete_hook, 999 );

	// Webhook first, query second: simulate a store moving processing to
	// completed before the later query observes a stale PROCESSING state.
	$webhook_first = $create_order( 'ys_shopline_linepay', 'webhook-first-trade', 'webhook-first-ref' );
	$webhook_pre = 0;
	$webhook_complete = 0;
	$webhook_pre_hook = static function ( $order_id ) use ( $webhook_first, &$webhook_pre ): void {
		if ( (int) $order_id === $webhook_first->get_id() ) {
			++$webhook_pre;
		}
	};
	$webhook_complete_hook = static function ( $order_id ) use ( $webhook_first, &$webhook_complete ): void {
		if ( (int) $order_id === $webhook_first->get_id() ) {
			++$webhook_complete;
		}
	};
	add_action( 'woocommerce_pre_payment_complete', $webhook_pre_hook, 999, 3 );
	add_action( 'woocommerce_payment_complete', $webhook_complete_hook, 999, 1 );
	$webhook_payload = $payment_payload( $webhook_first, 'webhook-first-trade', 'webhook-first-ref', 'LinePay', 'SUCCEEDED' );
	$handle_succeeded->invoke( $webhook, $webhook_payload );
	$webhook_first_fresh = wc_get_order( $webhook_first->get_id() );
	$webhook_first_fresh->update_status( 'completed' );
	$query_payload = $payment_payload( $webhook_first_fresh, 'webhook-first-trade', 'webhook-first-ref', 'LinePay', 'PROCESSING' );
	$manager->sync_payment_status( $webhook_first_fresh );
	$webhook_first_fresh = wc_get_order( $webhook_first->get_id() );
	$check( 'webhook-first then stale query calls pre-payment-complete once', 1 === $webhook_pre );
	$check( 'webhook-first then stale query calls payment-complete once', 1 === $webhook_complete );
	$check( 'late PROCESSING cannot downgrade completed order', 'completed' === $webhook_first_fresh->get_status() );
	$check( 'late PROCESSING cannot replace paid metadata', 'SUCCEEDED' === $webhook_first_fresh->get_meta( YSOrderMeta::PAYMENT_STATUS ) );
	remove_action( 'woocommerce_pre_payment_complete', $webhook_pre_hook, 999 );
	remove_action( 'woocommerce_payment_complete', $webhook_complete_hook, 999 );

	// Scheduled renewal response and its later webhook use the same completion
	// lock. This protects WooCommerce Subscriptions hooks from being replayed.
	$renewal = $create_order( 'ys_shopline_credit_subscription', 'renewal-race-trade', 'renewal-race-ref' );
	$renewal_pre = 0;
	$renewal_complete = 0;
	$renewal_pre_hook = static function ( $order_id ) use ( $renewal, &$renewal_pre ): void {
		if ( (int) $order_id === $renewal->get_id() ) {
			++$renewal_pre;
		}
	};
	$renewal_complete_hook = static function ( $order_id ) use ( $renewal, &$renewal_complete ): void {
		if ( (int) $order_id === $renewal->get_id() ) {
			++$renewal_complete;
		}
	};
	add_action( 'woocommerce_pre_payment_complete', $renewal_pre_hook, 999, 3 );
	add_action( 'woocommerce_payment_complete', $renewal_complete_hook, 999, 1 );
	$renewal_response = array( 'tradeOrderId' => 'renewal-race-trade', 'status' => 'SUCCEEDED' );
	$handle_renewal = new ReflectionMethod( $gateways['ys_shopline_credit_subscription'], 'handle_recurring_response' );
	$handle_renewal->setAccessible( true );
	$handle_renewal->invoke( $gateways['ys_shopline_credit_subscription'], $renewal, $renewal_response );
	$handle_succeeded->invoke(
		$webhook,
		$payment_payload( $renewal, 'renewal-race-trade', 'renewal-race-ref', 'CreditCard', 'SUCCEEDED' )
	);
	$handle_renewal->invoke( $gateways['ys_shopline_credit_subscription'], $renewal, $renewal_response );
	$renewal_fresh = wc_get_order( $renewal->get_id() );
	$check( 'renewal response plus webhook calls pre-payment-complete once', 1 === $renewal_pre );
	$check( 'renewal response plus webhook calls payment-complete once', 1 === $renewal_complete );
	$check( 'renewal response plus webhook remains paid', $renewal_fresh->is_paid() );
	remove_action( 'woocommerce_pre_payment_complete', $renewal_pre_hook, 999 );
	remove_action( 'woocommerce_payment_complete', $renewal_complete_hook, 999 );

	// Paid-history monotonicity is shared by all gateway IDs, including ATM.
	foreach ( $methods as $gateway_id => $method ) {
		$trade = 'paid-' . sanitize_key( $gateway_id );
		$order = $create_order( $gateway_id, $trade, $trade . '-ref' );
		$order->payment_complete( $trade );
		$order->update_status( 'completed' );
		$order->update_meta_data( YSOrderMeta::PAYMENT_STATUS, 'SUCCEEDED' );
		$order->save();
		$query_payload = $payment_payload( $order, $trade, $trade . '-ref', $method, 'PROCESSING' );
		$manager->sync_payment_status( $order );
		$fresh = wc_get_order( $order->get_id() );
		$check( $gateway_id . ' late PROCESSING retains completed', 'completed' === $fresh->get_status() );
		$check( $gateway_id . ' late PROCESSING retains paid metadata', 'SUCCEEDED' === $fresh->get_meta( YSOrderMeta::PAYMENT_STATUS ) );
	}
} finally {
	remove_filter( 'pre_http_request', $http_filter, 999 );
	$_POST = $original_post;
	if ( null === $old_paid_state ) {
		delete_option( 'ys_shopline_order_status_paid' );
	} else {
		update_option( 'ys_shopline_order_status_paid', $old_paid_state );
	}
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		foreach ( $order_ids as $cleanup_order_id ) {
			$cleanup = wc_get_order( $cleanup_order_id );
			if ( $cleanup ) {
				$active = $cleanup->get_meta( YSOrderMeta::CONFIRMATION_DATA );
				if ( is_array( $active ) && ! empty( $active['reference'] ) ) {
					as_unschedule_all_actions(
						YSPaymentConfirmation::SCHEDULE_HOOK,
						array( $cleanup_order_id, (string) $active['reference'], (int) ( $active['stage'] ?? 0 ) ),
						'ys-shopline-payment-confirmation'
					);
				}
			}
		}
	}
	foreach ( $order_ids as $cleanup_order_id ) {
		$cleanup = wc_get_order( $cleanup_order_id );
		if ( $cleanup ) {
			$cleanup->delete( true );
		}
	}
	if ( $product_id ) {
		wp_delete_post( $product_id, true );
	}
}

WP_CLI::log(
	wp_json_encode(
		array(
			'pass'       => $passes,
			'fail'       => count( $failures ),
			'order_ids'  => $order_ids,
			'product_id' => $product_id,
			'query_calls'=> $query_calls,
			'create_calls' => $create_calls,
		)
	)
);
if ( $failures ) {
	throw new RuntimeException( 'Integration failures: ' . implode( ', ', $failures ) );
}
