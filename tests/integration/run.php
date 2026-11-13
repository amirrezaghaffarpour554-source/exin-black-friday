<?php
/** Real WordPress/WooCommerce tests. Run only inside scripts/test-runtime.sh's isolated DB. */

use Exin\BlackFriday\Application\PriceProcessor;
use Exin\BlackFriday\Application\PriceState;
use Exin\BlackFriday\Application\ProductSelectionService;
use Exin\BlackFriday\Application\Scheduler;
use Exin\BlackFriday\Domain\Timeline;
use Exin\BlackFriday\Infrastructure\CampaignRepository;
use Exin\BlackFriday\Infrastructure\LockManager;
use Exin\BlackFriday\Infrastructure\Logger;
use Exin\BlackFriday\Infrastructure\Migration;
use Exin\BlackFriday\Integration\Shipping;
use Exin\BlackFriday\Integration\Orders;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || wp_get_environment_type() !== 'local' || DB_NAME !== 'exin_test' ) {
	throw new RuntimeException( 'Tests require the isolated exin_test database and local environment.' );
}
if ( ! class_exists( CampaignRepository::class ) || ! class_exists( 'WooCommerce' ) ) {
	throw new RuntimeException( 'Activate WooCommerce and EXIN before running integration tests.' );
}
add_filter( 'action_scheduler_allow_async_request_runner', '__return_false' );
add_filter( 'pre_wp_mail', '__return_true' );
update_option( 'woocommerce_currency', 'USD' );
update_option( 'woocommerce_price_num_decimals', 2 );
wc_load_cart();
$GLOBALS['test_results'] = array();
$GLOBALS['test_assertions'] = 0;
$test_started = microtime( true );
function exin_assert( $condition, string $message ): void {
	global $test_assertions;
	++$test_assertions;
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}
function exin_test( string $name, callable $callback ): void {
	global $test_results;
	$start = microtime( true );
	try {
		$callback();
		$test_results[] = array( 'test' => $name, 'status' => 'passed', 'seconds' => round( microtime( true ) - $start, 4 ) );
		echo "PASS $name\n";
	} catch ( Throwable $error ) {
		$test_results[] = array( 'test' => $name, 'status' => 'failed', 'error' => $error->getMessage(), 'seconds' => round( microtime( true ) - $start, 4 ) );
		echo "FAIL $name: {$error->getMessage()}\n";
	}
}
function exin_throws( callable $callback, string $contains ): void {
	try {
		$callback();
	} catch ( Throwable $error ) {
		exin_assert( str_contains( $error->getMessage(), $contains ), 'Unexpected error: ' . $error->getMessage() );
		return;
	}
	exin_assert( false, 'Expected error containing ' . $contains );
}
function exin_product( string $name, string $price = '100.00' ): WC_Product_Simple {
	$product = new WC_Product_Simple();
	$product->set_name( 'EXIN TEST ' . $name );
	$product->set_sku( 'exin-test-' . wp_generate_uuid4() );
	$product->set_status( 'publish' );
	$product->set_regular_price( $price );
	$product->set_manage_stock( true );
	$product->set_stock_quantity( 100 );
	$product->save();
	return $product;
}
function exin_price( int $product_id ): string {
	return PriceState::money( (string) wc_get_product( $product_id )->get_price( 'edit' ) );
}
function exin_rates(): array {
	return array(
		'flat_rate:11' => new WC_Shipping_Rate( 'flat_rate:11', 'Allowed', '12.00', array( 1 => '1.20' ), 'flat_rate', 11 ),
		'flat_rate:12' => new WC_Shipping_Rate( 'flat_rate:12', 'Other flat', '15.00', array(), 'flat_rate', 12 ),
		'local_pickup:5' => new WC_Shipping_Rate( 'local_pickup:5', 'Pickup', '3.00', array(), 'local_pickup', 5 ),
	);
}
function exin_line( WC_Product $product, string $total, int $quantity = 1 ): array {
	return array( 'data' => $product, 'product_id' => $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id(), 'variation_id' => $product->is_type( 'variation' ) ? $product->get_id() : 0, 'quantity' => $quantity, 'line_total' => $total, 'line_subtotal' => $total );
}

$repo = new CampaignRepository();
$logger = new Logger();
$selection = new ProductSelectionService( $repo );
$processor = new PriceProcessor( $repo, $logger );
$scheduler = new Scheduler( $repo, $processor, $logger );
Migration::run();
$campaign_id = $repo->create( array( 'title' => 'EXIN TEST integration', 'start_utc' => gmdate( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS ), 'end_utc' => gmdate( 'Y-m-d H:i:s', time() + 4 * DAY_IN_SECONDS ), 'timezone' => 'Asia/Tehran', 'days' => 7, 'status' => 'draft' ) );
$simple = exin_product( 'simple' );
$restore_product = exin_product( 'restore' );
$external_product = exin_product( 'external' );
$crash_product = exin_product( 'interrupted' );
$regular = exin_product( 'noncampaign' );

