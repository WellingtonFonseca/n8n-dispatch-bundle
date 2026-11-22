# n8n dispatch plugin

The client sends email, HSM (WhatsApp template messages) and SMS through n8n. They keep creating templates and building journeys in Mautic; this plugin delegates the actual sending to n8n. One plugin (`N8nDispatchBundle`, version 0.10.0), no core files edited.

```json
{
  "local clone": "/home/paperclip/project/n8n-dispatch-bundle-5 (own git history, branch main = mautic5-base)",
  "github": "https://github.com/WellingtonFonseca/n8n-dispatch-bundle",
  "mount": "docroot/plugins/N8nDispatchBundle in mautic_web, mautic_cron and mautic_worker (../wiki/docker-mautic5.md)",
  "tables": "n8n_dispatch_email_variables, n8n_dispatch_sms_templates, n8n_dispatch_hsm_templates, n8n_dispatch_tracking, n8n_dispatch_callback, n8n_dispatch_poll_run, n8n_dispatch_alert_log. Created or altered by N8nDispatchBundle::onPluginUpdate(): run mautic:plugins:reload after deploying a new version.",
  "settings": "Settings > Plugins > N8N Dispatch > Features: status_poll_enabled (OFF by default), status_poll_interval minutes (default 60, 5-1440), status_poll_batch ids per call (default 100, 10-1000), status_poll_delay milliseconds to wait between two calls to n8n (default 1000, 0-60000, 0 = no pause), status_poll_timeout (default 180 s, 10-900: idle = no byte received) and status_poll_max_duration (default 300 s, up to 1800, never below the timeout); a read-only 'Last run' JSON block (date, status, a warning when no run for max(3 x interval, 60) minutes, one object per channel with asked/changed/unchanged/unknown/invalid/calls or its error; LastRunReport::json; the summary line is written and read back by StatusPollSummary) and a 'Check now' button (Assets/js/status-poll-check-now.js -> AjaxController::runStatusPollAction -> StatusPollNow, needs plugin:plugins:manage): ONE call per channel with the scheduled run's defaults against the SAVED webhook, works with the switch off, really applies the answers, does not touch poll_run / 'Last run'. Changes take effect on the next cron tick.",
  "modal save button": "the save button of the plugin modal is `.modal .btn-apply.btn-copy` (email-send-mode-setting.spec.js)."
}
```

## What it does

```json
{
  "Email save sync": "every Email save (UI or API) is posted to the webhook so Mirror registers/updates the template. Header email.save. The result is recorded and a job sends the out-of-sync Emails again: wiki/n8n-dispatch-mirror-resync.md.",
  "Send via n8n (Email)": "Campaign Action. Picks a Mautic Email and maps its {{variables}}. Header email.send. On an instance whose 'Enviar o Email por' is Mautic it goes through Mautic's mailer instead: wiki/n8n-dispatch-email-direct.md.",
  "Send via n8n (SMS)": "Campaign Action. Picks an SMS Template (main menu > N8N Dispatch > SMS). The text is resolved in Mautic and sent as 'message'. Header sms.send.",
  "Send via n8n (HSM)": "Campaign Action. Picks an HSM Template (main menu > N8N Dispatch > HSM) by 'hsmTemplateId'. Router, hsmTemplate (the WhatsApp-side template name), type and {{name}}-mapped variables live on the template and are read at send time. Header hsm.send.",
  "Dispatches screen": "main menu > N8N Dispatch > Disparos: every dispatch, with filters, details and Resend.",
  "N8N Dispatch menu": "main menu > N8N Dispatch holds Disparos (priority 10), Emails (6), SMS (5) and HSM (4); the SMS and HSM labels are only the channel name (translation keys `smstemplate.menu.index` and `hsmtemplate.menu.index`, which only the menu uses; the list page titles are other keys). SMS and HSM are plugin items with `parent` = the root (Config/config.php). Emails is core's own item (EmailBundle puts it under Channels): EventListener/EmailMenuSubscriber.php moves it at BUILD_MENU priority -9999, after core added every item, by editing the finished tree. A plugin config cannot move it: the same key under another parent shows the item twice. The root `access` is a list of the permissions of its items (core drops an item whose parent is hidden and shows it at the TOP of the menu), so the entry shows for anyone who can open one item. If the root is missing from the tree (it cannot be while the root asks for the permissions of the Email item) the item stays under Channels. A user whose only channel permission was Email keeps Channels as a bare entry with no children: core does the same for any user with no channel permission. The root list copies the permissions of core's Email item by hand: change both together (scripts/5/test-n8n-menu-access.sh checks four roles). Tests: Tests/Unit/Config/MenuTest.php, Tests/Unit/EventListener/EmailMenuSubscriberTest.php, scripts/5/e2e/tests/n8n-menu.spec.js, scripts/5/test-n8n-menu-access.sh.",
  "Node name": "a step is always named after its template (campaign save and rename of the template): wiki/n8n-dispatch-campaign-actions.md."
}
```

