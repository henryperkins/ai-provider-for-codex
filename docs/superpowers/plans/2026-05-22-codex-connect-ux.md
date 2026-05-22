# Codex Connect UX Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make Codex account linking start directly from Settings > Connectors and the per-user Codex Provider page, with popup handoff, clipboard copy, bounded polling, and no manual page refresh on the happy path.

**Architecture:** Add one shared browser controller for the device-code lifecycle and keep screen-specific rendering in thin adapters. The connector card and user page both call the same controller with injected browser, fetch, clipboard, and timer dependencies so popup, clipboard, polling, reconciliation, and timeout behavior can be tested in Node without a WordPress browser session.

**Tech Stack:** WordPress plugin PHP 7.4, WordPress admin script modules, plain JavaScript ES modules, Node `node --test`, WP REST API, WP-CLI verification through `scripts/verify.sh`.

---

## Source Spec

Implement the design in `docs/superpowers/specs/2026-05-22-codex-connect-ux-design.md`.

The spec is already written and self-reviewed. This plan assumes the spec is approved for implementation, but no production code changes have been made for this feature yet.

## File Responsibility Map

- Create `assets/connection-flow.js`: shared state-machine controller for start, popup handoff, clipboard copy, polling, `/status` reconciliation, focus/visibility resume, timeout, and custom DOM events.
- Create `assets/connection-flow.test.mjs`: Node tests for the shared controller.
- Modify `assets/connectors.js`: import the shared controller and replace redirect-after-start with inline pending/connected/error rendering.
- Create `assets/user-connection.js`: progressive enhancement adapter for the PHP-rendered per-user page.
- Modify `src/Admin/ConnectorsIntegration.php`: add connect-status and provider-status config keys consumed by the shared controller.
- Modify `src/Admin/UserConnectionPage.php`: enqueue the user-page module, render JSON config and enhancement mount points, update help/manual copy while preserving no-JavaScript actions.
- Modify `scripts/verify.php`: assert route/config/page fallback contracts for the enhanced UI.
- Modify `scripts/verify.sh`: syntax-check all new JavaScript modules and run the controller tests.

## Dirty Worktree And Commit Rules

This checkout already has an untracked `docs/` directory. Treat all existing untracked docs as user-owned unless this plan explicitly creates or modifies the file.

- Before each optional commit, run `git status --short`.
- Stage only the files changed by the current task.
- If committing incrementally, verify staged paths with `git diff --cached --name-only`.
- If the user wants a single final commit, skip per-task commit steps and still run the final verification.

---

### Task 1: Add Controller Tests First

**Files:**
- Create: `assets/connection-flow.test.mjs`

- [ ] **Step 1: Create the failing controller test file**

Create `assets/connection-flow.test.mjs` with this content:

