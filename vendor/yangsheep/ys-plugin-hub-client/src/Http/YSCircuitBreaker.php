<?php
/**
 * YSCircuitBreaker - 熔斷器
 *
 * Hub 故障時自動熔斷，避免影響客戶端效能。
 * 使用 site_transient 儲存（不依賴自訂資料表）。
 *
 * @package YangSheep\PluginHubClient\Http
 */

namespace YangSheep\PluginHubClient\Http;

use YangSheep\PluginHubClient\Database\YSHubClientLogRepo;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Circuit Breaker 實作
 *
 * 狀態：closed（正常）→ open（熔斷）→ half_open（半開，嘗試恢復）
 */
class YSCircuitBreaker {

    /**
     * Transient key
     *
     * @var string
     */
    private const TRANSIENT_KEY = 'ys_hub_circuit_breaker';

    /**
     * 連續失敗次數門檻
     *
     * @var int
     */
    private const FAILURE_THRESHOLD = 3;

    /**
     * 熔斷恢復時間（秒）— 30 分鐘
     *
     * @var int
     */
    private const RECOVERY_TIMEOUT = 1800;

    /**
     * 檢查 Hub 是否可連線
     *
     * - closed / half_open → true（可嘗試）
     * - open 且未過恢復時間 → false（熔斷中）
     *
     * @return bool
     */
    public static function is_available(): bool {
        $state = get_site_transient( self::TRANSIENT_KEY );

        if ( ! $state ) {
            return true; // 無紀錄 = 正常
        }

        if ( 'open' === ( $state['status'] ?? '' ) ) {
            // 檢查是否已過恢復時間
            if ( time() - ( $state['opened_at'] ?? 0 ) >= self::RECOVERY_TIMEOUT ) {
                return true; // 半開：允許一次嘗試
            }
            return false; // 熔斷中
        }

        return true;
    }

    /**
     * 記錄成功 — 重置熔斷器
     *
     * @return void
     */
    public static function record_success(): void {
        delete_site_transient( self::TRANSIENT_KEY );
    }

    /**
     * 記錄失敗 — 累積失敗次數，超過門檻觸發熔斷
     *
     * @return void
     */
    public static function record_failure(): void {
        $state = get_site_transient( self::TRANSIENT_KEY );

        if ( ! $state || ! is_array( $state ) ) {
            $state = array(
                'failures' => 0,
                'status'   => 'closed',
            );
        }

        $state['failures']++;
        $state['last_failure'] = time();

        if ( $state['failures'] >= self::FAILURE_THRESHOLD ) {
            $state['status']    = 'open';
            $state['opened_at'] = time();
            YSHubClientLogRepo::warning( 'circuit_breaker', sprintf( '熔斷器觸發（連續失敗 %d 次），暫停 Hub 連線 30 分鐘', $state['failures'] ) );
        }

        set_site_transient( self::TRANSIENT_KEY, $state, DAY_IN_SECONDS );
    }

    /**
     * 取得目前熔斷器狀態
     *
     * @return string 'closed' | 'open' | 'half_open'
     */
    public static function get_state(): string {
        $state = get_site_transient( self::TRANSIENT_KEY );

        if ( ! $state ) {
            return 'closed';
        }

        if ( 'open' === ( $state['status'] ?? '' ) ) {
            if ( time() - ( $state['opened_at'] ?? 0 ) >= self::RECOVERY_TIMEOUT ) {
                return 'half_open';
            }
            return 'open';
        }

        return $state['status'] ?? 'closed';
    }

    /**
     * 取得狀態的中文標籤
     *
     * @return string
     */
    public static function get_state_label(): string {
        $state = self::get_state();

        switch ( $state ) {
            case 'closed':
                return __( '正常', 'ys-plugin-hub-client' );
            case 'open':
                return __( '熔斷中', 'ys-plugin-hub-client' );
            case 'half_open':
                return __( '嘗試恢復', 'ys-plugin-hub-client' );
            default:
                return __( '未知', 'ys-plugin-hub-client' );
        }
    }

    /**
     * 強制重置熔斷器
     *
     * @return void
     */
    public static function reset(): void {
        delete_site_transient( self::TRANSIENT_KEY );
    }
}
