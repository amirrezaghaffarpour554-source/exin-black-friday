<?php
/** Focused real-AS regressions for the independent PR #16 release audit. */

use Exin\BlackFriday\Application\PriceProcessor;
use Exin\BlackFriday\Application\Scheduler;
use Exin\BlackFriday\Infrastructure\CampaignRepository;
use Exin\BlackFriday\Infrastructure\Logger;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'exin_test' !== DB_NAME || 'local' !== wp_get_environment_type() ) {
	throw new RuntimeException( 'An isolated local exin_test database is required.' );
}
add_filter( 'action_scheduler_allow_async_request_runner', '__return_false' );
$repo      = new CampaignRepository();
$scheduler = new Scheduler( $repo, new PriceProcessor( $repo, new Logger() ), new Logger() );
$store     = ActionScheduler::store();
$checks    = 0;
$assert    = static function ( bool $value, string $reason ) use ( &$checks ): void {
	++$checks;
	if ( ! $value ) {
		throw new RuntimeException( $reason );
	}
};
$pending = static fn(): array => as_get_scheduled_actions( array( 'hook' => 'exin_bf_tick', 'group' => Scheduler::GROUP, 'args' => array(), 'status' => 'pending', 'per_page' => 100 ), 'ids' );
$timely  = static function () use ( $pending, $store ): array {
	return array_values( array_filter( $pending(), static function ( $id ) use ( $store ): bool {
		$action   = $store->fetch_action( $id );
		$schedule = $action->get_schedule();
		return ! $action->get_args() && $schedule->is_recurring() && 60 === (int) $schedule->get_recurrence() && $schedule->get_date()->getTimestamp() <= time() + 60;
	} ) );
};
$clear = static function (): void {
	as_unschedule_all_actions( 'exin_bf_tick', array(), Scheduler::GROUP );
};

// A mere recurring record is not proof of a working minute watchdog.
$clear();
$wrong = as_schedule_recurring_action( time() + 5, DAY_IN_SECONDS, 'exin_bf_tick', array(), Scheduler::GROUP, false );
$scheduler->recover();
$assert( count( $timely() ) === 1, 'Wrong-cadence recurrence must be replaced by a timely 60-second chain.' );
$assert( 'canceled' === $store->get_status( $wrong ), 'An unclaimed wrong-cadence recurrence must not survive reconciliation.' );

$clear();
$future = as_schedule_recurring_action( time() + DAY_IN_SECONDS, 60, 'exin_bf_tick', array(), Scheduler::GROUP, false );
update_option( 'exin_bf_last_tick', gmdate( 'Y-m-d H:i:s', time() - 600 ), false );
$scheduler->recover();
$assert( count( $timely() ) === 1, 'A far-future minute recurrence must not block timely recovery.' );
$assert( 'canceled' === $store->get_status( $future ), 'An unclaimed far-future recurrence must be reconciled.' );

// Exercise the ordinary-request periodic-verification fast path too.
$clear();
as_schedule_recurring_action( time() + DAY_IN_SECONDS, 60, 'exin_bf_tick', array(), Scheduler::GROUP, false );
update_option( 'exin_bf_last_tick', gmdate( 'Y-m-d H:i:s' ), false );
update_option( 'exin_bf_recovery_health', array( 'release' => EXIN_BF_VERSION, 'verified' => time() - 90 ), false );
delete_option( 'exin_bf_needs_recovery' );
delete_option( 'exin_bf_boot_error' );
delete_option( 'exin_bf_recovery_attempt' );
$scheduler->maybe_recover();
$assert( count( $timely() ) === 1, 'Periodic verification cannot bless a far-future recurrence as healthy.' );

// A second connection changes the flag after observation, immediately before
// acknowledgement. It deliberately bypasses this request's WP option cache.
global $wpdb;
$db_host  = explode( ':', DB_HOST, 2 );
$socket   = isset( $db_host[1] ) && str_starts_with( $db_host[1], '/' ) ? $db_host[1] : null;
$port     = isset( $db_host[1] ) && ctype_digit( $db_host[1] ) ? (int) $db_host[1] : 0;
$external = new mysqli( $db_host[0], DB_USER, DB_PASSWORD, DB_NAME, $port, $socket );
$external->set_charset( 'utf8mb4' );
$option_table = str_replace( '`', '``', $wpdb->options );
$observed     = wp_generate_uuid4();
$newer        = wp_generate_uuid4();
update_option( 'exin_bf_needs_recovery', $observed, false );
$assert( get_option( 'exin_bf_needs_recovery' ) === $observed, 'The old recovery flag is primed in the request cache.' );
$interleaved = false;
$replace_flag = static function ( string $sql ) use ( $external, $option_table, $newer, &$interleaved ): string {
	if ( ! $interleaved && preg_match( '/^DELETE\b/i', ltrim( $sql ) ) && str_contains( $sql, 'exin_bf_needs_recovery' ) ) {
		$interleaved = true;
		$stmt = $external->prepare( "UPDATE `{$option_table}` SET option_value=? WHERE option_name='exin_bf_needs_recovery'" );
		$stmt->bind_param( 's', $newer );
		$stmt->execute();
		$stmt->close();
	}
	return $sql;
};
add_filter( 'query', $replace_flag );
try {
	$scheduler->recover();
} finally {
	remove_filter( 'query', $replace_flag );
}
$raw_flag = $external->query( "SELECT option_value FROM `{$option_table}` WHERE option_name='exin_bf_needs_recovery'" )->fetch_row();
$assert( $interleaved, 'The newer flag is installed at the acknowledgement race boundary.' );
$assert( $raw_flag && $raw_flag[0] === $newer, 'Successful recovery must preserve a newer flag written by another connection.' );
$assert( get_option( 'exin_bf_needs_recovery' ) === $newer, 'Acknowledgement invalidates stale request-local option cache.' );
delete_option( 'exin_bf_needs_recovery' );

