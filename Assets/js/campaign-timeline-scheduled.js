/**
 * Confirmation-modal callback for the "cancel this scheduled dispatch"
 * button on the n8n dispatch Timeline card (SubscribedEvents/Timeline/
 * _email_send.html.twig). The button itself only calls
 * Mautic.showConfirmation(this) — this function is what actually
 * cancels the event once the user confirms, read via data-event-id/
 * data-contact-id on the button (Mautic.showConfirmation's own
 * confirmAction/href mechanism isn't used here, since this isn't a URL
 * to navigate to).
 */
Mautic.n8ndispatchConfirmCancelSchedule = function (action, el) {
    var eventId   = mQuery(el).data('event-id');
    var contactId = mQuery(el).data('contact-id');

    Mautic.dismissConfirmation();
    Mautic.cancelScheduledCampaignEvent(eventId, contactId);

    // Separate call, not a replacement for the one above: core's own
    // cancelScheduledCampaignEvent has no concept of who clicked cancel,
    // only that the event is now cancelled. This records the acting user
    // into our own metadata.n8ndispatch_cancellation bag so the Timeline
    // card's 'cancelled' message can show it — best-effort, never blocks
    // or undoes the cancellation above if it fails. The 'cancelled' <span>
    // was rendered server-side before this click happened, so it can't
    // already contain this text; filled in live here rather than waiting
    // for the next full page load. .html(), not .text() — the message is
    // prefixed with a line break to match the Twig-rendered version.
    Mautic.ajaxActionRequest('plugin:N8nDispatch:recordScheduleCancellation', {
        eventId: eventId,
        contactId: contactId,
    }, function (response) {
        if (response.success && response.cancelledByMessage) {
            mQuery('.n8ndispatch-timeline-cancelled-by-' + eventId).html('<br/>' + response.cancelledByMessage);
        }
    });
};

/**
 * Reschedule tracking, via a global ajaxSuccess listener rather than a
 * wrapped onclick — core lets a reschedule be saved two different ways
 * (pressing Enter in the inline date field, or clicking our own Save
 * button, CampaignBundle/Assets/js/campaign.js's
 * Mautic.updateScheduledCampaignEvent and Mautic.saveScheduledCampaignEvent),
 * and only one of those two paths goes through a button this plugin
 * controls. Both fire the exact same campaign:updateScheduledCampaignEvent
 * ajax call under the hood, so watching for that call succeeding, rather
 * than hooking a specific button, covers both uniformly and needs no
 * changes if core ever adds a third way to save the same edit.
 *
 * mQuery.ajax() (what Mautic.ajaxActionRequest calls) is what settings/xhr
 * below come from — this fires for every ajax call on the page, filtered
 * down to just this one action by URL.
 */
mQuery(document).ajaxSuccess(function (event, xhr, settings) {
    if (!settings.url || settings.url.indexOf('action=campaign:updateScheduledCampaignEvent') === -1) {
        return;
    }

    var response = xhr.responseJSON;
    if (!response || !response.success) {
        return;
    }

    var params    = new URLSearchParams(settings.data);
    var eventId   = params.get('eventId');
    var contactId = params.get('contactId');

    if (!eventId || !contactId) {
        return;
    }

    // Same best-effort reasoning as the cancellation call above: never
    // blocks or undoes the reschedule that already succeeded if this
    // fails.
    Mautic.ajaxActionRequest('plugin:N8nDispatch:recordScheduleReschedule', {
        eventId: eventId,
        contactId: contactId,
    }, function (recordResponse) {
        if (recordResponse.success && recordResponse.rescheduledByMessage) {
            mQuery('.n8ndispatch-timeline-rescheduled-by-' + eventId).html('<br/>' + recordResponse.rescheduledByMessage);
        }
    });
});
