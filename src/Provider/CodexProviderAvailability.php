<?php
/**
 * Provider availability.
 *
 * @package HtperkinsAIProviderForCodex
 */

declare( strict_types=1 );

namespace Htperkins\AIProviderForCodex\Provider;

use Htperkins\AIProviderForCodex\Runtime\HealthMonitor;
use Htperkins\AIProviderForCodex\Runtime\Settings;
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
