<?php
/**
 * YSHubClientSettingsRepo - 設定 CRUD 操作類別
 *
 * 使用自訂資料表 ys_hub_client_settings，禁止使用 wp_options。
 *
 * @package YangSheep\PluginHubClient\Database
 */

namespace YangSheep\PluginHubClient\Database;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 負責設定的 CRUD 操作和快取管理
 */
class YSHubClientSettingsRepo {

    /**
     * 設定快取
     *
     * @var array|null
     */
    private static $cache = null;

    /**
     * 快取是否已載入
     *
     * @var bool
     */
    private static $cache_loaded = false;

    /**
     * 單例實例
     *
     * @var self|null
     */
    private static $instance = null;

    /**
     * Table Maker 實例
     *
     * @var YSHubClientTableMaker
     */
    private $table_maker;

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
        $this->table_maker = YSHubClientTableMaker::instance();
    }

    /**
     * 取得資料表名稱
     *
     * @return string
     */
    private function get_table_name(): string {
        return $this->table_maker->get_table_name();
    }

    /**
     * 檢查資料表是否存在
     *
     * @return bool
     */
    public function table_exists(): bool {
        return $this->table_maker->table_exists();
    }

    /**
     * 取得設定值
     *
     * @param string $key     設定 key
     * @param mixed  $default 預設值
     * @return mixed
     */
    public function get( string $key, $default = false ) {
        $this->prime_cache();

        if ( isset( self::$cache[ $key ] ) ) {
            return self::$cache[ $key ];
        }

        return $default;
    }

    /**
     * 設定值（UPSERT）
     *
     * @param string $key   設定 key
     * @param mixed  $value 設定值
     * @return bool
     */
    public function set( string $key, $value ): bool {
        global $wpdb;

        if ( ! $this->table_exists() ) {
            return false;
        }

        $table_name = $this->get_table_name();
        $serialized = maybe_serialize( $value );
        $key_exists = $this->key_exists_in_db( $key );

        if ( $key_exists ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $result = $wpdb->update(
                $table_name,
                array( 'setting_value' => $serialized ),
                array( 'setting_key' => $key ),
                array( '%s' ),
                array( '%s' )
            );
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $result = $wpdb->insert(
                $table_name,
                array(
                    'setting_key'   => $key,
                    'setting_value' => $serialized,
                ),
                array( '%s', '%s' )
            );
        }

        // 更新快取
        if ( false !== $result ) {
            self::$cache[ $key ] = $value;
            return true;
        }

        return false;
    }

    /**
     * 刪除設定
     *
     * @param string $key 設定 key
     * @return bool
     */
    public function delete( string $key ): bool {
        global $wpdb;

        if ( ! $this->table_exists() ) {
            return false;
        }

        $table_name = $this->get_table_name();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = $wpdb->delete(
            $table_name,
            array( 'setting_key' => $key ),
            array( '%s' )
        );

        if ( false !== $result ) {
            unset( self::$cache[ $key ] );
            return true;
        }

        return false;
    }

    /**
     * 取得所有設定
     *
     * @return array
     */
    public function get_all(): array {
        global $wpdb;

        if ( ! $this->table_exists() ) {
            return array();
        }

        $table_name = $this->get_table_name();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $results = $wpdb->get_results(
            "SELECT setting_key, setting_value FROM {$table_name}",
            ARRAY_A
        );

        if ( empty( $results ) ) {
            return array();
        }

        $settings = array();
        foreach ( $results as $row ) {
            $settings[ $row['setting_key'] ] = maybe_unserialize( $row['setting_value'] );
        }

        return $settings;
    }

    /**
     * 批次設定多個值
     *
     * @param array $settings key => value 陣列
     * @return bool
     */
    public function set_many( array $settings ): bool {
        if ( empty( $settings ) ) {
            return true;
        }

        $success = true;
        foreach ( $settings as $key => $value ) {
            if ( ! $this->set( $key, $value ) ) {
                $success = false;
            }
        }

        return $success;
    }

    /**
     * 預先載入快取
     *
     * @return void
     */
    public function prime_cache(): void {
        if ( self::$cache_loaded ) {
            return;
        }

        self::$cache        = $this->get_all();
        self::$cache_loaded = true;
    }

    /**
     * 清除快取
     *
     * @return void
     */
    public function flush_cache(): void {
        self::$cache        = null;
        self::$cache_loaded = false;
    }

    /**
     * 檢查資料庫中是否存在指定的 key（直接查詢，不使用快取）
     *
     * @param string $key 設定 key
     * @return bool
     */
    private function key_exists_in_db( string $key ): bool {
        global $wpdb;

        if ( ! $this->table_exists() ) {
            return false;
        }

        $table_name = $this->get_table_name();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $count = $wpdb->get_var(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT COUNT(*) FROM {$table_name} WHERE setting_key = %s",
                $key
            )
        );

        return (int) $count > 0;
    }
}
