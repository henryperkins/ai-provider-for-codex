<?php
/**
 * WordPress AI Connector Approval integration.
 *
 * @package AIProviderForCodex
 */

declare( strict_types=1 );

namespace AIProviderForCodex\Admin;

use WordPress\AI\Connector_Approval\Approvals_Store;
use WordPress\AI\Experiments\Connector_Approval\Connector_Approval as ConnectorApprovalExperiment;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bridges the optional WordPress AI Connector Approval experiment.
 */
final class ConnectorApprovalIntegration {

	public const OPTION_SELF_APPROVAL_SEEDED = 'codex_provider_connector_self_approval_seeded';

	private const CONNECTOR_ID = 'codex';

	/**
	 * Grants the provider plugin access to its own local-runtime connector once.
	 *
	 * @return void
	 */
	public static function maybe_self_approve(): void {
		if ( get_option( self::OPTION_SELF_APPROVAL_SEEDED, false ) ) {
			return;
		}

		if ( ! self::is_connector_approval_enabled() || ! class_exists( Approvals_Store::class ) ) {
			return;
		}

		$store           = new Approvals_Store();
		$plugin_basename = plugin_basename( \AIProviderForCodex\PLUGIN_FILE );

		if ( ! $store->is_approved( $plugin_basename, self::CONNECTOR_ID ) ) {
			$store->set_approval( $plugin_basename, self::CONNECTOR_ID, true );
		}

		if ( ! $store->is_approved( $plugin_basename, self::CONNECTOR_ID ) ) {
			return;
		}

		$store->remove_pending( $store->pending_key( $plugin_basename, self::CONNECTOR_ID ) );
		update_option( self::OPTION_SELF_APPROVAL_SEEDED, '1', false );
	}

	/**
	 * Returns whether the optional WordPress AI Connector Approval experiment is active.
	 *
	 * @return bool
	 */
	private static function is_connector_approval_enabled(): bool {
		if ( ! class_exists( ConnectorApprovalExperiment::class ) ) {
			return false;
		}

		try {
			return ( new ConnectorApprovalExperiment() )->is_enabled();
		} catch ( \Throwable $exception ) {
			return false;
		}
	}
}
