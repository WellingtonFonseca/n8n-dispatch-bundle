# n8n dispatch: HSM sent through the DialogHSM plugin

An HSM template can be sent by another plugin (the DialogHSMBundle, 360dialog) instead of n8n's webhook. Our part ends at its answer: the step passes ("Sucesso") or fails with the reason ("Falhou"). What happens to the message after the plugin accepted it (Meta, delivered, read, answered) is the plugin's: it has its own message log, webhook and timeline. An instance without the plugin must boot and work as before.

## Decisions

```json
{
  "template choice": "Each SMS and HSM template has a select 'Enviar por' (`provider`: `n8n` or `dialoghsm`; the HSM label shows `360Dialog`). With `dialoghsm` the Router field is a select of the numbers of the plugin (`whatsapp_number_id`). SMS has the select with N8N only.",
  "what dies for dialoghsm": "Meta callback, Meta badge, History, DispatchTracking, poll, backfill, 'Checar agora', the 'poll failed' alert, the n8n webhook and token, the router. What stays: status test / production / paused, body, response, success / failure, the campaign alerts.",
  "refusal at once": "A refusal (bad phone, missing template) FAILS the step with the reason. The plugin's own Resend handles what fails AFTER it accepted the message; ours only the first case.",
  "optional plugin": "HsmProviderGateway is the only door. Config/services.php registers DialogHsmGateway only where the plugin's classes exist; elsewhere the container gets NullHsmProviderGateway. No other class may name a class of the other plugin.",
  "not supported": "A button with a variable (dynamic URL) per card."
}
```

## Code

```json
{
  "gateway": "Service/Provider/HsmProviderGateway (isAvailable, numbers, numberName, send). send() never throws: every failure is a refused HsmSendResult with the reason. Service/Provider/Dialog/DialogHsmGateway calls the plugin's HANDLER (SendWhatsAppMessageHandler), not its API class: the handler writes its message log, timeline entry and the link its webhook follows by wamid.",
  "values": "HsmSendRequest (contact, phone, language, template, type, variables sorted by number, cards, number id, campaign and step ids; toArray() is the body shown on the card; fromArray() rebuilds it from the log) and HsmSendResult (accepted, wamid, http status, error, response). Both are excluded from autowire.",
  "send": "HsmCampaignTriggerSubscriber::dispatchThroughProvider, called from AbstractTemplateDispatchSubscriber after the Do Not Contact and phone checks (hooks sendsThroughProvider and dispatchThroughProvider). A variable with no text fails the step BEFORE the call (the Meta refuses it): reason VARIABLE_EMPTY. Test and paused steps show what would be handed over.",
  "card on the log": "Service/Provider/HsmProviderOutcome::card writes metadata.n8ndispatch = {mode: dialoghsm, status, request, and once sent: outcome sent|failed, wamid, result}. outcomeOf() is what the screens read (null for a test, a pause or an n8n log).",
  "images": "The plugin builds the image header only for .jpg, .jpeg and .png; another link is dropped in silence. HsmProviderFields (form) and the gateway refuse it. The carousel needs the carousel change in the plugin's repo on that instance.",
  "screens": "Dispatches table: callback column Sucesso / Falhou from providerState; the status filter follows it (DispatchFilters::providerStatusOf, DispatchListReader::statusOfRow). Card: Resources/views/SubscribedEvents/Timeline/_dispatch_provider.html.twig (details table, body, response). Same card on the details modal and the contact Timeline.",
  "resend": "Service/Provider/ProviderResender, called by DispatchResender for a provider log after the step-in-production check. Only outcome failed is resent, with the request of the log or the values of the resend form (ResendVariables reads request.variables as the first send). Same log row; an accepted resend clears core's failed log (Service/CoreFailedLog); a refused one stays failed and raises ResendFailedAlert. The plugin's handler lock (same event and contact within 1 h) does not block a log in `failed`. The button shows only on a row with providerState failed."
}
```

## Traps

- Padrão: [stack-mautic-armadilhas.md](stack-mautic-armadilhas.md) (services.php autowire exclusions Service/Provider/Dialog, HsmSendRequest, HsmSendResult; `scripts/5/test-plugin-boots.sh N8nDispatchBundle n8n_dispatch`).
- Padrão: [ui-textos-idioma.md](ui-textos-idioma.md) (changed translation/template needs `cache:clear` and `apachectl graceful`; reason text follows the language of the cron, tests compare the prefix and the `{{n}}`).
- The same contact in the same step again within 1 h: the plugin's handler answers success without resending.
- Padrão: [testes.md](testes.md) (Chosen select, spec data dated `NOW()`, specs skip without the plugin via `dialogHsmInstalled()`; the DialogHSM integration must be published, `seed-dialoghsm.sh` does it, the live test fails with that command when it is missing).
- Padrão: [testes.md](testes.md) (campaign and segment prefix `lib/campaign-prefix.sh`; `DIALOGHSM_DISABLE_SSRF_CHECK=1` only on the cron command).

## Names to review when a second provider arrives

- `whatsapp_number_id` (column, property, getter) is better as `dialoghsm_number_id`: `RENAME COLUMN` guarded by `hasColumn` in `onPluginUpdate`, version up.
- `HsmProviderPolicy` is better as `DialogHsmProviderPolicy`; its generic part needs a registry of gateways by provider (the container has one alias today).
- Texts `number.label`, `number.placeholder`, `number.missing` and the messages `number_required`, `number_missing` get the prefix `dialoghsm.`.
- Already right: `DialogHsmGateway`, the folder `Service/Provider/Dialog`, the value `dialoghsm`. Generic on purpose: `HsmProviderGateway`, `NullHsmProviderGateway`, the column `provider`.

## Tests

PHPUnit: HsmSendTest, HsmProviderOutcomeTest, ProviderResenderTest, HsmCampaignTriggerSubscriberProviderTest, DialogHsmGatewayTest. Live: `scripts/5/test-hsm-dialoghsm-send.sh` (fake 360dialog `scripts/5/fake-360dialog.php`; accepted, refused, empty variable, resend). Browser: `dialoghsm-dispatch-card.spec.js`. Demo data: `scripts/5/seed-dialoghsm-dispatch-demo.sh` (`--remove` takes it out), `scripts/5/seed-dialoghsm.sh` (numbers and template).
