# YS SHOPLINE v3.6.0 Payment Confirmation Lifecycle RD

Status: candidate implemented; awaiting independent review and release approval

Baseline: v3.5.39 (`7718b28c3bf3d9b862f17a534ab59ff32c8d3128`)

## 1. Problem

WooCommerce has one order-status field, while SHOPLINE and other commerce platforms separate order progress from payment progress. The plugin currently represents an unsettled SHOPLINE payment as either `pending` or `on-hold` plus metadata. That protects against some repeat payments, but the back office cannot distinguish:

- a customer who has not completed Apple Pay, LINE Pay, or 3DS;
- a payment already authorized or processing at the acquirer;
- a create/query response whose result is unknown;
- a BNPL application still under review;
- a normal ATM order waiting for transfer.

The result is ambiguous wording, inconsistent retry permissions, and duplicated transition logic across checkout, redirect, webhook, and hourly sync paths.

## 2. External Behavior Review

### 2.1 SHOPLINE native platform

- SHOPLINE separates order status, payment status, and delivery status.
- SHOPLINE native online orders are created as unpaid and reserve stock before payment is final.
- Definite payment failure is reflected as failed and the native platform cancels the order and restores stock.
- Incomplete customer action is treated as an expired payment flow, not as an authorization failure.
- SHOPLINE Payments credit-card status updates normally take 10 minutes to 1 hour and can take up to 6 hours.
- SHOPLINE POS instructs merchants to wait 150 seconds if no card was presented, but up to 3 hours if the card was already presented.
- SHOPLINE's own billing UI uses `付款處理中`, removes the payment action, and locks state changes to avoid duplicate charging.

Primary references:

- https://support.shoplineapp.com/hc/zh-tw/articles/115005489043
- https://support.shoplineapp.com/hc/zh-tw/articles/24626094173209
- https://support.shoplineapp.com/hc/zh-tw/articles/57465143948057
- https://docs.shoplinepayments.com/api/trade/create/
- https://docs.shoplinepayments.com/api/trade/query/
- https://docs.shoplinepayments.com/guide/quick/

### 2.2 Other mainstream commerce platforms

- CYBERBIZ keeps failed or unpaid orders repayable through a payment link instead of requiring a new order.
- EasyStore keeps unpaid orders in a payable state and can auto-cancel them after a merchant-configured period; cancellation and stock restoration are separate configured actions.
- Shopify distinguishes `pending` and `authorized`, reserves inventory for both, blocks treating either as paid, and only runs paid workflows after capture.

References:

- https://help.cyberbiz.io/ec/orders/order-settings/provide-payment-link/
- https://support.easystore.co/zh-tw/article/44cq5yqf6io96kit5a6a44cr6zec5pa857wq5biz6kit5a6a-puvdde/
- https://help.shopify.com/zh-TW/manual/fulfillment/managing-orders/order-status

### 2.3 Design conclusion

The plugin should expose a distinct payment-confirmation state and lock repayment while money may be in flight. A timer may trigger another query or escalation, but elapsed time alone must never prove failure. Repayment is permitted only after an exact terminal response or a confirmed cancellation.

## 3. State Model

### 3.1 WooCommerce status

- Post status: `wc-ys-confirming`
- WooCommerce key: `ys-confirming`
- Merchant label: `付款確認中`
- Customer wording: `付款確認中`

This status behaves like an unpaid fulfillment hold:

- it is not a paid status;
- it does not trigger fulfillment or paid-order email hooks;
- it is not valid for `order-pay`;
- it keeps already reduced stock reserved;
- it remains queryable in HPOS and legacy order storage.

### 3.2 Internal reasons

The customer sees neutral wording. The administrator sees the reason:

- `authorized`: SHOPLINE returned `AUTHORIZED` or an authorized sub-status;
- `in_flight`: SHOPLINE returned `PROCESSING` or `PENDING`;
- `indeterminate`: create/query transport result or response envelope is not definitive;
- `bnpl_review`: Chailease application remains under review.

### 3.3 Status families

| SHOPLINE result | Family | WooCommerce action |
| --- | --- | --- |
| `SUCCEEDED`, `SUCCESS`, `CAPTURED`, partial-refund paid states | paid | `payment_complete()` and clear confirmation data |
| `AUTHORIZED` | authorized | enter `ys-confirming` |
| `PROCESSING`, `PENDING` | in flight | enter `ys-confirming` |
| transport error, missing trade ID, malformed/unknown response | indeterminate | enter `ys-confirming`, retain exact envelope |
| `CREATED`, `CUSTOMER_ACTION` | customer pending | remain `pending`; existing cancel/requery/abandon flow remains authoritative |
| `FAILED`, `EXPIRED`, `CANCELLED`, `CANCELED` | terminal | immediate browser failure stays `failed`; asynchronous terminal from the active confirming attempt returns to `pending` |
| unknown future status | indeterminate | fail closed in `ys-confirming` |

ATM with a valid virtual account is not a confirmation case. It continues to use its configured pending/on-hold status and due date.

Scheduled subscription renewals keep the v3.5.36 fail-closed flow and do not enter the customer-facing confirmation lifecycle in v3.6.0.

## 4. Attempt Identity and Metadata

All asynchronous actions are scoped to the active `referenceOrderId`.

New order metadata:

- `_ys_shopline_confirmation_data`: active attempt envelope;
- `_ys_shopline_confirmation_history`: append-only transition records;
- `_ys_shopline_confirmation_mail_sent`: map keyed by reference;
- `_ys_shopline_confirmation_review`: unresolved confirmation requiring staff review.

The active envelope contains:

```text
reference
trade_order_id
session_id
gateway
shopline_method
amount
currency
reason
remote_status
started_at
stage
```

