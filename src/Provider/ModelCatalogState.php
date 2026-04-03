<?php
/**
 * Effective model catalog helpers.
 *
 * @package AIProviderForCodex
 */

declare( strict_types=1 );

namespace AIProviderForCodex\Provider;

use AIProviderForCodex\Auth\ConnectionRepository;
use AIProviderForCodex\Auth\ConnectionSnapshotRepository;
use AIProviderForCodex\Runtime\Settings;

/**
 * Resolves the model list and selected model for the current user.
 *
	 * Two sources, no cascade:
	 *  1. User's local runtime snapshot (if the user has a linked account with models).
	 *  2. Settings fallback (admin-configured allowed-models list).
 *
 * Selected model: user's explicit choice (user meta) → first available.
 */
final class ModelCatalogState {

	private const USER_META_PREFERRED_MODEL = 'codex_provider_preferred_model';

	/**
	 * Returns the current user's effective model catalog.
	 *
	 * @param int|null $wp_user_id Optional user ID.
	 * @return array{
	 *     source:string,
	 *     selected_model:string,
	 *     checked_at:?string,
	 *     models:list<array{id:string,label:string}>,
	 *     model_ids:list<string>
	 * }
	 */
	public static function get_effective_catalog( ?int $wp_user_id = null ): array {
		$wp_user_id = $wp_user_id ?? get_current_user_id();

		if ( $wp_user_id > 0 ) {
			$catalog = self::get_user_snapshot_catalog( $wp_user_id );

			if ( [] !== $catalog['model_ids'] ) {
				return $catalog;
			}
		}

		return self::get_settings_catalog( $wp_user_id );
	}

	/**
	 * Returns the user's runtime-snapshot-backed catalog.
	 *
	 * @param int $wp_user_id User ID.
	 * @return array{
	 *     source:string,
	 *     selected_model:string,
	 *     checked_at:?string,
	 *     models:list<array{id:string,label:string}>,
	 *     model_ids:list<string>
	 * }
	 */
	public static function get_user_snapshot_catalog( int $wp_user_id ): array {
		$connection = ConnectionRepository::get_for_user( $wp_user_id );

		if ( ! $connection || empty( $connection['connection_id'] ) || ConnectionRepository::is_expired( $connection ) ) {
			return self::empty_catalog( 'user_snapshot' );
		}

		$snapshot = ConnectionSnapshotRepository::get( (string) $connection['connection_id'] );

		if ( ! $snapshot ) {
			return self::empty_catalog( 'user_snapshot' );
		}

		$models = self::normalize_models( $snapshot['models'] ?? [] );

		if ( [] === $models ) {
			return self::empty_catalog( 'user_snapshot' );
		}

		$model_ids = array_map(
			static function ( array $model ): string {
				return $model['id'];
			},
			$models
		);

		$selected = self::resolve_selected_model( $model_ids, $wp_user_id );
		$models   = self::prioritize_selected_model( $models, $selected );

		return [
			'source'         => 'user_snapshot',
			'selected_model' => $selected,
			'checked_at'     => ! empty( $snapshot['checked_at'] ) ? (string) $snapshot['checked_at'] : null,
			'models'         => $models,
			'model_ids'      => $model_ids,
		];
	}

	/**
	 * Returns human-readable labels from a catalog payload.
	 *
	 * @param array<string,mixed> $catalog Catalog payload.
	 * @return string[]
	 */
	public static function labels_from_catalog( array $catalog ): array {
		$models = $catalog['models'] ?? [];

		if ( ! is_array( $models ) ) {
			return [];
		}

		return array_values(
			array_filter(
				array_map(
					static function ( $model ): string {
						if ( is_array( $model ) && ! empty( $model['label'] ) ) {
							return (string) $model['label'];
						}

						if ( is_array( $model ) && ! empty( $model['id'] ) ) {
							return (string) $model['id'];
						}

						return '';
					},
					$models
				)
			)
		);
	}

	/**
	 * Returns the user's explicitly chosen model.
	 *
	 * @param int|null $wp_user_id Optional user ID.
	 * @return string
	 */
	public static function get_user_preferred_model( ?int $wp_user_id = null ): string {
		$wp_user_id = $wp_user_id ?? get_current_user_id();

		if ( $wp_user_id <= 0 ) {
			return '';
		}

		return sanitize_text_field( (string) get_user_meta( $wp_user_id, self::USER_META_PREFERRED_MODEL, true ) );
	}

	/**
	 * Stores the user's model choice.
	 *
	 * @param int    $wp_user_id User ID.
	 * @param string $model_id Model ID (empty string clears the choice).
	 * @return void
	 */
	public static function update_user_preferred_model( int $wp_user_id, string $model_id ): void {
		if ( $wp_user_id <= 0 ) {
			return;
		}

		$model_id = sanitize_text_field( $model_id );

		if ( '' === $model_id ) {
			delete_user_meta( $wp_user_id, self::USER_META_PREFERRED_MODEL );
			return;
		}

		update_user_meta( $wp_user_id, self::USER_META_PREFERRED_MODEL, $model_id );
	}

