# YS Shopline Payment 開發指南

本文件詳細說明外掛的架構、開發模式和擴展方式。

---

## 目錄結構

```
ys-shopline-via-woocommerce/
├── ys-shopline-via-woocommerce.php     # 主入口（YSShoplinePayment 類別）
├── composer.json                        # PSR-4 自動加載配置
│
├── assets/                              # 靜態資源（ys-shopline-* kebab-case）
│   ├── css/
│   │   └── ys-shopline-admin-settings.css
│   ├── images/
│   │   ├── visa.svg
│   │   ├── mastercard.svg
│   │   └── jcb.svg
│   └── js/
│       ├── blocks/
│       │   └── ys-shopline-blocks.js
│       └── ys-shopline-checkout.js
│
├── src/                                 # PHP 類別（PascalCase = 類別名 = 檔名）
│   ├── Admin/
│   │   └── YSAdminSettings.php
│   ├── Api/
│   │   ├── YSApi.php
│   │   ├── YSShoplineClient.php
│   │   └── YSShoplineRequester.php
│   ├── Blocks/
│   │   ├── YSBlocksSupport.php
│   │   └── YSBlocksIntegration.php
│   ├── Customer/
│   │   └── YSCustomer.php
│   ├── DTOs/
│   │   ├── YSAmountDTO.php
│   │   ├── YSCustomerDTO.php
│   │   ├── YSPaymentDTO.php
│   │   ├── YSPaymentInstrumentDTO.php
│   │   ├── YSRefundDTO.php
│   │   └── YSSessionDTO.php
│   ├── Frontend/
│   │   └── YSOrderDisplay.php
│   ├── Gateways/
│   │   ├── YSGatewayBase.php           # 抽象基底類別
│   │   ├── YSCreditCard.php            # 信用卡
│   │   ├── YSCreditSubscription.php    # 信用卡訂閱
│   │   ├── YSVirtualAccount.php        # ATM 虛擬帳號
│   │   ├── YSJKOPay.php               # 街口支付
│   │   ├── YSApplePay.php             # Apple Pay
│   │   ├── YSLinePay.php              # LINE Pay
│   │   ├── YSChaileaseBNPL.php        # 中租零卡分期
│   │   └── YSSubscription.php          # 訂閱處理器
│   ├── Handlers/
│   │   ├── YSWebhookHandler.php
│   │   ├── YSStatusManager.php
│   │   ├── YSRedirectHandler.php
│   │   └── YSAddPaymentMethodHandler.php
│   └── Utils/
│       ├── YSLogger.php
│       ├── YSOrderMeta.php
│       └── YSSignatureVerifier.php
│
├── templates/
│   └── myaccount/
│       └── payment-methods.php
│
└── vendor/
    └── autoload.php                    # PSR-4 自動加載器
```

---

## 架構說明

### 統一 PSR-4 架構

所有 PHP 類別放在 `src/` 目錄下，檔名 = 類別名（PascalCase），由 PSR-4 autoloader 直接載入：

| 子目錄 | 命名空間 | 說明 |
|--------|---------|------|
| `src/Api/` | `YangSheep\ShoplinePayment\Api` | API 通訊 |
| `src/Admin/` | `YangSheep\ShoplinePayment\Admin` | 後台管理 |
| `src/Blocks/` | `YangSheep\ShoplinePayment\Blocks` | Block Checkout |
| `src/Customer/` | `YangSheep\ShoplinePayment\Customer` | 會員/儲存卡 |
| `src/DTOs/` | `YangSheep\ShoplinePayment\DTOs` | 資料傳輸物件 |
| `src/Frontend/` | `YangSheep\ShoplinePayment\Frontend` | 前台顯示 |
| `src/Gateways/` | `YangSheep\ShoplinePayment\Gateways` | 付款閘道 |
| `src/Handlers/` | `YangSheep\ShoplinePayment\Handlers` | 事件處理器 |
| `src/Utils/` | `YangSheep\ShoplinePayment\Utils` | 工具類別 |

### 跨模組引用

