/**
 * The alert settings as their own tab on Settings > Plugins > N8N Dispatch. Core draws the tabs of that modal by itself
 * and gives a plugin no way to add one, so the fields are built in the Features form (Service/Alert/AlertSettingsForm.php)
 * and the first one carries data-onload-callback: this script moves every field marked data-alerts-field into a tab of its
 * own once the modal is up. Without the script the fields just stay on the Features tab.
 */
(function (window) {
    'use strict';

    var Mautic = window.Mautic;
    var PANE_ID = 'n8ndispatch-alerts-container';

    Mautic.n8ndispatchAlertsTab = function (element) {
        var $ = window.mQuery;
        var $first = $(element);
        var $content = $first.closest('.tab-content');
        // core draws the tab list just before the form that holds the tab content
        var $tabs = $content.closest('form').prev('ul.nav-tabs');

        if (!$content.length || !$tabs.length || $('#' + PANE_ID).length) {
            return;
        }

        var $pane = $('<div class="tab-pane fade bdr-w-0"></div>').attr('id', PANE_ID);

        $content.find('[data-alerts-field]').each(function () {
            var $row = $(this).closest('.form-group');

            if ($row.length && !$.contains($pane[0], $row[0])) {
                $pane.append($row);
            }
        });

        var $tab = $('<li id="n8ndispatch-alerts-tab"></li>')
            .append($('<a role="tab" data-toggle="tab"></a>').attr('href', '#' + PANE_ID).text($first.attr('data-tab-label')));
        // the change log is always the last tab, whichever script runs first (its own script appends it at the end)
        var $changelog = $tabs.children('#n8ndispatch-changelog-tab');

        if ($changelog.length) {
            $tab.insertBefore($changelog);
        } else {
            $tab.appendTo($tabs);
        }

        $pane.appendTo($content);
    };
}(window));
