# 付款防護升級方案 v2.3.8

## 目標

解決 Review 指出的三個問題：
1. PROCESSING/未知中間狀態仍可建立新交易
2. 缺少伺服器端併發鎖
3. 查到前次交易成功時 meta 不完整

## 方案總覽

```
process_payment($order_id)
  │
  ├─ [1] 狀態檢查：processing/completed/on-hold → 導向感謝頁
  │
  ├─ [2] Transient Lock 檢查
  │   └─ has_order_lock($order) → 提示「付款處理中」
  │
  ├─ [3] 前次交易查詢（pending + 有 tradeOrderId）
  │   ├─ SUCCEEDED/CAPTURED → 導向感謝頁（RedirectHandler 補 meta）
  │   ├─ 所有非終態（含 PROCESSING、未知）→ 提示「尚未完成」
  │   └─ FAILED/EXPIRED/CANCELLED → 清除，允許重試
  │
  ├─ [4] set_order_lock($order)  ← 取得鎖
  │
  ├─ [5] API create_payment_trade()（含 idempotentKey header）
  │   ├─ 失敗 → release_order_lock() + 回傳 failure
  │   └─ 成功 → 保留鎖（redirect/webhook 釋放）
  │
  └─ [6] 回傳 nextAction 或 redirect
```

---

## 修改項目

### 項目 1：Transient Lock（防併發）

**檔案**：`src/Gateways/YSGatewayBase.php`

新增三個方法：

```php
protected function set_order_lock( $order ) {
    $order_id = is_object( $order ) ? $order->get_id() : $order;
    set_transient( 'ys_shopline_lock_' . $order_id, $order_id, 2 * MINUTE_IN_SECONDS );
}

protected function has_order_lock( $order ) {
    $order_id = is_object( $order ) ? $order->get_id() : $order;
    return false !== get_transient( 'ys_shopline_lock_' . $order_id );
}

protected function release_order_lock( $order ) {
    $order_id = is_object( $order ) ? $order->get_id() : $order;
    delete_transient( 'ys_shopline_lock_' . $order_id );
}
```

**整合到 `process_payment()`**：
- 狀態檢查之後、paySession 驗證之前，加入 `has_order_lock()` 檢查
- API 呼叫之前 `set_order_lock()`
- API 失敗時 `release_order_lock()`
- 成功/nextAction 時保留 lock

**釋放點**：
- `YSRedirectHandler::check_and_update_order()` 結尾
- `YSWebhookHandler::handle_trade_succeeded()` 結尾
- `YSWebhookHandler::handle_trade_failed()` 結尾
- Lock TTL 2 分鐘自動過期（兜底）

### 項目 2：嚴格狀態機（只允許終態重試）

**檔案**：`src/Gateways/YSGatewayBase.php` → `check_prior_trade_status()`

改為白名單終態判斷：

```php
// 終態清單：只有這些才允許重試
$terminal_statuses = array( 'FAILED', 'EXPIRED', 'CANCELLED' );

if ( in_array( $status, $terminal_statuses, true ) ) {
    // 清除 tradeOrderId，允許重試
    ...
    return null;
}

// 成功
if ( in_array( $status, array( 'SUCCEEDED', 'SUCCESS', 'CAPTURED' ), true ) ) {
    // 導向感謝頁
    ...
}

// 其他所有狀態（CREATED/PENDING/AUTHORIZED/PROCESSING/未知）一律視為進行中
wc_add_notice( '前次付款流程尚未完成...', 'error' );
return array( 'result' => 'failure' );
```

### 項目 3：Idempotent Key（API 層防重）

**檔案**：`src/Api/YSApi.php` → `request()`

在 headers 加入 `idempotentKey`：

```php
private function request( $endpoint, $data = array(), $method = 'POST', $idempotent_key = '' ) {
    $headers = array(
        'Content-Type' => 'application/json',
        'merchantId'   => $this->merchant_id,
        'apiKey'       => $this->api_key,
        'requestId'    => $request_id,
    );

    if ( ! empty( $idempotent_key ) ) {
        $headers['idempotentKey'] = substr( $idempotent_key, 0, 32 );
    }
    ...
}
```

**檔案**：`src/Api/YSApi.php` → `create_payment_trade()`

```php
public function create_payment_trade( $data, $idempotent_key = '' ) {
    return $this->request( '/trade/payment/create', $data, 'POST', $idempotent_key );
}
```

**檔案**：`src/Gateways/YSGatewayBase.php` → `process_payment()`

呼叫時帶入 idempotent key：

```php
// 冪等鍵 = order_id + attempt（同一次嘗試重送相同 key）
$idempotent_key = $order->get_id() . '_' . $order->get_meta( YSOrderMeta::PAYMENT_ATTEMPT );
$response = $this->api->create_payment_trade( $payment_data, $idempotent_key );
```

