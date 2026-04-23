<?php
/**
 * Shopline API Requester
 *
 * @package YangSheep\ShoplinePayment\Api
 */

declare(strict_types=1);

namespace YangSheep\ShoplinePayment\Api;

use YangSheep\ShoplinePayment\Utils\YSLogger;

defined( 'ABSPATH' ) || exit;

/**
 * HTTP 請求器類別
 *
 * 處理與 Shopline Payment API 的所有 HTTP 通訊
 */
final class YSShoplineRequester {

    /** @var string 正式環境 API URL */
    private const PRODUCTION_URL = 'https://api.shoplinepayments.com/api/v1';

    /** @var string 測試環境 API URL */
    private const SANDBOX_URL = 'https://api-sandbox.shoplinepayments.com/api/v1';

    /** @var int 請求超時時間（秒） */
    private const TIMEOUT = 45;

    /** @var bool 是否為測試模式 */
    private bool $is_test_mode;

    /** @var string Merchant ID */
    private string $merchant_id;

    /** @var string API Key */
    private string $api_key;

    /** @var string|null Platform ID（平台特店用） */
    private ?string $platform_id;

    /**
     * Constructor
     *
     * @param bool        $is_test_mode 是否為測試模式
     * @param string|null $merchant_id  Merchant ID（可選，預設從設定取得）
     * @param string|null $api_key      API Key（可選，預設從設定取得）
     */
    public function __construct(
        ?bool $is_test_mode = null,
        ?string $merchant_id = null,
        ?string $api_key = null
    ) {
        $this->is_test_mode = $is_test_mode ?? ( 'yes' === get_option( 'ys_shopline_testmode', 'yes' ) );

        if ( $this->is_test_mode ) {
            $this->merchant_id = $merchant_id ?? get_option( 'ys_shopline_sandbox_merchant_id', '' );
            $this->api_key     = $api_key ?? get_option( 'ys_shopline_sandbox_api_key', '' );
            $this->platform_id = get_option( 'ys_shopline_sandbox_platform_id', '' ) ?: null;
        } else {
            $this->merchant_id = $merchant_id ?? get_option( 'ys_shopline_merchant_id', '' );
            $this->api_key     = $api_key ?? get_option( 'ys_shopline_api_key', '' );
            $this->platform_id = get_option( 'ys_shopline_platform_id', '' ) ?: null;
        }
    }

    /**
     * 發送 POST 請求
     *
     * @param string               $endpoint       API 端點
     * @param array<string, mixed> $data           請求資料
     * @param string               $idempotent_key 冪等鍵（同一筆重送相同 key，API 只處理一次）
     * @return array<string, mixed>
     * @throws \Exception 如果請求失敗
     */
    public function post( string $endpoint, array $data = [], string $idempotent_key = '' ): array {
        return $this->request( $endpoint, $data, 'POST', $idempotent_key );
    }

    /**
     * 發送 GET 請求
     *
     * @param string               $endpoint API 端點
     * @param array<string, mixed> $data     請求資料
     * @return array<string, mixed>
     * @throws \Exception 如果請求失敗
     */
    public function get( string $endpoint, array $data = [] ): array {
        return $this->request( $endpoint, $data, 'GET' );
    }

    /**
     * 發送 HTTP 請求
     *
     * @param string               $endpoint API 端點
     * @param array<string, mixed> $data     請求資料
     * @param string               $method   HTTP 方法
     * @return array<string, mixed>
     * @throws \Exception 如果請求失敗
     */
    private function request( string $endpoint, array $data, string $method, string $idempotent_key = '' ): array {
        $url        = $this->get_api_url() . $endpoint;
        $request_id = $this->generate_request_id();
        $headers    = $this->build_headers( $request_id, $idempotent_key );

        $args = [
            'method'  => $method,
            'headers' => $headers,
            'timeout' => self::TIMEOUT,
        ];

        if ( ! empty( $data ) ) {
            if ( 'GET' === $method ) {
                $url = add_query_arg( $data, $url );
            } else {
                $args['body'] = wp_json_encode( $data );
            }
        }

        YSLogger::debug( "API 請求: {$method} {$url}", [
            'request_id' => $request_id,
            'data'       => self::redact_sensitive( $data ),
        ] );

        $response = wp_remote_request( $url, $args );

        return $this->handle_response( $response, $request_id );
    }

    /**
     * 遮罩敏感欄位（用於 log）
     *
     * 遮罩規則：
     * - PII（personalInfo.email/phone、address.street）→ 部分顯示 + 星號
     * - Token 欄位（customerToken, payToken, apiKey 等）→ 前後綴 + 星號
     * - 其他結構保留供除錯
     *
     * @param mixed $data
     * @return mixed
     */
    public static function redact_sensitive( $data ) {
        if ( ! is_array( $data ) ) {
            return $data;
        }

        $sensitive_full = [ 'apiKey', 'signKey', 'clientKey', 'customerToken', 'payToken', 'cvv', 'cardNumber' ];
        $sensitive_pii  = [ 'email', 'phone', 'phoneNumber', 'street', 'street2', 'street3' ];

        $redact_str = static function ( $val ) {
            if ( ! is_string( $val ) || '' === $val ) {
                return $val;
            }
            $len = strlen( $val );
            if ( $len <= 4 ) {
                return str_repeat( '*', $len );
            }
            return substr( $val, 0, 2 ) . str_repeat( '*', max( 4, $len - 4 ) ) . substr( $val, -2 );
        };

        array_walk_recursive( $data, static function ( &$value, $key ) use ( $sensitive_full, $sensitive_pii, $redact_str ) {
            if ( in_array( $key, $sensitive_full, true ) ) {
                $value = is_string( $value ) && '' !== $value ? '[REDACTED:' . strlen( $value ) . ']' : $value;
                return;
            }
            if ( in_array( $key, $sensitive_pii, true ) ) {
                $value = $redact_str( $value );
            }
        } );

        return $data;
    }

