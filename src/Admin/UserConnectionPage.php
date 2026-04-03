<?php
/**
 * Per-user connection UI.
 *
 * @package AIProviderForCodex
 */

declare( strict_types=1 );

namespace AIProviderForCodex\Admin;

use AIProviderForCodex\Admin\StatusLabels;
use AIProviderForCodex\Auth\ConnectionService;
use AIProviderForCodex\Provider\ModelCatalogState;
use AIProviderForCodex\Provider\SupportChecks;
use RuntimeException;

/**
 * Renders the current user's Codex connection page.
 */
final class UserConnectionPage {

	/**
	 * Registers the page.
	 *
	 * @return void
	 */
	public static function register_page(): void {
		add_users_page(
			__( 'Codex Provider', 'ai-provider-for-codex' ),
			__( 'Codex Provider', 'ai-provider-for-codex' ),
			'read',
			'ai-provider-for-codex',
			[ self::class, 'render_page' ]
		);
	}

	/**
	 * Returns the page URL.
	 *
	 * @return string
	 */
	public static function page_url(): string {
		return admin_url( 'users.php?page=ai-provider-for-codex' );
	}

	/**
	 * Handles page actions and callbacks.
	 *
	 * @return void
	 */
	public static function maybe_handle_actions(): void {
		if ( ! is_admin() || ! is_user_logged_in() ) {
			return;
		}

		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		if ( 'ai-provider-for-codex' !== $page ) {
			return;
		}

		$post_action = isset( $_POST['codex_provider_action'] ) ? sanitize_key( wp_unslash( $_POST['codex_provider_action'] ) ) : '';

		if ( 'set-default-model' === $post_action ) {
			check_admin_referer( 'codex-provider-set-default-model' );
			self::set_default_model();
		}

		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';

		if ( 'start-connect' === $action ) {
			check_admin_referer( 'codex-provider-start-connect' );
			self::start_connect();
		}

		if ( 'disconnect' === $action ) {
			check_admin_referer( 'codex-provider-disconnect' );
			self::disconnect();
		}

		if ( 'refresh-status' === $action ) {
			check_admin_referer( 'codex-provider-refresh-status' );
			self::refresh_status();
		}

		if ( isset( $_GET['broker_code'], $_GET['state'] ) ) {
			self::handle_callback();
		}
	}

