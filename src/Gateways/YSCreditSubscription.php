<?php
/**
 * Credit Card Subscription Gateway for YS Shopline Payment.
 *
 * @package YangSheep\ShoplinePayment\Gateways
 */

namespace YangSheep\ShoplinePayment\Gateways;

defined( 'ABSPATH' ) || exit;

use YangSheep\ShoplinePayment\Utils\YSLogger;
use YangSheep\ShoplinePayment\Utils\YSOrderMeta;

/**
 * YSCreditSubscription Class.
 *
 * Credit card payment gateway with WooCommerce Subscriptions support.
 * - 首次付款：透過 SDK CardBindPayment 綁卡 + 付款
 * - 續約扣款：透過 Shopline Recurring API（伺服器對伺服器）
 */
class YSCreditSubscription extends YSGatewayBase {

    /**
     * Constructor.
     */
    public function __construct() {
        $this->id                 = 'ys_shopline_credit_subscription';
        $this->icon               = '';
        $this->has_fields         = true;
        $this->method_title       = __( 'SHOPLINE 信用卡定期定額', 'ys-shopline-via-woocommerce' );
        $this->method_description = __( '透過 SHOPLINE Payment 信用卡進行定期定額付款（WooCommerce Subscriptions）', 'ys-shopline-via-woocommerce' );

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

        parent::__construct();

        // Subscription hooks
        add_action( 'woocommerce_scheduled_subscription_payment_' . $this->id, array( $this, 'process_subscription_payment' ), 10, 2 );
        add_action( 'woocommerce_subscription_failing_payment_method_updated_' . $this->id, array( $this, 'update_failing_payment_method' ), 10, 2 );

        // 宣告 subscription 需要的 meta 欄位
        add_filter( 'woocommerce_subscription_payment_meta', array( $this, 'subscription_payment_meta' ), 10, 2 );
    }

    /**
     * Get payment method for SDK.
     *
     * @return string
     */
    public function get_payment_method() {
        return 'CreditCard';
    }

    /**
     * Check if gateway is available.
     *
     * 新增付款方式頁面：訂閱閘道不適用（綁卡由 ys_shopline_credit 統一處理）
     *
     * @return bool
     */
    public function is_available() {
        if ( function_exists( 'is_add_payment_method_page' ) && is_add_payment_method_page() ) {
            return false;
        }

        return parent::is_available();
    }

    /**
     * Get SDK configuration.
     *
     * @return array
     */
    public function get_sdk_config() {
        $config = parent::get_sdk_config();

        // 訂閱 SDK config（v3.3.3 回正邏輯）：
        // - 保留 parent 提供的 customerToken → SDK 自己顯示「已綁卡 tab + 新卡 tab」
        // - bindCard.enable 跟隨是否有 customerToken（有才能綁新卡到會員）
        $has_customer_token = isset( $config['customerToken'] ) && ! empty( $config['customerToken'] );

        $config['forceSaveCard']     = true;
        $config['paymentInstrument'] = array(
            'bindCard' => array(
                'enable'   => $has_customer_token,
                'protocol' => array(
                    'switchVisible'       => false,
                    'defaultSwitchStatus' => true,
                    'mustAccept'          => true,
                ),
            ),
        );

        // 偵測 $0 訂閱（試用）：SDK 與 API 都拒絕 amount=0
        // 實際 API 會用 CardBind + amount=10000（對齊官方範例，銀行只授權不請款）
        if ( isset( $config['amount'] ) && (int) $config['amount'] <= 0 ) {
            $config['bindOnlyMode'] = true;
        }

        return $config;
    }

