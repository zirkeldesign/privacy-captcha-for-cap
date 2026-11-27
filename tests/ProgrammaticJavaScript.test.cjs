const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

const source = fs.readFileSync(path.join(__dirname, '../assets/js/programmatic.js'), 'utf8');

const ERROR_MESSAGE = 'solve failed';

// The shared programmatic helper solves once and writes that token into every
// cap-token field on the page. Fluent Forms, wpDiscuz and WPForms fields are
// owned by fluent-forms.js, wpdiscuz.js and wpforms.js, which need one
// single-use token per form.
//
// `solves` lists what each successive Cap solve answers with: a token, or
// null for a rejection. Once the list is used up, solves return 'shared-token'.
function fixture(solves = []) {
    const handlers = {};
    const outcomes = [...solves];

    class Message {
        constructor() {
            this.hidden = false;
            this.textContent = '';
            this.className = '';
            this.classList = { contains: (name) => this.className === name };
        }

        setAttribute() {}
    }

    class Form {
        constructor(owner) {
            this.field = {
                value: '',
                nextElementSibling: null,
                closest: (selector) => (owner && selector.split(', ').includes(owner) ? {} : null),
                insertAdjacentElement(position, element) { this.nextElementSibling = element; },
            };
            this.submissions = 0;
        }

        get message() { return this.field.nextElementSibling; }
        querySelector() { return this.field; }

        // As in a browser, requestSubmit() fires the submit event again. The
        // cap turns a handler that resubmits an empty field into a failure
        // rather than an endless run.
        requestSubmit(submitter) {
            if (++this.submissions > 5) return;
            this.submitter = submitter;
            handlers.submit(submitOf(this, submitter));
        }
    }

    const plain = new Form(null);
    const fluent = new Form('.cap-captcha--fluent');
    const wpdiscuz = new Form('.cap-captcha--wpdiscuz');
    const wpforms = new Form('.cap-captcha--wpforms');
    const elementor = new Form('.cap-captcha--elementor');
    const document = {
        readyState: 'complete',
        querySelectorAll: () => [plain.field, fluent.field, wpdiscuz.field, wpforms.field, elementor.field],
        createElement: () => new Message(),
        addEventListener: (name, handler) => { handlers[name] = handler; },
    };
    const window = {
        CAP_CAPTCHA_PROGRAMMATIC: { apiEndpoint: 'https://example.test/', tokenField: 'cap-token', errorMessage: ERROR_MESSAGE },
        Cap: class {
            solve() {
                const token = outcomes.length ? outcomes.shift() : 'shared-token';

                return token === null ? Promise.reject(new Error('refused')) : Promise.resolve({ token });
            }
        },
    };
    vm.runInNewContext(source, { window, document, HTMLFormElement: Form, setTimeout });

    return { plain, fluent, wpdiscuz, wpforms, elementor, handlers };
}

function submitOf(form, submitter = null) {
    return { target: form, submitter, preventDefault() { this.prevented = true; } };
}

async function settle() {
    await new Promise((resolve) => setImmediate(resolve));
}

test('the shared token is written to ordinary forms but not to Fluent Forms, wpDiscuz or WPForms fields', async () => {
    const context = fixture();
    await settle();
    assert.equal(context.plain.field.value, 'shared-token');
    assert.equal(context.fluent.field.value, '');
    assert.equal(context.wpdiscuz.field.value, '');
    assert.equal(context.wpforms.field.value, '');
    assert.equal(context.elementor.field.value, '');
});

test('an early submit is held for ordinary forms and left alone for Fluent Forms and WPForms', async () => {
    const context = fixture();
    const held = submitOf(context.plain);
    const untouched = submitOf(context.fluent);
    const wpforms = submitOf(context.wpforms);
    context.handlers.submit(held);
    context.handlers.submit(untouched);
    context.handlers.submit(wpforms);
    await settle();

    assert.equal(held.prevented, true);
    assert.equal(context.plain.submissions, 1);
    assert.equal(untouched.prevented, undefined);
    assert.equal(context.fluent.submissions, 0);
    assert.equal(wpforms.prevented, undefined);
    assert.equal(context.wpforms.submissions, 0);
});

test('two clicks while one token is being solved send the form once', async () => {
    const context = fixture();
    context.handlers.submit(submitOf(context.plain));
    context.handlers.submit(submitOf(context.plain));
    await settle();

    assert.equal(context.plain.submissions, 1);
});

test('a held submit is sent with the button that was pressed', async () => {
    const context = fixture();
    const button = { name: 'wp-submit' };
    context.handlers.submit(submitOf(context.plain, button));
    await settle();

    assert.equal(context.plain.submitter, button);
});

test('a failed solve shows the message under ordinary fields only', async () => {
    const context = fixture([null]);
    await settle();

    assert.equal(context.plain.message.textContent, ERROR_MESSAGE);
    assert.equal(context.plain.message.hidden, false);
    assert.equal(context.fluent.message, null);
    assert.equal(context.wpdiscuz.message, null);
    assert.equal(context.wpforms.message, null);
});

test('a submit is held and not sent while the solve keeps failing', async () => {
    const context = fixture([null, null]);
    await settle();
    const held = submitOf(context.plain);
    context.handlers.submit(held);
    await settle();

    assert.equal(held.prevented, true);
    assert.equal(context.plain.submissions, 0);
    assert.equal(context.plain.field.value, '');
    assert.equal(context.plain.message.hidden, false);
});

test('a submit after a failed solve solves again and sends the form', async () => {
    const context = fixture([null, 'second-token']);
    await settle();
    context.handlers.submit(submitOf(context.plain));
    await settle();

    assert.equal(context.plain.field.value, 'second-token');
    assert.equal(context.plain.submissions, 1);
    assert.equal(context.plain.message.hidden, true);
});

test('a solve that answers without a token counts as failed', async () => {
    const context = fixture(['']);
    await settle();
    context.handlers.submit(submitOf(context.plain));
    await settle();

    assert.equal(context.plain.field.value, 'shared-token');
    assert.equal(context.plain.submissions, 1);
});
