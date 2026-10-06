/**
 * Softaware_IyzicoPayment: "Test Connection" button in the payment method settings.
 */
define(['jquery'], function ($) {
    'use strict';

    return function (config, element) {
        var $result = $(config.result);

        $(element).on('click', function () {
            $result.text('…').css('color', '');
            $.ajax({
                url: config.url,
                type: 'POST',
                dataType: 'json',
                data: {form_key: window.FORM_KEY},
                showLoader: true
            }).done(function (response) {
                $result.text(response.message || '').css('color', response.success ? '#006400' : '#e22626');
            }).fail(function () {
                $result.text('Request failed.').css('color', '#e22626');
            });
        });
    };
});
