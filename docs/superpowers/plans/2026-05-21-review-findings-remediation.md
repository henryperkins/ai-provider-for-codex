# Review Findings Remediation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Prevent internal planning documents and non-shipped helper scripts from leaking into release surfaces, raise the supported runtime floor to WordPress 7.0 plus AI Client SDK 1.0 and WordPress AI plugin 1.0 when that standalone plugin is present, restore the global AI-disable and version-gated provider-registration contract, and make the Codex provider credential affordance point to the per-user connection page.

**Architecture:** Keep all runtime hook registration owned by the gated `load()` -> `Plugin::init()` path, while leaving the actual provider-registration function in `plugin.php` for the AI Client registry integration. The `load()` gates should reject unsupported WordPress and AI Client versions before runtime hooks are registered; the supported floor is WordPress 7.0 plus AI Client SDK 1.0, and if the standalone WordPress AI plugin is present its `WPAI_VERSION` must also be 1.0 or newer. Because WordPress 7.0 is the floor, `wp_supports_ai()` can be treated as available. Mirror release-package exclusions in the Plugin Check wrapper and enforce that mirror in the repo verification script. Since `/sidecar/scripts` remains non-shipping, shipped readme and admin UI copy must describe only shipped sidecar assets such as the systemd service template and config example. Provider metadata should expose the user connection screen as the credential/help target because Codex credentials are per-user account links, not site-wide API keys.

**Tech Stack:** WordPress plugin PHP 7.4, WordPress 7.0+, AI Client SDK 1.0+, WordPress AI plugin 1.0+ when present, WP-CLI `eval-file` verification, Bash release packaging scripts, `rsync`/`zip` release artifacts.

---

## File Structure

- Modify `scripts/release-exclude.txt`: add `/docs` as an internal-only directory excluded from release zips.
- Modify `scripts/plugin-check-release.sh`: add `docs` to `EXCLUDE_DIRECTORIES` so dev-tree Plugin Check matches release packaging.
- Modify `scripts/verify.sh`: add fast static checks that fail if release exclusions, Plugin Check exclusions, or the local AI Client dev dependency drift again.
- Modify `src/Admin/SiteSettings.php`: stop recommending the non-shipping `sidecar/scripts/install-systemd.sh` helper and point setup guidance at shipped sidecar assets.
- Modify `plugin.php`: declare WordPress 7.0 as the minimum, require AI Client SDK 1.0+ and WordPress AI plugin 1.0+ when the standalone plugin is present, restore the `wp_supports_ai()` global-disable gate in `register_provider()`, and remove the unconditional top-level `init` hook.
- Modify `readme.txt`: declare WordPress 7.0 and AI Client SDK 1.0+ as the supported runtime floor, require WordPress AI plugin 1.0+ when that plugin provides the client, and stop claiming non-shipped helper install scripts are included.
- Modify `composer.json` and `composer.lock`: raise the local development AI Client dependency to the same 1.0+ floor as runtime.
- Modify `src/Plugin.php`: register the provider hook from `Plugin::init()` at priority `5`, so provider registration only exists after PHP, WordPress, and AI Client requirements pass.
- Modify `src/Provider/CodexProvider.php`: set `ProviderMetadata::$credentialsUrl` to `UserConnectionPage::page_url()`.
- Modify `scripts/verify.php`: add pre-mutation runtime checks for the WordPress 7.0 / AI Client 1.0 floor, WordPress AI plugin 1.0 floor when applicable, global AI gate, successful enabled registration, shipped setup guidance, and the credentials URL target.

---

### Task 1: Block internal docs and non-shipping helpers from release surfaces

**Files:**
- Modify: `scripts/release-exclude.txt`
- Modify: `scripts/plugin-check-release.sh`
- Modify: `scripts/verify.sh`
- Modify: `src/Admin/SiteSettings.php`
- Modify: `readme.txt`
- Modify: `scripts/verify.php`

- [ ] **Step 1: Update the release exclusion list**

In `scripts/release-exclude.txt`, update the internal-only docs section from:

