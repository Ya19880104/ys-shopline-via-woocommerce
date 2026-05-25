<?php
/**
 * YSPluginInfo - 外掛資訊對應
 *
 * 將 Hub 回傳的外掛資料對應到 WordPress 更新 API 格式。
 *
 * @package YangSheep\PluginHubClient\Updater
 */

namespace YangSheep\PluginHubClient\Updater;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 將 Hub 回傳的外掛資訊轉為 WP 更新格式
 */
class YSPluginInfo {

    /**
     * 從 Hub 回應建立 WP 更新物件
     *
     * 對應 WordPress 的 plugins_api() 回傳格式。
     *
     * @param array $plugin_data Hub 回傳的單一外掛資料
     * @return object
     */
    public static function from_hub_response( array $plugin_data ): object {
        $info = new \stdClass();
        $download_url = $plugin_data['package'] ?? $plugin_data['download_url'] ?? '';

        $info->name          = $plugin_data['name'] ?? '';
        $info->slug          = $plugin_data['slug'] ?? '';
        $info->version       = $plugin_data['version'] ?? '';
        $info->author        = $plugin_data['author'] ?? 'YANGSHEEP DESIGN';
        $info->author_profile = $plugin_data['author_profile'] ?? 'https://yangsheep.com.tw';
        $info->homepage      = $plugin_data['homepage'] ?? '';
        $info->requires      = $plugin_data['requires'] ?? '5.8';
        $info->tested        = $plugin_data['tested'] ?? '';
        $info->requires_php  = $plugin_data['requires_php'] ?? '7.4';
        $info->download_link = $download_url;
        $info->trunk         = $download_url;
        $info->last_updated  = $plugin_data['last_updated'] ?? '';

        // 描述區塊
        $info->sections = array(
            'description' => $plugin_data['description'] ?? '',
            'changelog'   => $plugin_data['changelog'] ?? '',
        );

        // 圖示
        if ( ! empty( $plugin_data['icons'] ) ) {
            $info->icons = $plugin_data['icons'];
        }

        // 橫幅
        if ( ! empty( $plugin_data['banners'] ) ) {
            $info->banners = $plugin_data['banners'];
        }

        return $info;
    }

    /**
     * 從 Hub 回應建立 WP 更新 transient 格式的物件
     *
     * 用於注入 update_plugins transient。
     *
     * @param array  $plugin_data Hub 回傳的單一外掛資料
     * @param string $plugin_file 外掛檔案路徑（如 ys-paynow-shipping/ys-paynow-shipping.php）
     * @return object
     */
    public static function to_update_object( array $plugin_data, string $plugin_file ): object {
        $update = new \stdClass();
        $download_url = $plugin_data['package'] ?? $plugin_data['download_url'] ?? '';

        $update->id          = $plugin_data['slug'] ?? '';
        $update->slug        = $plugin_data['slug'] ?? '';
        $update->plugin      = $plugin_file;
        $update->new_version = $plugin_data['version'] ?? '';
        $update->url         = $plugin_data['homepage'] ?? '';
        $update->package     = $download_url;
        $update->requires    = $plugin_data['requires'] ?? '5.8';
        $update->tested      = $plugin_data['tested'] ?? '';
        $update->requires_php = $plugin_data['requires_php'] ?? '7.4';

        if ( ! empty( $plugin_data['icons'] ) ) {
            $update->icons = $plugin_data['icons'];
        }

        return $update;
    }
}
