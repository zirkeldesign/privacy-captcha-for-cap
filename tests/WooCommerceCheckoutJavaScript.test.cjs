const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

const source = fs.readFileSync(path.join(__dirname, '../assets/js/woocommerce-checkout.js'), 'utf8');

// The classic checkout form: an inline or floating widget, or the hidden
// programmatic field. WooCommerce triggers checkout_error on document.body and
// checkout_place_order on the form.
function fixture({ programmatic = false, solve } = {}) {
    let solves = 0;
    let resets = 0;
    let submits = 0;
    const handlers = { body: {}, form: {} };

    const widget = { reset: () => { resets++; } };
    const field = { value: programmatic ? 'spent-token' : '' };
    const document = {
        body: {},
        querySelectorAll: (selector) => {
            if (selector.includes('cap-widget')) return programmatic ? [] : [widget];
            if (selector.includes('data-cap-programmatic')) return programmatic ? [field] : [];
            return [];
        },
    };
    const jquery = (target) => {
        const bucket = target === document.body ? handlers.body : handlers.form;
        return {
            on: (event, handler) => { bucket[event] = handler; },
            trigger: (event) => { if (event === 'submit') submits++; },
        };
    };
    const window = {
        CAP_CAPTCHA_PROGRAMMATIC: { apiEndpoint: 'https://example.test/' },
        Cap: class {
            solve() {
                solves++;
                return solve ? solve(solves) : Promise.resolve({ token: 'token-' + solves });
            }
        },
    };
    vm.runInNewContext(source, { window, document, jQuery: jquery, Promise });

    return {
        handlers,
        field,
        placeOrder: () => handlers.form.checkout_place_order.call({}),
        getResets: () => resets,
        getSolves: () => solves,
        getSubmits: () => submits,
    };
}

async function settle() {
    for (let i = 0; i < 5; i++) await new Promise((resolve) => setImmediate(resolve));
}

// The server marks the errors of a form that never sent its token to Cap.
const KEPT = '<ul class="woocommerce-error"><li>Billing Email address is a required field.<span class="cap-captcha-kept"></span></li></ul>';
const SPENT = '<ul class="woocommerce-error"><li>Payment declined.</li></ul>';
// WooCommerce's generic message when the response carried no notice or no JSON.
const GENERIC = '<div class="woocommerce-error">Error processing checkout. Please try again.</div>';

test('a failed checkout resets the widget, with or without a notice', async () => {
    const context = fixture();

    context.handlers.body.checkout_error({}, SPENT);
    context.handlers.body.checkout_error({}, GENERIC);
    context.handlers.body.checkout_error({}, undefined);
    await settle();

    assert.equal(context.getResets(), 3);
});

test('a failed checkout solves a fresh token in programmatic mode', async () => {
    const context = fixture({ programmatic: true });

    context.handlers.body.checkout_error({}, SPENT);
    await settle();

    assert.equal(context.getSolves(), 1);
    assert.equal(context.field.value, 'token-1');
});

test('a form rejected before Cap was asked keeps its token', async () => {
    const inline = fixture();
    const programmatic = fixture({ programmatic: true });

    inline.handlers.body.checkout_error({}, KEPT);
    programmatic.handlers.body.checkout_error({}, KEPT);
    await settle();

    assert.equal(inline.getResets(), 0);
    assert.equal(programmatic.getSolves(), 0);
    assert.equal(programmatic.field.value, 'spent-token');
});

test('"Place order" waits for the fresh token, then places the order', async () => {
    let release;
    const context = fixture({ programmatic: true, solve: () => new Promise((resolve) => { release = resolve; }) });

    assert.equal(context.placeOrder(), undefined);

    context.handlers.body.checkout_error({}, SPENT);
    assert.equal(context.placeOrder(), false);
    assert.equal(context.field.value, 'spent-token');
    assert.equal(context.getSubmits(), 0);

    release({ token: 'fresh' });
    await settle();

    assert.equal(context.field.value, 'fresh');
    assert.equal(context.getSubmits(), 1);
    assert.equal(context.placeOrder(), undefined);
});

test('a failed solve releases "Place order" instead of blocking it for good', async () => {
    const context = fixture({ programmatic: true, solve: () => Promise.reject(new Error('offline')) });

    context.handlers.body.checkout_error({}, SPENT);
    await settle();

    assert.equal(context.placeOrder(), undefined);
});
