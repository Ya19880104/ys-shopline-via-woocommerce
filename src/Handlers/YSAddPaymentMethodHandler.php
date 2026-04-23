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

			YSLogger::info( 'BindCard token saved', array(
				'trade_order_id' => $trade_order_id,
				'instrument_id'  => '*' . substr( (string) $instrument_id, -6 ),
				'last4'          => $last4,
				'brand'          => is_array( $credit_card ) && isset( $credit_card['brand'] ) ? (string) $credit_card['brand'] : '',
			) );

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
			// 綁卡失敗 — 使用與結帳失敗一致的友善錯誤訊息
			$raw_msg      = $response['paymentMsg']['msg'] ?? __( '綁卡失敗', 'ys-shopline-via-woocommerce' );
			$friendly_msg = \YangSheep\ShoplinePayment\Handlers\YSRedirectHandler::humanize_error_message( $raw_msg );

			YSLogger::error( 'BindCard failed', array(
				'user_id'        => $user_id,
				'trade_order_id' => $trade_order_id,
				'raw_msg'        => $raw_msg,
				'friendly'       => $friendly_msg,
			) );

			self::clear_pending_data( $user_id );
			wc_add_notice( __( '新增付款方式失敗：', 'ys-shopline-via-woocommerce' ) . $friendly_msg, 'error' );

		} else {
			// 其他狀態（PROCESSING, CREATED 等）- 可能尚未完成
			YSLogger::debug( 'Add payment method: Unexpected status', array(
				'status' => $status,
			) );
			wc_add_notice( __( '綁卡正在處理中，請稍後查看。', 'ys-shopline-via-woocommerce' ), 'notice' );
		}
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