Every scheduled job must verify order ID, current status, current reference, trade/session identity, amount, currency, and payment method before mutating the order. A stale job is a no-op.

## 5. Transition Rules

### 5.1 Enter confirmation

`enter_confirmation()`:

1. refuses paid orders;
2. saves or updates the active attempt envelope;
3. appends one history record when the state or remote status changes;
4. clears the legacy authorized-pending marker after migration;
5. moves the order to `ys-confirming`;
6. schedules the next query for the same reference.

### 5.2 Success

Success from redirect, webhook, manual sync, or scheduled query:

1. verifies the active attempt;
2. runs the existing payment-completion lock;
3. calls `payment_complete()` once;
4. clears active confirmation and indeterminate markers;
5. keeps history for audit;
6. cancels no jobs explicitly because stale-job validation makes them no-ops.

### 5.3 Asynchronous terminal result

An exact terminal response for the active confirming attempt:

1. verifies the order is unpaid;
2. records the terminal result in history;
3. clears only current-attempt trade/session/next-action/confirmation markers;
4. moves the order to `pending`;
5. lets WooCommerce restore stock through its standard pending-status hook;
6. sends at most one customer notification per reference;
7. allows `order-pay` again.

The message must not claim that no debit or authorization hold exists. It says the payment was not confirmed and advises checking with the bank if a reserved amount remains.

### 5.4 Immediate terminal result

If the customer is still in the browser and the first authoritative query returns `FAILED`, preserve the current behavior: show the error, set `failed`, and redirect to `order-pay`. Do not send the asynchronous confirmation email.

### 5.5 Elapsed time

Elapsed time is never a terminal signal.

- Credit cards and wallets: query at approximately 2m, 5m, 15m, 30m, 1h, 3h, and 6h.
- BNPL: query at approximately 5m, 15m, 1h, 6h, and 24h.
- At the final stage, keep `ys-confirming`, set the review marker, add an admin note, send one reference-scoped high-priority store-admin email, and stop automatic polling.

The schedule uses Action Scheduler when available and falls back to WP-Cron.

## 6. User Experience

### 6.1 Confirmation notice

Title: `付款確認中`

Body:

> 已收到您的付款請求，正在等待金流服務確認結果。為避免重複扣款，此訂單目前無法再次付款。確認完成後訂單會自動更新，請勿重複下單。

### 6.2 Returned-to-pending notice and email

Title: `付款確認未完成`

Body:

> 金流服務已回覆本次付款未完成，訂單已恢復為等待付款，您可以重新選擇付款方式。若銀行端仍顯示保留款，請先向發卡銀行確認後再付款。

The same notice appears on thank-you, My Account order detail, and the WooCommerce `order-pay` form. On `order-pay`, the notice contains no action buttons because the native form is the single payment command.

### 6.3 Administrator view

Order notes and confirmation metadata include reason, remote status, reference, trade ID, scheduler stage, and source. The final review stage also sends one idempotent store-admin email per reference; it never emails the customer because no terminal result exists. The customer-facing notice never exposes protocol details.

## 7. Compatibility

- Legacy `on-hold + _ys_shopline_payment_authorized_pending=yes` orders remain blocked from repayment and are migrated to `ys-confirming` when next synchronized. There is intentionally no bulk migration: a stale legacy flag is insufficient evidence to lock every historical order without a fresh synchronization event.
- An administrator changing a confirming order to `cancelled` does not clear the confirmation review marker. Manual cancellation is not proof of the SHOPLINE transaction result; the marker remains until exact convergence or a future outcome-specific administrative resolution workflow.
- The 24-hour BNPL boundary stops automatic polling and escalates to review; it never marks the payment failed or unlocks repayment. Runtime duration should be reviewed against real merchant data before making it configurable.
- Existing `resolve_prior_trade()` behavior for Apple Pay/LINE Pay customer cancellation remains unchanged.
- Existing indeterminate exact-reference webhook guards remain authoritative.
- Confirmation convergence is attempt-scoped, not merely order-scoped. A delayed webhook for `X_1` must never unlock active attempt `X_2`, even when both belong to the same WooCommerce order. The stale event remains a no-op and is handled by its own paid/abandoned-history path, scheduled reconciliation, or manual review; safety takes precedence over cross-generation convenience.
- Reopening `pending` after an exact `CUSTOMER_ACTION` does not bypass `resolve_prior_trade()`. If the adopted trade cannot be queried, the resolver keeps the trade and fails closed without creating a new reference.
- Existing abandoned-trade paid guard remains authoritative.
- ATM account reuse remains unchanged.
- Subscription recurring charge behavior remains byte-for-byte unchanged unless shared confirmation metadata cleanup is required.

## 8. Acceptance Criteria

1. `AUTHORIZED`, `PROCESSING`, `PENDING`, and create/query unknown enter `ys-confirming`.
2. `CREATED` and `CUSTOMER_ACTION` do not enter `ys-confirming`.
3. Confirming orders cannot be repaid, fulfilled, or treated as paid.
4. Paid webhook/query completes the order exactly once.
5. Exact terminal webhook/query returns an unpaid confirming order to `pending`, releases stock, and sends one email per reference.
6. A stale attempt, mismatched amount/currency/method, malformed payload, or unknown status never unlocks repayment.
7. Reaching the last polling stage creates a review flag but does not unlock repayment.
8. ATM, Apple Pay close-and-retry, installment saved/new/new-save, and subscription renewal regressions remain green.
9. HPOS and legacy order storage paths use WooCommerce CRUD only.
10. The release package contains production files and excludes tests and internal RD material.

## 9. Release Boundary

Implementation and dev-checkout validation produce a v3.6.0 candidate. Commit, tag, GitHub Release, and customer-site deployment occur only after independent Claude review.
