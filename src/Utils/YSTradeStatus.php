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
}
