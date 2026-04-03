<?php
/**
 * Broker installation exchange.
 *
 * @package AIProviderForCodex
 */

declare( strict_types=1 );

namespace AIProviderForCodex\Broker;

use RuntimeException;

/**
 * Exchanges a one-time installation code for a site identity.
 */
final class SiteRegistration {

	/**
	 * Exchanges an installation code and stores the resulting site credentials.
	 *
	 * @param string $installation_code One-time broker onboarding code.
	 * @return array<string,mixed>
	 */
	public static function exchange_installation_code( string $installation_code ): array {
		$installation_code = sanitize_text_field( trim( $installation_code ) );

		if ( '' === $installation_code ) {
			throw new RuntimeException( __( 'An installation code is required.', 'ai-provider-for-codex' ) );
		}

		$client   = new Client();
		$response = $client->post(
			'/v1/wordpress/installations/exchange',
			[
				'installationCode' => $installation_code,
				'siteUrl'          => site_url(),
				'homeUrl'          => home_url(),
				'adminEmail'       => (string) get_option( 'admin_email', '' ),
				'wpVersion'        => get_bloginfo( 'version' ),
				'pluginVersion'    => \AIProviderForCodex\VERSION,
			],
			false
		);

		Settings::store_registration( $response );

		return $response;
	}
}
