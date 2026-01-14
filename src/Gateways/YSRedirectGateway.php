<?php
/**
 * Redirect Gateway
 *
 * @package YangSheep\ShoplinePayment\Gateways
 */

declare(strict_types=1);

namespace YangSheep\ShoplinePayment\Gateways;

use YangSheep\ShoplinePayment\DTOs\YSPaymentDTO;
use YangSheep\ShoplinePayment\Utils\YSLogger;
use YangSheep\ShoplinePayment\Utils\YSOrderMeta;

defined( 'ABSPATH' ) || exit;

/**
 * 跳轉支付閘道
 *
 * 支援信用卡、LINE Pay、街口支付、Apple Pay、虛擬帳號等
 */
final class YSRedirectGateway extends YSAbstractGateway {

    /** @var string 閘道 ID */
    public const ID = 'ys_shopline_redirect';

    /**
     * Constructor
     */
    public function __construct() {
        $this->id                 = self::ID;
        $this->method_title       = __( 'Shopline Payment 線上付款', 'ys-shopline-payment' );
        $this->method_description = __( '提供信用卡、LINE Pay、街口支付、Apple Pay、虛擬帳號等多種付款方式', 'ys-shopline-payment' );
        $this->has_fields         = false;

        parent::__construct();

        // 訂單完成頁處理
        add_action( 'woocommerce_before_thankyou', [ $this, 'handle_return_from_payment' ] );

        // 後台訂單詳情顯示
        add_action( 'woocommerce_admin_order_data_after_billing_address', [ $this, 'display_payment_detail_in_admin' ] );
    }

    /**
     * 付款前處理
     *
     * @param \WC_Order $order 訂單物件
     * @return string 跳轉 URL
     * @throws \Exception 如果建立交易失敗
     */
    protected function before_process_payment( \WC_Order $order ): string {
        $client     = $this->get_api_client( $order );
        $return_url = $this->get_return_url( $order );

        // 建立 Session
        $session_dto = $client->create_session( $order, $return_url );

        // 檢查是否已過期
        if ( $session_dto->is_expired() ) {
            $order->add_order_note( __( '已超過 Shopline Payment 付款期限，請重新下單', 'ys-shopline-payment' ) );
            $order->update_status( 'cancelled' );
            throw new \Exception( __( '已超過付款期限，請重新下單', 'ys-shopline-payment' ) );
        }

        // 儲存 Session ID
        $order->update_meta_data( YSOrderMeta::SESSION_ID, $session_dto->session_id );
        $order->save();

        YSLogger::info( "建立 Session 成功", [
            'order_id'   => $order->get_id(),
            'session_id' => $session_dto->session_id,
        ] );

        // 清空購物車
        WC()->cart->empty_cart();

        // 返回跳轉 URL
        return $session_dto->session_url;
    }

    /**
     * 處理從付款頁面返回
     *
     * @param int $order_id 訂單 ID
     */
    public function handle_return_from_payment( $order_id ): void {
        $order = wc_get_order( $order_id );

        if ( ! $order || $order->get_payment_method() !== $this->id ) {
            return;
        }

        // 從 URL 取得 tradeOrderId
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $trade_order_id = isset( $_GET['tradeOrderId'] ) ? sanitize_text_field( wp_unslash( $_GET['tradeOrderId'] ) ) : '';

        if ( empty( $trade_order_id ) ) {
            return;
        }

        try {
            // 檢查是否已處理過
            $saved_trade_order_id = $order->get_meta( YSOrderMeta::TRADE_ORDER_ID );

            if ( $saved_trade_order_id === $trade_order_id ) {
                return; // 已處理過
            }

            // 儲存交易訂單 ID
            $order->update_meta_data( YSOrderMeta::TRADE_ORDER_ID, $trade_order_id );
            $order->save();

            YSLogger::info( "訂單返回處理完成", [
                'order_id'       => $order_id,
                'trade_order_id' => $trade_order_id,
            ] );
        } catch ( \Throwable $e ) {
            YSLogger::error( "訂單返回處理失敗: {$e->getMessage()}", [
                'order_id' => $order_id,
            ] );
        }
    }

    /**
     * 在後台訂單詳情顯示付款資訊
     *
     * @param \WC_Order $order 訂單物件
     */
    public function display_payment_detail_in_admin( \WC_Order $order ): void {
        if ( $order->get_payment_method() !== $this->id ) {
            return;
        }

        $payment_dto = YSPaymentDTO::from_order( $order );

        if ( $payment_dto ) {
            echo $payment_dto->to_human_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        } else {
            // 顯示基本資訊
            $trade_order_id = $order->get_meta( YSOrderMeta::TRADE_ORDER_ID );
            $session_id     = $order->get_meta( YSOrderMeta::SESSION_ID );

            if ( $trade_order_id || $session_id ) {
                echo '<div class="ys-shopline-payment-detail">';
                echo '<h4>Shopline Payment 資訊</h4>';
                echo '<table class="widefat">';

                if ( $trade_order_id ) {
                    echo '<tr><th>交易編號</th><td>' . esc_html( $trade_order_id ) . '</td></tr>';
                }

                if ( $session_id ) {
                    echo '<tr><th>Session ID</th><td>' . esc_html( $session_id ) . '</td></tr>';
                }

                echo '</table>';
                echo '</div>';
            }
        }
    }

    /**
     * 初始化
     */
    public static function init(): void {
        // 註冊閘道
        add_filter( 'woocommerce_payment_gateways', function ( array $gateways ): array {
            $gateways[] = self::class;
            return $gateways;
        } );
    }
}
