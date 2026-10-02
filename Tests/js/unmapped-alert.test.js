// Run inside the Mautic container, no extra dependencies (Node's built-in runner):
//   docker exec mautic-mautic_web-1 sh -c "cd /var/www/html/docroot/plugins/N8nDispatchBundle && node --test 'Tests/js/*.test.js'"
'use strict';

const test   = require('node:test');
const assert = require('node:assert');
const fs     = require('node:fs');
const path   = require('node:path');
const vm     = require('node:vm');

// n8ndispatch-shared.js is a browser IIFE over (Mautic, mQuery). The helpers under test don't touch the DOM,
// so a stub mQuery is enough to load the file and read what it exposes on Mautic.n8ndispatchShared.
function loadShared() {
    const window = {Mautic: {}, mQuery: {extend: Object.assign}};
    const code   = fs.readFileSync(path.join(__dirname, '../../Assets/js/n8ndispatch-shared.js'), 'utf8');

    vm.runInNewContext(code, {window, Mautic: window.Mautic, mQuery: window.mQuery});

    return window.Mautic.n8ndispatchShared;
}

const shared = loadShared();

test('exposes the helpers', () => {
    assert.strictEqual(typeof shared.isEntrySet, 'function');
    assert.strictEqual(typeof shared.findUnmapped, 'function');
    assert.strictEqual(typeof shared.buildUnmappedAlertHtml, 'function');
});

test('a static value counts as set only when it is not blank', () => {
    assert.strictEqual(shared.isEntrySet({source: 'static', value: 'Maria'}), true);
    assert.strictEqual(shared.isEntrySet({source: 'static', value: ''}), false);
    assert.strictEqual(shared.isEntrySet({source: 'static', value: '   '}), false);
    assert.strictEqual(shared.isEntrySet({source: 'static'}), false);
});

test('a contact field counts as set when one is chosen', () => {
    assert.strictEqual(shared.isEntrySet({source: 'field', field: 'firstname'}), true);
    assert.strictEqual(shared.isEntrySet({source: 'field', field: ''}), false);
});

test('a custom object field needs both the object and the field', () => {
    assert.strictEqual(shared.isEntrySet({source: 'custom_object', customObject: 'courses', customObjectField: 'cursid'}), true);
    assert.strictEqual(shared.isEntrySet({source: 'custom_object', customObject: 'courses', customObjectField: ''}), false);
    assert.strictEqual(shared.isEntrySet({source: 'custom_object', customObject: '', customObjectField: 'cursid'}), false);
});

test('a missing, null or non-object entry is not set', () => {
    assert.strictEqual(shared.isEntrySet(undefined), false);
    assert.strictEqual(shared.isEntrySet(null), false);
    assert.strictEqual(shared.isEntrySet('text'), false);
});

test('an entry without a source is treated as static', () => {
    assert.strictEqual(shared.isEntrySet({value: 'x'}), true);
    assert.strictEqual(shared.isEntrySet({}), false);
});

test('findUnmapped returns the variables whose entry is not set, in template order', () => {
    const mapping = {
        n8n_nome:  {source: 'field', field: 'firstname'},
        n8n_curso: {source: 'static', value: ''},
        n8n_data:  {source: 'static', value: '2026-10-01'},
    };

    assert.deepStrictEqual(shared.findUnmapped(['n8n_curso', 'n8n_nome', 'n8n_data', 'n8n_novo'], mapping), ['n8n_curso', 'n8n_novo']);
});

test('findUnmapped is empty when everything is mapped or there are no variables', () => {
    assert.deepStrictEqual(shared.findUnmapped([], {}), []);
    assert.deepStrictEqual(shared.findUnmapped(['a'], {a: {source: 'static', value: 'x'}}), []);
});

test('findUnmapped tolerates a missing mapping', () => {
    assert.deepStrictEqual(shared.findUnmapped(['a', 'b'], null), ['a', 'b']);
});

