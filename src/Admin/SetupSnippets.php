<?php
/**
 * Read-only setup snippet generator (systemd unit + env file).
 *
 * @package AIProviderForCodex
 */

declare( strict_types=1 );

namespace AIProviderForCodex\Admin;

use AIProviderForCodex\Runtime\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds copy-paste setup snippets tailored to this install. Display only.
 */
final class SetupSnippets {

	private const TOKEN_OPTION       = 'codex_runtime_suggested_bearer_token';
	private const PLACEHOLDER_PATH   = '/path/to/wp-content/plugins/scriptorium-ai-provider-for-codex';

	/**
	 * Returns the bundled systemd unit with the real plugin path substituted.
	 *
	 * @return string
	 */
	public static function systemd_unit(): string {
		$template_path = untrailingslashit( \AIProviderForCodex\PLUGIN_DIR ) . '/sidecar/systemd/codex-wp-sidecar.service';
		$template      = is_readable( $template_path ) ? (string) file_get_contents( $template_path ) : '';

		if ( '' === $template ) {
			return '';
		}

		return str_replace( self::PLACEHOLDER_PATH, untrailingslashit( \AIProviderForCodex\PLUGIN_DIR ), $template );
	}

	/**
	 * Returns an env-file snippet with detected values and a stable suggested token.
	 *
	 * @return string
	 */
	public static function env_file(): string {
		$base_url = Settings::get_base_url();

		if ( '' === $base_url ) {
			$base_url = Settings::DEFAULT_RUNTIME_BASE_URL;
		}

		$lines = [
			'CODEX_BIN=/usr/local/bin/codex',
			'CODEX_WP_STORAGE_ROOT=/var/lib/codex-wp',
			'CODEX_WP_HOST=127.0.0.1',
			'CODEX_WP_PORT=4317',
			'CODEX_WP_RUNTIME_BASE_URL=' . $base_url,
			'CODEX_WP_BEARER_TOKEN=' . self::suggested_bearer_token(),
			'CODEX_RUNTIME_REQUEST_TIMEOUT=60',
			'CODEX_RUNTIME_TURN_TIMEOUT=300',
			'CODEX_RUNTIME_LOGIN_TIMEOUT=1800',
		];

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * Returns a stable bearer token to show in the snippet.
	 *
	 * Uses the configured token when present; otherwise caches a generated one.
	 *
	 * @return string
	 */
	public static function suggested_bearer_token(): string {
		$configured = Settings::get_bearer_token();

		if ( '' !== $configured ) {
			return $configured;
		}

		$cached = (string) get_option( self::TOKEN_OPTION, '' );

		if ( '' !== $cached ) {
			return $cached;
		}

		$token = wp_generate_password( 64, false );

		// add_option() returns false if a concurrent request stored a token first;
		// in that case return the persisted value so every caller agrees on the
		// same token instead of a freshly generated one that was never saved.
		if ( ! add_option( self::TOKEN_OPTION, $token, '', false ) ) {
			$stored = (string) get_option( self::TOKEN_OPTION, '' );

			if ( '' !== $stored ) {
				return $stored;
			}
		}

		return $token;
	}
}
