# Changelog

所有重要的變更都會記錄在此檔案中。

格式基於 [Keep a Changelog](https://keepachangelog.com/zh-TW/1.0.0/)。

---

## [2.3.4] - 2026-02-21

### Fixed
- 覆寫 `YSCreditSubscription::get_tokens()` 查詢 `CREDIT_GATEWAY_ID` 下的 token，修正訂閱閘道看不到已儲存卡片的問題

## [2.3.3] - 2026-02-20

### Fixed
- 修正訂閱續扣 "No saved payment method" 錯誤：Token 已存在時 `sync_payment_token()` 提前 return，導致 subscription instrument_id 未被寫入
- 提取獨立方法 `update_subscription_instrument()`，在 `sync_payment_token()` 之外執行，不受 token 是否新建影響
- 統一 Token Gateway ID：所有信用卡 Token 統一存在 `YSOrderMeta::CREDIT_GATEWAY_ID`，移除所有多 gateway 搜尋邏輯
- 消除所有硬編碼 Meta Key：新增 6 個常數（`INSTALLMENT`、`BNPL_INSTALLMENT`、`PENDING_BIND`、`ADD_METHOD_NEXT_ACTION`、`INSTRUMENTS_CACHE`、`TOKEN_INSTRUMENT_ID`）
- 修正 `generate_reference_order_id()` 雙重 `$order->save()` 合併為一次
- 清理 `YSStatusManager` 中不存在的 legacy gateway ID

## [2.3.2] - 2026-02-19

### Changed
- 重構 `YSCreditSubscription::process_subscription_payment()` 為三個獨立方法
- 提取 `build_recurring_payment_data()` — Recurring 專用的 API 請求建構
- 提取 `handle_recurring_response()` — 使用 `YSOrderMeta` 常數處理 API 回應
- 統一 Token 查找：移除三層 fallback，改為只從 subscription meta 取得
- `save_subscription_meta_from_order()` 精簡為只存 `customer_id`
- 統一 Meta Key 常數：將所有硬編碼 `_ys_shopline_*` 改用 `YSOrderMeta::*` 常數，影響 8 個檔案

### Removed
- 移除 `find_latest_instrument_id()` — 多閘道掃描的 fallback 邏輯不再需要

## [2.3.1] - 2026-02-17

### Fixed
- 修正 ATM 虛擬帳號欄位名稱與 API 不符（`bankCode` → `recipientBankCode`、`account` → `recipientAccountNum`、`expireDate` → `dueDate`）
- 修正所有非信用卡閘道（ATM、Apple Pay、LINE Pay、JKOPay、BNPL）`paymentBehavior` 從 `QuickPayment` 改為 `Regular`
- 修正 ATM 付款流程：保留 `nextAction` 傳回前端讓 SDK 確認，確認後才產生虛擬帳號
- 修正 ATM 訂單狀態使用管理員設定（`ys_shopline_order_status_pending`）
- 修正 ATM 虛擬帳號 JSON 路徑錯誤（`response.virtualAccount` → `response.payment.virtualAccount`）
- 修正 Pay-for-order 頁面 SDK 初始化錯誤 "amount is required (1004)"：AJAX 傳遞 order_id + 所有權驗證
- 修正 Pay-for-order 頁面 "Payment session missing" 錯誤：新增 `PayForOrderHandler` + 獨立 AJAX endpoint
- 修正感謝頁面付款失敗時出現兩組重複的重新付款按鈕（隱藏 WC 預設，保留自訂樣式）
- 修正 Redirect handler 補抓 ATM 虛擬帳號資訊（正確的 API 回應路徑）
- 修正 `YSRedirectHandler::sync_payment_token()` 的 `$existing_tokens` 變數作用域 bug
- 修正 `YSCreditSubscription::save_subscription_meta_from_order()` 的 `$instrument_id` 未定義警告

### Changed
- ATM 覆寫 `handle_next_action()`：使用管理員設定的訂單狀態 + 傳回 nextAction 給前端
- 新增 `store_virtual_account_info()` 公用方法，統一 VA 資訊儲存邏輯
- ATM 感謝頁面和郵件加入轉帳操作提示文字 + 轉帳金額欄位
- 新增 `PayForOrderHandler`（JS）和 `ajax_pay_for_order`（PHP）處理重新付款頁面
- 清理 `YSGatewayBase` 殘留 TODO 注釋

## [2.3.0] - 2026-02-15

### Changed
- 重寫 `YSCreditSubscription`：對齊 Shopline Recurring API 規格
  - 續約扣款使用 `paymentBehavior: Recurring`（伺服器對伺服器）
  - 加入必要欄位：`acquirerType`、`autoConfirm`、`autoCapture`、`client.ip`
  - 使用 `generate_reference_order_id()` 確保 referenceOrderId 唯一
  - 驗證 API 回應 `status` 欄位，根據狀態分流處理（SUCCEEDED→完成、CREATED/AUTHORIZED→on-hold、其他→failed）
- 智慧 Token 查找：三層 fallback（renewal order meta → subscription meta → WC Tokens）
- 首次付款後自動儲存 `_ys_shopline_payment_instrument_id` 到 subscription meta
- 宣告 `woocommerce_subscription_payment_meta` 供管理員後台手動修改

### Fixed
- 修正續約扣款 referenceOrderId 不唯一（重試必定被 Shopline 拒絕）的問題
- 修正續約扣款未驗證 API 回應狀態，可能錯誤標記為付款完成
- 修正未使用 subscription 專屬 Token（多張卡時可能扣錯卡）

### Added
- Webhook 綁卡事件後自動更新尚未綁定 instrument 的 subscription meta

### Removed
- 移除 `YSSubscription::subscription_fail_handler()`：干擾 WC Subscriptions 內建重試機制
- 移除 `YSSubscription::copy_meta_to_renewal()`：複製的舊 meta key 從未被使用
- 移除 `YSSubscription::get_subscription_token()`、`can_charge_subscription()`：死代碼
- 移除 `YSSubscription::admin_scripts()`：空方法
- 移除 `YSCreditSubscription::get_customer_default_token()`：被智慧 Token 查找取代
- 移除 `YSCreditSubscription::process_zero_amount_subscription()`：統一走 SDK CardBindPayment

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
