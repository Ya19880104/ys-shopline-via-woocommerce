<?php
/**
 * My Account Endpoint - Saved Cards
 *
 * @package YangSheep\ShoplinePayment\Customer
 */

declare(strict_types=1);

namespace YangSheep\ShoplinePayment\Customer;

use YangSheep\ShoplinePayment\DTOs\YSPaymentInstrumentDTO;
use YangSheep\ShoplinePayment\Utils\YSLogger;

defined( 'ABSPATH' ) || exit;

/**
 * 我的帳戶 - 管理儲存卡端點
 */
final class YSMyAccountEndpoint {

    /** @var string 端點 Slug */
    public const ENDPOINT_SLUG = 'ys-saved-cards';

    /** @var string 端點標題 */
    public const ENDPOINT_TITLE = '管理儲存卡';

    /** @var YSCustomerManager */
    private YSCustomerManager $customer_manager;

    /**
     * Constructor
     */
    public function __construct() {
        $this->customer_manager = new YSCustomerManager();
    }

    /**
     * 初始化
     */
    public static function init(): void {
        $instance = new self();
        $instance->register_hooks();
    }

    /**
     * 註冊 Hooks
     */
    private function register_hooks(): void {
        // 註冊端點
        add_action( 'init', [ $this, 'register_endpoint' ] );

        // 加入選單項目
        add_filter( 'woocommerce_account_menu_items', [ $this, 'add_menu_item' ] );

        // 端點內容
        add_action( 'woocommerce_account_' . self::ENDPOINT_SLUG . '_endpoint', [ $this, 'endpoint_content' ] );

        // 處理 AJAX 刪除請求
        add_action( 'wp_ajax_ys_shopline_delete_card', [ $this, 'handle_delete_card' ] );

        // 載入腳本
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
    }

    /**
     * 註冊端點
     */
    public function register_endpoint(): void {
        add_rewrite_endpoint( self::ENDPOINT_SLUG, EP_ROOT | EP_PAGES );
    }

    /**
     * 加入選單項目
     *
     * @param array $items 選單項目
     * @return array
     */
    public function add_menu_item( array $items ): array {
        // 在「登出」之前插入
        $logout = $items['customer-logout'] ?? null;
        unset( $items['customer-logout'] );

        $items[ self::ENDPOINT_SLUG ] = self::ENDPOINT_TITLE;

        if ( $logout ) {
            $items['customer-logout'] = $logout;
        }

        return $items;
    }

    /**
     * 端點內容
     */
    public function endpoint_content(): void {
        $user_id = get_current_user_id();

        if ( ! $user_id ) {
            echo '<p>' . esc_html__( '請先登入', 'ys-shopline-payment' ) . '</p>';
            return;
        }

        // 處理表單提交（非 AJAX 備援）
        $this->handle_form_submission();

        // 取得付款工具
        $instruments_array = $this->customer_manager->get_payment_instruments( $user_id, true );

        // 轉換為 DTO
        $instruments = array_map(
            fn( $data ) => YSPaymentInstrumentDTO::from_response( $data ),
            $instruments_array
        );

        // 載入模板
        $this->render_template( $instruments );
    }

    /**
     * 渲染模板
     *
     * @param array<YSPaymentInstrumentDTO> $instruments 付款工具列表
     */
    private function render_template( array $instruments ): void {
        ?>
        <div class="ys-saved-cards-wrapper">
            <h3><?php esc_html_e( '已儲存的付款方式', 'ys-shopline-payment' ); ?></h3>

            <?php if ( empty( $instruments ) ) : ?>
                <div class="ys-no-saved-cards">
                    <p><?php esc_html_e( '您目前沒有儲存的付款方式。', 'ys-shopline-payment' ); ?></p>
                    <p class="description"><?php esc_html_e( '在結帳時選擇「儲存卡片」即可新增付款方式。', 'ys-shopline-payment' ); ?></p>
                </div>
            <?php else : ?>
                <div class="ys-saved-cards-list">
                    <?php foreach ( $instruments as $instrument ) : ?>
                        <div class="ys-saved-card-item" data-instrument-id="<?php echo esc_attr( $instrument->instrument_id ); ?>">
                            <div class="ys-card-icon">
                                <?php echo $this->get_card_brand_icon( $instrument->get_card_brand() ); ?>
                            </div>
                            <div class="ys-card-info">
                                <div class="ys-card-name">
                                    <?php echo esc_html( $instrument->get_display_name() ); ?>
                                </div>
                                <div class="ys-card-details">
                                    <span class="ys-card-expiry">
                                        <?php
                                        printf(
                                            /* translators: %s: Card expiry date */
                                            esc_html__( '到期日：%s', 'ys-shopline-payment' ),
                                            esc_html( $instrument->get_card_expiry() )
                                        );
                                        ?>
                                    </span>
                                    <span class="ys-card-status <?php echo esc_attr( $instrument->is_expired() ? 'expired' : 'active' ); ?>">
                                        <?php echo esc_html( $instrument->get_status_display() ); ?>
                                    </span>
                                </div>
                            </div>
                            <div class="ys-card-actions">
                                <button type="button"
                                        class="ys-delete-card-btn button"
                                        data-instrument-id="<?php echo esc_attr( $instrument->instrument_id ); ?>"
                                        data-card-name="<?php echo esc_attr( $instrument->get_display_name() ); ?>">
                                    <?php esc_html_e( '刪除', 'ys-shopline-payment' ); ?>
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <style>
            .ys-saved-cards-wrapper {
                max-width: 600px;
            }
            .ys-saved-cards-wrapper h3 {
                margin-bottom: 20px;
            }
            .ys-no-saved-cards {
                padding: 30px;
                background: #f8f9fa;
                border-radius: 8px;
                text-align: center;
            }
            .ys-no-saved-cards .description {
                color: #666;
                font-size: 14px;
            }
            .ys-saved-cards-list {
                display: flex;
                flex-direction: column;
                gap: 15px;
            }
            .ys-saved-card-item {
                display: flex;
                align-items: center;
                padding: 15px 20px;
                background: #fff;
                border: 1px solid #ddd;
                border-radius: 8px;
                transition: border-color 0.2s;
            }
            .ys-saved-card-item:hover {
                border-color: #8fa8b8;
            }
            .ys-saved-card-item.deleting {
                opacity: 0.5;
                pointer-events: none;
            }
            .ys-card-icon {
                font-size: 24px;
                margin-right: 15px;
                width: 40px;
                text-align: center;
            }
            .ys-card-info {
                flex: 1;
            }
            .ys-card-name {
                font-weight: 600;
                margin-bottom: 5px;
            }
            .ys-card-details {
                font-size: 13px;
                color: #666;
            }
            .ys-card-details span {
                margin-right: 15px;
            }
            .ys-card-status.expired {
                color: #dc3545;
            }
            .ys-card-status.active {
                color: #28a745;
            }
            .ys-card-actions .ys-delete-card-btn {
                background: #fff;
                border: 1px solid #dc3545;
                color: #dc3545;
                padding: 5px 15px;
                border-radius: 4px;
                cursor: pointer;
                transition: all 0.2s;
            }
            .ys-card-actions .ys-delete-card-btn:hover {
                background: #dc3545;
                color: #fff;
            }
            /* 通知樣式 */
            .ys-notice {
                padding: 12px 15px;
                border-radius: 4px;
                margin-bottom: 20px;
                font-size: 14px;
            }
            .ys-notice-success {
                background: #d4edda;
                border: 1px solid #c3e6cb;
                color: #155724;
            }
            .ys-notice-error {
                background: #f8d7da;
                border: 1px solid #f5c6cb;
                color: #721c24;
            }
        </style>
        <?php
    }

