<?php
/**
 * YSHubClientLogRepo - 客戶端日誌 Repository
 *
 * 記錄所有 Hub 操作的日誌（更新檢查、安裝、同步、連線等）。
 *
 * @package YangSheep\PluginHubClient\Database
 */

namespace YangSheep\PluginHubClient\Database;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 日誌 CRUD
 */
final class YSHubClientLogRepo {

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
     * 取得資料表名稱
     *
     * @return string
     */
    private function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'ys_hub_client_logs';
    }

    /**
     * 資料表是否存在
     *
     * @return bool
     */
    public function table_exists(): bool {
        global $wpdb;
        $table = $this->table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
    }

    /**
     * 寫入日誌
     *
     * @param string $level   等級：info, success, warning, error
     * @param string $action  操作：install, update, sync, check, connect, circuit_breaker
     * @param string $message 訊息
     * @param array  $context 附加資料（JSON 存儲）
     * @return void
     */
    public static function log( string $level, string $action, string $message, array $context = array() ): void {
        $instance = self::instance();

        if ( ! $instance->table_exists() ) {
            return; // 資料表不存在時靜默跳過
        }

        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->insert(
            $instance->table(),
            array(
                'level'      => sanitize_key( $level ),
                'action'     => sanitize_text_field( $action ),
                'message'    => sanitize_text_field( $message ),
                'context'    => ! empty( $context ) ? wp_json_encode( $context ) : null,
                'created_at' => current_time( 'mysql' ),
            ),
            array( '%s', '%s', '%s', '%s', '%s' )
        );

        // 自動清理超過 30 天的日誌
        self::auto_cleanup();
    }

    /**
     * 快捷方法：info
     */
    public static function info( string $action, string $message, array $context = array() ): void {
        self::log( 'info', $action, $message, $context );
    }

    /**
     * 快捷方法：success
     */
    public static function success( string $action, string $message, array $context = array() ): void {
        self::log( 'success', $action, $message, $context );
    }

    /**
     * 快捷方法：warning
     */
    public static function warning( string $action, string $message, array $context = array() ): void {
        self::log( 'warning', $action, $message, $context );
    }

    /**
     * 快捷方法：error
     */
    public static function error( string $action, string $message, array $context = array() ): void {
        self::log( 'error', $action, $message, $context );
    }

    /**
     * 取得日誌列表
     *
     * @param array $args 查詢參數。
     * @return array
     */
    public function get_logs( array $args = array() ): array {
        if ( ! $this->table_exists() ) {
            return array();
        }

        global $wpdb;

        $defaults = array(
            'level'   => '',
            'action'  => '',
            'limit'   => 50,
            'offset'  => 0,
            'orderby' => 'created_at',
            'order'   => 'DESC',
        );

        $args  = wp_parse_args( $args, $defaults );
        $table = $this->table();
        $where = array( '1=1' );
        $values = array();

        if ( ! empty( $args['level'] ) ) {
            $where[]  = 'level = %s';
            $values[] = $args['level'];
        }

        if ( ! empty( $args['action'] ) ) {
            $where[]  = 'action = %s';
            $values[] = $args['action'];
        }

        $where_sql = implode( ' AND ', $where );
        $order     = 'DESC' === strtoupper( $args['order'] ) ? 'DESC' : 'ASC';
        $limit     = absint( $args['limit'] );
        $offset    = absint( $args['offset'] );

        $sql = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY created_at {$order} LIMIT {$limit} OFFSET {$offset}";

        if ( ! empty( $values ) ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            return $wpdb->get_results( $wpdb->prepare( $sql, ...$values ) );
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return $wpdb->get_results( $sql );
    }

    /**
     * 取得日誌總數
     *
     * @param string $level  篩選等級。
     * @param string $action 篩選操作。
     * @return int
     */
    public function count( string $level = '', string $action = '' ): int {
        if ( ! $this->table_exists() ) {
            return 0;
        }

        global $wpdb;
        $table = $this->table();
        $where = array( '1=1' );
        $values = array();

        if ( ! empty( $level ) ) {
            $where[]  = 'level = %s';
            $values[] = $level;
        }

        if ( ! empty( $action ) ) {
            $where[]  = 'action = %s';
            $values[] = $action;
        }

        $where_sql = implode( ' AND ', $where );
        $sql       = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";

        if ( ! empty( $values ) ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            return (int) $wpdb->get_var( $wpdb->prepare( $sql, ...$values ) );
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return (int) $wpdb->get_var( $sql );
    }

    /**
     * 清除所有日誌
     *
     * @return void
     */
    public function clear_all(): void {
        if ( ! $this->table_exists() ) {
            return;
        }

        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query( "TRUNCATE TABLE {$this->table()}" );
    }

    /**
     * 自動清理 30 天前的日誌
     *
     * @return void
     */
    private static function auto_cleanup(): void {
        // 每天最多清理一次
        $last_cleanup = get_site_transient( 'ys_hub_log_last_cleanup' );
        if ( false !== $last_cleanup ) {
            return;
        }

        set_site_transient( 'ys_hub_log_last_cleanup', 1, DAY_IN_SECONDS );

        global $wpdb;
        $table = self::instance()->table();
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query( "DELETE FROM {$table} WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)" );
    }
}
