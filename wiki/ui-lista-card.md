categoria: ui
regras:
- Template lists (SMS, HSM) put the Status in a column of its own right after the menu column, header printed by the twig, not sortable; the description under the name is hidden because the badge no longer sits in that cell.
- The Dispatches list is ALWAYS newest first by dispatch date (l.date_triggered DESC, then l.id DESC), also with the status filter; the user cannot sort (headers have no orderBy on purpose); paged with core's pagination selector (default 30). A plain GET form for filters, so the query string survives pagination.
- Detail tables: ONE table titled Detalhes (Timeline/_facts_table.html.twig: a title row over both columns, bold label in a 20% column, value beside it); never a bullet list.
- Every card (n8n, Email through Mautic, DialogHSM) is: status badges, then the last error as `.alert.alert-danger` with the error text only (Timeline/_last_error.html.twig), the resend note, then the Detalhes table. The same partial `_dispatch_details.html.twig` serves the details modal and the contact Timeline, so both always match.
- The History table of the card is core's `.table .table-hover` inside `.table-responsive`; the plugin css only sets fixed column widths. Do not restyle padding, borders or font there.
- Log tables (alert log, Mirror log): the list scrolls sideways (`overflow: auto`); every column keeps its text on one line, only Detalhe wraps (min 260px), so a long campaign name is not squeezed. Latest 50 rows. Dates through dateToFullConcat(date, 'UTC') (log tables), or n8ndispatch_date on the card.
- A campaign name is shown as 'PREFIX - Name' on the Dispatches screen (table, filter, details modal).
- A custom card replacing a core template carries core's blocks itself; scope color fixes to the card (`.n8ndispatch-timeline-scheduled .text-warning`), never a global change.
- Dark themes: card blocks (Body/Response, cancelled line, reschedule date field) follow the theme variables (--text-primary, --field-01, --border-subtle-01); core's inline `color: #000000` needs `!important` in dark and solarized-dark.
- Specs of lists and cards check both themes (light and dark).
- Core's `|purify` must be on messages that embed real HTML (a `<span data-date>`), or Twig prints the tag as text.
- Twig 3 has no `for ... if`: use |filter.

Buttons: [ui-botoes.md](ui-botoes.md). Modals: [ui-modal.md](ui-modal.md). Badges: [ui-badge-n8n.md](ui-badge-n8n.md).
