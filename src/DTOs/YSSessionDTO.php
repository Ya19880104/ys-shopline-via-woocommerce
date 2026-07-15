<?php
/**
 * Session DTO
 *
 * @package YangSheep\ShoplinePayment\DTOs
 */

declare(strict_types=1);

namespace YangSheep\ShoplinePayment\DTOs;

use YangSheep\ShoplinePayment\Utils\YSTradeStatus;

defined( 'ABSPATH' ) || exit;

/**
 * 結帳交易 Session 資料傳輸物件
 */
final class YSSessionDTO {

    /**
     * Constructor
     *
     * @param string      $session_id   Session ID
     * @param string      $session_url  跳轉 URL
     * @param string      $status       狀態
     * @param string|null $trade_order_id 交易訂單 ID
     * @param array       $raw_data     原始回應資料
     */
    public function __construct(
        public readonly string $session_id,
        public readonly string $session_url,
        public readonly string $status,
        public readonly ?string $trade_order_id = null,
        public readonly array $raw_data = []
    ) {}

    /**
     * 從 API 回應建立
     *
     * @param array<string, mixed> $response API 回應資料
     * @return self
     * @throws \Exception 如果回應資料不完整
     */
    public static function from_response( array $response ): self {
        if ( empty( $response['sessionId'] ) ) {
            throw new \Exception( '回應缺少 sessionId' );
        }

        // v3.5.36 Review P1（覆核五輪）：一律經共用嚴格 selector 從 paymentDetails 產生交易 ID——
        // root tradeOrderId **不得**優先繞過安全判斷（否則多筆 active／malformed 時會用 root
        // 遮蔽 selector 的 fail-closed，把較低風險或錯的那筆寫進訂單 meta）。
        // selector 已對「盲取 [0]／多筆 active／未知／malformed」全數 fail-closed。
        $details        = ( isset( $response['paymentDetails'] ) && is_array( $response['paymentDetails'] ) ) ? $response['paymentDetails'] : array();
        $selected       = YSTradeStatus::select_representative_trade_id( $details );
        $trade_order_id = ( '' !== $selected ) ? $selected : null;

        return new self(
            session_id: $response['sessionId'],
            session_url: $response['sessionUrl'] ?? '',
            status: $response['status'] ?? '',
            trade_order_id: $trade_order_id,
            raw_data: $response
        );
    }

    /**
     * 是否已過期
     *
     * @return bool
     */
    public function is_expired(): bool {
        return 'EXPIRED' === strtoupper( $this->status );
    }

    /**
     * 是否成功
     *
     * @return bool
     */
    public function is_succeeded(): bool {
        return 'SUCCEEDED' === strtoupper( $this->status );
    }

    /**
     * 是否待處理
     *
     * @return bool
     */
    public function is_pending(): bool {
        return 'PENDING' === strtoupper( $this->status );
    }

    /**
     * 轉換為陣列
     *
     * @return array<string, mixed>
     */
    public function to_array(): array {
        return [
            'sessionId'    => $this->session_id,
            'sessionUrl'   => $this->session_url,
            'status'       => $this->status,
            'tradeOrderId' => $this->trade_order_id,
        ];
    }
}
