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
 * 自動註冊所有已啟用的 ys_shopline_* gateway 到 Block Checkout
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
        if ( ! class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
            return;
        }

        add_action(
            'woocommerce_blocks_payment_method_type_registration',
            function ( PaymentMethodRegistry $registry ) {
                // 註冊所有 ys_shopline_ 開頭的已啟用 gateway
                $all_gateways = WC()->payment_gateways()->payment_gateways();

                foreach ( $all_gateways as $gateway_id => $gateway ) {
                    if ( str_starts_with( $gateway_id, 'ys_shopline_' ) && 'yes' === $gateway->enabled ) {
                        $registry->register( new YSBlocksIntegration( $gateway_id ) );
                    }
                }
            }
        );
    }
}
