<?php
/**
 * YSMarketplaceInstaller - 外掛安裝 / 更新處理
 *
 * 使用 WordPress Plugin_Upgrader API 進行安裝和更新。
 *
 * @package YangSheep\PluginHubClient\Marketplace
 */

namespace YangSheep\PluginHubClient\Marketplace;

use YangSheep\PluginHubClient\Database\YSHubClientLogRepo;
use YangSheep\PluginHubClient\Http\YSHubApiClient;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 外掛安裝和更新操作
 */
final class YSMarketplaceInstaller {

    /**
     * 安裝外掛
     *
     * @param string $slug    外掛 slug
     * @param string $version 版本號
     * @return true|\WP_Error
     */
    public static function install( string $slug, string $version ) {
        $download_url = YSHubApiClient::instance()->get_download_url( $slug, $version );

        if ( empty( $download_url ) ) {
            return new \WP_Error(
                'ys_hub_no_download_url',
                __( '無法取得下載連結', 'ys-plugin-hub-client' )
            );
        }

        // 載入必要的 WP 類別（Plugin_Upgrader 依賴多個 admin includes）
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/misc.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';

        // 使用靜默 skin 避免輸出 HTML
        $skin     = new \WP_Ajax_Upgrader_Skin();
        $upgrader = new \Plugin_Upgrader( $skin );

        YSHubClientLogRepo::info( 'install', sprintf( '開始安裝 %s v%s', $slug, $version ), array( 'url' => $download_url ) );

        $result = $upgrader->install( $download_url );

        if ( is_wp_error( $result ) ) {
            YSHubClientLogRepo::error( 'install', sprintf( '安裝 %s 失敗：%s', $slug, $result->get_error_message() ) );
            return $result;
        }

        if ( is_wp_error( $skin->result ) ) {
            YSHubClientLogRepo::error( 'install', sprintf( '安裝 %s 失敗：%s', $slug, $skin->result->get_error_message() ) );
            return $skin->result;
        }

        if ( ! $result ) {
            $feedback = $skin->get_upgrade_messages();
            YSHubClientLogRepo::error( 'install', sprintf( '安裝 %s 失敗（未知原因）', $slug ), array( 'feedback' => $feedback ) );
            return new \WP_Error(
                'ys_hub_install_failed',
                __( '安裝失敗', 'ys-plugin-hub-client' )
            );
        }

        YSHubClientLogRepo::success( 'install', sprintf( '外掛 %s v%s 安裝成功', $slug, $version ) );
        return true;
    }

    /**
     * 更新外掛
     *
     * @param string $slug    外掛 slug
     * @param string $version 目標版本號
     * @return true|\WP_Error
     */
    public static function update( string $slug, string $version ) {
        // 找到外掛檔案路徑
        $plugin_file = self::find_plugin_file( $slug );

        if ( empty( $plugin_file ) ) {
            return new \WP_Error(
                'ys_hub_plugin_not_found',
                sprintf(
                    /* translators: %s: 外掛 slug */
                    __( '找不到外掛 %s', 'ys-plugin-hub-client' ),
                    $slug
                )
            );
        }

        $download_url = YSHubApiClient::instance()->get_download_url( $slug, $version );

        if ( empty( $download_url ) ) {
            return new \WP_Error(
                'ys_hub_no_download_url',
                __( '無法取得下載連結', 'ys-plugin-hub-client' )
            );
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/misc.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';

        // 記錄更新前的啟用狀態
        $was_active         = is_plugin_active( $plugin_file );
        $was_network_active = is_plugin_active_for_network( $plugin_file );

        $skin     = new \WP_Ajax_Upgrader_Skin();
        $upgrader = new \Plugin_Upgrader( $skin );

        // 注入更新資訊供 upgrader 使用
        $transient = get_site_transient( 'update_plugins' );
        if ( ! is_object( $transient ) ) {
            $transient = new \stdClass();
        }

        $transient->response[ $plugin_file ] = (object) array(
            'slug'        => $slug,
            'plugin'      => $plugin_file,
            'new_version' => $version,
            'package'     => $download_url,
        );

        set_site_transient( 'update_plugins', $transient );

        YSHubClientLogRepo::info( 'update', sprintf( '開始更新 %s → v%s（啟用: %s）', $slug, $version, $was_active ? 'yes' : 'no' ) );

        $result = $upgrader->upgrade( $plugin_file );

        if ( is_wp_error( $result ) ) {
            YSHubClientLogRepo::error( 'update', sprintf( '更新 %s 失敗：%s', $slug, $result->get_error_message() ) );
            return $result;
        }

        if ( is_wp_error( $skin->result ) ) {
            YSHubClientLogRepo::error( 'update', sprintf( '更新 %s 失敗：%s', $slug, $skin->result->get_error_message() ) );
            return $skin->result;
        }

        if ( ! $result ) {
            return new \WP_Error(
                'ys_hub_update_failed',
                __( '更新失敗', 'ys-plugin-hub-client' )
            );
        }

        // 更新成功 → 重新啟用（如果原本是啟用狀態）
        if ( $was_active ) {
            $activate_result = activate_plugin( $plugin_file, '', $was_network_active, true );
            if ( is_wp_error( $activate_result ) ) {
                YSHubClientLogRepo::error( 'update', sprintf(
                    '更新 %s 成功但重新啟用失敗：%s',
                    $slug,
                    $activate_result->get_error_message()
                ) );
                // 更新本身成功，回傳成功但附帶警告
            } else {
                YSHubClientLogRepo::success( 'update', sprintf( '外掛 %s 已更新至 v%s 並重新啟用', $slug, $version ) );
            }
        } else {
            YSHubClientLogRepo::success( 'update', sprintf( '外掛 %s 已更新至 v%s（未啟用）', $slug, $version ) );
        }

        // 清除更新快取
        delete_site_transient( 'ys_hub_update_data' );

        return true;
    }

    /**
     * 根據 slug 找到外掛檔案路徑
     *
     * @param string $slug 外掛 slug
     * @return string 外掛檔案路徑（如 ys-paynow-shipping/ys-paynow-shipping.php），找不到回傳空字串
     */
    private static function find_plugin_file( string $slug ): string {
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $all_plugins = get_plugins();

        foreach ( $all_plugins as $plugin_file => $plugin_data ) {
            if ( dirname( $plugin_file ) === $slug ) {
                return $plugin_file;
            }
        }

        return '';
    }
}
