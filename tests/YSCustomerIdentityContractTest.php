<?php
/**
 * Contract tests for customer-bound gateway scope and stale customer repair.
 *
 * @package YangSheep\ShoplinePayment\Tests
 */

declare(strict_types=1);

use YangSheep\ShoplinePayment\Gateways\YSGatewayBase;
use YangSheep\ShoplinePayment\Utils\YSOrderMeta;

final class YS_Customer_Identity_Test_Order {
	public array $meta = array();
	public function get_id(): int { return 9501; }
	public function get_user_id(): int { return 77; }
	public function get_total(): float { return 100.0; }
	public function get_currency(): string { return 'TWD'; }
	public function get_shipping_method(): string { return 'Test shipping'; }
	public function get_shipping_total(): float { return 0.0; }
	public function update_meta_data( string $key, $value ): void { $this->meta[ $key ] = $value; }
	public function save(): void {}
}

final class YS_Customer_Identity_Test_Api {
	public array $token_calls = array();
	public int $create_customer_calls = 0;
	public bool $generic_1005 = false;

	public function get_customer_token( string $customer_id ) {
		$this->token_calls[] = $customer_id;
		if ( 'stale-customer-77' === $customer_id ) {
			return new WP_Error(
				'1005',
				$this->generic_1005 ? 'Other validation check failed' : 'Enable Customer not found,customerId=stale-customer-77',
				array( 'http_status' => 400 )
			);
		}
		return array( 'customerToken' => 'fresh-token-77' );
	}

	public function create_customer( array $data ): array {
		$this->create_customer_calls++;
		return array( 'customerId' => 'fresh-customer-77' );
	}
}

final class YS_Customer_Identity_Test_Gateway extends YSGatewayBase {
	private string $method;
	private bool $subscription;

	public function __construct( string $gateway_id, string $method, $api = null, bool $subscription = false ) {
		$this->id     = $gateway_id;
		$this->method = $method;
		$this->api    = $api;
		$this->testmode = true;
		$this->subscription = $subscription;
	}

	public function get_payment_method() { return $this->method; }
	public function expose_prepare_payment_data( $order, string $pay_session ): array { return $this->prepare_payment_data( $order, $pay_session ); }
	public function expose_get_customer_token( int $user_id ) { return $this->get_customer_token( $user_id ); }
	protected function order_contains_subscription( $order ) { return $this->subscription; }
	protected function build_personal_info( $order, $type = 'billing' ) { return array( 'firstName' => 'Test' ); }
	protected function build_address( $order, $type = 'billing' ) { return array( 'city' => 'Taipei' ); }
	protected function build_products( $order ) { return array( array( 'name' => 'Product', 'amount' => array( 'value' => 10000, 'currency' => 'TWD' ) ) ); }
	protected function get_client_ip() { return '127.0.0.1'; }
	protected function build_client_info( $client_ip ) { return array( 'ip' => $client_ip ); }
	protected function get_return_url( $order = null ) { return 'https://example.test/return'; }
	protected function generate_reference_order_id( $order ) { return '9501_1'; }
	protected function get_shopline_language() { return 'zh-TW'; }
}

function ys_customer_identity_prepare( string $gateway_id, string $method, string $mode = 'new' ): array {
	$_POST['ys_shopline_payment_instrument_mode'] = $mode;
	$data = ( new YS_Customer_Identity_Test_Gateway( $gateway_id, $method ) )
		->expose_prepare_payment_data( new YS_Customer_Identity_Test_Order(), 'session-identity' );
	unset( $_POST['ys_shopline_payment_instrument_mode'] );
	return $data;
}

