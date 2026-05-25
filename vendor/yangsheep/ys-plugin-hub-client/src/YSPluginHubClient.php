<?php
/**
 * YSPluginHubClient - 主 Facade 類別
 *
 * 負責初始化所有子模組，偵測 YS 系列外掛。
 *
 * @package YangSheep\PluginHubClient
 */

namespace YangSheep\PluginHubClient;

use YangSheep\PluginHubClient\Admin\YSHubAjaxHandler;
use YangSheep\PluginHubClient\Http\YSHubApiClient;
use YangSheep\PluginHubClient\Marketplace\YSMarketplacePage;
use YangSheep\PluginHubClient\Updater\YSUpdateChecker;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 主 Facade — 統一初始化所有子系統
 */
final class YSPluginHubClient {

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
        $this->init_hooks();
    }

    /**
     * 初始化 Hooks
     *
     * @return void
     */
    private function init_hooks(): void {
        // 更新檢查器（前後台 + Cron 都需要，不限 is_admin）
        YSUpdateChecker::init();

        // 背景 Cron hook（不限 is_admin，WP Cron 也需要）
        add_action( 'ys_hub_bg_check', array( YSUpdateChecker::class, 'background_check' ) );

        // 以下僅後台
        if ( ! is_admin() ) {
            return;
        }

        // 註冊選單（priority 20，比其他 YS 外掛的 21 早，確保 ys-toolbox 首頁由市集控制）
        add_action( 'admin_menu', array( $this, 'register_menu' ), 20 );

        // 初始化 AJAX 處理器
        YSHubAjaxHandler::init();

        // WP admin footer 加入 YANGSHEEP CLOUD
        add_filter( 'admin_footer_text', array( $this, 'admin_footer_text' ) );

        // 聯絡我們外部連結 target="_blank"
        add_action( 'admin_footer', array( $this, 'contact_link_script' ) );
    }

    /**
     * 註冊後台選單
     *
     * 加入 ys-toolbox 子選單；若 ys-toolbox 不存在則自建頂層選單。
     *
     * @return void
     */
    public function register_menu(): void {
        global $menu;

        // 檢查 ys-toolbox 頂層選單是否存在
        $toolbox_exists = false;
        if ( is_array( $menu ) ) {
            foreach ( $menu as $item ) {
                if ( isset( $item[2] ) && 'ys-toolbox' === $item[2] ) {
                    $toolbox_exists = true;
                    break;
                }
            }
        }

        if ( ! $toolbox_exists ) {
            // 頂層選單：首頁直接顯示市集
            add_menu_page(
                esc_html__( 'YS Plugin', 'ys-plugin-hub-client' ),
                esc_html__( 'YS Plugin', 'ys-plugin-hub-client' ),
                'manage_options',
                'ys-toolbox',
                array( YSMarketplacePage::class, 'render' ),
                'dashicons-admin-plugins',
                59
            );
        }

        // 第一個子選單覆蓋（顯示為「外掛市集」）
        add_submenu_page(
            'ys-toolbox',
            esc_html__( 'YS 外掛市集', 'ys-plugin-hub-client' ),
            esc_html__( '外掛市集', 'ys-plugin-hub-client' ),
            'manage_options',
            'ys-toolbox', // 與 parent 同 slug → 覆蓋首頁子選單
            array( YSMarketplacePage::class, 'render' )
        );

        // 系統資訊子選單
        add_submenu_page(
            'ys-toolbox',
            esc_html__( '系統資訊', 'ys-plugin-hub-client' ),
            esc_html__( '系統資訊', 'ys-plugin-hub-client' ),
            'manage_options',
            'ys-hub-logs',
            array( $this, 'render_logs_page' )
        );

        // 聯絡我們（外部連結，新開視窗）
        global $submenu;
        $submenu['ys-toolbox'][] = array(
            '<span id="ys-contact-link">' . esc_html__( '聯絡我們', 'ys-plugin-hub-client' ) . ' <span class="dashicons dashicons-external" style="font-size:12px;width:12px;height:12px;vertical-align:text-top;"></span></span>',
            'manage_options',
            'https://yangsheep.com.tw/contact-us/',
        );
    }

    /**
     * 渲染日誌頁面
     *
     * @return void
     */
    public function render_logs_page(): void {
        $log_repo = \YangSheep\PluginHubClient\Database\YSHubClientLogRepo::instance();

        $filter_level  = sanitize_key( $_GET['level'] ?? '' );
        $filter_action = sanitize_key( $_GET['action_filter'] ?? '' );
        $page          = max( 1, absint( $_GET['paged'] ?? 1 ) );

        $args = array(
            'limit'  => 50,
            'offset' => ( $page - 1 ) * 50,
        );

        if ( ! empty( $filter_level ) ) {
            $args['level'] = $filter_level;
        }
        if ( ! empty( $filter_action ) ) {
            $args['action'] = $filter_action;
        }

        $logs  = $log_repo->get_logs( $args );
        $total = $log_repo->count( $filter_level, $filter_action );

        // Enqueue marketplace CSS（共用莫蘭迪色系）
        $css_file    = YS_HUB_CLIENT_DIR . 'assets/css/ys-marketplace.css';
        $css_version = YS_HUB_CLIENT_VERSION . '.' . ( file_exists( $css_file ) ? filemtime( $css_file ) : time() );
        wp_enqueue_style( 'ys-marketplace', YS_HUB_CLIENT_URL . 'assets/css/ys-marketplace.css', array(), $css_version );

        include YS_HUB_CLIENT_DIR . 'templates/logs-page.php';
    }

    /**
     * WP admin footer 加入 YANGSHEEP CLOUD 標示
     *
     * @param string $text 原始 footer 文字
     * @return string
     */
    public function admin_footer_text( string $text ): string {
        $ys_credit = sprintf(
            /* translators: %s: YANGSHEEP CLOUD link */
            __( '由 %s 開發與維護', 'ys-plugin-hub-client' ),
            '<a href="https://yangsheep.com.tw" target="_blank" rel="noopener noreferrer" style="text-decoration:none;">YANGSHEEP CLOUD</a>'
        );

        return $text . ' | ' . $ys_credit;
    }

    /**
     * 聯絡我們連結新開視窗
     *
     * @return void
     */
    public function contact_link_script(): void {
        ?>
        <script>
        jQuery(function($){
            $('#adminmenu a[href*="yangsheep.com.tw/contact"]').attr('target', '_blank').attr('rel', 'noopener noreferrer');
        });
        </script>
        <?php
    }

    /**
     * 向後相容：外掛手動註冊（v1.0 API）
     *
     * v2.0 使用 detect_ys_plugins() 自動偵測，此方法保留給已整合 v1.0 register() 的外掛。
     * 呼叫此方法不會有任何副作用，僅為避免 fatal error。
     *
     * @param array $config 外掛設定（slug, version, plugin_file, name）
     * @return void
     */
    public static function register( array $config ): void {
        // No-op — v2.0 自動偵測，不需要手動註冊
    }

    /**
     * 偵測已安裝的 YS 系列外掛
     *
     * 掃描 slug 以 ys- 或 yangsheep- 開頭的外掛。
     *
     * @return array [ slug => version ] 對應表
     */
    public static function detect_ys_plugins(): array {
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $all_plugins  = get_plugins();
        $ys_plugins   = array();

        foreach ( $all_plugins as $plugin_file => $plugin_data ) {
            $slug = dirname( $plugin_file );

            // 只偵測 ys- 或 yangsheep- 開頭的外掛
            if ( 0 === strpos( $slug, 'ys-' ) || 0 === strpos( $slug, 'yangsheep-' ) ) {
                $ys_plugins[ $slug ] = array(
                    'version'   => $plugin_data['Version'] ?? '0.0.0',
                    'name'      => $plugin_data['Name'] ?? $slug,
                    'active'    => is_plugin_active( $plugin_file ),
                    'file'      => $plugin_file,
                );
            }
        }

        return $ys_plugins;
    }
}
