// Run inside the Mautic container, no extra dependencies (Node's built-in runner):
//   docker exec mautic-mautic_web-1 sh -c "cd /var/www/html/docroot/plugins/N8nDispatchBundle && node --test 'Tests/js/*.test.js'"
'use strict';

const test   = require('node:test');
const assert = require('node:assert');
const fs     = require('node:fs');
const path   = require('node:path');
const vm     = require('node:vm');

// status-poll-check-now.js is a browser script over (Mautic, mQuery, window). The helper under test only builds
// text, so stubs are enough to load it and read what it exposes on Mautic.n8ndispatchStatusPoll.
function load(lang) {
    const window = {Mautic: {}, mauticLang: lang || {}, mQuery: {}};
    const code   = fs.readFileSync(path.join(__dirname, '../../Assets/js/status-poll-check-now.js'), 'utf8');

    vm.runInNewContext(code, {window, Mautic: window.Mautic, mQuery: window.mQuery});

    return window.Mautic.n8ndispatchStatusPoll;
}

const summary = (over) => Object.assign({requested: 0, changed: 0, unchanged: 0, unknown: 0, invalid: 0, error: null}, over || {});

test('exposes the helper', () => {
    assert.strictEqual(typeof load().buildResult, 'function');
});

test('the json has one object per channel with the numbers', () => {
    const result = load().buildResult({success: 1, ok: true, channels: {
        email: summary({requested: 3, changed: 1, unchanged: 2}),
        sms:   summary({requested: 1, unchanged: 1}),
    }});

    assert.deepStrictEqual(JSON.parse(result.json), {
        email: {asked: 3, changed: 1, unchanged: 2, unknown: 0, invalid: 0},
        sms:   {asked: 1, changed: 0, unchanged: 1, unknown: 0, invalid: 0},
    });
    assert.match(result.json, /\n    "email": \{/, 'written out, one key per line');
    assert.strictEqual(result.notes.length, 0);
});

test('an error channel shows only its error', () => {
    const result = load().buildResult({success: 1, ok: false, channels: {
        hsm: summary({error: 'webhook returned HTTP 500.'}),
    }});

    assert.deepStrictEqual(JSON.parse(result.json), {hsm: {error: 'webhook returned HTTP 500.'}});
});

test('nothing pending gets one explanatory note under the json', () => {
    const result = load().buildResult({success: 1, ok: true, channels: {
        email: summary(), sms: summary(), hsm: summary(),
    }});

    assert.deepStrictEqual(Object.keys(JSON.parse(result.json)), ['email', 'sms', 'hsm']);
    assert.strictEqual(result.notes.length, 1);
    assert.strictEqual(result.notes[0].level, 'info');
    assert.match(result.notes[0].text, /Nothing pending/);
});

test('no note when something was asked', () => {
    const result = load().buildResult({success: 1, ok: true, channels: {
        email: summary({requested: 1, unchanged: 1}), sms: summary(), hsm: summary(),
    }});

    assert.strictEqual(result.notes.length, 0);
});

test('no note when a channel failed, the failure is the news', () => {
    const result = load().buildResult({success: 1, ok: false, channels: {
        email: summary({error: 'boom'}), sms: summary(), hsm: summary(),
    }});

    assert.strictEqual(result.notes.length, 0);
});

test('a refused request has no json, only the error note', () => {
    const result = load().buildResult({success: 0});

    assert.strictEqual(result.json, null);
    assert.strictEqual(result.notes.length, 1);
    assert.strictEqual(result.notes[0].level, 'error');
    assert.match(result.notes[0].text, /permission|could not/i);
});

test('the note comes in the system language when it has it', () => {
    const result = load({
        'mautic.n8ndispatch.js.check_now.nothing': 'Nenhum disparo pendente.',
    }).buildResult({success: 1, ok: true, channels: {email: summary()}});

    assert.strictEqual(result.notes[0].text, 'Nenhum disparo pendente.');
});
