# YS Shopline via WooCommerce

整合 Shopline Payments 金流至 WooCommerce 的外掛，支援 HPOS 和 WooCommerce Subscriptions。

## 版本資訊

- **目前版本**：2.2.0
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

## 授權

Copyright © YangSheep Design
