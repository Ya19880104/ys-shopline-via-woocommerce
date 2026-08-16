<?php
/**
 * Contract tests for exact SHOPLINE refund webhook convergence.
 *
 * @package YangSheep\ShoplinePayment\Tests
 */

declare(strict_types=1);

use YangSheep\ShoplinePayment\Handlers\YSRefundReconciliation;
use YangSheep\ShoplinePayment\Handlers\YSWebhookHandler;
use YangSheep\ShoplinePayment\Utils\YSOrderMeta;

/** @return array{0:YS_Refund_Test_Order,1:YS_Refund_Test_Api,2:array} */
function ys_refund_webhook_fixture(): array {
	$order = ys_refund_test_order();
	ys_refund_capture();
	$api = new YS_Refund_Test_Api( $order );
	$api->query_responses = array( ys_refund_response( 'PROCESSING' ) );
	YSRefundReconciliation::process( $order, 100.0, 'Customer requested refund', $api, 'ys_shopline_bnpl', 'ChaileaseBNPL' );
	$attempt = $order->get_meta( YSOrderMeta::REFUND_CONFIRMATION_DATA );
	$GLOBALS['ys_test_refund_creations'] = array();
	$GLOBALS['ys_test_refund_creation_result'] = null;
	return array( $order, $api, $attempt );
}

function ys_refund_webhook_payload( array $attempt, string $status = 'SUCCEEDED' ): array {
	return array(
		'refundOrderId'    => $attempt['refund_order_id'],
		'referenceOrderId' => $attempt['refund_reference'],
		'tradeOrderId'     => $attempt['trade_order_id'],
		'amount'           => array(
			'value'    => $attempt['amount'],
			'currency' => $attempt['currency'],
		),
		'status'           => $status,
	);
}

function ys_run_refund_webhook_contract(): void {
	echo "== Refund webhook: exact envelope convergence ==\n";

	$has_handler = method_exists( YSRefundReconciliation::class, 'handle_webhook' );
	YS_Assert::eq( 'refund lifecycle exposes exact webhook convergence', true, $has_handler );
	if ( ! $has_handler ) {
		return;
	}

	list( $order, $api, $attempt ) = ys_refund_webhook_fixture();
	$payload = ys_refund_webhook_payload( $attempt );
	$handled = YSRefundReconciliation::handle_webhook( $order, $payload, 'trade.refund.succeeded' );
	YS_Assert::eq( 'matching refund success webhook is consumed', true, $handled );
	YS_Assert::eq( 'matching refund success creates one local refund', 1, count( $GLOBALS['ys_test_refund_creations'] ) );
	YS_Assert::eq( 'webhook success performs no additional remote call', 2, $api->create_calls + $api->query_calls );
	YS_Assert::eq( 'webhook success clears active attempt', '', $order->get_meta( YSOrderMeta::REFUND_CONFIRMATION_DATA ) );

	$duplicate = YSRefundReconciliation::handle_webhook( $order, $payload, 'trade.refund.succeeded' );
	YS_Assert::eq( 'duplicate webhook is ignored after exact convergence', false, $duplicate );
	YS_Assert::eq( 'duplicate webhook never creates another local refund', 1, count( $GLOBALS['ys_test_refund_creations'] ) );

	$mismatch_cases = array(
		'refundOrderId'    => 'wrong-refund',
		'referenceOrderId' => 'wrong-reference',
		'tradeOrderId'     => 'wrong-trade',
		'amount.value'     => 9999,
		'amount.currency'  => 'USD',
	);
	foreach ( $mismatch_cases as $field => $wrong ) {
		list( $candidate, , $candidate_attempt ) = ys_refund_webhook_fixture();
		$candidate_payload = ys_refund_webhook_payload( $candidate_attempt );
		if ( str_starts_with( $field, 'amount.' ) ) {
			$candidate_payload['amount'][ substr( $field, 7 ) ] = $wrong;
		} else {
			$candidate_payload[ $field ] = $wrong;
		}
		$before = $candidate->get_meta( YSOrderMeta::REFUND_CONFIRMATION_DATA );
		$handled = YSRefundReconciliation::handle_webhook( $candidate, $candidate_payload, 'trade.refund.succeeded' );
		YS_Assert::eq( "mismatched {$field} is rejected", false, $handled );
		YS_Assert::eq( "mismatched {$field} keeps active attempt", $before, $candidate->get_meta( YSOrderMeta::REFUND_CONFIRMATION_DATA ) );
		YS_Assert::eq( "mismatched {$field} creates no local refund", 0, count( $GLOBALS['ys_test_refund_creations'] ) );
	}

	list( $failed_order, , $failed_attempt ) = ys_refund_webhook_fixture();
	$failed_payload = ys_refund_webhook_payload( $failed_attempt, 'FAILED' );
	$failed_handled = YSRefundReconciliation::handle_webhook( $failed_order, $failed_payload, 'trade.refund.failed' );
	YS_Assert::eq( 'matching failed refund webhook is consumed', true, $failed_handled );
	YS_Assert::eq( 'failed refund webhook creates no local refund', 0, count( $GLOBALS['ys_test_refund_creations'] ) );
	YS_Assert::eq( 'failed refund webhook clears active attempt', '', $failed_order->get_meta( YSOrderMeta::REFUND_CONFIRMATION_DATA ) );

	echo "== Refund webhook: existing handler delegates ==\n";
	list( $routed_order, , $routed_attempt ) = ys_refund_webhook_fixture();
	$GLOBALS['ys_test_orders'] = array( $routed_order );
	$routed_payload = ys_refund_webhook_payload( $routed_attempt );
	$method = new ReflectionMethod( YSWebhookHandler::class, 'handle_refund_succeeded' );
	$method->invoke( new YSWebhookHandler(), $routed_payload );
	YS_Assert::eq( 'webhook handler routes success into local reconstruction', 1, count( $GLOBALS['ys_test_refund_creations'] ) );
	YS_Assert::eq( 'webhook handler route clears active refund attempt', '', $routed_order->get_meta( YSOrderMeta::REFUND_CONFIRMATION_DATA ) );
}
