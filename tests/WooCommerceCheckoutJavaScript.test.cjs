const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

const source = fs.readFileSync(path.join(__dirname, '../assets/js/woocommerce-checkout.js'), 'utf8');

// The classic checkout form: an inline or floating widget, or the hidden
// programmatic field. WooCommerce triggers checkout_error on document.body.
function fixture({ programmatic = false } = {}) {
    let solves = 0;
    let resets = 0;
    const bodyHandlers = {};

    const widget = { reset: () => { resets++; } };
    const field = { value: programmatic ? 'spent-token' : '' };
    const document = {
        body: {},
        querySelectorAll: (selector) => {
            if (selector.includes('cap-widget')) return programmatic ? [] : [widget];
            if (selector.includes('data-cap-programmatic')) return programmatic ? [field] : [];
            return [];
        },
        addEventListener: () => {},
    };
    const jquery = (value) => {
        if (typeof value === 'function') {
            value();
            return;
        }
        return {
            on: (events, handler) => {
                events.split(' ').forEach((name) => { bodyHandlers[name] = handler; });
            },
        };
    };
    const window = {
        CAP_CAPTCHA_PROGRAMMATIC: { apiEndpoint: 'https://example.test/' },
        Cap: class {
            solve() { return Promise.resolve({ token: 'token-' + ++solves }); }
        },
    };
    vm.runInNewContext(source, { window, document, jQuery: jquery, Promise, setTimeout });

    return { bodyHandlers, field, getResets: () => resets, getSolves: () => solves };
}

async function settle() {
    await new Promise((resolve) => setImmediate(resolve));
}

// The server marks the errors of a checkout that spent the token.
const SPENT = '<ul class="woocommerce-error"><li>Payment declined.<span class="cap-captcha-spent"></span></li></ul>';
const UNSPENT = '<ul class="woocommerce-error"><li>Billing Email address is a required field.</li></ul>';

test('a checkout that spent the token resets the widget', async () => {
    const context = fixture();
    assert.equal(typeof context.bodyHandlers.checkout_error, 'function');

    context.bodyHandlers.checkout_error({}, SPENT);
    await settle();

    assert.equal(context.getResets(), 1);
});

test('a checkout that spent the token solves a fresh one in programmatic mode', async () => {
    const context = fixture({ programmatic: true });
    assert.equal(context.getSolves(), 0);

    context.bodyHandlers.checkout_error({}, SPENT);
    await settle();

    assert.equal(context.getSolves(), 1);
    assert.equal(context.field.value, 'token-1');
});

test('a checkout rejected before the token was checked keeps the token', async () => {
    const inline = fixture();
    const programmatic = fixture({ programmatic: true });

    inline.bodyHandlers.checkout_error({}, UNSPENT);
    programmatic.bodyHandlers.checkout_error({}, UNSPENT);
    await settle();

    assert.equal(inline.getResets(), 0);
    assert.equal(programmatic.getSolves(), 0);
    assert.equal(programmatic.field.value, 'spent-token');
});
