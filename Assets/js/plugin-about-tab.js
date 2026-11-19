/*
 * The "Sobre" block of the plugin as the FIRST tab on Settings > Plugins > N8N Dispatch. Core draws the tabs of that modal by itself and
 * gives a plugin no way to add one, so Resources/views/Integration/_about.html.twig (the 'custom' note of the first tab) marks the block
 * with data-onload-callback="n8ndispatchAboutTab" and this moves it into a new tab, put before the others and made the active one, once the modal
 * is up. The classes are set by hand (not .tab('show')) so the result does not depend on the fade timing. Without the script the block
 * just stays under the fields of the first tab.
 */
(function (root) {
    'use strict';

    var PANE_ID = 'n8ndispatch-about-container';

    /** Core calls this (Mautic.<name>) with the block when the modal's content is up. */
    function moveToTab($, element) {
        var $block = $(element);
        var $content = $block.closest('.tab-content');
        // core draws the tab list just before the form that holds the tab content
        var $tabs = $content.closest('form').prev('ul.nav-tabs');

        if (!$content.length || !$tabs.length || $('#' + PANE_ID).length) { return false; }

        // a save that failed re-draws the modal and core keeps its first tab ("Habilitar/Autenticar", where the keys are) active: "Sobre" is then added without being active, or the error would be hidden behind it. An error on another tab was hidden before "Sobre" too.
        var failed = $content.find('.has-error').length > 0;

        if (!failed) {
            // the tab that was the first one is no longer the active one
            $tabs.children('li').removeClass('active');
            $content.children('.tab-pane').removeClass('in active');
        }

        $('<li id="n8ndispatch-about-tab"' + (failed ? '' : ' class="active"') + '></li>')
            .append($('<a role="tab" data-toggle="tab"></a>').attr('href', '#' + PANE_ID).text($block.attr('data-tab-label')))
            .prependTo($tabs);

        $('<div class="tab-pane fade' + (failed ? '' : ' in active') + ' bdr-w-0"></div>').attr('id', PANE_ID).append($block).prependTo($content);

        return true;
    }

    function install($, Mautic) {
        if (!$ || !Mautic) { return; }
        Mautic.n8ndispatchAboutTab = function (element) { moveToTab($, element); };
    }

    var api = {install: install, moveToTab: moveToTab};

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    } else {
        root.n8ndispatchAboutTabApi = api;
        // loaded by a <script src>, so nothing calls install(): core's callback must exist as soon as the script runs
        install(root.mQuery, root.Mautic);
    }
})(typeof window !== 'undefined' ? window : this);
