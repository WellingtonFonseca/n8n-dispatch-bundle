# n8n dispatch: Mirror sync state and resync

The result of every `email.save` sync and the job that sends the out-of-sync Emails to Mirror again.
An Email save never waits for this and never fails because of it: a failed sync is recorded, and the job sends the Email again later.
Must not break: the job never saves an Email (no date_modified, no POST_SAVE), and it sends one Email per call, never two calls at a time.

## Sync state

```json
{
  "table": "n8n_dispatch_mirror_sync (Entity/MirrorSyncState.php), one row per Email, email_id is the key (no foreign key to core). Created by onPluginUpdate (0.14.0).",
  "columns": "status ok | failed | skipped, content_hash, http_status (null on a transport failure), error, attempts (failures in a row, 0 after anything else), source save | job, attempted_at, synced_at (last success, kept by a later failure or skip). Dates in UTC.",
  "write": "MirrorSyncStateRepository::record(), one INSERT ... ON DUPLICATE KEY UPDATE. Called only by Service/MirrorSync.php.",
  "content_hash": "sha256 of subject + HTML sent (footer and preheader in place) + from_name + from_address (MirrorSyncPayload::contentHash). The payload `hash` stays the HTML hash only: it is n8n's contract.",
  "no row": "the integration is off or has no webhook URL: nothing is sent and nothing is recorded, so the Email counts as never synced."
}
```

## One sender

```json
{
  "MirrorSync::sync": "the only sender of email.save, used by the save (EmailMirrorSyncSubscriber) and the job. Webhook and headers from WebhookSettings::forAction. Logs and records every failure, never throws.",
  "failure": "HTTP >= 300 (error 'HTTP <n>'), a transport error (the exception text), or a 2xx whose JSON body has a top-level `error` string (DispatchFailureReasons::errorInBody, the same rule as the dispatches). A 2xx with any other body is ok.",
  "empty HTML": "customHtml empty or only spaces: no call, no footer, status skipped. The job sends it again only when its content changes.",
  "payload": "Service/MirrorSyncPayload.php builds it from the fields (fieldsOf(Email) on save, the database row in the job), so both send the same HTML and hash.",
  "footer": "only the save persists the footer into customHtml (flush, see wiki/n8n-dispatch-email.md). The job adds it in memory, so the HTML and the hash are the same."
}
```

## The resync job

```json
{
  "command": "php bin/console n8ndispatch:mirror:resync [--batch] [--delay] [--email-id] [--run-id]. --email-id sends that Email whatever its state. Works by hand with the switch off: the switch gates only the send on save (EmailMirrorSyncSubscriber) and the job (MirrorResyncJob); MirrorSync never reads it.",
  "schedule": "no cron entry of its own: ScheduledJobsSubscriber on the Mautic cron commands (CronCommands::NAMES), JobScheduler claims a run of kind mirror_resync (MirrorResyncJob) in n8n_dispatch_poll_run, BackgroundCommandLauncher starts the command with nohup. The status poll is tried first on a tick. Same lock as the status poll (wiki/n8n-dispatch-status-poll.md).",
  "order": "1 failed with fewer than 10 failures in a row, 2 never synced, 3 content changed, 4 last attempt older than the refresh hours (attempted_at; an ok sync has attempted_at = synced_at). Inside a priority the oldest attempt first. The batch cuts the list; 'left' is the rest.",
  "reading": "MirrorSyncStateRepository::page() reads the Emails 100 at a time (id cursor) with plain DBAL, computes the content hash in PHP and keeps only the due ids. emailRow() reads each Email again right before its send, so a save made during the run is never overwritten by older content.",
  "pace": "one Email per call, strictly in sequence (getStatusCode() waits for n8n before the next Email). Service/CallPacer keeps the pause between the END of a call and the START of the next (finished() ends a call) (one instance per class: Config/services.php sets it `share(false)`, so the status poll and the resync never count each other's calls); no pause before the first call nor for a skipped Email.",
  "orphans": "each run deletes the state of Emails that no longer exist (pruneOrphans).",
  "settings": "Mirror tab, right of Features (MirrorResyncSettings, MirrorSettingsForm builds the fields in the Features form; Assets/js/plugin-mirror-tab.js, callback n8ndispatchMirrorTab on the switch, moves every row whose label or input name is mirror_* into the tab, inserted right after the Features tab; without the script they stay on Features. A new Mirror field must have a key that starts with mirror_): mirror_send_enabled (ON; MirrorSendSwitch is its one reader), mirror_resync_interval 120 min (30-1440), mirror_resync_batch 100 Emails (10-1000), mirror_resync_delay 2000 ms (0-60000), mirror_resync_refresh_hours 24 (6-168). The log of the runs sits under the fields: Resources/views/Integration/_mirror_log.html.twig, same table as the alert log (wiki/n8n-dispatch-alerts.md: icon badge, 780px modal, sideways scroll, Detalhe wraps). It lists the latest 50 rows of kind mirror_resync of n8n_dispatch_poll_run (PollRunRepository::latestRuns; the table keeps 7 days) as columns Status (ok green, error red, running gray), Detalhe (the error, or the text of a summary that is not a summary line), Data (UTC), Pendentes, Chamadas, Ok, Falhas, Ignorados, Restam. MirrorResyncLogRows splits the summary line (MirrorResyncSummary::parse); a run with no summary has empty counts. A red line above the table is the 'cron stopped' warning (StatusPollSettings::isStaleAt, only with the send switch on). The block is a `.n8ndispatch-mirror-log-block` (NOT `.n8ndispatch-alert-log-block`: plugin-alerts-tab.js would move that one into the Alertas tab); plugin-mirror-tab.js moves it into the Mirror pane. N8nDispatchIntegration::latestMirrorRuns gives the template `mirrorLog` ({rows, warning}, null when the read fails: red 'could not read' line).",
  "once a day": "with the defaults every Email is sent again once a day, spread over the 12 runs: 1200 Emails a day at most. Past that, raise the batch ('left' grows)."
}
```

