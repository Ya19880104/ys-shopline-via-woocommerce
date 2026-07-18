<?php
/**
 * SHOPLINE API 錯誤分類器（rejected vs unknown）
 *
 * @package YangSheep\ShoplinePayment\Utils
 */

declare(strict_types=1);

namespace YangSheep\ShoplinePayment\Utils;

defined( 'ABSPATH' ) || exit;

/**
 * 分類建立交易時的 API 錯誤：是否「確定未建立交易（rejected）」或「狀態不明（unknown）」。
 *
 * v3.5.36 P0：timeout／空回應／JSON 解析失敗，以及部分業務碼（1001 交易已存在、
 * 4003 通路 timeout、4458 processing、1018 未知原因…）都可能發生在「遠端已建立交易之後」，
 * 若一律當 rejected 放行重試，下一次會用新 referenceOrderId／冪等鍵建立**第二筆**交易＝雙扣。
 *
 * 因此採「明確 rejected allowlist，其餘一律 unknown（fail-closed）」。
 * allowlist 僅收官方錯誤碼中「可確定不會再收款」的建立前驗證／驗簽／權限／設定錯誤，
 * 以及明確付款拒絕／失敗（銀行拒絕、卡片失效、定期付款無法完成）等終止結果
 *（見 https://docs.shoplinepayments.com/appendix/errorCode/）。
 */
final class YSApiError {

	/**
	 * 明確 rejected 的錯誤碼（確定不會形成可收款交易）。其餘一律視為 unknown。
	 *
	 * @var string[]
	 */
	public const REJECTED_CODES = array(
		// 參數／驗證
		'1003', // Param miss
		'1004', // Param error
		'1005', // Check error
		'1025', // Create amount error（超出限額，建立前驗證）
		// 驗簽／權限／認證
		'2001', // APPID does not exist
		'2002', // Signature error
		'2003', // Request URL error
		'2005', // Access denied
		'2006', // Bad request
		'2007', // Not found
		'2009', // Token expired
		'2010', // Token tampered
		'2013', // Merchants are not connected to the platform
		// 商戶設定／權限
		'1009', // Receive forbidden
		'1010', // Merchant refund permission disabled
		'1012', // Merchant payment permission disabled
		// UI／設定／方式與幣別不支援
		'4108', // Failed to pull up the form
		'4109', // Invalid store url
		'4200', // Payment method not activated
		'4201', // Merchant account invalid
		'4202', // Currency not support
		'4203', // Merchant payment permission disabled
		'4204', // Payment method not currently supported
		// 明確付款拒絕／失敗（可安全讓顧客重試，或讓訂閱改用另一張卡）
		'3000', // SLP risk control: payer/card irregularities
		'3001', // SLP risk control: card irregularities
		'3002', // SLP risk control: recipient information
		'3003', // SLP risk control: high-risk transaction
		'3004', // SLP risk control: transaction information
		'4101', // Wrong amount
		'4102', // Order abnormality decline
		'4103', // Unspecified decline
		'4104', // Merchant account decline
		'4105', // Customer account decline
		'4106', // IP whitelist/system decline
		'4107', // Channel currency not supported
		'4350', // Issuing bank rejected
		'4401', // Payment expired
		'4404', // Payment token amount mismatch
		'4405', // Invalid payment token
		'4406', // Invalid public key
		'4407', // Invalid private key
		'4408', // Invalid payment signature
		'4409', // Payment token expired
		'4411', // Channel authentication failed
		'4412', // Channel returned not found
		'4450', // 3DS timeout
		'4451', // Issuing bank rejected
		'4452', // 3DS verification failed
		'4453', // CVC invalid
		'4454', // Insufficient funds
		'4455', // Invalid card number
		'4457', // Issuer high-risk decline
		'4459', // Card expired
		'4460', // PIN attempts exceeded
		'4461', // Amount/credit limit exceeded
		'4462', // PIN invalid
		'4463', // Stolen/lost card
		'4464', // Card blocked
		'4465', // Online PIN required
		'4466', // Restricted card
		'4467', // Transaction count exceeded
		'4468', // Payment/QR code expired
		'4550', // Customer cancelled
		'4552', // Customer account error
		'4600', // Other explicit decline
		// 綁卡／定期付款的明確失敗
		'1200', // Card binding not supported
		'1203', // Card verification failed
		'4801', // Customer rejected binding
		'4802', // Binding parameter error
		'4900', // Recurring requires 3DS and cannot complete
		'4901', // Recurring requires CVC and cannot complete
		'4902', // Saved-card recurring payment failed
		// Connect 系（字串碼）
		'URL_NOT_FOUND',
		'ACCESS_DENIED',
		'MERCHANT_NOT_EXISTS',
		'UNAUTHORIZED_CLIENT',
		'KEY_INCORRECT',
		'INVALID_SCOPE',
	);