    /**
     * Prepare payment data.
     *
     * - 一般訂閱（首期金額 > 0）：CardBindPayment（綁卡並付款）
     * - 試用訂閱（首期金額 = 0）：CardBind（純綁卡，對齊官方 amount=10000 範例）
     *
     * @param \WC_Order $order       Order object.
     * @param string    $pay_session Pay session from SDK.
     * @return array
     */
    protected function prepare_payment_data( $order, $pay_session ) {
        $data = parent::prepare_payment_data( $order, $pay_session );

        $order_total = (float) $order->get_total();

        if ( $order_total <= 0 ) {
            // 零元試用訂閱：純綁卡
            // SHOPLINE API 拒絕 amount=0，對齊官方 CardBind 範例傳 amount.value=10000（TWD $100）
            // CardBind 為 SHOPLINE「非付款場景」paymentBehavior，銀行進行卡片授權驗證但不實際請款
            // 參考：https://docs.shoplinepayments.com/guide/quick/ 章節 4.1
            $data['confirm']['paymentBehavior'] = 'CardBind';
            $data['amount']['value']             = 10000;

            // 同步修正 order.products 的 amount（避免商品總額與 amount 不符）
            if ( isset( $data['order']['products'][0]['amount']['value'] ) ) {
                $data['order']['products'][0]['amount']['value'] = 10000;
            }

            YSLogger::info( 'Zero-amount subscription: using CardBind paymentBehavior with placeholder amount 10000', array(
                'order_id' => $order->get_id(),
            ) );
        } else {
            // 一般訂閱：綁卡並付款
            $data['confirm']['paymentBehavior'] = 'CardBindPayment';
        }

        $data['confirm']['paymentInstrument']['savePaymentInstrument'] = true;

        return $data;
    }

    /**
     * Process payment.
     *
     * 所有訂閱付款（包含零元試用）都走 SDK CardBindPayment 以確保綁卡。
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

        // 統一走 parent（SDK CardBindPayment），prepare_payment_data() 會強制綁卡
        $result = parent::process_payment( $order_id );

        // 首次付款成功後，儲存 subscription meta
        if ( 'success' === ( $result['result'] ?? '' ) ) {
            $this->save_subscription_meta_from_order( $order );
        }

        return $result;
    }

    /**
     * Process subscription renewal payment (Recurring).
     *
     * 流程控制：驗證 → 建構資料 → 呼叫 API → 處理回應。
     *
     * @param float     $amount Renewal amount.
     * @param \WC_Order $order  Renewal order.
     */
    public function process_subscription_payment( $amount, $order ) {
        $this->log( 'Processing subscription payment for order #' . $order->get_id() );

        // 1. 前置驗證
        $user_id = $order->get_user_id();
        if ( ! $user_id ) {
            $this->log( 'No user ID found for order #' . $order->get_id(), 'error' );
            $order->update_status( 'failed', __( 'Subscription payment failed: No user found.', 'ys-shopline-via-woocommerce' ) );
            return;
        }

        if ( ! $this->api ) {
            $this->log( 'API not configured', 'error' );
            $order->update_status( 'failed', __( 'Subscription payment failed: Gateway not configured.', 'ys-shopline-via-woocommerce' ) );
            return;
        }

        $customer_id = $this->get_shopline_customer_id( $user_id );
        if ( ! $customer_id ) {
            $this->log( 'No Shopline customer ID for user #' . $user_id, 'error' );
            $order->update_status( 'failed', __( 'Subscription payment failed: No customer ID.', 'ys-shopline-via-woocommerce' ) );
            return;
        }

        $instrument_id = $this->get_subscription_instrument_id( $order );
        if ( ! $instrument_id ) {
            $this->log( 'No payment instrument found for order #' . $order->get_id(), 'error' );
            $order->update_status( 'failed', __( 'Subscription payment failed: No saved payment method.', 'ys-shopline-via-woocommerce' ) );
            return;
        }

        // 2. 建構 Recurring 請求資料
        $data = $this->build_recurring_payment_data( $order, $amount, $customer_id, $instrument_id );

        YSLogger::debug( 'Recurring payment request', array(
            'order_id'      => $order->get_id(),
            'amount'        => $data['amount']['value'],
            'currency'      => $data['amount']['currency'],
            'customer_id'   => $customer_id,
            'instrument_id' => $instrument_id,
        ) );

        // 3. 呼叫 API + 處理回應（帶冪等鍵）
        $idempotent_key = (string) $order->get_meta( YSOrderMeta::REFERENCE_ORDER_ID );
        $response       = $this->api->create_payment_trade( $data, $idempotent_key );
        $this->handle_recurring_response( $order, $response );
    }

