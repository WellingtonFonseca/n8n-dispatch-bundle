# n8n dispatch: Email sync to Mirror and the Email dispatch

The Email save sync to Mirror (`email.save`) and how an Email dispatch behaves: the Stat, the view link, the unsubscribe link, test / production / paused.

## Email save sync to Mirror

For an Email to go out through n8n it must already be registered as a template in Mirror (n8n sends template id + variables, not HTML). Registering at SAVE time (one save at a time) avoids the duplicates that registering at dispatch time creates when sends fire close together.

```json
{
  "trigger": "EmailEvents::EMAIL_POST_SAVE, from the UI and from POST /api/emails/new and PATCH /api/emails/{id}/edit. Listener EventListener/EmailMirrorSyncSubscriber.php (autoconfigure tags any EventSubscriberInterface); the send and the payload are Service/MirrorSync.php and Service/MirrorSyncPayload.php.",
  "payload": "POST {webhook_url} {mautic_template_id, subject, html, hash, from_name, from_address, modified_by_email}",
  "headers": "X-N8n-Dispatch-Token and X-N8n-Dispatch-Action: email.save (it was template.sync: any n8n node that still routes on 'template.sync' stops triggering)",
  "html": "the customHtml column. A themed Email (Builder) keeps its source in `content` (serialized slots) and customHtml is a snapshot compiled only by EmailController's real edit/save screen. A raw API PATCH of other fields can leave customHtml empty on a themed Email even though the Email looks fine in the UI. For real use (someone saves in the UI) it is never a problem.",
  "from_name / from_address": "read off the Email (getFromName/getFromAddress). null means 'uses the system default sender', not 'no sender' (the fallback is not resolved in the payload). The save rule (wiki/n8n-dispatch-payload-variables.md) makes them required anyway.",
  "modified_by_email": "UserHelper::getUser(true), the authenticated user of the request. Not the Email's modifiedBy (an int id and a name, never an email, and not set on creation). null when there is no user (CLI, jobs): valid.",
  "state": "every save records its result in n8n_dispatch_mirror_sync (ok, failed, skipped); the resync job sends the Emails out of sync again: wiki/n8n-dispatch-mirror-resync.md. The endpoint still decides whether anything changed in Mirror.",
  "failure": "a webhook failure (down, non-2xx, a 2xx with a JSON `error`) is logged (channel 'app' in var/logs/prod-*.php), recorded as failed and never blocks the Email save: the listener runs on POST_SAVE. The user sees no message. Symfony's HttpClient is lazy: transport errors only surface when the response is read, so getStatusCode() is called inside the try. The save waits for n8n: at most 30 s without a byte or 60 s in all (MirrorSync::TIMEOUT, MAX_DURATION), then the call counts as failed.",
  "empty HTML": "a customHtml that is empty or only spaces is not sent and gets no footer (status skipped).",
  "unsubscribe footer": "the same save injects an idempotent unsubscribe block into customHtml (UnsubscribeVariable::TOKEN, the placeholder {{n8ndispatch_unsubscribe_url}}, delimited by HTML comments, replaced wholesale on every save) so the dispatch can resolve a working unsubscribe link. It is persisted with $entityManager->getUnitOfWork()->scheduleForDirtyCheck($email) before flush(): Mautic entities use Doctrine's DEFERRED_EXPLICIT tracking (ClassMetadataBuilder::setChangeTrackingPolicyDeferredExplicit()), so a flush of an already-managed entity changed outside its model's saveEntity() writes NOTHING without that call, with no error. Any direct flush() of a Mautic entity needs it. The outgoing payload is built from the in-memory Email, so Mirror always had the footer."
}
```

## Dispatch behavior

This is the n8n path. An instance set to send the Email through Mautic's mailer takes another branch of the same action (no webhook, no Stat of ours): wiki/n8n-dispatch-email-direct.md.

