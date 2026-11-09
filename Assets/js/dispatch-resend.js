/**
 * "Reenviar" on the Dispatches screen (Resources/views/Dispatch/resend.html.twig, the modal the options menu opens).
 * The modal lists the variables of the template with an input each; this posts them to the server
 * (AjaxController::resendDispatchAction), which resends with those values. The buttons live in the modal's footer: core
 * copies them from the `.bottom-form-buttons` block, locks both and spins the icon of the one clicked, then clicks the
 * original (this function's `button`). The modal stays open until n8n answers and can't be closed by the backdrop or Esc,
 * so the dispatch can't be sent twice. When the answer arrives the modal closes and the table reloads with the same
 * filters; on an error the footer is unlocked again. The server's answer shows as a flash message, which core displays
 * by itself (1.core.js reads response.flashes).
 */
Mautic.n8ndispatchSubmitResend = function (button) {
    var form    = mQuery(button).closest('.n8ndispatch-resend');
    var modal   = form.closest('.modal');
    var footer  = modal.find('.modal-form-buttons button');

    // Second click while the call is running: nothing.
    if (form.data('resend-pending')) {
        return;
    }

    var variables = {};

    form.find('[data-resend-variable]').each(function () {
        variables[mQuery(this).data('resend-variable')] = mQuery(this).val();
    });

    form.data('resend-pending', true);
    form.find('[data-resend-variable]').prop('disabled', true);

    // Backdrop click and Esc both end up here.
    modal.on('hide.bs.modal', function (event) {
        if (form.data('resend-pending')) {
            event.preventDefault();
        }
    });

    mQuery.ajax({
        url: mauticAjaxUrl + '?action=plugin:N8nDispatch:resendDispatch',
        type: 'POST',
        data: {logId: form.data('resend-log'), variables: variables},
        success: function () {
            form.data('resend-pending', false);
            modal.modal('hide');
            // Keeps the filters (they are in the query string).
            Mautic.loadContent(window.location.pathname + window.location.search);
        },
        error: function (request, textStatus, errorThrown) {
            form.data('resend-pending', false);
            footer.prop('disabled', false);
            Mautic.stopIconSpinPostEvent();
            form.find('[data-resend-variable]').prop('disabled', false);
            Mautic.processAjaxError(request, textStatus, errorThrown, true);
        }
    });
};
