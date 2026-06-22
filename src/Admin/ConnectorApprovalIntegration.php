<?php
/**
 * WordPress AI Connector Approval integration.
 *
 * @package HtperkinsAIProviderForCodex
 */

declare( strict_types=1 );

namespace Htperkins\AIProviderForCodex\Admin;

use WordPress\AI\Connector_Approval\Approvals_Store;
use WordPress\AI\Experiments\Connector_Approval\Connector_Approval as ConnectorApprovalExperiment;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bridges the optional WordPress AI Connector Approval experiment.
 */
final class ConnectorApprovalIntegration {

	public const OPTION_SELF_APPROVAL_SEEDED = 'htperkins_aipfc_connector_self_approval_seeded';

	private const CONNECTOR_ID = 'codex';

	/**
	 * Plugin basename used before the 0.1.5 main-file rename.
	 *
	 * Connector approvals are keyed by basename, and the pre-0.1.5 seed marker
	 * ('1') did not record one. This lets the one-time rename migration look up
	 * the prior approval decision so it can be carried forward — or, if an admin
	 * revoked it, left in place.
	 *
	 * @var string
	 */
	private const LEGACY_BASENAME = 'ai-provider-for-codex/plugin.php';

	/**
	 * Grants the provider plugin access to its own local-runtime connector once.
	 *
	 * @return void
	 */
	public static function maybe_self_approve(): void {
		$plugin_basename = plugin_basename( \Htperkins\AIProviderForCodex\PLUGIN_FILE );

		// The seed marker records the basename last reconciled. Once it matches
		// the current basename there is nothing to do.
		$seed = (string) get_option( self::OPTION_SELF_APPROVAL_SEEDED, '' );

		if ( $seed === $plugin_basename ) {
			return;
		}

		if ( ! self::is_connector_approval_enabled() || ! class_exists( Approvals_Store::class ) ) {
			return;
		}

		$store = new Approvals_Store();

		// Identify the basename this plugin was last reconciled under so a rename
		// carries the prior decision forward rather than minting a new one. The
		// legacy '1' marker predates basename-aware seeding; map it to the known
		// pre-0.1.5 basename.
		$previous_basename = '';
		if ( '1' === $seed ) {
			$previous_basename = self::LEGACY_BASENAME;
		} elseif ( '' !== $seed && false !== strpos( $seed, '/' ) ) {
			$previous_basename = $seed;
		}

		// First boot performs the one-time self-approval. A rename re-approves
		// only when the prior basename is still approved: connector approvals
		// delete the entry on revocation, so an absent prior grant means either
		// a never-approved state or a deliberate admin revocation, and must not
		// be silently overridden.
		$should_approve = '' === $previous_basename
			|| $store->is_approved( $previous_basename, self::CONNECTOR_ID );

		if ( $should_approve && ! $store->is_approved( $plugin_basename, self::CONNECTOR_ID ) ) {
			$store->set_approval( $plugin_basename, self::CONNECTOR_ID, true );
		}

		// If the approval was meant to persist but did not, retry on a later
		// boot rather than recording the seed.
		if ( $should_approve && ! $store->is_approved( $plugin_basename, self::CONNECTOR_ID ) ) {
			return;
		}

		// Retire the stale prior-basename grant once it has been carried forward.
		if (
			'' !== $previous_basename
			&& $previous_basename !== $plugin_basename
			&& $store->is_approved( $previous_basename, self::CONNECTOR_ID )
		) {
			$store->set_approval( $previous_basename, self::CONNECTOR_ID, false );
		}

		$store->remove_pending( $store->pending_key( $plugin_basename, self::CONNECTOR_ID ) );
		update_option( self::OPTION_SELF_APPROVAL_SEEDED, $plugin_basename, false );
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
