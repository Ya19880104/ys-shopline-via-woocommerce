<?php
/**
 * Contract tests for the admin new-order email bridge.
 *
 * @package YangSheep\ShoplinePayment\Tests
 */

declare(strict_types=1);

use YangSheep\ShoplinePayment\Handlers\YSPaymentConfirmation;
use YangSheep\ShoplinePayment\Utils\YSOrderMeta;

final class YS_New_Order_Email_Test_Order extends WC_Order {
    public bool $new_order_email_sent = false;
    public string $payment_method = 'ys_shopline_credit';
    public string $payment_status = 'SUCCEEDED';
    public array $confirmation_history = array();
    public $date_paid = '2026-08-15 10:00:00';
    public array $notes = array();

    public function __construct( private int $id = 9801 ) {}

    public function get_id(): int {
        return $this->id;
    }

    public function get_date_paid() {
        return $this->date_paid;
    }

    public function get_payment_method(): string {
        return $this->payment_method;
    }

    public function get_new_order_email_sent(): bool {
        return $this->new_order_email_sent;
    }

    public function get_meta( string $key ) {
        if ( '_new_order_email_sent' === $key ) {
            return $this->new_order_email_sent ? 'true' : '';
        }
        if ( YSOrderMeta::PAYMENT_STATUS === $key ) {
            return $this->payment_status;
        }
        if ( YSOrderMeta::CONFIRMATION_HISTORY === $key ) {
            return $this->confirmation_history;
        }
        return '';
    }

    public function add_order_note( string $note ): void {
        $this->notes[] = $note;
    }

    public function save(): void {}
}

final class YS_New_Order_Email_Test_Email {
    public int $trigger_calls = 0;
    private bool $trigger_context_ready = false;

    public function __construct(
        public bool $enabled = true,
        public string $recipient = 'merchant@example.test',
        public bool $succeeds = true,
        public bool $recipient_requires_trigger_context = false
    ) {}

    public function is_enabled(): bool {
        return $this->enabled;
    }

    public function get_recipient(): string {
        if ( $this->recipient_requires_trigger_context && ! $this->trigger_context_ready ) {
            return '';
        }

        return $this->recipient;
    }

    public function trigger( int $order_id, $order = false ): void {
        $this->trigger_calls++;
        $this->trigger_context_ready = true;
        if ( $this->enabled
            && '' !== trim( $this->get_recipient() )
            && $this->succeeds
            && $order instanceof YS_New_Order_Email_Test_Order ) {
            $order->new_order_email_sent = true;
        }
    }
}

