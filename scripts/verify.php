<?php
/**
 * Repeatable verification checks for the Codex provider plugin.
 *
 * Run with:
 * wp --path=/path/to/site eval-file wp-content/plugins/ai-provider-for-codex/scripts/verify.php
 */

use AIProviderForCodex\Auth\ConnectionRepository;
use AIProviderForCodex\Auth\ConnectionSnapshotRepository;
use AIProviderForCodex\Auth\PendingConnectionRepository;
use AIProviderForCodex\Admin\SiteSettings;
use AIProviderForCodex\Database\Installer;
use AIProviderForCodex\Provider\ModelCatalogState;
use AIProviderForCodex\Provider\SupportChecks;
use AIProviderForCodex\Runtime\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run this script with wp eval-file.\n" );
	return;
}

require_once ABSPATH . 'wp-admin/includes/user.php';

$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};

$original_options = [
	Settings::OPTION_RUNTIME_BASE_URL => get_option( Settings::OPTION_RUNTIME_BASE_URL, null ),
	Settings::OPTION_RUNTIME_BEARER   => get_option( Settings::OPTION_RUNTIME_BEARER, null ),
	Settings::OPTION_ALLOWED_MODELS   => get_option( Settings::OPTION_ALLOWED_MODELS, null ),
];
$legacy_default_model_option = 'codex_runtime_default_model';
$temporary_user_id       = 0;
$original_user_id        = get_current_user_id();
$temporary_connection_id = 'codex-verify-' . wp_generate_uuid4();
$model_token             = strtolower( wp_generate_password( 8, false, false ) );
$temporary_model_a       = 'codex-verify-' . $model_token . '-alpha';
$temporary_model_b       = 'codex-verify-' . $model_token . '-beta';
$temporary_fallback_a    = 'codex-fallback-' . $model_token . '-alpha';
$temporary_fallback_b    = 'codex-fallback-' . $model_token . '-beta';
/** @var \Throwable|null $failure */
$failure = null;

