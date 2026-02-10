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
 * @package YS_Shopline_Payment
 */

defined( 'ABSPATH' ) || exit;

/**
 * YS_Shopline_Add_Payment_Method_Handler Class.
 */
class YS_Shopline_Add_Payment_Method_Handler {

	/**
	 * Initialize the handler.
	 */
	public static function init() {
		// 處理 3DS 回調（優先級 5，在其他處理器之前）
		add_action( 'template_redirect', array( __CLASS__, 'handle_add_method_redirect' ), 5 );

		// 渲染 3DS 頁面（如果需要從 add_payment_method() 返回 nextAction）
		add_action( 'template_redirect', array( __CLASS__, 'handle_3ds_page' ), 6 );
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

		YS_Shopline_Logger::debug( 'Add payment method redirect handler triggered', array(
			'user_id' => $user_id,
		) );

		// 取得暫存的綁卡資訊
		$pending_bind = get_user_meta( $user_id, '_ys_shopline_pending_bind', true );

		if ( empty( $pending_bind ) ) {
			YS_Shopline_Logger::warning( 'Add payment method redirect: No pending bind data', array(
				'user_id' => $user_id,
			) );
			wc_add_notice( __( '找不到綁卡資訊，請重新新增卡片。', 'ys-shopline-via-woocommerce' ), 'error' );
			return;
		}

		$trade_order_id     = isset( $pending_bind['trade_order_id'] ) ? $pending_bind['trade_order_id'] : '';
		$reference_order_id = isset( $pending_bind['reference_order_id'] ) ? $pending_bind['reference_order_id'] : '';
		$customer_id        = isset( $pending_bind['customer_id'] ) ? $pending_bind['customer_id'] : '';

		if ( empty( $trade_order_id ) ) {
			YS_Shopline_Logger::error( 'Add payment method redirect: No trade_order_id', array(
				'user_id'      => $user_id,
				'pending_bind' => $pending_bind,
			) );
			wc_add_notice( __( '交易資訊遺失，請重新新增卡片。', 'ys-shopline-via-woocommerce' ), 'error' );
			self::clear_pending_data( $user_id );
			return;
		}

		// 查詢 API 確認綁卡結果
		$api = YS_Shopline_Payment::get_api();
		if ( ! $api ) {
			YS_Shopline_Logger::error( 'Add payment method redirect: API not available' );
			wc_add_notice( __( '系統錯誤，請稍後重試。', 'ys-shopline-via-woocommerce' ), 'error' );
			return;
		}

		$response = $api->get_payment_trade( $trade_order_id );

		if ( is_wp_error( $response ) ) {
			YS_Shopline_Logger::error( 'Add payment method redirect: API query failed', array(
				'error'          => $response->get_error_message(),
				'trade_order_id' => $trade_order_id,
			) );
			wc_add_notice( __( '查詢綁卡結果失敗，請稍後重試。', 'ys-shopline-via-woocommerce' ), 'error' );
			return;
		}

		YS_Shopline_Logger::debug( 'Add payment method redirect: API response', array(
			'status'      => $response['status'] ?? 'unknown',
			'subStatus'   => $response['subStatus'] ?? 'unknown',
		) );

		$status = isset( $response['status'] ) ? $response['status'] : '';

		// CardBind 的成功狀態可能是 SUCCEEDED 或 SUCCESS
		if ( 'SUCCEEDED' === $status || 'SUCCESS' === $status || 'CREATED' === $status ) {
			// 綁卡成功，同步 Token
			YS_Shopline_Logger::info( 'Add payment method success', array(
				'user_id'          => $user_id,
				'trade_order_id'   => $trade_order_id,
				'status'           => $status,
			) );

			// 嘗試從回應中提取付款工具資訊
			$payment_instrument = $response['paymentInstrument'] ?? $response['payment']['paymentInstrument'] ?? array();
			$credit_card        = $response['creditCard'] ?? $response['payment']['creditCard'] ?? $payment_instrument['instrumentCard'] ?? array();
			$instrument_id      = $payment_instrument['paymentInstrumentId'] ?? $payment_instrument['instrumentId'] ?? '';

			if ( ! empty( $instrument_id ) ) {
				// 直接建立 WC Token
				self::create_token_from_response( $user_id, $instrument_id, $credit_card, $customer_id );
			} else {
				// 從 API 同步所有 Token
				$customer_manager = YS_Shopline_Customer::instance();
				$customer_manager->sync_tokens_from_api( $user_id );
			}

			// 清理暫存資料
			self::clear_pending_data( $user_id );

			wc_add_notice( __( '付款方式已成功新增。', 'ys-shopline-via-woocommerce' ), 'success' );

			// 重新導向到 payment-methods 頁面（移除參數）
			wp_safe_redirect( wc_get_account_endpoint_url( 'payment-methods' ) );
			exit;

		} elseif ( 'FAILED' === $status ) {
			// 綁卡失敗
			$error_msg = $response['paymentMsg']['msg'] ?? __( '綁卡失敗', 'ys-shopline-via-woocommerce' );
			YS_Shopline_Logger::error( 'Add payment method failed', array(
				'user_id'        => $user_id,
				'trade_order_id' => $trade_order_id,
				'error'          => $error_msg,
			) );

			self::clear_pending_data( $user_id );
			wc_add_notice( __( '新增付款方式失敗：', 'ys-shopline-via-woocommerce' ) . $error_msg, 'error' );

		} else {
			// 其他狀態（PROCESSING, CREATED 等）- 可能尚未完成
			YS_Shopline_Logger::debug( 'Add payment method: Unexpected status', array(
				'status' => $status,
			) );
			wc_add_notice( __( '綁卡正在處理中，請稍後查看。', 'ys-shopline-via-woocommerce' ), 'notice' );
		}
	}

