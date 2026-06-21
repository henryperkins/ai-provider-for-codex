# Codex Account Connect UX Design

**Date:** 2026-05-22

**Goal:** Make account linking feel immediate from Settings > Connectors and the per-user Codex Provider page. When a user clicks Connect or Reconnect, WordPress should start the Codex device-code login, attempt to copy the returned user code, open the returned verification URL in a new browser context, poll for completion, and show clear success or failure without requiring a manual page refresh.

**Status:** Design spec. No implementation has been performed.

## Current System

The existing backend already exposes the primitives needed for this flow.

- `POST /wp-json/htperkins-aipfc/v1/connect/start` starts a local runtime login for the current user and returns `authSessionId`, `verificationUrl`, `userCode`, and `status`.
- `GET /wp-json/htperkins-aipfc/v1/connect/status` polls the pending login and converts a completed runtime login into a persisted WordPress connection and catalog snapshot.
- `GET /wp-json/htperkins-aipfc/v1/status` returns provider readiness, runtime health, existing connection state, catalog data, and pending connection state.
- The sidecar starts Codex app-server login with `account/login/start` using `chatgptDeviceCode` and watches for `account/login/completed`.

The gap is UI orchestration. `assets/connectors.js` currently starts a login from Settings > Connectors and redirects the user to the connection page. `UserConnectionPage` currently renders a server-side manual flow where the user opens the verification page and clicks Check connection status.

## Source Of Truth

OpenAI's Codex app-server device-code flow returns a `verificationUrl` and `userCode`; the frontend is responsible for showing them and waiting for completion notifications through the runtime/session layer. The implementation must use the returned `verificationUrl` instead of hard-coding `chatgpt.com`, because current docs show `https://auth.openai.com/codex/device` as the example verification URL.

Reference: `https://developers.openai.com/codex/app-server#3b-log-in-with-chatgpt-device-code-flow`

## Browser Constraints

This cannot be a perfectly identical experience on all devices.

- Desktop browsers usually allow a popup/new tab if it is opened synchronously from the user's click handler. If JavaScript waits for the REST response before calling `window.open`, popup blockers may reject it.
- Mobile browsers often turn popups into full tabs, may suspend JavaScript while the admin tab is backgrounded, and may not preserve focus events consistently.
- Clipboard writes require a secure context and user activation. The UI may attempt `navigator.clipboard.writeText(userCode)`, but it must not assume success.
- The user code must always be visible in the WordPress UI with an explicit Copy button fallback.

## Recommended Approach

Use progressive enhancement with a shared JavaScript connection controller.

1. On click, synchronously open a placeholder popup with about:blank.
2. Start the login through WordPress REST.
3. When the REST response arrives, attempt to copy `userCode`.
4. Clear the placeholder popup's opener where the browser permits it, then navigate the popup to the returned `verificationUrl`.
5. Render an in-admin pending state with the user code, copy status, verification-link fallback, and waiting indicator.
6. Poll `connect/status` until the login is connected, failed, missing, or timed out.
7. Reconcile the status back through `status` so the connector card and user page display the same final state.

This is the best fit because it preserves the existing backend contract, works within popup-blocker rules, degrades cleanly on mobile, and keeps all browser traffic routed through WordPress REST rather than exposing the sidecar to the browser.

## Alternatives Considered

### Redirect To User Connection Page First

The connector could keep redirecting users to the per-user page after `connect/start`, then the page could open the verification URL and poll. This is simpler but weaker: the popup is no longer directly tied to the original Connect click, so popup blockers are more likely, and the connector does not provide immediate visual feedback.

### Modal-Only Flow

The connector and user page could show a modal with the code and a verification link, but not open a popup automatically. This is reliable across browsers but does not meet the desired "click Connect and immediately open verification" behavior.

### Full OAuth Callback

A browser callback could replace device-code login. This is out of scope because the Codex app-server docs explicitly position device-code login for clients that own the sign-in ceremony or where browser callback is brittle, and the current plugin architecture already relies on a local sidecar and per-user device-code login.

## UX Requirements

### Settings > Connectors

