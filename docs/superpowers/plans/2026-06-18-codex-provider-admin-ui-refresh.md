# Codex Provider Admin UI Refresh Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make both Codex Provider admin pages visually mirror WordPress core's Connectors route while preserving every settings, diagnostics, connection, nonce, and model-selection contract.

**Architecture:** Keep the pages server-rendered PHP and screen-scoped inline CSS delivered through `wp_add_inline_style()`. Add one shared admin style helper so both pages use the same Connectors-like white surface, centered 680px shell, card stack, badges, model pills, diagnostics rows, and responsive rules without depending on core-private React/WPDS modules.

**Tech Stack:** WordPress admin PHP, plugin PSR-4 autoloading, scoped CSS strings, existing script modules in `assets/diagnostics.js` and `assets/user-connection.js`, `scripts/verify.php`/`scripts/verify.sh`, Node test runner for asset tests.

---

## Source Documents

- Design spec: `docs/superpowers/specs/2026-06-18-codex-provider-admin-ui-design.md`
- Existing site page: `src/Admin/SiteSettings.php`
- Existing user page: `src/Admin/UserConnectionPage.php`
- Verification harness: `scripts/verify.php`
- Runtime invariants: `CLAUDE.md`

## File Structure

- Create `src/Admin/AdminPageStyle.php`
  - Owns shared CSS for the Connectors-like page shell, cards, badges, fields, diagnostics rows, setup details, model pills, device-code console, focus states, and mobile breakpoints.
  - Exposes `AdminPageStyle::css(): string`.
- Modify `src/Admin/SiteSettings.php`
  - Replace the old status-card/form-table layout with a compact header and three card sections: runtime, runtime settings, setup.
  - Keep `settings_fields()`, registered option names, setup snippets, diagnostics data attributes, and passive render behavior unchanged.
- Modify `src/Admin/UserConnectionPage.php`
  - Replace the old wide status table and loose model section with account, connection console/fallback, and model cards.
  - Keep all action URLs, nonces, `data-codex-*` attributes, server fallback blocks, and model preference form behavior unchanged.
- Modify `scripts/verify.php`
  - Add rendered-markup and source checks that fail on the current layout and pass only with the shared style helper, scoped white-surface CSS, card wrappers, and preserved behavioral selectors.

## Task 1: Shared Connectors-Like Admin CSS

**Files:**
- Create: `src/Admin/AdminPageStyle.php`
- Modify: `src/Admin/SiteSettings.php`
- Modify: `src/Admin/UserConnectionPage.php`
- Modify: `scripts/verify.php`

- [ ] **Step 1: Add failing verification for the shared visual helper**

  In `scripts/verify.php`, directly after the existing source reads near the user connection page assertions:

  ```php
  $codex_provider_user_page_source       = (string) file_get_contents( dirname( __DIR__ ) . '/src/Admin/UserConnectionPage.php' );
  $codex_provider_site_settings_source   = (string) file_get_contents( dirname( __DIR__ ) . '/src/Admin/SiteSettings.php' );
  $codex_provider_admin_style_source     = (string) file_get_contents( dirname( __DIR__ ) . '/src/Admin/AdminPageStyle.php' );
  $codex_provider_connection_flow_source = (string) file_get_contents( dirname( __DIR__ ) . '/assets/connection-flow.js' );
  $codex_provider_connector_source       = (string) file_get_contents( dirname( __DIR__ ) . '/assets/connectors.js' );
  $codex_provider_user_connection_source = (string) file_get_contents( dirname( __DIR__ ) . '/assets/user-connection.js' );
  ```

  Replace the old block that only reads `UserConnectionPage.php`, `connection-flow.js`, `connectors.js`, and `user-connection.js`.

  Add these assertions immediately after the existing `admin_enqueue_scripts` assertion:

  ```php
  $codex_provider_assert( false !== strpos( $codex_provider_site_settings_source, 'AdminPageStyle::css()' ), 'Site settings should use the shared Connectors-like admin page CSS helper.' );
  $codex_provider_assert( false !== strpos( $codex_provider_user_page_source, 'AdminPageStyle::css()' ), 'User connection page should use the shared Connectors-like admin page CSS helper.' );
  $codex_provider_assert( false !== strpos( $codex_provider_admin_style_source, 'final class AdminPageStyle' ), 'Shared admin page style helper should be available.' );
  $codex_provider_assert( false !== strpos( $codex_provider_admin_style_source, 'settings_page_scriptorium-ai-provider-for-codex' ), 'Shared admin CSS should scope white-surface rules to the settings page body class.' );
  $codex_provider_assert( false !== strpos( $codex_provider_admin_style_source, 'users_page_scriptorium-ai-provider-for-codex' ), 'Shared admin CSS should scope white-surface rules to the users page body class.' );
  $codex_provider_assert( false !== strpos( $codex_provider_admin_style_source, 'profile_page_scriptorium-ai-provider-for-codex' ), 'Shared admin CSS should include the profile page body class for lower-capability user routing.' );
  $codex_provider_assert( false !== strpos( $codex_provider_admin_style_source, 'max-width: 680px' ), 'Shared admin CSS should use the measured Connectors content width.' );
  $codex_provider_assert( false !== strpos( $codex_provider_admin_style_source, 'padding: 24px' ), 'Shared admin CSS should use the measured Connectors desktop shell padding.' );
  $codex_provider_assert( false !== strpos( $codex_provider_admin_style_source, 'padding: 20px' ), 'Shared admin CSS should use the measured Connectors desktop card padding.' );
  $codex_provider_assert( false !== strpos( $codex_provider_admin_style_source, '@media (max-width: 480px)' ), 'Shared admin CSS should include the measured Connectors mobile breakpoint.' );
  $codex_provider_assert( false !== strpos( $codex_provider_admin_style_source, 'padding: 8px' ), 'Shared admin CSS should use the measured mobile shell padding.' );
  $codex_provider_assert( false !== strpos( $codex_provider_admin_style_source, 'padding: 12px' ), 'Shared admin CSS should use the measured mobile card padding.' );
  $codex_provider_assert( false !== strpos( $codex_provider_admin_style_source, '#wpcontent' ), 'Shared admin CSS should own the Codex Provider page content surface.' );
  $codex_provider_assert( false !== strpos( $codex_provider_admin_style_source, '#wpfooter' ), 'Shared admin CSS should hide the footer on Codex Provider screens to match the Connectors app surface.' );
  $codex_provider_assert( false === strpos( $codex_provider_admin_style_source, '#wpbody-content > div:not' ), 'Shared admin CSS must not copy the core Connectors sibling-hiding rule.' );
  ```

