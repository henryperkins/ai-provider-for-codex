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

	private const SCRIPT_MODULE_ID = 'scriptorium-ai-provider-for-codex/diagnostics';

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
		wp_register_script_module(
			self::SCRIPT_MODULE_ID,
			plugins_url( 'assets/diagnostics.js', \AIProviderForCodex\PLUGIN_FILE ),
			[],
			\AIProviderForCodex\VERSION
		);
		wp_enqueue_script_module( self::SCRIPT_MODULE_ID );
		wp_register_style( self::STYLE_HANDLE, false, [], \AIProviderForCodex\VERSION );
		wp_enqueue_style( self::STYLE_HANDLE );
		wp_add_inline_style( self::STYLE_HANDLE, self::inline_css() );
	}

	/**
	 * Supplies config + labels to the diagnostics script module.
	 *
	 * @param array<string,mixed> $data Existing module data.
	 * @return array<string,mixed>
	 */
	public static function script_module_data( array $data ): array {
		return array_merge(
			$data,
			[
				'diagnosticsUrl' => rest_url( 'codex-provider/v1/diagnostics' ),
				'restNonce'      => wp_create_nonce( 'wp_rest' ),
				'labels'         => [
					'run'           => __( 'Check runtime', 'scriptorium-ai-provider-for-codex' ),
					'running'       => __( 'Checking…', 'scriptorium-ai-provider-for-codex' ),
					'healthy'       => __( 'All checks passed.', 'scriptorium-ai-provider-for-codex' ),
					'issues'        => __( 'Some checks need attention:', 'scriptorium-ai-provider-for-codex' ),
					'failed'        => __( 'The diagnostics request failed.', 'scriptorium-ai-provider-for-codex' ),
					'requestFailed' => __( 'The diagnostics request failed', 'scriptorium-ai-provider-for-codex' ),
					'networkError'  => __( 'Could not reach WordPress to run diagnostics.', 'scriptorium-ai-provider-for-codex' ),
					'networkHint'   => __( 'Check your connection and try again.', 'scriptorium-ai-provider-for-codex' ),
					'nonceHint'     => __( 'Your session may have expired. Reload this page and try again.', 'scriptorium-ai-provider-for-codex' ),
					'permHint'      => __( 'You may not have permission to run diagnostics, or your session expired — reload the page and try again.', 'scriptorium-ai-provider-for-codex' ),
				],
			]
		);
	}

	/**
	 * Returns this screen's CSS.
	 *
	 * @return string
	 */
	private static function inline_css(): string {
		return AdminPageStyle::css();
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
	 * The full walkthrough lives in the in-page Setup section (see
	 * render_setup_guide()); the help tab only points to it so the guidance is
	 * not duplicated on the same screen.
	 *
	 * @return void
	 */
	public static function render_help_tab(): void {
		?>
		<p>
			<?php esc_html_e( 'The full setup walkthrough — installing the local runtime, configuring the environment file, and linking each user’s account — is in the Setup section at the bottom of this screen.', 'scriptorium-ai-provider-for-codex' ); ?>
		</p>
		<?php
	}

	/**
	 * Renders the shared setup guide content.
	 *
	 * @return void
	 */
	public static function render_setup_guide(): void {
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
		$runtime_status  = HealthMonitor::get_status();
		$last_diagnostic = get_transient( 'codex_provider_last_diagnostics' );
		$runtime_config  = Settings::configuration_metadata();
		$health_ind      = StatusLabels::status_indicator( (string) $runtime_status['status'] );
		$base_url_locked = ! empty( $runtime_config['base_url_managed'] );
		$bearer_locked   = ! empty( $runtime_config['bearer_token_managed'] );
		?>
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
