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
use AIProviderForCodex\Broker\Client;
use AIProviderForCodex\Broker\Settings;
use RuntimeException;

/**
 * Normalizes model data for per-user and site-scoped catalog views.
 */
final class ModelCatalogState {

	private const REFRESH_TTL           = 10 * MINUTE_IN_SECONDS;
	private const REFRESH_TRANSIENT_KEY = 'codex_provider_site_catalog_refresh_attempt';
	private const USER_META_PREFERRED_MODEL = 'codex_provider_preferred_model';

	/**
	 * Returns the current request's effective model catalog.
	 *
	 * Prefers the current user's broker snapshot when available, then falls back
	 * to the site-wide snapshot aggregate, and finally the settings-backed
	 * bootstrap list.
	 *
	 * @param int|null $wp_user_id Optional user ID.
	 * @return array{
	 *     source:string,
	 *     default_model:string,
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

		return self::get_site_catalog();
	}

	/**
	 * Returns the site-scoped model catalog.
	 *
	 * Aggregates all active broker snapshots so the AI Client has a broker-backed
	 * site catalog even when no current user context is available. When linked
	 * account snapshots are missing or stale, the plugin refreshes them from the
	 * broker's per-user `/v1/wordpress/models` endpoint before aggregating them.
	 *
	 * @return array{
	 *     source:string,
	 *     default_model:string,
	 *     checked_at:?string,
	 *     models:list<array{id:string,label:string}>,
	 *     model_ids:list<string>
	 * }
	 */
	public static function get_site_catalog(): array {
		$active_connections = ConnectionRepository::list_active_for_site_catalog();
		$stored_catalog     = self::get_snapshot_aggregate_catalog();

		if ( [] === $active_connections ) {
			return [] !== $stored_catalog['model_ids'] ? $stored_catalog : self::get_settings_catalog();
		}

		if (
			Settings::has_required_site_configuration() &&
			self::site_catalog_requires_refresh( $active_connections ) &&
			! self::has_recent_refresh_attempt()
		) {
			self::record_refresh_attempt();

			$live_catalog = self::refresh_site_catalog_from_broker( $active_connections );

			if ( [] !== $live_catalog['model_ids'] ) {
				return $live_catalog;
			}

			$stored_catalog = self::get_snapshot_aggregate_catalog();
		}

		if ( [] !== $stored_catalog['model_ids'] ) {
			return $stored_catalog;
		}

		return self::get_settings_catalog();
	}

