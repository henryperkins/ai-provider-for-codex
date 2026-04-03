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
use AIProviderForCodex\Broker\HealthMonitor;
use AIProviderForCodex\Broker\Settings;

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
		$site_catalog = ModelCatalogState::get_site_catalog();

		if ( ! Settings::has_required_site_configuration() ) {
			return [
				'ready'           => false,
				'reason'          => 'broker_unconfigured',
				'broker'          => HealthMonitor::get_status(),
				'siteConfigured'  => false,
				'connection'      => null,
				'snapshot'        => null,
				'catalog'         => $catalog,
				'siteCatalog'     => $site_catalog,
			];
		}

		$connection = $wp_user_id > 0 ? ConnectionRepository::get_for_user( $wp_user_id ) : null;

		if ( ! $connection ) {
			return [
				'ready'           => false,
				'reason'          => 'user_unlinked',
				'broker'          => HealthMonitor::get_status(),
				'siteConfigured'  => true,
				'connection'      => null,
				'snapshot'        => null,
				'catalog'         => $catalog,
				'siteCatalog'     => $site_catalog,
			];
		}

		if ( ! empty( $connection['session_expires_at'] ) ) {
			$expires_at = strtotime( (string) $connection['session_expires_at'] );

			if ( false !== $expires_at && $expires_at < time() ) {
				return [
					'ready'          => false,
					'reason'         => 'connection_expired',
					'broker'         => HealthMonitor::get_status(),
					'siteConfigured' => true,
					'connection'     => $connection,
					'snapshot'       => null,
					'catalog'        => $catalog,
					'siteCatalog'    => $site_catalog,
				];
			}
		}

		$snapshot = ConnectionSnapshotRepository::get( (string) $connection['connection_id'] );

		if ( ! $snapshot ) {
			return [
				'ready'          => false,
				'reason'         => 'broker_unreachable',
				'broker'         => HealthMonitor::get_status(),
				'siteConfigured' => true,
				'connection'     => $connection,
				'snapshot'       => null,
				'catalog'        => $catalog,
				'siteCatalog'    => $site_catalog,
			];
		}

		$reason = (string) ( $snapshot['readiness_status'] ?? 'ready' );

		return [
			'ready'          => 'ready' === $reason,
			'reason'         => $reason,
			'broker'         => HealthMonitor::get_status(),
			'siteConfigured' => true,
			'connection'     => $connection,
			'snapshot'       => $snapshot,
			'catalog'        => $catalog,
			'siteCatalog'    => $site_catalog,
		];
	}
}
