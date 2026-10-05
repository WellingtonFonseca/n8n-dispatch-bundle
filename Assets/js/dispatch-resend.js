/**
 * Confirmation-modal callback for "Reenviar" on the Dispatches screen
 * (Resources/views/Dispatch/list.html.twig). The link only calls
 * Mautic.showConfirmation(this); once the user confirms, this asks the server
 * to call n8n again for that dispatch (AjaxController::resendDispatchAction).
 * The modal stays open until n8n answers: the buttons are locked (Cancelar
 * disabled, Reenviar with Mautic's spinner), and it can't be closed by the
 * backdrop or Esc, so the dispatch can't be sent twice. When the answer
 * arrives the modal closes and the table reloads with the same filters. The
 * server's answer shows as a flash message, which core displays by itself
 * (1.core.js reads response.flashes).
 */
Mautic.n8ndispatchConfirmResend = function (action, el) {
    var logId   = mQuery(el).data('dispatch-resend');
    var modal   = mQuery('.confirmation-modal');
    var confirm = modal.find('#confirm');
    var cancel  = modal.find('.modal-body button').not('#confirm');

    // Second click while the call is running: nothing.
    if (confirm.prop('disabled')) {
        return;
    }

    var pending = true;

    confirm.prop('disabled', true).append(mQuery('<i class="ri-loader-3-line ri-spin ri-fw"></i>'));
    cancel.prop('disabled', true);

    // Backdrop click and Esc both end up here.
    modal.on('hide.bs.modal', function (event) {
        if (pending) {
            event.preventDefault();
        }
    });

    mQuery.ajax({
        url: mauticAjaxUrl + '?action=plugin:N8nDispatch:resendDispatch',
        type: 'POST',
        data: {logId: logId},
        success: function () {
            pending = false;
            Mautic.dismissConfirmation();
            // Keeps the filters (they are in the query string).
            Mautic.loadContent(window.location.pathname + window.location.search);
        },
        error: function (request, textStatus, errorThrown) {
            pending = false;
            confirm.prop('disabled', false).find('.ri-loader-3-line').remove();
            cancel.prop('disabled', false);
            Mautic.processAjaxError(request, textStatus, errorThrown, true);
        }
    });
};
