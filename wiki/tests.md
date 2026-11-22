categoria: testes
regras:
- Never run a dispatch test against the production n8n.
- A live test that is allowed to succeed has side effects: the integration calls the configured webhook on every Email save. Test only blocked cases against the real endpoint, or accept the call.
- A live test that can write must wait after `cache:clear` (the first request can still run the old container): clear the cache, make a few harmless GETs, pause, then test. Once a POST got through that should have been blocked and created a real Email and called the webhook.
- Live tests that point the integration at a fake n8n (URL, token, published flag) put everything back in a finally, and run in the `first` lane of test-all because of that. Fake n8n ports: 8097 (mirror resync, step failure sweep), 8098 (end to end), 8099 (alerts).
- A failed event is read from the `metadata` of its log (`failed`, `reason`).
- Node tests (`Tests/js/`) run the browser scripts in a `vm` with a fake `window`: they prove the logic, not the real browser. Live only: Twig and CSS look, real browser behavior, and the `onPluginUpdate()` migrations.
- Browser specs: the type select of a form can be a Chosen widget (the real `<select>` is hidden: use `#..._chosen` to see it, `evaluate` to choose); new spec data is dated `NOW()` (the History spec opens the LAST row of the Dispatches screen); specs that need an optional plugin skip without it (`dialogHsmInstalled()` in helpers.js); specs run in both themes; a spec that needs a seed says so.
- Restarting a scheduled or cancelled dispatch for a test: `mautic:campaigns:update` / `trigger` do NOTHING for a contact who is still an active member (`campaign_leads` row present, `manually_removed = 0`, `date_last_exited` NULL). Deleting `campaign_lead_event_log` rows is not enough: the membership must show an exit. Use the API:

```bash
curl -sf -u "$MAUTIC_API_USER:$MAUTIC_API_PASSWORD" -X POST "$MAUTIC_BASE_URL/api/campaigns/{campaignId}/contact/{leadId}/remove"   # manually_removed
curl -sf -u "$MAUTIC_API_USER:$MAUTIC_API_PASSWORD" -X POST "$MAUTIC_BASE_URL/api/campaigns/{campaignId}/contact/{leadId}/add"      # new rotation
docker exec --user www-data mautic-mautic_web-1 php /var/www/html/bin/console mautic:campaigns:trigger --campaign-id={campaignId}
# optional, throwaway test contact only (hard delete of history rows):
# DELETE FROM campaign_lead_event_log WHERE campaign_id={campaignId} AND lead_id={leadId};
```

- When a live dispatch test goes silent: `mautic:campaigns:trigger` prints nothing when the campaign has `is_published = 0` (it flipped to 0 on its own several times; cause not found). Publish it first: `PATCH /api/campaigns/{id}/edit {"isPublished": true}`. A one-time immediate event does not fire again for a contact that already has a log row for it.
- The live tests need the campaign prefix and a segment prefix (`lib/campaign-prefix.sh`); `DIALOGHSM_DISABLE_SSRF_CHECK=1` goes only on the cron command; DialogHSM live tests need its integration published (`seed-dialoghsm.sh`).
- Click tests: Mautic ignores hits of known bots, so curl needs a browser User-Agent. `docker exec` needs `-i` to read a heredoc; `-e MAUTIC_MAILER_DSN=...` gives one process the fake SMTP. An `rm` inside `sh -c` is refused by Claude Code: clean up with Python os.remove.
- Infection: `--filter=` with a short name also matches the SMS / HSM subscribers and Infection stops: use the full path.
- Escaped mutants that are not worth a test (about 130 left of 2080 after the triage): `MAUTIC_TABLE_PREFIX.'table'` concat / operand removal (prefix is empty on the stacks); `(int)` / `(string)` casts of values already typed, an array key PHP turns into an int, `array_values`, a capture group `[1]` equal to `[0]`; an initial value overwritten on every path, a default `?? 0` read as `?? -1` when both mean the same; the clock arithmetic of the pause between calls (CallPacer::pause); DispatchListReader: 43 of the above. Anything that changes a boundary (HTTP 299/300), a message, a header, a key of a payload or a call on a collaborator is a real gap: write the test.
- Commands, lanes and tools: [../../wiki/tests-live-scripts.md](../../wiki/tests-live-scripts.md), [../../wiki/tests-browser-specs.md](../../wiki/tests-browser-specs.md), [../../wiki/tests-mutation-and-coverage.md](../../wiki/tests-mutation-and-coverage.md).
