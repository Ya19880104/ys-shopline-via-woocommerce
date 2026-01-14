<?php
/**
 * Abstract Gateway
 *
 * @package YangSheep\ShoplinePayment\Gateways
 */

declare(strict_types=1);

namespace YangSheep\ShoplinePayment\Gateways;

use YangSheep\ShoplinePayment\Api\YSShoplineClient;
use YangSheep\ShoplinePayment\Utils\YSLogger;
use YangSheep\ShoplinePayment\Utils\YSOrderMeta;

defined( 'ABSPATH' ) || exit;

/**
 * 抽象付款閘道基底類別
 */
abstract class YSAbstractGateway extends \WC_Payment_Gateway {

    /** @var bool 是否為測試模式 */
    protected bool $testmode;

    /** @var bool 是否啟用除錯 */
    protected bool $debug;

    /** @var YSShoplineClient|null API 客戶端 */
    protected ?YSShoplineClient $api_client = null;

    /**
     * Constructor
     */
    public function __construct() {
        // 載入設定
        $this->init_form_fields();
        $this->init_settings();

        // 定義屬性
        $this->title       = $this->get_option( 'title' );
        $this->description = $this->get_option( 'description' );
        $this->enabled     = $this->get_option( 'enabled' );

        // 全域設定
        $this->testmode = 'yes' === get_option( 'ys_shopline_testmode', 'yes' );
        $this->debug    = 'yes' === get_option( 'ys_shopline_debug', 'no' );

        // 支援的功能
        $this->supports = [
            'products',
            'refunds',
        ];

        // 註冊 Hooks
        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ] );
        add_action( 'woocommerce_thankyou_' . $this->id, [ $this, 'thankyou_page' ] );
        add_action( 'woocommerce_email_before_order_table', [ $this, 'email_instructions' ], 10, 3 );
    }

    /**
     * 取得 API 客戶端
     *
     * @param \WC_Order|null $order 訂單物件
     * @return YSShoplineClient
     */
    protected function get_api_client( ?\WC_Order $order = null ): YSShoplineClient {
        if ( is_null( $this->api_client ) ) {
            $this->api_client = new YSShoplineClient( $order );
        }
        return $this->api_client;
    }

    /**
     * 初始化表單欄位
     */
    public function init_form_fields(): void {
        $this->form_fields = [
            'enabled' => [
                'title'   => __( '啟用/停用', 'ys-shopline-payment' ),
                'type'    => 'checkbox',
                'label'   => sprintf(
                    /* translators: %s: Payment method title */
                    __( '啟用 %s', 'ys-shopline-payment' ),
                    $this->method_title
                ),
                'default' => 'no',
            ],
            'title' => [
                'title'       => __( '標題', 'ys-shopline-payment' ),
                'type'        => 'text',
                'description' => __( '顧客在結帳時看到的付款方式名稱', 'ys-shopline-payment' ),
                'default'     => $this->method_title,
                'desc_tip'    => true,
            ],
            'description' => [
                'title'       => __( '說明', 'ys-shopline-payment' ),
                'type'        => 'textarea',
                'description' => __( '顧客在結帳時看到的付款方式說明', 'ys-shopline-payment' ),
                'default'     => '',
                'desc_tip'    => true,
            ],
        ];
    }

    /**
     * 處理付款
     *
     * @param int $order_id 訂單 ID
     * @return array
     */
    public function process_payment( $order_id ): array {
        $order = wc_get_order( $order_id );

        if ( ! $order ) {
            wc_add_notice( __( '找不到訂單', 'ys-shopline-payment' ), 'error' );
            return [ 'result' => 'failure' ];
        }

        try {
            // 由子類別實作
            $redirect_url = $this->before_process_payment( $order );

            return [
                'result'   => 'success',
                'redirect' => $redirect_url,
            ];
        } catch ( \Throwable $e ) {
            YSLogger::error( "付款處理失敗: {$e->getMessage()}", [
                'order_id' => $order_id,
                'gateway'  => $this->id,
            ] );

            wc_add_notice(
                __( '付款處理失敗，請稍後再試。', 'ys-shopline-payment' ),
                'error'
            );

            return [ 'result' => 'failure' ];
        }
    }

    /**
     * 付款前處理（由子類別實作）
     *
     * @param \WC_Order $order 訂單物件
     * @return string 跳轉 URL
     */
    abstract protected function before_process_payment( \WC_Order $order ): string;

    /**
     * 處理退款
     *
     * @param int        $order_id 訂單 ID
     * @param float|null $amount   退款金額
     * @param string     $reason   退款原因
     * @return bool|\WP_Error
     */
    public function process_refund( $order_id, $amount = null, $reason = '' ) {
        $order = wc_get_order( $order_id );

        if ( ! $order ) {
            return new \WP_Error( 'invalid_order', __( '找不到訂單', 'ys-shopline-payment' ) );
        }

        $trade_order_id = $order->get_meta( YSOrderMeta::TRADE_ORDER_ID );

        if ( empty( $trade_order_id ) ) {
            return new \WP_Error( 'no_trade_id', __( '找不到交易訂單 ID', 'ys-shopline-payment' ) );
        }

        try {
            $client      = $this->get_api_client( $order );
            $refund_dto  = $client->create_refund(
                $trade_order_id,
                (float) $amount,
                $reason,
                $order->get_currency()
            );

            if ( $refund_dto->has_error() ) {
                return new \WP_Error( 'refund_failed', $refund_dto->get_error_message() );
            }

            // 記錄退款資訊
            $order->add_order_note(
                sprintf(
                    /* translators: 1: Refund amount, 2: Refund ID */
                    __( '已透過 Shopline Payment 退款 %1$s。退款編號：%2$s', 'ys-shopline-payment' ),
                    wc_price( $amount ),
                    $refund_dto->refund_order_id
                )
            );

            return true;
        } catch ( \Throwable $e ) {
            YSLogger::error( "退款失敗: {$e->getMessage()}", [
                'order_id' => $order_id,
                'amount'   => $amount,
            ] );

            return new \WP_Error( 'refund_failed', $e->getMessage() );
        }
    }

    /**
     * 感謝頁面輸出
     *
     * @param int $order_id 訂單 ID
     */
    public function thankyou_page( $order_id ): void {
        // 可由子類別覆寫
    }

    /**
     * 郵件說明
     *
     * @param \WC_Order $order         訂單物件
     * @param bool      $sent_to_admin 是否發送給管理員
     * @param bool      $plain_text    是否為純文字
     */
    public function email_instructions( $order, $sent_to_admin, $plain_text = false ): void {
        // 可由子類別覆寫
    }

    /**
     * 記錄日誌
     *
     * @param string $message 訊息
     * @param string $level   日誌等級
     */
    protected function log( string $message, string $level = 'info' ): void {
        if ( $this->debug ) {
            YSLogger::log( $message, $level );
        }
    }

    /**
     * 檢查是否為此閘道的訂單
     *
     * @param int $order_id 訂單 ID
     * @return bool
     */
    protected function is_this_gateway( int $order_id ): bool {
        $order = wc_get_order( $order_id );
        return $order && $order->get_payment_method() === $this->id;
    }
}
