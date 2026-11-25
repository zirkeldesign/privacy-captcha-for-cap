/*
 * Cap CAPTCHA pass for express payment buttons (Apple Pay, Google Pay, Link).
 *
 * A gateway's express button places its order through the Store API from the
 * gateway's own script, which carries no Cap token. This script solves in the
 * background and trades the token for a pass the server keeps in the visitor's
 * session; a Store API checkout without a token spends it.
 *
 * A pass buys one attempt, so a new one is fetched after every Store API
 * checkout request, and on the next interaction once the old one has aged.
 * Nothing is solved for a visitor who never touches the page.
 */
(function () {
    var cfg = window.CAP_CAPTCHA_EXPRESS_PASS;
    if (!cfg || !cfg.apiEndpoint || !cfg.url) return;

    // The Store API by pretty permalink or as ?rest_route=.
    var CHECKOUT = /wc(\/|%2F)store(\/|%2F)(v\d+(\/|%2F))?checkout/i;
    var issuedAt = 0;
    var pending = null;

    function issue() {
        if (pending || typeof window.Cap === 'undefined') return;

        pending = new window.Cap({ apiEndpoint: cfg.apiEndpoint }).solve().then(function (result) {
            return window.fetch(cfg.url, {
                method: 'POST',
                credentials: 'same-origin',
                body: new URLSearchParams({ 'cap-token': result.token }),
            });
        }).then(function (response) {
            if (response.ok) issuedAt = Date.now();
        }).catch(function () {}).then(function () {
            pending = null;
        });
    }

    function ensure() {
        // Renewed at 80 % of its lifetime (ttl is in seconds).
        if (Date.now() - issuedAt > cfg.ttl * 800) issue();
    }

    ['pointermove', 'pointerdown', 'keydown', 'touchstart'].forEach(function (type) {
        document.addEventListener(type, ensure, { capture: true, passive: true });
    });

    if (typeof window.PerformanceObserver === 'undefined') return;

    // The block's totals refresh is a PUT to the same route and spends nothing.
    new window.PerformanceObserver(function (list) {
        var spent = list.getEntries().some(function (entry) {
            return CHECKOUT.test(entry.name) && entry.name.indexOf('calc_totals') === -1;
        });

        if (spent) {
            issuedAt = 0;
            issue();
        }
    }).observe({ type: 'resource' });
})();
