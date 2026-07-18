<?php
/**
 * Pure payment-confirmation lifecycle policy.
 *
 * @package YangSheep\ShoplinePayment\Utils
 */

declare(strict_types=1);

namespace YangSheep\ShoplinePayment\Utils;

defined( 'ABSPATH' ) || exit;

/**
 * Classifies SHOPLINE trade states and defines reconciliation intervals.
 */
final class YSConfirmationPolicy {

    public const FAMILY_PAID            = 'paid';
    public const FAMILY_CUSTOMER_PENDING = 'customer_pending';
    public const FAMILY_IN_FLIGHT        = 'in_flight';
    public const FAMILY_TERMINAL         = 'terminal';
    public const FAMILY_UNKNOWN          = 'unknown';

    public const REASON_AUTHORIZED    = 'authorized';
    public const REASON_IN_FLIGHT     = 'in_flight';
    public const REASON_INDETERMINATE = 'indeterminate';
    public const REASON_BNPL_REVIEW   = 'bnpl_review';

    /** @var int[] */
    private const CARD_QUERY_DELAYS = array( 120, 300, 900, 1800, 3600, 10800, 21600 );

    /** @var int[] */
    private const BNPL_QUERY_DELAYS = array( 300, 900, 3600, 21600, 86400 );

    /**
     * Return the lifecycle family for a SHOPLINE trade status.
     */
    public static function family( string $status ): string {
        if ( YSTradeStatus::is_paid( $status ) ) {
            return self::FAMILY_PAID;
        }
        if ( YSTradeStatus::is_customer_pending( $status ) ) {
            return self::FAMILY_CUSTOMER_PENDING;
        }
        if ( YSTradeStatus::is_in_flight( $status ) ) {
            return self::FAMILY_IN_FLIGHT;
        }
        if ( YSTradeStatus::is_terminal_safe( $status ) ) {
            return self::FAMILY_TERMINAL;
        }

        return self::FAMILY_UNKNOWN;
    }

    /**
     * Return the internal reason recorded for an unsettled payment.
     */
    public static function reason_for( string $status, string $gateway_id ): string {
        $status = strtoupper( $status );

        if ( 'ys_shopline_bnpl' === $gateway_id && self::FAMILY_IN_FLIGHT === self::family( $status ) ) {
            return self::REASON_BNPL_REVIEW;
        }
        if ( 'AUTHORIZED' === $status ) {
            return self::REASON_AUTHORIZED;
        }
        if ( self::FAMILY_IN_FLIGHT === self::family( $status ) ) {
            return self::REASON_IN_FLIGHT;
        }

        return self::REASON_INDETERMINATE;
    }

    /**
     * Return cumulative reconciliation delays for a gateway.
     *
     * @return int[]
     */
    public static function query_delays( string $gateway_id ): array {
        return 'ys_shopline_bnpl' === $gateway_id
            ? self::BNPL_QUERY_DELAYS
            : self::CARD_QUERY_DELAYS;
    }

    /**
     * Return the delay for a zero-based reconciliation stage.
     */
    public static function delay_for_stage( string $gateway_id, int $stage ): ?int {
        $delays = self::query_delays( $gateway_id );
        return $delays[ $stage ] ?? null;
    }

    /**
     * A stage beyond the configured polling window requires manual review.
     */
    public static function is_review_stage( string $gateway_id, int $stage ): bool {
        return null === self::delay_for_stage( $gateway_id, $stage );
    }

    /**
     * Return the absolute timestamp for one cumulative query stage.
     *
     * Overdue jobs are queued a few seconds ahead so the scheduler does not
     * receive a timestamp in the past.
     */
    public static function scheduled_at( string $gateway_id, int $stage, int $started_at, int $now ): ?int {
        $delay = self::delay_for_stage( $gateway_id, $stage );
        if ( null === $delay ) {
            return null;
        }

        return max( $now + 5, $started_at + $delay );
    }

    /**
     * Elapsed time alone must never prove that a payment failed.
     */
    public static function elapsed_time_is_terminal( int $elapsed_seconds ): bool {
        return false;
    }
}
