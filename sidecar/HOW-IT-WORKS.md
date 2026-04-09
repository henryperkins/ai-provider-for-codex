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
- `CODEX_RUNTIME_TURN_TIMEOUT`: generation turn timeout, default `300`
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

When the sidecar starts a `codex app-server` subprocess for a user, it sets both:

- `CODEX_HOME=<that user's directory>`
- `HOME=<that user's directory>`

That causes `codex app-server` to read and write auth and runtime files inside that per-user directory instead of sharing state across users.

The sidecar itself only explicitly knows about `auth.json`:

- `auth.json` is treated as the marker that a user has stored auth.
- `POST /v1/session/clear` deletes `auth.json`.

The existing design documents also reserve room for other Codex-managed files in the same directory, such as `config.toml`, `history.jsonl`, and `logs/`, but those are created and managed by Codex rather than by the sidecar.

## JSON-RPC Bridge To `codex app-server`

The core adapter is `JsonRpcSession`.

### Session startup

When a session starts, it:

1. Creates the user directory if needed.
2. Spawns `codex app-server`.
3. Connects to its stdin/stdout.
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
- `thread/start`
- `turn/start`
- `thread/read`

It uses notification waiting for long-running async events like:

- login completion
- streaming text generation deltas
- turn completion

### Process lifetime

There are two distinct session lifetimes:

- Short-lived sessions for snapshot and generation requests.
- A longer-lived in-memory session for a pending device-code login.

For short-lived work, the sidecar uses `app_server_session(...)`, which starts a subprocess, does the work, and closes it immediately afterward.

For device-code login, the sidecar keeps the subprocess alive in memory until the login completes or errors, because it needs to wait for an `account/login/completed` notification.

## HTTP API

The sidecar exposes five routes plus the `ping` alias.

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
  "version": "0.1.0",
  "codexBin": "/usr/local/bin/codex"
}
```

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
   - `account/read` with `{ "refresh": true }`
   - `account/rateLimits/read`
   - `model/list` with `{ "includeHidden": false }`
5. Normalizes the returned payload into a stable HTTP response.

Returned data includes:

- normalized account data
- whether auth is stored
- a selected default model
- the visible model list
- rate limits

Important implementation detail:

- `planType` is optional and may be blank.
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

4. Starts a turn with the flattened input text and optional controls.
5. Waits for notifications associated with the turn.
6. Collects output from:
   - `item/agentMessage/delta`
   - `item/completed`
   - `turn/completed`
7. If no direct text was captured, falls back to `thread/read` and extracts the final agent message from the completed turn.
8. Reads account and rate-limit data again after generation.
9. Returns a normalized text response.

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

The current implementation always returns token usage as zeroes:

```json
{
  "usage": {
    "inputTokens": 0,
    "outputTokens": 0
  }
}
```

So the sidecar currently forwards content, account info, and rate limits, but it does not compute or expose actual token counts.

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

The systemd installer writes `/etc/codex-wp-sidecar.env` with:

- `CODEX_WP_RUNTIME_BASE_URL`
- `CODEX_WP_BEARER_TOKEN`
- host, port, storage root, and path-related values

If PHP can read that file, the plugin auto-detects the runtime URL and bearer token and treats those settings as externally managed.

## systemd Installation

The helper at [sidecar/scripts/install-systemd.sh](./scripts/install-systemd.sh):

1. Resolves the plugin directory, Python binary, Codex binary, and runtime path.
2. Generates a bearer token if one was not supplied.
3. Writes a shared env file, default `/etc/codex-wp-sidecar.env`.
4. Writes a systemd unit that runs:

```text
python3 <plugin-dir>/sidecar/app/main.py
```

5. Creates the storage root if needed.
6. Optionally syncs the runtime URL and bearer token into WordPress options with WP-CLI.
7. Enables and starts or restarts the service.
8. Probes `/healthz`.

The static unit template in [sidecar/systemd/codex-wp-sidecar.service](./systemd/codex-wp-sidecar.service) is only a placeholder. The installer writes the real unit file with the actual plugin path.

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
