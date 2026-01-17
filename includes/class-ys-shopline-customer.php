<?php
/**
 * Customer management for YS Shopline Payment.
 *
 * 處理會員/儲存卡管理功能
 *
 * @package YS_Shopline_Payment
 */

defined( 'ABSPATH' ) || exit;

/**
 * YS_Shopline_Customer Class.
 *
 * 管理 WordPress 用戶與 Shopline Customer 的對應關係，
 * 以及儲存卡（Payment Instruments）的管理。
 */
class YS_Shopline_Customer {

	/**
	 * User Meta Key - Shopline Customer ID
	 */
	const META_CUSTOMER_ID = '_ys_shopline_customer_id';

	/**
	 * User Meta Key - 付款工具快取
	 */
	const META_INSTRUMENTS_CACHE = '_ys_shopline_instruments_cache';

	/**
	 * 快取有效時間（秒）- 1 小時
	 */
	const CACHE_TTL = 3600;

	/**
	 * Singleton instance.
	 *
	 * @var YS_Shopline_Customer
	 */
	private static $instance = null;

	/**
	 * API instance.
	 *
	 * @var YS_Shopline_API
	 */
	private $api;

	/**
	 * Get singleton instance.
	 *
	 * @return YS_Shopline_Customer
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->api = YS_Shopline_Payment::get_api();
		$this->init_hooks();
	}

	/**
	 * Initialize hooks.
	 *
	 * 整合 WC 內建的 payment-methods 頁面
	 * 樣式和按鈕已移至 templates/myaccount/payment-methods.php
	 */
	private function init_hooks() {
		// 當使用者進入 payment-methods 頁面時，自動同步 tokens
		add_action( 'woocommerce_account_payment-methods_endpoint', array( $this, 'maybe_sync_tokens_from_api' ), 1 );

		// 當 WC Token 被刪除時，呼叫 Shopline API 解綁
		add_action( 'woocommerce_payment_token_deleted', array( $this, 'on_payment_token_deleted' ), 10, 2 );

		// AJAX 刪除卡片（保留相容性）
		add_action( 'wp_ajax_ys_shopline_delete_card', array( $this, 'ajax_delete_card' ) );

		// AJAX 同步卡片
		add_action( 'wp_ajax_ys_shopline_sync_cards', array( $this, 'ajax_sync_cards' ) );
	}

	/**
	 * 取得或建立 Shopline Customer ID
	 *
	 * @param int $user_id WordPress 用戶 ID
	 * @return string|false Customer ID 或 false（如果失敗）
	 */
	public function get_or_create_customer_id( $user_id ) {
		if ( ! $user_id ) {
			return false;
		}

		// 先嘗試從 User Meta 取得
		$customer_id = get_user_meta( $user_id, self::META_CUSTOMER_ID, true );

		if ( ! empty( $customer_id ) ) {
			return $customer_id;
		}

		// 建立新的 Customer
		if ( ! $this->api ) {
			YS_Shopline_Logger::error( 'Cannot create customer: API not configured' );
			return false;
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return false;
		}

		// 取得電話號碼並格式化
		$raw_phone = get_user_meta( $user_id, 'billing_phone', true );
		$country   = get_user_meta( $user_id, 'billing_country', true ) ?: 'TW';
		$phone     = $this->format_phone_number( $raw_phone, $country );

		// 新版 API 格式：需要 customer 物件
		$data = array(
			'referenceCustomerId' => (string) $user_id,
			'customer'            => array(
				'email'       => $user->user_email,
				'phoneNumber' => $phone,
			),
			'name'                => $user->display_name ?: $user->user_login,
		);

		$response = $this->api->create_customer( $data );

		if ( is_wp_error( $response ) ) {
			YS_Shopline_Logger::error( 'Failed to create customer: ' . $response->get_error_message(), array(
				'user_id' => $user_id,
			) );
			return false;
		}

		// 新版 API 回應欄位為 customerId
		if ( isset( $response['customerId'] ) ) {
			update_user_meta( $user_id, self::META_CUSTOMER_ID, $response['customerId'] );
			YS_Shopline_Logger::info( 'Customer created', array(
				'user_id'     => $user_id,
				'customer_id' => $response['customerId'],
			) );
			return $response['customerId'];
		}

		return false;
	}

