# 契約測試（Contract tests）

鎖定 P0/P1 金流關鍵契約，防未來回歸。純 PHP standalone，**不需 PHPUnit、不需 WordPress、不需 Composer**。

## 執行（固定命令）

於外掛根目錄執行：

```sh
php tests/run.php                          # PHP 契約（後端）
php tests/regression-scan.php              # 付款關鍵機制靜態哨兵（亦已納入 run.php）
node tests/js/checkout-contract.test.js    # 前端契約（真實 JS 檔載入驗證）
```

dev-checkout 的真實 WooCommerce/HPOS 整合探針另以 `wp eval-file tests/integration/dev-checkout-v3.6.2.php` 與 `wp eval-file tests/integration/dev-checkout-v3.6.2-paid-ordering.php` 執行；其 SHOPLINE query/create 由 `pre_http_request` 固定回應或阻擋，不建立真交易，並在 `finally` 清除 fixture。再以環境變數傳入 fixture ID 執行對應 probe，跨 request 確認零殘留。

- 全通過 → 印出 `RESULT: N PASS / 0 FAIL`、exit code `0`
- 任一失敗 → 印出 `FAIL | ...`、exit code `1`

## 涵蓋範圍

- **`YSTradeStatus`**（[`YSTradeStatusContractTest.php`](YSTradeStatusContractTest.php)）
  - 狀態分類器 `is_terminal_safe` / `is_paid` / `is_customer_pending` / `is_in_flight`（不分大小寫）。
  - `select_representative_trade_id`：terminal-safe 忽略、已收款／顧客處理中／在途 active、多筆 active 回空、distinct tradeOrderId 去重、未知狀態與 **malformed（非陣列／缺 `tradeOrderId`／缺 `status`）一律 fail-closed 回 `''`**。
- **`YSSessionDTO`**（[`YSSessionDTOContractTest.php`](YSSessionDTOContractTest.php)）
  - `from_response` 一律經 selector 產生交易 ID；**root `tradeOrderId` 不得繞過**；root 與 selector 不一致時 selector 勝；無 `paymentDetails`／malformed → `null`；缺 `sessionId` 拋例外。
- **`YSApiError`**（[`YSApiErrorContractTest.php`](YSApiErrorContractTest.php)）
  - 建立交易回應三態分類；明確 rejected allowlist 與 unknown 相鄰碼；缺 `tradeOrderId`、未知狀態及 malformed 回應皆 fail-closed。
  - unknown 診斷只保留 request ID、HTTP status、錯誤碼／訊息、response key 名稱與 `tradeOrderId`／`nextAction` 存在性；`YSApiException → WP_Error → gateway log` 必須保留同一份安全 context，且不得記錄 token 或原始 response body。
  - `1005` 只有明確包含 `Customer not found` 才能視為 stale customer identity；其他 validation `1005` 不得觸發本地資料失效。
- **`YSGatewayBase`**（[`YSGatewayOutcomeContractTest.php`](YSGatewayOutcomeContractTest.php)）
  - 含 transport unknown 真實影片等價路徑：第一次只建立一筆交易、轉入中立確認頁；立即重送在 API 前 fail-closed，reference 不變。
  - LINE Pay／Apple Pay unknown 後收到 exact `CUSTOMER_ACTION` webhook 時，能以 reference fallback 找回尚無本地 trade ID 的訂單、立即回到 prior-trade resolver；金額不符仍維持確認鎖。
  - customer-pending 解鎖後，若 prior-trade 的重查失敗，resolver 必須保留 trade、執行 query-cancel-query 後 fail-closed、不得建立新交易，並回傳 checkout／order-pay 共用的「請稍候約 1 分鐘」中立訊息。
  - 一般付款 rejected／accepted／unknown 消費契約；在途轉 `on-hold`；已收款完成入帳；缺交易 ID 寫入 indeterminate marker。
  - Apple Pay 建立交易不可用空的純 server paySession 模擬：`referenceMerchant.StoreWebsite` 由瀏覽器 SHOPLINE SDK paySession 提供；`'{}'` 必然被 SHOPLINE 拒絕，只能用真實瀏覽器／裝置覆蓋 create 段，不得把此測試限制誤判成產品回歸。
