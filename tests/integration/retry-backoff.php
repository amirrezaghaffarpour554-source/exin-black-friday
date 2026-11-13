<?php
/** Durable retry budget and watchdog regressions against real WordPress/WooCommerce. */

use Exin\BlackFriday\Application\PriceProcessor;
use Exin\BlackFriday\Application\ProductSelectionService;
use Exin\BlackFriday\Application\Scheduler;
use Exin\BlackFriday\Infrastructure\CampaignRepository;
use Exin\BlackFriday\Infrastructure\Logger;
use Exin\BlackFriday\Infrastructure\Migration;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() || 'exin_test' !== DB_NAME ) {
	throw new RuntimeException( 'Retry regressions require isolated local database.' );
}
add_filter( 'action_scheduler_allow_async_request_runner', '__return_false' );
$repository = new CampaignRepository();
$logger = new Logger();
$selection = new ProductSelectionService( $repository );
$processor = new PriceProcessor( $repository, $logger );
$scheduler = new Scheduler( $repository, $processor, $logger );
Migration::run();
$checks = 0;
$assert = static function ( $condition, string $message ) use ( &$checks ) {
	++$checks;
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
	echo 'PASS ' . $message . "\n";
};
$fixture = static function () use ( $repository, $selection ) {
	$id = $repository->create( array( 'title' => 'EXIN TEST durable retries', 'start_utc' => gmdate( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS ), 'end_utc' => gmdate( 'Y-m-d H:i:s', time() + 4 * DAY_IN_SECONDS ), 'status' => 'draft' ) );
	$product = new WC_Product_Simple();
	$product->set_name( 'EXIN TEST retry reservation' );
	$product->set_status( 'publish' );
	$product->set_regular_price( '100.00' );
	$product->save();
	$selection->add( $id, $product->get_id(), '40.00' );
	$repository->save( $id, array( 'status' => 'active' ) );
	return array( $id, $product->get_id(), $repository->get( $id )['version'] );
};
$due = static function ( int $id, int $product_id ) use ( $repository ) {
	$state = $repository->item( $id, $product_id )['retry_state'];
	$state['next_at'] = time() - 1;
	$repository->put_item( $id, $product_id, array( 'retry_state' => $state ) );
};

list( $id, $product_id, $version ) = $fixture();
$failures = 0;
$failure = static function ( $product ) use ( $product_id, &$failures ) {
	if ( $product->get_id() === $product_id ) {
		++$failures;
		throw new RuntimeException( 'retry_fixture_failure' );
	}
};
add_action( 'woocommerce_before_product_object_save', $failure, 10000 );
try {
	$scheduler->batch( $id, $version, 0, 'apply' );
	$state = $repository->item( $id, $product_id )['retry_state'];
	$assert( 1 === $failures && 0 === $state['attempt'], 'Initial failure durably records the first attempt' );
	$assert( $state['next_at'] >= time() + 29 && ! $state['exhausted'], 'Initial retry respects thirty-second backoff' );
	$assert( as_has_scheduled_action( 'exin_bf_retry', array( $id, $product_id, $version, 'apply', 1 ), Scheduler::GROUP ), 'First retry is durably scheduled' );
	$fresh = new Scheduler( $repository, $processor, $logger );
	$fresh->batch( $id, $version, 0, 'apply' );
	$fresh->batch( $id, $version, 0, 'apply' );
	$fresh->retry( $id, $product_id, $version, 'apply', 1 );
	$assert( 1 === $failures, 'Fresh watchdog batches and an early retry cannot bypass backoff' );
	$due( $id, $product_id );
	$fresh->retry( $id, $product_id, $version, 'apply', 1 );
	$state = $repository->item( $id, $product_id )['retry_state'];
	$assert( 2 === $failures && 1 === $state['attempt'] && $state['next_at'] >= time() + 59, 'Retry one consumes the same durable budget and waits sixty seconds' );
	$fresh->batch( $id, $version, 0, 'apply' );
	$fresh->retry( $id, $product_id, $version, 'apply', 1 );
	$assert( 2 === $failures, 'Duplicate callbacks and batches cannot restart retry one' );
	$due( $id, $product_id );
	$fresh->retry( $id, $product_id, $version, 'apply', 1 );
	$assert( 2 === $failures, 'A stale earlier attempt remains inert after its deadline' );
	$fresh->batch( $id, $version, 0, 'apply' );
	$state = $repository->item( $id, $product_id )['retry_state'];
	$assert( 3 === $failures && 2 === $state['attempt'] && $state['next_at'] >= time() + 119, 'Watchdog recovers due retry two without resetting the budget' );
	$due( $id, $product_id );
	$fresh->retry( $id, $product_id, $version, 'apply', 3 );
	$item = $repository->item( $id, $product_id );
	$assert( 4 === $failures && $item['retry_state']['exhausted'] && 'conflict' === $item['status'], 'Exactly three retries exhaust the budget and record conflict' );
	$assert( str_starts_with( $item['conflict'], 'retry_exhausted_' ) && $item['intent'], 'Exhaustion preserves the durable financial intent' );
	$fresh->batch( $id, $version, 0, 'apply' );
	$fresh->retry( $id, $product_id, $version, 'apply', 3 );
	$fresh->retry( $id, $product_id, $version, 'apply', 4 );
	$assert( 4 === $failures, 'Exhausted and invalid callbacks never start a new retry chain' );
} finally {
	remove_action( 'woocommerce_before_product_object_save', $failure, 10000 );
}
$selection->resolve( $id, $product_id, 'retry' );
$item = $repository->item( $id, $product_id );
$assert( null === $item['retry_state'] && null === $item['intent'] && null === $item['expected'] && 'completed' === $item['status'], 'Explicit administrator retry clears budget and safely restores ownership' );

