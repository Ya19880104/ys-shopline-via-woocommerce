<?php
/**
 * Status Manager
 *
 * @package YangSheep\ShoplinePayment\Handlers
 */

declare(strict_types=1);

namespace YangSheep\ShoplinePayment\Handlers;

use YangSheep\ShoplinePayment\Api\YSShoplineClient;
use YangSheep\ShoplinePayment\DTOs\YSPaymentDTO;
use YangSheep\ShoplinePayment\Utils\YSLogger;
use YangSheep\ShoplinePayment\Utils\YSOrderMeta;

defined( 'ABSPATH' ) || exit;

/**
 * Keep WooCommerce order status in sync with SHOPLINE payment status.
 */
final class YSStatusManager {

    /**
     * All SHOPLINE gateway IDs used by this plugin.
     *
     * Includes legacy IDs for backward compatibility with old orders.
     *
     * @var array<int, string>
     */
    private const SHOPLINE_GATEWAY_IDS = [
        'ys_shopline_credit',
        'ys_shopline_credit_subscription',
        'ys_shopline_atm',
        'ys_shopline_jkopay',
        'ys_shopline_applepay',
        'ys_shopline_linepay',
        'ys_shopline_bnpl',
    ];

    /**
     * Map SHOPLINE payment status to WooCommerce order status.
     *
     * @var array<string, string>
     */
    private const STATUS_MAP = [
        'CREATED'          => 'pending',
        'CUSTOMER_ACTION'  => 'pending',
        'PENDING'          => 'pending',
        'PROCESSING'       => 'on-hold',
        'AUTHORIZED'       => 'on-hold',
        'SUCCEEDED'        => 'processing',
        'CAPTURED'         => 'processing',
        'FAILED'           => 'failed',
        'CANCELLED'        => 'cancelled',
        'EXPIRED'          => 'cancelled',
        'REFUNDED'         => 'refunded',
        'PARTIALLY_REFUND' => 'processing',
    ];

    /**
     * Legacy status aliases.
     *
     * @var array<string, string>
     */
    private const STATUS_ALIASES = [
        'SUCCESS' => 'SUCCEEDED',
    ];

    /**
     * Bootstrap.
     */
    public static function init(): void {
        $instance = new self();
        $instance->register_hooks();
    }

    /**
     * Register hooks.
     */
    private function register_hooks(): void {
        add_action( 'woocommerce_order_status_changed', [ $this, 'handle_order_status_change' ], 10, 4 );

        add_filter( 'woocommerce_order_actions', [ $this, 'add_sync_action' ] );
        add_action( 'woocommerce_order_action_ys_sync_payment_status', [ $this, 'sync_payment_status' ] );

        add_action( 'ys_shopline_sync_pending_orders', [ $this, 'sync_pending_orders' ] );

        if ( ! wp_next_scheduled( 'ys_shopline_sync_pending_orders' ) ) {
            wp_schedule_event( time(), 'hourly', 'ys_shopline_sync_pending_orders' );
        }
    }

    /**
     * Handle WC order status changes.
     *
     * @param int      $order_id   Order ID.
     * @param string   $old_status Old WC status.
     * @param string   $new_status New WC status.
     * @param \WC_Order $order     Order object.
     */
    public function handle_order_status_change( int $order_id, string $old_status, string $new_status, \WC_Order $order ): void {
        if ( ! $this->is_shopline_order( $order ) ) {
            return;
        }

        if ( 'cancelled' === $new_status && in_array( $old_status, [ 'pending', 'on-hold' ], true ) ) {
            $this->cancel_payment( $order );
        }
    }

    /**
     * Add manual sync action on WC order admin page.
     *
     * @param array<string, string> $actions Available actions.
     * @return array<string, string>
     */
    public function add_sync_action( array $actions ): array {
        global $theorder;

        if ( $theorder instanceof \WC_Order && $this->is_shopline_order( $theorder ) ) {
            $actions['ys_sync_payment_status'] = __( 'Sync SHOPLINE payment status', 'ys-shopline-via-woocommerce' );
        }

        return $actions;
    }

    /**
     * Manually sync payment status for one order.
     *
     * @param \WC_Order $order Order object.
     */
    public function sync_payment_status( \WC_Order $order ): void {
        $trade_order_id = (string) $order->get_meta( YSOrderMeta::TRADE_ORDER_ID );
        $session_id     = (string) $order->get_meta( YSOrderMeta::SESSION_ID );

        if ( '' === $trade_order_id && '' === $session_id ) {
            $order->add_order_note( __( 'Cannot sync SHOPLINE status: missing trade/session ID.', 'ys-shopline-via-woocommerce' ) );
            return;
        }

        try {
            $client = $this->get_api_client( $order );

            if ( '' !== $trade_order_id ) {
                $payment_dto = $client->query_payment( $trade_order_id );
                $this->update_order_from_payment( $order, $payment_dto );
            } else {
                $session_dto = $client->query_session( $session_id );

                if ( ! empty( $session_dto->trade_order_id ) ) {
                    $order->update_meta_data( YSOrderMeta::TRADE_ORDER_ID, $session_dto->trade_order_id );

                    $payment_dto = $client->query_payment( $session_dto->trade_order_id );
                    $this->update_order_from_payment( $order, $payment_dto );
                } else {
                    $order->add_order_note(
                        sprintf(
                            /* translators: %s: session status */
                            __( 'Session status is %s; no trade order is available yet.', 'ys-shopline-via-woocommerce' ),
                            $session_dto->status ?: 'UNKNOWN'
                        )
                    );
                }
            }

            $order->save();

            YSLogger::info( 'SHOPLINE status sync completed', [
                'order_id' => $order->get_id(),
            ] );
        } catch ( \Throwable $e ) {
            YSLogger::error( 'SHOPLINE status sync failed: ' . $e->getMessage(), [
                'order_id' => $order->get_id(),
            ] );

            $order->add_order_note(
                sprintf(
                    /* translators: %s: error message */
                    __( 'SHOPLINE status sync failed: %s', 'ys-shopline-via-woocommerce' ),
                    $e->getMessage()
                )
            );
        }
    }

