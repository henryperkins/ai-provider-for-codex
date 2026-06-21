<?php
/**
 * Repeatable verification checks for the Codex provider plugin.
 *
 * Run with:
 * wp --path=/path/to/site eval-file wp-content/plugins/ai-provider-for-codex/scripts/verify.php
 */

use Htperkins\AIProviderForCodex\Auth\ConnectionRepository;
use Htperkins\AIProviderForCodex\Auth\ConnectionRefreshScheduler;
use Htperkins\AIProviderForCodex\Auth\ConnectionService;
use Htperkins\AIProviderForCodex\Auth\ConnectionSnapshotRepository;
use Htperkins\AIProviderForCodex\Auth\PendingConnectionRepository;
use Htperkins\AIProviderForCodex\Admin\ConnectorApprovalIntegration;
use Htperkins\AIProviderForCodex\Admin\ConnectorsIntegration;
use Htperkins\AIProviderForCodex\Admin\SiteSettings;
use Htperkins\AIProviderForCodex\Admin\UserConnectionPage;
use Htperkins\AIProviderForCodex\Database\Installer;
use Htperkins\AIProviderForCodex\Provider\CodexProvider;
use Htperkins\AIProviderForCodex\Provider\ModelCatalogState;
use Htperkins\AIProviderForCodex\Provider\SupportChecks;
use Htperkins\AIProviderForCodex\REST\ConnectController;
use Htperkins\AIProviderForCodex\Runtime\HealthMonitor;
use Htperkins\AIProviderForCodex\Runtime\Settings;
use WordPress\AiClient\AiClient;
use WordPress\AiClient\Files\DTO\File;
use WordPress\AiClient\Files\Enums\FileTypeEnum;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\UserMessage;
use WordPress\AiClient\Messages\Enums\ModalityEnum;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Providers\Models\DTO\ModelRequirements;
use WordPress\AiClient\Providers\Models\DTO\RequiredOption;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;

if ( ! defined( 'ABSPATH' ) ) {
	throw new RuntimeException( 'Run this script with wp eval-file.' );
}

if ( ! defined( 'Htperkins\\AIProviderForCodex\\PLUGIN_FILE' ) ) {
	require_once dirname( __DIR__ ) . '/ai-provider-for-codex.php';
}

