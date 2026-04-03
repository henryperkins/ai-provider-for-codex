<?php
/**
 * Provider availability.
 *
 * @package AIProviderForCodex
 */

declare( strict_types=1 );

namespace AIProviderForCodex\Provider;

use AIProviderForCodex\Runtime\HealthMonitor;
use AIProviderForCodex\Runtime\Settings;
use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;

/**
 * Reports site-level provider readiness.
 */
final class CodexProviderAvailability implements ProviderAvailabilityInterface {

	/**
	 * Returns whether the site has runtime credentials and no cached hard failure.
	 *
	 * @return bool
	 */
	public function isConfigured(): bool {
		return Settings::has_required_configuration() && HealthMonitor::is_available();
	}
}
