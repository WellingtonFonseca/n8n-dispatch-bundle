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
            return {toggle: (visible) => shown.push({selector: arg, visible}), data: () => '', val: () => ''};
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
