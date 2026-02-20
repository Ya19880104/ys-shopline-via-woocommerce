# YS Shopline via WooCommerce

整合 Shopline Payments 金流至 WooCommerce 的外掛，支援 HPOS 和 WooCommerce Subscriptions。

## 版本資訊

- **目前版本**：2.3.4
- **PHP 需求**：>= 8.0
- **WordPress 需求**：>= 6.0
- **WooCommerce 需求**：7.0 - 9.0

## 支援的付款方式

| 付款方式 | Gateway ID | 說明 |
|----------|-----------|------|
| 信用卡 | `ys_shopline_credit` | 支援分期付款 |
| 信用卡訂閱 | `ys_shopline_credit_subscription` | 訂閱制付款，整合 WC Subscriptions |
| ATM 虛擬帳號 | `ys_shopline_atm` | ATM 轉帳付款 |
| JKOPay | `ys_shopline_jkopay` | 街口支付 |
| Apple Pay | `ys_shopline_applepay` | Apple Pay |
| LINE Pay | `ys_shopline_linepay` | LINE Pay |
| Chailease BNPL | `ys_shopline_bnpl` | 中租零卡分期 |

## 主要功能

- **HPOS 相容**：完全支援 WooCommerce High-Performance Order Storage
- **Block Checkout**：支援 WooCommerce Block 結帳頁
- **訂閱支援**：與 WooCommerce Subscriptions 整合
- **儲存卡管理**：My Account 頁面管理已儲存的付款工具
- **Webhook 整合**：自動接收 Shopline 付款狀態通知
- **狀態同步**：自動和手動訂單狀態同步
- **沙盒模式**：支援測試環境切換

## 安裝方式

1. 上傳外掛至 `wp-content/plugins/` 目錄
2. 在 WordPress 後台啟用外掛
3. 前往 **WooCommerce > 設定 > 付款** 設定各付款方式
4. 前往 **WooCommerce > 設定 > Shopline Payment** 設定 API 金鑰

## 設定說明

### API 金鑰設定

在 **WooCommerce > 設定 > Shopline Payment** 頁面設定：

- **測試模式**：啟用後使用沙盒環境
- **Merchant ID**：商家 ID
- **API Key**：API 金鑰
- **Sign Key**：簽章金鑰

### Webhook 設定

在 Shopline 商家後台設定 Webhook URL：

```
https://your-domain.com/wp-json/ys-shopline/v1/webhook
```

## 開發者資訊

詳細的開發文件請參考 [DEVELOPMENT.md](DEVELOPMENT.md)。

---

## 變更紀錄

### 2.3.4 - 2026-02-21

**修正**
- 覆寫 `YSCreditSubscription::get_tokens()` 查詢 `CREDIT_GATEWAY_ID` 下的 token，修正訂閱閘道看不到已儲存卡片的問題

### 2.3.3 - 2026-02-20

**修正**
- 修正訂閱續扣 "No saved payment method" 錯誤：Token 已存在時 `sync_payment_token()` 提前 return，導致 subscription instrument_id 未被寫入
- 提取獨立方法 `update_subscription_instrument()`，在 `sync_payment_token()` 之外執行，不受 token 是否新建影響
- **統一 Token Gateway ID**：所有信用卡 Token 統一存在 `YSOrderMeta::CREDIT_GATEWAY_ID`（`ys_shopline_credit`），移除所有多 gateway 搜尋邏輯
- **消除所有硬編碼 Meta Key**：新增 6 個常數（`INSTALLMENT`、`BNPL_INSTALLMENT`、`PENDING_BIND`、`ADD_METHOD_NEXT_ACTION`、`INSTRUMENTS_CACHE`、`TOKEN_INSTRUMENT_ID`），影響 `YSCreditCard`、`YSChaileaseBNPL`、`YSAddPaymentMethodHandler`、`YSGatewayBase`、`YSCustomer`
- 修正 `generate_reference_order_id()` 雙重 `$order->save()` 合併為一次
- 清理 `YSStatusManager` 中不存在的 legacy gateway ID（`ys_shopline_redirect`、`ys_shopline_token`）

### 2.3.2 - 2026-02-19

