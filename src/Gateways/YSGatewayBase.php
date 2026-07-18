<?php
/**
 * Base Gateway class for YS Shopline Payment.
 *
 * @package YangSheep\ShoplinePayment\Gateways
 */

namespace YangSheep\ShoplinePayment\Gateways;

defined( 'ABSPATH' ) || exit;

use YangSheep\ShoplinePayment\Utils\YSLogger;
use YangSheep\ShoplinePayment\Utils\YSOrderMeta;
use YangSheep\ShoplinePayment\Utils\YSTradeStatus;
use YangSheep\ShoplinePayment\Utils\YSApiError;
use YangSheep\ShoplinePayment\Api\YSApi;
use YangSheep\ShoplinePayment\Handlers\YSRedirectHandler;
use YangSheep\ShoplinePayment\Handlers\YSPaymentConfirmation;
use YangSheep\ShoplinePayment\Customer\YSCustomer;
use WC_Payment_Gateway;
use WC_Order;
use WC_Payment_Tokens;
use WC_Payment_Token_CC;
use WP_Error;
use Exception;

/**
 * YSGatewayBase Class.
 *
 * Abstract base class for all Shopline payment gateways.
 */
abstract class YSGatewayBase extends WC_Payment_Gateway {

    /**
     * API instance.
     *
     * @var YSApi
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

        // 外掛自訂開關只控制是否註冊 gateway；註冊後由 WooCommerce 原生付款設定控制啟閉。
        $this->enabled = $this->get_option( 'enabled', 'no' );

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

        // v3.5.2: Codex F8 — handle_pay_redirect / render_pay_page 移除
        // 原因：主 plugin 已在 priority 5 註冊 handle_3ds_redirect 處理同一組 query 並 exit，
        // 這個 gateway base 的 handler 實際從 v3.4.7 起就走不到（AJAX 綁卡流程接手）。
        // 保留舊碼會有兩個問題：
        //   1. 死碼含 amount:0 寫死（違反 v3.4.11 10100 規則）、raw result console log
        //   2. 未來 refactor 若誤觸就會復活舊 bug
        // 改由主 plugin 統一處理，若真的需要獨立 3DS 頁請重新設計。
    }

    /**
     * Initialize API.
     */
    protected function init_api() {
        $this->api = \YSShoplinePayment::get_api();
    }

    /**
     * Get payment method for SDK.
     *
     * @return string
     */
    abstract public function get_payment_method();

