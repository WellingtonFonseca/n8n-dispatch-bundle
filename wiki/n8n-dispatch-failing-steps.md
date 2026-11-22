# n8n dispatch: failing steps (the shield, the failure table, the sweep)

A dispatch step of the plugin that fails never unpublishes the campaign. Core retries the contact every hour until someone fixes the cause. The plugin counts the failures per contact and step, and alerts about the contacts that are stuck. Do not break: the shield must run before core's listener, and nothing may write to the failure table on a success.

## Why

Core unpublishes a campaign when 10% of its contacts failed on ONE step (CampaignEventSubscriber::onEventFailed, a private constant 0.1). One broken dispatch step then stops the students of every other step.

## What it does

```json
{
  "shield": "EventListener/StepFailureShieldSubscriber on CampaignEvents::ON_EVENT_FAILED, priority 1000. For an event type that starts with `n8ndispatch.` it calls stopPropagation() FIRST, then records the failure. Core's listener (priority 0: incrementFailedCount, notifyOfFailure, unpublish) and the plugin's own CampaignFailureAlertSubscriber (100 and -100) never see it. Other types pass untouched.",
  "retry": "Core still reschedules the failed log after the event (ActionDispatcher::dispatchFailedEvent calls rescheduleFailures after the loop). The contact stays on the step and is tried again every hour (core setting campaign_time_wait_on_event_false, PT1H) with no limit, until the send works, someone cancels the contact's event on the Timeline, or the contact leaves the campaign. The plugin's steps keep fail(): every send failure, no phone, template missing, Email not published, webhook missing, integration off.",
  "table": "n8n_dispatch_step_failure, PK (event_id, lead_id): campaign_id, log_id (core's lead_event_log id), failures, last_reason (191 characters, the column length), first_failure_at, updated_at (UTC). Entity/StepFailure + StepFailureRepository. Created by N8nDispatchBundle::onPluginUpdate (run mautic:plugins:reload after cache:clear).",
  "record": "StepFailureRepository::record(): ONE INSERT ... ON DUPLICATE KEY UPDATE. The same log_id adds 1 (the hourly retry). A different log_id is a new pass through the step (reentry, loop): failures restart at 1 and first_failure_at moves. log_id is assigned LAST in the UPDATE list because MySQL evaluates the assignments left to right and the others compare it. Core retries reuse the same LeadEventLog; a new pass creates a new log (rotation).",
  "no write on success": "The send code never touches the table on a success. The sweep deletes the row of a contact that is no longer waiting.",
  "sweep": "Command/FailureSweepCommand `n8ndispatch:failure:sweep [--run-id]`, started by JobScheduler through Service/FailureSweepJob (kind failure_sweep, a LANE of its own in Config/services.php, never off). Its run is claimed in n8n_dispatch_poll_run with a lock that covers only its own kind: a long status poll or Mirror resync never blocks it, and it never blocks them. Service/Alert/FailingStepAlerts::sweep(): (1) deleteResolved(), (2) failingSteps(threshold), (3) one alert per step.",
  "cleanup": "deleteResolved() deletes a row whose log is not scheduled any more (the retry passed, the event was cancelled, the contact was removed), or whose step or campaign is deleted (`deleted` not null) or missing. An UNPUBLISHED campaign keeps its rows: core does not retry it and the count goes on when it is published again.",
  "count": "failingSteps(): rows with failures >= threshold, grouped by campaign and step, only PUBLISHED campaigns. A contact counts while its row exists and has enough failures. A success never resets a step-wide counter: there is none.",
  "alert": "`campaign.step_failing` (header X-N8n-Dispatch-Action alert.campaign.step_failing). Data: campaign {id, name, url}, step {id, name, type}, failing_contacts, threshold, max_failures, oldest_failure_at (ATOM), oldest_failure_hours, top_reasons (10 at most per step, most common first: reason, contacts; StepFailureRepository::reasons() cuts them in SQL with ROW_NUMBER, MySQL 8.0+, so the contact total of failingSteps() is not touched). One alert per failing step per sweep, no cooldown key: it repeats on every run while contacts are stuck and stops when the count is zero. With alerts_enabled off the sender logs not_sent / alerts_off, one row per step per run.",
  "settings": "Alertas tab: alert_failing_threshold (failures of one contact on one step that count as stuck, 1-1000, default 3) and alert_failing_interval_minutes (minutes between sweeps, 15-1440, default 60; under 60 adds nothing because the retry is hourly). AlertSettings clamps them."
}
```

