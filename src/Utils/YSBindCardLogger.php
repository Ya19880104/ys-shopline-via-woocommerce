<?php
/**
 * BindCard Logger Utility
 *
 * 獨立於 YSLogger（debug log）的綁卡專用日誌。
 * 管理員可在進階設定啟用，每日一個檔案，30 天自動清理。
 *
 * @package YangSheep\ShoplinePayment\Utils
 */

declare(strict_types=1);

namespace YangSheep\ShoplinePayment\Utils;

defined( 'ABSPATH' ) || exit;

/**
 * 綁卡專用日誌記錄工具類別
 */
final class YSBindCardLogger {

	/** @var string Option key 控制是否啟用 */
	public const OPTION_ENABLED = 'ys_shopline_bindcard_log_enabled';

	/** @var int 保存天數 */
	public const RETENTION_DAYS = 30;

	/** @var string 事件類型常數 */
	public const EVENT_SDK_INIT     = 'SDK_INIT';
	public const EVENT_API_REQUEST  = 'API_REQUEST';
	public const EVENT_API_RESPONSE = 'API_RESPONSE';
	public const EVENT_WEBHOOK      = 'WEBHOOK';
	public const EVENT_TOKEN_SAVED  = 'TOKEN_SAVED';
	public const EVENT_ERROR        = 'ERROR';

	/**
	 * 記錄綁卡事件（啟用時才實際寫檔）
	 *
	 * @param string $event_type 事件類型
	 * @param array  $context    附加資訊
	 * @return void
	 */
	public static function log( string $event_type, array $context = array() ): void {
		if ( ! self::is_enabled() ) {
			return;
		}

		$log_dir = self::get_log_dir();
		if ( ! $log_dir ) {
			return;
		}

		$file_path = $log_dir . '/bindcard-' . gmdate( 'Y-m-d' ) . '.log';

		$user_id    = get_current_user_id();
		$user_email = '';
		if ( $user_id ) {
			$user       = get_userdata( $user_id );
			$user_email = $user ? $user->user_email : '';
		}

		$entry = array(
			'timestamp'  => gmdate( 'Y-m-d H:i:s' ) . ' UTC',
			'event'      => $event_type,
			'user_id'    => $user_id,
			'user_email' => $user_email,
			'context'    => self::sanitize_context( $context ),
		);

		$line = wp_json_encode( $entry, JSON_UNESCAPED_UNICODE ) . PHP_EOL;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		@file_put_contents( $file_path, $line, FILE_APPEND | LOCK_EX );
	}

	/**
	 * 遮罩敏感欄位（完整卡號、CVV、sign key 等）
	 *
	 * @param array $context
	 * @return array
	 */
	private static function sanitize_context( array $context ): array {
		$sensitive_keys = array( 'cvv', 'cvc', 'card_number', 'cardNumber', 'signKey', 'sign_key', 'apiKey', 'api_key', 'clientKey' );

		$sanitize = function ( $value, $key ) use ( &$sanitize, $sensitive_keys ) {
			if ( is_array( $value ) ) {
				$result = array();
				foreach ( $value as $k => $v ) {
					$result[ $k ] = $sanitize( $v, $k );
				}
				return $result;
			}

			if ( is_string( $key ) && in_array( $key, $sensitive_keys, true ) ) {
				return is_string( $value ) && strlen( $value ) > 4
					? '***' . substr( $value, -4 )
					: '***';
			}

			return $value;
		};

		return $sanitize( $context, '' );
	}

	/**
	 * 是否啟用綁卡日誌
	 */
	public static function is_enabled(): bool {
		return 'yes' === get_option( self::OPTION_ENABLED, 'no' );
	}

