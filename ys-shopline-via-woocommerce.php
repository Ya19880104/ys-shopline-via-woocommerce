<?php
/**
 * Plugin Name: YS Shopline via WooCommerce
 * Plugin URI: https://yangsheep.com
 * Description: Support Shopline Payments for WooCommerce, including HPOS and Subscriptions. Supports Credit Card, ATM, JKOPay, Apple Pay, LINE Pay, and Chailease BNPL.
 * Version: 2.0.0
 * Author: YangSheep
 * Author URI: https://yangsheep.com
 * Text Domain: ys-shopline-via-woocommerce
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 * WC tested up to: 9.0
 */

defined( 'ABSPATH' ) || exit;

// Define plugin constants
define( 'YS_SHOPLINE_VERSION', '2.0.0' );
define( 'YS_SHOPLINE_PLUGIN_FILE', __FILE__ );
define( 'YS_SHOPLINE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'YS_SHOPLINE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'YS_SHOPLINE_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Main plugin class.
 */
final class YS_Shopline_Payment {

    /**
     * Plugin instance.
     *
     * @var YS_Shopline_Payment
     */
    private static $instance = null;

    /**
     * Get plugin instance.
     *
     * @return YS_Shopline_Payment
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
        $this->includes();
        $this->init_hooks();
    }

    /**
     * Include required files.
     */
    private function includes() {
        // Core classes - always load (they don't depend on WooCommerce)
        require_once YS_SHOPLINE_PLUGIN_DIR . 'includes/class-ys-shopline-loader.php';
        require_once YS_SHOPLINE_PLUGIN_DIR . 'includes/class-ys-shopline-logger.php';
        require_once YS_SHOPLINE_PLUGIN_DIR . 'includes/class-ys-shopline-api.php';
        require_once YS_SHOPLINE_PLUGIN_DIR . 'includes/class-ys-shopline-webhook-handler.php';
    }

    /**
     * Include WooCommerce-dependent files.
     * Called after WooCommerce is loaded.
     */
    private function includes_wc() {
        // Gateway base class
        require_once YS_SHOPLINE_PLUGIN_DIR . 'includes/gateways/class-ys-shopline-gateway-base.php';

        // Payment gateways
        require_once YS_SHOPLINE_PLUGIN_DIR . 'includes/gateways/class-ys-shopline-credit-card.php';
        require_once YS_SHOPLINE_PLUGIN_DIR . 'includes/gateways/class-ys-shopline-credit-subscription.php';
        require_once YS_SHOPLINE_PLUGIN_DIR . 'includes/gateways/class-ys-shopline-virtual-account.php';
        require_once YS_SHOPLINE_PLUGIN_DIR . 'includes/gateways/class-ys-shopline-jkopay.php';
        require_once YS_SHOPLINE_PLUGIN_DIR . 'includes/gateways/class-ys-shopline-applepay.php';
        require_once YS_SHOPLINE_PLUGIN_DIR . 'includes/gateways/class-ys-shopline-linepay.php';
        require_once YS_SHOPLINE_PLUGIN_DIR . 'includes/gateways/class-ys-shopline-chailease-bnpl.php';

        // Subscription handler
        require_once YS_SHOPLINE_PLUGIN_DIR . 'includes/gateways/class-ys-shopline-subscription.php';
    }

    /**
     * Initialize hooks.
     */
    private function init_hooks() {
        // Declare compatibility
        add_action( 'before_woocommerce_init', array( $this, 'declare_compatibility' ) );

        // Initialize plugin after WooCommerce is loaded
        add_action( 'plugins_loaded', array( $this, 'init' ), 11 );

        // Register payment gateways
        add_filter( 'woocommerce_payment_gateways', array( $this, 'register_gateways' ) );

        // Add settings page to WooCommerce (after WooCommerce is loaded)
        add_filter( 'woocommerce_get_settings_pages', array( $this, 'add_settings_page' ) );

        // Add settings link on plugins page
        add_filter( 'plugin_action_links_' . YS_SHOPLINE_PLUGIN_BASENAME, array( $this, 'plugin_action_links' ) );
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

            // Cart and Checkout Blocks - not compatible yet
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
                'cart_checkout_blocks',
                YS_SHOPLINE_PLUGIN_FILE,
                false
            );
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

        // Include WooCommerce-dependent files
        $this->includes_wc();

        // Load text domain
        load_plugin_textdomain( 'ys-shopline-via-woocommerce', false, dirname( YS_SHOPLINE_PLUGIN_BASENAME ) . '/languages' );

        // Initialize webhook handler
        new YS_Shopline_Webhook_Handler();

        // Initialize subscription handler if WooCommerce Subscriptions is active
        if ( class_exists( 'WC_Subscriptions' ) ) {
            YS_Shopline_Subscription::init();
        }

        // Register AJAX handlers
        $this->register_ajax_handlers();
    }

    /**
     * Add settings page to WooCommerce settings.
     *
     * @param array $settings Settings pages array.
     * @return array
     */
    public function add_settings_page( $settings ) {
        require_once YS_SHOPLINE_PLUGIN_DIR . 'includes/admin/class-ys-shopline-settings.php';
        $settings[] = new YS_Shopline_Settings();
        return $settings;
    }

    /**
     * Register AJAX handlers.
     */
    private function register_ajax_handlers() {
        // SDK config AJAX - will be handled by individual gateways
        add_action( 'wp_ajax_ys_shopline_get_sdk_config', array( $this, 'ajax_get_sdk_config' ) );
        add_action( 'wp_ajax_nopriv_ys_shopline_get_sdk_config', array( $this, 'ajax_get_sdk_config' ) );
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

        if ( ! method_exists( $gateway, 'get_sdk_config' ) ) {
            wp_send_json_error( array( 'message' => __( 'Gateway does not support SDK config.', 'ys-shopline-via-woocommerce' ) ) );
        }

        $config = $gateway->get_sdk_config();
        wp_send_json_success( $config );
    }

    /**
     * Register payment gateways.
     *
     * @param array $gateways Registered gateways.
     * @return array
     */
    public function register_gateways( $gateways ) {
        // Credit Card
        if ( 'yes' === get_option( 'ys_shopline_credit_enabled', 'yes' ) ) {
            $gateways[] = 'YS_Shopline_Credit_Card';
        }

        // Credit Card Subscription
        if ( 'yes' === get_option( 'ys_shopline_credit_subscription_enabled', 'yes' ) && class_exists( 'WC_Subscriptions' ) ) {
            $gateways[] = 'YS_Shopline_Credit_Subscription';
        }

        // ATM Virtual Account
        if ( 'yes' === get_option( 'ys_shopline_atm_enabled', 'yes' ) ) {
            $gateways[] = 'YS_Shopline_Virtual_Account';
        }

        // JKOPay
        if ( 'yes' === get_option( 'ys_shopline_jkopay_enabled', 'yes' ) ) {
            $gateways[] = 'YS_Shopline_JKOPay';
        }

        // Apple Pay
        if ( 'yes' === get_option( 'ys_shopline_applepay_enabled', 'yes' ) ) {
            $gateways[] = 'YS_Shopline_ApplePay';
        }

        // LINE Pay
        if ( 'yes' === get_option( 'ys_shopline_linepay_enabled', 'yes' ) ) {
            $gateways[] = 'YS_Shopline_LinePay';
        }

        // Chailease BNPL
        if ( 'yes' === get_option( 'ys_shopline_bnpl_enabled', 'yes' ) ) {
            $gateways[] = 'YS_Shopline_Chailease_BNPL';
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
            admin_url( 'admin.php?page=wc-settings&tab=ys_shopline_payment' ),
            __( 'Settings', 'ys-shopline-via-woocommerce' )
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
     * @return YS_Shopline_API|null
     */
    public static function get_api() {
        $test_mode = 'yes' === get_option( 'ys_shopline_testmode', 'yes' );

        // Get credentials based on test mode
        if ( $test_mode ) {
            $merchant_id = get_option( 'ys_shopline_sandbox_merchant_id', '' );
            $api_key     = get_option( 'ys_shopline_sandbox_api_key', '' );
        } else {
            $merchant_id = get_option( 'ys_shopline_merchant_id', '' );
            $api_key     = get_option( 'ys_shopline_api_key', '' );
        }

        if ( empty( $merchant_id ) || empty( $api_key ) ) {
            return null;
        }

        return new YS_Shopline_API( $merchant_id, $api_key, $test_mode );
    }

    /**
     * Helper: Get formatted amount for Shopline API.
     *
     * @param float  $amount   Amount to format.
     * @param string $currency Currency code.
     * @return int
     */
    public static function get_formatted_amount( $amount, $currency ) {
        // Zero decimal currencies
        $zero_decimal = array( 'TWD', 'JPY', 'KRW', 'CLP', 'VND' );

        if ( in_array( $currency, $zero_decimal, true ) ) {
            return (int) round( $amount );
        }

        // Other currencies: convert to minor units (cents)
        return (int) round( $amount * 100 );
    }
}

/**
 * Get plugin instance.
 *
 * @return YS_Shopline_Payment
 */
function ys_shopline_payment() {
    return YS_Shopline_Payment::instance();
}

// Initialize the plugin
ys_shopline_payment();
