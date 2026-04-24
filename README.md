# YS Shopline via WooCommerce

整合 Shopline Payments 金流至 WooCommerce 的外掛，支援 HPOS 和 WooCommerce Subscriptions。

## 版本資訊

- **目前版本**：3.5.4
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

### 3.5.4 - 2026-04-24

**F9 延伸：3DS 內嵌頁 raw log 清理 + API Requester log 遮罩強化**

v3.5.2 F9 只清到 `placeOrder.pay()` / Pay-for-order / AddPaymentMethod；第二輪瀏覽器實測 devtools 又抓到兩處殘留：

#### 🔒 3DS 內嵌頁（主 plugin inline JS）
- `console.log('3DS Page Loaded', { nextAction: nextAction, ... })` 完整印 `nextAction`（含 customerToken/payToken）
- `SDK initialized: <result object>` 印 SDK 內部結構
- `pay() result: <payResult object>` 印 payResult 原文

三處改為 meta-only log（`nextActionType` / `nextActionKeys` / `hasError`），與主結帳頁 F2/F9 策略一致。

#### 🔒 `processNextAction` 殘留 `nextAction: nextAction` 行
checkout.js L922 仍保留完整輸出 nextAction，移除。

#### 🛡️ `YSShoplineRequester::redact_sensitive_data()` 新增兩組遮罩 bucket
| Bucket | 欄位 | 遮罩方式 |
|--------|------|---------|
| `sensitive_last6` | `instrumentId` / `paymentInstrumentId` / `customerId` / `paymentCustomerId` / `referenceCustomerId` / `tradeOrderId` / `channelDealId` | `*{末6碼}`（長度 ≤ 6 改 `***`） |
| URL | `url` / `returnUrl` | `[URL_REDACTED:{sha256前8}]`（避免 returnUrl 帶的 cart session 外洩） |

原本 `sensitive_full` 只遮 `apiKey` / `signKey` / `cvv` / `cardNumber`，這組識別碼會被完整寫進 API request/response log；生產 debug.log 被支援人員截圖時會曝露顧客 ID 對應 / 交易 ID，雖非 PCI 敏感，但仍屬可追蹤 PII。

#### 🧪 實機驗證
- 對方 agent：訂閱 #1256 續扣 `processing` + Recurring API `SUCCEEDED`
- 1251–1255 共 5 筆（含 2 訂閱 + 3 信用卡 + 1 沙盒刻意 decline 測試卡）全部流程正常
- `php -l` 全 34 檔、`node --check` checkout.js + blocks.js 通過

### 3.5.2 - 2026-04-24

**Codex review 第二輪 8 項 findings 修完**

#### 🔒 F1 runId/domVersion guard（state machine 補強）

雖然 v3.5.0 state machine 測試通過，但 `updated_checkout` 在 `ShoplinePayments()` in-flight 時可能讓舊 async result 被接受為 current instance。

v3.5.2 新增：
- `domVersion` 計數器：`onUpdatedCheckout` 每次 +1
- `activeRuns[gatewayId] = { runId, domVersion, el: containerEl }`
- `isRunValid()`：驗證 runId 還是當前 active、domVersion 沒變、container 還在 document

守衛點：`fetchSdkConfig` 前後、`ShoplinePayments()` 前後、`paymentInstances` 寫入前。

#### 🔒 F2 移除 raw paySession console log

`placeOrder` 裡 `console.log('createPayment result:', result)` / `paySession value:` 會在瀏覽器 devtools / support screenshot 暴露付款 session material。v3.5.2 改只 log keys / length / type，移除所有 raw 值。

#### 🚫 F3 停用 `YSBlocksSupport::init()`

v3.5.1 只移除 compatibility 宣告，但仍呼叫 Blocks init 註冊 `canMakePayment=false` 的空殼。v3.5.2 註解掉 init 呼叫與 use import，類別檔保留供未來啟用。

#### 🚫 F4 Redirect fallback 不建 placeholder token

原先 card normalizer 失敗時用 `visa/0000/當前年` 建 token 避免 fatal，但會在 My Account 留誤導性 saved card。v3.5.2 改為 **skip token creation**，`return` 直接跳出；交易 meta 仍正常寫入不影響付款。

#### 🛡️ F5 / F6 Webhook / AddPaymentMethod is_scalar guard

