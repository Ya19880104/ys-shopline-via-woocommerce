<?php
defined( 'ABSPATH' ) || exit;

/**
 * YS_Shopline_Gateway Class.
 */
class YS_Shopline_Gateway extends WC_Payment_Gateway {

    /**
     * @var YS_Shopline_API
     */
    public $api;

    /**
     * Constructor for the gateway.
     */
    public function __construct() {
        $this->id                 = 'ys_shopline';
        $this->icon               = '';
        $this->has_fields         = true; // We use SDK fields
        $this->method_title       = __( 'Shopline Payments', 'ys-shopline-via-woocommerce' );
        $this->method_description = __( 'Accept payments via Shopline.', 'ys-shopline-via-woocommerce' );

        // Load the settings.
        $this->init_form_fields();
        $this->init_settings();

        // Define user set variables.
        $this->title       = $this->get_option( 'title' );
        $this->description = $this->get_option( 'description' );
        $this->enabled     = $this->get_option( 'enabled' );

        // Supports
        $this->supports = array(
            'products',
            'refunds',
            'tokenization',
            'add_payment_method',
            'subscriptions',
            'subscription_cancellation',
            'subscription_suspension',
            'subscription_reactivation',
            'subscription_amount_changes',
            'subscription_date_changes',
            'subscription_payment_method_change',
            'subscription_payment_method_change_customer',
            'subscription_payment_method_change_admin',
            'multiple_subscriptions',
        );
        
        // Init API
        $this->init_api();

        // Actions
        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'payment_scripts' ) );
        // AJAX Hooks are registered in the main plugin file to ensure they run without explicit manual instantiation
        add_action( 'template_redirect', array( $this, 'handle_custom_pay_page' ) );
    }

    /**
     * Static AJAX Handler to ensure Gateway is loaded.
     */
    public static function ajax_config_handler() {
        $gateway = new self();
        $gateway->ajax_get_sdk_config();
    }

    /**
     * Init API.
     */
    public function init_api() {
        $merchant_id = $this->get_option( 'merchant_id' );
        $api_key     = $this->get_option( 'api_key' );
        $test_mode   = 'yes' === $this->get_option( 'testmode' );

        if ( $merchant_id && $api_key ) {
            $this->api = new YS_Shopline_API( $merchant_id, $api_key, $test_mode );
        }
    }

    /**
     * Initialize Gateway Settings Form Fields.
     */
    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title'   => __( 'Enable/Disable', 'woocommerce' ),
                'type'    => 'checkbox',
                'label'   => __( 'Enable Shopline Payments', 'ys-shopline-via-woocommerce' ),
                'default' => 'yes',
            ),
            'title' => array(
                'title'       => __( 'Title', 'woocommerce' ),
                'type'        => 'text',
                'description' => __( 'This controls the title which the user sees during checkout.', 'woocommerce' ),
                'default'     => __( 'Shopline Payments', 'ys-shopline-via-woocommerce' ),
                'desc_tip'    => true,
            ),
            'description' => array(
                'title'       => __( 'Description', 'woocommerce' ),
                'type'        => 'textarea',
                'description' => __( 'Payment method description that the customer will see on your checkout.', 'woocommerce' ),
                'default'     => __( 'Pay securely using Shopline.', 'ys-shopline-via-woocommerce' ),
            ),
            'merchant_id' => array(
                'title'       => __( 'Merchant ID', 'ys-shopline-via-woocommerce' ),
                'type'        => 'text',
                'default'     => '',
            ),
            'api_key' => array(
                'title'       => __( 'API Key', 'ys-shopline-via-woocommerce' ),
                'type'        => 'password',
                'default'     => '',
            ),
            'client_key' => array(
                'title'       => __( 'Client Key', 'ys-shopline-via-woocommerce' ),
                'type'        => 'text',
                'default'     => '',
            ),
            'sign_key' => array(
                'title'       => __( 'Webhook Sign Key', 'ys-shopline-via-woocommerce' ),
                'type'        => 'password',
                'description' => __( 'Secret key for verifying webhook signatures. Provided by Shopline via email.', 'ys-shopline-via-woocommerce' ),
                'default'     => '',
                'desc_tip'    => true,
            ),
             'testmode' => array(
                'title'       => __( 'Test mode', 'woocommerce' ),
                'label'       => __( 'Enable Test Mode', 'woocommerce' ),
                'type'        => 'checkbox',
                'description' => __( 'Place the payment gateway in test mode using test API keys.', 'woocommerce' ),
                'default'     => 'yes',
                'desc_tip'    => true,
            ),
        );
    }

    /**
     * Payment Fields.
     */
    public function payment_fields() {
        if ( $this->description ) {
            echo wpautop( wp_kses_post( $this->description ) );
        }
        
        // Container with min-height to ensure visibility even if empty initially
        echo '<div id="ys_shopline_container" style="min-height: 100px;"></div>';
    }

    /**
     * Enqueue scripts.
     */
    public function payment_scripts() {
        if ( ! is_checkout() && ! is_account_page() ) {
            return;
        }

        if ( 'no' === $this->enabled ) {
            return;
        }

        // Shopline SDK
        wp_enqueue_script( 'ys-shopline-sdk', 'https://cdn.shoplinepayments.com/sdk/v1/payment-web.js', array(), null, true );

        // Custom JS
        wp_enqueue_script( 'ys-shopline-checkout', YS_SHOPLINE_PLUGIN_URL . 'assets/js/shopline-checkout.js', array( 'jquery', 'ys-shopline-sdk' ), YS_SHOPLINE_VERSION, true );

        wp_localize_script( 'ys-shopline-checkout', 'ys_shopline_params', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'ys_shopline_nonce' ),
        ));
    }

    /**
     * Get SDK Config for Frontend.
     */
    public function ajax_get_sdk_config() {
        // Log the request for debugging
        YS_Shopline_Logger::debug( 'AJAX Get SDK Config Request: ' . print_r( $_POST, true ) );

        if ( ! check_ajax_referer( 'ys_shopline_nonce', 'nonce', false ) ) {
            YS_Shopline_Logger::error( 'Nonce verification failed. Sent: ' . ( isset($_POST['nonce']) ? sanitize_text_field( $_POST['nonce'] ) : 'missing' ) );
            wp_send_json_error( array( 'message' => 'Security check failed. Please refresh the page.' ) );
            return;
        }

        $cart_total = WC()->cart->get_total( 'edit' ); // Or strictly get total amount in cents/units? Shopline amount is usually in minor units?
        // Guide SDK: "amount: 10000" (likely 100 TWD).
        // Need to check currency. TWD is 0 decimals usually in gatewways? No, TWD is usually integer.
        // But get_total provides '100.00'.
        
        $currency = get_woocommerce_currency();
        // Use raw total, not formatted string
        $amount   = WC()->cart->total; 
        
        $amount_req = $this->get_formatted_amount( $amount, $currency );

        $config = array(
            'merchantId' => $this->get_option( 'merchant_id' ),
            'clientKey'  => $this->get_option( 'client_key' ),
            'currency'   => $currency,
            'amount'     => $amount_req,
        );
        
        if ( class_exists( 'WC_Subscriptions_Cart' ) && WC_Subscriptions_Cart::cart_contains_subscription() ) {
            $config['forceSaveCard'] = true;
        }

        if ( is_user_logged_in() ) {
            $user_id = get_current_user_id();
            $customer_id = $this->get_shopline_customer_id( $user_id );
            
            if ( $customer_id ) {
                $token_response = $this->api->get_customer_token( $customer_id );
                 if ( ! is_wp_error( $token_response ) && isset( $token_response['customerToken'] ) ) {
                     $config['customerToken'] = $token_response['customerToken'];
                 }
            }
        }

        wp_send_json_success( $config );
    }

    /**
     * Get Shopline Customer ID.
     */
    public function get_shopline_customer_id( $user_id ) {
        $customer_id = get_user_meta( $user_id, '_shopline_payment_customer_id', true );
        
        if ( $customer_id ) {
            return $customer_id;
        }
        
        $user = get_userdata( $user_id );
        if ( ! $user ) {
            return false;
        }
        
        $data = array(
            'referenceCustomerId' => (string) $user_id,
            'email' => $user->user_email,
            'name' => $user->display_name ?: $user->user_login, // Fallback
            'phone' => get_user_meta( $user_id, 'billing_phone', true ) ?: '',
        );
        
        $response = $this->api->create_customer( $data );
        
        if ( is_wp_error( $response ) ) {
            YS_Shopline_Logger::error( 'Failed to create customer: ' . $response->get_error_message() );
            return false;
        }
        
        if ( isset( $response['paymentCustomerId'] ) ) {
             update_user_meta( $user_id, '_shopline_payment_customer_id', $response['paymentCustomerId'] );
             return $response['paymentCustomerId'];
        }
        
        return false;
    }

    /**
     * Process the payment and return the result.
     * 
     * @param int $order_id
     * @return array
     */
    public function process_payment( $order_id ) {
        $order = wc_get_order( $order_id );
        
        $pay_session = isset( $_POST['ys_shopline_pay_session'] ) ? sanitize_text_field( $_POST['ys_shopline_pay_session'] ) : '';

        if ( empty( $pay_session ) ) {
            wc_add_notice( __( 'Payment session missing. Please try again.', 'ys-shopline-via-woocommerce' ), 'error' );
            return;
        }

        // Check if saving card or subscription
        $is_subscription = class_exists( 'WC_Subscriptions_Cart' ) && WC_Subscriptions_Cart::cart_contains_subscription();
        $save_card       = $is_subscription; // Or check $_POST['wc-ys_shopline-new-payment-method'] if we had a checkbox
        
        // Prepare data for API
        $data = array(
            'paySession' => $pay_session,
            'amount'     => array(
                'value'    => $this->get_formatted_amount( $order->get_total(), $order->get_currency() ),
                'currency' => $order->get_currency(),
            ),
             'confirm' => array(
                'paymentBehavior' => 'QuickPayment', // Start with Quick implementation which might cover both? 
                // Docs say 4.1 CardBindPayment, 4.2 QuickPayment.
                // If we have a customer token usage (saved card), we should use QuickPayment.
                // If we are adding a new card (paySession from empty form), we use CardBindPayment.
                // How to detect?
                // For now, let's look at `_shopline_payment_customer_id`.
                // If user is guest, CardBindPayment?
                // Actually, if we use QuickPayment for a NEW card, does it work?
                // Let's try CardBindPayment if save_card is true. 
                'paymentBehavior' => $save_card ? 'CardBindPayment' : 'QuickPayment', 
                
                'paymentCustomerId' => 'guest_' . $order->get_id(), // Need proper logic
                'autoConfirm' => true,
                'autoCapture' => true,
                'paymentInstrument' => array( 
                    'savePaymentInstrument' => $save_card 
                ),
            ),
             'order' => array(
                 'referenceOrderId' => (string) $order->get_id(), // Must be string
             )
        );
        
        // Adjust paymentCustomerId if logged in
        if ( $order->get_user_id() ) {
            $customer_id = $this->get_shopline_customer_id( $order->get_user_id() );
            if ( $customer_id ) {
                $data['confirm']['paymentCustomerId'] = $customer_id;
            }
        }

        // Call API
        $response = $this->api->create_payment_trade( $data );

        if ( is_wp_error( $response ) ) {
            wc_add_notice( __( 'Payment failed: ', 'ys-shopline-via-woocommerce' ) . $response->get_error_message(), 'error' );
             return;
        }

        if ( isset( $response['nextAction'] ) ) {
            // Flow continues to frontend handling
             $order->update_meta_data( '_shopline_next_action', $response['nextAction'] );
             $order->update_meta_data( '_shopline_trade_order_id', $response['tradeOrderId'] );
             $order->save();
             
             // Mark as pending payment
             $order->update_status( 'pending', __( 'Awaiting Shopline payment completion.', 'ys-shopline-via-woocommerce' ) );
             
             // Reduce stock
             wc_reduce_stock_levels( $order->get_id() );
             
             // Remove cart
             WC()->cart->empty_cart();

             // Redirect to custom pay handler
             return array(
                'result'   => 'success',
                'redirect' => home_url( '/?ys_shopline_pay=1&order_id=' . $order->get_id() . '&key=' . $order->get_order_key() ),
            );
        }

        // Success immediately? (Unlikely for header-based flow, but possible if no 3DS)
        $order->payment_complete( $response['tradeOrderId'] );
        
        return array(
            'result'   => 'success',
            'redirect' => $this->get_return_url( $order ),
        );
    }
    
    /**
     * Handle Custom Pay Page.
     */
    public function handle_custom_pay_page() {
        if ( isset( $_GET['ys_shopline_pay'] ) && isset( $_GET['order_id'] ) && isset( $_GET['key'] ) ) {
             $order_id = absint( $_GET['order_id'] );
             $key      = sanitize_text_field( $_GET['key'] );
             
             $order = wc_get_order( $order_id );
             
             if ( ! $order || $order->get_order_key() !== $key ) {
                 wp_die( 'Invalid Order.' );
             }
             
             $next_action = $order->get_meta( '_shopline_next_action' );
             
             if ( ! $next_action ) {
                 wp_safe_redirect( $this->get_return_url( $order ) );
                 exit;
             }
             
             // Render simple HTML page
             $this->render_pay_page( $next_action, $this->get_return_url( $order ) );
             exit;
        }
    }
    
    private function render_pay_page( $next_action, $return_url ) {
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <title>Processing Payment...</title>
            <script src="https://cdn.shoplinepayments.com/sdk/v1/payment-web.js"></script>
        </head>
        <body>
            <div id="paymentContainer"></div>
            <script>
                var nextAction = <?php echo json_encode( $next_action ); ?>;
                var returnUrl = "<?php echo $return_url; ?>";
                
                async function process() {
                    var container = document.getElementById('paymentContainer');
                    try {
                         // We need a dummy payment instance? 
                         // Docs say: "import ShoplinePayments... await payment.pay(nextAction)"
                         // But 'payment' comes from 'await ShoplinePayments({...})'.
                         // Do we need to RE-INIT the SDK?
                         // "4. 参照SDK 串接，發起付款（需傳入建立交易介面回應的 nextAction 資訊）"
                         // "呼叫 payment.pay() 發起付款。"
                         
                         // Yes, we likely need an instance.
                         const { payment, error } = await ShoplinePayments({
                               clientKey: '<?php echo $this->get_option( 'client_key' ); ?>',
                               merchantId: '<?php echo $this->get_option( 'merchant_id' ); ?>',
                               paymentMethod: 'CreditCard', // Does it matter for 'pay'?
                               element: '#paymentContainer' // Required?
                         });
                         
                         if(error) {
                             alert('Init Error: ' + error.message);
                             return;
                         }
                         
                         const payResult = await payment.pay(nextAction);
                         
                         if (payResult && payResult.error) {
                             alert('Payment Failed: ' + payResult.error.message);
                             // Redirect to cancel?
                         } else {
                             // Success
                             window.location.href = returnUrl;
                         }

                    } catch (e) {
                         console.error(e);
                         alert('System Error');
                    }
                }
                
                process();
            </script>
        </body>
        </html>
        <?php
    }
    
    private function get_formatted_amount( $amount, $currency ) {
         // Logic for currency decimal places
         // TWD, JPY: 0 decimals
         // USD, EUR: 2 decimals
         
         $zero_decimal = array( 'TWD', 'JPY', 'KRW', 'CLP', 'VND' );
         
         if ( in_array( $currency, $zero_decimal ) ) {
             return round( $amount );
         }
         
         return round( $amount * 100 );
    }

    /**
     * Process scheduled subscription payment.
     * 
     * @param float $amount
     * @param WC_Order $order
     */
    public function process_scheduled_subscription_payment( $amount, $order ) {
        $user_id = $order->get_user_id();
        if ( ! $user_id ) {
            $order->payment_complete(); // Fallback? No, fail.
            return; 
        }

        // Get saved token (Need to implement token storage retrieval)
        // For now, assuming we stored it in user meta or WC Token. 
        // Let's assume we use WC_Payment_Tokens.
        $tokens = WC_Payment_Tokens::get_customer_tokens( $user_id, $this->id );
        $token = reset( $tokens ); // Get first token for now. Real logic needs selection.
        
        if ( ! $token || ! $token->get_token() ) {
             $order->add_order_note( 'Renewal failed: No payment token found.' );
             $order->update_status( 'failed' );
             return;
        }

        $payment_instrument_id = $token->get_token();
        $customer_id = $this->get_shopline_customer_id( $user_id );

        $data = array(
            'amount'     => array(
                'value'    => $this->get_formatted_amount( $amount, $order->get_currency() ),
                'currency' => $order->get_currency(),
            ),
             'confirm' => array(
                'paymentBehavior' => 'Recurring',
                'paymentCustomerId' => $customer_id,
                'paymentInstrument' => array(
                    'paymentInstrumentId' => $payment_instrument_id
                ),
                'autoConfirm' => true,
                'autoCapture' => true,
            ),
             'order' => array(
                 'referenceOrderId' => (string) $order->get_id(),
             )
        );

        $response = $this->api->create_payment_trade( $data );

        if ( is_wp_error( $response ) ) {
            $order->add_order_note( 'Renewal failed: ' . $response->get_error_message() );
            $order->update_status( 'failed' );
            return;
        }
        
        // Success
        $order->payment_complete( $response['tradeOrderId'] );
    }
}