> **注意**：idempotent key 在 `generate_reference_order_id()` 之後產生，
> 用的是「當次 attempt」，所以重送同筆會帶相同 key，Shopline 端只處理一次。

### 項目 4：查到成功時導向感謝頁（不自行補 meta）

**檔案**：`src/Gateways/YSGatewayBase.php` → `check_prior_trade_status()`

```php
// 前一筆已成功 → 導向感謝頁（RedirectHandler 會查 API 補齊所有 meta）
if ( in_array( $status, array( 'SUCCEEDED', 'SUCCESS', 'CAPTURED' ), true ) ) {
    YSLogger::info( 'Prior trade already succeeded, redirecting to thank-you page', array(
        'order_id'       => $order->get_id(),
        'trade_order_id' => $existing_trade_id,
    ) );

    return array(
        'result'   => 'success',
        'redirect' => $this->get_return_url( $order ),
    );
}
```

> 不在這裡呼叫 `payment_complete()`，改由 `YSRedirectHandler::process_redirect()`
> 統一處理（它會查 API、寫 payment_method、card_info、sync token、update subscription）。

### 項目 5：Webhook/Redirect 釋放 Lock

**檔案**：`src/Handlers/YSRedirectHandler.php` → `check_and_update_order()` 結尾

```php
// 釋放 order lock
delete_transient( 'ys_shopline_lock_' . $order->get_id() );
```

**檔案**：`src/Handlers/YSWebhookHandler.php` → `handle_trade_succeeded()` / `handle_trade_failed()` 結尾

```php
// 釋放 order lock
delete_transient( 'ys_shopline_lock_' . $order->get_id() );
```

---

## 修改檔案清單

| # | 檔案 | 動作 |
|---|------|------|
| 1 | `src/Gateways/YSGatewayBase.php` | 新增 lock 方法 + 整合到 process_payment + 修改 check_prior_trade_status 狀態機 + idempotent key 傳遞 |
| 2 | `src/Api/YSApi.php` | request() 支援 idempotentKey header + create_payment_trade() 傳遞 |
| 3 | `src/Handlers/YSRedirectHandler.php` | check_and_update_order() 結尾釋放 lock |
| 4 | `src/Handlers/YSWebhookHandler.php` | handle_trade_succeeded/failed 結尾釋放 lock |
| 5 | `CHANGELOG.md` | v2.3.8 記錄 |
| 6 | 版本號檔案 | 同步 2.3.8 |

---

## process_payment() 修改後完整流程

```
process_payment($order_id)
  │
  ├─ wc_get_order() 驗證
  │
  ├─ 已付款狀態 → 導向感謝頁
  │
  ├─ has_order_lock() → 提示「付款處理中，請勿重複操作」
  │
  ├─ 有 tradeOrderId + pending → check_prior_trade_status()
  │   ├─ SUCCEEDED/CAPTURED → 導向感謝頁（不做 payment_complete）
  │   ├─ FAILED/EXPIRED/CANCELLED → 清除 tradeOrderId + release_order_lock → 繼續
  │   └─ 其他所有狀態 → 提示「尚未完成」
  │
  ├─ paySession 驗證
  ├─ API 可用性驗證
  │
  ├─ set_order_lock()  ← 取得鎖
  ├─ prepare_payment_data()
  ├─ generate_reference_order_id() → attempt 計數
  │
  ├─ idempotent_key = order_id + '_' + attempt
  ├─ api->create_payment_trade($data, $idempotent_key)
  │   ├─ WP_Error → release_order_lock() + failed 狀態 + 友善訊息
  │   ├─ 有 nextAction → 存 tradeOrderId + handle_next_action（保留 lock）
  │   └─ 無 nextAction → payment_complete（保留 lock，redirect 釋放）
  │
  └─ 回傳 result
```

---

## 防護層級對照

| 攻擊向量 | 防護機制 |
|---------|---------|
| 用戶連點按鈕 | `_isSubmitting` + Block UI |
| 瀏覽器雙重提交 | `_isSubmitting` |
| 3DS 回來重新提交 | `check_prior_trade_status()` + 嚴格狀態機 |
| 兩個 tab 同時提交 | Transient Lock |
| 網路重送（同一請求） | Idempotent Key（Shopline 端去重） |
| Webhook + Redirect 同時到達 | Transient Lock + `is_paid()` 檢查 |

---

## 風險評估

- **Transient Lock**：MySQL transient 在極高併發下仍有微小 race condition（兩個請求同 ms 讀取），但搭配 idempotent key 後 Shopline 端保證只扣一次
- **Idempotent Key**：依賴 Shopline API 正確實作冪等性（wp-power-checkout 已驗證此功能可用）
- **Lock TTL 2 分鐘**：3DS 驗證通常 30 秒內完成，2 分鐘足夠；最差情況 lock 自動過期
- **不影響 failed 訂單重試**：failed 狀態不會有 lock（API 失敗時已釋放），tradeOrderId 已清除