原先對 `card_info['last']` / `['brand']` 直接 `(string)` cast，若 SHOPLINE 傳 array-valued 欄位會觸發 `Array to string conversion` warning，strict handler 可能升級成 exception。v3.5.2 改用 `is_scalar()` guard，非 scalar 記 `(non-scalar)`。

#### 📝 F7 Logger message 內插 ID 改走 context

`YSLogger::log()` 只遮罩 context array，直接內插進 message 的 ID 會繞過集中遮罩。v3.5.2 修正 Webhook 兩處內插訊息改走 context（`trade_order_id` / `order_id` / `subscription_id`）。

#### 💀 F8 刪除死碼 `handle_pay_redirect` / `render_pay_page`

主 plugin 從 v3.4.7 起在 priority 5 處理 `ys_shopline_pay` query 並 exit，gateway base 的 handle_pay_redirect 已走不到。該死碼包含寫死 `amount: 0`（違反 v3.4.11 10100 規則）+ raw result console log，保留會有未來復活舊 bug 風險。v3.5.2 整段移除（約 130 行）。

#### 🔒 F9 Pay-for-order / AddPaymentMethod / pay() 殘留 raw console log 清理

Review 第二輪再補抓到主流程之外還有殘留：
- `placeOrder.pay()` 返回 raw payResult（含 SDK 內部 token）
- Pay-for-order `createPayment result` / WC response / AJAX response 都 log 完整物件
- AddPaymentMethod SDK result / AJAX response / pay result 全是 raw
- WC AJAX error 路徑記 `xhr.responseText`

v3.5.2 全部改為 meta-only log（keys / length / hasError / type / status code），不再洩漏物件原文。

### 3.5.1 - 2026-04-24

**訂單 audit trail + Card normalizer 共用 + Logger 集中遮罩 + 移除 Blocks 宣告**

#### 🔎 訂單 audit trail（每筆訂單都有金流狀態記錄）

先前情境：訂單建立後沒有任何 order note，管理員無法追查發生什麼事。v3.5.1 補齊：

| 時機 | 新增 note |
|------|------|
| WC 建單成功 | `Shopline 金流：訂單已由 WooCommerce 建立（gateway=X，金額=NT$Y）` |
| WCS 訂閱建立 | `Shopline 金流：訂閱已建立（gateway=X），等待首次扣款` |
| `process_payment` 進入 | `Shopline 金流：開始處理付款（gateway=X）` |
| 重複提交（已付款）| `偵測到重複提交，訂單已處於狀態 {status}，直接導向感謝頁` |
| 既有 tradeOrderId 查詢 | `偵測到既存交易（X），查詢前次狀態中...` / `前次交易已終態，允許重新建立交易` |
| `paySession` 遺失 | `付款失敗 — paySession 資料遺失（前端 SDK 未正確回傳）` |
| `paySession` JSON 解析失敗 | `付款失敗 — paySession 格式錯誤（{error}）` |
| API 未設定 | `付款失敗 — gateway API 未設定（商家後台 API 金鑰缺失）` |
| `handle_next_action`（3DS/redirect）| `SHOPLINE 已受理交易（tradeOrderId=X），下一步=confirm/redirect，等待使用者完成驗證` |

新增 meta：`PAYMENT_STATUS` 記 `MISSING_PAY_SESSION` / `INVALID_PAY_SESSION` / `API_NOT_CONFIGURED` / `ERROR` 等。

#### 🧩 #4 Card payload normalizer 共用（Codex review）

新增 `YSCustomer::normalize_card_payload()` 靜態方法：
- `instrumentCard` 必須 array
- `brand` / `last` / `expireMonth` / `expireYear` 必須 is_scalar
- `expireYear` 2 位自動補 `20` 前綴（SHOPLINE 回 `30` → `2030`）
- `expireMonth` 1 位自動補 `0` 前綴
- `last4` 必須 1-4 位數字，否則回 false

套用位置：
- `YSCustomer::create_wc_token_from_instrument`
- `YSRedirectHandler::sync_payment_token`
- `YSWebhookHandler::handle_payment_instrument_created`

Redirect / Webhook 的 token->save() 也擴展到 `catch \Throwable`。

#### 🔒 #5 Logger 集中遮罩（Codex review）

`YSLogger::log()` 進入點遞迴 sanitize context：

