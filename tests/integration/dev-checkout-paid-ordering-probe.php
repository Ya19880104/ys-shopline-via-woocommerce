<?php
/**
 * Cross-request cleanup probe for paid-ordering integration fixtures.
 */

declare(strict_types=1);

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	throw new RuntimeException( 'This probe must run under WP-CLI.' );
}

$order_ids = array_values(
	array_filter(
		array_map( 'absint', explode( ',', (string) getenv( 'YS_PROBE_ORDER_IDS' ) ) )
	)
);
$product_id = (int) getenv( 'YS_PROBE_PRODUCT_ID' );
$remaining_orders = array_values(
	array_filter(
		$order_ids,
		static fn ( int $order_id ): bool => (bool) wc_get_order( $order_id )
	)
);
$remaining = array(
	'orders'  => $remaining_orders,
	'product' => $product_id > 0 && (bool) get_post( $product_id ),
);

WP_CLI::log( wp_json_encode( $remaining ) );
if ( ! empty( $remaining_orders ) || true === $remaining['product'] ) {
	throw new RuntimeException( 'Paid-ordering integration fixture cleanup is incomplete.' );
}