	/**
	 * 取得 Shopline Customer ID（不建立）
	 *
	 * @param int $user_id WordPress 用戶 ID
	 * @return string|false
	 */
	public function get_customer_id( $user_id ) {
		$customer_id = get_user_meta( $user_id, self::META_CUSTOMER_ID, true );
		return ! empty( $customer_id ) ? $customer_id : false;
	}

	/**
	 * 取得用戶的付款工具（帶快取）
	 *
	 * @param int  $user_id     WordPress 用戶 ID
	 * @param bool $force_fresh 是否強制刷新
	 * @return array
	 */
	public function get_payment_instruments( $user_id, $force_fresh = false ) {
		$customer_id = $this->get_customer_id( $user_id );

		if ( ! $customer_id ) {
			return array();
		}

		// 檢查快取
		if ( ! $force_fresh ) {
			$cached = $this->get_instruments_from_cache( $user_id );
			if ( false !== $cached ) {
				return $cached;
			}
		}

		// 從 API 取得
		if ( ! $this->api ) {
			return array();
		}

		// 不帶 filter，取得所有卡片（帶 filter 會導致 API 返回 null）
		$response = $this->api->get_payment_instruments( $customer_id );

		if ( is_wp_error( $response ) ) {
			YS_Shopline_Logger::error( 'Failed to get payment instruments: ' . $response->get_error_message(), array(
				'user_id'     => $user_id,
				'customer_id' => $customer_id,
			) );
			return array();
		}

		$instruments = isset( $response['paymentInstruments'] ) ? $response['paymentInstruments'] : array();

		// 儲存快取
		$this->save_instruments_to_cache( $user_id, $instruments );

		return $instruments;
	}

	/**
	 * 解綁付款工具
	 *
	 * @param int    $user_id       WordPress 用戶 ID
	 * @param string $instrument_id 付款工具 ID
	 * @return bool
	 */
	public function unbind_payment_instrument( $user_id, $instrument_id ) {
		$customer_id = $this->get_customer_id( $user_id );

		if ( ! $customer_id || ! $this->api ) {
			return false;
		}

		$response = $this->api->delete_payment_instrument( $customer_id, $instrument_id );

		if ( is_wp_error( $response ) ) {
			YS_Shopline_Logger::error( 'Failed to unbind payment instrument: ' . $response->get_error_message(), array(
				'user_id'       => $user_id,
				'instrument_id' => $instrument_id,
			) );
			return false;
		}

		// 清除快取
		$this->clear_instruments_cache( $user_id );

		// 刪除對應的 WC Token
		$this->delete_wc_token_by_instrument_id( $user_id, $instrument_id );

		YS_Shopline_Logger::info( 'Payment instrument unbound', array(
			'user_id'       => $user_id,
			'instrument_id' => $instrument_id,
		) );

		return true;
	}

	/**
	 * 當 WC Token 被刪除時的回呼
	 *
	 * WooCommerce 在 /my-account/payment-methods/ 頁面刪除 token 時會觸發這個 action。
	 * 我們攔截這個事件，呼叫 Shopline API 解綁付款工具。
	 *
	 * @param int                $token_id Token ID
	 * @param WC_Payment_Token   $token    Token 物件
	 */
	public function on_payment_token_deleted( $token_id, $token ) {
		// 只處理我們閘道的 tokens
		$gateway_id = $token->get_gateway_id();
		if ( strpos( $gateway_id, 'ys_shopline' ) === false ) {
			return;
		}

		$user_id       = $token->get_user_id();
		$instrument_id = $token->get_token();

		if ( ! $user_id || ! $instrument_id ) {
			return;
		}

		$customer_id = $this->get_customer_id( $user_id );

		if ( ! $customer_id || ! $this->api ) {
			YS_Shopline_Logger::warning( 'Cannot unbind payment instrument: no customer ID or API', array(
				'user_id'       => $user_id,
				'instrument_id' => $instrument_id,
			) );
			return;
		}

		// 呼叫 Shopline API 解綁付款工具
		$response = $this->api->delete_payment_instrument( $customer_id, $instrument_id );

		if ( is_wp_error( $response ) ) {
			// API 失敗時只記錄警告，不影響 WC Token 刪除（已經被 WC 刪除了）
			YS_Shopline_Logger::warning( 'Failed to unbind payment instrument via API (WC token already deleted): ' . $response->get_error_message(), array(
				'user_id'       => $user_id,
				'instrument_id' => $instrument_id,
			) );
			return;
		}

		// 清除快取
		$this->clear_instruments_cache( $user_id );

		YS_Shopline_Logger::info( 'Payment instrument unbound via WC payment-methods page', array(
			'user_id'       => $user_id,
			'instrument_id' => $instrument_id,
		) );
	}