	/**
	 * Handle 3DS page rendering.
	 *
	 * 如果 add_payment_method() 返回了 nextAction，前端需要處理 3DS。
	 * 這個方法處理從後端返回 nextAction 需要跳轉的情況。
	 */
	public static function handle_3ds_page() {
		// 檢查是否是 3DS 頁面請求
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['ys_shopline_3ds'] ) || ! isset( $_GET['add_method'] ) ) {
			return;
		}

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			wp_die( esc_html__( '請先登入。', 'ys-shopline-via-woocommerce' ) );
		}

		// 取得 nextAction
		$next_action = get_user_meta( $user_id, '_ys_shopline_add_method_next_action', true );

		if ( empty( $next_action ) ) {
			wp_safe_redirect( wc_get_account_endpoint_url( 'payment-methods' ) );
			exit;
		}

		// 清除 nextAction（只用一次）
		delete_user_meta( $user_id, '_ys_shopline_add_method_next_action' );

		// 渲染 3DS 頁面
		self::render_3ds_page( $next_action );
		exit;
	}

	/**
	 * Render 3DS verification page.
	 *
	 * @param array $next_action NextAction data from API.
	 */
	private static function render_3ds_page( $next_action ) {
		$return_url = add_query_arg(
			array( 'ys_shopline_add_method' => '1' ),
			wc_get_account_endpoint_url( 'payment-methods' )
		);

		// Get credentials
		$testmode = 'yes' === get_option( 'ys_shopline_testmode', 'yes' );

		if ( $testmode ) {
			$client_key  = get_option( 'ys_shopline_sandbox_client_key', '' );
			$merchant_id = get_option( 'ys_shopline_sandbox_merchant_id', '' );
		} else {
			$client_key  = get_option( 'ys_shopline_client_key', '' );
			$merchant_id = get_option( 'ys_shopline_merchant_id', '' );
		}

		$env = $testmode ? 'sandbox' : 'production';
		?>
		<!DOCTYPE html>
		<html>
		<head>
			<meta charset="UTF-8">
			<meta name="viewport" content="width=device-width, initial-scale=1.0">
			<title><?php esc_html_e( '驗證中...', 'ys-shopline-via-woocommerce' ); ?></title>
			<script src="https://cdn.shoplinepayments.com/sdk/v1/payment-web.js"></script>
			<style>
				body {
					font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
					display: flex;
					justify-content: center;
					align-items: center;
					min-height: 100vh;
					margin: 0;
					background: #f5f5f5;
				}
				.container {
					text-align: center;
					padding: 40px;
					background: white;
					border-radius: 8px;
					box-shadow: 0 2px 10px rgba(0,0,0,0.1);
					max-width: 500px;
					width: 90%;
				}
				.spinner {
					border: 3px solid #f3f3f3;
					border-top: 3px solid #3498db;
					border-radius: 50%;
					width: 40px;
					height: 40px;
					animation: spin 1s linear infinite;
					margin: 20px auto;
				}
				@keyframes spin {
					0% { transform: rotate(0deg); }
					100% { transform: rotate(360deg); }
				}
				.error { color: #e74c3c; margin-top: 20px; }
				#paymentContainer { margin-top: 20px; }
			</style>
		</head>
		<body>
			<div class="container">
				<h2><?php esc_html_e( '驗證信用卡', 'ys-shopline-via-woocommerce' ); ?></h2>
				<div class="spinner"></div>
				<p><?php esc_html_e( '正在進行安全驗證，請稍候...', 'ys-shopline-via-woocommerce' ); ?></p>
				<div id="paymentContainer"></div>
				<div id="errorMessage" class="error" style="display:none;"></div>
			</div>
			<script>
				var nextAction = <?php echo wp_json_encode( $next_action ); ?>;
				var returnUrl = <?php echo wp_json_encode( $return_url ); ?>;
				var clientKey = <?php echo wp_json_encode( $client_key ); ?>;
				var merchantId = <?php echo wp_json_encode( $merchant_id ); ?>;
				var env = <?php echo wp_json_encode( $env ); ?>;

				async function process3DS() {
					try {
						console.log('Initializing SDK for 3DS (add payment method)...');

						var result = await ShoplinePayments({
							clientKey: clientKey,
							merchantId: merchantId,
							paymentMethod: 'CreditCard',
							element: '#paymentContainer',
							env: env,
							currency: 'TWD',
							amount: 0
						});

						console.log('SDK initialized:', result);

						if (result.error) {
							showError('SDK Error: ' + result.error.message);
							return;
						}

						console.log('Calling payment.pay() with nextAction...');
						var payResult = await result.payment.pay(nextAction);

						console.log('pay() result:', payResult);

						if (payResult && payResult.error) {
							showError('<?php echo esc_js( __( '驗證失敗：', 'ys-shopline-via-woocommerce' ) ); ?>' + payResult.error.message);
						} else {
							// Success - redirect
							window.location.href = returnUrl;
						}

					} catch (e) {
						console.error('3DS error:', e);
						showError('<?php echo esc_js( __( '系統錯誤：', 'ys-shopline-via-woocommerce' ) ); ?>' + e.message);
					}
				}

				function showError(message) {
					document.querySelector('.spinner').style.display = 'none';
					document.getElementById('errorMessage').textContent = message;
					document.getElementById('errorMessage').style.display = 'block';
				}

				// Start processing
				process3DS();
			</script>
		</body>
		</html>
		<?php
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
		$gateway_ids = array( 'ys_shopline_credit', 'ys_shopline_credit_card', 'ys_shopline_credit_subscription' );

		foreach ( $gateway_ids as $gw_id ) {
			$existing_tokens = WC_Payment_Tokens::get_customer_tokens( $user_id, $gw_id );
			foreach ( $existing_tokens as $existing_token ) {
				if ( $existing_token->get_token() === $instrument_id ) {
					YS_Shopline_Logger::debug( 'Token already exists', array(
						'instrument_id' => $instrument_id,
					) );
					return true;
				}
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
		$token->set_gateway_id( 'ys_shopline_credit' );
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
				YS_Shopline_Logger::info( 'Payment token created', array(
					'user_id'       => $user_id,
					'token_id'      => $token->get_id(),
					'instrument_id' => $instrument_id,
					'card_last4'    => $last4,
				) );
				return true;
			}
		} catch ( Exception $e ) {
			YS_Shopline_Logger::error( 'Failed to save token', array(
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

		$api = YS_Shopline_Payment::get_api();
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
		delete_user_meta( $user_id, '_ys_shopline_pending_bind' );
		delete_user_meta( $user_id, '_ys_shopline_add_method_next_action' );
	}
}

// Initialize
YS_Shopline_Add_Payment_Method_Handler::init();
