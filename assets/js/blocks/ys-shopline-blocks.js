/**
 * YS Shopline Payment - WooCommerce Blocks Integration
 *
 * 動態註冊所有已啟用的 Shopline gateway 到 Block Checkout。
 * gateway 列表由 PHP 透過 wp_localize_script 傳入 ysShoplineBlocksGateways。
 *
 * @package YangSheep\ShoplinePayment
 */

( function () {
    const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
    const { getSetting } = window.wc.wcSettings;
    const { decodeEntities } = window.wp.htmlEntities;
    const { createElement, Fragment } = window.wp.element;

    // 從 PHP localize 取得所有已註冊的 gateway ID
    const gatewayIds = window.ysShoplineBlocksGateways || [ 'ys_shopline_credit' ];

    /**
     * 建立付款圖示組件
     */
    function createPaymentIcons( icons ) {
        if ( ! icons || ! icons.length ) {
            return null;
        }

        return createElement(
            'span',
            { className: 'ys-shopline-payment-icons' },
            icons.map( function ( icon ) {
                return createElement( 'img', {
                    key: icon.id,
                    src: icon.src,
                    alt: icon.alt,
                    className: 'ys-shopline-payment-icon',
                    style: {
                        height: '24px',
                        marginRight: '4px',
                    },
                } );
            } )
        );
    }

    /**
     * 為每個 gateway 註冊付款方式
     */
    gatewayIds.forEach( function ( gatewayId ) {
        var settings = getSetting( gatewayId + '_data', {} );

        if ( ! settings ) {
            return;
        }

        var title = decodeEntities( settings.title || gatewayId );
        var description = decodeEntities( settings.description || '' );
        var icons = settings.icons || [];

        var Label = function () {
            return createElement(
                Fragment,
                null,
                createElement( 'span', null, title ),
                createPaymentIcons( icons )
            );
        };

        var Content = function () {
            if ( ! description ) {
                return null;
            }
            return createElement(
                'div',
                { className: 'ys-shopline-payment-description' },
                description
            );
        };

        var Edit = function () {
            return createElement(
                'div',
                { className: 'ys-shopline-payment-edit' },
                title
            );
        };

        registerPaymentMethod( {
            name: gatewayId,
            label: createElement( Label, null ),
            content: createElement( Content, null ),
            edit: createElement( Edit, null ),
            canMakePayment: function () {
                // Block Checkout 尚未整合 SDK paySession 流程，暫時停用
                // TODO: 整合 PaymentMethodInterface 的 paymentProcessing 事件
                return false;
            },
            ariaLabel: title,
            supports: {
                features: settings.supports || [],
            },
        } );
    } );
} )();
