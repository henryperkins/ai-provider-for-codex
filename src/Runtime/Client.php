<?php
/**
 * Local runtime HTTP client.
 *
 * @package HtperkinsAIProviderForCodex
 */

declare( strict_types=1 );

namespace Htperkins\AIProviderForCodex\Runtime;

use RuntimeException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Performs authenticated runtime requests over the WordPress HTTP API.
 */
final class Client {

	public const CONNECTOR_APPROVAL_ERROR_CODE = 'wpai_connector_not_approved';

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
	 * Runs the runtime's read-only diagnostics without touching the health cache.
	 *
	 * @return array<string,mixed>
	 */
	public function diagnostics(): array {
		return $this->request( 'GET', '/v1/diagnostics', [], [], false );
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
	public function request( string $method, string $path, array $body = [], array $query = [], bool $record_health = true ): array {
		$method   = strtoupper( $method );
		$path     = '/' . ltrim( $path, '/' );
		$base_url = Settings::get_base_url();

		if ( '' === $base_url ) {
			throw self::runtime_exception( esc_html__( 'The Codex runtime URL is not configured.', 'ai-provider-for-codex' ) );
		}

		if ( ! Settings::has_required_configuration() ) {
			throw self::runtime_exception( esc_html__( 'The local Codex runtime settings are incomplete.', 'ai-provider-for-codex' ) );
		}

		if ( AppServerClient::supports_url( $base_url ) ) {
			return $this->request_app_server( $method, $path, $body, $record_health );
		}

		$body_json = [] === $body ? '' : wp_json_encode( $body );

		if ( false === $body_json ) {
			throw self::runtime_exception( esc_html__( 'The runtime request body could not be encoded as JSON.', 'ai-provider-for-codex' ) );
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
			if ( $record_health ) {
				if ( self::is_connector_approval_error( $response ) ) {
					HealthMonitor::record_connector_unapproved( $message );
				} else {
					HealthMonitor::record_failure( $message );
				}
			}
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Transport message is escaped when rendered.
			throw new RuntimeException( $message );
		}

		return $this->process_response(
			(int) wp_remote_retrieve_response_code( $response ),
			(string) wp_remote_retrieve_body( $response ),
			(string) wp_remote_retrieve_response_message( $response ),
			$record_health
		);
	}

	/**
	 * Parses the runtime response body and converts non-2xx responses into structured exceptions.
	 *
	 * @param int    $status_code HTTP status code.
	 * @param string $raw_body Raw response body.
	 * @param string $fallback_status_message Reason phrase to fall back to when the runtime returns no error message.
	 * @return array<string,mixed>
	 */
	private function process_response( int $status_code, string $raw_body, string $fallback_status_message, bool $record_health = true ): array {
		$payload = [];

		if ( '' !== $raw_body ) {
			$payload = json_decode( $raw_body, true );

			if ( JSON_ERROR_NONE !== json_last_error() ) {
				if ( $record_health ) {
					HealthMonitor::record_failure( __( 'The local Codex runtime returned invalid JSON.', 'ai-provider-for-codex' ) );
				}
				throw self::runtime_exception( esc_html__( 'The local Codex runtime returned invalid JSON.', 'ai-provider-for-codex' ) );
			}
		}

		if ( $status_code >= 400 ) {
			$runtime_error_code = sanitize_key( (string) ( $payload['error']['code'] ?? '' ) );
			$runtime_message    = (string) ( $payload['error']['message'] ?? $fallback_status_message );
			$message            = self::normalize_runtime_error_message(
				$status_code,
				$runtime_message,
				$runtime_error_code
			);

			if ( $record_health && ( $status_code >= 500 || in_array( $status_code, [ 401, 403 ], true ) ) ) {
				HealthMonitor::record_failure( (string) $message );
			}

			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Runtime exception payload is rendered through escaped admin notices, not direct output.
			throw new RuntimeRequestException(
				esc_html( sanitize_text_field( (string) $message ) ),
				$status_code,
				$runtime_error_code,
				$runtime_message,
				is_array( $payload ) ? $payload : []
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		if ( $record_health ) {
			HealthMonitor::record_success();
		}

		return is_array( $payload ) ? $payload : [];
	}

	/**
	 * Routes legacy runtime paths to a direct Codex app-server session.
	 *
	 * @param string              $method HTTP-like method.
	 * @param string              $path Runtime path.
	 * @param array<string,mixed> $body Request body.
	 * @param bool                $record_health Whether to update health cache.
	 * @return array<string,mixed>
	 */
	private function request_app_server( string $method, string $path, array $body, bool $record_health ): array {
		$app_server = new AppServerClient();

		try {
			if ( 'GET' === $method && in_array( $path, [ '/healthz', '/ping' ], true ) ) {
				$response = $app_server->health();
			} elseif ( 'GET' === $method && '/v1/diagnostics' === $path ) {
				$response = $app_server->diagnostics();
			} elseif ( 'GET' === $method && '/v1/account/snapshot' === $path ) {
				$response = $app_server->account_snapshot();
			} elseif ( 'POST' === $method && '/v1/responses/text' === $path ) {
				$response = $app_server->generate_text( $body );
			} elseif ( 'POST' === $method && '/v1/responses/image' === $path ) {
				$response = $app_server->generate_image( $body );
			} elseif ( 'POST' === $method && '/v1/session/clear' === $path ) {
				$response = [ 'ok' => true ];
			} else {
				throw self::runtime_exception( sprintf( 'Unsupported Codex app-server runtime path: %s %s', $method, $path ) );
			}
		} catch ( RuntimeRequestException $exception ) {
			throw $exception;
		} catch ( RuntimeException $exception ) {
			$message        = $exception->getMessage();
			$is_unreachable = self::is_app_server_transport_failure( $message );
			$error_code     = $is_unreachable ? 'app_server_unreachable' : 'app_server_error';

			// Only transport/connection failures mean the runtime is unreachable.
			// Expected domain errors (missing input, missing account auth, image
			// capability denial) come back from a runtime that answered, so they
			// must not poison the health cache.
			if ( $record_health && $is_unreachable ) {
				HealthMonitor::record_failure( $message );
			}

			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- App-server exception payload is rendered through escaped admin notices, not direct output.
			throw new RuntimeRequestException(
				esc_html( sanitize_text_field( $message ) ),
				$is_unreachable ? 503 : 400,
				$error_code,
				$message,
				[
					'error' => [
						'code'    => $error_code,
						'message' => $message,
					],
				]
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		if ( $record_health ) {
			HealthMonitor::record_success();
		}

		return $response;
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
		$timeout = 0 === strpos( $path, '/v1/responses/' )
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
			'htperkins_aipfc_runtime_request_timeout',
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
		if ( self::is_connector_approval_error( $error ) ) {
			return self::normalize_connector_approval_error_message( $error );
		}

		return self::normalize_transport_error_string( $error->get_error_message(), $url, $timeout );
	}

	/**
	 * Returns whether a transport error came from WordPress AI Connector Approval.
	 *
	 * @param \WP_Error $error Transport error.
	 * @return bool
	 */
	public static function is_connector_approval_error( \WP_Error $error ): bool {
		return self::CONNECTOR_APPROVAL_ERROR_CODE === $error->get_error_code();
	}

	/**
	 * Converts WordPress AI Connector Approval blocks into an actionable runtime message.
	 *
	 * @param \WP_Error $error Connector Approval error.
	 * @return string
	 */
	private static function normalize_connector_approval_error_message( \WP_Error $error ): string {
		$data         = $error->get_error_data();
		$connector_id = is_array( $data ) ? sanitize_key( (string) ( $data['connector_id'] ?? 'codex' ) ) : 'codex';
		$caller       = is_array( $data ) && isset( $data['caller'] ) && is_array( $data['caller'] )
			? (string) ( $data['caller']['basename'] ?? '' )
			: '';

		if ( '' === $connector_id ) {
			$connector_id = 'codex';
		}

		if ( '' !== $caller ) {
			return sprintf(
				/* translators: 1: connector ID, 2: plugin or theme basename. */
				__( 'WordPress AI Connector Approval blocked the %1$s local-runtime request for %2$s. Open Tools > Connector Approvals and approve that caller for the Codex connector, or deactivate the Connector Approval experiment.', 'ai-provider-for-codex' ),
				$connector_id,
				$caller
			);
		}

		return sprintf(
			/* translators: %s: connector ID. */
			__( 'WordPress AI Connector Approval blocked the %s local-runtime request. Open Tools > Connector Approvals and approve the pending caller for the Codex connector, or deactivate the Connector Approval experiment.', 'ai-provider-for-codex' ),
			$connector_id
		);
	}

	/**
	 * Maps a raw transport error message to a clearer runtime message.
	 *
	 * @param string $message Raw error message from the transport layer.
	 * @param string $url Request URL.
	 * @param int    $timeout Timeout in seconds.
	 * @return string
	 */
	private static function normalize_transport_error_string( string $message, string $url, int $timeout ): string {
		$lower  = strtolower( $message );
		$host   = (string) wp_parse_url( $url, PHP_URL_HOST );
		$port   = (int) wp_parse_url( $url, PHP_URL_PORT );
		$target = '' !== $host ? $host : $url;

		if ( $port > 0 ) {
			$target .= ':' . $port;
		}

		if (
			self::string_contains( $lower, 'curl error 7' )
			|| self::string_contains( $lower, 'failed to connect to' )
			|| self::string_contains( $lower, 'couldn\'t connect to server' )
			|| self::string_contains( $lower, 'connection refused' )
		) {
			return sprintf(
				/* translators: %s: runtime host and port. */
				__(
					'The local Codex runtime is not reachable at %s. Start codex app-server there, or update the Runtime URL if the service is listening on a different address.',
					'ai-provider-for-codex'
				),
				$target
			);
		}

		if (
			self::string_contains( $lower, 'curl error 28' )
			|| self::string_contains( $lower, 'operation timed out' )
			|| self::string_contains( $lower, 'timed out' )
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

	/**
	 * Converts runtime HTTP errors into clearer admin-facing messages.
	 *
	 * @param int    $status_code HTTP status code.
	 * @param string $message Runtime error message.
	 * @param string $runtime_error_code Runtime error code.
	 * @return string
	 */
	private static function normalize_runtime_error_message( int $status_code, string $message, string $runtime_error_code = '' ): string {
		$lower              = strtolower( $message );
		$runtime_error_code = sanitize_key( $runtime_error_code );

		if ( 'auth_required' === $runtime_error_code ) {
			return __(
				'The local Codex runtime does not have stored ChatGPT or Codex auth. Run codex login for the service user that starts app-server, then restart the service.',
				'ai-provider-for-codex'
			);
		}

		if ( in_array( $status_code, [ 401, 403 ], true ) ) {
			if (
				self::string_contains( $lower, 'invalid bearer token' )
				|| self::string_contains( $lower, 'missing bearer token' )
			) {
				return __(
					'The local Codex runtime rejected the shared bearer token. If app-server is started with WebSocket auth, make sure Settings > Codex Provider uses the same raw token value as CODEX_WP_BEARER_TOKEN, and paste only the token itself instead of a full Authorization header.',
					'ai-provider-for-codex'
				);
			}

			if ( self::string_contains( $lower, 'bearer token is not configured' ) ) {
				return __(
					'The local Codex runtime is missing its shared bearer token. Set CODEX_WP_BEARER_TOKEN for app-server and the matching Runtime bearer token in WordPress, or remove the token from WordPress if app-server is not using WebSocket auth.',
					'ai-provider-for-codex'
				);
			}

			if ( self::string_contains( $lower, 'only accepts local connections' ) ) {
				return __(
					'The local Codex runtime only accepts requests from the same host. Run codex app-server on the WordPress host or point the Runtime URL at a local address.',
					'ai-provider-for-codex'
				);
			}
		}

		return $message;
	}

	/**
	 * Returns whether a string contains a substring on PHP 7.4+.
	 *
	 * @param string $haystack String to inspect.
	 * @param string $needle Substring to find.
	 * @return bool
	 */
	private static function string_contains( string $haystack, string $needle ): bool {
		return '' === $needle || false !== strpos( $haystack, $needle );
	}

	/**
	 * Returns whether an app-server RuntimeException is a transport/connection failure.
	 *
	 * These map to AppServerConnection socket, handshake, and timeout errors and
	 * mean the runtime is unreachable. Everything else is an expected domain
	 * error from a runtime that answered.
	 *
	 * @param string $message Exception message.
	 * @return bool
	 */
	private static function is_app_server_transport_failure( string $message ): bool {
		$lower = strtolower( $message );

		foreach ( [
			'could not connect',
			'could not read',
			'could not write',
			'socket',
			'handshake',
			'timed out',
			'closed the websocket',
		] as $needle ) {
			if ( self::string_contains( $lower, $needle ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Creates a runtime exception without tripping output sniffs.
	 *
	 * @param string $message Plain-text exception message.
	 * @return RuntimeException
	 */
	private static function runtime_exception( string $message ): RuntimeException {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are escaped at the render boundary.
		return new RuntimeException( $message );
	}
}