function ys_run_customer_identity_contract(): void {
	echo "== Customer identity: gateway scope and stale mapping recovery ==\n";
	$GLOBALS['ys_test_user_meta'][77] = array( YSOrderMeta::CUSTOMER_ID => 'customer-77' );
	$fixture_instrument_id = 'instrument-old';
	WC_Payment_Tokens::$customer_tokens[77]['ys_shopline_credit'] = array( (object) array( 'token' => $fixture_instrument_id ) );

	foreach ( array(
		'ys_shopline_linepay'  => 'LinePay',
		'ys_shopline_applepay' => 'ApplePay',
		'ys_shopline_jkopay'   => 'JKOPay',
		'ys_shopline_atm'      => 'VirtualAccount',
		'ys_shopline_bnpl'     => 'ChaileaseBNPL',
	) as $gateway_id => $method ) {
		foreach ( array( 'new', 'new_save', 'saved', '' ) as $mode ) {
			$data  = ys_customer_identity_prepare( $gateway_id, $method, $mode );
			$label = '' === $mode ? 'missing' : $mode;
			YS_Assert::eq( "{$method} {$label} mode remains Regular", 'Regular', $data['confirm']['paymentBehavior'] ?? '' );
			YS_Assert::eq( "{$method} {$label} mode omits paymentCustomerId", false, array_key_exists( 'paymentCustomerId', $data['confirm'] ) );
		}
	}

	$GLOBALS['ys_test_users'][77] = (object) array(
		'user_email'   => 'member@example.test',
		'display_name' => 'Member 77',
		'user_login'   => 'member77',
	);
	$GLOBALS['ys_test_user_meta'][77] = array(
		'billing_phone'   => '0912345678',
		'billing_country' => 'TW',
	);
	$wallet_prepare_api = new YS_Customer_Identity_Test_Api();
	$_POST['ys_shopline_payment_instrument_mode'] = 'saved';
	$wallet_without_customer = ( new YS_Customer_Identity_Test_Gateway(
		'ys_shopline_linepay',
		'LinePay',
		$wallet_prepare_api
	) )->expose_prepare_payment_data( new YS_Customer_Identity_Test_Order(), 'session-no-customer' );
	unset( $_POST['ys_shopline_payment_instrument_mode'] );
	YS_Assert::eq( 'wallet payload without mapping remains Regular', 'Regular', $wallet_without_customer['confirm']['paymentBehavior'] ?? '' );
	YS_Assert::eq( 'wallet prepare never creates a card customer identity', 0, $wallet_prepare_api->create_customer_calls );
	YS_Assert::eq( 'wallet prepare leaves customer mapping absent', '', get_user_meta( 77, YSOrderMeta::CUSTOMER_ID, true ) );

	$GLOBALS['ys_test_filters']['ys_shopline_payment_data'] = array(
		static function ( array $data ): array {
			$data['confirm']['paymentBehavior']   = 'QuickPayment';
			$data['confirm']['paymentCustomerId'] = 'filter-customer';
			$data['confirm']['paymentInstrument'] = array( 'paymentInstrumentId' => 'filter-instrument' );
			$data['filter_extension_kept']        = true;
			return $data;
		},
	);
	$filtered_wallet = ys_customer_identity_prepare( 'ys_shopline_linepay', 'LinePay', 'saved' );
	$GLOBALS['ys_test_filters']['ys_shopline_payment_data'] = array();
	YS_Assert::eq( 'wallet final filter boundary restores Regular', 'Regular', $filtered_wallet['confirm']['paymentBehavior'] ?? '' );
	YS_Assert::eq( 'wallet final filter boundary strips paymentCustomerId', false, array_key_exists( 'paymentCustomerId', $filtered_wallet['confirm'] ?? array() ) );
	YS_Assert::eq( 'wallet final filter boundary strips paymentInstrument', false, array_key_exists( 'paymentInstrument', $filtered_wallet['confirm'] ?? array() ) );
	YS_Assert::eq( 'wallet final filter boundary keeps unrelated extensions', true, $filtered_wallet['filter_extension_kept'] ?? false );

	$GLOBALS['ys_test_user_meta'][77] = array( YSOrderMeta::CUSTOMER_ID => 'customer-77' );

	$credit = ys_customer_identity_prepare( 'ys_shopline_credit', 'CreditCard' );
	YS_Assert::eq( 'credit customer-bound Regular keeps paymentCustomerId compatibility', 'customer-77', $credit['confirm']['paymentCustomerId'] ?? '' );
	$_POST['ys_shopline_payment_instrument_mode'] = 'new_save';
	$wallet_subscription = ( new YS_Customer_Identity_Test_Gateway( 'ys_shopline_linepay', 'LinePay', null, true ) )
		->expose_prepare_payment_data( new YS_Customer_Identity_Test_Order(), 'session-subscription-wallet' );
	unset( $_POST['ys_shopline_payment_instrument_mode'] );
	YS_Assert::eq( 'unsupported wallet subscription cannot activate card binding', 'Regular', $wallet_subscription['confirm']['paymentBehavior'] ?? '' );
	YS_Assert::eq( 'unsupported wallet subscription omits paymentCustomerId', false, array_key_exists( 'paymentCustomerId', $wallet_subscription['confirm'] ) );

	$GLOBALS['ys_test_user_id'] = 77;
	foreach ( array(
		'ys_shopline_linepay'  => 'LinePay',
		'ys_shopline_applepay' => 'ApplePay',
		'ys_shopline_jkopay'   => 'JKOPay',
		'ys_shopline_atm'      => 'VirtualAccount',
		'ys_shopline_bnpl'     => 'ChaileaseBNPL',
	) as $gateway_id => $method ) {
		$wallet_api    = new YS_Customer_Identity_Test_Api();
		$wallet_config = ( new YS_Customer_Identity_Test_Gateway( $gateway_id, $method, $wallet_api ) )->get_sdk_config();
		YS_Assert::eq( "{$method} SDK config omits customerToken", false, array_key_exists( 'customerToken', $wallet_config ) );
		YS_Assert::eq( "{$method} SDK config never queries customer token", array(), $wallet_api->token_calls );
	}
	$GLOBALS['ys_test_user_id'] = 0;

	$GLOBALS['ys_test_user_meta'][77] = array(
		YSOrderMeta::CUSTOMER_ID       => 'stale-customer-77',
		YSOrderMeta::INSTRUMENTS_CACHE => array( 'cached' => true ),
		'billing_phone'                 => '0912345678',
		'billing_country'               => 'TW',
	);
	$api = new YS_Customer_Identity_Test_Api();
	$gateway = new YS_Customer_Identity_Test_Gateway( 'ys_shopline_credit', 'CreditCard', $api );
	$token = $gateway->expose_get_customer_token( 77 );
	YS_Assert::eq( 'stale customer token path recreates once', 1, $api->create_customer_calls );
	YS_Assert::eq( 'stale customer token path retries old then fresh ID', array( 'stale-customer-77', 'fresh-customer-77' ), $api->token_calls );
	YS_Assert::eq( 'stale customer token path returns fresh token', 'fresh-token-77', $token );
	YS_Assert::eq( 'stale customer token path stores fresh mapping', 'fresh-customer-77', get_user_meta( 77, YSOrderMeta::CUSTOMER_ID, true ) );
	YS_Assert::eq( 'stale customer token path clears instruments cache', '', get_user_meta( 77, YSOrderMeta::INSTRUMENTS_CACHE, true ) );

	$GLOBALS['ys_test_user_meta'][77][ YSOrderMeta::CUSTOMER_ID ] = 'stale-customer-77';
	$GLOBALS['ys_test_user_meta'][77][ YSOrderMeta::INSTRUMENTS_CACHE ] = array( 'cached' => true );
	$generic_api = new YS_Customer_Identity_Test_Api();
	$generic_api->generic_1005 = true;
	$generic = new YS_Customer_Identity_Test_Gateway( 'ys_shopline_credit', 'CreditCard', $generic_api );
	YS_Assert::eq( 'generic 1005 customer token remains unavailable', false, $generic->expose_get_customer_token( 77 ) );
	YS_Assert::eq( 'generic 1005 does not recreate customer', 0, $generic_api->create_customer_calls );
	YS_Assert::eq( 'generic 1005 keeps customer mapping', 'stale-customer-77', get_user_meta( 77, YSOrderMeta::CUSTOMER_ID, true ) );

	$GLOBALS['ys_test_user_meta'][77][ YSOrderMeta::CUSTOMER_ID ] = 'concurrently-rebuilt-77';
	YS_Assert::eq(
		'late stale response cannot delete concurrently rebuilt mapping',
		false,
		\YangSheep\ShoplinePayment\Customer\YSCustomer::invalidate_stale_identity( 77, 'stale-customer-77', 'late_response' )
	);
	YS_Assert::eq( 'concurrently rebuilt mapping remains intact', 'concurrently-rebuilt-77', get_user_meta( 77, YSOrderMeta::CUSTOMER_ID, true ) );
}