- [ ] **Step 2: Run verification to confirm the new assertions fail**

  Run:

  ```bash
  WP_PATH=/home/dev/wp-hperkins-com ./scripts/verify.sh
  ```

  Expected: FAIL with a PHP warning or assertion showing `src/Admin/AdminPageStyle.php` is missing, or FAIL with `Shared admin page style helper should be available.`

- [ ] **Step 3: Create the shared CSS helper**

  Create `src/Admin/AdminPageStyle.php`:

  ```php
  <?php
  /**
   * Shared Connectors-like admin page styles.
   *
   * @package AIProviderForCodex
   */

  declare( strict_types=1 );

  namespace AIProviderForCodex\Admin;

  if ( ! defined( 'ABSPATH' ) ) {
  	exit;
  }

  /**
   * Supplies shared CSS for the plugin-owned admin pages.
   */
  final class AdminPageStyle {

  	/**
  	 * Returns scoped CSS for the Codex Provider admin screens.
  	 *
  	 * @return string
  	 */
  	public static function css(): string {
  		return '
  		body.settings_page_scriptorium-ai-provider-for-codex,
  		body.users_page_scriptorium-ai-provider-for-codex,
  		body.profile_page_scriptorium-ai-provider-for-codex,
  		body.settings_page_scriptorium-ai-provider-for-codex #wpcontent,
  		body.users_page_scriptorium-ai-provider-for-codex #wpcontent,
  		body.profile_page_scriptorium-ai-provider-for-codex #wpcontent,
  		body.settings_page_scriptorium-ai-provider-for-codex #wpbody,
  		body.users_page_scriptorium-ai-provider-for-codex #wpbody,
  		body.profile_page_scriptorium-ai-provider-for-codex #wpbody,
  		body.settings_page_scriptorium-ai-provider-for-codex #wpbody-content,
  		body.users_page_scriptorium-ai-provider-for-codex #wpbody-content,
  		body.profile_page_scriptorium-ai-provider-for-codex #wpbody-content { background: #fff; }
  		body.settings_page_scriptorium-ai-provider-for-codex #wpwrap,
  		body.users_page_scriptorium-ai-provider-for-codex #wpwrap,
  		body.profile_page_scriptorium-ai-provider-for-codex #wpwrap { background: var(--wpds-color-fg-content-neutral, #1e1e1e); }
  		body.settings_page_scriptorium-ai-provider-for-codex #wpcontent,
  		body.users_page_scriptorium-ai-provider-for-codex #wpcontent,
  		body.profile_page_scriptorium-ai-provider-for-codex #wpcontent { padding-inline-start: 0; }
  		body.settings_page_scriptorium-ai-provider-for-codex #wpbody-content,
  		body.users_page_scriptorium-ai-provider-for-codex #wpbody-content,
  		body.profile_page_scriptorium-ai-provider-for-codex #wpbody-content { padding-bottom: 0; }
  		body.settings_page_scriptorium-ai-provider-for-codex #wpfooter,
  		body.users_page_scriptorium-ai-provider-for-codex #wpfooter,
  		body.profile_page_scriptorium-ai-provider-for-codex #wpfooter { display: none; }

  		.codex-provider-admin-page { color: var(--wpds-color-fg-content-neutral, #1e1e1e); margin: 0; }
  		.codex-provider-admin-page a { color: #2271b1; }
  		.codex-provider-shell { box-sizing: border-box; width: 100%; max-width: 680px; margin: 0 auto; padding: 24px; }
  		.codex-provider-page-header { margin: 0 0 16px; }
  		.codex-provider-page-header h1 { margin: 0 0 4px; padding: 0; color: var(--wpds-color-fg-content-neutral, #1e1e1e); font-size: 20px; font-weight: 500; line-height: 1.3; }
  		.codex-provider-page-subtitle { margin: 0; color: var(--wpds-color-fg-content-neutral-weak, #757575); font-size: 13px; line-height: 1.45; }
  		.codex-provider-stack { display: flex; flex-direction: column; gap: 12px; }
  		.codex-provider-card { box-sizing: border-box; width: 100%; overflow: hidden; padding: 20px; border: 1px solid #ddd; border-radius: 8px; background: #fff; }
  		.codex-provider-card__header { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; margin: 0 0 14px; }
  		.codex-provider-card__identity { display: flex; align-items: flex-start; gap: 12px; min-width: 0; }
  		.codex-provider-card__icon { display: inline-flex; align-items: center; justify-content: center; flex: 0 0 auto; width: 32px; height: 32px; border: 1px solid #ddd; border-radius: 6px; background: #f6f7f7; color: #1e1e1e; font-size: 15px; font-weight: 600; line-height: 1; }
  		.codex-provider-card__title { margin: 0; color: var(--wpds-color-fg-content-neutral, #1e1e1e); font-size: 15px; font-weight: 600; line-height: 1.35; }
  		.codex-provider-card__description { margin: 2px 0 0; color: var(--wpds-color-fg-content-neutral-weak, #757575); font-size: 12px; line-height: 1.45; }
  		.codex-provider-card__action { flex: 0 0 auto; text-align: right; }
  		.codex-provider-card__body > :first-child { margin-top: 0; }
  		.codex-provider-card__body > :last-child { margin-bottom: 0; }
  		.codex-provider-meta-list { display: grid; grid-template-columns: minmax(120px, max-content) 1fr; gap: 8px 16px; margin: 0; font-size: 13px; line-height: 1.45; }
  		.codex-provider-meta-list dt { margin: 0; color: var(--wpds-color-fg-content-neutral-weak, #757575); font-weight: 500; }
  		.codex-provider-meta-list dd { margin: 0; min-width: 0; color: var(--wpds-color-fg-content-neutral, #1e1e1e); overflow-wrap: anywhere; }
  		.codex-provider-status-line { display: flex; align-items: center; gap: 8px; margin: 0; font-size: 13px; line-height: 1.45; }
  		.codex-provider-guidance { margin: 6px 0 0; color: var(--wpds-color-fg-content-neutral-weak, #757575); font-size: 12px; line-height: 1.45; }
  		.codex-provider-error { color: var(--wpds-color-fg-content-error-weak, #cc1818); }
  		.codex-provider-badge { display: inline-flex; align-items: center; min-height: 20px; padding: 0 8px; border-radius: 999px; background: #edfaef; color: #005c12; font-size: 12px; font-weight: 500; line-height: 20px; white-space: nowrap; }
  		.codex-provider-badge.is-warning { background: #fcf9e8; color: #674e00; }
  		.codex-provider-badge.is-error { background: #fcf0f1; color: #8a2424; }
  		.codex-indicator { display: inline-block; flex: 0 0 auto; width: 10px; height: 10px; border-radius: 50%; vertical-align: middle; }
  		.codex-indicator.good { background: #00a32a; }
  		.codex-indicator.warning { background: #dba617; }
  		.codex-indicator.error { background: #d63638; }
  		.codex-provider-fields { display: grid; gap: 16px; margin: 0; }
  		.codex-provider-field label { display: block; margin: 0 0 6px; color: var(--wpds-color-fg-content-neutral, #1e1e1e); font-size: 13px; font-weight: 600; line-height: 1.35; }
  		.codex-provider-field input.regular-text,
  		.codex-provider-field textarea.large-text,
  		.codex-provider-field select { width: 100%; max-width: 100%; }
  		.codex-provider-field .description,
  		.codex-provider-card .description { color: var(--wpds-color-fg-content-neutral-weak, #757575); font-size: 12px; line-height: 1.45; }
  		.codex-provider-field input:disabled { color: #50575e; background: #f6f7f7; opacity: 1; }
  		.codex-provider-actions { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin: 14px 0 0; }
  		.codex-provider-actions .button { margin: 0; }
  		.codex-diagnostics-rows { margin: 10px 0 0; padding-left: 0; list-style: none; }
  		.codex-diagnostics-rows > li { margin: 0 0 8px; }
  		.codex-remediation { margin: 4px 0 0 16px; padding: 6px 8px; border-left: 3px solid #2271b1; background: #f6f7f7; color: #50575e; font-size: 12px; line-height: 1.5; }
  		.codex-provider-models-list,
  		.codex-models-list { display: flex; flex-wrap: wrap; gap: 6px; margin: 8px 0 0; }
  		.codex-model-pill { display: inline-flex; align-items: center; min-height: 22px; border-radius: 999px; padding: 0 8px; background: #f0f0f1; color: #2c3338; font-size: 12px; line-height: 22px; }
  		.codex-model-pill.selected { background: #2271b1; color: #fff; }
  		.codex-provider-details { margin: 12px 0 0; border-top: 1px solid #ddd; padding-top: 12px; }
  		.codex-provider-details summary { cursor: pointer; color: #1e1e1e; font-size: 13px; font-weight: 600; }
  		.codex-provider-details textarea { margin-top: 10px; width: 100%; }
  		.codex-device-box ol { margin: 8px 0 14px 20px; }
  		.codex-device-box li { margin-bottom: 6px; }
  		.codex-device-code { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 24px; font-weight: 600; letter-spacing: 0.18em; overflow-wrap: anywhere; }
  		.codex-provider-admin-page :focus-visible,
  		.codex-provider-details summary:focus-visible { outline: 2px solid #2271b1; outline-offset: 2px; box-shadow: none; }

  		@media (max-width: 480px) {
  			.codex-provider-shell { padding: 8px; }
  			.codex-provider-card { padding: 12px; }
  			.codex-provider-card__header { flex-direction: column; gap: 12px; }
  			.codex-provider-card__action { width: 100%; text-align: left; }
  			.codex-provider-meta-list { grid-template-columns: 1fr; gap: 2px; }
  			.codex-provider-actions { flex-direction: column; align-items: stretch; }
  			.codex-provider-actions .button { justify-content: center; text-align: center; }
  		}
  		';
  	}
  }
  ```

