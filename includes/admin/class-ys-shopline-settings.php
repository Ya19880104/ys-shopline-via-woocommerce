<?php
/**
 * Settings page for YS Shopline Payment.
 *
 * @package YS_Shopline_Payment
 */

defined( 'ABSPATH' ) || exit;

/**
 * YS_Shopline_Settings Class.
 *
 * Adds a dedicated settings tab in WooCommerce settings.
 */
class YS_Shopline_Settings extends WC_Settings_Page {

    /**
     * Constructor.
     */
    public function __construct() {
        $this->id    = 'ys_shopline_payment';
        $this->label = __( 'SHOPLINE Payment', 'ys-shopline-via-woocommerce' );

        parent::__construct();

        // Add styles
        add_action( 'admin_head', array( $this, 'admin_styles' ) );
    }

    /**
     * Get sections.
     *
     * @return array
     */
    public function get_sections() {
        $sections = array(
            ''         => __( '一般設定', 'ys-shopline-via-woocommerce' ),
            'api'      => __( 'API 設定', 'ys-shopline-via-woocommerce' ),
            'gateways' => __( '支付方式', 'ys-shopline-via-woocommerce' ),
            'advanced' => __( '進階設定', 'ys-shopline-via-woocommerce' ),
        );

        return apply_filters( 'woocommerce_get_sections_' . $this->id, $sections );
    }

    /**
     * Get settings array.
     *
     * @param string $current_section Current section slug.
     * @return array
     */
    public function get_settings( $current_section = '' ) {
        switch ( $current_section ) {
            case 'api':
                $settings = $this->get_api_settings();
                break;

            case 'gateways':
                $settings = $this->get_gateway_settings();
                break;

            case 'advanced':
                $settings = $this->get_advanced_settings();
                break;

            default:
                $settings = $this->get_general_settings();
                break;
        }

        return apply_filters( 'woocommerce_get_settings_' . $this->id, $settings, $current_section );
    }

    /**
     * Get general settings.
     *
     * @return array
     */
    private function get_general_settings() {
        return array(
            array(
                'title' => __( 'SHOPLINE Payment 設定', 'ys-shopline-via-woocommerce' ),
                'type'  => 'title',
                'desc'  => __( '在此設定 SHOPLINE Payment 金流服務。', 'ys-shopline-via-woocommerce' ),
                'id'    => 'ys_shopline_general_settings',
            ),
            array(
                'title'   => __( '啟用 SHOPLINE Payment', 'ys-shopline-via-woocommerce' ),
                'desc'    => __( '啟用 SHOPLINE Payment 金流服務', 'ys-shopline-via-woocommerce' ),
                'id'      => 'ys_shopline_enabled',
                'default' => 'yes',
                'type'    => 'checkbox',
                'class'   => 'ys-shopline-toggle',
            ),
            array(
                'title'   => __( '測試模式', 'ys-shopline-via-woocommerce' ),
                'desc'    => __( '使用 SHOPLINE Sandbox 測試環境', 'ys-shopline-via-woocommerce' ),
                'id'      => 'ys_shopline_testmode',
                'default' => 'yes',
                'type'    => 'checkbox',
                'class'   => 'ys-shopline-toggle',
            ),
            array(
                'type' => 'sectionend',
                'id'   => 'ys_shopline_general_settings',
            ),
        );
    }

