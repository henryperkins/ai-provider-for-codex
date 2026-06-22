<?php
/**
 * Connectors screen integration.
 *
 * @package HtperkinsAIProviderForCodex
 */

declare( strict_types=1 );

namespace Htperkins\AIProviderForCodex\Admin;

use Htperkins\AIProviderForCodex\Auth\ConnectionService;
use Htperkins\AIProviderForCodex\Provider\ModelCatalogState;
use Htperkins\AIProviderForCodex\Runtime\HealthMonitor;
use Htperkins\AIProviderForCodex\Runtime\Settings;

/**
 * Makes the provider visible and actionable in Settings > Connectors.
 */
final class ConnectorsIntegration {

	private const MODULE_ID     = 'htperkins-ai-provider-for-codex/connectors';
	private const SCRIPT_HANDLE = 'htperkins-aipfc-connectors';
	private const CONNECTOR_ID  = 'codex';
	private const AI_IMAGE_GENERATION_SCRIPT_HANDLE = 'ai_image_generation';

	/**
	 * Returns the connector module config payload.
	 *
	 * @return array<string,string>
	 */
	private static function connector_config(): array {
		return [
			'connectorId'           => self::CONNECTOR_ID,
			'statusUrl'             => rest_url( 'htperkins-aipfc/v1/status' ),
			'statusPath'            => '/htperkins-aipfc/v1/status',
			'providerStatusUrl'     => rest_url( 'htperkins-aipfc/v1/status' ),
			'providerStatusPath'    => '/htperkins-aipfc/v1/status',
			'startConnectUrl'       => rest_url( 'htperkins-aipfc/v1/connect/start' ),
			'startConnectPath'      => '/htperkins-aipfc/v1/connect/start',
			'connectStatusUrl'      => rest_url( 'htperkins-aipfc/v1/connect/status' ),
			'connectStatusPath'     => '/htperkins-aipfc/v1/connect/status',
			'connectorApprovalsUrl' => admin_url( 'tools.php?page=ai-connector-approval' ),
			'siteSettingsUrl'       => SiteSettings::page_url(),
			'userConnectionUrl'     => UserConnectionPage::page_url(),
			'restNonce'             => wp_create_nonce( 'wp_rest' ),
		];
	}

	/**
	 * Adds the owning-plugin file pointer that auto-discovery does not set for new providers.
	 *
	 * Name, description, logo_url, type, and authentication.method are all populated by
	 * the WP 7.0 Connectors API auto-discovery from the registered ProviderMetadata. Only
	 * plugin.file requires a manual override for non-built-in connectors.
	 *
	 * @param \WP_Connector_Registry $registry Connector registry.
	 * @return void
	 */
	public static function register_connector_metadata( \WP_Connector_Registry $registry ): void {
		if ( ! $registry->is_registered( self::CONNECTOR_ID ) ) {
			return;
		}

		$connector = $registry->unregister( self::CONNECTOR_ID );
		if ( ! is_array( $connector ) ) {
			return;
		}

		$plugin                      = isset( $connector['plugin'] ) && is_array( $connector['plugin'] ) ? $connector['plugin'] : [];
		$plugin['file']              = plugin_basename( \Htperkins\AIProviderForCodex\PLUGIN_FILE );
		$connector['plugin']         = $plugin;

		$registry->register( self::CONNECTOR_ID, $connector );
	}

	/**
	 * Lets the AI plugin recognize Codex's local-runtime connector as configured.
	 *
	 * The AI plugin's default check only detects API-key connectors with stored
	 * settings. Codex uses a local app-server endpoint instead.
	 *
	 * @param bool                $has_credentials Whether AI credentials are already available.
	 * @param array<string,mixed> $connectors Registered connectors from WordPress core.
	 * @return bool
	 */
	public static function filter_ai_plugin_has_credentials( bool $has_credentials, array $connectors ): bool {
		if ( $has_credentials || ! self::has_codex_connector( $connectors ) ) {
			return $has_credentials;
		}

		return Settings::has_required_configuration();
	}

	/**
	 * Lets the AI plugin's validity check use the Codex runtime health state.
	 *
	 * @param bool|null $has_valid_credentials Existing short-circuit value, or null to continue default checks.
	 * @return bool|null
	 */
	public static function filter_ai_plugin_has_valid_credentials( ?bool $has_valid_credentials ): ?bool {
		if ( null !== $has_valid_credentials || ! Settings::has_required_configuration() ) {
			return $has_valid_credentials;
		}

		if ( function_exists( 'wp_is_connector_registered' ) && ! wp_is_connector_registered( self::CONNECTOR_ID ) ) {
			return $has_valid_credentials;
		}

		return HealthMonitor::is_available() ? true : $has_valid_credentials;
	}

	/**
	 * Lets the AI plugin's status widget show Codex as configured when the local runtime is ready.
	 *
	 * @param bool $is_configured Whether the AI plugin already considers the connector configured.
	 * @return bool
	 */
	public static function filter_ai_plugin_is_codex_connector_configured( bool $is_configured ): bool {
		if ( $is_configured ) {
			return true;
		}

		return Settings::has_required_configuration() && HealthMonitor::is_available();
	}

