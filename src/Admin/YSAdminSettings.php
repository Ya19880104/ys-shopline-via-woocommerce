<?php
/**
 * Admin Settings Page for YS Shopline Payment.
 *
 * 獨立設定頁面，風格與結帳強化外掛一致。
 * 使用 TAB 頁籤，一次載入所有設定，確保儲存時不會遺漏。
 *
 * @package YangSheep\ShoplinePayment\Admin
 */

namespace YangSheep\ShoplinePayment\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * YSAdminSettings Class.
 */
class YSAdminSettings {

	/**
	 * Instance.
	 *
	 * @var YSAdminSettings
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
	 * @return YSAdminSettings
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
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ), 21 );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_filter( 'ys_toolbox_plugins', array( $this, 'register_toolbox_card' ) );

		// Apple Pay 驗證檔案 AJAX
		add_action( 'wp_ajax_ys_shopline_apple_verify', array( $this, 'ajax_apple_verify' ) );

		// 綁卡紀錄下載
		add_action( 'admin_post_ys_shopline_bindcard_log_download', array( $this, 'handle_bindcard_log_download' ) );
	}

	/**
	 * Download bindcard log file.
	 */
	public function handle_bindcard_log_download() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '權限不足。', 'ys-shopline-via-woocommerce' ), 403 );
		}

		check_admin_referer( 'ys_bindcard_log_download' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$date = isset( $_GET['log_date'] ) ? sanitize_text_field( wp_unslash( $_GET['log_date'] ) ) : '';
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			wp_die( esc_html__( '無效的日期。', 'ys-shopline-via-woocommerce' ), 400 );
		}

		$log_dir = \YangSheep\ShoplinePayment\Utils\YSBindCardLogger::get_log_dir();
		if ( ! $log_dir ) {
			wp_die( esc_html__( '日誌目錄無法存取。', 'ys-shopline-via-woocommerce' ), 500 );
		}

		$file_path = $log_dir . '/bindcard-' . $date . '.log';
		if ( ! file_exists( $file_path ) ) {
			wp_die( esc_html__( '檔案不存在。', 'ys-shopline-via-woocommerce' ), 404 );
		}

		nocache_headers();
		header( 'Content-Type: text/plain; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="bindcard-' . $date . '.log"' );
		header( 'Content-Length: ' . filesize( $file_path ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		readfile( $file_path );
		exit;
	}

	/**
	 * Add admin menu under 電商工具箱.
	 */
	public function add_admin_menu() {
		global $menu;

		// 檢查「電商工具箱」頂層選單是否已存在
		$toolbox_exists = false;
		if ( is_array( $menu ) ) {
			foreach ( $menu as $item ) {
				if ( isset( $item[2] ) && 'ys-toolbox' === $item[2] ) {
					$toolbox_exists = true;
					break;
				}
			}
		}

		// 只建立一次頂層選單
		if ( ! $toolbox_exists ) {
			add_menu_page(
				__( '電商工具箱', 'ys-shopline-via-woocommerce' ),
				__( '電商工具箱', 'ys-shopline-via-woocommerce' ),
				'manage_options',
				'ys-toolbox',
				array( __CLASS__, 'render_toolbox_welcome' ),
				'dashicons-store',
				56
			);

			// 將第一個子選單顯示為「總覽」而非重複的「電商工具箱」
			add_submenu_page(
				'ys-toolbox',
				__( '電商工具箱', 'ys-shopline-via-woocommerce' ),
				__( '總覽', 'ys-shopline-via-woocommerce' ),
				'manage_options',
				'ys-toolbox',
				array( __CLASS__, 'render_toolbox_welcome' )
			);
		}

		// 註冊子選單
		add_submenu_page(
			'ys-toolbox',
			__( 'SHOPLINE Payment 設定', 'ys-shopline-via-woocommerce' ),
			__( 'SHOPLINE 金流', 'ys-shopline-via-woocommerce' ),
			'manage_options',
			'ys-shopline-payment',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * 註冊本外掛的工具箱卡片資訊
	 *
	 * @param array $plugins 已註冊的外掛列表。
	 * @return array
	 */
	public function register_toolbox_card( $plugins ) {
		$plugins[] = array(
			'name'    => 'SHOPLINE 金流',
			'version' => YS_SHOPLINE_VERSION,
			'icon'    => 'dashicons-money-alt',
			'desc'    => '整合 SHOPLINE Payment 金流服務，支援信用卡、ATM、LINE Pay、Apple Pay 等多種付款方式。',
			'url'     => admin_url( 'admin.php?page=ys-shopline-payment' ),
		);
		return $plugins;
	}

	/**
	 * 渲染電商工具箱歡迎頁面
	 *
	 * 靜態方法，任何外掛都可呼叫，但只需註冊一次。
	 * 透過 ys_toolbox_plugins filter 自動偵測所有已註冊的 YS 外掛。
	 */
	public static function render_toolbox_welcome() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		/**
		 * 收集所有已啟用的 YS 外掛卡片資訊。
		 *
		 * 每個外掛透過此 filter 註冊自己的卡片，歡迎頁面自動顯示。
		 * 陣列元素格式：
		 *   'name'    => (string) 外掛顯示名稱
		 *   'version' => (string) 版本號
		 *   'icon'    => (string) Dashicon CSS class
		 *   'desc'    => (string) 簡短描述
		 *   'url'     => (string) 設定頁面完整 URL
		 *
		 * @param array $plugins 已註冊的外掛列表。
		 */
		$plugins = apply_filters( 'ys_toolbox_plugins', array() );

		?>
		<div class="wrap">
			<h1 style="display:none;"><?php esc_html_e( '電商工具箱', 'ys-toolbox' ); ?></h1>
		</div>

		<div class="ys-toolbox-welcome">

			<!-- Header -->
			<div class="ys-toolbox-header">
				<div class="ys-toolbox-header-content">
					<div class="ys-toolbox-logo">
						<span class="dashicons dashicons-store"></span>
					</div>
					<h2>電商工具箱</h2>
					<p class="ys-toolbox-subtitle">WooCommerce 電商擴充套件，由 YANGSHEEP DESIGN 開發維護</p>
				</div>
			</div>

			<!-- Plugin Cards -->
			<?php if ( ! empty( $plugins ) ) : ?>
			<div class="ys-toolbox-cards">
				<?php
				foreach ( $plugins as $plugin ) :
					$plugin = wp_parse_args( $plugin, array(
						'name'    => __( '未知外掛', 'ys-toolbox' ),
						'version' => '0.0.0',
						'icon'    => 'dashicons-admin-plugins',
						'desc'    => '',
						'url'     => '#',
					) );
				?>
				<a href="<?php echo esc_url( $plugin['url'] ); ?>" class="ys-toolbox-card">
					<div class="ys-toolbox-card-icon">
						<span class="dashicons <?php echo esc_attr( $plugin['icon'] ); ?>"></span>
					</div>
					<div class="ys-toolbox-card-body">
						<h3><?php echo esc_html( $plugin['name'] ); ?></h3>
						<span class="ys-toolbox-card-version">v<?php echo esc_html( $plugin['version'] ); ?></span>
						<p><?php echo esc_html( $plugin['desc'] ); ?></p>
					</div>
					<span class="ys-toolbox-card-arrow dashicons dashicons-arrow-right-alt2"></span>
				</a>
				<?php endforeach; ?>
			</div>
			<?php else : ?>
			<div class="ys-toolbox-empty">
				<span class="dashicons dashicons-info-outline"></span>
				<p>尚未偵測到已啟用的 YS 外掛。</p>
			</div>
			<?php endif; ?>

			<!-- Footer -->
			<div class="ys-toolbox-footer">
				<div class="ys-toolbox-footer-info">
					<span class="dashicons dashicons-heart"></span>
					<span>由 <strong>YANGSHEEP DESIGN</strong> 用心開發</span>
					<span class="ys-toolbox-sep">|</span>
					<a href="https://yangsheep.com.tw" target="_blank" rel="noopener">yangsheep.com.tw</a>
				</div>
			</div>
		</div>

		<style>
			/* ── Welcome Page ─────────────────────────────── */
			.ys-toolbox-welcome {
				max-width: 860px;
				margin: 20px 0;
				font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
			}

			/* Header */
			.ys-toolbox-header {
				background: linear-gradient(135deg, #3a4f63 0%, #2c3e50 100%);
				border-radius: 12px;
				padding: 40px;
				margin-bottom: 24px;
				color: #fff;
			}
			.ys-toolbox-header-content {
				text-align: center;
			}
			.ys-toolbox-logo {
				display: inline-flex;
				align-items: center;
				justify-content: center;
				width: 64px;
				height: 64px;
				background: rgba(255,255,255,.15);
				border-radius: 16px;
				margin-bottom: 16px;
			}
			.ys-toolbox-logo .dashicons {
				font-size: 32px;
				width: 32px;
				height: 32px;
				color: #fff;
			}
			.ys-toolbox-header h2 {
				font-size: 24px;
				font-weight: 600;
				margin: 0 0 8px;
				color: #fff;
			}
			.ys-toolbox-subtitle {
				font-size: 14px;
				opacity: .8;
				margin: 0;
			}

			/* Cards */
			.ys-toolbox-cards {
				display: flex;
				flex-direction: column;
				gap: 12px;
				margin-bottom: 24px;
			}
			.ys-toolbox-card {
				display: flex;
				align-items: center;
				gap: 20px;
				background: #fff;
				border: 1px solid #e0e0e0;
				border-radius: 10px;
				padding: 24px;
				text-decoration: none;
				color: inherit;
				transition: all .2s ease;
			}
			.ys-toolbox-card:hover {
				border-color: #8fa8b8;
				box-shadow: 0 2px 12px rgba(0,0,0,.08);
				transform: translateY(-1px);
			}
			.ys-toolbox-card:focus {
				outline: 2px solid #8fa8b8;
				outline-offset: 2px;
			}
			.ys-toolbox-card-icon {
				flex-shrink: 0;
				display: flex;
				align-items: center;
				justify-content: center;
				width: 52px;
				height: 52px;
				background: #f0f4f7;
				border-radius: 12px;
			}
			.ys-toolbox-card-icon .dashicons {
				font-size: 24px;
				width: 24px;
				height: 24px;
				color: #3a4f63;
			}
			.ys-toolbox-card-body {
				flex: 1;
				min-width: 0;
			}
			.ys-toolbox-card-body h3 {
				font-size: 15px;
				font-weight: 600;
				margin: 0 0 4px;
				color: #1d2327;
				display: inline;
			}
			.ys-toolbox-card-version {
				display: inline-block;
				font-size: 11px;
				color: #8fa8b8;
				background: #f0f4f7;
				padding: 1px 8px;
				border-radius: 10px;
				margin-left: 8px;
				vertical-align: middle;
			}
			.ys-toolbox-card-body p {
				font-size: 13px;
				color: #646970;
				margin: 6px 0 0;
				line-height: 1.5;
			}
			.ys-toolbox-card-arrow {
				flex-shrink: 0;
				color: #c3c4c7;
				transition: color .2s ease;
			}
			.ys-toolbox-card:hover .ys-toolbox-card-arrow {
				color: #8fa8b8;
			}

			/* Empty State */
			.ys-toolbox-empty {
				text-align: center;
				padding: 48px 24px;
				background: #fff;
				border: 1px solid #e0e0e0;
				border-radius: 10px;
				margin-bottom: 24px;
			}
			.ys-toolbox-empty .dashicons {
				font-size: 40px;
				width: 40px;
				height: 40px;
				color: #c3c4c7;
				margin-bottom: 12px;
			}
			.ys-toolbox-empty p {
				color: #646970;
				font-size: 14px;
				margin: 0;
			}

			/* Footer */
			.ys-toolbox-footer {
				text-align: center;
				padding: 16px 0;
			}
			.ys-toolbox-footer-info {
				display: inline-flex;
				align-items: center;
				gap: 6px;
				font-size: 13px;
				color: #8c8f94;
			}
			.ys-toolbox-footer-info .dashicons {
				font-size: 14px;
				width: 14px;
				height: 14px;
				color: #cc99c2;
			}
			.ys-toolbox-footer-info a {
				color: #8fa8b8;
				text-decoration: none;
			}
			.ys-toolbox-footer-info a:hover {
				color: #3a4f63;
			}
			.ys-toolbox-sep {
				color: #ddd;
				margin: 0 4px;
			}
		</style>
		<?php
	}

	/**
	 * Enqueue scripts.
	 *
	 * @param string $hook Current admin page.
	 */
	public function enqueue_scripts( $hook ) {
		if ( false === strpos( $hook, 'ys-toolbox' )
			&& false === strpos( $hook, 'ys-shopline-payment' ) ) {
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
			'ys_shopline_atm_expire_time',
			'ys_shopline_bnpl_expire_time',
			'ys_shopline_credit_enabled',
			'ys_shopline_credit_installment_enabled',
			'ys_shopline_credit_subscription_enabled',
			'ys_shopline_atm_enabled',
			'ys_shopline_jkopay_enabled',
			'ys_shopline_applepay_enabled',
			'ys_shopline_linepay_enabled',
			'ys_shopline_bnpl_enabled',

			// 進階設定
			'ys_shopline_debug',
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
				<a href="#" class="nav-tab ys-tab-link <?php echo 'bindcard_log' === $active_tab ? 'nav-tab-active' : ''; ?>" data-tab="bindcard_log">
					<span class="dashicons dashicons-id-alt"></span> <?php _e( '綁卡紀錄', 'ys-shopline-via-woocommerce' ); ?>
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

				<!-- Tab: 綁卡紀錄 -->
				<div class="ys-tab-content" id="ys-tab-bindcard_log" style="<?php echo 'bindcard_log' !== $active_tab ? 'display:none;' : ''; ?>">
					<?php $this->render_bindcard_log_tab(); ?>
				</div>

				<!-- Tab: 說明文件 -->
				<div class="ys-tab-content" id="ys-tab-docs" style="<?php echo 'docs' !== $active_tab ? 'display:none;' : ''; ?>">
					<?php $this->render_docs_tab(); ?>
				</div>

				<div class="ys-submit-wrap" id="ys-submit-button" style="<?php echo ( 'docs' === $active_tab || 'bindcard_log' === $active_tab ) ? 'display:none;' : ''; ?>">
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
			'ys_shopline_credit_installment_enabled',
			'ys_shopline_credit_subscription_enabled',
			'ys_shopline_atm_enabled',
			'ys_shopline_jkopay_enabled',
			'ys_shopline_applepay_enabled',
			'ys_shopline_linepay_enabled',
			'ys_shopline_bnpl_enabled',
			'ys_shopline_debug',
			'ys_shopline_bindcard_log_enabled',
		);

		foreach ( $checkboxes as $checkbox ) {
			$value = isset( $_POST[ $checkbox ] ) ? 'yes' : 'no';
			update_option( $checkbox, $value );
		}

		// 文字欄位
		$text_fields = array(
			'ys_shopline_atm_expire_time',
			'ys_shopline_bnpl_expire_time',
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

		<!-- Apple Pay 網域驗證 -->
		<div class="ys-section-card">
			<h3 class="ys-section-title"><span class="dashicons dashicons-shield"></span> <?php _e( 'Apple Pay 網域驗證', 'ys-shopline-via-woocommerce' ); ?></h3>
			<p class="ys-section-desc">
				<?php _e( 'Apple Pay 需要在網站根目錄放置驗證檔案，SHOPLINE 才能完成網域註冊。', 'ys-shopline-via-woocommerce' ); ?>
				<br>
				<?php _e( '正式環境與沙盒環境的驗證檔案內容不同，請根據目前使用的環境產生對應檔案。', 'ys-shopline-via-woocommerce' ); ?>
				<br><br>
				<span class="dashicons dashicons-info" style="color:#0073aa;"></span>
				<?php
				printf(
					/* translators: %s: Documentation URL */
					__( '詳細說明請參考 <a href="%s" target="_blank">SHOPLINE Apple Pay 整合文件</a>', 'ys-shopline-via-woocommerce' ),
					'https://docs.shoplinepayments.com/sdk/example/ApplePay/'
				);
				?>
			</p>

			<div id="ys-apple-verify-status">
			<?php $this->render_apple_verify_status(); ?>
		</div>

			<div class="ys-apple-verify-actions" style="display:flex;gap:10px;margin-top:15px;">
				<button type="button" class="button button-primary ys-apple-verify-btn" data-env="production">
					<span class="dashicons dashicons-admin-site-alt3" style="vertical-align:middle;margin-top:-2px;"></span>
					<?php _e( '產生正式環境驗證檔案', 'ys-shopline-via-woocommerce' ); ?>
				</button>
				<button type="button" class="button button-secondary ys-apple-verify-btn" data-env="sandbox">
					<span class="dashicons dashicons-admin-network" style="vertical-align:middle;margin-top:-2px;"></span>
					<?php _e( '產生沙盒環境驗證檔案', 'ys-shopline-via-woocommerce' ); ?>
				</button>
			</div>

			<div id="ys-apple-verify-result" style="margin-top:10px;"></div>
		</div>
		<?php
	}

	/**
	 * 渲染 Apple Pay 驗證檔案狀態。
	 */
	private function render_apple_verify_status() {
		$file_path = ABSPATH . '.well-known/apple-developer-merchantid-domain-association';
		$env       = get_option( 'ys_shopline_apple_verify_env', '' );

		if ( ! file_exists( $file_path ) || empty( $env ) ) {
			echo '<div class="ys-notice-item ys-notice-info">';
			echo '<span class="dashicons dashicons-info"></span> ';
			esc_html_e( '尚未產生 Apple Pay 驗證檔案。', 'ys-shopline-via-woocommerce' );
			echo '</div>';
			return;
		}

		$env_label = 'production' === $env
			? __( '正式環境', 'ys-shopline-via-woocommerce' )
			: __( '沙盒環境', 'ys-shopline-via-woocommerce' );

		$verify_url = home_url( '.well-known/apple-developer-merchantid-domain-association' );

		echo '<div class="ys-notice-item ys-notice-success">';
		echo '<span class="dashicons dashicons-yes-alt"></span> ';
		printf(
			/* translators: %s: Environment label */
			esc_html__( '已產生 Apple Pay %s驗證檔案', 'ys-shopline-via-woocommerce' ),
			esc_html( $env_label )
		);
		echo '</div>';
		echo '<div style="margin-top:8px;">';
		echo '<span class="dashicons dashicons-external" style="color:#0073aa;"></span> ';
		printf(
			__( '驗證網址：<a href="%1$s" target="_blank">%1$s</a>', 'ys-shopline-via-woocommerce' ),
			esc_url( $verify_url )
		);
		echo '</div>';
	}

	/**
	 * AJAX：產生 Apple Pay 網域驗證檔案。
	 */
	public function ajax_apple_verify() {
		check_ajax_referer( 'ys_shopline_apple_verify', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( '權限不足。', 'ys-shopline-via-woocommerce' ) ) );
		}

		$env = isset( $_POST['env'] ) ? sanitize_key( $_POST['env'] ) : '';

		if ( ! in_array( $env, array( 'production', 'sandbox' ), true ) ) {
			wp_send_json_error( array( 'message' => __( '無效的環境參數。', 'ys-shopline-via-woocommerce' ) ) );
		}

		// 來源 URL
		$urls = array(
			'production' => 'https://cdn.shoplinepayments.com/docs/product/apple-developer-merchantid-domain-association.txt',
			'sandbox'    => 'https://cdn.shoplinepayments.com/docs/sandbox/apple-developer-merchantid-domain-association.txt',
		);

		// 下載驗證檔案內容
		$response = wp_remote_get( $urls[ $env ], array( 'timeout' => 30 ) );

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( array(
				'message' => sprintf(
					/* translators: %s: Error message */
					__( '下載驗證檔案失敗：%s', 'ys-shopline-via-woocommerce' ),
					$response->get_error_message()
				),
			) );
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status_code ) {
			wp_send_json_error( array(
				'message' => sprintf(
					/* translators: %d: HTTP status code */
					__( '下載驗證檔案失敗，HTTP 狀態碼：%d', 'ys-shopline-via-woocommerce' ),
					$status_code
				),
			) );
		}

		$content = wp_remote_retrieve_body( $response );

		if ( empty( $content ) ) {
			wp_send_json_error( array( 'message' => __( '下載的驗證檔案內容為空。', 'ys-shopline-via-woocommerce' ) ) );
		}

		// 建立 .well-known 目錄
		$dir = ABSPATH . '.well-known';

		if ( ! file_exists( $dir ) ) {
			if ( ! wp_mkdir_p( $dir ) ) {
				wp_send_json_error( array(
					'message' => sprintf(
						/* translators: %s: Directory path */
						__( '無法建立目錄：%s，請確認伺服器檔案寫入權限。', 'ys-shopline-via-woocommerce' ),
						$dir
					),
				) );
			}
		}

		// 寫入檔案（覆蓋既有檔案）
		$file_path = $dir . '/apple-developer-merchantid-domain-association';
		$result    = file_put_contents( $file_path, $content );

		if ( false === $result ) {
			wp_send_json_error( array(
				'message' => sprintf(
					/* translators: %s: File path */
					__( '無法寫入檔案：%s，請確認伺服器檔案寫入權限。', 'ys-shopline-via-woocommerce' ),
					$file_path
				),
			) );
		}

		// 記錄目前環境
		update_option( 'ys_shopline_apple_verify_env', $env );

		$env_label  = 'production' === $env
			? __( '正式環境', 'ys-shopline-via-woocommerce' )
			: __( '沙盒環境', 'ys-shopline-via-woocommerce' );
		$verify_url = home_url( '.well-known/apple-developer-merchantid-domain-association' );

		wp_send_json_success( array(
			'message'    => sprintf(
				/* translators: %s: Environment label */
				__( '已成功產生 Apple Pay %s驗證檔案', 'ys-shopline-via-woocommerce' ),
				$env_label
			),
			'env'        => $env,
			'env_label'  => $env_label,
			'verify_url' => $verify_url,
		) );
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
						<span class="ys-toggle-desc"><?php _e( '信用卡一次付清', 'ys-shopline-via-woocommerce' ); ?></span>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<span class="ys-gateway-icon dashicons dashicons-calendar-alt"></span>
						<?php _e( '信用卡分期', 'ys-shopline-via-woocommerce' ); ?>
					</th>
					<td>
						<?php $this->render_toggle( 'ys_shopline_credit_installment_enabled', 'no' ); ?>
						<span class="ys-toggle-desc"><?php _e( '信用卡分期付款（獨立付款方式）', 'ys-shopline-via-woocommerce' ); ?></span>
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
						<div class="ys-sub-setting" style="margin-top:10px;margin-left:62px;">
							<label><?php _e( '繳費期限：', 'ys-shopline-via-woocommerce' ); ?></label>
							<select name="ys_shopline_atm_expire_time" style="width:auto;">
								<?php
								$atm_options = array(
									'360'  => __( '6 小時', 'ys-shopline-via-woocommerce' ),
									'1440' => __( '24 小時（1 天）', 'ys-shopline-via-woocommerce' ),
									'2880' => __( '48 小時（2 天）', 'ys-shopline-via-woocommerce' ),
									'4320' => __( '72 小時（3 天）', 'ys-shopline-via-woocommerce' ),
								);
								$atm_current = get_option( 'ys_shopline_atm_expire_time', '4320' );
								foreach ( $atm_options as $val => $label ) {
									printf(
										'<option value="%s" %s>%s</option>',
										esc_attr( $val ),
										selected( $atm_current, $val, false ),
										esc_html( $label )
									);
								}
								?>
							</select>
						</div>
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
						<div class="ys-sub-setting" style="margin-top:10px;margin-left:62px;">
							<label><?php _e( '付款期限：', 'ys-shopline-via-woocommerce' ); ?></label>
							<select name="ys_shopline_bnpl_expire_time" style="width:auto;">
								<?php
								$bnpl_options = array(
									'2880'  => __( '2 天', 'ys-shopline-via-woocommerce' ),
									'4320'  => __( '3 天', 'ys-shopline-via-woocommerce' ),
									'7200'  => __( '5 天', 'ys-shopline-via-woocommerce' ),
									'10080' => __( '7 天', 'ys-shopline-via-woocommerce' ),
								);
								$bnpl_current = get_option( 'ys_shopline_bnpl_expire_time', '4320' );
								foreach ( $bnpl_options as $val => $label ) {
									printf(
										'<option value="%s" %s>%s</option>',
										esc_attr( $val ),
										selected( $bnpl_current, $val, false ),
										esc_html( $label )
									);
								}
								?>
							</select>
						</div>
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
						<?php $this->render_toggle( 'ys_shopline_debug', 'no' ); ?>
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
					<th scope="row"><?php _e( '綁卡紀錄', 'ys-shopline-via-woocommerce' ); ?></th>
					<td>
						<?php $this->render_toggle( 'ys_shopline_bindcard_log_enabled', 'no' ); ?>
						<span class="ys-toggle-desc">
							<?php _e( '啟用綁卡專用日誌（獨立檔案，每日輪轉，保留 30 天）', 'ys-shopline-via-woocommerce' ); ?>
						</span>
						<p class="description">
							<?php _e( '啟用後可在「綁卡紀錄」tab 檢視記錄內容，包含用戶、卡號後 4 碼、API 往返細節。敏感欄位（CVV、完整卡號、金鑰）自動遮罩。', 'ys-shopline-via-woocommerce' ); ?>
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
	 * Render bindcard log tab.
	 */
	private function render_bindcard_log_tab() {
		$enabled = \YangSheep\ShoplinePayment\Utils\YSBindCardLogger::is_enabled();
		$files   = \YangSheep\ShoplinePayment\Utils\YSBindCardLogger::list_log_files();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$selected_date = isset( $_GET['log_date'] ) ? sanitize_text_field( wp_unslash( $_GET['log_date'] ) ) : '';
		if ( '' === $selected_date && ! empty( $files ) ) {
			$selected_date = array_key_first( $files );
		}

		$entries = $selected_date ? \YangSheep\ShoplinePayment\Utils\YSBindCardLogger::get_log_entries( $selected_date ) : array();
		?>
		<div class="ys-section-card">
			<h3 class="ys-section-title"><span class="dashicons dashicons-id-alt"></span> <?php _e( '綁卡紀錄', 'ys-shopline-via-woocommerce' ); ?></h3>

			<?php if ( ! $enabled ) : ?>
				<div class="notice notice-warning inline">
					<p>
						<?php _e( '綁卡紀錄目前<strong>未啟用</strong>。請至「進階設定」啟用後才會產生新的記錄。', 'ys-shopline-via-woocommerce' ); ?>
					</p>
				</div>
			<?php endif; ?>

			<?php if ( empty( $files ) ) : ?>
				<p><?php _e( '尚無綁卡紀錄。', 'ys-shopline-via-woocommerce' ); ?></p>
			<?php else : ?>
				<p>
					<label for="ys_bindcard_log_date"><?php _e( '選擇日期：', 'ys-shopline-via-woocommerce' ); ?></label>
					<select id="ys_bindcard_log_date" onchange="window.location.href=this.value">
						<?php foreach ( $files as $date => $path ) : ?>
							<?php
							$size = size_format( filesize( $path ) );
							$url  = add_query_arg(
								array(
									'page'     => 'ys-shopline-payment',
									'tab'      => 'bindcard_log',
									'log_date' => $date,
								),
								admin_url( 'admin.php' )
							);
							?>
							<option value="<?php echo esc_url( $url ); ?>" <?php selected( $selected_date, $date ); ?>>
								<?php echo esc_html( $date . ' (' . $size . ')' ); ?>
							</option>
						<?php endforeach; ?>
					</select>

					<?php
					$download_url = wp_nonce_url(
						add_query_arg(
							array(
								'action'   => 'ys_shopline_bindcard_log_download',
								'log_date' => $selected_date,
							),
							admin_url( 'admin-post.php' )
						),
						'ys_bindcard_log_download'
					);
					?>
					<a href="<?php echo esc_url( $download_url ); ?>" class="button">
						<span class="dashicons dashicons-download" style="vertical-align:middle;"></span>
						<?php _e( '下載此日記錄', 'ys-shopline-via-woocommerce' ); ?>
					</a>
				</p>

				<div class="ys-bindcard-log-viewer" style="max-height:600px;overflow:auto;border:1px solid #ddd;background:#f9f9f9;padding:12px;font-family:monospace;font-size:12px;">
					<?php if ( empty( $entries ) ) : ?>
						<p><?php _e( '此日期無記錄。', 'ys-shopline-via-woocommerce' ); ?></p>
					<?php else : ?>
						<p class="description"><?php echo esc_html( sprintf( __( '共 %d 筆（最新在上）', 'ys-shopline-via-woocommerce' ), count( $entries ) ) ); ?></p>
						<?php foreach ( $entries as $entry ) : ?>
							<div style="margin-bottom:10px;padding:8px;background:#fff;border-left:3px solid <?php echo $this->get_event_color( $entry['event'] ?? '' ); ?>;">
								<strong><?php echo esc_html( $entry['timestamp'] ?? '' ); ?></strong>
								<span style="color:<?php echo $this->get_event_color( $entry['event'] ?? '' ); ?>;font-weight:bold;">
									[<?php echo esc_html( $entry['event'] ?? '' ); ?>]
								</span>
								<?php if ( ! empty( $entry['user_email'] ) ) : ?>
									<span style="color:#666;">user: <?php echo esc_html( $entry['user_email'] ); ?> (#<?php echo esc_html( (string) ( $entry['user_id'] ?? '' ) ); ?>)</span>
								<?php endif; ?>
								<pre style="margin:6px 0 0;white-space:pre-wrap;word-break:break-all;"><?php echo esc_html( wp_json_encode( $entry['context'] ?? array(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) ); ?></pre>
							</div>
						<?php endforeach; ?>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<p class="description" style="margin-top:15px;">
				<?php _e( '記錄自動保留 30 天，超過期限的檔案每日會由 WordPress cron 自動清除。', 'ys-shopline-via-woocommerce' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Get color code for log event type.
	 *
	 * @param string $event
	 * @return string
	 */
	private function get_event_color( string $event ): string {
		$map = array(
			'API_REQUEST'  => '#2271b1',
			'API_RESPONSE' => '#00a32a',
			'WEBHOOK'      => '#7c3aed',
			'TOKEN_SAVED'  => '#00a32a',
			'SDK_INIT'     => '#8c8f94',
			'ERROR'        => '#d63638',
		);
		return $map[ $event ] ?? '#666';
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
					<li><?php _e( '「設為預設」功能用於訂閱自動續扣時的優先卡片；結帳頁的卡片顯示順序由 SHOPLINE SDK 自動管理，無法手動指定', 'ys-shopline-via-woocommerce' ); ?></li>
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

		/* Spinning animation */
		@keyframes rotation {
			from { transform: rotate(0deg); }
			to { transform: rotate(360deg); }
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

			// Apple Pay 驗證檔案
			$('.ys-apple-verify-btn').on('click', function() {
				var $btn = $(this);
				var env = $btn.data('env');
				var $result = $('#ys-apple-verify-result');

				$btn.prop('disabled', true).css('opacity', '0.6');
				$result.html('<span style="color:#666;"><span class="dashicons dashicons-update" style="animation:rotation 1s linear infinite;"></span> <?php echo esc_js( __( '正在下載並建立驗證檔案…', 'ys-shopline-via-woocommerce' ) ); ?></span>');

				$.ajax({
					url: ajaxurl,
					type: 'POST',
					data: {
						action: 'ys_shopline_apple_verify',
						nonce: '<?php echo wp_create_nonce( 'ys_shopline_apple_verify' ); ?>',
						env: env
					},
					success: function(response) {
						$btn.prop('disabled', false).css('opacity', '1');
						if (response.success) {
							// 更新上方狀態區
							$('#ys-apple-verify-status').html(
								'<div class="ys-notice-item ys-notice-success">' +
								'<span class="dashicons dashicons-yes-alt"></span> ' +
								response.data.message +
								'</div>' +
								'<div style="margin-top:8px;">' +
								'<span class="dashicons dashicons-external" style="color:#0073aa;"></span> ' +
								'<?php echo esc_js( __( '驗證網址：', 'ys-shopline-via-woocommerce' ) ); ?>' +
								'<a href="' + response.data.verify_url + '" target="_blank">' + response.data.verify_url + '</a>' +
								'</div>'
							);
							// 清除下方結果區
							$result.empty();
						} else {
							$result.html(
								'<div class="ys-notice-item ys-notice-error" style="margin-top:10px;">' +
								'<span class="dashicons dashicons-dismiss"></span> ' +
								response.data.message +
								'</div>'
							);
						}
					},
					error: function() {
						$btn.prop('disabled', false).css('opacity', '1');
						$result.html(
							'<div class="ys-notice-item ys-notice-error" style="margin-top:10px;">' +
							'<span class="dashicons dashicons-dismiss"></span> ' +
							'<?php echo esc_js( __( 'AJAX 請求失敗，請重新整理頁面後再試。', 'ys-shopline-via-woocommerce' ) ); ?>' +
							'</div>'
						);
					}
				});
			});
		});
		</script>
		<?php
	}
}