    /**
     * Check whether checkout payment method icons should be rendered.
     *
     * @return bool
     */
    protected function is_payment_icons_enabled() {
        return 'yes' === get_option( 'ys_shopline_payment_icons_enabled', 'yes' );
    }

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
            YS_SHOPLINE_PLUGIN_URL . 'assets/js/ys-shopline-checkout.js',
            array( 'jquery', 'ys-shopline-sdk' ),
            YS_SHOPLINE_VERSION,
            true
        );

        // 確保 Shopline SDK 容器內的卡片品牌圖示不被其他外掛隱藏
        wp_register_style( 'ys-shopline-checkout', false );
        wp_enqueue_style( 'ys-shopline-checkout' );
        wp_add_inline_style( 'ys-shopline-checkout', '#payment .ys-shopline-payment-container img { display: block !important; }' );

        // v3.5.8: 取得當前用戶的預設卡末 4 碼，供前端 auto-select default card 使用
        //
        // 重要：payment_scripts() 會被每個 SHOPLINE gateway（credit / installment /
        // subscription / BNPL / LinePay / ATM / Apple / JKO）各自呼叫一次，後者覆蓋前者。
        // 我們統一查 `ys_shopline_credit` 的 tokens — SHOPLINE saved cards 在所有 CC gateway
        // 之間共用，而非 CC gateway（LinePay/ATM 等）本來就沒 saved-card UI，last4 自然不作用。
        //
        // 優先順序：is_default() → fallback 用第一張 token
        $default_card_last4 = '';
        if ( is_user_logged_in() && class_exists( 'WC_Payment_Tokens' ) ) {
            $tokens = WC_Payment_Tokens::get_customer_tokens( get_current_user_id(), 'ys_shopline_credit' );
            $chosen = null;
            foreach ( $tokens as $token ) {
                if ( $token->is_default() ) {
                    $chosen = $token;
                    break;
                }
            }
            if ( ! $chosen && ! empty( $tokens ) ) {
                $chosen = reset( $tokens );
            }
            if ( $chosen && method_exists( $chosen, 'get_last4' ) ) {
                $candidate_last4 = (string) $chosen->get_last4();
                // v3.5.36: 只有該 last4 唯一對應一個 distinct instrument 才送給前端自動選卡；
                // 撞號（同末四碼的不同卡）時不送 → 前端不自動點選、改由使用者手選，避免點錯卡。
                if ( 1 === count( $this->distinct_instrument_ids_for_last4( get_current_user_id(), $candidate_last4 ) ) ) {
                    $default_card_last4 = $candidate_last4;
                } else {
                    YSLogger::info( 'Default card last4 maps to multiple instruments, skip auto-select', array(
                        'user_id' => get_current_user_id(),
                        'last4'   => $candidate_last4,
                    ) );
                }
            }
        }

        // Localize script
        wp_localize_script(
            'ys-shopline-checkout',
            'ys_shopline_params',
            array(
                'ajax_url'            => admin_url( 'admin-ajax.php' ),
                'nonce'               => wp_create_nonce( 'ys_shopline_nonce' ),
                'gateway_id'          => $this->id,
                'default_card_last4'  => $default_card_last4, // v3.5.8
                'i18n'                => array(
                    'payment_error'      => __( 'Payment error occurred. Please try again.', 'ys-shopline-via-woocommerce' ),
                    'config_error'       => __( 'Configuration error. Please contact support.', 'ys-shopline-via-woocommerce' ),
                    'sdk_error'          => __( 'Payment SDK failed to load. Please refresh the page.', 'ys-shopline-via-woocommerce' ),
                    'processing'         => __( 'Processing payment...', 'ys-shopline-via-woocommerce' ),
                    'applepay_unsupported' => __( '此裝置或瀏覽器不支援 Apple Pay。請使用 iPhone/iPad/Mac 上的 Safari 瀏覽器。', 'ys-shopline-via-woocommerce' ),
                    'payment_not_ready'  => __( '付款尚未準備就緒，請稍候再試。', 'ys-shopline-via-woocommerce' ),
                    'payment_component_timeout' => __( '付款元件載入逾時，請重新整理頁面後再試。', 'ys-shopline-via-woocommerce' ),
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
        // 檢查是否是 add_payment_method 頁面（AJAX 請求時從 POST 判斷）
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $is_add_payment_method = is_add_payment_method_page() || ( isset( $_POST['is_add_payment_method'] ) && '1' === $_POST['is_add_payment_method'] );

        // 取得金額：優先從訂單付款頁面取得，否則從購物車
        $amount_raw    = 0;
        $currency      = get_woocommerce_currency();
        $bind_only_mode = false; // 純綁卡模式（CardBind + amount=10000 對齊官方範例）

        // AJAX 傳入的 order_id（pay-for-order 頁面的 SDK config 請求）
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $ajax_order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;

        // v3.4.15: 訂閱變更付款方式情境 — WCS 的 order total 可能是 $0，SDK 需走 CardBind 綁卡模式
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $is_change_payment_method = isset( $_GET['change_payment_method'] ) || ( isset( $_POST['woocommerce_change_payment'] ) );

        // 新增卡片頁面 / 訂閱變更付款方式：純綁卡模式，短路回傳不做任何金額查詢
        if ( $is_add_payment_method || $is_change_payment_method ) {
            $amount_raw     = 0;
            $bind_only_mode = true;
        }
        // Pay-for-order（AJAX 來源）
        elseif ( $ajax_order_id ) {
            $order = wc_get_order( $ajax_order_id );
            // v3.4.15: 訂閱變更付款方式的 pay-for-order 訂單 total 可能為 0 且 needs_payment()=false
            //          所以改用「能 wc_get_order 就處理」，並在 total<=0 時走 bind-only
            if ( $order ) {
                // 驗證訂單所有權：登入用戶比對 user_id，訪客比對 order_key
                // phpcs:ignore WordPress.Security.NonceVerification.Missing
                $ajax_order_key  = isset( $_POST['order_key'] ) ? sanitize_text_field( wp_unslash( $_POST['order_key'] ) ) : '';
                $current_user_id = get_current_user_id();
                $is_owner        = $current_user_id && (int) $order->get_user_id() === $current_user_id;
                $is_guest_valid  = ! $current_user_id && 0 === (int) $order->get_user_id() && $ajax_order_key && $order->get_order_key() === $ajax_order_key;

                // v3.4.16: 訂閱變更付款方式 — JS 端帶 is_change_payment_method=1 flag
                // phpcs:ignore WordPress.Security.NonceVerification.Missing
                $is_change_payment = isset( $_POST['is_change_payment_method'] ) && '1' === (string) $_POST['is_change_payment_method'];

                if ( $is_owner || $is_guest_valid ) {
                    $raw_total = (float) $order->get_total();
                    if ( $is_change_payment || $raw_total <= 0 ) {
                        // 訂閱 change_payment_method 或訂單 total<=0 → 走 CardBind 綁卡模式
                        $amount_raw     = 0;
                        $bind_only_mode = true;
                    } else {
                        $amount_raw = $raw_total;
                    }
                    $currency = $order->get_currency();
                }
            }
        }
        // 檢查是否是訂單付款頁面（直接渲染，非 AJAX）
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        elseif ( isset( $_GET['pay_for_order'] ) && isset( $_GET['key'] ) ) {
            global $wp;
            $order_id = isset( $wp->query_vars['order-pay'] ) ? absint( $wp->query_vars['order-pay'] ) : 0;
            if ( $order_id ) {
                $order = wc_get_order( $order_id );
                if ( $order ) {
                    // 訂單總額為 0（如訂閱 change_payment 情境）→ 改綁卡模式
                    $raw_total = (float) $order->get_total();
                    if ( $raw_total <= 0 ) {
                        $amount_raw     = 0;
                        $bind_only_mode = true;
                    } else {
                        $amount_raw = $raw_total;
                    }
                    $currency = $order->get_currency();
                }
            }
        }
        // 如果不是訂單付款頁面，從購物車取得
        elseif ( WC()->cart ) {
            $amount_raw = WC()->cart->get_total( 'edit' );
        }

        // SDK 和 API 都需要金額 × 100（台幣 1 元 = 100）
        $amount = \YSShoplinePayment::get_sdk_amount( $amount_raw );

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
            'bindOnlyMode'  => $bind_only_mode, // 純綁卡模式標記（前端用 amount=10000 對齊官方 CardBind 範例）
        );

        // v3.4.15: bind-only 情境（訂閱變更付款方式 / 新增付款方式 / $0 訂閱試用）一律強制儲存卡片
        // 否則卡片不會 tokenize，後續續扣會取不到 instrument_id
        // 與 YSCreditSubscription 首次結帳的 forceSaveCard=true 行為對齊
        if ( $bind_only_mode ) {
            $config['forceSaveCard'] = true;
        }

        // Debug log for troubleshooting
        YSLogger::debug( 'SDK Config generated', array(
            'gateway'               => $this->id,
            'testmode'              => $this->testmode ? 'yes' : 'no',
            'merchantId'            => $merchant_id ? substr( $merchant_id, 0, 8 ) . '...' : '(empty)',
            'clientKey'             => $client_key ? substr( $client_key, 0, 8 ) . '...' : '(empty)',
            'env'                   => $config['env'],
            'amount'                => $amount,
            'bind_only_mode'        => $bind_only_mode ? 'yes' : 'no',
            'force_save_card'       => ! empty( $config['forceSaveCard'] ) ? 'yes' : 'no',
            'is_add_payment_method' => $is_add_payment_method ? 'yes' : 'no',
        ) );

        // Check for subscription in cart
        if ( class_exists( 'WC_Subscriptions_Cart' ) && \WC_Subscriptions_Cart::cart_contains_subscription() ) {
            $config['forceSaveCard'] = true;
        }

        // 已登入用戶：取得 customerToken 用於儲存卡片功能
        // 訪客：不傳 customerToken（不支援儲存卡片）
        $user_id = get_current_user_id();
        if ( $user_id ) {
            $customer_token = $this->get_customer_token( $user_id );
            if ( $customer_token ) {
                $config['customerToken'] = $customer_token;
                YSLogger::debug( 'Customer token added to SDK config', array(
                    'user_id' => $user_id,
                ) );
            }
        }

        // bind-only 情境（新增付款方式 / 訂閱變更付款方式 / $0 訂閱試用）一律強制綁卡。
        // v3.5.30: mustAccept 改 false + 補 textType，對齊 SLP 客服建議的綁卡 protocol。
        //   switchVisible=false（隱藏開關）+ defaultSwitchStatus=true（預設綁）+
        //   mustAccept=false（不需顧客額外點選同意）= 靜默強制綁卡，SLP 才會真正建立 paymentInstrument。
        // v3.5.32: 移除 textType（避免 4208 initData.paymentInstrument 參數異常）。
        if ( $bind_only_mode ) {
            $config['paymentInstrument'] = array(
                'bindCard' => array(
                    'enable'   => true,
                    'protocol' => array(
                        'switchVisible'       => false,  // 隱藏開關
                        'defaultSwitchStatus' => true,   // 預設綁卡
                        'mustAccept'          => false,  // 不需額外同意（隱藏開關下 mustAccept=true 會讓 SLP 不綁卡）
                    ),
                ),
            );
            $config['forceSaveCard'] = true;

            // 新增付款方式頁額外去掉 customerToken（官方範例：純綁卡 SDK 不該看到已綁卡列表）
            // change_payment_method 則保留 customerToken（讓使用者能選已綁卡片）
            if ( $is_add_payment_method ) {
                unset( $config['customerToken'] );
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
            YSLogger::debug( 'Cannot get customer token: no customerId', array(
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
            YSLogger::error( 'Failed to get customer token', array(
                'error'       => $response->get_error_message(),
                'customer_id' => $customer_id,
            ) );
            return false;
        }

        if ( isset( $response['customerToken'] ) ) {
            YSLogger::debug( 'Customer token retrieved', array(
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
        $customer_id = get_user_meta( $user_id, YSOrderMeta::CUSTOMER_ID, true );

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
            YSLogger::error( 'Failed to create customer: ' . $response->get_error_message() );
            return false;
        }

        // 新版 API 回應欄位為 customerId
        if ( isset( $response['customerId'] ) ) {
            update_user_meta( $user_id, YSOrderMeta::CUSTOMER_ID, $response['customerId'] );
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
            YSLogger::error( 'process_payment: order not found', array( 'order_id' => $order_id ) );
            wc_add_notice( __( 'Order not found.', 'ys-shopline-via-woocommerce' ), 'error' );
            return array( 'result' => 'failure', 'remote_outcome' => 'rejected' );
        }

        $active_confirmation = YSPaymentConfirmation::get_active_attempt( $order );
        if ( YSPaymentConfirmation::STATUS_KEY === $order->get_status() || ! empty( $active_confirmation ) ) {
            if ( ! empty( $active_confirmation ) && YSPaymentConfirmation::STATUS_KEY !== $order->get_status() ) {
                YSPaymentConfirmation::enter_confirmation( $order, $active_confirmation, 'pre_create_guard' );
            }
            $message = __( '付款結果確認中，為避免重複扣款，此訂單目前無法再次付款。', 'ys-shopline-via-woocommerce' );
            wc_add_notice( $message, 'notice' );
            return array(
                'result'         => 'success',
                'redirect'       => $this->get_return_url( $order ),
                'remote_outcome' => 'unknown',
                'messages'       => $message,
            );
        }

        // v3.5.1: 進入 process_payment 就立刻寫 note，確保訂單永遠有 audit trail
        $order->add_order_note( sprintf(
            /* translators: %s: gateway id */
            __( 'Shopline 金流：開始處理付款（gateway=%s）', 'ys-shopline-via-woocommerce' ),
            $this->id
        ) );

        // v3.5.36 P0：付款結果不明（indeterminate）時，於「產生新 reference/冪等鍵前」封鎖再建交易，
        // 避免雙扣。先嘗試收斂（webhook 已接管 / query_session 正向接管）；仍不明則回 unknown 封鎖。
        $indeterminate = $this->resolve_indeterminate( $order );
        if ( 'blocked' === $indeterminate ) {
            YSLogger::warning( 'process_payment blocked: prior payment result indeterminate', array( 'order_id' => $order_id ) );
            return $this->indeterminate_blocked_response();
        }

        // 防呆：訂單已付款成功，不再重複呼叫 API
        if ( in_array( $order->get_status(), array( 'processing', 'completed', 'on-hold' ), true ) ) {
            YSLogger::warning( 'Duplicate payment attempt blocked (status)', array(
                'order_id' => $order_id,
                'status'   => $order->get_status(),
            ) );
            $order->add_order_note( sprintf(
                /* translators: %s: order status */
                __( 'Shopline 金流：偵測到重複提交，訂單已處於狀態 %s，直接導向感謝頁', 'ys-shopline-via-woocommerce' ),
                $order->get_status()
            ) );

            return array(
                'result'   => 'success',
                'redirect' => $this->get_return_url( $order ),
                'remote_outcome' => 'accepted', // 訂單已付款：不還原付款方式
            );
        }

        // 防呆：訂單已有 tradeOrderId 代表 API 已呼叫過（含 3DS pending 流程）
        // 查詢前一筆交易狀態，決定是否允許重新付款
        $existing_trade_id = $order->get_meta( YSOrderMeta::TRADE_ORDER_ID );
        if ( ! empty( $existing_trade_id ) && 'pending' === $order->get_status() ) {
            $order->add_order_note( sprintf(
                /* translators: %s: trade order id */
                __( 'Shopline 金流：偵測到既存交易（%s），查詢前次狀態中...', 'ys-shopline-via-woocommerce' ),
                $existing_trade_id
            ) );
            $prior_result = $this->check_prior_trade_status( $order, $existing_trade_id );
            if ( null !== $prior_result ) {
                return $prior_result;
            }
            // v3.5.35: 放行原因與 note 由 resolve_prior_trade 記錄（終態放行/取消後放行）
        }

        // Get pay session from POST
        // paySession 從 SDK createPayment() 返回，可能是 JSON 字串或已序列化的物件
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $pay_session_raw = isset( $_POST['ys_shopline_pay_session'] ) ? wp_unslash( $_POST['ys_shopline_pay_session'] ) : '';

        if ( empty( $pay_session_raw ) ) {
            YSLogger::error( 'process_payment: paySession empty', array( 'order_id' => $order_id ) );
            $order->add_order_note( __( 'Shopline 金流：付款失敗 — paySession 資料遺失（前端 SDK 未正確回傳）', 'ys-shopline-via-woocommerce' ) );
            $order->update_meta_data( YSOrderMeta::PAYMENT_STATUS, 'MISSING_PAY_SESSION' );
            $order->save();
            wc_add_notice( __( 'Payment session missing. Please try again.', 'ys-shopline-via-woocommerce' ), 'error' );
            return array( 'result' => 'failure', 'remote_outcome' => 'rejected' );
        }

        // 嘗試解析 paySession
        // 根據 SHOPLINE API 文件，paySession 應該是 "JSON String" 類型
        // 意味著 API 期望收到的是 JSON 字串值，而不是物件
        $pay_session = $pay_session_raw;

        // 驗證 paySession 是有效的 JSON（至少能解析）
        // v3.4.15: paySession 為敏感載荷（含 sessionId / device fingerprint），log 僅記 hash 不留原文
        $decoded = json_decode( $pay_session_raw, true );
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            YSLogger::error( 'Invalid paySession JSON', array(
                'error'  => json_last_error_msg(),
                'length' => strlen( $pay_session_raw ),
                'hash'   => substr( hash( 'sha256', $pay_session_raw ), 0, 8 ),
            ) );
            $order->add_order_note( sprintf(
                /* translators: %s: json decode error */
                __( 'Shopline 金流：付款失敗 — paySession 格式錯誤（%s）', 'ys-shopline-via-woocommerce' ),
                json_last_error_msg()
            ) );
            $order->update_meta_data( YSOrderMeta::PAYMENT_STATUS, 'INVALID_PAY_SESSION' );
            $order->save();
            wc_add_notice( __( 'Invalid payment session. Please try again.', 'ys-shopline-via-woocommerce' ), 'error' );
            return array( 'result' => 'failure', 'remote_outcome' => 'rejected' );
        }

        YSLogger::debug( 'PaySession received', array(
            'type'          => gettype( $pay_session_raw ),
            'length'        => strlen( $pay_session_raw ),
            'decoded_ok'    => $decoded !== null ? 'yes' : 'no',
            'has_sessionId' => isset( $decoded['sessionId'] ) ? 'yes' : 'no',
            'hash'          => substr( hash( 'sha256', $pay_session_raw ), 0, 8 ),
            'decoded_keys'  => $decoded !== null ? array_keys( $decoded ) : array(),
        ) );

        // Check API
        if ( ! $this->api ) {
            YSLogger::error( 'process_payment: API not configured', array( 'order_id' => $order_id ) );
            $order->add_order_note( __( 'Shopline 金流：付款失敗 — gateway API 未設定（商家後台 API 金鑰缺失）', 'ys-shopline-via-woocommerce' ) );
            $order->update_meta_data( YSOrderMeta::PAYMENT_STATUS, 'API_NOT_CONFIGURED' );
            $order->save();
            wc_add_notice( __( 'Payment gateway not configured.', 'ys-shopline-via-woocommerce' ), 'error' );
            return array( 'result' => 'failure', 'remote_outcome' => 'rejected' );
        }

        // Prepare payment data
        $payment_data = $this->prepare_payment_data( $order, $pay_session );
        $this->store_payment_attempt_data( $order, $payment_data, is_array( $decoded ) ? $decoded : array() );

        // Create payment trade（帶冪等鍵，同一次 attempt 重送不會重複扣款）
        $idempotent_key = (string) $order->get_meta( YSOrderMeta::REFERENCE_ORDER_ID );
        $response       = $this->api->create_payment_trade( $payment_data, $idempotent_key );

        if ( is_wp_error( $response ) ) {
            $raw_error    = $response->get_error_message();
            $error_code   = (string) $response->get_error_code();
            $friendly_msg = YSRedirectHandler::humanize_error_message( $raw_error );
            // v3.5.36 P0：分類 rejected（確定不會收款，可安全還原/重試）vs unknown（狀態不明，可能已建交易）
            $outcome      = YSApiError::classify( $response );

            YSLogger::error(
                'Payment API error after create_payment_trade',
                array_merge(
                    array(
                        'order_id'       => $order->get_id(),
                        'remote_outcome' => $outcome,
                    ),
                    YSApiError::diagnostic_context( $response )
                )
            );

            if ( 'unknown' === $outcome ) {
                // 狀態不明（timeout/空回應/解析失敗/1001/4003/4458/1018…）：遠端可能已建立交易但
                // 我方未收到明確回應。標記 indeterminate 並封鎖後續「用新 reference/冪等鍵再建交易」，
                // 避免雙扣；等 webhook（exact reference）或 query_session 正向接管收斂。**不**寫 ERROR 終態。
                $this->mark_indeterminate( $order, $pay_session, $idempotent_key, $payment_data );
                $order->add_order_note( sprintf(
                    /* translators: 1: payment method, 2: error code */
                    __( 'Shopline 金流：付款結果尚待確認（%1$s，碼 %2$s）——金流端可能已建立交易但未收到回應。已鎖定本訂單避免重複建立交易，系統將於確認後自動更新。', 'ys-shopline-via-woocommerce' ),
                    $this->get_payment_method(),
                    '' !== $error_code ? $error_code : 'n/a'
                ) );
                $order->update_meta_data( YSOrderMeta::PAYMENT_STATUS, 'INDETERMINATE' );
                $order->save();

                $msg = __( '付款結果確認中，請勿重複付款；若已扣款我們會自動更新訂單，若未扣款請稍後再試。', 'ys-shopline-via-woocommerce' );
                wc_add_notice( $msg, 'notice' );
                if ( YSPaymentConfirmation::enter_from_order(
                    $order,
                    'INDETERMINATE',
                    array(),
                    'gateway_create_unknown',
                    array(
                        'gateway'         => $this->id,
                        'shopline_method' => $this->get_payment_method(),
                    )
                ) ) {
                    WC()->cart->empty_cart();
                    return array(
                        'result'         => 'success',
                        'redirect'       => $this->get_return_url( $order ),
                        'remote_outcome' => 'unknown',
                        'messages'       => $msg,
                    );
                }
                return array(
                    'result'         => 'failure',
                    'remote_outcome' => 'unknown',
                    'messages'       => $msg,
                );
            }

            // rejected：確定不會收款 → 記 ERROR、導 pay-for-order 重試（消費端可安全還原付款方式）
            $order->add_order_note(
                sprintf(
                    /* translators: 1: Payment method name, 2: Error message */
                    __( 'Shopline API 呼叫被拒（%1$s）：%2$s', 'ys-shopline-via-woocommerce' ),
                    $this->get_payment_method(),
                    $raw_error
                )
            );
            $order->update_meta_data( YSOrderMeta::PAYMENT_STATUS, 'ERROR' );
            $order->update_meta_data( YSOrderMeta::ERROR_CODE, $error_code );
            $order->update_meta_data( YSOrderMeta::ERROR_MESSAGE, $friendly_msg );
            $order->save();

            wc_add_notice( $friendly_msg, 'error' );
            return array(
                'result'         => 'success',
                'redirect'       => $order->get_checkout_payment_url(),
                'remote_outcome' => 'rejected',
            );
        }

        // v3.5.36 P0：SHOPLINE 成功回應契約要求 tradeOrderId 必填。缺 ID 的回應（不論 nextAction /
        // CREATED / 其他狀態）都無法確定是否真的建立交易、也沒有交易 ID 可供後續比對 →
        // 一律標記 indeterminate 並封鎖後續再建交易，避免「下一次遞增 reference 再建第二筆」的雙扣。
        if ( empty( $response['tradeOrderId'] ) ) {
            YSLogger::warning(
                'process_payment: success-ish response missing tradeOrderId → indeterminate',
                array_merge(
                    array( 'order_id' => $order->get_id() ),
                    YSApiError::diagnostic_context( $response )
                )
            );
            $this->mark_indeterminate( $order, $pay_session, $idempotent_key, $payment_data );
            $order->update_meta_data( YSOrderMeta::PAYMENT_STATUS, 'INDETERMINATE' );
            $order->add_order_note( __( 'Shopline 金流：付款回應缺少交易編號（tradeOrderId），無法確認是否已建立交易，已鎖定訂單避免重複建立。系統將於 webhook/查詢確認後更新。', 'ys-shopline-via-woocommerce' ) );
            $order->save();
            if ( YSPaymentConfirmation::enter_from_order(
                $order,
                'INDETERMINATE',
                $response,
                'gateway_missing_trade_id',
                array(
                    'gateway'         => $this->id,
                    'shopline_method' => $this->get_payment_method(),
                )
            ) ) {
                WC()->cart->empty_cart();
                $blocked = $this->indeterminate_blocked_response();
                $blocked['result']   = 'success';
                $blocked['redirect'] = $this->get_return_url( $order );
                return $blocked;
            }
            return $this->indeterminate_blocked_response();
        }

        // Store trade order ID
        $order->update_meta_data( YSOrderMeta::TRADE_ORDER_ID, $response['tradeOrderId'] );
        $order->save();

        // Handle next action (3DS, redirect, etc.)
        if ( isset( $response['nextAction'] ) ) {
            return $this->handle_next_action( $order, $response );
        }

        // Use the same tri-state contract as scheduled recurring charges. A trade ID
        // alone does not make an unknown/terminal status accepted.
        $status  = strtoupper( trim( (string) ( $response['status'] ?? '' ) ) );
        $outcome = YSApiError::classify_create_trade_response( $response );

        if ( 'rejected' === $outcome ) {
            $order->update_meta_data( YSOrderMeta::PAYMENT_STATUS, $status );
            $order->add_order_note( sprintf(
                /* translators: %s: terminal payment status */
                __( 'Shopline 付款未成立（狀態：%s），可安全重新選擇付款方式。', 'ys-shopline-via-woocommerce' ),
                $status
            ) );
            $order->save();

            wc_add_notice( __( '付款未完成，請重新選擇付款方式。', 'ys-shopline-via-woocommerce' ), 'error' );
            return array(
                'result'         => 'success',
                'redirect'       => $order->get_checkout_payment_url(),
                'remote_outcome' => 'rejected',
            );
        }

        if ( 'accepted' === $outcome && ! YSTradeStatus::is_paid( $status ) ) {
            if ( YSTradeStatus::is_customer_pending( $status ) ) {
                if ( 'pending' !== $order->get_status() ) {
                    $order->update_status( 'pending' );
                }
                $order->update_meta_data( YSOrderMeta::PAYMENT_STATUS, $status );
                $order->save();
                WC()->cart->empty_cart();
                return array(
                    'result'         => 'success',
                    'redirect'       => $this->get_return_url( $order ),
                    'remote_outcome' => 'accepted',
                );
            }

            if ( 'ys_shopline_atm' !== $this->id
                && YSTradeStatus::is_in_flight( $status )
                && YSPaymentConfirmation::enter_from_order(
                    $order,
                    $status,
                    $response,
                    'gateway_create_response',
                    array(
                        'gateway'         => $this->id,
                        'shopline_method' => $this->get_payment_method(),
                    )
                ) ) {
                WC()->cart->empty_cart();
                return array(
                    'result'         => 'success',
                    'redirect'       => $this->get_return_url( $order ),
                    'remote_outcome' => 'accepted',
                );
            }

            $order->update_status( 'on-hold', sprintf(
                /* translators: %s: payment status */
                __( 'Shopline 付款處理中，狀態：%s，等待 webhook 確認。', 'ys-shopline-via-woocommerce' ),
                $status
            ) );
            $order->update_meta_data( YSOrderMeta::PAYMENT_STATUS, $status );
            $order->save();

            WC()->cart->empty_cart();

            return array(
                'result'         => 'success',
                'redirect'       => $this->get_return_url( $order ),
                'remote_outcome' => 'accepted',
            );
        }

        if ( 'unknown' === $outcome ) {
            YSLogger::error(
                'Payment returned unknown status with trade ID',
                array_merge(
                    array( 'order_id' => $order->get_id() ),
                    YSApiError::diagnostic_context( $response )
                )
            );
            $order->add_order_note( sprintf(
                /* translators: %s: payment status */
                __( 'Shopline 付款回傳未知狀態：%s。交易編號已保留，確認狀態前不會建立新交易。', 'ys-shopline-via-woocommerce' ),
                $status ?: '(empty)'
            ) );
            $order->update_meta_data( YSOrderMeta::PAYMENT_STATUS, $status ?: 'UNKNOWN' );
            $order->save();

            wc_add_notice( __( '付款結果確認中，請勿重複付款。', 'ys-shopline-via-woocommerce' ), 'notice' );
            if ( YSPaymentConfirmation::enter_from_order(
                $order,
                $status ?: 'UNKNOWN',
                $response,
                'gateway_unknown_status',
                array(
                    'gateway'         => $this->id,
                    'shopline_method' => $this->get_payment_method(),
                )
            ) ) {
                WC()->cart->empty_cart();
                return array(
                    'result'         => 'success',
                    'redirect'       => $this->get_return_url( $order ),
                    'remote_outcome' => 'unknown',
                );
            }
            return array(
                'result'         => 'success',
                'redirect'       => $order->get_checkout_payment_url(),
                'remote_outcome' => 'unknown',
            );
        }

        // Payment completed immediately (confirmed SUCCEEDED/CAPTURED)
        $order->payment_complete( isset( $response['tradeOrderId'] ) ? $response['tradeOrderId'] : '' );
        // 套用商家自訂的付款成功訂單狀態
        $custom_paid_status = get_option( 'ys_shopline_order_status_paid', '' );
        if ( $custom_paid_status && $custom_paid_status !== $order->get_status() ) {
            $order->update_status( $custom_paid_status, __( '依商家設定更新訂單狀態。', 'ys-shopline-via-woocommerce' ) );
        }
        $order->add_order_note( __( 'Shopline payment completed.', 'ys-shopline-via-woocommerce' ) );

        // Empty the cart
        WC()->cart->empty_cart();

        return array(
            'result'   => 'success',
            'redirect' => $this->get_return_url( $order ),
            'remote_outcome' => 'accepted', // 立即成功（SUCCEEDED/CAPTURED）
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
        // SDK 內建選卡 UI 時，由前端塞入 ys_shopline_payment_instrument_id
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $payment_instrument_id = isset( $_POST['ys_shopline_payment_instrument_id'] ) ? sanitize_text_field( wp_unslash( $_POST['ys_shopline_payment_instrument_id'] ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $payment_instrument_mode = isset( $_POST['ys_shopline_payment_instrument_mode'] ) ? sanitize_key( wp_unslash( $_POST['ys_shopline_payment_instrument_mode'] ) ) : '';
        if ( ! in_array( $payment_instrument_mode, array( 'regular', 'new', 'new_save', 'saved' ), true ) ) {
            $payment_instrument_mode = '';
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $saved_card_last4 = isset( $_POST['ys_shopline_saved_card_last4'] ) ? preg_replace( '/[^0-9]/', '', (string) wp_unslash( $_POST['ys_shopline_saved_card_last4'] ) ) : '';
        if ( strlen( $saved_card_last4 ) !== 4 ) {
            $saved_card_last4 = '';
        }

        $user_id     = $order->get_user_id();
        $customer_id = $user_id ? $this->get_shopline_customer_id( $user_id ) : false;

        if ( '' === $payment_instrument_id && 'saved' === $payment_instrument_mode && $user_id ) {
            $payment_instrument_id = $this->resolve_payment_instrument_id_by_last4( $user_id, $saved_card_last4 );
            if ( '' === $payment_instrument_id ) {
                YSLogger::warning( 'Saved card selected but WC token could not be resolved, falling back without savePaymentInstrument', array(
                    'order_id' => $order->get_id(),
                    'user_id'  => $user_id,
                    'last4'    => $saved_card_last4,
                ) );
            }
        }

        // IDOR 防護：驗證此 instrument_id 屬於當前訂單的使用者
        // 避免攻擊者攔截 POST 把 instrument_id 改為他人的（雖然 SHOPLINE 會驗 paymentCustomerId↔instrument 綁定，但不依賴遠端防線）
        if ( '' !== $payment_instrument_id && $order->get_user_id() ) {
            $owned = false;
            $user_tokens = \WC_Payment_Tokens::get_customer_tokens( $order->get_user_id(), YSOrderMeta::CREDIT_GATEWAY_ID );
            foreach ( $user_tokens as $user_token ) {
                if ( $user_token->get_token() === $payment_instrument_id ) {
                    $owned = true;
                    break;
                }
            }
            if ( ! $owned ) {
                YSLogger::warning( 'IDOR guard: instrument_id not owned by current user, falling back to Regular/CardBindPayment', array(
                    'order_id'      => $order->get_id(),
                    'user_id'       => $order->get_user_id(),
                    'instrument_id' => substr( $payment_instrument_id, -6 ),
                ) );
                $payment_instrument_id = '';
            }
        } elseif ( '' !== $payment_instrument_id && ! $order->get_user_id() ) {
            // 訪客訂單不應該有 instrument_id（訪客沒 WC Token）
            YSLogger::warning( 'Guest order should not carry instrument_id, ignoring', array(
                'order_id' => $order->get_id(),
            ) );
            $payment_instrument_id = '';
        }

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

        // Frontend sends this only when the customer explicitly chose "new card + save".
        // This prevents saved-card installment payments from being mis-sent as CardBindPayment.
        $client_bind_card_enabled = false;
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ( isset( $_POST['ys_shopline_bind_card_enabled'] ) ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing
            $client_bind_card_enabled = '1' === sanitize_text_field( wp_unslash( $_POST['ys_shopline_bind_card_enabled'] ) );
        }

        // 決定付款行為（paymentBehavior）：
        // - Regular: 一般信用卡付款（輸入卡號，不綁卡）
        // - CardBindPayment: 綁卡付款（輸入卡號並儲存）- SDK 啟用 bindCard 時使用
        // - QuickPayment: 快捷付款（使用已綁定的卡片，需要 paymentCustomerId；SDK paySession 可攜帶選卡結果）
        //
        // Installment uses the CreditCard SDK with three explicit modes:
        // saved -> QuickPayment, new_save -> CardBindPayment, new/missing -> Regular.
        // Frontend code captures the mode before createPayment() mutates the SDK DOM.
        if ( 'ys_shopline_credit_installment' === $this->id ) {
            if ( in_array( $payment_instrument_mode, array( 'saved', 'new_save' ), true ) ) {
                $use_bind_card = $user_id && $customer_id;
            } else {
                $use_bind_card = false;
            }
        }

        // Legacy fallback: if an older frontend sends no explicit instrument mode and the SDK
        // does not expose paymentInstrumentId, avoid blindly sending CardBindPayment for a
        // customer who already has saved tokens.
        //
        // Current frontend sends ys_shopline_payment_instrument_mode, so new_save can still
        // be processed as CardBindPayment without this fallback.
        if ( 'ys_shopline_credit_installment' !== $this->id
            && '' === $payment_instrument_mode
            && $use_bind_card && empty( $payment_instrument_id ) && $user_id && ! $is_subscription
            && class_exists( 'WC_Payment_Tokens' ) ) {
            $existing_tokens = WC_Payment_Tokens::get_customer_tokens( $user_id, 'ys_shopline_credit' );
            if ( ! empty( $existing_tokens ) ) {
                YSLogger::debug( 'CardBindPayment downgraded to Regular (user has saved tokens, avoid 1018)', array(
                    'order_id'      => $order->get_id(),
                    'user_id'       => $user_id,
                    'tokens_count'  => count( $existing_tokens ),
                ) );
                $use_bind_card = false;
            }
        }

        // 重要：當 SDK 啟用 bindCard 時，paySession 已包含用戶是否勾選儲存卡片的資訊
        // 後端使用 CardBindPayment + savePaymentInstrument=true，
        // API 會根據 paySession 中的用戶選擇來決定是否實際儲存卡片
        if ( 'saved' === $payment_instrument_mode && $user_id && $customer_id ) {
            $payment_behavior = 'QuickPayment';
        } elseif ( ! empty( $payment_instrument_id ) && $use_bind_card ) {
            // 使用已綁定的卡片（僅在啟用 bindCard 的 gateway 上有效）
            $payment_behavior = 'QuickPayment';
        } elseif ( $use_bind_card && 'saved' !== $payment_instrument_mode && 'new' !== $payment_instrument_mode ) {
            // SDK 啟用了綁卡功能，使用 CardBindPayment
            // paySession 已包含用戶是否勾選儲存的選擇
            $payment_behavior = 'CardBindPayment';
        } else {
            // 一般付款（SDK 未啟用綁卡，或使用者選新卡但未要求儲存）
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
                'value'    => \YSShoplinePayment::get_formatted_amount( $order->get_total(), $order->get_currency() ),
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
                        'value'    => \YSShoplinePayment::get_formatted_amount( $order->get_shipping_total(), $order->get_currency() ),
                        'currency' => $order->get_currency(),
                    ),
                ),
            ),
            'client'           => $this->build_client_info( $client_ip ),
        );

        // v3.5.10: 標記「新卡 + 要求儲存」路徑（非 QuickPayment、登入用戶 + bindCard）
        // 用途：redirect handler 判斷 SHOPLINE 是否真的有建 paymentInstrument，沒有就警告
        if ( 'CardBindPayment' === $payment_behavior && empty( $payment_instrument_id ) && $user_id ) {
            $order->update_meta_data( YSOrderMeta::BIND_CARD_ATTEMPTED, 'yes' );
            $order->save();
        }

        // QuickPayment 流程：使用已綁定的卡片
        if ( 'QuickPayment' === $payment_behavior ) {
            // 取得 customerId
            if ( $order->get_user_id() ) {
                $customer_id = $this->get_shopline_customer_id( $order->get_user_id() );
                if ( $customer_id ) {
                    $data['confirm']['paymentCustomerId'] = $customer_id;
                    if ( ! empty( $payment_instrument_id ) && 'saved' !== $payment_instrument_mode ) {
                        $data['confirm']['paymentInstrument'] = array(
                            'paymentInstrumentId' => $payment_instrument_id,
                        );
                    }
                } else {
                    // 沒有 customerId，無法使用 QuickPayment，降級為 Regular
                    YSLogger::warning( 'QuickPayment requested but no customerId found, falling back to Regular' );
                    $data['confirm']['paymentBehavior'] = 'Regular';
                }
            }
        }
        // CardBindPayment 流程：SDK 啟用綁卡功能
        // paySession 已包含用戶是否勾選儲存的選擇，API 會根據此決定是否實際儲存
        elseif ( 'CardBindPayment' === $payment_behavior && $use_bind_card ) {
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
        // When the SDK has customer-bound card UI but the request remains Regular, keep the
        // customer context so SHOPLINE can resolve the selected paySession correctly.
        if ( 'Regular' === $payment_behavior && $user_id && $customer_id
            && class_exists( 'WC_Payment_Tokens' )
            && ! empty( WC_Payment_Tokens::get_customer_tokens( $user_id, 'ys_shopline_credit' ) ) ) {
            $data['confirm']['paymentCustomerId'] = $customer_id;
        }

        YSLogger::debug( 'Payment data prepared', array(
            'order_id'         => $order->get_id(),
            'amount'           => $data['amount']['value'],
            'currency'         => $data['amount']['currency'],
            'payment_behavior' => $payment_behavior,
            'payment_method'   => $this->get_payment_method(),
            'use_bind_card'    => $use_bind_card ? 'yes' : 'no',
            'client_bind_card_enabled' => $client_bind_card_enabled ? 'yes' : 'no',
            'payment_instrument_mode'  => $payment_instrument_mode ?: 'none',
            'saved_card_last4'         => $saved_card_last4 ?: 'none',
            'resolved_instrument_id'   => $payment_instrument_id ? substr( $payment_instrument_id, -6 ) : 'none',
            'user_id'          => $user_id,
            'customer_id'      => $customer_id ?: 'none',
            'is_subscription'  => $is_subscription ? 'yes' : 'no',
            'pay_session_type' => gettype( $pay_session ),
            'pay_session_len'  => strlen( $pay_session ),
            'client_ip'        => $client_ip,
            'products_count'   => count( $products ),
        ) );

        // 詳細記錄完整資料結構（用於除錯 1999 錯誤）
        // 注意：billing_address / customer_info 經 redact_sensitive 遮罩 PII
        YSLogger::debug( 'Full payment data structure', array(
            'data_keys'           => array_keys( $data ),
            'confirm_keys'        => array_keys( $data['confirm'] ),
            'customer_keys'       => array_keys( $data['customer'] ),
            'billing_keys'        => array_keys( $data['billing'] ),
            'order_keys'          => array_keys( $data['order'] ),
            'client_keys'         => array_keys( $data['client'] ),
            'billing_address'     => \YangSheep\ShoplinePayment\Api\YSShoplineRequester::redact_sensitive( $data['billing']['address'] ),
            'customer_info'       => \YangSheep\ShoplinePayment\Api\YSShoplineRequester::redact_sensitive( $data['customer']['personalInfo'] ),
            'referenceOrderId'    => $data['referenceOrderId'],
        ) );

        return apply_filters( 'ys_shopline_payment_data', $data, $order, $this );
    }

    /**
     * Resolve a saved SHOPLINE payment instrument by the current user's WC token last4.
     *
     * @param int    $user_id User ID.
     * @param string $last4   Card last four digits.
     * @return string Payment instrument ID or empty string.
     */
    protected function resolve_payment_instrument_id_by_last4( $user_id, $last4 ) {
        if ( ! $user_id || strlen( (string) $last4 ) !== 4 || ! class_exists( 'WC_Payment_Tokens' ) ) {
            return '';
        }

        // v3.5.36: 唯一性依 distinct instrument ID 判斷，而非 WC token 筆數。
        $ids = $this->distinct_instrument_ids_for_last4( $user_id, $last4 );

        if ( 1 !== count( $ids ) ) {
            YSLogger::warning( 'Saved card last4 did not resolve to exactly one distinct instrument', array(
                'user_id'        => $user_id,
                'last4'          => $last4,
                'distinct_count' => count( $ids ),
            ) );
            return '';
        }

        return (string) $ids[0];
    }

    /**
     * v3.5.36: 取得某 last4 對應的「distinct SHOPLINE instrument ID」集合。
     *
     * 卡片唯一性必須以 distinct instrument ID（WC token 值＝SHOPLINE paymentInstrumentId）
     * 判斷，而非 WC token 筆數：同一張卡可能有多筆重複 WC token（都指向同一 instrument），
     * 那仍是唯一一張卡、可安全識別/自動選；只有 last4 對到「2 個以上不同 instrument」
     * 才是真撞號，必須停用自動選卡以免點錯卡。
     *
     * @param int    $user_id User ID.
     * @param string $last4   Card last four digits.
     * @return string[] Distinct instrument IDs（去重後）。
     */
    protected function distinct_instrument_ids_for_last4( $user_id, $last4 ) {
        $ids = array();
        if ( ! $user_id || strlen( (string) $last4 ) !== 4 || ! class_exists( 'WC_Payment_Tokens' ) ) {
            return $ids;
        }

        $tokens = WC_Payment_Tokens::get_customer_tokens( $user_id, YSOrderMeta::CREDIT_GATEWAY_ID );
        foreach ( $tokens as $token ) {
            if ( ! is_object( $token ) || ! method_exists( $token, 'get_last4' ) || ! method_exists( $token, 'get_token' ) ) {
                continue;
            }
            if ( (string) $token->get_last4() === (string) $last4 ) {
                $instrument = (string) $token->get_token();
                if ( '' !== $instrument ) {
                    $ids[ $instrument ] = true; // 以 instrument ID 為鍵去重
                }
            }
        }

        return array_keys( $ids );
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
     * 查詢前一筆交易狀態，決定是否允許重新付款（v3.5.35 改為 resolve_prior_trade 的轉接層）。
     *
     * @param WC_Order $order            訂單物件。
     * @param string   $existing_trade_id 已存在的 tradeOrderId（僅保留簽名相容，實際由 resolver 重讀 meta）。
     * @return array|null 回傳 process_payment 格式陣列或 null（允許繼續）。
     */
    protected function check_prior_trade_status( $order, $existing_trade_id ) {
        $resolution = $this->resolve_prior_trade( $order );

        if ( 'paid' === $resolution['action'] ) {
            return array(
                'result'   => 'success',
                'redirect' => isset( $resolution['redirect'] ) ? $resolution['redirect'] : $this->get_return_url( $order ),
                'remote_outcome' => 'accepted', // 前次交易已收款
            );
        }

        if ( 'allow' === $resolution['action'] ) {
            return null;
        }

        // blocked：真實原因進 WC notice（主結帳由 WC core 帶回前端；
        // order-pay 由 ajax_pay_for_order 收攏進 messages 回傳）
        $message = isset( $resolution['message'] ) && '' !== $resolution['message']
            ? $resolution['message']
            : __( '前次付款流程尚未完成，請稍候片刻後再試。若持續出現此訊息，請聯繫客服。', 'ys-shopline-via-woocommerce' );
        wc_add_notice( $message, 'error' );
        // blocked＝前次交易在途/狀態不明，未建立新交易；對消費端契約回 unknown（fail-closed：不還原）
        return array( 'result' => 'failure', 'remote_outcome' => 'unknown' );
    }

    /**
     * v3.5.35: 統一解決「前次交易」——order-pay 變更付款方式與主結帳同單重試共用。
     *
     * 分類（依 YSTradeStatus 共用分類器）：
     * - 無前次交易                        → allow
     * - PAID_RISK（已收款）               → paid（導感謝頁，RedirectHandler 補齊 meta）
     * - TERMINAL_SAFE（終態無風險）       → 清付款 meta → allow
     * - CUSTOMER_PENDING（顧客未完成）    → 主動取消 → 重查確認終態 → allow；
     *                                       取消未確認 → blocked（fail-closed）
     *   ※ 取消 API 回 200 仍可能 PROCESSING（官方文件），一律以重查結果為準。
     * - PROCESSING/AUTHORIZED/其他/查詢失敗 → blocked（fail-closed，避免重複扣款）
     *
     * 呼叫端約定：blocked 時「不得」改寫訂單付款方式或任何本地付款 meta。
     *
     * @param WC_Order $order 訂單物件。
     * @return array{action:string, redirect?:string, message?:string, status?:string}
     */
    public function resolve_prior_trade( $order ) {
        // v3.5.36 P0：付款結果不明（indeterminate）→ 於改寫付款方式/產生新 reference 前先收斂或封鎖，避免雙扣。
        $indeterminate = $this->resolve_indeterminate( $order );
        if ( 'blocked' === $indeterminate ) {
            return array(
                'action'  => 'blocked',
                'message' => __( '付款結果確認中，請勿重複付款；若已扣款我們會自動更新訂單，若未扣款請稍後再試。', 'ys-shopline-via-woocommerce' ),
                'status'  => 'INDETERMINATE',
            );
        }

        $existing_trade_id = (string) $order->get_meta( YSOrderMeta::TRADE_ORDER_ID );

        if ( '' === $existing_trade_id ) {
            return array( 'action' => 'allow', 'status' => '' );
        }

        if ( ! $this->api ) {
            return array(
                'action'  => 'blocked',
                'message' => __( '付款閘道未設定，請聯繫客服。', 'ys-shopline-via-woocommerce' ),
            );
        }

        YSLogger::info( 'Checking prior trade status before retry', array(
            'order_id'       => $order->get_id(),
            'trade_order_id' => $existing_trade_id,
        ) );

        $response = $this->api->get_payment_trade( $existing_trade_id );

        if ( is_wp_error( $response ) ) {
            YSLogger::error( 'Prior trade query failed', array(
                'order_id' => $order->get_id(),
                'error'    => $response->get_error_message(),
            ) );
            return array(
                'action'  => 'blocked',
                'message' => __( '無法確認前次付款狀態，請稍後再試或聯繫客服。', 'ys-shopline-via-woocommerce' ),
            );
        }

        $status = isset( $response['status'] ) ? strtoupper( (string) $response['status'] ) : '';

        YSLogger::info( 'Prior trade status result', array(
            'order_id'       => $order->get_id(),
            'trade_order_id' => $existing_trade_id,
            'status'         => $status,
        ) );

        // 前一筆已收款 → 導向感謝頁（RedirectHandler 會查 API 補齊所有 meta）
        if ( YSTradeStatus::is_paid( $status ) ) {
            return array(
                'action'   => 'paid',
                'redirect' => $this->get_return_url( $order ),
                'status'   => $status,
            );
        }

        // 前一筆已終態（失敗/過期/取消/退款）→ 清付款 meta，允許重新付款
        if ( YSTradeStatus::is_terminal_safe( $status ) ) {
            $this->clear_prior_payment_meta( $order );
            $order->add_order_note( sprintf(
                /* translators: %s: prior trade status */
                __( 'Shopline 金流：前次交易已終態（%s），允許重新建立交易。', 'ys-shopline-via-woocommerce' ),
                $status
            ) );
            YSLogger::info( 'Prior trade terminal, allowing retry', array(
                'order_id'           => $order->get_id(),
                'old_trade_order_id' => $existing_trade_id,
                'old_status'         => $status,
            ) );
            return array( 'action' => 'allow', 'status' => $status );
        }

        // 顧客端未完成（CREATED/CUSTOMER_ACTION：關閉 Apple Pay/3DS 視窗、未完成授權）
        //
        // 此狀態家族「沒有授權保留、沒有款項在途」。SHOPLINE 取消 API 僅支援已授權
        // 交易，對此家族實測一律拒絕（VA:"not support cancel"、LinePay:"can not cancel"）
        // — 若堅持取消確認才放行，等同把「關閉視窗後死鎖到交易過期（最長 6 小時）」
        // 原 bug 運回去。
        //
        // 流程（v3.5.35 Review P1-1：重查後嚴格分類，杜絕 fail-open）：
        //   best-effort 取消（VA 跳過，實證不支援）→ 一律重查取權威狀態，依重查結果分：
        //     PAID              → 導付款結果頁（競態：確認期間顧客完成收款）
        //     TERMINAL          → 取消生效，乾淨放行
        //     仍 CUSTOMER_PENDING → 無授權/無款項在途，棄用歸檔放行（供 webhook 溯源）
        //     PROCESSING/AUTHORIZED/未知/查詢失敗 → 款項可能在途 → blocked，不棄用、不改 meta
        if ( YSTradeStatus::is_customer_pending( $status ) ) {
            $remote_method = (string) ( $response['paymentMethod'] ?? ( $response['payment']['paymentMethod'] ?? '' ) );

            // best-effort 取消 — VirtualAccount 已實證不支援（"not support cancel"），跳過直接重查
            if ( 'VirtualAccount' !== $remote_method ) {
                $reference_id = (string) $order->get_meta( YSOrderMeta::REFERENCE_ORDER_ID );
                $cancel       = $this->api->cancel_payment_by_ids( $existing_trade_id, $reference_id );
                if ( is_wp_error( $cancel ) ) {
                    YSLogger::info( 'Prior pending trade cancel rejected, verifying by re-query', array(
                        'order_id'       => $order->get_id(),
                        'trade_order_id' => $existing_trade_id,
                        'error'          => $cancel->get_error_message(),
                    ) );
                }
            }

            // 一律重查取權威狀態（cancel 回 200 不可信；VA 沒取消也要確認未在收款）
            $verify = $this->api->get_payment_trade( $existing_trade_id );

            // 查詢失敗 → 無法確認 → fail-closed（不棄用、不改任何 meta）
            if ( is_wp_error( $verify ) ) {
                YSLogger::warning( 'Prior pending trade re-query failed, blocking', array(
                    'order_id'       => $order->get_id(),
                    'trade_order_id' => $existing_trade_id,
                    'error'          => $verify->get_error_message(),
                ) );
                $order->add_order_note( __( 'Shopline 金流：無法確認前次交易目前狀態（查詢失敗），為避免重複交易暫不放行。', 'ys-shopline-via-woocommerce' ) );
                return array(
                    'action'  => 'blocked',
                    'message' => __( '暫時無法確認前次付款狀態，請稍候約 1 分鐘後再試。若持續發生請聯繫客服。', 'ys-shopline-via-woocommerce' ),
                    'status'  => '',
                );
            }

            $vstatus = strtoupper( (string) ( $verify['status'] ?? '' ) );

            // 重查已收款（競態）→ 導付款結果頁
            if ( YSTradeStatus::is_paid( $vstatus ) ) {
                $order->add_order_note( sprintf(
                    /* translators: %s: verified trade status */
                    __( 'Shopline 金流：確認期間前次交易已完成收款（狀態 %s），導向付款結果頁。', 'ys-shopline-via-woocommerce' ),
                    $vstatus
                ) );
                return array(
                    'action'   => 'paid',
                    'redirect' => $this->get_return_url( $order ),
                    'status'   => $vstatus,
                );
            }

            // 重查已終態（取消生效）→ 乾淨放行
            if ( YSTradeStatus::is_terminal_safe( $vstatus ) ) {
                $this->clear_prior_payment_meta( $order );
                $order->add_order_note( sprintf(
                    /* translators: %s: verified trade status */
                    __( 'Shopline 金流：前次未完成交易已確認取消（狀態 %s），允許重新建立交易。', 'ys-shopline-via-woocommerce' ),
                    $vstatus
                ) );
                YSLogger::info( 'Prior pending trade cancelled, allowing retry', array(
                    'order_id'        => $order->get_id(),
                    'trade_order_id'  => $existing_trade_id,
                    'verified_status' => $vstatus,
                ) );
                return array( 'action' => 'allow', 'status' => $vstatus );
            }

            // 重查仍為顧客未完成（VA/LinePay 不支援取消；此態無授權、無款項在途）→ 棄用歸檔放行
            if ( YSTradeStatus::is_customer_pending( $vstatus ) ) {
                $abandoned   = (array) $order->get_meta( YSOrderMeta::ABANDONED_TRADE_IDS );
                $abandoned[] = $existing_trade_id;
                $order->update_meta_data( YSOrderMeta::ABANDONED_TRADE_IDS, array_values( array_unique( array_filter( $abandoned ) ) ) );
                $order->save();
                $this->clear_prior_payment_meta( $order );

                $order->add_order_note( sprintf(
                    /* translators: 1: trade order id, 2: prior trade status, 3: payment method */
                    __( '⚠️ Shopline 金流：棄用前次顧客未完成交易（%1$s，狀態 %2$s，方式 %3$s；金流端不支援取消此狀態，該交易無授權/未收款）。若顧客事後仍完成舊流程，付款通知將以重複收款警示標記，需人工確認退款。', 'ys-shopline-via-woocommerce' ),
                    $existing_trade_id,
                    $vstatus,
                    '' !== $remote_method ? $remote_method : 'UNKNOWN'
                ) );
                YSLogger::info( 'Prior pending trade abandoned for repayment', array(
                    'order_id'        => $order->get_id(),
                    'trade_order_id'  => $existing_trade_id,
                    'remote_method'   => $remote_method,
                    'verified_status' => $vstatus,
                ) );
                return array( 'action' => 'allow', 'status' => $vstatus );
            }

            // 其餘（PROCESSING/AUTHORIZED/未知）→ 款項可能在途 → fail-closed（不棄用、不改 meta）
            YSLogger::warning( 'Prior pending trade moved to in-flight state, blocking', array(
                'order_id'        => $order->get_id(),
                'trade_order_id'  => $existing_trade_id,
                'verified_status' => $vstatus,
            ) );
            $order->add_order_note( sprintf(
                /* translators: %s: verified trade status */
                __( 'Shopline 金流：前次交易已進入處理中（狀態 %s），為避免重複扣款暫不放行。', 'ys-shopline-via-woocommerce' ),
                '' !== $vstatus ? $vstatus : 'UNKNOWN'
            ) );
            return array(
                'action'  => 'blocked',
                'message' => sprintf(
                    /* translators: %s: verified trade status */
                    __( '前次付款已進入處理中（狀態 %s），為避免重複扣款暫時無法重新付款。請稍候片刻再試，若持續發生請聯繫客服。', 'ys-shopline-via-woocommerce' ),
                    '' !== $vstatus ? $vstatus : 'UNKNOWN'
                ),
                'status'  => $vstatus,
            );
        }

        // PROCESSING/AUTHORIZED/PENDING/未知 → 金流端處理中，取消有重複扣款風險 → fail-closed
        return array(
            'action'  => 'blocked',
            'message' => sprintf(
                /* translators: %s: prior trade status */
                __( '前次付款仍在金流端處理中（狀態 %s），為避免重複扣款暫時無法重新付款。請稍候片刻再試，若持續發生請聯繫客服。', 'ys-shopline-via-woocommerce' ),
                '' !== $status ? $status : 'UNKNOWN'
            ),
            'status'  => $status,
        );
    }

    /**
     * v3.5.35: 重用 ATM 虛擬帳號前，以遠端實況驗證前次交易確為 VirtualAccount
     * 且仍為顧客待付款狀態。
     *
     * 本地 payment method / VA meta 可能被失敗的付款方式變更嘗試污染
     *（歷史 P0：先寫入新 gateway 再判斷舊交易），不可單獨信任。
     * 驗證失敗一律回 false（fail-closed）→ 呼叫端改走 resolve_prior_trade 統一處理。
     *
     * @param WC_Order $order 訂單物件。
     * @return bool 是否可安全重用既有 VA。
     */
    public function verify_reusable_offline_trade( $order ) {
        if ( ! $this->api ) {
            return false;
        }

        $trade_id = (string) $order->get_meta( YSOrderMeta::TRADE_ORDER_ID );
        if ( '' === $trade_id ) {
            return false;
        }

        $response = $this->api->get_payment_trade( $trade_id );
        if ( is_wp_error( $response ) ) {
            YSLogger::warning( 'Offline reuse verification query failed', array(
                'order_id' => $order->get_id(),
                'error'    => $response->get_error_message(),
            ) );
            return false;
        }

        $status = isset( $response['status'] ) ? strtoupper( (string) $response['status'] ) : '';
        $method = (string) ( $response['paymentMethod'] ?? ( $response['payment']['paymentMethod'] ?? '' ) );

        $reusable = ( 'VirtualAccount' === $method ) && YSTradeStatus::is_customer_pending( $status );

        if ( ! $reusable ) {
            YSLogger::info( 'Offline reuse rejected by remote state', array(
                'order_id'       => $order->get_id(),
                'trade_order_id' => $trade_id,
                'remote_method'  => '' !== $method ? $method : 'UNKNOWN',
                'remote_status'  => '' !== $status ? $status : 'UNKNOWN',
            ) );
        }

        return $reusable;
    }

    /**
     * v3.5.35: 清除前次付款週期的訂單 meta（重新付款前）。
     *
     * 涵蓋交易指標與 ATM 離線付款展示資訊；「不」清除 REFERENCE_ORDER_ID 與
     * PAYMENT_ATTEMPT（_N 嘗試序號需要延續遞增，冪等鍵才不會重複）。
     *
     * @param WC_Order $order 訂單物件。
     * @return void
     */
    protected function clear_prior_payment_meta( $order ) {
        $order->delete_meta_data( YSOrderMeta::TRADE_ORDER_ID );
        $order->delete_meta_data( YSOrderMeta::SESSION_ID );
        $order->delete_meta_data( YSOrderMeta::PAYMENT_STATUS );
        $order->delete_meta_data( YSOrderMeta::PAYMENT_DETAIL );
        $order->delete_meta_data( YSOrderMeta::NEXT_ACTION );
        $order->delete_meta_data( YSOrderMeta::PAYMENT_ATTEMPT_DATA );
        $order->delete_meta_data( YSOrderMeta::VA_ACCOUNT );
        $order->delete_meta_data( YSOrderMeta::VA_BANK_CODE );
        $order->delete_meta_data( YSOrderMeta::VA_EXPIRE );
        $order->save();
    }

    /**
     * Store the exact non-sensitive request envelope before creating a trade.
     *
     * The raw paySession is intentionally excluded. This preserves filtered
     * amounts, including zero-value subscription bind charges, for later
     * redirect/webhook/query verification.
     *
     * @param WC_Order $order        Order object.
     * @param array    $payment_data Exact create-trade request.
     * @param array    $pay_session  Decoded paySession metadata.
     */
    protected function store_payment_attempt_data( $order, array $payment_data, array $pay_session ): void {
        $reference = trim( (string) ( $payment_data['referenceOrderId'] ?? '' ) );
        $method    = trim( (string) ( $payment_data['confirm']['paymentMethod'] ?? '' ) );
        $amount    = isset( $payment_data['amount']['value'] ) ? (int) $payment_data['amount']['value'] : -1;
        $currency  = strtoupper( trim( (string) ( $payment_data['amount']['currency'] ?? '' ) ) );
        if ( '' === $reference || '' === $method || $amount < 0 || '' === $currency ) {
            return;
        }

        $session_id = trim( (string) ( $pay_session['sessionId'] ?? '' ) );
        $order->update_meta_data(
            YSOrderMeta::PAYMENT_ATTEMPT_DATA,
            array(
                'reference'       => $reference,
                'session_id'      => $session_id,
                'gateway'         => $this->id,
                'shopline_method' => $method,
                'amount'          => $amount,
                'currency'        => $currency,
                'started_at'      => time(),
            )
        );
        if ( '' !== $session_id ) {
            $order->update_meta_data( YSOrderMeta::SESSION_ID, $session_id );
        }
        $order->update_meta_data( YSOrderMeta::PAYMENT_METHOD, $method );
        $order->save();
    }

    /**
     * v3.5.36 P0：標記付款結果不明（indeterminate）。
     *
     * 記錄「實際送給 SHOPLINE 的」referenceOrderId／金額／幣別／paymentMethod（＋sessionId／gateway），
     * 供 query_session／webhook 收斂時做金額幣別方式的完全相符核對；標記存在期間，
     * process_payment / resolve_prior_trade 於「產生新 reference 前」封鎖再建交易。
     *
     * v3.5.36 覆核四輪 P1：金額／方式一律取自 `$payment_data`（subclass／filter 可能改過，例如零元訂閱
     * CardBind 實送 amount=10100），**不可用 Woo 訂單總額與 gateway method 重算**，否則 marker 與實送不符 →
     * 收斂金額核對永遠失敗 → 訂單持續鎖定。$payment_data 缺欄位時才 fallback 回訂單重算（防呆）。
     *
     * @param WC_Order      $order        訂單。
     * @param string|array  $pay_session  paySession（取 sessionId）。
     * @param string        $reference    當時的 referenceOrderId（冪等鍵；envelope 缺時的 fallback）。
     * @param array         $payment_data 實際送出的請求 envelope（prepare_payment_data 產物，含 subclass 覆蓋）。
     * @return void
     */
    protected function mark_indeterminate( $order, $pay_session, $reference, $payment_data = array() ) {
        $session_id = '';
        $decoded    = is_string( $pay_session ) ? json_decode( $pay_session, true ) : ( is_array( $pay_session ) ? $pay_session : null );
        if ( is_array( $decoded ) && ! empty( $decoded['sessionId'] ) ) {
            $session_id = (string) $decoded['sessionId'];
        }

        // 以實送 envelope 為準（subclass／filter 可能改過 amount／method），缺欄位才 fallback。
        $pd        = is_array( $payment_data ) ? $payment_data : array();
        $env_ref   = isset( $pd['referenceOrderId'] ) ? (string) $pd['referenceOrderId'] : '';
        $env_amt   = isset( $pd['amount']['value'] ) ? (int) $pd['amount']['value'] : null;
        $env_cur   = isset( $pd['amount']['currency'] ) ? (string) $pd['amount']['currency'] : '';
        $env_meth  = isset( $pd['confirm']['paymentMethod'] ) ? (string) $pd['confirm']['paymentMethod'] : '';

        $reference = ( '' !== $env_ref ) ? $env_ref : (string) $reference;
        $amount    = ( null !== $env_amt ) ? $env_amt : (int) round( (float) $order->get_total() * 100 );
        $currency  = ( '' !== $env_cur ) ? $env_cur : $order->get_currency();
        $method    = ( '' !== $env_meth ) ? $env_meth : (string) $this->get_payment_method();

        $order->update_meta_data( YSOrderMeta::INDETERMINATE_REF, $reference );
        $order->update_meta_data( YSOrderMeta::INDETERMINATE_DATA, array(
            'reference'       => $reference,
            'session_id'      => $session_id,
            'gateway'         => $this->id,
            'shopline_method' => $method, // 實送的 SHOPLINE payment method，供 webhook/query 核對
            'amount'          => $amount,
            'currency'        => $currency,
            'ts'              => time(),
        ) );
    }

    /**
     * v3.5.36 P0：清除 indeterminate 標記（收斂後）。
     *
     * @param WC_Order $order 訂單。
     * @return void
     */
    protected function clear_indeterminate( $order ) {
        $order->delete_meta_data( YSOrderMeta::INDETERMINATE_REF );
        $order->delete_meta_data( YSOrderMeta::INDETERMINATE_DATA );
        $order->save();
    }

    /**
     * v3.5.36 P0：嘗試收斂 indeterminate 狀態。
     *
     * 回傳：
     *   'none'     — 無 indeterminate 標記（正常流程）
     *   'resolved' — 已收斂（webhook 已接管、或 query_session 正向接管取得 tradeOrderId）→ 放行
     *   'blocked'  — 仍不明 → 封鎖，禁止用新鍵再建交易
     *
     * @param WC_Order $order 訂單。
     * @return string
     */
    protected function resolve_indeterminate( $order ) {
        $ref = (string) $order->get_meta( YSOrderMeta::INDETERMINATE_REF );
        if ( '' === $ref ) {
            return 'none';
        }

        // 1) 訂單已真正付款（webhook exact convergence 會自行清 marker；此處僅作 is_paid 保底）→ 放行。
        //    v3.5.36 Review P1-3：**不**以「有任何 TRADE_ORDER_ID」泛化清 marker——舊失敗交易 meta 可能誤清。
        if ( $order->is_paid() ) {
            $this->clear_indeterminate( $order );
            return 'resolved';
        }

        // 2) query_session 正向接管（僅在確認「已收款交易」時 adopt；查不到不放行）。
        //    Review P0-2：paymentDetails 元素只有 tradeOrderId/status/paymentMethod，無 per-detail
        //    reference/amount；referenceId 與 amount 在 root。故：先核對 session root（referenceId＋
        //    金額＋幣別）確認是正確 session，再於 paymentDetails 中**只選狀態為已收款那筆**（不選 [0]、
        //    不採 pending/failed），避免 [FAILED old, SUCCEEDED new] 選到 old。
        $data       = (array) $order->get_meta( YSOrderMeta::INDETERMINATE_DATA );
        $session_id = isset( $data['session_id'] ) ? (string) $data['session_id'] : '';
        if ( '' === $session_id || ! $this->api ) {
            return 'blocked';
        }

        // 期望值（marker）必須完整——任一鍵缺失代表無法做「完全相符」核對 → fail-closed。
        //   v3.5.36 Review P0：不得「有值才比、缺值放行」；referenceId／金額／幣別為官方 sessionQuery
        //   root 必填，paymentMethod 亦為 detail 必填，故任一缺失即不接管。
        $exp_ref    = isset( $data['reference'] ) ? (string) $data['reference'] : $ref;
        $exp_amt    = isset( $data['amount'] ) ? (int) $data['amount'] : -1;
        $exp_cur    = isset( $data['currency'] ) ? (string) $data['currency'] : '';
        $exp_method = isset( $data['shopline_method'] ) ? (string) $data['shopline_method'] : '';
        if ( '' === $exp_ref || $exp_amt < 0 || '' === $exp_cur || '' === $exp_method ) {
            YSLogger::warning( 'Indeterminate query_session: marker incomplete, not adopting', array( 'order_id' => $order->get_id() ) );
            return 'blocked';
        }

        $dto = $this->api->query_session( $session_id );
        if ( is_wp_error( $dto ) ) {
            return 'blocked';
        }
        $raw = ( is_object( $dto ) && isset( $dto->raw_data ) && is_array( $dto->raw_data ) ) ? $dto->raw_data : array();

        // session root 必須「完整且完全相符」（缺欄位或不符一律 fail-closed，不再有值才比）。
        $sess_ref = (string) ( $raw['referenceId'] ?? '' );
        $sess_amt = isset( $raw['amount']['value'] ) ? (int) $raw['amount']['value'] : -1;
        $sess_cur = (string) ( $raw['amount']['currency'] ?? '' );
        if ( '' === $sess_ref || $sess_ref !== $exp_ref
            || $sess_amt < 0 || $sess_amt !== $exp_amt
            || '' === $sess_cur || $sess_cur !== $exp_cur ) {
            YSLogger::warning( 'Indeterminate query_session: session root incomplete/mismatch, not adopting', array(
                'order_id' => $order->get_id(),
            ) );
            return 'blocked';
        }

        // 於 paymentDetails 挑「唯一一筆」代表交易（共用嚴格 selector，杜絕 [FAILED old, SUCCEEDED new] 誤選）。
        // 接管入帳為高風險：額外要求該筆須為「已收款」且 paymentMethod 與期望 SHOPLINE method 相符；
        // 缺 method（官方必填）、或存在多筆已收款（雙付款異常）→ selector 回空／驗證失敗，一律 fail-closed，
        // 交由 webhook exact convergence 或人工核對。
        $details = ( isset( $raw['paymentDetails'] ) && is_array( $raw['paymentDetails'] ) ) ? $raw['paymentDetails'] : array();
        $trade   = YSTradeStatus::select_representative_trade_id( $details );
        if ( '' === $trade ) {
            return 'blocked';
        }
        $paid_trade  = '';
        $paid_status = '';
        foreach ( $details as $pd ) {
            if ( ! is_array( $pd ) || (string) ( $pd['tradeOrderId'] ?? '' ) !== $trade ) {
                continue;
            }
            $st     = (string) ( $pd['status'] ?? '' );
            $method = (string) ( $pd['paymentMethod'] ?? '' );
            if ( YSTradeStatus::is_paid( $st ) && '' !== $method && $method === $exp_method ) {
                $paid_trade  = $trade;
                $paid_status = strtoupper( $st );
            }
            break;
        }
        if ( '' === $paid_trade ) {
            // 選出的代表交易非「已收款且 method 相符」→ 不放行（等 webhook 收斂 paid/failed/expired）。
            return 'blocked';
        }

        $order->update_meta_data( YSOrderMeta::TRADE_ORDER_ID, $paid_trade );
        $order->update_meta_data( YSOrderMeta::PAYMENT_STATUS, $paid_status );
        $order->delete_meta_data( YSOrderMeta::INDETERMINATE_REF );
        $order->delete_meta_data( YSOrderMeta::INDETERMINATE_DATA );
        $order->add_order_note( sprintf(
            /* translators: %s: adopted trade order id */
            __( 'Shopline 金流：Session 查詢確認前次不明付款已完成收款（交易 %s，經 referenceId／金額／幣別／付款方式核對且唯一），已接管入帳。', 'ys-shopline-via-woocommerce' ),
            $paid_trade
        ) );
        $order->save();

        // Query-session positive convergence must complete the order itself. Browser
        // checkout can fall through to another status query, but scheduled renewals
        // have no redirect handler and would otherwise remain unpaid or retry later.
        if ( ! $order->is_paid() ) {
            $order->payment_complete( $paid_trade );

            $custom_paid_status = get_option( 'ys_shopline_order_status_paid', '' );
            if ( $custom_paid_status && $custom_paid_status !== $order->get_status() ) {
                $order->update_status( $custom_paid_status, __( '依商家設定更新訂單狀態。', 'ys-shopline-via-woocommerce' ) );
            }
        }

        return 'resolved';
    }

    /**
     * v3.5.36 P0：indeterminate 封鎖時回給前端的一致回應。
     *
     * @return array
     */
    protected function indeterminate_blocked_response() {
        $msg = __( '付款結果確認中，請勿重複付款；若已扣款我們會自動更新訂單，若未扣款請稍後再試。', 'ys-shopline-via-woocommerce' );
        wc_add_notice( $msg, 'notice' );
        return array(
            'result'         => 'failure',
            'remote_outcome' => 'unknown',
            'messages'       => $msg,
        );
    }

    /**
     * Generate unique reference order ID for Shopline.
     *
     * @param WC_Order $order Order object.
     * @return string
     */
    protected function generate_reference_order_id( $order ) {
        $order_id = $order->get_id();

        // 取得目前的付款嘗試次數
        $attempt = (int) $order->get_meta( YSOrderMeta::PAYMENT_ATTEMPT );
        $attempt++;

        // 產生唯一 ID：訂單ID_嘗試次數（例如：1022_1, 1022_2）
        $reference_id = sprintf( '%d_%d', $order_id, $attempt );

        $order->update_meta_data( YSOrderMeta::PAYMENT_ATTEMPT, $attempt );
        $order->update_meta_data( YSOrderMeta::REFERENCE_ORDER_ID, $reference_id );
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
    /**
     * Build billing address from user meta (for add_payment_method without order).
     *
     * 用戶 billing meta → 商店地址 fallback
     *
     * @param int $user_id
     * @return array
     */
    protected function build_user_billing_address( $user_id ) {
        $address_1 = (string) get_user_meta( $user_id, 'billing_address_1', true );
        $address_2 = (string) get_user_meta( $user_id, 'billing_address_2', true );
        $street    = trim( $address_1 . ' ' . $address_2 );
        $city      = (string) get_user_meta( $user_id, 'billing_city', true );
        $district  = (string) get_user_meta( $user_id, 'billing_state', true );
        $postcode  = (string) get_user_meta( $user_id, 'billing_postcode', true );
        $country   = (string) get_user_meta( $user_id, 'billing_country', true );

        // Fallback 到商店地址設定
        if ( '' === $street ) {
            $street = (string) get_option( 'ys_shopline_store_address', '' );
        }
        if ( '' === $city ) {
            $city = (string) get_option( 'ys_shopline_store_city', '' );
        }
        if ( '' === $postcode ) {
            $postcode = (string) get_option( 'ys_shopline_store_postcode', '' );
        }
        if ( '' === $country ) {
            $country = (string) get_option( 'ys_shopline_store_country', 'TW' );
        }

        // 最終安全預設值（避免空欄位）
        if ( '' === $street ) {
            $street = 'N/A';
        }
        if ( '' === $city ) {
            $city = 'Taipei';
        }
        if ( '' === $postcode ) {
            $postcode = '100';
        }

        return array(
            'countryCode' => $country,
            'city'        => $city,
            'district'    => $district,
            'street'      => $street,
            'postcode'    => $postcode,
        );
    }

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

        // Fallback 到商店地址（API 設定中的備用地址）
        // 順序：運送地址 → 帳單地址 → 商店地址
        if ( empty( $street ) ) {
            $store_address = get_option( 'ys_shopline_store_address', '' );
            if ( ! empty( $store_address ) ) {
                $street = $store_address;
                YSLogger::debug( 'Using store address as fallback', array(
                    'order_id' => $order->get_id(),
                    'address'  => $store_address,
                ) );
            }
        }

        if ( empty( $city ) ) {
            $store_city = get_option( 'ys_shopline_store_city', '' );
            if ( ! empty( $store_city ) ) {
                $city = $store_city;
            }
        }

        if ( empty( $postcode ) ) {
            $store_postcode = get_option( 'ys_shopline_store_postcode', '' );
            if ( ! empty( $store_postcode ) ) {
                $postcode = $store_postcode;
            }
        }

        if ( empty( $country_code ) ) {
            $store_country = get_option( 'ys_shopline_store_country', 'TW' );
            if ( ! empty( $store_country ) ) {
                $country_code = $store_country;
            }
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
                    'value'    => \YSShoplinePayment::get_formatted_amount(
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
                        'value'    => \YSShoplinePayment::get_formatted_amount( $fee_total, $currency ),
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
        $order_subtotal = \YSShoplinePayment::get_formatted_amount(
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

            YSLogger::debug( 'Products total adjustment', array(
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
            $ip = \WC_Geolocation::get_ip_address();
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
        $order->update_meta_data( YSOrderMeta::NEXT_ACTION, $response['nextAction'] );

        // v3.5.1: 明確記錄 nextAction 類型（3DS / redirect 等）
        $action_type = isset( $response['nextAction']['type'] ) ? (string) $response['nextAction']['type'] : 'unknown';
        $trade_order_id = isset( $response['tradeOrderId'] ) ? $response['tradeOrderId'] : '';
        $order->add_order_note( sprintf(
            /* translators: 1: action type, 2: trade order id */
            __( 'Shopline 金流：SHOPLINE 已受理交易（tradeOrderId=%2$s），下一步=%1$s，等待使用者完成 3DS/授權驗證', 'ys-shopline-via-woocommerce' ),
            $action_type,
            $trade_order_id
        ) );

        $order->update_status( 'pending', __( 'Awaiting Shopline payment completion.', 'ys-shopline-via-woocommerce' ) );
        $order->save();

        // Reduce stock
        wc_maybe_reduce_stock_levels( $order->get_id() );

        // Empty the cart
        WC()->cart->empty_cart();

        // 返回 nextAction 給前端，讓前端用同一個 SDK 實例處理
        // SDK 文件指出：payment.pay(nextAction) 必須用同一個 payment 實例
        // 否則 SDK 不知道原始卡片資訊
        return array(
            'result'     => 'success',
            'remote_outcome' => 'accepted', // 遠端已受理交易（等待 3DS/Confirm）
            'nextAction' => $response['nextAction'],
            'returnUrl'  => $this->get_return_url( $order ),
            // v3.5.5: 3DS/Confirm 失敗時前端導向 pay-for-order 頁
            // （WC 訂單已建立 + tradeOrderId 已寫入 meta，不能讓使用者停在結帳頁誤以為沒下單）
            'failureUrl' => $order->get_checkout_payment_url(),
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

        $trade_order_id = $order->get_meta( YSOrderMeta::TRADE_ORDER_ID );

        if ( empty( $trade_order_id ) ) {
            return new WP_Error( 'no_trade_id', __( 'Trade order ID not found.', 'ys-shopline-via-woocommerce' ) );
        }

        if ( ! $this->api ) {
            return new WP_Error( 'api_error', __( 'API not configured.', 'ys-shopline-via-woocommerce' ) );
        }

        $refund_reference_order_id = $this->generate_refund_reference_order_id( $order );

        $refund_data = array(
            'tradeOrderId'     => $trade_order_id,
            'referenceOrderId' => $refund_reference_order_id,
            'amount'           => array(
                'value'    => \YSShoplinePayment::get_formatted_amount( $amount, $order->get_currency() ),
                'currency' => $order->get_currency(),
            ),
        );

        if ( ! empty( $reason ) ) {
            $refund_data['reason'] = $reason;
        }

        $response = $this->api->create_refund( $refund_data );

        if ( is_wp_error( $response ) ) {
            $this->add_refund_failure_note( $order, $amount, $refund_reference_order_id, $response );
            return $response;
        }

        // Store refund ID
        if ( isset( $response['refundOrderId'] ) ) {
            $order->add_order_note(
                sprintf(
                    /* translators: 1: refund amount, 2: refund order ID, 3: referenceOrderId */
                    __( 'Refunded %1$s via Shopline. Refund ID: %2$s. Refund referenceOrderId: %3$s', 'ys-shopline-via-woocommerce' ),
                    wc_price( $amount ),
                    $response['refundOrderId'],
                    $refund_reference_order_id
                )
            );
        }

        return true;
    }

    /**
     * Generate a refund-specific referenceOrderId.
     *
     * SHOPLINE treats refund referenceOrderId values as unique even after a
     * failed refund request. Do not reuse the payment referenceOrderId here.
     *
     * @param \WC_Order $order Order object.
     * @return string
     */
    protected function generate_refund_reference_order_id( $order ) {
        $attempt = absint( $order->get_meta( YSOrderMeta::REFUND_ATTEMPT ) ) + 1;

        $order->update_meta_data( YSOrderMeta::REFUND_ATTEMPT, $attempt );
        $order->save();

        return sprintf( '%d_refund_%d', $order->get_id(), $attempt );
    }

    /**
     * Add an actionable refund failure note for admins.
     *
     * @param \WC_Order $order              Order object.
     * @param float     $amount             Refund amount.
     * @param string    $reference_order_id Refund referenceOrderId.
     * @param \WP_Error $error              API error.
     * @return void
     */
    protected function add_refund_failure_note( $order, $amount, $reference_order_id, $error ) {
        $code    = (string) $error->get_error_code();
        $message = rtrim( $error->get_error_message(), " \t\n\r\0\x0B.。" );
        $hint    = $this->get_refund_failure_hint( $code );

        $order->add_order_note(
            sprintf(
                /* translators: 1: refund amount, 2: referenceOrderId, 3: error code, 4: error message, 5: hint */
                __( 'Shopline refund request failed（SHOPLINE 退款送出失敗）。退款金額：%1$s。Refund referenceOrderId：%2$s。SHOPLINE 錯誤：%3$s - %4$s。%5$s', 'ys-shopline-via-woocommerce' ),
                wc_price( $amount ),
                $reference_order_id,
                $code,
                $message,
                $hint
            )
        );
    }

    /**
     * Map known SHOPLINE refund errors to actionable admin hints.
     *
     * @param string $code SHOPLINE error code.
     * @return string
     */
    protected function get_refund_failure_hint( $code ) {
        if ( '1022' === $code ) {
            return __( 'merchant account balance insufficient：SHOPLINE 商戶帳戶餘額不足。請先補足 SHOPLINE 商戶餘額後再重新退款；下一次退款會使用新的 refund referenceOrderId，不會重用本次失敗的編號。', 'ys-shopline-via-woocommerce' );
        }

        if ( '1001' === $code ) {
            return __( 'SHOPLINE 回覆退款 referenceOrderId 重複。請重新送出退款，外掛會產生新的 refund referenceOrderId。', 'ys-shopline-via-woocommerce' );
        }

        return __( '請查詢 SHOPLINE 交易紀錄，排除上游錯誤後再重新退款；外掛會在下一次送出新的 refund referenceOrderId。', 'ys-shopline-via-woocommerce' );
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
        $next_action = $order->get_meta( YSOrderMeta::NEXT_ACTION );
        if ( $next_action ) {
            $order->delete_meta_data( YSOrderMeta::NEXT_ACTION );
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

        YSLogger::debug( 'Checking order status via API', array(
            'order_id'       => $order->get_id(),
            'trade_order_id' => $trade_order_id,
        ) );

        // 查詢訂單狀態
        $response = $this->api->get_payment_trade( $trade_order_id );

        if ( is_wp_error( $response ) ) {
            YSLogger::error( 'Failed to query trade status: ' . $response->get_error_message() );
            return;
        }

        YSLogger::debug( 'Trade status query response', array(
            'status' => isset( $response['status'] ) ? $response['status'] : 'unknown',
        ) );

        // 根據狀態更新訂單
        $status = isset( $response['status'] ) ? $response['status'] : '';

        if ( 'SUCCEEDED' === $status || 'SUCCESS' === $status || 'CAPTURED' === $status ) {
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
                $order->update_meta_data( YSOrderMeta::PAYMENT_STATUS, $status );
                $order->save();

                YSLogger::info( 'Order marked as paid via status check: ' . $order->get_id() );
            }
        } elseif ( 'FAILED' === $status ) {
            // 付款失敗
            if ( ! $order->has_status( 'failed' ) ) {
                $error_msg = isset( $response['paymentMsg']['msg'] ) ? $response['paymentMsg']['msg'] : __( 'Payment failed', 'ys-shopline-via-woocommerce' );
                $order->update_status( 'failed', $error_msg );
                $order->update_meta_data( YSOrderMeta::PAYMENT_STATUS, 'FAILED' );
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
            YSLogger::log( $message, $level );
        }
    }

    /**
     * Add payment method via My Account (legacy WC form POST fallback).
     *
     * WC 原生 form POST 呼叫此方法。正常流程應由前端 AJAX 直接呼叫
     * `ajax_add_payment_method()`，讓原 SDK 實例延續 `pay(nextAction)` 處理 3DS。
     *
     * 若 JS 失效，WC form POST 進入此方法 → 回傳錯誤訊息，避免走到已廢棄的獨立 3DS 頁。
     *
     * @return array
     */
    public function add_payment_method() {
        YSLogger::warning( 'Legacy add_payment_method() form POST invoked — 應走 AJAX 流程。JS 可能失效。' );
        wc_add_notice(
            __( '請啟用 JavaScript 以新增付款方式；若問題持續請聯絡客服。', 'ys-shopline-via-woocommerce' ),
            'error'
        );
        return array( 'result' => 'failure' );
    }

    /**
     * AJAX handler: add payment method.
     *
     * 由前端 JS AJAX 呼叫。保持原 SDK 實例活著，回傳 nextAction 供前端
     * 直接呼叫 `paymentInstance.pay(nextAction)` 完成 3DS（SDK 自動跳 returnUrl）。
     *
     * 流程：
     *   1. 前端 SDK.createPayment() 取得 paySession
     *   2. POST 到本 endpoint（action=ys_shopline_add_payment_method）
     *   3. 本方法呼叫 Shopline `/trade/payment/create`（CardBind）
     *   4. 回 JSON { nextAction, returnUrl }
     *   5. 前端 `paymentInstance.pay(nextAction)` 完成 3DS
     *   6. SDK 跳至 returnUrl → `YSAddPaymentMethodHandler::handle_add_method_redirect()` 建立 Token
     *
     * @return void (wp_send_json_*)
     */
    public function ajax_add_payment_method() {
        check_ajax_referer( 'ys_shopline_nonce', 'nonce' );

        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            wp_send_json_error( array( 'message' => __( '請先登入後再新增付款方式。', 'ys-shopline-via-woocommerce' ) ) );
            return;
        }

        // 閘道必須啟用，防止被關閉時仍可被濫用觸發 SHOPLINE API
        if ( 'yes' !== $this->enabled ) {
            wp_send_json_error( array( 'message' => __( '此付款方式目前未啟用。', 'ys-shopline-via-woocommerce' ) ) );
            return;
        }

        // paySession 為 JSON 字串（不能用 sanitize_text_field 會破壞結構），由下游 json_decode 驗證
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $pay_session_raw = isset( $_POST['pay_session'] ) ? wp_unslash( $_POST['pay_session'] ) : '';

        $result = $this->do_add_payment_method_request( $pay_session_raw, $user_id );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
            return;
        }

        wp_send_json_success( $result );
    }

    /**
     * Core: 呼叫 Shopline 建立 CardBind 綁卡交易。
     *
     * 共用於 AJAX handler（正常流程）與 WC form fallback（已停用）。
     *
     * @param string $pay_session_raw paySession JSON 字串（來自前端 SDK.createPayment）
     * @param int    $user_id         WordPress user ID
     * @return array|\WP_Error { nextAction, returnUrl, reference_order_id, trade_order_id, customer_id } or WP_Error
     */
    protected function do_add_payment_method_request( $pay_session_raw, $user_id ) {
        if ( empty( $pay_session_raw ) ) {
            return new \WP_Error( 'missing_pay_session', __( '付款資訊遺失，請重新輸入卡片資訊。', 'ys-shopline-via-woocommerce' ) );
        }

        $decoded = json_decode( $pay_session_raw, true );
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            YSLogger::error( 'Invalid paySession JSON in add_payment_method', array(
                'error' => json_last_error_msg(),
            ) );
            return new \WP_Error( 'invalid_pay_session', __( '付款資訊格式錯誤，請重新輸入。', 'ys-shopline-via-woocommerce' ) );
        }

        $customer_id = $this->get_shopline_customer_id( $user_id );
        if ( ! $customer_id ) {
            return new \WP_Error( 'no_customer_id', __( '無法建立客戶資訊，請稍後重試。', 'ys-shopline-via-woocommerce' ) );
        }

        if ( ! $this->api ) {
            return new \WP_Error( 'no_api', __( '付款閘道尚未設定。', 'ys-shopline-via-woocommerce' ) );
        }

        // reference_order_id 使用亂數 hex 避免高併發碰撞（原本 `wp_rand(10,99)` 只 90 種）
        $reference_order_id = 'ADD' . gmdate( 'YmdHis' ) . bin2hex( random_bytes( 4 ) );

        $return_url = add_query_arg(
            array(
                'ys_shopline_add_method' => '1',
                'reference_id'           => $reference_order_id,
            ),
            wc_get_account_endpoint_url( 'payment-methods' )
        );

        $user      = get_userdata( $user_id );
        $raw_phone = get_user_meta( $user_id, 'billing_phone', true );
        $country   = get_user_meta( $user_id, 'billing_country', true ) ?: 'TW';
        $phone     = $this->format_phone_number( $raw_phone, $country );

        $first_name = get_user_meta( $user_id, 'billing_first_name', true );
        $last_name  = get_user_meta( $user_id, 'billing_last_name', true );
        $display    = $user->display_name ?: $user->user_login;
        if ( empty( $first_name ) ) {
            $first_name = $display;
        }
        if ( empty( $last_name ) ) {
            $last_name = $display;
        }

        $personal_info = array(
            'firstName' => $first_name,
            'lastName'  => $last_name,
            'email'     => $user->user_email,
            'phone'     => $phone,
        );

        $billing_address = $this->build_user_billing_address( $user_id );

        // 準備 API 請求資料（純綁卡 CardBind）
        // 對齊官方 /guide/quick/ 4.1 範例：amount.value=10000（TWD $100），銀行只授權不請款
        $data = array(
            'paySession'       => $pay_session_raw,
            'referenceOrderId' => $reference_order_id,
            'returnUrl'        => $return_url,
            'acquirerType'     => 'SDK',
            'language'         => $this->get_shopline_language(),
            'amount'           => array(
                'value'    => 10100,
                'currency' => 'TWD',
            ),
            'confirm'          => array(
                'paymentMethod'     => 'CreditCard',
                'paymentBehavior'   => 'CardBind',
                'paymentCustomerId' => $customer_id,
                'paymentInstrument' => array(
                    'savePaymentInstrument' => true,
                ),
            ),
            'customer'         => array(
                'referenceCustomerId' => (string) $user_id,
                'type'                => '0',
                'personalInfo'        => $personal_info,
            ),
            'billing'          => array(
                'description'  => 'Card binding for user #' . $user_id,
                'personalInfo' => $personal_info,
                'address'      => $billing_address,
            ),
            'order'            => array(
                'products' => array(
                    array(
                        'id'       => 'cardbind-' . $user_id,
                        'name'     => 'Card Binding Verification',
                        'quantity' => 1,
                        'amount'   => array(
                            'value'    => 10100,
                            'currency' => 'TWD',
                        ),
                    ),
                ),
                'shipping' => array(
                    'shippingMethod' => 'Standard',
                    'carrier'        => 'Default',
                    'personalInfo'   => $personal_info,
                    'address'        => $billing_address,
                    'amount'         => array(
                        'value'    => 0,
                        'currency' => 'TWD',
                    ),
                ),
            ),
            'client'           => $this->build_client_info( $this->get_client_ip() ),
        );

        YSLogger::debug( 'Add payment method request (AJAX)', array(
            'user_id'            => $user_id,
            'customer_id'        => $customer_id,
            'reference_order_id' => $reference_order_id,
        ) );

        // 綁卡 API 狀態統一透過 YSLogger 記錄（debug.log 類型），不再走獨立 BindCardLogger
        $response = $this->api->create_payment_trade( $data );

        if ( is_wp_error( $response ) ) {
            // 回傳友善錯誤訊息給前端（與結帳失敗一致）
            $raw_error    = $response->get_error_message();
            $friendly_msg = \YangSheep\ShoplinePayment\Handlers\YSRedirectHandler::humanize_error_message( $raw_error );
            YSLogger::error( 'BindCard API failed', array(
                'flow'        => 'ajax_add_payment_method',
                'error_code'  => $response->get_error_code(),
                'raw_error'   => $raw_error,
                'friendly'    => $friendly_msg,
            ) );
            return new \WP_Error( $response->get_error_code(), $friendly_msg );
        }

        $trade_order_id = isset( $response['tradeOrderId'] ) ? $response['tradeOrderId'] : '';
        YSLogger::debug( 'BindCard API response', array(
            'flow'           => 'ajax_add_payment_method',
            'trade_order_id' => $trade_order_id,
            'status'         => isset( $response['status'] ) ? $response['status'] : '',
            'has_nextAction' => isset( $response['nextAction'] ) ? 'yes' : 'no',
        ) );

        // 儲存 pending bind 資訊（供 handle_add_method_redirect 使用）
        update_user_meta( $user_id, YSOrderMeta::PENDING_BIND, array(
            'reference_order_id' => $reference_order_id,
            'trade_order_id'     => $trade_order_id,
            'customer_id'        => $customer_id,
            'created_at'         => time(),
        ) );

        return array(
            'nextAction'         => isset( $response['nextAction'] ) ? $response['nextAction'] : null,
            'returnUrl'          => $return_url,
            'reference_order_id' => $reference_order_id,
            'trade_order_id'     => $trade_order_id,
            'customer_id'        => $customer_id,
        );
    }

}
