<?php
/** Original request and received payment evidence, shared by all paid entry points. */
declare(strict_types=1);

namespace YangSheep\ShoplinePayment\Utils;

defined( 'ABSPATH' ) || exit;

final class YSPaymentEvidence {
    public const REVIEW_TYPE = 'payment_evidence_mismatch';

    public static function is_blocked( \WC_Order $order ): bool {
        return self::REVIEW_TYPE === $order->get_meta( YSOrderMeta::MANUAL_REVIEW_FLAG )
            && ! $order->get_meta( YSOrderMeta::MANUAL_REVIEW_RESOLVED );
    }

    /** Normalize only remote fields; never fill missing evidence from the order. */
    public static function normalize( array $raw, string $trade ): array {
        if ( isset( $raw['paymentDetails'] ) ) {
            $matches = array_filter( (array) $raw['paymentDetails'], static function ( $detail ) use ( $trade ) {
                return is_array( $detail ) && ( $detail['tradeOrderId'] ?? '' ) === $trade;
            } );
            if ( 1 !== count( $matches ) ) {
                return array();
            }
            $detail = reset( $matches );
            $raw = array_merge( $detail, array(
                'referenceOrderId' => $raw['referenceId'] ?? '',
                'amount' => $raw['amount'] ?? array(),
            ) );
        }
        $payment = is_array( $raw['payment'] ?? null ) ? $raw['payment'] : array();
        return array(
            'trade' => self::scalar( $raw['tradeOrderId'] ?? '' ),
            'reference' => self::scalar( $raw['referenceOrderId'] ?? $raw['order']['referenceOrderId'] ?? '' ),
            'status' => strtoupper( self::scalar( $raw['status'] ?? '' ) ),
            'method' => self::scalar( $raw['paymentMethod'] ?? $payment['paymentMethod'] ?? '' ),
            'amount' => $payment['paidAmount'] ?? $raw['paidAmount'] ?? $raw['amount'] ?? $raw['order']['amount'] ?? array(),
        );
    }

    /** Some create responses omit the financial envelope; query it, never invent it. */
    public static function from_create_response( array $raw, $api ): array {
        $trade = self::scalar( $raw['tradeOrderId'] ?? '' );
        $evidence = self::normalize( $raw, $trade );
        $amount = $evidence['amount'] ?? array();
        if ( '' !== $trade && YSTradeStatus::is_paid( $evidence['status'] ?? '' ) && $api
            && ( empty( $evidence['reference'] ) || empty( $evidence['method'] )
                || ! isset( $amount['value'], $amount['currency'] ) ) ) {
            $queried = $api->get_payment_trade( $trade );
            if ( is_array( $queried ) ) {
                return $queried;
            }
        }
        return $raw;
    }

    public static function scalar( $value ): string {
        return is_scalar( $value ) ? trim( (string) $value ) : '';
    }

