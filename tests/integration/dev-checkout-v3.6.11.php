<?php
/**
 * Real WooCommerce/HPOS acceptance for PR #2. Remote payments are intercepted.
 * Run: wp --user=1 eval-file /tmp/dev-checkout-v3.6.11.php
 * YS_KEEP_FIXTURES=1 keeps only the final browser fixtures after a passing run.
 * YS_CLEANUP_IDS=id,id removes only this probe's tagged orders.
 */
declare(strict_types=1);

use YangSheep\ShoplinePayment\Frontend\YSOrderDisplay;
use YangSheep\ShoplinePayment\Handlers\YSPaymentConfirmation;
use YangSheep\ShoplinePayment\Utils\YSOrderMeta;

if (!defined('WP_CLI') || !WP_CLI || 'dev-checkout.wppro.cloud' !== wp_parse_url(home_url(), PHP_URL_HOST)) {
    throw new RuntimeException('Restricted to dev-checkout WP-CLI.');
}
if (!current_user_can('manage_woocommerce') || true !== apply_filters('pre_wp_mail', null, array())) {
    throw new RuntimeException('Administrator and active email suppression required.');
}

$marker = 'ys-slp-pr2-v3611';
$cleanup_ids = array_filter(array_map('absint', explode(',', (string) getenv('YS_CLEANUP_IDS'))));
if ($cleanup_ids) {
    foreach ($cleanup_ids as $id) {
        $order = wc_get_order($id);
        if (!$order || $marker !== $order->get_created_via()) {
            throw new RuntimeException('Refusing unrelated fixture ' . $id);
        }
        $order->delete(true);
        WP_CLI::success('Deleted fixture ' . $id);
    }
    return;
}

$passes = 0;
$failures = array();
$fixtures = array();
$kept = array();
$http_calls = 0;
$original_post = $_POST;
$original_request = $_REQUEST;
$original_user = get_current_user_id();
$completed = false;
$check = static function (string $label, bool $ok) use (&$passes, &$failures): void {
    if ($ok) {
        ++$passes;
        WP_CLI::log('PASS | ' . $label);
    } else {
        $failures[] = $label;
        WP_CLI::warning('FAIL | ' . $label);
    }
};
$block_http = static function () use (&$http_calls) {
    ++$http_calls;
    return new WP_Error('ys_pr2_http_blocked', 'No remote request is permitted in this probe.');
};
$disable_tracking = static fn() => 'no';
add_filter('pre_http_request', $block_http, PHP_INT_MAX, 3);
add_filter('pre_option_woocommerce_allow_tracking', $disable_tracking);

$make_order = static function (float $total = 5000, string $status = 'pending', int $customer = 0) use (&$fixtures, $marker): WC_Order {
    $order = new WC_Order();
    $order->set_created_via($marker);
    $order->set_currency('TWD');
    $order->set_total($total);
    $order->set_customer_id($customer);
    $order->set_payment_method('ys_shopline_credit_installment');
    $order->set_payment_method_title('SHOPLINE 信用卡分期');
    $order->set_status($status);
    $order->save();
    $fixtures[] = $order->get_id();
    return $order;
};
$render = static function (WC_Order $order): string {
    ob_start();
    YSOrderDisplay::instance()->display_payment_status_notice($order->get_id());
    return (string) ob_get_clean();
};

