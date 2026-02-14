<?php
/**
 * Refund DTO
 *
 * @package YangSheep\ShoplinePayment\DTOs
 */

declare(strict_types=1);

namespace YangSheep\ShoplinePayment\DTOs;

defined( 'ABSPATH' ) || exit;

/**
 * 退款資料傳輸物件
 */
final class YSRefundDTO {

    /**
     * Constructor
     *
     * @param string      $refund_order_id 退款訂單 ID
     * @param string      $trade_order_id  原交易訂單 ID
     * @param string      $status          狀態
     * @param array|null  $amount          退款金額
     * @param string|null $reason          退款原因
     * @param array|null  $refund_msg      退款訊息
     * @param array       $raw_data        原始回應資料
     */
    public function __construct(
        public readonly string $refund_order_id,
        public readonly string $trade_order_id,
        public readonly string $status,
        public readonly ?array $amount = null,
        public readonly ?string $reason = null,
        public readonly ?array $refund_msg = null,
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
        return new self(
            refund_order_id: $response['refundOrderId'] ?? '',
            trade_order_id: $response['tradeOrderId'] ?? '',
            status: $response['status'] ?? '',
            amount: $response['amount'] ?? null,
            reason: $response['reason'] ?? null,
            refund_msg: $response['refundMsg'] ?? null,
            raw_data: $response
        );
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
     * 是否失敗
     *
     * @return bool
     */
    public function is_failed(): bool {
        return 'FAILED' === strtoupper( $this->status );
    }

    /**
     * 是否有錯誤
     *
     * @return bool
     */
    public function has_error(): bool {
        return ! empty( $this->refund_msg['code'] );
    }

    /**
     * 取得錯誤訊息
     *
     * @return string
     */
    public function get_error_message(): string {
        if ( ! $this->has_error() ) {
            return '';
        }

        return sprintf(
            '[%s] %s',
            $this->refund_msg['code'] ?? '',
            $this->refund_msg['msg'] ?? '未知錯誤'
        );
    }

    /**
     * 轉換為陣列
     *
     * @return array<string, mixed>
     */
    public function to_array(): array {
        return [
            'refundOrderId' => $this->refund_order_id,
            'tradeOrderId'  => $this->trade_order_id,
            'status'        => $this->status,
            'amount'        => $this->amount,
            'reason'        => $this->reason,
            'refundMsg'     => $this->refund_msg,
        ];
    }

    /**
     * 轉換為人類可讀的 HTML
     *
     * @param string $reason 退款原因
     * @return string
     */
    public function to_human_html( string $reason = '' ): string {
        $status_text = $this->is_succeeded() ? '成功' : ( $this->is_failed() ? '失敗' : '處理中' );
        $status_class = $this->is_succeeded() ? 'success' : ( $this->is_failed() ? 'error' : 'pending' );

        $html = '<div class="ys-shopline-refund-detail">';
        $html .= '<h4>退款資訊</h4>';
        $html .= '<table class="widefat">';
        $html .= '<tr><th>退款編號</th><td>' . esc_html( $this->refund_order_id ) . '</td></tr>';
        $html .= '<tr><th>狀態</th><td class="' . esc_attr( $status_class ) . '">' . esc_html( $status_text ) . '</td></tr>';

        if ( $this->amount ) {
            $amount_dto = new YSAmountDTO( $this->amount['value'] ?? 0, $this->amount['currency'] ?? 'TWD' );
            $html .= '<tr><th>退款金額</th><td>' . $amount_dto->to_display() . '</td></tr>';
        }

        if ( $reason || $this->reason ) {
            $html .= '<tr><th>退款原因</th><td>' . esc_html( $reason ?: $this->reason ) . '</td></tr>';
        }

        if ( $this->has_error() ) {
            $html .= '<tr><th>錯誤訊息</th><td class="error">' . esc_html( $this->get_error_message() ) . '</td></tr>';
        }

        $html .= '</table>';
        $html .= '</div>';

        return $html;
    }
}
