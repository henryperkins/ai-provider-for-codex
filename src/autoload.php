<?php
/**
 * Simple PSR-4 autoloader for the plugin scaffold.
 *
 * @package AIProviderForCodex
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'AIProviderForCodex\\';

		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}

		$relative_class = substr( $class, strlen( $prefix ) );
		$path           = __DIR__ . '/' . str_replace( '\\', '/', $relative_class ) . '.php';

		if ( file_exists( $path ) ) {
			require_once $path;
		}
	}
);
