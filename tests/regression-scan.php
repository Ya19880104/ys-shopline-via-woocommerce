<?php
/**
 * Static regression sentinels for payment-critical mechanisms.
 *
 * This complements behavioral contracts by making removal of established
 * safeguards an explicit test change. It intentionally checks durable symbols
 * and call sites instead of line numbers.
 *
 * Standalone command:
 *   php tests/regression-scan.php
 *
 * @package YangSheep\ShoplinePayment\Tests
 */

declare(strict_types=1);

if ( ! class_exists( 'YS_Assert' ) ) {
	require __DIR__ . '/bootstrap.php';
}

/**
 * Run source-level regression sentinels.
 */
function ys_run_regression_scan(): void {
	echo "== Production regression sentinels ==\n";

	$root = dirname( __DIR__ );
	$read = static function ( string $relative ) use ( $root ): string {
		$contents = file_get_contents( $root . '/' . $relative );
		return false === $contents ? '' : $contents;
	};
	$has = static function ( string $source, string $needle ): bool {
		return false !== strpos( $source, $needle );
	};

	$js           = $read( 'assets/js/ys-shopline-checkout.js' );
	$gateway      = $read( 'src/Gateways/YSGatewayBase.php' );
	$installment  = $read( 'src/Gateways/YSCreditInstallment.php' );
	$subscription = $read( 'src/Gateways/YSCreditSubscription.php' );
	$atm          = $read( 'src/Gateways/YSVirtualAccount.php' );
	$confirmation = $read( 'src/Handlers/YSPaymentConfirmation.php' );
	$status       = $read( 'src/Handlers/YSStatusManager.php' );
	$webhook      = $read( 'src/Handlers/YSWebhookHandler.php' );
	$display      = $read( 'src/Frontend/YSOrderDisplay.php' );
	$dto          = $read( 'src/DTOs/YSSessionDTO.php' );
	$trade_status = $read( 'src/Utils/YSTradeStatus.php' );
	$meta         = $read( 'src/Utils/YSOrderMeta.php' );
	$main         = $read( 'ys-shopline-via-woocommerce.php' );

	YS_Assert::is_true( 'JS singleton boot guard remains present', $has( $js, '__ysShoplineCheckoutBooted' ) );
	YS_Assert::is_true( 'saved-card mount health selector remains present', $has( $js, 'shoplinepayments_item_' ) );
	YS_Assert::is_true( 'mount health contract remains present', $has( $js, 'isMountHealthy' ) );
	YS_Assert::is_true( 'checkout deferred submission remains present', $has( $js, 'deferPlaceOrder' ) );
	YS_Assert::is_true( 'order-pay mount wait remains present', $has( $js, 'waitForMountThenContinue' ) );
	YS_Assert::is_true( 'SDK options use shared builder', $has( $js, 'buildSdkOptions' ) );
	YS_Assert::is_true( 'saved-card capability remains explicit', $has( $js, 'supportsSavedCards' ) );
	YS_Assert::is_true( 'bind-card capability remains explicit', $has( $js, 'supportsBindCard' ) );
	YS_Assert::is_true( 'SDK instrument ID remains preferred', $has( $js, 'result.paymentInstrumentId' ) );
	YS_Assert::is_true( 'checkout and order-pay share instrument selection', $has( $js, 'getPaymentInstrumentSelection' ) );

	YS_Assert::is_true( 'gateway responses retain tri-state outcome', $has( $gateway, "'remote_outcome'" ) );
	YS_Assert::is_true( 'prior trade resolver remains present', $has( $gateway, 'resolve_prior_trade' ) );
	YS_Assert::is_true( 'pre-create confirmation guard remains present', $has( $gateway, 'YSPaymentConfirmation::get_active_attempt' ) && $has( $gateway, 'resolve_indeterminate' ) );
	YS_Assert::is_true( 'exact request envelope remains persisted', $has( $gateway, 'PAYMENT_ATTEMPT_DATA' ) );
	YS_Assert::is_true( 'indeterminate reference lock remains persisted', $has( $gateway, 'INDETERMINATE_REF' ) );
	YS_Assert::is_true( 'credit nextAction uses WC stock bookkeeping', $has( $gateway, 'wc_maybe_reduce_stock_levels( $order->get_id() )' ) );
	YS_Assert::is_true( 'ATM nextAction uses WC stock bookkeeping', $has( $atm, 'wc_maybe_reduce_stock_levels( $order->get_id() )' ) );
	YS_Assert::is_true( 'no production direct stock reduction remains', ! $has( $gateway . $atm, 'wc_reduce_stock_levels(' ) );

	YS_Assert::is_true( 'installment keeps optional bind-card SDK support', $has( $installment, "'bindCard'" ) );
	YS_Assert::is_true( 'saved installment routes to QuickPayment', $has( $gateway, "'QuickPayment'" ) );
	YS_Assert::is_true( 'new saved card routes to CardBindPayment', $has( $gateway, "'CardBindPayment'" ) );
	YS_Assert::is_true( 'subscription remains forced CardBindPayment', $has( $subscription, "= 'CardBindPayment'" ) );
	YS_Assert::is_true( 'subscription excludes confirmation state copy', $has( $subscription, 'CONFIRMATION_DATA' ) && $has( $subscription, 'CONFIRMATION_REVIEW' ) );

	YS_Assert::is_true( 'trade selector remains centralized', $has( $trade_status, 'select_representative_trade_id' ) );
	YS_Assert::is_true( 'session DTO uses centralized trade selector', $has( $dto, 'YSTradeStatus::select_representative_trade_id' ) );
	YS_Assert::is_true( 'abandoned paid guard remains active', $has( $webhook, 'guard_abandoned_trade_paid' ) );
	YS_Assert::is_true( 'failed stock restoration keeps paid-date guard', $has( $status, "'failed' === \$new_status && ! \$order->get_date_paid()" ) );
	YS_Assert::is_true( 'hourly safety net includes confirming orders', $has( $status, "YSPaymentConfirmation::STATUS_KEY" ) && $has( $status, '2 * DAY_IN_SECONDS' ) );

	YS_Assert::is_true( 'confirmation custom status remains registered', $has( $confirmation, "public const STATUS_KEY  = 'ys-confirming'" ) );
	YS_Assert::is_true( 'confirmation lock remains order-scoped', $has( $confirmation, 'with_confirmation_lock' ) && $has( $confirmation, 'GET_LOCK' ) );
	YS_Assert::is_true( 'strict webhook attempt matching remains centralized', $has( $confirmation, 'matching_webhook_attempt' ) );
	YS_Assert::is_true( 'paid-history convergence guard remains present', $has( $confirmation, 'has_paid_history' ) );
	YS_Assert::is_true( 'terminal convergence retains neutral notification', $has( $confirmation, 'send_terminal_notification' ) );
	YS_Assert::is_true( 'final stage retains durable review marker', $has( $confirmation, 'mark_for_review' ) && $has( $confirmation, 'CONFIRMATION_REVIEW' ) );
	YS_Assert::is_true( 'final review sends event-scoped admin alert', $has( $confirmation, 'send_manual_review_notification' ) && $has( $confirmation, 'manual_review:' ) );
	YS_Assert::is_true( 'order-pay uses standard pre-payment notice hook', $has( $display, 'woocommerce_pay_order_before_payment' ) );

	YS_Assert::is_true( 'confirmation attempt meta key remains defined', $has( $meta, 'CONFIRMATION_DATA' ) && $has( $meta, 'PAYMENT_ATTEMPT_DATA' ) );
	YS_Assert::is_true( 'main plugin initializes confirmation lifecycle', $has( $main, 'YSPaymentConfirmation::init' ) );
	YS_Assert::is_true( 'order-pay consumes tri-state outcome explicitly', $has( $main, "if ( 'rejected' === \$outcome )" ) && $has( $main, "elseif ( 'unknown' === \$outcome" ) );
	YS_Assert::is_true( 'v3.5.38 customerToken-only assumption comment is gone', ! $has( $js, '顯示既有卡只需 customerToken' ) );
	YS_Assert::is_true( 'installment is no longer documented as unable to bind new cards', ! $has( $js, '分期支援既有卡但不綁新卡' ) );
	YS_Assert::is_true( 'save-card gate no longer names installment as unsupported', ! $has( $js, '不支援綁新卡的 gateway（如分期）' ) );

	$guard_pos = strpos( $gateway, 'YSPaymentConfirmation::get_active_attempt' );
	$ref_pos   = strpos( $gateway, 'generate_reference_order_id' );
	YS_Assert::is_true( 'pre-create guard remains before reference generation', false !== $guard_pos && false !== $ref_pos && $guard_pos < $ref_pos );
}

if ( realpath( (string) ( $_SERVER['SCRIPT_FILENAME'] ?? '' ) ) === __FILE__ ) {
	ys_run_regression_scan();
	YS_Assert::summary_exit();
}
