<?php
/**
 * Add Payment Method Handler for YS Shopline Payment.
 *
 * 處理 /my-account/add-payment-method/ 頁面的 3DS 回調和 Token 儲存。
 *
 * 流程：
 * 1. 用戶在 add-payment-method 頁面填寫卡片資訊
 * 2. SDK createPayment() → 後端 add_payment_method() → API CardBind
 * 3. API 返回 nextAction → 前端 SDK payment.pay(nextAction) → 3DS 驗證
 * 4. 3DS 完成後跳轉到 returnUrl（帶 ys_shopline_add_method 參數）
 * 5. 本 Handler 攔截 returnUrl，查詢 API 確認綁卡結果
 * 6. 成功則同步 Token 到 WooCommerce
 *
 * @package YangSheep\ShoplinePayment\Handlers
 */

namespace YangSheep\ShoplinePayment\Handlers;

defined( 'ABSPATH' ) || exit;

use YangSheep\ShoplinePayment\Utils\YSLogger;
use YangSheep\ShoplinePayment\Utils\YSBindCardLogger;
use YangSheep\ShoplinePayment\Utils\YSOrderMeta;
use YangSheep\ShoplinePayment\Customer\YSCustomer;
use WC_Payment_Tokens;
use WC_Payment_Token_CC;
use Exception;

/**
 * YSAddPaymentMethodHandler Class.
 */
class YSAddPaymentMethodHandler {

	/**
	 * Initialize the handler.
	 */
	public static function init() {
		// 處理 3DS 完成後的回調（SDK 跳至 returnUrl 後觸發，建立 WC Token）
		add_action( 'template_redirect', array( __CLASS__, 'handle_add_method_redirect' ), 5 );

		// v3.4.7 起綁卡改走 AJAX 模式（原 SDK 實例直接 pay(nextAction)），
		// 獨立 3DS 頁因跨頁 PCI session 丟失而不可行，已移除 handle_3ds_page / render_3ds_page。
	}

