(function (Mautic, mQuery) {
    if (typeof Mautic === 'undefined' || typeof mQuery === 'undefined') {
        return;
    }

    var CONTAINER_ID = 'n8ndispatch-variables-container';

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

    function renderVariableInputs($emailField) {
        var emailId  = $emailField.val();
        var $container = getContainer($emailField);
        var $hidden     = getHiddenField($emailField);

        if (!emailId) {
            $container.empty();
            $hidden.val('{}');

            return;
        }

        Mautic.ajaxActionRequest('plugin:N8nDispatch:getEmailVariables', {emailId: emailId}, function (response) {
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
                var value = existing[name] || '';
                html += '<div class="form-group">'
                    + '<label class="control-label">{{' + name + '}}</label>'
                    + '<input type="text" class="form-control n8ndispatch-var-input" data-var-name="' + name + '" value="' + value + '">'
                    + '</div>';
            });

            $container.html(html);
            syncHiddenField($container, $hidden);

            $container.find('.n8ndispatch-var-input').on('keyup change', function () {
                syncHiddenField($container, $hidden);
            });
        });
    }

    function syncHiddenField($container, $hidden) {
        var values = {};

        $container.find('.n8ndispatch-var-input').each(function () {
            var $input = mQuery(this);
            values[$input.data('var-name')] = $input.val();
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
