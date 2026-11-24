/*
 * Cap CAPTCHA for WPForms.
 *
 * An AJAX form stays on the page after a refused submit. When the server says
 * the token never reached Cap (WPForms refused the form first), it is kept;
 * after any other failure the widget is reset, or in programmatic mode a new
 * token is solved, since Cap tokens are single-use. A submit without a
 * usable token is held until there is one.
 */
(function ($) {
    var WRAPPER = '.cap-captcha--wpforms';
    var pending = new WeakMap();
    var fresh = new WeakSet();
    var passing = new WeakSet();

    function errorMessage(element) {
        var wrapper = element.closest(WRAPPER);
        return wrapper ? wrapper.querySelector('.cap-captcha-wpforms-error') : null;
    }

    function solve(field) {
        var config = window.CAP_CAPTCHA_PROGRAMMATIC;
        var message = errorMessage(field);
        if (!config || !config.apiEndpoint) {
            if (message) message.hidden = false;
            return Promise.reject(new Error('Cap is not configured'));
        }
        if (message) message.hidden = true;

        var promise = new Promise(function (resolve, reject) {
            var waited = 0;
            function start() {
                if (typeof window.Cap === 'undefined') {
                    // A blocked widget script must end in the visible error, not a dead button.
                    waited += 50;
                    if (waited > 10000) {
                        reject(new Error('Cap did not load'));
                        return;
                    }
                    setTimeout(start, 50);
                    return;
                }
                new window.Cap({ apiEndpoint: config.apiEndpoint }).solve().then(function (result) {
                    if (!result || !result.token) throw new Error('Cap returned no token');
                    return result;
                }).then(resolve, reject);
            }
            start();
        });

        pending.set(field, promise);
        promise.then(function (result) {
            if (pending.get(field) !== promise) return;
            pending.delete(field);
            field.value = result.token;
            fresh.add(field);
        }).catch(function () {
            if (pending.get(field) !== promise) return;
            pending.delete(field);
            if (message) message.hidden = false;
        });
        return promise;
    }

    function tokenField(form) {
        return form.querySelector(WRAPPER + ' input[data-cap-programmatic]');
    }

    function widgetHasToken(widget) {
        var input = widget.querySelector('input[name="cap-token"]');
        return Boolean((input && input.value) || widget.token);
    }

    // The spent token stays in the field until the new one replaces it; submits wait for that.
    function refresh(form) {
        form.querySelectorAll(WRAPPER + ' cap-widget').forEach(function (widget) {
            if (typeof widget.reset === 'function') widget.reset();
        });
        var field = tokenField(form);
        if (field) {
            fresh.delete(field);
            solve(field).catch(function () {});
        }
    }

    /** A promise for the token a submit has to wait for, or null when it can go. */
    function tokenReady(form) {
        var field = tokenField(form);
        if (field) {
            return fresh.has(field) ? null : (pending.get(field) || solve(field));
        }

        var widget = form.querySelector(WRAPPER + ' cap-widget');
        if (!widget || widgetHasToken(widget) || typeof widget.solve !== 'function') return null;

        return Promise.resolve(widget.solve()).then(function (result) {
            if (!widgetHasToken(widget) && !(result && result.token)) throw new Error('Cap returned no token');
            return result;
        });
    }

    function ownForm(event) {
        var form = event.target;
        return form && typeof form.querySelector === 'function' && form.querySelector(WRAPPER) ? form : null;
    }

    $(function () {
        document.querySelectorAll(WRAPPER + ' input[data-cap-programmatic]').forEach(function (field) {
            solve(field).catch(function () {});
        });
    });

    // WPForms fires this for AJAX and normal submits alike, and stops when it is prevented.
    $(document).on('wpformsBeforeFormSubmit', function (event) {
        var form = ownForm(event);
        if (!form) return;
        if (passing.has(form)) {
            passing.delete(form);
            return;
        }

        var ready = tokenReady(form);
        if (!ready) return;

        event.preventDefault();
        ready.then(function () {
            passing.add(form);
            window.wpforms.formSubmit($(form));
        }).catch(function () {});
    });

    $(document).on('wpformsAjaxSubmitFailed', function (event, json) {
        var form = ownForm(event);
        if (!form || (json && json.data && json.data.cap_captcha_kept)) return;
        refresh(form);
    });

    $(document).on('wpformsAjaxSubmitError', function (event) {
        var form = ownForm(event);
        if (form) refresh(form);
    });
})(jQuery);
