<?php
/**
 * SHOPLINE 契約測試 bootstrap（standalone，無 PHPUnit、無 WordPress）。
 *
 * 直接載入受測純類別（YSTradeStatus 無依賴；YSSessionDTO 僅依賴 YSTradeStatus），
 * 並提供極簡斷言器 YS_Assert。固定命令見 tests/run.php。
 *
 * @package YangSheep\ShoplinePayment\Tests
 */

declare(strict_types=1);

// 受測類別內含 `defined( 'ABSPATH' ) || exit;` 守衛，測試環境先行定義以放行載入。
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

if ( ! class_exists( 'WP_Error' ) ) {
	final class WP_Error {
		public function __construct(
			private string $code,
			private string $message = ''
		) {}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $value ): bool {
		return $value instanceof WP_Error;
	}
}

if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
	class WC_Payment_Gateway {
		/** @var string */
		public $id = '';

		/** 設定值 stub：測試子類以 $test_options 供值。 */
		public array $test_options = array();

		public function get_option( string $key, $default = '' ) {
			return $this->test_options[ $key ] ?? $default;
		}
	}
}

if ( ! class_exists( 'WC_Order' ) ) {
	// 型別 stub：YSStatusManager::handle_order_status_change 的 \WC_Order type hint 用。
	class WC_Order {}
}

if ( ! class_exists( 'WC_Logger' ) ) {
	class WC_Logger {
		public function log( string $level, string $message, array $context = array() ): void {}
	}
}

if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public bool $lock_available;
		public array $queries = array();

		public function __construct( bool $lock_available = true ) {
			$this->lock_available = $lock_available;
		}

		public function prepare( string $query, ...$args ): string {
			return $query . ' | ' . implode( ' | ', array_map( 'strval', $args ) );
		}

		public function get_var( string $query ) {
			$this->queries[] = $query;
			if ( str_contains( $query, 'GET_LOCK' ) ) {
				return $this->lock_available ? '1' : '0';
			}
			return '1';
		}
	}
}

$GLOBALS['wpdb'] = null;

if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = '' ): string {
		return $text;
	}
}

if ( ! function_exists( 'esc_attr__' ) ) {
	function esc_attr__( string $text, string $domain = '' ): string {
		return $text;
	}
}

$GLOBALS['ys_test_options'] = array();
if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $name, $default = false ) {
		return array_key_exists( $name, $GLOBALS['ys_test_options'] )
			? $GLOBALS['ys_test_options'][ $name ]
			: $default;
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value, int $flags = 0 ): string {
		return (string) json_encode( $value, $flags );
	}
}

if ( ! function_exists( 'esc_sql' ) ) {
	function esc_sql( string $value ): string {
		return addslashes( $value );
	}
}

if ( ! function_exists( 'wc_get_logger' ) ) {
	function wc_get_logger(): WC_Logger {
		return new WC_Logger();
	}
}

$GLOBALS['ys_test_order'] = null;
$GLOBALS['ys_test_orders'] = array();
$GLOBALS['ys_test_notices'] = array();
$GLOBALS['ys_test_actions'] = array();
$GLOBALS['ys_test_query_vars'] = array();

if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): void {
		$GLOBALS['ys_test_actions'][] = compact( 'hook', 'callback', 'priority', 'accepted_args' );
	}
}

if ( ! function_exists( 'get_query_var' ) ) {
	function get_query_var( string $name, $default = '' ) {
		return $GLOBALS['ys_test_query_vars'][ $name ] ?? $default;
	}
}

if ( ! function_exists( 'absint' ) ) {
	function absint( $value ): int {
		return abs( (int) $value );
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $value ): string {
		return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_html_e' ) ) {
	function esc_html_e( string $text, string $domain = '' ): void {
		echo esc_html( $text );
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $value ): string {
		return (string) $value;
	}
}

if ( ! function_exists( 'wc_get_account_endpoint_url' ) ) {
	function wc_get_account_endpoint_url( string $endpoint ): string {
		return '/my-account/' . $endpoint . '/';
	}
}

if ( ! function_exists( 'wc_get_order' ) ) {
	function wc_get_order( $order_id ) {
		return $GLOBALS['ys_test_order'];
	}
}

if ( ! function_exists( 'wc_get_orders' ) ) {
	function wc_get_orders( array $args = array() ): array {
		return $GLOBALS['ys_test_orders'];
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		return $value;
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $value ): string {
		return trim( (string) $value );
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $value ): string {
		return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $value ) );
	}
}