    /** Return an explicit rejection reason, or an empty string when all evidence matches. */
    public static function mismatch( \WC_Order $order, string $trade, array $raw ): string {
        if ( self::is_blocked( $order ) ) {
            return 'manual_review_pending';
        }
        $request = $order->get_meta( YSOrderMeta::PAYMENT_ATTEMPT_DATA );
        if ( ! is_array( $request ) || empty( $request ) ) {
            $request = $order->get_meta( YSOrderMeta::INDETERMINATE_DATA );
        }
        foreach ( array( 'reference', 'gateway', 'shopline_method', 'amount', 'currency' ) as $key ) {
            if ( ! is_array( $request ) || ! isset( $request[$key] ) || '' === self::scalar( $request[$key] ) ) {
                return 'original_request_missing';
            }
        }
        $remote = self::normalize( $raw, $trade );
        $amount = $remote['amount'] ?? array();
        if ( ! is_array( $amount ) || ! isset( $amount['value'], $amount['currency'] )
            || ! is_numeric( $amount['value'] ) || (float) $amount['value'] !== (float) (int) $amount['value'] ) {
            return 'remote_amount_missing_or_invalid';
        }
        if ( '' === $trade || $trade !== ( $remote['trade'] ?? '' )
            || ! YSTradeStatus::is_paid( $remote['status'] ?? '' ) ) {
            return 'remote_trade_or_status_mismatch';
        }
        $current_trade = self::scalar( $order->get_meta( YSOrderMeta::TRADE_ORDER_ID ) );
        if ( '' !== $current_trade && $current_trade !== $trade ) {
            return 'current_trade_mismatch';
        }
        if ( $request['reference'] !== ( $remote['reference'] ?? '' )
            || $request['reference'] !== self::scalar( $order->get_meta( YSOrderMeta::REFERENCE_ORDER_ID ) )
            || $request['shopline_method'] !== ( $remote['method'] ?? '' )
            || $request['gateway'] !== $order->get_payment_method() ) {
            return 'reference_or_method_mismatch';
        }
        if ( (int) $request['amount'] !== (int) $amount['value']
            || strtoupper( $request['currency'] ) !== strtoupper( self::scalar( $amount['currency'] ) ) ) {
            return 'paid_amount_or_currency_mismatch';
        }
        $order_amount = (int) \YSShoplinePayment::get_formatted_amount( $order->get_total(), $order->get_currency() );
        $original_order_amount = (int) ( $request['order_amount'] ?? $request['amount'] );
        $original_currency = strtoupper( (string) ( $request['order_currency'] ?? $request['currency'] ) );
        if ( $original_order_amount !== $order_amount || $original_currency !== strtoupper( $order->get_currency() ) ) {
            return 'order_changed_after_request';
        }
        // The zero-total subscription CardBind request uses an explicit authorization amount.
        $card_bind = 0 === $original_order_amount
            && 'ys_shopline_credit_subscription' === $request['gateway']
            && 'CreditCard' === $request['shopline_method']
            && 'CardBind' === ( $request['payment_behavior'] ?? '' );
        if ( $original_currency !== strtoupper( $request['currency'] )
            || ( ! $card_bind && $original_order_amount !== (int) $request['amount'] ) ) {
            return 'request_does_not_match_order';
        }
        return '';
    }

    /** Must run while the caller owns the payment-completion lock. */
    public static function accept( \WC_Order $order, string $trade, array $raw ): bool {
        $reason = self::mismatch( $order, $trade, $raw );
        if ( '' === $reason ) {
            return true;
        }
        if ( 'manual_review_pending' === $reason ) {
            return false;
        }
        $entries = $order->get_meta( YSOrderMeta::MANUAL_REVIEW_DATA );
        $entries = is_array( $entries ) ? $entries : array();
        $entries[] = array(
            'type' => self::REVIEW_TYPE, 'reason' => $reason, 'ts' => time(),
            'trade' => $trade, 'evidence' => self::normalize( $raw, $trade ),
            'request' => $order->get_meta( YSOrderMeta::PAYMENT_ATTEMPT_DATA ),
            'indeterminate_request' => $order->get_meta( YSOrderMeta::INDETERMINATE_DATA ),
            'order_total' => $order->get_total(), 'order_currency' => $order->get_currency(),
        );
        $order->update_meta_data( YSOrderMeta::MANUAL_REVIEW_FLAG, self::REVIEW_TYPE );
        $order->update_meta_data( YSOrderMeta::MANUAL_REVIEW_DATA, $entries );
        $order->delete_meta_data( YSOrderMeta::MANUAL_REVIEW_RESOLVED );
        $order->add_order_note( sprintf(
            __( 'SHOPLINE 收款資料與原始付款請求或目前訂單不一致（%s）。已停止自動入帳及重新付款，請至 SHOPLINE 後台核對金額、幣別與交易編號；請勿要求顧客再次付款。', 'ys-shopline-via-woocommerce' ),
            $reason
        ) );
        $order->save();
        YSLogger::error( 'Payment completion rejected: evidence mismatch', array( 'order_id' => $order->get_id(), 'trade' => $trade, 'reason' => $reason ) );
        return false;
    }
}