| 欄位類型 | 遮罩方式 |
|---------|---------|
| `customerToken` / `payToken` / `apiKey` / `clientKey` / `signKey` / `cardNumber` / `cvv` / `cvc` / `pan` / `password` / `secret` | `[REDACTED]` |
| `paySession` / `pay_session_raw` / `raw_card_info` / `raw_instrument` / `instrumentCard` | `#` + SHA256 前 8 字 fingerprint |
| `instrument_id` / `payment_instrument_id` / `customer_id` / `paymentCustomerId` / `trade_order_id` / `tradeOrderId` / `channelDealId` | `*` + 末 6 碼 |

已遮罩過的值（`*` 開頭或含 `[REDACTED]`）會保留原值，不重複遮罩。

不再依賴各呼叫端自己記得套用遮罩 → 安全一致性保證。

#### 🚫 #6 移除 Blocks cart_checkout_blocks 宣告（Codex review）

原先 `declare_compatibility('cart_checkout_blocks', ...)` 但 gateway `canMakePayment()` 回 false，API contract 不一致。移除 compatible 宣告對齊實際能力。若未來完整支援 Blocks 再重新宣告。

### 3.5.0 - 2026-04-24

**結帳 JS 重寫：單一 state machine + 80ms debounce**

#### 背景

v3.4.19/20 為了修多 gateway 切換 race 累積了多層守衛（`sdkInitializing` / `sdkGeneration` / `ajaxGeneration` / `$container.data` 雙層 flag），守衛互相干擾導致 SDK mount 中途被 cancel → `ERR_CONNECTION_CLOSED` + SDK 卡在 loading 不顯示。

#### 重寫範圍

**只動** `assets/js/ys-shopline-checkout.js` 裡 `ShoplineCheckout` 的 state 管理段。完全不動：
- Token 儲存 / 同步邏輯（YSCustomer）
- Gateway `process_payment` / 各 gateway PHP
- Webhook / RedirectHandler / AddPaymentMethodHandler PHP
- Admin 設定頁
- `placeOrder` / Block 支援 / `ajax_add_payment_method` JS

#### 新設計

| 元素 | 說明 |
|------|------|
| `gatewayState[gw]` | 單一狀態：`idle` / `loading` / `mounted` |
| `requestMount(gw)` | 80ms debounce，吸收 WC 連發 events |
| `doMount(gw)` | 狀態機 + iframe-check（DOM 已活就不重 mount）|
| `unmountOthers(gw)` | 切換時清其他 gateway container |
| `fetchSdkConfig(gw)` | AJAX wrapped in Promise |

#### 移除內容

- `sdkInitializing` / `$container.data('sdk-initializing' \| 'sdk-initialized')`
- `sdkGeneration` / `ajaxGeneration` / `myGeneration` 計數器
- `clearAllInstances` / `clearConflictingInstances` 繁複函式
- `activeGateway` 追蹤變數（改以 `getSelectedGateway()` 即時查詢）

程式行數 1767 → 1690（-77 行）。

#### 相容性

- `initSDK()` 保留為 alias → `requestMount()`（供 PayForOrderHandler 等呼叫）
- `refreshGateway()` 改用 state machine
- `paymentInstances` 保留為 SDK instance 存儲

### 3.4.20 - 2026-04-23

**v3.4.19 續修：lock 要嚴格，clear 時不能重置 sdkInitializing**

- 問題：v3.4.19 generation guard 修了 **stale AJAX response**，但沒修 **並行 mount**
- 情境：隨機切換 10 次中約 2 次出現 8 iframes（雙重渲染）
- 根因：`clearAllInstances` / `clearConflictingInstances` 執行時會把 `sdkInitializing[gateway]=false`。若有 SDK mount 正在進行，第二個 `initSDK` 看到 false → 並行進入 mount → 兩個 `await ShoplinePayments()` 同時跑 → iframe 疊加
- 修正：
  - `clearAllInstances` / `clearConflictingInstances` **不再重置** `sdkInitializing`
  - 讓當前 mount 跑完，由 `await ShoplinePayments()` 之後的 generation guard 判斷 stale → return
  - Stale path 新增 `$container.empty()` 清掉可能已 mount 的 iframe，避免殘留

### 3.4.19 - 2026-04-23

**修多個付款方式切換後 SDK 重複渲染的 race condition**

