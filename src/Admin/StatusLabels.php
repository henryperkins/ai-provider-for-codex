<?php
/**
 * Human-readable labels for status codes.
 *
 * @package AIProviderForCodex
 */

declare( strict_types=1 );

namespace AIProviderForCodex\Admin;

/**
 * Maps machine-readable status/reason codes to user-friendly labels.
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
			case 'broker_unconfigured':
				return __( 'Broker not configured', 'ai-provider-for-codex' );
			case 'user_unlinked':
				return __( 'Account not linked', 'ai-provider-for-codex' );
			case 'connection_expired':
				return __( 'Connection expired', 'ai-provider-for-codex' );
			case 'broker_unreachable':
				return __( 'Broker unreachable', 'ai-provider-for-codex' );
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
			case 'broker_unconfigured':
				return __( 'Complete site setup first.', 'ai-provider-for-codex' );
			case 'user_unlinked':
				return __( 'Connect your Codex account to start using AI features.', 'ai-provider-for-codex' );
			case 'connection_expired':
				return __( 'Reconnect to restore access.', 'ai-provider-for-codex' );
			case 'broker_unreachable':
				return __( 'Check broker URL and network connectivity.', 'ai-provider-for-codex' );
			default:
				return '';
		}
	}

	/**
	 * Returns a human-readable label for a broker health status.
	 *
	 * @param string $status Health status from HealthMonitor.
	 * @return string
	 */
	public static function broker_health_label( string $status ): string {
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
			case 'site_broker_aggregate':
				return __( 'Auto-refreshed from broker', 'ai-provider-for-codex' );
			case 'site_snapshot_aggregate':
				return __( 'From linked accounts', 'ai-provider-for-codex' );
			case 'settings_fallback':
			default:
				return __( 'Configured defaults (no live data)', 'ai-provider-for-codex' );
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
				return 'warning';
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
