<?php
/**
 * Connectors screen integration.
 *
 * @package AIProviderForCodex
 */

declare( strict_types=1 );

namespace AIProviderForCodex\Admin;

use AIProviderForCodex\Broker\Settings;

/**
 * Makes the provider visible and actionable in Settings > Connectors.
 */
final class ConnectorsIntegration {

	private const MODULE_ID      = 'ai-provider-for-codex/connectors';
	private const SCRIPT_HANDLE  = 'ai-provider-for-codex-connectors';
	private const CONNECTOR_ID   = 'codex';

	/**
	 * Registers richer connector metadata for the Codex provider.
	 *
	 * @param \WP_Connector_Registry $registry Connector registry.
	 * @return void
	 */
	public static function register_connector_metadata( \WP_Connector_Registry $registry ): void {
		if ( $registry->is_registered( self::CONNECTOR_ID ) ) {
			$registry->unregister( self::CONNECTOR_ID );
		}

		$registry->register(
			self::CONNECTOR_ID,
			[
				'name'           => __( 'Codex', 'ai-provider-for-codex' ),
				'description'    => __( 'AI text generation powered by Codex. Each user connects their own account.', 'ai-provider-for-codex' ),
				'type'           => 'ai_provider',
				'logo_url'       => plugins_url( 'src/Provider/logo.svg', \AIProviderForCodex\PLUGIN_FILE ),
				'authentication' => [
					'method' => 'none',
				],
				'plugin'         => [
					'file' => plugin_basename( \AIProviderForCodex\PLUGIN_FILE ),
				],
			]
		);
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

		$config = [
			'connectorId'       => self::CONNECTOR_ID,
			'statusPath'        => '/codex-provider/v1/status',
			'startConnectPath'  => '/codex-provider/v1/connect/start',
			'siteSettingsUrl'   => SiteSettings::page_url(),
			'userConnectionUrl' => UserConnectionPage::page_url(),
		];

		wp_register_script_module(
			self::MODULE_ID,
			plugins_url( 'assets/connectors.js', \AIProviderForCodex\PLUGIN_FILE ),
			[
				[
					'id'     => '@wordpress/connectors',
					'import' => 'static',
				],
			],
			\AIProviderForCodex\VERSION
		);

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
			\AIProviderForCodex\VERSION,
			true
		);

		wp_add_inline_script(
			self::SCRIPT_HANDLE,
			'window.aiProviderForCodexConnectors = ' . wp_json_encode( $config ) . ';',
			'before'
		);
		wp_enqueue_script( self::SCRIPT_HANDLE );
		wp_enqueue_script_module( self::MODULE_ID );
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
	 * Renders a setup notice for admins until the broker is configured.
	 *
	 * @return void
	 */
	public static function maybe_render_setup_notice(): void {
		if ( ! current_user_can( 'manage_options' ) || Settings::has_required_site_configuration() ) {
			return;
		}

		$current_screen = get_current_screen();

		if ( $current_screen && in_array( $current_screen->id, [ 'options-connectors', 'settings_page_ai-provider-for-codex' ], true ) ) {
			return;
		}

		printf(
			'<div class="notice notice-info"><p>%s</p></div>',
			wp_kses_post(
				sprintf(
					/* translators: 1: Connectors URL, 2: Settings URL. */
					__(
						'AI Provider for Codex is active, but the broker is not configured yet. Start on the <a href="%1$s">Connectors</a> screen or go directly to <a href="%2$s">plugin settings</a>.',
						'ai-provider-for-codex'
					),
					esc_url( admin_url( 'options-connectors.php' ) ),
					esc_url( SiteSettings::page_url() )
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
		if ( ! is_user_logged_in() || ! Settings::has_required_site_configuration() ) {
			return;
		}

		$user_id = get_current_user_id();

		if ( get_user_meta( $user_id, 'codex_provider_dismiss_link_notice', true ) ) {
			return;
		}

		$connection = \AIProviderForCodex\Auth\ConnectionRepository::get_for_user( $user_id );

		if ( null !== $connection ) {
			return;
		}

		$current_screen = get_current_screen();

		if ( $current_screen && in_array( $current_screen->id, [ 'options-connectors', 'users_page_ai-provider-for-codex', 'settings_page_ai-provider-for-codex' ], true ) ) {
			return;
		}

		printf(
			'<div class="notice notice-info is-dismissible" data-codex-dismiss-notice="link"><p>%s</p></div>',
			wp_kses_post(
				sprintf(
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
		update_user_meta( get_current_user_id(), 'codex_provider_dismiss_link_notice', '1' );
		wp_send_json_success();
	}

	/**
	 * Enqueues the dismiss-notice script on admin pages if the unlinked notice may render.
	 *
	 * @return void
	 */
	public static function maybe_enqueue_dismiss_script(): void {
		if ( ! is_user_logged_in() || ! Settings::has_required_site_configuration() ) {
			return;
		}

		$user_id = get_current_user_id();

		if ( get_user_meta( $user_id, 'codex_provider_dismiss_link_notice', true ) ) {
			return;
		}

		$connection = \AIProviderForCodex\Auth\ConnectionRepository::get_for_user( $user_id );

		if ( null !== $connection ) {
			return;
		}

		wp_add_inline_script(
			'common',
			sprintf(
				'jQuery(function($){$(document).on("click",".notice[data-codex-dismiss-notice] .notice-dismiss",function(){$.post(ajaxurl,{action:"codex_provider_dismiss_notice",nonce:"%s"});});});',
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

		return isset( $_GET['page'] ) && 'options-connectors-wp-admin' === sanitize_key( wp_unslash( $_GET['page'] ) );
	}
}
