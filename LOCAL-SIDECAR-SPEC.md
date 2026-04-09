# Local Sidecar Implementation Spec

## Purpose

This document defines the runtime-native architecture for `ai-provider-for-codex`.

Goals:

- ChatGPT-managed Codex login and billing
- per-user account isolation
- WordPress AI Client compatibility
- a first-party WordPress.org-style provider UX

## Product Constraints

The plugin should behave like an official WordPress AI provider plugin wherever possible.

That means:

- provider registration stays simple and conventional
- the Connectors screen remains the main discovery and action surface
- settings look like provider settings, not service onboarding
- user account linking stays lightweight and in wp-admin
- the plugin does not expose installation exchange, site registration, site secrets, or external connect pages

The product must continue to use Codex and ChatGPT-managed billing rather than direct API billing.

Each WordPress user must connect their own account. Shared auth for all users is out of scope.

## End-State Architecture

Runtime flow:

`WordPress AI Client -> AI Provider for Codex -> localhost sidecar -> codex app-server -> ChatGPT/Codex`

Core components:

- the WordPress plugin
- a localhost sidecar service
- `codex app-server` over stdio
- per-user auth storage on disk
- device-code login
- a shared bearer token between WordPress and the sidecar

## Non-Goals

This design does not try to:

- support generic shared hosting with no daemon or container support
- support a single shared Codex account for all WordPress users
- let the browser talk directly to the sidecar
- store sidecar auth state in WordPress options or user meta
- add a public callback-based login flow

## Current Implementation Status

- Phase 1 functional cutover is complete.
- Phase 2 terminology cleanup is complete.
- Active plugin code is runtime-only.
- Schema version `4` removes the obsolete callback-state table and the unused legacy connection metadata column.

## Core Design Decisions

### 1. Local Runtime Required

Codex auth state must live in a local runtime on the WordPress host.

The sidecar is the supported runtime boundary for:

- auth storage
- device-code login orchestration
- account snapshot reads
- text generation requests

### 2. Device Code Login

The plugin uses ChatGPT device-code login as the default server-side auth flow.

Reasons:

- works for remotely hosted WordPress sites
- avoids redirect and callback complexity
- fits a lightweight in-admin connection flow

### 3. Per-User Isolation

Every WordPress user gets isolated Codex auth storage.

This is required for:

- billing attribution
- account separation
- avoiding shared auth corruption
- preserving the product model

### 4. Provider-Style UX

The plugin should present as a normal provider plugin with one extra local runtime requirement.

The runtime complexity stays behind:

- one settings page
- one user connection page
- the Connectors screen

## Naming and Terminology

Preferred terms:

- runtime
- local runtime
- Codex runtime
- connect account
- account snapshot
- runtime status

Avoid terms that imply external onboarding or hosted control-plane setup.

## Sidecar Specification

### Runtime Form

Current runtime shape:

- Python HTTP service
- binds to `127.0.0.1` only
- wraps `codex app-server` over stdio
- stores auth state on disk
- requires a shared bearer token from the WordPress plugin

### Default Network Configuration

- host: `127.0.0.1`
- port: `4317`
- base URL: `http://127.0.0.1:4317`

Port can be configurable, but `4317` is the documented default.

### Runtime Auth Between Plugin and Sidecar

The sidecar requires a bearer token for every request except `GET /healthz`.

Required behavior:

- reject missing token with `401`
- reject invalid token with `401`
- compare tokens in constant time where practical
- never accept requests from non-local interfaces by default

### Storage Layout

Default storage root:

- `/var/lib/codex-wp`

Per-user storage layout:

- `/var/lib/codex-wp/users/<wp_user_id>/auth.json`
- `/var/lib/codex-wp/users/<wp_user_id>/config.toml`
- `/var/lib/codex-wp/users/<wp_user_id>/history.jsonl`
- `/var/lib/codex-wp/users/<wp_user_id>/logs/`

If multisite needs stronger isolation later, a site-scoped storage prefix can be introduced.

### File Permissions

The sidecar must ensure:

- data is created outside webroot
- auth files use owner-only permissions where supported
- directories use restrictive permissions where supported

## Sidecar API Contract

