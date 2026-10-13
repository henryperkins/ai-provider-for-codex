# How The Codex WordPress Sidecar Works

This document describes the sidecar exactly as it is implemented in this repository.

It is the runtime bridge between the WordPress plugin and `codex app-server`. The sidecar is a local Python HTTP service that accepts localhost requests from WordPress, starts `codex app-server` subprocesses on demand, and keeps each WordPress user's Codex auth state isolated on disk.

## Topology

Runtime flow:

```text
WordPress plugin
  -> local HTTP request
  -> sidecar
  -> JSON-RPC over stdio
  -> codex app-server
  -> ChatGPT / Codex account and model APIs
```

The implementation lives in [sidecar/app/main.py](./app/main.py). The plugin-side caller and storage code live in:

- [src/Runtime/Client.php](../src/Runtime/Client.php)
- [src/Auth/ConnectionService.php](../src/Auth/ConnectionService.php)
- [src/Models/CodexTextGenerationModel.php](../src/Models/CodexTextGenerationModel.php)
- [src/Models/CodexImageGenerationModel.php](../src/Models/CodexImageGenerationModel.php)
- [src/Runtime/Settings.php](../src/Runtime/Settings.php)

## What The Sidecar Process Is

The sidecar is not FastAPI, Flask, or a long-running JSON-RPC broker. It is a small Python program built on the standard library:

- `ThreadingHTTPServer` handles incoming HTTP requests.
- `BaseHTTPRequestHandler` routes requests.
- `subprocess.Popen()` starts `codex app-server`.
- A `JsonRpcSession` object turns stdio into a request/response plus notification stream.

The sidecar reads its runtime configuration from environment variables:

- `CODEX_BIN`: path to the `codex` executable
- `CODEX_WP_STORAGE_ROOT`: root directory for per-user Codex homes
- `CODEX_WP_HOST`: bind host, default `127.0.0.1`
- `CODEX_WP_PORT`: bind port, default `4317`
- `CODEX_WP_BEARER_TOKEN`: shared secret required by WordPress
- `CODEX_RUNTIME_REQUEST_TIMEOUT`: short RPC timeout, default `60`
- `CODEX_RUNTIME_TURN_TIMEOUT`: wall-clock budget from `turn/start` through completion, default `300`; incoming events do not reset it
- `CODEX_RUNTIME_LOGIN_TIMEOUT`: device-code login wait timeout, default `1800`

At startup the sidecar:

1. Ensures the storage root exists.
2. Creates a `ThreadingHTTPServer` bound to `HOST:PORT`.
3. Serves forever until the process is stopped.

## Security Boundary

The sidecar is intentionally local-only.

### Network checks

Every request other than `GET /ping` and `GET /healthz` is rejected unless:

- the TCP client address is `127.0.0.1` or `::1`
- the `Authorization` header is present as `Bearer <token>`
- the bearer token matches `CODEX_WP_BEARER_TOKEN`

Token comparison uses `hmac.compare_digest()`, which avoids normal string comparison timing behavior.

### What this means operationally

- The browser is not supposed to talk to the sidecar directly.
- WordPress talks to it server-to-server over loopback.
- If the host, port, or token are wrong, the plugin fails locally before any Codex request is made.

## Per-User Isolation

Each WordPress user gets a separate Codex home directory:

```text
<storage-root>/users/<wp_user_id>/
```

By default that is:

```text
/var/lib/codex-wp/users/<wp_user_id>/
```

When the sidecar starts a `codex app-server` subprocess for a user, it sets:

- `CODEX_HOME=<that user's directory>`
- `HOME=<that user's directory>`
- `USERPROFILE=<that user's directory>`

That causes `codex app-server` to read and write auth and runtime files inside that per-user directory instead of sharing state across users.

### Generation Isolation (Three Layers)

Every generation session enforces isolation across three distinct layers:

1. **Process environment:** `codex app-server` receives only `PATH`, `PATHEXT`, `SYSTEMROOT`, `WINDIR`, the CA trust paths `SSL_CERT_FILE`, `SSL_CERT_DIR` and `CODEX_CA_CERTIFICATE`, and the proxy settings `HTTP_PROXY`, `HTTPS_PROXY`, `ALL_PROXY` and `NO_PROXY` (any letter case) from the parent process, plus `CODEX_HOME`, `HOME` and `USERPROFILE` pointing to the user's isolated directory. Provider API keys, sidecar credentials (`CODEX_WP_BEARER_TOKEN`), database passwords and every other variable are never passed to the child process. Proxy variables are operator-owned network configuration like the CA paths: Codex honors them by default, and a host that reaches `chatgpt.com` only through a proxy cannot log in or generate without them. Under the enforced thread policy the model cannot read the child's environment.

2. **Process features:** Every `codex app-server` is spawned with `--disable apps --disable plugins`. ChatGPT Apps exposes the connected ChatGPT account's external connectors to every generation turn, and Plugins synchronizes the account's installed plugins into the user's `CODEX_HOME` and starts declared MCP servers as host processes. Using `--disable` (rather than `-c features.<name>=false`) ensures startup fails closed if an unrecognized feature name is supplied.

3. **Thread policy:** Every generation thread is created with `approvalPolicy: "never"`, `sandbox: "read-only"`, `environments: []`, `features.shell_tool: false`, `features.stable_environment_tools: false`, `features.view_image: false`, `features.multi_agent: false`, `features.multi_agent_v2: false`, `agents.enabled: false`, and `web_search: "disabled"`. Native image generation (`features.image_generation`) is enabled only for image threads when advertised by the connected account. The empty environment selection ensures model tools have no host filesystem environment (preventing native image tools from reading local reference paths and disabling `apply_patch`). The sidecar inspects the `thread/start` result and immediately aborts the turn with HTTP 502 `runtime_policy_rejected` if Codex fails to echo the exact requested approval policy, read-only sandbox, and empty environments list.

**Security boundary:** On the qualified baseline without externally configured tools, built-in model tools have no host filesystem or command-execution access. Codex itself retains access to authentication, runtime state and generated-artifact storage. MCP servers and enabled, trusted or managed hooks defined in any applicable Codex configuration layer run as ordinary host processes under the sidecar's OS account, outside the per-thread policy. Keep MCP servers, hooks and plugin marketplaces out of system, user, managed and project configuration on the sidecar host. `--disable plugins` removes account-synced plugins only; it does not suppress separately configured servers or hooks. Per-user directories under one OS account separate state but are not an OS isolation boundary; that requires separate service accounts or filesystem confinement.

The sidecar itself only explicitly knows about `auth.json`:

- `auth.json` is treated as the marker that a user has stored auth.
- `POST /v1/session/clear` deletes `auth.json`.

Codex also creates runtime databases, caches, system skills and generated-artifact files. The sidecar never writes per-user configuration. Diagnostics warn about `config.toml`, `hooks.json`, `.env`, `AGENTS.md` and `AGENTS.override.md` in user homes, without reading their contents. This scan does not audit all system or managed configuration layers.

## JSON-RPC Bridge To `codex app-server`

The core adapter is `JsonRpcSession`.

### Session startup

When a session starts, it:

1. Creates the user directory if needed.
2. Spawns `codex app-server --disable apps --disable plugins` with the scoped child environment.
3. Connects to its stdin/stdout and drains stderr, keeping a short printable tail (five lines) for error messages.
4. Starts a reader thread.
5. Sends an `initialize` JSON-RPC request.

The reader thread continuously reads newline-delimited JSON messages from stdout and splits them into:

- responses to in-flight requests, matched by integer request id
- notifications, stored in an in-memory queue

The writer side serializes one JSON object per line and flushes immediately.

### Request/notification model

The sidecar uses JSON-RPC synchronously for direct requests like:

- `account/login/start`
- `account/read`
- `account/rateLimits/read`
- `model/list`
- `modelProvider/capabilities/read`
- `thread/start`
- `turn/start`
- `turn/interrupt` (best-effort on deadline expiry)
- `experimentalFeature/list` (explicit diagnostics only)

It uses notification waiting for long-running async events like:

- login completion
- streaming text generation deltas
- completed image generation items
- turn completion

### Process lifetime

There are two distinct session lifetimes:

- Short-lived sessions for snapshot and generation requests.
- A longer-lived in-memory session for a pending device-code login.

For short-lived work, the sidecar uses `app_server_session(...)`, which starts a subprocess, does the work, and closes it immediately afterward.