- 問題：切換多個付款方式（或 cart update 時）偶發「同一個 gateway 裡出現兩組卡號/姓名/有效期/安全碼表單」
- 根因：
  1. initSDK AJAX response 抵達時，若期間發生 `clearAllInstances` / `clearConflictingInstances`（generation 被 bump），舊 response 仍 fall through 進入 renderPayment（原 stale guard 只在 `await ShoplinePayments` 之後檢查）
  2. clearAllInstances 未呼叫 `$container.empty()` → SDK iframe 遺留 DOM
- 修正：
  - AJAX success/error callback 先比對 `ajaxGeneration` (initSDK 時捕捉)，不符合直接丟棄
  - renderPayment 進入時再檢查一次 (double-defense)
  - `clearAllInstances` 主動 `$container.empty()` 清 SDK iframe
  - `clearConflictingInstances` 改為無條件清 DOM + state（不再要求 `paymentInstances` 已寫入）

### 3.4.18 - 2026-04-23

**v3.4.17 續修：bind-only 情境必須 `mustAccept=true` 才能真正鎖住 checkbox**

- 問題：v3.4.16/17 只設 `forceSaveCard=true` → JS 默認 `mustAccept: false` → SHOPLINE SDK 仍顯示 checkbox（可取消）
- 根因：SDK 的 `switchVisible: false` 會被 `mustAccept: false` 無效化
- 修正：把 PHP `is_add_payment_method` 分支的 `paymentInstrument + mustAccept=true` 結構擴到整個 `bind_only_mode`
  - 新增付款方式頁：`paymentInstrument + unset customerToken`
  - 訂閱變更付款方式：`paymentInstrument`（保留 customerToken 讓使用者選已綁卡）
  - $0 訂閱試用：同上

### 3.4.17 - 2026-04-23

**v3.4.16 hotfix：AJAX SDK config 需要 is_change_payment_method flag 才能觸發 bind-only**

- v3.4.16 首版只在 PHP 檢查 `$_GET['change_payment_method']`，但 SDK config 走 AJAX POST 請求不會帶 GET 參數
- 結果訂閱 `$order->get_total()` 返回週期金額 101（非實際要扣金額）→ 不進 bind-only → `forceSaveCard` 保持 false
- 修正：JS 偵測 URL 有 `change_payment_method` 時，AJAX body 帶 `is_change_payment_method=1`；PHP 對應檢查 `$_POST['is_change_payment_method']`
- 實機驗證（訂閱 #1221）：SDK Config log `bind_only_mode:"yes", force_save_card:"yes"` ✓；checkbox 文字灰階化不可取消 ✓

### 3.4.16 - 2026-04-23

**訂閱變更付款方式強制儲存新卡（與結帳訂閱行為對齊）**

- 問題：v3.4.15 變更付款方式表單的「我同意記錄本次付款資訊」checkbox 可不勾選 → 新卡不被 tokenize → 續扣取不到 instrument_id
- 與 `YSCreditSubscription::payment_fields()` 的 `data-force-save="true"` 首次結帳行為不一致
- 修正：`YSGatewayBase::get_sdk_config` 的 bind-only 情境（訂閱變更付款方式 / 新增付款方式 / $0 訂閱試用）一律設 `forceSaveCard=true`
- JS 端原本就已支援 `serverConfig.forceSaveCard`（`switchVisible=!forceSave` 隱藏 switch、`defaultSwitchStatus=forceSave` 預設勾選）
- 結果：bind-only 情境下使用新卡必定儲存，不給使用者選擇不存的機會

### 3.4.15 - 2026-04-23

**訂閱續扣 bound-then-default retry + 變更付款方式可行 + log 敏感欄位遮罩**

#### 🔄 續扣重試邏輯（P0）

改寫 `YSCreditSubscription::process_subscription_payment`：
1. **綁定卡優先**：從 subscription meta 讀 `_ys_shopline_payment_instrument_id` 扣款
2. **綁定卡失敗**：若使用者 WC default token 不同卡，改用預設卡重試（WP_Error 或 FAILED 都算失敗）
3. **meta 空**：直接用使用者預設卡
4. **每次嘗試都寫訂單備註**供管理員稽核（嘗試哪張卡 / 失敗原因 / fallback 結果 / 最終狀態）
5. **retry 用獨立冪等鍵**（`reference_order_id-{instrument末6碼}`），避免被 SHOPLINE 冪等擋下

