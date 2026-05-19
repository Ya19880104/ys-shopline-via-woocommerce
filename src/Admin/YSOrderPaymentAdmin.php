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
