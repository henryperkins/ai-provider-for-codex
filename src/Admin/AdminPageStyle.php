<?php
/**
 * Shared Connectors-like admin page styles.
 *
 * @package HtperkinsAIProviderForCodex
 */

declare( strict_types=1 );

namespace Htperkins\AIProviderForCodex\Admin;

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
		body.settings_page_ai-provider-for-codex,
		body.users_page_ai-provider-for-codex,
		body.profile_page_ai-provider-for-codex,
		body.settings_page_ai-provider-for-codex #wpcontent,
		body.users_page_ai-provider-for-codex #wpcontent,
		body.profile_page_ai-provider-for-codex #wpcontent,
		body.settings_page_ai-provider-for-codex #wpbody,
		body.users_page_ai-provider-for-codex #wpbody,
		body.profile_page_ai-provider-for-codex #wpbody,
		body.settings_page_ai-provider-for-codex #wpbody-content,
		body.users_page_ai-provider-for-codex #wpbody-content,
		body.profile_page_ai-provider-for-codex #wpbody-content { background: #fff; }
		body.settings_page_ai-provider-for-codex #wpwrap,
		body.users_page_ai-provider-for-codex #wpwrap,
		body.profile_page_ai-provider-for-codex #wpwrap { background: #fff; }
		body.settings_page_ai-provider-for-codex #wpcontent,
		body.users_page_ai-provider-for-codex #wpcontent,
		body.profile_page_ai-provider-for-codex #wpcontent { padding-inline-start: 0; }
		body.settings_page_ai-provider-for-codex #wpbody-content,
		body.users_page_ai-provider-for-codex #wpbody-content,
		body.profile_page_ai-provider-for-codex #wpbody-content { padding-bottom: 0; }
		body.settings_page_ai-provider-for-codex #wpfooter,
		body.users_page_ai-provider-for-codex #wpfooter,
		body.profile_page_ai-provider-for-codex #wpfooter { display: none; }

		.codex-provider-admin-page { color: var(--wpds-color-fg-content-neutral, #1e1e1e); margin: 0; }
		.codex-provider-admin-page [hidden] { display: none !important; }
		.codex-provider-admin-page a { color: #2271b1; }
		.codex-provider-shell { box-sizing: border-box; width: 100%; max-width: 680px; margin: 0 auto; padding: 24px; }
		.codex-provider-page-header { margin: 0 0 16px; }
		.codex-provider-page-header h1 { margin: 0 0 4px; padding: 0; color: var(--wpds-color-fg-content-neutral, #1e1e1e); font-size: 20px; font-weight: 500; line-height: 1.3; }
		.codex-provider-page-subtitle { margin: 0; color: var(--wpds-color-fg-content-neutral-weak, #757575); font-size: 13px; line-height: 1.45; }
		.codex-provider-stack { display: flex; flex-direction: column; gap: 12px; }
		.codex-provider-card { box-sizing: border-box; width: 100%; overflow: hidden; padding: 20px; border: 1px solid #ddd; border-radius: 8px; background: #fff; }
		.codex-provider-card__header { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; margin: 0 0 14px; }
		.codex-provider-card__identity { display: flex; align-items: flex-start; gap: 12px; min-width: 0; }
		.codex-provider-card__logo { display: block; flex: 0 0 auto; width: 40px; height: 40px; border-radius: 8px; object-fit: contain; }
		.codex-provider-card__title { margin: 0; color: var(--wpds-color-fg-content-neutral, #1e1e1e); font-size: 15px; font-weight: 600; line-height: 1.35; }
		.codex-provider-card__description { margin: 2px 0 0; color: var(--wpds-color-fg-content-neutral-weak, #757575); font-size: 12px; line-height: 1.45; }
		.codex-provider-card__action { flex: 0 0 auto; text-align: right; }
		.codex-provider-card__body > :first-child { margin-top: 0; }
		.codex-provider-card__body > :last-child { margin-bottom: 0; }
		.codex-provider-card__body p { font-size: 13px; line-height: 1.45; }
		.codex-provider-card__body h3 { margin: 16px 0 8px; color: var(--wpds-color-fg-content-neutral, #1e1e1e); font-size: 13px; font-weight: 600; line-height: 1.35; }
		.codex-provider-card__body ol { margin: 8px 0 12px 20px; }
		.codex-provider-card__body li { margin-bottom: 6px; }
		.codex-provider-meta-list { display: grid; grid-template-columns: minmax(120px, max-content) 1fr; gap: 8px 16px; margin: 0; font-size: 13px; line-height: 1.45; }
		.codex-provider-meta-list dt { margin: 0; color: var(--wpds-color-fg-content-neutral-weak, #757575); font-weight: 500; }
		.codex-provider-meta-list dd { margin: 0; min-width: 0; color: var(--wpds-color-fg-content-neutral, #1e1e1e); overflow-wrap: anywhere; }
		.codex-provider-status-line { display: flex; align-items: center; gap: 8px; margin: 0; font-size: 13px; line-height: 1.45; }
		.codex-provider-guidance { margin: 6px 0 0; color: var(--wpds-color-fg-content-neutral-weak, #757575); font-size: 12px; line-height: 1.45; }
		.codex-provider-error { color: var(--wpds-color-fg-content-error-weak, #cc1818); }
		.codex-provider-badge { display: inline-flex; align-items: center; padding: 4px 12px; border-radius: var(--wpds-border-radius-sm, 2px); background: var(--wpds-color-bg-surface-success-weak, #eff8f0); color: #345b37; font-size: 13px; font-weight: 500; line-height: 16px; white-space: nowrap; }
		.codex-provider-badge.is-warning { background: var(--wpds-color-bg-surface-warning-weak, #fff7e0); color: var(--wpds-color-fg-content-warning, #2e1900); }
		.codex-provider-badge.is-error { background: var(--wpds-color-bg-surface-error-weak, #fff6f4); color: var(--wpds-color-fg-content-error, #470000); }
		.codex-indicator { display: inline-block; flex: 0 0 auto; width: 10px; height: 10px; border-radius: 50%; vertical-align: middle; }
		.codex-indicator.good { background: #00a32a; }
		.codex-indicator.warning { background: #dba617; }
		.codex-indicator.error { background: #d63638; }
		.codex-provider-admin-page .notice { margin: 0 0 12px; padding: 10px 12px; border: 1px solid var(--wpds-color-stroke-surface-neutral, #d8d8d8); border-radius: var(--wpds-border-radius-lg, 8px); box-shadow: none; }
		.codex-provider-admin-page .notice p { margin: 0; padding: 0; font-size: 13px; line-height: 1.45; }
		.codex-provider-admin-page .notice-success { background: var(--wpds-color-bg-surface-success-weak, #eaffed); border-color: var(--wpds-color-stroke-surface-success, #8ac894); color: var(--wpds-color-fg-content-success, #002900); }
		.codex-provider-admin-page .notice-info { background: var(--wpds-color-bg-surface-info-weak, #f2f9ff); border-color: var(--wpds-color-stroke-surface-info, #9fbcdc); color: var(--wpds-color-fg-content-info, #001b4f); }
		.codex-provider-admin-page .notice-warning { background: var(--wpds-color-bg-surface-warning-weak, #fff7e0); border-color: var(--wpds-color-stroke-surface-warning, #d0b381); color: var(--wpds-color-fg-content-warning, #2e1900); }
		.codex-provider-admin-page .notice-error { background: var(--wpds-color-bg-surface-error-weak, #fff6f4); border-color: var(--wpds-color-stroke-surface-error, #daa39b); color: var(--wpds-color-fg-content-error, #470000); }
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
		.codex-model-pill { display: inline-flex; align-items: center; border-radius: var(--wpds-border-radius-lg, 8px); padding: 4px 8px; background: var(--wpds-color-bg-surface-neutral-weak, #f0f0f0); color: var(--wpds-color-fg-content-neutral, #1e1e1e); font-size: 12px; line-height: 16px; }
		.codex-model-pill.selected { background: #2271b1; color: #fff; }
		.codex-provider-details { margin: 12px 0 0; border-top: 1px solid #ddd; padding-top: 12px; }
		.codex-provider-details summary { cursor: pointer; color: #1e1e1e; font-size: 13px; font-weight: 600; }
		.codex-provider-details__content { margin-top: 12px; }
		.codex-provider-details textarea { margin-top: 10px; width: 100%; }
		.codex-device-box ol { margin: 8px 0 14px 20px; }
		.codex-device-box li { margin-bottom: 6px; }
		.codex-device-code { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 24px; font-weight: 600; letter-spacing: 0.18em; overflow-wrap: anywhere; }
		.codex-device-box [data-codex-code-actions]:not([hidden]),
		.codex-device-box [data-codex-connected-actions]:not([hidden]),
		.codex-device-box [data-codex-terminal-actions]:not([hidden]) { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin: 14px 0 0; }
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