	/**
	 * Lets WordPress AI's image-generation UI recognize Codex's non-API-key connector.
	 *
	 * The AI plugin's page-load support helper only considers API-key connectors.
	 * Codex uses local app-server auth, so patch the already-localized script
	 * data when the effective catalog exposes the image model.
	 *
	 * @return void
	 */
	public static function maybe_override_ai_image_generation_support(): void {
		if ( ! wp_script_is( self::AI_IMAGE_GENERATION_SCRIPT_HANDLE, 'enqueued' ) ) {
			return;
		}

		$catalog = self::get_current_user_image_catalog();

		if ( ! in_array( ModelCatalogState::IMAGE_MODEL_ID, $catalog['image_model_ids'], true ) ) {
			return;
		}

		wp_add_inline_script(
			self::AI_IMAGE_GENERATION_SCRIPT_HANDLE,
			'window.aiImageGenerationData = window.aiImageGenerationData || {}; window.aiImageGenerationData.hasImageGenerationSupport = true;',
			'after'
		);
	}

	/**
	 * Returns the current catalog, recovering a stored runtime snapshot when possible.
	 *
	 * @return array<string,mixed>
	 */
	private static function get_current_user_image_catalog(): array {
		$wp_user_id = get_current_user_id();
		$catalog    = ModelCatalogState::get_effective_catalog( $wp_user_id );

		if ( in_array( ModelCatalogState::IMAGE_MODEL_ID, $catalog['image_model_ids'], true ) ) {
			return $catalog;
		}

		if ( $wp_user_id <= 0 || ! Settings::has_required_configuration() ) {
			return $catalog;
		}

		if ( in_array( HealthMonitor::get_status()['status'], [ 'connector_unapproved', 'unreachable' ], true ) ) {
			return $catalog;
		}

		try {
			( new ConnectionService() )->refresh_snapshot( $wp_user_id );
		} catch ( \Throwable $exception ) {
			return $catalog;
		}

		return ModelCatalogState::get_effective_catalog( $wp_user_id );
	}

	/**
	 * Registers the Codex connector script module.
	 *
	 * @return void
	 */
	private static function register_connectors_module(): void {
		wp_register_script_module(
			self::MODULE_ID,
			plugins_url( 'assets/connectors.js', \Htperkins\AIProviderForCodex\PLUGIN_FILE ),
			[
				[
					'id'     => '@wordpress/connectors',
					'import' => 'static',
				],
			],
			\Htperkins\AIProviderForCodex\VERSION
		);
	}

	/**
	 * Registers classic WordPress package prerequisites used by the connector module.
	 *
	 * @return void
	 */
	private static function register_connectors_prerequisites(): void {
		wp_register_script(
			self::SCRIPT_HANDLE,
			'',
			[
				'react',
				'react-dom',
				'react-jsx-runtime',
				'wp-api-fetch',
				'wp-components',
				'wp-element',
				'wp-i18n',
				'wp-private-apis',
				'wp-url',
			],
			\Htperkins\AIProviderForCodex\VERSION,
			false
		);
	}

	/**
	 * Enqueues the Codex module while the routed Connectors app builds its boot graph.
	 *
	 * @return void
	 */
	public static function enqueue_connectors_boot_module(): void {
		self::register_connectors_module();
		self::register_connectors_prerequisites();

		wp_enqueue_script( self::SCRIPT_HANDLE );
		wp_enqueue_script_module( self::MODULE_ID );
	}

	/**
	 * Enqueues the custom connector module on the Connectors screen.
	 *
	 * @param string $hook_suffix Admin hook suffix.
	 * @return void
	 */
	public static function enqueue_connectors_assets( string $hook_suffix ): void {
		unset( $hook_suffix );

		if ( ! self::is_connectors_screen() ) {
			return;
		}

		self::enqueue_connectors_boot_module();
	}

	/**
	 * Supplies bootstrap data for the connector script module.
	 *
	 * @param array<string,mixed> $data Existing module data.
	 * @return array<string,mixed>
	 */
	public static function script_module_data( array $data ): array {
		if ( ! self::is_connectors_screen() ) {
			return $data;
		}

		return array_merge( $data, self::connector_config() );
	}

