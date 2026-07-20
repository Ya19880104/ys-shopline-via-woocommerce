/**
 * 前端契約測試（v3.5.39 分期三態與 SDK DOM snapshot）——Node 直跑、零依賴。
 *
 * 固定命令（外掛根目錄）：node tests/js/checkout-contract.test.js
 * exit 0＝全過；1＝有失敗。
 *
 * 驗證對象＝真實 assets/js/ys-shopline-checkout.js（vm sandbox 載入，非複製邏輯）：
 *   1. 最終 SDK options（ShoplineCheckout.buildSdkOptions）：
 *      分期會員 optional bindCard 預設關閉、customerToken 保留；訪客不啟用；
 *      主卡／訂閱 bindCard 行為不變。
 *   2. 前端實際產生的 mode（ShoplineCheckout.getPaymentInstrumentSelection，
 *      checkout 與 order-pay 共用同一函式）：分期既有卡→saved、SDK 自製開關
 *      off→new／active→new_save；snapshot 跨 createPayment DOM 替換保留；
 *      主卡 new_save 回歸；不支援既有卡的 gateway→regular。
 *   3. 呼叫點契約（原始碼斷言）：order-pay 與 checkout 均走共用 selection；
 *      renderPayment 走 buildSdkOptions；舊 bind-card-enabled 容器旗標已移除。
 */

'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

let pass = 0;
let fail = 0;
const failures = [];

function verdict(name, ok, detail) {
	if (ok) {
		pass++;
		console.log('PASS | ' + name);
	} else {
		fail++;
		failures.push(name);
		console.log('FAIL | ' + name + (detail ? ' | ' + detail : ''));
	}
}
function eq(name, expected, actual) {
	verdict(name, Object.is(expected, actual) || JSON.stringify(expected) === JSON.stringify(actual),
		'expected=' + JSON.stringify(expected) + ' got=' + JSON.stringify(actual));
}

// ---------- 極簡 jQuery / DOM 假件 ----------

function makeEl(props) {
	return Object.assign({
		tagName: 'DIV',
		checked: false,
		__text: '',
		__children: {},
		getBoundingClientRect: function () { return { width: 0, height: 0 }; }
	}, props || {});
}

const registry = {};

function C(elements) {
	elements = elements || [];
	const api = {
		__isFake: true,
		length: elements.length,
		elements: elements,
		each: function (fn) {
			for (let i = 0; i < elements.length; i++) {
				if (fn.call(elements[i], i, elements[i]) === false) { break; }
			}
			return api;
		},
		filter: function (sel) {
			if (typeof sel === 'function') { return C(elements.filter(function (e, i) { return sel.call(e, i, e); })); }
			if (sel === ':checked') { return C(elements.filter(function (e) { return !!e.checked; })); }
			return C([]);
		},
		find: function (sel) {
			let out = [];
			elements.forEach(function (e) {
				const kids = (e.__children || {})[sel];
				if (kids) { out = out.concat(kids); }
			});
			return C(out);
		},
		text: function () { return elements.map(function (e) { return e.__text || ''; }).join(''); },
		closest: function () { return C([]); },
		data: function () { return undefined; },
		on: function () { return api; }, off: function () { return api; }, trigger: function () { return api; },
		hasClass: function () { return false; }, is: function () { return false; },
		val: function () { return ''; }, attr: function () { return api; }, prop: function () { return undefined; },
		append: function () { return api; }, prepend: function () { return api; }, remove: function () { return api; },
		empty: function () { return api; }, html: function () { return ''; },
		addClass: function () { return api; }, removeClass: function () { return api; }, css: function () { return api; },
		show: function () { return api; }, hide: function () { return api; },
		first: function () { return C(elements.slice(0, 1)); }, parent: function () { return C([]); }, parents: function () { return C([]); },
		offset: function () { return { top: 0 }; }, animate: function () { return api; },
		ajaxSend: function () { return api; }, ajaxComplete: function () { return api; },
		ajaxStart: function () { return api; }, ajaxStop: function () { return api; }, ajaxError: function () { return api; },
		not: function () { return api; }, add: function () { return api; }, children: function () { return C([]); },
		index: function () { return -1; }, scrollTop: function () { return 0; }, height: function () { return 0; },
		width: function () { return 0; }, outerHeight: function () { return 0; }, focus: function () { return api; },
		blur: function () { return api; }, submit: function () { return api; }, serializeArray: function () { return []; },
		ready: function (fn) { fn(jq); return api; }
	};
	return api;
}