if ( ! function_exists( 'wc_add_notice' ) ) {
	function wc_add_notice( string $message, string $type = 'success' ): void {
		$GLOBALS['ys_test_notices'][] = array( $type, $message );
	}
}

$GLOBALS['ys_test_stock_calls'] = array(
	'direct'   => 0,
	'maybe'    => 0,
	'increase' => 0,
);

if ( ! function_exists( 'wc_reduce_stock_levels' ) ) {
	function wc_reduce_stock_levels( $order_id ): void {
		$GLOBALS['ys_test_stock_calls']['direct']++;
	}
}

if ( ! function_exists( 'wc_maybe_reduce_stock_levels' ) ) {
	function wc_maybe_reduce_stock_levels( $order_id ): void {
		$GLOBALS['ys_test_stock_calls']['maybe']++;
	}
}

if ( ! function_exists( 'wc_maybe_increase_stock_levels' ) ) {
	function wc_maybe_increase_stock_levels( $order_id ): void {
		$GLOBALS['ys_test_stock_calls']['increase']++;
	}
}

// --- get_sdk_config 依賴 stubs（YSCreditInstallment SDK 契約用）---

$GLOBALS['ys_test_user_id'] = 0;

if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id(): int {
		return (int) $GLOBALS['ys_test_user_id'];
	}
}

if ( ! function_exists( 'is_add_payment_method_page' ) ) {
	function is_add_payment_method_page(): bool {
		return false;
	}
}

if ( ! function_exists( 'get_woocommerce_currency' ) ) {
	function get_woocommerce_currency(): string {
		return 'TWD';
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $tag, $value, ...$args ) {
		return $value;
	}
}

$GLOBALS['ys_test_registered_statuses'] = array();

if ( ! function_exists( 'register_post_status' ) ) {
	function register_post_status( string $status, array $args ): void {
		$GLOBALS['ys_test_registered_statuses'][ $status ] = $args;
	}
}

if ( ! function_exists( '_n_noop' ) ) {
	function _n_noop( string $singular, string $plural, string $domain = '' ): array {
		return compact( 'singular', 'plural', 'domain' );
	}
}

if ( ! class_exists( 'YSShoplinePayment' ) ) {
	final class YSShoplinePayment {
		public static $test_api = null;

		public static function get_api() {
			return self::$test_api;
		}

		public static function get_sdk_amount( $raw ): int {
			return (int) round( (float) $raw * 100 );
		}
		public static function get_formatted_amount( $raw, $currency = 'TWD' ): int {
			return (int) round( (float) $raw * 100 );
		}
	}
}

$GLOBALS['ys_test_scheduled_actions'] = array();
$GLOBALS['ys_test_is_renewal_order'] = false;

if ( ! function_exists( 'wcs_order_contains_renewal' ) ) {
	function wcs_order_contains_renewal( $order ): bool {
		return (bool) $GLOBALS['ys_test_is_renewal_order'];
	}
}

if ( ! function_exists( 'as_has_scheduled_action' ) ) {
	function as_has_scheduled_action( string $hook, array $args = array(), string $group = '' ) {
		foreach ( $GLOBALS['ys_test_scheduled_actions'] as $action ) {
			if ( $hook === $action['hook'] && $args === $action['args'] && $group === $action['group'] ) {
				return 1;
			}
		}
		return false;
	}
}

if ( ! function_exists( 'as_schedule_single_action' ) ) {
	function as_schedule_single_action( int $timestamp, string $hook, array $args = array(), string $group = '', bool $unique = false, int $priority = 10 ): int {
		$GLOBALS['ys_test_scheduled_actions'][] = compact( 'timestamp', 'hook', 'args', 'group', 'unique', 'priority' );
		return count( $GLOBALS['ys_test_scheduled_actions'] );
	}
}

if ( ! class_exists( 'YangSheep\ShoplinePayment\Api\YSShoplineRequester' ) ) {
	final class YS_Test_Shopline_Requester {
		public static function redact_sensitive( $value ) {
			return $value;
		}
	}
	class_alias( YS_Test_Shopline_Requester::class, 'YangSheep\ShoplinePayment\Api\YSShoplineRequester' );
}

if ( ! class_exists( 'YS_Test_Cart' ) ) {
	final class YS_Test_Cart {
		public int $empty_calls = 0;
		/** @var float 供 get_sdk_config 金額來源 stub */
		public float $total = 5000.0;

		public function empty_cart(): void {
			$this->empty_calls++;
		}

		public function get_total( string $context = 'view' ): float {
			return $this->total;
		}
	}
}

