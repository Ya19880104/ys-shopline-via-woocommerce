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
     * v3.5.1: 敏感欄位清單（集中遮罩，避免各處自己記得套用遮罩的漏洞）
     *
     * FULL_MASK：完全遮罩，只留末 6 碼（如卡片類識別碼）
     * HASH_MASK：用 SHA256 前 8 字做 fingerprint（paySession 類敏感載荷）
     * STARS_MASK：固定 6 星號不洩漏長度（token/key 類）
     */
    private const REDACT_LAST6 = [
        'instrument_id', 'payment_instrument_id', 'paymentInstrumentId',
        'customer_id', 'paymentCustomerId', 'customerId', 'referenceCustomerId',
        'trade_order_id', 'tradeOrderId',
        'channelDealId',
    ];

    private const REDACT_HASH = [
        'paySession', 'pay_session', 'pay_session_raw',
        'raw_card_info', 'raw_instrument', 'instrumentCard',
    ];

    private const REDACT_FULL = [
        'customerToken', 'payToken', 'apiKey', 'clientKey', 'signKey', 'api_key', 'sign_key',
        'cardNumber', 'card_number', 'cvv', 'cvc', 'pan',
        'bin', 'cardBin', 'card_bin', 'issuerBin', 'issuer_bin',
        'iin', 'cardIin', 'card_iin',
        'password', 'secret',
    ];

    /**
     * 記錄日誌
     *
     * v3.5.1: 集中 sanitize — 任何傳入的 context 都會遞迴過 redaction，
     * 固定欄位遮罩不再依賴各呼叫端自己記得加 mask。
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

        // v3.5.1 集中遮罩：呼叫端仍可自行遮罩，但這裡保底
        if ( ! empty( $context ) ) {
            $context = self::redact( $context );
            $message .= ' | Context: ' . wp_json_encode( $context, JSON_UNESCAPED_UNICODE );
        }

        self::$logger->log( $level, $message, [ 'source' => self::SOURCE ] );
    }

    /**
     * v3.5.1: 遞迴 sanitize context，對已知敏感 key 做遮罩。
     * 已經被前端遮罩過（值以 `*` 開頭或含 `[REDACTED]`）的保留原值，避免重複遮罩。
     *
     * @param mixed $value
     * @return mixed
     */
    private static function redact( $value ) {
        if ( is_array( $value ) ) {
            $out = [];
            foreach ( $value as $k => $v ) {
                $key_str = (string) $k;
                // 已經遮罩的值直接保留（例如呼叫端傳入 '*515066' 或 '[REDACTED]'）
                if ( is_string( $v ) && ( strpos( $v, '[REDACTED]' ) !== false || ( strlen( $v ) <= 10 && strpos( $v, '*' ) === 0 ) ) ) {
                    $out[ $k ] = $v;
                    continue;
                }
                if ( self::key_matches( $key_str, self::REDACT_FULL ) ) {
                    $out[ $k ] = '[REDACTED]';
                } elseif ( self::key_matches( $key_str, self::REDACT_HASH ) ) {
                    $out[ $k ] = is_string( $v ) ? '#' . substr( hash( 'sha256', $v ), 0, 8 ) : '[REDACTED_NON_STRING]';
                } elseif ( self::key_matches( $key_str, self::REDACT_LAST6 ) ) {
                    $out[ $k ] = is_string( $v ) && strlen( $v ) > 6 ? '*' . substr( $v, -6 ) : ( is_string( $v ) ? '***' : '[REDACTED_NON_STRING]' );
                } else {
                    $out[ $k ] = self::redact( $v );
                }
            }
            return $out;
        }
        return $value;
    }

    /**
     * Case-insensitive key matching helper
     *
     * @param string $key
     * @param array  $list
     * @return bool
     */
    private static function key_matches( string $key, array $list ): bool {
        $lower = strtolower( $key );
        foreach ( $list as $pattern ) {
            if ( strtolower( $pattern ) === $lower ) {
                return true;
            }
        }
        return false;
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
