/**
 * CommerceGears_IyzicoPayment: places the order, then sends the customer to the payment page, which opens iyzico's
 * Checkout Form. Card details are only ever entered on iyzico's form.
 */
define([
    'Magento_Checkout/js/view/payment/default',
    'Magento_Checkout/js/model/full-screen-loader'
], function (Component, fullScreenLoader) {
    'use strict';

    return Component.extend({
        defaults: {
            template: 'CommerceGears_IyzicoPayment/payment/iyzico',
            redirectAfterPlaceOrder: false
        },

        getMethodConfig: function () {
            return (window.checkoutConfig.payment || {})[this.getCode()] || {};
        },

        getLogoUrl: function () {
            return this.getMethodConfig().logoUrl || '';
        },

        isSandbox: function () {
            return !!this.getMethodConfig().isSandbox;
        },

        afterPlaceOrder: function () {
            fullScreenLoader.startLoader();
            window.location.replace(this.getMethodConfig().startUrl);
        }
    });
});