When the runtime is configured and the current user is unlinked or expired, the Connect/Reconnect button should:

- open a blank browser context synchronously from the click handler;
- disable the button while login starts;
- call `connect/start`;
- navigate the opened browser context to `verificationUrl`;
- attempt to copy `userCode`;
- replace the connector action area with a compact pending state;
- poll `connect/status`;
- show Connected and Manage when the poll returns `connected`;
- reconcile provider status when the poll returns `error` so retryable post-login sync failures can show Retry account sync instead of discarding the completed login;
- show a clear terminal error state with Review error or Try again when the reconciled state is `login_failed` or the poll returns unrecoverable `missing`;
- leave a visible manual verification link if popup navigation failed or was blocked.

The connector card should not require redirecting to the user connection page for the normal happy path. The Manage link remains available after connection and for detailed account/model management.

### Per-User Codex Provider Page

The user page should become the full connection console. It should support the same start, copy, open, and poll flow, but with more room for details:

- status row updates without full-page refresh;
- visible device code while pending;
- Copy button with success/failure text;
- Open verification page button;
- automatic polling text such as "Waiting for ChatGPT approval";
- connected confirmation with account email, plan type, and selected model after success;
- retryable sync state with Retry account sync when the device-code login completed but snapshot/catalog refresh failed;
- failed/missing state with plain retry guidance for terminal login failures or lost sessions;
- disconnect and refresh actions preserved.

Existing server-rendered controls should remain as no-JavaScript fallbacks.

### Mobile Behavior

Mobile should use the same flow, with copy/open/poll attempted optimistically. The UI must assume that the admin page may pause while the user is in the verification tab.

Required mobile behaviors:

- continue to show the code before and after tab switches;
- resume polling on `visibilitychange`, `focus`, and `pageshow`;
- avoid claiming the code was copied unless the clipboard promise resolves;
- show "Return to this tab after approving" copy while pending;
- keep a manual "Check status" fallback.

## JavaScript Architecture

Add a small shared admin connection controller rather than duplicating polling logic inside the connector card and the PHP-rendered user page.

Recommended file layout:

- `assets/connection-flow.js`: shared controller for start, popup handoff, clipboard attempt, polling, status normalization, and custom DOM events.
- `assets/connectors.js`: import or reuse the shared controller for the Settings > Connectors card.
- `src/Admin/UserConnectionPage.php`: enqueue the shared script on the user page and render a data/config container for progressive enhancement.
- `src/Admin/ConnectorsIntegration.php`: enqueue/configure the shared script for the connector screen if script-module constraints make sharing direct imports awkward.

The shared controller should expose a small interface:

```js
createCodexConnectionFlow( {
	startUrl,
	statusUrl,
	providerStatusUrl,
	restNonce,
	onStateChange,
	onError,
	now,
	setTimeout,
	clearTimeout,
	windowObject,
	clipboard,
} )
```

The injected browser/time dependencies make the flow testable without relying on real popup or clipboard APIs.

## Polling Rules

Polling should be bounded and explicit.

- Start polling immediately after a successful `connect/start`.
- Poll every 2 seconds for the first 30 seconds.
- Poll every 5 seconds after that.
- Stop after 10 minutes and show a timeout state that keeps the code visible and offers manual status check/retry.
- Stop immediately on `connected`, `error`, or `missing`.
- Resume a still-pending poll when the page becomes visible or receives focus.

The status controller should keep using `connect/status` for pending login sessions. On `error`, the frontend should make one passive `/status` read to decide whether the error is a terminal `login_failed` state or a retryable `login_pending` + completed-pending sync failure. Normal passive reads of `/status` must not trigger live account snapshot refreshes; this preserves the current separation where only the active login-status poll completes the connection.

## Popup And Clipboard Details

The click handler should open the browser context before the async REST request and keep a usable handle for later navigation:

```js
const authWindow = window.open( 'about:blank', 'codex-provider-auth' );
```

Do not use `noopener` or `noreferrer` in the placeholder `window.open()` feature string. Chromium returns `null` for that pattern, which makes later `authWindow.location.href = verificationUrl` impossible even though the browser may still create a tab. The manual fallback anchor must still use `rel="noopener noreferrer"`.

