<?php
/**
 * WooCommerce Blocks Integration
 *
 * @package YangSheep\ShoplinePayment\Blocks
 */

declare(strict_types=1);

namespace YangSheep\ShoplinePayment\Blocks;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

defined( 'ABSPATH' ) || exit;

/**
 * Block Checkout 整合
 *
 * 為 WooCommerce Block Checkout 提供 Shopline Payment 支援
 */
final class YSBlocksIntegration extends AbstractPaymentMethodType {

    /**
     * 付款方式名稱
     *
     * @var string
     */
    protected $name = 'ys_shopline_redirect';

    /**
     * 閘道實例
     *
     * @var \WC_Payment_Gateway|null
     */
    private $gateway = null;

    /**
     * 初始化
     */
    public function initialize(): void {
        $this->settings = get_option( 'woocommerce_' . $this->name . '_settings', [] );
        $this->gateway  = $this->get_gateway();
    }

    /**
     * 取得閘道實例
     *
     * @return \WC_Payment_Gateway|null
     */
    private function get_gateway(): ?\WC_Payment_Gateway {
        $gateways = WC()->payment_gateways()->payment_gateways();
        return $gateways[ $this->name ] ?? null;
    }

    /**
     * 是否啟用
     *
     * @return bool
     */
    public function is_active(): bool {
        return $this->gateway && 'yes' === $this->gateway->enabled;
    }

    /**
     * 取得腳本 handles
     *
     * @return array
     */
    public function get_payment_method_script_handles(): array {
        $asset_path = YS_SHOPLINE_PLUGIN_DIR . 'assets/js/blocks/ys-shopline-blocks.asset.php';

        $version      = YS_SHOPLINE_VERSION;
        $dependencies = [];

        if ( file_exists( $asset_path ) ) {
            $asset        = require $asset_path;
            $version      = $asset['version'] ?? $version;
            $dependencies = $asset['dependencies'] ?? $dependencies;
        }

        wp_register_script(
            'ys-shopline-blocks',
            YS_SHOPLINE_PLUGIN_URL . 'assets/js/blocks/ys-shopline-blocks.js',
            $dependencies,
            $version,
            true
        );

        return [ 'ys-shopline-blocks' ];
    }

    /**
     * 取得付款方式資料
     *
     * @return array
     */
    public function get_payment_method_data(): array {
        return [
            'title'       => $this->get_setting( 'title' ),
            'description' => $this->get_setting( 'description' ),
            'supports'    => $this->get_supported_features(),
            'icons'       => $this->get_payment_icons(),
        ];
    }

    /**
     * 取得支援的功能
     *
     * @return array
     */
    private function get_supported_features(): array {
        return $this->gateway ? $this->gateway->supports : [];
    }

    /**
     * 取得付款圖示
     *
     * @return array
     */
    private function get_payment_icons(): array {
        return [
            [
                'id'  => 'visa',
                'alt' => 'Visa',
                'src' => YS_SHOPLINE_PLUGIN_URL . 'assets/images/visa.svg',
            ],
            [
                'id'  => 'mastercard',
                'alt' => 'Mastercard',
                'src' => YS_SHOPLINE_PLUGIN_URL . 'assets/images/mastercard.svg',
            ],
            [
                'id'  => 'jcb',
                'alt' => 'JCB',
                'src' => YS_SHOPLINE_PLUGIN_URL . 'assets/images/jcb.svg',
            ],
        ];
    }
}