	/**
	 * 取得日誌目錄（自動建立 + .htaccess 保護）
	 *
	 * @return string|null
	 */
	public static function get_log_dir(): ?string {
		$upload_dir = wp_upload_dir();
		if ( empty( $upload_dir['basedir'] ) ) {
			return null;
		}

		$log_dir = $upload_dir['basedir'] . '/ys-shopline-bindcard-logs';

		if ( ! is_dir( $log_dir ) ) {
			if ( ! wp_mkdir_p( $log_dir ) ) {
				return null;
			}
		}

		// .htaccess 禁止直接存取
		$htaccess = $log_dir . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			@file_put_contents( $htaccess, "Order Deny,Allow\nDeny from all\n" );
		}

		// index.php 防目錄瀏覽
		$index = $log_dir . '/index.php';
		if ( ! file_exists( $index ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			@file_put_contents( $index, "<?php\n// Silence is golden.\n" );
		}

		return $log_dir;
	}

	/**
	 * 列出所有現有日誌檔案（依日期降序）
	 *
	 * @return array<string, string> 格式：[ 'YYYY-MM-DD' => 'full/path', ... ]
	 */
	public static function list_log_files(): array {
		$log_dir = self::get_log_dir();
		if ( ! $log_dir ) {
			return array();
		}

		$files = glob( $log_dir . '/bindcard-*.log' );
		if ( ! $files ) {
			return array();
		}

		$result = array();
		foreach ( $files as $file ) {
			if ( preg_match( '/bindcard-(\d{4}-\d{2}-\d{2})\.log$/', $file, $matches ) ) {
				$result[ $matches[1] ] = $file;
			}
		}

		krsort( $result );
		return $result;
	}

	/**
	 * 讀取指定日期的日誌檔案內容
	 *
	 * @param string $date YYYY-MM-DD
	 * @param int    $max_lines 最多讀取行數（預設 1000）
	 * @return string
	 */
	public static function read_log( string $date, int $max_lines = 1000 ): string {
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return '';
		}

		$log_dir = self::get_log_dir();
		if ( ! $log_dir ) {
			return '';
		}

		$file_path = $log_dir . '/bindcard-' . $date . '.log';
		if ( ! file_exists( $file_path ) ) {
			return '';
		}

		// 讀取最後 N 行（大檔案友善）
		$file  = new \SplFileObject( $file_path, 'r' );
		$file->seek( PHP_INT_MAX );
		$total = $file->key();

		$start = max( 0, $total - $max_lines );
		$lines = array();
		$file->seek( $start );
		while ( ! $file->eof() ) {
			$line = $file->current();
			if ( '' !== trim( $line ) ) {
				$lines[] = $line;
			}
			$file->next();
		}

		return implode( '', $lines );
	}

	/**
	 * 清理超過保留天數的舊檔案
	 *
	 * @return int 刪除的檔案數
	 */
	public static function cleanup_old_logs(): int {
		$log_dir = self::get_log_dir();
		if ( ! $log_dir ) {
			return 0;
		}

		$files = self::list_log_files();
		if ( ! $files ) {
			return 0;
		}

		$cutoff_timestamp = strtotime( '-' . self::RETENTION_DAYS . ' days' );
		$deleted          = 0;

		foreach ( $files as $date => $path ) {
			$file_timestamp = strtotime( $date );
			if ( false !== $file_timestamp && $file_timestamp < $cutoff_timestamp ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				if ( @unlink( $path ) ) {
					$deleted++;
				}
			}
		}

		return $deleted;
	}

	/**
	 * 取得格式化後的日誌內容（給管理介面顯示用）
	 *
	 * @param string $date
	 * @return array<int, array>
	 */
	public static function get_log_entries( string $date ): array {
		$content = self::read_log( $date );
		if ( '' === $content ) {
			return array();
		}

		$entries = array();
		foreach ( explode( "\n", $content ) as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$decoded = json_decode( $line, true );
			if ( is_array( $decoded ) ) {
				$entries[] = $decoded;
			}
		}

		return array_reverse( $entries ); // 最新在上
	}
}