```text
# Internal-only docs and runtime artifacts
LOCAL-SIDECAR-SPEC.md
PLUGIN-SUBMISSION-READINESS-CHECKLIST.md
/README.md
codex-app.err
```

To:

```text
# Internal-only docs and runtime artifacts
/docs
LOCAL-SIDECAR-SPEC.md
PLUGIN-SUBMISSION-READINESS-CHECKLIST.md
/README.md
codex-app.err
```

- [ ] **Step 2: Mirror the docs exclusion in the Plugin Check wrapper**

In `scripts/plugin-check-release.sh`, replace:

```bash
EXCLUDE_DIRECTORIES="scripts,sidecar/scripts"
```

With:

```bash
EXCLUDE_DIRECTORIES="docs,scripts,sidecar/scripts"
```

- [ ] **Step 3: Add drift checks to repo verification**

In `scripts/verify.sh`, add this block after the tool `php -l` loop and before the `node --input-type=module --check` line:

```bash
for release_exclusion in "/docs" "/sidecar/scripts"; do
	if ! grep -Fxq "$release_exclusion" "$ROOT_DIR/scripts/release-exclude.txt"; then
		echo "scripts/release-exclude.txt must exclude $release_exclusion from release zips." >&2
		exit 1
	fi
done

plugin_check_excludes="$(
	sed -n 's/^EXCLUDE_DIRECTORIES="\([^"]*\)".*/\1/p' "$ROOT_DIR/scripts/plugin-check-release.sh" | head -n 1
)"

for plugin_check_exclusion in "docs" "sidecar/scripts"; do
	case ",$plugin_check_excludes," in
		*,"$plugin_check_exclusion",*) ;;
		*)
			echo "scripts/plugin-check-release.sh must exclude $plugin_check_exclusion from dev-tree Plugin Check runs." >&2
			exit 1
			;;
	esac
done
```

- [ ] **Step 4: Verify the static exclusion checks**

Run:

```bash
bash scripts/verify.sh
```

Expected result:

```text
No output from the new static release-exclusion or Plugin Check exclusion checks. The script proceeds to the JavaScript syntax check and WP-CLI eval-file verification.
```

If `WP_PATH` is not the default local WordPress path, run:

```bash
WP_PATH=/path/to/wordpress bash scripts/verify.sh
```

Expected result:

```text
The script completes without the release-exclusion or Plugin Check exclusion error messages.
```

- [ ] **Step 5: Align shipped setup guidance with shipped files**

Because `/sidecar/scripts` remains excluded from release zips, remove shipped UI and readme references to `sidecar/scripts/install-systemd.sh` instead of making release docs point at a missing helper.

In `src/Admin/SiteSettings.php`, replace the setup-helper command variable:

```php
$install_command  = sprintf( 'sudo %s/sidecar/scripts/install-systemd.sh', $plugin_dir );
$manual_command   = sprintf( 'CODEX_WP_BEARER_TOKEN=replace-me python3 %s/sidecar/app/main.py', $plugin_dir );
```

With shipped asset paths:

```php
$service_template = sprintf( '%s/sidecar/systemd/codex-wp-sidecar.service', $plugin_dir );
$env_example      = sprintf( '%s/sidecar/config.example.env', $plugin_dir );
$manual_command   = sprintf( 'CODEX_WP_BEARER_TOKEN=replace-me python3 %s/sidecar/app/main.py', $plugin_dir );
```

Then update the Quick setup list item that currently says:

```php
<?php esc_html_e( 'Run the bundled systemd installer from the installed plugin directory. It writes the systemd unit, creates the shared environment file, generates a bearer token when needed, starts the sidecar, and syncs the WordPress options when WP-CLI is available:', 'ai-provider-for-codex' ); ?>
<p><code><?php echo esc_html( $install_command ); ?></code></p>
```

To describe only shipped files:

