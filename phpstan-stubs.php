<?php
/**
 * Static-analysis stubs for symbols provided at runtime that are not part of
 * this plugin's dependencies: WordPress core symbols not yet in
 * wordpress-stubs, and the WordPress AI plugin's Request Logging classes.
 *
 * @package HtperkinsAIProviderForCodex
 */

declare( strict_types=1 );

namespace {
	if ( ! class_exists( 'WP_Connector_Registry' ) ) {
		/**
		 * Connector registry stub for PHPStan.
		 */
		class WP_Connector_Registry {

			/**
			 * @param string $id Connector ID.
			 * @return bool
			 */
			public function is_registered( string $id ): bool {}

			/**
			 * @param string $id Connector ID.
			 * @return array<string,mixed>|null The unregistered connector data on success, null on failure.
			 */
			public function unregister( string $id ): ?array {}

			/**
			 * @param string              $id Connector ID.
			 * @param array<string,mixed> $args Connector metadata.
			 * @return array<string,mixed>|null The registered connector data on success, null on failure.
			 */
			public function register( string $id, array $args ): ?array {}
		}
	}

	if ( ! function_exists( 'wp_supports_ai' ) ) {
		/**
		 * @return bool
		 */
		function wp_supports_ai(): bool {}
	}
}

namespace WordPress\AI\Logging {
	if ( ! class_exists( 'WordPress\\AI\\Logging\\AI_Request_Log_Manager' ) ) {
		/**
		 * AI plugin Request Logging manager stub for PHPStan.
		 *
		 * Provided at runtime by the WordPress AI plugin (slug `ai`) when its
		 * "AI Request Logging" experiment is active. Referenced only behind a
		 * class_exists() guard, so it is an optional, soft dependency.
		 */
		class AI_Request_Log_Manager {

			/**
			 * @param array<string,mixed> $data Log data.
			 * @return string|false The log ID on success, false on failure.
			 */
			public function log( array $data ) {}
		}
	}
}
