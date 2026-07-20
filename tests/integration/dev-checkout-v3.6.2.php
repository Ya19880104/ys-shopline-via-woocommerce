<?php
/**
 * dev-checkout integration probe for v3.6.2.
 *
 * Run with: wp eval-file /tmp/dev-checkout-v3.6.2.php
 */

declare(strict_types=1);

use YangSheep\ShoplinePayment\Handlers\YSPaymentConfirmation;
use YangSheep\ShoplinePayment\Handlers\YSRedirectHandler;
use YangSheep\ShoplinePayment\Utils\YSOrderMeta;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	throw new RuntimeException( 'This probe must run under WP-CLI.' );
}

$passes   = 0;
$failures = array();
$check    = static function ( string $label, bool $condition ) use ( &$passes, &$failures ): void {
	if ( $condition ) {
		++$passes;
		WP_CLI::log( 'PASS | ' . $label );
		return;
	}
	$failures[] = $label;
	WP_CLI::warning( 'FAIL | ' . $label );
};

$original_post = $_POST;
$user_id       = 0;
$product_id    = 0;
$order_id      = 0;
$token_id      = 0;
$reference     = '';
$query_payload = array();
$query_calls   = 0;

$http_filter = static function ( $preempt, $request, $url ) use ( &$query_payload, &$query_calls ) {
	if ( false === strpos( (string) $url, '/trade/payment/get' ) ) {
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

try {
	$suffix  = strtolower( wp_generate_password( 8, false, false ) );
	$user_id = wp_insert_user(
		array(
			'user_login'   => 'ys362_' . $suffix,
			'user_pass'    => wp_generate_password( 24, true, true ),
			'user_email'   => 'ys362_' . $suffix . '@example.invalid',
			'display_name' => 'YS 3.6.2 Probe',
		)
	);
	if ( is_wp_error( $user_id ) ) {
		throw new RuntimeException( $user_id->get_error_message() );
	}
	$user_id = (int) $user_id;
	update_user_meta( $user_id, 'billing_first_name', 'Probe' );
	update_user_meta( $user_id, 'billing_last_name', 'Customer' );
	update_user_meta( $user_id, 'billing_email', 'ys362_' . $suffix . '@example.invalid' );
	update_user_meta( $user_id, 'billing_phone', '0912345678' );
	update_user_meta( $user_id, 'billing_country', 'TW' );
	update_user_meta( $user_id, YSOrderMeta::CUSTOMER_ID, 'dev-stale-customer-' . $suffix );

	$token = new WC_Payment_Token_CC();
	$token->set_token( 'dev-instrument-' . $suffix );
	$token->set_gateway_id( YSOrderMeta::CREDIT_GATEWAY_ID );
	$token->set_user_id( $user_id );
	$token->set_last4( '4242' );
	$token->set_expiry_month( '12' );
	$token->set_expiry_year( '2030' );
	$token->set_card_type( 'visa' );
	$token->save();
	$token_id = (int) $token->get_id();

	$product = new WC_Product_Simple();
	$product->set_name( 'YS 3.6.2 Integration Fixture' );
	$product->set_status( 'draft' );
	$product->set_regular_price( '220' );
	$product->set_price( '220' );
	$product->set_catalog_visibility( 'hidden' );
	$product_id = (int) $product->save();

	$order = wc_create_order( array( 'customer_id' => $user_id ) );
	if ( is_wp_error( $order ) ) {
		throw new RuntimeException( $order->get_error_message() );
	}
	$order_id = (int) $order->get_id();
	$order->set_billing_first_name( 'Probe' );
	$order->set_billing_last_name( 'Customer' );
	$order->set_billing_email( 'ys362_' . $suffix . '@example.invalid' );
	$order->set_billing_phone( '0912345678' );
	$order->set_billing_country( 'TW' );
	$order->add_product( $product, 1 );
	$order->calculate_totals();
	$order->save();

	$gateways = WC()->payment_gateways()->payment_gateways();
	$methods  = array(
		'ys_shopline_linepay'  => 'LinePay',
		'ys_shopline_applepay' => 'ApplePay',
		'ys_shopline_jkopay'   => 'JKOPay',
		'ys_shopline_atm'      => 'VirtualAccount',
		'ys_shopline_bnpl'     => 'ChaileaseBNPL',
	);

	foreach ( $methods as $gateway_id => $shopline_method ) {
		$check( $gateway_id . ' is registered', isset( $gateways[ $gateway_id ] ) );
		if ( ! isset( $gateways[ $gateway_id ] ) ) {
			continue;
		}
		$gateway = $gateways[ $gateway_id ];
		$method  = new ReflectionMethod( $gateway, 'prepare_payment_data' );
		$method->setAccessible( true );
		$order->set_payment_method( $gateway );
		$order->save();

		foreach ( array( 'new', 'new_save', 'saved', '' ) as $mode ) {
			$_POST = array(
				'ys_shopline_payment_instrument_mode' => $mode,
				'ys_shopline_payment_instrument_id'   => 'dev-instrument-' . $suffix,
				'ys_shopline_saved_card_last4'        => '4242',
			);
			$data  = $method->invoke( $gateway, $order, '{"sessionId":"dev-362"}' );
			$label = '' === $mode ? 'missing' : $mode;
			$check( $shopline_method . ' ' . $label . ' remains Regular', 'Regular' === ( $data['confirm']['paymentBehavior'] ?? '' ) );
			$check( $shopline_method . ' ' . $label . ' omits paymentCustomerId', ! array_key_exists( 'paymentCustomerId', $data['confirm'] ?? array() ) );
		}
	}
	$check(
		'wallet payload assembly does not replace the existing card customer mapping',
		'dev-stale-customer-' . $suffix === get_user_meta( $user_id, YSOrderMeta::CUSTOMER_ID, true )
	);

	$linepay = $gateways['ys_shopline_linepay'];
	$order->set_payment_method( $linepay );
	$order->set_status( 'pending' );
	$reference = $order_id . '_return';
	$trade_id  = 'dev-return-trade-' . $suffix;
	$attempt   = array(
		'reference'       => $reference,
		'trade_order_id'  => $trade_id,
		'session_id'      => 'dev-return-session-' . $suffix,
		'gateway'         => 'ys_shopline_linepay',
		'shopline_method' => 'LinePay',
		'amount'          => 22000,
		'currency'        => 'TWD',
		'started_at'      => time(),
	);
	$order->update_meta_data( YSOrderMeta::TRADE_ORDER_ID, $trade_id );
	$order->update_meta_data( YSOrderMeta::REFERENCE_ORDER_ID, $reference );
	$order->update_meta_data( YSOrderMeta::PAYMENT_ATTEMPT_DATA, $attempt );
	$order->save();

	$query_payload = array(
		'tradeOrderId'     => $trade_id,
		'referenceOrderId' => $reference,
		'status'           => 'CUSTOMER_ACTION',
		'paymentMethod'    => 'LinePay',
		'amount'           => array( 'value' => 22000, 'currency' => 'TWD' ),
	);
	add_filter( 'pre_http_request', $http_filter, 999, 3 );
	$redirect = new ReflectionMethod( YSRedirectHandler::class, 'check_and_update_order' );
	$redirect->setAccessible( true );
	$redirect->invoke( null, $order, $trade_id );

	$confirming = wc_get_order( $order_id );
	$active     = $confirming->get_meta( YSOrderMeta::CONFIRMATION_DATA );
	$check( 'real redirect handler moves CUSTOMER_ACTION to ys-confirming', YSPaymentConfirmation::STATUS_KEY === $confirming->get_status() );
	$check( 'confirming order is not payable', false === $confirming->needs_payment() );
	$check( 'confirmation display title is neutral', '付款確認中' === YSPaymentConfirmation::confirmation_title() );
	$check( 'confirmation keeps exact reference', $reference === ( $active['reference'] ?? '' ) );
	$check( 'confirmation keeps exact trade ID', $trade_id === ( $active['trade_order_id'] ?? '' ) );
	$scheduled = function_exists( 'as_has_scheduled_action' )
		? as_has_scheduled_action( YSPaymentConfirmation::SCHEDULE_HOOK, array( $order_id, $reference, 0 ), 'ys-shopline-payment-confirmation' )
		: wp_next_scheduled( YSPaymentConfirmation::SCHEDULE_HOOK, array( $order_id, $reference, 0 ) );
	$check( 'confirmation reconciliation is scheduled', false !== $scheduled && 0 !== $scheduled );

	$query_payload['status'] = 'SUCCEEDED';
	$redirect->invoke( null, $confirming, $trade_id );
	$paid = wc_get_order( $order_id );
	$check( 'exact paid query completes the confirming order', $paid->is_paid() );
	$check( 'paid convergence stores the trade as transaction ID', $trade_id === $paid->get_transaction_id() );
	$check( 'paid convergence clears the active confirmation lock', empty( $paid->get_meta( YSOrderMeta::CONFIRMATION_DATA ) ) );
	$check( 'redirect lifecycle used exactly two intercepted queries', 2 === $query_calls );
} finally {
	remove_filter( 'pre_http_request', $http_filter, 999 );
	$_POST = $original_post;
	if ( $order_id && $reference && function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions(
			YSPaymentConfirmation::SCHEDULE_HOOK,
			array( $order_id, $reference, 0 ),
			'ys-shopline-payment-confirmation'
		);
	}
	if ( $order_id ) {
		$cleanup_order = wc_get_order( $order_id );
		if ( $cleanup_order ) {
			$cleanup_order->delete( true );
		}
	}
	if ( $token_id ) {
		$cleanup_token = WC_Payment_Tokens::get( $token_id );
		if ( $cleanup_token ) {
			$cleanup_token->delete( true );
		}
	}
	if ( $product_id ) {
		wp_delete_post( $product_id, true );
	}
	if ( $user_id ) {
		wp_delete_user( $user_id );
	}
}

WP_CLI::log(
	wp_json_encode(
		array(
			'pass'       => $passes,
			'fail'       => count( $failures ),
			'order_id'   => $order_id,
			'product_id' => $product_id,
			'user_id'    => $user_id,
			'token_id'   => $token_id,
		)
	)
);
if ( $failures ) {
	throw new RuntimeException( 'Integration failures: ' . implode( ', ', $failures ) );
}
