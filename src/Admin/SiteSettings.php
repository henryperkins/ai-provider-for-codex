<?php
/**
 * Site-scoped local runtime settings.
 *
 * @package AIProviderForCodex
 */

declare( strict_types=1 );

namespace AIProviderForCodex\Admin;

use AIProviderForCodex\Runtime\HealthMonitor;
use AIProviderForCodex\Runtime\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the site settings page.
 */
final class SiteSettings {

	/**
	 * Registers the settings page.
	 *
	 * @return void
	 */
	public static function register_page(): void {
		add_options_page(
			__( 'Codex Provider', 'ai-provider-for-codex' ),
			__( 'Codex Provider', 'ai-provider-for-codex' ),
			'manage_options',
			'ai-provider-for-codex',
			[ self::class, 'render_page' ]
		);
	}

	/**
	 * Registers settings.
	 *
	 * @return void
	 */
	public static function register_settings(): void {
		register_setting(
			'ai-provider-for-codex',
			Settings::OPTION_RUNTIME_BASE_URL,
			[
				'type'              => 'string',
				'sanitize_callback' => [ Settings::class, 'sanitize_base_url' ],
				'default'           => Settings::DEFAULT_RUNTIME_BASE_URL,
			]
		);

		register_setting(
			'ai-provider-for-codex',
			Settings::OPTION_RUNTIME_BEARER,
			[
				'type'              => 'string',
				'sanitize_callback' => [ Settings::class, 'sanitize_bearer_token' ],
				'default'           => '',
			]
		);

		register_setting(
			'ai-provider-for-codex',
			Settings::OPTION_ALLOWED_MODELS,
			[
				'type'              => 'string',
				'sanitize_callback' => [ Settings::class, 'sanitize_allowed_models' ],
				'default'           => Settings::allowed_models_as_text(),
			]
		);
	}

