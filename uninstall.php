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
	// Current keys.
	'htperkins_aipfc_runtime_base_url',
	'htperkins_aipfc_runtime_bearer_token',
	'htperkins_aipfc_runtime_allowed_models',
	'htperkins_aipfc_suggested_bearer_token',
	'htperkins_aipfc_schema_version',
	'htperkins_aipfc_connector_self_approval_seeded',
	'htperkins_aipfc_legacy_default_model',
	// Legacy keys from pre-rename releases.
	'codex_runtime_base_url',
	'codex_runtime_bearer_token',
	'codex_runtime_allowed_models',
	'codex_runtime_suggested_bearer_token',
	'codex_runtime_default_model',
	'codex_provider_schema_version',
	'codex_provider_connector_self_approval_seeded',
] as $htperkins_aipfc_option_name ) {
	delete_option( $htperkins_aipfc_option_name );
}

foreach ( [
	// Current transients.
	'htperkins_aipfc_runtime_health',
	'htperkins_aipfc_site_catalog_refresh_attempt',
	'htperkins_aipfc_last_diagnostics',
	// Legacy transients from pre-rename releases.
	'codex_provider_runtime_health',
	'codex_provider_site_catalog_refresh_attempt',
	'codex_provider_last_diagnostics',
] as $htperkins_aipfc_transient_name ) {
	delete_transient( $htperkins_aipfc_transient_name );
}

foreach ( [
	// Current user meta.
	'htperkins_aipfc_dismiss_link_notice',
	'htperkins_aipfc_pending_auth_session',
	'htperkins_aipfc_preferred_model',
	// Legacy user meta from pre-rename releases.
	'codex_provider_dismiss_link_notice',
	'codex_provider_pending_auth_session',
	'codex_provider_preferred_model',
] as $htperkins_aipfc_meta_key ) {
	// $delete_all (5th arg) removes the meta for every user; the 4th arg must be
	// an empty meta value so any stored value matches.
	delete_metadata( 'user', 0, $htperkins_aipfc_meta_key, '', true );
}

foreach ( [
	// Current tables.
	$wpdb->prefix . 'htperkins_aipfc_connections',
	$wpdb->prefix . 'htperkins_aipfc_connection_snapshots',
	$wpdb->prefix . 'htperkins_aipfc_auth_states',
	// Legacy tables from pre-rename releases.
	$wpdb->prefix . 'codex_provider_connections',
	$wpdb->prefix . 'codex_provider_connection_snapshots',
	$wpdb->prefix . 'codex_provider_auth_states',
] as $htperkins_aipfc_table_name ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall removes the plugin's custom tables directly.
	$wpdb->query(
		$wpdb->prepare( 'DROP TABLE IF EXISTS %i', $htperkins_aipfc_table_name ) // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- Uninstall removes the plugin's custom tables directly.
	);
}