```js
import assert from 'node:assert/strict';
import { test } from 'node:test';

import { createCodexConnectionFlow } from './connection-flow.js';

const START_URL = 'https://example.test/wp-json/codex-provider/v1/connect/start';
const CONNECT_STATUS_URL = 'https://example.test/wp-json/codex-provider/v1/connect/status';
const PROVIDER_STATUS_URL = 'https://example.test/wp-json/codex-provider/v1/status';

function jsonResponse( body, ok = true, status = 200 ) {
	return {
		ok,
		status,
		json: async () => body,
	};
}

function createHarness( options = {} ) {
	const states = [];
	const fetchCalls = [];
	const events = new Map();
	const timers = new Map();
	const sequence = [];
	const errors = [];
	let now = options.now ?? 0;
	let timerId = 0;

	const createPopupLocation = () => {
		let href = 'about:blank';
		return {
			get href() {
				return href;
			},
			set href( value ) {
				if ( options.popupNavigationError ) {
					throw new Error( options.popupNavigationError );
				}
				href = value;
			},
		};
	};

	const popup =
		options.popup === null
			? null
			: {
					closed: false,
					closeCalled: false,
					features: undefined,
					location: createPopupLocation(),
					opener: { name: 'wp-admin' },
					close() {
						this.closeCalled = true;
						this.closed = true;
					},
			  };

	const startResponse =
		options.startResponse ??
		{
			authSessionId: 'auth_123',
			status: 'pending',
			verificationUrl: 'https://auth.openai.com/codex/device',
			userCode: 'ABCD-EFGH',
		};
	const pollResponses = [ ...( options.pollResponses ?? [] ) ];
	const providerStatusResponses = [ ...( options.providerStatusResponses ?? [] ) ];

	const fetch = async ( url, requestOptions = {} ) => {
		fetchCalls.push( { url, requestOptions } );
		sequence.push( `fetch:${ url }` );

		if ( START_URL === url ) {
			if ( options.startError ) {
				return jsonResponse( { error: { message: options.startError } }, false, 400 );
			}

			return jsonResponse( startResponse );
		}

		if ( CONNECT_STATUS_URL === url ) {
			return jsonResponse( pollResponses.shift() ?? { status: 'pending' } );
		}

		if ( PROVIDER_STATUS_URL === url ) {
			return jsonResponse(
				providerStatusResponses.shift() ??
					{
						reason: 'login_failed',
						pendingConnection: { status: 'error', error: 'Device code expired.' },
					}
			);
		}

		return jsonResponse( { error: { message: `Unexpected URL ${ url }` } }, false, 404 );
	};

	const windowObject = {
		document: {
			visibilityState: 'visible',
			addEventListener( name, listener ) {
				events.set( name, listener );
			},
			removeEventListener( name ) {
				events.delete( name );
			},
			dispatchEvent( event ) {
				const listener = events.get( event.type );
				if ( listener ) {
					listener( event );
				}
			},
		},
		open( url, target, features ) {
			sequence.push( 'open' );
			if ( popup ) {
				popup.url = url;
				popup.target = target;
				popup.features = features;
			}
			return popup;
		},
		addEventListener( name, listener ) {
			events.set( name, listener );
		},
		removeEventListener( name ) {
			events.delete( name );
		},
		dispatchEvent( event ) {
			const listener = events.get( event.type );
			if ( listener ) {
				listener( event );
			}
		},
		CustomEvent: class CustomEvent {
			constructor( type, init ) {
				this.type = type;
				this.detail = init?.detail;
			}
		},
	};

	const clipboard = options.clipboardUnsupported
		? undefined
		: {
				writeText: async ( value ) => {
					sequence.push( `clipboard:${ value }` );
					if ( options.clipboardError ) {
						throw new Error( options.clipboardError );
					}
				},
		  };

	const setTimeout = ( callback, delay ) => {
		const id = ++timerId;
		timers.set( id, { callback, delay, due: now + delay } );
		return id;
	};
	const clearTimeout = ( id ) => {
		timers.delete( id );
	};

	const flow = createCodexConnectionFlow( {
		startUrl: START_URL,
		statusUrl: CONNECT_STATUS_URL,
		providerStatusUrl: PROVIDER_STATUS_URL,
		restNonce: 'nonce_123',
		fetch,
		clipboard,
		windowObject,
		now: () => now,
		setTimeout,
		clearTimeout,
		onStateChange: ( state ) => states.push( state ),
		onError: ( error ) => errors.push( error ),
		...( options.flowOptions || {} ),
	} );

	return {
		clipboard,
		errors,
		events,
		fetchCalls,
		flow,
		popup,
		sequence,
		states,
		timers,
		get now() {
			return now;
		},
		set now( value ) {
			now = value;
		},
		dispatch( type ) {
			windowObject.dispatchEvent( { type } );
			windowObject.document.dispatchEvent( { type } );
		},
		async runNextTimer() {
			const next = [ ...timers.entries() ].sort( ( a, b ) => a[ 1 ].due - b[ 1 ].due )[ 0 ];
			assert.ok( next, 'expected a scheduled timer' );
			const [ id, timer ] = next;
			timers.delete( id );
			now = timer.due;
			await timer.callback();
		},
	};
}

function lastState( harness ) {
	assert.ok( harness.states.length > 0, 'expected at least one emitted state' );
	return harness.states[ harness.states.length - 1 ];
}

test( 'opens a placeholder popup synchronously before connect/start and later navigates it', async () => {
	const harness = createHarness();

	await harness.flow.start();

	assert.deepEqual( harness.sequence.slice( 0, 2 ), [ 'open', `fetch:${ START_URL }` ] );
	assert.equal( harness.popup.url, 'about:blank' );
	assert.equal( harness.popup.target, 'codex-provider-auth' );
	assert.ok( ! String( harness.popup.features ?? '' ).includes( 'noopener' ) );
	assert.ok( ! String( harness.popup.features ?? '' ).includes( 'noreferrer' ) );
	assert.equal( harness.popup.opener, null );
	assert.equal( harness.popup.location.href, 'https://auth.openai.com/codex/device' );
	assert.equal( lastState( harness ).status, 'pending' );
	assert.equal( lastState( harness ).userCode, 'ABCD-EFGH' );
} );

test( 'reports clipboard failure without failing the pending login', async () => {
	const harness = createHarness( { clipboardError: 'denied' } );

	await harness.flow.start();

	assert.equal( lastState( harness ).status, 'pending' );
	assert.equal( lastState( harness ).copyStatus, 'failed' );
	assert.equal( lastState( harness ).userCode, 'ABCD-EFGH' );
} );

test( 'continues when the popup is blocked and keeps the manual URL visible', async () => {
	const harness = createHarness( { popup: null } );

	await harness.flow.start();

	assert.equal( lastState( harness ).status, 'pending' );
	assert.equal( lastState( harness ).popupStatus, 'blocked' );
	assert.equal( lastState( harness ).verificationUrl, 'https://auth.openai.com/codex/device' );
} );

test( 'keeps the manual verification fallback when an opened popup cannot be navigated', async () => {
	const harness = createHarness( { popupNavigationError: 'navigation denied' } );

	await harness.flow.start();

	assert.equal( lastState( harness ).status, 'pending' );
	assert.equal( lastState( harness ).popupStatus, 'blocked' );
	assert.equal( lastState( harness ).verificationUrl, 'https://auth.openai.com/codex/device' );
	assert.equal( harness.popup.location.href, 'about:blank' );
	assert.equal( harness.errors[ 0 ].message, 'navigation denied' );
} );

test( 'polls until connected and then stops scheduling timers', async () => {
	const harness = createHarness( {
		pollResponses: [
			{
				status: 'connected',
				connection: { account_email: 'user@example.test', plan_type: 'plus' },
				catalog: { selected_model: 'gpt-5' },
			},
		],
	} );

	await harness.flow.start();
	await harness.runNextTimer();

	assert.equal( lastState( harness ).status, 'connected' );
	assert.equal( lastState( harness ).connection.account_email, 'user@example.test' );
	assert.equal( harness.timers.size, 0 );
} );

test( 'reconciles connect/status errors through provider status for retryable sync failures', async () => {
	const harness = createHarness( {
		pollResponses: [ { status: 'error', error: 'Snapshot refresh failed.' } ],
		providerStatusResponses: [
			{
				reason: 'login_pending',
				pendingConnection: {
					status: 'completed',
					error: 'Snapshot refresh failed.',
				},
			},
		],
	} );

	await harness.flow.start();
	await harness.runNextTimer();

	assert.equal( lastState( harness ).status, 'sync_retry' );
	assert.equal( lastState( harness ).error, 'Snapshot refresh failed.' );
	assert.ok( harness.fetchCalls.some( ( call ) => call.url === PROVIDER_STATUS_URL ) );
} );

test( 'stops polling on missing sessions and exposes retry guidance state', async () => {
	const harness = createHarness( {
		pollResponses: [ { status: 'missing', error: 'Session was lost.' } ],
	} );

	await harness.flow.start();
	await harness.runNextTimer();

	assert.equal( lastState( harness ).status, 'missing' );
	assert.equal( lastState( harness ).error, 'Session was lost.' );
	assert.equal( harness.timers.size, 0 );
} );

test( 'resumes an existing pending server session without starting a fresh login', async () => {
	const harness = createHarness( {
		pollResponses: [
			{
				status: 'connected',
				connection: { account_email: 'existing@example.test', plan_type: 'plus' },
			},
		],
	} );

	harness.flow.resumePending( {
		authSessionId: 'auth_existing',
		verificationUrl: 'https://auth.openai.com/codex/device',
		userCode: 'EXIST-1234',
	} );

	assert.equal( lastState( harness ).status, 'pending' );
	assert.equal( lastState( harness ).userCode, 'EXIST-1234' );
	assert.equal( harness.fetchCalls.filter( ( call ) => call.url === START_URL ).length, 0 );

	await harness.runNextTimer();

	assert.equal( lastState( harness ).status, 'connected' );
	assert.equal( lastState( harness ).connection.account_email, 'existing@example.test' );
} );

test( 'resumes a still-pending poll when focus returns', async () => {
	const harness = createHarness( {
		pollResponses: [
			{ status: 'pending' },
			{
				status: 'connected',
				connection: { account_email: 'focus@example.test', plan_type: 'team' },
			},
		],
	} );

	await harness.flow.start();
	harness.dispatch( 'focus' );
	await Promise.resolve();

	assert.equal(
		harness.fetchCalls.filter( ( call ) => call.url === CONNECT_STATUS_URL ).length,
		1
	);
	assert.equal( lastState( harness ).status, 'pending' );

	await harness.runNextTimer();

	assert.equal( lastState( harness ).status, 'connected' );
	assert.equal( lastState( harness ).connection.account_email, 'focus@example.test' );
} );

test( 'marks the flow timed out after the bounded polling window', async () => {
	const harness = createHarness();

	await harness.flow.start();
	harness.now = 600001;
	harness.dispatch( 'focus' );
	await Promise.resolve();

	assert.equal( lastState( harness ).status, 'timed_out' );
	assert.equal( lastState( harness ).userCode, 'ABCD-EFGH' );
	assert.equal( harness.timers.size, 0 );
} );

test( 'allows a manual status check after timeout without starting over', async () => {
	const harness = createHarness( {
		pollResponses: [
			{
				status: 'connected',
				connection: { account_email: 'late@example.test', plan_type: 'plus' },
			},
		],
	} );

	await harness.flow.start();
	harness.now = 600001;
	harness.dispatch( 'focus' );
	await Promise.resolve();

	assert.equal( lastState( harness ).status, 'timed_out' );

	await harness.flow.pollNow();

	assert.equal( lastState( harness ).status, 'connected' );
	assert.equal( lastState( harness ).connection.account_email, 'late@example.test' );
} );
```

- [ ] **Step 2: Run the new tests and confirm RED**

Run:

```bash
node --test assets/connection-flow.test.mjs
```

Expected: FAIL because `assets/connection-flow.js` does not exist or does not export `createCodexConnectionFlow`.

- [ ] **Step 3: Commit the failing tests if committing incrementally**

Run:

```bash
git status --short
git add assets/connection-flow.test.mjs
git diff --cached --name-only
git commit -m "test: cover codex connection flow controller"
```

