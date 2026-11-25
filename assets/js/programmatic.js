/*
 * Cap CAPTCHA — programmatic auto-solve helper.
 *
 * Loaded only when an integration is set to "programmatic" display mode.
 * Starts solving the Cap challenge on page load and writes the resulting
 * token into all `<input name="cap-token">` fields in the document. When a
 * form is submitted before the token is ready, we intercept the submit,
 * wait for the token, then re-submit.
 *
 * A solve that fails shows `errorMessage` under every field and leaves the
 * form on the page. The next submit solves again.
 *
 * Reads its config from `window.CAP_CAPTCHA_PROGRAMMATIC`. Depends on the
 * widget script exposing `window.Cap` (the cap-widget package does so).
 */

(function () {
    var cfg = window.CAP_CAPTCHA_PROGRAMMATIC;
    if (!cfg || !cfg.apiEndpoint) return;

    var ERROR_CLASS = 'cap-captcha-error';

    var waited = 0;

    function start() {
        if (typeof window.Cap === 'undefined') {
            // Widget script not loaded yet; retry on the next tick. A blocked
            // script (CSP, a content blocker) ends the wait: the form then goes
            // out without a token and the server answers with its own error.
            waited += 50;
            if (waited > 10000) return;
            setTimeout(start, 50);
            return;
        }

        var fieldName = cfg.tokenField || 'cap-token';
        // The solve in flight or the one that succeeded, null after a failure.
        var attempt = null;
        // Forms whose submit is being held, so a second click sends nothing twice.
        var held = new WeakSet();

        // Fluent Forms and wpDiscuz fields belong to their own scripts, which
        // need one single-use token per form and show their own error.
        function isOwned(field) {
            return !field.closest('.cap-captcha--fluent, .cap-captcha--wpdiscuz');
        }

        function ownedFields() {
            return Array.prototype.filter.call(
                document.querySelectorAll('input[name="' + fieldName + '"]'),
                isOwned
            );
        }

        function messageOf(field) {
            var next = field.nextElementSibling;
            return next && next.classList.contains(ERROR_CLASS) ? next : null;
        }

        function showError() {
            if (!cfg.errorMessage) return;
            ownedFields().forEach(function (field) {
                var message = messageOf(field);
                if (!message) {
                    message = document.createElement('div');
                    message.className = ERROR_CLASS;
                    message.setAttribute('role', 'alert');
                    field.insertAdjacentElement('afterend', message);
                }
                message.textContent = cfg.errorMessage;
                message.hidden = false;
            });
        }

        function hideError() {
            ownedFields().forEach(function (field) {
                var message = messageOf(field);
                if (message) message.hidden = true;
            });
        }

        function solve() {
            hideError();

            var promise = new window.Cap({ apiEndpoint: cfg.apiEndpoint }).solve().then(function (result) {
                // An empty token would leave the fields empty and every submit
                // waiting on a solve that already answered.
                if (!result || !result.token) throw new Error('Cap returned no token');
                return result;
            });

            attempt = promise;
            promise.then(function (result) {
                ownedFields().forEach(function (field) {
                    field.value = result.token;
                });
            }, function () {
                if (attempt === promise) attempt = null;
                showError();
            });

            return promise;
        }

        solve();

        document.addEventListener('submit', function (event) {
            var form = event.target;
            if (!(form instanceof HTMLFormElement)) return;
            var field = form.querySelector('input[name="' + fieldName + '"]');
            if (!field || field.value) return;
            if (!isOwned(field)) return;

            event.preventDefault();
            if (held.has(form)) return;
            held.add(form);
            // Without it the form goes out without the name and value of the
            // button, which wp-login.php (`wp-submit`) and others read.
            var submitter = event.submitter;
            (attempt || solve()).then(function (result) {
                held.delete(form);
                field.value = result.token;
                if (typeof form.requestSubmit === 'function') {
                    form.requestSubmit(submitter || undefined);
                } else {
                    form.submit();
                }
            }, function () {
                held.delete(form);
                // Submitting again would fire this listener on a field that
                // is still empty. The message is up, the form stays put.
            });
        }, true);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
