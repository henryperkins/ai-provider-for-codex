<?php
/**
 * Local-runtime-backed Codex image model.
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
use WordPress\AiClient\Providers\Models\ImageGeneration\Contracts\ImageGenerationModelInterface;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends image-generation requests to the local Codex runtime.
 */
final class CodexImageGenerationModel extends AbstractApiBasedModel implements ImageGenerationModelInterface {

	use LocalRuntimeModelTrait;

	/**
	 * Generates an image result through the local runtime API.
	 *
	 * @param array<int,\WordPress\AiClient\Messages\DTO\Message> $prompt Prompt messages.
	 * @return GenerativeAiResult
	 */
	public function generateImageResult( array $prompt ): GenerativeAiResult {
		$wp_user_id = get_current_user_id();

		if ( $wp_user_id <= 0 ) {
			throw self::runtime_exception( esc_html__( 'Codex image generation requires a logged-in WordPress user.', 'ai-provider-for-codex' ) );
		}

		$is_site_level_app_server = Settings::is_app_server_endpoint();
		$connection               = ConnectionRepository::get_for_user( $wp_user_id );

		if ( ! $is_site_level_app_server && ( ! $connection || ConnectionRepository::is_expired( $connection ) ) ) {
			throw self::runtime_exception( esc_html__( 'Connect a Codex account before requesting image generation.', 'ai-provider-for-codex' ) );
		}

		$catalog  = ModelCatalogState::get_effective_catalog( $wp_user_id );
		$model_id = $this->metadata()->getId();

		if (
			ModelCatalogState::IMAGE_MODEL_ID !== $model_id
			|| ! in_array( $model_id, $catalog['image_model_ids'], true )
		) {
			throw self::runtime_exception( esc_html__( 'Your linked Codex account does not currently report image generation support.', 'ai-provider-for-codex' ) );
		}

		$client     = new Client();
		$config     = $this->getConfig();
		$input_text = $this->flatten_prompt( $prompt );
		$started_at = hrtime( true );
		$body       = [
			'wpUserId'          => $wp_user_id,
			'requestId'         => wp_generate_uuid4(),
			'prompt'            => $input_text,
			'systemInstruction' => $config->getSystemInstruction(),
			'context'           => [
				'surface'    => 'wordpress-ai-client',
				'pluginSlug' => 'ai-provider-for-codex',
			],
		];

		if ( null === $body['systemInstruction'] || '' === $body['systemInstruction'] ) {
			unset( $body['systemInstruction'] );
		}

		try {
			$response = $client->post(
				'/v1/responses/image',
				$body
			);
		} catch ( RuntimeRequestException $exception ) {
			RequestLogWriter::record(
				RequestLogWriter::build_entry(
					[
						'status'        => 'error',
						'type'          => 'image',
						'operation'     => 'codex:responses/image',
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

		try {
			$result = ResponseMapper::to_image_generative_ai_result(
				$response,
				$this->providerMetadata(),
				$this->metadata()
			);
		} catch ( \RuntimeException | \InvalidArgumentException $exception ) {
			RequestLogWriter::record(
				RequestLogWriter::build_entry(
					[
						'status'        => 'error',
						'type'          => 'image',
						'operation'     => 'codex:responses/image',
						'model'         => $model_id,
						'duration_ms'   => self::elapsed_ms( $started_at ),
						'error_message' => $exception->getMessage(),
						'input_preview' => $input_text,
						'user_id'       => $wp_user_id,
					]
				)
			);

			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are escaped at the render boundary.
			throw self::runtime_exception( $exception->getMessage() );
		}

		RequestLogWriter::record(
			RequestLogWriter::build_entry(
				[
					'status'         => 'success',
					'type'           => 'image',
					'operation'      => 'codex:responses/image',
					'model'          => $model_id,
					'duration_ms'    => self::elapsed_ms( $started_at ),
					'tokens_input'   => (int) ( $response['usage']['inputTokens'] ?? 0 ),
					'tokens_output'  => (int) ( $response['usage']['outputTokens'] ?? 0 ),
					'request_id'     => (string) ( $response['requestId'] ?? '' ),
					'input_preview'  => $input_text,
					'output_preview' => self::image_output_preview( $response ),
					'user_id'        => $wp_user_id,
				]
			)
		);

		return $result;
	}

	/**
	 * Flattens prompt messages to a text-only image prompt.
	 *
	 * @param array<int,Message> $prompt Prompt messages.
	 * @return string
	 */
	private function flatten_prompt( array $prompt ): string {
		$lines = [];

		foreach ( $prompt as $message ) {
			$parts = [];

			foreach ( $message->getParts() as $part ) {
				if ( null !== $part->getFile() ) {
					throw self::runtime_exception( esc_html__( 'Reference images are not supported yet by the Codex image provider.', 'ai-provider-for-codex' ) );
				}

				if ( null !== $part->getFunctionCall() || null !== $part->getFunctionResponse() ) {
					throw self::runtime_exception( esc_html__( 'Tool-call message parts are not supported for Codex image generation.', 'ai-provider-for-codex' ) );
				}

				if ( null !== $part->getText() ) {
					$parts[] = $part->getText();
				}
			}

			if ( [] === $parts ) {
				continue;
			}

			$lines[] = implode( "\n", $parts );
		}

		$input_text = trim( implode( "\n\n", $lines ) );

		if ( '' === $input_text ) {
			throw self::runtime_exception( esc_html__( 'Codex image generation requires a text prompt.', 'ai-provider-for-codex' ) );
		}

		return $input_text;
	}

	/**
	 * Builds a safe request-log preview without image data or local paths.
	 *
	 * @param array<string,mixed> $response Runtime response.
	 * @return string
	 */
	private static function image_output_preview( array $response ): string {
		$mime_type = isset( $response['mimeType'] ) && '' !== (string) $response['mimeType']
			? (string) $response['mimeType']
			: 'image/png';
		$preview   = sprintf(
			/* translators: %s: image MIME type */
			__( 'Generated %s image.', 'ai-provider-for-codex' ),
			$mime_type
		);

		if ( isset( $response['revisedPrompt'] ) && '' !== (string) $response['revisedPrompt'] ) {
			$preview .= ' ' . sprintf(
				/* translators: %s: revised image prompt */
				__( 'Revised prompt: %s', 'ai-provider-for-codex' ),
				(string) $response['revisedPrompt']
			);
		}

		return $preview;
	}

}
