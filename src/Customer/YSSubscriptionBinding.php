<?php
/** Order-scoped subscription card association; customer card events are not authorization. */
declare(strict_types=1);
namespace YangSheep\ShoplinePayment\Customer;

use YangSheep\ShoplinePayment\Utils\YSOrderMeta;
use YangSheep\ShoplinePayment\Utils\YSPaymentEvidence;

defined( 'ABSPATH' ) || exit;

final class YSSubscriptionBinding {
    public const META = '_ys_shopline_subscription_binding_request';

    public static function capture( \WC_Order $order, array $request, string $gateway ): void {
        if ( 'ys_shopline_credit_subscription' !== $gateway || ! function_exists( 'wcs_get_subscriptions_for_order' ) ) {
            return;
        }
        $ids = array();
        foreach ( wcs_get_subscriptions_for_order( $order, array( 'order_type' => 'parent' ) ) as $subscription ) {
            if ( (int) $subscription->get_parent_id() === (int) $order->get_id()
                && (int) $subscription->get_user_id() === (int) $order->get_user_id()
                && $gateway === $subscription->get_payment_method() ) {
                $ids[] = $subscription->get_id();
            }
        }
        if ( empty( $ids ) ) {
            return;
        }
        $binding = array(
            'reference' => $request['referenceOrderId'],
            'user_id' => $order->get_user_id(),
            'customer_id' => (string) ( $request['confirm']['paymentCustomerId'] ?? '' ),
            'instrument_id' => (string) ( $request['confirm']['paymentInstrument']['paymentInstrumentId'] ?? '' ),
            'subscriptions' => $ids, 'state' => 'awaiting_payment_evidence',
        );
        $order->update_meta_data( self::META, $binding );
    }

    /** Caller must first validate the payment against its persisted request. */
    public static function complete( \WC_Order $order, array $raw, string $trade ): void {
        $binding = $order->get_meta( self::META );
        if ( ! is_array( $binding ) || ! function_exists( 'wcs_get_subscription' )
            || 'bound' === ( $binding['state'] ?? '' ) ) {
            return;
        }
        $remote = YSPaymentEvidence::normalize( $raw, $trade );
        $payment = is_array( $raw['payment'] ?? null ) ? $raw['payment'] : array();
        $pi = $raw['paymentInstrument'] ?? $payment['paymentInstrument'] ?? array();
        $instrument = YSPaymentEvidence::scalar( $pi['paymentInstrumentId'] ?? $pi['instrumentId'] ?? '' );
        $customer = YSPaymentEvidence::scalar( $raw['paymentCustomerId'] ?? $payment['paymentCustomerId'] ?? '' );
        if ( '' === $instrument || '' === $customer || $customer !== $binding['customer_id']
            || $binding['reference'] !== ( $remote['reference'] ?? '' )
            || (int) $binding['user_id'] !== (int) $order->get_user_id()
            || ( '' !== $binding['instrument_id'] && $binding['instrument_id'] !== $instrument ) ) {
            return;
        }
        $complete = true;
        foreach ( $binding['subscriptions'] as $id ) {
            $subscription = wcs_get_subscription( $id );
            if ( ! $subscription || (int) $subscription->get_parent_id() !== (int) $order->get_id()
                || (int) $subscription->get_user_id() !== (int) $binding['user_id']
                || 'ys_shopline_credit_subscription' !== $subscription->get_payment_method() ) {
                $complete = false;
                continue;
            }
            $existing = (string) $subscription->get_meta( YSOrderMeta::PAYMENT_INSTRUMENT_ID );
            if ( '' !== $existing && $existing !== $instrument ) {
                $complete = false;
                continue;
            }
            if ( '' === $existing ) {
                $subscription->update_meta_data( YSOrderMeta::PAYMENT_INSTRUMENT_ID, $instrument );
                $subscription->update_meta_data( YSOrderMeta::CUSTOMER_ID, $customer );
                $subscription->save();
            }
        }
        $binding['state'] = $complete ? 'bound' : 'review_required';
        $binding['trade'] = $trade;
        $order->update_meta_data( self::META, $binding );
        $order->save();
    }
}
