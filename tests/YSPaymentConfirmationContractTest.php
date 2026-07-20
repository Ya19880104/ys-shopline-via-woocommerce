<?php
/**
 * Contract tests for the WooCommerce payment-confirmation lifecycle service.
 *
 * @package YangSheep\ShoplinePayment\Tests
 */

declare(strict_types=1);

use YangSheep\ShoplinePayment\Handlers\YSPaymentConfirmation;
use YangSheep\ShoplinePayment\Handlers\YSStatusManager;
use YangSheep\ShoplinePayment\Admin\YSOrderPaymentAdmin;
use YangSheep\ShoplinePayment\Utils\YSOrderMeta;

final class YS_Confirmation_Test_Order extends WC_Order {
    public array $meta = array();
    public array $notes = array();
    public string $status = 'pending';
    public int $save_count = 0;
    public int $payment_complete_count = 0;
    public bool $paid = false;
    public $date_paid = null;

    public function get_id(): int {
        return 9601;
    }

    public function get_status(): string {
        return $this->status;
    }

    public function get_payment_method(): string {
        return 'ys_shopline_credit';
    }

    public function get_total(): float {
        return 100.0;
    }

    public function get_currency(): string {
        return 'TWD';
    }

    public function get_billing_email(): string {
        return 'buyer@example.test';
    }

    public function get_date_paid() {
        return $this->date_paid;
    }

    public function needs_processing(): bool {
        return true;
    }

    public function get_meta( string $key ) {
        return $this->meta[ $key ] ?? '';
    }

    public function update_meta_data( string $key, $value ): void {
        $this->meta[ $key ] = $value;
    }

    public function delete_meta_data( string $key ): void {
        unset( $this->meta[ $key ] );
    }

    public function update_status( string $status, string $note = '' ): void {
        $this->status = $status;
        if ( '' !== $note ) {
            $this->notes[] = $note;
        }
    }

    public function add_order_note( string $note ): void {
        $this->notes[] = $note;
    }

    public function is_paid(): bool {
        return $this->paid;
    }

    public function payment_complete( string $trade_order_id = '' ): bool {
        if ( $this->paid ) {
            return false;
        }
        $this->paid = true;
        $this->date_paid = '2026-07-20 10:53:00';
        $this->payment_complete_count++;
        $this->status = 'processing';
        return true;
    }

    public function save(): void {
        $this->save_count++;
    }

    public function save_meta_data(): void {
        $this->save_count++;
    }
}

final class YS_Confirmation_Test_Payment_Result {
    public function __construct(
        public string $trade_order_id,
        public string $status,
        public ?string $payment_method,
        public ?array $amount,
        public array $raw_data = array()
    ) {}
}

final class YS_Confirmation_Test_Api {
    public $payment_result;
    public $session_result;
    public int $payment_queries = 0;
    public int $session_queries = 0;

    public function query_payment( string $trade_order_id ) {
        $this->payment_queries++;
        return $this->payment_result;
    }

    public function query_session( string $session_id ) {
        $this->session_queries++;
        return $this->session_result;
    }
}

final class YS_Confirmation_Test_Session_Result {
    public function __construct( public array $raw_data ) {}
}

function ys_confirmation_attempt( array $override = array() ): array {
    return array_merge(
        array(
            'reference'       => '9601_1',
            'trade_order_id'  => 'trade-confirm-1',
            'session_id'      => 'session-confirm-1',
            'gateway'         => 'ys_shopline_credit',
            'shopline_method' => 'CreditCard',
            'amount'          => 10000,
            'currency'        => 'TWD',
            'reason'          => 'in_flight',
            'remote_status'   => 'PROCESSING',
            'started_at'      => 1720000000,
            'stage'           => 0,
        ),
        $override
    );
}

