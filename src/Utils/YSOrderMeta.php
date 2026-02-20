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

    /** @var string 付款狀態 */
    public const PAYMENT_STATUS = '_ys_shopline_payment_status';

    /** @var string 退款詳情 */
    public const REFUND_DETAIL = '_ys_shopline_refund_detail';

    /** @var string Next Action */
    public const NEXT_ACTION = '_ys_shopline_next_action';

    /** @var string Shopline Customer ID（user meta / subscription meta 共用） */
    public const CUSTOMER_ID = '_ys_shopline_customer_id';

    /** @var string 付款工具 ID（subscription meta） */
    public const PAYMENT_INSTRUMENT_ID = '_ys_shopline_payment_instrument_id';

    /** @var string 付款嘗試次數 */
    public const PAYMENT_ATTEMPT = '_ys_shopline_payment_attempt';

    /** @var string Reference Order ID */
    public const REFERENCE_ORDER_ID = '_ys_shopline_reference_order_id';

    /** @var string 信用卡末四碼 */
    public const CARD_LAST4 = '_ys_shopline_card_last4';

    /** @var string 信用卡品牌 */
    public const CARD_BRAND = '_ys_shopline_card_brand';

    /** @var string 錯誤代碼 */
    public const ERROR_CODE = '_ys_shopline_error_code';

    /** @var string 錯誤訊息 */
    public const ERROR_MESSAGE = '_ys_shopline_error_message';

    /** @var string ATM 銀行代碼 */
    public const VA_BANK_CODE = '_ys_shopline_va_bank_code';

    /** @var string ATM 虛擬帳號 */
    public const VA_ACCOUNT = '_ys_shopline_va_account';

    /** @var string ATM 繳費期限 */
    public const VA_EXPIRE = '_ys_shopline_va_expire';

    /** @var string 信用卡分期期數 */
    public const INSTALLMENT = '_ys_shopline_installment';

    /** @var string BNPL 分期期數 */
    public const BNPL_INSTALLMENT = '_ys_shopline_bnpl_installment';

    /** @var string 待綁定付款工具 */
    public const PENDING_BIND = '_ys_shopline_pending_bind';

    /** @var string 新增付款方式 next action */
    public const ADD_METHOD_NEXT_ACTION = '_ys_shopline_add_method_next_action';

    /** @var string 付款工具快取（user meta） */
    public const INSTRUMENTS_CACHE = '_ys_shopline_instruments_cache';

    /** @var string Token 的 instrument ID（token meta） */
    public const TOKEN_INSTRUMENT_ID = '_ys_shopline_instrument_id';

    /** @var string 信用卡 Token 統一 gateway ID */
    public const CREDIT_GATEWAY_ID = 'ys_shopline_credit';

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
