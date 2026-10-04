/**
 * The "Check now" button on Settings > Plugins > N8n Dispatch > Features
 * (N8nDispatchIntegration::addCheckNowButton()). Asks n8n about the pending
 * dispatches once, right away (AjaxController::runStatusPollAction), and
 * shows what happened per channel, as a JSON block, under the button — so the n8n workflow can
 * be built and fixed before the schedule is switched on.
 *
 * The texts come from the plugin's javascript.ini in the system language
 * (window.mauticLang), with the English text as the fallback.
 */
(function (window) {
    'use strict';

    var Mautic = window.Mautic;
    var CHANNELS = ['email', 'sms', 'hsm'];

    function translate(key, fallback, params) {
        var lang = window.mauticLang;
        var text = (lang && lang[key]) || fallback;

        Object.keys(params || {}).forEach(function (name) {
            text = text.split('%' + name + '%').join(String(params[name]));
        });

        return text;
    }

    /**
     * What to show for a runStatusPollAction response: a JSON block with one
     * object per channel (the counts of that check, or its error), plus notes
     * under it — when nothing was pending at all, or when the request was refused
     * (then there is no block).
     */
    function buildResult(response) {
        if (!response || !response.success) {
            return {json: null, notes: [{level: 'error', text: translate('mautic.n8ndispatch.js.check_now.denied', 'It could not run: you may not have permission to.')}]};
        }

        var block = {};
        var anythingAsked = false;
        var anyError = false;

        CHANNELS.forEach(function (channel) {
            var summary = (response.channels || {})[channel];

            if (!summary) {
                return;
            }

            if (summary.error) {
                anyError = true;
                block[channel] = {error: summary.error};

                return;
            }

            anythingAsked = anythingAsked || summary.requested > 0;
            block[channel] = {
                asked: summary.requested,
                changed: summary.changed,
                unchanged: summary.unchanged,
                unknown: summary.unknown,
                invalid: summary.invalid
            };
        });

        var notes = [];

        if (!anythingAsked && !anyError) {
            notes.push({level: 'info', text: translate('mautic.n8ndispatch.js.check_now.nothing', 'Nothing pending to ask about. Run the backfill for the old dispatches, or wait for a new one.')});
        }

        return {json: JSON.stringify(block, null, 4), notes: notes};
    }

    function escapeHtml(text) {
        return window.mQuery('<div>').text(text == null ? '' : text).html();
    }

    function renderNotes(notes) {
        return notes.map(function (line) {
            return '<div class="n8ndispatch-check-now-line n8ndispatch-check-now-line--' + line.level + '">' + escapeHtml(line.text) + '</div>';
        }).join('');
    }

    // result: {json, notes}; the JSON goes in a block of its own, like the other JSON blocks of the plugin.
    function render($button, result) {
        // The button sits in a half-width column (core's standalone button row); the result goes in its own
        // full-width column of the same row, so the JSON block takes the whole width of the form.
        var $row = $button.closest('.row');
        var $parent = $row.length ? $row : $button.parent();
        var $result = $parent.find('.n8ndispatch-check-now-result');

        if (!$result.length) {
            $result = window.mQuery('<div class="n8ndispatch-check-now-result' + ($row.length ? ' col-xs-12' : '') + '" role="status"></div>').appendTo($parent);
        }

        $result.html(
            (result.json ? '<pre class="n8ndispatch-json-pre">' + escapeHtml(result.json) + '</pre>' : '') + renderNotes(result.notes)
        );
    }

    // onclick of the button. The webhook used is the one already SAVED in the plugin's settings.
    Mautic.n8ndispatchCheckStatusNow = function (button) {
        var $button = window.mQuery(button);

        $button.prop('disabled', true);
        render($button, {json: null, notes: [{level: 'info', text: translate('mautic.n8ndispatch.js.check_now.running', 'Asking n8n…')}]});

        Mautic.ajaxActionRequest('plugin:N8nDispatch:runStatusPoll', {}, function (response) {
            render($button, buildResult(response));
            $button.prop('disabled', false);
        }, function () {
            render($button, {json: null, notes: [{level: 'error', text: translate('mautic.n8ndispatch.js.check_now.failed', 'Could not run the check. Try again.')}]});
            $button.prop('disabled', false);
        });
    };

    // data-onload-callback of the read-only "Last run" field (N8nDispatchIntegration::appendToForm()): the form can only
    // draw it as a (disabled) textarea, so once the page is up it is swapped for a <pre> block, like the other JSON blocks.
    // Disabled, it is never submitted, so nothing is lost by replacing it.
    Mautic.n8ndispatchBlockFromTextarea = function (element) {
        var $field = window.mQuery(element);

        $field.replaceWith(window.mQuery('<pre class="n8ndispatch-json-pre n8ndispatch-last-run"></pre>').text($field.val()));
    };

    Mautic.n8ndispatchStatusPoll = {buildResult: buildResult, blockFromTextarea: Mautic.n8ndispatchBlockFromTextarea};
}(window));
