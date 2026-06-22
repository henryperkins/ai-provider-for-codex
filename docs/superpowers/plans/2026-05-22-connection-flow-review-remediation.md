# Connection Flow Review Remediation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Resolve all regressions found in the uncommitted enhanced Codex account connection flow review.

**Architecture:** Keep `assets/connection-flow.js` as the shared state controller, add a small shared pending-message helper, and make the DOM/React adapters render terminal and connected states explicitly. Add Node tests for the shared controller and the per-user DOM adapter, then wire those tests into `scripts/verify.sh` so these regressions stay covered.

**Tech Stack:** WordPress PHP 7.4+, WordPress admin script modules, `@wordpress/connectors`, browser `fetch`, Node built-in `node:test`.

---

## Reviewed Findings And Root Causes

### Finding 1: Terminal states can show a stale device code

**Root issue:** `assets/user-connection.js` calls `renderCode()` before state-specific rendering whenever `state.userCode` or `state.verificationUrl` exists. Terminal branches such as `sync_retry`, `failed`, and `missing` never clear the code block, code actions, or verification link. Completed and error pending sessions can therefore display an already-used or failed device code.

**Proof mechanism:** Add a DOM adapter test that loads `assets/user-connection.js` with a server-rendered `currentPending.status = "completed"` fixture that still contains `userCode` and `verificationUrl`. The test must assert that the enhanced console shows the sync-retry message and retry action, while `[data-codex-connection-code]`, `[data-codex-code-actions]`, and `[data-codex-open-verification]` remain hidden. This test fails before the remediation because the current renderer makes those elements visible.

**Complete fix:** Render code only while `state.status === "pending"`. Hide and clear code/action/link UI for every non-pending state before rendering terminal branches.

### Finding 2: Inline connected state hides account actions

**Root issue:** `assets/user-connection.js` hides `[data-codex-base-actions]` when enhanced rendering starts. The connected branch updates the status and connected details, but it does not render replacement actions for Refresh status or Disconnect. After an inline successful connect, the user loses account-management actions until a full page reload.

**Proof mechanism:** Add a DOM adapter test that starts a connection through the enhanced start button, advances the fake polling timer to a `status: "connected"` response, and asserts that a new `[data-codex-connected-actions]` container is visible with Refresh status and Disconnect links while the server fallback actions remain hidden. This test fails before the remediation because no connected action container exists and the connected branch never exposes one.

**Complete fix:** Add connected-state action markup to `src/Admin/UserConnectionPage.php` inside the enhanced console. Teach `assets/user-connection.js` to show it only when `state.status === "connected"` and hide it for all other states.

### Finding 3: Polling errors are captured but not shown

**Root issue:** `assets/connection-flow.js` catches REST, nonce, runtime, and network failures from `/connect/status`, emits `status: "pending"` with `state.error`, and keeps polling. Both renderers ignore that pending `state.error`: `assets/user-connection.js` hides the terminal text unless clipboard or popup status changed, and `assets/connectors.js` shows "Return to this tab after approving." unless clipboard failed. Users can wait until timeout without seeing the real problem.

**Proof mechanism:** Add a shared-controller test proving that a `/connect/status` HTTP 400 response keeps the flow pending and emits the error message. Add DOM adapter coverage proving pending `state.error` is visible on the per-user page. Add verifier source assertions proving both `assets/user-connection.js` and `assets/connectors.js` call the shared pending-message helper.

**Complete fix:** Export one helper from `assets/connection-flow.js` that prioritizes `state.error`, then popup and clipboard status messages. Use that helper in both renderers.

---

## File Structure

- Modify: `assets/connection-flow.js`
  - Keep connection state machine behavior.
  - Export `getCodexConnectionPendingSupportText()` for renderer message priority.

- Modify: `assets/connection-flow.test.mjs`
  - Add coverage for failed `/connect/status` responses emitting visible pending error state.
  - Add coverage for pending-message helper priority.

- Create: `assets/user-connection.test.mjs`
  - Test the per-user page progressive-enhancement adapter with a small fake DOM and fake browser APIs.
  - Cover stale-code hiding, pending error display, and connected action rendering.

- Modify: `assets/user-connection.js`
  - Use the shared pending support text helper.
  - Render device-code UI only in pending state.
  - Render connected actions in connected state.

- Modify: `assets/connectors.js`
  - Use the shared pending support text helper in the connector card pending action area.

