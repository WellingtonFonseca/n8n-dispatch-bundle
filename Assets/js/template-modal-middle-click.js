/**
 * The name on the SMS and HSM template lists is a link to the template's details (data-toggle="ajaxmodal"): a normal
 * click opens it in the shared modal, but a click with the middle button (scroll wheel) made the browser open the link's
 * address in a new tab, which holds only the bare content of the modal. The middle click opens the same modal instead.
 *
 * Core's handler only listens to "click"; a middle button press ends in "auxclick" (button 1). Capture phase, so the
 * browser's default (the new tab) is cancelled before anything else sees it. Ctrl/cmd + click is not touched.
 */
(function (window, document) {
    'use strict';

    if (window.n8ndispatchTemplateMiddleClick) {
        return;
    }

    window.n8ndispatchTemplateMiddleClick = true;

    var DETAILS = /\/n8ndispatch\/(sms|hsm)-templates\/details\/\d+/;

    document.addEventListener('auxclick', function (event) {
        if (event.button !== 1 || !event.target.closest) {
            return;
        }

        var link = event.target.closest('a[data-toggle="ajaxmodal"]');

        if (!link || !DETAILS.test(link.getAttribute('href') || '')) {
            return;
        }

        event.preventDefault();
        event.stopImmediatePropagation();
        window.Mautic.ajaxifyModal(link, event);
    }, true);
})(window, document);
