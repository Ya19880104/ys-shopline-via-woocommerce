/**
 * YS Shopline Payment - My Account 儲存卡管理
 *
 * @package YangSheep\ShoplinePayment
 */

(function($) {
    'use strict';

    /**
     * 儲存卡管理模組
     */
    const YSSavedCards = {
        /**
         * 初始化
         */
        init: function() {
            this.bindEvents();
        },

        /**
         * 綁定事件
         */
        bindEvents: function() {
            $(document).on('click', '.ys-delete-card-btn', this.handleDeleteCard.bind(this));
        },

        /**
         * 處理刪除卡片
         *
         * @param {Event} e 事件物件
         */
        handleDeleteCard: function(e) {
            e.preventDefault();

            const $btn = $(e.currentTarget);
            const instrumentId = $btn.data('instrument-id');
            const cardName = $btn.data('card-name');
            const $cardItem = $btn.closest('.ys-saved-card-item');

            // 確認刪除
            const confirmMsg = ys_shopline_myaccount.i18n.confirm_delete.replace('%s', cardName);
            if (!confirm(confirmMsg)) {
                return;
            }

            // 防止重複點擊
            if ($btn.prop('disabled')) {
                return;
            }

            // 設定載入狀態
            this.setLoadingState($btn, $cardItem, true);

            // 發送 AJAX 請求
            $.ajax({
                url: ys_shopline_myaccount.ajax_url,
                type: 'POST',
                data: {
                    action: 'ys_shopline_delete_card',
                    nonce: ys_shopline_myaccount.nonce,
                    instrument_id: instrumentId
                },
                success: this.handleDeleteSuccess.bind(this, $cardItem),
                error: this.handleDeleteError.bind(this, $btn, $cardItem),
                complete: function() {
                    // 如果有錯誤，重設狀態會在 error handler 處理
                }
            });
        },

        /**
         * 設定載入狀態
         *
         * @param {jQuery} $btn 按鈕元素
         * @param {jQuery} $cardItem 卡片項目元素
         * @param {boolean} loading 是否載入中
         */
        setLoadingState: function($btn, $cardItem, loading) {
            if (loading) {
                $btn.prop('disabled', true).text(ys_shopline_myaccount.i18n.deleting);
                $cardItem.addClass('deleting');
            } else {
                $btn.prop('disabled', false).text(ys_shopline_myaccount.i18n.delete || '刪除');
                $cardItem.removeClass('deleting');
            }
        },

        /**
         * 處理刪除成功
         *
         * @param {jQuery} $cardItem 卡片項目元素
         * @param {Object} response 回應物件
         */
        handleDeleteSuccess: function($cardItem, response) {
            if (response.success) {
                // 淡出並移除
                $cardItem.fadeOut(300, function() {
                    $(this).remove();

                    // 檢查是否還有卡片
                    if ($('.ys-saved-card-item').length === 0) {
                        // 顯示無卡片訊息
                        $('.ys-saved-cards-list').replaceWith(
                            '<div class="ys-no-saved-cards">' +
                            '<p>' + (ys_shopline_myaccount.i18n.no_cards || '您目前沒有儲存的付款方式。') + '</p>' +
                            '<p class="description">' + (ys_shopline_myaccount.i18n.add_card_hint || '在結帳時選擇「儲存卡片」即可新增付款方式。') + '</p>' +
                            '</div>'
                        );
                    }
                });

                // 顯示成功訊息
                this.showNotice(ys_shopline_myaccount.i18n.delete_success, 'success');
            } else {
                this.showNotice(response.data?.message || ys_shopline_myaccount.i18n.delete_error, 'error');
                this.setLoadingState($cardItem.find('.ys-delete-card-btn'), $cardItem, false);
            }
        },

        /**
         * 處理刪除錯誤
         *
         * @param {jQuery} $btn 按鈕元素
         * @param {jQuery} $cardItem 卡片項目元素
         */
        handleDeleteError: function($btn, $cardItem) {
            this.showNotice(ys_shopline_myaccount.i18n.delete_error, 'error');
            this.setLoadingState($btn, $cardItem, false);
        },

        /**
         * 顯示通知
         *
         * @param {string} message 訊息
         * @param {string} type 類型 (success/error)
         */
        showNotice: function(message, type) {
            // 移除現有通知
            $('.ys-notice').remove();

            // 建立通知元素
            const $notice = $('<div class="ys-notice ys-notice-' + type + '">' + message + '</div>');

            // 加入到頁面
            $('.ys-saved-cards-wrapper').prepend($notice);

            // 自動隱藏
            setTimeout(function() {
                $notice.fadeOut(300, function() {
                    $(this).remove();
                });
            }, 5000);
        }
    };

    // DOM Ready
    $(document).ready(function() {
        YSSavedCards.init();
    });

})(jQuery);
