# YS Shopline Payment 開發指南

本文件詳細說明外掛的架構、開發模式和擴展方式。

---

## 目錄結構

```
ys-shopline-via-woocommerce/
├── ys-shopline-via-woocommerce.php     # 主入口檔案
├── composer.json                        # PSR-4 自動加載配置
│
├── assets/                              # 靜態資源
│   ├── css/
│   │   └── admin-settings.css          # 後台設定樣式
│   └── js/
│       ├── blocks/
│       │   └── ys-shopline-blocks.js   # Block Checkout 支援
│       ├── shopline-checkout.js        # 結帳頁 SDK 初始化
│       └── ys-shopline-myaccount.js    # My Account 卡片管理
│
├── includes/                            # 核心類別
│   ├── class-ys-shopline-loader.php    # 自動加載器
│   ├── class-ys-shopline-logger.php    # 日誌記錄
│   ├── class-ys-shopline-api.php       # API 通訊
│   ├── class-ys-shopline-webhook-handler.php
│   ├── class-ys-shopline-customer.php
│   ├── class-ys-shopline-order-display.php
│   ├── class-ys-shopline-redirect-handler.php
│   │
│   └── admin/
│       └── class-ys-shopline-settings.php
│
├── src/                                 # 功能模組
│   ├── Api/                            # API 客戶端（PSR-4）
│   │   ├── YSShoplineClient.php
│   │   └── YSShoplineRequester.php
│   │
│   ├── Blocks/                         # Block Checkout（PSR-4）
│   │   ├── YSBlocksSupport.php
│   │   └── YSBlocksIntegration.php
│   │
│   ├── Customer/                       # 儲存卡管理（PSR-4）
│   │   ├── YSCustomerManager.php
│   │   └── YSMyAccountEndpoint.php
│   │
│   ├── DTOs/                           # 資料傳輸物件（PSR-4）
│   │   ├── YSSessionDTO.php
│   │   ├── YSPaymentDTO.php
│   │   ├── YSPaymentInstrumentDTO.php
│   │   ├── YSAmountDTO.php
│   │   ├── YSCustomerDTO.php
│   │   └── YSRefundDTO.php
│   │
│   ├── Gateways/                       # 付款閘道（SDK 站內付款）
│   │   ├── class-ys-shopline-gateway-base.php
│   │   ├── class-ys-shopline-credit-card.php
│   │   ├── class-ys-shopline-credit-subscription.php
│   │   ├── class-ys-shopline-virtual-account.php
│   │   ├── class-ys-shopline-jkopay.php
│   │   ├── class-ys-shopline-applepay.php
│   │   ├── class-ys-shopline-linepay.php
│   │   ├── class-ys-shopline-chailease-bnpl.php
│   │   └── class-ys-shopline-subscription.php
│   │
│   ├── Handlers/                       # Webhook、狀態同步（PSR-4）
│   │   ├── YSWebhookHandler.php
│   │   └── YSStatusManager.php
│   │
│   └── Utils/                          # 工具類別（PSR-4）
│       ├── YSLogger.php
│       ├── YSOrderMeta.php
│       └── YSSignatureVerifier.php
│
├── templates/
│   └── myaccount/
│
├── languages/
│
└── vendor/                              # Composer 依賴
```

---

## 架構說明

### 混合架構設計

外掛採用混合架構：

| 目錄 | 命名規則 | 說明 |
|------|---------|------|
| `includes/` | `YS_Shopline_*` | 核心類別、API、設定頁 |
| `src/Gateways/` | `YS_Shopline_*` | 付款閘道（SDK 站內付款） |
| `src/` 其他 | `YS*` (PSR-4) | 輔助功能模組 |

### 命名空間

```php
// 新架構使用 PSR-4 命名空間
namespace YangSheep\ShoplinePayment\Api;
namespace YangSheep\ShoplinePayment\Blocks;
namespace YangSheep\ShoplinePayment\Customer;
namespace YangSheep\ShoplinePayment\DTOs;
namespace YangSheep\ShoplinePayment\Gateways;
namespace YangSheep\ShoplinePayment\Handlers;
namespace YangSheep\ShoplinePayment\Utils;
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

### 舊架構類別名稱

```php
YS_Shopline_Payment         // 主外掛類別
YS_Shopline_Gateway_Base    // 閘道基底類別
YS_Shopline_Credit_Card     // 信用卡閘道
```

---

## 常數定義

```php
// 版本
YS_SHOPLINE_VERSION = '2.0.6'

// 路徑
YS_SHOPLINE_PLUGIN_FILE      // 主檔完整路徑
YS_SHOPLINE_PLUGIN_DIR       // 外掛目錄路徑
YS_SHOPLINE_PLUGIN_PATH      // 同 PLUGIN_DIR
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
| `_ys_shopline_next_action` | 3DS 下一步動作 |

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

**YS_Shopline_Gateway_Base** (舊架構)
- 所有舊架構閘道的基底類別
- 提供通用設定、API 調用方法

**YSAbstractGateway** (新架構)
- 新架構抽象閘道類別
- 簡化的付款流程實現

### Webhook 處理

**YSWebhookHandler** (src/Handlers/)
- REST API 端點：`/wp-json/ys-shopline/v1/webhook`
- 簽章驗證
- 事件處理和訂單狀態更新

---

## 支付流程

### 跳轉支付流程

```
1. 顧客選擇付款方式，點擊結帳
2. 呼叫 process_payment()
   ├─ 建立 WC 訂單
   ├─ 呼叫 Shopline API 建立 Session
   └─ 儲存 session_id 至訂單 Meta

3. 跳轉至 Shopline 付款頁面 (session.redirectUrl)

4. 顧客完成付款，跳回 return URL
   └─ YS_Shopline_Redirect_Handler 處理

5. 查詢 Session 狀態，更新訂單
   ├─ 成功：設為 processing
   └─ 失敗：設為 failed

6. Webhook 通知（非同步）
   └─ YSWebhookHandler 處理狀態確認
```

### 3D Secure 處理

```php
// nextAction 類型處理
switch ( $next_action['type'] ) {
    case 'Redirect':
        // 跳轉至 3DS 驗證頁
        break;
    case 'Confirm':
        // 需要重新確認
        break;
    case 'WAIT':
        // 等待中
        break;
}
```

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

1. 在 `src/Gateways/` 建立新閘道類別
2. 繼承 `YS_Shopline_Gateway_Base`
3. 實作必要方法：
   - `__construct()` - 設定 id, title, supports
   - `init_form_fields()` - 定義設定欄位
   - `process_payment()` - 處理付款
4. 在主檔案註冊閘道

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

### 2.0.6
- 改善儲存卡頁面使用 AJAX 更新
- 修正兩位數年份導致 WC Token 建立失敗
- 修正儲存卡同步和刪除功能

### 2.0.5
- 整合儲存卡管理和訂單頁面顯示功能
- 增強 3DS 付款處理

### 2.0.0
- 導入 PSR-4 新架構
- 新增 DTO 資料物件
- 改善 Block Checkout 支援

---

## 相關文件

- [架構標準](../../docs/development/architecture-standard.md)
- [編碼規範](../../docs/development/coding-standards.md)
- [Shopline 升級計畫](../../docs/plans/2026-01-14-ys-shopline-payment-upgrade-design.md)