All sidecar routes are localhost-only and plugin-authenticated.

### `GET /healthz`

Purpose:

- runtime availability check
- used by plugin settings and readiness checks

Response:

```json
{
  "ok": true,
  "service": "codex-wp-sidecar",
  "version": "0.1.0",
  "codexBin": "/usr/local/bin/codex"
}
```

### `POST /v1/login/start`

Purpose:

- start ChatGPT device-code login for one WordPress user

Request:

```json
{
  "wpUserId": 123,
  "displayName": "Jane Doe",
  "email": "jane@example.com"
}
```

Response:

```json
{
  "authSessionId": "auth_abc123",
  "status": "pending",
  "verificationUrl": "https://chatgpt.com/device",
  "userCode": "ABCD-EFGH"
}
```

Behavior:

- initializes or reuses a login session for the user
- starts Codex `chatgptDeviceCode` login
- returns device-code details immediately

### `GET /v1/login/status?wpUserId=<id>&authSessionId=<id>`

Purpose:

- poll login completion

Response while pending:

```json
{
  "authSessionId": "auth_abc123",
  "status": "pending",
  "verificationUrl": "https://chatgpt.com/device",
  "userCode": "ABCD-EFGH",
  "error": null
}
```

Response when completed:

```json
{
  "authSessionId": "auth_abc123",
  "status": "completed",
  "verificationUrl": null,
  "userCode": null,
  "error": null,
  "authStored": true
}
```

Response when the sidecar lost the in-memory login session:

```json
{
  "authSessionId": "auth_abc123",
  "status": "missing",
  "verificationUrl": null,
  "userCode": null,
  "error": "Login session was not found in the local runtime.",
  "authStored": true
}
```

Compatibility note:

- The current sidecar returns this payload with HTTP `200`.
- During mixed rollouts, WordPress should also tolerate the previous HTTP `404` form of the same `status = "missing"` payload.

### `GET /v1/account/snapshot?wpUserId=<id>`

Purpose:

- fetch current account, model, and rate-limit state for one user

Response:

```json
{
  "account": {
    "email": "jane@example.com",
    "planType": "plus",
    "authMode": "chatgpt",
    "type": "chatgpt"
  },
  "authStored": true,
  "defaultModel": "gpt-5-codex",
  "models": [
    {
      "id": "gpt-5-codex",
      "displayName": "GPT-5 Codex",
      "model": "gpt-5-codex",
      "inputModalities": ["text"],
      "supportedReasoningEfforts": ["minimal", "low", "medium", "high"],
      "isDefault": true
    }
  ],
  "rateLimits": {}
}
```

Notes:

- `planType` may be `null` or an empty string.
- WordPress treats this snapshot as the source of truth for displayed billing-plan information and clears stale local values when a fresh snapshot omits them.
- If this route returns `409 auth_required`, WordPress clears the local connection state and prompts the user to reconnect.

### `POST /v1/responses/text`

Purpose:

- run text generation for one user

Request:

```json
{
  "wpUserId": 123,
  "model": "gpt-5-codex",
  "input": "Write a short release note.",
  "systemInstruction": "Be concise.",
  "reasoningEffort": "medium",
  "requestId": "req_123",
  "responseFormat": null
}
```

Response:

```json
{
  "requestId": "req_123",
  "model": "gpt-5-codex",
  "outputText": "Added a local Codex runtime and simplified the provider flow.",
  "structuredOutput": null,
  "finishReason": "stop",
  "usage": {
    "inputTokens": 12,
    "outputTokens": 18,
    "reasoningTokens": 0
  },
  "account": {
    "email": "jane@example.com",
    "planType": "plus",
    "authMode": "chatgpt",
    "type": "chatgpt"
  },
  "rateLimits": {}
}
```

### `POST /v1/session/clear`

Purpose:

- clear a user session and delete local auth state

Request:

```json
{
  "wpUserId": 123
}
```

Response:

```json
{
  "ok": true
}
```

## Sidecar Error Contract

All sidecar failures should be normalized to:

```json
{
  "error": {
    "code": "runtime_unavailable",
    "message": "Codex runtime is not available."
  }
}
```

Suggested error codes:

