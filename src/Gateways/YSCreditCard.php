<?php
/**
 * Credit Card Gateway for YS Shopline Payment.
 *
 * @package YangSheep\ShoplinePayment\Gateways
 */

namespace YangSheep\ShoplinePayment\Gateways;

defined( 'ABSPATH' ) || exit;

// v3.5.11: WC_HTTPS removed — get_icon() now uses local SVG via YS_SHOPLINE_PLUGIN_URL (already https)
use YangSheep\ShoplinePayment\Utils\YSOrderMeta;

/**
 * YSCreditCard Class.
 *
 * Credit card single payment gateway (no installment).
 */
class YSCreditCard extends YSGatewayBase {

    /**
     * Constructor.
     */
    public function __construct() {
        $this->id                 = 'ys_shopline_credit';
        $this->icon               = '';
        $this->has_fields         = true;
        $this->method_title       = __( 'SHOPLINE 信用卡', 'ys-shopline-via-woocommerce' );
        $this->method_description = __( '透過 SHOPLINE Payment 信用卡一次付清', 'ys-shopline-via-woocommerce' );

        // Supports
        // v3.4.15: 加入 subscription_payment_method_change_* 讓訂閱能改扣款卡到本 gateway
        // （訂閱初始建立仍由 YSCreditSubscription 處理，此處只接既有訂閱的「變更付款方式」）
        $this->supports = array(
            'products',
            'refunds',
            'tokenization',
            'add_payment_method',
            'subscription_payment_method_change',
            'subscription_payment_method_change_customer',
            'subscription_payment_method_change_admin',
        );

        parent::__construct();

        // 訂閱變更付款方式成功後同步 instrument_id 到 subscription meta
        add_action(
            'woocommerce_subscription_payment_method_updated_to_' . $this->id,
            array( $this, 'sync_subscription_instrument_after_method_change' ),
            10,
            2
        );
    }

    /**
     * 當客戶把訂閱付款方式改為本 gateway 時，把最新的 instrument_id 寫回 subscription meta。
     *
     * WC Subscriptions 的 change-payment-method 流程：
     *   1. 客戶在訂閱內點「變更付款方式」→ 進 pay-for-order 表單
     *   2. 填新卡資訊 → 走 process_payment → YSGatewayBase 會在 order meta 寫 instrument_id
     *   3. WCS 把 subscription 的 _payment_method 改成本 gateway → 觸發本 hook
     *   4. 我們把 order meta 的 instrument_id 回寫到 subscription meta（否則續扣取不到）
     *
     * @param \WC_Subscription $subscription        Subscription being changed.
     * @param string           $old_payment_method  Previous gateway id.
     */
    public function sync_subscription_instrument_after_method_change( $subscription, $old_payment_method ) {
        if ( ! $subscription || ! is_object( $subscription ) ) {
            return;
        }

        // WCS 會把變更過程中的 order 掛在 subscription 上，取最近一張 parent/related order
        $parent_order = $subscription->get_parent();
        $instrument_id = $parent_order ? (string) $parent_order->get_meta( YSOrderMeta::PAYMENT_INSTRUMENT_ID ) : '';

        // 若 parent order 沒寫（不同版本 WCS 行為），改從使用者 default token 取
        if ( empty( $instrument_id ) ) {
            $user_id = $subscription->get_user_id();
            if ( $user_id ) {
                $default_token = \WC_Payment_Tokens::get_customer_default_token( $user_id );
                if ( $default_token && YSOrderMeta::CREDIT_GATEWAY_ID === $default_token->get_gateway_id() ) {
                    $instrument_id = $default_token->get_token();
                }
            }
        }

        if ( empty( $instrument_id ) ) {
            \YangSheep\ShoplinePayment\Utils\YSLogger::warning(
                'Subscription payment method changed but no instrument_id found to sync',
                array(
                    'subscription_id'     => $subscription->get_id(),
                    'old_payment_method'  => $old_payment_method,
                    'new_payment_method'  => $this->id,
                )
            );
            return;
        }

        $subscription->update_meta_data( YSOrderMeta::PAYMENT_INSTRUMENT_ID, $instrument_id );
        $subscription->save();

        $subscription->add_order_note( sprintf(
            /* translators: 1: old gateway id, 2: last 6 chars */
            __( '訂閱付款方式已從 %1$s 改為 SHOPLINE 信用卡（末 6 碼 %2$s），續扣將使用此卡。', 'ys-shopline-via-woocommerce' ),
            $old_payment_method,
            substr( $instrument_id, -6 )
        ) );

        \YangSheep\ShoplinePayment\Utils\YSLogger::info(
            'Subscription instrument synced after payment method change',
            array(
                'subscription_id'    => $subscription->get_id(),
                'old_payment_method' => $old_payment_method,
                'instrument_id'      => substr( $instrument_id, -6 ),
            )
        );
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

        // v3.5.31: 儲存卡片選項對「已登入用戶」一律開放，與 customerToken 解耦。
        //   舊邏輯把勾選框綁在 has_customer_token，導致「首次顧客（customer/token
        //   API 尚未成功）」看不到儲存卡勾選框 → 無法存卡。
        //   customerToken 的用途只是顯示既有卡列表；「能否儲存新卡」取決於是否登入。
        //   訪客（未登入，user_id=0）不送 paymentInstrument → 不顯示勾選框。
        $user_id = get_current_user_id();

        // v3.5.32: 移除 textType。SDK 在 switchVisible=true（顯示勾選框）+ 有 customerToken
        //   的情境下，帶 textType 會觸發 initData.paymentInstrument 參數異常 (4208)。
        //   原始可正常綁卡的設定本來就沒有 textType，故移除。
        if ( $user_id ) {
            $config['paymentInstrument'] = array(
                'bindCard' => array(
                    'enable'   => true,
                    'protocol' => array(
                        'switchVisible'       => true,   // 顯示勾選框（用戶自選）
                        'defaultSwitchStatus' => false,  // 預設不勾
                        'mustAccept'          => false,  // 不勾也能付款
                    ),
                ),
            );
        }

        return $config;
    }

    /**
     * Payment fields.
     */
    public function payment_fields() {
        if ( $this->description ) {
            echo wpautop( wp_kses_post( $this->description ) );
        }

        // SDK 容器：customerToken + bindCard.enable 會讓 SDK 自己顯示「已綁卡 + 新卡」tab
        printf(
            '<div id="%s_container" class="ys-shopline-payment-container" data-gateway="%s" data-payment-method="%s" style="min-height: 150px;"></div>',
            esc_attr( $this->id ),
            esc_attr( $this->id ),
            esc_attr( $this->get_payment_method() )
        );
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

        // v3.5.11: 改用本地化 SVG（assets/images/）— 詳見 YSCreditInstallment::get_icon 註釋
        $base = YS_SHOPLINE_PLUGIN_URL . 'assets/images/';
        $icons = array(
            '<img src="' . esc_url( $base . 'visa.svg' ) . '" alt="Visa" width="32" />',
            '<img src="' . esc_url( $base . 'mastercard.svg' ) . '" alt="Mastercard" width="32" />',
            '<img src="' . esc_url( $base . 'jcb.svg' ) . '" alt="JCB" width="32" />',
        );

        return apply_filters( 'woocommerce_gateway_icon', implode( ' ', $icons ), $this->id );
    }
}
