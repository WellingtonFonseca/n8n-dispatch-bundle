// Runs the page script (Assets/js/template-modal-middle-click.js) against a stand-in for the browser: a middle click on the
// details link of an SMS / HSM template opens the modal and cancels the new tab; nothing else is touched.
const test = require('node:test');
const assert = require('node:assert');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

function load() {
    const opened = [];
    let handler = null;
    let capture = null;
    const window = {Mautic: {ajaxifyModal: (el, event) => opened.push({el, event})}};
    const document = {addEventListener: (type, fn, cap) => { if (type === 'auxclick') { handler = fn; capture = cap; } }};
    const code = fs.readFileSync(path.join(__dirname, '../../Assets/js/template-modal-middle-click.js'), 'utf8');

    vm.runInNewContext(code, {window, document});

    const link = (href, toggle = 'ajaxmodal') => {
        const a = {getAttribute: (n) => (n === 'href' ? href : null), toggle};
        a.closest = (sel) => (sel === 'a[data-toggle="ajaxmodal"]' && toggle === 'ajaxmodal' ? a : null);

        return a;
    };
    const press = (target, button = 1) => {
        const event = {button, target, prevented: false, stopped: false, preventDefault() { this.prevented = true; }, stopImmediatePropagation() { this.stopped = true; }};
        handler(event);

        return event;
    };

    return {opened, press, link, capture: () => capture, window, code};
}

test('a middle click on the SMS template name opens the modal and cancels the new tab', () => {
    const t = load();
    const a = t.link('/s/n8ndispatch/sms-templates/details/5');

    const event = t.press(a);

    assert.strictEqual(t.capture(), true);
    assert.strictEqual(event.prevented, true);
    assert.strictEqual(event.stopped, true);
    assert.strictEqual(t.opened.length, 1);
    assert.strictEqual(t.opened[0].el, a);
});

test('the same for an HSM template, and from inside the link (a child element)', () => {
    const t = load();
    const a = t.link('/s/n8ndispatch/hsm-templates/details/12?x=1');
    const child = {closest: (sel) => a.closest(sel)};

    const event = t.press(child);

    assert.strictEqual(event.prevented, true);
    assert.strictEqual(t.opened.length, 1);
});

test('other buttons are left alone (right = 2, and a normal click does not come here)', () => {
    const t = load();

    const event = t.press(t.link('/s/n8ndispatch/sms-templates/details/5'), 2);

    assert.strictEqual(event.prevented, false);
    assert.strictEqual(t.opened.length, 0);
});

test('other links are left alone: another address, or not an ajax modal link', () => {
    const t = load();

    assert.strictEqual(t.press(t.link('/s/n8ndispatch/sms-templates/edit/5')).prevented, false);
    assert.strictEqual(t.press(t.link('/s/n8ndispatch/dispatches/details/5')).prevented, false);
    assert.strictEqual(t.press(t.link('/s/n8ndispatch/sms-templates/details/5', 'ajax')).prevented, false);
    assert.strictEqual(t.press({}).prevented, false, 'a target without closest()');
    assert.strictEqual(t.opened.length, 0);
});

test('loaded twice, it installs the handler once', () => {
    const t = load();
    let installs = 0;
    const document = {addEventListener: () => { installs += 1; }};

    vm.runInNewContext(t.code, {window: t.window, document});
    vm.runInNewContext(t.code, {window: t.window, document});

    assert.strictEqual(installs, 0, 'the flag is on the window of the first run, shared here');
});