	/**
	 * Deletes the user's model choice.
	 *
	 * @param int $wp_user_id User ID.
	 * @return void
	 */
	public static function delete_user_preferred_model( int $wp_user_id ): void {
		if ( $wp_user_id <= 0 ) {
			return;
		}

		delete_user_meta( $wp_user_id, self::USER_META_PREFERRED_MODEL );
	}

	/**
	 * Returns a readable label for a model ID.
	 *
	 * @param string $model_id Model ID.
	 * @return string
	 */
	public static function label_for_model_id( string $model_id ): string {
		return str_replace(
			[ 'gpt-', '-codex' ],
			[ 'GPT-', ' Codex' ],
			$model_id
		);
	}

	/**
	 * Returns the settings-backed fallback catalog.
	 *
	 * @param int|null $wp_user_id Optional user ID for selected-model resolution.
	 * @return array{
	 *     source:string,
	 *     selected_model:string,
	 *     checked_at:?string,
	 *     models:list<array{id:string,label:string}>,
	 *     model_ids:list<string>
	 * }
	 */
	private static function get_settings_catalog( ?int $wp_user_id = null ): array {
		$models = array_map(
			static function ( string $model_id ): array {
				return [
					'id'    => $model_id,
					'label' => self::label_for_model_id( $model_id ),
				];
			},
			Settings::get_allowed_models()
		);

		$model_ids = array_map(
			static function ( array $model ): string {
				return $model['id'];
			},
			$models
		);

		$selected = self::resolve_selected_model( $model_ids, $wp_user_id );
		$models   = self::prioritize_selected_model( $models, $selected );

		return [
			'source'         => 'settings_fallback',
			'selected_model' => $selected,
			'checked_at'     => null,
			'models'         => $models,
			'model_ids'      => $model_ids,
		];
	}

	/**
	 * Normalizes raw model payload data into catalog entries.
	 *
	 * @param mixed $models Raw model list.
	 * @return list<array{id:string,label:string}>
	 */
	private static function normalize_models( $models ): array {
		if ( ! is_array( $models ) ) {
			return [];
		}

		$normalized = [];

		foreach ( $models as $model ) {
			$model_id = self::extract_model_id( $model );

			if ( '' === $model_id ) {
				continue;
			}

			$normalized[ $model_id ] = [
				'id'    => $model_id,
				'label' => self::extract_model_label( $model, $model_id ),
			];
		}

		return array_values( $normalized );
	}

	/**
	 * Extracts a model ID from a runtime payload item.
	 *
	 * @param mixed $model Raw model entry.
	 * @return string
	 */
	private static function extract_model_id( $model ): string {
		if ( is_string( $model ) ) {
			return sanitize_text_field( $model );
		}

		if ( is_array( $model ) ) {
			if ( isset( $model['id'] ) ) {
				return sanitize_text_field( (string) $model['id'] );
			}

			if ( isset( $model['model'] ) ) {
				return sanitize_text_field( (string) $model['model'] );
			}
		}

		return '';
	}

	/**
	 * Extracts a readable model label from a runtime payload item.
	 *
	 * @param mixed  $model Raw model entry.
	 * @param string $model_id Normalized model ID.
	 * @return string
	 */
	private static function extract_model_label( $model, string $model_id ): string {
		if ( is_array( $model ) ) {
			foreach ( [ 'label', 'name', 'displayName', 'title' ] as $key ) {
				if ( ! empty( $model[ $key ] ) ) {
					return sanitize_text_field( (string) $model[ $key ] );
				}
			}
		}

		return self::label_for_model_id( $model_id );
	}

	/**
	 * Resolves which model is selected.
	 *
	 * User's explicit choice wins. Falls back to first available.
	 *
	 * @param string[] $model_ids Available model IDs.
	 * @param int|null $wp_user_id Optional user ID.
	 * @return string
	 */
	private static function resolve_selected_model( array $model_ids, ?int $wp_user_id = null ): string {
		$preferred = self::get_user_preferred_model( $wp_user_id );

		if ( '' !== $preferred && in_array( $preferred, $model_ids, true ) ) {
			return $preferred;
		}

		return $model_ids[0] ?? '';
	}

	/**
	 * Reorders model entries so the selected model comes first.
	 *
	 * @param list<array{id:string,label:string}> $models Model entries.
	 * @param string                              $selected Selected model ID.
	 * @return list<array{id:string,label:string}>
	 */
	private static function prioritize_selected_model( array $models, string $selected ): array {
		if ( '' === $selected || [] === $models ) {
			return $models;
		}

		usort(
			$models,
			static function ( array $left, array $right ) use ( $selected ): int {
				if ( $left['id'] === $right['id'] ) {
					return 0;
				}

				if ( $left['id'] === $selected ) {
					return -1;
				}

				if ( $right['id'] === $selected ) {
					return 1;
				}

				return 0;
			}
		);

		return $models;
	}

	/**
	 * Returns an empty catalog shell.
	 *
	 * @param string $source Source identifier.
	 * @return array{
	 *     source:string,
	 *     selected_model:string,
	 *     checked_at:?string,
	 *     models:list<array{id:string,label:string}>,
	 *     model_ids:list<string>
	 * }
	 */
	private static function empty_catalog( string $source ): array {
		return [
			'source'         => $source,
			'selected_model' => '',
			'checked_at'     => null,
			'models'         => [],
			'model_ids'      => [],
		];
	}
}
