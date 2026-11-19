// Test-tags: areas=plugin-settings risks=UI,FLUXO
// What Assets/js/plugin-about-tab.js does: the callback core calls with the block, and the order of the steps that make "Sobre" the
// first and the only active tab. The modal itself is checked in the browser (scripts/5/e2e/tests/plugin-changelog.spec.js).
//
//   docker exec mautic-mautic_web-1 sh -c "cd /var/www/html/docroot/plugins/N8nDispatchBundle && node --test 'Tests/js/*.test.js'"
'use strict';

const test   = require('node:test');
const assert = require('node:assert');
const api    = require('../../Assets/js/plugin-about-tab.js');
const {page, empty} = require('./fake-page.js');

test('install: the callback of the template is on Mautic', () => {
    const Mautic = {};
    api.install(empty, Mautic);

    assert.strictEqual(typeof Mautic.n8ndispatchAboutTab, 'function');
});

test('install: without jQuery or Mautic nothing is touched', () => {
    const Mautic = {};
    api.install(null, Mautic);
    assert.deepStrictEqual(Mautic, {}, 'a callback without jQuery would throw when core calls it');
    assert.doesNotThrow(() => api.install(() => ({}), null));
});

test('moveToTab: a block outside a tab content is left alone', () => {
    assert.strictEqual(api.moveToTab(empty, {}), false);
});

test('moveToTab: the tab is put first and is the only active one', () => {
    const {$, block, log} = page(false);

    assert.strictEqual(api.moveToTab($, block), true);
    assert.deepStrictEqual(log, [
        'tabs: remove active',
        'panes: remove in active',
        'li prepended to tabs: <li id="n8ndispatch-about-tab" class="active"></li>',
        'pane prepended to content: <div class="tab-pane fade in active bdr-w-0"></div>',
    ], 'the old tab is deactivated BEFORE the new one is added, or it would lose its own class');
});

test('moveToTab: a tab content with an error from a failed save keeps its own tab active and "Sobre" is only added', () => {
    const {$, block, log} = page(false, false, true);

    assert.strictEqual(api.moveToTab($, block), true);
    assert.deepStrictEqual(log, [
        'li prepended to tabs: <li id="n8ndispatch-about-tab"></li>',
        'pane prepended to content: <div class="tab-pane fade bdr-w-0"></div>',
    ], 'nothing is deactivated: the error stays in view');
});

test('moveToTab: a second call does not add the tab again', () => {
    const {$, block, log} = page(true);

    assert.strictEqual(api.moveToTab($, block), false);
    assert.deepStrictEqual(log, []);
});

test('moveToTab: a tab content with no tab list before its form is left alone', () => {
    const {$, block, log} = page(false, true);

    assert.strictEqual(api.moveToTab($, block), false);
    assert.deepStrictEqual(log, []);
});

test('install: the callback core calls moves the block into the tab', () => {
    const {$, block, log} = page(false);
    const Mautic = {};
    api.install($, Mautic);

    Mautic.n8ndispatchAboutTab(block);

    assert.deepStrictEqual(log, [
        'tabs: remove active',
        'panes: remove in active',
        'li prepended to tabs: <li id="n8ndispatch-about-tab" class="active"></li>',
        'pane prepended to content: <div class="tab-pane fade in active bdr-w-0"></div>',
    ]);
});

test('a script tag install: the file defines the callback on window.Mautic when it is loaded', () => {
    const path = require.resolve('../../Assets/js/plugin-about-tab.js');
    const window = {Mautic: {}, mQuery: empty};
    const vm = require('node:vm');
    vm.runInNewContext(require('node:fs').readFileSync(path, 'utf8'), {window}, {filename: path});
    assert.strictEqual(typeof window.Mautic.n8ndispatchAboutTab, 'function');
    assert.strictEqual(typeof window.n8ndispatchAboutTabApi.install, 'function');
});
