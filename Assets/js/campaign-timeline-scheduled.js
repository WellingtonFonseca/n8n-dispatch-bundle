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
