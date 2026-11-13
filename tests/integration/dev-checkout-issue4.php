<?php
/**
 * Signed terminal-webhook regression with real WooCommerce/HPOS and stock hooks.
 * Run: wp --path=public_html --user=1 eval-file /tmp/dev-checkout-issue4.php
 * All outbound HTTP is intercepted. Own draft product/order are retained, not deleted.
 */
declare(strict_types=1);

use YangSheep\ShoplinePayment\Handlers\YSWebhookHandler;
use YangSheep\ShoplinePayment\Utils\YSOrderMeta;
use YangSheep\ShoplinePayment\Utils\YSSignatureVerifier;

if (!defined('WP_CLI') || !WP_CLI || 'dev-checkout.wppro.cloud' !== wp_parse_url(home_url(), PHP_URL_HOST)
    || !current_user_can('manage_woocommerce') || true !== apply_filters('pre_wp_mail', null, array())
    || 'yes' !== get_option('ys_shopline_testmode') || 'yes' !== get_option('woocommerce_custom_orders_table_enabled')) {
    throw new RuntimeException('Requires dev-checkout, administrator, sandbox, HPOS and suppressed emails.');
}

$passes = 0;
$failures = array();
$requests = array();
$financial_requests = array();
$marker = 'ys-slp-issue4-20261114';
$check = static function (string $label, bool $ok) use (&$passes, &$failures): void {
    if ($ok) {
        ++$passes;
        WP_CLI::log('PASS | ' . $label);
    } else {
        $failures[] = $label;
        WP_CLI::warning('FAIL | ' . $label);
    }
};
$block_http = static function ($preempt, array $args, string $url) use (&$requests, &$financial_requests) {
    $requests[] = array('url' => $url, 'body' => $args['body'] ?? '');
    if (str_contains((string) wp_parse_url($url, PHP_URL_PATH), '/trade/')) {
        $financial_requests[] = end($requests);
    }
    return new WP_Error('ys_issue4_http_blocked', 'Issue #4 probe blocks all outbound HTTP.');
};
add_filter('pre_http_request', $block_http, PHP_INT_MAX, 3);
add_filter('pre_option_woocommerce_allow_tracking', '__return_false');

$reuse_id = absint(getenv('YS_ISSUE4_ORDER'));
if ($reuse_id) {
    $order = wc_get_order($reuse_id);
    if (!$order || $marker !== $order->get_created_via() || 1 !== count($order->get_items())) {
        throw new RuntimeException('Refusing to reuse an unrelated order.');
    }
    $items = $order->get_items();
    $product = reset($items)->get_product();
    if (!$product || $marker !== $product->get_meta('_ys_issue4_fixture') || 'draft' !== $product->get_status()) {
        throw new RuntimeException('Refusing to reuse an unrelated product.');
    }
    wc_increase_stock_levels($order->get_id());
    $order->get_data_store()->set_stock_reduced($order->get_id(), false);
    wc_update_product_stock($product, 100, 'set');
} else {
    $product = new WC_Product_Simple();
    $product->set_name('Issue #4 isolated webhook stock fixture');
    $product->set_status('draft');
    $product->set_regular_price('100');
    $product->set_manage_stock(true);
    $product->set_stock_quantity(100);
    $product->update_meta_data('_ys_issue4_fixture', $marker);
    $product->save();
    $order = new WC_Order();
    $order->set_created_via($marker);
    $order->set_currency('TWD');
    $order->set_billing_email('issue4@example.invalid');
    $order->set_payment_method('ys_shopline_credit');
    $order->add_product($product, 1);
    $order->calculate_totals();
    $order->save();
}
$order_id = $order->get_id();
$product_id = $product->get_id();
WP_CLI::log('FIXTURE order=' . $order_id . ' draft_product=' . $product_id);
WP_CLI::log('HANDLER_SHA256=' . hash_file('sha256', (new ReflectionClass(YSWebhookHandler::class))->getFileName()));

