<?php
/**
 * Admin order/subscription SHOPLINE payment panels.
 *
 * @package YangSheep\ShoplinePayment\Admin
 */

declare(strict_types=1);

namespace YangSheep\ShoplinePayment\Admin;

defined( 'ABSPATH' ) || exit;

use YangSheep\ShoplinePayment\Utils\YSOrderMeta;
use WC_Payment_Tokens;

/**
 * Shows local SHOPLINE payment and subscription binding data on WooCommerce admin order screens.
 */
final class YSOrderPaymentAdmin {

	/**
	 * Register admin hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		$instance = new self();

		add_action( 'add_meta_boxes', array( $instance, 'register_meta_boxes' ), 20, 2 );
		add_action( 'admin_footer', array( $instance, 'render_subscription_binding_position_script' ) );
		// v3.5.36: abandoned-paid 需人工處理告警（訂單編輯頁顯眼橫幅）＋結案動作
		add_action( 'admin_notices', array( $instance, 'render_manual_review_notice' ) );
		add_filter( 'woocommerce_order_actions', array( $instance, 'add_resolve_review_order_action' ), 10, 2 );
		add_action( 'woocommerce_order_action_ys_shopline_resolve_review', array( $instance, 'handle_resolve_review_order_action' ) );
		// v3.5.36 Review P2-6：訂單列表欄位（HPOS＋傳統）——全域一覽待人工處理訂單
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( $instance, 'add_review_list_column' ) );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( $instance, 'render_review_list_column_hpos' ), 10, 2 );
		add_filter( 'manage_edit-shop_order_columns', array( $instance, 'add_review_list_column' ) );
		add_action( 'manage_shop_order_posts_custom_column', array( $instance, 'render_review_list_column_legacy' ), 10, 2 );
		// v3.6.8：退款在途時提供「立即查詢退款結果」按鈕（AJAX），免得商家只能乾等排程。
		add_action( 'wp_ajax_ys_shopline_refund_recheck', array( $instance, 'ajax_refund_recheck' ) );
	}

	/**
	 * 手動重查退款結果（AJAX）。
	 *
	 * 權限採 WooCommerce 訂單編輯權限；nonce 綁定訂單 ID，避免跨單重放。
	 * 實際收斂交給 YSRefundReconciliation::manual_recheck()（含訂單鎖，
	 * 在途時不推進排程 stage）。
	 */
	public function ajax_refund_recheck(): void {
		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;

		if ( ! $order_id || ! current_user_can( 'edit_shop_orders' ) ) {
			wp_send_json_error( array(
				'message' => __( '權限不足，無法查詢此訂單的退款結果。', 'ys-shopline-via-woocommerce' ),
			), 403 );
		}

		check_ajax_referer( 'ys_shopline_refund_recheck_' . $order_id, 'nonce' );

		$result = \YangSheep\ShoplinePayment\Handlers\YSRefundReconciliation::manual_recheck( $order_id );

		wp_send_json_success( array(
			'state'        => (string) ( $result['state'] ?? 'pending' ),
			'message'      => (string) ( $result['message'] ?? '' ),
			'order_status' => (string) ( $result['order_status'] ?? '' ),
			// 已收斂（成功／失敗／轉人工）時請前端重新載入，讓訂單狀態、
			// 退款單與金額一次反映到 WooCommerce 原生 UI。
			'reload'       => in_array( (string) ( $result['state'] ?? '' ), array( 'succeeded', 'failed', 'review' ), true ),
		) );
	}

	/**
	 * 是否有進行中的 SHOPLINE 退款（供按鈕顯示條件）。
	 */
	public static function has_active_refund_attempt( \WC_Order $order ): bool {
		$attempt = $order->get_meta( YSOrderMeta::REFUND_CONFIRMATION_DATA );
		return is_array( $attempt ) && ! empty( $attempt['refund_reference'] );
	}

	/**
	 * v3.5.36 P2-6：訂單列表加「SLP審核」欄。
	 *
	 * @param array $columns 現有欄位。
	 * @return array
	 */
	public function add_review_list_column( $columns ) {
		if ( is_array( $columns ) ) {
			$columns['ys_shopline_review'] = __( 'SLP審核', 'ys-shopline-via-woocommerce' );
		}
		return $columns;
	}

	/**
	 * HPOS 欄位渲染。
	 *
	 * @param string          $column 欄位鍵。
	 * @param \WC_Order|mixed  $order  訂單。
	 * @return void
	 */
	public function render_review_list_column_hpos( $column, $order ): void {
		if ( 'ys_shopline_review' === $column && $order instanceof \WC_Order ) {
			echo wp_kses_post( $this->review_cell_html( $order ) );
		}
	}

	/**
	 * 傳統（post）欄位渲染。
	 *
	 * @param string $column  欄位鍵。
	 * @param int    $post_id 訂單 post ID。
	 * @return void
	 */
	public function render_review_list_column_legacy( $column, $post_id ): void {
		if ( 'ys_shopline_review' !== $column ) {
			return;
		}
		$order = wc_get_order( $post_id );
		if ( $order instanceof \WC_Order ) {
			echo wp_kses_post( $this->review_cell_html( $order ) );
		}
	}