- [ ] **Step 4: Update both page classes to use the helper**

  In `src/Admin/SiteSettings.php`, replace the full body of `private static function inline_css(): string` with:

  ```php
  private static function inline_css(): string {
  	return AdminPageStyle::css();
  }
  ```

  In `src/Admin/UserConnectionPage.php`, replace the full body of `private static function inline_css(): string` with:

  ```php
  private static function inline_css(): string {
  	return AdminPageStyle::css();
  }
  ```

- [ ] **Step 5: Run syntax and verification**

  Run:

  ```bash
  php -l src/Admin/AdminPageStyle.php
  php -l src/Admin/SiteSettings.php
  php -l src/Admin/UserConnectionPage.php
  WP_PATH=/home/dev/wp-hperkins-com ./scripts/verify.sh
  ```

  Expected:
  - All `php -l` commands print `No syntax errors detected`.
  - `verify.sh` still fails later on layout assertions that will be added in Tasks 2 and 3 only if those assertions are already present; otherwise it passes the new shared-style checks.

- [ ] **Step 6: Commit**

  ```bash
  git add src/Admin/AdminPageStyle.php src/Admin/SiteSettings.php src/Admin/UserConnectionPage.php scripts/verify.php
  git commit -m "feat: add shared codex provider admin styles"
  ```

## Task 2: Redesign Settings > Codex Provider

**Files:**
- Modify: `src/Admin/SiteSettings.php`
- Modify: `scripts/verify.php`

