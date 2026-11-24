// Test-tags: areas=templates risks=FLUXO,REGRA
// WEL-296: the 360Dialog JSON tab of the HSM form (only while a 360Dialog template is picked, read only, copy button) and
// the card inputs that follow the number of cards of the picked template (hidden ones are emptied, all shown are required).
//
// Run inside the Mautic container, no extra dependencies:
//   docker exec mautic-mautic_web-1 sh -c "cd /var/www/html/docroot/plugins/N8nDispatchBundle && node --test 'Tests/js/*.test.js'"
'use strict';

const test   = require('node:test');
const assert = require('node:assert');
const fs     = require('node:fs');
const path   = require('node:path');

const code = fs.readFileSync(path.join(__dirname, '../../Assets/js/campaign-hsm-dispatch.js'), 'utf8');

const TEXTS = {min: 'MIN', gap: 'GAP', url: 'URL %card%', required: 'REQ %card%'};
const DATA  = {'min-text': 'MIN', 'gap-text': 'GAP', 'url-text': 'URL %card%', 'required-text': 'REQ %card%'};

function load({list, provider = 'dialoghsm', cards = ['', '', '', ''], clipboard = null, secure = true}) {
    const st    = {json: '', jsonTab: null, tabShown: [], alert: '', copyLabel: 'Copy', copied: [], handlers: {}, tabActive: false};
    const win   = {isSecureContext: secure};
    const vals  = {provider, hsmTemplate: '', templateLanguage: '', text: '', type: 'text', number: 3};
    const inputs = cards.map((v) => {
        const col = {shown: true, hide() { col.shown = false; }, css(k, v2) { if ('display' === k && '' === v2) { col.shown = true; } }};
        const inp = {value: v};

        inp.col = col;

        return inp;
    });
    const select = {innerHTML: '', value: '', appendChild: () => {}};
    const shared = new Proxy({}, {get: (t, k) => ('hasUnmappedVariables' === k ? () => false : () => {})});
    const Mautic = {n8ndispatchShared: shared, ajaxActionRequest: () => {}};
    const key    = (s) => (/hsmTemplate/.test(s) ? 'hsmTemplate' : /templateLanguage/.test(s) ? 'templateLanguage' : /\[text\]/.test(s) ? 'text' : /\[type\]/.test(s) ? 'type' : /\[provider\]/.test(s) ? 'provider' : /number|^#x$/.test(s) ? 'number' : 'other');
    const spanData = {};
    const span     = {
        data: (k, v) => { if (undefined === v) { return spanData[k]; } spanData[k] = v; return span; },
        text: (t) => { if (undefined === t) { return st.copyLabel; } st.copyLabel = t; st.copied.push(t); return span; },
    };


    let document = null;
    const mQuery = (arg) => {
        const sel  = 'string' === typeof arg ? arg : null;
        const self = arg && arg.__input ? arg : null;
        if (arg && arg.__button) {
            return {find: () => span};
        }

        if ('#n8ndispatch-hsm-json-copy' === sel) {
            return {data: () => 'Copied!'};
        }

        const node = {
            val:  (v) => {
                if (self) { if (undefined === v) { return self.value; } self.value = v; return chain; }
                if (undefined === v) { return vals[key(sel || '')]; }
                vals[key(sel || '')] = v;

                return chain;
            },
            prop: (k, v) => (undefined === v ? false : chain),
            data: (k, v) => {
                if (sel && /json-copy/.test(sel)) { return 'Copied!'; }
                if (sel === '#n8ndispatch-hsm-cards') { return DATA[k]; }
                if (sel === '#n8ndispatch-hsm-config') { return 'x'; }
                if (undefined !== v) { spanData[k] = v; return chain; }

                return 'span' === (arg && arg.__kind) ? spanData[k] : 'x';
            },
            length: '#n8ndispatch-hsm-preview' === sel ? 0 : 1,
            text: (t) => {
                if (sel === '#n8ndispatch-hsm-json') { if (undefined === t) { return st.json; } st.json = t; return chain; }
                if (arg && 'span' === arg.__kind) { if (undefined === t) { return st.copyLabel; } st.copyLabel = t; st.copied.push(t); return chain; }

                return chain;
            },
            toggle: (on) => { if (sel === '#hsm-json-tab') { st.jsonTab = on; } return chain; },
            hasClass: () => (sel === '#hsm-json-tab' ? st.tabActive : false),
            tab: (a) => { st.tabShown.push([sel, a]); st.tabActive = false; return chain; },
            html: () => { if (sel === '#n8ndispatch-hsm-cards-alert') { st.alert = ''; } return chain; },
            append: (n) => { if (sel === '#n8ndispatch-hsm-cards-alert') { st.alert = n.__text; } return chain; },
            each: (fn) => { if (sel === '#n8ndispatch-hsm-cards input') { inputs.forEach((inp, i) => fn.call(Object.assign(inp, {__input: true}), i)); } return chain; },
            closest: () => (self ? self.col : chain),
            find: () => ({__kind: 'span', __parent: true}),
            on: (ev, a, b) => {
                const fn = 'function' === typeof a ? a : b;
                if (arg === document && 'string' === typeof ev && 0 === ev.indexOf('click') && fn) { st.handlers.click = fn; }
                if (arg === select && fn) { st.handlers.change = fn; }

                return chain;
            },
            off: () => chain,
        };
        const chain = new Proxy(node, {get: (t, k) => (k in t ? t[k] : () => chain)});

        if ('string' === typeof arg && '<div class="alert alert-warning"></div>' === arg) {
            return {text: (t) => ({__text: t})};
        }

        if ('string' === typeof arg && '<textarea></textarea>' === arg) {
            return {val: () => ({css: () => ({appendTo: () => ({0: {select() {}}, remove() {}})})})};
        }

        return chain;
    };

    document = {body: {contains: () => true}, getElementById: (id) => ('n8ndispatch-hsm-config' === id ? {id: 'c'} : select), querySelector: () => null, execCommand: () => {}};
    const fetch    = () => Promise.resolve({ok: true, json: () => Promise.resolve({available: true, templates: list})});
    const context  = {window: Object.assign(win, {Mautic, mQuery, setTimeout: (fn) => { fn(); return 1; }, clearTimeout: () => {}}), Mautic, mQuery, document, fetch, Option: function (l, v) { this.text = l; this.value = v; }, setTimeout: (fn) => { fn(); return 1; }, clearTimeout: () => {}, navigator: {clipboard}, JSON, Array, String, parseInt};

    require('node:vm').runInNewContext(code, context);

    return {Mautic, st, vals, select, inputs};
}

const tick = () => new Promise((resolve) => setImmediate(resolve));

async function pick(page, name) {
    page.Mautic.n8nDispatchOnHsmProviderChange({});
    await tick();
    page.select.value = name + '|pt_BR';
    page.st.handlers.change();
}

const TPL = (name, extra) => Object.assign({name, language: 'pt_BR', approved: true, body: {text: 'hi'}}, extra || {});

test('exact-N rule: every one of the N cards is required, in order, and must be an https link', () => {
    const f = load({list: []}).Mautic.n8nDispatchCardsProblem;

    assert.strictEqual(f(['https://a.com/1.png', '', '', ''], TEXTS, 3), 'REQ 2');
    assert.strictEqual(f(['https://a.com/1.png', 'https://a.com/2.png', 'http://a.com/3.png'], TEXTS, 3), 'URL 3');
    assert.strictEqual(f(['https://a.com/1.png', 'https://a.com/2.png', 'https://a.com/3.png', ''], TEXTS, 3), '', 'the 4th is not asked');
    assert.strictEqual(f(['   ', 'https://a.com/2.png'], TEXTS, 2), 'REQ 1', 'blank counts as missing');
});

test('without a limit the old rules stay: min 2 reminder, gap and url', () => {
    const f = load({list: []}).Mautic.n8nDispatchCardsProblem;

    assert.strictEqual(f(['https://a.com/1.png', '', '', ''], TEXTS, 0), 'MIN');
    assert.strictEqual(f([], TEXTS), 'MIN');
});

test('picking a 360Dialog template shows the JSON tab with the template as pretty JSON', async () => {
    const tpl  = TPL('promo', {components: [{type: 'BODY'}]});
    const page = load({list: [tpl]});

    await pick(page, 'promo');

    assert.strictEqual(page.st.jsonTab, true);
    assert.deepStrictEqual(JSON.parse(page.st.json), tpl);
    assert.ok(page.st.json.includes('\n  "name": "promo"'), 'indented, readable');
});

test('the JSON tab goes away (and its text is emptied) when the provider is no longer the 360Dialog', async () => {
    const page = load({list: [TPL('promo')]});

    await pick(page, 'promo');
    assert.strictEqual(page.st.jsonTab, true);

    page.vals.provider = 'n8n';
    page.st.handlers.change();

    assert.strictEqual(page.st.jsonTab, false);
    assert.strictEqual(page.st.json, '');
});

test('leaving the picked template (manual) hides the tab and, when it was open, goes back to the template tab', async () => {
    const page = load({list: [TPL('promo')]});

    await pick(page, 'promo');
    page.st.tabActive = true;
    page.select.value = '';
    page.st.handlers.change();

    assert.strictEqual(page.st.jsonTab, false);
    assert.strictEqual(page.st.json, '');
    assert.deepStrictEqual(page.st.tabShown, [['a[href="#hsm-template-container"]', 'show']]);
});

test('the carousel with 2 cards hides and empties the other inputs; a template without carousel shows all again', async () => {
    const page = load({list: [TPL('car', {carousel: [{}, {}]}), TPL('plain')], cards: ['https://a.com/1.png', 'https://a.com/2.png', 'https://a.com/3.png', 'https://a.com/4.png']});

    await pick(page, 'car');

    assert.deepStrictEqual(page.inputs.map((i) => i.col.shown), [true, true, false, false]);
    assert.deepStrictEqual(page.inputs.map((i) => i.value), ['https://a.com/1.png', 'https://a.com/2.png', '', ''], 'hidden inputs are emptied: nothing hidden is posted');

    page.select.value = 'plain|pt_BR';
    page.st.handlers.change();

    assert.deepStrictEqual(page.inputs.map((i) => i.col.shown), [true, true, true, true]);
});

test('the carousel count may come as a number: 3 cards required, the alert asks for the first empty one', async () => {
    const page = load({list: [TPL('car', {carousel: '3'})], cards: ['https://a.com/1.png', '', '', '']});

    await pick(page, 'car');

    assert.deepStrictEqual(page.inputs.map((i) => i.col.shown), [true, true, true, false]);
    assert.strictEqual(page.st.alert, 'REQ 2');
});

test('the copy button writes the JSON to the clipboard and the label turns to "Copied!" then back', async () => {
    const written = [];
    const page    = load({list: [TPL('promo')], clipboard: {writeText: (t) => { written.push(t); return Promise.resolve(); }}});

    await pick(page, 'promo');
    page.st.handlers.click.call({__button: true});
    await tick();

    assert.deepStrictEqual(written, [page.st.json]);
    assert.deepStrictEqual(page.st.copied, ['Copied!', 'Copy'], 'label shown 2s, then the original comes back');
});

test('a second click inside the 2s keeps the original label (not "Copied!")', async () => {
    const page = load({list: [TPL('promo')], clipboard: {writeText: () => Promise.resolve()}});

    await pick(page, 'promo');
    page.st.handlers.click.call({__button: true});
    await tick();
    page.st.handlers.click.call({__button: true});
    await tick();

    assert.strictEqual(page.st.copyLabel, 'Copy');
});
