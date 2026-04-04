<?php
/**
 * Model metadata directory.
 *
 * @package AIProviderForCodex
 */

declare( strict_types=1 );

namespace AIProviderForCodex\Provider;

use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\SupportedOption;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;
use WordPress\AiClient\Messages\Enums\ModalityEnum;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides the current request's effective model list.
 */
final class ModelCatalog implements ModelMetadataDirectoryInterface {

	/**
	 * Cached catalog payload for the current request.
	 *
	 * @var array<string,mixed>|null
	 */
	private $catalog;

	/**
	 * Returns model metadata for all configured models.
	 *
	 * @return array<int,ModelMetadata>
	 */
	public function listModelMetadata(): array {
		return array_map( [ $this, 'create_metadata' ], $this->get_catalog()['models'] );
	}

	/**
	 * Whether a model exists in the current request's effective model set.
	 *
	 * @param string $model_id Model ID.
	 * @return bool
	 */
	public function hasModelMetadata( string $model_id ): bool {
		return in_array( $model_id, $this->get_catalog()['model_ids'], true );
	}

	/**
	 * Returns model metadata by ID.
	 *
	 * @param string $model_id Model ID.
	 * @return ModelMetadata
	 */
	public function getModelMetadata( string $model_id ): ModelMetadata {
		$model = $this->get_model_entry( $model_id );

		if ( ! is_array( $model ) ) {
			throw self::invalid_argument_exception(
				sprintf( 'Unknown Codex model "%s".', esc_html( $model_id ) )
			);
		}

		return $this->create_metadata( $model );
	}

	/**
	 * Builds a metadata object for a model.
	 *
	 * @param array{id:string,label:string} $model Model entry.
	 * @return ModelMetadata
	 */
	private function create_metadata( array $model ): ModelMetadata {
		return new ModelMetadata(
			$model['id'],
			$model['label'],
			[
				CapabilityEnum::textGeneration(),
				CapabilityEnum::chatHistory(),
			],
			[
				new SupportedOption( OptionEnum::inputModalities(), [ [ ModalityEnum::text() ] ] ),
				new SupportedOption( OptionEnum::outputModalities(), [ [ ModalityEnum::text() ] ] ),
				new SupportedOption( OptionEnum::systemInstruction() ),
				new SupportedOption( OptionEnum::outputMimeType(), [ 'text/plain', 'application/json' ] ),
				new SupportedOption( OptionEnum::outputSchema() ),
				new SupportedOption( OptionEnum::customOptions() ),
			]
		);
	}

	/**
	 * Returns the current request's catalog payload.
	 *
	 * @return array<string,mixed>
	 */
	private function get_catalog(): array {
		if ( null === $this->catalog ) {
			$this->catalog = ModelCatalogState::get_effective_catalog();
		}

		return $this->catalog;
	}

	/**
	 * Returns the model entry for a given ID.
	 *
	 * @param string $model_id Model ID.
	 * @return array{id:string,label:string}|null
	 */
	private function get_model_entry( string $model_id ): ?array {
		foreach ( $this->get_catalog()['models'] as $model ) {
			if ( is_array( $model ) && isset( $model['id'] ) && $model_id === $model['id'] ) {
				return $model;
			}
		}

		return null;
	}

	/**
	 * Creates an invalid-argument exception without tripping output sniffs.
	 *
	 * @param string $message Plain-text exception message.
	 * @return InvalidArgumentException
	 */
	private static function invalid_argument_exception( string $message ): InvalidArgumentException {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are escaped at the render boundary.
		return new InvalidArgumentException( $message );
	}
}
