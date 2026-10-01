(function (Mautic, mQuery) {
    if (typeof Mautic === 'undefined' || typeof mQuery === 'undefined') {
        return;
    }

    var CONTAINER_ID = 'n8ndispatch-variables-container';

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

    // texts = {text, tab}, both already translated by Twig (so they follow the system language). text carries a
    // %tab% placeholder, replaced by the tab's name in bold, which opens that tab when clicked. Deliberately
    // generic: it doesn't list the variables, the tab itself shows which ones are missing.
    function buildUnmappedAlertHtml(texts) {
        var tab = '<a href="#n8ndispatch-email-tab-container" class="n8ndispatch-open-variables-tab"><b>'
            + escapePlain(texts.tab) + '</b></a>';

        return '<div class="alert alert-warning" id="n8ndispatch-unmapped-alert">'
            + escapePlain(texts.text).replace('%tab%', function () { return tab; })
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
            + '<option value="static"' + ('static' === source ? ' selected' : '') + '>Static value</option>'
            + '<option value="field"' + ('field' === source ? ' selected' : '') + '>Contact field</option>'
            + '<option value="custom_object"' + ('custom_object' === source ? ' selected' : '') + '>Custom Object field</option>'
            + '</select>';

        var valueBlock = '<input type="text" class="form-control n8ndispatch-var-value" value="' + escapeHtml(entry.value) + '"' + display.static + '>'
            + '<select class="form-control n8ndispatch-var-field"' + display.field + '>'
            + selectOptionsHtml(fields, entry.field)
            + '</select>'
            + '<div class="n8ndispatch-var-custom-object"' + display.customObject + '>'
            + '<select class="form-control n8ndispatch-var-custom-object-select mb-xs">'
            + '<option value="">Select a Custom Object</option>'
            + customObjectOptionsHtml(customObjects, entry.customObject)
            + '</select>'
            + '<select class="form-control n8ndispatch-var-custom-object-field">'
            + '<option value="">Select a field</option>'
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
        buildTabDotHtml:             buildTabDotHtml,
        buildUnmappedAlertHtml:      buildUnmappedAlertHtml,
        clearVariables:              clearVariables,
        findUnmapped:                findUnmapped,
        isEntrySet:                  isEntrySet,
        renderVariablesFromResponse: renderVariablesFromResponse,
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
                '<option value="">Select a field</option>' + customObjectFieldOptionsHtml(customObjects, selectedAlias, '')
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