	/**
	 * 產生審核欄 HTML（🔴 待處理／✔ 已結案／空）。
	 *
	 * @param \WC_Order $order 訂單。
	 * @return string
	 */
	private function review_cell_html( \WC_Order $order ): string {
		$flag = (string) $order->get_meta( YSOrderMeta::MANUAL_REVIEW_FLAG );
		$confirmation_review = self::has_open_confirmation_review( $order );
		$refund_review       = self::has_open_refund_review( $order );
		if ( '' === $flag && ! $confirmation_review && ! $refund_review ) {
			return '';
		}
		if ( ! $confirmation_review && ! $refund_review && (int) $order->get_meta( YSOrderMeta::MANUAL_REVIEW_RESOLVED ) > 0 ) {
			return '<span title="' . esc_attr__( 'SHOPLINE 付款審核已結案', 'ys-shopline-via-woocommerce' ) . '">✔️</span>';
		}
		return '<span style="color:#d63638;font-weight:bold" title="' . esc_attr__( 'SHOPLINE 付款需人工處理', 'ys-shopline-via-woocommerce' ) . '">🔴</span>';
	}

	/**
	 * Confirmation timeouts stay open until an exact payment result converges.
	 */
	public static function has_open_confirmation_review( \WC_Order $order ): bool {
		$review = $order->get_meta( YSOrderMeta::CONFIRMATION_REVIEW );
		return is_array( $review ) && 'confirmation_timeout' === (string) ( $review['type'] ?? '' );
	}

	/**
	 * Whether this order has an unresolved remote/local refund difference.
	 */
	public static function has_open_refund_review( \WC_Order $order ): bool {
		$review = $order->get_meta( YSOrderMeta::REFUND_REVIEW );
		return is_array( $review )
			&& '' !== (string) ( $review['type'] ?? '' )
			&& (int) ( $review['resolved_at'] ?? 0 ) <= 0;
	}

	/**
	 * v3.5.36 P2：在訂單「動作」下拉加入「標記 SHOPLINE 人工審核已結案」（僅在有待處理旗標且未結案時）。
	 *
	 * @param array           $actions 現有動作。
	 * @param \WC_Order|mixed  $order   訂單（WC 版本差異，可能未傳）。
	 * @return array
	 */
	public function add_resolve_review_order_action( $actions, $order = null ) {
		if ( ! $order instanceof \WC_Order ) {
			return $actions;
		}
		$flag          = (string) $order->get_meta( YSOrderMeta::MANUAL_REVIEW_FLAG );
		$resolved      = (int) $order->get_meta( YSOrderMeta::MANUAL_REVIEW_RESOLVED );
		$refund_review = self::has_open_refund_review( $order );
		if ( ( '' !== $flag && $resolved <= 0 ) || $refund_review ) {
			$actions['ys_shopline_resolve_review'] = __( '標記 SHOPLINE 人工審核已結案', 'ys-shopline-via-woocommerce' );
		}
		return $actions;
	}

	/**
	 * v3.5.36 P2：執行「結案」——寫入結案時間戳，之後不再列入待處理、不再顯示告警橫幅。
	 *
	 * @param \WC_Order $order 訂單。
	 * @return void
	 */
	public function handle_resolve_review_order_action( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$resolved_at = time();
		$order->update_meta_data( YSOrderMeta::MANUAL_REVIEW_RESOLVED, $resolved_at );
		$refund_review = $order->get_meta( YSOrderMeta::REFUND_REVIEW );
		if ( is_array( $refund_review ) && '' !== (string) ( $refund_review['type'] ?? '' ) ) {
			$refund_review['resolved_at'] = $resolved_at;
			$refund_review['resolved_by'] = get_current_user_id();
			$order->update_meta_data( YSOrderMeta::REFUND_REVIEW, $refund_review );

			$attempt = $order->get_meta( YSOrderMeta::REFUND_CONFIRMATION_DATA );
			if ( is_array( $attempt ) ) {
				$history = $order->get_meta( YSOrderMeta::REFUND_HISTORY );
				$history = is_array( $history ) ? $history : array();
				$history[] = array(
					'reference'       => (string) ( $attempt['refund_reference'] ?? '' ),
					'refund_order_id' => (string) ( $attempt['refund_order_id'] ?? '' ),
					'status'          => (string) ( $attempt['last_status'] ?? '' ),
					'event'           => 'manual_review_resolved',
					'source'          => 'admin_order_action',
					'wc_refund_id'    => 0,
					'ts'              => $resolved_at,
				);
				$order->update_meta_data( YSOrderMeta::REFUND_HISTORY, $history );
				$order->delete_meta_data( YSOrderMeta::REFUND_CONFIRMATION_DATA );
			}
		}
		$order->add_order_note( sprintf(
			/* translators: %s: current user display name */
			__( 'SHOPLINE 付款／退款人工審核已由 %s 標記結案。', 'ys-shopline-via-woocommerce' ),
			wp_get_current_user()->display_name ?: 'admin'
		) );
		$order->save();
	}

