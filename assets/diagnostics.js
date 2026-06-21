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
		remediation: row.remediation || '',
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

// Turns an HTTP-level failure into a legible message. On an error status the
// body is a WordPress REST error envelope ({ code, message, data }), not a
// diagnostics result, so it must never be fed to mapDiagnosticsView (which
// would silently collapse it into an empty "Some checks failed." view).
export function mapRequestError( status, body, labels = {} ) {
	const httpStatus = Number( status ) || 0;
	const serverMessage =
		body && typeof body.message === 'string' ? body.message : '';
	const code = body && typeof body.code === 'string' ? body.code : '';

	if ( 0 === httpStatus ) {
		return {
			title: labels.networkError || 'Could not reach WordPress to run diagnostics.',
			detail: serverMessage,
			hint: labels.networkHint || 'Check your connection and try again.',
		};
	}

	let hint = '';
	if ( 'rest_cookie_invalid_nonce' === code ) {
		hint = labels.nonceHint || 'Your session may have expired. Reload this page and try again.';
	} else if ( 401 === httpStatus || 403 === httpStatus ) {
		hint = labels.permHint || 'You may not have permission to run diagnostics, or your session expired — reload the page and try again.';
	}

	const base = labels.requestFailed || 'The diagnostics request failed';

	return {
		title: `${ base } (HTTP ${ httpStatus }).`,
		detail: serverMessage,
		hint,
	};
}

const MODULE_ID = 'htperkins-ai-provider-for-codex/diagnostics';
const documentRef = typeof document !== 'undefined' ? document : null;
const configElement = documentRef?.getElementById( `wp-script-module-data-${ MODULE_ID }` );
const runButton = documentRef?.querySelector( '[data-codex-diagnostics-run]' );
const resultsRoot = documentRef?.querySelector( '[data-codex-diagnostics-results]' );

if ( configElement && runButton && resultsRoot ) {
	const config = JSON.parse( configElement.textContent || '{}' );
	const labels = config.labels || {};

	const appendRemediation = ( parent, text ) => {
		const remediation = document.createElement( 'p' );
		remediation.className = 'codex-remediation';
		remediation.textContent = text;
		parent.appendChild( remediation );
	};

	const renderView = ( view ) => {
		resultsRoot.textContent = '';

		const overall = document.createElement( 'p' );
		const dot = document.createElement( 'span' );
		dot.className = `codex-indicator ${ INDICATOR_CLASS[ view.overall.status ] || 'error' }`;
		overall.appendChild( dot );
		overall.appendChild( document.createTextNode( view.overall.label ) );
		resultsRoot.appendChild( overall );

		const list = document.createElement( 'ul' );
		list.className = 'codex-diagnostics-rows';
		view.rows.forEach( ( row ) => {
			const item = document.createElement( 'li' );
			const indicator = document.createElement( 'span' );
			indicator.className = `codex-indicator ${ row.indicatorClass }`;
			item.appendChild( indicator );
			const text = row.detail ? `${ row.label }: ${ row.detail }` : row.label;
			item.appendChild( document.createTextNode( text ) );
			if ( row.remediation ) {
				appendRemediation( item, row.remediation );
			}
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

	const renderRequestError = ( error ) => {
		resultsRoot.textContent = '';

		const title = document.createElement( 'p' );
		const dot = document.createElement( 'span' );
		dot.className = 'codex-indicator error';
		title.appendChild( dot );
		title.appendChild( document.createTextNode( error.title ) );
		resultsRoot.appendChild( title );

		if ( error.detail ) {
			const detail = document.createElement( 'p' );
			detail.className = 'description';
			detail.textContent = error.detail;
			resultsRoot.appendChild( detail );
		}

		if ( error.hint ) {
			appendRemediation( resultsRoot, error.hint );
		}
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

			let body = null;
			try {
				body = await response.json();
			} catch ( parseError ) {
				body = null;
			}

			if ( ! response.ok ) {
				renderRequestError( mapRequestError( response.status, body, labels ) );
				return;
			}

			renderView( mapDiagnosticsView( body, labels ) );
		} catch ( error ) {
			renderRequestError( mapRequestError( 0, null, labels ) );
			window.console?.warn?.( 'Codex diagnostics request failed.', error );
		} finally {
			runButton.disabled = false;
			runButton.textContent = previous;
		}
	} );
}
