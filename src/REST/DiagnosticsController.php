<?php
/**
 * Read-only runtime diagnostics REST endpoint.
 *
 * @package AIProviderForCodex
 */

declare( strict_types=1 );

namespace AIProviderForCodex\REST;

use AIProviderForCodex\Runtime\Client;
use AIProviderForCodex\Runtime\RuntimeRequestException;
use AIProviderForCodex\Runtime\Settings;
use RuntimeException;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs an explicit, admin-triggered diagnostic against the local runtime.
 */
final class DiagnosticsController {

	private const VERDICT_TRANSIENT = 'codex_provider_last_diagnostics';

	/**
	 * Registers routes.
	 *
	 * @return void
	 */
	public static function register_routes(): void {
		register_rest_route(
			'codex-provider/v1',
			'/diagnostics',
			[
				'methods'             => 'POST',
				'permission_callback' => [ self::class, 'can_run' ],
				'callback'            => [ self::class, 'run' ],
			]
		);
	}

	/**
	 * Only administrators may run diagnostics (it exposes host paths and spawns a process).
	 *
	 * @return bool
	 */
	public static function can_run(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Runs the diagnostic and returns composed rows.
	 *
	 * @return WP_REST_Response
	 */
	public static function run(): WP_REST_Response {
		$rows    = [];
		$version = '';

		try {
			$result  = ( new Client() )->diagnostics();
			$version = (string) ( $result['version'] ?? '' );
			$rows[]  = self::row( 'reachable', __( 'Sidecar reachable', 'scriptorium-ai-provider-for-codex' ), 'pass', '' );
			$rows[]  = self::row( 'bearer', __( 'Bearer token matches', 'scriptorium-ai-provider-for-codex' ), 'pass', '' );

			foreach ( (array) ( $result['checks'] ?? [] ) as $check ) {
				if ( ! is_array( $check ) ) {
					continue;
				}

				$id     = sanitize_key( (string) ( $check['id'] ?? '' ) );
				$status = self::normalize_status( (string) ( $check['status'] ?? 'fail' ) );
				$rows[] = self::row(
					$id,
					(string) ( $check['label'] ?? '' ),
					$status,
					(string) ( $check['detail'] ?? '' ),
					'pass' === $status ? '' : self::remediation_for_check( $id )
				);
			}

			$ok = (bool) ( $result['ok'] ?? false );
		} catch ( RuntimeRequestException $exception ) {
			// A structured error means the sidecar answered, so it is reachable;
			// the failure is in the response and is classified by status + code.
			$rows[] = self::row( 'reachable', __( 'Sidecar reachable', 'scriptorium-ai-provider-for-codex' ), 'pass', '' );
			$rows[] = self::runtime_error_row( $exception );
			$ok     = false;
		} catch ( RuntimeException $exception ) {
			$rows[] = self::row(
				'reachable',
				__( 'Sidecar reachable', 'scriptorium-ai-provider-for-codex' ),
				'fail',
				$exception->getMessage(),
				self::remediation_for_transport( $exception->getMessage() )
			);
			$ok = false;
		}

		$checked_at = gmdate( 'Y-m-d H:i:s' );
		self::store_verdict( $ok, $checked_at, $rows );

		return new WP_REST_Response(
			[
				'ok'        => $ok,
				'checkedAt' => $checked_at,
				'rows'      => $rows,
				'config'    => self::config_rows( $version ),
			]
		);
	}

	/**
	 * Builds a single result row.
	 *
	 * @param string $id Row ID.
	 * @param string $label Row label.
	 * @param string $status pass|warn|fail.
	 * @param string $detail Detail text.
	 * @param string $remediation Optional next step the admin can take to clear the issue.
	 * @return array{id:string,label:string,status:string,detail:string,remediation:string}
	 */
	private static function row( string $id, string $label, string $status, string $detail, string $remediation = '' ): array {
		return [
			'id'          => $id,
			'label'       => $label,
			'status'      => $status,
			'detail'      => $detail,
			'remediation' => $remediation,
		];
	}

	/**
	 * Maps a structured runtime error to the correct, separately-labelled row.
	 *
	 * A non-2xx response means the sidecar replied but the request did not
	 * succeed. The cause is encoded in the HTTP status and the sidecar error
	 * code, so each class gets its own row and remediation instead of being
	 * blanket-attributed to the bearer token.
	 *
	 * @param RuntimeRequestException $exception Structured runtime failure.
	 * @return array{id:string,label:string,status:string,detail:string,remediation:string}
	 */
	private static function runtime_error_row( RuntimeRequestException $exception ): array {
		$status = $exception->get_status_code();
		$code   = $exception->get_runtime_error_code();
		$detail = $exception->get_runtime_message();

		if ( '' === $detail ) {
			$detail = $exception->getMessage();
		}

		if ( 401 === $status || 'unauthorized' === $code ) {
			return self::row(
				'bearer',
				__( 'Bearer token matches', 'scriptorium-ai-provider-for-codex' ),
				'fail',
				$detail,
				__( 'The sidecar rejected the bearer token. Make sure the token under Settings → Codex Provider exactly matches CODEX_WP_BEARER_TOKEN in the sidecar environment file, then run the check again.', 'scriptorium-ai-provider-for-codex' )
			);
		}

		if ( 404 === $status || 'not_found' === $code ) {
			return self::row(
				'endpoint',
				__( 'Diagnostics endpoint available', 'scriptorium-ai-provider-for-codex' ),
				'fail',
				$detail,
				__( 'The sidecar is reachable but does not serve /v1/diagnostics, so it is running an older build. Restart the sidecar service to load the current code, e.g. "sudo systemctl restart codex-wp-sidecar".', 'scriptorium-ai-provider-for-codex' )
			);
		}

		return self::row(
			'sidecar_error',
			__( 'Sidecar response', 'scriptorium-ai-provider-for-codex' ),
			'warn',
			$detail,
			__( 'The sidecar returned an unexpected error. Check the sidecar service logs for details, e.g. "journalctl -u codex-wp-sidecar -e".', 'scriptorium-ai-provider-for-codex' )
		);
	}

	/**
	 * Returns the remediation text for a failing sidecar check.
	 *
	 * @param string $id Check ID as reported by the sidecar.
	 * @return string
	 */
	private static function remediation_for_check( string $id ): string {
		switch ( $id ) {
			case 'python_version':
				return __( 'Upgrade the Python interpreter used by the sidecar service to 3.11 or newer, then restart it.', 'scriptorium-ai-provider-for-codex' );
			case 'codex_cli':
				return __( 'Install the Codex CLI and point CODEX_BIN at it in the sidecar environment, then restart the sidecar.', 'scriptorium-ai-provider-for-codex' );
			case 'storage_root':
				return __( 'Make the storage root writable by the sidecar service user (check CODEX_WP_STORAGE_ROOT ownership and permissions).', 'scriptorium-ai-provider-for-codex' );
			case 'app_server':
				return __( 'The Codex CLI could not start its app-server. Verify the codex binary runs, then review the sidecar logs, e.g. "journalctl -u codex-wp-sidecar -e".', 'scriptorium-ai-provider-for-codex' );
			default:
				return '';
		}
	}

	/**
	 * Returns remediation text for a transport-level failure when the cause is clear.
	 *
	 * @param string $message Normalized transport error message.
	 * @return string
	 */
	private static function remediation_for_transport( string $message ): string {
		if (
			false !== stripos( $message, 'refused' )
			|| false !== stripos( $message, 'failed to connect' )
			|| false !== stripos( $message, 'not configured' )
			|| false !== stripos( $message, 'timed out' )
		) {
			return __( 'Make sure the sidecar service is running and the runtime URL is correct, e.g. "sudo systemctl status codex-wp-sidecar".', 'scriptorium-ai-provider-for-codex' );
		}

		return '';
	}

	/**
	 * Clamps a sidecar status to the known vocabulary.
	 *
	 * @param string $status Raw status.
	 * @return string
	 */
	private static function normalize_status( string $status ): string {
		return in_array( $status, [ 'pass', 'warn', 'fail' ], true ) ? $status : 'fail';
	}

	/**
	 * Returns the resolved-configuration info rows.
	 *
	 * @param string $version Running sidecar version, when known.
	 * @return array<int,array{label:string,value:string}>
	 */
	private static function config_rows( string $version = '' ): array {
		$meta = Settings::configuration_metadata();

		$rows = [
			[
				'label' => __( 'Runtime URL source', 'scriptorium-ai-provider-for-codex' ),
				'value' => $meta['base_url_source'],
			],
			[
				'label' => __( 'Bearer token source', 'scriptorium-ai-provider-for-codex' ),
				'value' => $meta['bearer_token_source'],
			],
		];

		if ( '' !== $version ) {
			$rows[] = [
				'label' => __( 'Sidecar version', 'scriptorium-ai-provider-for-codex' ),
				'value' => $version,
			];
		}

		return $rows;
	}

	/**
	 * Stores a compact verdict for the passive settings card. Never touches HealthMonitor.
	 *
	 * @param bool                                                          $ok Overall result.
	 * @param string                                                        $checked_at GMT timestamp.
	 * @param array<int,array{id:string,label:string,status:string,detail:string,remediation:string}> $rows Result rows.
	 * @return void
	 */
	private static function store_verdict( bool $ok, string $checked_at, array $rows ): void {
		$failed = [];
		foreach ( $rows as $row ) {
			// Count anything that is not a clean pass (warn included) so a
			// non-fatal problem is never summarised on the settings card as
			// "0 issues" while the overall verdict is not ok.
			if ( 'pass' !== $row['status'] ) {
				$failed[] = $row['label'];
			}
		}

		set_transient(
			self::VERDICT_TRANSIENT,
			[
				'checked_at' => $checked_at,
				'ok'         => $ok,
				'failed'     => $failed,
			],
			HOUR_IN_SECONDS
		);
	}
}
