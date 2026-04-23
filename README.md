# YS Shopline via WooCommerce

整合 Shopline Payments 金流至 WooCommerce 的外掛，支援 HPOS 和 WooCommerce Subscriptions。

## 版本資訊

- **目前版本**：3.4.4
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
- **Block Checkout**：已註冊但尚未完整支援（目前僅支援傳統結帳頁）
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

### 3.4.4 - 2026-04-23

**修復 — 新增付款方式 3DS 流程斷線**
- 修正「新增付款方式」完成輸入後不跳頁、Token 未儲存的嚴重 bug
- 根因：`YSGatewayBase::add_payment_method()` 回傳 `nextAction`，但 WooCommerce `WC_Form_Handler` 只認 `result` + `redirect`，導致 3DS 從未執行，Shopline trade 停留 `CREATED` 狀態
- 修法：回傳 `redirect` 指向 3DS 頁面（`?ys_shopline_3ds=1&add_method=1`），由 `YSAddPaymentMethodHandler::handle_3ds_page()` 渲染 SDK + `payment.pay(nextAction)`，3DS 完成後經 returnUrl 觸發 `handle_add_method_redirect()` 建立 WC Token

**修復 — 「新增付款方式」按鈕消失**
- `templates/myaccount/payment-methods.php` 原條件 `get_available_payment_gateways()` 在 My Account 無 cart/order 時常回空陣列
- 改為直接檢查 `ys_shopline_credit` 閘道是否啟用且支援 `add_payment_method`

**調整 — 綁卡頁只顯示新卡輸入（Q3）**
- 新增付款方式頁 SDK config 不再傳 `customerToken`，SDK 只顯示卡號輸入欄位（不再顯示已綁卡片列表）
- 對齊官方 `/guide/quick/` 4.1 純綁卡範例（SDK init 無 `customerToken`）

### 3.4.3 - 2026-04-23

**調整 — CardBind 金額對齊官方範例**
- `CardBind`（純綁卡）placeholder 金額 `100`（TWD $1）改為 `10000`（TWD $100）
- 對齊 SHOPLINE 官方文件 `/guide/quick/` 章節 4.1 的 CardBind SDK 初始化範例（`amount: 10000`）
- 統一兩條綁卡路徑：My Account「新增付款方式」、`$0` 訂閱試用結帳
- 同步前端 SDK init、後端 API 呼叫、`order.products[0].amount` 三處金額
- `CardBind` 為 SHOPLINE「非付款場景」paymentBehavior，銀行進行卡片授權驗證但不實際請款

**修復**
- 修正 `YSBindCardLogger` 記錄 `amount` 欄位誤植為 `0`（實際送出為 `10000`），避免日後除錯誤判

### 3.4.2 - 2026-04-23

**修復**
- 修正訂閱試用 $0 付款失敗（SHOPLINE API 錯誤 1025 `Create amount error`）
- 修正新增卡片頁相同錯誤
- 根因：SHOPLINE API 即使 `CardBind` 模式也不接受 `amount.value=0`，需傳 100（TWD $1）通過驗證
- `CardBind` 為 SHOPLINE「純綁卡」paymentBehavior，銀行進行授權驗證但不實際請款
- `YSCreditSubscription` supports 陣列加入 `add_payment_method`

**修復 — 我的帳戶頁「新增付款方式」按鈕消失**
- 根因：`$this->enabled` 讀的是 WC 原生 gateway settings，但本外掛用自訂 option `ys_shopline_{gateway}_enabled`，導致 `parent::is_available()` 回傳 false
- 修正：`__construct()` 中手動同步 `$this->enabled` 自自訂 option

### 3.4.1 - 2026-04-09

**修復**
- 修正綁卡 API 錯誤「order is required; billing is required」：`add_payment_method()` 補齊 `billing` + `order` 欄位
- 新增 `build_user_billing_address()` helper，從 user billing meta + 商店地址 fallback 組出地址

**UX 強化**
- 訂閱閘道結帳頁不再顯示已儲存卡片，強制走新增卡流程（避免已儲存卡無法直接訂閱的混淆）
- 訂閱綁卡提示移到「定期扣款」說明下方，改為藍色資訊框
- JS 依 bindOnlyMode 動態切換提示文字（試用期 vs 一般訂閱）

### 3.4.0 - 2026-04-09

**新增 — 綁卡專用日誌系統**
- 新增 `YSBindCardLogger` 獨立日誌類別，與 Debug Log 完全分離
- 進階設定新增「綁卡紀錄」開關（預設關閉）
- 設定頁新增「綁卡紀錄」tab：日期選擇、內容檢視、下載
- 每日輪轉、保留 30 天、cron 自動清理
- 敏感欄位（CVV、完整卡號、金鑰）自動遮罩
- 日誌目錄含 `.htaccess` 防直接 URL 存取

