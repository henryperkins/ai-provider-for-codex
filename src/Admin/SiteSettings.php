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

	private const STYLE_HANDLE = 'codex-provider-site-settings';

	/**
	 * Registers the settings page.
	 *
	 * @return void
	 */
	public static function register_page(): void {
		$hook = add_options_page(
			__( 'Codex Provider', 'scriptorium-ai-provider-for-codex' ),
			__( 'Codex Provider', 'scriptorium-ai-provider-for-codex' ),
			'manage_options',
			\AIProviderForCodex\SLUG,
			[ self::class, 'render_page' ]
		);

		if ( $hook ) {
			add_action( "load-{$hook}", [ self::class, 'register_help_tab' ] );
			add_action(
				'admin_enqueue_scripts',
				static function ( $hook_suffix ) use ( $hook ): void {
					if ( (string) $hook_suffix === (string) $hook ) {
						self::enqueue_assets();
					}
				}
			);
		}
	}

	/**
	 * Registers and enqueues this screen's styles.
	 *
	 * Screen scoping is handled by the admin_enqueue_scripts closure in
	 * register_page(); release verification calls this directly.
	 *
	 * @return void
	 */
	public static function enqueue_assets(): void {
		wp_register_style( self::STYLE_HANDLE, false, [], \AIProviderForCodex\VERSION );
		wp_enqueue_style( self::STYLE_HANDLE );
		wp_add_inline_style( self::STYLE_HANDLE, self::inline_css() );
	}

	/**
	 * Returns this screen's CSS.
	 *
	 * @return string
	 */
	private static function inline_css(): string {
		return '
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
		';
	}

	/**
	 * Registers the contextual help tab for this screen.
	 *
	 * @return void
	 */
	public static function register_help_tab(): void {
		$screen = get_current_screen();

		if ( ! $screen ) {
			return;
		}

		$screen->add_help_tab(
			[
				'id'       => 'codex-provider-site-settings',
				'title'    => __( 'Codex Provider setup', 'scriptorium-ai-provider-for-codex' ),
				'callback' => [ self::class, 'render_help_tab' ],
			]
		);
	}

	/**
	 * Renders the contextual help tab content.
	 *
	 * @return void
	 */
	public static function render_help_tab(): void {
		$runtime_config   = Settings::configuration_metadata();
		$plugin_dir       = untrailingslashit( \AIProviderForCodex\PLUGIN_DIR );
		$shared_env_file  = (string) $runtime_config['shared_env_file'];
		$service_template = sprintf( '%s/sidecar/systemd/codex-wp-sidecar.service', $plugin_dir );
		$env_example      = sprintf( '%s/sidecar/config.example.env', $plugin_dir );
		$manual_command   = sprintf( 'CODEX_WP_BEARER_TOKEN=replace-me python3 %s/sidecar/app/main.py', $plugin_dir );
		$runtime_base_url = Settings::get_base_url();
		$healthz_url      = rtrim( '' !== $runtime_base_url ? $runtime_base_url : Settings::DEFAULT_RUNTIME_BASE_URL, '/' ) . '/healthz';
		?>
		<p><?php esc_html_e( 'Codex uses a local runtime service that runs on the same host as WordPress. Each user links their own Codex or ChatGPT account so access and billing stay user-specific.', 'scriptorium-ai-provider-for-codex' ); ?></p>
		<p>
			<?php
			echo esc_html(
				SafeFormat::sprintf(
					/* translators: %s: absolute shared env file path. */
					__( 'For service installs, the plugin can auto-detect the runtime URL and bearer token from %s when PHP can read that file.', 'scriptorium-ai-provider-for-codex' ),
					$shared_env_file
				)
			);
			?>
		</p>
		<p>
			<?php
			echo wp_kses_post(
				SafeFormat::sprintf(
					/* translators: 1: connectors settings URL, 2: user connection page URL. */
					__(
						'<a href="%1$s">Settings &gt; Connectors</a> is the main status screen. Per-user account linking is on the <a href="%2$s">user connection page</a>.',
						'scriptorium-ai-provider-for-codex'
					),
					esc_url( admin_url( 'options-connectors.php' ) ),
					esc_url( UserConnectionPage::page_url() )
				)
			);
			?>
		</p>
		<h3><?php esc_html_e( 'Quick setup', 'scriptorium-ai-provider-for-codex' ); ?></h3>
		<p><?php esc_html_e( 'WordPress activation is only step one. The plugin starts working after the local sidecar is running on this server and each user links their own account.', 'scriptorium-ai-provider-for-codex' ); ?></p>
		<ol>
			<li><?php esc_html_e( 'On the same host as WordPress, install Python 3.11+ and the codex CLI.', 'scriptorium-ai-provider-for-codex' ); ?></li>
			<li>
				<?php esc_html_e( 'Use the bundled systemd service template and environment example from the installed plugin directory:', 'scriptorium-ai-provider-for-codex' ); ?>
				<p><code><?php echo esc_html( $service_template ); ?></code></p>
				<p><code><?php echo esc_html( $env_example ); ?></code></p>
			</li>
			<li>
				<?php
				echo esc_html(
					SafeFormat::sprintf(
						/* translators: %s: absolute shared env file path. */
						__( 'Confirm Settings > Codex Provider shows the runtime URL and bearer token from %s. If PHP cannot read that file, enter the same Runtime URL and raw bearer token manually, then save.', 'scriptorium-ai-provider-for-codex' ),
						$shared_env_file
					)
				);
				?>
			</li>
			<li>
				<?php
				echo wp_kses_post(
					SafeFormat::sprintf(
						/* translators: %s: expected health check URL. */
						__( 'Open <a href="%1$s">Settings &gt; Connectors</a> and confirm Codex reports a healthy local runtime. If it does not, the sidecar should answer %2$s from the WordPress host.', 'scriptorium-ai-provider-for-codex' ),
						esc_url( admin_url( 'options-connectors.php' ) ),
						esc_html( $healthz_url )
					)
				);
				?>
			</li>
			<li>
				<?php
				echo wp_kses_post(
					SafeFormat::sprintf(
						/* translators: %s: user connection page URL. */
						__( 'Have each user open <a href="%s">Users &gt; Codex Provider</a>, click Connect Codex account, and complete the device-code login.', 'scriptorium-ai-provider-for-codex' ),
						esc_url( UserConnectionPage::page_url() )
					)
				);
				?>
			</li>
		</ol>
		<p><?php esc_html_e( 'Manual fallback for quick testing:', 'scriptorium-ai-provider-for-codex' ); ?></p>
		<p><code><?php echo esc_html( $manual_command ); ?></code></p>
		<?php
	}

	/**
	 * Registers settings.
	 *
	 * @return void
	 */
	public static function register_settings(): void {
		register_setting(
			\AIProviderForCodex\SLUG,
			Settings::OPTION_RUNTIME_BASE_URL,
			[
				'type'              => 'string',
				'sanitize_callback' => [ Settings::class, 'sanitize_base_url' ],
				'default'           => Settings::DEFAULT_RUNTIME_BASE_URL,
			]
		);

		register_setting(
			\AIProviderForCodex\SLUG,
			Settings::OPTION_RUNTIME_BEARER,
			[
				'type'              => 'string',
				'sanitize_callback' => [ Settings::class, 'sanitize_bearer_token' ],
				'default'           => '',
			]
		);

		register_setting(
			\AIProviderForCodex\SLUG,
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
		<div class="wrap">
			<h1><?php esc_html_e( 'Scriptorium AI Provider for Codex', 'scriptorium-ai-provider-for-codex' ); ?></h1>

			<?php self::render_notice( $notice ); ?>
			<?php settings_errors(); ?>

			<div class="codex-status-cards">
				<div class="codex-status-card">
					<h3><?php esc_html_e( 'Runtime', 'scriptorium-ai-provider-for-codex' ); ?></h3>
					<div class="value">
						<span class="codex-indicator <?php echo esc_attr( $is_configured ? $health_ind : 'error' ); ?>"></span>
						<?php
						if ( ! $is_configured ) {
							esc_html_e( 'Not configured', 'scriptorium-ai-provider-for-codex' );
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
					<h3><?php esc_html_e( 'Fallback models', 'scriptorium-ai-provider-for-codex' ); ?></h3>
					<div class="value">
						<?php
						echo esc_html(
							SafeFormat::sprintf(
								/* translators: %d: number of models. */
								_n( '%d model configured', '%d models configured', count( $fallback_models ), 'scriptorium-ai-provider-for-codex' ),
								count( $fallback_models )
							)
						);
						?>
					</div>
					<div class="meta"><?php esc_html_e( 'Used before a user links a Codex account.', 'scriptorium-ai-provider-for-codex' ); ?></div>
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
				<?php settings_fields( \AIProviderForCodex\SLUG ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="<?php echo esc_attr( Settings::OPTION_RUNTIME_BASE_URL ); ?>"><?php esc_html_e( 'Runtime URL', 'scriptorium-ai-provider-for-codex' ); ?></label></th>
						<td>
							<input class="regular-text code" id="<?php echo esc_attr( Settings::OPTION_RUNTIME_BASE_URL ); ?>" name="<?php echo esc_attr( Settings::OPTION_RUNTIME_BASE_URL ); ?>" type="url" value="<?php echo esc_attr( Settings::get_base_url() ); ?>" <?php disabled( $base_url_locked ); ?> />
							<p class="description"><?php esc_html_e( 'The base URL of the local Codex runtime service, typically http://127.0.0.1:4317.', 'scriptorium-ai-provider-for-codex' ); ?></p>
							<p class="description"><?php echo esc_html( (string) $runtime_config['base_url_source'] ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="<?php echo esc_attr( Settings::OPTION_RUNTIME_BEARER ); ?>"><?php esc_html_e( 'Runtime bearer token', 'scriptorium-ai-provider-for-codex' ); ?></label></th>
						<td>
							<input class="regular-text code" id="<?php echo esc_attr( Settings::OPTION_RUNTIME_BEARER ); ?>" name="<?php echo esc_attr( Settings::OPTION_RUNTIME_BEARER ); ?>" type="password" value="<?php echo esc_attr( $bearer_locked ? '' : Settings::get_bearer_token() ); ?>" <?php disabled( $bearer_locked ); ?> autocomplete="off" placeholder="<?php echo esc_attr( $bearer_locked ? __( 'Managed automatically', 'scriptorium-ai-provider-for-codex' ) : '' ); ?>" />
							<p class="description"><?php esc_html_e( 'The shared bearer token used between WordPress and the local Codex runtime.', 'scriptorium-ai-provider-for-codex' ); ?></p>
							<p class="description"><?php esc_html_e( 'Enter only the raw token value here, not a full Authorization header or Bearer prefix.', 'scriptorium-ai-provider-for-codex' ); ?></p>
							<p class="description"><?php echo esc_html( (string) $runtime_config['bearer_token_source'] ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="<?php echo esc_attr( Settings::OPTION_ALLOWED_MODELS ); ?>"><?php esc_html_e( 'Fallback models', 'scriptorium-ai-provider-for-codex' ); ?></label></th>
						<td>
							<textarea class="large-text code" id="<?php echo esc_attr( Settings::OPTION_ALLOWED_MODELS ); ?>" name="<?php echo esc_attr( Settings::OPTION_ALLOWED_MODELS ); ?>" rows="4"><?php echo esc_textarea( Settings::allowed_models_as_text() ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Model list used before a user links their Codex account. One model ID per line.', 'scriptorium-ai-provider-for-codex' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save settings', 'scriptorium-ai-provider-for-codex' ) ); ?>
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
		return admin_url( 'options-general.php?page=' . \AIProviderForCodex\SLUG );
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
				$text = __( 'Local Codex runtime settings were updated.', 'scriptorium-ai-provider-for-codex' );
				break;
			case 'settings-failed':
				$class = 'notice notice-error';
				$text  = '' !== $notice['message'] ? $notice['message'] : __( 'Updating the local Codex runtime settings failed.', 'scriptorium-ai-provider-for-codex' );
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
