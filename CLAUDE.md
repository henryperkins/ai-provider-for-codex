# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A WordPress plugin (`ai-provider-for-codex`) that registers a `codex` provider with the **WordPress AI Client** (WP 7.0+, `wordpress/php-ai-client` SDK 1.0+). Once registered, the `codex` provider becomes available to every consumer of `wp_ai_client_prompt()` and appears as a card under **Settings → Connectors**.

The defining constraint: **the PHP plugin never talks to OpenAI/Codex directly.** It talks only to a **localhost sidecar** over loopback HTTP. The sidecar (Python, `sidecar/app/main.py`) wraps the `codex` CLI's `app-server` over JSON-RPC, performs device-code login, and keeps each WordPress user's auth isolated in its own `CODEX_HOME`. Auth and billing are ChatGPT-managed; WordPress stores only connection metadata and cached snapshots, never tokens. Text generation is always model-catalog backed; text-to-image generation is exposed through `codex-image` only when the connected user's snapshot reports `imageGeneration: true`.

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
- `GET  /v1/account/snapshot` → account + capabilities + `model/list` + rate limits (requires stored `auth.json`)
- `POST /v1/responses/text` → text generation (ephemeral thread → turn → streamed deltas)
- `POST /v1/responses/image` → image generation (capability probe → ephemeral thread → imageGeneration item result)
- `POST /v1/session/clear` → deletes the user's `auth.json`

Per-user auth lives at `${CODEX_WP_STORAGE_ROOT}/users/<wp_user_id>/auth.json` (default `/var/lib/codex-wp`). The sidecar enforces loopback-only clients and `hmac`-compares the bearer token.

## PHP architecture (`src/`, PSR-4 `Htperkins\AIProviderForCodex\`)

Boot: `ai-provider-for-codex.php` runs version/SDK gates (`check_php_version`/`check_wp_version`/`check_ai_client`) then `Plugin::init()` wires all hooks. `register_provider()` registers `CodexProvider` on `init` (priority 5), gated by `wp_supports_ai()`.