- Modify: `src/Admin/UserConnectionPage.php`
  - Add enhanced connected action links inside `[data-codex-connection-console]`.

- Modify: `scripts/verify.sh`
  - Add the new Node test.

- Modify: `scripts/verify.php`
  - Add source-level assertions that both JS adapters use the shared helper and that the PHP page renders connected action selectors.

---

### Task 1: Shared Pending Error Priority

**Files:**
- Modify: `assets/connection-flow.js`
- Modify: `assets/connection-flow.test.mjs`

- [ ] **Step 1: Add failing tests for pending error state and message priority**

Append these tests to `assets/connection-flow.test.mjs`:

```js
test( 'keeps polling after connect/status failures and emits the pending error', async () => {
	const harness = createHarness( {
		pollResponses: [
			{
				ok: false,
				status: 400,
				body: {
					error: {
						message: 'Runtime temporarily unavailable.',
					},
				},
			},
		],
	} );

	await harness.flow.start();
	await harness.runNextTimer();

	assert.equal( lastState( harness ).status, 'pending' );
	assert.equal( lastState( harness ).error, 'Runtime temporarily unavailable.' );
	assert.equal( harness.timers.size, 1 );
} );

test( 'pending support text prioritizes status errors over clipboard and popup messages', async () => {
	const { getCodexConnectionPendingSupportText } = await import( './connection-flow.js' );
	const labels = {
		copied: 'Code copied.',
		copyFailed: 'Copy failed.',
		popupBlocked: 'Popup blocked.',
		defaultText: 'Return after approving.',
	};

	assert.equal(
		getCodexConnectionPendingSupportText(
			{
				status: 'pending',
				error: 'Runtime temporarily unavailable.',
				copyStatus: 'copied',
				popupStatus: 'blocked',
			},
			labels
		),
		'Runtime temporarily unavailable.'
	);

	assert.equal(
		getCodexConnectionPendingSupportText(
			{
				status: 'pending',
				error: '',
				copyStatus: 'failed',
				popupStatus: 'idle',
			},
			labels
		),
		'Copy failed.'
	);

	assert.equal(
		getCodexConnectionPendingSupportText(
			{
				status: 'pending',
				error: '',
				copyStatus: 'idle',
				popupStatus: 'blocked',
			},
			labels
		),
		'Popup blocked.'
	);
} );
```

Update the `CONNECT_STATUS_URL` branch in `createHarness()` so the first test can return an HTTP error:

```js
if ( CONNECT_STATUS_URL === url ) {
	const nextPollResponse = pollResponses.shift() ?? { status: 'pending' };

	if ( nextPollResponse?.ok === false ) {
		return jsonResponse( nextPollResponse.body ?? {}, false, nextPollResponse.status ?? 400 );
	}

	return jsonResponse( nextPollResponse );
}
```

- [ ] **Step 2: Run tests to verify the helper test fails**

Run:

```bash
node --test assets/connection-flow.test.mjs
```

Expected: FAIL with an import or assertion failure for `getCodexConnectionPendingSupportText`, because the helper does not exist yet. The pending-error state test may already pass after the harness change, which is useful proof that the controller captures the error and the renderers are the missing layer.

- [ ] **Step 3: Add the shared helper**

Add this export near the top of `assets/connection-flow.js`, after `toErrorMessage()`:

```js
export function getCodexConnectionPendingSupportText( state, labels = {} ) {
	if ( state?.error ) {
		return state.error;
	}

	if ( state?.copyStatus === 'failed' ) {
		return labels.copyFailed || '';
	}

	if ( state?.popupStatus === 'blocked' ) {
		return labels.popupBlocked || '';
	}

	if ( state?.copyStatus === 'copied' ) {
		return labels.copied || '';
	}

	return labels.defaultText || '';
}
```

- [ ] **Step 4: Run tests to verify the helper passes**

Run:

```bash
node --test assets/connection-flow.test.mjs
```

Expected: PASS, including the new pending-error and support-text tests.

- [ ] **Step 5: Commit**

```bash
git add assets/connection-flow.js assets/connection-flow.test.mjs
git commit -m "test: cover connection flow pending error messages"
```

---

### Task 2: User Connection DOM Regression Tests

**Files:**
- Create: `assets/user-connection.test.mjs`

- [ ] **Step 1: Create the DOM adapter test file**

