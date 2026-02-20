<?php
/**
 * Redirect Handler for YS Shopline Payment.
 *
 * 處理從 SHOPLINE SDK 跳轉回來的請求，
 * 在用戶到達感謝頁面之前查詢 API 確認付款狀態。
 *
 * 這是參考 Stripe 外掛的做法：
 * - 在 redirect 時查詢付款狀態
 * - 立即更新訂單狀態
 * - 然後才跳轉到感謝頁面
 *
 * @package YangSheep\ShoplinePayment\Handlers
 */

namespace YangSheep\ShoplinePayment\Handlers;

defined( 'ABSPATH' ) || exit;

use YangSheep\ShoplinePayment\Utils\YSLogger;
use YangSheep\ShoplinePayment\Utils\YSOrderMeta;
use YangSheep\ShoplinePayment\Customer\YSCustomer;
use WC_Payment_Tokens;
use WC_Payment_Token_CC;
use Exception;

/**
 * YSRedirectHandler Class.
 */
class YSRedirectHandler {

    /**
     * Initialize the handler.
     */
    public static function init() {
        // 在 template_redirect 之前處理（優先級 5）
        add_action( 'template_redirect', array( __CLASS__, 'process_redirect' ), 5 );
    }

    /**
     * Process redirect from SHOPLINE SDK.
     *
     * SDK 成功後會跳轉到 returnUrl（WooCommerce 感謝頁面）。
     * 我們在這裡攔截，查詢 API 確認付款狀態，然後讓用戶繼續到感謝頁面。
     */
    public static function process_redirect() {
        // 檢查是否是感謝頁面
        if ( ! is_wc_endpoint_url( 'order-received' ) ) {
            return;
        }

        // 取得 order ID
        global $wp;
        $order_id = isset( $wp->query_vars['order-received'] ) ? absint( $wp->query_vars['order-received'] ) : 0;

        if ( ! $order_id ) {
            return;
        }

        $order = wc_get_order( $order_id );

        if ( ! $order ) {
            return;
        }

        // 檢查是否是 Shopline 付款方式
        $payment_method = $order->get_payment_method();
        if ( strpos( $payment_method, 'ys_shopline' ) !== 0 ) {
            return;
        }

        // 如果訂單已付款，不需要處理
        if ( $order->is_paid() ) {
            YSLogger::debug( 'Redirect handler: Order already paid', array(
                'order_id' => $order_id,
            ) );
            // 清除 next_action
            self::clear_next_action( $order );
            return;
        }

        // 取得 trade order ID
        $trade_order_id = $order->get_meta( YSOrderMeta::TRADE_ORDER_ID );

        if ( ! $trade_order_id ) {
            YSLogger::warning( 'Redirect handler: No trade_order_id', array(
                'order_id' => $order_id,
            ) );
            return;
        }

        YSLogger::info( 'Redirect handler: Checking payment status', array(
            'order_id'       => $order_id,
            'trade_order_id' => $trade_order_id,
        ) );

        // 查詢 API 確認付款狀態
        self::check_and_update_order( $order, $trade_order_id );
    }

