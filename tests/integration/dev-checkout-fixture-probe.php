<?php
/**
 * Cross-request cleanup probe for dev-checkout integration fixtures.
 */

declare(strict_types=1);

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	throw new RuntimeException( 'This probe must run under WP-CLI.' );
}

$order_id   = (int) getenv( 'YS_PROBE_ORDER_ID' );
$product_id = (int) getenv( 'YS_PROBE_PRODUCT_ID' );
$user_id    = (int) getenv( 'YS_PROBE_USER_ID' );
$token_id   = (int) getenv( 'YS_PROBE_TOKEN_ID' );

$remaining = array(
	'order'   => $order_id > 0 && (bool) wc_get_order( $order_id ),
	'product' => $product_id > 0 && (bool) get_post( $product_id ),
	'user'    => $user_id > 0 && (bool) get_userdata( $user_id ),
	'token'   => $token_id > 0 && (bool) WC_Payment_Tokens::get( $token_id ),
);

WP_CLI::log( wp_json_encode( $remaining ) );
if ( in_array( true, $remaining, true ) ) {
	throw new RuntimeException( 'Integration fixture cleanup is incomplete.' );
}
