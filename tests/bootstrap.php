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
	}
}

if ( ! class_exists( 'WC_Logger' ) ) {
	class WC_Logger {
		public function log( string $level, string $message, array $context = array() ): void {}
	}
}

if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = '' ): string {
		return $text;
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $name, $default = false ) {
		return $default;
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
$GLOBALS['ys_test_notices'] = array();

if ( ! function_exists( 'wc_get_order' ) ) {
	function wc_get_order( $order_id ) {
		return $GLOBALS['ys_test_order'];
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		return $value;
	}
}

if ( ! function_exists( 'wc_add_notice' ) ) {
	function wc_add_notice( string $message, string $type = 'success' ): void {
		$GLOBALS['ys_test_notices'][] = array( $type, $message );
	}
}

if ( ! class_exists( 'YS_Test_Cart' ) ) {
	final class YS_Test_Cart {
		public int $empty_calls = 0;

		public function empty_cart(): void {
			$this->empty_calls++;
		}
	}
}

if ( ! function_exists( 'WC' ) ) {
	function WC() {
		static $wc = null;
		if ( null === $wc ) {
			$wc = (object) array( 'cart' => new YS_Test_Cart() );
		}
		return $wc;
	}
}

$ys_src = dirname( __DIR__ ) . '/src';
require_once $ys_src . '/Utils/YSTradeStatus.php';
require_once $ys_src . '/Utils/YSApiError.php';
require_once $ys_src . '/Utils/YSLogger.php';
require_once $ys_src . '/Utils/YSOrderMeta.php';
require_once $ys_src . '/DTOs/YSSessionDTO.php';
require_once $ys_src . '/Gateways/YSGatewayBase.php';
require_once $ys_src . '/Gateways/YSCreditSubscription.php';

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
