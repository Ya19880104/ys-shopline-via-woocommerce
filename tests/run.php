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

ys_run_tradestatus_contract();
ys_run_api_error_contract();
ys_run_gateway_outcome_contract();
ys_run_sessiondto_contract();
ys_run_subscription_renewal_contract();

YS_Assert::summary_exit();
