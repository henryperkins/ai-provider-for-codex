<?php
/**
 * Local runtime HTTP client.
 *
 * @package AIProviderForCodex
 */

declare( strict_types=1 );

namespace AIProviderForCodex\Runtime;

use RuntimeException;

/**
 * Performs authenticated runtime requests over the WordPress HTTP API.
 */
final class Client {

	/**
	 * Default timeout for short runtime control-plane requests.
	 */
	private const DEFAULT_TIMEOUT = 20;

	/**
	 * Text generation requests can block while Codex waits for a turn.
	 */
	private const TEXT_GENERATION_TIMEOUT = 360;

	/**
	 * Sends a GET request.
	 *
	 * @param string              $path Relative runtime path.
	 * @param array<string,mixed> $query Query args.
	 * @return array<string,mixed>
	 */
	public function get( string $path, array $query = [] ): array {
		return $this->request( 'GET', $path, [], $query );
	}

	/**
	 * Sends a POST request.
	 *
	 * @param string              $path Relative runtime path.
	 * @param array<string,mixed> $body Request body.
	 * @return array<string,mixed>
	 */
	public function post( string $path, array $body = [] ): array {
		return $this->request( 'POST', $path, $body, [] );
	}

	/**
	 * Sends a runtime request.
	 *
	 * @param string              $method HTTP method.
	 * @param string              $path Relative runtime path.
	 * @param array<string,mixed> $body Request body.
	 * @param array<string,mixed> $query Query args.
	 * @return array<string,mixed>
	 */
	public function request( string $method, string $path, array $body = [], array $query = [] ): array {
		$method   = strtoupper( $method );
		$path     = '/' . ltrim( $path, '/' );
		$base_url = Settings::get_base_url();

		if ( '' === $base_url ) {
			throw new RuntimeException( __( 'The Codex runtime URL is not configured.', 'ai-provider-for-codex' ) );
		}

		if ( ! Settings::has_required_configuration() ) {
			throw new RuntimeException( __( 'The local Codex runtime settings are incomplete.', 'ai-provider-for-codex' ) );
		}

		$body_json = [] === $body ? '' : wp_json_encode( $body );

		if ( false === $body_json ) {
			throw new RuntimeException( __( 'The runtime request body could not be encoded as JSON.', 'ai-provider-for-codex' ) );
		}

		$url = $base_url . $path;

		if ( [] !== $query ) {
			$url = add_query_arg( $query, $url );
		}

		$headers = [
			'Accept'        => 'application/json',
			'Authorization' => 'Bearer ' . Settings::get_bearer_token(),
		];

		if ( 'GET' !== $method ) {
			$headers['Content-Type'] = 'application/json';
		}

		$timeout = $this->request_timeout( $method, $path, $url );

		$args = [
			'method'  => $method,
			'headers' => $headers,
			'timeout' => $timeout,
		];

		if ( '' !== $body_json && 'GET' !== $method ) {
			$args['body'] = $body_json;
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			$message = self::normalize_transport_error_message( $response, $url, $timeout );
			HealthMonitor::record_failure( $message );
			throw new RuntimeException( $message );
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		$raw_body    = (string) wp_remote_retrieve_body( $response );
		$payload     = [];

		if ( '' !== $raw_body ) {
			$payload = json_decode( $raw_body, true );

			if ( JSON_ERROR_NONE !== json_last_error() ) {
				HealthMonitor::record_failure( __( 'The local Codex runtime returned invalid JSON.', 'ai-provider-for-codex' ) );
				throw new RuntimeException( __( 'The local Codex runtime returned invalid JSON.', 'ai-provider-for-codex' ) );
			}
		}

		if ( $status_code >= 400 ) {
			$message = $payload['error']['message'] ?? wp_remote_retrieve_response_message( $response );

			if ( $status_code >= 500 || in_array( $status_code, [ 401, 403 ], true ) ) {
				HealthMonitor::record_failure( (string) $message );
			}

			throw new RuntimeException( sanitize_text_field( (string) $message ) );
		}

		HealthMonitor::record_success();

		return is_array( $payload ) ? $payload : [];
	}

	/**
	 * Returns the timeout to use for a runtime request.
	 *
	 * @param string $method HTTP method.
	 * @param string $path Relative runtime path.
	 * @param string $url Fully qualified request URL.
	 * @return int
	 */
	private function request_timeout( string $method, string $path, string $url ): int {
		$timeout = str_starts_with( $path, '/v1/responses/' )
			? self::TEXT_GENERATION_TIMEOUT
			: self::DEFAULT_TIMEOUT;

		/**
		 * Filters the timeout used for runtime HTTP requests.
		 *
		 * @param int    $timeout Request timeout in seconds.
		 * @param string $method HTTP method.
		 * @param string $path Relative runtime path beginning with `/`.
		 * @param string $url Fully qualified request URL.
		 */
		$timeout = (int) apply_filters(
			'codex_provider_runtime_request_timeout',
			$timeout,
			$method,
			$path,
			$url
		);

		return max( 1, $timeout );
	}

	/**
	 * Converts low-level transport failures into clearer runtime errors.
	 *
	 * @param \WP_Error $error Transport error.
	 * @param string    $url Request URL.
	 * @param int       $timeout Timeout in seconds.
	 * @return string
	 */
	public static function normalize_transport_error_message( \WP_Error $error, string $url, int $timeout ): string {
		$message = $error->get_error_message();
		$lower   = strtolower( $message );
		$host    = (string) parse_url( $url, PHP_URL_HOST );
		$port    = (int) parse_url( $url, PHP_URL_PORT );
		$target  = '' !== $host ? $host : $url;

		if ( $port > 0 ) {
			$target .= ':' . $port;
		}

		if (
			str_contains( $lower, 'curl error 7' )
			|| str_contains( $lower, 'failed to connect to' )
			|| str_contains( $lower, 'couldn\'t connect to server' )
			|| str_contains( $lower, 'connection refused' )
		) {
			return sprintf(
				/* translators: %s: runtime host and port. */
				__(
					'The local Codex runtime is not reachable at %s. Start the sidecar service there, or update the Runtime URL if the service is listening on a different address.',
					'ai-provider-for-codex'
				),
				$target
			);
		}

		if (
			str_contains( $lower, 'curl error 28' )
			|| str_contains( $lower, 'operation timed out' )
			|| str_contains( $lower, 'timed out' )
		) {
			return sprintf(
				/* translators: 1: timeout in seconds, 2: runtime host and port */
				__(
					'The local Codex runtime request timed out after %1$d seconds while contacting %2$s. Increase the runtime request timeout if longer generations are expected.',
					'ai-provider-for-codex'
				),
				$timeout,
				$target
			);
		}

		return $message;
	}
}
