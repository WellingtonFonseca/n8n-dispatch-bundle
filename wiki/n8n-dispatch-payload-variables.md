# n8n dispatch: payload, variables and template rules

What the plugin sends to n8n, how the `{{variables}}` of a template are resolved (contact fields and Custom Objects by segment) and the rules checked when a template is saved.

## Payload sent to n8n

```json
{
  "all channels": ["contact_id", "contact_email", "contact_phone", "contact_name", "contact_inst_id_lyceum", "contact_inst_id_company", "contact_inst_alias", "status", "language", "variables"],
  "email only": "mautic_template_id",
  "sms only": "message (the text with every {{variable}} already replaced)",
  "hsm only": ["hsm_router", "hsm_template", "hsm_type", "enro_academic_record (the contact field of that name, sent as is, empty when the contact has none)", "cards (carousel and image types only)"],
  "contact_inst_*": "from the contact fields inst_id_lyceum, inst_id_company, inst_alias. If a field does not exist the value goes out empty; n8n answered 400 'Missing header - company-id or institution-alias' for a contact without inst_id_company / inst_alias.",
  "language": "the template's language (Email editor language, or the SMS/HSM Template's own): the one the dates and decimals in 'variables' were formatted for. It tells a wrong-looking value (template saved in the wrong language) from a bug."
}
```

## Variables

```json
{
  "sources": {
    "static": "a fixed value typed in",
    "field": "a contact field. A Lead from LeadModel::getEntity() does NOT have its custom field values hydrated: Lead::getFieldValue() returns null until LeadRepository::getFieldValues() + Lead::setFields() ran. VariableResolver does it before resolving a 'field' source.",
    "custom_object": "a Custom Object field; the item(s) come from the campaign's source segment (below)"
  },
  "several items match": "Email joins with '<br>' (Mirror inserts the value raw into the HTML); SMS and HSM join with ', ' (plain text)",
  "non-array mapping entry": "an old plain-string entry resolves to empty, it does not crash",
  "language": "Resolver/LocaleConventions.php, one table: pt_BR = d/m/Y + comma decimals; en and en_US = m/d/Y + dot; en_GB = d/m/Y + dot. Lookup: exact code, then the language part ('en_US' -> 'en'), then pt_BR. Any language not listed falls back to pt_BR. The Email's language is Email::getLanguage() (emails.lang; Mautic's default for an Email is 'en', so check old Emails). SMS/HSM Templates have a 'language' column (VARCHAR 191, default 'pt_BR'; the migration adds it with that default). An inline event with no template uses pt_BR.",
  "dates": "Resolver/DateFormatter: date and datetime values (Mautic's Y-m-d, Y-m-d H:i:s; a 'date' column read from a DATETIME also arrives as 'Y-m-d 00:00:00') become the language's format; the time is dropped for a date.",
  "decimals": "Resolver/DecimalFormatter, only Custom Object fields of type decimal. Storage returns DECIMAL(20,6) as '1.100000'; sent with at least two places and up to the last non-zero place, with the language's separator: 1.100000 -> 1,10 / 1.10; 3.750000 -> 3,75; 2.000000 -> 2,00; 1.000100 -> 1,0001; -0.500000 -> -0,50. It works on the stored text, never through float. A value that is not a plain number (empty, text, 1e-06) is sent unchanged. Contact 'number' fields are not touched.",
  "calculation": "the Email's Variables tab has a Calculation box under a `field` or `custom_object` source, saved in the entry as `dateOffsetDays` (the key keeps its old name). A whole number is saved as a number, a decimal as TEXT ('13.2'), so no float rounds it; 0 or empty leaves the key out. VariableResolver::addDays() reads it as text ('0' when it is not a number; a comma is read as a point). A date/datetime moves by the whole part (DateFormatter, cut to ±3650 days, MAX_DAYS); Resolver/NumberCalculator adds it to a contact 'number' and a Custom Object 'int' / 'decimal': whole-digit arithmetic on the stored text (no float, no bcmath, no limit), keeps the places of the value, and only a 'decimal' uses the places of the amount, the other types its whole part. Another type is not changed. The SMS and HSM template forms have the same box: getEmailVariables, getSmsVariables and getHsmVariables all answer dateOffset: true, and the shared JS shows the box only then. The help list is once on top, inside a `div.alert.alert-info.n8ndispatch-var-offset-help`: the Email tab includes the twig component Template/_calculation_help.html.twig (messages.ini); on SMS and HSM the shared JS builds the same markup in calculationHelpHtml() (javascript.ini, same texts) only when the page has no `.n8ndispatch-var-offset-help` outside the variables container. Change both together.",
  "plumbing": "the three subscribers -> VariableResolver::resolveAll(..., $language) -> resolveContactField / CustomObjectVariableResolver::resolve(..., $language, $calculation) -> DateFormatter / NumberCalculator / DecimalFormatter (in that order, each ignores the other's types). $language defaults to pt_BR at every step."
}
```

