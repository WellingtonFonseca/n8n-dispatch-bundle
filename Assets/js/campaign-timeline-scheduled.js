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
};