    /**
     * 建構 Recurring 續扣的 API 請求資料。
     *
     * 與一般結帳 prepare_payment_data() 的差異：
     * - paySession: '{}'（無 SDK）
     * - paymentBehavior: Recurring
     * - autoConfirm / autoCapture: true
     * - IP: 伺服器 IP（非客戶端）
     * - 必須提供 paymentCustomerId + paymentInstrumentId
     *
     * @param \WC_Order $order         Renewal order.
     * @param float     $amount        Renewal amount.
     * @param string    $customer_id   Shopline paymentCustomerId.
     * @param string    $instrument_id Shopline paymentInstrumentId.
     * @return array
     */
    protected function build_recurring_payment_data( $order, $amount, $customer_id, $instrument_id ) {
        $customer_personal_info = $this->build_personal_info( $order, 'billing' );
        $billing_address        = $this->build_address( $order, 'billing' );
        $shipping_address       = $this->build_address( $order, 'shipping' );
        $shipping_personal_info = $this->build_personal_info( $order, 'shipping' );
        $products               = $this->build_products( $order );

        return array(
            'paySession'       => '{}',
            'referenceOrderId' => $this->generate_reference_order_id( $order ),
            'returnUrl'        => $this->get_return_url( $order ),
            'acquirerType'     => 'SDK',
            'language'         => $this->get_shopline_language(),
            'amount'           => array(
                'value'    => \YSShoplinePayment::get_formatted_amount( $amount, $order->get_currency() ),
                'currency' => $order->get_currency(),
            ),
            'confirm'          => array(
                'paymentMethod'     => 'CreditCard',
                'paymentBehavior'   => 'Recurring',
                'paymentCustomerId' => $customer_id,
                'paymentInstrument' => array(
                    'paymentInstrumentId' => $instrument_id,
                ),
                'autoConfirm' => true,
                'autoCapture' => true,
            ),
            'customer'         => array(
                'referenceCustomerId' => (string) $order->get_user_id(),
                'type'                => '0',
                'personalInfo'        => $customer_personal_info,
            ),
            'billing'          => array(
                'description'  => sprintf( 'Subscription renewal #%s', $order->get_id() ),
                'personalInfo' => $customer_personal_info,
                'address'      => $billing_address,
            ),
            'order'            => array(
                'products' => $products,
                'shipping' => array(
                    'shippingMethod' => $order->get_shipping_method() ?: 'Standard',
                    'carrier'        => $order->get_shipping_method() ?: 'Default',
                    'personalInfo'   => ! empty( $shipping_personal_info['firstName'] )
                        ? $shipping_personal_info : $customer_personal_info,
                    'address'        => ! empty( $shipping_address['city'] )
                        ? $shipping_address : $billing_address,
                    'amount'         => array(
                        'value'    => \YSShoplinePayment::get_formatted_amount(
                            $order->get_shipping_total(), $order->get_currency()
                        ),
                        'currency' => $order->get_currency(),
                    ),
                ),
            ),
            'client'           => array(
                'ip'                 => $this->get_server_ip(),
                'transactionWebSite' => home_url(),
            ),
        );
    }

    /**
     * 處理 Recurring API 回應。
     *
     * @param \WC_Order $order    Renewal order.
     * @param array|\WP_Error $response API response.
     */
    protected function handle_recurring_response( $order, $response ) {
        if ( is_wp_error( $response ) ) {
            $this->log( 'Subscription payment failed: ' . $response->get_error_message(), 'error' );
            $order->update_status( 'failed', __( 'Subscription payment failed: ', 'ys-shopline-via-woocommerce' ) . $response->get_error_message() );
            return;
        }

        $trade_order_id = $response['tradeOrderId'] ?? '';
        $status         = isset( $response['status'] ) ? strtoupper( $response['status'] ) : '';

        $order->update_meta_data( YSOrderMeta::TRADE_ORDER_ID, $trade_order_id );
        $order->update_meta_data( YSOrderMeta::PAYMENT_STATUS, $status ?: 'UNKNOWN' );

        YSLogger::debug( 'Recurring payment response', array(
            'order_id'       => $order->get_id(),
            'trade_order_id' => $trade_order_id,
            'status'         => $status,
        ) );

        if ( in_array( $status, array( 'SUCCEEDED', 'SUCCESS', 'CAPTURED' ), true ) ) {
            $order->save();
            $order->payment_complete( $trade_order_id );
            $order->add_order_note(
                sprintf(
                    __( 'Subscription payment completed. Trade ID: %s', 'ys-shopline-via-woocommerce' ),
                    $trade_order_id
                )
            );
            $this->log( 'Subscription payment completed for order #' . $order->get_id() );

        } elseif ( in_array( $status, array( 'CREATED', 'AUTHORIZED' ), true ) ) {
            $order->save();
            $order->update_status( 'on-hold',
                sprintf(
                    __( 'Subscription payment awaiting confirmation (status: %s).', 'ys-shopline-via-woocommerce' ),
                    $status
                )
            );
            $this->log( "Subscription payment on-hold (status: {$status}) for order #" . $order->get_id() );

        } else {
            $error_msg = $response['msg'] ?? $response['message'] ?? __( 'Unknown payment status', 'ys-shopline-via-woocommerce' );
            $order->save();
            $order->update_status( 'failed',
                sprintf(
                    __( 'Subscription payment failed: %1$s (status: %2$s)', 'ys-shopline-via-woocommerce' ),
                    $error_msg,
                    $status ?: 'empty'
                )
            );
            $this->log( 'Subscription payment failed for order #' . $order->get_id() . ' - status: ' . $status, 'error' );
        }
    }

