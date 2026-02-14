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
     * @param string               $endpoint API 端點
     * @param array<string, mixed> $data     請求資料
     * @return array<string, mixed>
     * @throws \Exception 如果請求失敗
     */
    public function post( string $endpoint, array $data = [] ): array {
        return $this->request( $endpoint, $data, 'POST' );
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
    private function request( string $endpoint, array $data, string $method ): array {
        $url        = $this->get_api_url() . $endpoint;
        $request_id = $this->generate_request_id();
        $headers    = $this->build_headers( $request_id );

        $args = [
            'method'  => $method,
            'headers' => $headers,
            'timeout' => self::TIMEOUT,
        ];

        if ( ! empty( $data ) && 'GET' !== $method ) {
            $args['body'] = wp_json_encode( $data );
        }

        YSLogger::debug( "API 請求: {$method} {$url}", [
            'request_id' => $request_id,
            'data'       => $data,
        ] );

        $response = wp_remote_request( $url, $args );

        return $this->handle_response( $response, $request_id );
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
            throw new \Exception( "API 請求失敗: {$error_message}" );
        }

        $body = wp_remote_retrieve_body( $response );
        $code = wp_remote_retrieve_response_code( $response );

        YSLogger::debug( "API 回應 ({$code})", [
            'request_id' => $request_id,
            'body'       => $body,
        ] );

        $decoded_body = json_decode( $body, true );

        if ( json_last_error() !== JSON_ERROR_NONE ) {
            throw new \Exception( "無法解析 API 回應: {$body}" );
        }

        // 處理 HTTP 錯誤狀態碼
        if ( $code >= 400 ) {
            $error_message = $decoded_body['msg'] ?? $decoded_body['message'] ?? "API 請求失敗，狀態碼: {$code}";
            $error_code    = $decoded_body['code'] ?? 'api_error';

            YSLogger::error( "API 錯誤: {$error_message}", [
                'request_id' => $request_id,
                'code'       => $error_code,
                'http_code'  => $code,
            ] );

            throw new \Exception( $error_message );
        }

        return $decoded_body;
    }

    /**
     * 建立請求標頭
     *
     * @param string $request_id 請求 ID
     * @return array<string, string>
     */
    private function build_headers( string $request_id ): array {
        $headers = [
            'Content-Type' => 'application/json',
            'merchantId'   => $this->merchant_id,
            'apiKey'       => $this->api_key,
            'requestId'    => $request_id,
        ];

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
