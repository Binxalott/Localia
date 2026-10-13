# WordPress plugin plan

Decisions agreed so far for turning Localia into a WordPress plugin. Not built yet.

## Packaging

- **Free plugin**: this public repo (`binxalott/localia`), published on wordpress.org. License GPLv2 or later. Nothing in it is locked or limited behind payment (wordpress.org rule).
- **Pro add-on**: separate plugin in the private repo `binxalott/localia-pro`, sold from our own website (e.g. via Freemius or Lemon Squeezy). It is installed alongside the free plugin and extends it through hooks. The Pro license key field is part of the Pro add-on, never the free plugin. Pro is GPL too.
- Never commit Pro code to the public repo.

## Free plugin features

- **Connect your PC**: a setup wizard on the Settings page.
  - The plugin generates the agent ID and secret.
  - **The home-PC agent is downloadable from the Settings page, already configured** with the site's endpoint, agent ID and secret, so setup is "download, unzip, run the start script".
  - Planned: a zip of `client/` plus the start scripts and a generated config file. Fallback if the host lacks `ZipArchive`: a single self-contained HTML file plus a pairing code to paste.
  - The download contains the secret, so: admins only (`manage_options`), nonce-protected, sent with no-cache headers, and with a "regenerate secret" button that invalidates old downloads.
  - The agent keeps running from `http://localhost:8787` on the PC (avoids browser local-network permission prompts and Ollama CORS issues).
- **Daily scheduler (24-hour)**: any number of post tasks, each run at one or more times of day, every day. Optionally pick days of the week. No calendar or future dates (that's Pro).
- **Text post tasks only**:
  - Create a new post (prompt/topic, category, tags, author).
  - Rewrite or expand an existing post, saved as a revision.
  - New posts are saved as drafts by default, with an optional auto-publish.
- **"Generate with my AI" button** in the post editor.
- **Tabs**:
  - **Dashboard**: PC online status, queue, recent changes, log.
  - **Tasks**: reusable recipes.
  - **Schedule**: the daily times.
  - **Settings**: connection and options.
  - **Free vs Pro**: comparison table and "Get Pro" buttons.
- **Upsell rules**:
  - Upsells only on the plugin's own pages; any admin notices are dismissible.
  - No remote assets or tracking.
  - Buy links come from one constant (placeholder URL until the website exists).
- **Implementation notes**:
  - Use `$wpdb` with `dbDelta` for the queue tables.
  - Use REST routes (`register_rest_route`) for the agent protocol (same as `docs/PROTOCOL.md`).
  - Use the Options API for settings.
  - Prefix everything `localia_`.
  - Add an `uninstall.php` that removes tables and options.
  - Check with the Plugin Check plugin before submitting.
- **WP-Cron caveat**: it only fires on page visits. Show a status note and document a real cron setup. Agent polls also trigger it.

## Pro add-on features

- **Calendar scheduling**: specific dates, planning ahead, recurring rules ("every 2nd Tuesday").
- **Comment AI**:
  - Spam check returns yes / no / unknown, and the user picks an action for each. "Unknown" defaults to leaving the comment for a human; never hard-delete.
  - Reply drafts that need approval before publishing.
  - Send only the comment text (no name, email or IP).
  - Force the output to yes / no / unknown, treating anything else as unknown (prompt-injection safety).
- **Later (v2)**: image and audio generation (ComfyUI / Stable Diffusion WebUI / TTS connectors on the agent; results go to the Media Library; needs larger or chunked uploads).

## Naming

- **Task**: a reusable recipe the user creates.
- **Run**: one execution of a task.
- **Job**: an item in the agent queue (protocol term).
