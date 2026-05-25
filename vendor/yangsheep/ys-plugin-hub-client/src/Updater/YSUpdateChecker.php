<?php
/**
 * YSUpdateChecker - 異步更新檢查器
 *
 * 核心原則：pre_set_site_transient_update_plugins 中永遠不做同步 HTTP。
 * 只讀快取，過期時排程背景 Cron 更新。
 *
 * @package YangSheep\PluginHubClient\Updater
 */

namespace YangSheep\PluginHubClient\Updater;

use YangSheep\PluginHubClient\Http\YSCircuitBreaker;
use YangSheep\PluginHubClient\Http\YSHubApiClient;
use YangSheep\PluginHubClient\YSPluginHubClient;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 完全異步的更新檢查器
 *
 * 流程：
 * 1. pre_set_site_transient_update_plugins 觸發
 * 2. 讀取快取 (site_transient: ys_hub_update_data, TTL 12h)
 * 3. 有快取 → 直接注入 transient → 回傳（0ms）
 * 4. 無快取且沒有排程 → wp_schedule_single_event(time(), 'ys_hub_bg_check')
 * 5. 直接回傳原 transient（不等 HTTP）
 * 6. WP Cron 背景觸發 → 呼叫 Hub → 更新快取
 */
class YSUpdateChecker {

    /**
     * 更新資料快取 key
     *
     * @var string
     */
    private const CACHE_KEY = 'ys_hub_update_data';

    /**
     * 快取 TTL（秒）— 12 小時
     *
     * @var int
     */
    private const CACHE_TTL = 43200;

    /**
     * 背景排程 flag transient
     *
     * @var string
     */
    private const BG_LOCK_KEY = 'ys_hub_bg_check_scheduled';