    /**
     * Get API settings.
     *
     * @return array
     */
    private function get_api_settings() {
        return array(
            // Sandbox API settings
            array(
                'title' => __( '測試環境 API 設定', 'ys-shopline-via-woocommerce' ),
                'type'  => 'title',
                'desc'  => __( '請填入 SHOPLINE 提供的 Sandbox 測試環境 API 憑證。', 'ys-shopline-via-woocommerce' ),
                'id'    => 'ys_shopline_sandbox_api_settings',
            ),
            array(
                'title'    => __( 'Sandbox Merchant ID', 'ys-shopline-via-woocommerce' ),
                'desc'     => __( '測試環境的特店 ID', 'ys-shopline-via-woocommerce' ),
                'id'       => 'ys_shopline_sandbox_merchant_id',
                'type'     => 'text',
                'default'  => '',
                'desc_tip' => true,
            ),
            array(
                'title'    => __( 'Sandbox API Key', 'ys-shopline-via-woocommerce' ),
                'desc'     => __( '測試環境的 API 金鑰', 'ys-shopline-via-woocommerce' ),
                'id'       => 'ys_shopline_sandbox_api_key',
                'type'     => 'password',
                'default'  => '',
                'desc_tip' => true,
            ),
            array(
                'title'    => __( 'Sandbox Client Key', 'ys-shopline-via-woocommerce' ),
                'desc'     => __( '測試環境的 SDK Client Key', 'ys-shopline-via-woocommerce' ),
                'id'       => 'ys_shopline_sandbox_client_key',
                'type'     => 'text',
                'default'  => '',
                'desc_tip' => true,
            ),
            array(
                'title'    => __( 'Sandbox Webhook Sign Key', 'ys-shopline-via-woocommerce' ),
                'desc'     => __( '測試環境的 Webhook 簽名金鑰', 'ys-shopline-via-woocommerce' ),
                'id'       => 'ys_shopline_sandbox_sign_key',
                'type'     => 'password',
                'default'  => '',
                'desc_tip' => true,
            ),
            array(
                'type' => 'sectionend',
                'id'   => 'ys_shopline_sandbox_api_settings',
            ),

            // Production API settings
            array(
                'title' => __( '正式環境 API 設定', 'ys-shopline-via-woocommerce' ),
                'type'  => 'title',
                'desc'  => __( '請填入 SHOPLINE 提供的正式環境 API 憑證。', 'ys-shopline-via-woocommerce' ),
                'id'    => 'ys_shopline_production_api_settings',
            ),
            array(
                'title'    => __( 'Merchant ID', 'ys-shopline-via-woocommerce' ),
                'desc'     => __( '正式環境的特店 ID', 'ys-shopline-via-woocommerce' ),
                'id'       => 'ys_shopline_merchant_id',
                'type'     => 'text',
                'default'  => '',
                'desc_tip' => true,
            ),
            array(
                'title'    => __( 'API Key', 'ys-shopline-via-woocommerce' ),
                'desc'     => __( '正式環境的 API 金鑰', 'ys-shopline-via-woocommerce' ),
                'id'       => 'ys_shopline_api_key',
                'type'     => 'password',
                'default'  => '',
                'desc_tip' => true,
            ),
            array(
                'title'    => __( 'Client Key', 'ys-shopline-via-woocommerce' ),
                'desc'     => __( '正式環境的 SDK Client Key', 'ys-shopline-via-woocommerce' ),
                'id'       => 'ys_shopline_client_key',
                'type'     => 'text',
                'default'  => '',
                'desc_tip' => true,
            ),
            array(
                'title'    => __( 'Webhook Sign Key', 'ys-shopline-via-woocommerce' ),
                'desc'     => __( '正式環境的 Webhook 簽名金鑰', 'ys-shopline-via-woocommerce' ),
                'id'       => 'ys_shopline_sign_key',
                'type'     => 'password',
                'default'  => '',
                'desc_tip' => true,
            ),
            array(
                'type' => 'sectionend',
                'id'   => 'ys_shopline_production_api_settings',
            ),

            // Webhook URL info
            array(
                'title' => __( 'Webhook 設定', 'ys-shopline-via-woocommerce' ),
                'type'  => 'title',
                'desc'  => sprintf(
                    /* translators: %s: Webhook URL */
                    __( '請在 SHOPLINE 後台設定以下 Webhook URL：<br><code>%s</code>', 'ys-shopline-via-woocommerce' ),
                    home_url( 'wc-api/ys_shopline_webhook' )
                ),
                'id'    => 'ys_shopline_webhook_info',
            ),
            array(
                'type' => 'sectionend',
                'id'   => 'ys_shopline_webhook_info',
            ),
        );
    }

