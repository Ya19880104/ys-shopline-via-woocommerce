<?php
/** Compare installment payment completion routes on dev-checkout. */
declare(strict_types=1);

use YangSheep\ShoplinePayment\DTOs\YSPaymentDTO;
use YangSheep\ShoplinePayment\Handlers\YSPaymentConfirmation;
use YangSheep\ShoplinePayment\Handlers\YSRedirectHandler;
use YangSheep\ShoplinePayment\Handlers\YSStatusManager;
use YangSheep\ShoplinePayment\Utils\YSOrderMeta;
use YangSheep\ShoplinePayment\Utils\YSSignatureVerifier;

if (!defined('WP_CLI') || !WP_CLI || 'dev-checkout.wppro.cloud' !== wp_parse_url(home_url(), PHP_URL_HOST)
    || !current_user_can('manage_woocommerce') || true !== apply_filters('pre_wp_mail', null, [])
    || 'yes' !== get_option('ys_shopline_testmode') || 'yes' !== get_option('woocommerce_custom_orders_table_enabled')) {
    throw new RuntimeException('Requires dev-checkout WP-CLI, admin, sandbox, HPOS and email suppression.');
}

$pass = 0;
$fail = 0;
$orders = [];
$completed = [];
$configured = 'wc-completed';
$remote = [];
$query_count = 0;
$blocked_http = [];
$marker = 'ys-slp-issue8-status';
$product = new WC_Product_Simple();
$check = static function (string $label, bool $ok) use (&$pass, &$fail): void {
    if ($ok) {
        ++$pass;
    } else {
        ++$fail;
    }
    WP_CLI::log(($ok ? 'PASS' : 'FAIL') . ' | ' . $label);
};
$option = static function () use (&$configured): string { return $configured; };
$http = static function ($pre, $args, string $url) use (&$remote, &$query_count, &$blocked_http) {
    if (str_contains($url, '/trade/payment/get')) {
        ++$query_count;
        return ['headers' => [], 'response' => ['code' => 200], 'body' => wp_json_encode($remote), 'cookies' => []];
    }
    $blocked_http[] = wp_parse_url($url, PHP_URL_HOST) . wp_parse_url($url, PHP_URL_PATH);
    return new WP_Error('ys_issue8_http_blocked', 'Outbound HTTP blocked by dev-checkout test.');
};
$count = static function (int $id) use (&$completed): void {
    $completed[$id] = ($completed[$id] ?? 0) + 1;
};
$custom = static function (array $statuses): array {
    return $statuses + ['wc-ys-issue8-ready' => 'Issue 8 test status'];
};
$deliver = static function (string $type, array $payload): WP_REST_Response {
    $body = wp_json_encode(['type' => $type, 'data' => $payload]);
    $timestamp = (string) (time() * 1000);
    $request = new WP_REST_Request('POST', '/ys-shopline/v1/webhook');
    $request->set_header('content-type', 'application/json');
    $request->set_header('timestamp', $timestamp);
    $request->set_header('sign', YSSignatureVerifier::generate_signature($timestamp . '.' . $body));
    $request->set_body($body);
    return rest_do_request($request);
};
$make_order = static function (string $gateway = 'ys_shopline_credit_installment') use (&$orders, $product, $marker): array {
    $order = new WC_Order();
    $order->set_created_via($marker);
    $order->set_currency('TWD');
    $order->set_billing_email('slp-issue8@example.invalid');
    $order->set_payment_method($gateway);
    $order->set_status('pending');
    $order->add_product($product, 1);
    $order->set_total(5000);
    $order->save();
    $id = $order->get_id();
    $orders[] = $id;
    $trade = 'issue8-test-' . $id;
    $reference = $id . '_1';
    $order->update_meta_data(YSOrderMeta::TRADE_ORDER_ID, $trade);
    $order->update_meta_data(YSOrderMeta::REFERENCE_ORDER_ID, $reference);
    $order->update_meta_data(YSOrderMeta::PAYMENT_ATTEMPT_DATA, [
        'reference' => $reference, 'gateway' => $gateway, 'shopline_method' => 'CreditCard',
        'amount' => 500000, 'currency' => 'TWD', 'order_amount' => 500000, 'order_currency' => 'TWD',
        'payment_behavior' => 'Regular',
    ]);
    if ('ys_shopline_credit_installment' === $gateway) {
        $order->update_meta_data(YSOrderMeta::INSTALLMENT, '6');
    }
    $order->save();
    return [$order, [
        'tradeOrderId' => $trade, 'referenceOrderId' => $reference, 'status' => 'SUCCEEDED',
        'payment' => ['paymentMethod' => 'CreditCard', 'paidAmount' => ['value' => 500000, 'currency' => 'TWD']],
    ]];
};

