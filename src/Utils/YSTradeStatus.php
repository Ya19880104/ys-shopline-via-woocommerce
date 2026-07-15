<?php
/**
 * SHOPLINE 交易狀態分類器
 *
 * @package YangSheep\ShoplinePayment\Utils
 */

declare(strict_types=1);

namespace YangSheep\ShoplinePayment\Utils;

defined( 'ABSPATH' ) || exit;

/**
 * 交易狀態共用分類（v3.5.35）
 *
 * 單一事實來源：YSStatusManager（取消閉環）與 YSGatewayBase（前次交易解決）
 * 皆以此類別判斷，避免各處硬編碼清單漂移。
 */
final class YSTradeStatus {

    /**
     * 終態且無收款風險：可安全視為「此交易已結束、金流不會再收款」。
     *
     * @var string[]
     */
    public const TERMINAL_SAFE = array( 'EXPIRED', 'FAILED', 'CANCELLED', 'CANCELED', 'REFUNDED' );

    /**
     * 已收款（或曾收款）風險狀態：訂單端取消／重付前必須人工或退款處理。
     *
     * @var string[]
     */
    public const PAID_RISK = array( 'SUCCEEDED', 'SUCCESS', 'CAPTURED', 'PARTIALLY_REFUND', 'PARTIALLY_REFUNDED' );

    /**
     * 顧客端未完成流程（尚未進入金流端請款）：關閉 Apple Pay／3DS 視窗、
     * 未完成 LINE Pay 授權等。此類交易可安全主動取消後開立新交易。
     *
     * @var string[]
     */
    public const CUSTOMER_PENDING = array( 'CREATED', 'CUSTOMER_ACTION' );

    /**
     * 在途（已進入金流端處理／授權，收款結果尚未確定）：非終態、也非顧客端可安全取消。
     * 供狀態同步「單筆在途可追蹤」，但接管入帳仍須 is_paid() 把關。
     *
     * @var string[]
     */
    public const IN_FLIGHT = array( 'PROCESSING', 'AUTHORIZED', 'PENDING' );

    /**
     * 是否為無收款風險終態
     *
     * @param string $status 交易狀態（不分大小寫）。
     * @return bool
     */
    public static function is_terminal_safe( string $status ): bool {
        return in_array( strtoupper( $status ), self::TERMINAL_SAFE, true );
    }

    /**
     * 是否為已收款（含部分退款）狀態
     *
     * @param string $status 交易狀態（不分大小寫）。
     * @return bool
     */
    public static function is_paid( string $status ): bool {
        return in_array( strtoupper( $status ), self::PAID_RISK, true );
    }

    /**
     * 是否為顧客端未完成流程（可安全主動取消）
     *
     * @param string $status 交易狀態（不分大小寫）。
     * @return bool
     */
    public static function is_customer_pending( string $status ): bool {
        return in_array( strtoupper( $status ), self::CUSTOMER_PENDING, true );
    }

    /**
     * 是否為在途（PROCESSING／AUTHORIZED／PENDING）
     *
     * @param string $status 交易狀態（不分大小寫）。
     * @return bool
     */
    public static function is_in_flight( string $status ): bool {
        return in_array( strtoupper( $status ), self::IN_FLIGHT, true );
    }

    /**
     * v3.5.36 P0/P1：從 paymentDetails 陣列挑出「代表本 session 的單一交易 ID」。
     *
     * 取代盲取 paymentDetails[0]（[FAILED old, SUCCEEDED new] 會誤選舊失敗筆）。單一事實來源，供
     * YSGatewayBase（indeterminate 接管）與 YSSessionDTO（狀態同步）共用。
     *
     * 規則（v3.5.36 覆核四／五輪修正）：
     *   - **malformed 明細（非陣列／缺 tradeOrderId／缺 status）一律 fail-closed 回 ''**（覆核五輪）——
     *     可能是「另一筆無法識別但仍在途」的交易，靜默略過會漏偵 → 破壞雙扣防線。
     *   - **唯一允許忽略（continue）**：欄位完整且狀態為 terminal-safe（EXPIRED／FAILED／CANCELLED／REFUNDED，無收款風險）。
     *   - 其餘可辨識狀態（已收款／顧客處理中／在途 PROCESSING·AUTHORIZED·PENDING）皆視為 **active**。
     *   - **未知狀態一律 fail-closed 回 ''**（不可忽略後選到較低風險那筆，漏掉另一筆仍可能收款的交易）。
     *   - 依 distinct tradeOrderId 去重後：**恰好一筆 active → 回該筆；零筆或多筆 → 回 ''**。
     *
     * 單筆在途（PROCESSING/AUTHORIZED）會被回傳供狀態同步追蹤；接管入帳（resolver）之呼叫端
     * 仍須自行以 is_paid() 把關，並加驗金額／幣別／paymentMethod。
     *
     * @param array $details paymentDetails（每元素須含 tradeOrderId／status）。
     * @return string 交易 ID；無法唯一判定或有 malformed 明細回 ''。
     */
    public static function select_representative_trade_id( array $details ): string {
        $active = array();
        foreach ( $details as $pd ) {
            // malformed 明細不得靜默略過——唯一允許忽略的是「欄位完整且 terminal-safe」。
            if ( ! is_array( $pd ) ) {
                return '';
            }
            $tid    = (string) ( $pd['tradeOrderId'] ?? '' );
            $status = isset( $pd['status'] ) ? (string) $pd['status'] : '';
            if ( '' === $tid || '' === $status ) {
                return '';
            }
            if ( self::is_terminal_safe( $status ) ) {
                continue; // 唯一允許忽略：欄位完整且為終態（無收款風險）
            }
            if ( self::is_paid( $status ) || self::is_customer_pending( $status ) || self::is_in_flight( $status ) ) {
                $active[ $tid ] = true;
            } else {
                // 未知狀態：無法判定收款風險 → fail-closed。
                return '';
            }
        }
        return ( 1 === count( $active ) ) ? (string) array_key_first( $active ) : '';
    }
}
