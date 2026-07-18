# Payment Confirmation Lifecycle Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add an attempt-aware `付款確認中` lifecycle that blocks duplicate payment while SHOPLINE is unsettled and only reopens payment after an exact terminal result.

**Architecture:** A pure confirmation policy defines status families and query stages. A WooCommerce integration service owns the custom status, attempt metadata, scheduled reconciliation, stock-safe terminal transition, and notification idempotency. Existing redirect, webhook, status-sync, and gateway paths delegate lifecycle transitions to that service; existing customer-pending, ATM, abandoned-trade, and subscription paths remain separate.

**Tech Stack:** PHP 8.0+, WordPress/WooCommerce CRUD, Action Scheduler with WP-Cron fallback, existing standalone PHP contract runner, dev-checkout WooCommerce 10.4.4 with HPOS.

---

### Task 1: Lock the pure lifecycle contract

**Files:**
- Create: `src/Utils/YSConfirmationPolicy.php`
- Create: `tests/YSConfirmationPolicyContractTest.php`
- Modify: `tests/bootstrap.php`
- Modify: `tests/run.php`

- [x] Write failing tests for status-family classification, neutral reasons, card/wallet and BNPL stage delays, and final-stage review behavior.
- [x] Run `php tests/run.php` and confirm failures are caused by the missing policy.
- [x] Implement the minimal pure policy.
- [x] Re-run the policy tests and the complete PHP suite.

### Task 2: Register the WooCommerce status and metadata contract

**Files:**
- Create: `src/Handlers/YSPaymentConfirmation.php`
- Create: `tests/YSPaymentConfirmationContractTest.php`
- Modify: `src/Utils/YSOrderMeta.php`
- Modify: `tests/bootstrap.php`
- Modify: `tests/run.php`
- Modify: `ys-shopline-via-woocommerce.php`

- [x] Write failing tests for status registration, order-status list insertion, confirmation entry, stale-attempt rejection, metadata history, and repayment lock.
- [x] Verify RED with `php tests/run.php`.
- [x] Implement status registration and active-attempt metadata through WooCommerce CRUD.
- [x] Verify GREEN and confirm existing ATM `on-hold` payment exception still excludes confirmation orders.

### Task 3: Implement attempt-aware scheduling and convergence

**Files:**
- Modify: `src/Handlers/YSPaymentConfirmation.php`
- Modify: `tests/YSPaymentConfirmationContractTest.php`
- Modify: `tests/bootstrap.php`

- [x] Write failing tests for Action Scheduler preference, WP-Cron fallback, exact attempt validation, paid convergence, exact terminal return to pending, transient requeue, malformed fail-closed, and final-stage manual review.
- [x] Verify RED.
- [x] Implement the scheduler callback with query-by-trade and strict query-session fallback.
- [x] Verify GREEN and ensure elapsed time alone never changes an order to pending or failed.

### Task 4: Integrate gateway and redirect paths

**Files:**
- Modify: `src/Gateways/YSGatewayBase.php`
- Modify: `src/Handlers/YSRedirectHandler.php`
- Modify: `tests/YSGatewayOutcomeContractTest.php`
- Modify: `tests/bootstrap.php`
- Modify: `tests/run.php`

- [x] Write failing tests for accepted in-flight, indeterminate create/query, customer-pending exclusion, and immediate browser failure.
- [x] Verify RED.
- [x] Route in-flight and indeterminate outcomes into confirmation while preserving `CREATED/CUSTOMER_ACTION` and immediate `FAILED` behavior.
- [x] Verify GREEN, including v3.5.39 installment and v3.5.36 recurring contracts.

### Task 5: Integrate webhook and hourly sync paths

**Files:**
- Modify: `src/Handlers/YSWebhookHandler.php`
- Modify: `src/Handlers/YSStatusManager.php`
- Create: `tests/YSWebhookPaidHistoryContractTest.php`
- Modify: `tests/bootstrap.php`
- Modify: `tests/run.php`

- [x] Write failing tests for authorized/processing entry, paid completion, exact terminal retry unlock, stale terminal no-op, paid/date-paid guard, and legacy on-hold migration.
- [x] Verify RED.
- [x] Delegate webhook and status-sync transitions to the confirmation service.
- [x] Include `ys-confirming` in periodic sync without changing ATM or subscription behavior.
- [x] Verify GREEN.

### Task 6: Add neutral customer/admin presentation and attempt-scoped email

**Files:**
- Modify: `src/Frontend/YSOrderDisplay.php`
- Modify: `src/Handlers/YSPaymentConfirmation.php`
- Modify: `tests/YSPaymentConfirmationContractTest.php`

- [x] Write failing tests for neutral confirmation wording, neutral returned-to-pending wording, no `未扣款` claim, and one email per reference.
- [x] Verify RED.
- [x] Implement the notice and WooCommerce-mailer-backed notification.
- [x] Verify GREEN and ensure confirming does not trigger native paid/on-hold fulfillment email behavior.

### Task 7: Version, documentation, and static verification

**Files:**
- Modify: `ys-shopline-via-woocommerce.php`
- Modify: `README.md`
- Modify: `tests/README.md`

- [x] Bump candidate version to `3.6.0` without changing the release tag.
- [x] Document the lifecycle, status matrix, scheduler, wording, and operational review state.
- [x] Run all PHP tests, Node tests, PHP lint, JS syntax checks, `git diff --check`, EOL/churn checks, and direct-call regression searches.

### Task 8: dev-checkout integration verification

**Files:**
- Create only temporary remote fixtures outside the repository; remove them in a separate request.

- [x] Deploy the candidate production files to dev-checkout and flush opcache.
- [x] Verify status registration, HPOS persistence, repayment lock, Action Scheduler execution, paid convergence, terminal-to-pending stock restoration, one-email idempotency, stale-attempt no-op, and manual-review escalation.
- [x] Re-run installment saved/new/new-save, main-credit saved, order-pay, ATM, and subscription renewal smoke paths; Apple Pay remains a declared real-device gap.
- [x] Confirm fixture cleanup in a separate request and inspect PHP/WooCommerce logs.

### Task 9: Candidate package and review handoff

**Files:**
- No production edits.

- [x] Build the candidate ZIP from the working tree using the existing package allowlist.
- [x] Verify one root directory, production version, required new files, no tests/RD/Git metadata, PHP lint, SHA-256, sensitive-data scan, and byte/LF-normalized parity with dev-checkout.
- [x] Leave commit/tag/release untouched.
- [x] Produce the Claude review report with exact commands, pass counts, order IDs, fixture cleanup proof, package SHA, and residual real-device gaps.
