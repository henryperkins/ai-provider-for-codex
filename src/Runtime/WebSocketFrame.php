<?php
/**
 * Minimal WebSocket frame codec for Codex app-server transport.
 *
 * @package HtperkinsAIProviderForCodex
 */

declare( strict_types=1 );

namespace Htperkins\AIProviderForCodex\Runtime;

use RuntimeException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Encodes client text frames and decodes complete server frames.
 */
final class WebSocketFrame {

	/**
	 * Encodes a masked client text frame.
	 *
	 * @param string      $payload Payload text.
	 * @param string|null $mask_key Optional four-byte mask for tests.
	 * @return string
	 */
	public static function encode_client_text( string $payload, ?string $mask_key = null ): string {
		$mask_key = null === $mask_key ? random_bytes( 4 ) : $mask_key;

		if ( 4 !== strlen( $mask_key ) ) {
			throw new RuntimeException( 'WebSocket mask key must be exactly four bytes.' );
		}

		$length = strlen( $payload );
		$frame  = "\x81";

		if ( $length <= 125 ) {
			$frame .= chr( 0x80 | $length );
		} elseif ( $length <= 65535 ) {
			$frame .= chr( 0x80 | 126 ) . pack( 'n', $length );
		} else {
			$frame .= chr( 0x80 | 127 ) . pack( 'NN', 0, $length );
		}

		return $frame . $mask_key . self::mask_payload( $payload, $mask_key );
	}

	/**
	 * Decodes a complete server frame.
	 *
	 * @param string $frame Raw frame bytes.
	 * @return array{type:string,payload:string}
	 */
	public static function decode_server_frame( string $frame ): array {
		if ( strlen( $frame ) < 2 ) {
			throw new RuntimeException( 'Incomplete WebSocket frame.' );
		}

		$first_byte  = ord( $frame[0] );
		$second_byte = ord( $frame[1] );
		$opcode      = $first_byte & 0x0f;
		$masked      = 0 !== ( $second_byte & 0x80 );
		$length      = $second_byte & 0x7f;
		$offset      = 2;

		if ( 126 === $length ) {
			if ( strlen( $frame ) < 4 ) {
				throw new RuntimeException( 'Incomplete WebSocket frame length.' );
			}
			$length = (int) unpack( 'n', substr( $frame, 2, 2 ) )[1];
			$offset = 4;
		} elseif ( 127 === $length ) {
			if ( strlen( $frame ) < 10 ) {
				throw new RuntimeException( 'Incomplete WebSocket frame length.' );
			}
			$parts  = unpack( 'Nhigh/Nlow', substr( $frame, 2, 8 ) );
			$length = (int) $parts['low'];
			$offset = 10;
		}

		$mask_key = '';
		if ( $masked ) {
			if ( strlen( $frame ) < $offset + 4 ) {
				throw new RuntimeException( 'Incomplete WebSocket mask key.' );
			}
			$mask_key = substr( $frame, $offset, 4 );
			$offset  += 4;
		}

		if ( strlen( $frame ) < $offset + $length ) {
			throw new RuntimeException( 'Incomplete WebSocket payload.' );
		}

		$payload = substr( $frame, $offset, $length );
		if ( $masked ) {
			$payload = self::mask_payload( $payload, $mask_key );
		}

		if ( 0x1 === $opcode ) {
			return [
				'type'    => 'text',
				'payload' => $payload,
			];
		}

		if ( 0x8 === $opcode ) {
			return [
				'type'    => 'close',
				'payload' => $payload,
			];
		}

		if ( 0x9 === $opcode ) {
			return [
				'type'    => 'ping',
				'payload' => $payload,
			];
		}

		if ( 0xA === $opcode ) {
			return [
				'type'    => 'pong',
				'payload' => $payload,
			];
		}

		throw new RuntimeException( 'Unsupported WebSocket opcode.' );
	}

	/**
	 * Applies or removes a WebSocket mask.
	 *
	 * @param string $payload Payload bytes.
	 * @param string $mask_key Four-byte mask.
	 * @return string
	 */
	private static function mask_payload( string $payload, string $mask_key ): string {
		$output = '';
		$length = strlen( $payload );

		for ( $i = 0; $i < $length; $i++ ) {
			$output .= $payload[ $i ] ^ $mask_key[ $i % 4 ];
		}

		return $output;
	}
}
