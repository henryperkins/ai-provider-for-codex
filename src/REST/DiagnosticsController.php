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
		$rows = [];

		try {
			$result = ( new Client() )->diagnostics();
			$rows[] = self::row( 'reachable', __( 'Sidecar reachable', 'scriptorium-ai-provider-for-codex' ), 'pass', '' );
			$rows[] = self::row( 'bearer', __( 'Bearer token matches', 'scriptorium-ai-provider-for-codex' ), 'pass', '' );

			foreach ( (array) ( $result['checks'] ?? [] ) as $check ) {
				if ( ! is_array( $check ) ) {
					continue;
				}
				$rows[] = self::row(
					sanitize_key( (string) ( $check['id'] ?? '' ) ),
					(string) ( $check['label'] ?? '' ),
					self::normalize_status( (string) ( $check['status'] ?? 'fail' ) ),
					(string) ( $check['detail'] ?? '' )
				);
			}

			$ok = (bool) ( $result['ok'] ?? false );
		} catch ( RuntimeRequestException $exception ) {
			$rows[] = self::row( 'reachable', __( 'Sidecar reachable', 'scriptorium-ai-provider-for-codex' ), 'pass', '' );

			if ( 401 === $exception->get_status_code() ) {
				$rows[] = self::row( 'bearer', __( 'Bearer token matches', 'scriptorium-ai-provider-for-codex' ), 'fail', $exception->getMessage() );
			} else {
				$rows[] = self::row( 'bearer', __( 'Bearer token matches', 'scriptorium-ai-provider-for-codex' ), 'warn', $exception->getMessage() );
			}

			$ok = false;
		} catch ( RuntimeException $exception ) {
			$rows[] = self::row( 'reachable', __( 'Sidecar reachable', 'scriptorium-ai-provider-for-codex' ), 'fail', $exception->getMessage() );
			$ok     = false;
		}

		$checked_at = gmdate( 'Y-m-d H:i:s' );
		self::store_verdict( $ok, $checked_at, $rows );

		return new WP_REST_Response(
			[
				'ok'        => $ok,
				'checkedAt' => $checked_at,
				'rows'      => $rows,
				'config'    => self::config_rows(),
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
	 * @return array{id:string,label:string,status:string,detail:string}
	 */
	private static function row( string $id, string $label, string $status, string $detail ): array {
		return [
			'id'     => $id,
			'label'  => $label,
			'status' => $status,
			'detail' => $detail,
		];
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
	 * @return array<int,array{label:string,value:string}>
	 */
	private static function config_rows(): array {
		$meta = Settings::configuration_metadata();

		return [
			[
				'label' => __( 'Runtime URL source', 'scriptorium-ai-provider-for-codex' ),
				'value' => $meta['base_url_source'],
			],
			[
				'label' => __( 'Bearer token source', 'scriptorium-ai-provider-for-codex' ),
				'value' => $meta['bearer_token_source'],
			],
		];
	}

	/**
	 * Stores a compact verdict for the passive settings card. Never touches HealthMonitor.
	 *
	 * @param bool                                                          $ok Overall result.
	 * @param string                                                        $checked_at GMT timestamp.
	 * @param array<int,array{id:string,label:string,status:string,detail:string}> $rows Result rows.
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