	/**
	 * 分類建立交易的 API WP_Error。
	 *
	 * @param \WP_Error $error API 回傳的 WP_Error。
	 * @return string 'rejected'（確定不會收款，可安全還原/重試）或 'unknown'（狀態不明，須封鎖）。
	 */
	public static function classify( $error ): string {
		if ( ! is_wp_error( $error ) ) {
			// 非 WP_Error 不該走到這裡；保守起見當 unknown。
			return 'unknown';
		}

		$code = (string) $error->get_error_code();

		return in_array( $code, self::REJECTED_CODES, true ) ? 'rejected' : 'unknown';
	}

	/**
	 * Classify the complete create-trade result.
	 *
	 * Accepted requires both a recognized active/paid status and a non-empty trade ID.
	 * A success-like response without a trade ID remains unknown because a later webhook
	 * cannot be correlated safely and a new reference could create a second charge.
	 *
	 * @param array|\WP_Error|mixed $response Create-trade response.
	 * @return string 'accepted', 'rejected', or 'unknown'.
	 */
	public static function classify_create_trade_response( $response ): string {
		if ( is_wp_error( $response ) ) {
			return self::classify( $response );
		}

		if ( ! is_array( $response ) ) {
			return 'unknown';
		}

		$trade_order_id = trim( (string) ( $response['tradeOrderId'] ?? '' ) );
		$status         = strtoupper( trim( (string) ( $response['status'] ?? '' ) ) );

		if ( '' === $trade_order_id || '' === $status ) {
			return 'unknown';
		}

		if ( YSTradeStatus::is_terminal_safe( $status ) ) {
			return 'rejected';
		}

		if ( YSTradeStatus::is_paid( $status )
			|| YSTradeStatus::is_customer_pending( $status )
			|| YSTradeStatus::is_in_flight( $status ) ) {
			return 'accepted';
		}

		return 'unknown';
	}

	/**
	 * Normalize safe diagnostics for an indeterminate create-trade result.
	 *
	 * Raw response bodies and payment/session tokens are deliberately excluded.
	 * The caller may log this context through YSLogger, which applies its own
	 * recursive redaction as a second boundary.
	 *
	 * @param array|\WP_Error|mixed $response Create-trade response.
	 * @return array<string, mixed>
	 */
	public static function diagnostic_context( $response ): array {
		$context = array(
			'http_status'           => null,
			'request_id'            => '',
			'error_code'            => '',
			'error_message'         => '',
			'status'                => '',
			'response_keys'         => array(),
			'has_trade_order_id'    => false,
			'has_next_action'       => false,
			'payment_error_code'    => '',
			'payment_error_message' => '',
			'transport_error'       => '',
		);

		if ( is_wp_error( $response ) ) {
			$data = method_exists( $response, 'get_error_data' ) ? $response->get_error_data() : null;
			$data = is_array( $data ) ? $data : array();
			$context['http_status']           = isset( $data['http_status'] ) && is_numeric( $data['http_status'] ) ? (int) $data['http_status'] : null;
			$context['request_id']            = trim( (string) ( $data['request_id'] ?? '' ) );
			$context['error_code']            = (string) $response->get_error_code();
			$context['error_message']         = (string) $response->get_error_message();
			$context['response_keys']         = self::string_keys( $data['response_keys'] ?? array() );
			$context['has_trade_order_id']    = ! empty( $data['has_trade_order_id'] );
			$context['has_next_action']       = ! empty( $data['has_next_action'] );
			$context['payment_error_code']    = (string) ( $data['payment_error_code'] ?? '' );
			$context['payment_error_message'] = (string) ( $data['payment_error_message'] ?? '' );
			$context['transport_error']       = (string) ( $data['transport_error'] ?? ( 'http_request_failed' === $context['error_code'] ? $context['error_message'] : '' ) );
			return $context;
		}

		if ( ! is_array( $response ) ) {
			return $context;
		}

		$payment_msg = isset( $response['paymentMsg'] ) && is_array( $response['paymentMsg'] )
			? $response['paymentMsg']
			: array();
		$context['status']                = strtoupper( trim( (string) ( $response['status'] ?? '' ) ) );
		$context['response_keys']         = self::string_keys( array_keys( $response ) );
		$context['has_trade_order_id']    = ! empty( $response['tradeOrderId'] );
		$context['has_next_action']       = array_key_exists( 'nextAction', $response ) && null !== $response['nextAction'];
		$context['payment_error_code']    = (string) ( $payment_msg['code'] ?? $response['code'] ?? '' );
		$context['payment_error_message'] = (string) ( $payment_msg['msg'] ?? $response['msg'] ?? $response['message'] ?? '' );

		return $context;
	}

	/**
	 * Normalize a list of response key names.
	 *
	 * @param mixed $keys Candidate key list.
	 * @return string[]
	 */
	private static function string_keys( $keys ): array {
		if ( ! is_array( $keys ) ) {
			return array();
		}

		return array_values(
			array_map(
				static fn( $key ): string => (string) $key,
				$keys
			)
		);
	}
}
