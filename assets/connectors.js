import {
	createCodexConnectionFlow,
	getCodexConnectionPendingSupportText,
} from './connection-flow.js';

import {
	__experimentalConnectorItem as ConnectorItem,
	__experimentalRegisterConnector as registerConnector,
} from '@wordpress/connectors';

const MODULE_ID = 'ai-provider-for-codex/connectors';
const MAX_BOOT_ATTEMPTS = 40;
const BOOT_DELAY_MS = 50;

let hasBooted = false;
let bootAttempts = 0;

function readModuleData() {
	const script = document.getElementById( `wp-script-module-data-${ MODULE_ID }` );

	if ( ! script || ! script.textContent ) {
		return null;
	}

	try {
		return JSON.parse( script.textContent );
	} catch ( error ) {
		return null;
	}
}

function getWpPackages() {
	const wp = window.wp || {};

	if ( ! wp.apiFetch || ! wp.components || ! wp.element || ! wp.i18n ) {
		return null;
	}

	return {
		apiFetch: wp.apiFetch,
		components: wp.components,
		element: wp.element,
		i18n: wp.i18n,
	};
}

function buildConnector( config, wpPackages ) {
	const { apiFetch } = wpPackages;
	const { Button, Spinner } = wpPackages.components;
	const { createElement, Fragment, useEffect, useRef, useState } = wpPackages.element;
	const { __ } = wpPackages.i18n;
	const restHeaders = config.restNonce ? { 'X-WP-Nonce': config.restNonce } : {};

	const request = ( args ) =>
		apiFetch( {
			headers: restHeaders,
			...args,
		} );

	function CodexConnector( props ) {
		const [ status, setStatus ] = useState( null );
		const [ isLoading, setIsLoading ] = useState( true );
		const [ isBusy, setIsBusy ] = useState( false );
		const [ flowState, setFlowState ] = useState( null );
		const flowRef = useRef( null );
		const pendingStatus = status?.pendingConnection?.status;

		const loadStatus = () => {
			setIsLoading( true );

			return request( { url: config.statusUrl || config.statusPath } )
				.then( ( response ) => {
					setStatus( response );
				} )
				.catch( () => {
					setStatus( null );
				} )
				.finally( () => {
					setIsLoading( false );
				} );
		};

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

		useEffect( () => {
			loadStatus();

			return () => {
				flowRef.current?.stop();
			};
		}, [] );

		const handleConnect = () => {
			setIsBusy( true );

			flowRef.current
				.start()
				.finally( () => {
					setIsBusy( false );
				} );
		};

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
							window.navigator?.clipboard?.writeText( nextState.userCode )?.catch( () => {} );
						},
					},
					__( 'Copy', 'ai-provider-for-codex' )
				)
			);

		const renderPendingFlow = ( nextState ) => {
			const pendingSupportText = getCodexConnectionPendingSupportText( nextState, {
				copied: __( 'Code copied.', 'ai-provider-for-codex' ),
				copyFailed: __( 'Copy did not work in this browser. Select the code below.', 'ai-provider-for-codex' ),
				popupBlocked: __( 'Your browser blocked the verification tab. Open it manually.', 'ai-provider-for-codex' ),
				defaultText: __( 'Return to this tab after approving.', 'ai-provider-for-codex' ),
			} );

			return createElement(
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
					pendingSupportText
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
		};

		const renderTerminalFlow = ( nextState ) =>
			createElement(
				'div',
				{ 'aria-live': 'polite' },
				createElement(
					'p',
					{ style: { margin: '0 0 8px' } },
					nextState.status === 'sync_retry'
						? __(
								'Your login was approved, but WordPress could not sync your Codex account yet.',
								'ai-provider-for-codex'
						  )
						: nextState.status === 'missing'
						? __(
								'The local runtime no longer has this login session. Start again to get a fresh code.',
								'ai-provider-for-codex'
						  )
						: nextState.status === 'timed_out'
						? __( 'Still waiting. You can check again or start over.', 'ai-provider-for-codex' )
						: nextState.error ||
						  __( 'The local Codex runtime request failed.', 'ai-provider-for-codex' )
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

		let actionArea;
		let setupLabel = __( 'Set up Codex runtime', 'ai-provider-for-codex' );

		if ( status?.reason === 'runtime_unreachable' ) {
			setupLabel = __( 'Check Codex runtime', 'ai-provider-for-codex' );
		}

		if ( flowState?.status === 'starting' ) {
			actionArea = createElement( Spinner );
		} else if ( flowState?.status === 'pending' ) {
			actionArea = renderPendingFlow( flowState );
		} else if ( [ 'sync_retry', 'failed', 'missing', 'timed_out' ].includes( flowState?.status ) ) {
			actionArea = renderTerminalFlow( flowState );
		} else if ( isLoading ) {
			actionArea = createElement( Spinner );
		} else if (
			! status ||
			! status.runtimeConfigured ||
			status.reason === 'runtime_unconfigured' ||
			status.reason === 'runtime_unreachable'
		) {
			actionArea = createElement(
				Button,
				{
					variant: 'secondary',
					href: config.siteSettingsUrl,
				},
					setupLabel
			);
		} else if ( status.reason === 'login_pending' && pendingStatus === 'completed' ) {
			actionArea = createElement(
				Button,
				{
					variant: 'primary',
					href: config.userConnectionUrl,
				},
				__( 'Retry account sync', 'ai-provider-for-codex' )
			);
		} else if ( status.reason === 'login_pending' ) {
			actionArea = createElement(
				Button,
				{
					variant: 'primary',
					href: config.userConnectionUrl,
				},
				__( 'Continue connecting', 'ai-provider-for-codex' )
			);
		} else if ( status.reason === 'login_failed' ) {
			actionArea = createElement(
				Button,
				{
					variant: 'secondary',
					href: config.userConnectionUrl,
				},
				__( 'Review error', 'ai-provider-for-codex' )
			);
		} else if ( ! status.connection || status.reason === 'connection_expired' ) {
			actionArea = createElement(
				Fragment,
				null,
				createElement(
					Button,
					{
						variant: 'primary',
						onClick: handleConnect,
						disabled: isBusy,
					},
					status.reason === 'connection_expired'
						? __( 'Reconnect', 'ai-provider-for-codex' )
						: __( 'Connect', 'ai-provider-for-codex' )
				)
			);
		} else {
			actionArea = createElement(
				Fragment,
				null,
				createElement(
					'span',
					{
						style: {
							display: 'inline-block',
							padding: '0 12px',
							lineHeight: '30px',
							fontSize: '13px',
							fontWeight: 500,
							color: '#1e1e1e',
						},
					},
					__( 'Connected', 'ai-provider-for-codex' )
				),
				createElement(
					Button,
					{
						variant: 'secondary',
						href: config.userConnectionUrl,
					},
					__( 'Manage', 'ai-provider-for-codex' )
				)
			);
		}

		return createElement(
			ConnectorItem,
			{
				logo: props.logo,
				name: props.name,
				description: props.description,
				actionArea,
			}
		);
	}

	registerConnector( config.connectorId || 'codex', {
		render: ( props ) => createElement( CodexConnector, props ),
	} );
}

function tryBoot() {
	if ( hasBooted ) {
		return;
	}

	const config = readModuleData();
	const wpPackages = getWpPackages();

	if ( config && wpPackages ) {
		buildConnector( config, wpPackages );
		hasBooted = true;
		return;
	}

	bootAttempts += 1;

	if ( bootAttempts >= MAX_BOOT_ATTEMPTS ) {
		window.console?.warn?.(
			'AI Provider for Codex connector did not initialize before timeout.'
		);
		return;
	}

	window.setTimeout( tryBoot, BOOT_DELAY_MS );
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', tryBoot, { once: true } );
} else {
	tryBoot();
}
