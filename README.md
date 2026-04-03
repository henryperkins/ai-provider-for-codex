# AI Provider for Codex

Scaffold for a standalone WordPress AI provider plugin backed by a hosted Codex broker.

## Included in the scaffold

- `plugin.php` bootstrap with WordPress and AI Client checks
- provider registration for `codex`
- custom Connectors card for site status, account status, and primary actions
- site settings for broker URL, site identity, and default models
- local tables for per-user connections, short-lived auth states, and broker snapshots
- broker client and HMAC request signing
- broker-refreshed model catalog with a site-wide aggregate fallback for the AI Client
- per-user admin page for connect, refresh, and disconnect flows
- REST endpoints for connection start/disconnect/refresh and readiness status
- repeatable verification scripts in `scripts/verify.php` and `scripts/verify.sh`

## Not implemented yet

- a dedicated site-level broker `/models` endpoint (the plugin currently refreshes a site catalog by aggregating per-user broker `/models` responses)
- deterministic AI Client support filtering wired into `wp_ai_client_prevent_prompt`
- the deeper transport/auth refactor toward the AI Client request pipeline
- directory-ready assets, screenshots, or packaging automation

## Verification

- `WP_PATH=/path/to/site ./scripts/verify.sh`
- `wp --path=/path/to/site eval-file wp-content/plugins/ai-provider-for-codex/scripts/verify.php`
