<?php
/**
 * Repeatable verification checks for the Codex provider plugin.
 *
 * Run with:
 * wp --path=/path/to/site eval-file wp-content/plugins/ai-provider-for-codex/scripts/verify.php
 */
use AIProviderForCodex\Auth\AuthStateRepository;
use AIProviderForCodex\Auth\ConnectionRepository;
use AIProviderForCodex\Auth\ConnectionSnapshotRepository;
use AIProviderForCodex\Broker\Settings;
use AIProviderForCodex\Database\Installer;
use AIProviderForCodex\Provider\ModelCatalogState;

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run this script with wp eval-file.\n" );
	return;
}

require_once ABSPATH . 'wp-admin/includes/user.php';

/**
 * Throws when a verification condition fails.
 *
 * @param bool   $condition Verification result.
 * @param string $message Failure message.
 * @return void
 */
$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};

$temporary_user_id      = 0;
$original_user_id       = get_current_user_id();
$temporary_connection_id = 'codex-verify-' . wp_generate_uuid4();
$model_token            = strtolower( wp_generate_password( 8, false, false ) );
$temporary_model_a      = 'codex-verify-' . $model_token . '-alpha';
$temporary_model_b      = 'codex-verify-' . $model_token . '-beta';
/** @var \Throwable|null $failure */
$failure = null;

try {
	Installer::maybe_upgrade();

	global $wpdb;

	foreach ( [ ConnectionRepository::table_name(), ConnectionSnapshotRepository::table_name(), AuthStateRepository::table_name() ] as $table_name ) {
		$existing_table = (string) $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name )
		);

		$assert( $table_name === $existing_table, sprintf( 'Missing expected table: %s', $table_name ) );
	}

	$assert(
		'2' === (string) get_option( 'codex_provider_schema_version', '' ),
		'Schema version was not upgraded to 2.'
	);

	$sanitized_models = Settings::sanitize_allowed_models( " gpt-5-codex \n\n gpt-5.3-codex, gpt-5-codex " );
	$assert(
		"gpt-5-codex\ngpt-5.3-codex" === $sanitized_models,
		'Allowed-model sanitizer did not normalize textarea input.'
	);

	$user_login = 'codexverify' . strtolower( wp_generate_password( 10, false, false ) );
	$user_email = $user_login . '@example.com';

	$temporary_user = wp_insert_user(
		[
			'user_login' => $user_login,
			'user_pass'  => wp_generate_password( 20, true, true ),
			'user_email' => $user_email,
			'role'       => 'subscriber',
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
			'connectionId'     => $temporary_connection_id,
			'brokerUserId'     => 'broker-' . $temporary_user_id,
			'status'           => 'linked',
			'account'          => [
				'email'    => $user_email,
				'planType' => 'verification',
				'authMode' => 'oauth',
			],
			'sessionExpiresAt' => gmdate( 'c', time() + HOUR_IN_SECONDS ),
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
		$temporary_model_a === (string) $user_catalog['default_model'],
		'User catalog default model was not preserved.'
	);

	$site_catalog = ModelCatalogState::get_site_catalog();
	$assert(
		in_array( $temporary_model_a, $site_catalog['model_ids'], true ) && in_array( $temporary_model_b, $site_catalog['model_ids'], true ),
		'Site catalog did not aggregate active snapshot models.'
	);

	$server = rest_get_server();
	$routes = $server->get_routes();

	$assert( isset( $routes['/codex-provider/v1/connect/start'] ), 'Connect start route is not registered.' );
	$assert( isset( $routes['/codex-provider/v1/connect/refresh'] ), 'Connect refresh route is not registered.' );

	$start_route      = $routes['/codex-provider/v1/connect/start'][0] ?? [];
	$return_url_param = $start_route['args']['returnUrl'] ?? null;
	$assert(
		is_array( $return_url_param ) && isset( $return_url_param['sanitize_callback'], $return_url_param['validate_callback'] ),
		'Connect start route is missing returnUrl sanitization or validation.'
	);

	wp_set_current_user( $temporary_user_id );

	$request  = new WP_REST_Request( 'POST', '/codex-provider/v1/connect/start' );
	$request->set_param( 'returnUrl', 'notaurl' );
	$response = rest_do_request( $request );
	$data     = $response->get_data();

	$assert( 400 === $response->get_status(), 'Invalid returnUrl was not rejected.' );
	$assert(
		is_array( $data ) && 'rest_invalid_param' === (string) ( $data['code'] ?? '' ),
		'Invalid returnUrl did not fail at the REST validation layer.'
	);
} catch ( \Throwable $throwable ) {
	$failure = $throwable;
} finally {
	wp_set_current_user( $original_user_id );
	ConnectionSnapshotRepository::delete( $temporary_connection_id );

	if ( $temporary_user_id > 0 ) {
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
