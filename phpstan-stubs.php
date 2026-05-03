<?php
/**
 * Static-analysis stubs for WordPress core symbols not yet in wordpress-stubs.
 *
 * @package AIProviderForCodex
 */

declare( strict_types=1 );

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
		 * @return void
		 */
		public function unregister( string $id ): void {}

		/**
		 * @param string              $id Connector ID.
		 * @param array<string,mixed> $args Connector metadata.
		 * @return void
		 */
		public function register( string $id, array $args ): void {}
	}
}

if ( ! function_exists( 'wp_supports_ai' ) ) {
	/**
	 * @return bool
	 */
	function wp_supports_ai(): bool {}
}
