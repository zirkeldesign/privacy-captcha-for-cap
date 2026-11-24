const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

const source = fs.readFileSync(path.join(__dirname, '../assets/js/programmatic.js'), 'utf8');

// The shared programmatic helper solves once and writes that token into every
// cap-token field on the page. Fluent Forms, wpDiscuz and WPForms fields are
// owned by fluent-forms.js, wpdiscuz.js and wpforms.js, which need one
// single-use token per form.
function fixture() {
    const handlers = {};

    class Form {
        constructor(owner) {
            this.field = { value: '', closest: (selector) => (owner && selector.split(', ').includes(owner) ? {} : null) };
            this.submissions = 0;
        }

        querySelector() { return this.field; }
        requestSubmit() { this.submissions++; }
    }

    const plain = new Form(null);
    const fluent = new Form('.cap-captcha--fluent');
    const wpdiscuz = new Form('.cap-captcha--wpdiscuz');
    const wpforms = new Form('.cap-captcha--wpforms');
    const document = {
        readyState: 'complete',
        querySelectorAll: () => [plain.field, fluent.field, wpdiscuz.field, wpforms.field],
        addEventListener: (name, handler) => { handlers[name] = handler; },
    };
    const window = {
        CAP_CAPTCHA_PROGRAMMATIC: { apiEndpoint: 'https://example.test/', tokenField: 'cap-token' },
        Cap: class { solve() { return Promise.resolve({ token: 'shared-token' }); } },
    };
    vm.runInNewContext(source, { window, document, HTMLFormElement: Form, setTimeout });

    return { plain, fluent, wpdiscuz, wpforms, handlers };
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
});

test('an early submit is held for ordinary forms and left alone for Fluent Forms and WPForms', async () => {
    const context = fixture();
    const held = { target: context.plain, preventDefault() { this.prevented = true; } };
    const untouched = { target: context.fluent, preventDefault() { this.prevented = true; } };
    const wpforms = { target: context.wpforms, preventDefault() { this.prevented = true; } };
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
