<?php
/**
 * Payment Instrument DTO
 *
 * @package YangSheep\ShoplinePayment\DTOs
 */

declare(strict_types=1);

namespace YangSheep\ShoplinePayment\DTOs;

defined( 'ABSPATH' ) || exit;

/**
 * 付款工具（Token 卡片）資料傳輸物件
 */
final class YSPaymentInstrumentDTO {

    /**
     * Constructor
     *
     * @param string      $instrument_id     付款工具 ID
     * @param string      $instrument_type   付款工具類型
     * @param string      $instrument_status 付款工具狀態
     * @param array|null  $instrument_card   卡片資訊
     * @param array       $raw_data          原始回應資料
     */
    public function __construct(
        public readonly string $instrument_id,
        public readonly string $instrument_type,
        public readonly string $instrument_status,
        public readonly ?array $instrument_card = null,
        public readonly array $raw_data = []
    ) {}

    /**
     * 從 API 回應建立
     *
     * @param array<string, mixed> $response API 回應資料
     * @return self
     */
    public static function from_response( array $response ): self {
        // 支援多種 API 回傳格式
        // API 可能回傳 instrumentId 或 paymentInstrumentId
        $instrument_id = $response['instrumentId']
            ?? $response['paymentInstrumentId']
            ?? '';

        return new self(
            instrument_id: $instrument_id,
            instrument_type: $response['instrumentType'] ?? '',
            instrument_status: $response['instrumentStatus'] ?? '',
            instrument_card: $response['instrumentCard'] ?? null,
            raw_data: $response
        );
    }

    /**
     * 從 API 回應陣列建立多個物件
     *
     * @param array<int, array<string, mixed>> $instruments 付款工具陣列
     * @return array<int, self>
     */
    public static function from_response_array( array $instruments ): array {
        return array_map(
            fn( array $item ) => self::from_response( $item ),
            $instruments
        );
    }

    /**
     * 是否啟用
     *
     * @return bool
     */
    public function is_enabled(): bool {
        return 'ENABLED' === strtoupper( $this->instrument_status );
    }

    /**
     * 是否為信用卡
     *
     * @return bool
     */
    public function is_credit_card(): bool {
        return 'CreditCard' === $this->instrument_type;
    }

    /**
     * 是否已過期
     *
     * @return bool
     */
    public function is_expired(): bool {
        if ( ! $this->instrument_card ) {
            return false;
        }

        return $this->instrument_card['expired'] ?? false;
    }

    /**
     * 取得卡片品牌
     *
     * @return string
     */
    public function get_card_brand(): string {
        return $this->instrument_card['brand'] ?? '';
    }

    /**
     * 取得卡號後四碼
     *
     * @return string
     */
    public function get_card_last_four(): string {
        return $this->instrument_card['last'] ?? '';
    }

    /**
     * 取得卡片到期日
     *
     * @return string 格式：MM/YYYY
     */
    public function get_card_expiry(): string {
        if ( ! $this->instrument_card ) {
            return '';
        }

        // 支援多種欄位名稱
        $month = $this->instrument_card['expireMonth']
            ?? $this->instrument_card['expiryMonth']
            ?? '';
        $year  = $this->instrument_card['expireYear']
            ?? $this->instrument_card['expiryYear']
            ?? '';

        if ( ! $month || ! $year ) {
            return '';
        }

        // 處理兩位數年份
        if ( strlen( (string) $year ) === 2 ) {
            $year = '20' . $year;
        }

        return sprintf( '%02d/%s', (int) $month, $year );
    }

    /**
     * 取得卡片類型
     *
     * @return string DEBIT/CREDIT
     */
    public function get_card_type(): string {
        return $this->instrument_card['type'] ?? '';
    }

    /**
     * 取得顯示名稱
     *
     * @return string 例如：Mastercard •••• 6378
     */
    public function get_display_name(): string {
        $brand = $this->get_card_brand();
        $last  = $this->get_card_last_four();

        if ( ! $brand || ! $last ) {
            return $this->instrument_type;
        }

        return sprintf( '%s •••• %s', $brand, $last );
    }

    /**
     * 取得狀態顯示文字
     *
     * @return string
     */
    public function get_status_display(): string {
        if ( $this->is_expired() ) {
            return '已過期';
        }

        $statuses = [
            'ENABLED'  => '有效',
            'DISABLED' => '停用',
            'CREATED'  => '建立中',
        ];

        return $statuses[ strtoupper( $this->instrument_status ) ] ?? $this->instrument_status;
    }

    /**
     * 轉換為陣列
     *
     * @return array<string, mixed>
     */
    public function to_array(): array {
        return [
            'instrumentId'     => $this->instrument_id,
            'instrumentType'   => $this->instrument_type,
            'instrumentStatus' => $this->instrument_status,
            'instrumentCard'   => $this->instrument_card,
        ];
    }
}
