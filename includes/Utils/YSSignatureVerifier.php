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
 */
final class YSSignatureVerifier {

    /** @var int 時間戳容許誤差（毫秒） - 5 分鐘 */
    private const TIMESTAMP_TOLERANCE = 300000;

    /**
     * 驗證 Webhook 請求
     *
     * @param \WP_REST_Request $request 請求物件
     * @return bool
     * @throws \Exception 如果驗證失敗
     */
    public static function verify( \WP_REST_Request $request ): bool {
        // 本地開發環境跳過驗證
        if ( self::is_local_environment() ) {
            return true;
        }

        // 驗證時間戳
        self::verify_timestamp( $request );

        // 驗證 API 版本
        self::verify_api_version( $request );

        // 驗證簽章
        return self::verify_signature( $request );
    }

    /**
     * 驗證時間戳
     *
     * @param \WP_REST_Request $request 請求物件
     * @return void
     * @throws \Exception 如果時間戳無效
     */
    private static function verify_timestamp( \WP_REST_Request $request ): void {
        $timestamp    = $request->get_header( 'timestamp' );
        $current_time = time() * 1000;
        $diff_time    = abs( $current_time - (int) $timestamp );

        if ( $diff_time > self::TIMESTAMP_TOLERANCE ) {
            throw new \Exception(
                sprintf(
                    '時間戳過期，目前：%d，收到：%s，差異：%d ms',
                    $current_time,
                    $timestamp,
                    $diff_time
                )
            );
        }
    }

    /**
     * 驗證 API 版本
     *
     * @param \WP_REST_Request $request 請求物件
     * @return void
     */
    private static function verify_api_version( \WP_REST_Request $request ): void {
        $api_version = $request->get_header( 'apiVersion' );

        if ( 'V1' !== $api_version ) {
            YSLogger::warning(
                sprintf( 'Webhook API 版本與預期 V1 不符，收到：%s', $api_version )
            );
        }
    }

    /**
     * 驗證 HMAC-SHA256 簽章
     *
     * @param \WP_REST_Request $request 請求物件
     * @return bool
     * @throws \Exception 如果簽章驗證失敗
     */
    private static function verify_signature( \WP_REST_Request $request ): bool {
        $timestamp = $request->get_header( 'timestamp' );
        $sign      = $request->get_header( 'sign' );
        $body      = $request->get_body();

        // 組合要簽名的字串
        $payload = "{$timestamp}.{$body}";

        // 計算簽章
        $calculated_signature = self::generate_signature( $payload );

        // 比對簽章
        $is_verified = hash_equals( $sign, $calculated_signature );

        if ( ! $is_verified ) {
            throw new \Exception(
                sprintf(
                    '簽章驗證失敗，計算值：%s，收到值：%s',
                    $calculated_signature,
                    $sign
                )
            );
        }

        return true;
    }

    /**
     * 產生 HMAC-SHA256 簽章
     *
     * @param string $payload 要簽名的字串
     * @return string
     */
    public static function generate_signature( string $payload ): string {
        // 確保資料是 UTF-8 編碼
        $payload = mb_convert_encoding( $payload, 'UTF-8', 'auto' );

        // 取得簽章金鑰
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

        if ( $is_test_mode ) {
            return get_option( 'ys_shopline_sandbox_sign_key', '' );
        }

        return get_option( 'ys_shopline_sign_key', '' );
    }

    /**
     * 是否為本地開發環境
     *
     * @return bool
     */
    private static function is_local_environment(): bool {
        $site_url = get_site_url();

        // 檢查是否為本地開發環境
        $local_patterns = [
            'localhost',
            '127.0.0.1',
            '.local',
            '.test',
            '.dev',
        ];

        foreach ( $local_patterns as $pattern ) {
            if ( false !== strpos( $site_url, $pattern ) ) {
                return true;
            }
        }

        return false;
    }
}
