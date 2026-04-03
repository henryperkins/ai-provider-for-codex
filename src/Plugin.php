<?php
/**
 * Main plugin wiring.
 *
 * @package AIProviderForCodex
 */

declare( strict_types=1 );

namespace AIProviderForCodex;

use AIProviderForCodex\Admin\ConnectorsIntegration;
use AIProviderForCodex\Admin\SiteSettings;
use AIProviderForCodex\Admin\UserConnectionPage;
use AIProviderForCodex\Database\Installer;
use AIProviderForCodex\Provider\CodexProvider;
use AIProviderForCodex\REST\ConnectController;
use AIProviderForCodex\REST\StatusController;
use WordPress\AiClient\AiClient;

/**
 * Coordinates hook registration.
 */
final class Plugin {

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'init', [ Installer::class, 'maybe_upgrade' ], 1 );
		add_action( 'init', [ $this, 'register_provider' ], 5 );
		add_action( 'init', [ $this, 'load_textdomain' ] );

		add_action( 'admin_menu', [ SiteSettings::class, 'register_page' ] );
		add_action( 'admin_menu', [ UserConnectionPage::class, 'register_page' ] );
		add_action( 'admin_init', [ SiteSettings::class, 'register_settings' ] );
		add_action( 'admin_init', [ UserConnectionPage::class, 'maybe_handle_actions' ] );
		add_action( 'admin_enqueue_scripts', [ ConnectorsIntegration::class, 'enqueue_connectors_assets' ] );
		add_action( 'wp_connectors_init', [ ConnectorsIntegration::class, 'register_connector_metadata' ] );

		add_action( 'rest_api_init', [ ConnectController::class, 'register_routes' ] );
		add_action( 'rest_api_init', [ StatusController::class, 'register_routes' ] );

		add_filter(
			'plugin_action_links_' . plugin_basename( PLUGIN_FILE ),
			[ ConnectorsIntegration::class, 'plugin_action_links' ]
		);
		add_action( 'admin_notices', [ ConnectorsIntegration::class, 'maybe_render_setup_notice' ] );
		add_action( 'admin_notices', [ ConnectorsIntegration::class, 'maybe_render_unlinked_notice' ] );
		add_action( 'admin_enqueue_scripts', [ ConnectorsIntegration::class, 'maybe_enqueue_dismiss_script' ] );
		add_action( 'wp_ajax_codex_provider_dismiss_notice', [ ConnectorsIntegration::class, 'ajax_dismiss_notice' ] );
	}

	/**
	 * Loads translations.
	 *
	 * @return void
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'ai-provider-for-codex', false, dirname( plugin_basename( PLUGIN_FILE ) ) . '/languages' );
	}

	/**
	 * Registers the Codex provider with the WordPress AI Client.
	 *
	 * @return void
	 */
	public function register_provider(): void {
		if ( ! class_exists( AiClient::class ) ) {
			return;
		}

		$registry = AiClient::defaultRegistry();

		if ( $registry->hasProvider( CodexProvider::class ) ) {
			return;
		}

		$registry->registerProvider( CodexProvider::class );
	}
}
