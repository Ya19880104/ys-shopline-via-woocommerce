<?php
/**
 * Admin order/subscription SHOPLINE payment panel.
 *
 * @package YangSheep\ShoplinePayment\Admin
 */

declare(strict_types=1);

namespace YangSheep\ShoplinePayment\Admin;

defined( 'ABSPATH' ) || exit;

use YangSheep\ShoplinePayment\Utils\YSOrderMeta;
use WC_Payment_Tokens;

/**
 * Shows local SHOPLINE payment/card data on WooCommerce order edit screens.
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
	}

	/**
	 * Register the meta box for legacy and HPOS order screens.
	 *
	 * @param string              $post_type Current post type/screen context.
	 * @param \WP_Post|mixed|null $post      Current post object.
	 * @return void
	 */
	public function register_meta_boxes( $post_type, $post = null ): void {
		$order = $this->resolve_order( $post );
		if ( $order && ! $this->should_show_for_order( $order ) ) {
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

		foreach ( array_unique( array_filter( $screens ) ) as $screen ) {
			add_meta_box(
				'ys-shopline-order-payment-admin',
				__( 'SHOPLINE 付款與儲存卡資訊', 'ys-shopline-via-woocommerce' ),
				array( $this, 'render_meta_box' ),
				$screen,
				'normal',
				'high'
			);
		}
	}

	/**
	 * Render the meta box.
	 *
	 * @param \WP_Post|\WC_Abstract_Order|mixed $post_or_order Current screen object.
	 * @return void
	 */
	public function render_meta_box( $post_or_order ): void {
		$order = $this->resolve_order( $post_or_order );
		if ( ! $order || ! $this->current_user_can_edit_order( $order ) ) {
			return;
		}

		$user_id       = (int) $order->get_user_id();
		$cards         = $user_id ? $this->get_local_cards( $user_id ) : array();
		$cards_by_inst = $this->index_cards_by_instrument( $cards );
		$subscriptions = $this->get_related_subscriptions( $order );

		$this->render_styles();
		?>
		<div class="ys-shopline-order-admin-panel">
			<?php $this->render_customer_summary( $order, $user_id ); ?>
			<?php $this->render_customer_cards( $cards ); ?>
			<?php $this->render_subscription_binding( $subscriptions, $cards_by_inst ); ?>
			<?php $this->render_order_payment_overview( $order ); ?>
		</div>
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
	 * Only show on SHOPLINE order/subscription contexts.
	 *
	 * @param \WC_Order|\WC_Subscription $order Order object.
	 * @return bool
	 */
	private function should_show_for_order( $order ): bool {
		$payment_method = (string) $order->get_payment_method();
		if ( 0 === strpos( $payment_method, 'ys_shopline_' ) ) {
			return true;
		}

		if ( $this->is_subscription( $order ) && $order->get_meta( YSOrderMeta::PAYMENT_INSTRUMENT_ID ) ) {
			return true;
		}

		return (bool) $order->get_meta( YSOrderMeta::PAYMENT_DETAIL );
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
	 * Render customer summary line.
	 *
	 * @param \WC_Order|\WC_Subscription $order   Order object.
	 * @param int                        $user_id User ID.
	 * @return void
	 */
	private function render_customer_summary( $order, int $user_id ): void {
		$user       = $user_id ? get_userdata( $user_id ) : false;
		$email      = $user ? $user->user_email : $order->get_billing_email();
		$name       = $user ? $user->display_name : trim( $order->get_formatted_billing_full_name() );
		$customer   = $name ?: $email;
		$customer_id = $user_id ? (string) get_user_meta( $user_id, YSOrderMeta::CUSTOMER_ID, true ) : '';
		?>
		<p class="ys-shopline-order-admin-summary">
			<strong><?php esc_html_e( '客戶', 'ys-shopline-via-woocommerce' ); ?>:</strong>
			<?php echo esc_html( $customer ?: __( '訪客', 'ys-shopline-via-woocommerce' ) ); ?>
			<?php if ( $user_id ) : ?>
				<?php echo esc_html( sprintf( '#%d', $user_id ) ); ?>
			<?php endif; ?>
			<?php if ( $email ) : ?>
				<span class="description"><?php echo esc_html( $email ); ?></span>
			<?php endif; ?>
			<?php if ( $customer_id ) : ?>
				<br><strong><?php esc_html_e( 'SHOPLINE Customer ID', 'ys-shopline-via-woocommerce' ); ?>:</strong>
				<code><?php echo esc_html( $customer_id ); ?></code>
			<?php endif; ?>
		</p>
		<?php
	}

	/**
	 * Render local saved cards.
	 *
	 * @param array<int, array<string, mixed>> $cards Cards.
	 * @return void
	 */
	private function render_customer_cards( array $cards ): void {
		?>
		<h4><?php esc_html_e( '客戶已儲存的信用卡', 'ys-shopline-via-woocommerce' ); ?></h4>
		<?php if ( empty( $cards ) ) : ?>
			<p class="ys-shopline-order-admin-empty"><?php esc_html_e( '此客戶目前沒有本機 WooCommerce 儲存卡資料。', 'ys-shopline-via-woocommerce' ); ?></p>
			<?php return; ?>
		<?php endif; ?>
		<table class="widefat striped ys-shopline-order-admin-table">
			<thead>
				<tr>
					<th><?php esc_html_e( '卡片', 'ys-shopline-via-woocommerce' ); ?></th>
					<th><?php esc_html_e( '到期', 'ys-shopline-via-woocommerce' ); ?></th>
					<th><?php esc_html_e( '狀態', 'ys-shopline-via-woocommerce' ); ?></th>
					<th><?php esc_html_e( '預設', 'ys-shopline-via-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'SHOPLINE Instrument', 'ys-shopline-via-woocommerce' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $cards as $card ) : ?>
					<tr>
						<td>
							<strong><?php echo esc_html( strtoupper( (string) $card['brand'] ) ); ?></strong>
							<?php echo esc_html( ' **** ' . (string) $card['last4'] ); ?>
						</td>
						<td><?php echo $card['expiry'] ? esc_html( (string) $card['expiry'] ) : '&mdash;'; ?></td>
						<td><?php $this->render_status_badge( (string) $card['status_label'], (string) $card['status_class'] ); ?></td>
						<td><?php echo ! empty( $card['is_default'] ) ? esc_html__( '是', 'ys-shopline-via-woocommerce' ) : '&mdash;'; ?></td>
						<td><code><?php echo esc_html( $this->mask_instrument_id( (string) $card['instrument_id'] ) ); ?></code></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render subscription card bindings.
	 *
	 * @param array<int, \WC_Subscription|\WC_Order> $subscriptions       Subscriptions.
	 * @param array<string, array<string, mixed>>    $cards_by_instrument Cards by instrument ID.
	 * @return void
	 */
	private function render_subscription_binding( array $subscriptions, array $cards_by_instrument ): void {
		if ( empty( $subscriptions ) ) {
			return;
		}
		?>
		<h4><?php esc_html_e( '訂閱綁定信用卡', 'ys-shopline-via-woocommerce' ); ?></h4>
		<table class="widefat striped ys-shopline-order-admin-table">
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
						<td><?php echo esc_html( wc_get_order_status_name( (string) $subscription->get_status() ) ); ?></td>
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
	 * Render order payment overview.
	 *
	 * @param \WC_Order|\WC_Subscription $order Order object.
	 * @return void
	 */
	private function render_order_payment_overview( $order ): void {
		$detail         = $this->get_payment_detail( $order );
		$card           = $this->get_order_card_info( $order, $detail );
		$instrument_id  = $this->get_payment_instrument_id( $order, $detail );
		$payment_method = (string) $order->get_meta( YSOrderMeta::PAYMENT_METHOD );
		$rows           = array(
			__( 'WooCommerce 付款方式', 'ys-shopline-via-woocommerce' ) => trim( $order->get_payment_method_title() . ' (' . $order->get_payment_method() . ')' ),
			__( 'SHOPLINE 付款方式', 'ys-shopline-via-woocommerce' ) => $this->format_shopline_method( $payment_method ),
			__( 'SHOPLINE 付款狀態', 'ys-shopline-via-woocommerce' ) => (string) $order->get_meta( YSOrderMeta::PAYMENT_STATUS ),
			__( 'SHOPLINE Trade Order ID', 'ys-shopline-via-woocommerce' ) => (string) $order->get_meta( YSOrderMeta::TRADE_ORDER_ID ),
			__( 'Reference Order ID', 'ys-shopline-via-woocommerce' ) => (string) $order->get_meta( YSOrderMeta::REFERENCE_ORDER_ID ),
			__( '信用卡', 'ys-shopline-via-woocommerce' ) => $card,
			__( 'Payment Instrument', 'ys-shopline-via-woocommerce' ) => $instrument_id ? $this->mask_instrument_id( $instrument_id ) : '',
			__( '信用卡分期期數', 'ys-shopline-via-woocommerce' ) => (string) $order->get_meta( YSOrderMeta::INSTALLMENT ),
			__( '銀角零卡期數', 'ys-shopline-via-woocommerce' ) => (string) $order->get_meta( YSOrderMeta::BNPL_INSTALLMENT ),
			__( 'ATM 銀行代碼', 'ys-shopline-via-woocommerce' ) => (string) $order->get_meta( YSOrderMeta::VA_BANK_CODE ),
			__( 'ATM 轉帳帳號', 'ys-shopline-via-woocommerce' ) => (string) $order->get_meta( YSOrderMeta::VA_ACCOUNT ),
			__( 'ATM 繳費期限', 'ys-shopline-via-woocommerce' ) => (string) $order->get_meta( YSOrderMeta::VA_EXPIRE ),
			__( 'Next Action', 'ys-shopline-via-woocommerce' ) => (string) $order->get_meta( YSOrderMeta::NEXT_ACTION ),
			__( '錯誤代碼', 'ys-shopline-via-woocommerce' ) => (string) $order->get_meta( YSOrderMeta::ERROR_CODE ),
			__( '錯誤訊息', 'ys-shopline-via-woocommerce' ) => (string) $order->get_meta( YSOrderMeta::ERROR_MESSAGE ),
		);

		$rows = array_filter(
			$rows,
			static function ( $value ): bool {
				return '' !== trim( wp_strip_all_tags( (string) $value ) );
			}
		);
		?>
		<h4><?php esc_html_e( '訂單付款資訊', 'ys-shopline-via-woocommerce' ); ?></h4>
		<?php if ( empty( $rows ) ) : ?>
			<p class="ys-shopline-order-admin-empty"><?php esc_html_e( '此訂單目前沒有 SHOPLINE 付款資料。', 'ys-shopline-via-woocommerce' ); ?></p>
			<?php return; ?>
		<?php endif; ?>
		<table class="widefat striped ys-shopline-order-admin-table ys-shopline-order-admin-kv">
			<tbody>
				<?php foreach ( $rows as $label => $value ) : ?>
					<tr>
						<th><?php echo esc_html( (string) $label ); ?></th>
						<td>
							<?php if ( false !== strpos( (string) $label, 'ID' ) || false !== strpos( (string) $label, 'Instrument' ) ) : ?>
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
	 * Resolve subscriptions related to the current order.
	 *
	 * @param \WC_Order|\WC_Subscription $order Order object.
	 * @return array<int, \WC_Order|\WC_Subscription>
	 */
	private function get_related_subscriptions( $order ): array {
		$subscriptions = array();

		if ( $this->is_subscription( $order ) ) {
			$subscriptions[ $order->get_id() ] = $order;
		}

		if ( function_exists( 'wcs_get_subscriptions_for_order' ) ) {
			foreach ( wcs_get_subscriptions_for_order( $order, array( 'order_type' => 'any' ) ) as $subscription ) {
				$subscriptions[ $subscription->get_id() ] = $subscription;
			}
		}

		if ( function_exists( 'wcs_get_subscriptions_for_renewal_order' ) ) {
			foreach ( wcs_get_subscriptions_for_renewal_order( $order ) as $subscription ) {
				$subscriptions[ $subscription->get_id() ] = $subscription;
			}
		}

		foreach ( $subscriptions as $id => $subscription ) {
			if ( ! is_object( $subscription ) || ! method_exists( $subscription, 'get_payment_method' ) ) {
				unset( $subscriptions[ $id ] );
				continue;
			}

			if ( 0 !== strpos( (string) $subscription->get_payment_method(), 'ys_shopline_' ) ) {
				unset( $subscriptions[ $id ] );
			}
		}

		return $subscriptions;
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
				'label' => __( '未綁定到此訂閱', 'ys-shopline-via-woocommerce' ),
				'class' => 'missing',
			);
		}

		if ( null === $linked_card ) {
			return array(
				'label' => __( '已綁定，但本機找不到儲存卡', 'ys-shopline-via-woocommerce' ),
				'class' => 'instrument-missing',
			);
		}

		if ( ! empty( $linked_card['is_expired'] ) ) {
			return array(
				'label' => __( '已綁定，卡片已過期', 'ys-shopline-via-woocommerce' ),
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
	 * Get payment detail meta as an array.
	 *
	 * @param \WC_Order|\WC_Subscription $order Order object.
	 * @return array<string, mixed>
	 */
	private function get_payment_detail( $order ): array {
		$detail = $order->get_meta( YSOrderMeta::PAYMENT_DETAIL );
		return is_array( $detail ) ? $detail : array();
	}

	/**
	 * Get card information from order meta/API response snapshot.
	 *
	 * @param \WC_Order|\WC_Subscription $order  Order object.
	 * @param array<string, mixed>       $detail Payment detail.
	 * @return string
	 */
	private function get_order_card_info( $order, array $detail ): string {
		$brand = (string) $order->get_meta( YSOrderMeta::CARD_BRAND );
		$last4 = (string) $order->get_meta( YSOrderMeta::CARD_LAST4 );

		if ( '' === $brand ) {
			$brand = $this->get_nested_scalar( $detail, array(
				array( 'creditCard', 'brand' ),
				array( 'paymentInstrument', 'instrumentCard', 'brand' ),
				array( 'payment', 'creditCard', 'brand' ),
			) );
		}

		if ( '' === $last4 ) {
			$last4 = $this->get_nested_scalar( $detail, array(
				array( 'creditCard', 'last4' ),
				array( 'creditCard', 'last' ),
				array( 'paymentInstrument', 'instrumentCard', 'last4' ),
				array( 'paymentInstrument', 'instrumentCard', 'last' ),
				array( 'payment', 'creditCard', 'last4' ),
			) );
		}

		$type = $this->get_nested_scalar( $detail, array(
			array( 'creditCard', 'type' ),
			array( 'payment', 'creditCard', 'type' ),
		) );

		if ( '' === $brand && '' === $last4 ) {
			return '';
		}

		$card = trim( strtoupper( $brand ) . ( $last4 ? ' **** ' . $last4 : '' ) );
		return $type ? $card . ' (' . $type . ')' : $card;
	}

	/**
	 * Get SHOPLINE payment instrument ID from order/subscription meta.
	 *
	 * @param \WC_Order|\WC_Subscription $order  Order object.
	 * @param array<string, mixed>       $detail Payment detail.
	 * @return string
	 */
	private function get_payment_instrument_id( $order, array $detail ): string {
		$instrument_id = (string) $order->get_meta( YSOrderMeta::PAYMENT_INSTRUMENT_ID );
		if ( '' !== $instrument_id ) {
			return $instrument_id;
		}

		return $this->get_nested_scalar( $detail, array(
			array( 'paymentInstrument', 'paymentInstrumentId' ),
			array( 'paymentInstrument', 'instrumentId' ),
			array( 'payment', 'paymentInstrument', 'paymentInstrumentId' ),
			array( 'payment', 'paymentInstrument', 'instrumentId' ),
		) );
	}

	/**
	 * Get the first scalar value found at one of the nested paths.
	 *
	 * @param array<string, mixed> $source Source data.
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
	 * Render scoped styles.
	 *
	 * @return void
	 */
	private function render_styles(): void {
		?>
		<style>
			.ys-shopline-order-admin-panel h4 {
				margin: 1em 0 .5em;
			}
			.ys-shopline-order-admin-summary {
				margin: 0 0 12px;
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
			.ys-shopline-order-admin-kv th {
				width: 220px;
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