Expected staged path: `assets/connection-flow.test.mjs`.

---

### Task 2: Implement The Shared Connection Controller

**Files:**
- Create: `assets/connection-flow.js`
- Test: `assets/connection-flow.test.mjs`

- [ ] **Step 1: Create the shared controller**

Create `assets/connection-flow.js` with a small exported controller that matches the tested public API, including `resumePending()` for already-persisted server-side sessions rendered by the no-JavaScript fallback:

```js
const INITIAL_STATE = Object.freeze( {
	status: 'idle',
	copyStatus: 'idle',
	popupStatus: 'idle',
	authSessionId: '',
	verificationUrl: '',
	userCode: '',
	error: '',
	connection: null,
	catalog: null,
	providerStatus: null,
} );

const FIRST_INTERVAL_MS = 2000;
const SECOND_INTERVAL_MS = 5000;
const FAST_POLL_WINDOW_MS = 30000;
const TIMEOUT_MS = 10 * 60 * 1000;

function toErrorMessage( error, fallback ) {
	if ( error && typeof error.message === 'string' && error.message ) {
		return error.message;
	}
	return fallback;
}

async function readJsonResponse( response ) {
	const body = await response.json();
	if ( ! response.ok ) {
		const message = body?.error?.message || body?.message || `Request failed with HTTP ${ response.status }`;
		throw new Error( message );
	}
	return body;
}

export function createCodexConnectionFlow( options ) {
	const windowObject = options.windowObject || window;
	const documentObject = windowObject.document;
	const fetchObject = options.fetch || windowObject.fetch?.bind( windowObject );
	const clipboard = options.clipboard || windowObject.navigator?.clipboard;
	const now = options.now || Date.now;
	const setTimer = options.setTimeout || windowObject.setTimeout.bind( windowObject );
	const clearTimer = options.clearTimeout || windowObject.clearTimeout.bind( windowObject );
	const onStateChange = options.onStateChange || ( () => {} );
	const onError = options.onError || ( () => {} );
	let state = { ...INITIAL_STATE };
	let startedAt = 0;
	let timer = null;
	let polling = false;
	let stopped = false;
	let visibilityListener = null;

	const request = async ( url, init = {} ) => {
		if ( ! fetchObject ) {
			throw new Error( 'The browser does not provide fetch().' );
		}

		const headers = {
			Accept: 'application/json',
			...( init.headers || {} ),
		};

		if ( options.restNonce ) {
			headers[ 'X-WP-Nonce' ] = options.restNonce;
		}

		return readJsonResponse(
			await fetchObject( url, {
				credentials: 'same-origin',
				...init,
				headers,
			} )
		);
	};

	const emit = ( patch ) => {
		state = { ...state, ...patch };
		onStateChange( state );

		if ( documentObject?.dispatchEvent && windowObject.CustomEvent ) {
			documentObject.dispatchEvent(
				new windowObject.CustomEvent( 'codex-provider-connection-state', {
					detail: state,
				} )
			);
		}

		return state;
	};

	const clearScheduledPoll = () => {
		if ( null !== timer ) {
			clearTimer( timer );
			timer = null;
		}
	};

	const stop = () => {
		stopped = true;
		clearScheduledPoll();
		removeResumeListeners();
	};

	const terminal = ( patch ) => {
		clearScheduledPoll();
		removeResumeListeners();
		return emit( patch );
	};

	const elapsed = () => Math.max( 0, now() - startedAt );

	const scheduleNextPoll = () => {
		clearScheduledPoll();

		if ( stopped || state.status !== 'pending' ) {
			return;
		}

		if ( elapsed() >= TIMEOUT_MS ) {
			terminal( { status: 'timed_out' } );
			return;
		}

		const interval = elapsed() < FAST_POLL_WINDOW_MS ? FIRST_INTERVAL_MS : SECOND_INTERVAL_MS;
		timer = setTimer( pollOnce, interval );
	};

	const reconcileError = async ( fallbackError ) => {
		if ( ! options.providerStatusUrl ) {
			return terminal( { status: 'failed', error: fallbackError } );
		}

		try {
			const providerStatus = await request( options.providerStatusUrl );
			const pending = providerStatus?.pendingConnection;

			if ( providerStatus?.reason === 'login_pending' && pending?.status === 'completed' ) {
				return terminal( {
					status: 'sync_retry',
					error: pending.error || fallbackError,
					providerStatus,
				} );
			}

			return terminal( {
				status: providerStatus?.reason === 'login_failed' ? 'failed' : 'failed',
				error: pending?.error || fallbackError,
				providerStatus,
			} );
		} catch ( error ) {
			onError( error );
			return terminal( { status: 'failed', error: fallbackError } );
		}
	};

		async function pollOnce() {
			if ( polling || stopped || state.status !== 'pending' ) {
				return;
			}

		if ( elapsed() >= TIMEOUT_MS ) {
			terminal( { status: 'timed_out' } );
			return;
		}

		polling = true;

		try {
			const result = await request( options.statusUrl );
			const status = result?.status || 'pending';

			if ( status === 'connected' ) {
				terminal( {
					status: 'connected',
					connection: result.connection || null,
					catalog: result.catalog || result.snapshot || null,
					error: '',
				} );
				return;
			}

			if ( status === 'error' ) {
				await reconcileError( result.error || 'The local Codex runtime reported a login error.' );
				return;
			}

			if ( status === 'missing' ) {
				terminal( {
					status: 'missing',
					error: result.error || 'The local runtime no longer has this login session.',
				} );
				return;
			}

			emit( { status: 'pending', error: result.error || '' } );
		} catch ( error ) {
			onError( error );
			emit( {
				status: 'pending',
				error: toErrorMessage( error, 'Still checking connection status.' ),
			} );
		} finally {
			polling = false;
				scheduleNextPoll();
			}
		}

		const pollNow = () => {
			if ( state.status === 'timed_out' ) {
				stopped = false;
				startedAt = now();
				emit( { status: 'pending', error: '' } );
			}

			return pollOnce();
		};

		const resume = () => {
			if ( state.status !== 'pending' || polling || stopped ) {
				return;
			}

		void pollOnce();
	};

	function addResumeListeners() {
		windowObject.addEventListener?.( 'focus', resume );
		windowObject.addEventListener?.( 'pageshow', resume );
		visibilityListener = () => {
			if ( ! documentObject.visibilityState || documentObject.visibilityState === 'visible' ) {
				resume();
			}
		};
		documentObject?.addEventListener?.( 'visibilitychange', visibilityListener );
	}

		function removeResumeListeners() {
			windowObject.removeEventListener?.( 'focus', resume );
			windowObject.removeEventListener?.( 'pageshow', resume );
			if ( visibilityListener ) {
				documentObject?.removeEventListener?.( 'visibilitychange', visibilityListener );
				visibilityListener = null;
			}
		}

		const resumePending = ( pendingState ) => {
			stopped = false;
			startedAt = now();
			clearScheduledPoll();
			removeResumeListeners();
			emit( {
				...INITIAL_STATE,
				...( pendingState || {} ),
				status: 'pending',
				copyStatus: 'idle',
				popupStatus: 'idle',
				error: pendingState?.error || '',
			} );
			addResumeListeners();
			scheduleNextPoll();
			return state;
		};

		const start = async () => {
			stopped = false;
			startedAt = now();
			clearScheduledPoll();
			emit( { ...INITIAL_STATE, status: 'starting' } );

		const authWindow = windowObject.open?.( 'about:blank', 'codex-provider-auth' ) || null;
		const popupStatus = authWindow ? 'opened' : 'blocked';

		try {
			const result = await request( options.startUrl, { method: 'POST' } );

			let finalPopupStatus = popupStatus;
			if ( authWindow && ! authWindow.closed ) {
				try {
					authWindow.opener = null;
					authWindow.location.href = result.verificationUrl;
					finalPopupStatus = 'navigated';
				} catch ( error ) {
					onError( error );
					finalPopupStatus = 'blocked';
				}
			}

			emit( {
				status: 'pending',
				authSessionId: result.authSessionId || '',
				verificationUrl: result.verificationUrl || '',
				userCode: result.userCode || '',
				copyStatus: 'unsupported',
				popupStatus: finalPopupStatus,
				error: '',
			} );

			if ( clipboard?.writeText && result.userCode ) {
				try {
					await clipboard.writeText( result.userCode );
					emit( { copyStatus: 'copied' } );
				} catch ( error ) {
					onError( error );
					emit( { copyStatus: 'failed' } );
				}
			}

			addResumeListeners();
			scheduleNextPoll();
			return result;
		} catch ( error ) {
			if ( authWindow && ! authWindow.closed ) {
				try {
					authWindow.close();
				} catch ( closeError ) {
					onError( closeError );
				}
			}

			const message = toErrorMessage( error, 'The local Codex runtime request failed.' );
			onError( error );
			terminal( { status: 'failed', error: message, popupStatus } );
			return null;
		}
	};

	return {
			getState: () => state,
			resumePending,
			start,
			pollNow,
			stop,
		};
	}
```

