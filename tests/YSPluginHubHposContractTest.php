<?php
/**
 * HPOS ownership contract for the vendored Hub Client.
 *
 * @package YangSheep\ShoplinePayment\Tests
 */

declare(strict_types=1);

function ys_run_plugin_hub_hpos_contract(): void {
	echo "== Hub Client HPOS declaration ownership ==\n";

	$root       = dirname( __DIR__ );
	$hub_file   = $root . '/vendor/yangsheep/ys-plugin-hub-client/ys-plugin-hub-client.php';
	$host_file  = $root . '/ys-shopline-via-woocommerce.php';
	$hub_source = (string) file_get_contents( $hub_file );
	$host_source = (string) file_get_contents( $host_file );

	YS_Assert::is_true(
		'vendored Hub Client does not declare WooCommerce feature compatibility',
		false === strpos( $hub_source, 'FeaturesUtil::declare_compatibility' )
	);
	YS_Assert::is_true(
		'SHOPLINE host owns the HPOS declaration',
		false !== strpos( $host_source, "'custom_order_tables'" )
			&& false !== strpos( $host_source, 'YS_SHOPLINE_PLUGIN_FILE' )
			&& false !== strpos( $host_source, 'FeaturesUtil::declare_compatibility' )
	);
	YS_Assert::is_true(
		'SHOPLINE host registers its HPOS declaration on the WooCommerce bootstrap hook',
		false !== strpos( $host_source, "add_action( 'before_woocommerce_init', array( \$this, 'declare_compatibility' ) )" )
	);
}