```php
<?php esc_html_e( 'Use the bundled systemd service template and environment example from the installed plugin directory:', 'ai-provider-for-codex' ); ?>
<p><code><?php echo esc_html( $service_template ); ?></code></p>
<p><code><?php echo esc_html( $env_example ); ?></code></p>
```

In `readme.txt`, update the sidecar bundle FAQ from:

```text
Yes. The plugin bundle includes the sidecar source, setup guide, and helper install scripts in the `sidecar/` directory. An administrator still needs to configure and run that local service on the same host as WordPress.
```

To:

```text
Yes. The plugin bundle includes the sidecar source, setup guide, systemd service template, and environment example in the `sidecar/` directory. An administrator still needs to configure and run that local service on the same host as WordPress.
```

In `scripts/verify.php`, replace the setup-help assertion:

```php
$codex_provider_assert(
	false !== strpos( $codex_provider_site_settings_help_html, 'sidecar/scripts/install-systemd.sh' ),
	'Site settings contextual Help should recommend the bundled systemd installer script.'
);
```

With assertions that prevent this regression:

```php
$codex_provider_assert(
	false === strpos( $codex_provider_site_settings_help_html, 'sidecar/scripts/install-systemd.sh' ),
	'Site settings contextual Help should not recommend non-shipped helper scripts.'
);
$codex_provider_assert(
	false !== strpos( $codex_provider_site_settings_help_html, 'sidecar/systemd/codex-wp-sidecar.service' ),
	'Site settings contextual Help should recommend the shipped systemd service template.'
);
```

- [ ] **Step 6: Verify the packaged zip does not contain internal docs or non-shipping helpers**

Run:

```bash
DIST_DIR="$(mktemp -d)" bash scripts/package-release.sh
```

Expected result includes:

```text
Created /tmp/.../ai-provider-for-codex-0.1.1.zip
```

Then inspect the created zip path printed by the command:

```bash
unzip -l /tmp/.../ai-provider-for-codex-0.1.1.zip | grep -E '/(docs|sidecar/scripts)/'
```

Expected result:

```text
No output, and grep exits non-zero because docs/ and sidecar/scripts/ are absent from the zip.
```

- [ ] **Step 7: Commit the release-surface fix**

```bash
git add scripts/release-exclude.txt scripts/plugin-check-release.sh scripts/verify.sh src/Admin/SiteSettings.php readme.txt scripts/verify.php
git commit -m "fix: exclude internal docs from release checks"
```

---

### Task 2: Restore gated provider registration behavior

**Files:**
- Modify: `plugin.php`
- Modify: `readme.txt`
- Modify: `composer.json`
- Modify: `composer.lock`
- Modify: `src/Plugin.php`
- Modify: `scripts/verify.sh`
- Modify: `scripts/verify.php`

- [ ] **Step 1: Declare the WordPress 7.0 support floor**

In `plugin.php`, update the plugin header and minimum-version constant from:

```php
 * Requires at least: 6.9
```

```php
const MIN_WP_VERSION = '6.9';
```

To:

```php
 * Requires at least: 7.0
```

```php
const MIN_WP_VERSION = '7.0';
```

In `readme.txt`, update the header from:

```text
Requires at least: 6.9
Tested up to: 7.0
```

To:

```text
Requires at least: 7.0
Tested up to: 7.0
```

- [ ] **Step 2: Raise the local AI Client development dependency**

In `composer.json`, update the development constraint from:

```json
"wordpress/php-ai-client": "^0.4 || dev-trunk"
```

To:

```json
"wordpress/php-ai-client": "^1.0 || dev-trunk"
```

Then update the lock file:

```bash
composer update wordpress/php-ai-client --with-dependencies
```

Expected result:

```text
composer.lock records wordpress/php-ai-client at 1.0.0 or newer, or at dev-trunk when testing against trunk.
```

- [ ] **Step 3: Add a local dependency drift check to repo verification**

In `scripts/verify.sh`, add this block after the release-exclusion / Plugin Check exclusion checks from Task 1 and before the JavaScript syntax check:

