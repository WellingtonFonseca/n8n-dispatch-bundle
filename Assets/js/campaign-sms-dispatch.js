(function (Mautic, mQuery) {
    if (typeof Mautic === 'undefined' || typeof mQuery === 'undefined' || !Mautic.n8ndispatchShared) {
        return;
    }

    var shared      = Mautic.n8ndispatchShared;
    var DEBOUNCE_MS = 400;
    var debounceTimer;

    var NUMERIC_ALERT_ID = 'n8ndispatch-sms-numeric-alert';

    // The alert text comes translated (system language) from the template's hidden config element
    // (Resources/views/SmsTemplate/form.html.twig).
    function updateNumericAlert($textField, hasNonNumeric) {
        shared.setNumericAlert($textField, NUMERIC_ALERT_ID, mQuery('#n8ndispatch-sms-config').data('numeric-alert-text'), hasNonNumeric);
    }

    function renderSmsVariables($textField) {
        var text = $textField.val();

        if (!text) {
            shared.clearVariables($textField);
            updateNumericAlert($textField, false);

            return;
        }

        Mautic.ajaxActionRequest('plugin:N8nDispatch:getSmsVariables', {text: text}, function (response) {
            shared.renderVariablesFromResponse($textField, response);
            updateNumericAlert($textField, !!response && 0 < (response.nonNumericVariables || []).length);
        });
    }

    // data-onload-callback convention (see CoreBundle Assets/js/1a.content.js) —
    // fires once when an SMS Template's edit page opens (Form/Type/
    // SmsTemplateType.php; the campaign action itself no longer holds text).
    Mautic.n8nDispatchInitSmsVariables = function (el) {
        renderSmsVariables(mQuery(el));
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
