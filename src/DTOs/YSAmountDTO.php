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
 */
final class YSAmountDTO {

    /**
     * Constructor
     *
     * @param int    $value    金額（最小單位，如分）
     * @param string $currency 幣別代碼
     */
    public function __construct(
        public readonly int $value,
        public readonly string $currency = 'TWD'
    ) {}

    /**
     * 從 WC_Order 建立
     *
     * @param \WC_Order $order 訂單物件
     * @return self
     */
    public static function from_order( \WC_Order $order ): self {
        $total    = $order->get_total();
        $currency = $order->get_currency();
        $value    = self::format_amount( (float) $total, $currency );

        return new self( $value, $currency );
    }

    /**
     * 從金額數值建立
     *
     * @param float  $amount   金額
     * @param string $currency 幣別
     * @return self
     */
    public static function from_amount( float $amount, string $currency = 'TWD' ): self {
        $value = self::format_amount( $amount, $currency );
        return new self( $value, $currency );
    }

    /**
     * 格式化金額為最小單位
     *
     * @param float  $amount   金額
     * @param string $currency 幣別
     * @return int
     */
    public static function format_amount( float $amount, string $currency ): int {
        // 零小數幣別
        $zero_decimal_currencies = [ 'TWD', 'JPY', 'KRW', 'VND' ];

        if ( in_array( strtoupper( $currency ), $zero_decimal_currencies, true ) ) {
            return (int) round( $amount );
        }

        // 其他幣別轉換為最小單位（分）
        return (int) round( $amount * 100 );
    }

    /**
     * 轉換為陣列
     *
     * @return array<string, mixed>
     */
    public function to_array(): array {
        return [
            'value'    => $this->value,
            'currency' => $this->currency,
        ];
    }

    /**
     * 取得顯示用金額
     *
     * @return string
     */
    public function to_display(): string {
        $zero_decimal_currencies = [ 'TWD', 'JPY', 'KRW', 'VND' ];

        if ( in_array( strtoupper( $this->currency ), $zero_decimal_currencies, true ) ) {
            $amount = $this->value;
        } else {
            $amount = $this->value / 100;
        }

        return wc_price( $amount, [ 'currency' => $this->currency ] );
    }
}
