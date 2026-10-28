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

/** A fake badge: its children are what paintBadge builds. */
function badge(state) {
    const doc = {createElement: (name) => ({name, className: '', attrs: {}, children: [], textContent: '', setAttribute(k, v) { this.attrs[k] = v; }, appendChild(c) { this.children.push(c); }})};
    const b = {
        ownerDocument: doc, className: 'n8ndispatch-badge n8ndispatch-template-status n8ndispatch-badge--production', attrs: {'data-state': state, 'data-text-published': 'Ativo', 'data-text-unpublished': 'Inativo'},
        children: [], _text: '',
        get textContent() { return this._text; },
        set textContent(v) { this._text = v; this.children = []; },
        getAttribute(k) { return this.attrs[k] === undefined ? null : this.attrs[k]; },
        setAttribute(k, v) { this.attrs[k] = v; },
        appendChild(c) { this.children.push(c); },
    };

    return b;
}

const parts = (b) => b.children.map((c) => [c.name, c.className, c.textContent]);

test('paintBadge: an inactive template is a red stop icon plus the word, and the word is the title', () => {
    const b = badge('published');

    api.paintBadge(b, 'unpublished');

    assert.strictEqual(b.className, 'n8ndispatch-badge n8ndispatch-template-status n8ndispatch-badge--failed');
    assert.deepStrictEqual(parts(b), [['i', 'ri-stop-fill', ''], ['span', 'n8ndispatch-template-status__text', 'Inativo']]);
    assert.strictEqual(b.children[0].attrs['aria-hidden'], 'true');
    assert.strictEqual(b.attrs.title, 'Inativo');
    assert.strictEqual(b.attrs['aria-label'], 'Inativo');
    assert.strictEqual(b.attrs['data-state'], 'unpublished');
});

test('paintBadge: an active template is a green play icon plus the word', () => {
    const b = badge('unpublished');

    api.paintBadge(b, 'published');

    assert.strictEqual(b.className, 'n8ndispatch-badge n8ndispatch-template-status n8ndispatch-badge--production');
    assert.deepStrictEqual(parts(b), [['i', 'ri-play-fill', ''], ['span', 'n8ndispatch-template-status__text', 'Ativo']]);
    assert.strictEqual(b.attrs.title, 'Ativo');
});

test('paintBadge: while core works the badge is a spinner only, orange', () => {
    const b = badge('published');

    api.paintBadge(b, 'busy');

    assert.strictEqual(b.className, 'n8ndispatch-badge n8ndispatch-template-status n8ndispatch-badge--pending');
    assert.deepStrictEqual(parts(b), [['i', 'ri-loader-3-line ri-spin', '']]);
    assert.strictEqual(b.attrs['data-state'], 'busy');
});

test('paintBadge: a badge that already shows the state is not touched', () => {
    const b = badge('published');
    b.children = ['untouched'];

    api.paintBadge(b, 'published');

    assert.deepStrictEqual(b.children, ['untouched']);
});

test('growth: a badge grows to its content, up to 140px, minus the 22px it has', () => {
    assert.strictEqual(api.growth({scrollWidth: 60, offsetWidth: 22}), 38);
    assert.strictEqual(api.growth({scrollWidth: 400, offsetWidth: 22}), 118);
    assert.strictEqual(api.growth({scrollWidth: 22, offsetWidth: 22}), 0);
});

test('overflow: the columns are pushed only by what the open badge does not fit in the cell', () => {
    assert.strictEqual(api.overflow(22, 118, 90), 50);
    assert.strictEqual(api.overflow(22, 68, 90), 0, 'it fits exactly');
    assert.strictEqual(api.overflow(22, 38, 90), 0, 'it fits with room to spare');
});

test('pushPlan: the opening badge widens its own column by the extra, every other column keeps its width', () => {
    const plan = api.pushPlan([40, 90, 60, 300], 1, 50);

    assert.deepStrictEqual(plan.widths, [40, 140, 60, 300]);
    assert.strictEqual(plan.total, 540);
    assert.deepStrictEqual(api.pushPlan([10, 20], 0, 0), {widths: [10, 20], total: 30}, 'nothing extra: widths fixed as they are');
    assert.deepStrictEqual(api.pushPlan([10, 20], 0, -5), {widths: [10, 20], total: 30});
});
