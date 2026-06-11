/*
 * Cap CAPTCHA — programmatic auto-solve helper.
 *
 * Loaded only when an integration is set to "programmatic" display mode.
 * Starts solving the Cap challenge on page load and writes the resulting
 * token into all `<input name="cap-token">` fields in the document. When a
 * form is submitted before the token is ready, we intercept the submit,
 * wait for the token, then re-submit.
 *
 * Reads its config from `window.CAP_CAPTCHA_PROGRAMMATIC`. Depends on the
 * widget script exposing `window.Cap` (the cap-widget package does so).
 */

(function () {
    var cfg = window.CAP_CAPTCHA_PROGRAMMATIC;
    if (!cfg || !cfg.apiEndpoint) return;

    function start() {
        if (typeof window.Cap === 'undefined') {
            // Widget script not loaded yet; retry on the next tick.
            setTimeout(start, 50);
            return;
        }

        var fieldName = cfg.tokenField || 'cap-token';
        var cap = new window.Cap({ apiEndpoint: cfg.apiEndpoint });
        var tokenPromise = cap.solve();

        function writeToken(token) {
            document.querySelectorAll('input[name="' + fieldName + '"]').forEach(function (field) {
                field.value = token;
            });
        }

        tokenPromise.then(function (result) {
            writeToken(result.token);
        }).catch(function () {});

        document.addEventListener('submit', function (event) {
            var form = event.target;
            if (!(form instanceof HTMLFormElement)) return;
            var field = form.querySelector('input[name="' + fieldName + '"]');
            if (!field || field.value) return;

            event.preventDefault();
            tokenPromise.then(function (result) {
                field.value = result.token;
                if (typeof form.requestSubmit === 'function') {
                    form.requestSubmit();
                } else {
                    form.submit();
                }
            }).catch(function () {
                if (typeof form.requestSubmit === 'function') {
                    form.requestSubmit();
                } else {
                    form.submit();
                }
            });
        }, true);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
