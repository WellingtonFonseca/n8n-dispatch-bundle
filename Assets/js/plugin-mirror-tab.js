/**
 * The Mirror settings as their own tab, right of Features, on Settings > Plugins > N8N Dispatch. Core gives a plugin no tab,
 * so the fields are built in the Features form (Service/MirrorSettingsForm.php) and the switch carries data-onload-callback.
 * Rows are found by their mirror_* label or name, not by a marker.
 */
(function (window) {
    'use strict';

    var Mautic = window.Mautic;
    var PANE_ID = 'n8ndispatch-mirror-container';

    Mautic.n8ndispatchMirrorTab = function (element) {
        var $ = window.mQuery;
        var $first = $(element);
        var $content = $first.closest('.tab-content');
        // core draws the tab list just before the form that holds the tab content
        var $tabs = $content.closest('form').prev('ul.nav-tabs');
        var $features = $first.closest('.tab-pane');

        var $featuresTab = $tabs.find('a[href="#' + $features.attr('id') + '"]').parent();

        // no Features tab to sit beside: leave the fields where they are rather than lose them
        if (!$content.length || !$tabs.length || !$features.length || !$featuresTab.length || $('#' + PANE_ID).length) {
            return;
        }

        var $pane = $('<div class="tab-pane fade bdr-w-0"></div>').attr('id', PANE_ID);

        // the switch is a radio group: its label may not carry a for, so the inputs count too
        $content.find('label[for*="featureSettings_mirror_"], [name*="[mirror_"]').each(function () {
            var $row = $(this).closest('.form-group');

            if ($row.length && !$.contains($pane[0], $row[0])) {
                $pane.append($row);
            }
        });

        // the log of the resync runs (Resources/views/Integration/_mirror_log.html.twig) goes under the fields
        $content.find('.n8ndispatch-mirror-log-block').appendTo($pane);

        var $tab = $('<li id="n8ndispatch-mirror-tab"></li>')
            .append($('<a role="tab" data-toggle="tab"></a>').attr('href', '#' + PANE_ID).text($first.attr('data-tab-label')));

        // right of the Features tab, whichever of the tab scripts runs first
        $tab.insertAfter($featuresTab);
        $pane.appendTo($content);
    };
}(window));
