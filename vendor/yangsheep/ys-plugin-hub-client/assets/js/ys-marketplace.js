/**
 * YS Plugin Hub Client - 市集頁面前端邏輯
 * v2.0.2
 *
 * 所有操作走 AJAX，禁止 form POST。
 */
(function ($) {
    'use strict';

    var config = window.ysHubClient || {};
    var i18n = config.i18n || {};

    /**
     * 市集模組
     */
    var Marketplace = {

        /** 目前篩選的分類 */
        currentCategory: 'all',

        currentPlatform: 'all',

        /** 目前搜尋關鍵字 */
        searchKeyword: '',

        /** 外掛原始資料 */
        pluginsData: [],

        /** 分類資料 */
        categoriesData: [],

        platformsData: [],

        /** 公告資料 */
        announcementsData: [],

        /**
         * 初始化
         */
        init: function () {
            this.bindEvents();
            this.loadPlugins();
        },

        normalizePlatforms: function (platforms, plugins) {
            var defaults = [
                {slug: 'ys-cart', name: 'YS CART'},
                {slug: 'woocommerce', name: 'WooCommerce'},
                {slug: 'wordpress', name: 'WordPress'}
            ];
            var source = Array.isArray(platforms) && platforms.length ? platforms : defaults;
            var seen = {};
            var result = [];

            $.each(source, function (i, platform) {
                var slug = platform.slug || '';
                if (!slug || seen[slug]) return;
                seen[slug] = true;
                result.push({
                    slug: slug,
                    name: platform.name || slug
                });
            });

            $.each(plugins || [], function (i, plugin) {
                var slug = Marketplace.getPluginPlatform(plugin);
                if (!slug || seen[slug]) return;
                seen[slug] = true;
                result.push({
                    slug: slug,
                    name: plugin.platform_label || slug
                });
            });

            return result;
        },

        getPluginPlatform: function (plugin) {
            if (plugin.platform) return plugin.platform;
            if (plugin.slug === 'ys-cart') return 'ys-cart';
            if (plugin.category === 'payment' || plugin.category === 'shipping' || plugin.category === 'checkout' || plugin.category === 'cart') {
                return 'woocommerce';
            }
            if ((plugin.name || '').toLowerCase().indexOf('woocommerce') !== -1) return 'woocommerce';
            return 'wordpress';
        },

        findCategoryLabel: function (category) {
            if (!category) return '';
            for (var i = 0; i < this.categoriesData.length; i++) {
                if (this.categoriesData[i].slug === category) {
                    return this.categoriesData[i].name || category;
                }
            }
            return category;
        },

        findPlatformLabel: function (platform) {
            if (!platform) return '';
            for (var i = 0; i < this.platformsData.length; i++) {
                if (this.platformsData[i].slug === platform) {
                    return this.platformsData[i].name || platform;
                }
            }
            if (platform === 'ys-cart') return 'YS CART';
            if (platform === 'woocommerce') return 'WooCommerce';
            if (platform === 'wordpress') return 'WordPress';
            return platform;
        },

        renderPlatformTabs: function () {
            var self = this;
            var $tabs = $('#ys-platform-tabs');
            if (!$tabs.length) return;

            $tabs.find('.ys-platform-tab[data-platform!="all"]').remove();

            $.each(self.platformsData, function (i, platform) {
                var $tab = $('<button type="button" class="ys-filter-tab ys-platform-tab" data-platform="' + self.escAttr(platform.slug) + '">' +
                    self.escHtml(platform.name) + '</button>');
                $tabs.append($tab);
            });

            $tabs.find('.ys-platform-tab').removeClass('active');
            $tabs.find('.ys-platform-tab[data-platform="' + self.escAttr(self.currentPlatform) + '"]').addClass('active');
        },

        /**
         * 綁定事件
         */
        bindEvents: function () {
            var self = this;

            $(document).on('click', '.ys-platform-tab', function (e) {
                e.preventDefault();
                self.currentPlatform = $(this).data('platform') || 'all';
                self.currentCategory = 'all';
                $('.ys-platform-tab').removeClass('active');
                $(this).addClass('active');
                self.renderCategoryTabs();
                self.renderPlugins();
            });

            // 分類篩選
            $(document).on('click', '.ys-filter-tab:not(.ys-platform-tab)', function (e) {
                e.preventDefault();
                var category = $(this).data('category');
                self.currentCategory = category;
                $('.ys-filter-tabs .ys-filter-tab').removeClass('active');
                $(this).addClass('active');
                self.renderPlugins();
            });

            // 搜尋
            $(document).on('input', '#ys-search-input', function () {
                self.searchKeyword = $(this).val().trim().toLowerCase();
                self.renderPlugins();
            });

            // 安裝外掛
            $(document).on('click', '.ys-install-btn', function (e) {
                e.preventDefault();
                if (!confirm(i18n.confirmInstall)) return;
                var btn = $(this);
                var slug = btn.data('slug');
                var version = btn.data('version');
                self.installPlugin(btn, slug, version);
            });

            // 更新外掛
            $(document).on('click', '.ys-update-btn', function (e) {
                e.preventDefault();
                if (!confirm(i18n.confirmUpdate)) return;
                var btn = $(this);
                var slug = btn.data('slug');
                var version = btn.data('version');
                self.updatePlugin(btn, slug, version);
            });

            // 啟用外掛
            $(document).on('click', '.ys-activate-btn', function (e) {
                e.preventDefault();
                var btn = $(this);
                var slug = btn.data('slug');
                self.activatePlugin(btn, slug);
            });

            // 停用外掛
            $(document).on('click', '.ys-deactivate-btn', function (e) {
                e.preventDefault();
                var btn = $(this);
                var slug = btn.data('slug');
                self.deactivatePlugin(btn, slug);
            });

            // 刪除外掛
            $(document).on('click', '.ys-delete-btn', function (e) {
                e.preventDefault();
                var btn = $(this);
                var slug = btn.data('slug');
                if (confirm(i18n.confirmDelete || '確定要刪除此外掛？此操作無法復原。')) {
                    self.deletePlugin(btn, slug);
                }
            });

            // 刷新市集
            $(document).on('click', '#ys-refresh-btn', function (e) {
                e.preventDefault();
                self.refreshMarketplace($(this));
            });

            // 儲存設定
            $(document).on('click', '#ys-save-settings', function (e) {
                e.preventDefault();
                Settings.save($(this));
            });

            // 自動檢查更新 checkbox 變更時自動儲存
            $(document).on('change', '#ys-auto-check', function () {
                Settings.save($('#ys-save-settings'));
            });

            // 產生 Site Key
            $(document).on('click', '#ys-generate-key', function (e) {
                e.preventDefault();
                Settings.generateSiteKey($(this));
            });

            // 關閉公告
            $(document).on('click', '.ys-announcement-close', function () {
                var $ann = $(this).closest('.ys-announcement');
                var id = $ann.data('id');
                self.dismissAnnouncement(id);
                $ann.slideUp(200, function () {
                    $(this).remove();
                    // 若全部公告已關閉，隱藏容器
                    if ($('#ys-announcements .ys-announcement').length === 0) {
                        $('#ys-announcements').hide();
                    }
                });
            });
        },

        /**
         * 載入外掛列表（AJAX）
         */
        loadPlugins: function () {
            var self = this;

            self.showSkeleton();
            self.setHubStatus('checking', i18n.connecting || '連線中...');

            $.ajax({
                url: config.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ys_hub_client_get_marketplace',
                    nonce: config.nonce
                },
                success: function (response) {
                    if (response.success && response.data) {
                        // 連線成功
                        self.setHubStatus('ok', i18n.connected || '已連線');

                        // 處理兩種格式：直接陣列或 {plugins: [...]} 物件
                        var plugins = response.data.plugins || [];
                        if (plugins.plugins && Array.isArray(plugins.plugins)) {
                            plugins = plugins.plugins;
                        }
                        if (!Array.isArray(plugins)) {
                            plugins = [];
                        }
                        self.pluginsData = plugins;

                        self.platformsData = self.normalizePlatforms(response.data.platforms || [], plugins);
                        self.renderPlatformTabs();

                        // 處理分類資料
                        var categories = response.data.categories || [];
                        if (Array.isArray(categories)) {
                            self.categoriesData = categories;
                            self.renderCategoryTabs();
                        }

                        // 處理公告資料
                        var announcements = response.data.announcements || [];
                        if (Array.isArray(announcements)) {
                            self.announcementsData = announcements;
                            self.renderAnnouncements();
                        }

                        self.renderPlugins();
                    } else {
                        // API 回傳錯誤
                        var msg = (response.data && response.data.message)
                            ? response.data.message
                            : i18n.connectionFail;
                        var code = (response.data && response.data.code) || '';

                        self.setHubStatus('error', i18n.disconnected || '連線異常');
                        self.showConnectionError(msg, code);
                        self.showError(msg);
                    }
                },
                error: function (xhr) {
                    var reason = '';
                    if (xhr.status === 0) {
                        reason = i18n.networkError || 'Hub 伺服器無法連線（網路逾時或伺服器關閉）';
                    } else if (xhr.status >= 500) {
                        reason = (i18n.serverError || 'Hub 伺服器錯誤') + ' (HTTP ' + xhr.status + ')';
                    } else if (xhr.status === 403) {
                        reason = i18n.blocked || '此站台已被封鎖';
                    } else {
                        reason = (i18n.connectionFail || '連線失敗') + ' (HTTP ' + xhr.status + ')';
                    }

                    self.setHubStatus('error', i18n.disconnected || '連線異常');
                    self.showConnectionError(reason, 'http_' + xhr.status);
                    self.showError(reason);
                }
            });
        },

        /**
         * 設定 Hub 連線狀態燈號
         *
         * @param {string} status  'checking' | 'ok' | 'error' | 'breaker'
         * @param {string} text    狀態文字
         */
        setHubStatus: function (status, text) {
            var $el = $('#ys-hub-status');
            $el.removeClass('ys-hub-status-checking ys-hub-status-ok ys-hub-status-error ys-hub-status-breaker')
               .addClass('ys-hub-status-' + status);
            $el.find('.ys-hub-status-text').text(text);
            $el.attr('title', text);
        },

        /**
         * 顯示連線錯誤為公告
         *
         * @param {string} message 錯誤訊息
         * @param {string} code    錯誤代碼
         */
        showConnectionError: function (message, code) {
            var $wrap = $('#ys-announcements');
            var errorId = 'ys-conn-error';

            // 移除舊的連線錯誤公告
            $('#' + errorId).remove();

            var html = '<div id="' + errorId + '" class="ys-announcement ys-announcement-warning">' +
                '<div class="ys-announcement-content">' +
                '<strong><span class="dashicons dashicons-warning"></span> ' +
                this.escHtml(i18n.hubConnectionIssue || 'Hub 連線異常') + '</strong>' +
                '<p>' + this.escHtml(message) + '</p>';

            // 如果是熔斷，說明自動恢復時間
            if (code === 'circuit_breaker') {
                html += '<p style="font-size:12px;opacity:0.8;">' +
                    this.escHtml(i18n.circuitBreakerNote || '系統將在 30 分鐘後自動重新嘗試連線。') +
                    '</p>';
            }

            html += '</div></div>';

            $wrap.prepend(html).show();
        },

        /**
         * 渲染外掛卡片
         */
        renderPlugins: function () {
            var self = this;
            var plugins = self.filterPlugins();
            var $grid = $('#ys-plugin-grid');

            $grid.empty();

            if (!plugins || plugins.length === 0) {
                $grid.html(
                    '<div class="ys-empty-state">' +
                    '<span class="dashicons dashicons-plugins-checked"></span>' +
                    '<p>' + self.escHtml(i18n.noPlugins) + '</p>' +
                    '</div>'
                );
                return;
            }

            $.each(plugins, function (i, plugin) {
                $grid.append(self.buildCard(plugin));
            });
        },

        /**
         * 篩選外掛
         */
        filterPlugins: function () {
            var self = this;
            var result = self.pluginsData;

            if (self.currentPlatform !== 'all') {
                result = $.grep(result, function (p) {
                    return self.getPluginPlatform(p) === self.currentPlatform;
                });
            }

            // 分類篩選
            if (self.currentCategory !== 'all') {
                result = $.grep(result, function (p) {
                    return p.category === self.currentCategory;
                });
            }

            // 搜尋篩選
            if (self.searchKeyword) {
                var kw = self.searchKeyword;
                result = $.grep(result, function (p) {
                    var name = (p.name || '').toLowerCase();
                    var desc = (p.description || '').toLowerCase();
                    var slug = (p.slug || '').toLowerCase();
                    return name.indexOf(kw) !== -1
                        || desc.indexOf(kw) !== -1
                        || slug.indexOf(kw) !== -1;
                });
            }

            return result;
        },

        /**
         * 建構外掛卡片 HTML
         */
        buildCard: function (plugin) {
            var slug = this.escAttr(plugin.slug || '');
            var name = this.escHtml(plugin.name || slug);
            var version = this.escHtml(plugin.version || '');
            var description = this.escHtml(plugin.description || '');
            var icon = plugin.icon || 'dashicons-admin-plugins';
            var status = plugin.status || plugin.local_status || 'not_installed';
            var localVersion = plugin.local_version || '';
            var priceType = plugin.price_type || 'free';
            var priceAmount = plugin.price_amount || '';
            var externalUrl = plugin.external_url || '';
            var infoUrl = plugin.info_url || '';
            var platformSlug = this.getPluginPlatform(plugin);
            var platformLabel = plugin.platform_label || this.findPlatformLabel(platformSlug);
            var categoryLabel = plugin.category_label || this.findCategoryLabel(plugin.category || '');

            // 價格徽章
            var priceBadgeHtml = '';
            if (priceType === 'paid') {
                priceBadgeHtml = '<span class="ys-price-badge-paid">' +
                    this.escHtml(priceAmount || '付費') + '</span>';
            } else {
                priceBadgeHtml = '<span class="ys-price-badge-free">' +
                    this.escHtml(i18n.free || '免費') + '</span>';
            }

            // 狀態徽章 + 操作按鈕
            var badgeHtml = '';
            var actionHtml = '';

            // 付費外掛不顯示安裝/更新按鈕，改顯示「查看外掛」
            if (priceType === 'paid') {
                badgeHtml = priceBadgeHtml;
                if (externalUrl) {
                    actionHtml = '<button type="button" class="ys-btn ys-btn-external ys-btn-sm" ' +
                        'onclick="window.open(\'' + this.escAttr(externalUrl) + '\', \'_blank\')">' +
                        '<span class="dashicons dashicons-external" style="font-size:14px;width:14px;height:14px;"></span> ' +
                        this.escHtml(i18n.viewPlugin || '查看外掛') + '</button>';
                }
            } else if (status === 'active') {
                // 已啟用
                badgeHtml = priceBadgeHtml +
                    '<span class="ys-badge ys-badge-active">' + this.escHtml(i18n.active || '已啟用') + '</span>';
                if (plugin.update_available) {
                    // 已啟用且有更新
                    actionHtml = '<button type="button" class="ys-btn ys-btn-primary ys-btn-sm ys-update-btn" ' +
                        'data-slug="' + slug + '" data-version="' + version + '">' +
                        this.escHtml(i18n.update || '更新') + ' v' + version + '</button>';
                } else {
                    // 已啟用無更新 → 停用按鈕
                    actionHtml = '<button type="button" class="ys-btn ys-btn-muted ys-btn-sm ys-deactivate-btn" ' +
                        'data-slug="' + slug + '">' +
                        this.escHtml(i18n.deactivate || '停用') + '</button>';
                }
            } else if (status === 'installed' && plugin.update_available) {
                // 已安裝但有更新（未啟用）
                badgeHtml = priceBadgeHtml +
                    '<span class="ys-badge ys-badge-installed">' + this.escHtml(i18n.installed || '已安裝') + '</span>';
                actionHtml = '<button type="button" class="ys-btn ys-btn-primary ys-btn-sm ys-update-btn" ' +
                    'data-slug="' + slug + '" data-version="' + version + '">' +
                    this.escHtml(i18n.update || '更新') + ' v' + version + '</button>';
            } else if (status === 'installed') {
                // 已安裝未啟用 → 啟用 + 刪除按鈕
                badgeHtml = priceBadgeHtml +
                    '<span class="ys-badge ys-badge-installed">' + this.escHtml(i18n.installed || '已安裝') + '</span>';
                actionHtml = '<button type="button" class="ys-btn ys-btn-success ys-btn-sm ys-activate-btn" ' +
                    'data-slug="' + slug + '">' +
                    (i18n.activate || '啟用') + '</button>' +
                    ' <button type="button" class="ys-btn ys-btn-danger-text ys-btn-sm ys-delete-btn" ' +
                    'data-slug="' + slug + '">' +
                    this.escHtml(i18n.deletePlugin || '刪除') + '</button>';
            } else {
                // 未安裝
                badgeHtml = priceBadgeHtml;
                actionHtml = '<button type="button" class="ys-btn ys-btn-outline ys-btn-sm ys-install-btn" ' +
                    'data-slug="' + slug + '" data-version="' + version + '">' +
                    this.escHtml(i18n.install || '安裝') + '</button>';
            }

            var versionLabel = localVersion
                ? 'v' + this.escHtml(localVersion)
                : 'v' + version;
            var taxonomyBadges = [];
            if (platformLabel) {
                taxonomyBadges.push(
                    '<span class="ys-plugin-taxonomy-badge ys-plugin-platform-badge">' + this.escHtml(platformLabel) + '</span>'
                );
            }
            if (categoryLabel && (!platformLabel || categoryLabel.toLowerCase() !== platformLabel.toLowerCase())) {
                taxonomyBadges.push(
                    '<span class="ys-plugin-taxonomy-badge ys-plugin-category-badge">' + this.escHtml(categoryLabel) + '</span>'
                );
            }
            if (taxonomyBadges.length) {
                versionLabel = '<span class="ys-plugin-taxonomy-tags">' + taxonomyBadges.join('') + '</span>' +
                    '<span class="ys-plugin-version-number">' + versionLabel + '</span>';
            }

            return '<div class="ys-plugin-card" data-slug="' + slug + '">' +
                '<div class="ys-plugin-card-header">' +
                '<div class="ys-plugin-icon"><span class="dashicons ' + this.escAttr(icon) + '"></span></div>' +
                '<div class="ys-plugin-meta">' +
                '<h3 class="ys-plugin-name">' + (infoUrl ? '<a href="' + this.escAttr(infoUrl) + '" target="_blank" rel="noopener noreferrer">' + name + '</a>' : name) + '</h3>' +
                '<span class="ys-plugin-version">' + versionLabel + '</span>' +
                '</div></div>' +
                '<div class="ys-plugin-description">' + description + '</div>' +
                '<div class="ys-plugin-card-footer">' +
                '<div class="ys-card-footer-left">' + badgeHtml + '</div>' +
                '<div class="ys-card-footer-right">' + actionHtml + '</div>' +
                '</div></div>';
        },

        /**
         * 整張卡片重新渲染（安裝/更新/啟用後使用）
         */
        replaceCard: function (slug, pluginData) {
            var $oldCard = $('.ys-plugin-card[data-slug="' + Marketplace.escAttr(slug) + '"]');
            if ($oldCard.length && pluginData) {
                var newCardHtml = Marketplace.buildCard(pluginData);
                $oldCard.replaceWith(newCardHtml);
            }
        },

        /**
         * 安裝外掛
         */
        installPlugin: function (btn, slug, version) {
            var origText = btn.text();
            btn.prop('disabled', true).html('<span class="ys-spinner"></span> ' + this.escHtml(i18n.installing || '安裝中...'));

            $.ajax({
                url: config.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ys_hub_client_install_plugin',
                    nonce: config.nonce,
                    slug: slug,
                    version: version
                },
                success: function (response) {
                    if (response.success) {
                        Toast.show(response.data.message || i18n.success, 'success');
                        // 整張卡片用回傳的 plugin data 重新渲染
                        if (response.data.plugin) {
                            Marketplace.replaceCard(slug, response.data.plugin);
                        }
                    } else {
                        Toast.show(response.data.message || i18n.failed, 'error');
                        btn.prop('disabled', false).text(origText);
                    }
                },
                error: function () {
                    Toast.show(i18n.failed, 'error');
                    btn.prop('disabled', false).text(origText);
                }
            });
        },

        /**
         * 更新外掛
         */
        updatePlugin: function (btn, slug, version) {
            var origText = btn.text();
            btn.prop('disabled', true).html('<span class="ys-spinner"></span> ' + this.escHtml(i18n.updating));

            $.ajax({
                url: config.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ys_hub_client_update_plugin',
                    nonce: config.nonce,
                    slug: slug,
                    version: version
                },
                success: function (response) {
                    if (response.success) {
                        Toast.show(response.data.message || i18n.success, 'success');
                        if (response.data.plugin) {
                            Marketplace.replaceCard(slug, response.data.plugin);
                        }
                    } else {
                        Toast.show(response.data.message || i18n.failed, 'error');
                        btn.prop('disabled', false).text(origText);
                    }
                },
                error: function () {
                    Toast.show(i18n.failed, 'error');
                    btn.prop('disabled', false).text(origText);
                }
            });
        },

        /**
         * 啟用外掛
         */
        activatePlugin: function (btn, slug) {
            btn.prop('disabled', true).html('<span class="ys-spinner"></span> ' + (i18n.activating || '啟用中...'));

            $.ajax({
                url: config.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ys_hub_client_activate_plugin',
                    nonce: config.nonce,
                    slug: slug
                },
                success: function (response) {
                    if (response.success) {
                        Toast.show(response.data.message || (i18n.activated || '已啟用'), 'success');
                        if (response.data.plugin) {
                            Marketplace.replaceCard(slug, response.data.plugin);
                        }
                    } else {
                        Toast.show(response.data.message || i18n.failed, 'error');
                        btn.prop('disabled', false).text(i18n.activate || '啟用');
                    }
                },
                error: function () {
                    Toast.show(i18n.failed, 'error');
                    btn.prop('disabled', false).text(i18n.activate || '啟用');
                }
            });
        },

        /**
         * 停用外掛
         */
        deactivatePlugin: function (btn, slug) {
            btn.prop('disabled', true).html('<span class="ys-spinner"></span> ' + (i18n.deactivating || '停用中...'));

            $.ajax({
                url: config.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ys_hub_client_deactivate_plugin',
                    nonce: config.nonce,
                    slug: slug
                },
                success: function (response) {
                    if (response.success) {
                        Toast.show(response.data.message || '已停用', 'success');
                        // 如果是最後一個 YS 外掛 → 跳轉到外掛頁面
                        if (response.data.is_last && response.data.redirect) {
                            Toast.show(i18n.lastPluginWarning || '最後一個 YS 外掛已停用，即將跳轉到外掛管理頁面...', 'warning');
                            setTimeout(function () {
                                window.location.href = response.data.redirect;
                            }, 1500);
                            return;
                        }
                        if (response.data.plugin) {
                            Marketplace.replaceCard(slug, response.data.plugin);
                        }
                    } else {
                        Toast.show(response.data.message || i18n.failed, 'error');
                        btn.prop('disabled', false).text(i18n.deactivate || '停用');
                    }
                },
                error: function () {
                    Toast.show(i18n.failed, 'error');
                    btn.prop('disabled', false).text(i18n.deactivate || '停用');
                }
            });
        },

        /**
         * 刪除外掛
         */
        deletePlugin: function (btn, slug) {
            btn.prop('disabled', true).html('<span class="ys-spinner"></span> ' + (i18n.deleting || '刪除中...'));

            $.ajax({
                url: config.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ys_hub_client_delete_plugin',
                    nonce: config.nonce,
                    slug: slug
                },
                success: function (response) {
                    if (response.success) {
                        Toast.show(response.data.message || '已刪除', 'success');
                        // 如果沒有剩餘 YS 外掛 → 跳轉
                        if (response.data.redirect) {
                            setTimeout(function () {
                                window.location.href = response.data.redirect;
                            }, 1500);
                            return;
                        }
                        // 從市集重新載入（刪除後卡片回到「安裝」狀態）
                        var $card = $('.ys-plugin-card[data-slug="' + Marketplace.escAttr(slug) + '"]');
                        // 更新本地資料中的狀態
                        for (var i = 0; i < Marketplace.pluginsData.length; i++) {
                            if (Marketplace.pluginsData[i].slug === slug) {
                                Marketplace.pluginsData[i].status = 'not_installed';
                                Marketplace.pluginsData[i].local_status = 'not_installed';
                                Marketplace.pluginsData[i].local_version = '';
                                Marketplace.pluginsData[i].update_available = false;
                                Marketplace.replaceCard(slug, Marketplace.pluginsData[i]);
                                break;
                            }
                        }
                    } else {
                        Toast.show(response.data.message || i18n.failed, 'error');
                        btn.prop('disabled', false).text(i18n.deletePlugin || '刪除');
                    }
                },
                error: function () {
                    Toast.show(i18n.failed, 'error');
                    btn.prop('disabled', false).text(i18n.deletePlugin || '刪除');
                }
            });
        },

        /**
         * 強制刷新市集
         */
        refreshMarketplace: function (btn) {
            var self = this;
            var origText = btn.text();
            btn.prop('disabled', true).html('<span class="ys-spinner ys-spinner-dark"></span> ' + this.escHtml(i18n.refreshing));

            $.ajax({
                url: config.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ys_hub_client_refresh_marketplace',
                    nonce: config.nonce
                },
                success: function (response) {
                    btn.prop('disabled', false).text(origText);
                    if (response.success && response.data && response.data.plugins) {
                        self.pluginsData = response.data.plugins;
                        self.platformsData = self.normalizePlatforms(response.data.platforms || [], self.pluginsData);
                        self.renderPlatformTabs();

                        // 更新分類
                        if (response.data.categories && Array.isArray(response.data.categories)) {
                            self.categoriesData = response.data.categories;
                            self.renderCategoryTabs();
                        }

                        // 更新公告
                        if (response.data.announcements && Array.isArray(response.data.announcements)) {
                            self.announcementsData = response.data.announcements;
                            self.renderAnnouncements();
                        }

                        self.renderPlugins();
                        Toast.show(response.data.message || i18n.success, 'success');
                    } else {
                        var msg = (response.data && response.data.message) ? response.data.message : i18n.failed;
                        Toast.show(msg, 'error');
                    }
                },
                error: function () {
                    btn.prop('disabled', false).text(origText);
                    Toast.show(i18n.failed, 'error');
                }
            });
        },

        /**
         * 顯示 Skeleton loading
         */
        showSkeleton: function () {
            var $grid = $('#ys-plugin-grid');
            $grid.empty();
            for (var i = 0; i < 6; i++) {
                $grid.append(
                    '<div class="ys-skeleton-card">' +
                    '<div class="ys-skeleton-header">' +
                    '<div class="ys-skeleton ys-skeleton-icon"></div>' +
                    '<div style="flex:1">' +
                    '<div class="ys-skeleton ys-skeleton-title"></div>' +
                    '<div class="ys-skeleton ys-skeleton-text"></div>' +
                    '</div></div>' +
                    '<div class="ys-skeleton-body">' +
                    '<div class="ys-skeleton ys-skeleton-desc"></div>' +
                    '<div class="ys-skeleton ys-skeleton-desc"></div>' +
                    '<div class="ys-skeleton ys-skeleton-desc"></div>' +
                    '</div>' +
                    '<div class="ys-skeleton-footer">' +
                    '<div class="ys-skeleton ys-skeleton-btn"></div>' +
                    '<div class="ys-skeleton ys-skeleton-btn"></div>' +
                    '</div></div>'
                );
            }
        },

        /**
         * 顯示錯誤訊息
         */
        showError: function (message) {
            var $grid = $('#ys-plugin-grid');
            $grid.html(
                '<div class="ys-notice ys-notice-warning" style="grid-column: 1 / -1;">' +
                '<span class="dashicons dashicons-warning"></span>' +
                '<span>' + this.escHtml(message) + '</span>' +
                '</div>'
            );
        },

        /**
         * 渲染動態分類 tabs
         */
        renderCategoryTabs: function () {
            var self = this;
            var $tabs = $('#ys-filter-tabs');
            if (!$tabs.length) return;
            var visibleCategories = {};
            var categoryStillVisible = self.currentCategory === 'all';

            $.each(self.pluginsData, function (i, plugin) {
                if (self.currentPlatform !== 'all' && self.getPluginPlatform(plugin) !== self.currentPlatform) {
                    return;
                }
                if (plugin.category) {
                    visibleCategories[plugin.category] = true;
                }
            });

            // 保留「全部」按鈕，移除其他動態 tab
            $tabs.find('.ys-filter-tab[data-category!="all"]').remove();

            $.each(self.categoriesData, function (i, cat) {
                if (!visibleCategories[cat.slug]) return;
                if (cat.slug === self.currentCategory) {
                    categoryStillVisible = true;
                }
                var icon = cat.icon ? '<span class="dashicons ' + self.escAttr(cat.icon) + '" style="font-size:14px;width:14px;height:14px;margin-right:4px;"></span>' : '';
                var $tab = $('<button type="button" class="ys-filter-tab" data-category="' + self.escAttr(cat.slug) + '">' +
                    icon + self.escHtml(cat.name) + '</button>');
                $tabs.append($tab);
            });

            // 重新標記 active 狀態
            $tabs.find('.ys-filter-tab').removeClass('active');
            if (!categoryStillVisible) {
                self.currentCategory = 'all';
            }

            $tabs.find('.ys-filter-tab').removeClass('active');
            $tabs.find('.ys-filter-tab[data-category="' + self.escAttr(self.currentCategory) + '"]').addClass('active');
        },

        /**
         * 渲染公告
         */
        renderAnnouncements: function () {
            var self = this;
            var $wrap = $('#ys-announcements');
            if (!$wrap.length) return;

            $wrap.empty();

            // 取得已關閉的公告 ID
            var dismissedIds = self.getDismissedAnnouncements();

            // 過濾掉已關閉的公告
            var visibleAnns = $.grep(self.announcementsData, function (ann) {
                return dismissedIds.indexOf(String(ann.id)) === -1;
            });

            if (visibleAnns.length === 0) {
                $wrap.hide();
                return;
            }

            // 公告類型對應 CSS class
            var typeClasses = {
                info: 'ys-announcement-info',
                warning: 'ys-announcement-warning',
                success: 'ys-announcement-success',
                danger: 'ys-announcement-danger'
            };

            // 公告類型對應圖示
            var typeIcons = {
                info: 'dashicons-info',
                warning: 'dashicons-warning',
                success: 'dashicons-yes-alt',
                danger: 'dashicons-dismiss'
            };

            $.each(visibleAnns, function (i, ann) {
                var typeClass = typeClasses[ann.type] || typeClasses.info;
                var typeIcon = typeIcons[ann.type] || typeIcons.info;
                var pinnedClass = ann.is_pinned ? ' ys-announcement-pinned' : '';

                var html = '<div class="ys-announcement ' + typeClass + pinnedClass + '" data-id="' + self.escAttr(String(ann.id)) + '">' +
                    '<span class="dashicons ' + self.escAttr(typeIcon) + '" style="flex-shrink:0;margin-top:1px;"></span>' +
                    '<div class="ys-announcement-body">' +
                    '<div class="ys-announcement-title">' + self.escHtml(ann.title) + '</div>';

                if (ann.content) {
                    html += '<div class="ys-announcement-content">' + self.escHtml(ann.content) + '</div>';
                }

                html += '</div>' +
                    '<span class="ys-announcement-close dashicons dashicons-no-alt" title="' + self.escAttr(i18n.dismiss || '關閉') + '"></span>' +
                    '</div>';

                $wrap.append(html);
            });

            $wrap.show();
        },

        /**
         * 取得已關閉的公告 ID 列表
         */
        getDismissedAnnouncements: function () {
            try {
                var stored = localStorage.getItem('ys_hub_dismissed_announcements');
                if (stored) {
                    return JSON.parse(stored);
                }
            } catch (e) {
                // ignore
            }
            return [];
        },

        /**
         * 關閉公告
         */
        dismissAnnouncement: function (id) {
            var dismissed = this.getDismissedAnnouncements();
            if (dismissed.indexOf(String(id)) === -1) {
                dismissed.push(String(id));
            }
            try {
                localStorage.setItem('ys_hub_dismissed_announcements', JSON.stringify(dismissed));
            } catch (e) {
                // ignore
            }
        },

        /**
         * HTML escape
         */
        escHtml: function (str) {
            if (!str) return '';
            var div = document.createElement('div');
            div.appendChild(document.createTextNode(str));
            return div.innerHTML;
        },

        /**
         * Attribute escape
         */
        escAttr: function (str) {
            if (!str) return '';
            return str.replace(/&/g, '&amp;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;');
        }
    };

    /**
     * 設定模組
     */
    var Settings = {

        /**
         * 儲存設定
         */
        save: function (btn) {
            var origText = btn.text();
            btn.prop('disabled', true).html('<span class="ys-spinner"></span> ' + Marketplace.escHtml(i18n.loading));

            var siteKey = $('#ys-site-key').val();
            var autoCheck = $('#ys-auto-check').is(':checked') ? 'yes' : 'no';

            $.ajax({
                url: config.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ys_hub_client_save_settings',
                    nonce: config.nonce,
                    site_key: siteKey,
                    auto_check: autoCheck
                },
                success: function (response) {
                    btn.prop('disabled', false).text(origText);
                    if (response.success) {
                        Toast.show(i18n.saved, 'success');
                    } else {
                        Toast.show(response.data.message || i18n.failed, 'error');
                    }
                },
                error: function () {
                    btn.prop('disabled', false).text(origText);
                    Toast.show(i18n.failed, 'error');
                }
            });
        },

        /**
         * 測試連線
         */
        testConnection: function (btn) {
            var origText = btn.text();
            btn.prop('disabled', true).html('<span class="ys-spinner ys-spinner-dark"></span> ' + Marketplace.escHtml(i18n.testingConn));

            $.ajax({
                url: config.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ys_hub_client_test_connection',
                    nonce: config.nonce
                },
                success: function (response) {
                    btn.prop('disabled', false).text(origText);
                    if (response.success) {
                        Toast.show(i18n.connSuccess, 'success');
                        if (response.data && response.data.state) {
                            Settings.updateStatusDisplay(response.data.state, response.data.label);
                        }
                    } else {
                        Toast.show((response.data && response.data.message) || i18n.connFailed, 'error');
                        if (response.data && response.data.state) {
                            Settings.updateStatusDisplay(response.data.state, response.data.label);
                        }
                    }
                },
                error: function () {
                    btn.prop('disabled', false).text(origText);
                    Toast.show(i18n.connFailed, 'error');
                }
            });
        },

        /**
         * 產生 Site Key
         */
        generateSiteKey: function (btn) {
            var origText = btn.text();
            btn.prop('disabled', true).html('<span class="ys-spinner ys-spinner-dark"></span> ' + Marketplace.escHtml(i18n.generating));

            $.ajax({
                url: config.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ys_hub_client_generate_site_key',
                    nonce: config.nonce
                },
                success: function (response) {
                    btn.prop('disabled', false).text(origText);
                    if (response.success && response.data && response.data.site_key) {
                        $('#ys-site-key').val(response.data.site_key);
                        Toast.show(response.data.message || i18n.success, 'success');
                    } else {
                        Toast.show((response.data && response.data.message) || i18n.failed, 'error');
                    }
                },
                error: function () {
                    btn.prop('disabled', false).text(origText);
                    Toast.show(i18n.failed, 'error');
                }
            });
        },

        /**
         * 更新連線狀態顯示
         */
        updateStatusDisplay: function (state, label) {
            var $status = $('#ys-connection-status');
            $status.removeClass('ys-status-closed ys-status-open ys-status-half_open')
                .addClass('ys-status-' + Marketplace.escAttr(state));
            $status.find('.ys-status-label').text(label);
        }
    };

    /**
     * Toast 提示模組
     */
    var Toast = {
        timer: null,

        show: function (message, type) {
            var $existing = $('.ys-toast');
            if ($existing.length) {
                $existing.remove();
            }

            type = type || 'info';
            var $toast = $('<div class="ys-toast ys-toast-' + Marketplace.escAttr(type) + '">' +
                Marketplace.escHtml(message) + '</div>');

            $('body').append($toast);

            // 觸發動畫
            setTimeout(function () {
                $toast.addClass('show');
            }, 10);

            // 自動隱藏
            if (Toast.timer) {
                clearTimeout(Toast.timer);
            }
            Toast.timer = setTimeout(function () {
                $toast.removeClass('show');
                setTimeout(function () {
                    $toast.remove();
                }, 300);
            }, 3000);
        }
    };

    // DOM Ready
    $(function () {
        if ($('#ys-plugin-grid').length) {
            Marketplace.init();
        }
    });

})(jQuery);
