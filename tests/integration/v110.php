<?php
/** Focused v1.1.0 financial integration checks; synthetic local WordPress only. */

use Exin\BlackFriday\Application\BulkSelectionService;
use Exin\BlackFriday\Application\PriceProcessor;
use Exin\BlackFriday\Application\PriceState;
use Exin\BlackFriday\Application\ProductSelectionService;
use Exin\BlackFriday\Application\Scheduler;
use Exin\BlackFriday\Domain\DiscountPlan;
use Exin\BlackFriday\Infrastructure\CampaignRepository;
use Exin\BlackFriday\Infrastructure\Logger;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() || 'exin_test' !== DB_NAME ) {
	throw new RuntimeException( 'Run only in the isolated local exin_test fixture.' );
}
if ( ! class_exists( 'WooCommerce' ) || ! class_exists( BulkSelectionService::class ) ) {
	throw new RuntimeException( 'Real WooCommerce and the v1.1.0 source must be active.' );
}
add_filter( 'action_scheduler_allow_async_request_runner', '__return_false' );
add_filter( 'pre_wp_mail', '__return_true' );

$suite = new class() {
	public int $assertions = 0;
	public array $results = array();
	public function same( $expected, $actual, string $message ): void {
		++$this->assertions;
		if ( $expected !== $actual ) {
			throw new RuntimeException( $message . '; expected ' . wp_json_encode( $expected ) . ', got ' . wp_json_encode( $actual ) );
		}
	}
	public function test( string $name, callable $operation ): void {
		try {
			$operation();
			$this->results[] = array( 'test' => $name, 'status' => 'passed' );
			echo 'PASS ' . $name . PHP_EOL;
		} catch ( Throwable $error ) {
			$this->results[] = array( 'test' => $name, 'status' => 'failed', 'error' => $error->getMessage() );
			echo 'FAIL ' . $name . ': ' . $error->getMessage() . PHP_EOL;
		}
	}
};
$repo = new CampaignRepository();
$selection = new ProductSelectionService( $repo );
$processor = new PriceProcessor( $repo, new Logger() );
$scheduler = new Scheduler( $repo, $processor, new Logger() );
$bulk = new BulkSelectionService( $repo );
$old_currency = get_option( 'woocommerce_currency' );
$old_precision = get_option( 'woocommerce_price_num_decimals' );
$old_user = get_current_user_id();
$administrators = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
if ( ! $administrators ) { throw new RuntimeException( 'A local fixture administrator is required.' ); }
wp_set_current_user( (int) $administrators[0] );
update_option( 'woocommerce_currency', 'USD' );
update_option( 'woocommerce_price_num_decimals', 2 );
$campaigns = array();
$nonce = wp_create_nonce( 'wp_rest' );
$rest = static function ( string $path, array $body = array(), int $status = 200 ) use ( $suite, $nonce ): array {
	$request = new WP_REST_Request( 'POST', '/exin-bf/v1/admin/' . $path );
	$request->set_header( 'X-WP-Nonce', $nonce );
	$request->set_header( 'Content-Type', 'application/json' );
	$request->set_body( wp_json_encode( $body ) );
	$response = rest_do_request( $request );
	$suite->same( $status, $response->get_status(), 'REST ' . $path . ': ' . wp_json_encode( $response->get_data() ) );
	return $response->get_data();
};
$product = static function ( string $name, string $price = '100.00' ): WC_Product_Simple {
	$item = new WC_Product_Simple();
	$item->set_name( 'EXIN110 ' . $name );
	$item->set_status( 'publish' );
	$item->set_virtual( true );
	$item->set_regular_price( $price );
	$item->set_manage_stock( true );
	$item->set_stock_quantity( 20 );
	$item->save();
	return $item;
};
$campaign = static function ( array $plan = array(), int $offset = -5, int $priority = 0 ) use ( $repo, &$campaigns ): int {
	$plan = DiscountPlan::normalize( array_replace( array( 'mode' => 'fixed_percent', 'initial_discount' => '5', 'steps' => 3, 'interval' => 'hourly' ), $plan ), wc_get_price_decimals() );
	$data = array(
		'title' => 'EXIN110 ' . wp_generate_uuid4(), 'status' => 'draft', 'start_utc' => gmdate( 'Y-m-d H:i:s', time() + $offset ),
		'timezone' => 'Asia/Tehran', 'days' => $plan['steps'], 'strategy' => 'linear', 'power' => 2, 'rounding' => 'half_up',
		'settings' => array( 'discount_plan' => $plan, 'priority' => $priority, 'existing_sale_policy' => 'preserve', 'campaign_type' => 'general' ),
	);
	$data['end_utc'] = DiscountPlan::end( $data );
	$id = $repo->create( $data );
	$campaigns[] = $id;
	return $id;
};
$activate = static function ( int $id ) use ( $repo ): int {
	$settings = $repo->get( $id )['settings'];
	$settings['started_at'] = gmdate( 'Y-m-d H:i:s' );
	$repo->save( $id, array( 'status' => 'active', 'settings' => $settings ) );
	return $repo->get( $id )['version'];
};
$shift = static function ( int $id, int $offset ) use ( $repo ): void {
	$record = $repo->get( $id );
	$record['start_utc'] = gmdate( 'Y-m-d H:i:s', time() + $offset );
	$repo->save( $id, array( 'start_utc' => $record['start_utc'], 'end_utc' => DiscountPlan::end( $record ) ) );
};
$price = static fn( WC_Product $item ): string => PriceState::money( wc_get_product( $item->get_id() )->get_price( 'edit' ) );
$raw_item = static function ( int $id, int $pid ): array {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE campaign_id=%d AND product_id=%d', $wpdb->prefix . 'exin_bf_items', $id, $pid ), ARRAY_A );
};

