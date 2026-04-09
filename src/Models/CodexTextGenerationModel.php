<?php
/**
 * Local-runtime-backed Codex text model.
 *
 * @package AIProviderForCodex
 */

declare( strict_types=1 );

namespace AIProviderForCodex\Models;

use AIProviderForCodex\Auth\ConnectionRepository;
use AIProviderForCodex\Auth\ConnectionService;
use AIProviderForCodex\Provider\ModelCatalogState;
use AIProviderForCodex\Runtime\Client;
use AIProviderForCodex\Runtime\ResponseMapper;
use AIProviderForCodex\Runtime\RuntimeRequestException;
use RuntimeException;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiBasedModel;
use WordPress\AiClient\Providers\Models\TextGeneration\Contracts\TextGenerationModelInterface;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends text-generation requests to the local Codex runtime.
 */
final class CodexTextGenerationModel extends AbstractApiBasedModel implements TextGenerationModelInterface {

	/**
	 * Generates a text result through the local runtime API.
	 *
	 * @param array<int,Message> $prompt Prompt messages.
	 * @return GenerativeAiResult
	 */
	public function generateTextResult( array $prompt ): GenerativeAiResult {
		$wp_user_id = get_current_user_id();

		if ( $wp_user_id <= 0 ) {
			throw self::runtime_exception( esc_html__( 'Codex generation requires a logged-in WordPress user.', 'ai-provider-for-codex' ) );
		}

		$connection = ConnectionRepository::get_for_user( $wp_user_id );

		if ( ! $connection ) {
			throw self::runtime_exception( esc_html__( 'Connect a Codex account before requesting text generation.', 'ai-provider-for-codex' ) );
		}

		$catalog  = ModelCatalogState::get_effective_catalog( $wp_user_id );
		$model_id = $this->metadata()->getId();

		if ( [] !== $catalog['model_ids'] && ! in_array( $model_id, $catalog['model_ids'], true ) ) {
			throw self::runtime_exception(
				sprintf(
					/* translators: 1: requested model ID, 2: comma-separated available models */
					esc_html__(
						'The model "%1$s" is not available for your Codex account. Available models: %2$s.',
						'ai-provider-for-codex'
					),
					esc_html( $model_id ),
					esc_html( implode( ', ', ModelCatalogState::labels_from_catalog( $catalog ) ) )
				)
			);
		}

		$client = new Client();
		$config = $this->getConfig();

		try {
			$response = $client->post(
				'/v1/responses/text',
				array_filter(
					[
						'wpUserId'          => $wp_user_id,
						'requestId'         => wp_generate_uuid4(),
						'input'             => $this->flatten_prompt( $prompt ),
						'systemInstruction' => $config->getSystemInstruction(),
						'model'             => $model_id,
						'modelPreferences'  => [ $model_id ],
						'reasoningEffort'   => $this->extract_reasoning_effort(),
						'responseFormat'    => $this->build_response_format(),
						'context'           => [
							'surface'    => 'wordpress-ai-client',
							'pluginSlug' => 'ai-provider-for-codex',
						],
					],
					static function ( $value ): bool {
						return null !== $value && '' !== $value && [] !== $value;
					}
				)
			);
		} catch ( RuntimeRequestException $exception ) {
			if ( $exception->is_auth_required() ) {
				ConnectionService::invalidate_local_connection( $wp_user_id );
			}

			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are escaped at the render boundary.
			throw self::runtime_exception( $exception->getMessage() );
		}

		return ResponseMapper::to_generative_ai_result(
			$response,
			$this->providerMetadata(),
			$this->metadata()
		);
	}

	/**
	 * Flattens a prompt to the runtime text input field.
	 *
	 * @param array<int,Message> $prompt Prompt messages.
	 * @return string
	 */
	private function flatten_prompt( array $prompt ): string {
		$lines = [];

		foreach ( $prompt as $message ) {
			$parts = [];

			foreach ( $message->getParts() as $part ) {
				if ( null !== $part->getText() ) {
					$parts[] = $part->getText();
				}
			}

			if ( [] === $parts ) {
				continue;
			}

			$lines[] = strtoupper( $message->getRole()->value ) . ': ' . implode( "\n", $parts );
		}

		return implode( "\n\n", $lines );
	}

	/**
	 * Maps JSON output settings to the runtime contract.
	 *
	 * @return array<string,mixed>|null
	 */
	private function build_response_format(): ?array {
		$config = $this->getConfig();

		if ( 'application/json' !== $config->getOutputMimeType() || ! $config->getOutputSchema() ) {
			return null;
		}

		return [
			'type'   => 'json_schema',
			'schema' => $config->getOutputSchema(),
		];
	}

	/**
	 * Extracts a reasoning effort from custom options when present.
	 *
	 * @return string|null
	 */
	private function extract_reasoning_effort(): ?string {
		$custom_options = $this->getConfig()->getCustomOptions();

		if ( isset( $custom_options['reasoningEffort'] ) ) {
			return sanitize_text_field( (string) $custom_options['reasoningEffort'] );
		}

		if ( isset( $custom_options['reasoning_effort'] ) ) {
			return sanitize_text_field( (string) $custom_options['reasoning_effort'] );
		}

		return null;
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
