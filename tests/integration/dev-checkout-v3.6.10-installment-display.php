<?php
/**
 * Real WooCommerce/HPOS probe for issue #1; never creates a remote payment.
 *
 * Run: wp --user=1 eval-file /tmp/dev-checkout-v3.6.10-installment-display.php
 * Optional YS_KEEP_FIXTURE=1 retains the passing fixture for browser inspection.
 * YS_CLEANUP_ORDER_ID removes only an order created by this probe.
 */

declare(strict_types=1);

use YangSheep\ShoplinePayment\Admin\YSOrderPaymentAdmin;
use YangSheep\ShoplinePayment\Utils\YSOrderMeta;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'dev-checkout.wppro.cloud' !== wp_parse_url( home_url(), PHP_URL_HOST ) ) {
	throw new RuntimeException( 'This probe is restricted to dev-checkout WP-CLI.' );
}

$created_via = 'ys-slp-issue-1-probe';
$cleanup_id  = (int) getenv( 'YS_CLEANUP_ORDER_ID' );
if ( $cleanup_id > 0 ) {
	$cleanup = wc_get_order( $cleanup_id );
	if ( ! $cleanup || $created_via !== $cleanup->get_created_via() ) {
		throw new RuntimeException( 'Refusing to delete an unrelated order.' );
	}
	$cleanup->delete( true );
	WP_CLI::success( 'Deleted issue #1 fixture ' . $cleanup_id );
	return;
}

if ( ! current_user_can( 'manage_woocommerce' ) ) {
	throw new RuntimeException( 'Run as a WooCommerce administrator.' );
}
if ( true !== apply_filters( 'pre_wp_mail', null, array() ) ) {
	throw new RuntimeException( 'Outgoing email must be disabled before running this probe.' );
}

$passes         = 0;
$failures       = array();
$http_calls     = 0;
$http_trace     = array();
$fixture        = null;
$completed      = false;
$original_post  = $_POST;
$original_user  = get_current_user_id();
$disable_tracking = static fn() => 'no';
$block_http     = static function ( $pre, $args, $url ) use ( &$http_calls, &$http_trace ) {
	++$http_calls;
	$http_trace[] = array(
		'host' => wp_parse_url( $url, PHP_URL_HOST ),
		'path' => wp_parse_url( $url, PHP_URL_PATH ),
		'caller' => array_map( static fn( array $frame ): string => ( $frame['class'] ?? '' ) . ( $frame['type'] ?? '' ) . $frame['function'], array_slice( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ), 4, 8 ) ),
	);
	return new WP_Error( 'ys_issue_1_no_network', 'This display probe must never contact a remote service.' );
};
$check = static function ( string $label, bool $condition ) use ( &$passes, &$failures ): void {
	if ( $condition ) {
		++$passes;
		WP_CLI::log( 'PASS | ' . $label );
	} else {
		$failures[] = $label;
		WP_CLI::warning( 'FAIL | ' . $label );
	}
};
$render = static function ( WC_Order $order ): string {
	ob_start();
	( new YSOrderPaymentAdmin() )->render_meta_box( $order );
	return (string) ob_get_clean();
};