test('the alert is generic: it does not list the variables and shows the tab name in bold', () => {
    const html = shared.buildUnmappedAlertHtml({text: 'There are unmapped variables. Go to %tab% to map them.', tab: 'Variables N8N'});

    assert.match(html, /class="alert alert-warning"/);
    assert.match(html, /There are unmapped variables\. Go to .*<b>Variables N8N<\/b>.* to map them\./);
    assert.doesNotMatch(html, /%tab%/);
    assert.doesNotMatch(html, /<code>/);
});

test('the tab name in the alert opens the tab', () => {
    const html = shared.buildUnmappedAlertHtml({text: 'Go to %tab%', tab: 'Variables N8N'});

    assert.match(html, /<a href="#n8ndispatch-email-tab-container" class="n8ndispatch-open-tab"><b>Variables N8N<\/b><\/a>/);
});

test('the alert follows the translated texts it receives', () => {
    const html = shared.buildUnmappedAlertHtml({text: 'Existem variáveis não mapeadas. Acesse %tab%.', tab: 'Variáveis N8N'});

    assert.match(html, /Existem variáveis não mapeadas\. Acesse .*<b>Variáveis N8N<\/b>/);
});

test('the alert escapes the translated texts', () => {
    const html = shared.buildUnmappedAlertHtml({text: '<i>"q"</i> %tab%', tab: '<u>t</u>'});

    assert.doesNotMatch(html, /<i>|<u>/);
    assert.match(html, /&lt;i&gt;&quot;q&quot;&lt;\/i&gt;/);
    assert.match(html, /<b>&lt;u&gt;t&lt;\/u&gt;<\/b>/);
});

test('the tab dot is a decorative element styled by n8ndispatch-tab-dot', () => {
    assert.strictEqual(typeof shared.buildTabDotHtml, 'function');

    const html = shared.buildTabDotHtml();

    assert.match(html, /^<span class="n8ndispatch-tab-dot"/);
    assert.match(html, /aria-hidden="true"/);
});

test('the prefix alert names the tab in bold, as a link that opens it', () => {
    assert.strictEqual(typeof shared.buildPrefixAlertHtml, 'function');

    const html = shared.buildPrefixAlertHtml({text: '%tab% must start with n8n_ (for example {{n8n_name}}).', tab: 'Variables N8N'});

    assert.match(html, /class="alert alert-warning" id="n8ndispatch-prefix-alert"/);
    assert.match(html, /<a href="#n8ndispatch-email-tab-container" class="n8ndispatch-open-tab"><b>Variables N8N<\/b><\/a> must start with n8n_ \(for example \{\{n8n_name\}\}\)\./);
    assert.doesNotMatch(html, /%tab%/);
});

test('the prefix alert escapes the translated texts', () => {
    const html = shared.buildPrefixAlertHtml({text: '<i>%tab%</i>', tab: '<u>t</u>'});

    assert.doesNotMatch(html, /<i>|<u>/);
    assert.match(html, /&lt;i&gt;/);
    assert.match(html, /<b>&lt;u&gt;t&lt;\/u&gt;<\/b>/);
});

test('which notice to show: the prefix one wins, then the unmapped one, otherwise none', () => {
    assert.strictEqual(typeof shared.noticeFor, 'function');

    assert.strictEqual(shared.noticeFor(2, 3), 'prefix');
    assert.strictEqual(shared.noticeFor(1, 0), 'prefix');
    assert.strictEqual(shared.noticeFor(0, 3), 'unmapped');
    assert.strictEqual(shared.noticeFor(0, 0), null);
});

test('the alerts about variables link to the Variables N8N tab', () => {
    const unmapped = shared.buildUnmappedAlertHtml({text: 'Go to %tab%', tab: 'Variables N8N'});
    const prefix   = shared.buildPrefixAlertHtml({text: 'Go to %tab%', tab: 'Variables N8N'});

    assert.match(unmapped, /<a href="#n8ndispatch-email-tab-container" class="n8ndispatch-open-tab">/);
    assert.match(prefix, /<a href="#n8ndispatch-email-tab-container" class="n8ndispatch-open-tab">/);
});

