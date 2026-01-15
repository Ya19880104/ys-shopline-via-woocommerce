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

            // Bind form submission for all Shopline gateways using event delegation
            $.each(GATEWAY_CONFIG, function (gatewayId) {
                $(document.body).on('checkout_place_order_' + gatewayId, function () {
                    console.log('[YS Shopline] checkout_place_order event triggered for:', gatewayId);
                    return self.placeOrder(gatewayId);
                });
            });

            // Intercept form submission directly for Shopline gateways
            // 使用更高優先級的事件監聽
            $('form.checkout, form.woocommerce-checkout').on('submit.ys_shopline', function (e) {
                var selectedGateway = self.getSelectedGateway();
                console.log('[YS Shopline] Form submit intercepted, selected gateway:', selectedGateway);

                if (selectedGateway) {
                    var $form = $(this);

                    // Check if we already have a pay session (means SDK already processed)
                    if ($form.find('input[name="ys_shopline_pay_session"]').val()) {
                        console.log('[YS Shopline] Pay session exists, allowing form submission');
                        return true; // Allow normal submission
                    }

                    // Check if payment instance is ready
                    if (!paymentInstances[selectedGateway]) {
                        console.error('[YS Shopline] Payment instance not ready for:', selectedGateway);
                        console.log('[YS Shopline] Available instances:', Object.keys(paymentInstances));
                        self.showFormError('付款元件尚未準備就緒，請稍候再試。');
                        return false;
                    }

                    // Prevent default submission and process with SDK
                    e.preventDefault();
                    e.stopPropagation();
                    e.stopImmediatePropagation();

                    console.log('[YS Shopline] Intercepting for Shopline payment');
                    self.placeOrder(selectedGateway);
                    return false;
                }
            });

            // 另外監聽結帳按鈕點擊
            $(document.body).on('click', '#place_order', function(e) {
                var selectedGateway = self.getSelectedGateway();
                console.log('[YS Shopline] Place order button clicked, gateway:', selectedGateway);

                if (selectedGateway) {
                    var $form = $('form.checkout, form.woocommerce-checkout');

                    // Check if we already have a pay session
                    if ($form.find('input[name="ys_shopline_pay_session"]').val()) {
                        console.log('[YS Shopline] Pay session exists, allowing click');
                        return true;
                    }

                    // Check if payment instance is ready
                    if (!paymentInstances[selectedGateway]) {
                        console.error('[YS Shopline] Payment instance not ready');
                        e.preventDefault();
                        e.stopPropagation();
                        self.showFormError('付款元件尚未準備就緒，請稍候再試。');
                        return false;
                    }

                    // Prevent and process
                    e.preventDefault();
                    e.stopPropagation();

                    console.log('[YS Shopline] Processing payment via button click');
                    self.placeOrder(selectedGateway);
                    return false;
                }
            });

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
            // Clear cached instances on checkout update
            paymentInstances = {};

            var gatewayId = this.getSelectedGateway();

            if (gatewayId) {
                activeGateway = gatewayId;
                this.initSDK(gatewayId);
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
                console.log('SDK already initialized for:', gatewayId);
                return;
            }

            var $container = $('#' + config.containerId);

            // If container doesn't exist, skip
            if (!$container.length) {
                return;
            }

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
                        var errorMsg = (response.data && response.data.message)
                            ? response.data.message
                            : ys_shopline_params.i18n.config_error || 'Configuration error';
                        self.showError($container, errorMsg);
                    }
                },
                error: function (jqXHR, textStatus) {
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
                self.showError($container, ys_shopline_params.i18n.sdk_not_loaded || 'Payment SDK not loaded');
                return;
            }

            sdkLoaded = true;

            // Validate config
            if (!serverConfig.clientKey || !serverConfig.merchantId) {
                self.showError($container, ys_shopline_params.i18n.missing_config || 'Missing configuration');
                return;
            }

            // Clear container
            $container.empty();

            try {
                // Build SDK options
                // Note: SHOPLINE SDK may use 'accessMode' or auto-detect from clientKey prefix (pk_sandbox_ vs pk_live_)
                var options = {
                    clientKey: serverConfig.clientKey,
                    merchantId: serverConfig.merchantId,
                    paymentMethod: gatewayConfig.paymentMethod,
                    currency: serverConfig.currency || 'TWD',
                    amount: serverConfig.amount || 0,
                    element: '#' + gatewayConfig.containerId,
                    env: serverConfig.env || 'production',
                    accessMode: serverConfig.env === 'sandbox' ? 'sandbox' : 'production'
                };

                // Add customer token if available (for saved cards)
                if (serverConfig.customerToken) {
                    options.customerToken = serverConfig.customerToken;
                }

                // Configure card binding for credit card gateways
                if (gatewayConfig.supportsBindCard) {
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

                // Configure installment for supported gateways
                if (gatewayConfig.supportsInstallment && serverConfig.installments) {
                    options.installment = {
                        options: serverConfig.installments
                    };
                }

                // Apply any additional server-side options
                if (serverConfig.sdkOptions) {
                    options = $.extend(true, options, serverConfig.sdkOptions);
                }

                // Debug: Log SDK options (without sensitive data)
                console.log('Shopline SDK Init:', {
                    merchantId: options.merchantId ? options.merchantId.substring(0, 8) + '...' : '(empty)',
                    clientKey: options.clientKey ? options.clientKey.substring(0, 12) + '...' : '(empty)',
                    paymentMethod: options.paymentMethod,
                    env: options.env,
                    accessMode: options.accessMode,
                    amount: options.amount,
                    currency: options.currency,
                    element: options.element
                });

                // Initialize SDK
                var result = await ShoplinePayments(options);

                console.log('[YS Shopline] SDK Result:', result);
                console.log('[YS Shopline] SDK Result payment object:', result.payment);

                if (result.error) {
                    console.error('Shopline SDK Error:', result.error);

                    // Provide helpful error messages based on error code
                    var errorMessage = result.error.message || 'Unknown error';
                    var errorCode = result.error.code;

                    if (errorCode === 2009) {
                        errorMessage = '認證失敗 (Access Denied)。請確認：1) merchantId 與 clientKey 配對正確 2) 網域已在 SHOPLINE 後台設定白名單';
                    }

                    self.showError($container, errorMessage + (errorCode ? ' (' + errorCode + ')' : ''));
                    return;
                }

                // Store payment instance
                paymentInstances[gatewayId] = result.payment;
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
                self.showError($container, e.message || 'SDK initialization failed');
            }
        },

        /**
         * Handle order placement
         *
         * @param {string} gatewayId Gateway ID
         * @return {boolean} Whether to proceed with form submission
         */
        placeOrder: function (gatewayId) {
            var self = this;
            var paymentInstance = paymentInstances[gatewayId];

            console.log('placeOrder called for:', gatewayId);
            console.log('paymentInstance:', paymentInstance);

            if (!paymentInstance) {
                // Instance not ready, show error
                console.error('Payment instance not found for:', gatewayId);
                var errorMsg = ys_shopline_params.i18n.payment_not_ready || 'Payment not ready. Please wait and try again.';
                self.showFormError(errorMsg);
                return false;
            }

            var $form = $('form.checkout');

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
                $form.find('input[name="ys_shopline_pay_session"]').remove();
                $form.append(
                    $('<input>').attr({
                        type: 'hidden',
                        name: 'ys_shopline_pay_session',
                        value: result.paySession
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

                // Add save card preference
                // SDK 可能返回 saveCard, bindCard, 或 savePaymentInstrument
                var saveCardValue = false;
                if (typeof result.saveCard !== 'undefined') {
                    saveCardValue = result.saveCard;
                } else if (typeof result.bindCard !== 'undefined') {
                    saveCardValue = result.bindCard;
                } else if (typeof result.savePaymentInstrument !== 'undefined') {
                    saveCardValue = result.savePaymentInstrument;
                }

                console.log('saveCard value:', saveCardValue);

                $form.find('input[name="ys_shopline_save_card"]').remove();
                $form.append(
                    $('<input>').attr({
                        type: 'hidden',
                        name: 'ys_shopline_save_card',
                        value: saveCardValue ? '1' : '0'
                    })
                );

                console.log('[YS Shopline] Submitting form to WooCommerce...');

                // Check if wc_checkout_params exists
                if (typeof wc_checkout_params === 'undefined') {
                    console.error('[YS Shopline] wc_checkout_params is not defined!');
                    $form.removeClass('processing').unblock();
                    self.showFormError('結帳設定錯誤，請重新整理頁面。');
                    return false;
                }

                console.log('[YS Shopline] checkout_url:', wc_checkout_params.checkout_url);

                // Remove the processing class first to allow re-submission
                $form.removeClass('processing');

                // Trigger WooCommerce checkout via AJAX
                // We need to manually trigger the WooCommerce checkout process
                var formData = $form.serialize();

                console.log('[YS Shopline] Form data length:', formData.length);
                console.log('[YS Shopline] Has ys_shopline_pay_session:', formData.indexOf('ys_shopline_pay_session') !== -1);

                $.ajax({
                    type: 'POST',
                    url: wc_checkout_params.checkout_url,
                    data: formData,
                    dataType: 'json',
                    success: function(response) {
                        console.log('[YS Shopline] WooCommerce checkout response:', response);

                        // Remove any existing notices
                        $('.woocommerce-error, .woocommerce-message, .woocommerce-info').remove();

                        if (response.result === 'success') {
                            // Redirect to the specified URL
                            if (response.redirect) {
                                console.log('[YS Shopline] Redirecting to:', response.redirect);
                                window.location.href = response.redirect;
                            }
                        } else if (response.result === 'failure') {
                            // Show error message
                            $form.removeClass('processing').unblock();

                            if (response.messages) {
                                // Insert WooCommerce error messages
                                $form.prepend(response.messages);
                                $('html, body').animate({
                                    scrollTop: $form.offset().top - 100
                                }, 500);
                            } else {
                                self.showFormError(response.message || '付款處理失敗，請重試。');
                            }
                        } else {
                            console.warn('[YS Shopline] Unexpected response result:', response.result);
                            $form.removeClass('processing').unblock();
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('[YS Shopline] AJAX error:', status, error);
                        console.error('[YS Shopline] XHR response:', xhr.responseText);
                        $form.removeClass('processing').unblock();
                        self.showFormError('網路錯誤，請檢查連線後重試。');
                    }
                });

            }).catch(function (error) {
                console.error('Shopline createPayment error:', error);
                $form.removeClass('processing').unblock();
                self.showFormError(error.message || ys_shopline_params.i18n.payment_error || 'Payment error occurred');
            });

            // Prevent immediate form submission
            return false;
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
            if (paymentInstances[gatewayId]) {
                delete paymentInstances[gatewayId];
            }
            this.initSDK(gatewayId);
        }
    };

    // Initialize on document ready
    ShoplineCheckout.init();

    // Expose to global scope for extensions
    window.ShoplineCheckout = ShoplineCheckout;
});
