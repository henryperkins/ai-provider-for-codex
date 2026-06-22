<?php
/**
 * Standalone unit tests for RequestLogWriter.
 *
 * Pure logic only (no WordPress / AI-plugin dependency), so it runs fast:
 *
 *   php scripts/test-request-log-writer.php
 *
 * @package HtperkinsAIProviderForCodex
 */

declare( strict_types=1 );

// The class file guards on ABSPATH; satisfy it for standalone execution.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

require __DIR__ . '/../src/Logging/RequestLogWriter.php';

$codex_failures = 0;
$codex_tests    = 0;

/**
 * Minimal assertion helper.
 *
 * @param bool   $condition Result under test.
 * @param string $message   Description.
 */
$codex_assert = static function ( bool $condition, string $message ) use ( &$codex_failures, &$codex_tests ): void {
	++$codex_tests;
	if ( ! $condition ) {
		++$codex_failures;
		fwrite( STDERR, "FAIL: {$message}\n" );
	}
};

$writer = '\\Htperkins\AIProviderForCodex\\Logging\\RequestLogWriter';

// --- build_entry(): success shape ---------------------------------------
$entry = $writer::build_entry(
	array(
		'model'          => 'gpt-5-codex',
		'status'         => 'success',
		'duration_ms'    => 1234,
		'tokens_input'   => 10,
		'tokens_output'  => 20,
		'request_id'     => 'req-123',
		'input_preview'  => 'USER: hello',
		'output_preview' => 'hi there',
		'user_id'        => 7,
	)
);

$codex_assert( ( $entry['type'] ?? null ) === 'text', 'success: type is text' );
$codex_assert( ( $entry['operation'] ?? null ) === 'codex:responses/text', 'success: operation' );
$codex_assert( ( $entry['provider'] ?? null ) === 'codex', 'success: provider is codex' );
$codex_assert( ( $entry['status'] ?? null ) === 'success', 'success: status' );
$codex_assert( ( $entry['model'] ?? null ) === 'gpt-5-codex', 'success: model' );
$codex_assert( ( $entry['duration_ms'] ?? null ) === 1234, 'success: duration_ms (int)' );
$codex_assert( ( $entry['tokens_input'] ?? null ) === 10, 'success: tokens_input' );
$codex_assert( ( $entry['tokens_output'] ?? null ) === 20, 'success: tokens_output' );
$codex_assert( ( $entry['user_id'] ?? null ) === 7, 'success: user_id' );
$codex_assert( isset( $entry['context'] ) && is_array( $entry['context'] ), 'success: context is array' );
$codex_assert( ( $entry['context']['surface'] ?? null ) === 'wordpress-ai-client', 'success: context surface' );
$codex_assert( ( $entry['context']['input_preview'] ?? null ) === 'USER: hello', 'success: input_preview nested in context' );
$codex_assert( ( $entry['context']['output_preview'] ?? null ) === 'hi there', 'success: output_preview nested in context' );
$codex_assert( ( $entry['context']['request_id'] ?? null ) === 'req-123', 'success: request_id nested in context' );
$codex_assert( ! array_key_exists( 'error_message', $entry ), 'success: omits error_message' );

// --- build_entry(): image success shape -----------------------------------
$image_base64 = str_repeat( 'a', 120 );
$image        = $writer::build_entry(
	array(
		'type'           => 'image',
		'operation'      => 'codex:responses/image',
		'model'          => 'codex-image',
		'status'         => 'success',
		'duration_ms'    => 2345,
		'request_id'     => 'img-123',
		'input_preview'  => 'USER: Draw a blue circle.',
		'output_preview' => 'Generated image/png image. Revised prompt: A small blue circle.',
		'image_base64'   => $image_base64,
		'artifacts'      => array(
			'savedPath' => '/home/dev/.codex/generated_images/session/call.png',
		),
		'user_id'        => 7,
	)
);

