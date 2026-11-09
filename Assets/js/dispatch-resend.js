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
/**
 * The message shown at the end of the form (SMS / HSM): the template text with each {{name}} replaced by what is typed in the
 * input of that name; a name with no input, or an empty one, stays as written. It is a PREVIEW: the server fills the text that is
 * sent with TemplateVariableScanner::fill(); a resend never has an empty value (n8ndispatchValidateResend).
 */
Mautic.n8ndispatchFillResendMessage = function (form) {
    var code = form.find('[data-resend-template]');

    if (!code.length) {
        return;
    }

    code.text(String(code.attr('data-resend-template')).replace(/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/g, function (placeholder, name) {
        var input = form.find('[data-resend-variable]').filter(function () { return mQuery(this).attr('data-resend-variable') === name; });

        // An empty input keeps the {{name}}: the gap is seen in the text.
        return input.length && input.val() !== '' ? input.val() : placeholder;
    }));
};

/**
 * The template needs every variable it names: while an input is empty (or only spaces) the footer's Reenviar is disabled and the
 * form says why. The server refuses such a resend too (DispatchResender::emptyVariablesAnswer).
 */
Mautic.n8ndispatchValidateResend = function (form) {
    var empty = form.find('[data-resend-variable]').filter(function () { return mQuery.trim(mQuery(this).val()) === ''; }).length > 0;
    var button = form.closest('.modal').find('.modal-form-buttons .btn-primary');

    button.toggleClass('disabled', empty).prop('disabled', empty);
    form.find('[data-resend-block]').toggle(empty);
};

// Called when the modal content is loaded, BEFORE core copies the buttons into the footer: the check waits for them.
Mautic.n8ndispatchResendForm = function (element) {
    setTimeout(function () { Mautic.n8ndispatchValidateResend(mQuery(element)); }, 0);
};

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
        // attr, not data(): data() turns a name like 007 into the number 7
        variables[mQuery(this).attr('data-resend-variable')] = mQuery(this).val();
    });

    form.data('resend-pending', true);
    form.find('[data-resend-variable]').prop('disabled', true);

    // Backdrop click and Esc both end up here.
    modal.off('hide.bs.modal.n8ndispatchResend').on('hide.bs.modal.n8ndispatchResend', function (event) {
        if (form.data('resend-pending')) {
            event.preventDefault();
        }
    });

    mQuery.ajax({
        url: mauticAjaxUrl + '?action=plugin:N8nDispatch:resendDispatch',
        type: 'POST',
        data: {logId: form.data('resend-log'), form: 1, variables: variables},
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
            Mautic.n8ndispatchValidateResend(form);
            Mautic.processAjaxError(request, textStatus, errorThrown, true);
        }
    });
};

// The modal is built by ajax: its inputs are listened to from the document, so no hook after the load is needed.
mQuery(document).on('input', '.n8ndispatch-resend [data-resend-variable]', function () {
    var form = mQuery(this).closest('.n8ndispatch-resend');

    Mautic.n8ndispatchFillResendMessage(form);
    Mautic.n8ndispatchValidateResend(form);
});
