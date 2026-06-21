# Codex Provider Admin UI Refresh Design

**Date:** 2026-06-18

**Goal:** Make both Codex Provider admin pages mirror the look and feel of WordPress core's `Settings > Connectors` route while preserving the existing runtime settings, diagnostics, per-user connection flow, model selection, REST contracts, nonces, and no-JavaScript fallbacks.

**Status:** Design spec. No implementation has been performed.

## Source UI

The live reference was captured from the local WordPress install at `https://wp.hperkins.com/wp-admin/options-connectors.php` on 2026-06-18. The install was running WordPress `7.1-alpha-62511`; the AI plugin was active at version 1.0.2 and contributed connector integrations, but the `Settings > Connectors` page itself is a WordPress core screen.

That source matters. `wp-admin/options-connectors.php` is a core file (`@since 7.0.0`) that delegates to `wp_options_connectors_wp_admin_render_page()` from `wp-includes/build/pages/options-connectors/page-wp-admin.php`. The page body is a React route mounted at `#options-connectors-wp-admin-app`, built from core/private admin UI primitives, Emotion-injected CSS, and WPDS design tokens. The route opts into private APIs through `__dangerousOptInToUnstableAPIsOnlyForCoreModules()` with the warning that those APIs are not for plugins and will break in the next WordPress version.

Therefore this plugin should not import, depend on, or copy private core classes as a contract. The implementation should hand-roll the visual language with screen-scoped PHP/CSS, optionally using public CSS custom properties such as `--wpds-*` only with hardcoded fallbacks.

The Connectors page uses these visible patterns:

- a white app-like admin surface instead of the default gray `wp-admin` content background;
- a compact header band with the title `Connectors` and one short subtitle;
- a centered content column around 680px wide;
- stacked cards with 8px radius, light neutral borders, and 20px desktop padding;
- each connector card lays out logo, title, description, and action/status controls in one horizontal row;
- connected state uses a quiet green `@wordpress/components` Badge in core, which this plugin must hand-approximate; setup/install actions use WordPress component-style blue outline or filled buttons;
- supporting copy is brief and sits below the card stack, not inside a large instructional block.

Measured reference values from `wp-includes/build/routes/connectors-home/content.js`:

- `.connectors-page`: `box-sizing: border-box; width: 100%; max-width: 680px; margin: 0 auto; padding: 24px;`.
- `@media (max-width: 480px)`: `.connectors-page { padding: 8px; }`.
- connector card (`.connectors-page .components-item`): `background: #fff; border: 1px solid #ddd; border-radius: 8px; overflow: hidden; padding: 20px;`.
- `@media (max-width: 480px)`: connector cards use `padding: 12px`.
- connector error text uses `#cc1818`.
- connector item titles render at roughly 15px and weight 600; descriptions render at roughly 12px muted text.

The current Codex Provider pages diverge in predictable ways:

- `Settings > Codex Provider` uses the default gray page, wide form-table layout, custom status cards, and long setup/snippet sections that dominate the first viewport.
- `Users > Codex Provider` uses a wide table, loose model controls, and a classic settings flow instead of card-based account and model surfaces.

## Drift And Maintenance

"Mirror" means "track the visual language visible in the current core Connectors route," not "freeze a private core implementation." The measured values above are targets for WordPress `7.1-alpha-62511`; they may drift as the WordPress 7.x admin UI and WPDS tokens evolve.

Maintenance rule: re-check the live `Settings > Connectors` route before major WordPress/core upgrades, before changing the Codex Provider admin CSS, and before release work that claims Connectors visual alignment. If the core route changes materially, update this spec or the implementation comments with the new measured targets before polishing the PHP pages.

Using WPDS custom properties with fallbacks is acceptable for colors where the token is present in the installed WordPress build, for example `var(--wpds-color-fg-content-neutral-weak, #6d6d6d)`. Do not rely on a token without a fallback, and do not import private core modules to gain token access.

## Scope

Apply the redesign to both plugin-owned admin pages:

- `Settings > Codex Provider`, rendered by `src/Admin/SiteSettings.php`;
- `Users > Codex Provider`, rendered by `src/Admin/UserConnectionPage.php`.