```bash
locked_ai_client_version="$(
	php -r '
		$lock = json_decode(file_get_contents($argv[1]), true);
		foreach (($lock["packages-dev"] ?? []) as $package) {
			if (($package["name"] ?? "") === "wordpress/php-ai-client") {
				echo (string) ($package["version"] ?? "");
				exit;
			}
		}
	' "$ROOT_DIR/composer.lock"
)"

if [[ -z "$locked_ai_client_version" ]]; then
	echo "composer.lock must include wordpress/php-ai-client for local verification." >&2
	exit 1
fi

if [[ "$locked_ai_client_version" != dev-* ]] && ! php -r 'exit(version_compare($argv[1], "1.0", ">=") ? 0 : 1);' "$locked_ai_client_version"; then
	echo "composer.lock must use wordpress/php-ai-client 1.0 or newer for local verification. Current locked version: $locked_ai_client_version" >&2
	exit 1
fi
```

- [ ] **Step 4: Require AI Client SDK 1.0 and WordPress AI plugin 1.0 when applicable**

In `plugin.php`, update `check_ai_client()` from:

```php
function check_ai_client(): bool {
	if ( class_exists( AiClient::class ) ) {
		return true;
	}

	add_action(
		'admin_notices',
		static function () {
				requirement_notice(
					__( 'AI Provider for Codex requires the WordPress AI Client, bundled in WordPress 7.0+ or available separately on WordPress 6.9.', 'ai-provider-for-codex' )
				);
		}
	);

	return false;
}
```

To:

```php
function check_ai_client(): bool {
	$has_supported_ai_client = class_exists( AiClient::class ) && version_compare( AiClient::VERSION, '1.0', '>=' );
	$has_supported_ai_plugin = ! defined( 'WPAI_VERSION' ) || version_compare( (string) constant( 'WPAI_VERSION' ), '1.0', '>=' );

	if ( $has_supported_ai_client && $has_supported_ai_plugin ) {
		return true;
	}

	add_action(
		'admin_notices',
		static function () {
			$current_client_version = class_exists( AiClient::class ) ? AiClient::VERSION : __( 'not available', 'ai-provider-for-codex' );
			$current_plugin_version = defined( 'WPAI_VERSION' ) ? (string) constant( 'WPAI_VERSION' ) : __( 'not detected', 'ai-provider-for-codex' );

			requirement_notice(
				sprintf(
					/* translators: 1: current AI Client version or availability status, 2: current WordPress AI plugin version or detection status. */
					__( 'AI Provider for Codex requires AI Client 1.0 or newer. If the WordPress AI plugin is installed, it must also be version 1.0 or newer. Current AI Client version: %1$s. Current WordPress AI plugin version: %2$s.', 'ai-provider-for-codex' ),
					$current_client_version,
					$current_plugin_version
				)
			);
		}
	);

	return false;
}
```

In `readme.txt`, update the runtime requirement from:

```text
* WordPress 6.9 or newer with the WordPress AI Client available, either bundled in WordPress 7.0+ or installed separately on WordPress 6.9
```

To:

```text
* WordPress 7.0 or newer with AI Client SDK 1.0 or newer. If the standalone WordPress AI plugin provides the client, WordPress AI plugin 1.0 or newer is also required.
```

- [ ] **Step 5: Restore the global AI support gate in `register_provider()`**

In `plugin.php`, update `register_provider()` from:

```php
function register_provider(): void {
	if ( ! class_exists( AiClient::class ) ) {
		return;
	}

	$registry = AiClient::defaultRegistry();

	if ( $registry->hasProvider( CodexProvider::class ) ) {
		return;
	}

	$registry->registerProvider( CodexProvider::class );
}
```

To:

```php
function register_provider(): void {
	if ( ! class_exists( AiClient::class ) ) {
		return;
	}

	if ( ! wp_supports_ai() ) {
		return;
	}

	$registry = AiClient::defaultRegistry();

	if ( $registry->hasProvider( CodexProvider::class ) ) {
		return;
	}

	$registry->registerProvider( CodexProvider::class );
}
```