Create `assets/user-connection.test.mjs` with this content:

```js
import assert from 'node:assert/strict';
import { test } from 'node:test';

const DEFAULT_TEXT = {
	heading: 'Complete account connection',
	connectedHeading: 'Codex account connected',
	syncRetryHeading: 'Retry account sync',
	failedHeading: 'Connection needs attention',
	starting: 'Starting Codex login...',
	pending: 'Waiting for ChatGPT approval...',
	copied: 'Code copied.',
	copyFailed: 'Copy did not work in this browser. Select the code below.',
	popupBlocked: 'Your browser blocked the verification tab. Open it manually.',
	connected: 'Your Codex account is connected.',
	syncRetry: 'Your login was approved, but WordPress could not sync your Codex account yet.',
	missing: 'The local runtime no longer has this login session. Start again to get a fresh code.',
	timedOut: 'Still waiting. You can check again or start over.',
	failed: 'The local Codex runtime request failed.',
};

class FakeClassList {
	constructor() {
		this.values = new Set();
	}

	toggle( name, force ) {
		if ( force ) {
			this.values.add( name );
			return true;
		}

		this.values.delete( name );
		return false;
	}
}

class FakeElement {
	constructor( { id = '', attrs = {}, text = '' } = {} ) {
		this.id = id;
		this.attrs = { ...attrs };
		this.children = [];
		this.classList = new FakeClassList();
		this.disabled = false;
		this.eventListeners = new Map();
		this.hidden = false;
		this.href = '#';
		this.parentNode = null;
		this.textContent = text;
	}

	append( ...children ) {
		children.forEach( ( child ) => {
			child.parentNode = this;
			this.children.push( child );
		} );
		return this;
	}

	setAttribute( name, value ) {
		this.attrs[ name ] = String( value );
		if ( name === 'id' ) {
			this.id = String( value );
		}
	}

	removeAttribute( name ) {
		delete this.attrs[ name ];
		if ( name === 'href' ) {
			this.href = '';
		}
	}

	addEventListener( name, listener ) {
		const listeners = this.eventListeners.get( name ) ?? [];
		listeners.push( listener );
		this.eventListeners.set( name, listeners );
	}

	async click() {
		const listeners = this.eventListeners.get( 'click' ) ?? [];
		await Promise.all(
			listeners.map( ( listener ) =>
				listener( {
					defaultPrevented: false,
					preventDefault() {
						this.defaultPrevented = true;
					},
				} )
			)
		);
	}

	matches( selector ) {
		const attributeMatch = selector.match( /^\[([^\]]+)\]$/ );
		if ( attributeMatch ) {
			return Object.prototype.hasOwnProperty.call( this.attrs, attributeMatch[ 1 ] );
		}

		if ( selector.startsWith( '#' ) ) {
			return this.id === selector.slice( 1 );
		}

		return false;
	}

	querySelector( selector ) {
		return this.querySelectorAll( selector )[ 0 ] ?? null;
	}

	querySelectorAll( selector ) {
		const matches = [];
		const visit = ( node ) => {
			if ( node.matches( selector ) ) {
				matches.push( node );
			}
			node.children.forEach( visit );
		};
		this.children.forEach( visit );
		return matches;
	}
}

class FakeDocument extends FakeElement {
	constructor() {
		super();
		this.visibilityState = 'visible';
	}

	getElementById( id ) {
		return this.querySelector( `#${ id }` );
	}

	dispatchEvent() {}
}

function node( name, attrs = {}, text = '' ) {
	return new FakeElement( { attrs: { [ name ]: '', ...attrs }, text } );
}