// The helper runs inside a separate vm context, so the arrays it creates have that context's Array prototype and
// deepStrictEqual rejects them against the test's own arrays; Array.from() copies them into this context first.
const missing = (values) => Array.from(shared.missingSenderFields(values));

test('missingSenderFields lists the blank sender fields, in form order', () => {
    assert.strictEqual(typeof shared.missingSenderFields, 'function');

    assert.deepStrictEqual(missing({fromName: '', fromAddress: 'a@b.c'}), ['fromName']);
    assert.deepStrictEqual(missing({fromName: 'Inst', fromAddress: '  '}), ['fromAddress']);
    assert.deepStrictEqual(missing({fromName: '', fromAddress: ''}), ['fromName', 'fromAddress']);
    assert.deepStrictEqual(missing({fromName: 'Inst', fromAddress: 'a@b.c'}), []);
});

test('missingSenderFields treats missing values as blank', () => {
    assert.deepStrictEqual(missing({}), ['fromName', 'fromAddress']);
    assert.deepStrictEqual(missing({fromName: null, fromAddress: undefined}), ['fromName', 'fromAddress']);
    assert.deepStrictEqual(missing(undefined), ['fromName', 'fromAddress']);
});

test('the sender alert lists the missing labels and links to the Advanced tab, in bold', () => {
    assert.strictEqual(typeof shared.buildSenderAlertHtml, 'function');

    const html = shared.buildSenderAlertHtml(
        {text: 'Fill in: %fields%. Go to the %tab% tab.', tab: 'Advanced'},
        ['Nome do Remetente', 'E-mail do Remetente']
    );

    assert.match(html, /class="alert alert-warning" id="n8ndispatch-sender-alert"/);
    assert.match(html, /Fill in: Nome do Remetente, E-mail do Remetente\./);
    assert.match(html, /<a href="#advanced-container" class="n8ndispatch-open-tab"><b>Advanced<\/b><\/a> tab\./);
    assert.doesNotMatch(html, /%fields%|%tab%/);
});

test('the sender alert escapes the labels and texts', () => {
    const html = shared.buildSenderAlertHtml({text: '<i>%fields%</i> %tab%', tab: '<u>t</u>'}, ['<b>x</b>']);

    assert.doesNotMatch(html, /<i>|<u>|<b>x<\/b>/);
    assert.match(html, /&lt;b&gt;x&lt;\/b&gt;/);
    assert.match(html, /<b>&lt;u&gt;t&lt;\/u&gt;<\/b>/);
});

test('buildPlainAlertHtml makes a yellow alert with the given id and the escaped text', () => {
    assert.strictEqual(typeof shared.buildPlainAlertHtml, 'function');

    const html = shared.buildPlainAlertHtml('n8ndispatch-hsm-numeric-alert', 'Variables are numeric, e.g. {{1}} <b>');

    assert.match(html, /^<div class="alert alert-warning" id="n8ndispatch-hsm-numeric-alert">/);
    assert.match(html, /Variables are numeric, e\.g\. \{\{1\}\} &lt;b&gt;<\/div>$/);
});

test('buildRowAlertHtml wraps the alert in the row/column markup form fields use, with a removable row id', () => {
    assert.strictEqual(typeof shared.buildRowAlertHtml, 'function');

    const html = shared.buildRowAlertHtml('n8ndispatch-hsm-numeric-alert', 'Numbers only, e.g. {{1}}');

    assert.match(html, /^<div class="row" id="n8ndispatch-hsm-numeric-alert-row"><div class="col-xs-12">/);
    assert.match(html, /<div class="alert alert-warning" id="n8ndispatch-hsm-numeric-alert">Numbers only, e\.g\. \{\{1\}\}<\/div>/);
    assert.match(html, /<\/div><\/div>$/);
});