## What the client does

- Fixes the data (a missing phone) or the n8n flow: the next hourly retry works, the contact moves on, and the sweep deletes its row.
- Or cancels the contact's scheduled event on the Timeline, or removes the contact from the campaign: the sweep deletes the row.
- The campaign stays published the whole time.

## Traps

- The alert uses the same webhook as the dispatches. When the cause is n8n itself (down, integration off, webhook empty), the alert cannot arrive: the Alertas log shows `not_sent`. Nothing stops the campaign any more.
- The shield also removes core's bell notification for each failed contact of these steps, and the plugin's `campaign.unpublished` and `campaign.failure_warning` alerts. Those two still work for steps of core and of other plugins. Core's failed_count of a plugin step no longer grows.
- Never use `stopPropagation()` without the type check: it would hide the failures of every other plugin from core.
- An n8n flow that sends the message and then answers an error makes the retry send it again every hour. The alert (reason, count, oldest failure) is the only defense.
- Empty `campaign_time_wait_on_event_false`: core does not reschedule the FIRST failure of a log. That log never gets `is_scheduled = 1`, so the sweep deletes the row. A log that was already scheduled keeps its flag and its row. The default is PT1H.
- `VALUES()` in the upsert is deprecated since MySQL 8.0.20 (warning 1287, it works). It stays for MariaDB.
- A sweep that runs between the first failure and core's reschedule save can delete the new row. The count restarts at 1 and recovers by itself.
- The sweep has its own lock lane: only a sweep that is still 'running' (up to 120 minutes, the stale limit of PollRunRepository) delays the next sweep. It runs in the background, so the alert HTTP calls never slow the cron command.
- Deploy of the lanes (0.15.1): delete `var/cache/prod` BEFORE `cache:clear`. JobScheduler got a new constructor argument (a logger) and a different `$lanes` list: the old compiled container still builds it with 3 arguments, `cache:clear` itself fails to boot and every Mautic cron command dies with `Too few arguments to ... JobScheduler::__construct()` until the folder is gone (../wiki/docker-mautic5.md, wiki/n8n-dispatch-mirror-resync.md). Then `mautic:plugins:reload` for the version.
- The cleanup is ONE multi-table DELETE that reads every row of the table. With very many stuck contacts (an outage on a big campaign) it holds row locks for the length of the statement and the shield's upserts wait. The table has no index on `failures` on purpose: the upsert changes it on every retry.
- A DNC contact still PASSES with `passWithError` (not counted). An outage with many failing steps sends one alert per step on every run.
- `paused` records the payload and PASSES the step: the contact moves on (see wiki/n8n-dispatch-plugin.md).

## Tests

- Unit: StepFailureShieldSubscriberTest, FailingStepAlertsTest, FailureSweepJobTest, FailureSweepCommandTest, AlertSettingsTest, AlertSettingsFormTest.
- Real MySQL: Tests/Integration/Service/StepFailureRepositoryDbTest.php (the upsert, the new pass, the cleanup cases, the count, the reasons).
- Live: scripts/5/test-step-failure-sweep.sh (fake n8n on port 8097: three hourly retries bring the count to 3, the campaign stays published, no bell, one alert per sweep, a removed contact leaves the count, switch off, unpublished campaign, empty table). test-alerts-channel.sh no longer covers the warning and unpublish alerts for a plugin step: they are unit-only.