list( $crash_id, $crash_product, $crash_version ) = $fixture();
$repository->put_item( $crash_id, $crash_product, array( 'retry_state' => array( 'version' => $crash_version, 'mode' => 'apply', 'attempt' => 3, 'next_at' => time() - 1, 'running' => true, 'exhausted' => false ) ) );
$scheduler->batch( $crash_id, $crash_version, 0, 'apply' );
$item = $repository->item( $crash_id, $crash_product );
$assert( $item['retry_state']['exhausted'] && 'conflict' === $item['status'], 'A crashed final reservation cannot reset the attempt budget' );
$assert( '100.00' === \Exin\BlackFriday\Application\PriceState::money( (string) wc_get_product( $crash_product )->get_price( 'edit' ) ), 'A crashed exhausted operation never rewrites the product' );

list( $success_id, $success_product, $success_version ) = $fixture();
$repository->put_item( $success_id, $success_product, array( 'retry_state' => array( 'version' => $success_version, 'mode' => 'apply', 'attempt' => 0, 'next_at' => time() - 1, 'running' => false, 'exhausted' => false ) ) );
$scheduler->retry( $success_id, $success_product, $success_version, 'apply', 1 );
$item = $repository->item( $success_id, $success_product );
$assert( null === $item['retry_state'] && '70.00' === $item['expected']['price'], 'A successful due retry clears its durable reservation' );
$scheduler->retry( $success_id, $success_product, $success_version, 'apply', 1 );
$assert( null === $repository->item( $success_id, $success_product )['retry_state'], 'A completed retry cannot be duplicated into a fresh operation' );
$repository->put_item( $success_id, $success_product, array( 'retry_state' => array( 'version' => $success_version, 'mode' => 'apply', 'attempt' => 0, 'next_at' => time() - 1, 'running' => false, 'exhausted' => false ) ) );
$scheduler->transition( $success_id, 'pause' );
$scheduler->retry( $success_id, $success_product, $success_version, 'apply', 1 );
$assert( 0 === $repository->item( $success_id, $success_product )['retry_state']['attempt'], 'Paused stale-version retries cannot consume or execute a budget' );
$scheduler->transition( $success_id, 'resume' );
$new_version = $repository->get( $success_id )['version'];
$scheduler->batch( $success_id, $new_version, 0, 'apply' );
$assert( null === $repository->item( $success_id, $success_product )['retry_state'], 'Resumed campaign version supersedes the old retry state safely' );
$scheduler->transition( $success_id, 'restore' );
$restore_version = $repository->get( $success_id )['version'];
$scheduler->batch( $success_id, $restore_version, 0, 'restore' );
$assert( null === $repository->item( $success_id, $success_product )['expected'] && 'completed' === $repository->get( $success_id )['status'], 'Restoration has an independent bounded operation scope' );

