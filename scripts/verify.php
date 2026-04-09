<?php
/**
 * Repeatable verification checks for the Codex provider plugin.
 *
 * Run with:
 * wp --path=/path/to/site eval-file wp-content/plugins/ai-provider-for-codex/scripts/verify.php
 */

use AIProviderForCodex\Auth\ConnectionRepository;
use AIProviderForCodex\Auth\ConnectionService;
use AIProviderForCodex\Auth\ConnectionSnapshotRepository;
use AIProviderForCodex\Auth\PendingConnectionRepository;
use AIProviderForCodex\Admin\SiteSettings;
use AIProviderForCodex\Admin\UserConnectionPage;
use AIProviderForCodex\Database\Installer;
use AIProviderForCodex\Provider\ModelCatalogState;
use AIProviderForCodex\Provider\SupportChecks;
use AIProviderForCodex\REST\ConnectController;
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
		$codex_provider_http_json_response = static function ( int $status_code, array $payload ): array {
			return [
				'headers'  => [],
				'body'     => (string) wp_json_encode( $payload ),
				'response' => [
					'code'    => $status_code,
					'message' => (string) wp_get_server_protocol(),
				],
				'cookies'  => [],
				'filename' => null,
			];
		};
		$codex_provider_with_mock_runtime = static function ( callable $handler, callable $callback ) {
			$filter = static function ( $preempt, array $args, string $url ) use ( $handler ) {
				return $handler( $preempt, $args, $url );
			};

			add_filter( 'pre_http_request', $filter, 10, 3 );

			try {
				return $callback();
			} finally {
				remove_filter( 'pre_http_request', $filter, 10 );
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
	$codex_provider_original_env_bearer         = getenv( 'CODEX_WP_BEARER_TOKEN' );
	$codex_provider_original_env_bearer_exists  = false !== $codex_provider_original_env_bearer;
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
		$codex_provider_assert(
			'stored%20token' === Settings::sanitize_bearer_token( 'Bearer "stored%20token"' ),
			'Runtime bearer token sanitizer should strip surrounding quotes and any pasted Bearer prefix.'
		);

		putenv( 'CODEX_WP_BEARER_TOKEN=Bearer env%20token' );
		$_ENV['CODEX_WP_BEARER_TOKEN'] = 'Bearer env%20token';
		$codex_provider_assert(
			'env%20token' === Settings::get_bearer_token(),
			'Runtime bearer token overrides should preserve opaque token contents while stripping a pasted Bearer prefix.'
		);
		putenv( 'CODEX_WP_BEARER_TOKEN' );
		unset( $_ENV['CODEX_WP_BEARER_TOKEN'] );

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
			$codex_provider_site_settings_filter_applied  = false;
			$codex_provider_site_settings_translate_filter = static function ( string $translation, string $text, string $domain ) use ( &$codex_provider_site_settings_filter_applied ): string {
				if (
					'ai-provider-for-codex' === $domain
					&& str_contains( $text, 'Per-user account linking is on the' )
				) {
					$codex_provider_site_settings_filter_applied = true;

					return '<a href="%1$s">Settings &gt; Connectors</a> is the main entry point. 100% guided. Per-user account linking is on the <a href="%2$s">user connection page</a>.';
				}

				return $translation;
			};

			add_filter( 'gettext', $codex_provider_site_settings_translate_filter, 10, 3 );

			try {
				ob_start();
				SiteSettings::render_page();
				$codex_provider_site_settings_html = (string) ob_get_clean();
			} finally {
				remove_filter( 'gettext', $codex_provider_site_settings_translate_filter, 10 );
			}

			$codex_provider_assert( $codex_provider_site_settings_filter_applied, 'Site settings percent-sign translation filter was not applied.' );
			$codex_provider_assert( '' !== $codex_provider_site_settings_html, 'Site settings should render successfully when translated text contains a literal percent sign.' );

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
			$codex_provider_assert( isset( $codex_provider_routes['/codex-provider/v1/status'] ), 'Status route is not registered.' );

		$codex_provider_assert( Settings::has_required_configuration(), 'Runtime settings should be considered configured.' );

		$codex_provider_status = SupportChecks::current_user_status( $codex_provider_temporary_user_id );
		$codex_provider_assert( array_key_exists( 'runtimeConfigured', $codex_provider_status ), 'Status payload should expose runtimeConfigured.' );
		$codex_provider_assert( ! array_key_exists( 'siteConfigured', $codex_provider_status ), 'Legacy siteConfigured alias should not be returned.' );

		$codex_provider_base_url = Settings::get_base_url();

		ConnectionService::invalidate_local_connection( $codex_provider_temporary_user_id );
		PendingConnectionRepository::upsert(
			$codex_provider_temporary_user_id,
			[
				'authSessionId'   => 'auth_route_connected',
				'status'          => 'pending',
				'verificationUrl' => 'https://chatgpt.com/device',
				'userCode'        => 'ROUTE-1234',
			]
		);
			$codex_provider_with_mock_runtime(
				static function ( $preempt, array $args, string $url ) use ( $codex_provider_base_url, $codex_provider_http_json_response, $codex_provider_temporary_model_a, $codex_provider_user_email ) {
					if ( 0 !== strpos( $url, $codex_provider_base_url ) ) {
						return $preempt;
				}

				$path = (string) wp_parse_url( $url, PHP_URL_PATH );

				if ( '/v1/login/status' === $path ) {
					return $codex_provider_http_json_response(
						200,
						[
							'authSessionId'   => 'auth_route_connected',
							'status'          => 'completed',
							'authStored'      => true,
							'verificationUrl' => 'https://chatgpt.com/device',
							'userCode'        => 'ROUTE-1234',
						]
					);
				}

				if ( '/v1/account/snapshot' === $path ) {
					return $codex_provider_http_json_response(
						200,
						[
							'account'      => [
								'email'    => $codex_provider_user_email,
								'planType' => 'plus',
								'authMode' => 'chatgpt',
								'type'     => 'chatgpt',
							],
							'authStored'   => true,
							'defaultModel' => $codex_provider_temporary_model_a,
							'models'       => [
								[
									'id'    => $codex_provider_temporary_model_a,
									'label' => 'Codex Verify Alpha',
								],
							],
							'rateLimits'   => [],
						]
					);
				}

				return $preempt;
			},
			static function () use ( $codex_provider_assert, $codex_provider_temporary_model_a ) {
				$codex_provider_response = ConnectController::status();
				$codex_provider_data     = $codex_provider_response->get_data();

				$codex_provider_assert( 'connected' === (string) ( $codex_provider_data['status'] ?? '' ), 'Connect status REST route should surface a connected result when login completes.' );
				$codex_provider_assert( $codex_provider_temporary_model_a === (string) ( $codex_provider_data['catalog']['selected_model'] ?? '' ), 'Connect status REST route should append the effective catalog for connected users.' );
			}
		);

		$codex_provider_status_snapshot_requests = [];
		$codex_provider_with_mock_runtime(
			static function ( $preempt, array $args, string $url ) use ( $codex_provider_base_url, $codex_provider_http_json_response, &$codex_provider_status_snapshot_requests ) {
				if ( 0 !== strpos( $url, $codex_provider_base_url ) ) {
					return $preempt;
				}

				$path = (string) wp_parse_url( $url, PHP_URL_PATH );

				if ( '/healthz' === $path ) {
					return $codex_provider_http_json_response(
						200,
						[
							'ok'      => true,
							'service' => 'codex-wp-sidecar',
						]
					);
				}

				if ( '/v1/account/snapshot' === $path ) {
					$codex_provider_status_snapshot_requests[] = $path;

					return $codex_provider_http_json_response(
						500,
						[
							'error' => [
								'code'    => 'unexpected_snapshot_call',
								'message' => 'Status reads should not request a live snapshot.',
							],
						]
					);
				}

				return $preempt;
			},
			static function () use ( $codex_provider_assert, &$codex_provider_status_snapshot_requests ) {
				$codex_provider_request  = new WP_REST_Request( 'GET', '/codex-provider/v1/status' );
				$codex_provider_response = rest_do_request( $codex_provider_request );
				$codex_provider_data     = $codex_provider_response->get_data();

				// @phpstan-ignore-next-line Verification mutates this request log through the pre_http_request hook.
				$codex_provider_assert( 0 === count( $codex_provider_status_snapshot_requests ), 'Normal status reads should not trigger a live account snapshot refresh.' );
				$codex_provider_assert( 'ready' === (string) ( $codex_provider_data['reason'] ?? '' ), 'Normal status reads should continue using the stored ready snapshot.' );
			}
		);

		ConnectionService::invalidate_local_connection( $codex_provider_temporary_user_id );
		PendingConnectionRepository::upsert(
			$codex_provider_temporary_user_id,
			[
				'authSessionId'   => 'auth_sync_retry',
				'status'          => 'pending',
				'verificationUrl' => 'https://chatgpt.com/device',
				'userCode'        => 'SYNC-9999',
			]
		);
		$codex_provider_with_mock_runtime(
			static function ( $preempt, array $args, string $url ) use ( $codex_provider_base_url, $codex_provider_http_json_response ) {
				if ( 0 !== strpos( $url, $codex_provider_base_url ) ) {
					return $preempt;
				}

				$path = (string) wp_parse_url( $url, PHP_URL_PATH );

				if ( '/healthz' === $path ) {
					return $codex_provider_http_json_response(
						200,
						[
							'ok'      => true,
							'service' => 'codex-wp-sidecar',
						]
					);
				}

				if ( '/v1/login/status' === $path ) {
					return $codex_provider_http_json_response(
						200,
						[
							'authSessionId'   => 'auth_sync_retry',
							'status'          => 'completed',
							'authStored'      => true,
							'verificationUrl' => 'https://chatgpt.com/device',
							'userCode'        => 'SYNC-9999',
						]
					);
				}

				if ( '/v1/account/snapshot' === $path ) {
					return $codex_provider_http_json_response(
						500,
						[
							'error' => [
								'code'    => 'runtime_failure',
								'message' => 'Snapshot refresh failed during verification.',
							],
						]
					);
				}

				return $preempt;
			},
			static function () use ( $codex_provider_assert, $codex_provider_temporary_user_id ) {
				$codex_provider_result = ( new ConnectionService() )->poll_connect_status( $codex_provider_temporary_user_id );
				$codex_provider_assert( 'error' === (string) ( $codex_provider_result['status'] ?? '' ), 'Snapshot refresh failures should surface an error result after login completes.' );

				$codex_provider_status = SupportChecks::current_user_status( $codex_provider_temporary_user_id );
				$codex_provider_assert( 'login_pending' === (string) ( $codex_provider_status['reason'] ?? '' ), 'Retryable post-login sync failures should keep the user in a recoverable pending state.' );
				$codex_provider_assert( 'completed' === (string) ( $codex_provider_status['pendingConnection']['status'] ?? '' ), 'Retryable post-login sync failures should retain the completed pending session marker.' );
				$codex_provider_assert( 'Snapshot refresh failed during verification.' === (string) ( $codex_provider_status['pendingConnection']['error'] ?? '' ), 'Retryable post-login sync failures should preserve the stored error message.' );

				ob_start();
				UserConnectionPage::render_page();
				$codex_provider_connection_page_html = (string) ob_get_clean();

				$codex_provider_assert( false !== strpos( $codex_provider_connection_page_html, 'Retry account sync' ), 'User connection page should offer a retry-sync action when snapshot refresh fails after login.' );
				$codex_provider_assert( false !== strpos( $codex_provider_connection_page_html, 'Snapshot refresh failed during verification.' ), 'User connection page should surface the stored sync error after login completes.' );
			}
		);

		ConnectionService::invalidate_local_connection( $codex_provider_temporary_user_id );
		PendingConnectionRepository::upsert(
			$codex_provider_temporary_user_id,
			[
				'authSessionId'   => 'auth_terminal_error',
				'status'          => 'pending',
				'verificationUrl' => 'https://chatgpt.com/device',
				'userCode'        => 'FAIL-0001',
			]
		);
		$codex_provider_login_status_requests = [];
		$codex_provider_with_mock_runtime(
			static function ( $preempt, array $args, string $url ) use ( $codex_provider_base_url, $codex_provider_http_json_response, &$codex_provider_login_status_requests ) {
				if ( 0 !== strpos( $url, $codex_provider_base_url ) ) {
					return $preempt;
				}

				$path = (string) wp_parse_url( $url, PHP_URL_PATH );

				if ( '/healthz' === $path ) {
					return $codex_provider_http_json_response(
						200,
						[
							'ok'      => true,
							'service' => 'codex-wp-sidecar',
						]
					);
				}

				if ( '/v1/login/status' === $path ) {
					$codex_provider_login_status_requests[] = $path;

					return $codex_provider_http_json_response(
						200,
						[
							'authSessionId'   => 'auth_terminal_error',
							'status'          => 'error',
							'authStored'      => false,
							'verificationUrl' => 'https://chatgpt.com/device',
							'userCode'        => 'FAIL-0001',
							'error'           => 'Device code expired.',
						]
					);
				}

				return $preempt;
			},
			static function () use ( $codex_provider_assert, $codex_provider_temporary_user_id, &$codex_provider_login_status_requests ) {
				$codex_provider_result = ( new ConnectionService() )->poll_connect_status( $codex_provider_temporary_user_id );
				$codex_provider_assert( 'error' === (string) ( $codex_provider_result['status'] ?? '' ), 'Terminal device-code failures should surface an error result.' );

				$codex_provider_pending_error = PendingConnectionRepository::get_for_user( $codex_provider_temporary_user_id );
				$codex_provider_assert( 'error' === (string) ( $codex_provider_pending_error['status'] ?? '' ), 'Terminal device-code failures should remain stored until the user starts over.' );
				$codex_provider_assert( 'Device code expired.' === (string) ( $codex_provider_pending_error['error'] ?? '' ), 'Stored terminal device-code failures should preserve the exact runtime error.' );

				$codex_provider_status = SupportChecks::current_user_status( $codex_provider_temporary_user_id );
				$codex_provider_assert( 'login_failed' === (string) ( $codex_provider_status['reason'] ?? '' ), 'Passive status reads should report a stored terminal login error.' );
				// @phpstan-ignore-next-line Verification mutates this request log through the pre_http_request hook.
				$codex_provider_assert( 1 === count( $codex_provider_login_status_requests ), 'Passive status reads should not poll the runtime again after a terminal login error is stored.' );

				$codex_provider_translate_filter = static function ( string $translation, string $text, string $domain ): string {
					if (
						'ai-provider-for-codex' === $domain
						&& str_contains( $text, 'This page manages your personal account link.' )
					) {
						return 'This page manages your personal account link. 100% reliable. <a href="%1$s">Plugin settings</a> control the local runtime shared by all users. <a href="%2$s">Settings &gt; Connectors</a> shows overall provider status.';
					}

					return $translation;
				};

				add_filter( 'gettext', $codex_provider_translate_filter, 10, 3 );

				try {
					ob_start();
					UserConnectionPage::render_page();
					$codex_provider_connection_page_html = (string) ob_get_clean();
				} finally {
					remove_filter( 'gettext', $codex_provider_translate_filter, 10 );
				}

				$codex_provider_assert( false !== strpos( $codex_provider_connection_page_html, 'Connection attempt failed' ), 'User connection page should describe a stored terminal login error.' );
				$codex_provider_assert( false !== strpos( $codex_provider_connection_page_html, 'Start connection again' ), 'User connection page should let the user restart after a terminal login error.' );
				$codex_provider_assert( false !== strpos( $codex_provider_connection_page_html, 'Device code expired.' ), 'User connection page should render the stored terminal login error.' );
				$codex_provider_assert( false !== strpos( $codex_provider_connection_page_html, '100% reliable' ), 'User connection page should render translated strings containing a literal percent sign.' );
			}
		);

		ConnectionRepository::upsert(
			$codex_provider_temporary_user_id,
			[
				'connectionId' => $codex_provider_temporary_connection_id,
				'status'       => 'linked',
				'account'      => [
					'email'    => $codex_provider_user_email,
					'planType' => 'plus',
					'authMode' => 'chatgpt',
				],
			]
		);
		ConnectionRepository::upsert(
			$codex_provider_temporary_user_id,
			[
				'connectionId' => $codex_provider_temporary_connection_id,
				'status'       => 'linked',
				'account'      => [
					'email'    => null,
					'planType' => null,
					'authMode' => null,
				],
			]
		);
		$codex_provider_cleared_connection = ConnectionRepository::get_for_user( $codex_provider_temporary_user_id );
		$codex_provider_assert( is_array( $codex_provider_cleared_connection ), 'Connection row should still exist after clearing account fields.' );
		$codex_provider_assert( '' === (string) $codex_provider_cleared_connection['account_email'], 'Account email should clear when the runtime no longer reports one.' );
		$codex_provider_assert( '' === (string) $codex_provider_cleared_connection['plan_type'], 'Plan type should clear when the runtime no longer reports one.' );
		$codex_provider_assert( '' === (string) $codex_provider_cleared_connection['auth_mode'], 'Auth mode should clear when the runtime no longer reports one.' );

		ConnectionService::invalidate_local_connection( $codex_provider_temporary_user_id );
		PendingConnectionRepository::upsert(
			$codex_provider_temporary_user_id,
			[
				'authSessionId'   => 'auth_recover',
				'status'          => 'pending',
				'verificationUrl' => 'https://chatgpt.com/device',
				'userCode'        => 'WXYZ-1234',
			]
		);
		$codex_provider_with_mock_runtime(
			static function ( $preempt, array $args, string $url ) use ( $codex_provider_base_url, $codex_provider_http_json_response, $codex_provider_temporary_model_a, $codex_provider_user_email ) {
				if ( 0 !== strpos( $url, $codex_provider_base_url ) ) {
					return $preempt;
				}

				$path = (string) wp_parse_url( $url, PHP_URL_PATH );

				if ( '/v1/login/status' === $path ) {
					return $codex_provider_http_json_response(
						200,
						[
							'authSessionId'   => 'auth_recover',
							'status'          => 'missing',
							'authStored'      => true,
							'verificationUrl' => null,
							'userCode'        => null,
							'error'           => 'Login session was not found in the local runtime.',
						]
					);
				}

				if ( '/v1/account/snapshot' === $path ) {
					return $codex_provider_http_json_response(
						200,
						[
							'account'      => [
								'email'    => $codex_provider_user_email,
								'planType' => 'plus',
								'authMode' => 'chatgpt',
								'type'     => 'chatgpt',
							],
							'authStored'   => true,
							'defaultModel' => $codex_provider_temporary_model_a,
							'models'       => [
								[
									'id'    => $codex_provider_temporary_model_a,
									'label' => 'Codex Verify Alpha',
								],
							],
							'rateLimits'   => [],
						]
					);
				}

				return $preempt;
			},
			static function () use ( $codex_provider_assert, $codex_provider_temporary_user_id ) {
				$codex_provider_result = ( new ConnectionService() )->poll_connect_status( $codex_provider_temporary_user_id );
				$codex_provider_assert( 'connected' === (string) ( $codex_provider_result['status'] ?? '' ), 'Missing login sessions should recover when auth is already stored.' );
				$codex_provider_assert( null === PendingConnectionRepository::get_for_user( $codex_provider_temporary_user_id ), 'Recovered login sessions should clear pending auth state.' );
					$codex_provider_assert( is_array( ConnectionRepository::get_for_user( $codex_provider_temporary_user_id ) ), 'Recovered login sessions should restore the local connection row.' );
				}
			);

			ConnectionService::invalidate_local_connection( $codex_provider_temporary_user_id );
			PendingConnectionRepository::upsert(
				$codex_provider_temporary_user_id,
				[
					'authSessionId'   => 'auth_recover_legacy',
					'status'          => 'pending',
					'verificationUrl' => 'https://chatgpt.com/device',
					'userCode'        => 'LEGACY-404',
				]
			);
			$codex_provider_with_mock_runtime(
				static function ( $preempt, array $args, string $url ) use ( $codex_provider_base_url, $codex_provider_http_json_response, $codex_provider_temporary_model_a, $codex_provider_user_email ) {
					if ( 0 !== strpos( $url, $codex_provider_base_url ) ) {
						return $preempt;
					}

					$path = (string) wp_parse_url( $url, PHP_URL_PATH );

					if ( '/v1/login/status' === $path ) {
						return $codex_provider_http_json_response(
							404,
							[
								'authSessionId'   => 'auth_recover_legacy',
								'status'          => 'missing',
								'authStored'      => true,
								'verificationUrl' => null,
								'userCode'        => null,
								'error'           => 'Login session was not found in the local runtime.',
							]
						);
					}

					if ( '/v1/account/snapshot' === $path ) {
						return $codex_provider_http_json_response(
							200,
							[
								'account'      => [
									'email'    => $codex_provider_user_email,
									'planType' => 'plus',
									'authMode' => 'chatgpt',
									'type'     => 'chatgpt',
								],
								'authStored'   => true,
								'defaultModel' => $codex_provider_temporary_model_a,
								'models'       => [
									[
										'id'    => $codex_provider_temporary_model_a,
										'label' => 'Codex Verify Alpha',
									],
								],
								'rateLimits'   => [],
							]
						);
					}

					return $preempt;
				},
				static function () use ( $codex_provider_assert, $codex_provider_temporary_user_id ) {
					$codex_provider_result = ( new ConnectionService() )->poll_connect_status( $codex_provider_temporary_user_id );
					$codex_provider_assert( 'connected' === (string) ( $codex_provider_result['status'] ?? '' ), 'Legacy 404 missing-session responses should still recover when auth is already stored.' );
					$codex_provider_assert( null === PendingConnectionRepository::get_for_user( $codex_provider_temporary_user_id ), 'Legacy 404 recovery should clear pending auth state after reconnecting.' );
					$codex_provider_assert( is_array( ConnectionRepository::get_for_user( $codex_provider_temporary_user_id ) ), 'Legacy 404 recovery should restore the local connection row.' );
				}
			);

			ConnectionService::invalidate_local_connection( $codex_provider_temporary_user_id );
			PendingConnectionRepository::upsert(
				$codex_provider_temporary_user_id,
				[
				'authSessionId'   => 'auth_missing',
				'status'          => 'pending',
				'verificationUrl' => 'https://chatgpt.com/device',
				'userCode'        => 'QRST-5678',
			]
		);
		$codex_provider_with_mock_runtime(
			static function ( $preempt, array $args, string $url ) use ( $codex_provider_base_url, $codex_provider_http_json_response ) {
				if ( 0 !== strpos( $url, $codex_provider_base_url ) ) {
					return $preempt;
				}

				$path = (string) wp_parse_url( $url, PHP_URL_PATH );

				if ( '/v1/login/status' === $path ) {
					return $codex_provider_http_json_response(
						200,
						[
							'authSessionId'   => 'auth_missing',
							'status'          => 'missing',
							'authStored'      => false,
							'verificationUrl' => null,
							'userCode'        => null,
							'error'           => 'Login session was not found in the local runtime.',
						]
					);
				}

					if ( '/v1/account/snapshot' === $path ) {
						return $codex_provider_http_json_response(
							409,
						[
							'error' => [
								'code'    => 'auth_required',
								'message' => 'No stored ChatGPT or Codex auth is available for this WordPress user.',
							],
						]
					);
				}

				return $preempt;
			},
			static function () use ( $codex_provider_assert, $codex_provider_temporary_user_id ) {
				$codex_provider_result = ( new ConnectionService() )->poll_connect_status( $codex_provider_temporary_user_id );
				$codex_provider_assert( 'missing' === (string) ( $codex_provider_result['status'] ?? '' ), 'Missing login sessions without stored auth should require a new connection.' );
				$codex_provider_assert( null === PendingConnectionRepository::get_for_user( $codex_provider_temporary_user_id ), 'Missing login sessions should clear pending auth state when auth is gone.' );
					$codex_provider_assert( null === ConnectionRepository::get_for_user( $codex_provider_temporary_user_id ), 'Missing login sessions without stored auth should not leave a local connection row behind.' );
				}
			);

			ConnectionService::invalidate_local_connection( $codex_provider_temporary_user_id );
			PendingConnectionRepository::upsert(
				$codex_provider_temporary_user_id,
				[
					'authSessionId'   => 'auth_missing_retry',
					'status'          => 'pending',
					'verificationUrl' => 'https://chatgpt.com/device',
					'userCode'        => 'MISS-5000',
				]
			);
			$codex_provider_with_mock_runtime(
				static function ( $preempt, array $args, string $url ) use ( $codex_provider_base_url, $codex_provider_http_json_response ) {
					if ( 0 !== strpos( $url, $codex_provider_base_url ) ) {
						return $preempt;
					}

					$path = (string) wp_parse_url( $url, PHP_URL_PATH );

					if ( '/healthz' === $path ) {
						return $codex_provider_http_json_response(
							200,
							[
								'ok'      => true,
								'service' => 'codex-wp-sidecar',
							]
						);
					}

					if ( '/v1/login/status' === $path ) {
						return $codex_provider_http_json_response(
							200,
							[
								'authSessionId'   => 'auth_missing_retry',
								'status'          => 'missing',
								'authStored'      => true,
								'verificationUrl' => null,
								'userCode'        => null,
								'error'           => 'Login session was not found in the local runtime.',
							]
						);
					}

					if ( '/v1/account/snapshot' === $path ) {
						return $codex_provider_http_json_response(
							500,
							[
								'error' => [
									'code'    => 'runtime_failure',
									'message' => 'Missing-session recovery failed during verification.',
								],
							]
						);
					}

					return $preempt;
				},
				static function () use ( $codex_provider_assert, $codex_provider_temporary_user_id ) {
					$codex_provider_result = ( new ConnectionService() )->poll_connect_status( $codex_provider_temporary_user_id );
					$codex_provider_assert( 'error' === (string) ( $codex_provider_result['status'] ?? '' ), 'Missing-session recovery should surface an error when snapshot refresh fails transiently.' );
					$codex_provider_assert( 'completed' === (string) ( PendingConnectionRepository::get_for_user( $codex_provider_temporary_user_id )['status'] ?? '' ), 'Missing-session recovery failures should preserve a retryable completed pending state.' );
					$codex_provider_assert( 'Missing-session recovery failed during verification.' === (string) ( PendingConnectionRepository::get_for_user( $codex_provider_temporary_user_id )['error'] ?? '' ), 'Missing-session recovery failures should preserve the retryable error message.' );

					$codex_provider_status = SupportChecks::current_user_status( $codex_provider_temporary_user_id );
					$codex_provider_assert( 'login_pending' === (string) ( $codex_provider_status['reason'] ?? '' ), 'Retryable missing-session recovery failures should keep the user in a recoverable pending state.' );
					$codex_provider_assert( 'completed' === (string) ( $codex_provider_status['pendingConnection']['status'] ?? '' ), 'Retryable missing-session recovery failures should keep the completed pending marker.' );
					$codex_provider_assert( 'Missing-session recovery failed during verification.' === (string) ( $codex_provider_status['pendingConnection']['error'] ?? '' ), 'Retryable missing-session recovery failures should keep the stored error message.' );
				}
			);

			ConnectionService::invalidate_local_connection( $codex_provider_temporary_user_id );
			ConnectionRepository::upsert(
				$codex_provider_temporary_user_id,
				[
				'connectionId' => $codex_provider_temporary_connection_id,
				'status'       => 'linked',
				'account'      => [
					'email'    => $codex_provider_user_email,
					'planType' => 'plus',
					'authMode' => 'chatgpt',
				],
			]
		);
		ConnectionSnapshotRepository::upsert(
			$codex_provider_temporary_connection_id,
			[
				'models'       => [
					[
						'id'    => $codex_provider_temporary_model_a,
						'label' => 'Codex Verify Alpha',
					],
				],
				'defaultModel' => $codex_provider_temporary_model_a,
				'rateLimits'   => [],
			]
			);
			ModelCatalogState::update_user_preferred_model( $codex_provider_temporary_user_id, $codex_provider_temporary_model_a );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Verification intentionally updates plugin data directly.
			$wpdb->update(
				ConnectionRepository::table_name(),
				[
				'last_synced_at' => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ),
			],
			[
				'wp_user_id' => $codex_provider_temporary_user_id,
			],
			[ '%s' ],
			[ '%d' ]
		);
		$codex_provider_with_mock_runtime(
			static function ( $preempt, array $args, string $url ) use ( $codex_provider_base_url, $codex_provider_http_json_response ) {
				if ( 0 !== strpos( $url, $codex_provider_base_url ) ) {
					return $preempt;
				}

				$path = (string) wp_parse_url( $url, PHP_URL_PATH );

				if ( '/healthz' === $path ) {
					return $codex_provider_http_json_response(
						200,
						[
							'ok'      => true,
							'service' => 'codex-wp-sidecar',
						]
					);
				}

				if ( '/v1/account/snapshot' === $path ) {
					return $codex_provider_http_json_response(
						409,
						[
							'error' => [
								'code'    => 'auth_required',
								'message' => 'No stored ChatGPT or Codex auth is available for this WordPress user.',
							],
						]
					);
				}

				return $preempt;
			},
				static function () use ( $codex_provider_assert, $codex_provider_temporary_connection_id, $codex_provider_temporary_model_a, $codex_provider_temporary_user_id ) {
					$codex_provider_status = SupportChecks::current_user_status( $codex_provider_temporary_user_id );

					$codex_provider_assert( 'ready' === (string) $codex_provider_status['reason'], 'Passive status reads should keep using the stored connection snapshot.' );
					$codex_provider_assert( null !== ConnectionRepository::get_for_user( $codex_provider_temporary_user_id ), 'Passive status reads should not delete the local connection row.' );
					$codex_provider_assert( '' !== ModelCatalogState::get_user_preferred_model( $codex_provider_temporary_user_id ), 'Passive status reads should not clear the preferred model.' );

				try {
					( new ConnectionService() )->refresh_snapshot( $codex_provider_temporary_user_id );
					$codex_provider_assert( false, 'Explicit snapshot refresh should fail when runtime auth disappears.' );
				} catch ( RuntimeException $exception ) {
					$codex_provider_assert( false !== strpos( $exception->getMessage(), 'no longer has a stored ChatGPT or Codex login' ), 'Explicit snapshot refresh should surface the auth-required reconnect message.' );
				}

					$codex_provider_assert( null === ConnectionRepository::get_for_user( $codex_provider_temporary_user_id ), 'Auth loss should delete the local connection row.' );
					$codex_provider_assert( null === ConnectionSnapshotRepository::get( $codex_provider_temporary_connection_id ), 'Auth loss should delete the local snapshot row.' );
					$codex_provider_assert( '' === ModelCatalogState::get_user_preferred_model( $codex_provider_temporary_user_id ), 'Auth loss should clear the preferred model.' );
					$codex_provider_status = SupportChecks::current_user_status( $codex_provider_temporary_user_id );
					$codex_provider_assert( 'settings_fallback' === (string) ( $codex_provider_status['catalog']['source'] ?? '' ), 'Auth loss should fall back to configured models instead of stale snapshot models.' );
					$codex_provider_assert(
						! in_array( $codex_provider_temporary_model_a, $codex_provider_status['catalog']['model_ids'] ?? [], true ),
					'Auth loss should not keep stale snapshot models in the effective catalog.'
				);
			}
			);
		} catch ( \Throwable $codex_provider_throwable ) {
		$codex_provider_failure = $codex_provider_throwable;
	} finally {
		wp_set_current_user( $codex_provider_original_user_id );
		ConnectionSnapshotRepository::delete( $codex_provider_temporary_connection_id );

		if ( $codex_provider_original_env_bearer_exists ) {
			putenv( 'CODEX_WP_BEARER_TOKEN=' . $codex_provider_original_env_bearer );
			$_ENV['CODEX_WP_BEARER_TOKEN'] = $codex_provider_original_env_bearer;
		} else {
			putenv( 'CODEX_WP_BEARER_TOKEN' );
			unset( $_ENV['CODEX_WP_BEARER_TOKEN'] );
		}

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