For device-code login, the sidecar keeps the subprocess alive in memory until the login completes or errors, because it needs to wait for an `account/login/completed` notification. If the app-server fails to start or the device-code request fails, the sidecar closes that subprocess before returning the error; an unexpected exit reports the exit code together with Codex's last stderr lines.

## HTTP API

The sidecar exposes health, diagnostics, login, snapshot, text/image generation and session-clear routes, plus the `ping` health alias.

### `GET /healthz`

Purpose:

- prove that the sidecar process is up
- return the current sidecar version and configured Codex binary path

This route does not require local-client or bearer validation.

Response shape:

```json
{
  "ok": true,
  "service": "codex-wp-sidecar",
  "version": "2.1.4",
  "codexBin": "/usr/local/bin/codex"
}
```

### `GET /v1/diagnostics`

This bearer-authenticated route is called only from an explicit administrator action in WordPress. It checks Python, `codex --version`, writable storage and per-user configuration files. If the CLI check passes, it adds `codex_version` (a warning unless the installed version is 0.162.1) and starts an unauthenticated app-server in a throwaway `_diagnostics` home.

After initialization it opens an ephemeral generation thread and confirms the approval, sandbox and environment echo plus all seven disabled features (`shell_tool`, `stable_environment_tools`, `view_image`, `multi_agent`, `multi_agent_v2`, `apps`, `plugins`). A mismatch produces a failing `generation_policy` row naming the first mismatch. The process closes and the throwaway home is removed. No generation turn or account snapshot is requested. Warnings count as issues in the overall result.

### `POST /v1/login/start`

Request body:

```json
{
  "wpUserId": 123,
  "displayName": "Jane Doe",
  "email": "jane@example.com"
}
```

Only `wpUserId` is used by the current implementation. `displayName` and `email` are passed by the plugin but ignored by the sidecar.

Behavior:

1. If there is already an in-memory pending login session for that WordPress user, the sidecar returns the existing public payload instead of starting a second login.
2. Otherwise it creates a new `authSessionId`.
3. It starts a `JsonRpcSession` for that user's Codex home.
4. It calls:

```text
account/login/start { "type": "chatgptDeviceCode" }
```

5. It expects a response with:
   - `type = "chatgptDeviceCode"`
   - `loginId`
   - `verificationUrl`
   - `userCode`
6. It stores a pending login record in the process-wide `RuntimeState._login_sessions` map.
7. It starts a watcher thread that waits for `account/login/completed`.
8. It returns a public payload to the plugin.

Returned payload:

```json
{
  "authSessionId": "auth_...",
  "verificationUrl": "https://chatgpt.com/device",
  "userCode": "ABCD-EFGH",
  "status": "pending",
  "error": null
}
```

Important implementation detail:

- Pending login state lives only in memory inside the sidecar process.
- If the sidecar restarts mid-login, the pending `authSessionId` is lost.
- Persisted auth is different: once Codex writes `auth.json`, that auth survives sidecar restarts.

### `GET /v1/login/status`

Query parameters:

- `wpUserId`
- `authSessionId`

Behavior:

- Looks up the pending login session in memory.
- If the session is missing or belongs to another user, returns `200` with `status = "missing"` plus `authStored`.
- Otherwise returns the current pending or completed state.
- Adds `authStored` by checking whether the user's `auth.json` file exists.

During mixed plugin/sidecar rollouts, the WordPress plugin also tolerates the earlier `404` response that carried the same `status = "missing"` payload.

The watcher thread updates the in-memory session like this:

- on successful `account/login/completed`: `status = "completed"`
- on failure or timeout: `status = "error"` and `error = ...`

After login completion, the plugin polls this route, then separately asks the sidecar for a snapshot and persists WordPress-side connection data. If the sidecar restarted and the in-memory login session is gone, WordPress treats `status = "missing"` as a recoverable state: it tries `GET /v1/account/snapshot` and reconnects automatically when stored auth already exists.

### `GET /v1/account/snapshot`

Query parameters:

- `wpUserId`

Behavior:

