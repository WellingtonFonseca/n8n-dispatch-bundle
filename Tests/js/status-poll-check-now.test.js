// Run inside the Mautic container, no extra dependencies (Node's built-in runner):
//   docker exec mautic-mautic_web-1 sh -c "cd /var/www/html/docroot/plugins/N8nDispatchBundle && node --test 'Tests/js/*.test.js'"
'use strict';

const test   = require('node:test');
const assert = require('node:assert');
const fs     = require('node:fs');
const path   = require('node:path');
const vm     = require('node:vm');

// status-poll-check-now.js is a browser script over (Mautic, mQuery, window). The helpers under test only build
// text, so stubs are enough to load it and read what it exposes on Mautic.n8ndispatchStatusPoll.
function load(lang) {
    const window = {Mautic: {}, mauticLang: lang || {}, mQuery: {}};
    const code   = fs.readFileSync(path.join(__dirname, '../../Assets/js/status-poll-check-now.js'), 'utf8');

    vm.runInNewContext(code, {window, Mautic: window.Mautic, mQuery: window.mQuery});

    return window.Mautic.n8ndispatchStatusPoll;
}

const summary = (over) => Object.assign({requested: 0, changed: 0, unchanged: 0, unknown: 0, invalid: 0, error: null}, over || {});

test('exposes the helper and the button handler', () => {
    const poll = load();

    assert.strictEqual(typeof poll.buildResultLines, 'function');
});

test('one line per channel with the numbers', () => {
    const lines = load().buildResultLines({success: 1, ok: true, channels: {
        email: summary({requested: 3, changed: 1, unchanged: 2}),
        sms:   summary({requested: 1, unchanged: 1}),
    }});

    assert.strictEqual(lines.length, 2);
    assert.strictEqual(lines[0].level, 'ok');
    assert.match(lines[0].text, /^EMAIL: .*asked 3.*changed 1.*unchanged 2/);
    assert.match(lines[1].text, /^SMS: /);
});

test('an error channel shows its message and the error level', () => {
    const lines = load().buildResultLines({success: 1, ok: false, channels: {
        hsm: summary({error: 'webhook returned HTTP 500.'}),
    }});

    assert.strictEqual(lines[0].level, 'error');
    assert.strictEqual(lines[0].text, 'HSM: webhook returned HTTP 500.');
});

test('nothing pending gets one explanatory line after the channels', () => {
    const lines = load().buildResultLines({success: 1, ok: true, channels: {
        email: summary(), sms: summary(), hsm: summary(),
    }});

    assert.strictEqual(lines.length, 4);
    assert.strictEqual(lines[3].level, 'info');
    assert.match(lines[3].text, /Nothing pending/);
});

test('no explanatory line when something was asked', () => {
    const lines = load().buildResultLines({success: 1, ok: true, channels: {
        email: summary({requested: 1, unchanged: 1}), sms: summary(), hsm: summary(),
    }});

    assert.strictEqual(lines.length, 3);
});

test('no explanatory line when a channel failed, the failure is the news', () => {
    const lines = load().buildResultLines({success: 1, ok: false, channels: {
        email: summary({error: 'boom'}), sms: summary(), hsm: summary(),
    }});

    assert.strictEqual(lines.length, 3);
});

test('a refused request says so', () => {
    const lines = load().buildResultLines({success: 0});

    assert.strictEqual(lines.length, 1);
    assert.strictEqual(lines[0].level, 'error');
    assert.match(lines[0].text, /permission|could not/i);
});

test('the texts come from the system language when it has them', () => {
    const lines = load({
        'mautic.n8ndispatch.js.check_now.line': '%channel%: perguntou %requested%, mudaram %changed%, sem mudança %unchanged%, desconhecidos %unknown%, inválidos %invalid%',
    }).buildResultLines({success: 1, ok: true, channels: {email: summary({requested: 2, changed: 1, unchanged: 1})}});

    assert.strictEqual(lines[0].text, 'EMAIL: perguntou 2, mudaram 1, sem mudança 1, desconhecidos 0, inválidos 0');
});
