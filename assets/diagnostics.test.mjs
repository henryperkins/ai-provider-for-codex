import assert from 'node:assert/strict';
import { test } from 'node:test';

import { mapDiagnosticsView, mapRequestError } from './diagnostics.js';

const LABELS = { healthy: 'All checks passed.', issues: 'Some checks failed.' };

test( 'maps statuses to indicator classes', () => {
	const view = mapDiagnosticsView(
		{
			ok: false,
			rows: [
				{ id: 'reachable', label: 'Sidecar reachable', status: 'pass', detail: '' },
				{ id: 'bearer', label: 'Bearer token matches', status: 'warn', detail: 'odd' },
				{ id: 'codex_cli', label: 'Codex CLI', status: 'fail', detail: 'missing', remediation: 'Install the Codex CLI.' },
			],
			config: [ { label: 'Runtime URL source', value: 'env file' } ],
		},
		LABELS
	);

	assert.equal( view.rows[ 0 ].indicatorClass, 'good' );
	assert.equal( view.rows[ 1 ].indicatorClass, 'warning' );
	assert.equal( view.rows[ 2 ].indicatorClass, 'error' );
	assert.equal( view.config[ 0 ].value, 'env file' );
	assert.equal( view.overall.status, 'fail' );
	assert.equal( view.overall.label, 'Some checks failed.' );
} );

test( 'passes remediation through and defaults it to an empty string', () => {
	const view = mapDiagnosticsView(
		{
			ok: false,
			rows: [
				{ id: 'reachable', label: 'Sidecar reachable', status: 'pass', detail: '' },
				{ id: 'codex_cli', label: 'Codex CLI', status: 'fail', detail: 'missing', remediation: 'Install the Codex CLI.' },
			],
		},
		LABELS
	);

	assert.equal( view.rows[ 0 ].remediation, '' );
	assert.equal( view.rows[ 1 ].remediation, 'Install the Codex CLI.' );
} );

test( 'reports healthy overall when ok is true', () => {
	const view = mapDiagnosticsView( { ok: true, rows: [], config: [] }, LABELS );
	assert.equal( view.overall.status, 'pass' );
	assert.equal( view.overall.label, 'All checks passed.' );
} );

const ERROR_LABELS = {
	requestFailed: 'The diagnostics request failed',
	networkError: 'Could not reach WordPress to run diagnostics.',
	networkHint: 'Check your connection and try again.',
	nonceHint: 'Your session may have expired. Reload this page and try again.',
	permHint: 'You may not have permission to run diagnostics.',
};

test( 'maps an expired-nonce error to a reload hint, not an empty result', () => {
	const error = mapRequestError(
		403,
		{ code: 'rest_cookie_invalid_nonce', message: 'Cookie check failed.' },
		ERROR_LABELS
	);

	assert.equal( error.title, 'The diagnostics request failed (HTTP 403).' );
	assert.equal( error.detail, 'Cookie check failed.' );
	assert.equal( error.hint, ERROR_LABELS.nonceHint );
} );

test( 'maps a generic server error to its status and message', () => {
	const error = mapRequestError(
		500,
		{ code: 'internal_server_error', message: 'Boom.' },
		ERROR_LABELS
	);

	assert.equal( error.title, 'The diagnostics request failed (HTTP 500).' );
	assert.equal( error.detail, 'Boom.' );
	assert.equal( error.hint, '' );
} );

test( 'maps a network failure (status 0) to the offline message', () => {
	const error = mapRequestError( 0, null, ERROR_LABELS );

	assert.equal( error.title, ERROR_LABELS.networkError );
	assert.equal( error.hint, ERROR_LABELS.networkHint );
} );
