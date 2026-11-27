const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

const source = fs.readFileSync(path.join(__dirname, '../assets/js/elementor-forms.js'), 'utf8');
const tick = () => new Promise((resolve) => setImmediate(resolve));

function fixture() {
    const handlers = {};
    const jqueryHandlers = {};
    const state = { solves: 0, resets: 0 };

    class Form {
        constructor() {
            const message = { hidden: true };
            this.field = { value: '', closest: () => ({ querySelector: () => message }) };
            this.widget = { reset: () => { state.resets++; } };
            this.submissions = 0;
        }

        querySelector() { return this.field; }
        querySelectorAll(selector) { return selector.includes('cap-widget') ? [this.widget] : [this.field]; }

        // As in a browser, requestSubmit() fires the submit event again.
        requestSubmit() {
            if (++this.submissions > 5) return;
            handlers.submit({ target: this, preventDefault() {}, stopImmediatePropagation() {} });
        }
    }

    const form = new Form();
    const document = {
        querySelectorAll: () => [form.field],
        addEventListener: (name, handler) => { handlers[name] = handler; },
    };
    const jquery = (value) => {
        if (typeof value === 'function') value();

        return { on: (events, selector, handler) => { jqueryHandlers[events] = { selector, handler }; } };
    };
    const window = {
        CAP_CAPTCHA_PROGRAMMATIC: { apiEndpoint: 'https://example.test/' },
        Cap: class {
            solve() { return Promise.resolve({ token: `token-${++state.solves}` }); }
        },
    };
    vm.runInNewContext(source, { window, document, jQuery: jquery, HTMLFormElement: Form, WeakMap, Promise, setTimeout });

    return { form, handlers, jqueryHandlers, state };
}

test('solves a token of its own for the form when the page loads', async () => {
    const { form, state } = fixture();
    await tick();

    assert.equal(form.field.value, 'token-1');
    assert.equal(state.solves, 1);
});

test('holds a submit without a token and sends the form once the token is there', async () => {
    const { form, handlers } = fixture();
    form.field.value = '';
    let held = false;
    handlers.submit({ target: form, preventDefault() { held = true; }, stopImmediatePropagation() {} });
    await tick();

    assert.equal(held, true);
    assert.equal(form.field.value, 'token-1');
    assert.equal(form.submissions, 1);
});

test('renews the token after a failed and after a successful submission', async () => {
    const { form, jqueryHandlers, state } = fixture();
    await tick();
    const bound = jqueryHandlers['error submit_success'];

    assert.equal(bound.selector, 'form.elementor-form');
    bound.handler.call(form);
    await tick();

    assert.equal(state.resets, 1);
    assert.equal(form.field.value, 'token-2');
});
