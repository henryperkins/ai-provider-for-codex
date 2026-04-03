<?php
/**
 * Local connection REST endpoints.
 *
 * @package AIProviderForCodex
 */

declare( strict_types=1 );

namespace AIProviderForCodex\REST;

use AIProviderForCodex\Admin\UserConnectionPage;
use AIProviderForCodex\Auth\ConnectionService;
use RuntimeException;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Exposes local connect/disconnect endpoints for future admin UI work.
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
				'args'                => [
					'returnUrl' => [
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'esc_url_raw',
						'validate_callback' => [ self::class, 'validate_return_url' ],
					],
				],
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
	 * Starts the broker connect flow.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public static function start_connect( WP_REST_Request $request ): WP_REST_Response {
		try {
			$service = new ConnectionService();
			$url     = $service->start_connect(
				get_current_user_id(),
				(string) $request->get_param( 'returnUrl' ) ?: UserConnectionPage::page_url()
			);

			return new WP_REST_Response(
				[
					'connectUrl' => $url,
				]
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

	/**
	 * Validates an optional return URL.
	 *
	 * @param mixed           $value Parameter value.
	 * @param WP_REST_Request $request REST request.
	 * @param string          $param Parameter name.
	 * @return bool
	 */
	public static function validate_return_url( $value, WP_REST_Request $request, string $param ): bool {
		unset( $request, $param );

		if ( null === $value || '' === $value ) {
			return true;
		}

		return false !== wp_http_validate_url( (string) $value );
	}

	/**
	 * Disconnects the current user locally.
	 *
	 * @return WP_REST_Response
	 */
	public static function disconnect(): WP_REST_Response {
		$service = new ConnectionService();
		$service->disconnect( get_current_user_id() );

		return new WP_REST_Response(
			[
				'status' => 'disconnected',
			]
		);
	}

	/**
	 * Refreshes the current user's broker snapshot.
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
				]
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
