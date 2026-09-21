# N8n Dispatch Bundle

Mautic 5 plugin that syncs Email templates to Mirror on save, and dispatches
Email sends (SMS/HSM planned) through n8n instead of Mautic's native senders.

## Requirements

- PHP >= 8.1
- `mautic/core-lib` ^5.0
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

- **On every Email template save** (UI or API): syncs the template's HTML
  to Mirror via the configured webhook.
- **"Send via n8n (Email)" Campaign Action**: a journey-builder step that
  resolves per-contact variables (static value, contact field, or Custom
  Object field) and dispatches the send through n8n, recording the outcome
  back on Mautic's own contact Timeline and DNC/unsubscribe state.

Full architecture, payload formats, and implementation history:
[wiki/n8n-dispatch-plugin.md](../wiki/n8n-dispatch-plugin.md).

## Running the test suite

```bash
docker exec mautic-mautic_web-1 sh -c "cd /var/www/html/docroot/plugins/N8nDispatchBundle && /var/www/html/vendor/bin/phpunit"
```

Requires `phpunit/phpunit` as a dev dependency on the image — see
[wiki/docker-mautic5.md](../wiki/docker-mautic5.md) ("PHPUnit / automated
tests") for how that's set up in this project's Docker stack.
