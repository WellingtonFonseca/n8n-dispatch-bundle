(function (Mautic, mQuery) {
    if (typeof Mautic === 'undefined' || typeof mQuery === 'undefined') {
        return;
    }

    var CONTAINER_ID = 'n8ndispatch-variables-container';

    // A text that lives in the browser, in the system language: Mautic fills window.mauticLang from the plugin's
    // javascript.ini (Translations/<locale>/javascript.ini), merged over the en_US one. The English text passed here
    // is the fallback when the key is missing (or when this runs without a page, as in the tests).
    function translate(key, fallback) {
        var lang = window.mauticLang;

        return (lang && lang[key]) || fallback;
    }

    function escapeHtml(str) {
        return mQuery('<div>').text(str == null ? '' : str).html();
    }

    // Mautic's own form_row block wraps every field as
    // <div class="row"><div class="form-group col-xs-12">...</div></div> —
    // the col-xs-12 is what gives a row its left/right gutter. A container
    // inserted without that wrapper sits flush against the panel edges.
    function getContainer($emailField) {
        var $container = mQuery('#' + CONTAINER_ID);

        if (0 === $container.length) {
            var $row = mQuery(
                '<div class="row">'
                + '<div id="' + CONTAINER_ID + '" class="col-xs-12"></div>'
                + '</div>'
            );
            $emailField.closest('.row').after($row);
            $container = $row.find('#' + CONTAINER_ID);
        }

        return $container;
    }

    function getHiddenField($emailField) {
        return $emailField.closest('form').find('.n8ndispatch-variables-json');
    }

    function selectOptionsHtml(choices, selectedKey) {
        var html = '';

        mQuery.each(choices || {}, function (key, label) {
            var selected = key === selectedKey ? ' selected' : '';
            html += '<option value="' + escapeHtml(key) + '"' + selected + '>' + escapeHtml(label) + '</option>';
        });

        return html;
    }

    function customObjectOptionsHtml(customObjects, selectedAlias) {
        var choices = {};

        mQuery.each(customObjects || {}, function (alias, def) {
            choices[alias] = def.label;
        });

        return selectOptionsHtml(choices, selectedAlias);
    }

    function customObjectFieldOptionsHtml(customObjects, selectedObject, selectedField) {
        var def = (customObjects || {})[selectedObject];

        return selectOptionsHtml(def ? def.fields : {}, selectedField);
    }

    var DEFAULT_ENTRY = {source: 'static', value: '', field: '', customObject: '', customObjectField: ''};

    // A variable row counts as "mapped" only when the user actually filled it in. Every variable found in a
    // template gets a row with the default static + blank value (and that default is saved as-is), so merely
    // having a key in the saved mapping says nothing.
    function isEntrySet(entry) {
        if (!entry || 'object' !== typeof entry) {
            return false;
        }

        var source = entry.source || 'static';

        if ('field' === source) {
            return !!entry.field;
        }

        if ('custom_object' === source) {
            return !!entry.customObject && !!entry.customObjectField;
        }

        return '' !== String(null == entry.value ? '' : entry.value).trim();
    }

    function findUnmapped(names, mapping) {
        var saved = mapping || {};

        return (names || []).filter(function (name) {
            return !isEntrySet(saved[name]);
        });
    }

    // Plain-string escape (no DOM), so the alert builder below stays testable outside a browser.
    function escapePlain(str) {
        return String(null == str ? '' : str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    // Pulsing yellow dot appended to the "Variables N8N" tab's header while some variables are unmapped; the
    // animation lives in Assets/css/campaign-status-badge.css (.n8ndispatch-tab-dot).
    function buildTabDotHtml() {
        return '<span class="n8ndispatch-tab-dot" aria-hidden="true"></span>';
    }

    // A yellow alert with the given id and an already-translated text, escaped, nothing else in it.
    function buildPlainAlertHtml(id, text) {
        return '<div class="alert alert-warning" id="' + id + '">' + escapePlain(text) + '</div>';
    }

    // The same alert inside the <div class="row"><div class="col-xs-12"> wrapper core's form_row puts around every
    // field, which is what gives it the left/right gutter when it sits between two fields. The row's id is the
    // alert's plus "-row", so removing the row removes the wrapper too.
    function buildRowAlertHtml(id, text) {
        return '<div class="row" id="' + id + '-row"><div class="col-xs-12">' + buildPlainAlertHtml(id, text) + '</div></div>';
    }

    // Whether a template text has a {{ ... }} placeholder that isn't only digits: the same rule as
    // TemplateVariableScanner::findNonNumeric() in PHP, evaluated here so the alert can follow the text itself
    // instead of waiting for a server answer.
    function hasNonNumericPlaceholder(text) {
        var pattern = /\{\{\s*([^{}]*?)\s*\}\}/g;
        var source  = String(null == text ? '' : text);
        var match;

        while (null !== (match = pattern.exec(source))) {
            if (!/^\d+$/.test(match[1])) {
                return true;
            }
        }

        return false;
    }

    // The generic "variables must be numeric" alert of the HSM and SMS template forms, shown (or removed) between the
    // message text and the variable rows: that is where the user is when it matters, in the order the screen is filled
    // in. It goes right after the text field's row, and the variable rows are inserted after that same row
    // (getContainer() above). Inserting "right after" puts the later one first, so callers render the variable rows
    // first and call this second, which leaves the alert above them.
    function setNumericAlert($textField, id, text, show) {
        mQuery('#' + id + '-row').remove();

        if (show && text) {
            $textField.closest('.row').after(buildRowAlertHtml(id, text));
        }
    }

    // Whether a template text has numeric placeholders whose row in the saved mapping (the hidden field's JSON) is not
    // filled in yet: the same rule as MappedVariables in PHP. It steps aside while the text still has a non-numeric
    // placeholder (the numeric alert is the one to show then), like the save check does.
    function hasUnmappedPlaceholder(text, mappingJson) {
        var source = String(null == text ? '' : text);

        if (hasNonNumericPlaceholder(source)) {
            return false;
        }

        var mapping = {};
        try {
            mapping = JSON.parse(mappingJson || '{}') || {};
        } catch (e) {
            mapping = {};
        }

        var names   = [];
        var pattern = /\{\{\s*(\d+)\s*\}\}/g;
        var match;

        while (null !== (match = pattern.exec(source))) {
            if (-1 === names.indexOf(match[1])) {
                names.push(match[1]);
            }
        }

        return 0 < findUnmapped(names, mapping).length;
    }

    // The generic "every variable needs a value" alert of the HSM and SMS template forms. Same place as the numeric
    // one (right after the text field's row, above the variable rows), so the same rules apply.
    function setUnmappedAlert($textField, id, text, show) {
        setNumericAlert($textField, id, text, show);
    }

    // Decided from the text field and the rows' hidden field, as they are right now.
    function hasUnmappedVariables($textField) {
        return hasUnmappedPlaceholder($textField.val(), getHiddenField($textField).val());
    }

    // Calls fn each time the variable rows change (syncHiddenField() triggers this on the hidden field, which bubbles
    // up to the form). Bound again on every call without piling up handlers.
    function onMappingSynced($textField, fn) {
        $textField.closest('form').off('n8ndispatch:synced.templatealert').on('n8ndispatch:synced.templatealert', fn);
    }

    var VARIABLES_TAB = '#n8ndispatch-email-tab-container';
    var ADVANCED_TAB  = '#advanced-container';

    // The tab's name in bold, as a link that opens that tab (the click handler is in email-tab-variables.js).
    function tabLinkHtml(target, label) {
        return '<a href="' + target + '" class="n8ndispatch-open-tab"><b>' + escapePlain(label) + '</b></a>';
    }

    // The notice about variables that don't start with the required prefix. Same shape as the unmapped one below
    // ({text, tab}, %tab% replaced by the tab's name in bold), only the id differs.
    function buildPrefixAlertHtml(texts) {
        return buildTabAlertHtml('n8ndispatch-prefix-alert', texts);
    }

    // Which notice the edit page shows. A wrong prefix comes first: those names have to be renamed before mapping
    // them matters (the save check works the same way).
    function noticeFor(invalidPrefixCount, unmappedCount) {
        if (0 < invalidPrefixCount) {
            return 'prefix';
        }

        return 0 < unmappedCount ? 'unmapped' : null;
    }

    // texts = {text, tab}, both already translated by Twig (so they follow the system language). text carries a
    // %tab% placeholder, replaced by the tab's name in bold, which opens that tab when clicked. Deliberately
    // generic: it doesn't list the variables, the tab itself shows which ones are missing.
    function buildTabAlertHtml(id, texts) {
        var tab = tabLinkHtml(VARIABLES_TAB, texts.tab);

        return '<div class="alert alert-warning" id="' + id + '">'
            + escapePlain(texts.text).replace('%tab%', function () { return tab; })
            + '</div>';
    }

    function buildUnmappedAlertHtml(texts) {
        return buildTabAlertHtml('n8ndispatch-unmapped-alert', texts);
    }

    var SENDER_FIELDS = ['fromName', 'fromAddress'];

    // The sender fields (Advanced tab) that are blank, in form order. values = {fromName, fromAddress}.
    function missingSenderFields(values) {
        return SENDER_FIELDS.filter(function (field) {
            var value = values ? values[field] : null;

            return '' === String(null == value ? '' : value).trim();
        });
    }

    // texts = {text, tab} translated by Twig; text carries %fields% (the missing fields' labels, as the screen
    // names them) and %tab% (the Advanced tab's name, in bold, opening that tab).
    function buildSenderAlertHtml(texts, labels) {
        var fields = labels.map(escapePlain).join(', ');
        var tab    = tabLinkHtml(ADVANCED_TAB, texts.tab);

        return '<div class="alert alert-warning" id="n8ndispatch-sender-alert">'
            + escapePlain(texts.text)
                .replace('%fields%', function () { return fields; })
                .replace('%tab%', function () { return tab; })
            + '</div>';
    }

    function variableRowHtml(name, existingEntry, fields, customObjects) {
        var entry  = mQuery.extend({}, DEFAULT_ENTRY, existingEntry || {});
        var source = entry.source || 'static';

        var display = {
            static:       'static' === source ? '' : ' style="display:none"',
            field:        'field' === source ? '' : ' style="display:none"',
            customObject: 'custom_object' === source ? '' : ' style="display:none"',
        };

        var sourceSelect = '<select class="form-control n8ndispatch-var-source">'
            + '<option value="static"' + ('static' === source ? ' selected' : '') + '>' + escapePlain(translate('mautic.n8ndispatch.js.source.static', 'Static value')) + '</option>'
            + '<option value="field"' + ('field' === source ? ' selected' : '') + '>' + escapePlain(translate('mautic.n8ndispatch.js.source.field', 'Contact field')) + '</option>'
            + '<option value="custom_object"' + ('custom_object' === source ? ' selected' : '') + '>' + escapePlain(translate('mautic.n8ndispatch.js.source.custom_object', 'Custom Object field')) + '</option>'
            + '</select>';

        var valueBlock = '<input type="text" class="form-control n8ndispatch-var-value" value="' + escapeHtml(entry.value) + '"' + display.static + '>'
            + '<select class="form-control n8ndispatch-var-field"' + display.field + '>'
            + selectOptionsHtml(fields, entry.field)
            + '</select>'
            + '<div class="n8ndispatch-var-custom-object"' + display.customObject + '>'
            + '<select class="form-control n8ndispatch-var-custom-object-select mb-xs">'
            + '<option value="">' + escapePlain(translate('mautic.n8ndispatch.js.select_custom_object', 'Select a Custom Object')) + '</option>'
            + customObjectOptionsHtml(customObjects, entry.customObject)
            + '</select>'
            + '<select class="form-control n8ndispatch-var-custom-object-field">'
            + '<option value="">' + escapePlain(translate('mautic.n8ndispatch.js.select_field', 'Select a field')) + '</option>'
            + customObjectFieldOptionsHtml(customObjects, entry.customObject, entry.customObjectField)
            + '</select>'
            + '</div>';

        return '<div class="form-group n8ndispatch-var-row" data-var-name="' + escapeHtml(name) + '">'
            + '<label class="control-label">{{' + escapeHtml(name) + '}}</label>'
            + '<div class="row">'
            + '<div class="col-xs-4">' + sourceSelect + '</div>'
            + '<div class="col-xs-8">' + valueBlock + '</div>'
            + '</div>'
            + '</div>';
    }

    // Shared with campaign-sms-dispatch.js and email-tab-variables.js: turns
    // an ajaxActionRequest response of shape {variables, fields,
    // customObjects} into the rendered variable-source rows for whichever
    // field anchors them ($anchorField — the SMS textarea there, the
    // "Variables" tab's container here). Only ever writes into the hidden
    // field kept in the DOM — persisting it anywhere (a form's own Save
    // button, or email-tab-variables.js's submit-time AJAX call) is each
    // caller's own concern, not this shared renderer's.
    function renderVariablesFromResponse($anchorField, response) {
        var $container = getContainer($anchorField);
        var $hidden     = getHiddenField($anchorField);

        if (!response || !response.success) {
            return;
        }

        var existing = {};
        try {
            existing = JSON.parse($hidden.val() || '{}');
        } catch (e) {
            existing = {};
        }

        var html = '';
        mQuery.each(response.variables || [], function (i, name) {
            html += variableRowHtml(name, existing[name], response.fields, response.customObjects, false);
        });

        $container.html(html);
        syncHiddenField($container, $hidden);
        wireVariableRowEvents($container, $hidden, response.customObjects);
    }

    function clearVariables($anchorField) {
        getContainer($anchorField).empty();
        getHiddenField($anchorField).val('{}');
    }

    // getContainer/getHiddenField/variableRowHtml used to be exposed here
    // too, for campaign-hsm-dispatch.js's own hand-built (manually named,
    // no-text-to-scan) variable rows. Removed along with that script when
    // HSM moved to templates (Entity/HsmTemplate.php) and dropped its
    // per-campaign variable picker outright.
    Mautic.n8ndispatchShared = {
        buildPlainAlertHtml:         buildPlainAlertHtml,
        buildPrefixAlertHtml:        buildPrefixAlertHtml,
        buildRowAlertHtml:           buildRowAlertHtml,
        buildSenderAlertHtml:        buildSenderAlertHtml,
        buildTabDotHtml:             buildTabDotHtml,
        buildUnmappedAlertHtml:      buildUnmappedAlertHtml,
        clearVariables:              clearVariables,
        findUnmapped:                findUnmapped,
        hasNonNumericPlaceholder:    hasNonNumericPlaceholder,
        hasUnmappedPlaceholder:      hasUnmappedPlaceholder,
        hasUnmappedVariables:        hasUnmappedVariables,
        isEntrySet:                  isEntrySet,
        missingSenderFields:         missingSenderFields,
        noticeFor:                   noticeFor,
        onMappingSynced:             onMappingSynced,
        renderVariablesFromResponse: renderVariablesFromResponse,
        setNumericAlert:             setNumericAlert,
        setUnmappedAlert:            setUnmappedAlert,
        translate:                   translate,
    };

    function wireVariableRowEvents($container, $hidden, customObjects) {
        $container.find('.n8ndispatch-var-source').on('change', function () {
            var $row   = mQuery(this).closest('.n8ndispatch-var-row');
            var source = mQuery(this).val();

            $row.find('.n8ndispatch-var-value').toggle('static' === source);
            $row.find('.n8ndispatch-var-field').toggle('field' === source);
            $row.find('.n8ndispatch-var-custom-object').toggle('custom_object' === source);

            syncHiddenField($container, $hidden);
        });

        $container.find('.n8ndispatch-var-custom-object-select').on('change', function () {
            var $row          = mQuery(this).closest('.n8ndispatch-var-row');
            var selectedAlias = mQuery(this).val();

            $row.find('.n8ndispatch-var-custom-object-field').html(
                '<option value="">' + escapePlain(translate('mautic.n8ndispatch.js.select_field', 'Select a field')) + '</option>' + customObjectFieldOptionsHtml(customObjects, selectedAlias, '')
            );

            syncHiddenField($container, $hidden);
        });

        $container.find(
            '.n8ndispatch-var-value, .n8ndispatch-var-field, .n8ndispatch-var-custom-object-field'
        ).on('keyup change', function () {
            syncHiddenField($container, $hidden);
        });
    }

    function syncHiddenField($container, $hidden) {
        var values = {};

        $container.find('.n8ndispatch-var-row').each(function () {
            var $row   = mQuery(this);
            var name   = $row.data('var-name');
            var source = $row.find('.n8ndispatch-var-source').val();
            var entry  = mQuery.extend({}, DEFAULT_ENTRY, {source: source});

            if ('field' === source) {
                entry.field = $row.find('.n8ndispatch-var-field').val();
            } else if ('custom_object' === source) {
                entry.customObject      = $row.find('.n8ndispatch-var-custom-object-select').val();
                entry.customObjectField = $row.find('.n8ndispatch-var-custom-object-field').val();
            } else {
                entry.value = $row.find('.n8ndispatch-var-value').val();
            }

            values[name] = entry;
        });

        $hidden.val(JSON.stringify(values));
        // email-tab-variables.js listens to this to keep its "unmapped variables" alert up to date.
        $hidden.trigger('n8ndispatch:synced');
    }
})(window.Mautic, window.mQuery);
