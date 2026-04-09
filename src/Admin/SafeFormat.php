<?php
/**
 * Safe formatting for translated admin strings.
 *
 * @package AIProviderForCodex
 */

declare( strict_types=1 );

namespace AIProviderForCodex\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Escapes stray percent signs while preserving valid sprintf placeholders.
 */
final class SafeFormat {

	/**
	 * Matches percent signs that are not part of a supported sprintf placeholder.
	 */
	private const UNESCAPED_PERCENT_PATTERN = '/%(?!(?:\d+\$)?[-+0-9.\'#]*(?:[bcdeEfFgGosuxX])|%)/';

	/**
	 * Formats a translated string without letting stray percent signs break sprintf.
	 *
	 * @param string $format Translated format string.
	 * @param mixed  ...$args Values to substitute.
	 * @return string
	 */
	public static function sprintf( string $format, ...$args ): string {
		$normalized = preg_replace( self::UNESCAPED_PERCENT_PATTERN, '%%', $format );

		if ( null === $normalized ) {
			$normalized = $format;
		}

		return vsprintf( $normalized, $args );
	}
}
