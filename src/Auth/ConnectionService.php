<?php
/**
 * Local runtime-backed connection workflows.
 *
 * @package AIProviderForCodex
 */

declare( strict_types=1 );

namespace AIProviderForCodex\Auth;

use AIProviderForCodex\Runtime\Client;
use AIProviderForCodex\Runtime\ResponseMapper;
use AIProviderForCodex\Runtime\Settings;
use RuntimeException;

/**
 * Starts, polls, refreshes, and disconnects user links.
 */
final class ConnectionService {

	/**
	 * Starts the local runtime connect flow for a user.
	 *
	 * @param int $wp_user_id User ID.
	 * @return array<string,mixed>
	 */
	public function start_connect( int $wp_user_id ): array {
		if ( ! Settings::has_required_configuration() ) {
			throw new RuntimeException( __( 'The local Codex runtime settings are incomplete.', 'ai-provider-for-codex' ) );
		}

		$user = get_userdata( $wp_user_id );

		if ( ! $user ) {
			throw new RuntimeException( __( 'The current user could not be loaded.', 'ai-provider-for-codex' ) );
		}

		$client   = new Client();
		$response = $client->post(
			'/v1/login/start',
			[
				'wpUserId'    => $wp_user_id,
				'email'       => $user->user_email,
				'displayName' => $user->display_name,
			]
		);

		if ( empty( $response['authSessionId'] ) || empty( $response['verificationUrl'] ) || empty( $response['userCode'] ) ) {
			throw new RuntimeException( __( 'The local Codex runtime returned an incomplete login response.', 'ai-provider-for-codex' ) );
		}

		PendingConnectionRepository::upsert( $wp_user_id, $response );

		return $response;
	}

	/**
	 * Polls the local runtime status for a pending user login.
	 *
	 * @param int $wp_user_id User ID.
	 * @return array<string,mixed>
	 */
	public function poll_connect_status( int $wp_user_id ): array {
		$pending = PendingConnectionRepository::get_for_user( $wp_user_id );

		if ( ! $pending || empty( $pending['authSessionId'] ) ) {
			return [
				'status' => 'missing',
			];
		}

		$client   = new Client();
		$response = $client->get(
			'/v1/login/status',
			[
				'wpUserId'      => $wp_user_id,
				'authSessionId' => (string) $pending['authSessionId'],
			]
		);

		PendingConnectionRepository::upsert( $wp_user_id, $response );

		if ( 'completed' !== (string) ( $response['status'] ?? '' ) ) {
			return $response;
		}

		$connection_id = $this->connection_id_for_user( $wp_user_id );
		$snapshot      = $this->refresh_snapshot( $wp_user_id, $connection_id );

		PendingConnectionRepository::delete_for_user( $wp_user_id );

		return [
			'status'     => 'connected',
			'connection' => ConnectionRepository::get_for_user( $wp_user_id ),
			'snapshot'   => $snapshot,
		];
	}

	/**
	 * Refreshes the stored runtime snapshot.
	 *
	 * @param int         $wp_user_id User ID.
	 * @param string|null $connection_id Optional connection ID override.
	 * @return array<string,mixed>
	 */
	public function refresh_snapshot( int $wp_user_id, ?string $connection_id = null ): array {
		if ( ! Settings::has_required_configuration() ) {
			throw new RuntimeException( __( 'The local Codex runtime settings are incomplete.', 'ai-provider-for-codex' ) );
		}

		$connection = ConnectionRepository::get_for_user( $wp_user_id );
		$connection_id = $connection_id ?: (string) ( $connection['connection_id'] ?? '' );

		if ( '' === $connection_id ) {
			$connection_id = $this->connection_id_for_user( $wp_user_id );
		}

		$client   = new Client();
		$response = $client->get(
			'/v1/account/snapshot',
			[
				'wpUserId' => $wp_user_id,
			]
		);

		ResponseMapper::store_connection_snapshot( $wp_user_id, $connection_id, $response );

		return $response;
	}

	/**
	 * Disconnects the current user locally and from the sidecar runtime.
	 *
	 * @param int $wp_user_id User ID.
	 * @return void
	 */
	public function disconnect( int $wp_user_id ): void {
		$connection = ConnectionRepository::get_for_user( $wp_user_id );

		if ( Settings::has_required_configuration() ) {
			try {
				$client = new Client();
				$client->post(
					'/v1/session/clear',
					[
						'wpUserId' => $wp_user_id,
					]
				);
			} catch ( RuntimeException $exception ) {
				// Local cleanup is still useful when the runtime cannot be reached.
			}
		}

		PendingConnectionRepository::delete_for_user( $wp_user_id );

		if ( $connection ) {
			ConnectionSnapshotRepository::delete( (string) $connection['connection_id'] );
		}

		ConnectionRepository::delete_for_user( $wp_user_id );
	}

	/**
	 * Returns the pending runtime login session for a user.
	 *
	 * @param int $wp_user_id User ID.
	 * @return array<string,mixed>|null
	 */
	public function get_pending_connect( int $wp_user_id ): ?array {
		return PendingConnectionRepository::get_for_user( $wp_user_id );
	}

	/**
	 * Returns or creates a stable local connection ID for a user.
	 *
	 * @param int $wp_user_id User ID.
	 * @return string
	 */
	private function connection_id_for_user( int $wp_user_id ): string {
		$connection = ConnectionRepository::get_for_user( $wp_user_id );

		if ( $connection && ! empty( $connection['connection_id'] ) ) {
			return (string) $connection['connection_id'];
		}

		return 'conn_local_' . $wp_user_id;
	}
}
