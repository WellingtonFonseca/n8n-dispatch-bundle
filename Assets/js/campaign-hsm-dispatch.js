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
        bindNumberSelect();
        loadDialogTemplates(false);
    };

    // ---- DialogHSM template select -------------------------------------------------------------------------------
    // With the DialogHSM provider the template is picked from the list of the number (GET route of the DialogHSM, see
    // HsmTemplateType::finishView): the select fills the template name, the language the 360dialog gave it and the body
    // text (the field below stays in the form, hidden, so the variable rows and alerts keep scanning it). Without the
    // plugin, without access, or when the list is not available, the manual form (free name + text) is shown as before.
    var templates   = [];
    var loadCounter = 0;

    function providerValue() {
        return mQuery('select[name$="[provider]"]').val();
    }

    function numberSelect() {
        return mQuery('#' + mQuery('#n8ndispatch-hsm-config').data('number-id'));
    }

    function bindNumberSelect() {
        mQuery(document).off('change.n8ndispatchhsmnumber').on('change.n8ndispatchhsmnumber', '#' + mQuery('#n8ndispatch-hsm-config').data('number-id'), function () {
            loadDialogTemplates(false);
        });
        mQuery('#n8ndispatch-hsm-template-refresh').off('click.n8ndispatchhsm').on('click.n8ndispatchhsm', function () {
            loadDialogTemplates(true);
        });
    }

    function showManual() {
        mQuery('#n8ndispatch-hsm-template-manual, #n8ndispatch-hsm-text-row').show();
        mQuery('#n8ndispatch-hsm-template-select-row, #n8ndispatch-hsm-preview, #n8ndispatch-hsm-unsupported').hide();
    }

    function showSelect() {
        mQuery('#n8ndispatch-hsm-template-manual, #n8ndispatch-hsm-text-row').hide();
        mQuery('#n8ndispatch-hsm-template-select-row, #n8ndispatch-hsm-unsupported').show();
    }

    function findTemplate(key) {
        for (var i = 0; i < templates.length; i++) {
            if (templates[i].name + '|' + templates[i].language === key) {
                return templates[i];
            }
        }

        return null;
    }

    var previewTemplate = null;

    // What the preview shows for the variables and the header: the image link typed in the first image input (the type
    // Image sends it as the header), the carousel's image links and, per {{n}}, the value of its mapping row (a field or Custom Object shows as a
    // token badge, since the real value only exists at send time).
    function previewValues() {
        var values  = {header: String(mQuery(CARDS + ' input').first().val() || '').trim(), body: [], cards: []};
        var mapping = {};

        // The carousel's image links, one per card input (the DialogHSM preview draws the cards from them).
        mQuery(CARDS + ' input').each(function () {
            values.cards.push(String(mQuery(this).val() || '').trim());
        });

        try {
            mapping = JSON.parse(mQuery('.n8ndispatch-variables-json').val() || '{}');
        } catch (e) {
            mapping = {};
        }

        mQuery.each(mapping, function (name, entry) {
            var n = parseInt(name, 10);

            if (!n || !entry) {
                return;
            }

            if ('field' === entry.source && entry.field) {
                values.body[n - 1] = '{contactfield=' + entry.field + '}';
            } else if ('custom_object' === entry.source && entry.customObject && entry.customObjectField) {
                values.body[n - 1] = '{contactfield=' + entry.customObject + '.' + entry.customObjectField + '}';
            } else if (entry.value) {
                values.body[n - 1] = String(entry.value);
            }
        });

        return values;
    }

    function renderPreview(tpl) {
        var $box = mQuery('#n8ndispatch-hsm-preview');
        var ok   = false;

        previewTemplate = tpl;

        if (tpl && window.DialogHsmPreview) {
            ok = window.DialogHsmPreview.render(document.getElementById('n8ndispatch-hsm-preview-body'), tpl, previewValues(), {title: mQuery('#n8ndispatch-hsm-config').data('preview-title'), phone: true});
        }

        $box.toggle(!!ok && 'dialoghsm' === providerValue());
    }

    // Live: the image link and the mapping rows feed the preview as they are typed.
    mQuery(document).on('input.n8ndispatchhsmpreview change.n8ndispatchhsmpreview', CARDS + ' input', function () {
        if (previewTemplate) {
            renderPreview(previewTemplate);
        }
    });
    mQuery(document).on('n8ndispatch:synced.n8ndispatchhsmpreview', 'form', function () {
        if (previewTemplate && document.getElementById('n8ndispatch-hsm-preview-body')) {
            renderPreview(previewTemplate);
        }
    });

    // What the template asks for and this form does not send yet (only the body variables, and the image header /
    // carousel through the type): shown under the preview so nobody expects it to work.
    function unsupportedParts(tpl) {
        var parts  = [];
        var header = tpl.header || {};

        if (['VIDEO', 'DOCUMENT', 'LOCATION'].indexOf(header.format) >= 0) {
            parts.push('header ' + header.format);
        }

        if (header.vars) {
            parts.push('header {{n}}');
        }

        if (null === (tpl.body || {}).vars) {
            parts.push('named variables');
        }

        (tpl.buttons || []).forEach(function (b) {
            if (b.vars) {
                parts.push('button ' + b.type + ' ' + (b.index + 1));
            }
        });

        return parts;
    }

    // The image header and the carousel are the form's own types: follow what the template needs.
    function followTemplateType(tpl) {
        var type = tpl.carousel ? 'carousel' : ('IMAGE' === (tpl.header || {}).format ? 'image' : 'text');
        var $type = mQuery('select[name$="[type]"]');

        if ($type.val() !== type) {
            $type.val(type).trigger('chosen:updated').trigger('change');
        }
    }

    function renderUnsupported(tpl) {
        var $box  = mQuery('#n8ndispatch-hsm-unsupported').empty();
        var parts = tpl ? unsupportedParts(tpl) : [];

        if (parts.length) {
            $box.append(mQuery('<div class="alert alert-warning"></div>').text(String(mQuery('#n8ndispatch-hsm-config').data('unsupported-text')).replace('%what%', parts.join(', '))));
        }
    }

    // Copies the chosen template into the form's own fields; the body text drives the variable rows and the alerts.
    function applyTemplate(tpl, rescan) {
        var $text = mQuery('textarea[name$="[text]"]');
        var text  = tpl && tpl.body && tpl.body.text ? String(tpl.body.text).replace(/\\r\\n|\\n/g, '\n') : '';

        if (tpl) {
            mQuery('input[name$="[hsmTemplate]"]').val(tpl.name);
            mQuery('input[name$="[templateLanguage]"]').val(tpl.language);
        } else {
            mQuery('input[name$="[hsmTemplate]"]').val('');
            mQuery('input[name$="[templateLanguage]"]').val('');
        }

        if ($text.val() !== text || rescan) {
            $text.val(text);
            renderHsmVariables($text);
        }

        if (tpl && rescan) {
            followTemplateType(tpl);
        }

        renderUnsupported(tpl);
        renderPreview(tpl);
    }

    function fillSelect() {
        var config   = mQuery('#n8ndispatch-hsm-config');
        var select   = document.getElementById('n8ndispatch-hsm-template-select');
        var name     = mQuery('input[name$="[hsmTemplate]"]').val();
        var language = mQuery('input[name$="[templateLanguage]"]').val();
        var current  = name ? name + '|' + language : '';
        var found    = false;

        select.innerHTML = '';
        select.appendChild(new Option(config.data('select-placeholder'), ''));

        templates.forEach(function (tpl) {
            var label = tpl.name + ' (' + tpl.language + ')' + (tpl.approved ? '' : ' ' + config.data('select-unapproved'));
            var opt   = new Option(label, tpl.name + '|' + tpl.language);

            if (opt.value === current) {
                opt.selected = true;
                found        = true;
            }

            select.appendChild(opt);
        });

        // A saved template that the list no longer has: kept (and shown), so saving does not lose it silently.
        if (current && !found) {
            var missing = new Option(String(config.data('select-missing')).replace('%name%', name), current, true, true);
            select.appendChild(missing);
        }

        mQuery(select).trigger('chosen:updated');
        mQuery(select).off('change.n8ndispatchhsm').on('change.n8ndispatchhsm', function () {
            applyTemplate(findTemplate(select.value), true);
        });

        // The page opens with the template already saved: keep its text in step with the list (not a rescan of a typed text).
        applyTemplate(findTemplate(current), false);
    }

    function loadDialogTemplates(refresh) {
        var config = mQuery('#n8ndispatch-hsm-config');
        var url    = config.data('templates-url');
        var number = numberSelect().val();

        if ('dialoghsm' !== providerValue() || !url || !number) {
            showManual();

            return;
        }

        var ticket = ++loadCounter;
        var select = document.getElementById('n8ndispatch-hsm-template-select');

        select.innerHTML = '';
        select.appendChild(new Option(config.data('select-loading'), ''));
        mQuery(select).trigger('chosen:updated');
        showSelect();

        fetch(url + encodeURIComponent(number) + (refresh ? '?refresh=1' : ''), {
            credentials: 'same-origin',
            headers:     {'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json'}
        }).then(function (response) {
            // 403 (no access), 404 (number gone), 5xx: the manual form.
            return response.ok ? response.json() : null;
        }).then(function (data) {
            if (ticket !== loadCounter) {
                return;
            }

            if (!data || !data.available || !data.templates) {
                showManual();

                return;
            }

            templates = data.templates;
            fillSelect();
        }).catch(function () {
            if (ticket === loadCounter) {
                showManual();
            }
        });
    }

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
