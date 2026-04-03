<?php
/**
 * Per-user pending local runtime connection persistence.
 *
 * @package AIProviderForCodex
 */

declare( strict_types=1 );

namespace AIProviderForCodex\Auth;

/**
 * Stores short-lived local runtime login session data in user meta.
 */
final class PendingConnectionRepository {

	private const USER_META_KEY = 'codex_provider_pending_auth_session';

	/**
	 * Returns the pending login session for a user.
	 *
	 * @param int $wp_user_id User ID.
	 * @return array<string,mixed>|null
	 */
	public static function get_for_user( int $wp_user_id ): ?array {
		if ( $wp_user_id <= 0 ) {
			return null;
		}

		$value = get_user_meta( $wp_user_id, self::USER_META_KEY, true );

		if ( ! is_array( $value ) || empty( $value['authSessionId'] ) ) {
			return null;
		}

		return [
			'authSessionId'   => sanitize_text_field( (string) $value['authSessionId'] ),
			'status'          => sanitize_text_field( (string) ( $value['status'] ?? 'pending' ) ),
			'verificationUrl' => ! empty( $value['verificationUrl'] ) ? esc_url_raw( (string) $value['verificationUrl'] ) : '',
			'userCode'        => sanitize_text_field( (string) ( $value['userCode'] ?? '' ) ),
			'error'           => sanitize_text_field( (string) ( $value['error'] ?? '' ) ),
			'updatedAt'       => sanitize_text_field( (string) ( $value['updatedAt'] ?? '' ) ),
		];
	}

	/**
	 * Stores or updates the pending login session for a user.
	 *
	 * @param int                 $wp_user_id User ID.
	 * @param array<string,mixed> $payload Pending runtime payload.
	 * @return void
	 */
	public static function upsert( int $wp_user_id, array $payload ): void {
		if ( $wp_user_id <= 0 ) {
			return;
		}

		$existing = self::get_for_user( $wp_user_id );
		$data     = [
			'authSessionId'   => sanitize_text_field( (string) ( $payload['authSessionId'] ?? $existing['authSessionId'] ?? '' ) ),
			'status'          => sanitize_text_field( (string) ( $payload['status'] ?? $existing['status'] ?? 'pending' ) ),
			'verificationUrl' => ! empty( $payload['verificationUrl'] ) ? esc_url_raw( (string) $payload['verificationUrl'] ) : ( $existing['verificationUrl'] ?? '' ),
			'userCode'        => sanitize_text_field( (string) ( $payload['userCode'] ?? $existing['userCode'] ?? '' ) ),
			'error'           => sanitize_text_field( (string) ( $payload['error'] ?? $existing['error'] ?? '' ) ),
			'updatedAt'       => gmdate( 'Y-m-d H:i:s' ),
		];

		update_user_meta( $wp_user_id, self::USER_META_KEY, $data );
	}

	/**
	 * Deletes the pending login session for a user.
	 *
	 * @param int $wp_user_id User ID.
	 * @return void
	 */
	public static function delete_for_user( int $wp_user_id ): void {
		if ( $wp_user_id <= 0 ) {
			return;
		}

		delete_user_meta( $wp_user_id, self::USER_META_KEY );
	}
}