- `unauthorized`
- `invalid_request`
- `missing_user`
- `auth_required`
- `login_pending`
- `runtime_unavailable`
- `runtime_error`
- `invalid_model`
- `request_timeout`

## WordPress Plugin Specification

### Plugin UX Goals

The plugin aligns with first-party provider plugins released on WordPress.org.

Desired UX traits:

- simple provider registration
- Connectors-friendly discovery and status
- standard settings forms
- no custom external onboarding steps
- no hosted control-plane concepts in the UI

## Admin Screens

### 1. Connectors Screen

The Connectors card remains the primary entry point.

States:

- runtime not configured: show `Set up`
- runtime configured but unreachable: show `Set up`
- runtime reachable and user not connected: show `Connect`
- login pending: show `Continue connecting`
- connected: show `Connected` and `Manage`
- expired or revoked: show `Reconnect`

Action behavior:

- `Set up` links to plugin settings
- `Connect` starts an in-admin flow backed by WordPress REST
- `Manage` links to the user connection page

### 2. Site Settings Page

The site settings page is a provider settings screen.

Sections:

- Runtime
- Models
- Advanced

Runtime section fields:

- `Runtime URL`
- `Runtime bearer token`

Models section fields:

- `Fallback models`
- one model per line
- default list begins with `gpt-5-codex`

Optional advanced fields:

- `Force ChatGPT login`
- `Allowed ChatGPT workspace ID`

Settings page status cards:

- runtime status
- fallback models count
- last successful runtime check

### 3. User Connection Page

The user connection page manages the device-code flow.

Required page sections:

- current status
- connected account information
- available models and selected model
- connect or reconnect action
- disconnect action
- refresh snapshot action

When a login is pending, the page displays:

- verification URL
- user code
- copy-friendly code presentation
- polling status text

## WordPress REST Contract

Browser JavaScript must only call WordPress REST endpoints.

The browser must not talk directly to the sidecar.

### `POST /wp-json/codex-provider/v1/connect/start`

Starts the device-code flow for the current user.

### `GET /wp-json/codex-provider/v1/connect/status`

Returns pending or connected state for the current user.

### `POST /wp-json/codex-provider/v1/connect/disconnect`

Disconnects the current user and clears local runtime state.

### `POST /wp-json/codex-provider/v1/connect/refresh`

Refreshes the current user's snapshot from the local runtime.

### `GET /wp-json/codex-provider/v1/status`

Response shape:

```json
{
  "ready": true,
  "reason": "ready",
  "runtime": {
    "status": "healthy",
    "checked_at": "2026-04-03 16:00:00",
    "error": ""
  },
  "runtimeConfigured": true,
  "connection": {},
  "snapshot": {},
  "catalog": {}
}
```

## WordPress Data Model

### Connections Table

Fields:

- `wp_user_id`
- `connection_id`
- `status`
- `account_email`
- `plan_type`
- `auth_mode`
- `session_expires_at`
- `last_synced_at`
- timestamps

These fields mirror the latest successful account snapshot and must be clearable when the runtime stops returning a value such as `planType`.

### Snapshots Table

Fields:

- `connection_id`
- `models_json`
- `default_model`
- `reasoning_effort`
- `rate_limits_json`
- `readiness_status`
- `checked_at`
- timestamps

### User Meta

Used for:

- pending auth session state
- preferred model
- dismissible notices

### Removed Storage

The old short-lived callback-state table is not part of the runtime design.

- `codex_provider_auth_states`

## Runtime Status Model

Canonical readiness reasons:

- `ready`
- `runtime_unconfigured`
- `runtime_unreachable`
- `user_unlinked`
- `connection_expired`
- `login_pending`

Health statuses:

- `healthy`
- `unreachable`
- `unknown`

## WordPress Service Layer

### `Runtime\Settings`

Responsibilities:

- option names
- option sanitization
- runtime URL normalization
- bearer token storage
- allowed model storage
- optional enterprise configuration values
- runtime configuration completeness checks

Current option names:

- `codex_runtime_base_url`
- `codex_runtime_bearer_token`
- `codex_runtime_allowed_models`

### `Runtime\Client`

Responsibilities:

- local HTTP requests to the sidecar
- bearer token headers
- request timeout handling
- health recording
- normalized runtime exceptions

