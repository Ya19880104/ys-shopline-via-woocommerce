<?php
/**
 * Payment methods
 *
 * 自訂付款方式頁面 - 與 SHOPLINE Payment 整合
 *
 * @package YangSheep\ShoplinePayment
 * @version 1.0.0
 */

defined( 'ABSPATH' ) || exit;

$saved_methods = wc_get_customer_saved_methods_list( get_current_user_id() );
$has_methods   = (bool) $saved_methods;

// 取得 Shopline Customer ID（用於判斷是否顯示同步按鈕）
$customer_manager = \YangSheep\ShoplinePayment\Customer\YSCustomer::instance();
$has_shopline_customer = $customer_manager->get_customer_id( get_current_user_id() );

do_action( 'woocommerce_before_account_payment_methods', $has_methods );
?>

<style>
/* YS Shopline Payment - 付款方式頁面樣式 */
.ys-payment-methods {
	display: flex;
	flex-direction: column;
	gap: 12px;
	margin-bottom: 24px;
}

.ys-payment-card {
	display: flex;
	align-items: center;
	justify-content: space-between;
	background: #fff;
	border: 1px solid #e5e5e5;
	border-radius: 12px;
	padding: 16px 20px;
	transition: box-shadow 0.2s ease;
}

.ys-payment-card:hover {
	box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
}

