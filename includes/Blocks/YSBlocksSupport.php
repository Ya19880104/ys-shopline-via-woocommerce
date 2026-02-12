<?php
/**
 * Blocks Support
 *
 * @package YangSheep\ShoplinePayment\Blocks
 */

declare(strict_types=1);

namespace YangSheep\ShoplinePayment\Blocks;

use Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce Blocks 支援
 *
 * 註冊 Block Checkout 付款方式
 */
final class YSBlocksSupport {

    /**
     * 初始化
     */
    public static function init(): void {
        add_action( 'woocommerce_blocks_loaded', [ self::class, 'register_payment_methods' ] );
    }

    /**
     * 註冊付款方式
     */
    public static function register_payment_methods(): void {
        // 檢查是否有 Block 支援
        if ( ! class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
            return;
        }

        add_action(
            'woocommerce_blocks_payment_method_type_registration',
            function ( PaymentMethodRegistry $registry ) {
                // 註冊跳轉支付
                $registry->register( new YSBlocksIntegration() );

                // 未來可註冊 Token 支付
                // $registry->register( new YSTokenBlocksIntegration() );
            }
        );
    }
}
