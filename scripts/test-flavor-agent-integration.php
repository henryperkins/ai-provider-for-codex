<?php
/**
 * Cross-plugin regressions on a disposable WordPress installation.
 *
 * Activate Codex, Flavor Agent and WordPress AI with AI Request Logging enabled:
 *   wp --path=/path/to/disposable-site eval-file scripts/test-flavor-agent-integration.php
 *
 * Uses real SDK results, log storage, request tagging and Activity projections.
 * Only the sidecar HTTP boundary is intercepted by the shared persistence fixture.
 * No live model requests. Fixture rows, users, posts and options are removed.
 *
 * @package AIProviderForCodex
 */

use FlavorAgent\Abilities\RecommendationAbilityExecution;
use FlavorAgent\Activity\Repository;
use FlavorAgent\Activity\RequestLoggingBridge;
use FlavorAgent\AI\FeatureModelSelection;
use FlavorAgent\LLM\WordPressAIClient;
use FlavorAgent\Support\FlavorAgentRequestTag;
use WordPress\AI\Logging\Logging_Integration;
use WordPress\AiClient\AiClient;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\UserMessage;

if ( ! defined( 'ABSPATH' ) || ! class_exists( WordPressAIClient::class ) || ! class_exists( Logging_Integration::class ) ) {
	throw new RuntimeException( 'Run on a disposable site with Codex, Flavor Agent and WordPress AI active.' );
}

