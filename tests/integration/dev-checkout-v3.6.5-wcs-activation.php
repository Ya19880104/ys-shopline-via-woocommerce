<?php
/**
 * Real WooCommerce Subscriptions/HPOS activation probe for v3.6.5.
 *
 * Run with:
 *   wp eval-file /tmp/dev-checkout-v3.6.5-wcs-activation.php
 */

declare(strict_types=1);

use YangSheep\ShoplinePayment\Handlers\YSPaymentConfirmation;

if ( ! function_exists( 'wcs_create_subscription' ) ) {
	throw new RuntimeException( 'WooCommerce Subscriptions is not active.' );
}

$pass             = 0;
$fail             = 0;
$order_ids        = array();
$subscription_ids = array();
$user_id          = 0;
$product_id       = 0;
$product          = null;
$filter_callback  = array( YSPaymentConfirmation::class, 'treat_confirming_transition_as_completed' );
$filter_attached  = has_filter( 'wcs_is_subscription_order_completed', $filter_callback );

$check = static function ( string $name, bool $condition ) use ( &$pass, &$fail ): void {
	if ( $condition ) {
		$pass++;
		echo "PASS | {$name}\n";
		return;
	}
	$fail++;
	echo "FAIL | {$name}\n";
};

$make_fixture = static function ( string $suffix ) use ( &$order_ids, &$subscription_ids, &$user_id, &$product ) {
	$order = wc_create_order(
		array(
			'customer_id' => $user_id,
			'status'      => 'pending',
		)
	);
	if ( is_wp_error( $order ) ) {
		throw new RuntimeException( $order->get_error_message() );
	}

	$order->set_payment_method( 'ys_shopline_credit_subscription' );
	$order->set_payment_method_title( 'SHOPLINE Credit Subscription Fixture ' . $suffix );
	$order->add_product( $product, 1 );
	$order->calculate_totals();
	$order->save();
	$order_ids[] = $order->get_id();

	$subscription = wcs_create_subscription(
		array(
			'order_id'        => $order->get_id(),
			'customer_id'     => $user_id,
			'billing_period'  => 'month',
			'billing_interval'=> 1,
			'status'          => 'pending',
		)
	);
	if ( is_wp_error( $subscription ) ) {
		throw new RuntimeException( $subscription->get_error_message() );
	}

	$subscription->set_payment_method( 'ys_shopline_credit_subscription' );
	$subscription->add_product( $product, 1 );
	$subscription->calculate_totals();
	$subscription->update_dates(
		array(
			'next_payment' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
		)
	);
	$subscription->save();
	$subscription_ids[] = $subscription->get_id();

	return array( $order, $subscription );
};

$cleanup = static function () use ( &$order_ids, &$subscription_ids, &$user_id, &$product_id, $filter_callback, &$filter_attached ): void {
	if ( ! $filter_attached ) {
		add_filter( 'wcs_is_subscription_order_completed', $filter_callback, 10, 5 );
		$filter_attached = true;
	}

	foreach ( $subscription_ids as $subscription_id ) {
		$args = array( 'subscription_id' => $subscription_id );
		foreach ( array( 'payment', 'trial_end', 'expiration', 'end_of_prepaid_term' ) as $event ) {
			as_unschedule_all_actions( 'woocommerce_scheduled_subscription_' . $event, $args );
		}
		$subscription = wcs_get_subscription( $subscription_id );
		if ( $subscription instanceof WC_Subscription ) {
			$subscription->delete( true );
		}
	}

	foreach ( $order_ids as $order_id ) {
		$order = wc_get_order( $order_id );
		if ( $order instanceof WC_Order ) {
			$order->delete( true );
		}
	}

	if ( $product_id > 0 ) {
		$product = wc_get_product( $product_id );
		if ( $product instanceof WC_Product ) {
			$product->delete( true );
		}
	}

	if ( $user_id > 0 ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $user_id );
	}
};

