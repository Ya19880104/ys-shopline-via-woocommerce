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
use YangSheep\ShoplinePayment\Utils\YSTradeStatus;
use YangSheep\ShoplinePayment\DTOs\YSPaymentDTO;
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

        // 驗證 order key（防止未授權請求觸發 API 查詢）
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $order_key = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
        if ( ! $order_key || $order->get_order_key() !== $order_key ) {
            YSLogger::warning( 'Redirect handler: Invalid order key', array(
                'order_id' => $order_id,
            ) );
            return;
        }

        // 檢查是否是 Shopline 付款方式
        $payment_method = $order->get_payment_method();
        if ( strpos( $payment_method, 'ys_shopline' ) !== 0 ) {
            return;
        }

        // 如果訂單已付款，不需要處理
        if ( self::has_paid_history( $order ) ) {
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
            YSPaymentConfirmation::enter_from_order(
                $order,
                'QUERY_ERROR',
                array(),
                'redirect_query_error',
                array( 'trade_order_id' => (string) $trade_order_id )
            );
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

        $status     = isset( $response['status'] ) ? strtoupper( (string) $response['status'] ) : '';
        // v3.5.11: SHOPLINE 中間態須讀 subStatus（hitrust 3DS 後常見：PROCESSING + AUTHORIZED）
        $sub_status = isset( $response['subStatus'] ) ? $response['subStatus'] : '';

        if ( self::is_transient_payment_status( $status ) ) {
            $response   = self::poll_processing_status( $api, $trade_order_id, $response );
            $status     = isset( $response['status'] ) ? strtoupper( (string) $response['status'] ) : $status;
            // v3.5.11: polling 後 sub_status 也要重新取（hitrust 3DS 中間態判斷需要）
            $sub_status = isset( $response['subStatus'] ) ? $response['subStatus'] : $sub_status;
        }

        if ( YSPaymentConfirmation::STATUS_KEY === $order->get_status() ) {
            try {
                $dto = YSPaymentDTO::from_response( $response );
                YSPaymentConfirmation::handle_query_result( $order, $dto );
            } catch ( \Throwable $e ) {
                YSPaymentConfirmation::enter_from_order(
                    $order,
                    '' !== $status ? $status : 'UNKNOWN',
                    $response,
                    'redirect_malformed_query'
                );
            }
            self::clear_next_action( $order );
            return;
        }

        // 根據狀態更新訂單
        // 注意：SHOPLINE API 回傳 'SUCCEEDED' 而不是 'SUCCESS'
        if ( 'SUCCEEDED' === $status || 'SUCCESS' === $status || 'CAPTURED' === $status ) {
            // 付款成功
            if ( ! self::has_paid_history( $order ) ) {
                $completion = YSPaymentConfirmation::complete_payment_once( $order, (string) $trade_order_id );
                if ( ! empty( $completion['busy'] ) || ! ( $completion['order'] instanceof \WC_Order ) ) {
                    YSLogger::warning( 'Redirect handler: Payment completion lock busy', array(
                        'order_id' => $order->get_id(),
                    ) );
                    return;
                }
                if ( empty( $completion['completed'] ) ) {
                    YSLogger::info( 'Redirect handler: Payment was completed by another converger', array(
                        'order_id' => $order->get_id(),
                    ) );
                    return;
                }

                $fresh = $completion['order'];
                // 套用商家自訂的付款成功訂單狀態
                $custom_paid_status = get_option( 'ys_shopline_order_status_paid', '' );
                if ( $custom_paid_status && $custom_paid_status !== $fresh->get_status() ) {
                    $fresh->update_status( $custom_paid_status, __( '依商家設定更新訂單狀態。', 'ys-shopline-via-woocommerce' ) );
                }
                $fresh->add_order_note(
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
                    $fresh->update_meta_data( YSOrderMeta::PAYMENT_METHOD, $payment_method );
                }
                if ( ! empty( $credit_card ) ) {
                    $fresh->update_meta_data( YSOrderMeta::CARD_LAST4, $credit_card['last4'] ?? $credit_card['last'] ?? '' );
                    $fresh->update_meta_data( YSOrderMeta::CARD_BRAND, $credit_card['brand'] ?? '' );
                }

                // 同步付款工具到 WooCommerce Payment Tokens
                if ( ! empty( $payment_instrument_id ) ) {
                    self::sync_payment_token(
                        $fresh,
                        $payment_instrument_id,
                        $credit_card,
                        $payment_customer_id
                    );

                    // 如果此訂單有關聯 subscription，將 instrument_id 寫入 subscription meta
                    // 放在 sync_payment_token 外面，因為不管 token 是新建還是已存在都要執行
                    self::update_subscription_instrument( $fresh, $payment_instrument_id );
                } else {
                    self::maybe_note_bind_card_not_persisted( $fresh, $payment_instrument, $response );
                }

                // 儲存完整的付款詳情
                $fresh->update_meta_data( YSOrderMeta::PAYMENT_DETAIL, YSOrderMeta::sanitize_payment_detail( array(
                    'paymentMethod'       => $payment_method,
                    'paymentInstrument'   => $payment_instrument,
                    'creditCard'          => $credit_card,
                    'paymentCustomerId'   => $payment_customer_id,
                ) ) );

                $fresh->update_meta_data( YSOrderMeta::PAYMENT_STATUS, $status );
                $fresh->save();
                $order = $fresh;

                YSLogger::info( 'Redirect handler: Order completed', array(
                    'order_id' => $fresh->get_id(),
                    'status'   => $status,
                ) );
            }
        } elseif ( YSTradeStatus::is_in_flight( $status ) && 'ys_shopline_atm' !== $order->get_payment_method() ) {
            YSPaymentConfirmation::enter_from_order(
                $order,
                $status,
                $response,
                'redirect_query',
                array(
                    'reason' => ( 'AUTHORIZED' === $status
                        || ( 'PROCESSING' === $status && in_array( strtoupper( (string) $sub_status ), array( 'AUTHORIZED', 'CAPTURING' ), true ) ) )
                        ? 'authorized'
                        : null,
                )
            );
        } elseif ( YSTradeStatus::is_customer_pending( $status ) && 'ys_shopline_atm' !== $order->get_payment_method() ) {
            // The customer has already returned from an exact wallet/card trade,
            // but SHOPLINE's query result may lag behind the paid webhook. Keep
            // the order locked in the neutral confirmation state instead of
            // exposing WooCommerce's ordinary pending/re-payment UI.
            YSPaymentConfirmation::enter_from_order(
                $order,
                $status,
                $response,
                'redirect_customer_pending'
            );
        } elseif ( YSTradeStatus::is_terminal_safe( $status ) ) {
            if ( method_exists( $order, 'get_date_paid' ) && $order->get_date_paid() ) {
                $order->add_order_note(
                    sprintf(
                        /* translators: %s: late terminal payment status */
                        __( 'SHOPLINE 回傳晚到的終態 %s；此訂單已有付款日期，系統未重新開放付款，請人工核對。', 'ys-shopline-via-woocommerce' ),
                        $status
                    )
                );
                $order->save();
                return;
            }
            // 付款失敗：保留原始訊息供管理員除錯，顯示友善訊息給客戶
            $raw_msg      = isset( $response['paymentMsg']['msg'] ) ? $response['paymentMsg']['msg'] : '';
            $friendly_msg = self::humanize_error_message( $raw_msg );

            $order->update_status( 'failed', $raw_msg ?: __( '付款失敗', 'ys-shopline-via-woocommerce' ) );
            $order->update_meta_data( YSOrderMeta::PAYMENT_STATUS, $status );
            $order->update_meta_data( YSOrderMeta::ERROR_CODE, $response['paymentMsg']['code'] ?? '' );
            $order->update_meta_data( YSOrderMeta::ERROR_MESSAGE, $friendly_msg );
            $order->save();

            YSLogger::info( 'Redirect handler: Payment failed, redirecting to pay-for-order', array(
                'order_id' => $order->get_id(),
                'raw_msg'  => $raw_msg,
                'friendly' => $friendly_msg,
            ) );

            // 失敗時不讓使用者停留在感謝頁（會顯示「訂單失敗」但無重試入口）
            // 改跳 WC pay-for-order 頁，讓客戶能立即重選付款方式重試同一張訂單
            wc_add_notice( $friendly_msg, 'error' );
            wp_safe_redirect( $order->get_checkout_payment_url() );
            exit;
        } elseif ( ! YSTradeStatus::is_customer_pending( $status ) && 'ys_shopline_atm' !== $order->get_payment_method() ) {
            // Unknown future states remain locked until a strict scheduled query
            // or exact webhook proves paid or terminal.
            YSPaymentConfirmation::enter_from_order(
                $order,
                '' !== $status ? $status : 'UNKNOWN',
                $response,
                'redirect_unknown'
            );
        }
        // ATM and any deliberately excluded state continue through their existing display flow.

        // 補抓 ATM 虛擬帳號資訊
        if ( 'ys_shopline_atm' === $order->get_payment_method() && ! $order->get_meta( YSOrderMeta::VA_ACCOUNT ) ) {
            // 嘗試多種路徑取得 VA 資料（API 回傳結構可能不同）
            $va_data = $response['payment']['virtualAccount']
                ?? $response['virtualAccount']
                ?? null;

            if ( $va_data && ! empty( $va_data['recipientAccountNum'] ) ) {
                $bank_code = $va_data['recipientBankCode'] ?? '';
                $account   = $va_data['recipientAccountNum'] ?? '';
                $expire    = $va_data['dueDate'] ?? '';

                $order->update_meta_data( YSOrderMeta::VA_BANK_CODE, $bank_code );
                $order->update_meta_data( YSOrderMeta::VA_ACCOUNT, $account );
                $order->update_meta_data( YSOrderMeta::VA_EXPIRE, $expire );

                // 寫入訂單備註
                $note_parts = array();
                if ( $bank_code ) {
                    $note_parts[] = sprintf( __( '銀行代碼：%s', 'ys-shopline-via-woocommerce' ), $bank_code );
                }
                $note_parts[] = sprintf( __( '虛擬帳號：%s', 'ys-shopline-via-woocommerce' ), $account );
                $note_parts[] = sprintf( __( '轉帳金額：%s', 'ys-shopline-via-woocommerce' ), $order->get_formatted_order_total() );
                if ( $expire ) {
                    $note_parts[] = sprintf( __( '繳費期限：%s', 'ys-shopline-via-woocommerce' ), $expire );
                }
                $order->add_order_note(
                    __( 'ATM 虛擬帳號已產生', 'ys-shopline-via-woocommerce' ) . "\n" . implode( "\n", $note_parts )
                );

                $order->save();

                YSLogger::info( 'Redirect handler: ATM VA info stored from query', array(
                    'order_id'  => $order->get_id(),
                    'bank_code' => $bank_code,
                    'account'   => $account ? substr( $account, 0, 4 ) . '****' : '',
                ) );
            } else {
                YSLogger::warning( 'Redirect handler: ATM order but no VA data in API response', array(
                    'order_id'      => $order->get_id(),
                    'response_keys' => array_keys( $response ),
                    'payment_keys'  => isset( $response['payment'] ) ? array_keys( $response['payment'] ) : [],
                    'status'        => $status,
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
     * Check whether a payment status is still being finalized by SHOPLINE.
     *
     * @param string $status Payment status.
     * @return bool
     */
    private static function is_transient_payment_status( $status ) {
        return in_array( $status, array( 'CREATED', 'PROCESSING' ), true );
    }

    /**
     * Re-query transient payment statuses before rendering the return page.
     *
     * v3.5.11 短期 polling（codex 加）：3 次×2 秒 = max 6 秒。
     * polling 後仍 PROCESSING → 由 check_and_update_order 走 PROCESSING+AUTHORIZED 分支
     * 設 on-hold + 綠章「已授權待 capture」並等 webhook 完成最終 capture（fallback 機制）。
     *
     * @param object $api            SHOPLINE API client.
     * @param string $trade_order_id Trade order ID.
     * @param array  $response       Initial response.
     * @return array
     */
    private static function poll_processing_status( $api, $trade_order_id, $response ) {
        $latest = is_array( $response ) ? $response : array();

        for ( $attempt = 1; $attempt <= 3; $attempt++ ) {
            sleep( 2 );

            $polled = $api->get_payment_trade( $trade_order_id );
            if ( is_wp_error( $polled ) || ! is_array( $polled ) ) {
                YSLogger::warning( 'Redirect handler: transient status poll failed', array(
                    'attempt' => $attempt,
                    'error'   => is_wp_error( $polled ) ? $polled->get_error_message() : 'invalid_response',
                ) );
                continue;
            }

            $latest = $polled;
            $status = isset( $latest['status'] ) ? $latest['status'] : '';

            YSLogger::debug( 'Redirect handler: transient status poll result', array(
                'attempt' => $attempt,
                'status'  => $status ?: 'unknown',
            ) );

            if ( ! self::is_transient_payment_status( $status ) ) {
                break;
            }
        }

        return $latest;
    }

    /**
     * Add an admin-facing warning when SHOPLINE does not persist a requested new card.
     *
     * v3.5.11 codex 重構：抽 method + is_bool/is_scalar 防衛 + 冪等保護。
     * v3.5.10 內聯邏輯改用此 method。
     *
     * @param \WC_Order $order              Order object.
     * @param array     $payment_instrument SHOPLINE paymentInstrument response.
     * @param array     $response           Full SHOPLINE response.
     * @return bool True when the missing-instrument warning applies.
     */
    public static function maybe_note_bind_card_not_persisted( $order, $payment_instrument, array $response ) {
        if ( 'yes' !== $order->get_meta( YSOrderMeta::BIND_CARD_ATTEMPTED ) ) {
            return false;
        }

        if ( ! is_array( $payment_instrument ) ) {
            $payment_instrument = array();
        }

        $payment_instrument_id = $payment_instrument['paymentInstrumentId']
            ?? $payment_instrument['instrumentId']
            ?? '';

        if ( '' !== (string) $payment_instrument_id ) {
            return false;
        }

        $shopline_save_flag = $payment_instrument['savePaymentInstrument'] ?? null;
        if ( null === $shopline_save_flag ) {
            $flag_str = 'unset';
        } elseif ( is_bool( $shopline_save_flag ) ) {
            $flag_str = $shopline_save_flag ? 'true' : 'false';
        } elseif ( is_scalar( $shopline_save_flag ) ) {
            $flag_str = (string) $shopline_save_flag;
        } else {
            $flag_str = 'non-scalar';
        }

        if ( 'yes' !== $order->get_meta( YSOrderMeta::BIND_CARD_NOT_PERSISTED ) ) {
            $order->add_order_note( sprintf(
                /* translators: %s: SHOPLINE response 的 savePaymentInstrument 旗標值 */
                __(
                    'Shopline 金流：使用者勾選了「儲存卡片」，但 SHOPLINE did not create a saved card / 未建立 paymentInstrument（response savePaymentInstrument=%s）。本訂單付款已完成，但此次卡片未被儲存，下次仍需重新輸入卡片。這不是 WooCommerce token 儲存錯誤；請使用本訂單 tradeOrderId 請 SHOPLINE 查詢為何未建立 paymentInstrument（常見方向：卡片或銀行不支援綁定、商家帳號綁卡權限、SHOPLINE 端風控或交易規則）。',
                    'ys-shopline-via-woocommerce'
                ),
                $flag_str
            ) );
        }

        $order->update_meta_data( YSOrderMeta::BIND_CARD_NOT_PERSISTED, 'yes' );

        YSLogger::warning( 'CardBindPayment requested but SHOPLINE did not return paymentInstrumentId', array(
            'order_id'                  => $order->get_id(),
            'shopline_save_flag'        => $flag_str,
            'shopline_payment_behavior' => $response['payment']['paymentBehavior'] ?? $response['paymentBehavior'] ?? 'unknown',
        ) );

        return true;
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

        // v3.5.1: 套用共用 normalizer（YSCustomer::normalize_card_payload）
        // 為保留向後相容（SHOPLINE 多種欄位命名：last4 / cardLast4 / expiryMonth 等），
        // 先把欄位統一成 normalizer 認得的 {brand, last, expireMonth, expireYear}，再呼叫
        $normalized_input = array(
            'brand'       => $card_info['brand'] ?? $card_info['cardBrand'] ?? 'visa',
            'last'        => $card_info['last'] ?? $card_info['last4'] ?? $card_info['cardLast4'] ?? '',
            'expireMonth' => $card_info['expireMonth'] ?? $card_info['expiryMonth'] ?? $card_info['expMonth'] ?? '',
            'expireYear'  => $card_info['expireYear'] ?? $card_info['expiryYear'] ?? $card_info['expYear'] ?? '',
        );
        $normalized = \YangSheep\ShoplinePayment\Customer\YSCustomer::normalize_card_payload( $normalized_input );

        // 若 creditCard 回應欄位不足，查 paymentInstrument API 補齊再 normalize
        if ( ! $normalized ) {
            YSLogger::debug( 'Redirect handler: creditCard missing fields, fetching from paymentInstrument API', array(
                'payment_instrument_id' => $payment_instrument_id,
                'payment_customer_id'   => $payment_customer_id,
            ) );
            $instrument_card = self::fetch_instrument_card_info( $payment_customer_id, $payment_instrument_id );
            if ( is_array( $instrument_card ) ) {
                $normalized = \YangSheep\ShoplinePayment\Customer\YSCustomer::normalize_card_payload( $instrument_card );
            }
        }

        // v3.5.2: Codex F4 — 不再建立 placeholder token
        // 若卡片資料取不齊，skip token creation 避免 My Account 出現 Visa/0000 誤導性 saved card
        // 訂單交易結果仍會由 redirect/webhook 寫入 order meta，不影響付款成功
        if ( ! $normalized ) {
            YSLogger::warning( 'Redirect handler: card info could not be normalized, skipping token creation', array(
                'payment_instrument_id' => $payment_instrument_id,
                'payment_customer_id'   => $payment_customer_id,
            ) );
            return;
        }

        $card_type    = $normalized['brand'];
        $last4        = $normalized['last4'];
        $expiry_month = $normalized['expiry_month'];
        $expiry_year  = $normalized['expiry_year'];

        YSLogger::debug( 'Redirect handler: Creating payment token', array(
            'payment_instrument_id' => $payment_instrument_id,
            'card_type'             => $card_type,
            'last4'                 => $last4,
            'expiry_month'          => $expiry_month,
            'expiry_year'           => $expiry_year,
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
        } catch ( \Throwable $e ) {
            // v3.5.1: 擴展到 \Throwable 以捕捉 TypeError / ValueError（Codex review 建議）
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

    /**
     * A paid date remains authoritative after custom order-status changes.
     */
    private static function has_paid_history( \WC_Order $order ): bool {
        return $order->is_paid()
            || ( method_exists( $order, 'get_date_paid' ) && (bool) $order->get_date_paid() );
    }

    /**
     * 將 Shopline API 錯誤訊息轉為使用者友善的中文提示。
     *
     * @param string $raw_msg Shopline API 原始錯誤訊息。
     * @return string 友善的中文錯誤訊息。
     */
    public static function humanize_error_message( $raw_msg ) {
        $msg = strtolower( $raw_msg );

        if ( strpos( $msg, 'instrument' ) !== false && strpos( $msg, 'invalid' ) !== false ) {
            return __( '您選擇的信用卡已失效，請重新綁定卡片或使用其他卡片付款。', 'ys-shopline-via-woocommerce' );
        }

        if ( strpos( $msg, 'insufficient' ) !== false || strpos( $msg, 'balance' ) !== false ) {
            return __( '卡片餘額不足，請確認額度後重試或使用其他卡片。', 'ys-shopline-via-woocommerce' );
        }

        if ( strpos( $msg, 'expired' ) !== false ) {
            return __( '卡片已過期，請使用其他卡片付款。', 'ys-shopline-via-woocommerce' );
        }

        if ( strpos( $msg, 'declined' ) !== false || strpos( $msg, 'reject' ) !== false ) {
            return __( '交易被發卡銀行拒絕，請聯繫發卡銀行或使用其他卡片。', 'ys-shopline-via-woocommerce' );
        }

        if ( strpos( $msg, '3ds' ) !== false || strpos( $msg, 'authentication' ) !== false ) {
            return __( '3D 驗證失敗，請重試並完成銀行驗證步驟。', 'ys-shopline-via-woocommerce' );
        }

        if ( strpos( $msg, 'duplicate' ) !== false ) {
            return __( '此筆交易已處理過，請勿重複提交。', 'ys-shopline-via-woocommerce' );
        }

        if ( strpos( $msg, 'invalid store url' ) !== false ) {
            return __( '商店網域驗證失敗，此付款方式目前無法使用，請選擇其他付款方式或聯繫商店客服。', 'ys-shopline-via-woocommerce' );
        }

        // 無法辨識的錯誤，回傳通用提示
        return __( '付款失敗，請重試或使用其他付款方式。', 'ys-shopline-via-woocommerce' );
    }
}