- [ ] **Step 1: Add failing verification for the settings page layout**

  In `scripts/verify.php`, immediately after the assertion `Site settings should render configured fallback models.`, add:

  ```php
  $codex_provider_assert(
  	false !== strpos( $codex_provider_site_settings_html, 'codex-provider-admin-page' ),
  	'Site settings should render the shared Connectors-like page wrapper.'
  );
  $codex_provider_assert(
  	false !== strpos( $codex_provider_site_settings_html, 'codex-provider-page-header' ),
  	'Site settings should render a compact page header.'
  );
  $codex_provider_assert(
  	false !== strpos( $codex_provider_site_settings_html, 'Configure the local Codex runtime used by this site' ),
  	'Site settings should render the compact Connectors-style subtitle.'
  );
  $codex_provider_assert(
  	false !== strpos( $codex_provider_site_settings_html, 'codex-provider-card codex-provider-card--runtime' ),
  	'Site settings should render a runtime card.'
  );
  $codex_provider_assert(
  	false !== strpos( $codex_provider_site_settings_html, 'codex-provider-card codex-provider-card--settings' ),
  	'Site settings should render a runtime settings card.'
  );
  $codex_provider_assert(
  	false !== strpos( $codex_provider_site_settings_html, 'codex-provider-card codex-provider-card--setup' ),
  	'Site settings should render a setup card.'
  );
  $codex_provider_assert(
  	false !== strpos( $codex_provider_site_settings_html, 'codex-provider-details' ),
  	'Site settings should keep generated setup snippets in compact details blocks.'
  );
  $codex_provider_assert(
  	false === strpos( $codex_provider_site_settings_html, 'codex-status-cards' ),
  	'Site settings should no longer render the old wide status-card layout.'
  );
  $codex_provider_assert(
  	false === strpos( $codex_provider_site_settings_html, 'class="form-table"' ),
  	'Site settings should no longer render a WordPress form table.'
  );
  $codex_provider_assert(
  	false !== strpos( $codex_provider_site_settings_html, 'data-codex-diagnostics-results' ),
  	'Site settings should preserve the diagnostics results container.'
  );
  $codex_provider_assert(
  	false !== strpos( $codex_provider_site_settings_html, Settings::OPTION_RUNTIME_BASE_URL ),
  	'Site settings should preserve the runtime URL option field.'
  );
  $codex_provider_assert(
  	false !== strpos( $codex_provider_site_settings_html, Settings::OPTION_RUNTIME_BEARER ),
  	'Site settings should preserve the runtime bearer token option field.'
  );
  $codex_provider_assert(
  	false !== strpos( $codex_provider_site_settings_html, Settings::OPTION_ALLOWED_MODELS ),
  	'Site settings should preserve the allowed models option field.'
  );
  ```

- [ ] **Step 2: Run verification to confirm the layout assertions fail**

  Run:

  ```bash
  WP_PATH=/home/dev/wp-hperkins-com ./scripts/verify.sh
  ```

  Expected: FAIL with `Site settings should render the shared Connectors-like page wrapper.`

