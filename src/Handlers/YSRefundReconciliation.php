<?php
/**
 * Asynchronous SHOPLINE refund reconciliation.
 *
 * @package YangSheep\ShoplinePayment\Handlers
 */

declare(strict_types=1);

namespace YangSheep\ShoplinePayment\Handlers;

use YangSheep\ShoplinePayment\Utils\YSLogger;
use YangSheep\ShoplinePayment\Utils\YSOrderMeta;
use YangSheep\ShoplinePayment\Utils\YSRefundStatus;
use YangSheep\ShoplinePayment\Utils\YSTradeStatus;

defined( 'ABSPATH' ) || exit;

final class YSRefundReconciliation {

    public const SCHEDULE_HOOK = 'ys_shopline_reconcile_refund';

    private const SCHEDULE_GROUP = 'ys-shopline-refund-reconciliation';

    /** @var int[] Cumulative delays from the attempt start. */
    /**
     * 退款查詢節奏（累計秒）：30s／2m／10m／1h／6h。
     *
     * 首點 30s 讓多數退款在第一次排程即收斂（沙盒實測中租約 5 秒完成），且不佔用 admin request；
     * 末點 6h 與付款端卡類觀察窗一致——SHOPLINE 官方載明退款狀態更新時間依通路而異，
     * 給足收斂空間後仍不明才進 C9 人工審核，避免過早把「還在處理」誤判成需要人工。
     */
    private const SCHEDULE_DELAYS = array( 30, 120, 600, 3600, 21600 );

    /** @var array<int, array<string, mixed>> */
    private static array $request_snapshots = array();

    /** @var array<int, object> Temporary Woo refunds captured before first save. */
    private static array $request_refunds = array();

    /** @var array<string, mixed>|null */
    private static ?array $local_creation_context = null;

    public static function init(): void {
        add_action( 'woocommerce_create_refund', array( self::class, 'capture_refund_request' ), 10, 2 );
        add_action( self::SCHEDULE_HOOK, array( self::class, 'reconcile' ), 10, 3 );
    }

    /**
     * Capture WooCommerce refund arguments before the temporary refund is saved.
     *
     * @param object $refund Temporary WC_Order_Refund object.
     * @param array  $args   wc_create_refund() arguments.
     */
    public static function capture_refund_request( $refund, array $args ): void {
        if ( empty( $args['refund_payment'] ) ) {
            $context = self::$local_creation_context;
            if ( is_array( $context )
                && absint( $args['order_id'] ?? 0 ) === (int) ( $context['order_id'] ?? 0 )
                && is_object( $refund )
                && method_exists( $refund, 'update_meta_data' ) ) {
                $refund->update_meta_data( YSOrderMeta::REFUND_REFERENCE, (string) $context['reference'] );
            }
            return;
        }

        $order_id = absint( $args['order_id'] ?? 0 );
        if ( $order_id <= 0 ) {
            return;
        }

        $snapshot = self::normalize_snapshot( $args );
        $order    = wc_get_order( $order_id );
        if ( $order instanceof \WC_Order && method_exists( $order, 'get_remaining_refund_amount' ) ) {
            // This hook runs before Woo saves the temporary refund, so this is
            // the authoritative pre-request refundable balance.
            $snapshot['refundable_before_request'] = (float) $order->get_remaining_refund_amount();
        }

        self::$request_snapshots[ $order_id ] = $snapshot;
        if ( is_object( $refund ) && method_exists( $refund, 'update_meta_data' ) ) {
            self::$request_refunds[ $order_id ] = $refund;
        }
    }

    /**
     * Process one merchant-initiated refund while Woo's temporary refund exists.
     *
     * @param \WC_Order $order           Parent order.
     * @param float     $amount          Refund amount in major units.
     * @param string    $reason          Merchant reason.
     * @param object    $api             SHOPLINE API facade.
     * @param string    $gateway_id      WooCommerce gateway ID.
     * @param string    $shopline_method SHOPLINE payment method.
     * @return bool|\WP_Error
     */
    public static function process( \WC_Order $order, float $amount, string $reason, $api, string $gateway_id, string $shopline_method ) {
        return self::with_lock(
            $order->get_id(),
            static function () use ( $order, $amount, $reason, $api, $gateway_id, $shopline_method ) {
                return self::process_locked( $order, $amount, $reason, $api, $gateway_id, $shopline_method );
            }
        );
    }

