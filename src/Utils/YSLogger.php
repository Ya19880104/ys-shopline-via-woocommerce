<?php
/**
 * Logger Utility
 *
 * @package YangSheep\ShoplinePayment\Utils
 */

declare(strict_types=1);

namespace YangSheep\ShoplinePayment\Utils;

defined( 'ABSPATH' ) || exit;

/**
 * 日誌記錄工具類別
 */
final class YSLogger {

    /** @var string 日誌來源識別 */
    private const SOURCE = 'ys-shopline-payment';

    /** @var \WC_Logger|null */
    private static ?\WC_Logger $logger = null;

    /**
     * 記錄日誌
     *
     * @param string $message 訊息
     * @param string $level   日誌等級 (debug, info, notice, warning, error, critical)
     * @param array  $context 附加資訊
     * @return void
     */
    public static function log( string $message, string $level = 'info', array $context = [] ): void {
        if ( is_null( self::$logger ) ) {
            self::$logger = wc_get_logger();
        }

        // 如果有附加資訊，加入訊息中
        if ( ! empty( $context ) ) {
            $message .= ' | Context: ' . wp_json_encode( $context, JSON_UNESCAPED_UNICODE );
        }

        self::$logger->log( $level, $message, [ 'source' => self::SOURCE ] );
    }

    /**
     * 記錄除錯訊息
     *
     * @param string $message 訊息
     * @param array  $context 附加資訊
     * @return void
     */
    public static function debug( string $message, array $context = [] ): void {
        if ( ! self::is_debug_enabled() ) {
            return;
        }
        self::log( $message, 'debug', $context );
    }

    /**
     * 記錄一般訊息
     *
     * @param string $message 訊息
     * @param array  $context 附加資訊
     * @return void
     */
    public static function info( string $message, array $context = [] ): void {
        self::log( $message, 'info', $context );
    }

    /**
     * 記錄警告訊息
     *
     * @param string $message 訊息
     * @param array  $context 附加資訊
     * @return void
     */
    public static function warning( string $message, array $context = [] ): void {
        self::log( $message, 'warning', $context );
    }

    /**
     * 記錄錯誤訊息
     *
     * @param string $message 訊息
     * @param array  $context 附加資訊
     * @return void
     */
    public static function error( string $message, array $context = [] ): void {
        self::log( $message, 'error', $context );
    }

    /**
     * 記錄通知訊息
     *
     * @param string $message 訊息
     * @param array  $context 附加資訊
     */
    public static function notice( string $message, array $context = [] ): void {
        self::log( $message, 'notice', $context );
    }

    /**
     * 記錄嚴重錯誤訊息
     *
     * @param string $message 訊息
     * @param array  $context 附加資訊
     */
    public static function critical( string $message, array $context = [] ): void {
        self::log( $message, 'critical', $context );
    }

    /**
     * 是否啟用除錯模式
     *
     * @return bool
     */
    public static function is_debug_enabled(): bool {
        return 'yes' === get_option( 'ys_shopline_debug', 'no' );
    }

    /**
     * 清除日誌檔案
     *
     * @return bool
     */
    public static function clear(): bool {
        if ( ! function_exists( 'wc_get_log_file_path' ) ) {
            return false;
        }

        $log_file = wc_get_log_file_path( self::SOURCE );

        if ( file_exists( $log_file ) ) {
            return unlink( $log_file );
        }

        return true;
    }

    /**
     * 取得日誌檔案路徑
     *
     * @return string|false
     */
    public static function get_log_file_path(): string|false {
        if ( ! function_exists( 'wc_get_log_file_path' ) ) {
            return false;
        }

        return wc_get_log_file_path( self::SOURCE );
    }
}
