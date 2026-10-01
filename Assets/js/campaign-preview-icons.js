/**
 * Type icons and status badge for the n8n dispatch actions on the
 * campaign's read-only preview (the journey on the campaign details page). Core's preview
 * always includes @MauticCampaign/Event/_preview.html.twig and ignores
 * the action's settings['template'], so Resources/views/Event/
 * _email_send.html.twig (which does set these icons in the editor) is
 * never used there and the nodes fall back to core's generic
 * 'ri-shapes-fill' with no status badge. Fixed client-side instead, with
 * no core change. The badge markup/CSS are the editor's own (see
 * Assets/css/campaign-status-badge.css); the status comes from
 * plugin:N8nDispatch:getCampaignEventStatuses.
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

    // Keep in sync with the status icons in _email_send.html.twig. The labels are looked up when a badge is drawn
    // (labelFor() below): Mautic fills window.mauticLang from javascript.ini in the system language, English is
    // the fallback.
    var STATUSES = {
        test:       {icon: 'ri-bug-line',  label: 'Test'},
        production: {icon: 'ri-play-fill', label: 'Production'},
        paused:     {icon: 'ri-pause-fill', label: 'Paused'},
    };

    function labelFor(status) {
        var lang = window.mauticLang;
        var key  = STATUSES[status] ? status : 'test';

        return (lang && lang['mautic.n8ndispatch.js.status.' + key]) || STATUSES[key].label;
    }

    var badgeRequested = {};

    function addBadge($node, status) {
        var def = STATUSES[status] || STATUSES.test;

        $node.prepend(
            '<span class="n8ndispatch-status-badge n8ndispatch-status-badge--' + (STATUSES[status] ? status : 'test') + '" title="' + labelFor(status) + '">'
            + '<i class="' + def.icon + '"></i>'
            + '<span class="n8ndispatch-status-badge__label">' + labelFor(status) + '</span>'
            + '</span>'
        );
    }

    // Only the read-only preview needs this: the editor's nodes already
    // carry the badge (rendered server-side), so they're skipped.
    function requestBadges($nodes) {
        var ids = [];

        $nodes.each(function () {
            var id = mQuery(this).data('event-id');

            if (id && !badgeRequested[id]) {
                badgeRequested[id] = true;
                ids.push(id);
            }
        });

        if (0 === ids.length) {
            return;
        }

        Mautic.ajaxActionRequest('plugin:N8nDispatch:getCampaignEventStatuses', {eventIds: ids}, function (response) {
            if (!response || !response.success) {
                return;
            }

            mQuery.each(response.statuses || {}, function (id, status) {
                var $node = mQuery('#CampaignEvent_' + id);

                if ($node.length && 0 === $node.children('.n8ndispatch-status-badge').length) {
                    addBadge($node, status);
                }
            });
        }, false);
    }

    function applyIcons() {
        var $needBadge = mQuery();

        mQuery('.list-campaign-event').each(function () {
            var icon = ICONS[mQuery(this).data('event')];

            if (!icon) {
                return;
            }

            var $i = mQuery(this).find('.campaign-event-icon i').first();

            if ($i.length && !$i.hasClass(icon)) {
                $i.attr('class', icon);
            }

            if (0 === mQuery(this).children('.n8ndispatch-status-badge').length) {
                $needBadge = $needBadge.add(this);
            }
        });

        requestBadges($needBadge);
    }

    new MutationObserver(applyIcons).observe(document.body, {childList: true, subtree: true});
    applyIcons();
})(window.mQuery);
