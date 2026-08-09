<?php
/**
 * Payment-confirmation lifecycle integration.
 *
 * @package YangSheep\ShoplinePayment\Handlers
 */

declare(strict_types=1);

namespace YangSheep\ShoplinePayment\Handlers;

use YangSheep\ShoplinePayment\Utils\YSOrderMeta;
use YangSheep\ShoplinePayment\Utils\YSConfirmationPolicy;
use YangSheep\ShoplinePayment\Utils\YSLogger;
use YangSheep\ShoplinePayment\Utils\YSTradeStatus;

defined( 'ABSPATH' ) || exit;

/**
 * Owns the WooCommerce confirmation status and active-attempt metadata.
 */
final class YSPaymentConfirmation {

    public const STATUS_KEY  = 'ys-confirming';
    public const POST_STATUS = 'wc-ys-confirming';
    public const SCHEDULE_HOOK = 'ys_shopline_confirm_payment';

    private const SCHEDULE_GROUP = 'ys-shopline-payment-confirmation';

    /**
     * Register lifecycle hooks.
     */
    public static function init(): void {
        add_action( 'init', array( self::class, 'register_order_status' ) );
        add_filter( 'wc_order_statuses', array( self::class, 'add_order_status' ) );
        add_filter( 'woocommerce_valid_order_statuses_for_payment', array( self::class, 'exclude_from_payable_statuses' ), 999, 2 );
        add_filter( 'woocommerce_valid_order_statuses_for_payment_complete', array( self::class, 'include_for_payment_complete' ), 10, 2 );
        add_filter( 'wcs_is_subscription_order_completed', array( self::class, 'treat_confirming_transition_as_completed' ), 10, 5 );
        add_action( self::SCHEDULE_HOOK, array( self::class, 'reconcile' ), 10, 3 );
        add_action( 'woocommerce_payment_complete', array( self::class, 'clear_after_payment_complete' ), 20 );
    }

    /**
     * Register the custom WordPress order status.
     */
    public static function register_order_status(): void {
        register_post_status(
            self::POST_STATUS,
            array(
                'label'                     => __( '付款確認中', 'ys-shopline-via-woocommerce' ),
                'public'                    => false,
                'exclude_from_search'       => false,
                'show_in_admin_all_list'    => true,
                'show_in_admin_status_list' => true,
                'label_count'               => _n_noop(
                    '付款確認中 <span class="count">(%s)</span>',
                    '付款確認中 <span class="count">(%s)</span>',
                    'ys-shopline-via-woocommerce'
                ),
            )
        );
    }

    /**
     * Add the status after on-hold in WooCommerce status selectors.
     *
     * @param array<string, string> $statuses Status labels.
     * @return array<string, string>
     */
    public static function add_order_status( array $statuses ): array {
        $result   = array();
        $inserted = false;

        foreach ( $statuses as $key => $label ) {
            $result[ $key ] = $label;
            if ( 'wc-on-hold' === $key ) {
                $result[ self::POST_STATUS ] = __( '付款確認中', 'ys-shopline-via-woocommerce' );
                $inserted = true;
            }
        }

        if ( ! $inserted ) {
            $result[ self::POST_STATUS ] = __( '付款確認中', 'ys-shopline-via-woocommerce' );
        }

        return $result;
    }

    /**
     * Confirmation orders are never eligible for order-pay.
     *
     * @param array<int, string> $statuses Valid statuses.
     * @param \WC_Order|null      $order    Order object.
     * @return array<int, string>
     */
    public static function exclude_from_payable_statuses( array $statuses, $order = null ): array {
        $excluded = array( self::STATUS_KEY, self::POST_STATUS );
        if ( $order instanceof \WC_Order && ! empty( self::get_active_attempt( $order ) ) ) {
            $excluded[] = (string) $order->get_status();
        }
        return array_values( array_diff( $statuses, $excluded ) );
    }

    /**
     * Allow an authoritative remote convergence to complete a confirming order.
     *
     * @param array<int, string> $statuses Valid source statuses.
     * @param \WC_Order|null      $order    Order object.
     * @return array<int, string>
     */
    public static function include_for_payment_complete( array $statuses, $order = null ): array {
        if ( ! in_array( self::STATUS_KEY, $statuses, true ) ) {
            $statuses[] = self::STATUS_KEY;
        }
        return $statuses;
    }

    /**
     * Let WCS recognize a paid parent-order transition from confirmation.
     *
     * WCS normally activates subscriptions only when the previous order status
     * is pending, on-hold, or failed. An asynchronous SHOPLINE payment changes
     * from ys-confirming instead, so WCS needs this narrow compatibility signal.
     * WooCommerce writes date_paid before firing the processing/completed status
     * transition, including when an administrator intentionally marks it paid.
     *
     * @param bool                 $order_completed  WCS's native decision.
     * @param string               $new_order_status New status without wc- prefix.
     * @param string               $old_order_status Old status without wc- prefix.
     * @param \WC_Subscription[]   $subscriptions    Parent-order subscriptions.
     * @param \WC_Order            $order            Parent order.
     * @return bool
     */
    public static function treat_confirming_transition_as_completed( $order_completed, $new_order_status, $old_order_status, $subscriptions, $order ): bool {
        if ( $order_completed || self::STATUS_KEY !== $old_order_status || ! $order instanceof \WC_Order ) {
            return (bool) $order_completed;
        }

        $paid_statuses = array(
            apply_filters( 'woocommerce_payment_complete_order_status', 'processing', $order->get_id(), $order ),
            'processing',
            'completed',
        );

        if ( ! in_array( $new_order_status, $paid_statuses, true ) ) {
            return false;
        }

        return (bool) $order->get_date_paid();
    }

    public static function confirmation_title(): string {
        return __( '付款確認中', 'ys-shopline-via-woocommerce' );
    }

    public static function confirmation_message(): string {
        return __( '已收到您的付款請求，正在等待金流服務確認結果。為避免重複扣款，此訂單目前無法再次付款。確認完成後訂單會自動更新，請勿重複下單。', 'ys-shopline-via-woocommerce' );
    }

    public static function terminal_title(): string {
        return __( '付款確認未完成', 'ys-shopline-via-woocommerce' );
    }

    public static function terminal_message(): string {
        return __( '金流服務已回覆本次付款未完成，訂單已恢復為等待付款，您可以重新選擇付款方式。若銀行端仍顯示保留款，請先向發卡銀行確認後再付款。', 'ys-shopline-via-woocommerce' );
    }