### Custom Object variables follow every segment condition

`Resolver/CustomObjectVariableResolver.php` turns the items the campaign's source segment(s) select into the text of the variable; WHICH items they select is the Custom Objects plugin's `Segment/SegmentItemResolver` (+ `SegmentItemMatcher`, see ../mc-cs-plugin-custom-objects-5/wiki/custom-objects-plugin.md), which re-applies the Custom Object conditions of the source segment(s) to ONE contact:

```json
{
  "filter list": "Mautic's own ContactSegmentFilterFactory::getSegmentFilters(): the exact list the segment build uses",
  "grouping": "as the segment: an 'or' glue starts a new group, conditions in a group are ANDed",
  "AND": "all conditions on the object in a group must match the SAME item",
  "OR / several segments": "items from each group and each segment are added together",
  "which segments": "only source segments the contact is in now (lead_lists_leads, not manually removed)",
  "each condition": "built with the Custom Objects plugin's own QueryFilterHelper (the calls its segment builders make), selecting item ids instead of contact ids, so operators, values and relative dates match the segment. Covers fields (cmf_<id>) and item names (cmo_<id>).",
  "negative operators": "those the segment applies as NOT EXISTS (neq, empty, notLike, !multiselect, !between, notBetween; for item names neq, empty, notLike) keep every item of the contact except those matching the positive condition",
  "merged filters": "with custom_object_merge_filter on (plugin default), conditions arrive as one merged filter per AND group: the plugin's SegmentItemMatcher::findMergedItemIds() resolves it through the plugin's createMergedItemIdsQuery() (../mc-cs-plugin-custom-objects-5/wiki/custom-objects-merged-segment-filters.md). The two plugins must be deployed together.",
  "no condition on the object": "the variable is empty and a warning is logged",
  "operators that never reach it": "between and in cannot be built in the segment screen (../mc-cs-plugin-custom-objects-5/wiki/custom-objects-merged-segment-testing.md); a hand-set between on an int field works. A between on a DATE field crashes core's own segment rebuild (DateOptionFactory expects a scalar)."
}
```

Limits: contact-field conditions inside OR groups do not pick the group (`(email = A AND posicao = 10) OR (email = B AND posicao = 3)` counts items from both groups); only items linked directly to the contact count (no item-to-item relations); with the merge OFF a contact with item A (`posicao = 10`) and item B (`nome = X`) IS in the segment of `posicao = 10 AND nome = X`, and the variable is empty because no single item matches both.

## Rules checked when a template is saved

