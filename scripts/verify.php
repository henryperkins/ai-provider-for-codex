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
	throw new RuntimeException( 'Run this script with wp eval-file.' );
}

require_once ABSPATH . 'wp-admin/includes/user.php';

( static function (): void {
	$codex_provider_assert = static function ( bool $condition, string $message ): void {
		if ( ! $condition ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Verification errors are surfaced through WP-CLI exception handling, not rendered directly.
			throw new RuntimeException( (string) $message );
		}
	};

	$codex_provider_original_options = [
		Settings::OPTION_RUNTIME_BASE_URL => get_option( Settings::OPTION_RUNTIME_BASE_URL, null ),
		Settings::OPTION_RUNTIME_BEARER   => get_option( Settings::OPTION_RUNTIME_BEARER, null ),
		Settings::OPTION_ALLOWED_MODELS   => get_option( Settings::OPTION_ALLOWED_MODELS, null ),
	];
	$codex_provider_legacy_default_model_option = 'codex_runtime_default_model';
	$codex_provider_temporary_user_id           = 0;
	$codex_provider_original_user_id            = get_current_user_id();
	$codex_provider_temporary_connection_id     = 'codex-verify-' . wp_generate_uuid4();
	$codex_provider_model_token                 = strtolower( wp_generate_password( 8, false, false ) );
	$codex_provider_temporary_model_a           = 'codex-verify-' . $codex_provider_model_token . '-alpha';
	$codex_provider_temporary_model_b           = 'codex-verify-' . $codex_provider_model_token . '-beta';
	$codex_provider_temporary_fallback_a        = 'codex-fallback-' . $codex_provider_model_token . '-alpha';
	$codex_provider_temporary_fallback_b        = 'codex-fallback-' . $codex_provider_model_token . '-beta';
	/** @var \Throwable|null $codex_provider_failure */
	$codex_provider_failure = null;

	try {
		update_option( $codex_provider_legacy_default_model_option, 'legacy-model' );
		update_option( 'codex_provider_schema_version', '4' );
		Installer::maybe_upgrade();

		global $wpdb;

		foreach ( [ ConnectionRepository::table_name(), ConnectionSnapshotRepository::table_name() ] as $codex_provider_table_name ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Verification inspects plugin custom tables directly.
			$codex_provider_existing_table = (string) $wpdb->get_var(
				$wpdb->prepare( 'SHOW TABLES LIKE %s', $codex_provider_table_name )
			);

			$codex_provider_assert( $codex_provider_table_name === $codex_provider_existing_table, sprintf( 'Missing expected table: %s', $codex_provider_table_name ) );
		}

		$codex_provider_assert(
			'5' === (string) get_option( 'codex_provider_schema_version', '' ),
			'Schema version was not upgraded to 5.'
		);
		$codex_provider_assert(
			false === get_option( $codex_provider_legacy_default_model_option, false ),
			'Legacy default-model option should be removed during upgrade.'
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Verification inspects plugin custom tables directly.
		$codex_provider_connection_columns = $wpdb->get_col(
			$wpdb->prepare( 'SHOW COLUMNS FROM %i', ConnectionRepository::table_name() ),
			0
		);
		$codex_provider_assert( is_array( $codex_provider_connection_columns ), 'Could not read connection table columns.' );
		$codex_provider_assert( ! in_array( 'broker_user_id', $codex_provider_connection_columns, true ), 'Legacy broker_user_id column should not exist.' );

		$codex_provider_sanitized_models = Settings::sanitize_allowed_models( " gpt-5-codex \n\n gpt-5.3-codex, gpt-5-codex " );
		$codex_provider_assert(
			"gpt-5-codex\ngpt-5.3-codex" === $codex_provider_sanitized_models,
			'Allowed-model sanitizer did not normalize textarea input.'
		);

		SiteSettings::register_settings();
		update_option(
			Settings::OPTION_ALLOWED_MODELS,
			Settings::sanitize_allowed_models( $codex_provider_temporary_fallback_a . "\n" . $codex_provider_temporary_fallback_b )
		);
		update_option( Settings::OPTION_RUNTIME_BASE_URL, 'http://127.0.0.1:4317' );
		update_option( Settings::OPTION_RUNTIME_BEARER, 'verify-token' );
		update_option( Settings::OPTION_RUNTIME_BASE_URL, null );
		update_option( Settings::OPTION_RUNTIME_BEARER, null );
		$codex_provider_assert(
			is_string( get_option( Settings::OPTION_RUNTIME_BASE_URL, '' ) ),
			'Runtime base URL should survive a null options.php submission.'
		);
		$codex_provider_assert(
			is_string( get_option( Settings::OPTION_RUNTIME_BEARER, '' ) ),
			'Runtime bearer token should survive a null options.php submission.'
		);

		$codex_provider_user_login = 'codexverify' . strtolower( wp_generate_password( 10, false, false ) );
		$codex_provider_user_email = $codex_provider_user_login . '@example.com';

		$codex_provider_temporary_user = wp_insert_user(
			[
				'user_login' => $codex_provider_user_login,
				'user_pass'  => wp_generate_password( 20, true, true ),
				'user_email' => $codex_provider_user_email,
				'role'       => 'administrator',
			]
		);

		$codex_provider_assert(
			! is_wp_error( $codex_provider_temporary_user ),
			is_wp_error( $codex_provider_temporary_user ) ? $codex_provider_temporary_user->get_error_message() : 'Could not create a temporary verification user.'
		);

		$codex_provider_temporary_user_id = (int) $codex_provider_temporary_user;

		ConnectionRepository::upsert(
			$codex_provider_temporary_user_id,
			[
				'connectionId' => $codex_provider_temporary_connection_id,
				'status'       => 'linked',
				'account'      => [
					'email'    => $codex_provider_user_email,
					'planType' => 'verification',
					'authMode' => 'chatgpt',
				],
			]
		);

		$codex_provider_connection = ConnectionRepository::get_for_user( $codex_provider_temporary_user_id );
		$codex_provider_assert( is_array( $codex_provider_connection ), 'Connection upsert failed.' );
		$codex_provider_assert( $codex_provider_temporary_connection_id === (string) $codex_provider_connection['connection_id'], 'Connection ID was not persisted.' );

		foreach ( [ 'created_at', 'updated_at', 'last_synced_at' ] as $codex_provider_field ) {
			$codex_provider_assert(
				1 === preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) ( $codex_provider_connection[ $codex_provider_field ] ?? '' ) ),
				sprintf( 'Connection %s was not stored as a MySQL datetime.', $codex_provider_field )
			);
		}

		ConnectionSnapshotRepository::upsert(
			$codex_provider_temporary_connection_id,
			[
				'models'       => [
					[
						'id'    => $codex_provider_temporary_model_a,
						'label' => 'Codex Verify Alpha',
					],
					[
						'id'    => $codex_provider_temporary_model_b,
						'label' => 'Codex Verify Beta',
					],
				],
				'defaultModel' => $codex_provider_temporary_model_a,
				'defaults'     => [
					'reasoningEffort' => 'medium',
				],
				'checkedAt'    => gmdate( 'c' ),
			]
		);

		$codex_provider_snapshot = ConnectionSnapshotRepository::get( $codex_provider_temporary_connection_id );
		$codex_provider_assert( is_array( $codex_provider_snapshot ), 'Snapshot upsert failed.' );

		foreach ( [ 'created_at', 'updated_at', 'checked_at' ] as $codex_provider_field ) {
			$codex_provider_assert(
				1 === preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) ( $codex_provider_snapshot[ $codex_provider_field ] ?? '' ) ),
				sprintf( 'Snapshot %s was not stored as a MySQL datetime.', $codex_provider_field )
			);
		}

		$codex_provider_assert(
			is_array( $codex_provider_snapshot['models'] ?? null ) && 2 === count( $codex_provider_snapshot['models'] ),
			'Snapshot models were not persisted.'
		);

		$codex_provider_user_catalog = ModelCatalogState::get_user_snapshot_catalog( $codex_provider_temporary_user_id );
		$codex_provider_assert( 'user_snapshot' === (string) $codex_provider_user_catalog['source'], 'User catalog did not use the snapshot source.' );
		$codex_provider_assert(
			in_array( $codex_provider_temporary_model_a, $codex_provider_user_catalog['model_ids'], true ) && in_array( $codex_provider_temporary_model_b, $codex_provider_user_catalog['model_ids'], true ),
			'User catalog did not include snapshot models.'
		);
		$codex_provider_assert(
			$codex_provider_temporary_model_a === (string) $codex_provider_user_catalog['selected_model'],
			'User catalog selected model did not default to first available.'
		);

		wp_set_current_user( $codex_provider_temporary_user_id );
		ob_start();
		SiteSettings::render_page();
		$codex_provider_site_settings_html = (string) ob_get_clean();
		$codex_provider_assert(
			false !== strpos( $codex_provider_site_settings_html, $codex_provider_temporary_fallback_a ) && false !== strpos( $codex_provider_site_settings_html, $codex_provider_temporary_fallback_b ),
			'Site settings should render configured fallback models.'
		);
		$codex_provider_assert(
			false === strpos( $codex_provider_site_settings_html, $codex_provider_temporary_model_a ) && false === strpos( $codex_provider_site_settings_html, $codex_provider_temporary_model_b ),
			'Site settings should not render current-user snapshot models.'
		);

		PendingConnectionRepository::upsert(
			$codex_provider_temporary_user_id,
			[
				'authSessionId'   => 'auth_verify',
				'status'          => 'pending',
				'verificationUrl' => 'https://chatgpt.com/device',
				'userCode'        => 'ABCD-EFGH',
			]
		);

		$codex_provider_pending = PendingConnectionRepository::get_for_user( $codex_provider_temporary_user_id );
		$codex_provider_assert( is_array( $codex_provider_pending ), 'Pending connection state was not persisted.' );
		$codex_provider_assert( 'auth_verify' === (string) $codex_provider_pending['authSessionId'], 'Pending auth session ID did not persist.' );

		$codex_provider_server = rest_get_server();
		$codex_provider_routes = $codex_provider_server->get_routes();

		$codex_provider_assert( isset( $codex_provider_routes['/codex-provider/v1/connect/start'] ), 'Connect start route is not registered.' );
		$codex_provider_assert( isset( $codex_provider_routes['/codex-provider/v1/connect/status'] ), 'Connect status route is not registered.' );
		$codex_provider_assert( isset( $codex_provider_routes['/codex-provider/v1/connect/refresh'] ), 'Connect refresh route is not registered.' );

		$codex_provider_assert( Settings::has_required_configuration(), 'Runtime settings should be considered configured.' );

		$codex_provider_status = SupportChecks::current_user_status( $codex_provider_temporary_user_id );
		$codex_provider_assert( array_key_exists( 'runtimeConfigured', $codex_provider_status ), 'Status payload should expose runtimeConfigured.' );
		$codex_provider_assert( ! array_key_exists( 'siteConfigured', $codex_provider_status ), 'Legacy siteConfigured alias should not be returned.' );
	} catch ( \Throwable $codex_provider_throwable ) {
		$codex_provider_failure = $codex_provider_throwable;
	} finally {
		wp_set_current_user( $codex_provider_original_user_id );
		ConnectionSnapshotRepository::delete( $codex_provider_temporary_connection_id );

		foreach ( $codex_provider_original_options as $codex_provider_option_name => $codex_provider_option_value ) {
			if ( null === $codex_provider_option_value ) {
				delete_option( $codex_provider_option_name );
				continue;
			}

			update_option( $codex_provider_option_name, $codex_provider_option_value );
		}

		if ( $codex_provider_temporary_user_id > 0 ) {
			PendingConnectionRepository::delete_for_user( $codex_provider_temporary_user_id );
			ConnectionRepository::delete_for_user( $codex_provider_temporary_user_id );
			wp_delete_user( $codex_provider_temporary_user_id );
		}
	}

	if ( $codex_provider_failure ) {
		if ( class_exists( 'WP_CLI' ) ) {
			WP_CLI::error( $codex_provider_failure->getMessage() );
		}

		throw $codex_provider_failure;
	}

	if ( class_exists( 'WP_CLI' ) ) {
		WP_CLI::success( 'AI Provider for Codex verification passed.' );
		return;
	}

	echo "AI Provider for Codex verification passed.\n";
} )();
