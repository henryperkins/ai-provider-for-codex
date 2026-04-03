<?php
/**
 * Local runtime health cache.
 *
 * @package AIProviderForCodex
 */

declare( strict_types=1 );

namespace AIProviderForCodex\Runtime;

/**
 * Stores the last-known local runtime health state.
 */
final class HealthMonitor {

	private const TRANSIENT_KEY = 'codex_provider_runtime_health';
	private const SUCCESS_TTL   = 10 * MINUTE_IN_SECONDS;
	private const FAILURE_TTL   = 5 * MINUTE_IN_SECONDS;
	private const PROBE_TIMEOUT = 5;

	/**
	 * Records a successful runtime request.
	 *
	 * @return void
	 */
	public static function record_success(): void {
		set_transient(
			self::TRANSIENT_KEY,
			[
				'status'     => 'healthy',
				'checked_at' => gmdate( 'Y-m-d H:i:s' ),
				'error'      => '',
			],
			self::SUCCESS_TTL
		);
	}

	/**
	 * Records a runtime failure.
	 *
	 * @param string $message Failure message.
	 * @return void
	 */
	public static function record_failure( string $message ): void {
		set_transient(
			self::TRANSIENT_KEY,
			[
				'status'     => 'unreachable',
				'checked_at' => gmdate( 'Y-m-d H:i:s' ),
				'error'      => sanitize_text_field( $message ),
			],
			self::FAILURE_TTL
		);
	}

	/**
	 * Returns the last-known local runtime health state.
	 *
	 * @return array{status:string,checked_at:?string,error:string}
	 */
	public static function get_status(): array {
		$health = get_transient( self::TRANSIENT_KEY );

		if ( ! is_array( $health ) ) {
			return [
				'status'     => 'unknown',
				'checked_at' => null,
				'error'      => '',
			];
		}

		return [
			'status'     => sanitize_text_field( (string) ( $health['status'] ?? 'unknown' ) ),
			'checked_at' => ! empty( $health['checked_at'] ) ? (string) $health['checked_at'] : null,
			'error'      => sanitize_text_field( (string) ( $health['error'] ?? '' ) ),
		];
	}

	/**
	 * Returns whether the cached runtime state is healthy or unknown.
	 *
	 * Unknown is treated as available so first-run sites are not blocked before
	 * the plugin has made any runtime requests.
	 *
	 * @return bool
	 */
	public static function is_available(): bool {
		return 'unreachable' !== self::get_status()['status'];
	}

	/**
	 * Probes the runtime health endpoint and updates the cache.
	 *
	 * @return array{status:string,checked_at:?string,error:string}
	 */
	public static function probe(): array {
		$base_url = Settings::get_base_url();

		if ( '' === $base_url ) {
			return self::get_status();
		}

		$response = wp_remote_get(
			$base_url . '/healthz',
			[
				'timeout' => self::PROBE_TIMEOUT,
				'headers' => [
					'Accept' => 'application/json',
				],
			]
		);

		if ( is_wp_error( $response ) ) {
			self::record_failure( Client::normalize_transport_error_message( $response, $base_url . '/healthz', self::PROBE_TIMEOUT ) );
			return self::get_status();
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );

		if ( $status_code >= 400 ) {
			self::record_failure(
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'The local Codex runtime health check returned HTTP %d.', 'ai-provider-for-codex' ),
					$status_code
				)
			);

			return self::get_status();
		}

		self::record_success();

		return self::get_status();
	}
}
