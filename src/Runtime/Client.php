<?php
/**
 * Local runtime HTTP client.
 *
 * @package AIProviderForCodex
 */

declare( strict_types=1 );

namespace AIProviderForCodex\Runtime;

use RuntimeException;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;

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
	 * @param RequestOptions|null $options Per-request SDK HTTP options.
	 * @return array<string,mixed>
	 */
	public function post( string $path, array $body = [], ?RequestOptions $options = null ): array {
		return $this->request( 'POST', $path, $body, [], true, $options );
	}

	/**
	 * Runs the sidecar's read-only diagnostics without touching the health cache.
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
	 * @param bool $record_health Whether to update the health cache.
	 * @param RequestOptions|null $options Per-request SDK HTTP options.
	 * @return array<string,mixed>
	 */
	public function request( string $method, string $path, array $body = [], array $query = [], bool $record_health = true, ?RequestOptions $options = null ): array {
		$method   = strtoupper( $method );
		$path     = '/' . ltrim( $path, '/' );
		$base_url = Settings::get_base_url();

		if ( '' === $base_url ) {
			throw self::runtime_exception( esc_html__( 'The Codex runtime URL is not configured.', 'scriptorium-ai-provider-for-codex' ) );
		}

		if ( ! Settings::has_required_configuration() ) {
			throw self::runtime_exception( esc_html__( 'The local Codex runtime settings are incomplete.', 'scriptorium-ai-provider-for-codex' ) );
		}

		$body_json = [] === $body ? '' : wp_json_encode( $body );

		if ( false === $body_json ) {
			throw self::runtime_exception( esc_html__( 'The runtime request body could not be encoded as JSON.', 'scriptorium-ai-provider-for-codex' ) );
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

		$timeout = null !== $options && null !== $options->getTimeout()
			? $options->getTimeout()
			: $this->request_timeout( $method, $path, $url );

		$args = [
			'method'  => $method,
			'headers' => $headers,
			'timeout' => $timeout,
		];
		if ( null !== $options && null !== $options->getMaxRedirects() ) {
			$args['redirection'] = $options->getMaxRedirects();
		}

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
			throw new RuntimeRequestException(
				esc_html( sanitize_text_field( $message ) ),
				0,
				sanitize_key( (string) $response->get_error_code() ),
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Raw cause is structured exception data, escaped by the rendering consumer.
				$response->get_error_message()
			);
		}

		return $this->process_response(
			(int) wp_remote_retrieve_response_code( $response ),
			(string) wp_remote_retrieve_body( $response ),
			(string) wp_remote_retrieve_response_message( $response ),
			$url,
			$record_health
		);
	}

	/**
	 * Parses the runtime response body and converts non-2xx responses into structured exceptions.
	 *
	 * The sidecar answers every request with JSON and reports its own failures in an
	 * `error` envelope. A non-JSON error body, or a 5xx without that envelope, was
	 * therefore produced by a proxy, tunnel, or gateway in front of the sidecar.
	 *
	 * @param int    $status_code HTTP status code.
	 * @param string $raw_body Raw response body.
	 * @param string $fallback_status_message Reason phrase to fall back to when the runtime returns no error message.
	 * @param string $url Request URL.
	 * @param bool   $record_health Whether to update the health cache.
	 * @return array<string,mixed>
	 */
	private function process_response( int $status_code, string $raw_body, string $fallback_status_message, string $url, bool $record_health = true ): array {
		$payload = [];

		if ( '' !== $raw_body ) {
			$payload = json_decode( $raw_body, true );

			if ( JSON_ERROR_NONE !== json_last_error() ) {
				if ( $status_code >= 400 ) {
					throw self::proxy_response_exception( $status_code, $fallback_status_message, $url, $record_health );
				}

				if ( $record_health ) {
					HealthMonitor::record_failure( __( 'The local Codex runtime returned invalid JSON.', 'scriptorium-ai-provider-for-codex' ) );
				}
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Message is escaped and the raw response body is excluded.
				throw new RuntimeRequestException(
					esc_html__( 'The local Codex runtime returned invalid JSON.', 'scriptorium-ai-provider-for-codex' ),
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- HTTP status is internal integer exception metadata, not rendered output.
					$status_code,
					'invalid_json',
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- JSON parser cause is structured exception data; the raw response body is excluded.
					json_last_error_msg()
				);
			}
		}

		if ( $status_code >= 400 ) {
			$runtime_error = is_array( $payload ) && is_array( $payload['error'] ?? null ) ? $payload['error'] : [];

			if ( $status_code >= 500 && ! isset( $runtime_error['code'] ) && ! isset( $runtime_error['message'] ) ) {
				throw self::proxy_response_exception( $status_code, $fallback_status_message, $url, $record_health, is_array( $payload ) ? $payload : [] );
			}

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
	 * Creates the exception for an HTTP error that did not come from the sidecar.
	 *
	 * @param int                 $status_code HTTP status code.
	 * @param string              $reason_phrase HTTP reason phrase, when known.
	 * @param string              $url Request URL.
	 * @param bool                $record_health Whether to update the health cache.
	 * @param array<string,mixed> $payload Decoded JSON body, when the body was JSON.
	 * @return RuntimeRequestException
	 */
	private static function proxy_response_exception( int $status_code, string $reason_phrase, string $url, bool $record_health, array $payload = [] ): RuntimeRequestException {
		$message = self::proxy_response_message( $status_code, $reason_phrase, $url );

		if ( $record_health ) {
			HealthMonitor::record_failure( $message );
		}

		// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Message is escaped; status, code, and reason phrase are structured exception data and the raw body is excluded.
		return new RuntimeRequestException(
			esc_html( sanitize_text_field( $message ) ),
			$status_code,
			RuntimeRequestException::PROXY_RESPONSE,
			'' !== $reason_phrase ? $reason_phrase : 'HTTP ' . $status_code,
			$payload
		);
		// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	}

	/**
	 * Describes an HTTP error that a proxy, tunnel, or gateway returned instead of the sidecar.
	 *
	 * @param int    $status_code HTTP status code.
	 * @param string $reason_phrase HTTP reason phrase, when known.
	 * @param string $url Request URL.
	 * @return string
	 */
	private static function proxy_response_message( int $status_code, string $reason_phrase, string $url ): string {
		$status = '' !== $reason_phrase ? $status_code . ' ' . $reason_phrase : (string) $status_code;
		$target = self::describe_target( $url );

		if ( 524 === $status_code ) {
			return sprintf(
				/* translators: 1: runtime host and port, 2: HTTP status code and reason phrase. */
				__( 'Cloudflare stopped waiting for the Codex runtime at %1$s and returned HTTP %2$s before the sidecar responded. Cloudflare stops waiting after 125 seconds by default, so a longer generation cannot finish through that hostname.', 'scriptorium-ai-provider-for-codex' ),
				$target,
				$status
			);
		}

		if ( 504 === $status_code ) {
			return sprintf(
				/* translators: 1: runtime host and port, 2: HTTP status code and reason phrase. */
				__( 'A proxy or gateway in front of the Codex runtime at %1$s timed out and returned HTTP %2$s before the sidecar responded. Every proxy timeout between WordPress and the sidecar must allow the generation to finish.', 'scriptorium-ai-provider-for-codex' ),
				$target,
				$status
			);
		}

		if ( $status_code >= 500 ) {
			return sprintf(
				/* translators: 1: runtime host and port, 2: HTTP status code and reason phrase. */
				__( 'A proxy, tunnel, or gateway in front of the Codex runtime at %1$s returned HTTP %2$s instead of a sidecar response, so the request did not reach a working sidecar. Make sure the sidecar, and any gateway or tunnel in front of it, is running.', 'scriptorium-ai-provider-for-codex' ),
				$target,
				$status
			);
		}

		return sprintf(
			/* translators: 1: runtime host and port, 2: HTTP status code and reason phrase. */
			__( 'The Codex runtime URL at %1$s returned HTTP %2$s with a non-JSON body, so something other than the sidecar answered. Check that the Runtime URL points at the sidecar and that any proxy or gateway in front of it allows the request.', 'scriptorium-ai-provider-for-codex' ),
			$target,
			$status
		);
	}

	/**
	 * Returns the host and explicit port of a runtime URL for error messages.
	 *
	 * @param string $url Request URL.
	 * @return string
	 */
	private static function describe_target( string $url ): string {
		$host   = (string) wp_parse_url( $url, PHP_URL_HOST );
		$port   = (int) wp_parse_url( $url, PHP_URL_PORT );
		$target = '' !== $host ? $host : $url;

		if ( $port > 0 ) {
			$target .= ':' . $port;
		}

		return $target;
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
	 * @param float     $timeout Timeout in seconds.
	 * @return string
	 */
	public static function normalize_transport_error_message( \WP_Error $error, string $url, float $timeout ): string {
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
				__( 'WordPress AI Connector Approval blocked the %1$s local-runtime request for %2$s. Open Tools > Connector Approvals and approve that caller for the Codex connector, or deactivate the Connector Approval experiment.', 'scriptorium-ai-provider-for-codex' ),
				$connector_id,
				$caller
			);
		}

		return sprintf(
			/* translators: %s: connector ID. */
			__( 'WordPress AI Connector Approval blocked the %s local-runtime request. Open Tools > Connector Approvals and approve the pending caller for the Codex connector, or deactivate the Connector Approval experiment.', 'scriptorium-ai-provider-for-codex' ),
			$connector_id
		);
	}

	/**
	 * Maps a raw transport error message to a clearer runtime message.
	 *
	 * @param string $message Raw error message from the transport layer.
	 * @param string $url Request URL.
	 * @param float  $timeout Timeout in seconds.
	 * @return string
	 */
	private static function normalize_transport_error_string( string $message, string $url, float $timeout ): string {
		$lower  = strtolower( $message );
		$target = self::describe_target( $url );

		if (
			self::string_contains( $lower, 'curl error 7' )
			|| self::string_contains( $lower, 'failed to connect to' )
			|| self::string_contains( $lower, 'couldn\'t connect to server' )
			|| self::string_contains( $lower, 'connection refused' )
		) {
			return sprintf(
				/* translators: %s: runtime host and port. */
				__(
					'The local Codex runtime is not reachable at %s. Start the sidecar service there, or update the Runtime URL if the service is listening on a different address.',
					'scriptorium-ai-provider-for-codex'
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
					'The local Codex runtime request timed out after %1$s seconds while contacting %2$s. Increase the runtime request timeout if longer generations are expected.',
					'scriptorium-ai-provider-for-codex'
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
				'The local Codex runtime no longer has a stored ChatGPT or Codex login for your WordPress account. Reconnect your account to refresh billing and model access.',
				'scriptorium-ai-provider-for-codex'
			);
		}

		if ( in_array( $status_code, [ 401, 403 ], true ) ) {
			if (
				self::string_contains( $lower, 'invalid bearer token' )
				|| self::string_contains( $lower, 'missing bearer token' )
			) {
				return __(
					'The local Codex runtime rejected the shared bearer token. Make sure Settings > Codex Provider uses the same raw token value as CODEX_WP_BEARER_TOKEN in the sidecar, and paste only the token itself instead of a full Authorization header.',
					'scriptorium-ai-provider-for-codex'
				);
			}

			if ( self::string_contains( $lower, 'bearer token is not configured' ) ) {
				return __(
					'The local Codex runtime is missing its shared bearer token. Set CODEX_WP_BEARER_TOKEN for the sidecar and the matching Runtime bearer token in WordPress.',
					'scriptorium-ai-provider-for-codex'
				);
			}

			if ( self::string_contains( $lower, 'only accepts local connections' ) ) {
				return __(
					'The local Codex runtime only accepts requests from the same host. Run the sidecar on the WordPress host or point the Runtime URL at a local address.',
					'scriptorium-ai-provider-for-codex'
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
