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
	const { createElement, Fragment, useEffect, useState } = wpPackages.element;
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

		useEffect( () => {
			loadStatus();
		}, [] );

		const handleConnect = () => {
			setIsBusy( true );

			request( {
				url: config.startConnectUrl || config.startConnectPath,
				method: 'POST',
			} )
				.then( () => {
					window.location.assign( config.userConnectionUrl );
				} )
				.catch( () => {
					setIsBusy( false );
				} );
		};

		let actionArea;
		let setupLabel = __( 'Set up local runtime', 'ai-provider-for-codex' );

		if ( status?.reason === 'runtime_unreachable' ) {
			setupLabel = __( 'Check local runtime', 'ai-provider-for-codex' );
		}

		if ( isLoading ) {
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
