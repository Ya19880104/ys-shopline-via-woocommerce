<?php
/**
 * Customer Manager
 *
 * @package YangSheep\ShoplinePayment\Customer
 */

declare(strict_types=1);

namespace YangSheep\ShoplinePayment\Customer;

use YangSheep\ShoplinePayment\Api\YSShoplineClient;
use YangSheep\ShoplinePayment\DTOs\YSCustomerDTO;
use YangSheep\ShoplinePayment\Utils\YSLogger;

defined( 'ABSPATH' ) || exit;

/**
 * 會員管理類別
 *
 * 處理 WordPress 用戶與 Shopline Customer 的對應關係
 */
final class YSCustomerManager {

    /** @var string User Meta Key - Shopline Customer ID */
    public const META_CUSTOMER_ID = '_ys_shopline_customer_id';

    /** @var string User Meta Key - 付款工具快取 */
    public const META_INSTRUMENTS_CACHE = '_ys_shopline_instruments_cache';

    /** @var int 快取有效時間（秒）- 1 小時 */
    private const CACHE_TTL = 3600;

    /** @var YSShoplineClient */
    private YSShoplineClient $client;

    /**
     * Constructor
     */
    public function __construct() {
        $this->client = new YSShoplineClient();
    }

    /**
     * 取得或建立 Shopline Customer ID
     *
     * @param int $user_id WordPress 用戶 ID
     * @return string|null Customer ID 或 null（如果失敗）
     */
    public function get_or_create_customer_id( int $user_id ): ?string {
        // 先嘗試從 User Meta 取得
        $customer_id = get_user_meta( $user_id, self::META_CUSTOMER_ID, true );

        if ( ! empty( $customer_id ) ) {
            return $customer_id;
        }

        // 建立新的 Customer
        try {
            $customer_dto = $this->client->create_customer( $user_id );
            $customer_id  = $customer_dto->customer_id;

            // 儲存到 User Meta
            update_user_meta( $user_id, self::META_CUSTOMER_ID, $customer_id );

            YSLogger::info( "建立 Shopline Customer 成功", [
                'user_id'     => $user_id,
                'customer_id' => $customer_id,
            ] );

            return $customer_id;
        } catch ( \Throwable $e ) {
            YSLogger::error( "建立 Shopline Customer 失敗: {$e->getMessage()}", [
                'user_id' => $user_id,
            ] );

            return null;
        }
    }

    /**
     * 取得 Shopline Customer ID（不建立）
     *
     * @param int $user_id WordPress 用戶 ID
     * @return string|null
     */
    public function get_customer_id( int $user_id ): ?string {
        $customer_id = get_user_meta( $user_id, self::META_CUSTOMER_ID, true );
        return ! empty( $customer_id ) ? $customer_id : null;
    }

    /**
     * 儲存 Customer ID 到用戶
     *
     * @param int    $user_id     WordPress 用戶 ID
     * @param string $customer_id Shopline Customer ID
     * @return bool
     */
    public function save_customer_id( int $user_id, string $customer_id ): bool {
        return (bool) update_user_meta( $user_id, self::META_CUSTOMER_ID, $customer_id );
    }

    /**
     * 刪除用戶的 Customer ID
     *
     * @param int $user_id WordPress 用戶 ID
     * @return bool
     */
    public function delete_customer_id( int $user_id ): bool {
        $this->clear_instruments_cache( $user_id );
        return delete_user_meta( $user_id, self::META_CUSTOMER_ID );
    }

    /**
     * 取得用戶的付款工具（帶快取）
     *
     * @param int  $user_id     WordPress 用戶 ID
     * @param bool $force_fresh 是否強制刷新
     * @return array
     */
    public function get_payment_instruments( int $user_id, bool $force_fresh = false ): array {
        $customer_id = $this->get_customer_id( $user_id );

        if ( ! $customer_id ) {
            return [];
        }

        // 檢查快取
        if ( ! $force_fresh ) {
            $cached = $this->get_instruments_from_cache( $user_id );
            if ( false !== $cached ) {
                return $cached;
            }
        }

        // 從 API 取得
        try {
            $instruments = $this->client->query_payment_instruments( $customer_id, [
                'instrumentStatusList' => [ 'ENABLED', 'CREATED' ],
            ] );

            // 轉換為陣列格式以便快取
            $instruments_array = array_map(
                fn( $dto ) => $dto->to_array(),
                $instruments
            );

            // 儲存快取
            $this->save_instruments_to_cache( $user_id, $instruments_array );

            return $instruments_array;
        } catch ( \Throwable $e ) {
            YSLogger::error( "取得付款工具失敗: {$e->getMessage()}", [
                'user_id'     => $user_id,
                'customer_id' => $customer_id,
            ] );

            return [];
        }
    }

    /**
     * 解綁付款工具
     *
     * @param int    $user_id       WordPress 用戶 ID
     * @param string $instrument_id 付款工具 ID
     * @return bool
     */
    public function unbind_payment_instrument( int $user_id, string $instrument_id ): bool {
        $customer_id = $this->get_customer_id( $user_id );

        if ( ! $customer_id ) {
            return false;
        }

        try {
            $this->client->unbind_payment_instrument( $customer_id, $instrument_id );

            // 清除快取
            $this->clear_instruments_cache( $user_id );

            YSLogger::info( "解綁付款工具成功", [
                'user_id'       => $user_id,
                'instrument_id' => $instrument_id,
            ] );

            return true;
        } catch ( \Throwable $e ) {
            YSLogger::error( "解綁付款工具失敗: {$e->getMessage()}", [
                'user_id'       => $user_id,
                'instrument_id' => $instrument_id,
            ] );

            return false;
        }
    }

    /**
     * 從快取取得付款工具
     *
     * @param int $user_id WordPress 用戶 ID
     * @return array|false 快取資料或 false（如果快取無效）
     */
    private function get_instruments_from_cache( int $user_id ) {
        $cache = get_user_meta( $user_id, self::META_INSTRUMENTS_CACHE, true );

        if ( ! is_array( $cache ) || empty( $cache['cached_at'] ) ) {
            return false;
        }

        // 檢查快取是否過期
        if ( time() - $cache['cached_at'] > self::CACHE_TTL ) {
            return false;
        }

        return $cache['data'] ?? [];
    }

    /**
     * 儲存付款工具到快取
     *
     * @param int   $user_id     WordPress 用戶 ID
     * @param array $instruments 付款工具陣列
     */
    private function save_instruments_to_cache( int $user_id, array $instruments ): void {
        update_user_meta( $user_id, self::META_INSTRUMENTS_CACHE, [
            'data'      => $instruments,
            'cached_at' => time(),
        ] );
    }

    /**
     * 清除付款工具快取
     *
     * @param int $user_id WordPress 用戶 ID
     */
    public function clear_instruments_cache( int $user_id ): void {
        delete_user_meta( $user_id, self::META_INSTRUMENTS_CACHE );
    }

    /**
     * 檢查用戶是否有已儲存的付款工具
     *
     * @param int $user_id WordPress 用戶 ID
     * @return bool
     */
    public function has_saved_instruments( int $user_id ): bool {
        $instruments = $this->get_payment_instruments( $user_id );
        return ! empty( $instruments );
    }

    /**
     * 取得 API 客戶端
     *
     * @return YSShoplineClient
     */
    public function get_client(): YSShoplineClient {
        return $this->client;
    }
}