    /**
     * 取得卡片品牌圖示
     *
     * @param string $brand 品牌名稱
     * @return string
     */
    private function get_card_brand_icon( string $brand ): string {
        $icons = [
            'Visa'       => '💳',
            'Mastercard' => '💳',
            'JCB'        => '💳',
            'AmEx'       => '💳',
        ];

        return $icons[ $brand ] ?? '💳';
    }

    /**
     * 載入腳本
     */
    public function enqueue_scripts(): void {
        if ( ! is_account_page() ) {
            return;
        }

        wp_enqueue_script(
            'ys-shopline-myaccount',
            YS_SHOPLINE_PLUGIN_URL . 'assets/js/ys-shopline-myaccount.js',
            [ 'jquery' ],
            YS_SHOPLINE_VERSION,
            true
        );

        wp_localize_script( 'ys-shopline-myaccount', 'ys_shopline_myaccount', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'ys_shopline_delete_card' ),
            'i18n'     => [
                'confirm_delete' => __( '確定要刪除這張卡片嗎？', 'ys-shopline-payment' ),
                'deleting'       => __( '刪除中...', 'ys-shopline-payment' ),
                'delete_success' => __( '卡片已成功刪除', 'ys-shopline-payment' ),
                'delete_error'   => __( '刪除失敗，請稍後再試', 'ys-shopline-payment' ),
            ],
        ] );
    }

    /**
     * 處理 AJAX 刪除卡片請求
     */
    public function handle_delete_card(): void {
        // 驗證 Nonce
        if ( ! wp_verify_nonce( $_POST['nonce'] ?? '', 'ys_shopline_delete_card' ) ) {
            wp_send_json_error( [ 'message' => __( '安全驗證失敗', 'ys-shopline-payment' ) ] );
        }

        // 驗證登入
        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            wp_send_json_error( [ 'message' => __( '請先登入', 'ys-shopline-payment' ) ] );
        }

        // 取得參數
        $instrument_id = sanitize_text_field( $_POST['instrument_id'] ?? '' );
        if ( empty( $instrument_id ) ) {
            wp_send_json_error( [ 'message' => __( '缺少卡片 ID', 'ys-shopline-payment' ) ] );
        }

        // 執行刪除
        $success = $this->customer_manager->unbind_payment_instrument( $user_id, $instrument_id );

        if ( $success ) {
            wp_send_json_success( [ 'message' => __( '卡片已成功刪除', 'ys-shopline-payment' ) ] );
        } else {
            wp_send_json_error( [ 'message' => __( '刪除失敗，請稍後再試', 'ys-shopline-payment' ) ] );
        }
    }

    /**
     * 處理表單提交（非 AJAX 備援）
     */
    private function handle_form_submission(): void {
        if ( ! isset( $_POST['ys_delete_card_nonce'] ) ) {
            return;
        }

        if ( ! wp_verify_nonce( $_POST['ys_delete_card_nonce'], 'ys_delete_card' ) ) {
            wc_add_notice( __( '安全驗證失敗', 'ys-shopline-payment' ), 'error' );
            return;
        }

        $instrument_id = sanitize_text_field( $_POST['instrument_id'] ?? '' );
        $user_id       = get_current_user_id();

        if ( empty( $instrument_id ) || ! $user_id ) {
            return;
        }

        $success = $this->customer_manager->unbind_payment_instrument( $user_id, $instrument_id );

        if ( $success ) {
            wc_add_notice( __( '卡片已成功刪除', 'ys-shopline-payment' ), 'success' );
        } else {
            wc_add_notice( __( '刪除失敗，請稍後再試', 'ys-shopline-payment' ), 'error' );
        }
    }
}