Rationale: WordPress 7.0 is the minimum supported version, so `wp_supports_ai()` is part of the supported runtime contract. The plugin must honor the administrative global AI opt-out before registering the Codex provider.

- [ ] **Step 6: Remove the unconditional top-level provider hook**

In `plugin.php`, remove this top-level hook registration:

```php
add_action( 'init', __NAMESPACE__ . '\\register_provider', 5 );
```

Leave the activation, deactivation, and `plugins_loaded` hooks in place:

```php
register_activation_hook( PLUGIN_FILE, __NAMESPACE__ . '\\activate_plugin' );
register_deactivation_hook( PLUGIN_FILE, __NAMESPACE__ . '\\deactivate_plugin' );
add_action( 'plugins_loaded', __NAMESPACE__ . '\\load' );
```

- [ ] **Step 7: Register the provider hook from the gated plugin init path**

In `src/Plugin.php`, add this line at the start of `Plugin::init()`, before the installer hook:

```php
add_action( 'init', __NAMESPACE__ . '\\register_provider', 5 );
```

The beginning of `Plugin::init()` should become:

```php
public function init(): void {
	add_action( 'init', __NAMESPACE__ . '\\register_provider', 5 );
	add_action( 'init', [ Installer::class, 'maybe_upgrade' ], 1 );
	ConnectionRefreshScheduler::register_hooks();
```

Rationale: `Plugin::init()` only runs after `check_php_version()`, `check_wp_version()`, and `check_ai_client()` pass in `load()`. Moving the hook here makes provider registration follow the same PHP, WordPress 7.0, and AI Client 1.0 gates as the rest of the plugin.

- [ ] **Step 8: Add provider registration verifier imports**

In `scripts/verify.php`, add this import near the other provider imports:

```php
use AIProviderForCodex\Provider\CodexProvider;
```

The provider import group should include:

```php
use AIProviderForCodex\Provider\CodexProvider;
use AIProviderForCodex\Provider\ModelCatalogState;
use AIProviderForCodex\Provider\SupportChecks;
```

- [ ] **Step 9: Add a helper to reset the AI Client default registry during verification**

In `scripts/verify.php`, after the `$codex_provider_with_mock_runtime` helper definition, add:

```php
			$codex_provider_reset_ai_client_registry = static function (): void {
				$property = new ReflectionProperty( AiClient::class, 'defaultRegistry' );
				$property->setAccessible( true );
				$property->setValue( null, null );
			};
```

- [ ] **Step 10: Add pre-mutation verifier assertions for the version floor, global AI gate, and enabled registration**

In `scripts/verify.php`, after the reset helper from Step 9 and before the `$codex_provider_original_options` assignment, add:

```php
			$codex_provider_assert(
				is_wp_version_compatible( \AIProviderForCodex\MIN_WP_VERSION ),
				'AI Provider for Codex verification requires WordPress 7.0 or newer.'
			);
			$codex_provider_assert(
				class_exists( AiClient::class ),
				'AI Provider for Codex requires AI Client 1.0 or newer, but the AI Client class is not available.'
			);
			$codex_provider_assert(
				version_compare( AiClient::VERSION, '1.0', '>=' ),
				'AI Provider for Codex requires AI Client 1.0 or newer.'
			);
			$codex_provider_assert(
				! defined( 'WPAI_VERSION' ) || version_compare( (string) constant( 'WPAI_VERSION' ), '1.0', '>=' ),
				'AI Provider for Codex requires WordPress AI plugin 1.0 or newer when the standalone plugin is installed.'
			);

			$codex_provider_disable_ai_filter = static function (): bool {
				return false;
			};

			add_filter( 'wp_supports_ai', $codex_provider_disable_ai_filter );

			try {
				$codex_provider_reset_ai_client_registry();
				\AIProviderForCodex\register_provider();
				$codex_provider_assert(
					! AiClient::defaultRegistry()->hasProvider( CodexProvider::class ),
					'Codex provider should not register when wp_supports_ai disables AI globally.'
				);
			} finally {
				remove_filter( 'wp_supports_ai', $codex_provider_disable_ai_filter );
			}

			$codex_provider_reset_ai_client_registry();
			\AIProviderForCodex\register_provider();
			$codex_provider_assert(
				AiClient::defaultRegistry()->hasProvider( CodexProvider::class ),
				'Codex provider should register when AI is supported and the AI Client is available.'
			);
```

