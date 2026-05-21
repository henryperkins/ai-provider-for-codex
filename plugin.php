<?php
/**
 * Plugin Name:       AI Provider for Codex
 * Plugin URI:        https://github.com/henryperkins/ai-provider-for-codex
 * Description:       Codex provider for the WordPress AI Client using a local runtime sidecar.
 * Requires at least: 7.0
 * Requires PHP:      7.4
 * Version:           0.1.1
 * Author:            Lakefront Digital
 * License:           GPL-2.0-or-later
 * License URI:       https://spdx.org/licenses/GPL-2.0-or-later.html
 * Text Domain:       ai-provider-for-codex
 *
 * @package AIProviderForCodex
 */

declare( strict_types=1 );

namespace AIProviderForCodex;

use AIProviderForCodex\Auth\ConnectionRefreshScheduler;
use AIProviderForCodex\Database\Installer;
use AIProviderForCodex\Provider\CodexProvider;
use WordPress\AiClient\AiClient;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const VERSION = '0.1.1';
const PLUGIN_URI = 'https://github.com/henryperkins/ai-provider-for-codex';
const MIN_WP_VERSION = '7.0';
const MIN_PHP_VERSION = '7.4';
const PLUGIN_FILE = __FILE__;
const PLUGIN_DIR  = __DIR__ . '/';

require_once PLUGIN_DIR . 'src/autoload.php';

/**
 * Renders a requirement notice.
 *
 * @param string $message Notice body.
 * @return void
 */
function requirement_notice( string $message ): void {
	if ( ! is_admin() ) {
		return;
	}
	?>
	<div class="notice notice-error">
		<p><?php echo wp_kses_post( $message ); ?></p>
	</div>
	<?php
}

/**
 * Checks the current PHP version.
 *
 * @return bool
 */
function check_php_version(): bool {
	if ( version_compare( PHP_VERSION, MIN_PHP_VERSION, '>=' ) ) {
		return true;
	}

	add_action(
		'admin_notices',
		static function () {
			requirement_notice(
				sprintf(
					/* translators: 1: required PHP version, 2: current PHP version */
					__( 'AI Provider for Codex requires PHP %1$s or newer. Current PHP version: %2$s.', 'ai-provider-for-codex' ),
					MIN_PHP_VERSION,
					PHP_VERSION
				)
			);
		}
	);

	return false;
}

/**
 * Checks the current WordPress version.
 *
 * @return bool
 */
function check_wp_version(): bool {
	if ( is_wp_version_compatible( MIN_WP_VERSION ) ) {
		return true;
	}

	add_action(
		'admin_notices',
		static function () {
			global $wp_version;

			requirement_notice(
				sprintf(
					/* translators: 1: required WordPress version, 2: current WordPress version */
					__( 'AI Provider for Codex requires WordPress %1$s or newer. Current WordPress version: %2$s.', 'ai-provider-for-codex' ),
					MIN_WP_VERSION,
					(string) $wp_version
				)
			);
		}
	);

	return false;
}

/**
 * Checks whether the WordPress AI Client is available.
 *
 * @return bool
 */
function check_ai_client(): bool {
	$has_supported_ai_client = class_exists( AiClient::class ) && version_compare( AiClient::VERSION, '1.0', '>=' );
	$has_supported_ai_plugin = ! defined( 'WPAI_VERSION' ) || version_compare( (string) constant( 'WPAI_VERSION' ), '1.0', '>=' );

	if ( $has_supported_ai_client && $has_supported_ai_plugin ) {
		return true;
	}

	add_action(
		'admin_notices',
		static function () {
			$current_client_version = class_exists( AiClient::class ) ? AiClient::VERSION : __( 'not available', 'ai-provider-for-codex' );
			$current_plugin_version = defined( 'WPAI_VERSION' ) ? (string) constant( 'WPAI_VERSION' ) : __( 'not detected', 'ai-provider-for-codex' );

			requirement_notice(
				sprintf(
					/* translators: 1: current AI Client version or availability status, 2: current WordPress AI plugin version or detection status. */
					__( 'AI Provider for Codex requires AI Client 1.0 or newer. If the WordPress AI plugin is installed, it must also be version 1.0 or newer. Current AI Client version: %1$s. Current WordPress AI plugin version: %2$s.', 'ai-provider-for-codex' ),
					$current_client_version,
					$current_plugin_version
				)
			);
		}
	);

	return false;
}

/**
 * Handles plugin activation.
 *
 * @return void
 */
function activate_plugin(): void {
	Installer::activate();
	ConnectionRefreshScheduler::schedule();
}

/**
 * Handles plugin deactivation.
 *
 * @return void
 */
function deactivate_plugin(): void {
	ConnectionRefreshScheduler::unschedule();
}

/**
 * Boots the plugin.
 *
 * @return void
 */
function load(): void {
	static $loaded = false;

	if ( $loaded ) {
		return;
	}

	if ( ! check_php_version() || ! check_wp_version() || ! check_ai_client() ) {
		return;
	}

	$plugin = new Plugin();
	$plugin->init();
	$loaded = true;
}

/**
 * Registers the Codex provider with the WordPress AI Client.
 *
 * @return void
 */
function register_provider(): void {
	if ( ! class_exists( AiClient::class ) ) {
		return;
	}

	if ( ! wp_supports_ai() ) {
		return;
	}

	$registry = AiClient::defaultRegistry();

	if ( $registry->hasProvider( CodexProvider::class ) ) {
		return;
	}

	$registry->registerProvider( CodexProvider::class );
}

register_activation_hook( PLUGIN_FILE, __NAMESPACE__ . '\\activate_plugin' );
register_deactivation_hook( PLUGIN_FILE, __NAMESPACE__ . '\\deactivate_plugin' );
add_action( 'plugins_loaded', __NAMESPACE__ . '\\load' );
