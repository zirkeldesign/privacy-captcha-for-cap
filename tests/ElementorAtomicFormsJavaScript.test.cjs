const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

const source = fs.readFileSync(path.join(__dirname, '../assets/js/elementor-atomic-forms.js'), 'utf8');

// Elementor's side of the contract: collect what the listeners add, wait for all of it, merge.
async function submit(handlers) {
    const pending = [];
    handlers['elementor/atomic-form/before-submit']({ detail: { addFields: (fields) => pending.push(fields) } });
    const resolved = await Promise.all(pending);

    // Copied into this realm: the script's objects come from the vm context.
    return JSON.parse(JSON.stringify(Object.assign({}, ...resolved.filter((fields) => fields && typeof fields === 'object'))));
}

function load({ cfg = { apiEndpoint: 'https://cap.example/key/' }, solve } = {}) {
    const handlers = {};
    const state = { solves: 0, endpoints: [] };
    const window = { CAP_CAPTCHA_ATOMIC_FORM: cfg };
    window.Cap = class {
        constructor(options) { state.endpoints.push(options.apiEndpoint); }

        solve() {
            state.solves++;
            return solve ? solve(state.solves) : Promise.resolve({ token: 'token-' + state.solves });
        }
    };
    vm.runInNewContext(source, { window, document: { addEventListener: (name, handler) => { handlers[name] = handler; } } });

    return { handlers, state, window };
}

test('adds a token to the submission', async () => {
    const { handlers, state } = load();

    assert.deepEqual(await submit(handlers), { 'cap-token': 'token-1' });
    assert.deepEqual(state.endpoints, ['https://cap.example/key/']);
});

test('solves a fresh token for every attempt', async () => {
    const { handlers } = load();

    await submit(handlers);
    assert.deepEqual(await submit(handlers), { 'cap-token': 'token-2' });
});

test('sends no token when the solve fails, and does not break the submission', async () => {
    const { handlers } = load({ solve: () => Promise.reject(new Error('unreachable')) });

    assert.deepEqual(await submit(handlers), {});
});

test('stays out of the way without a configuration or before the solver has loaded', async () => {
    assert.deepEqual(load({ cfg: null }).handlers, {});

    const { handlers, window } = load();
    delete window.Cap;
    assert.deepEqual(await submit(handlers), {});
});