function buildFixture( configOverrides = {}, fetchImpl = async () => jsonResponse( {} ) ) {
	const document = new FakeDocument();
	const configElement = new FakeElement( { id: 'codex-provider-connection-config' } );
	const root = node( 'data-codex-connection-root' );
	const consolePanel = node( 'data-codex-connection-console' );
	const heading = node( 'data-codex-connection-heading' );
	const consoleStatus = node( 'data-codex-connection-status' );
	const tableStatus = node( 'data-codex-connection-status' );
	const codeText = node( 'data-codex-connection-code' );
	const codeActions = node( 'data-codex-code-actions' );
	const copyButton = node( 'data-codex-copy-code' );
	const openLink = node( 'data-codex-open-verification' );
	const checkButton = node( 'data-codex-check-status' );
	const terminalText = node( 'data-codex-terminal-text' );
	const terminalActions = node( 'data-codex-terminal-actions' );
	const retrySyncLink = node( 'data-codex-retry-sync' );
	const startAgainLink = node( 'data-codex-start-again' );
	const connectedDetails = node( 'data-codex-connected-details' );
	const connectedEmail = node( 'data-codex-connected-email' );
	const connectedPlan = node( 'data-codex-connected-plan' );
	const connectedModel = node( 'data-codex-connected-model' );
	const connectedActions = node( 'data-codex-connected-actions' );
	const refreshStatusLink = node( 'data-codex-refresh-status' );
	const disconnectLink = node( 'data-codex-disconnect' );
	const serverFallback = node( 'data-codex-server-fallback' );
	const baseActions = node( 'data-codex-base-actions' );
	const startButton = node( 'data-codex-start-connect' );
	const timers = [];

	consolePanel.hidden = true;
	codeText.hidden = true;
	codeActions.hidden = true;
	openLink.hidden = true;
	checkButton.hidden = true;
	terminalText.hidden = true;
	terminalActions.hidden = true;
	retrySyncLink.hidden = true;
	startAgainLink.hidden = true;
	connectedDetails.hidden = true;
	connectedActions.hidden = true;

	codeActions.append( copyButton, openLink, checkButton );
	terminalActions.append( retrySyncLink, startAgainLink );
	connectedDetails.append( connectedEmail, connectedPlan, connectedModel );
	connectedActions.append( refreshStatusLink, disconnectLink );
	consolePanel.append(
		heading,
		consoleStatus,
		codeText,
		codeActions,
		terminalText,
		terminalActions,
		connectedDetails,
		connectedActions
	);
	baseActions.append( startButton );
	root.append( consolePanel, tableStatus, serverFallback, baseActions );

	configElement.textContent = JSON.stringify( {
		pageUrl: 'https://example.test/wp-admin/users.php?page=ai-provider-for-codex',
		startUrl: 'https://example.test/wp-json/htperkins-aipfc/v1/connect/start',
		connectStatusUrl: 'https://example.test/wp-json/htperkins-aipfc/v1/connect/status',
		providerStatusUrl: 'https://example.test/wp-json/htperkins-aipfc/v1/status',
		restNonce: 'nonce_123',
		currentPending: null,
		text: DEFAULT_TEXT,
		...configOverrides,
	} );

	document.append( configElement, root );

	const windowObject = {
		document,
		navigator: {
			clipboard: {
				writeText: async () => {},
			},
		},
		console: {
			warn() {},
		},
		fetch: fetchImpl,
		open() {
			return {
				closed: false,
				opener: {},
				location: {
					href: 'about:blank',
				},
			};
		},
		addEventListener() {},
		removeEventListener() {},
		setTimeout( callback, delay ) {
			timers.push( { callback, delay } );
			return timers.length;
		},
		clearTimeout() {},
		CustomEvent: class CustomEvent {
			constructor( type, init ) {
				this.type = type;
				this.detail = init?.detail;
			}
		},
	};

	return {
		document,
		elements: {
			baseActions,
			checkButton,
			codeActions,
			codeText,
			connectedActions,
			connectedDetails,
			connectedEmail,
			connectedModel,
			connectedPlan,
			consolePanel,
			disconnectLink,
			openLink,
			refreshStatusLink,
			retrySyncLink,
			startButton,
			terminalText,
		},
		timers,
		windowObject,
	};
}

function jsonResponse( body, ok = true, status = 200 ) {
	return {
		ok,
		status,
		json: async () => body,
	};
}

async function loadUserConnection( fixture ) {
	globalThis.document = fixture.document;
	globalThis.window = fixture.windowObject;
	await import( `./user-connection.js?case=${ Date.now() }-${ Math.random() }` );
}

test( 'hides stale device code and verification link for retryable completed sessions', async () => {
	const fixture = buildFixture( {
		currentPending: {
			authSessionId: 'auth_completed',
			status: 'completed',
			verificationUrl: 'https://auth.example.test/device',
			userCode: 'STALE-1234',
			error: 'Snapshot refresh failed during verification.',
		},
	} );

	await loadUserConnection( fixture );

	assert.equal( fixture.elements.consolePanel.hidden, false );
	assert.equal( fixture.elements.codeText.hidden, true );
	assert.equal( fixture.elements.codeActions.hidden, true );
	assert.equal( fixture.elements.openLink.hidden, true );
	assert.equal( fixture.elements.terminalText.hidden, false );
	assert.equal(
		fixture.elements.terminalText.textContent,
		'Snapshot refresh failed during verification.'
	);
	assert.equal( fixture.elements.retrySyncLink.hidden, false );
} );