function ys_run_payment_confirmation_contract(): void {
    echo "== Payment confirmation: WC lifecycle contract ==\n";

    if ( ! class_exists( YSPaymentConfirmation::class ) ) {
        YS_Assert::eq( 'payment confirmation service is available', true, false );
        return;
    }

    $GLOBALS['ys_test_registered_statuses'] = array();
    YSPaymentConfirmation::register_order_status();
    $registered = $GLOBALS['ys_test_registered_statuses'][ YSPaymentConfirmation::POST_STATUS ] ?? array();
    YS_Assert::eq( 'custom post status is registered', '付款確認中', $registered['label'] ?? '' );
    YS_Assert::eq( 'custom status is not a public WP post status', false, $registered['public'] ?? true );
    YS_Assert::eq( 'custom status appears in the admin all-orders list', true, $registered['show_in_admin_all_list'] ?? false );
    YS_Assert::eq( 'custom status appears in admin status counts', true, $registered['show_in_admin_status_list'] ?? false );

    $statuses = YSPaymentConfirmation::add_order_status( array(
        'wc-pending' => '等待付款中',
        'wc-on-hold' => '保留',
        'wc-processing' => '處理中',
    ) );
    YS_Assert::eq( 'WC status list includes confirmation status', '付款確認中', $statuses[ YSPaymentConfirmation::POST_STATUS ] ?? '' );

    $payable = YSPaymentConfirmation::exclude_from_payable_statuses(
        array( 'pending', 'failed', YSPaymentConfirmation::STATUS_KEY )
    );
    YS_Assert::eq( 'confirmation status is never payable', false, in_array( YSPaymentConfirmation::STATUS_KEY, $payable, true ) );

    $completion = new YS_Confirmation_Test_Order();
    $GLOBALS['ys_test_order'] = $completion;
    $GLOBALS['wpdb'] = new wpdb();
    $first_completion = YSPaymentConfirmation::complete_payment_once( $completion, 'trade-complete-once' );
    $second_completion = YSPaymentConfirmation::complete_payment_once( $completion, 'trade-complete-once' );
    YS_Assert::eq( 'shared completion lock completes the first paid event', true, $first_completion['completed'] ?? false );
    YS_Assert::eq( 'shared completion lock treats the next event as already paid', false, $second_completion['completed'] ?? true );
    YS_Assert::eq( 'shared completion lock calls payment_complete exactly once', 1, $completion->payment_complete_count );

    $custom_paid_completion = new YS_Confirmation_Test_Order();
    $custom_paid_completion->status = 'shipped';
    $custom_paid_completion->paid = false;
    $custom_paid_completion->date_paid = '2026-07-20 10:53:00';
    $GLOBALS['ys_test_order'] = $custom_paid_completion;
    $custom_result = YSPaymentConfirmation::complete_payment_once( $custom_paid_completion, 'trade-custom-paid' );
    YS_Assert::eq( 'shared completion lock honors date-paid custom status', false, $custom_result['completed'] ?? true );
    YS_Assert::eq( 'date-paid custom status never replays payment_complete', 0, $custom_paid_completion->payment_complete_count );
    $GLOBALS['wpdb'] = null;
    $order_with_active_meta = new YS_Confirmation_Test_Order();
    $order_with_active_meta->meta[ YSOrderMeta::CONFIRMATION_DATA ] = ys_confirmation_attempt();
    $active_payable = YSPaymentConfirmation::exclude_from_payable_statuses( array( 'pending', 'failed' ), $order_with_active_meta );
    YS_Assert::eq( 'active attempt blocks payment even if status was changed to pending', false, in_array( 'pending', $active_payable, true ) );
    $completable = YSPaymentConfirmation::include_for_payment_complete( array( 'pending', 'on-hold' ) );
    YS_Assert::eq( 'authoritative convergence may payment_complete from confirmation', true, in_array( YSPaymentConfirmation::STATUS_KEY, $completable, true ) );

    $order = new YS_Confirmation_Test_Order();
    $GLOBALS['ys_test_order'] = $order;
    $GLOBALS['ys_test_scheduled_actions'] = array();
    $order->meta[ YSOrderMeta::PAYMENT_AUTHORIZED_PENDING ] = 'yes';
    $entered = YSPaymentConfirmation::enter_confirmation( $order, ys_confirmation_attempt(), 'redirect' );
    YS_Assert::eq( 'valid attempt enters confirmation', true, $entered );
    YS_Assert::eq( 'order moves to custom confirmation status', YSPaymentConfirmation::STATUS_KEY, $order->status );
    YS_Assert::eq( 'active attempt is persisted', '9601_1', $order->get_meta( YSOrderMeta::CONFIRMATION_DATA )['reference'] ?? '' );
    YS_Assert::eq( 'legacy authorized marker is cleared', '', $order->get_meta( YSOrderMeta::PAYMENT_AUTHORIZED_PENDING ) );
    YS_Assert::eq( 'first transition writes one history record', 1, count( (array) $order->get_meta( YSOrderMeta::CONFIRMATION_HISTORY ) ) );
    YS_Assert::eq( 'first transition exposes reference in an admin order note', true, false !== strpos( implode( "\n", $order->notes ), '9601_1' ) );
    YS_Assert::eq( 'first transition exposes internal reason in an admin order note', true, false !== strpos( implode( "\n", $order->notes ), 'in_flight' ) );
    YS_Assert::eq( 'entering confirmation schedules one reconciliation', 1, count( $GLOBALS['ys_test_scheduled_actions'] ) );
    YS_Assert::eq( 'scheduled job is scoped to the active reference', '9601_1', $GLOBALS['ys_test_scheduled_actions'][0]['args'][1] ?? '' );

    YSPaymentConfirmation::enter_confirmation( $order, ys_confirmation_attempt(), 'scheduled_query' );
    YS_Assert::eq( 'same attempt and state do not duplicate history', 1, count( (array) $order->get_meta( YSOrderMeta::CONFIRMATION_HISTORY ) ) );

    YS_Assert::eq(
        'exact active attempt matches',
        true,
        YSPaymentConfirmation::matches_active_attempt( $order, ys_confirmation_attempt() )
    );
    YS_Assert::eq(
        'stale reference never matches active attempt',
        false,
        YSPaymentConfirmation::matches_active_attempt( $order, ys_confirmation_attempt( array( 'reference' => '9601_2' ) ) )
    );
    YS_Assert::eq(
        'amount mismatch never matches active attempt',
        false,
        YSPaymentConfirmation::matches_active_attempt( $order, ys_confirmation_attempt( array( 'amount' => 9999 ) ) )
    );

    $invalid = new YS_Confirmation_Test_Order();
    YS_Assert::eq(
        'attempt without reference is rejected',
        false,
        YSPaymentConfirmation::enter_confirmation( $invalid, ys_confirmation_attempt( array( 'reference' => '' ) ), 'redirect' )
    );
    YS_Assert::eq( 'invalid attempt leaves order pending', 'pending', $invalid->status );

    $paid_history = new YS_Confirmation_Test_Order();
    $paid_history->date_paid = '2026-07-17 12:00:00';
    YS_Assert::eq( 'order with a paid date cannot enter confirmation', false, YSPaymentConfirmation::enter_confirmation( $paid_history, ys_confirmation_attempt(), 'manual' ) );

    $renewal = new YS_Confirmation_Test_Order();
    $GLOBALS['ys_test_is_renewal_order'] = true;
    YS_Assert::eq( 'scheduled renewal does not enter customer confirmation lifecycle', false, YSPaymentConfirmation::enter_confirmation( $renewal, ys_confirmation_attempt(), 'renewal' ) );
    $GLOBALS['ys_test_is_renewal_order'] = false;

    $built_order = new YS_Confirmation_Test_Order();
    $built_order->meta[ YSOrderMeta::REFERENCE_ORDER_ID ] = '9601_9';
    $built_order->meta[ YSOrderMeta::SESSION_ID ] = 'session-built';
    $built_order->meta[ YSOrderMeta::PAYMENT_METHOD ] = 'CreditCard';
    $built = YSPaymentConfirmation::attempt_from_order(
        $built_order,
        'AUTHORIZED',
        array(
            'tradeOrderId' => 'trade-built',
            'amount' => array( 'value' => 10000, 'currency' => 'TWD' ),
            'paymentMethod' => 'CreditCard',
        )
    );
    YS_Assert::eq( 'attempt builder keeps exact reference', '9601_9', $built['reference'] ?? '' );
    YS_Assert::eq( 'attempt builder adopts response trade ID', 'trade-built', $built['trade_order_id'] ?? '' );
    YS_Assert::eq( 'attempt builder classifies authorized reason', 'authorized', $built['reason'] ?? '' );
    YS_Assert::eq( 'attempt builder keeps exact minor-unit amount', 10000, $built['amount'] ?? -1 );

    $request_envelope_order = new YS_Confirmation_Test_Order();
    $request_envelope_order->meta['_ys_shopline_payment_attempt_data'] = array(
        'reference' => '9601_zero_1',
        'session_id' => 'session-zero',
        'gateway' => 'ys_shopline_credit_subscription',
        'shopline_method' => 'CreditCard',
        'amount' => 10100,
        'currency' => 'TWD',
    );
    $request_built = YSPaymentConfirmation::attempt_from_order( $request_envelope_order, 'PROCESSING' );
    YS_Assert::eq( 'attempt builder preserves filtered request amount', 10100, $request_built['amount'] ?? -1 );
    YS_Assert::eq( 'attempt builder preserves request session identity', 'session-zero', $request_built['session_id'] ?? '' );

    $api = new YS_Confirmation_Test_Api();
    YSShoplinePayment::$test_api = $api;

    $in_flight = new YS_Confirmation_Test_Order();
    $GLOBALS['ys_test_order'] = $in_flight;
    $GLOBALS['ys_test_scheduled_actions'] = array();
    $attempt = ys_confirmation_attempt( array( 'started_at' => time() ) );
    YSPaymentConfirmation::enter_confirmation( $in_flight, $attempt, 'redirect' );
    $GLOBALS['ys_test_scheduled_actions'] = array();
    $api->payment_result = new YS_Confirmation_Test_Payment_Result(
        'trade-confirm-1',
        'PROCESSING',
        'CreditCard',
        array( 'value' => 10000, 'currency' => 'TWD' )
    );
    YSPaymentConfirmation::reconcile( 9601, '9601_1', 0 );
    YS_Assert::eq( 'in-flight query advances the stage', 1, $in_flight->get_meta( YSOrderMeta::CONFIRMATION_DATA )['stage'] ?? -1 );
    YS_Assert::eq( 'in-flight query remains confirmation', YSPaymentConfirmation::STATUS_KEY, $in_flight->status );
    YS_Assert::eq( 'in-flight query schedules the next cumulative stage', 1, count( $GLOBALS['ys_test_scheduled_actions'] ) );

    $queries_before = $api->payment_queries;
    YSPaymentConfirmation::reconcile( 9601, 'stale-reference', 1 );
    YS_Assert::eq( 'stale reference is a no-op before API query', $queries_before, $api->payment_queries );

    $paid = new YS_Confirmation_Test_Order();
    $GLOBALS['ys_test_order'] = $paid;
    YSPaymentConfirmation::enter_confirmation( $paid, ys_confirmation_attempt(), 'redirect' );
    $api->payment_result = new YS_Confirmation_Test_Payment_Result(
        'trade-confirm-1',
        'SUCCEEDED',
        'CreditCard',
        array( 'value' => 10000, 'currency' => 'TWD' )
    );
    YSPaymentConfirmation::reconcile( 9601, '9601_1', 0 );
    YS_Assert::eq( 'paid query completes payment once', 1, $paid->payment_complete_count );
    YS_Assert::eq( 'paid query clears active confirmation data', '', $paid->get_meta( YSOrderMeta::CONFIRMATION_DATA ) );

    $terminal = new YS_Confirmation_Test_Order();
    $GLOBALS['ys_test_order'] = $terminal;
    YSPaymentConfirmation::enter_confirmation( $terminal, ys_confirmation_attempt(), 'redirect' );
    $api->payment_result = new YS_Confirmation_Test_Payment_Result(
        'trade-confirm-1',
        'FAILED',
        'CreditCard',
        array( 'value' => 10000, 'currency' => 'TWD' )
    );
    YSPaymentConfirmation::reconcile( 9601, '9601_1', 0 );
    YS_Assert::eq( 'exact terminal query returns order to pending', 'pending', $terminal->status );
    YS_Assert::eq( 'terminal query clears active confirmation data', '', $terminal->get_meta( YSOrderMeta::CONFIRMATION_DATA ) );

    $customer_pending = new YS_Confirmation_Test_Order();
    $GLOBALS['ys_test_order'] = $customer_pending;
    YSPaymentConfirmation::enter_confirmation( $customer_pending, ys_confirmation_attempt(), 'redirect' );
    $api->payment_result = new YS_Confirmation_Test_Payment_Result(
        'trade-confirm-1',
        'CUSTOMER_ACTION',
        'CreditCard',
        array( 'value' => 10000, 'currency' => 'TWD' )
    );
    YSPaymentConfirmation::reconcile( 9601, '9601_1', 0 );
    YS_Assert::eq( 'exact customer-pending query reopens resolver path', 'pending', $customer_pending->status );
    YS_Assert::eq( 'customer-pending convergence keeps trade for resolver', 'trade-confirm-1', $customer_pending->get_meta( YSOrderMeta::TRADE_ORDER_ID ) );
    YS_Assert::eq( 'customer-pending convergence clears confirmation lock', '', $customer_pending->get_meta( YSOrderMeta::CONFIRMATION_DATA ) );

    $GLOBALS['ys_test_mails'] = array();
    $GLOBALS['ys_test_options']['admin_email'] = 'merchant@example.test';
    $review = new YS_Confirmation_Test_Order();
    $GLOBALS['ys_test_order'] = $review;
    YSPaymentConfirmation::enter_confirmation( $review, ys_confirmation_attempt( array( 'stage' => 6 ) ), 'scheduled_query' );
    $api->payment_result = new WP_Error( 'http_request_failed', 'timeout' );
    YSPaymentConfirmation::reconcile( 9601, '9601_1', 6 );
    YS_Assert::eq( 'final transient query remains confirmation', YSPaymentConfirmation::STATUS_KEY, $review->status );
    YS_Assert::eq( 'final transient query creates manual review marker', 'confirmation_timeout', $review->get_meta( YSOrderMeta::CONFIRMATION_REVIEW )['type'] ?? '' );
    YS_Assert::eq( 'final transient query appears in existing SLP review queue', true, YSOrderPaymentAdmin::has_open_confirmation_review( $review ) );
    YS_Assert::eq( 'final transient query sends one merchant review email', 1, count( $GLOBALS['ys_test_mails'] ) );
    YS_Assert::eq( 'manual review email goes only to store admin', 'merchant@example.test', $GLOBALS['ys_test_mails'][0]['to'] ?? '' );
    YS_Assert::eq( 'manual review email is marked high priority', true, false !== strpos( $GLOBALS['ys_test_mails'][0]['subject'] ?? '', '需立即核對' ) );
    YS_Assert::eq( 'manual review mail map is event-scoped', true, isset( $review->get_meta( YSOrderMeta::CONFIRMATION_MAIL_SENT )['manual_review:9601_1'] ) );
    YSPaymentConfirmation::reconcile( 9601, '9601_1', 7 );
    YS_Assert::eq( 'same reference never sends duplicate manual review email', 1, count( $GLOBALS['ys_test_mails'] ) );
    unset( $GLOBALS['ys_test_options']['admin_email'] );

    $session_attempt = ys_confirmation_attempt( array(
        'trade_order_id' => '',
        'session_id' => 'session-confirm-1',
        'reason' => 'indeterminate',
        'remote_status' => 'INDETERMINATE',
    ) );

    $session_paid = new YS_Confirmation_Test_Order();
    $GLOBALS['ys_test_order'] = $session_paid;
    YSPaymentConfirmation::enter_confirmation( $session_paid, $session_attempt, 'gateway_unknown' );
    $api->session_result = new YS_Confirmation_Test_Session_Result( array(
        'referenceId' => '9601_1',
        'amount' => array( 'value' => 10000, 'currency' => 'TWD' ),
        'paymentDetails' => array(
            array( 'tradeOrderId' => 'old-failed', 'status' => 'FAILED', 'paymentMethod' => 'CreditCard' ),
            array( 'tradeOrderId' => 'paid-session', 'status' => 'SUCCEEDED', 'paymentMethod' => 'CreditCard' ),
        ),
    ) );
    YSPaymentConfirmation::reconcile( 9601, '9601_1', 0 );
    YS_Assert::eq( 'session exact unique paid trade completes payment', 1, $session_paid->payment_complete_count );
    YS_Assert::eq( 'session paid convergence adopts the paid trade', 'paid-session', $session_paid->get_meta( YSOrderMeta::TRADE_ORDER_ID ) );

    $session_terminal = new YS_Confirmation_Test_Order();
    $GLOBALS['ys_test_order'] = $session_terminal;
    YSPaymentConfirmation::enter_confirmation( $session_terminal, $session_attempt, 'gateway_unknown' );
    $api->session_result = new YS_Confirmation_Test_Session_Result( array(
        'referenceId' => '9601_1',
        'amount' => array( 'value' => 10000, 'currency' => 'TWD' ),
        'paymentDetails' => array(
            array( 'tradeOrderId' => 'failed-session', 'status' => 'FAILED', 'paymentMethod' => 'CreditCard' ),
        ),
    ) );
    YSPaymentConfirmation::reconcile( 9601, '9601_1', 0 );
    YS_Assert::eq( 'session all-terminal exact result reopens payment', 'pending', $session_terminal->status );

    $session_customer_pending = new YS_Confirmation_Test_Order();
    $GLOBALS['ys_test_order'] = $session_customer_pending;
    YSPaymentConfirmation::enter_confirmation( $session_customer_pending, $session_attempt, 'gateway_unknown' );
    $api->session_result = new YS_Confirmation_Test_Session_Result( array(
        'referenceId' => '9601_1',
        'amount' => array( 'value' => 10000, 'currency' => 'TWD' ),
        'paymentDetails' => array(
            array( 'tradeOrderId' => 'customer-action-session', 'status' => 'CUSTOMER_ACTION', 'paymentMethod' => 'CreditCard' ),
        ),
    ) );
    YSPaymentConfirmation::reconcile( 9601, '9601_1', 0 );
    YS_Assert::eq( 'session customer-pending result reopens resolver path', 'pending', $session_customer_pending->status );
    YS_Assert::eq( 'session customer-pending adopts exact trade', 'customer-action-session', $session_customer_pending->get_meta( YSOrderMeta::TRADE_ORDER_ID ) );

    $hourly_session_paid = new YS_Confirmation_Test_Order();
    $GLOBALS['ys_test_order'] = $hourly_session_paid;
    YSPaymentConfirmation::enter_confirmation( $hourly_session_paid, $session_attempt, 'gateway_unknown' );
    $api->session_result = new YS_Confirmation_Test_Session_Result( array(
        'referenceId' => '9601_1',
        'amount' => array( 'value' => 10000, 'currency' => 'TWD' ),
        'paymentDetails' => array(
            array( 'tradeOrderId' => 'hourly-paid-session', 'status' => 'SUCCEEDED', 'paymentMethod' => 'CreditCard' ),
        ),
    ) );
    ( new YSStatusManager() )->sync_payment_status( $hourly_session_paid );
    YS_Assert::eq( 'hourly safety-net delegates session convergence to confirmation lifecycle', 1, $hourly_session_paid->payment_complete_count );
    YS_Assert::eq( 'hourly session convergence adopts exact paid trade', 'hourly-paid-session', $hourly_session_paid->get_meta( YSOrderMeta::TRADE_ORDER_ID ) );

    $session_wrong_method = new YS_Confirmation_Test_Order();
    $GLOBALS['ys_test_order'] = $session_wrong_method;
    YSPaymentConfirmation::enter_confirmation( $session_wrong_method, $session_attempt, 'gateway_unknown' );
    $api->session_result = new YS_Confirmation_Test_Session_Result( array(
        'referenceId' => '9601_1',
        'amount' => array( 'value' => 10000, 'currency' => 'TWD' ),
        'paymentDetails' => array(
            array( 'tradeOrderId' => 'paid-wrong-method', 'status' => 'SUCCEEDED', 'paymentMethod' => 'ApplePay' ),
        ),
    ) );
    YSPaymentConfirmation::reconcile( 9601, '9601_1', 0 );
    YS_Assert::eq( 'session wrong method remains confirmation', YSPaymentConfirmation::STATUS_KEY, $session_wrong_method->status );
    YS_Assert::eq( 'session wrong method advances without unlocking', 1, $session_wrong_method->get_meta( YSOrderMeta::CONFIRMATION_DATA )['stage'] ?? -1 );

    $session_multiple = new YS_Confirmation_Test_Order();
    $GLOBALS['ys_test_order'] = $session_multiple;
    YSPaymentConfirmation::enter_confirmation( $session_multiple, $session_attempt, 'gateway_unknown' );
    $api->session_result = new YS_Confirmation_Test_Session_Result( array(
        'referenceId' => '9601_1',
        'amount' => array( 'value' => 10000, 'currency' => 'TWD' ),
        'paymentDetails' => array(
            array( 'tradeOrderId' => 'paid-active', 'status' => 'SUCCEEDED', 'paymentMethod' => 'CreditCard' ),
            array( 'tradeOrderId' => 'processing-active', 'status' => 'PROCESSING', 'paymentMethod' => 'CreditCard' ),
        ),
    ) );
    YSPaymentConfirmation::reconcile( 9601, '9601_1', 0 );
    YS_Assert::eq( 'session multiple active trades never adopts paid trade', 0, $session_multiple->payment_complete_count );
    YS_Assert::eq( 'session multiple active trades remain locked', YSPaymentConfirmation::STATUS_KEY, $session_multiple->status );

    $webhook_payload = array(
        'referenceOrderId' => '9601_1',
        'tradeOrderId' => 'trade-confirm-1',
        'payment' => array(
            'paymentMethod' => 'CreditCard',
            'paidAmount' => array( 'value' => 10000, 'currency' => 'TWD' ),
        ),
    );

    $webhook_paid = new YS_Confirmation_Test_Order();
    $GLOBALS['ys_test_order'] = $webhook_paid;
    YSPaymentConfirmation::enter_confirmation( $webhook_paid, ys_confirmation_attempt(), 'webhook_processing' );
    YS_Assert::eq(
        'exact paid webhook is handled by confirmation lifecycle',
        true,
        YSPaymentConfirmation::handle_paid_webhook( $webhook_paid, $webhook_payload, 'SUCCEEDED' )
    );
    YS_Assert::eq( 'exact paid webhook completes once', 1, $webhook_paid->payment_complete_count );

    $lock_busy_webhook = new YS_Confirmation_Test_Order();
    $GLOBALS['ys_test_order'] = $lock_busy_webhook;
    YSPaymentConfirmation::enter_confirmation( $lock_busy_webhook, ys_confirmation_attempt(), 'webhook_processing' );
    $GLOBALS['wpdb'] = new wpdb( false );
    YS_Assert::eq(
        'matching paid webhook is consumed while convergence lock is busy',
        true,
        YSPaymentConfirmation::handle_paid_webhook( $lock_busy_webhook, $webhook_payload, 'SUCCEEDED' )
    );
    YS_Assert::eq( 'busy convergence lock prevents duplicate payment completion', 0, $lock_busy_webhook->payment_complete_count );
    YS_Assert::eq( 'busy convergence lock keeps confirmation active', YSPaymentConfirmation::STATUS_KEY, $lock_busy_webhook->status );

    $lock_busy_query = new YS_Confirmation_Test_Order();
    $GLOBALS['ys_test_order'] = $lock_busy_query;
    $GLOBALS['wpdb'] = null;
    YSPaymentConfirmation::enter_confirmation( $lock_busy_query, ys_confirmation_attempt(), 'redirect' );
    $queries_before_lock = $api->payment_queries;
    $GLOBALS['wpdb'] = new wpdb( false );
    YSPaymentConfirmation::reconcile( 9601, '9601_1', 0 );
    YS_Assert::eq( 'busy convergence lock prevents a concurrent API query', $queries_before_lock, $api->payment_queries );
    YS_Assert::eq( 'busy scheduled convergence leaves stage unchanged', 0, $lock_busy_query->get_meta( YSOrderMeta::CONFIRMATION_DATA )['stage'] ?? -1 );
    $GLOBALS['wpdb'] = null;

    $webhook_wrong = new YS_Confirmation_Test_Order();
    $GLOBALS['ys_test_order'] = $webhook_wrong;
    YSPaymentConfirmation::enter_confirmation( $webhook_wrong, ys_confirmation_attempt(), 'webhook_processing' );
    $wrong_payload = $webhook_payload;
    $wrong_payload['payment']['paidAmount']['value'] = 10001;
    YS_Assert::eq(
        'mismatched paid webhook is rejected',
        false,
        YSPaymentConfirmation::handle_paid_webhook( $webhook_wrong, $wrong_payload, 'SUCCEEDED' )
    );
    YS_Assert::eq( 'mismatched paid webhook remains locked', YSPaymentConfirmation::STATUS_KEY, $webhook_wrong->status );

    $webhook_terminal = new YS_Confirmation_Test_Order();
    $GLOBALS['ys_test_order'] = $webhook_terminal;
    YSPaymentConfirmation::enter_confirmation( $webhook_terminal, ys_confirmation_attempt(), 'webhook_processing' );
    YS_Assert::eq(
        'exact terminal webhook is handled by confirmation lifecycle',
        true,
        YSPaymentConfirmation::handle_terminal_webhook( $webhook_terminal, $webhook_payload, 'FAILED' )
    );
    YS_Assert::eq( 'exact terminal webhook reopens payment', 'pending', $webhook_terminal->status );

    $webhook_malformed = new YS_Confirmation_Test_Order();
    $GLOBALS['ys_test_order'] = $webhook_malformed;
    YSPaymentConfirmation::enter_confirmation( $webhook_malformed, ys_confirmation_attempt(), 'webhook_processing' );
    $malformed_payload = $webhook_payload;
    unset( $malformed_payload['payment']['paymentMethod'] );
    YS_Assert::eq(
        'terminal webhook missing method is fail-closed',
        false,
        YSPaymentConfirmation::handle_terminal_webhook( $webhook_malformed, $malformed_payload, 'FAILED' )
    );
    YS_Assert::eq( 'malformed terminal webhook remains locked', YSPaymentConfirmation::STATUS_KEY, $webhook_malformed->status );

    $external_paid = new YS_Confirmation_Test_Order();
    $GLOBALS['ys_test_order'] = $external_paid;
    YSPaymentConfirmation::enter_confirmation( $external_paid, ys_confirmation_attempt(), 'redirect' );
    $external_paid->paid = true;
    $external_paid->status = 'processing';
    YSPaymentConfirmation::clear_after_payment_complete( 9601 );
    YS_Assert::eq( 'external payment_complete clears active confirmation', '', $external_paid->get_meta( YSOrderMeta::CONFIRMATION_DATA ) );

    YS_Assert::eq( 'confirmation title is neutral', '付款確認中', YSPaymentConfirmation::confirmation_title() );
    YS_Assert::eq( 'terminal title is neutral', '付款確認未完成', YSPaymentConfirmation::terminal_title() );
    YS_Assert::eq( 'confirmation copy does not claim authorization success', false, false !== strpos( YSPaymentConfirmation::confirmation_message(), '授權成功' ) );
    YS_Assert::eq( 'terminal copy does not claim no debit', false, false !== strpos( YSPaymentConfirmation::terminal_message(), '未扣款' ) );
    YS_Assert::eq( 'terminal copy mentions possible bank hold', true, false !== strpos( YSPaymentConfirmation::terminal_message(), '保留款' ) );

    $GLOBALS['ys_test_mails'] = array();
    $GLOBALS['ys_test_options']['admin_email'] = 'merchant@example.test';
    $mail_order = new YS_Confirmation_Test_Order();
    $GLOBALS['ys_test_order'] = $mail_order;
    YSPaymentConfirmation::enter_confirmation( $mail_order, ys_confirmation_attempt(), 'webhook_processing' );
    YSPaymentConfirmation::handle_terminal_webhook( $mail_order, $webhook_payload, 'FAILED' );
    YS_Assert::eq( 'terminal convergence sends customer and merchant emails', 2, count( $GLOBALS['ys_test_mails'] ) );
    YS_Assert::eq( 'terminal customer notice goes to buyer', 'buyer@example.test', $GLOBALS['ys_test_mails'][0]['to'] ?? '' );
    YS_Assert::eq( 'terminal merchant notice goes to store admin', 'merchant@example.test', $GLOBALS['ys_test_mails'][1]['to'] ?? '' );
    YS_Assert::eq( 'mail map is keyed by reference', true, isset( $mail_order->get_meta( YSOrderMeta::CONFIRMATION_MAIL_SENT )['9601_1'] ) );

    YSPaymentConfirmation::enter_confirmation( $mail_order, ys_confirmation_attempt(), 'webhook_processing' );
    YSPaymentConfirmation::handle_terminal_webhook( $mail_order, $webhook_payload, 'FAILED' );
    YS_Assert::eq( 'same terminal reference never sends duplicate emails', 2, count( $GLOBALS['ys_test_mails'] ) );
    unset( $GLOBALS['ys_test_options']['admin_email'] );

    $paid_then_terminal = new YS_Confirmation_Test_Order();
    $GLOBALS['ys_test_order'] = $paid_then_terminal;
    YSPaymentConfirmation::enter_confirmation( $paid_then_terminal, ys_confirmation_attempt(), 'webhook_processing' );
    $paid_then_terminal->date_paid = '2026-07-17 12:00:00';
    YSPaymentConfirmation::handle_terminal_webhook( $paid_then_terminal, $webhook_payload, 'FAILED' );
    YS_Assert::eq( 'terminal event restores a paid-history order instead of reopening payment', 'processing', $paid_then_terminal->status );
    YS_Assert::eq( 'paid-history recovery does not rerun payment_complete', 0, $paid_then_terminal->payment_complete_count );
    YS_Assert::eq( 'paid-history recovery clears inconsistent confirmation lock', '', $paid_then_terminal->get_meta( YSOrderMeta::CONFIRMATION_DATA ) );

    $paid_then_paid = new YS_Confirmation_Test_Order();
    $GLOBALS['ys_test_order'] = $paid_then_paid;
    YSPaymentConfirmation::enter_confirmation( $paid_then_paid, ys_confirmation_attempt(), 'webhook_processing' );
    $paid_then_paid->date_paid = '2026-07-17 12:00:00';
    YSPaymentConfirmation::handle_paid_webhook( $paid_then_paid, $webhook_payload, 'SUCCEEDED' );
    YS_Assert::eq( 'late paid event restores paid-history order status', 'processing', $paid_then_paid->status );
    YS_Assert::eq( 'late paid event never reruns payment_complete with paid history', 0, $paid_then_paid->payment_complete_count );
}