$prepare = static function (string $gateway, string $initial = 'pending') use ($order_id): WC_Order {
    wc_maybe_increase_stock_levels($order_id);
    $order = new WC_Order($order_id);
    $order->set_status('pending');
    $order->set_payment_method($gateway);
    $order->set_date_paid(null);
    $order->set_date_completed(null);
    foreach ($order->get_meta_data() as $meta) {
        if (str_starts_with($meta->key, '_ys_shopline_')) {
            $order->delete_meta_data($meta->key);
        }
    }
    $order->update_meta_data(YSOrderMeta::TRADE_ORDER_ID, 'issue4-B-' . $order_id);
    $order->update_meta_data(YSOrderMeta::REFERENCE_ORDER_ID, $order_id . '_2');
    $order->update_meta_data(YSOrderMeta::PAYMENT_STATUS, 'CUSTOMER_ACTION');
    $order->update_meta_data(YSOrderMeta::PAYMENT_DETAIL, array('fixture' => 'B'));
    $order->save();
    if ('on-hold' === $initial) {
        $order->update_status('on-hold');
    }
    wc_maybe_reduce_stock_levels($order_id);
    return new WC_Order($order_id);
};
$snapshot = static function (WC_Order $order): array {
    $meta = array();
    foreach ($order->get_meta_data() as $item) {
        if (str_starts_with($item->key, '_ys_shopline_')) {
            $meta[$item->key] = $item->value;
        }
    }
    ksort($meta);
    return $meta;
};
$deliver = static function (string $event, string $trade, ?string $reference, bool $valid_signature = true, array $extra = array()) {
    $data = array_merge(array('tradeOrderId' => $trade, 'status' => strtoupper(substr($event, 6))), $extra);
    if (null !== $reference) { $data['referenceOrderId'] = $reference; }
    $body = wp_json_encode(array('type' => $event, 'data' => $data));
    $timestamp = (string) (time() * 1000);
    $request = new WP_REST_Request('POST', '/ys-shopline/v1/webhook');
    $request->set_body($body);
    $request->set_header('content-type', 'application/json');
    $request->set_header('timestamp', $timestamp);
    $request->set_header('sign', $valid_signature ? YSSignatureVerifier::generate_signature($timestamp . '.' . $body) : 'invalid-signature');
    return rest_do_request($request);
};
$events = array('trade.failed', 'trade.cancelled', 'trade.expired');
$gateways = array('credit', 'credit_installment', 'credit_subscription', 'atm', 'linepay', 'applepay', 'jkopay', 'bnpl');

