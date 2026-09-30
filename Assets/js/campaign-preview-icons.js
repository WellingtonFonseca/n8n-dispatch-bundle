/**
 * Type icons for the n8n dispatch actions on the campaign's read-only
 * preview (the journey on the campaign details page). Core's preview
 * always includes @MauticCampaign/Event/_preview.html.twig and ignores
 * the action's settings['template'], so Resources/views/Event/
 * _email_send.html.twig (which does set these icons in the editor) is
 * never used there and the nodes fall back to core's generic
 * 'ri-shapes-fill'. Fixed client-side instead, with no core change.
 *
 * A MutationObserver, not a load hook: Mautic swaps page content over AJAX
 * without a full reload, so the preview can appear at any time. Setting
 * the same class again is a no-op, so the editor's own (already correct)
 * nodes are left alone.
 */
(function (mQuery) {
    if (typeof mQuery === 'undefined') {
        return;
    }

    // Keep in sync with typeIcon in Resources/views/Event/_email_send.html.twig.
    var ICONS = {
        'n8ndispatch.email.send': 'ri-mail-fill',
        'n8ndispatch.sms.send':   'ri-message-3-fill',
        'n8ndispatch.hsm.send':   'ri-whatsapp-fill',
    };

    function applyIcons() {
        mQuery('.list-campaign-event').each(function () {
            var icon = ICONS[mQuery(this).data('event')];

            if (!icon) {
                return;
            }

            var $i = mQuery(this).find('.campaign-event-icon i').first();

            if ($i.length && !$i.hasClass(icon)) {
                $i.attr('class', icon);
            }
        });
    }

    new MutationObserver(applyIcons).observe(document.body, {childList: true, subtree: true});
    applyIcons();
})(window.mQuery);