try {
    $check('runtime is 3.6.11', defined('YS_SHOPLINE_VERSION') && '3.6.11' === YS_SHOPLINE_VERSION);
    $check('HPOS enabled', 'yes' === get_option('woocommerce_custom_orders_table_enabled'));
    $check('sandbox enabled', 'yes' === get_option('ys_shopline_testmode'));
    if ($failures) {
        throw new RuntimeException('Runtime precondition failed.');
    }
    if (!WC()->session) {
        WC()->initialize_session();
    }
    $gateways = WC()->payment_gateways()->payment_gateways();
    $gateway = $gateways['ys_shopline_credit_installment'];
    $original_settings = $gateway->settings;
    $settings_hash = hash('sha256', wp_json_encode(get_option('woocommerce_ys_shopline_credit_installment_settings')));
    $gateway->settings['installments'] = array('3', '6');
    $gateway->settings['min_installment_amount'] = '3000';
    $api_property = new ReflectionProperty($gateway, 'api');
    $api_property->setAccessible(true);
    $original_api = $api_property->getValue($gateway);
    $interceptor = new class {
        public array $requests = array();
        public function create_payment_trade(array $data, string $idempotency) {
            $this->requests[] = $data;
            return new WP_Error('4450', 'TEST ONLY: intercepted before the financial API');
        }
    };
    $api_property->setValue($gateway, $interceptor);
    wp_set_current_user(0);

    $cases = array(
        array('enabled 3', 5000, '3', true),
        array('enabled 6 at minimum', 3000, '6', true),
        array('missing means pay-in-full', 5000, null, true),
        array('zero means pay-in-full', 5000, '0', true),
        array('disabled 24', 5000, '24', false),
        array('below minimum', 2999, '3', false),
        array('trailing text', 5000, '6abc', false),
        array('decimal', 5000, '6.5', false),
        array('negative', 5000, '-6', false),
        array('nonnumeric', 5000, 'invalid', false),
        array('empty string', 5000, '', false),
        array('empty array', 5000, array(), false),
        array('array', 5000, array('6'), false),
    );
    foreach ($cases as [$label, $total, $posted, $allowed]) {
        $order = $make_order((float) $total);
        $_POST = array('ys_shopline_pay_session' => '{"sessionId":"pr2-no-remote-payment"}', 'ys_shopline_payment_instrument_mode' => 'new');
        if (null !== $posted) {
            $_POST['ys_shopline_installment'] = $posted;
        }
        wc_clear_notices();
        $before = count($interceptor->requests);
        $result = $gateway->process_payment($order->get_id());
        $order = wc_get_order($order->get_id());
        $check($label . ': create boundary count', ($allowed ? 1 : 0) === count($interceptor->requests) - $before);
        if ($allowed) {
            $data = end($interceptor->requests);
            $expected = null !== $posted && '0' !== $posted ? $posted : null;
            $check($label . ': exact request terms', $expected === ($data['confirm']['paymentMethodOptions']['installments']['count'] ?? null));
            $check($label . ': persisted terms match', ($expected ?? '') === (string) $order->get_meta(YSOrderMeta::INSTALLMENT));
        } else {
            $check($label . ': local retryable failure', 'failure' === ($result['result'] ?? '') && 'rejected' === ($result['remote_outcome'] ?? ''));
            $notices = wc_get_notices('error');
            $check($label . ': clear customer notice', 1 === count($notices) && str_contains($notices[0]['notice'], '所選的分期期數目前無法使用'));
            $check($label . ': no payment attempt written', '' === $order->get_meta(YSOrderMeta::REFERENCE_ORDER_ID) && '' === $order->get_meta(YSOrderMeta::PAYMENT_STATUS) && 'pending' === $order->get_status());
        }
        $check($label . ': never paid', null === $order->get_date_paid() && '' === $order->get_meta(YSOrderMeta::TRADE_ORDER_ID));
    }

    $gateway->settings['installments'] = array();
    $order = $make_order();
    $_POST['ys_shopline_installment'] = '3';
    $before = count($interceptor->requests);
    $result = $gateway->process_payment($order->get_id());
    $check('empty merchant list blocks before create', 'failure' === ($result['result'] ?? '') && $before === count($interceptor->requests));
    $gateway->settings['installments'] = array('3', '6');

    foreach (array('paid', 'confirming') as $state) {
        $order = $make_order();
        if ('paid' === $state) {
            $order->set_date_paid(time());
        } else {
            $order->set_status(YSPaymentConfirmation::STATUS_KEY);
        }
        $order->save();
        $_POST['ys_shopline_installment'] = '24';
        $before = count($interceptor->requests);
        $result = $gateway->process_payment($order->get_id());
        $check($state . ': original guard remains before validation', $before === count($interceptor->requests) && 'success' === ($result['result'] ?? ''));
    }

    // Exercise the real order-pay adapter, including its nonce and guest key.
    if (!defined('DOING_AJAX')) { define('DOING_AJAX', true); }
    $json_exit = new RuntimeException('pr2-json-exit');
    $die_handler = static fn() => static function () use ($json_exit): void { throw $json_exit; };
    add_filter('wp_die_ajax_handler', $die_handler);
    try {
        foreach (array('24', '3') as $posted) {
            $order = $make_order();
            $order->set_payment_method('ys_shopline_credit');
            $order->set_payment_method_title('Original payment method');
            $order->save();
            $_POST = array(
                'order_id' => $order->get_id(),
                'order_key' => $order->get_order_key(),
                'payment_method' => $gateway->id,
                'ys_shopline_pay_session' => '{"sessionId":"pr2-no-remote-payment"}',
                'ys_shopline_payment_instrument_mode' => 'new',
                'ys_shopline_installment' => $posted,
                'nonce' => wp_create_nonce('ys_shopline_nonce'),
            );
            $_REQUEST = $_POST;
            wc_clear_notices();
            $before = count($interceptor->requests);
            ob_start();
            try {
                YSShoplinePayment::instance()->ajax_pay_for_order();
            } catch (RuntimeException $e) {
                if ($e !== $json_exit) { throw $e; }
            } finally {
                $json = (string) ob_get_clean();
            }
            $result = json_decode($json, true);
            $order = wc_get_order($order->get_id());
            $check('order-pay ' . $posted . ': create boundary count', ('3' === $posted ? 1 : 0) === count($interceptor->requests) - $before);
            $check('order-pay ' . $posted . ': messages reach JSON', 'failure' === ($result['result'] ?? '') && !empty($result['messages']));
            if ('24' === $posted) {
                $check('order-pay invalid terms expose the correct reason', str_contains($result['messages'] ?? '', '所選的分期期數目前無法使用'));
            }
            $check('order-pay ' . $posted . ': rejected attempt restores gateway and title', 'ys_shopline_credit' === $order->get_payment_method() && 'Original payment method' === $order->get_payment_method_title());
            $check('order-pay ' . $posted . ': notices drained', array() === wc_get_notices('error'));
        }
    } finally {
        remove_filter('wp_die_ajax_handler', $die_handler);
    }

    $order = $make_order(5000, 'failed');
    $private = '付款失敗 staff-only-pr2-private-do-not-publish';
    $order->add_order_note($private, false);
    $notice_queries = 0;
    $observe_notes = static function ($clauses) use (&$notice_queries) { ++$notice_queries; return $clauses; };
    add_filter('comments_clauses', $observe_notes);
    $html = $render($order);
    remove_filter('comments_clauses', $observe_notes);
    $check('private note really exists', str_contains(wp_json_encode(wc_get_order_notes(array('order_id' => $order->get_id()))), 'staff-only-pr2'));
    $check('failed notice never exposes private note', !str_contains($html, 'staff-only-pr2'));
    $check('failed notice performs no notes query', 0 === $notice_queries);
    $check('missing payment error shows generic retry', str_contains($html, '立即付款') && !str_contains($html, 'ys-shopline-error-details'));
    $kept['generic'] = $order->get_id();
    $order = $make_order(5000, 'failed');
    $order->add_order_note($private, false);
    $order->update_meta_data(YSOrderMeta::ERROR_CODE, '4450');
    $order->update_meta_data(YSOrderMeta::ERROR_MESSAGE, '發卡銀行拒絕交易，請改用其他付款方式。');
    $order->save();
    $html = $render($order);
    $check('stored payment error and retry displayed', str_contains($html, '4450') && str_contains($html, '發卡銀行拒絕交易') && str_contains($html, '立即付款'));
    $check('stored error never mixes in private note', !str_contains($html, 'staff-only-pr2'));
    $kept['stored_error'] = $order->get_id();
    $order->update_meta_data(YSOrderMeta::ERROR_MESSAGE, '<script>pr2_xss</script>');
    $order->save();
    $check('stored error remains HTML-escaped', str_contains($render($order), '&lt;script&gt;pr2_xss&lt;/script&gt;'));
    $order->update_meta_data(YSOrderMeta::ERROR_MESSAGE, '發卡銀行拒絕交易，請改用其他付款方式。');
    $order->save();
    $check('gateway settings unchanged in database', $settings_hash === hash('sha256', wp_json_encode(get_option('woocommerce_ys_shopline_credit_installment_settings'))));
    $check('zero HTTP requests attempted', 0 === $http_calls);
    $check('email suppression remains active', true === apply_filters('pre_wp_mail', null, array()));
    $completed = !$failures;
    WP_CLI::log(wp_json_encode(array('pass' => $passes, 'fail' => count($failures), 'intercepted_creates' => count($interceptor->requests), 'http_calls' => $http_calls, 'browser_fixtures' => $kept)));
    // Guest fixtures use key-protected thank-you and order-pay pages, not My Account.
    foreach ($kept as $label => $id) {
        $order = wc_get_order($id);
        WP_CLI::log($label . '_thankyou: ' . $order->get_checkout_order_received_url());
    }
} finally {
    $_POST = $original_post;
    $_REQUEST = $original_request;
    wp_set_current_user($original_user);
    if (isset($original_settings)) { $gateway->settings = $original_settings; }
    if (isset($original_api)) { $api_property->setValue($gateway, $original_api); }
    wc_clear_notices();
    foreach ($fixtures as $id) {
        if ($completed && '1' === getenv('YS_KEEP_FIXTURES') && in_array($id, $kept, true)) {
            continue;
        }
        $fixture = wc_get_order($id);
        if ($fixture && $marker === $fixture->get_created_via()) { $fixture->delete(true); }
    }
    remove_filter('pre_http_request', $block_http, PHP_INT_MAX);
    remove_filter('pre_option_woocommerce_allow_tracking', $disable_tracking);
    WP_CLI::log('Fixture cleanup complete; no products, stock or customer accounts changed.');
}
if ($failures) {
    throw new RuntimeException(implode('; ', $failures));
}
