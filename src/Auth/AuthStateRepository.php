<?php
/**
 * Temporary auth state persistence.
 *
 * @package AIProviderForCodex
 */

declare( strict_types=1 );

namespace AIProviderForCodex\Auth;

/**
 * Stores short-lived connect callback states.
 */
final class AuthStateRepository {

	private const TABLE_SUFFIX = 'codex_provider_auth_states';

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
	 * Creates a short-lived state token.
	 *
	 * @param int    $wp_user_id User ID.
	 * @param string $return_url Callback URL.
	 * @return string
	 */
	public static function create_state( int $wp_user_id, string $return_url ): string {
		global $wpdb;

		$state = wp_generate_password( 32, false, false );
		$now   = gmdate( 'Y-m-d H:i:s' );

		$wpdb->insert(
			self::table_name(),
			[
				'state'      => $state,
				'wp_user_id' => $wp_user_id,
				'return_url' => esc_url_raw( $return_url ),
				'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 15 * MINUTE_IN_SECONDS ),
				'used_at'    => null,
				'created_at' => $now,
			],
			[
				'%s',
				'%d',
				'%s',
				'%s',
				'%s',
				'%s',
			]
		);

		return $state;
	}

	/**
	 * Consumes a valid state for a specific user.
	 *
	 * @param string $state State token.
	 * @param int    $wp_user_id User ID.
	 * @return array<string,mixed>|null
	 */
	public static function consume_state( string $state, int $wp_user_id ): ?array {
		global $wpdb;

		self::delete_expired();

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table_name() . ' WHERE state = %s AND wp_user_id = %d AND used_at IS NULL LIMIT 1',
				$state,
				$wp_user_id
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return null;
		}

		$expires_at = strtotime( (string) $row['expires_at'] );

		if ( false === $expires_at || $expires_at < time() ) {
			return null;
		}

		$wpdb->update(
			self::table_name(),
			[ 'used_at' => gmdate( 'Y-m-d H:i:s' ) ],
			[ 'id' => (int) $row['id'] ],
			[ '%s' ],
			[ '%d' ]
		);

		return $row;
	}

	/**
	 * Deletes expired or used states.
	 *
	 * @return void
	 */
	public static function delete_expired(): void {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM ' . self::table_name() . ' WHERE expires_at < %s OR used_at IS NOT NULL',
				gmdate( 'Y-m-d H:i:s' )
			)
		);
	}
}
