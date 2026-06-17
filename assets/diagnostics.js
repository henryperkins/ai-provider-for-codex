const INDICATOR_CLASS = Object.freeze( {
	pass: 'good',
	warn: 'warning',
	fail: 'error',
} );

export function mapDiagnosticsView( result, labels = {} ) {
	const rows = ( result?.rows || [] ).map( ( row ) => ( {
		label: row.label || '',
		status: row.status || 'fail',
		detail: row.detail || '',
		indicatorClass: INDICATOR_CLASS[ row.status ] || 'error',
	} ) );

	const ok = Boolean( result?.ok );

	return {
		overall: {
			status: ok ? 'pass' : 'fail',
			label: ok ? labels.healthy || '' : labels.issues || '',
		},
		rows,
		config: result?.config || [],
	};
}

const MODULE_ID = 'scriptorium-ai-provider-for-codex/diagnostics';
const documentRef = typeof document !== 'undefined' ? document : null;
const configElement = documentRef?.getElementById( `wp-script-module-data-${ MODULE_ID }` );
const runButton = documentRef?.querySelector( '[data-codex-diagnostics-run]' );
const resultsRoot = documentRef?.querySelector( '[data-codex-diagnostics-results]' );

if ( configElement && runButton && resultsRoot ) {
	const config = JSON.parse( configElement.textContent || '{}' );
	const labels = config.labels || {};

	const renderView = ( view ) => {
		resultsRoot.textContent = '';

		const overall = document.createElement( 'p' );
		const dot = document.createElement( 'span' );
		dot.className = `codex-indicator ${ INDICATOR_CLASS[ view.overall.status ] || 'error' }`;
		overall.appendChild( dot );
		overall.appendChild( document.createTextNode( view.overall.label ) );
		resultsRoot.appendChild( overall );

		const list = document.createElement( 'ul' );
		view.rows.forEach( ( row ) => {
			const item = document.createElement( 'li' );
			const indicator = document.createElement( 'span' );
			indicator.className = `codex-indicator ${ row.indicatorClass }`;
			item.appendChild( indicator );
			const text = row.detail ? `${ row.label }: ${ row.detail }` : row.label;
			item.appendChild( document.createTextNode( text ) );
			list.appendChild( item );
		} );
		resultsRoot.appendChild( list );

		view.config.forEach( ( entry ) => {
			const line = document.createElement( 'p' );
			line.className = 'description';
			line.textContent = `${ entry.label }: ${ entry.value }`;
			resultsRoot.appendChild( line );
		} );
	};

	runButton.addEventListener( 'click', async () => {
		runButton.disabled = true;
		const previous = runButton.textContent;
		runButton.textContent = labels.running || previous;

		try {
			const response = await window.fetch( config.diagnosticsUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					Accept: 'application/json',
					'X-WP-Nonce': config.restNonce || '',
				},
			} );
			const body = await response.json();
			renderView( mapDiagnosticsView( body, labels ) );
		} catch ( error ) {
			resultsRoot.textContent = labels.failed || 'The diagnostic request failed.';
			window.console?.warn?.( 'Codex diagnostics request failed.', error );
		} finally {
			runButton.disabled = false;
			runButton.textContent = previous;
		}
	} );
}
