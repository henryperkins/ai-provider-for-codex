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
use AIProviderForCodex\Runtime\Settings;
use RuntimeException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the current user's Codex connection page.
 */
final class UserConnectionPage {

	private const SCRIPT_MODULE_ID = 'scriptorium-ai-provider-for-codex/user-connection';

	private const STYLE_HANDLE = 'codex-provider-user-connection';

	/**
	 * Connection config exposed to the script module after the page renders.
	 *
	 * @var array<string,mixed>|null
	 */
	private static $connection_config = null;

	/**
	 * Registers the page.
	 *
	 * @return void
	 */
	public static function register_page(): void {
		$hook = add_users_page(
			__( 'Codex Provider', 'scriptorium-ai-provider-for-codex' ),
			__( 'Codex Provider', 'scriptorium-ai-provider-for-codex' ),
			'read',
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
	 * Registers and enqueues the enhanced user connection flow assets.
	 *
	 * Screen scoping is handled by the admin_enqueue_scripts closure in
	 * register_page(); release verification calls this directly.
	 *
	 * @return void
	 */
	public static function enqueue_assets(): void {
		wp_register_script_module(
			self::SCRIPT_MODULE_ID,
			plugins_url( 'assets/user-connection.js', \AIProviderForCodex\PLUGIN_FILE ),
			[],
			\AIProviderForCodex\VERSION
		);
		wp_enqueue_script_module( self::SCRIPT_MODULE_ID );

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
		return AdminPageStyle::css();
	}

	/**
	 * Supplies the connection config to the user-connection script module.
	 *
	 * @param array<string,mixed> $data Existing module data.
	 * @return array<string,mixed>
	 */
	public static function script_module_data( array $data ): array {
		if ( null === self::$connection_config ) {
			return $data;
		}

		return array_merge( $data, self::$connection_config );
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
				'id'       => 'codex-provider-how-it-works',
				'title'    => __( 'How Codex Provider works', 'scriptorium-ai-provider-for-codex' ),
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
		$runtime_configured = Settings::has_required_configuration();
		?>
		<ul>
			<li><?php esc_html_e( 'This site uses a local Codex runtime running on the same host as WordPress.', 'scriptorium-ai-provider-for-codex' ); ?></li>
			<li><?php esc_html_e( 'Each person connects their own Codex or ChatGPT account so access and billing stay user-specific.', 'scriptorium-ai-provider-for-codex' ); ?></li>
		</ul>
		<p>
			<?php
			echo wp_kses_post(
				SafeFormat::sprintf(
					/* translators: 1: site settings URL, 2: connectors settings URL. */
					__(
						'This page manages your personal account link. <a href="%1$s">Plugin settings</a> control the local runtime shared by all users. <a href="%2$s">Settings &gt; Connectors</a> shows overall provider status.',
						'scriptorium-ai-provider-for-codex'
					),
					esc_url( SiteSettings::page_url() ),
					esc_url( admin_url( 'options-connectors.php' ) )
				)
			);
			?>
		</p>
		<?php if ( ! $runtime_configured ) : ?>
			<p><strong><?php esc_html_e( 'A site administrator still needs to finish the shared runtime setup before you can connect an account here.', 'scriptorium-ai-provider-for-codex' ); ?></strong></p>
		<?php endif; ?>
		<ol>
			<li><?php esc_html_e( 'A site administrator creates and starts the local sidecar service, then confirms Codex is healthy on Settings > Connectors.', 'scriptorium-ai-provider-for-codex' ); ?></li>
			<li><?php esc_html_e( 'You click Connect Codex account on this page.', 'scriptorium-ai-provider-for-codex' ); ?></li>
			<li><?php esc_html_e( 'WordPress opens the verification page, keeps the device code visible, and checks automatically while you return to this tab after approval.', 'scriptorium-ai-provider-for-codex' ); ?></li>
		</ol>
		<?php
	}

	/**
	 * Returns the page URL.
	 *
	 * @return string
	 */
	public static function page_url(): string {
		return admin_url( 'users.php?page=' . \AIProviderForCodex\SLUG );
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

		if ( \AIProviderForCodex\SLUG !== $page ) {
			return;
		}

		$post_action = isset( $_POST['codex_provider_action'] ) ? sanitize_key( wp_unslash( $_POST['codex_provider_action'] ) ) : '';

		if ( 'set-model' === $post_action ) {
			check_admin_referer( 'codex-provider-set-model' );
			$model_id = isset( $_POST['codex_provider_model'] )
				? sanitize_text_field( (string) wp_unslash( $_POST['codex_provider_model'] ) )
				: '';
			self::set_model( $model_id );
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
		$pending_status = is_array( $pending ) ? sanitize_key( (string) ( $pending['status'] ?? 'pending' ) ) : '';
		$pending_active = in_array( $pending_status, [ 'pending', 'completed' ], true );
		$ind            = StatusLabels::status_indicator( $reason );
		$reason_label   = StatusLabels::readiness_label( $reason );
		$guidance       = StatusLabels::readiness_guidance( $reason );
		$model_labels   = ModelCatalogState::labels_from_catalog( $catalog );
		$model_ids      = is_array( $catalog['model_ids'] ?? null ) ? $catalog['model_ids'] : [];
		$selected_model = (string) ( $catalog['selected_model'] ?? '' );
		$connection_config = [
			'pageUrl'           => self::page_url(),
			'startUrl'          => rest_url( 'codex-provider/v1/connect/start' ),
			'connectStatusUrl'  => rest_url( 'codex-provider/v1/connect/status' ),
			'providerStatusUrl' => rest_url( 'codex-provider/v1/status' ),
			'restNonce'         => wp_create_nonce( 'wp_rest' ),
			'currentPending'    => $pending && ! empty( $pending['authSessionId'] )
				? [
					'authSessionId'   => (string) $pending['authSessionId'],
					'status'          => $pending_status,
					'verificationUrl' => (string) ( $pending['verificationUrl'] ?? '' ),
					'userCode'        => (string) ( $pending['userCode'] ?? '' ),
					'error'           => (string) ( $pending['error'] ?? '' ),
				]
				: null,
			'text'              => [
				'heading'          => __( 'Complete account connection', 'scriptorium-ai-provider-for-codex' ),
				'connectedHeading' => __( 'Codex account connected', 'scriptorium-ai-provider-for-codex' ),
				'syncRetryHeading' => __( 'Retry account sync', 'scriptorium-ai-provider-for-codex' ),
				'failedHeading'    => __( 'Connection needs attention', 'scriptorium-ai-provider-for-codex' ),
				'starting'         => __( 'Starting Codex login...', 'scriptorium-ai-provider-for-codex' ),
				'pending'          => __( 'Waiting for ChatGPT approval...', 'scriptorium-ai-provider-for-codex' ),
				'copied'           => __( 'Code copied.', 'scriptorium-ai-provider-for-codex' ),
				'copyFailed'       => __( 'Copy did not work in this browser. Select the code below.', 'scriptorium-ai-provider-for-codex' ),
				'popupBlocked'     => __( 'Your browser blocked the verification tab. Open it manually.', 'scriptorium-ai-provider-for-codex' ),
				'connected'        => __( 'Your Codex account is connected.', 'scriptorium-ai-provider-for-codex' ),
				'syncRetry'        => __( 'Your login was approved, but WordPress could not sync your Codex account yet.', 'scriptorium-ai-provider-for-codex' ),
				'missing'          => __( 'The local runtime no longer has this login session. Start again to get a fresh code.', 'scriptorium-ai-provider-for-codex' ),
				'timedOut'         => __( 'Still waiting. You can check again or start over.', 'scriptorium-ai-provider-for-codex' ),
				'failed'           => __( 'The local Codex runtime request failed.', 'scriptorium-ai-provider-for-codex' ),
			],
		];

		self::$connection_config = $connection_config;

		if ( 'completed' === $pending_status ) {
			$ind          = 'warning';
			$reason_label = __( 'Account sync needs retry', 'scriptorium-ai-provider-for-codex' );
			$guidance     = __( 'Device-code login already completed, but WordPress could not refresh your Codex account details yet. Retry the account sync below.', 'scriptorium-ai-provider-for-codex' );
		}
		?>
		<div class="wrap codex-provider-admin-page">
			<div class="codex-provider-shell" data-codex-connection-root>
				<header class="codex-provider-page-header">
					<h1><?php esc_html_e( 'Codex Provider', 'scriptorium-ai-provider-for-codex' ); ?></h1>
					<p class="codex-provider-page-subtitle"><?php esc_html_e( 'Connect your Codex or ChatGPT account and choose the model used for your requests.', 'scriptorium-ai-provider-for-codex' ); ?></p>
				</header>

				<?php self::render_notice( $notice ); ?>

				<div class="codex-provider-stack">
					<section class="codex-provider-card codex-provider-card--connection-console codex-device-box" data-codex-connection-console hidden>
						<h2 class="codex-provider-card__title" data-codex-connection-heading><?php esc_html_e( 'Complete account connection', 'scriptorium-ai-provider-for-codex' ); ?></h2>
						<p data-codex-connection-status aria-live="polite"></p>
						<p class="codex-device-code" data-codex-connection-code hidden></p>
						<p data-codex-code-actions hidden>
							<button type="button" class="button button-secondary" data-codex-copy-code><?php esc_html_e( 'Copy code', 'scriptorium-ai-provider-for-codex' ); ?></button>
							<a class="button button-secondary" data-codex-open-verification href="#" target="_blank" rel="noopener noreferrer" hidden><?php esc_html_e( 'Open verification page', 'scriptorium-ai-provider-for-codex' ); ?></a>
							<a class="button button-secondary" data-codex-check-status href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'action', 'check-connect', self::page_url() ), 'codex-provider-check-connect' ) ); ?>" hidden><?php esc_html_e( 'Check connection status', 'scriptorium-ai-provider-for-codex' ); ?></a>
						</p>
						<p class="description" data-codex-terminal-text aria-live="polite" hidden></p>
						<dl data-codex-connected-details hidden>
							<dt><?php esc_html_e( 'Account email', 'scriptorium-ai-provider-for-codex' ); ?></dt>
							<dd data-codex-connected-email></dd>
							<dt><?php esc_html_e( 'Plan type', 'scriptorium-ai-provider-for-codex' ); ?></dt>
							<dd data-codex-connected-plan></dd>
							<dt><?php esc_html_e( 'Selected model', 'scriptorium-ai-provider-for-codex' ); ?></dt>
							<dd data-codex-connected-model></dd>
						</dl>
						<p data-codex-connected-actions hidden>
							<a class="button button-secondary" data-codex-refresh-status href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'action', 'refresh-status', self::page_url() ), 'codex-provider-refresh-status' ) ); ?>" hidden><?php esc_html_e( 'Refresh status', 'scriptorium-ai-provider-for-codex' ); ?></a>
							<a class="button button-link-delete" data-codex-disconnect href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'action', 'disconnect', self::page_url() ), 'codex-provider-disconnect' ) ); ?>" hidden><?php esc_html_e( 'Disconnect Codex account', 'scriptorium-ai-provider-for-codex' ); ?></a>
						</p>
						<p data-codex-terminal-actions hidden>
							<a class="button button-primary" data-codex-retry-sync href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'action', 'refresh-status', self::page_url() ), 'codex-provider-refresh-status' ) ); ?>" hidden><?php esc_html_e( 'Retry account sync', 'scriptorium-ai-provider-for-codex' ); ?></a>
							<a class="button button-primary" data-codex-start-connect data-codex-start-again href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'action', 'start-connect', self::page_url() ), 'codex-provider-start-connect' ) ); ?>" hidden><?php esc_html_e( 'Start connection again', 'scriptorium-ai-provider-for-codex' ); ?></a>
						</p>
					</section>

					<section class="codex-provider-card codex-provider-card--account">
						<div class="codex-provider-card__header">
							<div class="codex-provider-card__identity">
								<span class="codex-provider-card__icon" aria-hidden="true">C</span>
								<div>
									<h2 class="codex-provider-card__title"><?php esc_html_e( 'Account', 'scriptorium-ai-provider-for-codex' ); ?></h2>
									<p class="codex-provider-card__description"><?php esc_html_e( 'Personal Codex connection for this WordPress user.', 'scriptorium-ai-provider-for-codex' ); ?></p>
								</div>
							</div>
							<div class="codex-provider-card__action">
								<span class="codex-provider-badge<?php echo esc_attr( 'good' === $ind ? '' : ( 'warning' === $ind ? ' is-warning' : ' is-error' ) ); ?>">
									<?php echo esc_html( $reason_label ); ?>
								</span>
							</div>
						</div>
						<div class="codex-provider-card__body">
							<p class="codex-provider-status-line">
								<span class="codex-indicator <?php echo esc_attr( $ind ); ?>"></span>
								<span data-codex-connection-status aria-live="polite"><?php echo esc_html( $reason_label ); ?></span>
							</p>
							<?php if ( '' !== $guidance ) : ?>
								<p class="codex-provider-guidance"><?php echo esc_html( $guidance ); ?></p>
							<?php endif; ?>
							<dl class="codex-provider-meta-list">
								<dt><?php esc_html_e( 'Runtime configured', 'scriptorium-ai-provider-for-codex' ); ?></dt>
								<dd><?php echo ! empty( $status['runtimeConfigured'] ) ? esc_html__( 'Yes', 'scriptorium-ai-provider-for-codex' ) : esc_html__( 'No', 'scriptorium-ai-provider-for-codex' ); ?></dd>
								<?php if ( ! empty( $status['connection'] ) ) : ?>
									<dt><?php esc_html_e( 'Connection ID', 'scriptorium-ai-provider-for-codex' ); ?></dt>
									<dd><code><?php echo esc_html( (string) $status['connection']['connection_id'] ); ?></code></dd>
									<dt><?php esc_html_e( 'Account email', 'scriptorium-ai-provider-for-codex' ); ?></dt>
									<dd><?php echo esc_html( (string) $status['connection']['account_email'] ); ?></dd>
									<dt><?php esc_html_e( 'Plan type', 'scriptorium-ai-provider-for-codex' ); ?></dt>
									<dd><?php echo esc_html( (string) $status['connection']['plan_type'] ); ?></dd>
								<?php endif; ?>
							</dl>
							<div class="codex-provider-actions" data-codex-base-actions>
								<?php if ( empty( $status['runtimeConfigured'] ) ) : ?>
									<a class="button button-secondary" href="<?php echo esc_url( SiteSettings::page_url() ); ?>"><?php esc_html_e( 'Configure local runtime', 'scriptorium-ai-provider-for-codex' ); ?></a>
								<?php elseif ( 'error' === $pending_status ) : ?>
									<a class="button button-primary" data-codex-start-connect href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'action', 'start-connect', self::page_url() ), 'codex-provider-start-connect' ) ); ?>"><?php esc_html_e( 'Start connection again', 'scriptorium-ai-provider-for-codex' ); ?></a>
								<?php elseif ( empty( $status['connection'] ) && ! $pending_active ) : ?>
									<a class="button button-primary" data-codex-start-connect href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'action', 'start-connect', self::page_url() ), 'codex-provider-start-connect' ) ); ?>"><?php esc_html_e( 'Connect Codex account', 'scriptorium-ai-provider-for-codex' ); ?></a>
								<?php else : ?>
									<a class="button button-secondary" href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'action', 'refresh-status', self::page_url() ), 'codex-provider-refresh-status' ) ); ?>"><?php echo esc_html( 'completed' === $pending_status ? __( 'Retry account sync', 'scriptorium-ai-provider-for-codex' ) : __( 'Refresh status', 'scriptorium-ai-provider-for-codex' ) ); ?></a>
									<a class="button button-link-delete" href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'action', 'disconnect', self::page_url() ), 'codex-provider-disconnect' ) ); ?>"><?php esc_html_e( 'Disconnect Codex account', 'scriptorium-ai-provider-for-codex' ); ?></a>
								<?php endif; ?>
							</div>
						</div>
					</section>

					<?php if ( $pending && ! empty( $pending['authSessionId'] ) ) : ?>
						<section class="codex-provider-card codex-provider-card--connection-fallback codex-device-box" data-codex-server-fallback>
							<?php if ( 'pending' === $pending_status ) : ?>
								<h2 class="codex-provider-card__title"><?php esc_html_e( 'Complete account connection', 'scriptorium-ai-provider-for-codex' ); ?></h2>
								<p><?php esc_html_e( 'Finish the connection in three quick steps:', 'scriptorium-ai-provider-for-codex' ); ?></p>
								<ol>
									<li><?php esc_html_e( 'Open the verification page.', 'scriptorium-ai-provider-for-codex' ); ?></li>
									<li><?php esc_html_e( 'Enter this device code.', 'scriptorium-ai-provider-for-codex' ); ?></li>
									<li><?php esc_html_e( 'Return to this tab after approving. WordPress will keep checking automatically, and the Check status button remains available.', 'scriptorium-ai-provider-for-codex' ); ?></li>
								</ol>
								<p class="codex-device-code" data-codex-connection-code><?php echo esc_html( (string) $pending['userCode'] ); ?></p>
								<p class="codex-provider-actions">
									<button type="button" class="button button-secondary" data-codex-copy-code><?php esc_html_e( 'Copy code', 'scriptorium-ai-provider-for-codex' ); ?></button>
									<a class="button button-secondary" data-codex-open-verification href="<?php echo esc_url( (string) $pending['verificationUrl'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open verification page', 'scriptorium-ai-provider-for-codex' ); ?></a>
									<a class="button button-primary" data-codex-check-status href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'action', 'check-connect', self::page_url() ), 'codex-provider-check-connect' ) ); ?>"><?php esc_html_e( 'Check connection status', 'scriptorium-ai-provider-for-codex' ); ?></a>
								</p>
								<p class="description" data-codex-terminal-text aria-live="polite" hidden></p>
								<?php if ( ! empty( $pending['error'] ) ) : ?>
									<p class="description codex-provider-error"><?php echo esc_html( (string) $pending['error'] ); ?></p>
								<?php endif; ?>
							<?php elseif ( 'completed' === $pending_status ) : ?>
								<h2 class="codex-provider-card__title"><?php esc_html_e( 'Retry account sync', 'scriptorium-ai-provider-for-codex' ); ?></h2>
								<p><?php esc_html_e( 'Your device-code login finished, but WordPress could not finish syncing your Codex account yet.', 'scriptorium-ai-provider-for-codex' ); ?></p>
								<ol>
									<li><?php esc_html_e( 'Confirm the local sidecar is still running.', 'scriptorium-ai-provider-for-codex' ); ?></li>
									<li><?php esc_html_e( 'Click Retry account sync below.', 'scriptorium-ai-provider-for-codex' ); ?></li>
									<li><?php esc_html_e( 'If it still fails, disconnect and start the connection again.', 'scriptorium-ai-provider-for-codex' ); ?></li>
								</ol>
								<?php if ( ! empty( $pending['error'] ) ) : ?>
									<p class="description codex-provider-error"><?php echo esc_html( (string) $pending['error'] ); ?></p>
								<?php endif; ?>
							<?php elseif ( 'error' === $pending_status ) : ?>
								<h2 class="codex-provider-card__title"><?php esc_html_e( 'Connection attempt failed', 'scriptorium-ai-provider-for-codex' ); ?></h2>
								<p><?php esc_html_e( 'The previous device-code login did not finish successfully.', 'scriptorium-ai-provider-for-codex' ); ?></p>
								<ol>
									<li><?php esc_html_e( 'Review the error below.', 'scriptorium-ai-provider-for-codex' ); ?></li>
									<li><?php esc_html_e( 'Click Start connection again to request a fresh device code.', 'scriptorium-ai-provider-for-codex' ); ?></li>
									<li><?php esc_html_e( 'Complete the new verification flow from the beginning.', 'scriptorium-ai-provider-for-codex' ); ?></li>
								</ol>
								<?php if ( ! empty( $pending['error'] ) ) : ?>
									<p class="description codex-provider-error"><?php echo esc_html( (string) $pending['error'] ); ?></p>
								<?php endif; ?>
							<?php endif; ?>
						</section>
					<?php endif; ?>

					<?php if ( ! empty( $model_labels ) ) : ?>
						<section class="codex-provider-card codex-provider-card--model">
							<div class="codex-provider-card__header">
								<div class="codex-provider-card__identity">
									<span class="codex-provider-card__icon" aria-hidden="true">M</span>
									<div>
										<h2 class="codex-provider-card__title"><?php esc_html_e( 'Model', 'scriptorium-ai-provider-for-codex' ); ?></h2>
										<p class="codex-provider-card__description"><?php esc_html_e( 'Text model used for your Codex requests.', 'scriptorium-ai-provider-for-codex' ); ?></p>
									</div>
								</div>
							</div>
							<div class="codex-provider-card__body">
								<?php if ( '' !== $selected_model ) : ?>
									<p>
										<?php
										echo wp_kses(
											SafeFormat::sprintf(
												/* translators: %s: model name. */
												__( 'Using: %s', 'scriptorium-ai-provider-for-codex' ),
												'<strong>' . esc_html( ModelCatalogState::label_for_model_id( $selected_model ) ) . '</strong>'
											),
											[ 'strong' => [] ]
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
									<p class="description"><?php esc_html_e( 'Using configured defaults — connect an account for live model discovery.', 'scriptorium-ai-provider-for-codex' ); ?></p>
								<?php endif; ?>
								<form class="codex-provider-field" method="post" action="<?php echo esc_url( self::page_url() ); ?>">
									<?php wp_nonce_field( 'codex-provider-set-model' ); ?>
									<input type="hidden" name="codex_provider_action" value="set-model" />
									<label for="codex_provider_model"><strong><?php esc_html_e( 'Choose model', 'scriptorium-ai-provider-for-codex' ); ?></strong></label><br />
									<select id="codex_provider_model" name="codex_provider_model" <?php disabled( [] === $model_ids ); ?>>
										<?php foreach ( $model_ids as $model_id ) : ?>
											<option value="<?php echo esc_attr( (string) $model_id ); ?>" <?php selected( $selected_model, (string) $model_id ); ?>>
												<?php echo esc_html( ModelCatalogState::label_for_model_id( (string) $model_id ) ); ?>
											</option>
										<?php endforeach; ?>
									</select>
									<span class="codex-provider-actions">
										<?php submit_button( __( 'Set model', 'scriptorium-ai-provider-for-codex' ), 'secondary', 'submit', false ); ?>
									</span>
									<p class="description"><?php esc_html_e( 'This model will be used for all your Codex requests until you change it.', 'scriptorium-ai-provider-for-codex' ); ?></p>
								</form>
							</div>
						</section>
					<?php endif; ?>
				</div>
			</div>
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

			if ( 'missing' === (string) ( $result['status'] ?? '' ) ) {
				self::redirect_with_notice( 'connect-missing', (string) ( $result['error'] ?? '' ) );
			}

			if ( 'error' === (string) ( $result['status'] ?? '' ) ) {
				self::redirect_with_notice( 'connect-failed', (string) ( $result['error'] ?? __( 'The local Codex runtime reported a login error.', 'scriptorium-ai-provider-for-codex' ) ) );
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
		self::redirect_with_notice( 'disconnected' );
	}

	/**
	 * Stores the current user's chosen model.
	 *
	 * @return void
	 */
	private static function set_model( string $model_id ): void {
		$wp_user_id = get_current_user_id();
		$catalog    = ModelCatalogState::get_effective_catalog( $wp_user_id );
		$model_ids  = $catalog['model_ids'];

		if ( '' !== $model_id && ! in_array( $model_id, $model_ids, true ) ) {
			self::redirect_with_notice( 'model-failed', __( 'That model is not available in your current Codex catalog.', 'scriptorium-ai-provider-for-codex' ) );
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
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reads display-only notice query args added by this screen's redirects.
		$code    = isset( $_GET['codex_provider_notice'] ) ? sanitize_key( wp_unslash( $_GET['codex_provider_notice'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reads display-only notice query args added by this screen's redirects.
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
				$text = __( 'The local Codex runtime started a device-code login for your account.', 'scriptorium-ai-provider-for-codex' );
				break;
			case 'connect-pending':
				$class = 'notice notice-info';
				$text  = __( 'The login is still pending. Complete the device-code step and check status again.', 'scriptorium-ai-provider-for-codex' );
				break;
			case 'connect-missing':
				$class = 'notice notice-warning';
				$text  = '' !== $notice['message']
					? $notice['message']
					: __( 'The previous device-code session is no longer available in the local runtime. Start the connection again to finish linking your account.', 'scriptorium-ai-provider-for-codex' );
				break;
			case 'connected':
				$text = __( 'Your Codex account is now linked.', 'scriptorium-ai-provider-for-codex' );
				break;
			case 'disconnected':
				$text = __( 'Your local Codex link has been removed.', 'scriptorium-ai-provider-for-codex' );
				break;
			case 'status-refreshed':
				$text = __( 'The local Codex snapshot was refreshed.', 'scriptorium-ai-provider-for-codex' );
				break;
			case 'model-updated':
				$text = __( 'Your model has been updated.', 'scriptorium-ai-provider-for-codex' );
				break;
			case 'connect-failed':
			case 'refresh-failed':
			case 'model-failed':
				$class = 'notice notice-error';
				$text  = '' !== $notice['message'] ? $notice['message'] : __( 'The local Codex runtime request failed.', 'scriptorium-ai-provider-for-codex' );
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
