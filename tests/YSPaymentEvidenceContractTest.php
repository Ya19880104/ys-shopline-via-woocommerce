<?php
declare(strict_types=1);

use YangSheep\ShoplinePayment\Handlers\YSPaymentConfirmation;
use YangSheep\ShoplinePayment\Utils\YSOrderMeta;

class YS_Evidence_Order extends WC_Order {
    public array $meta = [];
    public array $notes = [];
    public float $total = 100;
    public string $currency = 'TWD';
    public string $gateway = 'ys_shopline_credit';
    public int $completed = 0;
    public function get_id() { return 9871; }
    public function get_total() { return $this->total; }
    public function get_currency() { return $this->currency; }
    public function get_payment_method() { return $this->gateway; }
    public function get_status() { return $this->completed ? 'processing' : 'pending'; }
    public function get_date_paid() { return $this->completed ? '2026-11-14' : null; }
    public function is_paid() { return $this->completed > 0; }
    public function get_meta($key) { return $this->meta[$key] ?? ''; }
    public function update_meta_data($key, $value) { $this->meta[$key] = $value; }
    public function delete_meta_data($key) { unset($this->meta[$key]); }
    public function save() {}
    public function add_order_note($note) { $this->notes[] = $note; }
    public function payment_complete($id = '') { ++$this->completed; return true; }
}

function ys_evidence_fixture(): array {
    $order = new YS_Evidence_Order();
    $order->meta[YSOrderMeta::REFERENCE_ORDER_ID] = '9871_1';
    $order->meta[YSOrderMeta::TRADE_ORDER_ID] = 'evidence-trade';
    $order->meta[YSOrderMeta::PAYMENT_ATTEMPT_DATA] = [
        'reference' => '9871_1', 'gateway' => 'ys_shopline_credit',
        'shopline_method' => 'CreditCard', 'amount' => 10000, 'currency' => 'TWD',
        'order_amount' => 10000, 'order_currency' => 'TWD', 'payment_behavior' => 'Regular',
    ];
    $GLOBALS['ys_test_order'] = $order;
    $GLOBALS['wpdb'] = null;
    return [$order, [
        'tradeOrderId' => 'evidence-trade', 'referenceOrderId' => '9871_1', 'status' => 'SUCCEEDED',
        'payment' => ['paymentMethod' => 'CreditCard', 'paidAmount' => ['value' => 10000, 'currency' => 'TWD']],
    ]];
}

