<?php
/**
 * Status Manager
 *
 * @package YangSheep\ShoplinePayment\Handlers
 */

declare(strict_types=1);

namespace YangSheep\ShoplinePayment\Handlers;

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
        'ys_shopline_credit_installment',
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
     * 取消場景的交易安全終態（無收款風險）。
     * REFUNDED = 款項已退回消費者，同屬安全終態。
     *
     * @var string[]
     */
    private const TRADE_TERMINAL_SAFE = [ 'EXPIRED', 'FAILED', 'CANCELLED', 'CANCELED', 'REFUNDED' ];

    /**
     * 已收款狀態（含部分退款＝仍有款項在店家側）——訂單已取消時屬最高風險，需人工退款。
     *
     * @var string[]
     */
    private const TRADE_PAID_RISK = [ 'SUCCEEDED', 'SUCCESS', 'CAPTURED', 'PARTIALLY_REFUND', 'PARTIALLY_REFUNDED' ];

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

        // v3.5.34: 取消結果追蹤（取消 API 回 200 可能仍 PROCESSING；訂單此時已 cancelled，
        // 不在 pending/on-hold 的 cron 查詢範圍 → 需獨立的單次排程確認最終結果）
        add_action( 'ys_shopline_cancel_followup', [ $this, 'verify_cancel_result' ], 10, 2 );

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
        try {
            $this->do_sync_payment_status( $order );
        } catch ( \Throwable $e ) {
            YSLogger::error( 'SHOPLINE status sync uncaught error: ' . $e->getMessage(), [
                'order_id' => $order->get_id(),
                'trace'    => $e->getTraceAsString(),
            ] );
            $order->add_order_note(
                sprintf(
                    /* translators: %s: error message */
                    __( 'SHOPLINE status sync error: %s', 'ys-shopline-via-woocommerce' ),
                    $e->getMessage()
                )
            );
        }
    }

    /**
     * 實際執行狀態同步邏輯。
     *
     * @param \WC_Order $order Order object.
     */
    private function do_sync_payment_status( \WC_Order $order ): void {
        $trade_order_id = (string) $order->get_meta( YSOrderMeta::TRADE_ORDER_ID );
        $session_id     = (string) $order->get_meta( YSOrderMeta::SESSION_ID );

        if ( '' === $trade_order_id && '' === $session_id ) {
            $order->add_order_note( __( 'Cannot sync SHOPLINE status: missing trade/session ID.', 'ys-shopline-via-woocommerce' ) );
            return;
        }

        $api = \YSShoplinePayment::get_api();

        if ( null === $api ) {
            YSLogger::error( 'SHOPLINE status sync failed: API credentials not configured', [
                'order_id' => $order->get_id(),
            ] );
            $order->add_order_note( __( 'SHOPLINE status sync failed: API credentials are not configured.', 'ys-shopline-via-woocommerce' ) );
            return;
        }

        if ( '' !== $trade_order_id ) {
            $payment_dto = $api->query_payment( $trade_order_id );

            if ( is_wp_error( $payment_dto ) ) {
                YSLogger::error( 'SHOPLINE status sync failed: ' . $payment_dto->get_error_message(), [
                    'order_id' => $order->get_id(),
                ] );
                $order->add_order_note(
                    sprintf(
                        /* translators: %s: error message */
                        __( 'SHOPLINE status sync failed: %s', 'ys-shopline-via-woocommerce' ),
                        $payment_dto->get_error_message()
                    )
                );
                return;
            }

            $this->update_order_from_payment( $order, $payment_dto );
        } else {
            $session_dto = $api->query_session( $session_id );

            if ( is_wp_error( $session_dto ) ) {
                YSLogger::error( 'SHOPLINE status sync failed: ' . $session_dto->get_error_message(), [
                    'order_id' => $order->get_id(),
                ] );
                $order->add_order_note(
                    sprintf(
                        /* translators: %s: error message */
                        __( 'SHOPLINE status sync failed: %s', 'ys-shopline-via-woocommerce' ),
                        $session_dto->get_error_message()
                    )
                );
                return;
            }

            if ( ! empty( $session_dto->trade_order_id ) ) {
                $order->update_meta_data( YSOrderMeta::TRADE_ORDER_ID, $session_dto->trade_order_id );

                $payment_dto = $api->query_payment( $session_dto->trade_order_id );

                if ( is_wp_error( $payment_dto ) ) {
                    YSLogger::error( 'SHOPLINE status sync failed: ' . $payment_dto->get_error_message(), [
                        'order_id' => $order->get_id(),
                    ] );
                    $order->add_order_note(
                        sprintf(
                            /* translators: %s: error message */
                            __( 'SHOPLINE status sync failed: %s', 'ys-shopline-via-woocommerce' ),
                            $payment_dto->get_error_message()
                        )
                    );
                    return;
                }

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
        $trade_order_id     = (string) $order->get_meta( YSOrderMeta::TRADE_ORDER_ID );
        $reference_order_id = (string) $order->get_meta( YSOrderMeta::REFERENCE_ORDER_ID );

        if ( '' === $trade_order_id ) {
            return;
        }

        // v3.5.34: 交易已達安全終態（過期/失敗/已取消/已退款）→ 無需也無法取消，
        // 跳過 API 呼叫（對終態交易呼叫 cancel 必然回 1008 Status error，純噪音）
        $payment_status = strtoupper( (string) $order->get_meta( YSOrderMeta::PAYMENT_STATUS ) );
        if ( in_array( $payment_status, self::TRADE_TERMINAL_SAFE, true ) ) {
            $order->add_order_note( sprintf(
                /* translators: %s: payment status */
                __( 'Shopline 金流：交易已達終態（%s），無需向 SHOPLINE 取消。', 'ys-shopline-via-woocommerce' ),
                $payment_status
            ) );
            return;
        }

        $api = \YSShoplinePayment::get_api();

        if ( null === $api ) {
            YSLogger::error( 'Failed to cancel SHOPLINE payment: API credentials not configured', [
                'order_id'       => $order->get_id(),
                'trade_order_id' => $trade_order_id,
            ] );
            return;
        }

        $response = $api->cancel_payment_by_ids(
            $trade_order_id,
            '' !== $reference_order_id ? $reference_order_id : (string) $order->get_id()
        );

        if ( is_wp_error( $response ) ) {
            // v3.5.34: 取消失敗（1008 只是通用 Status error，另有 6001 處理中/6002 已請款/6401 通路錯誤等）
            // 一律不可假設遠端狀態 → 立即查詢交易實際狀態，依實況分級記錄。
            $this->handle_cancel_failure( $order, $trade_order_id, $response, $api );
            return;
        }

        // 官方文件：取消 API 的 HTTP 200 可能仍為 PROCESSING（取消處理中），非即為已取消
        $status = '';
        if ( isset( $response['status'] ) && is_string( $response['status'] ) ) {
            $status = $this->normalize_status( $response['status'] );
        }

        if ( '' !== $status ) {
            $order->update_meta_data( YSOrderMeta::PAYMENT_STATUS, $status );
        }

        if ( in_array( $status, self::TRADE_TERMINAL_SAFE, true ) ) {
            // 即時回應已達安全終態（已取消/已過期/已退款等）→ 直接收斂，不需 followup
            $order->add_order_note( sprintf(
                /* translators: %s: final trade status */
                __( 'Shopline 金流：交易取消完成（狀態 %s）。', 'ys-shopline-via-woocommerce' ),
                $status
            ) );
        } elseif ( in_array( $status, self::TRADE_PAID_RISK, true ) ) {
            // 即時回應顯示已收款 → 立即示警，不延後等 followup 分類
            YSLogger::error( 'Cancel API response indicates trade already paid', [
                'order_id'       => $order->get_id(),
                'trade_order_id' => $trade_order_id,
                'status'         => $status,
            ] );
            $order->add_order_note( sprintf(
                /* translators: %s: trade status */
                __( 'Shopline 金流：🔴 訂單已取消，但 SHOPLINE 回應交易為已收款（%s）——請立即至 SHOPLINE 後台處理退款。', 'ys-shopline-via-woocommerce' ),
                $status
            ) );
        } else {
            // 官方文件：取消 API 200 可能回 PROCESSING。訂單此時已 cancelled，
            // 不會再被 pending/on-hold cron 掃到，webhook 也可能略過已取消訂單
            // → 排程單次 followup 主動確認最終結果
            $order->add_order_note( sprintf(
                /* translators: %s: payment status returned by cancel API */
                __( 'Shopline 金流：取消請求已受理（目前狀態 %s），已排程自動確認最終結果。', 'ys-shopline-via-woocommerce' ),
                '' !== $status ? $status : 'UNKNOWN'
            ) );
            $this->schedule_cancel_followup( $order->get_id(), 1 );
        }
        $order->save();
    }

    /**
     * 排程取消結果確認（單次事件，不佔常駐 cron）。
     *
     * @param int $order_id 訂單 ID。
     * @param int $attempt  第幾次確認（首次 2 分鐘後，之後每 5 分鐘）。
     */
    private function schedule_cancel_followup( int $order_id, int $attempt ): void {
        $delay     = ( 1 === $attempt ) ? 120 : 300;
        $scheduled = wp_schedule_single_event( time() + $delay, 'ys_shopline_cancel_followup', [ $order_id, $attempt ] );
        if ( true !== $scheduled ) {
            // 排程失敗（cron 停用/重複事件等）不可靜默——留 ERROR 讓人工接手
            YSLogger::error( 'Failed to schedule cancel followup', [
                'order_id' => $order_id,
                'attempt'  => $attempt,
            ] );
        }
    }

    /**
     * 確認取消的最終結果（ys_shopline_cancel_followup 排程回呼）。
     *
     * 遠端仍非終態時最多重試 5 次（約 22 分鐘），之後留 ERROR 與人工核對備註，
     * 確保「取消回 PROCESSING」不會讓付款狀態永久停住。
     *
     * @param int $order_id 訂單 ID。
     * @param int $attempt  目前第幾次確認。
     */
    public function verify_cancel_result( int $order_id, int $attempt = 1 ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        $trade_order_id = (string) $order->get_meta( YSOrderMeta::TRADE_ORDER_ID );
        if ( '' === $trade_order_id ) {
            return;
        }

        // 期間可能已由 webhook 補上終態 → 不再查詢
        $current = strtoupper( (string) $order->get_meta( YSOrderMeta::PAYMENT_STATUS ) );
        if ( in_array( $current, self::TRADE_TERMINAL_SAFE, true ) ) {
            return;
        }

        $api = \YSShoplinePayment::get_api();
        if ( null === $api ) {
            // API 暫時不可用不可斷鏈——重排下一次確認
            if ( $attempt < 5 ) {
                $this->schedule_cancel_followup( $order_id, $attempt + 1 );
            } else {
                YSLogger::error( 'Cancel followup aborted: API unavailable after max attempts', [
                    'order_id' => $order_id,
                    'attempt'  => $attempt,
                ] );
            }
            return;
        }

        $context = [
            'order_id'       => $order_id,
            'trade_order_id' => $trade_order_id,
            'attempt'        => $attempt,
        ];

        $remote        = $api->get_payment_trade( $trade_order_id );
        $remote_status = '';
        if ( ! is_wp_error( $remote ) && isset( $remote['status'] ) && is_string( $remote['status'] ) ) {
            $remote_status = $this->normalize_status( $remote['status'] );
        }

        if ( in_array( $remote_status, self::TRADE_TERMINAL_SAFE, true ) ) {
            $order->update_meta_data( YSOrderMeta::PAYMENT_STATUS, $remote_status );
            $order->add_order_note( sprintf(
                /* translators: %s: final trade status */
                __( 'Shopline 金流：取消結果已確認，交易最終狀態 %s。', 'ys-shopline-via-woocommerce' ),
                $remote_status
            ) );
            $order->save();
            YSLogger::info( 'Cancel followup confirmed terminal status', $context + [ 'remote_status' => $remote_status ] );
            return;
        }

        if ( in_array( $remote_status, self::TRADE_PAID_RISK, true ) ) {
            $order->update_meta_data( YSOrderMeta::PAYMENT_STATUS, $remote_status );
            $order->add_order_note( sprintf(
                /* translators: %s: remote status */
                __( 'Shopline 金流：🔴 訂單已取消，但 SHOPLINE 交易確認為已收款（%s）——請立即至 SHOPLINE 後台處理退款。', 'ys-shopline-via-woocommerce' ),
                $remote_status
            ) );
            $order->save();
            YSLogger::error( 'Cancel followup found trade already paid', $context + [ 'remote_status' => $remote_status ] );
            return;
        }

        // 仍在處理中或查詢失敗 → 重試或收斂為人工核對
        if ( $attempt < 5 ) {
            YSLogger::debug( 'Cancel followup still non-terminal, rescheduling', $context + [ 'remote_status' => $remote_status ] );
            $this->schedule_cancel_followup( $order_id, $attempt + 1 );
            return;
        }

        YSLogger::error( 'Cancel result unconfirmed after max followup attempts', $context + [ 'remote_status' => $remote_status ] );
        $order->add_order_note( sprintf(
            /* translators: 1: attempts, 2: last known status */
            __( 'Shopline 金流：⚠️ 取消結果經 %1$d 次自動確認仍未達終態（最後狀態 %2$s），請至 SHOPLINE 後台核對此筆交易。', 'ys-shopline-via-woocommerce' ),
            $attempt,
            '' !== $remote_status ? $remote_status : 'UNKNOWN'
        ) );
        $order->save();
    }

    /**
     * 取消 API 失敗後查詢遠端交易實況，依實際狀態分級記錄。
     *
     * 錯誤碼本身（1008/6001/6002/6401/網路錯誤）不足以判斷可否安全忽略——
     * 唯一可信的是查詢回來的交易狀態：
     * - 終態（EXPIRED/FAILED/CANCELLED）→ 無收款風險，warning
     * - 已收款（SUCCEEDED/CAPTURED）→ 訂單已取消但錢已收，ERROR + 退款提示
     * - 進行中/其他 → ERROR + 後台核對提示
     * - 查詢也失敗 → ERROR + 無法確認風險提示
     *
     * @param \WC_Order $order          訂單。
     * @param string    $trade_order_id 交易 ID。
     * @param \WP_Error $cancel_error   取消 API 的錯誤。
     * @param object    $api            API client。
     */
    private function handle_cancel_failure( \WC_Order $order, string $trade_order_id, \WP_Error $cancel_error, $api ): void {
        $error_code = (string) $cancel_error->get_error_code();
        $error_msg  = $cancel_error->get_error_message();
        $context    = [
            'order_id'       => $order->get_id(),
            'trade_order_id' => $trade_order_id,
            'error_code'     => $error_code,
        ];

        $remote        = $api->get_payment_trade( $trade_order_id );
        $remote_status = '';
        if ( ! is_wp_error( $remote ) && isset( $remote['status'] ) && is_string( $remote['status'] ) ) {
            $remote_status = $this->normalize_status( $remote['status'] );
        }

        if ( '' === $remote_status ) {
            YSLogger::error( 'Failed to cancel SHOPLINE payment and remote status unverifiable: ' . $error_msg, $context );
            $order->add_order_note( sprintf(
                /* translators: %s: error message */
                __( 'Shopline 金流：⚠️ 取消請求失敗（%s）且暫時無法查得遠端交易狀態，系統將自動重試確認；請留意後續備註。', 'ys-shopline-via-woocommerce' ),
                $error_msg
            ) );
            $order->save();
            // 查詢失敗不可斷鏈——交給 followup 繼續確認（重試/收斂邏輯在 verify_cancel_result）
            $this->schedule_cancel_followup( $order->get_id(), 1 );
            return;
        }

        $order->update_meta_data( YSOrderMeta::PAYMENT_STATUS, $remote_status );
        $context['remote_status'] = $remote_status;

        if ( in_array( $remote_status, self::TRADE_TERMINAL_SAFE, true ) ) {
            // 遠端已是終態 → 取消被拒是預期（無可取消對象），無收款風險
            YSLogger::warning( 'SHOPLINE cancel not accepted; remote trade already terminal: ' . $error_msg, $context );
            $order->add_order_note( sprintf(
                /* translators: 1: cancel error, 2: remote status */
                __( 'Shopline 金流：取消未被受理（%1$s），已查證遠端交易為終態 %2$s，無收款風險。', 'ys-shopline-via-woocommerce' ),
                $error_msg,
                $remote_status
            ) );
        } elseif ( in_array( $remote_status, self::TRADE_PAID_RISK, true ) ) {
            // 訂單被取消但遠端已成功收款 → 最高風險
            YSLogger::error( 'Order cancelled but SHOPLINE trade already paid: ' . $error_msg, $context );
            $order->add_order_note( sprintf(
                /* translators: %s: remote status */
                __( 'Shopline 金流：🔴 訂單已取消，但 SHOPLINE 交易已成功收款（%s）——請立即至 SHOPLINE 後台處理退款。', 'ys-shopline-via-woocommerce' ),
                $remote_status
            ) );
        } else {
            // CREATED / CUSTOMER_ACTION / PROCESSING / AUTHORIZED 等進行中狀態
            YSLogger::error( 'Failed to cancel SHOPLINE payment; remote trade still active: ' . $error_msg, $context );
            $order->add_order_note( sprintf(
                /* translators: 1: cancel error, 2: remote status */
                __( 'Shopline 金流：⚠️ 取消失敗（%1$s），遠端交易仍為 %2$s，請至 SHOPLINE 後台確認並手動處理，避免後續請款；系統將持續自動確認。', 'ys-shopline-via-woocommerce' ),
                $error_msg,
                $remote_status
            ) );
            $this->schedule_cancel_followup( $order->get_id(), 1 );
        }
        $order->save();
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
        $order->update_meta_data( YSOrderMeta::PAYMENT_DETAIL, YSOrderMeta::sanitize_payment_detail( $payment_dto->to_array() ) );

        $wc_status = self::STATUS_MAP[ $status ] ?? null;

        if ( null !== $wc_status && $order->get_status() !== $wc_status ) {
            if ( 'processing' === $wc_status && ! $order->is_paid() ) {
                $order->payment_complete( $payment_dto->trade_order_id );
                // 套用商家自訂的付款成功訂單狀態
                $custom_paid_status = get_option( 'ys_shopline_order_status_paid', '' );
                if ( $custom_paid_status && $custom_paid_status !== $order->get_status() ) {
                    $order->update_status( $custom_paid_status, __( '依商家設定更新訂單狀態。', 'ys-shopline-via-woocommerce' ) );
                }
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
