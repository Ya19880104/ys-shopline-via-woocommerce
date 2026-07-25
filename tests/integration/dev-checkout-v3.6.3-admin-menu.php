<?php
/**
 * dev-checkout integration probe for the v3.6.3 dual admin endpoints.
 *
 * Run with: wp --user=1 eval-file /tmp/dev-checkout-v3.6.3-admin-menu.php
 */

declare(strict_types=1);

use YangSheep\ShoplinePayment\Admin\YSAdminSettings;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	throw new RuntimeException( 'This probe must run under WP-CLI.' );
}

if ( ! function_exists( 'add_menu_page' ) ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
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

global $menu, $submenu, $_registered_pages, $_parent_pages;

$menu              = array();
$submenu           = array();
$_registered_pages = array();
$_parent_pages     = array();

$settings = YSAdminSettings::get_instance();
do_action( 'admin_menu' );

$top_level = array_values(
	array_filter(
		$menu,
		static fn( array $item ): bool => 'ys_shopline_payment' === ( $item[2] ?? '' )
	)
);
$toolbox_top = array_values(
	array_filter(
		$menu,
		static fn( array $item ): bool => 'ys-toolbox' === ( $item[2] ?? '' )
	)
);
$toolbox_submenus = array_values(
	array_filter(
		$submenu['ys-toolbox'] ?? array(),
		static fn( array $item ): bool => 'ys-shopline-payment' === ( $item[2] ?? '' )
	)
);

$legacy_hook = get_plugin_page_hookname( 'ys_shopline_payment', '' );
$toolbox_hook = get_plugin_page_hookname( 'ys-shopline-payment', 'ys-toolbox' );

$check( 'runtime version is 3.6.3', defined( 'YS_SHOPLINE_VERSION' ) && '3.6.3' === YS_SHOPLINE_VERSION );
$check( 'loaded Hub Client is 2.0.4', defined( 'YS_HUB_CLIENT_VERSION' ) && '2.0.4' === YS_HUB_CLIENT_VERSION );
$check( 'loaded Hub URL remains HTTPS', defined( 'YS_HUB_CLIENT_HUB_URL' ) && str_starts_with( YS_HUB_CLIENT_HUB_URL, 'https://' ) );
$check( 'legacy top-level endpoint is registered once', 1 === count( $top_level ) );
$check( 'legacy top-level endpoint uses SHOPLINE label', 'SHOPLINE 金流' === ( $top_level[0][0] ?? '' ) );
$check( 'legacy top-level endpoint requires manage_options', 'manage_options' === ( $top_level[0][1] ?? '' ) );
$check( 'toolbox top-level remains registered once', 1 === count( $toolbox_top ) );
$check( 'toolbox top-level label is normalized', '電商工具箱' === ( $toolbox_top[0][0] ?? '' ) );
$check( 'toolbox page title is normalized', '電商工具箱' === ( $toolbox_top[0][3] ?? '' ) );
$check( 'toolbox top-level uses the store icon', 'dashicons-store' === ( $toolbox_top[0][6] ?? '' ) );
$check( 'toolbox endpoint remains registered once', 1 === count( $toolbox_submenus ) );
$check( 'toolbox endpoint requires manage_options', 'manage_options' === ( $toolbox_submenus[0][1] ?? '' ) );
$check( 'legacy endpoint hook is registered', false !== has_action( $legacy_hook, array( $settings, 'render_settings_page' ) ) );
$check( 'toolbox endpoint hook is registered', false !== has_action( $toolbox_hook, array( $settings, 'render_settings_page' ) ) );

$settings->register_settings();

$original_page = $_GET['page'] ?? null;
$outputs       = array();
foreach ( array( 'ys_shopline_payment', 'ys-shopline-payment' ) as $page_slug ) {
	$_GET['page'] = $page_slug;
	ob_start();
	$settings->render_settings_page();
	$output = (string) ob_get_clean();
	$output = preg_replace( '/value="[a-f0-9]{10}"/', 'value="[nonce]"', $output );
	$outputs[ $page_slug ] = $output;
	$check( $page_slug . ' renders the settings form', false !== strpos( $output, 'class="ys-settings-form"' ) );
	$check( $page_slug . ' renders the merchant setting', false !== strpos( $output, 'name="ys_shopline_merchant_id"' ) );
	$check( $page_slug . ' renders the SHOPLINE heading', false !== strpos( $output, 'SHOPLINE Payment' ) );
}

if ( null === $original_page ) {
	unset( $_GET['page'] );
} else {
	$_GET['page'] = $original_page;
}

$check(
	'both endpoints render identical settings markup',
	$outputs['ys_shopline_payment'] === $outputs['ys-shopline-payment']
);

if ( ! wp_script_is( 'wp-color-picker', 'registered' ) ) {
	wp_register_script( 'wp-color-picker', false, array(), false, true );
}
$settings->enqueue_scripts( $legacy_hook );
$check( 'legacy endpoint enqueues the color picker style', wp_style_is( 'wp-color-picker', 'enqueued' ) );
$check( 'legacy endpoint enqueues the color picker script', wp_script_is( 'wp-color-picker', 'enqueued' ) );

WP_CLI::log(
	wp_json_encode(
		array(
			'pass'         => $passes,
			'fail'         => count( $failures ),
			'legacy_hook'  => $legacy_hook,
			'toolbox_hook' => $toolbox_hook,
		)
	)
);

if ( $failures ) {
	throw new RuntimeException( 'Integration failures: ' . implode( ', ', $failures ) );
}
