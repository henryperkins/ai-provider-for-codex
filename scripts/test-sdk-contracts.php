<?php
/**
 * Offline regressions for the provider's PHP AI Client contracts.
 *
 * Run: php scripts/test-sdk-contracts.php
 * Optional CODEX_SDK_TEST_ROOT selects an isolated SDK source tree; the default
 * is vendor/wordpress/php-ai-client. CODEX_SDK_TEST_VENDOR_AUTOLOAD may select a
 * dependency autoloader without replacing this checkout's SDK or provider code.
 * WordPress persistence and HTTP are inert fixtures; models, SDK DTOs, request
 * builders, Runtime Client, and ResponseMapper are the real implementations.
 *
 * @package AIProviderForCodex
 */

declare( strict_types=1 );

namespace AIProviderForCodex\Auth {

	final class ConnectionRepository {
		public static function get_for_user( int $user_id ): ?array {
			return [ 'connection_id' => 'fixture', 'status' => 'linked' ];
		}
		public static function is_expired( array $connection ): bool {
			return false;
		}
	}
}

namespace AIProviderForCodex\Provider {

	final class ModelCatalogState {
		public const IMAGE_MODEL_ID = 'codex-image';
		public static function get_effective_catalog( ?int $user_id = null ): array {
			return [
				'models'          => [
					[ 'id' => 'gpt-6.1-sol', 'label' => 'Fixture Text', 'kind' => 'text' ],
					[ 'id' => 'codex-image', 'label' => 'Fixture Image', 'kind' => 'image' ],
				],
				'model_ids'       => [ 'gpt-6.1-sol', 'codex-image' ],
				'text_model_ids'  => [ 'gpt-6.1-sol' ],
				'image_model_ids' => [ 'codex-image' ],
			];
		}
	}
}

namespace AIProviderForCodex\Runtime {

	final class Settings {
		public static function get_base_url(): string { return 'http://127.0.0.1:4317'; }
		public static function get_bearer_token(): string { return 'inert-fixture'; }
		public static function has_required_configuration(): bool { return true; }
	}
	final class HealthMonitor {
		public static function is_available(): bool { return true; }
		public static function record_success(): void {}
		public static function record_failure( string $message ): void {}
		public static function record_connector_unapproved( string $message ): void {}
	}
}

namespace AIProviderForCodex\Logging {

	final class RequestLogWriter {
		public static function build_entry( array $entry ): array { return $entry; }
		public static function record( array $entry ): bool { return true; }
	}
}

namespace {

	use AIProviderForCodex\Provider\CodexProvider;
	use WordPress\AiClient\AiClient;
	use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
	use WordPress\AiClient\Providers\Models\DTO\ModelConfig;

	define( 'ABSPATH', __DIR__ . '/' );
	define( 'WP_PLUGIN_DIR', dirname( __DIR__, 2 ) );
	define( 'AIProviderForCodex\\PLUGIN_FILE', dirname( __DIR__ ) . '/scriptorium-ai-provider-for-codex.php' );
	define( 'AIProviderForCodex\\SLUG', 'scriptorium-ai-provider-for-codex' );

	function __( string $text, string $domain = '' ): string { return $text; }
	function esc_html__( string $text, string $domain = '' ): string { return $text; }
	function esc_html( string $text ): string { return $text; }
	function admin_url( string $path = '' ): string { return 'https://example.invalid/wp-admin/' . $path; }
	function plugin_basename( string $path ): string { return 'scriptorium-ai-provider-for-codex/scriptorium-ai-provider-for-codex.php'; }
	function get_current_user_id(): int { return 123; }
	function wp_generate_uuid4(): string { return '00000000-0000-4000-a000-000000000000'; }
	function wp_json_encode( $value ) { return json_encode( $value ); }
	function apply_filters( string $tag, $value, ...$args ) { return $value; }
	function sanitize_key( string $text ): string { return strtolower( (string) preg_replace( '/[^a-zA-Z0-9_\-]/', '', $text ) ); }
	function sanitize_text_field( string $text ): string { return $text; }
	function is_wp_error( $value ): bool { return false; }
	function wp_remote_retrieve_response_code( array $response ): int { return $response['response']['code']; }
	function wp_remote_retrieve_body( array $response ): string { return $response['body']; }
	function wp_remote_retrieve_response_message( array $response ): string { return $response['response']['message']; }

	$GLOBALS['codex_sdk_http_captures'] = [];
	$GLOBALS['codex_sdk_usage']         = [ 'inputTokens' => 20, 'outputTokens' => 10, 'reasoningOutputTokens' => 3 ];

