<?php
/**
 * Scheduled refresh for local Codex account snapshots.
 *
 * @package AIProviderForCodex
 */

declare( strict_types=1 );

namespace AIProviderForCodex\Auth;

use AIProviderForCodex\Runtime\Settings;
use RuntimeException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps stored connection state aligned with the local runtime.
 */
final class ConnectionRefreshScheduler {

	public const HOOK = 'codex_provider_refresh_connection_snapshots';

	/**
	 * Registers WordPress hooks for scheduled snapshot refreshes.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		add_action( self::HOOK, [ self::class, 'refresh_linked_connections' ] );
		add_action( 'init', [ self::class, 'schedule' ], 20 );
	}

	/**
	 * Ensures the recurring refresh event exists.
	 *
	 * @return void
	 */
	public static function schedule(): void {
		if ( false !== wp_next_scheduled( self::HOOK ) ) {
			return;
		}

		wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'hourly', self::HOOK );
	}

	/**
	 * Removes the recurring refresh event.
	 *
	 * @return void
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Refreshes every linked, non-expired local connection.
	 *
	 * @return void
	 */
	public static function refresh_linked_connections(): void {
		if ( ! Settings::has_required_configuration() ) {
			return;
		}

		$service = new ConnectionService();

		foreach ( ConnectionRepository::list_active_for_site_catalog() as $connection ) {
			$wp_user_id    = (int) ( $connection['wp_user_id'] ?? 0 );
			$connection_id = sanitize_text_field( (string) ( $connection['connection_id'] ?? '' ) );

			if ( $wp_user_id <= 0 ) {
				continue;
			}

			try {
				$service->refresh_snapshot( $wp_user_id, '' !== $connection_id ? $connection_id : null );
			} catch ( RuntimeException $exception ) {
				unset( $exception );
				continue;
			}
		}
	}
}
