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
 * 訂單狀態管理器
 *
 * 同步 Shopline Payment 與 WooCommerce 訂單狀態
 */
final class YSStatusManager {

    /**
     * Shopline Payment 狀態對應 WooCommerce 狀態
     */
    private const STATUS_MAP = [
        // 付款狀態
        'PENDING'    => 'pending',
        'PROCESSING' => 'on-hold',
        'SUCCEEDED'  => 'processing',
        'FAILED'     => 'failed',
        'CANCELLED'  => 'cancelled',
        'EXPIRED'    => 'cancelled',

        // 退款狀態
        'REFUNDED'         => 'refunded',
        'PARTIALLY_REFUND' => 'processing',
    ];

    /**
     * 初始化
     */
    public static function init(): void {
        $instance = new self();
        $instance->register_hooks();
    }

    /**
     * 註冊 Hooks
     */
    private function register_hooks(): void {
        // 訂單狀態變更時同步
        add_action( 'woocommerce_order_status_changed', [ $this, 'handle_order_status_change' ], 10, 4 );

        // 後台手動同步按鈕
        add_action( 'woocommerce_order_actions', [ $this, 'add_sync_action' ] );
        add_action( 'woocommerce_order_action_ys_sync_payment_status', [ $this, 'sync_payment_status' ] );

        // 定時同步（處理 Webhook 遺漏）
        add_action( 'ys_shopline_sync_pending_orders', [ $this, 'sync_pending_orders' ] );

        // 註冊排程
        if ( ! wp_next_scheduled( 'ys_shopline_sync_pending_orders' ) ) {
            wp_schedule_event( time(), 'hourly', 'ys_shopline_sync_pending_orders' );
        }
    }

    /**
     * 處理訂單狀態變更
     *
     * @param int $order_id 訂單 ID
     * @param string $old_status 舊狀態
     * @param string $new_status 新狀態
     * @param \WC_Order $order 訂單物件
     */
    public function handle_order_status_change( int $order_id, string $old_status, string $new_status, \WC_Order $order ): void {
        // 只處理 Shopline Payment 訂單
        if ( ! $this->is_shopline_order( $order ) ) {
            return;
        }

        // 取消訂單時，嘗試取消 Shopline 付款
        if ( 'cancelled' === $new_status && in_array( $old_status, [ 'pending', 'on-hold' ], true ) ) {
            $this->cancel_payment( $order );
        }
    }

    /**
     * 新增同步動作
     *
     * @param array $actions 動作列表
     * @return array
     */
    public function add_sync_action( array $actions ): array {
        global $theorder;

        if ( $theorder && $this->is_shopline_order( $theorder ) ) {
            $actions['ys_sync_payment_status'] = __( '同步 Shopline Payment 狀態', 'ys-shopline-payment' );
        }

        return $actions;
    }

    /**
     * 同步付款狀態
     *
     * @param \WC_Order $order 訂單物件
     */
    public function sync_payment_status( \WC_Order $order ): void {
        $trade_order_id = $order->get_meta( YSOrderMeta::TRADE_ORDER_ID );
        $session_id     = $order->get_meta( YSOrderMeta::SESSION_ID );

        if ( ! $trade_order_id && ! $session_id ) {
            $order->add_order_note( __( '無法同步：缺少交易資訊', 'ys-shopline-payment' ) );
            return;
        }

        try {
            $client = $this->get_api_client( $order );

            // 優先查詢付款狀態
            if ( $trade_order_id ) {
                $payment_dto = $client->query_payment( $trade_order_id );
                $this->update_order_from_payment( $order, $payment_dto );
            } elseif ( $session_id ) {
                // 查詢 Session 狀態
                $session_dto = $client->query_session( $session_id );

                if ( $session_dto->trade_order_id ) {
                    $order->update_meta_data( YSOrderMeta::TRADE_ORDER_ID, $session_dto->trade_order_id );

                    // 再查詢付款狀態
                    $payment_dto = $client->query_payment( $session_dto->trade_order_id );
                    $this->update_order_from_payment( $order, $payment_dto );
                } else {
                    $order->add_order_note(
                        sprintf(
                            /* translators: %s: Session status */
                            __( 'Session 狀態：%s，尚未完成付款', 'ys-shopline-payment' ),
                            $session_dto->session_status
                        )
                    );
                }
            }

            $order->save();

            YSLogger::info( '訂單狀態同步完成', [
                'order_id' => $order->get_id(),
            ] );
        } catch ( \Throwable $e ) {
            YSLogger::error( "訂單狀態同步失敗: {$e->getMessage()}", [
                'order_id' => $order->get_id(),
            ] );

            $order->add_order_note(
                sprintf(
                    /* translators: %s: Error message */
                    __( '同步失敗：%s', 'ys-shopline-payment' ),
                    $e->getMessage()
                )
            );
        }
    }

