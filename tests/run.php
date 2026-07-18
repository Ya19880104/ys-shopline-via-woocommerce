<?php
/**
 * SHOPLINE 契約測試 runner（standalone，無 PHPUnit、無 WordPress）。
 *
 * 固定命令（於外掛根目錄）：
 *   php tests/run.php
 *
 * exit code：0＝全通過；1＝有失敗。CI／release gate 以此判定。
 *
 * @package YangSheep\ShoplinePayment\Tests
 */

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/YSTradeStatusContractTest.php';
require __DIR__ . '/YSApiErrorContractTest.php';
require __DIR__ . '/YSGatewayOutcomeContractTest.php';
require __DIR__ . '/YSSessionDTOContractTest.php';
require __DIR__ . '/YSSubscriptionRenewalContractTest.php';
require __DIR__ . '/YSStockReductionContractTest.php';
require __DIR__ . '/YSFailedRestockContractTest.php';
require __DIR__ . '/YSInstallmentSdkConfigContractTest.php';
require __DIR__ . '/YSInstallmentRoutingContractTest.php';
require __DIR__ . '/YSConfirmationPolicyContractTest.php';
require __DIR__ . '/YSPaymentConfirmationContractTest.php';
require __DIR__ . '/YSWebhookPaidHistoryContractTest.php';
require __DIR__ . '/YSOrderPayNoticeContractTest.php';
require __DIR__ . '/regression-scan.php';

ys_run_tradestatus_contract();
ys_run_api_error_contract();
ys_run_gateway_outcome_contract();
ys_run_sessiondto_contract();
ys_run_subscription_renewal_contract();
ys_run_stock_reduction_contract();
ys_run_failed_restock_contract();
ys_run_installment_sdk_contract();
ys_run_installment_routing_contract();
ys_run_confirmation_policy_contract();
ys_run_payment_confirmation_contract();
ys_run_webhook_paid_history_contract();
ys_run_order_pay_notice_contract();
ys_run_regression_scan();

YS_Assert::summary_exit();