    /**
     * Move an unpaid order into payment confirmation for one exact attempt.
     *
     * @param \WC_Order           $order   Order object.
     * @param array<string, mixed> $attempt Attempt envelope.
     * @param string               $source  Transition source.
     */
    public static function enter_confirmation( \WC_Order $order, array $attempt, string $source ): bool {
        if ( $order->is_paid() || self::has_paid_history( $order ) ) {
            return false;
        }
        if ( function_exists( 'wcs_order_contains_renewal' ) && wcs_order_contains_renewal( $order ) ) {
            return false;
        }

        $attempt = self::normalize_attempt( $attempt );
        if ( ! self::is_valid_attempt( $attempt ) ) {
            return false;
        }

        $current = self::get_active_attempt( $order );
        if ( ! empty( $current ) && (string) $current['reference'] !== (string) $attempt['reference'] ) {
            return false;
        }

        $order->update_meta_data( YSOrderMeta::CONFIRMATION_DATA, $attempt );
        $order->update_meta_data( YSOrderMeta::PAYMENT_STATUS, $attempt['remote_status'] );
        $order->update_meta_data( YSOrderMeta::REFERENCE_ORDER_ID, $attempt['reference'] );

        if ( '' !== $attempt['trade_order_id'] ) {
            $order->update_meta_data( YSOrderMeta::TRADE_ORDER_ID, $attempt['trade_order_id'] );
        }
        if ( '' !== $attempt['session_id'] ) {
            $order->update_meta_data( YSOrderMeta::SESSION_ID, $attempt['session_id'] );
        }

        $order->delete_meta_data( YSOrderMeta::PAYMENT_AUTHORIZED_PENDING );
        $history_added = self::append_history( $order, $attempt, $source );
        if ( $history_added ) {
            $order->add_order_note(
                sprintf(
                    /* translators: 1: reason, 2: remote status, 3: reference, 4: trade ID, 5: stage, 6: source. */
                    __( 'SHOPLINE 付款確認：reason=%1$s；status=%2$s；reference=%3$s；trade=%4$s；stage=%5$d；source=%6$s。', 'ys-shopline-via-woocommerce' ),
                    (string) $attempt['reason'],
                    (string) $attempt['remote_status'],
                    (string) $attempt['reference'],
                    '' !== (string) $attempt['trade_order_id'] ? (string) $attempt['trade_order_id'] : '-',
                    (int) $attempt['stage'],
                    $source
                )
            );
        }

        if ( self::STATUS_KEY !== $order->get_status() ) {
            $order->update_status(
                self::STATUS_KEY,
                __( 'SHOPLINE 付款結果確認中；確認完成前已鎖定重新付款。', 'ys-shopline-via-woocommerce' )
            );
        }

        $order->save();
        self::schedule_next( $order, $attempt );
        return true;
    }

    /**
     * Reconcile one exact active attempt.
     */
    public static function reconcile( int $order_id, string $reference, int $stage ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order instanceof \WC_Order ) {
            return;
        }

