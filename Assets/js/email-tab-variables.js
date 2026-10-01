(function (Mautic, mQuery) {
    if (typeof Mautic === 'undefined' || typeof mQuery === 'undefined' || !Mautic.n8ndispatchShared) {
        return;
    }

    var shared = Mautic.n8ndispatchShared;

    // A single, page-wide capture-phase click listener (bound once, ever —
    // see the guard below), not one per form. Only acts when the clicked
    // button is inside a form Mautic.n8nDispatchInitEmailTabVariables has
    // already flagged (formEl.n8ndispatchEmailId set) — harmless on every
    // other page's Save/Apply buttons (Campaign builder, Contact edit,
    // ...), same "loaded globally, only reacts where relevant" pattern as
    // the rest of this plugin's JS.
    //
    // Bound on 'document', with useCapture = true: a capture-phase
    // listener on an ancestor always runs before the event reaches its
    // target, which is what actually makes this reliable — earlier,
    // binding a 'submit' listener directly on the <form> and calling
    // preventDefault() there did NOT stop Mautic's own Apply/Save
    // handling (confirmed live: the native form POST went out before our
    // saveEmailVariables call, meaning Mautic doesn't drive Apply through
    // a native 'submit' event this class can intercept that way at all —
    // it's handled straight off the button's click). Capture on
    // 'document' intercepts that click before ANY handler bound on the
    // button itself gets a chance to run, regardless of whether Mautic's
    // own handler is bound directly on the button or delegated via
    // document — capture always precedes both.
    function wireGlobalClickIntercept() {
        if (Mautic.n8ndispatchEmailTabClickWired) {
            return;
        }
        Mautic.n8ndispatchEmailTabClickWired = true;

        document.addEventListener('click', function (e) {
            var button = e.target.closest('.btn-save, .btn-apply');

            if (!button) {
                return;
            }

            var formEl = button.closest('form');

            if (!formEl || !formEl.n8ndispatchEmailId) {
                return;
            }

            if (button.n8ndispatchAlreadyIntercepted) {
                // Our own re-dispatched click, right below — let it
                // through to whatever Mautic's own handler does.
                return;
            }

            var $hidden = mQuery(formEl).find('.n8ndispatch-variables-json');

            if (0 === $hidden.length) {
                return;
            }

            var variablesJson = $hidden.val() || '{}';

            e.preventDefault();
            e.stopImmediatePropagation();

            Mautic.ajaxActionRequest('plugin:N8nDispatch:saveEmailVariables', {
                emailId:       formEl.n8ndispatchEmailId,
                variablesJson: variablesJson,
            }, function () {
                button.n8ndispatchAlreadyIntercepted = true;
                button.click();
                button.n8ndispatchAlreadyIntercepted = false;
            });
        }, true);
    }

    // data-onload-callback convention (see CoreBundle Assets/js/1a.content.js)
    // — fires once when the Email edit page loads, same as every other
    // data-onload-callback element on that page, whether or not the
    // "Variables" tab is the one currently showing.
    Mautic.n8nDispatchInitEmailTabVariables = function (el) {
        var $el     = mQuery(el);
        var emailId = $el.data('email-id');

        if (!emailId) {
            return;
        }

        wireGlobalClickIntercept();

        var formEl = $el.closest('form').get(0);
        if (formEl) {
            // Marks this form as "ours" for the global click listener
            // above — set on data-onload-callback re-fires too (e.g.
            // after Mautic's own in-place content refresh), which is
            // fine, it's just re-flagging the same (or a replacement)
            // form element with the same id.
            formEl.n8ndispatchEmailId = emailId;
        }

        var noneHtml = $el.html();

        // Yellow alert above the tabs (outside any of them, so it shows whichever tab is open): a generic notice
        // that some variables haven't been mapped yet. Core's Email form has no extension point above the tabs,
        // so it's inserted into the DOM; if that markup ever changes the alert is simply not shown.
        var alertTexts = {
            text: $el.data('alert-text'),
            tab:  $el.data('alert-tab'),
        };
        var $form   = mQuery(formEl || []);
        var $hidden = $form.find('.n8ndispatch-variables-json');

        function updateUnmappedAlert() {
            mQuery('#n8ndispatch-unmapped-alert').remove();
            $form.find('.n8ndispatch-tab-dot').remove();

            var mapping = {};
            try {
                mapping = JSON.parse($hidden.val() || '{}');
            } catch (e) {
                mapping = {};
            }

            var names = [];
            $el.find('.n8ndispatch-var-row').each(function () {
                names.push(mQuery(this).data('var-name'));
            });

            if (0 === shared.findUnmapped(names, mapping).length) {
                return;
            }

            // Pulsing dot on the tab's own header, so it stands out from the other tabs.
            $form.find('a[href="#n8ndispatch-email-tab-container"]').append(shared.buildTabDotHtml());

            var $tabs = $form.find('ul.nav-tabs-contained').first();

            if (0 < $tabs.length) {
                $tabs.before(shared.buildUnmappedAlertHtml(alertTexts));
            }
        }

        $hidden.off('n8ndispatch:synced.n8nalert').on('n8ndispatch:synced.n8nalert', updateUnmappedAlert);

        function refreshVariables(html) {
            var data = {emailId: emailId};

            if (typeof html === 'string') {
                data.html = html;
            }

            Mautic.ajaxActionRequest('plugin:N8nDispatch:getEmailVariables', data, function (response) {
                if (!response || !response.success) {
                    return;
                }

                // No {{variable}} placeholders found: show the translated
                // "none found" message Twig rendered by default (Resources/
                // views/SubscribedEvents/EmailTab/content.html.twig) and
                // reset the hidden field, so nothing stale gets saved.
                if (!response.variables || 0 === response.variables.length) {
                    $el.html(noneHtml);
                    shared.clearVariables($el);
                    updateUnmappedAlert();
                    return;
                }

                shared.renderVariablesFromResponse($el, response);
            });
        }

        refreshVariables();
        $el.data('n8ndispatchRefresh', refreshVariables);
    };

    // The alert's link opens the "Variables N8N" tab. Delegated on document, bound once.
    if (!Mautic.n8ndispatchOpenTabWired) {
        Mautic.n8ndispatchOpenTabWired = true;

        mQuery(document).on('click', '.n8ndispatch-open-variables-tab', function (e) {
            e.preventDefault();
            mQuery('a[href="#n8ndispatch-email-tab-container"]').tab('show');
        });
    }

    // Closing the GrapesJS builder (the check/"Close" button) copies the
    // new HTML into textarea.builder-html and then triggers 'builder:hide'
    // on '.builder' (GrapesJsBuilderBundle builder.service.js) — before
    // the Email form is saved, so the tab re-scans that textarea instead
    // of the stale saved customHtml. Delegated on document, bound once.
    if (!Mautic.n8ndispatchBuilderHideWired) {
        Mautic.n8ndispatchBuilderHideWired = true;

        mQuery(document).on('builder:hide', function () {
            var refresh = mQuery('#n8ndispatch-variables-container').data('n8ndispatchRefresh');

            if (refresh) {
                refresh(mQuery('textarea.builder-html').val() || '');
            }
        });
    }
})(window.Mautic, window.mQuery);
