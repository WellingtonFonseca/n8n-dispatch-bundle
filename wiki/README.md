# n8n-dispatch-bundle: wiki

Notes of the N8N Dispatch plugin for Mautic 5. Read the pattern files first: they hold the rules every feature follows (look of badges, modals, buttons and lists, texts, traps of the stack and of the tests). A feature file explains how one feature works and points to the pattern with `Padrão: arquivo.md`. Start with [n8n-dispatch-plugin.md](n8n-dispatch-plugin.md) for the plugin overview.

## Padrões

### ui

- [ui-badge-n8n.md](ui-badge-n8n.md): the status pill, the canvas badge and the Status column icon badge (twin of the patches plugin; shared rules in [../../wiki/ui-badge.md](../../wiki/ui-badge.md) and [../../wiki/ui-status-column.md](../../wiki/ui-status-column.md)).
- [ui-modal.md](ui-modal.md): modal width, footer buttons, details modals, middle click, confirmation.
- [ui-botoes.md](ui-botoes.md): core's button component, row menu items, options dropdown, `<button>` vs `<a>`.
- [ui-lista-card.md](ui-lista-card.md): lists, log tables, Timeline / details cards, dark themes.
- [ui-textos-idioma.md](ui-textos-idioma.md): translations (en_US / pt_BR), dates, failure reasons, icon.

### api

- [api-payload.md](api-payload.md): the dispatch metadata and payload rules shared by the channels.

### stack

- [stack-mautic-armadilhas.md](stack-mautic-armadilhas.md): traps of Mautic core, the container cache, services.php, entities and forms.

### testes

- [testes.md](testes.md): traps shared by the tests (live, specs, mutants), restarting a dispatch for a test.

## Funcionalidades

- [n8n-dispatch-plugin.md](n8n-dispatch-plugin.md): what the plugin is, tables, settings (start here).
- [n8n-dispatch-payload-variables.md](n8n-dispatch-payload-variables.md): payload, variables, rules checked when a template is saved.
- [n8n-dispatch-email.md](n8n-dispatch-email.md): Email sync to Mirror and the Email dispatch.
- [n8n-dispatch-mirror-resync.md](n8n-dispatch-mirror-resync.md): Mirror sync state and the resync job (`n8ndispatch:mirror:resync`).
- [n8n-dispatch-email-direct.md](n8n-dispatch-email-direct.md): Email sent through Mautic's own mailer (send mode per instance), result read from Mautic, resend, limits.
- [n8n-dispatch-campaign-actions.md](n8n-dispatch-campaign-actions.md): the campaign actions Email / SMS / HSM.
- [n8n-dispatch-templates.md](n8n-dispatch-templates.md): SMS and HSM templates, lists, details modal.
- [n8n-dispatch-provider.md](n8n-dispatch-provider.md): HSM sent through the DialogHSM plugin ("Enviar por", gateway, Sucesso / Falhou card, resend of a refusal); read with [n8n-dispatch-dispatches-screen.md](n8n-dispatch-dispatches-screen.md).
- [n8n-dispatch-template-history.md](n8n-dispatch-template-history.md): history of the SMS and HSM templates (audit_log, Histórico tab); the email page is in the patches wiki (`patches-email-page.md`).
- [n8n-dispatch-templates-hsm-cards.md](n8n-dispatch-templates-hsm-cards.md): HSM types Carousel and Image (cards, validation, form block, n8n Code nodes); see also [n8n-dispatch-templates.md](n8n-dispatch-templates.md).
- [n8n-dispatch-template-lists.md](n8n-dispatch-template-lists.md): Status column (icon badge, Ativar / Desativar) of the SMS and HSM template lists.
- [n8n-dispatch-status-poll.md](n8n-dispatch-status-poll.md): status poll (callback) and the unified History.
- [n8n-dispatch-dispatches-screen.md](n8n-dispatch-dispatches-screen.md): Dispatches screen, Resend, Timeline cards.
- [n8n-dispatch-resend-form.md](n8n-dispatch-resend-form.md): the Reenviar modal (variables, message preview, values sent); read with [n8n-dispatch-dispatches-screen.md](n8n-dispatch-dispatches-screen.md).
- [n8n-dispatch-alerts.md](n8n-dispatch-alerts.md): alerts to n8n (campaign switched off, failure warning, bounce spike, poll failed, resend failed), the Alertas tab.
- [n8n-dispatch-failing-steps.md](n8n-dispatch-failing-steps.md): a failing step (retry, stuck contacts, shield against campaign unpublish, sweep, `campaign.step_failing`).
- [n8n-dispatch-traps-and-tests.md](n8n-dispatch-traps-and-tests.md): traps, open points and how the plugin tests run.
- [n8n-documents-sync-script.md](n8n-documents-sync-script.md): the n8n Code node that syncs documents.