function ys_run_new_order_email_contract(): void {
    echo "== Payment confirmation: admin new-order email bridge ==\n";

    if ( ! method_exists( YSPaymentConfirmation::class, 'register_email_actions' )
        || ! method_exists( YSPaymentConfirmation::class, 'maybe_send_admin_new_order_email' ) ) {
        YS_Assert::eq( 'confirmation lifecycle exposes the new-order email bridge', true, false );
        return;
    }

    $actions = YSPaymentConfirmation::register_email_actions( array( 'woocommerce_new_customer_note' ) );
    YS_Assert::eq(
        'processing transition is a transactional email action',
        true,
        in_array( 'woocommerce_order_status_ys-confirming_to_processing', $actions, true )
    );
    YS_Assert::eq(
        'completed transition is a transactional email action',
        true,
        in_array( 'woocommerce_order_status_ys-confirming_to_completed', $actions, true )
    );
    YS_Assert::eq( 'transactional email actions remain unique', count( array_unique( $actions ) ), count( $actions ) );

    $GLOBALS['ys_test_actions'] = array();
    $GLOBALS['ys_test_filter_registrations'] = array();
    $GLOBALS['ys_test_filters'] = array();
    YSPaymentConfirmation::init();
    $registered_hooks = array_column( $GLOBALS['ys_test_actions'], 'hook' );
    YS_Assert::eq(
        'processing notification hook is bridged',
        true,
        in_array( 'woocommerce_order_status_ys-confirming_to_processing_notification', $registered_hooks, true )
    );
    YS_Assert::eq(
        'completed notification hook is bridged',
        true,
        in_array( 'woocommerce_order_status_ys-confirming_to_completed_notification', $registered_hooks, true )
    );
    YS_Assert::eq(
        'new-order email retry hook is registered',
        true,
        in_array( YSPaymentConfirmation::NEW_ORDER_EMAIL_RETRY_HOOK, $registered_hooks, true )
    );

    $order = new YS_New_Order_Email_Test_Order();
    $email = new YS_New_Order_Email_Test_Email();
    $GLOBALS['ys_test_order'] = $order;
    $GLOBALS['wpdb'] = null;
    $GLOBALS['ys_test_scheduled_actions'] = array();
    WC()->mailer()->emails = array( 'WC_Email_New_Order' => $email );

    YSPaymentConfirmation::maybe_send_admin_new_order_email( $order->get_id(), $order );
    YSPaymentConfirmation::maybe_send_admin_new_order_email( $order->get_id(), $order );
    YS_Assert::eq( 'paid confirmation transition sends the native new-order email once', 1, $email->trigger_calls );
    YS_Assert::eq( 'successful native email records its persistent sent flag', true, $order->new_order_email_sent );

    $unpaid = new YS_New_Order_Email_Test_Order( 9802 );
    $unpaid->date_paid = null;
    $unpaid_email = new YS_New_Order_Email_Test_Email();
    $GLOBALS['ys_test_order'] = $unpaid;
    WC()->mailer()->emails = array( 'WC_Email_New_Order' => $unpaid_email );
    YSPaymentConfirmation::maybe_send_admin_new_order_email( $unpaid->get_id(), $unpaid );
    YS_Assert::eq( 'unpaid confirmation transition never sends admin email', 0, $unpaid_email->trigger_calls );

    $manual = new YS_New_Order_Email_Test_Order( 9808 );
    $manual->payment_status = 'PROCESSING';
    $manual_email = new YS_New_Order_Email_Test_Email();
    $GLOBALS['ys_test_order'] = $manual;
    WC()->mailer()->emails = array( 'WC_Email_New_Order' => $manual_email );
    YSPaymentConfirmation::maybe_send_admin_new_order_email( $manual->get_id(), $manual );
    YS_Assert::eq( 'manual paid-status transition without SHOPLINE paid evidence never sends', 0, $manual_email->trigger_calls );

    $refunded_after_queue = new YS_New_Order_Email_Test_Order( 9809 );
    $refunded_after_queue->payment_status = 'REFUNDED';
    $refunded_after_queue->confirmation_history = array(
        array( 'remote_status' => 'SUCCEEDED' ),
    );
    $refunded_after_queue_email = new YS_New_Order_Email_Test_Email();
    $GLOBALS['ys_test_order'] = $refunded_after_queue;
    WC()->mailer()->emails = array( 'WC_Email_New_Order' => $refunded_after_queue_email );
    YSPaymentConfirmation::maybe_send_admin_new_order_email( $refunded_after_queue->get_id(), $refunded_after_queue );
    YS_Assert::eq( 'deferred email accepts durable SHOPLINE paid history after refund', 1, $refunded_after_queue_email->trigger_calls );

    $foreign = new YS_New_Order_Email_Test_Order( 9803 );
    $foreign->payment_method = 'cod';
    $foreign_email = new YS_New_Order_Email_Test_Email();
    $GLOBALS['ys_test_order'] = $foreign;
    WC()->mailer()->emails = array( 'WC_Email_New_Order' => $foreign_email );
    YSPaymentConfirmation::maybe_send_admin_new_order_email( $foreign->get_id(), $foreign );
    YS_Assert::eq( 'non-SHOPLINE order never enters the bridge', 0, $foreign_email->trigger_calls );

    $disabled = new YS_New_Order_Email_Test_Order( 9804 );
    $disabled_email = new YS_New_Order_Email_Test_Email( false );
    $GLOBALS['ys_test_order'] = $disabled;
    $GLOBALS['ys_test_scheduled_actions'] = array();
    WC()->mailer()->emails = array( 'WC_Email_New_Order' => $disabled_email );
    YSPaymentConfirmation::maybe_send_admin_new_order_email( $disabled->get_id(), $disabled );
    YS_Assert::eq( 'disabled WooCommerce email enters only the native trigger', 1, $disabled_email->trigger_calls );
    YS_Assert::eq( 'disabled WooCommerce email remains unsent', false, $disabled->new_order_email_sent );
    YS_Assert::eq( 'disabled email is not backfilled by retry', 0, count( $GLOBALS['ys_test_scheduled_actions'] ) );

    $dynamic = new YS_New_Order_Email_Test_Order( 9807 );
    $dynamic_email = new YS_New_Order_Email_Test_Email( true, 'dynamic@example.test', true, true );
    $GLOBALS['ys_test_order'] = $dynamic;
    $GLOBALS['ys_test_scheduled_actions'] = array();
    WC()->mailer()->emails = array( 'WC_Email_New_Order' => $dynamic_email );
    YSPaymentConfirmation::maybe_send_admin_new_order_email( $dynamic->get_id(), $dynamic );
    YS_Assert::eq( 'order-aware recipient filters run with native email context', 1, $dynamic_email->trigger_calls );
    YS_Assert::eq( 'order-aware recipient sends through the native email', true, $dynamic->new_order_email_sent );

    $busy = new YS_New_Order_Email_Test_Order( 9805 );
    $busy_email = new YS_New_Order_Email_Test_Email();
    $GLOBALS['ys_test_order'] = $busy;
    $GLOBALS['ys_test_scheduled_actions'] = array();
    $GLOBALS['wpdb'] = new wpdb( false );
    WC()->mailer()->emails = array( 'WC_Email_New_Order' => $busy_email );
    YSPaymentConfirmation::maybe_send_admin_new_order_email( $busy->get_id(), $busy );
    YS_Assert::eq( 'busy email lock never sends concurrently', 0, $busy_email->trigger_calls );
    YS_Assert::eq( 'busy email lock schedules one safe retry', 1, count( $GLOBALS['ys_test_scheduled_actions'] ) );

    $failed = new YS_New_Order_Email_Test_Order( 9806 );
    $failed_email = new YS_New_Order_Email_Test_Email( true, 'merchant@example.test', false );
    $GLOBALS['ys_test_order'] = $failed;
    $GLOBALS['ys_test_scheduled_actions'] = array();
    $GLOBALS['wpdb'] = null;
    WC()->mailer()->emails = array( 'WC_Email_New_Order' => $failed_email );
    YSPaymentConfirmation::maybe_send_admin_new_order_email( $failed->get_id(), $failed );
    YS_Assert::eq( 'failed native email schedules one bounded retry', 1, count( $GLOBALS['ys_test_scheduled_actions'] ) );
    $failed_email->succeeds = true;
    YSPaymentConfirmation::retry_admin_new_order_email( $failed->get_id(), 2 );
    YS_Assert::eq( 'retry uses the native new-order email and succeeds once', 2, $failed_email->trigger_calls );
    YS_Assert::eq( 'successful retry records the native sent flag', true, $failed->new_order_email_sent );

    $GLOBALS['wpdb'] = null;
}
