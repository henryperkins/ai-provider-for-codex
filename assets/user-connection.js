import {
	createCodexConnectionFlow,
	getCodexConnectionPendingSupportText,
} from './connection-flow.js';

const MODULE_ID = 'scriptorium-ai-provider-for-codex/user-connection';
const configElement = document.getElementById( `wp-script-module-data-${ MODULE_ID }` );
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
	const connectedActions = root.querySelector( '[data-codex-connected-actions]' );
	const refreshStatusLink = root.querySelector( '[data-codex-refresh-status]' );
	const disconnectLink = root.querySelector( '[data-codex-disconnect]' );
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

	const renderConnectedDetails = ( state ) => {
		const connection = state.connection || {};
		const catalog = state.catalog || {};
		const selectedModel = catalog.selected_model || catalog.defaultModel || '';

		setText( connectedEmail, connection.account_email || connection.accountEmail || '' );
		setText( connectedPlan, connection.plan_type || connection.planType || '' );
		setText( connectedModel, selectedModel );
		setHidden(
			connectedDetails,
			! (
				connection.account_email ||
				connection.accountEmail ||
				connection.plan_type ||
				connection.planType ||
				selectedModel
			)
		);
	};

	const renderTerminalActions = ( state ) => {
		const showRetry = state.status === 'sync_retry';
		const showStartAgain = [ 'failed', 'missing', 'timed_out' ].includes( state.status );

		setHidden( retrySyncLink, ! showRetry );
		setHidden( startAgainLink, ! showStartAgain );
		setHidden( terminalActions, ! showRetry && ! showStartAgain );
		hideConnectedActions();
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

		if ( [ 'pending', 'timed_out' ].includes( state.status ) ) {
			renderCode( state );
		} else {
			hideCode();
		}

		if ( state.status === 'starting' ) {
			setText( heading, config.text.heading );
			setStatusText( config.text.starting );
			setHidden( terminalText, true );
			setHidden( connectedDetails, true );
			setHidden( terminalActions, true );
			hideConnectedActions();
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
			hideConnectedActions();
			setStartButtonsBusy( false );

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
			showConnectedActions();
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