    public static function reconcile( int $order_id, string $reference, int $stage ): void {
        $result = self::with_lock(
            $order_id,
            static function () use ( $order_id, $reference, $stage ): void {
                $order = wc_get_order( $order_id );
                if ( ! $order instanceof \WC_Order ) {
                    return;
                }

                $attempt = $order->get_meta( YSOrderMeta::REFUND_CONFIRMATION_DATA );
                if ( ! is_array( $attempt )
                    || $reference !== (string) ( $attempt['refund_reference'] ?? '' )
                    || $stage !== (int) ( $attempt['stage'] ?? -1 ) ) {
                    return;
                }

                if ( YSRefundStatus::is_succeeded( (string) ( $attempt['last_status'] ?? '' ) ) ) {
                    self::create_local_refund( $order, $attempt, 'scheduled_retry' );
                    return;
                }

                $api = \YSShoplinePayment::get_api();
                if ( ! $api ) {
                    self::advance_or_review( $order, $attempt, 'api_unavailable' );
                    return;
                }

                $refund_order_id = (string) ( $attempt['refund_order_id'] ?? '' );
                $response = '' !== $refund_order_id
                    ? $api->query_refund( $refund_order_id )
                    : $api->create_refund( self::request_from_attempt( $attempt ), (string) $attempt['idempotent_key'] );

                if ( is_wp_error( $response ) ) {
                    if ( '' === $refund_order_id && ! self::is_transport_unknown( $response ) ) {
                        self::reject_create_error( $order, $attempt, $response );
                        return;
                    }
                    $attempt['last_status'] = 'UNKNOWN';
                    self::advance_or_review( $order, $attempt, 'query_unknown' );
                    return;
                }

                if ( ! is_array( $response ) ) {
                    $attempt['last_status'] = 'UNKNOWN';
                    self::advance_or_review( $order, $attempt, 'query_unknown' );
                    return;
                }

                if ( ! self::response_matches_attempt( $attempt, $response ) ) {
                    self::mark_remote_envelope_mismatch( $order, $attempt, $response, 'scheduled_query' );
                    return;
                }

                $attempt = self::merge_response( $attempt, $response );
                $family  = YSRefundStatus::family( (string) $attempt['last_status'] );

                if ( YSRefundStatus::FAMILY_SUCCEEDED === $family ) {
                    self::persist_attempt( $order, $attempt );
                    self::create_local_refund( $order, $attempt, 'scheduled_query' );
                    return;
                }
                if ( YSRefundStatus::FAMILY_FAILED === $family ) {
                    self::complete_failed_attempt( $order, $attempt, $response, 'scheduled_query' );
                    return;
                }

                self::advance_or_review( $order, $attempt, 'still_in_flight' );
            }
        );

        if ( is_wp_error( $result ) && 'ys_shopline_refund_locked' === $result->get_error_code() ) {
            $order = wc_get_order( $order_id );
            if ( ! $order instanceof \WC_Order ) {
                return;
            }

            $attempt = $order->get_meta( YSOrderMeta::REFUND_CONFIRMATION_DATA );
            if ( is_array( $attempt )
                && $reference === (string) ( $attempt['refund_reference'] ?? '' )
                && $stage === (int) ( $attempt['stage'] ?? -1 ) ) {
                self::schedule_next( $order, $attempt, true, 15 );
            }
        }
    }

    /**
     * Converge one signed refund webhook only when its complete envelope matches
     * the active order-scoped attempt.
     */
    public static function handle_webhook( \WC_Order $candidate, array $payload, string $event_type ): bool {
        $result = self::with_lock(
            $candidate->get_id(),
            static function () use ( $candidate, $payload, $event_type ): bool {
                $order = wc_get_order( $candidate->get_id() );
                if ( ! $order instanceof \WC_Order ) {
                    return false;
                }

                $attempt = $order->get_meta( YSOrderMeta::REFUND_CONFIRMATION_DATA );
                if ( ! is_array( $attempt ) || ! self::webhook_matches_attempt( $attempt, $payload ) ) {
                    YSLogger::warning(
                        'Refund webhook rejected: envelope does not match active attempt',
                        array(
                            'order_id'           => $order->get_id(),
                            'refund_order_id'    => (string) ( $payload['refundOrderId'] ?? '' ),
                            'reference_order_id' => (string) ( $payload['referenceOrderId'] ?? '' ),
                        )
                    );
                    return false;
                }

                $status = strtoupper( (string) ( $payload['status'] ?? '' ) );
                $is_success_event = in_array( $event_type, array( 'trade.refund.succeeded', 'refund.succeeded', 'refund.success' ), true );
                $is_failed_event  = in_array( $event_type, array( 'trade.refund.failed', 'refund.failed' ), true );

                if ( $is_success_event && ! YSRefundStatus::is_succeeded( $status ) ) {
                    return false;
                }
                if ( $is_failed_event && ! YSRefundStatus::is_failed( $status ) ) {
                    return false;
                }
                if ( ! $is_success_event && ! $is_failed_event ) {
                    return false;
                }

                $attempt = self::merge_response( $attempt, $payload );
                if ( $is_success_event ) {
                    self::persist_attempt( $order, $attempt );
                    self::create_local_refund( $order, $attempt, 'webhook' );
                    return true;
                }

                self::complete_failed_attempt( $order, $attempt, $payload, 'webhook' );
                return true;
            }
        );

        return true === $result;
    }

