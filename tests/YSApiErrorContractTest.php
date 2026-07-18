<?php
/**
 * Contract tests for create-trade remote outcome classification.
 *
 * @package YangSheep\ShoplinePayment\Tests
 */

declare(strict_types=1);

use YangSheep\ShoplinePayment\Utils\YSApiError;
use YangSheep\ShoplinePayment\Api\YSApi;
use YangSheep\ShoplinePayment\Api\YSApiException;

final class YS_Api_Diagnostic_Requester {
	public function post( string $endpoint, array $data, string $idempotent_key = '' ) {
		throw new YSApiException(
			'4003',
			'channel timeout',
			504,
			array(
				'http_status'    => 504,
				'request_id'     => 'req-map-1',
				'response_keys'  => array( 'code', 'msg' ),
			)
		);
	}
}

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

	echo "== YSApiError: safe unknown diagnostics ==\n";
	$has_diagnostics = method_exists( YSApiError::class, 'diagnostic_context' );
	YS_Assert::eq( 'unknown diagnostic normalizer exists', true, $has_diagnostics );
	if ( $has_diagnostics ) {
		$wp_context = YSApiError::diagnostic_context(
			new WP_Error(
				'http_request_failed',
				'API request timed out',
				array(
					'http_status'    => 0,
					'request_id'     => 'req-timeout-1',
					'response_keys'  => array(),
					'transport_error' => 'Connection timed out after send',
				)
			)
		);
		YS_Assert::eq( 'transport diagnostic keeps HTTP status', 0, $wp_context['http_status'] ?? null );
		YS_Assert::eq( 'transport diagnostic keeps request id', 'req-timeout-1', $wp_context['request_id'] ?? '' );
		YS_Assert::eq( 'transport diagnostic keeps exact error code', 'http_request_failed', $wp_context['error_code'] ?? '' );
		YS_Assert::eq( 'transport diagnostic keeps safe transport message', 'Connection timed out after send', $wp_context['transport_error'] ?? '' );
		YS_Assert::eq( 'transport diagnostic never claims nextAction', false, $wp_context['has_next_action'] ?? null );
		YS_Assert::eq( 'transport diagnostic never claims trade id', false, $wp_context['has_trade_order_id'] ?? null );

		$array_context = YSApiError::diagnostic_context(
			array(
				'status'      => 'SUCCEEDED',
				'paymentMsg'  => array( 'code' => '4458', 'msg' => 'still processing' ),
				'nextAction'  => array( 'type' => 'Redirect' ),
				'customerToken' => 'must-not-be-logged',
			)
		);
		YS_Assert::eq( 'array diagnostic records response keys only', array( 'status', 'paymentMsg', 'nextAction', 'customerToken' ), $array_context['response_keys'] ?? array() );
		YS_Assert::eq( 'array diagnostic records nextAction presence', true, $array_context['has_next_action'] ?? null );
		YS_Assert::eq( 'array diagnostic records missing trade id', false, $array_context['has_trade_order_id'] ?? null );
		YS_Assert::eq( 'array diagnostic records payment error code', '4458', $array_context['payment_error_code'] ?? '' );
		YS_Assert::eq( 'array diagnostic records payment message', 'still processing', $array_context['payment_error_message'] ?? '' );
		YS_Assert::eq( 'array diagnostic never includes raw sensitive values', false, str_contains( wp_json_encode( $array_context ), 'must-not-be-logged' ) );
	}

	$exception = new YSApiException(
		'4003',
		'channel timeout',
		504,
		array( 'request_id' => 'req-504', 'http_status' => 504 )
	);
	$has_exception_context = method_exists( $exception, 'get_context' );
	YS_Assert::eq( 'API exception preserves diagnostic context', true, $has_exception_context );
	if ( $has_exception_context ) {
		YS_Assert::eq( 'API exception context keeps HTTP status', 504, $exception->get_context()['http_status'] ?? null );
		YS_Assert::eq( 'API exception context keeps request id', 'req-504', $exception->get_context()['request_id'] ?? '' );
	}

	$api_ref = new ReflectionClass( YSApi::class );
	$api     = $api_ref->newInstanceWithoutConstructor();
	$requester_property = $api_ref->getProperty( 'requester' );
	$requester_property->setValue( $api, new YS_Api_Diagnostic_Requester() );
	$mapped = $api->create_payment_trade( array(), 'idem-1' );
	YS_Assert::eq( 'YSApi maps requester exception to WP_Error', true, is_wp_error( $mapped ) );
	YS_Assert::eq( 'YSApi keeps requester HTTP status in WP_Error data', 504, $mapped->get_error_data()['http_status'] ?? null );
	YS_Assert::eq( 'YSApi keeps requester id in WP_Error data', 'req-map-1', $mapped->get_error_data()['request_id'] ?? '' );
	YS_Assert::eq( 'YSApi keeps response key names in WP_Error data', array( 'code', 'msg' ), $mapped->get_error_data()['response_keys'] ?? array() );
}
