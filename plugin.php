<?php
/**
 * Plugin Name:       AI Provider for Codex
 * Plugin URI:        https://github.com/henryperkins/wp-hperkins-com/tree/main/wp-content/plugins/ai-provider-for-codex
 * Description:       Broker-backed Codex provider scaffold for the WordPress AI Client.
 * Requires at least: 7.0
 * Requires PHP:      8.0
 * Version:           0.1.0
 * Author:            Lakefront Digital
 * License:           GPL-2.0-or-later
 * License URI:       https://spdx.org/licenses/GPL-2.0-or-later.html
 * Text Domain:       ai-provider-for-codex
 *
 * @package AIProviderForCodex
 */

declare( strict_types=1 );

namespace AIProviderForCodex;

use AIProviderForCodex\Database\Installer;
use WordPress\AiClient\AiClient;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const VERSION = '0.1.0';
const PLUGIN_URI = 'https://github.com/henryperkins/wp-hperkins-com/tree/main/wp-content/plugins/ai-provider-for-codex';
const MIN_WP_VERSION = '7.0';
const MIN_PHP_VERSION = '8.0';
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
	if ( class_exists( AiClient::class ) ) {
		return true;
	}

	add_action(
		'admin_notices',
		static function () {
			requirement_notice(
				__( 'AI Provider for Codex requires the WordPress AI Client available in WordPress 7.0+.', 'ai-provider-for-codex' )
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

register_activation_hook( PLUGIN_FILE, __NAMESPACE__ . '\\activate_plugin' );
add_action( 'plugins_loaded', __NAMESPACE__ . '\\load' );
