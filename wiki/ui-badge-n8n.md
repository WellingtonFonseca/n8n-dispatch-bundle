categoria: ui
regras:
- Every status pill of the plugin (Timeline card, Dispatches screen, template lists and details modal, canvas strip) is the one class `.n8ndispatch-badge` in Assets/css/n8ndispatch.css: 13px, weight 600, uppercase, padding 2px 10px, no icon, centered, nowrap, with the modifiers production / failed / pending / test / paused and the translucent info / neutral (unused here; they exist so the family equals MauticPatchesBundle's `.patches-badge`, which the Mautic lists use). One change of that class changes them all, and the same change goes to the twin (spec badge-twin compares them). Core's own .label is 10px and the page text 14px; 11px looked too small in the modals.
- No badge of the plugin has an icon: the word and the color tell the status. Exceptions: the hover circle of the campaign canvas and the icon badge of the Status column (template lists, alert log, Mirror log).
- Canvas badge (`.n8ndispatch-status-badge`, builder and Preview): the circle is the icon only when closed; on hover the word opens to its right (13px uppercase, like the pills) and the ICON STAYS where the pointer is. Hiding the icon on hover put the pointer over the first letters of the word. Spec n8n-status-badge-hover (seeded campaign 'Campanha n8n', found by name).
- Status column (icon badge that opens on hover): a 22px circle, the word in a `__text` span that is also the title and aria-label; it opens on hover to `max-width:140px` and the column widens.
- Status column cells are `white-space:nowrap` and no script fixes column widths. TRAP: with wrapping cells the open badge shrank the other columns, the rows re-wrapped and the badge slid from under the pointer (it flickered). Same rule as MauticPatchesBundle.
- An inactive template badge is neutral gray, not red: it is not an error (Active = green, `ri-check-line`).
- The Status cell of a log table (`.n8ndispatch-alert-status`, grouped with `.n8ndispatch-template-status` in n8ndispatch.css) is the same icon badge: `ri-check-line` on green (sent / ok) or `ri-close-line` on red (not sent / error), gray for running.
- The badge/status-column code is a twin of MauticPatchesBundle's and is duplicated on purpose; the same rules live in the root wiki.

Shared rules with the patches plugin: [../../wiki/ui-badge.md](../../wiki/ui-badge.md) and [../../wiki/ui-status-column.md](../../wiki/ui-status-column.md).
