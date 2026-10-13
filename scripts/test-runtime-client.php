<?php
/**
 * Standalone runtime HTTP boundary regressions. No network requests are sent.
 *
 * Run: php scripts/test-runtime-client.php
 * Optional SDK autoloader override: CODEX_PROVIDER_SDK_AUTOLOAD.
 *
 * @package AIProviderForCodex
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
$codex_sdk_autoload = getenv( 'CODEX_PROVIDER_SDK_AUTOLOAD' ) ?: __DIR__ . '/../vendor/autoload.php';
require $codex_sdk_autoload;
require __DIR__ . '/../src/Runtime/RuntimeRequestException.php';

class Codex_Runtime_Settings_Fixture {
	public static function get_base_url(): string { return 'http://127.0.0.1:4317'; }
	public static function has_required_configuration(): bool { return true; }
	public static function get_bearer_token(): string { return 'isolated-inert-test-token'; }
	public static function configuration_metadata(): array { return array( 'base_url_source' => 'fixture', 'bearer_token_source' => 'fixture' ); }
}

class Codex_Runtime_Health_Fixture {
	public static $status = '';
	public static function record_failure( string $message ): void { self::$status = 'unreachable'; }
	public static function record_connector_unapproved( string $message ): void { self::$status = 'connector_unapproved'; }
	public static function record_success(): void { self::$status = 'healthy'; }
}

class_alias( Codex_Runtime_Settings_Fixture::class, 'AIProviderForCodex\\Runtime\\Settings' );
class_alias( Codex_Runtime_Health_Fixture::class, 'AIProviderForCodex\\Runtime\\HealthMonitor' );

class WP_Error {
	private $code;
	private $message;
	private $data;
	public function __construct( string $code, string $message, array $data = array() ) { $this->code = $code; $this->message = $message; $this->data = $data; }
	public function get_error_code(): string { return $this->code; }
	public function get_error_message(): string { return $this->message; }
	public function get_error_data(): array { return $this->data; }
}

class WP_REST_Response {
	private $data;
	public function __construct( array $data ) { $this->data = $data; }
	public function get_data(): array { return $this->data; }
}

define( 'HOUR_IN_SECONDS', 3600 );
function set_transient( $name, $value, $expiration ) { return true; }

$codex_reply = array( 'body' => '{"outputText":"fixture"}', 'response' => array( 'code' => 200, 'message' => 'OK' ) );
$codex_last_args = array();
function wp_remote_request( $url, $args ) { global $codex_reply, $codex_last_args; $codex_last_args = $args; return $codex_reply; }
function is_wp_error( $value ): bool { return $value instanceof WP_Error; }
function wp_remote_retrieve_response_code( $response ) { return $response['response']['code']; }
function wp_remote_retrieve_response_message( $response ) { return $response['response']['message']; }
function wp_remote_retrieve_body( $response ) { return $response['body']; }
function wp_json_encode( $value ) { return json_encode( $value ); }
function apply_filters( $name, $value, ...$args ) { return $value; }
function __( $message, $domain = '' ) { return $message; }
function esc_html__( $message, $domain = '' ) { return $message; }
function esc_html( $message ) { return htmlspecialchars( $message, ENT_QUOTES, 'UTF-8' ); }
function sanitize_text_field( $message ) { return strip_tags( $message ); }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $value ) ); }
function wp_parse_url( $url, $component ) { return parse_url( $url, $component ); }

require __DIR__ . '/../src/Runtime/Client.php';
require __DIR__ . '/../src/REST/DiagnosticsController.php';

$codex_failures = 0;
$codex_tests = 0;
$codex_assert = static function ( bool $condition, string $message ) use ( &$codex_failures, &$codex_tests ): void {
	++$codex_tests;
	if ( ! $condition ) { ++$codex_failures; fwrite( STDERR, "FAIL: {$message}\n" ); }
};
$client = new AIProviderForCodex\Runtime\Client();
$exception_class = AIProviderForCodex\Runtime\RuntimeRequestException::class;

foreach ( array(
	array( new WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect' ), 'http_request_failed', 'unreachable' ),
	array( new WP_Error( 'wpai_connector_not_approved', 'Caller blocked', array( 'connector_id' => 'codex', 'caller' => array( 'basename' => 'fixture/fixture.php' ) ) ), 'wpai_connector_not_approved', 'connector_unapproved' ),
) as $case ) {
	$codex_reply = $case[0];
	$caught = null;
	try { $client->post( '/v1/responses/text', array( 'input' => 'fixture' ) ); } catch ( Throwable $error ) { $caught = $error; }
	$codex_assert( $caught instanceof $exception_class, 'transport: failure reaches the model logging catch as RuntimeRequestException' );
	if ( $caught instanceof $exception_class ) {
		$codex_assert( 0 === $caught->get_status_code(), 'transport: no HTTP response is represented by status zero' );
		$codex_assert( $case[1] === $caught->get_runtime_error_code(), 'transport: original WordPress error code is preserved' );
		$codex_assert( $case[0]->get_error_message() === $caught->get_runtime_message(), 'transport: original cause is preserved' );
		$codex_assert( false === $caught->is_auth_required(), 'transport: transport failure does not clear account authentication' );
	}
	$codex_assert( $case[2] === Codex_Runtime_Health_Fixture::$status, 'transport: existing health classification is preserved' );
}

$codex_reply = array( 'body' => 'invalid body with sensitive-test-marker', 'response' => array( 'code' => 200, 'message' => 'OK' ) );
$caught = null;
try { $client->post( '/v1/responses/image', array( 'prompt' => 'fixture' ) ); } catch ( Throwable $error ) { $caught = $error; }
$codex_assert( $caught instanceof $exception_class, 'JSON: invalid response reaches the model logging catch as RuntimeRequestException' );
if ( $caught instanceof $exception_class ) {
	$codex_assert( 'invalid_json' === $caught->get_runtime_error_code(), 'JSON: invalid body has a stable error code' );
	$codex_assert( 200 === $caught->get_status_code(), 'JSON: response status is retained' );
	$codex_assert( array() === $caught->get_payload(), 'JSON: invalid body is excluded from exception payload' );
	$codex_assert( false === strpos( $caught->getMessage(), 'sensitive-test-marker' ), 'JSON: raw invalid body does not leak through the displayed error' );
}

// A sidecar generation-policy refusal keeps its code and message and never clears authentication.
$codex_reply = array(
	'body'     => json_encode(
		array(
			'error' => array(
				'code'    => 'runtime_policy_rejected',
				'message' => 'Codex did not apply the sidecar generation policy. Check the Codex version against the qualified release.',
				'data'    => array( 'approvalPolicy' => 'never', 'sandboxType' => null, 'environmentCount' => null ),
			),
		)
	),
	'response' => array( 'code' => 502, 'message' => 'Bad Gateway' ),
);
$caught = null;
try { $client->post( '/v1/responses/text', array( 'input' => 'fixture' ) ); } catch ( Throwable $error ) { $caught = $error; }
$codex_assert( $caught instanceof $exception_class, 'policy: refusal reaches the model logging catch as RuntimeRequestException' );
if ( $caught instanceof $exception_class ) {
	$codex_assert( 502 === $caught->get_status_code(), 'policy: HTTP status is retained' );
	$codex_assert( 'runtime_policy_rejected' === $caught->get_runtime_error_code(), 'policy: sidecar error code passes through unchanged' );
	$codex_assert( false !== strpos( $caught->getMessage(), 'did not apply the sidecar generation policy' ), 'policy: sidecar message passes through unchanged' );
	$codex_assert( false === $caught->is_auth_required(), 'policy: refusal does not clear account authentication' );
}

$codex_reply = array( 'body' => '{"outputText":"fixture"}', 'response' => array( 'code' => 200, 'message' => 'OK' ) );
$client->post( '/v1/responses/text', array() );
$codex_assert( 360 === $codex_last_args['timeout'], 'options: unspecified generation timeout retains the existing default' );
$options = new WordPress\AiClient\Providers\Http\DTO\RequestOptions();
$options->setTimeout( 7.25 );
$options->setMaxRedirects( 0 );
$client->post( '/v1/responses/text', array(), $options );
$codex_assert( 7.25 === $codex_last_args['timeout'], 'options: explicit SDK timeout preserves fractional seconds' );
$codex_assert( 0 === ( $codex_last_args['redirection'] ?? null ), 'options: explicit SDK redirect prohibition reaches WordPress HTTP' );
$options->setTimeout( 0.0 );
$options->setMaxRedirects( 2 );
$client->post( '/v1/responses/image', array(), $options );
$codex_assert( 0.0 === $codex_last_args['timeout'], 'options: explicit zero timeout is not replaced by the default' );
$codex_assert( 2 === ( $codex_last_args['redirection'] ?? null ), 'options: positive redirect limit reaches WordPress HTTP' );
$options->setTimeout( null );
$options->setMaxRedirects( null );
$client->post( '/v1/responses/image', array(), $options );
$codex_assert( 360 === $codex_last_args['timeout'], 'options: null SDK timeout preserves generation default' );
$codex_assert( ! isset( $codex_last_args['redirection'] ), 'options: null redirect count preserves WordPress HTTP default' );

// Diagnostics must distinguish a failed transport from an answered HTTP request.
$codex_reply = new WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect' );
$diagnostics = AIProviderForCodex\REST\DiagnosticsController::run()->get_data();
$codex_assert( false === $diagnostics['ok'], 'diagnostics: transport failure fails the verdict' );
$codex_assert( 'fail' === $diagnostics['rows'][0]['status'], 'diagnostics: status-zero transport failure marks reachability failed' );
$codex_assert( false !== strpos( $diagnostics['rows'][0]['detail'], 'not reachable at 127.0.0.1:4317' ), 'diagnostics: normalized transport detail identifies the unreachable service' );
$codex_assert( false !== strpos( $diagnostics['rows'][0]['detail'], 'Start the sidecar service' ), 'diagnostics: transport detail retains actionable service guidance' );
$codex_assert( 1 === count( $diagnostics['rows'] ), 'diagnostics: transport failure does not add a misleading sidecar-response row' );

foreach ( array( 401 => 'unauthorized', 404 => 'not_found', 500 => 'internal_error' ) as $status => $code ) {
	$codex_reply = array( 'body' => json_encode( array( 'error' => array( 'code' => $code, 'message' => 'Fixture response' ) ) ), 'response' => array( 'code' => $status, 'message' => 'Fixture' ) );
	$diagnostics = AIProviderForCodex\REST\DiagnosticsController::run()->get_data();
	$codex_assert( 'pass' === $diagnostics['rows'][0]['status'], "diagnostics: answered HTTP {$status} proves reachability" );
	$expected_row = 401 === $status ? 'bearer' : ( 404 === $status ? 'endpoint' : 'sidecar_error' );
	$codex_assert( $expected_row === $diagnostics['rows'][1]['id'], "diagnostics: HTTP {$status} preserves its response classification" );
}

$codex_reply = array( 'body' => 'malformed fixture', 'response' => array( 'code' => 200, 'message' => 'OK' ) );
$diagnostics = AIProviderForCodex\REST\DiagnosticsController::run()->get_data();
$codex_assert( 'pass' === $diagnostics['rows'][0]['status'], 'diagnostics: malformed JSON with HTTP 200 still proves reachability' );

echo 0 === $codex_failures ? "OK: {$codex_tests} assertions passed\n" : "{$codex_failures}/{$codex_tests} assertions FAILED\n";
exit( 0 === $codex_failures ? 0 : 1 );
