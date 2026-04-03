<?php
/**
 * Broker-backed connection workflows.
 *
 * @package AIProviderForCodex
 */

declare( strict_types=1 );

namespace AIProviderForCodex\Auth;

use AIProviderForCodex\Broker\Client;
use AIProviderForCodex\Broker\ResponseMapper;
use AIProviderForCodex\Broker\Settings;
use RuntimeException;

/**
 * Starts, refreshes, exchanges, and disconnects user links.
 */
final class ConnectionService {

	/**
	 * Starts the broker connect flow for a user.
	 *
	 * @param int    $wp_user_id User ID.
	 * @param string $return_url Callback URL.
	 * @return string
	 */
	public function start_connect( int $wp_user_id, string $return_url ): string {
		if ( ! Settings::has_required_site_configuration() ) {
			throw new RuntimeException( __( 'The Codex broker site registration is incomplete.', 'ai-provider-for-codex' ) );
		}

		$user = get_userdata( $wp_user_id );

		if ( ! $user ) {
			throw new RuntimeException( __( 'The current user could not be loaded.', 'ai-provider-for-codex' ) );
		}

		$state    = AuthStateRepository::create_state( $wp_user_id, $return_url );
		$client   = new Client();
		$response = $client->post(
			'/v1/wordpress/connections/start',
			[
				'wpUserId'          => $wp_user_id,
				'wpUserEmail'       => $user->user_email,
				'wpUserDisplayName' => $user->display_name,
				'state'             => $state,
				'returnUrl'         => $return_url,
			]
		);

		$connect_url = (string) ( $response['connectUrl'] ?? '' );

		if ( '' === $connect_url ) {
			throw new RuntimeException( __( 'The broker did not return a connect URL.', 'ai-provider-for-codex' ) );
		}

		return $connect_url;
	}

	/**
	 * Exchanges a broker callback code.
	 *
	 * @param int    $wp_user_id User ID.
	 * @param string $state Local state token.
	 * @param string $broker_code One-time broker code.
	 * @return array<string,mixed>
	 */
	public function exchange_code( int $wp_user_id, string $state, string $broker_code ): array {
		$state_row = AuthStateRepository::consume_state( $state, $wp_user_id );

		if ( ! $state_row ) {
			throw new RuntimeException( __( 'The Codex connect state is invalid or expired.', 'ai-provider-for-codex' ) );
		}

		$client   = new Client();
		$response = $client->post(
			'/v1/wordpress/connections/exchange',
			[
				'wpUserId'   => $wp_user_id,
				'state'      => $state,
				'brokerCode' => sanitize_text_field( $broker_code ),
			]
		);

		ResponseMapper::store_connection_exchange( $wp_user_id, $response );

		return $response;
	}

	/**
	 * Refreshes the stored broker snapshot.
	 *
	 * @param int $wp_user_id User ID.
	 * @return array<string,mixed>
	 */
	public function refresh_snapshot( int $wp_user_id ): array {
		$connection = ConnectionRepository::get_for_user( $wp_user_id );

		if ( ! $connection ) {
			throw new RuntimeException( __( 'No Codex connection exists for the current user.', 'ai-provider-for-codex' ) );
		}

		$client   = new Client();
		$response = $client->get(
			'/v1/wordpress/connections/' . rawurlencode( (string) $connection['connection_id'] ),
			[
				'wpUserId' => $wp_user_id,
			]
		);

		ResponseMapper::store_connection_refresh( $wp_user_id, (string) $connection['connection_id'], $response );

		return $response;
	}

	/**
	 * Disconnects the current user locally and remotely.
	 *
	 * @param int $wp_user_id User ID.
	 * @return void
	 */
	public function disconnect( int $wp_user_id ): void {
		$connection = ConnectionRepository::get_for_user( $wp_user_id );

		if ( ! $connection ) {
			return;
		}

		if ( Settings::has_required_site_configuration() ) {
			try {
				$client = new Client();
				$client->post(
					'/v1/wordpress/connections/' . rawurlencode( (string) $connection['connection_id'] ) . '/disconnect',
					[
						'wpUserId' => $wp_user_id,
					]
				);
			} catch ( RuntimeException $exception ) {
				// Local cleanup is still useful when the remote session cannot be reached.
			}
		}

		ConnectionSnapshotRepository::delete( (string) $connection['connection_id'] );
		ConnectionRepository::delete_for_user( $wp_user_id );
	}
}