	/**
	 * v3.5.36: 在訂單編輯頁顯示「需人工處理」告警（abandoned-trade 事後收款）。
	 *
	 * 旗標由 YSWebhookHandler::guard_abandoned_trade_paid 持久化於訂單 meta，
	 * 後台亦可用 wc_get_orders( array( 'meta_key' => MANUAL_REVIEW_FLAG ) ) 追蹤全部待處理訂單。
	 *
	 * @return void
	 */
	public function render_manual_review_notice(): void {
		$order = $this->resolve_current_screen_order();
		if ( ! $order ) {
			return;
		}

		$flag                = (string) $order->get_meta( YSOrderMeta::MANUAL_REVIEW_FLAG );
		$confirmation_review = self::has_open_confirmation_review( $order );
		$refund_review       = self::has_open_refund_review( $order );
		if ( '' === $flag && ! $confirmation_review && ! $refund_review ) {
			return;
		}
		// v3.5.36 P2：已結案 → 不再顯示告警橫幅
		if ( ! $confirmation_review && ! $refund_review && (int) $order->get_meta( YSOrderMeta::MANUAL_REVIEW_RESOLVED ) > 0 ) {
			return;
		}

		$messages = array();
		if ( $confirmation_review ) {
			$messages[] = __( '本訂單的付款結果在自動查詢期間仍無法確認，系統已持續鎖定重新付款。請先至 SHOPLINE Payments 核對該筆 reference／交易狀態，再決定入帳、取消或重新開放付款；未確認前請勿要求顧客重付。確認結果收斂後，此待辦會自動清除。', 'ys-shopline-via-woocommerce' );
		}
		if ( $refund_review ) {
			$review = $order->get_meta( YSOrderMeta::REFUND_REVIEW );
			switch ( (string) ( $review['type'] ?? '' ) ) {
				case 'local_refund_creation_failed':
					$messages[] = __( 'SHOPLINE 已確認退款成功，但 WooCommerce 退款補登失敗。系統不會再次呼叫遠端退款；請核對 SHOPLINE 後人工補登 WooCommerce 退款。', 'ys-shopline-via-woocommerce' );
					break;
				case 'remote_envelope_mismatch':
					$messages[] = __( 'SHOPLINE 退款回應與本次退款資料不一致，系統已停止自動補登。請核對退款編號、參考編號、金額與幣別，確認前請勿再次退款。', 'ys-shopline-via-woocommerce' );
					break;
				case 'reconcile_schedule_failed':
					$messages[] = __( 'SHOPLINE 退款已送出，但自動確認排程建立失敗。WooCommerce 尚未建立退款；請至 SHOPLINE 後台核對，確認前請勿再次退款。', 'ys-shopline-via-woocommerce' );
					break;
				case 'remote_status_unresolved':
				default:
					$messages[] = __( 'SHOPLINE 退款在自動查詢期限內仍無法確認，可能已退款但 WooCommerce 尚未入帳。請至 SHOPLINE 後台核對，確認前請勿再次退款。', 'ys-shopline-via-woocommerce' );
					break;
			}
		}

		$manual_review_open = '' !== $flag && (int) $order->get_meta( YSOrderMeta::MANUAL_REVIEW_RESOLVED ) <= 0;
		if ( $manual_review_open ) {
			switch ( $flag ) {
			case 'paid_no_current_trade':
				$messages[] = __( '本訂單目前無現行有效交易，但有一筆「已棄用交易」事後完成收款——顧客實際已付款、訂單卻未入帳。系統不會自動入帳，請人工決定確認入帳或辦理退款。', 'ys-shopline-via-woocommerce' );
				break;
			case 'abandoned_paid_current_exists':
				$messages[] = __( '本訂單有一筆「已棄用交易」已實際收款，且訂單另有一筆現行交易（其狀態請至 SHOPLINE 後台核對）。棄用交易才是已確認的實際收款，請勿盲目退舊；請核對現行交易狀態後決定以哪一筆入帳、另一筆退款。', 'ys-shopline-via-woocommerce' );
				break;
			case 'duplicate_paid':
			default:
				$messages[] = __( '本訂單現行交易已付款，但有一筆「已棄用交易」事後也完成收款——屬重複收款。請對「已棄用交易」辦理退款。', 'ys-shopline-via-woocommerce' );
				break;
			}
			$messages[] = __( '處理棄用交易後，可於「訂單動作」選「標記 SHOPLINE 付款審核已結案」。', 'ys-shopline-via-woocommerce' );
		}
		$msg = implode( ' ', $messages );

		printf(
			'<div class="notice notice-error"><p><strong>%s</strong>%s</p></div>',
			esc_html__( '🔴 SHOPLINE 付款／退款需人工處理：', 'ys-shopline-via-woocommerce' ),
			esc_html( $msg )
		);
	}

	/**
	 * v3.5.36: 解析目前訂單編輯畫面對應的訂單（相容 HPOS ?id= 與傳統 ?post=）。
	 *
	 * @return \WC_Order|null
	 */
	private function resolve_current_screen_order() {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return null;
		}
		$screen = get_current_screen();
		if ( ! $screen ) {
			return null;
		}

		$is_order_screen = in_array( $screen->id, array( 'shop_order', 'woocommerce_page_wc-orders' ), true )
			|| ( isset( $screen->post_type ) && 'shop_order' === $screen->post_type );
		if ( ! $is_order_screen ) {
			return null;
		}

		// 唯讀顯示閘門，僅據以決定是否顯示告警，不執行任何動作。
		$order_id = 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['id'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$order_id = absint( wp_unslash( $_GET['id'] ) );
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		} elseif ( isset( $_GET['post'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$order_id = absint( wp_unslash( $_GET['post'] ) );
		}
		if ( ! $order_id ) {
			return null;
		}

		$order = wc_get_order( $order_id );
		return $order ? $order : null;
	}

	/**
	 * Register the payment summary meta box for legacy and HPOS order screens.
	 *
	 * @param string              $post_type Current post type/screen context.
	 * @param \WP_Post|mixed|null $post      Current post object.
	 * @return void
	 */
	public function register_meta_boxes( $post_type, $post = null ): void {
		$order = $this->resolve_order( $post );
		if ( ! $order ) {
			return;
		}

		$screens = array(
			'shop_order',
			'shop_subscription',
			'woocommerce_page_wc-orders',
			'woocommerce_page_wc-orders--shop_subscription',
		);

		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$screens[] = wc_get_page_screen_id( 'shop-order' );
			$screens[] = wc_get_page_screen_id( 'shop_subscription' );
		}

		$screens = array_unique( array_filter( $screens ) );

		if ( $this->should_show_for_order( $order ) ) {
			foreach ( $screens as $screen ) {
				add_meta_box(
					'ys-shopline-order-payment-admin',
					__( 'SHOPLINE 訂單付款資訊', 'ys-shopline-via-woocommerce' ),
					array( $this, 'render_meta_box' ),
					$screen,
					'normal',
					'low'
				);
			}
		}

		if ( $this->should_show_subscription_binding( $order ) ) {
			foreach ( $screens as $screen ) {
				add_meta_box(
					'ys-shopline-subscription-binding-admin',
					__( '訂閱綁定信用卡', 'ys-shopline-via-woocommerce' ),
					array( $this, 'render_subscription_binding_meta_box' ),
					$screen,
					'normal',
					'core'
				);
			}
		}
	}

