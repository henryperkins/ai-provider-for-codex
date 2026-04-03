<?php
/**
 * Local runtime connection REST endpoints.
 *
 * @package AIProviderForCodex
 */

declare( strict_types=1 );

namespace AIProviderForCodex\REST;

use AIProviderForCodex\Auth\ConnectionService;
use AIProviderForCodex\Provider\ModelCatalogState;
use RuntimeException;
use WP_REST_Response;

/**
 * Exposes local connect, poll, disconnect, and refresh endpoints.
 */
final class ConnectController {

	/**
	 * Registers routes.
	 *
	 * @return void
	 */
	public static function register_routes(): void {
		register_rest_route(
			'codex-provider/v1',
			'/connect/start',
			[
				'methods'             => 'POST',
				'permission_callback' => [ self::class, 'can_manage_connection' ],
				'callback'            => [ self::class, 'start_connect' ],
			]
		);

		register_rest_route(
			'codex-provider/v1',
			'/connect/status',
			[
				'methods'             => 'GET',
				'permission_callback' => [ self::class, 'can_manage_connection' ],
				'callback'            => [ self::class, 'status' ],
			]
		);

		register_rest_route(
			'codex-provider/v1',
			'/connect/disconnect',
			[
				'methods'             => 'POST',
				'permission_callback' => [ self::class, 'can_manage_connection' ],
				'callback'            => [ self::class, 'disconnect' ],
			]
		);

		register_rest_route(
			'codex-provider/v1',
			'/connect/refresh',
			[
				'methods'             => 'POST',
				'permission_callback' => [ self::class, 'can_manage_connection' ],
				'callback'            => [ self::class, 'refresh' ],
			]
		);
	}

	/**
	 * Ensures the current user is logged in.
	 *
	 * @return bool
	 */
	public static function can_manage_connection(): bool {
		return is_user_logged_in() && current_user_can( 'read' );
	}

	/**
	 * Starts the local runtime connect flow.
	 *
	 * @return WP_REST_Response
	 */
	public static function start_connect(): WP_REST_Response {
		try {
			$service = new ConnectionService();
			$data    = $service->start_connect( get_current_user_id() );

			return new WP_REST_Response( $data );
		} catch ( RuntimeException $exception ) {
			return new WP_REST_Response(
				[
					'error' => [
						'message' => $exception->getMessage(),
					],
				],
				400
			);
		}
	}

	/**
	 * Returns the current connect status.
	 *
	 * @return WP_REST_Response
	 */
	public static function status(): WP_REST_Response {
		try {
			$service = new ConnectionService();
			$status  = $service->poll_connect_status( get_current_user_id() );

			if ( 'connected' === (string) ( $status['status'] ?? '' ) ) {
				$status['catalog'] = ModelCatalogState::get_effective_catalog( get_current_user_id() );
			}

			return new WP_REST_Response( $status );
		} catch ( RuntimeException $exception ) {
			return new WP_REST_Response(
				[
					'error' => [
						'message' => $exception->getMessage(),
					],
				],
				400
			);
		}
	}

	/**
	 * Disconnects the current user locally.
	 *
	 * @return WP_REST_Response
	 */
	public static function disconnect(): WP_REST_Response {
		$service = new ConnectionService();
		$service->disconnect( get_current_user_id() );
		ModelCatalogState::delete_user_preferred_model( get_current_user_id() );

		return new WP_REST_Response(
			[
				'status' => 'disconnected',
			]
		);
	}

	/**
	 * Refreshes the current user's local runtime snapshot.
	 *
	 * @return WP_REST_Response
	 */
	public static function refresh(): WP_REST_Response {
		try {
			$service = new ConnectionService();
			$status  = $service->refresh_snapshot( get_current_user_id() );

			return new WP_REST_Response(
				[
					'status' => 'refreshed',
					'data'   => $status,
				],
			);
		} catch ( RuntimeException $exception ) {
			return new WP_REST_Response(
				[
					'error' => [
						'message' => $exception->getMessage(),
					],
				],
				400
			);
		}
	}
}
