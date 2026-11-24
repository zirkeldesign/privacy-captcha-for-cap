const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

const source = fs.readFileSync(path.join(__dirname, '../assets/js/wpforms.js'), 'utf8');

// A WPForms form holding either the programmatic token field or a <cap-widget>.
function fixture({ count = 1, mode = 'programmatic', ...overrides } = {}) {
    const { setTimeout: timer = setTimeout, ...windowOverrides } = overrides;
    const handlers = {};
    let solves = 0;

    class Form {
        constructor() {
            const message = { hidden: true };
            const wrapper = { querySelector: () => message };
            this.message = message;
            this.field = mode === 'programmatic' ? { value: '', closest: () => wrapper } : null;
            this.widgetInput = { value: '' };
            this.widget = mode === 'programmatic' ? null : {
                resets: 0,
                solves: 0,
                closest: () => wrapper,
                querySelector: () => this.widgetInput,
                reset: () => { this.widget.resets++; this.widgetInput.value = ''; },
                solve: () => {
                    this.widget.solves++;
                    this.widgetInput.value = 'widget-token-' + this.widget.solves;
                    return Promise.resolve({ success: true, token: this.widgetInput.value });
                },
            };
            this.submits = 0;
        }

        querySelector(selector) {
            if (selector.includes('input[data-cap-programmatic]')) return this.field;
            if (selector.includes('cap-widget')) return this.widget;
            return {};
        }

        querySelectorAll(selector) {
            if (selector.includes('cap-widget')) return this.widget ? [this.widget] : [];
            return this.field ? [this.field] : [];
        }
    }

    const forms = Array.from({ length: count }, () => new Form());
    const document = {
        querySelectorAll: () => forms.filter((form) => form.field).map((form) => form.field),
    };
    const jquery = (value) => {
        if (typeof value === 'function') {
            value();
            return undefined;
        }
        return {
            0: value,
            on: (name, handler) => { handlers[name] = handler; },
        };
    };
    const window = {
        CAP_CAPTCHA_PROGRAMMATIC: { apiEndpoint: 'https://example.test/' },
        Cap: class { solve() { return Promise.resolve({ token: 'token-' + ++solves }); } },
        wpforms: { formSubmit: ($form) => { $form[0].submits++; } },
        ...windowOverrides,
    };
    vm.runInNewContext(source, { window, document, jQuery: jquery, Promise, WeakMap, WeakSet, setTimeout: timer });

    // WPForms triggers its events on the form; jQuery hands the handler an event and the extra data.
    function trigger(name, form, ...data) {
        const event = {
            target: form,
            prevented: false,
            preventDefault() { this.prevented = true; },
        };
        handlers[name](event, ...data);
        return event;
    }

    return { forms, handlers, trigger, window };
}

async function settle() {
    for (let i = 0; i < 5; i++) await new Promise((resolve) => setImmediate(resolve));
}

test('two programmatic forms on one page get a token each', async () => {
    const context = fixture({ count: 2 });
    await settle();
    assert.equal(context.forms[0].field.value, 'token-1');
    assert.equal(context.forms[1].field.value, 'token-2');
});

test('a submit with an unspent token goes through untouched', async () => {
    const context = fixture();
    await settle();
    const event = context.trigger('wpformsBeforeFormSubmit', context.forms[0]);
    assert.equal(event.prevented, false);
    assert.equal(context.forms[0].submits, 0);
});

test('a submit is held until the token is there, then sent once', async () => {
    const resolvers = [];
    const context = fixture({ Cap: class { solve() { return new Promise((resolve) => resolvers.push(resolve)); } } });
    const form = context.forms[0];
    const event = context.trigger('wpformsBeforeFormSubmit', form);
    assert.equal(event.prevented, true);
    assert.equal(form.submits, 0);

    resolvers[0]({ token: 'late' });
    await settle();
    assert.equal(form.field.value, 'late');
    assert.equal(form.submits, 1);

    // The resubmit by the script itself passes through.
    assert.equal(context.trigger('wpformsBeforeFormSubmit', form).prevented, false);
});

test('a failed AJAX submit renews the token unless the server kept it', async () => {
    const context = fixture({ count: 2 });
    await settle();

    context.trigger('wpformsAjaxSubmitFailed', context.forms[0], { success: false, data: { errors: {}, cap_captcha_kept: true } });
    await settle();
    assert.equal(context.forms[0].field.value, 'token-1');

    context.trigger('wpformsAjaxSubmitFailed', context.forms[0], { success: false, data: { errors: {} } });
    await settle();
    assert.equal(context.forms[0].field.value, 'token-3');
    assert.equal(context.forms[1].field.value, 'token-2');
});

