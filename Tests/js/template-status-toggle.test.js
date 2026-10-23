// The state logic of Assets/js/template-status-toggle.js: what core's icon says about a template. The DOM part is checked in the browser
// (scripts/5/e2e/tests/hsm-status.spec.js and sms-status.spec.js).
const test = require('node:test');
const assert = require('node:assert');
const api = require('../../Assets/js/template-status-toggle.js');

const icon = (className, status) => ({className, getAttribute: (n) => (n === 'data-status' ? status : null)});

test('stateOf: published and unpublished follow the data-status of the icon', () => {
    assert.strictEqual(api.stateOf(icon('ri-toggle-fill', 'published')), 'published');
    assert.strictEqual(api.stateOf(icon('ri-toggle-line', 'unpublished')), 'unpublished');
});

test('stateOf: any other status counts as published, like the isPublished flag', () => {
    assert.strictEqual(api.stateOf(icon('ri-toggle-fill', 'expired')), 'published');
    assert.strictEqual(api.stateOf(icon('ri-toggle-fill', null)), 'published');
});

test('stateOf: busy while core spins the icon', () => {
    assert.strictEqual(api.stateOf(icon('ri-toggle-fill ri-spin ri-loader-3-line', 'published')), 'busy');
});