    /**
     * Periodic sync for recent pending/on-hold orders.
     */
    public function sync_pending_orders(): void {
        $orders = wc_get_orders( [
            'status'         => [ 'pending', 'on-hold' ],
            'date_created'   => '>' . gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ),
            'payment_method' => self::SHOPLINE_GATEWAY_IDS,
            'limit'          => 50,
        ] );

        foreach ( $orders as $order ) {
            if ( ! $order instanceof \WC_Order ) {
                continue;
            }

            try {
                $this->sync_payment_status( $order );
            } catch ( \Throwable $e ) {
                YSLogger::error( 'Scheduled SHOPLINE sync failed: ' . $e->getMessage(), [
                    'order_id' => $order->get_id(),
                ] );
            }
        }
    }

    /**
     * Cancel payment in SHOPLINE when WC order is cancelled.
     *
     * @param \WC_Order $order Order object.
     */
    private function cancel_payment( \WC_Order $order ): void {
        $trade_order_id = (string) $order->get_meta( YSOrderMeta::TRADE_ORDER_ID );

        if ( '' === $trade_order_id ) {
            return;
        }

        try {
            $client   = $this->get_api_client( $order );
            $response = $client->cancel_payment( $trade_order_id );

            $status = '';
            if ( isset( $response['status'] ) && is_string( $response['status'] ) ) {
                $status = $this->normalize_status( $response['status'] );
            }

            if ( '' !== $status ) {
                $order->update_meta_data( YSOrderMeta::PAYMENT_STATUS, $status );
            }

            $order->add_order_note( __( 'SHOPLINE payment cancel request sent.', 'ys-shopline-via-woocommerce' ) );
            $order->save();
        } catch ( \Throwable $e ) {
            YSLogger::error( 'Failed to cancel SHOPLINE payment: ' . $e->getMessage(), [
                'order_id'       => $order->get_id(),
                'trade_order_id' => $trade_order_id,
            ] );
        }
    }

    /**
     * Update order from payment DTO.
     *
     * @param \WC_Order     $order       Order object.
     * @param YSPaymentDTO $payment_dto Payment DTO.
     */
    private function update_order_from_payment( \WC_Order $order, YSPaymentDTO $payment_dto ): void {
        $status = $this->normalize_status( $payment_dto->status );

        if ( ! empty( $payment_dto->payment_method ) ) {
            $order->update_meta_data( YSOrderMeta::PAYMENT_METHOD, $payment_dto->payment_method );
        }
        $order->update_meta_data( YSOrderMeta::TRADE_ORDER_ID, $payment_dto->trade_order_id );
        $order->update_meta_data( YSOrderMeta::PAYMENT_STATUS, $status );
        $order->update_meta_data( YSOrderMeta::PAYMENT_DETAIL, $payment_dto->to_array() );

        $wc_status = self::STATUS_MAP[ $status ] ?? null;

        if ( null !== $wc_status && $order->get_status() !== $wc_status ) {
            if ( 'processing' === $wc_status && ! $order->is_paid() ) {
                $order->payment_complete( $payment_dto->trade_order_id );
            } elseif ( 'on-hold' === $wc_status ) {
                $order->update_status( 'on-hold' );
            } elseif ( 'refunded' === $wc_status ) {
                $order->update_status( 'refunded' );
            } elseif ( in_array( $wc_status, [ 'failed', 'cancelled' ], true ) && ! $order->is_paid() ) {
                $order->update_status( $wc_status );
            }
        }

        $order->add_order_note(
            sprintf(
                /* translators: 1: payment status 2: payment method */
                __( 'Synced SHOPLINE payment status: %1$s (%2$s).', 'ys-shopline-via-woocommerce' ),
                $status,
                $payment_dto->get_payment_method_display()
            )
        );
    }

    /**
     * Check if order was paid by this SHOPLINE plugin.
     *
     * @param \WC_Order $order Order object.
     * @return bool
     */
    private function is_shopline_order( \WC_Order $order ): bool {
        $payment_method = (string) $order->get_payment_method();

        if ( '' === $payment_method ) {
            return false;
        }

        if ( in_array( $payment_method, self::SHOPLINE_GATEWAY_IDS, true ) ) {
            return true;
        }

        return str_starts_with( $payment_method, 'ys_shopline_' );
    }

    /**
     * Build API client for the order.
     *
     * @param \WC_Order $order Order object.
     * @return YSShoplineClient
     */
    private function get_api_client( \WC_Order $order ): YSShoplineClient {
        $client = new YSShoplineClient( $order );

        if ( ! $client->has_credentials() ) {
            throw new \RuntimeException( __( 'SHOPLINE API credentials are not configured.', 'ys-shopline-via-woocommerce' ) );
        }

        return $client;
    }

    /**
     * Normalize status to canonical SHOPLINE status.
     *
     * @param string $status Raw status.
     * @return string
     */
    private function normalize_status( string $status ): string {
        $status = strtoupper( trim( $status ) );
        return self::STATUS_ALIASES[ $status ] ?? $status;
    }
}