if ( ! class_exists( 'YS_Test_Mailer' ) ) {
	final class YS_Test_Mailer {
		public function wrap_message( string $heading, string $body ): string {
			return '<h1>' . $heading . '</h1><p>' . $body . '</p>';
		}
	}
}

if ( ! class_exists( 'YS_Test_WC' ) ) {
	final class YS_Test_WC {
		public YS_Test_Cart $cart;

		public function __construct() {
			$this->cart = new YS_Test_Cart();
		}

		public function mailer(): YS_Test_Mailer {
			return new YS_Test_Mailer();
		}
	}
}

$GLOBALS['ys_test_mails'] = array();

if ( ! function_exists( 'wc_mail' ) ) {
	function wc_mail( string $to, string $subject, string $message, string $headers = '' ): bool {
		$GLOBALS['ys_test_mails'][] = compact( 'to', 'subject', 'message', 'headers' );
		return true;
	}
}

if ( ! function_exists( 'WC' ) ) {
	function WC() {
		static $wc = null;
		if ( null === $wc ) {
			$wc = new YS_Test_WC();
		}
		return $wc;
	}
}

$ys_src = dirname( __DIR__ ) . '/src';
require_once $ys_src . '/Utils/YSTradeStatus.php';
require_once $ys_src . '/Utils/YSConfirmationPolicy.php';
require_once $ys_src . '/Utils/YSApiError.php';
require_once $ys_src . '/Utils/YSLogger.php';
require_once $ys_src . '/Utils/YSOrderMeta.php';
require_once $ys_src . '/DTOs/YSSessionDTO.php';
require_once $ys_src . '/Handlers/YSRedirectHandler.php';
require_once $ys_src . '/Gateways/YSGatewayBase.php';
require_once $ys_src . '/Gateways/YSCreditSubscription.php';
require_once $ys_src . '/Gateways/YSVirtualAccount.php';
require_once $ys_src . '/Gateways/YSCreditInstallment.php';
require_once $ys_src . '/Handlers/YSStatusManager.php';
require_once $ys_src . '/Handlers/YSPaymentConfirmation.php';
require_once $ys_src . '/Handlers/YSWebhookHandler.php';
require_once $ys_src . '/Admin/YSOrderPaymentAdmin.php';
require_once $ys_src . '/Frontend/YSOrderDisplay.php';

/**
 * 極簡斷言器：累計 pass/fail，逐筆輸出，最終以 exit code 回報。
 */
final class YS_Assert {

	/** @var int */
	public static $pass = 0;
	/** @var int */
	public static $fail = 0;
	/** @var string[] */
	public static $failures = array();

	/**
	 * 嚴格相等斷言（===）。
	 *
	 * @param string $name     案名。
	 * @param mixed  $expected 期望值。
	 * @param mixed  $actual   實際值。
	 * @return void
	 */
	public static function eq( string $name, $expected, $actual ): void {
		if ( $expected === $actual ) {
			self::$pass++;
			echo 'PASS | ' . $name . "\n";
			return;
		}
		self::$fail++;
		self::$failures[] = $name;
		echo 'FAIL | ' . $name . ' | expected=' . var_export( $expected, true ) . ' got=' . var_export( $actual, true ) . "\n";
	}

	/**
	 * 真值斷言。
	 *
	 * @param string $name 案名。
	 * @param mixed  $cond 條件。
	 * @return void
	 */
	public static function is_true( string $name, $cond ): void {
		self::eq( $name, true, (bool) $cond );
	}

	/**
	 * 期望拋出例外。
	 *
	 * @param string   $name 案名。
	 * @param callable $fn   受測封包。
	 * @return void
	 */
	public static function throws( string $name, callable $fn ): void {
		try {
			$fn();
		} catch ( \Throwable $e ) {
			self::$pass++;
			echo 'PASS | ' . $name . "\n";
			return;
		}
		self::$fail++;
		self::$failures[] = $name;
		echo 'FAIL | ' . $name . ' | expected exception, none thrown' . "\n";
	}

	/**
	 * 輸出彙總並以 exit code 結束（0＝全過、1＝有失敗）。
	 *
	 * @return void
	 */
	public static function summary_exit(): void {
		echo "----\n";
		echo 'RESULT: ' . self::$pass . ' PASS / ' . self::$fail . " FAIL\n";
		exit( self::$fail > 0 ? 1 : 0 );
	}
}