    /**
     * Get gateway settings.
     *
     * @return array
     */
    private function get_gateway_settings() {
        $settings = array(
            array(
                'title' => __( '支付方式設定', 'ys-shopline-via-woocommerce' ),
                'type'  => 'title',
                'desc'  => __( '選擇要啟用的支付方式。各支付方式的詳細設定請至 WooCommerce > 設定 > 付款 中調整。', 'ys-shopline-via-woocommerce' ),
                'id'    => 'ys_shopline_gateway_settings',
            ),
            array(
                'title'   => __( '信用卡', 'ys-shopline-via-woocommerce' ),
                'desc'    => __( '啟用信用卡付款（支援分期）', 'ys-shopline-via-woocommerce' ),
                'id'      => 'ys_shopline_credit_enabled',
                'default' => 'yes',
                'type'    => 'checkbox',
                'class'   => 'ys-shopline-toggle',
            ),
        );

        // Add subscription option if WooCommerce Subscriptions is active
        if ( class_exists( 'WC_Subscriptions' ) ) {
            $settings[] = array(
                'title'   => __( '信用卡訂閱（定期定額）', 'ys-shopline-via-woocommerce' ),
                'desc'    => __( '啟用信用卡定期定額付款（WooCommerce Subscriptions）', 'ys-shopline-via-woocommerce' ),
                'id'      => 'ys_shopline_credit_subscription_enabled',
                'default' => 'yes',
                'type'    => 'checkbox',
                'class'   => 'ys-shopline-toggle',
            );
        }

        $settings = array_merge(
            $settings,
            array(
                array(
                    'title'   => __( 'ATM 銀行轉帳', 'ys-shopline-via-woocommerce' ),
                    'desc'    => __( '啟用 ATM 虛擬帳號付款', 'ys-shopline-via-woocommerce' ),
                    'id'      => 'ys_shopline_atm_enabled',
                    'default' => 'yes',
                    'type'    => 'checkbox',
                    'class'   => 'ys-shopline-toggle',
                ),
                array(
                    'title'   => __( '街口支付', 'ys-shopline-via-woocommerce' ),
                    'desc'    => __( '啟用街口支付 JKOPay', 'ys-shopline-via-woocommerce' ),
                    'id'      => 'ys_shopline_jkopay_enabled',
                    'default' => 'yes',
                    'type'    => 'checkbox',
                    'class'   => 'ys-shopline-toggle',
                ),
                array(
                    'title'   => __( 'Apple Pay', 'ys-shopline-via-woocommerce' ),
                    'desc'    => __( '啟用 Apple Pay', 'ys-shopline-via-woocommerce' ),
                    'id'      => 'ys_shopline_applepay_enabled',
                    'default' => 'yes',
                    'type'    => 'checkbox',
                    'class'   => 'ys-shopline-toggle',
                ),
                array(
                    'title'   => __( 'LINE Pay', 'ys-shopline-via-woocommerce' ),
                    'desc'    => __( '啟用 LINE Pay', 'ys-shopline-via-woocommerce' ),
                    'id'      => 'ys_shopline_linepay_enabled',
                    'default' => 'yes',
                    'type'    => 'checkbox',
                    'class'   => 'ys-shopline-toggle',
                ),
                array(
                    'title'   => __( '中租 zingla 銀角零卡', 'ys-shopline-via-woocommerce' ),
                    'desc'    => __( '啟用中租 zingla BNPL 先買後付', 'ys-shopline-via-woocommerce' ),
                    'id'      => 'ys_shopline_bnpl_enabled',
                    'default' => 'yes',
                    'type'    => 'checkbox',
                    'class'   => 'ys-shopline-toggle',
                ),
                array(
                    'type' => 'sectionend',
                    'id'   => 'ys_shopline_gateway_settings',
                ),
            )
        );

        return $settings;
    }

