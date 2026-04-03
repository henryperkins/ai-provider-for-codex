=== AI Provider for Codex ===
Contributors: lakefrontdigital
Tags: ai, codex, wordpress-ai-client
Requires at least: 7.0
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 0.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Local-runtime Codex provider for the WordPress AI Client.

== Description ==

AI Provider for Codex is a standalone WordPress AI provider plugin for Codex models.

This initial version:

* registers a `codex` provider with the WordPress AI Client
* shows a custom `Codex` card in `Settings > Connectors`
* stores local runtime settings for the site
* stores per-user Codex connection metadata and runtime snapshots locally
* builds the AI Client model catalog from per-user runtime snapshots, with site fallback models before a user connects
* exposes local status and connect/disconnect/refresh endpoints for the Connectors UI and supporting admin pages
* supports device-code login through a localhost sidecar that wraps `codex app-server`

This plugin expects a localhost sidecar runtime for Codex auth and execution.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/`.
2. Activate the plugin through the WordPress admin.
3. Configure the local runtime URL and bearer token in `Settings > Codex Provider`.
4. Open `Settings > Connectors` and review the `Codex` connector card.
5. Use the connector card or `Users > Codex Provider` to link a WordPress user with the device-code flow.

For automated installs on systemd hosts, run `sudo ./sidecar/scripts/install-systemd.sh`. That writes `/etc/codex-wp-sidecar.env`, starts the sidecar, and the plugin auto-detects the runtime URL and bearer token from that shared env file.

== Development ==

For repeatable local verification, run either of the following from a WordPress environment where the plugin is active:

* `WP_PATH=/path/to/site ./scripts/verify.sh`
* `wp --path=/path/to/site eval-file wp-content/plugins/ai-provider-for-codex/scripts/verify.php`

== Changelog ==

= 0.1.0 =
* Initial local-runtime scaffold with Connectors integration, per-user runtime snapshots, and repeatable verification checks.