舊函式 `get_subscription_instrument_id` 拆為：
- `get_bound_subscription_instrument_id( $order )` — 只讀 subscription meta
- `get_user_default_instrument_id( $user_id )` — 只讀使用者預設 token

新增 helper：
- `try_recurring_charge( $order, $amount, $customer_id, $instrument_id )` — 單次扣款
- `is_charge_success( $response )` — 判斷是否算成功（SUCCEEDED/CAPTURED/CREATED/AUTHORIZED）

#### 🔑 訂閱變更付款方式（P0）

`YSCreditCard::supports` 加入：
- `subscription_payment_method_change`
- `subscription_payment_method_change_customer`
- `subscription_payment_method_change_admin`

新增 hook `woocommerce_subscription_payment_method_updated_to_ys_shopline_credit`，在付款方式被改為 SHOPLINE 信用卡時：
- 從 parent order meta 抓 instrument_id（或 user default token 補位）
- 回寫到 subscription meta
- 加 subscription note：`訂閱付款方式已從 {old} 改為 SHOPLINE 信用卡（末 6 碼 X），續扣將使用此卡`

`YSGatewayBase::get_sdk_config` 的 SDK 初始化邏輯修正：
- AJAX 與 pay-for-order 直渲染兩條分支：偵測 `$order->get_total() <= 0` 時自動切 bind-only 模式
- 修正訂閱 change_payment_method 情境下 SDK 初始化報 `amount is required (1004)` 的 bug
- 解決原本「該訂閱的付款方式無法變更」錯誤訊息

#### 🧪 實機驗證（[REDACTED_TEST_SITE]）

- 訂閱 #1230（active、`ys_shopline_credit_subscription`）→「變更付款」按鈕進入 pay-for-order 表單
- SDK 成功載入（amount=10100 CardBind 綁卡模式）
- 選擇已綁 JCB 0200 → 送出 → 訂閱 meta `_ys_shopline_payment_instrument_id` 實際更新（`*515066` → `*797062`）
- 下次續扣會用新卡執行

#### 🔒 Log 敏感欄位遮罩（P1）

- `YSGatewayBase` 兩處（L691 invalid JSON log、L703 PaySession received debug log）：
  - 移除 `raw_value` / `preview`（原記錄 paySession 前 100 字元明文）
  - 改記 `hash`（SHA256 前 8 字符，足以除錯不同 paySession，但不洩漏內容）
- `YSWebhookHandler::handle_customer_instrument_unbinded` L618：
  - 原 `YSLogger::info("Webhook: 刪除 Payment Token: {$payment_instrument_id}")` 明文 instrument_id
  - 改為結構化陣列 + 只留末 6 碼

### 3.4.14 - 2026-04-23

**失敗時導向 pay-for-order 訂單內頁，不再停在結帳頁**

- **背景**：WC 核心在 `process_payment` 之前就建立訂單，gateway 層無法阻止訂單產生
- **v3.4.13 的問題**：訂單產生了，畫面卻停在結帳頁 → UX 語意矛盾（使用者看到錯誤但訂單已在後台）
- **v3.4.14 改動**：`process_payment` 的 WP_Error 分支與未預期狀態分支改回傳 `{result:'success', redirect: pay-for-order URL}`
  - 使用者自動被導向訂單內頁
  - 訂單內頁顯示訂單明細、錯誤訊息（wc_add_notice 跨頁保留）、付款方式選擇、重試按鈕
  - 與 `YSRedirectHandler` 的 3DS FAILED 分支 UX 完全一致（同樣走 `get_checkout_payment_url()`）
- **仍然保留的行為**：訂單 meta（`PAYMENT_STATUS=ERROR` / `ERROR_CODE` / `ERROR_MESSAGE`）+ 訂單備註供管理員除錯

### 3.4.13 - 2026-04-23

**Revert v3.4.12 的 `$order->delete(true)`，回歸 WC 原生 UX**