- **訂閱續扣**（[`YSSubscriptionRenewalContractTest.php`](YSSubscriptionRenewalContractTest.php)）
  - timeout 與 `1001`／`4003` unknown 不 fallback；`4450`／`4900` 明確拒絕才換卡；同卡不重試；既存交易 pre-create guard；`90011_` reference-prefix 反例；跨期 meta 排除；續扣成功與 webhook／狀態同步共用 order-scoped completion lock，重送不得重跑付款完成 hooks。
- **庫存扣減**（[`YSStockReductionContractTest.php`](YSStockReductionContractTest.php)）
  - 信用卡 nextAction 與 ATM 必須使用 `wc_maybe_reduce_stock_levels()`，且不得直接呼叫 `wc_reduce_stock_levels()`，確保 WooCommerce 同步維護訂單層庫存旗標。
- **分期 SDK config**（[`YSInstallmentSdkConfigContractTest.php`](YSInstallmentSdkConfigContractTest.php)）
  - 會員分期 `get_sdk_config()` 啟用可選 bindCard（switch visible、default off）以相容現行 SHOPLINE SDK 的既有卡渲染；訪客不啟用、bind-only 防禦情境不留下強制綁卡設定；`installmentCounts` 行為不受影響。
- **分期後端路由**（[`YSInstallmentRoutingContractTest.php`](YSInstallmentRoutingContractTest.php)）
  - 直接執行真實 `YSGatewayBase::prepare_payment_data()`：分期 `new`／缺 mode → `Regular`、`new_save` → `CardBindPayment`＋`savePaymentInstrument`、`saved` → `QuickPayment`＋`paymentCustomerId`。另鎖定一般信用卡與訂閱路由不變。
- **前端契約**（[`js/checkout-contract.test.js`](js/checkout-contract.test.js)，Node 直跑、零依賴，vm sandbox 載入**真實** `assets/js/ys-shopline-checkout.js`）
  - `GATEWAY_CONFIG` 能力分離（`supportsSavedCards`／`supportsBindCard`）；**最終 SDK options**（`buildSdkOptions`）：分期會員啟用 optional bindCard 並保留 `customerToken`，訪客不啟用；主卡／訂閱與 bind-only amount 回歸。
  - **前端實際產生的 mode**（`getPaymentInstrumentSelection`，checkout 與 order-pay 共用）：分期既有卡→`saved`、新卡 SDK 自製開關 off→`new`／active→`new_save`；`createPayment()` 前的 selection snapshot 可跨 SDK DOM 替換保留，明確 SDK instrument ID 可覆寫 snapshot；主卡／訂閱與非卡 gateway 回歸。
  - 呼叫點契約（原始碼斷言）：order-pay 走共用 selection、`renderPayment` 走 `buildSdkOptions`、舊 `bind-card-enabled`／`isBindCardEnabled` 已移除。
- **failed 回補**（[`YSFailedRestockContractTest.php`](YSFailedRestockContractTest.php)）
  - **未實收** SHOPLINE 訂單轉入 `failed` 時必須呼叫 `wc_maybe_increase_stock_levels()`（pending／on-hold／自訂狀態起點、含 `ys_shopline_` 前綴 legacy gateway）；**已付款（`date_paid` 非空）訂單轉 failed 一律不得回補**（款在貨放＝超賣；不以 old_status 判斷）；**非 SHOPLINE 金流、空 payment method、非 failed 轉換一律不得觸發**。
- **付款確認政策**（[`YSConfirmationPolicyContractTest.php`](YSConfirmationPolicyContractTest.php)）
  - paid／customer-pending／in-flight／terminal／unknown 分類；信用卡／錢包與中租的累積查詢階段；LINE Pay 首次補查保留 120 秒；時間經過永不單獨構成 terminal。