	/**
	 * Returns the current user's stored snapshot-backed catalog.
	 *
	 * @param int $wp_user_id User ID.
	 * @return array{
	 *     source:string,
	 *     default_model:string,
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

		$model_ids = array_values(
			array_map(
				static function ( array $model ): string {
					return $model['id'];
				},
				$models
			)
		);

		$default_model = self::resolve_default_model(
			$model_ids,
			[
				(string) ( $snapshot['default_model'] ?? '' ),
			],
			$wp_user_id
		);
		$models        = self::prioritize_default_model( $models, $default_model );

		return [
			'source'        => 'user_snapshot',
			'default_model' => $default_model,
			'checked_at'    => ! empty( $snapshot['checked_at'] ) ? (string) $snapshot['checked_at'] : null,
			'models'        => $models,
			'model_ids'     => $model_ids,
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
	 * Returns the current user's preferred model when one is stored.
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
	 * Stores the current user's preferred model.
	 *
	 * @param int    $wp_user_id User ID.
	 * @param string $model_id Preferred model ID.
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
	 * Deletes the current user's preferred model.
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
	 * Returns a readable label for a catalog source.
	 *
	 * @param string $source Source identifier.
	 * @return string
	 */
	public static function label_for_source( string $source ): string {
		switch ( $source ) {
			case 'user_snapshot':
				return __( 'Current account snapshot', 'ai-provider-for-codex' );
			case 'site_broker_aggregate':
				return __( 'Broker-refreshed site aggregate', 'ai-provider-for-codex' );
			case 'site_snapshot_aggregate':
				return __( 'Site snapshot aggregate', 'ai-provider-for-codex' );
			case 'settings_fallback':
			default:
				return __( 'Site settings fallback', 'ai-provider-for-codex' );
		}
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
	 * Returns whether a catalog timestamp is still fresh.
	 *
	 * @param array<string,mixed> $catalog Catalog payload.
	 * @return bool
	 */
	public static function is_catalog_fresh( array $catalog ): bool {
		if ( empty( $catalog['checked_at'] ) ) {
			return false;
		}

		$checked_at = strtotime( (string) $catalog['checked_at'] );

		return false !== $checked_at && $checked_at >= ( time() - self::REFRESH_TTL );
	}

	/**
	 * Returns the settings-backed bootstrap catalog.
	 *
	 * @return array{
	 *     source:string,
	 *     default_model:string,
	 *     checked_at:?string,
	 *     models:list<array{id:string,label:string}>,
	 *     model_ids:list<string>
	 * }
	 */
	private static function get_settings_catalog(): array {
		$models = array_map(
			static function ( string $model_id ): array {
				return [
					'id'    => $model_id,
					'label' => self::label_for_model_id( $model_id ),
				];
			},
			Settings::get_allowed_models()
		);

		$model_ids = array_values(
			array_map(
				static function ( array $model ): string {
					return $model['id'];
				},
				$models
			)
		);

		$default_model = self::resolve_default_model( $model_ids, [] );
		$models        = self::prioritize_default_model( $models, $default_model );

		return [
			'source'        => 'settings_fallback',
			'default_model' => $default_model,
			'checked_at'    => null,
			'models'        => $models,
			'model_ids'     => $model_ids,
		];
	}

	/**
	 * Returns the current site aggregate from stored snapshots without fallback.
	 *
	 * @param string $source Source label for the returned catalog.
	 * @return array{
	 *     source:string,
	 *     default_model:string,
	 *     checked_at:?string,
	 *     models:list<array{id:string,label:string}>,
	 *     model_ids:list<string>
	 * }
	 */
	private static function get_snapshot_aggregate_catalog( string $source = 'site_snapshot_aggregate' ): array {
		$snapshots = ConnectionSnapshotRepository::list_active_for_site_catalog();

		if ( [] === $snapshots ) {
			return self::empty_catalog( $source );
		}

		$models_map        = [];
		$latest_checked_at = null;
		$snapshot_defaults = [];

		foreach ( $snapshots as $snapshot ) {
			foreach ( self::normalize_models( $snapshot['models'] ?? [] ) as $model ) {
				$models_map[ $model['id'] ] = $model;
			}

			$default_model = sanitize_text_field( (string) ( $snapshot['default_model'] ?? '' ) );

			if ( '' !== $default_model ) {
				$snapshot_defaults[] = $default_model;
			}

			if ( ! empty( $snapshot['checked_at'] ) ) {
				$checked_at = (string) $snapshot['checked_at'];

				if ( null === $latest_checked_at || $checked_at > $latest_checked_at ) {
					$latest_checked_at = $checked_at;
				}
			}
		}

		if ( [] === $models_map ) {
			return self::empty_catalog( $source );
		}

		ksort( $models_map );

		$model_ids = array_keys( $models_map );
		$default_model = self::resolve_default_model( $model_ids, $snapshot_defaults );
		$models        = self::prioritize_default_model( array_values( $models_map ), $default_model );

		return [
			'source'        => $source,
			'default_model' => $default_model,
			'checked_at'    => $latest_checked_at,
			'models'        => $models,
			'model_ids'     => array_values( $model_ids ),
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
	 * Extracts a model ID from a broker payload item.
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
	 * Extracts a readable model label from a broker payload item.
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
	 * Resolves the effective default model for a catalog.
	 *
	 * @param string[] $model_ids Catalog model IDs.
	 * @param string[] $candidate_defaults Candidate defaults from snapshots.
	 * @return string
	 */
	private static function resolve_default_model( array $model_ids, array $candidate_defaults, ?int $wp_user_id = null ): string {
		$user_preferred_model = self::get_user_preferred_model( $wp_user_id );

		if ( '' !== $user_preferred_model && in_array( $user_preferred_model, $model_ids, true ) ) {
			return $user_preferred_model;
		}

		$site_default = sanitize_text_field( Settings::get_default_model() );

		if ( '' !== $site_default && in_array( $site_default, $model_ids, true ) ) {
			return $site_default;
		}

		$counts = [];

		foreach ( $candidate_defaults as $candidate ) {
			$candidate = sanitize_text_field( (string) $candidate );

			if ( '' === $candidate || ! in_array( $candidate, $model_ids, true ) ) {
				continue;
			}

			$counts[ $candidate ] = ( $counts[ $candidate ] ?? 0 ) + 1;
		}

		if ( [] !== $counts ) {
			arsort( $counts );

			return (string) array_key_first( $counts );
		}

		return $model_ids[0] ?? $site_default;
	}

	/**
	 * Reorders model entries so the effective default model is evaluated first.
	 *
	 * @param list<array{id:string,label:string}> $models Model entries.
	 * @param string                              $default_model Effective default model.
	 * @return list<array{id:string,label:string}>
	 */
	private static function prioritize_default_model( array $models, string $default_model ): array {
		if ( '' === $default_model || [] === $models ) {
			return $models;
		}

		usort(
			$models,
			static function ( array $left, array $right ) use ( $default_model ): int {
				if ( $left['id'] === $right['id'] ) {
					return 0;
				}

				if ( $left['id'] === $default_model ) {
					return -1;
				}

				if ( $right['id'] === $default_model ) {
					return 1;
				}

				return 0;
			}
		);

		return $models;
	}

	/**
	 * Returns whether the site catalog should refresh from the broker.
	 *
	 * @param array<int,array<string,mixed>> $active_connections Active connection rows.
	 * @return bool
	 */
	private static function site_catalog_requires_refresh( array $active_connections ): bool {
		foreach ( $active_connections as $connection ) {
			$snapshot = ConnectionSnapshotRepository::get( (string) $connection['connection_id'] );

			if ( ! $snapshot || ! self::is_catalog_fresh( $snapshot ) || empty( $snapshot['models'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Returns whether a recent refresh attempt already ran.
	 *
	 * @return bool
	 */
	private static function has_recent_refresh_attempt(): bool {
		return false !== get_transient( self::REFRESH_TRANSIENT_KEY );
	}

	/**
	 * Records a site-catalog refresh attempt.
	 *
	 * @return void
	 */
	private static function record_refresh_attempt(): void {
		set_transient( self::REFRESH_TRANSIENT_KEY, gmdate( 'Y-m-d H:i:s' ), self::REFRESH_TTL );
	}

	/**
	 * Refreshes active connection snapshots from the broker models endpoint.
	 *
	 * @param array<int,array<string,mixed>> $active_connections Active connection rows.
	 * @return array{
	 *     source:string,
	 *     default_model:string,
	 *     checked_at:?string,
	 *     models:list<array{id:string,label:string}>,
	 *     model_ids:list<string>
	 * }
	 */
	private static function refresh_site_catalog_from_broker( array $active_connections ): array {
		$client      = new Client();
		$did_refresh = false;

		foreach ( $active_connections as $connection ) {
			try {
				$payload = $client->get(
					'/v1/wordpress/models',
					[
						'wpUserId'     => (int) $connection['wp_user_id'],
						'connectionId' => (string) $connection['connection_id'],
					]
				);

				if ( ! isset( $payload['checkedAt'] ) ) {
					$payload['checkedAt'] = gmdate( 'c' );
				}

				ConnectionSnapshotRepository::upsert(
					(string) $connection['connection_id'],
					$payload,
					sanitize_text_field( (string) ( $payload['readinessStatus'] ?? 'ready' ) )
				);

				$did_refresh = true;
			} catch ( RuntimeException $exception ) {
				continue;
			}
		}

		if ( ! $did_refresh ) {
			return self::empty_catalog( 'site_broker_aggregate' );
		}

		return self::get_snapshot_aggregate_catalog( 'site_broker_aggregate' );
	}

	/**
	 * Returns an empty catalog shell.
	 *
	 * @param string $source Source identifier.
	 * @return array{
	 *     source:string,
	 *     default_model:string,
	 *     checked_at:?string,
	 *     models:list<array{id:string,label:string}>,
	 *     model_ids:list<string>
	 * }
	 */
	private static function empty_catalog( string $source ): array {
		return [
			'source'        => $source,
			'default_model' => '',
			'checked_at'    => null,
			'models'        => [],
			'model_ids'     => [],
		];
	}
}