- [ ] **Step 3: Replace the settings page body with the card layout**

  In `src/Admin/SiteSettings.php`, replace the HTML block emitted by `render_page()` from:

  ```php
  <div class="wrap">
  	<h1><?php esc_html_e( 'Scriptorium AI Provider for Codex', 'scriptorium-ai-provider-for-codex' ); ?></h1>
  ```

  through the closing `</div>` before `<?php` with this structure, reusing the variables already computed at the top of `render_page()`:

  ```php
  <div class="wrap codex-provider-admin-page">
  	<div class="codex-provider-shell">
  		<header class="codex-provider-page-header">
  			<h1><?php esc_html_e( 'Codex Provider', 'scriptorium-ai-provider-for-codex' ); ?></h1>
  			<p class="codex-provider-page-subtitle"><?php esc_html_e( 'Configure the local Codex runtime used by this site\'s AI connector.', 'scriptorium-ai-provider-for-codex' ); ?></p>
  		</header>

  		<?php self::render_notice( $notice ); ?>
  		<?php settings_errors(); ?>

  		<div class="codex-provider-stack">
  			<section class="codex-provider-card codex-provider-card--runtime">
  				<div class="codex-provider-card__header">
  					<div class="codex-provider-card__identity">
  						<span class="codex-provider-card__icon" aria-hidden="true">C</span>
  						<div>
  							<h2 class="codex-provider-card__title"><?php esc_html_e( 'Runtime', 'scriptorium-ai-provider-for-codex' ); ?></h2>
  							<p class="codex-provider-card__description"><?php esc_html_e( 'Local sidecar status and diagnostics.', 'scriptorium-ai-provider-for-codex' ); ?></p>
  						</div>
  					</div>
  					<div class="codex-provider-card__action">
  						<span class="codex-provider-badge<?php echo esc_attr( $is_configured ? '' : ' is-error' ); ?>">
  							<?php
  							if ( ! $is_configured ) {
  								esc_html_e( 'Not configured', 'scriptorium-ai-provider-for-codex' );
  							} else {
  								echo esc_html( StatusLabels::runtime_health_label( (string) $runtime_status['status'] ) );
  							}
  							?>
  						</span>
  					</div>
  				</div>
  				<div class="codex-provider-card__body">
  					<p class="codex-provider-status-line">
  						<span class="codex-indicator <?php echo esc_attr( $is_configured ? $health_ind : 'error' ); ?>"></span>
  						<span>
  							<?php
  							if ( ! $is_configured ) {
  								esc_html_e( 'Runtime configuration is incomplete.', 'scriptorium-ai-provider-for-codex' );
  							} else {
  								echo esc_html( StatusLabels::runtime_health_label( (string) $runtime_status['status'] ) );
  							}
  							?>
  						</span>
  					</p>
  					<dl class="codex-provider-meta-list">
  						<?php if ( ! empty( $runtime_status['checked_at'] ) ) : ?>
  							<dt><?php esc_html_e( 'Last check', 'scriptorium-ai-provider-for-codex' ); ?></dt>
  							<dd><?php echo esc_html( StatusLabels::relative_time( (string) $runtime_status['checked_at'] ) ); ?></dd>
  						<?php endif; ?>
  						<?php if ( ! empty( $runtime_status['error'] ) ) : ?>
  							<dt><?php esc_html_e( 'Error', 'scriptorium-ai-provider-for-codex' ); ?></dt>
  							<dd class="codex-provider-error"><?php echo esc_html( (string) $runtime_status['error'] ); ?></dd>
  						<?php endif; ?>
  						<dt><?php esc_html_e( 'Runtime URL', 'scriptorium-ai-provider-for-codex' ); ?></dt>
  						<dd><?php echo esc_html( (string) $runtime_config['base_url_source'] ); ?></dd>
  						<dt><?php esc_html_e( 'Bearer token', 'scriptorium-ai-provider-for-codex' ); ?></dt>
  						<dd><?php echo esc_html( (string) $runtime_config['bearer_token_source'] ); ?></dd>
  					</dl>
  					<div class="codex-provider-actions">
  						<button type="button" class="button button-secondary" data-codex-diagnostics-run>
  							<?php esc_html_e( 'Check runtime', 'scriptorium-ai-provider-for-codex' ); ?>
  						</button>
  						<a class="button button-secondary" href="<?php echo esc_url( admin_url( 'options-connectors.php' ) ); ?>"><?php esc_html_e( 'Open Connectors', 'scriptorium-ai-provider-for-codex' ); ?></a>
  					</div>
  					<?php if ( is_array( $last_diagnostic ) ) : ?>
  						<p class="codex-provider-guidance">
  							<?php
  							echo esc_html(
  								SafeFormat::sprintf(
  									/* translators: 1: pass/fail summary, 2: relative time. */
  									__( 'Last diagnostics check: %1$s (%2$s).', 'scriptorium-ai-provider-for-codex' ),
  									empty( $last_diagnostic['ok'] )
  										? sprintf(
  											/* translators: %d: number of failed checks. */
  											_n( '%d issue', '%d issues', count( (array) ( $last_diagnostic['failed'] ?? [] ) ), 'scriptorium-ai-provider-for-codex' ),
  											count( (array) ( $last_diagnostic['failed'] ?? [] ) )
  										)
  										: __( 'healthy', 'scriptorium-ai-provider-for-codex' ),
  									StatusLabels::relative_time( (string) ( $last_diagnostic['checked_at'] ?? '' ) )
  								)
  							);
  							?>
  						</p>
  					<?php endif; ?>
  					<div data-codex-diagnostics-results aria-live="polite"></div>
  				</div>
  			</section>

  			<form method="post" action="options.php">
  				<?php settings_fields( \AIProviderForCodex\SLUG ); ?>
  				<section class="codex-provider-card codex-provider-card--settings">
  					<div class="codex-provider-card__header">
  						<div class="codex-provider-card__identity">
  							<span class="codex-provider-card__icon" aria-hidden="true">R</span>
  							<div>
  								<h2 class="codex-provider-card__title"><?php esc_html_e( 'Runtime settings', 'scriptorium-ai-provider-for-codex' ); ?></h2>
  								<p class="codex-provider-card__description"><?php esc_html_e( 'Shared sidecar endpoint and fallback model catalog.', 'scriptorium-ai-provider-for-codex' ); ?></p>
  							</div>
  						</div>
  					</div>
  					<div class="codex-provider-card__body codex-provider-fields">
  						<div class="codex-provider-field">
  							<label for="<?php echo esc_attr( Settings::OPTION_RUNTIME_BASE_URL ); ?>"><?php esc_html_e( 'Runtime URL', 'scriptorium-ai-provider-for-codex' ); ?></label>
  							<input class="regular-text code" id="<?php echo esc_attr( Settings::OPTION_RUNTIME_BASE_URL ); ?>" name="<?php echo esc_attr( Settings::OPTION_RUNTIME_BASE_URL ); ?>" type="url" value="<?php echo esc_attr( Settings::get_base_url() ); ?>" <?php disabled( $base_url_locked ); ?> />
  							<p class="description"><?php esc_html_e( 'Base URL for the local Codex runtime, typically http://127.0.0.1:4317.', 'scriptorium-ai-provider-for-codex' ); ?></p>
  							<p class="description"><?php echo esc_html( (string) $runtime_config['base_url_source'] ); ?></p>
  						</div>
  						<div class="codex-provider-field">
  							<label for="<?php echo esc_attr( Settings::OPTION_RUNTIME_BEARER ); ?>"><?php esc_html_e( 'Runtime bearer token', 'scriptorium-ai-provider-for-codex' ); ?></label>
  							<input class="regular-text code" id="<?php echo esc_attr( Settings::OPTION_RUNTIME_BEARER ); ?>" name="<?php echo esc_attr( Settings::OPTION_RUNTIME_BEARER ); ?>" type="password" value="<?php echo esc_attr( $bearer_locked ? '' : Settings::get_bearer_token() ); ?>" <?php disabled( $bearer_locked ); ?> autocomplete="off" placeholder="<?php echo esc_attr( $bearer_locked ? __( 'Managed automatically', 'scriptorium-ai-provider-for-codex' ) : '' ); ?>" />
  							<p class="description"><?php esc_html_e( 'Shared raw token used between WordPress and the local runtime.', 'scriptorium-ai-provider-for-codex' ); ?></p>
  							<p class="description"><?php echo esc_html( (string) $runtime_config['bearer_token_source'] ); ?></p>
  						</div>
  						<div class="codex-provider-field">
  							<label for="<?php echo esc_attr( Settings::OPTION_ALLOWED_MODELS ); ?>"><?php esc_html_e( 'Fallback models', 'scriptorium-ai-provider-for-codex' ); ?></label>
  							<textarea class="large-text code" id="<?php echo esc_attr( Settings::OPTION_ALLOWED_MODELS ); ?>" name="<?php echo esc_attr( Settings::OPTION_ALLOWED_MODELS ); ?>" rows="4"><?php echo esc_textarea( Settings::allowed_models_as_text() ); ?></textarea>
  							<p class="description"><?php esc_html_e( 'One text model ID per line. Used until a user links an account.', 'scriptorium-ai-provider-for-codex' ); ?></p>
  							<?php if ( ! empty( $fallback_models ) ) : ?>
  								<div class="codex-provider-models-list" aria-label="<?php esc_attr_e( 'Configured fallback models', 'scriptorium-ai-provider-for-codex' ); ?>">
  									<?php foreach ( $fallback_models as $model_id ) : ?>
  										<span class="codex-model-pill"><?php echo esc_html( $model_id ); ?></span>
  									<?php endforeach; ?>
  								</div>
  							<?php endif; ?>
  						</div>
  						<div class="codex-provider-actions">
  							<?php submit_button( __( 'Save settings', 'scriptorium-ai-provider-for-codex' ), 'primary', 'submit', false ); ?>
  						</div>
  					</div>
  				</section>
  			</form>

  			<section class="codex-provider-card codex-provider-card--setup">
  				<div class="codex-provider-card__header">
  					<div class="codex-provider-card__identity">
  						<span class="codex-provider-card__icon" aria-hidden="true">S</span>
  						<div>
  							<h2 class="codex-provider-card__title"><?php esc_html_e( 'Setup', 'scriptorium-ai-provider-for-codex' ); ?></h2>
  							<p class="codex-provider-card__description"><?php esc_html_e( 'Install the local sidecar and link each user account.', 'scriptorium-ai-provider-for-codex' ); ?></p>
  						</div>
  					</div>
  				</div>
  				<div class="codex-provider-card__body">
  					<?php self::render_setup_guide(); ?>
  					<details class="codex-provider-details">
  						<summary><?php esc_html_e( 'systemd unit (/etc/systemd/system/codex-wp-sidecar.service)', 'scriptorium-ai-provider-for-codex' ); ?></summary>
  						<textarea class="large-text code" rows="12" readonly><?php echo esc_textarea( SetupSnippets::systemd_unit() ); ?></textarea>
  					</details>
  					<details class="codex-provider-details">
  						<summary><?php esc_html_e( 'Environment file (/etc/codex-wp-sidecar.env)', 'scriptorium-ai-provider-for-codex' ); ?></summary>
  						<textarea class="large-text code" rows="10" readonly><?php echo esc_textarea( SetupSnippets::env_file() ); ?></textarea>
  					</details>
  				</div>
  			</section>
  		</div>
  	</div>
  </div>
  ```

