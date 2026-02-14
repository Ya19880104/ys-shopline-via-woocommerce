# Changelog

所有重要的變更都會記錄在此檔案中。

格式基於 [Keep a Changelog](https://keepachangelog.com/zh-TW/1.0.0/)。

---

## [2.2.0] - 2026-02-15

### Changed
- 重構：統一所有命名為 PSR-4 風格（PascalCase 檔名 + 命名空間）
- 所有 14 個 Legacy 檔案（`class-ys-shopline-*.php`）重新命名為 PascalCase（`YS*.php`）
- 所有類別加上 `namespace YangSheep\ShoplinePayment\{Module}`
- 類別名稱統一：`YS_Shopline_*` → `YS*`（如 `YS_Shopline_Credit_Card` → `YSCreditCard`）
- 主入口類別：`YS_Shopline_Payment` → `YSShoplinePayment`
- 閘道註冊改用 `::class` 語法（FQN 字串）
- 消除所有 `require_once`，全部由 PSR-4 autoloader 載入
- 合併 `init_new_architecture()` 到 `init()`，不再區分新舊架構
- 資產檔案統一使用 `ys-shopline-*` 前綴命名

### Fixed
- Webhook 事件名稱對齊 API 文件（`trade.cancelled`、`customer.instrument.binded/unbinded`、`trade.refund.succeeded/failed`）
- Webhook 付款工具欄位改用 API 文件結構（`data.paymentInstrument.instrumentId`、`data.customerId`）
- 卡片同步欄位映射修正（`instrumentId`、`expireMonth`、`expireYear`）
- 簽章驗證移除本地環境繞過，所有環境一律驗證 HMAC

### Added
- 新增 `trade.expired`、`trade.processing`、`trade.customer_action`、`customer.instrument.updated` 事件處理
- 新增 `manual.trade.capture.succeeded`、`manual.trade.cancel.succeeded` 事件處理
- 保留舊事件名稱相容（`payment.success`、`refund.succeeded` 等）
- 新增 Block Checkout 信用卡圖示（visa.svg、mastercard.svg、jcb.svg）

### Removed
- 移除 `includes()` 和 `includes_wc()` 方法（15 個 `require_once`）
- 移除 `init_new_architecture()` 方法
- 移除簽章驗證的本地環境繞過邏輯

## [2.1.0] - 2026-02-14

### Changed
- 重構：統一所有程式碼至 `src/` 目錄，消除 `includes/` 與 `src/` 雙架構
- 所有閘道檔案搬至 `src/Gateways/`
- 所有 PSR-4 模組搬至 `src/`（Api、Blocks、DTOs、Handlers、Utils）
- Legacy 類別搬入對應子目錄（Admin、Customer、Frontend、Handlers）
- 合併 Logger：統一使用 `YSLogger`（PSR-4），所有檔案透過 `use` 語句引用
- 合併 Webhook Handler：統一使用 `YSWebhookHandler`（PSR-4），支援 REST API + WC API 雙端點
- 統一 debug option key 為 `ys_shopline_debug`
- PHP 最低需求升級至 8.0
- 更新 Composer PSR-4 autoloader 路徑

### Removed
- 移除 `includes/class-ys-shopline-logger.php`（已合併至 YSLogger）
- 移除 `includes/class-ys-shopline-webhook-handler.php`（已合併至 YSWebhookHandler）
- 移除 `class_alias` 向後相容機制，直接使用 PSR-4 類別
- 移除死代碼：`class-ys-shopline-settings.php`、`YSCustomerManager.php`
- 移除整個 `includes/` 目錄

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
