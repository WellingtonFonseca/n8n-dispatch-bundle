(function (Mautic, mQuery) {
    if (typeof Mautic === 'undefined' || typeof mQuery === 'undefined' || !Mautic.n8ndispatchShared) {
        return;
    }

    var shared          = Mautic.n8ndispatchShared;
    var ADD_CONTROL_ID  = 'n8ndispatch-hsm-add-control';
    var NEW_NAME_ID     = 'n8ndispatch-hsm-new-var-name';
    var NAME_PATTERN    = /^[a-zA-Z0-9_]+$/;

    // No text to scan for {{variable}} placeholders on this channel (see
    // Form/Type/HsmDispatchActionType.php's docblock) — the hidden
    // variablesJson field is itself the source of truth for which rows to
    // render, not an ajaxActionRequest response the way Email/SMS work.
    function renderFromHidden($container, $hidden) {
        var existing = {};
        try {
            existing = JSON.parse($hidden.val() || '{}');
        } catch (e) {
            existing = {};
        }

        var fields        = $container.data('fields') || {};
        var customObjects = $container.data('customObjects') || {};

        var html = '';
        mQuery.each(existing, function (name, entry) {
            html += shared.variableRowHtml(name, entry, fields, customObjects, true);
        });

        $container.html(html);
        shared.wireVariableRowEvents($container, $hidden, customObjects);
    }

    function addVariable($container, $hidden) {
        var $input = mQuery('#' + NEW_NAME_ID);
        var name   = (($input.val() || '') + '').trim();

        if ('' === name || !NAME_PATTERN.test(name)) {
            alert('Variable name must contain only letters, numbers or underscore.');

            return;
        }

        var existing = {};
        try {
            existing = JSON.parse($hidden.val() || '{}');
        } catch (e) {
            existing = {};
        }

        if (Object.prototype.hasOwnProperty.call(existing, name)) {
            alert('A variable named "' + name + '" already exists.');

            return;
        }

        existing[name] = {source: 'static', value: '', field: '', customObject: '', customObjectField: ''};
        $hidden.val(JSON.stringify(existing));
        $input.val('');

        renderFromHidden($container, $hidden);
    }

    // Created once per form-open, kept outside the variables container so
    // renderFromHidden()'s $container.html() rewrite never wipes it.
    //
    // Nested row > col-xs-12 > row > col-xs-8/col-xs-4, same structure
    // getContainer() itself uses in campaign-email-dispatch.js: the outer
    // col-xs-12 is what supplies the left/right gutter against the modal
    // panel edges (Mautic's own form_row block does the same — see that
    // file's own comment) — a bare row > col-x without it sits flush
    // against the wall.
    function ensureAddControl($container, $hidden) {
        if (mQuery('#' + ADD_CONTROL_ID).length) {
            return;
        }

        var $row = mQuery(
            '<div class="row" id="' + ADD_CONTROL_ID + '">'
            + '<div class="col-xs-12">'
            + '<div class="row">'
            + '<div class="col-xs-8"><input type="text" class="form-control" id="' + NEW_NAME_ID + '" placeholder="Variable name, e.g. nome"></div>'
            + '<div class="col-xs-4"><button type="button" class="btn btn-primary btn-block">Add variable</button></div>'
            + '</div>'
            + '</div>'
            + '</div>'
        );

        // $container is the INNER col-xs-12 div getContainer() returns
        // (see campaign-email-dispatch.js), not the outer .row wrapping
        // it — inserting before $container directly would nest this new
        // .row one level too deep, inside that outer .row with no col-x
        // between them, which is what broke the gutter/alignment the
        // first time. closest('.row') climbs back out to the right level
        // so this new block sits as a sibling of that outer .row instead.
        $container.closest('.row').before($row);

        $row.find('button').on('click', function () {
            addVariable($container, $hidden);
        });
    }

    // data-onload-callback convention (see CoreBundle Assets/js/1a.content.js) —
    // fires once when the "Send via n8n (HSM)" event's form is (re)rendered.
    Mautic.n8nDispatchInitHsmVariables = function (el) {
        var $anchorField = mQuery(el);
        var $container   = shared.getContainer($anchorField);
        var $hidden      = shared.getHiddenField($anchorField);

        Mautic.ajaxActionRequest('plugin:N8nDispatch:getHsmContext', {}, function (response) {
            if (!response || !response.success) {
                return;
            }

            $container.data('fields', response.fields);
            $container.data('customObjects', response.customObjects);

            renderFromHidden($container, $hidden);
            ensureAddControl($container, $hidden);
        });
    };
})(window.Mautic, window.mQuery);
