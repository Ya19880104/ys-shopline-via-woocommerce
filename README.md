# YS Shopline via WooCommerce

整合 Shopline Payments 金流至 WooCommerce 的外掛，支援 HPOS 和 WooCommerce Subscriptions。

## 版本資訊

- **目前版本**：2.4.3
- **PHP 需求**：>= 8.0
- **WordPress 需求**：>= 6.0
- **WooCommerce 需求**：7.0 - 9.0

## 支援的付款方式

| 付款方式 | Gateway ID | 說明 |
|----------|-----------|------|
| 信用卡 | `ys_shopline_credit` | 信用卡一次付清 |
| 信用卡分期 | `ys_shopline_credit_installment` | 信用卡分期付款 |
| 信用卡訂閱 | `ys_shopline_credit_subscription` | 訂閱制付款，整合 WC Subscriptions |
| ATM 虛擬帳號 | `ys_shopline_atm` | ATM 轉帳付款（可設繳費期限） |
| JKOPay | `ys_shopline_jkopay` | 街口支付 |
| Apple Pay | `ys_shopline_applepay` | Apple Pay |
| LINE Pay | `ys_shopline_linepay` | LINE Pay |
| Chailease BNPL | `ys_shopline_bnpl` | 中租零卡分期（可設付款期限） |

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

### 2.4.3 - 2026-03-11

**新增**
- ATM 銀行轉帳後台新增「繳費期限」下拉設定（6 小時 / 1 天 / 2 天 / 3 天），並將 `expireTime` 送至 Shopline API
- 中租 zingla 銀角零卡後台新增「付款期限」下拉設定（2 天 / 3 天 / 5 天 / 7 天），並將 `expireTime` 送至 Shopline API

**修正**
- 付款失敗訂單備註現在包含付款方式名稱，例如 `Shopline payment failed (ApplePay): Invalid store url`
- 新增 "Invalid store url" 友善錯誤訊息對應（商店網域驗證失敗提示）

### 2.4.2 - 2026-03-11

**修正**
- 退款與取消 API 補齊 `referenceOrderId`（P0）：Shopline API 要求此欄位，缺漏導致所有退款/取消回傳 400 錯誤
- Apple Pay 圖示改用本地 SVG，移除失效的 Shopline CDN 連結

### 2.4.1 - 2026-03-11

**修正**
- 修正分期付款 SDK 無法渲染：Shopline SDK 不允許同頁面同時掛載多個相同 paymentMethod 實例，新增 `clearConflictingInstances()` 切換時清除舊實例
- checkout 更新時（DOM 替換）新增 `clearAllInstances()` 重置所有 SDK 狀態

### 2.4.0 - 2026-03-11

**新增**
- 信用卡分期付款獨立為新付款方式 `YSCreditInstallment`（Gateway ID: `ys_shopline_credit_installment`）
- SHOPLINE 設定頁新增「信用卡分期」啟用開關
- 分期付款最低金額未達時自動隱藏該付款方式（`is_available()`）

**變更**
- `YSCreditCard` 簡化為純信用卡一次付清（移除分期設定與邏輯）

### 2.3.9 - 2026-03-11

**新增**
- 信用卡分期設定新增「一次付清」選項（`installmentCounts` 含 `'0'`）

### 2.3.8 - 2026-03-05

**改進**
- 嚴格狀態機：只有終態（FAILED/EXPIRED/CANCELLED）允許重新付款
- Idempotent Key：API 呼叫帶冪等鍵，防止 Shopline 端重複扣款

### 2.3.7 - 2026-03-05

**修正**
- 防呆：3DS pending 訂單已有 tradeOrderId 時阻擋重複 API 呼叫
- 防呆：Pay-for-order 頁面加入 `_isSubmitting` flag，防止重複扣款
- ERROR meta 統一：Webhook 失敗、API 錯誤皆寫入 ERROR_CODE / ERROR_MESSAGE

### 2.3.6 - 2026-03-05

**修正**
- 錯誤訊息友善化：Shopline API 原始錯誤訊息轉為可讀中文提示
- 防呆：`process_payment()` 加入訂單狀態檢查，已付款成功的訂單不再重複呼叫 API
- 防呆：前端 JS 加入 `_isSubmitting` flag，防止重複提交

### 2.3.5 - 2026-03-05

**修正**
- 移除訂閱結帳頁雙重 UI（WC radio buttons 與 SDK 卡片選擇器）
- 新增 inline CSS 強制顯示 SDK 容器內卡片品牌圖示

### 2.3.4 - 2026-02-21

**修正**
- 修正訂閱閘道看不到已儲存卡片的問題

### 2.3.3 - 2026-02-20

**修正**
- 修正訂閱續扣 "No saved payment method" 錯誤
- 統一 Token Gateway ID + 消除所有硬編碼 Meta Key

### 2.3.2 - 2026-02-19

**重構**
- 重構 `YSCreditSubscription::process_subscription_payment()` 為三個獨立方法
- 統一 Meta Key 常數：所有硬編碼 `_ys_shopline_*` 改用 `YSOrderMeta::*` 常數

### 2.3.1 - 2026-02-17

**修正**
- 修正所有非信用卡閘道 `paymentBehavior` 從 `QuickPayment` 改為 `Regular`
- 修正 ATM 虛擬帳號欄位名稱與 API 不符
- 修正 Pay-for-order 頁面多項錯誤
- 新增 `PayForOrderHandler` 處理重新付款頁面

### 2.3.0 - 2026-02-15

**變更**
- 重寫 `YSCreditSubscription`：對齊 Shopline Recurring API 規格
- 續約扣款使用 `paymentBehavior: Recurring`（伺服器對伺服器）

### 2.2.0 - 2026-02-15

**變更**
- 統一所有命名為 PSR-4 風格（PascalCase 檔名 + namespace）
- 消除所有 `require_once`，全部由 PSR-4 autoloader 載入

### 2.1.0 - 2026-02-14

- 統一所有程式碼至 `src/` 目錄
- PHP 最低需求升級至 8.0

### 2.0.7 以前

- 儲存卡管理、3DS 處理、PSR-4 架構導入、Block Checkout 支援

---

## 授權

Copyright © YangSheep Design
