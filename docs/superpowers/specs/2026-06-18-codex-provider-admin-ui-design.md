# Codex Provider Admin UI Refresh Design

**Date:** 2026-06-18

**Goal:** Make both Codex Provider admin pages mirror the look and feel of the WordPress AI plugin's `Settings > Connectors` page while preserving the existing runtime settings, diagnostics, per-user connection flow, model selection, REST contracts, nonces, and no-JavaScript fallbacks.

**Status:** Design spec. No implementation has been performed.

## Source UI

The live reference was captured from the local WordPress install at `https://wp.hperkins.com/wp-admin/options-connectors.php` on 2026-06-18 with the AI plugin active at version 1.0.2.

The Connectors page uses these visible patterns:

- a white app-like admin surface instead of the default gray `wp-admin` content background;
- a compact header band with the title `Connectors` and one short subtitle;
- a centered content column around 680px wide;
- stacked cards with 8px radius, light neutral borders, and roughly 24px padding;
- each connector card lays out logo, title, description, and action/status controls in one horizontal row;
- connected state uses a quiet green status badge, while setup/install actions use WordPress component-style blue outline or filled buttons;
- supporting copy is brief and sits below the card stack, not inside a large instructional block.

The current Codex Provider pages diverge in predictable ways:

- `Settings > Codex Provider` uses the default gray page, wide form-table layout, custom status cards, and long setup/snippet sections that dominate the first viewport.
- `Users > Codex Provider` uses a wide table, loose model controls, and a classic settings flow instead of card-based account and model surfaces.

## Scope

Apply the redesign to both plugin-owned admin pages:

- `Settings > Codex Provider`, rendered by `src/Admin/SiteSettings.php`;
- `Users > Codex Provider`, rendered by `src/Admin/UserConnectionPage.php`.

Do not change the `Settings > Connectors` React card integration in `assets/connectors.js` except if verification exposes a styling regression. The Connectors page is the visual source of truth, not a target for this work.

## Goals

- Bring both Codex Provider pages into the same visual family as the AI plugin's Connectors page.
- Keep the implementation server-rendered PHP plus scoped CSS, matching the existing architecture.
- Keep all behavior unchanged: setting names, REST endpoints, diagnostics, connection actions, model preference storage, notices, and no-JavaScript fallbacks.
- Reduce first-viewport clutter by moving from tables and long inline guides to compact cards.
- Keep admin copy short and action-oriented.
- Avoid introducing a build step, React app, or dependency on AI plugin private CSS.

## Non-Goals

- No React rewrite of the PHP admin pages.
- No new REST routes or database schema changes.
- No sidecar behavior changes.
- No changes to runtime configuration precedence.
- No removal of the full setup guide or generated snippets; they remain available, but in a less dominant card.
- No attempt to make these pages pixel-identical to the full Connectors route shell, because that shell is owned by WordPress core route/admin UI code.

## Recommended Approach

Create a small shared admin UI layer inside this plugin:

- a reusable CSS vocabulary shared by `SiteSettings` and `UserConnectionPage`;
- a helper method, trait, or small class for shared page sections only if it meaningfully reduces duplication;
- PHP markup reshaped into card rows and compact page headers;
- existing WordPress admin controls retained for forms and actions.

This is preferable to a React rewrite because the existing pages are PHP forms with server-side fallbacks, and the desired change is visual structure rather than client-side state architecture. It is also better than CSS-only skinning because the existing wide tables and long setup section are what make the pages feel unlike Connectors.

## Shared Visual System

Both pages use a top-level wrapper such as `.codex-provider-admin-page`.

Page shell:

- white page background for `#wpcontent`, `#wpbody`, and page body while on the two Codex Provider screens;
- compact header with title and subtitle;
- content column centered with `max-width: 680px`;
- vertical rhythm matching the Connectors stack, roughly 12px to 16px between cards;
- no nested cards.

Cards:

- `.codex-provider-card` uses white background, `1px solid #ddd`, `border-radius: 8px`, and compact padding;
- card header row supports an optional logo/status icon, title, description, and right-aligned action;
- card content uses smaller rows or form fields, not `form-table`;
- status badges use quiet background fills similar to Connectors' `Connected` badge;
- model pills keep their current meaning but adopt the same compact badge treatment.

Forms:

- replace `table.form-table` on the site page with stacked field groups inside the settings card;
- labels sit above controls or in compact field rows depending on space;
- descriptions remain under each field but are shortened where possible;
- Save buttons stay at the bottom of the relevant card.

Notices:

- existing WordPress notices still render, but inside the centered column below the header so they align with page content;
- diagnostics results keep `aria-live` and remain in the runtime card.

Responsive behavior:

- at small widths the column becomes full width with 16px padding;
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
- existing nonce and `codex_provider_action=set-model` hidden field.

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
- full `scripts/verify.sh` against the local WordPress install if practical.

Manual/browser verification should include:

- screenshot of `Settings > Connectors` as the reference;
- screenshot of refreshed `Settings > Codex Provider`;
- screenshot of refreshed `Users > Codex Provider`;
- compare first viewport: both Codex pages should read as the same family as Connectors with a centered card stack and compact header;
- confirm Connect, Check runtime, Save settings, Set model, Refresh status, and Disconnect controls remain visible in their relevant states.

## Risks

- The connection flow JavaScript relies on data attributes in the current markup. Preserve selectors before changing presentation.
- The site settings form relies on WordPress settings APIs. Do not separate inputs from `settings_fields()` or the form submit path.
- The setup snippets are long. Collapse them by default with clear `<summary>` labels so they stay easy to find without dominating the page.
- The Connectors page is rendered by core/admin UI assets, so matching should target visual language rather than copying private classes.

## Acceptance Criteria

- Both Codex Provider pages use a centered, Connectors-like card stack on a white admin surface.
- Both pages have compact title/subtitle headers.
- `Settings > Codex Provider` no longer starts with wide status cards and a form table.
- `Users > Codex Provider` no longer starts with a wide status table.
- Existing behavior, settings storage, nonces, REST endpoints, diagnostics, connection flow, and model selection remain intact.
- Verification confirms rendered markup and existing JS tests still pass.
