<?php
/**
 * Admin Settings Page for YS Shopline Payment.
 *
 * 獨立設定頁面，風格與結帳強化外掛一致。
 * 使用 TAB 頁籤，一次載入所有設定，確保儲存時不會遺漏。
 *
 * @package YS_Shopline_Payment
 */

defined( 'ABSPATH' ) || exit;

/**
 * YS_Shopline_Admin_Settings Class.
 */
class YS_Shopline_Admin_Settings {

	/**
	 * Instance.
	 *
	 * @var YS_Shopline_Admin_Settings
	 */
	private static $instance = null;

	/**
	 * Option group name.
	 *
	 * @var string
	 */
	private $option_group = 'ys_shopline_payment_settings';

	/**
	 * Get instance.
	 *
	 * @return YS_Shopline_Admin_Settings
	 */
	public static function get_instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
	}

	/**
	 * Add admin menu.
	 */
	public function add_admin_menu() {
		add_menu_page(
			__( 'SHOPLINE Payment 設定', 'ys-shopline-via-woocommerce' ),
			__( 'SHOPLINE 金流', 'ys-shopline-via-woocommerce' ),
			'manage_options',
			'ys_shopline_payment',
			array( $this, 'render_settings_page' ),
			'dashicons-money-alt',
			58
		);
	}

	/**
	 * Enqueue scripts.
	 *
	 * @param string $hook Current admin page.
	 */
	public function enqueue_scripts( $hook ) {
		if ( 'toplevel_page_ys_shopline_payment' !== $hook ) {
			return;
		}

		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'wp-color-picker' );
	}

	/**
	 * Register settings.
	 */
	public function register_settings() {
		// 所有選項都用同一個 group，確保一次儲存
		$options = array(
			// 一般設定
			'ys_shopline_enabled',
			'ys_shopline_testmode',

			// 商店地址
			'ys_shopline_store_address',
			'ys_shopline_store_city',
			'ys_shopline_store_postcode',
			'ys_shopline_store_country',

			// Sandbox API
			'ys_shopline_sandbox_merchant_id',
			'ys_shopline_sandbox_api_key',
			'ys_shopline_sandbox_client_key',
			'ys_shopline_sandbox_sign_key',

			// Production API
			'ys_shopline_merchant_id',
			'ys_shopline_api_key',
			'ys_shopline_client_key',
			'ys_shopline_sign_key',

			// 支付方式
			'ys_shopline_credit_enabled',
			'ys_shopline_credit_subscription_enabled',
			'ys_shopline_atm_enabled',
			'ys_shopline_jkopay_enabled',
			'ys_shopline_applepay_enabled',
			'ys_shopline_linepay_enabled',
			'ys_shopline_bnpl_enabled',

			// 進階設定
			'ys_shopline_debug_log',
			'ys_shopline_order_status_paid',
			'ys_shopline_order_status_pending',
		);

		foreach ( $options as $option ) {
			register_setting( $this->option_group, $option, array(
				'sanitize_callback' => array( $this, 'sanitize_option' ),
			) );
		}
	}

	/**
	 * Sanitize option.
	 *
	 * @param mixed $value Option value.
	 * @return mixed
	 */
	public function sanitize_option( $value ) {
		if ( is_string( $value ) ) {
			return sanitize_text_field( $value );
		}
		return $value;
	}

	/**
	 * Render settings page.
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// 處理儲存
		if ( isset( $_POST['submit'] ) && check_admin_referer( $this->option_group . '-options' ) ) {
			$this->save_settings();
		}

		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'general';
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline" style="display:none;"><?php echo esc_html( get_admin_page_title() ); ?></h1>
		</div>

		<div class="ys-settings-wrap">
			<div class="ys-settings-header">
				<h2><span class="dashicons dashicons-money-alt"></span> <?php _e( 'SHOPLINE Payment 設定', 'ys-shopline-via-woocommerce' ); ?></h2>
				<p class="ys-settings-desc"><?php _e( '整合 SHOPLINE Payment 金流服務至 WooCommerce', 'ys-shopline-via-woocommerce' ); ?></p>
			</div>

			<nav class="nav-tab-wrapper ys-settings-tabs">
				<a href="#" class="nav-tab ys-tab-link <?php echo 'general' === $active_tab ? 'nav-tab-active' : ''; ?>" data-tab="general">
					<span class="dashicons dashicons-admin-generic"></span> <?php _e( '一般設定', 'ys-shopline-via-woocommerce' ); ?>
				</a>
				<a href="#" class="nav-tab ys-tab-link <?php echo 'api' === $active_tab ? 'nav-tab-active' : ''; ?>" data-tab="api">
					<span class="dashicons dashicons-admin-network"></span> <?php _e( 'API 設定', 'ys-shopline-via-woocommerce' ); ?>
				</a>
				<a href="#" class="nav-tab ys-tab-link <?php echo 'gateways' === $active_tab ? 'nav-tab-active' : ''; ?>" data-tab="gateways">
					<span class="dashicons dashicons-cart"></span> <?php _e( '支付方式', 'ys-shopline-via-woocommerce' ); ?>
				</a>
				<a href="#" class="nav-tab ys-tab-link <?php echo 'advanced' === $active_tab ? 'nav-tab-active' : ''; ?>" data-tab="advanced">
					<span class="dashicons dashicons-admin-tools"></span> <?php _e( '進階設定', 'ys-shopline-via-woocommerce' ); ?>
				</a>
				<a href="#" class="nav-tab ys-tab-link <?php echo 'docs' === $active_tab ? 'nav-tab-active' : ''; ?>" data-tab="docs">
					<span class="dashicons dashicons-book"></span> <?php _e( '說明文件', 'ys-shopline-via-woocommerce' ); ?>
				</a>
			</nav>

			<form method="post" action="" class="ys-settings-form">
				<?php wp_nonce_field( $this->option_group . '-options' ); ?>

				<!-- Tab: 一般設定 -->
				<div class="ys-tab-content" id="ys-tab-general" style="<?php echo 'general' !== $active_tab ? 'display:none;' : ''; ?>">
					<?php $this->render_general_tab(); ?>
				</div>

				<!-- Tab: API 設定 -->
				<div class="ys-tab-content" id="ys-tab-api" style="<?php echo 'api' !== $active_tab ? 'display:none;' : ''; ?>">
					<?php $this->render_api_tab(); ?>
				</div>

				<!-- Tab: 支付方式 -->
				<div class="ys-tab-content" id="ys-tab-gateways" style="<?php echo 'gateways' !== $active_tab ? 'display:none;' : ''; ?>">
					<?php $this->render_gateways_tab(); ?>
				</div>

				<!-- Tab: 進階設定 -->
				<div class="ys-tab-content" id="ys-tab-advanced" style="<?php echo 'advanced' !== $active_tab ? 'display:none;' : ''; ?>">
					<?php $this->render_advanced_tab(); ?>
				</div>

				<!-- Tab: 說明文件 -->
				<div class="ys-tab-content" id="ys-tab-docs" style="<?php echo 'docs' !== $active_tab ? 'display:none;' : ''; ?>">
					<?php $this->render_docs_tab(); ?>
				</div>

				<div class="ys-submit-wrap" id="ys-submit-button" style="<?php echo 'docs' === $active_tab ? 'display:none;' : ''; ?>">
					<?php submit_button( __( '儲存設定', 'ys-shopline-via-woocommerce' ), 'primary large', 'submit', false ); ?>
				</div>
			</form>
		</div>

		<?php
		$this->render_styles();
		$this->render_scripts();
	}

	/**
	 * Save settings.
	 */
	private function save_settings() {
		// Checkbox 需要特別處理（未勾選時不會傳送）
		$checkboxes = array(
			'ys_shopline_enabled',
			'ys_shopline_testmode',
			'ys_shopline_credit_enabled',
			'ys_shopline_credit_subscription_enabled',
			'ys_shopline_atm_enabled',
			'ys_shopline_jkopay_enabled',
			'ys_shopline_applepay_enabled',
			'ys_shopline_linepay_enabled',
			'ys_shopline_bnpl_enabled',
			'ys_shopline_debug_log',
		);

		foreach ( $checkboxes as $checkbox ) {
			$value = isset( $_POST[ $checkbox ] ) ? 'yes' : 'no';
			update_option( $checkbox, $value );
		}

		// 文字欄位
		$text_fields = array(
			'ys_shopline_store_address',
			'ys_shopline_store_city',
			'ys_shopline_store_postcode',
			'ys_shopline_store_country',
			'ys_shopline_sandbox_merchant_id',
			'ys_shopline_sandbox_api_key',
			'ys_shopline_sandbox_client_key',
			'ys_shopline_sandbox_sign_key',
			'ys_shopline_merchant_id',
			'ys_shopline_api_key',
			'ys_shopline_client_key',
			'ys_shopline_sign_key',
			'ys_shopline_order_status_paid',
			'ys_shopline_order_status_pending',
		);

		foreach ( $text_fields as $field ) {
			if ( isset( $_POST[ $field ] ) ) {
				update_option( $field, sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) );
			}
		}

		add_settings_error(
			'ys_shopline_messages',
			'ys_shopline_message',
			__( '設定已儲存。', 'ys-shopline-via-woocommerce' ),
			'updated'
		);
	}

	/**
	 * Render general tab.
	 */
	private function render_general_tab() {
		?>
		<div class="ys-section-card">
			<h3 class="ys-section-title"><span class="dashicons dashicons-admin-settings"></span> <?php _e( '基本設定', 'ys-shopline-via-woocommerce' ); ?></h3>

			<table class="form-table">
				<tr>
					<th scope="row"><?php _e( '啟用 SHOPLINE Payment', 'ys-shopline-via-woocommerce' ); ?></th>
					<td>
						<?php $this->render_toggle( 'ys_shopline_enabled', 'yes' ); ?>
						<span class="ys-toggle-desc"><?php _e( '啟用 SHOPLINE Payment 金流服務', 'ys-shopline-via-woocommerce' ); ?></span>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php _e( '測試模式', 'ys-shopline-via-woocommerce' ); ?></th>
					<td>
						<?php $this->render_toggle( 'ys_shopline_testmode', 'yes' ); ?>
						<span class="ys-toggle-desc"><?php _e( '使用 SHOPLINE Sandbox 測試環境', 'ys-shopline-via-woocommerce' ); ?></span>
					</td>
				</tr>
			</table>
		</div>

		<?php $this->render_phone_notice(); ?>
		<?php
	}

	/**
	 * Render API tab.
	 */
	private function render_api_tab() {
		?>
		<!-- 商店地址設定 -->
		<div class="ys-section-card">
			<h3 class="ys-section-title"><span class="dashicons dashicons-location"></span> <?php _e( '商店地址設定', 'ys-shopline-via-woocommerce' ); ?></h3>
			<p class="ys-section-desc">
				<span class="dashicons dashicons-info" style="color:#0073aa;"></span>
				<?php _e( 'SHOPLINE Payment API 需要顧客地址資訊。系統會自動依序嘗試取得地址：', 'ys-shopline-via-woocommerce' ); ?>
				<br>
				<strong><?php _e( '運送地址 → 帳單地址 → 此處設定的商店地址', 'ys-shopline-via-woocommerce' ); ?></strong>
				<br><br>
				<?php _e( '若您的結帳頁面未顯示地址欄位（例如純虛擬商品、超取物流），請務必填寫此商店地址作為備用，否則可能導致付款失敗。', 'ys-shopline-via-woocommerce' ); ?>
			</p>

			<table class="form-table">
				<tr>
					<th scope="row"><?php _e( '商店地址', 'ys-shopline-via-woocommerce' ); ?> <span class="required">*</span></th>
					<td>
						<input type="text" name="ys_shopline_store_address" value="<?php echo esc_attr( get_option( 'ys_shopline_store_address', '' ) ); ?>" class="regular-text" placeholder="<?php esc_attr_e( '例如：信義路五段7號', 'ys-shopline-via-woocommerce' ); ?>" />
						<p class="description"><?php _e( '街道地址', 'ys-shopline-via-woocommerce' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php _e( '城市', 'ys-shopline-via-woocommerce' ); ?> <span class="required">*</span></th>
					<td>
						<input type="text" name="ys_shopline_store_city" value="<?php echo esc_attr( get_option( 'ys_shopline_store_city', '' ) ); ?>" class="regular-text" placeholder="<?php esc_attr_e( '例如：台北市', 'ys-shopline-via-woocommerce' ); ?>" />
					</td>
				</tr>
				<tr>
					<th scope="row"><?php _e( '郵遞區號', 'ys-shopline-via-woocommerce' ); ?> <span class="required">*</span></th>
					<td>
						<input type="text" name="ys_shopline_store_postcode" value="<?php echo esc_attr( get_option( 'ys_shopline_store_postcode', '' ) ); ?>" class="small-text" placeholder="<?php esc_attr_e( '例如：110', 'ys-shopline-via-woocommerce' ); ?>" />
					</td>
				</tr>
				<tr>
					<th scope="row"><?php _e( '國家/地區', 'ys-shopline-via-woocommerce' ); ?> <span class="required">*</span></th>
					<td>
						<select name="ys_shopline_store_country" class="regular-text">
							<?php
							$countries = array(
								'TW' => __( '台灣', 'ys-shopline-via-woocommerce' ),
								'HK' => __( '香港', 'ys-shopline-via-woocommerce' ),
								'MY' => __( '馬來西亞', 'ys-shopline-via-woocommerce' ),
								'SG' => __( '新加坡', 'ys-shopline-via-woocommerce' ),
								'TH' => __( '泰國', 'ys-shopline-via-woocommerce' ),
							);
							$current   = get_option( 'ys_shopline_store_country', 'TW' );
							foreach ( $countries as $code => $name ) {
								printf(
									'<option value="%s" %s>%s</option>',
									esc_attr( $code ),
									selected( $current, $code, false ),
									esc_html( $name )
								);
							}
							?>
						</select>
					</td>
				</tr>
			</table>
		</div>

		<!-- Sandbox API -->
		<div class="ys-section-card">
			<h3 class="ys-section-title"><span class="dashicons dashicons-admin-network"></span> <?php _e( '測試環境 API 設定', 'ys-shopline-via-woocommerce' ); ?></h3>
			<p class="ys-section-desc"><?php _e( '請填入 SHOPLINE 提供的 Sandbox 測試環境 API 憑證。', 'ys-shopline-via-woocommerce' ); ?></p>

			<table class="form-table">
				<tr>
					<th scope="row"><?php _e( 'Sandbox Merchant ID', 'ys-shopline-via-woocommerce' ); ?></th>
					<td>
						<input type="text" name="ys_shopline_sandbox_merchant_id" value="<?php echo esc_attr( get_option( 'ys_shopline_sandbox_merchant_id', '' ) ); ?>" class="regular-text" />
						<p class="description"><?php _e( '測試環境的特店 ID', 'ys-shopline-via-woocommerce' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php _e( 'Sandbox API Key', 'ys-shopline-via-woocommerce' ); ?></th>
					<td>
						<input type="password" name="ys_shopline_sandbox_api_key" value="<?php echo esc_attr( get_option( 'ys_shopline_sandbox_api_key', '' ) ); ?>" class="regular-text" />
						<p class="description"><?php _e( '測試環境的 API 金鑰', 'ys-shopline-via-woocommerce' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php _e( 'Sandbox Client Key', 'ys-shopline-via-woocommerce' ); ?></th>
					<td>
						<input type="text" name="ys_shopline_sandbox_client_key" value="<?php echo esc_attr( get_option( 'ys_shopline_sandbox_client_key', '' ) ); ?>" class="regular-text" />
						<p class="description"><?php _e( '測試環境的 SDK Client Key', 'ys-shopline-via-woocommerce' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php _e( 'Sandbox Webhook Sign Key', 'ys-shopline-via-woocommerce' ); ?></th>
					<td>
						<input type="password" name="ys_shopline_sandbox_sign_key" value="<?php echo esc_attr( get_option( 'ys_shopline_sandbox_sign_key', '' ) ); ?>" class="regular-text" />
						<p class="description"><?php _e( '測試環境的 Webhook 簽名金鑰', 'ys-shopline-via-woocommerce' ); ?></p>
					</td>
				</tr>
			</table>
		</div>

		<!-- Production API -->
		<div class="ys-section-card">
			<h3 class="ys-section-title"><span class="dashicons dashicons-admin-site-alt3"></span> <?php _e( '正式環境 API 設定', 'ys-shopline-via-woocommerce' ); ?></h3>
			<p class="ys-section-desc"><?php _e( '請填入 SHOPLINE 提供的正式環境 API 憑證。', 'ys-shopline-via-woocommerce' ); ?></p>

			<table class="form-table">
				<tr>
					<th scope="row"><?php _e( 'Merchant ID', 'ys-shopline-via-woocommerce' ); ?></th>
					<td>
						<input type="text" name="ys_shopline_merchant_id" value="<?php echo esc_attr( get_option( 'ys_shopline_merchant_id', '' ) ); ?>" class="regular-text" />
						<p class="description"><?php _e( '正式環境的特店 ID', 'ys-shopline-via-woocommerce' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php _e( 'API Key', 'ys-shopline-via-woocommerce' ); ?></th>
					<td>
						<input type="password" name="ys_shopline_api_key" value="<?php echo esc_attr( get_option( 'ys_shopline_api_key', '' ) ); ?>" class="regular-text" />
						<p class="description"><?php _e( '正式環境的 API 金鑰', 'ys-shopline-via-woocommerce' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php _e( 'Client Key', 'ys-shopline-via-woocommerce' ); ?></th>
					<td>
						<input type="text" name="ys_shopline_client_key" value="<?php echo esc_attr( get_option( 'ys_shopline_client_key', '' ) ); ?>" class="regular-text" />
						<p class="description"><?php _e( '正式環境的 SDK Client Key', 'ys-shopline-via-woocommerce' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php _e( 'Webhook Sign Key', 'ys-shopline-via-woocommerce' ); ?></th>
					<td>
						<input type="password" name="ys_shopline_sign_key" value="<?php echo esc_attr( get_option( 'ys_shopline_sign_key', '' ) ); ?>" class="regular-text" />
						<p class="description"><?php _e( '正式環境的 Webhook 簽名金鑰', 'ys-shopline-via-woocommerce' ); ?></p>
					</td>
				</tr>
			</table>
		</div>

		<!-- Webhook URL -->
		<div class="ys-section-card">
			<h3 class="ys-section-title"><span class="dashicons dashicons-admin-links"></span> <?php _e( 'Webhook 設定', 'ys-shopline-via-woocommerce' ); ?></h3>
			<p class="ys-section-desc">
				<?php _e( '請在 SHOPLINE 後台設定以下 Webhook URL：', 'ys-shopline-via-woocommerce' ); ?>
			</p>
			<div class="ys-webhook-url">
				<code><?php echo esc_html( home_url( 'wc-api/ys_shopline_webhook' ) ); ?></code>
				<button type="button" class="button button-secondary ys-copy-btn" data-copy="<?php echo esc_attr( home_url( 'wc-api/ys_shopline_webhook' ) ); ?>">
					<span class="dashicons dashicons-clipboard"></span> <?php _e( '複製', 'ys-shopline-via-woocommerce' ); ?>
				</button>
			</div>
		</div>
		<?php
	}

	/**
	 * Render gateways tab.
	 */
	private function render_gateways_tab() {
		?>
		<div class="ys-section-card">
			<h3 class="ys-section-title"><span class="dashicons dashicons-cart"></span> <?php _e( '支付方式設定', 'ys-shopline-via-woocommerce' ); ?></h3>
			<p class="ys-section-desc">
				<?php _e( '選擇要啟用的支付方式。啟用後會自動註冊到 WooCommerce 付款閘道。', 'ys-shopline-via-woocommerce' ); ?>
				<br>
				<span class="dashicons dashicons-info" style="color:#0073aa;"></span>
				<?php
				printf(
					/* translators: %s: WooCommerce payments settings URL */
					__( '各支付方式的詳細設定請至 <a href="%s">WooCommerce > 設定 > 付款</a> 中調整。', 'ys-shopline-via-woocommerce' ),
					esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout' ) )
				);
				?>
			</p>

			<table class="form-table ys-gateway-table">
				<tr>
					<th scope="row">
						<span class="ys-gateway-icon dashicons dashicons-id-alt"></span>
						<?php _e( '信用卡', 'ys-shopline-via-woocommerce' ); ?>
					</th>
					<td>
						<?php $this->render_toggle( 'ys_shopline_credit_enabled', 'yes' ); ?>
						<span class="ys-toggle-desc"><?php _e( '支援國內外信用卡、分期付款', 'ys-shopline-via-woocommerce' ); ?></span>
					</td>
				</tr>

				<?php if ( class_exists( 'WC_Subscriptions' ) ) : ?>
				<tr>
					<th scope="row">
						<span class="ys-gateway-icon dashicons dashicons-update-alt"></span>
						<?php _e( '信用卡訂閱', 'ys-shopline-via-woocommerce' ); ?>
					</th>
					<td>
						<?php $this->render_toggle( 'ys_shopline_credit_subscription_enabled', 'yes' ); ?>
						<span class="ys-toggle-desc"><?php _e( '支援 WooCommerce Subscriptions 定期定額扣款', 'ys-shopline-via-woocommerce' ); ?></span>
					</td>
				</tr>
				<?php endif; ?>

				<tr>
					<th scope="row">
						<span class="ys-gateway-icon dashicons dashicons-building"></span>
						<?php _e( 'ATM 銀行轉帳', 'ys-shopline-via-woocommerce' ); ?>
					</th>
					<td>
						<?php $this->render_toggle( 'ys_shopline_atm_enabled', 'yes' ); ?>
						<span class="ys-toggle-desc"><?php _e( 'ATM 虛擬帳號付款', 'ys-shopline-via-woocommerce' ); ?></span>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<span class="ys-gateway-icon dashicons dashicons-smartphone"></span>
						<?php _e( '街口支付', 'ys-shopline-via-woocommerce' ); ?>
					</th>
					<td>
						<?php $this->render_toggle( 'ys_shopline_jkopay_enabled', 'yes' ); ?>
						<span class="ys-toggle-desc"><?php _e( 'JKOPay 街口支付', 'ys-shopline-via-woocommerce' ); ?></span>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<span class="ys-gateway-icon dashicons dashicons-phone"></span>
						<?php _e( 'Apple Pay', 'ys-shopline-via-woocommerce' ); ?>
					</th>
					<td>
						<?php $this->render_toggle( 'ys_shopline_applepay_enabled', 'yes' ); ?>
						<span class="ys-toggle-desc"><?php _e( 'Apple Pay 快速結帳', 'ys-shopline-via-woocommerce' ); ?></span>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<span class="ys-gateway-icon dashicons dashicons-format-chat"></span>
						<?php _e( 'LINE Pay', 'ys-shopline-via-woocommerce' ); ?>
					</th>
					<td>
						<?php $this->render_toggle( 'ys_shopline_linepay_enabled', 'yes' ); ?>
						<span class="ys-toggle-desc"><?php _e( 'LINE Pay 行動支付', 'ys-shopline-via-woocommerce' ); ?></span>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<span class="ys-gateway-icon dashicons dashicons-money"></span>
						<?php _e( '中租 zingla 銀角零卡', 'ys-shopline-via-woocommerce' ); ?>
					</th>
					<td>
						<?php $this->render_toggle( 'ys_shopline_bnpl_enabled', 'yes' ); ?>
						<span class="ys-toggle-desc"><?php _e( 'Chailease BNPL 先買後付', 'ys-shopline-via-woocommerce' ); ?></span>
					</td>
				</tr>
			</table>
		</div>
		<?php
	}

	/**
	 * Render advanced tab.
	 */
	private function render_advanced_tab() {
		$order_statuses = function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : array();
		?>
		<div class="ys-section-card">
			<h3 class="ys-section-title"><span class="dashicons dashicons-admin-tools"></span> <?php _e( '進階設定', 'ys-shopline-via-woocommerce' ); ?></h3>

			<table class="form-table">
				<tr>
					<th scope="row"><?php _e( 'Debug Log', 'ys-shopline-via-woocommerce' ); ?></th>
					<td>
						<?php $this->render_toggle( 'ys_shopline_debug_log', 'no' ); ?>
						<span class="ys-toggle-desc">
							<?php _e( '啟用 Debug 日誌記錄', 'ys-shopline-via-woocommerce' ); ?>
						</span>
						<p class="description">
							<?php
							printf(
								/* translators: %s: Log file path */
								__( '日誌路徑：<code>%s</code>', 'ys-shopline-via-woocommerce' ),
								function_exists( 'wc_get_log_file_path' ) ? wc_get_log_file_path( 'ys-shopline-payment' ) : 'wc-logs/ys-shopline-payment-*.log'
							);
							?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php _e( '訂單狀態 - 付款成功', 'ys-shopline-via-woocommerce' ); ?></th>
					<td>
						<select name="ys_shopline_order_status_paid" class="regular-text">
							<?php
							$current = get_option( 'ys_shopline_order_status_paid', 'wc-processing' );
							foreach ( $order_statuses as $status => $label ) {
								printf(
									'<option value="%s" %s>%s</option>',
									esc_attr( $status ),
									selected( $current, $status, false ),
									esc_html( $label )
								);
							}
							?>
						</select>
						<p class="description"><?php _e( '付款成功後的訂單狀態', 'ys-shopline-via-woocommerce' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php _e( '訂單狀態 - 等待付款', 'ys-shopline-via-woocommerce' ); ?></th>
					<td>
						<select name="ys_shopline_order_status_pending" class="regular-text">
							<?php
							$current = get_option( 'ys_shopline_order_status_pending', 'wc-on-hold' );
							foreach ( $order_statuses as $status => $label ) {
								printf(
									'<option value="%s" %s>%s</option>',
									esc_attr( $status ),
									selected( $current, $status, false ),
									esc_html( $label )
								);
							}
							?>
						</select>
						<p class="description"><?php _e( '等待付款確認（如 ATM 轉帳）時的訂單狀態', 'ys-shopline-via-woocommerce' ); ?></p>
					</td>
				</tr>
			</table>
		</div>
		<?php
	}

	/**
	 * Render docs tab.
	 */
	private function render_docs_tab() {
		?>
		<div class="ys-docs-card">
			<h2><span class="dashicons dashicons-book"></span> <?php _e( 'SHOPLINE Payment 使用說明', 'ys-shopline-via-woocommerce' ); ?></h2>
			<p><?php _e( '本外掛整合 SHOPLINE Payment 金流服務至 WooCommerce，支援多種支付方式。', 'ys-shopline-via-woocommerce' ); ?></p>

			<div class="ys-docs-section">
				<h3><span class="dashicons dashicons-admin-network"></span> <?php _e( '1. 取得 API 憑證', 'ys-shopline-via-woocommerce' ); ?></h3>
				<ol>
					<li><?php _e( '登入 SHOPLINE Payment 商家後台', 'ys-shopline-via-woocommerce' ); ?></li>
					<li><?php _e( '前往「設定」>「API 設定」', 'ys-shopline-via-woocommerce' ); ?></li>
					<li><?php _e( '複製 Merchant ID、API Key、Client Key、Webhook Sign Key', 'ys-shopline-via-woocommerce' ); ?></li>
					<li><?php _e( '貼入本外掛對應欄位', 'ys-shopline-via-woocommerce' ); ?></li>
				</ol>
			</div>

			<div class="ys-docs-section">
				<h3><span class="dashicons dashicons-admin-links"></span> <?php _e( '2. 設定 Webhook', 'ys-shopline-via-woocommerce' ); ?></h3>
				<p><?php _e( '在 SHOPLINE 後台的 Webhook 設定中，新增以下 URL：', 'ys-shopline-via-woocommerce' ); ?></p>
				<code style="display:block;padding:10px;background:#f5f5f5;border-radius:4px;"><?php echo esc_html( home_url( 'wc-api/ys_shopline_webhook' ) ); ?></code>
			</div>

			<div class="ys-docs-section">
				<h3><span class="dashicons dashicons-location"></span> <?php _e( '3. 關於商店地址', 'ys-shopline-via-woocommerce' ); ?></h3>
				<p><?php _e( 'SHOPLINE Payment API 需要顧客地址資訊進行風險評估。系統會自動按以下順序取得地址：', 'ys-shopline-via-woocommerce' ); ?></p>
				<ol>
					<li><strong><?php _e( '運送地址', 'ys-shopline-via-woocommerce' ); ?></strong> - <?php _e( '結帳時填寫的運送地址', 'ys-shopline-via-woocommerce' ); ?></li>
					<li><strong><?php _e( '帳單地址', 'ys-shopline-via-woocommerce' ); ?></strong> - <?php _e( '結帳時填寫的帳單地址', 'ys-shopline-via-woocommerce' ); ?></li>
					<li><strong><?php _e( '商店地址', 'ys-shopline-via-woocommerce' ); ?></strong> - <?php _e( '在「API 設定」中設定的商店地址', 'ys-shopline-via-woocommerce' ); ?></li>
				</ol>
				<p><strong><?php _e( '建議：', 'ys-shopline-via-woocommerce' ); ?></strong> <?php _e( '若您的商店有「超取物流」或「純虛擬商品」等不需要地址的情況，請務必填寫商店地址作為備用。', 'ys-shopline-via-woocommerce' ); ?></p>
			</div>

			<div class="ys-docs-section">
				<h3><span class="dashicons dashicons-cart"></span> <?php _e( '4. 支付方式說明', 'ys-shopline-via-woocommerce' ); ?></h3>
				<ul>
					<li><strong><?php _e( '信用卡', 'ys-shopline-via-woocommerce' ); ?></strong> - <?php _e( '支援國內外信用卡、分期付款、儲存卡片', 'ys-shopline-via-woocommerce' ); ?></li>
					<li><strong><?php _e( '信用卡訂閱', 'ys-shopline-via-woocommerce' ); ?></strong> - <?php _e( '搭配 WooCommerce Subscriptions 進行定期定額扣款', 'ys-shopline-via-woocommerce' ); ?></li>
					<li><strong><?php _e( 'ATM 銀行轉帳', 'ys-shopline-via-woocommerce' ); ?></strong> - <?php _e( '產生虛擬帳號，顧客轉帳後自動確認', 'ys-shopline-via-woocommerce' ); ?></li>
					<li><strong><?php _e( '街口支付', 'ys-shopline-via-woocommerce' ); ?></strong> - <?php _e( '掃描 QR Code 或跳轉街口 App 付款', 'ys-shopline-via-woocommerce' ); ?></li>
					<li><strong><?php _e( 'Apple Pay', 'ys-shopline-via-woocommerce' ); ?></strong> - <?php _e( 'iOS 裝置快速結帳', 'ys-shopline-via-woocommerce' ); ?></li>
					<li><strong><?php _e( 'LINE Pay', 'ys-shopline-via-woocommerce' ); ?></strong> - <?php _e( '跳轉 LINE Pay 完成付款', 'ys-shopline-via-woocommerce' ); ?></li>
					<li><strong><?php _e( '中租 zingla', 'ys-shopline-via-woocommerce' ); ?></strong> - <?php _e( '先買後付，分期零利率', 'ys-shopline-via-woocommerce' ); ?></li>
				</ul>
			</div>

			<div class="ys-docs-section">
				<h3><span class="dashicons dashicons-id-alt"></span> <?php _e( '5. 儲存卡片功能', 'ys-shopline-via-woocommerce' ); ?></h3>
				<p><?php _e( '會員可以在「我的帳號」>「付款方式」管理已儲存的信用卡：', 'ys-shopline-via-woocommerce' ); ?></p>
				<ul>
					<li><?php _e( '結帳時選擇「儲存卡片」即可保存', 'ys-shopline-via-woocommerce' ); ?></li>
					<li><?php _e( '下次結帳可直接選用已儲存的卡片', 'ys-shopline-via-woocommerce' ); ?></li>
					<li><?php _e( '支援從「新增付款方式」頁面直接綁卡', 'ys-shopline-via-woocommerce' ); ?></li>
				</ul>
			</div>

			<div class="ys-docs-section">
				<h3><span class="dashicons dashicons-sos"></span> <?php _e( '6. 常見問題', 'ys-shopline-via-woocommerce' ); ?></h3>
				<p><strong>Q: <?php _e( '付款失敗顯示「地址資訊不完整」？', 'ys-shopline-via-woocommerce' ); ?></strong></p>
				<p>A: <?php _e( '請確認「API 設定」中已填寫商店地址，或結帳頁面有地址欄位。', 'ys-shopline-via-woocommerce' ); ?></p>

				<p><strong>Q: <?php _e( 'Webhook 無法接收？', 'ys-shopline-via-woocommerce' ); ?></strong></p>
				<p>A: <?php _e( '請確認 Webhook URL 正確、SSL 憑證有效、Sign Key 正確。', 'ys-shopline-via-woocommerce' ); ?></p>
			</div>
		</div>
		<?php
	}

	/**
	 * Render toggle switch.
	 *
	 * @param string $name    Option name.
	 * @param string $default Default value.
	 */
	private function render_toggle( $name, $default = 'no' ) {
		$value = get_option( $name, $default );
		?>
		<label class="ys-toggle-switch">
			<input type="checkbox" name="<?php echo esc_attr( $name ); ?>" value="yes" <?php checked( $value, 'yes' ); ?> />
			<span class="ys-toggle-slider"></span>
		</label>
		<?php
	}

	/**
	 * Render phone notice.
	 */
	private function render_phone_notice() {
		$checkout_fields = function_exists( 'WC' ) && WC()->checkout() ? WC()->checkout()->get_checkout_fields() : array();
		$phone_enabled   = isset( $checkout_fields['billing']['billing_phone'] );
		?>
		<div class="ys-section-card">
			<h3 class="ys-section-title"><span class="dashicons dashicons-warning"></span> <?php _e( '重要設定提醒', 'ys-shopline-via-woocommerce' ); ?></h3>

			<div class="ys-notice-item <?php echo $phone_enabled ? 'ys-notice-success' : 'ys-notice-error'; ?>">
				<?php if ( $phone_enabled ) : ?>
					<span class="dashicons dashicons-yes-alt"></span>
					<?php _e( '帳單電話欄位已啟用', 'ys-shopline-via-woocommerce' ); ?>
				<?php else : ?>
					<span class="dashicons dashicons-dismiss"></span>
					<strong><?php _e( '帳單電話欄位未啟用！', 'ys-shopline-via-woocommerce' ); ?></strong>
					<br>
					<?php _e( 'SHOPLINE Payment 需要顧客電話進行付款驗證。', 'ys-shopline-via-woocommerce' ); ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-settings&tab=account' ) ); ?>" class="button button-secondary" style="margin-left:10px;">
						<?php _e( '前往設定', 'ys-shopline-via-woocommerce' ); ?>
					</a>
				<?php endif; ?>
			</div>

			<div class="ys-notice-item ys-notice-info">
				<span class="dashicons dashicons-info"></span>
				<?php _e( '台灣手機號碼格式：09XXXXXXXX（共 10 碼），系統會自動加入國碼 +886', 'ys-shopline-via-woocommerce' ); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render styles.
	 */
	private function render_styles() {
		?>
		<style>
		/* ===== YS Shopline Settings Styles ===== */
		.wrap {
			max-width: 1200px;
			margin: 10px 20px 0 0;
		}
		.wrap .notice,
		.wrap .error,
		.wrap .updated {
			margin: 5px 0 15px 0;
		}
		.ys-settings-wrap {
			max-width: 1200px;
			margin: 20px 20px 20px 0;
		}
		.ys-settings-header {
			background: linear-gradient(135deg, #cc99c2 0%, #b085a8 100%);
			color: #fff;
			padding: 30px;
			border-radius: 12px;
			margin-bottom: 20px;
			box-shadow: 0 4px 15px rgba(204, 153, 194, 0.3);
		}
		.ys-settings-header h2 {
			color: #fff;
			margin: 0 0 8px 0;
			font-size: 28px;
			display: flex;
			align-items: center;
			gap: 10px;
		}
		.ys-settings-header h2 .dashicons {
			font-size: 32px;
			width: 32px;
			height: 32px;
		}
		.ys-settings-desc {
			margin: 0;
			opacity: 0.9;
			font-size: 15px;
		}
		.ys-settings-tabs {
			border-bottom: 2px solid #e0d0dc;
			margin-bottom: 0;
			padding-bottom: 0;
		}
		.ys-settings-tabs .nav-tab {
			display: inline-flex;
			align-items: center;
			gap: 6px;
			padding: 12px 20px;
			border: none;
			background: #f8f0f6;
			margin-right: 4px;
			border-radius: 8px 8px 0 0;
			color: #9a7a92;
			font-weight: 500;
			transition: all 0.2s;
		}
		.ys-settings-tabs .nav-tab:hover {
			background: #f0e5ed;
			color: #7a5a72;
		}
		.ys-settings-tabs .nav-tab-active {
			background: #fff;
			color: #cc99c2;
			box-shadow: 0 -2px 10px rgba(0,0,0,0.05);
		}
		.ys-settings-form {
			background: #fff;
			padding: 25px;
			border-radius: 0 0 12px 12px;
			box-shadow: 0 2px 10px rgba(0,0,0,0.05);
		}
		.ys-section-card {
			background: #faf8fa;
			border: 1px solid #e0d0dc;
			border-radius: 10px;
			padding: 20px;
			margin-bottom: 20px;
		}
		.ys-section-title {
			display: flex;
			align-items: center;
			gap: 8px;
			margin: 0 0 15px 0;
			padding-bottom: 12px;
			border-bottom: 1px solid #e0d0dc;
			color: #7a5a72;
			font-size: 16px;
		}
		.ys-section-title .dashicons {
			color: #cc99c2;
		}
		.ys-section-desc {
			color: #666;
			margin: 0 0 15px 0;
			padding: 12px 15px;
			background: #fff;
			border-left: 3px solid #cc99c2;
			border-radius: 0 6px 6px 0;
		}
		.ys-settings-form .form-table {
			margin: 0;
		}
		.ys-settings-form .form-table th {
			padding: 12px 10px 12px 0;
			width: 180px;
			color: #7a5a72;
			font-weight: 500;
		}
		.ys-settings-form .form-table td {
			padding: 12px 0;
		}
		.ys-settings-form .form-table input[type="text"],
		.ys-settings-form .form-table input[type="password"],
		.ys-settings-form .form-table select {
			width: 100%;
			max-width: 400px;
		}
		.ys-settings-form .form-table .small-text {
			max-width: 100px;
		}

		/* Toggle Switch */
		.ys-toggle-switch {
			position: relative;
			display: inline-block;
			width: 50px;
			height: 26px;
			vertical-align: middle;
		}
		.ys-toggle-switch input {
			opacity: 0;
			width: 0;
			height: 0;
		}
		.ys-toggle-slider {
			position: absolute;
			cursor: pointer;
			top: 0; left: 0; right: 0; bottom: 0;
			background-color: #d0c0cc;
			transition: 0.3s;
			border-radius: 26px;
		}
		.ys-toggle-slider:before {
			position: absolute;
			content: "";
			height: 20px;
			width: 20px;
			left: 3px;
			bottom: 3px;
			background-color: white;
			transition: 0.3s;
			border-radius: 50%;
			box-shadow: 0 2px 4px rgba(0,0,0,0.2);
		}
		.ys-toggle-switch input:checked + .ys-toggle-slider {
			background-color: #cc99c2;
		}
		.ys-toggle-switch input:checked + .ys-toggle-slider:before {
			transform: translateX(24px);
		}
		.ys-toggle-desc {
			margin-left: 12px;
			color: #666;
			vertical-align: middle;
		}

		/* Gateway Table */
		.ys-gateway-table th {
			display: flex;
			align-items: center;
			gap: 8px;
		}
		.ys-gateway-icon {
			color: #cc99c2;
		}

		/* Webhook URL */
		.ys-webhook-url {
			display: flex;
			align-items: center;
			gap: 10px;
			background: #f5f5f5;
			padding: 12px 15px;
			border-radius: 6px;
		}
		.ys-webhook-url code {
			flex: 1;
			background: transparent;
			padding: 0;
		}
		.ys-copy-btn {
			display: inline-flex;
			align-items: center;
			gap: 4px;
		}

		/* Notice Items */
		.ys-notice-item {
			display: flex;
			align-items: center;
			gap: 8px;
			padding: 12px 15px;
			border-radius: 6px;
			margin-bottom: 10px;
		}
		.ys-notice-success {
			background: #e8f5e9;
			color: #2e7d32;
		}
		.ys-notice-error {
			background: #ffebee;
			color: #c62828;
		}
		.ys-notice-info {
			background: #e3f2fd;
			color: #1565c0;
		}

		/* Required */
		.required {
			color: #c62828;
		}

		/* Submit */
		.ys-submit-wrap {
			margin-top: 20px;
			padding-top: 20px;
			border-top: 1px solid #e0d0dc;
		}
		.ys-submit-wrap .button-primary {
			background: #cc99c2;
			border-color: #b085a8;
			padding: 8px 30px;
			height: auto;
			font-size: 15px;
		}
		.ys-submit-wrap .button-primary:hover {
			background: #b085a8;
			border-color: #9a7098;
		}

		/* Docs */
		.ys-docs-card {
			background: #faf8fa;
			border: 1px solid #e0d0dc;
			border-radius: 10px;
			padding: 30px;
		}
		.ys-docs-card h2 {
			display: flex;
			align-items: center;
			gap: 10px;
			color: #7a5a72;
			margin-top: 0;
		}
		.ys-docs-section {
			margin-top: 25px;
			padding-top: 20px;
			border-top: 1px solid #e0d0dc;
		}
		.ys-docs-section h3 {
			display: flex;
			align-items: center;
			gap: 8px;
			color: #9a7a92;
		}
		.ys-docs-section ul,
		.ys-docs-section ol {
			margin-left: 25px;
		}
		.ys-docs-section li {
			margin-bottom: 8px;
			color: #555;
		}
		</style>
		<?php
	}

	/**
	 * Render scripts.
	 */
	private function render_scripts() {
		?>
		<script type="text/javascript">
		jQuery(document).ready(function($) {
			// Tab switching
			$('.ys-tab-link').on('click', function(e) {
				e.preventDefault();
				var tab = $(this).data('tab');

				$('.ys-tab-link').removeClass('nav-tab-active');
				$(this).addClass('nav-tab-active');

				$('.ys-tab-content').hide();
				$('#ys-tab-' + tab).show();

				if (tab === 'docs') {
					$('#ys-submit-button').hide();
				} else {
					$('#ys-submit-button').show();
				}

				if (history.pushState) {
					var url = new URL(window.location);
					url.searchParams.set('tab', tab);
					history.pushState({}, '', url);
				}
			});

			// Copy button
			$('.ys-copy-btn').on('click', function() {
				var text = $(this).data('copy');
				navigator.clipboard.writeText(text).then(function() {
					alert('<?php echo esc_js( __( '已複製到剪貼簿', 'ys-shopline-via-woocommerce' ) ); ?>');
				});
			});
		});
		</script>
		<?php
	}
}

// Initialize
add_action( 'plugins_loaded', function() {
	if ( is_admin() ) {
		YS_Shopline_Admin_Settings::get_instance();
	}
}, 20 );