- [ ] **Step 2: Run controller tests and confirm GREEN**

Run:

```bash
node --test assets/connection-flow.test.mjs
```

Expected: PASS for all tests in `assets/connection-flow.test.mjs`.

- [ ] **Step 3: Syntax-check the new module**

Run:

```bash
node --input-type=module --check < assets/connection-flow.js
```

Expected: no output and exit code `0`.

- [ ] **Step 4: Commit if committing incrementally**

Run:

```bash
git status --short
git add assets/connection-flow.js assets/connection-flow.test.mjs
git diff --cached --name-only
git commit -m "feat: add codex connection flow controller"
```

Expected staged paths:

```text
assets/connection-flow.js
assets/connection-flow.test.mjs
```

---

### Task 3: Wire The Connector Card To The Shared Flow

**Files:**
- Modify: `src/Admin/ConnectorsIntegration.php`
- Modify: `assets/connectors.js`
- Test: `assets/connection-flow.test.mjs`

- [ ] **Step 1: Extend connector REST config**

In `src/Admin/ConnectorsIntegration.php`, update `connector_config()` so it returns connect-status and provider-status keys while retaining the existing keys:

```php
return [
	'connectorId'            => self::CONNECTOR_ID,
	'statusUrl'              => rest_url( 'codex-provider/v1/status' ),
	'statusPath'             => '/codex-provider/v1/status',
	'providerStatusUrl'      => rest_url( 'codex-provider/v1/status' ),
	'providerStatusPath'     => '/codex-provider/v1/status',
	'startConnectUrl'        => rest_url( 'codex-provider/v1/connect/start' ),
	'startConnectPath'       => '/codex-provider/v1/connect/start',
	'connectStatusUrl'       => rest_url( 'codex-provider/v1/connect/status' ),
	'connectStatusPath'      => '/codex-provider/v1/connect/status',
	'siteSettingsUrl'        => SiteSettings::page_url(),
	'userConnectionUrl'      => UserConnectionPage::page_url(),
	'restNonce'              => wp_create_nonce( 'wp_rest' ),
];
```

- [ ] **Step 2: Import the controller and add React refs**

In `assets/connectors.js`, add this import above the WordPress imports:

```js
import { createCodexConnectionFlow } from './connection-flow.js';
```

Change the element destructuring from:

```js
const { createElement, Fragment, useEffect, useState } = wpPackages.element;
```

To:

```js
const { createElement, Fragment, useEffect, useRef, useState } = wpPackages.element;
```

- [ ] **Step 3: Replace redirect-based connect handling**

Inside `CodexConnector`, add a `flowRef` and `flowState`:

```js
const [ flowState, setFlowState ] = useState( null );
const flowRef = useRef( null );

if ( ! flowRef.current ) {
	flowRef.current = createCodexConnectionFlow( {
		startUrl: config.startConnectUrl || config.startConnectPath,
		statusUrl: config.connectStatusUrl || config.connectStatusPath,
		providerStatusUrl: config.providerStatusUrl || config.statusUrl || config.statusPath,
		restNonce: config.restNonce,
		fetch: window.fetch.bind( window ),
		windowObject: window,
		clipboard: window.navigator?.clipboard,
		onStateChange: ( nextState ) => {
			setFlowState( nextState );

			if ( [ 'connected', 'sync_retry', 'failed', 'missing' ].includes( nextState.status ) ) {
				loadStatus();
			}
		},
		onError: ( error ) => {
			window.console?.warn?.( 'Codex account connection flow failed.', error );
		},
	} );
}
```

Update the cleanup effect:

```js
useEffect( () => {
	loadStatus();

	return () => {
		flowRef.current?.stop();
	};
}, [] );
```

Replace `handleConnect` with:

```js
const handleConnect = () => {
	setIsBusy( true );

	flowRef.current
		.start()
		.finally( () => {
			setIsBusy( false );
		} );
};
```

- [ ] **Step 4: Add compact connector pending/error rendering**

Add helper rendering functions near `handleConnect`:

```js
const renderCodeLine = ( nextState ) =>
	createElement(
		'div',
		{
			style: {
				display: 'flex',
				gap: '8px',
				alignItems: 'center',
				flexWrap: 'wrap',
				marginTop: '6px',
			},
		},
		createElement(
			'code',
			{
				style: {
					fontSize: '13px',
					letterSpacing: '0.08em',
				},
			},
			nextState.userCode
		),
		createElement(
			Button,
			{
				variant: 'secondary',
				size: 'small',
				onClick: () => {
					window.navigator?.clipboard?.writeText( nextState.userCode );
				},
			},
			__( 'Copy', 'ai-provider-for-codex' )
		)
	);

const renderPendingFlow = ( nextState ) =>
	createElement(
		'div',
		{
			'aria-live': 'polite',
			style: {
				maxWidth: '280px',
			},
		},
		createElement(
			'strong',
			null,
			__( 'Waiting for ChatGPT approval...', 'ai-provider-for-codex' )
		),
		renderCodeLine( nextState ),
		createElement(
			'p',
			{
				style: {
					margin: '6px 0 0',
				},
			},
			nextState.copyStatus === 'copied'
				? __( 'Code copied.', 'ai-provider-for-codex' )
				: nextState.copyStatus === 'failed'
				? __( 'Copy did not work in this browser. Select the code below.', 'ai-provider-for-codex' )
				: __( 'Return to this tab after approving.', 'ai-provider-for-codex' )
		),
		nextState.popupStatus === 'blocked'
			? createElement(
					'a',
					{
						href: nextState.verificationUrl,
						target: '_blank',
						rel: 'noopener noreferrer',
					},
					__( 'Open verification page', 'ai-provider-for-codex' )
			  )
			: null
	);

const renderTerminalFlow = ( nextState ) =>
	createElement(
		'div',
		{ 'aria-live': 'polite' },
		createElement(
			'p',
			{ style: { margin: '0 0 8px' } },
			nextState.status === 'sync_retry'
				? __( 'Your login was approved, but WordPress could not sync your Codex account yet.', 'ai-provider-for-codex' )
				: nextState.status === 'missing'
				? __( 'The local runtime no longer has this login session. Start again to get a fresh code.', 'ai-provider-for-codex' )
				: nextState.status === 'timed_out'
				? __( 'Still waiting. You can check again or start over.', 'ai-provider-for-codex' )
				: nextState.error || __( 'The local Codex runtime request failed.', 'ai-provider-for-codex' )
		),
		createElement(
			Button,
			{
				variant: nextState.status === 'sync_retry' ? 'primary' : 'secondary',
				href: config.userConnectionUrl,
			},
			nextState.status === 'sync_retry'
				? __( 'Retry account sync', 'ai-provider-for-codex' )
				: __( 'Review error', 'ai-provider-for-codex' )
		)
	);
```

