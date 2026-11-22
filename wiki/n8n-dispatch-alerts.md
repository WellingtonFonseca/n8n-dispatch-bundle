# n8n dispatch: the alert channel

The alert channel posts an alert to the n8n webhook when a campaign, the status poll or a resend goes wrong. n8n routes it (Teams, email, WhatsApp). It must never break the code that raises the alert, and it must stay off until someone turns it on.

## What it does

```json
{
  "switch": "Settings > Plugins > N8N Dispatch > tab Alertas, field alerts_enabled (feature settings of the integration). Off by default.",
  "wire": "Service/Alert/AlertSender::send(type, data, onceKey, cooldownSeconds). POST to webhook_url through WebhookSettings::forAction with the header `X-N8n-Dispatch-Action: alert.<type>` and the token. Body: {alert, instance, occurred_at (ATOM), data}. `instance` is the setting `alert_instance_name` (trimmed, 100 characters at most, empty when not filled): one n8n Teams channel serves many instances, so the card names the one in trouble (title `Mautic / <instance> / <type>`). Timeout 10 s. Service/Alert/CampaignLink names a campaign in an alert (id, name, absolute link).",
  "log": "Entity/AlertLogEntry (table n8n_dispatch_alert_log: created_at UTC, alert, outcome sent|not_sent, reason, detail, subject) written by Entity/AlertLogEntryRepository::record() in AlertSender, one row per alert that reaches the sender. Reasons: alerts_off, webhook_missing (detail: the message), refused (detail: HTTP code), error (detail: the exception message). Subject: the campaign name (`campaign.name`, or `name` for the bounce spike). Rows older than 30 days go on the next write. A failure to write is logged and never thrown. The detail of an `error` has webhook addresses replaced by `[url]` (the path of an n8n webhook is a secret). The repository class must be named <Entity>Repository (core resolves the entity from the name).",
  "failure": "A refused call (HTTP >= 300), an exception (also of the cache or the settings) or a missing webhook is logged and returns false. Nothing is thrown. Both listeners of CampaignFailureAlertSubscriber catch too: they run inside core's failure handling. The cooldown key is freed, so the next check tries again.",
  "alerts off": "With alerts_enabled off the detection still runs (CampaignFailureAlerts and BounceSpikeAlerts do not check the switch); AlertSender claims the cooldown key, writes `not_sent / alerts_off` and posts nothing. So the log shows what Mautic would have sent. The key stays taken: turning the alerts on keeps a warning or a bounce alert of that campaign silent until its cooldown ends. The bounce check costs one SQL every 5 minutes with the alerts off.",
  "cooldown": "Service/Alert/AlertThrottle on core's CacheStorageHelper (a `cache:clear` empties it: at worst one alert repeats). Wired by hand in Config/services.php because core registers the helper only under `mautic.helper.cache_storage`."
}
```

## The alerts

```json
{
  "alert.campaign.step_failing": "The contacts stuck on a step of the plugin: wiki/n8n-dispatch-failing-steps.md. A step of the plugin never raises the two campaign alerts below: the shield stops them.",
  "alert.campaign.unpublished": "Service/Alert/CampaignFailureAlerts. Core's CampaignEventSubscriber (ON_EVENT_FAILED, priority 0) switches a campaign off at 10% of failed contacts (private constant, 0.1). CampaignFailureAlertSubscriber remembers `isPublished` at priority 100 and compares at priority -100: published before and off after = switched off by failures. A manual unpublish never fires it. No cooldown. Data: campaign {id, name, url}, step {id, name, type}, failed_count, contact_count, failed_percent, core_limit_percent, last_reason.",
  "alert.campaign.failure_warning": "Same class. Campaign still published, contacts >= alert_warning_min_contacts (20) and failed_percent >= alert_warning_percent (5). Once per 24 h per campaign (key warning:<id>). The numbers are core's: failures of the STEP (EventRepository::getFailedCount) divided by the contacts of the campaign. A campaign under the minimum falls before a warning helps.",
  "alert.campaign.bounce_spike": "Service/Alert/BounceSpikeAlerts + BounceSpikeReader (one SQL). Contacts of a published campaign that got an `n8ndispatch.email.send` step in the window (is_scheduled 0, non_action_path_taken 0, no failed log) and went to the email Do Not Contact list as a bounce (reason 2) in the window, over the contacts that got the step. Needs sends >= alert_bounce_min_sends (20) and bounces >= alert_bounce_percent (10) of them, window alert_bounce_window_minutes (60). Once per 6 h per campaign (key bounce_spike:<id>). The dates are read in UTC. It works for both send modes because it reads Mautic's own tables. The check runs at most every 5 minutes (key bounce_check), woken by EventListener/BounceSpikeCheckSubscriber on mautic:campaigns:trigger, mautic:campaigns:update and mautic:segments:update.",
  "alert.status_poll.failed": "the `onError` that StatusPollCommand gives Service/ScheduledRun::run(), when a scheduled run ends as error (a channel error or an exception). Once per 6 h (key status_poll). Data: run_id, summary.",
  "alert.dispatch.resend_failed": "Service/Alert/ResendFailedAlert, called by DispatchResender when a Resend fails again (n8n refused, or the mailer refused). No cooldown. Data: log_id, channel, reason, resent_by, contact_id, step, campaign."
}
```