function ys_run_payment_evidence_contract(): void {
    echo "== Original payment evidence ==\n";
    [$order, $remote] = ys_evidence_fixture();
    $order->total = 200;
    YSPaymentConfirmation::complete_payment_once($order, 'evidence-trade', $remote);
    YS_Assert::eq('edited order is not paid using the original smaller payment', 0, $order->completed);
    YS_Assert::eq('original request remains available on mismatch', 10000, $order->meta[YSOrderMeta::PAYMENT_ATTEMPT_DATA]['amount']);

    $mutations = [
        'order currency' => static function($o, &$r) { $o->currency = 'USD'; },
        'paid amount' => static function($o, &$r) { $r['payment']['paidAmount']['value'] = 9999; },
        'paid currency' => static function($o, &$r) { $r['payment']['paidAmount']['currency'] = 'USD'; },
        'missing currency' => static function($o, &$r) { unset($r['payment']['paidAmount']['currency']); },
        'missing amount' => static function($o, &$r) { unset($r['payment']['paidAmount']); },
        'fractional amount' => static function($o, &$r) { $r['payment']['paidAmount']['value'] = 10000.5; },
        'reference suffix' => static function($o, &$r) { $r['referenceOrderId'] = '9871_2'; },
        'missing reference' => static function($o, &$r) { unset($r['referenceOrderId']); },
        'method' => static function($o, &$r) { $r['payment']['paymentMethod'] = 'LinePay'; },
        'trade' => static function($o, &$r) { $r['tradeOrderId'] = 'other'; },
        'current trade' => static function($o, &$r) { $o->meta[YSOrderMeta::TRADE_ORDER_ID] = 'newer'; },
        'no original request' => static function($o, &$r) { unset($o->meta[YSOrderMeta::PAYMENT_ATTEMPT_DATA]); },
        'request differs from order' => static function($o, &$r) { $o->meta[YSOrderMeta::PAYMENT_ATTEMPT_DATA]['order_amount'] = 9000; $o->total = 90; },
    ];
    foreach ($mutations as $label => $mutate) {
        [$o, $r] = ys_evidence_fixture();
        $mutate($o, $r);
        $before = $o->meta[YSOrderMeta::PAYMENT_ATTEMPT_DATA] ?? '';
        YSPaymentConfirmation::complete_payment_once($o, 'evidence-trade', $r);
        YS_Assert::eq("$label rejects automatic completion", 0, $o->completed);
        YS_Assert::eq("$label retains original request", $before, $o->get_meta(YSOrderMeta::PAYMENT_ATTEMPT_DATA));
        YS_Assert::eq("$label enters review", 'payment_evidence_mismatch', $o->get_meta(YSOrderMeta::MANUAL_REVIEW_FLAG));
        YSPaymentConfirmation::complete_payment_once($o, 'evidence-trade', $r);
        YS_Assert::eq("$label repeated notification does not flood notes", 1, count($o->notes));
    }
    $gateways = [
        'credit' => 'CreditCard', 'credit_installment' => 'CreditCard', 'credit_subscription' => 'CreditCard',
        'atm' => 'VirtualAccount', 'applepay' => 'ApplePay', 'linepay' => 'LinePay',
        'jkopay' => 'JKOPay', 'bnpl' => 'ChaileaseBNPL',
    ];
    foreach ($gateways as $gateway => $method) {
        foreach (['SUCCEEDED', 'CAPTURED'] as $status) {
            [$o, $r] = ys_evidence_fixture();
            $o->gateway = 'ys_shopline_' . $gateway;
            $o->meta[YSOrderMeta::PAYMENT_ATTEMPT_DATA]['gateway'] = $o->gateway;
            $o->meta[YSOrderMeta::PAYMENT_ATTEMPT_DATA]['shopline_method'] = $method;
            $r['payment']['paymentMethod'] = $method;
            $r['status'] = $status;
            YSPaymentConfirmation::complete_payment_once($o, 'evidence-trade', $r);
            YSPaymentConfirmation::complete_payment_once($o, 'evidence-trade', $r);
            YS_Assert::eq("$gateway $status exact payment completes only once", 1, $o->completed);
        }
    }
    foreach ([true, false] as $explicit) {
        [$o, $r] = ys_evidence_fixture();
        $o->total = 0;
        $o->gateway = 'ys_shopline_credit_subscription';
        $o->meta[YSOrderMeta::PAYMENT_ATTEMPT_DATA]['gateway'] = $o->gateway;
        $o->meta[YSOrderMeta::PAYMENT_ATTEMPT_DATA]['order_amount'] = 0;
        $o->meta[YSOrderMeta::PAYMENT_ATTEMPT_DATA]['amount'] = 10100;
        $o->meta[YSOrderMeta::PAYMENT_ATTEMPT_DATA]['payment_behavior'] = $explicit ? 'CardBind' : '';
        $r['payment']['paidAmount']['value'] = 10100;
        YSPaymentConfirmation::complete_payment_once($o, 'evidence-trade', $r);
        YS_Assert::eq('zero-total requires an explicit original CardBind request ' . (int)$explicit, $explicit ? 1 : 0, $o->completed);
    }
    [$o, $r] = ys_evidence_fixture();
    $r['amount'] = ['value' => 10000, 'currency' => 'TWD'];
    $r['payment']['paidAmount']['value'] = 5000;
    YSPaymentConfirmation::complete_payment_once($o, 'evidence-trade', $r);
    YS_Assert::eq('paidAmount cannot be hidden by a matching order amount', 0, $o->completed);
    [$o, $r] = ys_evidence_fixture();
    $api = new YS_Gateway_Test_Api();
    $api->prior_responses = [$r];
    $short = ['tradeOrderId' => 'evidence-trade', 'status' => 'SUCCEEDED'];
    $full = YangSheep\ShoplinePayment\Utils\YSPaymentEvidence::from_create_response($short, $api);
    YSPaymentConfirmation::complete_payment_once($o, 'evidence-trade', $full);
    YS_Assert::eq('incomplete create response queries the exact remote trade once', 1, $api->query_calls);
    YS_Assert::eq('queried complete evidence permits payment', 1, $o->completed);
    $api->prior_responses = [new WP_Error('offline', 'query unavailable')];
    YS_Assert::eq('failed evidence query never invents amount or reference', $short, YangSheep\ShoplinePayment\Utils\YSPaymentEvidence::from_create_response($short, $api));
    $before = $api->query_calls;
    YangSheep\ShoplinePayment\Utils\YSPaymentEvidence::from_create_response($r, $api);
    YS_Assert::eq('complete response needs no extra query', $before, $api->query_calls);
}
