# Scriptorium AI Provider for Codex

WordPress AI Client provider plugin for Codex text models and capability-gated Codex image generation using a local sidecar runtime and ChatGPT-managed authentication and billing.

## Architecture

- `LOCAL-SIDECAR-SPEC.md` is the implementation spec for the localhost sidecar architecture.
- The WordPress plugin stores site runtime settings and per-user connection state locally.
- A localhost sidecar wraps `codex app-server`, handles device-code login, and keeps auth isolated per WordPress user.
- The plugin talks only to the local runtime for health checks, account snapshots, text generation, and capability-gated text-to-image generation.
- Status screens stay read-only and use stored local connection state; explicit connect checks, snapshot refreshes, and live runtime requests reconcile auth against the sidecar.
- Billing plan display is informational only and mirrors the latest account snapshot; if the runtime stops reporting a plan, the stored value is cleared.
- If the WordPress AI plugin's experimental Connector Approval feature is enabled, this plugin performs a one-time approval for its own `codex` connector during activation or the next normal boot. It does not pre-approve while the experiment is disabled, and if the approval is later revoked, runtime calls stay blocked until an administrator re-approves it under `Tools > Connector Approvals` or disables the experiment.

## Included

- `scriptorium-ai-provider-for-codex.php` bootstrap with WordPress and AI Client checks
- provider registration for `codex`
- Connectors integration for runtime status and user connection actions
- site settings for runtime base URL, bearer token, and fallback models
- local tables for per-user connections and connection snapshots
- snapshot-backed text model catalogs and a synthetic `codex-image` model when Codex reports image support
- per-user device-code connect, refresh, and disconnect flows
- REST endpoints for connect, status, refresh, disconnect, and readiness checks
- local sidecar app under `sidecar/`
- repeatable verification scripts in `scripts/verify.php` and `scripts/verify.sh`

## Current Status

- Phase 1 functional cutover to the localhost runtime is complete.
- Phase 2 cleanup removed the remaining legacy compatibility aliases from the active plugin code and docs.
- Pending device-code sessions now recover cleanly after a sidecar restart when `auth.json` already exists for that user.
- Text-to-image generation is available through `codex-image` only for connected users whose current Codex app-server snapshot reports `imageGeneration: true`.

## Quick Start On A WordPress Host

1. Install and activate the plugin in WordPress.
2. On the same host as WordPress, install Python 3.11+ and the `codex` CLI.
3. Create a localhost systemd service from `sidecar/systemd/codex-wp-sidecar.service` and write `/etc/codex-wp-sidecar.env` with the runtime settings.
4. Open `Settings > Codex Provider` and confirm the runtime URL and bearer token were auto-detected from `/etc/codex-wp-sidecar.env`, or enter them manually.
5. Open `Settings > Connectors` and confirm Codex reports a healthy local runtime.
6. Each user then opens `Users > Codex Provider`, clicks `Connect Codex account`, and completes the device-code login.

## Verification

- `WP_PATH=/path/to/site ./scripts/verify.sh`
- `wp --path=/path/to/site eval-file wp-content/plugins/scriptorium-ai-provider-for-codex/scripts/verify.php`
- `python3 sidecar/scripts/test-image-generation.py`

## Automated Sidecar Setup

- `sidecar/systemd/codex-wp-sidecar.service` provides a systemd template for running the sidecar.
- The plugin can auto-detect the runtime URL and bearer token from `/etc/codex-wp-sidecar.env` when PHP can read that file.
- The runtime status probe now checks `GET /healthz`, so Connectors can report `Runtime unreachable` before a user hits the connect action.