	/**
	 * Render the minimal order payment summary meta box.
	 *
	 * @param \WP_Post|\WC_Abstract_Order|mixed $post_or_order Current screen object.
	 * @return void
	 */
	public function render_meta_box( $post_or_order ): void {
		$order = $this->resolve_order( $post_or_order );
		if ( ! $order || ! $this->current_user_can_edit_order( $order ) || ! $this->should_show_for_order( $order ) ) {
			return;
		}

		$this->render_styles();
		?>
		<div class="ys-shopline-order-admin-panel ys-shopline-order-admin-payment-summary">
			<?php $this->render_order_payment_overview( $order, false ); ?>
		</div>
		<?php
	}

	/**
	 * Render order payment details with only method, status, and ID.
	 *
	 * @param \WC_Order|\WC_Subscription $order        Order object.
	 * @param bool                       $show_heading Whether to render an inline heading.
	 * @return void
	 */
	private function render_order_payment_overview( $order, bool $show_heading = true ): void {
		$rows = $this->get_order_payment_rows( $order );
		?>
		<?php if ( $show_heading ) : ?>
			<h4><?php esc_html_e( '訂單付款資訊', 'ys-shopline-via-woocommerce' ); ?></h4>
		<?php endif; ?>
		<?php if ( empty( $rows ) ) : ?>
			<p class="ys-shopline-order-admin-empty"><?php esc_html_e( '此訂單目前沒有 SHOPLINE 付款資料。', 'ys-shopline-via-woocommerce' ); ?></p>
			<?php return; ?>
		<?php endif; ?>
		<table class="ys-shopline-order-admin-table ys-shopline-order-admin-box-table ys-shopline-order-admin-kv">
			<tbody>
				<?php foreach ( $rows as $label => $value ) : ?>
					<tr>
						<th><?php echo esc_html( (string) $label ); ?></th>
						<td>
							<?php if ( '付款編號' === (string) $label ) : ?>
								<code><?php echo esc_html( (string) $value ); ?></code>
							<?php else : ?>
								<?php echo esc_html( (string) $value ); ?>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		$this->render_refund_recheck_button( $order );
	}

	/**
	 * 退款在途時，於付款資訊區塊顯示「立即查詢退款結果」按鈕。
	 *
	 * 排程本來就會自動確認（30 秒／1／3／5／10 分鐘／1／6 小時），此按鈕只是讓商家
	 * 不必乾等；收斂成功／失敗後重新載入頁面，讓訂單狀態與退款單反映到原生 UI。
	 *
	 * @param \WC_Order|mixed $order 訂單物件。
	 */
	private function render_refund_recheck_button( $order ): void {
		if ( ! $order instanceof \WC_Order || ! self::has_active_refund_attempt( $order ) ) {
			return;
		}

		$attempt   = $order->get_meta( YSOrderMeta::REFUND_CONFIRMATION_DATA );
		$reference = is_array( $attempt ) ? (string) ( $attempt['refund_reference'] ?? '' ) : '';
		$status    = is_array( $attempt ) ? (string) ( $attempt['last_status'] ?? '' ) : '';
		$order_id  = $order->get_id();
		?>
		<div class="ys-shopline-refund-recheck" style="margin-top:12px;padding:10px;border:1px solid #dcdcde;border-left:4px solid #dba617;background:#fcf9e8;">
			<p style="margin:0 0 8px;">
				<strong><?php esc_html_e( 'SHOPLINE 退款確認中', 'ys-shopline-via-woocommerce' ); ?></strong><br>
				<?php esc_html_e( '退款請求已送出 API，但尚未收到確認結果。確認成功後才會建立 WooCommerce 退款單並回補庫存。', 'ys-shopline-via-woocommerce' ); ?><br>
				<?php if ( '' !== $reference ) : ?>
					<span><?php esc_html_e( '退款參考編號：', 'ys-shopline-via-woocommerce' ); ?><code><?php echo esc_html( $reference ); ?></code></span><br>
				<?php endif; ?>
				<?php if ( '' !== $status ) : ?>
					<span><?php esc_html_e( '最後回報狀態：', 'ys-shopline-via-woocommerce' ); ?><code><?php echo esc_html( $status ); ?></code></span>
				<?php endif; ?>
			</p>
			<button type="button" class="button" id="ys-shopline-refund-recheck-btn"><?php esc_html_e( '立即查詢退款結果', 'ys-shopline-via-woocommerce' ); ?></button>
			<span id="ys-shopline-refund-recheck-msg" style="margin-left:8px;"></span>
		</div>
		<script>
		( function () {
			var btn = document.getElementById( 'ys-shopline-refund-recheck-btn' );
			var msg = document.getElementById( 'ys-shopline-refund-recheck-msg' );
			if ( ! btn ) { return; }
			btn.addEventListener( 'click', function () {
				btn.disabled = true;
				msg.textContent = <?php echo wp_json_encode( __( '查詢中…', 'ys-shopline-via-woocommerce' ) ); ?>;
				var body = new URLSearchParams();
				body.append( 'action', 'ys_shopline_refund_recheck' );
				body.append( 'order_id', <?php echo (int) $order_id; ?> );
				body.append( 'nonce', <?php echo wp_json_encode( wp_create_nonce( 'ys_shopline_refund_recheck_' . $order_id ) ); ?> );
				fetch( <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>, {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
					body: body.toString()
				} ).then( function ( r ) { return r.json(); } ).then( function ( res ) {
					var data = ( res && res.data ) ? res.data : {};
					msg.textContent = data.message || '';
					if ( data.reload ) {
						msg.textContent += <?php echo wp_json_encode( __( '（重新載入中…）', 'ys-shopline-via-woocommerce' ) ); ?>;
						window.location.reload();
						return;
					}
					btn.disabled = false;
				} ).catch( function () {
					msg.textContent = <?php echo wp_json_encode( __( '查詢失敗，請稍後再試。', 'ys-shopline-via-woocommerce' ) ); ?>;
					btn.disabled = false;
				} );
			} );
		} )();
		</script>
		<?php
	}

