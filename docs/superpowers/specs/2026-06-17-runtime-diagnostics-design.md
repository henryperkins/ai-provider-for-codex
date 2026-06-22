# Local Runtime Diagnostics And Guided Setup Design

**Date:** 2026-06-17

**Goal:** Replace the shallow, implicit `/healthz` page-load probe with an explicit, read-only diagnostics experience that tells an administrator *why* the local runtime is or is not working — and surface the existing setup guidance (plus generated, install-tailored systemd/env snippets) directly on the settings page. Keep all OS installation manual: no file writes outside WordPress, no process installation, no phone-home.

**Status:** Design spec. No implementation has been performed.

## Background And Problem

The current setup UX is a checker, not a guide, and the checker is shallow.

- The only runtime check is `HealthMonitor::probe()` — an unauthenticated `GET /healthz` with a 5s timeout (`src/Runtime/HealthMonitor.php:117`). The sidecar's `/healthz` returns a static `{ok, service, version, codexBin}` (`sidecar/app/main.py:782`) and runs *before* `_ensure_authorized()`, so it proves only that the Python process is answering.
- Because `/healthz` is pre-auth and the probe sends no `Authorization` header, a **bearer-token mismatch is invisible**: the page shows "healthy" with a wrong token, and the failure only surfaces at the first authenticated call (connect or generation). This is the single most valuable gap to close.
- `Settings > Codex Provider` blocks on the live probe during render (`src/Admin/SiteSettings.php:250`) and hides the "Quick setup" guide in a contextual Help tab (`src/Admin/SiteSettings.php:114-198`), not the page body.
- Failure states collapse into "Not configured" or "Runtime unreachable" with no step-level detail (Python present? `codex` executable? app-server boots? storage writable?).
- The automation that *could* generate a systemd unit and env file already exists in `sidecar/scripts/install-systemd.sh`, but that path is excluded from the release zip (`scripts/release-exclude.txt:125`), so a plugin-only installer never receives it.

These observations were verified against the current tree on 2026-06-17.

## Goals And Non-Goals

**Goals**

- An authenticated, read-only sidecar diagnostic endpoint that runs step-level checks and returns a structured result.
- An explicit "Check runtime" action in wp-admin; no live runtime call on ordinary page load.
- Close the bearer-match blind spot by putting the diagnostic behind bearer auth (endpoint reached ⇒ token matches).
- Surface the existing setup guide in the settings page body and generate copy-paste systemd/env snippets tailored to this install.

**Non-Goals**

- No OS-level installation, no writing to `/etc`, no executing installers from PHP. The plugin only *displays* text.
- No new database tables. The check runs live; only a compact verdict summary (a transient) and the suggested bearer token (an option) are persisted.
- No version bump as part of this work (see Versioning And Release).
- No change to the passive status path used by `/status`, Connectors, or the user page (see Passive-Status Scope).

## Architecture Overview

One new sidecar endpoint, one new WordPress REST proxy, one new admin asset. No schema change.

```
Settings > Codex Provider        WordPress REST (new)              Sidecar (new)
  "Check runtime" button --fetch--> POST htperkins-aipfc/v1/      --Runtime\Client--> GET /v1/diagnostics
  + results panel        <--JSON--  diagnostics                     (Bearer auth)     (authenticated)
                                    (manage_options)                                       |
                                         |                                          runs server-side checks
                                         └─ records last-diagnostics verdict
```

The browser never talks to the sidecar directly; all traffic is proxied through WordPress REST exactly as the existing connect/status routes are.

The results panel is assembled from **two sources**:

- **PHP-derived rows**, free from the proxy call itself:
  - *Sidecar reachable* — the HTTP request connected.
  - *Bearer token matches* — the request returned `200`, not `401` (blind spot closed).
  - *Resolved configuration* — a small block (not pass/fail) showing where the runtime URL and bearer token each resolved from, via `Settings::configuration_metadata()` (`base_url_source`, `bearer_token_source`).