	/**
	 * Handle redirect from 3DS verification.
	 *
	 * 當 3DS 驗證完成後，SDK 會跳轉到 returnUrl。
	 * 我們在這裡攔截，查詢 API 確認綁卡結果。
	 */
	public static function handle_add_method_redirect() {
		// 檢查是否是 add_payment_method 的回調
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['ys_shopline_add_method'] ) ) {
			return;
		}

		// 必須在 payment-methods 頁面
		if ( ! is_wc_endpoint_url( 'payment-methods' ) ) {
			return;
		}

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return;
		}

		YSLogger::debug( 'Add payment method redirect handler triggered', array(
			'user_id' => $user_id,
		) );

		// 取得暫存的綁卡資訊
		$pending_bind = get_user_meta( $user_id, YSOrderMeta::PENDING_BIND, true );

		if ( empty( $pending_bind ) ) {
			YSLogger::warning( 'Add payment method redirect: No pending bind data', array(
				'user_id' => $user_id,
			) );
			wc_add_notice( __( '找不到綁卡資訊，請重新新增卡片。', 'ys-shopline-via-woocommerce' ), 'error' );
			return;
		}

		$trade_order_id     = isset( $pending_bind['trade_order_id'] ) ? $pending_bind['trade_order_id'] : '';
		$reference_order_id = isset( $pending_bind['reference_order_id'] ) ? $pending_bind['reference_order_id'] : '';
		$customer_id        = isset( $pending_bind['customer_id'] ) ? $pending_bind['customer_id'] : '';

		if ( empty( $trade_order_id ) ) {
			YSLogger::error( 'Add payment method redirect: No trade_order_id', array(
				'user_id'      => $user_id,
				'pending_bind' => $pending_bind,
			) );
			wc_add_notice( __( '交易資訊遺失，請重新新增卡片。', 'ys-shopline-via-woocommerce' ), 'error' );
			self::clear_pending_data( $user_id );
			return;
		}

		// 查詢 API 確認綁卡結果
		$api = \YSShoplinePayment::get_api();
		if ( ! $api ) {
			YSLogger::error( 'Add payment method redirect: API not available' );
			wc_add_notice( __( '系統錯誤，請稍後重試。', 'ys-shopline-via-woocommerce' ), 'error' );
			return;
		}

		$response = $api->get_payment_trade( $trade_order_id );

		if ( is_wp_error( $response ) ) {
			YSLogger::error( 'Add payment method redirect: API query failed', array(
				'error'          => $response->get_error_message(),
				'trade_order_id' => $trade_order_id,
			) );
			wc_add_notice( __( '查詢綁卡結果失敗，請稍後重試。', 'ys-shopline-via-woocommerce' ), 'error' );
			return;
		}

		YSLogger::debug( 'Add payment method redirect: API response', array(
			'status'      => $response['status'] ?? 'unknown',
			'subStatus'   => $response['subStatus'] ?? 'unknown',
		) );

		$status = isset( $response['status'] ) ? $response['status'] : '';

		// CardBind 的成功狀態可能是 SUCCEEDED 或 SUCCESS
		if ( 'SUCCEEDED' === $status || 'SUCCESS' === $status ) {
			// 綁卡成功，同步 Token
			YSLogger::info( 'Add payment method success', array(
				'user_id'          => $user_id,
				'trade_order_id'   => $trade_order_id,
				'status'           => $status,
			) );

			// 嘗試從回應中提取付款工具資訊
			$payment_instrument = $response['paymentInstrument'] ?? $response['payment']['paymentInstrument'] ?? array();
			$credit_card        = $response['creditCard'] ?? $response['payment']['creditCard'] ?? $payment_instrument['instrumentCard'] ?? array();
			$instrument_id      = $payment_instrument['paymentInstrumentId'] ?? $payment_instrument['instrumentId'] ?? '';

			$last4 = is_array( $credit_card ) && isset( $credit_card['last'] ) ? (string) $credit_card['last'] : '';

			YSBindCardLogger::log(
				YSBindCardLogger::EVENT_TOKEN_SAVED,
				array(
					'flow'           => 'add_payment_method',
					'trade_order_id' => $trade_order_id,
					'instrument_id'  => $instrument_id,
					'customer_id'    => $customer_id,
					'last4'          => $last4,
					'brand'          => is_array( $credit_card ) && isset( $credit_card['brand'] ) ? (string) $credit_card['brand'] : '',
				)
			);

			// 統一使用 sync_tokens_from_api 建 WC Token
			// （結帳流程已驗證穩定；早期 create_token_from_response 自建會遇到 WC_Payment_Token
			// 「Invalid or missing payment token fields」Exception，放棄）
			$customer_manager = YSCustomer::instance();
			$customer_manager->sync_tokens_from_api( $user_id );

			// 清理暫存資料
			self::clear_pending_data( $user_id );

			wc_add_notice( __( '付款方式已成功新增。', 'ys-shopline-via-woocommerce' ), 'success' );

			// 重新導向到 payment-methods 頁面（移除參數）
			wp_safe_redirect( wc_get_account_endpoint_url( 'payment-methods' ) );
			exit;

		} elseif ( 'FAILED' === $status ) {
			// 綁卡失敗
			$error_msg = $response['paymentMsg']['msg'] ?? __( '綁卡失敗', 'ys-shopline-via-woocommerce' );
			YSLogger::error( 'Add payment method failed', array(
				'user_id'        => $user_id,
				'trade_order_id' => $trade_order_id,
				'error'          => $error_msg,
			) );

			YSBindCardLogger::log(
				YSBindCardLogger::EVENT_ERROR,
				array(
					'flow'           => 'add_payment_method',
					'trade_order_id' => $trade_order_id,
					'status'         => $status,
					'error'          => $error_msg,
				)
			);

			self::clear_pending_data( $user_id );
			wc_add_notice( __( '新增付款方式失敗：', 'ys-shopline-via-woocommerce' ) . $error_msg, 'error' );

		} else {
			// 其他狀態（PROCESSING, CREATED 等）- 可能尚未完成
			YSLogger::debug( 'Add payment method: Unexpected status', array(
				'status' => $status,
			) );
			wc_add_notice( __( '綁卡正在處理中，請稍後查看。', 'ys-shopline-via-woocommerce' ), 'notice' );
		}
	}

	/**
	 * Create WC Token from API response.
	 *
	 * @param int    $user_id       WordPress user ID.
	 * @param string $instrument_id Payment instrument ID.
	 * @param array  $card_info     Credit card information.
	 * @param string $customer_id   SHOPLINE customer ID.
	 * @return bool
	 */
	private static function create_token_from_response( $user_id, $instrument_id, $card_info, $customer_id ) {
		if ( empty( $instrument_id ) ) {
			return false;
		}

		// 檢查 token 是否已存在
		$existing_tokens = WC_Payment_Tokens::get_customer_tokens( $user_id, YSOrderMeta::CREDIT_GATEWAY_ID );
		foreach ( $existing_tokens as $existing_token ) {
			if ( $existing_token->get_token() === $instrument_id ) {
				YSLogger::debug( 'Token already exists', array(
					'instrument_id' => $instrument_id,
				) );
				return true;
			}
		}

		// 取得卡片資訊
		$card_type    = strtolower( $card_info['brand'] ?? $card_info['cardBrand'] ?? 'visa' );
		$last4        = $card_info['last4'] ?? $card_info['last'] ?? $card_info['cardLast4'] ?? '0000';
		$expiry_month = $card_info['expireMonth'] ?? $card_info['expiryMonth'] ?? '12';
		$expiry_year  = $card_info['expireYear'] ?? $card_info['expiryYear'] ?? gmdate( 'Y' );

		// 處理兩位數年份
		if ( strlen( (string) $expiry_year ) === 2 ) {
			$expiry_year = '20' . $expiry_year;
		}

		// 如果缺少到期日，從 API 取得
		if ( empty( $expiry_month ) || '12' === $expiry_month || empty( $last4 ) || '0000' === $last4 ) {
			$full_info = self::fetch_instrument_info( $customer_id, $instrument_id );
			if ( ! empty( $full_info ) ) {
				$expiry_month = $full_info['expireMonth'] ?? $full_info['expiryMonth'] ?? $expiry_month;
				$expiry_year  = $full_info['expireYear'] ?? $full_info['expiryYear'] ?? $expiry_year;
				$last4        = $full_info['last'] ?? $full_info['last4'] ?? $last4;
				$card_type    = strtolower( $full_info['brand'] ?? $card_type );
			}
		}

		// 建立新 token
		$token = new WC_Payment_Token_CC();
		$token->set_token( $instrument_id );
		$token->set_gateway_id( YSOrderMeta::CREDIT_GATEWAY_ID );
		$token->set_card_type( $card_type );
		$token->set_last4( $last4 );
		$token->set_expiry_month( $expiry_month );
		$token->set_expiry_year( $expiry_year );
		$token->set_user_id( $user_id );

		// 檢查是否是第一張卡，設為預設
		$all_tokens = WC_Payment_Tokens::get_customer_tokens( $user_id );
		if ( empty( $all_tokens ) ) {
			$token->set_default( true );
		}

		try {
			$saved = $token->save();

			if ( $saved ) {
				YSLogger::info( 'Payment token created', array(
					'user_id'       => $user_id,
					'token_id'      => $token->get_id(),
					'instrument_id' => $instrument_id,
					'card_last4'    => $last4,
				) );
				return true;
			}
		} catch ( Exception $e ) {
			YSLogger::error( 'Failed to save token', array(
				'error' => $e->getMessage(),
			) );
		}

		return false;
	}

	/**
	 * Fetch instrument info from API.
	 *
	 * @param string $customer_id   SHOPLINE customer ID.
	 * @param string $instrument_id Payment instrument ID.
	 * @return array|null
	 */
	private static function fetch_instrument_info( $customer_id, $instrument_id ) {
		if ( empty( $customer_id ) || empty( $instrument_id ) ) {
			return null;
		}

		$api = \YSShoplinePayment::get_api();
		if ( ! $api ) {
			return null;
		}

		$response = $api->get_payment_instruments( $customer_id );

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$instruments = $response['paymentInstruments'] ?? array();

		foreach ( $instruments as $instrument ) {
			$inst_id = $instrument['instrumentId'] ?? $instrument['paymentInstrumentId'] ?? '';
			if ( $inst_id === $instrument_id ) {
				return $instrument['instrumentCard'] ?? null;
			}
		}

		return null;
	}

	/**
	 * Clear pending bind data.
	 *
	 * @param int $user_id WordPress user ID.
	 */
	private static function clear_pending_data( $user_id ) {
		delete_user_meta( $user_id, YSOrderMeta::PENDING_BIND );
		delete_user_meta( $user_id, YSOrderMeta::ADD_METHOD_NEXT_ACTION );
	}
}
