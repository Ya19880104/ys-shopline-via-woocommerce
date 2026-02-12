# Changelog

所有重要的變更都會記錄在此檔案中。

格式基於 [Keep a Changelog](https://keepachangelog.com/zh-TW/1.0.0/)。

---

## [2.0.7] - 2026-02-10

### Changed
- 重構：將所有程式碼統一到 `includes/` 目錄，消除 `src/` 與 `includes/` 雙目錄架構
- 閘道檔案從 `src/Gateways/` 搬回 `includes/gateways/`
- PSR-4 模組從 `src/` 搬入 `includes/`（Api、Blocks、Customer、DTOs、Handlers、Utils）
- 更新 autoloader 路徑對應

### Removed
- 移除死代碼：`class-ys-shopline-gateway.php`、`class-ys-shopline-loader.php`、`YSMyAccountEndpoint.php`

## [2.0.6] - 2026-01-17

### Fixed
- 改善儲存卡頁面使用 AJAX 更新，提升用戶體驗
- 修正兩位數年份導致 WC Token 建立失敗的問題
- 同步儲存卡查詢不帶 filter，避免 API 錯誤
- 修正儲存卡同步和刪除功能
- 移除重複的 AJAX 刪除卡片 hook
- 移除儲存卡去重邏輯，避免邏輯衝突
- 修正 gateway ID 不一致導致儲存卡無法顯示

## [2.0.5] - 2026-01

### Added
- 整合儲存卡管理和訂單頁面顯示功能
- 增強 3DS 付款處理流程

### Changed
- 儲存卡頁面優先使用 WC Tokens

## [2.0.4] - 2026-01

### Changed
- 更新 YSShoplineClient API endpoints
- 增強 WooCommerce plugin 的 3DS payment handling

### Fixed
- 改用直接 AJAX 提交結帳表單
- 修正表單重新提交方式，改用 click 觸發

## [2.0.3] - 2026-01

### Changed
- 重構：簡化站內付款流程，採用 Helcim 模式

### Added
- 增強 AJAX 結帳提交的 debug 訊息

## [2.0.0] - 2025

### Added
- 導入 PSR-4 新架構 (`src/` 目錄)
- 新增 DTO 資料物件層
- 新增 Block Checkout 完整支援
- 新增 REST API Webhook 處理器
- 新增 YSShoplineClient 高階 API 客戶端

### Changed
- 架構重構：雙架構並行設計
- 改善 HPOS 相容性
- 升級 WooCommerce 支援版本至 9.0

## [1.x] - 舊版本

初始發布版本。
