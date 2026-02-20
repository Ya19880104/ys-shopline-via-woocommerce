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
     * Get SDK configuration.
     *
     * @return array
     */
    public function get_sdk_config() {
        $config = parent::get_sdk_config();

        // 訂閱付款必須有 customerToken 才能綁卡
        $has_customer_token = isset( $config['customerToken'] ) && ! empty( $config['customerToken'] );

        if ( ! $has_customer_token ) {
            YSLogger::warning( 'Subscription gateway: No customerToken available', array(
                'user_id' => get_current_user_id(),
            ) );
        }

        // Force save card for subscriptions
        $config['forceSaveCard'] = true;
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

        return $config;
    }

    /**
     * Prepare payment data.
     *
     * @param \WC_Order $order       Order object.
     * @param string    $pay_session Pay session from SDK.
     * @return array
     */
    protected function prepare_payment_data( $order, $pay_session ) {
        $data = parent::prepare_payment_data( $order, $pay_session );

        // Force CardBindPayment for subscriptions
        $data['confirm']['paymentBehavior'] = 'CardBindPayment';
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

        // 3. 呼叫 API + 處理回應
        $response = $this->api->create_payment_trade( $data );
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
     * Payment fields.
     */
    public function payment_fields() {
        if ( $this->description ) {
            echo wpautop( wp_kses_post( $this->description ) );
        }

        // Show saved cards only if user has tokens (avoid phantom radio button)
        if ( is_user_logged_in() && count( $this->get_tokens() ) > 0 ) {
            $this->saved_payment_methods();
        }

        // Container for SDK
        printf(
            '<div id="%s_container" class="ys-shopline-payment-container" data-gateway="%s" data-payment-method="%s" data-force-save="true" style="min-height: 150px;"></div>',
            esc_attr( $this->id ),
            esc_attr( $this->id ),
            esc_attr( $this->get_payment_method() )
        );

        // Info message
        echo '<p class="ys-shopline-subscription-notice">';
        echo '<small>';
        esc_html_e( '此付款方式會儲存您的信用卡資訊以供定期扣款使用。', 'ys-shopline-via-woocommerce' );
        echo '</small>';
        echo '</p>';
    }
}
