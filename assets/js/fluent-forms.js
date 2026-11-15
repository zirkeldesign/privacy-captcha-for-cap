(function ($) {
    var pending = new WeakMap();

    function solve(field) {
        var config = window.CAP_CAPTCHA_PROGRAMMATIC;
        var message = field.closest('.cap-captcha--fluent').querySelector('.cap-captcha-fluent-error');
        if (!config || !config.apiEndpoint) {
            if (message) message.hidden = false;
            return Promise.reject(new Error('Cap is not configured'));
        }
        if (message) message.hidden = true;

        var promise = new Promise(function (resolve, reject) {
            function start() {
                if (typeof window.Cap === 'undefined') {
                    setTimeout(start, 50);
                    return;
                }
                new window.Cap({ apiEndpoint: config.apiEndpoint }).solve().then(resolve, reject);
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

    function refresh(form) {
        form.querySelectorAll('.cap-captcha--fluent cap-widget').forEach(function (widget) {
            if (typeof widget.reset === 'function') widget.reset();
        });
        form.querySelectorAll('.cap-captcha--fluent input[data-cap-programmatic]').forEach(function (field) {
            field.value = '';
            solve(field).catch(function () {});
        });
    }

    $(function () {
        document.querySelectorAll('.cap-captcha--fluent input[data-cap-programmatic]').forEach(function (field) {
            solve(field).catch(function () {});
        });

        document.addEventListener('submit', function (event) {
            var form = event.target;
            if (!(form instanceof HTMLFormElement)) return;
            var field = form.querySelector('.cap-captcha--fluent input[data-cap-programmatic]');
            if (!field || field.value) return;

            event.preventDefault();
            event.stopImmediatePropagation();
            var submitter = event.submitter;
            var promise = pending.get(field) || solve(field);
            promise.then(function (result) {
                field.value = result.token;
                if (typeof form.requestSubmit === 'function') {
                    form.requestSubmit(submitter || undefined);
                } else if (submitter) {
                    submitter.click();
                }
            }).catch(function () {
                field.value = '';
            });
        }, true);
    });

    $(document).on('fluentform_submission_failed', 'form', function () {
        refresh(this);
    });
    $(document).on('fluentform_submission_success', function (event, detail) {
        if (detail && detail.form && detail.form[0]) refresh(detail.form[0]);
    });
})(jQuery);