/**
 * The "Check now" button on Settings > Plugins > N8n Dispatch > Features
 * (N8nDispatchIntegration::addCheckNowButton()). Asks n8n about the pending
 * dispatches once, right away (AjaxController::runStatusPollAction), and
 * shows what happened per channel under the button — so the n8n workflow can
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
     * What to show for a runStatusPollAction response: one {level, text} per
     * channel, plus a note when nothing was pending at all.
     */
    function buildResultLines(response) {
        if (!response || !response.success) {
            return [{level: 'error', text: translate('mautic.n8ndispatch.js.check_now.denied', 'It could not run: you may not have permission to.')}];
        }

        var lines = [];
        var anythingAsked = false;
        var anyError = false;

        CHANNELS.forEach(function (channel) {
            var summary = (response.channels || {})[channel];

            if (!summary) {
                return;
            }

            var label = channel.toUpperCase();

            if (summary.error) {
                anyError = true;
                lines.push({level: 'error', text: label + ': ' + summary.error});

                return;
            }

            anythingAsked = anythingAsked || summary.requested > 0;
            lines.push({
                level: 'ok',
                text: translate(
                    'mautic.n8ndispatch.js.check_now.line',
                    '%channel%: asked %requested%, changed %changed%, unchanged %unchanged%, unknown %unknown%, invalid %invalid%',
                    {
                        channel: label,
                        requested: summary.requested,
                        changed: summary.changed,
                        unchanged: summary.unchanged,
                        unknown: summary.unknown,
                        invalid: summary.invalid
                    }
                )
            });
        });

        if (!anythingAsked && !anyError) {
            lines.push({level: 'info', text: translate('mautic.n8ndispatch.js.check_now.nothing', 'Nothing pending to ask about. Run the backfill for the old dispatches, or wait for a new one.')});
        }

        return lines;
    }

    function escapeHtml(text) {
        return window.mQuery('<div>').text(text == null ? '' : text).html();
    }

    function render($button, lines) {
        var $parent = $button.parent();
        var $result = $parent.find('.n8ndispatch-check-now-result');

        if (!$result.length) {
            $result = window.mQuery('<div class="n8ndispatch-check-now-result" role="status"></div>').appendTo($parent);
        }

        $result.html(lines.map(function (line) {
            return '<div class="n8ndispatch-check-now-line n8ndispatch-check-now-line--' + line.level + '">' + escapeHtml(line.text) + '</div>';
        }).join(''));
    }

    // onclick of the button. The webhook used is the one already SAVED in the plugin's settings.
    Mautic.n8ndispatchCheckStatusNow = function (button) {
        var $button = window.mQuery(button);

        $button.prop('disabled', true);
        render($button, [{level: 'info', text: translate('mautic.n8ndispatch.js.check_now.running', 'Asking n8n…')}]);

        Mautic.ajaxActionRequest('plugin:N8nDispatch:runStatusPoll', {}, function (response) {
            render($button, buildResultLines(response));
            $button.prop('disabled', false);
        }, function () {
            render($button, [{level: 'error', text: translate('mautic.n8ndispatch.js.check_now.failed', 'Could not run the check. Try again.')}]);
            $button.prop('disabled', false);
        });
    };

    Mautic.n8ndispatchStatusPoll = {buildResultLines: buildResultLines};
}(window));
