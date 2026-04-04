<?php
/**
 * Local runtime option helpers.
 *
 * @package AIProviderForCodex
 */

declare( strict_types=1 );

namespace AIProviderForCodex\Runtime;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Centralizes plugin option names and normalization.
 */
final class Settings {

	public const OPTION_RUNTIME_BASE_URL   = 'codex_runtime_base_url';
	public const OPTION_RUNTIME_BEARER     = 'codex_runtime_bearer_token';
	public const OPTION_ALLOWED_MODELS     = 'codex_runtime_allowed_models';
	public const DEFAULT_RUNTIME_BASE_URL  = 'http://127.0.0.1:4317';
	private const DEFAULT_SHARED_ENV_FILE  = '/etc/codex-wp-sidecar.env';

	/**
	 * Default models used before a user connects an account.
	 *
	 * @var string[]
	 */
	private const DEFAULT_ALLOWED_MODELS = [
		'gpt-5-codex',
		'gpt-5.3-codex',
	];

	/**
	 * Parsed shared runtime config cache.
	 *
	 * @var array<string,string>|null
	 */
	private static $shared_env_config;

	/**
	 * Returns all runtime option names.
	 *
	 * @return string[]
	 */
	public static function option_names(): array {
		return [
			self::OPTION_RUNTIME_BASE_URL,
			self::OPTION_RUNTIME_BEARER,
			self::OPTION_ALLOWED_MODELS,
		];
	}

	/**
	 * Returns the configured local runtime base URL.
	 *
	 * @return string
	 */
	public static function get_base_url(): string {
		$override = self::base_url_override();

		if ( '' !== $override['value'] ) {
			return self::normalize_base_url_value( $override['value'] );
		}

		$value = self::stored_option( self::OPTION_RUNTIME_BASE_URL, self::DEFAULT_RUNTIME_BASE_URL );

		return self::normalize_base_url_value( $value );
	}

	/**
	 * Returns the configured local runtime bearer token.
	 *
	 * @return string
	 */
	public static function get_bearer_token(): string {
		$override = self::setting_override( 'CODEX_WP_BEARER_TOKEN' );

		if ( '' !== $override['value'] ) {
			return self::normalize_bearer_token_value( $override['value'] );
		}

		return self::normalize_bearer_token_value( self::stored_option( self::OPTION_RUNTIME_BEARER, '' ) );
	}

	/**
	 * Returns the configured fallback model list.
	 *
	 * @return string[]
	 */
	public static function get_allowed_models(): array {
		return self::normalize_allowed_models(
			get_option( self::OPTION_ALLOWED_MODELS, self::DEFAULT_ALLOWED_MODELS )
		);
	}

	/**
	 * Returns whether the site has the minimum local runtime configuration.
	 *
	 * @return bool
	 */
	public static function has_required_configuration(): bool {
		return '' !== self::get_base_url() && '' !== self::get_bearer_token();
	}

	/**
	 * Sanitizes the runtime base URL option.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_base_url( $value ): string {
		if ( self::is_base_url_managed_externally() ) {
			return self::stored_option( self::OPTION_RUNTIME_BASE_URL, self::DEFAULT_RUNTIME_BASE_URL );
		}

		if ( null === $value || ( ! is_scalar( $value ) && ! $value instanceof \Stringable ) ) {
			return self::stored_option( self::OPTION_RUNTIME_BASE_URL, self::DEFAULT_RUNTIME_BASE_URL );
		}

		return self::normalize_base_url_value( (string) $value );
	}

	/**
	 * Sanitizes the bearer token option.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_bearer_token( $value ): string {
		if ( self::is_bearer_token_managed_externally() ) {
			return self::stored_option( self::OPTION_RUNTIME_BEARER, '' );
		}

		if ( null === $value || ( ! is_scalar( $value ) && ! $value instanceof \Stringable ) ) {
			return self::stored_option( self::OPTION_RUNTIME_BEARER, '' );
		}

		return self::normalize_bearer_token_value( (string) $value );
	}

	/**
	 * Returns the current runtime configuration metadata.
	 *
	 * @return array{
	 *     base_url_managed:bool,
	 *     base_url_source:string,
	 *     bearer_token_managed:bool,
	 *     bearer_token_source:string,
	 *     shared_env_file:string
	 * }
	 */
	public static function configuration_metadata(): array {
		$base_url_override = self::base_url_override();
		$bearer_override   = self::setting_override( 'CODEX_WP_BEARER_TOKEN' );

		return [
			'base_url_managed'     => '' !== $base_url_override['value'],
			'base_url_source'      => self::override_label(
				$base_url_override,
				__( 'Saved in WordPress options.', 'ai-provider-for-codex' )
			),
			'bearer_token_managed' => '' !== $bearer_override['value'],
			'bearer_token_source'  => self::override_label(
				$bearer_override,
				__( 'Saved in WordPress options.', 'ai-provider-for-codex' )
			),
			'shared_env_file'      => self::shared_env_file(),
		];
	}

