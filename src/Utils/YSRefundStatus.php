<?php
/**
 * SHOPLINE refund status classifier.
 *
 * @package YangSheep\ShoplinePayment\Utils
 */

declare(strict_types=1);

namespace YangSheep\ShoplinePayment\Utils;

defined( 'ABSPATH' ) || exit;

final class YSRefundStatus {

    public const FAMILY_SUCCEEDED = 'succeeded';
    public const FAMILY_IN_FLIGHT = 'in_flight';
    public const FAMILY_FAILED    = 'failed';
    public const FAMILY_UNKNOWN   = 'unknown';

    /** @var string[] */
    private const SUCCEEDED = array( 'SUCCEEDED' );

    /** @var string[] */
    private const IN_FLIGHT = array( 'CREATED', 'PROCESSING', 'PENDING' );

    /** @var string[] */
    private const FAILED = array( 'FAILED', 'CANCELLED', 'CANCELED', 'REJECTED' );

    public static function family( string $status ): string {
        $status = strtoupper( trim( $status ) );

        if ( in_array( $status, self::SUCCEEDED, true ) ) {
            return self::FAMILY_SUCCEEDED;
        }
        if ( in_array( $status, self::IN_FLIGHT, true ) ) {
            return self::FAMILY_IN_FLIGHT;
        }
        if ( in_array( $status, self::FAILED, true ) ) {
            return self::FAMILY_FAILED;
        }

        return self::FAMILY_UNKNOWN;
    }

    public static function is_succeeded( string $status ): bool {
        return self::FAMILY_SUCCEEDED === self::family( $status );
    }

    public static function is_in_flight( string $status ): bool {
        return self::FAMILY_IN_FLIGHT === self::family( $status );
    }

    public static function is_failed( string $status ): bool {
        return self::FAMILY_FAILED === self::family( $status );
    }
}
