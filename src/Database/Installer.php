<?php
/**
 * Database setup.
 *
 * @package AIProviderForCodex
 */

declare( strict_types=1 );

namespace AIProviderForCodex\Database;

use AIProviderForCodex\Auth\ConnectionRepository;
use AIProviderForCodex\Auth\ConnectionSnapshotRepository;
use AIProviderForCodex\Runtime\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates plugin tables and seeds defaults.
 */
final class Installer {

	private const SCHEMA_VERSION_OPTION = 'codex_provider_schema_version';
	private const SCHEMA_VERSION        = '5';
	private const LEGACY_DEFAULT_MODEL  = 'codex_runtime_default_model';

	/**
	 * Runs on plugin activation.
	 *
	 * @return void
	 */
	public static function activate(): void {
		self::create_tables();
		self::cleanup_legacy_schema();
		self::seed_defaults();
		self::normalize_option_storage();
		self::cleanup_legacy_options();
		update_option( self::SCHEMA_VERSION_OPTION, self::SCHEMA_VERSION );
	}

	/**
	 * Upgrades the schema when needed.
	 *
	 * @return void
	 */
	public static function maybe_upgrade(): void {
		$current = (string) get_option( self::SCHEMA_VERSION_OPTION, '' );

		if ( self::SCHEMA_VERSION === $current ) {
			return;
		}

		self::activate();
	}

	/**
	 * Creates plugin tables.
	 *
	 * @return void
	 */
	private static function create_tables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		$connections = ConnectionRepository::table_name();
		$snapshots   = ConnectionSnapshotRepository::table_name();

		dbDelta(
			"CREATE TABLE {$connections} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				wp_user_id bigint(20) unsigned NOT NULL,
				connection_id varchar(191) NOT NULL,
				status varchar(50) NOT NULL DEFAULT 'linked',
				account_email varchar(190) NOT NULL DEFAULT '',
				plan_type varchar(50) NOT NULL DEFAULT '',
				auth_mode varchar(50) NOT NULL DEFAULT '',
				session_expires_at datetime NULL,
				last_synced_at datetime NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY wp_user_id (wp_user_id),
				UNIQUE KEY connection_id (connection_id)
			) {$charset_collate};"
		);

		dbDelta(
			"CREATE TABLE {$snapshots} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				connection_id varchar(191) NOT NULL,
				models_json longtext NULL,
				default_model varchar(191) NOT NULL DEFAULT '',
				reasoning_effort varchar(50) NOT NULL DEFAULT '',
				rate_limits_json longtext NULL,
				readiness_status varchar(50) NOT NULL DEFAULT 'unknown',
				checked_at datetime NOT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY connection_id (connection_id)
			) {$charset_collate};"
		);
	}

	/**
	 * Removes schema artifacts left from the old connect flow.
	 *
	 * @return void
	 */
	private static function cleanup_legacy_schema(): void {
		global $wpdb;

		$connections = ConnectionRepository::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema inspection during upgrade must query the plugin table directly.
		$legacy_column = $wpdb->get_var(
			$wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $connections, 'broker_user_id' )
		);

		if ( is_string( $legacy_column ) && '' !== $legacy_column ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema cleanup during upgrade must run directly.
			$wpdb->query(
				$wpdb->prepare( 'ALTER TABLE %i DROP COLUMN broker_user_id', $connections ) // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- Schema cleanup during upgrade must run directly.
			);
		}

		$legacy_auth_states = $wpdb->prefix . 'codex_provider_auth_states';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema cleanup during upgrade must run directly.
		$wpdb->query(
			$wpdb->prepare( 'DROP TABLE IF EXISTS %i', $legacy_auth_states ) // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- Schema cleanup during upgrade must run directly.
		);
	}

	/**
	 * Seeds default options.
	 *
	 * @return void
	 */
	private static function seed_defaults(): void {
		add_option( Settings::OPTION_ALLOWED_MODELS, Settings::allowed_models_as_text() );
		add_option( Settings::OPTION_RUNTIME_BASE_URL, Settings::get_base_url() );
	}

	/**
	 * Normalizes legacy option storage after upgrades.
	 *
	 * @return void
	 */
	private static function normalize_option_storage(): void {
		$allowed_models = get_option( Settings::OPTION_ALLOWED_MODELS, null );

		if ( is_array( $allowed_models ) ) {
			update_option( Settings::OPTION_ALLOWED_MODELS, Settings::allowed_models_as_text() );
		}
	}

	/**
	 * Removes legacy WordPress options that are no longer used.
	 *
	 * @return void
	 */
	private static function cleanup_legacy_options(): void {
		delete_option( self::LEGACY_DEFAULT_MODEL );
	}
}
