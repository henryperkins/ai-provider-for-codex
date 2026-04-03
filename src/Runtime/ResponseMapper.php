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
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\ModelMessage;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Results\DTO\Candidate;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Results\DTO\TokenUsage;
use WordPress\AiClient\Results\Enums\FinishReasonEnum;

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
		$usage_reasoning  = isset( $payload['usage']['reasoningTokens'] ) ? (int) $payload['usage']['reasoningTokens'] : null;
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
			new TokenUsage( $usage_input, $usage_completion, $total_tokens, $usage_reasoning ),
			$provider_metadata,
			$model_metadata,
			[
				'account'    => $payload['account'] ?? [],
				'rateLimits' => $payload['rateLimits'] ?? [],
			]
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

		throw new RuntimeException( __( 'The local Codex runtime response did not include text output.', 'ai-provider-for-codex' ) );
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
}