1. Computes the user's Codex home.
2. Rejects the request with `409 auth_required` if `auth.json` is missing.
3. Starts a short-lived `codex app-server` session for that user.
4. Calls:
   - `account/read` with `{ "refreshToken": true }`
   - `account/rateLimits/read`
   - `model/list` with `{ "includeHidden": false }`
   - `modelProvider/capabilities/read`
5. Normalizes the returned payload into a stable HTTP response.

Returned data includes:

- normalized account data
- whether auth is stored
- normalized capability booleans for image generation, namespace tools, and web search
- a selected default model
- the visible model list
- rate limits

Important implementation detail:

- `planType` is optional and may be blank.
- A failed capability RPC preserves its cause and fails the snapshot. Missing capability fields in an otherwise successful reply normalize to `false`.
- WordPress clears stale stored account fields when a fresh snapshot omits them.
- If this route returns `409 auth_required`, WordPress clears the local connection and prompts the user to reconnect.

The plugin stores that result in its own WordPress tables so it can power:

- connector readiness
- available model selection
- site-level catalog fallback behavior

### `POST /v1/responses/text`

Request body from the plugin includes:

- `wpUserId`
- `requestId`
- `input`
- `systemInstruction`
- `model`
- `reasoningEffort`
- optionally `responseFormat`

Behavior:

1. Rejects with `409 auth_required` if `auth.json` is missing.
2. Starts a short-lived `codex app-server` session for that user.
3. Creates an ephemeral thread:

```text
thread/start { "ephemeral": true, ... }
```

4. Confirms the generation policy echo, then starts a turn with the flattened input text and optional controls.
5. Waits for turn notifications within one wall-clock deadline; on expiry attempts `turn/interrupt` and returns HTTP 504 without partial output.
6. Collects output from:
   - `item/agentMessage/delta`
   - `item/completed`
   - `turn/completed`
7. Requires a successfully completed turn; recovers text from terminal items or streamed deltas. Missing output fails. Ephemeral threads do not support history recovery, so no `thread/read` is sent.
8. Reads account and rate-limit data again after generation.
9. Returns a normalized text response.

### `POST /v1/responses/image`

Request body from the plugin includes:

- `wpUserId`
- `requestId`
- `prompt`
- `systemInstruction`

The plugin does not send the synthetic WordPress model ID as a Codex runtime model override. `codex-image` is only the WordPress AI Client model identifier.

Behavior:

1. Rejects with `409 auth_required` if `auth.json` is missing.
2. Starts a short-lived `codex app-server` session for that user.
3. Calls `modelProvider/capabilities/read` and rejects with `409 image_generation_unavailable` unless `imageGeneration` is true.
4. Creates an ephemeral thread without a `model` override and confirms its generation policy echo.
5. Starts a turn with one text input part containing the image prompt.
6. Waits for turn notifications within one wall-clock deadline; timeout interrupts the turn and fails without partial output.
7. Captures `item/completed` notifications whose `params.item.type` is `imageGeneration`.
8. Treats a non-empty `params.item.result` as deliverable image data even if the item status string is still `generating`.
9. Requires a successfully completed turn and also inspects its terminal items. Missing images fail; no ephemeral-history request is sent.
10. Reads account and rate-limit data again after generation.
11. Returns a normalized image response with `imageBase64`, `mimeType`, optional `revisedPrompt`, optional local `artifacts.savedPath`, and token usage when Codex emits it.

Returned `artifacts.savedPath` is local diagnostic metadata from Codex. It is not a public URL and the PHP request-log bridge does not store it.

### How JSON schema output is passed through

If the plugin sends:

```json
{
  "responseFormat": {
    "type": "json_schema",
    "schema": { "...": "..." }
  }
}
```

the sidecar converts that to Codex `turn/start` payload field:

```json
{
  "outputSchema": { "...": "..." }
}
```

If the final text looks like JSON, the sidecar also attempts `json.loads()` and returns the parsed object as `structuredOutput`.

### Usage accounting

The sidecar listens for `thread/tokenUsage/updated` and uses the `total` bucket for the ephemeral single-turn thread, which counts all model responses in that turn. It falls back to `last` when `total` is missing or malformed:

```json
{
  "usage": {
    "inputTokens": 12,
    "outputTokens": 18,
    "reasoningOutputTokens": 0
  }
}
```

If Codex does not emit usable token data, the sidecar returns zeroes for the same keys. Token accounting never blocks text or image delivery.

