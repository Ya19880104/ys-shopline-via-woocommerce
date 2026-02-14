<?php
/**
 * Signature Verifier Utility
 *
 * @package YangSheep\ShoplinePayment\Utils
 */

declare(strict_types=1);

namespace YangSheep\ShoplinePayment\Utils;

defined( 'ABSPATH' ) || exit;

/**
 * Webhook 簽章驗證工具類別
 *
 * 支援兩種驗證方式：
 * 1. sign + timestamp header（HMAC-SHA256）
 * 2. WP_REST_Request 物件
 */
final class YSSignatureVerifier {

    /** @var int 時間戳容許誤差（毫秒） - 5 分鐘 */
    private const TIMESTAMP_TOLERANCE = 300000;

    /**
     * 驗證 Webhook 簽章（使用原始字串）
     *
     * @param string $body      請求內容
     * @param string $sign      簽章值（sign header）
     * @param string $timestamp 時間戳（timestamp header）
     * @return bool
     */
    public static function verify_raw( string $body, string $sign, string $timestamp ): bool {
        $sign_key = self::get_sign_key();

        if ( empty( $sign_key ) ) {
            YSLogger::error( 'Webhook 簽章金鑰未設定，拒絕請求' );
            return false;
        }

        if ( empty( $sign ) || empty( $timestamp ) ) {
            YSLogger::error( 'Webhook 缺少簽章 headers', [
                'has_sign'      => ! empty( $sign ),
                'has_timestamp' => ! empty( $timestamp ),
            ] );
            return false;
        }

        // 檢查時間戳新鮮度
        $current_time = time() * 1000;
        $diff         = abs( $current_time - (int) $timestamp );

        if ( $diff > self::TIMESTAMP_TOLERANCE ) {
            YSLogger::error( 'Webhook 時間戳過期', [
                'timestamp'    => $timestamp,
                'current_time' => $current_time,
                'diff_ms'      => $diff,
            ] );
            return false;
        }

        // 計算並比對簽章
        $payload    = "{$timestamp}.{$body}";
        $calculated = hash_hmac( 'sha256', $payload, $sign_key );

        return hash_equals( $calculated, $sign );
    }

    /**
     * 驗證 REST API Webhook 請求
     *
     * @param \WP_REST_Request $request 請求物件
     * @return bool
     */
    public static function verify_request( \WP_REST_Request $request ): bool {
        $sign      = $request->get_header( 'sign' ) ?? '';
        $timestamp = $request->get_header( 'timestamp' ) ?? '';
        $body      = $request->get_body();

        return self::verify_raw( $body, $sign, $timestamp );
    }

    /**
     * 產生 HMAC-SHA256 簽章
     *
     * @param string $payload 要簽名的字串
     * @return string
     */
    public static function generate_signature( string $payload ): string {
        $sign_key = self::get_sign_key();
        return hash_hmac( 'sha256', $payload, $sign_key );
    }

    /**
     * 取得簽章金鑰
     *
     * @return string
     */
    private static function get_sign_key(): string {
        $is_test_mode = 'yes' === get_option( 'ys_shopline_testmode', 'yes' );

        return $is_test_mode
            ? get_option( 'ys_shopline_sandbox_sign_key', '' )
            : get_option( 'ys_shopline_sign_key', '' );
    }

}
