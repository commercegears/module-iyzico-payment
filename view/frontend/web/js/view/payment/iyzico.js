/**
 * Softaware_IyzicoPayment: registers the iyzico renderer in the checkout.
 */
define([
    'uiComponent',
    'Magento_Checkout/js/model/payment/renderer-list'
], function (Component, rendererList) {
    'use strict';

    rendererList.push({
        type: 'softaware_iyzico',
        component: 'Softaware_IyzicoPayment/js/view/payment/method-renderer/iyzico'
    });

    return Component.extend({});
});