    /**
     * Check payment status and update order.
     *
     * @param \WC_Order $order          Order object.
     * @param string   $trade_order_id Shopline trade order ID.
     */
    private static function check_and_update_order( $order, $trade_order_id ) {
        $api = \YSShoplinePayment::get_api();

        if ( ! $api ) {
            YSLogger::error( 'Redirect handler: API not available' );
            return;
        }

        // 查詢訂單狀態
        $response = $api->get_payment_trade( $trade_order_id );

        if ( is_wp_error( $response ) ) {
            YSLogger::error( 'Redirect handler: API query failed', array(
                'error' => $response->get_error_message(),
            ) );
            return;
        }

        // 記錄完整的 API 回應結構以便除錯
        YSLogger::debug( 'Redirect handler: Full API response', array(
            'status'              => $response['status'] ?? 'unknown',
            'subStatus'           => $response['subStatus'] ?? 'unknown',
            'response_keys'       => array_keys( $response ),
            'has_payment'         => isset( $response['payment'] ) ? 'yes' : 'no',
            'has_paymentInstrument' => isset( $response['paymentInstrument'] ) ? 'yes' : 'no',
            'paymentMethod'       => $response['paymentMethod'] ?? ( $response['payment']['paymentMethod'] ?? 'unknown' ),
        ) );

        $status = isset( $response['status'] ) ? $response['status'] : '';

        // 根據狀態更新訂單
        // 注意：SHOPLINE API 回傳 'SUCCEEDED' 而不是 'SUCCESS'
        if ( 'SUCCEEDED' === $status || 'SUCCESS' === $status || 'CAPTURED' === $status ) {
            // 付款成功
            if ( ! $order->is_paid() ) {
                $order->payment_complete( $trade_order_id );
                $order->add_order_note(
                    sprintf(
                        /* translators: %s: Trade order ID */
                        __( 'SHOPLINE 付款已確認（透過跳轉查詢）。交易編號：%s', 'ys-shopline-via-woocommerce' ),
                        $trade_order_id
                    )
                );

                // 儲存付款資訊
                // SHOPLINE API 可能把付款資訊放在不同位置
                // 嘗試多種路徑取得資料
                $payment_method = $response['paymentMethod']
                    ?? $response['payment']['paymentMethod']
                    ?? '';
                $payment_instrument = $response['paymentInstrument']
                    ?? $response['payment']['paymentInstrument']
                    ?? array();
                $credit_card = $response['creditCard']
                    ?? $response['payment']['creditCard']
                    ?? $payment_instrument['instrumentCard']
                    ?? array();
                $payment_customer_id = $response['paymentCustomerId']
                    ?? $response['payment']['paymentCustomerId']
                    ?? $response['customerId']
                    ?? '';
                $payment_instrument_id = $payment_instrument['paymentInstrumentId']
                    ?? $payment_instrument['instrumentId']
                    ?? '';

                YSLogger::debug( 'Redirect handler: Extracted payment info', array(
                    'payment_method'        => $payment_method,
                    'payment_customer_id'   => $payment_customer_id,
                    'payment_instrument_id' => $payment_instrument_id,
                    'credit_card'           => $credit_card,
                    'raw_instrument'        => $payment_instrument,
                ) );

                if ( $payment_method ) {
                    $order->update_meta_data( YSOrderMeta::PAYMENT_METHOD, $payment_method );
                }
                if ( ! empty( $credit_card ) ) {
                    $order->update_meta_data( YSOrderMeta::CARD_LAST4, $credit_card['last4'] ?? $credit_card['last'] ?? '' );
                    $order->update_meta_data( YSOrderMeta::CARD_BRAND, $credit_card['brand'] ?? '' );
                }

                // 同步付款工具到 WooCommerce Payment Tokens
                if ( ! empty( $payment_instrument_id ) ) {
                    self::sync_payment_token(
                        $order,
                        $payment_instrument_id,
                        $credit_card,
                        $payment_customer_id
                    );

                    // 如果此訂單有關聯 subscription，將 instrument_id 寫入 subscription meta
                    // 放在 sync_payment_token 外面，因為不管 token 是新建還是已存在都要執行
                    self::update_subscription_instrument( $order, $payment_instrument_id );
                }

                // 儲存完整的付款詳情
                $order->update_meta_data( YSOrderMeta::PAYMENT_DETAIL, array(
                    'paymentMethod'       => $payment_method,
                    'paymentInstrument'   => $payment_instrument,
                    'creditCard'          => $credit_card,
                    'paymentCustomerId'   => $payment_customer_id,
                ) );

                $order->update_meta_data( YSOrderMeta::PAYMENT_STATUS, $status );
                $order->save();

                YSLogger::info( 'Redirect handler: Order completed', array(
                    'order_id' => $order->get_id(),
                    'status'   => $status,
                ) );
            }
        } elseif ( 'AUTHORIZED' === $status ) {
            // 已授權但未請款（手動請款模式）
            $order->update_status( 'on-hold', __( 'SHOPLINE 付款已授權，等待請款。', 'ys-shopline-via-woocommerce' ) );
            $order->update_meta_data( YSOrderMeta::PAYMENT_STATUS, 'AUTHORIZED' );
            $order->save();
        } elseif ( 'FAILED' === $status ) {
            // 付款失敗
            $error_msg = isset( $response['paymentMsg']['msg'] ) ? $response['paymentMsg']['msg'] : __( '付款失敗', 'ys-shopline-via-woocommerce' );
            $order->update_status( 'failed', $error_msg );
            $order->update_meta_data( YSOrderMeta::PAYMENT_STATUS, 'FAILED' );
            $order->save();
        }
        // 其他狀態（CREATED, PROCESSING）暫不處理，等待 Webhook 或用戶重試

        // 補抓 ATM 虛擬帳號資訊（API 回傳 VA 在 payment 物件內）
        $va_data = $response['payment']['virtualAccount'] ?? null;
        if ( 'ys_shopline_atm' === $order->get_payment_method() && $va_data ) {
            if ( ! $order->get_meta( YSOrderMeta::VA_ACCOUNT ) ) {
                $order->update_meta_data( YSOrderMeta::VA_BANK_CODE, $va_data['recipientBankCode'] ?? '' );
                $order->update_meta_data( YSOrderMeta::VA_ACCOUNT, $va_data['recipientAccountNum'] ?? '' );
                $order->update_meta_data( YSOrderMeta::VA_EXPIRE, $va_data['dueDate'] ?? '' );
                $order->save();

                YSLogger::info( 'Redirect handler: ATM VA info supplemented from query', array(
                    'order_id' => $order->get_id(),
                ) );
            }
        }

        // 清除 next_action
        self::clear_next_action( $order );
    }

