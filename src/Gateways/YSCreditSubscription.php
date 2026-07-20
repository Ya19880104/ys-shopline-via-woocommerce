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
use YangSheep\ShoplinePayment\Utils\YSApiError;
use YangSheep\ShoplinePayment\Utils\YSTradeStatus;
use YangSheep\ShoplinePayment\Handlers\YSPaymentConfirmation;

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
     * Prevent transaction-scoped SHOPLINE meta from being copied into a new renewal.
     *
     * WooCommerce Subscriptions copies custom meta on an opt-out basis. Customer and
     * instrument IDs belong to the subscription, but trade/reference/status markers
     * belong to one specific order and must never seed the next billing cycle.
     *
     * @param string $query      Renewal meta-copy SQL.
     * @param int    $to_order   Renewal order ID.
     * @param int    $from_order Subscription ID.
     * @return string
     */
    public static function exclude_order_specific_meta_from_renewals( $query, $to_order, $from_order ) {
        $excluded = array(
            YSOrderMeta::TRADE_ORDER_ID,
            YSOrderMeta::SESSION_ID,
            YSOrderMeta::PAYMENT_METHOD,
            YSOrderMeta::PAYMENT_DETAIL,
            YSOrderMeta::PAYMENT_STATUS,
            YSOrderMeta::REFUND_DETAIL,
            YSOrderMeta::REFUND_ATTEMPT,
            YSOrderMeta::NEXT_ACTION,
            YSOrderMeta::BIND_CARD_ATTEMPTED,
            YSOrderMeta::BIND_CARD_NOT_PERSISTED,
            YSOrderMeta::PAYMENT_AUTHORIZED_PENDING,
            YSOrderMeta::PAYMENT_COMPLETE_LOCK,
            YSOrderMeta::PAYMENT_ATTEMPT,
            YSOrderMeta::REFERENCE_ORDER_ID,
            YSOrderMeta::CARD_LAST4,
            YSOrderMeta::CARD_BRAND,
            YSOrderMeta::ERROR_CODE,
            YSOrderMeta::ERROR_MESSAGE,
            YSOrderMeta::VA_BANK_CODE,
            YSOrderMeta::VA_ACCOUNT,
            YSOrderMeta::VA_EXPIRE,
            YSOrderMeta::ABANDONED_TRADE_IDS,
            YSOrderMeta::ABANDONED_TRADE_PAID_IDS,
            YSOrderMeta::MANUAL_REVIEW_FLAG,
            YSOrderMeta::MANUAL_REVIEW_DATA,
            YSOrderMeta::MANUAL_REVIEW_RESOLVED,
            YSOrderMeta::INDETERMINATE_REF,
            YSOrderMeta::INDETERMINATE_DATA,
            YSOrderMeta::PAYMENT_ATTEMPT_DATA,
            YSOrderMeta::CONFIRMATION_DATA,
            YSOrderMeta::CONFIRMATION_HISTORY,
            YSOrderMeta::CONFIRMATION_MAIL_SENT,
            YSOrderMeta::CONFIRMATION_REVIEW,
            YSOrderMeta::INSTALLMENT,
            YSOrderMeta::BNPL_INSTALLMENT,
            YSOrderMeta::PENDING_BIND,
            YSOrderMeta::ADD_METHOD_NEXT_ACTION,
            YSOrderMeta::INSTRUMENTS_CACHE,
        );

        $quoted = array_map(
            static fn( $key ) => "'" . esc_sql( $key ) . "'",
            $excluded
        );

        return $query . ' AND `meta_key` NOT IN (' . implode( ',', $quoted ) . ')';
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
     * Get icon HTML.
     *
     * @return string
     */
    public function get_icon() {
        if ( ! $this->is_payment_icons_enabled() ) {
            return apply_filters( 'woocommerce_gateway_icon', '', $this->id );
        }

        $base = YS_SHOPLINE_PLUGIN_URL . 'assets/images/';
        $icons = array(
            '<img src="' . esc_url( $base . 'visa.svg' ) . '" alt="Visa" width="32" />',
            '<img src="' . esc_url( $base . 'mastercard.svg' ) . '" alt="Mastercard" width="32" />',
            '<img src="' . esc_url( $base . 'jcb.svg' ) . '" alt="JCB" width="32" />',
        );

        return apply_filters( 'woocommerce_gateway_icon', implode( ' ', $icons ), $this->id );
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

        // 訂閱 SDK config：
        // - 保留 parent 提供的 customerToken → SDK 自己顯示「已綁卡 tab + 新卡 tab」
        // - v3.5.30: bindCard.enable 一律 true（與 customerToken 解耦）
        //   SLP 客服確認：SLP 以 SDK 的 bindCard.enable 為準，即使 API 帶
        //   savePaymentInstrument:true，若 SDK enable=false 仍不綁卡。
        //   舊邏輯把 enable 綁在 has_customer_token，導致「首次訂閱顧客（無既有
        //   token）」被送 enable=false → 卡片永遠綁不上 → 訂閱無法續扣。
        //   customerToken 的作用只是讓 SDK 顯示既有卡列表，與「能否綁新卡」無關。

        // v3.5.32: 移除 textType（避免 4208 initData.paymentInstrument 參數異常）。
        //   原始可正常綁卡的設定本來就沒有 textType；enable:true 才是 SLP 確認的關鍵修法。
        $config['forceSaveCard']     = true;
        $config['paymentInstrument'] = array(
            'bindCard' => array(
                'enable'   => true,
                'protocol' => array(
                    'switchVisible'       => false,
                    'defaultSwitchStatus' => true,
                    'mustAccept'          => false,
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
            $data['amount']['value']             = 10100;

            // 同步修正 order.products 的 amount（避免商品總額與 amount 不符）
            if ( isset( $data['order']['products'][0]['amount']['value'] ) ) {
                $data['order']['products'][0]['amount']['value'] = 10100;
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
            return array( 'result' => 'failure', 'remote_outcome' => 'rejected' );
        }

        // 統一走 parent（SDK CardBindPayment），prepare_payment_data() 會強制綁卡
        $result = parent::process_payment( $order_id );

        // v3.5.36 P2: 只在遠端「明確受理」時才寫 subscription meta——
        // 不可用 result==='success'（WP_Error rejected 也回 success+redirect）、
        // 也不可用 accepted!==false（unknown 會誤過）；必須 remote_outcome === 'accepted'。
        if ( 'accepted' === ( is_array( $result ) ? ( $result['remote_outcome'] ?? '' ) : '' ) ) {
            $this->save_subscription_meta_from_order( $order );
        }

        return $result;
    }

    /**
     * Process subscription renewal payment (Recurring).
     *
     * v3.4.15 流程：
     *   1. 驗證 → 綁定卡扣款（primary，subscription meta）
     *   2. 若綁定卡扣款失敗且使用者有不同的預設卡 → 以預設卡重試（fallback）
     *   3. 若 subscription meta 空但使用者有預設卡 → 直接用預設卡
     *   4. 每次嘗試都寫訂單備註供管理員稽核（綁定/fallback/成功/失敗）
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

        // A renewal has no browser redirect to finish reconciliation. Resolve or block
        // indeterminate/prior trades before generate_reference_order_id() can advance.
        if ( ! $this->can_start_recurring_payment( $order ) ) {
            return;
        }

        $customer_id = $this->get_shopline_customer_id( $user_id );
        if ( ! $customer_id ) {
            $this->log( 'No Shopline customer ID for user #' . $user_id, 'error' );
            $order->update_status( 'failed', __( 'Subscription payment failed: No customer ID.', 'ys-shopline-via-woocommerce' ) );
            return;
        }

        // 2. 取得候選卡（bound = subscription meta、fallback = 使用者預設卡）
        $bound_instrument_id    = $this->get_bound_subscription_instrument_id( $order );
        $fallback_instrument_id = $this->get_user_default_instrument_id( $user_id );

        // 2a. 優先嘗試綁定卡
        if ( $bound_instrument_id ) {
            $order->add_order_note( sprintf(
                /* translators: %s: last 6 chars of instrument id */
                __( '訂閱續扣：嘗試綁定卡片（末 6 碼 %s）', 'ys-shopline-via-woocommerce' ),
                substr( (string) $bound_instrument_id, -6 )
            ) );

            $attempt  = $this->try_recurring_charge( $order, $amount, $customer_id, $bound_instrument_id );
            $response = $attempt['response'];
            $outcome  = YSApiError::classify_create_trade_response( $response );

            if ( 'accepted' === $outcome ) {
                $this->handle_recurring_response( $order, $response );
                return;
            }

            if ( 'unknown' === $outcome ) {
                $this->handle_recurring_unknown( $order, $attempt, $bound_instrument_id );
                return;
            }

            // Only a definitive rejection may try a different instrument.
            $fail_reason = is_wp_error( $response )
                ? $response->get_error_message()
                : ( $response['paymentMsg']['msg'] ?? $response['status'] ?? 'UNKNOWN' );

            $order->add_order_note( sprintf(
                /* translators: 1: last 6, 2: reason */
                __( '訂閱續扣：綁定卡片（末 6 碼 %1$s）扣款失敗 — %2$s', 'ys-shopline-via-woocommerce' ),
                substr( (string) $bound_instrument_id, -6 ),
                $fail_reason
            ) );

            YSLogger::warning( 'Bound subscription card charge failed, evaluating default card fallback', array(
                'order_id'            => $order->get_id(),
                'bound_instrument'    => substr( (string) $bound_instrument_id, -6 ),
                'fallback_instrument' => $fallback_instrument_id ? substr( (string) $fallback_instrument_id, -6 ) : null,
                'reason'              => $fail_reason,
            ) );

            // 2b. 如果使用者預設卡不同，嘗試 fallback
            if ( $fallback_instrument_id && $fallback_instrument_id !== $bound_instrument_id ) {
                $order->add_order_note( sprintf(
                    __( '訂閱續扣：改用使用者預設卡片 fallback（末 6 碼 %s）', 'ys-shopline-via-woocommerce' ),
                    substr( (string) $fallback_instrument_id, -6 )
                ) );

                $attempt2  = $this->try_recurring_charge( $order, $amount, $customer_id, $fallback_instrument_id );
                $response2 = $attempt2['response'];
                $outcome2  = YSApiError::classify_create_trade_response( $response2 );

                if ( 'accepted' === $outcome2 ) {
                    $order->add_order_note( __( '訂閱續扣：預設卡片 fallback 已由 SHOPLINE 受理。原綁定卡請客戶檢查或更換。', 'ys-shopline-via-woocommerce' ) );
                    $this->handle_recurring_response( $order, $response2 );
                    return;
                }

                if ( 'unknown' === $outcome2 ) {
                    $this->handle_recurring_unknown( $order, $attempt2, $fallback_instrument_id );
                    return;
                }

                // Both attempts were definitively rejected.
                $order->add_order_note( __( '訂閱續扣：綁定卡與預設卡均扣款失敗，請人工處理。', 'ys-shopline-via-woocommerce' ) );
                $this->handle_recurring_response( $order, $response2 );
                return;
            }

            // 沒有可用的 fallback（或預設卡與綁定卡相同）
            $this->handle_recurring_response( $order, $response );
            return;
        }

        // 3. 沒有綁定卡 → 直接用預設卡
        if ( $fallback_instrument_id ) {
            $order->add_order_note( sprintf(
                __( '訂閱續扣：訂閱未指定卡片，使用使用者預設卡（末 6 碼 %s）', 'ys-shopline-via-woocommerce' ),
                substr( (string) $fallback_instrument_id, -6 )
            ) );

            $attempt  = $this->try_recurring_charge( $order, $amount, $customer_id, $fallback_instrument_id );
            $response = $attempt['response'];
            $outcome  = YSApiError::classify_create_trade_response( $response );

            if ( 'unknown' === $outcome ) {
                $this->handle_recurring_unknown( $order, $attempt, $fallback_instrument_id );
                return;
            }

            $this->handle_recurring_response( $order, $response );
            return;
        }

        // 4. 真的沒有任何可用卡片
        $this->log( 'No payment instrument found for order #' . $order->get_id(), 'error' );
        $order->update_status( 'failed', __( 'Subscription payment failed: No saved payment method.', 'ys-shopline-via-woocommerce' ) );
    }

    /**
     * 執行單次 Recurring 扣款（供 process_subscription_payment 重試邏輯使用）。
     *
     * @param \WC_Order $order
     * @param float     $amount
     * @param string    $customer_id
     * @param string    $instrument_id
     * @return array{response:array|\WP_Error,payment_data:array,idempotent_key:string}
     */
    protected function try_recurring_charge( $order, $amount, $customer_id, $instrument_id ) {
        $data = $this->build_recurring_payment_data( $order, $amount, $customer_id, $instrument_id );

        YSLogger::debug( 'Recurring payment request', array(
            'order_id'      => $order->get_id(),
            'amount'        => $data['amount']['value'],
            'currency'      => $data['amount']['currency'],
            'customer_id'   => $customer_id,
            'instrument_id' => substr( (string) $instrument_id, -6 ),
        ) );

        // 每次嘗試使用獨立冪等鍵（reference_order_id + instrument 尾碼），避免 retry 被 SHOPLINE 冪等擋下
        $idempotent_key = (string) $order->get_meta( YSOrderMeta::REFERENCE_ORDER_ID ) . '-' . substr( (string) $instrument_id, -6 );

        return array(
            'response'        => $this->api->create_payment_trade( $data, $idempotent_key ),
            'payment_data'    => $data,
            'idempotent_key'  => $idempotent_key,
        );
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

        if ( ! is_array( $response ) ) {
            $this->put_recurring_on_hold(
                $order,
                __( 'Subscription payment returned a malformed response. The payment status requires manual confirmation.', 'ys-shopline-via-woocommerce' )
            );
            return;
        }

        $trade_order_id = trim( (string) ( $response['tradeOrderId'] ?? '' ) );
        $status         = strtoupper( trim( (string) ( $response['status'] ?? '' ) ) );

        // Defensive fail-closed guard. Normal callers route this case through
        // handle_recurring_unknown() so the exact request envelope is retained.
        if ( '' === $trade_order_id || '' === $status ) {
            $this->put_recurring_on_hold(
                $order,
                __( 'Subscription payment was accepted without complete trade information. The payment status requires manual confirmation.', 'ys-shopline-via-woocommerce' )
            );
            return;
        }

        $order->update_meta_data( YSOrderMeta::TRADE_ORDER_ID, $trade_order_id );
        $order->update_meta_data( YSOrderMeta::PAYMENT_STATUS, $status ?: 'UNKNOWN' );

        YSLogger::debug( 'Recurring payment response', array(
            'order_id'       => $order->get_id(),
            'trade_order_id' => $trade_order_id,
            'status'         => $status,
        ) );

        if ( YSTradeStatus::is_paid( $status ) ) {
            $order->save();
            $completion = YSPaymentConfirmation::complete_payment_once( $order, $trade_order_id );
            if ( ! empty( $completion['busy'] ) || ! ( $completion['order'] instanceof \WC_Order ) ) {
                $this->log( 'Subscription payment completion deferred because the order lock is busy: #' . $order->get_id(), 'warning' );
                return;
            }
            if ( empty( $completion['completed'] ) ) {
                $this->log( 'Subscription payment was already completed by another converger: #' . $order->get_id() );
                return;
            }

            $order = $completion['order'];
            $order->add_order_note(
                sprintf(
                    __( 'Subscription payment completed. Trade ID: %s', 'ys-shopline-via-woocommerce' ),
                    $trade_order_id
                )
            );
            $this->log( 'Subscription payment completed for order #' . $order->get_id() );

        } elseif ( YSTradeStatus::is_customer_pending( $status ) || YSTradeStatus::is_in_flight( $status ) ) {
            $order->save();
            $this->put_recurring_on_hold(
                $order,
                sprintf(
                    __( 'Subscription payment awaiting confirmation (status: %s).', 'ys-shopline-via-woocommerce' ),
                    $status
                )
            );
            $this->log( "Subscription payment on-hold (status: {$status}) for order #" . $order->get_id() );

        } elseif ( YSTradeStatus::is_terminal_safe( $status ) ) {
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
        } else {
            $order->save();
            $this->put_recurring_on_hold(
                $order,
                sprintf(
                    __( 'Subscription payment returned an unknown status (%s). The payment status requires manual confirmation.', 'ys-shopline-via-woocommerce' ),
                    $status
                )
            );
            $this->log( 'Subscription payment unknown for order #' . $order->get_id() . ' - status: ' . $status, 'error' );
        }
    }

    /**
     * Resolve an indeterminate or existing renewal trade before creating a new one.
     *
     * @param \WC_Order $order Renewal order.
     * @return bool True when a new recurring trade may be created.
     */
    protected function can_start_recurring_payment( $order ) {
        $indeterminate = $this->resolve_indeterminate( $order );
        if ( 'blocked' === $indeterminate ) {
            $this->put_recurring_on_hold(
                $order,
                __( '訂閱續扣：前次付款結果仍在確認中，為避免重複扣款，本次未建立新交易。', 'ys-shopline-via-woocommerce' )
            );
            return false;
        }

        if ( $order->is_paid() ) {
            return false;
        }

        $trade_order_id = trim( (string) $order->get_meta( YSOrderMeta::TRADE_ORDER_ID ) );
        if ( '' === $trade_order_id ) {
            return true;
        }

        // Renewal orders may inherit extension meta from the subscription. A trade is
        // current only when its reference was generated for this renewal order.
        $reference       = trim( (string) $order->get_meta( YSOrderMeta::REFERENCE_ORDER_ID ) );
        $expected_prefix = (string) $order->get_id() . '_';
        if ( '' === $reference || 0 !== strpos( $reference, $expected_prefix ) ) {
            $this->clear_prior_payment_meta( $order );
            $order->delete_meta_data( YSOrderMeta::REFERENCE_ORDER_ID );
            $order->delete_meta_data( YSOrderMeta::PAYMENT_ATTEMPT );
            $order->add_order_note( __( '訂閱續扣：已忽略從前一期複製而來的交易資料，將為本期建立獨立交易。', 'ys-shopline-via-woocommerce' ) );
            $order->save();
            return true;
        }

        $response = $this->api->get_payment_trade( $trade_order_id );
        if ( is_wp_error( $response ) ) {
            $this->put_recurring_on_hold(
                $order,
                __( '訂閱續扣：無法確認前次交易狀態，為避免重複扣款，本次未建立新交易。', 'ys-shopline-via-woocommerce' )
            );
            return false;
        }

        $status = strtoupper( trim( (string) ( $response['status'] ?? '' ) ) );
        if ( YSTradeStatus::is_terminal_safe( $status ) ) {
            $this->clear_prior_payment_meta( $order );
            $order->add_order_note( sprintf(
                __( '訂閱續扣：前次交易已確認終止（%s），允許建立新的續扣交易。', 'ys-shopline-via-woocommerce' ),
                $status
            ) );
            return true;
        }

        if ( YSTradeStatus::is_paid( $status )
            || YSTradeStatus::is_customer_pending( $status )
            || YSTradeStatus::is_in_flight( $status ) ) {
            $response['tradeOrderId'] = $trade_order_id;
            $this->handle_recurring_response( $order, $response );
            return false;
        }

        $this->put_recurring_on_hold(
            $order,
            sprintf(
                __( '訂閱續扣：前次交易回傳未知狀態（%s），為避免重複扣款，本次未建立新交易。', 'ys-shopline-via-woocommerce' ),
                $status ?: 'empty'
            )
        );
        return false;
    }

    /**
     * Persist an indeterminate recurring attempt and prevent alternate-card retry.
     *
     * @param \WC_Order $order         Renewal order.
     * @param array     $attempt       Attempt envelope from try_recurring_charge().
     * @param string    $instrument_id Instrument used for the attempt.
     * @return void
     */
    protected function handle_recurring_unknown( $order, array $attempt, $instrument_id ) {
        $payment_data   = isset( $attempt['payment_data'] ) && is_array( $attempt['payment_data'] ) ? $attempt['payment_data'] : array();
        $idempotent_key = (string) ( $attempt['idempotent_key'] ?? '' );

        $this->mark_indeterminate( $order, '{}', $idempotent_key, $payment_data );
        $order->update_meta_data( YSOrderMeta::PAYMENT_STATUS, 'INDETERMINATE' );
        $order->save();

        $this->put_recurring_on_hold(
            $order,
            __( '訂閱續扣：SHOPLINE 回應不明，可能已扣款。為避免重複扣款，已停止 fallback 與後續重試，請等待 webhook 或人工核對。', 'ys-shopline-via-woocommerce' )
        );

        YSLogger::warning( 'Recurring payment indeterminate; alternate-card retry blocked', array(
            'order_id'         => $order->get_id(),
            'reference'        => (string) ( $payment_data['referenceOrderId'] ?? '' ),
            'instrument_suffix'=> substr( (string) $instrument_id, -6 ),
        ) );
    }

    /**
     * Put a renewal on hold without duplicating status transitions.
     *
     * @param \WC_Order $order   Renewal order.
     * @param string    $message Audit note/status message.
     * @return void
     */
    protected function put_recurring_on_hold( $order, $message ) {
        if ( 'on-hold' === $order->get_status() ) {
            $order->add_order_note( $message );
            return;
        }

        $order->update_status( 'on-hold', $message );
    }

    /**
     * Get bound payment instrument ID from subscription meta (authoritative).
     *
     * v3.4.15: 拆自原 get_subscription_instrument_id，只讀 subscription meta，不做 fallback。
     * Fallback 邏輯搬到 process_subscription_payment，由「綁定卡扣款失敗」觸發。
     *
     * @param \WC_Order $order Renewal order.
     * @return string|false Instrument ID or false.
     */
    protected function get_bound_subscription_instrument_id( $order ) {
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
                    'instrument_id'   => substr( (string) $instrument_id, -6 ),
                ) );
                return $instrument_id;
            }
        }

        return false;
    }

    /**
     * Get user's default WC Payment Token instrument_id（for SHOPLINE credit gateway only）。
     *
     * v3.4.15: 獨立函式供 process_subscription_payment 的 fallback 使用。
     *
     * @param int $user_id
     * @return string|false Instrument ID or false.
     */
    protected function get_user_default_instrument_id( $user_id ) {
        if ( ! $user_id ) {
            return false;
        }

        $default_token = \WC_Payment_Tokens::get_customer_default_token( $user_id );
        if ( $default_token && YSOrderMeta::CREDIT_GATEWAY_ID === $default_token->get_gateway_id() ) {
            return $default_token->get_token();
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
