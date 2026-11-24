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

    var previewTemplate = null;
    var renderedExtra   = '';
    var listSettled     = false;
    var initHeld        = false;

    // The buttons of the picked DialogHSM template that take a value (a URL with {{1}}, a code to copy) get a mapping row each, named
    // button_<position>_<kind>: they are not in the text. The server builds the rows and their labels (Controller/AjaxController.php).
    function buttonRowNames() {
        var names = [];

        if ('dialoghsm' !== providerValue()) {
            return names;
        }

        // No template to read the buttons from (the list failed, or the saved template is not in it): the button rows
        // already saved stay, or the rescan would drop them and the sends would miss their button values.
        if (!previewTemplate) {
            var saved = {};

            try {
                saved = JSON.parse(mQuery('.n8ndispatch-variables-json').val() || '{}') || {};
            } catch (e) {
                saved = {};
            }

            Object.keys(saved).forEach(function (name) {
                if (/^button_\d+_(url|copy_code)$/.test(name)) {
                    names.push(name);
                }
            });

            return names;
        }

        (previewTemplate.buttons || []).forEach(function (b) {
            if (b.vars && -1 !== ['URL', 'COPY_CODE'].indexOf(b.type)) {
                names.push('button_' + b.index + '_' + b.type.toLowerCase());
            }
        });

        return names;
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

        var extra = buttonRowNames();
        renderedExtra = extra.join(',');

        if (!text && !extra.length) {
            shared.clearVariables($textField);

            return;
        }

        Mautic.ajaxActionRequest('plugin:N8nDispatch:getHsmVariables', {text: text, extra: extra.join(',')}, function (response) {
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

    // The types with one file in the header (the image, the video, the document): only the first input counts.
    function isSingle(type) {
        return -1 !== ['image', 'video', 'document'].indexOf(type);
    }

    // The alert between the help text and the inputs, following what is typed (same look as the variables alert).
    function updateCardsAlert() {
        var values = [];
        mQuery(CARDS + ' input').each(function () {
            values.push(mQuery(this).val());
        });

        var config  = mQuery(CARDS);
        var problem = isSingle(currentType)
            ? imageProblem(values, {image: 'image' === currentType ? config.data('image-text') : config.data('file-text'), url: config.data('image' === currentType ? 'image-url-text' : 'file-url-text')})
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
        var single  = isSingle(currentType);
        mQuery(CARDS).toggle(single || 'carousel' === currentType).toggleClass('n8ndispatch-cards-single', single);

        // The label of the single file follows the type (Image, Video, Document).
        if (single) {
            mQuery(CARDS + ' .n8ndispatch-cards-label-single').text(mQuery(CARDS).data('label-' + currentType));
        }

        mQuery(CARDS).off('input.cardsalert change.cardsalert').on('input.cardsalert change.cardsalert', 'input', updateCardsAlert);
        updateCardsAlert();
    };

    // Shows the router while the template is sent through n8n and the number select while it is sent through the DialogHSM
    // (they share one slot of the form). Also the provider select's data-onload-callback, so the page opens in the
    // right state.
    Mautic.n8nDispatchOnHsmProviderChange = function (el) {
        resetFormState();

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
    var formNode    = null;

    // The module outlives the pages (ajax navigation): a new form (its config node is a new element) starts from a clean state, or its
    // first scan would use the list, the buttons and the "settled" flag of the form before. Runs first on a form (the provider
    // select's onload), so the text field's own onload callback finds the state already reset.
    function resetFormState() {
        var node = document.getElementById('n8ndispatch-hsm-config');

        if (node === formNode) {
            return;
        }

        formNode        = node;
        templates       = [];
        previewTemplate = null;
        renderedExtra   = '';
        listSettled     = false;
        initHeld        = false;
        loadCounter++;
    }

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
        lockType(false);

        // A hand-typed name is not the template of the stored language any more.
        mQuery('input[name$="[hsmTemplate]"]').off('input.n8ndispatchhsmname').on('input.n8ndispatchhsmname', function () {
            mQuery('input[name$="[templateLanguage]"]').val('');
        });

        // The list will not come: the page's own scan (held back while the list was awaited) can run now.
        if (!listSettled) {
            listSettled = true;

            if (initHeld) {
                renderHsmVariables(mQuery('textarea[name$="[text]"]'));
            }
        }

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
            ok = window.DialogHsmPreview.render(document.getElementById('n8ndispatch-hsm-preview-body'), tpl, previewValues(), {title: mQuery('#n8ndispatch-hsm-config').data('preview-title')});
        }

        $box.toggle(!!ok && 'dialoghsm' === providerValue());
        mQuery('#n8ndispatch-hsm-json').text(tpl ? JSON.stringify(tpl, null, 2) : '');
        mQuery('#n8ndispatch-hsm-json-row').toggle(!!tpl && 'dialoghsm' === providerValue());
        floatPreview();
    }

    // The preview floats: while the long form scrolls it stays on the screen, FLOAT_GAP px under the bar at the top of the page (the one of the
    // notifications and settings), and goes back to its place when the page comes back to the top. At the end of its column it slides up
    // with the page. position: sticky does not work here: the page wrapper has overflow hidden, so it is the scroll container for sticky.
    var FLOAT_GAP = 16;

    function floatPreview() {
        var $preview = mQuery('#n8ndispatch-hsm-preview');
        var $slot    = mQuery('#n8ndispatch-hsm-preview-slot');

        if (!$preview.length || !$slot.length) {
            // The page has no preview (the handler is bound once, at load: it must stay for the next form).
            return;
        }

        if (!$preview.is(':visible') && 'fixed' !== $preview.css('position')) {
            return;
        }

        var bar    = document.querySelector('header, #app-header, .app-header');
        var offset = (bar ? bar.getBoundingClientRect().bottom : 56) + FLOAT_GAP;
        var height = $preview.outerHeight();
        var column = $slot.closest('[class*="col-md-"]')[0];
        var top    = $slot[0].getBoundingClientRect().top;

        if (top >= offset) {
            $slot.css('height', '');
            $preview.css({position: '', top: '', width: ''});

            return;
        }

        // Pinned: never lower than the end of the column (it slides up with the page there).
        var limit = column ? column.getBoundingClientRect().bottom - height : offset;

        $slot.css('height', height);
        $preview.css({position: 'fixed', top: Math.min(offset, limit), width: $slot.width()});
    }

    mQuery(window).off('scroll.n8ndispatchhsmfloat resize.n8ndispatchhsmfloat').on('scroll.n8ndispatchhsmfloat resize.n8ndispatchhsmfloat', floatPreview);

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

        if ('LOCATION' === header.format) {
            parts.push('header LOCATION');
        }

        if (header.vars) {
            parts.push('header {{n}}');
        }

        if (null === (tpl.body || {}).vars) {
            parts.push('named variables');
        }

        (tpl.buttons || []).forEach(function (b) {
            if (b.vars && -1 === ['URL', 'COPY_CODE'].indexOf(b.type)) {
                parts.push('button ' + b.type + ' ' + (b.index + 1));
            }
        });

        return parts;
    }

    // The image header and the carousel are the form's own types: the template (the 360dialog) decides, so while a template is
    // picked the Type select is read only (a disabled select is not posted: a hidden twin carries the value).
    function followTemplateType(tpl) {
        var type  = tpl.carousel ? 'carousel' : ({IMAGE: 'image', VIDEO: 'video', DOCUMENT: 'document'}[(tpl.header || {}).format] || 'text');
        var $type = mQuery('select[name$="[type]"]');

        if ($type.val() !== type) {
            $type.val(type).trigger('chosen:updated').trigger('change');
        }

        lockType(true);
    }

    function lockType(locked) {
        var $type = mQuery('select[name$="[type]"]');

        mQuery('#n8ndispatch-hsm-type-mirror').remove();

        if (locked) {
            mQuery('<input type="hidden" id="n8ndispatch-hsm-type-mirror">').attr('name', $type.attr('name')).val($type.val()).insertAfter($type);
        }

        $type.prop('disabled', locked).trigger('chosen:updated');
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

        var textChanged = $text.val() !== text || rescan;

        if (textChanged) {
            $text.val(text);
        }

        if (tpl) {
            followTemplateType(tpl);
        } else {
            lockType(false);
        }

        renderUnsupported(tpl);
        renderPreview(tpl);

        // One scan of the variables: the text changed, the buttons that take a value changed, or the page just opened (its own scan waited
        // for this list, see n8nDispatchInitHsmVariables: a scan before the buttons are known would drop their mapping).
        if (textChanged || buttonRowNames().join(',') !== renderedExtra || !listSettled) {
            renderHsmVariables($text);
        }

        listSettled = true;
    }

    function findTemplateByName(name) {
        for (var i = 0; i < templates.length; i++) {
            if (templates[i].name === name) {
                return templates[i];
            }
        }

        return null;
    }

    function fillSelect() {
        var config   = mQuery('#n8ndispatch-hsm-config');
        var select   = document.getElementById('n8ndispatch-hsm-template-select');
        var name     = mQuery('input[name$="[hsmTemplate]"]').val();
        var language = mQuery('input[name$="[templateLanguage]"]').val();
        var current  = name ? name + '|' + language : '';
        var found    = false;
        var $typeSel = mQuery('select[name$="[type]"]');
        var snapshot = {name: name, language: language, text: mQuery('textarea[name$="[text]"]').val(), type: $typeSel.val(), locked: !!$typeSel.prop('disabled'), variablesJson: mQuery('.n8ndispatch-variables-json').val()};

        // Saved before the language was kept (templateLanguage empty): matched by name, the language is filled in by applyTemplate().
        var byName   = name && !language ? findTemplateByName(name) : null;

        if (byName) {
            current = byName.name + '|' + byName.language;
        }

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
            var picked = findTemplate(select.value);

            // Re-picking the saved '(not in the list)' option: its saved values come back instead of being wiped.
            if (!picked && select.value && select.value === current) {
                mQuery('input[name$="[hsmTemplate]"]').val(snapshot.name);
                mQuery('input[name$="[templateLanguage]"]').val(snapshot.language);
                mQuery('textarea[name$="[text]"]').val(snapshot.text);
                // The mappings and button rows come back as saved: the other template's rows had rewritten them.
                mQuery('.n8ndispatch-variables-json').val(snapshot.variablesJson);

                var $pickType = mQuery('select[name$="[type]"]');

                // Unlock first: a disabled select may not take the value, and the mirror is rebuilt by lockType().
                lockType(false);

                if ($pickType.val() !== snapshot.type) {
                    $pickType.val(snapshot.type).trigger('chosen:updated').trigger('change');
                }

                lockType(snapshot.locked);
                renderUnsupported(null);
                renderPreview(null);
                renderHsmVariables(mQuery('textarea[name$="[text]"]'));

                return;
            }

            applyTemplate(picked, true);
        });

        // The page opens with the template already saved: keep its text in step with the list (not a rescan of a typed text).
        if (!current || found) {
            applyTemplate(findTemplate(current), false);

            return;
        }

        // A saved template that the list no longer has: its saved name, language, text and variables stay as they are.
        renderUnsupported(null);
        renderPreview(null);

        if (!listSettled) {
            listSettled = true;

            if (initHeld) {
                renderHsmVariables(mQuery('textarea[name$="[text]"]'));
            }
        }
    }

    function loadDialogTemplates(refresh) {
        var config = mQuery('#n8ndispatch-hsm-config');
        var url    = config.data('templates-url');
        var number = numberSelect().val();

        if ('dialoghsm' !== providerValue() || !url || !number) {
            // Invalidates a fetch still pending, so it cannot overwrite the manual fields afterwards.
            ++loadCounter;
            showManual();

            return;
        }

        var ticket  = ++loadCounter;
        var select  = document.getElementById('n8ndispatch-hsm-template-select');
        var $icon   = mQuery('#n8ndispatch-hsm-template-refresh i');
        var started = Date.now();

        // The refresh icon spins while the list loads, for at least 600 ms (a list from the cache answers faster than the eye).
        $icon.addClass('n8ndispatch-spin');

        var stopSpin = function () {
            window.setTimeout(function () {
                if (ticket === loadCounter) {
                    $icon.removeClass('n8ndispatch-spin');
                }
            }, Math.max(0, 600 - (Date.now() - started)));
        };

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
        }).then(stopSpin);
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

        // Held back while the DialogHSM template list is awaited (applyTemplate / showManual scan then), so the button rows are known.
        if (!listSettled && 'dialoghsm' === providerValue() && mQuery('#n8ndispatch-hsm-config').data('templates-url') && numberSelect().val()) {
            initHeld = true;

            return;
        }

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
    // Copy button of the 360Dialog JSON box (top right, shown on hover).
    mQuery(document).off('click.n8ndispatchJson').on('click.n8ndispatchJson', '#n8ndispatch-hsm-json-copy', function () {
        var text  = mQuery('#n8ndispatch-hsm-json').text();
        var $btn  = mQuery(this);
        var done  = function () {
            $btn.addClass('is-open');
            setTimeout(function () { $btn.removeClass('is-open'); }, 1500);
        };

        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(done);

            return;
        }

        var $tmp = mQuery('<textarea></textarea>').val(text).css({position: 'fixed', opacity: 0}).appendTo('body');
        $tmp[0].select();
        document.execCommand('copy');
        $tmp.remove();
        done();
    });

})(window.Mautic, window.mQuery);