    /**
     * Clear next_action meta data.
     *
     * @param \WC_Order $order Order object.
     */
    private static function clear_next_action( $order ) {
        $next_action = $order->get_meta( YSOrderMeta::NEXT_ACTION );
        if ( $next_action ) {
            $order->delete_meta_data( YSOrderMeta::NEXT_ACTION );
            $order->save();
        }
    }

    /**
     * Update subscription instrument ID from order.
     *
     * 在付款成功後，將 instrument_id 寫入關聯的 subscription meta。
     * 此方法獨立於 sync_payment_token()，因為不管 token 是新建還是已存在都需要執行。
     *
     * @param \WC_Order $order                 Order object.
     * @param string    $payment_instrument_id SHOPLINE payment instrument ID.
     */
    private static function update_subscription_instrument( $order, $payment_instrument_id ) {
        if ( ! function_exists( 'wcs_get_subscriptions_for_order' ) ) {
            return;
        }

        $subscriptions = wcs_get_subscriptions_for_order( $order );

        foreach ( $subscriptions as $subscription ) {
            $existing = $subscription->get_meta( YSOrderMeta::PAYMENT_INSTRUMENT_ID );
            if ( empty( $existing ) || $existing !== $payment_instrument_id ) {
                $subscription->update_meta_data( YSOrderMeta::PAYMENT_INSTRUMENT_ID, $payment_instrument_id );
                $subscription->save();
                YSLogger::info( "Redirect handler: Updated subscription #{$subscription->get_id()} instrument ID", array(
                    'instrument_id' => $payment_instrument_id,
                ) );
            }
        }
    }

