<?php
/**
 * Site-scoped broker settings.
 *
 * @package AIProviderForCodex
 */

declare( strict_types=1 );

namespace AIProviderForCodex\Admin;

use AIProviderForCodex\Broker\HealthMonitor;
use AIProviderForCodex\Broker\Settings;
use AIProviderForCodex\Broker\SiteRegistration;
use AIProviderForCodex\Provider\ModelCatalogState;
use RuntimeException;

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
			Settings::OPTION_BROKER_BASE_URL,
			[
				'type'              => 'string',
				'sanitize_callback' => [ Settings::class, 'sanitize_base_url' ],
				'default'           => '',
			]
		);

		register_setting(
			'ai-provider-for-codex',
			Settings::OPTION_SITE_ID,
			[
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			]
		);

		register_setting(
			'ai-provider-for-codex',
			Settings::OPTION_SITE_SECRET,
			[
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			]
		);

		register_setting(
			'ai-provider-for-codex',
			Settings::OPTION_DEFAULT_MODEL,
			[
				'type'              => 'string',
				'sanitize_callback' => [ Settings::class, 'sanitize_default_model' ],
				'default'           => Settings::get_default_model(),
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
	 * Handles installation exchange actions.
	 *
	 * @return void
	 */
	public static function maybe_handle_actions(): void {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$page   = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$action = isset( $_POST['codex_provider_action'] ) ? sanitize_key( wp_unslash( $_POST['codex_provider_action'] ) ) : '';

		if ( 'ai-provider-for-codex' !== $page || 'exchange-installation' !== $action ) {
			return;
		}

		check_admin_referer( 'codex-provider-exchange-installation' );

		try {
			SiteRegistration::exchange_installation_code(
				(string) wp_unslash( $_POST['installation_code'] ?? '' )
			);

			// Redirect to user connection page so the admin can link their account next.
			wp_safe_redirect(
				add_query_arg(
					[
						'codex_provider_notice' => 'site-registered',
					],
					UserConnectionPage::page_url()
				)
			);
			exit;
		} catch ( RuntimeException $exception ) {
			self::redirect_with_notice( 'site-registration-failed', $exception->getMessage() );
		}
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

		$notice        = self::read_notice();
		$site_catalog  = ModelCatalogState::get_site_catalog();
		$broker_status = HealthMonitor::get_status();
		$is_configured = Settings::has_required_site_configuration();
		$health_ind    = StatusLabels::status_indicator( (string) $broker_status['status'] );
		$model_labels  = ModelCatalogState::labels_from_catalog( $site_catalog );
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
			.codex-model-pill.default { background: #2271b1; color: #fff; }
			.codex-how-it-works { background: #f0f6fc; border: 1px solid #c3c4c7; border-left: 4px solid #2271b1; padding: 1rem 1.25rem; max-width: 960px; margin-bottom: 1.5rem; border-radius: 2px; }
			.codex-how-it-works p { margin: 0.25rem 0; }
			.codex-advanced-toggle { cursor: pointer; user-select: none; color: #2271b1; font-weight: 600; }
			.codex-advanced-toggle:hover { color: #135e96; }
		</style>
		<div class="wrap">
			<h1><?php esc_html_e( 'AI Provider for Codex', 'ai-provider-for-codex' ); ?></h1>

			<div class="codex-how-it-works">
				<p>
					<?php esc_html_e( 'Codex uses a broker service that connects your WordPress site to Codex AI models. Unlike API-key providers, each user links their own Codex account for billing and access control.', 'ai-provider-for-codex' ); ?>
				</p>
				<p>
					<?php
					printf(
						wp_kses_post(
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

			<div class="codex-status-cards">
				<div class="codex-status-card">
					<h3><?php esc_html_e( 'Connection', 'ai-provider-for-codex' ); ?></h3>
					<div class="value">
						<span class="codex-indicator <?php echo esc_attr( $is_configured ? $health_ind : 'error' ); ?>"></span>
						<?php
						if ( ! $is_configured ) {
							esc_html_e( 'Not configured', 'ai-provider-for-codex' );
						} else {
							echo esc_html( StatusLabels::broker_health_label( (string) $broker_status['status'] ) );
						}
						?>
					</div>
					<?php if ( ! empty( $broker_status['checked_at'] ) ) : ?>
						<div class="meta"><?php echo esc_html( StatusLabels::relative_time( (string) $broker_status['checked_at'] ) ); ?></div>
					<?php endif; ?>
					<?php if ( ! empty( $broker_status['error'] ) ) : ?>
						<div class="meta" style="color: #d63638;"><?php echo esc_html( (string) $broker_status['error'] ); ?></div>
					<?php endif; ?>
				</div>

				<div class="codex-status-card">
					<h3><?php esc_html_e( 'Default Model', 'ai-provider-for-codex' ); ?></h3>
					<div class="value">
						<?php
						$default_model = (string) $site_catalog['default_model'];
						echo esc_html( '' !== $default_model ? ModelCatalogState::label_for_model_id( $default_model ) : '—' );
						?>
					</div>
					<div class="meta">
						<?php
						printf(
							/* translators: %d: number of models. */
							esc_html( _n( '%d model available', '%d models available', count( $model_labels ), 'ai-provider-for-codex' ) ),
							count( $model_labels )
						);
						?>
					</div>
				</div>

				<div class="codex-status-card">
					<h3><?php esc_html_e( 'Catalog', 'ai-provider-for-codex' ); ?></h3>
					<div class="value"><?php echo esc_html( StatusLabels::catalog_source_label( (string) $site_catalog['source'] ) ); ?></div>
					<?php if ( ! empty( $site_catalog['checked_at'] ) ) : ?>
						<div class="meta">
							<?php
							printf(
								/* translators: %s: relative time. */
								esc_html__( 'Refreshed %s', 'ai-provider-for-codex' ),
								esc_html( StatusLabels::relative_time( (string) $site_catalog['checked_at'] ) )
							);
							?>
						</div>
					<?php endif; ?>
				</div>
			</div>

			<?php if ( ! empty( $model_labels ) ) : ?>
				<div class="codex-models-list" style="max-width: 960px; margin-bottom: 2rem;">
					<?php foreach ( $model_labels as $label ) : ?>
						<span class="codex-model-pill<?php echo $label === ModelCatalogState::label_for_model_id( $default_model ) ? ' default' : ''; ?>">
							<?php echo esc_html( $label ); ?>
						</span>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<?php if ( ! $is_configured ) : ?>
				<h2><?php esc_html_e( 'Site Setup', 'ai-provider-for-codex' ); ?></h2>
				<p><?php esc_html_e( 'Use a one-time installation code from your broker dashboard to connect this site. The code configures the site-to-broker connection shared by all users.', 'ai-provider-for-codex' ); ?></p>
				<form method="post" action="<?php echo esc_url( self::page_url() ); ?>">
					<?php wp_nonce_field( 'codex-provider-exchange-installation' ); ?>
					<input type="hidden" name="codex_provider_action" value="exchange-installation" />
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row">
								<label for="installation_code"><?php esc_html_e( 'Installation code', 'ai-provider-for-codex' ); ?></label>
							</th>
							<td>
								<input class="regular-text" id="installation_code" name="installation_code" type="text" value="" />
								<p class="description"><?php esc_html_e( 'Paste the one-time code from your broker onboarding page.', 'ai-provider-for-codex' ); ?></p>
							</td>
						</tr>
					</table>
					<?php submit_button( __( 'Exchange installation code', 'ai-provider-for-codex' ) ); ?>
				</form>
			<?php else : ?>
				<h2><?php esc_html_e( 'Re-register Site', 'ai-provider-for-codex' ); ?></h2>
				<p><?php esc_html_e( 'If you need to re-register with a new installation code, paste it here. This replaces the existing site credentials.', 'ai-provider-for-codex' ); ?></p>
				<form method="post" action="<?php echo esc_url( self::page_url() ); ?>">
					<?php wp_nonce_field( 'codex-provider-exchange-installation' ); ?>
					<input type="hidden" name="codex_provider_action" value="exchange-installation" />
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row">
								<label for="installation_code"><?php esc_html_e( 'Installation code', 'ai-provider-for-codex' ); ?></label>
							</th>
							<td>
								<input class="regular-text" id="installation_code" name="installation_code" type="text" value="" />
							</td>
						</tr>
					</table>
					<?php submit_button( __( 'Exchange installation code', 'ai-provider-for-codex' ) ); ?>
				</form>
			<?php endif; ?>

			<hr />

			<p>
				<span class="codex-advanced-toggle" onclick="document.getElementById('codex-advanced-settings').style.display = document.getElementById('codex-advanced-settings').style.display === 'none' ? 'block' : 'none';">
					▸ <?php esc_html_e( 'Advanced configuration', 'ai-provider-for-codex' ); ?>
				</span>
			</p>

			<div id="codex-advanced-settings" style="display: none;">
				<p class="description"><?php esc_html_e( 'These fields are populated automatically by the installation code exchange. Edit them only if you need to override broker settings manually.', 'ai-provider-for-codex' ); ?></p>
				<form method="post" action="options.php">
					<?php settings_fields( 'ai-provider-for-codex' ); ?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="<?php echo esc_attr( Settings::OPTION_BROKER_BASE_URL ); ?>"><?php esc_html_e( 'Broker base URL', 'ai-provider-for-codex' ); ?></label></th>
							<td>
								<input class="regular-text code" id="<?php echo esc_attr( Settings::OPTION_BROKER_BASE_URL ); ?>" name="<?php echo esc_attr( Settings::OPTION_BROKER_BASE_URL ); ?>" type="url" value="<?php echo esc_attr( Settings::get_base_url() ); ?>" />
								<p class="description"><?php esc_html_e( 'The URL of your Codex broker service (provided during onboarding).', 'ai-provider-for-codex' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="<?php echo esc_attr( Settings::OPTION_SITE_ID ); ?>"><?php esc_html_e( 'Site ID', 'ai-provider-for-codex' ); ?></label></th>
							<td>
								<input class="regular-text code" id="<?php echo esc_attr( Settings::OPTION_SITE_ID ); ?>" name="<?php echo esc_attr( Settings::OPTION_SITE_ID ); ?>" type="text" value="<?php echo esc_attr( Settings::get_site_id() ); ?>" />
								<p class="description"><?php esc_html_e( 'Unique identifier assigned to this WordPress site by the broker.', 'ai-provider-for-codex' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="<?php echo esc_attr( Settings::OPTION_SITE_SECRET ); ?>"><?php esc_html_e( 'Site secret', 'ai-provider-for-codex' ); ?></label></th>
							<td>
								<input class="regular-text code" id="<?php echo esc_attr( Settings::OPTION_SITE_SECRET ); ?>" name="<?php echo esc_attr( Settings::OPTION_SITE_SECRET ); ?>" type="password" value="<?php echo esc_attr( Settings::get_site_secret() ); ?>" autocomplete="off" />
								<p class="description"><?php esc_html_e( 'Shared secret for authenticating requests between this site and the broker. Keep this confidential.', 'ai-provider-for-codex' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="<?php echo esc_attr( Settings::OPTION_DEFAULT_MODEL ); ?>"><?php esc_html_e( 'Default model', 'ai-provider-for-codex' ); ?></label></th>
							<td>
								<input class="regular-text code" id="<?php echo esc_attr( Settings::OPTION_DEFAULT_MODEL ); ?>" name="<?php echo esc_attr( Settings::OPTION_DEFAULT_MODEL ); ?>" type="text" value="<?php echo esc_attr( Settings::get_default_model() ); ?>" />
								<p class="description"><?php esc_html_e( 'The model used when no specific model is requested.', 'ai-provider-for-codex' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="<?php echo esc_attr( Settings::OPTION_ALLOWED_MODELS ); ?>"><?php esc_html_e( 'Allowed models', 'ai-provider-for-codex' ); ?></label></th>
							<td>
								<textarea class="large-text code" id="<?php echo esc_attr( Settings::OPTION_ALLOWED_MODELS ); ?>" name="<?php echo esc_attr( Settings::OPTION_ALLOWED_MODELS ); ?>" rows="6"><?php echo esc_textarea( Settings::allowed_models_as_text() ); ?></textarea>
								<p class="description"><?php esc_html_e( 'Fallback model list used until broker snapshots provide a live catalog. One model ID per line.', 'ai-provider-for-codex' ); ?></p>
							</td>
						</tr>
					</table>
					<?php submit_button( __( 'Save settings', 'ai-provider-for-codex' ) ); ?>
				</form>
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
		return admin_url( 'options-general.php?page=ai-provider-for-codex' );
	}

	/**
	 * Redirects back with a notice.
	 *
	 * @param string      $code Notice code.
	 * @param string|null $message Optional message.
	 * @return void
	 */
	private static function redirect_with_notice( string $code, ?string $message = null ): void {
		$url = add_query_arg(
			[
				'codex_provider_notice'         => $code,
				'codex_provider_notice_message' => rawurlencode( (string) $message ),
			],
			self::page_url()
		);

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Reads the current notice from query args.
	 *
	 * @return array{code:string,message:string}
	 */
	private static function read_notice(): array {
		$code    = isset( $_GET['codex_provider_notice'] ) ? sanitize_key( wp_unslash( $_GET['codex_provider_notice'] ) ) : '';
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
			case 'site-registered':
				$text = __( 'Broker site registration was updated.', 'ai-provider-for-codex' );
				break;
			case 'site-registration-failed':
				$class = 'notice notice-error';
				$text  = '' !== $notice['message'] ? $notice['message'] : __( 'The broker site registration exchange failed.', 'ai-provider-for-codex' );
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
