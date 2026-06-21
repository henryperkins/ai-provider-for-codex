<?php
/**
 * JSON-RPC client for Codex app-server over WebSocket.
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
 * Maintains one initialized WebSocket connection to codex app-server.
 */
final class AppServerConnection {

	/**
	 * App-server WebSocket endpoint.
	 *
	 * @var string
	 */
	private $endpoint;

	/**
	 * Optional WebSocket bearer token.
	 *
	 * @var string
	 */
	private $token;

	/**
	 * Socket connect timeout.
	 *
	 * @var int
	 */
	private $timeout;

	/**
	 * Connected socket resource.
	 *
	 * @var resource|null
	 */
	private $socket;

	/**
	 * Next JSON-RPC request ID.
	 *
	 * @var int
	 */
	private $next_id = 1;

	/**
	 * Buffered notifications.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private $notifications = [];

	/**
	 * Constructor.
	 *
	 * @param string $endpoint WebSocket endpoint.
	 * @param string $token Optional bearer token.
	 * @param int    $timeout Connect timeout.
	 */
	public function __construct( string $endpoint, string $token = '', int $timeout = 20 ) {
		$this->endpoint = $endpoint;
		$this->token    = $token;
		$this->timeout  = max( 1, $timeout );
	}

	/**
	 * Sends a JSON-RPC request and waits for its response.
	 *
	 * @param string              $method Request method.
	 * @param array<string,mixed> $params Request params.
	 * @param int                 $timeout Timeout in seconds.
	 * @return mixed
	 */
	public function request( string $method, array $params = [], int $timeout = 20 ) {
		$this->connect();

		$id = $this->next_id++;
		$this->send_json(
			[
				'id'     => $id,
				'method' => $method,
				'params' => (object) $params,
			]
		);

		$deadline = microtime( true ) + max( 1, $timeout );

		while ( microtime( true ) < $deadline ) {
			$message = $this->receive_json( max( 1, (int) ceil( $deadline - microtime( true ) ) ) );

				if ( isset( $message['id'] ) && (int) $message['id'] === $id ) {
					if ( isset( $message['error'] ) && is_array( $message['error'] ) ) {
						// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are escaped at the render boundary.
						throw self::runtime_exception( sanitize_text_field( (string) ( $message['error']['message'] ?? $method . ' failed.' ) ) );
					}

				return $message['result'] ?? [];
			}

			if ( ! isset( $message['id'] ) ) {
				$this->notifications[] = $message;
			}
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are escaped at the render boundary.
		throw self::runtime_exception( sprintf( '%s timed out after %d seconds.', $method, $timeout ) );
	}

	/**
	 * Sends a JSON-RPC notification.
	 *
	 * @param string              $method Notification method.
	 * @param array<string,mixed> $params Notification params.
	 * @return void
	 */
	public function notify( string $method, array $params = [] ): void {
		$this->connect();
		$this->send_json(
			[
				'method' => $method,
				'params' => (object) $params,
			]
		);
	}

	/**
	 * Waits for a notification matching a predicate.
	 *
	 * @param callable $predicate Predicate accepting a message array.
	 * @param int      $timeout Timeout in seconds.
	 * @return array<string,mixed>
	 */
	public function wait_for_notification( callable $predicate, int $timeout = 300 ): array {
		$deadline = microtime( true ) + max( 1, $timeout );

		while ( microtime( true ) < $deadline ) {
			foreach ( $this->notifications as $index => $message ) {
				if ( $predicate( $message ) ) {
					unset( $this->notifications[ $index ] );
					$this->notifications = array_values( $this->notifications );
					return $message;
				}
			}

			$message = $this->receive_json( max( 1, (int) ceil( $deadline - microtime( true ) ) ) );
			if ( $predicate( $message ) ) {
				return $message;
			}

			if ( ! isset( $message['id'] ) ) {
				$this->notifications[] = $message;
			}
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are escaped at the render boundary.
		throw self::runtime_exception( sprintf( 'Timed out waiting for Codex app-server notification after %d seconds.', $timeout ) );
	}

	/**
	 * Closes the socket.
	 *
	 * @return void
	 */
	public function close(): void {
		if ( is_resource( $this->socket ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- WebSocket streams require direct PHP stream close; WP_Filesystem cannot operate sockets.
			fclose( $this->socket );
		}

		$this->socket = null;
	}

	/**
	 * Connects and initializes the app-server session.
	 *
	 * @return void
	 */
	private function connect(): void {
		if ( is_resource( $this->socket ) ) {
			return;
		}

		$parts  = $this->parse_endpoint();
		$errno  = 0;
		$errstr = '';
		$scheme = 'wss' === $parts['scheme'] ? 'tls' : 'tcp';
		$target = sprintf( '%s://%s:%d', $scheme, $parts['host'], $parts['port'] );
		$socket = stream_socket_client( $target, $errno, $errstr, $this->timeout );

		if ( false === $socket ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are escaped at the render boundary.
			throw self::runtime_exception( sprintf( 'Could not connect to Codex app-server at %s: %s', $this->endpoint, $errstr ?: (string) $errno ) );
		}

		$this->socket = $socket;
		stream_set_timeout( $this->socket, $this->timeout );
		$this->handshake( $parts );
		$this->initialize();
	}

	/**
	 * Parses the WebSocket endpoint.
	 *
	 * @return array{scheme:string,host:string,port:int,path:string}
	 */
	private function parse_endpoint(): array {
		$scheme = strtolower( (string) wp_parse_url( $this->endpoint, PHP_URL_SCHEME ) );
		$host   = (string) wp_parse_url( $this->endpoint, PHP_URL_HOST );
		$port   = (int) wp_parse_url( $this->endpoint, PHP_URL_PORT );
		$path   = (string) wp_parse_url( $this->endpoint, PHP_URL_PATH );

		if ( ! in_array( $scheme, [ 'ws', 'wss' ], true ) || '' === $host ) {
			throw new RuntimeException( 'Codex app-server endpoint must be a ws:// or wss:// URL.' );
		}

		if ( 0 === $port ) {
			$port = 'wss' === $scheme ? 443 : 80;
		}

		return [
			'scheme' => $scheme,
			'host'   => $host,
			'port'   => $port,
			'path'   => '' !== $path ? $path : '/',
		];
	}

	/**
	 * Performs the WebSocket handshake.
	 *
	 * @param array{scheme:string,host:string,port:int,path:string} $parts Endpoint parts.
	 * @return void
	 */
	private function handshake( array $parts ): void {
		$key     = base64_encode( random_bytes( 16 ) );
		$host    = $parts['host'] . ':' . $parts['port'];
		$headers = [
			'GET ' . $parts['path'] . ' HTTP/1.1',
			'Host: ' . $host,
			'Upgrade: websocket',
			'Connection: Upgrade',
			'Sec-WebSocket-Key: ' . $key,
			'Sec-WebSocket-Version: 13',
		];

		if ( '' !== $this->token ) {
			$headers[] = 'Authorization: Bearer ' . $this->token;
		}

		$this->write( implode( "\r\n", $headers ) . "\r\n\r\n" );
		$response = '';

		while ( false === strpos( $response, "\r\n\r\n" ) ) {
			$chunk = $this->read( 1024 );
			if ( '' === $chunk ) {
				break;
			}
			$response .= $chunk;
		}

		if ( ! preg_match( '/^HTTP\/\d(?:\.\d)?\s+101\s/i', $response ) ) {
			throw new RuntimeException( 'Codex app-server WebSocket handshake failed.' );
		}
	}

	/**
	 * Initializes JSON-RPC.
	 *
	 * @return void
	 */
	private function initialize(): void {
		$this->request(
			'initialize',
			[
				'clientInfo' => [
					'name'    => 'wordpress_ai_provider_for_codex',
					'title'   => 'WordPress AI Provider for Codex',
					'version' => \Htperkins\AIProviderForCodex\VERSION,
				],
			],
			$this->timeout
		);
		$this->notify( 'initialized' );
	}

	/**
	 * Sends JSON as a WebSocket text frame.
	 *
	 * @param array<string,mixed> $payload Payload.
	 * @return void
	 */
	private function send_json( array $payload ): void {
		$json = wp_json_encode( $payload );

		if ( false === $json ) {
			throw new RuntimeException( 'Could not encode Codex app-server JSON-RPC payload.' );
		}

		$this->write( WebSocketFrame::encode_client_text( $json ) );
	}

	/**
	 * Receives JSON from a WebSocket text frame.
	 *
	 * @param int $timeout Timeout in seconds.
	 * @return array<string,mixed>
	 */
	private function receive_json( int $timeout ): array {
		if ( is_resource( $this->socket ) ) {
			stream_set_timeout( $this->socket, max( 1, $timeout ) );
		}

		$frame = $this->read_frame();

		if ( 'close' === $frame['type'] ) {
			throw new RuntimeException( 'Codex app-server closed the WebSocket connection.' );
		}

		if ( 'text' !== $frame['type'] ) {
			return $this->receive_json( $timeout );
		}

		$message = json_decode( $frame['payload'], true );

		if ( ! is_array( $message ) ) {
			throw new RuntimeException( 'Codex app-server returned invalid JSON-RPC.' );
		}

		return $message;
	}

	/**
	 * Reads a complete frame from the socket.
	 *
	 * @return array{type:string,payload:string}
	 */
	private function read_frame(): array {
		$header      = $this->read_exact( 2 );
		$second_byte = ord( $header[1] );
		$length      = $second_byte & 0x7f;
		$extra       = '';

		if ( 126 === $length ) {
			$extra = $this->read_exact( 2 );
			$length = (int) unpack( 'n', $extra )[1];
		} elseif ( 127 === $length ) {
			$extra = $this->read_exact( 8 );
			$parts = unpack( 'Nhigh/Nlow', $extra );
			$length = (int) $parts['low'];
		}

		$mask_key = '';
		if ( 0 !== ( $second_byte & 0x80 ) ) {
			$mask_key = $this->read_exact( 4 );
		}

		$payload = $length > 0 ? $this->read_exact( $length ) : '';

		return WebSocketFrame::decode_server_frame( $header . $extra . $mask_key . $payload );
	}

	/**
	 * Writes bytes to the socket.
	 *
	 * @param string $bytes Bytes to write.
	 * @return void
	 */
	private function write( string $bytes ): void {
		if ( ! is_resource( $this->socket ) ) {
			throw self::runtime_exception( 'Codex app-server socket is not connected.' );
		}

		$total  = strlen( $bytes );
		$offset = 0;

		while ( $offset < $total ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- WebSocket streams require direct PHP stream writes; WP_Filesystem cannot operate sockets.
			$written = fwrite( $this->socket, substr( $bytes, $offset ) );

			if ( false === $written || 0 === $written ) {
				throw self::runtime_exception( 'Could not write to Codex app-server socket.' );
			}

			$offset += $written;
		}
	}

	/**
	 * Reads bytes from the socket.
	 *
	 * @param int $length Max bytes.
	 * @return string
	 */
	private function read( int $length ): string {
		if ( ! is_resource( $this->socket ) ) {
			throw self::runtime_exception( 'Codex app-server socket is not connected.' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- WebSocket streams require direct PHP stream reads; WP_Filesystem cannot operate sockets.
		$chunk = fread( $this->socket, $length );

		if ( false === $chunk ) {
			throw self::runtime_exception( 'Could not read from Codex app-server socket.' );
		}

		return $chunk;
	}

	/**
	 * Reads an exact number of bytes.
	 *
	 * @param int $length Bytes required.
	 * @return string
	 */
	private function read_exact( int $length ): string {
		$buffer = '';

		while ( strlen( $buffer ) < $length ) {
			$chunk = $this->read( $length - strlen( $buffer ) );

			if ( '' === $chunk ) {
				throw self::runtime_exception( 'Codex app-server socket closed unexpectedly.' );
			}

			$buffer .= $chunk;
		}

		return $buffer;
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
