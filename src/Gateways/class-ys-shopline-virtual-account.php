<?php
/**
 * Virtual Account (ATM) Gateway for YS Shopline Payment.
 *
 * @package YS_Shopline_Payment
 */

defined( 'ABSPATH' ) || exit;

/**
 * YS_Shopline_Virtual_Account Class.
 *
 * ATM/Bank transfer payment gateway.
 */
class YS_Shopline_Virtual_Account extends YS_Shopline_Gateway_Base {

    /**
     * Constructor.
     */
    public function __construct() {
        $this->id                 = 'ys_shopline_atm';
        $this->icon               = '';
        $this->has_fields         = true;
        $this->method_title       = __( 'SHOPLINE ATM 銀行轉帳', 'ys-shopline-via-woocommerce' );
        $this->method_description = __( '透過 SHOPLINE Payment ATM 虛擬帳號付款', 'ys-shopline-via-woocommerce' );

        // Supports
        $this->supports = array(
            'products',
        );

        parent::__construct();
    }

    /**
     * Get payment method for SDK.
     *
     * @return string
     */
    public function get_payment_method() {
        return 'VirtualAccount';
    }

    /**
     * Initialize gateway settings form fields.
     */
    public function init_form_fields() {
        parent::init_form_fields();

        $this->form_fields['expire_days'] = array(
            'title'       => __( '繳費期限（天）', 'ys-shopline-via-woocommerce' ),
            'type'        => 'number',
            'description' => __( '虛擬帳號繳費期限天數，預設 3 天。', 'ys-shopline-via-woocommerce' ),
            'default'     => '3',
            'desc_tip'    => true,
            'custom_attributes' => array(
                'min'  => '1',
                'max'  => '30',
                'step' => '1',
            ),
        );
    }

    /**
     * Payment fields.
     */
    public function payment_fields() {
        if ( $this->description ) {
            echo wpautop( wp_kses_post( $this->description ) );
        }

        // Info message
        $expire_days = $this->get_option( 'expire_days', '3' );
        echo '<p class="ys-shopline-atm-notice">';
        printf(
            /* translators: %d: Number of days for payment */
            esc_html__( '下單後將產生虛擬帳號，請於 %d 天內完成轉帳。', 'ys-shopline-via-woocommerce' ),
            absint( $expire_days )
        );
        echo '</p>';

        // Container for SDK
        printf(
            '<div id="%s_container" class="ys-shopline-payment-container" data-gateway="%s" data-payment-method="%s" style="min-height: 50px;"></div>',
            esc_attr( $this->id ),
            esc_attr( $this->id ),
            esc_attr( $this->get_payment_method() )
        );
    }

    /**
     * Prepare payment data.
     *
     * @param WC_Order $order       Order object.
     * @param string   $pay_session Pay session from SDK.
     * @return array
     */
    protected function prepare_payment_data( $order, $pay_session ) {
        $data = parent::prepare_payment_data( $order, $pay_session );

        // ATM doesn't need card binding
        $data['confirm']['paymentBehavior'] = 'QuickPayment';
        unset( $data['confirm']['paymentInstrument']['savePaymentInstrument'] );

        return $data;
    }

    /**
     * Handle successful API response for ATM.
     *
     * @param WC_Order $order    Order object.
     * @param array    $response API response.
     * @return array
     */
    protected function handle_next_action( $order, $response ) {
        // Store virtual account info
        if ( isset( $response['virtualAccount'] ) ) {
            $va = $response['virtualAccount'];
            $order->update_meta_data( '_ys_shopline_va_bank_code', isset( $va['bankCode'] ) ? $va['bankCode'] : '' );
            $order->update_meta_data( '_ys_shopline_va_account', isset( $va['account'] ) ? $va['account'] : '' );
            $order->update_meta_data( '_ys_shopline_va_expire', isset( $va['expireDate'] ) ? $va['expireDate'] : '' );
        }

        // Set to on-hold waiting for payment
        $pending_status = get_option( 'ys_shopline_order_status_pending', 'on-hold' );
        $order->update_status(
            $pending_status,
            __( 'Awaiting ATM payment.', 'ys-shopline-via-woocommerce' )
        );

        if ( isset( $response['tradeOrderId'] ) ) {
            $order->update_meta_data( '_ys_shopline_trade_order_id', $response['tradeOrderId'] );
        }

        $order->save();

        // Reduce stock
        wc_reduce_stock_levels( $order->get_id() );

        // Empty the cart
        WC()->cart->empty_cart();

        return array(
            'result'   => 'success',
            'redirect' => $this->get_return_url( $order ),
        );
    }

