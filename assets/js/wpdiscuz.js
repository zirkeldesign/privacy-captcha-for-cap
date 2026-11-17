/*
 * Cap CAPTCHA for wpDiscuz.
 *
 * wpDiscuz posts comments by AJAX from a click on `.wc_comm_submit` and keeps
 * the form on the page, so a spent token would stay in it. After every
 * attempt the form's widget is reset, or in programmatic mode a fresh token
 * is solved. Reply forms are cloned from a hidden template later; the click
 * handler is delegated so clones work too.
 */
(function ($) {
    var WRAPPER = '.cap-captcha--wpdiscuz';
    var TEMPLATE = '#wpdiscuz_hidden_secondary_form';
    var pending = new WeakMap();

    function solve(field) {
        var config = window.CAP_CAPTCHA_PROGRAMMATIC;
        var message = field.closest(WRAPPER).querySelector('.cap-captcha-wpdiscuz-error');
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
            if (pending.get(field) === promise) field.value = result.token;
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

    function refresh(form) {
        form.querySelectorAll(WRAPPER + ' cap-widget').forEach(function (widget) {
            if (typeof widget.reset === 'function') widget.reset();
        });
        var field = tokenField(form);
        if (field) {
            field.value = '';
            pending.delete(field);
            solve(field).catch(function () {});
        }
    }

    $(function () {
        document.querySelectorAll(WRAPPER + ' input[data-cap-programmatic]').forEach(function (field) {
            if (!field.closest(TEMPLATE)) solve(field).catch(function () {});
        });
    });

    // Capture phase: runs before wpDiscuz' delegated jQuery handler on body.
    document.addEventListener('click', function (event) {
        var button = event.target && event.target.closest ? event.target.closest('.wc_comm_submit') : null;
        var form = button && button.closest('form');
        var field = form && tokenField(form);
        if (!field || field.value) return;

        event.preventDefault();
        event.stopImmediatePropagation();
        (pending.get(field) || solve(field)).then(function (result) {
            field.value = result.token;
            button.click();
        }).catch(function () {
            field.value = '';
        });
    }, true);

    $(document.body).on('wpdiscuz_comment_post_complete wpdiscuz_comment_post_failed', function (event, wcForm) {
        if (wcForm && wcForm[0]) refresh(wcForm[0]);
    });
})(jQuery);
