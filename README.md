# AI Provider for Codex

WordPress AI Client provider plugin for Codex text models and capability-gated Codex image generation using a local sidecar runtime and ChatGPT-managed authentication and billing.

## Architecture

- `LOCAL-SIDECAR-SPEC.md` is the implementation spec for the localhost sidecar architecture.
- The WordPress plugin stores site runtime settings and per-user connection state locally.
- A localhost sidecar wraps `codex app-server`, handles device-code login, and keeps auth isolated per WordPress user.
- The plugin talks only to the local runtime for health checks, account snapshots, text generation, and capability-gated text-to-image generation.
- Status screens stay read-only and use stored local connection state; explicit connect checks, snapshot refreshes, and live runtime requests reconcile auth against the sidecar.
- Billing plan display is informational only and mirrors the latest account snapshot; if the runtime stops reporting a plan, the stored value is cleared.
- If the WordPress AI plugin's experimental Connector Approval feature is enabled, this plugin performs a one-time approval for its own `codex` connector during activation or the next normal boot. AI 1.4 attributes generation requests to the consuming extension: direct AI abilities require approval for `ai/ai.php`, while MCP-mediated abilities require approval for `mcp-adapter/mcp-adapter.php`. Revoking the provider's self-approval does not block provider-owned control requests under that policy. Administrators manage consumer approvals under `Tools > Connector Approvals`.

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

- v2.1.2 completed the eight-configuration local qualification matrix against the audited WordPress AI 1.4.0, MCP Adapter 0.7.0 and PHP AI Client 1.5.0 targets, with Codex stable 0.162.1. See the [retained findings and qualification report](docs/upstream-remediation-2026-10-11.md).
- Intended-host device-code authentication, forced refresh and one WordPress text generation succeeded, with a persisted success log. Live image generation, complete production error/de-duplication coverage and reboot recovery remain unverified; the logging bridge remains.
- Phase 1 functional cutover to the localhost runtime is complete.
- Phase 2 cleanup removed the remaining legacy compatibility aliases from the active plugin code and docs.
- Pending device-code sessions now recover cleanly after a sidecar restart when `auth.json` already exists for that user.
- Text-to-image generation is exposed through `codex-image` only for connected users whose current Codex app-server snapshot reports `imageGeneration: true`. The capability flag does not establish account entitlement or remaining image quota.

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

Release qualification uses local evidence; GitHub Actions is unavailable for this
repository. Run the full verification script against disposable WordPress 7.0 and
7.1 / MySQL 8.4 installations on PHP 7.4 and 8.3, plus Composer validation and
PHPStan. Also check the latest WordPress AI/MCP stack and standalone PHP AI Client
without replacing Core's bundled SDK. AI 1.4 requires WordPress 7.0.3 or newer.
See [the local release gate](docs/local-release-gate.md) for coverage and evidence
requirements.

The audited Codex stable target is **0.162.1**. Verify the official binary's
checksum and exercise the real sidecar JSON-RPC transport with an isolated,
unauthenticated `CODEX_HOME`. These checks cover initialization, capability probing,
generation-thread approval and sandbox policies, disabled host-tool features,
process restart, and device-code protocol availability. To run them locally on Linux:

```sh
CODEX_BIN=/path/to/codex python3 sidecar/scripts/test-codex-compatibility.py --version 0.162.1
```

Save the exact source revision and patch, tool versions, dependency and binary
checksums, logs, and exit codes, including failures. Preview Codex checks are
reported separately. These checks do not perform login or billable generation;
intended-host authentication and generation need their own evidence.

Generation honors the consumer's SDK request timeout and redirect limit. Explicit
SDK options take precedence over the provider timeout filter; absent options keep
the provider defaults. WordPress Core's prompt builder supplies a 30-second timeout
by default; the provider's 360-second generation fallback applies when the caller
supplies no SDK timeout. Consumers requesting a long generation should supply a
matching timeout. A separate SDK connection timeout has no portable WordPress
HTTP mapping. JSON output without a schema uses a JSON-only instruction and
validates the returned text; invalid output fails instead of being accepted as
plain text.

## Automated Sidecar Setup

- `sidecar/systemd/codex-wp-sidecar.service` provides a systemd template for running the sidecar.
- The plugin can auto-detect the runtime URL and bearer token from `/etc/codex-wp-sidecar.env` when PHP can read that file.
- The runtime status probe now checks `GET /healthz`, so Connectors can report `Runtime unreachable` before a user hits the connect action.
