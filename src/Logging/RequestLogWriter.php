<?php
/**
 * Best-effort bridge that records Codex generations into the WordPress AI
 * plugin's "AI Request Logging" experiment.
 *
 * @package HtperkinsAIProviderForCodex
 */

declare( strict_types=1 );

namespace Htperkins\AIProviderForCodex\Logging;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Maps a Codex generation outcome to the AI plugin's request-log shape and
 * writes it through that plugin's log manager when the experiment is active.
 *
 * The codex provider reaches its local runtime over its own loopback client and
 * therefore never passes through the SDK HTTP transporter that the experiment
 * decorates — so its requests are invisible to the Request Log. This bridge
 * re-adds codex calls (successes *and* failures) from the provider's own call
 * site. Writing is always best-effort: it never throws and never disturbs
 * generation.
 */
final class RequestLogWriter {

	/**
	 * Request-log "type" column value.
	 */
	private const TYPE = 'text';

	/**
	 * Request-log "operation" column value.
	 */
	private const OPERATION = 'codex:responses/text';

	/**
	 * Request-log "provider" column value.
	 */
	private const PROVIDER = 'codex';

	/**
	 * Maximum characters stored for a request/response preview.
	 */
	private const PREVIEW_LIMIT = 2000;

	/**
	 * Fully-qualified AI-plugin log manager class.
	 */
	private const MANAGER_CLASS = 'WordPress\\AI\\Logging\\AI_Request_Log_Manager';

	/**
	 * Builds an AI-plugin request-log payload from a generation outcome.
	 *
	 * Pure: performs no WordPress or AI-plugin calls, so it is unit-testable in
	 * isolation. Absent optional values are omitted rather than written as
	 * empty/zero so the Request Log stays clean.
	 *
	 * @param array{
	 *     status: string,
	 *     type?: string,
	 *     operation?: string,
	 *     model?: string,
	 *     duration_ms?: int,
	 *     tokens_input?: int,
	 *     tokens_output?: int,
	 *     error_message?: string,
	 *     user_id?: int,
	 *     request_id?: string,
	 *     input_preview?: string,
	 *     output_preview?: string
	 * } $args Generation outcome.
	 * @return array<string,mixed> Payload accepted by AI_Request_Log_Manager::log().
	 */
	public static function build_entry( array $args ): array {
		$status    = $args['status'];
		$type      = isset( $args['type'] ) && '' !== (string) $args['type'] ? (string) $args['type'] : self::TYPE;
		$operation = isset( $args['operation'] ) && '' !== (string) $args['operation'] ? (string) $args['operation'] : self::OPERATION;

		$context = array( 'surface' => 'wordpress-ai-client' );

		if ( isset( $args['request_id'] ) && '' !== (string) $args['request_id'] ) {
			$context['request_id'] = (string) $args['request_id'];
		}

		if ( isset( $args['input_preview'] ) && '' !== (string) $args['input_preview'] ) {
			$context['input_preview'] = self::preview( (string) $args['input_preview'] );
		}

		if ( isset( $args['output_preview'] ) && '' !== (string) $args['output_preview'] ) {
			$context['output_preview'] = self::preview( (string) $args['output_preview'] );
		}

		$entry = array(
			'type'      => $type,
			'operation' => $operation,
			'provider'  => self::PROVIDER,
			'status'    => $status,
			'context'   => $context,
		);

		if ( isset( $args['model'] ) && '' !== (string) $args['model'] ) {
			$entry['model'] = (string) $args['model'];
		}

		if ( isset( $args['duration_ms'] ) ) {
			$entry['duration_ms'] = max( 0, (int) $args['duration_ms'] );
		}

		$tokens_input = (int) ( $args['tokens_input'] ?? 0 );
		if ( $tokens_input > 0 ) {
			$entry['tokens_input'] = $tokens_input;
		}

		$tokens_output = (int) ( $args['tokens_output'] ?? 0 );
		if ( $tokens_output > 0 ) {
			$entry['tokens_output'] = $tokens_output;
		}

		if ( 'error' === $status && isset( $args['error_message'] ) && '' !== (string) $args['error_message'] ) {
			$entry['error_message'] = (string) $args['error_message'];
		}

		$user_id = (int) ( $args['user_id'] ?? 0 );
		if ( $user_id > 0 ) {
			$entry['user_id'] = $user_id;
		}

		return $entry;
	}

	/**
	 * Records a log entry on a best-effort basis.
	 *
	 * Never throws and never disturbs generation: any failure to write is
	 * swallowed. Returns whether the entry was handed to a sink successfully.
	 *
	 * @param array<string,mixed> $entry Payload from build_entry().
	 * @param callable|null       $sink  Optional writer `fn(array): void`; resolved when null.
	 * @return bool True when an entry was written, false when skipped or failed.
	 */
	public static function record( array $entry, ?callable $sink = null ): bool {
		try {
			if ( null === $sink ) {
				$sink = self::resolve_sink();
			}

			if ( null === $sink ) {
				return false;
			}

			$sink( $entry );

			return true;
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Whether the AI plugin's Request Logging experiment is present and enabled.
	 *
	 * Mirrors the AI plugin's own gate (global toggle + per-feature toggle) and
	 * fails closed: if either option is off or the manager class is absent, no
	 * codex entries are written.
	 *
	 * @return bool
	 */
	public static function is_logging_enabled(): bool {
		if ( ! class_exists( self::MANAGER_CLASS ) ) {
			return false;
		}

		if ( ! (bool) get_option( 'wpai_features_enabled', false ) ) {
			return false;
		}

		return (bool) get_option( 'wpai_feature_ai-request-logging_enabled', false );
	}

	/**
	 * Resolves the sink that performs the actual write, or null when logging is
	 * unavailable.
	 *
	 * @return callable|null
	 */
	private static function resolve_sink(): ?callable {
		$default = self::is_logging_enabled() ? self::default_sink() : null;

		/**
		 * Filters the sink used to record Codex request-log entries.
		 *
		 * Return a callable `fn(array $entry): void` to redirect or observe
		 * Codex log writes, or null to disable them.
		 *
		 * @param callable|null $default Default sink (AI-plugin log manager), or null when unavailable.
		 */
		$sink = apply_filters( 'htperkins_aipfc_request_log_sink', $default );

		return is_callable( $sink ) ? $sink : null;
	}

	/**
	 * Default sink: writes through the AI plugin's log manager.
	 *
	 * @return callable
	 */
	private static function default_sink(): callable {
		return static function ( array $entry ): void {
			$manager_class = self::MANAGER_CLASS;
			$manager       = new $manager_class();

			$manager->log( $entry );
		};
	}

	/**
	 * Truncates a preview string to the storage limit.
	 *
	 * @param string $value Raw text.
	 * @return string
	 */
	private static function preview( string $value ): string {
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $value, 0, self::PREVIEW_LIMIT );
		}

		return substr( $value, 0, self::PREVIEW_LIMIT );
	}
}
