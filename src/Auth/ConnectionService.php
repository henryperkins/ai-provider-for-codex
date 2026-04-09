<?php
/**
 * Local runtime-backed connection workflows.
 *
 * @package AIProviderForCodex
 */

declare( strict_types=1 );

namespace AIProviderForCodex\Auth;

use AIProviderForCodex\Provider\ModelCatalogState;
use AIProviderForCodex\Runtime\Client;
use AIProviderForCodex\Runtime\ResponseMapper;
use AIProviderForCodex\Runtime\RuntimeRequestException;
use AIProviderForCodex\Runtime\Settings;
use RuntimeException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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
			throw self::runtime_exception( esc_html__( 'The local Codex runtime settings are incomplete.', 'ai-provider-for-codex' ) );
		}

		$user = get_userdata( $wp_user_id );

		if ( ! $user ) {
			throw self::runtime_exception( esc_html__( 'The current user could not be loaded.', 'ai-provider-for-codex' ) );
		}

		PendingConnectionRepository::delete_for_user( $wp_user_id );

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
			throw self::runtime_exception( esc_html__( 'The local Codex runtime returned an incomplete login response.', 'ai-provider-for-codex' ) );
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

		if ( 'error' === sanitize_key( (string) ( $pending['status'] ?? 'pending' ) ) ) {
			return $pending;
		}

		$response = $this->runtime_connect_status( $wp_user_id, $pending );

		$status = sanitize_key( (string) ( $response['status'] ?? 'pending' ) );

		if ( 'pending' === $status ) {
			PendingConnectionRepository::upsert( $wp_user_id, $response );
			return $response;
		}

		if ( 'error' === $status ) {
			PendingConnectionRepository::upsert( $wp_user_id, $response );

			return PendingConnectionRepository::get_for_user( $wp_user_id ) ?? $response;
		}

		if ( 'missing' === $status ) {
			return $this->recover_missing_connect_session( $wp_user_id, $pending, $response );
		}

		if ( 'completed' !== $status ) {
			PendingConnectionRepository::delete_for_user( $wp_user_id );
			return self::connect_error_response(
				esc_html__( 'The local Codex runtime returned an unexpected login status.', 'ai-provider-for-codex' )
			);
		}

		try {
			$connection_id = $this->connection_id_for_user( $wp_user_id );
			$snapshot      = $this->refresh_snapshot( $wp_user_id, $connection_id );
		} catch ( RuntimeRequestException $exception ) {
			if ( $exception->is_auth_required() ) {
				return $this->build_missing_connect_response(
					(string) $pending['authSessionId'],
					$exception->getMessage()
				);
			}

			$this->persist_retryable_connect_state( $wp_user_id, $pending, $response, $exception->getMessage() );

			return self::connect_error_response( $exception->getMessage() );
		} catch ( RuntimeException $exception ) {
			$this->persist_retryable_connect_state( $wp_user_id, $pending, $response, $exception->getMessage() );

			return self::connect_error_response( $exception->getMessage() );
		}

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
			throw self::runtime_exception( esc_html__( 'The local Codex runtime settings are incomplete.', 'ai-provider-for-codex' ) );
		}

		$connection    = ConnectionRepository::get_for_user( $wp_user_id );
		$connection_id = $connection_id ?: (string) ( $connection['connection_id'] ?? '' );

		if ( '' === $connection_id ) {
			$connection_id = $this->connection_id_for_user( $wp_user_id );
		}

		$client   = new Client();
		try {
			$response = $client->get(
				'/v1/account/snapshot',
				[
					'wpUserId' => $wp_user_id,
				]
			);
		} catch ( RuntimeRequestException $exception ) {
			if ( $exception->is_auth_required() ) {
				self::invalidate_local_connection( $wp_user_id );
			}

			throw $exception;
		}

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

		self::invalidate_local_connection( $wp_user_id );
	}

	/**
	 * Clears all local state for a user's Codex link.
	 *
	 * @param int $wp_user_id User ID.
	 * @return void
	 */
	public static function invalidate_local_connection( int $wp_user_id ): void {
		$connection = ConnectionRepository::get_for_user( $wp_user_id );

		PendingConnectionRepository::delete_for_user( $wp_user_id );
		ModelCatalogState::delete_user_preferred_model( $wp_user_id );

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

	/**
	 * Creates a runtime exception without tripping output sniffs.
	 *
	 * @param string $message Plain-text exception message.
	 * @return RuntimeException
	 */
	private static function runtime_exception( string $message ): RuntimeException {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are escaped at the render boundary.
		return new RuntimeException( $message );
	}

	/**
	 * Attempts to recover after the sidecar lost an in-memory login session.
	 *
	 * @param int                 $wp_user_id User ID.
	 * @param array<string,mixed> $response Sidecar status response.
	 * @return array<string,mixed>
	 */
	private function recover_missing_connect_session( int $wp_user_id, array $pending, array $response ): array {
		try {
			$snapshot = $this->refresh_snapshot( $wp_user_id, $this->connection_id_for_user( $wp_user_id ) );
		} catch ( RuntimeRequestException $exception ) {
			if ( $exception->is_auth_required() ) {
				PendingConnectionRepository::delete_for_user( $wp_user_id );

				return $this->build_missing_connect_response(
					(string) ( $response['authSessionId'] ?? $pending['authSessionId'] ),
					$exception->getMessage()
				);
			}

			$this->persist_retryable_connect_state( $wp_user_id, $pending, $response, $exception->getMessage() );

			return self::connect_error_response( $exception->getMessage() );
		} catch ( RuntimeException $exception ) {
			$this->persist_retryable_connect_state( $wp_user_id, $pending, $response, $exception->getMessage() );

			return self::connect_error_response( $exception->getMessage() );
		}

		PendingConnectionRepository::delete_for_user( $wp_user_id );

		return [
			'status'     => 'connected',
			'connection' => ConnectionRepository::get_for_user( $wp_user_id ),
			'snapshot'   => $snapshot,
		];
	}

	/**
	 * Reads runtime connect status while tolerating the legacy missing-session contract.
	 *
	 * @param int                     $wp_user_id User ID.
	 * @param array<string,mixed>     $pending Pending auth session row.
	 * @return array<string,mixed>
	 */
	private function runtime_connect_status( int $wp_user_id, array $pending ): array {
		$client = new Client();

		try {
			return $client->get(
				'/v1/login/status',
				[
					'wpUserId'      => $wp_user_id,
					'authSessionId' => (string) $pending['authSessionId'],
				]
			);
		} catch ( RuntimeRequestException $exception ) {
			if ( $this->is_legacy_missing_connect_response( $exception, (string) $pending['authSessionId'] ) ) {
				return $this->normalize_legacy_missing_connect_response( $exception, $pending );
			}

			throw $exception;
		}
	}

	/**
	 * Returns whether an exception matches the older 404 missing-session contract.
	 *
	 * @param RuntimeRequestException $exception Runtime exception.
	 * @param string                  $auth_session_id Expected auth session ID.
	 * @return bool
	 */
	private function is_legacy_missing_connect_response( RuntimeRequestException $exception, string $auth_session_id ): bool {
		if ( 404 !== $exception->get_status_code() ) {
			return false;
		}

		$payload        = $exception->get_payload();
		$status         = sanitize_key( (string) ( $payload['status'] ?? '' ) );
		$response_auth  = sanitize_text_field( (string) ( $payload['authSessionId'] ?? '' ) );

		if ( 'missing' !== $status ) {
			return false;
		}

		return '' === $response_auth || $response_auth === $auth_session_id;
	}

	/**
	 * Converts the older 404 missing-session payload into the current normalized shape.
	 *
	 * @param RuntimeRequestException $exception Runtime exception.
	 * @param array<string,mixed>     $pending Pending auth session row.
	 * @return array<string,mixed>
	 */
	private function normalize_legacy_missing_connect_response( RuntimeRequestException $exception, array $pending ): array {
		$payload = $exception->get_payload();

		return [
			'authSessionId'   => sanitize_text_field( (string) ( $payload['authSessionId'] ?? $pending['authSessionId'] ) ),
			'status'          => 'missing',
			'authStored'      => ! empty( $payload['authStored'] ),
			'verificationUrl' => null,
			'userCode'        => null,
			'error'           => sanitize_text_field(
				(string) ( $payload['error'] ?? __( 'Login session was not found in the local runtime.', 'ai-provider-for-codex' ) )
			),
		];
	}

	/**
	 * Stores a retryable post-login sync state for the current user.
	 *
	 * @param int                 $wp_user_id User ID.
	 * @param array<string,mixed> $pending Existing pending auth session row.
	 * @param array<string,mixed> $response Latest runtime response.
	 * @param string              $message Error message.
	 * @return void
	 */
	private function persist_retryable_connect_state( int $wp_user_id, array $pending, array $response, string $message ): void {
		PendingConnectionRepository::upsert(
			$wp_user_id,
			[
				'authSessionId'   => ! empty( $response['authSessionId'] ) ? (string) $response['authSessionId'] : (string) $pending['authSessionId'],
				'status'          => 'completed',
				'verificationUrl' => ! empty( $response['verificationUrl'] ) ? (string) $response['verificationUrl'] : (string) ( $pending['verificationUrl'] ?? '' ),
				'userCode'        => ! empty( $response['userCode'] ) ? (string) $response['userCode'] : (string) ( $pending['userCode'] ?? '' ),
				'error'           => $message,
			]
		);
	}

	/**
	 * Returns a normalized missing-session response.
	 *
	 * @param string $auth_session_id Pending auth session ID.
	 * @param string $message Message to surface to the user.
	 * @return array<string,mixed>
	 */
	private function build_missing_connect_response( string $auth_session_id, string $message ): array {
		return [
			'authSessionId'   => $auth_session_id,
			'status'          => 'missing',
			'verificationUrl' => null,
			'userCode'        => null,
			'error'           => $message,
		];
	}

	/**
	 * Returns a normalized connect-flow error response.
	 *
	 * @param string $message Error message.
	 * @return array<string,mixed>
	 */
	private static function connect_error_response( string $message ): array {
		return [
			'status' => 'error',
			'error'  => $message,
		];
	}
}
