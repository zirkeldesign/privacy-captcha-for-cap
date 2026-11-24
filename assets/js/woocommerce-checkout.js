/*
 * Cap CAPTCHA for the classic WooCommerce checkout.
 *
 * The checkout posts by AJAX and keeps the form on the page. A checkout that
 * fails after the token was checked (a rejected token, a declined payment)
 * leaves a spent token in the form, so on WooCommerce's `checkout_error` the
 * widget is reset, or in programmatic mode a fresh token is solved.
 */
(function ($) {
    var FORM = 'form.checkout';

    function solve(field) {
        var config = window.CAP_CAPTCHA_PROGRAMMATIC;
        if (!config || !config.apiEndpoint || typeof window.Cap === 'undefined') return;

        new window.Cap({ apiEndpoint: config.apiEndpoint }).solve().then(function (result) {
            if (result && result.token) field.value = result.token;
        }).catch(function () {});
    }

    $(document.body).on('checkout_error', function () {
        document.querySelectorAll(FORM + ' cap-widget').forEach(function (widget) {
            if (typeof widget.reset === 'function') widget.reset();
        });
        document.querySelectorAll(FORM + ' input[data-cap-programmatic]').forEach(function (field) {
            field.value = '';
            solve(field);
        });
    });
})(jQuery);
