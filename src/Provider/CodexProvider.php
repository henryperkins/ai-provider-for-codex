<?php
/**
 * WordPress AI Client provider registration.
 *
 * @package AIProviderForCodex
 */

declare( strict_types=1 );

namespace AIProviderForCodex\Provider;

use AIProviderForCodex\Models\CodexTextGenerationModel;
use AIProviderForCodex\Runtime\Settings;
use WordPress\AiClient\AiClient;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiProvider;
use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;
use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;

/**
 * Codex provider scaffold.
 */
final class CodexProvider extends AbstractApiProvider {

	/**
	 * Returns the configured local runtime base URL.
	 *
	 * @return string
	 */
	protected static function baseUrl(): string {
		return Settings::get_base_url();
	}

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
		$provider_metadata_args = [
			'codex',
			'Codex',
			ProviderTypeEnum::cloud(),
			\AIProviderForCodex\PLUGIN_URI,
			null,
		];

		// Provider description support was added in php-ai-client 1.2.0.
		if ( version_compare( AiClient::VERSION, '1.2.0', '>=' ) ) {
			$provider_metadata_args[] = __( 'AI text generation through a localhost Codex sidecar. Configure the shared runtime, then each user connects their own Codex or ChatGPT account.', 'ai-provider-for-codex' );
		}

		// Provider logoPath support was added in php-ai-client 1.3.0.
		// Build the path through WP_PLUGIN_DIR + plugin_basename so the WP core resolver
		// (_wp_connectors_resolve_ai_provider_logo_url) accepts it even when the plugin
		// is loaded via a symlink — PHP's __DIR__ always returns the realpath.
		if ( version_compare( AiClient::VERSION, '1.3.0', '>=' ) ) {
			$provider_dir = WP_PLUGIN_DIR . '/' . dirname( plugin_basename( \AIProviderForCodex\PLUGIN_FILE ) );
			$provider_metadata_args[] = $provider_dir . '/src/Provider/logo.svg';
		}

		return new ProviderMetadata( ...$provider_metadata_args );
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