try {
	$login   = 'ys_wcs_' . wp_generate_password( 10, false, false );
	$user_id = wp_create_user( $login, wp_generate_password( 24, true, true ), $login . '@example.test' );
	if ( is_wp_error( $user_id ) ) {
		throw new RuntimeException( $user_id->get_error_message() );
	}

	$product = new WC_Product_Simple();
	$product->set_name( 'YS WCS activation fixture' );
	$product->set_status( 'private' );
	$product->set_regular_price( '100' );
	$product->set_price( '100' );
	$product->set_virtual( false );
	$product_id = $product->save();

	$check( 'runtime version is 3.6.5', defined( 'YS_SHOPLINE_VERSION' ) && '3.6.5' === YS_SHOPLINE_VERSION );
	$check( 'HPOS is enabled', class_exists( Automattic\WooCommerce\Utilities\OrderUtil::class ) && Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() );
	$check( 'WCS completion filter is registered', false !== $filter_attached );

	list( $native_order, $native_subscription ) = $make_fixture( 'native' );
	$native_order->payment_complete( 'ys-wcs-native' );
	$native_subscription = wcs_get_subscription( $native_subscription->get_id() );
	$check( 'native pending payment still activates subscription', $native_subscription instanceof WC_Subscription && $native_subscription->has_status( 'active' ) );

	remove_filter( 'wcs_is_subscription_order_completed', $filter_callback, 10 );
	$filter_attached = false;
	list( $broken_order, $broken_subscription ) = $make_fixture( 'broken-control' );
	$broken_order->update_status( YSPaymentConfirmation::STATUS_KEY );
	$broken_order->payment_complete( 'ys-wcs-broken-control' );
	$broken_subscription = wcs_get_subscription( $broken_subscription->get_id() );
	$check( 'control reproduces pending subscription without compatibility filter', $broken_subscription instanceof WC_Subscription && $broken_subscription->has_status( 'pending' ) );

	add_filter( 'wcs_is_subscription_order_completed', $filter_callback, 10, 5 );
	$filter_attached = true;
	list( $fixed_order, $fixed_subscription ) = $make_fixture( 'fixed' );
	$fixed_order->update_status( YSPaymentConfirmation::STATUS_KEY );
	$fixed_order->payment_complete( 'ys-wcs-fixed' );
	$fixed_subscription = wcs_get_subscription( $fixed_subscription->get_id() );
	$fixed_args         = array( 'subscription_id' => $fixed_subscription->get_id() );
	$first_schedule     = as_next_scheduled_action( 'woocommerce_scheduled_subscription_payment', $fixed_args );
	$check( 'confirming payment activates subscription', $fixed_subscription instanceof WC_Subscription && $fixed_subscription->has_status( 'active' ) );
	$check( 'confirming payment records paid date', (bool) $fixed_order->get_date_paid() );
	$check( 'activation schedules next subscription payment', false !== $first_schedule );

	$fixed_order->payment_complete( 'ys-wcs-fixed-replayed' );
	$fixed_subscription = wcs_get_subscription( $fixed_subscription->get_id() );
	$check( 'duplicate payment completion keeps subscription active', $fixed_subscription instanceof WC_Subscription && $fixed_subscription->has_status( 'active' ) );
	$check( 'duplicate payment completion does not duplicate schedule', $first_schedule === as_next_scheduled_action( 'woocommerce_scheduled_subscription_payment', $fixed_args ) );

	list( $unpaid_order, $unpaid_subscription ) = $make_fixture( 'unpaid' );
	$unpaid_order->update_status( YSPaymentConfirmation::STATUS_KEY );
	$unpaid_order->update_status( 'on-hold' );
	$unpaid_subscription = wcs_get_subscription( $unpaid_subscription->get_id() );
	$check( 'non-paid destination keeps subscription pending', $unpaid_subscription instanceof WC_Subscription && $unpaid_subscription->has_status( 'pending' ) );
	$check( 'non-paid destination keeps parent date unpaid', ! $unpaid_order->get_date_paid() );

	list( $manual_order, $manual_subscription ) = $make_fixture( 'manual-processing' );
	$manual_order->update_status( YSPaymentConfirmation::STATUS_KEY );
	$manual_order->update_status( 'processing' );
	$manual_order        = wc_get_order( $manual_order->get_id() );
	$manual_subscription = wcs_get_subscription( $manual_subscription->get_id() );
	$check( 'manual processing follows WooCommerce paid-date semantics', (bool) $manual_order->get_date_paid() );
	$check( 'manual processing activates subscription like native WCS', $manual_subscription instanceof WC_Subscription && $manual_subscription->has_status( 'active' ) );

	echo 'FIXTURE_ORDER_IDS=' . implode( ',', $order_ids ) . "\n";
	echo 'FIXTURE_SUBSCRIPTION_IDS=' . implode( ',', $subscription_ids ) . "\n";
} finally {
	$cleanup();
}

echo "RESULT: {$pass} PASS / {$fail} FAIL\n";
if ( $fail > 0 ) {
	throw new RuntimeException( 'WCS activation integration failed.' );
}