	/**
	 * 當 WC Tokens 為空時，從 API 同步
	 */
	public function maybe_sync_tokens_from_api() {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return;
		}

		// 檢查是否有 Shopline Customer ID
		$customer_id = $this->get_customer_id( $user_id );
		if ( ! $customer_id ) {
			return;
		}

		// 取得現有的 WC Tokens
		$tokens = WC_Payment_Tokens::get_customer_tokens( $user_id, 'ys_shopline_credit_card' );

		// 如果已有 tokens，不需要同步
		if ( ! empty( $tokens ) ) {
			return;
		}

		// 從 API 取得付款工具
		$instruments = $this->get_payment_instruments( $user_id, true );

		if ( empty( $instruments ) ) {
			return;
		}

		YS_Shopline_Logger::info( 'Syncing payment instruments to WC Tokens', array(
			'user_id' => $user_id,
			'count'   => count( $instruments ),
		) );

		// 同步到 WC Tokens
		foreach ( $instruments as $instrument ) {
			$this->create_wc_token_from_instrument( $user_id, $instrument );
		}
	}

	/**
	 * 從付款工具建立 WC Token
	 *
	 * @param int   $user_id    WordPress 用戶 ID
	 * @param array $instrument 付款工具資料
	 * @return WC_Payment_Token_CC|false
	 */
	private function create_wc_token_from_instrument( $user_id, $instrument ) {
		if ( empty( $instrument['paymentInstrumentId'] ) ) {
			return false;
		}

		// 檢查是否已存在
		$existing = $this->get_wc_token_by_instrument_id( $user_id, $instrument['paymentInstrumentId'] );
		if ( $existing ) {
			return $existing;
		}

		$card_info = isset( $instrument['instrumentCard'] ) ? $instrument['instrumentCard'] : array();

		$token = new WC_Payment_Token_CC();
		$token->set_token( $instrument['paymentInstrumentId'] );
		$token->set_gateway_id( 'ys_shopline_credit_card' );
		$token->set_user_id( $user_id );
		$token->set_card_type( strtolower( $card_info['brand'] ?? 'visa' ) );
		$token->set_last4( $card_info['last'] ?? '****' );
		$token->set_expiry_month( $card_info['expiryMonth'] ?? '12' );
		$token->set_expiry_year( $card_info['expiryYear'] ?? date( 'Y' ) );

		// 儲存 Shopline 付款工具 ID
		$token->add_meta_data( '_ys_shopline_instrument_id', $instrument['paymentInstrumentId'], true );

		if ( $token->save() ) {
			YS_Shopline_Logger::debug( 'WC Token created from instrument', array(
				'user_id'       => $user_id,
				'token_id'      => $token->get_id(),
				'instrument_id' => $instrument['paymentInstrumentId'],
			) );
			return $token;
		}

		return false;
	}

	/**
	 * 根據 instrument ID 取得 WC Token
	 *
	 * @param int    $user_id       WordPress 用戶 ID
	 * @param string $instrument_id 付款工具 ID
	 * @return WC_Payment_Token_CC|false
	 */
	private function get_wc_token_by_instrument_id( $user_id, $instrument_id ) {
		$tokens = WC_Payment_Tokens::get_customer_tokens( $user_id, 'ys_shopline_credit_card' );

		foreach ( $tokens as $token ) {
			if ( $token->get_token() === $instrument_id ) {
				return $token;
			}
		}

		return false;
	}

	/**
	 * 根據 instrument ID 刪除 WC Token
	 *
	 * @param int    $user_id       WordPress 用戶 ID
	 * @param string $instrument_id 付款工具 ID
	 */
	private function delete_wc_token_by_instrument_id( $user_id, $instrument_id ) {
		$token = $this->get_wc_token_by_instrument_id( $user_id, $instrument_id );

		if ( $token ) {
			WC_Payment_Tokens::delete( $token->get_id() );
			YS_Shopline_Logger::debug( 'WC Token deleted', array(
				'user_id'       => $user_id,
				'token_id'      => $token->get_id(),
				'instrument_id' => $instrument_id,
			) );
		}
	}

	/**
	 * 渲染付款方式頁面（額外內容）
	 */
	public function render_payment_methods_page() {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return;
		}

		// 取得付款工具
		$instruments = $this->get_payment_instruments( $user_id );

		if ( empty( $instruments ) ) {
			return;
		}

		// 輸出管理介面的 CSS 和 JS
		$this->output_payment_methods_assets();
	}

	/**
	 * 輸出付款方式頁面的 CSS 和 JS
	 */
	private function output_payment_methods_assets() {
		?>
		<style>
			.ys-shopline-card-actions {
				margin-top: 10px;
			}
			.ys-shopline-delete-card {
				color: #a00;
				cursor: pointer;
				text-decoration: underline;
			}
			.ys-shopline-delete-card:hover {
				color: #dc3232;
			}
		</style>
		<script>
		jQuery(function($) {
			// 刪除卡片
			$(document).on('click', '.ys-shopline-delete-card', function(e) {
				e.preventDefault();

				if (!confirm('<?php echo esc_js( __( '確定要刪除這張卡片嗎？', 'ys-shopline-via-woocommerce' ) ); ?>')) {
					return;
				}

				var $btn = $(this);
				var tokenId = $btn.data('token-id');

				$btn.text('<?php echo esc_js( __( '刪除中...', 'ys-shopline-via-woocommerce' ) ); ?>');

				$.ajax({
					url: '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>',
					type: 'POST',
					data: {
						action: 'ys_shopline_delete_card',
						token_id: tokenId,
						nonce: '<?php echo esc_js( wp_create_nonce( 'ys_shopline_delete_card' ) ); ?>'
					},
					success: function(response) {
						if (response.success) {
							location.reload();
						} else {
							alert(response.data.message || '<?php echo esc_js( __( '刪除失敗', 'ys-shopline-via-woocommerce' ) ); ?>');
							$btn.text('<?php echo esc_js( __( '刪除', 'ys-shopline-via-woocommerce' ) ); ?>');
						}
					},
					error: function() {
						alert('<?php echo esc_js( __( '刪除失敗', 'ys-shopline-via-woocommerce' ) ); ?>');
						$btn.text('<?php echo esc_js( __( '刪除', 'ys-shopline-via-woocommerce' ) ); ?>');
					}
				});
			});
		});
		</script>
		<?php
	}

	/**
	 * AJAX 刪除卡片
	 */
	public function ajax_delete_card() {
		check_ajax_referer( 'ys_shopline_delete_card', 'nonce' );

		$user_id  = get_current_user_id();
		$token_id = isset( $_POST['token_id'] ) ? absint( $_POST['token_id'] ) : 0;

		if ( ! $user_id || ! $token_id ) {
			wp_send_json_error( array( 'message' => __( '參數錯誤', 'ys-shopline-via-woocommerce' ) ) );
		}

		// 取得 Token
		$token = WC_Payment_Tokens::get( $token_id );

		if ( ! $token || $token->get_user_id() !== $user_id ) {
			wp_send_json_error( array( 'message' => __( '找不到卡片', 'ys-shopline-via-woocommerce' ) ) );
		}

		// 取得 Shopline 付款工具 ID
		$instrument_id = $token->get_token();

		// 先嘗試呼叫 API 解綁
		$api_success = $this->unbind_payment_instrument( $user_id, $instrument_id );

		// 無論 API 是否成功，都刪除本地 Token（可能 API 端已經不存在了）
		WC_Payment_Tokens::delete( $token_id );

		// 清除快取
		$this->clear_instruments_cache( $user_id );

		wp_send_json_success( array(
			'message'     => __( '卡片已刪除', 'ys-shopline-via-woocommerce' ),
			'api_success' => $api_success,
		) );
	}

	/**
	 * 從快取取得付款工具
	 *
	 * @param int $user_id WordPress 用戶 ID
	 * @return array|false 快取資料或 false（如果快取無效）
	 */
	private function get_instruments_from_cache( $user_id ) {
		$cache = get_user_meta( $user_id, self::META_INSTRUMENTS_CACHE, true );

		if ( ! is_array( $cache ) || empty( $cache['cached_at'] ) ) {
			return false;
		}

		// 檢查快取是否過期
		if ( time() - $cache['cached_at'] > self::CACHE_TTL ) {
			return false;
		}

		return isset( $cache['data'] ) ? $cache['data'] : array();
	}

	/**
	 * 儲存付款工具到快取
	 *
	 * @param int   $user_id     WordPress 用戶 ID
	 * @param array $instruments 付款工具陣列
	 */
	private function save_instruments_to_cache( $user_id, $instruments ) {
		update_user_meta( $user_id, self::META_INSTRUMENTS_CACHE, array(
			'data'      => $instruments,
			'cached_at' => time(),
		) );
	}

	/**
	 * 清除付款工具快取
	 *
	 * @param int $user_id WordPress 用戶 ID
	 */
	public function clear_instruments_cache( $user_id ) {
		delete_user_meta( $user_id, self::META_INSTRUMENTS_CACHE );
	}

	/**
	 * 格式化電話號碼
	 *
	 * @param string $phone   電話號碼
	 * @param string $country 國家代碼
	 * @return string
	 */
	private function format_phone_number( $phone, $country = 'TW' ) {
		if ( empty( $phone ) ) {
			return '';
		}

		// 移除所有非數字字元（保留開頭的 +）
		$has_plus = ( substr( $phone, 0, 1 ) === '+' );
		$phone    = preg_replace( '/[^0-9]/', '', $phone );

		// 如果已經有國碼格式，直接返回
		if ( $has_plus ) {
			return '+' . $phone;
		}

		// 根據國家加入國碼
		$country_codes = array(
			'TW' => '886',
			'HK' => '852',
			'JP' => '81',
			'KR' => '82',
			'US' => '1',
			'CN' => '86',
			'SG' => '65',
			'MY' => '60',
		);

		$country_code = isset( $country_codes[ $country ] ) ? $country_codes[ $country ] : '886';

		// 移除開頭的 0（台灣手機 09xx -> 9xx）
		if ( substr( $phone, 0, 1 ) === '0' ) {
			$phone = substr( $phone, 1 );
		}

		return '+' . $country_code . $phone;
	}

	/**
	 * 檢查用戶是否有已儲存的付款工具
	 *
	 * @param int $user_id WordPress 用戶 ID
	 * @return bool
	 */
	public function has_saved_instruments( $user_id ) {
		$instruments = $this->get_payment_instruments( $user_id );
		return ! empty( $instruments );
	}

	/**
	 * AJAX 同步卡片
	 */
	public function ajax_sync_cards() {
		check_ajax_referer( 'ys_shopline_sync_cards', 'nonce' );

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			wp_send_json_error( array( 'message' => '請先登入' ) );
		}

		$customer_id = $this->get_customer_id( $user_id );
		if ( ! $customer_id ) {
			wp_send_json_error( array( 'message' => '找不到 Shopline 會員資料' ) );
		}

		// 清除快取，強制從 API 取得最新資料
		$this->clear_instruments_cache( $user_id );

		// 從 API 取得付款工具
		$instruments = $this->get_payment_instruments( $user_id, true );

		if ( empty( $instruments ) ) {
			wp_send_json_success( array(
				'message' => '沒有找到儲存的卡片',
				'count'   => 0,
			) );
		}

		// 取得現有的 WC Tokens
		$existing_tokens = WC_Payment_Tokens::get_customer_tokens( $user_id, 'ys_shopline_credit_card' );
		$existing_instrument_ids = array();
		foreach ( $existing_tokens as $token ) {
			$existing_instrument_ids[] = $token->get_token();
		}

		// 同步到 WC Tokens
		$synced_count = 0;
		foreach ( $instruments as $instrument ) {
			$instrument_id = $instrument['paymentInstrumentId'] ?? '';
			if ( $instrument_id && ! in_array( $instrument_id, $existing_instrument_ids, true ) ) {
				$result = $this->create_wc_token_from_instrument( $user_id, $instrument );
				if ( $result ) {
					$synced_count++;
				}
			}
		}

		YS_Shopline_Logger::info( 'Cards synced from API', array(
			'user_id'      => $user_id,
			'total'        => count( $instruments ),
			'synced'       => $synced_count,
		) );

		wp_send_json_success( array(
			'message' => sprintf( '同步完成，共 %d 張卡片', count( $instruments ) ),
			'count'   => count( $instruments ),
			'synced'  => $synced_count,
		) );
	}
}