    /**
     * Get payment instrument ID for subscription renewal.
     *
     * 統一從 subscription meta 取得，這是唯一正確來源。
     * 首次付款時 save_subscription_meta_from_order() 會儲存 instrument ID。
     *
     * @param \WC_Order $order Renewal order.
     * @return string|false Instrument ID or false.
     */
    protected function get_subscription_instrument_id( $order ) {
        if ( ! function_exists( 'wcs_get_subscriptions_for_renewal_order' ) ) {
            $this->log( 'WooCommerce Subscriptions not active', 'error' );
            return false;
        }

        $subscriptions = wcs_get_subscriptions_for_renewal_order( $order );

        foreach ( $subscriptions as $subscription ) {
            $instrument_id = $subscription->get_meta( YSOrderMeta::PAYMENT_INSTRUMENT_ID );
            if ( $instrument_id ) {
                YSLogger::debug( 'Found instrument ID from subscription meta', array(
                    'order_id'        => $order->get_id(),
                    'subscription_id' => $subscription->get_id(),
                    'instrument_id'   => $instrument_id,
                ) );
                return $instrument_id;
            }
        }

        return false;
    }

    /**
     * Get server IP for Recurring payment.
     *
     * Recurring 是伺服器對伺服器呼叫，API 文件允許使用伺服器 IP。
     *
     * @return string
     */
    protected function get_server_ip() {
        if ( ! empty( $_SERVER['SERVER_ADDR'] ) ) {
            return sanitize_text_field( wp_unslash( $_SERVER['SERVER_ADDR'] ) );
        }

        // 備用：嘗試取得伺服器外部 IP
        $hostname = gethostname();
        if ( $hostname ) {
            $ip = gethostbyname( $hostname );
            if ( $ip && $ip !== $hostname ) {
                return $ip;
            }
        }

        return '127.0.0.1';
    }

    /**
     * Declare subscription payment meta fields.
     *
     * 讓 WooCommerce Subscriptions 知道我們需要哪些 meta 欄位，
     * 管理員可在後台手動修改這些欄位。
     *
     * @param array            $meta         Payment meta.
     * @param \WC_Subscription $subscription Subscription object.
     * @return array
     */
    public function subscription_payment_meta( $meta, $subscription ) {
        $meta[ $this->id ] = array(
            'post_meta' => array(
                YSOrderMeta::CUSTOMER_ID => array(
                    'value' => $subscription->get_meta( YSOrderMeta::CUSTOMER_ID ),
                    'label' => __( 'SHOPLINE Customer ID', 'ys-shopline-via-woocommerce' ),
                ),
                YSOrderMeta::PAYMENT_INSTRUMENT_ID => array(
                    'value' => $subscription->get_meta( YSOrderMeta::PAYMENT_INSTRUMENT_ID ),
                    'label' => __( 'SHOPLINE Payment Instrument ID', 'ys-shopline-via-woocommerce' ),
                ),
            ),
        );

        return $meta;
    }

    /**
     * Save subscription meta from first payment order.
     *
     * 在 process_payment() 時呼叫，此時只能存 customer_id。
     * instrument_id 由 YSRedirectHandler 或 YSWebhookHandler 在 token 建立後寫入。
     *
     * @param \WC_Order $order First payment order.
     */
    protected function save_subscription_meta_from_order( $order ) {
        if ( ! function_exists( 'wcs_get_subscriptions_for_order' ) ) {
            return;
        }

        $subscriptions = wcs_get_subscriptions_for_order( $order );

        if ( empty( $subscriptions ) ) {
            return;
        }

        $user_id     = $order->get_user_id();
        $customer_id = $user_id ? $this->get_shopline_customer_id( $user_id ) : '';

        foreach ( $subscriptions as $subscription ) {
            if ( $customer_id ) {
                $subscription->update_meta_data( YSOrderMeta::CUSTOMER_ID, $customer_id );
                $subscription->save();
            }

            YSLogger::debug( 'Saved subscription customer_id from order', array(
                'order_id'        => $order->get_id(),
                'subscription_id' => $subscription->get_id(),
                'customer_id'     => $customer_id ?: 'none',
            ) );
        }
    }