test( 'shows pending poll errors while keeping the device code available', async () => {
	const fixture = buildFixture( {
		currentPending: {
			authSessionId: 'auth_pending',
			status: 'pending',
			verificationUrl: 'https://auth.example.test/device',
			userCode: 'CODE-1234',
			error: 'Runtime temporarily unavailable.',
		},
	} );

	await loadUserConnection( fixture );

	assert.equal( fixture.elements.codeText.hidden, false );
	assert.equal( fixture.elements.codeText.textContent, 'CODE-1234' );
	assert.equal( fixture.elements.terminalText.hidden, false );
	assert.equal( fixture.elements.terminalText.textContent, 'Runtime temporarily unavailable.' );
} );

test( 'shows connected account actions after an inline successful connection', async () => {
	const fixture = buildFixture( {}, async ( url ) => {
		if ( url.endsWith( '/connect/start' ) ) {
			return jsonResponse( {
				authSessionId: 'auth_inline',
				status: 'pending',
				verificationUrl: 'https://auth.example.test/device',
				userCode: 'LIVE-1234',
			} );
		}

		if ( url.endsWith( '/connect/status' ) ) {
			return jsonResponse( {
				status: 'connected',
				connection: {
					account_email: 'user@example.test',
					plan_type: 'plus',
				},
				catalog: {
					selected_model: 'gpt-5',
				},
			} );
		}

		return jsonResponse( {}, false, 404 );
	} );

	await loadUserConnection( fixture );
	await fixture.elements.startButton.click();
	assert.equal( fixture.timers.length, 1 );
	await fixture.timers[ 0 ].callback();

	assert.equal( fixture.elements.baseActions.hidden, true );
	assert.equal( fixture.elements.connectedActions.hidden, false );
	assert.equal( fixture.elements.refreshStatusLink.hidden, false );
	assert.equal( fixture.elements.disconnectLink.hidden, false );
	assert.equal( fixture.elements.connectedEmail.textContent, 'user@example.test' );
	assert.equal( fixture.elements.connectedPlan.textContent, 'plus' );
	assert.equal( fixture.elements.connectedModel.textContent, 'gpt-5' );
} );
```

- [ ] **Step 2: Run tests to verify current regressions fail**

Run:

```bash
node --test assets/user-connection.test.mjs
```

Expected: FAIL. The stale-code test should fail because terminal states still expose the code. The pending-error test should fail because `terminalText` remains hidden. The connected-actions test should fail because no connected action rendering exists.

- [ ] **Step 3: Commit the failing tests**

```bash
git add assets/user-connection.test.mjs
git commit -m "test: capture user connection rendering regressions"
```

---

### Task 3: Fix User Connection Rendering

**Files:**
- Modify: `assets/user-connection.js`
- Modify: `src/Admin/UserConnectionPage.php`

- [ ] **Step 1: Import the shared pending-message helper**

Change the import at the top of `assets/user-connection.js`:

```js
import {
	createCodexConnectionFlow,
	getCodexConnectionPendingSupportText,
} from './connection-flow.js';
```

- [ ] **Step 2: Add connected-action selectors and reset helpers**

In `assets/user-connection.js`, add these selectors next to the existing connected detail selectors:

```js
const connectedActions = root.querySelector( '[data-codex-connected-actions]' );
const refreshStatusLink = root.querySelector( '[data-codex-refresh-status]' );
const disconnectLink = root.querySelector( '[data-codex-disconnect]' );
```

Add these helpers after `renderCode()`:

```js
const hideCode = () => {
	setText( codeText, '' );
	setHidden( codeText, true );
	setHidden( codeActions, true );
	setHidden( checkButton, true );

	if ( openLink ) {
		openLink.href = '#';
		openLink.hidden = true;
	}
};

const hideConnectedActions = () => {
	setHidden( connectedActions, true );
	setHidden( refreshStatusLink, true );
	setHidden( disconnectLink, true );
};

