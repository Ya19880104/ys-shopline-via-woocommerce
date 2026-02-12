<?php
/**
 * Order Meta Keys
 *
 * @package YangSheep\ShoplinePayment\Utils
 */

declare(strict_types=1);

namespace YangSheep\ShoplinePayment\Utils;

defined( 'ABSPATH' ) || exit;

/**
 * 訂單 Meta Key 常數類別
 */
final class YSOrderMeta {

    /** @var string 交易訂單 ID */
    public const TRADE_ORDER_ID = '_ys_shopline_trade_order_id';

    /** @var string Session ID */
    public const SESSION_ID = '_ys_shopline_session_id';

    /** @var string 付款方式 */
    public const PAYMENT_METHOD = '_ys_shopline_payment_method';

    /** @var string 付款詳情 */
    public const PAYMENT_DETAIL = '_ys_shopline_payment_detail';

    /** @var string 退款詳情 */
    public const REFUND_DETAIL = '_ys_shopline_refund_detail';

    /** @var string Next Action */
    public const NEXT_ACTION = '_ys_shopline_next_action';

    /**
     * 根據交易訂單 ID 取得 WC 訂單
     *
     * @param string $trade_order_id 交易訂單 ID
     * @return \WC_Order|null
     */
    public static function get_order_by_trade_order_id( string $trade_order_id ): ?\WC_Order {
        $orders = wc_get_orders( [
            'meta_key'   => self::TRADE_ORDER_ID,
            'meta_value' => $trade_order_id,
            'limit'      => 1,
        ] );

        return ! empty( $orders ) ? $orders[0] : null;
    }

    /**
     * 儲存付款詳情到訂單
     *
     * @param \WC_Order $order        訂單物件
     * @param array     $payment_data 付款資料
     * @return void
     */
    public static function save_payment_detail( \WC_Order $order, array $payment_data ): void {
        if ( isset( $payment_data['tradeOrderId'] ) ) {
            $order->update_meta_data( self::TRADE_ORDER_ID, $payment_data['tradeOrderId'] );
        }

        if ( isset( $payment_data['sessionId'] ) ) {
            $order->update_meta_data( self::SESSION_ID, $payment_data['sessionId'] );
        }

        if ( isset( $payment_data['paymentMethod'] ) ) {
            $order->update_meta_data( self::PAYMENT_METHOD, $payment_data['paymentMethod'] );
        }

        $order->update_meta_data( self::PAYMENT_DETAIL, $payment_data );
        $order->save();
    }

    /**
     * 取得訂單的付款詳情
     *
     * @param \WC_Order $order 訂單物件
     * @return array
     */
    public static function get_payment_detail( \WC_Order $order ): array {
        $detail = $order->get_meta( self::PAYMENT_DETAIL );
        return is_array( $detail ) ? $detail : [];
    }
}