## Traps
- The switch key is `mirror_send_enabled`. The old `mirror_resync_enabled` (default OFF) is ignored and dropped at the next save of the tab. Keeping it would turn off the send on save on every instance that saved the tab.
- Switch off: the guard in EmailMirrorSyncSubscriber runs before isConfigured(), so nothing is logged. A run already started keeps sending until it ends (MAX_RUN_SECONDS). The Mirror log shows no "cron stopped" line.
- A renamed form class or changed constructor needs `var/cache/prod` deleted before `cache:clear` (dev, deploy).

- The 7 instances share one n8n webhook. Sequential calls and the pause are the only load control: on one cron tick the instances can still send up to 7 calls at a time. To lower the load, raise the delay or lower the batch.
- A run still 'running' blocks the other kind of its lane: one instance never runs the status poll and the resync at the same time. The failure sweep is in another lane and is never blocked by them (wiki/n8n-dispatch-failing-steps.md). The interval counts only the runs of the same kind.
- A renamed or removed service class breaks `cache:clear` itself (the old container fails to boot). Delete `var/cache/prod` first, then `cache:clear`. On a deploy of 0.14.0 (StatusPollLauncher became BackgroundCommandLauncher): delete the cache folder, then `cache:clear`, then `mautic:plugins:reload`.
- A run stops taking Emails after MirrorResync::MAX_RUN_SECONDS (30 min); the rest counts as 'left'. Each call has a 30 s idle timeout and a 60 s total limit (MirrorSync::TIMEOUT, MAX_DURATION), also on save. So a run never reaches the 120 min after which a 'running' row is taken as dead, and it never blocks the status poll for long.
- A save during the job's call to the same Email can leave Mirror with the job's older content: the save records the new hash, then the job records the old one. The next run sees the content changed and sends it again. Mirror is stale for one interval at most.
- `--email-id` takes no run lock: by hand it can overlap a scheduled run and send the same Email twice. A missing Email is an error.
- The status poll and the resync claim on the same cron command at the same priority. A status poll that always outlasts its interval would take every slot first; it does not at the default settings.
- Before `mautic:plugins:reload` the table is missing: the save still works (the record failure is logged as 'could not record the Mirror sync'), the job finds nothing.

## Tests

- Unit: Tests/Unit/EventListener/EmailMirrorSyncSubscriberTest.php (payload, headers, recorded results, empty HTML, error body), Tests/Unit/Service/MirrorResyncTest.php (order, refresh limit, pages, pause, deleted Email), Tests/Unit/Service/MirrorResyncSchedulingTest.php (settings, job, launcher, pacer), Tests/Unit/Service/JobSchedulerTest.php (claim, order, launch), Tests/Unit/EventListener/ScheduledJobsSubscriberTest.php, Tests/Unit/Command/MirrorResyncCommandTest.php, Tests/Unit/Service/MirrorResyncLogRowsTest.php and the mirrorLog cases of Tests/Unit/Integration/N8nDispatchIntegrationTest.php (the Mirror log). Not covered: the SQL of latestRuns on real MySQL, and a browser spec of the table.
- Real MySQL: Tests/Integration/Service/MirrorSyncStateRepositoryDbTest.php (upsert counts, page, deleted Email, claim per kind).
- Live: scripts/5/test-mirror-resync.sh (fake n8n on port 8097; save with n8n failing, resync by the job, the Email not saved again, error body, the pause between calls). It points the integration at the fake and puts it back, and restores the state table.
