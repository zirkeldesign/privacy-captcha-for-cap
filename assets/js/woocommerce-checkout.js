/*
 * Cap CAPTCHA for the classic WooCommerce checkout.
 *
 * The checkout posts by AJAX and keeps the form on the page. A checkout that
 * failed after the token was sent to Cap (a rejected token, a declined
 * payment, a server error) leaves a spent token in the form, so on
 * WooCommerce's `checkout_error` the widget is reset, or in programmatic mode
 * a fresh token is solved. The server marks the errors of a form that its own
 * validation rejected before Cap was asked; that form keeps its token.
 */
(function ($) {
    var FORM = 'form.checkout';
    var KEPT_TOKEN_MARKER = 'cap-captcha-kept';
    var pending = null;

    // shortcut: without window.Cap the spent token stays and the next order fails the CAPTCHA, as any broken widget would.
    function solve(field) {
        var config = window.CAP_CAPTCHA_PROGRAMMATIC;
        if (!config || !config.apiEndpoint || typeof window.Cap === 'undefined') return;

        // The spent token stays in the field until the new one arrives, so
        // programmatic.js never mistakes an empty field for an unsolved page.
        var current = pending = new window.Cap({ apiEndpoint: config.apiEndpoint }).solve().then(function (result) {
            if (current === pending && result && result.token) field.value = result.token;
        }).catch(function () {}).then(function () {
            if (current === pending) pending = null;
        });
    }

    $(document.body).on('checkout_error', function (event, errorMessage) {
        if (String(errorMessage || '').indexOf(KEPT_TOKEN_MARKER) !== -1) return;

        document.querySelectorAll(FORM + ' cap-widget').forEach(function (widget) {
            if (typeof widget.reset === 'function') widget.reset();
        });
        document.querySelectorAll(FORM + ' input[data-cap-programmatic]').forEach(solve);
    });

    // Hold "Place order" until the fresh token is there, then place it.
    $(FORM).on('checkout_place_order', function () {
        // Returning nothing keeps another handler's `false`.
        if (!pending) return;

        var form = $(this);
        pending.then(function () {
            form.trigger('submit');
        });

        return false;
    });
})(jQuery);
