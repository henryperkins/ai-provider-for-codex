<?php
/**
 * Human-readable labels for status codes.
 *
 * @package AIProviderForCodex
 */

declare( strict_types=1 );

namespace AIProviderForCodex\Admin;

/**
 * Maps machine-readable status and reason codes to user-friendly labels.
 */
final class StatusLabels {

	/**
	 * Returns a human-readable label for a readiness reason code.
	 *
	 * @param string $reason Reason code from SupportChecks.
	 * @return string
	 */
	public static function readiness_label( string $reason ): string {
		switch ( $reason ) {
			case 'ready':
				return __( 'Connected and ready', 'ai-provider-for-codex' );
			case 'runtime_unconfigured':
				return __( 'Runtime not configured', 'ai-provider-for-codex' );
			case 'runtime_unreachable':
				return __( 'Runtime unreachable', 'ai-provider-for-codex' );
			case 'user_unlinked':
				return __( 'Account not linked', 'ai-provider-for-codex' );
			case 'connection_expired':
				return __( 'Connection expired', 'ai-provider-for-codex' );
			case 'login_pending':
				return __( 'Login pending', 'ai-provider-for-codex' );
			case 'login_failed':
				return __( 'Login failed', 'ai-provider-for-codex' );
			default:
				return $reason;
		}
	}

	/**
	 * Returns contextual guidance for a readiness reason code.
	 *
	 * @param string $reason Reason code from SupportChecks.
	 * @return string
	 */
	public static function readiness_guidance( string $reason ): string {
		switch ( $reason ) {
			case 'runtime_unconfigured':
				return __( 'Start the bundled local sidecar on the WordPress host, then make sure this site can read the runtime URL and bearer token (or `/etc/codex-wp-sidecar.env`).', 'ai-provider-for-codex' );
			case 'runtime_unreachable':
				return __( 'The sidecar should be running on the same host as WordPress and answering the configured `/healthz` URL.', 'ai-provider-for-codex' );
			case 'user_unlinked':
				return __( 'Once the shared runtime is healthy, connect your Codex account to start using AI features.', 'ai-provider-for-codex' );
			case 'connection_expired':
				return __( 'Reconnect to restore access.', 'ai-provider-for-codex' );
			case 'login_pending':
				return __( 'Open the verification page, enter your device code, then refresh this page.', 'ai-provider-for-codex' );
			case 'login_failed':
				return __( 'The previous device-code login failed. Start the connection again to request a new device code.', 'ai-provider-for-codex' );
			default:
				return '';
		}
	}

	/**
	 * Returns a human-readable label for a runtime health status.
	 *
	 * @param string $status Health status from HealthMonitor.
	 * @return string
	 */
	public static function runtime_health_label( string $status ): string {
		switch ( $status ) {
			case 'healthy':
				return __( 'Healthy', 'ai-provider-for-codex' );
			case 'unreachable':
				return __( 'Unreachable', 'ai-provider-for-codex' );
			case 'unknown':
			default:
				return __( 'Not yet checked', 'ai-provider-for-codex' );
		}
	}

	/**
	 * Returns a human-readable label for a catalog source.
	 *
	 * @param string $source Catalog source identifier.
	 * @return string
	 */
	public static function catalog_source_label( string $source ): string {
		switch ( $source ) {
			case 'user_snapshot':
				return __( 'From your Codex account', 'ai-provider-for-codex' );
			case 'settings_fallback':
			default:
				return __( 'Configured defaults', 'ai-provider-for-codex' );
		}
	}

	/**
	 * Returns a CSS-safe status indicator class name.
	 *
	 * @param string $reason Reason code.
	 * @return string One of 'good', 'warning', 'error'.
	 */
	public static function status_indicator( string $reason ): string {
		switch ( $reason ) {
			case 'ready':
			case 'healthy':
				return 'good';
			case 'connection_expired':
			case 'user_unlinked':
			case 'unknown':
			case 'login_pending':
				return 'warning';
			case 'login_failed':
				return 'error';
			default:
				return 'error';
		}
	}

	/**
	 * Formats a UTC timestamp as a human-readable relative time.
	 *
	 * @param string $utc_timestamp ISO 8601 or MySQL-format UTC timestamp.
	 * @return string Human-readable string like "3 minutes ago".
	 */
	public static function relative_time( string $utc_timestamp ): string {
		if ( '' === $utc_timestamp ) {
			return __( 'Never', 'ai-provider-for-codex' );
		}

		$timestamp = strtotime( $utc_timestamp );

		if ( false === $timestamp ) {
			return $utc_timestamp;
		}

		return human_time_diff( $timestamp, time() ) . ' ' . __( 'ago', 'ai-provider-for-codex' );
	}
}