```json
{
  "email.send": "EventListener/CampaignTriggerSubscriber.php. Per contact: VariableResolver resolves the variables, then POST {mautic_template_id, contact_*, status, language, variables} with action header email.send. 'paused' and 'test' short-circuit before loading the Email: recordWithoutDispatch() records the payload and PASSES the step, so the contact moves on.",
  "sms.send / hsm.send": "EventListener/SmsCampaignTriggerSubscriber.php (the message is resolved in Mautic, there is no Mirror template for SMS; id logSendSmsId) and HsmCampaignTriggerSubscriber.php (router/hsmTemplate/type/variables read from the HsmTemplate; id logSendHsmId; a 2xx with an EMPTY body counts as a failure). Same status field, variable picker, Timeline card and failure handling as Email. No native SmsBundle Stat/Sms row is created.",
  "failure reason": "a failed contact gets $event->fail($log, reason) and never affects the others of the batch; core retries it every hour and the failure never unpublishes the campaign (wiki/n8n-dispatch-failing-steps.md). The reason is n8n's `error` string when the body is JSON with a top-level `error` string (n8n's 'Respond to Webhook' node, fed by a Code node that RETURNS {statusCode, statusMessage, error} instead of throwing: a thrown Error aborts the execution and n8n answers a generic 'Error in workflow'), else 'webhook returned HTTP <n>'. Core's Timeline template reads item.metadata.reason for a failed event, so it shows on the contact's History. A real HSM refusal nests it (body.error.message) and only the HTTP line shows (open point).",
  "webhook and headers of a dispatch": "Service/WebhookSettings::forAction($integrationHelper, $action) returns [url, headers] or the DispatchFailureReasons key of what is missing (INTEGRATION_DISABLED, WEBHOOK_MISSING). The Email, SMS and HSM subscribers and DispatchResender use it. StatusPoller and EmailMirrorSyncSubscriber read the same settings on their own (different errors).",
  "full response always recorded": "recordDispatchOutcome() (each subscriber's own, which hands the work to Service/DispatchOutcomeRecorder::record(): the three channels differ only in the ids n8n returns) runs on EVERY outcome, before the pass/fail branch, and writes metadata['n8ndispatch'] (payload, response, httpStatusCode). PendingEvent::fail() only array_merge()s 'failed'/'reason' over the existing metadata, it never clears it. The outcome badge reads httpStatusCode (Padrão: [api-payload.md](api-payload.md)).",
  "send-log id": "logSendEmailId / logSendSmsId / logSendHsmId, flat or under 'body' in n8n's answer (n8n forwards Mirror's raw output nested), kept as its own metadata key and, for Email, written to metadata['timeline'] ('N8nDispatch: sent via n8n/Mirror (log id: N).'), the one key core's Timeline template always renders. Best effort: a missing id never blocks the pass.",
  "failure reasons are translated": "Padrão: [ui-textos-idioma.md](ui-textos-idioma.md) (prefix `N8nDispatch: `, translated when the step runs, what n8n answers passes through)""
}
```

### Email dispatch: Stat, view link, unsubscribe

The dispatch never goes through Mautic's mailer, so none of the `email_stats` rows that core relies on (the native "Sent Email" Timeline entry, `/email/unsubscribe/...` incl. RFC 8058 one-click, Do Not Contact on unsubscribe) would exist. `CampaignTriggerSubscriber` therefore creates a real Stat on every 'production' dispatch (never test/paused):

```json
{
  "unsubscribe": "the {{n8ndispatch_unsubscribe_url}} placeholder is resolved to a real mautic_email_unsubscribe URL (EmailModel::buildUrl() from a generated idHash; buildUnsubscribeUrl() is a pure builder, no DB write) and sent in 'variables' under UnsubscribeVariable::KEY.",
  "Stat": "createStat($email, $contact, $contactEmail, $idHash, $templateCopyHash, $isFailed) on every outcome (success, HTTP >= 300, exception). With is_failed = 1 core's LeadSubscriber shows an 'Email failed' entry.",
  "view in browser": "the native entry's link (/email/view/{idHash}, PublicController) renders $stat->getStoredCopy(), empty unless set: the Stat is linked to the Copy row that saveTemplateCopy() snapshots (setStoredCopy(getReference(Copy::class, hash))). EntityManagerInterface is injected into the subscriber because CopyRepository::getEntityManager() is protected.",
  "accepted core quirk": "StatRepository::getLeadStats() has a branch for 'read' and 'failed' but none for 'sent', and date_sent is NOT NULL, so a failed dispatch's Stat shows in BOTH 'Email sent' and 'Email failed'. Core offers no way around it without editing core. Skipping the Stat on failure was tried and reverted: a visible 'Email failed' entry is worth more to the user.",
  "no open-tracking pixel": "a 1x1 pixel pointing at core's mautic_email_tracker never flipped is_read against a real Gmail inbox (clients proxy, cache or block remote images). Do not add one.",
  "Timeline card badge": "an outcome badge ('Success' when the HTTP status is < 300, 'Failed' otherwise; Padrão: [ui-badge-n8n.md](ui-badge-n8n.md)). There is no 'View template' link: the native entry's view link works."
}
```