	/**
	 * Renders the page.
	 *
	 * @return void
	 */
	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$notice          = self::read_notice();
		$fallback_models = Settings::get_allowed_models();
		$is_configured   = Settings::has_required_configuration();
		$runtime_status  = $is_configured ? HealthMonitor::probe() : HealthMonitor::get_status();
		$runtime_config  = Settings::configuration_metadata();
		$health_ind      = StatusLabels::status_indicator( (string) $runtime_status['status'] );
		$base_url_locked = ! empty( $runtime_config['base_url_managed'] );
		$bearer_locked   = ! empty( $runtime_config['bearer_token_managed'] );
		?>
		<style>
			.codex-status-cards { display: flex; flex-wrap: wrap; gap: 1rem; margin-bottom: 2rem; max-width: 960px; }
			.codex-status-card { background: #fff; border: 1px solid #ddd; border-radius: 4px; padding: 1rem 1.25rem; flex: 1 1 200px; min-width: 200px; }
			.codex-status-card h3 { margin: 0 0 0.5rem; font-size: 13px; text-transform: uppercase; letter-spacing: 0.5px; color: #666; }
			.codex-status-card .value { font-size: 16px; font-weight: 600; }
			.codex-status-card .meta { font-size: 12px; color: #888; margin-top: 0.25rem; }
			.codex-indicator { display: inline-block; width: 10px; height: 10px; border-radius: 50%; margin-right: 6px; vertical-align: middle; }
			.codex-indicator.good { background: #00a32a; }
			.codex-indicator.warning { background: #dba617; }
			.codex-indicator.error { background: #d63638; }
			.codex-models-list { display: flex; flex-wrap: wrap; gap: 0.375rem; margin-top: 0.25rem; }
			.codex-model-pill { display: inline-block; background: #f0f0f1; border-radius: 3px; padding: 2px 8px; font-size: 12px; }
			.codex-how-it-works { background: #f0f6fc; border: 1px solid #c3c4c7; border-left: 4px solid #2271b1; padding: 1rem 1.25rem; max-width: 960px; margin-bottom: 1.5rem; border-radius: 2px; }
			.codex-how-it-works p { margin: 0.25rem 0; }
		</style>
		<div class="wrap">
			<h1><?php esc_html_e( 'AI Provider for Codex', 'ai-provider-for-codex' ); ?></h1>

			<div class="codex-how-it-works">
				<p><?php esc_html_e( 'Codex uses a local runtime service that runs on the same host as WordPress. Each user links their own Codex account so access and billing stay user-specific.', 'ai-provider-for-codex' ); ?></p>
				<p>
					<?php
						printf(
							/* translators: %s: absolute shared env file path. */
							esc_html__( 'For automated installs, the plugin can auto-detect the runtime URL and bearer token from %s.', 'ai-provider-for-codex' ),
							esc_html( (string) $runtime_config['shared_env_file'] )
						);
					?>
				</p>
				<p>
					<?php
						printf(
							wp_kses_post(
								/* translators: 1: connectors settings URL, 2: user connection page URL. */
								__(
									'<a href="%1$s">Settings &gt; Connectors</a> is the main entry point. Per-user account linking is on the <a href="%2$s">user connection page</a>.',
									'ai-provider-for-codex'
							)
						),
						esc_url( admin_url( 'options-connectors.php' ) ),
						esc_url( UserConnectionPage::page_url() )
					);
					?>
				</p>
			</div>

			<?php self::render_notice( $notice ); ?>
			<?php settings_errors(); ?>

			<div class="codex-status-cards">
				<div class="codex-status-card">
					<h3><?php esc_html_e( 'Runtime', 'ai-provider-for-codex' ); ?></h3>
					<div class="value">
						<span class="codex-indicator <?php echo esc_attr( $is_configured ? $health_ind : 'error' ); ?>"></span>
						<?php
						if ( ! $is_configured ) {
							esc_html_e( 'Not configured', 'ai-provider-for-codex' );
						} else {
							echo esc_html( StatusLabels::runtime_health_label( (string) $runtime_status['status'] ) );
						}
						?>
					</div>
					<?php if ( ! empty( $runtime_status['checked_at'] ) ) : ?>
						<div class="meta"><?php echo esc_html( StatusLabels::relative_time( (string) $runtime_status['checked_at'] ) ); ?></div>
					<?php endif; ?>
					<?php if ( ! empty( $runtime_status['error'] ) ) : ?>
						<div class="meta" style="color: #d63638;"><?php echo esc_html( (string) $runtime_status['error'] ); ?></div>
					<?php endif; ?>
				</div>

				<div class="codex-status-card">
					<h3><?php esc_html_e( 'Fallback models', 'ai-provider-for-codex' ); ?></h3>
					<div class="value">
						<?php
						printf(
							/* translators: %d: number of models. */
							esc_html( _n( '%d model configured', '%d models configured', count( $fallback_models ), 'ai-provider-for-codex' ) ),
							count( $fallback_models )
						);
						?>
					</div>
					<div class="meta"><?php esc_html_e( 'Used before a user links a Codex account.', 'ai-provider-for-codex' ); ?></div>
				</div>
			</div>

			<?php if ( ! empty( $fallback_models ) ) : ?>
				<div class="codex-models-list" style="max-width: 960px; margin-bottom: 2rem;">
					<?php foreach ( $fallback_models as $model_id ) : ?>
						<span class="codex-model-pill">
							<?php echo esc_html( $model_id ); ?>
						</span>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php settings_fields( 'ai-provider-for-codex' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="<?php echo esc_attr( Settings::OPTION_RUNTIME_BASE_URL ); ?>"><?php esc_html_e( 'Runtime URL', 'ai-provider-for-codex' ); ?></label></th>
						<td>
							<input class="regular-text code" id="<?php echo esc_attr( Settings::OPTION_RUNTIME_BASE_URL ); ?>" name="<?php echo esc_attr( Settings::OPTION_RUNTIME_BASE_URL ); ?>" type="url" value="<?php echo esc_attr( Settings::get_base_url() ); ?>" <?php disabled( $base_url_locked ); ?> />
							<p class="description"><?php esc_html_e( 'The base URL of the local Codex runtime service, typically http://127.0.0.1:4317.', 'ai-provider-for-codex' ); ?></p>
							<p class="description"><?php echo esc_html( (string) $runtime_config['base_url_source'] ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="<?php echo esc_attr( Settings::OPTION_RUNTIME_BEARER ); ?>"><?php esc_html_e( 'Runtime bearer token', 'ai-provider-for-codex' ); ?></label></th>
						<td>
							<input class="regular-text code" id="<?php echo esc_attr( Settings::OPTION_RUNTIME_BEARER ); ?>" name="<?php echo esc_attr( Settings::OPTION_RUNTIME_BEARER ); ?>" type="password" value="<?php echo esc_attr( $bearer_locked ? '' : Settings::get_bearer_token() ); ?>" <?php disabled( $bearer_locked ); ?> autocomplete="off" placeholder="<?php echo esc_attr( $bearer_locked ? __( 'Managed automatically', 'ai-provider-for-codex' ) : '' ); ?>" />
							<p class="description"><?php esc_html_e( 'The shared bearer token used between WordPress and the local Codex runtime.', 'ai-provider-for-codex' ); ?></p>
							<p class="description"><?php echo esc_html( (string) $runtime_config['bearer_token_source'] ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="<?php echo esc_attr( Settings::OPTION_ALLOWED_MODELS ); ?>"><?php esc_html_e( 'Fallback models', 'ai-provider-for-codex' ); ?></label></th>
						<td>
							<textarea class="large-text code" id="<?php echo esc_attr( Settings::OPTION_ALLOWED_MODELS ); ?>" name="<?php echo esc_attr( Settings::OPTION_ALLOWED_MODELS ); ?>" rows="4"><?php echo esc_textarea( Settings::allowed_models_as_text() ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Model list used before a user links their Codex account. One model ID per line.', 'ai-provider-for-codex' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save settings', 'ai-provider-for-codex' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Returns the page URL.
	 *
	 * @return string
	 */
	public static function page_url(): string {
		return admin_url( 'options-general.php?page=ai-provider-for-codex' );
	}

	/**
	 * Reads the current notice from query args.
	 *
	 * @return array{code:string,message:string}
	 */
	private static function read_notice(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reads display-only notice query args for this admin screen.
		$code    = isset( $_GET['codex_provider_notice'] ) ? sanitize_key( wp_unslash( $_GET['codex_provider_notice'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reads display-only notice query args for this admin screen.
		$message = isset( $_GET['codex_provider_notice_message'] ) ? rawurldecode( sanitize_text_field( wp_unslash( $_GET['codex_provider_notice_message'] ) ) ) : '';

		return [
			'code'    => $code,
			'message' => $message,
		];
	}

	/**
	 * Renders a page notice.
	 *
	 * @param array{code:string,message:string} $notice Notice payload.
	 * @return void
	 */
	private static function render_notice( array $notice ): void {
		if ( '' === $notice['code'] ) {
			return;
		}

		$class = 'notice notice-success';
		$text  = '';

		switch ( $notice['code'] ) {
			case 'settings-saved':
				$text = __( 'Local Codex runtime settings were updated.', 'ai-provider-for-codex' );
				break;
			case 'settings-failed':
				$class = 'notice notice-error';
				$text  = '' !== $notice['message'] ? $notice['message'] : __( 'Updating the local Codex runtime settings failed.', 'ai-provider-for-codex' );
				break;
		}

		if ( '' === $text ) {
			return;
		}
		?>
		<div class="<?php echo esc_attr( $class ); ?>"><p><?php echo esc_html( $text ); ?></p></div>
		<?php
	}
}