        self::with_confirmation_lock(
            $order,
            static function ( \WC_Order $fresh ) use ( $reference, $stage ): bool {
                self::reconcile_locked( $fresh, $reference, $stage );
                return true;
            },
            false
        );
    }

    /**
     * Reconcile after the order-scoped database lock has been acquired.
     */
    private static function reconcile_locked( \WC_Order $order, string $reference, int $stage ): void {
        if ( self::STATUS_KEY !== $order->get_status() || $order->is_paid() ) {
            return;
        }
        if ( self::has_paid_history( $order ) ) {
            self::restore_paid_history_order( $order );
            return;
        }

        $attempt = self::get_active_attempt( $order );
        if ( empty( $attempt )
            || $reference !== (string) ( $attempt['reference'] ?? '' )
            || $stage !== (int) ( $attempt['stage'] ?? -1 ) ) {
            return;
        }

        $api = \YSShoplinePayment::get_api();
        if ( null === $api ) {
            self::advance_transient( $order, $attempt, 'API_UNAVAILABLE', YSConfirmationPolicy::REASON_INDETERMINATE );
            return;
        }

        $trade_order_id = (string) ( $attempt['trade_order_id'] ?? '' );
        if ( '' !== $trade_order_id ) {
            $result = $api->query_payment( $trade_order_id );
            self::handle_payment_query_result( $order, $attempt, $result );
            return;
        }

        $session_id = (string) ( $attempt['session_id'] ?? '' );
        if ( '' === $session_id ) {
            self::advance_transient( $order, $attempt, 'MISSING_QUERY_ID', YSConfirmationPolicy::REASON_INDETERMINATE );
            return;
        }

        $result = $api->query_session( $session_id );
        self::handle_session_query_result( $order, $attempt, $result );
    }

    /**
     * Converge an exact paid webhook for an active confirmation attempt.
     *
     * @param \WC_Order           $order   Order object.
     * @param array<string, mixed> $payload Webhook payload.
     * @param string               $status  Paid status.
     */
    public static function handle_paid_webhook( \WC_Order $order, array $payload, string $status ): bool {
        if ( YSConfirmationPolicy::FAMILY_PAID !== YSConfirmationPolicy::family( $status ) ) {
            return false;
        }

        $attempt = self::matching_webhook_attempt( $order, $payload );
        if ( null === $attempt ) {
            return false;
        }

        return (bool) self::with_confirmation_lock(
            $order,
            static function ( \WC_Order $fresh ) use ( $payload, $status ): bool {
                $fresh_attempt = self::matching_webhook_attempt( $fresh, $payload );
                if ( null === $fresh_attempt ) {
                    return true;
                }
                if ( self::has_paid_history( $fresh ) ) {
                    self::restore_paid_history_order( $fresh );
                    return true;
                }

                self::converge_paid(
                    $fresh,
                    $fresh_attempt,
                    (string) $payload['tradeOrderId'],
                    strtoupper( $status ),
                    $payload
                );
                return true;
            },
            true
        );
    }

    /**
     * Reopen payment after an exact terminal webhook for the active attempt.
     *
     * @param \WC_Order           $order   Order object.
     * @param array<string, mixed> $payload Webhook payload.
     * @param string               $status  Terminal status.
     */
    public static function handle_terminal_webhook( \WC_Order $order, array $payload, string $status ): bool {
        if ( YSConfirmationPolicy::FAMILY_TERMINAL !== YSConfirmationPolicy::family( $status ) ) {
            return false;
        }

        $attempt = self::matching_webhook_attempt( $order, $payload );
        if ( null === $attempt ) {
            return false;
        }

        return (bool) self::with_confirmation_lock(
            $order,
            static function ( \WC_Order $fresh ) use ( $payload, $status ): bool {
                if ( self::has_paid_history( $fresh ) ) {
                    self::restore_paid_history_order( $fresh );
                    return true;
                }
                $fresh_attempt = self::matching_webhook_attempt( $fresh, $payload );
                if ( null === $fresh_attempt ) {
                    return true;
                }
                self::return_to_pending( $fresh, $fresh_attempt, strtoupper( $status ), $payload );
                return true;
            },
            true
        );
    }

    /**
     * Return an exact customer-action webhook to the prior-trade resolver.
     *
     * Wallet cancellation can arrive after create returned an indeterminate
     * result, before a trade ID was stored locally. Exact envelope matching is
     * required before the confirmation lock is released.
     *
     * @param \WC_Order            $order   Order object.
     * @param array<string, mixed> $payload Webhook payload.
     * @param string               $status  CREATED/CUSTOMER_ACTION status.
     */
    public static function handle_customer_pending_webhook( \WC_Order $order, array $payload, string $status ): bool {
        if ( YSConfirmationPolicy::FAMILY_CUSTOMER_PENDING !== YSConfirmationPolicy::family( $status ) ) {
            return false;
        }

        $attempt = self::matching_webhook_attempt( $order, $payload );
        if ( null === $attempt ) {
            return false;
        }

        return (bool) self::with_confirmation_lock(
            $order,
            static function ( \WC_Order $fresh ) use ( $payload, $status ): bool {
                if ( self::has_paid_history( $fresh ) ) {
                    self::restore_paid_history_order( $fresh );
                    return true;
                }
                $fresh_attempt = self::matching_webhook_attempt( $fresh, $payload );
                if ( null === $fresh_attempt ) {
                    return true;
                }
                self::converge_customer_pending(
                    $fresh,
                    $fresh_attempt,
                    (string) $payload['tradeOrderId'],
                    strtoupper( $status ),
                    $payload
                );
                return true;
            },
            true
        );
    }

    /**
     * Route an exact payment-query DTO through the active lifecycle.
     *
     * @param \WC_Order $order  Order object.
     * @param mixed      $result Payment query DTO.
     */
    public static function handle_query_result( \WC_Order $order, $result ): bool {
        if ( self::STATUS_KEY !== $order->get_status() || empty( self::get_active_attempt( $order ) ) ) {
            return false;
        }

        return (bool) self::with_confirmation_lock(
            $order,
            static function ( \WC_Order $fresh ) use ( $result ): bool {
                $attempt = self::get_active_attempt( $fresh );
                if ( self::STATUS_KEY !== $fresh->get_status() || empty( $attempt ) ) {
                    return true;
                }
                if ( self::has_paid_history( $fresh ) ) {
                    self::restore_paid_history_order( $fresh );
                    return true;
                }
                self::handle_payment_query_result( $fresh, $attempt, $result );
                return true;
            },
            true
        );
    }

    /**
     * Complete an order at most once across webhook, redirect, and status sync.
     *
     * WooCommerce fires woocommerce_pre_payment_complete before it checks the
     * current status. Serializing and reloading here prevents two stale order
     * objects from replaying payment hooks when webhook and query overlap.
     *
     * @return array{order:?\WC_Order,completed:bool,busy:bool}
     */
    public static function complete_payment_once( \WC_Order $order, string $trade_order_id ): array {
        $busy = array(
            'order'     => null,
            'completed' => false,
            'busy'      => true,
        );

        return (array) self::with_confirmation_lock(
            $order,
            static function ( \WC_Order $fresh ) use ( $trade_order_id ): array {
                if ( self::has_paid_history( $fresh ) ) {
                    return array(
                        'order'     => $fresh,
                        'completed' => false,
                        'busy'      => false,
                    );
                }

                $fresh->payment_complete( $trade_order_id );
                return array(
                    'order'     => $fresh,
                    'completed' => true,
                    'busy'      => false,
                );
            },
            $busy
        );
    }

    /**
     * Clear stale confirmation locks after any authoritative WC payment completion.
     */
    public static function clear_after_payment_complete( int $order_id ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order instanceof \WC_Order || ! $order->is_paid() ) {
            return;
        }

        if ( ! empty( self::get_active_attempt( $order ) ) ) {
            self::clear_active_data( $order );
        }
        $order->delete_meta_data( YSOrderMeta::PAYMENT_ATTEMPT_DATA );
        $order->save();
    }

    /**
     * Return the active attempt envelope.
     *
     * @return array<string, mixed>
     */
    public static function get_active_attempt( \WC_Order $order ): array {
        $data = $order->get_meta( YSOrderMeta::CONFIRMATION_DATA );
        return is_array( $data ) ? $data : array();
    }

    /**
     * Build a normalized attempt from the exact remote envelope and order data.
     *
     * Remote values and the indeterminate marker take precedence over values
     * recomputed from the order. This preserves zero-value subscription and
     * filtered gateway envelopes.
     *
     * @param \WC_Order           $order         Order object.
     * @param string               $remote_status SHOPLINE status.
     * @param array<string, mixed> $remote         Remote payload.
     * @param array<string, mixed> $overrides      Trusted caller overrides.
     * @return array<string, mixed>
     */
    public static function attempt_from_order( \WC_Order $order, string $remote_status, array $remote = array(), array $overrides = array() ): array {
        $indeterminate = $order->get_meta( YSOrderMeta::INDETERMINATE_DATA );
        $indeterminate = is_array( $indeterminate ) ? $indeterminate : array();
        $request        = $order->get_meta( YSOrderMeta::PAYMENT_ATTEMPT_DATA );
        $request        = is_array( $request ) ? $request : array();
        $current        = self::get_active_attempt( $order );

        $reference = self::first_non_empty( array(
            $remote['referenceOrderId'] ?? '',
            $overrides['reference'] ?? '',
            $request['reference'] ?? '',
            $order->get_meta( YSOrderMeta::REFERENCE_ORDER_ID ),
            $indeterminate['reference'] ?? '',
            $order->get_meta( YSOrderMeta::INDETERMINATE_REF ),
        ) );

        $remote_payment = isset( $remote['payment'] ) && is_array( $remote['payment'] ) ? $remote['payment'] : array();
        $remote_amount  = isset( $remote['amount'] ) && is_array( $remote['amount'] ) ? $remote['amount'] : array();
        if ( empty( $remote_amount ) && isset( $remote['order']['amount'] ) && is_array( $remote['order']['amount'] ) ) {
            $remote_amount = $remote['order']['amount'];
        }

        $gateway = self::first_non_empty( array( $overrides['gateway'] ?? '', $request['gateway'] ?? '', $order->get_payment_method() ) );
        $method  = self::first_non_empty( array(
            $overrides['shopline_method'] ?? '',
            $remote['paymentMethod'] ?? '',
            $remote_payment['paymentMethod'] ?? '',
            $request['shopline_method'] ?? '',
            $order->get_meta( YSOrderMeta::PAYMENT_METHOD ),
            $indeterminate['shopline_method'] ?? '',
            self::shopline_method_for_gateway( $gateway ),
        ) );
        $amount = isset( $overrides['amount'] )
            ? (int) $overrides['amount']
            : ( isset( $remote_amount['value'] )
                ? (int) $remote_amount['value']
                : ( isset( $request['amount'] )
                    ? (int) $request['amount']
                    : ( isset( $indeterminate['amount'] )
                        ? (int) $indeterminate['amount']
                        : (int) \YSShoplinePayment::get_formatted_amount( $order->get_total(), $order->get_currency() ) ) ) );
        $currency = strtoupper( self::first_non_empty( array(
            $overrides['currency'] ?? '',
            $remote_amount['currency'] ?? '',
            $request['currency'] ?? '',
            $indeterminate['currency'] ?? '',
            $order->get_currency(),
        ) ) );

        $same_attempt = ! empty( $current ) && $reference === (string) ( $current['reference'] ?? '' );
        $attempt = array(
            'reference'       => $reference,
            'trade_order_id'  => self::first_non_empty( array( $overrides['trade_order_id'] ?? '', $remote['tradeOrderId'] ?? '', $order->get_meta( YSOrderMeta::TRADE_ORDER_ID ) ) ),
            'session_id'      => self::first_non_empty( array( $overrides['session_id'] ?? '', $remote['sessionId'] ?? '', $request['session_id'] ?? '', $indeterminate['session_id'] ?? '', $order->get_meta( YSOrderMeta::SESSION_ID ) ) ),
            'gateway'         => $gateway,
            'shopline_method' => $method,
            'amount'          => $amount,
            'currency'        => $currency,
            'reason'          => (string) ( $overrides['reason'] ?? YSConfirmationPolicy::reason_for( $remote_status, $gateway ) ),
            'remote_status'   => strtoupper( trim( $remote_status ) ) ?: 'UNKNOWN',
            'started_at'      => $same_attempt ? (int) ( $current['started_at'] ?? time() ) : (int) ( $overrides['started_at'] ?? $request['started_at'] ?? time() ),
            'stage'           => $same_attempt ? (int) ( $current['stage'] ?? 0 ) : (int) ( $overrides['stage'] ?? 0 ),
        );

        return self::normalize_attempt( $attempt );
    }

    /**
     * Return the first non-empty scalar value.
     *
     * @param array<int, mixed> $values Candidate values.
     */
    private static function first_non_empty( array $values ): string {
        foreach ( $values as $value ) {
            if ( is_scalar( $value ) ) {
                $value = trim( (string) $value );
                if ( '' !== $value ) {
                    return $value;
                }
            }
        }

        return '';
    }

    /**
     * Return the SHOPLINE API method for a WooCommerce gateway ID.
     */
    private static function shopline_method_for_gateway( string $gateway_id ): string {
        $map = array(
            'ys_shopline_credit'              => 'CreditCard',
            'ys_shopline_credit_installment'  => 'CreditCard',
            'ys_shopline_credit_subscription' => 'CreditCard',
            'ys_shopline_atm'                 => 'VirtualAccount',
            'ys_shopline_jkopay'              => 'JKOPay',
            'ys_shopline_applepay'            => 'ApplePay',
            'ys_shopline_linepay'             => 'LinePay',
            'ys_shopline_bnpl'                => 'ChaileaseBNPL',
        );

        return $map[ $gateway_id ] ?? '';
    }

    /**
     * Build and enter confirmation in one call.
     *
     * @param \WC_Order           $order         Order object.
     * @param string               $remote_status SHOPLINE status.
     * @param array<string, mixed> $remote         Remote payload.
     * @param string               $source         Transition source.
     * @param array<string, mixed> $overrides      Trusted caller overrides.
     */
    public static function enter_from_order( \WC_Order $order, string $remote_status, array $remote, string $source, array $overrides = array() ): bool {
        return self::enter_confirmation(
            $order,
            self::attempt_from_order( $order, $remote_status, $remote, $overrides ),
            $source
        );
    }

    /**
     * Verify that candidate data belongs to the active attempt.
     *
     * Empty trade/session IDs may be enriched later, but conflicting IDs fail.
     *
     * @param \WC_Order           $order     Order object.
     * @param array<string, mixed> $candidate Candidate attempt.
     */
    public static function matches_active_attempt( \WC_Order $order, array $candidate ): bool {
        $current   = self::get_active_attempt( $order );
        $candidate = self::normalize_attempt( $candidate );

        if ( empty( $current ) || ! self::is_valid_attempt( $candidate ) ) {
            return false;
        }

        foreach ( array( 'reference', 'gateway', 'shopline_method', 'amount', 'currency' ) as $key ) {
            if ( (string) ( $current[ $key ] ?? '' ) !== (string) ( $candidate[ $key ] ?? '' ) ) {
                return false;
            }
        }

        foreach ( array( 'trade_order_id', 'session_id' ) as $key ) {
            $active_value    = (string) ( $current[ $key ] ?? '' );
            $candidate_value = (string) ( $candidate[ $key ] ?? '' );
            if ( '' !== $active_value && '' !== $candidate_value && $active_value !== $candidate_value ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Normalize an attempt envelope before storage or comparison.
     *
     * @param array<string, mixed> $attempt Raw attempt.
     * @return array<string, mixed>
     */
    private static function normalize_attempt( array $attempt ): array {
        return array(
            'reference'       => trim( (string) ( $attempt['reference'] ?? '' ) ),
            'trade_order_id'  => trim( (string) ( $attempt['trade_order_id'] ?? '' ) ),
            'session_id'      => trim( (string) ( $attempt['session_id'] ?? '' ) ),
            'gateway'         => trim( (string) ( $attempt['gateway'] ?? '' ) ),
            'shopline_method' => trim( (string) ( $attempt['shopline_method'] ?? '' ) ),
            'amount'          => isset( $attempt['amount'] ) ? (int) $attempt['amount'] : -1,
            'currency'        => strtoupper( trim( (string) ( $attempt['currency'] ?? '' ) ) ),
            'reason'          => trim( (string) ( $attempt['reason'] ?? '' ) ),
            'remote_status'   => strtoupper( trim( (string) ( $attempt['remote_status'] ?? '' ) ) ),
            'started_at'      => isset( $attempt['started_at'] ) ? (int) $attempt['started_at'] : time(),
            'stage'           => max( 0, (int) ( $attempt['stage'] ?? 0 ) ),
        );
    }

    /**
     * Validate fields needed for fail-closed reconciliation.
     *
     * Reference is the primary identity; trade/session IDs may be unknown at create time.
     *
     * @param array<string, mixed> $attempt Normalized attempt.
     */
    private static function is_valid_attempt( array $attempt ): bool {
        return '' !== $attempt['reference']
            && '' !== $attempt['gateway']
            && '' !== $attempt['shopline_method']
            && $attempt['amount'] >= 0
            && '' !== $attempt['currency']
            && '' !== $attempt['reason']
            && '' !== $attempt['remote_status'];
    }

    /**
     * Append an idempotent transition record.
     *
     * @param \WC_Order           $order   Order object.
     * @param array<string, mixed> $attempt Normalized attempt.
     * @param string               $source  Transition source.
     */
    private static function append_history( \WC_Order $order, array $attempt, string $source ): bool {
        $history = $order->get_meta( YSOrderMeta::CONFIRMATION_HISTORY );
        $history = is_array( $history ) ? $history : array();
        $last    = ! empty( $history ) ? end( $history ) : array();

        $signature = array(
            'reference'     => $attempt['reference'],
            'reason'        => $attempt['reason'],
            'remote_status' => $attempt['remote_status'],
            'stage'         => $attempt['stage'],
        );
        $last_signature = is_array( $last )
            ? array_intersect_key( $last, $signature )
            : array();

        if ( $signature === $last_signature ) {
            return false;
        }

        $history[] = array_merge(
            $signature,
            array(
                'source' => $source,
                'ts'     => time(),
            )
        );
        $order->update_meta_data( YSOrderMeta::CONFIRMATION_HISTORY, $history );
        return true;
    }

    /**
     * Schedule the active stage, or mark the order for review after the window.
     *
     * @param \WC_Order           $order   Order object.
     * @param array<string, mixed> $attempt Normalized attempt.
     */
    private static function schedule_next( \WC_Order $order, array $attempt ): void {
        $stage     = (int) $attempt['stage'];
        $timestamp = YSConfirmationPolicy::scheduled_at(
            (string) $attempt['gateway'],
            $stage,
            (int) $attempt['started_at'],
            time()
        );

        if ( null === $timestamp ) {
            self::mark_for_review( $order, $attempt );
            return;
        }

        $args = array( $order->get_id(), (string) $attempt['reference'], $stage );

        if ( function_exists( 'as_schedule_single_action' ) ) {
            if ( function_exists( 'as_has_scheduled_action' )
                && as_has_scheduled_action( self::SCHEDULE_HOOK, $args, self::SCHEDULE_GROUP ) ) {
                return;
            }
            as_schedule_single_action( $timestamp, self::SCHEDULE_HOOK, $args, self::SCHEDULE_GROUP, true );
            return;
        }

        if ( ! wp_next_scheduled( self::SCHEDULE_HOOK, $args ) ) {
            wp_schedule_single_event( $timestamp, self::SCHEDULE_HOOK, $args );
        }
    }

    /**
     * Return the active attempt when a webhook envelope matches exactly.
     *
     * @param \WC_Order           $order   Order object.
     * @param array<string, mixed> $payload Webhook payload.
     * @return array<string, mixed>|null
     */
    private static function matching_webhook_attempt( \WC_Order $order, array $payload ): ?array {
        $attempt = self::get_active_attempt( $order );
        if ( empty( $attempt ) || self::STATUS_KEY !== $order->get_status() || $order->is_paid() ) {
            return null;
        }

        $payment   = isset( $payload['payment'] ) && is_array( $payload['payment'] ) ? $payload['payment'] : array();
        $paid      = isset( $payment['paidAmount'] ) && is_array( $payment['paidAmount'] ) ? $payment['paidAmount'] : array();
        $order_raw = isset( $payload['order']['amount'] ) && is_array( $payload['order']['amount'] ) ? $payload['order']['amount'] : array();
        $amount    = ! empty( $paid ) ? $paid : ( ! empty( $order_raw ) ? $order_raw : ( is_array( $payload['amount'] ?? null ) ? $payload['amount'] : array() ) );

        $reference = trim( (string) ( $payload['referenceOrderId'] ?? '' ) );
        $trade     = trim( (string) ( $payload['tradeOrderId'] ?? '' ) );
        $method    = self::first_non_empty( array( $payload['paymentMethod'] ?? '', $payment['paymentMethod'] ?? '' ) );
        $value     = isset( $amount['value'] ) && is_numeric( $amount['value'] ) ? (int) $amount['value'] : -1;
        $currency  = strtoupper( trim( (string) ( $amount['currency'] ?? '' ) ) );

        if ( '' === $reference
            || $reference !== (string) ( $attempt['reference'] ?? '' )
            || '' === $trade
            || ( '' !== (string) ( $attempt['trade_order_id'] ?? '' ) && $trade !== (string) $attempt['trade_order_id'] )
            || '' === $method
            || $method !== (string) ( $attempt['shopline_method'] ?? '' )
            || $value < 0
            || $value !== (int) ( $attempt['amount'] ?? -1 )
            || '' === $currency
            || $currency !== (string) ( $attempt['currency'] ?? '' ) ) {
            YSLogger::warning(
                'Confirmation webhook envelope mismatch',
                array(
                    'order_id'  => $order->get_id(),
                    'reference' => $reference,
                    'trade'     => $trade,
                )
            );
            return null;
        }

        $attempt['trade_order_id'] = $trade;
        return $attempt;
    }

    /**
     * Process a strict query-by-trade result.
     *
     * @param \WC_Order           $order   Order object.
     * @param array<string, mixed> $attempt Active attempt.
     * @param mixed                $result  API result.
     */
    private static function handle_payment_query_result( \WC_Order $order, array $attempt, $result ): void {
        if ( is_wp_error( $result ) || ! is_object( $result ) ) {
            self::advance_transient( $order, $attempt, 'QUERY_ERROR', YSConfirmationPolicy::REASON_INDETERMINATE );
            return;
        }

        $trade_order_id = (string) ( $result->trade_order_id ?? '' );
        $status         = strtoupper( trim( (string) ( $result->status ?? '' ) ) );
        $method         = trim( (string) ( $result->payment_method ?? '' ) );
        $amount         = is_array( $result->amount ?? null ) ? $result->amount : array();
        $amount_value   = isset( $amount['value'] ) ? (int) $amount['value'] : -1;
        $currency       = strtoupper( trim( (string) ( $amount['currency'] ?? '' ) ) );

        if ( '' === $trade_order_id
            || $trade_order_id !== (string) $attempt['trade_order_id']
            || '' === $method
            || $method !== (string) $attempt['shopline_method']
            || $amount_value !== (int) $attempt['amount']
            || '' === $currency
            || $currency !== (string) $attempt['currency'] ) {
            self::advance_transient( $order, $attempt, 'MALFORMED_OR_MISMATCHED', YSConfirmationPolicy::REASON_INDETERMINATE );
            return;
        }

        $family = YSConfirmationPolicy::family( $status );
        $raw    = is_array( $result->raw_data ?? null ) ? $result->raw_data : array();

        if ( YSConfirmationPolicy::FAMILY_PAID === $family ) {
            self::converge_paid( $order, $attempt, $trade_order_id, $status, $raw );
            return;
        }
        if ( YSConfirmationPolicy::FAMILY_TERMINAL === $family ) {
            self::return_to_pending( $order, $attempt, $status, $raw );
            return;
        }
        if ( YSConfirmationPolicy::FAMILY_CUSTOMER_PENDING === $family ) {
            self::converge_customer_pending( $order, $attempt, $trade_order_id, $status, $raw );
            return;
        }

        self::advance_transient(
            $order,
            $attempt,
            '' !== $status ? $status : 'UNKNOWN',
            YSConfirmationPolicy::reason_for( $status, (string) $attempt['gateway'] )
        );
    }

    /**
     * Process an indeterminate query-by-session result.
     *
     * Session convergence is strict: the root envelope must match and every
     * payment detail must be well formed and use the expected method.
     *
     * @param \WC_Order           $order   Order object.
     * @param array<string, mixed> $attempt Active attempt.
     * @param mixed                $result  API result.
     */
    private static function handle_session_query_result( \WC_Order $order, array $attempt, $result ): void {
        if ( is_wp_error( $result ) || ! is_object( $result ) || ! is_array( $result->raw_data ?? null ) ) {
            self::advance_transient( $order, $attempt, 'QUERY_ERROR', YSConfirmationPolicy::REASON_INDETERMINATE );
            return;
        }

        $raw      = $result->raw_data;
        $root_ref = trim( (string) ( $raw['referenceId'] ?? '' ) );
        $root_amt = isset( $raw['amount']['value'] ) ? (int) $raw['amount']['value'] : -1;
        $root_cur = strtoupper( trim( (string) ( $raw['amount']['currency'] ?? '' ) ) );
        $details  = ( isset( $raw['paymentDetails'] ) && is_array( $raw['paymentDetails'] ) )
            ? $raw['paymentDetails']
            : array();

        if ( $root_ref !== (string) $attempt['reference']
            || $root_amt !== (int) $attempt['amount']
            || $root_cur !== (string) $attempt['currency']
            || empty( $details ) ) {
            self::advance_transient( $order, $attempt, 'MALFORMED_OR_MISMATCHED', YSConfirmationPolicy::REASON_INDETERMINATE );
            return;
        }

        $active = array();
        $terminal_status = '';
        foreach ( $details as $detail ) {
            if ( ! is_array( $detail ) ) {
                self::advance_transient( $order, $attempt, 'MALFORMED_SESSION', YSConfirmationPolicy::REASON_INDETERMINATE );
                return;
            }
            $trade  = trim( (string) ( $detail['tradeOrderId'] ?? '' ) );
            $status = strtoupper( trim( (string) ( $detail['status'] ?? '' ) ) );
            $method = trim( (string) ( $detail['paymentMethod'] ?? '' ) );
            if ( '' === $trade || '' === $status || $method !== (string) $attempt['shopline_method'] ) {
                self::advance_transient( $order, $attempt, 'MALFORMED_SESSION', YSConfirmationPolicy::REASON_INDETERMINATE );
                return;
            }

            $family = YSConfirmationPolicy::family( $status );
            if ( YSConfirmationPolicy::FAMILY_UNKNOWN === $family ) {
                self::advance_transient( $order, $attempt, $status, YSConfirmationPolicy::REASON_INDETERMINATE );
                return;
            }
            if ( YSConfirmationPolicy::FAMILY_TERMINAL === $family ) {
                $terminal_status = $status;
                continue;
            }
            $active[ $trade ] = array( 'status' => $status, 'family' => $family );
        }

        if ( empty( $active ) ) {
            self::return_to_pending( $order, $attempt, $terminal_status ?: 'FAILED', $raw );
            return;
        }
        if ( 1 !== count( $active ) ) {
            self::advance_transient( $order, $attempt, 'MULTIPLE_ACTIVE_TRADES', YSConfirmationPolicy::REASON_INDETERMINATE );
            return;
        }

        $trade  = (string) array_key_first( $active );
        $status = (string) $active[ $trade ]['status'];
        $family = (string) $active[ $trade ]['family'];
        if ( YSConfirmationPolicy::FAMILY_PAID === $family ) {
            $attempt['trade_order_id'] = $trade;
            self::converge_paid( $order, $attempt, $trade, $status, $raw );
            return;
        }
        if ( YSConfirmationPolicy::FAMILY_CUSTOMER_PENDING === $family ) {
            $attempt['trade_order_id'] = $trade;
            self::converge_customer_pending( $order, $attempt, $trade, $status, $raw );
            return;
        }

        $attempt['trade_order_id'] = $trade;
        self::advance_transient(
            $order,
            $attempt,
            $status,
            YSConfirmationPolicy::reason_for( $status, (string) $attempt['gateway'] )
        );
    }

    /**
     * Advance polling while preserving the repayment lock.
     *
     * @param \WC_Order           $order         Order object.
     * @param array<string, mixed> $attempt       Active attempt.
     * @param string               $remote_status Latest remote state.
     * @param string               $reason        Internal reason.
     */
    private static function advance_transient( \WC_Order $order, array $attempt, string $remote_status, string $reason ): void {
        $attempt['remote_status'] = strtoupper( $remote_status );
        $attempt['reason']        = $reason;
        $attempt['stage']         = (int) $attempt['stage'] + 1;
        self::enter_confirmation( $order, $attempt, 'scheduled_query' );
    }

    /**
     * Complete one exact paid attempt.
     *
     * @param \WC_Order           $order          Order object.
     * @param array<string, mixed> $attempt        Active attempt.
     * @param string               $trade_order_id Trade ID.
     * @param string               $status         Paid status.
     * @param array<string, mixed> $raw            Query payload.
     */
    private static function converge_paid( \WC_Order $order, array $attempt, string $trade_order_id, string $status, array $raw ): void {
        $attempt['trade_order_id'] = $trade_order_id;
        $attempt['remote_status']  = $status;
        self::append_history( $order, $attempt, 'scheduled_query_paid' );

        $order->update_meta_data( YSOrderMeta::TRADE_ORDER_ID, $trade_order_id );
        $order->update_meta_data( YSOrderMeta::PAYMENT_STATUS, $status );
        if ( ! empty( $raw ) ) {
            $order->update_meta_data( YSOrderMeta::PAYMENT_DETAIL, YSOrderMeta::sanitize_payment_detail( $raw ) );
        }
        self::clear_active_data( $order );
        $order->save();

        if ( ! $order->is_paid() ) {
            $order->payment_complete( $trade_order_id );
            $custom_paid_status = get_option( 'ys_shopline_order_status_paid', '' );
            if ( $custom_paid_status && $custom_paid_status !== $order->get_status() ) {
                $order->update_status( $custom_paid_status, __( '依商家設定更新訂單狀態。', 'ys-shopline-via-woocommerce' ) );
            }
        }
        $order->add_order_note( __( 'SHOPLINE 排程查詢確認付款完成。', 'ys-shopline-via-woocommerce' ) );
        $order->save();
    }

    /**
     * Reopen payment only after an exact terminal result.
     *
     * @param \WC_Order           $order   Order object.
     * @param array<string, mixed> $attempt Active attempt.
     * @param string               $status  Terminal status.
     * @param array<string, mixed> $raw     Query payload.
     */
    private static function return_to_pending( \WC_Order $order, array $attempt, string $status, array $raw ): void {
        if ( $order->is_paid() || self::has_paid_history( $order ) || ! YSTradeStatus::is_terminal_safe( $status ) ) {
            return;
        }

        $attempt['remote_status'] = $status;
        self::append_history( $order, $attempt, 'scheduled_query_terminal' );
        $order->update_meta_data( YSOrderMeta::PAYMENT_STATUS, $status );
        if ( ! empty( $raw ) ) {
            $order->update_meta_data( YSOrderMeta::PAYMENT_DETAIL, YSOrderMeta::sanitize_payment_detail( $raw ) );
        }
        self::clear_active_data( $order );
        $order->delete_meta_data( YSOrderMeta::TRADE_ORDER_ID );
        $order->delete_meta_data( YSOrderMeta::SESSION_ID );
        $order->delete_meta_data( YSOrderMeta::NEXT_ACTION );
        $order->update_status(
            'pending',
            self::terminal_message()
        );
        $order->save();
        self::send_terminal_notification( $order, $attempt );
    }

    /**
     * Return a confirmed customer-action trade to the existing prior-trade resolver.
     *
     * This is not a failure. The trade ID remains attached so a later payment
     * attempt must cancel, re-query, or archive it through resolve_prior_trade().
     *
     * @param \WC_Order           $order          Order object.
     * @param array<string, mixed> $attempt        Active attempt.
     * @param string               $trade_order_id Exact trade ID.
     * @param string               $status         CREATED/CUSTOMER_ACTION.
     * @param array<string, mixed> $raw            Query payload.
     */
    private static function converge_customer_pending( \WC_Order $order, array $attempt, string $trade_order_id, string $status, array $raw ): void {
        if ( $order->is_paid() || self::has_paid_history( $order ) || ! YSTradeStatus::is_customer_pending( $status ) ) {
            return;
        }

        $attempt['trade_order_id'] = $trade_order_id;
        $attempt['remote_status']  = strtoupper( $status );
        self::append_history( $order, $attempt, 'scheduled_query_customer_pending' );
        $order->update_meta_data( YSOrderMeta::TRADE_ORDER_ID, $trade_order_id );
        $order->update_meta_data( YSOrderMeta::PAYMENT_STATUS, strtoupper( $status ) );
        if ( ! empty( $raw ) ) {
            $order->update_meta_data( YSOrderMeta::PAYMENT_DETAIL, YSOrderMeta::sanitize_payment_detail( $raw ) );
        }
        self::clear_active_data( $order );
        $order->delete_meta_data( YSOrderMeta::NEXT_ACTION );
        $order->update_status(
            'pending',
            __( 'SHOPLINE 已確認本次交易仍待顧客完成；訂單已回到等待付款，後續付款會先核對既有交易。', 'ys-shopline-via-woocommerce' )
        );
        $order->save();
    }

    /**
     * Send at most one neutral customer notification for one reference.
     *
     * @param \WC_Order           $order   Order object.
     * @param array<string, mixed> $attempt Terminal attempt.
     */
    private static function send_terminal_notification( \WC_Order $order, array $attempt ): void {
        $reference = (string) ( $attempt['reference'] ?? '' );
        $customer_email = method_exists( $order, 'get_billing_email' ) ? trim( (string) $order->get_billing_email() ) : '';
        $admin_email    = trim( (string) get_option( 'admin_email', '' ) );
        if ( '' === $reference || ! function_exists( 'wc_mail' ) || ( '' === $customer_email && '' === $admin_email ) ) {
            return;
        }

        $sent = $order->get_meta( YSOrderMeta::CONFIRMATION_MAIL_SENT );
        $sent = is_array( $sent ) ? $sent : array();
        if ( isset( $sent[ $reference ] ) ) {
            return;
        }

        // Persist before sending to make concurrent webhook deliveries idempotent.
        $sent[ $reference ] = array( 'status' => 'sending', 'ts' => time() );
        $order->update_meta_data( YSOrderMeta::CONFIRMATION_MAIL_SENT, $sent );
        $order->save();

        $customer_subject = sprintf(
            /* translators: %s: order ID */
            __( '訂單 #%s 付款確認未完成', 'ys-shopline-via-woocommerce' ),
            (string) $order->get_id()
        );
        $customer_message = self::terminal_message();
        $mailer  = WC()->mailer();
        if ( is_object( $mailer ) && method_exists( $mailer, 'wrap_message' ) ) {
            $customer_message = $mailer->wrap_message( self::terminal_title(), $customer_message );
        }

        $customer_ok = true;
        if ( '' !== $customer_email ) {
            $customer_ok = wc_mail( $customer_email, $customer_subject, $customer_message, 'Content-Type: text/html; charset=UTF-8' );
        }

        $admin_ok = true;
        if ( '' !== $admin_email && $admin_email !== $customer_email ) {
            $admin_subject = sprintf(
                /* translators: %s: order ID */
                __( 'SHOPLINE 訂單 #%s 付款確認未完成', 'ys-shopline-via-woocommerce' ),
                (string) $order->get_id()
            );
            $admin_message = sprintf(
                /* translators: 1: order ID, 2: reference ID, 3: remote payment status. */
                __( '訂單 #%1$s 的付款確認已收到明確未完成結果，訂單已回到等待付款。Reference: %2$s；金流狀態: %3$s。若銀行端仍有保留款，請先核對 SHOPLINE Payments 後再協助顧客重付。', 'ys-shopline-via-woocommerce' ),
                (string) $order->get_id(),
                $reference,
                (string) ( $attempt['remote_status'] ?? 'UNKNOWN' )
            );
            if ( is_object( $mailer ) && method_exists( $mailer, 'wrap_message' ) ) {
                $admin_message = $mailer->wrap_message( self::terminal_title(), $admin_message );
            }
            $admin_ok = wc_mail( $admin_email, $admin_subject, $admin_message, 'Content-Type: text/html; charset=UTF-8' );
        }

        $ok = $customer_ok && $admin_ok;
        $sent[ $reference ] = array(
            'status'   => $ok ? 'sent' : 'failed',
            'customer' => $customer_ok ? 'sent' : 'failed',
            'admin'    => $admin_ok ? 'sent' : 'failed',
            'ts'       => time(),
        );
        $order->update_meta_data( YSOrderMeta::CONFIRMATION_MAIL_SENT, $sent );
        $order->save();

        if ( ! $ok ) {
            YSLogger::warning(
                'Payment confirmation terminal email failed',
                array( 'order_id' => $order->get_id(), 'reference' => $reference )
            );
        }
    }

    /**
     * Clear active-attempt locks after exact convergence.
     */
    private static function clear_active_data( \WC_Order $order ): void {
        $order->delete_meta_data( YSOrderMeta::CONFIRMATION_DATA );
        $order->delete_meta_data( YSOrderMeta::CONFIRMATION_REVIEW );
        $order->delete_meta_data( YSOrderMeta::INDETERMINATE_REF );
        $order->delete_meta_data( YSOrderMeta::INDETERMINATE_DATA );
        $order->delete_meta_data( YSOrderMeta::PAYMENT_AUTHORIZED_PENDING );
        $order->delete_meta_data( YSOrderMeta::PAYMENT_ATTEMPT_DATA );
    }

    /**
     * Serialize query/webhook convergence and re-read the order inside the lock.
     *
     * @param \WC_Order $order       Order object used only for its ID.
     * @param callable  $callback    Receives a freshly loaded order.
     * @param mixed     $busy_result Result returned when another request owns the lock.
     * @return mixed
     */
    private static function with_confirmation_lock( \WC_Order $order, callable $callback, $busy_result ) {
        global $wpdb;

        if ( ! $wpdb instanceof \wpdb ) {
            return $callback( $order );
        }

        $lock_name = 'ys_slp_confirm_' . max( 0, (int) $order->get_id() );
        $acquired  = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock_name, 2 ) );
        if ( '1' !== (string) $acquired ) {
            YSLogger::warning(
                'Payment confirmation convergence lock busy',
                array( 'order_id' => $order->get_id() )
            );
            return $busy_result;
        }

        try {
            $fresh = wc_get_order( $order->get_id() );
            if ( ! $fresh instanceof \WC_Order ) {
                return $busy_result;
            }
            return $callback( $fresh );
        } finally {
            $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
        }
    }

    /**
     * Detect orders that were paid even if their current status is unpaid.
     */
    private static function has_paid_history( \WC_Order $order ): bool {
        return $order->is_paid()
            || ( method_exists( $order, 'get_date_paid' ) && (bool) $order->get_date_paid() );
    }

    /**
     * Repair an inconsistent confirming status without replaying paid hooks.
     */
    private static function restore_paid_history_order( \WC_Order $order ): void {
        $target = (string) get_option( 'ys_shopline_order_status_paid', '' );
        if ( '' === $target ) {
            $target = method_exists( $order, 'needs_processing' ) && $order->needs_processing()
                ? 'processing'
                : 'completed';
        }

        self::clear_active_data( $order );
        if ( $target !== $order->get_status() ) {
            $order->update_status(
                $target,
                __( 'SHOPLINE 付款確認偵測到訂單已有付款日期；已清除不一致的確認鎖，未重跑付款完成流程。', 'ys-shopline-via-woocommerce' )
            );
        }
        $order->save();
    }

    /**
     * Stop polling and expose a durable manual-review marker without unlocking.
     *
     * @param \WC_Order           $order   Order object.
     * @param array<string, mixed> $attempt Active attempt.
     */
    private static function mark_for_review( \WC_Order $order, array $attempt ): void {
        $current = $order->get_meta( YSOrderMeta::CONFIRMATION_REVIEW );
        if ( is_array( $current ) && (string) ( $current['reference'] ?? '' ) === (string) $attempt['reference'] ) {
            self::send_manual_review_notification( $order, $attempt );
            return;
        }

        $order->update_meta_data(
            YSOrderMeta::CONFIRMATION_REVIEW,
            array(
                'type'          => 'confirmation_timeout',
                'reference'     => $attempt['reference'],
                'trade_order_id'=> $attempt['trade_order_id'],
                'reason'        => $attempt['reason'],
                'remote_status' => $attempt['remote_status'],
                'stage'         => $attempt['stage'],
                'ts'            => time(),
            )
        );
        $order->add_order_note(
            sprintf(
                /* translators: 1: reference ID, 2: last remote status, 3: scheduler stage. */
                __( 'SHOPLINE 付款確認已達自動查詢上限；訂單仍鎖定，請人工核對金流結果，勿直接要求顧客重付。Reference: %1$s；最後狀態: %2$s；查詢階段: %3$d。', 'ys-shopline-via-woocommerce' ),
                (string) $attempt['reference'],
                (string) $attempt['remote_status'],
                (int) $attempt['stage']
            )
        );
        $order->save();
        self::send_manual_review_notification( $order, $attempt );

        YSLogger::warning(
            'Payment confirmation requires manual review',
            array(
                'order_id'  => $order->get_id(),
                'reference' => $attempt['reference'],
                'stage'     => $attempt['stage'],
            )
        );
    }

    /**
     * Send one high-priority store-admin alert for a review-stage reference.
     *
     * @param \WC_Order           $order   Order object.
     * @param array<string, mixed> $attempt Active attempt.
     */
    private static function send_manual_review_notification( \WC_Order $order, array $attempt ): void {
        $reference = trim( (string) ( $attempt['reference'] ?? '' ) );
        $admin_email = trim( (string) get_option( 'admin_email', '' ) );
        if ( '' === $reference || '' === $admin_email || ! function_exists( 'wc_mail' ) ) {
            return;
        }

        $mail_key = 'manual_review:' . $reference;
        $sent = $order->get_meta( YSOrderMeta::CONFIRMATION_MAIL_SENT );
        $sent = is_array( $sent ) ? $sent : array();
        if ( isset( $sent[ $mail_key ] ) ) {
            return;
        }

        // Persist first so repeated scheduler/safety-net runs cannot duplicate the alert.
        $sent[ $mail_key ] = array( 'status' => 'sending', 'admin' => 'sending', 'ts' => time() );
        $order->update_meta_data( YSOrderMeta::CONFIRMATION_MAIL_SENT, $sent );
        $order->save();

        $subject = sprintf(
            /* translators: %s: order ID. */
            __( '[需立即核對] SHOPLINE 訂單 #%s 付款結果仍未確認', 'ys-shopline-via-woocommerce' ),
            (string) $order->get_id()
        );
        $message = sprintf(
            /* translators: 1: order ID, 2: reference ID, 3: remote status, 4: scheduler stage. */
            __( '訂單 #%1$s 的付款確認已達自動查詢上限，訂單仍維持鎖定。請登入 SHOPLINE Payments 核對實際交易結果；確認前勿要求顧客重複付款。Reference: %2$s；最後狀態: %3$s；查詢階段: %4$d。', 'ys-shopline-via-woocommerce' ),
            (string) $order->get_id(),
            $reference,
            (string) ( $attempt['remote_status'] ?? 'UNKNOWN' ),
            (int) ( $attempt['stage'] ?? 0 )
        );
        $mailer = WC()->mailer();
        if ( is_object( $mailer ) && method_exists( $mailer, 'wrap_message' ) ) {
            $message = $mailer->wrap_message( __( 'SHOPLINE 付款需人工核對', 'ys-shopline-via-woocommerce' ), $message );
        }

        $ok = wc_mail( $admin_email, $subject, $message, 'Content-Type: text/html; charset=UTF-8' );
        $sent[ $mail_key ] = array(
            'status' => $ok ? 'sent' : 'failed',
            'admin'  => $ok ? 'sent' : 'failed',
            'ts'     => time(),
        );
        $order->update_meta_data( YSOrderMeta::CONFIRMATION_MAIL_SENT, $sent );
        $order->save();

        if ( ! $ok ) {
            YSLogger::warning(
                'Payment confirmation manual-review email failed',
                array( 'order_id' => $order->get_id(), 'reference' => $reference )
            );
        }
    }
}
