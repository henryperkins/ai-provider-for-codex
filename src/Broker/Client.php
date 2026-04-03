<?php
/**
 * Broker HTTP client.
 *
 * @package AIProviderForCodex
 */

declare( strict_types=1 );

namespace AIProviderForCodex\Broker;

use RuntimeException;

/**
 * Performs signed broker requests over the WordPress HTTP API.
 */
final class Client {

	/**
	 * Sends a GET request.
	 *
	 * @param string               $path Relative broker path.
	 * @param array<string,mixed>  $query Query args.
	 * @param bool                 $signed Whether to add HMAC headers.
	 * @return array<string,mixed>
	 */
	public function get( string $path, array $query = [], bool $signed = true ): array {
		return $this->request( 'GET', $path, [], $query, $signed );
	}

	/**
	 * Sends a POST request.
	 *
	 * @param string               $path Relative broker path.
	 * @param array<string,mixed>  $body Request body.
	 * @param bool                 $signed Whether to add HMAC headers.
	 * @return array<string,mixed>
	 */
	public function post( string $path, array $body = [], bool $signed = true ): array {
		return $this->request( 'POST', $path, $body, [], $signed );
	}

	/**
	 * Sends a broker request.
	 *
	 * @param string               $method HTTP method.
	 * @param string               $path Relative broker path.
	 * @param array<string,mixed>  $body Request body.
	 * @param array<string,mixed>  $query Query args.
	 * @param bool                 $signed Whether to add HMAC headers.
	 * @return array<string,mixed>
	 */
	public function request( string $method, string $path, array $body = [], array $query = [], bool $signed = true ): array {
		$base_url = Settings::get_base_url();

		if ( '' === $base_url ) {
			throw new RuntimeException( __( 'The Codex broker base URL is not configured.', 'ai-provider-for-codex' ) );
		}

		if ( $signed && ! Settings::has_required_site_configuration() ) {
			throw new RuntimeException( __( 'The Codex broker site credentials are incomplete.', 'ai-provider-for-codex' ) );
		}

		$body_json = [] === $body ? '' : wp_json_encode( $body );

		if ( false === $body_json ) {
			throw new RuntimeException( __( 'The broker request body could not be encoded as JSON.', 'ai-provider-for-codex' ) );
		}

		$url = $base_url . '/' . ltrim( $path, '/' );

		if ( [] !== $query ) {
			$url = add_query_arg( $query, $url );
		}

		$headers = [
			'Accept' => 'application/json',
		];

		if ( 'GET' !== strtoupper( $method ) ) {
			$headers['Content-Type'] = 'application/json';
		}

		if ( $signed ) {
			$headers = array_merge( $headers, RequestSigner::build_headers( $method, '/' . ltrim( $path, '/' ), $body_json ) );
		}

		$args = [
			'method'  => strtoupper( $method ),
			'headers' => $headers,
			'timeout' => 20,
		];

		if ( '' !== $body_json && 'GET' !== strtoupper( $method ) ) {
			$args['body'] = $body_json;
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			HealthMonitor::record_failure( $response->get_error_message() );
			throw new RuntimeException( $response->get_error_message() );
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		$raw_body = (string) wp_remote_retrieve_body( $response );
		$payload  = [];

		if ( '' !== $raw_body ) {
			$payload = json_decode( $raw_body, true );

			if ( JSON_ERROR_NONE !== json_last_error() ) {
				HealthMonitor::record_failure( __( 'The broker returned invalid JSON.', 'ai-provider-for-codex' ) );
				throw new RuntimeException( __( 'The broker returned invalid JSON.', 'ai-provider-for-codex' ) );
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
}