At the start of the `actionArea` selection, before `isLoading`, add:

```js
if ( flowState?.status === 'starting' ) {
	actionArea = createElement( Spinner );
} else if ( flowState?.status === 'pending' ) {
	actionArea = renderPendingFlow( flowState );
} else if ( [ 'sync_retry', 'failed', 'missing', 'timed_out' ].includes( flowState?.status ) ) {
	actionArea = renderTerminalFlow( flowState );
} else if ( isLoading ) {
```

Then remove the old `if ( isLoading ) {` branch opening so the condition chain remains valid.

- [ ] **Step 5: Verify connector syntax and controller tests**

Run:

```bash
node --input-type=module --check < assets/connectors.js
node --test assets/connection-flow.test.mjs
```

Expected: both commands pass.

- [ ] **Step 6: Commit if committing incrementally**

Run:

```bash
git status --short
git add src/Admin/ConnectorsIntegration.php assets/connectors.js
git diff --cached --name-only
git commit -m "feat: run codex connector login inline"
```

Expected staged paths:

```text
assets/connectors.js
src/Admin/ConnectorsIntegration.php
```

---

### Task 4: Add Progressive Enhancement To The User Connection Page

**Files:**
- Create: `assets/user-connection.js`
- Modify: `src/Admin/UserConnectionPage.php`
- Test: `scripts/verify.php`

- [ ] **Step 1: Add a user-page script module**

Create `assets/user-connection.js`:

```js
import { createCodexConnectionFlow } from './connection-flow.js';

const configElement = document.getElementById( 'codex-provider-connection-config' );
const root = document.querySelector( '[data-codex-connection-root]' );

if ( configElement && root ) {
	const config = JSON.parse( configElement.textContent || '{}' );
	const consolePanel = root.querySelector( '[data-codex-connection-console]' );
	const baseActions = root.querySelector( '[data-codex-base-actions]' );
	const fallbackBlocks = root.querySelectorAll( '[data-codex-server-fallback]' );
	const statusTargets = root.querySelectorAll( '[data-codex-connection-status]' );
	const heading = root.querySelector( '[data-codex-connection-heading]' );
	const codeText = root.querySelector( '[data-codex-connection-code]' );
	const codeActions = root.querySelector( '[data-codex-code-actions]' );
	const copyButton = root.querySelector( '[data-codex-copy-code]' );
	const openLink = root.querySelector( '[data-codex-open-verification]' );
	const startButtons = root.querySelectorAll( '[data-codex-start-connect]' );
	const checkButton = root.querySelector( '[data-codex-check-status]' );
	const terminalText = root.querySelector( '[data-codex-terminal-text]' );
	const terminalActions = root.querySelector( '[data-codex-terminal-actions]' );
	const retrySyncLink = root.querySelector( '[data-codex-retry-sync]' );
	const startAgainLink = root.querySelector( '[data-codex-start-again]' );
	const connectedDetails = root.querySelector( '[data-codex-connected-details]' );
	const connectedEmail = root.querySelector( '[data-codex-connected-email]' );
	const connectedPlan = root.querySelector( '[data-codex-connected-plan]' );
	const connectedModel = root.querySelector( '[data-codex-connected-model]' );
	let isStarting = false;

	const setText = ( element, text ) => {
		if ( element ) {
			element.textContent = text;
		}
	};

	const setStatusText = ( text ) => {
		statusTargets.forEach( ( element ) => {
			element.textContent = text;
		} );
	};

	const setHidden = ( element, hidden ) => {
		if ( element ) {
			element.hidden = hidden;
		}
	};

	const setStartButtonsBusy = ( busy ) => {
		startButtons.forEach( ( button ) => {
			button.setAttribute( 'aria-disabled', busy ? 'true' : 'false' );
			button.classList.toggle( 'disabled', busy );

			if ( 'disabled' in button ) {
				button.disabled = busy;
			}
		} );
	};

	const showEnhancedConsole = () => {
		setHidden( consolePanel, false );
		setHidden( baseActions, true );
		fallbackBlocks.forEach( ( block ) => {
			block.hidden = true;
		} );
	};

	const renderCode = ( state ) => {
		if ( state.userCode ) {
			setText( codeText, state.userCode );
			setHidden( codeText, false );
			setHidden( codeActions, false );
		}

		if ( openLink && state.verificationUrl ) {
			openLink.href = state.verificationUrl;
			openLink.hidden = false;
			setHidden( codeActions, false );
		}
	};

	const renderConnectedDetails = ( state ) => {
		const connection = state.connection || {};
		const catalog = state.catalog || {};
		const selectedModel = catalog.selected_model || catalog.defaultModel || '';

		setText( connectedEmail, connection.account_email || connection.accountEmail || '' );
		setText( connectedPlan, connection.plan_type || connection.planType || '' );
		setText( connectedModel, selectedModel );
		setHidden(
			connectedDetails,
			! ( connection.account_email || connection.accountEmail || connection.plan_type || connection.planType || selectedModel )
		);
	};

	const renderTerminalActions = ( state ) => {
		const showRetry = state.status === 'sync_retry';
		const showStartAgain = [ 'failed', 'missing', 'timed_out' ].includes( state.status );

		setHidden( retrySyncLink, ! showRetry );
		setHidden( startAgainLink, ! showStartAgain );
		setHidden( terminalActions, ! showRetry && ! showStartAgain );
	};

	const normalizePendingStatus = ( status ) => {
		if ( status === 'completed' ) {
			return 'sync_retry';
		}

		if ( status === 'error' ) {
			return 'failed';
		}

		return status || 'pending';
	};

	const renderState = ( state ) => {
		if ( state.status !== 'idle' ) {
			showEnhancedConsole();
		}

		if ( state.userCode || state.verificationUrl ) {
			renderCode( state );
		}

		if ( state.status === 'starting' ) {
			setText( heading, config.text.heading );
			setStatusText( config.text.starting );
			setHidden( terminalText, true );
			setHidden( connectedDetails, true );
			setHidden( terminalActions, true );
			setStartButtonsBusy( true );
			return;
		}

		if ( state.status === 'pending' ) {
			setText( heading, config.text.heading );
			setStatusText( config.text.pending );
			setHidden( terminalText, true );
			setHidden( checkButton, false );
			setHidden( connectedDetails, true );
			setHidden( terminalActions, true );
			setStartButtonsBusy( false );

			if ( state.copyStatus === 'copied' ) {
				setText( terminalText, config.text.copied );
				setHidden( terminalText, false );
			} else if ( state.copyStatus === 'failed' ) {
				setText( terminalText, config.text.copyFailed );
				setHidden( terminalText, false );
			} else if ( state.popupStatus === 'blocked' ) {
				setText( terminalText, config.text.popupBlocked );
				setHidden( terminalText, false );
			}

			return;
		}

		if ( state.status === 'connected' ) {
			setText( heading, config.text.connectedHeading );
			setStatusText( config.text.connected );
			setText( terminalText, config.text.connected );
			setHidden( terminalText, false );
			setHidden( codeText, true );
			setHidden( codeActions, true );
			setHidden( checkButton, true );
			setHidden( terminalActions, true );
			renderConnectedDetails( state );
			return;
		}

		if ( state.status === 'sync_retry' ) {
			setText( heading, config.text.syncRetryHeading );
			setStatusText( config.text.syncRetry );
			setText( terminalText, state.error || config.text.syncRetry );
			setHidden( terminalText, false );
			setHidden( checkButton, true );
			renderTerminalActions( state );
			return;
		}

		if ( state.status === 'missing' ) {
			setText( heading, config.text.failedHeading );
			setStatusText( config.text.missing );
			setText( terminalText, state.error || config.text.missing );
			setHidden( terminalText, false );
			setHidden( checkButton, true );
			renderTerminalActions( state );
			return;
		}

		if ( state.status === 'timed_out' ) {
			setText( heading, config.text.failedHeading );
			setStatusText( config.text.timedOut );
			setText( terminalText, config.text.timedOut );
			setHidden( terminalText, false );
			setHidden( checkButton, false );
			renderTerminalActions( state );
			return;
		}

		if ( state.status === 'failed' ) {
			setText( heading, config.text.failedHeading );
			setStatusText( config.text.failed );
			setText( terminalText, state.error || config.text.failed );
			setHidden( terminalText, false );
			setHidden( checkButton, true );
			renderTerminalActions( state );
		}
	};

	const flow = createCodexConnectionFlow( {
		startUrl: config.startUrl,
		statusUrl: config.connectStatusUrl,
		providerStatusUrl: config.providerStatusUrl,
		restNonce: config.restNonce,
		fetch: window.fetch.bind( window ),
		windowObject: window,
		clipboard: window.navigator?.clipboard,
		onStateChange: renderState,
		onError: ( error ) => {
			window.console?.warn?.( 'Codex account connection flow failed.', error );
		},
	} );

	if ( config.currentPending ) {
		const pendingState = {
			status: normalizePendingStatus( config.currentPending.status ),
			authSessionId: config.currentPending.authSessionId || '',
			verificationUrl: config.currentPending.verificationUrl || '',
			userCode: config.currentPending.userCode || '',
			error: config.currentPending.error || '',
			copyStatus: 'idle',
			popupStatus: 'idle',
		};

		if ( pendingState.status === 'pending' ) {
			flow.resumePending( pendingState );
		} else {
			renderState( pendingState );
		}
	}

	startButtons.forEach( ( button ) => {
		button.addEventListener( 'click', async ( event ) => {
			event.preventDefault();

			if ( isStarting ) {
				return;
			}

			isStarting = true;
			setStartButtonsBusy( true );

			try {
				await flow.start();
			} finally {
				isStarting = false;
				if ( flow.getState().status !== 'starting' ) {
					setStartButtonsBusy( false );
				}
			}
		} );
	} );

	copyButton?.addEventListener( 'click', async () => {
		const code = codeText?.textContent?.trim();
		if ( ! code || ! window.navigator?.clipboard?.writeText ) {
			setText( terminalText, config.text.copyFailed );
			setHidden( terminalText, false );
			return;
		}

		try {
			await window.navigator.clipboard.writeText( code );
			setText( terminalText, config.text.copied );
			setHidden( terminalText, false );
		} catch ( error ) {
			setText( terminalText, config.text.copyFailed );
			setHidden( terminalText, false );
		}
	} );

	checkButton?.addEventListener( 'click', ( event ) => {
		event.preventDefault();
		flow.pollNow();
	} );
}
```