try {
	update_option( $legacy_default_model_option, 'legacy-model' );
	update_option( 'codex_provider_schema_version', '4' );
	Installer::maybe_upgrade();

	global $wpdb;

	foreach ( [ ConnectionRepository::table_name(), ConnectionSnapshotRepository::table_name() ] as $table_name ) {
		$existing_table = (string) $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name )
		);

		$assert( $table_name === $existing_table, sprintf( 'Missing expected table: %s', $table_name ) );
	}

	$assert(
		'5' === (string) get_option( 'codex_provider_schema_version', '' ),
		'Schema version was not upgraded to 5.'
	);
	$assert(
		false === get_option( $legacy_default_model_option, false ),
		'Legacy default-model option should be removed during upgrade.'
	);

	$connection_columns = $wpdb->get_col( 'SHOW COLUMNS FROM ' . ConnectionRepository::table_name(), 0 ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$assert( is_array( $connection_columns ), 'Could not read connection table columns.' );
	$assert( ! in_array( 'broker_user_id', $connection_columns, true ), 'Legacy broker_user_id column should not exist.' );

	$sanitized_models = Settings::sanitize_allowed_models( " gpt-5-codex \n\n gpt-5.3-codex, gpt-5-codex " );
	$assert(
		"gpt-5-codex\ngpt-5.3-codex" === $sanitized_models,
		'Allowed-model sanitizer did not normalize textarea input.'
	);

	SiteSettings::register_settings();
	update_option(
		Settings::OPTION_ALLOWED_MODELS,
		Settings::sanitize_allowed_models( $temporary_fallback_a . "\n" . $temporary_fallback_b )
	);
	update_option( Settings::OPTION_RUNTIME_BASE_URL, 'http://127.0.0.1:4317' );
	update_option( Settings::OPTION_RUNTIME_BEARER, 'verify-token' );
	update_option( Settings::OPTION_RUNTIME_BASE_URL, null );
	update_option( Settings::OPTION_RUNTIME_BEARER, null );
	$assert(
		is_string( get_option( Settings::OPTION_RUNTIME_BASE_URL, '' ) ),
		'Runtime base URL should survive a null options.php submission.'
	);
	$assert(
		is_string( get_option( Settings::OPTION_RUNTIME_BEARER, '' ) ),
		'Runtime bearer token should survive a null options.php submission.'
	);

	$user_login = 'codexverify' . strtolower( wp_generate_password( 10, false, false ) );
	$user_email = $user_login . '@example.com';

	$temporary_user = wp_insert_user(
		[
			'user_login' => $user_login,
			'user_pass'  => wp_generate_password( 20, true, true ),
			'user_email' => $user_email,
			'role'       => 'administrator',
		]
	);

	$assert(
		! is_wp_error( $temporary_user ),
		is_wp_error( $temporary_user ) ? $temporary_user->get_error_message() : 'Could not create a temporary verification user.'
	);

	$temporary_user_id = (int) $temporary_user;

	ConnectionRepository::upsert(
		$temporary_user_id,
		[
			'connectionId' => $temporary_connection_id,
			'status'       => 'linked',
			'account'      => [
				'email'    => $user_email,
				'planType' => 'verification',
				'authMode' => 'chatgpt',
			],
		]
	);

	$connection = ConnectionRepository::get_for_user( $temporary_user_id );
	$assert( is_array( $connection ), 'Connection upsert failed.' );
	$assert( $temporary_connection_id === (string) $connection['connection_id'], 'Connection ID was not persisted.' );

	foreach ( [ 'created_at', 'updated_at', 'last_synced_at' ] as $field ) {
		$assert(
			1 === preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) ( $connection[ $field ] ?? '' ) ),
			sprintf( 'Connection %s was not stored as a MySQL datetime.', $field )
		);
	}

	ConnectionSnapshotRepository::upsert(
		$temporary_connection_id,
		[
			'models'       => [
				[
					'id'    => $temporary_model_a,
					'label' => 'Codex Verify Alpha',
				],
				[
					'id'    => $temporary_model_b,
					'label' => 'Codex Verify Beta',
				],
			],
			'defaultModel' => $temporary_model_a,
			'defaults'     => [
				'reasoningEffort' => 'medium',
			],
			'checkedAt'    => gmdate( 'c' ),
		]
	);

	$snapshot = ConnectionSnapshotRepository::get( $temporary_connection_id );
	$assert( is_array( $snapshot ), 'Snapshot upsert failed.' );

	foreach ( [ 'created_at', 'updated_at', 'checked_at' ] as $field ) {
		$assert(
			1 === preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) ( $snapshot[ $field ] ?? '' ) ),
			sprintf( 'Snapshot %s was not stored as a MySQL datetime.', $field )
		);
	}

	$assert(
		is_array( $snapshot['models'] ?? null ) && 2 === count( $snapshot['models'] ),
		'Snapshot models were not persisted.'
	);

	$user_catalog = ModelCatalogState::get_user_snapshot_catalog( $temporary_user_id );
	$assert( 'user_snapshot' === (string) $user_catalog['source'], 'User catalog did not use the snapshot source.' );
	$assert(
		in_array( $temporary_model_a, $user_catalog['model_ids'], true ) && in_array( $temporary_model_b, $user_catalog['model_ids'], true ),
		'User catalog did not include snapshot models.'
	);
	$assert(
		$temporary_model_a === (string) $user_catalog['selected_model'],
		'User catalog selected model did not default to first available.'
	);

	wp_set_current_user( $temporary_user_id );
	ob_start();
	SiteSettings::render_page();
	$site_settings_html = (string) ob_get_clean();
	$assert(
		false !== strpos( $site_settings_html, $temporary_fallback_a ) && false !== strpos( $site_settings_html, $temporary_fallback_b ),
		'Site settings should render configured fallback models.'
	);
	$assert(
		false === strpos( $site_settings_html, $temporary_model_a ) && false === strpos( $site_settings_html, $temporary_model_b ),
		'Site settings should not render current-user snapshot models.'
	);

	PendingConnectionRepository::upsert(
		$temporary_user_id,
		[
			'authSessionId'   => 'auth_verify',
			'status'          => 'pending',
			'verificationUrl' => 'https://chatgpt.com/device',
			'userCode'        => 'ABCD-EFGH',
		]
	);

	$pending = PendingConnectionRepository::get_for_user( $temporary_user_id );
	$assert( is_array( $pending ), 'Pending connection state was not persisted.' );
	$assert( 'auth_verify' === (string) $pending['authSessionId'], 'Pending auth session ID did not persist.' );

	$server = rest_get_server();
	$routes = $server->get_routes();

	$assert( isset( $routes['/codex-provider/v1/connect/start'] ), 'Connect start route is not registered.' );
	$assert( isset( $routes['/codex-provider/v1/connect/status'] ), 'Connect status route is not registered.' );
	$assert( isset( $routes['/codex-provider/v1/connect/refresh'] ), 'Connect refresh route is not registered.' );

	$assert( Settings::has_required_configuration(), 'Runtime settings should be considered configured.' );

	$status = SupportChecks::current_user_status( $temporary_user_id );
	$assert( array_key_exists( 'runtimeConfigured', $status ), 'Status payload should expose runtimeConfigured.' );
	$assert( ! array_key_exists( 'siteConfigured', $status ), 'Legacy siteConfigured alias should not be returned.' );
} catch ( \Throwable $throwable ) {
	$failure = $throwable;
} finally {
	wp_set_current_user( $original_user_id );
	ConnectionSnapshotRepository::delete( $temporary_connection_id );

	foreach ( $original_options as $option_name => $option_value ) {
		if ( null === $option_value ) {
			delete_option( $option_name );
			continue;
		}

		update_option( $option_name, $option_value );
	}

	if ( $temporary_user_id > 0 ) {
		PendingConnectionRepository::delete_for_user( $temporary_user_id );
		ConnectionRepository::delete_for_user( $temporary_user_id );
		wp_delete_user( $temporary_user_id );
	}
}

if ( $failure ) {
	if ( class_exists( 'WP_CLI' ) ) {
		WP_CLI::error( $failure->getMessage() );
	}

	throw $failure;
}

if ( class_exists( 'WP_CLI' ) ) {
	WP_CLI::success( 'AI Provider for Codex verification passed.' );
	return;
}

echo "AI Provider for Codex verification passed.\n";
