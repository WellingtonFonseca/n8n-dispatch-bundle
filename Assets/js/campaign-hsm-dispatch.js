(function (Mautic, mQuery) {
    if (typeof Mautic === 'undefined' || typeof mQuery === 'undefined' || !Mautic.n8ndispatchShared) {
        return;
    }

    var shared      = Mautic.n8ndispatchShared;
    var DEBOUNCE_MS = 400;
    var debounceTimer;

    var NUMERIC_ALERT_ID = 'n8ndispatch-hsm-numeric-alert';

    // Yellow alert between the message text and the variable rows, while the text has placeholders that aren't
    // numeric: that is where the user is when it matters, in the order they fill the screen in. Deliberately
    // generic: it explains the pattern ({{1}}, {{2}}, ...) and doesn't point at the wrong ones. It goes right after
    // the text field's row, and the variable rows are inserted after that same row (getContainer() in
    // n8ndispatch-shared.js). Inserting "right after" puts the later one first, so the response handler below
    // renders the variable rows first and the alert second, which leaves the alert above them.
    function updateNumericAlert($textField, hasNonNumeric) {
        mQuery('#' + NUMERIC_ALERT_ID + '-row').remove();

        if (!hasNonNumeric) {
            return;
        }

        var text = mQuery('#n8ndispatch-hsm-config').data('numeric-alert-text');

        if (text) {
            $textField.closest('.row').after(shared.buildRowAlertHtml(NUMERIC_ALERT_ID, text));
        }
    }

    function renderHsmVariables($textField) {
        var text = $textField.val();

        if (!text) {
            shared.clearVariables($textField);
            updateNumericAlert($textField, false);

            return;
        }

        Mautic.ajaxActionRequest('plugin:N8nDispatch:getHsmVariables', {text: text}, function (response) {
            shared.renderVariablesFromResponse($textField, response);
            updateNumericAlert($textField, !!response && 0 < (response.nonNumericVariables || []).length);
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