- [ ] **Step 2: Register and enqueue the user-page module**

In `src/Admin/UserConnectionPage.php`, add constants inside the class:

```php
private const SCRIPT_MODULE_ID = 'ai-provider-for-codex/user-connection';
```

In `register_page()`, after the help-tab hook registration, add:

```php
if ( $hook ) {
	add_action( "admin_print_scripts-{$hook}", [ self::class, 'enqueue_assets' ] );
}
```

Add this public method to the class:

```php
/**
 * Enqueues the enhanced user connection flow.
 *
 * @return void
 */
public static function enqueue_assets(): void {
	wp_register_script_module(
		self::SCRIPT_MODULE_ID,
		plugins_url( 'assets/user-connection.js', \AIProviderForCodex\PLUGIN_FILE ),
		[],
		\AIProviderForCodex\VERSION
	);
	wp_enqueue_script_module( self::SCRIPT_MODULE_ID );
}
```

- [ ] **Step 3: Render page-local config and enhancement targets**

In `render_page()`, after `$selected_model` is set, add:

```php
	$connection_config = [
		'pageUrl'           => self::page_url(),
		'startUrl'          => rest_url( 'codex-provider/v1/connect/start' ),
		'connectStatusUrl'  => rest_url( 'codex-provider/v1/connect/status' ),
		'providerStatusUrl' => rest_url( 'codex-provider/v1/status' ),
		'restNonce'         => wp_create_nonce( 'wp_rest' ),
		'currentPending'    => $pending && ! empty( $pending['authSessionId'] )
			? [
				'authSessionId'   => (string) $pending['authSessionId'],
				'status'          => $pending_status,
				'verificationUrl' => (string) ( $pending['verificationUrl'] ?? '' ),
				'userCode'        => (string) ( $pending['userCode'] ?? '' ),
				'error'           => (string) ( $pending['error'] ?? '' ),
			]
			: null,
		'text'              => [
			'heading'           => __( 'Complete account connection', 'ai-provider-for-codex' ),
			'connectedHeading'  => __( 'Codex account connected', 'ai-provider-for-codex' ),
			'syncRetryHeading'  => __( 'Retry account sync', 'ai-provider-for-codex' ),
			'failedHeading'     => __( 'Connection needs attention', 'ai-provider-for-codex' ),
			'starting'          => __( 'Starting Codex login...', 'ai-provider-for-codex' ),
			'pending'           => __( 'Waiting for ChatGPT approval...', 'ai-provider-for-codex' ),
			'copied'            => __( 'Code copied.', 'ai-provider-for-codex' ),
			'copyFailed'        => __( 'Copy did not work in this browser. Select the code below.', 'ai-provider-for-codex' ),
			'popupBlocked'      => __( 'Your browser blocked the verification tab. Open it manually.', 'ai-provider-for-codex' ),
			'connected'         => __( 'Your Codex account is connected.', 'ai-provider-for-codex' ),
			'syncRetry'         => __( 'Your login was approved, but WordPress could not sync your Codex account yet.', 'ai-provider-for-codex' ),
			'missing'           => __( 'The local runtime no longer has this login session. Start again to get a fresh code.', 'ai-provider-for-codex' ),
			'timedOut'          => __( 'Still waiting. You can check again or start over.', 'ai-provider-for-codex' ),
			'failed'            => __( 'The local Codex runtime request failed.', 'ai-provider-for-codex' ),
		],
	];
```

Immediately inside `<div class="wrap">`, after the `<h1>`, add:

```php
<script type="application/json" id="codex-provider-connection-config"><?php echo wp_json_encode( $connection_config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ); ?></script>
```

Wrap the existing page body in an enhancement root:

```php
<div data-codex-connection-root>
```

Close that wrapper before the existing closing `</div>` for `.wrap`.

