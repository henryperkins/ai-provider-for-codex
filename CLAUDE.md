# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A WordPress plugin (`ai-provider-for-codex`) that registers a `codex` provider with the **WordPress AI Client** (WP 7.0+, `wordpress/php-ai-client` SDK 1.0+). Once registered, the `codex` provider becomes available to every consumer of `wp_ai_client_prompt()` and appears as a card under **Settings → Connectors**.

The defining constraint: **the PHP plugin never talks to OpenAI/Codex directly.** It talks only to a **localhost sidecar** over loopback HTTP. The sidecar (Python, `sidecar/app/main.py`) wraps the `codex` CLI's `app-server` over JSON-RPC, performs device-code login, and keeps each WordPress user's auth isolated in its own `CODEX_HOME`. Auth and billing are ChatGPT-managed; WordPress stores only connection metadata and cached snapshots — never tokens. Text generation only (vision/image route to other providers).

`LOCAL-SIDECAR-SPEC.md` is the implementation spec for this architecture.

## Two processes, one HTTP contract

Everything important happens across two processes that meet at a small loopback HTTP contract (Bearer-authed, 127.0.0.1-only). Understanding a change usually means tracing across this boundary:

```
WordPress (PHP, src/)  ──HTTP──>  Sidecar (Python, sidecar/app/main.py)  ──JSON-RPC/stdio──>  codex app-server
        Runtime\Client                RuntimeHandler                              per-user CODEX_HOME
```

Sidecar endpoints (the full contract; see `sidecar/app/main.py` `RuntimeHandler._dispatch`):
- `GET /healthz`, `GET /ping` — health (no auth)
- `POST /v1/login/start` → device-code (`verificationUrl`, `userCode`)
- `GET  /v1/login/status` → `pending|completed|error|missing`
- `GET  /v1/account/snapshot` → account + `model/list` + rate limits (requires stored `auth.json`)
- `POST /v1/responses/text` → text generation (ephemeral thread → turn → streamed deltas)
- `POST /v1/session/clear` → deletes the user's `auth.json`

Per-user auth lives at `${CODEX_WP_STORAGE_ROOT}/users/<wp_user_id>/auth.json` (default `/var/lib/codex-wp`). The sidecar enforces loopback-only clients and `hmac`-compares the bearer token.

## PHP architecture (`src/`, PSR-4 `AIProviderForCodex\`)

Boot: `plugin.php` runs version/SDK gates (`check_php_version`/`check_wp_version`/`check_ai_client`) then `Plugin::init()` wires all hooks. `register_provider()` registers `CodexProvider` on `init` (priority 5), gated by `wp_supports_ai()`.