.ys-payment-card.is-default {
	border-color: #4caf50;
	background: linear-gradient(to right, #f8fff8, #fff);
}

.ys-card-left {
	display: flex;
	align-items: center;
	gap: 16px;
}

.ys-card-icon {
	width: 48px;
	height: 32px;
	border-radius: 6px;
	display: flex;
	align-items: center;
	justify-content: center;
	font-weight: bold;
	font-size: 10px;
	color: #fff;
	flex-shrink: 0;
}

.ys-card-icon.visa {
	background: linear-gradient(135deg, #1a1f71, #2a3f90);
}

.ys-card-icon.mastercard {
	background: linear-gradient(135deg, #eb001b, #f79e1b);
}

.ys-card-icon.jcb {
	background: linear-gradient(135deg, #0e4c96, #00a94f);
}

.ys-card-icon.amex,
.ys-card-icon.american_express {
	background: linear-gradient(135deg, #006fcf, #0095d9);
}

.ys-card-icon.unknown {
	background: linear-gradient(135deg, #666, #999);
}

.ys-card-icon svg {
	width: 32px;
	height: 20px;
}

.ys-card-details {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.ys-card-number {
	display: flex;
	align-items: center;
	gap: 8px;
	font-size: 15px;
	font-weight: 600;
	color: #333;
}

.ys-card-brand {
	text-transform: capitalize;
}

.ys-card-last4 {
	color: #666;
	letter-spacing: 1px;
}

.ys-card-meta {
	display: flex;
	align-items: center;
	gap: 12px;
	font-size: 13px;
	color: #888;
}

.ys-card-expiry {
	color: #666;
}

.ys-card-status {
	display: inline-flex;
	align-items: center;
	padding: 2px 8px;
	border-radius: 4px;
	font-size: 12px;
	font-weight: 500;
}

.ys-card-status.active {
	background: #e8f5e9;
	color: #2e7d32;
}

.ys-card-status.expired {
	background: #ffebee;
	color: #c62828;
}

.ys-card-status.default {
	background: #e3f2fd;
	color: #1565c0;
}

.ys-card-actions {
	display: flex;
	gap: 8px;
}

.ys-card-actions .button {
	padding: 8px 16px;
	border-radius: 6px;
	font-size: 13px;
	font-weight: 500;
	text-decoration: none;
	transition: all 0.2s ease;
}

.ys-card-actions .button.delete {
	background: #333;
	color: #fff;
	border: none;
}

.ys-card-actions .button.delete:hover {
	background: #555;
}

.ys-card-actions .button.default {
	background: #fff;
	color: #333;
	border: 1px solid #ddd;
}

.ys-card-actions .button.default:hover {
	background: #f5f5f5;
	border-color: #ccc;
}

/* 按鈕區域 */
.ys-payment-actions {
	display: flex;
	gap: 12px;
	flex-wrap: wrap;
}

.ys-payment-actions .button {
	padding: 12px 24px;
	border-radius: 8px;
	font-size: 14px;
	font-weight: 500;
	text-decoration: none;
	transition: all 0.2s ease;
}

.ys-payment-actions .button.add-method {
	background: #333;
	color: #fff;
	border: none;
}

.ys-payment-actions .button.add-method:hover {
	background: #555;
}

.ys-payment-actions .button.sync-cards {
	background: #fff;
	color: #333;
	border: 1px solid #ddd;
}

.ys-payment-actions .button.sync-cards:hover {
	background: #f5f5f5;
	border-color: #ccc;
}

.ys-payment-actions .button.sync-cards.loading {
	opacity: 0.7;
	pointer-events: none;
}

/* 無卡片提示 */
.ys-no-methods {
	text-align: center;
	padding: 40px 20px;
	background: #f9f9f9;
	border-radius: 12px;
	color: #666;
	margin-bottom: 24px;
}

.ys-no-methods-icon {
	font-size: 48px;
	margin-bottom: 12px;
	opacity: 0.5;
}

/* 訊息提示 */
.ys-sync-message {
	padding: 12px 16px;
	border-radius: 8px;
	margin-bottom: 16px;
	font-size: 14px;
}

.ys-sync-message.success {
	background: #e8f5e9;
	color: #2e7d32;
	border: 1px solid #c8e6c9;
}

.ys-sync-message.error {
	background: #ffebee;
	color: #c62828;
	border: 1px solid #ffcdd2;
}

/* RWD */
@media (max-width: 600px) {
	.ys-payment-card {
		flex-direction: column;
		align-items: flex-start;
		gap: 16px;
	}

	.ys-card-actions {
		width: 100%;
	}

	.ys-card-actions .button {
		flex: 1;
		text-align: center;
	}
}
</style>

<div id="ys-sync-message-container"></div>

<?php if ( $has_methods ) : ?>

	<div class="ys-payment-methods">
		<?php foreach ( $saved_methods as $type => $methods ) : ?>
			<?php foreach ( $methods as $method ) : ?>
				<?php
				// 取得卡片資訊
				$brand       = $method['method']['brand'] ?? 'unknown';
				$brand_class = strtolower( str_replace( ' ', '_', $brand ) );
				$last4       = $method['method']['last4'] ?? '****';
				$expires     = $method['expires'] ?? '';
				$is_default  = ! empty( $method['is_default'] );

				// 解析到期日判斷是否過期
				$is_expired = false;
				if ( $expires && preg_match( '/(\d{1,2})\/(\d{2,4})/', $expires, $matches ) ) {
					$exp_month = (int) $matches[1];
					$exp_year  = (int) $matches[2];
					if ( strlen( $matches[2] ) === 2 ) {
						$exp_year = 2000 + $exp_year;
					}
					$exp_date = \DateTime::createFromFormat( 'Y-m', $exp_year . '-' . str_pad( $exp_month, 2, '0', STR_PAD_LEFT ) );
					if ( $exp_date ) {
						$exp_date->modify( 'last day of this month' );
						$is_expired = $exp_date < new \DateTime();
					}
				}

				$brand_display = ucfirst( strtolower( $brand ) );
				?>
				<div class="ys-payment-card<?php echo $is_default ? ' is-default' : ''; ?>">
					<div class="ys-card-left">
						<div class="ys-card-icon <?php echo esc_attr( $brand_class ); ?>">
							<?php echo esc_html( strtoupper( substr( $brand, 0, 4 ) ) ); ?>
						</div>
						<div class="ys-card-details">
							<div class="ys-card-number">
								<span class="ys-card-brand"><?php echo esc_html( $brand_display ); ?></span>
								<span class="ys-card-last4">•••• <?php echo esc_html( $last4 ); ?></span>
							</div>
							<div class="ys-card-meta">
								<?php if ( $expires ) : ?>
									<span class="ys-card-expiry">到期 <?php echo esc_html( $expires ); ?></span>
								<?php endif; ?>
								<?php if ( $is_expired ) : ?>
									<span class="ys-card-status expired">已過期</span>
								<?php elseif ( $is_default ) : ?>
									<span class="ys-card-status default" title="<?php esc_attr_e( '訂閱續扣時將優先使用此卡片', 'ys-shopline-via-woocommerce' ); ?>">預設</span>
								<?php else : ?>
									<span class="ys-card-status active">有效</span>
								<?php endif; ?>
							</div>
						</div>
					</div>
					<div class="ys-card-actions">
						<?php foreach ( $method['actions'] as $key => $action ) : ?>
							<a href="<?php echo esc_url( $action['url'] ); ?>" class="button <?php echo esc_attr( $key ); ?>">
								<?php echo esc_html( $action['name'] ); ?>
							</a>
						<?php endforeach; ?>
					</div>
				</div>
			<?php endforeach; ?>
		<?php endforeach; ?>
		<p class="ys-payment-hint" style="margin-top: 12px; font-size: 13px; color: #888;">
			<?php esc_html_e( '「預設」卡片將用於訂閱自動續扣。結帳頁的卡片選擇由付款系統自動管理。', 'ys-shopline-via-woocommerce' ); ?>
		</p>
	</div>

<?php else : ?>

	<div class="ys-no-methods">
		<div class="ys-no-methods-icon">💳</div>
		<p><?php esc_html_e( '尚未儲存任何付款方式', 'ys-shopline-via-woocommerce' ); ?></p>
	</div>

<?php endif; ?>

<?php do_action( 'woocommerce_after_account_payment_methods', $has_methods ); ?>

<?php
// 檢查是否可顯示「新增付款方式」按鈕
// WC 原生 get_available_payment_gateways() 在 My Account 頁面會因無 cart/order 而常回空陣列，
// 導致按鈕消失。改為直接檢查 SHOPLINE 信用卡閘道是否啟用且支援 add_payment_method。
$all_gateways          = WC()->payment_gateways->payment_gateways();
$credit_gateway        = isset( $all_gateways['ys_shopline_credit'] ) ? $all_gateways['ys_shopline_credit'] : null;
$can_add_payment_method = $credit_gateway
	&& 'yes' === $credit_gateway->enabled
	&& $credit_gateway->supports( 'add_payment_method' );
?>
<div class="ys-payment-actions">
	<?php if ( $can_add_payment_method ) : ?>
		<a class="button add-method" href="<?php echo esc_url( wc_get_endpoint_url( 'add-payment-method' ) ); ?>">
			<?php esc_html_e( '新增付款方式', 'ys-shopline-via-woocommerce' ); ?>
		</a>
	<?php endif; ?>

	<?php if ( $has_shopline_customer ) : ?>
		<a href="#" class="button sync-cards" id="ys-sync-cards-btn">
			↻ <?php esc_html_e( '同步 SHOPLINE 儲存卡', 'ys-shopline-via-woocommerce' ); ?>
		</a>
	<?php endif; ?>
</div>

<?php if ( $has_shopline_customer ) : ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
	var syncBtn = document.getElementById('ys-sync-cards-btn');
	var messageContainer = document.getElementById('ys-sync-message-container');

	if (syncBtn) {
		syncBtn.addEventListener('click', function(e) {
			e.preventDefault();
			if (syncBtn.classList.contains('loading')) return;

			syncBtn.classList.add('loading');
			var originalText = syncBtn.innerHTML;
			syncBtn.innerHTML = '同步中...';

			// 清除舊訊息
			messageContainer.innerHTML = '';

			var xhr = new XMLHttpRequest();
			xhr.open('POST', '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>');
			xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
			xhr.onload = function() {
				syncBtn.classList.remove('loading');
				syncBtn.innerHTML = originalText;

				if (xhr.status === 200) {
					try {
						var response = JSON.parse(xhr.responseText);
						if (response.success) {
							// 顯示成功訊息
							showMessage('success', response.data.message || '同步完成');
							// 如果有新卡片，重新整理頁面
							if (response.data.synced > 0) {
								setTimeout(function() {
									location.reload();
								}, 1000);
							}
						} else {
							showMessage('error', response.data.message || '同步失敗');
						}
					} catch (err) {
						showMessage('error', '同步失敗：回應格式錯誤');
					}
				} else {
					showMessage('error', '同步失敗：伺服器錯誤');
				}
			};
			xhr.onerror = function() {
				syncBtn.classList.remove('loading');
				syncBtn.innerHTML = originalText;
				showMessage('error', '同步失敗：網路錯誤');
			};
			xhr.send('action=ys_shopline_sync_cards&nonce=<?php echo esc_js( wp_create_nonce( 'ys_shopline_sync_cards' ) ); ?>');
		});
	}

	function showMessage(type, message) {
		var div = document.createElement('div');
		div.className = 'ys-sync-message ' + type;
		div.textContent = message;
		messageContainer.appendChild(div);

		// 5秒後自動消失
		setTimeout(function() {
			div.style.opacity = '0';
			div.style.transition = 'opacity 0.3s ease';
			setTimeout(function() {
				div.remove();
			}, 300);
		}, 5000);
	}
});
</script>
<?php endif; ?>