Immediately after `self::render_notice( $notice );`, add an always-rendered enhancement console. This console is hidden until JavaScript starts or resumes a connection flow, so first-time users have stable targets before any pending server state exists:

```php
<div class="codex-device-box" data-codex-connection-console hidden>
	<h3 data-codex-connection-heading><?php esc_html_e( 'Complete account connection', 'ai-provider-for-codex' ); ?></h3>
	<p data-codex-connection-status aria-live="polite"></p>
	<p class="codex-device-code" data-codex-connection-code hidden></p>
	<p data-codex-code-actions hidden>
		<button type="button" class="button button-secondary" data-codex-copy-code><?php esc_html_e( 'Copy code', 'ai-provider-for-codex' ); ?></button>
		<a class="button button-secondary" data-codex-open-verification href="#" target="_blank" rel="noopener noreferrer" hidden><?php esc_html_e( 'Open verification page', 'ai-provider-for-codex' ); ?></a>
		<a class="button button-secondary" data-codex-check-status href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'action', 'check-connect', self::page_url() ), 'codex-provider-check-connect' ) ); ?>" hidden><?php esc_html_e( 'Check connection status', 'ai-provider-for-codex' ); ?></a>
	</p>
	<p class="description" data-codex-terminal-text aria-live="polite" hidden></p>
	<dl data-codex-connected-details hidden>
		<dt><?php esc_html_e( 'Account email', 'ai-provider-for-codex' ); ?></dt>
		<dd data-codex-connected-email></dd>
		<dt><?php esc_html_e( 'Plan type', 'ai-provider-for-codex' ); ?></dt>
		<dd data-codex-connected-plan></dd>
		<dt><?php esc_html_e( 'Selected model', 'ai-provider-for-codex' ); ?></dt>
		<dd data-codex-connected-model></dd>
	</dl>
	<p data-codex-terminal-actions hidden>
		<a class="button button-primary" data-codex-retry-sync href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'action', 'refresh-status', self::page_url() ), 'codex-provider-refresh-status' ) ); ?>" hidden><?php esc_html_e( 'Retry account sync', 'ai-provider-for-codex' ); ?></a>
		<a class="button button-primary" data-codex-start-connect data-codex-start-again href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'action', 'start-connect', self::page_url() ), 'codex-provider-start-connect' ) ); ?>" hidden><?php esc_html_e( 'Start connection again', 'ai-provider-for-codex' ); ?></a>
	</p>
</div>
```

In the status row, add `aria-live` and a target span:

```php
<span class="codex-indicator <?php echo esc_attr( $ind ); ?>"></span>
<span data-codex-connection-status aria-live="polite"><?php echo esc_html( $reason_label ); ?></span>
```

On the existing pending device box, add `data-codex-server-fallback` so JavaScript can hide the server-rendered fallback when the enhanced console is active:

```php
<div class="codex-device-box" data-codex-server-fallback>
```

In that pending fallback box, change the code paragraph and action links to:

```php
<p class="codex-device-code" data-codex-connection-code><?php echo esc_html( (string) $pending['userCode'] ); ?></p>
<p>
	<button type="button" class="button button-secondary" data-codex-copy-code><?php esc_html_e( 'Copy code', 'ai-provider-for-codex' ); ?></button>
	<a class="button button-secondary" data-codex-open-verification href="<?php echo esc_url( (string) $pending['verificationUrl'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open verification page', 'ai-provider-for-codex' ); ?></a>
	<a class="button button-primary" data-codex-check-status href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'action', 'check-connect', self::page_url() ), 'codex-provider-check-connect' ) ); ?>"><?php esc_html_e( 'Check connection status', 'ai-provider-for-codex' ); ?></a>
</p>
	<p class="description" data-codex-terminal-text aria-live="polite" hidden></p>
```

Wrap the bottom action paragraph with an enhancement target:

```php
<p style="margin-top: 1.5rem;" data-codex-base-actions>
```

On the "Connect Codex account" and "Start connection again" links, add `data-codex-start-connect`. Keep the `href` values intact so no-JavaScript fallback actions still work, but JavaScript will intercept these anchors and guard against duplicate starts with `aria-disabled` plus an in-flight flag.

- [ ] **Step 4: Update user-facing copy for automatic polling**

In `render_help_tab()`, replace the third ordered-list item:

```php
<li><?php esc_html_e( 'You open the verification page, enter the device code, then come back here and refresh status.', 'ai-provider-for-codex' ); ?></li>
```

With:

```php
<li><?php esc_html_e( 'WordPress opens the verification page, keeps the device code visible, and checks automatically while you return to this tab after approval.', 'ai-provider-for-codex' ); ?></li>
```

In the pending flow ordered list, replace the third item:

```php
<li><?php esc_html_e( 'Return here and click Check connection status.', 'ai-provider-for-codex' ); ?></li>
```

With:

```php
<li><?php esc_html_e( 'Return to this tab after approving. WordPress will keep checking automatically, and the Check status button remains available.', 'ai-provider-for-codex' ); ?></li>
```

- [ ] **Step 5: Verify syntax**

Run:

```bash
php -l src/Admin/UserConnectionPage.php
node --input-type=module --check < assets/user-connection.js
node --test assets/connection-flow.test.mjs
```

Expected: all commands pass.

- [ ] **Step 6: Commit if committing incrementally**

Run:

```bash
git status --short
git add assets/user-connection.js src/Admin/UserConnectionPage.php
git diff --cached --name-only
git commit -m "feat: enhance codex user connection page"
```

Expected staged paths:

```text
assets/user-connection.js
src/Admin/UserConnectionPage.php
```

---

### Task 5: Extend Repository Verification

**Files:**
- Modify: `scripts/verify.php`
- Modify: `scripts/verify.sh`
- Test: `scripts/verify.sh`

- [ ] **Step 1: Add JavaScript checks to `scripts/verify.sh`**

Replace the existing JavaScript syntax check:

```bash
node --input-type=module --check < "$ROOT_DIR/assets/connectors.js" >/dev/null
```

With:

```bash
node --input-type=module --check < "$ROOT_DIR/assets/connection-flow.js" >/dev/null
node --input-type=module --check < "$ROOT_DIR/assets/connectors.js" >/dev/null
node --input-type=module --check < "$ROOT_DIR/assets/user-connection.js" >/dev/null
node --test "$ROOT_DIR/assets/connection-flow.test.mjs"
```

- [ ] **Step 2: Add connector config assertions to `scripts/verify.php`**

Near the existing `ConnectorsIntegration::filter_ai_plugin_has_credentials()` assertions, add a temporary Connectors-screen context and assert the new keys:

```php
$_GET['page'] = 'options-connectors';
$codex_provider_connector_data = ConnectorsIntegration::script_module_data( [] );
unset( $_GET['page'] );

$codex_provider_assert( '/codex-provider/v1/connect/status' === (string) ( $codex_provider_connector_data['connectStatusPath'] ?? '' ), 'Connector module data should expose the connect/status REST path.' );
$codex_provider_assert( '/codex-provider/v1/status' === (string) ( $codex_provider_connector_data['providerStatusPath'] ?? '' ), 'Connector module data should expose the passive provider status REST path.' );
$codex_provider_assert( ! empty( $codex_provider_connector_data['connectStatusUrl'] ), 'Connector module data should expose the connect/status REST URL.' );
$codex_provider_assert( ! empty( $codex_provider_connector_data['providerStatusUrl'] ), 'Connector module data should expose the provider status REST URL.' );
```

- [ ] **Step 3: Add user-page enhancement assertions to `scripts/verify.php`**

