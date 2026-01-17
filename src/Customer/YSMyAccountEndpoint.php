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

        // 處理 AJAX 同步請求
        add_action( 'wp_ajax_ys_shopline_sync_cards', [ $this, 'handle_sync_cards' ] );

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

        // 從 WC Tokens 取得儲存卡（不自動同步 API，改由按鈕觸發）
        // 檢查所有相關 gateway
        $wc_tokens = \WC_Payment_Tokens::get_customer_tokens( $user_id, 'ys_shopline_credit' );
        $wc_tokens = array_merge( $wc_tokens, \WC_Payment_Tokens::get_customer_tokens( $user_id, 'ys_shopline_credit_subscription' ) );
        $wc_tokens = array_merge( $wc_tokens, \WC_Payment_Tokens::get_customer_tokens( $user_id, 'ys_shopline_credit_card' ) );

        // 去除重複（根據 token 值）
        $unique_tokens = [];
        $seen_ids = [];
        foreach ( $wc_tokens as $token ) {
            $token_value = $token->get_token();
            if ( ! in_array( $token_value, $seen_ids, true ) ) {
                $seen_ids[] = $token_value;
                $unique_tokens[] = $token;
            }
        }

        // 轉換 WC Tokens 為顯示格式
        $instruments = array_map(
            fn( $token ) => $this->convert_wc_token_to_instrument( $token ),
            $unique_tokens
        );

        // 載入模板
        $this->render_template( $instruments );
    }

    /**
     * 當 WC Tokens 為空時，從 API 同步
     *
     * @param int $user_id WordPress 用戶 ID
     */
    private function maybe_sync_tokens_from_api( int $user_id ): void {
        // 檢查現有的 WC Tokens（所有相關 gateway）
        $tokens = \WC_Payment_Tokens::get_customer_tokens( $user_id, 'ys_shopline_credit' );
        $tokens = array_merge( $tokens, \WC_Payment_Tokens::get_customer_tokens( $user_id, 'ys_shopline_credit_subscription' ) );
        $tokens = array_merge( $tokens, \WC_Payment_Tokens::get_customer_tokens( $user_id, 'ys_shopline_credit_card' ) );

        // 如果已有 tokens，不需要同步
        if ( ! empty( $tokens ) ) {
            return;
        }

        // 從 API 取得付款工具
        $instruments_array = $this->customer_manager->get_payment_instruments( $user_id, true );

        if ( empty( $instruments_array ) ) {
            return;
        }

        YSLogger::info( '從 API 同步付款工具到 WC Tokens', [
            'user_id' => $user_id,
            'count'   => count( $instruments_array ),
        ] );

        // 同步到 WC Tokens
        foreach ( $instruments_array as $instrument ) {
            $this->create_wc_token_from_instrument( $user_id, $instrument );
        }
    }

    /**
     * 從付款工具建立 WC Token
     *
     * @param int   $user_id    WordPress 用戶 ID
     * @param array $instrument 付款工具資料
     * @return \WC_Payment_Token_CC|null
     */
    private function create_wc_token_from_instrument( int $user_id, array $instrument ): ?\WC_Payment_Token_CC {
        $instrument_id = $instrument['paymentInstrumentId'] ?? '';
        if ( empty( $instrument_id ) ) {
            return null;
        }

        // 檢查是否已存在（所有相關 gateway）
        $gateway_ids = [ 'ys_shopline_credit', 'ys_shopline_credit_subscription', 'ys_shopline_credit_card' ];
        foreach ( $gateway_ids as $gw_id ) {
            $existing_tokens = \WC_Payment_Tokens::get_customer_tokens( $user_id, $gw_id );
            foreach ( $existing_tokens as $token ) {
                if ( $token->get_token() === $instrument_id ) {
                    return $token;
                }
            }
        }

        $card_info = $instrument['instrumentCard'] ?? [];

        // 取得卡片資訊（支援多種欄位名稱）
        $card_type    = strtolower( $card_info['brand'] ?? $card_info['cardBrand'] ?? 'visa' );
        $last4        = $card_info['last'] ?? $card_info['last4'] ?? $card_info['cardLast4'] ?? '****';
        $expiry_month = $card_info['expiryMonth'] ?? $card_info['expireMonth'] ?? $card_info['expMonth'] ?? '12';
        $expiry_year  = $card_info['expiryYear'] ?? $card_info['expireYear'] ?? $card_info['expYear'] ?? date( 'Y' );

        // 確保 expiry 欄位有有效值
        if ( empty( $expiry_month ) || ! is_numeric( $expiry_month ) ) {
            $expiry_month = '12';
        }
        if ( empty( $expiry_year ) || ! is_numeric( $expiry_year ) ) {
            $expiry_year = date( 'Y' );
        }

        $token = new \WC_Payment_Token_CC();
        $token->set_token( $instrument_id );
        // 使用正確的 gateway ID
        $token->set_gateway_id( 'ys_shopline_credit' );
        $token->set_user_id( $user_id );
        $token->set_card_type( $card_type );
        $token->set_last4( $last4 );
        $token->set_expiry_month( $expiry_month );
        $token->set_expiry_year( $expiry_year );

        try {
            if ( $token->save() ) {
                YSLogger::debug( 'WC Token 建立成功', [
                    'user_id'       => $user_id,
                    'token_id'      => $token->get_id(),
                    'instrument_id' => $instrument_id,
                    'last4'         => $last4,
                ] );
                return $token;
            }
        } catch ( \Exception $e ) {
            YSLogger::error( 'WC Token 建立失敗', [
                'user_id'       => $user_id,
                'instrument_id' => $instrument_id,
                'error'         => $e->getMessage(),
            ] );
        }

        return null;
    }

    /**
     * 將 WC Token 轉換為顯示用的 DTO
     *
     * @param \WC_Payment_Token_CC $token WC Token
     * @return YSPaymentInstrumentDTO
     */
    private function convert_wc_token_to_instrument( \WC_Payment_Token_CC $token ): YSPaymentInstrumentDTO {
        // 建立一個相容的資料陣列
        $data = [
            'paymentInstrumentId' => $token->get_token(),
            'instrumentStatus'    => 'ENABLED',
            'instrumentCard'      => [
                'brand'       => ucfirst( $token->get_card_type() ),
                'last'        => $token->get_last4(),
                'expiryMonth' => $token->get_expiry_month(),
                'expiryYear'  => $token->get_expiry_year(),
            ],
            // 加入 WC Token ID 以便刪除
            '_wc_token_id' => $token->get_id(),
        ];

        return YSPaymentInstrumentDTO::from_response( $data );
    }

    /**
     * 渲染模板
     *
     * @param array<YSPaymentInstrumentDTO> $instruments 付款工具列表
     */
    private function render_template( array $instruments ): void {
        ?>
        <div class="ys-saved-cards-wrapper">
            <div class="ys-saved-cards-header">
                <h3><?php esc_html_e( '已儲存的付款方式', 'ys-shopline-payment' ); ?></h3>
                <button type="button" class="ys-sync-cards-btn button" id="ys-sync-cards-btn">
                    <span class="ys-sync-icon">↻</span>
                    <?php esc_html_e( '同步卡片', 'ys-shopline-payment' ); ?>
                </button>
            </div>

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
            .ys-saved-cards-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 20px;
            }
            .ys-saved-cards-header h3 {
                margin: 0;
            }
            .ys-sync-cards-btn {
                display: inline-flex;
                align-items: center;
                gap: 5px;
                padding: 8px 16px;
                font-size: 13px;
            }
            .ys-sync-cards-btn .ys-sync-icon {
                font-size: 16px;
                transition: transform 0.3s;
            }
            .ys-sync-cards-btn.syncing .ys-sync-icon {
                animation: ys-spin 1s linear infinite;
            }
            .ys-sync-cards-btn:disabled {
                opacity: 0.6;
                cursor: not-allowed;
            }
            @keyframes ys-spin {
                from { transform: rotate(0deg); }
                to { transform: rotate(360deg); }
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
            'ajax_url'   => admin_url( 'admin-ajax.php' ),
            'nonce'      => wp_create_nonce( 'ys_shopline_delete_card' ),
            'sync_nonce' => wp_create_nonce( 'ys_shopline_sync_cards' ),
            'i18n'       => [
                'confirm_delete' => __( '確定要刪除這張卡片嗎？', 'ys-shopline-payment' ),
                'deleting'       => __( '刪除中...', 'ys-shopline-payment' ),
                'delete_success' => __( '卡片已成功刪除', 'ys-shopline-payment' ),
                'delete_error'   => __( '刪除失敗，請稍後再試', 'ys-shopline-payment' ),
                'syncing'        => __( '同步中...', 'ys-shopline-payment' ),
                'sync_success'   => __( '同步完成', 'ys-shopline-payment' ),
                'sync_error'     => __( '同步失敗，請稍後再試', 'ys-shopline-payment' ),
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

        // 先刪除 WC Token（instrument_id 就是 token value）
        $this->delete_wc_token_by_instrument_id( $user_id, $instrument_id );

        // 執行 API 解綁
        $api_success = $this->customer_manager->unbind_payment_instrument( $user_id, $instrument_id );

        // 無論 API 是否成功，WC Token 已刪除就算成功
        // （API 可能早已刪除，或網路問題）
        YSLogger::info( '刪除儲存卡', [
            'user_id'       => $user_id,
            'instrument_id' => $instrument_id,
            'api_success'   => $api_success,
        ] );

        wp_send_json_success( [ 'message' => __( '卡片已成功刪除', 'ys-shopline-payment' ) ] );
    }

    /**
     * 根據 instrument ID 刪除 WC Token
     *
     * @param int    $user_id       WordPress 用戶 ID
     * @param string $instrument_id 付款工具 ID（也是 token value）
     * @return bool 是否成功刪除
     */
    private function delete_wc_token_by_instrument_id( int $user_id, string $instrument_id ): bool {
        // 搜尋所有相關 gateway 的 tokens
        $gateway_ids = [ 'ys_shopline_credit', 'ys_shopline_credit_subscription', 'ys_shopline_credit_card' ];

        foreach ( $gateway_ids as $gateway_id ) {
            $tokens = \WC_Payment_Tokens::get_customer_tokens( $user_id, $gateway_id );

            foreach ( $tokens as $token ) {
                if ( $token->get_token() === $instrument_id ) {
                    \WC_Payment_Tokens::delete( $token->get_id() );
                    YSLogger::info( 'WC Token 已刪除', [
                        'user_id'       => $user_id,
                        'token_id'      => $token->get_id(),
                        'gateway_id'    => $gateway_id,
                        'instrument_id' => $instrument_id,
                    ] );
                    return true;
                }
            }
        }

        YSLogger::warning( '找不到要刪除的 WC Token', [
            'user_id'       => $user_id,
            'instrument_id' => $instrument_id,
        ] );
        return false;
    }

    /**
     * 處理 AJAX 同步卡片請求
     */
    public function handle_sync_cards(): void {
        // 驗證 Nonce
        if ( ! wp_verify_nonce( $_POST['nonce'] ?? '', 'ys_shopline_sync_cards' ) ) {
            wp_send_json_error( [ 'message' => __( '安全驗證失敗', 'ys-shopline-payment' ) ] );
        }

        // 驗證登入
        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            wp_send_json_error( [ 'message' => __( '請先登入', 'ys-shopline-payment' ) ] );
        }

        YSLogger::info( '開始同步儲存卡', [ 'user_id' => $user_id ] );

        // 從 API 取得付款工具
        $instruments_array = $this->customer_manager->get_payment_instruments( $user_id, true );

        if ( empty( $instruments_array ) ) {
            wp_send_json_success( [
                'message' => __( '沒有找到儲存的卡片', 'ys-shopline-payment' ),
                'count'   => 0,
            ] );
            return;
        }

        // 取得現有的 WC Token instrument IDs
        $existing_instrument_ids = [];
        $gateway_ids = [ 'ys_shopline_credit', 'ys_shopline_credit_subscription', 'ys_shopline_credit_card' ];
        foreach ( $gateway_ids as $gw_id ) {
            $tokens = \WC_Payment_Tokens::get_customer_tokens( $user_id, $gw_id );
            foreach ( $tokens as $token ) {
                $existing_instrument_ids[] = $token->get_token();
            }
        }

        // 同步到 WC Tokens（只新增不存在的）
        $new_count = 0;
        foreach ( $instruments_array as $instrument ) {
            $instrument_id = $instrument['paymentInstrumentId'] ?? '';
            if ( ! empty( $instrument_id ) && ! in_array( $instrument_id, $existing_instrument_ids, true ) ) {
                $token = $this->create_wc_token_from_instrument( $user_id, $instrument );
                if ( $token ) {
                    $new_count++;
                }
            }
        }

        YSLogger::info( '同步儲存卡完成', [
            'user_id'   => $user_id,
            'api_count' => count( $instruments_array ),
            'new_count' => $new_count,
        ] );

        wp_send_json_success( [
            'message' => sprintf(
                /* translators: %d: number of cards synced */
                __( '同步完成，新增 %d 張卡片', 'ys-shopline-payment' ),
                $new_count
            ),
            'count'   => $new_count,
            'reload'  => $new_count > 0,
        ] );
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

        // 先刪除 WC Token
        $this->delete_wc_token_by_instrument_id( $user_id, $instrument_id );

        // 執行 API 解綁
        $this->customer_manager->unbind_payment_instrument( $user_id, $instrument_id );

        wc_add_notice( __( '卡片已成功刪除', 'ys-shopline-payment' ), 'success' );
    }
}
