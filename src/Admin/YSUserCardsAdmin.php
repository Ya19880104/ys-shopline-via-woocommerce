<?php
/**
 * Admin user profile card and subscription view.
 *
 * @package YangSheep\ShoplinePayment\Admin
 */

namespace YangSheep\ShoplinePayment\Admin;

defined( 'ABSPATH' ) || exit;

use YangSheep\ShoplinePayment\Customer\YSCustomer;
use YangSheep\ShoplinePayment\Utils\YSLogger;
use YangSheep\ShoplinePayment\Utils\YSOrderMeta;
use WC_Payment_Tokens;

/**
 * Shows SHOPLINE saved cards and subscription bindings on wp-admin user edit.
 */
final class YSUserCardsAdmin {

	/**
	 * Register admin hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		$instance = new self();

		add_action( 'show_user_profile', array( $instance, 'render_user_profile_section' ), 40 );
		add_action( 'edit_user_profile', array( $instance, 'render_user_profile_section' ), 40 );
		add_action( 'wp_ajax_ys_shopline_admin_sync_user_cards', array( $instance, 'ajax_sync_user_cards' ) );
	}

	/**
	 * Render the profile section.
	 *
	 * This method reads local WooCommerce/user/subscription data only. The
	 * SHOPLINE API is called only by ajax_sync_user_cards().
	 *
	 * @param \WP_User $user User being viewed.
	 * @return void
	 */
	public function render_user_profile_section( $user ): void {
		if ( ! $user instanceof \WP_User ) {
			return;
		}

		$user_id = (int) $user->ID;
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}

		if ( ! class_exists( WC_Payment_Tokens::class ) ) {
			return;
		}

		$customer_id   = get_user_meta( $user_id, YSOrderMeta::CUSTOMER_ID, true );
		$cards         = $this->get_local_cards( $user_id );
		$cards_by_inst = array();
		foreach ( $cards as $card ) {
			if ( ! empty( $card['instrument_id'] ) ) {
				$cards_by_inst[ (string) $card['instrument_id'] ] = $card;
			}
		}

		$subscriptions  = $this->get_shopline_subscriptions( $user_id, $cards_by_inst );
		$records        = $this->get_recent_payment_records( $user_id );
		$cache          = get_user_meta( $user_id, YSOrderMeta::INSTRUMENTS_CACHE, true );
		$cached_at      = is_array( $cache ) && ! empty( $cache['cached_at'] ) ? (int) $cache['cached_at'] : 0;
		$sync_nonce     = wp_create_nonce( 'ys_shopline_admin_sync_user_cards_' . $user_id );
		$can_sync       = ! empty( $customer_id ) && current_user_can( 'edit_user', $user_id );

		$this->render_styles();
		?>
		<h2><?php esc_html_e( 'SHOPLINE 付款資料', 'ys-shopline-via-woocommerce' ); ?></h2>
		<div class="ys-shopline-admin-user-panel">
			<div class="ys-shopline-admin-user-summary">
				<div>
					<strong><?php esc_html_e( 'SHOPLINE Customer ID', 'ys-shopline-via-woocommerce' ); ?></strong>
					<span><?php echo $customer_id ? '<code>' . esc_html( (string) $customer_id ) . '</code>' : esc_html__( '尚未建立', 'ys-shopline-via-woocommerce' ); ?></span>
				</div>
				<div>
					<strong><?php esc_html_e( '本機已儲存卡片', 'ys-shopline-via-woocommerce' ); ?></strong>
					<span><?php echo esc_html( (string) count( $cards ) ); ?></span>
				</div>
				<div>
					<strong><?php esc_html_e( '最近同步', 'ys-shopline-via-woocommerce' ); ?></strong>
					<span><?php echo $cached_at ? esc_html( $this->format_timestamp( $cached_at ) ) : esc_html__( '尚無同步紀錄', 'ys-shopline-via-woocommerce' ); ?></span>
				</div>
			</div>

			<p class="description">
				<?php esc_html_e( '此區塊預設只讀取 WooCommerce 本機資料，不會在開啟使用者頁面時呼叫 SHOPLINE API。需要更新卡片資料時請手動同步。', 'ys-shopline-via-woocommerce' ); ?>
			</p>