exin_test( 'HPOS is the authoritative order store', static function () {
	exin_assert( \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled(), 'HPOS not enabled' );
} );
exin_test( 'Migration is idempotent and preserves campaign rows', static function () use ( $repo, $campaign_id ) {
	Migration::run();
	exin_assert( $repo->get( $campaign_id )['title'] === 'EXIN TEST integration', 'Migration lost campaign' );
} );
exin_test( 'Simple selection has immutable original snapshot and seven-day schedule', static function () use ( $selection, $repo, $campaign_id, $simple, $restore_product, $external_product, $crash_product ) {
	foreach ( array( $simple, $restore_product, $external_product, $crash_product ) as $product ) {
		$item = $selection->add( $campaign_id, $product->get_id(), '40.00' );
		exin_assert( $item['snapshot']['regular'] === '100.00' && $item['snapshot']['sale'] === '', 'Invalid original snapshot' );
		exin_assert( $item['schedule'] === array( '100.00', '90.00', '80.00', '70.00', '60.00', '50.00', '40.00' ), 'Wrong seven-day schedule' );
	}
	exin_assert( $repo->count_items( $campaign_id ) === 4, 'Wrong item count' );
} );
exin_test( 'Server rejects invalid floors and grouped products', static function () use ( $selection, $campaign_id ) {
	$p = exin_product( 'invalid-floor' );
	exin_throws( static fn() => $selection->add( $campaign_id, $p->get_id(), '0' ), 'floor' );
	exin_throws( static fn() => $selection->add( $campaign_id, $p->get_id(), '101' ), 'floor' );
	$grouped = new WC_Product_Grouped();
	$grouped->set_name( 'EXIN TEST grouped' );
	$grouped->set_status( 'publish' );
	$grouped->save();
	exin_throws( static fn() => $selection->add( $campaign_id, $grouped->get_id(), '40' ), 'unsupported' );
} );
exin_test( 'Existing and scheduled sales are rejected without mutation', static function () use ( $selection, $campaign_id ) {
	$p = exin_product( 'prior-sale' );
	$p->set_sale_price( '90' );
	$p->save();
	exin_throws( static fn() => $selection->add( $campaign_id, $p->get_id(), '40' ), 'sale_conflict' );
	exin_assert( exin_price( $p->get_id() ) === '90.00', 'Prior sale overwritten' );
	$p->set_date_on_sale_from( time() + DAY_IN_SECONDS );
	$p->set_date_on_sale_to( time() + 2 * DAY_IN_SECONDS );
	$p->save();
	exin_throws( static fn() => $selection->add( $campaign_id, $p->get_id(), '40' ), 'sale_conflict' );
	exin_assert( wc_get_product( $p->get_id() )->get_date_on_sale_from()->getTimestamp() > time(), 'Scheduled sale mutated' );
} );
exin_test( 'Known dynamic pricing conflicts are rejected', static function () use ( $selection, $campaign_id ) {
	$p = exin_product( 'dynamic' );
	$filter = static fn( $price, $product ) => $product->get_id() === $p->get_id() ? '80' : $price;
	add_filter( 'woocommerce_product_get_price', $filter, 20, 2 );
	try {
		exin_throws( static fn() => $selection->add( $campaign_id, $p->get_id(), '40' ), 'dynamic' );
	} finally {
		remove_filter( 'woocommerce_product_get_price', $filter, 20 );
	}
} );

$parent = new WC_Product_Variable();
$parent->set_name( 'EXIN TEST variable' );
$parent->set_status( 'publish' );
$attribute = new WC_Product_Attribute();
$attribute->set_name( 'Color' );
$attribute->set_options( array( 'Blue' ) );
$attribute->set_variation( true );
$parent->set_attributes( array( $attribute ) );
$parent->save();
$variation = new WC_Product_Variation();
$variation->set_parent_id( $parent->get_id() );
$variation->set_attributes( array( 'color' => 'Blue' ) );
$variation->set_status( 'publish' );
$variation->set_regular_price( '100.00' );
$variation->set_manage_stock( true );
$variation->set_stock_quantity( 10 );
$variation->save();
exin_test( 'Variation is enrolled independently; variable parent is rejected', static function () use ( $selection, $campaign_id, $variation, $parent ) {
	$item = $selection->add( $campaign_id, $variation->get_id(), '40.00' );
	exin_assert( $item['product_id'] === $variation->get_id(), 'Variation not independently saved' );
	exin_throws( static fn() => $selection->add( $campaign_id, $parent->get_id(), '40' ), 'unsupported' );
} );

