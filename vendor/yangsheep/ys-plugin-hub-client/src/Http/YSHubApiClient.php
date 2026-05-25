<?php
/**
 * YSHubApiClient - Hub API HTTP 通訊客戶端
 *
 * 整合 CircuitBreaker，所有對 Hub 的 HTTP 請求都經由此類別。
 *
 * @package YangSheep\PluginHubClient\Http
 */

namespace YangSheep\PluginHubClient\Http;

use YangSheep\PluginHubClient\Database\YSHubClientLogRepo;
use YangSheep\PluginHubClient\Database\YSHubClientSettingsRepo;
use YangSheep\PluginHubClient\Registry\YSEndpointRegistry;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Hub API 低層 HTTP 請求器
 */
final class YSHubApiClient {

    /**
     * HTTP 請求超時秒數
     *
     * @var int
     */
    private const TIMEOUT = 15;

    /**
     * Hub 基底 URL
     *
     * @var string
     */
    private string $base_url;

    /**
     * 站台識別金鑰
     *
     * @var string
     */
    private string $site_key;

    /**
     * 單例實例
     *
     * @var self|null
     */
    private static $instance = null;

    /**
     * 取得單例實例
     *
     * @return self
     */
    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 私有建構子
     */
    private function __construct() {
        $this->base_url = rtrim( YS_HUB_CLIENT_HUB_URL, '/' );

        $repo           = YSHubClientSettingsRepo::instance();
        $this->site_key = $repo->get( 'ys_hub_site_key', '' );
    }

    /**
     * 發送 GET 請求
     *
     * @param string $endpoint API 路徑
     * @param array  $params   查詢參數
     * @return array|\WP_Error
     */
    public function get( string $endpoint, array $params = array() ) {
        $url = $this->build_url( $endpoint, $params );
        return $this->request( $url, 'GET' );
    }

    /**
     * 發送 POST 請求
     *
     * @param string $endpoint API 路徑
     * @param array  $data     POST body
     * @return array|\WP_Error
     */
    public function post( string $endpoint, array $data = array() ) {
        $url = $this->build_url( $endpoint );
        return $this->request( $url, 'POST', $data );
    }

    /**
     * 更新檢查 — 專用方法
     *
     * 傳送已安裝的 YS 外掛資訊到 Hub，取得可用更新。
     *
     * @param array $plugins_data [ slug => version ] 對應
     * @return array|\WP_Error
     */
    public function check_updates( array $plugins_data ) {
        return $this->post(
            YSEndpointRegistry::UPDATE_CHECK,
            array(
                'site_url' => get_site_url(),
                'site_key' => $this->site_key,
                'plugins'  => $plugins_data,
            )
        );
    }

    /**
     * 取得市集外掛列表
     *
     * @return array|\WP_Error
     */
    public function get_marketplace_plugins() {
        // 改用 POST 以便帶上已安裝外掛資訊（讓 Hub 記錄 plugins_data）
        $ys_plugins   = \YangSheep\PluginHubClient\YSPluginHubClient::detect_ys_plugins();
        $plugins_data = array();
        foreach ( $ys_plugins as $slug => $info ) {
            $plugins_data[ $slug ] = $info['version'];
        }

        return $this->post(
            YSEndpointRegistry::MARKETPLACE_LIST,
            array(
                'site_url' => get_site_url(),
                'site_key' => $this->site_key,
                'plugins'  => $plugins_data,
            )
        );
    }

    /**
     * 測試連線
     *
     * @return array|\WP_Error
     */
    public function test_connection() {
        return $this->get(
            YSEndpointRegistry::PING,
            array(
                'site_key' => $this->site_key,
            )
        );
    }

    /**
     * 取得外掛下載 URL
     *
     * @param string $slug    外掛 slug
     * @param string $version 版本號
     * @return string
     */
    public function get_download_url( string $slug, string $version ): string {
        return $this->build_url(
            YSEndpointRegistry::PLUGIN_DOWNLOAD,
            array(
                'slug'     => $slug,
                'version'  => $version,
                'site_key' => $this->site_key,
                'site_url' => get_site_url(),
            )
        );
    }

    /**
     * 建構完整 URL
     *
     * @param string $endpoint API 路徑
     * @param array  $params   查詢參數
     * @return string
     */
    private function build_url( string $endpoint, array $params = array() ): string {
        $url = $this->base_url . '/' . ltrim( $endpoint, '/' );
        if ( ! empty( $params ) ) {
            $url .= '?' . http_build_query( $params );
        }
        return $url;
    }

    /**
     * 發送 HTTP 請求（整合 Circuit Breaker）
     *
     * @param string     $url    完整 URL
     * @param string     $method HTTP 方法
     * @param array|null $body   POST body
     * @return array|\WP_Error 成功回傳解碼後的 body 陣列，失敗回傳 WP_Error
     */
    private function request( string $url, string $method, ?array $body = null ) {
        // 熔斷器檢查
        if ( ! YSCircuitBreaker::is_available() ) {
            return new \WP_Error(
                'ys_hub_circuit_open',
                __( 'Hub 連線暫時中斷（Circuit Breaker 熔斷中）', 'ys-plugin-hub-client' )
            );
        }

        $args = array(
            'method'    => $method,
            'timeout'   => self::TIMEOUT,
            'sslverify' => true,
            'headers'   => array(
                'Content-Type'     => 'application/json',
                'Accept'           => 'application/json',
                'X-Hub-Client'     => 'ys-plugin-hub-client/' . YS_HUB_CLIENT_VERSION,
                'X-Site-Key'       => $this->site_key,
            ),
        );

        if ( null !== $body && in_array( $method, array( 'POST', 'PUT', 'PATCH' ), true ) ) {
            $args['body'] = wp_json_encode( $body );
        }

        $response = wp_remote_request( $url, $args );

        // WP_Error 處理
        if ( is_wp_error( $response ) ) {
            YSCircuitBreaker::record_failure();
            YSHubClientLogRepo::error( 'connect', sprintf( 'HTTP 請求失敗：%s（%s）', $response->get_error_message(), $url ) );
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        $body_raw    = wp_remote_retrieve_body( $response );
        $decoded     = json_decode( $body_raw, true );

        // 伺服器錯誤 (5xx) → 記錄失敗
        if ( $status_code >= 500 ) {
            YSCircuitBreaker::record_failure();
            return new \WP_Error(
                'ys_hub_server_error',
                sprintf(
                    /* translators: %d: HTTP 狀態碼 */
                    __( 'Hub 伺服器錯誤 (HTTP %d)', 'ys-plugin-hub-client' ),
                    $status_code
                )
            );
        }

        // 客戶端錯誤 (4xx) → 不觸發熔斷，但回傳錯誤
        if ( $status_code >= 400 ) {
            $error_msg = $decoded['message'] ?? sprintf( 'HTTP %d', $status_code );
            return new \WP_Error( 'ys_hub_client_error', $error_msg );
        }

        // 成功 → 重置熔斷器
        YSCircuitBreaker::record_success();

        return is_array( $decoded ) ? $decoded : array();
    }

    /**
     * 重置單例（測試用）
     *
     * @return void
     */
    public static function reset_instance(): void {
        self::$instance = null;
    }
}