// Failure must not downgrade an already-present unique request token to 1.
$clear();
$failure_flag = wp_generate_uuid4();
update_option( 'exin_bf_needs_recovery', $failure_flag, false );
$failed_insert = false;
$fail_insert = static function ( string $sql ) use ( &$failed_insert ): string {
	if ( str_contains( $sql, 'INSERT INTO' ) && str_contains( $sql, 'actionscheduler_actions' ) ) {
		$failed_insert = true;
		return 'SELECT * FROM exin_release_audit_deliberately_missing_table';
	}
	return $sql;
};
$old_errors = $wpdb->suppress_errors( true );
add_filter( 'query', $fail_insert );
try {
	$scheduler->recover();
} finally {
	remove_filter( 'query', $fail_insert );
	$wpdb->suppress_errors( $old_errors );
}
$raw_flag = $external->query( "SELECT option_value FROM `{$option_table}` WHERE option_name='exin_bf_needs_recovery'" )->fetch_row();
$assert( $failed_insert, 'The scheduler persistence failure was injected.' );
$assert( $raw_flag && $raw_flag[0] === $failure_flag, 'Failed recovery must preserve the existing unique recovery request.' );
delete_option( 'exin_bf_needs_recovery' );
$scheduler->recover();

// Deterministic runner interleaving at the cancellation boundary. A recovery-
// owned AS claim excludes the duplicate from another runner's atomic claim.
// The old check-then-cancel implementation lets the runner start that action,
// then blindly changes its in-progress status to canceled.
$clear();
$preclaimed = as_schedule_recurring_action( time() + 1, 60, 'exin_bf_tick', array(), Scheduler::GROUP, false );
$existing_claim = $store->stake_claim( 1, as_get_datetime_object( time() + 3600 ), array( 'exin_bf_tick' ), Scheduler::GROUP );
$assert( in_array( $preclaimed, $existing_claim->get_actions(), true ), 'A pre-existing runner owns the earliest watchdog.' );
as_schedule_recurring_action( time() + 2, 60, 'exin_bf_tick', array(), Scheduler::GROUP, false );
as_schedule_recurring_action( time() + 3, 60, 'exin_bf_tick', array(), Scheduler::GROUP, false );
$race_ran = false;
$cancel_was_owned = false;
$started = array();
$runner_claims = array();
$runner = static function ( string $sql ) use ( $store, &$race_ran, &$cancel_was_owned, &$started, &$runner_claims ): string {
	if ( ! $race_ran && preg_match( '/^UPDATE\b/i', ltrim( $sql ) ) && str_contains( $sql, 'actionscheduler_actions' ) && str_contains( $sql, 'canceled' ) && preg_match( '/action_id[^0-9]*([0-9]+)/', $sql, $match ) ) {
		$race_ran = true;
		$target = (int) $match[1];
		$cancel_was_owned = 0 !== (int) $store->get_claim_id( $target );
		$claim = $store->stake_claim( 10, as_get_datetime_object( time() + 3600 ), array( 'exin_bf_tick' ), Scheduler::GROUP );
		$runner_claims[] = $claim;
		foreach ( $claim->get_actions() as $id ) {
			$store->log_execution( $id );
			$started[] = $id;
		}
	}
	return $sql;
};
add_filter( 'query', $runner );
try {
	$scheduler->recover();
} finally {
	remove_filter( 'query', $runner );
}
$assert( $race_ran, 'A real runner claim attempt occurred immediately before AS cancellation.' );
$assert( $cancel_was_owned, 'Recovery owns an AS claim before canceling a duplicate.' );
foreach ( $started as $id ) {
	$assert( 'in-progress' === $store->get_status( $id ), 'No action started by the interleaving runner is canceled.' );
	$store->mark_complete( $id );
}
$assert( 'pending' === $store->get_status( $preclaimed ), 'The pre-existing claimed watchdog is untouched.' );
$store->release_claim( $existing_claim );
foreach ( $runner_claims as $claim ) {
	$store->release_claim( $claim );
}
$scheduler->recover();
$assert( count( $timely() ) === 1, 'After runners release their claims, exactly one timely recurring successor remains.' );
$external->close();

echo 'RELEASE_WATCHDOG_AUDIT ' . wp_json_encode( array( 'passed' => $checks, 'failed' => 0, 'separate_db_connection' => true, 'real_as_claim_interleaving' => true, 'production_accessed' => false ) ) . PHP_EOL;
