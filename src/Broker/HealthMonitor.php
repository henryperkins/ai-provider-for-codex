<?php
/**
 * Broker health cache.
 *
 * @package AIProviderForCodex
 */

declare( strict_types=1 );

namespace AIProviderForCodex\Broker;

/**
 * Stores the last-known broker health state.
 */
final class HealthMonitor {

	private const TRANSIENT_KEY = 'codex_provider_broker_health';
	private const SUCCESS_TTL   = 10 * MINUTE_IN_SECONDS;
	private const FAILURE_TTL   = 5 * MINUTE_IN_SECONDS;

	/**
	 * Records a successful broker request.
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
	 * Records a broker failure.
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
	 * Returns the last-known broker health state.
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
	 * Returns whether the cached broker state is healthy or unknown.
	 *
	 * Unknown is treated as available so first-run sites are not blocked before
	 * the plugin has made any broker requests.
	 *
	 * @return bool
	 */
	public static function is_available(): bool {
		return 'unreachable' !== self::get_status()['status'];
	}
}