	/**
	 * Render subscription card binding as an independent meta box.
	 *
	 * @param \WP_Post|\WC_Abstract_Order|mixed $post_or_order Current screen object.
	 * @return void
	 */
	public function render_subscription_binding_meta_box( $post_or_order ): void {
		$order = $this->resolve_order( $post_or_order );
		if ( ! $order || ! $this->current_user_can_edit_order( $order ) || ! $this->should_show_subscription_binding( $order ) ) {
			return;
		}

		$user_id       = (int) $order->get_user_id();
		$cards         = $user_id ? $this->get_local_cards( $user_id ) : array();
		$cards_by_inst = $this->index_cards_by_instrument( $cards );

		$this->render_styles();
		?>
		<div class="ys-shopline-order-admin-panel ys-shopline-order-admin-subscription-binding">
			<?php $this->render_subscription_binding( array( $order ), $cards_by_inst, false ); ?>
		</div>
		<?php
	}

	/**
	 * Position the independent subscription card box below subscription data and above order items.
	 *
	 * @return void
	 */
	public function render_subscription_binding_position_script(): void {
		$order = $this->resolve_order();
		if ( ! $order || ! $this->current_user_can_edit_order( $order ) || ! $this->should_show_subscription_binding( $order ) ) {
			return;
		}
		?>
		<script>
			document.addEventListener('DOMContentLoaded', function () {
				var box = document.getElementById('ys-shopline-subscription-binding-admin');
				var subscriptionData = document.getElementById('woocommerce-subscription-data');
				var orderItems = document.getElementById('woocommerce-order-items');

				if (!box || !subscriptionData || !orderItems || subscriptionData.parentNode !== orderItems.parentNode) {
					return;
				}

				subscriptionData.insertAdjacentElement('afterend', box);
			});
		</script>
		<?php
	}