Do not change this plugin's `Settings > Connectors` React card integration in `assets/connectors.js` except if verification exposes a styling regression. The core Connectors page is the visual source of truth, not a target for this work.

## Goals

- Bring both Codex Provider pages into the same visual family as WordPress core's Connectors page.
- Keep the implementation server-rendered PHP plus scoped CSS, matching the existing architecture.
- Keep all behavior unchanged: setting names, REST endpoints, diagnostics, connection actions, model preference storage, notices, and no-JavaScript fallbacks.
- Reduce first-viewport clutter by moving from tables and long inline guides to compact cards.
- Keep admin copy short and action-oriented.
- Avoid introducing a build step, React app, or dependency on core private APIs or screen-gated route CSS.

## Non-Goals

- No React rewrite of the PHP admin pages.
- No new REST routes or database schema changes.
- No sidecar behavior changes.
- No changes to runtime configuration precedence.
- No removal of the full setup guide or generated snippets; they remain available, but in a less dominant card.
- No attempt to make these pages pixel-identical to the full Connectors route shell, because that shell is owned by WordPress core route/admin UI code.
- No attempt to make the PHP pages feel like a single-page React route. Save, refresh, disconnect, and model-selection actions may continue to use full-page requests by design; the device-code console remains the primary JavaScript-enhanced interaction.

## Recommended Approach

Create a small shared admin UI layer inside this plugin:

- a reusable CSS vocabulary shared by `SiteSettings` and `UserConnectionPage`;
- a helper method, trait, or small class for shared page sections only if it meaningfully reduces duplication;
- PHP markup reshaped into card rows and compact page headers;
- existing WordPress admin controls retained for forms and actions.

This is preferable to a React rewrite because the existing pages are PHP forms with server-side fallbacks, and the desired change is visual structure rather than client-side state architecture. It is also better than CSS-only skinning because the existing wide tables and long setup section are what make the pages feel unlike Connectors.

It is also the only viable way to mirror the reference without taking an unstable dependency. The core Connectors route is built from core-private React primitives, screen-gated route CSS, and WPDS tokens; those APIs are explicitly unavailable as plugin contracts. The plugin should copy the visible design language, not the implementation substrate.

## Shared Visual System

Both pages use a top-level wrapper such as `.codex-provider-admin-page`.

Page shell:

- screen-scoped white page background for the Codex Provider admin screens;
- compact header with title and subtitle;
- content column centered with `box-sizing: border-box; width: 100%; max-width: 680px; margin: 0 auto; padding: 24px;`;
- vertical rhythm matching the Connectors stack, roughly 12px to 16px between cards;
- no nested cards.

The white-surface override must be scoped to the Codex Provider screen body classes, expected to include `settings_page_ai-provider-for-codex`, `users_page_ai-provider-for-codex`, and where WordPress emits it for lower-capability users, `profile_page_ai-provider-for-codex`.

Use this override set as the implementation target:

- `body` / `#wpcontent` / `#wpbody` / `#wpbody-content` on those screen classes use a white content surface;
- `#wpwrap` may keep `var(--wpds-color-fg-content-neutral, #1e1e1e)` behind the admin menu if needed, matching the Connectors route, but must not make the content area gray;
- `#wpcontent { padding-inline-start: 0; }` on those screen classes so the custom page shell owns horizontal spacing;
- `#wpbody-content { padding-bottom: 0; }` on those screen classes;
- `#wpfooter { display: none; }` on those screen classes, matching the Connectors app's footer treatment;
- do not copy core's `#wpbody-content > div:not(.boot-layout-container):not(#screen-meta) { display: none; }` rule. That would hide third-party admin notices and unrelated WordPress chrome on these PHP pages.

Cards:

- `.codex-provider-card` uses white background, `1px solid #ddd`, `border-radius: 8px`, `overflow: hidden`, and `padding: 20px`;
- card header row supports an optional logo/status icon, title, description, and right-aligned action;
- card content uses smaller rows or form fields, not `form-table`;
- status badges use quiet background fills similar to Connectors' `Connected` badge; there is no stable badge CSS to borrow;
- model pills keep their current meaning but adopt the same compact badge treatment.