    /**
     * 同步待處理訂單
     */
    public function sync_pending_orders(): void {
        // 取得過去 24 小時內的待處理訂單
        $orders = wc_get_orders( [
            'status'       => [ 'pending', 'on-hold' ],
            'date_created' => '>' . ( time() - DAY_IN_SECONDS ),
            'payment_method' => [
                'ys_shopline_redirect',
                'ys_shopline_token',
            ],
            'limit'        => 50,
        ] );

        foreach ( $orders as $order ) {
            try {
                $this->sync_payment_status( $order );
            } catch ( \Throwable $e ) {
                YSLogger::error( "自動同步失敗: {$e->getMessage()}", [
                    'order_id' => $order->get_id(),
                ] );
            }
        }
    }

    /**
     * 取消付款
     *
     * @param \WC_Order $order 訂單物件
     */
    private function cancel_payment( \WC_Order $order ): void {
        $trade_order_id = $order->get_meta( YSOrderMeta::TRADE_ORDER_ID );

        if ( ! $trade_order_id ) {
            return;
        }

        try {
            $client  = $this->get_api_client( $order );
            $success = $client->cancel_payment( $trade_order_id );

            if ( $success ) {
                $order->add_order_note( __( 'Shopline Payment 付款已取消', 'ys-shopline-payment' ) );
            }
        } catch ( \Throwable $e ) {
            YSLogger::error( "取消付款失敗: {$e->getMessage()}", [
                'order_id'       => $order->get_id(),
                'trade_order_id' => $trade_order_id,
            ] );
        }
    }

    /**
     * 從付款資訊更新訂單
     *
     * @param \WC_Order $order 訂單物件
     * @param YSPaymentDTO $payment_dto 付款資訊
     */
    private function update_order_from_payment( \WC_Order $order, YSPaymentDTO $payment_dto ): void {
        // 儲存付款資訊
        $order->update_meta_data( YSOrderMeta::PAYMENT_METHOD, $payment_dto->payment_method );
        $order->update_meta_data( YSOrderMeta::PAYMENT_STATUS, $payment_dto->payment_status );
        $order->update_meta_data( YSOrderMeta::PAYMENT_DETAIL, $payment_dto->to_array() );

        // 取得對應的 WooCommerce 狀態
        $wc_status = self::STATUS_MAP[ strtoupper( $payment_dto->payment_status ) ] ?? null;

        if ( $wc_status && $order->get_status() !== $wc_status ) {
            // 根據狀態更新
            if ( 'processing' === $wc_status && ! $order->is_paid() ) {
                $order->payment_complete( $payment_dto->trade_order_id );
            } elseif ( in_array( $wc_status, [ 'failed', 'cancelled' ], true ) ) {
                $order->update_status( $wc_status );
            }
        }

        $order->add_order_note(
            sprintf(
                /* translators: 1: Payment status 2: Payment method */
                __( '從 Shopline Payment 同步狀態：%1$s（%2$s）', 'ys-shopline-payment' ),
                $payment_dto->payment_status,
                $payment_dto->get_payment_method_display()
            )
        );
    }

    /**
     * 檢查是否為 Shopline Payment 訂單
     *
     * @param \WC_Order $order 訂單物件
     * @return bool
     */
    private function is_shopline_order( \WC_Order $order ): bool {
        $payment_method = $order->get_payment_method();

        return in_array( $payment_method, [
            'ys_shopline_redirect',
            'ys_shopline_token',
        ], true );
    }

    /**
     * 取得 API 客戶端
     *
     * @param \WC_Order $order 訂單物件
     * @return YSShoplineClient
     */
    private function get_api_client( \WC_Order $order ): YSShoplineClient {
        $gateway_id = $order->get_payment_method();
        $gateways   = WC()->payment_gateways()->get_available_payment_gateways();

        if ( ! isset( $gateways[ $gateway_id ] ) ) {
            throw new \RuntimeException( __( '找不到付款閘道', 'ys-shopline-payment' ) );
        }

        $gateway = $gateways[ $gateway_id ];

        return new YSShoplineClient(
            $gateway->get_option( 'merchant_id', '' ),
            $gateway->get_option( 'api_key', '' ),
            'yes' === $gateway->get_option( 'testmode', 'no' )
        );
    }
}
