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

    assert.match(html, /<a href="#n8ndispatch-email-tab-container" class="n8ndispatch-open-variables-tab"><b>Variables N8N<\/b><\/a>/);
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