// Same rule as TemplateVariableScanner::findNonNumeric() in PHP (tested there): every {{ ... }} must be digits only.
test('hasNonNumericPlaceholder accepts only digit placeholders', () => {
    assert.strictEqual(typeof shared.hasNonNumericPlaceholder, 'function');

    assert.strictEqual(shared.hasNonNumericPlaceholder('Hello {{1}}, class {{ 2 }} at {{03}}.'), false);
    assert.strictEqual(shared.hasNonNumericPlaceholder('Just a reminder.'), false);
    assert.strictEqual(shared.hasNonNumericPlaceholder('No placeholders, just { braces } and {single}.'), false);
});

test('hasNonNumericPlaceholder flags anything else between double braces', () => {
    for (const text of ['Hi {{valor}}', '{{1}} {{n8n_nome}}', '{{1a}}', '{{ nome completo }}', '{{}}', '{{1}} and {{curso}}']) {
        assert.strictEqual(shared.hasNonNumericPlaceholder(text), true, text);
    }
});

test('hasNonNumericPlaceholder treats a missing text as having none', () => {
    assert.strictEqual(shared.hasNonNumericPlaceholder(null), false);
    assert.strictEqual(shared.hasNonNumericPlaceholder(undefined), false);
    assert.strictEqual(shared.hasNonNumericPlaceholder(''), false);
});

// Texts that live in the browser (the variable source picker, the status badges) come from Mautic's own `mauticLang`
// object, filled from the plugin's javascript.ini in the system language; the English text is the fallback.
function loadSharedWithLang(mauticLang) {
    const window = {Mautic: {}, mQuery: {extend: Object.assign}, mauticLang};
    const code   = fs.readFileSync(path.join(__dirname, '../../Assets/js/n8ndispatch-shared.js'), 'utf8');

    vm.runInNewContext(code, {window, Mautic: window.Mautic, mQuery: window.mQuery});

    return window.Mautic.n8ndispatchShared;
}

test('translate returns the text Mautic put in mauticLang for the system language', () => {
    const translated = loadSharedWithLang({'mautic.n8ndispatch.js.select_field': 'Selecione um campo'});

    assert.strictEqual(translated.translate('mautic.n8ndispatch.js.select_field', 'Select a field'), 'Selecione um campo');
});

test('translate falls back to the English text when mauticLang has no such key or does not exist', () => {
    assert.strictEqual(loadSharedWithLang({}).translate('mautic.n8ndispatch.js.select_field', 'Select a field'), 'Select a field');
    assert.strictEqual(loadSharedWithLang(undefined).translate('mautic.n8ndispatch.js.select_field', 'Select a field'), 'Select a field');
    assert.strictEqual(loadSharedWithLang({'mautic.n8ndispatch.js.select_field': ''}).translate('mautic.n8ndispatch.js.select_field', 'Select a field'), 'Select a field');
});

test('unmapped placeholders of an HSM/SMS text: same rule as the save check', () => {
    const filled = JSON.stringify({1: {source: 'static', value: 'a'}, 2: {source: 'field', field: 'firstname'}});
    const blank  = JSON.stringify({1: {source: 'static', value: ''}});

    assert.strictEqual(shared.hasUnmappedPlaceholder('Hi {{1}} {{ 2 }}', filled), false);
    assert.strictEqual(shared.hasUnmappedPlaceholder('Hi {{1}}', blank), true);
    assert.strictEqual(shared.hasUnmappedPlaceholder('Hi {{1}}', '{}'), true);
    assert.strictEqual(shared.hasUnmappedPlaceholder('Hi {{1}} {{3}}', filled), true);
    assert.strictEqual(shared.hasUnmappedPlaceholder('Hi {{1}}', 'not json'), true);
    assert.strictEqual(shared.hasUnmappedPlaceholder('Hi {{1}}', ''), true);
});

test('no placeholders, or a non-numeric one (the numeric alert is the one to show), means no unmapped alert', () => {
    assert.strictEqual(shared.hasUnmappedPlaceholder('Just text', '{}'), false);
    assert.strictEqual(shared.hasUnmappedPlaceholder('', '{}'), false);
    assert.strictEqual(shared.hasUnmappedPlaceholder('{{1}} {{valor}}', '{}'), false);
});
