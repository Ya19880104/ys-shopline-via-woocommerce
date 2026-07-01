<?php
/**
 * Order display enhancements for YS Shopline Payment.
 *
 * 處理訂單頁面的顯示：付款成功/失敗的醒目提示
 *
 * @package YangSheep\ShoplinePayment\Frontend
 */

namespace YangSheep\ShoplinePayment\Frontend;

defined( 'ABSPATH' ) || exit;

use YangSheep\ShoplinePayment\Utils\YSOrderMeta;

/**
 * YSOrderDisplay Class.
 *
 * 增強訂單頁面的顯示，包括：
 * - 付款失敗時顯示錯誤訊息和重新付款連結
 * - 付款成功時顯示付款完成和付款方式資訊
 */
class YSOrderDisplay {

	/**
	 * Singleton instance.
	 *
	 * @var YSOrderDisplay
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return YSOrderDisplay
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
		$this->init_hooks();
	}

	/**
	 * Initialize hooks.
	 */
	private function init_hooks() {
		// 感謝頁面顯示付款狀態
		add_action( 'woocommerce_before_thankyou', array( $this, 'display_payment_status_notice' ), 5 );

		// 訂單詳情頁面（我的帳戶）顯示付款狀態
		add_action( 'woocommerce_view_order', array( $this, 'display_payment_status_notice' ), 5 );

		// 輸出樣式
		add_action( 'wp_head', array( $this, 'output_styles' ) );
	}

	/**
	 * 顯示付款狀態通知
	 *
	 * @param int $order_id 訂單 ID
	 */
	public function display_payment_status_notice( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return;
		}

		// 檢查是否為 Shopline 付款
		$payment_method = $order->get_payment_method();
		if ( strpos( $payment_method, 'ys_shopline' ) !== 0 ) {
			return;
		}

		$status = $order->get_status();

		$has_offline_payment_details = $this->has_offline_payment_details( $order );

		// 付款成功
		if ( $order->is_paid() ) {
			$this->render_success_notice( $order );
			if ( $has_offline_payment_details ) {
				$this->render_offline_payment_info( $order );
			}
			return;
		}

		// 離線付款已取得繳費資訊：pending/on-hold 都顯示繳費資訊，只提供變更付款方式。
		if ( $has_offline_payment_details && in_array( $status, array( 'pending', 'on-hold' ), true ) ) {
			$this->render_offline_payment_pending_notice( $order );
			$this->render_offline_payment_info( $order );
			return;
		}

		// v3.5.11: 信用卡 / 分期 hitrust 3DS 中間態 — 已授權待 capture
		// 顯示綠色標章「已授權等待金流回傳確認」，避免使用者誤以為「付款尚未完成」
		if ( 'on-hold' === $status && 'yes' === $order->get_meta( YSOrderMeta::PAYMENT_AUTHORIZED_PENDING ) ) {
			$this->render_authorized_pending_notice( $order );
			return;
		}