try {
	$suite->test( 'Immediate discount, preview immutability and changed versus duplicate operation counts', static function () use ( $suite, $campaign, $product, $selection, $repo, $rest, $activate, $scheduler, $processor, $price ): void {
		$id = $campaign(); $item = $product( 'immediate' );
		$selection->add( $id, $item->get_id(), '0' );
		$preview = $rest( 'campaign/' . $id . '/simulation' );
		$suite->same( '95.00', $preview['samples'][0]['schedule'][0], 'Day one is already five percent discounted' );
		$suite->same( '100.00', $price( $item ), 'Simulation never saves a real price' );
		$version = $activate( $id );
		$scheduler->batch( $id, $version );
		$suite->same( '95.00', $price( $item ), 'Immediate price is applied through real Woo CRUD' );
		$run = $repo->get( $id )['settings']['last_run'];
		$suite->same( array( 1, 0, 0, 0 ), array( $run['changed'], $run['unchanged'], $run['errors'], $run['skipped'] ), 'A changed item is distinguished from a successful no-op' );
		$scheduler->batch( $id, $version );
		$suite->same( $run, $repo->get( $id )['settings']['last_run'], 'Duplicate callback cannot recount the completed run' );
		$suite->same( 'unchanged', $processor->apply( $id, $item->get_id(), $version ), 'Duplicate financial operation reports no price change' );
		$processor->restore( $id, $item->get_id() );
	} );

	$suite->test( 'Increasing decreasing and custom delayed stages use the immutable base and product floor', static function () use ( $suite, $campaign, $product, $selection, $activate, $processor, $shift, $price ): void {
		foreach ( array( array( '5', '15', '95.00', '85.00' ), array( '20', '10', '80.00', '90.00' ) ) as $values ) {
			$id = $campaign( array( 'mode' => 'stepped', 'initial_discount' => $values[0], 'final_discount' => $values[1] ) );
			$item = $product( 'direction' ); $selection->add( $id, $item->get_id(), '70.00' ); $version = $activate( $id );
			$suite->same( 'changed', $processor->apply( $id, $item->get_id(), $version ), 'First stage changes the price' );
			$suite->same( $values[2], $price( $item ), 'Initial percentage uses the original regular price' );
			$shift( $id, -2 * HOUR_IN_SECONDS - 5 );
			$suite->same( 'changed', $processor->apply( $id, $item->get_id(), $version ), 'Late execution applies the current stage directly' );
			$suite->same( $values[3], $price( $item ), 'Discount can safely increase or decrease without compounding' );
			$processor->restore( $id, $item->get_id() );
		}
		$id = $campaign( array( 'mode' => 'custom', 'points' => array( array( 'offset_minutes' => 0, 'kind' => 'percent', 'value' => '5' ), array( 'offset_minutes' => 10, 'kind' => 'amount', 'value' => '50' ), array( 'offset_minutes' => 30, 'kind' => 'percent', 'value' => '10' ) ), 'duration_minutes' => 120 ), -35 * MINUTE_IN_SECONDS );
		$item = $product( 'custom late' ); $selection->add( $id, $item->get_id(), '80.00' ); $version = $activate( $id );
		$suite->same( 'changed', $processor->apply( $id, $item->get_id(), $version ), 'Custom late stage executes' );
		$suite->same( '90.00', $price( $item ), 'Custom stage selects current percentage rather than replaying old reductions' );
		$processor->restore( $id, $item->get_id() );
	} );

	$suite->test( 'Prior Woo sales restore exactly and a manual external price is never overwritten', static function () use ( $suite, $campaign, $product, $selection, $activate, $processor, $repo, $price ): void {
		$item = $product( 'prior sale' ); $item->set_sale_price( '80.00' ); $item->set_date_on_sale_from( time() - HOUR_IN_SECONDS ); $item->set_date_on_sale_to( time() + DAY_IN_SECONDS ); $item->save();
		$before = PriceState::capture( wc_get_product( $item->get_id() ) );
		$id = $campaign( array( 'initial_discount' => '30' ) ); $selection->add( $id, $item->get_id(), '75.00' ); $version = $activate( $id );
		$processor->apply( $id, $item->get_id(), $version );
		$suite->same( '75.00', $price( $item ), 'Original regular base is discounted without stacking and clamps to the floor' );
		$suite->same( 'changed', $processor->restore( $id, $item->get_id() ), 'Restoration changes the owned campaign price' );
		$suite->same( $before, PriceState::capture( wc_get_product( $item->get_id() ) ), 'Regular sale price and both old sale dates restore exactly' );
		$item = $product( 'external change' ); $id = $campaign(); $selection->add( $id, $item->get_id(), '0' ); $version = $activate( $id );
		$processor->apply( $id, $item->get_id(), $version ); $snapshot = $repo->item( $id, $item->get_id() )['snapshot'];
		$external = wc_get_product( $item->get_id() ); $external->set_regular_price( '120.00' ); $external->set_sale_price( '' ); $external->set_price( '120.00' ); $external->save();
		$suite->same( 'error', $processor->restore( $id, $item->get_id() ), 'External modification produces a reviewable conflict' );
		$suite->same( '120.00', $price( $item ), 'External price remains untouched' );
		$suite->same( $snapshot, $repo->item( $id, $item->get_id() )['snapshot'], 'Original snapshot survives the conflict' );
		$selection->resolve( $id, $item->get_id(), 'keep_external' );
	} );

	$suite->test( 'Overlapping draft priority grants one owner and never preempts an existing financial owner', static function () use ( $suite, $campaign, $product, $selection, $repo, $activate, $processor, $price ): void {
		$item = $product( 'priority' ); $low = $campaign( array(), -5, 1 ); $high = $campaign( array( 'initial_discount' => '10' ), -5, 10 );
		$selection->add( $low, $item->get_id(), '0' ); $selection->add( $high, $item->get_id(), '0' ); $lv = $activate( $low ); $hv = $activate( $high );
		$suite->same( $high, $repo->winner_for_product( $item->get_id(), time() )['id'], 'Priority resolves before the first financial write' );
		$suite->same( 'skipped', $processor->apply( $low, $item->get_id(), $lv ), 'Lower-priority candidate does not acquire ownership' );
		$suite->same( 'changed', $processor->apply( $high, $item->get_id(), $hv ), 'Winning candidate applies once' );
		$settings = $repo->get( $low )['settings']; $settings['priority'] = 100; $repo->save( $low, array( 'settings' => $settings ) );
		$suite->same( 'skipped', $processor->apply( $low, $item->get_id(), $lv ), 'Later priority cannot steal a live snapshot owner' );
		$suite->same( '90.00', $price( $item ), 'Discounts are never stacked' );
		$suite->same( null, $repo->item( $low, $item->get_id() )['expected'], 'Losing campaign has no financial ownership' );
		$processor->restore( $high, $item->get_id() );
		$suite->same( 'changed', $processor->apply( $low, $item->get_id(), $lv ), 'Next campaign can apply after original owner restores' );
		$suite->same( '95.00', $price( $item ), 'Successor starts from the restored original base' );
		$processor->restore( $low, $item->get_id() );
	} );

	$suite->test( 'Owned archive and copy are blocked; restored campaign copies and reruns without rewriting old history', static function () use ( $suite, $campaign, $product, $selection, $repo, $activate, $processor, $scheduler, $rest, $raw_item, $price, &$campaigns ): void {
		$id = $campaign(); $item = $product( 'copy restore' ); $selection->add( $id, $item->get_id(), '40.00' ); $version = $activate( $id ); $processor->apply( $id, $item->get_id(), $version );
		$rest( 'campaign/' . $id . '/copy', array(), 400 );
		$rest( 'campaign/' . $id . '/action', array( 'action' => 'archive' ), 400 );
		$scheduler->transition( $id, 'restore' ); $scheduler->batch( $id, $repo->get( $id )['version'], 0, 'restore' );
		$suite->same( 'completed', $repo->get( $id )['status'], 'Restore finishes the old campaign' );
		$old = $raw_item( $id, $item->get_id() );
		$copy = $rest( 'campaign/' . $id . '/copy' ); $new = $copy['campaign']['id']; $campaigns[] = $new;
		$suite->same( true, $new !== $id, 'Rerun receives a new campaign identifier' );
		$job = $copy['job']; for ( $i = 0; ! $job['complete'] && $i < 3; ++$i ) { $job = $rest( 'campaign/' . $new . '/selection-jobs/' . $job['token'] )['job']; }
		$suite->same( 1, $repo->count_items( $new ), 'Copied selection enrolls with the previous safety floor' );
		$suite->same( '40.00', $repo->item( $new, $item->get_id() )['floor_price'], 'Prior floor survives copying' );
		$suite->same( $old, $raw_item( $id, $item->get_id() ), 'Every old financial row byte remains unchanged' );
		$rest( 'campaign/' . $id . '/action', array( 'action' => 'archive' ) );
		$suite->same( 'archived', $repo->get( $id )['status'], 'Unowned old campaign can be archived' );
		$rest( 'campaign/' . $new . '/action', array( 'action' => 'schedule', 'confirm_activate' => true ) );
		$suite->same( 'scheduled', $repo->get( $new )['status'], 'Copied independent draft schedules explicitly' );
		$suite->same( '100.00', $price( $item ), 'Copy and schedule do not change a real price before its start' );
	} );

	$suite->test( 'Server-side scope crosses 200 products, resolves group floors and previews/removes without Woo price saves', static function () use ( $suite, $campaign, $product, $repo, $rest, $bulk, $price ): void {
		$prefix = 'BULK-' . wp_generate_uuid4(); $ids = array();
		for ( $i = 0; $i < 250; ++$i ) { $ids[] = $product( $prefix . '-' . $i )->get_id(); }
		$id = $campaign( array(), HOUR_IN_SECONDS ); $lookup = array_fill_keys( $ids, true ); $writes = 0;
		$watch = static function ( $item ) use ( &$writes, $lookup ): void { if ( isset( $lookup[ $item->get_id() ] ) ) { ++$writes; } };
		add_action( 'woocommerce_before_product_object_save', $watch, PHP_INT_MAX );
		try {
			$filters = array( 'scope' => 'filtered', 'search' => $prefix ); $policy = array( 'mode' => 'percent', 'value' => '20' );
			$preview = $rest( 'campaign/' . $id . '/simulation', array( 'filters' => $filters, 'floor_policy' => $policy ) );
			$suite->same( 250, $preview['count'], 'Preview counts the server scope without returning all products' );
			$suite->same( 5, count( $preview['samples'] ), 'Preview returns only five sample schedules' );
			$job = $rest( 'campaign/' . $id . '/selection-jobs', array( 'filters' => $filters, 'floor_policy' => $policy ) )['job'];
			$maximum = 0;
			for ( $i = 0; ! $job['complete'] && $i < 12; ++$i ) { $before = $job['processed']; $job = $rest( 'campaign/' . $id . '/selection-jobs/' . $job['token'] )['job']; $maximum = max( $maximum, $job['processed'] - $before ); }
			$suite->same( array( true, 250, 0, 250 ), array( $job['complete'], $job['added'], $job['failed'], $repo->count_items( $id ) ), 'All 250 products finish without a browser-side selection ceiling' );
			$suite->same( true, $maximum <= 25, 'Each request processes a bounded product batch' );
			$suite->same( '80.00', $repo->item( $id, $ids[0] )['floor_price'], 'Percentage floor resolves from the immutable base' );
			foreach ( array( array( 'amount', '30', '70.00' ), array( 'shared', '60', '60.00' ), array( 'previous', '0', '80.00' ) ) as $floor ) {
				$suite->same( $floor[2], BulkSelectionService::resolve_floor( $repo, $repo->get( $id ), $ids[0], '100.00', array( 'mode' => $floor[0], 'value' => $floor[1] ), $id ), 'Group floor policy ' . $floor[0] . ' is exact' );
			}
			$job = $bulk->start( $id, array( 'scope' => 'all' ), array( 'mode' => 'none' ), 'remove' );
			for ( $i = 0; ! $job['complete'] && $i < 12; ++$i ) { $job = $bulk->step( $id, $job['token'] ); }
			$suite->same( array( 250, 0 ), array( $job['removed'], $repo->count_items( $id ) ), 'Group removal touches only selected membership' );
			$suite->same( 0, $writes, 'Preview, selection, group floors and removal never save Woo product prices' );
			$suite->same( '100.00', $price( wc_get_product( $ids[249] ) ), 'Last product remains at its original price' );
		} finally { remove_action( 'woocommerce_before_product_object_save', $watch, PHP_INT_MAX ); }
	} );

	$suite->test( 'Hourly queues recover delayed stages and one failed write uses bounded retry with precise reporting', static function () use ( $suite, $campaign, $product, $selection, $repo, $scheduler, $shift, $price, $processor ): void {
		$id = $campaign( array( 'mode' => 'stepped', 'initial_discount' => '5', 'final_discount' => '15' ), 30 * MINUTE_IN_SECONDS ); $item = $product( 'queued hourly' ); $selection->add( $id, $item->get_id(), '0' );
		$scheduler->transition( $id, 'schedule' ); $record = $repo->get( $id ); $version = $record['version']; $at = strtotime( $record['start_utc'] . ' UTC' );
		$suite->same( true, (bool) as_has_scheduled_action( 'exin_bf_stage', array( $id, $version, $at ), 'exin-black-friday' ), 'Real Action Scheduler contains the exact next UTC boundary' );
		$shift( $id, -2 * HOUR_IN_SECONDS - 5 ); $scheduler->stage( $id, $version, strtotime( $repo->get( $id )['start_utc'] . ' UTC' ) ); $scheduler->batch( $id, $version );
		$suite->same( '85.00', $price( $item ), 'Delayed scheduled callback jumps to the current hourly stage' );
		$end = strtotime( $repo->get( $id )['end_utc'] . ' UTC' );
		$suite->same( true, (bool) as_has_scheduled_action( 'exin_bf_stage', array( $id, $version, $end ), 'exin-black-friday' ), 'Last hourly stage retains an exact end callback' );
		$scheduler->recover(); $scheduler->recover();
		$suite->same( '85.00', $price( $item ), 'Queue recovery itself never changes prices' );
		$processor->restore( $id, $item->get_id() );
		$id = $campaign(); $item = $product( 'bounded retry' ); $selection->add( $id, $item->get_id(), '0' ); $repo->save( $id, array( 'status' => 'active' ) ); $version = $repo->get( $id )['version'];
		$fail = static function ( $value ) use ( $item ): void { if ( $value->get_id() === $item->get_id() ) { throw new RuntimeException( 'v110_injected_product_save_failed' ); } };
		add_action( 'woocommerce_before_product_object_save', $fail, PHP_INT_MAX );
		try { $scheduler->batch( $id, $version ); } finally { remove_action( 'woocommerce_before_product_object_save', $fail, PHP_INT_MAX ); }
		$state = $repo->item( $id, $item->get_id() )['retry_state'];
		$suite->same( array( 0, false ), array( $state['attempt'], $state['exhausted'] ), 'First failure persists a bounded budget rather than resetting it' );
		$suite->same( 'v110_injected_product_save_failed', $repo->get( $id )['last_error'], 'Known error code remains traceable instead of internal_operation_error' );
		$suite->same( true, (bool) as_has_scheduled_action( 'exin_bf_retry', array( $id, $item->get_id(), $version, 'apply', 1 ), 'exin-black-friday' ), 'Only the next bounded retry is scheduled' );
		$state['next_at'] = time() - 1; $repo->put_item( $id, $item->get_id(), array( 'retry_state' => $state ) ); $scheduler->retry( $id, $item->get_id(), $version, 'apply', 1 );
		$suite->same( '95.00', $price( $item ), 'One due retry recovers the intended first-stage price' );
		$run = $repo->get( $id )['settings']['last_run'];
		$suite->same( array( 1, 0, null ), array( $run['changed'], $run['errors'], $repo->item( $id, $item->get_id() )['retry_state'] ), 'Successful retry replaces its old error without double-counting' );
		$processor->restore( $id, $item->get_id() );
	} );

	$suite->test( 'REST converts one Persian Toman fixed amount into canonical IRR exactly once', static function () use ( $suite, $rest, $repo, $product, $selection, $price, &$campaigns ): void {
		update_option( 'woocommerce_currency', 'IRR' ); update_option( 'woocommerce_price_num_decimals', 0 );
		try {
			$result = $rest( 'campaign', array( 'title' => 'EXIN110 fixed Toman', 'start_local' => ( new DateTimeImmutable( '+1 hour', new DateTimeZone( 'Asia/Tehran' ) ) )->format( 'Y-m-d\TH:i' ), 'timezone' => 'Asia/Tehran', 'amount_unit' => 'toman', 'settings' => array( 'campaign_type' => 'general', 'discount_plan' => array( 'mode' => 'fixed_amount', 'interval' => 'hourly', 'steps' => 2, 'initial_discount' => '۱۰٬۰۰۰' ) ) ) );
			$id = $result['campaign']['id']; $campaigns[] = $id;
			$suite->same( '100000', $repo->get( $id )['settings']['discount_plan']['initial_discount'], 'Ten thousand Toman is stored as exactly one hundred thousand IRR' );
			$item = $product( 'IRR preview', '1000000' ); $preview = $selection->preview( $id, $item->get_id(), '0' );
			$suite->same( array( '900000', '900000' ), $preview['schedule'], 'Fixed store-unit discount is not converted a second time' );
			$suite->same( '1000000', $price( $item ), 'IRR preview does not change the actual product' );
		} finally { update_option( 'woocommerce_currency', 'USD' ); update_option( 'woocommerce_price_num_decimals', 2 ); }
	} );
} finally {
	foreach ( $campaigns as $id ) {
		$record = $repo->get( $id );
		if ( $record && 'archived' !== $record['status'] ) { $repo->save( $id, array( 'status' => $repo->has_ownership( $id ) ? 'paused' : 'completed' ) ); }
	}
	update_option( 'woocommerce_currency', $old_currency ); update_option( 'woocommerce_price_num_decimals', $old_precision ); wp_set_current_user( $old_user );
}
$failed = count( array_filter( $suite->results, static fn( $item ) => 'failed' === $item['status'] ) );
echo 'EXIN_V110_RESULT ' . wp_json_encode( array( 'suite' => 'v110-financial-integration', 'wordpress' => get_bloginfo( 'version' ), 'woocommerce' => WC_VERSION, 'php' => PHP_VERSION, 'database' => $GLOBALS['wpdb']->db_version(), 'hpos' => \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled(), 'tests' => count( $suite->results ), 'assertions' => $suite->assertions, 'passed' => count( $suite->results ) - $failed, 'failed' => $failed, 'results' => $suite->results ) ) . PHP_EOL;
if ( $failed ) { throw new RuntimeException( 'v110_financial_integration_failed' ); }
