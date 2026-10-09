# N8n Dispatch Bundle

Mautic 5 plugin that syncs Email templates to Mirror on save, and dispatches
Email, SMS and HSM (WhatsApp template) sends through n8n instead of Mautic's
native senders.

## Requirements

- PHP >= 8.1
- `mautic/core-lib` ^5.0
- The CustomObjectsBundle plugin installed and enabled (used for Custom
  Object variables)
- A running n8n instance with a webhook endpoint that accepts this plugin's
  payload (see [wiki/n8n-dispatch-plugin.md](../wiki/n8n-dispatch-plugin.md)
  in the workspace root for the payload shapes and headers)

## Installing into a Mautic instance

Mautic autoloads plugins from `docroot/plugins/<PluginDirectoryName>` (or
`docroot/app/plugins/...`) — the directory name must exactly match the
`install-directory-name` declared in `composer.json`.

1. Place this repository's contents at:
   ```
   <mautic-install>/docroot/plugins/N8nDispatchBundle
   ```
   In this project's Docker setup that's done via a bind mount in
   `docker-compose.yml`:
   ```yaml
   volumes:
     - ../n8n-dispatch-bundle:/var/www/html/docroot/plugins/N8nDispatchBundle:z
   ```
   applied to `mautic_web`, `mautic_cron`, and `mautic_worker` (all three
   need the code, not just the web container).

2. Clear the cache and install/register the plugin:
   ```bash
   docker exec --user www-data mautic-mautic_web-1 php /var/www/html/bin/console cache:clear
   docker exec --user www-data mautic-mautic_web-1 php /var/www/html/bin/console mautic:plugins:install
   ```
   (Adjust the container name if it differs from `mautic-mautic_web-1`.)

   Mautic's compiled cache is baked into the image and does **not** know
   about a newly mounted plugin — skipping `cache:clear` means the plugin
   silently never shows up, with no error.

   `mautic:plugins:install` also creates or updates the plugin's own tables
   (`n8n_dispatch_sms_templates`, `n8n_dispatch_hsm_templates`). Run it
   again after pulling a version that bumps `version` in
   `Config/config.php`.

3. In the Mautic UI, go to **Settings > Plugins**, find **N8n Dispatch**,
   open it and:
   - Set **webhook_url** — the n8n webhook that receives this plugin's calls.
   - Set **webhook_token** — sent on every call as the
     `X-N8n-Dispatch-Token` header (a plain literal header value, not
     `Authorization: Bearer`).
   - Mark it **Published/Enabled**. If disabled, the plugin no-ops
     everywhere (no sync, no dispatch).

4. Whenever the plugin's containers are recreated (`docker compose down` +
   `up`, not just a restart), the cache reverts to the image's baked-in
   state and step 2's `cache:clear` needs to run again.

## What it does once installed

All calls go to the configured `webhook_url`; n8n tells them apart by the
`X-N8n-Dispatch-Action` header.

| Feature | Where | `X-N8n-Dispatch-Action` |
|---|---|---|
| Sync an Email template to Mirror on every save (UI or API) | automatic | `email.save` |
| **Send via n8n (Email)** — pick a Mautic Email, map its `{{variables}}` | Campaign Action | `email.send` |
| **Send via n8n (SMS)** — pick an SMS Template | Campaign Action | `sms.send` |
| **Send via n8n (HSM)** — pick an HSM Template | Campaign Action | `hsm.send` |
| **SMS Templates (n8n)** — message text + variable mapping, reused by many campaigns | Channels menu | — |
| **HSM Templates (n8n)** — router, WhatsApp-side template reference, send type (Text or Image Carousel: 2 to 10 https image URLs, sent as `cards`), and `{{variable}}` mapping, reused by many campaigns | Channels menu | — |
| **Dispatches** — every dispatch the three actions made, with filters, details and **Resend** for the ones in error | N8n Dispatch menu (needs Campaigns > Full) | — (resend: the original's) |

Each campaign step has a **status**: `test` (records the payload on the
contact's Timeline without calling n8n), `production` (real call), or
`paused` (skipped, contact rescheduled).

Variables can come from a static value, a contact field, or a Custom Object
field. For Custom Object fields, the item(s) used are the ones that match
the campaign's source segment conditions on that object.

Both template screens split their edit page into a **Template** tab and a
**Campaigns** tab listing every campaign using the template, and a template
in use can't be deleted (singly or in a batch).

**Rules checked when a template is saved** (screen and API):

- **Email**: every `{{variable}}` must start with `n8n_` and be mapped on the
  *Variables N8N* tab (both from the second save on; the first save of a new
  Email is never blocked), and *From name* and *From address* are required on
  every save. The edit page shows a yellow alert above the tabs and a pulsing
  dot on the tab to fix, and a warning after saving.
- **HSM and SMS templates**: variables must be numbers only, `{{1}}`, `{{2}}`,
  ... A yellow alert between the text and the variable rows says so while
  typing.

Messages follow the system language (`Translations/en_US`, `pt_BR`).

Contacts on the Do Not Contact list for the channel are not sent to (SMS and
HSM use the `sms` channel).

A campaign step held back by an hour/day restriction shows its real
scheduled time on the contact's Timeline (not a blank card), with buttons to
reschedule or cancel it — cancelling asks for confirmation first, and both
actions record who did it and when, shown right on the card.

**Dispatches screen** (main menu > N8n Dispatch > Disparos, needs Campaigns >
Full): one row per dispatch, with the callback badge (HSM: Meta's), the status
of the campaign step, links to the campaign, template and contact, and a
details modal with the same card as the contact's History. Filter by
campaign, template, dates and callback status. **Resend** (options menu, only
on a dispatch whose callback ended in error or whose call was refused) sends
the same saved body to n8n again, one at a time, and replaces the dispatch's
response, tracking and callback history with the new ones, with a note of who
resent it and when; a refused attempt is recorded too. It needs the step to
be in production, and honours the Do Not Contact list.

Full architecture, payload formats, and implementation history:
[wiki/n8n-dispatch-plugin.md](../wiki/n8n-dispatch-plugin.md) — start with
its "Current state" section.

## Running the test suite

```bash
docker exec mautic-mautic_web-1 sh -c "cd /var/www/html/docroot/plugins/N8nDispatchBundle && /var/www/html/vendor/bin/phpunit"
```

The browser scripts have their own tests (Node's built-in runner, nothing to
install, Node is already in the image):

```bash
docker exec mautic-mautic_web-1 sh -c "cd /var/www/html/docroot/plugins/N8nDispatchBundle && node --test 'Tests/js/*.test.js'"
```

The HSM carousel form also has a live test against the running stack
(`mautic/scripts/test-hsm-carousel-form.sh` in the Mautic stack repo): it logs
in like a browser and removes the rows it creates.

Requires `phpunit/phpunit` as a dev dependency on the image — see
[wiki/docker-mautic5.md](../wiki/docker-mautic5.md) ("PHPUnit / automated
tests") for how that's set up in this project's Docker stack.
