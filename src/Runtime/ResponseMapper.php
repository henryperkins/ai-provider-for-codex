<?php
/**
 * Local runtime response normalization helpers.
 *
 * @package AIProviderForCodex
 */

declare( strict_types=1 );

namespace AIProviderForCodex\Runtime;

use AIProviderForCodex\Auth\ConnectionRepository;
use AIProviderForCodex\Auth\ConnectionSnapshotRepository;
use RuntimeException;
use WordPress\AiClient\Files\DTO\File;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\ModelMessage;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Results\DTO\Candidate;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Results\DTO\TokenUsage;
use WordPress\AiClient\Results\Enums\FinishReasonEnum;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Maps runtime payloads into local storage and AI Client DTOs.
 */
final class ResponseMapper {

	/**
	 * Stores a runtime snapshot locally.
	 *
	 * @param int                 $wp_user_id User ID.
	 * @param string              $connection_id Local connection ID.
	 * @param array<string,mixed> $payload Runtime payload.
	 * @return void
	 */
	public static function store_connection_snapshot( int $wp_user_id, string $connection_id, array $payload ): void {
		$payload['connectionId'] = $connection_id;
		$payload['status']       = sanitize_text_field( (string) ( $payload['status'] ?? 'linked' ) );

		ConnectionRepository::upsert( $wp_user_id, $payload );
		ConnectionSnapshotRepository::upsert( $connection_id, $payload, 'ready' );
	}

	/**
	 * Creates a GenerativeAiResult from a runtime text response.
	 *
	 * @param array<string,mixed> $payload Runtime payload.
	 * @param ProviderMetadata    $provider_metadata Provider metadata.
	 * @param ModelMetadata       $model_metadata Model metadata.
	 * @return GenerativeAiResult
	 */
	public static function to_generative_ai_result(
		array $payload,
		ProviderMetadata $provider_metadata,
		ModelMetadata $model_metadata
	): GenerativeAiResult {
		$text = self::extract_output_text( $payload );

		$usage_input      = (int) ( $payload['usage']['inputTokens'] ?? 0 );
		$usage_completion = (int) ( $payload['usage']['outputTokens'] ?? 0 );
		$total_tokens     = $usage_input + $usage_completion;

		return new GenerativeAiResult(
			(string) ( $payload['requestId'] ?? wp_generate_uuid4() ),
			[
				new Candidate(
					new ModelMessage(
						[
							new MessagePart( $text ),
						]
					),
					self::finish_reason( $payload['finishReason'] ?? 'stop' )
				),
			],
			new TokenUsage( $usage_input, $usage_completion, $total_tokens ),
			$provider_metadata,
			$model_metadata,
			[
				'account'    => $payload['account'] ?? [],
				'rateLimits' => $payload['rateLimits'] ?? [],
			]
		);
	}

	/**
	 * Creates a GenerativeAiResult from a runtime image response.
	 *
	 * @param array<string,mixed> $payload Runtime payload.
	 * @param ProviderMetadata    $provider_metadata Provider metadata.
	 * @param ModelMetadata       $model_metadata Model metadata.
	 * @return GenerativeAiResult
	 */
	public static function to_image_generative_ai_result(
		array $payload,
		ProviderMetadata $provider_metadata,
		ModelMetadata $model_metadata
	): GenerativeAiResult {
		$image_base64 = isset( $payload['imageBase64'] ) ? (string) $payload['imageBase64'] : '';
		$image_base64 = (string) preg_replace( '/\s+/', '', $image_base64 );

		if ( '' === $image_base64 ) {
			throw self::runtime_exception( esc_html__( 'The local Codex runtime response did not include image data.', 'scriptorium-ai-provider-for-codex' ) );
		}

		$mime_type = isset( $payload['mimeType'] ) && '' !== (string) $payload['mimeType'] ? (string) $payload['mimeType'] : 'image/png';

		if ( 'image/png' !== $mime_type ) {
			throw self::runtime_exception( esc_html__( 'The local Codex runtime returned an unsupported image MIME type.', 'scriptorium-ai-provider-for-codex' ) );
		}

		$usage_input      = (int) ( $payload['usage']['inputTokens'] ?? 0 );
		$usage_completion = (int) ( $payload['usage']['outputTokens'] ?? 0 );
		$total_tokens     = $usage_input + $usage_completion;
		$additional_data  = [
			'account'    => $payload['account'] ?? [],
			'rateLimits' => $payload['rateLimits'] ?? [],
		];

		if ( isset( $payload['revisedPrompt'] ) && '' !== (string) $payload['revisedPrompt'] ) {
			$additional_data['revisedPrompt'] = (string) $payload['revisedPrompt'];
		}

		if ( isset( $payload['artifacts'] ) && is_array( $payload['artifacts'] ) ) {
			$additional_data['artifacts'] = $payload['artifacts'];
		}

		if ( isset( $payload['runtimeModel'] ) && '' !== (string) $payload['runtimeModel'] ) {
			$additional_data['runtimeModel'] = (string) $payload['runtimeModel'];
		}

		return new GenerativeAiResult(
			(string) ( $payload['requestId'] ?? wp_generate_uuid4() ),
			[
				new Candidate(
					new ModelMessage(
						[
							new MessagePart( new File( $image_base64, $mime_type ) ),
						]
					),
					self::finish_reason( $payload['finishReason'] ?? 'stop' )
				),
			],
			new TokenUsage( $usage_input, $usage_completion, $total_tokens ),
			$provider_metadata,
			$model_metadata,
			$additional_data
		);
	}

	/**
	 * Extracts text content from a runtime payload.
	 *
	 * @param array<string,mixed> $payload Runtime payload.
	 * @return string
	 */
	private static function extract_output_text( array $payload ): string {
		if ( isset( $payload['outputText'] ) && '' !== (string) $payload['outputText'] ) {
			return (string) $payload['outputText'];
		}

		if ( isset( $payload['structuredOutput'] ) ) {
			$json = wp_json_encode( $payload['structuredOutput'] );

			if ( false !== $json ) {
				return $json;
			}
		}

		throw self::runtime_exception( esc_html__( 'The local Codex runtime response did not include text output.', 'scriptorium-ai-provider-for-codex' ) );
	}

	/**
	 * Normalizes runtime finish reasons.
	 *
	 * @param string $value Raw finish reason.
	 * @return FinishReasonEnum
	 */
	private static function finish_reason( string $value ): FinishReasonEnum {
		switch ( $value ) {
			case 'length':
				return FinishReasonEnum::length();
			case 'content_filter':
				return FinishReasonEnum::contentFilter();
			case 'tool_calls':
				return FinishReasonEnum::toolCalls();
			case 'error':
				return FinishReasonEnum::error();
			case 'stop':
			default:
				return FinishReasonEnum::stop();
		}
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