    /**
     * 處理 HTTP 回應
     *
     * @param array|\WP_Error $response   HTTP 回應
     * @param string          $request_id 請求 ID
     * @return array<string, mixed>
     * @throws \Exception 如果請求失敗
     */
    private function handle_response( $response, string $request_id ): array {
        if ( is_wp_error( $response ) ) {
            $error_message = $response->get_error_message();
            YSLogger::error( "API 請求錯誤: {$error_message}", [ 'request_id' => $request_id ] );
            throw new YSApiException( 'http_request_failed', "API 請求失敗: {$error_message}" );
        }

        $body = wp_remote_retrieve_body( $response );
        $code = wp_remote_retrieve_response_code( $response );

        // 回應 body 可能含 customerToken / payToken / PII，解碼後遮罩再 log
        $log_body = $body;
        $decoded_preview = json_decode( $body, true );
        if ( is_array( $decoded_preview ) ) {
            $log_body = wp_json_encode( self::redact_sensitive( $decoded_preview ) );
        }
        YSLogger::debug( "API 回應 ({$code})", [
            'request_id' => $request_id,
            'body'       => $log_body,
        ] );

        // 空字串 body → empty_response（不進 json_decode 以保留錯誤碼契約）
        if ( '' === $body ) {
            YSLogger::error( 'API 回應為空', [
                'request_id' => $request_id,
                'http_code'  => $code,
            ] );
            throw new YSApiException( 'empty_response', 'API 回應為空，請稍後重試。' );
        }

        $decoded_body = json_decode( $body, true );

        if ( json_last_error() !== JSON_ERROR_NONE ) {
            throw new YSApiException( 'json_decode_error', 'API 回應格式異常，請稍後重試。' );
        }

        // 防護：確保解析結果為陣列
        if ( ! is_array( $decoded_body ) || empty( $decoded_body ) ) {
            YSLogger::error( 'API 回應為空或非陣列', [
                'request_id' => $request_id,
                'http_code'  => $code,
            ] );
            throw new YSApiException( 'empty_response', 'API 回應為空，請稍後重試。' );
        }

        // 處理 HTTP 錯誤狀態碼
        if ( $code >= 400 ) {
            $has_trade_info = isset( $decoded_body['tradeOrderId'] ) || isset( $decoded_body['nextAction'] );

            // 400 但有 tradeOrderId 或 nextAction → 部分成功（3DS 等流程需要繼續）
            if ( $has_trade_info ) {
                YSLogger::warning( "API 回應 {$code} 但含交易資訊，視為部分成功", [
                    'request_id'     => $request_id,
                    'http_code'      => $code,
                    'tradeOrderId'   => $decoded_body['tradeOrderId'] ?? 'none',
                    'has_nextAction' => isset( $decoded_body['nextAction'] ) ? 'yes' : 'no',
                ] );

                throw new YSApiPartialSuccessException( $decoded_body, $decoded_body['msg'] ?? '', $code );
            }

            $error_message = $decoded_body['msg'] ?? $decoded_body['message'] ?? "API 請求失敗，狀態碼: {$code}";
            $error_code    = (string) ( $decoded_body['code'] ?? 'api_error' );

            // 1999 是 SHOPLINE 內部伺服器錯誤，提供更有意義的訊息
            if ( '1999' === $error_code ) {
                $error_message = 'SHOPLINE 伺服器錯誤 (1999)。請稍後重試或聯繫客服。';
                YSLogger::error( 'SHOPLINE Server Error 1999', [
                    'request_id' => $request_id,
                    'http_code'  => $code,
                    'response'   => $decoded_body,
                    'hint'       => '這通常是 SHOPLINE 內部處理錯誤，可能與 3DS 驗證流程有關',
                ] );
            } else {
                YSLogger::error( "API 錯誤: {$error_message}", [
                    'request_id' => $request_id,
                    'code'       => $error_code,
                    'http_code'  => $code,
                ] );
            }

            throw new YSApiException( $error_code, $error_message, $code );
        }

        return $decoded_body;
    }

    /**
     * 建立請求標頭
     *
     * @param string $request_id 請求 ID
     * @return array<string, string>
     */
    private function build_headers( string $request_id, string $idempotent_key = '' ): array {
        $headers = [
            'Content-Type' => 'application/json',
            'merchantId'   => $this->merchant_id,
            'apiKey'       => $this->api_key,
            'requestId'    => $request_id,
        ];

        if ( '' !== $idempotent_key ) {
            $headers['idempotentKey'] = substr( $idempotent_key, 0, 32 );
        }

        // 平台特店需要加入 platformId
        if ( $this->platform_id ) {
            $headers['platformId'] = $this->platform_id;
        }

        return $headers;
    }

    /**
     * 產生唯一請求 ID
     *
     * @return string
     */
    private function generate_request_id(): string {
        return (string) round( microtime( true ) * 1000 ) . wp_rand( 1000, 9999 );
    }

    /**
     * 取得 API URL
     *
     * @return string
     */
    public function get_api_url(): string {
        return $this->is_test_mode ? self::SANDBOX_URL : self::PRODUCTION_URL;
    }

    /**
     * 是否為測試模式
     *
     * @return bool
     */
    public function is_test_mode(): bool {
        return $this->is_test_mode;
    }

    /**
     * 檢查 API 憑證是否已設定
     *
     * @return bool
     */
    public function has_credentials(): bool {
        return ! empty( $this->merchant_id ) && ! empty( $this->api_key );
    }
}