list( $audit_id, $audit_product, $audit_version ) = $fixture();
$audit_failures = 0;
$audit_product_failure = static function ( $product ) use ( $audit_product, &$audit_failures ) {
	if ( $product->get_id() === $audit_product ) {
		++$audit_failures;
		throw new RuntimeException( 'retry_audit_fixture_failure' );
	}
};
$audit_insert_failure = static function ( $sql ) {
	global $wpdb;
	if ( preg_match( '/^INSERT\s+INTO\s+`?' . preg_quote( $wpdb->prefix . 'exin_bf_audit', '/' ) . '`?\s/i', $sql ) ) {
		return str_replace( $wpdb->prefix . 'exin_bf_audit', $wpdb->prefix . 'exin_bf_missing_retry_audit', $sql );
	}
	return $sql;
};
global $wpdb;
$previous_suppression = $wpdb->suppress_errors( true );
add_action( 'woocommerce_before_product_object_save', $audit_product_failure, 10000 );
add_filter( 'query', $audit_insert_failure );
try {
	try {
		$scheduler->batch( $audit_id, $audit_version, 0, 'apply' );
	} catch ( Throwable $error ) {
		$assert( 'audit_write_failed' === $error->getMessage(), 'Audit outage is recorded as the expected storage failure' );
	}
	$state = $repository->item( $audit_id, $audit_product )['retry_state'];
	$assert( 0 === $state['attempt'] && ! $state['exhausted'] && $state['next_at'] >= time() + 29, 'Diagnostic audit outage cannot erase the reserved retry budget' );
	$assert( as_has_scheduled_action( 'exin_bf_retry', array( $audit_id, $audit_product, $audit_version, 'apply', 1 ), Scheduler::GROUP ), 'Retry queue is acknowledged before unavailable diagnostic audit storage' );
	$scheduler->batch( $audit_id, $audit_version, 0, 'apply' );
	$assert( 1 === $audit_failures, 'Watchdog cannot bypass backoff while diagnostic storage is unavailable' );
} finally {
	remove_filter( 'query', $audit_insert_failure );
	remove_action( 'woocommerce_before_product_object_save', $audit_product_failure, 10000 );
	$wpdb->suppress_errors( $previous_suppression );
}
$due( $audit_id, $audit_product );
$scheduler->retry( $audit_id, $audit_product, $audit_version, 'apply', 1 );
$assert( null === $repository->item( $audit_id, $audit_product )['retry_state'] && null === $repository->item( $audit_id, $audit_product )['intent'], 'Repairing diagnostic storage lets the original durable retry recover safely' );

list( $pagination_id, $pagination_first ) = $fixture();
$other_products = array();
foreach ( array( 1, 2 ) as $number ) {
	$product = new WC_Product_Simple();
	$product->set_name( 'EXIN TEST removed pagination ' . $number );
	$product->set_status( 'publish' );
	$product->set_regular_price( '100.00' );
	$product->save();
	$selection->add( $pagination_id, $product->get_id(), '40.00' );
	$other_products[] = $product->get_id();
}
$selection->remove( $pagination_id, $pagination_first );
$selection->remove( $pagination_id, $other_products[0] );
$page = $repository->items( $pagination_id, 1, 0 );
$assert( 1 === count( $page ) && $page[0]['product_id'] === $other_products[1] && 1 === $repository->count_items( $pagination_id ), 'Removed rows cannot consume admin pagination slots or hide remaining selected products' );
$assert( 'removed' === $repository->item( $pagination_id, $pagination_first )['status'] && count( $repository->batch( $pagination_id ) ) === 3, 'Pagination filtering preserves removed snapshots and recovery traversal' );

$snapshot_before_upgrade = $repository->item( $pagination_id, $pagination_first )['snapshot'];
update_option( 'exin_bf_schema_version', '1', false );
Migration::run();
$assert( '2' === get_option( 'exin_bf_schema_version' ) && $snapshot_before_upgrade === $repository->item( $pagination_id, $pagination_first )['snapshot'], 'Schema one upgrades to durable retry schema two without changing historical snapshots' );

$retention_campaign = $repository->current();
$retention_settings = $retention_campaign['settings'];
$retention_settings['retention_days'] = 7;
$repository->save( $retention_campaign['id'], array( 'settings' => $retention_settings ) );
$logger->log( 'info', 'retention_fixture', array( 'campaign_id' => $retention_campaign['id'] ) );
$retention_record = (int) $wpdb->insert_id;
$wpdb->update( $wpdb->prefix . 'exin_bf_audit', array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 20 * DAY_IN_SECONDS ) ), array( 'id' => $retention_record ) );
$scheduler->tick();
$assert( null === $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE id=%d', $wpdb->prefix . 'exin_bf_audit', $retention_record ) ), 'Watchdog honors current campaign configured seven-day audit retention' );

echo 'EXIN_RETRY_RESULT ' . wp_json_encode( array( 'checks' => $checks, 'passed' => $checks, 'failed' => 0, 'schema' => get_option( 'exin_bf_schema_version' ), 'wordpress' => get_bloginfo( 'version' ), 'woocommerce' => WC_VERSION, 'php' => PHP_VERSION ) ) . "\n";
