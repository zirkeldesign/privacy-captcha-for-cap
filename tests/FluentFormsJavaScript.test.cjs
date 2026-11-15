const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

const source = fs.readFileSync(path.join(__dirname, '../assets/js/fluent-forms.js'), 'utf8');

function fixture(count = 1) {
    const handlers = {};
    const jqueryHandlers = {};
    let solves = 0;
    let resetCount = 0;

    class Form {
        constructor() {
            const message = { hidden: true };
            const wrapper = { querySelector: () => message };
            this.field = { value: '', closest: () => wrapper };
            this.message = message;
            this.widget = { reset: () => { resetCount++; } };
            this.submissions = [];
        }

        querySelector() { return this.field; }
        querySelectorAll(selector) {
            return selector.includes('cap-widget') ? [this.widget] : [this.field];
        }
        requestSubmit(submitter) { this.submissions.push(submitter); }
    }

    const forms = Array.from({ length: count }, () => new Form());
    const document = {
        querySelectorAll: () => forms.map((form) => form.field),
        addEventListener: (name, handler) => { handlers[name] = handler; },
    };
    const jquery = (value) => {
        if (typeof value === 'function') {
            value();
            return;
        }
        return {
            on: (name, selector, handler) => {
                jqueryHandlers[name] = handler || selector;
            },
        };
    };
    const window = {
        CAP_CAPTCHA_PROGRAMMATIC: { apiEndpoint: 'https://example.test/' },
        Cap: class {
            solve() { return Promise.resolve({ token: 'token-' + ++solves }); }
        },
    };
    vm.runInNewContext(source, { window, document, jQuery: jquery, HTMLFormElement: Form, Promise, WeakMap, setTimeout });

    return { forms, handlers, jqueryHandlers, window, getResetCount: () => resetCount };
}

async function settle() {
    await new Promise((resolve) => setImmediate(resolve));
}

test('programmatic forms receive independent tokens and refresh after AJAX failure', async () => {
    const context = fixture(2);
    await settle();
    assert.equal(context.forms[0].field.value, 'token-1');
    assert.equal(context.forms[1].field.value, 'token-2');

    context.jqueryHandlers.fluentform_submission_failed.call(context.forms[0]);
    await settle();
    assert.equal(context.getResetCount(), 1);
    assert.equal(context.forms[0].field.value, 'token-3');
    assert.equal(context.forms[1].field.value, 'token-2');
});

test('successful AJAX submission refreshes the submitted form', async () => {
    const context = fixture();
    await settle();
    context.jqueryHandlers.fluentform_submission_success({}, { form: [context.forms[0]] });
    await settle();
    assert.equal(context.forms[0].field.value, 'token-2');
});

test('submission waits for a pending solve and retries with the same submitter', async () => {
    const context = fixture();
    const form = context.forms[0];
    const submitter = {};
    const event = {
        target: form,
        submitter,
        preventDefault() { this.prevented = true; },
        stopImmediatePropagation() { this.stopped = true; },
    };
    context.handlers.submit(event);
    await settle();
    assert.equal(event.prevented, true);
    assert.equal(event.stopped, true);
    assert.equal(form.field.value, 'token-1');
    assert.equal(form.submissions[0], submitter);
});

test('solver errors are visible and a later submit starts a new challenge', async () => {
    const context = fixture();
    await settle();
    context.window.Cap = class { solve() { return Promise.reject(new Error('offline')); } };
    context.jqueryHandlers.fluentform_submission_failed.call(context.forms[0]);
    await settle();
    assert.equal(context.forms[0].message.hidden, false);

    context.window.Cap = class { solve() { return Promise.resolve({ token: 'retry' }); } };
    const event = { target: context.forms[0], preventDefault() {}, stopImmediatePropagation() {} };
    context.handlers.submit(event);
    await settle();
    assert.equal(context.forms[0].field.value, 'retry');
    assert.equal(context.forms[0].message.hidden, true);
    assert.equal(context.forms[0].submissions.length, 1);
});