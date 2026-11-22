# n8n dispatch: traps, language, open points and tests

The traps of the plugin, its language and translations, the open points and how its tests run.

## Traps

- **Debounced scans race with a re-rendered form.** The HSM/SMS scan waits 400 ms after the last key. If Save is clicked right after typing, the save is blocked, the page returns a NEW form, and the old request answers for a field no longer on the page (it removed the new alert and wrote into the detached DOM). Work for a field that left the page is dropped (`document.body.contains`), and the alert does not depend on the answer.
- Padrão: [stack-mautic-armadilhas.md](stack-mautic-armadilhas.md) (unsubmitted form `isValid()`, core API error cause, cache:clear first request).
- Padrão: [testes.md](testes.md) (side effects of live tests, wait after cache:clear).
- **Same word, two things**: see 'HSM field names'.

## Language and translations

Padrão: [ui-textos-idioma.md](ui-textos-idioma.md)

## Open points

- A segment or decision cannot act on a delivery failure (the poll is information only).
- The reason line of a refused HSM dispatch shows only the HTTP status (nested `body.error.message` is not read): `DispatchResender`, `DispatchFailureReasons::fromResponseBody` (used by the Email, SMS and HSM subscribers).
- A node is always named after its template (campaign save, rename of the template, `n8ndispatch:events:sync-names`). The canvas box shows the template name and the Timeline's campaign.event row shows the node name: a node whose name was never synced reads like a skipped step.
- Scripts in `mautic/scripts` that create contacts, companies or segments through the API need updating for the current fields ([../wiki/contact-api-and-fields.md](../../wiki/contact-api-and-fields.md)) and the required segment prefix ([../mautic-patches-bundle-5/wiki/mautic-patches-bundle.md](../../mautic-patches-bundle-5/wiki/mautic-patches-bundle.md)).

## Tests

PHPUnit in `n8n-dispatch-bundle/Tests/Unit/` and, against the real database, `Tests/Integration/` (../wiki/tests-live-scripts.md, "integration tests"; the Dispatches list queries and the usage finders; command in [../wiki/docker-mautic5.md](../../wiki/docker-mautic5.md)): the resolvers (all sources, segment-condition grouping), the Email sync, the three dispatch subscribers (Stat and Copy linking, unsubscribe, outcome on pass and fail), templates and usage finders, the Email delete guard, the Email/HSM/SMS rules, status poll, history, resend, the Dispatches screen, node naming, the AJAX endpoints, translation parity. Node tests in `Tests/js/` (alert builders, mapped/unmapped rule, numeric rule, the race guard, HSM type toggle, Check now, template text race). Live, end to end: `scripts/5/test-dispatch-end-to-end.sh` runs a real campaign (Email, SMS, HSM in production) against a fake n8n made by the script itself (a PHP server in the web container, port 8098, own ids): the requests, the token header, the variables resolved from a contact field, the tracking rows and the logs, and a contact with no phone (the Email goes, SMS and HSM fail with no request). It points the integration at the fake (URL, token, published flag) and puts all back in a finally; it is in the `first` lane of test-all because of that. `scripts/5/test-step-failure-sweep.sh` covers the failing-step shield and sweep (wiki/n8n-dispatch-failing-steps.md) and `test-alerts-channel.sh` the bounce alert. Padrão: [testes.md](testes.md) (never against the production n8n; what only a live test proves).

## Escaped mutants that are not worth a test

Padrão: [testes.md](testes.md)