After `connect/start` returns:

- if `authWindow` exists, attempt `authWindow.opener = null`, then set `authWindow.location.href = verificationUrl`;
- if the popup is blocked or closed, render a manual Open verification page button;
- attempt `navigator.clipboard.writeText(userCode)` only after the user click path has initiated the flow;
- store clipboard result as `copied`, `failed`, or `unsupported`;
- never hide the code based on clipboard success.

If a browser refuses opener clearing, navigation, or exposes no popup handle, the UI should continue with the code and manual verification link. The final implementation should verify the placeholder-popup behavior in Chromium and at least one mobile-class browser profile if available.

## State Model

The frontend should normalize all UI states to a compact state machine:

- `idle`: no active pending login.
- `starting`: REST start request in flight.
- `pending`: login started, code available, polling active.
- `copy_succeeded`: sub-state of `pending`.
- `copy_failed`: sub-state of `pending`.
- `popup_blocked`: sub-state of `pending`.
- `connected`: WordPress persisted the connection and refreshed catalog.
- `sync_retry`: device-code login completed, but WordPress could not refresh the account snapshot/catalog yet; keep the pending session marker and show the existing Retry account sync path.
- `failed`: REST start failed or the runtime returned a terminal login error.
- `missing`: runtime lost the login session and no recoverable auth snapshot exists.
- `timed_out`: browser stopped automatic polling after the configured limit.

The PHP/server-side readiness reasons remain unchanged: `user_unlinked`, `login_pending`, `login_failed`, `connection_expired`, `runtime_unconfigured`, `runtime_unreachable`, and `ready`. The frontend derives `sync_retry` from `reason=login_pending` with `pendingConnection.status=completed`; it must not invent a new stored readiness reason for that state.

## Accessibility And Copy

The enhanced UI should use WordPress admin component conventions where available and plain semantic HTML on the user page.

- Use `aria-live="polite"` for pending/success/failure status text.
- Keep the code in selectable text and a button-controlled copy action.
- Do not rely on color alone; keep text labels for connected, pending, failed, and timed out.
- Buttons should remain keyboard accessible.
- The popup fallback link must be a normal anchor with `target="_blank"` and `rel="noopener noreferrer"`.

Suggested user-facing text:

- Pending: "Waiting for ChatGPT approval..."
- Copied: "Code copied."
- Copy failed: "Copy did not work in this browser. Select the code below."
- Popup blocked: "Your browser blocked the verification tab. Open it manually."
- Connected: "Your Codex account is connected."
- Sync retry: "Your login was approved, but WordPress could not sync your Codex account yet."
- Missing: "The local runtime no longer has this login session. Start again to get a fresh code."
- Timed out: "Still waiting. You can check again or start over."

## Error Handling

The UI must preserve enough detail for the user to recover.

- REST start failure: close the blank popup if possible, re-enable Connect, show the REST error message.
- Popup blocked: continue with the code and manual verification link; keep polling only if login start succeeded.
- Clipboard failure: show fallback copy text; do not fail the login.
- Poll network failure: retry with the next interval up to the timeout limit; show transient "still checking" text.
- `error` status from `connect/status`: stop polling, then fetch `/status` before rendering the final action. If `/status` reports `reason=login_pending` with `pendingConnection.status=completed`, render the retryable Retry account sync state and preserve the stored error. If `/status` reports `reason=login_failed`, render the terminal runtime error and show Start connection again.
- `missing` status: stop polling, explain that the runtime lost the session, show Start connection again.
- post-login snapshot/catalog sync failure: treat as retryable, keep the existing "Retry account sync" path, and do not start a fresh device-code login unless the user explicitly disconnects or starts over.

## PHP Changes

Expected implementation changes:

- Add an enqueue path for the user connection page script in `UserConnectionPage::register_page()` or a new enqueue method bound to `admin_enqueue_scripts`.
- Add page-local config containing REST URLs, nonce, current pending state, and provider status URL.
- Keep existing nonce-protected GET actions for no-JavaScript fallback.
- Update the help text that currently says users return and click Check connection status; with JavaScript, the page checks automatically, but manual fallback remains.
- Avoid adding new database tables or options.
- Avoid changing sidecar authentication or exposing the sidecar directly to the browser.

## Verification Plan

### Static And Existing Repo Verification

Run:

```bash
bash scripts/verify.sh
```

Expected: existing PHP lint, JavaScript module syntax checks, controller tests, and WP-CLI verification pass.

### PHP Verification Additions

Extend `scripts/verify.php` to assert:

- `UserConnectionPage` exposes/enqueues the connection-flow asset on its admin screen.
- `ConnectorsIntegration::script_module_data()` or equivalent config includes `connectStatusUrl`/`connectStatusPath` and `providerStatusUrl`/`providerStatusPath` if new names are introduced.
- Existing REST routes remain registered.
- Pending connection state with `verificationUrl` and `userCode` still renders in the no-JavaScript user page fallback.
- Retryable completed pending state still renders Retry account sync and preserves the stored sync error.

### JavaScript Verification Additions

Add lightweight Node-checkable JavaScript tests for the shared controller. The repo does not currently need a package-level test harness for this; a small `node --test` file is enough because the controller accepts injected browser, clipboard, fetch, and timer dependencies. `scripts/verify.sh` should parse both JavaScript files and run the controller tests.

The connection controller should be testable for:

- opens placeholder popup before awaiting `connect/start`;
- uses a placeholder popup without `noopener`/`noreferrer`, then attempts to clear `authWindow.opener` before navigation;
- navigates the popup to `verificationUrl` after start succeeds;
- calls clipboard write with `userCode`;
- reports copy failure without failing connection;
- polls until connected and then stops;
- reconciles `connect/status` `error` through `/status` and maps completed pending sessions to Retry account sync instead of Start connection again;
- resumes polling on focus/visibility events;
- stops on error/missing/timeout.

Suggested verification commands after implementation:

```bash
node --input-type=module --check < assets/connection-flow.js
node --test assets/connection-flow.test.mjs
```

### Manual Browser Verification

Desktop:

1. Open Settings > Connectors.
2. Click Connect.
3. Confirm a verification tab opens immediately.
4. Confirm the connector card shows pending state and code.
5. Complete the ChatGPT/Codex verification.
6. Return to WordPress and confirm the card changes to Connected without manual refresh.

Mobile or mobile emulation:

1. Open the per-user Codex Provider page.
2. Tap Connect Codex account.
3. Confirm the verification page opens in a new tab or browser context.
4. Return to the WordPress tab after approval.
5. Confirm polling resumes and the page shows connected confirmation.

## Non-Goals

- Do not replace device-code login with an OAuth callback.
- Do not hard-code a ChatGPT or OpenAI verification URL.
- Do not expose the local sidecar to browser JavaScript.
- Do not remove no-JavaScript fallback actions.
- Do not change account storage, per-user auth isolation, or sidecar filesystem layout.
- Do not add a shared site-wide Codex account.

## Acceptance Criteria

- Connect/Reconnect from Settings > Connectors starts login, opens the returned verification URL, attempts code copy, and updates the card without redirecting on the happy path.
- Connect/Reconnect from the per-user page uses the same enhanced flow and shows detailed progress.
- Desktop popup behavior works when triggered from the button click, including the placeholder-popup handle used for later navigation.
- Mobile users can complete the flow even if the browser switches tabs and suspends the admin page.
- Clipboard success is accurately reported, and clipboard failure has a visible fallback.
- Automatic polling detects success, terminal failure, retryable sync failure, missing session, and timeout.
- Existing no-JavaScript actions still work.
- `bash scripts/verify.sh` passes after implementation.

## Self-Review

- No placeholders remain.
- The spec does not require backend contract changes beyond script/config enqueue work.
- Browser limitations are explicit and handled through fallbacks.
- The verification URL is treated as runtime-provided data, not a hard-coded domain.
- Scope is narrow enough for one implementation plan.