- **付款確認生命週期**（[`YSPaymentConfirmationContractTest.php`](YSPaymentConfirmationContractTest.php)）
  - `wc-ys-confirming` 註冊與 order-pay 鎖、精確 attempt envelope、Action Scheduler、strict trade/session 查詢、paid／terminal／customer-pending 三種收斂、stale/malformed/mismatch fail-closed、MySQL convergence lock、paid-history 恢復、客戶＋管理員通知冪等、最終人工審核及每小時 safety-net。
- **晚到 webhook paid-history 防護**（[`YSWebhookPaidHistoryContractTest.php`](YSWebhookPaidHistoryContractTest.php)）
  - 訂單已有 `date_paid` 時，晚到 AUTHORIZED／PROCESSING／CUSTOMER_ACTION／FAILED／CANCELLED／EXPIRED 不得覆寫訂單狀態或已付款 meta。
- **人工／排程同步 paid-history 防護**（[`YSStatusSyncPaidHistoryContractTest.php`](YSStatusSyncPaidHistoryContractTest.php)）
  - 八種 gateway 已付款後收到晚到 `PROCESSING` 不得降級；其他非 paid 狀態不得覆寫 paid meta；webhook-first／query-first 皆只完成一次；部分退款不降 Woo 狀態，完整退款仍可進 `refunded`。
  - 活躍 LINE Pay attempt 會在 create API 前阻擋信用卡、分期、訂閱、ATM、街口、Apple Pay、LINE Pay 與中租等所有替代 gateway。ATM 雖非即時扣款，舊 LINE Pay 仍可能晚到成功，不能作為繞過確認鎖的例外。
- **order-pay 中立提示**（[`YSOrderPayNoticeContractTest.php`](YSOrderPayNoticeContractTest.php)）
  - 精確 terminal confirmation history 才在 WooCommerce 標準付款表單顯示「付款確認未完成」；一般 pending 不顯示，且不得重複輸出付款按鈕。
- **錢包回站狀態**（[`YSRedirectReturnContractTest.php`](YSRedirectReturnContractTest.php)）
  - LINE Pay 回站第一輪仍為 `CUSTOMER_ACTION` 時，真實 redirect handler 必須把 exact attempt 轉入 `ys-confirming` 並排程收斂，不得露出一般 pending 重付 UI。
- **Customer identity 邊界**（[`YSCustomerIdentityContractTest.php`](YSCustomerIdentityContractTest.php)）
  - LINE Pay／Apple Pay／街口／ATM／中租在 new/new_save/saved/缺 mode 下皆固定 `Regular` 且不帶 `paymentCustomerId`；一般信用卡相容路徑保留。
  - wallet SDK 與付款 payload 組裝都不得查詢或建立 card customer identity；SDK `sdkOptions` 與 PHP `ys_shopline_payment_data` filter 執行後仍須重新套用能力邊界。精確 stale ID 可重建一次，generic `1005` 與晚到舊 ID 不得清除目前 mapping；create 被拒只送一次，不自動重建第二筆交易。
- **管理員設定入口**（[`YSAdminMenuContractTest.php`](YSAdminMenuContractTest.php)）
  - 舊版獨立頂層 endpoint `ys_shopline_payment` 與電商工具箱子選單 `ys-shopline-payment` 必須同時註冊、共用設定 callback 與 `manage_options` 權限；Hub Client 2.0.4 必須中央註冊 `電商工具箱`，並將系統資訊／聯絡我們固定在最後。legacy hook 必須載入既有管理員資產，無關頁面不得載入。
- **Production 回歸哨兵**（[`regression-scan.php`](regression-scan.php)）
  - 鎖定 mount health、存卡能力、tri-state、prior-trade、indeterminate、庫存、分期／訂閱、strict selector、paid-history、confirmation lock、safety-net、order-pay 與通知等付款關鍵機制；亦檢查 pre-create guard 仍位於 reference 生成之前，並阻止已撤回的 v3.5.38 註解回流。

## 出貨排除

測試與內部 RD 僅版控、**不隨 release 出貨**：`.gitattributes` 將 `tests/`、`docs/`、`.gitattributes` 與 `.gitignore` 標記為 `export-ignore`；正式包必須由 release tag 執行 `git archive`，並在上傳前檢查 zip 不含上述項目。