    /**
     * Sync payment token to WooCommerce.
     *
     * 當用戶儲存卡片後，將 SHOPLINE 的 paymentInstrumentId 同步到 WooCommerce Payment Tokens，
     * 這樣結帳頁面就能顯示已儲存的卡片。
     *
     * @param \WC_Order $order                 Order object.
     * @param string   $payment_instrument_id SHOPLINE payment instrument ID.
     * @param array    $card_info             Credit card information.
     * @param string   $payment_customer_id   SHOPLINE customer ID.
     */
    private static function sync_payment_token( $order, $payment_instrument_id, $card_info, $payment_customer_id ) {
        $user_id = $order->get_user_id();

        if ( ! $user_id ) {
            YSLogger::debug( 'Redirect handler: Cannot sync token for guest user' );
            return;
        }

        // 驗證必要的 payment_instrument_id
        if ( empty( $payment_instrument_id ) ) {
            YSLogger::debug( 'Redirect handler: Empty payment_instrument_id, skipping token sync' );
            return;
        }

        // 信用卡 Token 統一存在同一個 gateway ID 下
        $gateway_id = YSOrderMeta::CREDIT_GATEWAY_ID;

        // 檢查 token 是否已存在
        $all_existing_tokens = array();
        $tokens = WC_Payment_Tokens::get_customer_tokens( $user_id, $gateway_id );

        foreach ( $tokens as $existing_token ) {
            if ( $existing_token->get_token() === $payment_instrument_id ) {
                YSLogger::debug( 'Redirect handler: Token already exists', array(
                    'token_id'              => $existing_token->get_id(),
                    'payment_instrument_id' => $payment_instrument_id,
                ) );
                return;
            }
            $all_existing_tokens[] = $existing_token;
        }

        // 取得卡片資訊（支援多種 API 回傳格式）
        // SHOPLINE API 可能使用不同的欄位名稱
        $card_type    = strtolower( $card_info['brand'] ?? $card_info['cardBrand'] ?? 'visa' );
        $last4        = $card_info['last4'] ?? $card_info['last'] ?? $card_info['cardLast4'] ?? '0000';
        $expiry_month = $card_info['expireMonth'] ?? $card_info['expiryMonth'] ?? $card_info['expMonth'] ?? '';
        $expiry_year  = $card_info['expireYear'] ?? $card_info['expiryYear'] ?? $card_info['expYear'] ?? '';

        // 如果 creditCard 回應沒有到期日，查詢 paymentInstrument API 取得完整資訊
        if ( empty( $expiry_month ) || empty( $expiry_year ) ) {
            YSLogger::debug( 'Redirect handler: creditCard missing expiry, fetching from paymentInstrument API', array(
                'payment_instrument_id' => $payment_instrument_id,
                'payment_customer_id'   => $payment_customer_id,
            ) );

            $instrument_card = self::fetch_instrument_card_info( $payment_customer_id, $payment_instrument_id );
            if ( ! empty( $instrument_card ) ) {
                // 從 paymentInstrument API 取得到期日
                $expiry_month = $instrument_card['expireMonth'] ?? $instrument_card['expiryMonth'] ?? $expiry_month;
                $expiry_year  = $instrument_card['expireYear'] ?? $instrument_card['expiryYear'] ?? $expiry_year;
                // 如果 last4 也是空的，補上
                if ( empty( $last4 ) || '0000' === $last4 ) {
                    $last4 = $instrument_card['last'] ?? $instrument_card['last4'] ?? $last4;
                }
            }
        }

        // 確保 expiry 欄位有有效值（WooCommerce 必須有這些欄位）
        if ( empty( $expiry_month ) || ! is_numeric( $expiry_month ) ) {
            $expiry_month = '12';
        }
        if ( empty( $expiry_year ) || ! is_numeric( $expiry_year ) ) {
            $expiry_year = gmdate( 'Y' );
        }
        // 處理兩位數年份（如 "30" -> "2030"）
        if ( strlen( (string) $expiry_year ) === 2 ) {
            $expiry_year = '20' . $expiry_year;
        }
        if ( empty( $last4 ) ) {
            $last4 = '0000';
        }

        YSLogger::debug( 'Redirect handler: Creating payment token', array(
            'payment_instrument_id' => $payment_instrument_id,
            'card_type'             => $card_type,
            'last4'                 => $last4,
            'expiry_month'          => $expiry_month,
            'expiry_year'           => $expiry_year,
            'raw_card_info'         => $card_info,
        ) );

        // 建立新 token
        $token = new WC_Payment_Token_CC();
        $token->set_token( $payment_instrument_id );
        $token->set_gateway_id( $gateway_id );
        $token->set_card_type( $card_type );
        $token->set_last4( $last4 );
        $token->set_expiry_month( $expiry_month );
        $token->set_expiry_year( $expiry_year );
        $token->set_user_id( $user_id );

        // 如果是第一張卡，設為預設
        if ( empty( $all_existing_tokens ) ) {
            $token->set_default( true );
        }

        try {
            $saved = $token->save();

            if ( $saved ) {
                YSLogger::info( 'Redirect handler: Payment token synced', array(
                    'user_id'               => $user_id,
                    'token_id'              => $token->get_id(),
                    'gateway_id'            => $gateway_id,
                    'payment_instrument_id' => $payment_instrument_id,
                    'card_last4'            => $last4,
                ) );

                // 同時儲存 SHOPLINE customer ID 到用戶 meta（如果還沒有）
                if ( $payment_customer_id ) {
                    $existing_customer_id = get_user_meta( $user_id, YSOrderMeta::CUSTOMER_ID, true );
                    if ( empty( $existing_customer_id ) ) {
                        update_user_meta( $user_id, YSOrderMeta::CUSTOMER_ID, $payment_customer_id );
                    }
                }

            } else {
                YSLogger::error( 'Redirect handler: Failed to save payment token (save returned false)', array(
                    'user_id'               => $user_id,
                    'payment_instrument_id' => $payment_instrument_id,
                ) );
            }
        } catch ( Exception $e ) {
            YSLogger::error( 'Redirect handler: Exception when saving payment token', array(
                'user_id'               => $user_id,
                'payment_instrument_id' => $payment_instrument_id,
                'error'                 => $e->getMessage(),
            ) );
        }
    }