test('a failed AJAX submit without any data renews the token', async () => {
    const context = fixture();
    await settle();
    context.trigger('wpformsAjaxSubmitFailed', context.forms[0]);
    await settle();
    assert.equal(context.forms[0].field.value, 'token-2');
});

test('an AJAX transport error renews the token', async () => {
    const context = fixture();
    await settle();
    context.trigger('wpformsAjaxSubmitError', context.forms[0], {}, 'error', 'Internal Server Error');
    await settle();
    assert.equal(context.forms[0].field.value, 'token-2');
});

test('programmatic mode keeps the spent token until the new one arrives, and holds a submit meanwhile', async () => {
    let resolveNext;
    let solves = 0;
    const context = fixture({
        Cap: class {
            solve() {
                solves++;
                if (solves === 1) return Promise.resolve({ token: 'first' });
                return new Promise((resolve) => { resolveNext = resolve; });
            }
        },
    });
    const form = context.forms[0];
    await settle();

    context.trigger('wpformsAjaxSubmitError', form);
    await settle();
    assert.equal(form.field.value, 'first');

    const event = context.trigger('wpformsBeforeFormSubmit', form);
    assert.equal(event.prevented, true);
    assert.equal(solves, 2);

    resolveNext({ token: 'second' });
    await settle();
    assert.equal(form.field.value, 'second');
    assert.equal(form.submits, 1);
});

test('a refresh during a pending solve discards the stale token', async () => {
    const resolvers = [];
    const context = fixture({ Cap: class { solve() { return new Promise((resolve) => resolvers.push(resolve)); } } });
    const form = context.forms[0];
    context.trigger('wpformsAjaxSubmitError', form);
    assert.equal(resolvers.length, 2);

    resolvers[0]({ token: 'stale' });
    await settle();
    assert.equal(form.field.value, '');

    resolvers[1]({ token: 'fresh' });
    await settle();
    assert.equal(form.field.value, 'fresh');
});

test('inline mode resets the widget after a failure and keeps it after a kept failure', async () => {
    const context = fixture({ mode: 'inline' });
    const form = context.forms[0];
    form.widgetInput.value = 'solved';

    context.trigger('wpformsAjaxSubmitFailed', form, { data: { cap_captcha_kept: true } });
    assert.equal(form.widget.resets, 0);
    assert.equal(form.widgetInput.value, 'solved');

    context.trigger('wpformsAjaxSubmitFailed', form, { data: {} });
    assert.equal(form.widget.resets, 1);
    assert.equal(form.widgetInput.value, '');
});

test('inline mode solves the widget before a submit without a token', async () => {
    const context = fixture({ mode: 'inline' });
    const form = context.forms[0];

    const event = context.trigger('wpformsBeforeFormSubmit', form);
    assert.equal(event.prevented, true);
    await settle();
    assert.equal(form.widget.solves, 1);
    assert.equal(form.submits, 1);

    form.submits = 0;
    assert.equal(context.trigger('wpformsBeforeFormSubmit', form).prevented, false);
    assert.equal(form.widget.solves, 1);
});

test('a form without our widget is left alone', () => {
    const context = fixture();
    const other = { querySelector: () => null };
    const event = context.trigger('wpformsBeforeFormSubmit', other);
    assert.equal(event.prevented, false);
    context.trigger('wpformsAjaxSubmitFailed', other, { data: {} });
});

test('a missing endpoint shows the error and blocks the submit', async () => {
    let constructed = 0;
    const context = fixture({ CAP_CAPTCHA_PROGRAMMATIC: undefined, Cap: class { constructor() { constructed++; } } });
    const form = context.forms[0];
    await settle();
    assert.equal(constructed, 0);
    assert.equal(form.message.hidden, false);

    const event = context.trigger('wpformsBeforeFormSubmit', form);
    await settle();
    assert.equal(event.prevented, true);
    assert.equal(form.submits, 0);
});

test('a widget script that never loads ends in the visible error', async () => {
    let polls = 0;
    const context = fixture({ Cap: undefined, setTimeout: (callback) => { polls++; callback(); } });
    await settle();
    assert.equal(polls, 200);
    assert.equal(context.forms[0].message.hidden, false);
});

test('an empty token counts as a failed solve and is never submitted', async () => {
    const context = fixture({ Cap: class { solve() { return Promise.resolve({ token: '' }); } } });
    const form = context.forms[0];
    await settle();
    assert.equal(form.field.value, '');
    assert.equal(form.message.hidden, false);

    context.trigger('wpformsBeforeFormSubmit', form);
    await settle();
    assert.equal(form.submits, 0);
});