try {
    foreach ($gateways as $gateway) {
        foreach (array('pending', 'on-hold') as $initial) {
            foreach ($events as $event) {
                $order = $prepare('ys_shopline_' . $gateway, $initial);
                $before = $snapshot($order);
                $stock = (int) wc_get_product($product_id)->get_stock_quantity();
                $request_count = count($financial_requests);
                $result = $deliver($event, 'issue4-A-' . $order_id, $order_id . '_1');
                $fresh = new WC_Order($order_id);
                $label = "$gateway / $initial / $event old attempt";
                $check($label . ': signed route returns 200', 200 === $result->get_status());
                $check($label . ': order status unchanged', $initial === $fresh->get_status());
                $check($label . ': payment metadata unchanged', $before === $snapshot($fresh));
                $check($label . ': actual inventory remains reserved', $stock === (int) wc_get_product($product_id)->get_stock_quantity() && $fresh->get_data_store()->get_stock_reduced($order_id));
                $check($label . ': zero outbound cancel/query requests', count($financial_requests) === $request_count);
            }
        }
    }
    foreach ($events as $event) {
        foreach (array($order_id . '_1', null) as $reference) {
            $order = $prepare('ys_shopline_credit');
            $before = $snapshot($order);
            $count = count($financial_requests);
            $deliver($event, 'issue4-B-' . $order_id, $reference);
            $fresh = new WC_Order($order_id);
            $label = $event . (null === $reference ? ' missing' : ' wrong') . ' reference';
            $check($label . ': exact trade alone cannot transition order', 'pending' === $fresh->get_status() && $before === $snapshot($fresh));
            $check($label . ': stock remains reserved', 99 === (int) wc_get_product($product_id)->get_stock_quantity());
            $check($label . ': no remote API side effects', $count === count($financial_requests));
        }
        foreach (array('pending', 'on-hold') as $initial) {
            $prepare('ys_shopline_credit', $initial);
            $count = count($financial_requests);
            $result = $deliver($event, 'issue4-B-' . $order_id, $order_id . '_2');
            $fresh = new WC_Order($order_id);
            $label = $event . ' / ' . $initial . ' current attempt';
            $check($label . ': signed route returns 200', 200 === $result->get_status());
            $check($label . ': expected Woo status', ('trade.cancelled' === $event ? 'cancelled' : 'failed') === $fresh->get_status());
            $check($label . ': expected payment status', strtoupper(substr($event, 6)) === $fresh->get_meta(YSOrderMeta::PAYMENT_STATUS));
            $check($label . ': inventory restored exactly once', 100 === (int) wc_get_product($product_id)->get_stock_quantity() && !$fresh->get_data_store()->get_stock_reduced($order_id));
            $check($label . ': no redundant remote cancellation', $count === count($financial_requests));
            $deliver($event, 'issue4-B-' . $order_id, $order_id . '_2');
            $check($label . ': duplicate never increases stock again', 100 === (int) wc_get_product($product_id)->get_stock_quantity());
        }
    }
    $envelope = array(
        'payment' => array('paymentMethod' => 'CreditCard'),
        'order' => array('amount' => array('value' => 10000, 'currency' => 'TWD')),
    );
    foreach (array('trade.failed', 'trade.expired') as $event) {
        $order = $prepare('ys_shopline_credit');
        $order->delete_meta_data(YSOrderMeta::TRADE_ORDER_ID);
        $order->update_meta_data(YSOrderMeta::INDETERMINATE_REF, $order_id . '_2');
        $order->update_meta_data(YSOrderMeta::INDETERMINATE_DATA, array('shopline_method' => 'CreditCard', 'amount' => 10000, 'currency' => 'TWD'));
        $order->save();
        $before = $snapshot($order);
        $deliver($event, 'issue4-A-' . $order_id, $order_id . '_1', true, $envelope);
        $fresh = new WC_Order($order_id);
        $check($event . ': old reference cannot unlock legacy attempt', 'pending' === $fresh->get_status() && $before === $snapshot($fresh));
        $result = $deliver($event, 'issue4-B-' . $order_id, $order_id . '_2', true, $envelope);
        $fresh = new WC_Order($order_id);
        $check($event . ': exact legacy event reaches signed handler', 200 === $result->get_status());
        $check($event . ': exact legacy event retains failed transition and releases marker', 'failed' === $fresh->get_status() && '' === $fresh->get_meta(YSOrderMeta::INDETERMINATE_REF));
        $check($event . ': exact legacy event restores real inventory', 100 === (int) wc_get_product($product_id)->get_stock_quantity());

        $order = $prepare('ys_shopline_credit');
        $order->update_meta_data(YSOrderMeta::INDETERMINATE_REF, $order_id . '_1');
        $order->update_meta_data(YSOrderMeta::INDETERMINATE_DATA, array('shopline_method' => 'CreditCard', 'amount' => 10000, 'currency' => 'TWD'));
        $order->save();
        $before = $snapshot($order);
        $deliver($event, 'issue4-A-' . $order_id, $order_id . '_1', true, $envelope);
        $fresh = new WC_Order($order_id);
        $check($event . ': stale legacy marker cannot alter current B', 'pending' === $fresh->get_status() && $before === $snapshot($fresh));
        $check($event . ': stale legacy marker leaves B inventory reserved', 99 === (int) wc_get_product($product_id)->get_stock_quantity());
    }
    $order = $prepare('ys_shopline_credit');
    $before = $snapshot($order);
    $invalid = $deliver('trade.cancelled', 'issue4-B-' . $order_id, $order_id . '_2', false);
    $check('invalid signature still rejected', 400 === $invalid->get_status());
    $check('invalid signature cannot mutate the order', 'pending' === (new WC_Order($order_id))->get_status() && $before === $snapshot(new WC_Order($order_id)));
} finally {
    // Leave the isolated fixtures inert and stocked; never remove existing test data.
    $order = new WC_Order($order_id);
    $order->update_meta_data(YSOrderMeta::PAYMENT_STATUS, 'CANCELLED');
    $order->update_status('cancelled', 'Issue #4 isolated probe finished; no financial API was contacted.');
    wc_maybe_increase_stock_levels($order_id);
    for ($attempt = 1; $attempt <= 5; ++$attempt) {
        wp_clear_scheduled_hook('ys_shopline_cancel_followup', array($order_id, $attempt));
    }
    $check('fixture stock restored to 100', 100 === (int) wc_get_product($product_id)->get_stock_quantity());
    remove_filter('pre_http_request', $block_http, PHP_INT_MAX);
    remove_filter('pre_option_woocommerce_allow_tracking', '__return_false');
}
WP_CLI::log('BLOCKED_HTTP=' . count($requests));
WP_CLI::log('BLOCKED_FINANCIAL_HTTP=' . count($financial_requests));
$cancel_targets = array();
foreach ($financial_requests as $request) {
    if (str_contains($request['url'], '/trade/payment/cancel')) {
        $body = json_decode((string) $request['body'], true);
        $cancel_targets[] = array('trade' => $body['tradeOrderId'] ?? '', 'reference' => $body['referenceOrderId'] ?? '');
    }
}
WP_CLI::log('CANCEL_TARGETS=' . wp_json_encode($cancel_targets));
WP_CLI::log('RESULT: ' . $passes . ' PASS / ' . count($failures) . ' FAIL');
if ($failures) { WP_CLI::error('Issue #4 acceptance failed. Fixtures retained for inspection.'); }