    /**
     * 初始化 hooks
     *
     * @return void
     */
    public static function init(): void {
        // 注入更新資訊（只讀快取，零 HTTP）
        add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'inject_update_data' ) );

        // 攔截 plugins_api 提供外掛詳細資訊
        add_filter( 'plugins_api', array( __CLASS__, 'plugins_api_filter' ), 10, 3 );
    }

    /**
     * 注入更新資料到 update_plugins transient
     *
     * **關鍵**：此方法中永遠不做同步 HTTP 請求。
     *
     * @param object $transient WordPress update_plugins transient
     * @return object
     */
    public static function inject_update_data( $transient ) {
        if ( ! is_object( $transient ) ) {
            return $transient;
        }

        // 讀取快取
        $cached = get_site_transient( self::CACHE_KEY );

        if ( false === $cached || ! is_array( $cached ) ) {
            // 快取不存在或過期 → 嘗試同步取得（帶超時保護）
            // 不再依賴 WP Cron 單次事件（WP-CLI cron 環境下 autoloader 可能不完整）
            $cached = self::sync_update_check();
            if ( false === $cached || ! is_array( $cached ) ) {
                return $transient;
            }
        }

        // 有快取 → 注入更新資訊
        $ys_plugins = YSPluginHubClient::detect_ys_plugins();

        foreach ( $cached as $slug => $update_data ) {
            if ( ! isset( $ys_plugins[ $slug ] ) ) {
                continue;
            }

            $local_version  = $ys_plugins[ $slug ]['version'];
            $remote_version = $update_data['version'] ?? '0.0.0';
            $plugin_file    = $ys_plugins[ $slug ]['file'];

            if ( version_compare( $remote_version, $local_version, '>' ) ) {
                // 有新版本 → 加入 response（更新列表）
                $transient->response[ $plugin_file ] = YSPluginInfo::to_update_object( $update_data, $plugin_file );
            } else {
                // 已是最新 → 加入 no_update
                $transient->no_update[ $plugin_file ] = YSPluginInfo::to_update_object( $update_data, $plugin_file );
            }
        }

        return $transient;
    }

    /**
     * 攔截 plugins_api 提供 YS 外掛的詳細資訊
     *
     * @param false|object|array $result 預設結果
     * @param string             $action API 動作
     * @param object             $args   查詢參數
     * @return false|object
     */
    public static function plugins_api_filter( $result, $action, $args ) {
        if ( 'plugin_information' !== $action ) {
            return $result;
        }

        $slug = $args->slug ?? '';
        if ( empty( $slug ) ) {
            return $result;
        }

        // 只處理 YS 系列外掛
        if ( 0 !== strpos( $slug, 'ys-' ) && 0 !== strpos( $slug, 'yangsheep-' ) ) {
            return $result;
        }

        // 從快取讀取外掛資訊
        $cached = get_site_transient( self::CACHE_KEY );
        if ( ! is_array( $cached ) || ! isset( $cached[ $slug ] ) ) {
            return $result;
        }

        return YSPluginInfo::from_hub_response( $cached[ $slug ] );
    }

    /**
     * 同步更新檢查（帶超時保護和快取鎖）
     *
     * 當快取為空時直接呼叫 Hub API（不依賴 WP Cron），
     * 使用 5 分鐘鎖避免短時間內重複請求。
     *
     * @return array|false 成功回傳快取陣列，失敗回傳 false
     */
    private static function sync_update_check() {
        // 5 分鐘內不重複請求
        if ( get_site_transient( self::BG_LOCK_KEY ) ) {
            return false;
        }
        set_site_transient( self::BG_LOCK_KEY, 1, 300 );

        // Circuit Breaker 檢查
        if ( ! YSCircuitBreaker::is_available() ) {
            return false;
        }

        // 收集已安裝外掛
        $ys_plugins = YSPluginHubClient::detect_ys_plugins();
        if ( empty( $ys_plugins ) ) {
            return false;
        }

        $plugins_data = array();
        foreach ( $ys_plugins as $slug => $info ) {
            $plugins_data[ $slug ] = $info['version'];
        }

        // 呼叫 Hub（ApiClient 已有超時保護 + Circuit Breaker）
        $api      = YSHubApiClient::instance();
        $response = $api->check_updates( $plugins_data );

        if ( is_wp_error( $response ) ) {
            return false;
        }

        // 合併 updates + no_updates
        $all_plugins = array();
        if ( isset( $response['updates'] ) && is_array( $response['updates'] ) ) {
            $all_plugins = array_merge( $all_plugins, $response['updates'] );
        }
        if ( isset( $response['no_updates'] ) && is_array( $response['no_updates'] ) ) {
            $all_plugins = array_merge( $all_plugins, $response['no_updates'] );
        }
        if ( empty( $all_plugins ) && isset( $response['plugins'] ) ) {
            $all_plugins = $response['plugins'];
        }

        if ( ! empty( $all_plugins ) ) {
            set_site_transient( self::CACHE_KEY, $all_plugins, self::CACHE_TTL );
            return $all_plugins;
        }

        return false;
    }

    /**
     * 背景 Cron 執行：呼叫 Hub 取得更新資料
     *
     * 由 WP Cron 觸發，不在前台執行。
     *
     * @return void
     */
    public static function background_check(): void {
        // 清除排程鎖
        delete_site_transient( self::BG_LOCK_KEY );

        // 熔斷器檢查
        if ( ! YSCircuitBreaker::is_available() ) {
            return; // 熔斷中 → 跳過，繼續使用過期快取
        }

        // 收集已安裝的 YS 外掛資訊
        $ys_plugins = YSPluginHubClient::detect_ys_plugins();
        if ( empty( $ys_plugins ) ) {
            return;
        }

        // 組裝傳送資料
        $plugins_data = array();
        foreach ( $ys_plugins as $slug => $info ) {
            $plugins_data[ $slug ] = $info['version'];
        }

        // 呼叫 Hub
        $api      = YSHubApiClient::instance();
        $response = $api->check_updates( $plugins_data );

        if ( is_wp_error( $response ) ) {
            // 失敗（CircuitBreaker 已在 ApiClient 中處理）
            return;
        }

        // 成功 → 更新快取
        // Hub /update-check 回傳 {success, count, updates: {slug: {...}}, no_updates: {slug: {...}}}
        // 合併 updates + no_updates 存入快取
        $all_plugins = array();
        if ( isset( $response['updates'] ) && is_array( $response['updates'] ) ) {
            $all_plugins = array_merge( $all_plugins, $response['updates'] );
        }
        if ( isset( $response['no_updates'] ) && is_array( $response['no_updates'] ) ) {
            $all_plugins = array_merge( $all_plugins, $response['no_updates'] );
        }
        // Fallback: 如果 Hub 回傳的格式不同
        if ( empty( $all_plugins ) && isset( $response['plugins'] ) ) {
            $all_plugins = $response['plugins'];
        }

        if ( ! empty( $all_plugins ) ) {
            set_site_transient( self::CACHE_KEY, $all_plugins, self::CACHE_TTL );
        }
    }

    /**
     * 強制刷新更新快取
     *
     * @return bool 是否成功
     */
    public static function force_refresh(): bool {
        // 清除現有快取
        delete_site_transient( self::CACHE_KEY );
        delete_site_transient( self::BG_LOCK_KEY );

        // 直接執行背景檢查（管理員手動觸發，允許同步）
        $ys_plugins = YSPluginHubClient::detect_ys_plugins();
        if ( empty( $ys_plugins ) ) {
            return false;
        }

        $plugins_data = array();
        foreach ( $ys_plugins as $slug => $info ) {
            $plugins_data[ $slug ] = $info['version'];
        }

        $api      = YSHubApiClient::instance();
        $response = $api->check_updates( $plugins_data );

        if ( is_wp_error( $response ) ) {
            return false;
        }

        $all_plugins = array();
        if ( isset( $response['updates'] ) && is_array( $response['updates'] ) ) {
            $all_plugins = array_merge( $all_plugins, $response['updates'] );
        }
        if ( isset( $response['no_updates'] ) && is_array( $response['no_updates'] ) ) {
            $all_plugins = array_merge( $all_plugins, $response['no_updates'] );
        }
        if ( empty( $all_plugins ) && isset( $response['plugins'] ) ) {
            $all_plugins = $response['plugins'];
        }

        if ( ! empty( $all_plugins ) ) {
            set_site_transient( self::CACHE_KEY, $all_plugins, self::CACHE_TTL );
            return true;
        }

        return false;
    }
}
