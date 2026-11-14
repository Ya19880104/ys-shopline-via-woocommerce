<?php
declare(strict_types=1);
use YangSheep\ShoplinePayment\Customer\YSSubscriptionBinding;
use YangSheep\ShoplinePayment\Utils\YSOrderMeta;

function wcs_get_subscriptions_for_order($order, $args = []) {
    return array_filter($GLOBALS['ys_binding_subs'] ?? [], static fn($sub) => $sub->parent === $order->get_id());
}
function wcs_get_subscription($id) { return $GLOBALS['ys_binding_subs'][$id] ?? null; }

class YS_Binding_Order extends YS_Evidence_Order {
    public int $id = 9871;
    public int $user = 77;
    public int $parent = 0;
    public function get_id() { return $this->id; }
    public function get_user_id() { return $this->user; }
    public function get_parent_id() { return $this->parent; }
}

function ys_run_subscription_binding_contract(): void {
    echo "== Exact subscription binding ownership ==\n";
    $GLOBALS['ys_binding_subs'] = [];
    $parents = [];
    foreach ([9871 => 'card-A', 9872 => 'card-B'] as $id => $card) {
        $order = new YS_Binding_Order();
        $order->id = $id;
        $order->gateway = 'ys_shopline_credit_subscription';
        $sub = new YS_Binding_Order();
        $sub->gateway = $order->gateway;
        $sub->id = $id + 100;
        $sub->parent = $id;
        $GLOBALS['ys_binding_subs'][$sub->id] = $sub;
        $request = ['referenceOrderId' => $id . '_1', 'confirm' => ['paymentCustomerId' => 'customer-77', 'paymentInstrument' => ['paymentInstrumentId' => $card]]];
        YSSubscriptionBinding::capture($order, $request, $order->gateway);
        $parents[$id] = $order;
        YS_Assert::eq('captures only the original order subscription ' . $id, [$sub->id], $order->get_meta(YSSubscriptionBinding::META)['subscriptions']);
        YS_Assert::eq('captures the official nested selected card ' . $id, $card, $order->get_meta(YSSubscriptionBinding::META)['instrument_id']);
    }
    $remote = ['tradeOrderId' => 'paid-A', 'referenceOrderId' => '9871_1', 'payment' => [
        'paymentCustomerId' => 'customer-77', 'paymentInstrument' => ['paymentInstrumentId' => 'card-A'],
    ]];
    $wrong = $remote;
    $wrong['payment']['paymentInstrument']['paymentInstrumentId'] = 'another-card';
    YSSubscriptionBinding::complete($parents[9871], $wrong, 'paid-A');
    YS_Assert::eq('same customer wrong selected card cannot bind', '', $GLOBALS['ys_binding_subs'][9971]->get_meta(YSOrderMeta::PAYMENT_INSTRUMENT_ID));
    YSSubscriptionBinding::complete($parents[9871], $remote, 'paid-A');
    YSSubscriptionBinding::complete($parents[9871], $remote, 'paid-A');
    YS_Assert::eq('first order binds its own card', 'card-A', $GLOBALS['ys_binding_subs'][9971]->get_meta(YSOrderMeta::PAYMENT_INSTRUMENT_ID));
    YS_Assert::eq('first card never fills another pending subscription', '', $GLOBALS['ys_binding_subs'][9972]->get_meta(YSOrderMeta::PAYMENT_INSTRUMENT_ID));
    $remote['referenceOrderId'] = '9872_1';
    $remote['payment']['paymentInstrument']['paymentInstrumentId'] = 'card-B';
    YSSubscriptionBinding::complete($parents[9872], $remote, 'paid-A');
    YS_Assert::eq('second order independently binds its selected card', 'card-B', $GLOBALS['ys_binding_subs'][9972]->get_meta(YSOrderMeta::PAYMENT_INSTRUMENT_ID));
    $GLOBALS['ys_binding_subs'][9972]->meta[YSOrderMeta::PAYMENT_INSTRUMENT_ID] = 'manually-changed-card';
    YSSubscriptionBinding::complete($parents[9872], $remote, 'paid-A');
    YS_Assert::eq('late payment event never overwrites an existing card', 'manually-changed-card', $GLOBALS['ys_binding_subs'][9972]->get_meta(YSOrderMeta::PAYMENT_INSTRUMENT_ID));
    $GLOBALS['ys_binding_subs'][9972]->meta = [];
    $parents[9872]->meta[YSSubscriptionBinding::META]['state'] = 'awaiting_payment_evidence';
    $remote['payment']['paymentCustomerId'] = 'other-user';
    YSSubscriptionBinding::complete($parents[9872], $remote, 'paid-A');
    YS_Assert::eq('different customer cannot bind card', '', $GLOBALS['ys_binding_subs'][9972]->get_meta(YSOrderMeta::PAYMENT_INSTRUMENT_ID));
    $sql = YangSheep\ShoplinePayment\Gateways\YSCreditSubscription::exclude_order_specific_meta_from_renewals('SELECT meta_key', 1, 2);
    YS_Assert::is_true('binding request is never copied into renewals', str_contains($sql, YSSubscriptionBinding::META));
    $gateway = new YS_Subscription_Test_Gateway();
    $gateway->bound = '';
    $gateway->fallback = 'default-card';
    $order = new YS_Subscription_Test_Order();
    $gateway->process_subscription_payment(10, $order);
    YS_Assert::eq('unbound subscription never charges the user default card', [], $gateway->charge_instruments);
    YS_Assert::eq('unbound renewal remains on hold for review', 'on-hold', $order->status);
    $handler = file_get_contents(dirname(__DIR__) . '/src/Handlers/YSWebhookHandler.php');
    YS_Assert::eq('customer bind event has no user-wide subscription writer', false, str_contains($handler, 'update_pending_subscriptions_instrument'));
    $GLOBALS['ys_binding_subs'] = [];
}
