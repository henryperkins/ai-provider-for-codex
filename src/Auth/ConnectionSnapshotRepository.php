<?php
/**
 * Local snapshot persistence.
 *
 * @package AIProviderForCodex
 */

declare( strict_types=1 );

namespace AIProviderForCodex\Auth;

/**
 * Stores broker-derived readiness snapshots.
 */
final class ConnectionSnapshotRepository {

	private const TABLE_SUFFIX = 'codex_provider_connection_snapshots';

	/**
	 * Returns the table name.
	 *
	 * @return string
	 */
	public static function table_name(): string {
		global $wpdb;

		return $wpdb->prefix . self::TABLE_SUFFIX;
	}

	/**
	 * Returns the stored snapshot for a connection.
	 *
	 * @param string $connection_id Connection ID.
	 * @return array<string,mixed>|null
	 */
	public static function get( string $connection_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table_name() . ' WHERE connection_id = %s LIMIT 1',
				$connection_id
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return null;
		}

		$row['models']      = self::decode_json_column( $row['models_json'] ?? '' );
		$row['rate_limits'] = self::decode_json_column( $row['rate_limits_json'] ?? '' );

		return $row;
	}

	/**
	 * Returns active snapshots for site-scoped catalog aggregation.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function list_active_for_site_catalog(): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT snapshots.*, connections.session_expires_at
				FROM ' . self::table_name() . ' AS snapshots
				INNER JOIN ' . ConnectionRepository::table_name() . ' AS connections
					ON connections.connection_id = snapshots.connection_id
				WHERE connections.status = %s',
				'linked'
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return [];
		}

		$snapshots = [];
		$now       = time();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			if ( ! empty( $row['session_expires_at'] ) ) {
				$expires_at = strtotime( (string) $row['session_expires_at'] );

				if ( false !== $expires_at && $expires_at < $now ) {
					continue;
				}
			}

			$row['models']      = self::decode_json_column( $row['models_json'] ?? '' );
			$row['rate_limits'] = self::decode_json_column( $row['rate_limits_json'] ?? '' );
			$snapshots[]        = $row;
		}

		return $snapshots;
	}

	/**
	 * Inserts or replaces a snapshot row.
	 *
	 * @param string               $connection_id Connection ID.
	 * @param array<string,mixed>  $payload Broker payload.
	 * @param string               $readiness_status Computed readiness state.
	 * @return void
	 */
	public static function upsert( string $connection_id, array $payload, string $readiness_status = 'ready' ): void {
		global $wpdb;

		$existing = self::get( $connection_id );
		$now      = gmdate( 'Y-m-d H:i:s' );

		$data = [
			'connection_id'     => sanitize_text_field( $connection_id ),
			'models_json'       => wp_json_encode( $payload['models'] ?? $existing['models'] ?? [] ),
			'default_model'     => sanitize_text_field( (string) ( $payload['defaults']['model'] ?? $payload['defaultModel'] ?? $existing['default_model'] ?? '' ) ),
			'reasoning_effort'  => sanitize_text_field( (string) ( $payload['defaults']['reasoningEffort'] ?? $existing['reasoning_effort'] ?? '' ) ),
			'rate_limits_json'  => wp_json_encode( $payload['rateLimits'] ?? $existing['rate_limits'] ?? [] ),
			'readiness_status'  => sanitize_text_field( $readiness_status ),
			'checked_at'        => self::to_mysql_datetime( $payload['checkedAt'] ?? $now ) ?? $now,
			'created_at'        => $existing['created_at'] ?? $now,
			'updated_at'        => $now,
		];

		$formats = [
			'%s',
			'%s',
			'%s',
			'%s',
			'%s',
			'%s',
			'%s',
			'%s',
			'%s',
		];

		if ( isset( $existing['id'] ) ) {
			$data    = [ 'id' => (int) $existing['id'] ] + $data;
			$formats = [ '%d' ] + $formats;
		}

		$wpdb->replace( self::table_name(), $data, array_values( $formats ) );
	}

	/**
	 * Deletes a snapshot by connection ID.
	 *
	 * @param string $connection_id Connection ID.
	 * @return void
	 */
	public static function delete( string $connection_id ): void {
		global $wpdb;

		$wpdb->delete( self::table_name(), [ 'connection_id' => $connection_id ], [ '%s' ] );
	}

	/**
	 * Decodes a JSON column.
	 *
	 * @param string $json Raw JSON.
	 * @return array<mixed>
	 */
	private static function decode_json_column( string $json ): array {
		if ( '' === $json ) {
			return [];
		}

		$decoded = json_decode( $json, true );

		return is_array( $decoded ) ? $decoded : [];
	}

	/**
	 * Converts ISO8601 strings to MySQL datetime.
	 *
	 * @param mixed $value Raw value.
	 * @return string|null
	 */
	private static function to_mysql_datetime( $value ): ?string {
		if ( empty( $value ) ) {
			return null;
		}

		if ( is_string( $value ) && preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value ) ) {
			return $value;
		}

		$timestamp = strtotime( (string) $value );

		return false !== $timestamp ? gmdate( 'Y-m-d H:i:s', $timestamp ) : null;
	}
}