- [ ] **Step 4: Run syntax and focused verification**

  Run:

  ```bash
  php -l src/Admin/SiteSettings.php
  WP_PATH=/home/dev/wp-hperkins-com ./scripts/verify.sh
  ```

  Expected:
  - `php -l` prints `No syntax errors detected`.
  - `verify.sh` passes the settings page layout assertions and any existing passive-render assertions. If Task 3 assertions have already been added, the suite may fail on user-page layout assertions until Task 3 is complete.

- [ ] **Step 5: Commit**

  ```bash
  git add src/Admin/SiteSettings.php scripts/verify.php
  git commit -m "feat: refresh codex provider settings page"
  ```

## Task 3: Redesign Users > Codex Provider

**Files:**
- Modify: `src/Admin/UserConnectionPage.php`
- Modify: `scripts/verify.php`

- [ ] **Step 1: Add failing verification for the user page layout**

  In `scripts/verify.php`, after the existing assertion `User connection page should render the enhanced connection root.`, add:

  ```php
  $codex_provider_assert(
  	false !== strpos( $codex_provider_connection_page_html, 'codex-provider-admin-page' ),
  	'User connection page should render the shared Connectors-like page wrapper.'
  );
  $codex_provider_assert(
  	false !== strpos( $codex_provider_connection_page_html, 'codex-provider-page-header' ),
  	'User connection page should render a compact page header.'
  );
  $codex_provider_assert(
  	false !== strpos( $codex_provider_connection_page_html, 'Connect your Codex or ChatGPT account and choose the model' ),
  	'User connection page should render the compact Connectors-style subtitle.'
  );
  $codex_provider_assert(
  	false !== strpos( $codex_provider_connection_page_html, 'codex-provider-card codex-provider-card--account' ),
  	'User connection page should render an account card.'
  );
  $codex_provider_assert(
  	false !== strpos( $codex_provider_connection_page_html, 'codex-provider-card codex-provider-card--connection-console' ),
  	'User connection page should render the enhanced connection console as a card.'
  );
  $codex_provider_assert(
  	false !== strpos( $codex_provider_connection_page_html, 'codex-provider-card codex-provider-card--model' ),
  	'User connection page should render a model card.'
  );
  $codex_provider_assert(
  	false === strpos( $codex_provider_connection_page_html, 'class="widefat striped"' ),
  	'User connection page should no longer render the old wide status table.'
  );
  $codex_provider_assert(
  	false === strpos( $codex_provider_connection_page_html, 'codex-models-section' ),
  	'User connection page should no longer render the old loose model section.'
  );
  $codex_provider_assert(
  	false !== strpos( $codex_provider_connection_page_html, 'id="codex_provider_model"' ),
  	'User connection page should preserve the model selector.'
  );
  $codex_provider_assert(
  	false !== strpos( $codex_provider_connection_page_html, 'name="codex_provider_action" value="set-model"' ),
  	'User connection page should preserve the set-model form action.'
  );
  ```

- [ ] **Step 2: Run verification to confirm the user layout assertions fail**

  Run:

  ```bash
  WP_PATH=/home/dev/wp-hperkins-com ./scripts/verify.sh
  ```

  Expected: FAIL with `User connection page should render the shared Connectors-like page wrapper.`

- [ ] **Step 3: Replace the user page wrapper and enhanced console**

  In `src/Admin/UserConnectionPage.php`, replace the opening page markup:

  ```php
  <div class="wrap">
  	<h1><?php esc_html_e( 'Codex Provider', 'scriptorium-ai-provider-for-codex' ); ?></h1>

  	<div data-codex-connection-root>
  	<?php self::render_notice( $notice ); ?>

  	<div class="codex-device-box" data-codex-connection-console hidden>
  ```

  with:

  ```php
  <div class="wrap codex-provider-admin-page">
  	<div class="codex-provider-shell" data-codex-connection-root>
  		<header class="codex-provider-page-header">
  			<h1><?php esc_html_e( 'Codex Provider', 'scriptorium-ai-provider-for-codex' ); ?></h1>
  			<p class="codex-provider-page-subtitle"><?php esc_html_e( 'Connect your Codex or ChatGPT account and choose the model used for your requests.', 'scriptorium-ai-provider-for-codex' ); ?></p>
  		</header>

  		<?php self::render_notice( $notice ); ?>

  		<div class="codex-provider-stack">
  			<section class="codex-provider-card codex-provider-card--connection-console codex-device-box" data-codex-connection-console hidden>
  ```

  Replace the matching closing `</div>` for the hidden console block with `</section>`.

