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

AI Provider for Codex adds a `codex` provider to the WordPress AI Client and sends requests through a localhost runtime that runs on the same host as WordPress.

This plugin is intended for self-managed environments that can run a local service. It is not intended for shared hosting or managed hosts that cannot run background processes.

Features:

* registers a `codex` provider with the WordPress AI Client
* adds a `Codex` connector card to `Settings > Connectors`
* stores site-level runtime settings in WordPress
* lets each WordPress user connect their own Codex or ChatGPT account with a device-code flow
* stores per-user connection metadata and cached runtime snapshots in WordPress
* discovers available models from each user's local runtime snapshot, with site fallback models before a user connects
* exposes local status and connect, disconnect, and refresh flows for the admin UI

Runtime requirements:

* WordPress 7.0 or newer with the WordPress AI Client available
* PHP 8.0 or newer
* Python 3.11 or newer on the same host as WordPress
* the `codex` CLI installed on the same host
* permission to run a localhost-only background service or daemon
* administrator access to configure the bundled sidecar runtime after plugin install

== Installation ==

1. Upload the plugin to `/wp-content/plugins/`.
2. Activate the plugin through the WordPress admin.
3. On the same host as WordPress, install Python 3.11+ and the `codex` CLI.
4. The plugin bundle includes the companion Codex sidecar runtime in the `sidecar/` directory. Follow the bundled setup guide in `sidecar/README.md`.
5. Configure the sidecar to listen on loopback only and set a shared bearer token with `CODEX_WP_BEARER_TOKEN`.
6. If you use systemd, the bundle also includes `sidecar/scripts/install-systemd.sh` to install the service from the plugin directory.
7. In WordPress, open `Settings > Codex Provider` and configure the runtime URL and bearer token, unless they are managed externally.
8. Open `Settings > Connectors` and confirm that the `Codex` connector reports a healthy local runtime.
9. Each user who wants to use Codex should open `Users > Codex Provider` and complete the device-code login flow.

The plugin can also auto-detect the runtime URL and bearer token from `/etc/codex-wp-sidecar.env` when that file is readable by PHP. The sidecar setup guide includes an example systemd unit.

== Frequently Asked Questions ==

= Does this plugin work without a local runtime? =

No. The plugin requires a localhost sidecar runtime and the `codex` CLI on the same host as WordPress.

= Is the sidecar included in the plugin zip? =

Yes. The plugin bundle includes the sidecar source, setup guide, and helper install scripts in the `sidecar/` directory. An administrator still needs to configure and run that local service on the same host as WordPress.

= Does every user share one Codex account? =

No. Each WordPress user connects their own Codex or ChatGPT account. The plugin keeps WordPress-side metadata per user, and the sidecar keeps Codex auth files per user on disk.

= What kind of hosting is supported? =

This plugin is intended for self-managed environments where you control the server and can run local background processes. It is usually not a fit for typical shared hosting.

== External services ==

This plugin requires a local sidecar runtime that wraps `codex app-server`. That local runtime connects to OpenAI's Codex and ChatGPT services after an administrator configures the runtime and a user starts the connection flow.

When a user connects an account or sends a request through the provider, data sent off the WordPress host can include:

* prompt text and system instructions submitted through the WordPress AI Client
* the selected model ID and optional response-format schema
* account, model-catalog, and rate-limit metadata returned by the Codex runtime
* authentication data needed to complete the user-initiated device-code login flow

OpenAI Terms of Use: https://openai.com/policies/terms-of-use/

OpenAI Privacy Policy: https://openai.com/policies/privacy-policy/

== Privacy ==

This plugin stores the following data in WordPress:

* the local runtime URL
* the shared bearer token, unless it is managed externally
* per-user connection metadata such as connection ID, account email, plan type, auth mode, and session expiry
* cached model and rate-limit snapshots
* pending device-code session metadata
* each user's preferred model selection

This plugin stores the following data outside WordPress in the local sidecar storage directory, typically under `/var/lib/codex-wp/users/<wp_user_id>/`:

* per-user Codex auth files such as `auth.json`
* other Codex-managed local runtime files created by the `codex` CLI for that user

The sidecar is designed for localhost-only communication between WordPress and the runtime. It uses a shared bearer token for authentication and does not send data to external services until an administrator configures the runtime and a user initiates account connection or request execution.

== Support ==

Support is limited to documented, self-managed environments that can run the local sidecar runtime and the `codex` CLI on the same host as WordPress.

== Changelog ==

= 0.1.0 =
* Initial local-runtime release with Connectors integration, per-user account linking, local runtime snapshots, and Codex provider support for the WordPress AI Client.