- **`Provider/`** — AI Client integration. `CodexProvider` (extends `AbstractApiProvider`; wires model factory, metadata, availability, catalog; metadata is version-gated against SDK 1.2.0/1.3.0 for description/logo). `CodexProviderAvailability::isConfigured()` = has runtime config **and** `HealthMonitor` not `unreachable`. `ModelCatalog`/`ModelCatalogState`, `SupportChecks`.
- **`Models/CodexTextGenerationModel`** — the generation path. Flattens prompt messages, POSTs `/v1/responses/text`, maps reasoning effort + JSON-schema output, and calls `ConnectionService::invalidate_local_connection()` on an `auth_required` runtime error.
- **`Runtime/`** — the HTTP boundary. `Client` (WordPress HTTP API, Bearer auth, **20s control-plane timeout / 360s for any `/v1/responses/` path**, extensive transport+runtime error normalization including Connector-Approval blocks). `Settings` (option names + the config precedence chain below). `HealthMonitor` (transient-cached health, probes `/healthz`; `unknown` counts as available). `ResponseMapper`, `RuntimeRequestException` (carries status/code/payload; `is_auth_required()`).
- **`Auth/`** — connection lifecycle. `ConnectionService` (start/poll/refresh/disconnect; reuses a still-valid stored `auth.json` before starting a new device-code login; recovers sidecar "missing session" after a restart). `ConnectionRepository`, `ConnectionSnapshotRepository`, `PendingConnectionRepository`, `ConnectionRefreshScheduler` (hourly cron `codex_provider_refresh_connection_snapshots`).
- **`Database/Installer`** — creates two custom tables (`{prefix}codex_provider_connections`, `{prefix}codex_provider_connection_snapshots`). `maybe_upgrade()` re-runs `activate()` when `codex_provider_schema_version` (currently `'5'`) drifts, and cleans up legacy schema/options.
- **`Admin/`** — `SiteSettings` (**Settings → Codex Provider**: runtime URL, bearer, fallback models). `UserConnectionPage` (**Users → Codex Provider**: per-user device-code connect UI; this is the provider's `credentialsUrl`). `ConnectorsIntegration` (the **Settings → Connectors** card + the `wpai_*` credential-bridge filters + script-module data + admin notices). `ConnectorApprovalIntegration` (optional experiment, see below).
- **`REST/`** — namespace `codex-provider/v1`: `ConnectController` (`/connect/start|status|disconnect|refresh`) and `StatusController` (`/status`). Permission callback = logged in + `read`.

Runtime uses a **hand-rolled autoloader** (`src/autoload.php`), not Composer's. Composer is dev-only (PHPStan + the SDK for static analysis/stubs); the SDK is expected to be provided by WordPress / the WordPress AI plugin at runtime.

## Invariants worth preserving (and that tests enforce)

- **Status reads are passive.** `SupportChecks` / the `/status` route / the Connectors card use stored state + a cheap `/healthz` probe only. They must **not** trigger live `account/snapshot` or generation calls — only explicit connect/refresh/generate do. `scripts/verify.php` asserts this.
- **Effective model catalog is two sources, no cascade** (`ModelCatalogState`): the user's runtime snapshot if they're linked and it has models, otherwise the admin **fallback** list (`codex_runtime_allowed_models`, defaults `gpt-5-codex`, `gpt-5.3-codex`). Selected model = user meta `codex_provider_preferred_model` → first available.
- **Bearer token must match raw** between WordPress and the sidecar's `CODEX_WP_BEARER_TOKEN`. `Settings::normalize_bearer_token_value()` strips a pasted `Bearer ` prefix and surrounding quotes but preserves opaque contents — keep that behavior.

## Runtime config precedence (`Runtime\Settings`)

For each setting, the first non-empty wins: **PHP constant → environment variable → shared env file → stored WP option.** When a value is supplied externally, the corresponding Settings field is locked in the UI. The shared env file defaults to `/etc/codex-wp-sidecar.env` (override with `CODEX_WP_RUNTIME_ENV_FILE`) and is the basis for "auto-detected" runtime settings.

Keys: `CODEX_WP_RUNTIME_BASE_URL` (or `CODEX_WP_HOST` + `CODEX_WP_PORT`), `CODEX_WP_BEARER_TOKEN`. WP option names: `codex_runtime_base_url`, `codex_runtime_bearer_token`, `codex_runtime_allowed_models`. Sidecar env contract: `sidecar/config.example.env`.

## Connector Approval (optional WP AI experiment)

If the WordPress AI plugin's **Connector Approval** experiment is enabled, `ConnectorApprovalIntegration::maybe_self_approve()` grants the plugin's own `codex` connector a one-time approval (on activation and ordinary boot). It does **not** pre-approve while the experiment is off, and an admin revocation re-blocks runtime calls until re-approved under **Tools → Connector Approvals**. Transport errors carrying `wpai_connector_not_approved` are rewritten into an actionable message in `Client::normalize_connector_approval_error_message()`.

## Commands

Static analysis (PHPStan level 5 + `szepeviktor/phpstan-wordpress`; baseline in `phpstan-baseline.neon`):
```bash
composer install        # dev deps only (PHPStan + SDK for analysis/stubs)
composer phpstan
```

Full verification — run this before claiming a change works. Orchestrates `php -l` on all non-vendor PHP, release-exclude/Plugin-Check consistency checks, a `composer.lock` SDK-version guard, plugin.php↔readme.txt "Requires at least" parity, JS `node --check` + `node --test`, then the big WP-CLI end-to-end check:
```bash
WP_PATH=/path/to/site ./scripts/verify.sh
```

JS asset tests (no `package.json`; uses Node's built-in runner directly):
```bash
node --test assets/connection-flow.test.mjs
node --test assets/user-connection.test.mjs
```

WP-CLI end-to-end check on its own (the ~1.5k-line `scripts/verify.php` exercises the whole plugin with mocked HTTP via `pre_http_request`):
```bash
wp --path=/path/to/site eval-file wp-content/plugins/ai-provider-for-codex/scripts/verify.php
```

Release-style Plugin Check (mirrors the release zip's exclusions against a symlinked dev checkout):
```bash
WP_PATH=/path/to/wordpress bash scripts/plugin-check-release.sh
```

Build the distributable zip (refuses to build on version drift; writes to `../plugin-builds/`):
```bash
bash scripts/package-release.sh
```

Sidecar (separate process, same host): Python 3.11+ and the `codex` CLI; configure via env (`sidecar/config.example.env`) and run `sidecar/app/main.py`. Systemd template: `sidecar/systemd/codex-wp-sidecar.service`.

## Releasing — version sync gotcha

A release must keep these in lockstep:
- `plugin.php`: the `Version:` header **and** the `const VERSION` (and `MIN_WP_VERSION` if it changes)
- `readme.txt`: `Stable tag:` (must equal the plugin.php `Version:`) and `Requires at least:` (must equal plugin.php's, and be referenced in `PLUGIN-SUBMISSION-READINESS-CHECKLIST.md`)
- `sidecar/app/main.py`: the reported `version` strings (`clientInfo`, the `/healthz` payload, `server_version`)

`package-release.sh` enforces plugin.php `Version:` == readme `Stable tag:`, and `verify.sh` enforces the `Requires at least:` parity (plugin.php ↔ readme.txt ↔ checklist). The sidecar version strings are **not** script-enforced — bump them by hand.

`PLUGIN-SUBMISSION-READINESS-CHECKLIST.md` is the WordPress.org submission gate. Planning/spec artifacts live in `docs/superpowers/plans/` and `docs/superpowers/specs/`.