**修復 — 綁卡流程完整重構**
- 修正「新增付款方式」頁 SDK 錯誤 `amount is required (1004)`
- SDK init 採 amount=100 通過 SDK 驗證，後端仍用 `CardBind` + amount=0 不扣款
- 新增付款方式頁只顯示信用卡，排除分期/訂閱閘道
- 修正購物車有商品時新增卡片頁會抓購物車金額的 bug
- 訂閱試用 $0 首期走 `CardBind` paymentBehavior（原本強制 `CardBindPayment` 導致 SDK 1004）
- 新增卡片頁與 $0 訂閱結帳頁加入藍色提示文字說明綁卡驗證性質

### 3.3.3 - 2026-04-09

**修正**
- 修正 Token 同步導致「我的帳戶」頁白屏：API 回傳不完整的 `instrumentCard` 時 WC data store 驗證失敗
- `create_wc_token_from_instrument()` 加入完整防禦：`is_array()` 正規化、`is_scalar()` 型別檢查、`last4` 格式驗證、`catch \Throwable`
- 付款方式頁新增「預設」卡片用途提示（用於訂閱續扣，非結帳頁卡片順序）
- 後台說明文件補充預設卡片與結帳頁卡片順序的差異

### 3.3.2 - 2026-04-02

**改進**
- Apple Pay 不支援裝置改顯示友善提示（取代紅色 SDK 錯誤），並對 1100/2009/4200/4204 等錯誤碼提供中文說明
- 消除切換付款方式的競態風險：同 gateway 已有 instance 時 `onPaymentMethodChange()` 直接跳過
- order-pay 頁面不再註冊全域 `change`/`updated_checkout` listener，消除雙 handler 衝突

### 3.3.1 - 2026-04-02

**修正**
- 訪客 order-pay 頁面補傳 `order_key`，修正「無權存取此訂單」與 SDK 金額為 0 的問題
- Pay-for-order 切換付款方式加入 `clearConflictingInstances()`，防止同類型 SDK 衝突
- Block Checkout 暫時停用（`canMakePayment: false`），因尚未整合 SDK paySession 流程
- README 修正 Block Checkout 支援狀態描述

### 3.2.9 - 2026-04-01

**修正**
- 修正結帳頁首次載入時 SDK 重複渲染（所有付款方式出現兩次）
- 根因：`init()` 與 WC `updated_checkout` 同時觸發 `initSDK()`，`ShoplinePayments()` 被呼叫時即渲染 iframe
- 移除 `init()` 中的 `onPaymentMethodChange()` 呼叫，統一由 `updated_checkout` 初始化
- 保留 `sdkGeneration` + `pendingAjax` 作為額外防護層

### 3.0.2 - 2026-03-18

**修正**
- ATM 虛擬帳號資訊未儲存至訂單 meta，導致感謝頁與訂單內頁無法顯示銀行代碼、帳號、繳費期限
- `trade.customer_action` webhook 改為提取並儲存 VA 資訊（原本只做 log）
- Redirect handler 增加 `virtualAccount` fallback 路徑與 warning log

### 3.0.1 - 2026-03-14

**修正**
- 空字串 API 回應正確回傳 `empty_response` 錯誤碼（不再誤歸為 `json_decode_error`）
- GET 請求參數透過 `add_query_arg()` 加入 URL query string（不再被靜默忽略）

### 3.0.0 - 2026-03-13

**重構（Breaking）**
- API Client 統一：消除 `YSApi` / `YSShoplineRequester+YSShoplineClient` 雙軌架構
- `YSApi` 內部改委託 `YSShoplineRequester` 執行 HTTP 請求，對外維持 `array|WP_Error` 契約
- 新增 `YSApiException`（結構化 API 錯誤碼）與 `YSApiPartialSuccessException`（3DS 部分成功）
- `YSShoplineRequester` 新增 `idempotent_key` 與 400 部分成功容錯
- `query_session()`、`query_payment()`、`cancel_payment_by_ids()` 併入 `YSApi`
- `YSStatusManager` 改用 `YSApi`（`is_wp_error()` 取代 `try/catch`）
- 刪除 `YSShoplineClient`
- 移除未使用方法：`capture_payment`、`cancel_payment(array)`、`get_refund`、`check_credentials`、`get_api_url`、`is_test_mode`
- `get_api()` 簡化（憑證讀取委託 Requester）

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
