// Status of the SMS / HSM template lists: an "Ativo" / "Inativo" badge (the n8n badge, an icon that opens on hover) in the Status column, and "Desativar" /
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

    /**
     * Writes the state into a badge: an icon (play on green, stop on red) plus the word, which is the badge's title and shows when it
     * opens on hover (n8ndispatch.css); while core works only a spinner, orange. Nothing is touched when it already shows the state.
     */
    function paintBadge(badge, state) {
        if (badge.getAttribute('data-state') === state) { return; }
        var modifier = state === 'busy' ? 'pending' : (state === 'unpublished' ? 'failed' : 'production');
        var doc = badge.ownerDocument, icon = doc.createElement('i');
        badge.className = 'n8ndispatch-badge n8ndispatch-template-status n8ndispatch-badge--' + modifier;
        badge.textContent = '';
        icon.setAttribute('aria-hidden', 'true');
        if (state === 'busy') {
            icon.className = 'ri-loader-3-line ri-spin';
            badge.appendChild(icon);
        } else {
            var text = badge.getAttribute('data-text-' + state), word = doc.createElement('span');
            icon.className = state === 'unpublished' ? 'ri-stop-fill' : 'ri-play-fill';
            word.className = 'n8ndispatch-template-status__text';
            word.textContent = text;
            badge.appendChild(icon);
            badge.appendChild(word);
            badge.setAttribute('title', text);
            badge.setAttribute('aria-label', text);
        }
        badge.setAttribute('data-state', state);
    }

    /** How much a badge grows when it opens: its content, up to 140px, minus what it has. */
    function growth(badge) {
        return Math.max(0, Math.min(badge.scrollWidth, 140) - badge.offsetWidth);
    }

    /** What the open badge does not fit in its cell: the badge at rest plus what it grows, past the room the cell has. 0 when it fits. */
    function overflow(restWidth, grow, room) {
        return Math.max(0, restWidth + grow - room);
    }

    /**
     * The widths of the columns while a badge in column `index` is open: that column grows by `extra` (what does not fit, often nothing: the header is
     * wider than the badge), the others keep what they have. Fixing the widths is what keeps the table from sharing the room again among its columns
     * (the names would re-wrap, a row change height and the badge slide from under the pointer).
     */
    function pushPlan(widths, index, extra) {
        var grow = Math.max(0, extra), out = widths.map(function (w, k) { return k === index ? w + grow : w; });

        return {widths: out, total: out.reduce(function (a, b) { return a + b; }, 0)};
    }

    var PUSHED = 'data-status-pushed';
    var BADGES = 'td.col-template-status .n8ndispatch-template-status';

    /** Opening a badge fixes the widths of the table and widens its own column only by what does not fit in it (pushPlan). */
    function openBadge(badge) {
        var table = badge.closest('table'), cell = badge.closest('td');
        if (!table || !cell || table.hasAttribute(PUSHED)) { return; }
        var heads = table.querySelectorAll('thead th'), widths = [];
        for (var k = 0; k < heads.length; k++) { widths.push(heads[k].getBoundingClientRect().width); }
        var style = window.getComputedStyle(cell);
        var room = cell.clientWidth - parseFloat(style.paddingLeft) - parseFloat(style.paddingRight);
        var plan = pushPlan(widths, cell.cellIndex, overflow(badge.offsetWidth, growth(badge), room));
        table.setAttribute(PUSHED, table.style.cssText + '|' + Array.prototype.map.call(heads, function (h) { return h.style.width; }).join(','));
        table.style.tableLayout = 'fixed';
        table.style.width = plan.total + 'px';
        for (var j = 0; j < heads.length; j++) { heads[j].style.width = plan.widths[j] + 'px'; }
    }

    /** Gives the table back its own widths. */
    function closeBadge(badge) {
        var table = badge.closest('table');
        if (!table || !table.hasAttribute(PUSHED)) { return; }
        var saved = table.getAttribute(PUSHED).split('|'), widths = saved[saved.length - 1].split(','), heads = table.querySelectorAll('thead th');
        table.removeAttribute(PUSHED);
        table.style.cssText = saved.slice(0, -1).join('|');
        for (var k = 0; k < heads.length; k++) { heads[k].style.width = widths[k] || ''; }
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

        doc.addEventListener('mouseover', function (event) {
            var badge = event.target.closest && event.target.closest(BADGES);
            if (badge) { openBadge(badge); }
        });
        doc.addEventListener('mouseout', function (event) {
            var badge = event.target.closest && event.target.closest(BADGES);
            if (badge && !(event.relatedTarget && badge.contains(event.relatedTarget))) { closeBadge(badge); }
        });

        var pending = false;
        new win.MutationObserver(function () {
            if (pending) { return; }
            pending = true;
            win.requestAnimationFrame(function () { pending = false; sync(doc); });
        }).observe(doc.body || doc.documentElement, {childList: true, subtree: true, attributes: true, attributeFilter: ['class', 'data-status']});
    }

    var api = {growth: growth, overflow: overflow, pushPlan: pushPlan, stateOf: stateOf, paintBadge: paintBadge, sync: sync, install: install};

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    } else if (root.document && !root.n8ndispatchTemplateStatus) {
        root.n8ndispatchTemplateStatus = api;
        install(root.document, root);
    }
})(typeof window !== 'undefined' ? window : this);