$before_option = get_option('ys_shopline_order_status_paid');
add_filter('pre_option_ys_shopline_order_status_paid', $option);
add_filter('pre_http_request', $http, PHP_INT_MAX, 3);
add_filter('pre_option_woocommerce_allow_tracking', '__return_false');
add_filter('wc_order_statuses', $custom);
add_action('woocommerce_payment_complete', $count);
try {
    $product->set_name('SLP issue 8 test only');
    $product->set_status('draft');
    $product->set_regular_price('5000');
    $product->set_manage_stock(true);
    $product->set_stock_quantity(100);
    $product->save();
    foreach (['succeeded webhook', 'captured webhook', 'redirect', 'status sync', 'confirmation query', 'confirmation webhook', 'recurring response'] as $route) {
        $gateway = 'recurring response' === $route ? 'ys_shopline_credit_subscription' : 'ys_shopline_credit_installment';
        [$order, $remote] = $make_order($gateway);
        $id = $order->get_id();
        $check("$route has a product that normally requires processing", $order->needs_processing());
        if (str_starts_with($route, 'confirmation')) {
            $order->set_status(YSPaymentConfirmation::STATUS_KEY);
            $order->update_meta_data(YSOrderMeta::CONFIRMATION_DATA, YSPaymentConfirmation::attempt_from_order($order, 'PROCESSING', $remote));
            $order->save();
        }

        if ('redirect' === $route) {
            (new ReflectionMethod(YSRedirectHandler::class, 'check_and_update_order'))->invoke(null, $order, $remote['tradeOrderId']);
        } elseif ('status sync' === $route) {
            (new ReflectionMethod(YSStatusManager::class, 'update_order_from_payment'))->invoke(new YSStatusManager(), $order, YSPaymentDTO::from_response($remote));
        } elseif ('confirmation query' === $route) {
            YSPaymentConfirmation::handle_query_result($order, YSPaymentDTO::from_response($remote));
        } elseif ('recurring response' === $route) {
            $recurring = WC()->payment_gateways()->payment_gateways()[$gateway];
            (new ReflectionMethod($recurring, 'handle_recurring_response'))->invoke($recurring, $order, $remote);
        } else {
            $captured = 'captured webhook' === $route;
            $remote['status'] = $captured ? 'CAPTURED' : 'SUCCEEDED';
            $response = $deliver($captured ? 'trade.captured' : 'trade.succeeded', $remote);
            $check("$route signature admitted", 200 === $response->get_status());
        }

        $fresh = new WC_Order($id);
        $check("$route honors completed (actual: {$fresh->get_status()})", 'completed' === $fresh->get_status());
        $check("$route preserves paid date and exact trade", $fresh->get_date_paid() && $remote['tradeOrderId'] === $fresh->get_transaction_id());
        $check("$route fires payment complete once", 1 === ($completed[$id] ?? 0));
        if ('ys_shopline_credit_installment' === $gateway) {
            $check("$route retains 6 installments", '6' === (string) $fresh->get_meta(YSOrderMeta::INSTALLMENT));
        }
        $stock = (int) wc_get_product($product->get_id())->get_stock_quantity();
        $check("$route reduces stock once", 100 - count($orders) === $stock);
        if ('succeeded webhook' === $route) {
            (new ReflectionMethod(YSRedirectHandler::class, 'check_and_update_order'))->invoke(null, $fresh, $remote['tradeOrderId']);
            $fresh = new WC_Order($id);
            $check("webhook then redirect honors completed (actual: {$fresh->get_status()})", 'completed' === $fresh->get_status());
        }
        $configured = 'wc-processing';
        $deliver('trade.succeeded', $remote);
        $replayed = new WC_Order($id);
        $check("$route replay keeps original status and stock", $fresh->get_status() === $replayed->get_status()
            && 1 === ($completed[$id] ?? 0) && $stock === (int) wc_get_product($product->get_id())->get_stock_quantity());
        $configured = 'wc-completed';
    }

    foreach (['' => 'processing', 'wc-processing' => 'processing', 'wc-removed-status' => 'processing', 'wc-ys-confirming' => 'processing',
        'completed' => 'completed', 'wc-ys-issue8-ready' => 'ys-issue8-ready'] as $configured => $expected) {
        [$order, $remote] = $make_order();
        $deliver('trade.succeeded', $remote);
        $fresh = new WC_Order($order->get_id());
        $check("configured '$configured' resolves to $expected (actual: {$fresh->get_status()})", $expected === $fresh->get_status() && $fresh->get_date_paid());
    }
    $configured = 'wc-completed';
    [$order, $remote] = $make_order();
    --$remote['payment']['paidAmount']['value'];
    $deliver('trade.succeeded', $remote);
    $fresh = new WC_Order($order->get_id());
    $check('mismatched amount cannot apply paid status', !$fresh->get_date_paid() && 'pending' === $fresh->get_status()
        && 'payment_evidence_mismatch' === $fresh->get_meta(YSOrderMeta::MANUAL_REVIEW_FLAG));

    [$other] = $make_order('bacs');
    $other->payment_complete('issue8-test-other');
    $check('other gateway uses WooCommerce default', 'processing' === (new WC_Order($other->get_id()))->get_status());
    $check('both redirect queries were intercepted', 2 === $query_count);
    if ($blocked_http) {
        WP_CLI::log('BLOCKED_HTTP=' . wp_json_encode(array_values(array_unique($blocked_http))));
    }
    $check('no payment creation or cancellation attempted', !array_filter($blocked_http, static function (string $url): bool {
        return str_contains($url, '/trade/');
    }));
} finally {
    remove_filter('pre_option_ys_shopline_order_status_paid', $option);
    foreach ($orders as $id) {
        $order = wc_get_order($id);
        if ($order && $marker === $order->get_created_via()) {
            $order->delete(true);
            delete_transient('wc_order_' . $id . '_needs_processing');
        }
    }
    if ($product->get_id()) {
        $product->delete(true);
    }
    remove_action('woocommerce_payment_complete', $count);
    remove_filter('wc_order_statuses', $custom);
    remove_filter('pre_http_request', $http, PHP_INT_MAX);
    remove_filter('pre_option_woocommerce_allow_tracking', '__return_false');
    $check('persisted site setting unchanged', $before_option === get_option('ys_shopline_order_status_paid'));
    WP_CLI::log('FIXTURES_DELETED=' . implode(',', $orders) . ' EMAIL_SUPPRESSED=yes REAL_HTTP_SENT=0 VERSION=' . YS_SHOPLINE_VERSION);
}
WP_CLI::log("RESULT: $pass PASS / $fail FAIL");
if ($fail) {
    WP_CLI::error('Issue 8 regression assertions failed.');
}
