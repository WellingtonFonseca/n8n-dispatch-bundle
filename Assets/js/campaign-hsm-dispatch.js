(function (Mautic, mQuery) {
    if (typeof Mautic === 'undefined' || typeof mQuery === 'undefined' || !Mautic.n8ndispatchShared) {
        return;
    }

    var shared         = Mautic.n8ndispatchShared;
    var ADD_CONTROL_ID = 'n8ndispatch-hsm-add-control';

    // HSM templates (WhatsApp's own syntax) take positional variables —
    // "Olá $1, aqui está a sua fatura de $2" — not Mautic's named
    // {{variable}} tokens. So unlike Email/SMS, a variable here has no
    // name to type or scan: variablesJson's keys are just "1", "2", "3",
    // ... in order, one per "Add variable" click, and stay contiguous —
    // removing one renumbers everything after it, so a removed middle
    // variable can never leave a gap (e.g. $1, $3 with no $2).
    function sortedEntries(existing) {
        return Object.keys(existing)
            .map(Number)
            .sort(function (a, b) { return a - b; })
            .map(function (n) { return existing[String(n)]; });
    }

    function renderFromHidden($container, $hidden) {
        var existing = {};
        try {
            existing = JSON.parse($hidden.val() || '{}');
        } catch (e) {
            existing = {};
        }

        var fields        = $container.data('fields') || {};
        var customObjects = $container.data('customObjects') || {};
        var entries       = sortedEntries(existing);

        var html = '';
        entries.forEach(function (entry, i) {
            html += shared.variableRowHtml(String(i + 1), entry, fields, customObjects, true);
        });

        $container.html(html);
        shared.wireVariableRowEvents($container, $hidden, customObjects);

        // wireVariableRowEvents() already binds '.n8ndispatch-var-remove'
        // to a plain "delete this row" handler (fine for Email/SMS, whose
        // names are independent of each other) — replaced here with one
        // that also renumbers everything after the removed position, so
        // the sequence sent to n8n never skips a number.
        $container.find('.n8ndispatch-var-remove').off('click').on('click', function () {
            var $row     = mQuery(this).closest('.n8ndispatch-var-row');
            var removeAt = parseInt($row.data('var-name'), 10) - 1;

            entries.splice(removeAt, 1);

            var renumbered = {};
            entries.forEach(function (entry, i) {
                renumbered[String(i + 1)] = entry;
            });

            $hidden.val(JSON.stringify(renumbered));
            renderFromHidden($container, $hidden);
        });
    }

    function addVariable($container, $hidden) {
        var existing = {};
        try {
            existing = JSON.parse($hidden.val() || '{}');
        } catch (e) {
            existing = {};
        }

        var nextPosition = Object.keys(existing).length + 1;
        existing[String(nextPosition)] = {source: 'static', value: '', field: '', customObject: '', customObjectField: ''};
        $hidden.val(JSON.stringify(existing));

        renderFromHidden($container, $hidden);
    }

    // Created once per form-open, kept outside the variables container so
    // renderFromHidden()'s $container.html() rewrite never wipes it.
    //
    // Row > col-xs-12, same structure getContainer() itself uses in
    // campaign-email-dispatch.js: the col-xs-12 is what supplies the
    // left/right gutter against the modal panel edges (Mautic's own
    // form_row block does the same — see that file's own comment) — a
    // bare row without it sits flush against the wall.
    function ensureAddControl($container, $hidden) {
        if (mQuery('#' + ADD_CONTROL_ID).length) {
            return;
        }

        // Below the variable rows, not above them — the button adds a
        // row underneath, so that's where it visually belongs.
        // margin-top keeps this row from sitting flush against the last
        // variable row rendered right before it in $container.
        var $row = mQuery(
            '<div class="row" id="' + ADD_CONTROL_ID + '" style="margin-top: 15px;">'
            + '<div class="col-xs-12"><button type="button" class="btn btn-primary">Add variable</button></div>'
            + '</div>'
        );

        // $container is the INNER col-xs-12 div getContainer() returns
        // (see campaign-email-dispatch.js), not the outer .row wrapping
        // it — inserting next to $container directly would nest this new
        // .row one level too deep, inside that outer .row with no col-x
        // between them, which is what broke the gutter/alignment the
        // first time. closest('.row') climbs back out to the right level
        // so this new block sits as a sibling of that outer .row instead.
        // .after(), not .before(): $container's own innerHTML is replaced
        // wholesale on every renderFromHidden() call, but that never
        // moves $container itself (or this sibling) — inserting after it
        // once here is enough to keep the button below on every re-render.
        $container.closest('.row').after($row);

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
