<?php
/**
 * Shared helpers for local-runtime-backed models.
 *
 * @package AIProviderForCodex
 */

declare( strict_types=1 );

namespace AIProviderForCodex\Models;

use RuntimeException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Small shared helpers for local runtime model implementations.
 */
trait LocalRuntimeModelTrait {

	/**
	 * Returns elapsed whole milliseconds since an hrtime( true ) marker.
	 *
	 * @param float|int $started_at Start marker captured from hrtime( true ).
	 * @return int
	 */
	private static function elapsed_ms( $started_at ): int {
		return (int) round( ( hrtime( true ) - $started_at ) / 1e6 );
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
