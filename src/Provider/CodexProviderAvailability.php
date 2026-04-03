<?php
/**
 * Provider availability.
 *
 * @package AIProviderForCodex
 */

declare( strict_types=1 );

namespace AIProviderForCodex\Provider;

use AIProviderForCodex\Broker\HealthMonitor;
use AIProviderForCodex\Broker\Settings;
use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;

/**
 * Reports site-level provider readiness.
 */
final class CodexProviderAvailability implements ProviderAvailabilityInterface {

	/**
	 * Returns whether the site has broker credentials and no cached hard failure.
	 *
	 * @return bool
	 */
	public function isConfigured(): bool {
		return Settings::has_required_site_configuration() && HealthMonitor::is_available();
	}
}
