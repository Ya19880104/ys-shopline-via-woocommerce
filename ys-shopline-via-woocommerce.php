<?php
/**
 * Plugin Name: YS Shopline via WooCommerce
 * Plugin URI: https://yangsheep.com
 * Description: Support Shopline Payments for WooCommerce, including HPOS and Subscriptions. Supports Credit Card, ATM, JKOPay, Apple Pay, LINE Pay, and Chailease BNPL.
 * Version: 2.0.6
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
define( 'YS_SHOPLINE_VERSION', '2.0.6' );
define( 'YS_SHOPLINE_PLUGIN_FILE', __FILE__ );
define( 'YS_SHOPLINE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'YS_SHOPLINE_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'YS_SHOPLINE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'YS_SHOPLINE_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Load Composer autoloader for new architecture
if ( file_exists( YS_SHOPLINE_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
    require_once YS_SHOPLINE_PLUGIN_DIR . 'vendor/autoload.php';
}

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
        require_once YS_SHOPLINE_PLUGIN_DIR . 'src/Gateways/class-ys-shopline-gateway-base.php';

        // Payment gateways
        require_once YS_SHOPLINE_PLUGIN_DIR . 'src/Gateways/class-ys-shopline-credit-card.php';
        require_once YS_SHOPLINE_PLUGIN_DIR . 'src/Gateways/class-ys-shopline-credit-subscription.php';
        require_once YS_SHOPLINE_PLUGIN_DIR . 'src/Gateways/class-ys-shopline-virtual-account.php';
        require_once YS_SHOPLINE_PLUGIN_DIR . 'src/Gateways/class-ys-shopline-jkopay.php';
        require_once YS_SHOPLINE_PLUGIN_DIR . 'src/Gateways/class-ys-shopline-applepay.php';
        require_once YS_SHOPLINE_PLUGIN_DIR . 'src/Gateways/class-ys-shopline-linepay.php';
        require_once YS_SHOPLINE_PLUGIN_DIR . 'src/Gateways/class-ys-shopline-chailease-bnpl.php';

        // Subscription handler
        require_once YS_SHOPLINE_PLUGIN_DIR . 'src/Gateways/class-ys-shopline-subscription.php';

        // Redirect handler - 處理付款完成後的跳轉查詢
        require_once YS_SHOPLINE_PLUGIN_DIR . 'includes/class-ys-shopline-redirect-handler.php';
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

            // Cart and Checkout Blocks - now compatible via new architecture
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
                'cart_checkout_blocks',
                YS_SHOPLINE_PLUGIN_FILE,
                true
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

        // Initialize webhook handler (legacy)
        new YS_Shopline_Webhook_Handler();

        // Initialize subscription handler if WooCommerce Subscriptions is active
        if ( class_exists( 'WC_Subscriptions' ) ) {
            YS_Shopline_Subscription::init();
        }

        // Initialize customer management (儲存卡管理)
        YS_Shopline_Customer::instance();

        // Initialize order display enhancements (付款狀態顯示)
        YS_Shopline_Order_Display::instance();

        // Register AJAX handlers
        $this->register_ajax_handlers();

        // Handle 3DS/redirect payment - 必須在主外掛中註冊，因為閘道可能還沒被實例化
        add_action( 'template_redirect', array( $this, 'handle_3ds_redirect' ), 5 );

        // Initialize new architecture components (PSR-4)
        $this->init_new_architecture();
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
     * Initialize new architecture components (PSR-4 autoloaded)
     */
    private function init_new_architecture(): void {
        // Check if autoloader is available
        if ( ! class_exists( 'YangSheep\\ShoplinePayment\\Handlers\\YSWebhookHandler' ) ) {
            return;
        }

        // 注意：YSMyAccountEndpoint 已棄用，改用 WC 內建的 payment-methods 頁面
        // 自訂 template 位於 templates/myaccount/payment-methods.php

        // Initialize new webhook handler (REST API)
        \YangSheep\ShoplinePayment\Handlers\YSWebhookHandler::init();

        // Initialize status manager
        \YangSheep\ShoplinePayment\Handlers\YSStatusManager::init();

        // Initialize WooCommerce Blocks support
        \YangSheep\ShoplinePayment\Blocks\YSBlocksSupport::init();
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

        YS_Shopline_Logger::debug( 'handle_3ds_redirect triggered (main plugin)', array(
            'order_id' => $order_id,
        ) );

        $order = wc_get_order( $order_id );

        if ( ! $order || $order->get_order_key() !== $key ) {
            YS_Shopline_Logger::error( 'Invalid order in 3DS redirect', array(
                'order_id' => $order_id,
                'key'      => $key,
            ) );
            wp_die( esc_html__( 'Invalid Order.', 'ys-shopline-via-woocommerce' ) );
        }

        // 檢查是否為 Shopline 閘道
        $payment_method = $order->get_payment_method();
        if ( strpos( $payment_method, 'ys_shopline' ) !== 0 ) {
            YS_Shopline_Logger::debug( 'Not a Shopline gateway, skipping', array(
                'payment_method' => $payment_method,
            ) );
            return;
        }

        $next_action = $order->get_meta( '_ys_shopline_next_action' );

        YS_Shopline_Logger::debug( 'Next action check', array(
            'has_next_action' => ! empty( $next_action ) ? 'yes' : 'no',
            'payment_method'  => $payment_method,
        ) );

        if ( ! $next_action ) {
            // No next action, redirect to thank you page
            YS_Shopline_Logger::debug( 'No next action, redirecting to order-received page' );
            wp_safe_redirect( $order->get_checkout_order_received_url() );
            exit;
        }

        // Render 3DS page
        YS_Shopline_Logger::debug( 'Rendering 3DS page from main plugin' );
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

                console.log('3DS Page Loaded', { nextAction: nextAction, env: env, amount: amount, currency: currency });

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

                        console.log('SDK initialized:', result);

                        if (result.error) {
                            showError('SDK Error: ' + result.error.message);
                            return;
                        }

                        console.log('Calling payment.pay() with nextAction...');
                        var payResult = await result.payment.pay(nextAction);

                        console.log('pay() result:', payResult);

                        if (payResult && payResult.error) {
                            showError('Payment Failed: ' + payResult.error.message);
                        } else {
                            // Success - redirect to thank you page
                            console.log('Payment successful, redirecting to:', returnUrl);
                            window.location.href = returnUrl;
                        }

                    } catch (e) {
                        console.error('Payment error:', e);
                        showError('System Error: ' + e.message);
                    }
                }

                function showError(message) {
                    document.querySelector('.spinner').style.display = 'none';
                    var errorEl = document.getElementById('errorMessage');
                    errorEl.innerHTML = message + '<br><a href="' + checkoutUrl + '" class="btn">Return to Checkout</a>';
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
 * @return YS_Shopline_Payment
 */
function ys_shopline_payment() {
    return YS_Shopline_Payment::instance();
}

// Initialize the plugin
ys_shopline_payment();