所有類別透過 `use` 語句互相引用：

```php
namespace YangSheep\ShoplinePayment\Gateways;

use YangSheep\ShoplinePayment\Utils\YSLogger;
use YangSheep\ShoplinePayment\Customer\YSCustomer;

// 引用主入口類別（全域命名空間）
\YSShoplinePayment::get_api();
```

---

## 命名規範

### 前綴規則

| 類型 | 前綴 | 範例 |
|------|------|------|
| 類別名稱 | `YS` | `YSShoplineClient`, `YSSessionDTO` |
| 函數/Hook | `ys_` | `ys_shopline_order_created` |
| Meta Key | `_ys_` | `_ys_shopline_trade_order_id` |
| Option Key | `ys_` | `ys_shopline_api_key` |
| 常數 | `YS_` | `YS_SHOPLINE_VERSION` |
| REST API | `ys-` | `ys-shopline/v1/webhook` |

### 類別名稱對照

| 模組 | 類別名稱 | FQN |
|------|---------|-----|
| 主入口 | `YSShoplinePayment` | （全域命名空間） |
| API | `YSApi` | `YangSheep\ShoplinePayment\Api\YSApi` |
| 閘道基底 | `YSGatewayBase` | `YangSheep\ShoplinePayment\Gateways\YSGatewayBase` |
| 信用卡 | `YSCreditCard` | `YangSheep\ShoplinePayment\Gateways\YSCreditCard` |
| Logger | `YSLogger` | `YangSheep\ShoplinePayment\Utils\YSLogger` |

---

## 常數定義

```php
// 版本
YS_SHOPLINE_VERSION = '2.3.4'

// 路徑
YS_SHOPLINE_PLUGIN_FILE      // 主檔完整路徑
YS_SHOPLINE_PLUGIN_DIR       // 外掛目錄路徑
YS_SHOPLINE_PLUGIN_URL       // 外掛 URL
YS_SHOPLINE_PLUGIN_BASENAME  // 外掛基本名稱
```

---

## 訂單 Meta Keys

所有 Meta Key 皆使用 HPOS 相容的方式存取：

```php
// 正確用法
$order->get_meta( '_ys_shopline_trade_order_id' );
$order->update_meta_data( '_ys_shopline_payment_detail', $data );
$order->save();
```

| Meta Key | 用途 |
|----------|------|
| `_ys_shopline_trade_order_id` | Shopline 交易 ID |
| `_ys_shopline_session_id` | Session ID |
| `_ys_shopline_payment_method` | 付款方式 |
| `_ys_shopline_payment_detail` | 完整付款資訊 |
| `_ys_shopline_refund_detail` | 退款詳情 |
| `_ys_shopline_next_action` | 3DS/Confirm 下一步動作 |
| `_ys_shopline_va_bank_code` | ATM 銀行代碼 |
| `_ys_shopline_va_account` | ATM 虛擬帳號 |
| `_ys_shopline_va_expire` | ATM 繳費期限 |
| `_ys_shopline_payment_instrument_id` | 綁定卡片 Instrument ID |

---

## Option Keys

| Option Key | 說明 |
|-----------|------|
| `ys_shopline_testmode` | 是否測試模式 |
| `ys_shopline_debug` | 是否啟用除錯 |
| `ys_shopline_merchant_id` | 正式 Merchant ID |
| `ys_shopline_api_key` | 正式 API Key |
| `ys_shopline_sign_key` | 正式簽章金鑰 |
| `ys_shopline_sandbox_merchant_id` | 沙盒 Merchant ID |
| `ys_shopline_sandbox_api_key` | 沙盒 API Key |
| `ys_shopline_sandbox_sign_key` | 沙盒簽章金鑰 |
| `ys_shopline_order_status_pending` | ATM 等待付款的訂單狀態（預設 `on-hold`） |

---

## 核心類別說明

### API 層 (src/Api/)

