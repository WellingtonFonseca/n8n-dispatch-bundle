categoria: api
regras:
- `metadata['n8ndispatch']` is reserved for a real dispatch payload; other records (cancellation, reschedule) use their own keys (`n8ndispatch_cancellation`, `n8ndispatch_reschedule`, `n8ndispatch_resend`), NEVER nested under it (a card would read n8n.status on a row that never dispatched and crash).
- The full response is recorded on EVERY outcome, before the pass/fail branch (payload, response, httpStatusCode); a transport error is stored with httpStatusCode 0. The outcome badge reads httpStatusCode (the real transport status), not the status field nested in the response body. HTTP < 300 is success.
- The step status values are English (test / production / paused) and sent as is in the payload; `paused` and `test` record the payload and PASS the step, so the contact moves on.
- The payload always carries `language` (the template's language) next to the formatted `variables`; any language not listed falls back to pt_BR.
- A step node is always named after its template.
- The `cards` payload shape is read by the n8n Code nodes: do not change it.
- A failed event is stored in the log's `metadata` (`failed`, `reason`).
