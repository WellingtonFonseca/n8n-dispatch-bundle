/**
 * Confirmation-modal callback for "Reenviar" on the Dispatches screen
 * (Resources/views/Dispatch/list.html.twig). The link only calls
 * Mautic.showConfirmation(this); once the user confirms, this asks the server
 * to call n8n again for that dispatch (AjaxController::resendDispatchAction)
 * and reloads the table. The server's answer arrives as a flash message,
 * which core shows by itself (1.core.js reads response.flashes).
 */
Mautic.n8ndispatchConfirmResend = function (action, el) {
    var logId = mQuery(el).data('dispatch-resend');

    Mautic.dismissConfirmation();

    Mautic.ajaxActionRequest('plugin:N8nDispatch:resendDispatch', {logId: logId}, function () {
        Mautic.loadContent(window.location.pathname);
    });
};
