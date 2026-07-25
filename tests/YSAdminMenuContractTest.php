<?php
/**
 * Admin menu endpoint contract.
 *
 * @package YangSheep\ShoplinePayment\Tests
 */

declare(strict_types=1);

use YangSheep\ShoplinePayment\Admin\YSAdminSettings;

function ys_run_admin_menu_contract(): void {
	echo "== Admin menu: legacy top-level and toolbox endpoints ==\n";

	$GLOBALS['menu'] = array(
		array( 'YS Plugin', 'manage_options', 'ys-toolbox', 'YS Plugin' ),
	);
	$GLOBALS['ys_test_admin_menu_pages']    = array();
	$GLOBALS['ys_test_admin_submenu_pages'] = array();

	$settings = ( new ReflectionClass( YSAdminSettings::class ) )->newInstanceWithoutConstructor();
	$settings->add_admin_menu();

	$top_level = $GLOBALS['ys_test_admin_menu_pages'][0] ?? array();
	YS_Assert::eq( 'legacy standalone endpoint is registered once', 1, count( $GLOBALS['ys_test_admin_menu_pages'] ) );
	YS_Assert::eq( 'legacy standalone endpoint keeps underscore slug', 'ys_shopline_payment', $top_level['menu_slug'] ?? '' );
	YS_Assert::eq( 'legacy standalone endpoint uses SHOPLINE label', 'SHOPLINE 金流', $top_level['menu_title'] ?? '' );
	YS_Assert::eq( 'legacy standalone endpoint requires manage_options', 'manage_options', $top_level['capability'] ?? '' );

	$toolbox_top = array_values(
		array_filter(
			$GLOBALS['menu'],
			static fn( array $item ): bool => 'ys-toolbox' === ( $item[2] ?? '' )
		)
	);
	YS_Assert::eq( 'existing toolbox menu is not duplicated', 1, count( $toolbox_top ) );
	YS_Assert::eq( 'existing toolbox menu label is normalized', '電商工具箱', $toolbox_top[0][0] ?? '' );
	YS_Assert::eq( 'existing toolbox page title is normalized', '電商工具箱', $toolbox_top[0][3] ?? '' );

	$shopline_submenus = array_values(
		array_filter(
			$GLOBALS['ys_test_admin_submenu_pages'],
			static fn( array $item ): bool => 'ys-shopline-payment' === ( $item['menu_slug'] ?? '' )
		)
	);
	$toolbox = $shopline_submenus[0] ?? array();
	YS_Assert::eq( 'toolbox endpoint remains registered once', 1, count( $shopline_submenus ) );
	YS_Assert::eq( 'toolbox endpoint remains under ys-toolbox', 'ys-toolbox', $toolbox['parent_slug'] ?? '' );
	YS_Assert::eq( 'both endpoints render the same settings callback', true, ( $top_level['callback'] ?? null ) === ( $toolbox['callback'] ?? null ) );

	$GLOBALS['ys_test_enqueued_styles']  = array();
	$GLOBALS['ys_test_enqueued_scripts'] = array();
	$settings->enqueue_scripts( 'toplevel_page_ys_shopline_payment' );
	YS_Assert::eq( 'legacy endpoint enqueues color picker style', array( 'wp-color-picker' ), $GLOBALS['ys_test_enqueued_styles'] );
	YS_Assert::eq( 'legacy endpoint enqueues color picker script', array( 'wp-color-picker' ), $GLOBALS['ys_test_enqueued_scripts'] );

	$settings->enqueue_scripts( 'post.php' );
	YS_Assert::eq( 'unrelated admin screen does not enqueue another style', 1, count( $GLOBALS['ys_test_enqueued_styles'] ) );
	YS_Assert::eq( 'unrelated admin screen does not enqueue another script', 1, count( $GLOBALS['ys_test_enqueued_scripts'] ) );
}