### `POST /v1/session/clear`

Request body:

```json
{
  "wpUserId": 123
}
```

Behavior:

- deletes that user's `auth.json` file if it exists
- returns `{ "ok": true }`

This is a local logout/reset. It clears the sidecar's stored auth marker for that WordPress user. The plugin then also removes its own WordPress-side pending connection data, connection row, snapshot row, and preferred model.

## What Lives In WordPress vs. What Lives In The Sidecar

The boundary is intentionally split.

### Sidecar-owned state

The sidecar owns local Codex runtime state:

- per-user Codex home directories
- persisted Codex auth files such as `auth.json`
- in-memory pending login sessions while device-code login is active

### WordPress-owned state

The plugin owns WordPress application state:

- runtime URL and bearer token settings
- temporary pending login metadata in user meta
- connection rows in `{$wpdb->prefix}codex_provider_connections`
- snapshot rows in `{$wpdb->prefix}codex_provider_connection_snapshots`

That means:

- the sidecar is the source of truth for actual local Codex auth
- WordPress is the source of truth for plugin UI state, connection metadata, and cached snapshot data

## How The Plugin Finds And Uses The Sidecar

The plugin resolves the sidecar location and secret in [src/Runtime/Settings.php](../src/Runtime/Settings.php).

Resolution order for settings is:

1. PHP constants
2. environment variables
3. a shared env file, default `/etc/codex-wp-sidecar.env`
4. saved WordPress options

For the runtime base URL specifically, the plugin supports either:

- explicit `CODEX_WP_RUNTIME_BASE_URL`
- or derived `http://<CODEX_WP_HOST>:<CODEX_WP_PORT>`

The systemd service should use `/etc/codex-wp-sidecar.env` with:

- `CODEX_WP_RUNTIME_BASE_URL`
- `CODEX_WP_BEARER_TOKEN`
- host, port, storage root, and path-related values

If PHP can read that file, the plugin auto-detects the runtime URL and bearer token and treats those settings as externally managed.

## systemd Installation

Use [sidecar/systemd/codex-wp-sidecar.service](./systemd/codex-wp-sidecar.service) as a template and replace the placeholder plugin path with the real installed plugin directory. The service runs:

```text
python3 <plugin-dir>/sidecar/app/main.py
```

Create the storage root if needed, write `/etc/codex-wp-sidecar.env`, then enable and start the service. The static unit template includes placeholder paths and must be edited before use.

## Failure Modes And Their Effects

### Sidecar down

- `GET /healthz` fails from WordPress.
- Plugin readiness checks show the runtime as unavailable.
- Snapshot and generation requests fail before any Codex work starts.

### Bearer token mismatch

- WordPress receives `401 unauthorized`.
- The sidecar does not process the request.

### Pending login lost due to restart

- WordPress still has a pending auth session id in user meta.
- The sidecar no longer has the in-memory login session.
- `GET /v1/login/status` returns `200` with `status = "missing"`.
- WordPress tries `GET /v1/account/snapshot` before asking the user to reconnect.
- If `auth.json` already exists and the snapshot succeeds, WordPress restores the local connection automatically.
- If the snapshot returns `409 auth_required`, WordPress clears the stale local connection and the user starts the connect flow again.

### `codex app-server` transport failure

- `JsonRpcSession` records a transport error.
- waiting callers are released
- the HTTP layer returns `502` or `504` style errors depending on whether the failure was a runtime exception or timeout

### Disconnect while sidecar unreachable

The plugin still performs local WordPress cleanup even if `POST /v1/session/clear` cannot reach the sidecar. That means WordPress can forget the connection while the sidecar's `auth.json` remains on disk until the sidecar becomes reachable and the user clears it again or deletes it manually.

## Practical Summary

The sidecar is intentionally narrow:

- It is a localhost HTTP wrapper, not a public API service.
- It does not try to own the full plugin state model.
- It starts `codex app-server` with a per-user home directory so auth stays isolated.
- It keeps device-code login state in memory only while login is active.
- It uses short-lived Codex subprocess sessions for snapshot reads and text generation.
- It lets WordPress cache connection and model metadata in normal WordPress storage while leaving real auth on disk outside WordPress.
