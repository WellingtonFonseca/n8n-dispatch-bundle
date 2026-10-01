// The numeric-variables alert of the HSM and SMS forms is decided from the text field itself, right away, not from
// the answer to the (debounced) request that scans the variables. So it is there as soon as the page comes back from
// a blocked save, and a late answer can't take it away. Work started for a field that already left the page (the
// form was re-rendered meanwhile) is dropped: it would remove the new form's alert (same id) and put a new one into
// the old, detached DOM.
//
// Run inside the Mautic container, no extra dependencies:
//   docker exec mautic-mautic_web-1 sh -c "cd /var/www/html/docroot/plugins/N8nDispatchBundle && node --test 'Tests/js/*.test.js'"
'use strict';

const test   = require('node:test');
const assert = require('node:assert');
const fs     = require('node:fs');
const path   = require('node:path');
const vm     = require('node:vm');

const sharedCode = fs.readFileSync(path.join(__dirname, '../../Assets/js/n8ndispatch-shared.js'), 'utf8');

// Loads one of the template scripts with just enough fake browser around it to drive its callbacks. The real shared
// script is loaded for the pure rule (hasNonNumericPlaceholder); everything that touches the page is a recorder.
function load(file, {text, attached}) {
    const calls = {render: 0, ajax: 0, alerts: []};
    let   ajaxCallback;

    const realShared = (() => {
        const w = {Mautic: {}, mQuery: {extend: Object.assign}};
        vm.runInNewContext(sharedCode, {window: w, Mautic: w.Mautic, mQuery: w.mQuery});

        return w.Mautic.n8ndispatchShared;
    })();

    const shared = {
        clearVariables:              () => {},
        renderVariablesFromResponse: () => { calls.render++; },
        setNumericAlert:             (field, id, alertText, show) => { calls.alerts.push({id, alertText, show}); },
        hasNonNumericPlaceholder:    realShared.hasNonNumericPlaceholder,
    };
    const Mautic = {
        n8ndispatchShared: shared,
        ajaxActionRequest: (action, data, callback) => { calls.ajax++; ajaxCallback = callback; },
    };
    const mQuery   = (arg) => ({0: arg, val: () => text.value, data: () => 'alert text'});
    const window   = {Mautic, mQuery, setTimeout: (fn) => { fn(); return 1; }, clearTimeout: () => {}};
    const document = {body: {contains: () => attached.value}};

    vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../../Assets/js', file), 'utf8'), {window, Mautic, mQuery, document});

    return {Mautic, calls, answer: (response) => ajaxCallback(response)};
}

const RESPONSE = {success: 1, variables: [], nonNumericVariables: ['valor']};

for (const [file, init, onChange] of [
    ['campaign-hsm-dispatch.js', 'n8nDispatchInitHsmVariables', 'n8nDispatchOnHsmTextChange'],
    ['campaign-sms-dispatch.js', 'n8nDispatchInitSmsVariables', 'n8nDispatchOnSmsTextChange'],
]) {
    test(`${file}: the alert is there right away when the page loads with a wrong placeholder, before any answer`, () => {
        const page = load(file, {text: {value: 'Hi {{valor}}'}, attached: {value: true}});

        page.Mautic[init]({});

        assert.strictEqual(page.calls.alerts.length, 1);
        assert.strictEqual(page.calls.alerts[0].show, true);
        assert.strictEqual(page.calls.alerts[0].alertText, 'alert text');
    });

    test(`${file}: no alert for numeric placeholders or an empty text`, () => {
        const numeric = load(file, {text: {value: 'Hi {{1}}, {{2}}'}, attached: {value: true}});
        numeric.Mautic[init]({});
        assert.deepStrictEqual(numeric.calls.alerts.map((a) => a.show), [false]);

        const empty = load(file, {text: {value: ''}, attached: {value: true}});
        empty.Mautic[init]({});
        assert.deepStrictEqual(empty.calls.alerts.map((a) => a.show), [false]);
    });

    test(`${file}: the answer renders the rows and puts the alert back above them`, () => {
        const page = load(file, {text: {value: 'Hi {{valor}}'}, attached: {value: true}});

        page.Mautic[init]({});
        page.answer(RESPONSE);

        assert.strictEqual(page.calls.render, 1);
        // Once on load and once after the rows were rendered: inserting the rows right after the text field would
        // otherwise leave them above the alert.
        assert.deepStrictEqual(page.calls.alerts.map((a) => a.show), [true, true]);
    });

    test(`${file}: the alert follows the text, not the answer`, () => {
        const text = {value: 'Hi {{valor}}'};
        const page = load(file, {text, attached: {value: true}});

        page.Mautic[init]({});
        text.value = 'Hi {{1}}'; // fixed while the request was in flight
        page.answer(RESPONSE);

        assert.strictEqual(page.calls.alerts[page.calls.alerts.length - 1].show, false);
    });

    test(`${file}: an answer for a field that left the page is ignored`, () => {
        const attached = {value: true};
        const page     = load(file, {text: {value: 'Hi {{valor}}'}, attached});

        page.Mautic[init]({});
        const alertsBefore = page.calls.alerts.length;
        attached.value = false; // the form was re-rendered while the request was in flight
        page.answer(RESPONSE);

        assert.strictEqual(page.calls.render, 0);
        assert.strictEqual(page.calls.alerts.length, alertsBefore);
    });

    test(`${file}: a debounced scan that fires after the field left the page does nothing`, () => {
        const page = load(file, {text: {value: 'Hi {{valor}}'}, attached: {value: false}});

        page.Mautic[onChange]({});

        assert.strictEqual(page.calls.ajax, 0);
        assert.strictEqual(page.calls.alerts.length, 0);
    });
}
