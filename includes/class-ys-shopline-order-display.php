<?php
/**
 * Order display enhancements for YS Shopline Payment.
 *
 * 處理訂單頁面的顯示：付款成功/失敗的醒目提示
 *
 * @package YS_Shopline_Payment
 */

defined( 'ABSPATH' ) || exit;

/**
 * YS_Shopline_Order_Display Class.
 *
 * 增強訂單頁面的顯示，包括：
 * - 付款失敗時顯示錯誤訊息和重新付款連結
 * - 付款成功時顯示付款完成和付款方式資訊
 */
class YS_Shopline_Order_Display {

	/**
	 * Singleton instance.
	 *
	 * @var YS_Shopline_Order_Display
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return YS_Shopline_Order_Display
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

		// 付款成功
		if ( $order->is_paid() ) {
			$this->render_success_notice( $order );
			return;
		}

		// 付款失敗或等待付款
		if ( in_array( $status, array( 'failed', 'pending', 'on-hold' ), true ) ) {
			$this->render_pending_or_failed_notice( $order );
			return;
		}
	}

	/**
	 * 渲染付款成功通知
	 *
	 * @param WC_Order $order 訂單物件
	 */
	private function render_success_notice( $order ) {
		$trade_order_id = $order->get_meta( '_ys_shopline_trade_order_id' );
		$payment_method = $order->get_meta( '_ys_shopline_payment_method' );
		$payment_detail = $order->get_meta( '_ys_shopline_payment_detail' );

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
	 * @param WC_Order $order 訂單物件
	 */
	private function render_pending_or_failed_notice( $order ) {
		$status = $order->get_status();

		// 取得錯誤資訊
		$error_code = $order->get_meta( '_ys_shopline_error_code' );
		$error_msg  = $order->get_meta( '_ys_shopline_error_message' );

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
			'CreditCard'    => __( '信用卡', 'ys-shopline-via-woocommerce' ),
			'LinePay'       => 'LINE Pay',
			'JkoPay'        => __( '街口支付', 'ys-shopline-via-woocommerce' ),
			'ApplePay'      => 'Apple Pay',
			'VirtualAtm'    => __( '虛擬帳號', 'ys-shopline-via-woocommerce' ),
			'ChaileaseBnpl' => __( '中租零卡分期', 'ys-shopline-via-woocommerce' ),
		);

		return isset( $methods[ $payment_method ] ) ? $methods[ $payment_method ] : ( $payment_method ?: __( '線上付款', 'ys-shopline-via-woocommerce' ) );
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
	 * @param WC_Order $order      訂單物件
	 * @param string   $error_code 錯誤代碼
	 * @param string   $error_msg  錯誤訊息
	 */
	public static function save_payment_error( $order, $error_code, $error_msg ) {
		$order->update_meta_data( '_ys_shopline_error_code', $error_code );
		$order->update_meta_data( '_ys_shopline_error_message', $error_msg );
		$order->save();
	}

	/**
	 * 清除付款錯誤資訊
	 *
	 * 供其他類別呼叫，在付款成功時清除錯誤資訊
	 *
	 * @param WC_Order $order 訂單物件
	 */
	public static function clear_payment_error( $order ) {
		$order->delete_meta_data( '_ys_shopline_error_code' );
		$order->delete_meta_data( '_ys_shopline_error_message' );
		$order->save();
	}
}
