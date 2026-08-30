<?php
/**
 * Virtual Account (ATM) Gateway for YS Shopline Payment.
 *
 * @package YangSheep\ShoplinePayment\Gateways
 */

namespace YangSheep\ShoplinePayment\Gateways;

defined( 'ABSPATH' ) || exit;

use YangSheep\ShoplinePayment\Utils\YSLogger;
use YangSheep\ShoplinePayment\Utils\YSOrderMeta;

/**
 * YSVirtualAccount Class.
 *
 * ATM/Bank transfer payment gateway.
 */
class YSVirtualAccount extends YSGatewayBase {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id                 = 'ys_shopline_atm';
		$this->icon               = '';
		$this->has_fields         = true;
		$this->method_title       = __( 'SHOPLINE ATM 銀行轉帳', 'ys-shopline-via-woocommerce' );
		$this->method_description = __( '透過 SHOPLINE Payment ATM 虛擬帳號付款', 'ys-shopline-via-woocommerce' );

		// Supports
		$this->supports = array(
			'products',
		);

		parent::__construct();
	}

	/**
	 * Get payment method for SDK.
	 *
	 * @return string
	 */
	public function get_payment_method() {
		return 'VirtualAccount';
	}

	/**
	 * Get icon HTML.
	 *
	 * @return string
	 */
	public function get_icon() {
		if ( ! $this->is_payment_icons_enabled() ) {
			return apply_filters( 'woocommerce_gateway_icon', '', $this->id );
		}

		$icon = '<img src="' . esc_url( YS_SHOPLINE_PLUGIN_URL . 'assets/images/atm.webp' ) . '" alt="ATM" style="max-height: 28px; margin-right: 5px;" />';

		return apply_filters( 'woocommerce_gateway_icon', $icon, $this->id );
	}

	/**
	 * 取得 ATM 繳費期限（分鐘）。
	 *
	 * 從全域 option 讀取（SHOPLINE 金流 > 支付方式頁面設定）。
	 *
	 * @return int
	 */
	public function get_expire_time() {
		return (int) get_option( 'ys_shopline_atm_expire_time', '4320' );
	}

	/**
	 * Payment fields.
	 */
	public function payment_fields() {
		if ( $this->description ) {
			echo wpautop( wp_kses_post( $this->description ) );
		}

		// Info message
		$expire_minutes = $this->get_expire_time();
		$expire_label   = $this->get_expire_label( $expire_minutes );
		echo '<p class="ys-shopline-atm-notice">';
		printf(
			/* translators: %s: Expire time label (e.g. "72 小時（3 天）") */
			esc_html__( '下單後將產生虛擬帳號，請於 %s 內完成轉帳。', 'ys-shopline-via-woocommerce' ),
			esc_html( $expire_label )
		);
		echo '</p>';

		// Container for SDK
		printf(
			'<div id="%s_container" class="ys-shopline-payment-container" data-gateway="%s" data-payment-method="%s" style="min-height: 50px;"></div>',
			esc_attr( $this->id ),
			esc_attr( $this->id ),
			esc_attr( $this->get_payment_method() )
		);
	}

	/**
	 * Prepare payment data.
	 *
	 * @param \WC_Order $order       Order object.
	 * @param string    $pay_session Pay session from SDK.
	 * @return array
	 */
	protected function prepare_payment_data( $order, $pay_session ) {
		$data = parent::prepare_payment_data( $order, $pay_session );

		// ATM 使用一般付款，不需要卡片綁定
		$data['confirm']['paymentBehavior'] = 'Regular';
		unset( $data['confirm']['paymentInstrument'] );

		// 設定繳費期限（分鐘）
		$data['expireTime'] = $this->get_expire_time();

		return $data;
	}

	/**
	 * 取得到期時間的顯示文字。
	 *
	 * @param int $minutes 到期分鐘數。
	 * @return string
	 */
	private function get_expire_label( $minutes ) {
		$labels = array(
			360   => __( '6 小時', 'ys-shopline-via-woocommerce' ),
			1440  => __( '24 小時（1 天）', 'ys-shopline-via-woocommerce' ),
			2880  => __( '48 小時（2 天）', 'ys-shopline-via-woocommerce' ),
			4320  => __( '72 小時（3 天）', 'ys-shopline-via-woocommerce' ),
		);

		return $labels[ $minutes ] ?? sprintf( __( '%d 分鐘', 'ys-shopline-via-woocommerce' ), $minutes );
	}

	/**
	 * Handle next action for ATM.
	 *
	 * ATM 需要 SDK 處理 nextAction（Confirm 類型）後才會產生虛擬帳號。
	 * 必須把 nextAction 傳回前端，讓 SDK 呼叫 payment.pay(nextAction)。
	 *
	 * @param \WC_Order $order    Order object.
	 * @param array     $response API response.
	 * @return array
	 */
	protected function handle_next_action( $order, $response ) {
		$order->update_meta_data( YSOrderMeta::NEXT_ACTION, $response['nextAction'] );

		// 使用管理員設定的等待付款狀態
		$pending_status = get_option( 'ys_shopline_order_status_pending', 'on-hold' );
		$order->update_status( $pending_status, __( 'Awaiting ATM payment.', 'ys-shopline-via-woocommerce' ) );

		// 預先儲存 VA 資訊（如果 create trade 就有返回的話）
		$this->store_virtual_account_info( $order, $response );

		$order->save();

		wc_maybe_reduce_stock_levels( $order->get_id() );
		WC()->cart->empty_cart();

		// 傳回 nextAction 給前端，讓 SDK 處理確認流程
		return array(
			'result'     => 'success',
			// v3.6.9：本覆寫版先前漏了下面兩個鍵——base 的 handle_next_action() 一直都有，
			// 只有 ATM 沒有。這裡是補上既有機制，不是新增機制。
			//
			// 🔴 remote_outcome：order-pay 的 ajax_pay_for_order() 以三態分流，
			// **缺值一律 fail-closed**（`elseif ( 'accepted' !== $outcome )`）。缺這個鍵時，
			// 顧客從訂單付款頁重試 ATM：交易已在 SHOPLINE 端建立（CREATED＋nextAction），
			// 前端卻收到「付款結果確認中」的 failure → processNextAction() 不執行 →
			// **payment.pay() 從不送出 → 虛擬帳號從不產生**。與瀏覽器中斷無關，必定重現。
			// 2026-08-30 沙盒實測（訂單 12525）即為此路徑；修正後（12526/12527）
			// pay() 正常送出、SDK 導向收銀台、交易由 CREATED 進入 CUSTOMER_ACTION 並配號。
			'remote_outcome' => 'accepted',
			'nextAction' => $response['nextAction'],
			'returnUrl'  => $this->get_return_url( $order ),
			// 🔴 failureUrl：SDK 實例被結帳更新換掉時，前端據此導向 pay-for-order 頁重試。
			// 缺這個鍵時顧客只會看到「請重新整理頁面」，而訂單其實已建立（隱形訂單）。
			'failureUrl' => $order->get_checkout_payment_url(),
			'orderId'    => $order->get_id(),
		);
	}

	/**
	 * 儲存虛擬帳號資訊至訂單 meta。
	 *
	 * API 欄位：recipientBankCode、recipientAccountNum、dueDate
	 *
	 * @param \WC_Order $order    Order object.
	 * @param array     $response API response.
	 */
	public function store_virtual_account_info( $order, $response ) {
		// API 回傳 virtualAccount 在 payment 物件內
		$va = $response['payment']['virtualAccount'] ?? null;

		if ( ! $va ) {
			return;
		}

		$bank_code = isset( $va['recipientBankCode'] ) ? $va['recipientBankCode'] : '';
		$account   = isset( $va['recipientAccountNum'] ) ? $va['recipientAccountNum'] : '';
		$expire    = isset( $va['dueDate'] ) ? $va['dueDate'] : '';

		$order->update_meta_data( YSOrderMeta::VA_BANK_CODE, $bank_code );
		$order->update_meta_data( YSOrderMeta::VA_ACCOUNT, $account );
		$order->update_meta_data( YSOrderMeta::VA_EXPIRE, $expire );

		// 寫入訂單備註供後台查看
		if ( $account ) {
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
		}

		YSLogger::debug( 'ATM virtual account info stored', array(
			'order_id'  => $order->get_id(),
			'bank_code' => $bank_code,
			'account'   => $account ? substr( $account, 0, 4 ) . '****' : '',
			'expire'    => $expire,
		) );
	}

	/**
	 * Thank you page - ATM 轉帳資訊由 YSOrderDisplay 統一渲染。
	 *
	 * @param int $order_id Order ID.
	 */
	public function thankyou_page( $order_id ) {
		// YSOrderDisplay 已在 woocommerce_before_thankyou hook 中渲染 ATM 轉帳資訊，
		// 此方法保留空實作以覆蓋 YSGatewayBase 的預設行為。
	}

	/**
	 * Email instructions - show virtual account info.
	 *
	 * @param \WC_Order $order         Order object.
	 * @param bool      $sent_to_admin Sent to admin.
	 * @param bool      $plain_text    Plain text email.
	 */
	public function email_instructions( $order, $sent_to_admin, $plain_text = false ) {
		if ( $order->get_payment_method() !== $this->id ) {
			return;
		}

		if ( $order->has_status( array( 'completed', 'processing' ) ) ) {
			return;
		}

		$bank_code = $order->get_meta( YSOrderMeta::VA_BANK_CODE );
		$account   = $order->get_meta( YSOrderMeta::VA_ACCOUNT );
		$expire    = $order->get_meta( YSOrderMeta::VA_EXPIRE );

		if ( ! $account ) {
			return;
		}

		if ( $plain_text ) {
			echo "\n" . esc_html__( 'ATM 轉帳付款', 'ys-shopline-via-woocommerce' ) . "\n";
			echo esc_html__( '請前往銀行櫃檯、ATM 或使用網路銀行，依下方資訊進行轉帳。', 'ys-shopline-via-woocommerce' ) . "\n\n";
			if ( $bank_code ) {
				echo esc_html__( '銀行代碼：', 'ys-shopline-via-woocommerce' ) . $bank_code . "\n";
			}
			echo esc_html__( '虛擬帳號：', 'ys-shopline-via-woocommerce' ) . $account . "\n";
			echo esc_html__( '轉帳金額：', 'ys-shopline-via-woocommerce' ) . wp_strip_all_tags( $order->get_formatted_order_total() ) . "\n";
			if ( $expire ) {
				echo esc_html__( '繳費期限：', 'ys-shopline-via-woocommerce' ) . $expire . "\n";
			}
			echo "\n";
		} else {
			?>
			<h2><?php esc_html_e( 'ATM 轉帳付款', 'ys-shopline-via-woocommerce' ); ?></h2>
			<p><?php esc_html_e( '請前往銀行櫃檯、ATM 或使用網路銀行，依下方資訊進行轉帳。', 'ys-shopline-via-woocommerce' ); ?></p>
			<table cellspacing="0" cellpadding="6" style="width: 100%; border: 1px solid #eee;" border="1">
				<?php if ( $bank_code ) : ?>
				<tr>
					<th style="text-align: left; border: 1px solid #eee;"><?php esc_html_e( '銀行代碼', 'ys-shopline-via-woocommerce' ); ?></th>
					<td style="text-align: left; border: 1px solid #eee;"><strong><?php echo esc_html( $bank_code ); ?></strong></td>
				</tr>
				<?php endif; ?>
				<tr>
					<th style="text-align: left; border: 1px solid #eee;"><?php esc_html_e( '虛擬帳號', 'ys-shopline-via-woocommerce' ); ?></th>
					<td style="text-align: left; border: 1px solid #eee;"><strong style="font-family: monospace;"><?php echo esc_html( $account ); ?></strong></td>
				</tr>
				<tr>
					<th style="text-align: left; border: 1px solid #eee;"><?php esc_html_e( '轉帳金額', 'ys-shopline-via-woocommerce' ); ?></th>
					<td style="text-align: left; border: 1px solid #eee;"><strong><?php echo wp_kses_post( $order->get_formatted_order_total() ); ?></strong></td>
				</tr>
				<?php if ( $expire ) : ?>
				<tr>
					<th style="text-align: left; border: 1px solid #eee;"><?php esc_html_e( '繳費期限', 'ys-shopline-via-woocommerce' ); ?></th>
					<td style="text-align: left; border: 1px solid #eee;"><?php echo esc_html( $expire ); ?></td>
				</tr>
				<?php endif; ?>
			</table>
			<p><?php esc_html_e( '請於繳費期限前完成轉帳，逾期此虛擬帳號將失效。', 'ys-shopline-via-woocommerce' ); ?></p>
			<?php
		}
	}
}
