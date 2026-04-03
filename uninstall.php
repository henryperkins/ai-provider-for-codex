<?php
/**
 * Uninstalls the AI Provider for Codex scaffold.
 *
 * @package AIProviderForCodex
 */

declare( strict_types=1 );

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

foreach ( [
	'codex_runtime_base_url',
	'codex_runtime_bearer_token',
	'codex_runtime_allowed_models',
	'codex_provider_schema_version',
] as $option_name ) {
	delete_option( $option_name );
}

// Clear the removed site-level default-model option on upgraded installs too.
delete_option( 'codex_runtime_default_model' );

delete_transient( 'codex_provider_runtime_health' );
delete_transient( 'codex_provider_site_catalog_refresh_attempt' );

delete_metadata( 'user', 0, 'codex_provider_dismiss_link_notice', true );
delete_metadata( 'user', 0, 'codex_provider_pending_auth_session', true );
delete_metadata( 'user', 0, 'codex_provider_preferred_model', true );

foreach ( [
	'codex_provider_connections',
	'codex_provider_connection_snapshots',
	'codex_provider_auth_states',
] as $suffix ) {
	$table_name = $wpdb->prefix . $suffix;
	$wpdb->query( "DROP TABLE IF EXISTS {$table_name}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
}
