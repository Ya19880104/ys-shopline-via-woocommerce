/* global jQuery, ys_shopline_params, ShoplinePayments */
/**
 * Shopline Checkout SDK Handler
 *
 * Handles multiple independent payment gateways with embedded SDK.
 *
 * @package YangSheep\ShoplinePayment
 */
jQuery(function ($) {
    'use strict';

    /**
     * v3.5.34: 腳本單例守衛（防衛性）
     *
     * 部分客戶站的結帳外掛會以 AJAX 重繪含 <script> 的區塊，導致本腳本被重複執行。
     * 每次重複執行都會生出一套全新的 closure（paymentInstances / _isSubmitting 各自獨立），
     * 多套副本互搶 SDK 掛載且鎖互不相通。只允許第一份存活。
     */
    if (window.__ysShoplineCheckoutBooted) {
        console.warn('[YS Shopline] Duplicate script execution blocked by singleton guard');
        return;
    }
    window.__ysShoplineCheckoutBooted = true;

    /**
     * Gateway configurations mapping
     */
    var GATEWAY_CONFIG = {
        'ys_shopline_credit': {
            paymentMethod: 'CreditCard',
            containerId: 'ys_shopline_credit_container',
            supportsBindCard: true,
            supportsInstallment: false
        },
        'ys_shopline_credit_installment': {
            paymentMethod: 'CreditCard',
            containerId: 'ys_shopline_credit_installment_container',
            // Installment uses CreditCard SDK with installmentCounts; saved-card UI is required for QuickPayment.
            supportsBindCard: true,
            supportsInstallment: true
        },
        'ys_shopline_credit_subscription': {
            paymentMethod: 'CreditCard',
            containerId: 'ys_shopline_credit_subscription_container',
            supportsBindCard: true,
            forceSaveCard: true,
            supportsInstallment: false
        },
        'ys_shopline_atm': {
            paymentMethod: 'VirtualAccount',
            containerId: 'ys_shopline_atm_container',
            supportsBindCard: false,
            supportsInstallment: false
        },
        'ys_shopline_jkopay': {
            paymentMethod: 'JKOPay',
            containerId: 'ys_shopline_jkopay_container',
            supportsBindCard: false,
            supportsInstallment: false
        },
        'ys_shopline_applepay': {
            paymentMethod: 'ApplePay',
            containerId: 'ys_shopline_applepay_container',
            supportsBindCard: false,
            supportsInstallment: false
        },
        'ys_shopline_linepay': {
            paymentMethod: 'LinePay',
            containerId: 'ys_shopline_linepay_container',
            supportsBindCard: false,
            supportsInstallment: false
        },
        'ys_shopline_bnpl': {
            paymentMethod: 'ChaileaseBNPL',
            containerId: 'ys_shopline_bnpl_container',
            supportsBindCard: false,
            supportsInstallment: true
        }
    };

    /**
     * v3.5.0: 簡化 state management
     *
     * 單一狀態機：每個 gateway 只有 idle / loading / mounted 三態
     * 加一個 debounce timer 把 WooCommerce 連發的 updated_checkout event 折成一次 mount。
     *
     * 移除了 v3.4.19/20 累積的 sdkInitializing / sdkGeneration / ajaxGeneration /
     * $container.data 雙層 flag，避免守衛互相干擾導致 SDK 卡死。
     */
    var paymentInstances = {};       // { gatewayId: sdkPaymentObject } — SDK 成功後存
    var gatewayState = {};           // { gatewayId: 'idle' | 'loading' | 'mounted' }
    var pendingAjax = {};            // { gatewayId: jqXHR } — 供 abort
    var mountTimer = null;           // debounce timer
    var sdkLoaded = false;           // 全域 SDK JS 載入 flag

    /**
     * v3.5.2: runId + domVersion guard（Codex review F1）
     *
     * - domVersion：每次 WC updated_checkout 會 +1（WC 替換 #payment DOM 的訊號）
     * - activeRunId：每次 mount 會產生 unique id；若 run 中 state 被 reset 就能辨識出過期結果
     * - containerRef：mount 開始時記的 container DOM 指標，結束前比對確認沒被 DOM 替換
     *
     * 守衛放在三處：fetchSdkConfig 前後、ShoplinePayments() 前後、寫 paymentInstances 前。
     */
    var domVersion = 0;
    var runCounter = 0;
    var activeRuns = {};             // { gatewayId: { runId, domVersion, $container DOM } }
    var sdkConfigs = {};             // { gatewayId: serverConfig }

    /**
     * v3.5.34: WC 結帳更新生命週期追蹤（純被動 flag，無計時器）
     *
     * update_checkout（發起）→ update_order_review AJAX → updated_checkout（完成，DOM 可能已被替換）。
     * 正式站故障根因：自動填入觸發的更新在使用者按下單前後落地，替換付款 DOM 並換掉 SDK instance，
     * 導致 nextAction（3DS）永遠不啟動、交易卡在 CREATED。
     *
     * 防護設計 = 一條互斥線：
     *   起跑前（placeOrder gate）— 以「實體狀態」判斷可否起跑（更新不在途 + instance 存在 + iframe 活著），
     *   不依賴時間魔法數；未就緒則 defer 自動等待沉澱後續行。
     *   起跑後（onUpdatedCheckout 凍結 + instance 閉包直傳）— 付款到 nextAction 之間不受任何更新干擾。
     *
     * 實測依據（2026-07-11 HiNet+Cloudflare 正式站量測）：
     *   update_checkout→AJAX 發出 ~360ms、AJAX 往返 ~550ms、全程沉澱 ~0.9s（異常樣本 5.4s）；
     *   SHOPLINE create API 伺服器端 28 筆全數 <2s；SDK 重掛 ~2-3s。
     */
    var updateInFlight = false;      // update_checkout 已發起、updated_checkout 尚未回來
    var updateEventAt = 0;           // 最近一次 update_checkout 事件時間（flag 丟失自癒的 grace 基準）
    var lastUpdateSettledAt = 0;     // 上次 updated_checkout 完成時間（ms）
    var wcAjaxActive = [];           // 在途 wc-ajax jqXHR 集合（cart 突變不一定先發 update_checkout 事件；
                                     // 以真實 XHR + readyState 過濾自癒，不用會漂移的裸計數器）
    var SETTLE_QUIET_MS = 250;       // 更新剛完成後的靜默期：吸收連鎖更新的起手空檔（sc-icg 類外掛會鏈式觸發）
    var UPDATE_EVENT_GRACE_MS = 8000; // update_checkout 事件後多久內未觀察到對應 XHR 仍視為在途
                                      //（實測事件→AJAX 發出常態 ~360ms、異常樣本 5.4s；超過 grace 且無 XHR = 事件丟失，flag 自癒）
    var DEFER_TICK_MS = 150;         // defer 等待的檢查間隔（僅被 gate 攔下時啟動的有限輪詢，非常駐）
    var DEFER_TIMEOUT_MS = 5000;     // defer 檢查點：到點後看實體在途證據——慢而未壞就續等（fail-closed），不清狀態開閘
    var DEFER_HARD_LIMIT_MS = 20000; // defer 硬上限：慢站/CDN 最壞情境仍等不到沉澱 → 停止等待並提示重整（fail-closed，不自動送單）

    /**
     * Main Shopline Checkout Handler
     */
    var ShoplineCheckout = {

        /**
         * Initialize the checkout handler
         */
        init: function () {
            var self = this;

            console.log('[YS Shopline] Checkout handler initializing...');

            // Check SDK availability
            if (typeof ShoplinePayments !== 'undefined') {
                sdkLoaded = true;
                console.log('[YS Shopline] SDK already loaded');
            }

            // Bind events — 只在一般結帳頁（非 order-pay）
            // order-pay 由 PayForOrderHandler 獨立管理，避免雙重 handler
            if (window.location.pathname.indexOf('order-pay') === -1) {
                $(document.body).on('change', 'input[name="payment_method"]', function () {
                    self.onPaymentMethodChange();
                });

                $(document.body).on('updated_checkout', function () {
                    self.onUpdatedCheckout();
                });
            }

            // 重置提交 flag：只在 checkout_error（付款流程已明確失敗）時解鎖。
            // v3.5.34: 移除 updated_checkout 解鎖 — 提交進行中 WC 更新不該解開鎖，
            // 否則使用者可在 nextAction 處理期間再次送出造成雙重提交。
            $(document.body).on('checkout_error', function () {
                self._isSubmitting = false;
                // 提交期間被凍結略過的 DOM 更新，在失敗解鎖後補做一次重掛
                if (self._pendingRemount) {
                    self._pendingRemount = false;
                    self.onUpdatedCheckout();
                }
            });

            // v3.5.34: WC 結帳更新生命週期追蹤 — 純被動 flag，不操作任何 UI、無計時器。
            // 按鈕狀態唯一由 deferPlaceOrder 管理（點下單時才介入），避免多處搶控按鈕互相干擾。
            // updated_checkout 遺失（update AJAX 失敗）的 flag 卡死由 defer 逾時分支重置。
            $(document.body).on('update_checkout', function () {
                updateInFlight = true;
                updateEventAt = Date.now();
            });
            $(document.body).on('updated_checkout', function () {
                updateInFlight = false;
                lastUpdateSettledAt = Date.now();
            });

            // v3.5.34: 部分 cart 突變（WC 折價券、第三方外掛的加購/數量調整）只在自己的
            // AJAX 成功「之後」才 trigger update_checkout，事件 flag 看不到在途的前置請求。
            // 以全域 ajaxSend/ajaxComplete 維護在途 wc-ajax 的「真實 jqXHR 集合」補上盲區
            //（排除 wc-ajax=checkout 本身；ajaxComplete 於 error/abort 也會觸發；
            //  查詢時再以 readyState 過濾一次，殭屍條目自癒，不依賴逾時清零）。
            var isTrackedWcAjax = function (settings) {
                return settings && typeof settings.url === 'string' &&
                    settings.url.indexOf('wc-ajax=') !== -1 &&
                    settings.url.indexOf('wc-ajax=checkout') === -1;
            };
            $(document).ajaxSend(function (e, xhr, settings) {
                if (isTrackedWcAjax(settings)) wcAjaxActive.push(xhr);
            });
            $(document).ajaxComplete(function (e, xhr, settings) {
                if (!isTrackedWcAjax(settings)) return;
                var i = wcAjaxActive.indexOf(xhr);
                if (i !== -1) wcAjaxActive.splice(i, 1);
            });

            // Bind checkout events
            this.bindCheckoutEvents();

            // 不在此處呼叫 onPaymentMethodChange()：
            // WooCommerce 結帳頁一定會觸發 updated_checkout 事件，
            // 由 onUpdatedCheckout() 統一初始化 SDK，避免競態條件導致重複渲染。

            console.log('[YS Shopline] Checkout handler initialized');
        },

        /**
         * Get currently selected gateway
         *
         * @return {string|null} Gateway ID or null
         */
        getSelectedGateway: function () {
            var selected = $('input[name="payment_method"]:checked').val();

            if (selected && GATEWAY_CONFIG[selected]) {
                return selected;
            }

            return null;
        },

        /**
         * Bind checkout form events
         * 使用與 Helcim 相同的綁定方式
         */
        bindCheckoutEvents: function () {
            var self = this;

            // 解除舊的綁定，避免重複
            $('form.checkout').off('.ys_shopline');

            // Bind form submission for all Shopline gateways
            $.each(GATEWAY_CONFIG, function (gatewayId) {
                $('form.checkout').on('checkout_place_order_' + gatewayId + '.ys_shopline', function () {
                    console.log('[YS Shopline] checkout_place_order event triggered for:', gatewayId);
                    return self.placeOrder(gatewayId);
                });
            });

            console.log('[YS Shopline] Checkout events bound');
        },

        /**
         * v3.5.0: 事件入口統一呼叫 requestMount
         */
        onPaymentMethodChange: function () {
            var gatewayId = this.getSelectedGateway();
            if (!gatewayId) return;
            // 把其他 shopline gateway 的 container + state 清掉
            this.unmountOthers(gatewayId);
            this.requestMount(gatewayId);
        },

        /**
         * v3.5.0: WC DOM 更新後的處理
         *
         * WC 觸發 updated_checkout 可能因為運費/地址變化重新 render #payment 區塊。
         * 策略：
         * 1. 每個 gateway 狀態若為 mounted 但 DOM 已無 iframe → 視為 idle（被 WC 清了）
         * 2. 重新排程當前 selected gateway 的 mount（debounce）
         */
        onUpdatedCheckout: function () {
            // v3.5.34: 提交進行中 → 凍結生命週期（不作廢 runs、不清 instance、不重掛）。
            // WC 觸發本事件前 DOM 可能已被替換，但 SDK instance 物件存活於 JS heap，
            // 凍結可保證 nextAction 沿用原 instance 完成 3DS。被略過的更新記為 pending，
            // 付款失敗解鎖後（checkout_error / resetAllSubmitLocks）補做重掛。
            if (this._isSubmitting) {
                console.log('[YS Shopline] updated_checkout ignored: submission in progress');
                this._pendingRemount = true;
                return;
            }

            // v3.5.2: WC 可能已替換 DOM → 所有既有 runs 立刻失效
            domVersion++;
            activeRuns = {};
            // v3.5.2 fix: 既然舊 runs 都作廢，對應 loading 狀態也要 reset
            // 否則後續 requestMount 會被 "state==loading 不重複" 擋住，導致 SDK 永遠 mount 不上
            $.each(gatewayState, function (gw, st) {
                if (st === 'loading') gatewayState[gw] = 'idle';
            });

            this.bindCheckoutEvents();

            // WC 可能重建表單 → 清 paySession hidden input
            $('form.checkout').find('input[name="ys_shopline_pay_session"]').remove();

            // 檢查每個 gateway 的掛載是否還健康（卡片家族看 iframe、其他金流看 SDK 內容）；
            // 被 WC 替換掉的 mark 為 idle
            var self2 = this;
            $.each(GATEWAY_CONFIG, function (gatewayId) {
                if (gatewayState[gatewayId] !== 'mounted') return;
                if (!self2.isMountHealthy(gatewayId)) {
                    // WC 砍掉 DOM → 清狀態讓後續 requestMount 能重 mount
                    gatewayState[gatewayId] = 'idle';
                    delete paymentInstances[gatewayId];
                }
            });

            var gatewayId = this.getSelectedGateway();
            if (gatewayId) this.requestMount(gatewayId);
        },

        /**
         * v3.5.0: 清掉其他 gateway container 與 state
         *
         * @param {string} keepGatewayId 保留不動的 gateway id
         */
        unmountOthers: function (keepGatewayId) {
            $.each(GATEWAY_CONFIG, function (gatewayId, config) {
                if (gatewayId === keepGatewayId) return;
                if (gatewayState[gatewayId] === 'idle' || !gatewayState[gatewayId]) return;

                // abort 正在進行的 AJAX
                if (pendingAjax[gatewayId]) {
                    try { pendingAjax[gatewayId].abort(); } catch (e) {}
                    delete pendingAjax[gatewayId];
                }
                delete paymentInstances[gatewayId];
                gatewayState[gatewayId] = 'idle';

                var $c = $('#' + config.containerId);
                if ($c.length) $c.empty();
            });
        },

        /**
         * v3.5.0: 排程 mount（debounce 80ms）
         *
         * 80ms 視窗吸收 WC 連發的 updated_checkout（radio change 通常同時觸發
         * `change` + `update_order_review` AJAX → 多次 updated_checkout），
         * 只真正 mount 一次。
         */
        requestMount: function (gatewayId) {
            var self = this;
            clearTimeout(mountTimer);
            mountTimer = setTimeout(function () { self.doMount(gatewayId); }, 80);
        },

        /**
         * v3.5.0: 實際 mount SDK；v3.5.2 加入 runId/domVersion/containerEl guard
         *
         * 狀態機：
         *   idle    → 從 AJAX 取 config → mount → mounted
         *   loading → 已在進行中，不重複
         *   mounted → 若 DOM 還有 iframe 則 skip；否則降為 idle 重 mount
         *
         * v3.5.2: 每個 run 有 runId + domVersion + containerEl snapshot。
         *         任何 async 節點後都驗證 run 是否仍然有效（containerEl 還在 document 裡、
         *         domVersion 沒變、activeRuns[gw].runId 還是我）。
         */
        doMount: async function (gatewayId) {
            if (!GATEWAY_CONFIG[gatewayId]) return;
            var self = this;
            var config = GATEWAY_CONFIG[gatewayId];
            var $container = $('#' + config.containerId);
            if (!$container.length) return;

            // 已 mounted 且掛載健康（卡片家族看 iframe、其他金流看 SDK 內容）→ 不需動
            if (gatewayState[gatewayId] === 'mounted') {
                if (this.isMountHealthy(gatewayId)) return;
                // DOM 失效 → 降為 idle 繼續 mount
                gatewayState[gatewayId] = 'idle';
                delete paymentInstances[gatewayId];
            }

            // 已在 loading（AJAX 或 SDK mount 進行中）→ 不重複啟動
            if (gatewayState[gatewayId] === 'loading') return;

            // v3.5.2: 建立本 run 的身分標記
            var runId = ++runCounter;
            var runDomVersion = domVersion;
            var containerEl = $container.get(0);
            activeRuns[gatewayId] = { runId: runId, domVersion: runDomVersion, el: containerEl };

            // 判斷 run 是否還有效（container 還在 document、domVersion 沒變、還是當前 active run）
            var isRunValid = function () {
                var a = activeRuns[gatewayId];
                if (!a || a.runId !== runId) return false;
                if (a.domVersion !== domVersion) return false;
                if (!containerEl || !document.body.contains(containerEl)) return false;
                return true;
            };

            gatewayState[gatewayId] = 'loading';
            $container.html('<div class="ys-shopline-loading" style="text-align:center;padding:20px;"><span class="spinner is-active" style="float:none;"></span></div>');

            // 取 SDK config
            var serverConfig;
            try {
                serverConfig = await this.fetchSdkConfig(gatewayId);
            } catch (e) {
                if (e && e.aborted) return;
                if (isRunValid()) {
                    gatewayState[gatewayId] = 'idle';
                    self.showError($container, (e && e.message) || 'Configuration error');
                }
                return;
            }

            // v3.5.2: fetchSdkConfig 回來後驗證 run 仍有效
            if (!isRunValid()) {
                console.log('[YS Shopline] run invalidated after fetchSdkConfig:', gatewayId, 'runId=' + runId);
                return;
            }
            if (gatewayState[gatewayId] !== 'loading') return;
            if (this.getSelectedGateway() !== gatewayId) {
                gatewayState[gatewayId] = 'idle';
                $container.empty();
                return;
            }

            try {
                await this.renderPayment(gatewayId, serverConfig, { runId: runId, isRunValid: isRunValid });
                // renderPayment 成功後會 set paymentInstances[gatewayId]
                if (paymentInstances[gatewayId] && isRunValid()) {
                    gatewayState[gatewayId] = 'mounted';
                } else {
                    // renderPayment 內部 error 但沒 throw（已 showError），或 run 被作廢
                    if (isRunValid()) gatewayState[gatewayId] = 'idle';
                }
            } catch (e) {
                console.error('[YS Shopline] doMount error:', e);
                if (isRunValid()) {
                    gatewayState[gatewayId] = 'idle';
                    self.showError($container, (e && e.message) || 'SDK initialization failed');
                }
            }
        },

        /**
         * v3.5.0: 從 server 取 SDK config（Promise）
         */
        fetchSdkConfig: function (gatewayId) {
            var self = this;
            return new Promise(function (resolve, reject) {
                var ajaxData = {
                    action: 'ys_shopline_get_sdk_config',
                    nonce: ys_shopline_params.nonce,
                    gateway: gatewayId
                };

                var orderPayMatch = window.location.pathname.match(/order-pay\/(\d+)/);
                if (orderPayMatch) {
                    ajaxData.order_id = orderPayMatch[1];
                    var urlParams = new URLSearchParams(window.location.search);
                    if (urlParams.get('key')) ajaxData.order_key = urlParams.get('key');
                    if (urlParams.get('change_payment_method')) ajaxData.is_change_payment_method = '1';
                }

                // abort 同 gateway 舊的 request
                if (pendingAjax[gatewayId]) {
                    try { pendingAjax[gatewayId].abort(); } catch (e) {}
                }

                pendingAjax[gatewayId] = $.ajax({
                    type: 'POST',
                    url: ys_shopline_params.ajax_url,
                    data: ajaxData,
                    success: function (response) {
                        delete pendingAjax[gatewayId];
                        if (response.success) {
                            resolve(response.data);
                        } else {
                            var msg = (response.data && response.data.message) ||
                                ys_shopline_params.i18n.config_error || 'Configuration error';
                            reject(new Error(msg));
                        }
                    },
                    error: function (jqXHR, textStatus) {
                        delete pendingAjax[gatewayId];
                        if (textStatus === 'abort') {
                            var err = new Error('aborted');
                            err.aborted = true;
                            reject(err);
                        } else {
                            var errMsg = (ys_shopline_params.i18n.connection_error || 'Connection error') + ': ' + textStatus;
                            reject(new Error(errMsg));
                        }
                    }
                });
            });
        },

        /**
         * v3.5.0 相容介面：保留 initSDK 方法名給外部呼叫（PayForOrderHandler 等）
         * 內部轉呼叫 requestMount → doMount 新流程
         *
         * @param {string} gatewayId Gateway ID
         */
        initSDK: function (gatewayId) {
            if (gatewayId) this.requestMount(gatewayId);
        },

        /**
         * Render payment SDK
         *
         * v3.5.0: 由 doMount 呼叫，不再直接由 initSDK 呼叫
         * v3.5.2: 增加 runGuard 參數（doMount 傳入 runId / isRunValid）
         *
         * @param {string} gatewayId Gateway ID
         * @param {Object} serverConfig Server configuration
         * @param {Object} [runGuard] { runId, isRunValid } — v3.5.2 跨 async 驗證
         */
        renderPayment: async function (gatewayId, serverConfig, runGuard) {
            var self = this;
            var gatewayConfig = GATEWAY_CONFIG[gatewayId];
            var $container = $('#' + gatewayConfig.containerId);
            sdkConfigs[gatewayId] = serverConfig || {};

            // v3.5.0: doMount 已檢查重複呼叫；renderPayment 只需要最基本 validation
            if (!sdkLoaded && typeof ShoplinePayments === 'undefined') {
                self.showError($container, ys_shopline_params.i18n.sdk_not_loaded || 'Payment SDK not loaded');
                return;
            }
            sdkLoaded = true;

            if (!serverConfig.clientKey || !serverConfig.merchantId) {
                self.showError($container, ys_shopline_params.i18n.missing_config || 'Missing configuration');
                return;
            }

            // 清掉 loading spinner，準備給 SDK mount
            $container.empty();

            try {
                // Build SDK options
                // Note: env 參數控制環境 (sandbox/production)
                // bindOnlyMode: true 時（如 $0 訂閱試用），SDK 與 API 都拒絕 amount=0
                // 後端會以 CardBind + amount=10000 處理（對齊官方 CardBind 範例，銀行只授權不請款）
                var sdkAmount = serverConfig.amount || 0;
                if (serverConfig.bindOnlyMode && sdkAmount <= 0) {
                    sdkAmount = 10100; // TWD $100 對齊官方 CardBind 範例（僅授權，不請款）
                }
                var options = {
                    clientKey: serverConfig.clientKey,
                    merchantId: serverConfig.merchantId,
                    paymentMethod: gatewayConfig.paymentMethod,
                    currency: serverConfig.currency || 'TWD',
                    amount: sdkAmount,
                    element: '#' + gatewayConfig.containerId,
                    env: serverConfig.env || 'production'
                };

                // Add customer token if available (for saved cards)
                if (serverConfig.customerToken) {
                    options.customerToken = serverConfig.customerToken;
                }

                // Configure card binding for credit card gateways
                // 只有在有 customerToken 時才啟用儲存卡片功能
                // 後端已經根據登入狀態決定是否傳 paymentInstrument
                if (serverConfig.paymentInstrument) {
                    // 使用後端傳來的配置（v3.5.2: 不 log raw object，避免暴露 protocol 細節）
                    options.paymentInstrument = serverConfig.paymentInstrument;
                } else if (gatewayConfig.supportsBindCard && serverConfig.customerToken) {
                    // 如果後端沒傳但有 customerToken，使用預設配置
                    var forceSave = gatewayConfig.forceSaveCard || serverConfig.forceSaveCard || false;

                    options.paymentInstrument = {
                        bindCard: {
                            enable: true,
                            protocol: {
                                // 強制儲存時隱藏開關，否則顯示讓用戶選擇
                                switchVisible: !forceSave,
                                // 強制儲存時預設開啟，否則預設關閉
                                defaultSwitchStatus: forceSave,
                                // 不強制要求同意儲存，允許不勾選也能交易
                                mustAccept: false
                            }
                        }
                    };
                }
                // 沒有 customerToken 時不設定 paymentInstrument（訪客不能儲存卡片）

                // Configure installment for supported gateways
                // SDK 文件使用 installmentCounts 參數（可以是字串或數字陣列）
                if (gatewayConfig.supportsInstallment && serverConfig.installmentCounts) {
                    options.installmentCounts = serverConfig.installmentCounts;
                }

                // Apply any additional server-side options
                if (serverConfig.sdkOptions) {
                    options = $.extend(true, options, serverConfig.sdkOptions);
                }

                // 儲存是否啟用綁卡功能（供後續 isBindCardEnabled 使用）
                $container.data('bind-card-enabled', !!options.paymentInstrument);

                // Debug: Log SDK options (without sensitive data)
                console.log('Shopline SDK Init:', {
                    merchantId: options.merchantId ? options.merchantId.substring(0, 8) + '...' : '(empty)',
                    clientKey: options.clientKey ? options.clientKey.substring(0, 12) + '...' : '(empty)',
                    paymentMethod: options.paymentMethod,
                    env: options.env,
                    amount: options.amount,
                    currency: options.currency,
                    element: options.element,
                    hasBindCard: !!options.paymentInstrument
                });

                // v3.5.0/5.2: mount 前再 verify 這個 gateway 還是 loading 狀態
                if (gatewayState[gatewayId] !== 'loading') {
                    console.log('[YS Shopline] mount cancelled (state changed) for:', gatewayId);
                    $container.empty();
                    return;
                }
                if (runGuard && typeof runGuard.isRunValid === 'function' && !runGuard.isRunValid()) {
                    console.log('[YS Shopline] mount cancelled (run invalidated) for:', gatewayId);
                    return;
                }

                // Initialize SDK
                var result = await ShoplinePayments(options);

                // v3.5.0/5.2: mount 完成後再次 verify + runGuard
                if (gatewayState[gatewayId] !== 'loading' || self.getSelectedGateway() !== gatewayId) {
                    console.log('[YS Shopline] mount result discarded (gateway switched) for:', gatewayId);
                    try { $container.empty(); } catch (e) {}
                    return;
                }
                if (runGuard && typeof runGuard.isRunValid === 'function' && !runGuard.isRunValid()) {
                    console.log('[YS Shopline] mount result discarded (run invalidated, DOM may have changed) for:', gatewayId);
                    try { $container.empty(); } catch (e) {}
                    return;
                }

                if (result.error) {
                    // v3.5.2: 不 log raw result object（含 SDK 內部資訊可能洩漏 paySession 片段）
                    console.error('[YS Shopline] SDK error', { code: result.error.code, message: result.error.message });
                    var errorCode = result.error.code;
                    var friendlyMsg = self.getFriendlySDKError(errorCode, gatewayConfig.paymentMethod);
                    if (friendlyMsg) {
                        self.showFriendlyNotice($container, friendlyMsg);
                    } else {
                        var errorMessage = result.error.message || 'Unknown error';
                        self.showError($container, errorMessage + (errorCode ? ' (' + errorCode + ')' : ''));
                    }
                    return;
                }

                // 成功：存 instance（狀態由 doMount 升為 mounted）
                paymentInstances[gatewayId] = result.payment;
                console.log('[YS Shopline] Payment instance stored for:', gatewayId);

                // Remove loading state - SDK should have rendered its content
                $container.find('.ys-shopline-loading').remove();

                // v3.5.8: SDK render 完後 best-effort 模擬點擊預設卡
                // 原本 SDK render 出 saved cards 但不自動 select，使用者必須手動點 →
                // 容易 submit 後才發現沒選卡或選錯卡。auto-select 讓最常用的情境（預設卡）一鍵完成。
                if (gatewayId === 'ys_shopline_credit') {
                    self._tryAutoSelectDefaultCard(gatewayId);
                }

                // v3.5.9: 一般 CC checkout 上 SDK 自動顯示「將依本次交易所使用之付款資訊，進行後續定期扣款」
                // 文案，但這對非訂閱訂單是誤導（一般訂單不會自動扣後續款項）。只在非 subscription gateway hide。
                if (gatewayId !== 'ys_shopline_credit_subscription') {
                    self._hideMisleadingRecurringHint(gatewayId);
                }

                // bindOnlyMode（$0 訂閱試用）：將 PHP 渲染的綁卡提示改為試用期版本
                if (serverConfig.bindOnlyMode) {
                    var $hint = $('.ys-bindcard-hint-subscription[data-gateway="' + gatewayId + '"]');
                    if ($hint.length) {
                        $hint.find('.ys-bindcard-hint-body').text(
                            '本訂閱含免費試用期，試用期間不會扣款。試用結束後將依訂閱方案自動扣款。' +
                            '綁卡過程中銀行可能進行小額授權驗證，驗證金額將於完成後自動解除。'
                        );
                        $hint.find('strong').text('ℹ️ 試用期綁卡驗證');
                    }
                }

                // For Apple Pay, check if button was rendered (device support)
                if (gatewayConfig.paymentMethod === 'ApplePay') {
                    setTimeout(function() {
                        // Check if SDK rendered any content (iframe, button, etc.)
                        var $sdkContent = $container.find('iframe, button, .apple-pay-button, [class*="apple"], [class*="shopline"]');
                        var containerText = $container.text().trim();
                        var hasVisibleContent = $sdkContent.length > 0 || containerText.length > 50;

                        console.log('Apple Pay check:', {
                            sdkElements: $sdkContent.length,
                            textLength: containerText.length,
                            hasContent: hasVisibleContent
                        });

                        if (!hasVisibleContent) {
                            $container.html(
                                '<div class="ys-shopline-applepay-unsupported" style="text-align: center; padding: 15px; color: #666; background: #f9f9f9; border-radius: 4px;">' +
                                '<p style="margin: 0;">' +
                                (ys_shopline_params.i18n.applepay_unsupported || '此裝置或瀏覽器不支援 Apple Pay。請使用 iPhone/iPad/Mac 上的 Safari 瀏覽器。') +
                                '</p></div>'
                            );
                        }
                    }, 2000); // Give SDK more time to render
                }

                // Trigger custom event for extensions
                $(document.body).trigger('ys_shopline_sdk_ready', [gatewayId, result.payment]);

            } catch (e) {
                console.error('Shopline SDK Exception:', e);
                self.showError($container, e.message || 'SDK initialization failed');
                throw e; // 讓 doMount catch 到，改狀態為 idle
            }
        },

        /**
         * Validate Taiwan phone number format (09XXXXXXXX)
         *
         * @param {string} phone Phone number
         * @return {boolean} Whether the phone is valid
         */
        validateTaiwanPhone: function (phone) {
            if (!phone) {
                return false;
            }
            // 移除所有非數字字元
            var cleaned = phone.replace(/\D/g, '');
            // 台灣手機格式：09 開頭，共 10 碼
            var pattern = /^09\d{8}$/;
            return pattern.test(cleaned);
        },

        /**
         * v3.5.34: 結帳頁是否處於「不可送單」狀態
         *
         * 條件（實體狀態優先）：WC 更新事件在途、wc-ajax 請求在途（cart 突變的前置 AJAX）、
         * 上次更新完成未滿靜默期（吸收連鎖更新起手空檔）、SDK instance 不存在、
         * 或 container 內 iframe 已被 DOM 替換移除。
         *
         * @param {string} gatewayId Gateway ID
         * @return {boolean}
         */
        /**
         * v3.5.34: 下單按鈕共用鎖（window 級引用計數，與 YS 結帳優化外掛同協定）。
         *
         * 「各自記取得前狀態」在雙持有情境會互解（A 持鎖期間 B 取鎖，A 先釋放
         * 會 enable 掉 B 的鎖）→ 以 window.__ysPlaceOrderLocks 引用計數：
         * count 歸零才考慮 enable，且尊重第一位取鎖者記下的「非參與者已 disable」。
         */
        acquirePlaceOrderLock: function () {
            if (!window.__ysPlaceOrderLocks) {
                window.__ysPlaceOrderLocks = { count: 0, externalDisabled: false };
            }
            var reg = window.__ysPlaceOrderLocks;
            var $btn = $('#place_order');
            if (reg.count === 0) {
                reg.externalDisabled = $btn.length ? $btn.prop('disabled') === true : false;
            }
            reg.count++;
            $btn.prop('disabled', true).attr('aria-busy', 'true');
        },

        releasePlaceOrderLock: function () {
            var reg = window.__ysPlaceOrderLocks;
            if (!reg || reg.count === 0) return;
            reg.count--;
            if (reg.count === 0) {
                var $btn = $('#place_order');
                $btn.removeAttr('aria-busy');
                if (!reg.externalDisabled) {
                    $btn.prop('disabled', false);
                }
            }
        },

        /**
         * v3.5.34: 在途 wc-ajax 數（readyState 過濾自癒後）
         */
        wcAjaxInFlightCount: function () {
            // readyState 4 = 完成、0 = 已 abort（abort 後停在 0，不是在途）；
            // ajaxComplete 於 abort 也會移除，此過濾為第二道保險
            wcAjaxActive = wcAjaxActive.filter(function (x) { return x && x.readyState !== 4 && x.readyState !== 0; });
            return wcAjaxActive.length;
        },

        /**
         * v3.5.34: 是否仍有「結帳更新活動」的實體在途證據。
         *
         * - wc-ajax XHR 在飛 → 明確在途。
         * - update_checkout 事件 flag 為 true：事件到實際發出 XHR 有排程延遲
         *   （實測常態 ~360ms、異常 5.4s），grace 期內視為在途；
         *   超過 grace 且無任何 XHR = updated_checkout 丟失 → flag 自癒歸位。
         */
        hasCheckoutActivityEvidence: function () {
            if (this.wcAjaxInFlightCount() > 0) return true;
            if (updateInFlight) {
                if (Date.now() - updateEventAt < UPDATE_EVENT_GRACE_MS) return true;
                updateInFlight = false; // 事件丟失自癒（有 XHR 的情況已在上面攔住）
            }
            return false;
        },

        /**
         * v3.5.34: SDK 掛載是否健康（統一判斷，三處共用：onUpdatedCheckout / doMount / isCheckoutBusy）。
         *
         * 卡片家族（paymentMethod=CreditCard：信用卡/分期/訂閱）有兩種健康形態：
         *   1. 新卡表單 → 渲染 PCI iframe
         *   2. 已存卡快速結帳 → 渲染 saved-card 列表（class 含 shoplinepayments_item_，見
         *      _tryAutoSelectDefaultCard），**此形態沒有 iframe**
         * v3.5.35 Review P1-3：原本只認 iframe，會員已存卡情境（列表已正常渲染但 iframe=0）
         * 被誤判不健康 → 主結帳 placeOrder 走 isCheckoutBusy→defer→5 秒 force remount→紅字
         * 「付款元件重新載入中」，等於擋掉已存卡快速結帳。改為「iframe 或 saved-card 列表
         * 任一存在」皆健康；只有容器空、只剩本外掛 loading spinner、或 DOM 被替換才不健康。
         *
         * ATM / LINE Pay / Apple Pay / JKO / BNPL 只渲染 DIV 或按鈕——
         * 對這些金流要求 iframe 會讓 gate 永遠 busy、完全無法下單（v3.5.34 首版實測 regression）。
         * 非卡金流的健康條件＝instance 存在＋容器仍在 DOM＋SDK 已渲染內容（排除 loading spinner）。
         *
         * @param {string} gatewayId Gateway ID
         * @return {boolean}
         */
        isMountHealthy: function (gatewayId) {
            if (!paymentInstances[gatewayId]) return false;
            var config = GATEWAY_CONFIG[gatewayId] || {};
            var $c = $('#' + config.containerId);
            if (!$c.length) return false;
            if (config.paymentMethod === 'CreditCard') {
                // 新卡表單 iframe，或已存卡列表（shoplinepayments_item_）任一存在即健康
                return $c.find('iframe').length > 0
                    || $c.find('[class*="shoplinepayments_item_"]').length > 0;
            }
            return $c.children().not('.ys-shopline-loading').length > 0;
        },

        isCheckoutBusy: function (gatewayId) {
            if (this.hasCheckoutActivityEvidence()) return true;
            if (Date.now() - lastUpdateSettledAt < SETTLE_QUIET_MS) return true;
            if (!this.isMountHealthy(gatewayId)) return true;
            return false;
        },

        /**
         * v3.5.34: 等待結帳更新沉澱後自動續行下單
         *
         * 每 150ms 檢查一次（僅在被 gate 攔下時啟動的有限輪詢，非常駐），
         * 最多等 5 秒。沉澱後自動重新進入 placeOrder；逾時則主動重掛 SDK 並提示重試。
         * 期間鎖定下單按鈕避免重複點擊。
         *
         * @param {string} gatewayId Gateway ID
         */
        deferPlaceOrder: function (gatewayId) {
            var self = this;
            if (self._deferTimer) return; // 已在等待中
            var startedAt = Date.now();
            var slowNotified = false;
            // 共用鎖 registry：取得/釋放走引用計數，雙持有（結帳優化突變鎖同時在場）不互解
            self.acquirePlaceOrderLock();

            var finish = function () {
                self._deferTimer = null;
                self.releasePlaceOrderLock();
            };

            var tick = function () {
                self._deferTimer = null;
                // 使用者已改選其他付款方式 → 放棄本次續行
                if (self.getSelectedGateway() !== gatewayId) {
                    finish();
                    return;
                }
                if (!self.isCheckoutBusy(gatewayId)) {
                    finish();
                    console.log('[YS Shopline] Checkout settled after ' + (Date.now() - startedAt) + 'ms, resuming placeOrder');
                    self.placeOrder(gatewayId);
                    return;
                }
                if (Date.now() - startedAt > DEFER_TIMEOUT_MS) {
                    // 檢查點（fail-closed）：仍有實體在途證據（XHR 在飛 / 事件 grace 內）＝慢而未壞
                    // → 不清狀態、不放行，續等真正的完成信號（慢站/CDN 正是最需要保護的時候）
                    if (self.hasCheckoutActivityEvidence()) {
                        if (Date.now() - startedAt > DEFER_HARD_LIMIT_MS) {
                            finish();
                            console.warn('[YS Shopline] Checkout activity still in flight after ' + DEFER_HARD_LIMIT_MS + 'ms, giving up (fail-closed, no auto-submit)');
                            self.showFormError('結帳更新回應逾時，請重新整理頁面後再試。');
                            return;
                        }
                        if (!slowNotified) {
                            slowNotified = true;
                            console.warn('[YS Shopline] Checkout update slow (>' + DEFER_TIMEOUT_MS + 'ms), keep waiting (fail-closed)');
                        }
                        self._deferTimer = setTimeout(tick, DEFER_TICK_MS);
                        return;
                    }
                    // 無任何在途證據（flag 已於證據檢查中自癒）：剩下的 busy 只可能是掛載本體問題
                    finish();
                    if (!self.isCheckoutBusy(gatewayId)) {
                        console.log('[YS Shopline] Checkout settled at timeout checkpoint, resuming placeOrder');
                        self.placeOrder(gatewayId);
                        return;
                    }
                    // instance 缺失 / iframe 被移除 / state 卡在 loading，且無在途請求 → 真壞死，強制重掛
                    console.warn('[YS Shopline] Mount broken with no in-flight activity, force remounting SDK');
                    self.forceRemount(gatewayId);
                    self.showFormError('付款元件重新載入中，請稍候幾秒後再按一次「下單」。');
                    return;
                }
                self._deferTimer = setTimeout(tick, DEFER_TICK_MS);
            };
            self._deferTimer = setTimeout(tick, DEFER_TICK_MS);
        },

        /**
         * v3.5.34: 強制重置該 gateway 的掛載狀態機後重掛。
         *
         * requestMount → doMount 遇到 state 卡在 'loading'（config AJAX 懸掛、
         * run 被作廢但 state 未復位）會直接 return 而永遠掛不回來——
         * 此方法先歸零狀態再重掛，是 defer 逾時路徑專用的脫困出口。
         *
         * @param {string} gatewayId Gateway ID
         */
        forceRemount: function (gatewayId) {
            if (pendingAjax[gatewayId]) {
                try { pendingAjax[gatewayId].abort(); } catch (e) {}
                delete pendingAjax[gatewayId];
            }
            delete activeRuns[gatewayId];
            delete paymentInstances[gatewayId];
            gatewayState[gatewayId] = 'idle';
            this.requestMount(gatewayId);
        },

        /**
         * v3.5.35: 等待指定 gateway 掛載健康後續行（order-pay 重新付款頁使用）。
         *
         * 切換付款方式後 SDK 掛載需時（debounce 80ms + config AJAX + SDK render），
         * 手快的使用者按下付款時 instance 還不存在。過去直接 alert 斷頭；
         * 改以 DEFER_TICK_MS 輪詢 isMountHealthy：
         * - 健康 → onReady()（呼叫端自動續行付款）
         * - 使用者又切換金流 → onFail('gateway_switched')（新金流由 change handler 接手）
         * - DEFER_TIMEOUT_MS 仍未健康 → forceRemount 自癒一次後繼續等
         * - DEFER_HARD_LIMIT_MS → onFail('timeout')（fail-closed，絕不自動送單）
         *
         * @param {string}   gatewayId Gateway ID
         * @param {Function} onReady   掛載健康時呼叫
         * @param {Function} onFail    放棄等待時呼叫（參數 'gateway_switched' | 'timeout'）
         */
        waitForMountThenContinue: function (gatewayId, onReady, onFail) {
            var self = this;
            var startedAt = Date.now();
            var forcedRemount = false;

            var tick = function () {
                if (self.getSelectedGateway() !== gatewayId) {
                    onFail('gateway_switched');
                    return;
                }
                if (self.isMountHealthy(gatewayId)) {
                    onReady();
                    return;
                }

                var elapsed = Date.now() - startedAt;
                if (elapsed >= DEFER_HARD_LIMIT_MS) {
                    onFail('timeout');
                    return;
                }
                if (elapsed >= DEFER_TIMEOUT_MS && !forcedRemount) {
                    // 掛載卡死（config AJAX 懸掛/run 作廢未復位）→ 自癒一次後繼續等
                    forcedRemount = true;
                    console.warn('[YS Shopline] waitForMount: mount unhealthy after ' + elapsed + 'ms, forcing remount:', gatewayId);
                    self.forceRemount(gatewayId);
                }
                setTimeout(tick, DEFER_TICK_MS);
            };

            tick();
        },

        /**
         * Handle order placement
         *
         * @param {string} gatewayId Gateway ID
         * @return {boolean} Whether to proceed with form submission
         */
        placeOrder: function (gatewayId) {
            var self = this;
            var $form = $('form.checkout');
            var gatewayConfig = GATEWAY_CONFIG[gatewayId] || {};

            // 防呆：防止重複提交（瀏覽器慢、連點等情況）
            if (self._isSubmitting) {
                console.log('[YS Shopline] Duplicate submission blocked');
                return false;
            }

            console.log('[YS Shopline] placeOrder called for:', gatewayId);

            // 驗證帳單電話格式（台灣手機 09XXXXXXXX）
            var billingPhone = $('#billing_phone').val();
            var shippingPhone = $('#shipping_phone').val();
            var countryField = $('#billing_country').val();

            // 只有台灣需要驗證手機格式
            if (countryField === 'TW') {
                var phoneToValidate = billingPhone || shippingPhone;

                if (!phoneToValidate) {
                    self.showFormError('請填寫帳單電話號碼，Shopline 付款需要此資訊。');
                    $('#billing_phone').focus();
                    return false;
                }

                if (!self.validateTaiwanPhone(phoneToValidate)) {
                    self.showFormError('請輸入正確的台灣手機號碼格式（09XXXXXXXX，共 10 碼）。');
                    $('#billing_phone').focus();
                    return false;
                }
            }

            // Check if paySession already exists (means SDK already processed)
            var existingPaySession = $form.find('input[name="ys_shopline_pay_session"]').val();
            if (existingPaySession) {
                console.log('[YS Shopline] paySession exists, allowing WooCommerce to process');
                return true; // Let WooCommerce handle the submission
            }

            // v3.5.34: 結帳更新沉澱 gate — 更新進行中 / 剛完成（SDK 可能重掛中）/ instance 或 iframe 尚未就緒
            // 一律先等待沉澱再自動續行，把「客人手動等幾秒再按就不會錯」自動化、確定化。
            if (this.isCheckoutBusy(gatewayId)) {
                console.log('[YS Shopline] Checkout busy (update in flight / SDK remounting), deferring submission');
                this.deferPlaceOrder(gatewayId);
                return false;
            }

            var paymentInstance = paymentInstances[gatewayId];
            console.log('[YS Shopline] paymentInstance:', paymentInstance);

            if (!paymentInstance) {
                // Instance not ready, show error
                console.error('[YS Shopline] Payment instance not found for:', gatewayId);
                var errorMsg = ys_shopline_params.i18n.payment_not_ready || 'Payment not ready. Please wait and try again.';
                self.showFormError(errorMsg);
                return false;
            }

            // 設定提交中 flag + Block UI
            self._isSubmitting = true;
            $form.addClass('processing').block({
                message: null,
                overlayCSS: {
                    background: '#fff',
                    opacity: 0.6
                }
            });

            console.log('Calling createPayment...');

            // Create payment via SDK
            // v3.5.2: 移除 raw paySession / result console log（Codex review F2）
            // paySession 是付款 session material，在 production devtools / support screenshot
            // 暴露會造成 replay / information leak 風險。只記 meta（keys/length/hash）。
            paymentInstance.createPayment().then(function (result) {
                var resultKeys = result ? Object.keys(result) : [];
                console.log('[YS Shopline] createPayment returned, keys:', resultKeys);

                if (result.error) {
                    var msg = result.error.message || ys_shopline_params.i18n.payment_failed || 'Payment failed';
                    console.error('[YS Shopline] createPayment error:', { code: result.error.code, message: result.error.message });
                    self._isSubmitting = false;
                    $form.removeClass('processing').unblock();
                    self.showFormError(msg);
                    return false;
                }

                // Check if paySession exists
                if (!result.paySession) {
                    console.error('[YS Shopline] createPayment: No paySession in result, keys=', resultKeys);
                    self._isSubmitting = false;
                    $form.removeClass('processing').unblock();
                    self.showFormError('付款資訊建立失敗，請重新輸入卡片資訊。');
                    return false;
                }

                // Success - add paySession to form
                // paySession 可能是物件或字串，確保傳遞正確格式
                var paySessionValue = result.paySession;
                if (typeof paySessionValue === 'object') {
                    paySessionValue = JSON.stringify(paySessionValue);
                }
                // v3.5.2: 只 log type + length（不記 value）
                console.log('[YS Shopline] paySession ready, type=' + (typeof result.paySession) + ', length=' + (paySessionValue ? paySessionValue.length : 0));

                $form.find('input[name="ys_shopline_pay_session"]').remove();
                $form.append(
                    $('<input>').attr({
                        type: 'hidden',
                        name: 'ys_shopline_pay_session',
                        value: paySessionValue
                    })
                );

                // Add selected installment if applicable
                var selectedInstallment = gatewayConfig.supportsInstallment ? self.getSelectedInstallment(result, gatewayId) : '';
                if (selectedInstallment) {
                    var installmentFieldName = gatewayId === 'ys_shopline_bnpl' ? 'ys_shopline_bnpl_installment' : 'ys_shopline_installment';
                    $form.find('input[name="ys_shopline_installment"], input[name="ys_shopline_bnpl_installment"]').remove();
                    $form.append(
                        $('<input>').attr({
                            type: 'hidden',
                            name: installmentFieldName,
                            value: selectedInstallment
                        })
                    );
                }

                // Add selected payment instrument if applicable
                // 驗證 SDK 是否真的帶 paymentInstrumentId（用戶選已綁卡時）
                var instrumentSelection = self.getPaymentInstrumentSelection(result, gatewayId);
                if (instrumentSelection.instrumentId) {
                    console.log('[YS Shopline] selected paymentInstrumentId, last6=' + String(instrumentSelection.instrumentId).slice(-6));
                    $form.find('input[name="ys_shopline_payment_instrument_id"]').remove();
                    $form.append(
                        $('<input>').attr({
                            type: 'hidden',
                            name: 'ys_shopline_payment_instrument_id',
                            value: instrumentSelection.instrumentId
                        })
                    );
                } else {
                    console.log('[YS Shopline] payment instrument mode:', instrumentSelection.mode, 'last4:', instrumentSelection.last4 || '');
                }

                $form.find('input[name="ys_shopline_payment_instrument_mode"]').remove();
                $form.append(
                    $('<input>').attr({
                        type: 'hidden',
                        name: 'ys_shopline_payment_instrument_mode',
                        value: instrumentSelection.mode
                    })
                );

                $form.find('input[name="ys_shopline_saved_card_last4"]').remove();
                if (instrumentSelection.last4) {
                    $form.append(
                        $('<input>').attr({
                            type: 'hidden',
                            name: 'ys_shopline_saved_card_last4',
                            value: instrumentSelection.last4
                        })
                    );
                }

                // Add bind card enabled flag
                // 重要：SDK createPayment() 只返回 { paySession, error }
                // paySession 已經包含了用戶的所有選擇（包括是否儲存卡片）
                // 後端需要知道 SDK 是否啟用了綁卡功能，以決定 paymentBehavior
                //
                // 根據 SHOPLINE 文件：
                // - 如果 SDK 有啟用 bindCard，且用戶勾選了儲存卡片，paySession 會包含這個資訊
                // - 後端應該設定 paymentBehavior = CardBindPayment + savePaymentInstrument = true
                // - SDK 和 API 會根據 paySession 中的用戶選擇來決定是否實際儲存卡片
                //
                // 所以我們只需要告訴後端「SDK 有啟用綁卡功能」
                var bindCardEnabled = instrumentSelection.mode === 'new_save';

                console.log('[YS Shopline] bindCardEnabled:', bindCardEnabled);

                $form.find('input[name="ys_shopline_bind_card_enabled"]').remove();
                $form.append(
                    $('<input>').attr({
                        type: 'hidden',
                        name: 'ys_shopline_bind_card_enabled',
                        value: bindCardEnabled ? '1' : '0'
                    })
                );

                // 收集並添加裝置資訊（3DS/風控所需）
                self.appendClientInfo($form);

                console.log('[YS Shopline] paySession saved, submitting to WooCommerce via AJAX...');

                // 直接發送 AJAX 到 WooCommerce checkout endpoint
                // v3.5.34: 把建立 paySession 的 instance 一路傳下去 — nextAction 必須用同一實例
                self.submitCheckoutAjax($form, gatewayId, paymentInstance);

            }).catch(function (error) {
                console.error('Shopline createPayment error:', error);
                self._isSubmitting = false;
                $form.removeClass('processing').unblock();
                self.showFormError(error.message || ys_shopline_params.i18n.payment_error || 'Payment error occurred');
            });

            // Prevent immediate form submission
            return false;
        },

        /**
         * Submit checkout form via AJAX to WooCommerce
         * 直接發送 AJAX 請求，不依賴 WooCommerce 的事件機制
         *
         * @param {jQuery} $form Checkout form
         * @param {string} [gatewayId] v3.5.34: 發起提交時的 gateway（避免提交期間使用者切換付款方式造成錯配）
         * @param {Object} [capturedInstance] v3.5.34: 建立 paySession 的 SDK instance（nextAction 必須沿用）
         */
        submitCheckoutAjax: function ($form, gatewayId, capturedInstance) {
            var self = this;
            gatewayId = gatewayId || this.getSelectedGateway();

            // 確認 wc_checkout_params 存在
            if (typeof wc_checkout_params === 'undefined') {
                console.error('[YS Shopline] wc_checkout_params is not defined');
                self._isSubmitting = false;
                $form.removeClass('processing').unblock();
                self.showFormError('結帳設定錯誤，請重新整理頁面。');
                return;
            }

            var checkoutUrl = wc_checkout_params.checkout_url;
            var formData = $form.serialize();

            console.log('[YS Shopline] Sending AJAX to:', checkoutUrl);
            console.log('[YS Shopline] Form data includes paySession:', formData.indexOf('ys_shopline_pay_session') !== -1);

            $.ajax({
                type: 'POST',
                url: checkoutUrl,
                data: formData,
                dataType: 'json',
                success: function (response) {
                    // v3.5.2: 不 log raw response（含 nextAction 內的 customerToken/payToken）
                    console.log('[YS Shopline] WooCommerce response result:', response ? response.result : 'none');

                    // 移除現有訊息
                    $('.woocommerce-NoticeGroup-checkout, .woocommerce-error, .woocommerce-message').remove();

                    if (response.result === 'success') {
                        // 檢查是否有 nextAction 需要處理（3DS/Confirm）
                        if (response.nextAction) {
                            console.log('[YS Shopline] Got nextAction (type=' + (response.nextAction.type || 'unknown') + '), processing with SDK...');
                            // v3.5.5: 傳 failureUrl（pay-for-order），3DS/Confirm 失敗時自動導過去
                            // v3.5.34: 傳 capturedInstance — 就算提交期間 DOM 被更新換掉全域 instance，仍沿用原實例
                            self.processNextAction(gatewayId, response.nextAction, response.returnUrl, response.failureUrl, capturedInstance);
                        } else if (response.redirect) {
                            // 直接跳轉（無需額外驗證）
                            console.log('[YS Shopline] Redirecting (has redirect URL)');
                            window.location.href = response.redirect;
                        }
                    } else if (response.result === 'failure') {
                        // 失敗 - 顯示錯誤訊息
                        $form.removeClass('processing').unblock();

                        if (response.messages) {
                            // 插入 WooCommerce 錯誤訊息
                            $form.prepend('<div class="woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout">' + response.messages + '</div>');
                            $('html, body').animate({
                                scrollTop: $form.offset().top - 100
                            }, 500);
                        } else {
                            self.showFormError(response.message || '付款處理失敗，請重試。');
                        }

                        // 觸發 WC 事件
                        $(document.body).trigger('checkout_error', [response.messages]);
                    } else {
                        // v3.5.2: 不 log raw response，改記 keys
                        console.warn('[YS Shopline] Unexpected response, keys:', response ? Object.keys(response) : []);
                        self._isSubmitting = false;
                        $form.removeClass('processing').unblock();
                        self.showFormError('發生未預期的錯誤，請重試。');
                    }
                },
                error: function (xhr, status, error) {
                    console.error('[YS Shopline] AJAX error:', status, error);
                    // v3.5.2: 不記 raw responseText（可能含 SDK 錯誤訊息）
                    self._isSubmitting = false;
                    $form.removeClass('processing').unblock();
                    self.showFormError('網路錯誤，請檢查連線後重試。');
                }
            });
        },

        /**
         * Process nextAction with SDK (3DS/Confirm)
         * 使用同一個 payment 實例來處理 nextAction
         *
         * 重要：根據 SHOPLINE SDK 文件：
         * - payment.pay(nextAction) 成功後 SDK 會自動跳轉到 returnUrl
         * - 錯誤時回傳 error 物件
         * - 3DS 驗證時 SDK 會自動處理跳轉到驗證頁面，完成後跳轉到 returnUrl
         *
         * @param {string} gatewayId Gateway ID
         * @param {Object} nextAction Next action data from API
         * @param {string} returnUrl Return URL after success (SDK 會自動使用)
         * @param {string} [failureUrl] v3.5.5: 3DS/Confirm 失敗時導向的 pay-for-order URL（主結帳頁才傳）
         * @param {Object} [capturedInstance] v3.5.34: 建立 paySession 的原 SDK instance。
         *                 提交期間 WC 更新可能已把全域 paymentInstances 換成新實例（無原卡片 session），
         *                 nextAction 必須沿用原實例；全域 map 僅作為舊呼叫端的 fallback。
         */
        processNextAction: async function (gatewayId, nextAction, returnUrl, failureUrl, capturedInstance) {
            var self = this;
            // v3.5.6: 三個頁面共用此 method，form selector 要 fallback
            // - 主結帳頁: form.checkout
            // - Pay-for-order 頁: #order_review
            // - Add-payment-method 頁: form#add_payment_method
            var $form = $('form.checkout');
            if (!$form.length) { $form = $('#order_review'); }
            if (!$form.length) { $form = $('form#add_payment_method'); }
            var paymentInstance = capturedInstance || paymentInstances[gatewayId];

            console.log('[YS Shopline] processNextAction called', {
                gatewayId: gatewayId,
                hasInstance: !!paymentInstance,
                usingCapturedInstance: !!capturedInstance,
                nextActionType: nextAction.type || 'unknown',
                nextActionKeys: nextAction ? Object.keys(nextAction) : [],
                hasFailureUrl: !!failureUrl
            });

            if (!paymentInstance) {
                console.error('[YS Shopline] No payment instance for nextAction processing');
                $form.removeClass('processing').unblock();
                self.resetAllSubmitLocks($form);
                // v3.5.34: 走到這裡代表 WC 訂單與 SHOPLINE 交易皆已建立，
                // 停在結帳頁會讓訂單隱形 — 導向 pay-for-order 頁讓訂單可見並重掛 SDK 重試。
                if (failureUrl) {
                    self.showFormError('付款元件已被頁面更新重置，正在為您導向訂單付款頁...');
                    setTimeout(function () { window.location.href = failureUrl; }, 1500);
                } else {
                    self.showFormError('付款處理失敗，請重新整理頁面後重試。');
                }
                return;
            }

            try {
                console.log('[YS Shopline] Calling payment.pay() with nextAction...');
                console.log('[YS Shopline] SDK will auto-redirect to returnUrl on success');

                var payResult = await paymentInstance.pay(nextAction);

                // v3.5.2: 不 log raw payResult（含 SDK 內部 nextAction / token 資訊）
                console.log('[YS Shopline] pay() returned, hasError=' + !!(payResult && payResult.error));

                // 根據 SDK 文件：
                // - 成功時 payResult 為 undefined，SDK 自動跳轉到 returnUrl
                // - 失敗時 payResult.error 有錯誤資訊
                // - 3DS 時 SDK 會自動處理，完成後跳轉到 returnUrl
                //
                // 如果執行到這裡且沒有 error，表示 SDK 沒有自動跳轉
                // 這可能是 SDK 版本或設定問題，作為備援手動跳轉
                if (payResult && payResult.error) {
                    console.error('[YS Shopline] pay() error:', { code: payResult.error.code, message: payResult.error.message });
                    $form.removeClass('processing').unblock();
                    self.resetAllSubmitLocks($form);  // v3.5.7: 解所有 handler 的 submit lock + button disabled
                    self.showFormError('付款失敗：' + (payResult.error.message || '未知錯誤'));
                    // v3.5.5: 主結帳頁走到這裡代表 WC 訂單已建立（tradeOrderId 已寫入 meta），
                    // 如果停在結帳頁使用者會誤以為沒下單；導向 pay-for-order 頁讓訂單可見 + 支援重試。
                    // pay-for-order 本頁呼叫 processNextAction 時不傳 failureUrl，保持原地顯示錯誤。
                    if (failureUrl) {
                        console.log('[YS Shopline] Order was created; redirecting to pay-for-order in 1.5s');
                        setTimeout(function () { window.location.href = failureUrl; }, 1500);
                    }
                } else {
                    // SDK 應該已經自動跳轉，但如果沒有（某些情況下）
                    // 等待一下看 SDK 是否會跳轉
                    console.log('[YS Shopline] pay() completed without error, waiting for SDK redirect...');

                    // 給 SDK 時間自動跳轉（可能是非同步）
                    setTimeout(function() {
                        // 如果還在這頁，表示 SDK 沒有自動跳轉，手動跳轉
                        console.log('[YS Shopline] SDK did not redirect, manually redirecting to:', returnUrl);
                        window.location.href = returnUrl;
                    }, 2000);
                }
            } catch (e) {
                console.error('[YS Shopline] pay() exception:', e);
                $form.removeClass('processing').unblock();
                self.resetAllSubmitLocks($form);  // v3.5.7: 解所有 handler 的 submit lock + button disabled
                self.showFormError('付款處理發生錯誤：' + (e.message || '未知錯誤'));
                // v3.5.5: 同 pay().error 分支，訂單已建立就要導去 pay-for-order
                if (failureUrl) {
                    console.log('[YS Shopline] pay() exception with existing order; redirecting to pay-for-order in 1.5s');
                    setTimeout(function () { window.location.href = failureUrl; }, 1500);
                }
            }
        },

        /**
         * v3.5.7: 解除所有 handler 的 submit lock + re-enable submit button。
         *
         * 過去 bug：processNextAction error 分支只 reset ShoplineCheckout._isSubmitting，
         * 但使用者可能從 PayForOrderHandler / AddPaymentMethodHandler 入口觸發過來，
         * 那些 handler 的 _isSubmitting 沒 reset → 使用者看到紅框但按鈕卡死、必須重整才能重試。
         *
         * @param {jQuery} $form 當前 form (可選，用於 re-enable submit button)
         */
        resetAllSubmitLocks: function ($form) {
            this._isSubmitting = false;
            try {
                if (typeof PayForOrderHandler !== 'undefined' && PayForOrderHandler._isSubmitting !== undefined) {
                    PayForOrderHandler._isSubmitting = false;
                }
                if (typeof AddPaymentMethodHandler !== 'undefined' && AddPaymentMethodHandler._isSubmitting !== undefined) {
                    AddPaymentMethodHandler._isSubmitting = false;
                }
            } catch (_) { /* ignore undefined handler lookups */ }
            // Re-enable submit buttons（WC 或外部 theme 可能把 button 加 disabled）
            // v3.5.34: #place_order 受共用鎖 registry 管轄——仍有人持鎖
            //（結帳優化的購物車突變鎖等）時不可代解，只解其餘 submit 元件
            if ($form && $form.length) {
                $form.find('button[type="submit"], input[type="submit"]').not('#place_order').prop('disabled', false).removeClass('disabled');
            }
            var lockReg = window.__ysPlaceOrderLocks;
            if (!lockReg || lockReg.count === 0) {
                $('#place_order').prop('disabled', false).removeClass('disabled');
            }

            // v3.5.34: 提交期間被凍結略過的 DOM 更新，在解鎖後補做重掛（instance 已失效，重建乾淨狀態）
            if (this._pendingRemount) {
                this._pendingRemount = false;
                this.onUpdatedCheckout();
            }
        },

        /**
         * Show error in container
         *
         * @param {jQuery} $container Container element
         * @param {string} message Error message
         */
        showError: function ($container, message) {
            $container.html(
                '<div class="woocommerce-error ys-shopline-error" role="alert" style="margin: 10px 0;">' +
                '<strong>' + (ys_shopline_params.i18n.error_prefix || 'Error') + ':</strong> ' +
                this.escapeHtml(message) +
                '</div>'
            );
        },

        /**
         * 根據 SDK 錯誤碼回傳友善訊息（null 表示無對應）
         */
        getFriendlySDKError: function (code, paymentMethod) {
            var map = {
                1100: paymentMethod === 'ApplePay'
                    ? '此裝置或瀏覽器不支援 Apple Pay。\n請使用 iPhone、iPad 或 Mac 上的 Safari 瀏覽器，並確認已設定 Apple Pay 錢包。'
                    : '此裝置不支援此付款方式，請選擇其他付款方式。',
                2009: '認證失敗，請聯繫商家確認金流設定。',
                4200: '此付款方式尚未啟用，請聯繫商家。',
                4204: '此付款方式目前不支援，請選擇其他付款方式。'
            };
            return map[code] || null;
        },

        /**
         * 顯示友善提示（非紅色錯誤，而是資訊提示）
         */
        showFriendlyNotice: function ($container, message) {
            $container.html(
                '<div class="ys-shopline-notice" style="' +
                    'text-align:center;padding:20px 16px;color:#666;' +
                    'background:#f9f9f9;border:1px solid #e0e0e0;border-radius:6px;' +
                    'font-size:14px;line-height:1.6;">' +
                    '<div style="font-size:28px;margin-bottom:8px;">⚠️</div>' +
                    '<p style="margin:0;white-space:pre-line;">' + this.escapeHtml(message) + '</p>' +
                '</div>'
            );
        },

        /**
         * Show error at form level
         *
         * @param {string} message Error message
         */
        /**
         * v3.5.8: SDK render 完後自動選擇使用者預設卡（best-effort）。
         *
         * SHOPLINE SDK 本身沒 expose `setDefaultInstrument()` 類 API，也不會自動 select
         * `is_default=1` 的 token。使用者手動點才 select → 常見 UX 痛點。
         *
         * 做法：
         * 1. PHP 端 localize `default_card_last4`（從 WC_Payment_Tokens::is_default() 抓）
         * 2. 前端 poll DOM 最多 3 秒等 SDK 渲染 `.shoplinepayments_item_*` list
         * 3. 找到 innerText 含 `last4` 的 card element，dispatch 完整 pointer 序列
         *    （React event delegation 需 native pointerdown/up + click 才認）
         *
         * 不 break 現有流程：失敗靜默 return，使用者仍可手動選。
         */
        _tryAutoSelectDefaultCard: function (gatewayId) {
            var last4 = ys_shopline_params && ys_shopline_params.default_card_last4;
            if (!last4 || last4.length < 4) {
                return; // 訪客 / 無預設卡 / 資料不足
            }
            var attempts = 10; // 最多 poll 3 秒
            var intervalId = setInterval(function () {
                if (--attempts <= 0) {
                    clearInterval(intervalId);
                    return;
                }
                // SDK 渲染的 card items（class 名含隨機 hash，用 contains 選擇器）
                var items = document.querySelectorAll('[class*="shoplinepayments_item_"]');
                if (!items.length) return;
                clearInterval(intervalId);

                // v3.5.36 P1: 只在「SDK 實際渲染出恰好一個 last4 相符 item」時才自動點選——
                // 本地 WC token 數不等於 SDK 實際渲染（token 僅特定頁面同步、已有任一 token 便不補同步），
                // 若兩張不同卡同末四碼、SDK 渲染出 2 個相符 item，自動點第一個會點錯卡。
                var matches = [];
                for (var i = 0; i < items.length; i++) {
                    if (String(items[i].innerText || '').indexOf(last4) >= 0) {
                        matches.push(items[i]);
                    }
                }
                if (matches.length !== 1) {
                    console.log('[YS Shopline] auto-select skipped: last4=' + last4 + ' matched ' + matches.length + ' SDK items (need exactly 1)');
                    return;
                }
                var opts = { bubbles: true, cancelable: true, view: window };
                try {
                    // React 用 root-based event delegation → 需完整 pointer 序列才認
                    if (typeof PointerEvent !== 'undefined') {
                        matches[0].dispatchEvent(new PointerEvent('pointerdown', opts));
                        matches[0].dispatchEvent(new PointerEvent('pointerup', opts));
                    }
                    matches[0].dispatchEvent(new MouseEvent('mousedown', opts));
                    matches[0].dispatchEvent(new MouseEvent('mouseup', opts));
                    matches[0].dispatchEvent(new MouseEvent('click', opts));
                    console.log('[YS Shopline] v3.5.8 auto-selected default card ending ' + last4);
                } catch (e) {
                    console.warn('[YS Shopline] auto-select failed:', e.message);
                }
            }, 300);
        },

        /**
         * v3.5.9: 隱藏 SDK 對一般 CC 訂單誤顯示的「後續定期扣款」文案。
         *
         * 啟用 customerToken / savePaymentInstrument 的 SDK config 會在 footer 加上
         * 「SHOPLINE Payments 將依本次交易所使用之付款資訊，進行後續定期扣款」字樣 —
         * 這對訂閱情境正確，但一般單次訂單看到會誤以為「之後會自動再扣款」。
         *
         * 處理方式：text-based hide（SDK class 名是 hash，文字 match 比 class match 穩定）。
         * Poll 最多 3 秒等 SDK render，找到 leaf element 且含關鍵詞就 hide。
         */
        _hideMisleadingRecurringHint: function (gatewayId) {
            var KEYWORD = '後續定期扣款';
            var attempts = 10;
            var iv = setInterval(function () {
                if (--attempts <= 0) { clearInterval(iv); return; }
                var $container = $('#' + gatewayId + '_container');
                if (!$container.length) return;
                var $hits = $container.find('*').filter(function () {
                    return this.children.length === 0
                        && (this.textContent || '').indexOf(KEYWORD) >= 0;
                });
                if ($hits.length) {
                    clearInterval(iv);
                    $hits.hide();
                    console.log('[YS Shopline] v3.5.9 hidden ' + $hits.length + ' misleading recurring hint(s) on ' + gatewayId);
                }
            }, 300);
        },

        /**
         * v3.5.7: Sanitize SHOPLINE error messages to mask long digit IDs.
         *
         * SDK 直接返回的 error message 會含 customer/instrument/trade IDs 明文
         * （例：`customer [10282601157350923963645829361] instrument [20232...997970] invalid`）。
         * 後端 log 用 sensitive_last6 遮罩；前端使用者可見訊息也必須遮，避免 support
         * 截圖 / 客訴對話洩漏識別碼。
         *
         * @param {string} msg SHOPLINE SDK 原始 error message
         * @return {string} 遮罩後訊息（20 位以上純數字 → *末6）
         */
        sanitizeErrorMessage: function (msg) {
            if (typeof msg !== 'string') return msg;
            // v3.5.8: context-aware — 只遮 SHOPLINE 識別碼欄位相鄰的 10+ 位數字，
            // 避免誤傷非敏感的長數字（例如顯示在 error 裡的自訂訂單號 / 交易流水號）。
            //
            // 匹配：<key>[Id?]<sep><digits>[rightBracket?]
            //   key: customer/instrument/paymentInstrument/paymentCustomer/tradeOrder/channelDeal
            //   sep: ": ", "=", " [" 等
            //
            // 對應生產中看到的 error 格式：
            //   customer [1028...] instrument [2023...] invalid
            //   customerId: 1028...
            //   tradeOrderId: 2023...
            var KEYS = 'customer|instrument|paymentInstrument|paymentCustomer|tradeOrder|channelDeal';
            var re = new RegExp('\\b(' + KEYS + ')(Id)?(\\s*[:=]\\s*|\\s*\\[\\s*)(\\d{10,})(\\s*\\])?', 'gi');
            return msg.replace(re, function (_, key, idSuffix, sep, digits, rightBracket) {
                return key + (idSuffix || '') + sep + '*' + digits.slice(-6) + (rightBracket || '');
            });
        },

        showFormError: function (message) {
            // v3.5.7: 前置遮罩，任何呼叫點都自動 sanitize（防止 SHOPLINE ID 洩漏）
            message = this.sanitizeErrorMessage(message);
            // v3.5.6: 三頁面 robust fallback（主結帳 / pay-for-order / add-payment-method）
            var $form = $('form.checkout');
            if (!$form.length) { $form = $('#order_review'); }
            if (!$form.length) { $form = $('form#add_payment_method'); }
            if (!$form.length) { $form = $('.woocommerce-notices-wrapper, main, body').first(); }

            // Remove existing errors
            $('.woocommerce-NoticeGroup-checkout, .woocommerce-error, .woocommerce-message').remove();

            // Add new error
            $form.prepend(
                '<div class="woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout">' +
                '<ul class="woocommerce-error" role="alert">' +
                '<li>' + this.escapeHtml(message).replace(/\n/g, '<br>') + '</li>' +
                '</ul>' +
                '</div>'
            );

            // v3.5.7: 改滾到**剛插入的紅框 notice 位置**而非 form 頂（form 可能很長、notice 在頁面中間看不到）
            // Notice 剛插入後 offset 已是最終位置。
            var $notice = $form.find('.woocommerce-NoticeGroup-checkout').first();
            var noticeOffset = $notice.length ? $notice.offset() : null;
            var scrollTarget;
            if (noticeOffset && typeof noticeOffset.top === 'number') {
                scrollTarget = Math.max(0, noticeOffset.top - 80);
            } else {
                // Fallback: form 頂或頁首
                var formOffset = $form.offset();
                scrollTarget = (formOffset && typeof formOffset.top === 'number') ? Math.max(0, formOffset.top - 100) : 0;
            }
            $('html, body').animate({ scrollTop: scrollTarget }, 500);

            // Trigger WC event
            $(document.body).trigger('checkout_error', [message]);
        },

        /**
         * Escape HTML to prevent XSS
         *
         * @param {string} text Text to escape
         * @return {string} Escaped text
         */
        escapeHtml: function (text) {
            if (!text) return '';

            var map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };

            return String(text).replace(/[&<>"']/g, function (m) {
                return map[m];
            });
        },

        /**
         * Append client device info to form for 3DS/risk assessment.
         *
         * 這些資訊對信用卡 3DS 驗證非常重要，缺漏可能導致 API 錯誤。
         *
         * @param {jQuery} $form Checkout form
         */
        appendClientInfo: function ($form) {
            // 移除舊的裝置資訊
            $form.find('input[name^="ys_shopline_screen"]').remove();
            $form.find('input[name^="ys_shopline_color"]').remove();
            $form.find('input[name^="ys_shopline_timezone"]').remove();
            $form.find('input[name^="ys_shopline_java"]').remove();
            $form.find('input[name^="ys_shopline_browser"]').remove();

            // 收集裝置資訊
            var clientInfo = {
                'ys_shopline_screen_width': String(window.screen.width || ''),
                'ys_shopline_screen_height': String(window.screen.height || ''),
                'ys_shopline_color_depth': String(window.screen.colorDepth || ''),
                'ys_shopline_timezone_offset': String(new Date().getTimezoneOffset() || ''),
                'ys_shopline_java_enabled': String(navigator.javaEnabled ? navigator.javaEnabled() : false),
                'ys_shopline_browser_language': navigator.language || navigator.userLanguage || ''
            };

            // 添加到表單
            $.each(clientInfo, function (name, value) {
                if (value) {
                    $form.append(
                        $('<input>').attr({
                            type: 'hidden',
                            name: name,
                            value: value
                        })
                    );
                }
            });

            console.log('[YS Shopline] Client info collected:', clientInfo);
        },

        /**
         * Check if bind card is enabled for this gateway.
         *
         * 根據 SHOPLINE 文件，SDK createPayment() 返回的 paySession
         * 已經包含了用戶的所有選擇，包括是否勾選儲存卡片。
         *
         * 後端只需要知道 SDK 是否啟用了綁卡功能：
         * - 如果啟用，後端使用 CardBindPayment + savePaymentInstrument=true
         * - 如果未啟用，後端使用 Regular
         *
         * API 會根據 paySession 中的用戶選擇來決定是否實際儲存卡片。
         *
         * @param {string} gatewayId Gateway ID
         * @return {boolean} Whether bind card is enabled
         */
        isBindCardEnabled: function (gatewayId) {
            var gatewayConfig = GATEWAY_CONFIG[gatewayId];

            // 如果 gateway 不支援綁卡
            if (!gatewayConfig || !gatewayConfig.supportsBindCard) {
                console.log('[YS Shopline] isBindCardEnabled: supportsBindCard=false');
                return false;
            }

            // 如果 gateway 強制儲存卡片（如訂閱）
            if (gatewayConfig.forceSaveCard) {
                console.log('[YS Shopline] isBindCardEnabled: forceSaveCard=true');
                return true;
            }

            // 檢查 SDK 初始化時是否啟用了綁卡功能
            // 這個值在 renderPayment 時根據 serverConfig 設定
            var $container = $('#' + gatewayConfig.containerId);
            if ($container.length) {
                var bindCardEnabled = $container.data('bind-card-enabled');
                console.log('[YS Shopline] isBindCardEnabled: from container data:', bindCardEnabled);
                return !!bindCardEnabled;
            }

            console.log('[YS Shopline] isBindCardEnabled: container not found, default false');
            return false;
        },

        getPaymentInstrumentSelection: function (result, gatewayId) {
            var selection = {
                mode: 'regular',
                instrumentId: '',
                last4: ''
            };

            if (result && result.paymentInstrumentId) {
                selection.mode = 'saved';
                selection.instrumentId = String(result.paymentInstrumentId);
                return selection;
            }

            if (!this.isBindCardEnabled(gatewayId)) {
                return selection;
            }

            var config = GATEWAY_CONFIG[gatewayId] || {};
            var $container = config.containerId ? $('#' + config.containerId) : $();
            var activeText = this.getActivePaymentOptionText($container);
            var activeLast4 = this.extractSavedCardLast4(activeText);

            if (activeLast4 && !this.textLooksLikeNewCard(activeText)) {
                selection.mode = 'saved';
                selection.last4 = activeLast4;
                return selection;
            }

            if (!this.isNewCardFormVisible($container)) {
                var allLast4 = this.extractAllSavedCardLast4($container.text() || '');
                if (allLast4.length === 1) {
                    selection.mode = 'saved';
                    selection.last4 = allLast4[0];
                    return selection;
                }
                if (allLast4.length > 1) {
                    selection.mode = 'saved';
                    return selection;
                }
            }

            selection.mode = this.isSaveCardRequested($container, gatewayId) ? 'new_save' : 'new';
            return selection;
        },

        getActivePaymentOptionText: function ($container) {
            if (!$container || !$container.length) {
                return '';
            }

            var texts = [];
            var isPaymentOptionText = function (text) {
                text = String(text || '').replace(/\s+/g, ' ').trim();
                return text && text.length <= 220 && (text.indexOf('****') !== -1 || text.indexOf('使用新卡') !== -1 || /new card/i.test(text));
            };

            $container.find('[class*="shoplinepayments_item_"], [class*="_item_"]').each(function () {
                var $item = $(this);
                var text = String($item.text() || '').replace(/\s+/g, ' ').trim();
                if (!isPaymentOptionText(text)) {
                    return;
                }

                var selected = false;
                $item.find('svg, [class*="footer"]').each(function () {
                    var rect = this.getBoundingClientRect();
                    if (rect.width > 8 && rect.height > 8) {
                        selected = true;
                        return false;
                    }
                });

                if (selected) {
                    texts.push(text);
                }
            });

            if (texts.length) {
                return texts.join(' ');
            }

            var selectors = [
                '[class*="selectOptionActive"]',
                '[aria-selected="true"]',
                'input[type="radio"]:checked'
            ];

            $.each(selectors, function (_, selector) {
                $container.find(selector).each(function () {
                    var text = '';
                    if (this.tagName && this.tagName.toLowerCase() === 'input') {
                        text = $(this).closest('label, li, div').text();
                    } else {
                        text = $(this).text();
                    }
                    text = String(text || '').replace(/\s+/g, ' ').trim();
                    if (isPaymentOptionText(text)) {
                        texts.push(text);
                    }
                });
            });

            return texts.join(' ');
        },

        extractSavedCardLast4: function (text) {
            var match = String(text || '').match(/\*{2,}[\s*]*(\d{4})/);
            return match && match[1] ? match[1] : '';
        },

        extractAllSavedCardLast4: function (text) {
            var values = [];
            var regex = /\*{2,}[\s*]*(\d{4})/g;
            var match;

            while ((match = regex.exec(String(text || ''))) !== null) {
                if (values.indexOf(match[1]) === -1) {
                    values.push(match[1]);
                }
            }

            return values;
        },

        textLooksLikeNewCard: function (text) {
            text = String(text || '');
            return text.indexOf('使用新卡') !== -1 || /new card/i.test(text);
        },

        isNewCardFormVisible: function ($container) {
            if (!$container || !$container.length) {
                return false;
            }

            var visibleIframes = $container.find('iframe').filter(function () {
                var rect = this.getBoundingClientRect();
                return rect.width > 20 && rect.height > 10;
            }).length;

            return visibleIframes >= 2;
        },

        isSaveCardRequested: function ($container, gatewayId) {
            var gatewayConfig = GATEWAY_CONFIG[gatewayId] || {};

            if (gatewayConfig.forceSaveCard) {
                return true;
            }

            if (!$container || !$container.length) {
                return false;
            }

            var $checkboxes = $container.find('input[type="checkbox"]');
            if ($checkboxes.length) {
                return $checkboxes.filter(':checked').length > 0;
            }

            var text = ($container.text() || '').replace(/\s+/g, ' ');
            return /記錄本次付款資訊|儲存|綁定|save card|bind card/i.test(text);
        },

        /**
         * Get payment instance for gateway
         *
         * @param {string} gatewayId Gateway ID
         * @return {Object|null} Payment instance or null
         */
        getPaymentInstance: function (gatewayId) {
            return paymentInstances[gatewayId] || null;
        },

        getSelectedInstallment: function (result, gatewayId) {
            var value = result && (
                result.installment ||
                result.installmentCount ||
                result.installments ||
                (result.paymentMethodOptions && result.paymentMethodOptions.installments && result.paymentMethodOptions.installments.count)
            );

            if (value) {
                return String(value);
            }

            var configMeta = GATEWAY_CONFIG[gatewayId] || {};
            var $container = configMeta.containerId ? $('#' + configMeta.containerId) : $();
            var selectedText = '';

            if ($container.length) {
                selectedText = $container.find('[class*="selectOptionActive"]').first().text() ||
                    $container.find('[class*="selectWrap"]').first().text() ||
                    '';
            }

            var selectedMatch = String(selectedText).match(/x\s*(\d+)\s*期/);
            if (selectedMatch && selectedMatch[1]) {
                return String(selectedMatch[1]);
            }

            var config = sdkConfigs[gatewayId] || {};
            var counts = config.installmentCounts || [];
            var nonZeroCounts = $.grep(counts, function (count) {
                return String(count) !== '0';
            });

            return nonZeroCounts.length === 1 ? String(nonZeroCounts[0]) : '';
        },

        /**
         * Check if gateway is Shopline gateway
         *
         * @param {string} gatewayId Gateway ID
         * @return {boolean}
         */
        isShoplineGateway: function (gatewayId) {
            return !!GATEWAY_CONFIG[gatewayId];
        },

        /**
         * Refresh SDK for specific gateway
         *
         * v3.5.0: 使用 state machine 重新 mount
         *
         * @param {string} gatewayId Gateway ID
         */
        refreshGateway: function (gatewayId) {
            var config = GATEWAY_CONFIG[gatewayId];
            if (!config) return;

            // abort pending AJAX
            if (pendingAjax[gatewayId]) {
                try { pendingAjax[gatewayId].abort(); } catch (e) {}
                delete pendingAjax[gatewayId];
            }
            delete paymentInstances[gatewayId];
            gatewayState[gatewayId] = 'idle';

            var $container = $('#' + config.containerId);
            if ($container.length) $container.empty();

            this.requestMount(gatewayId);
        }
    };

    /**
     * Pay-for-Order Page Handler
     *
     * 處理 /checkout/order-pay/{id}/ 頁面的 SDK 付款。
     * 該頁面使用 #order_review 表單（非 form.checkout），
     * 需要獨立攔截表單提交並透過 AJAX 呼叫 process_payment。
     */
    var PayForOrderHandler = {

        /**
         * Initialize handler
         */
        init: function () {
            if (!this.isPayForOrderPage()) {
                return;
            }

            var $form = $('#order_review');
            if (!$form.length) {
                return;
            }

            console.log('[YS Shopline] Pay-for-order page detected');

            var self = this;

            // 初始化當前選中的閘道 SDK
            var gatewayId = $('input[name="payment_method"]:checked').val();
            if (gatewayId && GATEWAY_CONFIG[gatewayId]) {
                ShoplineCheckout.initSDK(gatewayId);
            }

            // 監聽付款方式變更（v3.5.0: unmountOthers + requestMount 取代 clearConflicting+initSDK）
            $form.on('change', 'input[name="payment_method"]', function () {
                var newGatewayId = $(this).val();
                if (newGatewayId && GATEWAY_CONFIG[newGatewayId]) {
                    ShoplineCheckout.unmountOthers(newGatewayId);
                    ShoplineCheckout.requestMount(newGatewayId);
                }
            });

            // 攔截表單提交
            $form.on('submit', function (e) {
                var selectedGateway = $('input[name="payment_method"]:checked').val();

                // 非 Shopline 閘道，正常提交
                if (!selectedGateway || !GATEWAY_CONFIG[selectedGateway]) {
                    return true;
                }

                // 已有 paySession（避免重複攔截）
                if ($form.find('input[name="ys_shopline_pay_session"]').val()) {
                    return true;
                }

                e.preventDefault();
                self.processPayment($form, selectedGateway);
                return false;
            });
        },

        /**
         * 偵測是否為 pay-for-order 頁面
         */
        isPayForOrderPage: function () {
            return window.location.pathname.indexOf('order-pay') !== -1;
        },

        /**
         * 取得 order_id（從 URL 路徑）
         */
        getOrderId: function () {
            var match = window.location.pathname.match(/order-pay\/(\d+)/);
            return match ? match[1] : '';
        },

        /**
         * 處理付款：取得 paySession 後透過 AJAX 提交
         */
        processPayment: function ($form, gatewayId) {
            var self = this;

            // 防呆：防止重複提交
            if (self._isSubmitting) {
                console.log('[YS Shopline] Pay-for-order duplicate submission blocked');
                return;
            }

            var paymentInstance = paymentInstances[gatewayId];

            // v3.5.35 Review P2: 不只看 instance 是否存在——instance 尚在但 iframe/container
            // 已被結帳更新替換脫離 DOM 時，createPayment 會打在死 instance 上而失敗。
            // 改用 isMountHealthy（instance 存在 + 容器 iframe/內容健在）判定。
            if (!ShoplineCheckout.isMountHealthy(gatewayId)) {
                // v3.5.35: 切換金流後 SDK 掛載尚未完成／已失效 — 等待掛載健康後自動續行付款，
                // 不再直接 alert 斷頭（fail-closed：硬逾時才報錯，絕不猜測送單）
                if (self._isWaitingMount) {
                    console.log('[YS Shopline] Pay-for-order: already waiting for mount');
                    return;
                }
                self._isWaitingMount = true;
                ShoplineCheckout.acquirePlaceOrderLock();
                console.log('[YS Shopline] Pay-for-order: instance not ready, waiting for mount:', gatewayId);

                ShoplineCheckout.waitForMountThenContinue(gatewayId, function () {
                    self._isWaitingMount = false;
                    ShoplineCheckout.releasePlaceOrderLock();
                    console.log('[YS Shopline] Pay-for-order: mount ready, resuming payment:', gatewayId);
                    self.processPayment($form, gatewayId);
                }, function (reason) {
                    self._isWaitingMount = false;
                    ShoplineCheckout.releasePlaceOrderLock();
                    if (reason === 'timeout') {
                        ShoplineCheckout.showFormError(ys_shopline_params.i18n.payment_component_timeout || '付款元件載入逾時，請重新整理頁面後再試。');
                    }
                    // gateway_switched：使用者已改選其他金流，靜默結束（新金流自行掛載）
                });
                return;
            }

            self._isSubmitting = true;

            // Block UI
            $form.addClass('processing').block({
                message: null,
                overlayCSS: { background: '#fff', opacity: 0.6 }
            });

            // 呼叫 SDK 取得 paySession
            paymentInstance.createPayment().then(function (result) {
                // v3.5.2: 不 log raw result（含 paySession 原文）
                console.log('[YS Shopline] Pay-for-order createPayment returned, keys:', result ? Object.keys(result) : []);

                if (result.error) {
                    console.error('[YS Shopline] Pay-for-order createPayment error:', { code: result.error.code, message: result.error.message });
                    self._isSubmitting = false;
                    $form.removeClass('processing').unblock();
                    // v3.5.35: alert → 頁內 WC notice（showFormError 內建 sanitize + escape）
                    ShoplineCheckout.showFormError(result.error.message || '付款失敗，請重試。');
                    return;
                }

                if (!result.paySession) {
                    self._isSubmitting = false;
                    $form.removeClass('processing').unblock();
                    ShoplineCheckout.showFormError('付款流程建立失敗，請重試。');
                    return;
                }

                // 準備 AJAX 資料
                var paySessionValue = typeof result.paySession === 'object'
                    ? JSON.stringify(result.paySession)
                    : result.paySession;

                var instrumentSelection = ShoplineCheckout.getPaymentInstrumentSelection(result, gatewayId);
                var urlParams = new URLSearchParams(window.location.search);
                var ajaxData = {
                    action: 'ys_shopline_pay_for_order',
                    nonce: ys_shopline_params.nonce,
                    order_id: self.getOrderId(),
                    order_key: urlParams.get('key') || '',
                    payment_method: gatewayId,
                    ys_shopline_pay_session: paySessionValue,
                    ys_shopline_bind_card_enabled: instrumentSelection.mode === 'new_save' ? '1' : '0',
                    ys_shopline_payment_instrument_mode: instrumentSelection.mode
                };

                if (instrumentSelection.instrumentId) {
                    ajaxData.ys_shopline_payment_instrument_id = instrumentSelection.instrumentId;
                }

                if (instrumentSelection.last4) {
                    ajaxData.ys_shopline_saved_card_last4 = instrumentSelection.last4;
                }

                var selectedInstallment = (GATEWAY_CONFIG[gatewayId] || {}).supportsInstallment ? ShoplineCheckout.getSelectedInstallment(result, gatewayId) : '';
                if (selectedInstallment) {
                    if (gatewayId === 'ys_shopline_bnpl') {
                        ajaxData.ys_shopline_bnpl_installment = selectedInstallment;
                    } else {
                        ajaxData.ys_shopline_installment = selectedInstallment;
                    }
                }

                // 加入裝置資訊
                var clientInfo = {
                    'ys_shopline_screen_width': String(window.screen.width || ''),
                    'ys_shopline_screen_height': String(window.screen.height || ''),
                    'ys_shopline_color_depth': String(window.screen.colorDepth || ''),
                    'ys_shopline_timezone_offset': String(new Date().getTimezoneOffset() || ''),
                    'ys_shopline_java_enabled': String(navigator.javaEnabled ? navigator.javaEnabled() : false),
                    'ys_shopline_browser_language': navigator.language || navigator.userLanguage || ''
                };
                $.extend(ajaxData, clientInfo);

                // 透過 AJAX 呼叫 process_payment
                $.ajax({
                    type: 'POST',
                    url: ys_shopline_params.ajax_url,
                    data: ajaxData,
                    dataType: 'json',
                    success: function (response) {
                        // v3.5.2: 不 log raw response（可能含 nextAction 中的 customerToken/payToken）
                        console.log('[YS Shopline] Pay-for-order response result:', response ? response.result : 'none');

                        if (response.result === 'success') {
                            if (response.nextAction) {
                                // 需要 SDK 處理 nextAction（3DS/Confirm）
                                // v3.5.34: 沿用建立 paySession 的原 instance（第 5 參數；failureUrl 本頁不傳，原地顯示錯誤）
                                console.log('[YS Shopline] Pay-for-order: processing nextAction (type=' + (response.nextAction.type || 'unknown') + ')');
                                ShoplineCheckout.processNextAction(gatewayId, response.nextAction, response.returnUrl, undefined, paymentInstance);
                            } else if (response.redirect) {
                                window.location.href = response.redirect;
                            }
                        } else {
                            self._isSubmitting = false;
                            $form.removeClass('processing').unblock();
                            // v3.5.35: 後端已把真實原因（含既存交易處理結果）收攏進 messages（純文字，
                            // 可能多行）；防禦性去除任何殘留標籤後以頁內 notice 呈現
                            var errorMsg = String(response.messages || response.message || '付款處理失敗，請重試。').replace(/<[^>]*>/g, ' ');
                            ShoplineCheckout.showFormError(errorMsg);
                        }
                    },
                    error: function (xhr, status, error) {
                        console.error('[YS Shopline] Pay-for-order AJAX error:', status, error);
                        self._isSubmitting = false;
                        $form.removeClass('processing').unblock();
                        ShoplineCheckout.showFormError('網路錯誤，請檢查連線後重試。');
                    }
                });

            }).catch(function (error) {
                console.error('[YS Shopline] Pay-for-order createPayment error:', error);
                self._isSubmitting = false;
                $form.removeClass('processing').unblock();
                // v3.5.35: alert → 頁內 notice（showFormError 內建 sanitize + escape）
                ShoplineCheckout.showFormError(error.message || '付款處理發生錯誤，請重試。');
            });
        }
    };

    // Initialize on document ready
    ShoplineCheckout.init();

    // Initialize pay-for-order handler
    PayForOrderHandler.init();

    // Expose to global scope for extensions
    window.ShoplineCheckout = ShoplineCheckout;

    /**
     * Add Payment Method Page Handler
     *
     * 處理 /my-account/add-payment-method/ 頁面的 SDK 初始化和表單提交。
     */
    var AddPaymentMethodHandler = {

        /**
         * Payment instance
         */
        paymentInstance: null,

        /**
         * Initialize handler
         */
        init: function () {
            var self = this;

            // 檢查是否在 add_payment_method 頁面
            if (!this.isAddPaymentMethodPage()) {
                return;
            }

            console.log('[YS Shopline] Add payment method page detected');

            // 初始化 SDK
            this.initSDK();

            // 綁定表單事件
            this.bindFormEvents();
        },

        /**
         * Check if on add payment method page
         *
         * @return {boolean}
         */
        isAddPaymentMethodPage: function () {
            // 檢查 URL 或頁面元素
            return $('form#add_payment_method').length > 0 ||
                   window.location.href.indexOf('add-payment-method') !== -1;
        },

        /**
         * Initialize SDK for add payment method
         */
        initSDK: function () {
            var self = this;

            // 找到 Shopline 信用卡的容器
            var $container = $('.ys-shopline-payment-container[data-gateway="ys_shopline_credit"]');

            if (!$container.length) {
                // 嘗試其他可能的 gateway ID
                $container = $('.ys-shopline-payment-container').first();
            }

            if (!$container.length) {
                console.log('[YS Shopline] No payment container found on add_payment_method page');
                return;
            }

            var gatewayId = $container.data('gateway') || 'ys_shopline_credit';

            console.log('[YS Shopline] Initializing SDK for add_payment_method, gateway:', gatewayId);

            // 顯示載入狀態
            $container.html('<div class="ys-shopline-loading" style="text-align: center; padding: 20px;"><span class="spinner is-active" style="float: none;"></span> 正在載入...</div>');

            // 從伺服器取得 SDK 配置
            $.ajax({
                type: 'POST',
                url: ys_shopline_params.ajax_url,
                data: {
                    action: 'ys_shopline_get_sdk_config',
                    nonce: ys_shopline_params.nonce,
                    gateway: gatewayId,
                    is_add_payment_method: 1
                },
                success: function (response) {
                    if (response.success) {
                        self.renderPayment($container, response.data);
                    } else {
                        var errorMsg = (response.data && response.data.message) ? response.data.message : '載入失敗';
                        $container.html('<div class="woocommerce-error">' + errorMsg + '</div>');
                    }
                },
                error: function () {
                    $container.html('<div class="woocommerce-error">網路錯誤，請重新整理頁面。</div>');
                }
            });
        },

        /**
         * Render payment SDK
         *
         * @param {jQuery} $container Container element
         * @param {Object} serverConfig Server configuration
         */
        renderPayment: async function ($container, serverConfig) {
            var self = this;

            if (typeof ShoplinePayments === 'undefined') {
                $container.html('<div class="woocommerce-error">付款 SDK 未載入，請重新整理頁面。</div>');
                return;
            }

            if (!serverConfig.clientKey || !serverConfig.merchantId) {
                $container.html('<div class="woocommerce-error">付款設定錯誤。</div>');
                return;
            }

            // 清空容器
            $container.empty();

            try {
                // SDK 選項 - 純綁卡頁面
                // SHOPLINE SDK 與 API 都驗證 amount 必填且 > 0
                // 後端 API 以 CardBind + amount=10000 處理（對齊官方範例，銀行只授權不請款）
                var options = {
                    clientKey: serverConfig.clientKey,
                    merchantId: serverConfig.merchantId,
                    paymentMethod: 'CreditCard',
                    currency: serverConfig.currency || 'TWD',
                    amount: serverConfig.amount || 10100, // SDK 驗證用，API 以 CardBind 處理（只授權不請款）
                    element: '#' + $container.attr('id'),
                    env: serverConfig.env || 'production'
                };

                // 強制啟用 bindCard（純綁卡模式）
                // 官方 /guide/quick/ 4.1 CardBind 範例 SDK init 不需要 customerToken
                // 實際綁卡是後端 /trade/payment/create 帶 paymentCustomerId 達成
                options.paymentInstrument = {
                    bindCard: {
                        enable: true,
                        protocol: {
                            switchVisible: false,     // 隱藏開關，強制儲存
                            defaultSwitchStatus: true,
                            mustAccept: true
                        }
                    }
                };

                // 若後端仍帶了 customerToken 則一併傳給 SDK（舊版相容）
                if (serverConfig.customerToken) {
                    options.customerToken = serverConfig.customerToken;
                }

                console.log('[YS Shopline] Add payment method SDK options:', {
                    merchantId: options.merchantId.substring(0, 8) + '...',
                    paymentMethod: options.paymentMethod,
                    env: options.env,
                    amount: options.amount,
                    hasCustomerToken: !!options.customerToken
                });

                // 初始化 SDK
                var result = await ShoplinePayments(options);

                // v3.5.2: 不 log raw SDK result（可能含 SDK 內部資訊）
                console.log('[YS Shopline] Add payment method SDK returned, hasError=' + !!(result && result.error) + ', hasPayment=' + !!(result && result.payment));

                if (result.error) {
                    console.error('[YS Shopline] Add payment method SDK error:', { code: result.error.code, message: result.error.message });
                    $container.html('<div class="woocommerce-error">' + (result.error.message || 'SDK 錯誤') + '</div>');
                    return;
                }

                // 儲存 payment instance
                self.paymentInstance = result.payment;
                $container.data('sdk-initialized', true);

                // 附加純綁卡提示文字（告知用戶此為驗證性質不會扣款）
                if ($container.find('.ys-bindcard-hint').length === 0) {
                    $container.append(
                        '<div class="ys-bindcard-hint" style="margin-top:12px;padding:10px 14px;' +
                        'background:#f0f7ff;border-left:3px solid #4a90e2;border-radius:4px;' +
                        'font-size:13px;color:#555;line-height:1.5;">' +
                        '<strong style="color:#4a90e2;">ℹ️ 純綁卡驗證</strong><br>' +
                        '此為綁卡驗證流程，SHOPLINE 將依銀行規範進行卡片授權驗證，不會扣款。' +
                        '</div>'
                    );
                }

                console.log('[YS Shopline] Add payment method SDK initialized successfully');

            } catch (e) {
                console.error('[YS Shopline] Add payment method SDK exception:', e);
                $container.html('<div class="woocommerce-error">' + (e.message || 'SDK 初始化失敗') + '</div>');
            }
        },

        /**
         * Bind form submit event
         */
        bindFormEvents: function () {
            var self = this;

            // WooCommerce add payment method form
            var $form = $('form#add_payment_method');

            if (!$form.length) {
                return;
            }

            // 移除舊的綁定
            $form.off('.ys_shopline_add_method');

            // 永遠阻止 WC 原生 form POST
            // 改走 AJAX：讓原 SDK 實例延續 pay(nextAction)，否則跨頁會丟失 PCI session
            $form.on('submit.ys_shopline_add_method', function (e) {
                var selectedPayment = $('input[name="payment_method"]:checked').val();
                if (!selectedPayment || selectedPayment.indexOf('ys_shopline') !== 0) {
                    return true; // 非 Shopline 付款方式，讓 WC 處理
                }
                e.preventDefault();
                self.processAddPaymentMethod($form);
                return false;
            });

            console.log('[YS Shopline] Add payment method form events bound (AJAX mode)');
        },

        /**
         * Process add payment method (AJAX 模式，原 SDK 實例延續 3DS).
         *
         * 1. SDK.createPayment() → paySession
         * 2. AJAX POST 送到後端（action=ys_shopline_add_payment_method）
         * 3. 後端呼叫 Shopline CardBind API 回 nextAction
         * 4. **用原 SDK 實例** pay(nextAction) → SDK 自己跳 returnUrl
         * 5. returnUrl 觸發 handle_add_method_redirect 建 Token
         *
         * @param {jQuery} $form Form element
         */
        processAddPaymentMethod: function ($form) {
            var self = this;

            if (!this.paymentInstance) {
                this.showError($form, '付款尚未準備就緒，請稍候再試。');
                return;
            }

            $form.addClass('processing').block({
                message: null,
                overlayCSS: { background: '#fff', opacity: 0.6 }
            });

            console.log('[YS Shopline] AddMethod: Creating paySession...');

            this.paymentInstance.createPayment().then(function (result) {
                if (result.error) {
                    $form.removeClass('processing').unblock();
                    self.showError($form, result.error.message || '建立付款失敗');
                    return;
                }
                if (!result.paySession) {
                    $form.removeClass('processing').unblock();
                    self.showError($form, '付款資訊建立失敗，請重新輸入卡片資訊。');
                    return;
                }

                var paySessionValue = result.paySession;
                if (typeof paySessionValue === 'object') {
                    paySessionValue = JSON.stringify(paySessionValue);
                }

                console.log('[YS Shopline] AddMethod: POST to AJAX endpoint...');

                $.ajax({
                    type: 'POST',
                    url: ys_shopline_params.ajax_url,
                    data: {
                        action: 'ys_shopline_add_payment_method',
                        nonce: ys_shopline_params.nonce,
                        pay_session: paySessionValue
                    }
                }).done(function (response) {
                    // v3.5.2: 不 log raw response（含 nextAction 內的 customerToken/payToken）
                    console.log('[YS Shopline] AddMethod AJAX response success:', response ? !!response.success : false);
                    if (!response || !response.success) {
                        $form.removeClass('processing').unblock();
                        var msg = (response && response.data && response.data.message) || '新增付款方式失敗';
                        self.showError($form, msg);
                        return;
                    }

                    var nextAction = response.data.nextAction;
                    var returnUrl  = response.data.returnUrl;

                    // 無 nextAction：SDK 不需額外步驟，直接跳 returnUrl
                    if (!nextAction) {
                        console.log('[YS Shopline] AddMethod: no nextAction, redirecting to returnUrl');
                        window.location.href = returnUrl || '/my-account/payment-methods/';
                        return;
                    }

                    // 有 nextAction：用原 SDK 實例 pay()，SDK 會處理 3DS + 自動跳 returnUrl
                    console.log('[YS Shopline] AddMethod: calling paymentInstance.pay(nextAction, type=' + (nextAction.type || 'unknown') + ')...');
                    self.paymentInstance.pay(nextAction).then(function (payResult) {
                        // v3.5.2: 不 log raw payResult（可能含 SDK 內部 token 資訊）
                        console.log('[YS Shopline] AddMethod pay returned, hasError=' + !!(payResult && payResult.error));
                        // 成功時 payResult 為 undefined（SDK 已跳轉）
                        if (payResult && payResult.error) {
                            $form.removeClass('processing').unblock();
                            self.showError($form, payResult.error.message || '3DS 驗證失敗');
                        }
                    }).catch(function (err) {
                        console.error('[YS Shopline] AddMethod pay error:', { message: err && err.message });
                        $form.removeClass('processing').unblock();
                        self.showError($form, err.message || '3DS 驗證錯誤');
                    });
                }).fail(function (xhr) {
                    // v3.5.2: 不 log raw xhr 物件（responseText 可能含 SDK 錯誤內容）
                    console.error('[YS Shopline] AddMethod AJAX failed, status=' + (xhr ? xhr.status : 'unknown'));
                    $form.removeClass('processing').unblock();
                    self.showError($form, '伺服器錯誤，請稍後再試。');
                });

            }).catch(function (error) {
                console.error('[YS Shopline] AddMethod createPayment error:', error);
                $form.removeClass('processing').unblock();
                self.showError($form, error.message || '發生錯誤');
            });
        },

        /**
         * Show error message
         *
         * @param {jQuery} $form Form element
         * @param {string} message Error message
         */
        showError: function ($form, message) {
            // v3.5.9: 與 ShoplineCheckout.showFormError 共用同一遮罩策略，避免綁卡頁洩漏 SHOPLINE 識別碼
            var safe = (window.ShoplineCheckout && typeof window.ShoplineCheckout.sanitizeErrorMessage === 'function')
                ? window.ShoplineCheckout.sanitizeErrorMessage(message)
                : message;

            // 移除現有錯誤
            $form.find('.woocommerce-error, .woocommerce-message').remove();

            // 添加新錯誤
            $form.prepend(
                '<ul class="woocommerce-error" role="alert"><li>' +
                $('<div>').text(safe).html() +
                '</li></ul>'
            );

            // v3.5.9: offset() 防衛
            var off = $form.offset();
            var target = (off && typeof off.top === 'number') ? Math.max(0, off.top - 100) : 0;
            $('html, body').animate({ scrollTop: target }, 500);
        }
    };

    // Initialize add payment method handler
    AddPaymentMethodHandler.init();

    // Expose to global scope
    window.AddPaymentMethodHandler = AddPaymentMethodHandler;
});