function jq(arg) {
	if (typeof arg === 'function') { arg(jq); return C([]); }
	if (typeof arg === 'string') {
		if (arg.charAt(0) === '<') { return C([makeEl()]); }
		return registry[arg] || C([]);
	}
	if (arg && arg.__isFake) { return arg; }
	if (arg && typeof arg === 'object') { return C([arg]); }
	return C([]);
}
jq.each = function (obj, cb) {
	if (Array.isArray(obj)) { obj.forEach(function (v, i) { cb(i, v); }); }
	else { Object.keys(obj || {}).forEach(function (k) { cb(k, obj[k]); }); }
};
jq.extend = function () {
	let args = Array.prototype.slice.call(arguments);
	let deep = false;
	if (args[0] === true) { deep = true; args = args.slice(1); }
	const target = args.shift() || {};
	args.forEach(function (src) {
		if (!src) { return; }
		Object.keys(src).forEach(function (k) {
			const v = src[k];
			if (deep && v && typeof v === 'object' && !Array.isArray(v)) {
				target[k] = jq.extend(true, (target[k] && typeof target[k] === 'object') ? target[k] : {}, v);
			} else {
				target[k] = v;
			}
		});
	});
	return target;
};
const jqXhrStub = { done: function () { return jqXhrStub; }, fail: function () { return jqXhrStub; }, always: function () { return jqXhrStub; }, abort: function () {} };
jq.ajax = function () { return jqXhrStub; };
jq.post = jq.ajax;
jq.get = jq.ajax;
jq.fn = {};

// ---------- 以 vm sandbox 載入真實前端檔 ----------

const jsPath = path.join(__dirname, '..', '..', 'assets', 'js', 'ys-shopline-checkout.js');
const source = fs.readFileSync(jsPath, 'utf8');

const base = {
	jQuery: jq,
	$: jq,
	ys_shopline_params: { ajax_url: '', nonce: '', i18n: {}, gateway_id: '' },
	console: { log: function () {}, warn: function () {}, error: function () {} },
	setTimeout: function () { return 0; },
	clearTimeout: function () {},
	setInterval: function () { return 0; },
	clearInterval: function () {},
	MutationObserver: function () { this.observe = function () {}; this.disconnect = function () {}; },
	navigator: { userAgent: 'node-contract-test' },
	screen: { width: 0, height: 0 },
	document: {
		body: {},
		head: {},
		createElement: function () { return makeEl(); },
		addEventListener: function () {},
		removeEventListener: function () {},
		querySelector: function () { return null; },
		querySelectorAll: function () { return []; }
	}
};
base.window = base;
base.window.__ysShoplineExposeForTests = true;
base.location = { href: 'https://contract.test/checkout', pathname: '/checkout/', search: '', hash: '', reload: function () {} };
base.window.location = base.location;

const sandbox = new Proxy(base, {
	get: function (t, k) {
		if (k in t) { return t[k]; }
		return globalThis[k]; // 內建（String/Object/RegExp/JSON…）回真品；未知 → undefined
	},
	has: function () { return true; } // 未知識別字 → undefined，而非 ReferenceError
});
vm.createContext(sandbox);
vm.runInContext(source, sandbox, { filename: 'ys-shopline-checkout.js' });

const hooks = base.window.__ysShoplineTestable;
verdict('harness: test hooks exposed', !!(hooks && hooks.ShoplineCheckout && hooks.GATEWAY_CONFIG));
if (!hooks) {
	console.log('----');
	console.log('RESULT: ' + pass + ' PASS / ' + (fail + 1) + ' FAIL');
	process.exit(1);
}
const SC = hooks.ShoplineCheckout;
const CFG = hooks.GATEWAY_CONFIG;

// ---------- 1. 能力矩陣 ----------
console.log('== GATEWAY_CONFIG capability split ==');
eq('installment: bindCard+savedCards both true', true, CFG.ys_shopline_credit_installment.supportsBindCard && CFG.ys_shopline_credit_installment.supportsSavedCards);
eq('credit: bindCard+savedCards both true', true, CFG.ys_shopline_credit.supportsBindCard && CFG.ys_shopline_credit.supportsSavedCards);
eq('subscription: bindCard+savedCards+forceSave', true, CFG.ys_shopline_credit_subscription.supportsBindCard && CFG.ys_shopline_credit_subscription.supportsSavedCards && CFG.ys_shopline_credit_subscription.forceSaveCard);
eq('ATM: no savedCards, no bindCard', true, !CFG.ys_shopline_atm.supportsBindCard && !CFG.ys_shopline_atm.supportsSavedCards);