$codex_assert( ( $image['type'] ?? null ) === 'image', 'image: type is image' );
$codex_assert( ( $image['operation'] ?? null ) === 'codex:responses/image', 'image: operation' );
$codex_assert( ( $image['model'] ?? null ) === 'codex-image', 'image: model' );
$codex_assert( ( $image['context']['output_preview'] ?? null ) === 'Generated image/png image. Revised prompt: A small blue circle.', 'image: output preview is safe text' );
$image_json = (string) json_encode( $image );
$codex_assert( false === strpos( $image_json, $image_base64 ), 'image: entry omits base64 payload' );
$codex_assert( false === strpos( $image_json, 'generated_images/session/call.png' ), 'image: entry omits local generated path' );

// --- build_entry(): error shape -----------------------------------------
$err = $writer::build_entry(
	array(
		'model'         => 'gpt-5-codex',
		'status'        => 'error',
		'duration_ms'   => 50,
		'error_message' => 'sidecar unreachable',
		'input_preview' => 'USER: hello',
		'user_id'       => 7,
	)
);

$codex_assert( ( $err['status'] ?? null ) === 'error', 'error: status' );
$codex_assert( ( $err['error_message'] ?? null ) === 'sidecar unreachable', 'error: error_message' );
$codex_assert( ! array_key_exists( 'tokens_input', $err ), 'error: omits tokens_input' );
$codex_assert( ! array_key_exists( 'tokens_output', $err ), 'error: omits tokens_output' );
$codex_assert( ! isset( $err['context']['output_preview'] ), 'error: omits output_preview' );
$codex_assert( ( $err['context']['input_preview'] ?? null ) === 'USER: hello', 'error: keeps input_preview' );

// --- build_entry(): omits absent optionals ------------------------------
$min = $writer::build_entry( array( 'status' => 'success' ) );

$codex_assert( ( $min['status'] ?? null ) === 'success', 'min: status' );
$codex_assert( ( $min['provider'] ?? null ) === 'codex', 'min: provider' );
$codex_assert( ! array_key_exists( 'model', $min ), 'min: omits model' );
$codex_assert( ! array_key_exists( 'duration_ms', $min ), 'min: omits duration_ms' );
$codex_assert( ! array_key_exists( 'tokens_input', $min ), 'min: omits tokens_input' );
$codex_assert( ! array_key_exists( 'user_id', $min ), 'min: omits user_id' );

// --- build_entry(): truncates oversized previews ------------------------
$big = $writer::build_entry(
	array(
		'status'        => 'success',
		'input_preview' => str_repeat( 'a', 5000 ),
	)
);

$codex_assert( strlen( (string) $big['context']['input_preview'] ) <= 2000, 'truncate: input_preview capped at 2000 chars' );

// --- record(): writes through an injected sink --------------------------
$captured = null;
$sink     = static function ( array $payload ) use ( &$captured ): void {
	$captured = $payload;
};
$result = $writer::record( array( 'status' => 'success', 'type' => 'text', 'operation' => 'codex:responses/text' ), $sink );

$codex_assert( true === $result, 'record: returns true when sink succeeds' );
$codex_assert( is_array( $captured ) && 'success' === ( $captured['status'] ?? null ), 'record: passes entry to sink' );

// --- record(): swallows sink failures -----------------------------------
$threw  = false;
$result = false;
try {
	$result = $writer::record(
		array( 'status' => 'error' ),
		static function ( array $payload ): void {
			throw new RuntimeException( 'boom' );
		}
	);
} catch ( \Throwable $e ) {
	$threw = true;
}

$codex_assert( false === $threw, 'record: never lets a sink exception escape' );
$codex_assert( false === $result, 'record: returns false when sink throws' );

echo 0 === $codex_failures
	? "OK: {$codex_tests} assertions passed\n"
	: "{$codex_failures}/{$codex_tests} assertions FAILED\n";

exit( 0 === $codex_failures ? 0 : 1 );
