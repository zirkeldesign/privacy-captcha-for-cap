const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

const source = fs.readFileSync(path.join(__dirname, '../assets/js/wpdiscuz.js'), 'utf8');

// wpDiscuz forms: the main form, the hidden reply template, and a reply form
// cloned from it later. Each programmatic field needs its own token.
function fixture({ programmatic = true, config = true } = {}) {
    let solves = 0;
    let clickHandler;
    let bodyEvents;
    let bodyHandler;

    function makeForm(inTemplate) {
        const message = { hidden: true };
        const wrapper = { querySelector: () => message };
        const form = { clicks: 0, resets: 0, message };
        form.field = programmatic
            ? { value: '', closest: (selector) => (selector === '#wpdiscuz_hidden_secondary_form' ? (inTemplate ? {} : null) : wrapper) }
            : null;
        form.widget = { reset: () => { form.resets++; } };
        form.button = {
            closest: (selector) => (selector === '.wc_comm_submit' ? form.button : form),
            click: () => { form.clicks++; },
        };
        form.querySelector = () => form.field;
        form.querySelectorAll = (selector) => (selector.includes('cap-widget') ? [form.widget] : []);
        return form;
    }

    const main = makeForm(false);
    const template = makeForm(true);
    const document = {
        body: {},
        querySelectorAll: () => [main.field, template.field].filter(Boolean),
        addEventListener: (name, handler) => { if (name === 'click') clickHandler = handler; },
    };
    const jquery = (value) => {
        if (typeof value === 'function') {
            value();
            return;
        }
        return { on: (events, handler) => { bodyEvents = events; bodyHandler = handler; } };
    };
    const window = {
        CAP_CAPTCHA_PROGRAMMATIC: config ? { apiEndpoint: 'https://example.test/' } : undefined,
        Cap: class {
            solve() { return Promise.resolve({ token: 'token-' + ++solves }); }
        },
    };
    vm.runInNewContext(source, { window, document, jQuery: jquery, Promise, WeakMap, setTimeout });

    function click(form) {
        const event = {
            target: form.button,
            preventDefault() { this.prevented = true; },
            stopImmediatePropagation() { this.stopped = true; },
        };
        clickHandler(event);
        return event;
    }

    return {
        main,
        template,
        makeForm,
        click,
        fire: (form) => bodyHandler({}, [form]),
        bodyEvents: () => bodyEvents,
        solves: () => solves,
    };
}

async function settle() {
    await new Promise((resolve) => setImmediate(resolve));
}

test('the main form is solved on load, the hidden reply template is not', async () => {
    const context = fixture();
    await settle();

    assert.equal(context.main.field.value, 'token-1');
    assert.equal(context.template.field.value, '');
    assert.equal(context.solves(), 1);
});

test('a submit click on a cloned reply form waits for its own token, then clicks again', async () => {
    const context = fixture();
    await settle();
    const reply = context.makeForm(false);

    const held = context.click(reply);
    assert.equal(held.prevented, true);
    assert.equal(held.stopped, true);
    await settle();

    assert.equal(reply.field.value, 'token-2');
    assert.equal(reply.clicks, 1);

    const passed = context.click(reply);
    assert.equal(passed.prevented, undefined);
});

test('a finished or failed post resets the widget and solves a fresh token', async () => {
    const context = fixture();
    await settle();

    assert.match(context.bodyEvents(), /wpdiscuz_comment_post_complete/);
    assert.match(context.bodyEvents(), /wpdiscuz_comment_post_failed/);
    context.fire(context.main);
    assert.equal(context.main.resets, 1);
    assert.equal(context.main.field.value, '');
    await settle();
    assert.equal(context.main.field.value, 'token-2');
});

test('inline and floating forms are left to the widget, only reset after a post', async () => {
    const context = fixture({ programmatic: false });
    const event = context.click(context.main);

    assert.equal(event.prevented, undefined);
    context.fire(context.main);
    assert.equal(context.main.resets, 1);
    assert.equal(context.solves(), 0);
});

test('a missing configuration shows the error instead of a silently dead button', async () => {
    const context = fixture({ config: false });
    await settle();
    assert.equal(context.main.message.hidden, false);

    const event = context.click(context.main);
    await settle();
    assert.equal(event.prevented, true);
    assert.equal(context.main.clicks, 0);
});
