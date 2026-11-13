<?php
/** Terminal webhooks must not mutate a later payment attempt. */

declare(strict_types=1);

use YangSheep\ShoplinePayment\Handlers\YSPaymentConfirmation;
use YangSheep\ShoplinePayment\Handlers\YSStatusManager;
use YangSheep\ShoplinePayment\Handlers\YSWebhookHandler;
use YangSheep\ShoplinePayment\Utils\YSOrderMeta;

final class YS_Terminal_Attempt_Order extends WC_Order {
    public array $meta = array();
    public array $notes = array();
    public array $transitions = array();
    public string $status = 'pending';
    public int $save_count = 0;
    public $date_paid = null;

    public function get_id(): int { return 9601; }
    public function get_status(): string { return $this->status; }
    public function get_payment_method(): string { return 'ys_shopline_credit'; }
    public function get_date_paid() { return $this->date_paid; }
    public function is_paid(): bool { return false; }
    public function get_meta( string $key ) { return $this->meta[ $key ] ?? ''; }
    public function update_meta_data( string $key, $value ): void { $this->meta[ $key ] = $value; }
    public function delete_meta_data( string $key ): void { unset( $this->meta[ $key ] ); }
    public function add_order_note( string $note ): void { $this->notes[] = $note; }
    public function save(): void { $this->save_count++; }
    public function update_status( string $status, string $note = '' ): void {
        $old = $this->status;
        $this->status = $status;
        $this->notes[] = $note;
        if ( $old !== $status ) {
            $this->transitions[] = array( $old, $status );
            // Exercise the real plugin status hook, including its cancel API call.
            ( new YSStatusManager() )->handle_order_status_change( $this->get_id(), $old, $status, $this );
        }
    }
}

final class YS_Terminal_Attempt_Api {
    public array $cancels = array();
    public function cancel_payment_by_ids( string $trade, string $reference ): array {
        $this->cancels[] = array( $trade, $reference );
        return array( 'status' => 'CANCELLED' );
    }
}

function ys_terminal_attempt_order(): YS_Terminal_Attempt_Order {
    $order = new YS_Terminal_Attempt_Order();
    $order->meta = array(
        YSOrderMeta::TRADE_ORDER_ID => 'trade-B',
        YSOrderMeta::REFERENCE_ORDER_ID => '9601_2',
        YSOrderMeta::PAYMENT_STATUS => 'CUSTOMER_ACTION',
        YSOrderMeta::PAYMENT_DETAIL => array( 'tradeOrderId' => 'trade-B' ),
        YSOrderMeta::PAYMENT_ATTEMPT_DATA => array( 'reference' => '9601_2' ),
    );
    $GLOBALS['ys_test_order'] = $order;
    $GLOBALS['ys_test_orders'] = array();
    $GLOBALS['ys_test_stock_calls'] = array( 'increase' => 0, 'reduce' => 0 );
    $GLOBALS['ys_test_logs'] = array();
    YSShoplinePayment::$test_api = new YS_Terminal_Attempt_Api();
    return $order;
}

