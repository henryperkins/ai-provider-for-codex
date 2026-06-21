<?php
/**
 * Local readiness REST endpoint.
 *
 * @package HtperkinsAIProviderForCodex
 */

declare( strict_types=1 );

namespace Htperkins\AIProviderForCodex\REST;

use Htperkins\AIProviderForCodex\Provider\SupportChecks;
use WP_REST_Response;

/**
 * Exposes current user readiness data.
 */
final class StatusController {

	/**
	 * Registers routes.
	 *
	 * @return void
	 */
	public static function register_routes(): void {
		register_rest_route(
			'htperkins-aipfc/v1',
			'/status',
			[
				'methods'             => 'GET',
				'permission_callback' => static function (): bool {
					return is_user_logged_in() && current_user_can( 'read' );
				},
				'callback'            => static function (): WP_REST_Response {
					return new WP_REST_Response( SupportChecks::current_user_status() );
				},
			]
		);
	}
}
