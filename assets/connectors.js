import {
	__experimentalConnectorItem as ConnectorItem,
	__experimentalRegisterConnector as registerConnector,
} from '@wordpress/connectors';

const config = window.aiProviderForCodexConnectors;
const wp = window.wp || {};

if (
	config &&
	wp.apiFetch &&
	wp.components &&
	wp.element &&
	wp.i18n
) {
	const { Button, Spinner } = wp.components;
	const { createElement, Fragment, useEffect, useState } = wp.element;
	const { __ } = wp.i18n;
	const apiFetch = wp.apiFetch;

	function CodexConnector( props ) {
		const [ status, setStatus ] = useState( null );
		const [ isLoading, setIsLoading ] = useState( true );
		const [ isBusy, setIsBusy ] = useState( false );

		const loadStatus = () => {
			setIsLoading( true );

			return apiFetch( { path: config.statusPath } )
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

			apiFetch( {
				path: config.startConnectPath,
				method: 'POST',
				data: { returnUrl: config.userConnectionUrl },
			} )
				.then( ( response ) => {
					if ( response && response.connectUrl ) {
						window.location.assign( response.connectUrl );
					}
				} )
				.catch( () => {
					setIsBusy( false );
				} );
		};

		let actionArea;

		if ( isLoading ) {
			actionArea = createElement( Spinner );
		} else if ( ! status || ! status.siteConfigured ) {
			actionArea = createElement(
				Button,
				{
					variant: 'secondary',
					href: config.siteSettingsUrl,
				},
				__( 'Set up', 'ai-provider-for-codex' )
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
					__( 'Edit', 'ai-provider-for-codex' )
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
