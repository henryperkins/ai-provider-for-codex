<?php
/**
 * HMAC signing helpers for broker calls.
 *
 * @package AIProviderForCodex
 */

declare( strict_types=1 );

namespace AIProviderForCodex\Broker;

/**
 * Creates signed broker headers.
 */
final class RequestSigner {

	/**
	 * Builds signed headers for a broker request.
	 *
	 * @param string $method HTTP method.
	 * @param string $path Broker request path.
	 * @param string $body_json JSON request body.
	 * @return array<string,string>
	 */
	public static function build_headers( string $method, string $path, string $body_json ): array {
		$site_id     = Settings::get_site_id();
		$site_secret = Settings::get_site_secret();
		$timestamp   = (string) time();
		$body_hash   = hash( 'sha256', $body_json );
		$signature   = hash_hmac(
			'sha256',
			strtoupper( $method ) . "\n" . $path . "\n" . $timestamp . "\n" . $body_hash,
			$site_secret
		);

		return [
			'X-Codex-Site-Id'  => $site_id,
			'X-Codex-Timestamp' => $timestamp,
			'X-Codex-Signature' => $signature,
		];
	}
}