- **Sidecar-reported rows**, the four server-side checks below.

## Component 1: Sidecar `GET /v1/diagnostics`

Add the route in `RuntimeHandler._dispatch()` **after** `_ensure_local_client()` and `_ensure_authorized()` (contrast `/healthz`, which returns before auth). Reaching the handler therefore proves loopback origin and bearer-match.

The handler calls a new `run_diagnostics()` that returns an ordered, individually-guarded check list. One failing check never aborts the others; each is wrapped so its exception becomes a `fail` row.

Response shape:

```json
{
  "ok": true,
  "service": "codex-wp-sidecar",
  "version": "0.1.5",
  "checks": [
    { "id": "python_version", "label": "Python runtime",        "status": "pass", "detail": "Python 3.11.6" },
    { "id": "codex_cli",      "label": "Codex CLI",             "status": "pass", "detail": "codex 0.x.y at /usr/local/bin/codex" },
    { "id": "storage_root",   "label": "Storage root writable", "status": "pass", "detail": "/var/lib/codex-wp is writable" },
    { "id": "app_server",     "label": "app-server handshake",  "status": "pass", "detail": "initialize completed in 0.8s" }
  ]
}
```

- `status` vocabulary: `pass | fail | warn`.
- `ok` = logical AND of the critical checks (all four are critical).
- The `service`/`version` fields reuse a shared identity helper (see Versioning And Release) — no new hardcoded version literal.

**The checks:**

- `python_version` — `sys.version_info >= (3, 11)` (requires adding `import sys`; `os`, `subprocess` etc. are already imported). Detail = `platform.python_version()`. Below 3.11 ⇒ `fail`.
- `codex_cli` — `subprocess.run([CODEX_BIN, "--version"], capture_output=True, text=True, timeout=5)`. `returncode == 0` ⇒ `pass` with the trimmed version string and `CODEX_BIN` path. `FileNotFoundError`/non-zero/`TimeoutExpired` ⇒ `fail` naming the configured path.
- `storage_root` — ensure `STORAGE_ROOT` exists (`mkdir(parents=True, exist_ok=True)`), then write-and-unlink a probe file inside it. Any `OSError` ⇒ `fail` with the path and errno text.
- `app_server` — construct `JsonRpcSession(STORAGE_ROOT / "_diagnostics")` and call `.start(initialize_timeout=DIAGNOSTIC_HANDSHAKE_TIMEOUT)`, then `.close()` in a `finally`. Success ⇒ `pass` with elapsed seconds. `FileNotFoundError` (binary missing), transport error, or timeout ⇒ `fail`. The diagnostic home is a dedicated, isolated directory and never touches a real user's `auth.json`; `start()`/`initialize` requires no stored auth (verified: the `auth.json` gate exists only in `account_snapshot`/`generate_text`, not in `start()`).

**New constant:** `DIAGNOSTIC_HANDSHAKE_TIMEOUT = 10.0` (seconds). Chosen below WordPress's 20s control-plane HTTP timeout so the sidecar returns a clean "handshake timed out" row rather than letting the PHP transport time out.

## Component 2: `JsonRpcSession.start()` Initialize Timeout

`start()` currently hardcodes `timeout=REQUEST_TIMEOUT` for its `initialize` request (`sidecar/app/main.py:99`), so the ~10s diagnostic bound is not achievable as written.

Change: add an optional parameter.

```python
def start(self, initialize_timeout: float | None = None) -> "JsonRpcSession":
    ...
    self.request("initialize", {...}, timeout=initialize_timeout or REQUEST_TIMEOUT)
```

Defaulting to `REQUEST_TIMEOUT` preserves every existing caller (`app_server_session`, `start_login`, `account_snapshot`, `generate_text`). The diagnostic constructs its own session and calls `.start(initialize_timeout=DIAGNOSTIC_HANDSHAKE_TIMEOUT)` directly (it does not need the `app_server_session` context manager, but may use it if the manager is extended to forward the argument).