```json
{
  "Email variables": {
    "rule": "every {{variable}} in customHtml or in the subject must start with n8n_. The reserved n8ndispatch_unsubscribe_url is ignored. TemplateVariableScanner::extractFromEmail / extractWithoutPrefixFromEmail read the subject and the HTML each on its own (wiki/n8n-dispatch-email-direct.md, 'Subject').",
    "when": "from the SECOND save on. The first save of a new Email is never blocked (templates often come from tools without the prefix and are remapped anyway).",
    "where": "Form/Extension/EmailVariablePrefixExtension.php (POST_SUBMIT on core's EmailType, so UI and /api/emails share it); Service/TemplateVariableScanner.php (REQUIRED_PREFIX, extractWithoutPrefix()). One generic message naming the Variables N8N tab; the error goes on the field that has the problem (customHtml or subject)."
  },
  "Email mapping": {
    "rule": "every variable must be mapped on the Variables N8N tab. A row counts as mapped only when filled (static: not blank; contact field: one chosen; custom object: object and field chosen). Every variable gets a default row (static, blank) that is saved as is, so a key in the saved mapping means nothing.",
    "when": "blocks from the second save on. After ANY save from the screen a yellow flash warns about unmapped variables (what a first save or Save & close sees). If the prefix check already flagged customHtml, the mapping check steps aside: rename first, map second.",
    "where": "Form/Extension/EmailVariableMappingExtension.php (priority -10), EventListener/EmailUnmappedVariablesSubscriber.php (the flash, only when the request has emailform[...]), Service/VariableMappingChecker.php. The same rule is in JS (isEntrySet in Assets/js/n8ndispatch-shared.js): keep both equal.",
    "why it works": "the Variables tab saves its mapping by its own AJAX call right before the form is submitted (Assets/js/email-tab-variables.js), so the saved mapping is current when the server checks it.",
    "api": "there is no API for the mapping, so a PATCH to an Email with unmapped variables is refused until they are mapped on screen."
  },
  "Email sender": "fromName and fromAddress are required on EVERY save, including the first, UI and API (Form/Extension/EmailSenderRequiredExtension.php; one error per field, with core's own labels mautic.email.from_name / from_email and tab name mautic.core.advanced). Without a sender Mirror rejects the Email while Mautic saved it anyway, a failure that only reached the log.",
  "HSM and SMS templates": "the text may only have positional placeholders {{1}}, {{2}}, ...; anything else between double braces ({{name}}, {{n8n_x}}, {{1a}}, {{}}) is refused with one generic message, on every save. Entity/Validation/NumericVariables.php, used by both entities' loadValidatorMetadata as a callable ARRAY, not a closure (the validator caches the metadata and closures cannot be serialized); message key in Translations/*/validators.ini. Numeric names work end to end: the SMS lookup finds integer keys and the variables go to n8n as a JSON object ({\"1\":...}); only {{0}} plus {{1}} (keys 0..n-1) would turn into a JSON list.",
  "HSM carousel and image": "see wiki/n8n-dispatch-templates-hsm-cards.md"
}
```

Mapping is keyed by the variable NAME: renaming `{{nome}}` to `{{n8n_nome}}` leaves the old key, and the dispatch sends the new name without a value until it is mapped. A scheduled campaign step reads the Email and its mapping at execution time (nothing is frozen at scheduling), so the safe order is: set the step to `paused`, rename, map, back to `production`. n8n creates a new Mirror template on every save and sends with the latest one.

### Screen helpers

```json
{
  "Email edit page": [
    "A yellow alert above the tabs and a pulsing dot on the Variables N8N tab: wrong prefix (wins) or unmapped variables. getEmailVariables returns invalidVariables from the same scanner.",
    "A yellow alert above the tabs and a pulsing dot on the Advanced tab while From name / From address are blank (works for new and old Emails). The texts reach the page through a hidden element in core's 'email.settings.advanced' extension point (EventListener/EmailSenderAlertSubscriber.php), which exists for new Emails too, unlike the Variables tab (it needs the Email id).",
    "Core's Email form has NO extension point above the tabs, so the alerts are inserted by JS before ul.nav-tabs-contained. If core changes that markup the alerts simply do not show."
  ],
  "HSM / SMS template edit page": "a yellow alert between the text field and the variable rows, decided in the browser from the text itself (hasNonNumericPlaceholder in n8ndispatch-shared.js, same rule as the PHP), not from the AJAX answer.",
  "pulsing dot": "Assets/css/n8ndispatch.css (.n8ndispatch-tab-dot), pinned to the tab's top-right corner; under prefers-reduced-motion it fades instead of pulsing",
  "alert links": "the tab name is bold and opens that tab (class n8ndispatch-open-tab)",
  "Variables tab refresh": "Assets/js/email-tab-variables.js lists the {{variables}} of the page's customHtml. Closing the GrapesJS builder only writes the HTML into textarea.builder-html and triggers 'builder:hide' on '.builder'; the database changes only on Save. The plugin listens for builder:hide on document (bound once, Mautic.n8ndispatchBuilderHideWired) and sends the textarea's HTML as an optional 'html' param to plugin:N8nDispatch:getEmailVariables (without it the action reads the saved customHtml). The {{name}} scan stays in TemplateVariableScanner, so the tab and the campaign form cannot drift. Variables still present keep their source, new ones start empty, removed ones disappear; zero left shows the 'none found' message and resets the hidden field to {}. The sources are persisted only on Save/Apply of the Email form (saveEmailVariables)."
}
```