Rationale: the verifier should fail before it writes options, creates users, or mutates custom tables when run against an unsupported WordPress, AI Client SDK, or standalone WordPress AI plugin runtime. The `class_exists()` assertion prevents a missing AI Client from surfacing as a PHP fatal. Resetting `AiClient::$defaultRegistry` avoids a false pass caused by provider registration that happened earlier during WordPress bootstrap.

- [ ] **Step 11: Verify provider registration behavior**

Run:

```bash
WP_PATH=/path/to/wordpress bash scripts/verify.sh
```

Expected result:

```text
The verifier completes without any of these messages:
AI Provider for Codex verification requires WordPress 7.0 or newer.
AI Provider for Codex requires AI Client 1.0 or newer, but the AI Client class is not available.
AI Provider for Codex requires AI Client 1.0 or newer.
AI Provider for Codex requires WordPress AI plugin 1.0 or newer when the standalone plugin is installed.
Codex provider should not register when wp_supports_ai disables AI globally.
Codex provider should register when AI is supported and the AI Client is available.
```

- [ ] **Step 12: Commit the provider registration fix**

```bash
git add plugin.php readme.txt composer.json composer.lock src/Plugin.php scripts/verify.sh scripts/verify.php
git commit -m "fix: gate codex provider registration"
```

---

### Task 3: Restore a useful credentials/help URL in provider metadata

**Files:**
- Modify: `src/Provider/CodexProvider.php`
- Modify: `scripts/verify.php`

- [ ] **Step 1: Import the user connection page in provider metadata**

In `src/Provider/CodexProvider.php`, add this import with the other imports:

```php
use AIProviderForCodex\Admin\UserConnectionPage;
```

The top import block should begin:

```php
use AIProviderForCodex\Admin\UserConnectionPage;
use AIProviderForCodex\Models\CodexTextGenerationModel;
use AIProviderForCodex\Runtime\Settings;
```

- [ ] **Step 2: Point `credentialsUrl` at the per-user connection page**

In `src/Provider/CodexProvider.php`, update the provider metadata args from:

```php
$provider_metadata_args = [
	'codex',
	'Codex',
	ProviderTypeEnum::cloud(),
	null,
	null,
];
```

To:

```php
$provider_metadata_args = [
	'codex',
	'Codex',
	ProviderTypeEnum::cloud(),
	UserConnectionPage::page_url(),
	null,
];
```

Rationale: the old plugin URL was misleading, but `null` removes the Connectors screen affordance entirely. The user connection page is the correct destination because each user links their own Codex or ChatGPT account.

- [ ] **Step 3: Add verifier assertions for the metadata URL**

In `scripts/verify.php`, after the provider registration assertions added in Task 2, add:

```php
		$codex_provider_metadata = CodexProvider::metadata();
		$codex_provider_assert(
			UserConnectionPage::page_url() === $codex_provider_metadata->getCredentialsUrl(),
			'Codex provider credentials URL should point to the per-user connection page.'
		);
```

The verifier already imports `UserConnectionPage`, so no additional admin import is needed.

- [ ] **Step 4: Verify metadata behavior**

Run:

```bash
WP_PATH=/path/to/wordpress bash scripts/verify.sh
```

Expected result:

```text
The verifier completes without this message:
Codex provider credentials URL should point to the per-user connection page.
```

- [ ] **Step 5: Commit the provider metadata UX fix**

```bash
git add src/Provider/CodexProvider.php scripts/verify.php
git commit -m "fix: point provider credentials url to user connection"
```

---

### Task 4: Final release verification

