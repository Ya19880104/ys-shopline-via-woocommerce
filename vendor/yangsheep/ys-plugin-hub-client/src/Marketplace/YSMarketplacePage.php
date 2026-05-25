<?php
/**
 * YSMarketplacePage - 市集頁面渲染
 *
 * 頁面先渲染 skeleton loading，再由前端 JS 發 AJAX 取得外掛列表。
 *
 * @package YangSheep\PluginHubClient\Marketplace
 */

namespace YangSheep\PluginHubClient\Marketplace;

use YangSheep\PluginHubClient\Database\YSHubClientSettingsRepo;
use YangSheep\PluginHubClient\Http\YSCircuitBreaker;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 市集頁面控制器
 */
final class YSMarketplacePage {

    /**
     * 渲染市集頁面
     *
     * @return void
     */
    public static function render(): void {
        // Enqueue assets
        self::enqueue_assets();

        // 讀取設定
        $repo       = YSHubClientSettingsRepo::instance();
        $site_key   = $repo->get( 'ys_hub_site_key', '' );
        $auto_check = $repo->get( 'ys_hub_auto_check', 'yes' );

        // Circuit Breaker 狀態
        $cb_state = YSCircuitBreaker::get_state();
        $cb_label = YSCircuitBreaker::get_state_label();

        // 載入模板
        include YS_HUB_CLIENT_DIR . 'templates/marketplace-page.php';
    }

    /**
     * 註冊前端資源
     *
     * @return void
     */
    private static function enqueue_assets(): void {
        // 使用 JS 檔案的修改時間作為版本號，確保更新後 bust cache
        $js_file = YS_HUB_CLIENT_DIR . 'assets/js/ys-marketplace.js';
        $version = YS_HUB_CLIENT_VERSION . '.' . ( file_exists( $js_file ) ? filemtime( $js_file ) : time() );

        wp_enqueue_style(
            'ys-marketplace',
            YS_HUB_CLIENT_URL . 'assets/css/ys-marketplace.css',
            array(),
            $version
        );

        wp_enqueue_script(
            'ys-marketplace',
            YS_HUB_CLIENT_URL . 'assets/js/ys-marketplace.js',
            array( 'jquery' ),
            $version,
            true
        );

        wp_localize_script( 'ys-marketplace', 'ysHubClient', array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'ys_hub_marketplace_nonce' ),
            'hubUrl'  => YS_HUB_CLIENT_HUB_URL,
            'i18n'    => array(
                'loading'        => __( '載入中...', 'ys-plugin-hub-client' ),
                'noPlugins'      => __( '目前沒有可用的外掛', 'ys-plugin-hub-client' ),
                'connectionFail' => __( '目前無法連線到 Hub，請稍後再試', 'ys-plugin-hub-client' ),
                'installed'      => __( '已安裝', 'ys-plugin-hub-client' ),
                'updateAvail'    => __( '可更新', 'ys-plugin-hub-client' ),
                'install'        => __( '安裝', 'ys-plugin-hub-client' ),
                'update'         => __( '更新', 'ys-plugin-hub-client' ),
                'installing'     => __( '安裝中...', 'ys-plugin-hub-client' ),
                'updating'       => __( '更新中...', 'ys-plugin-hub-client' ),
                'success'        => __( '成功', 'ys-plugin-hub-client' ),
                'failed'         => __( '失敗', 'ys-plugin-hub-client' ),
                'confirmInstall' => __( '確定要安裝此外掛嗎？', 'ys-plugin-hub-client' ),
                'confirmUpdate'  => __( '確定要更新此外掛嗎？', 'ys-plugin-hub-client' ),
                'saved'          => __( '設定已儲存', 'ys-plugin-hub-client' ),
                'refreshing'     => __( '刷新中...', 'ys-plugin-hub-client' ),
                'testingConn'    => __( '測試中...', 'ys-plugin-hub-client' ),
                'connSuccess'    => __( '連線成功', 'ys-plugin-hub-client' ),
                'connFailed'     => __( '連線失敗', 'ys-plugin-hub-client' ),
                'generating'     => __( '產生中...', 'ys-plugin-hub-client' ),
                'dismiss'           => __( '關閉', 'ys-plugin-hub-client' ),
                'activate'          => __( '啟用', 'ys-plugin-hub-client' ),
                'activating'        => __( '啟用中...', 'ys-plugin-hub-client' ),
                'activated'         => __( '已啟用', 'ys-plugin-hub-client' ),
                'active'            => __( '已啟用', 'ys-plugin-hub-client' ),
                'deactivate'        => __( '停用', 'ys-plugin-hub-client' ),
                'deactivating'      => __( '停用中...', 'ys-plugin-hub-client' ),
                'deletePlugin'      => __( '刪除', 'ys-plugin-hub-client' ),
                'deleting'          => __( '刪除中...', 'ys-plugin-hub-client' ),
                'confirmDelete'     => __( '確定要刪除此外掛？此操作無法復原。', 'ys-plugin-hub-client' ),
                'lastPluginWarning' => __( '最後一個 YS 外掛已停用，即將跳轉到外掛管理頁面...', 'ys-plugin-hub-client' ),
                'free'              => __( '免費', 'ys-plugin-hub-client' ),
                'viewPlugin'        => __( '查看外掛', 'ys-plugin-hub-client' ),
            ),
        ) );
    }
}
