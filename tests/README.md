# 契約測試（Contract tests）

鎖定 P0/P1 金流關鍵契約，防未來回歸。純 PHP standalone，**不需 PHPUnit、不需 WordPress、不需 Composer**。

## 執行（固定命令）

於外掛根目錄執行：

```sh
php tests/run.php
```

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
- **`YSGatewayBase`**（[`YSGatewayOutcomeContractTest.php`](YSGatewayOutcomeContractTest.php)）
  - 一般付款 rejected／accepted／unknown 消費契約；在途轉 `on-hold`；已收款完成入帳；缺交易 ID 寫入 indeterminate marker。
- **訂閱續扣**（[`YSSubscriptionRenewalContractTest.php`](YSSubscriptionRenewalContractTest.php)）
  - timeout 與 `1001`／`4003` unknown 不 fallback；`4450`／`4900` 明確拒絕才換卡；同卡不重試；既存交易 pre-create guard；`90011_` reference-prefix 反例；跨期 meta 排除。
- **庫存扣減**（[`YSStockReductionContractTest.php`](YSStockReductionContractTest.php)）
  - 信用卡 nextAction 與 ATM 必須使用 `wc_maybe_reduce_stock_levels()`，且不得直接呼叫 `wc_reduce_stock_levels()`，確保 WooCommerce 同步維護訂單層庫存旗標。

## 出貨排除

測試僅版控、**不隨 release 出貨**：`.gitattributes` 將 `tests/`、`.gitattributes` 與 `.gitignore` 標記為 `export-ignore`；正式包必須由 release tag 執行 `git archive`，並在上傳前檢查 zip 不含上述項目。
