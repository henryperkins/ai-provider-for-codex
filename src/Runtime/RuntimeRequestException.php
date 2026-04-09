<?php
/**
 * Structured runtime request failures.
 *
 * @package AIProviderForCodex
 */

declare( strict_types=1 );

namespace AIProviderForCodex\Runtime;

use RuntimeException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Carries normalized and raw runtime error details.
 */
final class RuntimeRequestException extends RuntimeException {

	/**
	 * Runtime HTTP status code.
	 *
	 * @var int
	 */
	private $status_code;

	/**
	 * Runtime error code from the sidecar payload.
	 *
	 * @var string
	 */
	private $runtime_error_code;

	/**
	 * Raw runtime error message.
	 *
	 * @var string
	 */
	private $runtime_message;

	/**
	 * Raw decoded runtime payload.
	 *
	 * @var array<string,mixed>
	 */
	private $payload;

	/**
	 * Constructor.
	 *
	 * @param string $user_message Normalized user-facing error message.
	 * @param int    $status_code Runtime HTTP status code.
	 * @param string $runtime_error_code Sidecar error code.
	 * @param string              $runtime_message Raw runtime error message.
	 * @param array<string,mixed> $payload Raw decoded runtime payload.
	 */
	public function __construct( string $user_message, int $status_code, string $runtime_error_code, string $runtime_message, array $payload = [] ) {
		parent::__construct( $user_message );

		$this->status_code        = $status_code;
		$this->runtime_error_code = $runtime_error_code;
		$this->runtime_message    = $runtime_message;
		$this->payload            = $payload;
	}

	/**
	 * Returns the runtime HTTP status code.
	 *
	 * @return int
	 */
	public function get_status_code(): int {
		return $this->status_code;
	}

	/**
	 * Returns the sidecar error code.
	 *
	 * @return string
	 */
	public function get_runtime_error_code(): string {
		return $this->runtime_error_code;
	}

	/**
	 * Returns the raw sidecar error message.
	 *
	 * @return string
	 */
	public function get_runtime_message(): string {
		return $this->runtime_message;
	}

	/**
	 * Returns the raw decoded runtime payload.
	 *
	 * @return array<string,mixed>
	 */
	public function get_payload(): array {
		return $this->payload;
	}

	/**
	 * Returns whether the runtime no longer has user auth for the request.
	 *
	 * @return bool
	 */
	public function is_auth_required(): bool {
		return 'auth_required' === $this->runtime_error_code;
	}
}