const showConnectedActions = () => {
	setHidden( connectedActions, false );
	setHidden( refreshStatusLink, false );
	setHidden( disconnectLink, false );
};
```

- [ ] **Step 3: Render code only for pending states**

Replace this block in `renderState()`:

```js
if ( state.userCode || state.verificationUrl ) {
	renderCode( state );
}
```

with this block:

```js
if ( state.status === 'pending' ) {
	renderCode( state );
} else {
	hideCode();
}
```

- [ ] **Step 4: Show pending errors and hide connected actions while pending**

Replace the support-text block in the pending branch:

```js
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
```

with:

```js
hideConnectedActions();

const pendingSupportText = getCodexConnectionPendingSupportText( state, {
	copied: config.text.copied,
	copyFailed: config.text.copyFailed,
	popupBlocked: config.text.popupBlocked,
} );

if ( pendingSupportText ) {
	setText( terminalText, pendingSupportText );
	setHidden( terminalText, false );
} else {
	setText( terminalText, '' );
	setHidden( terminalText, true );
}
```

- [ ] **Step 5: Show connected actions in connected state**

In the connected branch of `renderState()`, after `renderConnectedDetails( state );`, add:

```js
showConnectedActions();
```

In the `starting`, `sync_retry`, `missing`, `timed_out`, and `failed` branches, add `hideConnectedActions();` before returning.

- [ ] **Step 6: Add connected-action markup to the enhanced console**

In `src/Admin/UserConnectionPage.php`, inside the `[data-codex-connection-console]` block after the existing `<dl data-codex-connected-details hidden>...</dl>`, add:

```php
<p data-codex-connected-actions hidden>
	<a class="button button-secondary" data-codex-refresh-status href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'action', 'refresh-status', self::page_url() ), 'codex-provider-refresh-status' ) ); ?>" hidden><?php esc_html_e( 'Refresh status', 'ai-provider-for-codex' ); ?></a>
	<a class="button button-link-delete" data-codex-disconnect href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'action', 'disconnect', self::page_url() ), 'codex-provider-disconnect' ) ); ?>" hidden><?php esc_html_e( 'Disconnect Codex account', 'ai-provider-for-codex' ); ?></a>
</p>
```

- [ ] **Step 7: Run tests to verify user rendering passes**

Run:

```bash
node --test assets/user-connection.test.mjs
node --input-type=module --check < assets/user-connection.js
php -l src/Admin/UserConnectionPage.php
```

Expected: PASS for all commands.

- [ ] **Step 8: Commit**

```bash
git add assets/user-connection.js src/Admin/UserConnectionPage.php
git commit -m "fix: render user connection terminal and connected states"
```

---

### Task 4: Fix Connector Pending Error Rendering

**Files:**
- Modify: `assets/connectors.js`
- Modify: `scripts/verify.php`

- [ ] **Step 1: Import the shared helper in the connector card**

Change the import at the top of `assets/connectors.js`:

```js
import {
	createCodexConnectionFlow,
	getCodexConnectionPendingSupportText,
} from './connection-flow.js';
```

- [ ] **Step 2: Use the helper for pending support text**

Inside `renderPendingFlow()`, before returning the JSX-free `createElement()` tree, add:

```js
const pendingSupportText = getCodexConnectionPendingSupportText( nextState, {
	copied: __( 'Code copied.', 'ai-provider-for-codex' ),
	copyFailed: __( 'Copy did not work in this browser. Select the code below.', 'ai-provider-for-codex' ),
	popupBlocked: __( 'Your browser blocked the verification tab. Open it manually.', 'ai-provider-for-codex' ),
	defaultText: __( 'Return to this tab after approving.', 'ai-provider-for-codex' ),
} );
```

Replace the current nested copy-status ternary in the pending `<p>` with:

```js
pendingSupportText
```

- [ ] **Step 3: Add verifier source assertions**

In `scripts/verify.php`, near the existing user page source assertions, add:

```php
$codex_provider_connection_flow_source = (string) file_get_contents( dirname( __DIR__ ) . '/assets/connection-flow.js' );
$codex_provider_connector_source       = (string) file_get_contents( dirname( __DIR__ ) . '/assets/connectors.js' );
$codex_provider_user_connection_source = (string) file_get_contents( dirname( __DIR__ ) . '/assets/user-connection.js' );