    /**
     * Thank you page - show virtual account info.
     *
     * @param int $order_id Order ID.
     */
    public function thankyou_page( $order_id ) {
        $order = wc_get_order( $order_id );

        if ( ! $order || $order->get_payment_method() !== $this->id ) {
            return;
        }

        $bank_code = $order->get_meta( '_ys_shopline_va_bank_code' );
        $account   = $order->get_meta( '_ys_shopline_va_account' );
        $expire    = $order->get_meta( '_ys_shopline_va_expire' );

        if ( ! $account ) {
            return;
        }

        ?>
        <h2><?php esc_html_e( 'ATM 轉帳資訊', 'ys-shopline-via-woocommerce' ); ?></h2>
        <table class="woocommerce-table shop_table ys-shopline-atm-info">
            <tbody>
                <?php if ( $bank_code ) : ?>
                <tr>
                    <th><?php esc_html_e( '銀行代碼', 'ys-shopline-via-woocommerce' ); ?></th>
                    <td><strong><?php echo esc_html( $bank_code ); ?></strong></td>
                </tr>
                <?php endif; ?>
                <tr>
                    <th><?php esc_html_e( '虛擬帳號', 'ys-shopline-via-woocommerce' ); ?></th>
                    <td><strong style="font-family: monospace; font-size: 1.2em;"><?php echo esc_html( $account ); ?></strong></td>
                </tr>
                <?php if ( $expire ) : ?>
                <tr>
                    <th><?php esc_html_e( '繳費期限', 'ys-shopline-via-woocommerce' ); ?></th>
                    <td><?php echo esc_html( $expire ); ?></td>
                </tr>
                <?php endif; ?>
                <tr>
                    <th><?php esc_html_e( '應付金額', 'ys-shopline-via-woocommerce' ); ?></th>
                    <td><strong><?php echo wp_kses_post( $order->get_formatted_order_total() ); ?></strong></td>
                </tr>
            </tbody>
        </table>
        <p class="ys-shopline-atm-warning">
            <?php esc_html_e( '請於繳費期限前完成轉帳，逾期此虛擬帳號將失效。', 'ys-shopline-via-woocommerce' ); ?>
        </p>
        <?php
    }

    /**
     * Email instructions - show virtual account info.
     *
     * @param WC_Order $order         Order object.
     * @param bool     $sent_to_admin Sent to admin.
     * @param bool     $plain_text    Plain text email.
     */
    public function email_instructions( $order, $sent_to_admin, $plain_text = false ) {
        if ( $order->get_payment_method() !== $this->id ) {
            return;
        }

        if ( $order->has_status( array( 'completed', 'processing' ) ) ) {
            return;
        }

        $bank_code = $order->get_meta( '_ys_shopline_va_bank_code' );
        $account   = $order->get_meta( '_ys_shopline_va_account' );
        $expire    = $order->get_meta( '_ys_shopline_va_expire' );

        if ( ! $account ) {
            return;
        }

        if ( $plain_text ) {
            echo "\n" . esc_html__( 'ATM 轉帳資訊', 'ys-shopline-via-woocommerce' ) . "\n\n";
            if ( $bank_code ) {
                echo esc_html__( '銀行代碼：', 'ys-shopline-via-woocommerce' ) . $bank_code . "\n";
            }
            echo esc_html__( '虛擬帳號：', 'ys-shopline-via-woocommerce' ) . $account . "\n";
            if ( $expire ) {
                echo esc_html__( '繳費期限：', 'ys-shopline-via-woocommerce' ) . $expire . "\n";
            }
            echo "\n";
        } else {
            ?>
            <h2><?php esc_html_e( 'ATM 轉帳資訊', 'ys-shopline-via-woocommerce' ); ?></h2>
            <table cellspacing="0" cellpadding="6" style="width: 100%; border: 1px solid #eee;" border="1">
                <?php if ( $bank_code ) : ?>
                <tr>
                    <th style="text-align: left; border: 1px solid #eee;"><?php esc_html_e( '銀行代碼', 'ys-shopline-via-woocommerce' ); ?></th>
                    <td style="text-align: left; border: 1px solid #eee;"><strong><?php echo esc_html( $bank_code ); ?></strong></td>
                </tr>
                <?php endif; ?>
                <tr>
                    <th style="text-align: left; border: 1px solid #eee;"><?php esc_html_e( '虛擬帳號', 'ys-shopline-via-woocommerce' ); ?></th>
                    <td style="text-align: left; border: 1px solid #eee;"><strong style="font-family: monospace;"><?php echo esc_html( $account ); ?></strong></td>
                </tr>
                <?php if ( $expire ) : ?>
                <tr>
                    <th style="text-align: left; border: 1px solid #eee;"><?php esc_html_e( '繳費期限', 'ys-shopline-via-woocommerce' ); ?></th>
                    <td style="text-align: left; border: 1px solid #eee;"><?php echo esc_html( $expire ); ?></td>
                </tr>
                <?php endif; ?>
            </table>
            <p><?php esc_html_e( '請於繳費期限前完成轉帳，逾期此虛擬帳號將失效。', 'ys-shopline-via-woocommerce' ); ?></p>
            <?php
        }
    }
}