- [ ] **Step 4: Replace the status table with an account card**

  Replace the entire `<table class="widefat striped" style="max-width: 960px"> ... </table>` block with:

  ```php
  <section class="codex-provider-card codex-provider-card--account">
  	<div class="codex-provider-card__header">
  		<div class="codex-provider-card__identity">
  			<span class="codex-provider-card__icon" aria-hidden="true">C</span>
  			<div>
  				<h2 class="codex-provider-card__title"><?php esc_html_e( 'Account', 'scriptorium-ai-provider-for-codex' ); ?></h2>
  				<p class="codex-provider-card__description"><?php esc_html_e( 'Personal Codex connection for this WordPress user.', 'scriptorium-ai-provider-for-codex' ); ?></p>
  			</div>
  		</div>
  		<div class="codex-provider-card__action">
  			<span class="codex-provider-badge<?php echo esc_attr( 'good' === $ind ? '' : ( 'warning' === $ind ? ' is-warning' : ' is-error' ) ); ?>">
  				<?php echo esc_html( $reason_label ); ?>
  			</span>
  		</div>
  	</div>
  	<div class="codex-provider-card__body">
  		<p class="codex-provider-status-line">
  			<span class="codex-indicator <?php echo esc_attr( $ind ); ?>"></span>
  			<span data-codex-connection-status aria-live="polite"><?php echo esc_html( $reason_label ); ?></span>
  		</p>
  		<?php if ( '' !== $guidance ) : ?>
  			<p class="codex-provider-guidance"><?php echo esc_html( $guidance ); ?></p>
  		<?php endif; ?>
  		<dl class="codex-provider-meta-list">
  			<dt><?php esc_html_e( 'Runtime configured', 'scriptorium-ai-provider-for-codex' ); ?></dt>
  			<dd><?php echo ! empty( $status['runtimeConfigured'] ) ? esc_html__( 'Yes', 'scriptorium-ai-provider-for-codex' ) : esc_html__( 'No', 'scriptorium-ai-provider-for-codex' ); ?></dd>
  			<?php if ( ! empty( $status['connection'] ) ) : ?>
  				<dt><?php esc_html_e( 'Connection ID', 'scriptorium-ai-provider-for-codex' ); ?></dt>
  				<dd><code><?php echo esc_html( (string) $status['connection']['connection_id'] ); ?></code></dd>
  				<dt><?php esc_html_e( 'Account email', 'scriptorium-ai-provider-for-codex' ); ?></dt>
  				<dd><?php echo esc_html( (string) $status['connection']['account_email'] ); ?></dd>
  				<dt><?php esc_html_e( 'Plan type', 'scriptorium-ai-provider-for-codex' ); ?></dt>
  				<dd><?php echo esc_html( (string) $status['connection']['plan_type'] ); ?></dd>
  			<?php endif; ?>
  		</dl>
  		<div class="codex-provider-actions" data-codex-base-actions>
  			<?php if ( empty( $status['runtimeConfigured'] ) ) : ?>
  				<a class="button button-secondary" href="<?php echo esc_url( SiteSettings::page_url() ); ?>"><?php esc_html_e( 'Configure local runtime', 'scriptorium-ai-provider-for-codex' ); ?></a>
  			<?php elseif ( 'error' === $pending_status ) : ?>
  				<a class="button button-primary" data-codex-start-connect href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'action', 'start-connect', self::page_url() ), 'codex-provider-start-connect' ) ); ?>"><?php esc_html_e( 'Start connection again', 'scriptorium-ai-provider-for-codex' ); ?></a>
  			<?php elseif ( empty( $status['connection'] ) && ! $pending_active ) : ?>
  				<a class="button button-primary" data-codex-start-connect href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'action', 'start-connect', self::page_url() ), 'codex-provider-start-connect' ) ); ?>"><?php esc_html_e( 'Connect Codex account', 'scriptorium-ai-provider-for-codex' ); ?></a>
  			<?php else : ?>
  				<a class="button button-secondary" href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'action', 'refresh-status', self::page_url() ), 'codex-provider-refresh-status' ) ); ?>"><?php echo esc_html( 'completed' === $pending_status ? __( 'Retry account sync', 'scriptorium-ai-provider-for-codex' ) : __( 'Refresh status', 'scriptorium-ai-provider-for-codex' ) ); ?></a>
  				<a class="button button-link-delete" href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'action', 'disconnect', self::page_url() ), 'codex-provider-disconnect' ) ); ?>"><?php esc_html_e( 'Disconnect Codex account', 'scriptorium-ai-provider-for-codex' ); ?></a>
  			<?php endif; ?>
  		</div>
  	</div>
  </section>
  ```

  Delete the old trailing `<p style="margin-top: 1.5rem;" data-codex-base-actions> ... </p>` block near the end of `render_page()` because its actions now live inside the account card.

- [ ] **Step 5: Convert the server fallback block to a card**

  Replace:

  ```php
  <div class="codex-device-box" data-codex-server-fallback>
  ```

  with:

  ```php
  <section class="codex-provider-card codex-provider-card--connection-fallback codex-device-box" data-codex-server-fallback>
  ```

  Replace the matching closing `</div>` for that fallback block with `</section>`.

- [ ] **Step 6: Replace the model section with a card**

  Replace:

  ```php
  <div class="codex-models-section">
  ```

  with:

  ```php
  <section class="codex-provider-card codex-provider-card--model">
  	<div class="codex-provider-card__header">
  		<div class="codex-provider-card__identity">
  			<span class="codex-provider-card__icon" aria-hidden="true">M</span>
  			<div>
  				<h2 class="codex-provider-card__title"><?php esc_html_e( 'Model', 'scriptorium-ai-provider-for-codex' ); ?></h2>
  				<p class="codex-provider-card__description"><?php esc_html_e( 'Text model used for your Codex requests.', 'scriptorium-ai-provider-for-codex' ); ?></p>
  			</div>
  		</div>
  	</div>
  	<div class="codex-provider-card__body">
  ```

  Remove the old nested `<h3><?php esc_html_e( 'Model', 'scriptorium-ai-provider-for-codex' ); ?></h3>` line from inside the section.

  Replace the closing `</div>` for the old model section with:

  ```php
  	</div>
  </section>
  ```

  Replace the inline form style:

  ```php
  <form method="post" action="<?php echo esc_url( self::page_url() ); ?>" style="margin-top: 1rem;">
  ```

  with:

  ```php
  <form class="codex-provider-field" method="post" action="<?php echo esc_url( self::page_url() ); ?>">
  ```

  Replace the inline submit button style call:

  ```php
  <?php submit_button( __( 'Set model', 'scriptorium-ai-provider-for-codex' ), 'secondary', 'submit', false, [ 'style' => 'margin-left: 0.5rem;' ] ); ?>
  ```

  with:

  ```php
  <span class="codex-provider-actions">
  	<?php submit_button( __( 'Set model', 'scriptorium-ai-provider-for-codex' ), 'secondary', 'submit', false ); ?>
  </span>
  ```

- [ ] **Step 7: Close the new stack and shell wrappers**

  At the end of the page markup, replace the old closing sequence:

  ```php
  		</div>
  	</div>
  ```

  with:

  ```php
  		</div>
  	</div>
  </div>
  ```

  Confirm the nesting is:

  ```html
  <div class="wrap codex-provider-admin-page">
    <div class="codex-provider-shell" data-codex-connection-root>
      <header class="codex-provider-page-header">...</header>
      <div class="codex-provider-stack">
        <section data-codex-connection-console>...</section>
        <section class="codex-provider-card--account">...</section>
        <section data-codex-server-fallback>...</section>
        <section class="codex-provider-card--model">...</section>
      </div>
    </div>
  </div>
  ```

- [ ] **Step 8: Run syntax and focused verification**

  Run:

  ```bash
  php -l src/Admin/UserConnectionPage.php
  WP_PATH=/home/dev/wp-hperkins-com ./scripts/verify.sh
  ```

  Expected:
  - `php -l` prints `No syntax errors detected`.
  - `verify.sh` passes the user page layout assertions and the existing `data-codex-*`, nonce URL, server fallback, pending device-code, and model selector assertions.

