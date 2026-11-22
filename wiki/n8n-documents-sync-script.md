# n8n script: sync "documents" Custom Object items from an external mirror

Lives at [mautic-5/n8n/documents-sync-classifier.js](../../mautic-5/n8n/documents-sync-classifier.js),
with a standalone test harness next to it,
[mautic-5/n8n/documents-sync-classifier.test.js](../../mautic-5/n8n/documents-sync-classifier.test.js).
This is code for an n8n **Code node**, not a Mautic plugin — it doesn't run inside the
`mautic/` Docker stack, it runs inside an n8n workflow that talks to a live Mautic instance
over the REST API described in the REST API guide in `mc-cs-plugin-custom-objects-5/README.md`. This n8n
workflow feeds Mautic **production** data, so treat changes to it with the same care as prod
code.

## What it does

Compares two things and decides what to do with each document:

- an external "mirror" source's document list for one student (`academic_record` +
  `document[]`, one entry per document with `code`, `type`, `status`, `is_required`, etc.)
- the current state of the `documents` Custom Object in Mautic, read via
  `GET /api/customobjects/documents/items`

For every document in the mirror:

```json
{
  "already exists as a Custom Item": "-> patch (direct batch PATCH on the Custom Object, no contact needed — it's already linked)",
  "doesn't exist yet": "-> post (PATCH /api/contacts/{id}/edit with customObjects.data, so the new item gets created AND linked in one call)",
  "existed before but is gone from the mirror now": "-> delete (batch DELETE, see below)"
}
```

It runs once per n8n execution (**"Run Once for All Items"** mode, not per-item), because it
needs the full picture — the whole mirror document list and the whole existing-items list —
to tell "new" from "gone" apart.

## Output shape

Always an array of up to 3 items, one per action type, each already shaped as the batch body
that action's HTTP call expects:

```json
[
  {"switch": "patch",  "data": [{"id": 13450, "attributes": {"doccode": "1827403", "...": "..."}}]},
  {"switch": "delete", "data": [{"id": 13451}]},
  {"switch": "post",   "data": [{"id": 3131, "customObjects": {"data": [{"alias": "documents", "data": [{"name": "...", "attributes": {"...": "..."}}]}]}}]}
]
```

A branch is only included if it has at least one entry — an empty `patch`/`delete`/`post`
never gets emitted. Downstream, a Switch node routes on the `switch` field to three separate
HTTP Request nodes:

- `switch: patch` → `PATCH /api/customobjects/documents/items` with `{"data": <that item's data>}`
- `switch: delete` → `DELETE /api/customobjects/documents/items` with `{"data": <that item's data>}` (batch delete, see below)
- `switch: post` → `PATCH /api/contacts/{id}/edit` with `<that item's data as the body>`

**Never returns an empty array.** If there is genuinely nothing to do (no patch, no delete, no
post), it returns a single sentinel item instead: `{"switch": "noop", "data": []}`. This
matters because the node this script runs in sits inside an n8n loop (`LoopDocuments`) — a
loop node needs at least one output item per iteration to keep going, so an empty return would
stall the loop instead of just skipping to the next student. Put an `if switch === "noop"` in
front of the real Switch node to loop back to the start without doing anything.

## Matching key: `academic_record + code`

A document is considered "the same" as an existing Custom Item when both
`docacademicrecord`/`academic_record` and `doccode`/`code` match. This also builds the
Custom Item's `name`: `"${academic_record} - ${code} | ${type}"`.

The mirror's `document[]` array can contain fully duplicate entries (same `code` appearing
twice with identical fields) — this is a known artifact of a bug in the query on the mirror
side, not a real second document. The script deduplicates by this same
`academic_record + code` key before comparing, keeping one entry per key. **This is
deliberate and must stay** — it is a safety net against that upstream query bug recurring.

## Batch delete

The `delete` branch calls `DELETE /api/customobjects/{objectAlias}/items` with `{"data": [{"id": ...}]}` (`CustomItemDeleteApiController::batchDeleteAction` in `mc-cs-plugin-custom-objects`; see "DELETE" in the REST API guide in `mc-cs-plugin-custom-objects-5/README.md`). Like the batch POST/PATCH/PUT it is one DB transaction: an id from another Custom Object rolls back the whole delete and answers 404. An unknown id is not an error: the answer lists it in `alreadyGone` (`{"total", "deleted", "alreadyGone"}`), and an id repeated in the call is deleted once.

## Testing without n8n running

`documents-sync-classifier.test.js` mocks n8n's `$('NodeName').all()` helper with two
variables defined right in the file (`MOCK_MIRROR_INPUT`, `MOCK_CUSTOM_OBJECTS_GET`), then
loads and runs the real `documents-sync-classifier.js` against them — not a copy, the actual
file. Run with:

```bash
node mautic-5/n8n/documents-sync-classifier.test.js
```

Edit the two mock variables to try other scenarios (a document that no longer exists in the
mirror, an `academic_record` with no existing items yet, etc.) instead of needing a running
n8n instance or live Mautic data to check the logic.

## n8n node names this script depends on

Set at the top of the file — update these constants if the workflow's nodes get renamed:

- `CUSTOM_OBJECTS_NODE = 'MauticGET Documents'` — the node that ran the `GET` against the
  Custom Object.
- `MIRROR_INPUT_NODE = 'LoopDocuments'` — the loop node carrying the mirror/mautic input data.

`$('NodeName').all()` in n8n only works if that node actually ran earlier in the same
execution path — a typo in either constant, or referencing a node that isn't an ancestor of
this Code node, silently breaks the comparison rather than erroring clearly.