	/**
	 * Adds action links on the Plugins screen.
	 *
	 * @param string[] $links Existing links.
	 * @return string[]
	 */
	public static function plugin_action_links( array $links ): array {
		array_unshift(
			$links,
			sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'options-connectors.php' ) ),
				esc_html__( 'Connectors', 'ai-provider-for-codex' )
			),
			sprintf(
				'<a href="%s">%s</a>',
				esc_url( SiteSettings::page_url() ),
				esc_html__( 'Settings', 'ai-provider-for-codex' )
			)
		);

		return $links;
	}

	/**
	 * Renders a setup notice for admins until the runtime is configured.
	 *
	 * @return void
	 */
	public static function maybe_render_setup_notice(): void {
		if ( ! current_user_can( 'manage_options' ) || Settings::has_required_configuration() ) {
			return;
		}

		$current_screen = get_current_screen();

		if ( $current_screen && in_array( $current_screen->id, [ 'options-connectors', 'settings_page_' . \Htperkins\AIProviderForCodex\SLUG ], true ) ) {
			return;
		}

		printf(
			'<div class="notice notice-info"><p>%s</p></div>',
			wp_kses_post(
				SafeFormat::sprintf(
					/* translators: 1: Settings URL, 2: Connectors URL. */
					__(
						'Scriptorium AI Provider for Codex is active, but it will not work until codex app-server is configured and running on this server. Open <a href="%1$s">plugin settings</a> for the systemd and environment-file setup guide, then confirm the result on <a href="%2$s">Connectors</a>.',
						'ai-provider-for-codex'
					),
					esc_url( SiteSettings::page_url() ),
					esc_url( admin_url( 'options-connectors.php' ) )
				)
			)
		);
	}

	/**
	 * Renders a notice for logged-in users who haven't linked their Codex account.
	 *
	 * @return void
	 */
	public static function maybe_render_unlinked_notice(): void {
		if ( ! is_user_logged_in() || ! Settings::has_required_configuration() || Settings::is_app_server_endpoint() ) {
			return;
		}

		$user_id = get_current_user_id();

		if ( get_user_meta( $user_id, 'htperkins_aipfc_dismiss_link_notice', true ) ) {
			return;
		}

		$connection = \Htperkins\AIProviderForCodex\Auth\ConnectionRepository::get_for_user( $user_id );

		if ( null !== $connection ) {
			return;
		}

		$current_screen = get_current_screen();

		if ( $current_screen && in_array( $current_screen->id, [ 'options-connectors', 'users_page_' . \Htperkins\AIProviderForCodex\SLUG, 'profile_page_' . \Htperkins\AIProviderForCodex\SLUG, 'settings_page_' . \Htperkins\AIProviderForCodex\SLUG ], true ) ) {
			return;
		}

		printf(
			'<div class="notice notice-info is-dismissible" data-codex-dismiss-notice="link"><p>%s</p></div>',
			wp_kses_post(
				SafeFormat::sprintf(
					/* translators: %s: user connection URL. */
					__(
						'Codex AI is available on this site. <a href="%s">Connect your Codex account</a> to start using AI features.',
						'ai-provider-for-codex'
					),
					esc_url( UserConnectionPage::page_url() )
				)
			)
		);
	}

	/**
	 * AJAX handler to dismiss the unlinked-user notice.
	 *
	 * @return void
	 */
	public static function ajax_dismiss_notice(): void {
		check_ajax_referer( 'codex-provider-dismiss-notice', 'nonce' );

		if ( ! current_user_can( 'read' ) ) {
			wp_send_json_error(
				[
					'message' => __( 'You are not allowed to dismiss this notice.', 'ai-provider-for-codex' ),
				],
				403
			);
		}

		update_user_meta( get_current_user_id(), 'htperkins_aipfc_dismiss_link_notice', '1' );
		wp_send_json_success();
	}

	/**
	 * Enqueues the dismiss-notice script on admin pages if the unlinked notice may render.
	 *
	 * @return void
	 */
	public static function maybe_enqueue_dismiss_script(): void {
		if ( ! is_user_logged_in() || ! Settings::has_required_configuration() || Settings::is_app_server_endpoint() ) {
			return;
		}

		$user_id = get_current_user_id();

		if ( get_user_meta( $user_id, 'htperkins_aipfc_dismiss_link_notice', true ) ) {
			return;
		}

		$connection = \Htperkins\AIProviderForCodex\Auth\ConnectionRepository::get_for_user( $user_id );

		if ( null !== $connection ) {
			return;
		}

		wp_add_inline_script(
			'common',
			sprintf(
				'jQuery(function($){$(document).on("click",".notice[data-codex-dismiss-notice] .notice-dismiss",function(){$.post(ajaxurl,{action:"htperkins_aipfc_dismiss_notice",nonce:"%s"});});});',
				esc_js( wp_create_nonce( 'codex-provider-dismiss-notice' ) )
			)
		);
	}

	/**
	 * Returns whether the current admin screen is the Connectors page.
	 *
	 * @return bool
	 */
	private static function is_connectors_screen(): bool {
		$current_screen = get_current_screen();

		if ( $current_screen && 'options-connectors' === $current_screen->id ) {
			return true;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reads the current admin page slug only to detect screen context.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		return in_array( $page, [ 'options-connectors', 'options-connectors-wp-admin' ], true );
	}

	/**
	 * Returns whether the registered connector list includes Codex.
	 *
	 * @param array<string,mixed> $connectors Registered connectors from WordPress core.
	 * @return bool
	 */
	private static function has_codex_connector( array $connectors ): bool {
		$connector = $connectors[ self::CONNECTOR_ID ] ?? null;

		return is_array( $connector ) && 'ai_provider' === (string) ( $connector['type'] ?? '' );
	}
}
