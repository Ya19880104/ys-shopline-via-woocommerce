<?php
/**
 * Contract tests for create-trade remote outcome classification.
 *
 * @package YangSheep\ShoplinePayment\Tests
 */

declare(strict_types=1);

use YangSheep\ShoplinePayment\Utils\YSApiError;

/**
 * Run YSApiError contract cases.
 *
 * @return void
 */
function ys_run_api_error_contract(): void {
	echo "== YSApiError: explicit rejected vs unknown ==\n";

	foreach ( array( '1004', '2002', '4202', '4450', '4451', '4454', '4550', '4900', '4901', '4902' ) as $code ) {
		YS_Assert::eq(
			"WP_Error {$code} -> rejected",
			'rejected',
			YSApiError::classify( new WP_Error( $code, 'declined' ) )
		);
	}

	foreach ( array( 'http_request_failed', 'empty_response', 'json_decode_error', 'api_error', '1001', '1999', '4003', '4400', '4403', '4410', '4456', '4458' ) as $code ) {
		YS_Assert::eq(
			"WP_Error {$code} -> unknown",
			'unknown',
			YSApiError::classify( new WP_Error( $code, 'indeterminate' ) )
		);
	}

	echo "== YSApiError: create-trade response envelope ==\n";

	foreach ( array( 'SUCCEEDED', 'SUCCESS', 'CAPTURED', 'CREATED', 'CUSTOMER_ACTION', 'PROCESSING', 'AUTHORIZED', 'PENDING' ) as $status ) {
		YS_Assert::eq(
			"{$status} + tradeOrderId -> accepted",
			'accepted',
			YSApiError::classify_create_trade_response(
				array( 'tradeOrderId' => 'trade-1', 'status' => $status )
			)
		);
	}

	foreach ( array( 'FAILED', 'EXPIRED', 'CANCELLED', 'CANCELED', 'REFUNDED' ) as $status ) {
		YS_Assert::eq(
			"{$status} + tradeOrderId -> rejected",
			'rejected',
			YSApiError::classify_create_trade_response(
				array( 'tradeOrderId' => 'trade-1', 'status' => $status )
			)
		);
	}

	YS_Assert::eq(
		'success-ish without tradeOrderId -> unknown',
		'unknown',
		YSApiError::classify_create_trade_response( array( 'status' => 'SUCCEEDED' ) )
	);
	YS_Assert::eq(
		'in-flight without tradeOrderId -> unknown',
		'unknown',
		YSApiError::classify_create_trade_response( array( 'status' => 'PROCESSING' ) )
	);
	YS_Assert::eq(
		'unknown status with tradeOrderId -> unknown',
		'unknown',
		YSApiError::classify_create_trade_response( array( 'tradeOrderId' => 'trade-1', 'status' => 'WHAT' ) )
	);
	YS_Assert::eq(
		'missing status with tradeOrderId -> unknown',
		'unknown',
		YSApiError::classify_create_trade_response( array( 'tradeOrderId' => 'trade-1' ) )
	);
	YS_Assert::eq( 'non-array -> unknown', 'unknown', YSApiError::classify_create_trade_response( 'garbage' ) );
	YS_Assert::eq(
		'WP_Error rejected delegates to allowlist',
		'rejected',
		YSApiError::classify_create_trade_response( new WP_Error( '4451', 'declined' ) )
	);
	YS_Assert::eq(
		'WP_Error transport remains unknown',
		'unknown',
		YSApiError::classify_create_trade_response( new WP_Error( 'http_request_failed', 'timeout' ) )
	);
}