- 檢討後確認：`process_payment` 的「WP_Error 分支」與「未預期狀態分支」本質上是**系統/協定層錯誤**（API 連線、認證、參數、未知回應），不是「付款失敗」
- 真正的付款失敗（卡片被拒、3DS 失敗）走 `YSRedirectHandler::process_payment_response` FAILED 分支 + webhook，那條路徑不刪訂單、導向 pay-for-order 供重試
- v3.4.12 直接 delete 過於積極，會讓管理員失去除錯軌跡、也與 WC 原生 UX 脫節
- v3.4.13 改為：訂單維持 pending 狀態 + 加訂單備註 + 錯誤 meta（`ERROR_CODE` / `ERROR_MESSAGE`）
- `result: failure` 讓使用者停在結帳頁，WC 的 `order_awaiting_payment` session 機制會在重試時沿用同一張訂單（購物車未變的前提下），**不會新建失敗訂單堆積**
- 與 Stripe / PayPal / 其他主流 gateway 的失敗處理模式對齊

### 3.4.12 - 2026-04-23

**簡化日誌系統 + 失敗訂單自動刪除 + 綁卡失敗友善訊息**

- **移除 `YSBindCardLogger` 獨立日誌類別**：整合至主 `YSLogger`（debug.log），減少維運負擔
  - 刪除 `src/Utils/YSBindCardLogger.php`
  - 移除設定頁「綁卡紀錄」tab、啟用開關、下載功能與相關清理 cron
  - `YSAddPaymentMethodHandler` / `YSGatewayBase` / `YSWebhookHandler` 原 `BindCardLogger::log` 呼叫點全數改用 `YSLogger::debug/info/error`
  - 停用 hook 保留對舊版 `ys_shopline_bindcard_log_cleanup` 排程的清除，確保升級乾淨
- **綁卡失敗顯示友善錯誤**：`add-payment-method` 流程的 `FAILED` 狀態訊息改走 `YSRedirectHandler::humanize_error_message()`，與結帳失敗訊息對齊
- **錯誤付款不產生訂單殘留**：`YSGatewayBase::process_payment` 兩處明確失敗分支（`create_payment_trade` WP_Error、未預期狀態）直接 `$order->delete(true)` 刪除訂單
  - 保留 3DS 失敗 → pay-for-order 的重試流程（只刪 SHOPLINE 從未受理的訂單）
  - 配合既有 `check_prior_trade_status` 的終態白名單，不會誤刪重試中的訂單

### 3.4.11 - 2026-04-23

**CardBind 佔位金額 10000 → 10100（沙盒測試相容）**

- 沙盒金額規則：**非 3D 交易金額去掉末兩位後必須為奇數才會成功**（10000 → 100 偶數會 decline），造成 $0 訂閱試用綁卡被 `The payment was declined after being flagged by the issuing bank.` 拒絕
- 全部 CardBind placeholder 金額統一改為 `10100`（TWD $101，奇數通過沙盒規則）
- 影響：`YSGatewayBase`、`YSCreditSubscription`、`ys-shopline-checkout.js` 共 5 處
- 生產環境不受影響（銀行授權驗證不區分奇偶，CardBind 仍只授權不請款）

### 3.4.10 - 2026-04-23

**Review 後修正（v3.4.9 diff review 抓到 4 個 blocker + 補強）**

- **B1 `wp_send_json_error` 後加 `return`**：`ajax_add_payment_method` 的三個錯誤分支補 return，避免 `wp_die_handler` 被 filter 改寫時繼續執行
- **B2 `redact_sensitive` 欄位擴充**：`firstName/lastName/name/holder` + `identityNumber/idNumber/taxId/nationalId` + `mobile` + `cvc/pan` + `paySession` 全列入
- **B3 redact 固定長度**：改為 `xx******yy`（固定 6 星號）+ 短於 8 字全星，不再依長度動態計算避免長度 fingerprint
- **B4 續扣 fallback 改 read-only**：`get_subscription_instrument_id` 的 WC default token fallback 不再回寫 subscription meta（避免「default 卡與訂閱綁的卡不同」時被錯卡續扣），僅 log warning 供人工稽核
- **S1 其他 log 點套 redact**：`YSWebhookHandler::process_webhook` 的 `customer.instrument.updated`、`YSGatewayBase::prepare_payment_data` 的 `Full payment data structure` 都接上 `redact_sensitive`
- **S2 unbind/token log 全遮罩**：`YSCustomer` 裡剩餘 4 處 `instrument_id` log 改為末 6 碼

### 3.4.9 - 2026-04-23

