# n8n dispatch: Email through Mautic's own mailer (send mode)

A per-instance switch makes the Email action send through Mautic's mailer instead of the n8n webhook. The plugin still resolves the `{{variables}}`; Mautic sends, tracks and keeps the result; the Dispatches screens read that result from Mautic. Updating the plugin never turns it on. SMS and HSM always go through n8n.

## The switch

```json
{
  "where": "Settings > Plugins > N8N Dispatch > Habilitar/Autenticar, field 'Enviar o Email por', under the token. Saved with the keys of the connection (key `email_send_mode`). Integration/N8nDispatchIntegration::addEmailSendMode().",
  "values": "`n8n` (default) | `mautic`. Only the exact value `mautic` turns it on (Service/EmailSendMode::fromArray); a missing or other value is `n8n`.",
  "scope": "one Mautic instance (10 in production, turned one at a time). Every campaign, node, template and variable stays as it is.",
  "back": "save `n8n` again. Logs already sent keep their own mode: the log's `n8ndispatch.mode` is `mautic` for the ones sent here."
}
```

## Send path

```json
{
  "branch": "EventListener/CampaignTriggerSubscriber::onEmailSend: after the Email is loaded, production status and direct mode -> Service/DirectEmailDispatch::dispatch() (the batch loop, the STOP_AFTER breaker and the log block; the subscriber only picks the way out). test / paused behave as before (payload on the Timeline, nothing sent). No webhook is read, so the integration may have none.",
  "sender": "Service/DirectEmailSender::send(): ONE contact per EmailModel::sendEmail() call (the `tokens` option is the same for everyone in a call). Options: source ['campaign.event', event id], email_type marketing (fixed: it makes Mautic respect Do Not Contact and bounce and add List-Unsubscribe one-click), return_errors, dnc_as_error, customHeaders X-EMAIL-ID, email_attempts 3, email_priority 2.",
  "tokens": "{{name}} => resolved value for every variable of the mapping (VariableResolver, unchanged), then {{n8ndispatch_unsubscribe_url}} => '{unsubscribe_url}' LAST: core replaces tokens in order, ours first, so the placeholder lands on core's own token and becomes the real link. The footer the plugin injects on save works the same.",
  "native tokens": "{contactfield=...} and the other core tokens work in the subject and the body as in any Mautic Email.",
  "skipped, not failed": "a contact with no address, or who left the Email's category, is passed with a note (core does the same): retrying never helps.",
  "empty Email": "no HTML and no theme (`customHtml` and `template` blank) is refused for every contact (EMAIL_EMPTY): it would go out with only the unsubscribe footer. The contacts wait; core retries a failed step.",
  "not published": "DirectEmailDispatch::dispatch fails the whole step (EMAIL_NOT_PUBLISHED); core's own action does not send it either.",
  "DNC": "Service/EmailDncGuard::passIfNotContactable() runs first for both paths (the subscriber and DirectEmailDispatch share it) and PASSES the step with the reason (passWithError; a fail() is retried by core every hour with no end) (same reason on the History); the mailer asks again and adds bounce.",
  "channel": "PendingEvent::setChannel('email', id) so core's campaign channel stats see it.",
  "no Stat of ours": "the mailer makes the Stat, the template copy and the tracked links. createStat() and saveTemplateCopy() run only on the n8n path.",
  "log metadata": "`n8ndispatch` = {mode:'mautic', status, mautic_template_id, contact_id, contact_email, language, variables, outcome (sent|queued|skipped|failed), statId (the first Stat), statIds (all of them, only when more than one)}. MauticEmailOutcome::statFields() adds the new Stat to the earlier ones: core retries a failed step by itself, and a resend is another attempt."
}
```

## Result read from Mautic

```json
{
  "service": "Service/MauticEmailOutcome. Nothing is copied: it reads core's `email_stats`, `page_hits` and `lead_donotcontact` when a screen draws. states() for the list (batch), detail() for the card.",
  "state of the LAST attempt": "bounced (Do Not Contact reason 2 at or after the send) > failed (Stat is_failed) > unsubscribed (reason 1 after the send) > clicked (a tracked hit of that contact on that Email after the send) > opened (is_read) > sent. No Stat: failed / skipped when the step said so (`outcome`), else queued.",
  "result for the filter": "success: sent, opened, clicked, unsubscribed; error: failed, bounced, skipped; pending: queued. DispatchFilters::mauticStatusOf(): a step that did not go through (log marked failed) is 'failed'.",
  "late Stat": "an Email Mautic held back (frequency rule, queue) has no Stat when the step ends; the Stat it makes later is found by contact + step (`source campaign.event`, source_id) + date_sent >= the log's date (lateStatIds, factsOfLog). Not found for failed / skipped logs.",
  "attempts": "one Stat per attempt, newest first on the card; the number of Stats is the number of attempts.",
  "open is approximate": "the pixel is cached or blocked by some mail clients (Gmail, Apple). A click is reliable. 'Delivered' does not exist on plain SMTP: only a provider webhook or an IMAP bounce box tells Mautic.",
  "what the plugin does not do": "no poll, no tracking rows, no copy into n8n_dispatch_callback for these logs. DispatchLogReader::dispatchOf returns null for them so the n8n backfill ignores them."
}
```

## Screens

