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

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $order_id = absint( $_GET['order_id'] );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $key = sanitize_text_field( wp_unslash( $_GET['key'] ) );

        $order = wc_get_order( $order_id );

        if ( ! $order || $order->get_order_key() !== $key ) {
            wp_die( esc_html__( 'Invalid Order.', 'ys-shopline-via-woocommerce' ) );
        }

        // Check if this order belongs to this gateway
        if ( $order->get_payment_method() !== $this->id ) {
            return;
        }

        $next_action = $order->get_meta( '_ys_shopline_next_action' );

        if ( ! $next_action ) {
            // No next action, redirect to thank you page
            wp_safe_redirect( $this->get_return_url( $order ) );
            exit;
        }

        // Render 3DS/redirect page
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
                            accessMode: env
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
        $cart_total = WC()->cart ? WC()->cart->get_total( 'edit' ) : 0;
        $currency   = get_woocommerce_currency();
        $amount     = YS_Shopline_Payment::get_formatted_amount( $cart_total, $currency );

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

        // Note: Customer token feature temporarily disabled due to API endpoint issues
        // TODO: Re-enable when SHOPLINE confirms correct API endpoint for customer management
        // The /customer-paymentInstrument/customer/create endpoint returns 404

        return apply_filters( 'ys_shopline_sdk_config', $config, $this );
    }

    /**
     * Get or create Shopline customer ID for a user.
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

        $data = array(
            'referenceCustomerId' => (string) $user_id,
            'email'               => $user->user_email,
            'name'                => $user->display_name ?: $user->user_login,
            'phone'               => get_user_meta( $user_id, 'billing_phone', true ) ?: '',
        );

        $response = $this->api->create_customer( $data );

        if ( is_wp_error( $response ) ) {
            YS_Shopline_Logger::error( 'Failed to create customer: ' . $response->get_error_message() );
            return false;
        }

        if ( isset( $response['paymentCustomerId'] ) ) {
            update_user_meta( $user_id, '_ys_shopline_customer_id', $response['paymentCustomerId'] );
            return $response['paymentCustomerId'];
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
        $pay_session = isset( $_POST['ys_shopline_pay_session'] ) ? sanitize_text_field( wp_unslash( $_POST['ys_shopline_pay_session'] ) ) : '';

        if ( empty( $pay_session ) ) {
            wc_add_notice( __( 'Payment session missing. Please try again.', 'ys-shopline-via-woocommerce' ), 'error' );
            return array( 'result' => 'failure' );
        }

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

        // 檢查用戶是否選擇儲存卡片（從 SDK 回傳的 saveCard 參數）
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $save_card_from_sdk = isset( $_POST['ys_shopline_save_card'] ) && '1' === $_POST['ys_shopline_save_card'];

        // 訂閱訂單強制儲存卡片，否則依用戶選擇
        $save_card = $is_subscription || $save_card_from_sdk;

        // 決定付款行為：不儲存卡片時使用 QuickPayment
        $payment_behavior = $save_card ? 'CardBindPayment' : 'QuickPayment';

        $data = array(
            'paySession' => $pay_session,
            'amount'     => array(
                'value'    => YS_Shopline_Payment::get_formatted_amount( $order->get_total(), $order->get_currency() ),
                'currency' => $order->get_currency(),
            ),
            'confirm'    => array(
                'paymentBehavior'   => $payment_behavior,
                'autoConfirm'       => true,
                'autoCapture'       => true,
            ),
            'order'      => array(
                'referenceOrderId' => (string) $order->get_id(),
            ),
        );

        // 只有儲存卡片時才加入 paymentInstrument 設定
        if ( $save_card ) {
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

        // 記錄付款資料（用於除錯）
        YS_Shopline_Logger::debug( 'Payment data prepared', array(
            'order_id'         => $order->get_id(),
            'amount'           => $data['amount']['value'],
            'currency'         => $data['amount']['currency'],
            'payment_behavior' => $payment_behavior,
            'save_card'        => $save_card ? 'yes' : 'no',
            'is_subscription'  => $is_subscription ? 'yes' : 'no',
            'pay_session'      => substr( $pay_session, 0, 20 ) . '...',
        ) );

        return apply_filters( 'ys_shopline_payment_data', $data, $order, $this );
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

        // Redirect to custom pay handler
        return array(
            'result'   => 'success',
            'redirect' => add_query_arg(
                array(
                    'ys_shopline_pay' => '1',
                    'order_id'        => $order->get_id(),
                    'key'             => $order->get_order_key(),
                ),
                home_url()
            ),
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
     * @param int $order_id Order ID.
     */
    public function thankyou_page( $order_id ) {
        // Can be overridden by child classes
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