**安全強化 + 死碼清理 + 架構補強（v3.4.8 全面 review 後修正）**

**🔒 安全**
- API log 遮罩 PII 與 Token 欄位（`email/phone/phoneNumber/street` 部分遮罩；`apiKey/signKey/clientKey/customerToken/payToken/cvv/cardNumber` 全遮罩）
- `YSShoplineRequester::redact_sensitive()` 遞迴處理 request/response 的 log 內容，降低 wc-logs 外洩風險
- `prepare_payment_data` IDOR 防護：驗證 `ys_shopline_payment_instrument_id` 屬於當前訂單使用者，否則降級為 CardBindPayment/Regular
- 訪客訂單拒絕攜帶 `ys_shopline_payment_instrument_id`（訪客沒 WC Token）
- `ajax_add_payment_method` 加入 `$this->enabled === 'yes'` 檢查防止閘道停用時被濫用
- `reference_order_id` 改用 `bin2hex(random_bytes(4))`（原 `wp_rand(10,99)` 僅 90 種碰撞空間）
- `YSCustomer::unbind_payment_instrument` 與 payment-methods page 的 unbind log 遮罩 instrument_id（只留末 6 碼）

**🔧 續扣可靠性**
- `YSCreditSubscription::get_subscription_instrument_id()` 新增 fallback：subscription meta 為空時從使用者的 WC Payment Tokens 取 `default` 卡（限 `ys_shopline_credit` gateway_id），避免 webhook + redirect handler 都沒寫到時永久續扣失敗；取到後自動回寫 meta

**🔍 可觀測性**
- JS 加 `createPayment()` 結果 key log + `paymentInstrumentId` 尾碼（若有），驗證 SHOPLINE SDK 是否真會在用戶選「已綁卡」時回傳此欄位（這個回傳是 QuickPayment 路徑的前提）

**🧹 死碼清理**
- 移除 `YSAddPaymentMethodHandler::create_token_from_response()` 與 `fetch_instrument_info()`（約 108 行），這兩個 private 方法在 v3.4.7 後已改用 `YSCustomer::sync_tokens_from_api()` 取代

### 3.4.8 - 2026-04-23

**Revert — 回到 v3.3.3 單一 UI 路線**

v3.4.7 疊加 WC 原生 `saved_payment_methods()` 與 SDK 內建 UI，造成兩套卡片選擇介面（WC radio + SDK tab）重複且視覺跑版。回到 v3.3.3 的正確路線：**只用 SDK 內建的已綁卡選擇 UI**。

- 移除 `YSCreditSubscription::payment_fields()` 的 `saved_payment_methods()` + `tokenization_script()` 呼叫
- 移除 `YSCreditCard::payment_fields()` 的同樣呼叫
- `YSCreditSubscription::get_sdk_config()` 回到「有 `customerToken` 就 `bindCard.enable=true`」，不 unset token
- 移除 `YSGatewayBase::prepare_payment_data()` 的 `wc-{id}-payment-token` 讀取（保留 SDK 原生 `ys_shopline_payment_instrument_id` 路徑）
- 移除前端 JS 的 `onTokenChange` / `isUsingSavedToken` / `applyTokenUiState` 與 `placeOrder` 的已綁卡分支
- 移除 `#payment ul.woocommerce-SavedPaymentMethods` 相關 CSS
- 移除 `save_subscription_meta_from_order` 從 WC token 讀取 instrument_id 的新增邏輯（續扣 meta 寫入仍由 `YSRedirectHandler::update_subscription_instrument` 與 Webhook 兩路負責，v3.3.3 架構）

保留 v3.4.3～v3.4.6 的真正修復：AJAX 綁卡、CardBind amount=10000、`expireYear` 2→4 位、結帳 FAILED redirect 到 pay-for-order 頁、3DS SDK amount=10000。

### 3.4.7 - 2026-04-23

**重大改善 — 綁卡／已綁卡整合重構（AJAX 模式）**

本版同時處理「信用卡綁卡」與「訂閱結帳」兩條路徑的 UX 與流程一致性，解決先前 v3.4.2～v3.4.6 一連串症狀的根因：跨頁 3DS PCI session 丟失、已綁卡在訂閱頁不顯示、Token 儲存失敗。

