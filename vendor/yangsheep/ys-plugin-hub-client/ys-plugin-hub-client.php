<?php
/**
 * Plugin Name: YS Plugin Hub Client
 * Plugin URI:  https://yangsheep.com.tw
 * Description: YANGSHEEP DESIGN 外掛市集客戶端 — 連接 Hub 取得更新和市集資訊。
 * Version:     2.0.2
 * Author:      YANGSHEEP DESIGN
 * Author URI:  https://yangsheep.com.tw
 * License:     GPL-2.0-or-later
 * Text Domain: ys-plugin-hub-client
 * Domain Path: /languages
 * Requires PHP: 7.4
 * Requires at least: 5.8
 *
 * @package YangSheep\PluginHubClient
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* ──────────────────────────────────────────────
 * 防止重複載入（必須在常數定義之前！）
 * 當多個 YS 外掛的 vendor/ 都包含此檔案時，只載入第一個。
 * ────────────────────────────────────────────── */
if ( defined( 'YS_HUB_CLIENT_VERSION' ) || did_action( 'ys_hub_client_loaded' ) ) {
    return;
}

/* ──────────────────────────────────────────────
 * 常數定義（放在防重複之後，確保只定義一次）
 * ────────────────────────────────────────────── */
define( 'YS_HUB_CLIENT_VERSION', '2.0.2' );
define( 'YS_HUB_CLIENT_FILE', __FILE__ );
define( 'YS_HUB_CLIENT_DIR', plugin_dir_path( __FILE__ ) );
define( 'YS_HUB_CLIENT_URL', plugin_dir_url( __FILE__ ) );
define( 'YS_HUB_CLIENT_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Hub 伺服器 URL（寫死，不可變更）
 */
define( 'YS_HUB_CLIENT_HUB_URL', 'https://yangsheep.com.tw' );

/* ──────────────────────────────────────────────
 * Fallback PSR-4 Autoloader
 * ────────────────────────────────────────────── */
spl_autoload_register( function ( $class ) {
    $prefix    = 'YangSheep\\PluginHubClient\\';
    $base_dir  = __DIR__ . '/src/';
    $len       = strlen( $prefix );

    if ( 0 !== strncmp( $prefix, $class, $len ) ) {
        return;
    }

    $relative = substr( $class, $len );
    $file     = $base_dir . str_replace( '\\', '/', $relative ) . '.php';

    if ( file_exists( $file ) ) {
        require_once $file;
    }
} );

/* ──────────────────────────────────────────────
 * HPOS 相容宣告
 * ────────────────────────────────────────────── */
add_action( 'before_woocommerce_init', function () {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            __FILE__,
            true
        );
    }
} );

/* ──────────────────────────────────────────────
 * Activation Hook — 建立資料表
 * ────────────────────────────────────────────── */
register_activation_hook( __FILE__, function () {
    $table_maker = \YangSheep\PluginHubClient\Database\YSHubClientTableMaker::instance();
    $table_maker->create_table();
} );

/* ──────────────────────────────────────────────
 * 主要啟動
 * ────────────────────────────────────────────── */
add_action( 'plugins_loaded', function () {
    // 檢查 schema 版本，必要時升級資料表
    $table_maker = \YangSheep\PluginHubClient\Database\YSHubClientTableMaker::instance();
    if ( $table_maker->schema_update_required() ) {
        $table_maker->create_table();
    }

    // 初始化主 Facade
    \YangSheep\PluginHubClient\YSPluginHubClient::instance();

    /**
     * 標記已載入，供其他外掛偵測
     */
    do_action( 'ys_hub_client_loaded' );
}, 10 );
