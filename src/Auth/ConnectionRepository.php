<?php
/**
 * Local connection persistence.
 *
 * @package AIProviderForCodex
 */

declare( strict_types=1 );

namespace AIProviderForCodex\Auth;

/**
 * Manages local connection records.
 */
final class ConnectionRepository {

	private const TABLE_SUFFIX = 'codex_provider_connections';

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
	 * Returns the connection for a user.
	 *
	 * @param int $wp_user_id User ID.
	 * @return array<string,mixed>|null
	 */
	public static function get_for_user( int $wp_user_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table_name() . ' WHERE wp_user_id = %d LIMIT 1',
				$wp_user_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Returns active linked connections for site-scoped catalog refreshes.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function list_active_for_site_catalog(): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table_name() . ' WHERE status = %s',
				'linked'
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return [];
		}

		$connections = [];

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || self::is_expired( $row ) ) {
				continue;
			}

			$connections[] = $row;
		}

		return $connections;
	}

	/**
	 * Inserts or replaces a user connection.
	 *
	 * @param int                 $wp_user_id User ID.
	 * @param array<string,mixed> $payload Runtime payload.
	 * @return void
	 */
	public static function upsert( int $wp_user_id, array $payload ): void {
		global $wpdb;

		$now = gmdate( 'Y-m-d H:i:s' );

		$existing = self::get_for_user( $wp_user_id );

		$data = [
			'wp_user_id'         => $wp_user_id,
			'connection_id'      => sanitize_text_field( (string) ( $payload['connectionId'] ?? $existing['connection_id'] ?? '' ) ),
			'status'             => sanitize_text_field( (string) ( $payload['status'] ?? $existing['status'] ?? 'linked' ) ),
			'account_email'      => sanitize_email( (string) ( $payload['account']['email'] ?? $existing['account_email'] ?? '' ) ),
			'plan_type'          => sanitize_text_field( (string) ( $payload['account']['planType'] ?? $existing['plan_type'] ?? '' ) ),
			'auth_mode'          => sanitize_text_field( (string) ( $payload['account']['authMode'] ?? $existing['auth_mode'] ?? '' ) ),
			'session_expires_at' => self::to_mysql_datetime( $payload['sessionExpiresAt'] ?? $existing['session_expires_at'] ?? null ),
			'last_synced_at'     => $now,
			'created_at'         => $existing['created_at'] ?? $now,
			'updated_at'         => $now,
		];

		$formats = [
			'%d',
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

		$wpdb->replace( self::table_name(), $data, $formats );
	}

	/**
	 * Deletes a user connection.
	 *
	 * @param int $wp_user_id User ID.
	 * @return void
	 */
	public static function delete_for_user( int $wp_user_id ): void {
		global $wpdb;

		$wpdb->delete( self::table_name(), [ 'wp_user_id' => $wp_user_id ], [ '%d' ] );
	}

	/**
	 * Returns whether a connection row is expired.
	 *
	 * @param array<string,mixed> $connection Connection row.
	 * @return bool
	 */
	public static function is_expired( array $connection ): bool {
		if ( empty( $connection['session_expires_at'] ) ) {
			return false;
		}

		$expires_at = strtotime( (string) $connection['session_expires_at'] );

		return false !== $expires_at && $expires_at < time();
	}

	/**
	 * Converts an ISO8601 date or MySQL datetime to MySQL datetime.
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
