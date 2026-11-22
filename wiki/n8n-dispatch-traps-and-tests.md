# n8n dispatch: traps, language, open points and tests

The traps of the plugin, its language and translations, the open points and how its tests run.

## Traps

- **Debounced scans race with a re-rendered form.** The HSM/SMS scan waits 400 ms after the last key. If Save is clicked right after typing, the save is blocked, the page returns a NEW form, and the old request answers for a field no longer on the page (it removed the new alert and wrote into the detached DOM). Work for a field that left the page is dropped (`document.body.contains`), and the alert does not depend on the answer.
- **An unsubmitted form field throws on `isValid()`.** An API PATCH that omits `customHtml` leaves that child unsubmitted and `isValid()` raised 'Cannot check if an unsubmitted form is valid' (HTTP 500). Use `getErrors()` in form extensions.
- **Core's API error handling reads `$error->getCause()->getCode()`.** A `FormError` with no cause crashes it (500): every extension here gives its errors a `ConstraintViolation` as the cause.
- **Right after `cache:clear` the first request can still run the old container** (../wiki/docker-mautic5.md). A live test that can write must wait: clear the cache, make a few harmless GETs, pause, then test. Once a POST got through that should have been blocked and created a real Email and called the webhook.
- **A live test that is allowed to succeed has side effects**: the integration calls the configured webhook on every Email save. Test only blocked cases against the real endpoint, or accept the call.
- **Same word, two things**: see 'HSM field names'.

## Language and translations

- Plugin texts: `Translations/en_US` and `pt_BR`, four files each: `messages.ini` (screens), `flashes.ini` (delete refusals), `validators.ini` (validation errors) and `javascript.ini` (texts the browser scripts draw). Every key has a pt_BR text with the same placeholders; `Tests/Unit/Translations/TranslationParityTest.php` fails otherwise. Tests that check a reason use `Tests/Unit/EventListener/EnUsTranslator.php` (serves the real en_US texts of messages.ini and flashes.ini).
- Texts in the browser come from `window.mauticLang` (filled from `javascript.ini` in the system language, merged over en_US); the scripts keep an English fallback (`translate()` in n8ndispatch-shared.js, `labelFor()` in campaign-preview-icons.js).
- Core's own words (tab names, field labels such as "Advanced", "From name") are not ours to translate: messages that name them use core's translation keys. They are English in pt_BR until the core pack is installed.
- The core pt_BR pack is installed from `https://language-packs.mautic.com/` through Settings > Configuration. In dev it lives in `mautic/mautic/translations` (a bind mount: ../wiki/docker-mautic5.md). `config/local.php` must be writable by `www-data` (uid 33) or the Configuration screen cannot save (`chmod 666`, git-ignored).
- Plugin icon: `Assets/img/icon.png` and an identical `Assets/img/n8ndispatch.png` (200x200, transparent; the plugin's card is an INTEGRATION card, looked up as strtolower(integration name).png by AbstractIntegration::getIcon(); a plain plugin card uses icon.png). The n8n symbol only (no wordmark), so it reads on light and dark themes. Covered by `Tests/Unit/PluginIconTest.php`.

## Open points

- A segment or decision cannot act on a delivery failure (the poll is information only).
- The reason line of a refused HSM dispatch shows only the HTTP status (nested `body.error.message` is not read): `DispatchResender`, `DispatchFailureReasons::fromResponseBody` (used by the Email, SMS and HSM subscribers).
- A node is always named after its template (campaign save, rename of the template, `n8ndispatch:events:sync-names`). The canvas box shows the template name and the Timeline's campaign.event row shows the node name: a node whose name was never synced reads like a skipped step.
- Scripts in `mautic/scripts` that create contacts, companies or segments through the API need updating for the current fields ([../wiki/contact-api-and-fields.md](../../wiki/contact-api-and-fields.md)) and the required segment prefix ([../mautic-patches-bundle-5/wiki/mautic-patches-bundle.md](../../mautic-patches-bundle-5/wiki/mautic-patches-bundle.md)).

## Tests

PHPUnit in `n8n-dispatch-bundle/Tests/Unit/` and, against the real database, `Tests/Integration/` (../wiki/tests-live-scripts.md, "integration tests"; the Dispatches list queries and the usage finders; command in [../wiki/docker-mautic5.md](../../wiki/docker-mautic5.md)): the resolvers (all sources, segment-condition grouping), the Email sync, the three dispatch subscribers (Stat and Copy linking, unsubscribe, outcome on pass and fail), templates and usage finders, the Email delete guard, the Email/HSM/SMS rules, status poll, history, resend, the Dispatches screen, node naming, the AJAX endpoints, translation parity. Node tests in `Tests/js/`: the browser scripts run in a `vm` with a fake `window`, so they prove the logic, not the real browser (alert builders, mapped/unmapped rule, numeric rule, the race guard, HSM type toggle, Check now, template text race). Live, end to end: `scripts/5/test-dispatch-end-to-end.sh` runs a real campaign (Email, SMS, HSM in production) against a fake n8n made by the script itself (a PHP server in the web container, port 8098, own ids): the requests, the token header, the variables resolved from a contact field, the tracking rows and the logs, and a contact with no phone (the Email goes, SMS and HSM fail with no request). It points the integration at the fake (URL, token, published flag) and puts all back in a finally; it is in the `first` lane of test-all because of that. `scripts/5/test-step-failure-sweep.sh` covers the failing-step shield and sweep (wiki/n8n-dispatch-failing-steps.md) and `test-alerts-channel.sh` the bounce alert. A failed event is read from the `metadata` of its log (`failed`, `reason`). Never run a dispatch test against the production n8n. Live only: Twig and CSS look, real browser behavior, and the `onPluginUpdate()` migrations.

## Escaped mutants that are not worth a test

After the Infection triage of the plugin (about 130 left of 2080), what is left is equivalent here: do not write tests for it.

- `MAUTIC_TABLE_PREFIX.'table'` concat / operand removal: the prefix is empty on the stacks.
- `(int)` / `(string)` casts of values that are already typed, an array key that PHP turns into an int, `array_values`, and a capture group `[1]` equal to `[0]`.
- An initial value overwritten on every path (`$created = 0`), a default of `?? 0` read as `?? -1` when 0 and -1 mean the same.
- The clock arithmetic of the pause between calls (CallPacer::pause, used by StatusPoller and MirrorResync): it depends on real time.
- DispatchListReader: 43 of the above (casts and the prefix).
Anything that changes a boundary (HTTP 299/300), a message, a header, a key of a payload or a call on a collaborator is a real gap: write the test.

