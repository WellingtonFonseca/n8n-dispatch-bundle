(function (Mautic, mQuery) {
    if (typeof Mautic === 'undefined' || typeof mQuery === 'undefined' || !Mautic.n8ndispatchShared) {
        return;
    }

    var shared      = Mautic.n8ndispatchShared;
    var DEBOUNCE_MS = 400;
    var debounceTimer;

    var NUMERIC_ALERT_ID  = 'n8ndispatch-sms-numeric-alert';
    var UNMAPPED_ALERT_ID = 'n8ndispatch-sms-unmapped-alert';

    // The alert text comes translated (system language) from the template's hidden config element
    // (Resources/views/SmsTemplate/form.html.twig).
    function updateNumericAlert($textField, hasNonNumeric) {
        shared.setNumericAlert($textField, NUMERIC_ALERT_ID, mQuery('#n8ndispatch-sms-config').data('numeric-alert-text'), hasNonNumeric);
    }

    // "Every variable needs a value": shown while a placeholder of the text has no filled-in row below it (never
    // together with the numeric alert, see hasUnmappedPlaceholder()). Same text as the error of a blocked save.
    function updateUnmappedAlert($textField) {
        shared.setUnmappedAlert($textField, UNMAPPED_ALERT_ID, mQuery('#n8ndispatch-sms-config').data('unmapped-alert-text'), shared.hasUnmappedVariables($textField));
    }

    function renderSmsVariables($textField) {
        // Work started for a field that already left the page (this scan is debounced, so the form may have been
        // re-rendered by a blocked save meanwhile) would remove the new form's alert (same id) and put a new one
        // into the old, detached DOM: drop it.
        if (!document.body.contains($textField[0])) {
            return;
        }

        var text = $textField.val();

        // The alert follows the text itself, decided right here: it is there as soon as the page loads (a blocked
        // save brings the form back with the same text), and no answer from the server can take it away.
        updateNumericAlert($textField, shared.hasNonNumericPlaceholder(text));
        updateUnmappedAlert($textField);

        if (!text) {
            shared.clearVariables($textField);

            return;
        }

        Mautic.ajaxActionRequest('plugin:N8nDispatch:getSmsVariables', {text: text}, function (response) {
            // Same reason as above, for an answer that arrives after the form was re-rendered.
            if (!document.body.contains($textField[0])) {
                return;
            }

            shared.renderVariablesFromResponse($textField, response);
            // Inserting the variable rows right after the text field's row would leave them above the alert, so put
            // the alert back (from the text as it is now, not from the answer).
            updateNumericAlert($textField, shared.hasNonNumericPlaceholder($textField.val()));
            updateUnmappedAlert($textField);
        });
    }

    // data-onload-callback convention (see CoreBundle Assets/js/1a.content.js) —
    // fires once when an SMS Template's edit page opens (Form/Type/
    // SmsTemplateType.php; the campaign action itself no longer holds text).
    Mautic.n8nDispatchInitSmsVariables = function (el) {
        var $el = mQuery(el);

        // Filling in a row clears (or brings back) the alert without waiting for a save.
        shared.onMappingSynced($el, function () {
            if (document.body.contains($el[0])) {
                updateUnmappedAlert($el);
            }
        });

        renderSmsVariables($el);
    };

    // Debounced: unlike the Email picker's plain onchange, this fires on
    // every keystroke/paste in the textarea, so re-scanning the pasted
    // text on every single one would spam the AJAX endpoint.
    Mautic.n8nDispatchOnSmsTextChange = function (el) {
        var $el = mQuery(el);

        window.clearTimeout(debounceTimer);
        debounceTimer = window.setTimeout(function () {
            renderSmsVariables($el);
        }, DEBOUNCE_MS);
    };
})(window.Mautic, window.mQuery);