	/**
	 * Renders the page.
	 *
	 * @return void
	 */
	public static function render_page(): void {
		if ( ! current_user_can( 'read' ) ) {
			return;
		}

		$status  = SupportChecks::current_user_status();
		$catalog = is_array( $status['catalog'] ?? null ) ? $status['catalog'] : ModelCatalogState::get_effective_catalog();
		$notice  = self::read_notice();
		$reason  = (string) $status['reason'];
		$ind     = StatusLabels::status_indicator( $reason );
		$model_labels = ModelCatalogState::labels_from_catalog( $catalog );
		$model_ids    = is_array( $catalog['model_ids'] ?? null ) ? $catalog['model_ids'] : [];
		$default_model = (string) ( $catalog['default_model'] ?? '' );
		$user_preferred_model = ModelCatalogState::get_user_preferred_model();
		?>
		<style>
			.codex-how-box { background: #f0f6fc; border: 1px solid #c3c4c7; border-left: 4px solid #2271b1; padding: 0.75rem 1.25rem; max-width: 960px; margin-bottom: 1.5rem; border-radius: 2px; }
			.codex-how-box p { margin: 0.25rem 0; }
			.codex-how-box ul { margin: 0.5rem 0 0.25rem 1.25rem; }
			.codex-how-box li { margin-bottom: 0.25rem; }
			.codex-indicator { display: inline-block; width: 10px; height: 10px; border-radius: 50%; margin-right: 6px; vertical-align: middle; }
			.codex-indicator.good { background: #00a32a; }
			.codex-indicator.warning { background: #dba617; }
			.codex-indicator.error { background: #d63638; }
			.codex-models-section { margin: 1.5rem 0; max-width: 960px; }
			.codex-models-list { display: flex; flex-wrap: wrap; gap: 0.375rem; margin-top: 0.5rem; }
			.codex-model-pill { display: inline-block; background: #f0f0f1; border-radius: 3px; padding: 2px 8px; font-size: 12px; }
			.codex-model-pill.default { background: #2271b1; color: #fff; }
		</style>
		<div class="wrap">
			<h1><?php esc_html_e( 'Codex Provider', 'ai-provider-for-codex' ); ?></h1>

			<div class="codex-how-box">
				<p><strong><?php esc_html_e( 'How Codex works', 'ai-provider-for-codex' ); ?></strong></p>
				<ul>
					<li><?php esc_html_e( 'Site registration (admin, one-time) — connects this WordPress site to the broker service.', 'ai-provider-for-codex' ); ?></li>
					<li><?php esc_html_e( 'User account linking (each person) — connects your Codex account so usage is tracked and billed to you.', 'ai-provider-for-codex' ); ?></li>
				</ul>
				<p>
					<?php
					printf(
						wp_kses_post(
							__(
								'This page manages your personal account link. <a href="%1$s">Plugin settings</a> control the site registration shared by all users. <a href="%2$s">Settings &gt; Connectors</a> shows overall provider status.',
								'ai-provider-for-codex'
							)
						),
						esc_url( SiteSettings::page_url() ),
						esc_url( admin_url( 'options-connectors.php' ) )
					);
					?>
				</p>
			</div>

			<?php self::render_notice( $notice ); ?>

			<table class="widefat striped" style="max-width: 960px">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Site configured', 'ai-provider-for-codex' ); ?></th>
						<td><?php echo $status['siteConfigured'] ? esc_html__( 'Yes', 'ai-provider-for-codex' ) : esc_html__( 'No', 'ai-provider-for-codex' ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Status', 'ai-provider-for-codex' ); ?></th>
						<td>
							<span class="codex-indicator <?php echo esc_attr( $ind ); ?>"></span>
							<?php echo esc_html( StatusLabels::readiness_label( $reason ) ); ?>
							<?php
							$guidance = StatusLabels::readiness_guidance( $reason );
							if ( '' !== $guidance ) :
								?>
								<br /><em><?php echo esc_html( $guidance ); ?></em>
							<?php endif; ?>
						</td>
					</tr>
					<?php if ( ! empty( $status['connection'] ) ) : ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'Connection ID', 'ai-provider-for-codex' ); ?></th>
							<td><code><?php echo esc_html( (string) $status['connection']['connection_id'] ); ?></code></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Account email', 'ai-provider-for-codex' ); ?></th>
							<td><?php echo esc_html( (string) $status['connection']['account_email'] ); ?></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Plan type', 'ai-provider-for-codex' ); ?></th>
							<td><?php echo esc_html( (string) $status['connection']['plan_type'] ); ?></td>
						</tr>
					<?php endif; ?>
					<?php if ( ! empty( $catalog['source'] ) ) : ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'Model source', 'ai-provider-for-codex' ); ?></th>
							<td>
								<?php echo esc_html( StatusLabels::catalog_source_label( (string) $catalog['source'] ) ); ?>
								<?php if ( ! empty( $catalog['checked_at'] ) ) : ?>
									<br /><em>
									<?php
									printf(
										/* translators: %s: relative time. */
										esc_html__( 'Refreshed %s', 'ai-provider-for-codex' ),
										esc_html( StatusLabels::relative_time( (string) $catalog['checked_at'] ) )
									);
									?>
									</em>
								<?php endif; ?>
							</td>
						</tr>
					<?php endif; ?>
				</tbody>
			</table>

				<?php if ( ! empty( $model_labels ) ) : ?>
					<div class="codex-models-section">
						<h3><?php esc_html_e( 'Your Models', 'ai-provider-for-codex' ); ?></h3>
					<?php if ( '' !== $default_model ) : ?>
						<p>
							<?php
							printf(
								/* translators: %s: model name. */
								esc_html__( 'Default: %s', 'ai-provider-for-codex' ),
								esc_html( ModelCatalogState::label_for_model_id( $default_model ) )
							);
							?>
						</p>
					<?php endif; ?>
					<div class="codex-models-list">
						<?php foreach ( $model_labels as $label ) : ?>
							<span class="codex-model-pill<?php echo $label === ModelCatalogState::label_for_model_id( $default_model ) ? ' default' : ''; ?>">
								<?php echo esc_html( $label ); ?>
							</span>
						<?php endforeach; ?>
					</div>
						<?php if ( 'settings_fallback' === ( $catalog['source'] ?? '' ) ) : ?>
							<p class="description"><?php esc_html_e( 'Using configured defaults — connect an account for live model discovery.', 'ai-provider-for-codex' ); ?></p>
						<?php endif; ?>
						<form method="post" action="<?php echo esc_url( self::page_url() ); ?>" style="margin-top: 1rem;">
							<?php wp_nonce_field( 'codex-provider-set-default-model' ); ?>
							<input type="hidden" name="codex_provider_action" value="set-default-model" />
							<label for="codex_provider_default_model"><strong><?php esc_html_e( 'Preferred default model', 'ai-provider-for-codex' ); ?></strong></label><br />
							<select
								id="codex_provider_default_model"
								name="codex_provider_default_model"
								<?php disabled( [] === $model_ids ); ?>
							>
								<option value=""><?php esc_html_e( 'Use site default / broker default', 'ai-provider-for-codex' ); ?></option>
								<?php foreach ( $model_ids as $model_id ) : ?>
									<option value="<?php echo esc_attr( (string) $model_id ); ?>" <?php selected( $user_preferred_model, (string) $model_id ); ?>>
										<?php echo esc_html( ModelCatalogState::label_for_model_id( (string) $model_id ) ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<?php submit_button( __( 'Save preferred model', 'ai-provider-for-codex' ), 'secondary', 'submit', false, [ 'style' => 'margin-left: 0.5rem;' ] ); ?>
							<p class="description">
								<?php esc_html_e( 'Choose which discovered model this site should prefer for your Codex-backed requests. Leave blank to follow the site or broker default.', 'ai-provider-for-codex' ); ?>
							</p>
						</form>
					</div>
				<?php endif; ?>

			<p style="margin-top: 1.5rem;">
				<?php if ( ! $status['siteConfigured'] ) : ?>
					<a class="button button-secondary" href="<?php echo esc_url( SiteSettings::page_url() ); ?>"><?php esc_html_e( 'Configure site broker settings', 'ai-provider-for-codex' ); ?></a>
				<?php elseif ( empty( $status['connection'] ) ) : ?>
					<a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'action', 'start-connect', self::page_url() ), 'codex-provider-start-connect' ) ); ?>"><?php esc_html_e( 'Connect Codex account', 'ai-provider-for-codex' ); ?></a>
				<?php else : ?>
					<a class="button button-secondary" href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'action', 'refresh-status', self::page_url() ), 'codex-provider-refresh-status' ) ); ?>"><?php esc_html_e( 'Refresh status', 'ai-provider-for-codex' ); ?></a>
					<a class="button button-link-delete" href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'action', 'disconnect', self::page_url() ), 'codex-provider-disconnect' ) ); ?>"><?php esc_html_e( 'Disconnect Codex account', 'ai-provider-for-codex' ); ?></a>
				<?php endif; ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Starts the connect flow.
	 *
	 * @return void
	 */
	private static function start_connect(): void {
		try {
			$service     = new ConnectionService();
			$connect_url = $service->start_connect( get_current_user_id(), self::page_url() );

			wp_redirect( esc_url_raw( $connect_url ) );
			exit;
		} catch ( RuntimeException $exception ) {
			self::redirect_with_notice( 'connect-failed', $exception->getMessage() );
		}
	}

	/**
	 * Handles the broker callback.
	 *
	 * @return void
	 */
	private static function handle_callback(): void {
		$broker_code = sanitize_text_field( (string) wp_unslash( $_GET['broker_code'] ) );
		$state       = sanitize_text_field( (string) wp_unslash( $_GET['state'] ) );

		try {
			$service = new ConnectionService();
			$service->exchange_code( get_current_user_id(), $state, $broker_code );
			self::redirect_with_notice( 'connected' );
		} catch ( RuntimeException $exception ) {
			self::redirect_with_notice( 'connect-failed', $exception->getMessage() );
		}
	}

	/**
	 * Refreshes the local snapshot.
	 *
	 * @return void
	 */
	private static function refresh_status(): void {
		try {
			$service = new ConnectionService();
			$service->refresh_snapshot( get_current_user_id() );
			self::redirect_with_notice( 'status-refreshed' );
		} catch ( RuntimeException $exception ) {
			self::redirect_with_notice( 'refresh-failed', $exception->getMessage() );
		}
	}

	/**
	 * Disconnects the current user.
	 *
	 * @return void
	 */
	private static function disconnect(): void {
		$service = new ConnectionService();
		$service->disconnect( get_current_user_id() );
		ModelCatalogState::delete_user_preferred_model( get_current_user_id() );
		self::redirect_with_notice( 'disconnected' );
	}

	/**
	 * Stores the current user's preferred default model.
	 *
	 * @return void
	 */
	private static function set_default_model(): void {
		$wp_user_id     = get_current_user_id();
		$selected_model = isset( $_POST['codex_provider_default_model'] )
			? sanitize_text_field( (string) wp_unslash( $_POST['codex_provider_default_model'] ) )
			: '';
		$catalog        = ModelCatalogState::get_effective_catalog( $wp_user_id );
		$model_ids      = is_array( $catalog['model_ids'] ?? null ) ? $catalog['model_ids'] : [];

		if ( '' !== $selected_model && ! in_array( $selected_model, $model_ids, true ) ) {
			self::redirect_with_notice( 'default-model-failed', __( 'That model is not available in your current Codex catalog.', 'ai-provider-for-codex' ) );
		}

		ModelCatalogState::update_user_preferred_model( $wp_user_id, $selected_model );
		self::redirect_with_notice( 'default-model-updated' );
	}

	/**
	 * Redirects back with a notice.
	 *
	 * @param string      $code Notice code.
	 * @param string|null $message Optional message.
	 * @return void
	 */
	private static function redirect_with_notice( string $code, ?string $message = null ): void {
		wp_safe_redirect(
			add_query_arg(
				[
					'codex_provider_notice'         => $code,
					'codex_provider_notice_message' => rawurlencode( (string) $message ),
				],
				self::page_url()
			)
		);
		exit;
	}

	/**
	 * Reads the current notice.
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
	 * Renders the current page notice.
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
				$class = 'notice notice-success';
				$text  = __( 'Site configured — connect your Codex account to start using AI features.', 'ai-provider-for-codex' );
				break;
			case 'connected':
				$text = __( 'Your Codex account is now linked.', 'ai-provider-for-codex' );
				break;
			case 'disconnected':
				$text = __( 'Your local Codex link has been removed.', 'ai-provider-for-codex' );
				break;
			case 'status-refreshed':
				$text = __( 'The local Codex snapshot was refreshed.', 'ai-provider-for-codex' );
				break;
			case 'default-model-updated':
				$text = __( 'Your preferred Codex model was updated.', 'ai-provider-for-codex' );
				break;
			case 'connect-failed':
			case 'refresh-failed':
			case 'default-model-failed':
				$class = 'notice notice-error';
				$text  = '' !== $notice['message'] ? $notice['message'] : __( 'The Codex broker request failed.', 'ai-provider-for-codex' );
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
