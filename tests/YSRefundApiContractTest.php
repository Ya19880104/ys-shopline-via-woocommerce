<?php
/**
 * Contract tests for SHOPLINE refund API endpoints.
 *
 * @package YangSheep\ShoplinePayment\Tests
 */

declare(strict_types=1);

use YangSheep\ShoplinePayment\Api\YSApi;

final class YS_Refund_Api_Test_Requester {
	/** @var array<int, array{endpoint:string,data:array,idempotent_key:string}> */
	public array $posts = array();

	public function post( string $endpoint, array $data = array(), string $idempotent_key = '' ): array {
		$this->posts[] = compact( 'endpoint', 'data', 'idempotent_key' );
		return array( 'status' => 'CREATED' );
	}

	public function get( string $endpoint, array $data = array() ): array {
		return array();
	}
}

function ys_run_refund_api_contract(): void {
	echo "== Refund API: idempotent create and query endpoint ==\n";

	$api_ref = new ReflectionClass( YSApi::class );
	$api     = $api_ref->newInstanceWithoutConstructor();
	$fake    = new YS_Refund_Api_Test_Requester();
	$api_ref->getProperty( 'requester' )->setValue( $api, $fake );

	$api->create_refund(
		array(
			'tradeOrderId'     => 'trade-refund-1',
			'referenceOrderId' => '9001_refund_1',
		),
		'refund-idem-1'
	);

	YS_Assert::eq( 'refund create uses official endpoint', '/trade/refund/create', $fake->posts[0]['endpoint'] ?? '' );
	YS_Assert::eq( 'refund create forwards persistent idempotency key', 'refund-idem-1', $fake->posts[0]['idempotent_key'] ?? '' );

	$has_query = method_exists( $api, 'query_refund' );
	YS_Assert::eq( 'refund query API is available', true, $has_query );
	if ( ! $has_query ) {
		return;
	}

	$api->query_refund( 'refund-remote-1' );
	YS_Assert::eq( 'refund query uses official endpoint', '/trade/refund/get', $fake->posts[1]['endpoint'] ?? '' );
	YS_Assert::eq( 'refund query sends refundOrderId', 'refund-remote-1', $fake->posts[1]['data']['refundOrderId'] ?? '' );
}