$codex_provider_assert( false !== strpos( $codex_provider_connection_flow_source, 'getCodexConnectionPendingSupportText' ), 'Connection flow should expose a shared pending support-text helper.' );
$codex_provider_assert( false !== strpos( $codex_provider_connector_source, 'getCodexConnectionPendingSupportText' ), 'Connectors card should use the shared pending support-text helper so polling errors are visible.' );
$codex_provider_assert( false !== strpos( $codex_provider_user_connection_source, 'getCodexConnectionPendingSupportText' ), 'User connection page should use the shared pending support-text helper so polling errors are visible.' );
$codex_provider_assert( false !== strpos( $codex_provider_user_page_source, 'data-codex-connected-actions' ), 'User connection page should render enhanced connected-state actions.' );
$codex_provider_assert( false !== strpos( $codex_provider_user_page_source, 'data-codex-refresh-status' ), 'Enhanced connected state should expose a Refresh status action.' );
$codex_provider_assert( false !== strpos( $codex_provider_user_page_source, 'data-codex-disconnect' ), 'Enhanced connected state should expose a Disconnect action.' );
```

- [ ] **Step 4: Run syntax checks and verifier PHP lint**

Run:

```bash
node --input-type=module --check < assets/connectors.js
php -l scripts/verify.php
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add assets/connectors.js scripts/verify.php
git commit -m "fix: show connector polling errors"
```

---

### Task 5: Wire Verification

**Files:**
- Modify: `scripts/verify.sh`

- [ ] **Step 1: Add the new Node DOM adapter test to the verifier**

In `scripts/verify.sh`, add this command after the existing `node --test "$ROOT_DIR/assets/connection-flow.test.mjs"` line:

```bash
node --test "$ROOT_DIR/assets/user-connection.test.mjs"
```

- [ ] **Step 2: Run local fast verification**

Run:

```bash
node --input-type=module --check < assets/connection-flow.js
node --input-type=module --check < assets/connectors.js
node --input-type=module --check < assets/user-connection.js
node --test assets/connection-flow.test.mjs
node --test assets/user-connection.test.mjs
php -l src/Admin/UserConnectionPage.php
php -l src/Admin/ConnectorsIntegration.php
php -l scripts/verify.php
git diff --check
```

Expected: PASS for all commands.

- [ ] **Step 3: Run full verifier with the correct WordPress path**

The previous review attempt showed the default verifier path is not a WordPress install on this machine:

```text
Error: This does not seem to be a WordPress installation.
The used path is: /home/hperkins-wp/htdocs/wp.hperkins.com/
```

Run the verifier with a discovered WordPress path:

```bash
WP_LOAD="$(find /home/dev /home/hperkins-wp -path '*/wp-load.php' -print -quit 2>/dev/null)"
if [ -z "$WP_LOAD" ]; then
	echo "No WordPress checkout with wp-load.php was found under /home/dev or /home/hperkins-wp." >&2
	exit 1
fi
WP_PATH="$(dirname "$WP_LOAD")" scripts/verify.sh
```

Expected: PASS when a usable WordPress checkout is present. If discovery fails, record the exact "No WordPress checkout" message in the final handoff and do not claim full verifier success.

- [ ] **Step 4: Commit**

```bash
git add scripts/verify.sh
git commit -m "test: include user connection DOM adapter verification"
```

---

## Final Acceptance Criteria

- Terminal enhanced user connection states do not display stale device code, copy button, or verification link.
- Pending polling errors are visible on the per-user page.
- Pending polling errors are visible on the Settings > Connectors card.
- Inline successful connection leaves the per-user page with visible Refresh status and Disconnect actions.
- `node --test assets/connection-flow.test.mjs` passes.
- `node --test assets/user-connection.test.mjs` passes.
- `scripts/verify.sh` runs both Node test files.
- `git diff --check` passes.
- Full `scripts/verify.sh` passes when `WP_PATH` points to a real WordPress installation.

## Self-Review

**Spec coverage:** Every reviewed finding has a root-cause statement, a failing proof mechanism, concrete remediation instructions, and acceptance criteria.

**Placeholder scan:** This plan contains no deferred work markers and no unspecified "add tests" steps. Each code-writing step includes exact code or exact replacement instructions.

**Type consistency:** The shared helper name is consistently `getCodexConnectionPendingSupportText`. The new connected action selectors are consistently `data-codex-connected-actions`, `data-codex-refresh-status`, and `data-codex-disconnect`.