**重構**
- 重構 `YSCreditSubscription::process_subscription_payment()` 為三個獨立方法：流程控制、資料建構、回應處理
- 提取 `build_recurring_payment_data()` — Recurring 專用的 API 請求建構
- 提取 `handle_recurring_response()` — 使用 `YSOrderMeta` 常數處理 API 回應
- 統一 Token 查找：移除三層 fallback，改為只從 subscription meta 取得（唯一正確來源）
- `save_subscription_meta_from_order()` 精簡為只存 `customer_id`，`instrument_id` 由 Redirect/Webhook Handler 負責
- **統一 Meta Key 常數**：將所有硬編碼 `_ys_shopline_*` 字串改用 `YSOrderMeta::*` 常數
  - 新增 13 個常數至 `YSOrderMeta`（含 `CUSTOMER_ID`、`PAYMENT_INSTRUMENT_ID`、`VA_*`、`CARD_*`、`ERROR_*` 等）
  - 影響 8 個檔案：`YSGatewayBase`、`YSRedirectHandler`、`YSOrderDisplay`、`YSPaymentDTO`、`YSWebhookHandler`、`YSCreditSubscription`、`YSVirtualAccount`、`YSCustomer`
  - `YSCustomer::META_CUSTOMER_ID` 改為指向 `YSOrderMeta::CUSTOMER_ID`，消除重複定義

**移除**
- 移除 `find_latest_instrument_id()` — 多閘道掃描的 fallback 邏輯不再需要

### 2.3.1 - 2026-02-17

**修正**
- 修正所有非信用卡閘道（ATM、Apple Pay、LINE Pay、JKOPay、BNPL）`paymentBehavior` 從 `QuickPayment` 改為 `Regular`
- 修正 ATM 虛擬帳號欄位名稱與 API 不符（`bankCode` → `recipientBankCode`、`account` → `recipientAccountNum`、`expireDate` → `dueDate`）
- 修正 ATM 付款流程：保留 `nextAction` 傳回前端讓 SDK 確認，確認後才產生虛擬帳號
- 修正 ATM 虛擬帳號 JSON 路徑（`response.payment.virtualAccount`）
- 修正 Pay-for-order 頁面 SDK 初始化錯誤 "amount is required (1004)"
- 修正 Pay-for-order 頁面 "Payment session missing" 錯誤：新增 `PayForOrderHandler` + 獨立 AJAX endpoint
- 修正感謝頁面付款失敗時出現兩組重複的重新付款按鈕
- 修正 Redirect handler 補抓 ATM 虛擬帳號資訊
- 修正 `YSRedirectHandler::sync_payment_token()` 變數作用域 bug
- 修正 `YSCreditSubscription::save_subscription_meta_from_order()` 未定義變數警告

**變更**
- ATM 覆寫 `handle_next_action()`：使用管理員設定的訂單狀態 + 傳回 nextAction 給前端
- 新增 `store_virtual_account_info()` 公用方法，統一 VA 資訊儲存邏輯
- ATM 感謝頁面和郵件加入轉帳操作提示文字 + 轉帳金額欄位
- 新增 `PayForOrderHandler`（JS）和 `ajax_pay_for_order`（PHP）處理重新付款頁面

### 2.3.0 - 2026-02-15

**變更**
- 重寫 `YSCreditSubscription`：對齊 Shopline Recurring API 規格
- 續約扣款使用 `paymentBehavior: Recurring`（伺服器對伺服器）
- Token 查找從 subscription meta 取得 instrument ID
- 首次付款後自動儲存 `_ys_shopline_payment_instrument_id` 到 subscription meta

**修正**
- 修正續約扣款 referenceOrderId 不唯一的問題
- 修正續約扣款未驗證 API 回應狀態
- 修正未使用 subscription 專屬 Token

**移除**
- 移除多個死代碼方法（`subscription_fail_handler`、`copy_meta_to_renewal` 等）

### 2.2.0 - 2026-02-15

**變更**
- 統一所有命名為 PSR-4 風格（PascalCase 檔名 + 命名空間）
- 類別名稱統一：`YS_Shopline_*` → `YS*`
- 消除所有 `require_once`，全部由 PSR-4 autoloader 載入

**修正**
- Webhook 事件名稱對齊 API 文件
- 卡片同步欄位映射修正
- 簽章驗證移除本地環境繞過

### 2.1.0 - 2026-02-14

- 統一所有程式碼至 `src/` 目錄
- 合併 Logger 和 Webhook Handler
- PHP 最低需求升級至 8.0

### 2.0.7 - 2026-02-10

- 將所有程式碼統一到 `includes/`
- 移除死代碼

### 2.0.6 以前

- 改善儲存卡管理和 3DS 付款處理
- 導入 PSR-4 架構和 Block Checkout 支援

---

## 授權

Copyright © YangSheep Design