**Files:**
- No code changes expected after this task.

- [ ] **Step 1: Run the repo verifier**

Run:

```bash
WP_PATH=/path/to/wordpress bash scripts/verify.sh
```

Expected result:

```text
PHP syntax lint passes, connector JavaScript syntax check passes, and scripts/verify.php completes under WP-CLI without throwing RuntimeException.
```

- [ ] **Step 2: Build a release zip from a temporary dist directory**

Run:

```bash
DIST_DIR="$(mktemp -d)" bash scripts/package-release.sh
```

Expected result includes:

```text
Created /tmp/.../ai-provider-for-codex-0.1.1.zip
Before submitting to WordPress.org, also run:
  - Release-style Plugin Check:  WP_PATH=/path/to/wordpress bash scripts/plugin-check-release.sh
  - Readme validator:            https://wordpress.org/plugins/developers/readme-validator/
```

- [ ] **Step 3: Confirm internal docs and non-shipping helpers are absent from the zip**

Run against the zip path printed by Step 2:

```bash
unzip -l /tmp/.../ai-provider-for-codex-0.1.1.zip | grep -E '/(docs|sidecar/scripts)/'
```

Expected result:

```text
No output, and grep exits non-zero.
```

- [ ] **Step 4: Run release-style Plugin Check**

Run:

```bash
WP_PATH=/path/to/wordpress bash scripts/plugin-check-release.sh
```

Expected result:

```text
Plugin Check runs against ai-provider-for-codex with docs, scripts, and sidecar/scripts excluded from the dev checkout scan.
```

- [ ] **Step 5: Inspect final diff scope**

Run:

```bash
git status --short
```

Expected result after the per-task commits above:

```text
No uncommitted changes in the remediation files.
```

If the plan is executed without intermediate commits, run:

```bash
git diff --stat
```

Expected result includes only these files:

```text
readme.txt
plugin.php
composer.json
composer.lock
src/Admin/SiteSettings.php
src/Plugin.php
src/Provider/CodexProvider.php
scripts/release-exclude.txt
scripts/plugin-check-release.sh
scripts/verify.sh
scripts/verify.php
```

- [ ] **Step 6: Commit any final verification-only adjustments if needed**

If Task 4 revealed a small verifier wording or command adjustment, commit only that adjustment:

```bash
git add scripts/verify.sh scripts/verify.php
git commit -m "test: cover release and provider registration contracts"
```

Do not make behavior changes in this final task. If behavior changes are needed, return to the relevant task and repeat its verification step.

---

## Review Finding Coverage

- `scripts/release-exclude.txt` docs leak: addressed by `/docs`, mirrored Plugin Check exclusion, static drift checks, and packaged zip inspection.
- Non-shipped `sidecar/scripts/install-systemd.sh` references: addressed by keeping `/sidecar/scripts` excluded, removing shipped readme/admin UI references to the helper, asserting the admin help points at shipped sidecar assets, and checking the zip does not include `sidecar/scripts/`.
- WordPress 7.0, AI Client SDK 1.0, and WordPress AI plugin 1.0 support floor when the standalone plugin is present: addressed by updating the plugin/readme declarations, `check_wp_version()`, `check_ai_client()`, Composer dev dependency metadata, pre-mutation verifier assertions, and the `scripts/verify.sh` Composer lock drift check.
- Missing-AI-Client verifier fatal risk: addressed by adding `class_exists( AiClient::class )` before reading `AiClient::VERSION` and placing the runtime-floor assertions before option, user, or table mutations.
- `plugin.php` missing `wp_supports_ai()` gate: addressed by restoring the global AI-support guard and adding verifier coverage for disabled and enabled AI states.
- `plugin.php` unconditional provider hook before version gates: addressed by removing the top-level `init` hook and adding the hook inside `Plugin::init()` after `load()` requirements pass.
- `src/Provider/CodexProvider.php` `credentialsUrl` regression: addressed by pointing metadata at `UserConnectionPage::page_url()` and asserting the value in `scripts/verify.php`.
