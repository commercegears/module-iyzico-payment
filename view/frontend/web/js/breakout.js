/**
 * CommerceGears_IyzicoPayment: after a payment in the frame, load the result page in the whole window.
 */
(function () {
    'use strict';
    var script = document.currentScript, url = script && script.getAttribute('data-url');
    if (!url || url.indexOf(window.location.origin + '/') !== 0) {
        return;
    }
    try {
        window.top.location.href = url;
    } catch (e) {
        window.location.href = url;
    }
}());
