=== Scriptorium AI Provider for Codex ===
Contributors: htperkins
Tags: ai, codex, wordpress-ai-client
Requires at least: 7.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.1.5
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Local-runtime Codex provider for the WordPress AI Client.

== Description ==

Scriptorium AI Provider for Codex adds a `codex` provider to the WordPress AI Client and sends text-generation and text-to-image requests through a site-level Codex app-server running on the same host as WordPress.

Image generation is exposed as a separate `codex-image` model. Reference-image editing, masks, variations, and other vision workflows remain out of scope.

This plugin is intended for self-managed environments that can run a local service. It is not intended for shared hosting or managed hosts that cannot run background processes.

Codex and ChatGPT are products of OpenAI. This plugin is an independent integration and is not affiliated with, endorsed by, or sponsored by OpenAI.

Features:

* registers a `codex` provider with the WordPress AI Client
* adds a `Codex` connector card to `Settings > Connectors`
* stores site-level runtime settings in WordPress
* supports a local `codex app-server` WebSocket endpoint such as `ws://127.0.0.1:4500`
* uses the Codex auth configured for the service user that starts app-server
* exposes configured text model IDs through the WordPress AI Client
* exposes `codex-image` for text-to-image generation when app-server reports image-generation capability
* exposes local runtime status and diagnostics for the admin UI

Runtime requirements:

* WordPress 7.0 or newer with AI Client SDK 1.0 or newer. If the standalone WordPress AI plugin provides the client, WordPress AI plugin 1.0 or newer is also required.
* PHP 7.4 or newer
* the `codex` CLI installed on the same host
* a site-level Codex login for the service user that starts app-server
* permission to run a localhost-only app-server process or daemon
* administrator access to configure the local app-server endpoint after plugin install

== Installation ==

Important: activating the plugin in wp-admin is only the first step. An administrator also needs terminal access on the same host as WordPress to start Codex app-server.

1. Upload the plugin to `/wp-content/plugins/`.
2. Activate the plugin through the WordPress admin.
3. On the same host as WordPress, install the `codex` CLI.
4. As the service user that will run app-server, run `codex login` once.
5. Start `codex app-server --listen ws://127.0.0.1:4500`. The plugin settings screen shows copy-paste systemd and environment-file snippets.
6. Create `/etc/codex-app-server.env` with the runtime values shown in `Settings > Codex Provider`, start the service, and make the file readable by PHP if you want the plugin to auto-detect the Runtime URL.
7. In WordPress, open `Settings > Codex Provider`. If the value was not auto-detected, enter it manually. The default Runtime URL is `ws://127.0.0.1:4500`.
8. Open `Settings > Connectors` and confirm that the `Codex` connector reports a healthy local runtime.

If you are not using systemd, run `codex app-server` with the same environment variables shown on the plugin settings screen.

The plugin can also auto-detect the runtime URL and optional WebSocket bearer token from `/etc/codex-app-server.env` when that file is readable by PHP.

Architecture notes for reviewers:

* provider availability is based on the local app-server endpoint rather than a remote `/v1/models` request
* the plugin uses site-level Codex auth from the service user that starts app-server
* REST endpoints under `htperkins-aipfc/v1/*` power local status and diagnostics flows
* WordPress/ai credential filters are used because Codex does not rely on a remote API key stored in WordPress
* if the WordPress AI plugin's experimental Connector Approval feature is enabled, this plugin performs a one-time approval for its own `codex` connector during activation or the next normal boot. It does not pre-approve while the experiment is disabled, and if the approval is later revoked, runtime calls stay blocked until an administrator re-approves it under `Tools > Connector Approvals` or disables the experiment.

Developers can filter `htperkins_aipfc_runtime_request_timeout` to change local runtime HTTP timeouts.

== Frequently Asked Questions ==

= Does this plugin work without a local runtime? =

No. The plugin requires a localhost Codex app-server runtime and the `codex` CLI on the same host as WordPress.

= Is app-server included in the plugin zip? =

No. The WordPress.org plugin zip contains the PHP provider, assets, readme, and generated setup snippets. Codex app-server is provided by the separately installed `codex` CLI.