$codex_flavor_checks = static function ( callable $assert, string $model_id, string $request_id, int $user_id, string $log_table, string &$reply_kind ): void {
	global $wpdb;
	$missing = 'missing-' . wp_generate_uuid4();
	$selection = get_option( FeatureModelSelection::OPTION_NAME, $missing );
	$manager = Logging_Integration::get_log_manager();
	$slot = new ReflectionProperty( Logging_Integration::class, 'log_manager' );
	$slot->setAccessible( true );
	$post_id = 0;
	$activity_table = Repository::table_name();
	$prior_suppress = $wpdb->suppress_errors();
	$persist_diagnostic = static function (): bool { return true; };
	$reject_insert = static function ( string $sql ) use ( $log_table, $model_id ): string {
		if ( 0 === strpos( $sql, 'INSERT INTO `' . $log_table . '`' ) && false !== strpos( $sql, $model_id ) ) {
			return str_replace( 'INSERT INTO `' . $log_table . '`', 'INSERT INTO `codex_integration_absent_table`', $sql );
		}
		return $sql;
	};
	$rows = static function () use ( $wpdb, $log_table, $user_id, $model_id ): array {
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE user_id = %d AND model = %s ORDER BY id', $log_table, $user_id, $model_id ), ARRAY_A );
	};
	$meta = static function ( $result ): array {
		return is_wp_error( $result ) ? ( $result->get_error_data()['requestMeta'] ?? array() ) : ( $result['requestMeta'] ?? array() );
	};
	try {
		wp_update_user( array( 'ID' => $user_id, 'role' => 'administrator' ) );
		wp_set_current_user( 0 );
		wp_set_current_user( $user_id );
		$post_id = wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'Disposable diagnostic integration', 'post_status' => 'draft', 'post_author' => $user_id ), true );
		if ( is_wp_error( $post_id ) ) { throw new RuntimeException( 'Could not create the fixture page.' ); }
		$scope = 'page:' . $post_id;
		update_option( FeatureModelSelection::OPTION_NAME, array( 'provider' => 'codex', 'model' => $model_id ) );
		add_filter( 'flavor_agent_persist_request_diagnostic_with_core_logging', $persist_diagnostic );
		$assert( false !== has_filter( 'wpai_request_log_context', array( RequestLoggingBridge::class, 'inject_flavor_agent_context' ) ), 'Flavor Agent registered its real logging bridge.' );
		$generate = static function () use ( $scope, $post_id ) {
			return RecommendationAbilityExecution::execute(
				'block', 'flavor-agent/recommend-block',
				array( 'document' => array( 'scopeKey' => $scope, 'postType' => 'page', 'entityId' => (string) $post_id ) ),
				static function () {
					$text = WordPressAIClient::chat( 'Return a short recommendation.', 'Synthetic integration request.' );
					return is_wp_error( $text ) ? $text : array( 'explanation' => $text, 'settings' => array(), 'styles' => array(), 'blocks' => array() );
				}
			);
		};
		$before = count( $rows() );
		$reply_kind = 'success';
		$result = $generate();
		$diagnostics = $meta( $result );
		$stored = $rows();
		$row = end( $stored );
		$context = json_decode( $row['context'], true );
		$assert( ! is_wp_error( $result ), 'Flavor Agent generates through the real WordPress SDK and Codex model.' );
		$assert( $before + 1 === count( $stored ), 'One Flavor Agent generation persists exactly one AI log.' );
		$assert( (string) $row['log_id'] === ( $diagnostics['requestLogId'] ?? '' ), 'Flavor Agent metadata links the actual persisted AI log ID.' );
		$assert( ( $diagnostics['requestToken'] ?? '' ) === ( $context['flavor_agent']['requestToken'] ?? null ), 'AI log and Flavor Agent share the request token.' );
		$assert( $scope === ( $context['flavor_agent']['scopeKey'] ?? null ), 'Correlation retains the originating document.' );
		$assert( $request_id === ( $diagnostics['responseSummary']['providerRequestId'] ?? null ) && $request_id === ( $context['request_id'] ?? null ), 'The SDK preserves the same Codex request ID in both diagnostics stores.' );
		$assert( 3 === ( $diagnostics['tokenUsage']['input'] ?? null ) && 5 === ( $diagnostics['tokenUsage']['output'] ?? null ), 'Token usage survives the real SDK boundary.' );
		$assert( ! isset( $diagnostics['requestSummary']['maxOutputTokens'] ), 'The optional default budget does not exclude Codex or claim an unsent limit.' );
		$assert( null === FlavorAgentRequestTag::current(), 'The active request tag is cleared after generation.' );
		$diagnostic_row = $wpdb->get_row( $wpdb->prepare( 'SELECT request_json FROM %i WHERE user_id = %d AND document_scope_key = %s AND activity_type = %s ORDER BY id DESC LIMIT 1', $activity_table, $user_id, $scope, 'request_diagnostic' ), ARRAY_A );
		$stored_request = json_decode( $diagnostic_row['request_json'] ?? '{}', true );
		$assert( (string) $row['log_id'] === ( $stored_request['ai']['requestLogId'] ?? '' ), 'The persisted Activity request retains the AI log link.' );

		$outcome_id = 'codex-outcome-' . $request_id;
		$created = Repository::create( array(
			'id' => $outcome_id, 'type' => 'recommendation_outcome', 'surface' => 'block',
			'document' => array( 'scopeKey' => $scope, 'postType' => 'page', 'entityId' => (string) $post_id ),
			'after' => array( 'outcome' => array( 'event' => 'shown', 'recommendationSetId' => 'set-' . $request_id, 'suggestionCount' => 0, 'learningAttribution' => $diagnostics['learningAttribution'] ?? array() ) ),
		) );
		$assert( ! is_wp_error( $created ), 'The generated attribution can be persisted as a recommendation outcome.' );
		$outcome = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE activity_id = %s', $activity_table, $outcome_id ), ARRAY_A );
		$assert( 'codex' === ( $outcome['admin_provider'] ?? null ) && $model_id === ( $outcome['admin_model'] ?? null ), 'Outcome provider/model projection matches the generated attribution.' );
		$outcome_request = json_decode( $outcome['request_json'] ?? '{}', true );
		$assert( ! isset( $outcome_request['ai'] ) && ! isset( $outcome_request['prompt'] ), 'Outcome telemetry stays limited to references and allowlisted attribution.' );

		$reply_kind = 'error';
		$error = $generate();
		$error_meta = $meta( $error );
		$stored = $rows();
		$error_row = end( $stored );
		$assert( is_wp_error( $error ) && $before + 2 === count( $stored ), 'Failed generation still records exactly one additional AI log.' );
		$assert( 'error' === $error_row['status'] && (string) $error_row['log_id'] === ( $error_meta['requestLogId'] ?? '' ), 'Failure diagnostics link the failed AI log.' );
		$assert( null === FlavorAgentRequestTag::current(), 'A failed request also clears the active tag.' );

		$reply_kind = 'success';
		$slot->setValue( null, null );
		$disabled = $generate();
		$assert( ! is_wp_error( $disabled ) && '' === ( $meta( $disabled )['requestLogId'] ?? '' ) && $before + 2 === count( $rows() ), 'Disabled logging preserves generation without a phantom or stale link.' );
		$slot->setValue( null, $manager );
		add_filter( 'query', $reject_insert );
		$wpdb->suppress_errors( true );
		$rejected = $generate();
		$assert( ! is_wp_error( $rejected ) && '' === ( $meta( $rejected )['requestLogId'] ?? '' ) && $before + 2 === count( $rows() ), 'Rejected storage preserves generation without a captured log ID.' );
		remove_filter( 'query', $reject_insert );
		$wpdb->suppress_errors( $prior_suppress );

		$model = AiClient::defaultRegistry()->getProviderModel( 'codex', $model_id );
		$model->generateTextResult( array( new UserMessage( array( new MessagePart( 'Unrelated consumer.' ) ) ) ) );
		$stored = $rows();
		$last = end( $stored );
		$last_context = json_decode( $last['context'], true );
		$assert( $before + 3 === count( $stored ) && ! isset( $last_context['flavor_agent'] ), 'Calls outside Flavor Agent never inherit its request context.' );
	} finally {
		remove_filter( 'query', $reject_insert );
		remove_filter( 'flavor_agent_persist_request_diagnostic_with_core_logging', $persist_diagnostic );
		$wpdb->suppress_errors( $prior_suppress );
		$slot->setValue( null, $manager );
		FlavorAgentRequestTag::finish();
		if ( is_int( $post_id ) && $post_id > 0 ) {
			$wpdb->delete( $activity_table, array( 'user_id' => $user_id, 'document_scope_key' => 'page:' . $post_id ), array( '%d', '%s' ) );
			wp_delete_post( $post_id, true );
		}
		if ( $missing === $selection ) { delete_option( FeatureModelSelection::OPTION_NAME ); } else { update_option( FeatureModelSelection::OPTION_NAME, $selection ); }
	}
};

require __DIR__ . '/test-persisted-request-logs.php';