// ---------- 2. 最終 SDK options ----------
console.log('== buildSdkOptions: final SDK options ==');
const SRV = function (extra) {
	return jq.extend({ clientKey: 'ck', merchantId: 'mid', currency: 'TWD', amount: 10000, env: 'sandbox' }, extra || {});
};

let opt = SC.buildSdkOptions(CFG.ys_shopline_credit_installment, SRV({ customerToken: 'tok-1' }));
eq('installment+token → vendor SDK saved-card compatibility enabled', true, opt.paymentInstrument.bindCard.enable);
eq('installment compatibility switch defaults off', false, opt.paymentInstrument.bindCard.protocol.defaultSwitchStatus);
eq('installment+token → customerToken kept', 'tok-1', opt.customerToken);

opt = SC.buildSdkOptions(CFG.ys_shopline_credit_installment, SRV({ customerToken: 'tok-1', paymentInstrument: { bindCard: { enable: true, protocol: { defaultSwitchStatus: true } } } }));
eq('installment server paymentInstrument remains supported', true, opt.paymentInstrument.bindCard.protocol.defaultSwitchStatus);

opt = SC.buildSdkOptions(CFG.ys_shopline_credit_installment, SRV({
	customerToken: 'tok-1',
	sdkOptions: { paymentInstrument: { bindCard: { enable: true } } }
}));
eq('installment sdkOptions paymentInstrument remains supported', true, opt.paymentInstrument.bindCard.enable);

opt = SC.buildSdkOptions(CFG.ys_shopline_credit_installment, SRV({}));
eq('installment guest → no token / no paymentInstrument', true, !('customerToken' in opt) && !('paymentInstrument' in opt));

opt = SC.buildSdkOptions(CFG.ys_shopline_credit_installment, SRV({ customerToken: 'tok-1', installmentCounts: ['3', '6'] }));
eq('installment → installmentCounts passthrough', ['3', '6'], opt.installmentCounts);

opt = SC.buildSdkOptions(CFG.ys_shopline_credit, SRV({ customerToken: 'tok-1' }));
eq('credit+token → bindCard rebuilt enable=true（回歸）', true, opt.paymentInstrument && opt.paymentInstrument.bindCard.enable);
eq('credit+token → switchVisible=true / defaultSwitch=false', true,
	opt.paymentInstrument.bindCard.protocol.switchVisible === true && opt.paymentInstrument.bindCard.protocol.defaultSwitchStatus === false);

opt = SC.buildSdkOptions(CFG.ys_shopline_credit, SRV({ customerToken: 'tok-1', paymentInstrument: { bindCard: { enable: true, protocol: { switchVisible: false } } } }));
eq('credit＋後端 paymentInstrument → passthrough（回歸）', false, opt.paymentInstrument.bindCard.protocol.switchVisible);

opt = SC.buildSdkOptions(CFG.ys_shopline_credit, SRV({
	customerToken: 'tok-1',
	sdkOptions: { paymentInstrument: { bindCard: { enable: true, protocol: { switchVisible: false } } } }
}));
eq('credit sdkOptions paymentInstrument remains supported', false, opt.paymentInstrument.bindCard.protocol.switchVisible);

opt = SC.buildSdkOptions(CFG.ys_shopline_credit_subscription, SRV({ customerToken: 'tok-1' }));
eq('subscription+token → forceSave：switchVisible=false / defaultSwitch=true（回歸）', true,
	opt.paymentInstrument.bindCard.protocol.switchVisible === false && opt.paymentInstrument.bindCard.protocol.defaultSwitchStatus === true);

opt = SC.buildSdkOptions(CFG.ys_shopline_atm, SRV({ customerToken: 'tok-1' }));
eq('ATM（即使誤帶 token）→ no paymentInstrument', false, 'paymentInstrument' in opt);

for (const gatewayId of [
	'ys_shopline_atm',
	'ys_shopline_jkopay',
	'ys_shopline_applepay',
	'ys_shopline_linepay',
	'ys_shopline_bnpl'
]) {
	opt = SC.buildSdkOptions(CFG[gatewayId], SRV({
		customerToken: 'stale-token',
		sdkOptions: {
			customerToken: 'filter-token',
			paymentInstrument: { bindCard: { enable: true } }
		}
	}));
	eq(gatewayId + ' strips stale customerToken after sdkOptions merge', false, 'customerToken' in opt);
	eq(gatewayId + ' strips stale paymentInstrument after sdkOptions merge', false, 'paymentInstrument' in opt);
}

