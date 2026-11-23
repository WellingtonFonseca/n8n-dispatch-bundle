# n8n dispatch: SMS and HSM templates

The SMS and HSM templates, their lists (Router and HSM Template columns, and for HSM a Tipo column between HSM Template and ID: the name of the type as plain text, sortable, texts of mautic.n8ndispatch.hsmtemplate.type.*; the Status column is in wiki/n8n-dispatch-template-lists.md), details modal. The HSM carousel and image types are in wiki/n8n-dispatch-templates-hsm-cards.md.

## SMS Templates and HSM Templates

Each template has the select "Enviar por" (`provider`); an HSM with `360Dialog` is sent by the DialogHSM plugin: wiki/n8n-dispatch-provider.md. The "Histórico" tab (last tab of the edit page and of the details modal) lists every change: wiki/n8n-dispatch-template-history.md.

The text and variable mapping live in a template that steps point to, so editing it reaches every campaign that uses it (read at send time).

```json
{
  "SMS entity": "Entity/SmsTemplate.php, table n8n_dispatch_sms_templates (name, description, text, variables_json, language + published/created/modified). Screen main menu > N8N Dispatch > SMS: standard Mautic list/new/edit/clone/delete (Controller/SmsTemplateController.php). Uses core's SMS permissions (sms:smses). The step stores the id as 'smsTemplate'. A missing template fails the whole batch ('SMS template #<id> not found.'). Steps saved before templates existed keep their inline text/variablesJson; opening one to edit requires picking a template.",
  "HSM entity": "Entity/HsmTemplate.php, table n8n_dispatch_hsm_templates (name, description, router, hsmTemplate, type, text, variables_json, cards, language + the usual). Screen main menu > N8N Dispatch > HSM, Controller/HsmTemplateController.php, same permissions (core has no WhatsApp permission set). The step stores the id as 'hsmTemplateId'. Old steps keep their inline router/hsmId/variablesJson and always get type text.",
  "HSM field names": "hsmTemplate is the WhatsApp-side template name (it was called hsmId; it is not an id), labeled 'HSM Template', second after router. hsmTemplateId (campaign step) is the id of the HsmTemplate ENTITY, an int. Do not mix them. `text` is never sent to n8n/WhatsApp (WhatsApp has the real template); it exists so the same {{name}} scanning drives the variable-source picker.",
  "pill size": "Padrão: [ui-badge-n8n.md](ui-badge-n8n.md) (the one class `.n8ndispatch-badge`, same change goes to the twin)",
  "canvas badge hover": "Padrão: [ui-badge-n8n.md](ui-badge-n8n.md) (spec n8n-status-badge-hover)",
  "menu entry": "the main menu entry is written `N8N Dispatch` (translation mautic.n8ndispatch.menu.root). The plugin is `N8N Dispatch` everywhere a person reads it: Config/config.php `name`, `N8nDispatchIntegration::getDisplayName()`, composer.json `display-name`, the two messages that name the integration (en_US, pt_BR; the English one is also the poll's error text, tested in DispatchFailureReasonsTest) and the docs. Its icon is n8n's official symbol without the name (Assets/img/n8n-logo.svg, ONE path, viewBox 0 0 240 96, the official n8n-symbol-accent.svg from guidelines.n8n.design; never redraw it), set by `iconClass => n8ndispatch-logo-icon` in Config/config.php and drawn by n8ndispatch.css as a CSS mask over `currentColor`, so it takes the menu text color in every theme (the fill color of the file does not matter). Core renders the icon as `<span class=\"icon pull-left fa <iconClass>\">` and forces that box to 26px wide (`.icon`), so a width in the plugin CSS is ignored: the size of the logo is the mask size (`24px auto`), the box stays 26px so the names line up. The symbol is 2.5 times as wide as tall (24px wide = 9.6px tall). Mautic's root font is 10px: 1rem is 10px, not 16. Spec n8n-menu-logo.",
  "plugin tile image": "the tile of Settings > Plugins shows Assets/img/n8ndispatch.png (icon.png is the same file): n8n's official symbol without the name, pink #FF91AC, 120 px wide on the disc, 200 x 200, transparent, made by rendering the path of the official SVG with Chromium (long shadow #303d6c). It is NOT gray when the plugin is on: core grays only tiles with `integration-disabled` (filter on the img), and the tiles FADE IN when the page opens, so a screenshot taken at once shows every logo gray: wait for opacity 1 and filter none. Spec n8n-plugin-tile.",
  "details modal": "the name on the SMS and HSM template lists opens a read-only modal (data-toggle=ajaxmodal into #MauticSharedModal), not the edit page; the edit stays in the row's options menu and in the modal's Edit button. Route {objectAction}=details (detailsAction in both controllers, permission sms:smses:viewown/viewother, Edit button only with editown/editother). Views {Sms,Hsm}Template/details.html.twig with the shared partials Template/_variables, _campaigns, _modal_buttons; Service/TemplateVariableRows turns variablesJson into rows. At the top of the Template tab an Active / Inactive badge from isPublished, with 'Available for use' as its title, replaces a plain Yes/No line (Padrão: [ui-badge-n8n.md](ui-badge-n8n.md)). HSM only: no badge in the tab; the modal head (.n8ndispatch-modal-head) shows the template name as title and, below it, the same icon badge as the list's Status column, as the patches details modals. Two tabs, Template (fields, text, variables; HSM adds router, template, type, carousel cards) and Campaigns. Footer buttons (Edit first, Close last), middle click on the name (Assets/js/template-modal-middle-click.js) and the Dispatches link not covered: Padrão: [ui-modal.md](ui-modal.md). Twig 3 has no `for ... if`: use |filter (ui-list-card.md). Spec: scripts/5/e2e/tests/template-details-modal.spec.js (it fails without the script).",
  "Template / Campaigns tabs": "both edit pages split into a Template tab and a Campaigns tab (campaigns using it: link, step name, status pill; shown only when the entity has an id). Plain nav-tabs markup written in the plugin's own twig.",
  "delete protection": "a template used by a campaign cannot be deleted, singly or in a batch (\"The SMS can't be deleted: it is used.\", no names: the Campaigns tab shows where; unused ones in the same batch are still deleted). Service/AbstractTemplateUsageFinder holds the query and the matching (it loads every non-deleted step of the event type and matches the id in PHP, because it sits inside a serialized column); Email, Sms and HsmTemplateUsageFinder only set EVENT_TYPE and PROPERTY_KEY.",
  "controllers": "Controller/AbstractTemplateController.php holds the screens of both template types (index, new, edit with the Campaigns tab, details modal, clone, delete and batch delete with the in-use protection); SmsTemplateController and HsmTemplateController only give their views folder (getTemplateBase), model (getModelName, which also builds the flash key mautic.<model>.error.in_use), their constructor and their own usage finder. Both use the sms: permissions. The finder stays a constructor argument (see the clone TypeError trap below). scripts/5/e2e/tests/template-controllers.spec.js covers both channels; the view-permission check of details is not covered (the test user is admin).",
  "Email delete protection": "an Email used by a 'Send via n8n (Email)' step cannot be deleted either. Core has no veto event (EMAIL_PRE_DELETE can only throw), so EventListener/EmailDeleteGuardSubscriber listens to kernel.controller and reads the `_controller` of the core action about to run (executeAction forwards to deleteAction / batchDeleteAction in a sub-request). Screen, one Email: the request is turned into a GET, which core's deleteAction answers with its own redirect and no delete, plus an error flash. Screen batch: the ids in use are dropped from `ids` (one error each), the rest is deleted. REST API (EmailApiController deleteEntityAction / deleteEntitiesAction): 409 with core's error shape; a batch with one Email in use is refused as a whole. In use means a step uses the Email OR any Email below it: deleting an Email also deletes its translations and A/B variants (translation_parent_id / variant_parent_id are ON DELETE CASCADE, at any depth), so EmailTemplateUsageFinder::inUseAmong() walks the whole subtree (a translation can be picked in the node; only variants are filtered out of the picker). Not covered: a delete made without these controllers (SQL, a CLI command). The guard depends on those controller class and method names: a Mautic upgrade that renames them breaks it.",
  "tables": "created by N8nDispatchBundle::onPluginUpdate() when missing (installPluginSchema is CREATE-only on purpose). Changes to an EXISTING table (added column, renamed column) use explicit narrow ALTER TABLE guarded by hasColumn(): a full Doctrine SchemaTool diff compares against the WHOLE database and can emit DROP statements for tables outside the plugin."
}
```

Gotchas for template screens:

```json
{
  "docblock with @": "a docblock mentioning a Twig path like @MauticCore/... breaks the controller: Doctrine's annotation reader treats it as an annotation. Never write @Something in controller docblocks.",
  "core list template": "core's Standard/list.html.twig crashes for an entity without a category once the list has rows ('Method getCategory not exists'): the plugin has its own list.html.twig.",
  "repositories are services": "a repository extending CommonRepository must be registered as a service tagged doctrine.repository_service (Config/services.php, the Entity/*Repository.php glob).",
  "flash messages": "Padrão: [ui-language-texts.md](ui-language-texts.md)",
  "options dropdown (Clone/Delete)": "Padrão: [ui-buttons.md](ui-buttons.md) (built by hand; far right with CSS order:1, gap via ml-xs)",
  "Clone, TypeError": "Padrão: [stack-mautic-traps.md](stack-mautic-traps.md) (editAction() keeps core's signature; SmsTemplateUsageFinder comes from the constructor)",
  "Clone saves nothing": "Padrão: [stack-mautic-traps.md](stack-mautic-traps.md) (`__clone()` with `$this->id = null;`; HsmTemplate has it)"
}
```

## Help text of the text field

```json
{
  "place": "The SMS and HSM forms (Resources/views/<Sms|Hsm>Template/form.html.twig) do not use form_row for `text`: they repeat core's form_row block (row, form-group, has-error) with the help `<p>` between the label and the textarea. Keep the `.row` wrapper: the variable rows are inserted after the closest `.row` of the textarea.",
  "size": "The `<p>` has the class n8ndispatch-text-help (14px, like the Calculation list). Core's `.help-block` is 12px, so the rule is `.form-group p.help-block.n8ndispatch-text-help`.",
  "test": "scripts/5/e2e/tests/sms-hsm-variable-calculation.spec.js"
}
```

### HSM carousel and image types

The types `carousel` and `image` (image URLs in `cards`) are in wiki/n8n-dispatch-templates-hsm-cards.md.
