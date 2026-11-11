(function (Mautic, mQuery) {
    if (typeof Mautic === 'undefined' || typeof mQuery === 'undefined' || !Mautic.n8ndispatchShared) {
        return;
    }

    var shared      = Mautic.n8ndispatchShared;
    var DEBOUNCE_MS = 400;
    var debounceTimer;

    var NUMERIC_ALERT_ID  = 'n8ndispatch-hsm-numeric-alert';
    var UNMAPPED_ALERT_ID = 'n8ndispatch-hsm-unmapped-alert';
    var SEQUENCE_ALERT_ID = 'n8ndispatch-hsm-sequence-alert';

    // The alert text comes translated (system language) from the template's hidden config element
    // (Resources/views/HsmTemplate/form.html.twig).
    function updateNumericAlert($textField, hasNonNumeric) {
        shared.setNumericAlert($textField, NUMERIC_ALERT_ID, mQuery('#n8ndispatch-hsm-config').data('numeric-alert-text'), hasNonNumeric);
    }

    // The skipped number, the {{0}} and the limit of 50: shown while the text is typed and when the field is left
    // (the field calls this script on keyup and on change). Same texts as the errors of a blocked save.
    function updateSequenceAlert($textField) {
        var config = mQuery('#n8ndispatch-hsm-config');

        shared.setSequenceAlert($textField, SEQUENCE_ALERT_ID, {
            gap:   config.data('sequence-gap-text'),
            zero:  config.data('sequence-zero-text'),
            limit: config.data('sequence-limit-text')
        }, $textField.val());
    }

    // "Every variable needs a value": shown while a placeholder of the text has no filled-in row below it (never
    // together with the numeric alert, see hasUnmappedPlaceholder()). Same text as the error of a blocked save.
    function updateUnmappedAlert($textField) {
        shared.setUnmappedAlert($textField, UNMAPPED_ALERT_ID, mQuery('#n8ndispatch-hsm-config').data('unmapped-alert-text'), shared.hasUnmappedVariables($textField));
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
        updateSequenceAlert($textField);
        updateUnmappedAlert($textField);

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
            updateSequenceAlert($textField);
            updateUnmappedAlert($textField);
        });
    }

    var CARDS = '#n8ndispatch-hsm-cards';

    // The first thing wrong with the carousel's image inputs, as text (or '' when nothing is): the same rules, in the
    // same order, as HsmCards in PHP. texts carry the %filled%/%blank%/%card% placeholders of the messages
    // shown when a save is refused; the minimum is a plain reminder, on screen until two images are in.
    function cardsProblem(values, texts) {
        var blankAt = 0;
        var filled  = 0;

        for (var i = 0; i < values.length; i++) {
            var url = String(values[i] || '').trim();

            if ('' === url) {
                blankAt = blankAt || i + 1;

                continue;
            }

            if (blankAt) {
                return String(texts.gap).replace('%filled%', i + 1).replace('%blank%', blankAt);
            }

            if (!/^https:\/\/\S+$/i.test(url)) {
                return String(texts.url).replace('%card%', i + 1);
            }

            filled++;
        }

        return filled < 2 ? String(texts.min) : '';
    }

    // Type Image: only the first input counts (the others are hidden and may hold what a carousel left). Same
    // rules as HsmCards::validateImage in PHP.
    function imageProblem(values, texts) {
        var url = String(values[0] || '').trim();

        if ('' === url) {
            return String(texts.image);
        }

        return /^https:\/\/\S+$/i.test(url) ? '' : String(texts.url);
    }

    var currentType = '';

    // The alert between the help text and the inputs, following what is typed (same look as the variables alert).
    function updateCardsAlert() {
        var values = [];
        mQuery(CARDS + ' input').each(function () {
            values.push(mQuery(this).val());
        });

        var config  = mQuery(CARDS);
        var problem = 'image' === currentType
            ? imageProblem(values, {image: config.data('image-text'), url: config.data('image-url-text')})
            : cardsProblem(values, {min: config.data('min-text'), gap: config.data('gap-text'), url: config.data('url-text')});
        var $alert  = mQuery('#n8ndispatch-hsm-cards-alert');

        $alert.html('');

        if (problem) {
            $alert.append(mQuery('<div class="alert alert-warning"></div>').text(problem));
        }
    }

    Mautic.n8nDispatchCardsProblem = cardsProblem;
    Mautic.n8nDispatchImageProblem = imageProblem;

    // Shows the image inputs only while the type is "carousel" (all of them) or "image" (the first), see
    // Resources/views/HsmTemplate/form.html.twig. Also the type select's data-onload-callback, so the page opens in
    // the right state (and with the alert bound).
    Mautic.n8nDispatchOnHsmTypeChange = function (el) {
        currentType = mQuery(el).val();
        var single  = 'image' === currentType;
        mQuery(CARDS).toggle(single || 'carousel' === currentType).toggleClass('n8ndispatch-cards-single', single);
        mQuery(CARDS).off('input.cardsalert change.cardsalert').on('input.cardsalert change.cardsalert', 'input', updateCardsAlert);
        updateCardsAlert();
    };

    // Shows the router while the template is sent through n8n and the number select while it is sent through the DialogHSM
    // (they share one slot of the form). Also the provider select's data-onload-callback, so the page opens in the
    // right state.
    Mautic.n8nDispatchOnHsmProviderChange = function (el) {
        var dialog = 'dialoghsm' === mQuery(el).val();

        mQuery('#n8ndispatch-hsm-router-box').toggle(!dialog);
        mQuery('#n8ndispatch-hsm-number-box').toggle(dialog);
    };

    // data-onload-callback convention (see CoreBundle Assets/js/1a.content.js) —
    // fires once when an HSM Template's edit page opens (Form/Type/
    // HsmTemplateType.php). Same {{name}}-scanning mechanism as
    // campaign-sms-dispatch.js — see that file's own comment — replacing
    // the positional ($1, $2, ...) picker this screen's fields used to
    // have back when they lived on the Campaign Action's own form.
    Mautic.n8nDispatchInitHsmVariables = function (el) {
        var $el = mQuery(el);

        // Filling in a row clears (or brings back) the alert without waiting for a save.
        shared.onMappingSynced($el, function () {
            if (document.body.contains($el[0])) {
                updateUnmappedAlert($el);
            }
        });

        renderHsmVariables($el);
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