$repo->save( $campaign_id, array( 'status' => 'active' ) );
$version = $repo->get( $campaign_id )['version'];
exin_test( 'Delayed job applies current day directly to simple and variation', static function () use ( $repo, $processor, $campaign_id, $version, $simple, $variation ) {
	exin_assert( ( new Timeline() )->day_index( $repo->get( $campaign_id ), time() ) === 3, 'Unexpected test campaign day' );
	foreach ( array( $simple, $variation ) as $p ) {
		$processor->apply( $campaign_id, $p->get_id(), $version );
		exin_assert( exin_price( $p->get_id() ) === '70.00', 'Delayed job used missed-day price' );
		exin_assert( $repo->item( $campaign_id, $p->get_id() )['status'] === 'active', 'Item not active' );
	}
	exin_assert( $repo->item( $campaign_id, $simple->get_id() )['snapshot']['regular'] === '100.00', 'Snapshot overwritten' );
} );
exin_test( 'Duplicate job preserves price, stock and snapshot', static function () use ( $repo, $processor, $campaign_id, $version, $simple ) {
	$snapshot = $repo->item( $campaign_id, $simple->get_id() )['snapshot'];
	$operation = $repo->item( $campaign_id, $simple->get_id() )['operation_id'];
	$cache_version = get_option( 'exin_bf_cache_version' );
	$processor->apply( $campaign_id, $simple->get_id(), $version );
	$processor->apply( $campaign_id, $simple->get_id(), $version );
	exin_assert( exin_price( $simple->get_id() ) === '70.00', 'Duplicate changed amount' );
	exin_assert( wc_get_product( $simple->get_id() )->get_stock_quantity() === 100, 'Campaign changed stock' );
	exin_assert( $repo->item( $campaign_id, $simple->get_id() )['snapshot'] === $snapshot, 'Snapshot changed' );
	exin_assert( $repo->item( $campaign_id, $simple->get_id() )['operation_id'] === $operation, 'No-op job created a financial operation' );
	exin_assert( get_option( 'exin_bf_cache_version' ) === $cache_version, 'No-op job invalidated cache unnecessarily' );
} );
exin_test( 'Pause blocks stale jobs without restoring; resume invalidates old jobs', static function () use ( $repo, $scheduler, $processor, $campaign_id, $version, $simple ) {
	$scheduler->transition( $campaign_id, 'pause' );
	$processor->apply( $campaign_id, $simple->get_id(), $version );
	exin_assert( exin_price( $simple->get_id() ) === '70.00', 'Pause restored unexpectedly' );
	exin_assert( $repo->get( $campaign_id )['status'] === 'paused', 'Campaign not paused' );
	$scheduler->transition( $campaign_id, 'resume' );
	exin_assert( $repo->get( $campaign_id )['version'] > $version, 'Resume did not invalidate old jobs' );
	$processor->apply( $campaign_id, $simple->get_id(), $version );
	exin_assert( exin_price( $simple->get_id() ) === '70.00', 'Stale job changed price' );
} );
$version = $repo->get( $campaign_id )['version'];
exin_test( 'Restore is repeatable and restores only owned price', static function () use ( $repo, $processor, $campaign_id, $version, $restore_product ) {
	$processor->apply( $campaign_id, $restore_product->get_id(), $version );
	exin_assert( exin_price( $restore_product->get_id() ) === '70.00', 'Price not applied' );
	$processor->restore( $campaign_id, $restore_product->get_id() );
	$processor->restore( $campaign_id, $restore_product->get_id() );
	exin_assert( exin_price( $restore_product->get_id() ) === '100.00', 'Original price not restored' );
	exin_assert( wc_get_product( $restore_product->get_id() )->get_sale_price( 'edit' ) === '', 'Sale not cleared' );
	exin_assert( $repo->item( $campaign_id, $restore_product->get_id() )['status'] === 'completed', 'Restore not completed' );
} );
exin_test( 'Manual price change causes conflict and restore preserves it', static function () use ( $repo, $processor, $campaign_id, $version, $external_product ) {
	$processor->apply( $campaign_id, $external_product->get_id(), $version );
	$p = wc_get_product( $external_product->get_id() );
	$p->set_sale_price( '65.00' );
	$p->save();
	$processor->restore( $campaign_id, $external_product->get_id() );
	exin_assert( exin_price( $external_product->get_id() ) === '65.00', 'External edit overwritten' );
	exin_assert( $repo->item( $campaign_id, $external_product->get_id() )['status'] === 'conflict', 'Missing conflict' );
} );
exin_test( 'Crash after Woo save recovers durable intent without duplicate price reduction', static function () use ( $repo, $processor, $campaign_id, $version, $crash_product ) {
	$fail = static function ( $p ) use ( $crash_product ) {
		if ( $p->get_id() === $crash_product->get_id() ) {
			throw new RuntimeException( 'simulated_process_interruption' );
		}
	};
	add_action( 'woocommerce_after_product_object_save', $fail, 10000 );
	try {
		exin_throws( static fn() => $processor->apply( $campaign_id, $crash_product->get_id(), $version ), 'simulated_process_interruption' );
	} finally {
		remove_action( 'woocommerce_after_product_object_save', $fail, 10000 );
	}
	exin_assert( ! empty( $repo->item( $campaign_id, $crash_product->get_id() )['intent'] ), 'No durable recovery intent' );
	$processor->apply( $campaign_id, $crash_product->get_id(), $version );
	exin_assert( exin_price( $crash_product->get_id() ) === '70.00', 'Recovery applied wrong price' );
	exin_assert( $repo->item( $campaign_id, $crash_product->get_id() )['intent'] === null, 'Recovery intent not settled' );
} );
exin_test( 'Audit database failure retains durable intent and safe restoration recovers', static function () use ( $repo, $selection, $processor, $campaign_id, $version ) {
	global $wpdb;
	$p = exin_product( 'audit-outage' );
	$selection->add( $campaign_id, $p->get_id(), '40.00' );
	$fail_audit = static function ( $sql ) use ( $wpdb ) {
		if ( preg_match( '/^INSERT\s+INTO\s+`?' . preg_quote( $wpdb->prefix . 'exin_bf_audit', '/' ) . '`?\s/i', $sql ) ) {
			return str_replace( $wpdb->prefix . 'exin_bf_audit', $wpdb->prefix . 'exin_bf_deliberately_missing_audit', $sql );
		}
		return $sql;
	};
	$was_suppressed = $wpdb->suppress_errors( true );
	add_filter( 'query', $fail_audit );
	try {
		exin_throws( static fn() => $processor->apply( $campaign_id, $p->get_id(), $version ), 'audit_write_failed' );
	} finally {
		remove_filter( 'query', $fail_audit );
		$wpdb->suppress_errors( $was_suppressed );
	}
	exin_assert( exin_price( $p->get_id() ) === '70.00', 'Fault injection did not occur after Woo price save' );
	exin_assert( ! empty( $repo->item( $campaign_id, $p->get_id() )['intent'] ), 'Audit outage lost recovery intent' );
	$processor->restore( $campaign_id, $p->get_id() );
	exin_assert( exin_price( $p->get_id() ) === '100.00' && $repo->item( $campaign_id, $p->get_id() )['intent'] === null, 'Recovery after audit restoration failed' );
} );
exin_test( 'Atomic lock excludes independent database connection and releases', static function () {
	global $wpdb;
	$manager = new LockManager();
	$name = $manager->acquire( 'integration-concurrent-product' );
	$other = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
	try {
		exin_assert( (string) $other->get_var( $other->prepare( 'SELECT GET_LOCK(%s,0)', $name ) ) === '0', 'Concurrent connection acquired exclusive lock' );
	} finally {
		$manager->release( $name );
	}
	exin_assert( (string) $other->get_var( $other->prepare( 'SELECT GET_LOCK(%s,0)', $name ) ) === '1', 'Lock not released' );
	$other->get_var( $other->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
	$other->close();
} );
exin_test( 'Lost connection-scoped lock fails closed on reentrant acquire', static function () {
	global $wpdb;
	$manager = new LockManager();
	$name = $manager->acquire( 'integration-lock-loss' );
	$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
	exin_throws( static fn() => $manager->acquire( 'integration-lock-loss' ), 'lock_connection_lost' );
	$manager->release( $name );
} );
exin_test( 'Corrupted stored schedule cannot apply below floor or above base', static function () use ( $repo, $selection, $processor, $campaign_id, $version ) {
	foreach ( array( '1.00', '200.00' ) as $invalid ) {
		$p = exin_product( 'corrupt-schedule' );
		$item = $selection->add( $campaign_id, $p->get_id(), '40.00' );
		$item['schedule'][3] = $invalid;
		$repo->put_item( $campaign_id, $p->get_id(), array( 'schedule' => $item['schedule'] ) );
		$processor->apply( $campaign_id, $p->get_id(), $version );
		exin_assert( exin_price( $p->get_id() ) === '100.00', 'Corrupted schedule changed price' );
		exin_assert( $repo->item( $campaign_id, $p->get_id() )['status'] === 'conflict', 'Corrupt schedule not flagged' );
	}
} );
exin_test( 'Deleted product produces a recorded conflict', static function () use ( $repo, $selection, $processor, $campaign_id, $version ) {
	$p = exin_product( 'deleted' );
	$id = $p->get_id();
	$selection->add( $campaign_id, $id, '40.00' );
	$p->delete( true );
	$processor->apply( $campaign_id, $id, $version );
	exin_assert( $repo->item( $campaign_id, $id )['status'] === 'conflict', 'Deleted product silently skipped' );
} );
exin_test( 'Out-of-stock and backorders follow Woo stock settings without mutation', static function () use ( $repo, $selection, $processor, $campaign_id, $version ) {
	foreach ( array( 'no', 'yes' ) as $backorders ) {
		$p = exin_product( 'stock-' . $backorders );
		$p->set_stock_quantity( 0 );
		$p->set_backorders( $backorders );
		$p->set_stock_status( 'yes' === $backorders ? 'onbackorder' : 'outofstock' );
		$p->save();
		$selection->add( $campaign_id, $p->get_id(), '40.00' );
		$processor->apply( $campaign_id, $p->get_id(), $version );
		$fresh = wc_get_product( $p->get_id() );
		exin_assert( $fresh->get_stock_quantity() === 0 && $fresh->get_backorders() === $backorders, 'Stock/backorders changed by price engine' );
		exin_assert( $repo->item( $campaign_id, $p->get_id() )['status'] === ( 'yes' === $backorders ? 'active' : 'sold_out' ), 'Wrong stock presentation state' );
	}
} );

$campaign = $repo->get( $campaign_id );
$settings = $campaign['settings'];
$settings['free_shipping'] = true;
$settings['shipping_threshold'] = '70.00';
$settings['shipping_instances'] = array( 11 );
$repo->save( $campaign_id, array( 'settings' => $settings ) );
$shipping = new Shipping( $repo );
exin_test( 'Shipping excludes ordinary cart and mixed-cart ordinary totals', static function () use ( $shipping, $simple, $regular ) {
	$r = $shipping->rates( exin_rates(), array( 'contents' => array( exin_line( $regular, '500.00' ) ) ) );
	exin_assert( (string) $r['flat_rate:11']->get_cost() === '12.00', 'Ordinary cart received free shipping' );
	$r = $shipping->rates( exin_rates(), array( 'contents' => array( exin_line( wc_get_product( $simple->get_id() ), '60.00' ), exin_line( $regular, '500.00' ) ) ) );
	exin_assert( (string) $r['flat_rate:11']->get_cost() === '12.00', 'Mixed cart counted ordinary totals' );
} );
exin_test( 'Shipping at threshold affects only allowed flat-rate instance and tax', static function () use ( $shipping, $simple ) {
	$r = $shipping->rates( exin_rates(), array( 'contents' => array( exin_line( wc_get_product( $simple->get_id() ), '70.00' ) ) ) );
	exin_assert( (float) $r['flat_rate:11']->get_cost() === 0.0 && array_sum( $r['flat_rate:11']->get_taxes() ) === 0, 'Eligible shipping or shipping tax not free' );
	exin_assert( (string) $r['flat_rate:12']->get_cost() === '15.00', 'Unauthorized flat rate changed' );
	exin_assert( (string) $r['local_pickup:5']->get_cost() === '3.00', 'Nonflat method changed' );
} );
exin_test( 'Shipping uses post-coupon total, quantity/removal, and package-local threshold', static function () use ( $shipping, $simple ) {
	$p = wc_get_product( $simple->get_id() );
	foreach ( array( array( '60.00', 1, false ), array( '140.00', 2, true ), array( '100.00', 0, false ), array( '35.00', 1, false ) ) as $case ) {
		$r = $shipping->rates( exin_rates(), array( 'contents' => array( exin_line( $p, $case[0], $case[1] ) ) ) );
		exin_assert( ( (float) $r['flat_rate:11']->get_cost() === 0.0 ) === $case[2], 'Wrong coupon/quantity/package decision' );
	}
} );

exin_test( 'Anonymous, invalid nonce and insufficient capability cannot read admin data', static function () {
	wp_set_current_user( 0 );
	$request = new WP_REST_Request( 'GET', '/exin-bf/v1/admin/state' );
	exin_assert( rest_do_request( $request )->get_status() === 403, 'Anonymous admin data access' );
	wp_set_current_user( 1 );
	$request->set_header( 'X-WP-Nonce', 'invalid' );
	exin_assert( rest_do_request( $request )->get_status() === 403, 'Invalid nonce accepted' );
	$user_id = wp_create_user( 'exin_subscriber_' . time(), 'local-only-test' );
	wp_set_current_user( $user_id );
	$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
	exin_assert( rest_do_request( $request )->get_status() === 403, 'Subscriber admin data access' );
	wp_set_current_user( 0 );
} );
exin_test( 'Admin nonce grants access and rejects malicious financial payload', static function () {
	wp_set_current_user( 1 );
	$request = new WP_REST_Request( 'GET', '/exin-bf/v1/admin/state' );
	$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
	exin_assert( rest_do_request( $request )->get_status() === 200, 'Authorized state inaccessible' );
	$request = new WP_REST_Request( 'POST', '/exin-bf/v1/admin/campaign' );
	$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
	$request->set_header( 'Content-Type', 'application/json' );
	$request->set_body( wp_json_encode( array( 'title' => '<script>alert(1)</script>', 'days' => "1;DROP TABLE", 'status' => 'active' ) ) );
	exin_assert( rest_do_request( $request )->get_status() === 400, 'Invalid/mass assignment payload accepted' );
	wp_set_current_user( 0 );
} );
exin_test( 'Restore requires strict explicit confirmation and item requests reject IDOR/types', static function () use ( $repo, $campaign_id, $simple ) {
	wp_set_current_user( 1 );
	foreach ( array( array(), array( 'confirm_restore' => 'true' ), array( 'confirm_restore' => 1 ) ) as $extra ) {
		$request = new WP_REST_Request( 'POST', '/exin-bf/v1/admin/campaign/' . $campaign_id . '/action' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( array_merge( array( 'action' => 'restore' ), $extra ) ) );
		exin_assert( rest_do_request( $request )->get_status() === 400, 'Restore accepted implicit confirmation' );
	}
	exin_assert( $repo->get( $campaign_id )['status'] === 'active', 'Rejected restore changed campaign status' );
	$request = new WP_REST_Request( 'POST', '/exin-bf/v1/admin/campaign/' . $campaign_id . '/item/99999999' );
	$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
	$request->set_header( 'Content-Type', 'application/json' );
	$request->set_body( '{"action":"update","featured":true}' );
	exin_assert( rest_do_request( $request )->get_status() === 400, 'Nonmember item ID accepted' );
	foreach ( array( array( 'floor_price' => array( '40' ) ), array( 'product_id' => '1e3' ), array( 'floor_price' => '40.001' ) ) as $invalid ) {
		$request = new WP_REST_Request( 'POST', '/exin-bf/v1/admin/campaign/' . $campaign_id . '/preview' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( array_merge( array( 'product_id' => $simple->get_id(), 'floor_price' => '40.00' ), $invalid ) ) );
		exin_assert( rest_do_request( $request )->get_status() === 400, 'Invalid financial field type/precision accepted' );
	}
	wp_set_current_user( 0 );
} );

exin_test( 'Shortcodes render only selected current products without confidential fields', static function () use ( $simple, $regular ) {
	$html = do_shortcode( '[exin_bf_products limit="20"]' );
	exin_assert( str_contains( $html, 'EXIN TEST simple' ), 'Selected product absent from campaign page' );
	exin_assert( ! str_contains( $html, 'EXIN TEST noncampaign' ), 'Nonselected product leaked into campaign page' );
	foreach ( array( 'floor_price', 'snapshot', 'operation_id', 'schedule', '40.00' ) as $private ) {
		exin_assert( ! str_contains( $html, $private ), 'Private data in public HTML: ' . $private );
	}
	foreach ( array( 'exin_bf_featured', 'exin_bf_countdown', 'exin_bf_categories', 'exin_bf_status' ) as $tag ) {
		exin_assert( shortcode_exists( $tag ), 'Shortcode missing: ' . $tag );
		do_shortcode( '[' . $tag . ']' );
	}
} );
exin_test( 'Shortcode pagination uses request page and renders each selected product context', static function () use ( $repo, $campaign_id ) {
	$query = new \Exin\BlackFriday\Integration\PublicProductQuery( $repo );
	$campaign = $repo->get( $campaign_id );
	$page_one = $query->query( $campaign, array( 'limit' => 1, 'page' => 1 ) );
	$page_two = $query->query( $campaign, array( 'limit' => 1, 'page' => 2 ) );
	exin_assert( count( $page_one['products'] ) === 1 && count( $page_two['products'] ) === 1, 'Need two paginated selected products' );
	exin_assert( $page_one['products'][0]->get_id() !== $page_two['products'][0]->get_id(), 'Pagination repeated first product' );
	$previous_page = $_GET['exin_bf_page'] ?? null;
	$previous_product = $GLOBALS['product'] ?? null;
	try {
		$_GET['exin_bf_page'] = 1;
		$html_one = do_shortcode( '[exin_bf_products limit="1"]' );
		$_GET['exin_bf_page'] = 2;
		$html_two = do_shortcode( '[exin_bf_products limit="1"]' );
		exin_assert( str_contains( $html_one, esc_html( $page_one['products'][0]->get_name() ) ), 'First page rendered wrong Woo global product' );
		exin_assert( str_contains( $html_two, esc_html( $page_two['products'][0]->get_name() ) ), 'Second page ignored request page/Woo product context' );
		exin_assert( ! str_contains( $html_two, '<h2 class="woocommerce-loop-product__title">' . esc_html( $page_one['products'][0]->get_name() ) . '</h2>' ), 'Second page included first-page product' );
		exin_assert( ( $GLOBALS['product'] ?? null ) === $previous_product, 'Shortcode leaked its Woo global product context' );
	} finally {
		if ( null === $previous_page ) { unset( $_GET['exin_bf_page'] ); } else { $_GET['exin_bf_page'] = $previous_page; }
	}
} );
exin_test( 'Public countdown endpoint exposes server time and only presentation fields', static function () {
	wp_set_current_user( 0 );
	$response = rest_do_request( new WP_REST_Request( 'GET', '/exin-bf/v1/time' ) );
	exin_assert( $response->get_status() === 200, 'Time route unavailable' );
	$data = $response->get_data();
	exin_assert( abs( $data['server_time'] - time() ) <= 2 && $data['active'], 'Invalid server clock' );
	exin_assert( array_diff( array_keys( $data ), array( 'server_time', 'active', 'campaign_id', 'ends_at' ) ) === array(), 'Unexpected public financial fields' );
	exin_assert( str_contains( $response->get_headers()['Cache-Control'], 'no-store' ), 'Time endpoint cacheable' );
} );

$classic_order = null;
exin_test( 'Classic checkout persists immutable campaign attribution on mixed multitem order', static function () use ( $campaign_id, $simple, $variation, $regular, &$classic_order ) {
	WC()->cart->empty_cart();
	WC()->cart->add_to_cart( $simple->get_id(), 2 );
	WC()->cart->add_to_cart( $variation->get_parent_id(), 1, $variation->get_id(), $variation->get_variation_attributes() );
	WC()->cart->add_to_cart( $regular->get_id(), 1 );
	WC()->cart->calculate_totals();
	$id = WC()->checkout()->create_order( array( 'billing_first_name' => 'Local', 'billing_last_name' => 'Fixture', 'billing_email' => 'local@example.invalid', 'billing_country' => 'US', 'billing_address_1' => 'Test', 'billing_city' => 'Test', 'billing_state' => 'CA', 'billing_postcode' => '90210', 'payment_method' => 'bacs' ) );
	exin_assert( ! is_wp_error( $id ), is_wp_error( $id ) ? $id->get_error_message() : 'Checkout failed' );
	$classic_order = wc_get_order( $id );
	$classic_order->set_status( 'processing' );
	$classic_order->save();
	$attributed = 0;
	foreach ( $classic_order->get_items() as $item ) {
		if ( (int) $item->get_meta( '_exin_bf_campaign_id' ) === $campaign_id ) {
			++$attributed;
			exin_assert( $item->get_meta( '_exin_bf_product_id' ) > 0, 'Missing sellable ID attribution' );
			foreach ( $item->get_meta_data() as $meta ) {
				exin_assert( ! str_contains( $meta->key, 'floor' ) && ! str_contains( $meta->key, 'schedule' ), 'Sensitive item metadata' );
			}
		}
	}
	exin_assert( $attributed === 2, 'Expected two attributed line items' );
	exin_assert( (string) $classic_order->get_total() === '310.00', 'Unexpected mixed order total' );
} );
exin_test( 'HPOS report counts multitem order once and separates campaign item revenue', static function () use ( $repo, $campaign_id ) {
	wp_cache_delete( 'analytics:' . $campaign_id, 'exin_bf' );
	$data = ( new Orders( $repo ) )->analytics( $campaign_id );
	exin_assert( $data['orders'] === 1 && $data['units'] === 3, 'Wrong order or campaign unit count' );
	exin_assert( $data['item_revenue'] === '210.00' && $data['order_revenue'] === '310.00', 'Campaign revenue confused with full order' );
} );
exin_test( 'Failed/cancelled orders excluded, repeated payment callback does not duplicate reports', static function () use ( $repo, $campaign_id, &$classic_order ) {
	foreach ( array( 'failed', 'cancelled' ) as $status ) {
		$id = WC()->checkout()->create_order( array( 'billing_email' => 'local@example.invalid', 'payment_method' => 'bacs' ) );
		exin_assert( ! is_wp_error( $id ), 'Fixture checkout failed' );
		$order = wc_get_order( $id );
		$order->set_status( $status );
		$order->save();
	}
	$classic_order->payment_complete( 'local-repeat-test' );
	$classic_order->payment_complete( 'local-repeat-test' );
	wp_cache_delete( 'analytics:' . $campaign_id, 'exin_bf' );
	$data = ( new Orders( $repo ) )->analytics( $campaign_id );
	exin_assert( $data['orders'] === 1, 'Failed/cancelled/repeated callbacks changed attribution count' );
} );
exin_test( 'Partial line refund reduces campaign revenue and units through real Woo refund CRUD', static function () use ( $repo, $campaign_id, $simple, &$classic_order ) {
	$line_id = 0;
	foreach ( $classic_order->get_items() as $id => $item ) {
		if ( $item->get_product_id() === $simple->get_id() ) {
			$line_id = $id;
		}
	}
	$refund = wc_create_refund( array( 'order_id' => $classic_order->get_id(), 'amount' => '70.00', 'reason' => 'local integration fixture', 'refund_payment' => false, 'restock_items' => true, 'line_items' => array( $line_id => array( 'qty' => 1, 'refund_total' => '70.00', 'refund_tax' => array() ) ) ) );
	exin_assert( ! is_wp_error( $refund ), is_wp_error( $refund ) ? $refund->get_error_message() : 'Refund failed' );
	wp_cache_delete( 'analytics:' . $campaign_id, 'exin_bf' );
	$data = ( new Orders( $repo ) )->analytics( $campaign_id );
	exin_assert( $data['orders'] === 1 && $data['units'] === 2, 'Refund count/units wrong' );
	exin_assert( $data['item_revenue'] === '140.00' && $data['order_revenue'] === '240.00', 'Refund revenue wrong' );
} );
exin_test( 'Blocks checkout lifecycle attributes real draft order and remains immutable', static function () use ( $campaign_id, $simple, $repo ) {
	$order = wc_create_order( array( 'status' => 'checkout-draft' ) );
	$id = $order->add_product( wc_get_product( $simple->get_id() ), 1 );
	$order->calculate_totals();
	do_action( 'woocommerce_store_api_checkout_update_order_from_request', $order, new WP_REST_Request( 'POST', '/wc/store/v1/checkout' ) );
	$item = $order->get_item( $id );
	exin_assert( ! $item->meta_exists( '_exin_bf_campaign_id' ), 'Unsubmitted Blocks draft should not be attributed' );
	$order->set_status( 'pending' );
	$order->save();
	do_action( 'woocommerce_store_api_checkout_order_processed', $order );
	$item = $order->get_item( $id );
	exin_assert( (int) $item->get_meta( '_exin_bf_campaign_id' ) === $campaign_id, 'Blocks item unattributed' );
	$before = $item->get_meta( '_exin_bf_campaign_id' );
	do_action( 'woocommerce_store_api_checkout_order_processed', $order );
	exin_assert( $item->get_meta( '_exin_bf_campaign_id' ) === $before, 'Repeated Blocks hook changed attribution' );
	$order->set_status( 'cancelled' );
	$order->save();
} );
exin_test( 'Later campaign price job never changes already saved order totals', static function () use ( $processor, $campaign_id, $version, $simple, &$classic_order ) {
	$before = wc_get_order( $classic_order->get_id() )->get_total();
	$processor->apply( $campaign_id, $simple->get_id(), $version );
	exin_assert( wc_get_order( $classic_order->get_id() )->get_total() === $before, 'Campaign repriced a saved order' );
} );
exin_test( 'Real Action Scheduler worker reaches final-day floor and natural end restores', static function () use ( $repo, $selection, $scheduler ) {
	$id = $repo->create( array( 'title' => 'EXIN TEST scheduler worker', 'start_utc' => gmdate( 'Y-m-d H:i:s', time() - 6 * DAY_IN_SECONDS ), 'end_utc' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ), 'status' => 'draft' ) );
	$p = exin_product( 'final-day' );
	$selection->add( $id, $p->get_id(), '40.00' );
	$scheduler->transition( $id, 'start' );
	$version = $repo->get( $id )['version'];
	$actions = as_get_scheduled_actions( array( 'hook' => 'exin_bf_batch', 'args' => array( $id, $version, 0, 'apply' ), 'status' => 'pending', 'per_page' => 1 ), 'ids' );
	exin_assert( count( $actions ) === 1, 'No unique apply action' );
	ActionScheduler_QueueRunner::instance()->process_action( $actions[0], 'EXIN integration test' );
	exin_assert( ActionScheduler_Store::instance()->get_status( $actions[0] ) === 'complete', 'Real action failed' );
	exin_assert( exin_price( $p->get_id() ) === '40.00', 'Final day did not reach floor' );
	$repo->save( $id, array( 'start_utc' => gmdate( 'Y-m-d H:i:s', time() - 8 * DAY_IN_SECONDS ), 'end_utc' => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ) );
	$scheduler->tick();
	$campaign = $repo->get( $id );
	exin_assert( ! empty( $campaign['settings']['restoring'] ), 'Natural end did not enter restoration' );
	$actions = as_get_scheduled_actions( array( 'hook' => 'exin_bf_batch', 'args' => array( $id, $campaign['version'], 0, 'restore' ), 'status' => 'pending', 'per_page' => 1 ), 'ids' );
	exin_assert( count( $actions ) === 1, 'No unique restore action' );
	ActionScheduler_QueueRunner::instance()->process_action( $actions[0], 'EXIN integration test' );
	exin_assert( exin_price( $p->get_id() ) === '100.00', 'Natural end did not restore' );
	exin_assert( $repo->get( $id )['status'] === 'completed', 'Natural end not completed' );
} );
exin_test( 'Failed job schedules bounded retry and real retry worker recovers intent', static function () use ( $repo, $selection, $scheduler ) {
	$id = $repo->create( array( 'title' => 'EXIN TEST retry worker', 'start_utc' => gmdate( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS ), 'end_utc' => gmdate( 'Y-m-d H:i:s', time() + 4 * DAY_IN_SECONDS ), 'status' => 'draft' ) );
	$p = exin_product( 'retry-worker' );
	$selection->add( $id, $p->get_id(), '40.00' );
	$scheduler->transition( $id, 'start' );
	$version = $repo->get( $id )['version'];
	$fail = static function ( $product ) use ( $p ) {
		if ( $product->get_id() === $p->get_id() ) { throw new RuntimeException( 'simulated_retryable_failure' ); }
	};
	add_action( 'woocommerce_before_product_object_save', $fail, 10000 );
	try {
		$scheduler->batch( $id, $version, 0, 'apply' );
	} finally {
		remove_action( 'woocommerce_before_product_object_save', $fail, 10000 );
	}
	$actions = as_get_scheduled_actions( array( 'hook' => 'exin_bf_retry', 'args' => array( $id, $p->get_id(), $version, 'apply', 1 ), 'status' => 'pending', 'per_page' => 1 ), 'ids' );
	exin_assert( count( $actions ) === 1, 'No bounded retry action' );
	$retry_state = $repo->item( $id, $p->get_id() )['retry_state'];
	exin_assert( is_array( $retry_state ) && $retry_state['next_at'] > time(), 'Missing durable future retry deadline' );
	$retry_state['next_at'] = time() - 1;
	$repo->put_item( $id, $p->get_id(), array( 'retry_state' => $retry_state ) );
	ActionScheduler_QueueRunner::instance()->process_action( $actions[0], 'EXIN integration retry' );
	exin_assert( exin_price( $p->get_id() ) === '70.00', 'Retry worker did not recover current price' );
	exin_assert( $repo->item( $id, $p->get_id() )['intent'] === null, 'Retry did not settle durable intent' );
} );