add_filter( 'pre_http_request', $block_http, PHP_INT_MAX, 3 );
add_filter( 'pre_option_woocommerce_allow_tracking', $disable_tracking );
try {
	$check( 'runtime is the release candidate', defined( 'YS_SHOPLINE_VERSION' ) && '3.6.10' === YS_SHOPLINE_VERSION );
	$check( 'HPOS is enabled', 'yes' === get_option( 'woocommerce_custom_orders_table_enabled' ) );
	$fixture = new WC_Order();
	$fixture->set_created_via( $created_via );
	$fixture->set_currency( 'TWD' );
	$fixture->set_total( '12000' );
	$fixture->set_payment_method( 'ys_shopline_credit_installment' );
	$fixture->set_payment_method_title( 'SHOPLINE 信用卡分期' );
	$fixture->save();

	$gateways = WC()->payment_gateways()->payment_gateways();
	$credit   = $gateways['ys_shopline_credit_installment'];
	$prepare  = new ReflectionMethod( $credit, 'prepare_payment_data' );
	$_POST = array( 'ys_shopline_payment_instrument_mode' => 'new', 'ys_shopline_installment' => '6' );
	$data = $prepare->invoke( $credit, $fixture, 'probe-no-remote-payment' );
	$check( 'credit request still sends the selected six terms', '6' === ( $data['confirm']['paymentMethodOptions']['installments']['count'] ?? null ) );
	$fixture = wc_get_order( $fixture->get_id() );
	$check( 'credit selection survives a real database reload', 6 === (int) $fixture->get_meta( YSOrderMeta::INSTALLMENT ) );
	$html = $render( $fixture );
	$check( 'public admin renderer displays the six terms', str_contains( $html, '<th>分期期數</th>' ) && str_contains( $html, '6 期' ) );
	$check( 'summary uses the existing table without a second panel', 1 === substr_count( $html, '<table' ) );

	$_POST['ys_shopline_installment'] = '0';
	$data = $prepare->invoke( $credit, $fixture, 'probe-no-remote-payment' );
	$fixture = wc_get_order( $fixture->get_id() );
	$check( 'one-time repayment clears the previous credit terms', '' === $fixture->get_meta( YSOrderMeta::INSTALLMENT ) );
	$check( 'one-time request sends no installment options', ! isset( $data['confirm']['paymentMethodOptions']['installments'] ) );
	$check( 'one-time repayment displays no stale terms', ! str_contains( $render( $fixture ), '分期期數' ) );

	$_POST['ys_shopline_installment'] = '12';
	$prepare->invoke( $credit, $fixture, 'probe-no-remote-payment' );
	unset( $_POST['ys_shopline_installment'] );
	$prepare->invoke( $credit, $fixture, 'probe-no-remote-payment' );
	$fixture = wc_get_order( $fixture->get_id() );
	$check( 'missing selection also clears the previous credit terms', '' === $fixture->get_meta( YSOrderMeta::INSTALLMENT ) );

	$fixture->set_payment_method( 'ys_shopline_bnpl' );
	$fixture->set_payment_method_title( 'SHOPLINE 中租 zingala 銀角零卡' );
	// Request-local configuration only; do not change the site's saved gateway options.
	$gateways['ys_shopline_bnpl']->settings['installments'] = array( '36' );
	$_POST = array( 'ys_shopline_bnpl_installment' => '36' );
	$bnpl_prepare = new ReflectionMethod( $gateways['ys_shopline_bnpl'], 'prepare_payment_data' );
	$data = $bnpl_prepare->invoke( $gateways['ys_shopline_bnpl'], $fixture, 'probe-no-remote-payment' );
	$fixture = wc_get_order( $fixture->get_id() );
	$check( 'BNPL request still sends the selected 36 terms', '36' === ( $data['confirm']['paymentMethodOptions']['installments']['count'] ?? null ) );
	$check( 'BNPL selection survives a real database reload', 36 === (int) $fixture->get_meta( YSOrderMeta::BNPL_INSTALLMENT ) );
	$check( 'public admin renderer displays BNPL 36 terms', str_contains( $render( $fixture ), '36 期' ) );

	$fixture->update_meta_data( YSOrderMeta::INSTALLMENT, 6 );
	foreach ( array( 'ys_shopline_credit', 'ys_shopline_atm', 'ys_shopline_linepay', 'ys_shopline_applepay', 'ys_shopline_jkopay', 'ys_shopline_credit_subscription' ) as $gateway ) {
		$fixture->set_payment_method( $gateway );
		$fixture->save();
		$fixture = wc_get_order( $fixture->get_id() );
		$check( $gateway . ' hides stale installment metadata', ! str_contains( $render( $fixture ), '分期期數' ) );
	}

	$fixture->set_payment_method( 'ys_shopline_credit_installment' );
	$fixture->set_payment_method_title( 'SHOPLINE 信用卡分期' );
	$fixture->set_status( 'completed' );
	$fixture->set_date_paid( time() );
	$fixture->update_meta_data( YSOrderMeta::PAYMENT_STATUS, 'SUCCEEDED' );
	$fixture->update_meta_data( YSOrderMeta::TRADE_ORDER_ID, 'fixture-issue-1-no-remote-charge' );
	$fixture->save();
	$fixture = wc_get_order( $fixture->get_id() );
	$before = array( $fixture->get_status(), $fixture->get_total(), $fixture->get_meta_data(), $fixture->get_date_paid()->getTimestamp() );
	$html   = $render( $fixture );
	$fixture->read_meta_data( true );
	$after  = array( $fixture->get_status(), $fixture->get_total(), $fixture->get_meta_data(), $fixture->get_date_paid()->getTimestamp() );
	$check( 'paid order summary displays terms, status and identifier', str_contains( $html, '6 期' ) && str_contains( $html, 'SUCCEEDED' ) && str_contains( $html, 'fixture-issue-1-no-remote-charge' ) );
	ob_start();
	( new YSOrderPaymentAdmin() )->render_meta_box( new WP_Post( (object) array( 'ID' => $fixture->get_id(), 'post_type' => 'shop_order' ) ) );
	$legacy_html = (string) ob_get_clean();
	$check( 'legacy WP_Post adapter resolves the same order and terms', str_contains( $legacy_html, '6 期' ) );
	wp_set_current_user( 0 );
	$check( 'an unauthenticated user cannot render payment details', '' === $render( $fixture ) );
	wp_set_current_user( $original_user );
	$check( 'admin display leaves status, totals, meta and paid date unchanged', wp_json_encode( $before ) === wp_json_encode( $after ) );
	$check( 'no remote HTTP request was attempted', 0 === $http_calls );
	$check( 'email suppression remains active', true === apply_filters( 'pre_wp_mail', null, array() ) );
	$completed = ! $failures;
	WP_CLI::log( wp_json_encode( array( 'pass' => $passes, 'fail' => count( $failures ), 'fixture_id' => $fixture->get_id(), 'remote_requests' => $http_calls, 'http_trace' => $http_trace ) ) );
} finally {
	$_POST = $original_post;
	wp_set_current_user( $original_user );
	remove_filter( 'pre_http_request', $block_http, PHP_INT_MAX );
	remove_filter( 'pre_option_woocommerce_allow_tracking', $disable_tracking );
	if ( $fixture && ( ! $completed || '1' !== getenv( 'YS_KEEP_FIXTURE' ) ) ) {
		$fixture->delete( true );
		WP_CLI::log( 'Fixture deleted; no products or stock were changed.' );
	}
}

if ( $failures ) {
	throw new RuntimeException( 'Integration failures: ' . implode( ', ', $failures ) );
}
