<?php
/**
 * Read-only setup snippet generator (systemd unit + env file).
 *
 * @package HtperkinsAIProviderForCodex
 */

declare( strict_types=1 );

namespace Htperkins\AIProviderForCodex\Admin;

use Htperkins\AIProviderForCodex\Runtime\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds copy-paste setup snippets tailored to this install. Display only.
 */
final class SetupSnippets {

	private const TOKEN_OPTION = 'htperkins_aipfc_suggested_bearer_token';

	/**
	 * Returns a systemd unit template for an externally installed Codex app-server.
	 *
	 * @return string
	 */
	public static function systemd_unit(): string {
		$runtime_config  = Settings::configuration_metadata();
		$shared_env_file = (string) $runtime_config['shared_env_file'];

		return implode(
			"\n",
			[
				'[Unit]',
				'Description=Codex app-server for WordPress AI',
				'After=network-online.target',
				'Wants=network-online.target',
				'',
				'[Service]',
				'Type=simple',
				'User=www-data',
				'Group=www-data',
				'EnvironmentFile=' . $shared_env_file,
				'ExecStart=/usr/local/bin/codex app-server --listen ${CODEX_WP_RUNTIME_BASE_URL}',
				'Restart=on-failure',
				'RestartSec=5',
				'NoNewPrivileges=true',
				'PrivateTmp=true',
				'',
				'[Install]',
				'WantedBy=multi-user.target',
				'',
			]
		);
	}

	/**
	 * Returns an env-file snippet with detected values.
	 *
	 * @return string
	 */
	public static function env_file(): string {
		$base_url = Settings::get_base_url();

		if ( '' === $base_url ) {
			$base_url = Settings::DEFAULT_RUNTIME_BASE_URL;
		}

		$lines = [
			'CODEX_HOME=/var/lib/codex-app-server',
			'CODEX_WP_RUNTIME_BASE_URL=' . $base_url,
			'# Optional: set CODEX_WP_BEARER_TOKEN only when app-server is started with WebSocket auth.',
			'# CODEX_WP_BEARER_TOKEN=' . self::suggested_bearer_token(),
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
