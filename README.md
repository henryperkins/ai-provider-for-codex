# AI Provider for Codex

WordPress AI Client provider plugin for Codex models using a local sidecar runtime and ChatGPT-managed authentication and billing.

## Architecture

- `LOCAL-SIDECAR-SPEC.md` is the implementation spec for the localhost sidecar architecture.
- The WordPress plugin stores site runtime settings and per-user connection state locally.
- A localhost sidecar wraps `codex app-server`, handles device-code login, and keeps auth isolated per WordPress user.
- The plugin talks only to the local runtime for health checks, account snapshots, and text generation.

## Included

- `plugin.php` bootstrap with WordPress and AI Client checks
- provider registration for `codex`
- Connectors integration for runtime status and user connection actions
- site settings for runtime base URL, bearer token, and fallback models
- local tables for per-user connections and connection snapshots
- per-user device-code connect, refresh, and disconnect flows
- REST endpoints for connect, status, refresh, disconnect, and readiness checks
- local sidecar app under `sidecar/`
- repeatable verification scripts in `scripts/verify.php` and `scripts/verify.sh`

## Current Status

- Phase 1 functional cutover to the localhost runtime is complete.
- Phase 2 cleanup removed the remaining legacy compatibility aliases from the active plugin code and docs.

## Verification

- `WP_PATH=/path/to/site ./scripts/verify.sh`
- `wp --path=/path/to/site eval-file wp-content/plugins/ai-provider-for-codex/scripts/verify.php`

## Automated Sidecar Setup

- `sudo ./sidecar/scripts/install-systemd.sh` installs the sidecar as a systemd service.
- The installer writes `/etc/codex-wp-sidecar.env`, and the plugin now auto-detects the runtime URL and bearer token from that file.
- The runtime status probe now checks `GET /healthz`, so Connectors can report `Runtime unreachable` before a user hits the connect action.
