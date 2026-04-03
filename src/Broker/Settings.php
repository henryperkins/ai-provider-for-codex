<?php
/**
 * Broker option helpers.
 *
 * @package AIProviderForCodex
 */

declare( strict_types=1 );

namespace AIProviderForCodex\Broker;

/**
 * Centralizes plugin option names and normalization.
 */
final class Settings {

	public const OPTION_BROKER_BASE_URL = 'codex_broker_base_url';
	public const OPTION_SITE_ID         = 'codex_broker_site_id';
	public const OPTION_SITE_SECRET     = 'codex_broker_site_secret';
	public const OPTION_DEFAULT_MODEL   = 'codex_broker_default_model';
	public const OPTION_ALLOWED_MODELS  = 'codex_broker_allowed_models';

	/**
	 * Default models used before a broker is available.
	 *
	 * @var string[]
	 */
	private const DEFAULT_ALLOWED_MODELS = [
		'gpt-5-codex',
		'gpt-5.3-codex',
	];

	/**
	 * Returns all plugin option names.
	 *
	 * @return string[]
	 */
	public static function option_names(): array {
		return [
			self::OPTION_BROKER_BASE_URL,
			self::OPTION_SITE_ID,
			self::OPTION_SITE_SECRET,
			self::OPTION_DEFAULT_MODEL,
			self::OPTION_ALLOWED_MODELS,
		];
	}

	/**
	 * Returns the configured broker base URL.
	 *
	 * @return string
	 */
	public static function get_base_url(): string {
		return untrailingslashit( (string) get_option( self::OPTION_BROKER_BASE_URL, '' ) );
	}

	/**
	 * Returns the configured site ID.
	 *
	 * @return string
	 */
	public static function get_site_id(): string {
		return (string) get_option( self::OPTION_SITE_ID, '' );
	}

	/**
	 * Returns the configured site secret.
	 *
	 * @return string
	 */
	public static function get_site_secret(): string {
		return (string) get_option( self::OPTION_SITE_SECRET, '' );
	}

	/**
	 * Returns the default model.
	 *
	 * @return string
	 */
	public static function get_default_model(): string {
		$model = (string) get_option( self::OPTION_DEFAULT_MODEL, self::DEFAULT_ALLOWED_MODELS[0] );

		return '' !== $model ? $model : self::DEFAULT_ALLOWED_MODELS[0];
	}

	/**
	 * Returns the configured model list.
	 *
	 * @return string[]
	 */
	public static function get_allowed_models(): array {
		return self::normalize_allowed_models(
			get_option( self::OPTION_ALLOWED_MODELS, self::DEFAULT_ALLOWED_MODELS )
		);
	}

	/**
	 * Returns whether the site has the minimum broker configuration.
	 *
	 * @return bool
	 */
	public static function has_required_site_configuration(): bool {
		return '' !== self::get_base_url() && '' !== self::get_site_id() && '' !== self::get_site_secret();
	}

	/**
	 * Stores the broker installation exchange response.
	 *
	 * @param array<string,mixed> $payload Broker installation payload.
	 * @return void
	 */
	public static function store_registration( array $payload ): void {
		if ( isset( $payload['brokerBaseUrl'] ) ) {
			update_option( self::OPTION_BROKER_BASE_URL, self::sanitize_base_url( (string) $payload['brokerBaseUrl'] ) );
		}

		if ( isset( $payload['siteId'] ) ) {
			update_option( self::OPTION_SITE_ID, sanitize_text_field( (string) $payload['siteId'] ) );
		}

		if ( isset( $payload['siteSecret'] ) ) {
			update_option( self::OPTION_SITE_SECRET, sanitize_text_field( (string) $payload['siteSecret'] ) );
		}

		if ( isset( $payload['defaultModel'] ) ) {
			update_option( self::OPTION_DEFAULT_MODEL, sanitize_text_field( (string) $payload['defaultModel'] ) );
		}

		if ( isset( $payload['allowedModels'] ) && is_array( $payload['allowedModels'] ) ) {
			update_option( self::OPTION_ALLOWED_MODELS, self::sanitize_allowed_models( $payload['allowedModels'] ) );
		}
	}

	/**
	 * Sanitizes the base URL option.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public static function sanitize_base_url( string $value ): string {
		$value = esc_url_raw( trim( $value ) );

		return '' !== $value ? untrailingslashit( $value ) : '';
	}

	/**
	 * Sanitizes the default model option.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public static function sanitize_default_model( string $value ): string {
		$value = sanitize_text_field( trim( $value ) );

		return '' !== $value ? $value : self::DEFAULT_ALLOWED_MODELS[0];
	}

	/**
	 * Sanitizes the allowed model setting.
	 *
	 * @param mixed $value Raw option value.
	 * @return string
	 */
	public static function sanitize_allowed_models( $value ): string {
		return self::serialize_allowed_models( self::normalize_allowed_models( $value ) );
	}

	/**
	 * Returns a textarea-ready value for allowed models.
	 *
	 * @return string
	 */
	public static function allowed_models_as_text(): string {
		return self::serialize_allowed_models( self::get_allowed_models() );
	}

	/**
	 * Normalizes raw input into a clean model ID list.
	 *
	 * @param mixed $value Raw option value.
	 * @return string[]
	 */
	private static function normalize_allowed_models( $value ): array {
		if ( is_array( $value ) ) {
			$models = $value;
		} else {
			$models = preg_split( '/[\r\n,]+/', (string) $value ) ?: [];
		}

		$models = array_values(
			array_unique(
				array_filter(
					array_map(
						static function ( $model ): string {
							return sanitize_text_field( (string) $model );
						},
						$models
					)
				)
			)
		);

		if ( [] === $models ) {
			return self::DEFAULT_ALLOWED_MODELS;
		}

		return $models;
	}

	/**
	 * Serializes a model list for textarea-backed option storage.
	 *
	 * @param string[] $models Normalized model IDs.
	 * @return string
	 */
	private static function serialize_allowed_models( array $models ): string {
		return implode( "\n", $models );
	}
}