opt = SC.buildSdkOptions(CFG.ys_shopline_credit_subscription, SRV({ customerToken: 'tok-1', amount: 0, bindOnlyMode: true }));
eq('bindOnlyMode amount 0 → 10100（回歸）', 10100, opt.amount);

// ---------- 3. 前端實際產生的 mode ----------
console.log('== getPaymentInstrumentSelection: frontend-produced mode ==');

const ITEMS_SEL = '[class*="shoplinepayments_item_"], [class*="_item_"]';
const MARK_SEL = 'svg, [class*="footer"]';

function containerWith(children, fullText) {
	const root = makeEl({ __children: children, __text: fullText || '' });
	return C([root]);
}
function savedItem(text, selected) {
	return makeEl({
		__text: text,
		__children: (function () {
			const m = {};
			m[MARK_SEL] = selected ? [makeEl({ getBoundingClientRect: function () { return { width: 20, height: 20 }; } })] : [];
			return m;
		})()
	});
}
function iframes(n) {
	const arr = [];
	for (let i = 0; i < n; i++) { arr.push(makeEl({ tagName: 'IFRAME', getBoundingClientRect: function () { return { width: 300, height: 40 }; } })); }
	return arr;
}
function checkbox(checked) { return makeEl({ tagName: 'INPUT', checked: !!checked }); }
function sdkSaveControl(active) {
	const checkboxKids = {};
	checkboxKids['[class*="active_"]'] = active ? [makeEl()] : [];
	const wrapKids = {};
	wrapKids['[class*="checkbox_"]'] = [makeEl({ __children: checkboxKids })];
	return makeEl({
		__text: '我同意記錄本次付款資訊，供後續交易使用。',
		__children: wrapKids
	});
}

// SDK 直接回 paymentInstrumentId（checkout 與 order-pay 皆此路徑）
let sel = SC.getPaymentInstrumentSelection({ paymentInstrumentId: 'pi-9' }, 'ys_shopline_credit_installment');
eq('SDK result.paymentInstrumentId → saved+id', true, sel.mode === 'saved' && sel.instrumentId === 'pi-9');

// 分期會員選既有卡（DOM 偵測；P1 核心：不再被 bindCard gate 擋掉）
let kids = {};
kids[ITEMS_SEL] = [savedItem('**** 1234 信用卡', true), savedItem('使用新卡 new card', false)];
kids.iframe = [];
kids['input[type="checkbox"]'] = [];
registry['#ys_shopline_credit_installment_container'] = containerWith(kids, '**** 1234 信用卡 使用新卡');
sel = SC.getPaymentInstrumentSelection(null, 'ys_shopline_credit_installment');
eq('installment 既有卡選取 → mode=saved（P1 核心）', true, sel.mode === 'saved' && sel.last4 === '1234');

// 分期新卡表單（iframe×2 可見）＋容器有勾選 checkbox → 仍必須是 new（P0 核心：永不 new_save）
kids = {};
kids[ITEMS_SEL] = [];
kids.iframe = iframes(2);
kids['input[type="checkbox"]'] = [checkbox(true)];
registry['#ys_shopline_credit_installment_container'] = containerWith(kids, '記錄本次付款資訊 儲存');
sel = SC.getPaymentInstrumentSelection(null, 'ys_shopline_credit_installment');
eq('installment 新卡＋勾選儲存樣態 → mode=new_save', 'new_save', sel.mode);

kids['input[type="checkbox"]'] = [checkbox(false)];
registry['#ys_shopline_credit_installment_container'] = containerWith(kids, '記錄本次付款資訊 儲存');
sel = SC.getPaymentInstrumentSelection(null, 'ys_shopline_credit_installment');
eq('installment 新卡未勾選 → mode=new', 'new', sel.mode);

// SHOPLINE 現行 SDK 使用自製 checkbox，沒有 input[type=checkbox]：
// off_* 必須是 new；只有 checkbox_* 內含 active_* 才是 new_save。
kids = {};
kids[ITEMS_SEL] = [];
kids.iframe = iframes(2);
kids['input[type="checkbox"]'] = [];
kids['[class*="wrap_"]'] = [sdkSaveControl(false)];
registry['#ys_shopline_credit_installment_container'] = containerWith(kids, '我同意記錄本次付款資訊，供後續交易使用。');
sel = SC.getPaymentInstrumentSelection(null, 'ys_shopline_credit_installment');
eq('installment SDK custom checkbox off → mode=new', 'new', sel.mode);

