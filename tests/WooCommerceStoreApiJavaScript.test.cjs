const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

const read = (file) => fs.readFileSync(path.join(__dirname, '../assets/js/', file), 'utf8');
const tick = () => new Promise((resolve) => setImmediate(resolve));

function capClass(counter) {
    return class {
        solve() {
            counter.solves++;
            return Promise.resolve({ token: 'token-' + counter.solves });
        }
    };
}

// The checkout store of the Checkout block, with the two names WooCommerce
// has had for the action that sets extension data.
function blockFixture({ widgetId = '', legacyAction = false } = {}) {
    const counter = { solves: 0 };
    const state = { status: 'idle', tokens: [], resets: 0 };
    const listeners = { document: {}, store: [] };
    const set = (namespace, value) => { state.tokens.push(namespace + ':' + value.token); };

    const window = {
        CAP_CAPTCHA_CHECKOUT_BLOCK: { apiEndpoint: 'https://example.test/', widgetId },
        Cap: capClass(counter),
        wp: {
            data: {
                dispatch: () => (legacyAction ? { __internalSetExtensionData: set } : { setExtensionData: set }),
                select: () => ({
                    isProcessing: () => state.status === 'processing',
                    isIdle: () => state.status === 'idle',
                }),
                subscribe: (listener) => { listeners.store.push(listener); },
            },
        },
    };
    const document = {
        addEventListener: (type, handler) => { listeners.document[type] = handler; },
        getElementById: () => ({ reset: () => { state.resets++; } }),
    };

    vm.runInNewContext(read('woocommerce-checkout-block.js'), { window, document, setTimeout });

    const go = (status) => {
        state.status = status;
        listeners.store.forEach((listener) => listener());
    };

    return { counter, state, listeners, go };
}

test('block: the inline widget\'s token goes into the extension data', () => {
    const { state, listeners, counter } = blockFixture({ widgetId: 'w' });

    listeners.document.solve({ target: { id: 'other' }, detail: { token: 'foreign' } });
    listeners.document.solve({ target: { id: 'w' }, detail: { token: 'abc' } });

    assert.deepEqual(state.tokens, ['cap-captcha:abc']);
    assert.equal(counter.solves, 0);
});

test('block: without a widget the token is solved in the background', async () => {
    const { state } = blockFixture();
    await tick();

    assert.deepEqual(state.tokens, ['cap-captcha:token-1']);
});

test('block: falls back to the action name WooCommerce used before 9.9', async () => {
    const { state } = blockFixture({ legacyAction: true });
    await tick();

    assert.deepEqual(state.tokens, ['cap-captcha:token-1']);
});

test('block: an attempt that was sent and came back drops the token and resets the widget', () => {
    const { state, go } = blockFixture({ widgetId: 'w' });

    go('before_processing');
    go('processing');
    go('after_processing');
    go('idle');

    assert.deepEqual(state.tokens, ['cap-captcha:']);
    assert.equal(state.resets, 1);
});

test('block: in the background modes a spent token is replaced by a fresh one', async () => {
    const { state, go } = blockFixture();
    await tick();

    go('processing');
    go('idle');
    await tick();

    assert.deepEqual(state.tokens, ['cap-captcha:token-1', 'cap-captcha:', 'cap-captcha:token-2']);
});

test('block: a form stopped for its own field errors keeps its token', () => {
    const { state, go } = blockFixture({ widgetId: 'w' });

    go('before_processing');
    go('idle');

    assert.deepEqual(state.tokens, []);
    assert.equal(state.resets, 0);
});

// A page with express buttons. fetch stands in for the pass endpoint.
function passFixture({ ok = true } = {}) {
    const counter = { solves: 0 };
    const posts = [];
    const listeners = {};
    let observer = null;

    const window = {
        CAP_CAPTCHA_EXPRESS_PASS: { apiEndpoint: 'https://example.test/', url: '/?wc-ajax=cap_captcha_pass', ttl: 600 },
        Cap: capClass(counter),
        fetch: (url, options) => {
            posts.push(url + ' ' + options.body.get('cap-token'));
            return Promise.resolve({ ok });
        },
        PerformanceObserver: class {
            constructor(callback) { observer = callback; }

            observe() {}
        },
    };
    const document = { addEventListener: (type, handler) => { listeners[type] = handler; } };

    vm.runInNewContext(read('woocommerce-express-pass.js'), { window, document, URLSearchParams, Date });

    const request = (name) => observer({ getEntries: () => [{ name }] });

    return { counter, posts, listeners, request };
}

test('pass: nothing is solved until the visitor touches the page', async () => {
    const { counter } = passFixture();
    await tick();

    assert.equal(counter.solves, 0);
});

test('pass: the first interaction trades one token for a pass, later ones reuse it', async () => {
    const { posts, listeners } = passFixture();

    listeners.pointermove();
    listeners.pointerdown();
    await tick();
    listeners.keydown();
    await tick();

    assert.deepEqual(posts, ['/?wc-ajax=cap_captcha_pass token-1']);
});

test('pass: a Store API checkout request spends the pass, so a new one is fetched', async () => {
    const { posts, listeners, request } = passFixture();
    listeners.pointerdown();
    await tick();

    request('https://shop.test/wp-json/wc/store/v1/checkout?_locale=site');
    await tick();
    request('https://shop.test/?rest_route=%2Fwc%2Fstore%2Fv1%2Fcheckout%2F42');
    await tick();

    assert.equal(posts.length, 3);
});

test('pass: the block\'s totals refresh and other requests spend nothing', async () => {
    const { posts, listeners, request } = passFixture();
    listeners.pointerdown();
    await tick();

    request('https://shop.test/wp-json/wc/store/v1/checkout?__experimental_calc_totals=true');
    request('https://shop.test/wp-json/wc/store/v1/cart');
    await tick();

    assert.equal(posts.length, 1);
});

test('pass: a refused token is tried again on the next interaction', async () => {
    const { posts, listeners } = passFixture({ ok: false });

    listeners.pointerdown();
    await tick();
    listeners.pointerdown();
    await tick();

    assert.equal(posts.length, 2);
});
