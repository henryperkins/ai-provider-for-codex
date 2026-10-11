<?php
/**
 * Static-analysis stubs for symbols provided at runtime that are not part of
 * this plugin's dependencies: WordPress core symbols not yet in
 * wordpress-stubs, and the WordPress AI plugin's Request Logging classes.
 *
 * @package AIProviderForCodex
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
	if ( ! class_exists( 'WordPress\\AI\\Logging\\Logging_Integration' ) ) {
		/**
		 * Active shared logging manager exposed by AI plugin 1.3+.
		 */
		class Logging_Integration {

			/**
			 * @return AI_Request_Log_Manager|null Null when logging is inactive.
			 */
			public static function get_log_manager(): ?AI_Request_Log_Manager {}
		}
	}

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

namespace WordPress\AI {
	if ( ! function_exists( 'WordPress\\AI\\get_preferred_image_models' ) ) {
		/**
		 * Preferred provider/model pairs supplied by the optional WordPress AI plugin.
		 *
		 * @return array<int,array{string,string}>
		 */
		function get_preferred_image_models(): array {}
	}

	if ( ! function_exists( 'WordPress\\AI\\log_ai_request' ) ) {
		/**
		 * Public request-log helper provided by AI plugin 1.3+.
		 *
		 * @param array<string,mixed> $data Request log data.
		 * @return string|false Log identifier, or false when inactive or rejected.
		 */
		function log_ai_request( array $data ) {}
	}
}