kids['[class*="wrap_"]'] = [sdkSaveControl(true)];
registry['#ys_shopline_credit_installment_container'] = containerWith(kids, '我同意記錄本次付款資訊，供後續交易使用。');
sel = SC.getPaymentInstrumentSelection(null, 'ys_shopline_credit_installment');
eq('installment SDK custom checkbox active → mode=new_save', 'new_save', sel.mode);

// 主卡同樣 DOM（checkbox 勾選）→ new_save（回歸：主卡行為不變）
kids['input[type="checkbox"]'] = [checkbox(true)];
registry['#ys_shopline_credit_container'] = containerWith(kids, '');
sel = SC.getPaymentInstrumentSelection(null, 'ys_shopline_credit');
eq('credit 新卡＋勾選 → mode=new_save（回歸）', 'new_save', sel.mode);

// 主卡未勾選 → new
kids = {};
kids[ITEMS_SEL] = [];
kids.iframe = iframes(2);
kids['input[type="checkbox"]'] = [checkbox(false)];
registry['#ys_shopline_credit_container'] = containerWith(kids, '');
sel = SC.getPaymentInstrumentSelection(null, 'ys_shopline_credit');
eq('credit 新卡未勾選 → mode=new（回歸）', 'new', sel.mode);

// 訂閱 forceSaveCard → new_save（回歸）
registry['#ys_shopline_credit_subscription_container'] = containerWith(kids, '');
sel = SC.getPaymentInstrumentSelection(null, 'ys_shopline_credit_subscription');
eq('subscription forceSave → mode=new_save（回歸）', 'new_save', sel.mode);

// 不支援既有卡的 gateway → regular（不進 DOM 偵測）
sel = SC.getPaymentInstrumentSelection(null, 'ys_shopline_atm');
eq('ATM → mode=regular', 'regular', sel.mode);

// 分期唯一既有卡（無 active 標記、無新卡表單）→ saved fallback
kids = {};
kids[ITEMS_SEL] = [];
kids.iframe = [];
kids['input[type="checkbox"]'] = [];
registry['#ys_shopline_credit_installment_container'] = containerWith(kids, '**** 5678');
sel = SC.getPaymentInstrumentSelection(null, 'ys_shopline_credit_installment');
eq('installment 唯一既有卡 fallback → saved+last4', true, sel.mode === 'saved' && sel.last4 === '5678');

// createPayment() may replace the selected-card DOM before the promise resolves.
// A pre-submit snapshot must survive unless the SDK returns a stronger instrument ID.
let resolved = SC.resolvePaymentInstrumentSelection(null, 'ys_shopline_credit_installment', { mode: 'saved', instrumentId: '', last4: '8405' });
eq('pre-create saved selection survives SDK DOM replacement', true, resolved.mode === 'saved' && resolved.last4 === '8405');
resolved = SC.resolvePaymentInstrumentSelection({ paymentInstrumentId: 'pi-77' }, 'ys_shopline_credit_installment', { mode: 'new', instrumentId: '', last4: '' });
eq('SDK instrument ID overrides pre-create snapshot', true, resolved.mode === 'saved' && resolved.instrumentId === 'pi-77');

// ---------- 4. 呼叫點契約（原始碼斷言）----------
console.log('== call-site contracts (source assertions) ==');
const payForOrderIdx = source.indexOf('var PayForOrderHandler');
verdict('order-pay handler 存在', payForOrderIdx > 0);
verdict('order-pay 走共用 getPaymentInstrumentSelection',
	source.indexOf('ShoplineCheckout.getPaymentInstrumentSelection', payForOrderIdx) > payForOrderIdx);
verdict('checkout 主流程走共用 getPaymentInstrumentSelection',
	/self\.getPaymentInstrumentSelection\(/.test(source));
verdict('renderPayment 走 buildSdkOptions（真實掛載同一 builder）',
	/buildSdkOptions\(gatewayConfig,\s*serverConfig\)/.test(source));
verdict('bind-card-enabled 容器旗標已移除', source.indexOf("data('bind-card-enabled'") === -1);
verdict('isBindCardEnabled 定義已移除', source.indexOf('isBindCardEnabled: function') === -1);

console.log('----');
console.log('RESULT: ' + pass + ' PASS / ' + fail + ' FAIL');
process.exit(fail > 0 ? 1 : 0);