Typography:

- page title and subtitle should follow the core route header rhythm: compact title, one muted subtitle, no oversized hero treatment;
- card titles should target 15px / 600;
- descriptions and secondary metadata should target 12px to 13px muted text;
- runtime or diagnostics errors should use `#cc1818` or `var(--wpds-color-fg-content-error-weak, #cc1818)`.

Forms:

- replace `table.form-table` on the site page with stacked field groups inside the settings card;
- labels sit above controls or in compact field rows depending on space;
- descriptions remain under each field but are shortened where possible;
- Save buttons stay at the bottom of the relevant card.

Notices:

- notices rendered by this plugin should sit inside the centered column below the header so they align with page content;
- notices injected by core or other plugins through global `admin_notices` may remain full-width above or around the page shell. Do not relocate them with JavaScript and do not hide all siblings the way the core Connectors route does; treat this as an accepted limitation of a PHP admin page;
- diagnostics results keep `aria-live` and remain in the runtime card.

Responsive behavior:

- at `max-width: 480px`, the content column uses 8px padding and cards use 12px padding, matching the measured Connectors route;
- card rows stack vertically;
- action buttons remain visible and do not overlap descriptions;
- long code/snippet textareas use full column width and fixed heights.

## `Settings > Codex Provider`

The site settings page should become a focused admin configuration page with four sections.

### Header

Title: `Codex Provider`

Subtitle: `Configure the local Codex runtime used by this site's AI connector.`

The longer product name can remain in plugin metadata and readme, but the page title should match the shorter Connectors-style heading.

### Runtime Card

Purpose: show operational status and the primary health action.

Content:

- Codex logo/status icon;
- runtime health label from `StatusLabels::runtime_health_label()`;
- last check time and error text when present;
- runtime URL source and bearer token source from `Settings::configuration_metadata()`;
- secondary link to `Settings > Connectors`;
- `Check runtime` button wired to the existing diagnostics script.

The diagnostics results container stays in this card so the explicit check is visually tied to runtime status.

### Runtime Settings Card

Purpose: edit site-level runtime configuration and fallback model defaults.

Content:

- Runtime URL field, disabled when managed externally;
- Runtime bearer token field, disabled when managed externally;
- fallback models textarea;
- current fallback model chips;
- `Save settings` submit button.

Implementation keeps the same registered settings and sanitizers. The form can wrap this card or the card can live inside the existing form, as long as WordPress settings fields and nonces remain intact.

### Setup Card

Purpose: keep setup guidance available without making the page feel like documentation first.

Content:

- short paragraph explaining that the sidecar runs on the same host;
- compact ordered list of the essential setup steps;
- paths to the bundled service template and env example;
- manual fallback command;
- generated systemd unit textarea inside a `<details>` block;
- generated env file textarea inside a `<details>` block.

The setup card should keep the essential setup steps visible and collapse the generated snippets by default with clear summaries. This keeps the first viewport close to the Connectors card stack while preserving the full copy-paste install details without navigation.

### Links

Use direct links where the user naturally needs to move:

- `Settings > Connectors` for overall connector status;
- `Users > Codex Provider` for per-user account linking.

## `Users > Codex Provider`

The user page should become a personal account and model management page with three sections.

### Header

Title: `Codex Provider`

Subtitle: `Connect your Codex or ChatGPT account and choose the model used for your requests.`

### Account Card

Purpose: replace the status table and base actions.

Content:

- status indicator and readiness label;
- guidance from `StatusLabels::readiness_guidance()`;
- runtime configured state;
- connection metadata when present: account email, plan type, connection ID;
- primary action button based on existing state:
  - configure runtime for unconfigured runtime;
  - connect account for unlinked users;
  - retry account sync for completed pending state;
  - refresh status and disconnect for linked users;
  - start connection again for terminal pending errors.

All existing action URLs, nonces, and server-side fallbacks remain unchanged.

### Connection Console Card

Purpose: keep the existing JavaScript-enhanced device-code flow but make it look like the same card system.

Content:

- existing `[data-codex-connection-console]` block;
- pending code display;
- copy/open/check controls;
- connected details;
- retry/start-over terminal controls;
- server-rendered fallback block for pending/completed/error states.

