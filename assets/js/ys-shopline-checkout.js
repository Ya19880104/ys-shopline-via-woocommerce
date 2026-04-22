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
     * Payment instances cache
     */
    var paymentInstances = {};

    /**
     * Current active gateway
     */
    var activeGateway = null;

    /**
     * SDK loaded flag
     */
    var sdkLoaded = false;

    /**
     * SDK initialization in progress (prevent duplicate calls)
     */
    var sdkInitializing = {};

    /**
     * Pending AJAX requests (for abort on clearAllInstances)
     */
    var pendingAjax = {};

    /**
     * Generation counter per gateway — incremented on clear, checked after async SDK init.
     * Prevents stale ShoplinePayments() callbacks from rendering into a cleared container.
     */
    var sdkGeneration = {};

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

            // 重置提交 flag：WC 在錯誤或頁面更新時觸發
            $(document.body).on('checkout_error updated_checkout', function () {
                self._isSubmitting = false;
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
         * Handle payment method change
         *
         * 同類型 paymentMethod（如 CreditCard）切換時，需先清除舊實例，
         * 因為 Shopline SDK 不允許同頁面同時掛載多個相同 paymentMethod 的實例。
         */
        onPaymentMethodChange: function () {
            var gatewayId = this.getSelectedGateway();

            if (gatewayId) {
                // 同一個 gateway 且已有 instance → 不需重新初始化
                if (gatewayId === activeGateway && paymentInstances[gatewayId]) {
                    return;
                }
                this.clearConflictingInstances(gatewayId);
                activeGateway = gatewayId;
                this.initSDK(gatewayId);
            } else {
                activeGateway = null;
            }
        },

        /**
         * Clear SDK instances that share the same paymentMethod as the target gateway.
         *
         * @param {string} targetGatewayId The gateway about to be initialized
         */
        clearConflictingInstances: function (targetGatewayId) {
            var targetConfig = GATEWAY_CONFIG[targetGatewayId];
            if (!targetConfig) return;

            var targetMethod = targetConfig.paymentMethod;

            $.each(GATEWAY_CONFIG, function (existingId, existingConfig) {
                if (existingId === targetGatewayId) return; // 跳過自己
                if (existingConfig.paymentMethod !== targetMethod) return; // 不同類型不衝突

                // 有舊實例或進行中的請求 → 清除
                if (paymentInstances[existingId] || pendingAjax[existingId]) {
                    console.log('[YS Shopline] Clearing conflicting SDK instance:', existingId, '→', targetGatewayId);
                    sdkGeneration[existingId] = (sdkGeneration[existingId] || 0) + 1;
                    if (pendingAjax[existingId]) {
                        pendingAjax[existingId].abort();
                        delete pendingAjax[existingId];
                    }
                    delete paymentInstances[existingId];
                    sdkInitializing[existingId] = false;

                    var $oldContainer = $('#' + existingConfig.containerId);
                    if ($oldContainer.length) {
                        $oldContainer.empty();
                        $oldContainer.removeData('sdk-initialized');
                        $oldContainer.removeData('sdk-initializing');
                    }
                }
            });
        },

        /**
         * Handle checkout update (cart changes, etc.)
         */
        onUpdatedCheckout: function () {
            // 重新綁定事件（表單可能被替換）
            this.bindCheckoutEvents();

            // 清除舊的 paySession（購物車更新後需要重新建立）
            $('form.checkout').find('input[name="ys_shopline_pay_session"]').remove();

            // checkout 更新時 DOM 被替換，所有舊的 SDK 實例都需要清除重建
            this.clearAllInstances();

            var gatewayId = this.getSelectedGateway();

            if (gatewayId) {
                activeGateway = gatewayId;
                this.initSDK(gatewayId);
            }
        },

        /**
         * Clear all SDK instances (used when checkout DOM is replaced).
         */
        clearAllInstances: function () {
            $.each(GATEWAY_CONFIG, function (gatewayId, config) {
                // Increment generation to invalidate in-flight ShoplinePayments() calls
                sdkGeneration[gatewayId] = (sdkGeneration[gatewayId] || 0) + 1;

                // Abort pending AJAX request to prevent stale callback
                if (pendingAjax[gatewayId]) {
                    pendingAjax[gatewayId].abort();
                    delete pendingAjax[gatewayId];
                }

                if (paymentInstances[gatewayId]) {
                    console.log('[YS Shopline] Clearing SDK instance on checkout update:', gatewayId);
                    delete paymentInstances[gatewayId];
                }
                sdkInitializing[gatewayId] = false;

                var $container = $('#' + config.containerId);
                if ($container.length) {
                    $container.removeData('sdk-initialized');
                    $container.removeData('sdk-initializing');
                }
            });
        },

        /**
         * Initialize SDK for specific gateway
         *
         * @param {string} gatewayId Gateway ID
         */
        initSDK: function (gatewayId) {
            var self = this;
            var config = GATEWAY_CONFIG[gatewayId];

            if (!config) {
                console.error('Unknown gateway:', gatewayId);
                return;
            }

            // Prevent duplicate initialization
            if (paymentInstances[gatewayId]) {
                console.log('[YS Shopline] SDK already initialized for:', gatewayId);
                return;
            }

            // Prevent concurrent initialization
            if (sdkInitializing[gatewayId]) {
                console.log('[YS Shopline] SDK initialization already in progress for:', gatewayId);
                return;
            }

            // Mark as initializing
            sdkInitializing[gatewayId] = true;

            var $container = $('#' + config.containerId);

            // If container doesn't exist, skip
            if (!$container.length) {
                sdkInitializing[gatewayId] = false;
                return;
            }

            // Check if container already has SDK content or is being initialized
            // This prevents double initialization from rapid event firing
            if ($container.data('sdk-initializing') || $container.data('sdk-initialized')) {
                console.log('[YS Shopline] Container already has SDK or is initializing for:', gatewayId);
                sdkInitializing[gatewayId] = false;
                return;
            }

            // Mark container as initializing
            $container.data('sdk-initializing', true);

            // Show loading state
            $container.html('<div class="ys-shopline-loading" style="text-align: center; padding: 20px;"><span class="spinner is-active" style="float: none;"></span></div>');

            // Fetch SDK config from server
            var ajaxData = {
                action: 'ys_shopline_get_sdk_config',
                nonce: ys_shopline_params.nonce,
                gateway: gatewayId
            };

            // Pay-for-order 頁面：傳遞 order_id + order_key 讓後端取得正確金額與驗證權限
            var orderPayMatch = window.location.pathname.match(/order-pay\/(\d+)/);
            if (orderPayMatch) {
                ajaxData.order_id = orderPayMatch[1];
                var urlParams = new URLSearchParams(window.location.search);
                if (urlParams.get('key')) {
                    ajaxData.order_key = urlParams.get('key');
                }
            }

            // Abort any previous pending request for this gateway
            if (pendingAjax[gatewayId]) {
                pendingAjax[gatewayId].abort();
            }

            pendingAjax[gatewayId] = $.ajax({
                type: 'POST',
                url: ys_shopline_params.ajax_url,
                data: ajaxData,
                success: function (response) {
                    delete pendingAjax[gatewayId];
                    if (response.success) {
                        self.renderPayment(gatewayId, response.data);
                    } else {
                        sdkInitializing[gatewayId] = false;
                        $container.data('sdk-initializing', false);
                        var errorMsg = (response.data && response.data.message)
                            ? response.data.message
                            : ys_shopline_params.i18n.config_error || 'Configuration error';
                        self.showError($container, errorMsg);
                    }
                },
                error: function (jqXHR, textStatus) {
                    delete pendingAjax[gatewayId];
                    // Silently ignore aborted requests (cleared by clearAllInstances)
                    if (textStatus === 'abort') {
                        console.log('[YS Shopline] SDK config request aborted for:', gatewayId);
                        return;
                    }
                    sdkInitializing[gatewayId] = false;
                    $container.data('sdk-initializing', false);
                    var errorMsg = ys_shopline_params.i18n.connection_error || 'Connection error';
                    self.showError($container, errorMsg + ': ' + textStatus);
                }
            });
        },

        /**
         * Render payment SDK
         *
         * @param {string} gatewayId Gateway ID
         * @param {Object} serverConfig Server configuration
         */
        renderPayment: async function (gatewayId, serverConfig) {
            var self = this;
            var gatewayConfig = GATEWAY_CONFIG[gatewayId];
            var $container = $('#' + gatewayConfig.containerId);

            // Final guard: if instance already created (race condition), skip
            if (paymentInstances[gatewayId]) {
                console.log('[YS Shopline] renderPayment skipped - instance already exists for:', gatewayId);
                sdkInitializing[gatewayId] = false;
                $container.data('sdk-initializing', false);
                return;
            }

            // Check SDK loaded
            if (!sdkLoaded && typeof ShoplinePayments === 'undefined') {
                sdkInitializing[gatewayId] = false;
                $container.data('sdk-initializing', false);
                self.showError($container, ys_shopline_params.i18n.sdk_not_loaded || 'Payment SDK not loaded');
                return;
            }

            sdkLoaded = true;

            // Validate config
            if (!serverConfig.clientKey || !serverConfig.merchantId) {
                sdkInitializing[gatewayId] = false;
                $container.data('sdk-initializing', false);
                self.showError($container, ys_shopline_params.i18n.missing_config || 'Missing configuration');
                return;
            }

            // Capture generation before async work
            var myGeneration = sdkGeneration[gatewayId] || 0;

            // Clear container
            $container.empty();

            try {
                // Build SDK options
                // Note: env 參數控制環境 (sandbox/production)
                // bindOnlyMode: true 時（如 $0 訂閱試用），SDK init amount 需 >= 100 通過驗證，
                // 後端會以 CardBind + amount=0 處理實際 API 呼叫，不會扣款
                var sdkAmount = serverConfig.amount || 0;
                if (serverConfig.bindOnlyMode && sdkAmount <= 0) {
                    sdkAmount = 100; // TWD $1 的 SDK 最低驗證值（不實際扣款）
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
                    // 使用後端傳來的配置
                    options.paymentInstrument = serverConfig.paymentInstrument;
                    console.log('[YS Shopline] Using server paymentInstrument config:', serverConfig.paymentInstrument);
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

                // Initialize SDK
                var result = await ShoplinePayments(options);

                // Stale check: if generation changed while awaiting, this init is outdated
                if ((sdkGeneration[gatewayId] || 0) !== myGeneration) {
                    console.log('[YS Shopline] Stale SDK result discarded for:', gatewayId,
                        '(gen', myGeneration, '→', sdkGeneration[gatewayId], ')');
                    sdkInitializing[gatewayId] = false;
                    return;
                }

                console.log('[YS Shopline] SDK Result:', result);
                console.log('[YS Shopline] SDK Result payment object:', result.payment);

                if (result.error) {
                    console.error('Shopline SDK Error:', result.error);
                    sdkInitializing[gatewayId] = false;
                    $container.data('sdk-initializing', false);

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

                // Store payment instance and clear initializing flag
                paymentInstances[gatewayId] = result.payment;
                sdkInitializing[gatewayId] = false;
                $container.data('sdk-initializing', false);
                $container.data('sdk-initialized', true);
                console.log('[YS Shopline] Payment instance stored for:', gatewayId);
                console.log('[YS Shopline] Payment instance has createPayment:', typeof result.payment?.createPayment);

                // Remove loading state - SDK should have rendered its content
                $container.find('.ys-shopline-loading').remove();

                // bindOnlyMode（$0 訂閱試用）：附加純綁卡提示
                if (serverConfig.bindOnlyMode && $container.find('.ys-bindcard-hint').length === 0) {
                    $container.append(
                        '<div class="ys-bindcard-hint" style="margin-top:12px;padding:10px 14px;' +
                        'background:#f0f7ff;border-left:3px solid #4a90e2;border-radius:4px;' +
                        'font-size:13px;color:#555;line-height:1.5;">' +
                        '<strong style="color:#4a90e2;">ℹ️ 試用期綁卡驗證</strong><br>' +
                        '試用期間不會扣款，試用結束後將依訂閱方案自動扣款。' +
                        '</div>'
                    );
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
                sdkInitializing[gatewayId] = false;
                $container.data('sdk-initializing', false);
                self.showError($container, e.message || 'SDK initialization failed');
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
         * Handle order placement
         *
         * @param {string} gatewayId Gateway ID
         * @return {boolean} Whether to proceed with form submission
         */
        placeOrder: function (gatewayId) {
            var self = this;
            var $form = $('form.checkout');

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
            paymentInstance.createPayment().then(function (result) {
                console.log('createPayment result:', result);
                console.log('createPayment result keys:', result ? Object.keys(result) : 'null');
                console.log('createPayment paySession:', result ? result.paySession : 'undefined');

                if (result.error) {
                    // Payment creation failed
                    var msg = result.error.message || ys_shopline_params.i18n.payment_failed || 'Payment failed';
                    console.error('createPayment error:', result.error);
                    self._isSubmitting = false;
                    $form.removeClass('processing').unblock();
                    self.showFormError(msg);
                    return false;
                }

                // Check if paySession exists
                if (!result.paySession) {
                    console.error('createPayment: No paySession returned', result);
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
                console.log('[YS Shopline] paySession type:', typeof result.paySession);
                console.log('[YS Shopline] paySession value:', paySessionValue);

                $form.find('input[name="ys_shopline_pay_session"]').remove();
                $form.append(
                    $('<input>').attr({
                        type: 'hidden',
                        name: 'ys_shopline_pay_session',
                        value: paySessionValue
                    })
                );

                // Add selected installment if applicable
                if (result.installment) {
                    $form.find('input[name="ys_shopline_installment"]').remove();
                    $form.append(
                        $('<input>').attr({
                            type: 'hidden',
                            name: 'ys_shopline_installment',
                            value: result.installment
                        })
                    );
                }

                // Add selected payment instrument if applicable
                if (result.paymentInstrumentId) {
                    $form.find('input[name="ys_shopline_payment_instrument_id"]').remove();
                    $form.append(
                        $('<input>').attr({
                            type: 'hidden',
                            name: 'ys_shopline_payment_instrument_id',
                            value: result.paymentInstrumentId
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
                var bindCardEnabled = self.isBindCardEnabled(gatewayId);

                console.log('[YS Shopline] createPayment result:', result);
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
                self.submitCheckoutAjax($form);

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
         */
        submitCheckoutAjax: function ($form) {
            var self = this;
            var gatewayId = this.getSelectedGateway();

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
                    console.log('[YS Shopline] WooCommerce response:', response);

                    // 移除現有訊息
                    $('.woocommerce-NoticeGroup-checkout, .woocommerce-error, .woocommerce-message').remove();

                    if (response.result === 'success') {
                        // 檢查是否有 nextAction 需要處理（3DS/Confirm）
                        if (response.nextAction) {
                            console.log('[YS Shopline] Got nextAction, processing with SDK...');
                            self.processNextAction(gatewayId, response.nextAction, response.returnUrl);
                        } else if (response.redirect) {
                            // 直接跳轉（無需額外驗證）
                            console.log('[YS Shopline] Redirecting to:', response.redirect);
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
                        console.warn('[YS Shopline] Unexpected response:', response);
                        self._isSubmitting = false;
                        $form.removeClass('processing').unblock();
                        self.showFormError('發生未預期的錯誤，請重試。');
                    }
                },
                error: function (xhr, status, error) {
                    console.error('[YS Shopline] AJAX error:', status, error);
                    console.error('[YS Shopline] Response:', xhr.responseText);
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
         */
        processNextAction: async function (gatewayId, nextAction, returnUrl) {
            var self = this;
            var $form = $('form.checkout');
            var paymentInstance = paymentInstances[gatewayId];

            console.log('[YS Shopline] processNextAction called', {
                gatewayId: gatewayId,
                hasInstance: !!paymentInstance,
                nextActionType: nextAction.type || 'unknown',
                nextActionKeys: nextAction ? Object.keys(nextAction) : [],
                nextAction: nextAction // 完整輸出 nextAction 內容
            });

            if (!paymentInstance) {
                console.error('[YS Shopline] No payment instance for nextAction processing');
                $form.removeClass('processing').unblock();
                self.showFormError('付款處理失敗，請重新整理頁面後重試。');
                return;
            }

            try {
                console.log('[YS Shopline] Calling payment.pay() with nextAction...');
                console.log('[YS Shopline] SDK will auto-redirect to returnUrl on success');

                var payResult = await paymentInstance.pay(nextAction);

                console.log('[YS Shopline] pay() returned:', payResult);

                // 根據 SDK 文件：
                // - 成功時 payResult 為 undefined，SDK 自動跳轉到 returnUrl
                // - 失敗時 payResult.error 有錯誤資訊
                // - 3DS 時 SDK 會自動處理，完成後跳轉到 returnUrl
                //
                // 如果執行到這裡且沒有 error，表示 SDK 沒有自動跳轉
                // 這可能是 SDK 版本或設定問題，作為備援手動跳轉
                if (payResult && payResult.error) {
                    console.error('[YS Shopline] pay() error:', payResult.error);
                    $form.removeClass('processing').unblock();
                    self.showFormError('付款失敗：' + (payResult.error.message || '未知錯誤'));
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
                self.showFormError('付款處理發生錯誤：' + (e.message || '未知錯誤'));
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
        showFormError: function (message) {
            var $form = $('form.checkout');

            // Remove existing errors
            $('.woocommerce-NoticeGroup-checkout, .woocommerce-error, .woocommerce-message').remove();

            // Add new error
            $form.prepend(
                '<div class="woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout">' +
                '<ul class="woocommerce-error" role="alert">' +
                '<li>' + this.escapeHtml(message) + '</li>' +
                '</ul>' +
                '</div>'
            );

            // Scroll to top
            $('html, body').animate({
                scrollTop: ($form.offset().top - 100)
            }, 500);

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

        /**
         * Get payment instance for gateway
         *
         * @param {string} gatewayId Gateway ID
         * @return {Object|null} Payment instance or null
         */
        getPaymentInstance: function (gatewayId) {
            return paymentInstances[gatewayId] || null;
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
         * @param {string} gatewayId Gateway ID
         */
        refreshGateway: function (gatewayId) {
            var config = GATEWAY_CONFIG[gatewayId];

            if (paymentInstances[gatewayId]) {
                delete paymentInstances[gatewayId];
            }
            if (sdkInitializing[gatewayId]) {
                delete sdkInitializing[gatewayId];
            }

            // Clear container data flags
            if (config) {
                var $container = $('#' + config.containerId);
                if ($container.length) {
                    $container.data('sdk-initializing', false);
                    $container.data('sdk-initialized', false);
                }
            }

            this.initSDK(gatewayId);
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

            // 監聽付款方式變更
            $form.on('change', 'input[name="payment_method"]', function () {
                var newGatewayId = $(this).val();
                if (newGatewayId && GATEWAY_CONFIG[newGatewayId]) {
                    ShoplineCheckout.clearConflictingInstances(newGatewayId);
                    ShoplineCheckout.initSDK(newGatewayId);
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

            if (!paymentInstance) {
                alert(ys_shopline_params.i18n.payment_not_ready || 'Payment not ready. Please wait and try again.');
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
                console.log('[YS Shopline] Pay-for-order createPayment result:', result);

                if (result.error) {
                    self._isSubmitting = false;
                    $form.removeClass('processing').unblock();
                    alert(result.error.message || 'Payment failed');
                    return;
                }

                if (!result.paySession) {
                    self._isSubmitting = false;
                    $form.removeClass('processing').unblock();
                    alert('Payment session creation failed. Please try again.');
                    return;
                }

                // 準備 AJAX 資料
                var paySessionValue = typeof result.paySession === 'object'
                    ? JSON.stringify(result.paySession)
                    : result.paySession;

                var urlParams = new URLSearchParams(window.location.search);
                var ajaxData = {
                    action: 'ys_shopline_pay_for_order',
                    nonce: ys_shopline_params.nonce,
                    order_id: self.getOrderId(),
                    order_key: urlParams.get('key') || '',
                    payment_method: gatewayId,
                    ys_shopline_pay_session: paySessionValue,
                    ys_shopline_bind_card_enabled: ShoplineCheckout.isBindCardEnabled(gatewayId) ? '1' : '0'
                };

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
                        console.log('[YS Shopline] Pay-for-order response:', response);

                        if (response.result === 'success') {
                            if (response.nextAction) {
                                // 需要 SDK 處理 nextAction（3DS/Confirm）
                                console.log('[YS Shopline] Pay-for-order: processing nextAction');
                                ShoplineCheckout.processNextAction(gatewayId, response.nextAction, response.returnUrl);
                            } else if (response.redirect) {
                                window.location.href = response.redirect;
                            }
                        } else {
                            self._isSubmitting = false;
                            $form.removeClass('processing').unblock();
                            var errorMsg = response.messages || response.message || '付款處理失敗，請重試。';
                            alert(errorMsg);
                        }
                    },
                    error: function (xhr, status, error) {
                        console.error('[YS Shopline] Pay-for-order AJAX error:', status, error);
                        self._isSubmitting = false;
                        $form.removeClass('processing').unblock();
                        alert('網路錯誤，請檢查連線後重試。');
                    }
                });

            }).catch(function (error) {
                console.error('[YS Shopline] Pay-for-order createPayment error:', error);
                self._isSubmitting = false;
                $form.removeClass('processing').unblock();
                alert(error.message || 'Payment error occurred');
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
                // SHOPLINE SDK 驗證 amount 必填且 > 0，但後端 API 會用 CardBind + amount=0 真正不扣款
                // SDK init 時的 amount 只是通過驗證，不會被實際計費
                var options = {
                    clientKey: serverConfig.clientKey,
                    merchantId: serverConfig.merchantId,
                    paymentMethod: 'CreditCard',
                    currency: serverConfig.currency || 'TWD',
                    amount: serverConfig.amount || 100, // SDK 驗證用，實際 API 以 CardBind + amount=0 處理
                    element: '#' + $container.attr('id'),
                    env: serverConfig.env || 'production'
                };

                // Customer token（必須有才能儲存卡片）
                if (serverConfig.customerToken) {
                    options.customerToken = serverConfig.customerToken;

                    // 強制啟用並儲存卡片
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
                } else {
                    $container.html('<div class="woocommerce-error">無法取得客戶資訊，請確認您已登入。</div>');
                    return;
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

                console.log('[YS Shopline] Add payment method SDK result:', result);

                if (result.error) {
                    console.error('[YS Shopline] Add payment method SDK error:', result.error);
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

            // 綁定提交事件
            $form.on('submit.ys_shopline_add_method', function (e) {
                // 檢查是否選擇了 Shopline 付款方式
                var selectedPayment = $('input[name="payment_method"]:checked').val();

                if (!selectedPayment || selectedPayment.indexOf('ys_shopline') !== 0) {
                    // 不是 Shopline 付款方式，讓 WooCommerce 處理
                    return true;
                }

                // 檢查是否已有 paySession
                if ($form.find('input[name="ys_shopline_pay_session"]').val()) {
                    console.log('[YS Shopline] Add method: paySession exists, submitting');
                    return true;
                }

                e.preventDefault();
                self.processAddPaymentMethod($form);
                return false;
            });

            console.log('[YS Shopline] Add payment method form events bound');
        },

        /**
         * Process add payment method
         *
         * @param {jQuery} $form Form element
         */
        processAddPaymentMethod: function ($form) {
            var self = this;

            if (!this.paymentInstance) {
                this.showError($form, '付款尚未準備就緒，請稍候再試。');
                return;
            }

            // Block form
            $form.addClass('processing').block({
                message: null,
                overlayCSS: { background: '#fff', opacity: 0.6 }
            });

            console.log('[YS Shopline] Creating payment for add_payment_method...');

            // 呼叫 SDK createPayment
            this.paymentInstance.createPayment().then(function (result) {
                console.log('[YS Shopline] Add method createPayment result:', result);

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

                // 添加 paySession 到表單
                var paySessionValue = result.paySession;
                if (typeof paySessionValue === 'object') {
                    paySessionValue = JSON.stringify(paySessionValue);
                }

                $form.find('input[name="ys_shopline_pay_session"]').remove();
                $form.append($('<input>').attr({
                    type: 'hidden',
                    name: 'ys_shopline_pay_session',
                    value: paySessionValue
                }));

                console.log('[YS Shopline] Add method paySession saved, submitting form...');

                // 提交表單到 WooCommerce
                $form.submit();

            }).catch(function (error) {
                console.error('[YS Shopline] Add method createPayment error:', error);
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
            // 移除現有錯誤
            $form.find('.woocommerce-error, .woocommerce-message').remove();

            // 添加新錯誤
            $form.prepend(
                '<ul class="woocommerce-error" role="alert"><li>' +
                $('<div>').text(message).html() +
                '</li></ul>'
            );

            // 捲動到錯誤位置
            $('html, body').animate({
                scrollTop: $form.offset().top - 100
            }, 500);
        }
    };

    // Initialize add payment method handler
    AddPaymentMethodHandler.init();

    // Expose to global scope
    window.AddPaymentMethodHandler = AddPaymentMethodHandler;
});
