<?php
/**
 * YSHubClientTableMaker - 客戶端設定資料表建立類別
 *
 * @package YangSheep\PluginHubClient\Database
 */

namespace YangSheep\PluginHubClient\Database;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 負責建立和管理 ys_hub_client_settings 資料表
 */
class YSHubClientTableMaker {

    /**
     * Schema 版本號
     *
     * @var int
     */
    protected $schema_version = 2;

    /**
     * 資料表名稱（不含前綴）
     *
     * @var string
     */
    protected $table_name = 'ys_hub_client_settings';

    /**
     * Schema 版本 option key
     *
     * @var string
     */
    const SCHEMA_VERSION_OPTION = 'ys_hub_client_settings_schema_version';

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
    private function __construct() {}

    /**
     * 取得完整資料表名稱（含前綴）
     *
     * @return string
     */
    public function get_table_name(): string {
        global $wpdb;
        return $wpdb->prefix . $this->table_name;
    }

    /**
     * 檢查資料表是否存在
     *
     * @return bool
     */
    public function table_exists(): bool {
        global $wpdb;
        $table = $this->get_table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
    }

    /**
     * 檢查是否需要更新 Schema
     *
     * @return bool
     */
    public function schema_update_required(): bool {
        $current_version = get_option( self::SCHEMA_VERSION_OPTION, 0 );
        return (int) $current_version < $this->schema_version;
    }

    /**
     * 建立或更新資料表
     *
     * @return array dbDelta 執行結果
     */
    public function create_table(): array {
        global $wpdb;

        $table_name      = $this->get_table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            setting_key varchar(191) NOT NULL,
            setting_value longtext,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY idx_setting_key (setting_key)
        ) {$charset_collate};";

        // 2. 日誌資料表
        $log_table = $wpdb->prefix . 'ys_hub_client_logs';
        $sql_logs  = "CREATE TABLE {$log_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            level varchar(20) NOT NULL DEFAULT 'info',
            action varchar(100) NOT NULL,
            message text NOT NULL,
            context longtext,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_level (level),
            KEY idx_action (action),
            KEY idx_created_at (created_at)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $result = dbDelta( $sql );
        dbDelta( $sql_logs );

        // 更新 schema 版本
        $this->mark_schema_update_complete();

        return $result;
    }

    /**
     * 標記 Schema 更新完成
     *
     * @return void
     */
    private function mark_schema_update_complete(): void {
        update_option( self::SCHEMA_VERSION_OPTION, $this->schema_version );
    }

    /**
     * 刪除資料表（解除安裝時使用）
     *
     * @return bool
     */
    public function drop_table(): bool {
        global $wpdb;
        $table_name = $this->get_table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $result = $wpdb->query( "DROP TABLE IF EXISTS {$table_name}" );
        delete_option( self::SCHEMA_VERSION_OPTION );
        return $result !== false;
    }

    /**
     * 取得當前 Schema 版本
     *
     * @return int
     */
    public function get_schema_version(): int {
        return $this->schema_version;
    }

    /**
     * 取得已安裝的 Schema 版本
     *
     * @return int
     */
    public function get_installed_schema_version(): int {
        return (int) get_option( self::SCHEMA_VERSION_OPTION, 0 );
    }
}