**YSShoplineClient** - 高階 API 客戶端
```php
use YangSheep\ShoplinePayment\Api\YSShoplineClient;

$client = new YSShoplineClient();

// 建立 Session
$session = $client->create_session( $order );

// 查詢付款
$payment = $client->get_payment( $payment_id );

// 退款
$refund = $client->create_refund( $payment_id, $amount );
```

**YSShoplineRequester** - HTTP 請求器
- 自動處理環境切換（沙盒/正式）
- 認證頭設定
- 簽章生成

### DTO 層 (src/DTOs/)

資料傳輸物件，用於 API 回應的結構化：

```php
use YangSheep\ShoplinePayment\DTOs\YSPaymentDTO;

$payment = YSPaymentDTO::from_api_response( $response );
echo $payment->status;           // 付款狀態
echo $payment->amount->value;    // 金額
```

### 閘道層

**YSGatewayBase** (src/Gateways/)
- 所有閘道的抽象基底類別（`extends WC_Payment_Gateway`）
- 提供通用設定、API 調用方法

### Webhook 處理

**YSWebhookHandler** (src/Handlers/)
- REST API 端點：`/wp-json/ys-shopline/v1/webhook`
- WC API 端點：`/wc-api/ys_shopline_webhook`
- 簽章驗證（HMAC-SHA256，`sign` + `timestamp` header）
- 支援事件：`trade.succeeded/failed/cancelled/expired/processing/authorized/captured`、`trade.refund.succeeded/failed`、`customer.instrument.binded/unbinded/updated`
- 保留舊事件格式相容（`payment.success`、`refund.succeeded` 等）

---

## 支付流程

### 站內付款流程（信用卡、Apple Pay 等）

```
1. 前端 SDK 初始化（AJAX 取得 SDK config）
2. 顧客輸入卡號 / 選擇付款方式
3. 前端呼叫 SDK createPayment() → 取得 paySession
4. paySession 隨表單提交至 process_payment()
5. 後端呼叫 Shopline API create_payment_trade()
6. 根據回應：
   ├─ 有 nextAction → 傳回前端，SDK 處理（3DS / Confirm）
   ├─ 無 nextAction + 成功 → payment_complete()
   └─ 失敗 → 訂單設為 failed
7. nextAction 處理完成後，跳轉至感謝頁
8. YSRedirectHandler 查詢交易狀態，更新訂單
9. Webhook 非同步確認
```

### ATM 虛擬帳號流程

```
1. 前端 SDK 初始化 → paySession
2. process_payment() → create_payment_trade()
3. API 回傳 nextAction(Confirm) → 傳回前端
4. 前端 SDK payment.pay(nextAction) → 產生虛擬帳號
5. 跳轉感謝頁，顯示銀行代碼 + 虛擬帳號 + 繳費期限
6. 訂單設為 on-hold，等待轉帳
7. 客戶轉帳 → Webhook trade.succeeded → 訂單完成
8. 帳號過期 → Webhook trade.expired → 訂單設為 failed
```

### Pay-for-order 流程（重新付款）

```
1. 偵測 pay-for-order 頁面（URL 含 order-pay）
2. PayForOrderHandler 攔截 #order_review 表單提交
3. 前端 SDK createPayment() → paySession
4. AJAX 呼叫 ys_shopline_pay_for_order endpoint
5. 後端驗證訂單所有權 + 呼叫 process_payment()
6. 處理 nextAction / redirect
```

### nextAction 類型

| 類型 | 說明 | 處理方式 |
|------|------|---------|
| `Redirect` | 3DS 驗證 | 跳轉至驗證頁面 |
| `Confirm` | 需要 SDK 確認（ATM 等）| 前端 `payment.pay(nextAction)` |
| `WAIT` | 等待中 | 等待 Webhook |

---

## Hook 清單

### 付款相關

```php
// 註冊支付方式
add_filter( 'woocommerce_payment_gateways', [ $this, 'add_gateways' ] );

// 添加設定頁
add_filter( 'woocommerce_get_settings_pages', [ $this, 'add_settings' ] );
```

### 訂單相關

