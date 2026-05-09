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
 * 為 WooCommerce Block Checkout 提供 Shopline Payment 支援。
 * 每個已啟用的 gateway 各建一個實例。
 */
final class YSBlocksIntegration extends AbstractPaymentMethodType {

    /**
     * 閘道實例
     *
     * @var \WC_Payment_Gateway|null
     */
    private $gateway = null;

    /**
     * 已註冊的 gateway ID 列表（跨實例共用）
     *
     * @var array
     */
    private static array $registered_ids = [];

    /**
     * 腳本是否已註冊
     *
     * @var bool
     */
    private static bool $script_registered = false;

    /**
     * Constructor.
     *
     * @param string $gateway_id Gateway ID.
     */
    public function __construct( string $gateway_id = 'ys_shopline_credit' ) {
        $this->name = $gateway_id;
        self::$registered_ids[] = $gateway_id;
    }

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
     * 所有 gateway 共用同一個 JS 檔案，只需註冊一次。
     * 透過 localize 傳遞所有已註冊的 gateway ID 給前端。
     *
     * @return array
     */
    public function get_payment_method_script_handles(): array {
        $handle = 'ys-shopline-blocks';

        if ( ! self::$script_registered ) {
            $asset_path = YS_SHOPLINE_PLUGIN_DIR . 'assets/js/blocks/ys-shopline-blocks.asset.php';

            $version      = YS_SHOPLINE_VERSION;
            $dependencies = [];

            if ( file_exists( $asset_path ) ) {
                $asset        = require $asset_path;
                $version      = $asset['version'] ?? $version;
                $dependencies = $asset['dependencies'] ?? $dependencies;
            }

            wp_register_script(
                $handle,
                YS_SHOPLINE_PLUGIN_URL . 'assets/js/blocks/ys-shopline-blocks.js',
                $dependencies,
                $version,
                true
            );

            self::$script_registered = true;
        }

        // 每次都更新 gateway 列表（最後一次呼叫包含所有 ID）
        wp_localize_script( $handle, 'ysShoplineBlocksGateways', self::$registered_ids );

        return [ $handle ];
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
        if ( 'yes' !== get_option( 'ys_shopline_payment_icons_enabled', 'yes' ) ) {
            return array();
        }

        $icons_map = array(
            'ys_shopline_credit' => array(
                array( 'id' => 'visa', 'alt' => 'Visa', 'src' => YS_SHOPLINE_PLUGIN_URL . 'assets/images/visa.svg' ),
                array( 'id' => 'mastercard', 'alt' => 'Mastercard', 'src' => YS_SHOPLINE_PLUGIN_URL . 'assets/images/mastercard.svg' ),
                array( 'id' => 'jcb', 'alt' => 'JCB', 'src' => YS_SHOPLINE_PLUGIN_URL . 'assets/images/jcb.svg' ),
            ),
            'ys_shopline_credit_installment' => array(
                array( 'id' => 'visa', 'alt' => 'Visa', 'src' => YS_SHOPLINE_PLUGIN_URL . 'assets/images/visa.svg' ),
                array( 'id' => 'mastercard', 'alt' => 'Mastercard', 'src' => YS_SHOPLINE_PLUGIN_URL . 'assets/images/mastercard.svg' ),
                array( 'id' => 'jcb', 'alt' => 'JCB', 'src' => YS_SHOPLINE_PLUGIN_URL . 'assets/images/jcb.svg' ),
            ),
            'ys_shopline_credit_subscription' => array(
                array( 'id' => 'visa', 'alt' => 'Visa', 'src' => YS_SHOPLINE_PLUGIN_URL . 'assets/images/visa.svg' ),
                array( 'id' => 'mastercard', 'alt' => 'Mastercard', 'src' => YS_SHOPLINE_PLUGIN_URL . 'assets/images/mastercard.svg' ),
                array( 'id' => 'jcb', 'alt' => 'JCB', 'src' => YS_SHOPLINE_PLUGIN_URL . 'assets/images/jcb.svg' ),
            ),
            'ys_shopline_atm' => array(
                array( 'id' => 'atm', 'alt' => 'ATM', 'src' => YS_SHOPLINE_PLUGIN_URL . 'assets/images/atm.webp' ),
            ),
            'ys_shopline_jkopay' => array(
                array( 'id' => 'jkopay', 'alt' => '街口支付', 'src' => YS_SHOPLINE_PLUGIN_URL . 'assets/images/jkopay.webp' ),
            ),
            'ys_shopline_applepay' => array(
                array( 'id' => 'applepay', 'alt' => 'Apple Pay', 'src' => YS_SHOPLINE_PLUGIN_URL . 'assets/images/apple-pay-mark.svg' ),
            ),
            'ys_shopline_linepay' => array(
                array( 'id' => 'linepay', 'alt' => 'LINE Pay', 'src' => YS_SHOPLINE_PLUGIN_URL . 'assets/images/linepay.webp' ),
            ),
            'ys_shopline_bnpl' => array(
                array( 'id' => 'zingala', 'alt' => 'zingala', 'src' => YS_SHOPLINE_PLUGIN_URL . 'assets/images/zingala.webp' ),
            ),
        );

        return $icons_map[ $this->name ] ?? array();
    }
}