    /** @return bool|\WP_Error */
    private static function process_locked( \WC_Order $order, float $amount, string $reason, $api, string $gateway_id, string $shopline_method ) {
        $order_id = $order->get_id();
        $snapshot = self::$request_snapshots[ $order_id ] ?? null;
        $temporary_refund = self::$request_refunds[ $order_id ] ?? null;
        unset( self::$request_snapshots[ $order_id ] );
        unset( self::$request_refunds[ $order_id ] );

        if ( ! is_array( $snapshot ) ) {
            return new \WP_Error(
                'ys_shopline_refund_snapshot_missing',
                __( '無法取得完整退款資料，SHOPLINE 尚未送出退款。請重新整理後再試。', 'ys-shopline-via-woocommerce' )
            );
        }

        $currency = strtoupper( (string) $order->get_currency() );
        $minor     = \YSShoplinePayment::get_formatted_amount( $amount, $currency );

        $snapshot_minor = \YSShoplinePayment::get_formatted_amount( (float) ( $snapshot['amount'] ?? 0 ), $currency );
        if ( (int) $snapshot_minor !== (int) $minor ) {
            return new \WP_Error(
                'ys_shopline_refund_snapshot_mismatch',
                __( '退款金額與 WooCommerce 暫存退款資料不一致，SHOPLINE 尚未送出退款。請重新整理後再試。', 'ys-shopline-via-woocommerce' )
            );
        }

        if ( 'ys_shopline_bnpl' === $gateway_id ) {
            $refundable_before_request = isset( $snapshot['refundable_before_request'] )
                ? (float) $snapshot['refundable_before_request']
                : $amount;
            $refundable_minor = \YSShoplinePayment::get_formatted_amount( $refundable_before_request, $currency );
            if ( (int) $minor < (int) $refundable_minor ) {
                return new \WP_Error(
                    'ys_shopline_chailease_partial_refund',
                    __( '中租 zingala 銀角零卡不支援部分退款，請改用全額退款。', 'ys-shopline-via-woocommerce' )
                );
            }
        }

        $existing = $order->get_meta( YSOrderMeta::REFUND_CONFIRMATION_DATA );
        if ( is_array( $existing ) && ! empty( $existing['refund_reference'] ) ) {
            if ( ! self::attempt_matches_request( $existing, (int) $minor, $currency, $gateway_id, $snapshot ) ) {
                self::schedule_next( $order, $existing );
                return new \WP_Error(
                    'ys_shopline_refund_in_progress',
                    __( '此訂單已有另一筆 SHOPLINE 退款正在確認中，請勿重複操作。', 'ys-shopline-via-woocommerce' )
                );
            }

            if ( ! self::tag_temporary_refund( $temporary_refund, (string) $existing['refund_reference'] ) ) {
                return new \WP_Error(
                    'ys_shopline_refund_tag_failed',
                    __( '無法標記 WooCommerce 暫存退款，SHOPLINE 尚未再次查詢。請重新整理後再試。', 'ys-shopline-via-woocommerce' )
                );
            }

            return self::resume_existing_attempt( $order, $existing, $api );
        }

        $trade_order_id = (string) $order->get_meta( YSOrderMeta::TRADE_ORDER_ID );
        if ( '' === $trade_order_id ) {
            return new \WP_Error( 'no_trade_id', __( 'Trade order ID not found.', 'ys-shopline-via-woocommerce' ) );
        }

        // 尚未結算的交易不得退款。SHOPLINE 官方界線是「於請款後方可進行退款」；
        // 對未收款交易發退款，遠端只回通用的 `1008 Status error`（實測 order#11740），
        // 商家看不出原因。此處在呼叫 API 前擋下並說明，避免把無效請求送出去。
        //
        // 判定採「本地已知的付款證據」而非樂觀放行：付款狀態屬已收款家族，
        // 或訂單已有 date_paid（WooCommerce 實收）。兩者皆無 → 視為尚未結算。
        $remote_paid = YSTradeStatus::is_paid( strtoupper( trim( (string) $order->get_meta( YSOrderMeta::PAYMENT_STATUS ) ) ) );
        $locally_paid = method_exists( $order, 'get_date_paid' ) && $order->get_date_paid();
        if ( ! $remote_paid && ! $locally_paid ) {
            return new \WP_Error(
                'ys_shopline_refund_not_settled',
                __( '此訂單的 SHOPLINE 付款尚未完成結算，暫時無法退款。請待付款確認完成後再操作；若付款最終失敗，訂單會自動回到等待付款，不需退款。', 'ys-shopline-via-woocommerce' )
            );
        }

        $counter   = absint( $order->get_meta( YSOrderMeta::REFUND_ATTEMPT ) ) + 1;
        $reference = sprintf( '%d_refund_%d', $order_id, $counter );
        if ( strlen( $reference ) > 32 ) {
            $reference = substr( 'r' . hash( 'sha256', $reference ), 0, 32 );
        }
        $idempotent_key = substr( 'refund_' . hash( 'sha256', $reference ), 0, 32 );

        $attempt = array(
            'refund_reference' => $reference,
            'idempotent_key'    => $idempotent_key,
            'refund_order_id'   => '',
            'trade_order_id'    => $trade_order_id,
            'amount'            => (int) $minor,
            'currency'          => $currency,
            'gateway'           => $gateway_id,
            'shopline_method'   => $shopline_method,
            'snapshot'          => $snapshot,
            'stage'             => 0,
            'started_at'        => time(),
            'last_status'       => 'CREATING',
        );

        $order->update_meta_data( YSOrderMeta::REFUND_ATTEMPT, $counter );
        $order->update_meta_data( YSOrderMeta::REFUND_CONFIRMATION_DATA, $attempt );
        $order->save();

        if ( ! self::tag_temporary_refund( $temporary_refund, $reference ) ) {
            $order->delete_meta_data( YSOrderMeta::REFUND_CONFIRMATION_DATA );
            $order->save();
            return new \WP_Error(
                'ys_shopline_refund_tag_failed',
                __( '無法標記 WooCommerce 暫存退款，SHOPLINE 尚未送出退款。請重新整理後再試。', 'ys-shopline-via-woocommerce' )
            );
        }

        $request = self::request_from_attempt( $attempt );
        $response = $api->create_refund( $request, $idempotent_key );

        if ( is_wp_error( $response ) ) {
            if ( ! self::is_transport_unknown( $response ) ) {
                return self::reject_create_error( $order, $attempt, $response );
            }
            $attempt['last_status'] = 'UNKNOWN';
            self::persist_attempt( $order, $attempt );
            self::schedule_next( $order, $attempt );
            self::add_pending_note_once( $order, $attempt );
            return self::pending_error();
        }

        if ( ! is_array( $response ) || ! self::response_matches_attempt( $attempt, $response ) ) {
            return self::mark_remote_envelope_mismatch( $order, $attempt, is_array( $response ) ? $response : array(), 'create' );
        }

        $attempt = self::merge_response( $attempt, $response );
        self::persist_attempt( $order, $attempt );

        $family = YSRefundStatus::family( (string) $attempt['last_status'] );
        if ( YSRefundStatus::FAMILY_SUCCEEDED === $family ) {
            return self::complete_current_request( $order, $attempt, $response );
        }
        if ( YSRefundStatus::FAMILY_FAILED === $family ) {
            return self::fail_current_request( $order, $attempt, $response );
        }

        if ( '' !== (string) $attempt['refund_order_id'] ) {
            // 單次立即查詢：沿用本來就要發出的一次 API 往返，**不得 sleep、不得阻塞 admin request**。
            //
            // 早期設計曾提「立即／2s／5s 短輪詢」，已否決：沙盒量到的 5 秒是單次量測值而非 SLA，
            // 用它決定同步等待長度等於拿不可靠的數字換體驗；且排程／webhook 收斂路徑無論如何都
            // 必須存在並自身正確，阻塞輪詢對正確性零貢獻，只會綁住 PHP-FPM worker 並多養一條
            // 會漂移的路徑。**收斂的正確性只由排程／webhook 保證。**
            $query = $api->query_refund( (string) $attempt['refund_order_id'] );

            if ( is_wp_error( $query ) ) {
                $attempt['last_status'] = 'UNKNOWN';
                self::persist_attempt( $order, $attempt );
            } elseif ( ! is_array( $query ) || ! self::response_matches_attempt( $attempt, $query ) ) {
                return self::mark_remote_envelope_mismatch( $order, $attempt, is_array( $query ) ? $query : array(), 'immediate_query' );
            } else {
                $attempt = self::merge_response( $attempt, $query );
                self::persist_attempt( $order, $attempt );
                $family = YSRefundStatus::family( (string) $attempt['last_status'] );
                if ( YSRefundStatus::FAMILY_SUCCEEDED === $family ) {
                    return self::complete_current_request( $order, $attempt, $query );
                }
                if ( YSRefundStatus::FAMILY_FAILED === $family ) {
                    return self::fail_current_request( $order, $attempt, $query );
                }
            }
        }

        self::schedule_next( $order, $attempt );
        self::add_pending_note_once( $order, $attempt );
        return self::pending_error();
    }