```json
{
  "list": "Dispatches table, column callback: badge of the state (queued=pending, sent/opened/clicked=success, failed/bounced/skipped=failed, unsubscribed=paused colours). DispatchListReader gets `?MauticEmailOutcome`; items carry `mauticState`.",
  "card": "Resources/views/SubscribedEvents/Timeline/_dispatch_mautic.html.twig, included by _dispatch_details.html.twig (contact Timeline and details modal) when `n8ndispatch_is_direct(item.metadata)`: state badge, send / open / clicks / DNC lines, the variables, who resent it, one row per attempt. Twig/MauticOutcomeExtension (`n8ndispatch_mautic_outcome(metadata, leadId, logId)`: the log gives lead, step and time).",
  "resend": "Reenviar is offered for a log whose `outcome` is failed and that is marked failed, step in production (DispatchResender::resendDirect). It sends again, as ONE MORE Stat, with the values of the first send (variables kept in the metadata) or, when the user used the resend form, the confirmed ones (they replace the variables stored in the log: wiki/n8n-dispatch-dispatches-screen.md); success clears failed / reason / errors and core's failed log and writes `n8ndispatch_resend` (who, when); another refusal is recorded and stays resendable. DNC, a deleted or unpublished Email are reported. A bounce, a sent or a skipped Email cannot be resent."
}
```

## Subject

The plugin treats the subject like the HTML for `{{n8n_...}}`: TemplateVariableScanner::extractFromEmail() reads each text on its own (subject first), the Variables N8N tab lists subject variables (the tab sends the typed subject and refreshes on `change`), the `n8n_` prefix rule and the unmapped check cover it, the error goes on the field that has the problem (`subject` or `customHtml`). The first save of a new Email is never blocked. Both modes.

## Limits and traps

```json
{
  "silent SMTP": "a server that accepts the connection and never answers held the cron ~73 s per contact. DirectEmailSender::SMTP_TIMEOUT (20 s, `default_socket_timeout` only around the send, put back) and the breaker: three failures in a row with the SAME reason stop the batch, the rest fail at once (SEND_STOPPED) and core retries them. 8 contacts to a silent server take ~110 s.",
  "volume": "~60 ms per Email (300 = 18 s, 1500 = 91 s), memory grows ~50 KB per contact (87 -> 147 MB for 300 -> 1500). The CLI memory_limit is 512M: ~8 thousand contacts per trigger run. For huge segments use smaller runs (`mautic:campaigns:trigger --max-events` / batch).",
  "re-entry": "a contact that re-enters gets the Email again (the n8n behaviour). Core's own 'already received this marketing Email' rule is NOT applied, on purpose.",
  "subject with a multi-value variable": "a Custom Object variable with several items is joined with '<br>' (the HTML separator, one value for subject and body: the tokens of a call are the same everywhere). In the SUBJECT it shows as literal 'A<br>B'. Do not use a multi-value variable in the subject. Values go in raw (no HTML escaping), as Mirror did.",
  "late Stat and re-entry": "the late Stat of a held-back Email is the newest of the contact and step made after the log: if the contact re-enters and gets a new Stat, the OLD held log can show that Stat. Rare; no bound by the next log's date yet.",
  "Timeline queries": "each direct card runs its own queries (factsOfLog, stats, DNC, hits): a contact with many direct Emails on the Timeline pays ~5 queries per card. The list batches the same data (states()).",
  "left-out contacts": "a contact left out by the Do Not Contact guard (passed with the reason, not failed) or failed by the stop gets a `n8ndispatch` block too (mode mautic, outcome failed, the resolved variables), so the screens read it as a Mautic dispatch and Reenviar can use the values.",
  "links with a variable": "href=\"{{n8n_link}}\" goes out with the right URL but is not click-tracked (Mautic does not track a link that is a token).",
  "A/B and translations": "Mautic picks the variant or the translation and sends ITS HTML; the mapping is the parent Email's, so only the variables with the same name are replaced.",
  "frequency rules": "marketing Emails obey the contact's and the global frequency rules; a held Email passes the step as `queued` and the Stat appears later (late Stat).",
  "failure notices": "Mautic emails the admin when a step fails and can unpublish a campaign whose step keeps failing (core, both modes).",
  "Stat time zone": "a Stat read back from the database has its time shifted by the zone: DirectEmailSender finds the new Stat by id (higher than the last one of the step), never by date.",
  "click tests": "Padrão: [testes.md](testes.md) (browser User-Agent for curl, `docker exec -i`, `-e MAUTIC_MAILER_DSN`, no `rm` in `sh -c`; the links carry the site URL, port 8080, inside the web container port 80)",
  "per instance (not code)": "the instance needs its SMTP (`mailer_dsn`), SPF / DKIM for the sender domain, and bounce handling (the provider's webhook or an IMAP bounce box) for Do Not Contact to learn bounces. With a queue (messenger) 'sent' means 'queued'."
}
```

## Tests

```json
{
  "PHPUnit": "EmailSendModeTest, DirectEmailSenderTest, MauticEmailOutcomeTest, MauticOutcomeExtensionTest, DispatchResenderTest (direct), DispatchFiltersTest, CampaignTriggerSubscriberTest (direct, breaker, through the real DirectEmailDispatch and EmailDncGuard); database tests in Tests/Integration/Service (MauticEmailOutcomeDbTest, DispatchListReaderMauticDbTest) run in a rolled-back transaction.",
  "live": "scripts/5/test-email-direct-send.sh (real campaign, the fake SMTP scripts/5/fake-smtp.py inside mautic_web: tokens, subject, click link, pixel, one-click unsubscribe, DNC, refused mailbox, resend, state read back), test-dispatch-end-to-end.sh still covers the n8n path.",
  "browser": "scripts/5/e2e/tests/email-send-mode-setting.spec.js, dispatch-mautic-card.spec.js, email-subject-variables.spec.js (light and dark).",
  "Infection": "`--filter=EventListener/CampaignTriggerSubscriber.php,Service/DirectEmailDispatch.php,Service/EmailDncGuard.php` Padrão: [testes.md](testes.md)."
}
```
