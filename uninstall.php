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
] as $codex_provider_option_name ) {
	delete_option( $codex_provider_option_name );
}

// Clear the removed site-level default-model option on upgraded installs too.
delete_option( 'codex_runtime_default_model' );

delete_transient( 'codex_provider_runtime_health' );
delete_transient( 'codex_provider_site_catalog_refresh_attempt' );

delete_metadata( 'user', 0, 'codex_provider_dismiss_link_notice', true );
delete_metadata( 'user', 0, 'codex_provider_pending_auth_session', true );
delete_metadata( 'user', 0, 'codex_provider_preferred_model', true );

foreach ( [
	$wpdb->prefix . 'codex_provider_connections',
	$wpdb->prefix . 'codex_provider_connection_snapshots',
	$wpdb->prefix . 'codex_provider_auth_states',
] as $codex_provider_table_name ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall removes the plugin's custom tables directly.
	$wpdb->query(
		$wpdb->prepare( 'DROP TABLE IF EXISTS %i', $codex_provider_table_name ) // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- Uninstall removes the plugin's custom tables directly.
	);
}
