# n8n dispatch: the history of the SMS and HSM templates

Every save, publish toggle and delete of an SMS or HSM template is written to core's `audit_log`, and a "Histórico" tab shows it. Core's Email already writes that table; nothing of core shows its detail on a page. What must not break: the models dispatch no events of their own, so the audit goes through `dispatchEvent`.

```json
{
  "write": "Model/AuditsChanges (trait of HsmTemplateModel and SmsTemplateModel) overrides dispatchEvent: 'post_save' (saveEntity AND togglePublishStatus both call it) and 'post_delete' go to Service/Audit/TemplateAuditLog. bundle `N8nDispatchBundle`, object `hsm_template` or `sms_template`, action create | update | delete, details {field: [old, new]} from the entity's getChanges() (a delete keeps {name}). The audit service comes by setter (#[Required] setAudit): the core model constructor stays untouched.",
  "failure": "The audit line is written after the template is saved: TemplateAuditLog catches a failed write and logs it (channel N8N Dispatch, with object, id, action and the error); the save never turns into an error page.",
  "noise": "dateAdded, dateModified, createdBy*, modifiedBy*, checkedOut* are bookkeeping and are dropped. A save with nothing else changed writes no line.",
  "read": "Twig function n8ndispatch_template_audit(object, id) (Twig/AuditHistoryExtension): AuditLogModel::getLogForObject, newest first, 200 lines. Service/Audit/AuditTrail makes the rows; Service/Audit/AuditFieldFormatter says field and value in the reader's language (the labels of the edit page, provider, type, Ativo / Inativo, the name of the DialogHSM number). A field, provider or type it does not know is shown as stored (never a raw translation key); a flag false is `0` (the formatter says Inativo).",
  "tab": "Template/_audit_pane.html.twig, the LAST tab of the edit page and of the details modal of both kinds (only for a saved template). Table: Data | Usuário | Ação | Campo | Antes | Depois, one row per field, date / user / action span their rows (rowspan), values in `pre.n8ndispatch-audit-value` (wrap, keep line breaks, dark themes), Antes and Depois 32% each.",
  "copy": "AuditTrail and the pane are copies of the ones of MauticPatchesBundle (../mautic-patches-bundle-5/wiki/patches-email-page.md): the plugins do not depend on each other."
}
```

## Traps

- The log is ordered by `dateAdded` to the SECOND: a create and an edit in the same second tie and can show in either order.
- A delete line is written but the page of the template is gone: nobody sees it today.
- `variablesJson` and `cards` show as JSON, cut at 300 characters.
- The date uses core's `dateToFull`, so it is in English; the user name is what core stored.
- No history before the audit existed.

## Tests

PHPUnit: TemplateAuditLogTest, AuditFieldFormatterTest, AuditTrailTest, AuditHistoryExtensionTest, Tests/Unit/Model/AuditsChangesTest. Browser: `scripts/5/e2e/tests/template-history.spec.js` (makes and changes the template through the form; edit page, modal, status toggle).