```php
// 訂單狀態變更
add_action( 'woocommerce_order_status_changed', [ $this, 'on_status_changed' ], 10, 4 );

// 感謝頁
add_action( 'woocommerce_thankyou_ys_shopline_credit', [ $this, 'thankyou_page' ] );
```

### AJAX

```php
// SDK 配置取得
add_action( 'wp_ajax_ys_shopline_get_sdk_config', [ $this, 'get_sdk_config' ] );

// Pay-for-order 頁面付款處理
add_action( 'wp_ajax_ys_shopline_pay_for_order', [ $this, 'ajax_pay_for_order' ] );

// 刪除儲存卡
add_action( 'wp_ajax_ys_shopline_delete_card', [ $this, 'delete_card' ] );

// 同步卡片
add_action( 'wp_ajax_ys_shopline_sync_cards', [ $this, 'sync_cards' ] );
```

---

## REST API 端點

| 端點 | 方法 | 說明 |
|------|------|------|
| `/wp-json/ys-shopline/v1/webhook` | POST | Shopline Webhook 接收 |

---

## 開發指引

### 新增支付方式

1. 在 `src/Gateways/` 建立新閘道類別（`YSXxx.php` PascalCase 檔名 = 類別名）
2. 加上 `namespace YangSheep\ShoplinePayment\Gateways;`
3. 繼承 `YSGatewayBase`
4. 實作必要方法：
   - `__construct()` - 設定 id, title, supports
   - `init_form_fields()` - 定義設定欄位
   - `process_payment()` - 處理付款
5. 在主檔案 `register_gateways()` 中用 `YSNewGateway::class` 註冊

### HPOS 相容性

```php
// ✅ 正確用法
$order->get_meta( '_ys_shopline_trade_order_id' );
$order->update_meta_data( '_ys_shopline_payment_detail', $data );
$order->save();

// ❌ 避免使用
get_post_meta( $order_id, '_meta_key', true );
update_post_meta( $order_id, '_meta_key', $value );
```

### 日誌記錄

```php
use YangSheep\ShoplinePayment\Utils\YSLogger;

YSLogger::info( 'Payment processed', [ 'order_id' => $order_id ] );
YSLogger::error( 'API Error', [ 'response' => $response ] );
```

---

## 測試

### 沙盒環境

1. 在設定頁啟用「測試模式」
2. 填入沙盒 API 金鑰
3. 使用測試卡號進行測試

### 除錯日誌

啟用「除錯日誌」後，可在 **WooCommerce > 狀態 > 日誌** 查看：
- `ys-shopline-payment-*.log`

---

## 版本歷史

### 2.3.1
- 修正所有非信用卡閘道 `paymentBehavior` 從 `QuickPayment` 改為 `Regular`
- 修正 ATM 虛擬帳號 API 欄位映射和 JSON 路徑
- 修正 Pay-for-order 頁面 SDK 初始化和 paySession 缺失
- 新增 `PayForOrderHandler`（JS）和 `ajax_pay_for_order`（PHP）
- 修正感謝頁面重複的重新付款按鈕

### 2.3.0
- 重寫 `YSCreditSubscription`：對齊 Shopline Recurring API 規格
- 智慧 Token 查找：三層 fallback 機制
- 移除多個死代碼方法

### 2.2.0
- 統一所有命名為 PSR-4 風格（PascalCase + namespace）
- 消除所有 `require_once`，全部由 autoloader 載入
- 類別名稱統一：`YS_Shopline_*` → `YS*`
- 主類別：`YS_Shopline_Payment` → `YSShoplinePayment`

### 2.1.0
- 統一所有程式碼至 `src/` 目錄
- 合併 Logger 和 Webhook Handler，統一使用 PSR-4 類別
- PHP 最低需求升級至 8.0

### 2.0.7 以前
- 儲存卡管理、3DS 處理、PSR-4 架構導入

---

## 相關文件

- [架構標準](../../docs/development/architecture-standard.md)
- [編碼規範](../../docs/development/coding-standards.md)
- [Shopline 升級計畫](../../docs/plans/2026-01-14-ys-shopline-payment-upgrade-design.md)
