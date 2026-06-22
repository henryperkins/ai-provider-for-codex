<?php
/**
 * Site-scoped local runtime settings.
 *
 * @package HtperkinsAIProviderForCodex
 */

declare( strict_types=1 );

namespace Htperkins\AIProviderForCodex\Admin;

use Htperkins\AIProviderForCodex\Runtime\HealthMonitor;
use Htperkins\AIProviderForCodex\Runtime\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the site settings page.
 */
final class SiteSettings {

	private const STYLE_HANDLE = 'codex-provider-site-settings';

	private const SCRIPT_MODULE_ID = 'htperkins-ai-provider-for-codex/diagnostics';

	/**
	 * Registers the settings page.
	 *
	 * @return void
	 */
	public static function register_page(): void {
		$hook = add_options_page(
			__( 'Codex Provider', 'ai-provider-for-codex' ),
			__( 'Codex Provider', 'ai-provider-for-codex' ),
			'manage_options',
			\Htperkins\AIProviderForCodex\SLUG,
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
			plugins_url( 'assets/diagnostics.js', \Htperkins\AIProviderForCodex\PLUGIN_FILE ),
			[],
			\Htperkins\AIProviderForCodex\VERSION
		);
		wp_enqueue_script_module( self::SCRIPT_MODULE_ID );
		wp_register_style( self::STYLE_HANDLE, false, [], \Htperkins\AIProviderForCodex\VERSION );
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
				'diagnosticsUrl' => rest_url( 'htperkins-aipfc/v1/diagnostics' ),
				'restNonce'      => wp_create_nonce( 'wp_rest' ),
				'labels'         => [
					'run'           => __( 'Check runtime', 'ai-provider-for-codex' ),
					'running'       => __( 'Checking…', 'ai-provider-for-codex' ),
					'healthy'       => __( 'All checks passed.', 'ai-provider-for-codex' ),
					'issues'        => __( 'Some checks need attention:', 'ai-provider-for-codex' ),
					'failed'        => __( 'The diagnostics request failed.', 'ai-provider-for-codex' ),
					'requestFailed' => __( 'The diagnostics request failed', 'ai-provider-for-codex' ),
					'networkError'  => __( 'Could not reach WordPress to run diagnostics.', 'ai-provider-for-codex' ),
					'networkHint'   => __( 'Check your connection and try again.', 'ai-provider-for-codex' ),
					'nonceHint'     => __( 'Your session may have expired. Reload this page and try again.', 'ai-provider-for-codex' ),
					'permHint'      => __( 'You may not have permission to run diagnostics, or your session expired — reload the page and try again.', 'ai-provider-for-codex' ),
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
				'title'    => __( 'Codex Provider setup', 'ai-provider-for-codex' ),
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
			<?php esc_html_e( 'The full setup walkthrough for installing Codex app-server, configuring the environment file, and confirming the site-level runtime is in the Setup section at the bottom of this screen.', 'ai-provider-for-codex' ); ?>
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
		$shared_env_file  = (string) $runtime_config['shared_env_file'];
		$runtime_base_url = Settings::get_base_url();
		$healthz_url      = rtrim( '' !== $runtime_base_url ? $runtime_base_url : Settings::DEFAULT_RUNTIME_BASE_URL, '/' ) . '/healthz';
		?>
			<p><?php esc_html_e( 'Codex uses a local app-server service that runs on the same host as WordPress. The service user owns the site-level Codex login used by WordPress AI requests.', 'ai-provider-for-codex' ); ?></p>
			<p>
				<?php
				echo esc_html(
					SafeFormat::sprintf(
						/* translators: %s: absolute shared env file path. */
						__( 'For service installs, the plugin can auto-detect the runtime URL and optional bearer token from %s when PHP can read that file.', 'ai-provider-for-codex' ),
						$shared_env_file
					)
				);
				?>
			</p>
			<p>
				<?php
				echo wp_kses_post(
					SafeFormat::sprintf(
						/* translators: %1$s: connectors settings URL. */
						__(
							'<a href="%1$s">Settings &gt; Connectors</a> is the main status screen. Site-level Codex runtime setup is managed on this page.',
							'ai-provider-for-codex'
						),
						esc_url( admin_url( 'options-connectors.php' ) )
					)
				);
				?>
			</p>
			<h3><?php esc_html_e( 'Quick setup', 'ai-provider-for-codex' ); ?></h3>
			<p><?php esc_html_e( 'WordPress activation is only step one. The plugin starts working after a site-level Codex app-server is running on this server.', 'ai-provider-for-codex' ); ?></p>
			<ol>
				<li><?php esc_html_e( 'On the same host as WordPress, install the codex CLI.', 'ai-provider-for-codex' ); ?></li>
				<li><?php esc_html_e( 'As the service user, run codex login once so app-server has site-level Codex auth.', 'ai-provider-for-codex' ); ?></li>
				<li><?php esc_html_e( 'Start codex app-server with the systemd and environment snippets shown below.', 'ai-provider-for-codex' ); ?></li>
				<li>
					<?php
					echo esc_html(
						SafeFormat::sprintf(
							/* translators: %s: absolute shared env file path. */
							__( 'Confirm Settings > Codex Provider shows the app-server URL from %s. If PHP cannot read that file, enter the same Runtime URL manually, then save.', 'ai-provider-for-codex' ),
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
							__( 'Open <a href="%1$s">Settings &gt; Connectors</a> and confirm Codex reports a healthy local runtime. If it does not, app-server should answer %2$s from the WordPress host.', 'ai-provider-for-codex' ),
							esc_url( admin_url( 'options-connectors.php' ) ),
							esc_html( $healthz_url )
						)
					);
					?>
				</li>
			</ol>
		<?php
	}

	/**
	 * Registers settings.
	 *
	 * @return void
	 */
	public static function register_settings(): void {
		register_setting(
			\Htperkins\AIProviderForCodex\SLUG,
			Settings::OPTION_RUNTIME_BASE_URL,
			[
				'type'              => 'string',
				'sanitize_callback' => [ Settings::class, 'sanitize_base_url' ],
				'default'           => Settings::DEFAULT_RUNTIME_BASE_URL,
			]
		);

		register_setting(
			\Htperkins\AIProviderForCodex\SLUG,
			Settings::OPTION_RUNTIME_BEARER,
			[
				'type'              => 'string',
				'sanitize_callback' => [ Settings::class, 'sanitize_bearer_token' ],
				'default'           => '',
			]
		);

		register_setting(
			\Htperkins\AIProviderForCodex\SLUG,
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
		$last_diagnostic = get_transient( 'htperkins_aipfc_last_diagnostics' );
		$runtime_config  = Settings::configuration_metadata();
		$health_ind      = StatusLabels::status_indicator( (string) $runtime_status['status'] );
		$base_url_locked = ! empty( $runtime_config['base_url_managed'] );
		$bearer_locked   = ! empty( $runtime_config['bearer_token_managed'] );
		$logo_url        = plugins_url( 'src/Provider/logo.svg', \Htperkins\AIProviderForCodex\PLUGIN_FILE );
		?>
		<div class="wrap codex-provider-admin-page">
			<div class="codex-provider-shell">
				<header class="codex-provider-page-header">
					<h1><?php esc_html_e( 'Codex Provider', 'ai-provider-for-codex' ); ?></h1>
					<p class="codex-provider-page-subtitle"><?php esc_html_e( 'Configure the local Codex runtime used by this site\'s AI connector.', 'ai-provider-for-codex' ); ?></p>
				</header>

				<?php self::render_notice( $notice ); ?>
				<?php settings_errors(); ?>

				<div class="codex-provider-stack">
					<section class="codex-provider-card codex-provider-card--runtime">
						<div class="codex-provider-card__header">
							<div class="codex-provider-card__identity">
									<img class="codex-provider-card__logo" src="<?php echo esc_url( $logo_url ); ?>" alt="" width="40" height="40" aria-hidden="true" />
									<div>
										<h2 class="codex-provider-card__title"><?php esc_html_e( 'Runtime', 'ai-provider-for-codex' ); ?></h2>
										<p class="codex-provider-card__description"><?php esc_html_e( 'Local Codex app-server status and diagnostics.', 'ai-provider-for-codex' ); ?></p>
									</div>
								</div>
							<div class="codex-provider-card__action">
								<span class="codex-provider-badge<?php echo esc_attr( ! $is_configured ? ' is-error' : ( 'good' === $health_ind ? '' : ( 'warning' === $health_ind ? ' is-warning' : ' is-error' ) ) ); ?>">
									<?php
									if ( ! $is_configured ) {
										esc_html_e( 'Not configured', 'ai-provider-for-codex' );
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
										esc_html_e( 'Runtime configuration is incomplete.', 'ai-provider-for-codex' );
									} else {
										echo esc_html( StatusLabels::runtime_health_label( (string) $runtime_status['status'] ) );
									}
									?>
								</span>
							</p>
							<dl class="codex-provider-meta-list">
								<?php if ( ! empty( $runtime_status['checked_at'] ) ) : ?>
									<dt><?php esc_html_e( 'Last check', 'ai-provider-for-codex' ); ?></dt>
									<dd><?php echo esc_html( StatusLabels::relative_time( (string) $runtime_status['checked_at'] ) ); ?></dd>
								<?php endif; ?>
								<?php if ( ! empty( $runtime_status['error'] ) ) : ?>
									<dt><?php esc_html_e( 'Error', 'ai-provider-for-codex' ); ?></dt>
									<dd class="codex-provider-error"><?php echo esc_html( (string) $runtime_status['error'] ); ?></dd>
								<?php endif; ?>
								<dt><?php esc_html_e( 'Runtime URL', 'ai-provider-for-codex' ); ?></dt>
								<dd><?php echo esc_html( (string) $runtime_config['base_url_source'] ); ?></dd>
								<dt><?php esc_html_e( 'Bearer token', 'ai-provider-for-codex' ); ?></dt>
								<dd><?php echo esc_html( (string) $runtime_config['bearer_token_source'] ); ?></dd>
							</dl>
							<div class="codex-provider-actions">
								<button type="button" class="button button-secondary" data-codex-diagnostics-run>
									<?php esc_html_e( 'Check runtime', 'ai-provider-for-codex' ); ?>
								</button>
								<a class="button button-secondary" href="<?php echo esc_url( admin_url( 'options-connectors.php' ) ); ?>"><?php esc_html_e( 'Open Connectors', 'ai-provider-for-codex' ); ?></a>
							</div>
							<?php if ( is_array( $last_diagnostic ) ) : ?>
								<p class="codex-provider-guidance">
									<?php
									echo esc_html(
										SafeFormat::sprintf(
											/* translators: 1: pass/fail summary, 2: relative time. */
											__( 'Last diagnostics check: %1$s (%2$s).', 'ai-provider-for-codex' ),
											empty( $last_diagnostic['ok'] )
												? sprintf(
													/* translators: %d: number of failed checks. */
													_n( '%d issue', '%d issues', count( (array) ( $last_diagnostic['failed'] ?? [] ) ), 'ai-provider-for-codex' ),
													count( (array) ( $last_diagnostic['failed'] ?? [] ) )
												)
												: __( 'healthy', 'ai-provider-for-codex' ),
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
						<?php settings_fields( \Htperkins\AIProviderForCodex\SLUG ); ?>
						<section class="codex-provider-card codex-provider-card--settings">
							<div class="codex-provider-card__header">
								<div class="codex-provider-card__identity">
										<img class="codex-provider-card__logo" src="<?php echo esc_url( $logo_url ); ?>" alt="" width="40" height="40" aria-hidden="true" />
										<div>
											<h2 class="codex-provider-card__title"><?php esc_html_e( 'Runtime settings', 'ai-provider-for-codex' ); ?></h2>
											<p class="codex-provider-card__description"><?php esc_html_e( 'Site-level app-server endpoint and fallback model catalog.', 'ai-provider-for-codex' ); ?></p>
										</div>
									</div>
							</div>
							<div class="codex-provider-card__body codex-provider-fields">
									<div class="codex-provider-field">
										<label for="<?php echo esc_attr( Settings::OPTION_RUNTIME_BASE_URL ); ?>"><?php esc_html_e( 'Runtime URL', 'ai-provider-for-codex' ); ?></label>
										<input class="regular-text code" id="<?php echo esc_attr( Settings::OPTION_RUNTIME_BASE_URL ); ?>" name="<?php echo esc_attr( Settings::OPTION_RUNTIME_BASE_URL ); ?>" type="text" value="<?php echo esc_attr( Settings::get_base_url() ); ?>" <?php disabled( $base_url_locked ); ?> />
										<p class="description"><?php esc_html_e( 'WebSocket URL for local Codex app-server, typically ws://127.0.0.1:4500.', 'ai-provider-for-codex' ); ?></p>
										<p class="description"><?php echo esc_html( (string) $runtime_config['base_url_source'] ); ?></p>
									</div>
								<div class="codex-provider-field">
										<label for="<?php echo esc_attr( Settings::OPTION_RUNTIME_BEARER ); ?>"><?php esc_html_e( 'Runtime bearer token', 'ai-provider-for-codex' ); ?></label>
										<input class="regular-text code" id="<?php echo esc_attr( Settings::OPTION_RUNTIME_BEARER ); ?>" name="<?php echo esc_attr( Settings::OPTION_RUNTIME_BEARER ); ?>" type="password" value="<?php echo esc_attr( $bearer_locked ? '' : Settings::get_bearer_token() ); ?>" <?php disabled( $bearer_locked ); ?> autocomplete="off" placeholder="<?php echo esc_attr( $bearer_locked ? __( 'Managed automatically', 'ai-provider-for-codex' ) : '' ); ?>" />
										<p class="description"><?php esc_html_e( 'Optional app-server WebSocket bearer token. Leave empty when app-server is bound to localhost without WebSocket auth.', 'ai-provider-for-codex' ); ?></p>
										<p class="description"><?php esc_html_e( 'Enter only the raw token value here, not a full Authorization header or Bearer prefix.', 'ai-provider-for-codex' ); ?></p>
										<p class="description"><?php echo esc_html( (string) $runtime_config['bearer_token_source'] ); ?></p>
									</div>
									<div class="codex-provider-field">
										<label for="<?php echo esc_attr( Settings::OPTION_ALLOWED_MODELS ); ?>"><?php esc_html_e( 'Fallback models', 'ai-provider-for-codex' ); ?></label>
										<textarea class="large-text code" id="<?php echo esc_attr( Settings::OPTION_ALLOWED_MODELS ); ?>" name="<?php echo esc_attr( Settings::OPTION_ALLOWED_MODELS ); ?>" rows="4"><?php echo esc_textarea( Settings::allowed_models_as_text() ); ?></textarea>
										<p class="description"><?php esc_html_e( 'One text model ID per line. Used as the site-level model catalog before live model discovery is refreshed.', 'ai-provider-for-codex' ); ?></p>
										<?php if ( ! empty( $fallback_models ) ) : ?>
										<div class="codex-provider-models-list" aria-label="<?php esc_attr_e( 'Configured fallback models', 'ai-provider-for-codex' ); ?>">
											<?php foreach ( $fallback_models as $model_id ) : ?>
												<span class="codex-model-pill"><?php echo esc_html( $model_id ); ?></span>
											<?php endforeach; ?>
										</div>
									<?php endif; ?>
								</div>
								<div class="codex-provider-actions">
									<?php submit_button( __( 'Save settings', 'ai-provider-for-codex' ), 'primary', 'submit', false ); ?>
								</div>
							</div>
						</section>
					</form>

					<section class="codex-provider-card codex-provider-card--setup">
						<div class="codex-provider-card__header">
							<div class="codex-provider-card__identity">
									<img class="codex-provider-card__logo" src="<?php echo esc_url( $logo_url ); ?>" alt="" width="40" height="40" aria-hidden="true" />
									<div>
										<h2 class="codex-provider-card__title"><?php esc_html_e( 'Setup', 'ai-provider-for-codex' ); ?></h2>
										<p class="codex-provider-card__description"><?php esc_html_e( 'Start local Codex app-server with site-level auth.', 'ai-provider-for-codex' ); ?></p>
									</div>
								</div>
							</div>
							<div class="codex-provider-card__body">
								<p class="codex-provider-guidance"><?php esc_html_e( 'Run codex app-server on this WordPress host, then verify Codex in Connectors.', 'ai-provider-for-codex' ); ?></p>
								<div class="codex-provider-actions">
									<a class="button button-secondary" href="<?php echo esc_url( admin_url( 'options-connectors.php' ) ); ?>"><?php esc_html_e( 'Open Connectors', 'ai-provider-for-codex' ); ?></a>
								</div>
							<details class="codex-provider-details codex-provider-details--setup">
								<summary><?php esc_html_e( 'Setup walkthrough', 'ai-provider-for-codex' ); ?></summary>
								<div class="codex-provider-details__content">
									<?php self::render_setup_guide(); ?>
								</div>
								</details>
								<details class="codex-provider-details">
									<summary><?php esc_html_e( 'systemd unit (/etc/systemd/system/codex-app-server.service)', 'ai-provider-for-codex' ); ?></summary>
									<textarea class="large-text code" rows="12" readonly><?php echo esc_textarea( SetupSnippets::systemd_unit() ); ?></textarea>
								</details>
								<details class="codex-provider-details">
									<summary><?php esc_html_e( 'Environment file (/etc/codex-app-server.env)', 'ai-provider-for-codex' ); ?></summary>
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
		return admin_url( 'options-general.php?page=' . \Htperkins\AIProviderForCodex\SLUG );
	}

	/**
	 * Reads the current notice from query args.
	 *
	 * @return array{code:string,message:string}
	 */
	private static function read_notice(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reads display-only notice query args for this admin screen.
		$code    = isset( $_GET['htperkins_aipfc_notice'] ) ? sanitize_key( wp_unslash( $_GET['htperkins_aipfc_notice'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reads display-only notice query args for this admin screen.
		$message = isset( $_GET['htperkins_aipfc_notice_message'] ) ? rawurldecode( sanitize_text_field( wp_unslash( $_GET['htperkins_aipfc_notice_message'] ) ) ) : '';

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