## Settings

```json
{
  "keys": "alerts_enabled, alert_instance_name (text, optional, 100), alert_warning_percent (1-9, 5), alert_warning_min_contacts (1-100000, 20), alert_bounce_percent (1-100, 10), alert_bounce_window_minutes (5-1440, 60), alert_bounce_min_sends (1-100000, 20), alert_failing_threshold (1-1000, 3) and alert_failing_interval_minutes (15-1440, 60: both belong to the failing-step sweep, wiki/n8n-dispatch-failing-steps.md). Service/Alert/AlertSettings clamps them.",
  "log tab": "Resources/views/Integration/_alert_log.html.twig (the latest 50 rows; columns Status, Detalhe (the reason, empty when sent), Data, Alerta, Campanha; drawn by _notes.html.twig with the About block, the Mirror log and the change log as the 'custom' note of the first tab). plugin-alerts-tab.js moves the block under the fields of the Alertas tab. The tab says it could not read the log (red line) when the read fails, e.g. the table is missing. A key inside its cooldown logs nothing (the first alert of the key is logged). The Status cell is the icon badge of the template lists (`.n8ndispatch-alert-status`; wiki/n8n-dispatch-template-lists.md; Padrão: [ui-badge-n8n.md](ui-badge-n8n.md)): sent = green check, not sent = red cross. Table layout and dates: Padrão: [ui-lista-card.md](ui-lista-card.md). The plugin modal is 780px: Padrão: [ui-modal.md](ui-modal.md).",
  "own tab": "Core gives a plugin no tab. Service/Alert/AlertSettingsForm builds the fields in the Features form and marks them `data-alerts-field`. Assets/js/plugin-alerts-tab.js (data-onload-callback n8ndispatchAlertsTab on the switch) moves them into a tab "Alertas", inserted BEFORE the change log tab (the change log is always the last tab, whichever of the two scripts runs first). Without the script the fields stay on the Features tab."
}
```

## Traps

- No alert goes out when the integration is off, the webhook is empty or n8n is down: the log shows `not_sent` with the reason (an unpublished integration shows as webhook_missing). There is no alert for "integration misconfigured": it has no way out.
- Nothing runs while Mautic's cron is stopped: the bounce check and the poll ride on the cron (the commands are in Service/CronCommands.php).
- Core gives no event for "campaign switched off by failures". Compare `isPublished` before and after core handles the failed step (priorities 100 and -100).
- `campaign.unpublished` and `campaign.failure_warning` now matter only for steps of core and of other plugins: StepFailureShieldSubscriber stops the failures of `n8ndispatch.*` steps first.
- Core's limit is a private constant (0.1). The alert data repeats it as `core_limit_percent`: change it there if core changes.
- A bounce reaches Mautic only when the provider or a bounce mailbox reports it. No report, no bounce alert.
- The cooldown claim is not atomic (`has` then `set` on core's cache). Two cron commands that start in the same second can both send the same alert. A duplicate is possible, a lost alert is not.
- A bounce is tied to the contact and the time (after the step), not to the Email: Do Not Contact keeps no campaign. A contact that bounces on another Email after a step of this campaign counts for it.
- A failing step of a big campaign runs the failure listener once per contact. While the warning cooldown is taken, it makes no query (`AlertThrottle::isTaken`).
- `n8ndispatch.email.send` is the only step counted for bounces. SMS and HSM have no bounce.
- The tab fields are required numbers. Saving the modal also stores the other feature settings it shows.

- A webhook that is down releases the cooldown key, so each later alert of the campaign tries again and logs a `not_sent` row: many rows can push the others out of the 50 shown.
- The log has no test for the red `could not read` line (no spec drops the table).

## Tests

- Tests/Unit/Service/ScheduledRunTest.php (the alert after the close), Tests/Unit/Service/Alert/* (settings, throttle, sender with MockHttpClient, campaign alerts, bounce alerts, resend alert, form), Tests/Unit/EventListener/CampaignFailureAlertSubscriberTest.php, BounceSpikeCheckSubscriberTest.php, the alert cases of StatusPollCommandTest and DispatchResenderTest.
- Tests/Integration/Service/BounceSpikeReaderDbTest.php and AlertLogEntryRepositoryDbTest.php (the SQL on the real MySQL, in a rolled-back transaction).
- scripts/5/test-alerts-channel.sh: a fake n8n on port 8099 (first lane). A bounce campaign made with SQL: the bounce alert from a cron tick, the cooldown, the switch off, `instance` in the payload, the log rows (not_sent / alerts_off with the switch off, sent after). Not covered live: failure_warning, unpublished, status_poll.failed and resend_failed (unit only; a step of the plugin no longer raises the first two). `campaign.step_failing` is in scripts/5/test-step-failure-sweep.sh.
- scripts/5/e2e/tests/plugin-alerts-tab.spec.js: the tab, the log of the alerts (rows, labels, reasons, both themes), the change log as the last tab, both themes, the fields off the Features tab, save and read back (restores the defaults).
- scripts/5/seed-alert-log.sh: demo rows of the log (every alert type, every reason) to look at the tab; `--remove` takes them out.
