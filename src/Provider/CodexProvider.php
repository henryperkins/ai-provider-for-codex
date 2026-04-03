<?php
/**
 * WordPress AI Client provider registration.
 *
 * @package AIProviderForCodex
 */

declare( strict_types=1 );

namespace AIProviderForCodex\Provider;

use AIProviderForCodex\Models\CodexTextGenerationModel;
use WordPress\AiClient\AiClient;
use WordPress\AiClient\Providers\AbstractProvider;
use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;
use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;

/**
 * Codex provider scaffold.
 */
final class CodexProvider extends AbstractProvider {

	/**
	 * Creates a model instance.
	 *
	 * @param ModelMetadata    $model_metadata Model metadata.
	 * @param ProviderMetadata $provider_metadata Provider metadata.
	 * @return ModelInterface
	 */
	protected static function createModel( ModelMetadata $model_metadata, ProviderMetadata $provider_metadata ): ModelInterface {
		return new CodexTextGenerationModel( $model_metadata, $provider_metadata );
	}

	/**
	 * Creates provider metadata.
	 *
	 * @return ProviderMetadata
	 */
	protected static function createProviderMetadata(): ProviderMetadata {
		$provider_metadata = [
			'codex',
			'Codex',
			ProviderTypeEnum::cloud(),
			\AIProviderForCodex\PLUGIN_URI,
			null,
		];

		if ( version_compare( AiClient::VERSION, '1.2.0', '>=' ) ) {
			$provider_metadata[] = __( 'Broker-backed Codex provider for the WordPress AI Client.', 'ai-provider-for-codex' );
		}

		if ( version_compare( AiClient::VERSION, '1.3.0', '>=' ) ) {
			$provider_metadata[] = __DIR__ . '/logo.svg';
		}

		return new ProviderMetadata( ...$provider_metadata );
	}

	/**
	 * Creates provider availability.
	 *
	 * @return ProviderAvailabilityInterface
	 */
	protected static function createProviderAvailability(): ProviderAvailabilityInterface {
		return new CodexProviderAvailability();
	}

	/**
	 * Creates the model metadata directory.
	 *
	 * @return ModelMetadataDirectoryInterface
	 */
	protected static function createModelMetadataDirectory(): ModelMetadataDirectoryInterface {
		return new ModelCatalog();
	}
}