    /**
     * Update failing payment method.
     *
     * 當用戶更換失敗訂閱的付款方式時，更新 subscription 的 instrument ID。
     *
     * @param \WC_Subscription $subscription  Subscription object.
     * @param \WC_Order        $renewal_order Renewal order.
     */
    public function update_failing_payment_method( $subscription, $renewal_order ) {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $new_token_id = isset( $_POST[ 'wc-' . $this->id . '-payment-token' ] ) ? absint( $_POST[ 'wc-' . $this->id . '-payment-token' ] ) : 0;

        if ( $new_token_id ) {
            $token = \WC_Payment_Tokens::get( $new_token_id );
            if ( $token && $token->get_user_id() === $subscription->get_user_id() ) {
                $subscription->update_meta_data( YSOrderMeta::PAYMENT_INSTRUMENT_ID, $token->get_token() );
                $subscription->save();

                YSLogger::debug( 'Updated failing payment method', array(
                    'subscription_id' => $subscription->get_id(),
                    'instrument_id'   => $token->get_token(),
                ) );
            }
        }
    }

    /**
     * Get customer payment tokens.
     *
     * 覆寫父類別方法，因為信用卡 Token 統一存在 ys_shopline_credit 閘道下，
     * 但本閘道 ID 是 ys_shopline_credit_subscription，父類 get_tokens() 會查不到。
     *
     * @return \WC_Payment_Token[]
     */
    public function get_tokens() {
        if ( ! is_user_logged_in() ) {
            return array();
        }

        return \WC_Payment_Tokens::get_customer_tokens( get_current_user_id(), YSOrderMeta::CREDIT_GATEWAY_ID );
    }

    /**
     * Payment fields.
     */
    public function payment_fields() {
        if ( $this->description ) {
            echo wpautop( wp_kses_post( $this->description ) );
        }

        // SDK 容器已內建「已綁卡列表 + 使用新卡」tab UI（透過 customerToken + bindCard.enable）
        // 不呼叫 WC 原生 saved_payment_methods()，避免兩套 UI 重複
        printf(
            '<div id="%s_container" class="ys-shopline-payment-container" data-gateway="%s" data-payment-method="%s" data-force-save="true" style="min-height: 150px;"></div>',
            esc_attr( $this->id ),
            esc_attr( $this->id ),
            esc_attr( $this->get_payment_method() )
        );

        echo '<p class="ys-shopline-subscription-notice">';
        echo '<small>';
        esc_html_e( '此付款方式會儲存您的信用卡資訊以供定期扣款使用。', 'ys-shopline-via-woocommerce' );
        echo '</small>';
        echo '</p>';

        // 綁卡驗證說明（放在「定期扣款」提示之下，用藍色資訊框強化）
        // JS 會依 bindOnlyMode 動態改文字（試用期 vs 一般訂閱）
        printf(
            '<div class="ys-bindcard-hint ys-bindcard-hint-subscription" data-gateway="%s" style="' .
                'margin-top:10px;padding:12px 14px;background:#f0f7ff;border-left:4px solid #4a90e2;' .
                'border-radius:4px;font-size:13px;color:#333;line-height:1.6;">' .
                '<strong style="color:#4a90e2;display:block;margin-bottom:4px;">%s</strong>' .
                '<span class="ys-bindcard-hint-body">%s</span>' .
            '</div>',
            esc_attr( $this->id ),
            esc_html__( 'ℹ️ 綁卡驗證說明', 'ys-shopline-via-woocommerce' ),
            esc_html__( '首次結帳時將綁定此張信用卡，並依訂閱方案進行後續自動續扣。綁卡過程可能進行小額授權驗證，銀行將於驗證完成後自動解除，不會實際扣款。', 'ys-shopline-via-woocommerce' )
        );
    }
}