	/**
	 * Returns whether the runtime URL is managed outside WordPress.
	 *
	 * @return bool
	 */
	public static function is_base_url_managed_externally(): bool {
		return '' !== self::base_url_override()['value'];
	}

	/**
	 * Returns whether the runtime bearer token is managed outside WordPress.
	 *
	 * @return bool
	 */
	public static function is_bearer_token_managed_externally(): bool {
		return '' !== self::setting_override( 'CODEX_WP_BEARER_TOKEN' )['value'];
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

	/**
	 * Returns a raw stored option value.
	 *
	 * @param string $name Option name.
	 * @param string $default Default value.
	 * @return string
	 */
	private static function stored_option( string $name, string $default = '' ): string {
		return (string) get_option( $name, $default );
	}

	/**
	 * Normalizes a runtime base URL without touching stored options.
	 *
	 * @param string $value Raw URL.
	 * @return string
	 */
	private static function normalize_base_url_value( string $value ): string {
		$value = esc_url_raw( trim( $value ) );

		if ( '' === $value ) {
			return '';
		}

		return untrailingslashit( $value );
	}

	/**
	 * Normalizes a runtime bearer token without touching stored options.
	 *
	 * @param string $value Raw token.
	 * @return string
	 */
	private static function normalize_bearer_token_value( string $value ): string {
		return sanitize_text_field( trim( $value ) );
	}

	/**
	 * Resolves a runtime URL override from constants, env vars, or the shared env file.
	 *
	 * @return array{value:string,source:string,detail:string}
	 */
	private static function base_url_override(): array {
		$override = self::setting_override( 'CODEX_WP_RUNTIME_BASE_URL' );

		if ( '' !== $override['value'] ) {
			return $override;
		}

		$host = self::setting_override( 'CODEX_WP_HOST' );

		if ( '' === $host['value'] ) {
			return self::empty_override();
		}

		$port   = self::setting_override( 'CODEX_WP_PORT' );
		$target = 'http://' . $host['value'];
		$port_value = preg_replace( '/[^0-9]/', '', $port['value'] );

		if ( is_string( $port_value ) && '' !== $port_value ) {
			$target .= ':' . $port_value;
		}

		return [
			'value'  => $target,
			'source' => $host['source'],
			'detail' => $host['detail'],
		];
	}

	/**
	 * Resolves an override for a runtime setting.
	 *
	 * @param string $key Env/config key.
	 * @return array{value:string,source:string,detail:string}
	 */
	private static function setting_override( string $key ): array {
		if ( defined( $key ) ) {
			$value = sanitize_text_field( (string) constant( $key ) );

			if ( '' !== $value ) {
				return [
					'value'  => $value,
					'source' => 'constant',
					'detail' => $key,
				];
			}
		}

		$value = getenv( $key );

		if ( false === $value && isset( $_ENV[ $key ] ) ) {
			$value = sanitize_text_field( (string) $_ENV[ $key ] );
		}

		if ( is_string( $value ) && '' !== trim( $value ) ) {
			return [
				'value'  => sanitize_text_field( trim( $value ) ),
				'source' => 'env',
				'detail' => $key,
			];
		}

		$file_value = self::shared_env_config()[ $key ] ?? '';

		if ( '' !== $file_value ) {
			return [
				'value'  => sanitize_text_field( $file_value ),
				'source' => 'file',
				'detail' => self::shared_env_file(),
			];
		}

		return self::empty_override();
	}

	/**
	 * Returns a human-readable label for an override source.
	 *
	 * @param array{value:string,source:string,detail:string} $override Override payload.
	 * @param string                                          $fallback Fallback label.
	 * @return string
	 */
	private static function override_label( array $override, string $fallback ): string {
		switch ( $override['source'] ) {
			case 'constant':
				return sprintf(
					/* translators: %s: PHP constant name. */
					__( 'Managed by the `%s` constant.', 'ai-provider-for-codex' ),
					$override['detail']
				);
			case 'env':
				return sprintf(
					/* translators: %s: environment variable name. */
					__( 'Managed by the `%s` environment variable.', 'ai-provider-for-codex' ),
					$override['detail']
				);
			case 'file':
				return sprintf(
					/* translators: %s: absolute shared env file path. */
					__( 'Auto-detected from `%s`.', 'ai-provider-for-codex' ),
					$override['detail']
				);
			default:
				return $fallback;
		}
	}

	/**
	 * Returns the shared sidecar env file path.
	 *
	 * @return string
	 */
	private static function shared_env_file(): string {
		$constant_name = 'CODEX_WP_RUNTIME_ENV_FILE';

		if ( defined( $constant_name ) ) {
			$value = sanitize_text_field( (string) constant( $constant_name ) );

			if ( '' !== $value ) {
				return $value;
			}
		}

		$value = getenv( $constant_name );

		if ( false === $value && isset( $_ENV[ $constant_name ] ) ) {
			$value = sanitize_text_field( (string) $_ENV[ $constant_name ] );
		}

		if ( is_string( $value ) && '' !== trim( $value ) ) {
			return sanitize_text_field( trim( $value ) );
		}

		return self::DEFAULT_SHARED_ENV_FILE;
	}

	/**
	 * Parses the shared sidecar env file.
	 *
	 * @return array<string,string>
	 */
	private static function shared_env_config(): array {
		if ( null !== self::$shared_env_config ) {
			return self::$shared_env_config;
		}

		$path = self::shared_env_file();

		if ( '' === $path || ! is_readable( $path ) ) {
			self::$shared_env_config = [];
			return self::$shared_env_config;
		}

		$lines = file( $path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );

		if ( false === $lines ) {
			self::$shared_env_config = [];
			return self::$shared_env_config;
		}

		$config = [];

		foreach ( $lines as $line ) {
			$line = trim( (string) $line );

			if ( '' === $line || str_starts_with( $line, '#' ) ) {
				continue;
			}

			if ( str_starts_with( $line, 'export ' ) ) {
				$line = trim( substr( $line, 7 ) );
			}

			$parts = explode( '=', $line, 2 );

			if ( 2 !== count( $parts ) ) {
				continue;
			}

			$key = sanitize_key( strtoupper( trim( $parts[0] ) ) );

			if ( '' === $key ) {
				continue;
			}

			$value = trim( $parts[1] );

			if (
				strlen( $value ) >= 2
				&& (
					( '"' === $value[0] && '"' === substr( $value, -1 ) )
					|| ( "'" === $value[0] && "'" === substr( $value, -1 ) )
				)
			) {
				$value = substr( $value, 1, -1 );
			}

			$config[ strtoupper( $key ) ] = $value;
		}

		self::$shared_env_config = $config;

		return self::$shared_env_config;
	}

	/**
	 * Returns an empty override payload.
	 *
	 * @return array{value:string,source:string,detail:string}
	 */
	private static function empty_override(): array {
		return [
			'value'  => '',
			'source' => '',
			'detail' => '',
		];
	}
}
