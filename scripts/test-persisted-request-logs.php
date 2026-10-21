<?php
/**
 * Default-sink regressions for wp eval-file on a disposable AI 1.4 site.
 * All HTTP is intercepted; fixture rows, users, options and hooks are restored.
 * Enable AI Request Logging before running this script.
 *
 * @package AIProviderForCodex
 */

use AIProviderForCodex\Auth\ConnectionRepository;
use AIProviderForCodex\Auth\ConnectionSnapshotRepository;
use AIProviderForCodex\Logging\RequestLogWriter;
use AIProviderForCodex\Runtime\Settings;
use WordPress\AI\Logging\Logging_Integration;
use WordPress\AiClient\AiClient;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\UserMessage;

if ( ! defined( 'ABSPATH' ) || ! class_exists( Logging_Integration::class ) ) {
	throw new RuntimeException( 'Run on a disposable WordPress site with the AI plugin active.' );
}
global $wpdb;
$codex_manager = Logging_Integration::get_log_manager();
if ( null === $codex_manager || has_filter( 'codex_provider_request_log_sink' ) ) {
	throw new RuntimeException( 'Enable AI Request Logging and use the unmodified default Codex log sink.' );
}
$codex_tests = 0;
$codex_failures = array();
$codex_assert = static function ( bool $condition, string $message ) use ( &$codex_tests, &$codex_failures ): void {
	++$codex_tests;
	if ( ! $condition ) { $codex_failures[] = $message; }
};
$codex_table = $codex_manager->get_schema()->get_table_name();
$codex_token = wp_generate_uuid4();
$codex_model_id = 'codex-persist-' . $codex_token;
$codex_connection_id = 'codex-persist-connection-' . $codex_token;
$codex_original_user = get_current_user_id();
$codex_user_id = 0;
$codex_requests = 0;
$codex_reply_kind = 'success';
$codex_missing = 'codex-missing-' . $codex_token;
$codex_saved_options = array();
foreach ( array( 'wpai_features_enabled', 'wpai_feature_ai-request-logging_enabled', Settings::OPTION_RUNTIME_BASE_URL, Settings::OPTION_RUNTIME_BEARER ) as $codex_option ) {
	$codex_saved_options[ $codex_option ] = get_option( $codex_option, $codex_missing );
}
$codex_saved_health = get_transient( 'codex_provider_runtime_health' );
$codex_slot = new ReflectionProperty( Logging_Integration::class, 'log_manager' );
$codex_slot->setAccessible( true );
$codex_count = static function () use ( $codex_table, &$codex_user_id, $codex_model_id ): int {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE user_id = %d AND model = %s', $codex_table, $codex_user_id, $codex_model_id ) );
};
$codex_http = static function ( $preempt, array $args, string $url ) use ( &$codex_requests, &$codex_reply_kind, $codex_token ) {
	if ( '/v1/responses/text' !== (string) wp_parse_url( $url, PHP_URL_PATH ) ) {
		return new WP_Error( 'codex_fixture_unexpected_http', 'Persistence fixture refuses all other HTTP requests.' );
	}
	++$codex_requests;
	if ( 'error' === $codex_reply_kind ) { return new WP_Error( 'http_request_failed', 'cURL error 7: persistence fixture connection refused' ); }
	return array(
		'headers' => array(),
		'body' => wp_json_encode( array( 'requestId' => $codex_token, 'outputText' => 'Persisted fixture output.', 'finishReason' => 'stop', 'usage' => array( 'inputTokens' => 3, 'outputTokens' => 5, 'reasoningOutputTokens' => 0 ), 'account' => array(), 'rateLimits' => array() ) ),
		'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null,
	);
};
$codex_reject_insert = static function ( string $sql ) use ( $codex_table, $codex_model_id ): string {
	if ( 0 === strpos( $sql, 'INSERT INTO `' . $codex_table . '`' ) && false !== strpos( $sql, $codex_model_id ) ) {
		return str_replace( 'INSERT INTO `' . $codex_table . '`', 'INSERT INTO `codex_persistence_fixture_absent_table`', $sql );
	}
	return $sql;
};
$codex_store = null;
$codex_prior_approval = false;
$codex_basename = plugin_basename( AIProviderForCodex\PLUGIN_FILE );
$codex_prior_suppress = $wpdb->suppress_errors();
try {
	delete_option( 'wpai_features_enabled' );
	update_option( 'wpai_feature_ai-request-logging_enabled', true );
	update_option( Settings::OPTION_RUNTIME_BASE_URL, 'http://127.0.0.1:4317' );
	update_option( Settings::OPTION_RUNTIME_BEARER, 'isolated-persistence-inert' );
	$codex_assert( true === RequestLogWriter::is_logging_enabled(), 'Active AI 1.4 logging works without the retired global option.' );
	$codex_created_user = wp_insert_user( array( 'user_login' => 'codex-persist-' . $codex_token, 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
	if ( is_wp_error( $codex_created_user ) ) { throw new RuntimeException( 'Could not create the isolated fixture user.' ); }
	$codex_user_id = (int) $codex_created_user;
	wp_set_current_user( $codex_user_id );
	ConnectionRepository::upsert( $codex_user_id, array( 'connectionId' => $codex_connection_id, 'status' => 'linked', 'sessionExpiresAt' => gmdate( 'c', time() + HOUR_IN_SECONDS ) ) );
	ConnectionSnapshotRepository::upsert( $codex_connection_id, array( 'models' => array( array( 'id' => $codex_model_id, 'label' => 'Persistence fixture' ) ), 'defaultModel' => $codex_model_id ) );
	$codex_store_class = 'WordPress\\AI\\Connector_Approval\\Approvals_Store';
	if ( class_exists( $codex_store_class ) ) {
		$codex_store = new $codex_store_class();
		$codex_prior_approval = $codex_store->is_approved( $codex_basename, 'codex' );
		$codex_store->set_approval( $codex_basename, 'codex', true );
	}
	add_filter( 'pre_http_request', $codex_http, PHP_INT_MAX, 3 );
	$model = AiClient::defaultRegistry()->getProviderModel( 'codex', $codex_model_id );
	$prompt = array( new UserMessage( array( new MessagePart( 'Isolated persistence fixture.' ) ) ) );
	$result = $model->generateTextResult( $prompt );
	$codex_assert( 'Persisted fixture output.' === $result->toText(), 'Default-sink logging preserves the generated result.' );
	$codex_assert( 1 === $codex_requests && 1 === $codex_count(), 'Successful consumer call makes one request and persists one row.' );
	$codex_reply_kind = 'error';
	$codex_threw = false;
	try { $model->generateTextResult( $prompt ); } catch ( Throwable $error ) { $codex_threw = false !== strpos( $error->getMessage(), 'not reachable' ); }
	$codex_assert( $codex_threw, 'Transport failure keeps its meaningful cause after logging.' );
	$codex_assert( 2 === $codex_requests && 2 === $codex_count(), 'Failed consumer call makes one additional request and persists one additional row.' );
	$codex_statuses = $wpdb->get_col( $wpdb->prepare( 'SELECT status FROM %i WHERE user_id = %d AND model = %s ORDER BY status', $codex_table, $codex_user_id, $codex_model_id ) );
	$codex_assert( array( 'error', 'success' ) === $codex_statuses, 'Default sink stores exactly one success and one error without duplicates.' );
	// Emulate the uninitialized manager in a fresh request with the feature off.
	update_option( 'wpai_feature_ai-request-logging_enabled', false );
	$codex_slot->setValue( null, null );
	$codex_assert( false === RequestLogWriter::is_logging_enabled(), 'Disabled experiment skips the default sink.' );
	$codex_reply_kind = 'success';
	$model->generateTextResult( $prompt );
	$codex_assert( 3 === $codex_requests && 2 === $codex_count(), 'Disabled logging adds no row and does not duplicate requests.' );
	$codex_slot->setValue( null, $codex_manager );
	update_option( 'wpai_feature_ai-request-logging_enabled', true );
	add_filter( 'query', $codex_reject_insert );
	$wpdb->suppress_errors( true );
	$codex_recorded = RequestLogWriter::record( RequestLogWriter::build_entry( array( 'model' => $codex_model_id, 'status' => 'success', 'user_id' => $codex_user_id ) ) );
	$codex_assert( false === $codex_recorded && 2 === $codex_count(), 'Rejected INSERT returns false through actual default sink and stores no row.' );
	$result = $model->generateTextResult( $prompt );
	$codex_assert( 'Persisted fixture output.' === $result->toText(), 'Persistence failure preserves a successful consumer result.' );
	$codex_assert( 4 === $codex_requests && 2 === $codex_count(), 'Persistence failure does not retry generation or duplicate rows.' );
	if ( isset( $codex_flavor_checks ) && is_callable( $codex_flavor_checks ) ) {
		remove_filter( 'query', $codex_reject_insert );
		$wpdb->suppress_errors( $codex_prior_suppress );
		$codex_flavor_checks( $codex_assert, $codex_model_id, $codex_token, $codex_user_id, $codex_table, $codex_reply_kind );
	}
} finally {
	remove_filter( 'pre_http_request', $codex_http, PHP_INT_MAX );
	remove_filter( 'query', $codex_reject_insert );
	$wpdb->suppress_errors( $codex_prior_suppress );
	$codex_slot->setValue( null, $codex_manager );
	if ( null !== $codex_store ) { $codex_store->set_approval( $codex_basename, 'codex', $codex_prior_approval ); }
	if ( $codex_user_id > 0 ) {
		$wpdb->delete( $codex_table, array( 'user_id' => $codex_user_id, 'model' => $codex_model_id ), array( '%d', '%s' ) );
		ConnectionSnapshotRepository::delete( $codex_connection_id );
		ConnectionRepository::delete_for_user( $codex_user_id );
		if ( ! function_exists( 'wp_delete_user' ) ) { require_once ABSPATH . 'wp-admin/includes/user.php'; }
		wp_delete_user( $codex_user_id );
	}
	wp_set_current_user( $codex_original_user );
	foreach ( $codex_saved_options as $codex_option => $codex_old ) {
		if ( $codex_missing === $codex_old ) { delete_option( $codex_option ); } else { update_option( $codex_option, $codex_old ); }
	}
	if ( false === $codex_saved_health ) { delete_transient( 'codex_provider_runtime_health' ); } else { set_transient( 'codex_provider_runtime_health', $codex_saved_health, 10 * MINUTE_IN_SECONDS ); }
}
echo wp_json_encode( array( 'assertions' => $codex_tests, 'requests' => $codex_requests, 'failures' => $codex_failures, 'live_runtime_requests' => 0 ), JSON_PRETTY_PRINT ) . "\n";
if ( array() !== $codex_failures ) { throw new RuntimeException( 'Persisted request-log regressions failed.' ); }