	function wp_remote_request( string $url, array $args ): array {
		$body = json_decode( (string) ( $args['body'] ?? '' ), true );
		$GLOBALS['codex_sdk_http_captures'][] = [ 'url' => $url, 'args' => $args, 'body' => $body ];
		$payload = [ 'requestId' => 'fixture', 'usage' => $GLOBALS['codex_sdk_usage'], 'finishReason' => 'stop' ];
		if ( '/v1/responses/image' === substr( $url, -19 ) ) {
			$payload['imageBase64'] = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9Zl1kAAAAASUVORK5CYII=';
			$payload['mimeType']    = 'image/png';
		} else {
			$payload['outputText'] = $GLOBALS['codex_sdk_text_output'] ?? ( isset( $body['responseFormat'] ) ? '{"answer":"fixture"}' : 'fixture text' );
		}
		return [ 'response' => [ 'code' => 200, 'message' => 'OK' ], 'body' => json_encode( $payload ) ];
	}

	$codex_plugin_root = dirname( __DIR__ );
	$codex_vendor_autoload = getenv( 'CODEX_SDK_TEST_VENDOR_AUTOLOAD' ) ?: $codex_plugin_root . '/vendor/autoload.php';
	$codex_sdk_root = getenv( 'CODEX_SDK_TEST_ROOT' ) ?: dirname( $codex_vendor_autoload ) . '/wordpress/php-ai-client';
	if ( ! is_file( $codex_vendor_autoload ) || ! is_file( $codex_sdk_root . '/src/AiClient.php' ) ) {
		fwrite( STDERR, "Install development dependencies or select the SDK fixture with CODEX_SDK_TEST_ROOT and CODEX_SDK_TEST_VENDOR_AUTOLOAD.\n" );
		exit( 1 );
	}
	require $codex_vendor_autoload;
	// Prepend both namespaces so an external dependency autoloader cannot load an
	// older provider checkout or an unintended SDK version.
	spl_autoload_register(
		static function ( string $class ) use ( $codex_sdk_root, $codex_plugin_root ): void {
			foreach ( [ 'WordPress\\AiClient\\' => $codex_sdk_root . '/src/', 'AIProviderForCodex\\' => $codex_plugin_root . '/src/' ] as $prefix => $directory ) {
				if ( 0 === strpos( $class, $prefix ) ) {
					$file = $directory . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
					if ( is_file( $file ) ) { require $file; }
					return;
				}
			}
		},
		true,
		true
	);
	require_once $codex_sdk_root . '/src/polyfills.php';

	$codex_checks   = 0;
	$codex_failures = 0;
	function codex_assert( bool $condition, string $message ): void {
		++$GLOBALS['codex_checks'];
		if ( ! $condition ) { throw new \RuntimeException( $message ); }
	}
	function codex_case( string $name, callable $test ): void {
		try {
			$test();
			echo "PASS: {$name}\n";
		} catch ( \Throwable $exception ) {
			++$GLOBALS['codex_failures'];
			fwrite( STDERR, "FAIL: {$name}: {$exception->getMessage()}\n" );
		}
	}
	function codex_capture(): array {
		return end( $GLOBALS['codex_sdk_http_captures'] );
	}
	function codex_rejects_before_http( callable $call, string $message_fragment ): void {
		$before = count( $GLOBALS['codex_sdk_http_captures'] );
		$error = null;
		try { $call(); } catch ( \RuntimeException $exception ) { $error = $exception; }
		codex_assert( null !== $error && false !== stripos( $error->getMessage(), $message_fragment ), 'expected explicit ' . $message_fragment . ' error' );
		codex_assert( $before === count( $GLOBALS['codex_sdk_http_captures'] ), 'invalid request reached HTTP' );
	}

	echo 'ENVIRONMENT ' . json_encode( [ 'phpVersion' => PHP_VERSION, 'sdkVersion' => AiClient::VERSION, 'sdkSource' => ( new \ReflectionClass( AiClient::class ) )->getFileName(), 'providerSource' => ( new \ReflectionClass( \AIProviderForCodex\Models\CodexTextGenerationModel::class ) )->getFileName() ] ) . "\n";
	$registry = AiClient::defaultRegistry();
	$registry->setHttpTransporter( new class implements \WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface {
		public function send( \WordPress\AiClient\Providers\Http\DTO\Request $request, ?RequestOptions $options = null ): \WordPress\AiClient\Providers\Http\DTO\Response {
			throw new \LogicException( 'A localhost provider must not use the SDK HTTP transporter in these offline tests.' );
		}
	} );
	$registry->registerProvider( CodexProvider::class );

