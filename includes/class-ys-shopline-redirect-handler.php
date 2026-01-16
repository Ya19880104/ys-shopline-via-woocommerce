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
 * @package YS_Shopline_Payment
 */

defined( 'ABSPATH' ) || exit;

/**
 * YS_Shopline_Redirect_Handler Class.
 */
class YS_Shopline_Redirect_Handler {

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
            YS_Shopline_Logger::debug( 'Redirect handler: Order already paid', array(
                'order_id' => $order_id,
            ) );
            // 清除 next_action
            self::clear_next_action( $order );
            return;
        }

        // 取得 trade order ID
        $trade_order_id = $order->get_meta( '_ys_shopline_trade_order_id' );

        if ( ! $trade_order_id ) {
            YS_Shopline_Logger::warning( 'Redirect handler: No trade_order_id', array(
                'order_id' => $order_id,
            ) );
            return;
        }

        YS_Shopline_Logger::info( 'Redirect handler: Checking payment status', array(
            'order_id'       => $order_id,
            'trade_order_id' => $trade_order_id,
        ) );

        // 查詢 API 確認付款狀態
        self::check_and_update_order( $order, $trade_order_id );
    }

    /**
     * Check payment status and update order.
     *
     * @param WC_Order $order          Order object.
     * @param string   $trade_order_id Shopline trade order ID.
     */
    private static function check_and_update_order( $order, $trade_order_id ) {
        $api = YS_Shopline_Payment::get_api();

        if ( ! $api ) {
            YS_Shopline_Logger::error( 'Redirect handler: API not available' );
            return;
        }

        // 查詢訂單狀態
        $response = $api->get_payment_trade( $trade_order_id );

        if ( is_wp_error( $response ) ) {
            YS_Shopline_Logger::error( 'Redirect handler: API query failed', array(
                'error' => $response->get_error_message(),
            ) );
            return;
        }

        // 記錄完整的 API 回應結構以便除錯
        YS_Shopline_Logger::debug( 'Redirect handler: Full API response', array(
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

                YS_Shopline_Logger::debug( 'Redirect handler: Extracted payment info', array(
                    'payment_method'        => $payment_method,
                    'payment_customer_id'   => $payment_customer_id,
                    'payment_instrument_id' => $payment_instrument_id,
                    'credit_card'           => $credit_card,
                    'raw_instrument'        => $payment_instrument,
                ) );

                if ( $payment_method ) {
                    $order->update_meta_data( '_ys_shopline_payment_method', $payment_method );
                }
                if ( ! empty( $credit_card ) ) {
                    $order->update_meta_data( '_ys_shopline_card_last4', $credit_card['last4'] ?? $credit_card['last'] ?? '' );
                    $order->update_meta_data( '_ys_shopline_card_brand', $credit_card['brand'] ?? '' );
                }

                // 同步付款工具到 WooCommerce Payment Tokens
                if ( ! empty( $payment_instrument_id ) ) {
                    self::sync_payment_token(
                        $order,
                        $payment_instrument_id,
                        $credit_card,
                        $payment_customer_id
                    );
                }

                // 儲存完整的付款詳情
                $order->update_meta_data( '_ys_shopline_payment_detail', array(
                    'paymentMethod'       => $payment_method,
                    'paymentInstrument'   => $payment_instrument,
                    'creditCard'          => $credit_card,
                    'paymentCustomerId'   => $payment_customer_id,
                ) );

                $order->update_meta_data( '_ys_shopline_payment_status', $status );
                $order->save();

                YS_Shopline_Logger::info( 'Redirect handler: Order completed', array(
                    'order_id' => $order->get_id(),
                    'status'   => $status,
                ) );
            }
        } elseif ( 'AUTHORIZED' === $status ) {
            // 已授權但未請款（手動請款模式）
            $order->update_status( 'on-hold', __( 'SHOPLINE 付款已授權，等待請款。', 'ys-shopline-via-woocommerce' ) );
            $order->update_meta_data( '_ys_shopline_payment_status', 'AUTHORIZED' );
            $order->save();
        } elseif ( 'FAILED' === $status ) {
            // 付款失敗
            $error_msg = isset( $response['paymentMsg']['msg'] ) ? $response['paymentMsg']['msg'] : __( '付款失敗', 'ys-shopline-via-woocommerce' );
            $order->update_status( 'failed', $error_msg );
            $order->update_meta_data( '_ys_shopline_payment_status', 'FAILED' );
            $order->save();
        }
        // 其他狀態（CREATED, PROCESSING）暫不處理，等待 Webhook 或用戶重試

        // 清除 next_action
        self::clear_next_action( $order );
    }

    /**
     * Clear next_action meta data.
     *
     * @param WC_Order $order Order object.
     */
    private static function clear_next_action( $order ) {
        $next_action = $order->get_meta( '_ys_shopline_next_action' );
        if ( $next_action ) {
            $order->delete_meta_data( '_ys_shopline_next_action' );
            $order->save();
        }
    }

    /**
     * Sync payment token to WooCommerce.
     *
     * 當用戶儲存卡片後，將 SHOPLINE 的 paymentInstrumentId 同步到 WooCommerce Payment Tokens，
     * 這樣結帳頁面就能顯示已儲存的卡片。
     *
     * @param WC_Order $order                 Order object.
     * @param string   $payment_instrument_id SHOPLINE payment instrument ID.
     * @param array    $card_info             Credit card information.
     * @param string   $payment_customer_id   SHOPLINE customer ID.
     */
    private static function sync_payment_token( $order, $payment_instrument_id, $card_info, $payment_customer_id ) {
        $user_id = $order->get_user_id();

        if ( ! $user_id ) {
            YS_Shopline_Logger::debug( 'Redirect handler: Cannot sync token for guest user' );
            return;
        }

        // 驗證必要的 payment_instrument_id
        if ( empty( $payment_instrument_id ) ) {
            YS_Shopline_Logger::debug( 'Redirect handler: Empty payment_instrument_id, skipping token sync' );
            return;
        }

        // 決定 gateway ID
        // 優先使用訂單的付款方式，如果是訂閱則使用訂閱專用 gateway
        $gateway_id = $order->get_payment_method();
        if ( empty( $gateway_id ) || strpos( $gateway_id, 'ys_shopline' ) !== 0 ) {
            $gateway_id = 'ys_shopline_credit_card';
        }

        // 檢查 token 是否已存在
        $existing_tokens = WC_Payment_Tokens::get_customer_tokens( $user_id, $gateway_id );

        foreach ( $existing_tokens as $existing_token ) {
            if ( $existing_token->get_token() === $payment_instrument_id ) {
                YS_Shopline_Logger::debug( 'Redirect handler: Token already exists', array(
                    'token_id'              => $existing_token->get_id(),
                    'payment_instrument_id' => $payment_instrument_id,
                ) );
                return;
            }
        }

        // 取得卡片資訊（支援多種 API 回傳格式）
        // SHOPLINE API 可能使用不同的欄位名稱
        $card_type    = strtolower( $card_info['brand'] ?? $card_info['cardBrand'] ?? 'visa' );
        $last4        = $card_info['last4'] ?? $card_info['last'] ?? $card_info['cardLast4'] ?? '0000';
        $expiry_month = $card_info['expireMonth'] ?? $card_info['expiryMonth'] ?? $card_info['expMonth'] ?? '12';
        $expiry_year  = $card_info['expireYear'] ?? $card_info['expiryYear'] ?? $card_info['expYear'] ?? date( 'Y' );

        // 確保 expiry 欄位有有效值（WooCommerce 必須有這些欄位）
        if ( empty( $expiry_month ) || ! is_numeric( $expiry_month ) ) {
            $expiry_month = '12';
        }
        if ( empty( $expiry_year ) || ! is_numeric( $expiry_year ) ) {
            $expiry_year = date( 'Y' );
        }
        if ( empty( $last4 ) ) {
            $last4 = '0000';
        }

        YS_Shopline_Logger::debug( 'Redirect handler: Creating payment token', array(
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
        if ( empty( $existing_tokens ) ) {
            $token->set_default( true );
        }

        try {
            $saved = $token->save();

            if ( $saved ) {
                YS_Shopline_Logger::info( 'Redirect handler: Payment token synced', array(
                    'user_id'               => $user_id,
                    'token_id'              => $token->get_id(),
                    'gateway_id'            => $gateway_id,
                    'payment_instrument_id' => $payment_instrument_id,
                    'card_last4'            => $last4,
                ) );

                // 同時儲存 SHOPLINE customer ID 到用戶 meta（如果還沒有）
                if ( $payment_customer_id ) {
                    $existing_customer_id = get_user_meta( $user_id, '_ys_shopline_customer_id', true );
                    if ( empty( $existing_customer_id ) ) {
                        update_user_meta( $user_id, '_ys_shopline_customer_id', $payment_customer_id );
                    }
                }
            } else {
                YS_Shopline_Logger::error( 'Redirect handler: Failed to save payment token (save returned false)', array(
                    'user_id'               => $user_id,
                    'payment_instrument_id' => $payment_instrument_id,
                ) );
            }
        } catch ( Exception $e ) {
            YS_Shopline_Logger::error( 'Redirect handler: Exception when saving payment token', array(
                'user_id'               => $user_id,
                'payment_instrument_id' => $payment_instrument_id,
                'error'                 => $e->getMessage(),
            ) );
        }
    }
}

// Initialize
YS_Shopline_Redirect_Handler::init();