### `Runtime\HealthMonitor`

Responsibilities:

- health checks against `GET /healthz`
- transient-backed runtime status caching
- normalized health payloads for admin and REST consumers

### `Runtime\ResponseMapper`

Responsibilities:

- map sidecar account snapshots into connection and snapshot repositories
- map text responses into AI Client DTOs

### `Auth\PendingConnectionRepository`

Responsibilities:

- persist pending device-code login state in user meta
- clear pending state after completion or disconnect

### `Auth\ConnectionService`

Responsibilities:

- start local connect flow
- poll connect status
- refresh snapshot
- disconnect user
- persist connection and snapshot rows

Required public methods:

- `start_connect( int $wp_user_id ): array`
- `poll_connect_status( int $wp_user_id ): array`
- `refresh_snapshot( int $wp_user_id ): array`
- `disconnect( int $wp_user_id ): void`

### `Provider\SupportChecks`

Responsibilities:

- assemble readiness payloads for the current user
- report `runtimeConfigured`
- expose connection, snapshot, and catalog state consistently

## Connect Flow

### Start Connect

1. User clicks `Connect` from Connectors or the user page.
2. Browser calls `POST /wp-json/codex-provider/v1/connect/start`.
3. WordPress calls sidecar `POST /v1/login/start`.
4. WordPress stores pending state in user meta.
5. WordPress returns the device-code payload to the browser.
6. Browser renders verification URL and user code inline.

### Poll Connect Status

1. Browser calls `GET /wp-json/codex-provider/v1/connect/status`.
2. WordPress calls sidecar `GET /v1/login/status`.
3. If still pending, WordPress returns pending state.
4. If completed, WordPress calls sidecar `GET /v1/account/snapshot`.
5. If `status = "missing"`, WordPress also tries `GET /v1/account/snapshot` before asking the user to reconnect.
6. If the snapshot succeeds, WordPress persists connection and snapshot data and returns connected state.
7. If the snapshot returns `auth_required`, WordPress clears the stale local connection and returns an unlinked state.

### Disconnect

1. User clicks `Disconnect`.
2. WordPress calls sidecar `POST /v1/session/clear`.
3. WordPress deletes the local snapshot row.
4. WordPress deletes the local connection row.
5. WordPress clears preferred-model user meta.

## Provider Model Behavior

Model catalog behavior:

- user snapshot models are preferred when available
- configured fallback models are used before a user is linked
- user-selected model remains a user meta preference

This preserves a familiar provider experience while supporting live per-user model discovery.

## Security Requirements

### Plugin

- sanitize all settings and REST inputs
- require `read` for user connection actions
- require `manage_options` for runtime settings
- never store `auth.json` in WordPress options or user meta
- never expose the sidecar bearer token to non-admin users unless strictly required by a server-side call path

### Sidecar

- localhost-only binding by default
- bearer token required
- auth files outside webroot
- restrictive filesystem permissions
- no public callback endpoints
- no externally routable default config

## Packaging and Operations

The plugin should not install or manage OS services directly.

Expected deployment patterns:

- systemd service on VPS or dedicated hosts
- Docker container on container-friendly hosts

The sidecar is installed separately via documented systemd or Docker instructions.

## Acceptance Criteria

The implementation is complete when all of the following are true:

- the plugin uses a localhost sidecar only
- the plugin does not use installation exchange, site registration, or public callback concepts
- a logged-in WordPress user can connect a ChatGPT or Codex account entirely from wp-admin using device code
- per-user Codex auth is stored only in the sidecar storage directory
- the provider can generate text without direct API billing
- the Connectors and settings experience feels like a standard provider plugin
- browser calls stay pointed at WordPress REST, not the sidecar directly

## Optional Future Enhancements

- forced ChatGPT login support
- workspace restriction support
- multisite-aware storage if needed
- packaging automation and release helpers

## Implementation Principles

- prefer the smallest correct changes
- preserve existing repository structure when it still fits the runtime design
- avoid adding a heavy internal service framework around the sidecar
- avoid over-designing for unsupported hosting targets
- keep browser calls pointed at WordPress REST, not the sidecar directly
