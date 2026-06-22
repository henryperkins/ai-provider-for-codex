<?php
/**
 * Tests the app-server WebSocket frame codec.
 *
 * @package HtperkinsAIProviderForCodex
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/../' );

require_once __DIR__ . '/../src/autoload.php';

use Htperkins\AIProviderForCodex\Runtime\WebSocketFrame;

$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};

$encoded = WebSocketFrame::encode_client_text( 'hi', "\x01\x02\x03\x04" );
$assert( "\x81\x82\x01\x02\x03\x04" . ( "h" ^ "\x01" ) . ( "i" ^ "\x02" ) === $encoded, 'Client text frames must be masked.' );

$decoded = WebSocketFrame::decode_server_frame( "\x81\x02hi" );
$assert( 'text' === $decoded['type'], 'Server text frame type should be text.' );
$assert( 'hi' === $decoded['payload'], 'Server text frame payload should decode unchanged.' );

echo "OK: websocket frame codec assertions passed\n";
