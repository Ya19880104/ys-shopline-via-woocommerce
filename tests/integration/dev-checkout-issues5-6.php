<?php
/** Real HPOS/WCS, signed REST dispatch, and mocked remote query responses. No financial HTTP leaves PHP. */
declare(strict_types=1);
use YangSheep\ShoplinePayment\Utils\YSOrderMeta;
use YangSheep\ShoplinePayment\Utils\YSSignatureVerifier;
use YangSheep\ShoplinePayment\Utils\YSPaymentEvidence;
use YangSheep\ShoplinePayment\Handlers\YSPaymentConfirmation;
use YangSheep\ShoplinePayment\Handlers\YSRedirectHandler;
use YangSheep\ShoplinePayment\Handlers\YSStatusManager;
use YangSheep\ShoplinePayment\DTOs\YSPaymentDTO;
use YangSheep\ShoplinePayment\Customer\YSSubscriptionBinding;
use YangSheep\ShoplinePayment\Gateways\YSGatewayBase;

/** Replace only SDK/request preparation, not the production response/completion path. */
class YS_Issue56_Create_Gateway extends YSGatewayBase {
    private string $method;
    public function __construct($gateway, $method) { $this->id = 'ys_shopline_' . $gateway; $this->method = $method; $this->api = YSShoplinePayment::get_api(); }
    public function get_payment_method() { return $this->method; }
    protected function prepare_payment_data($order, $session) {
        return ['referenceOrderId' => $order->get_id() . '_1', 'amount' => ['value' => 10000, 'currency' => 'TWD'],
            'confirm' => ['paymentMethod' => $this->method], 'paySession' => $session];
    }
}

if (!defined('WP_CLI') || !WP_CLI || 'dev-checkout.wppro.cloud' !== wp_parse_url(home_url(), PHP_URL_HOST)
    || !current_user_can('manage_woocommerce') || true !== apply_filters('pre_wp_mail', null, [])
    || 'yes' !== get_option('ys_shopline_testmode') || 'yes' !== get_option('woocommerce_custom_orders_table_enabled')
    || !function_exists('wcs_create_subscription')) {
    throw new RuntimeException('Requires DEV, admin, suppressed mail, sandbox, HPOS and WCS.');
}
$pass = 0; $fail = []; $intercepted = 0; $remote = []; $fixtures = []; $subscriptions = []; $edit_inflight = 0;
$check = static function($label, $ok) use (&$pass, &$fail) {
    if ($ok) { ++$pass; WP_CLI::log('PASS | ' . $label); }
    else { $fail[] = $label; WP_CLI::warning('FAIL | ' . $label); }
};
add_filter('pre_http_request', static function($pre, $args, $url) use (&$intercepted, &$remote, &$edit_inflight) {
    ++$intercepted;
    if (str_contains($url, '/trade/payment/create') && $edit_inflight) {
        $edited = new WC_Order($edit_inflight); $edited->set_total(200); $edited->save();
    }
    if (str_contains($url, '/trade/payment/get') || str_contains($url, '/trade/payment/create') || str_contains($url, '/trade/get') || str_contains($url, '/trade/sessions/query')) {
        return ['headers' => [], 'response' => ['code' => 200, 'message' => 'OK'], 'body' => wp_json_encode($remote), 'cookies' => []];
    }
    return new WP_Error('issues56_http_blocked', 'All outbound HTTP blocked during integration test.');
}, PHP_INT_MAX, 3);
add_filter('pre_option_woocommerce_allow_tracking', '__return_false');
$deliver = static function($type, $data, $valid = true) {
    $body = wp_json_encode(['type' => $type, 'data' => $data]);
    $ts = (string)(time() * 1000);
    $r = new WP_REST_Request('POST', '/ys-shopline/v1/webhook');
    $r->set_header('content-type', 'application/json'); $r->set_body($body);
    $r->set_header('timestamp', $ts);
    $r->set_header('sign', $valid ? YSSignatureVerifier::generate_signature($ts . '.' . $body) : 'bad-signature');
    return rest_do_request($r);
};
$new_order = static function() use (&$fixtures) {
    $o = new WC_Order(); $o->set_created_via('ys-issues56-20261114');
    $o->set_currency('TWD'); $o->set_billing_email('issues56@example.invalid'); $o->set_total(100);
    $o->set_payment_method('ys_shopline_credit'); $o->save(); $fixtures[] = $o->get_id();
    return $o;
};
$order = $new_order();
$id = $order->get_id();
WP_CLI::log('FIXTURE_ORDER=' . $id);
$reset = static function($gateway = 'credit', $method = 'CreditCard') use ($id) {
    $o = new WC_Order($id); $o->set_status('pending'); $o->set_date_paid(null); $o->set_date_completed(null);
    $o->set_total(100); $o->set_currency('TWD'); $o->set_payment_method('ys_shopline_' . $gateway);
    foreach ($o->get_meta_data() as $m) { if (str_starts_with($m->key, '_ys_shopline_')) { $o->delete_meta_data($m->key); } }
    $o->update_meta_data(YSOrderMeta::TRADE_ORDER_ID, 'issues56-' . $id);
    $o->update_meta_data(YSOrderMeta::REFERENCE_ORDER_ID, $id . '_1');
    $o->update_meta_data(YSOrderMeta::PAYMENT_ATTEMPT_DATA, [
        'reference' => $id . '_1', 'gateway' => 'ys_shopline_' . $gateway, 'shopline_method' => $method,
        'amount' => 10000, 'currency' => 'TWD', 'order_amount' => 10000, 'order_currency' => 'TWD', 'payment_behavior' => 'Regular',
    ]);
    $o->save(); return $o;
};
$payload = static function($method = 'CreditCard') use ($id) {
    return ['tradeOrderId' => 'issues56-' . $id, 'referenceOrderId' => $id . '_1', 'status' => 'SUCCEEDED',
        'payment' => ['paymentMethod' => $method, 'paidAmount' => ['value' => 10000, 'currency' => 'TWD']],
        'order' => ['amount' => ['value' => 10000, 'currency' => 'TWD']]];
};
$old_cart = WC()->cart; $old_session = WC()->session;
WC()->cart = new class { public function empty_cart() {} };
WC()->session = new class extends WC_Session {};

