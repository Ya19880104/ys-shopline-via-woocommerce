<?php
/**
 * Plugin Name: YS Shopline via WooCommerce
 * Plugin URI: https://yangsheep.com.tw
 * Description: Support Shopline Payments for WooCommerce, including HPOS and Subscriptions. Supports Credit Card, ATM, JKOPay, Apple Pay, LINE Pay, and Chailease BNPL.
 * Version:           3.6.0
 * Author: YangSheep
 * Author URI: https://yangsheep.com.tw
 * Text Domain: ys-shopline-via-woocommerce
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * WC requires at least: 7.0
 * WC tested up to: 10.4
 */

defined( 'ABSPATH' ) || exit;

// Define plugin constants
define( 'YS_SHOPLINE_VERSION', '3.6.0' );
// v3.5.14: 資料庫綱要版本（用於 plugins_loaded 一次性 migration），與 plugin 版本解耦。
define( 'YS_SHOPLINE_DB_VERSION', '3.5.14' );
define( 'YS_SHOPLINE_PLUGIN_FILE', __FILE__ );
define( 'YS_SHOPLINE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'YS_SHOPLINE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'YS_SHOPLINE_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Composer autoloader（載入 hub-client 等依賴）
if ( file_exists( YS_SHOPLINE_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
    require_once YS_SHOPLINE_PLUGIN_DIR . 'vendor/autoload.php';
}

// 永遠註冊 Fallback PSR-4（即使 vendor 存在，也確保自身 namespace 可載入）
spl_autoload_register( function ( $class ) {
    $prefix   = 'YangSheep\\ShoplinePayment\\';
    $base_dir = YS_SHOPLINE_PLUGIN_DIR . 'src/';
    $len      = strlen( $prefix );
    if ( strncmp( $prefix, $class, $len ) !== 0 ) {
        return;
    }
    $file = $base_dir . str_replace( '\\', '/', substr( $class, $len ) ) . '.php';
    if ( file_exists( $file ) ) {
        require_once $file;
    }
} );

// 停用時清理排程（v3.4.12 起 BindCardLogger 已整合至 YSLogger，不再排程獨立清理）
register_deactivation_hook( __FILE__, function () {
    wp_clear_scheduled_hook( 'ys_shopline_sync_pending_orders' );
    wp_clear_scheduled_hook( 'ys_shopline_bindcard_log_cleanup' ); // 舊版遺留
    wp_clear_scheduled_hook( 'ys_shopline_confirm_payment' );
    if ( function_exists( 'as_unschedule_all_actions' ) ) {
        as_unschedule_all_actions( 'ys_shopline_confirm_payment', array(), 'ys-shopline-payment-confirmation' );
    }
} );

use YangSheep\ShoplinePayment\Utils\YSLogger;
use YangSheep\ShoplinePayment\Utils\YSOrderMeta;
use YangSheep\ShoplinePayment\Api\YSApi;
use YangSheep\ShoplinePayment\Admin\YSAdminSettings;
use YangSheep\ShoplinePayment\Admin\YSUserCardsAdmin;
use YangSheep\ShoplinePayment\Admin\YSOrderPaymentAdmin;
use YangSheep\ShoplinePayment\Gateways\YSGatewayBase;
use YangSheep\ShoplinePayment\Gateways\YSSubscription;
use YangSheep\ShoplinePayment\Gateways\YSCreditCard;
use YangSheep\ShoplinePayment\Gateways\YSCreditInstallment;
use YangSheep\ShoplinePayment\Gateways\YSCreditSubscription;
use YangSheep\ShoplinePayment\Gateways\YSVirtualAccount;
use YangSheep\ShoplinePayment\Gateways\YSJKOPay;
use YangSheep\ShoplinePayment\Gateways\YSApplePay;
use YangSheep\ShoplinePayment\Gateways\YSLinePay;
use YangSheep\ShoplinePayment\Gateways\YSChaileaseBNPL;
use YangSheep\ShoplinePayment\Customer\YSCustomer;
use YangSheep\ShoplinePayment\Frontend\YSOrderDisplay;
use YangSheep\ShoplinePayment\Handlers\YSRedirectHandler;
use YangSheep\ShoplinePayment\Handlers\YSAddPaymentMethodHandler;
use YangSheep\ShoplinePayment\Handlers\YSWebhookHandler;
use YangSheep\ShoplinePayment\Handlers\YSStatusManager;
use YangSheep\ShoplinePayment\Handlers\YSPaymentConfirmation;
// v3.5.2: YSBlocksSupport 已停用（見 init()），保留類別但不 use/init
// use YangSheep\ShoplinePayment\Blocks\YSBlocksSupport;

/**
 * Main plugin class.
 */
final class YSShoplinePayment {

    /**
     * Plugin instance.
     *
     * @var YSShoplinePayment
     */
    private static $instance = null;

    /**
     * Get plugin instance.
     *
     * @return YSShoplinePayment
     */
    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor.
     */
    private function __construct() {
        $this->init_hooks();
    }

    /**
     * Initialize hooks.
     */
    private function init_hooks() {
        // 註冊到 YS Plugin Hub Client（自動更新 + 市集）
        if ( class_exists( '\YangSheep\PluginHubClient\YSPluginHubClient' ) ) {
            \YangSheep\PluginHubClient\YSPluginHubClient::register( array(
                'slug'        => 'ys-shopline-via-woocommerce',
                'version'     => YS_SHOPLINE_VERSION,
                'plugin_file' => __FILE__,
                'name'        => 'YS Shopline Payment',
            ) );
        }

        // Declare compatibility
        add_action( 'before_woocommerce_init', array( $this, 'declare_compatibility' ) );

        // v3.5.14: 一次性 migration（早於 init/register_gateways，priority 5）
        // 將舊版 ys_shopline_{gateway}_enabled option 同步到 WooCommerce 原生 gateway settings 的 enabled，
        // 避免「外掛開關只控制註冊；WC 原生控制啟閉」的雙層分工讓既有站升級後 gateway 全失效。
        add_action( 'plugins_loaded', array( $this, 'maybe_run_migrations' ), 5 );

        // Initialize plugin after WooCommerce is loaded
        add_action( 'plugins_loaded', array( $this, 'init' ), 11 );

        // Register payment gateways
        add_filter( 'woocommerce_payment_gateways', array( $this, 'register_gateways' ) );

        // WooCommerce Subscriptions copies custom meta into renewal orders on an
        // opt-out basis. Register this before gateway instances are constructed so
        // scheduled and manually-created renewals cannot inherit prior trade state.
        add_filter(
            'wcs_renewal_order_meta_query',
            array( YSCreditSubscription::class, 'exclude_order_specific_meta_from_renewals' ),
            10,
            3
        );

        // 允許 ATM 離線付款訂單進入 order-pay 變更付款方式。
        add_filter( 'woocommerce_valid_order_statuses_for_payment', array( $this, 'allow_offline_order_payment_change' ), 10, 2 );

        // Initialize admin settings page
        YSAdminSettings::get_instance();

        // Add settings link on plugins page
        add_filter( 'plugin_action_links_' . YS_SHOPLINE_PLUGIN_BASENAME, array( $this, 'plugin_action_links' ) );

        // Add plugin templates path for WooCommerce template overrides
        add_filter( 'woocommerce_locate_template', array( $this, 'locate_template' ), 10, 3 );
    }

    /**
     * Locate plugin templates.
     *
     * 讓 WooCommerce 優先使用外掛中的 template 檔案
     *
     * @param string $template      Template file path.
     * @param string $template_name Template name.
     * @param string $template_path Template path.
     * @return string
     */
    public function locate_template( $template, $template_name, $template_path ) {
        // 只處理 payment-methods.php
        if ( 'myaccount/payment-methods.php' !== $template_name ) {
            return $template;
        }

        // 檢查外掛是否有這個 template
        $plugin_template = YS_SHOPLINE_PLUGIN_DIR . 'templates/' . $template_name;

        if ( file_exists( $plugin_template ) ) {
            return $plugin_template;
        }

        return $template;
    }

    /**
     * Allow offline Shopline orders to enter order-pay for changing payment.
     *
     * WooCommerce excludes on-hold orders from payment by default. ATM orders
     * can intentionally stay on-hold while waiting for transfer, but customers
     * still need a working "change payment method" link.
     *
     * @param array          $statuses Valid payment statuses.
     * @param \WC_Order|null $order    Order object when provided by WooCommerce.
     * @return array
     */
    public function allow_offline_order_payment_change( $statuses, $order = null ) {
        if ( ! is_array( $statuses ) || ! is_a( $order, 'WC_Order' ) ) {
            return $statuses;
        }

        if ( 'on-hold' !== $order->get_status() ) {
            return $statuses;
        }

        if ( 'yes' === $order->get_meta( YSOrderMeta::PAYMENT_AUTHORIZED_PENDING ) ) {
            return $statuses;
        }

        if ( 'ys_shopline_atm' !== $order->get_payment_method() ) {
            return $statuses;
        }

        if ( ! in_array( 'on-hold', $statuses, true ) ) {
            $statuses[] = 'on-hold';
        }

        return $statuses;
    }

    /**
     * Declare compatibility with HPOS and Subscriptions.
     */
    public function declare_compatibility() {
        if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
            // HPOS compatibility
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
                'custom_order_tables',
                YS_SHOPLINE_PLUGIN_FILE,
                true
            );

            // v3.5.1: 移除 cart_checkout_blocks 宣告
            // 原先宣告 compatible 但 gateway 的 canMakePayment() 回 false，API contract 不一致。
            // 目前只支援傳統結帳頁（非 Blocks），明確不宣告反而比假宣告準確。
            // 若未來完整支援 Blocks 再重新宣告。
        }
    }

    /**
     * v3.5.14: 一次性 migration — 將舊版 ys_shopline_{gateway}_enabled option
     * 同步到 WooCommerce 原生 gateway settings 的 enabled。
     *
     * 背景：v3.5.13 之前 YSGatewayBase 用 `get_option('ys_shopline_xxx_enabled')`
     *      覆寫 $this->enabled，WC 原生 woocommerce_{id}_settings.enabled 從未被使用。
     *      v3.5.14 將 enabled 雙層分工（外掛開關只控制註冊，WC 原生控制啟閉）後，
     *      既有站若不 migrate，所有 gateway 在 WC 原生 settings 沒設過 enabled
     *      → fallback 'no' → 結帳全失效、訂閱續扣斷掉。
     *
     * 設計：
     *   - 用 ys_shopline_db_version option 做版本門檻（避免重複跑）
     *   - 只在 enabled key 不存在時寫入（不覆寫使用者主動關閉的 'no'）
     *   - 全新安裝：舊 option 不存在 → fallback 用各 gateway 的合理預設
     *   - 升級安裝：讀舊 option 同步過去
     *
     * 不依賴 register_activation_hook：升級不需要 deactivate/reactivate。
     *
     * @return void
     */
    public function maybe_run_migrations() {
        $current_db_version = get_option( 'ys_shopline_db_version', '0' );

        // 已是最新版（含未來版本）→ 不跑
        if ( version_compare( $current_db_version, YS_SHOPLINE_DB_VERSION, '>=' ) ) {
            return;
        }

        // v3.5.14: 同步 ys_shopline_xxx_enabled → woocommerce_{gateway_id}_settings.enabled
        if ( version_compare( $current_db_version, '3.5.14', '<' ) ) {
            $this->migrate_v3_5_14_gateway_enabled();
        }

        update_option( 'ys_shopline_db_version', YS_SHOPLINE_DB_VERSION );
    }

    /**
     * v3.5.14 migration：同步外掛 enabled option 到 WC 原生 gateway settings。
     *
     * Map：舊 option key → WC settings option key + default（與舊版 register_gateways 預設對齊）
     *
     * @return void
     */
    private function migrate_v3_5_14_gateway_enabled() {
        $gateway_map = array(
            // gateway_id => array( old_option_key, default_when_missing )
            'ys_shopline_credit'              => array( 'ys_shopline_credit_enabled', 'yes' ),
            'ys_shopline_credit_installment'  => array( 'ys_shopline_credit_installment_enabled', 'no' ),
            'ys_shopline_credit_subscription' => array( 'ys_shopline_credit_subscription_enabled', 'yes' ),
            'ys_shopline_atm'                 => array( 'ys_shopline_atm_enabled', 'yes' ),
            'ys_shopline_jkopay'              => array( 'ys_shopline_jkopay_enabled', 'yes' ),
            'ys_shopline_applepay'            => array( 'ys_shopline_applepay_enabled', 'yes' ),
            'ys_shopline_linepay'             => array( 'ys_shopline_linepay_enabled', 'yes' ),
            'ys_shopline_bnpl'                => array( 'ys_shopline_bnpl_enabled', 'yes' ),
        );

        $migrated = array();
        foreach ( $gateway_map as $gateway_id => $config ) {
            list( $old_option_key, $default_value ) = $config;

            $wc_settings_key = 'woocommerce_' . $gateway_id . '_settings';
            $wc_settings     = get_option( $wc_settings_key, array() );
            if ( ! is_array( $wc_settings ) ) {
                $wc_settings = array();
            }

            // 只在 WC 原生 settings 沒設過 enabled 時寫入（避免覆寫使用者主動關閉的 'no'）
            if ( isset( $wc_settings['enabled'] ) ) {
                continue;
            }

            $old_value = get_option( $old_option_key, null );
            $sync_value = ( null !== $old_value ) ? $old_value : $default_value;
            // sanitize 成 WC checkbox 預期的 'yes' / 'no'
            $sync_value = ( 'yes' === $sync_value ) ? 'yes' : 'no';

            $wc_settings['enabled'] = $sync_value;
            update_option( $wc_settings_key, $wc_settings );

            $migrated[] = sprintf( '%s=%s (from %s)', $gateway_id, $sync_value, $old_option_key );
        }

        if ( ! empty( $migrated ) && class_exists( '\YangSheep\ShoplinePayment\Utils\YSLogger' ) ) {
            \YangSheep\ShoplinePayment\Utils\YSLogger::info( 'v3.5.14 migration: synced gateway enabled to WC native settings', array(
                'migrated' => $migrated,
            ) );
        }
    }

    /**
     * Initialize plugin.
     */
    public function init() {
        // Check if WooCommerce is active
        if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
            add_action( 'admin_notices', array( $this, 'woocommerce_missing_notice' ) );
            return;
        }

        // Load text domain
        load_plugin_textdomain( 'ys-shopline-via-woocommerce', false, dirname( YS_SHOPLINE_PLUGIN_BASENAME ) . '/languages' );

        // 所有類別由 Composer PSR-4 autoloader 載入，不需 require_once

        // Initialize subscription handler if WooCommerce Subscriptions is active
        if ( class_exists( 'WC_Subscriptions' ) ) {
            YSSubscription::init();
        }

        // Initialize customer management (儲存卡管理)
        YSCustomer::instance();

        // Initialize wp-admin user payment overview.
        if ( is_admin() ) {
            YSUserCardsAdmin::init();
            YSOrderPaymentAdmin::init();
        }

        // Initialize order display enhancements (付款狀態顯示)
        YSOrderDisplay::instance();

        // Initialize the attempt-aware payment-confirmation lifecycle.
        YSPaymentConfirmation::init();

        // Initialize redirect handler (付款完成後的跳轉查詢)
        YSRedirectHandler::init();

        // Initialize add payment method handler (新增卡片的 3DS 回調)
        YSAddPaymentMethodHandler::init();

        // Initialize webhook handler (REST API)
        YSWebhookHandler::init();

        // Initialize status manager
        YSStatusManager::init();

        // v3.5.2: Blocks 支援整體停用（Codex F3）
        // 既然 canMakePayment=false，在 Blocks registry 註冊只會造成 UI 空殼。
        // 類別保留但不初始化，未來完整支援時可再啟用。
        // YSBlocksSupport::init();

        // Register AJAX handlers
        $this->register_ajax_handlers();

        // Handle 3DS/redirect payment - 必須在主外掛中註冊，因為閘道可能還沒被實例化
        add_action( 'template_redirect', array( $this, 'handle_3ds_redirect' ), 5 );

        // v3.5.1: 訂單建立時立刻記 audit note（確保任何 WC 建單必留痕跡，
        // 即使後續 process_payment 因 fatal 沒跑也有紀錄）
        add_action( 'woocommerce_checkout_order_processed', array( $this, 'log_order_created' ), 10, 3 );
        add_action( 'woocommerce_checkout_subscription_created', array( $this, 'log_subscription_created' ), 10, 3 );
    }

    /**
     * v3.5.1: WC 建單後立刻在訂單寫 audit note。
     *
     * @param int   $order_id Order ID.
     * @param array $posted_data POST data.
     * @param \WC_Order $order Order object.
     */
    public function log_order_created( $order_id, $posted_data = array(), $order = null ) {
        if ( ! $order ) {
            $order = wc_get_order( $order_id );
        }
        if ( ! $order ) {
            return;
        }
        $gw = $order->get_payment_method();
        if ( 0 !== strpos( $gw, 'ys_shopline_' ) ) {
            return;
        }
        $order->add_order_note( sprintf(
            /* translators: 1: gateway id, 2: order total */
            __( 'Shopline 金流：訂單已由 WooCommerce 建立（gateway=%1$s，金額=%2$s）', 'ys-shopline-via-woocommerce' ),
            $gw,
            $order->get_formatted_order_total()
        ) );
    }

    /**
     * v3.5.1: 訂閱建立也記一筆（覆蓋 WooCommerce Subscriptions 情境）。
     */
    public function log_subscription_created( $subscription, $order = null, $recurring_cart = null ) {
        if ( ! $subscription || ! is_object( $subscription ) ) {
            return;
        }
        $gw = method_exists( $subscription, 'get_payment_method' ) ? $subscription->get_payment_method() : '';
        if ( 0 !== strpos( (string) $gw, 'ys_shopline_' ) ) {
            return;
        }
        $subscription->add_order_note( sprintf(
            /* translators: %s: gateway id */
            __( 'Shopline 金流：訂閱已建立（gateway=%s），等待首次扣款', 'ys-shopline-via-woocommerce' ),
            $gw
        ) );
    }

    /**
     * Register AJAX handlers.
     */
    private function register_ajax_handlers() {
        // SDK config AJAX - will be handled by individual gateways
        add_action( 'wp_ajax_ys_shopline_get_sdk_config', array( $this, 'ajax_get_sdk_config' ) );
        add_action( 'wp_ajax_nopriv_ys_shopline_get_sdk_config', array( $this, 'ajax_get_sdk_config' ) );

        // Pay-for-order AJAX（重新付款頁面用）
        add_action( 'wp_ajax_ys_shopline_pay_for_order', array( $this, 'ajax_pay_for_order' ) );
        add_action( 'wp_ajax_nopriv_ys_shopline_pay_for_order', array( $this, 'ajax_pay_for_order' ) );

        // Add payment method AJAX（綁卡；同頁原 SDK 實例延續 3DS，不跳獨立頁）
        add_action( 'wp_ajax_ys_shopline_add_payment_method', array( $this, 'ajax_add_payment_method' ) );
    }

    /**
     * Handle 3DS redirect for Shopline payments.
     *
     * 這個方法在主外掛中執行，確保即使閘道實例還沒建立也能處理 3DS 跳轉。
     */
    public function handle_3ds_redirect() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ! isset( $_GET['ys_shopline_pay'] ) || ! isset( $_GET['order_id'] ) || ! isset( $_GET['key'] ) ) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $order_id = absint( $_GET['order_id'] );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $key = sanitize_text_field( wp_unslash( $_GET['key'] ) );

        YSLogger::debug( 'handle_3ds_redirect triggered (main plugin)', array(
            'order_id' => $order_id,
        ) );

        $order = wc_get_order( $order_id );

        if ( ! $order || $order->get_order_key() !== $key ) {
            YSLogger::error( 'Invalid order in 3DS redirect', array(
                'order_id' => $order_id,
                'key'      => $key,
            ) );
            wp_die( esc_html__( 'Invalid Order.', 'ys-shopline-via-woocommerce' ) );
        }

        // 檢查是否為 Shopline 閘道
        $payment_method = $order->get_payment_method();
        if ( strpos( $payment_method, 'ys_shopline' ) !== 0 ) {
            YSLogger::debug( 'Not a Shopline gateway, skipping', array(
                'payment_method' => $payment_method,
            ) );
            return;
        }

        $next_action = $order->get_meta( \YangSheep\ShoplinePayment\Utils\YSOrderMeta::NEXT_ACTION );

        YSLogger::debug( 'Next action check', array(
            'has_next_action' => ! empty( $next_action ) ? 'yes' : 'no',
            'payment_method'  => $payment_method,
        ) );

        if ( ! $next_action ) {
            // No next action, redirect to thank you page
            YSLogger::debug( 'No next action, redirecting to order-received page' );
            wp_safe_redirect( $order->get_checkout_order_received_url() );
            exit;
        }

        // Render 3DS page
        YSLogger::debug( 'Rendering 3DS page from main plugin' );
        $this->render_3ds_page( $order, $next_action );
        exit;
    }

    /**
     * Render 3DS payment page.
     *
     * @param WC_Order $order       Order object.
     * @param array    $next_action Next action data from API.
     */
    private function render_3ds_page( $order, $next_action ) {
        $return_url = $order->get_checkout_order_received_url();

        // Get credentials
        $testmode = 'yes' === get_option( 'ys_shopline_testmode', 'yes' );
        if ( $testmode ) {
            $client_key  = get_option( 'ys_shopline_sandbox_client_key', '' );
            $merchant_id = get_option( 'ys_shopline_sandbox_merchant_id', '' );
        } else {
            $client_key  = get_option( 'ys_shopline_client_key', '' );
            $merchant_id = get_option( 'ys_shopline_merchant_id', '' );
        }

        $env      = $testmode ? 'sandbox' : 'production';
        $amount   = self::get_formatted_amount( $order->get_total(), $order->get_currency() );
        $currency = $order->get_currency();

        // 檢查 nextAction 類型
        // 如果是 Confirm 類型，表示需要重新輸入卡片資訊，無法在此頁面處理
        // 因為 SDK 實例不保留原始的卡片資訊
        $next_action_type = isset( $next_action['type'] ) ? $next_action['type'] : '';
        $checkout_url     = wc_get_checkout_url();

        if ( 'Confirm' === $next_action_type ) {
            // Confirm 類型需要重新在結帳頁面處理
            ?>
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                <title><?php esc_html_e( 'Payment Session Expired', 'ys-shopline-via-woocommerce' ); ?></title>
                <style>
                    body {
                        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
                        display: flex;
                        justify-content: center;
                        align-items: center;
                        min-height: 100vh;
                        margin: 0;
                        background: #f5f5f5;
                    }
                    .container {
                        text-align: center;
                        padding: 40px;
                        background: white;
                        border-radius: 8px;
                        box-shadow: 0 2px 10px rgba(0,0,0,0.1);
                        max-width: 500px;
                        width: 90%;
                    }
                    .icon { font-size: 48px; margin-bottom: 20px; }
                    .btn {
                        display: inline-block;
                        background: #3498db;
                        color: white;
                        padding: 12px 24px;
                        border-radius: 4px;
                        text-decoration: none;
                        margin-top: 20px;
                    }
                    .btn:hover { background: #2980b9; }
                </style>
            </head>
            <body>
                <div class="container">
                    <div class="icon">⚠️</div>
                    <h2><?php esc_html_e( 'Payment Session Expired', 'ys-shopline-via-woocommerce' ); ?></h2>
                    <p><?php esc_html_e( 'Your payment session has expired. Please return to checkout and try again.', 'ys-shopline-via-woocommerce' ); ?></p>
                    <a href="<?php echo esc_url( $checkout_url ); ?>" class="btn">
                        <?php esc_html_e( 'Return to Checkout', 'ys-shopline-via-woocommerce' ); ?>
                    </a>
                </div>
            </body>
            </html>
            <?php
            return;
        }

        // 對於 3DS/Redirect 類型，嘗試處理（但可能仍會失敗）
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title><?php esc_html_e( 'Processing Payment...', 'ys-shopline-via-woocommerce' ); ?></title>
            <script src="https://cdn.shoplinepayments.com/sdk/v1/payment-web.js"></script>
            <style>
                body {
                    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
                    display: flex;
                    justify-content: center;
                    align-items: center;
                    min-height: 100vh;
                    margin: 0;
                    background: #f5f5f5;
                }
                .container {
                    text-align: center;
                    padding: 40px;
                    background: white;
                    border-radius: 8px;
                    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
                    max-width: 500px;
                    width: 90%;
                }
                .spinner {
                    border: 3px solid #f3f3f3;
                    border-top: 3px solid #3498db;
                    border-radius: 50%;
                    width: 40px;
                    height: 40px;
                    animation: spin 1s linear infinite;
                    margin: 20px auto;
                }
                @keyframes spin {
                    0% { transform: rotate(0deg); }
                    100% { transform: rotate(360deg); }
                }
                .error { color: #e74c3c; margin-top: 20px; }
                #paymentContainer { margin-top: 20px; }
                .btn {
                    display: inline-block;
                    background: #3498db;
                    color: white;
                    padding: 12px 24px;
                    border-radius: 4px;
                    text-decoration: none;
                    margin-top: 20px;
                }
            </style>
        </head>
        <body>
            <div class="container">
                <h2><?php esc_html_e( 'Processing Payment', 'ys-shopline-via-woocommerce' ); ?></h2>
                <div class="spinner"></div>
                <p><?php esc_html_e( 'Please wait while we process your payment...', 'ys-shopline-via-woocommerce' ); ?></p>
                <div id="paymentContainer"></div>
                <div id="errorMessage" class="error" style="display:none;"></div>
            </div>
            <script>
                var nextAction = <?php echo wp_json_encode( $next_action ); ?>;
                var returnUrl = <?php echo wp_json_encode( $return_url ); ?>;
                var checkoutUrl = <?php echo wp_json_encode( $checkout_url ); ?>;
                var clientKey = <?php echo wp_json_encode( $client_key ); ?>;
                var merchantId = <?php echo wp_json_encode( $merchant_id ); ?>;
                var env = <?php echo wp_json_encode( $env ); ?>;
                var amount = <?php echo (int) $amount; ?>;
                var currency = <?php echo wp_json_encode( $currency ); ?>;

                console.log('3DS Page Loaded', {
                    nextActionType: nextAction && nextAction.type ? nextAction.type : 'unknown',
                    nextActionKeys: nextAction ? Object.keys(nextAction) : [],
                    env: env,
                    amount: amount,
                    currency: currency
                });

                async function processPayment() {
                    try {
                        console.log('Initializing SDK for 3DS...');

                        var result = await ShoplinePayments({
                            clientKey: clientKey,
                            merchantId: merchantId,
                            paymentMethod: 'CreditCard',
                            element: '#paymentContainer',
                            env: env,
                            currency: currency,
                            amount: amount
                        });

                        console.log('SDK initialized, hasError=' + !!(result && result.error));

                        if (result.error) {
                            showError('SDK Error: ' + sanitizeMsg(result.error.message));
                            return;
                        }

                        console.log('Calling payment.pay() with nextAction...');
                        var payResult = await result.payment.pay(nextAction);

                        console.log('pay() returned, hasError=' + !!(payResult && payResult.error));

                        if (payResult && payResult.error) {
                            showError('Payment Failed: ' + sanitizeMsg(payResult.error.message));
                        } else {
                            // Success - redirect to thank you page
                            console.log('Payment successful, redirecting to:', returnUrl);
                            window.location.href = returnUrl;
                        }

                    } catch (e) {
                        console.error('Payment error:', e);
                        showError('System Error: ' + sanitizeMsg(e.message));
                    }
                }

                /**
                 * v3.5.9: 3DS inline page 的訊息遮罩（複製 ShoplineCheckout.sanitizeErrorMessage 邏輯，
                 * 因為 inline JS 在獨立 page，沒有 ShoplineCheckout global scope）。
                 * 詳見 README v3.5.7 / v3.5.8：SHOPLINE error message 含 customer/instrument/trade ID 明文。
                 */
                function sanitizeMsg(s) {
                    if (typeof s !== 'string') return s;
                    var KEYS = 'customer|instrument|paymentInstrument|paymentCustomer|tradeOrder|channelDeal';
                    var re = new RegExp('\\b(' + KEYS + ')(Id)?(\\s*[:=]\\s*|\\s*\\[\\s*)(\\d{10,})(\\s*\\])?', 'gi');
                    return s.replace(re, function (_, k, idS, sep, d, rb) {
                        return k + (idS || '') + sep + '*' + d.slice(-6) + (rb || '');
                    });
                }

                function showError(message) {
                    document.querySelector('.spinner').style.display = 'none';
                    var errorEl = document.getElementById('errorMessage');
                    errorEl.textContent = message;
                    var backLink = document.createElement('a');
                    backLink.href = checkoutUrl;
                    backLink.className = 'btn';
                    backLink.textContent = 'Return to Checkout';
                    errorEl.appendChild(document.createElement('br'));
                    errorEl.appendChild(backLink);
                    errorEl.style.display = 'block';
                }

                // Start processing
                processPayment();
            </script>
        </body>
        </html>
        <?php
    }

    /**
     * AJAX handler for SDK config.
     */
    public function ajax_get_sdk_config() {
        check_ajax_referer( 'ys_shopline_nonce', 'nonce' );

        // Accept both 'gateway' and 'gateway_id' for flexibility
        $gateway_id = '';
        if ( isset( $_POST['gateway'] ) ) {
            $gateway_id = sanitize_text_field( wp_unslash( $_POST['gateway'] ) );
        } elseif ( isset( $_POST['gateway_id'] ) ) {
            $gateway_id = sanitize_text_field( wp_unslash( $_POST['gateway_id'] ) );
        }

        if ( empty( $gateway_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Gateway ID is required.', 'ys-shopline-via-woocommerce' ) ) );
        }

        // Get the gateway instance
        $gateways = WC()->payment_gateways()->payment_gateways();

        if ( ! isset( $gateways[ $gateway_id ] ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid gateway.', 'ys-shopline-via-woocommerce' ) ) );
        }

        $gateway = $gateways[ $gateway_id ];

        if ( ! $this->is_gateway_enabled_for_context( $gateway ) ) {
            wp_send_json_error( array( 'message' => __( '付款閘道未啟用。', 'ys-shopline-via-woocommerce' ) ) );
        }

        if ( ! method_exists( $gateway, 'get_sdk_config' ) ) {
            wp_send_json_error( array( 'message' => __( 'Gateway does not support SDK config.', 'ys-shopline-via-woocommerce' ) ) );
        }

        $config = $gateway->get_sdk_config();
        wp_send_json_success( $config );
    }

    /**
     * AJAX handler: 新增付款方式（綁卡）
     *
     * 委派到 ys_shopline_credit 閘道的 ajax_add_payment_method() 完成 CardBind 綁卡 API。
     * 前端拿到回應 nextAction 後，使用**原 SDK 實例**執行 `payment.pay(nextAction)`，
     * SDK 自己跳至 returnUrl（由 YSAddPaymentMethodHandler::handle_add_method_redirect() 建 Token）。
     *
     * 不再跳獨立 3DS 頁（因為跨頁會丟失 PCI session）。
     */
    public function ajax_add_payment_method() {
        $gateways = WC()->payment_gateways()->payment_gateways();
        $gateway  = isset( $gateways['ys_shopline_credit'] ) ? $gateways['ys_shopline_credit'] : null;

        if ( ! $gateway || ! method_exists( $gateway, 'ajax_add_payment_method' ) ) {
            wp_send_json_error( array( 'message' => __( '付款閘道未啟用。', 'ys-shopline-via-woocommerce' ) ) );
        }

        if ( ! $this->is_gateway_enabled_for_context( $gateway ) ) {
            wp_send_json_error( array( 'message' => __( '付款閘道未啟用。', 'ys-shopline-via-woocommerce' ) ) );
        }

        $gateway->ajax_add_payment_method();
    }

    /**
     * AJAX handler for pay-for-order（重新付款頁面）.
     *
     * Pay-for-order 頁面使用 #order_review 表單，無法走 WooCommerce checkout AJAX，
     * 需要獨立的 AJAX endpoint 來呼叫 process_payment() 並回傳 nextAction。
     */
    public function ajax_pay_for_order() {
        check_ajax_referer( 'ys_shopline_nonce', 'nonce' );

        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $order_id       = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $payment_method = isset( $_POST['payment_method'] ) ? sanitize_text_field( wp_unslash( $_POST['payment_method'] ) ) : '';

        $order = wc_get_order( $order_id );

        if ( ! $order ) {
            wp_send_json( array(
                'result'   => 'failure',
                'messages' => __( '訂單不存在。', 'ys-shopline-via-woocommerce' ),
            ) );
            return;
        }

        // 驗證訂單存取權限：登入用戶比對 user_id，訪客比對 order_key
        $current_user_id = get_current_user_id();
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $order_key       = isset( $_POST['order_key'] ) ? sanitize_text_field( wp_unslash( $_POST['order_key'] ) ) : '';
        $is_owner        = $current_user_id && (int) $order->get_user_id() === $current_user_id;
        $is_guest_valid  = ! $current_user_id && 0 === (int) $order->get_user_id() && $order_key && $order->get_order_key() === $order_key;

        if ( ! $is_owner && ! $is_guest_valid ) {
            wp_send_json( array(
                'result'   => 'failure',
                'messages' => __( '無權存取此訂單。', 'ys-shopline-via-woocommerce' ),
            ) );
            return;
        }

        if ( YSPaymentConfirmation::STATUS_KEY === $order->get_status()
            || ! empty( YSPaymentConfirmation::get_active_attempt( $order ) ) ) {
            wp_send_json( array(
                'result'   => 'failure',
                'messages' => YSPaymentConfirmation::confirmation_message(),
            ) );
            return;
        }

        if ( ! $order->needs_payment() ) {
            wp_send_json( array(
                'result'   => 'failure',
                'messages' => __( '訂單不需要付款。', 'ys-shopline-via-woocommerce' ),
            ) );
            return;
        }

        // 取得閘道
        $gateways = WC()->payment_gateways()->payment_gateways();
        if ( ! isset( $gateways[ $payment_method ] ) ) {
            wp_send_json( array(
                'result'   => 'failure',
                'messages' => __( '無效的付款方式。', 'ys-shopline-via-woocommerce' ),
            ) );
            return;
        }

        $gateway = $gateways[ $payment_method ];

        if ( ! $this->is_gateway_enabled_for_context( $gateway ) ) {
            wp_send_json( array(
                'result'   => 'failure',
                'messages' => __( '付款閘道未啟用。', 'ys-shopline-via-woocommerce' ),
            ) );
            return;
        }

        // v3.5.35: 訂單級互斥 — 同一張訂單的 cancel→query→create 鏈一次只允許一條
        //（雙分頁、連點、併發重試都會被擋在這裡）
        if ( ! $this->acquire_order_payment_lock( $order_id ) ) {
            wp_send_json( array(
                'result'   => 'failure',
                'messages' => __( '另一筆付款請求正在處理中，請稍候片刻再試。', 'ys-shopline-via-woocommerce' ),
            ) );
            return;
        }

        // ATM 同方式重用：必須在 resolve 之前判斷，否則待付款的 VA 交易會被自動取消。
        // v3.5.35: 重用前以遠端實況驗證（本地 meta 可能被失敗的變更嘗試污染）
        $existing_offline_payment = $this->maybe_reuse_existing_offline_payment( $order, $payment_method, $gateway );
        if ( null !== $existing_offline_payment ) {
            wp_send_json( $existing_offline_payment );
            return;
        }

        // v3.5.35 P0 修正：先解決前次交易，確認可以重新付款「之後」才允許任何本地改寫。
        // 過去順序（先儲存新 gateway → 再判斷舊交易）會讓失敗的變更嘗試污染
        // payment method，進而誤把其他金流的有效交易當 ATM 清掉 → 產生兩筆可付款交易。
        $resolution = $gateway->resolve_prior_trade( $order );

        if ( 'paid' === $resolution['action'] ) {
            wp_send_json( array(
                'result'   => 'success',
                'redirect' => isset( $resolution['redirect'] ) ? $resolution['redirect'] : $gateway->get_return_url( $order ),
            ) );
            return;
        }

        if ( 'blocked' === $resolution['action'] ) {
            // fail-closed：不改寫付款方式、不動任何本地 meta
            wp_send_json( array(
                'result'   => 'failure',
                'messages' => isset( $resolution['message'] ) ? $resolution['message'] : __( '前次付款尚未完成，請稍候再試。', 'ys-shopline-via-woocommerce' ),
            ) );
            return;
        }

        // action === 'allow'：前次交易已解決（meta 已由 resolver 清除），本地改寫安全

        // ATM 保留單回到待付款（process_payment 會把 on-hold 視為已付款早退）
        if ( 'on-hold' === $order->get_status() && 'yes' !== $order->get_meta( YSOrderMeta::PAYMENT_AUTHORIZED_PENDING ) ) {
            $order->update_status( 'pending', __( '顧客變更付款方式，訂單回到待付款。', 'ys-shopline-via-woocommerce' ) );
        }

        // v3.5.36 P0: 付款方式延後提交＋失敗回復 — 依 process_payment 明確回傳的 remote_outcome
        //（accepted / rejected / unknown）完整 switch 分流，未知值一律 fail-closed（不還原、不重試建新）。
        $previous_method = $order->get_payment_method();
        $previous_title  = $order->get_payment_method_title();
        $order->set_payment_method( $gateway );
        $order->save();

        // 執行付款
        $result = $gateway->process_payment( $order_id );

        $outcome = ( is_array( $result ) && isset( $result['remote_outcome'] ) ) ? (string) $result['remote_outcome'] : '';
        $order_after_payment = wc_get_order( $order_id );

        if ( 'rejected' === $outcome ) {
            // 確定未建立遠端交易 → 完整還原付款方式 ID＋title（含清空、含同 ID 不同 title），
            // 避免失敗嘗試污染訂單；收攏原因以頁內 failure 呈現。
            $order_fresh = wc_get_order( $order_id );
            if ( $order_fresh ) {
                $order_fresh->set_payment_method( (string) $previous_method );
                $order_fresh->set_payment_method_title( (string) $previous_title );
                $order_fresh->save();
                if ( $previous_method !== $payment_method ) {
                    $order_fresh->add_order_note( sprintf(
                        /* translators: 1: rejected gateway id, 2: restored gateway id */
                        __( 'Shopline 金流：以 %1$s 重新付款被金流拒絕（未建立交易），付款方式回復為 %2$s。', 'ys-shopline-via-woocommerce' ),
                        $payment_method,
                        '' !== (string) $previous_method ? $previous_method : '（無）'
                    ) );
                }
            }

            $messages = ( is_array( $result ) && ! empty( $result['messages'] ) ) ? (string) $result['messages'] : '';
            if ( '' === $messages ) {
                $messages = $this->drain_wc_error_notices();
            }
            $result = array(
                'result'   => 'failure',
                'messages' => '' !== $messages ? $messages : __( '付款被拒，請重試或改用其他付款方式。', 'ys-shopline-via-woocommerce' ),
            );
        } elseif ( 'unknown' === $outcome
            && is_array( $result )
            && 'success' === ( $result['result'] ?? '' )
            && ! empty( $result['redirect'] )
            && $order_after_payment instanceof \WC_Order
            && ( $order_after_payment->is_paid()
                || YSPaymentConfirmation::STATUS_KEY === $order_after_payment->get_status()
                || ! empty( YSPaymentConfirmation::get_active_attempt( $order_after_payment ) ) ) ) {
            // The remote result is unknown, but the order is durably locked in
            // payment confirmation. Preserve the neutral status-page redirect.
        } elseif ( 'accepted' !== $outcome ) {
            // unknown 或未知值（fail-closed）：遠端可能已建立交易 → **不還原付款方式**（還原會孤兒化交易），
            // 不視為可重試失敗；以「確認中」訊息呈現，後續由 webhook / query_session 收斂。
            $messages = ( is_array( $result ) && ! empty( $result['messages'] ) ) ? (string) $result['messages'] : '';
            if ( '' === $messages ) {
                $messages = $this->drain_wc_error_notices();
            }
            $result = array(
                'result'   => 'failure',
                'messages' => '' !== $messages ? $messages : __( '付款結果確認中，請勿重複付款；若已扣款我們會自動更新訂單。', 'ys-shopline-via-woocommerce' ),
            );
        }
        // 'accepted'：原樣回傳 process_payment 結果（success / redirect / nextAction）。

        wp_send_json( $result );
    }

    /**
     * v3.5.35: 取得訂單級付款互斥鎖（MySQL GET_LOCK）。
     *
     * wp_send_json 以 die() 結束、不會走正常 return 路徑，因此以
     * register_shutdown_function 保底釋放；非持久連線在請求結束時
     * MySQL 也會自動釋放同連線持有的 named lock。
     *
     * @param int $order_id 訂單 ID。
     * @return bool 是否取得鎖。
     */
    private function acquire_order_payment_lock( $order_id ) {
        global $wpdb;

        $lock_name = 'ys_slp_pay_' . absint( $order_id );
        $acquired  = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock_name, 0 ) );

        if ( '1' !== (string) $acquired ) {
            YSLogger::warning( 'Order payment lock busy', array( 'order_id' => $order_id ) );
            return false;
        }

        register_shutdown_function( static function () use ( $lock_name ) {
            global $wpdb;
            if ( $wpdb instanceof \wpdb ) {
                $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
            }
        } );

        return true;
    }

    /**
     * v3.5.35: 收攏 WC session 中的 error notices 為純文字（並清空全部 notices）。
     *
     * 本外掛的 order-pay AJAX 端點沒有 WooCommerce checkout 的
     * send_ajax_failure_response 轉運，若不收攏，錯誤原因會滯留 session、
     * 前端只看得到通用錯誤，且下一頁還會噴出殘留 notice。
     *
     * @return string 純文字錯誤訊息（多則以換行分隔），無則空字串。
     */
    private function drain_wc_error_notices() {
        if ( ! function_exists( 'wc_get_notices' ) || ! WC()->session ) {
            return '';
        }

        $notices = wc_get_notices( 'error' );
        $texts   = array();

        foreach ( (array) $notices as $notice ) {
            $text = '';
            if ( is_array( $notice ) && isset( $notice['notice'] ) ) {
                $text = (string) $notice['notice'];
            } elseif ( is_string( $notice ) ) {
                $text = $notice;
            }
            $text = trim( wp_strip_all_tags( $text ) );
            if ( '' !== $text ) {
                $texts[] = $text;
            }
        }

        wc_clear_notices();

        return implode( "\n", $texts );
    }

    /**
     * Reuse an existing ATM virtual account when the customer re-selects ATM.
     *
     * @param \WC_Order          $order          Order object.
     * @param string             $payment_method Newly selected payment method.
     * @param WC_Payment_Gateway $gateway        Selected gateway.
     * @return array|null
     */
    private function maybe_reuse_existing_offline_payment( $order, $payment_method, $gateway ) {
        if ( ! $this->should_reuse_existing_atm_payment( $order, $payment_method ) ) {
            return null;
        }

        // v3.5.35: 本地 meta 可能被歷史失敗嘗試污染（payment method 曾被先寫後失敗）
        // — 重用前必以遠端實況確認交易確為 VirtualAccount 且仍待顧客付款；
        // 驗證不過就交給 resolve_prior_trade 統一處理（取消或封鎖），不得盲目重用。
        if ( ! ( $gateway instanceof YSGatewayBase ) || ! $gateway->verify_reusable_offline_trade( $order ) ) {
            YSLogger::warning( 'ATM reuse skipped: remote trade verification failed or mismatched', array(
                'order_id' => $order->get_id(),
            ) );
            return null;
        }

        return array(
            'result'               => 'success',
            'redirect'             => $gateway->get_return_url( $order ),
            'reusedOfflinePayment' => 'atm',
        );
    }

    /**
     * Determine whether an ATM order should keep its current virtual account.
     *
     * @param \WC_Order $order          Order object.
     * @param string    $payment_method Newly selected payment method.
     * @return bool
     */
    private function should_reuse_existing_atm_payment( $order, $payment_method ) {
        if ( ! $order || 'ys_shopline_atm' !== $order->get_payment_method() || 'ys_shopline_atm' !== $payment_method ) {
            return false;
        }

        if ( ! in_array( $order->get_status(), array( 'pending', 'on-hold' ), true ) ) {
            return false;
        }

        if ( 'yes' === $order->get_meta( YSOrderMeta::PAYMENT_AUTHORIZED_PENDING ) ) {
            return false;
        }

        if ( ! $order->get_meta( YSOrderMeta::VA_ACCOUNT ) || ! $order->get_meta( YSOrderMeta::TRADE_ORDER_ID ) ) {
            return false;
        }

        return ! $this->is_existing_atm_payment_expired( $order );
    }

    /**
     * Check whether the stored ATM virtual account has expired.
     *
     * @param \WC_Order $order Order object.
     * @return bool
     */
    private function is_existing_atm_payment_expired( $order ) {
        $expire = (string) $order->get_meta( YSOrderMeta::VA_EXPIRE );
        if ( '' === $expire ) {
            return false;
        }

        $expire_timestamp = strtotime( $expire );
        if ( ! $expire_timestamp ) {
            return false;
        }

        return $expire_timestamp <= time();
    }

    /**
     * Check whether a WooCommerce gateway is enabled and available in current context.
     *
     * Plugin-level switches decide registration only; WooCommerce's native
     * gateway setting remains authoritative for checkout/add-card execution.
     *
     * @param mixed $gateway Gateway instance.
     * @return bool
     */
    private function is_gateway_enabled_for_context( $gateway ) {
        if ( ! $gateway || ! is_a( $gateway, 'WC_Payment_Gateway' ) ) {
            return false;
        }

        if ( 'yes' !== $gateway->enabled ) {
            return false;
        }

        return ! method_exists( $gateway, 'is_available' ) || $gateway->is_available();
    }

    /**
     * Register payment gateways.
     *
     * @param array $gateways Registered gateways.
     * @return array
     */
    public function register_gateways( $gateways ) {
        if ( 'yes' !== get_option( 'ys_shopline_enabled', 'yes' ) ) {
            return $gateways;
        }

        // Credit Card (single payment)
        if ( 'yes' === get_option( 'ys_shopline_credit_enabled', 'yes' ) ) {
            $gateways[] = YSCreditCard::class;
        }

        // Credit Card Installment
        if ( 'yes' === get_option( 'ys_shopline_credit_installment_enabled', 'no' ) ) {
            $gateways[] = YSCreditInstallment::class;
        }

        // Credit Card Subscription
        if ( 'yes' === get_option( 'ys_shopline_credit_subscription_enabled', 'yes' ) && class_exists( 'WC_Subscriptions' ) ) {
            $gateways[] = YSCreditSubscription::class;
        }

        // ATM Virtual Account
        if ( 'yes' === get_option( 'ys_shopline_atm_enabled', 'yes' ) ) {
            $gateways[] = YSVirtualAccount::class;
        }

        // JKOPay
        if ( 'yes' === get_option( 'ys_shopline_jkopay_enabled', 'yes' ) ) {
            $gateways[] = YSJKOPay::class;
        }

        // Apple Pay
        if ( 'yes' === get_option( 'ys_shopline_applepay_enabled', 'yes' ) ) {
            $gateways[] = YSApplePay::class;
        }

        // LINE Pay
        if ( 'yes' === get_option( 'ys_shopline_linepay_enabled', 'yes' ) ) {
            $gateways[] = YSLinePay::class;
        }

        // Chailease BNPL
        if ( 'yes' === get_option( 'ys_shopline_bnpl_enabled', 'yes' ) ) {
            $gateways[] = YSChaileaseBNPL::class;
        }

        return $gateways;
    }

    /**
     * Add plugin action links.
     *
     * @param array $links Plugin action links.
     * @return array
     */
    public function plugin_action_links( $links ) {
        $settings_link = sprintf(
            '<a href="%s">%s</a>',
            admin_url( 'admin.php?page=ys-shopline-payment' ),
            __( '設定', 'ys-shopline-via-woocommerce' )
        );

        array_unshift( $links, $settings_link );

        return $links;
    }

    /**
     * WooCommerce missing notice.
     */
    public function woocommerce_missing_notice() {
        ?>
        <div class="notice notice-error">
            <p>
                <?php
                printf(
                    /* translators: %s: WooCommerce plugin name */
                    esc_html__( '%s requires WooCommerce to be installed and active.', 'ys-shopline-via-woocommerce' ),
                    '<strong>YS Shopline Payment</strong>'
                );
                ?>
            </p>
        </div>
        <?php
    }

    /**
     * Get API instance.
     *
     * @return YSApi|null
     */
    public static function get_api() {
        $api = new YSApi();

        return $api->has_credentials() ? $api : null;
    }

    /**
     * Helper: Get formatted amount for Shopline API.
     *
     * Shopline API 要求所有金額都要 ×100（包含 TWD）
     * 例如：1 元 TWD 傳入 100
     *
     * @param float  $amount   Amount to format.
     * @param string $currency Currency code.
     * @return int
     */
    public static function get_formatted_amount( $amount, $currency ) {
        // Shopline API 要求所有金額都乘以 100（無小數）
        // TWD 1元 = 100, USD $1.00 = 100
        return (int) round( $amount * 100 );
    }

    /**
     * Helper: Get amount for SDK initialization.
     *
     * SDK 初始化也需要 ×100（與 API 相同）
     * 例如：1 元 TWD 傳入 100
     *
     * @param float $amount Amount.
     * @return int
     */
    public static function get_sdk_amount( $amount ) {
        // SDK 初始化也需要乘以 100
        // TWD 1元 = 100, USD $1.00 = 100
        return (int) round( $amount * 100 );
    }
}

/**
 * Get plugin instance.
 *
 * @return YSShoplinePayment
 */
function ys_shopline_payment() {
    return YSShoplinePayment::instance();
}

// Initialize the plugin
ys_shopline_payment();
