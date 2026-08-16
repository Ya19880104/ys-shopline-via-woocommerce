<?php
/**
 * Contract tests for SHOPLINE refund status classification.
 *
 * @package YangSheep\ShoplinePayment\Tests
 */

declare(strict_types=1);

use YangSheep\ShoplinePayment\Utils\YSRefundStatus;

function ys_run_refund_status_contract(): void {
	echo "== Refund status: fail-closed classification ==\n";

	YS_Assert::eq( 'refund status classifier is available', true, class_exists( YSRefundStatus::class ) );
	if ( ! class_exists( YSRefundStatus::class ) ) {
		return;
	}

	YS_Assert::eq( 'SUCCEEDED is confirmed success', YSRefundStatus::FAMILY_SUCCEEDED, YSRefundStatus::family( 'succeeded' ) );
	YS_Assert::eq( 'CREATED remains in flight', YSRefundStatus::FAMILY_IN_FLIGHT, YSRefundStatus::family( 'CREATED' ) );
	YS_Assert::eq( 'PROCESSING remains in flight', YSRefundStatus::FAMILY_IN_FLIGHT, YSRefundStatus::family( 'processing' ) );
	YS_Assert::eq( 'PENDING remains in flight', YSRefundStatus::FAMILY_IN_FLIGHT, YSRefundStatus::family( 'PENDING' ) );
	YS_Assert::eq( 'FAILED is confirmed failure', YSRefundStatus::FAMILY_FAILED, YSRefundStatus::family( 'FAILED' ) );
	YS_Assert::eq( 'CANCELLED is confirmed failure', YSRefundStatus::FAMILY_FAILED, YSRefundStatus::family( 'cancelled' ) );
	YS_Assert::eq( 'REJECTED is confirmed failure', YSRefundStatus::FAMILY_FAILED, YSRefundStatus::family( 'REJECTED' ) );
	YS_Assert::eq( 'unknown status stays unknown', YSRefundStatus::FAMILY_UNKNOWN, YSRefundStatus::family( 'SOMETHING_NEW' ) );
	YS_Assert::eq( 'missing status stays unknown', YSRefundStatus::FAMILY_UNKNOWN, YSRefundStatus::family( '' ) );
	YS_Assert::eq( 'unknown is never treated as success', false, YSRefundStatus::is_succeeded( 'SOMETHING_NEW' ) );
}
