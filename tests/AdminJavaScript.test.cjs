const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

const source = fs.readFileSync(path.join(__dirname, '../assets/js/admin.js'), 'utf8');

// "Test connection" posts the settings form as it stands and prints the answer
// as text, with each warning of a passed check on a line of its own.
async function run(answer, fields) {
    let click;
    let request;
    const out = {
        className: '',
        textContent: '',
        lines: [],
        classList: { add: (name) => { out.className += ' ' + name; } },
        appendChild: (line) => out.lines.push(line),
    };
    const btn = {
        disabled: false,
        form: {},
        addEventListener: (name, handler) => { click = handler; },
    };
    const document = {
        readyState: 'complete',
        querySelector: (selector) => (selector === '[data-cap-captcha-test]' ? btn : out),
        querySelectorAll: () => [],
        createElement: () => ({ className: '', textContent: '' }),
    };
    const window = { capCaptchaAdmin: { ajaxUrl: '/ajax', nonce: 'n', optionKey: 'cap_captcha_settings' } };
    class FormData { forEach(fn) { fields.forEach(([name, value]) => fn(value, name)); } }
    const fetch = async (url, options) => { request = options.body; return { json: async () => answer }; };

    vm.runInNewContext(source, { window, document, FormData, URLSearchParams, fetch });
    await click({ preventDefault() {} });

    return { out, btn, request };
}

test('the form goes along as it stands, without fields of other forms or files', async () => {
    const { request, btn } = await run({ success: true, data: { message: 'ok' } }, [
        ['cap_captcha_settings[site_key]', 'typed'],
        ['unrelated', 'x'],
        ['cap_captcha_settings[file]', {}],
    ]);

    assert.equal(request.get('action'), 'cap_captcha_test_connection');
    assert.equal(request.get('cap_captcha_settings[site_key]'), 'typed');
    assert.equal(request.has('unrelated'), false);
    assert.equal(request.has('cap_captcha_settings[file]'), false);
    assert.equal(btn.disabled, false);
});

test('a passed check with warnings is marked as a warning and prints them as text', async () => {
    const { out } = await run({ success: true, data: { message: 'Connected', warnings: ['<b>one</b>', 'two'] } }, []);

    assert.match(out.className, /result--warn/);
    assert.equal(out.textContent, 'Connected');
    assert.deepEqual(out.lines.map((line) => line.textContent), ['<b>one</b>', 'two']);
});

test('a passed check without warnings is marked ok and a refusal as an error', async () => {
    const ok = await run({ success: true, data: { message: 'Connected' } }, []);
    const refused = await run({ success: false, data: { message: 'Refused' } }, []);

    assert.match(ok.out.className, /result--ok/);
    assert.equal(ok.out.lines.length, 0);
    assert.match(refused.out.className, /result--err/);
    assert.equal(refused.out.textContent, 'Refused');
});