- [ ] **Step 9: Run asset tests that depend on preserved selectors**

  Run:

  ```bash
  node --test assets/connection-flow.test.mjs assets/user-connection.test.mjs assets/diagnostics.test.mjs
  ```

  Expected: TAP output ends with `# fail 0`.

- [ ] **Step 10: Commit**

  ```bash
  git add src/Admin/UserConnectionPage.php scripts/verify.php
  git commit -m "feat: refresh codex provider user page"
  ```

## Task 4: Full Verification, Browser Screenshots, And Polish

**Files:**
- Modify only if browser verification exposes a defect: `src/Admin/AdminPageStyle.php`, `src/Admin/SiteSettings.php`, `src/Admin/UserConnectionPage.php`, `scripts/verify.php`

- [ ] **Step 1: Run full PHP verification**

  Run:

  ```bash
  WP_PATH=/home/dev/wp-hperkins-com ./scripts/verify.sh
  ```

  Expected: PASS with the script's normal success message and no assertions.

- [ ] **Step 2: Run all known focused JS asset tests**

  Run:

  ```bash
  node --test assets/connection-flow.test.mjs assets/user-connection.test.mjs assets/diagnostics.test.mjs
  ```

  Expected: TAP output ends with `# fail 0`.

- [ ] **Step 3: Capture desktop screenshots for visual comparison**

  Use Playwright against the local WordPress install at `https://wp.hperkins.com`:

  ```js
  const { chromium } = require('playwright');

  (async () => {
    const browser = await chromium.launch({ headless: true });
    const context = await browser.newContext({
      viewport: { width: 1440, height: 1000 },
      ignoreHTTPSErrors: true,
    });
    const page = await context.newPage();
    await page.goto('https://wp.hperkins.com/wp-admin/options-connectors.php', { waitUntil: 'networkidle' });
    await page.screenshot({ path: 'tmp-connectors-reference-desktop.png', fullPage: true });
    await page.goto('https://wp.hperkins.com/wp-admin/options-general.php?page=scriptorium-ai-provider-for-codex', { waitUntil: 'networkidle' });
    await page.screenshot({ path: 'tmp-codex-provider-settings-desktop.png', fullPage: true });
    await page.goto('https://wp.hperkins.com/wp-admin/users.php?page=scriptorium-ai-provider-for-codex', { waitUntil: 'networkidle' });
    await page.screenshot({ path: 'tmp-codex-provider-user-desktop.png', fullPage: true });
    await browser.close();
  })();
  ```

  Expected visual result:
  - Both Codex Provider pages use a white content surface.
  - Content is centered and about the same width as the Connectors page.
  - Headers are compact title/subtitle pairs.
  - First viewport is a card stack, not a table or wide settings form.
  - Plugin-rendered notices align with the centered column.

- [ ] **Step 4: Capture mobile screenshots for responsive checks**

  Re-run the same Playwright flow with:

  ```js
  viewport: { width: 390, height: 844 }
  ```

  Expected visual result:
  - Shell padding is tight like the Connectors reference.
  - Cards use compact padding.
  - Card headers/actions stack without overlap.
  - Buttons, device code, model pills, and snippet summaries fit within the column.

- [ ] **Step 5: Check RTL and an alternate admin color scheme**

  In the browser, switch the test user to an alternate admin color scheme such as Modern or Midnight, then open:

  ```text
  https://wp.hperkins.com/wp-admin/options-general.php?page=scriptorium-ai-provider-for-codex
  https://wp.hperkins.com/wp-admin/users.php?page=scriptorium-ai-provider-for-codex
  ```

  If the install has RTL enabled or an RTL locale available, repeat once with RTL active.

  Expected visual result:
  - The admin menu may retain the selected color scheme.
  - The Codex Provider content surface remains white.
  - Logical padding keeps the shell aligned in LTR and RTL.
  - Focus outlines are visible on buttons, links, fields, and `<summary>` rows.
  - Disabled runtime fields remain readable.

- [ ] **Step 6: Apply any polish fixes discovered by screenshots**

  If screenshots reveal a specific defect, make the smallest scoped edit and re-run:

  ```bash
  php -l src/Admin/AdminPageStyle.php
  php -l src/Admin/SiteSettings.php
  php -l src/Admin/UserConnectionPage.php
  WP_PATH=/home/dev/wp-hperkins-com ./scripts/verify.sh
  node --test assets/connection-flow.test.mjs assets/user-connection.test.mjs assets/diagnostics.test.mjs
  ```

  Expected:
  - PHP syntax checks pass.
  - `verify.sh` passes.
  - Node TAP output ends with `# fail 0`.

- [ ] **Step 7: Remove temporary screenshots**

  Run:

  ```bash
  rm -f tmp-connectors-reference-desktop.png tmp-codex-provider-settings-desktop.png tmp-codex-provider-user-desktop.png tmp-connectors-reference-mobile.png tmp-codex-provider-settings-mobile.png tmp-codex-provider-user-mobile.png
  ```

  Expected: no temporary screenshot files remain in `git status --short`.

- [ ] **Step 8: Final status and commit**

  Run:

  ```bash
  git status --short
  ```

  Expected:
  - Only the intended tracked files are modified.
  - Existing unrelated untracked files may remain:
    - `leadership-lesson-side-convo.md`
    - `plato-leadership-lesson.md`
    - `wp-ai-issue-502-managed-auth-comment.md`

  Commit any final tracked polish:

  ```bash
  git add src/Admin/AdminPageStyle.php src/Admin/SiteSettings.php src/Admin/UserConnectionPage.php scripts/verify.php
  git commit -m "polish: align codex provider admin pages with connectors"
  ```

## Self-Review

- Spec coverage: The plan covers both plugin-owned pages, the core Connectors measured values, the white-surface override set, no sibling-hiding rule, plugin notice alignment, global notice limitation by omission, page-reload interaction model by preserving forms/actions, accessibility focus and disabled-field contrast, and verification against preserved behavior.
- Placeholder scan: The plan contains no `TBD`, `TODO`, `implement later`, or unspecified "write tests" steps. Each code-changing step includes concrete code or exact replacement instructions.
- Type consistency: The only new PHP API is `AIProviderForCodex\Admin\AdminPageStyle::css(): string`, and both page classes call that exact method. Verification refers to existing `Settings` constants already imported in `scripts/verify.php`.