    /** @return array<string, mixed> */
    private static function normalize_snapshot( array $args ): array {
        $line_items = array();
        foreach ( is_array( $args['line_items'] ?? null ) ? $args['line_items'] : array() as $item_id => $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }
            $line_items[ $item_id ] = array(
                'qty'          => $item['qty'] ?? 0,
                'refund_total' => $item['refund_total'] ?? 0,
                'refund_tax'   => is_array( $item['refund_tax'] ?? null ) ? $item['refund_tax'] : array(),
            );
        }

        return array(
            'amount'        => (float) ( $args['amount'] ?? 0 ),
            'reason'        => (string) ( $args['reason'] ?? '' ),
            'line_items'    => $line_items,
            'restock_items' => ! empty( $args['restock_items'] ),
        );
    }

    private static function tag_temporary_refund( $refund, string $reference ): bool {
        if ( ! is_object( $refund ) || ! method_exists( $refund, 'update_meta_data' ) ) {
            return true;
        }

        $refund->update_meta_data( YSOrderMeta::REFUND_REFERENCE, $reference );
        if ( method_exists( $refund, 'save' ) && false === $refund->save() ) {
            return false;
        }
        return true;
    }

    /** @return array<string, mixed> */
    private static function request_from_attempt( array $attempt ): array {
        $request = array(
            'tradeOrderId'     => (string) $attempt['trade_order_id'],
            'referenceOrderId' => (string) $attempt['refund_reference'],
            'amount'           => array(
                'value'    => (int) $attempt['amount'],
                'currency' => (string) $attempt['currency'],
            ),
        );
        $reason = (string) ( $attempt['snapshot']['reason'] ?? '' );
        if ( '' !== $reason ) {
            $request['reason'] = $reason;
        }
        return $request;
    }

    private static function attempt_matches_request( array $attempt, int $amount, string $currency, string $gateway_id, array $snapshot ): bool {
        return $amount === (int) ( $attempt['amount'] ?? -1 )
            && strtoupper( $currency ) === strtoupper( (string) ( $attempt['currency'] ?? '' ) )
            && $gateway_id === (string) ( $attempt['gateway'] ?? '' )
            && $snapshot === ( $attempt['snapshot'] ?? null );
    }

    private static function webhook_matches_attempt( array $attempt, array $payload ): bool {
        $amount = isset( $payload['amount'] ) && is_array( $payload['amount'] ) ? $payload['amount'] : array();

        return '' !== (string) ( $attempt['refund_order_id'] ?? '' )
            && (string) $attempt['refund_order_id'] === (string) ( $payload['refundOrderId'] ?? '' )
            && (string) $attempt['refund_reference'] === (string) ( $payload['referenceOrderId'] ?? '' )
            && (string) $attempt['trade_order_id'] === (string) ( $payload['tradeOrderId'] ?? '' )
            && (int) $attempt['amount'] === (int) ( $amount['value'] ?? -1 )
            && strtoupper( (string) $attempt['currency'] ) === strtoupper( (string) ( $amount['currency'] ?? '' ) );
    }

    private static function response_matches_attempt( array $attempt, array $response ): bool {
        $body   = isset( $response['data'] ) && is_array( $response['data'] ) ? $response['data'] : $response;
        $amount = isset( $body['amount'] ) && is_array( $body['amount'] ) ? $body['amount'] : array();
        $refund_order_id = (string) ( $body['refundOrderId'] ?? '' );
        $expected_refund_order_id = (string) ( $attempt['refund_order_id'] ?? '' );

        return '' !== $refund_order_id
            && ( '' === $expected_refund_order_id || $expected_refund_order_id === $refund_order_id )
            && (string) $attempt['refund_reference'] === (string) ( $body['referenceOrderId'] ?? '' )
            && (string) $attempt['trade_order_id'] === (string) ( $body['tradeOrderId'] ?? '' )
            && (int) $attempt['amount'] === (int) ( $amount['value'] ?? -1 )
            && strtoupper( (string) $attempt['currency'] ) === strtoupper( (string) ( $amount['currency'] ?? '' ) );
    }

    private static function mark_remote_envelope_mismatch( \WC_Order $order, array $attempt, array $response, string $source ): \WP_Error {
        $body = isset( $response['data'] ) && is_array( $response['data'] ) ? $response['data'] : $response;
        $attempt['last_status'] = 'UNKNOWN';
        $attempt['local_state'] = 'manual_review';
        $attempt['observed_refund_order_id'] = (string) ( $body['refundOrderId'] ?? '' );
        $order->update_meta_data( YSOrderMeta::REFUND_CONFIRMATION_DATA, $attempt );
        $order->update_meta_data(
            YSOrderMeta::REFUND_REVIEW,
            array(
                'type'                        => 'remote_envelope_mismatch',
                'reference'                   => (string) $attempt['refund_reference'],
                'expected_refund_order_id'    => (string) ( $attempt['refund_order_id'] ?? '' ),
                'observed_refund_order_id'    => (string) ( $body['refundOrderId'] ?? '' ),
                'observed_reference_order_id' => (string) ( $body['referenceOrderId'] ?? '' ),
                'source'                      => $source,
                'ts'                          => time(),
            )
        );
        $order->add_order_note(
            __( '🔴 SHOPLINE 退款回應與本次訂單退款資料不符（退款編號／參考編號／交易／金額／幣別至少一項不一致）。WooCommerce 未建立退款，請至 SHOPLINE 後台人工核對。', 'ys-shopline-via-woocommerce' )
        );
        $order->save();

        return new \WP_Error(
            'ys_shopline_refund_response_mismatch',
            __( 'SHOPLINE 退款回應資料不一致，系統已停止自動處理。請查看訂單備註並人工核對。', 'ys-shopline-via-woocommerce' )
        );
    }

    /** @return bool|\WP_Error */
    private static function resume_existing_attempt( \WC_Order $order, array $attempt, $api ) {
        $refund_order_id = (string) ( $attempt['refund_order_id'] ?? '' );

        if ( '' === $refund_order_id ) {
            $response = $api->create_refund(
                self::request_from_attempt( $attempt ),
                (string) $attempt['idempotent_key']
            );
        } else {
            $response = $api->query_refund( $refund_order_id );
        }

        if ( is_wp_error( $response ) ) {
            if ( '' === $refund_order_id && ! self::is_transport_unknown( $response ) ) {
                return self::reject_create_error( $order, $attempt, $response );
            }
            $attempt['last_status'] = 'UNKNOWN';
            self::persist_attempt( $order, $attempt );
            self::schedule_next( $order, $attempt );
            return self::pending_error();
        }
        if ( ! is_array( $response ) ) {
            $attempt['last_status'] = 'UNKNOWN';
            self::persist_attempt( $order, $attempt );
            self::schedule_next( $order, $attempt );
            return self::pending_error();
        }

        if ( ! self::response_matches_attempt( $attempt, $response ) ) {
            return self::mark_remote_envelope_mismatch( $order, $attempt, $response, 'merchant_retry' );
        }

        $attempt = self::merge_response( $attempt, $response );
        self::persist_attempt( $order, $attempt );
        $family = YSRefundStatus::family( (string) $attempt['last_status'] );

        if ( YSRefundStatus::FAMILY_SUCCEEDED === $family ) {
            return self::complete_current_request( $order, $attempt, $response );
        }
        if ( YSRefundStatus::FAMILY_FAILED === $family ) {
            return self::fail_current_request( $order, $attempt, $response );
        }

        self::schedule_next( $order, $attempt );
        self::add_pending_note_once( $order, $attempt );
        return self::pending_error();
    }

    /** @return array<string, mixed> */
    private static function merge_response( array $attempt, array $response ): array {
        $body = isset( $response['data'] ) && is_array( $response['data'] ) ? $response['data'] : $response;
        if ( ! empty( $body['refundOrderId'] ) ) {
            $attempt['refund_order_id'] = (string) $body['refundOrderId'];
        }
        $attempt['last_status'] = strtoupper( (string) ( $body['status'] ?? '' ) );
        return $attempt;
    }

    private static function persist_attempt( \WC_Order $order, array $attempt ): void {
        $order->update_meta_data( YSOrderMeta::REFUND_CONFIRMATION_DATA, $attempt );
        $order->save();
    }

    /** @return true */
    private static function complete_current_request( \WC_Order $order, array $attempt, array $response ): bool {
        $order->update_meta_data( YSOrderMeta::REFUND_DETAIL, $response );
        self::append_history( $order, $attempt, 'remote_refund_succeeded', 'current_request', 0 );
        $order->delete_meta_data( YSOrderMeta::REFUND_CONFIRMATION_DATA );
        $order->delete_meta_data( YSOrderMeta::REFUND_REVIEW );
        $order->add_order_note(
            sprintf(
                __( 'SHOPLINE 退款已確認完成。退款編號：%s', 'ys-shopline-via-woocommerce' ),
                (string) $attempt['refund_order_id']
            )
        );
        $order->save();
        return true;
    }

    /** @return \WP_Error */
    private static function fail_current_request( \WC_Order $order, array $attempt, array $response ): \WP_Error {
        $order->update_meta_data( YSOrderMeta::REFUND_DETAIL, $response );
        self::append_history( $order, $attempt, 'remote_refund_failed', 'current_request', 0 );
        $order->delete_meta_data( YSOrderMeta::REFUND_CONFIRMATION_DATA );
        $order->delete_meta_data( YSOrderMeta::REFUND_REVIEW );
        $order->add_order_note( __( 'SHOPLINE 退款未成功，WooCommerce 未建立退款。', 'ys-shopline-via-woocommerce' ) );
        $order->save();
        return new \WP_Error( 'ys_shopline_refund_failed', __( 'SHOPLINE 退款未成功，請檢查訂單備註後再試。', 'ys-shopline-via-woocommerce' ) );
    }

    private static function add_pending_note_once( \WC_Order $order, array $attempt ): void {
        if ( ! empty( $attempt['pending_note_added'] ) ) {
            return;
        }
        $attempt['pending_note_added'] = true;
        $order->update_meta_data( YSOrderMeta::REFUND_CONFIRMATION_DATA, $attempt );
        $order->add_order_note(
            sprintf(
                __( 'SHOPLINE 退款已送出、正在確認中。退款參考編號：%s。確認成功後才會建立 WooCommerce 退款，請勿重複操作。', 'ys-shopline-via-woocommerce' ),
                (string) $attempt['refund_reference']
            )
        );
        $order->save();
    }

    private static function pending_error(): \WP_Error {
        return new \WP_Error(
            'ys_shopline_refund_pending',
            __( '退款已送出、正在等待 SHOPLINE 確認。確認成功後系統會自動建立退款紀錄，請勿重複操作。', 'ys-shopline-via-woocommerce' )
        );
    }

    private static function schedule_next( \WC_Order $order, array $attempt, bool $force = false, int $minimum_delay = 5 ): bool {
        $stage = (int) ( $attempt['stage'] ?? 0 );
        $delay = self::SCHEDULE_DELAYS[ $stage ] ?? null;
        if ( null === $delay ) {
            return false;
        }

        $args = array( $order->get_id(), (string) $attempt['refund_reference'], $stage );
        if ( function_exists( 'as_schedule_single_action' ) ) {
            if ( ! $force
                && function_exists( 'as_has_scheduled_action' )
                && as_has_scheduled_action( self::SCHEDULE_HOOK, $args, self::SCHEDULE_GROUP ) ) {
                return true;
            }
            $timestamp = max( time() + max( 5, $minimum_delay ), (int) $attempt['started_at'] + $delay );
            try {
                $action_id = as_schedule_single_action( $timestamp, self::SCHEDULE_HOOK, $args, self::SCHEDULE_GROUP, ! $force );
            } catch ( \Throwable $error ) {
                self::mark_schedule_failure( $order, $attempt, $error->getMessage() );
                return false;
            }
            if ( (int) $action_id <= 0 ) {
                self::mark_schedule_failure( $order, $attempt, 'Action Scheduler did not return an action ID.' );
                return false;
            }
            return true;
        }

        $timestamp = max( time() + max( 5, $minimum_delay ), (int) $attempt['started_at'] + $delay );
        if ( ! $force && wp_next_scheduled( self::SCHEDULE_HOOK, $args ) ) {
            return true;
        }

        $scheduled = wp_schedule_single_event( $timestamp, self::SCHEDULE_HOOK, $args, true );
        if ( is_wp_error( $scheduled ) || ! $scheduled ) {
            $message = is_wp_error( $scheduled ) ? $scheduled->get_error_message() : 'WP-Cron did not schedule the event.';
            self::mark_schedule_failure( $order, $attempt, $message );
            return false;
        }
        return true;
    }

    private static function mark_schedule_failure( \WC_Order $order, array $attempt, string $message ): void {
        $existing = $order->get_meta( YSOrderMeta::REFUND_REVIEW );
        $already_recorded = is_array( $existing )
            && 'reconcile_schedule_failed' === (string) ( $existing['type'] ?? '' )
            && (string) $attempt['refund_reference'] === (string) ( $existing['reference'] ?? '' );

        $order->update_meta_data(
            YSOrderMeta::REFUND_REVIEW,
            array(
                'type'      => 'reconcile_schedule_failed',
                'reference' => (string) $attempt['refund_reference'],
                'stage'     => (int) ( $attempt['stage'] ?? 0 ),
                'error'     => $message,
                'ts'        => time(),
            )
        );
        if ( ! $already_recorded ) {
            $order->add_order_note(
                __( '🔴 SHOPLINE 退款已送出，但自動確認排程建立失敗。WooCommerce 尚未建立退款，請至 SHOPLINE 後台核對，確認前請勿再次退款。', 'ys-shopline-via-woocommerce' )
            );
        }
        $order->save();

        YSLogger::error(
            'Failed to schedule SHOPLINE refund reconciliation',
            array(
                'order_id' => $order->get_id(),
                'reference' => (string) $attempt['refund_reference'],
                'stage' => (int) ( $attempt['stage'] ?? 0 ),
                'error' => $message,
            )
        );
    }

    private static function advance_or_review( \WC_Order $order, array $attempt, string $reason ): void {
        $attempt['stage'] = (int) ( $attempt['stage'] ?? 0 ) + 1;
        if ( ! array_key_exists( (int) $attempt['stage'], self::SCHEDULE_DELAYS ) ) {
            $attempt['review_reason'] = $reason;
            $order->update_meta_data( YSOrderMeta::REFUND_CONFIRMATION_DATA, $attempt );
            $order->update_meta_data(
                YSOrderMeta::REFUND_REVIEW,
                array(
                    'type'            => 'remote_status_unresolved',
                    'reference'       => (string) $attempt['refund_reference'],
                    'refund_order_id' => (string) ( $attempt['refund_order_id'] ?? '' ),
                    'last_status'     => (string) ( $attempt['last_status'] ?? 'UNKNOWN' ),
                    'ts'              => time(),
                )
            );
            $order->add_order_note(
                __( '⚠️ SHOPLINE 退款結果仍無法確認；SHOPLINE 可能已退款，但 WooCommerce 尚未建立退款。請至 SHOPLINE 後台核對，確認前請勿再次退款。', 'ys-shopline-via-woocommerce' )
            );
            $order->save();
            return;
        }

        self::persist_attempt( $order, $attempt );
        self::schedule_next( $order, $attempt );
    }

    private static function create_local_refund( \WC_Order $order, array $attempt, string $source ): void {
        if ( 'manual_review' === (string) ( $attempt['local_state'] ?? '' ) ) {
            return;
        }

        $reference = (string) $attempt['refund_reference'];
        $existing_refund = self::find_local_refund( $order, $reference );
        if ( is_object( $existing_refund ) ) {
            self::finish_local_success( $order, $attempt, $existing_refund, $source . '_adopted' );
            return;
        }

        $snapshot = is_array( $attempt['snapshot'] ?? null ) ? $attempt['snapshot'] : array();
        $args = array(
            'amount'         => (float) ( $snapshot['amount'] ?? 0 ),
            'reason'         => (string) ( $snapshot['reason'] ?? '' ),
            'order_id'       => $order->get_id(),
            'line_items'     => is_array( $snapshot['line_items'] ?? null ) ? $snapshot['line_items'] : array(),
            'refund_payment' => false,
            'restock_items'  => ! empty( $snapshot['restock_items'] ),
        );

        self::$local_creation_context = array(
            'order_id'  => $order->get_id(),
            'reference' => $reference,
        );
        try {
            $refund = wc_create_refund( $args );
        } catch ( \Throwable $error ) {
            $refund = new \WP_Error( 'ys_shopline_local_refund_exception', $error->getMessage() );
        } finally {
            self::$local_creation_context = null;
        }

        if ( is_wp_error( $refund ) || ! is_object( $refund ) ) {
            $fresh_order = wc_get_order( $order->get_id() );
            if ( $fresh_order instanceof \WC_Order ) {
                $existing_refund = self::find_local_refund( $fresh_order, $reference );
                if ( is_object( $existing_refund ) ) {
                    self::finish_local_success( $fresh_order, $attempt, $existing_refund, $source . '_recovered' );
                    return;
                }
                $order = $fresh_order;
            }
            self::mark_local_creation_failed( $order, $attempt, $refund );
            return;
        }

        self::finish_local_success( $order, $attempt, $refund, $source );
    }

    /** @return object|null */
    private static function find_local_refund( \WC_Order $order, string $reference ) {
        foreach ( method_exists( $order, 'get_refunds' ) ? $order->get_refunds() : array() as $refund ) {
            if ( is_object( $refund )
                && method_exists( $refund, 'get_meta' )
                && $reference === (string) $refund->get_meta( YSOrderMeta::REFUND_REFERENCE ) ) {
                return $refund;
            }
        }
        return null;
    }

    private static function finish_local_success( \WC_Order $order, array $attempt, $refund, string $source ): void {
        $refund_id = method_exists( $refund, 'get_id' ) ? (int) $refund->get_id() : 0;
        $order->update_meta_data(
            YSOrderMeta::REFUND_DETAIL,
            array(
                'refundOrderId'    => (string) ( $attempt['refund_order_id'] ?? '' ),
                'referenceOrderId' => (string) $attempt['refund_reference'],
                'tradeOrderId'     => (string) $attempt['trade_order_id'],
                'amount'           => array(
                    'value'    => (int) $attempt['amount'],
                    'currency' => (string) $attempt['currency'],
                ),
                'status'           => 'SUCCEEDED',
                'wcRefundId'       => $refund_id,
            )
        );
        self::append_history( $order, $attempt, 'local_refund_created', $source, $refund_id );
        $order->delete_meta_data( YSOrderMeta::REFUND_CONFIRMATION_DATA );
        $order->delete_meta_data( YSOrderMeta::REFUND_REVIEW );
        $order->add_order_note(
            sprintf(
                __( 'SHOPLINE 退款已確認完成，WooCommerce 退款 #%1$d 已建立。退款編號：%2$s', 'ys-shopline-via-woocommerce' ),
                $refund_id,
                (string) ( $attempt['refund_order_id'] ?? '' )
            )
        );
        $order->save();
    }

    private static function mark_local_creation_failed( \WC_Order $order, array $attempt, $error ): void {
        $message = is_wp_error( $error ) ? $error->get_error_message() : 'Invalid local refund result';
        $attempt['last_status'] = 'SUCCEEDED';
        $attempt['local_state'] = 'manual_review';
        $order->update_meta_data( YSOrderMeta::REFUND_CONFIRMATION_DATA, $attempt );
        $order->update_meta_data(
            YSOrderMeta::REFUND_REVIEW,
            array(
                'type'            => 'local_refund_creation_failed',
                'reference'       => (string) $attempt['refund_reference'],
                'refund_order_id' => (string) ( $attempt['refund_order_id'] ?? '' ),
                'error'           => $message,
                'ts'              => time(),
            )
        );
        $order->add_order_note(
            sprintf(
                __( '🔴 SHOPLINE 已退款，但 WooCommerce 退款建立失敗：%s。請人工補登，系統不會再次呼叫遠端退款。', 'ys-shopline-via-woocommerce' ),
                $message
            )
        );
        $order->save();
        YSLogger::error(
            'Remote refund succeeded but local WooCommerce refund creation failed',
            array( 'order_id' => $order->get_id(), 'reference' => $attempt['refund_reference'], 'error' => $message )
        );
    }

    private static function complete_failed_attempt( \WC_Order $order, array $attempt, array $response, string $source ): void {
        $order->update_meta_data( YSOrderMeta::REFUND_DETAIL, $response );
        self::append_history( $order, $attempt, 'remote_refund_failed', $source, 0 );
        $order->delete_meta_data( YSOrderMeta::REFUND_CONFIRMATION_DATA );
        $order->delete_meta_data( YSOrderMeta::REFUND_REVIEW );
        $order->add_order_note( __( 'SHOPLINE 已確認退款未成功，WooCommerce 未建立退款；您可以重新操作。', 'ys-shopline-via-woocommerce' ) );
        $order->save();
    }

    private static function is_transport_unknown( \WP_Error $error ): bool {
        $data        = $error->get_error_data();
        $http_status = is_array( $data ) ? (int) ( $data['http_status'] ?? 0 ) : 0;
        $code        = (string) $error->get_error_code();

        if ( $http_status >= 400 && $http_status < 500 && ! in_array( $http_status, array( 408, 429 ), true ) ) {
            return false;
        }

        if ( in_array( $code, array( 'http_request_failed', 'empty_response', 'json_decode_error', 'api_error' ), true ) ) {
            return true;
        }

        return 0 === $http_status || 408 === $http_status || 429 === $http_status || $http_status >= 500;
    }

    private static function reject_create_error( \WC_Order $order, array $attempt, \WP_Error $error ): \WP_Error {
        $order->update_meta_data(
            YSOrderMeta::REFUND_DETAIL,
            array(
                'referenceOrderId' => (string) $attempt['refund_reference'],
                'tradeOrderId'     => (string) $attempt['trade_order_id'],
                'amount'           => array(
                    'value'    => (int) $attempt['amount'],
                    'currency' => (string) $attempt['currency'],
                ),
                'status'           => 'FAILED',
                'errorCode'        => (string) $error->get_error_code(),
                'errorMessage'     => (string) $error->get_error_message(),
            )
        );
        self::append_history( $order, $attempt, 'remote_create_rejected', 'create', 0 );
        $order->delete_meta_data( YSOrderMeta::REFUND_CONFIRMATION_DATA );
        $order->delete_meta_data( YSOrderMeta::REFUND_REVIEW );
        $order->add_order_note(
            sprintf(
                __( 'SHOPLINE 退款送出失敗（%1$s）：%2$s。WooCommerce 未建立退款。', 'ys-shopline-via-woocommerce' ),
                (string) $error->get_error_code(),
                (string) $error->get_error_message()
            )
        );
        $order->save();
        return $error;
    }

    private static function append_history( \WC_Order $order, array $attempt, string $event, string $source, int $refund_id ): void {
        $history = $order->get_meta( YSOrderMeta::REFUND_HISTORY );
        $history = is_array( $history ) ? $history : array();
        $history[] = array(
            'reference'       => (string) $attempt['refund_reference'],
            'refund_order_id' => (string) ( $attempt['refund_order_id'] ?? '' ),
            'status'          => (string) ( $attempt['last_status'] ?? '' ),
            'event'           => $event,
            'source'          => $source,
            'wc_refund_id'    => $refund_id,
            'ts'              => time(),
        );
        $order->update_meta_data( YSOrderMeta::REFUND_HISTORY, $history );
    }

    /** @return mixed */
    private static function with_lock( int $order_id, callable $callback ) {
        global $wpdb;

        if ( ! $wpdb ) {
            return new \WP_Error( 'ys_shopline_refund_lock_unavailable', __( '退款鎖目前無法使用，請稍後再試。', 'ys-shopline-via-woocommerce' ) );
        }

        $lock_name = 'ys_slp_refund_' . $order_id;
        $acquired  = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock_name, 2 ) );
        if ( '1' !== (string) $acquired ) {
            return new \WP_Error( 'ys_shopline_refund_locked', __( '此訂單已有退款作業進行中，請稍後再試。', 'ys-shopline-via-woocommerce' ) );
        }

        try {
            return $callback();
        } finally {
            $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
        }
    }
}