			<div class="ys-shopline-admin-actions">
				<button type="button"
					class="button"
					id="ys-shopline-admin-sync-cards"
					data-user-id="<?php echo esc_attr( (string) $user_id ); ?>"
					data-nonce="<?php echo esc_attr( $sync_nonce ); ?>"
					<?php disabled( ! $can_sync ); ?>>
					<?php esc_html_e( '同步 SHOPLINE 卡片', 'ys-shopline-via-woocommerce' ); ?>
				</button>
				<span id="ys-shopline-admin-sync-result" class="ys-shopline-admin-sync-result"></span>
			</div>

			<?php $this->render_cards_table( $cards ); ?>
			<?php $this->render_subscriptions_table( $subscriptions ); ?>
			<?php $this->render_records_table( $records ); ?>
		</div>

		<?php if ( $can_sync ) : ?>
			<?php $this->render_script(); ?>
		<?php endif; ?>
		<?php
	}

	/**
	 * Sync a user's SHOPLINE cards on demand.
	 *
	 * @return void
	 */
	public function ajax_sync_user_cards(): void {
		$user_id = isset( $_POST['user_id'] ) ? absint( wp_unslash( $_POST['user_id'] ) ) : 0;

		if ( ! $user_id || ! current_user_can( 'edit_user', $user_id ) ) {
			wp_send_json_error( array(
				'message' => __( '權限不足，無法同步此使用者的 SHOPLINE 卡片。', 'ys-shopline-via-woocommerce' ),
			), 403 );
		}

		check_ajax_referer( 'ys_shopline_admin_sync_user_cards_' . $user_id, 'nonce' );

		$customer_id = get_user_meta( $user_id, YSOrderMeta::CUSTOMER_ID, true );
		if ( empty( $customer_id ) ) {
			wp_send_json_error( array(
				'message' => __( '此使用者尚未建立 SHOPLINE Customer ID。', 'ys-shopline-via-woocommerce' ),
			), 400 );
		}

		$before = count( $this->get_local_cards( $user_id ) );
		$synced = YSCustomer::instance()->sync_tokens_from_api( $user_id );
		$after  = count( $this->get_local_cards( $user_id ) );

		YSLogger::info( 'Admin synced user cards from profile page', array(
			'user_id' => $user_id,
			'before'  => $before,
			'after'   => $after,
			'synced'  => $synced,
		) );

		wp_send_json_success( array(
			'message' => sprintf(
				/* translators: 1: synced card count, 2: total local cards */
				__( '同步完成，新增 %1$d 張，本機目前共 %2$d 張卡片。', 'ys-shopline-via-woocommerce' ),
				(int) $synced,
				(int) $after
			),
			'synced'  => (int) $synced,
			'total'   => (int) $after,
		) );
	}

	/**
	 * Get local WooCommerce SHOPLINE cards.
	 *
	 * @param int $user_id User ID.
	 * @return array<int, array<string, mixed>>
	 */
	private function get_local_cards( int $user_id ): array {
		$tokens = WC_Payment_Tokens::get_customer_tokens( $user_id, YSOrderMeta::CREDIT_GATEWAY_ID );
		$cards  = array();

		foreach ( $tokens as $token ) {
			$month = method_exists( $token, 'get_expiry_month' ) ? (string) $token->get_expiry_month() : '';
			$year  = method_exists( $token, 'get_expiry_year' ) ? (string) $token->get_expiry_year() : '';
			$is_expired = $this->is_card_expired( $month, $year );
			$is_default = method_exists( $token, 'is_default' ) && $token->is_default();

			$cards[] = array(
				'token_id'      => $token->get_id(),
				'instrument_id' => $token->get_token(),
				'brand'         => method_exists( $token, 'get_card_type' ) ? (string) $token->get_card_type() : '',
				'last4'         => method_exists( $token, 'get_last4' ) ? (string) $token->get_last4() : '',
				'expiry'        => $month && $year ? sprintf( '%s/%s', str_pad( $month, 2, '0', STR_PAD_LEFT ), $year ) : '',
				'is_default'    => $is_default,
				'is_expired'    => $is_expired,
				'status_label'  => $is_expired ? __( '已過期', 'ys-shopline-via-woocommerce' ) : __( '可用', 'ys-shopline-via-woocommerce' ),
				'status_class'  => $is_expired ? 'expired' : 'active',
			);
		}

		return $cards;
	}

	/**
	 * Get local SHOPLINE subscription bindings.
	 *
	 * @param int   $user_id User ID.
	 * @param array $cards_by_instrument Cards keyed by instrument ID.
	 * @return array<int, array<string, mixed>>
	 */
	private function get_shopline_subscriptions( int $user_id, array $cards_by_instrument ): array {
		if ( ! function_exists( 'wcs_get_users_subscriptions' ) ) {
			return array();
		}

		$subscriptions = wcs_get_users_subscriptions( $user_id );
		$rows          = array();

		foreach ( $subscriptions as $subscription ) {
			if ( ! is_object( $subscription ) || ! method_exists( $subscription, 'get_payment_method' ) ) {
				continue;
			}

			$payment_method = (string) $subscription->get_payment_method();
			if ( 0 !== strpos( $payment_method, 'ys_shopline_' ) ) {
				continue;
			}

			$instrument_id = (string) $subscription->get_meta( YSOrderMeta::PAYMENT_INSTRUMENT_ID );
			$linked_card   = $instrument_id && isset( $cards_by_instrument[ $instrument_id ] )
				? $cards_by_instrument[ $instrument_id ]
				: null;
			$binding       = $this->resolve_subscription_binding_status( $instrument_id, $linked_card );

			$rows[] = array(
				'id'                   => $subscription->get_id(),
				'edit_url'             => method_exists( $subscription, 'get_edit_order_url' ) ? $subscription->get_edit_order_url() : '',
				'status'               => method_exists( $subscription, 'get_status' ) ? $subscription->get_status() : '',
				'payment_method_title' => method_exists( $subscription, 'get_payment_method_title' ) ? $subscription->get_payment_method_title() : $payment_method,
				'instrument_id'        => $instrument_id,
				'linked_card'          => $linked_card,
				'binding_label'        => $binding['label'],
				'binding_class'        => $binding['class'],
				'next_payment'         => method_exists( $subscription, 'get_date' ) ? (string) $subscription->get_date( 'next_payment' ) : '',
			);
		}

		return $rows;
	}

	/**
	 * Get recent local SHOPLINE payment records for this user.
	 *
	 * @param int $user_id User ID.
	 * @return array<int, array<string, string>>
	 */
	private function get_recent_payment_records( int $user_id ): array {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}

		$orders = wc_get_orders( array(
			'customer_id' => $user_id,
			'limit'       => 20,
			'orderby'     => 'date',
			'order'       => 'DESC',
			'return'      => 'objects',
		) );

		$records = array();
		foreach ( $orders as $order ) {
			if ( ! is_object( $order ) || ! method_exists( $order, 'get_payment_method' ) ) {
				continue;
			}

			$payment_method = (string) $order->get_payment_method();
			if ( 0 !== strpos( $payment_method, 'ys_shopline_' ) ) {
				continue;
			}

			$records[] = array(
				'id'                   => (string) $order->get_id(),
				'edit_url'             => method_exists( $order, 'get_edit_order_url' ) ? $order->get_edit_order_url() : '',
				'date'                 => $order->get_date_created() ? $order->get_date_created()->date_i18n( 'Y-m-d H:i' ) : '',
				'status'               => method_exists( $order, 'get_status' ) ? $order->get_status() : '',
				'payment_method_title' => method_exists( $order, 'get_payment_method_title' ) ? $order->get_payment_method_title() : $payment_method,
				'payment_status'       => (string) $order->get_meta( YSOrderMeta::PAYMENT_STATUS ),
				'trade_order_id'       => (string) $order->get_meta( YSOrderMeta::TRADE_ORDER_ID ),
				'total'                => method_exists( $order, 'get_formatted_order_total' ) ? $order->get_formatted_order_total() : '',
			);

			if ( count( $records ) >= 5 ) {
				break;
			}
		}

		return $records;
	}

	/**
	 * Resolve subscription binding status.
	 *
	 * @param string     $instrument_id Instrument ID.
	 * @param array|null $linked_card Linked card data.
	 * @return array{label: string, class: string}
	 */
	private function resolve_subscription_binding_status( string $instrument_id, ?array $linked_card ): array {
		if ( '' === $instrument_id ) {
			return array(
				'label' => __( '未綁定卡片', 'ys-shopline-via-woocommerce' ),
				'class' => 'missing',
			);
		}

		if ( null === $linked_card ) {
			return array(
				'label' => __( '找不到本機卡片', 'ys-shopline-via-woocommerce' ),
				'class' => 'instrument-missing',
			);
		}

		if ( ! empty( $linked_card['is_expired'] ) ) {
			return array(
				'label' => __( '已綁定，卡片過期', 'ys-shopline-via-woocommerce' ),
				'class' => 'expired',
			);
		}

		return array(
			'label' => __( '已綁定', 'ys-shopline-via-woocommerce' ),
			'class' => 'active',
		);
	}

	/**
	 * Render saved cards table.
	 *
	 * @param array $cards Cards.
	 * @return void
	 */
	private function render_cards_table( array $cards ): void {
		?>
		<h3><?php esc_html_e( '已儲存卡片', 'ys-shopline-via-woocommerce' ); ?></h3>
		<?php if ( empty( $cards ) ) : ?>
			<p class="ys-shopline-admin-empty"><?php esc_html_e( '此使用者目前沒有本機已儲存 SHOPLINE 卡片。', 'ys-shopline-via-woocommerce' ); ?></p>
			<?php return; ?>
		<?php endif; ?>
		<table class="widefat striped ys-shopline-admin-table">
			<thead>
				<tr>
					<th><?php esc_html_e( '卡片', 'ys-shopline-via-woocommerce' ); ?></th>
					<th><?php esc_html_e( '有效期', 'ys-shopline-via-woocommerce' ); ?></th>
					<th><?php esc_html_e( '狀態', 'ys-shopline-via-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'WC Token', 'ys-shopline-via-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'SHOPLINE Instrument', 'ys-shopline-via-woocommerce' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $cards as $card ) : ?>
					<tr>
						<td>
							<strong><?php echo esc_html( strtoupper( (string) $card['brand'] ) ); ?></strong>
							<?php echo esc_html( '•••• ' . (string) $card['last4'] ); ?>
							<?php if ( ! empty( $card['is_default'] ) ) : ?>
								<?php $this->render_status_badge( __( '預設', 'ys-shopline-via-woocommerce' ), 'default' ); ?>
							<?php endif; ?>
						</td>
						<td><?php echo $card['expiry'] ? esc_html( (string) $card['expiry'] ) : '&mdash;'; ?></td>
						<td><?php $this->render_status_badge( (string) $card['status_label'], (string) $card['status_class'] ); ?></td>
						<td><code><?php echo esc_html( (string) $card['token_id'] ); ?></code></td>
						<td><code><?php echo esc_html( $this->mask_instrument_id( (string) $card['instrument_id'] ) ); ?></code></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render subscriptions table.
	 *
	 * @param array $subscriptions Subscriptions.
	 * @return void
	 */
	private function render_subscriptions_table( array $subscriptions ): void {
		?>
		<h3><?php esc_html_e( 'SHOPLINE 訂閱綁定狀態', 'ys-shopline-via-woocommerce' ); ?></h3>
		<?php if ( empty( $subscriptions ) ) : ?>
			<p class="ys-shopline-admin-empty"><?php esc_html_e( '此使用者沒有使用 SHOPLINE 付款的 WooCommerce 訂閱。', 'ys-shopline-via-woocommerce' ); ?></p>
			<?php return; ?>
		<?php endif; ?>
		<table class="widefat striped ys-shopline-admin-table">
			<thead>
				<tr>
					<th><?php esc_html_e( '訂閱', 'ys-shopline-via-woocommerce' ); ?></th>
					<th><?php esc_html_e( '訂閱狀態', 'ys-shopline-via-woocommerce' ); ?></th>
					<th><?php esc_html_e( '付款方式', 'ys-shopline-via-woocommerce' ); ?></th>
					<th><?php esc_html_e( '關聯卡片', 'ys-shopline-via-woocommerce' ); ?></th>
					<th><?php esc_html_e( '綁定狀態', 'ys-shopline-via-woocommerce' ); ?></th>
					<th><?php esc_html_e( '下次付款', 'ys-shopline-via-woocommerce' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $subscriptions as $subscription ) : ?>
					<tr>
						<td>
							<?php if ( ! empty( $subscription['edit_url'] ) ) : ?>
								<a href="<?php echo esc_url( (string) $subscription['edit_url'] ); ?>">#<?php echo esc_html( (string) $subscription['id'] ); ?></a>
							<?php else : ?>
								#<?php echo esc_html( (string) $subscription['id'] ); ?>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( wc_get_order_status_name( (string) $subscription['status'] ) ); ?></td>
						<td><?php echo esc_html( (string) $subscription['payment_method_title'] ); ?></td>
						<td>
							<?php if ( ! empty( $subscription['linked_card'] ) ) : ?>
								<?php
								$card = $subscription['linked_card'];
								echo esc_html( strtoupper( (string) $card['brand'] ) . ' •••• ' . (string) $card['last4'] );
								?>
							<?php elseif ( ! empty( $subscription['instrument_id'] ) ) : ?>
								<code><?php echo esc_html( $this->mask_instrument_id( (string) $subscription['instrument_id'] ) ); ?></code>
							<?php else : ?>
								&mdash;
							<?php endif; ?>
						</td>
						<td><?php $this->render_status_badge( (string) $subscription['binding_label'], (string) $subscription['binding_class'] ); ?></td>
						<td><?php echo ! empty( $subscription['next_payment'] ) ? esc_html( (string) $subscription['next_payment'] ) : '&mdash;'; ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render recent local payment records.
	 *
	 * @param array $records Payment records.
	 * @return void
	 */
	private function render_records_table( array $records ): void {
		?>
		<h3><?php esc_html_e( '最近 SHOPLINE 付款紀錄', 'ys-shopline-via-woocommerce' ); ?></h3>
		<?php if ( empty( $records ) ) : ?>
			<p class="ys-shopline-admin-empty"><?php esc_html_e( '此使用者目前沒有本機 SHOPLINE 付款紀錄。', 'ys-shopline-via-woocommerce' ); ?></p>
			<?php return; ?>
		<?php endif; ?>
		<table class="widefat striped ys-shopline-admin-table">
			<thead>
				<tr>
					<th><?php esc_html_e( '訂單', 'ys-shopline-via-woocommerce' ); ?></th>
					<th><?php esc_html_e( '日期', 'ys-shopline-via-woocommerce' ); ?></th>
					<th><?php esc_html_e( '付款方式', 'ys-shopline-via-woocommerce' ); ?></th>
					<th><?php esc_html_e( '訂單狀態', 'ys-shopline-via-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'SHOPLINE 狀態', 'ys-shopline-via-woocommerce' ); ?></th>
					<th><?php esc_html_e( '金額', 'ys-shopline-via-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'Trade ID', 'ys-shopline-via-woocommerce' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $records as $record ) : ?>
					<tr>
						<td>
							<?php if ( ! empty( $record['edit_url'] ) ) : ?>
								<a href="<?php echo esc_url( (string) $record['edit_url'] ); ?>">#<?php echo esc_html( (string) $record['id'] ); ?></a>
							<?php else : ?>
								#<?php echo esc_html( (string) $record['id'] ); ?>
							<?php endif; ?>
						</td>
						<td><?php echo $record['date'] ? esc_html( (string) $record['date'] ) : '&mdash;'; ?></td>
						<td><?php echo esc_html( (string) $record['payment_method_title'] ); ?></td>
						<td><?php echo esc_html( wc_get_order_status_name( (string) $record['status'] ) ); ?></td>
						<td><?php echo $record['payment_status'] ? esc_html( (string) $record['payment_status'] ) : '&mdash;'; ?></td>
						<td><?php echo wp_kses_post( (string) $record['total'] ); ?></td>
						<td><?php echo $record['trade_order_id'] ? '<code>' . esc_html( $this->mask_instrument_id( (string) $record['trade_order_id'] ) ) . '</code>' : '&mdash;'; ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render status badge.
	 *
	 * @param string $label Label.
	 * @param string $class Class suffix.
	 * @return void
	 */
	private function render_status_badge( string $label, string $class ): void {
		printf(
			'<span class="ys-shopline-status-badge %1$s">%2$s</span>',
			esc_attr( sanitize_html_class( $class ) ),
			esc_html( $label )
		);
	}

	/**
	 * Render inline admin styles.
	 *
	 * @return void
	 */
	private function render_styles(): void {
		?>
		<style>
			.ys-shopline-admin-user-panel {
				max-width: 1180px;
				margin: 12px 0 28px;
				padding: 16px;
				background: #fff;
				border: 1px solid #dcdcde;
			}
			.ys-shopline-admin-user-summary {
				display: grid;
				grid-template-columns: repeat(3, minmax(180px, 1fr));
				gap: 12px;
				margin-bottom: 10px;
			}
			.ys-shopline-admin-user-summary > div {
				padding: 10px 12px;
				background: #f6f7f7;
				border: 1px solid #e0e0e0;
			}
			.ys-shopline-admin-user-summary strong {
				display: block;
				margin-bottom: 6px;
			}
			.ys-shopline-admin-actions {
				display: flex;
				align-items: center;
				gap: 10px;
				margin: 14px 0 18px;
			}
			.ys-shopline-admin-table {
				margin: 8px 0 20px;
			}
			.ys-shopline-admin-empty {
				margin: 8px 0 20px;
				color: #646970;
			}
			.ys-shopline-status-badge {
				display: inline-block;
				margin-left: 6px;
				padding: 2px 8px;
				border-radius: 999px;
				font-size: 12px;
				font-weight: 600;
				line-height: 1.6;
				background: #f0f0f1;
				color: #50575e;
			}
			.ys-shopline-status-badge.active,
			.ys-shopline-status-badge.default {
				background: #edfaef;
				color: #0a6b22;
			}
			.ys-shopline-status-badge.expired,
			.ys-shopline-status-badge.instrument-missing,
			.ys-shopline-status-badge.missing {
				background: #fcf0f1;
				color: #b32d2e;
			}
			.ys-shopline-admin-sync-result.success {
				color: #0a6b22;
			}
			.ys-shopline-admin-sync-result.error {
				color: #b32d2e;
			}
			@media (max-width: 900px) {
				.ys-shopline-admin-user-summary {
					grid-template-columns: 1fr;
				}
			}
		</style>
		<?php
	}

	/**
	 * Render inline sync script.
	 *
	 * @return void
	 */
	private function render_script(): void {
		?>
		<script>
		(function() {
			var button = document.getElementById('ys-shopline-admin-sync-cards');
			var result = document.getElementById('ys-shopline-admin-sync-result');
			if (!button || !result) {
				return;
			}

			button.addEventListener('click', function() {
				if (button.disabled) {
					return;
				}

				button.disabled = true;
				result.className = 'ys-shopline-admin-sync-result';
				result.textContent = '<?php echo esc_js( __( '同步中...', 'ys-shopline-via-woocommerce' ) ); ?>';

				var params = new URLSearchParams();
				params.append('action', 'ys_shopline_admin_sync_user_cards');
				params.append('user_id', button.getAttribute('data-user-id'));
				params.append('nonce', button.getAttribute('data-nonce'));

				fetch(ajaxurl, {
					method: 'POST',
					credentials: 'same-origin',
					headers: {
						'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
					},
					body: params.toString()
				}).then(function(response) {
					return response.json();
				}).then(function(response) {
					if (response && response.success) {
						result.className = 'ys-shopline-admin-sync-result success';
						result.textContent = response.data && response.data.message ? response.data.message : '<?php echo esc_js( __( '同步完成。', 'ys-shopline-via-woocommerce' ) ); ?>';
						window.setTimeout(function() {
							window.location.reload();
						}, 900);
						return;
					}

					result.className = 'ys-shopline-admin-sync-result error';
					result.textContent = response && response.data && response.data.message ? response.data.message : '<?php echo esc_js( __( '同步失敗。', 'ys-shopline-via-woocommerce' ) ); ?>';
					button.disabled = false;
				}).catch(function() {
					result.className = 'ys-shopline-admin-sync-result error';
					result.textContent = '<?php echo esc_js( __( '同步失敗，請稍後再試。', 'ys-shopline-via-woocommerce' ) ); ?>';
					button.disabled = false;
				});
			});
		})();
		</script>
		<?php
	}

	/**
	 * Check whether a card is expired.
	 *
	 * @param string $month Expiry month.
	 * @param string $year Expiry year.
	 * @return bool
	 */
	private function is_card_expired( string $month, string $year ): bool {
		$month_i = absint( $month );
		$year_i  = absint( $year );

		if ( $month_i < 1 || $month_i > 12 || $year_i < 1 ) {
			return false;
		}

		if ( $year_i < 100 ) {
			$year_i += 2000;
		}

		try {
			$expires = new \DateTimeImmutable(
				sprintf( '%04d-%02d-01 23:59:59', $year_i, $month_i ),
				wp_timezone()
			);
			$expires = $expires->modify( 'last day of this month' );
			$now     = new \DateTimeImmutable( 'now', wp_timezone() );
			return $expires < $now;
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Format a local timestamp.
	 *
	 * @param int $timestamp Unix timestamp.
	 * @return string
	 */
	private function format_timestamp( int $timestamp ): string {
		return date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
	}

	/**
	 * Mask instrument/trade IDs for admin display.
	 *
	 * @param string $instrument_id Raw ID.
	 * @return string
	 */
	private function mask_instrument_id( string $instrument_id ): string {
		if ( strlen( $instrument_id ) <= 8 ) {
			return $instrument_id;
		}

		return '...' . substr( $instrument_id, -8 );
	}
}