= Does every user share one Codex account? =

Yes. This WordPress.org build uses site-level Codex auth from the service user that starts app-server. Run `codex login` for that service user before starting app-server.

= What kind of hosting is supported? =

This plugin is intended for self-managed environments where you control the server and can run local background processes. It is usually not a fit for typical shared hosting.

== External services ==

This plugin requires a local Codex app-server runtime from the `codex` CLI. That local runtime connects to OpenAI's Codex and ChatGPT services after an administrator configures the runtime and starts app-server with a logged-in site-level Codex account.

When a user sends a request through the provider, data exchanged with OpenAI's Codex and ChatGPT services can include:

* prompt text and system instructions submitted through the WordPress AI Client
* image prompts submitted through the WordPress AI Client when `codex-image` is selected
* the selected model ID and optional response-format schema
* generated image data returned by Codex for requested image results
* account, capability, model-catalog, and rate-limit metadata returned by the Codex runtime

OpenAI Terms of Use: https://openai.com/policies/terms-of-use/

OpenAI Privacy Policy: https://openai.com/policies/privacy-policy/

== Privacy ==

This plugin stores the following data in WordPress:

* the local runtime URL
* the optional app-server WebSocket bearer token, unless it is managed externally
* a locally generated suggested bearer token, shown only as optional setup guidance and never transmitted unless an administrator uses it; removed on uninstall
* each user's preferred model selection

This plugin does not store Codex auth files in WordPress. Codex auth and runtime files are owned by the separately installed `codex` CLI, typically under the `CODEX_HOME` used by the service account that starts app-server.

The app-server endpoint is intended for localhost-only communication between WordPress and the runtime. The optional WebSocket bearer token is only needed when app-server is started with WebSocket auth.

== Support ==

Support is limited to documented, self-managed environments that can run Codex app-server and the `codex` CLI on the same host as WordPress.

== Changelog ==

= 0.1.5 =
* Add site-level Codex app-server mode so the WordPress.org package can function without bundling a custom sidecar runtime.
* Add text-to-image generation through the local Codex runtime when app-server reports image-generation capability.
* Align the plugin text domain, package slug, and main plugin file with the assigned `ai-provider-for-codex` slug while keeping the Scriptorium display name.
* Prefix plugin-owned namespaces, options, hooks, REST routes, script handles, and tables with the `htperkins` ownership prefix.
* Exclude the local sidecar source, systemd unit, and environment example from the WordPress.org plugin zip.
* Carry the connector approval across the plugin rename (preserving any prior admin revocation) for sites using the WordPress AI Connector Approval experiment.
* Deliver admin screen styles through wp_add_inline_style() and the connection-page config through script module data instead of inline style/script tags.
* Document that the plugin is an independent integration not affiliated with OpenAI.

= 0.1.4 =
* Add an explicit capability check to the dismiss-notice AJAX handler before updating user metadata.
* Add release-gate coverage for the dismiss-notice authorization contract.

= 0.1.3 =
* Add Connector Approval self-approval handling so the Codex connector can unblock itself when the WordPress AI experiment is active.
* Improve Connectors status messaging and local runtime health recovery for routed admin screens.

= 0.1.2 =
* Improve the Codex account connection flow with inline device-code approval, automatic status checks, copy-code support, and retry actions.

= 0.1.1 =
* Build the Connectors logo path through `WP_PLUGIN_DIR` so symlinked plugin installs render the logo correctly.
* Refresh linked-connection snapshots hourly so cached model lists and account metadata stay current without manual refresh.
* Align provider and connector registration with the canonical WordPress AI Client registration flow.
* Move the "How Codex Provider works" overview into the standard WordPress contextual Help tab on `Users > Codex Provider`.
* Internal hardening of the local runtime client, settings, and response mapping.

= 0.1.0 =
* Initial local-runtime release with Connectors integration, per-user account linking, local runtime snapshots, and Codex provider support for the WordPress AI Client.

== Upgrade Notice ==

= 0.1.5 =
Renaming the main plugin file to match the assigned slug can deactivate an in-place update. Reactivate it from the Plugins screen after updating. Fresh installs are unaffected.
