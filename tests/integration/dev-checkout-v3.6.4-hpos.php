<?php
/**
 * dev-checkout integration probe for Hub Client HPOS declaration ownership.
 *
 * Run with the other full Hub Client copies skipped so the SHOPLINE bundle is
 * the first loaded copy:
 *
 * wp --skip-plugins=yangsheep-checkout-optimizer,ys-paynow-shipping,ys-raq-addons,ys-webp-tools \
 *   eval-file /tmp/dev-checkout-v3.6.4-hpos.php
 */

declare(strict_types=1);

use Automattic\WooCommerce\Utilities\FeaturesUtil;
use Automattic\WooCommerce\Utilities\OrderUtil;
use Automattic\WooCommerce\Utilities\PluginUtil;

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

/** @var PluginUtil $plugin_util */
$plugin_util = wc_get_container()->get( PluginUtil::class );
$host_id     = $plugin_util->get_wp_plugin_id( YS_SHOPLINE_PLUGIN_FILE );
$hub_id      = $plugin_util->get_wp_plugin_id( YS_HUB_CLIENT_FILE );
$features    = FeaturesUtil::get_compatible_features_for_plugin( (string) $host_id );

$check( 'runtime version is 3.6.4', defined( 'YS_SHOPLINE_VERSION' ) && '3.6.4' === YS_SHOPLINE_VERSION );
$check( 'loaded Hub Client is 2.0.5', defined( 'YS_HUB_CLIENT_VERSION' ) && '2.0.5' === YS_HUB_CLIENT_VERSION );
$check(
	'SHOPLINE main file resolves to the registered plugin ID',
	'ys-shopline-via-woocommerce/ys-shopline-via-woocommerce.php' === $host_id
);
$check( 'vendored Hub Client path is not a registered plugin ID', false === $hub_id );
$check(
	'SHOPLINE main plugin retains HPOS compatibility',
	in_array( 'custom_order_tables', $features['compatible'] ?? array(), true )
);
$check( 'HPOS remains enabled on dev-checkout', OrderUtil::custom_orders_table_usage_is_enabled() );

WP_CLI::log(
	wp_json_encode(
		array(
			'pass'    => $passes,
			'fail'    => count( $failures ),
			'host_id' => $host_id,
			'hub_id'  => $hub_id,
		)
	)
);

if ( $failures ) {
	throw new RuntimeException( 'Integration failures: ' . implode( ', ', $failures ) );
}