The JavaScript selectors in `assets/user-connection.js` must keep working. Any markup reshaping must preserve the data attributes it reads and updates.

### Model Card

Purpose: replace the loose model section with a card.

Content:

- selected model summary;
- available model pills;
- source hint for fallback catalog;
- model `<select>`;
- `Set model` button;
- existing nonce and `htperkins_aipfc_action=set-model` hidden field.

The text-model preference remains text-only. This work must not add image-generation model behavior or change `ModelCatalogState`.

## Implementation Boundaries

Keep edits focused to:

- `src/Admin/SiteSettings.php`;
- `src/Admin/UserConnectionPage.php`;
- `scripts/verify.php` only for rendered-markup verification;
- optionally one new shared admin UI helper under `src/Admin/` if duplication becomes meaningful.

Avoid touching:

- runtime client and sidecar behavior;
- REST controllers;
- provider registration;
- `assets/connectors.js`;
- model catalog behavior.

## Testing And Verification

Automated checks should cover:

- PHP syntax for touched PHP files;
- rendered `SiteSettings::render_page()` still includes diagnostics button, diagnostics results container, setup snippets, registered setting names, and no passive runtime HTTP during render;
- rendered `UserConnectionPage::render_page()` still includes connection flow data attributes, nonce-backed action URLs, model selector, and server fallback markers;
- `assets/user-connection.js` tests still pass if markup selectors are affected;
- rendered page CSS is scoped to only the Codex Provider screen body classes and does not include the core Connectors sibling-hiding rule;
- full `scripts/verify.sh` against the local WordPress install if practical.

Manual/browser verification should include:

- screenshot of `Settings > Connectors` as the reference;
- screenshot of refreshed `Settings > Codex Provider`;
- screenshot of refreshed `Users > Codex Provider`;
- compare first viewport: both Codex pages should read as the same family as Connectors with a centered card stack and compact header;
- confirm Connect, Check runtime, Save settings, Set model, Refresh status, and Disconnect controls remain visible in their relevant states.
- check at least default and one alternate admin color scheme, plus RTL if available, because broad admin-surface overrides and logical spacing are the most likely visual regressions;
- verify keyboard focus is visible on card actions, custom links, `<summary>` elements, and form controls;
- verify disabled externally-managed Runtime URL and bearer-token fields remain readable against the white surface.

## Risks

- The connection flow JavaScript relies on data attributes in the current markup. Preserve selectors before changing presentation.
- The site settings form relies on WordPress settings APIs. Do not separate inputs from `settings_fields()` or the form submit path.
- The setup snippets are long. Collapse them by default with clear `<summary>` labels so they stay easy to find without dominating the page.
- The Connectors page is rendered by core/admin UI assets and private APIs, so matching should target visual language rather than copying private classes.
- The hand-rolled design can drift from core. Pin implementation review to the live Connectors route and revisit after major WordPress updates.
- White-surface overrides are broad enough to affect admin chrome. Scope selectors to the exact screen body classes and verify admin color schemes and RTL.
- Global third-party notices may remain full-width because this implementation must not hide or relocate arbitrary `admin_notices`.
- Most actions intentionally remain full-page form/action requests, so the interaction feel will not be identical to the React Connectors route.

## Acceptance Criteria

- Both Codex Provider pages use a centered, Connectors-like card stack on a white admin surface.
- Both pages have compact title/subtitle headers.
- `Settings > Codex Provider` no longer starts with wide status cards and a form table.
- `Users > Codex Provider` no longer starts with a wide status table.
- Existing behavior, settings storage, nonces, REST endpoints, diagnostics, connection flow, and model selection remain intact.
- The visual implementation uses the measured Connectors values from this spec unless current live-core verification justifies an update.
- CSS is screen-scoped and does not hide global admin siblings or global notices.
- Model pills, status badges, disabled fields, and diagnostics messages meet normal WordPress admin contrast expectations on a white surface.
- Keyboard focus remains visible on all actionable controls, including collapsed setup snippet summaries.
- Verification confirms rendered markup and existing JS tests still pass.
