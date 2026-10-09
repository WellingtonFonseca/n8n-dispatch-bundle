// Runs the page script (Assets/js/campaign-hsm-dispatch.js) against a stand-in for Mautic and mQuery:
// the carousel's image inputs must show for the "carousel" type and hide for any other.
const test = require('node:test');
const assert = require('node:assert');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

function load() {
    const shown = [];
    const mQuery = (arg) => {
        if (typeof arg === 'string') {
            const chain = {
                toggle: (visible) => shown.push({selector: arg, visible}),
                data: () => '', val: () => '', html: () => chain, append: () => chain, text: () => chain,
                off: () => chain, on: () => chain, each: () => chain,
            };

            return chain;
        }

        return {val: () => arg.value, data: () => ''};
    };
    const Mautic = {n8ndispatchShared: {}};
    const code = fs.readFileSync(path.join(__dirname, '../../Assets/js/campaign-hsm-dispatch.js'), 'utf8');

    vm.runInNewContext(code, {window: {Mautic, mQuery, clearTimeout() {}, setTimeout() {}}, document: {body: {contains: () => true}}, Mautic, mQuery});

    return {Mautic, shown};
}

test('the cards show for the carousel type', () => {
    const {Mautic, shown} = load();
    Mautic.n8nDispatchOnHsmTypeChange({value: 'carousel'});
    assert.deepStrictEqual(shown, [{selector: '#n8ndispatch-hsm-cards', visible: true}]);
});

test('the cards hide for the text type', () => {
    const {Mautic, shown} = load();
    Mautic.n8nDispatchOnHsmTypeChange({value: 'text'});
    assert.deepStrictEqual(shown, [{selector: '#n8ndispatch-hsm-cards', visible: false}]);
});

const TEXTS = {min: 'Fill at least 2', gap: 'Card %filled% filled, Card %blank% empty', url: 'Card %card% bad URL'};
const A = 'https://x.test/a.png';

test('the cards alert reminds the minimum until two images are in', () => {
    const {Mautic} = load();
    assert.strictEqual(Mautic.n8nDispatchCardsProblem(['', '', ''], TEXTS), 'Fill at least 2');
    assert.strictEqual(Mautic.n8nDispatchCardsProblem([A], TEXTS), 'Fill at least 2');
    assert.strictEqual(Mautic.n8nDispatchCardsProblem([A, A], TEXTS), '');
});

test('the cards alert is silent for cards in order', () => {
    const {Mautic} = load();
    assert.strictEqual(Mautic.n8nDispatchCardsProblem([A, A, A, '', ''], TEXTS), '');
});

test('the cards alert names the hole: card 4 filled with card 3 empty', () => {
    const {Mautic} = load();
    assert.strictEqual(Mautic.n8nDispatchCardsProblem([A, A, '', A], TEXTS), 'Card 4 filled, Card 3 empty');
    assert.strictEqual(Mautic.n8nDispatchCardsProblem(['', A], TEXTS), 'Card 2 filled, Card 1 empty');
});

test('the cards alert names a card that is not an https URL', () => {
    const {Mautic} = load();
    assert.strictEqual(Mautic.n8nDispatchCardsProblem([A, 'nope'], TEXTS), 'Card 2 bad URL');
    assert.strictEqual(Mautic.n8nDispatchCardsProblem([A, 'javascript:1'], TEXTS), 'Card 2 bad URL');
    assert.strictEqual(Mautic.n8nDispatchCardsProblem([A, 'http://x.test/b.png'], TEXTS), 'Card 2 bad URL');
});

// A fuller stand-in: the carousel block holds the given inputs, and what lands in the alert spot is recorded.
function loadWithInputs(values, config) {
    const alertTexts = [];
    let cleared = 0;
    const mQuery = (arg) => {
        if (typeof arg === 'function' || typeof arg === 'object') {
            return {val: () => arg.value};
        }
        if (arg.startsWith('<div')) {
            return {text: (t) => ({alert: t})};
        }
        const chain = {
            toggle: () => chain, off: () => chain, on: () => chain,
            data: (key) => config[key],
            val: () => '',
            each: (fn) => { values.forEach((v) => fn.call({value: v})); return chain; },
            html: () => { cleared++; return chain; },
            append: (node) => { alertTexts.push(node.alert); return chain; },
        };

        return chain;
    };
    const Mautic = {n8ndispatchShared: {}};
    const code = fs.readFileSync(path.join(__dirname, '../../Assets/js/campaign-hsm-dispatch.js'), 'utf8');
    vm.runInNewContext(code, {window: {Mautic, mQuery, clearTimeout() {}, setTimeout() {}}, document: {body: {contains: () => true}}, Mautic, mQuery});

    return {Mautic, alertTexts, cleared: () => cleared};
}

const CONFIG = {'min-text': 'Fill at least 2', 'gap-text': 'Card %filled% filled, Card %blank% empty', 'url-text': 'Card %card% bad URL'};

test('opening the page after a refused save shows the hole in the alert', () => {
    // The form comes back with what was typed: card 4 filled, card 3 empty.
    const {Mautic, alertTexts} = loadWithInputs([A, A, '', A, '', '', '', '', '', ''], CONFIG);
    Mautic.n8nDispatchOnHsmTypeChange({value: 'carousel'});
    assert.deepStrictEqual(alertTexts, ['Card 4 filled, Card 3 empty']);
});

test('opening the page with a good carousel shows no alert, and clears the previous one', () => {
    const {Mautic, alertTexts, cleared} = loadWithInputs([A, A, A, '', '', '', '', '', '', ''], CONFIG);
    Mautic.n8nDispatchOnHsmTypeChange({value: 'carousel'});
    assert.deepStrictEqual(alertTexts, []);
    assert.strictEqual(cleared(), 1);
});

test('a new carousel opens with the minimum reminder', () => {
    const {Mautic, alertTexts} = loadWithInputs(['', '', '', '', '', '', '', '', '', ''], CONFIG);
    Mautic.n8nDispatchOnHsmTypeChange({value: 'carousel'});
    assert.deepStrictEqual(alertTexts, ['Fill at least 2']);
});