## Component 3: WordPress REST Proxy And Client

**`REST/DiagnosticsController`** — registers `POST /htperkins-aipfc/v1/diagnostics` in namespace `htperkins-aipfc/v1`.

- `permission_callback` = `current_user_can( 'manage_options' )`. This is stricter than the existing connect/status routes (logged-in + `read`) because the diagnostic exposes host paths and binary versions and spawns a process. POST (not GET) signals a non-idempotent action and avoids caching.
- Calls a new `Runtime\Client::diagnostics()`, then composes the final row list: the PHP-derived rows (reachability, bearer-match, resolved configuration) followed by the sidecar `checks`.
- Records the run's verdict in a **dedicated transient `htperkins_aipfc_last_diagnostics`** — compact (`checked_at`, `ok`, labels of any failed checks), short TTL, always shown with its timestamp — on **every** run: success, an authenticated `200` whose `ok` is `false` (a critical check failed), and transport/`401` failures alike. It does **not** write the shared `HealthMonitor` reachability transient.

This decoupling is deliberate. `HealthMonitor::probe()` records success on any sub-`400` response (`src/Runtime/HealthMonitor.php:146-162`) and is still invoked by `SupportChecks::current_user_status()` (`src/Provider/SupportChecks.php:48`) for `/status`, the Connectors card, and the user page. If the diagnostic wrote its verdict into the same transient, the next passive unauthenticated `/healthz` probe — which returns `200` even with a wrong bearer or a broken `codex` binary — would erase it. Keeping the authoritative verdict in its own store means a passive reachability probe can never overwrite it, and a sidecar `ok:false` (HTTP `200`, failing checks) is still recorded as a failure instead of leaving the card green.

**`Runtime\Client::diagnostics(): array`** — issues `GET /v1/diagnostics` with the bearer header, reusing the existing transport-error normalization (including connector-approval rewriting). On a non-2xx it throws `RuntimeRequestException` carrying `get_status_code()`, `get_runtime_error_code()`, and the payload, exactly like the other client methods. The controller maps outcomes to rows:

- Transport/connection error (`WP_Error`) ⇒ *Sidecar reachable: fail* (no sidecar rows).
- `get_status_code() === 401` (sidecar code `unauthorized` from `_ensure_authorized()`, `sidecar/app/main.py:848`) ⇒ *Sidecar reachable: pass*, *Bearer token matches: fail*. Do **not** use `is_auth_required()` — that predicate matches the unrelated `auth_required` code (HTTP `409`, missing per-user Codex login, `src/Runtime/RuntimeRequestException.php:110`), which `/v1/diagnostics` never emits.
- `200` ⇒ *Sidecar reachable: pass*, *Bearer token matches: pass*, plus the decoded `checks` (whose individual `status` values may still be `fail`, making the overall `ok` false).

The diagnostics path uses the standard 20s control-plane timeout (it is not a `/v1/responses/` path).

## Component 4: Settings Page UX

**Passive on load (scoped).** `SiteSettings::render_page()` stops calling `HealthMonitor::probe()` at `src/Admin/SiteSettings.php:250` and instead reads `HealthMonitor::get_status()` for the status card. This removes the up-to-5s render block. A freshly configured site shows the last-known/`unknown` state until the admin clicks "Check runtime".

This change is **deliberately limited to `render_page()`**. `SupportChecks::current_user_status()` still calls `HealthMonitor::probe()` (`src/Provider/SupportChecks.php:48`), and that path remains unchanged — `/status`, the Connectors card, and the user connection page keep their existing cheap `/healthz` probe. This design does not claim that all status reads become passive; only the settings page stops blocking.

The card shows **two distinct signals**: reachability from `HealthMonitor::get_status()` (passive), and — when present — the last full diagnostic verdict and timestamp from `htperkins_aipfc_last_diagnostics`. They are shown separately so a green reachability probe never masks a failed deep check (e.g. a missing `codex` binary while `/healthz` still answers `200`).