Every campaign step has a **status**: `test` (records the payload on the contact's Timeline, no HTTP call), `production` (real call to n8n), `paused` (no call; the payload is recorded like `test` and the step PASSES, so the contact moves on to the next step).

Guards before a real dispatch: a contact on the Do Not Contact list for the channel (`email`; `sms` for SMS and HSM) leaves that contact out with no call, before anything is resolved (`DoNotContact::isContactable()`); the step PASSES with the reason (`PendingEvent::passWithError`, log metadata failed=1 and reason, so the History still shows it as failed), never `fail()`; an SMS/HSM contact with no phone fails; integration disabled or no `webhook_url` fails the whole batch. A `fail()` of a plugin step is retried by core every hour and never unpublishes the campaign (the shield, wiki/n8n-dispatch-failing-steps.md).


## SMS channel of core (for the Do Not Contact of SMS and HSM)

```json
{
  "why": "Core offers a channel in the channel preferences and the Do Not Contact options of the contact page only while some SMS transport is enabled (SmsBundle ChannelSubscriber, `TransportChain::getEnabledTransports()`). Without one, the page could block the email of a contact but not its SMS (and HSM, which follows the `sms` DNC channel). The dispatch itself never needed it: it reads the DNC table through DoNotContact::isContactable().",
  "how": "Service/N8nSmsTransport.php implements core's SmsBundle TransportInterface and is tagged `mautic.sms_transport` (Config/config.php, services.other, tagArguments integrationAlias N8nDispatch): it is enabled while the plugin integration is published. It sends NOTHING: sendSms returns a translated string (mautic.n8ndispatch.sms_transport.not_supported) that core keeps as the failure reason, because SMS and HSM go out through the n8n campaign actions.",
  "side effect": "While the plugin is active core's own SMS screens appear too (Channels > Text messages, the 'Send text message' action). Accepted: the users are told to use the n8n SMS.",
  "tests": "Tests/Unit/Service/N8nSmsTransportTest.php, scripts/5/e2e/tests/sms-dnc-channel.spec.js (the channel preferences offer email and sms)."
}
```

## Where to read

Read only the file of the area you touch:

- [wiki/n8n-dispatch-payload-variables.md](n8n-dispatch-payload-variables.md) — What the plugin sends to n8n, how the `{{variables}}` of a template are resolved (contact fields and Custom Objects by segment) and the rules checked when a template is saved.
- [wiki/n8n-dispatch-email-direct.md](n8n-dispatch-email-direct.md) — The per-instance send mode that sends the Email through Mautic's own mailer instead of n8n: the send path, the result read from Mautic, resend, the limits.
- [wiki/n8n-dispatch-email.md](n8n-dispatch-email.md) — The Email save sync to Mirror (`email.save`) and how an Email dispatch behaves: the Stat, the view link, the unsubscribe link, test / production / paused.
- [wiki/n8n-dispatch-mirror-resync.md](n8n-dispatch-mirror-resync.md) — The result of every Email sync to Mirror (n8n_dispatch_mirror_sync) and the resync job that sends the Emails out of sync again, one at a time.
- [wiki/n8n-dispatch-campaign-actions.md](n8n-dispatch-campaign-actions.md) — The Email / SMS / HSM campaign actions, their forms and the node name taken from the communication name on campaign save.
- [wiki/n8n-dispatch-templates.md](n8n-dispatch-templates.md) — The SMS and HSM templates, their lists (Router and HSM Template columns) and details modal.
- [wiki/n8n-dispatch-provider.md](n8n-dispatch-provider.md) — The HSM sent through the DialogHSM plugin: the "Enviar por" select, the gateway (optional plugin), the send, the card, Sucesso / Falhou, the resend of a refusal, the traps and the names to review.
- [wiki/n8n-dispatch-template-history.md](n8n-dispatch-template-history.md) — The history of the SMS and HSM templates: every save, toggle and delete in core's audit_log and the "Histórico" tab (field, before, after).
- [wiki/n8n-dispatch-templates-hsm-cards.md](n8n-dispatch-templates-hsm-cards.md) — The HSM carousel and image types: `cards`, validation, form block, n8n Code nodes.
- [wiki/n8n-dispatch-template-lists.md](n8n-dispatch-template-lists.md) — The Status column (icon badge that opens on hover, Ativar / Desativar) of the SMS and HSM template lists.
- [wiki/n8n-dispatch-status-poll.md](n8n-dispatch-status-poll.md) — The status poll (callback) that asks n8n about accepted dispatches, its settings and pause between calls, and the unified per-dispatch History (dispatch and callback entries as JSON).
- [wiki/n8n-dispatch-dispatches-screen.md](n8n-dispatch-dispatches-screen.md) — The Dispatches screen (menu, filters, details), Resend, and the Timeline card for pending, cancelled and rescheduled steps.
- [wiki/n8n-dispatch-failing-steps.md](n8n-dispatch-failing-steps.md) — A failing step never unpublishes the campaign: the shield, the per-contact failure table, the sweep and the `campaign.step_failing` alert.
- [wiki/n8n-dispatch-alerts.md](n8n-dispatch-alerts.md) — The alert channel: alerts to the n8n webhook (campaign switched off, failure warning, bounce spike, status poll failed, resend failed), the Alertas tab and its limits.
- [../wiki/plugin-changelog.md](../../wiki/plugin-changelog.md) — The CHANGELOG.md by day and its own tab in the settings modal of the plugin.
- [wiki/n8n-dispatch-traps-and-tests.md](n8n-dispatch-traps-and-tests.md) — The traps of the plugin, its language and translations, the open points and how its tests run.