exin_test( 'REST returns sanitized error when operation and diagnostic audit writes both fail', static function () use ( $repo ) {
	global $wpdb;
	$id = $repo->create( array( 'title' => 'EXIN TEST REST audit boundary', 'status' => 'draft' ) );
	$failures = array( 'campaign' => 0, 'audit' => 0 );
	$fail_writes = static function ( $sql ) use ( $wpdb, &$failures ) {
		if ( preg_match( '/^UPDATE\s+`?' . preg_quote( $wpdb->prefix . 'exin_bf_campaigns', '/' ) . '`?\s/i', $sql ) ) {
			++$failures['campaign'];
			return str_replace( $wpdb->prefix . 'exin_bf_campaigns', $wpdb->prefix . 'exin_bf_missing_boundary_campaigns', $sql );
		}
		if ( preg_match( '/^INSERT\s+INTO\s+`?' . preg_quote( $wpdb->prefix . 'exin_bf_audit', '/' ) . '`?\s/i', $sql ) ) {
			++$failures['audit'];
			return str_replace( $wpdb->prefix . 'exin_bf_audit', $wpdb->prefix . 'exin_bf_missing_boundary_audit', $sql );
		}
		return $sql;
	};
	wp_set_current_user( 1 );
	$request = new WP_REST_Request( 'POST', '/exin-bf/v1/admin/campaign/' . $id . '/action' );
	$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
	$request->set_header( 'Content-Type', 'application/json' );
	$request->set_body( wp_json_encode( array( 'action' => 'restore', 'confirm_restore' => true ) ) );
	$previous_suppression = $wpdb->suppress_errors( true );
	add_filter( 'query', $fail_writes );
	try {
		$response = rest_do_request( $request );
		exin_assert( $response->get_status() === 409, 'Storage failure did not return controlled REST conflict' );
		$data = $response->get_data();
		exin_assert( $data['code'] === 'exin_bf_operation_failed', 'REST failure escaped the controlled error boundary' );
		exin_assert( ! str_contains( wp_json_encode( $data ), 'missing_boundary' ) && ! str_contains( wp_json_encode( $data ), 'audit_write_failed' ), 'REST response exposed internal storage diagnostics' );
		exin_assert( $failures['campaign'] > 0 && $failures['audit'] > 0, 'Fixture did not fail both operation and diagnostic audit writes' );
	} finally {
		remove_filter( 'query', $fail_writes );
		$wpdb->suppress_errors( $previous_suppression );
		wp_set_current_user( 0 );
	}
	exin_assert( $repo->get( $id )['status'] === 'draft', 'Failed transition changed campaign lifecycle state' );
} );

