<?php
/**
 * YSEndpointRegistry - Hub API 端點註冊表
 *
 * 集中管理所有 Hub API 路徑常數。
 *
 * @package YangSheep\PluginHubClient\Registry
 */

namespace YangSheep\PluginHubClient\Registry;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Hub API 端點常數
 */
final class YSEndpointRegistry {

    /**
     * 更新檢查端點
     *
     * @var string
     */
    const UPDATE_CHECK = 'wp-json/ys-hub/v1/update-check';

    /**
     * 市集外掛列表端點
     *
     * @var string
     */
    const MARKETPLACE_LIST = 'wp-json/ys-hub/v1/plugins';

    /**
     * 外掛下載端點
     *
     * @var string
     */
    const PLUGIN_DOWNLOAD = 'wp-json/ys-hub/v1/download';

    /**
     * 外掛詳細資訊端點（{slug} 由呼叫者拼接）
     *
     * @var string
     */
    const PLUGIN_INFO = 'wp-json/ys-hub/v1/plugin';

    /**
     * 連線測試端點（用 /plugins 作為 ping）
     *
     * @var string
     */
    const PING = 'wp-json/ys-hub/v1/plugins';

    /**
     * Site Key 產生端點
     *
     * @var string
     */
    const GENERATE_SITE_KEY = 'wp-json/ys-hub/v1/site-key/generate';

    /**
     * 分類列表端點
     *
     * @var string
     */
    const CATEGORIES = 'wp-json/ys-hub/v1/categories';

    /**
     * 公告列表端點
     *
     * @var string
     */
    const ANNOUNCEMENTS = 'wp-json/ys-hub/v1/announcements';

    /**
     * 私有建構子 — 不可實例化
     */
    private function __construct() {}
}
