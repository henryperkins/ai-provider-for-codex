<?php
/**
 * Database setup.
 *
 * @package AIProviderForCodex
 */

declare( strict_types=1 );

namespace AIProviderForCodex\Database;

use AIProviderForCodex\Auth\AuthStateRepository;
use AIProviderForCodex\Auth\ConnectionRepository;
use AIProviderForCodex\Auth\ConnectionSnapshotRepository;
use AIProviderForCodex\Broker\Settings;

/**
 * Creates plugin tables and seeds defaults.
 */
final class Installer {

	private const SCHEMA_VERSION_OPTION = 'codex_provider_schema_version';
	private const SCHEMA_VERSION        = '2';

	/**
	 * Runs on plugin activation.
	 *
	 * @return void
	 */
	public static function activate(): void {
		self::create_tables();
		self::seed_defaults();
		self::normalize_option_storage();
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
		$states      = AuthStateRepository::table_name();

		dbDelta(
			"CREATE TABLE {$connections} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				wp_user_id bigint(20) unsigned NOT NULL,
				connection_id varchar(191) NOT NULL,
				broker_user_id varchar(191) NOT NULL DEFAULT '',
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

		dbDelta(
			"CREATE TABLE {$states} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				state varchar(191) NOT NULL,
				wp_user_id bigint(20) unsigned NOT NULL,
				return_url text NOT NULL,
				expires_at datetime NOT NULL,
				used_at datetime NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY state (state),
				KEY wp_user_id (wp_user_id)
			) {$charset_collate};"
		);
	}

	/**
	 * Seeds default options.
	 *
	 * @return void
	 */
	private static function seed_defaults(): void {
		add_option( Settings::OPTION_DEFAULT_MODEL, Settings::get_default_model() );
		add_option( Settings::OPTION_ALLOWED_MODELS, Settings::allowed_models_as_text() );
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
}
