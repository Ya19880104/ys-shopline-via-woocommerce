<?php
/**
 * Payment DTO
 *
 * @package YangSheep\ShoplinePayment\DTOs
 */

declare(strict_types=1);

namespace YangSheep\ShoplinePayment\DTOs;

defined( 'ABSPATH' ) || exit;

/**
 * 付款交易資料傳輸物件
 */
final class YSPaymentDTO {

    /**
     * Constructor
     *
     * @param string      $trade_order_id    交易訂單 ID
     * @param string      $status            狀態
     * @param string|null $sub_status        子狀態
     * @param string|null $payment_method    付款方式
     * @param array|null  $amount            金額資訊
     * @param array|null  $payment_detail    付款詳情
     * @param array|null  $payment_instrument 付款工具資訊
     * @param array       $raw_data          原始回應資料
     */
    public function __construct(
        public readonly string $trade_order_id,
        public readonly string $status,
        public readonly ?string $sub_status = null,
        public readonly ?string $payment_method = null,
        public readonly ?array $amount = null,
        public readonly ?array $payment_detail = null,
        public readonly ?array $payment_instrument = null,
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
        $trade_order_id = $response['tradeOrderId'] ?? '';
        if ( empty( $trade_order_id ) ) {
            throw new \Exception( '回應缺少 tradeOrderId' );
        }

        $payment_data = isset( $response['payment'] ) && is_array( $response['payment'] )
            ? $response['payment']
            : [];

        return new self(
            trade_order_id: $trade_order_id,
            status: $response['status'] ?? '',
            sub_status: $response['subStatus'] ?? null,
            payment_method: $response['paymentMethod'] ?? ( $payment_data['paymentMethod'] ?? null ),
            amount: $response['amount'] ?? ( $response['order']['amount'] ?? ( $payment_data['paidAmount'] ?? null ) ),
            payment_detail: $response['paymentDetail'] ?? $payment_data,
            payment_instrument: $response['paymentInstrument'] ?? ( $payment_data['paymentInstrument'] ?? null ),
            raw_data: $response
        );
    }

    /**
     * 從訂單 Meta 建立
     *
     * @param \WC_Order $order 訂單物件
     * @return self|null
     */
    public static function from_order( \WC_Order $order ): ?self {
        $payment_detail = $order->get_meta( '_ys_shopline_payment_detail' );

        if ( empty( $payment_detail ) || ! is_array( $payment_detail ) ) {
            return null;
        }

        return self::from_response( $payment_detail );
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
     * 是否待處理
     *
     * @return bool
     */
    public function is_pending(): bool {
        return 'PENDING' === strtoupper( $this->status );
    }

    /**
     * 取得付款方式顯示名稱
     *
     * @return string
     */
    public function get_payment_method_display(): string {
        $methods = [
            'CreditCard'    => '信用卡',
            'LinePay'       => 'LINE Pay',
            'JkoPay'        => '街口支付',
            'ApplePay'      => 'Apple Pay',
            'VirtualAtm'    => '虛擬帳號',
            'ChaileaseBnpl' => '中租零卡分期',
        ];

        return $methods[ $this->payment_method ] ?? $this->payment_method ?? '未知';
    }

    /**
     * 轉換為陣列
     *
     * @return array<string, mixed>
     */
    public function to_array(): array {
        return [
            'tradeOrderId'      => $this->trade_order_id,
            'status'            => $this->status,
            'subStatus'         => $this->sub_status,
            'paymentMethod'     => $this->payment_method,
            'amount'            => $this->amount,
            'paymentDetail'     => $this->payment_detail,
            'paymentInstrument' => $this->payment_instrument,
        ];
    }

    /**
     * 轉換為人類可讀的 HTML
     *
     * @return string
     */
    public function to_human_html(): string {
        $html = '<div class="ys-shopline-payment-detail">';
        $html .= '<h4>Shopline Payment 付款資訊</h4>';
        $html .= '<table class="widefat">';
        $html .= '<tr><th>交易編號</th><td>' . esc_html( $this->trade_order_id ) . '</td></tr>';
        $html .= '<tr><th>狀態</th><td>' . esc_html( $this->status ) . '</td></tr>';
        $html .= '<tr><th>付款方式</th><td>' . esc_html( $this->get_payment_method_display() ) . '</td></tr>';

        if ( $this->amount ) {
            $amount_dto = new YSAmountDTO( $this->amount['value'] ?? 0, $this->amount['currency'] ?? 'TWD' );
            $html .= '<tr><th>金額</th><td>' . $amount_dto->to_display() . '</td></tr>';
        }

        // 信用卡資訊
        if ( $this->payment_instrument && isset( $this->payment_instrument['instrumentCard'] ) ) {
            $card = $this->payment_instrument['instrumentCard'];
            $card_info = sprintf(
                '%s •••• %s',
                $card['brand'] ?? '',
                $card['last'] ?? ''
            );
            $html .= '<tr><th>卡片資訊</th><td>' . esc_html( $card_info ) . '</td></tr>';
        }

        $html .= '</table>';
        $html .= '</div>';

        return $html;
    }
}
