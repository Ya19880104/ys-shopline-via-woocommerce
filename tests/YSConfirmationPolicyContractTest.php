<?php
/**
 * Contract tests for the payment-confirmation status policy.
 *
 * @package YangSheep\ShoplinePayment\Tests
 */

declare(strict_types=1);

use YangSheep\ShoplinePayment\Utils\YSConfirmationPolicy;

function ys_run_confirmation_policy_contract(): void {
	echo "== Payment confirmation: pure policy ==\n";

	if ( ! class_exists( YSConfirmationPolicy::class ) ) {
		YS_Assert::eq( 'confirmation policy class is available', true, false );
		return;
	}

	foreach ( array( 'SUCCEEDED', 'SUCCESS', 'CAPTURED', 'PARTIALLY_REFUND', 'PARTIALLY_REFUNDED' ) as $status ) {
		YS_Assert::eq( "family($status) -> paid", YSConfirmationPolicy::FAMILY_PAID, YSConfirmationPolicy::family( $status ) );
	}

	foreach ( array( 'CREATED', 'CUSTOMER_ACTION' ) as $status ) {
		YS_Assert::eq( "family($status) -> customer_pending", YSConfirmationPolicy::FAMILY_CUSTOMER_PENDING, YSConfirmationPolicy::family( $status ) );
	}

	foreach ( array( 'AUTHORIZED', 'PROCESSING', 'PENDING' ) as $status ) {
		YS_Assert::eq( "family($status) -> in_flight", YSConfirmationPolicy::FAMILY_IN_FLIGHT, YSConfirmationPolicy::family( $status ) );
	}

	foreach ( array( 'FAILED', 'EXPIRED', 'CANCELLED', 'CANCELED', 'REFUNDED' ) as $status ) {
		YS_Assert::eq( "family($status) -> terminal", YSConfirmationPolicy::FAMILY_TERMINAL, YSConfirmationPolicy::family( $status ) );
	}

	foreach ( array( '', 'WHAT', 'INDETERMINATE' ) as $status ) {
		YS_Assert::eq( "family($status) -> unknown", YSConfirmationPolicy::FAMILY_UNKNOWN, YSConfirmationPolicy::family( $status ) );
	}

	YS_Assert::eq( 'AUTHORIZED uses authorized reason', YSConfirmationPolicy::REASON_AUTHORIZED, YSConfirmationPolicy::reason_for( 'AUTHORIZED', 'ys_shopline_credit' ) );
	YS_Assert::eq( 'PROCESSING uses in-flight reason', YSConfirmationPolicy::REASON_IN_FLIGHT, YSConfirmationPolicy::reason_for( 'PROCESSING', 'ys_shopline_credit' ) );
	YS_Assert::eq( 'BNPL PROCESSING uses review reason', YSConfirmationPolicy::REASON_BNPL_REVIEW, YSConfirmationPolicy::reason_for( 'PROCESSING', 'ys_shopline_bnpl' ) );
	YS_Assert::eq( 'unknown uses indeterminate reason', YSConfirmationPolicy::REASON_INDETERMINATE, YSConfirmationPolicy::reason_for( 'WHAT', 'ys_shopline_credit' ) );

	YS_Assert::eq(
		'card/wallet query schedule follows SHOPLINE wait windows',
		array( 120, 300, 900, 1800, 3600, 10800, 21600 ),
		YSConfirmationPolicy::query_delays( 'ys_shopline_credit' )
	);
	YS_Assert::eq(
		'Apple Pay uses card/wallet query schedule',
		array( 120, 300, 900, 1800, 3600, 10800, 21600 ),
		YSConfirmationPolicy::query_delays( 'ys_shopline_applepay' )
	);
	YS_Assert::eq(
		'BNPL query schedule extends to 24 hours',
		array( 300, 900, 3600, 21600, 86400 ),
		YSConfirmationPolicy::query_delays( 'ys_shopline_bnpl' )
	);
	YS_Assert::eq( 'stage zero schedules first card query', 120, YSConfirmationPolicy::delay_for_stage( 'ys_shopline_credit', 0 ) );
	YS_Assert::eq( 'last configured card stage has delay', 21600, YSConfirmationPolicy::delay_for_stage( 'ys_shopline_credit', 6 ) );
	YS_Assert::eq( 'stage after final card query has no delay', null, YSConfirmationPolicy::delay_for_stage( 'ys_shopline_credit', 7 ) );
	YS_Assert::is_true( 'card stage seven requires manual review', YSConfirmationPolicy::is_review_stage( 'ys_shopline_credit', 7 ) );
	YS_Assert::eq( 'query stages are cumulative from attempt start', 1300, YSConfirmationPolicy::scheduled_at( 'ys_shopline_credit', 1, 1000, 1100 ) );
	YS_Assert::eq( 'overdue query is queued five seconds from now', 1405, YSConfirmationPolicy::scheduled_at( 'ys_shopline_credit', 1, 1000, 1400 ) );
	YS_Assert::eq( 'review stage has no scheduled timestamp', null, YSConfirmationPolicy::scheduled_at( 'ys_shopline_credit', 7, 1000, 1100 ) );
	YS_Assert::eq( 'elapsed 15 minutes is never terminal', false, YSConfirmationPolicy::elapsed_time_is_terminal( 900 ) );
}
