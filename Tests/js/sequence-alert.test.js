// Test-tags: areas=alerts risks=FLUXO
// Run inside the Mautic container, no extra dependencies (Node's built-in runner):
//   docker exec mautic-mautic_web-1 sh -c "cd /var/www/html/docroot/plugins/N8nDispatchBundle && node --test 'Tests/js/*.test.js'"
// The sequence rule of the HSM and SMS text, as the page script decides it while the text is typed. It follows
// SequentialVariables in PHP: numbers 1..n with no hole, {{0}} refused, steps aside for a non-numeric placeholder.
'use strict';

const test   = require('node:test');
const assert = require('node:assert');
const fs     = require('node:fs');
const path   = require('node:path');
const vm     = require('node:vm');

function loadShared(mQuery) {
    const window = {Mautic: {}, mQuery: Object.assign(mQuery || (() => ({})), {extend: Object.assign})};
    const code   = fs.readFileSync(path.join(__dirname, '../../Assets/js/n8ndispatch-shared.js'), 'utf8');

    vm.runInNewContext(code, {window, Mautic: window.Mautic, mQuery: window.mQuery});

    return window.Mautic.n8ndispatchShared;
}

const shared = loadShared();

// The object comes from another vm context: its prototype differs, so compare plain copies.
const problemOf = (text) => JSON.parse(JSON.stringify(shared.sequenceProblem(text)));
const TEXTS  = {gap: 'Missing {{%missing%}}, highest {{%highest%}}', zero: 'Start at {{1}}', limit: 'At most %max% variables'};

test('a complete sequence has no problem, in any order and with repeats', () => {
    assert.strictEqual(problemOf('Hi {{1}} and {{2}}'), null);
    assert.strictEqual(problemOf('{{2}} then {{1}} then {{2}}'), null);
    assert.strictEqual(problemOf('{{ 1 }} {{ 2 }}'), null);
});

test('a text without variables has no problem', () => {
    assert.strictEqual(problemOf(''), null);
    assert.strictEqual(problemOf(null), null);
    assert.strictEqual(problemOf('no variables here'), null);
});

test('a skipped number names the first missing one and the highest', () => {
    assert.deepStrictEqual(problemOf('{{1}} {{3}}'), {kind: 'gap', missing: 2, highest: 3});
    assert.deepStrictEqual(problemOf('{{1}} {{2}} {{5}} {{6}}'), {kind: 'gap', missing: 3, highest: 6});
    assert.deepStrictEqual(problemOf('{{2}}'), {kind: 'gap', missing: 1, highest: 2});
    assert.deepStrictEqual(problemOf('{{ 1 }} {{ 3 }}'), {kind: 'gap', missing: 2, highest: 3});
});

test('zero is refused and wins over a gap', () => {
    assert.deepStrictEqual(problemOf('{{0}}'), {kind: 'zero'});
    assert.deepStrictEqual(problemOf('{{0}} {{1}}'), {kind: 'zero'});
    assert.deepStrictEqual(problemOf('{{0}} {{2}}'), {kind: 'zero'});
});

test('it steps aside while a placeholder is not a number', () => {
    assert.strictEqual(problemOf('{{1}} {{3}} {{name}}'), null);
    assert.strictEqual(problemOf('{{0}} {{}}'), null);
});

test('fifty is the limit, and it wins over a gap; a huge number answers at once', () => {
    const fifty = Array.from({length: 50}, (_, i) => '{{' + (i + 1) + '}}').join(' ');

    assert.strictEqual(problemOf(fifty), null);
    assert.deepStrictEqual(problemOf(fifty + ' {{51}}'), {kind: 'limit', max: 50});
    assert.deepStrictEqual(problemOf('{{1}} {{51}}'), {kind: 'limit', max: 50});
    assert.deepStrictEqual(problemOf('{{1}} {{99999999999}}'), {kind: 'limit', max: 50});
    assert.deepStrictEqual(problemOf('{{1}} {{50}}'), {kind: 'gap', missing: 2, highest: 50});
});

test('the alert text fills %missing% and %highest%, or says the zero message', () => {
    assert.strictEqual(shared.sequenceAlertText({kind: 'gap', missing: 2, highest: 3}, TEXTS), 'Missing {{2}}, highest {{3}}');
    assert.strictEqual(shared.sequenceAlertText({kind: 'zero'}, TEXTS), 'Start at {{1}}');
    assert.strictEqual(shared.sequenceAlertText({kind: 'limit', max: 50}, TEXTS), 'At most 50 variables');
    assert.strictEqual(shared.sequenceAlertText(null, TEXTS), '');
});

test('the alert goes after the text row while there is a problem and is removed when there is none', () => {
    const calls = [];
    const row   = {after: (html) => calls.push(['after', html])};
    const field  = {closest: () => row};
    const mQuery = (arg) => ({remove: () => calls.push(['remove', arg])});
    const s = loadShared(mQuery);

    s.setSequenceAlert(field, 'seq', TEXTS, '{{1}} {{3}}');
    assert.deepStrictEqual(calls[0], ['remove', '#seq-row']);
    assert.strictEqual(calls[1][0], 'after');
    assert.match(calls[1][1], /Missing \{\{2\}\}, highest \{\{3\}\}/);

    calls.length = 0;
    s.setSequenceAlert(field, 'seq', TEXTS, '{{1}} {{2}}');
    assert.deepStrictEqual(calls, [['remove', '#seq-row']]);
});
