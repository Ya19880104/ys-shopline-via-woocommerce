<?php
/**
 * Marketplace page template.
 *
 * @package YangSheep\PluginHubClient
 *
 * @var string $site_key   Registered site key.
 * @var string $auto_check Auto-check setting.
 * @var string $cb_state   Circuit breaker state.
 * @var string $cb_label   Circuit breaker label.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="ys-marketplace-wrap">

	<div class="ys-page-hero">
		<div class="ys-page-hero-content">
			<div class="ys-hero-title">
				<span class="dashicons dashicons-store"></span>
				<?php echo esc_html__( 'YS 外掛市集', 'ys-plugin-hub-client' ); ?>
			</div>
			<div class="ys-hero-subtitle">
				<?php
				printf(
					/* translators: %s: YANGSHEEP CLOUD link. */
					esc_html__( '由 %s 開發與維護', 'ys-plugin-hub-client' ),
					'<a href="https://yangsheep.com.tw" target="_blank" rel="noopener noreferrer" style="color:rgba(255,255,255,0.95);text-decoration:none;">YANGSHEEP CLOUD</a>'
				);
				?>
			</div>
		</div>
		<div class="ys-page-hero-actions">
			<span id="ys-hub-status" class="ys-hub-status ys-hub-status-checking" title="<?php echo esc_attr__( '檢查連線中...', 'ys-plugin-hub-client' ); ?>">
				<span class="ys-hub-status-dot"></span>
				<span class="ys-hub-status-text"><?php echo esc_html__( '檢查中...', 'ys-plugin-hub-client' ); ?></span>
			</span>
			<button type="button" id="ys-refresh-btn" class="ys-btn ys-btn-hero">
				<span class="dashicons dashicons-update"></span>
				<?php echo esc_html__( '檢查更新', 'ys-plugin-hub-client' ); ?>
			</button>
		</div>
	</div>

	<div class="wrap"><h2 style="display:none;"></h2></div>

	<div id="ys-announcements" class="ys-announcements-wrap" style="display:none;"></div>

	<div class="ys-marketplace-toolbar">
		<div class="ys-marketplace-filters">
			<div class="ys-platform-tabs" id="ys-platform-tabs">
				<button type="button" class="ys-filter-tab ys-platform-tab active" data-platform="all">
					<?php echo esc_html__( '全部', 'ys-plugin-hub-client' ); ?>
				</button>
			</div>
			<div class="ys-filter-tabs" id="ys-filter-tabs">
				<button type="button" class="ys-filter-tab active" data-category="all">
					<?php echo esc_html__( '全部', 'ys-plugin-hub-client' ); ?>
				</button>
			</div>
		</div>
		<div class="ys-search-box">
			<span class="dashicons dashicons-search"></span>
			<input type="text"
				id="ys-search-input"
				placeholder="<?php echo esc_attr__( '搜尋外掛...', 'ys-plugin-hub-client' ); ?>"
			/>
		</div>
	</div>

	<div id="ys-plugin-grid" class="ys-plugin-grid"></div>

	<input type="hidden" id="ys-hub-url" value="<?php echo esc_attr( YS_HUB_CLIENT_HUB_URL ); ?>" />
	<input type="hidden" id="ys-site-key" value="<?php echo esc_attr( $site_key ); ?>" />
	<input type="hidden" id="ys-auto-check" value="yes" />

</div>
