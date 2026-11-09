/**
 * "Reenviar" on the Dispatches screen (Resources/views/Dispatch/resend.html.twig, the modal the options menu opens).
 * The modal lists the variables of the template with an input each; this posts them to the server
 * (AjaxController::resendDispatchAction), which resends with those values. The modal stays open until n8n answers:
 * the buttons are locked (Cancelar disabled, Reenviar with Mautic's spinner), and it can't be closed by the backdrop
 * or Esc, so the dispatch can't be sent twice. When the answer arrives the modal closes and the table reloads with
 * the same filters. The server's answer shows as a flash message, which core displays by itself (1.core.js reads
 * response.flashes).
 */
Mautic.n8ndispatchSubmitResend = function (button) {
    var confirm = mQuery(button);
    var form    = confirm.closest('.n8ndispatch-resend');
    var modal   = confirm.closest('.modal');
    var cancel  = form.find('[data-resend-cancel]');

    // Second click while the call is running: nothing.
    if (confirm.prop('disabled')) {
        return;
    }

    var variables = {};

    form.find('[data-resend-variable]').each(function () {
        variables[mQuery(this).data('resend-variable')] = mQuery(this).val();
    });

    var pending = true;

    confirm.prop('disabled', true).append(mQuery('<i class="ri-loader-3-line ri-spin ri-fw"></i>'));
    cancel.prop('disabled', true);
    form.find('[data-resend-variable]').prop('disabled', true);

    // Backdrop click and Esc both end up here.
    modal.on('hide.bs.modal', function (event) {
        if (pending) {
            event.preventDefault();
        }
    });

    mQuery.ajax({
        url: mauticAjaxUrl + '?action=plugin:N8nDispatch:resendDispatch',
        type: 'POST',
        data: {logId: form.data('resend-log'), variables: variables},
        success: function () {
            pending = false;
            modal.modal('hide');
            // Keeps the filters (they are in the query string).
            Mautic.loadContent(window.location.pathname + window.location.search);
        },
        error: function (request, textStatus, errorThrown) {
            pending = false;
            confirm.prop('disabled', false).find('.ri-loader-3-line').remove();
            cancel.prop('disabled', false);
            form.find('[data-resend-variable]').prop('disabled', false);
            Mautic.processAjaxError(request, textStatus, errorThrown, true);
        }
    });
};
