<?php
/**
 * Local runtime health cache.
 *
 * @package HtperkinsAIProviderForCodex
 */

declare( strict_types=1 );

namespace Htperkins\AIProviderForCodex\Runtime;

/**
 * Stores the last-known local runtime health state.
 */
final class HealthMonitor {

	private const TRANSIENT_KEY = 'htperkins_aipfc_runtime_health';
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
	 * Records a Connector Approval block without marking the local runtime unreachable.
	 *
	 * @param string $message Approval guidance message.
	 * @return void
	 */
	public static function record_connector_unapproved( string $message ): void {
		set_transient(
			self::TRANSIENT_KEY,
			[
				'status'     => 'connector_unapproved',
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
	 * Returns whether the cached runtime state is healthy, unknown, or approval-blocked.
	 *
	 * Unknown is treated as available so first-run sites are not blocked before
	 * the plugin has made any runtime requests.
	 * Connector Approval blocks are not runtime reachability failures, so callers
	 * should inspect the status reason instead of treating credentials as missing.
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

		// A ws/wss app-server endpoint has no HTTP /healthz route, so probe it
		// with a real WebSocket handshake instead of wp_remote_get().
		if ( Settings::is_app_server_url( $base_url ) ) {
			return self::probe_app_server();
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
			$message = Client::normalize_transport_error_message( $response, $base_url . '/healthz', self::PROBE_TIMEOUT );

			if ( Client::is_connector_approval_error( $response ) ) {
				self::record_connector_unapproved( $message );
				return self::get_status();
			}

			self::record_failure( $message );
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

	/**
	 * Probes a Codex app-server endpoint with a cheap WebSocket handshake.
	 *
	 * @return array{status:string,checked_at:?string,error:string}
	 */
	private static function probe_app_server(): array {
		try {
			( new AppServerClient() )->health();
			self::record_success();
		} catch ( \Throwable $exception ) {
			self::record_failure( $exception->getMessage() );
		}

		return self::get_status();
	}
}