	codex_case( 'plain text retains generation timeout without JSON constraints', static function (): void {
		$result = AiClient::prompt( 'fixture' )->usingProvider( 'codex' )->generateTextResult();
		$sent = codex_capture();
		codex_assert( 'fixture text' === $result->toText(), 'plain output changed' );
		codex_assert( ! isset( $sent['body']['responseFormat'] ), 'plain text acquired JSON constraints' );
		codex_assert( 360 === $sent['args']['timeout'], 'default generation timeout changed' );
	} );
	codex_case( 'JSON without a schema reaches the valid-JSON runtime mode', static function (): void {
		$prompt = AiClient::prompt( 'fixture' )->usingProvider( 'codex' )->asJsonResponse();
		codex_assert( $prompt->isSupportedForTextGeneration(), 'valid-JSON mode is absent from model metadata' );
		$result = $prompt->generateTextResult();
		codex_assert( [ 'type' => 'json_object' ] === ( codex_capture()['body']['responseFormat'] ?? null ), 'schema-less JSON mode was dropped' );
		codex_assert( '{"answer":"fixture"}' === $result->toText(), 'valid JSON response was not mapped' );
	} );
	codex_case( 'empty JSON schema uses valid-JSON mode rather than invalid schema', static function (): void {
		AiClient::prompt( 'fixture' )->usingProvider( 'codex' )->asJsonResponse( [] )->generateTextResult();
		codex_assert( [ 'type' => 'json_object' ] === ( codex_capture()['body']['responseFormat'] ?? null ), 'empty schema was dropped or sent as an invalid schema' );
	} );
	foreach ( [ '[]', '"fixture"', '42', 'false', 'null' ] as $json_text ) {
		codex_case( 'schema-less JSON accepts valid value ' . $json_text, static function () use ( $json_text ): void {
			$GLOBALS['codex_sdk_text_output'] = $json_text;
			try {
				$result = AiClient::prompt( 'fixture' )->usingProvider( 'codex' )->asJsonResponse()->generateTextResult();
				codex_assert( $json_text === $result->toText(), 'valid JSON value changed' );
			} finally { unset( $GLOBALS['codex_sdk_text_output'] ); }
		} );
	}
	codex_case( 'JSON request rejects plaintext returned by the runtime', static function (): void {
		$GLOBALS['codex_sdk_text_output'] = 'invalid JSON fixture';
		$error = null;
		try {
			AiClient::prompt( 'fixture' )->usingProvider( 'codex' )->asJsonResponse()->generateTextResult();
		} catch ( \RuntimeException $exception ) {
			$error = $exception;
		} finally { unset( $GLOBALS['codex_sdk_text_output'] ); }
		codex_assert( null !== $error && false !== stripos( $error->getMessage(), 'valid JSON' ), 'plaintext was accepted as a JSON result' );
	} );
	codex_case( 'explicit JSON schema reaches the runtime unchanged', static function (): void {
		$schema = [ 'type' => 'object', 'properties' => [ 'answer' => [ 'type' => 'string' ] ], 'required' => [ 'answer' ], 'additionalProperties' => false ];
		$result = AiClient::prompt( 'fixture' )->usingProvider( 'codex' )->asJsonResponse( $schema )->generateTextResult();
		codex_assert( [ 'type' => 'json_schema', 'schema' => $schema ] === codex_capture()['body']['responseFormat'], 'JSON schema was changed or lost' );
		codex_assert( '{"answer":"fixture"}' === $result->toText(), 'schema-constrained response was not returned' );
	} );
	foreach ( [ 'max', 'ultra' ] as $effort ) {
		codex_case( 'advertised ' . $effort . ' reasoning effort reaches runtime', static function () use ( $effort ): void {
			$config = new ModelConfig();
			$config->setCustomOptions( [ 'reasoningEffort' => $effort ] );
			AiClient::prompt( 'fixture' )->usingProvider( 'codex' )->usingModelConfig( $config )->generateTextResult();
			codex_assert( $effort === ( codex_capture()['body']['reasoningEffort'] ?? null ), 'advertised effort was silently dropped' );
		} );
	}
	codex_case( 'malformed reasoning effort is explicitly rejected before HTTP', static function (): void {
		$config = new ModelConfig();
		$config->setCustomOptions( [ 'reasoningEffort' => 'high!' ] );
		codex_rejects_before_http( static function () use ( $config ): void { AiClient::prompt( 'fixture' )->usingProvider( 'codex' )->usingModelConfig( $config )->generateTextResult(); }, 'reasoning effort' );
	} );
	foreach ( [ 'text', 'image' ] as $kind ) {
		codex_case( $kind . ' preserves SDK timeout precision and zero redirects', static function () use ( $kind ): void {
			$options = new RequestOptions();
			$options->setTimeout( 4.5 );
			$options->setMaxRedirects( 0 );
			$prompt = AiClient::prompt( 'fixture' )->usingProvider( 'codex' )->usingRequestOptions( $options );
			if ( 'image' === $kind ) { $prompt->generateImageResult(); } else { $prompt->generateTextResult(); }
			codex_assert( 4.5 === codex_capture()['args']['timeout'], 'SDK timeout was ignored or rounded' );
			codex_assert( 0 === ( codex_capture()['args']['redirection'] ?? null ), 'SDK zero redirect limit was ignored' );
		} );
		codex_case( $kind . ' preserves explicit zero timeout and positive redirect count', static function () use ( $kind ): void {
			$options = new RequestOptions();
			$options->setTimeout( 0.0 );
			$options->setMaxRedirects( 2 );
			$prompt = AiClient::prompt( 'fixture' )->usingProvider( 'codex' )->usingRequestOptions( $options );
			if ( 'image' === $kind ) { $prompt->generateImageResult(); } else { $prompt->generateTextResult(); }
			codex_assert( 0.0 === codex_capture()['args']['timeout'], 'explicit zero timeout was replaced' );
			codex_assert( 2 === ( codex_capture()['args']['redirection'] ?? null ), 'redirect limit changed' );
		} );
		codex_case( $kind . ' unset SDK options retain provider defaults', static function () use ( $kind ): void {
			$prompt = AiClient::prompt( 'fixture' )->usingProvider( 'codex' )->usingRequestOptions( new RequestOptions() );
			if ( 'image' === $kind ) { $prompt->generateImageResult(); } else { $prompt->generateTextResult(); }
			codex_assert( 360 === codex_capture()['args']['timeout'], 'unset SDK timeout lost provider default' );
			codex_assert( ! isset( codex_capture()['args']['redirection'] ), 'unset SDK redirects changed WordPress default' );
		} );
		codex_case( $kind . ' reasoning tokens are detail without double-counted totals', static function () use ( $kind ): void {
			$GLOBALS['codex_sdk_usage'] = [ 'inputTokens' => 20, 'outputTokens' => 10, 'reasoningOutputTokens' => 3 ];
			$prompt = AiClient::prompt( 'fixture' )->usingProvider( 'codex' );
			$result = 'image' === $kind ? $prompt->generateImageResult() : $prompt->generateTextResult();
			$usage = $result->getTokenUsage();
			codex_assert( 3 === $usage->getThoughtTokens(), 'reasoning detail was discarded' );
			codex_assert( 20 === $usage->getPromptTokens() && 10 === $usage->getCompletionTokens() && 30 === $usage->getTotalTokens(), 'reasoning detail changed inclusive output/total counts' );
		} );
		codex_case( $kind . ' absent reasoning usage remains unknown', static function () use ( $kind ): void {
			$GLOBALS['codex_sdk_usage'] = [ 'inputTokens' => 20, 'outputTokens' => 10 ];
			$prompt = AiClient::prompt( 'fixture' )->usingProvider( 'codex' );
			$result = 'image' === $kind ? $prompt->generateImageResult() : $prompt->generateTextResult();
			codex_assert( null === $result->getTokenUsage()->getThoughtTokens(), 'absent reasoning detail became a fabricated count' );
		} );
		codex_case( $kind . ' explicit zero reasoning usage is retained', static function () use ( $kind ): void {
			$GLOBALS['codex_sdk_usage'] = [ 'inputTokens' => 20, 'outputTokens' => 10, 'reasoningOutputTokens' => 0 ];
			$prompt = AiClient::prompt( 'fixture' )->usingProvider( 'codex' );
			$result = 'image' === $kind ? $prompt->generateImageResult() : $prompt->generateTextResult();
			codex_assert( 0 === $result->getTokenUsage()->getThoughtTokens(), 'explicit zero reasoning detail was discarded' );
		} );
	}

	echo "TOTAL {$codex_checks} assertions; {$codex_failures} failing cases\n";
	exit( $codex_failures ? 1 : 0 );
}
