<?php
/**
 * Amount DTO
 *
 * @package YangSheep\ShoplinePayment\DTOs
 */

declare(strict_types=1);

namespace YangSheep\ShoplinePayment\DTOs;

defined( 'ABSPATH' ) || exit;

/**
 * 金額資料傳輸物件
 *
 * 用於解析 API 回應中的金額並提供顯示功能。
 * 注意：Shopline API 所有幣別（含 TWD）的 value 都是 ×100 格式。
 */
final class YSAmountDTO {

    /**
     * Constructor
     *
     * @param int    $value    金額（API 原始值，所有幣別皆為 ×100）
     * @param string $currency 幣別代碼
     */
    public function __construct(
        public readonly int $value,
        public readonly string $currency = 'TWD'
    ) {}

    /**
     * 取得顯示用金額
     *
     * @return string
     */
    public function to_display(): string {
        // Shopline API 所有幣別的 value 都是 ×100
        $amount = $this->value / 100;

        return wc_price( $amount, [ 'currency' => $this->currency ] );
    }
}
