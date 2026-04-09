<?php
/**
 * Local readiness helpers.
 *
 * @package AIProviderForCodex
 */

declare( strict_types=1 );

namespace AIProviderForCodex\Provider;

use AIProviderForCodex\Auth\ConnectionRepository;
use AIProviderForCodex\Auth\ConnectionSnapshotRepository;
use AIProviderForCodex\Auth\PendingConnectionRepository;
use AIProviderForCodex\Runtime\HealthMonitor;
use AIProviderForCodex\Runtime\Settings;

/**
 * Computes user-aware provider status from local state.
 */
final class SupportChecks {

	/**
	 * Returns current user readiness details.
	 *
	 * @param int|null $wp_user_id Optional user ID.
	 * @return array<string,mixed>
	 */
	public static function current_user_status( ?int $wp_user_id = null ): array {
		$wp_user_id = $wp_user_id ?? get_current_user_id();
		$catalog    = ModelCatalogState::get_effective_catalog( $wp_user_id );
		$pending    = $wp_user_id > 0 ? PendingConnectionRepository::get_for_user( $wp_user_id ) : null;
		$connection = $wp_user_id > 0 ? ConnectionRepository::get_for_user( $wp_user_id ) : null;

		if ( ! Settings::has_required_configuration() ) {
			return [
				'ready'             => false,
				'reason'            => 'runtime_unconfigured',
				'runtime'           => HealthMonitor::get_status(),
				'runtimeConfigured' => false,
				'connection'        => null,
				'snapshot'          => null,
				'catalog'           => $catalog,
				'pendingConnection' => $pending,
			];
		}

		$runtime = HealthMonitor::probe();

		if ( 'unreachable' === (string) $runtime['status'] ) {
			return [
				'ready'             => false,
				'reason'            => 'runtime_unreachable',
				'runtime'           => $runtime,
				'runtimeConfigured' => true,
				'connection'        => $connection,
				'snapshot'          => null,
				'catalog'           => $catalog,
				'pendingConnection' => $pending,
			];
		}

		if ( $pending && ! empty( $pending['authSessionId'] ) ) {
			$pending_status = sanitize_key( (string) ( $pending['status'] ?? 'pending' ) );

			return [
				'ready'             => false,
				'reason'            => 'error' === $pending_status ? 'login_failed' : 'login_pending',
				'runtime'           => $runtime,
				'runtimeConfigured' => true,
				'connection'        => null,
				'snapshot'          => null,
				'catalog'           => $catalog,
				'pendingConnection' => $pending,
			];
		}

		if ( ! $connection ) {
			return [
				'ready'             => false,
				'reason'            => 'user_unlinked',
				'runtime'           => $runtime,
				'runtimeConfigured' => true,
				'connection'        => null,
				'snapshot'          => null,
				'catalog'           => $catalog,
				'pendingConnection' => null,
			];
		}

		if ( ! empty( $connection['session_expires_at'] ) ) {
			$expires_at = strtotime( (string) $connection['session_expires_at'] );

			if ( false !== $expires_at && $expires_at < time() ) {
				return [
					'ready'             => false,
					'reason'            => 'connection_expired',
					'runtime'           => $runtime,
					'runtimeConfigured' => true,
					'connection'        => $connection,
					'snapshot'          => null,
					'catalog'           => $catalog,
					'pendingConnection' => null,
				];
			}
		}

		$snapshot = ConnectionSnapshotRepository::get( (string) $connection['connection_id'] );

		if ( ! $snapshot ) {
			return [
				'ready'             => false,
				'reason'            => 'runtime_unreachable',
				'runtime'           => $runtime,
				'runtimeConfigured' => true,
				'connection'        => $connection,
				'snapshot'          => null,
				'catalog'           => $catalog,
				'pendingConnection' => null,
			];
		}

		$reason = (string) ( $snapshot['readiness_status'] ?? 'ready' );

		return [
			'ready'             => 'ready' === $reason,
			'reason'            => $reason,
			'runtime'           => $runtime,
			'runtimeConfigured' => true,
			'connection'        => $connection,
			'snapshot'          => $snapshot,
			'catalog'           => $catalog,
			'pendingConnection' => null,
		];
	}
}
