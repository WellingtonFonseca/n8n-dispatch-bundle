// Status of the SMS / HSM template lists: an "Ativo" / "Inativo" badge (the n8n badge) at the start of the name, and "Desativar" /
// "Ativar" in the row menu. Both follow core's toggle icon of the row (hidden by n8ndispatch.css): the menu item clicks it,
// so core sends the request and checks the permission, and the badge and the item are brought to the icon's state, spinning while
// core works. The texts come from data attributes the list template prints (translated there).
(function (root) {
    'use strict';

    var ROWS = '#hsmTemplateTable tbody tr, #smsTemplateTable tbody tr';
    var BADGE = '.n8ndispatch-template-status';
    var ITEM = 'a[data-n8n-status-action]';

    function iconOf(row) { return row.querySelector('i[class*="-publish-icon"]'); }

    /** 'busy' while core's request runs, else the data-status of core's icon ('unpublished' or, for anything else, 'published'). */
    function stateOf(icon) {
        if (/\bri-spin\b/.test(icon.className)) { return 'busy'; }

        return icon.getAttribute('data-status') === 'unpublished' ? 'unpublished' : 'published';
    }

    function paintBadge(badge, state) {
        if (badge.getAttribute('data-state') === state) { return; }
        var modifier = state === 'busy' ? 'pending' : (state === 'unpublished' ? 'failed' : 'production');
        badge.className = 'n8ndispatch-badge n8ndispatch-template-status n8ndispatch-badge--' + modifier;
        // the word and the color tell the status; only the wait has an icon
        badge.textContent = state === 'busy' ? '' : badge.getAttribute('data-text-' + state);
        if (state === 'busy') { badge.insertAdjacentHTML('afterbegin', '<i class="ri-loader-3-line ri-spin"></i>'); }
        badge.setAttribute('data-state', state);
    }

    function paintItem(item, state) {
        var action = state === 'unpublished' ? 'activate' : 'deactivate';
        if (state === 'busy' || item.getAttribute('data-n8n-status-action') === action) { return; }
        var text = item.getAttribute('data-text-' + action), span = item.querySelector('span'), icon = item.querySelector('i');
        item.setAttribute('data-n8n-status-action', action);
        item.setAttribute('aria-label', text);
        if (span) { span.textContent = text; }
        if (icon) { icon.className = action === 'activate' ? 'ri-checkbox-circle-line' : 'ri-close-circle-line'; }
    }

    /** Brings the badge and the menu item of every row of the two lists to the state of its core icon. */
    function sync(doc) {
        var rows = doc.querySelectorAll(ROWS);
        for (var k = 0; k < rows.length; k++) {
            var icon = iconOf(rows[k]), badge = rows[k].querySelector(BADGE);
            if (!icon || !badge) { continue; }
            var state = stateOf(icon), item = rows[k].querySelector(ITEM);
            paintBadge(badge, state);
            if (item) { paintItem(item, state); }
        }
    }

    function install(doc, win) {
        doc.addEventListener('click', function (event) {
            var item = event.target.closest && event.target.closest(ITEM), row = item && item.closest('tr'), icon = row && iconOf(row);
            if (!item) { return; }
            event.preventDefault();
            if (icon) { icon.click(); }
        });

        var pending = false;
        new win.MutationObserver(function () {
            if (pending) { return; }
            pending = true;
            win.requestAnimationFrame(function () { pending = false; sync(doc); });
        }).observe(doc.body || doc.documentElement, {childList: true, subtree: true, attributes: true, attributeFilter: ['class', 'data-status']});
    }

    var api = {stateOf: stateOf, sync: sync, install: install};

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    } else if (root.document && !root.n8ndispatchTemplateStatus) {
        root.n8ndispatchTemplateStatus = api;
        install(root.document, root);
    }
})(typeof window !== 'undefined' ? window : this);
