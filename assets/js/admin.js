/*
 * Cap CAPTCHA — admin glue.
 *
 * "Test connection" button: posts to admin-ajax with the cap_captcha_test_connection
 * action, renders the outcome inline next to the button, with any warning of a
 * passed check on a line of its own. The form goes along as it stands, so the
 * test covers what is on screen before it is saved. Fields locked by a
 * wp-config constant are disabled, hence not sent, and the constant applies.
 */

(function () {
    const cfg = window.capCaptchaAdmin || {};

    function ready(fn) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', fn);
        } else {
            fn();
        }
    }

    ready(() => {
        const btn = document.querySelector('[data-cap-captcha-test]');
        if (!btn) return;

        const out = document.querySelector('[data-cap-captcha-test-result]');

        btn.addEventListener('click', async (e) => {
            e.preventDefault();
            btn.disabled = true;
            if (out) {
                out.className = 'cap-captcha-test-connection__result';
                out.textContent = cfg.i18n?.testing ?? 'Testing…';
            }

            try {
                const body = new URLSearchParams({
                    action: 'cap_captcha_test_connection',
                    _ajax_nonce: cfg.nonce ?? '',
                });
                if (btn.form) {
                    new FormData(btn.form).forEach((value, name) => {
                        if (name.startsWith(cfg.optionKey + '[') && typeof value === 'string') {
                            body.append(name, value);
                        }
                    });
                }
                const res = await fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body });
                const data = await res.json();
                if (out) {
                    if (data?.success) {
                        const warnings = Array.isArray(data.data?.warnings) ? data.data.warnings : [];
                        out.classList.add(
                            warnings.length
                                ? 'cap-captcha-test-connection__result--warn'
                                : 'cap-captcha-test-connection__result--ok'
                        );
                        out.textContent = data.data?.message ?? (cfg.i18n?.ok ?? 'OK');
                        warnings.forEach((warning) => {
                            const line = document.createElement('span');
                            line.className = 'cap-captcha-test-connection__warning';
                            line.textContent = warning;
                            out.appendChild(line);
                        });
                    } else {
                        out.classList.add('cap-captcha-test-connection__result--err');
                        out.textContent = data?.data?.message ?? (cfg.i18n?.error ?? 'Error');
                    }
                }
            } catch (err) {
                if (out) {
                    out.classList.add('cap-captcha-test-connection__result--err');
                    out.textContent = (cfg.i18n?.error ?? 'Error') + ': ' + (err?.message ?? err);
                }
            } finally {
                btn.disabled = false;
            }
        });
    });

    // Quick-link from an integration card to its options disclosure: open it and
    // smooth-scroll. Falls back to a native anchor jump without JS.
    ready(() => {
        document.querySelectorAll('.cap-captcha-integration__options-link').forEach((link) => {
            link.addEventListener('click', (e) => {
                const id = (link.getAttribute('href') || '').replace(/^#/, '');
                const details = id && document.getElementById(id);
                if (!details) return;
                e.preventDefault();
                details.open = true;
                details.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                const summary = details.querySelector('summary');
                if (summary) summary.focus();

                // Flash the disclosure so it's clear where the link landed.
                details.classList.remove('cap-captcha-integration-options--flash');
                void details.offsetWidth; // reflow so the animation restarts on repeat clicks
                details.classList.add('cap-captcha-integration-options--flash');
            });
        });
    });
})();
