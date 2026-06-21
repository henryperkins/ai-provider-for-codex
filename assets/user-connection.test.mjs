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
	const configElement = new FakeElement( { id: 'wp-script-module-data-htperkins-ai-provider-for-codex/user-connection' } );
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

test( 'keeps the device code and manual status check visible after polling times out', async () => {
	const originalDateNow = Date.now;
	let now = 0;
	Date.now = () => now;

	try {
		const fixture = buildFixture( {
			currentPending: {
				authSessionId: 'auth_timeout',
				status: 'pending',
				verificationUrl: 'https://auth.example.test/device',
				userCode: 'TIME-1234',
				error: '',
			},
		} );

		await loadUserConnection( fixture );

		now = 600001;
		await fixture.timers[ 0 ].callback();

		assert.equal( fixture.elements.terminalText.hidden, false );
		assert.equal( fixture.elements.terminalText.textContent, DEFAULT_TEXT.timedOut );
		assert.equal( fixture.elements.codeText.hidden, false );
		assert.equal( fixture.elements.codeText.textContent, 'TIME-1234' );
		assert.equal( fixture.elements.codeActions.hidden, false );
		assert.equal( fixture.elements.checkButton.hidden, false );
		assert.equal( fixture.elements.openLink.hidden, false );
		assert.equal( fixture.elements.openLink.href, 'https://auth.example.test/device' );
	} finally {
		Date.now = originalDateNow;
	}
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
