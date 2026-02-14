/**
 * YS Shopline Payment - WooCommerce Blocks Integration
 *
 * @package YangSheep\ShoplinePayment
 */

const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
const { getSetting } = window.wc.wcSettings;
const { decodeEntities } = window.wp.htmlEntities;
const { createElement, Fragment } = window.wp.element;

// 取得設定
const settings = getSetting('ys_shopline_credit_data', {});
const title = decodeEntities(settings.title || 'Shopline Payment');
const description = decodeEntities(settings.description || '');
const icons = settings.icons || [];

/**
 * 付款圖示組件
 */
const PaymentIcons = () => {
    if (!icons.length) {
        return null;
    }

    return createElement(
        'span',
        { className: 'ys-shopline-payment-icons' },
        icons.map((icon) =>
            createElement('img', {
                key: icon.id,
                src: icon.src,
                alt: icon.alt,
                className: 'ys-shopline-payment-icon',
                style: {
                    height: '24px',
                    marginRight: '4px',
                },
            })
        )
    );
};

/**
 * 付款方式標籤
 */
const Label = () => {
    return createElement(
        Fragment,
        null,
        createElement('span', null, title),
        createElement(PaymentIcons, null)
    );
};

/**
 * 付款方式內容（描述）
 */
const Content = () => {
    if (!description) {
        return null;
    }

    return createElement(
        'div',
        { className: 'ys-shopline-payment-description' },
        description
    );
};

/**
 * 編輯模式內容
 */
const Edit = () => {
    return createElement(
        'div',
        { className: 'ys-shopline-payment-edit' },
        title
    );
};

// 註冊付款方式
registerPaymentMethod({
    name: 'ys_shopline_credit',
    label: createElement(Label, null),
    content: createElement(Content, null),
    edit: createElement(Edit, null),
    canMakePayment: () => true,
    ariaLabel: title,
    supports: {
        features: settings.supports || [],
    },
});