function ys_run_webhook_terminal_attempt_contract(): void {
    echo "== Webhook: exact terminal attempt and status-hook side effects ==\n";
    $handler = new YSWebhookHandler();
    $events = array(
        'handle_trade_failed' => 'FAILED',
        'handle_payment_cancelled' => 'CANCELLED',
        'handle_trade_expired' => 'EXPIRED',
    );
    $cases = array(
        'old trade and reference' => array( 'trade-A', '9601_1', false ),
        'old trade with current reference' => array( 'trade-A', '9601_2', false ),
        'current trade with old reference' => array( 'trade-B', '9601_1', true ),
        'current trade without reference' => array( 'trade-B', null, true ),
        'no local trade' => array( 'trade-B', '9601_2', false ),
        'no local reference' => array( 'trade-B', '9601_2', true ),
    );
    foreach ( $events as $method => $status ) {
        foreach ( $cases as $name => [ $trade, $reference, $found_by_trade ] ) {
            $order = ys_terminal_attempt_order();
            if ( 'no local trade' === $name ) {
                unset( $order->meta[ YSOrderMeta::TRADE_ORDER_ID ] );
            }
            if ( 'no local reference' === $name ) {
                unset( $order->meta[ YSOrderMeta::REFERENCE_ORDER_ID ] );
            }
            $GLOBALS['ys_test_orders'] = $found_by_trade ? array( $order ) : array();
            $before = $order->meta;
            $payload = array( 'tradeOrderId' => $trade, 'status' => $status );
            if ( null !== $reference ) {
                $payload['referenceOrderId'] = $reference;
            }
            ys_invoke_webhook_handler( $handler, $method, $payload );
            $label = "$status / $name";
            YS_Assert::eq( "$label leaves order pending", 'pending', $order->status );
            YS_Assert::eq( "$label leaves all payment metadata unchanged", $before, $order->meta );
            YS_Assert::eq( "$label never saves the order", 0, $order->save_count );
            YS_Assert::eq( "$label never transitions status", array(), $order->transitions );
            YS_Assert::eq( "$label never restores stock", 0, $GLOBALS['ys_test_stock_calls']['increase'] );
            YS_Assert::eq( "$label never cancels the current trade", array(), YSShoplinePayment::$test_api->cancels );
        }

        foreach ( array( 'pending', 'on-hold' ) as $initial ) {
            $order = ys_terminal_attempt_order();
            $order->status = $initial;
            $GLOBALS['ys_test_orders'] = array( $order );
            $payload = array( 'tradeOrderId' => 'trade-B', 'referenceOrderId' => '9601_2', 'status' => $status );
            ys_invoke_webhook_handler( $handler, $method, $payload );
            YS_Assert::eq( "$status / $initial accepts exact current attempt", 'CANCELLED' === $status ? 'cancelled' : 'failed', $order->status );
            YS_Assert::eq( "$status / $initial writes terminal payment metadata", $status, $order->get_meta( YSOrderMeta::PAYMENT_STATUS ) );
            YS_Assert::eq( "$status / $initial never cancels an already-terminal trade", array(), YSShoplinePayment::$test_api->cancels );
            YS_Assert::eq( "$status / $initial preserves normal failed restock hook", 'CANCELLED' === $status ? 0 : 1, $GLOBALS['ys_test_stock_calls']['increase'] );
            ys_invoke_webhook_handler( $handler, $method, $payload );
            YS_Assert::eq( "$status duplicate has only one status transition", 1, count( $order->transitions ) );
        }
    }

    // An already-cancelled order must not accept an old event's metadata either.
    $order = ys_terminal_attempt_order();
    $order->status = 'cancelled';
    $before = $order->meta;
    ys_invoke_webhook_handler( $handler, 'handle_payment_cancelled', array( 'tradeOrderId' => 'trade-A', 'referenceOrderId' => '9601_1' ) );
    YS_Assert::eq( 'cancelled order ignores a prior attempt cancellation', $before, $order->meta );
    $GLOBALS['ys_test_orders'] = array( $order );
    ys_invoke_webhook_handler( $handler, 'handle_payment_cancelled', array( 'tradeOrderId' => 'trade-B', 'referenceOrderId' => '9601_2' ) );
    YS_Assert::eq( 'cancelled order still converges its current cancellation', 'CANCELLED', $order->get_meta( YSOrderMeta::PAYMENT_STATUS ) );

    foreach ( $events as $method => $status ) {
        $order = new YS_Confirmation_Test_Order();
        $GLOBALS['ys_test_order'] = $order;
        $GLOBALS['ys_test_orders'] = array();
        YSPaymentConfirmation::enter_confirmation( $order, ys_confirmation_attempt( array( 'reference' => '9601_2', 'trade_order_id' => '' ) ), 'terminal_attempt_test' );
        $before = $order->meta;
        $payload = array(
            'tradeOrderId' => 'trade-A',
            'referenceOrderId' => '9601_1',
            'payment' => array( 'paymentMethod' => 'CreditCard' ),
            'order' => array( 'amount' => array( 'value' => 10000, 'currency' => 'TWD' ) ),
        );
        ys_invoke_webhook_handler( $handler, $method, $payload );
        YS_Assert::eq( "$status old attempt preserves confirmation state", YSPaymentConfirmation::STATUS_KEY, $order->status );
        YS_Assert::eq( "$status old attempt preserves confirmation metadata", $before, $order->meta );
        $payload['tradeOrderId'] = 'trade-B';
        $payload['referenceOrderId'] = '9601_2';
        ys_invoke_webhook_handler( $handler, $method, $payload );
        YS_Assert::eq( "$status exact unknown-trade confirmation still reopens payment", 'pending', $order->status );
        YS_Assert::eq( "$status exact confirmation clears its active marker", '', $order->get_meta( YSOrderMeta::CONFIRMATION_DATA ) );
    }

    foreach ( array( 'handle_trade_failed', 'handle_trade_expired' ) as $method ) {
        $order = ys_terminal_attempt_order();
        unset( $order->meta[ YSOrderMeta::TRADE_ORDER_ID ] );
        $order->meta[ YSOrderMeta::INDETERMINATE_REF ] = '9601_2';
        $order->meta[ YSOrderMeta::INDETERMINATE_DATA ] = array( 'shopline_method' => 'CreditCard', 'amount' => 10000, 'currency' => 'TWD' );
        $payload = array(
            'tradeOrderId' => 'trade-A', 'referenceOrderId' => '9601_1',
            'payment' => array( 'paymentMethod' => 'CreditCard' ),
            'order' => array( 'amount' => array( 'value' => 10000, 'currency' => 'TWD' ) ),
        );
        $before = $order->meta;
        ys_invoke_webhook_handler( $handler, $method, $payload );
        YS_Assert::eq( "$method old attempt keeps indeterminate metadata", $before, $order->meta );
        $payload['tradeOrderId'] = 'trade-B';
        $payload['referenceOrderId'] = '9601_2';
        ys_invoke_webhook_handler( $handler, $method, $payload );
        YS_Assert::eq( "$method exact legacy indeterminate event still releases marker", '', $order->get_meta( YSOrderMeta::INDETERMINATE_REF ) );
        YS_Assert::eq( "$method exact legacy indeterminate event retains its terminal transition", 'failed', $order->status );

        $order = ys_terminal_attempt_order();
        $order->meta[ YSOrderMeta::INDETERMINATE_REF ] = '9601_1';
        $order->meta[ YSOrderMeta::INDETERMINATE_DATA ] = array( 'shopline_method' => 'CreditCard', 'amount' => 10000, 'currency' => 'TWD' );
        $before = $order->meta;
        $payload['tradeOrderId'] = 'trade-A';
        $payload['referenceOrderId'] = '9601_1';
        ys_invoke_webhook_handler( $handler, $method, $payload );
        YS_Assert::eq( "$method stale legacy marker cannot mutate current payment metadata", $before, $order->meta );
        YS_Assert::eq( "$method stale legacy marker cannot change current order status", 'pending', $order->status );
    }
    $GLOBALS['ys_test_order'] = null;
    $GLOBALS['ys_test_orders'] = array();
    YSShoplinePayment::$test_api = null;
}
