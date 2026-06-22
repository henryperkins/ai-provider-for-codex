<?php
/**
 * Local-runtime-compatible client backed by Codex app-server.
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
 * Converts the Codex app-server JSON-RPC protocol into the plugin runtime shape.
 */
final class AppServerClient {

	private const REQUEST_TIMEOUT = 20;
	private const TURN_TIMEOUT    = 360;

	/**
	 * Optional factory used by tests.
	 *
	 * @var callable|null
	 */
	private $session_factory;

	/**
	 * Constructor.
	 *
	 * @param callable|null $session_factory Optional session factory.
	 */
	public function __construct( ?callable $session_factory = null ) {
		$this->session_factory = $session_factory;
	}

	/**
	 * Returns whether a URL targets Codex app-server directly.
	 *
	 * @param string $url Runtime URL.
	 * @return bool
	 */
	public static function supports_url( string $url ): bool {
		return in_array(
			strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ),
			[ 'ws', 'wss' ],
			true
		);
	}

	/**
	 * Returns app-server health.
	 *
	 * @return array<string,mixed>
	 */
	public function health(): array {
		return $this->with_session(
			static function ( $session ): array {
				// Force the WebSocket connect + JSON-RPC handshake so health
				// reflects real reachability instead of a no-op success.
				$session->ping();

				return [
					'ok'      => true,
					'service' => 'codex-app-server',
					'version' => \Htperkins\AIProviderForCodex\VERSION,
				];
			}
		);
	}

	/**
	 * Returns diagnostics rows.
	 *
	 * @return array<string,mixed>
	 */
	public function diagnostics(): array {
		// Let a connection failure propagate: Client::request_app_server() wraps it
		// as a transport error so DiagnosticsController owns the unreachable row,
		// instead of reporting a misleading "reachable" pass here.
		$this->health();

		return [
			'ok'      => true,
			'service' => 'codex-app-server',
			'version' => \Htperkins\AIProviderForCodex\VERSION,
			'checks'  => [
				[
					'id'     => 'app_server',
					'label'  => __( 'Codex app-server reachable', 'ai-provider-for-codex' ),
					'status' => 'pass',
					'detail' => Settings::get_base_url(),
				],
			],
		];
	}

	/**
	 * Returns a site-level account snapshot.
	 *
	 * @return array<string,mixed>
	 */
	public function account_snapshot(): array {
		return $this->with_session(
			function ( $session ): array {
				$account     = $session->request( 'account/read', [ 'refresh' => true ], self::REQUEST_TIMEOUT );
				$rate_limits = $session->request( 'account/rateLimits/read', [], self::REQUEST_TIMEOUT );
				$models      = $session->request( 'model/list', [ 'includeHidden' => false ], self::REQUEST_TIMEOUT );

				try {
					$capabilities = $session->request( 'modelProvider/capabilities/read', [], self::REQUEST_TIMEOUT );
				} catch ( \Throwable $exception ) {
					$capabilities = [];
				}

				$account_payload = self::normalize_account_payload( $account );
				if ( empty( $account_payload['authMode'] ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are escaped at the render boundary.
					throw self::runtime_exception( __( 'No site-level Codex account is available. Run `codex login` for the service user that starts app-server.', 'ai-provider-for-codex' ) );
				}

				return [
					'account'      => $account_payload,
					'authStored'   => true,
					'capabilities' => self::normalize_capabilities_payload( $capabilities ),
					'defaultModel' => self::select_default_model( $models ),
					'models'       => self::normalize_models_payload( $models ),
					'rateLimits'   => self::normalize_rate_limits_payload( $rate_limits ),
				];
			}
		);
	}

	/**
	 * Generates text through Codex app-server.
	 *
	 * @param array<string,mixed> $payload Runtime request body.
	 * @return array<string,mixed>
	 */
	public function generate_text( array $payload ): array {
		return $this->with_session(
			function ( $session ) use ( $payload ): array {
				$input_text         = self::optional_string( $payload['input'] ?? null );
				$input_images       = self::normalize_text_input_images( $payload['inputImages'] ?? null );
				$request_id         = self::optional_string( $payload['requestId'] ?? null ) ?: wp_generate_uuid4();
				$model              = self::optional_string( $payload['model'] ?? null );
				$reasoning_effort   = self::optional_string( $payload['reasoningEffort'] ?? null );
				$system_instruction = self::optional_string( $payload['systemInstruction'] ?? null );
				$response_format    = $payload['responseFormat'] ?? null;

				if ( '' === $input_text && [] === $input_images ) {
					throw new RuntimeException( 'input is required.' );
				}

				$thread_params = [ 'ephemeral' => true ];
				if ( $model ) {
					$thread_params['model'] = $model;
				}
				if ( $system_instruction ) {
					$thread_params['developerInstructions'] = $system_instruction;
				}

				$thread_started = $session->request( 'thread/start', $thread_params, self::REQUEST_TIMEOUT );
				$thread_id      = self::require_nested_string( $thread_started, [ 'thread', 'id' ] );
				$turn_input     = [];

				if ( '' !== $input_text ) {
					$turn_input[] = [
						'type' => 'text',
						'text' => $input_text,
					];
				}

				foreach ( $input_images as $input_image ) {
					$turn_input[] = $input_image;
				}

				$turn_payload = [
					'threadId' => $thread_id,
					'input'    => $turn_input,
				];

				if ( $model ) {
					$turn_payload['model'] = $model;
				}
				if ( $reasoning_effort ) {
					$turn_payload['effort'] = $reasoning_effort;
				}
				if (
					is_array( $response_format )
					&& 'json_schema' === ( $response_format['type'] ?? '' )
					&& isset( $response_format['schema'] )
					&& is_array( $response_format['schema'] )
				) {
					$turn_payload['outputSchema'] = $response_format['schema'];
				}

				$turn_started = $session->request( 'turn/start', $turn_payload, self::REQUEST_TIMEOUT );
				$turn_id      = self::require_nested_string( $turn_started, [ 'turn', 'id' ] );
				$output_parts = [];
				$output_text  = '';
				$token_usage  = [];

				while ( true ) {
					$notification = $session->wait_for_notification(
						static function ( array $message ) use ( $turn_id ): bool {
							return self::notification_matches_turn( $message, $turn_id );
						},
						self::TURN_TIMEOUT
					);

					$method = (string) ( $notification['method'] ?? '' );
					$params = $notification['params'] ?? [];

					if ( 'item/agentMessage/delta' === $method && is_array( $params ) && is_string( $params['delta'] ?? null ) ) {
						$output_parts[] = (string) $params['delta'];
						continue;
					}

					if ( 'item/completed' === $method && is_array( $params ) ) {
						$item = $params['item'] ?? null;
						if ( is_array( $item ) && 'agentMessage' === ( $item['type'] ?? '' ) && is_string( $item['text'] ?? null ) ) {
							$output_text = (string) $item['text'];
						}
						continue;
					}

					if ( 'thread/tokenUsage/updated' === $method && is_array( $params ) && is_array( $params['tokenUsage'] ?? null ) ) {
						$token_usage = $params['tokenUsage'];
						continue;
					}

					if ( 'turn/completed' !== $method || ! is_array( $params ) ) {
						continue;
					}

					$turn = $params['turn'] ?? null;
					if ( is_array( $turn ) && ! empty( $turn['error'] ) ) {
						// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are escaped at the render boundary.
						throw self::runtime_exception( sanitize_text_field( (string) $turn['error'] ) );
					}
					break;
				}

				if ( '' === $output_text ) {
					$output_text = trim( implode( '', $output_parts ) );
				}

				$structured_output = null;
				if ( is_array( $response_format ) && '' !== $output_text ) {
					$decoded = json_decode( $output_text, true );
					if ( JSON_ERROR_NONE === json_last_error() ) {
						$structured_output = $decoded;
					}
				}

				$account     = $session->request( 'account/read', [ 'refresh' => false ], self::REQUEST_TIMEOUT );
				$rate_limits = $session->request( 'account/rateLimits/read', [], self::REQUEST_TIMEOUT );

				return [
					'account'          => self::normalize_account_payload( $account ),
					'authStored'       => true,
					'finishReason'     => 'stop',
					'model'            => $model ?: null,
					'outputText'       => $output_text,
					'rateLimits'       => self::normalize_rate_limits_payload( $rate_limits ),
					'requestId'        => $request_id,
					'structuredOutput' => $structured_output,
					'usage'            => self::extract_turn_token_usage( $token_usage ),
				];
			}
		);
	}

	/**
	 * Generates an image through Codex app-server.
	 *
	 * @param array<string,mixed> $payload Runtime request body.
	 * @return array<string,mixed>
	 */
	public function generate_image( array $payload ): array {
		return $this->with_session(
			function ( $session ) use ( $payload ): array {
				$prompt             = self::require_string( $payload['prompt'] ?? null, 'prompt' );
				$request_id         = self::optional_string( $payload['requestId'] ?? null ) ?: wp_generate_uuid4();
				$system_instruction = self::optional_string( $payload['systemInstruction'] ?? null );
				$capabilities       = [];

				try {
					$capabilities = self::normalize_capabilities_payload(
						$session->request( 'modelProvider/capabilities/read', [], self::REQUEST_TIMEOUT )
					);
				} catch ( \Throwable $exception ) {
					$capabilities = [];
				}

				if ( empty( $capabilities['imageGeneration'] ) ) {
					throw new RuntimeException( 'The active Codex app-server account does not report image generation support.' );
				}

				$thread_params = [ 'ephemeral' => true ];
				if ( $system_instruction ) {
					$thread_params['developerInstructions'] = $system_instruction;
				}

				$thread_started = $session->request( 'thread/start', $thread_params, self::REQUEST_TIMEOUT );
				$thread_id      = self::require_nested_string( $thread_started, [ 'thread', 'id' ] );
				$turn_started   = $session->request(
					'turn/start',
					[
						'threadId' => $thread_id,
						'input'    => [
							[
								'type' => 'text',
								'text' => self::build_image_generation_prompt( $prompt ),
							],
						],
					],
					self::REQUEST_TIMEOUT
				);
				$turn_id        = self::require_nested_string( $turn_started, [ 'turn', 'id' ] );
				$token_usage    = [];
				$image_item     = null;
				$image_count    = 0;

				while ( true ) {
					$notification = $session->wait_for_notification(
						static function ( array $message ) use ( $turn_id ): bool {
							return self::notification_matches_turn( $message, $turn_id ) || null !== self::extract_image_item( $message );
						},
						self::TURN_TIMEOUT
					);

					$method = (string) ( $notification['method'] ?? '' );
					$params = $notification['params'] ?? [];

					if ( 'thread/tokenUsage/updated' === $method && is_array( $params ) && is_array( $params['tokenUsage'] ?? null ) ) {
						$token_usage = $params['tokenUsage'];
						continue;
					}

					if ( 'item/completed' === $method ) {
						$extracted = self::extract_image_item( $notification );
						if ( null !== $extracted ) {
							$image_count++;
							if ( null === $image_item ) {
								$image_item = $extracted;
							}
						}
						continue;
					}

					if ( 'turn/completed' !== $method || ! is_array( $params ) ) {
						continue;
					}

					$turn = $params['turn'] ?? null;
					if ( is_array( $turn ) && ! empty( $turn['error'] ) ) {
						// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are escaped at the render boundary.
						throw self::runtime_exception( sanitize_text_field( (string) $turn['error'] ) );
					}
					break;
				}

				if ( null === $image_item ) {
					throw new RuntimeException( 'Codex completed the image turn without returning image data.' );
				}

				$account     = $session->request( 'account/read', [ 'refresh' => false ], self::REQUEST_TIMEOUT );
				$rate_limits = $session->request( 'account/rateLimits/read', [], self::REQUEST_TIMEOUT );
				$response    = [
					'account'      => self::normalize_account_payload( $account ),
					'artifacts'    => $image_item['artifacts'] ?? [],
					'authStored'   => true,
					'finishReason' => 'stop',
					'imageBase64'  => $image_item['imageBase64'],
					'imageCount'   => $image_count ?: 1,
					'mimeType'     => $image_item['mimeType'],
					'model'        => 'codex-image',
					'rateLimits'   => self::normalize_rate_limits_payload( $rate_limits ),
					'requestId'    => $request_id,
					'runtimeModel' => $image_item['runtimeModel'] ?? null,
					'usage'        => self::extract_turn_token_usage( $token_usage ),
				];

				if ( ! empty( $image_item['revisedPrompt'] ) ) {
					$response['revisedPrompt'] = $image_item['revisedPrompt'];
				}

				return $response;
			}
		);
	}

	/**
	 * Runs a callback with an app-server session.
	 *
	 * @param callable $callback Callback receiving a session.
	 * @return mixed
	 */
	private function with_session( callable $callback ) {
		$session = $this->create_session();

		try {
			return $callback( $session );
		} finally {
			if ( method_exists( $session, 'close' ) ) {
				$session->close();
			}
		}
	}

	/**
	 * Creates an app-server session.
	 *
	 * @return object
	 */
	private function create_session() {
		$session_factory = apply_filters( 'htperkins_aipfc_app_server_session_factory', $this->session_factory );

		if ( is_callable( $session_factory ) ) {
			return call_user_func( $session_factory );
		}

		return new AppServerConnection( Settings::get_base_url(), Settings::get_bearer_token(), self::REQUEST_TIMEOUT );
	}

	/**
	 * Returns a non-empty string or an empty string.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private static function optional_string( $value ): string {
		return is_string( $value ) && '' !== trim( $value ) ? trim( $value ) : '';
	}

	/**
	 * Requires a non-empty string.
	 *
	 * @param mixed  $value Raw value.
	 * @param string $field_name Field name.
	 * @return string
	 */
	private static function require_string( $value, string $field_name ): string {
		$value = self::optional_string( $value );

		if ( '' === $value ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are escaped at the render boundary.
			throw self::runtime_exception( sprintf( '%s is required.', $field_name ) );
		}

		return $value;
	}

	/**
	 * Requires a nested string path in a payload.
	 *
	 * @param mixed    $payload Payload.
	 * @param string[] $path Path segments.
	 * @return string
	 */
	private static function require_nested_string( $payload, array $path ): string {
		$current = $payload;

		foreach ( $path as $segment ) {
			if ( ! is_array( $current ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are escaped at the render boundary.
				throw self::runtime_exception( sprintf( 'Missing %s in app-server response.', implode( '.', $path ) ) );
			}
			$current = $current[ $segment ] ?? null;
		}

		if ( ! is_string( $current ) || '' === $current ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are escaped at the render boundary.
			throw self::runtime_exception( sprintf( 'Missing %s in app-server response.', implode( '.', $path ) ) );
		}

		return $current;
	}

	/**
	 * Normalizes account payload.
	 *
	 * @param mixed $payload Payload.
	 * @return array{authMode:?string,email:?string,planType:?string,type:?string}
	 */
	private static function normalize_account_payload( $payload ): array {
		$account = is_array( $payload ) && isset( $payload['account'] ) && is_array( $payload['account'] ) ? $payload['account'] : [];
		$type    = self::optional_string( $account['type'] ?? null );

		return [
			'authMode' => '' !== $type ? $type : null,
			'email'    => '' !== self::optional_string( $account['email'] ?? null ) ? self::optional_string( $account['email'] ?? null ) : null,
			'planType' => '' !== self::optional_string( $account['planType'] ?? null ) ? self::optional_string( $account['planType'] ?? null ) : null,
			'type'     => '' !== $type ? $type : null,
		];
	}

	/**
	 * Normalizes rate-limit payload.
	 *
	 * @param mixed $payload Payload.
	 * @return array<string,mixed>
	 */
	private static function normalize_rate_limits_payload( $payload ): array {
		if ( ! is_array( $payload ) ) {
			return [];
		}

		return isset( $payload['rateLimits'] ) && is_array( $payload['rateLimits'] ) ? $payload['rateLimits'] : $payload;
	}

	/**
	 * Normalizes model-list payload.
	 *
	 * @param mixed $payload Payload.
	 * @return array<int,array<string,mixed>>
	 */
	private static function normalize_models_payload( $payload ): array {
		return is_array( $payload ) && isset( $payload['data'] ) && is_array( $payload['data'] ) ? $payload['data'] : [];
	}

	/**
	 * Normalizes capabilities.
	 *
	 * @param mixed $payload Payload.
	 * @return array{imageGeneration:bool,namespaceTools:bool,webSearch:bool}
	 */
	private static function normalize_capabilities_payload( $payload ): array {
		return [
			'imageGeneration' => is_array( $payload ) && ! empty( $payload['imageGeneration'] ),
			'namespaceTools'  => is_array( $payload ) && ! empty( $payload['namespaceTools'] ),
			'webSearch'       => is_array( $payload ) && ! empty( $payload['webSearch'] ),
		];
	}

	/**
	 * Selects the default model from model/list.
	 *
	 * @param mixed $payload Payload.
	 * @return string|null
	 */
	private static function select_default_model( $payload ): ?string {
		$models = self::normalize_models_payload( $payload );

		foreach ( $models as $model ) {
			if ( ! empty( $model['isDefault'] ) && is_string( $model['model'] ?? null ) ) {
				return (string) $model['model'];
			}
		}

		$first = $models[0] ?? [];
		return is_string( $first['model'] ?? null ) ? (string) $first['model'] : null;
	}

	/**
	 * Normalizes image inputs.
	 *
	 * @param mixed $value Raw image list.
	 * @return array<int,array<string,mixed>>
	 */
	private static function normalize_text_input_images( $value ): array {
		if ( null === $value ) {
			return [];
		}

		if ( ! is_array( $value ) ) {
			throw new RuntimeException( 'inputImages must be a list.' );
		}

		$images = [];
		foreach ( $value as $index => $image ) {
			if ( ! is_array( $image ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are escaped at the render boundary.
				throw self::runtime_exception( sprintf( 'inputImages[%d] must be an object.', (int) $index ) );
			}

			$url = self::require_string( $image['url'] ?? null, sprintf( 'inputImages[%d].url', (int) $index ) );
			$item = [
				'type' => 'image',
				'url'  => $url,
			];
			$detail = self::optional_string( $image['detail'] ?? null );
			if ( '' !== $detail ) {
				$item['detail'] = $detail;
			}
			$images[] = $item;
		}

		return $images;
	}

	/**
	 * Whether a notification belongs to a turn.
	 *
	 * @param array<string,mixed> $message Notification.
	 * @param string              $turn_id Turn ID.
	 * @return bool
	 */
	private static function notification_matches_turn( array $message, string $turn_id ): bool {
		$params = $message['params'] ?? [];

		if ( ! is_array( $params ) ) {
			return false;
		}

		if ( isset( $params['turnId'] ) && $params['turnId'] === $turn_id ) {
			return true;
		}

		$turn = $params['turn'] ?? null;
		return is_array( $turn ) && ( $turn['id'] ?? null ) === $turn_id;
	}

	/**
	 * Builds the image-generation instruction.
	 *
	 * @param string $prompt User prompt.
	 * @return string
	 */
	private static function build_image_generation_prompt( string $prompt ): string {
		return "Generate an image using the image_generation tool. Do not reply with text-only commentary. Use this exact image prompt:\n\n" . $prompt;
	}

	/**
	 * Extracts a completed image item.
	 *
	 * @param array<string,mixed> $message Notification.
	 * @return array<string,mixed>|null
	 */
	private static function extract_image_item( array $message ): ?array {
		$params = $message['params'] ?? [];
		$item   = is_array( $params ) ? ( $params['item'] ?? null ) : null;

		if ( ! is_array( $item ) ) {
			return null;
		}

		$image_base64 = self::optional_string( $item['imageBase64'] ?? ( $item['base64'] ?? null ) );
		if ( '' === $image_base64 ) {
			return null;
		}

		$normalized = [
			'imageBase64' => $image_base64,
			'mimeType'    => 'image/png',
		];

		foreach ( [ 'revisedPrompt', 'runtimeModel', 'model' ] as $key ) {
			$value = self::optional_string( $item[ $key ] ?? null );
			if ( '' !== $value ) {
				$normalized[ 'model' === $key ? 'runtimeModel' : $key ] = $value;
			}
		}

		$saved_path = self::optional_string( $item['savedPath'] ?? null );
		if ( '' !== $saved_path ) {
			$normalized['artifacts'] = [ 'savedPath' => $saved_path ];
		}

		return $normalized;
	}

	/**
	 * Maps token usage to the local runtime response shape.
	 *
	 * @param mixed $token_usage Raw token usage.
	 * @return array{inputTokens:int,outputTokens:int,reasoningOutputTokens:int}
	 */
	private static function extract_turn_token_usage( $token_usage ): array {
		$bucket = [];

		if ( is_array( $token_usage ) ) {
			$candidate = $token_usage['last'] ?? null;
			if ( ! is_array( $candidate ) ) {
				$candidate = $token_usage['total'] ?? null;
			}
			if ( is_array( $candidate ) ) {
				$bucket = $candidate;
			}
		}

		return [
			'inputTokens'           => self::non_negative_int( $bucket['inputTokens'] ?? null ),
			'outputTokens'          => self::non_negative_int( $bucket['outputTokens'] ?? null ),
			'reasoningOutputTokens' => self::non_negative_int( $bucket['reasoningOutputTokens'] ?? null ),
		];
	}

	/**
	 * Returns a non-negative int, otherwise zero.
	 *
	 * @param mixed $value Value.
	 * @return int
	 */
	private static function non_negative_int( $value ): int {
		return is_int( $value ) && $value >= 0 ? $value : 0;
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
