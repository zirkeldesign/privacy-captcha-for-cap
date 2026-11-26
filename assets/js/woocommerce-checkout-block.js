/*
 * Cap CAPTCHA for the WooCommerce Checkout block.
 *
 * The block places its order through the Store API and sends whatever sits in
 * the checkout store's extension data. The token goes there as
 * `cap-captcha.token`, from the inline widget above "Place order" or, in the
 * floating and programmatic modes, from a solve in the background.
 *
 * A token is single use. Once an order attempt was sent and the checkout came
 * back without completing (a declined card, a rejected token), the token is
 * dropped and the widget reset or a fresh one solved. A form the block stopped
 * for its own field errors was never sent and keeps its token.
 */
(function () {
    var cfg = window.CAP_CAPTCHA_CHECKOUT_BLOCK;
    var data = window.wp && window.wp.data;
    if (!cfg || !cfg.apiEndpoint || !data) return;

    var STORE = 'wc/store/checkout';
    var waited = 0;
    var sent = false;

    function setToken(token) {
        var actions = data.dispatch(STORE);
        // Renamed in WooCommerce 9.9.
        var set = actions && (actions.setExtensionData || actions.__internalSetExtensionData);
        if (set) set('cap-captcha', { token: token });
    }

    // shortcut: an order placed while the solve is still running goes out without a token, is refused and renews; hold the button if that turns out to happen.
    function solve() {
        if (typeof window.Cap === 'undefined') {
            waited += 50;
            if (waited <= 10000) setTimeout(solve, 50);
            return;
        }

        new window.Cap({ apiEndpoint: cfg.apiEndpoint }).solve().then(function (result) {
            if (result && result.token) setToken(result.token);
        }).catch(function () {});
    }

    function renew() {
        setToken('');

        if (!cfg.widgetId) {
            solve();
            return;
        }

        var widget = document.getElementById(cfg.widgetId);
        if (widget && typeof widget.reset === 'function') widget.reset();
    }

    if (cfg.widgetId) {
        // Capture, so it does not matter whether the widget's event bubbles.
        document.addEventListener('solve', function (event) {
            if (event.target && event.target.id === cfg.widgetId && event.detail && event.detail.token) {
                setToken(event.detail.token);
            }
        }, true);
    } else {
        solve();
    }

    data.subscribe(function () {
        var checkout = data.select(STORE);
        if (!checkout) return;

        if (checkout.isProcessing()) {
            sent = true;
        } else if (sent && checkout.isIdle()) {
            sent = false;
            renew();
        }
    });
})();