After an existing `UserConnectionPage::render_page()` capture for pending state, add assertions for the progressive-enhancement config and no-JavaScript fallback:

```php
	$codex_provider_assert( false !== strpos( $codex_provider_connection_page_html, 'id="codex-provider-connection-config"' ), 'User connection page should render JSON config for the enhanced connection flow.' );
	$codex_provider_user_page_source = (string) file_get_contents( dirname( __DIR__ ) . '/src/Admin/UserConnectionPage.php' );
	$codex_provider_assert( false !== strpos( $codex_provider_user_page_source, 'JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT' ), 'User connection page JSON config should be encoded with hex flags for script-tag safety.' );
	$codex_provider_assert( false !== strpos( $codex_provider_user_page_source, 'admin_print_scripts-{$hook}' ), 'User connection page should hook its script module enqueue to the concrete admin page hook.' );
	UserConnectionPage::enqueue_assets();
	$codex_provider_script_module_queue = function_exists( 'wp_script_modules' ) ? wp_script_modules()->get_queue() : [];
	$codex_provider_assert( in_array( 'ai-provider-for-codex/user-connection', $codex_provider_script_module_queue, true ), 'User connection page should enqueue the enhanced connection-flow script module.' );
	$codex_provider_assert( false !== strpos( $codex_provider_connection_page_html, 'data-codex-connection-root' ), 'User connection page should render the enhanced connection root.' );
	$codex_provider_assert( false !== strpos( $codex_provider_connection_page_html, 'data-codex-connection-console' ), 'User connection page should render an always-present enhanced connection console for first-time starts.' );
	$codex_provider_assert( false !== strpos( $codex_provider_connection_page_html, 'data-codex-base-actions' ), 'User connection page should expose the base action container to hide during enhanced flows.' );
	$codex_provider_assert( false !== strpos( $codex_provider_connection_page_html, 'data-codex-start-connect' ), 'User connection page should mark start/restart links for progressive enhancement while preserving href fallbacks.' );
	$codex_provider_assert( false !== strpos( $codex_provider_connection_page_html, 'data-codex-copy-code' ), 'User connection page should render an explicit Copy code button.' );
	$codex_provider_assert( false !== strpos( $codex_provider_connection_page_html, 'data-codex-check-status' ), 'User connection page should preserve the manual Check connection status fallback.' );
	$codex_provider_assert( false !== strpos( $codex_provider_connection_page_html, 'data-codex-retry-sync' ), 'User connection page should expose the retry-sync action for retryable completed pending sessions.' );
	$codex_provider_assert( false !== strpos( $codex_provider_connection_page_html, 'data-codex-server-fallback' ), 'User connection page should label server-rendered pending blocks so JavaScript can hide them once enhanced rendering takes over.' );
	$codex_provider_assert( false !== strpos( $codex_provider_connection_page_html, 'ABCD-EFGH' ), 'Pending connection state should keep the device code visible in the no-JavaScript fallback.' );
	$codex_provider_pending = PendingConnectionRepository::get_for_user( $codex_provider_temporary_user_id );
	$codex_provider_pending_verification_url = is_array( $codex_provider_pending ) ? (string) ( $codex_provider_pending['verificationUrl'] ?? '' ) : '';
	$codex_provider_assert( '' !== $codex_provider_pending_verification_url, 'Pending connection fixture should include a runtime-provided verification URL.' );
	$codex_provider_assert( false !== strpos( $codex_provider_connection_page_html, esc_url( $codex_provider_pending_verification_url ) ), 'Pending connection state should keep the runtime-provided verification URL visible in the no-JavaScript fallback.' );
```

After rendering the retryable completed pending state, add:

```php
$codex_provider_assert( false !== strpos( $codex_provider_connection_page_html, 'Retry account sync' ), 'Retryable completed pending state should still render Retry account sync.' );
$codex_provider_assert( false !== strpos( $codex_provider_connection_page_html, 'Snapshot refresh failed during verification.' ), 'Retryable completed pending state should preserve the stored sync error.' );
```

- [ ] **Step 4: Run full verification**

Run:

```bash
bash scripts/verify.sh
```

Expected: PHP lint, release checks, JavaScript syntax checks, Node controller tests, and WP-CLI verification pass.

If the local WordPress install is not at the default path, run:

```bash
WP_PATH=/path/to/wordpress bash scripts/verify.sh
```

Expected: same pass result.

- [ ] **Step 5: Commit if committing incrementally**

Run:

```bash
git status --short
git add scripts/verify.php scripts/verify.sh
git diff --cached --name-only
git commit -m "test: verify codex connect ux"
```

Expected staged paths:

```text
scripts/verify.php
scripts/verify.sh
```

---

### Task 6: Manual Browser Verification

**Files:**
- No code files.
- Runtime verification against local WordPress admin.

- [ ] **Step 1: Run static and local verifier**

Run:

```bash
bash scripts/verify.sh
```

Expected: script exits `0`.

- [ ] **Step 2: Verify Settings > Connectors happy path on desktop Chromium**

In WordPress admin:

1. Open Settings > Connectors.
2. Click Connect or Reconnect on the Codex connector.
3. Confirm a new browser context opens immediately.
4. Confirm the connector card shows "Waiting for ChatGPT approval..." and the device code.
5. Approve the Codex login in the verification page.
6. Return to WordPress and confirm the card changes to Connected and shows Manage without a manual page refresh.

Record the verification result in the implementation summary.

- [ ] **Step 3: Verify popup-blocked/manual fallback path**

Run the same connector flow with popup blocking enabled, by forcing `window.open` to return `null` in DevTools, and by forcing an opened popup handle to reject navigation.

Expected:

```text
The connector card keeps the code visible, shows "Your browser blocked the verification tab. Open it manually.", exposes a manual verification link, and continues polling whether the popup was blocked outright or opened but could not be navigated.
```

- [ ] **Step 4: Verify the per-user page**

Open Users > Codex Provider:

1. Click Connect Codex account.
2. Confirm the code remains visible.
3. Confirm the Copy code button reports success or fallback accurately.
4. Confirm the Open verification page link remains a normal `_blank` anchor.
5. Approve login and return to the WordPress tab.
6. Confirm the status updates automatically or after Check status if the browser suspended the page.

Record the verification result in the implementation summary.

---

## Self-Review

- Spec coverage: Tasks 1 and 2 cover popup, clipboard, polling, reconciliation, missing, focus resume, and timeout. Tasks 3 and 4 cover both required UI entry points. Task 5 covers static/repo verification. Task 6 covers required manual browser checks.
- Review finding coverage: Task 1 now covers both blocked popups and opened popups that cannot be navigated. Task 4 now renders stable first-start enhancement targets, resumes existing pending sessions through the shared controller, updates connected/retry states without forcing a page reload, guards enhanced anchor starts against duplicates, and hex-encodes JSON script data. Task 5 now verifies user-page script-module enqueue behavior and uses the stored pending fixture URL instead of asserting a fixed verification domain.
- Backend scope: The plan does not change sidecar authentication, account storage, REST route contracts, per-user isolation, or no-JavaScript fallback actions.
- Browser constraints: The controller opens `about:blank` synchronously and avoids `noopener`/`noreferrer` in `window.open`; manual anchors still use `rel="noopener noreferrer"`.
- Verification URL: Production code and PHP verification assertions use `verificationUrl` returned by the runtime or stored pending fixture; no fixed ChatGPT/OpenAI verification domain is introduced into implementation logic.
- Placeholder scan: No placeholder markers or undefined task names remain.