exin_test( 'Concurrent daily price change preserves attribution of an already calculated classic cart', static function () use ( $repo, $selection, $processor ) {
	$id = $repo->create( array( 'title' => 'EXIN TEST checkout price race', 'start_utc' => gmdate( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS ), 'end_utc' => gmdate( 'Y-m-d H:i:s', time() + 4 * DAY_IN_SECONDS ), 'status' => 'draft' ) );
	$product = exin_product( 'checkout-price-race' );
	$product->set_virtual( true );
	$product->set_tax_status( 'none' );
	$product->save();
	$selection->add( $id, $product->get_id(), '40.00' );
	$repo->save( $id, array( 'status' => 'active' ) );
	$version = $repo->get( $id )['version'];
	WC()->cart->empty_cart();
	$key = WC()->cart->add_to_cart( $product->get_id(), 1 );
	WC()->cart->calculate_totals();
	exin_assert( PriceState::money( WC()->cart->get_cart()[ $key ]['data']->get_price() ) === '100.00', 'Fixture did not capture the pre-job Woo cart price' );
	$processor->apply( $id, $product->get_id(), $version );
	exin_assert( exin_price( $product->get_id() ) === '70.00', 'Daily campaign price did not change during checkout fixture' );
	exin_assert( PriceState::money( WC()->cart->get_cart()[ $key ]['data']->get_price() ) === '100.00', 'Fixture did not retain the in-flight Woo cart object' );
	$order_id = WC()->checkout()->create_order( array( 'billing_email' => 'local-race@example.invalid', 'payment_method' => 'bacs' ) );
	exin_assert( ! is_wp_error( $order_id ), is_wp_error( $order_id ) ? $order_id->get_error_message() : 'Classic checkout fixture failed' );
	$order = wc_get_order( $order_id );
	$items = $order->get_items();
	$item = reset( $items );
	exin_assert( (int) $item->get_meta( '_exin_bf_campaign_id', true ) === $id && (int) $item->get_meta( '_exin_bf_product_id', true ) === $product->get_id(), 'In-flight selected campaign item lost its immutable order attribution' );
	exin_assert( $order->get_total() === '100.00', 'Attribution changed the total calculated by WooCommerce' );
	$processor->apply( $id, $product->get_id(), $version );
	exin_assert( wc_get_order( $order_id )->get_total() === '100.00', 'Campaign job changed the already saved checkout total' );
	$order->set_status( 'cancelled' );
	$order->save();
} );

$test_results = $GLOBALS['test_results'];
$test_assertions = $GLOBALS['test_assertions'];
$summary = array(
	'wordpress' => get_bloginfo( 'version' ), 'woocommerce' => WC_VERSION, 'php' => PHP_VERSION,
	'database' => $GLOBALS['wpdb']->db_version(), 'hpos' => \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled(),
	'tests' => count( $test_results ), 'assertions' => $test_assertions,
	'passed' => count( array_filter( $test_results, static fn( $r ) => $r['status'] === 'passed' ) ),
	'failed' => count( array_filter( $test_results, static fn( $r ) => $r['status'] === 'failed' ) ),
	'seconds' => round( microtime( true ) - $test_started, 4 ), 'results' => $test_results,
);
echo "EXIN_INTEGRATION_RESULT " . wp_json_encode( $summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
if ( $summary['failed'] ) {
	WP_CLI::halt( 1 );
}
