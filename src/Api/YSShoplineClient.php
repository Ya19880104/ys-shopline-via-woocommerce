<?php
/**
 * Shopline API Client
 *
 * @package YangSheep\ShoplinePayment\Api
 */

declare(strict_types=1);

namespace YangSheep\ShoplinePayment\Api;

use YangSheep\ShoplinePayment\DTOs\YSSessionDTO;
use YangSheep\ShoplinePayment\DTOs\YSPaymentDTO;

defined( 'ABSPATH' ) || exit;

/**
 * Shopline Payment API 客戶端
 *
 * 提供 YSStatusManager 所需的查詢與取消操作
 */
final class YSShoplineClient {

    /** @var YSShoplineRequester */
    private YSShoplineRequester $requester;

    /**
     * Constructor
     */
    public function __construct() {
        $this->requester = new YSShoplineRequester();
    }

    // ==========================================
    // Session API
    // ==========================================

    /**
     * 查詢結帳交易 Session
     *
     * @param string $session_id Session ID
     * @return YSSessionDTO
     * @throws \Exception 如果查詢失敗
     *
     * @see https://docs.shoplinepayments.com/api/trade/sessionQuery/
     */
    public function query_session( string $session_id ): YSSessionDTO {
        $response = $this->requester->post( '/trade/sessions/query', [
            'sessionId' => $session_id,
        ] );

        return YSSessionDTO::from_response( $response );
    }

    // ==========================================
    // Payment API
    // ==========================================

    /**
     * 查詢付款交易
     *
     * @param string $trade_order_id 交易訂單 ID
     * @return YSPaymentDTO
     * @throws \Exception 如果查詢失敗
     *
     * @see https://docs.shoplinepayments.com/api/trade/query/
     */
    public function query_payment( string $trade_order_id ): YSPaymentDTO {
        $response = $this->requester->post( '/trade/payment/get', [
            'tradeOrderId' => $trade_order_id,
        ] );

        return YSPaymentDTO::from_response( $response );
    }

    /**
     * 取消付款交易
     *
     * @param string $trade_order_id      交易訂單 ID
     * @param string $reference_order_id  特店訂單號
     * @return array<string, mixed>
     * @throws \Exception 如果取消失敗
     *
     * @see https://docs.shoplinepayments.com/api/trade/cancel/
     */
    public function cancel_payment( string $trade_order_id, string $reference_order_id = '' ): array {
        $data = [
            'tradeOrderId' => $trade_order_id,
        ];

        if ( '' !== $reference_order_id ) {
            $data['referenceOrderId'] = $reference_order_id;
        }

        return $this->requester->post( '/trade/payment/cancel', $data );
    }

    // ==========================================
    // Utility
    // ==========================================

    /**
     * 檢查 API 憑證是否已設定
     *
     * @return bool
     */
    public function has_credentials(): bool {
        return $this->requester->has_credentials();
    }
}
