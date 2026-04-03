<?php
/**
 * Per-user connection UI.
 *
 * @package AIProviderForCodex
 */

declare( strict_types=1 );

namespace AIProviderForCodex\Admin;

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
	 * Handles page actions.
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

		if ( 'set-model' === $post_action ) {
			check_admin_referer( 'codex-provider-set-model' );
			self::set_model();
		}

		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';

		if ( 'start-connect' === $action ) {
			check_admin_referer( 'codex-provider-start-connect' );
			self::start_connect();
		}

		if ( 'check-connect' === $action ) {
			check_admin_referer( 'codex-provider-check-connect' );
			self::check_connect();
		}

		if ( 'disconnect' === $action ) {
			check_admin_referer( 'codex-provider-disconnect' );
			self::disconnect();
		}

		if ( 'refresh-status' === $action ) {
			check_admin_referer( 'codex-provider-refresh-status' );
			self::refresh_status();
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

		$status         = SupportChecks::current_user_status();
		$catalog        = is_array( $status['catalog'] ?? null ) ? $status['catalog'] : ModelCatalogState::get_effective_catalog();
		$notice         = self::read_notice();
		$reason         = (string) $status['reason'];
		$pending        = is_array( $status['pendingConnection'] ?? null ) ? $status['pendingConnection'] : null;
		$ind            = StatusLabels::status_indicator( $reason );
		$model_labels   = ModelCatalogState::labels_from_catalog( $catalog );
		$model_ids      = is_array( $catalog['model_ids'] ?? null ) ? $catalog['model_ids'] : [];
		$selected_model = (string) ( $catalog['selected_model'] ?? '' );
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
			.codex-model-pill.selected { background: #2271b1; color: #fff; }
			.codex-device-box { background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 1rem 1.25rem; max-width: 960px; margin-top: 1rem; }
			.codex-device-code { font-family: ui-monospace, SFMono-Regular, monospace; font-size: 24px; font-weight: 600; letter-spacing: 0.18em; }
		</style>
		<div class="wrap">
			<h1><?php esc_html_e( 'Codex Provider', 'ai-provider-for-codex' ); ?></h1>

			<div class="codex-how-box">
				<p><strong><?php esc_html_e( 'How Codex works', 'ai-provider-for-codex' ); ?></strong></p>
				<ul>
					<li><?php esc_html_e( 'This site uses a local Codex runtime running on the same host as WordPress.', 'ai-provider-for-codex' ); ?></li>
					<li><?php esc_html_e( 'Each person connects their own Codex or ChatGPT account so access and billing stay user-specific.', 'ai-provider-for-codex' ); ?></li>
				</ul>
				<p>
					<?php
					printf(
						wp_kses_post(
							__(
								'This page manages your personal account link. <a href="%1$s">Plugin settings</a> control the local runtime shared by all users. <a href="%2$s">Settings &gt; Connectors</a> shows overall provider status.',
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
						<th scope="row"><?php esc_html_e( 'Runtime configured', 'ai-provider-for-codex' ); ?></th>
						<td><?php echo ! empty( $status['runtimeConfigured'] ) ? esc_html__( 'Yes', 'ai-provider-for-codex' ) : esc_html__( 'No', 'ai-provider-for-codex' ); ?></td>
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
				</tbody>
			</table>

			<?php if ( $pending && ! empty( $pending['authSessionId'] ) ) : ?>
				<div class="codex-device-box">
					<h3><?php esc_html_e( 'Complete account connection', 'ai-provider-for-codex' ); ?></h3>
					<p><?php esc_html_e( 'Open the verification page and enter this code:', 'ai-provider-for-codex' ); ?></p>
					<p class="codex-device-code"><?php echo esc_html( (string) $pending['userCode'] ); ?></p>
					<p>
						<a class="button button-secondary" href="<?php echo esc_url( (string) $pending['verificationUrl'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open verification page', 'ai-provider-for-codex' ); ?></a>
						<a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'action', 'check-connect', self::page_url() ), 'codex-provider-check-connect' ) ); ?>"><?php esc_html_e( 'Check connection status', 'ai-provider-for-codex' ); ?></a>
					</p>
					<?php if ( ! empty( $pending['error'] ) ) : ?>
						<p class="description" style="color:#d63638;"><?php echo esc_html( (string) $pending['error'] ); ?></p>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<?php if ( ! empty( $model_labels ) ) : ?>
				<div class="codex-models-section">
					<h3><?php esc_html_e( 'Model', 'ai-provider-for-codex' ); ?></h3>
					<?php if ( '' !== $selected_model ) : ?>
						<p>
							<?php
							printf(
								/* translators: %s: model name. */
								esc_html__( 'Using: %s', 'ai-provider-for-codex' ),
								'<strong>' . esc_html( ModelCatalogState::label_for_model_id( $selected_model ) ) . '</strong>'
							);
							?>
						</p>
					<?php endif; ?>
					<div class="codex-models-list">
						<?php foreach ( $model_labels as $label ) : ?>
							<span class="codex-model-pill<?php echo $label === ModelCatalogState::label_for_model_id( $selected_model ) ? ' selected' : ''; ?>">
								<?php echo esc_html( $label ); ?>
							</span>
						<?php endforeach; ?>
					</div>
					<?php if ( 'settings_fallback' === ( $catalog['source'] ?? '' ) ) : ?>
						<p class="description"><?php esc_html_e( 'Using configured defaults — connect an account for live model discovery.', 'ai-provider-for-codex' ); ?></p>
					<?php endif; ?>
					<form method="post" action="<?php echo esc_url( self::page_url() ); ?>" style="margin-top: 1rem;">
						<?php wp_nonce_field( 'codex-provider-set-model' ); ?>
						<input type="hidden" name="codex_provider_action" value="set-model" />
						<label for="codex_provider_model"><strong><?php esc_html_e( 'Choose model', 'ai-provider-for-codex' ); ?></strong></label><br />
						<select id="codex_provider_model" name="codex_provider_model" <?php disabled( [] === $model_ids ); ?>>
							<?php foreach ( $model_ids as $model_id ) : ?>
								<option value="<?php echo esc_attr( (string) $model_id ); ?>" <?php selected( $selected_model, (string) $model_id ); ?>>
									<?php echo esc_html( ModelCatalogState::label_for_model_id( (string) $model_id ) ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<?php submit_button( __( 'Set model', 'ai-provider-for-codex' ), 'secondary', 'submit', false, [ 'style' => 'margin-left: 0.5rem;' ] ); ?>
						<p class="description"><?php esc_html_e( 'This model will be used for all your Codex requests until you change it.', 'ai-provider-for-codex' ); ?></p>
					</form>
				</div>
			<?php endif; ?>

			<p style="margin-top: 1.5rem;">
				<?php if ( empty( $status['runtimeConfigured'] ) ) : ?>
					<a class="button button-secondary" href="<?php echo esc_url( SiteSettings::page_url() ); ?>"><?php esc_html_e( 'Configure local runtime', 'ai-provider-for-codex' ); ?></a>
				<?php elseif ( empty( $status['connection'] ) && ! $pending ) : ?>
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
			$service = new ConnectionService();
			$service->start_connect( get_current_user_id() );
			self::redirect_with_notice( 'connect-started' );
		} catch ( RuntimeException $exception ) {
			self::redirect_with_notice( 'connect-failed', $exception->getMessage() );
		}
	}

	/**
	 * Checks the pending connect status.
	 *
	 * @return void
	 */
	private static function check_connect(): void {
		try {
			$service = new ConnectionService();
			$result  = $service->poll_connect_status( get_current_user_id() );

			if ( 'connected' === (string) ( $result['status'] ?? '' ) ) {
				self::redirect_with_notice( 'connected' );
			}

			if ( 'error' === (string) ( $result['status'] ?? '' ) ) {
				self::redirect_with_notice( 'connect-failed', (string) ( $result['error'] ?? __( 'The local Codex runtime reported a login error.', 'ai-provider-for-codex' ) ) );
			}

			self::redirect_with_notice( 'connect-pending' );
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
	 * Stores the current user's chosen model.
	 *
	 * @return void
	 */
	private static function set_model(): void {
		$wp_user_id = get_current_user_id();
		$model_id   = isset( $_POST['codex_provider_model'] )
			? sanitize_text_field( (string) wp_unslash( $_POST['codex_provider_model'] ) )
			: '';
		$catalog    = ModelCatalogState::get_effective_catalog( $wp_user_id );
		$model_ids  = $catalog['model_ids'];

		if ( '' !== $model_id && ! in_array( $model_id, $model_ids, true ) ) {
			self::redirect_with_notice( 'model-failed', __( 'That model is not available in your current Codex catalog.', 'ai-provider-for-codex' ) );
		}

		ModelCatalogState::update_user_preferred_model( $wp_user_id, $model_id );
		self::redirect_with_notice( 'model-updated' );
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
			case 'connect-started':
				$text = __( 'The local Codex runtime started a device-code login for your account.', 'ai-provider-for-codex' );
				break;
			case 'connect-pending':
				$class = 'notice notice-info';
				$text  = __( 'The login is still pending. Complete the device-code step and check status again.', 'ai-provider-for-codex' );
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
			case 'model-updated':
				$text = __( 'Your model has been updated.', 'ai-provider-for-codex' );
				break;
			case 'connect-failed':
			case 'refresh-failed':
			case 'model-failed':
				$class = 'notice notice-error';
				$text  = '' !== $notice['message'] ? $notice['message'] : __( 'The local Codex runtime request failed.', 'ai-provider-for-codex' );
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