- **`Provider/`** — AI Client integration. `CodexProvider` (extends `AbstractApiProvider`; wires model factory, metadata, availability, catalog; metadata is version-gated against SDK 1.2.0/1.3.0 for description/logo). `CodexProviderAvailability::isConfigured()` = has runtime config **and** `HealthMonitor` not `unreachable`. `ModelCatalog`/`ModelCatalogState`, `SupportChecks`.
- **`Models/CodexTextGenerationModel`** — the text generation path. Flattens prompt messages, POSTs `/v1/responses/text`, maps reasoning effort + JSON-schema output, and calls `ConnectionService::invalidate_local_connection()` on an `auth_required` runtime error. Mirrors each attempt (success **and** failure) into the AI plugin's Request Log via `RequestLogWriter`.
- **`Models/CodexImageGenerationModel`** — the image generation path. Accepts text prompt parts only, rejects reference image/file parts for v1, checks the current snapshot for `codex-image`, POSTs `/v1/responses/image`, maps base64 PNG data into an AI Client image `File`, and logs image requests with `type=image` and `operation=codex:responses/image` without base64 or local artifact paths.
- **`Logging/RequestLogWriter`** — best-effort bridge that records codex generations into the WordPress AI plugin's **AI Request Logging** experiment. Codex reaches its sidecar over its own loopback `Runtime\Client`, bypassing the SDK HTTP transporter the experiment decorates — so without this, codex calls are invisible to the Request Log (OpenAI/Anthropic, which use the transporter, are logged). Writes from the model's own call site instead. Gated on the experiment being enabled (`is_logging_enabled()` mirrors `wpai_features_enabled` + `wpai_feature_ai-request-logging_enabled`, fail-closed); writes never throw into generation; the sink is overridable via the `htperkins_aipfc_request_log_sink` filter. Unit-tested by `scripts/test-request-log-writer.php`; the success/error paths are asserted in `scripts/verify.php`.
- **`Runtime/`** — the HTTP boundary. `Client` (WordPress HTTP API, Bearer auth, **20s control-plane timeout / 360s for any `/v1/responses/` path**, extensive transport+runtime error normalization including Connector-Approval blocks). `Settings` (option names + the config precedence chain below). `HealthMonitor` (transient-cached health, probes `/healthz`; `unknown` counts as available). `ResponseMapper`, `RuntimeRequestException` (carries status/code/payload; `is_auth_required()`).
- **`Auth/`** — connection lifecycle. `ConnectionService` (start/poll/refresh/disconnect; reuses a still-valid stored `auth.json` before starting a new device-code login; recovers sidecar "missing session" after a restart). `ConnectionRepository`, `ConnectionSnapshotRepository`, `PendingConnectionRepository`, `ConnectionRefreshScheduler` (hourly cron `htperkins_aipfc_refresh_connection_snapshots`).
- **`Database/Installer`** — creates two custom tables (`{prefix}htperkins_aipfc_connections`, `{prefix}htperkins_aipfc_connection_snapshots`). `maybe_upgrade()` re-runs `activate()` when `htperkins_aipfc_schema_version` (currently `'6'`) drifts, and cleans up legacy schema/options. Snapshots include `capabilities_json`, used to gate `codex-image`.
- **`Admin/`** — `SiteSettings` (**Settings → Codex Provider**: runtime URL, bearer, fallback models). `UserConnectionPage` (**Users → Codex Provider**: per-user device-code connect UI; this is the provider's `credentialsUrl`). `ConnectorsIntegration` (the **Settings → Connectors** card + the `wpai_*` credential-bridge filters + script-module data + admin notices). `ConnectorApprovalIntegration` (optional experiment, see below).
- **`REST/`** — namespace `htperkins-aipfc/v1`: `ConnectController` (`/connect/start|status|disconnect|refresh`) and `StatusController` (`/status`). Permission callback = logged in + `read`.

Runtime uses a **hand-rolled autoloader** (`src/autoload.php`), not Composer's. Composer is dev-only (PHPStan + the SDK for static analysis/stubs); the SDK is expected to be provided by WordPress / the WordPress AI plugin at runtime.

## Invariants worth preserving (and that tests enforce)

- **Status reads are passive.** `SupportChecks` / the `/status` route / the Connectors card use stored state + a cheap `/healthz` probe only. They must **not** trigger live `account/snapshot` or generation calls — only explicit connect/refresh/generate do. `scripts/verify.php` asserts this.
- **Effective model catalog is two sources, no cascade** (`ModelCatalogState`): the user's runtime snapshot if they're linked and it has models, otherwise the admin **fallback** list (`htperkins_aipfc_runtime_allowed_models`, defaults `gpt-5-codex`, `gpt-5.3-codex`). Snapshot `models` remain text models; `codex-image` is appended only when `capabilities.imageGeneration === true`. Selected text model = user meta `htperkins_aipfc_preferred_model` → first text model, and text preferences must never emit `codex-image`.
- **Bearer token must match raw** between WordPress and the sidecar's `CODEX_WP_BEARER_TOKEN`. `Settings::normalize_bearer_token_value()` strips a pasted `Bearer ` prefix and surrounding quotes but preserves opaque contents — keep that behavior.

## Runtime config precedence (`Runtime\Settings`)

For each setting, the first non-empty wins: **PHP constant → environment variable → shared env file → stored WP option.** When a value is supplied externally, the corresponding Settings field is locked in the UI. The shared env file defaults to `/etc/codex-wp-sidecar.env` (override with `CODEX_WP_RUNTIME_ENV_FILE`) and is the basis for "auto-detected" runtime settings.

Keys: `CODEX_WP_RUNTIME_BASE_URL` (or `CODEX_WP_HOST` + `CODEX_WP_PORT`), `CODEX_WP_BEARER_TOKEN`. WP option names: `htperkins_aipfc_runtime_base_url`, `htperkins_aipfc_runtime_bearer_token`, `htperkins_aipfc_runtime_allowed_models`. Sidecar env contract: `sidecar/config.example.env`.

## Connector Approval (optional WP AI experiment)

If the WordPress AI plugin's **Connector Approval** experiment is enabled, `ConnectorApprovalIntegration::maybe_self_approve()` grants the plugin's own `codex` connector a one-time approval (on activation and ordinary boot). It does **not** pre-approve while the experiment is off, and an admin revocation re-blocks runtime calls until re-approved under **Tools → Connector Approvals**. Transport errors carrying `wpai_connector_not_approved` are rewritten into an actionable message in `Client::normalize_connector_approval_error_message()`.

## Commands

Static analysis (PHPStan level 5 + `szepeviktor/phpstan-wordpress`; baseline in `phpstan-baseline.neon`):
```bash
composer install        # dev deps only (PHPStan + SDK for analysis/stubs)
composer phpstan
```

Full verification — run this before claiming a change works. Orchestrates `php -l` on all non-vendor PHP, release-exclude/Plugin-Check consistency checks, a `composer.lock` SDK-version guard, ai-provider-for-codex.php↔readme.txt "Requires at least" parity, JS `node --check` + `node --test`, then the big WP-CLI end-to-end check:
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

Sidecar (separate process, same host): Python 3.11+ and the `codex` CLI; configure via the env values shown by `SetupSnippets::env_file()`. The WordPress.org zip excludes `sidecar/app`, `sidecar/systemd`, and `sidecar/config.example.env`; the settings screen renders a systemd unit for an externally installed `/usr/local/bin/codex-wp-sidecar` command.

Sidecar unit tests:
```bash
python3 sidecar/scripts/test-diagnostics.py
python3 sidecar/scripts/test-token-usage.py
python3 sidecar/scripts/test-image-generation.py
```

## Releasing — version sync gotcha

A release must keep these in lockstep:
- `ai-provider-for-codex.php`: the `Version:` header **and** the `const VERSION` (and `MIN_WP_VERSION` if it changes)
- `readme.txt`: `Stable tag:` (must equal the ai-provider-for-codex.php `Version:`) and `Requires at least:` (must equal ai-provider-for-codex.php's, and be referenced in `PLUGIN-SUBMISSION-READINESS-CHECKLIST.md`)
- `sidecar/app/main.py`: the reported `version` strings (`clientInfo`, the `/healthz` payload, `server_version`)

`package-release.sh` enforces ai-provider-for-codex.php `Version:` == readme `Stable tag:`, and `verify.sh` enforces the `Requires at least:` parity (ai-provider-for-codex.php ↔ readme.txt ↔ checklist). The sidecar version strings are **not** script-enforced — bump them by hand.

`PLUGIN-SUBMISSION-READINESS-CHECKLIST.md` is the WordPress.org submission gate. Planning/spec artifacts live in `docs/superpowers/plans/` and `docs/superpowers/specs/`.
