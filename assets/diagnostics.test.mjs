import assert from 'node:assert/strict';
import { test } from 'node:test';

import { mapDiagnosticsView } from './diagnostics.js';

const LABELS = { healthy: 'All checks passed.', issues: 'Some checks failed.' };

test( 'maps statuses to indicator classes', () => {
	const view = mapDiagnosticsView(
		{
			ok: false,
			rows: [
				{ id: 'reachable', label: 'Sidecar reachable', status: 'pass', detail: '' },
				{ id: 'bearer', label: 'Bearer token matches', status: 'warn', detail: 'odd' },
				{ id: 'codex_cli', label: 'Codex CLI', status: 'fail', detail: 'missing' },
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

test( 'reports healthy overall when ok is true', () => {
	const view = mapDiagnosticsView( { ok: true, rows: [], config: [] }, LABELS );
	assert.equal( view.overall.status, 'pass' );
	assert.equal( view.overall.label, 'All checks passed.' );
} );