\Htperkins\AIProviderForCodex\load();

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

		$codex_provider_reset_ai_client_registry = static function (): void {
			$property = new ReflectionProperty( AiClient::class, 'defaultRegistry' );
			$property->setAccessible( true );
			$property->setValue( null, null );

			$property = new ReflectionProperty( \WordPress\AiClient\Providers\AbstractProvider::class, 'modelMetadataDirectoryCache' );
			$property->setAccessible( true );
			$property->setValue( null, [] );
		};

		$codex_provider_assert(
			is_wp_version_compatible( \Htperkins\AIProviderForCodex\MIN_WP_VERSION ),
			'Scriptorium AI Provider for Codex verification requires WordPress 7.0 or newer.'
		);
		$codex_provider_assert(
			class_exists( AiClient::class ),
			'Scriptorium AI Provider for Codex requires AI Client 1.0 or newer, but the AI Client class is not available.'
		);
		$codex_provider_assert(
			version_compare( AiClient::VERSION, '1.0', '>=' ),
			'Scriptorium AI Provider for Codex requires AI Client 1.0 or newer.'
		);
		$codex_provider_assert(
			! defined( 'WPAI_VERSION' ) || version_compare( (string) constant( 'WPAI_VERSION' ), '1.0', '>=' ),
			'Scriptorium AI Provider for Codex requires WordPress AI plugin 1.0 or newer when the standalone plugin is installed.'
		);

		$codex_provider_disable_ai_filter = static function (): bool {
			return false;
		};

		add_filter( 'wp_supports_ai', $codex_provider_disable_ai_filter );

		try {
			$codex_provider_reset_ai_client_registry();
			\Htperkins\AIProviderForCodex\register_provider();
			$codex_provider_assert(
				! AiClient::defaultRegistry()->hasProvider( CodexProvider::class ),
				'Codex provider should not register when wp_supports_ai disables AI globally.'
			);
		} finally {
			remove_filter( 'wp_supports_ai', $codex_provider_disable_ai_filter );
		}

		$codex_provider_reset_ai_client_registry();
		\Htperkins\AIProviderForCodex\register_provider();
		$codex_provider_assert(
			AiClient::defaultRegistry()->hasProvider( CodexProvider::class ),
			'Codex provider should register when AI is supported and the AI Client is available.'
		);

			$codex_provider_metadata = CodexProvider::metadata();
			$codex_provider_assert(
				SiteSettings::page_url() === $codex_provider_metadata->getCredentialsUrl(),
				'Codex provider credentials URL should point to the site-level settings page.'
			);

		$codex_provider_original_options             = [
			Settings::OPTION_RUNTIME_BASE_URL              => get_option( Settings::OPTION_RUNTIME_BASE_URL, null ),
			Settings::OPTION_RUNTIME_BEARER                => get_option( Settings::OPTION_RUNTIME_BEARER, null ),
			Settings::OPTION_ALLOWED_MODELS                => get_option( Settings::OPTION_ALLOWED_MODELS, null ),
			'htperkins_aipfc_connector_self_approval_seeded' => get_option( 'htperkins_aipfc_connector_self_approval_seeded', null ),
			'wpai_features_enabled'                        => get_option( 'wpai_features_enabled', null ),
			'wpai_feature_connector-approval_enabled'      => get_option( 'wpai_feature_connector-approval_enabled', null ),
		];
		$codex_provider_legacy_default_model_option = 'htperkins_aipfc_legacy_default_model';
		$codex_provider_temporary_user_id           = 0;
		$codex_provider_original_user_id            = get_current_user_id();
		$codex_provider_temporary_connection_id     = 'codex-verify-' . wp_generate_uuid4();
		$htperkins_aipfc_model_token                 = strtolower( wp_generate_password( 8, false, false ) );
		$codex_provider_temporary_model_a           = 'codex-verify-' . $htperkins_aipfc_model_token . '-alpha';
			$codex_provider_temporary_model_b           = 'codex-verify-' . $htperkins_aipfc_model_token . '-beta';
			$codex_provider_temporary_fallback_a        = 'codex-fallback-' . $htperkins_aipfc_model_token . '-alpha';
			$codex_provider_temporary_fallback_b        = 'codex-fallback-' . $htperkins_aipfc_model_token . '-beta';
				$codex_provider_image_model                 = 'codex-image';
				$codex_provider_original_env_base_url       = getenv( 'CODEX_WP_RUNTIME_BASE_URL' );
			$codex_provider_original_env_base_url_exists = false !== $codex_provider_original_env_base_url;
				$codex_provider_original_env_bearer         = getenv( 'CODEX_WP_BEARER_TOKEN' );
			$codex_provider_original_env_bearer_exists  = false !== $codex_provider_original_env_bearer;
		/** @var \Throwable|null $codex_provider_failure */
		$codex_provider_failure = null;

	try {
		update_option( $codex_provider_legacy_default_model_option, 'legacy-model' );
		update_option( 'htperkins_aipfc_schema_version', '4' );
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
				'6' === (string) get_option( 'htperkins_aipfc_schema_version', '' ),
				'Schema version was not upgraded to 6.'
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
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Verification inspects plugin custom tables directly.
			$codex_provider_snapshot_columns = $wpdb->get_col(
				$wpdb->prepare( 'SHOW COLUMNS FROM %i', ConnectionSnapshotRepository::table_name() ),
				0
			);
			$codex_provider_assert( is_array( $codex_provider_snapshot_columns ), 'Could not read snapshot table columns.' );
			$codex_provider_assert( in_array( 'capabilities_json', $codex_provider_snapshot_columns, true ), 'Snapshot table should include capabilities_json.' );

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
			$codex_provider_assert(
				'ws://127.0.0.1:4500' === Settings::normalize_base_url( 'ws://127.0.0.1:4500' ),
				'Site-level Codex app-server endpoint should preserve a local ws:// URL.'
			);
			putenv( 'CODEX_WP_RUNTIME_BASE_URL=ws://127.0.0.1:4500' );
			$_ENV['CODEX_WP_RUNTIME_BASE_URL'] = 'ws://127.0.0.1:4500';
			update_option( Settings::OPTION_RUNTIME_BEARER, '' );
			$codex_provider_assert(
				Settings::has_required_configuration(),
				'Site-level Codex app-server configuration should not require a sidecar bearer token.'
			);
			putenv( 'CODEX_WP_RUNTIME_BASE_URL' );
			unset( $_ENV['CODEX_WP_RUNTIME_BASE_URL'] );
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
				],
				'defaultModel' => $codex_provider_temporary_model_a,
				'checkedAt'    => gmdate( 'c' ),
			]
		);
		$codex_provider_no_image_catalog = ModelCatalogState::get_user_snapshot_catalog( $codex_provider_temporary_user_id );
		$codex_provider_assert(
			! in_array( $codex_provider_image_model, $codex_provider_no_image_catalog['model_ids'], true ),
			'Snapshots without imageGeneration capability must not expose codex-image.'
		);
		wp_set_current_user( $codex_provider_temporary_user_id );
		$codex_provider_reset_ai_client_registry();
		\Htperkins\AIProviderForCodex\register_provider();
		$codex_provider_direct_image_lookup_failed = false;
		try {
			AiClient::defaultRegistry()->getProviderModel( 'codex', $codex_provider_image_model );
		} catch ( \Throwable $codex_provider_direct_image_lookup_error ) {
			$codex_provider_direct_image_lookup_failed = true;
		}
		$codex_provider_assert(
			true === $codex_provider_direct_image_lookup_failed,
			'Direct codex-image lookup must fail closed without imageGeneration capability.'
		);

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
				'capabilities' => [
					'imageGeneration' => true,
					'namespaceTools'  => true,
					'webSearch'       => false,
				],
			]
		);
		$codex_provider_reset_ai_client_registry();
		\Htperkins\AIProviderForCodex\register_provider();

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
			$codex_provider_assert(
				true === ( $codex_provider_snapshot['capabilities']['imageGeneration'] ?? null )
				&& true === ( $codex_provider_snapshot['capabilities']['namespaceTools'] ?? null )
				&& false === ( $codex_provider_snapshot['capabilities']['webSearch'] ?? null ),
				'Snapshot capabilities were not persisted.'
			);
			$codex_provider_site_snapshots = ConnectionSnapshotRepository::list_active_for_site_catalog();
			$codex_provider_site_snapshot  = null;
			foreach ( $codex_provider_site_snapshots as $codex_provider_candidate_snapshot ) {
				if ( $codex_provider_temporary_connection_id === (string) ( $codex_provider_candidate_snapshot['connection_id'] ?? '' ) ) {
					$codex_provider_site_snapshot = $codex_provider_candidate_snapshot;
					break;
				}
			}
			$codex_provider_assert(
				is_array( $codex_provider_site_snapshot )
				&& true === ( $codex_provider_site_snapshot['capabilities']['imageGeneration'] ?? null ),
				'Site catalog snapshots should expose decoded capabilities.'
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
			$codex_provider_assert(
				$codex_provider_temporary_model_a === (string) ( $codex_provider_user_catalog['selected_text_model'] ?? '' ),
				'User catalog selected_text_model should default to the first text model.'
			);
			$codex_provider_assert(
				in_array( $codex_provider_image_model, $codex_provider_user_catalog['model_ids'], true ),
				'Image-capable user catalog should include codex-image.'
			);
			$codex_provider_assert(
				in_array( $codex_provider_image_model, $codex_provider_user_catalog['image_model_ids'] ?? [], true ),
				'Image-capable user catalog should expose codex-image in image_model_ids.'
			);
			$codex_provider_assert(
				! in_array( $codex_provider_image_model, $codex_provider_user_catalog['text_model_ids'] ?? [], true ),
				'Image model should not be listed as a text model.'
			);

			wp_set_current_user( $codex_provider_temporary_user_id );
			ModelCatalogState::update_user_preferred_model( $codex_provider_temporary_user_id, $codex_provider_image_model );
			$codex_provider_catalog_with_image_preference = ModelCatalogState::get_user_snapshot_catalog( $codex_provider_temporary_user_id );
			$codex_provider_assert(
				$codex_provider_temporary_model_a === (string) ( $codex_provider_catalog_with_image_preference['selected_text_model'] ?? '' ),
				'codex-image must not become the selected text model.'
			);
			$codex_provider_preferred_text_models = ( new \Htperkins\AIProviderForCodex\Plugin() )->filter_preferred_text_models( [] );
			$codex_provider_assert(
				[ [ 'codex', $codex_provider_temporary_model_a ] ] === $codex_provider_preferred_text_models,
				'wpai_preferred_text_models must never emit codex-image.'
			);
			$codex_provider_preferred_image_models = ( new \Htperkins\AIProviderForCodex\Plugin() )->filter_preferred_image_models( [] );
			$codex_provider_assert(
				[ [ 'codex', $codex_provider_image_model ] ] === $codex_provider_preferred_image_models,
				'wpai_preferred_image_models should prefer codex-image when the current user has image generation support.'
			);
			$codex_provider_preferred_vision_models = ( new \Htperkins\AIProviderForCodex\Plugin() )->filter_preferred_vision_models( [] );
			$codex_provider_assert(
				[ [ 'codex', $codex_provider_temporary_model_a ] ] === $codex_provider_preferred_vision_models,
				'wpai_preferred_vision_models should prefer the selected Codex text model for vision workflows.'
			);
			$codex_provider_filtered_image_models = apply_filters( 'wpai_preferred_image_models', [] );
			$codex_provider_assert(
				[ 'codex', $codex_provider_image_model ] === ( $codex_provider_filtered_image_models[0] ?? null ),
				'The registered wpai_preferred_image_models filter should put codex-image before other image providers.'
			);
			$codex_provider_filtered_vision_models = apply_filters( 'wpai_preferred_vision_models', [] );
			$codex_provider_assert(
				[ 'codex', $codex_provider_temporary_model_a ] === ( $codex_provider_filtered_vision_models[0] ?? null ),
				'The registered wpai_preferred_vision_models filter should put Codex before other vision providers.'
			);
			ModelCatalogState::update_user_preferred_model( $codex_provider_temporary_user_id, $codex_provider_temporary_model_a );

			$codex_provider_image_model_instance = AiClient::defaultRegistry()->getProviderModel( 'codex', $codex_provider_image_model );
			$codex_provider_assert(
				is_a( $codex_provider_image_model_instance, 'Htperkins\AIProviderForCodex\\Models\\CodexImageGenerationModel' ),
				'codex-image should instantiate CodexImageGenerationModel.'
			);
			$codex_provider_image_metadata   = $codex_provider_image_model_instance->metadata();
			$codex_provider_capability_names = array_map(
				static function ( $capability ): string {
					return $capability->value;
				},
				$codex_provider_image_metadata->getSupportedCapabilities()
			);
			$codex_provider_option_names = array_map(
				static function ( $option ): string {
					return $option->getName()->value;
				},
				$codex_provider_image_metadata->getSupportedOptions()
			);
			$codex_provider_assert(
				in_array( CapabilityEnum::imageGeneration()->value, $codex_provider_capability_names, true )
				&& in_array( CapabilityEnum::chatHistory()->value, $codex_provider_capability_names, true ),
				'codex-image metadata should support image generation and chat history.'
			);
			$codex_provider_assert(
				! in_array( OptionEnum::outputSchema()->value, $codex_provider_option_names, true ),
				'codex-image metadata should not support output schema.'
			);
			$codex_provider_assert(
				in_array( OptionEnum::outputFileType()->value, $codex_provider_option_names, true ),
				'codex-image metadata should support inline output file requests from the WordPress AI plugin.'
			);
			$codex_provider_image_config = new ModelConfig();
			$codex_provider_image_config->setOutputFileType( FileTypeEnum::inline() );
			$codex_provider_image_requirements = ModelRequirements::fromPromptData(
				CapabilityEnum::imageGeneration(),
				[
					new UserMessage(
						[
							new MessagePart( 'Draw a blue square.' ),
						]
					),
				],
				$codex_provider_image_config
			);
			HealthMonitor::record_success();
			$codex_provider_image_matches      = AiClient::defaultRegistry()->findProviderModelsMetadataForSupport( 'codex', $codex_provider_image_requirements );
			$codex_provider_image_match_ids    = array_map(
				static function ( $model_metadata ): string {
					return $model_metadata->getId();
				},
				$codex_provider_image_matches
			);
			$codex_provider_assert(
				in_array( $codex_provider_image_model, $codex_provider_image_match_ids, true ),
				'The AI Client should match codex-image for inline image-generation prompts.'
			);
			$codex_provider_text_model_instance = AiClient::defaultRegistry()->getProviderModel( 'codex', $codex_provider_temporary_model_a );
			$codex_provider_assert(
				is_a( $codex_provider_text_model_instance, 'Htperkins\AIProviderForCodex\\Models\\CodexTextGenerationModel' ),
				'Text model IDs should still instantiate CodexTextGenerationModel.'
			);
			$codex_provider_vision_requirements = new ModelRequirements(
				[ CapabilityEnum::textGeneration() ],
				[
					new RequiredOption(
						OptionEnum::inputModalities(),
						[ ModalityEnum::text(), ModalityEnum::image() ]
					),
				]
			);
			$codex_provider_vision_matches      = AiClient::defaultRegistry()->findProviderModelsMetadataForSupport( 'codex', $codex_provider_vision_requirements );
			$codex_provider_vision_match_ids    = array_map(
				static function ( $model_metadata ): string {
					return $model_metadata->getId();
				},
				$codex_provider_vision_matches
			);
			$codex_provider_assert(
				in_array( $codex_provider_temporary_model_a, $codex_provider_vision_match_ids, true ),
				'The AI Client should match Codex text models for vision prompts used by Alt Text Generation.'
			);

			ob_start();
			SiteSettings::render_page();
		$codex_provider_site_settings_html = (string) ob_get_clean();
		$codex_provider_assert(
			false !== strpos( $codex_provider_site_settings_html, $codex_provider_temporary_fallback_a ) && false !== strpos( $codex_provider_site_settings_html, $codex_provider_temporary_fallback_b ),
			'Site settings should render configured fallback models.'
		);
		$codex_provider_assert(
			false !== strpos( $codex_provider_site_settings_html, 'codex-provider-admin-page' ),
			'Site settings should render the shared Connectors-like page wrapper.'
		);
		$codex_provider_assert(
			false !== strpos( $codex_provider_site_settings_html, 'codex-provider-page-header' ),
			'Site settings should render a compact page header.'
		);
		$codex_provider_assert(
			false !== strpos( $codex_provider_site_settings_html, 'Configure the local Codex runtime used by this site' ),
			'Site settings should render the compact Connectors-style subtitle.'
		);
		$codex_provider_assert(
			false !== strpos( $codex_provider_site_settings_html, 'codex-provider-card codex-provider-card--runtime' ),
			'Site settings should render a runtime card.'
		);
		$codex_provider_assert(
			false !== strpos( $codex_provider_site_settings_html, 'codex-provider-card codex-provider-card--settings' ),
			'Site settings should render a runtime settings card.'
		);
		$codex_provider_assert(
			false !== strpos( $codex_provider_site_settings_html, 'codex-provider-card codex-provider-card--setup' ),
			'Site settings should render a setup card.'
		);
		$codex_provider_assert(
			false !== strpos( $codex_provider_site_settings_html, 'codex-provider-card__logo' )
			&& false !== strpos( $codex_provider_site_settings_html, 'src/Provider/logo.svg' ),
			'Site settings cards should use the Codex provider logo instead of custom letter tiles.'
		);
		$codex_provider_assert(
			false === strpos( $codex_provider_site_settings_html, 'codex-provider-card__icon' ),
			'Site settings should not render bespoke letter-tile card icons.'
		);
		$codex_provider_assert(
			false !== strpos( $codex_provider_site_settings_html, 'codex-provider-details' ),
			'Site settings should keep generated setup snippets in compact details blocks.'
		);
		$codex_provider_assert(
			false !== strpos( $codex_provider_site_settings_html, 'codex-provider-details--setup' )
			&& false !== strpos( $codex_provider_site_settings_html, 'Setup walkthrough' ),
			'Site settings should keep the long setup walkthrough behind a compact details summary.'
		);
		$codex_provider_assert(
			false === strpos( $codex_provider_site_settings_html, 'codex-status-cards' ),
			'Site settings should no longer render the old wide status-card layout.'
		);
		$codex_provider_assert(
			false === strpos( $codex_provider_site_settings_html, 'class="form-table"' ),
			'Site settings should no longer render a WordPress form table.'
		);
		$codex_provider_assert(
			false !== strpos( $codex_provider_site_settings_html, 'data-codex-diagnostics-results' ),
			'Site settings should preserve the diagnostics results container.'
		);
		$codex_provider_assert(
			false !== strpos( $codex_provider_site_settings_html, Settings::OPTION_RUNTIME_BASE_URL ),
			'Site settings should preserve the runtime URL option field.'
		);
		$codex_provider_assert(
			false !== strpos( $codex_provider_site_settings_html, Settings::OPTION_RUNTIME_BEARER ),
			'Site settings should preserve the runtime bearer token option field.'
		);
		$codex_provider_assert(
			false !== strpos( $codex_provider_site_settings_html, Settings::OPTION_ALLOWED_MODELS ),
			'Site settings should preserve the allowed models option field.'
		);
		$codex_provider_assert(
			false === strpos( $codex_provider_site_settings_html, '<style' ),
			'Site settings must not print inline style tags; styles are delivered through wp_add_inline_style().'
		);
		$codex_provider_assert(
			false !== strpos( $codex_provider_site_settings_html, 'Quick setup' ),
			'Site settings should render the quick setup guidance in the page body.'
		);
			$codex_provider_assert(
				false !== strpos( $codex_provider_site_settings_html, 'Codex uses a local app-server service' ),
				'Site settings should render the introductory setup guide text in the page body.'
			);
		$codex_provider_assert(
			method_exists( SiteSettings::class, 'render_help_tab' ),
			'Site settings help tab renderer should be available.'
		);
		ob_start();
		SiteSettings::render_help_tab();
		$codex_provider_site_settings_help_html = (string) ob_get_clean();
		$codex_provider_assert(
			false === strpos( $codex_provider_site_settings_help_html, 'Quick setup' ),
			'The contextual Help tab must not duplicate the full setup guide; the guide lives in the in-page Setup section.'
		);
		$codex_provider_assert(
			'' !== trim( $codex_provider_site_settings_help_html ),
			'The contextual Help tab should still render a short pointer to the Setup section.'
		);
		$codex_provider_assert(
			false === strpos( $codex_provider_site_settings_html, 'sidecar/scripts/install-systemd.sh' ),
			'The setup guide should not recommend non-shipped helper scripts.'
		);
		$codex_provider_assert(
			false === strpos( $codex_provider_site_settings_html, 'sidecar/systemd/codex-wp-sidecar.service' ),
			'The setup guide should not recommend non-shipped systemd template files.'
			);
			$codex_provider_assert(
				false !== strpos( $codex_provider_site_settings_html, 'EnvironmentFile=/etc/codex-app-server.env' ),
				'The setup guide should render the generated app-server systemd service snippet.'
			);
		$codex_provider_assert(
			false === strpos( $codex_provider_site_settings_html, $codex_provider_temporary_model_a ) && false === strpos( $codex_provider_site_settings_html, $codex_provider_temporary_model_b ),
			'Site settings should not render current-user snapshot models.'
		);
		$codex_provider_site_settings_filter_applied  = false;
		$codex_provider_site_settings_translate_filter = static function ( string $translation, string $text, string $domain ) use ( &$codex_provider_site_settings_filter_applied ): string {
				if (
					'ai-provider-for-codex' === $domain
					&& str_contains( $text, 'Site-level Codex runtime setup is managed on this page.' )
				) {
					$codex_provider_site_settings_filter_applied = true;

					return '<a href="%1$s">Settings &gt; Connectors</a> is the main entry point. 100% guided. Site-level Codex runtime setup is managed on this page.';
				}

			return $translation;
		};

		add_filter( 'gettext', $codex_provider_site_settings_translate_filter, 10, 3 );

		try {
			ob_start();
			SiteSettings::render_setup_guide();
			$codex_provider_site_settings_translated_help_html = (string) ob_get_clean();
		} finally {
			remove_filter( 'gettext', $codex_provider_site_settings_translate_filter, 10 );
		}

		$codex_provider_assert( $codex_provider_site_settings_filter_applied, 'Site settings percent-sign translation filter was not applied.' );
		$codex_provider_assert( false !== strpos( $codex_provider_site_settings_translated_help_html, '100% guided' ), 'The setup guide should render translated strings containing a literal percent sign.' );

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
			ob_start();
			UserConnectionPage::render_page();
			$codex_provider_connection_page_html = (string) ob_get_clean();

				$codex_provider_assert( false === strpos( $codex_provider_connection_page_html, '<style' ), 'User connection page must not print inline style tags; styles are delivered through wp_add_inline_style().' );
				$codex_provider_assert( false === strpos( $codex_provider_connection_page_html, '<script' ), 'User connection page must not print inline script tags; config flows through script module data.' );
				$codex_provider_connection_module_data = apply_filters( 'script_module_data_htperkins-ai-provider-for-codex/user-connection', [] );
				$codex_provider_assert( isset( $codex_provider_connection_module_data['startUrl'], $codex_provider_connection_module_data['restNonce'], $codex_provider_connection_module_data['text'] ), 'User connection page should expose its JSON config through script module data.' );
				$codex_provider_assert( 'auth_verify' === (string) ( $codex_provider_connection_module_data['currentPending']['authSessionId'] ?? '' ), 'Script module data should carry the pending device-code session for the enhanced connection flow.' );
			$codex_provider_user_page_source       = (string) file_get_contents( dirname( __DIR__ ) . '/src/Admin/UserConnectionPage.php' );
			$codex_provider_site_settings_source   = (string) file_get_contents( dirname( __DIR__ ) . '/src/Admin/SiteSettings.php' );
			$codex_provider_admin_style_source     = (string) file_get_contents( dirname( __DIR__ ) . '/src/Admin/AdminPageStyle.php' );
			$codex_provider_connection_flow_source = (string) file_get_contents( dirname( __DIR__ ) . '/assets/connection-flow.js' );
			$codex_provider_connector_source       = (string) file_get_contents( dirname( __DIR__ ) . '/assets/connectors.js' );
			$codex_provider_user_connection_source = (string) file_get_contents( dirname( __DIR__ ) . '/assets/user-connection.js' );
				$codex_provider_assert( false !== strpos( $codex_provider_user_page_source, 'script_module_data' ), 'User connection page should expose its config via the script module data filter.' );
				$codex_provider_assert( false !== strpos( $codex_provider_user_page_source, 'admin_enqueue_scripts' ), 'User connection page should enqueue its assets through admin_enqueue_scripts.' );
				$codex_provider_assert( false !== strpos( $codex_provider_site_settings_source, 'AdminPageStyle::css()' ), 'Site settings should use the shared Connectors-like admin page CSS helper.' );
				$codex_provider_assert( false !== strpos( $codex_provider_user_page_source, 'AdminPageStyle::css()' ), 'User connection page should use the shared Connectors-like admin page CSS helper.' );
				$codex_provider_assert( false !== strpos( $codex_provider_admin_style_source, 'final class AdminPageStyle' ), 'Shared admin page style helper should be available.' );
				$codex_provider_assert( false !== strpos( $codex_provider_admin_style_source, 'settings_page_ai-provider-for-codex' ), 'Shared admin CSS should scope white-surface rules to the settings page body class.' );
				$codex_provider_assert( false !== strpos( $codex_provider_admin_style_source, 'users_page_ai-provider-for-codex' ), 'Shared admin CSS should scope white-surface rules to the users page body class.' );
				$codex_provider_assert( false !== strpos( $codex_provider_admin_style_source, 'profile_page_ai-provider-for-codex' ), 'Shared admin CSS should include the profile page body class for lower-capability user routing.' );
				$codex_provider_assert( false !== strpos( $codex_provider_admin_style_source, 'max-width: 680px' ), 'Shared admin CSS should use the measured Connectors content width.' );
				$codex_provider_assert( false !== strpos( $codex_provider_admin_style_source, 'padding: 24px' ), 'Shared admin CSS should use the measured Connectors desktop shell padding.' );
				$codex_provider_assert( false !== strpos( $codex_provider_admin_style_source, 'padding: 20px' ), 'Shared admin CSS should use the measured Connectors desktop card padding.' );
				$codex_provider_assert( false !== strpos( $codex_provider_admin_style_source, '@media (max-width: 480px)' ), 'Shared admin CSS should include the measured Connectors mobile breakpoint.' );
				$codex_provider_assert( false !== strpos( $codex_provider_admin_style_source, 'padding: 8px' ), 'Shared admin CSS should use the measured mobile shell padding.' );
				$codex_provider_assert( false !== strpos( $codex_provider_admin_style_source, 'padding: 12px' ), 'Shared admin CSS should use the measured mobile card padding.' );
				$codex_provider_assert( false !== strpos( $codex_provider_admin_style_source, '#wpcontent' ), 'Shared admin CSS should own the Codex Provider page content surface.' );
				$codex_provider_assert( false !== strpos( $codex_provider_admin_style_source, '#wpfooter' ), 'Shared admin CSS should hide the footer on Codex Provider screens to match the Connectors app surface.' );
				$codex_provider_assert( false === strpos( $codex_provider_admin_style_source, '#wpbody-content > div:not' ), 'Shared admin CSS must not copy the core Connectors sibling-hiding rule.' );
				$codex_provider_assert( false !== strpos( $codex_provider_admin_style_source, '.codex-provider-card__logo' ), 'Shared admin CSS should style real provider logos in card headers.' );
				$codex_provider_assert( false === strpos( $codex_provider_admin_style_source, 'codex-provider-card__icon' ), 'Shared admin CSS should not preserve the bespoke letter-tile icon style.' );
				$codex_provider_assert( false !== strpos( $codex_provider_admin_style_source, '[hidden] { display: none !important; }' ), 'Shared admin CSS should preserve hidden-state semantics when styled elements are progressively enhanced.' );
				$codex_provider_assert( false !== strpos( $codex_provider_connection_flow_source, 'getCodexConnectionPendingSupportText' ), 'Connection flow should expose a shared pending support-text helper.' );
				$codex_provider_assert( false !== strpos( $codex_provider_connector_source, 'getCodexConnectionPendingSupportText' ), 'Connectors card should use the shared pending support-text helper so polling errors are visible.' );
				$codex_provider_assert( false !== strpos( $codex_provider_user_connection_source, 'getCodexConnectionPendingSupportText' ), 'User connection page should use the shared pending support-text helper so polling errors are visible.' );
				UserConnectionPage::enqueue_assets();
			$codex_provider_script_module_queue = function_exists( 'wp_script_modules' ) ? wp_script_modules()->get_queue() : [];
			$codex_provider_assert( in_array( 'htperkins-ai-provider-for-codex/user-connection', $codex_provider_script_module_queue, true ), 'User connection page should enqueue the enhanced connection-flow script module.' );
			$codex_provider_assert( false !== strpos( $codex_provider_connection_page_html, 'data-codex-connection-root' ), 'User connection page should render the enhanced connection root.' );
			$codex_provider_assert(
				false !== strpos( $codex_provider_connection_page_html, 'codex-provider-admin-page' ),
				'User connection page should render the shared Connectors-like page wrapper.'
			);
			$codex_provider_assert(
				false !== strpos( $codex_provider_connection_page_html, 'codex-provider-page-header' ),
				'User connection page should render a compact page header.'
			);
			$codex_provider_assert(
				false !== strpos( $codex_provider_connection_page_html, 'Connect your Codex or ChatGPT account and choose the model' ),
				'User connection page should render the compact Connectors-style subtitle.'
			);
			$codex_provider_assert(
				false !== strpos( $codex_provider_connection_page_html, 'codex-provider-card codex-provider-card--account' ),
				'User connection page should render an account card.'
			);
			$codex_provider_assert(
				false !== strpos( $codex_provider_connection_page_html, 'codex-provider-card codex-provider-card--connection-console' ),
				'User connection page should render the enhanced connection console as a card.'
			);
			$codex_provider_assert(
				false !== strpos( $codex_provider_connection_page_html, 'codex-provider-card codex-provider-card--model' ),
				'User connection page should render a model card.'
			);
			$codex_provider_assert(
				false !== strpos( $codex_provider_connection_page_html, 'codex-provider-card__logo' )
				&& false !== strpos( $codex_provider_connection_page_html, 'src/Provider/logo.svg' ),
				'User connection page cards should use the Codex provider logo instead of custom letter tiles.'
			);
			$codex_provider_assert(
				false === strpos( $codex_provider_connection_page_html, 'codex-provider-card__icon' ),
				'User connection page should not render bespoke letter-tile card icons.'
			);
			$codex_provider_account_card_position = strpos( $codex_provider_connection_page_html, 'codex-provider-card codex-provider-card--account' );
			$codex_provider_console_card_position = strpos( $codex_provider_connection_page_html, 'codex-provider-card codex-provider-card--connection-console' );
			$codex_provider_assert(
				false !== $codex_provider_account_card_position
				&& false !== $codex_provider_console_card_position
				&& $codex_provider_account_card_position < $codex_provider_console_card_position,
				'User connection page should keep the account card before the progressively enhanced connection console.'
			);
			$codex_provider_assert(
				false === strpos( $codex_provider_connection_page_html, 'class="widefat striped"' ),
				'User connection page should no longer render the old wide status table.'
			);
			$codex_provider_assert(
				false === strpos( $codex_provider_connection_page_html, 'codex-models-section' ),
				'User connection page should no longer render the old loose model section.'
			);
			$codex_provider_assert(
				false !== strpos( $codex_provider_connection_page_html, 'id="htperkins_aipfc_model"' ),
				'User connection page should preserve the model selector.'
			);
			$codex_provider_assert(
				false !== strpos( $codex_provider_connection_page_html, 'name="htperkins_aipfc_action" value="set-model"' ),
				'User connection page should preserve the set-model form action.'
			);
			$codex_provider_assert( false !== strpos( $codex_provider_connection_page_html, 'data-codex-connection-console' ), 'User connection page should render an always-present enhanced connection console for first-time starts.' );
			$codex_provider_assert( false !== strpos( $codex_provider_connection_page_html, 'data-codex-base-actions' ), 'User connection page should expose the base action container to hide during enhanced flows.' );
			$codex_provider_assert( false !== strpos( $codex_provider_connection_page_html, 'data-codex-start-connect' ), 'User connection page should mark start/restart links for progressive enhancement while preserving href fallbacks.' );
				$codex_provider_assert( false !== strpos( $codex_provider_connection_page_html, 'data-codex-copy-code' ), 'User connection page should render an explicit Copy code button.' );
				$codex_provider_assert( false !== strpos( $codex_provider_connection_page_html, 'data-codex-check-status' ), 'User connection page should preserve the manual Check connection status fallback.' );
				$codex_provider_assert( false !== strpos( $codex_provider_connection_page_html, 'data-codex-retry-sync' ), 'User connection page should expose the retry-sync action for retryable completed pending sessions.' );
				$codex_provider_assert( false !== strpos( $codex_provider_connection_page_html, 'data-codex-connected-actions' ), 'User connection page should render enhanced connected-state actions.' );
				$codex_provider_assert( false !== strpos( $codex_provider_connection_page_html, 'data-codex-refresh-status' ), 'Enhanced connected state should expose a Refresh status action.' );
				$codex_provider_assert( false !== strpos( $codex_provider_connection_page_html, 'data-codex-disconnect' ), 'Enhanced connected state should expose a Disconnect action.' );
				$codex_provider_assert( false !== strpos( $codex_provider_connection_page_html, 'data-codex-server-fallback' ), 'User connection page should label server-rendered pending blocks so JavaScript can hide them once enhanced rendering takes over.' );
			$codex_provider_assert( false !== strpos( $codex_provider_connection_page_html, 'ABCD-EFGH' ), 'Pending connection state should keep the device code visible in the no-JavaScript fallback.' );
			$codex_provider_pending_verification_url = is_array( $codex_provider_pending ) ? (string) ( $codex_provider_pending['verificationUrl'] ?? '' ) : '';
			$codex_provider_assert( '' !== $codex_provider_pending_verification_url, 'Pending connection fixture should include a runtime-provided verification URL.' );
			$codex_provider_assert( false !== strpos( $codex_provider_connection_page_html, esc_url( $codex_provider_pending_verification_url ) ), 'Pending connection state should keep the runtime-provided verification URL visible in the no-JavaScript fallback.' );

			$codex_provider_server = rest_get_server();
		$codex_provider_routes = $codex_provider_server->get_routes();

			$codex_provider_assert( isset( $codex_provider_routes['/htperkins-aipfc/v1/connect/start'] ), 'Connect start route is not registered.' );
			$codex_provider_assert( isset( $codex_provider_routes['/htperkins-aipfc/v1/connect/status'] ), 'Connect status route is not registered.' );
			$codex_provider_assert( isset( $codex_provider_routes['/htperkins-aipfc/v1/connect/refresh'] ), 'Connect refresh route is not registered.' );
			$codex_provider_assert( isset( $codex_provider_routes['/htperkins-aipfc/v1/status'] ), 'Status route is not registered.' );

			$codex_provider_assert( class_exists( ConnectionRefreshScheduler::class ), 'Connection refresh scheduler class is not available.' );
			$codex_provider_assert(
				false !== has_action( ConnectionRefreshScheduler::HOOK, [ ConnectionRefreshScheduler::class, 'refresh_linked_connections' ] ),
				'Connection refresh cron hook is not registered.'
			);
			ConnectionRefreshScheduler::schedule();
			$codex_provider_assert(
				false !== wp_next_scheduled( ConnectionRefreshScheduler::HOOK ),
				'Connection refresh cron event should be scheduled.'
			);
			ConnectionRefreshScheduler::unschedule();
			$codex_provider_assert(
				false === wp_next_scheduled( ConnectionRefreshScheduler::HOOK ),
				'Connection refresh cron event should be removable during deactivation.'
			);
			ConnectionRefreshScheduler::schedule();

		$codex_provider_assert( Settings::has_required_configuration(), 'Runtime settings should be considered configured.' );
		$codex_provider_connectors = [
			'codex' => [
				'type'           => 'ai_provider',
				'authentication' => [
					'method' => 'none',
				],
			],
		];
		$codex_provider_assert(
			ConnectorsIntegration::filter_ai_plugin_has_credentials( false, $codex_provider_connectors ),
			'AI plugin credential check should recognize configured Codex connectors.'
		);
		$codex_provider_assert(
			ConnectorsIntegration::filter_ai_plugin_has_credentials( false, [] ) === false,
			'AI plugin credential check should not report credentials when Codex is not registered.'
		);
		$codex_provider_assert(
			ConnectorsIntegration::filter_ai_plugin_has_credentials( true, [] ) === true,
			'AI plugin credential check should preserve an existing true result.'
		);
		HealthMonitor::record_success();
		$codex_provider_assert(
			true === apply_filters( 'wpai_is_codex_connector_configured', false ),
			'AI plugin Codex status filter should report the configured, healthy local runtime as configured.'
		);
		$codex_provider_assert(
			method_exists( ConnectorsIntegration::class, 'maybe_override_ai_image_generation_support' ),
			'Codex should provide an AI plugin image-generation support override for its non-API-key connector.'
		);
		wp_register_script( 'ai_image_generation', '', [], null );
		wp_enqueue_script( 'ai_image_generation' );
		ConnectorsIntegration::maybe_override_ai_image_generation_support();
		$codex_provider_image_generation_inline_scripts = wp_scripts()->get_data( 'ai_image_generation', 'after' );
		$codex_provider_image_generation_inline_scripts = is_array( $codex_provider_image_generation_inline_scripts )
			? implode( "\n", $codex_provider_image_generation_inline_scripts )
			: (string) $codex_provider_image_generation_inline_scripts;
		$codex_provider_assert(
			false !== strpos( $codex_provider_image_generation_inline_scripts, 'hasImageGenerationSupport = true' ),
			'Codex should override the AI plugin image-generation page flag when the current user has codex-image.'
		);
		$codex_provider_recover_user_id = 0;
		$codex_provider_recover_login   = 'codexrecover' . strtolower( wp_generate_password( 10, false, false ) );
		$codex_provider_recover_user    = wp_insert_user(
			[
				'user_login' => $codex_provider_recover_login,
				'user_pass'  => wp_generate_password( 20, true, true ),
				'user_email' => $codex_provider_recover_login . '@example.com',
				'role'       => 'subscriber',
			]
		);
		$codex_provider_assert(
			! is_wp_error( $codex_provider_recover_user ),
			is_wp_error( $codex_provider_recover_user ) ? $codex_provider_recover_user->get_error_message() : 'Could not create a temporary recovery verification user.'
		);
		$codex_provider_recover_user_id       = (int) $codex_provider_recover_user;
		$codex_provider_recover_connection_id = 'conn_local_' . $codex_provider_recover_user_id;
		ConnectionSnapshotRepository::delete( $codex_provider_recover_connection_id );
		ConnectionRepository::delete_for_user( $codex_provider_recover_user_id );
		wp_set_current_user( $codex_provider_recover_user_id );
		wp_dequeue_script( 'ai_image_generation' );
		wp_deregister_script( 'ai_image_generation' );
		wp_register_script( 'ai_image_generation', '', [], null );
		wp_enqueue_script( 'ai_image_generation' );

		$codex_provider_recover_base_url          = Settings::get_base_url();
		$codex_provider_recover_snapshot_requests = 0;
		$codex_provider_with_mock_runtime(
			static function ( $preempt, array $args, string $url ) use ( $codex_provider_recover_base_url, $codex_provider_http_json_response, $codex_provider_recover_user_id, $codex_provider_temporary_model_a, &$codex_provider_recover_snapshot_requests ) {
				if ( 0 !== strpos( $url, $codex_provider_recover_base_url ) ) {
					return $preempt;
				}

				if ( '/v1/account/snapshot' !== (string) wp_parse_url( $url, PHP_URL_PATH ) ) {
					return $preempt;
				}

				$query = [];
				parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

				if ( $codex_provider_recover_user_id !== (int) ( $query['wpUserId'] ?? 0 ) ) {
					return $preempt;
				}

				++$codex_provider_recover_snapshot_requests;

				return $codex_provider_http_json_response(
					200,
					[
						'account'      => [
							'authMode' => 'chatgpt',
							'email'    => 'codex-recover@example.com',
							'planType' => 'verification',
						],
						'authStored'   => true,
						'capabilities' => [
							'imageGeneration' => true,
							'namespaceTools'  => true,
							'webSearch'       => false,
						],
						'defaultModel' => $codex_provider_temporary_model_a,
						'models'       => [
							[
								'id'    => $codex_provider_temporary_model_a,
								'model' => $codex_provider_temporary_model_a,
							],
						],
						'rateLimits'   => [],
					]
				);
			},
			static function () use ( $codex_provider_assert, $codex_provider_recover_user_id, $codex_provider_recover_connection_id, &$codex_provider_recover_snapshot_requests ) {
				ConnectorsIntegration::maybe_override_ai_image_generation_support();
				$codex_provider_recovered_catalog = ModelCatalogState::get_effective_catalog( $codex_provider_recover_user_id );
				$codex_provider_recovered_scripts = wp_scripts()->get_data( 'ai_image_generation', 'after' );
				$codex_provider_recovered_scripts = is_array( $codex_provider_recovered_scripts )
					? implode( "\n", $codex_provider_recovered_scripts )
					: (string) $codex_provider_recovered_scripts;

				$codex_provider_assert(
					1 === $codex_provider_recover_snapshot_requests,
					'Codex image support override should refresh a missing user snapshot from stored sidecar auth.'
				);
				$codex_provider_assert(
					is_array( ConnectionSnapshotRepository::get( $codex_provider_recover_connection_id ) ),
					'Codex image support override should persist the recovered sidecar snapshot.'
				);
				$codex_provider_assert(
					in_array( ModelCatalogState::IMAGE_MODEL_ID, $codex_provider_recovered_catalog['image_model_ids'] ?? [], true ),
					'Recovered sidecar snapshots should expose codex-image to the current request.'
				);
				$codex_provider_assert(
					false !== strpos( $codex_provider_recovered_scripts, 'hasImageGenerationSupport = true' ),
					'Recovered sidecar snapshots should unblock the AI plugin image-generation UI flag.'
				);
			}
		);
		ConnectionSnapshotRepository::delete( $codex_provider_recover_connection_id );
		ConnectionRepository::delete_for_user( $codex_provider_recover_user_id );
		wp_delete_user( $codex_provider_recover_user_id );
		wp_set_current_user( $codex_provider_temporary_user_id );
		HealthMonitor::record_failure( 'Verification runtime unavailable.' );
		$codex_provider_assert(
			false === apply_filters( 'wpai_is_codex_connector_configured', false ),
			'AI plugin Codex status filter should not report an unreachable local runtime as configured.'
		);
		HealthMonitor::record_success();
		$_GET['page'] = 'options-connectors';
		$codex_provider_connector_data = ConnectorsIntegration::script_module_data( [] );
		unset( $_GET['page'] );

		$codex_provider_assert( '/htperkins-aipfc/v1/connect/status' === (string) ( $codex_provider_connector_data['connectStatusPath'] ?? '' ), 'Connector module data should expose the connect/status REST path.' );
		$codex_provider_assert( '/htperkins-aipfc/v1/status' === (string) ( $codex_provider_connector_data['providerStatusPath'] ?? '' ), 'Connector module data should expose the passive provider status REST path.' );
		$codex_provider_assert( ! empty( $codex_provider_connector_data['connectStatusUrl'] ), 'Connector module data should expose the connect/status REST URL.' );
		$codex_provider_assert( ! empty( $codex_provider_connector_data['providerStatusUrl'] ), 'Connector module data should expose the provider status REST URL.' );
		$codex_provider_assert(
			admin_url( 'tools.php?page=ai-connector-approval' ) === (string) ( $codex_provider_connector_data['connectorApprovalsUrl'] ?? '' ),
			'Connector module data should expose the WordPress AI Connector Approvals admin URL.'
		);
		$_GET['page'] = 'options-connectors-wp-admin';
		$codex_provider_connector_app_data = ConnectorsIntegration::script_module_data( [] );
		unset( $_GET['page'] );

		$codex_provider_assert( '/htperkins-aipfc/v1/connect/status' === (string) ( $codex_provider_connector_app_data['connectStatusPath'] ?? '' ), 'Connector module data should also load on the routed Connectors admin app.' );
		$codex_provider_assert(
			ConnectorsIntegration::filter_ai_plugin_has_valid_credentials( true ) === true,
			'AI plugin valid-credential check should preserve an existing true result.'
		);
		$codex_provider_assert(
			false !== has_action( 'options-connectors_init', [ ConnectorsIntegration::class, 'enqueue_connectors_boot_module' ] ),
			'Codex connector module should be enqueued through the full-page Connectors boot hook.'
		);
		$codex_provider_assert(
			false !== has_action( 'options-connectors-wp-admin_init', [ ConnectorsIntegration::class, 'enqueue_connectors_boot_module' ] ),
			'Codex connector module should be enqueued through the routed wp-admin Connectors boot hook.'
		);
		$codex_provider_assert(
			false !== has_action( 'init', [ ConnectorApprovalIntegration::class, 'maybe_self_approve' ] ),
			'Codex connector self-approval should run during normal boot so updated active installs are covered.'
		);
		ConnectorsIntegration::enqueue_connectors_boot_module();
		$codex_provider_script_module_queue = function_exists( 'wp_script_modules' ) ? wp_script_modules()->get_queue() : [];
		$codex_provider_assert(
			in_array( 'htperkins-ai-provider-for-codex/connectors', $codex_provider_script_module_queue, true ),
			'Codex connector module should be queued when the routed Connectors app builds its boot dependency graph.'
		);
		$codex_provider_connectors_source = (string) file_get_contents( dirname( __DIR__ ) . '/assets/connectors.js' );
		$codex_provider_assert(
			false !== strpos( $codex_provider_connectors_source, 'scheduleRenderAssertion' ),
			'Codex connector module should reassert its custom render after core default connectors register.'
		);
		$codex_provider_assert(
			false !== strpos( $codex_provider_connectors_source, 'const RENDER_ASSERTION_ATTEMPTS =' ) && false !== strpos( $codex_provider_connectors_source, 'remainingAttempts - 1' ),
			'Codex connector module should use a bounded repeated render reassertion instead of a single race-prone retry.'
		);
		$codex_provider_assert(
			false !== strpos( $codex_provider_connectors_source, "status.reason === 'connector_unapproved'" )
			&& false !== strpos( $codex_provider_connectors_source, 'config.connectorApprovalsUrl' )
			&& false !== strpos( $codex_provider_connectors_source, 'status.runtime?.error' ),
			'Codex connector card should render Connector Approval guidance and link to Tools > Connector Approvals when approval blocks the runtime probe.'
		);
		$codex_provider_connectors_integration_source = (string) file_get_contents( dirname( __DIR__ ) . '/src/Admin/ConnectorsIntegration.php' );
		$htperkins_aipfc_dismiss_notice_position       = strpos( $codex_provider_connectors_integration_source, 'public static function ajax_dismiss_notice' );
		$htperkins_aipfc_dismiss_notice_segment        = false === $htperkins_aipfc_dismiss_notice_position ? '' : substr( $codex_provider_connectors_integration_source, $htperkins_aipfc_dismiss_notice_position, 700 );
		$htperkins_aipfc_dismiss_notice_nonce_position = strpos( $htperkins_aipfc_dismiss_notice_segment, "check_ajax_referer( 'codex-provider-dismiss-notice', 'nonce' )" );
		$htperkins_aipfc_dismiss_notice_cap_position   = strpos( $htperkins_aipfc_dismiss_notice_segment, "current_user_can( 'read' )" );
		$htperkins_aipfc_dismiss_notice_meta_position  = strpos( $htperkins_aipfc_dismiss_notice_segment, 'update_user_meta' );
		$codex_provider_assert(
			false !== $htperkins_aipfc_dismiss_notice_position,
			'Dismiss-notice AJAX handler should be present.'
		);
		$codex_provider_assert(
			false !== $htperkins_aipfc_dismiss_notice_nonce_position
			&& false !== $htperkins_aipfc_dismiss_notice_cap_position
			&& false !== $htperkins_aipfc_dismiss_notice_meta_position
			&& $htperkins_aipfc_dismiss_notice_nonce_position < $htperkins_aipfc_dismiss_notice_meta_position
			&& $htperkins_aipfc_dismiss_notice_cap_position < $htperkins_aipfc_dismiss_notice_meta_position,
			'Dismiss-notice AJAX handler should verify nonce and capability before updating user meta.'
		);
		if ( class_exists( '\\WordPress\\AI\\Connector_Approval\\Approvals_Store' ) ) {
			$codex_provider_approval_store_class                  = '\\WordPress\\AI\\Connector_Approval\\Approvals_Store';
			$codex_provider_approval_basename                     = plugin_basename( \Htperkins\AIProviderForCodex\PLUGIN_FILE );
			$codex_provider_approval_option                       = $codex_provider_approval_store_class::OPTION_APPROVALS;
			$codex_provider_approval_pending_option               = $codex_provider_approval_store_class::OPTION_PENDING;
			$codex_provider_approval_seed_option                  = 'htperkins_aipfc_connector_self_approval_seeded';
			$codex_provider_had_approvals                         = false !== get_option( $codex_provider_approval_option, false );
			$codex_provider_original_approvals                    = get_option( $codex_provider_approval_option, [] );
			$codex_provider_had_pending_approvals                 = false !== get_option( $codex_provider_approval_pending_option, false );
			$codex_provider_original_pending_approvals            = get_option( $codex_provider_approval_pending_option, [] );
			$codex_provider_original_approval_feature_options     = [
				$codex_provider_approval_seed_option       => get_option( $codex_provider_approval_seed_option, null ),
				'wpai_features_enabled'                    => get_option( 'wpai_features_enabled', null ),
				'wpai_feature_connector-approval_enabled' => get_option( 'wpai_feature_connector-approval_enabled', null ),
			];

			try {
				delete_option( $codex_provider_approval_option );
				delete_option( $codex_provider_approval_pending_option );
				delete_option( $codex_provider_approval_seed_option );
				update_option( 'wpai_features_enabled', false );
				update_option( 'wpai_feature_connector-approval_enabled', false );

				$codex_provider_approval_store = new $codex_provider_approval_store_class();

				$codex_provider_assert(
					! $codex_provider_approval_store->is_approved( $codex_provider_approval_basename, 'codex' ),
					'Connector Approval fixture should start with Codex self-approval absent.'
				);

				ConnectorApprovalIntegration::maybe_self_approve();

				$codex_provider_assert(
					! $codex_provider_approval_store->is_approved( $codex_provider_approval_basename, 'codex' ),
					'Activation should not pre-approve Codex while the Connector Approval experiment is disabled.'
				);

				update_option( 'wpai_features_enabled', true );
				update_option( 'wpai_feature_connector-approval_enabled', true );

				$codex_provider_block_approval_update = static function ( $value, $old_value ) {
					unset( $value );

					return $old_value;
				};
				add_filter( 'pre_update_option_' . $codex_provider_approval_option, $codex_provider_block_approval_update, 10, 2 );

				try {
					ConnectorApprovalIntegration::maybe_self_approve();
				} finally {
					remove_filter( 'pre_update_option_' . $codex_provider_approval_option, $codex_provider_block_approval_update, 10 );
				}

				$codex_provider_assert(
					! $codex_provider_approval_store->is_approved( $codex_provider_approval_basename, 'codex' ),
					'Connector Approval failed-write fixture should leave Codex self-approval absent.'
				);
				$codex_provider_assert(
					false === get_option( $codex_provider_approval_seed_option, false ),
					'Connector Approval self-approval should not record the seed marker when the approval write did not persist.'
				);

				$codex_provider_approval_pending_key = $codex_provider_approval_store->pending_key( $codex_provider_approval_basename, 'codex' );
				$codex_provider_approval_store->record_pending(
					[
						'type'     => 'plugin',
						'basename' => $codex_provider_approval_basename,
						'name'     => 'Scriptorium AI Provider for Codex',
					],
					'codex'
				);

				$codex_provider_assert(
					isset( $codex_provider_approval_store->get_pending()[ $codex_provider_approval_pending_key ] ),
					'Connector Approval fixture should seed a pending Codex self-approval request.'
				);

				ConnectorApprovalIntegration::maybe_self_approve();

				$codex_provider_assert(
					$codex_provider_approval_store->is_approved( $codex_provider_approval_basename, 'codex' ),
					'Activation and ordinary boot should self-approve the Codex provider plugin once when Connector Approval is enabled.'
				);
				$codex_provider_assert(
					$codex_provider_approval_basename === (string) get_option( $codex_provider_approval_seed_option, '' ),
					'Connector Approval self-approval should record the approved plugin basename as the seed marker.'
				);
				$codex_provider_assert(
					! isset( $codex_provider_approval_store->get_pending()[ $codex_provider_approval_pending_key ] ),
					'Connector Approval self-approval should clear the matching pending request after approval persists.'
				);

				$codex_provider_approval_store->set_approval( $codex_provider_approval_basename, 'codex', false );
				ConnectorApprovalIntegration::maybe_self_approve();

				$codex_provider_assert(
					! $codex_provider_approval_store->is_approved( $codex_provider_approval_basename, 'codex' ),
					'Connector Approval self-approval should not restore access after an administrator revokes it.'
				);

				// Regression (rename migration): a site upgraded from a release
				// with a different main-file basename carries the legacy '1'
				// seed marker. The migration must carry the PRIOR approval
				// decision forward — approve the renamed basename when the old
				// one was approved, but honor a prior admin revocation.
				$codex_provider_legacy_basename = 'ai-provider-for-codex/plugin.php';

				// Carry-over: prior basename approved -> renamed basename
				// approved, and the stale prior grant is retired.
				$codex_provider_approval_store->set_approval( $codex_provider_approval_basename, 'codex', false );
				$codex_provider_approval_store->set_approval( $codex_provider_legacy_basename, 'codex', true );
				update_option( $codex_provider_approval_seed_option, '1', false );
				ConnectorApprovalIntegration::maybe_self_approve();

				$codex_provider_assert(
					$codex_provider_approval_store->is_approved( $codex_provider_approval_basename, 'codex' ),
					'Connector Approval migration should carry a prior approval forward to the renamed basename.'
				);
				$codex_provider_assert(
					! $codex_provider_approval_store->is_approved( $codex_provider_legacy_basename, 'codex' ),
					'Connector Approval migration should retire the stale pre-rename basename grant.'
				);
				$codex_provider_assert(
					$codex_provider_approval_basename === (string) get_option( $codex_provider_approval_seed_option, '' ),
					'Connector Approval migration should record the renamed basename as the seed marker.'
				);

				// Preserve revocation: prior basename absent (admin revoked) ->
				// the renamed basename must NOT be silently re-approved.
				$codex_provider_approval_store->set_approval( $codex_provider_approval_basename, 'codex', false );
				$codex_provider_approval_store->set_approval( $codex_provider_legacy_basename, 'codex', false );
				update_option( $codex_provider_approval_seed_option, '1', false );
				ConnectorApprovalIntegration::maybe_self_approve();

				$codex_provider_assert(
					! $codex_provider_approval_store->is_approved( $codex_provider_approval_basename, 'codex' ),
					'Connector Approval migration must not re-approve the renamed basename when the prior approval was revoked.'
				);
				$codex_provider_assert(
					$codex_provider_approval_basename === (string) get_option( $codex_provider_approval_seed_option, '' ),
					'Connector Approval migration should record the renamed basename even when honoring a prior revocation.'
				);
			} finally {
				if ( $codex_provider_had_approvals ) {
					update_option( $codex_provider_approval_option, $codex_provider_original_approvals, false );
				} else {
					delete_option( $codex_provider_approval_option );
				}

				if ( $codex_provider_had_pending_approvals ) {
					update_option( $codex_provider_approval_pending_option, $codex_provider_original_pending_approvals, false );
				} else {
					delete_option( $codex_provider_approval_pending_option );
				}

				foreach ( $codex_provider_original_approval_feature_options as $codex_provider_approval_option_name => $codex_provider_approval_option_value ) {
					if ( null === $codex_provider_approval_option_value ) {
						delete_option( $codex_provider_approval_option_name );
						continue;
					}

					update_option( $codex_provider_approval_option_name, $codex_provider_approval_option_value );
				}
			}
		}

		$codex_provider_status = SupportChecks::current_user_status( $codex_provider_temporary_user_id );
		$codex_provider_assert( array_key_exists( 'runtimeConfigured', $codex_provider_status ), 'Status payload should expose runtimeConfigured.' );
		$codex_provider_assert( ! array_key_exists( 'siteConfigured', $codex_provider_status ), 'Legacy siteConfigured alias should not be returned.' );

		$codex_provider_base_url = Settings::get_base_url();
		$codex_provider_text_image_model_requests = [];
		$codex_provider_with_mock_runtime(
			static function ( $preempt, array $args, string $url ) use ( $codex_provider_base_url, $codex_provider_http_json_response, &$codex_provider_text_image_model_requests ) {
				if ( 0 !== strpos( $url, $codex_provider_base_url ) ) {
					return $preempt;
				}

				if ( '/v1/responses/text' !== (string) wp_parse_url( $url, PHP_URL_PATH ) ) {
					return $preempt;
				}

				$codex_provider_text_image_model_requests[] = [
					'url'  => $url,
					'args' => $args,
				];

				return $codex_provider_http_json_response(
					200,
					[
						'requestId'    => 'codex-verify-text-image-model',
						'outputText'   => 'This request should have been rejected before runtime.',
						'finishReason' => 'stop',
						'usage'        => [],
						'account'      => [],
						'rateLimits'   => [],
					]
				);
			},
			static function () use ( $codex_provider_assert, $codex_provider_image_model, &$codex_provider_text_image_model_requests ) {
				$codex_provider_text_image_model_threw = false;

				try {
					$model = new \Htperkins\AIProviderForCodex\Models\CodexTextGenerationModel(
						new \WordPress\AiClient\Providers\Models\DTO\ModelMetadata(
							$codex_provider_image_model,
							'Codex Image Routed as Text',
							[
								CapabilityEnum::textGeneration(),
								CapabilityEnum::chatHistory(),
							],
							[]
						),
						new \WordPress\AiClient\Providers\DTO\ProviderMetadata(
							'codex',
							'Codex',
							\WordPress\AiClient\Providers\Enums\ProviderTypeEnum::cloud()
						)
					);
					$model->generateTextResult(
						[
							new UserMessage(
								[
									new MessagePart( 'This text path must not accept the image-only model.' ),
								]
							),
						]
					);
				} catch ( \Throwable $codex_provider_text_image_model_error ) {
					$codex_provider_text_image_model_threw = false !== strpos( $codex_provider_text_image_model_error->getMessage(), 'not available' );
				}

				$codex_provider_assert(
					true === $codex_provider_text_image_model_threw,
					'Codex text generation should reject codex-image even when the combined catalog contains it.'
				);
				$codex_provider_assert(
					0 === count( $codex_provider_text_image_model_requests ),
					'Codex text generation should fail image-only model requests before contacting the runtime.'
				);
			}
		);
		$codex_provider_generation_requests = [];
			$codex_provider_with_mock_runtime(
				static function ( $preempt, array $args, string $url ) use ( $codex_provider_base_url, $codex_provider_http_json_response, &$codex_provider_generation_requests ) {
				if ( 0 !== strpos( $url, $codex_provider_base_url ) ) {
					return $preempt;
				}

				$path = (string) wp_parse_url( $url, PHP_URL_PATH );

				if ( '/v1/responses/text' !== $path ) {
					return $preempt;
				}

				if ( ! empty( $args['reject_unsafe_urls'] ) ) {
					return $preempt;
				}

				$codex_provider_generation_requests[] = [
					'url'  => $url,
					'args' => $args,
				];

				return $codex_provider_http_json_response(
					200,
					[
						'requestId'    => 'codex-verify-generation',
						'outputText'   => 'Local runtime generation path works.',
						'finishReason' => 'stop',
						'usage'        => [
							'inputTokens'  => 5,
							'outputTokens' => 6,
						],
						'account'      => [],
						'rateLimits'   => [],
					]
				);
			},
			static function () use ( $codex_provider_assert, &$codex_provider_generation_requests, $codex_provider_temporary_model_a, $codex_provider_temporary_user_id ) {
				$model  = AiClient::defaultRegistry()->getProviderModel( 'codex', $codex_provider_temporary_model_a );
				$result = $model->generateTextResult(
					[
						new UserMessage(
							[
								new MessagePart( 'Return a short verification sentence.' ),
							]
						),
					]
				);

				$codex_provider_assert(
					'Local runtime generation path works.' === $result->toText(),
					'Codex text generation should return the mocked local runtime output.'
				);
				$codex_provider_assert(
					1 === count( $codex_provider_generation_requests ),
					'Codex text generation should make exactly one local runtime request.'
				);

				$request_args = $codex_provider_generation_requests[0]['args'] ?? [];
				$request_body = json_decode( (string) ( $request_args['body'] ?? '' ), true );

				$codex_provider_assert(
					empty( $request_args['reject_unsafe_urls'] ),
					'Codex text generation should not use the AI Client safe HTTP transporter for the loopback runtime request.'
				);
				$codex_provider_assert(
					is_array( $request_body ) && $codex_provider_temporary_user_id === (int) ( $request_body['wpUserId'] ?? 0 ),
					'Codex text generation should send the current WordPress user ID to the runtime.'
				);

				$vision_image_base64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII=';
				$model->generateTextResult(
					[
						new UserMessage(
							[
								new MessagePart( 'Describe this verification image.' ),
								new MessagePart( new File( $vision_image_base64, 'image/png' ) ),
							]
						),
					]
				);
				$codex_provider_assert(
					2 === count( $codex_provider_generation_requests ),
					'Codex vision text generation should make one additional local runtime request.'
				);
				$vision_request_args = $codex_provider_generation_requests[1]['args'] ?? [];
				$vision_request_body = json_decode( (string) ( $vision_request_args['body'] ?? '' ), true );
				$codex_provider_assert(
					is_array( $vision_request_body )
					&& 'USER: Describe this verification image.' === (string) ( $vision_request_body['input'] ?? '' )
					&& isset( $vision_request_body['inputImages'][0]['url'] )
					&& 'data:image/png;base64,' . $vision_image_base64 === (string) $vision_request_body['inputImages'][0]['url'],
					'Codex vision text generation should send image file parts to the sidecar as inputImages.'
				);
					}
				);
				$codex_provider_app_server_requests      = [];
				$codex_provider_app_server_notifications = [
					[
						'method' => 'item/completed',
						'params' => [
							'turnId' => 'turn_site_level',
							'item'   => [
								'type' => 'agentMessage',
								'text' => 'Site-level app-server generation works.',
							],
						],
					],
					[
						'method' => 'turn/completed',
						'params' => [
							'turn' => [
								'id' => 'turn_site_level',
							],
						],
					],
				];
				$codex_provider_app_server_session_factory = static function () use ( &$codex_provider_app_server_requests, &$codex_provider_app_server_notifications ) {
					return new class( $codex_provider_app_server_requests, $codex_provider_app_server_notifications ) {
						private $requests;
						private $notifications;

						public function __construct( array &$requests, array &$notifications ) {
							$this->requests      =& $requests;
							$this->notifications =& $notifications;
						}

						public function request( string $method, array $params = [], int $timeout = 20 ) {
							$this->requests[] = [
								'method' => $method,
								'params' => $params,
							];

							if ( 'thread/start' === $method ) {
								return [ 'thread' => [ 'id' => 'thread_site_level' ] ];
							}

							if ( 'turn/start' === $method ) {
								return [ 'turn' => [ 'id' => 'turn_site_level' ] ];
							}

							if ( 'account/read' === $method ) {
								return [
									'account' => [
										'type'     => 'chatgpt',
										'email'    => 'site-level@example.com',
										'planType' => 'plus',
									],
								];
							}

							if ( 'account/rateLimits/read' === $method ) {
								return [ 'rateLimits' => [] ];
							}

							return [];
						}

						public function wait_for_notification( callable $predicate, int $timeout = 300 ): array {
							while ( [] !== $this->notifications ) {
								$notification = array_shift( $this->notifications );
								if ( is_array( $notification ) && $predicate( $notification ) ) {
									return $notification;
								}
							}

							throw new RuntimeException( 'No matching fake app-server notification.' );
						}

						public function close(): void {}
					};
				};
				add_filter(
					'htperkins_aipfc_app_server_session_factory',
					static function () use ( $codex_provider_app_server_session_factory ) {
						return $codex_provider_app_server_session_factory;
					}
				);
				$codex_provider_previous_runtime_base_url = getenv( 'CODEX_WP_RUNTIME_BASE_URL' );
				$codex_provider_had_runtime_base_url      = false !== $codex_provider_previous_runtime_base_url;
				try {
					putenv( 'CODEX_WP_RUNTIME_BASE_URL=ws://127.0.0.1:4500' );
					$_ENV['CODEX_WP_RUNTIME_BASE_URL'] = 'ws://127.0.0.1:4500';
					ConnectionRepository::delete_for_user( $codex_provider_temporary_user_id );
					ConnectionSnapshotRepository::delete( $codex_provider_temporary_connection_id );
					ModelCatalogState::delete_user_preferred_model( $codex_provider_temporary_user_id );
					$codex_provider_reset_ai_client_registry();
					\Htperkins\AIProviderForCodex\register_provider();
					$model  = AiClient::defaultRegistry()->getProviderModel( 'codex', $codex_provider_temporary_fallback_a );
					$result = $model->generateTextResult(
						[
							new UserMessage(
								[
									new MessagePart( 'Use site-level Codex auth.' ),
								]
							),
						]
					);

					$codex_provider_assert(
						'Site-level app-server generation works.' === $result->toText(),
						'Site-level Codex app-server mode should generate without a per-user connection row.'
					);
					$codex_provider_assert(
						in_array( 'turn/start', array_column( $codex_provider_app_server_requests, 'method' ), true ),
						'Site-level Codex app-server mode should start a turn through app-server.'
					);
				} finally {
					remove_all_filters( 'htperkins_aipfc_app_server_session_factory' );
					if ( $codex_provider_had_runtime_base_url ) {
						putenv( 'CODEX_WP_RUNTIME_BASE_URL=' . $codex_provider_previous_runtime_base_url );
						$_ENV['CODEX_WP_RUNTIME_BASE_URL'] = $codex_provider_previous_runtime_base_url;
					} else {
						putenv( 'CODEX_WP_RUNTIME_BASE_URL' );
						unset( $_ENV['CODEX_WP_RUNTIME_BASE_URL'] );
					}
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
							'checkedAt'    => gmdate( 'c' ),
							'capabilities' => [
								'imageGeneration' => true,
							],
						]
					);
					ModelCatalogState::update_user_preferred_model( $codex_provider_temporary_user_id, $codex_provider_temporary_model_a );
					$codex_provider_reset_ai_client_registry();
					\Htperkins\AIProviderForCodex\register_provider();
				}
				$codex_provider_image_requests = [];
			$codex_provider_image_base64   = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII=';
			$codex_provider_wrapped_image_base64 = substr( $codex_provider_image_base64, 0, 32 ) . "\n" . substr( $codex_provider_image_base64, 32 );
			$codex_provider_with_mock_runtime(
				static function ( $preempt, array $args, string $url ) use ( $codex_provider_base_url, $codex_provider_http_json_response, $codex_provider_wrapped_image_base64, &$codex_provider_image_requests ) {
					if ( 0 !== strpos( $url, $codex_provider_base_url ) ) {
						return $preempt;
					}

					$path = (string) wp_parse_url( $url, PHP_URL_PATH );

					if ( '/v1/responses/image' !== $path ) {
						return $preempt;
					}

					$codex_provider_image_requests[] = [
						'url'  => $url,
						'args' => $args,
					];

					return $codex_provider_http_json_response(
						200,
						[
							'requestId'     => 'codex-verify-image',
								'model'         => 'codex-image',
								'runtimeModel'  => null,
								'mimeType'      => 'image/png',
								'imageBase64'   => $codex_provider_wrapped_image_base64,
								'revisedPrompt' => 'A small blue circle on a white background.',
								'finishReason'  => 'stop',
								'usage'         => [
								'inputTokens'           => 0,
								'outputTokens'          => 0,
								'reasoningOutputTokens' => 0,
							],
							'account'       => [
								'email' => 'verify@example.com',
							],
							'rateLimits'    => [],
							'artifacts'     => [
								'savedPath' => '/home/dev/.codex/generated_images/session/call.png',
							],
						]
					);
				},
				static function () use ( $codex_provider_assert, &$codex_provider_image_requests, $codex_provider_image_model, $codex_provider_temporary_user_id ) {
					$model  = AiClient::defaultRegistry()->getProviderModel( 'codex', $codex_provider_image_model );
					$result = $model->generateImageResult(
						[
							new UserMessage(
								[
									new MessagePart( 'Draw a small blue circle.' ),
								]
							),
						]
					);

					$codex_provider_assert( $result->toImageFile()->isImage(), 'Codex image generation should return an image file.' );
					$codex_provider_assert(
						'A small blue circle on a white background.' === (string) ( $result->getAdditionalData()['revisedPrompt'] ?? '' ),
							'Codex image result should expose the revised prompt in additional data.'
						);
					$codex_provider_assert(
						1 === count( $codex_provider_image_requests ),
						'Codex image generation should make exactly one local runtime request.'
					);

					$request_args = $codex_provider_image_requests[0]['args'] ?? [];
					$request_body = json_decode( (string) ( $request_args['body'] ?? '' ), true );

					$codex_provider_assert(
						is_array( $request_body ) && $codex_provider_temporary_user_id === (int) ( $request_body['wpUserId'] ?? 0 ),
						'Codex image generation should send the current WordPress user ID to the runtime.'
					);
					$codex_provider_assert(
						is_array( $request_body ) && 'Draw a small blue circle.' === (string) ( $request_body['prompt'] ?? '' ),
						'Codex image generation should send the clean image prompt to the sidecar.'
					);
					$codex_provider_assert(
						is_array( $request_body ) && ! array_key_exists( 'model', $request_body ),
						'Codex image generation must not pass codex-image as a runtime model override.'
					);
				}
			);
			$codex_provider_reference_image_requests = [];
			$codex_provider_with_mock_runtime(
				static function ( $preempt, array $args, string $url ) use ( $codex_provider_base_url, &$codex_provider_reference_image_requests ) {
					if ( 0 !== strpos( $url, $codex_provider_base_url ) ) {
						return $preempt;
					}

					if ( '/v1/responses/image' !== (string) wp_parse_url( $url, PHP_URL_PATH ) ) {
						return $preempt;
					}

					$codex_provider_reference_image_requests[] = [
						'url'  => $url,
						'args' => $args,
					];

					return $preempt;
				},
				static function () use ( $codex_provider_assert, &$codex_provider_reference_image_requests, $codex_provider_image_base64, $codex_provider_image_model ) {
					$codex_provider_reference_image_threw = false;

					try {
						$model = AiClient::defaultRegistry()->getProviderModel( 'codex', $codex_provider_image_model );
						$model->generateImageResult(
							[
								new UserMessage(
									[
										new MessagePart( 'Use this reference image.' ),
										new MessagePart( new File( $codex_provider_image_base64, 'image/png' ) ),
									]
								),
							]
						);
					} catch ( \Throwable $codex_provider_reference_image_error ) {
						$codex_provider_reference_image_threw = false !== strpos( $codex_provider_reference_image_error->getMessage(), 'Reference images are not supported yet' );
					}

					$codex_provider_assert(
						true === $codex_provider_reference_image_threw,
						'Codex image generation should reject reference image inputs until the sidecar supports them.'
					);
					$codex_provider_assert(
						0 === count( $codex_provider_reference_image_requests ),
						'Unsupported reference image prompts should fail before contacting the local runtime.'
					);
				}
			);

			// Codex generations are mirrored into the AI plugin Request Log via the bridge sink.
		$codex_provider_log_entries = [];
		$codex_provider_log_sink    = static function ( array $entry ) use ( &$codex_provider_log_entries ): void {
			$codex_provider_log_entries[] = $entry;
		};
		add_filter(
			'htperkins_aipfc_request_log_sink',
			static function () use ( $codex_provider_log_sink ) {
				return $codex_provider_log_sink;
			}
		);
		$codex_provider_with_mock_runtime(
			static function ( $preempt, array $args, string $url ) use ( $codex_provider_base_url, $codex_provider_http_json_response ) {
				if ( 0 !== strpos( $url, $codex_provider_base_url ) ) {
					return $preempt;
				}

				if ( '/v1/responses/text' !== (string) wp_parse_url( $url, PHP_URL_PATH ) ) {
					return $preempt;
				}

				return $codex_provider_http_json_response(
					200,
					[
						'requestId'    => 'codex-verify-log',
						'outputText'   => 'Logged generation output.',
						'finishReason' => 'stop',
						'usage'        => [
							'inputTokens'           => 11,
							'outputTokens'          => 22,
							'reasoningOutputTokens' => 7,
						],
						'account'      => [],
						'rateLimits'   => [],
					]
				);
			},
			static function () use ( $codex_provider_assert, $codex_provider_temporary_model_a, $codex_provider_temporary_user_id, &$codex_provider_log_entries ) {
				$codex_provider_log_entries = [];
				$model                      = AiClient::defaultRegistry()->getProviderModel( 'codex', $codex_provider_temporary_model_a );
				$model->generateTextResult(
					[
						new UserMessage(
							[
								new MessagePart( 'Log this request.' ),
							]
						),
					]
				);

				$codex_provider_assert(
					1 === count( $codex_provider_log_entries ),
					'A successful Codex generation should emit exactly one request-log entry.'
				);

				$codex_provider_log_entry = $codex_provider_log_entries[0] ?? [];
				$codex_provider_assert(
					'codex' === ( $codex_provider_log_entry['provider'] ?? null ),
					'Codex request-log entries should attribute the codex provider.'
				);
				$codex_provider_assert(
					'success' === ( $codex_provider_log_entry['status'] ?? null ),
					'A successful Codex generation should be logged with status=success.'
				);
				$codex_provider_assert(
					$codex_provider_temporary_model_a === ( $codex_provider_log_entry['model'] ?? null ),
					'Codex request-log entries should record the model id.'
				);
				$codex_provider_assert(
					11 === ( $codex_provider_log_entry['tokens_input'] ?? null ),
					'Codex request-log entries should record input tokens from the runtime usage.'
				);
				$codex_provider_assert(
					22 === ( $codex_provider_log_entry['tokens_output'] ?? null ),
					'Codex request-log output tokens must equal the runtime outputTokens and must not double-count reasoning output tokens, which the runtime already includes in outputTokens.'
				);
				$codex_provider_assert(
					$codex_provider_temporary_user_id === ( $codex_provider_log_entry['user_id'] ?? null ),
					'Codex request-log entries should record the WordPress user id.'
				);
				$codex_provider_assert(
					'Logged generation output.' === ( $codex_provider_log_entry['context']['output_preview'] ?? null ),
					'Codex request-log entries should capture the response preview.'
				);
				$codex_provider_assert(
					false !== strpos( (string) ( $codex_provider_log_entry['context']['input_preview'] ?? '' ), 'Log this request.' ),
					'Codex request-log entries should capture the prompt preview.'
				);
			}
		);
		$codex_provider_with_mock_runtime(
			static function ( $preempt, array $args, string $url ) use ( $codex_provider_base_url, $codex_provider_http_json_response, $codex_provider_image_base64 ) {
				if ( 0 !== strpos( $url, $codex_provider_base_url ) ) {
					return $preempt;
				}

				if ( '/v1/responses/image' !== (string) wp_parse_url( $url, PHP_URL_PATH ) ) {
					return $preempt;
				}

				return $codex_provider_http_json_response(
					200,
					[
						'requestId'     => 'codex-verify-image-log',
						'mimeType'      => 'image/png',
						'imageBase64'   => $codex_provider_image_base64,
						'revisedPrompt' => 'A logged green square on a white background.',
						'finishReason'  => 'stop',
						'usage'         => [
							'inputTokens'  => 3,
							'outputTokens' => 4,
						],
						'account'       => [],
						'rateLimits'    => [],
						'artifacts'     => [
							'savedPath' => '/home/dev/.codex/generated_images/session/hidden.png',
						],
					]
				);
			},
			static function () use ( $codex_provider_assert, $codex_provider_image_base64, $codex_provider_image_model, $codex_provider_temporary_user_id, &$codex_provider_log_entries ) {
				$codex_provider_log_entries = [];
				$model                      = AiClient::defaultRegistry()->getProviderModel( 'codex', $codex_provider_image_model );
				$model->generateImageResult(
					[
						new UserMessage(
							[
								new MessagePart( 'Log this image request.' ),
							]
						),
					]
				);

				$codex_provider_assert(
					1 === count( $codex_provider_log_entries ),
					'A successful Codex image generation should emit exactly one request-log entry.'
				);

				$codex_provider_log_entry = $codex_provider_log_entries[0] ?? [];
				$codex_provider_log_json  = (string) wp_json_encode( $codex_provider_log_entry );

				$codex_provider_assert(
					'image' === ( $codex_provider_log_entry['type'] ?? null ),
					'Codex image request-log entries should use type=image.'
				);
				$codex_provider_assert(
					'codex:responses/image' === ( $codex_provider_log_entry['operation'] ?? null ),
					'Codex image request-log entries should use the image runtime operation.'
				);
				$codex_provider_assert(
					$codex_provider_image_model === ( $codex_provider_log_entry['model'] ?? null ),
					'Codex image request-log entries should record codex-image as the provider model.'
				);
				$codex_provider_assert(
					3 === ( $codex_provider_log_entry['tokens_input'] ?? null ) && 4 === ( $codex_provider_log_entry['tokens_output'] ?? null ),
					'Codex image request-log entries should preserve runtime token usage when present.'
				);
				$codex_provider_assert(
					$codex_provider_temporary_user_id === ( $codex_provider_log_entry['user_id'] ?? null ),
					'Codex image request-log entries should record the WordPress user id.'
				);
				$codex_provider_assert(
					false !== strpos( (string) ( $codex_provider_log_entry['context']['output_preview'] ?? '' ), 'Generated image/png image' ),
					'Codex image request-log entries should summarize the generated image without storing image data.'
				);
				$codex_provider_assert(
					false === strpos( $codex_provider_log_json, $codex_provider_image_base64 )
					&& false === strpos( $codex_provider_log_json, 'savedPath' )
					&& false === strpos( $codex_provider_log_json, '.codex/generated_images' ),
					'Codex image request-log entries must not store generated image base64 or local artifact paths.'
				);
			}
		);
		$codex_provider_with_mock_runtime(
			static function ( $preempt, array $args, string $url ) use ( $codex_provider_base_url, $codex_provider_http_json_response ) {
				if ( 0 !== strpos( $url, $codex_provider_base_url ) ) {
					return $preempt;
				}

				if ( '/v1/responses/text' !== (string) wp_parse_url( $url, PHP_URL_PATH ) ) {
					return $preempt;
				}

				return $codex_provider_http_json_response(
					500,
					[
						'error' => [
							'message' => 'sidecar exploded',
						],
					]
				);
			},
			static function () use ( $codex_provider_assert, $codex_provider_temporary_model_a, &$codex_provider_log_entries ) {
				$codex_provider_log_entries      = [];
				$codex_provider_generation_threw = false;

				try {
					$model = AiClient::defaultRegistry()->getProviderModel( 'codex', $codex_provider_temporary_model_a );
					$model->generateTextResult(
						[
							new UserMessage(
								[
									new MessagePart( 'This generation will fail.' ),
								]
							),
						]
					);
				} catch ( \Throwable $codex_provider_generation_error ) {
					$codex_provider_generation_threw = true;
				}

				$codex_provider_assert(
					true === $codex_provider_generation_threw,
					'A failed Codex generation should still raise to the caller.'
				);
				$codex_provider_assert(
					1 === count( $codex_provider_log_entries ),
					'A failed Codex generation should emit exactly one request-log entry.'
				);

				$codex_provider_log_entry = $codex_provider_log_entries[0] ?? [];
				$codex_provider_assert(
					'error' === ( $codex_provider_log_entry['status'] ?? null ),
					'A failed Codex generation should be logged with status=error.'
				);
				$codex_provider_assert(
					'codex' === ( $codex_provider_log_entry['provider'] ?? null ),
					'A failed Codex generation log should attribute the codex provider.'
				);
				$codex_provider_assert(
					'' !== (string) ( $codex_provider_log_entry['error_message'] ?? '' ),
					'A failed Codex generation log should include an error message.'
				);
			}
		);
		$codex_provider_with_mock_runtime(
			static function ( $preempt, array $args, string $url ) use ( $codex_provider_base_url, $codex_provider_http_json_response ) {
				if ( 0 !== strpos( $url, $codex_provider_base_url ) ) {
					return $preempt;
				}

				if ( '/v1/responses/image' !== (string) wp_parse_url( $url, PHP_URL_PATH ) ) {
					return $preempt;
				}

				return $codex_provider_http_json_response(
					500,
					[
						'error' => [
							'message' => 'image sidecar exploded',
						],
					]
				);
			},
			static function () use ( $codex_provider_assert, $codex_provider_image_model, &$codex_provider_log_entries ) {
				$codex_provider_log_entries           = [];
				$codex_provider_image_generation_threw = false;

				try {
					$model = AiClient::defaultRegistry()->getProviderModel( 'codex', $codex_provider_image_model );
					$model->generateImageResult(
						[
							new UserMessage(
								[
									new MessagePart( 'This image generation will fail.' ),
								]
							),
						]
					);
				} catch ( \Throwable $codex_provider_image_generation_error ) {
					$codex_provider_image_generation_threw = true;
				}

				$codex_provider_assert(
					true === $codex_provider_image_generation_threw,
					'A failed Codex image generation should still raise to the caller.'
				);
				$codex_provider_assert(
					1 === count( $codex_provider_log_entries ),
					'A failed Codex image generation should emit exactly one request-log entry.'
				);

				$codex_provider_log_entry = $codex_provider_log_entries[0] ?? [];
				$codex_provider_assert(
					'image' === ( $codex_provider_log_entry['type'] ?? null ),
					'A failed Codex image generation log should use type=image.'
				);
				$codex_provider_assert(
					'codex:responses/image' === ( $codex_provider_log_entry['operation'] ?? null ),
					'A failed Codex image generation log should use the image runtime operation.'
				);
				$codex_provider_assert(
					'error' === ( $codex_provider_log_entry['status'] ?? null ),
					'A failed Codex image generation should be logged with status=error.'
				);
				$codex_provider_assert(
					'' !== (string) ( $codex_provider_log_entry['error_message'] ?? '' ),
					'A failed Codex image generation log should include an error message.'
				);
			}
		);
		$codex_provider_with_mock_runtime(
			static function ( $preempt, array $args, string $url ) use ( $codex_provider_base_url, $codex_provider_http_json_response, $codex_provider_image_base64 ) {
				if ( 0 !== strpos( $url, $codex_provider_base_url ) ) {
					return $preempt;
				}

				if ( '/v1/responses/image' !== (string) wp_parse_url( $url, PHP_URL_PATH ) ) {
					return $preempt;
				}

				return $codex_provider_http_json_response(
					200,
					[
						'requestId'    => 'codex-verify-image-map-error',
						'mimeType'     => 'image/jpeg',
						'imageBase64'  => $codex_provider_image_base64,
						'finishReason' => 'stop',
						'usage'        => [],
						'account'      => [],
						'rateLimits'   => [],
					]
				);
			},
			static function () use ( $codex_provider_assert, $codex_provider_image_model, &$codex_provider_log_entries ) {
				$codex_provider_log_entries          = [];
				$codex_provider_image_mapping_threw = false;

				try {
					$model = AiClient::defaultRegistry()->getProviderModel( 'codex', $codex_provider_image_model );
					$model->generateImageResult(
						[
							new UserMessage(
								[
									new MessagePart( 'This image response has an unsupported MIME type.' ),
								]
							),
						]
					);
				} catch ( \Throwable $codex_provider_image_mapping_error ) {
					$codex_provider_image_mapping_threw = false !== strpos( $codex_provider_image_mapping_error->getMessage(), 'unsupported image MIME type' );
				}

				$codex_provider_assert(
					true === $codex_provider_image_mapping_threw,
					'Codex image mapper errors should still raise to the caller.'
				);
				$codex_provider_assert(
					1 === count( $codex_provider_log_entries ),
					'Codex image mapper errors should emit exactly one request-log entry.'
				);

				$codex_provider_log_entry = $codex_provider_log_entries[0] ?? [];
				$codex_provider_assert(
					'error' === ( $codex_provider_log_entry['status'] ?? null ),
					'Codex image mapper errors should be logged with status=error.'
				);
				$codex_provider_assert(
					'image' === ( $codex_provider_log_entry['type'] ?? null ),
					'Codex image mapper error logs should use type=image.'
				);
				$codex_provider_assert(
					false !== strpos( (string) ( $codex_provider_log_entry['error_message'] ?? '' ), 'unsupported image MIME type' ),
					'Codex image mapper error logs should include the mapper error.'
				);
			}
		);
		remove_all_filters( 'htperkins_aipfc_request_log_sink' );
		$codex_provider_with_mock_runtime(
			static function ( $preempt, array $args, string $url ) use ( $codex_provider_base_url, $codex_provider_http_json_response ) {
				if ( 0 !== strpos( $url, $codex_provider_base_url ) ) {
					return $preempt;
				}

				if ( '/v1/responses/image' !== (string) wp_parse_url( $url, PHP_URL_PATH ) ) {
					return $preempt;
				}

				return $codex_provider_http_json_response(
					409,
					[
						'error' => [
							'code'    => 'auth_required',
							'message' => 'No stored ChatGPT or Codex auth is available for this WordPress user.',
						],
					]
				);
			},
			static function () use ( $codex_provider_assert, $codex_provider_image_model, $codex_provider_temporary_connection_id, $codex_provider_temporary_user_id ) {
				$codex_provider_image_auth_threw = false;

				try {
					$model = AiClient::defaultRegistry()->getProviderModel( 'codex', $codex_provider_image_model );
					$model->generateImageResult(
						[
							new UserMessage(
								[
									new MessagePart( 'This image generation should require reconnect.' ),
								]
							),
						]
					);
					} catch ( \Throwable $codex_provider_image_auth_error ) {
						$codex_provider_image_auth_threw = false !== strpos( $codex_provider_image_auth_error->getMessage(), 'Run codex login for the service user that starts app-server' );
					}

					$codex_provider_assert(
						true === $codex_provider_image_auth_threw,
						'Codex image auth loss should surface the app-server login guidance.'
					);
				$codex_provider_assert(
					null === ConnectionRepository::get_for_user( $codex_provider_temporary_user_id ),
					'Codex image auth loss should delete the local connection row.'
				);
				$codex_provider_assert(
					null === ConnectionSnapshotRepository::get( $codex_provider_temporary_connection_id ),
					'Codex image auth loss should delete the local snapshot row.'
				);
				$codex_provider_assert(
					'' === ModelCatalogState::get_user_preferred_model( $codex_provider_temporary_user_id ),
					'Codex image auth loss should clear the preferred model.'
				);
			}
		);
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
				'capabilities' => [
					'imageGeneration' => true,
					'namespaceTools'  => true,
					'webSearch'       => false,
				],
			]
		);
		ModelCatalogState::update_user_preferred_model( $codex_provider_temporary_user_id, $codex_provider_temporary_model_a );
		$codex_provider_reset_ai_client_registry();
		\Htperkins\AIProviderForCodex\register_provider();
		$codex_provider_with_mock_runtime(
			static function ( $preempt, array $args, string $url ) use ( $codex_provider_base_url ) {
				if ( 0 !== strpos( $url, $codex_provider_base_url ) ) {
					return $preempt;
				}

				return new WP_Error(
					'wpai_connector_not_approved',
					'The "codex" AI connector has not been approved for use by "ai-provider-for-codex/ai-provider-for-codex.php".',
					[
						'status'       => 403,
						'connector_id' => 'codex',
					]
				);
			},
			static function () use ( $codex_provider_assert ) {
				try {
					try {
						( new \Htperkins\AIProviderForCodex\Runtime\Client() )->get( '/healthz' );
						$codex_provider_assert( false, 'Connector Approval transport blocks should raise a runtime exception.' );
					} catch ( RuntimeException $exception ) {
						$codex_provider_assert(
							false !== strpos( $exception->getMessage(), 'Connector Approval' ),
							'Connector Approval transport blocks should surface an actionable Connector Approval message.'
						);
					}

					$codex_provider_health = HealthMonitor::get_status();
					$codex_provider_assert(
						'connector_unapproved' === (string) $codex_provider_health['status'],
						'Connector Approval transport blocks should not be cached as runtime_unreachable health failures.'
					);
					$codex_provider_assert(
						HealthMonitor::is_available(),
						'Connector Approval transport blocks should keep the local runtime health distinct from sidecar reachability.'
					);
				} finally {
					HealthMonitor::record_success();
				}
			}
		);
		$codex_provider_with_mock_runtime(
			static function ( $preempt, array $args, string $url ) use ( $codex_provider_base_url ) {
				if ( 0 !== strpos( $url, $codex_provider_base_url ) ) {
					return $preempt;
				}

				return new WP_Error(
					'wpai_connector_not_approved',
					'The "codex" AI connector has not been approved for use by "ai-provider-for-codex/ai-provider-for-codex.php".',
					[
						'status'       => 403,
						'connector_id' => 'codex',
						'caller'       => [
							'basename' => 'ai-provider-for-codex/ai-provider-for-codex.php',
						],
					]
				);
			},
			static function () use ( $codex_provider_assert, $codex_provider_temporary_user_id ) {
				try {
					$codex_provider_status = SupportChecks::current_user_status( $codex_provider_temporary_user_id );
					$codex_provider_assert(
						'connector_unapproved' === (string) ( $codex_provider_status['reason'] ?? '' ),
						'Status checks blocked by Connector Approval should report connector_unapproved instead of runtime_unreachable.'
					);
					$codex_provider_assert(
						! empty( $codex_provider_status['runtime']['error'] ),
						'Connector Approval status should include the actionable runtime error.'
					);
				} finally {
					HealthMonitor::record_success();
				}
			}
		);

		ConnectionService::invalidate_local_connection( $codex_provider_temporary_user_id );
		$codex_provider_start_connect_login_requests = [];
		$codex_provider_with_mock_runtime(
			static function ( $preempt, array $args, string $url ) use ( $codex_provider_base_url, $codex_provider_http_json_response, $codex_provider_temporary_model_a, $codex_provider_user_email, &$codex_provider_start_connect_login_requests ) {
				if ( 0 !== strpos( $url, $codex_provider_base_url ) ) {
					return $preempt;
				}

				$path = (string) wp_parse_url( $url, PHP_URL_PATH );

				if ( '/v1/login/start' === $path ) {
					$codex_provider_start_connect_login_requests[] = $path;

					return $codex_provider_http_json_response(
						200,
						[
							'authSessionId'   => 'auth_should_not_start',
							'status'          => 'pending',
							'verificationUrl' => 'https://chatgpt.com/device',
							'userCode'        => 'NEWC-ODE1',
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
			static function () use ( $codex_provider_assert, $codex_provider_temporary_model_a, $codex_provider_temporary_user_id, &$codex_provider_start_connect_login_requests ) {
				$codex_provider_result = ( new ConnectionService() )->start_connect( $codex_provider_temporary_user_id );

				$codex_provider_assert( 'connected' === (string) ( $codex_provider_result['status'] ?? '' ), 'Connect start should reuse valid stored runtime auth before starting a new device-code login.' );
				$codex_provider_assert( 0 === count( $codex_provider_start_connect_login_requests ), 'Connect start should not call login/start when account snapshot succeeds.' );
				$codex_provider_assert( null === PendingConnectionRepository::get_for_user( $codex_provider_temporary_user_id ), 'Stored-auth reuse should not leave a pending device-code login.' );
				$codex_provider_assert( is_array( ConnectionRepository::get_for_user( $codex_provider_temporary_user_id ) ), 'Stored-auth reuse should restore the local connection row.' );
				$codex_provider_assert( $codex_provider_temporary_model_a === (string) ( $codex_provider_result['snapshot']['defaultModel'] ?? '' ), 'Stored-auth reuse should return the refreshed runtime snapshot.' );
			}
		);

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
				$codex_provider_request  = new WP_REST_Request( 'GET', '/htperkins-aipfc/v1/status' );
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
				$codex_provider_assert( false !== strpos( $codex_provider_connection_page_html, 'Retry account sync' ), 'Retryable completed pending state should still render Retry account sync.' );
				$codex_provider_assert( false !== strpos( $codex_provider_connection_page_html, 'Snapshot refresh failed during verification.' ), 'Retryable completed pending state should preserve the stored sync error.' );
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

					ob_start();
					UserConnectionPage::render_help_tab();
					$codex_provider_connection_help_html = (string) ob_get_clean();
				} finally {
					remove_filter( 'gettext', $codex_provider_translate_filter, 10 );
				}

				$codex_provider_assert( false !== strpos( $codex_provider_connection_page_html, 'Connection attempt failed' ), 'User connection page should describe a stored terminal login error.' );
				$codex_provider_assert( false !== strpos( $codex_provider_connection_page_html, 'Start connection again' ), 'User connection page should let the user restart after a terminal login error.' );
				$codex_provider_assert( false !== strpos( $codex_provider_connection_page_html, 'Device code expired.' ), 'User connection page should render the stored terminal login error.' );
				$codex_provider_assert( false !== strpos( $codex_provider_connection_help_html, '100% reliable' ), 'User connection help tab should render translated strings containing a literal percent sign.' );
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
						$codex_provider_assert( false !== strpos( $exception->getMessage(), 'Run codex login for the service user that starts app-server' ), 'Explicit snapshot refresh should surface the auth-required app-server login message.' );
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
				$codex_provider_with_mock_runtime(
					static function ( $preempt, array $args, string $url ) use ( $codex_provider_base_url, $codex_provider_http_json_response ) {
						if ( 0 !== strpos( $url, $codex_provider_base_url ) ) {
							return $preempt;
						}

						$path = (string) wp_parse_url( $url, PHP_URL_PATH );

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
					static function () use ( $codex_provider_assert, $codex_provider_temporary_connection_id, $codex_provider_temporary_user_id ) {
						ConnectionRefreshScheduler::refresh_linked_connections();

						$codex_provider_assert( null === ConnectionRepository::get_for_user( $codex_provider_temporary_user_id ), 'Cron snapshot refresh should delete the local connection row when runtime auth disappears.' );
						$codex_provider_assert( null === ConnectionSnapshotRepository::get( $codex_provider_temporary_connection_id ), 'Cron snapshot refresh should delete the local snapshot row when runtime auth disappears.' );
						$codex_provider_assert( '' === ModelCatalogState::get_user_preferred_model( $codex_provider_temporary_user_id ), 'Cron snapshot refresh should clear the preferred model when runtime auth disappears.' );
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
				$codex_provider_with_mock_runtime(
					static function ( $preempt, array $args, string $url ) use ( $codex_provider_base_url, $codex_provider_http_json_response ) {
						if ( 0 !== strpos( $url, $codex_provider_base_url ) ) {
							return $preempt;
						}

						$path = (string) wp_parse_url( $url, PHP_URL_PATH );

						if ( '/v1/account/snapshot' === $path ) {
							return $codex_provider_http_json_response(
								500,
								[
									'error' => [
										'code'    => 'runtime_failure',
										'message' => 'Transient cron refresh failure during verification.',
									],
								]
							);
						}

						return $preempt;
					},
					static function () use ( $codex_provider_assert, $codex_provider_temporary_user_id ) {
						ConnectionRefreshScheduler::refresh_linked_connections();

						$codex_provider_assert( null !== ConnectionRepository::get_for_user( $codex_provider_temporary_user_id ), 'Cron snapshot refresh should preserve local state after transient runtime failures.' );
					}
				);

			// --- Runtime diagnostics: Client::diagnostics() does not touch HealthMonitor. ---
			delete_transient( 'htperkins_aipfc_runtime_health' );
			HealthMonitor::record_failure( 'sentinel-before-diagnostics' );

			$codex_provider_with_mock_runtime(
				static function ( $preempt, array $args, string $url ) use ( $codex_provider_http_json_response ) {
					if ( false !== strpos( $url, '/v1/diagnostics' ) ) {
						return $codex_provider_http_json_response(
							200,
							[
								'ok'      => true,
								'service' => 'codex-wp-sidecar',
								'version' => '0.1.5',
								'checks'  => [
									[ 'id' => 'python_version', 'label' => 'Python runtime', 'status' => 'pass', 'detail' => 'Python 3.11.6' ],
								],
							]
						);
					}
					return $preempt;
				},
				static function () use ( $codex_provider_assert ) {
					$client = new \Htperkins\AIProviderForCodex\Runtime\Client();
					$result = $client->diagnostics();
					$codex_provider_assert( true === ( $result['ok'] ?? null ), 'Client::diagnostics() should return the sidecar ok flag.' );
					$codex_provider_assert( 'pass' === $result['checks'][0]['status'], 'Client::diagnostics() should return sidecar checks.' );
					$codex_provider_assert(
						'sentinel-before-diagnostics' === HealthMonitor::get_status()['error'],
						'Client::diagnostics() must not overwrite the HealthMonitor reachability cache.'
					);
				}
			);

			// --- Runtime diagnostics: controller row mapping + verdict storage. ---
			$codex_provider_diagnostics_rows = static function ( array $body ): array {
				$by_id = [];
				foreach ( $body['rows'] as $row ) {
					$by_id[ $row['id'] ] = $row;
				}
				return $by_id;
			};

			// 200 with a failing sidecar check => overall ok false, verdict recorded.
			delete_transient( 'htperkins_aipfc_last_diagnostics' );
			$codex_provider_with_mock_runtime(
				static function ( $preempt, array $args, string $url ) use ( $codex_provider_http_json_response ) {
					if ( false !== strpos( $url, '/v1/diagnostics' ) ) {
						return $codex_provider_http_json_response(
							200,
							[
								'ok'     => false,
								'checks' => [
									[ 'id' => 'codex_cli', 'label' => 'Codex CLI', 'status' => 'fail', 'detail' => 'codex not found' ],
								],
							]
						);
					}
					return $preempt;
				},
				static function () use ( $codex_provider_assert, $codex_provider_diagnostics_rows ) {
					$response = \Htperkins\AIProviderForCodex\REST\DiagnosticsController::run();
					$body     = $response->get_data();
					$rows     = $codex_provider_diagnostics_rows( $body );
					$codex_provider_assert( false === $body['ok'], 'A 200 with a failing check must yield overall ok=false.' );
					$codex_provider_assert( 'pass' === $rows['reachable']['status'], 'Reachable row should pass on HTTP 200.' );
					$codex_provider_assert( 'pass' === $rows['bearer']['status'], 'Bearer row should pass on HTTP 200.' );
					$codex_provider_assert( 'fail' === $rows['codex_cli']['status'], 'Failing sidecar check should surface as a fail row.' );
					$verdict = get_transient( 'htperkins_aipfc_last_diagnostics' );
					$codex_provider_assert( is_array( $verdict ) && false === $verdict['ok'], 'Verdict transient must record ok=false.' );
				}
			);

			// 401 => bearer fail, reachable pass.
			$codex_provider_with_mock_runtime(
				static function ( $preempt, array $args, string $url ) use ( $codex_provider_http_json_response ) {
					if ( false !== strpos( $url, '/v1/diagnostics' ) ) {
						return $codex_provider_http_json_response( 401, [ 'error' => [ 'code' => 'unauthorized', 'message' => 'Invalid bearer token.' ] ] );
					}
					return $preempt;
				},
				static function () use ( $codex_provider_assert, $codex_provider_diagnostics_rows ) {
					$rows = $codex_provider_diagnostics_rows( \Htperkins\AIProviderForCodex\REST\DiagnosticsController::run()->get_data() );
					$codex_provider_assert( 'pass' === $rows['reachable']['status'], '401 still proves reachability.' );
					$codex_provider_assert( 'fail' === $rows['bearer']['status'], '401 must mark the bearer row as failed.' );
				}
			);

			// 500 => its own runtime_error row (not bearer), reachable pass; the verdict still records an issue.
			delete_transient( 'htperkins_aipfc_last_diagnostics' );
			$codex_provider_with_mock_runtime(
				static function ( $preempt, array $args, string $url ) use ( $codex_provider_http_json_response ) {
					if ( false !== strpos( $url, '/v1/diagnostics' ) ) {
						return $codex_provider_http_json_response( 500, [ 'error' => [ 'message' => 'runtime exploded' ] ] );
					}
					return $preempt;
				},
				static function () use ( $codex_provider_assert, $codex_provider_diagnostics_rows ) {
					$rows = $codex_provider_diagnostics_rows( \Htperkins\AIProviderForCodex\REST\DiagnosticsController::run()->get_data() );
					$codex_provider_assert( 'pass' === $rows['reachable']['status'], 'A 500 still proves reachability.' );
					$codex_provider_assert( 'warn' === ( $rows['runtime_error']['status'] ?? '' ), 'A non-401 runtime error becomes its own runtime_error row.' );
					$codex_provider_assert( ! isset( $rows['bearer'] ), 'A non-401 runtime error must not be attributed to the bearer token.' );
					$verdict = get_transient( 'htperkins_aipfc_last_diagnostics' );
					$codex_provider_assert(
						is_array( $verdict ) && false === $verdict['ok'] && count( (array) $verdict['failed'] ) >= 1,
						'A failed diagnostic (ok=false) must record at least one issue so the settings card never reports "0 issues".'
					);
				}
			);

			// 404 => dedicated "endpoint" row (the stale-sidecar case), never the bearer row.
			$codex_provider_with_mock_runtime(
				static function ( $preempt, array $args, string $url ) use ( $codex_provider_http_json_response ) {
					if ( false !== strpos( $url, '/v1/diagnostics' ) ) {
						return $codex_provider_http_json_response( 404, [ 'error' => [ 'code' => 'not_found', 'message' => 'Runtime route not found.' ] ] );
					}
					return $preempt;
				},
				static function () use ( $codex_provider_assert, $codex_provider_diagnostics_rows ) {
					$rows = $codex_provider_diagnostics_rows( \Htperkins\AIProviderForCodex\REST\DiagnosticsController::run()->get_data() );
					$codex_provider_assert( 'pass' === $rows['reachable']['status'], 'A 404 still proves reachability.' );
					$codex_provider_assert( isset( $rows['endpoint'] ) && 'fail' === $rows['endpoint']['status'], 'A 404 must surface a dedicated endpoint failure row.' );
					$codex_provider_assert( '' !== ( $rows['endpoint']['remediation'] ?? '' ), 'The endpoint failure row must include remediation.' );
					$codex_provider_assert( ! isset( $rows['bearer'] ), 'A 404 must not be misattributed to the bearer token.' );
				}
			);

			// Transport failure => reachable fail.
			$codex_provider_with_mock_runtime(
				static function ( $preempt, array $args, string $url ) {
					if ( false !== strpos( $url, '/v1/diagnostics' ) ) {
						return new WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect to 127.0.0.1 port 4317: Connection refused' );
					}
					return $preempt;
				},
				static function () use ( $codex_provider_assert, $codex_provider_diagnostics_rows ) {
					$rows = $codex_provider_diagnostics_rows( \Htperkins\AIProviderForCodex\REST\DiagnosticsController::run()->get_data() );
					$codex_provider_assert( 'fail' === $rows['reachable']['status'], 'A transport failure must mark reachability as failed.' );
				}
			);

			// --- Setup snippets + stable suggested token. ---
			// suggested_bearer_token() returns the configured bearer when one is set
			// and only generates+persists a token otherwise. Clear the configured
			// bearer for this block so the generation/persistence path is actually
			// exercised (env is already cleared above; the shared env file is not
			// PHP-readable here), instead of passing vacuously.
			$codex_provider_saved_bearer_option = get_option( Settings::OPTION_RUNTIME_BEARER, null );
			update_option( Settings::OPTION_RUNTIME_BEARER, '' );
			$codex_provider_assert(
				'' === Settings::get_bearer_token(),
				'Test setup: no bearer should be configured while exercising suggested-token generation.'
			);
			delete_option( 'htperkins_aipfc_suggested_bearer_token' );
			$codex_provider_unit = \Htperkins\AIProviderForCodex\Admin\SetupSnippets::systemd_unit();
			$codex_provider_assert(
				false === strpos( $codex_provider_unit, '/path/to/wp-content/plugins/ai-provider-for-codex' ),
				'The systemd snippet must replace the placeholder plugin path.'
			);
			$codex_provider_assert(
				false !== strpos( $codex_provider_unit, 'EnvironmentFile=/etc/codex-app-server.env' ),
				'The systemd snippet must read the app-server env file.'
			);
			$codex_provider_assert(
				false !== strpos( $codex_provider_unit, 'ExecStart=/usr/local/bin/codex app-server --listen ${CODEX_WP_RUNTIME_BASE_URL}' ),
				'The systemd snippet must start the externally installed Codex app-server.'
			);
			$codex_provider_assert(
				false === strpos( $codex_provider_unit, 'codex-wp-sidecar' ),
				'The systemd snippet must not reference the removed sidecar service.'
			);
			$codex_provider_assert(
				false === strpos( $codex_provider_unit, untrailingslashit( \Htperkins\AIProviderForCodex\PLUGIN_DIR ) ),
				'The app-server systemd snippet must not require files inside the plugin directory.'
			);

			$codex_provider_env = \Htperkins\AIProviderForCodex\Admin\SetupSnippets::env_file();
			$codex_provider_assert(
				false !== strpos( $codex_provider_env, 'CODEX_WP_BEARER_TOKEN=' ),
				'The env snippet must include the bearer token line.'
			);

			$codex_provider_token_a = \Htperkins\AIProviderForCodex\Admin\SetupSnippets::suggested_bearer_token();
			$codex_provider_token_b = \Htperkins\AIProviderForCodex\Admin\SetupSnippets::suggested_bearer_token();
			$codex_provider_assert( '' !== $codex_provider_token_a, 'A suggested token must be generated.' );
			$codex_provider_assert( $codex_provider_token_a === $codex_provider_token_b, 'The suggested token must be stable across calls.' );
			// A non-autoloaded option is absent from wp_load_alloptions() (which holds autoloaded options only).
			$codex_provider_assert(
				! array_key_exists( 'htperkins_aipfc_suggested_bearer_token', wp_load_alloptions() ),
				'The suggested-token option must not autoload.'
			);

			// A race where another request stores a token between this function's
			// cache-miss read and its add_option() call: it must return the
			// persisted value, not the token it just generated. The pre_option
			// filter only fools this function's own cache check (one read); add_option
			// detects the existing row via its own DB query and returns false.
			delete_option( 'htperkins_aipfc_suggested_bearer_token' );
			add_option( 'htperkins_aipfc_suggested_bearer_token', 'codex-raced-winner', '', false );
			$codex_provider_token_cache_miss_once = true;
			$codex_provider_token_race_filter     = static function ( $pre ) use ( &$codex_provider_token_cache_miss_once ) {
				if ( $codex_provider_token_cache_miss_once ) {
					$codex_provider_token_cache_miss_once = false;
					return '';
				}
				return $pre;
			};
			add_filter( 'pre_option_htperkins_aipfc_suggested_bearer_token', $codex_provider_token_race_filter );
			$codex_provider_raced_token = \Htperkins\AIProviderForCodex\Admin\SetupSnippets::suggested_bearer_token();
			remove_filter( 'pre_option_htperkins_aipfc_suggested_bearer_token', $codex_provider_token_race_filter );
			$codex_provider_assert(
				'codex-raced-winner' === $codex_provider_raced_token,
				'suggested_bearer_token() must return the persisted token when add_option loses a race, not its own un-stored token.'
			);
			delete_option( 'htperkins_aipfc_suggested_bearer_token' );

			if ( null === $codex_provider_saved_bearer_option ) {
				delete_option( Settings::OPTION_RUNTIME_BEARER );
			} else {
				update_option( Settings::OPTION_RUNTIME_BEARER, $codex_provider_saved_bearer_option );
			}

			// --- Settings page is passive on load (no runtime HTTP during render). ---
			wp_set_current_user( 1 );
			$codex_provider_assert(
				current_user_can( 'manage_options' ),
				'verify.php expects user 1 to be an administrator for the settings-render check.'
			);

			$codex_provider_http_calls = 0;
			$codex_provider_with_mock_runtime(
				static function ( $preempt, array $args, string $url ) use ( &$codex_provider_http_calls ) {
					$codex_provider_http_calls++;
					return new WP_Error( 'blocked', 'No runtime HTTP is allowed during settings render.' );
				},
				static function () use ( &$codex_provider_http_calls, $codex_provider_assert ) {
					ob_start();
					SiteSettings::render_page();
					$html = (string) ob_get_clean();
					$codex_provider_assert( 0 === $codex_provider_http_calls, 'render_page() must not make runtime HTTP calls on load.' );
					$codex_provider_assert( false !== strpos( $html, 'data-codex-diagnostics-run' ), 'The settings page must render the Check runtime button.' );
					$codex_provider_assert( false !== strpos( $html, 'EnvironmentFile=/etc/codex-app-server.env' ), 'The settings page must render the app-server systemd snippet.' );
				}
			);
			} catch ( \Throwable $codex_provider_throwable ) {
		$codex_provider_failure = $codex_provider_throwable;
	} finally {
		wp_set_current_user( $codex_provider_original_user_id );
		ConnectionSnapshotRepository::delete( $codex_provider_temporary_connection_id );

		if ( $codex_provider_original_env_base_url_exists ) {
			putenv( 'CODEX_WP_RUNTIME_BASE_URL=' . $codex_provider_original_env_base_url );
			$_ENV['CODEX_WP_RUNTIME_BASE_URL'] = $codex_provider_original_env_base_url;
		} else {
			putenv( 'CODEX_WP_RUNTIME_BASE_URL' );
			unset( $_ENV['CODEX_WP_RUNTIME_BASE_URL'] );
		}

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
		WP_CLI::success( 'Scriptorium AI Provider for Codex verification passed.' );
		return;
	}

	echo "Scriptorium AI Provider for Codex verification passed.\n";
} )();
