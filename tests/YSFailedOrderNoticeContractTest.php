<?php
/**
 * Contract tests for the failed-order notice (v3.6.11).
 *
 * 付款失敗提示只能顯示外掛自己存在訂單上的錯誤訊息。訂單備註裡有店家與其他外掛
 * 寫的內部備註，不得拿來顯示在感謝頁或「我的帳戶」的訂單頁。
 *
 * @package YangSheep\ShoplinePayment\Tests
 */

declare(strict_types=1);

use YangSheep\ShoplinePayment\Frontend\YSOrderDisplay;
use YangSheep\ShoplinePayment\Utils\YSOrderMeta;

if ( ! function_exists( 'wc_get_order_notes' ) ) {
	/**
	 * 測試替身：記錄每一次查詢，回傳預先放好的備註。
	 */
	function wc_get_order_notes( array $args = array() ): array {
		$GLOBALS['ys_test_order_note_queries'][] = $args;
		return $GLOBALS['ys_test_order_notes'] ?? array();
	}
}

final class YS_Failed_Notice_Order extends WC_Order {
	public function __construct(
		private string $status,
		private array $meta
	) {}

	public function get_id(): int {
		return 9601;
	}

	public function get_payment_method(): string {
		return 'ys_shopline_credit';
	}

	public function get_status(): string {
		return $this->status;
	}

	public function is_paid(): bool {
		return false;
	}

	public function get_meta( string $key ) {
		return $this->meta[ $key ] ?? '';
	}

	public function get_checkout_payment_url(): string {
		return 'https://example.test/order-pay/9601';
	}
}

/**
 * Render the customer-facing payment notice for one order.
 *
 * @param string $status Order status.
 * @param array  $meta   Order meta.
 * @return string
 */
function ys_render_failed_notice( string $status, array $meta ): string {
	$GLOBALS['ys_test_order'] = new YS_Failed_Notice_Order( $status, $meta );

	ob_start();
	YSOrderDisplay::instance()->display_payment_status_notice( 9601 );
	return (string) ob_get_clean();
}

function ys_run_failed_order_notice_contract(): void {
	echo "== Failed-order notice: stored payment error only, never order notes ==\n";

	$internal_note = '付款失敗：staff-only 內部備註，請勿對外';
	$GLOBALS['ys_test_order_notes'] = array(
		(object) array(
			'content'       => $internal_note,
			'customer_note' => false,
		),
	);
	$GLOBALS['ys_test_order_note_queries'] = array();

	// 失敗訂單、沒有存錯誤訊息：只顯示通用提示與重新付款。
	$output = ys_render_failed_notice( 'failed', array() );
	YS_Assert::is_true( 'failed order renders the retry action', str_contains( $output, 'ys-shopline-pay-button' ) );
	YS_Assert::is_true( 'failed order without a stored error shows no internal note', ! str_contains( $output, 'staff-only' ) );
	YS_Assert::is_true( 'failed order without a stored error shows no error-details block', ! str_contains( $output, 'ys-shopline-error-details' ) );

	// 失敗訂單、有存錯誤訊息：顯示存下來的那一則。
	$output = ys_render_failed_notice(
		'failed',
		array(
			YSOrderMeta::ERROR_CODE    => '4450',
			YSOrderMeta::ERROR_MESSAGE => '發卡銀行拒絕交易，請改用其他付款方式。',
		)
	);
	YS_Assert::is_true( 'stored payment error message is shown', str_contains( $output, '發卡銀行拒絕交易' ) );
	YS_Assert::is_true( 'stored payment error code is shown', str_contains( $output, '4450' ) );
	YS_Assert::is_true( 'stored payment error never mixes in an internal note', ! str_contains( $output, 'staff-only' ) );

	// 等待付款的訂單同樣不讀備註。
	$output = ys_render_failed_notice( 'pending', array() );
	YS_Assert::is_true( 'pending order shows no internal note', ! str_contains( $output, 'staff-only' ) );

	YS_Assert::eq( 'customer-facing notice never queries order notes', 0, count( $GLOBALS['ys_test_order_note_queries'] ) );

	unset( $GLOBALS['ys_test_order_notes'] );
}
