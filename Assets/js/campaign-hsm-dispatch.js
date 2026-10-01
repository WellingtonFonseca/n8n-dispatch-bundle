(function (Mautic, mQuery) {
    if (typeof Mautic === 'undefined' || typeof mQuery === 'undefined' || !Mautic.n8ndispatchShared) {
        return;
    }

    var shared      = Mautic.n8ndispatchShared;
    var DEBOUNCE_MS = 400;
    var debounceTimer;

    var NUMERIC_ALERT_ID = 'n8ndispatch-hsm-numeric-alert';

    // The alert text comes translated (system language) from the template's hidden config element
    // (Resources/views/HsmTemplate/form.html.twig).
    function updateNumericAlert($textField, hasNonNumeric) {
        shared.setNumericAlert($textField, NUMERIC_ALERT_ID, mQuery('#n8ndispatch-hsm-config').data('numeric-alert-text'), hasNonNumeric);
    }

    function renderHsmVariables($textField) {
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

        if (!text) {
            shared.clearVariables($textField);

            return;
        }

        Mautic.ajaxActionRequest('plugin:N8nDispatch:getHsmVariables', {text: text}, function (response) {
            // Same reason as above, for an answer that arrives after the form was re-rendered.
            if (!document.body.contains($textField[0])) {
                return;
            }

            shared.renderVariablesFromResponse($textField, response);
            // Inserting the variable rows right after the text field's row would leave them above the alert, so put
            // the alert back (from the text as it is now, not from the answer).
            updateNumericAlert($textField, shared.hasNonNumericPlaceholder($textField.val()));
        });
    }

    // data-onload-callback convention (see CoreBundle Assets/js/1a.content.js) —
    // fires once when an HSM Template's edit page opens (Form/Type/
    // HsmTemplateType.php). Same {{name}}-scanning mechanism as
    // campaign-sms-dispatch.js — see that file's own comment — replacing
    // the positional ($1, $2, ...) picker this screen's fields used to
    // have back when they lived on the Campaign Action's own form.
    Mautic.n8nDispatchInitHsmVariables = function (el) {
        renderHsmVariables(mQuery(el));
    };

    // Debounced: unlike the Email picker's plain onchange, this fires on
    // every keystroke/paste in the textarea, so re-scanning the pasted
    // text on every single one would spam the AJAX endpoint.
    Mautic.n8nDispatchOnHsmTextChange = function (el) {
        var $el = mQuery(el);

        window.clearTimeout(debounceTimer);
        debounceTimer = window.setTimeout(function () {
            renderHsmVariables($el);
        }, DEBOUNCE_MS);
    };
})(window.Mautic, window.mQuery);
