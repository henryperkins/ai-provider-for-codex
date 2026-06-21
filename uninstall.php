<?php
/**
 * Uninstalls the Scriptorium AI Provider for Codex scaffold.
 *
 * @package HtperkinsAIProviderForCodex
 */

declare( strict_types=1 );

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

foreach ( [
	'htperkins_aipfc_runtime_base_url',
	'htperkins_aipfc_runtime_bearer_token',
	'htperkins_aipfc_runtime_allowed_models',
	'htperkins_aipfc_suggested_bearer_token',
	'htperkins_aipfc_schema_version',
	'htperkins_aipfc_connector_self_approval_seeded',
] as $htperkins_aipfc_option_name ) {
	delete_option( $htperkins_aipfc_option_name );
}

// Clear the removed site-level default-model option on upgraded installs too.
delete_option( 'htperkins_aipfc_legacy_default_model' );

delete_transient( 'htperkins_aipfc_runtime_health' );
delete_transient( 'htperkins_aipfc_site_catalog_refresh_attempt' );
delete_transient( 'htperkins_aipfc_last_diagnostics' );

delete_metadata( 'user', 0, 'htperkins_aipfc_dismiss_link_notice', true );
delete_metadata( 'user', 0, 'htperkins_aipfc_pending_auth_session', true );
delete_metadata( 'user', 0, 'htperkins_aipfc_preferred_model', true );

foreach ( [
	$wpdb->prefix . 'htperkins_aipfc_connections',
	$wpdb->prefix . 'htperkins_aipfc_connection_snapshots',
	$wpdb->prefix . 'htperkins_aipfc_auth_states',
] as $htperkins_aipfc_table_name ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall removes the plugin's custom tables directly.
	$wpdb->query(
		$wpdb->prepare( 'DROP TABLE IF EXISTS %i', $htperkins_aipfc_table_name ) // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- Uninstall removes the plugin's custom tables directly.
	);
}