try {
    foreach (['credit' => 'CreditCard', 'credit_installment' => 'CreditCard', 'credit_subscription' => 'CreditCard',
        'atm' => 'VirtualAccount', 'linepay' => 'LinePay', 'applepay' => 'ApplePay', 'jkopay' => 'JKOPay', 'bnpl' => 'ChaileaseBNPL'] as $gw => $method) {
        foreach (['webhook', 'redirect', 'status_sync', 'confirmation', 'confirm_webhook', 'immediate', 'gateway_query'] as $route) {
            foreach ([false, true] as $edited) {
                $o = $reset($gw, $method); $remote = $payload($method);
                if ($edited && $route !== 'immediate') { $o->set_total(200); $o->save(); }
                if ($route === 'webhook') {
                    $result = $deliver('trade.succeeded', $remote);
                    $check("$gw signed webhook admitted", 200 === $result->get_status());
                } elseif ($route === 'redirect') {
                    (new ReflectionMethod(YSRedirectHandler::class, 'check_and_update_order'))->invoke(null, $o, $remote['tradeOrderId']);
                } elseif ($route === 'status_sync') {
                    (new ReflectionMethod(YSStatusManager::class, 'update_order_from_payment'))->invoke(new YSStatusManager(), $o, YSPaymentDTO::from_response($remote));
                } elseif (in_array($route, ['confirmation', 'confirm_webhook'], true)) {
                    $o->set_status('ys-confirming');
                    $attempt = YSPaymentConfirmation::attempt_from_order($o, 'PROCESSING', $remote);
                    $o->update_meta_data(YSOrderMeta::CONFIRMATION_DATA, $attempt); $o->save();
                    if ($route === 'confirmation') {
                        YSPaymentConfirmation::handle_query_result($o, YSPaymentDTO::from_response($remote));
                    } else {
                        $deliver('trade.succeeded', $remote);
                    }
                } elseif ($route === 'immediate') {
                    $o->delete_meta_data(YSOrderMeta::TRADE_ORDER_ID); $o->save();
                    $edit_inflight = $edited ? $id : 0;
                    $_POST['ys_shopline_pay_session'] = '{"sessionId":"issues56-fake-sdk"}';
                    (new YS_Issue56_Create_Gateway($gw, $method))->process_payment($id);
                    $edit_inflight = 0;
                } else {
                    $gateway = WC()->payment_gateways()->payment_gateways()['ys_shopline_' . $gw];
                    (new ReflectionMethod($gateway, 'check_and_update_order_status'))->invoke($gateway, $o, $remote['tradeOrderId']);
                }
                $fresh = new WC_Order($id);
                $check("$gw / $route / edited=" . (int)$edited . ' paid boundary', !$edited === (bool)$fresh->get_date_paid());
                if ($edited) {
                    $check("$gw / $route mismatch retained request", 10000 === (int)($fresh->get_meta(YSOrderMeta::PAYMENT_ATTEMPT_DATA)['amount'] ?? -1));
                    $check("$gw / $route mismatch in admin review", YSPaymentEvidence::is_blocked($fresh));
                }
            }
        }
    }
    $o = $reset(); $remote = $payload();
    $result = $deliver('trade.succeeded', $remote, false);
    $check('bad signature rejected before payment', 400 === $result->get_status() && !(new WC_Order($id))->get_date_paid());
    $remote['payment']['paidAmount']['currency'] = 'USD';
    $deliver('trade.captured', $remote);
    $check('captured wrong currency blocked', !(new WC_Order($id))->get_date_paid() && YSPaymentEvidence::is_blocked(new WC_Order($id)));

    foreach (['recurring', 'session', 'legacy_indeterminate_webhook'] as $route) {
        foreach ([false, true] as $edited) {
            $o = $reset('credit_subscription', 'CreditCard'); $remote = $payload();
            if ($edited) { $o->set_total(200); }
            $gateway = WC()->payment_gateways()->payment_gateways()['ys_shopline_credit_subscription'];
            if ($route === 'recurring') {
                $o->save();
                (new ReflectionMethod($gateway, 'handle_recurring_response'))->invoke($gateway, $o, $remote);
            } else {
                $request = $o->get_meta(YSOrderMeta::PAYMENT_ATTEMPT_DATA);
                $request['session_id'] = 'issues56-session';
                $o->delete_meta_data(YSOrderMeta::PAYMENT_ATTEMPT_DATA);
                $o->delete_meta_data(YSOrderMeta::TRADE_ORDER_ID);
                $o->update_meta_data(YSOrderMeta::INDETERMINATE_REF, $id . '_1');
                $o->update_meta_data(YSOrderMeta::INDETERMINATE_DATA, $request); $o->save();
                if ($route === 'session') {
                    $remote = ['sessionId' => 'issues56-session', 'referenceId' => $id . '_1', 'amount' => ['value' => 10000, 'currency' => 'TWD'],
                        'paymentDetails' => [['tradeOrderId' => 'issues56-' . $id, 'paymentMethod' => 'CreditCard', 'status' => 'SUCCEEDED']]];
                    (new ReflectionMethod($gateway, 'resolve_indeterminate'))->invoke($gateway, $o);
                } else { $deliver('trade.succeeded', $remote); }
            }
            $fresh = new WC_Order($id);
            $check("$route edited=" . (int)$edited . ' paid boundary', !$edited === (bool)$fresh->get_date_paid());
            if ($edited) {
                $check("$route mismatch requires review", YSPaymentEvidence::is_blocked($fresh));
                $calls_before = $intercepted;
                $result = (new ReflectionMethod($gateway, 'resolve_indeterminate'))->invoke($gateway, $fresh);
                $check("$route review blocks another API attempt", $result === 'blocked' && $calls_before === $intercepted);
            }
        }
    }

    $uid = username_exists('ys-issues56-fixture');
    if (!$uid) { $uid = wp_insert_user(['user_login' => 'ys-issues56-fixture', 'user_pass' => wp_generate_password(48), 'user_email' => 'issues56-user@example.invalid', 'role' => 'customer']); }
    if (is_wp_error($uid)) { throw new RuntimeException($uid->get_error_message()); }
    update_user_meta($uid, YSOrderMeta::CUSTOMER_ID, 'issues56-customer');
    $parents = [];
    foreach (['A', 'B'] as $letter) {
        $p = $new_order(); $p->set_customer_id($uid); $p->set_payment_method('ys_shopline_credit_subscription'); $p->save();
        $sub = wcs_create_subscription(['order_id' => $p->get_id(), 'customer_id' => $uid, 'billing_period' => 'month', 'billing_interval' => 1, 'status' => 'pending']);
        if (is_wp_error($sub)) { throw new RuntimeException($sub->get_error_message()); }
        $sub->set_payment_method('ys_shopline_credit_subscription'); $sub->save(); $subscriptions[] = $sub->get_id();
        $request = ['referenceOrderId' => $p->get_id() . '_1', 'confirm' => ['paymentCustomerId' => 'issues56-customer', 'paymentInstrument' => ['paymentInstrumentId' => 'issues56-card-' . $letter]]];
        YSSubscriptionBinding::capture($p, $request, 'ys_shopline_credit_subscription'); $p->save();
        $p->update_meta_data(YSOrderMeta::REFERENCE_ORDER_ID, $request['referenceOrderId']);
        $p->update_meta_data(YSOrderMeta::TRADE_ORDER_ID, 'binding-' . $p->get_id());
        $p->update_meta_data(YSOrderMeta::PAYMENT_ATTEMPT_DATA, ['reference' => $request['referenceOrderId'],
            'gateway' => 'ys_shopline_credit_subscription', 'shopline_method' => 'CreditCard', 'amount' => 10000, 'currency' => 'TWD']);
        $p->save();
        $parents[$letter] = [$p, $sub];
        $check('binding capture ' . $letter, [$sub->get_id()] === $p->get_meta(YSSubscriptionBinding::META)['subscriptions']);
    }
    $card_event = ['customerId' => 'issues56-customer', 'paymentInstrument' => [
        'instrumentId' => 'issues56-card-A', 'instrumentCard' => ['last' => '1234', 'brand' => 'Visa', 'expireYear' => '2030', 'expireMonth' => '12', 'expiry' => '12/30'],
    ]];
    $deliver('customer.instrument.binded', $card_event); $deliver('customer.instrument.binded', $card_event);
    $tokens = array_filter(WC_Payment_Tokens::get_customer_tokens($uid, YSOrderMeta::CREDIT_GATEWAY_ID), static fn($t) => $t->get_token() === 'issues56-card-A');
    $check('duplicate customer event saves exactly one usable token', count($tokens) === 1);
    foreach ($parents as $letter => [$p, $sub]) {
        $check('generic bind leaves ' . $letter . ' unbound', '' === wcs_get_subscription($sub->get_id())->get_meta(YSOrderMeta::PAYMENT_INSTRUMENT_ID));
    }
    foreach ($parents as $letter => [$p, $sub]) {
        $r = ['tradeOrderId' => 'binding-' . $p->get_id(), 'referenceOrderId' => $p->get_id() . '_1', 'status' => 'SUCCEEDED',
            'payment' => ['paymentMethod' => 'CreditCard', 'paidAmount' => ['value' => 10000, 'currency' => 'TWD'],
                'paymentCustomerId' => 'issues56-customer', 'paymentInstrument' => ['paymentInstrumentId' => 'issues56-card-' . $letter]]];
        if ($letter === 'A') {
            $early = $r; unset($early['payment']['paymentInstrument']);
            $deliver('trade.succeeded', $early);
            $check('paid before richer instrument remains unbound', (bool)(new WC_Order($p->get_id()))->get_date_paid() && '' === wcs_get_subscription($sub->get_id())->get_meta(YSOrderMeta::PAYMENT_INSTRUMENT_ID));
        }
        $deliver('trade.succeeded', $r); $deliver('trade.succeeded', $r);
        $check('exact paid context binds ' . $letter, 'issues56-card-' . $letter === wcs_get_subscription($sub->get_id())->get_meta(YSOrderMeta::PAYMENT_INSTRUMENT_ID));
    }
    [$p, $sub] = $parents['B']; $sub = wcs_get_subscription($sub->get_id());
    $sub->update_meta_data(YSOrderMeta::PAYMENT_INSTRUMENT_ID, 'issues56-explicit-change'); $sub->save();
    $deliver('trade.succeeded', $r);
    $check('late context preserves explicit card change', 'issues56-explicit-change' === wcs_get_subscription($sub->get_id())->get_meta(YSOrderMeta::PAYMENT_INSTRUMENT_ID));
    foreach (WC_Payment_Tokens::get_customer_tokens($uid, YSOrderMeta::CREDIT_GATEWAY_ID) as $token) {
        if (str_starts_with($token->get_token(), 'issues56-')) { $token->delete(); }
    }
} finally {
    WC()->cart = $old_cart; WC()->session = $old_session;
    foreach ($subscriptions as $sid) {
        $s = wcs_get_subscription($sid); $s->set_status('on-hold');
        $s->delete_meta_data(YSOrderMeta::PAYMENT_INSTRUMENT_ID); $s->save();
        as_unschedule_all_actions('woocommerce_scheduled_subscription_payment', ['subscription_id' => $sid]);
    }
    foreach ($fixtures as $fid) {
        $o = new WC_Order($fid); $o->set_status('pending'); $o->set_date_paid(null); $o->set_date_completed(null);
        $o->delete_meta_data(YSOrderMeta::TRADE_ORDER_ID); $o->save();
        foreach ([YSPaymentConfirmation::SCHEDULE_HOOK, 'ys_shopline_cancel_followup'] as $hook) {
            foreach (as_get_scheduled_actions(['hook' => $hook, 'status' => 'pending', 'per_page' => 100]) as $aid => $action) {
                $args = $action->get_args(); if ((int)reset($args) === $fid) { ActionScheduler::store()->cancel_action($aid); }
            }
        }
    }
    WP_CLI::log('RETAINED orders=' . implode(',', $fixtures) . ' subscriptions=' . implode(',', $subscriptions));
    WP_CLI::log('HTTP_INTERCEPTED=' . $intercepted . ' REAL_HTTP_SENT=0 EMAIL_SUPPRESSED=yes');
}
WP_CLI::log('RESULT ' . $pass . ' PASS / ' . count($fail) . ' FAIL');
if ($fail) { WP_CLI::error('Integration assertions failed.'); }