		// 付款失敗或等待付款
		if ( in_array( $status, array( 'failed', 'pending', 'on-hold' ), true ) ) {
			$this->render_pending_or_failed_notice( $order );
			return;
		}
	}

	/**
	 * 判斷訂單是否已有離線付款資訊。
	 *
	 * 目前外掛只有 ATM 虛擬帳號屬於需要顯示繳費資訊的離線付款。
	 * 未來若新增 IBON / 超商代碼，可在這裡接入對應 meta 判斷。
	 *
	 * @param \WC_Order $order 訂單物件
	 * @return bool
	 */
	private function has_offline_payment_details( $order ) {
		if ( 'ys_shopline_atm' === $order->get_payment_method() ) {
			return (bool) $order->get_meta( YSOrderMeta::VA_ACCOUNT );
		}

		return false;
	}

	/**
	 * v3.5.11: 渲染「已授權等待金流回傳確認」綠色標章。
	 *
	 * 適用情境：信用卡 / 分期走 hitrust 3DS 後 SHOPLINE 進 PROCESSING/AUTHORIZED
	 * 中間態（卡片已授權，金額已預留），等待 SHOPLINE webhook 觸發 capture。
	 *
	 * @param \WC_Order $order 訂單物件
	 */
	private function render_authorized_pending_notice( $order ) {
		$trade_order_id = $order->get_meta( YSOrderMeta::TRADE_ORDER_ID );
		$payment_method = $order->get_meta( YSOrderMeta::PAYMENT_METHOD );
		$method_display = $this->get_payment_method_display( $payment_method );
		// v3.5.33: 標題依實際 WC gateway 選字，避免一次付清誤顯示「分期付款授權成功」。
		$notice_title = $this->get_authorized_pending_title( $order->get_payment_method() );
		?>
		<div class="ys-shopline-notice ys-shopline-notice-success">
			<div class="ys-shopline-notice-icon">✓</div>
			<div class="ys-shopline-notice-content">
				<h3><?php echo esc_html( $notice_title ); ?></h3>
				<p>
					<?php esc_html_e( '通常約需1~5分鐘，付款完成後系統將會自動變更訂單並通知您。', 'ys-shopline-via-woocommerce' ); ?>
				</p>
				<ul class="ys-shopline-payment-info">
					<li>
						<strong><?php esc_html_e( '付款方式：', 'ys-shopline-via-woocommerce' ); ?></strong>
						<?php echo esc_html( $method_display ); ?>
					</li>
					<?php if ( $trade_order_id ) : ?>
					<li>
						<strong><?php esc_html_e( '交易編號：', 'ys-shopline-via-woocommerce' ); ?></strong>
						<?php echo esc_html( $trade_order_id ); ?>
					</li>
					<?php endif; ?>
				</ul>
			</div>
		</div>
		<?php
	}

	/**
	 * v3.5.33: 依 WC gateway 取「已授權待確認」標題，讓文字跟隨實際付款方式。
	 *
	 * 分期（信用卡分期 / 中租零卡）→「分期付款授權成功」；
	 * 一次付清信用卡 / 訂閱 →「信用卡授權成功」；其餘 →「付款授權成功」。
	 *
	 * @param string $wc_gateway_id WooCommerce 付款方式 ID。
	 * @return string
	 */
	private function get_authorized_pending_title( $wc_gateway_id ) {
		switch ( $wc_gateway_id ) {
			case 'ys_shopline_credit_installment':
			case 'ys_shopline_bnpl':
				return __( '分期付款授權成功，等待銀行確認', 'ys-shopline-via-woocommerce' );
			case 'ys_shopline_credit':
			case 'ys_shopline_credit_subscription':
				return __( '信用卡授權成功，等待銀行確認', 'ys-shopline-via-woocommerce' );
			default:
				return __( '付款授權成功，等待銀行確認', 'ys-shopline-via-woocommerce' );
		}
	}

	/**
	 * 渲染付款成功通知
	 *
	 * @param \WC_Order $order 訂單物件
	 */
	private function render_success_notice( $order ) {
		$trade_order_id = $order->get_meta( YSOrderMeta::TRADE_ORDER_ID );
		$payment_method = $order->get_meta( YSOrderMeta::PAYMENT_METHOD );
		$payment_detail = YSOrderMeta::get_payment_detail( $order );

		// 付款方式顯示名稱
		$method_display = $this->get_payment_method_display( $payment_method );

		// 卡片資訊（如果有）
		$card_info = '';
		if ( is_array( $payment_detail ) && isset( $payment_detail['paymentInstrument']['instrumentCard'] ) ) {
			$card = $payment_detail['paymentInstrument']['instrumentCard'];
			$card_info = sprintf(
				'%s •••• %s',
				isset( $card['brand'] ) ? $card['brand'] : '',
				isset( $card['last'] ) ? $card['last'] : ''
			);
		}

		?>
		<div class="ys-shopline-notice ys-shopline-notice-success">
			<div class="ys-shopline-notice-icon">✓</div>
			<div class="ys-shopline-notice-content">
				<h3><?php esc_html_e( '付款成功', 'ys-shopline-via-woocommerce' ); ?></h3>
				<p>
					<?php esc_html_e( '您的付款已完成處理。', 'ys-shopline-via-woocommerce' ); ?>
				</p>
				<ul class="ys-shopline-payment-info">
					<li>
						<strong><?php esc_html_e( '付款方式：', 'ys-shopline-via-woocommerce' ); ?></strong>
						<?php echo esc_html( $method_display ); ?>
						<?php if ( $card_info ) : ?>
							<span class="ys-shopline-card-info">(<?php echo esc_html( $card_info ); ?>)</span>
						<?php endif; ?>
					</li>
					<?php if ( $trade_order_id ) : ?>
					<li>
						<strong><?php esc_html_e( '交易編號：', 'ys-shopline-via-woocommerce' ); ?></strong>
						<?php echo esc_html( $trade_order_id ); ?>
					</li>
					<?php endif; ?>
				</ul>
			</div>
		</div>
		<?php
	}

	/**
	 * 渲染付款失敗/等待付款通知
	 *
	 * @param \WC_Order $order 訂單物件
	 */
	private function render_pending_or_failed_notice( $order ) {
		$status = $order->get_status();

		// 取得錯誤資訊
		$error_code = $order->get_meta( YSOrderMeta::ERROR_CODE );
		$error_msg  = $order->get_meta( YSOrderMeta::ERROR_MESSAGE );

		// 從訂單備註中取得錯誤資訊（如果 meta 中沒有）
		if ( empty( $error_msg ) && 'failed' === $status ) {
			$notes = wc_get_order_notes( array(
				'order_id' => $order->get_id(),
				'limit'    => 5,
			) );

			foreach ( $notes as $note ) {
				if ( strpos( $note->content, '付款失敗' ) !== false || strpos( $note->content, 'failed' ) !== false ) {
					$error_msg = $note->content;
					break;
				}
			}
		}

		// 付款連結
		$pay_url = $order->get_checkout_payment_url();

		// 我的帳戶訂單連結
		$orders_url = wc_get_account_endpoint_url( 'orders' );

		$is_failed = ( 'failed' === $status );

		?>
		<div class="ys-shopline-notice ys-shopline-notice-<?php echo $is_failed ? 'error' : 'warning'; ?>">
			<div class="ys-shopline-notice-icon"><?php echo $is_failed ? '✕' : '!'; ?></div>
			<div class="ys-shopline-notice-content">
				<h3>
					<?php
					if ( $is_failed ) {
						esc_html_e( '付款失敗', 'ys-shopline-via-woocommerce' );
					} else {
						esc_html_e( '等待付款', 'ys-shopline-via-woocommerce' );
					}
					?>
				</h3>

				<?php if ( $error_msg ) : ?>
				<div class="ys-shopline-error-details">
					<?php if ( $error_code ) : ?>
					<p>
						<strong><?php esc_html_e( '錯誤代碼：', 'ys-shopline-via-woocommerce' ); ?></strong>
						<code><?php echo esc_html( $error_code ); ?></code>
					</p>
					<?php endif; ?>
					<p>
						<strong><?php esc_html_e( '錯誤訊息：', 'ys-shopline-via-woocommerce' ); ?></strong>
						<?php echo esc_html( $error_msg ); ?>
					</p>
				</div>
				<?php endif; ?>

				<p class="ys-shopline-action-text">
					<?php
					if ( $is_failed ) {
						esc_html_e( '請點擊下方按鈕重新付款，或前往「我的帳戶 > 訂單」中選擇此訂單進行付款。', 'ys-shopline-via-woocommerce' );
					} else {
						esc_html_e( '您的訂單尚未完成付款，請點擊下方按鈕進行付款。', 'ys-shopline-via-woocommerce' );
					}
					?>
				</p>

				<div class="ys-shopline-notice-actions">
					<a href="<?php echo esc_url( $pay_url ); ?>" class="button ys-shopline-pay-button">
						<?php esc_html_e( '立即付款', 'ys-shopline-via-woocommerce' ); ?>
					</a>
					<a href="<?php echo esc_url( $orders_url ); ?>" class="button button-secondary">
						<?php esc_html_e( '前往我的訂單', 'ys-shopline-via-woocommerce' ); ?>
					</a>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * 取得付款方式顯示名稱
	 *
	 * @param string $payment_method 付款方式代碼
	 * @return string
	 */
	private function get_payment_method_display( $payment_method ) {
		$methods = array(
			'CreditCard'     => __( '信用卡', 'ys-shopline-via-woocommerce' ),
			'LinePay'        => 'LINE Pay',
			'JKOPay'         => __( '街口支付', 'ys-shopline-via-woocommerce' ),
			'JkoPay'         => __( '街口支付', 'ys-shopline-via-woocommerce' ),
			'ApplePay'       => 'Apple Pay',
			'VirtualAccount' => __( 'ATM 虛擬帳號', 'ys-shopline-via-woocommerce' ),
			'VirtualAtm'     => __( 'ATM 虛擬帳號', 'ys-shopline-via-woocommerce' ),
			'ChaileaseBNPL'  => __( '中租零卡分期', 'ys-shopline-via-woocommerce' ),
			'ChaileaseBnpl'  => __( '中租零卡分期', 'ys-shopline-via-woocommerce' ),
		);

		return isset( $methods[ $payment_method ] ) ? $methods[ $payment_method ] : ( $payment_method ?: __( '線上付款', 'ys-shopline-via-woocommerce' ) );
	}

	/**
	 * 渲染離線付款等待通知
	 *
	 * @param \WC_Order $order 訂單物件
	 */
	private function render_offline_payment_pending_notice( $order ) {
		$pay_url = $order->get_checkout_payment_url();

		?>
		<div class="ys-shopline-notice ys-shopline-notice-warning">
			<div class="ys-shopline-notice-icon">!</div>
			<div class="ys-shopline-notice-content">
				<h3><?php esc_html_e( '等待轉帳', 'ys-shopline-via-woocommerce' ); ?></h3>
				<p>
					<?php esc_html_e( '您的訂單已建立，請依下方轉帳資訊前往銀行或 ATM 完成轉帳。', 'ys-shopline-via-woocommerce' ); ?>
				</p>
				<div class="ys-shopline-notice-actions">
					<a href="<?php echo esc_url( $pay_url ); ?>" class="button button-secondary">
						<?php esc_html_e( '變更付款方式', 'ys-shopline-via-woocommerce' ); ?>
					</a>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * 渲染離線付款資訊。
	 *
	 * @param \WC_Order $order 訂單物件
	 */
	private function render_offline_payment_info( $order ) {
		if ( 'ys_shopline_atm' === $order->get_payment_method() ) {
			$this->render_atm_transfer_info( $order );
		}
	}

	/**
	 * 渲染 ATM 轉帳資訊區塊（醒目樣式）
	 *
	 * @param \WC_Order $order 訂單物件
	 */
	private function render_atm_transfer_info( $order ) {
		$bank_code = $order->get_meta( YSOrderMeta::VA_BANK_CODE );
		$account   = $order->get_meta( YSOrderMeta::VA_ACCOUNT );
		$expire    = $order->get_meta( YSOrderMeta::VA_EXPIRE );

		if ( ! $account ) {
			return;
		}

		?>
		<div class="ys-shopline-notice ys-shopline-notice-info">
			<div class="ys-shopline-notice-icon">$</div>
			<div class="ys-shopline-notice-content">
				<h3><?php esc_html_e( 'ATM 轉帳付款', 'ys-shopline-via-woocommerce' ); ?></h3>
				<p><?php esc_html_e( '請前往銀行櫃檯、ATM 或使用網路銀行，依下方資訊進行轉帳。', 'ys-shopline-via-woocommerce' ); ?></p>
				<ul class="ys-shopline-payment-info ys-shopline-atm-details">
					<?php if ( $bank_code ) : ?>
					<li>
						<span class="ys-shopline-atm-label"><?php esc_html_e( '銀行代碼', 'ys-shopline-via-woocommerce' ); ?></span>
						<span class="ys-shopline-atm-value"><?php echo esc_html( $bank_code ); ?></span>
					</li>
					<?php endif; ?>
					<li>
						<span class="ys-shopline-atm-label"><?php esc_html_e( '虛擬帳號', 'ys-shopline-via-woocommerce' ); ?></span>
						<span class="ys-shopline-atm-value ys-shopline-atm-account"><?php echo esc_html( $account ); ?></span>
					</li>
					<li>
						<span class="ys-shopline-atm-label"><?php esc_html_e( '轉帳金額', 'ys-shopline-via-woocommerce' ); ?></span>
						<span class="ys-shopline-atm-value"><?php echo wp_kses_post( $order->get_formatted_order_total() ); ?></span>
					</li>
					<?php if ( $expire ) : ?>
					<li>
						<span class="ys-shopline-atm-label"><?php esc_html_e( '繳費期限', 'ys-shopline-via-woocommerce' ); ?></span>
						<span class="ys-shopline-atm-value"><?php echo esc_html( $expire ); ?></span>
					</li>
					<?php endif; ?>
				</ul>
				<p class="ys-shopline-atm-warning">
					<?php esc_html_e( '請於繳費期限前完成轉帳，逾期此虛擬帳號將失效。', 'ys-shopline-via-woocommerce' ); ?>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * 輸出樣式
	 */
	public function output_styles() {
		// 只在相關頁面輸出
		if ( ! is_order_received_page() && ! is_view_order_page() ) {
			return;
		}

		?>
		<style>
			/* 隱藏 WooCommerce 預設的付款失敗區塊，使用我們自訂的 */
			.woocommerce-thankyou-order-failed,
			.woocommerce-thankyou-order-failed-actions {
				display: none !important;
			}

			.ys-shopline-notice {
				display: flex;
				align-items: flex-start;
				padding: 20px;
				margin-bottom: 30px;
				border-radius: 8px;
				background: #fff;
				box-shadow: 0 2px 8px rgba(0,0,0,0.1);
			}

			.ys-shopline-notice-success {
				border-left: 4px solid #46b450;
				background: linear-gradient(135deg, #f0fff0 0%, #fff 100%);
			}

			.ys-shopline-notice-error {
				border-left: 4px solid #dc3232;
				background: linear-gradient(135deg, #fff0f0 0%, #fff 100%);
			}

			.ys-shopline-notice-warning {
				border-left: 4px solid #ffb900;
				background: linear-gradient(135deg, #fffbf0 0%, #fff 100%);
			}

			.ys-shopline-notice-info {
				border-left: 4px solid #0073aa;
				background: linear-gradient(135deg, #f0f6ff 0%, #fff 100%);
				margin-top: -20px;
			}

			.ys-shopline-notice-icon {
				flex-shrink: 0;
				width: 48px;
				height: 48px;
				display: flex;
				align-items: center;
				justify-content: center;
				border-radius: 50%;
				font-size: 24px;
				font-weight: bold;
				margin-right: 16px;
			}

			.ys-shopline-notice-success .ys-shopline-notice-icon {
				background: #46b450;
				color: #fff;
			}

			.ys-shopline-notice-error .ys-shopline-notice-icon {
				background: #dc3232;
				color: #fff;
			}

			.ys-shopline-notice-warning .ys-shopline-notice-icon {
				background: #ffb900;
				color: #fff;
			}

			.ys-shopline-notice-info .ys-shopline-notice-icon {
				background: #0073aa;
				color: #fff;
			}

			.ys-shopline-notice-content {
				flex: 1;
			}

			.ys-shopline-notice-content h3 {
				margin: 0 0 10px 0;
				font-size: 1.3em;
				font-weight: 600;
			}

			.ys-shopline-notice-success h3 {
				color: #2e7d32;
			}

			.ys-shopline-notice-error h3 {
				color: #c62828;
			}

			.ys-shopline-notice-warning h3 {
				color: #f57c00;
			}

			.ys-shopline-notice-info h3 {
				color: #01579b;
			}

			.ys-shopline-notice-content p {
				margin: 0 0 10px 0;
				color: #555;
			}

			.ys-shopline-payment-info {
				list-style: none;
				margin: 15px 0 0 0;
				padding: 15px;
				background: rgba(0,0,0,0.03);
				border-radius: 4px;
			}

			.ys-shopline-payment-info li {
				margin: 5px 0;
				padding: 0;
			}

			.ys-shopline-card-info {
				color: #666;
				font-size: 0.9em;
			}

			.ys-shopline-atm-details li {
				display: flex;
				justify-content: space-between;
				align-items: center;
				padding: 10px 0;
				border-bottom: 1px dashed #d0d0d0;
				margin: 0;
			}

			.ys-shopline-atm-details li:last-child {
				border-bottom: none;
			}

			.ys-shopline-atm-label {
				color: #555;
				font-weight: 500;
			}

			.ys-shopline-atm-value {
				font-weight: 700;
				font-size: 1.1em;
				color: #222;
			}

			.ys-shopline-atm-account {
				font-family: 'Courier New', Courier, monospace;
				font-size: 1.2em;
				letter-spacing: 1px;
			}

			.ys-shopline-atm-warning {
				color: #b71c1c;
				font-weight: 500;
				font-size: 0.9em;
				margin-top: 10px;
			}

			.ys-shopline-error-details {
				background: rgba(220,50,50,0.05);
				padding: 12px 15px;
				border-radius: 4px;
				margin: 10px 0;
			}

			.ys-shopline-error-details p {
				margin: 5px 0;
			}

			.ys-shopline-error-details code {
				background: rgba(0,0,0,0.1);
				padding: 2px 6px;
				border-radius: 3px;
				font-size: 0.9em;
			}

			.ys-shopline-action-text {
				font-weight: 500;
			}

			.ys-shopline-notice-actions {
				margin-top: 15px;
				display: flex;
				gap: 10px;
				flex-wrap: wrap;
			}

			.ys-shopline-pay-button {
				background: #0073aa !important;
				color: #fff !important;
				border: none !important;
				padding: 10px 24px !important;
				font-size: 1em !important;
			}

			.ys-shopline-pay-button:hover {
				background: #005a87 !important;
			}

			@media (max-width: 600px) {
				.ys-shopline-notice {
					flex-direction: column;
					text-align: center;
				}

				.ys-shopline-notice-icon {
					margin: 0 auto 15px auto;
				}

				.ys-shopline-notice-actions {
					justify-content: center;
				}
			}
		</style>
		<?php
	}

	/**
	 * 儲存付款錯誤資訊到訂單
	 *
	 * 供其他類別呼叫，在付款失敗時儲存錯誤資訊
	 *
	 * @param \WC_Order $order      訂單物件
	 * @param string   $error_code 錯誤代碼
	 * @param string   $error_msg  錯誤訊息
	 */
	public static function save_payment_error( $order, $error_code, $error_msg ) {
		$order->update_meta_data( YSOrderMeta::ERROR_CODE, $error_code );
		$order->update_meta_data( YSOrderMeta::ERROR_MESSAGE, $error_msg );
		$order->save();
	}

	/**
	 * 清除付款錯誤資訊
	 *
	 * 供其他類別呼叫，在付款成功時清除錯誤資訊
	 *
	 * @param \WC_Order $order 訂單物件
	 */
	public static function clear_payment_error( $order ) {
		$order->delete_meta_data( YSOrderMeta::ERROR_CODE );
		$order->delete_meta_data( YSOrderMeta::ERROR_MESSAGE );
		$order->save();
	}
}
