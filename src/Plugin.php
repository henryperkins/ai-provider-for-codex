<?php
/**
 * Main plugin wiring.
 *
 * @package AIProviderForCodex
 */

declare( strict_types=1 );

namespace AIProviderForCodex;

use AIProviderForCodex\Auth\ConnectionRefreshScheduler;
use AIProviderForCodex\Admin\ConnectorApprovalIntegration;
use AIProviderForCodex\Admin\ConnectorsIntegration;
use AIProviderForCodex\Admin\SiteSettings;
use AIProviderForCodex\Admin\UserConnectionPage;
use AIProviderForCodex\Database\Installer;
use AIProviderForCodex\Provider\ModelCatalogState;
use AIProviderForCodex\REST\ConnectController;
use AIProviderForCodex\REST\StatusController;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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
		add_action( 'init', __NAMESPACE__ . '\\register_provider', 5 );
		add_action( 'init', [ Installer::class, 'maybe_upgrade' ], 1 );
		add_action( 'init', [ ConnectorApprovalIntegration::class, 'maybe_self_approve' ], 2 );
		ConnectionRefreshScheduler::register_hooks();

		add_action( 'admin_menu', [ SiteSettings::class, 'register_page' ] );
		add_action( 'admin_menu', [ UserConnectionPage::class, 'register_page' ] );
		add_action( 'admin_init', [ SiteSettings::class, 'register_settings' ] );
		add_action( 'admin_init', [ UserConnectionPage::class, 'maybe_handle_actions' ] );
		add_action( 'admin_enqueue_scripts', [ ConnectorsIntegration::class, 'enqueue_connectors_assets' ] );
		add_filter( 'script_module_data_scriptorium-ai-provider-for-codex/connectors', [ ConnectorsIntegration::class, 'script_module_data' ] );
		add_filter( 'script_module_data_scriptorium-ai-provider-for-codex/user-connection', [ UserConnectionPage::class, 'script_module_data' ] );
		add_action( 'options-connectors_init', [ ConnectorsIntegration::class, 'enqueue_connectors_boot_module' ] );
		add_action( 'options-connectors-wp-admin_init', [ ConnectorsIntegration::class, 'enqueue_connectors_boot_module' ] );
		add_action( 'wp_connectors_init', [ ConnectorsIntegration::class, 'register_connector_metadata' ] );
		add_filter( 'wpai_has_ai_credentials', [ ConnectorsIntegration::class, 'filter_ai_plugin_has_credentials' ], 10, 2 );
		add_filter( 'wpai_pre_has_valid_credentials_check', [ ConnectorsIntegration::class, 'filter_ai_plugin_has_valid_credentials' ] );
		add_filter( 'wpai_is_codex_connector_configured', [ ConnectorsIntegration::class, 'filter_ai_plugin_is_codex_connector_configured' ] );
		add_filter( 'wpai_preferred_text_models', [ $this, 'filter_preferred_text_models' ] );

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
	 * Prepends the current Codex text model to WordPress/ai's preferred list.
	 *
	 * @param array<int,array{string,string}> $models Preferred provider/model pairs.
	 * @return array<int,array{string,string}>
	 */
	public function filter_preferred_text_models( array $models ): array {
		$catalog  = ModelCatalogState::get_effective_catalog();
		$model_id = $catalog['selected_model'];

		if ( '' !== $model_id ) {
			array_unshift( $models, [ 'codex', $model_id ] );
		}

		return $models;
	}
}
