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

    /** @var string Refund API attempt counter. */
    public const REFUND_ATTEMPT = '_ys_shopline_refund_attempt';

    /** @var string Next Action */
    public const NEXT_ACTION = '_ys_shopline_next_action';

    /** @var string Shopline Customer ID（user meta / subscription meta 共用） */
    public const CUSTOMER_ID = '_ys_shopline_customer_id';

    /** @var string 付款工具 ID（subscription meta） */
    public const PAYMENT_INSTRUMENT_ID = '_ys_shopline_payment_instrument_id';

    /** @var string 本次付款是否曾要求 SHOPLINE 儲存新卡 */
    public const BIND_CARD_ATTEMPTED = '_ys_shopline_bind_card_attempted';

    /** @var string SHOPLINE 付款成功但未回傳 paymentInstrumentId */
    public const BIND_CARD_NOT_PERSISTED = '_ys_shopline_bind_card_not_persisted';

    /** @var string SHOPLINE 已授權但尚未 capture (PROCESSING/AUTHORIZED 中間態) v3.5.11 */
    public const PAYMENT_AUTHORIZED_PENDING = '_ys_shopline_payment_authorized_pending';

    /** @var string Redirect return payment completion claim lock */
    public const PAYMENT_COMPLETE_LOCK = '_ys_shopline_payment_complete_lock';

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

    /** @var string 重新付款時棄用的前次交易 ID 清單（v3.5.35：顧客未完成態不支援取消 API，棄用歸檔供 webhook 溯源） */
    public const ABANDONED_TRADE_IDS = '_ys_shopline_abandoned_trade_ids';

    /** @var string 已棄用但顧客事後仍完成收款的交易 ID 清單（v3.5.35：重複收款警示，需人工退款） */
    public const ABANDONED_TRADE_PAID_IDS = '_ys_shopline_abandoned_trade_paid_ids';

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

    /** @var array<int, string> Card BIN/IIN keys removed before storing payment detail snapshots. */
    private const CARD_BIN_KEYS = [
        'bin',
        'cardbin',
        'card_bin',
        'issuerbin',
        'issuer_bin',
        'iin',
        'cardiin',
        'card_iin',
    ];

    /**
     * Remove card BIN/IIN values from payment detail snapshots before storage/display.
     *
     * @param array<string|int, mixed> $payment_data Payment detail data.
     * @return array<string|int, mixed>
     */
    public static function sanitize_payment_detail( array $payment_data ): array {
        $sanitized = [];

        foreach ( $payment_data as $key => $value ) {
            if ( is_string( $key ) && self::is_card_bin_key( $key ) ) {
                continue;
            }

            $sanitized[ $key ] = is_array( $value )
                ? self::sanitize_payment_detail( $value )
                : $value;
        }

        return $sanitized;
    }

    /**
     * Determine whether a response key contains a card BIN/IIN value.
     *
     * @param string $key Response key.
     * @return bool
     */
    private static function is_card_bin_key( string $key ): bool {
        return in_array( strtolower( $key ), self::CARD_BIN_KEYS, true );
    }

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
        $payment_data = self::sanitize_payment_detail( $payment_data );

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
        return is_array( $detail ) ? self::sanitize_payment_detail( $detail ) : [];
    }
}
