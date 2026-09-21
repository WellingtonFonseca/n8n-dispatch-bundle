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

    function variableRowHtml(name, existingEntry, fields, customObjects) {
        var entry  = mQuery.extend({}, DEFAULT_ENTRY, existingEntry || {});
        var source = entry.source || 'static';

        var display = {
            static:       'static' === source ? '' : ' style="display:none"',
            field:        'field' === source ? '' : ' style="display:none"',
            customObject: 'custom_object' === source ? '' : ' style="display:none"',
        };

        return '<div class="form-group n8ndispatch-var-row" data-var-name="' + escapeHtml(name) + '">'
            + '<label class="control-label">{{' + escapeHtml(name) + '}}</label>'
            + '<div class="row">'
            + '<div class="col-xs-4">'
            + '<select class="form-control n8ndispatch-var-source">'
            + '<option value="static"' + ('static' === source ? ' selected' : '') + '>Static value</option>'
            + '<option value="field"' + ('field' === source ? ' selected' : '') + '>Contact field</option>'
            + '<option value="custom_object"' + ('custom_object' === source ? ' selected' : '') + '>Custom Object field</option>'
            + '</select>'
            + '</div>'
            + '<div class="col-xs-8">'
            + '<input type="text" class="form-control n8ndispatch-var-value" value="' + escapeHtml(entry.value) + '"' + display.static + '>'
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
            + '</div>'
            + '</div>'
            + '</div>'
            + '</div>';
    }

    // Shared with campaign-sms-dispatch.js: turns an ajaxActionRequest
    // response of shape {variables, fields, customObjects} into the
    // rendered variable-source rows for whichever field anchors them
    // ($anchorField — the Email picker there, the SMS textarea here).
    // Kept off Mautic.n8ndispatchShared rather than duplicated per channel,
    // since both channels' AJAX actions already return the same shape.
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
            html += variableRowHtml(name, existing[name], response.fields, response.customObjects);
        });

        $container.html(html);
        syncHiddenField($container, $hidden);
        wireVariableRowEvents($container, $hidden, response.customObjects);
    }

    function clearVariables($anchorField) {
        getContainer($anchorField).empty();
        getHiddenField($anchorField).val('{}');
    }

    function renderVariableInputs($emailField) {
        var emailId = $emailField.val();

        if (!emailId) {
            clearVariables($emailField);

            return;
        }

        Mautic.ajaxActionRequest('plugin:N8nDispatch:getEmailVariables', {emailId: emailId}, function (response) {
            renderVariablesFromResponse($emailField, response);
        });
    }

    Mautic.n8ndispatchShared = {
        getContainer:               getContainer,
        getHiddenField:             getHiddenField,
        clearVariables:             clearVariables,
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
    }

    // data-onload-callback convention (see CoreBundle Assets/js/1a.content.js) —
    // Mautic calls Mautic.<name>(el) for every element carrying this attribute
    // whenever the containing form/panel is (re)rendered, e.g. opening an
    // existing "Send via n8n (Email)" campaign step for editing.
    Mautic.n8nDispatchInitVariables = function (el) {
        renderVariableInputs(mQuery(el));
    };

    // Plain onchange handler for when the user picks a different Email while
    // the form is already open.
    Mautic.n8nDispatchOnEmailChange = function (el) {
        renderVariableInputs(mQuery(el));
    };
})(window.Mautic, window.mQuery);