**新增付款方式（My Account）— AJAX 重構**
- 完全重寫綁卡流程為 AJAX 模式：前端 SDK 實例保持活著，不跳獨立 3DS 頁
- 新增 AJAX endpoint `wp_ajax_ys_shopline_add_payment_method`
- 後端 `YSGatewayBase::do_add_payment_method_request()` 回 JSON `{nextAction, returnUrl}`
- 前端取到 `nextAction` 後以**原 SDK 實例** `paymentInstance.pay(nextAction)` 完成 3DS
- SDK 自己跳 `returnUrl` → `handle_add_method_redirect()` 建立 WC Token
- 移除已廢棄的 `YSAddPaymentMethodHandler::handle_3ds_page()` 與 `render_3ds_page()`

**結帳／訂閱 — 已綁卡與新卡統一支援**
- `YSCreditSubscription::payment_fields()` 與 `YSCreditCard::payment_fields()` 加回 WC 原生 `saved_payment_methods()` radio 列表
- 已綁卡時顯示卡片清單（含預設標記）+ 「使用新付款方式」選項；無綁卡則直接顯示 SDK 新卡 UI
- 前端 JS 新增 `onTokenChange` / `isUsingSavedToken` / `applyTokenUiState`
  - 選已綁卡 → 隱藏 SDK 容器 + 不初始化 SDK
  - 選「使用新卡」 → 顯示 SDK 容器 + 按需初始化
- `placeOrder` 偵測已綁卡 → 塞 `paySession='{}'` 交由 WC 提交（後端走 QuickPayment）

**後端標準化**
- `YSGatewayBase::prepare_payment_data()` 讀 WC 標準欄位 `wc-{id}-payment-token`（取代自訂 `ys_shopline_payment_instrument_id`）
- 選 token 時自動轉為 Shopline `paymentInstrumentId` 走 `QuickPayment` paymentBehavior

**訂閱續扣 Instrument ID 寫入保障（三條路徑）**
1. `YSRedirectHandler::update_subscription_instrument`（走 returnUrl redirect 成功時）
2. `YSCreditSubscription::save_subscription_meta_from_order`（新增：QuickPayment 即時 SUCCEEDED 不走 redirect 時，從 WC Token 補寫 instrument_id 至 subscription meta）
3. `YSWebhookHandler::update_pending_subscriptions_instrument`（webhook 非同步保底）

**WC Token 儲存修正**
- `YSCustomer::create_wc_token_from_instrument()` 處理 SHOPLINE 回傳的兩位數年份 `"30"` 轉為 `"2030"`，避免 `WC_Payment_Token_CC::validate()` 失敗拋 `Invalid or missing payment token fields` Exception

**結帳付款失敗改善**
- `YSRedirectHandler` 處理 `FAILED` 狀態時，自動 `wp_safe_redirect()` 至 WC pay-for-order 頁（`/checkout/order-pay/{id}/?key=...`），並顯示 `wc_add_notice` 錯誤訊息
- 使用者不再卡在感謝頁，可直接重選付款方式重試同一訂單

### 3.4.6 - 2026-04-23

**Hotfix — 3DS 頁面 SDK amount 為 0 導致驗證中斷**
- v3.4.4 修好 redirect 到 3DS 頁後，3DS 頁面 SDK 初始化報錯 `SDK Error: amount is required`
- 根因：`YSAddPaymentMethodHandler::render_3ds_page()` 的 SDK init 寫死 `amount: 0`
- SHOPLINE SDK 要求 `amount > 0`（與結帳頁 SDK 規則一致）
- 修法：3DS 頁 SDK init 改為 `amount: 10000`（對齊官方 CardBind 範例，此處僅用於執行 3DS `nextAction`，不會觸發扣款）

### 3.4.5 - 2026-04-23

**Hotfix — v3.4.4 遺漏 JS 配套**
- v3.4.4 後端 SDK config 已移除 `customerToken`，但前端 JS（`ys-shopline-checkout.js`）仍硬性要求 `customerToken`，導致綁卡頁顯示「無法取得客戶資訊，請確認您已登入。」
- 修法：綁卡頁 SDK 初始化不再要求 `customerToken`，強制啟用 `bindCard.enable: true`；若後端帶 `customerToken` 則向後相容一併傳入
- 對齊官方 `/guide/quick/` 4.1 CardBind 範例（SDK init 無 `customerToken`）

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
