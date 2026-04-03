=== AI Provider for Codex ===
Contributors: lakefrontdigital
Tags: ai, codex, wordpress-ai-client
Requires at least: 7.0
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 0.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Broker-backed Codex provider scaffold for the WordPress AI Client.

== Description ==

AI Provider for Codex is a scaffold for a standalone WordPress AI provider plugin.

This initial version:

* registers a `codex` provider with the WordPress AI Client
* shows a custom `Codex` card in `Settings > Connectors`
* stores site-level broker registration settings
* stores per-user Codex connection metadata and broker snapshots locally
* builds the AI Client model catalog from broker-refreshed per-user snapshots, with site settings as a bootstrap fallback
* exposes local status and connect/disconnect/refresh endpoints for the Connectors UI and supporting admin pages

This scaffold expects a hosted broker service for Codex auth and execution.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/`.
2. Activate the plugin through the WordPress admin.
3. Open `Settings > Connectors` and review the `Codex` connector card.
4. If the site is not registered yet, open `Settings > Codex Provider` from the connector card or the Plugins screen and exchange the broker installation code.
5. Use the connector card or `Users > Codex Provider` to link a WordPress user to Codex.

== Development ==

For repeatable local verification, run either of the following from a WordPress environment where the plugin is active:

* `WP_PATH=/path/to/site ./scripts/verify.sh`
* `wp --path=/path/to/site eval-file wp-content/plugins/ai-provider-for-codex/scripts/verify.php`

== Changelog ==

= 0.1.0 =
* Initial scaffold with Connectors integration, cached broker health, broker-refreshed model catalog aggregation, and repeatable verification checks.
