<?php
/**
 * Base Gateway class for YS Shopline Payment.
 *
 * @package YS_Shopline_Payment
 */

defined( 'ABSPATH' ) || exit;

/**
 * YS_Shopline_Gateway_Base Class.
 *
 * Abstract base class for all Shopline payment gateways.
 */
abstract class YS_Shopline_Gateway_Base extends WC_Payment_Gateway {

    /**
     * API instance.
     *
     * @var YS_Shopline_API
     */
    protected $api;

    /**
     * Test mode flag.
     *
     * @var bool
     */
    protected $testmode;

    /**
     * Debug mode flag.
     *
     * @var bool
     */
    protected $debug;

    /**
     * Constructor.
     */
    public function __construct() {
        // Load settings
        $this->init_form_fields();
        $this->init_settings();

        // Define properties
        $this->title       = $this->get_option( 'title' );
        $this->description = $this->get_option( 'description' );
        $this->enabled     = $this->get_option( 'enabled' );

        // Global settings
        $this->testmode = 'yes' === get_option( 'ys_shopline_testmode', 'yes' );
        $this->debug    = 'yes' === get_option( 'ys_shopline_debug', 'no' );

        // Initialize API
        $this->init_api();

        // Hooks
        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'payment_scripts' ) );
        add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thankyou_page' ) );
        add_action( 'woocommerce_email_before_order_table', array( $this, 'email_instructions' ), 10, 3 );

        // Handle 3DS/redirect payment completion
        add_action( 'template_redirect', array( $this, 'handle_pay_redirect' ) );
    }

    /**
     * Handle payment redirect (3DS, etc.)
     */
    public function handle_pay_redirect() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ! isset( $_GET['ys_shopline_pay'] ) || ! isset( $_GET['order_id'] ) || ! isset( $_GET['key'] ) ) {
            return;
        }

        YS_Shopline_Logger::debug( 'handle_pay_redirect triggered', array(
            'order_id'   => isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 'not set',
            'gateway_id' => $this->id,
        ) );

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $order_id = absint( $_GET['order_id'] );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $key = sanitize_text_field( wp_unslash( $_GET['key'] ) );

        $order = wc_get_order( $order_id );

        if ( ! $order || $order->get_order_key() !== $key ) {
            YS_Shopline_Logger::error( 'Invalid order in pay redirect', array(
                'order_id' => $order_id,
                'key'      => $key,
            ) );
            wp_die( esc_html__( 'Invalid Order.', 'ys-shopline-via-woocommerce' ) );
        }

        // Check if this order belongs to this gateway
        $order_gateway = $order->get_payment_method();
        YS_Shopline_Logger::debug( 'Checking gateway match', array(
            'order_gateway' => $order_gateway,
            'this_gateway'  => $this->id,
        ) );

        if ( $order_gateway !== $this->id ) {
            // 不匹配但可能是另一個 Shopline 閘道，不要 return，讓其他閘道處理
            return;
        }

        $next_action = $order->get_meta( '_ys_shopline_next_action' );

        YS_Shopline_Logger::debug( 'Next action status', array(
            'has_next_action' => ! empty( $next_action ) ? 'yes' : 'no',
        ) );

        if ( ! $next_action ) {
            // No next action, redirect to thank you page
            YS_Shopline_Logger::debug( 'No next action, redirecting to thank you page' );
            wp_safe_redirect( $this->get_return_url( $order ) );
            exit;
        }

        // Render 3DS/redirect page
        YS_Shopline_Logger::debug( 'Rendering 3DS page' );
        $this->render_pay_page( $order, $next_action );
        exit;
    }

    /**
     * Render payment redirect page (3DS, etc.)
     *
     * @param WC_Order $order Order object.
     * @param array $next_action Next action data.
     */
    protected function render_pay_page( $order, $next_action ) {
        $return_url = $this->get_return_url( $order );

        // Get credentials
        if ( $this->testmode ) {
            $client_key  = get_option( 'ys_shopline_sandbox_client_key', '' );
            $merchant_id = get_option( 'ys_shopline_sandbox_merchant_id', '' );
        } else {
            $client_key  = get_option( 'ys_shopline_client_key', '' );
            $merchant_id = get_option( 'ys_shopline_merchant_id', '' );
        }

        $env = $this->testmode ? 'sandbox' : 'production';
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
                var clientKey = <?php echo wp_json_encode( $client_key ); ?>;
                var merchantId = <?php echo wp_json_encode( $merchant_id ); ?>;
                var env = <?php echo wp_json_encode( $env ); ?>;

                async function processPayment() {
                    try {
                        console.log('Initializing SDK for 3DS...');

                        var result = await ShoplinePayments({
                            clientKey: clientKey,
                            merchantId: merchantId,
                            paymentMethod: 'CreditCard',
                            element: '#paymentContainer',
                            env: env,
                            currency: 'TWD',
                            amount: 0
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
                            // Success - redirect
                            window.location.href = returnUrl;
                        }

                    } catch (e) {
                        console.error('Payment error:', e);
                        showError('System Error: ' + e.message);
                    }
                }

                function showError(message) {
                    document.querySelector('.spinner').style.display = 'none';
                    document.getElementById('errorMessage').textContent = message;
                    document.getElementById('errorMessage').style.display = 'block';
                }

                // Start processing
                processPayment();
            </script>
        </body>
        </html>
        <?php
    }

    /**
     * Initialize API.
     */
    protected function init_api() {
        $this->api = YS_Shopline_Payment::get_api();
    }

    /**
     * Get payment method for SDK.
     *
     * @return string
     */
    abstract public function get_payment_method();

    /**
     * Initialize gateway settings form fields.
     */
    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title'   => __( 'Enable/Disable', 'ys-shopline-via-woocommerce' ),
                'type'    => 'checkbox',
                'label'   => sprintf(
                    /* translators: %s: Payment method title */
                    __( 'Enable %s', 'ys-shopline-via-woocommerce' ),
                    $this->method_title
                ),
                'default' => 'no',
            ),
            'title' => array(
                'title'       => __( 'Title', 'ys-shopline-via-woocommerce' ),
                'type'        => 'text',
                'description' => __( 'This controls the title which the user sees during checkout.', 'ys-shopline-via-woocommerce' ),
                'default'     => $this->method_title,
                'desc_tip'    => true,
            ),
            'description' => array(
                'title'       => __( 'Description', 'ys-shopline-via-woocommerce' ),
                'type'        => 'textarea',
                'description' => __( 'Payment method description that the customer will see on your checkout.', 'ys-shopline-via-woocommerce' ),
                'default'     => '',
                'desc_tip'    => true,
            ),
        );
    }

    /**
     * Payment fields.
     */
    public function payment_fields() {
        if ( $this->description ) {
            echo wpautop( wp_kses_post( $this->description ) );
        }

        // Container for SDK
        printf(
            '<div id="%s_container" class="ys-shopline-payment-container" data-gateway="%s" style="min-height: 100px;"></div>',
            esc_attr( $this->id ),
            esc_attr( $this->id )
        );
    }

    /**
     * Enqueue payment scripts.
     */
    public function payment_scripts() {
        if ( ! is_checkout() && ! is_add_payment_method_page() ) {
            return;
        }

        if ( 'no' === $this->enabled ) {
            return;
        }

        // Shopline SDK
        wp_enqueue_script(
            'ys-shopline-sdk',
            'https://cdn.shoplinepayments.com/sdk/v1/payment-web.js',
            array(),
            null,
            true
        );

        // Custom checkout script
        wp_enqueue_script(
            'ys-shopline-checkout',
            YS_SHOPLINE_PLUGIN_URL . 'assets/js/shopline-checkout.js',
            array( 'jquery', 'ys-shopline-sdk' ),
            YS_SHOPLINE_VERSION,
            true
        );

        // Localize script
        wp_localize_script(
            'ys-shopline-checkout',
            'ys_shopline_params',
            array(
                'ajax_url'   => admin_url( 'admin-ajax.php' ),
                'nonce'      => wp_create_nonce( 'ys_shopline_nonce' ),
                'gateway_id' => $this->id,
                'i18n'       => array(
                    'payment_error'      => __( 'Payment error occurred. Please try again.', 'ys-shopline-via-woocommerce' ),
                    'config_error'       => __( 'Configuration error. Please contact support.', 'ys-shopline-via-woocommerce' ),
                    'sdk_error'          => __( 'Payment SDK failed to load. Please refresh the page.', 'ys-shopline-via-woocommerce' ),
                    'processing'         => __( 'Processing payment...', 'ys-shopline-via-woocommerce' ),
                    'applepay_unsupported' => __( '此裝置或瀏覽器不支援 Apple Pay。請使用 iPhone/iPad/Mac 上的 Safari 瀏覽器。', 'ys-shopline-via-woocommerce' ),
                    'payment_not_ready'  => __( '付款尚未準備就緒，請稍候再試。', 'ys-shopline-via-woocommerce' ),
                ),
            )
        );
    }

    /**
     * Get SDK configuration for frontend.
     *
     * @return array
     */
    public function get_sdk_config() {
        // 取得金額：優先從訂單付款頁面取得，否則從購物車
        $amount_raw = 0;
        $currency   = get_woocommerce_currency();

        // 檢查是否是訂單付款頁面（pay for order）
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( isset( $_GET['pay_for_order'] ) && isset( $_GET['key'] ) ) {
            global $wp;
            $order_id = isset( $wp->query_vars['order-pay'] ) ? absint( $wp->query_vars['order-pay'] ) : 0;
            if ( $order_id ) {
                $order = wc_get_order( $order_id );
                if ( $order ) {
                    $amount_raw = $order->get_total();
                    $currency   = $order->get_currency();
                }
            }
        }

        // 如果不是訂單付款頁面，從購物車取得
        if ( ! $amount_raw && WC()->cart ) {
            $amount_raw = WC()->cart->get_total( 'edit' );
        }

        // SDK 和 API 都需要金額 × 100（台幣 1 元 = 100）
        $amount = YS_Shopline_Payment::get_sdk_amount( $amount_raw );

        // Get credentials based on test mode
        if ( $this->testmode ) {
            $merchant_id = get_option( 'ys_shopline_sandbox_merchant_id', '' );
            $client_key  = get_option( 'ys_shopline_sandbox_client_key', '' );
        } else {
            $merchant_id = get_option( 'ys_shopline_merchant_id', '' );
            $client_key  = get_option( 'ys_shopline_client_key', '' );
        }

        $config = array(
            'merchantId'    => $merchant_id,
            'clientKey'     => $client_key,
            'currency'      => $currency,
            'amount'        => $amount,
            'paymentMethod' => $this->get_payment_method(),
            'env'           => $this->testmode ? 'sandbox' : 'production',
        );

        // Debug log for troubleshooting
        YS_Shopline_Logger::debug( 'SDK Config generated', array(
            'gateway'     => $this->id,
            'testmode'    => $this->testmode ? 'yes' : 'no',
            'merchantId'  => $merchant_id ? substr( $merchant_id, 0, 8 ) . '...' : '(empty)',
            'clientKey'   => $client_key ? substr( $client_key, 0, 8 ) . '...' : '(empty)',
            'env'         => $config['env'],
            'amount'      => $amount,
        ) );

        // Check for subscription in cart
        if ( class_exists( 'WC_Subscriptions_Cart' ) && WC_Subscriptions_Cart::cart_contains_subscription() ) {
            $config['forceSaveCard'] = true;
        }

        // 已登入用戶：取得 customerToken 用於儲存卡片功能
        // 訪客：不傳 customerToken（不支援儲存卡片）
        $user_id = get_current_user_id();
        if ( $user_id ) {
            $customer_token = $this->get_customer_token( $user_id );
            if ( $customer_token ) {
                $config['customerToken'] = $customer_token;
                YS_Shopline_Logger::debug( 'Customer token added to SDK config', array(
                    'user_id' => $user_id,
                ) );
            }
        }

        return apply_filters( 'ys_shopline_sdk_config', $config, $this );
    }

    /**
     * Get customer token for SDK initialization.
     *
     * 流程：
     * 1. 取得或建立 SHOPLINE customerId
     * 2. 呼叫 /customer/token 取得 customerToken
     *
     * @param int $user_id WordPress user ID.
     * @return string|false Customer token or false on failure.
     */
    protected function get_customer_token( $user_id ) {
        if ( ! $user_id ) {
            return false;
        }

        // 先取得 customerId
        $customer_id = $this->get_shopline_customer_id( $user_id );

        if ( ! $customer_id ) {
            YS_Shopline_Logger::debug( 'Cannot get customer token: no customerId', array(
                'user_id' => $user_id,
            ) );
            return false;
        }

        if ( ! $this->api ) {
            return false;
        }

        // 呼叫 /customer/token 取得 customerToken
        $response = $this->api->get_customer_token( $customer_id );

        if ( is_wp_error( $response ) ) {
            YS_Shopline_Logger::error( 'Failed to get customer token', array(
                'error'       => $response->get_error_message(),
                'customer_id' => $customer_id,
            ) );
            return false;
        }

        if ( isset( $response['customerToken'] ) ) {
            YS_Shopline_Logger::debug( 'Customer token retrieved', array(
                'customer_id' => $customer_id,
                'expire_time' => isset( $response['expireTime'] ) ? $response['expireTime'] : 'unknown',
            ) );
            return $response['customerToken'];
        }

        return false;
    }

    /**
     * Get or create Shopline customer ID for a user.
     *
     * 新版 API：
     * - 端點：/api/v1/customer/create
     * - 請求需要 customer 物件包含 email/phoneNumber
     * - 回應欄位為 customerId（不是 paymentCustomerId）
     *
     * @param int $user_id WordPress user ID.
     * @return string|false Customer ID or false on failure.
     */
    protected function get_shopline_customer_id( $user_id ) {
        $customer_id = get_user_meta( $user_id, '_ys_shopline_customer_id', true );

        if ( $customer_id ) {
            return $customer_id;
        }

        if ( ! $this->api ) {
            return false;
        }

        $user = get_userdata( $user_id );
        if ( ! $user ) {
            return false;
        }

        // 取得電話號碼並格式化
        $raw_phone = get_user_meta( $user_id, 'billing_phone', true );
        $country   = get_user_meta( $user_id, 'billing_country', true ) ?: 'TW';
        $phone     = $this->format_phone_number( $raw_phone, $country );

        // 新版 API 格式：需要 customer 物件
        $data = array(
            'referenceCustomerId' => (string) $user_id,
            'customer'            => array(
                'email'       => $user->user_email,
                'phoneNumber' => $phone,
            ),
            'name'                => $user->display_name ?: $user->user_login,
        );

        $response = $this->api->create_customer( $data );

        if ( is_wp_error( $response ) ) {
            YS_Shopline_Logger::error( 'Failed to create customer: ' . $response->get_error_message() );
            return false;
        }

        // 新版 API 回應欄位為 customerId
        if ( isset( $response['customerId'] ) ) {
            update_user_meta( $user_id, '_ys_shopline_customer_id', $response['customerId'] );
            return $response['customerId'];
        }

        return false;
    }

    /**
     * Process the payment.
     *
     * @param int $order_id Order ID.
     * @return array
     */
    public function process_payment( $order_id ) {
        $order = wc_get_order( $order_id );

        if ( ! $order ) {
            wc_add_notice( __( 'Order not found.', 'ys-shopline-via-woocommerce' ), 'error' );
            return array( 'result' => 'failure' );
        }

        // Get pay session from POST
        // paySession 從 SDK createPayment() 返回，可能是 JSON 字串或已序列化的物件
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $pay_session_raw = isset( $_POST['ys_shopline_pay_session'] ) ? wp_unslash( $_POST['ys_shopline_pay_session'] ) : '';

        if ( empty( $pay_session_raw ) ) {
            wc_add_notice( __( 'Payment session missing. Please try again.', 'ys-shopline-via-woocommerce' ), 'error' );
            return array( 'result' => 'failure' );
        }

        // 嘗試解析 paySession
        // 根據 SHOPLINE API 文件，paySession 應該是 "JSON String" 類型
        // 意味著 API 期望收到的是 JSON 字串值，而不是物件
        $pay_session = $pay_session_raw;

        // 驗證 paySession 是有效的 JSON（至少能解析）
        $decoded = json_decode( $pay_session_raw, true );
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            YS_Shopline_Logger::error( 'Invalid paySession JSON: ' . json_last_error_msg(), array(
                'raw_value' => substr( $pay_session_raw, 0, 100 ),
            ) );
            wc_add_notice( __( 'Invalid payment session. Please try again.', 'ys-shopline-via-woocommerce' ), 'error' );
            return array( 'result' => 'failure' );
        }

        YS_Shopline_Logger::debug( 'PaySession received', array(
            'type'          => gettype( $pay_session_raw ),
            'length'        => strlen( $pay_session_raw ),
            'decoded_ok'    => $decoded !== null ? 'yes' : 'no',
            'has_sessionId' => isset( $decoded['sessionId'] ) ? 'yes' : 'no',
            'preview'       => substr( $pay_session_raw, 0, 100 ) . '...',
            'decoded_keys'  => $decoded !== null ? array_keys( $decoded ) : array(),
        ) );

        // 根據測試：API 1999 錯誤可能是因為 paySession 格式問題
        // 嘗試傳遞 JSON 字串（保持原樣）或解析後的陣列
        // 先用解析後的陣列嘗試（如果 API 期望物件而非字串）
        // TODO: 如果這樣可以運作，確認這是正確的方式
        // $pay_session = $decoded; // 解析為陣列

        // Check API
        if ( ! $this->api ) {
            wc_add_notice( __( 'Payment gateway not configured.', 'ys-shopline-via-woocommerce' ), 'error' );
            return array( 'result' => 'failure' );
        }

        // Prepare payment data
        $payment_data = $this->prepare_payment_data( $order, $pay_session );

        // Create payment trade
        $response = $this->api->create_payment_trade( $payment_data );

        if ( is_wp_error( $response ) ) {
            YS_Shopline_Logger::error( 'Payment failed: ' . $response->get_error_message() );

            // 將訂單標記為失敗，這樣下次結帳會建立新訂單
            $order->update_status( 'failed', __( 'Shopline payment failed: ', 'ys-shopline-via-woocommerce' ) . $response->get_error_message() );

            wc_add_notice(
                __( 'Payment failed: ', 'ys-shopline-via-woocommerce' ) . $response->get_error_message(),
                'error'
            );
            return array( 'result' => 'failure' );
        }

        // Store trade order ID
        if ( isset( $response['tradeOrderId'] ) ) {
            $order->update_meta_data( '_ys_shopline_trade_order_id', $response['tradeOrderId'] );
            $order->save();
        }

        // Handle next action (3DS, redirect, etc.)
        if ( isset( $response['nextAction'] ) ) {
            return $this->handle_next_action( $order, $response );
        }

        // Payment completed immediately
        $order->payment_complete( isset( $response['tradeOrderId'] ) ? $response['tradeOrderId'] : '' );
        $order->add_order_note( __( 'Shopline payment completed.', 'ys-shopline-via-woocommerce' ) );

        // Empty the cart
        WC()->cart->empty_cart();

        return array(
            'result'   => 'success',
            'redirect' => $this->get_return_url( $order ),
        );
    }

    /**
     * Prepare payment data for API.
     *
     * @param WC_Order $order       Order object.
     * @param string   $pay_session Pay session from SDK.
     * @return array
     */
    protected function prepare_payment_data( $order, $pay_session ) {
        $is_subscription = $this->order_contains_subscription( $order );

        // 檢查是否使用已綁定的卡片（快捷付款）
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $payment_instrument_id = isset( $_POST['ys_shopline_payment_instrument_id'] ) ? sanitize_text_field( wp_unslash( $_POST['ys_shopline_payment_instrument_id'] ) ) : '';

        // 決定是否使用 CardBindPayment
        //
        // 重要：當 SDK 初始化時有啟用 bindCard（傳了 customerToken），
        // 後端必須使用 CardBindPayment，否則會導致 "User authorization verification failed" 錯誤。
        //
        // 這是因為 SDK 和 API 需要保持一致：
        // - SDK 啟用 bindCard → API 需要 CardBindPayment
        // - SDK 未啟用 bindCard → API 使用 Regular
        //
        // paySession 已經包含用戶是否勾選儲存卡片的選擇，
        // API 會根據此決定是否實際儲存卡片。
        //
        // 判斷方式：
        // 1. 訂閱訂單：強制使用 CardBindPayment
        // 2. 已登入用戶且有 SHOPLINE customerId：使用 CardBindPayment
        // 3. 訪客：使用 Regular
        $user_id     = $order->get_user_id();
        $customer_id = $user_id ? $this->get_shopline_customer_id( $user_id ) : false;

        // 判斷是否啟用綁卡
        // 只有已登入用戶且有 customerId 時才啟用（與 SDK 端邏輯一致）
        $use_bind_card = $is_subscription || ( $user_id && $customer_id );

        // 決定付款行為（paymentBehavior）：
        // - Regular: 一般信用卡付款（輸入卡號，不綁卡）
        // - CardBindPayment: 綁卡付款（輸入卡號並儲存）- SDK 啟用 bindCard 時使用
        // - QuickPayment: 快捷付款（使用已綁定的卡片，需要 paymentCustomerId + paymentInstrumentId）
        //
        // 重要：當 SDK 啟用 bindCard 時，paySession 已包含用戶是否勾選儲存卡片的資訊
        // 後端使用 CardBindPayment + savePaymentInstrument=true，
        // API 會根據 paySession 中的用戶選擇來決定是否實際儲存卡片
        if ( ! empty( $payment_instrument_id ) ) {
            // 使用已綁定的卡片
            $payment_behavior = 'QuickPayment';
        } elseif ( $use_bind_card ) {
            // SDK 啟用了綁卡功能，使用 CardBindPayment
            // paySession 已包含用戶是否勾選儲存的選擇
            $payment_behavior = 'CardBindPayment';
        } else {
            // 一般付款（SDK 未啟用綁卡）
            $payment_behavior = 'Regular';
        }

        // 準備客戶資訊
        $customer_personal_info = $this->build_personal_info( $order, 'billing' );

        // 準備帳單資訊
        $billing_address = $this->build_address( $order, 'billing' );

        // 準備運送資訊
        $shipping_address      = $this->build_address( $order, 'shipping' );
        $shipping_personal_info = $this->build_personal_info( $order, 'shipping' );

        // 準備產品清單
        $products = $this->build_products( $order );

        // 取得客戶 IP
        $client_ip = $this->get_client_ip();

        // 回調 URL
        $return_url = $this->get_return_url( $order );

        // 產生唯一的 referenceOrderId
        // Shopline 不允許重複使用相同的 referenceOrderId，即使之前付款失敗
        // 格式：{order_id}_{attempt} 例如：1022_1, 1022_2
        $reference_order_id = $this->generate_reference_order_id( $order );

        $data = array(
            'paySession'       => $pay_session,
            'referenceOrderId' => $reference_order_id,
            'returnUrl'        => $return_url,
            'acquirerType'     => 'SDK',
            'language'         => $this->get_shopline_language(),
            'amount'           => array(
                'value'    => YS_Shopline_Payment::get_formatted_amount( $order->get_total(), $order->get_currency() ),
                'currency' => $order->get_currency(),
            ),
            'confirm'          => array(
                'paymentMethod'   => $this->get_payment_method(),
                'paymentBehavior' => $payment_behavior,
                // autoConfirm: 自動確認付款（API 預設 false）
                // 文件只明確說 Recurring 要 autoConfirm=true
                // 一般付款使用預設 false
                // autoCapture: 自動請款（API 預設 true）
            ),
            'customer'         => array(
                'referenceCustomerId' => (string) ( $order->get_user_id() ?: $order->get_id() ),
                // type: 0=一般顧客, 1=SLP 會員
                // 目前不使用 SLP 會員流程，固定傳 0 避免後端走會員路徑
                'type'                => '0',
                'personalInfo'        => $customer_personal_info,
            ),
            'billing'          => array(
                'description'  => sprintf( 'Order #%s', $order->get_id() ),
                'personalInfo' => $customer_personal_info,
                'address'      => $billing_address,
            ),
            'order'            => array(
                'products'         => $products,
                'shipping'         => array(
                    'shippingMethod' => $order->get_shipping_method() ?: 'Standard',
                    'carrier'        => $order->get_shipping_method() ?: 'Default',
                    'personalInfo'   => ! empty( $shipping_personal_info['firstName'] ) ? $shipping_personal_info : $customer_personal_info,
                    'address'        => ! empty( $shipping_address['city'] ) ? $shipping_address : $billing_address,
                    'amount'         => array(
                        'value'    => YS_Shopline_Payment::get_formatted_amount( $order->get_shipping_total(), $order->get_currency() ),
                        'currency' => $order->get_currency(),
                    ),
                ),
            ),
            'client'           => $this->build_client_info( $client_ip ),
        );

        // QuickPayment 流程：使用已綁定的卡片
        if ( 'QuickPayment' === $payment_behavior && ! empty( $payment_instrument_id ) ) {
            // 取得 customerId
            if ( $order->get_user_id() ) {
                $customer_id = $this->get_shopline_customer_id( $order->get_user_id() );
                if ( $customer_id ) {
                    $data['confirm']['paymentCustomerId'] = $customer_id;
                    $data['confirm']['paymentInstrument'] = array(
                        'paymentInstrumentId' => $payment_instrument_id,
                    );
                } else {
                    // 沒有 customerId，無法使用 QuickPayment，降級為 Regular
                    YS_Shopline_Logger::warning( 'QuickPayment requested but no customerId found, falling back to Regular' );
                    $data['confirm']['paymentBehavior'] = 'Regular';
                }
            }
        }
        // CardBindPayment 流程：SDK 啟用綁卡功能
        // paySession 已包含用戶是否勾選儲存的選擇，API 會根據此決定是否實際儲存
        elseif ( $use_bind_card ) {
            $data['confirm']['paymentInstrument'] = array(
                'savePaymentInstrument' => true,
            );

            // Add customer ID for token binding
            if ( $order->get_user_id() ) {
                $customer_id = $this->get_shopline_customer_id( $order->get_user_id() );
                if ( $customer_id ) {
                    $data['confirm']['paymentCustomerId'] = $customer_id;
                }
            }
        }
        // Regular 流程：不需要額外設定

        // 記錄付款資料（用於除錯）
        YS_Shopline_Logger::debug( 'Payment data prepared', array(
            'order_id'         => $order->get_id(),
            'amount'           => $data['amount']['value'],
            'currency'         => $data['amount']['currency'],
            'payment_behavior' => $payment_behavior,
            'payment_method'   => $this->get_payment_method(),
            'use_bind_card'    => $use_bind_card ? 'yes' : 'no',
            'user_id'          => $user_id,
            'customer_id'      => $customer_id ?: 'none',
            'is_subscription'  => $is_subscription ? 'yes' : 'no',
            'pay_session_type' => gettype( $pay_session ),
            'pay_session_len'  => strlen( $pay_session ),
            'client_ip'        => $client_ip,
            'products_count'   => count( $products ),
        ) );

        // 詳細記錄完整資料結構（用於除錯 1999 錯誤）
        YS_Shopline_Logger::debug( 'Full payment data structure', array(
            'data_keys'           => array_keys( $data ),
            'confirm_keys'        => array_keys( $data['confirm'] ),
            'customer_keys'       => array_keys( $data['customer'] ),
            'billing_keys'        => array_keys( $data['billing'] ),
            'order_keys'          => array_keys( $data['order'] ),
            'client_keys'         => array_keys( $data['client'] ),
            'billing_address'     => $data['billing']['address'],
            'customer_info'       => $data['customer']['personalInfo'],
            'referenceOrderId'    => $data['referenceOrderId'],
        ) );

        return apply_filters( 'ys_shopline_payment_data', $data, $order, $this );
    }

    /**
     * Build personal info array from order.
     *
     * @param WC_Order $order Order object.
     * @param string   $type  Address type (billing or shipping).
     * @return array
     */
    protected function build_personal_info( $order, $type = 'billing' ) {
        $first_name = $order->{"get_{$type}_first_name"}();
        $last_name  = $order->{"get_{$type}_last_name"}();

        // 如果沒有拆分名字，嘗試從完整名字分割
        if ( empty( $first_name ) && empty( $last_name ) ) {
            $full_name  = $order->get_formatted_billing_full_name();
            $name_parts = explode( ' ', $full_name, 2 );
            $first_name = $name_parts[0] ?? '';
            $last_name  = $name_parts[1] ?? '';
        }

        // 確保至少有名字
        if ( empty( $first_name ) ) {
            $first_name = 'Customer';
        }

        // 取得電話號碼（容錯：帳單沒有就取運送資訊）
        $raw_phone = $order->get_billing_phone();
        $country   = $order->get_billing_country();

        // 如果帳單電話為空，嘗試取運送電話
        if ( empty( $raw_phone ) ) {
            $raw_phone = $order->get_shipping_phone();
            $country   = $order->get_shipping_country() ?: $country;
        }

        // 格式化電話號碼（加入國碼）
        $phone = $this->format_phone_number( $raw_phone, $country ?: 'TW' );

        return array(
            'firstName' => $first_name,
            'lastName'  => $last_name ?: $first_name,
            'email'     => $order->get_billing_email(),
            'phone'     => $phone,
        );
    }

    /**
     * Format phone number with country code.
     *
     * @param string $phone   Phone number.
     * @param string $country Country code.
     * @return string
     */
    protected function format_phone_number( $phone, $country = 'TW' ) {
        if ( empty( $phone ) ) {
            return '';
        }

        // 移除所有非數字字元（保留開頭的 +）
        $has_plus = ( substr( $phone, 0, 1 ) === '+' );
        $phone    = preg_replace( '/[^0-9]/', '', $phone );

        // 如果已經有國碼格式，直接返回
        if ( $has_plus ) {
            return '+' . $phone;
        }

        // 根據國家加入國碼
        $country_codes = array(
            'TW' => '886',
            'HK' => '852',
            'JP' => '81',
            'KR' => '82',
            'US' => '1',
            'CN' => '86',
            'SG' => '65',
            'MY' => '60',
        );

        $country_code = $country_codes[ $country ] ?? '886';

        // 移除開頭的 0（台灣手機 09xx -> 9xx）
        if ( substr( $phone, 0, 1 ) === '0' ) {
            $phone = substr( $phone, 1 );
        }

        return '+' . $country_code . $phone;
    }

    /**
     * Generate unique reference order ID for Shopline.
     *
     * Shopline 不允許重複使用相同的 referenceOrderId，
     * 所以需要加上嘗試次數來產生唯一 ID。
     *
     * @param WC_Order $order Order object.
     * @return string
     */
    protected function generate_reference_order_id( $order ) {
        $order_id = $order->get_id();

        // 取得目前的付款嘗試次數
        $attempt = (int) $order->get_meta( '_ys_shopline_payment_attempt' );
        $attempt++;

        // 更新嘗試次數
        $order->update_meta_data( '_ys_shopline_payment_attempt', $attempt );
        $order->save();

        // 產生唯一 ID：訂單ID_嘗試次數
        // 例如：1022_1, 1022_2, 1022_3
        $reference_id = sprintf( '%d_%d', $order_id, $attempt );

        // 記錄 referenceOrderId 以便後續查詢
        $order->update_meta_data( '_ys_shopline_reference_order_id', $reference_id );
        $order->save();

        return $reference_id;
    }

    /**
     * Build address array from order.
     *
     * 確保所有必填欄位都有值，防止 API 錯誤。
     * 優先順序：指定類型地址 > 另一類型地址 > 預設值
     *
     * @param WC_Order $order Order object.
     * @param string   $type  Address type (billing or shipping).
     * @return array
     */
    protected function build_address( $order, $type = 'billing' ) {
        $other_type = ( 'billing' === $type ) ? 'shipping' : 'billing';

        // 取得 street（地址 1 + 地址 2）
        $address_1 = $order->{"get_{$type}_address_1"}();
        $address_2 = $order->{"get_{$type}_address_2"}();
        $street    = trim( $address_1 . ' ' . $address_2 );

        // 如果地址為空，嘗試另一類型地址
        if ( empty( $street ) ) {
            $other_address_1 = $order->{"get_{$other_type}_address_1"}();
            $other_address_2 = $order->{"get_{$other_type}_address_2"}();
            $street = trim( $other_address_1 . ' ' . $other_address_2 );
        }

        // 取得其他欄位（同樣的 fallback 邏輯）
        $country_code = $order->{"get_{$type}_country"}();
        if ( empty( $country_code ) ) {
            $country_code = $order->{"get_{$other_type}_country"}();
        }

        $city = $order->{"get_{$type}_city"}();
        if ( empty( $city ) ) {
            $city = $order->{"get_{$other_type}_city"}();
        }

        $district = $order->{"get_{$type}_state"}();
        if ( empty( $district ) ) {
            $district = $order->{"get_{$other_type}_state"}();
        }

        $postcode = $order->{"get_{$type}_postcode"}();
        if ( empty( $postcode ) ) {
            $postcode = $order->{"get_{$other_type}_postcode"}();
        }

        // 最終防呆：確保必填欄位不為空
        if ( empty( $street ) ) {
            $street = ! empty( $city ) ? $city : __( '未輸入地址', 'ys-shopline-via-woocommerce' );
        }

        return array(
            'countryCode' => ! empty( $country_code ) ? $country_code : 'TW',
            'city'        => ! empty( $city ) ? $city : '',
            'district'    => ! empty( $district ) ? $district : '',
            'street'      => $street,
            'postcode'    => ! empty( $postcode ) ? $postcode : '',
        );
    }

    /**
     * Build products array from order items.
     *
     * 注意：運費透過 order.shipping 區塊傳送，不應放在 products 陣列
     * products 只包含：商品項目、手續費、折扣調整
     *
     * @param WC_Order $order Order object.
     * @return array
     */
    protected function build_products( $order ) {
        $products = array();
        $currency = $order->get_currency();

        // 1. 加入商品項目
        foreach ( $order->get_items() as $item ) {
            $product = $item->get_product();

            $products[] = array(
                'id'       => (string) ( $product ? $product->get_id() : $item->get_id() ),
                'name'     => $item->get_name(),
                'quantity' => $item->get_quantity(),
                'amount'   => array(
                    'value'    => YS_Shopline_Payment::get_formatted_amount(
                        $item->get_total() / max( 1, $item->get_quantity() ),
                        $currency
                    ),
                    'currency' => $currency,
                ),
            );
        }

        // 2. 加入手續費項目（WooCommerce Fees）
        foreach ( $order->get_items( 'fee' ) as $fee_item ) {
            $fee_total = (float) $fee_item->get_total();
            if ( $fee_total != 0 ) {
                $products[] = array(
                    'id'       => 'fee_' . $fee_item->get_id(),
                    'name'     => $fee_item->get_name() ?: __( '手續費', 'ys-shopline-via-woocommerce' ),
                    'quantity' => 1,
                    'amount'   => array(
                        'value'    => YS_Shopline_Payment::get_formatted_amount( $fee_total, $currency ),
                        'currency' => $currency,
                    ),
                );
            }
        }

        // 3. 計算 products 小計（不含運費，運費由 order.shipping 處理）
        $products_total = 0;
        foreach ( $products as $product_item ) {
            $products_total += $product_item['amount']['value'] * $product_item['quantity'];
        }

        // 計算不含運費的訂單小計
        $order_subtotal = YS_Shopline_Payment::get_formatted_amount(
            $order->get_total() - $order->get_shipping_total(),
            $currency
        );

        // 如果有差額（通常是因為折扣或稅），加入調整項目
        $diff = $order_subtotal - $products_total;
        if ( $diff != 0 ) {
            $adjustment_name = $diff > 0 ? __( '其他費用', 'ys-shopline-via-woocommerce' ) : __( '折扣', 'ys-shopline-via-woocommerce' );
            $products[] = array(
                'id'       => 'adjustment',
                'name'     => $adjustment_name,
                'quantity' => 1,
                'amount'   => array(
                    'value'    => $diff,
                    'currency' => $currency,
                ),
            );

            YS_Shopline_Logger::debug( 'Products total adjustment', array(
                'products_total' => $products_total,
                'order_subtotal' => $order_subtotal,
                'diff'           => $diff,
            ) );
        }

        return $products;
    }

    /**
     * Get client IP address.
     *
     * @return string
     */
    protected function get_client_ip() {
        // 優先使用 WooCommerce 的方法
        if ( class_exists( 'WC_Geolocation' ) ) {
            $ip = WC_Geolocation::get_ip_address();
            if ( $ip && '0.0.0.0' !== $ip ) {
                return $ip;
            }
        }

        // 備用方法
        $ip_keys = array(
            'HTTP_CF_CONNECTING_IP', // Cloudflare
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'REMOTE_ADDR',
        );

        foreach ( $ip_keys as $key ) {
            if ( ! empty( $_SERVER[ $key ] ) ) {
                $ip = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
                // 處理多個 IP（X-Forwarded-For 可能有多個）
                if ( strpos( $ip, ',' ) !== false ) {
                    $ip = trim( explode( ',', $ip )[0] );
                }
                if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                    return $ip;
                }
            }
        }

        return '127.0.0.1';
    }

    /**
     * Get Shopline language code.
     *
     * @return string
     */
    protected function get_shopline_language() {
        $locale = get_locale();

        // 映射 WordPress locale 到 Shopline 支援的語言
        $language_map = array(
            'zh_TW' => 'zh-TW',
            'zh_CN' => 'zh-CN',
            'en_US' => 'en',
            'en_GB' => 'en',
            'ja'    => 'ja',
            'ko_KR' => 'ko',
        );

        foreach ( $language_map as $wp_locale => $shopline_lang ) {
            if ( strpos( $locale, $wp_locale ) === 0 || strpos( $locale, explode( '_', $wp_locale )[0] ) === 0 ) {
                return $shopline_lang;
            }
        }

        // 預設繁體中文
        return 'zh-TW';
    }

    /**
     * Build client info for 3DS and risk assessment.
     *
     * 這些欄位對信用卡/3DS 驗證非常重要，缺漏可能導致 1999 錯誤。
     *
     * @param string $client_ip Client IP address.
     * @return array
     */
    protected function build_client_info( $client_ip ) {
        // 從 POST 取得前端收集的裝置資訊（如果有）
        // phpcs:disable WordPress.Security.NonceVerification.Missing
        $screen_width    = isset( $_POST['ys_shopline_screen_width'] ) ? sanitize_text_field( wp_unslash( $_POST['ys_shopline_screen_width'] ) ) : '';
        $screen_height   = isset( $_POST['ys_shopline_screen_height'] ) ? sanitize_text_field( wp_unslash( $_POST['ys_shopline_screen_height'] ) ) : '';
        $color_depth     = isset( $_POST['ys_shopline_color_depth'] ) ? sanitize_text_field( wp_unslash( $_POST['ys_shopline_color_depth'] ) ) : '';
        $timezone_offset = isset( $_POST['ys_shopline_timezone_offset'] ) ? sanitize_text_field( wp_unslash( $_POST['ys_shopline_timezone_offset'] ) ) : '';
        $java_enabled    = isset( $_POST['ys_shopline_java_enabled'] ) ? sanitize_text_field( wp_unslash( $_POST['ys_shopline_java_enabled'] ) ) : '';
        $browser_lang    = isset( $_POST['ys_shopline_browser_language'] ) ? sanitize_text_field( wp_unslash( $_POST['ys_shopline_browser_language'] ) ) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        // User Agent
        $user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';

        // Accept header
        $accept = isset( $_SERVER['HTTP_ACCEPT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT'] ) ) : '';

        // 交易網站 URL
        $transaction_website = home_url();

        $client_info = array(
            'ip'        => $client_ip,
            'userAgent' => substr( $user_agent, 0, 128 ), // API 限制 128 字元
        );

        // 只添加有值的欄位
        if ( ! empty( $screen_width ) ) {
            $client_info['screenWidth'] = $screen_width;
        }
        if ( ! empty( $screen_height ) ) {
            $client_info['screenHeight'] = $screen_height;
        }
        if ( ! empty( $color_depth ) ) {
            $client_info['colorDepth'] = $color_depth;
        }
        if ( ! empty( $timezone_offset ) ) {
            $client_info['timeZoneOffset'] = $timezone_offset;
        }
        if ( ! empty( $java_enabled ) ) {
            $client_info['javaEnabled'] = $java_enabled;
        }
        if ( ! empty( $browser_lang ) ) {
            $client_info['language'] = $browser_lang;
        }
        if ( ! empty( $accept ) ) {
            $client_info['accept'] = substr( $accept, 0, 128 ); // API 限制 128 字元
        }
        if ( ! empty( $transaction_website ) ) {
            $client_info['transactionWebSite'] = $transaction_website;
        }

        return $client_info;
    }

    /**
     * Handle next action from API response.
     *
     * @param WC_Order $order    Order object.
     * @param array    $response API response.
     * @return array
     */
    protected function handle_next_action( $order, $response ) {
        $order->update_meta_data( '_ys_shopline_next_action', $response['nextAction'] );
        $order->update_status( 'pending', __( 'Awaiting Shopline payment completion.', 'ys-shopline-via-woocommerce' ) );
        $order->save();

        // Reduce stock
        wc_reduce_stock_levels( $order->get_id() );

        // Empty the cart
        WC()->cart->empty_cart();

        // 返回 nextAction 給前端，讓前端用同一個 SDK 實例處理
        // SDK 文件指出：payment.pay(nextAction) 必須用同一個 payment 實例
        // 否則 SDK 不知道原始卡片資訊
        return array(
            'result'     => 'success',
            'nextAction' => $response['nextAction'],
            'returnUrl'  => $this->get_return_url( $order ),
            'orderId'    => $order->get_id(),
        );
    }

    /**
     * Check if order contains subscription.
     *
     * @param WC_Order $order Order object.
     * @return bool
     */
    protected function order_contains_subscription( $order ) {
        if ( ! function_exists( 'wcs_order_contains_subscription' ) ) {
            return false;
        }
        return wcs_order_contains_subscription( $order );
    }

    /**
     * Process refund.
     *
     * @param int        $order_id Order ID.
     * @param float|null $amount   Refund amount.
     * @param string     $reason   Refund reason.
     * @return bool|WP_Error
     */
    public function process_refund( $order_id, $amount = null, $reason = '' ) {
        $order = wc_get_order( $order_id );

        if ( ! $order ) {
            return new WP_Error( 'invalid_order', __( 'Order not found.', 'ys-shopline-via-woocommerce' ) );
        }

        $trade_order_id = $order->get_meta( '_ys_shopline_trade_order_id' );

        if ( empty( $trade_order_id ) ) {
            return new WP_Error( 'no_trade_id', __( 'Trade order ID not found.', 'ys-shopline-via-woocommerce' ) );
        }

        if ( ! $this->api ) {
            return new WP_Error( 'api_error', __( 'API not configured.', 'ys-shopline-via-woocommerce' ) );
        }

        $refund_data = array(
            'tradeOrderId' => $trade_order_id,
            'amount'       => array(
                'value'    => YS_Shopline_Payment::get_formatted_amount( $amount, $order->get_currency() ),
                'currency' => $order->get_currency(),
            ),
        );

        if ( ! empty( $reason ) ) {
            $refund_data['reason'] = $reason;
        }

        $response = $this->api->create_refund( $refund_data );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        // Store refund ID
        if ( isset( $response['refundOrderId'] ) ) {
            $order->add_order_note(
                sprintf(
                    /* translators: 1: Refund amount, 2: Refund ID */
                    __( 'Refunded %1$s via Shopline. Refund ID: %2$s', 'ys-shopline-via-woocommerce' ),
                    wc_price( $amount ),
                    $response['refundOrderId']
                )
            );
        }

        return true;
    }

    /**
     * Thank you page output.
     *
     * 注意：訂單狀態更新主要由以下機制處理：
     * 1. Webhook（推薦，非同步）
     * 2. Redirect handler（在跳轉到感謝頁前處理）
     *
     * 這個方法只做清理工作，不做 API 查詢以避免拖慢頁面。
     *
     * @param int $order_id Order ID.
     */
    public function thankyou_page( $order_id ) {
        $order = wc_get_order( $order_id );

        if ( ! $order || $order->get_payment_method() !== $this->id ) {
            return;
        }

        // 清除 next_action（付款完成後不再需要）
        $next_action = $order->get_meta( '_ys_shopline_next_action' );
        if ( $next_action ) {
            $order->delete_meta_data( '_ys_shopline_next_action' );
            $order->save();
        }
    }

    /**
     * Check payment status from API and update order.
     *
     * 在 Webhook 未能正常運作時，透過 API 查詢來確認付款狀態。
     *
     * @param WC_Order $order          Order object.
     * @param string   $trade_order_id Shopline trade order ID.
     */
    protected function check_and_update_order_status( $order, $trade_order_id ) {
        if ( ! $this->api ) {
            return;
        }

        YS_Shopline_Logger::debug( 'Checking order status via API', array(
            'order_id'       => $order->get_id(),
            'trade_order_id' => $trade_order_id,
        ) );

        // 查詢訂單狀態
        $response = $this->api->get_payment_trade( $trade_order_id );

        if ( is_wp_error( $response ) ) {
            YS_Shopline_Logger::error( 'Failed to query trade status: ' . $response->get_error_message() );
            return;
        }

        YS_Shopline_Logger::debug( 'Trade status query response', array(
            'status' => isset( $response['status'] ) ? $response['status'] : 'unknown',
        ) );

        // 根據狀態更新訂單
        $status = isset( $response['status'] ) ? $response['status'] : '';

        if ( 'SUCCESS' === $status || 'CAPTURED' === $status ) {
            // 付款成功
            if ( ! $order->is_paid() ) {
                $order->payment_complete( $trade_order_id );
                $order->add_order_note(
                    sprintf(
                        /* translators: %s: Trade order ID */
                        __( 'Shopline payment confirmed via status check. Trade ID: %s', 'ys-shopline-via-woocommerce' ),
                        $trade_order_id
                    )
                );
                $order->update_meta_data( '_ys_shopline_payment_status', $status );
                $order->save();

                YS_Shopline_Logger::info( 'Order marked as paid via status check: ' . $order->get_id() );
            }
        } elseif ( 'FAILED' === $status ) {
            // 付款失敗
            if ( ! $order->has_status( 'failed' ) ) {
                $error_msg = isset( $response['paymentMsg']['msg'] ) ? $response['paymentMsg']['msg'] : __( 'Payment failed', 'ys-shopline-via-woocommerce' );
                $order->update_status( 'failed', $error_msg );
                $order->update_meta_data( '_ys_shopline_payment_status', 'FAILED' );
                $order->save();
            }
        }
        // 其他狀態（CREATED, AUTHORIZED 等）暫不處理，等待 Webhook
    }

    /**
     * Email instructions.
     *
     * @param WC_Order $order         Order object.
     * @param bool     $sent_to_admin Sent to admin.
     * @param bool     $plain_text    Plain text email.
     */
    public function email_instructions( $order, $sent_to_admin, $plain_text = false ) {
        // Can be overridden by child classes
    }

    /**
     * Log messages.
     *
     * @param string $message Message to log.
     * @param string $level   Log level.
     */
    protected function log( $message, $level = 'info' ) {
        if ( $this->debug ) {
            YS_Shopline_Logger::log( $message, $level );
        }
    }
}