    /**
     * Get advanced settings.
     *
     * @return array
     */
    private function get_advanced_settings() {
        return array(
            array(
                'title' => __( '進階設定', 'ys-shopline-via-woocommerce' ),
                'type'  => 'title',
                'id'    => 'ys_shopline_advanced_settings',
            ),
            array(
                'title'   => __( 'Debug Log', 'ys-shopline-via-woocommerce' ),
                'desc'    => sprintf(
                    /* translators: %s: Log file path */
                    __( '啟用 Debug 日誌，日誌路徑：<code>%s</code>', 'ys-shopline-via-woocommerce' ),
                    wc_get_log_file_path( 'ys-shopline-payment' )
                ),
                'id'      => 'ys_shopline_debug_log',
                'default' => 'no',
                'type'    => 'checkbox',
                'class'   => 'ys-shopline-toggle',
            ),
            array(
                'title'    => __( '訂單狀態 - 付款成功', 'ys-shopline-via-woocommerce' ),
                'desc'     => __( '付款成功後的訂單狀態', 'ys-shopline-via-woocommerce' ),
                'id'       => 'ys_shopline_order_status_paid',
                'type'     => 'select',
                'class'    => 'wc-enhanced-select',
                'default'  => 'processing',
                'options'  => wc_get_order_statuses(),
                'desc_tip' => true,
            ),
            array(
                'title'    => __( '訂單狀態 - 等待付款', 'ys-shopline-via-woocommerce' ),
                'desc'     => __( '等待付款確認（如 ATM 轉帳）時的訂單狀態', 'ys-shopline-via-woocommerce' ),
                'id'       => 'ys_shopline_order_status_pending',
                'type'     => 'select',
                'class'    => 'wc-enhanced-select',
                'default'  => 'on-hold',
                'options'  => wc_get_order_statuses(),
                'desc_tip' => true,
            ),
            array(
                'type' => 'sectionend',
                'id'   => 'ys_shopline_advanced_settings',
            ),
        );
    }

    /**
     * Admin styles.
     */
    public function admin_styles() {
        $screen = get_current_screen();

        if ( 'woocommerce_page_wc-settings' !== $screen->id ) {
            return;
        }

        if ( ! isset( $_GET['tab'] ) || 'ys_shopline_payment' !== $_GET['tab'] ) {
            return;
        }

        ?>
        <style>
            /* Section title styles */
            .woocommerce-settings-ys_shopline_payment h2 {
                position: relative;
                border-top: 1px solid #ccc;
                padding: 2rem 0 0 1rem;
                margin: 2rem 0 1rem 0;
            }

            .woocommerce-settings-ys_shopline_payment h2:before {
                content: '';
                position: absolute;
                top: 31px;
                left: 0;
                width: 5px;
                height: 20px;
                background-color: #cc99c2;
            }

            .woocommerce-settings-ys_shopline_payment h2:first-of-type,
            .woocommerce-settings-ys_shopline_payment h1 + h2,
            .woocommerce-settings-ys_shopline_payment .notice + h2 {
                border-top: 0;
                margin-top: 0;
            }

            /* Toggle switch styles */
            input.ys-shopline-toggle[type=checkbox] {
                height: 0;
                width: 0;
                visibility: hidden;
                position: absolute;
            }

            input.ys-shopline-toggle + label {
                cursor: pointer;
                text-indent: -9999px;
                width: 50px;
                height: 26px;
                background: #b4b9be;
                display: block;
                border-radius: 100px;
                position: relative;
            }

            input.ys-shopline-toggle + label:after {
                content: '';
                position: absolute;
                top: 3px;
                left: 3px;
                width: 20px;
                height: 20px;
                background: #fff;
                border-radius: 40px;
                transition: 0.3s;
            }

            input.ys-shopline-toggle:checked + label {
                background: #cc99c2;
            }

            input.ys-shopline-toggle:checked + label:after {
                left: calc(100% - 3px);
                transform: translateX(-100%);
            }

            input.ys-shopline-toggle + label:active:after {
                width: 30px;
            }

            .form-table td fieldset label {
                margin-top: 0 !important;
                margin-left: -10px !important;
                margin-bottom: 3px !important;
            }

            .form-table td fieldset label + label.ys-shopline-toggle-label {
                display: inline-block;
                margin-left: 5px !important;
            }

            .form-table td fieldset label + label.ys-shopline-toggle-label:after {
                content: '停用 / 啟用';
                text-indent: 0;
                display: inline-block;
                margin-left: 60px;
                color: #666;
                font-size: 12px;
            }

            /* Code block styles */
            .form-table code {
                background: #f5f5f5;
                padding: 2px 6px;
                border-radius: 3px;
            }

            /* Enhanced select styles */
            .woocommerce-settings-ys_shopline_payment .select2-container {
                min-width: 250px;
            }
        </style>
        <script>
            jQuery(document).ready(function($) {
                // Add toggle label after checkbox
                $('.form-table td fieldset label').each(function() {
                    var $input = $(this).find('input.ys-shopline-toggle');
                    if ($input.length) {
                        $input.after('<label for="' + $input.attr('id') + '" class="ys-shopline-toggle-label">Toggle</label>');
                    }
                });
            });
        </script>
        <?php
    }
}