**Check runtime button + results panel.** A new hand-written `assets/diagnostics.js` (matching the existing no-build `connection-flow.js` pattern) is enqueued only on this screen. On click it POSTs to the REST route with the `wp_rest` nonce, shows a spinner, and renders the returned rows with pass/warn/fail indicators reusing the existing `.codex-indicator` CSS classes. A `node --test assets/diagnostics.test.mjs` covers the row-rendering logic. The flow is JS-driven for parity with the existing connect flow; with JS disabled the panel shows a short "enable JavaScript to run diagnostics" note.

**Setup guide in the page body.** The "Quick setup" content currently inside `render_help_tab()` is extracted to a shared `render_setup_guide()` renderer. Both the Help tab callback and the page body call it, so nothing is lost and there is no duplication.

**Resolved configuration block.** The panel shows where the runtime URL and bearer token resolved from, sourced from `Settings::configuration_metadata()` (already computed and partially shown as field descriptions today).

## Component 5: Setup Snippet Generator

A new `Admin\SetupSnippets` builds two read-only, copy-paste blocks rendered in the settings body. Purely display — no writing, no exec.

- **systemd unit** — reads the bundled template `sidecar/systemd/codex-wp-sidecar.service` and substitutes the real installed plugin directory (`\Htperkins\AIProviderForCodex\PLUGIN_DIR`) for the placeholder path. Rendering from the template keeps the snippet in lockstep with what ships.
- **env file** (`/etc/codex-wp-sidecar.env`) — emits `CODEX_BIN`, `CODEX_WP_STORAGE_ROOT`, `CODEX_WP_HOST`, `CODEX_WP_PORT`, `CODEX_WP_RUNTIME_BASE_URL` (detected values where available, else the documented defaults), and `CODEX_WP_BEARER_TOKEN` set to a stable suggested token (Component 6).

Both blocks render as read-only fields with a copy affordance. The env path mirrors `Settings`'s shared-env-file default so auto-detection works when PHP can read the file.

## Component 6: Suggested Bearer Token Option

The snippet needs a bearer value that is stable across reloads (a value regenerated every render would be unusable). Resolution order:

1. If a bearer token is already configured (option/env/file), use it — the env snippet then matches what WordPress already expects.
2. Otherwise, lazily generate one with `wp_generate_password( 64, false )` and cache it in a new option `htperkins_aipfc_suggested_bearer_token`.

Option lifecycle, per review:

- **`autoload = false`** — written via `add_option( 'htperkins_aipfc_suggested_bearer_token', $value, '', false )`.
- **Uninstall cleanup** — add `'htperkins_aipfc_suggested_bearer_token'` to the option array in `uninstall.php` (lines 16-22).
- **Privacy** — the value is a locally generated suggestion, not transmitted anywhere. Reuse the existing shared-bearer-token framing in `readme.txt` (the "Privacy" section, `readme.txt:102-108`); extend that list to mention the cached suggestion is stored locally and removed on uninstall. No schema change.

## Component 7: Documentation Fix

Complete the truncated sentence at `sidecar/README.md:65` ("...the WordPress plugin can auto-detect"), matching the complete phrasing already present later in the same file (`sidecar/README.md:74`).

## Versioning And Release

No version strings change in this work. `scripts/verify.sh:84-107` derives the plugin version from the plugin header and requires the sidecar to report that exact version in `clientInfo`, the `/healthz` payload, and `server_version`. Bumping the sidecar alone would fail that guard.

Everything stays at `0.1.5` during development; the next release bumps the plugin header, `const VERSION`, `readme.txt` `Stable tag:`, and the sidecar strings together as one atomic step, exactly as the existing release process already does.

To avoid adding a *third* hand-maintained version literal (and the drift risk that creates), extract a shared identity helper in the sidecar that returns only the two shared fields:

```python
def _server_identity() -> dict[str, Any]:
    return {"service": "codex-wp-sidecar", "version": "0.1.5"}
```

Each endpoint composes its own response around it, so nothing is clobbered:

- `/ping` and `/healthz` → `{"ok": True, **_server_identity(), "codexBin": CODEX_BIN}`
- `/v1/diagnostics` → `{"ok": <computed>, **_server_identity(), "checks": [...]}` (no `codexBin`, `ok` is the computed AND of checks)

The literal `"version": "0.1.5"` then appears exactly twice in the file — once in `clientInfo`, once in the helper — so `verify.sh`'s "at least 2" check is preserved with no new release burden.

## Error Handling

- Sidecar: each check is individually guarded; `run_diagnostics()` always returns the full list, degrading to per-row `fail` rather than a 500. The handler's existing `_dispatch` `try/except` still covers auth and transport faults.
- WordPress: `Runtime\Client::diagnostics()` reuses existing transport normalization, including the connector-approval rewrite. Connection failures and `401`s map to the reachability/bearer rows rather than throwing into the panel.
- Browser: a failed fetch shows an inline error in the panel and never blocks the page.

## Testing Plan

- **`scripts/verify.php`** (mocked sidecar via `pre_http_request`): assert the `manage_options` gate on the new route; the row mapping for success, a `200` with `ok:false` (failing sidecar checks), `401` (bearer-fail, keyed off `get_status_code()`/`unauthorized`, **not** `is_auth_required()`), and connection-refused; that `htperkins_aipfc_last_diagnostics` is written on each of those outcomes while the `HealthMonitor` reachability transient is left untouched by the diagnostic (a subsequent `probe()` is unaffected); and that `render_page()` makes no HTTP request on load.
- **`assets/diagnostics.test.mjs`** via `node --test`: row rendering for pass/warn/fail and the empty/error states.
- **Sidecar checks**: validated by a manual run plus a standalone invocation of `run_diagnostics()`; the repository has no Python test harness today, and `verify.php` exercises the PHP side against a mocked sidecar.
- **`scripts/verify.sh`** must continue to pass unchanged (the liveness-helper refactor keeps the version-literal count at two).

## File-By-File Change Summary

- `sidecar/app/main.py` — add `import sys`; `_server_identity()` helper; `DIAGNOSTIC_HANDSHAKE_TIMEOUT`; `JsonRpcSession.start(initialize_timeout=None)`; `run_diagnostics()`; route `GET /v1/diagnostics` after auth; reuse helper in `/ping` and `/healthz`.
- `src/REST/DiagnosticsController.php` — new controller, `manage_options` gate, exception→row mapping (incl. the `401`/`unauthorized` bearer-fail predicate), writes the `htperkins_aipfc_last_diagnostics` verdict transient; does **not** write `HealthMonitor`.
- `src/Runtime/Client.php` — new `diagnostics()` method.
- `src/Admin/SiteSettings.php` — drop the page-load probe; extract `render_setup_guide()`; render guide, resolved-config block, Check-runtime button, results panel, and snippets in the body; enqueue `diagnostics.js`.
- `src/Admin/SetupSnippets.php` — new snippet generator and suggested-token resolution.
- `assets/diagnostics.js`, `assets/diagnostics.test.mjs` — new admin asset and test.
- `uninstall.php` — add `htperkins_aipfc_suggested_bearer_token` to the option cleanup array and `delete_transient( 'htperkins_aipfc_last_diagnostics' )` alongside the existing transient cleanup.
- `readme.txt` — extend the Privacy section for the cached suggested token.
- `sidecar/README.md` — fix the truncated sentence at line 65.
- `scripts/verify.php` — new assertions described above.
- Plugin bootstrap (`ai-provider-for-codex.php` / `Plugin`) — register the new REST controller and the settings-screen asset enqueue.
