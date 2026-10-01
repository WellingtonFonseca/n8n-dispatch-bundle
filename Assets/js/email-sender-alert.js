(function (Mautic, mQuery) {
    if (typeof Mautic === 'undefined' || typeof mQuery === 'undefined' || !Mautic.n8ndispatchShared) {
        return;
    }

    var shared = Mautic.n8ndispatchShared;

    var FIELD_SELECTORS = {
        fromName:    '[name="emailform[fromName]"]',
        fromAddress: '[name="emailform[fromAddress]"]',
    };

    // data-onload-callback convention (see CoreBundle Assets/js/1a.content.js). The element carrying it is added
    // inside the Advanced tab of the Email form (EventListener/EmailSenderAlertSubscriber.php), which exists for new
    // and existing emails alike, unlike the Variables N8N tab.
    //
    // While From name / From address (both required, see Form/Extension/EmailSenderRequiredExtension.php) are blank:
    // a yellow alert above the tabs, visible whichever tab is open, plus the pulsing dot on the Advanced tab. Core's
    // Email form has no extension point above the tabs, so the alert is inserted into the DOM; if that markup ever
    // changes it is simply not shown.
    Mautic.n8nDispatchInitEmailSenderAlert = function (el) {
        var $el   = mQuery(el);
        var $form = $el.closest('form');

        if (0 === $form.length) {
            return;
        }

        var labels = {fromName: $el.data('name-label'), fromAddress: $el.data('address-label')};
        var texts  = {text: $el.data('text'), tab: $el.data('tab')};

        function currentValues() {
            var values = {};

            mQuery.each(FIELD_SELECTORS, function (field, selector) {
                values[field] = $form.find(selector).val();
            });

            return values;
        }

        function update() {
            mQuery('#n8ndispatch-sender-alert').remove();
            $form.find('a[href="#advanced-container"] .n8ndispatch-tab-dot').remove();

            var missing = shared.missingSenderFields(currentValues());

            if (0 === missing.length) {
                return;
            }

            $form.find('a[href="#advanced-container"]').append(shared.buildTabDotHtml());

            var $tabs = $form.find('ul.nav-tabs-contained').first();

            if (0 < $tabs.length) {
                $tabs.before(shared.buildSenderAlertHtml(texts, missing.map(function (field) {
                    return labels[field];
                })));
            }
        }

        var selectors = Object.keys(FIELD_SELECTORS).map(function (field) {
            return FIELD_SELECTORS[field];
        }).join(',');

        // Live: the alert goes away as the user fills the fields, and comes back if one is cleared.
        $form.off('input.n8nsender change.n8nsender keyup.n8nsender')
            .on('input.n8nsender change.n8nsender keyup.n8nsender', selectors, update);

        update();
    };
})(window.Mautic, window.mQuery);
