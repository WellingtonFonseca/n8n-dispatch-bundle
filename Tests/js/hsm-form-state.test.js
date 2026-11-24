// Test-tags: areas=templates risks=FLUXO
// The DialogHSM template select of the HSM form: a saved template the list does not have (or has without the language
// the old rows never kept) is not wiped; the module state belongs to one form, not to the session; the preview's scroll
// handler survives a page without the preview.
//
// Run inside the Mautic container, no extra dependencies:
//   docker exec mautic-mautic_web-1 sh -c "cd /var/www/html/docroot/plugins/N8nDispatchBundle && node --test 'Tests/js/*.test.js'"
'use strict';

const test   = require('node:test');
const assert = require('node:assert');
const fs     = require('node:fs');
const path   = require('node:path');

const code = fs.readFileSync(path.join(__dirname, '../../Assets/js/campaign-hsm-dispatch.js'), 'utf8');

function load({vals, list}) {
    const log   = {ajax: 0, params: null, windowOff: [], handler: null, previewPresent: false};
    const nodes = {config: {id: 'config1'}};
    const data  = {'templates-url': '/t/', 'number-id': 'num', 'select-placeholder': 'p', 'select-missing': '%name%'};
    const win   = {};

    const shared = new Proxy({}, {get: (t, k) => ('hasUnmappedVariables' === k ? () => false : () => {})});
    const Mautic = {n8ndispatchShared: shared, ajaxActionRequest: (a, params) => { log.ajax++; log.params = params; }};

    const key = (sel) => (/hsmTemplate/.test(sel) ? 'hsmTemplate' : /templateLanguage/.test(sel) ? 'templateLanguage' : /\[text\]/.test(sel) ? 'text' : /variables-json/.test(sel) ? 'json' : /provider/.test(sel) ? 'provider' : /^#num$/.test(sel) ? 'number' : null);

    const mQuery = (arg) => {
        const sel  = 'string' === typeof arg ? arg : null;
        const node = {
            val:    (v) => {
                if (undefined === v) {
                    return vals[key(sel)];
                }
                vals[key(sel)] = v;

                return chain;
            },
            data:   (k) => (undefined === data[k] ? 'x' : data[k]),
            length: '#n8ndispatch-hsm-preview' === sel && !log.previewPresent ? 0 : 1,
            off:    (ev) => { if (arg === win) { log.windowOff.push(ev); } return chain; },
            on:     (ev, fn) => { if (arg === win && 'function' === typeof fn) { log.handler = fn; } return chain; },
        };
        const chain = new Proxy(node, {get: (t, k) => (k in t ? t[k] : () => chain)});

        return chain;
    };

    const select   = {innerHTML: '', appendChild: () => {}};
    const document = {body: {contains: () => true}, getElementById: (id) => ('n8ndispatch-hsm-config' === id ? nodes.config : select), querySelector: () => null};
    const fetch    = () => Promise.resolve({ok: true, json: () => Promise.resolve({available: true, templates: list})});
    const context  = {window: Object.assign(win, {Mautic, mQuery, setTimeout: (fn) => { fn(); return 1; }, clearTimeout: () => {}}), Mautic, mQuery, document, fetch, Option: function (l, v) { this.text = l; this.value = v; }};

    require('node:vm').runInNewContext(code, context);

    return {Mautic, log, nodes, vals};
}

const tick = () => new Promise((resolve) => setImmediate(resolve));

test('a saved template that the list does not have keeps its name, language and text', async () => {
    const vals = {hsmTemplate: 'old_tpl', templateLanguage: 'pt_BR', text: 'saved {{1}}', provider: 'dialoghsm', number: 3};
    const page = load({vals, list: [{name: 'other', language: 'pt_BR', approved: true}]});

    page.Mautic.n8nDispatchOnHsmProviderChange({});
    await tick();

    assert.strictEqual(vals.hsmTemplate, 'old_tpl');
    assert.strictEqual(vals.templateLanguage, 'pt_BR');
    assert.strictEqual(vals.text, 'saved {{1}}');
});

test('a template saved without language is matched by name and gets the language of the list', async () => {
    const vals = {hsmTemplate: 'old_tpl', templateLanguage: '', text: 'saved', provider: 'dialoghsm', number: 3};
    const page = load({vals, list: [{name: 'old_tpl', language: 'pt_BR', approved: true, body: {text: 'saved'}}]});

    page.Mautic.n8nDispatchOnHsmProviderChange({});
    await tick();

    assert.strictEqual(vals.hsmTemplate, 'old_tpl');
    assert.strictEqual(vals.templateLanguage, 'pt_BR');
});

test('a second form of the session waits for its own list before the first scan', async () => {
    const vals = {hsmTemplate: 'a', templateLanguage: 'pt_BR', text: 'x', provider: 'dialoghsm', number: 3};
    const page = load({vals, list: [{name: 'a', language: 'pt_BR', approved: true, body: {text: 'x'}}]});
    const el   = {};

    page.Mautic.n8nDispatchOnHsmProviderChange(el);
    page.Mautic.n8nDispatchInitHsmVariables(el);
    await tick();

    const afterFirst = page.log.ajax;
    assert.ok(afterFirst > 0);

    page.nodes.config = {id: 'config2'};
    page.Mautic.n8nDispatchOnHsmProviderChange(el);
    page.Mautic.n8nDispatchInitHsmVariables(el);

    assert.strictEqual(page.log.ajax, afterFirst, 'held until the list of the new form arrives');
});

test('a page without the preview does not unbind the scroll handler', () => {
    const page   = load({vals: {provider: 'n8n'}, list: []});
    const before = page.log.windowOff.length;

    page.log.handler();

    assert.strictEqual(page.log.windowOff.length, before);
});

test('a saved button row stays in the rescan when the list does not give the template', async () => {
    const vals = {hsmTemplate: 'old_tpl', templateLanguage: 'pt_BR', text: 'saved {{1}}', provider: 'dialoghsm', number: 3, json: '{"1":{},"button_1_url":{"source":"x"},"button_2_copy_code":{}}'};
    const page = load({vals, list: [{name: 'other', language: 'pt_BR', approved: true}]});

    page.Mautic.n8nDispatchOnHsmProviderChange({});
    await tick();

    assert.ok(page.log.params, 'a scan ran');
    assert.strictEqual(page.log.params.extra, 'button_1_url,button_2_copy_code');
});
