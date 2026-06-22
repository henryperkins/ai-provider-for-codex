<?php
/**
 * Local-runtime-backed Codex text model.
 *
 * @package HtperkinsAIProviderForCodex
 */

declare( strict_types=1 );

namespace Htperkins\AIProviderForCodex\Models;

use Htperkins\AIProviderForCodex\Auth\ConnectionRepository;
use Htperkins\AIProviderForCodex\Auth\ConnectionService;
use Htperkins\AIProviderForCodex\Logging\RequestLogWriter;
use Htperkins\AIProviderForCodex\Provider\ModelCatalogState;
use Htperkins\AIProviderForCodex\Runtime\Client;
use Htperkins\AIProviderForCodex\Runtime\ResponseMapper;
use Htperkins\AIProviderForCodex\Runtime\RuntimeRequestException;
use Htperkins\AIProviderForCodex\Runtime\Settings;
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

	use LocalRuntimeModelTrait;

	private const REASONING_EFFORTS = [ 'none', 'minimal', 'low', 'medium', 'high', 'xhigh' ];

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

		$is_site_level_app_server = Settings::is_app_server_endpoint();
		$connection               = ConnectionRepository::get_for_user( $wp_user_id );

		if ( ! $is_site_level_app_server && ( ! $connection || ConnectionRepository::is_expired( $connection ) ) ) {
			throw self::runtime_exception( esc_html__( 'Connect a Codex account before requesting text generation.', 'ai-provider-for-codex' ) );
		}

		$catalog  = ModelCatalogState::get_effective_catalog( $wp_user_id );
		$model_id = $this->metadata()->getId();

		if ( [] !== $catalog['text_model_ids'] && ! in_array( $model_id, $catalog['text_model_ids'], true ) ) {
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

		$client       = new Client();
		$config       = $this->getConfig();
		$input_text   = $this->flatten_prompt( $prompt );
		$input_images = $this->extract_image_inputs( $prompt );
		$started_at   = hrtime( true );

		try {
			$response = $client->post(
				'/v1/responses/text',
				array_filter(
					[
						'wpUserId'          => $wp_user_id,
						'requestId'         => wp_generate_uuid4(),
						'input'             => $input_text,
						'inputImages'       => $input_images,
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
			RequestLogWriter::record(
				RequestLogWriter::build_entry(
					[
						'status'        => 'error',
						'model'         => $model_id,
						'duration_ms'   => self::elapsed_ms( $started_at ),
						'error_message' => $exception->getMessage(),
						'input_preview' => $input_text,
						'user_id'       => $wp_user_id,
					]
				)
			);

			if ( ! $is_site_level_app_server && $exception->is_auth_required() ) {
				ConnectionService::invalidate_local_connection( $wp_user_id );
			}

			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are escaped at the render boundary.
			throw self::runtime_exception( $exception->getMessage() );
		}

		$result = ResponseMapper::to_generative_ai_result(
			$response,
			$this->providerMetadata(),
			$this->metadata()
		);

		RequestLogWriter::record(
			RequestLogWriter::build_entry(
				[
					'status'         => 'success',
					'model'          => $model_id,
					'duration_ms'    => self::elapsed_ms( $started_at ),
					'tokens_input'   => (int) ( $response['usage']['inputTokens'] ?? 0 ),
					// outputTokens already includes reasoning tokens (Codex mirrors the
					// OpenAI Responses convention where reasoningOutputTokens is a subset
					// of outputTokens), so do not add reasoningOutputTokens here.
					'tokens_output'  => (int) ( $response['usage']['outputTokens'] ?? 0 ),
					'request_id'     => (string) ( $response['requestId'] ?? '' ),
					'input_preview'  => $input_text,
					'output_preview' => $result->toText(),
					'user_id'        => $wp_user_id,
				]
			)
		);

		return $result;
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
	 * Extracts image file parts for the local runtime text input contract.
	 *
	 * @param array<int,Message> $prompt Prompt messages.
	 * @return list<array{url:string}>
	 */
	private function extract_image_inputs( array $prompt ): array {
		$images = [];

		foreach ( $prompt as $message ) {
			foreach ( $message->getParts() as $part ) {
				$file = $part->getFile();

				if ( null === $file ) {
					continue;
				}

				if ( ! $file->isImage() ) {
					throw self::runtime_exception(
						sprintf(
							/* translators: %s: MIME type. */
							esc_html__( 'Codex text generation only supports image file inputs, not "%s".', 'ai-provider-for-codex' ),
							esc_html( $file->getMimeType() )
						)
					);
				}

				$url = $file->isRemote() ? $file->getUrl() : $file->getDataUri();

				if ( ! is_string( $url ) || '' === $url ) {
					throw self::runtime_exception( esc_html__( 'Codex text generation could not read the image input.', 'ai-provider-for-codex' ) );
				}

				$images[] = [ 'url' => $url ];
			}
		}

		return $images;
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
	 * Extracts a reasoning effort from AI Client config when present.
	 *
	 * @return string|null
	 */
	private function extract_reasoning_effort(): ?string {
		$config         = $this->getConfig();
		$custom_options = $config->getCustomOptions();

		foreach ( [ 'reasoningEffort', 'reasoning_effort', 'reasoning' ] as $key ) {
			if ( ! isset( $custom_options[ $key ] ) ) {
				continue;
			}

			$reasoning_effort = self::normalize_reasoning_effort( $custom_options[ $key ] );

			if ( null !== $reasoning_effort ) {
				return $reasoning_effort;
			}
		}

		foreach ( [ 'getReasoningEffort', 'getReasoning' ] as $method ) {
			if ( ! is_callable( [ $config, $method ] ) ) {
				continue;
			}

			$reasoning_effort = self::normalize_reasoning_effort( call_user_func( [ $config, $method ] ) );

			if ( null !== $reasoning_effort ) {
				return $reasoning_effort;
			}
		}

		return null;
	}

	/**
	 * Normalizes supported runtime reasoning effort values.
	 *
	 * @param mixed $value Raw reasoning value.
	 * @return string|null
	 */
	private static function normalize_reasoning_effort( $value ): ?string {
		if ( is_array( $value ) ) {
			return isset( $value['effort'] )
				? self::normalize_reasoning_effort( $value['effort'] )
				: null;
		}

		if ( is_object( $value ) ) {
			if ( is_callable( [ $value, 'getEffort' ] ) ) {
				return self::normalize_reasoning_effort( call_user_func( [ $value, 'getEffort' ] ) );
			}

			$properties = get_object_vars( $value );

			return isset( $properties['effort'] )
				? self::normalize_reasoning_effort( $properties['effort'] )
				: null;
		}

		if ( ! is_scalar( $value ) ) {
			return null;
		}

		$value = sanitize_key( (string) $value );

		return in_array( $value, self::REASONING_EFFORTS, true ) ? $value : null;
	}

}
