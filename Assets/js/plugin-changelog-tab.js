/**
 * The change log of the plugin as its own tab on Settings > Plugins > N8N Dispatch. Core draws the tabs of that modal by itself
 * and gives a plugin no way to add one, so Resources/views/Integration/_changelog.html.twig (the 'custom' note of the first
 * tab) marks the block with data-onload-callback and this script moves it into a new tab once the modal is up. Without the
 * script the block just stays, with its title, under the fields of the first tab.
 */
(function (window) {
    'use strict';

    var Mautic = window.Mautic;
    var PANE_ID = 'n8ndispatch-changelog-container';

    Mautic.n8ndispatchChangelogTab = function (element) {
        var $ = window.mQuery;
        var $block = $(element);
        var $content = $block.closest('.tab-content');
        // core draws the tab list just before the form that holds the tab content
        var $tabs = $content.closest('form').prev('ul.nav-tabs');

        if (!$content.length || !$tabs.length || $('#' + PANE_ID).length) {
            return;
        }

        $block.find('.n8ndispatch-changelog-title').remove();

        $('<li id="n8ndispatch-changelog-tab"></li>')
            .append($('<a role="tab" data-toggle="tab"></a>').attr('href', '#' + PANE_ID).text($block.attr('data-tab-label')))
            .appendTo($tabs);

        $('<div class="tab-pane fade bdr-w-0"></div>').attr('id', PANE_ID).append($block).appendTo($content);
    };
}(window));