	/**
	 * Resolve a WooCommerce order/subscription from admin context.
	 *
	 * @param mixed $source Source object.
	 * @return \WC_Order|\WC_Subscription|null
	 */
	private function resolve_order( $source = null ) {
		if ( $source instanceof \WC_Abstract_Order ) {
			return $source;
		}

		if ( $source instanceof \WP_Post ) {
			$order = wc_get_order( $source->ID );
			return $order ?: null;
		}

		$order_id = 0;
		if ( isset( $_GET['id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$order_id = absint( wp_unslash( $_GET['id'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		} elseif ( isset( $_GET['post'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$order_id = absint( wp_unslash( $_GET['post'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		if ( ! $order_id || ! function_exists( 'wc_get_order' ) ) {
			return null;
		}

		$order = wc_get_order( $order_id );
		return $order ?: null;
	}

	/**
	 * Only show the summary on SHOPLINE order/subscription contexts.
	 *
	 * @param \WC_Order|\WC_Subscription $order Order object.
	 * @return bool
	 */
	private function should_show_for_order( $order ): bool {
		if ( $this->is_subscription( $order ) ) {
			return false;
		}

		$payment_method = (string) $order->get_payment_method();
		if ( 0 === strpos( $payment_method, 'ys_shopline_' ) ) {
			return true;
		}

		return (bool) $order->get_meta( YSOrderMeta::PAYMENT_DETAIL )
			|| (bool) $order->get_meta( YSOrderMeta::TRADE_ORDER_ID );
	}

	/**
	 * Only render subscription binding on the subscription order itself.
	 *
	 * @param \WC_Order|\WC_Subscription $order Order object.
	 * @return bool
	 */
	private function should_show_subscription_binding( $order ): bool {
		if ( ! $this->is_subscription( $order ) ) {
			return false;
		}

		$payment_method = (string) $order->get_payment_method();
		return 0 === strpos( $payment_method, 'ys_shopline_' )
			|| (bool) $order->get_meta( YSOrderMeta::PAYMENT_INSTRUMENT_ID );
	}

	/**
	 * Check whether the current admin can edit this order/subscription.
	 *
	 * @param \WC_Order|\WC_Subscription $order Order object.
	 * @return bool
	 */
	private function current_user_can_edit_order( $order ): bool {
		$order_id = $order->get_id();
		$cap      = $this->is_subscription( $order ) ? 'edit_shop_subscription' : 'edit_shop_order';

		return current_user_can( $cap, $order_id )
			|| current_user_can( 'edit_shop_order', $order_id )
			|| current_user_can( 'edit_shop_orders' )
			|| current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Render subscription card bindings.
	 *
	 * @param array<int, \WC_Subscription|\WC_Order> $subscriptions       Subscriptions.
	 * @param array<string, array<string, mixed>>    $cards_by_instrument Cards by instrument ID.
	 * @param bool                                   $show_heading        Whether to render an inline heading.
	 * @return void
	 */
	private function render_subscription_binding( array $subscriptions, array $cards_by_instrument, bool $show_heading = true ): void {
		if ( empty( $subscriptions ) ) {
			return;
		}
		?>
		<?php if ( $show_heading ) : ?>
			<h4><?php esc_html_e( '訂閱綁定信用卡', 'ys-shopline-via-woocommerce' ); ?></h4>
		<?php endif; ?>
		<table class="ys-shopline-order-admin-table ys-shopline-order-admin-box-table ys-shopline-order-admin-subscription-table">
			<thead>
				<tr>
					<th><?php esc_html_e( '訂閱', 'ys-shopline-via-woocommerce' ); ?></th>
					<th><?php esc_html_e( '狀態', 'ys-shopline-via-woocommerce' ); ?></th>
					<th><?php esc_html_e( '付款方式', 'ys-shopline-via-woocommerce' ); ?></th>
					<th><?php esc_html_e( '關聯信用卡', 'ys-shopline-via-woocommerce' ); ?></th>
					<th><?php esc_html_e( '綁定狀態', 'ys-shopline-via-woocommerce' ); ?></th>
					<th><?php esc_html_e( '下次扣款', 'ys-shopline-via-woocommerce' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $subscriptions as $subscription ) : ?>
					<?php
					$instrument_id = (string) $subscription->get_meta( YSOrderMeta::PAYMENT_INSTRUMENT_ID );
					$linked_card   = $instrument_id && isset( $cards_by_instrument[ $instrument_id ] )
						? $cards_by_instrument[ $instrument_id ]
						: null;
					$binding       = $this->resolve_subscription_binding_status( $instrument_id, $linked_card );
					?>
					<tr>
						<td>
							<?php if ( method_exists( $subscription, 'get_edit_order_url' ) ) : ?>
								<a href="<?php echo esc_url( $subscription->get_edit_order_url() ); ?>">#<?php echo esc_html( (string) $subscription->get_id() ); ?></a>
							<?php else : ?>
								#<?php echo esc_html( (string) $subscription->get_id() ); ?>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( function_exists( 'wc_get_order_status_name' ) ? wc_get_order_status_name( (string) $subscription->get_status() ) : (string) $subscription->get_status() ); ?></td>
						<td><?php echo esc_html( $subscription->get_payment_method_title() ?: $subscription->get_payment_method() ); ?></td>
						<td><?php $this->render_bound_card_cell( $instrument_id, $linked_card ); ?></td>
						<td><?php $this->render_status_badge( $binding['label'], $binding['class'] ); ?></td>
						<td><?php echo esc_html( $this->format_subscription_next_payment( $subscription ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Read local WooCommerce SHOPLINE card tokens.
	 *
	 * @param int $user_id User ID.
	 * @return array<int, array<string, mixed>>
	 */
	private function get_local_cards( int $user_id ): array {
		if ( ! class_exists( WC_Payment_Tokens::class ) ) {
			return array();
		}

		$tokens = WC_Payment_Tokens::get_customer_tokens( $user_id, YSOrderMeta::CREDIT_GATEWAY_ID );
		$cards  = array();

		foreach ( $tokens as $token ) {
			$month      = method_exists( $token, 'get_expiry_month' ) ? (string) $token->get_expiry_month() : '';
			$year       = method_exists( $token, 'get_expiry_year' ) ? (string) $token->get_expiry_year() : '';
			$is_expired = $this->is_card_expired( $month, $year );
			$is_default = method_exists( $token, 'get_is_default' )
				? (bool) $token->get_is_default()
				: ( method_exists( $token, 'is_default' ) && (bool) $token->is_default() );

			$cards[] = array(
				'token_id'      => $token->get_id(),
				'instrument_id' => $token->get_token(),
				'brand'         => method_exists( $token, 'get_card_type' ) ? (string) $token->get_card_type() : '',
				'last4'         => method_exists( $token, 'get_last4' ) ? (string) $token->get_last4() : '',
				'expiry'        => $month && $year ? sprintf( '%s/%s', str_pad( $month, 2, '0', STR_PAD_LEFT ), substr( (string) $year, -2 ) ) : '',
				'is_default'    => $is_default,
				'is_expired'    => $is_expired,
				'status_label'  => $is_expired ? __( '已過期', 'ys-shopline-via-woocommerce' ) : __( '可使用', 'ys-shopline-via-woocommerce' ),
				'status_class'  => $is_expired ? 'expired' : 'active',
			);
		}

		return $cards;
	}

	/**
	 * Index cards by SHOPLINE instrument ID.
	 *
	 * @param array<int, array<string, mixed>> $cards Cards.
	 * @return array<string, array<string, mixed>>
	 */
	private function index_cards_by_instrument( array $cards ): array {
		$indexed = array();
		foreach ( $cards as $card ) {
			if ( ! empty( $card['instrument_id'] ) ) {
				$indexed[ (string) $card['instrument_id'] ] = $card;
			}
		}
		return $indexed;
	}

	/**
	 * Resolve subscription binding status.
	 *
	 * @param string     $instrument_id Instrument ID.
	 * @param array|null $linked_card   Linked local card.
	 * @return array{label: string, class: string}
	 */
	private function resolve_subscription_binding_status( string $instrument_id, ?array $linked_card ): array {
		if ( '' === $instrument_id ) {
			return array(
				'label' => __( '未取得綁定資料', 'ys-shopline-via-woocommerce' ),
				'class' => 'missing',
			);
		}

		if ( null === $linked_card ) {
			return array(
				'label' => __( '有綁定資料，但本機儲存卡不存在', 'ys-shopline-via-woocommerce' ),
				'class' => 'instrument-missing',
			);
		}

		if ( ! empty( $linked_card['is_expired'] ) ) {
			return array(
				'label' => __( '綁定卡片已過期', 'ys-shopline-via-woocommerce' ),
				'class' => 'expired',
			);
		}

		return array(
			'label' => __( '已綁定', 'ys-shopline-via-woocommerce' ),
			'class' => 'active',
		);
	}

	/**
	 * Render the card cell for a subscription.
	 *
	 * @param string     $instrument_id Instrument ID.
	 * @param array|null $linked_card   Linked card.
	 * @return void
	 */
	private function render_bound_card_cell( string $instrument_id, ?array $linked_card ): void {
		if ( $linked_card ) {
			echo esc_html( strtoupper( (string) $linked_card['brand'] ) . ' **** ' . (string) $linked_card['last4'] );
			echo '<br><code>' . esc_html( $this->mask_instrument_id( $instrument_id ) ) . '</code>';
			return;
		}

		if ( $instrument_id ) {
			echo '<code>' . esc_html( $this->mask_instrument_id( $instrument_id ) ) . '</code>';
			return;
		}

		echo '&mdash;';
	}

	/**
	 * Get minimal order payment rows.
	 *
	 * @param \WC_Order|\WC_Subscription $order Order object.
	 * @return array<string, string>
	 */
	private function get_order_payment_rows( $order ): array {
		$detail = $this->get_payment_detail( $order );
		$rows   = array(
			'付款方式' => $this->get_order_payment_method_label( $order, $detail ),
			'付款狀態' => $this->get_order_payment_status( $order, $detail ),
			'付款編號' => $this->get_order_payment_identifier( $order, $detail ),
		);

		return array_filter(
			$rows,
			static function ( string $value ): bool {
				return '' !== trim( $value );
			}
		);
	}

	/**
	 * Get payment detail meta as an array.
	 *
	 * @param \WC_Order|\WC_Subscription $order Order object.
	 * @return array<string, mixed>
	 */
	private function get_payment_detail( $order ): array {
		return YSOrderMeta::get_payment_detail( $order );
	}

	/**
	 * Get the display payment method.
	 *
	 * @param \WC_Order|\WC_Subscription $order  Order object.
	 * @param array<string, mixed>       $detail Payment detail.
	 * @return string
	 */
	private function get_order_payment_method_label( $order, array $detail ): string {
		$title = trim( (string) $order->get_payment_method_title() );
		if ( '' !== $title ) {
			return $title;
		}

		$method = (string) $order->get_meta( YSOrderMeta::PAYMENT_METHOD );
		if ( '' === $method ) {
			$method = $this->get_nested_scalar(
				$detail,
				array(
					array( 'paymentMethod' ),
					array( 'payment', 'paymentMethod' ),
				)
			);
		}

		if ( '' !== $method ) {
			return $this->format_shopline_method( $method );
		}

		return (string) $order->get_payment_method();
	}

	/**
	 * Get SHOPLINE payment status.
	 *
	 * @param \WC_Order|\WC_Subscription $order  Order object.
	 * @param array<string, mixed>       $detail Payment detail.
	 * @return string
	 */
	private function get_order_payment_status( $order, array $detail ): string {
		$status = (string) $order->get_meta( YSOrderMeta::PAYMENT_STATUS );
		if ( '' === $status ) {
			$status = $this->get_nested_scalar(
				$detail,
				array(
					array( 'status' ),
					array( 'payment', 'status' ),
				)
			);
		}

		if ( '' !== $status ) {
			return $status;
		}

		return function_exists( 'wc_get_order_status_name' )
			? wc_get_order_status_name( (string) $order->get_status() )
			: (string) $order->get_status();
	}

	/**
	 * Get the SHOPLINE transaction identifier.
	 *
	 * @param \WC_Order|\WC_Subscription $order  Order object.
	 * @param array<string, mixed>       $detail Payment detail.
	 * @return string
	 */
	private function get_order_payment_identifier( $order, array $detail ): string {
		$trade_order_id = (string) $order->get_meta( YSOrderMeta::TRADE_ORDER_ID );
		if ( '' !== $trade_order_id ) {
			return $trade_order_id;
		}

		$trade_order_id = $this->get_nested_scalar(
			$detail,
			array(
				array( 'tradeOrderId' ),
				array( 'payment', 'tradeOrderId' ),
			)
		);

		if ( '' !== $trade_order_id ) {
			return $trade_order_id;
		}

		$reference_order_id = (string) $order->get_meta( YSOrderMeta::REFERENCE_ORDER_ID );
		if ( '' !== $reference_order_id ) {
			return $reference_order_id;
		}

		return $this->get_nested_scalar(
			$detail,
			array(
				array( 'referenceOrderId' ),
				array( 'payment', 'referenceOrderId' ),
			)
		);
	}

	/**
	 * Get the first scalar value found at one of the nested paths.
	 *
	 * @param array<string, mixed>           $source Source data.
	 * @param array<int, array<int, string>> $paths Paths to inspect.
	 * @return string
	 */
	private function get_nested_scalar( array $source, array $paths ): string {
		foreach ( $paths as $path ) {
			$value = $source;
			foreach ( $path as $key ) {
				if ( ! is_array( $value ) || ! array_key_exists( $key, $value ) ) {
					$value = null;
					break;
				}
				$value = $value[ $key ];
			}

			if ( is_scalar( $value ) && '' !== (string) $value ) {
				return (string) $value;
			}
		}

		return '';
	}

	/**
	 * Format SHOPLINE method.
	 *
	 * @param string $method Method from SHOPLINE response.
	 * @return string
	 */
	private function format_shopline_method( string $method ): string {
		$map = array(
			'CreditCard'     => __( '信用卡', 'ys-shopline-via-woocommerce' ),
			'LinePay'        => 'LINE Pay',
			'JKOPay'         => __( '街口支付', 'ys-shopline-via-woocommerce' ),
			'JkoPay'         => __( '街口支付', 'ys-shopline-via-woocommerce' ),
			'ApplePay'       => 'Apple Pay',
			'VirtualAccount' => __( 'ATM 銀行轉帳', 'ys-shopline-via-woocommerce' ),
			'VirtualAtm'     => __( 'ATM 銀行轉帳', 'ys-shopline-via-woocommerce' ),
			'ChaileaseBNPL'  => __( '中租 zingala 銀角零卡', 'ys-shopline-via-woocommerce' ),
			'ChaileaseBnpl'  => __( '中租 zingala 銀角零卡', 'ys-shopline-via-woocommerce' ),
		);

		return $map[ $method ] ?? $method;
	}

	/**
	 * Format subscription next payment date.
	 *
	 * @param \WC_Order|\WC_Subscription $subscription Subscription object.
	 * @return string
	 */
	private function format_subscription_next_payment( $subscription ): string {
		if ( ! method_exists( $subscription, 'get_date' ) ) {
			return '-';
		}

		$next = (string) $subscription->get_date( 'next_payment' );
		if ( '' === $next || '0' === $next ) {
			return '-';
		}

		if ( function_exists( 'wcs_date_to_time' ) ) {
			return wp_date( 'Y-m-d H:i:s', wcs_date_to_time( $next ), wp_timezone() );
		}

		return $next;
	}

	/**
	 * Determine whether an order object is a subscription.
	 *
	 * @param \WC_Order|\WC_Subscription $order Order object.
	 * @return bool
	 */
	private function is_subscription( $order ): bool {
		if ( function_exists( 'wcs_is_subscription' ) && wcs_is_subscription( $order ) ) {
			return true;
		}

		return method_exists( $order, 'get_type' ) && 'shop_subscription' === $order->get_type();
	}

	/**
	 * Determine whether a saved card is expired.
	 *
	 * @param string $month Expiry month.
	 * @param string $year  Expiry year.
	 * @return bool
	 */
	private function is_card_expired( string $month, string $year ): bool {
		if ( '' === $month || '' === $year ) {
			return false;
		}

		$month_int = max( 1, min( 12, (int) $month ) );
		$year_int  = (int) $year;
		if ( $year_int < 100 ) {
			$year_int += 2000;
		}

		$expires = strtotime( sprintf( '%04d-%02d-01 +1 month', $year_int, $month_int ) );
		return $expires ? $expires <= current_time( 'timestamp' ) : false;
	}

	/**
	 * Render a small status badge.
	 *
	 * @param string $label Badge label.
	 * @param string $class Badge class suffix.
	 * @return void
	 */
	private function render_status_badge( string $label, string $class ): void {
		printf(
			'<span class="ys-shopline-order-admin-badge ys-shopline-order-admin-badge-%1$s">%2$s</span>',
			esc_attr( sanitize_html_class( $class ) ),
			esc_html( $label )
		);
	}

	/**
	 * Mask a SHOPLINE instrument ID.
	 *
	 * @param string $instrument_id Raw instrument ID.
	 * @return string
	 */
	private function mask_instrument_id( string $instrument_id ): string {
		if ( strlen( $instrument_id ) <= 8 ) {
			return $instrument_id;
		}

		return '...' . substr( $instrument_id, -8 );
	}

	/**
	 * Render scoped styles once.
	 *
	 * @return void
	 */
	private function render_styles(): void {
		static $rendered = false;
		if ( $rendered ) {
			return;
		}
		$rendered = true;
		?>
		<style>
			#ys-shopline-order-payment-admin .inside,
			#ys-shopline-subscription-binding-admin .inside {
				margin: 0;
				padding: 0;
			}
			#ys-shopline-order-payment-admin .inside > .ys-shopline-order-admin-panel,
			#ys-shopline-subscription-binding-admin .inside > .ys-shopline-order-admin-panel {
				margin: 0;
			}
			.ys-shopline-order-admin-panel h4 {
				margin: 1em 0 .5em;
			}
			.ys-shopline-order-admin-subscription-binding {
				clear: both;
				margin-top: 12px;
			}
			.ys-shopline-order-admin-summary code,
			.ys-shopline-order-admin-table code {
				font-size: 12px;
			}
			.ys-shopline-order-admin-table {
				margin-bottom: 14px;
			}
			.ys-shopline-order-admin-table th,
			.ys-shopline-order-admin-table td {
				vertical-align: middle;
			}
			.ys-shopline-order-admin-box-table {
				width: 100%;
				margin: 0;
				border: 0;
				box-shadow: none;
				border-spacing: 0;
				border-collapse: collapse;
			}
			.ys-shopline-order-admin-box-table th {
				background: #f6f7f7;
				font-weight: 600;
				text-align: left;
			}
			.ys-shopline-order-admin-box-table th,
			.ys-shopline-order-admin-box-table td {
				padding: 8px 10px;
				border-bottom: 1px solid #f0f0f1;
			}
			.ys-shopline-order-admin-box-table tbody tr:last-child th,
			.ys-shopline-order-admin-box-table tbody tr:last-child td {
				border-bottom: 0;
			}
			.ys-shopline-order-admin-kv th {
				width: 180px;
			}
			.ys-shopline-order-admin-empty {
				color: #646970;
				margin: 0 0 12px;
			}
			.ys-shopline-order-admin-badge {
				display: inline-block;
				border-radius: 3px;
				padding: 2px 7px;
				font-size: 12px;
				line-height: 1.7;
				background: #f0f0f1;
				color: #2c3338;
			}
			.ys-shopline-order-admin-badge-active {
				background: #d1e7dd;
				color: #0f5132;
			}
			.ys-shopline-order-admin-badge-expired,
			.ys-shopline-order-admin-badge-missing,
			.ys-shopline-order-admin-badge-instrument-missing {
				background: #f8d7da;
				color: #842029;
			}
		</style>
		<?php
	}
}
