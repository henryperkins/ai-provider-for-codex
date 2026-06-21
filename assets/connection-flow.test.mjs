import assert from 'node:assert/strict';
import { test } from 'node:test';

import { createCodexConnectionFlow } from './connection-flow.js';

const START_URL = 'https://example.test/wp-json/htperkins-aipfc/v1/connect/start';
const CONNECT_STATUS_URL = 'https://example.test/wp-json/htperkins-aipfc/v1/connect/status';
const PROVIDER_STATUS_URL = 'https://example.test/wp-json/htperkins-aipfc/v1/status';

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
					closed: Boolean( options.popupClosed ),
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
			const nextPollResponse = pollResponses.shift() ?? { status: 'pending' };

			if ( nextPollResponse?.ok === false ) {
				return jsonResponse( nextPollResponse.body ?? {}, false, nextPollResponse.status ?? 400 );
			}

			return jsonResponse( nextPollResponse );
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
		ClipboardItem: options.clipboardItemSupported
			? class ClipboardItem {
					constructor( data ) {
						this.data = data;
					}
			  }
			: undefined,
		Blob: options.clipboardItemSupported
			? class Blob {
					constructor( parts ) {
						this.parts = parts;
					}

					async text() {
						return this.parts.join( '' );
					}
			  }
			: undefined,
	};

	const clipboard = options.clipboardUnsupported
		? undefined
		: {
				write: options.clipboardItemSupported
					? async ( items ) => {
							sequence.push( 'clipboard-write' );
							const item = items[ 0 ];
							const blob = await item.data[ 'text/plain' ];
							const text =
								typeof blob?.text === 'function'
									? await blob.text()
									: String( blob ?? '' );
							sequence.push( `clipboard-write:${ text }` );
					  }
					: undefined,
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

test( 'pre-arms ClipboardItem copy before connect/start resolves on supporting browsers', async () => {
	const harness = createHarness( { clipboardItemSupported: true } );

	await harness.flow.start();

	assert.deepEqual( harness.sequence.slice( 0, 3 ), [
		'open',
		'clipboard-write',
		`fetch:${ START_URL }`,
	] );
	assert.ok( harness.sequence.includes( 'clipboard-write:ABCD-EFGH' ) );
	assert.ok( ! harness.sequence.includes( 'clipboard:ABCD-EFGH' ) );
	assert.equal( lastState( harness ).copyStatus, 'copied' );
} );

test( 'handles an already-stored auth snapshot from connect/start without starting a pending login', async () => {
	const harness = createHarness( {
		startResponse: {
			status: 'connected',
			connection: { account_email: 'stored@example.test', plan_type: 'plus' },
			snapshot: { defaultModel: 'gpt-5-codex' },
		},
	} );

	await harness.flow.start();

	assert.equal( lastState( harness ).status, 'connected' );
	assert.equal( lastState( harness ).connection.account_email, 'stored@example.test' );
	assert.equal( lastState( harness ).catalog.defaultModel, 'gpt-5-codex' );
	assert.equal( harness.popup.closeCalled, true );
	assert.equal( harness.timers.size, 0 );
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

test( 'treats a user-closed placeholder popup as blocked so manual verification remains visible', async () => {
	const harness = createHarness( { popupClosed: true } );

	await harness.flow.start();

	assert.equal( lastState( harness ).status, 'pending' );
	assert.equal( lastState( harness ).popupStatus, 'blocked' );
	assert.equal( lastState( harness ).verificationUrl, 'https://auth.openai.com/codex/device' );
	assert.equal( harness.popup.location.href, 'about:blank' );
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
