/* global jQuery, ys_shopline_params, ShoplinePayments */
/**
 * Shopline Checkout SDK Handler
 *
 * Handles multiple independent payment gateways with embedded SDK.
 *
 * @package YS_Shopline_Payment
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

            // Bind events
            $(document.body).on('change', 'input[name="payment_method"]', function () {
                self.onPaymentMethodChange();
            });

            $(document.body).on('updated_checkout', function () {
                self.onUpdatedCheckout();
            });

            // Bind checkout events
            this.bindCheckoutEvents();

            // Init on load if Shopline gateway already selected
            this.onPaymentMethodChange();

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
         */
        onPaymentMethodChange: function () {
            var gatewayId = this.getSelectedGateway();

            if (gatewayId) {
                activeGateway = gatewayId;
                this.initSDK(gatewayId);
            } else {
                activeGateway = null;
            }
        },

        /**
         * Handle checkout update (cart changes, etc.)
         */
        onUpdatedCheckout: function () {
            // 重新綁定事件（表單可能被替換）
            this.bindCheckoutEvents();

            // 清除舊的 paySession（購物車更新後需要重新建立）
            $('form.checkout').find('input[name="ys_shopline_pay_session"]').remove();

            var gatewayId = this.getSelectedGateway();

            if (gatewayId) {
                activeGateway = gatewayId;

                // 只有在沒有已存在的實例且沒有正在初始化時才重新初始化
                // 注意：購物車金額變更時需要重新初始化 SDK
                if (!paymentInstances[gatewayId] && !sdkInitializing[gatewayId]) {
                    this.initSDK(gatewayId);
                }
            }
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
            $.ajax({
                type: 'POST',
                url: ys_shopline_params.ajax_url,
                data: {
                    action: 'ys_shopline_get_sdk_config',
                    nonce: ys_shopline_params.nonce,
                    gateway: gatewayId
                },
                success: function (response) {
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

            // Clear container
            $container.empty();

            try {
                // Build SDK options
                // Note: env 參數控制環境 (sandbox/production)
                var options = {
                    clientKey: serverConfig.clientKey,
                    merchantId: serverConfig.merchantId,
                    paymentMethod: gatewayConfig.paymentMethod,
                    currency: serverConfig.currency || 'TWD',
                    amount: serverConfig.amount || 0,
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

                console.log('[YS Shopline] SDK Result:', result);
                console.log('[YS Shopline] SDK Result payment object:', result.payment);

                if (result.error) {
                    console.error('Shopline SDK Error:', result.error);
                    sdkInitializing[gatewayId] = false;
                    $container.data('sdk-initializing', false);

                    // Provide helpful error messages based on error code
                    var errorMessage = result.error.message || 'Unknown error';
                    var errorCode = result.error.code;

                    if (errorCode === 2009) {
                        errorMessage = '認證失敗 (Access Denied)。請確認：1) merchantId 與 clientKey 配對正確 2) 網域已在 SHOPLINE 後台設定白名單';
                    }

                    self.showError($container, errorMessage + (errorCode ? ' (' + errorCode + ')' : ''));
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

            // Block UI
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
                    $form.removeClass('processing').unblock();
                    self.showFormError(msg);
                    return false;
                }

                // Check if paySession exists
                if (!result.paySession) {
                    console.error('createPayment: No paySession returned', result);
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
                        $form.removeClass('processing').unblock();
                        self.showFormError('發生未預期的錯誤，請重試。');
                    }
                },
                error: function (xhr, status, error) {
                    console.error('[YS Shopline] AJAX error:', status, error);
                    console.error('[YS Shopline] Response:', xhr.responseText);
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

    // Initialize on document ready
    ShoplineCheckout.init();

    // Expose to global scope for extensions
    window.ShoplineCheckout = ShoplineCheckout;
});
