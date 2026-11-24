// Test-tags: areas=dispatch-hsm,templates risks=FLUXO
// The "Texto do HSM" field of the HSM form: with a DialogHSM template list it stays visible but read only (it still
// posts, so it is not disabled); with the manual form (other provider, list not available) it is visible and editable.
//
// Run inside the Mautic container, no extra dependencies:
//   docker exec mautic-mautic_web-1 sh -c "cd /var/www/html/docroot/plugins/N8nDispatchBundle && node --test 'Tests/js/*.test.js'"
'use strict';

const test   = require('node:test');
const assert = require('node:assert');
const fs     = require('node:fs');
const path   = require('node:path');

const code = fs.readFileSync(path.join(__dirname, '../../Assets/js/campaign-hsm-dispatch.js'), 'utf8');

function load({provider, available, list}) {
    const state = {readonly: undefined, rowHidden: false, textVisible: undefined};
    const data  = {'templates-url': '/t/', 'number-id': 'num', 'select-placeholder': 'p', 'select-missing': '%name%'};
    const win   = {};
    const select = {innerHTML: '', value: '', appendChild: () => {}};
    const shared = new Proxy({}, {get: (t, k) => ('hasUnmappedVariables' === k ? () => false : () => {})});
    const Mautic = {n8ndispatchShared: shared, ajaxActionRequest: () => {}};

    const mQuery = (arg) => {
        const sel  = 'string' === typeof arg ? arg : '';
        const isText = /textarea\[name\$="\[text\]"\]/.test(sel);
        const isRow  = '#n8ndispatch-hsm-text-row' === sel || /#n8ndispatch-hsm-text-row/.test(sel);
        const node = {
            val:   () => (/provider/.test(sel) ? provider : /^#num$/.test(sel) ? 3 : ''),
            prop:  (k, v) => {
                if ('readonly' === k && isText && undefined !== v) {
                    state.readonly = v; if (process.env.DBG) console.log('ro', v, new Error().stack.split('\n').slice(2,5).join('|'));
                }

                return chain;
            },
            data:  (k) => (undefined === data[k] ? 'x' : data[k]),
            length: '#n8ndispatch-hsm-preview' === sel ? 0 : 1,
            show:  () => { if (isRow) { state.rowHidden = false; } return chain; },
            hide:  () => { if (isRow && !/manual/.test(sel)) { state.rowHidden = true; } return chain; },
        };
        const chain = new Proxy(node, {get: (t, k) => (k in t ? t[k] : () => chain)});

        return chain;
    };

    const document = {body: {contains: () => true}, getElementById: (id) => ('n8ndispatch-hsm-config' === id ? {id: 'c'} : select), querySelector: () => null};
    const fetch    = () => Promise.resolve({ok: true, json: () => Promise.resolve({available, templates: list})});
    const context  = {window: Object.assign(win, {Mautic, mQuery, setTimeout: (fn) => { fn(); return 1; }, clearTimeout: () => {}}), Mautic, mQuery, document, fetch, Option: function (l, v) { this.text = l; this.value = v; }};

    require('node:vm').runInNewContext(code, context);

    return {Mautic, state};
}

const tick = () => new Promise((resolve) => setImmediate(resolve));

test('DialogHSM template list: the text field is shown and read only', async () => {
    const page = load({provider: 'dialoghsm', available: true, list: [{name: 'a', language: 'pt_BR', approved: true, body: {text: 'hi'}}]});

    page.Mautic.n8nDispatchOnHsmProviderChange({});
    await tick();

    assert.strictEqual(page.state.readonly, true, 'read only, so the text follows the template');
    assert.strictEqual(page.state.rowHidden, false, 'the text row is not hidden');
});

test('DialogHSM list not available: the manual form gives the text field back editable', async () => {
    const page = load({provider: 'dialoghsm', available: false, list: []});

    page.Mautic.n8nDispatchOnHsmProviderChange({});
    await tick();

    assert.strictEqual(page.state.readonly, false);
    assert.strictEqual(page.state.rowHidden, false);
});

test('other provider: the text field is editable', async () => {
    const page = load({provider: 'n8n', available: true, list: []});

    page.Mautic.n8nDispatchOnHsmProviderChange({});
    await tick();

    assert.strictEqual(page.state.readonly, false);
});