    /**
     * Fetch instrument card info from API.
     *
     * 當 creditCard 回應沒有到期日時，透過 paymentInstrument/query API 取得完整卡片資訊。
     *
     * @param string $customer_id            SHOPLINE customer ID.
     * @param string $payment_instrument_id  Payment instrument ID to find.
     * @return array|null instrumentCard data or null if not found.
     */
    private static function fetch_instrument_card_info( $customer_id, $payment_instrument_id ) {
        if ( empty( $customer_id ) || empty( $payment_instrument_id ) ) {
            return null;
        }

        $api = \YSShoplinePayment::get_api();
        if ( ! $api ) {
            return null;
        }

        // 查詢該客戶的所有付款工具
        $response = $api->get_payment_instruments( $customer_id );

        if ( is_wp_error( $response ) ) {
            YSLogger::warning( 'Redirect handler: Failed to fetch payment instruments', array(
                'customer_id' => $customer_id,
                'error'       => $response->get_error_message(),
            ) );
            return null;
        }

        $instruments = $response['paymentInstruments'] ?? array();

        // 找到對應的 instrument
        foreach ( $instruments as $instrument ) {
            $inst_id = $instrument['instrumentId'] ?? $instrument['paymentInstrumentId'] ?? '';
            if ( $inst_id === $payment_instrument_id ) {
                YSLogger::debug( 'Redirect handler: Found instrument card info from API', array(
                    'payment_instrument_id' => $payment_instrument_id,
                    'instrument_card'       => $instrument['instrumentCard'] ?? array(),
                ) );
                return $instrument['instrumentCard'] ?? null;
            }
        }

        YSLogger::debug( 'Redirect handler: Instrument not found in API response', array(
            'payment_instrument_id' => $payment_instrument_id,
            'instruments_count'     => count( $instruments ),
        ) );

        return null;
    }
}
