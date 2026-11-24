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

test('a failed checkout resets the widget, whose token may be spent', async () => {
    const context = fixture();
    assert.equal(typeof context.bodyHandlers.checkout_error, 'function');

    context.bodyHandlers.checkout_error();
    await settle();

    assert.equal(context.getResets(), 1);
});

test('a failed checkout solves a fresh token in programmatic mode', async () => {
    const context = fixture({ programmatic: true });
    assert.equal(context.getSolves(), 0);

    context.bodyHandlers.checkout_error();
    await settle();

    assert.equal(context.getSolves(), 1);
    assert.equal(context.field.value, 'token-1');
});
