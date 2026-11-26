(function (root) {
    'use strict';

    // The "Enviar por" column of the Email list, between the name and the category: built from the hidden markers
    // EventListener/EmailListProviderSubscriber prints in each name cell. Runs on every drawing of the list (ajax too).
    function addColumn(doc) {
        var table = doc.querySelector('table.email-list');
        if (!table || table.querySelector('th.col-n8ndispatch-provider') || doc.readyState === 'loading') { return; }

        var anchor = table.querySelector('thead th.col-email-category');
        var marker = table.querySelector('.n8ndispatch-email-provider');
        if (!anchor || !marker) { return; }

        var index = Array.prototype.indexOf.call(anchor.parentNode.children, anchor);
        var th = doc.createElement('th');
        th.className = 'visible-md visible-lg col-n8ndispatch-provider';
        th.textContent = marker.getAttribute('data-header');
        anchor.parentNode.insertBefore(th, anchor);

        var rows = table.querySelectorAll('tbody tr');
        for (var k = 0; k < rows.length; k++) {
            var cell = rows[k].children[index];
            if (!cell) { continue; }
            var td = doc.createElement('td');
            td.className = 'visible-md visible-lg col-n8ndispatch-provider';
            var row = rows[k].querySelector('.n8ndispatch-email-provider');
            if (row) { td.textContent = row.getAttribute('data-label'); }
            rows[k].insertBefore(td, cell);
        }
    }

    var pending = false;
    function draw() { addColumn(root.document); }
    draw();
    root.document.addEventListener('DOMContentLoaded', draw);
    new root.MutationObserver(function () {
        if (pending) { return; }
        pending = true;
        root.requestAnimationFrame(function () { pending = false; draw(); });
    }).observe(root.document.body || root.document.documentElement, {childList: true, subtree: true});
})(window);
