<?php
/** Actual old ZIP -> new ZIP/source upgrade, isolated from the main test site. */

use Exin\BlackFriday\Application\PriceProcessor;
use Exin\BlackFriday\Application\PriceState;
use Exin\BlackFriday\Application\ProductSelectionService;
use Exin\BlackFriday\Infrastructure\CampaignRepository;
use Exin\BlackFriday\Infrastructure\Logger;

global $wpdb;
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() || 'exin_test' !== DB_NAME || 'exin_upgrade_' !== $wpdb->prefix ) {
	throw new RuntimeException( 'Upgrade tests require the isolated exin_upgrade_ site.' );
}
add_filter( 'action_scheduler_allow_async_request_runner', '__return_false' );
add_filter( 'pre_wp_mail', '__return_true' );
$mode = $args[0] ?? '';
$checks = 0;
$equal = static function ( $expected, $actual, string $message ) use ( &$checks ): void {
	++$checks;
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . '; expected ' . wp_json_encode( $expected ) . ', actual ' . wp_json_encode( $actual ) );
	}
	echo 'PASS ' . $message . "\n";
};
$repository = new CampaignRepository();
$processor = new PriceProcessor( $repository, new Logger() );
$raw = static function ( int $campaign, int $product ): array {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE campaign_id=%d AND product_id=%d', $wpdb->prefix . 'exin_bf_items', $campaign, $product ), ARRAY_A );
};
$state = static function ( int $id ): array {
	$product = wc_get_product( $id );
	return array( 'price' => PriceState::capture( $product ), 'stock_quantity' => $product->get_stock_quantity(), 'stock_status' => $product->get_stock_status() );
};
$order_state = static function ( int $id ): array {
	$order = wc_get_order( $id );
	return array( 'total' => $order->get_total(), 'currency' => $order->get_currency(), 'status' => $order->get_status(), 'marker' => $order->get_meta( '_exin_upgrade_proof', true ), 'lines' => array_values( array_map( static fn( $item ) => array( 'product' => $item->get_product_id(), 'quantity' => $item->get_quantity(), 'total' => $item->get_total(), 'marker' => $item->get_meta( '_exin_upgrade_line', true ) ), $order->get_items() ) ) );
};

if ( 'prepare' === $mode ) {
	$equal( '1.0.0', EXIN_BF_VERSION, 'The actual released v1.0.0 PHP code is loaded' );
	$equal( '2', get_option( 'exin_bf_schema_version' ), 'Old release creates schema 2 itself' );
	update_option( 'woocommerce_currency', 'USD' );
	update_option( 'woocommerce_price_num_decimals', 2 );
	$create_product = static function ( string $label ): WC_Product_Simple {
		$product = new WC_Product_Simple();
		$product->set_name( 'EXIN isolated upgrade ' . $label );
		$product->set_status( 'publish' );
		$product->set_virtual( true );
		$product->set_regular_price( '100.00' );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( 19 );
		$product->save();
		return $product;
	};
	$selection = new ProductSelectionService( $repository );
	$campaign = $repository->create( array( 'title' => 'EXIN actual v100 upgrade active', 'start_utc' => gmdate( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS ), 'end_utc' => gmdate( 'Y-m-d H:i:s', time() + 4 * DAY_IN_SECONDS ) ) );
	$first = $create_product( 'owned' );
	$second = $create_product( 'interrupted intent' );
	$selection->add( $campaign, $first->get_id(), '40.00', array( 'featured' => true, 'priority' => 7 ) );
	$selection->add( $campaign, $second->get_id(), '40.00' );
	$settings = $repository->get( $campaign )['settings'];
	$settings['target_orders'] = 123;
	$settings['show_out_of_stock'] = true;
	$settings['upgrade_preserved_value'] = 'unchanged';
	$repository->save( $campaign, array( 'status' => 'active', 'settings' => $settings ) );
	$version = $repository->get( $campaign )['version'];
	$processor->apply( $campaign, $first->get_id(), $version );
	$equal( '70.00', PriceState::money( wc_get_product( $first->get_id() )->get_price( 'edit' ) ), 'Real old processor applies day-four price' );
	$hit = false;
	$audit = $wpdb->prefix . 'exin_bf_audit';
	$filter = static function ( string $sql ) use ( &$hit, $audit ): string {
		if ( preg_match( '/^\s*INSERT\s+INTO\s+`?' . preg_quote( $audit, '/' ) . '`?(?:\s|\()/i', $sql ) ) {
			$hit = true;
			return 'INSERT INTO `' . $audit . '_unavailable` (`id`) VALUES (1)';
		}
		return $sql;
	};
	$previous = $wpdb->suppress_errors( true );
	add_filter( 'query', $filter );
	try {
		$processor->apply( $campaign, $second->get_id(), $version );
		throw new RuntimeException( 'Old release did not fail at the injected audit boundary.' );
	} catch ( RuntimeException $error ) {
		$equal( true, str_contains( $error->getMessage(), 'audit_write_failed' ), 'Real old audit failure interrupts after the Woo write' );
	} finally {
		remove_filter( 'query', $filter );
		$wpdb->suppress_errors( $previous );
	}
	$equal( true, $hit, 'Failure injection reached a real old audit INSERT' );
	$equal( '70.00', PriceState::money( wc_get_product( $second->get_id() )->get_price( 'edit' ) ), 'Interrupted old write reached the actual Woo product' );
	$equal( true, is_array( $repository->item( $campaign, $second->get_id() )['intent'] ), 'Old release leaves a durable pending intent' );
	$repository->put_item( $campaign, $second->get_id(), array( 'retry_state' => array( 'version' => $version, 'mode' => 'apply', 'attempt' => 1, 'next_at' => time() + 120, 'exhausted' => false, 'running' => false ) ) );
	$draft = $repository->create( array( 'title' => 'EXIN actual v100 upgrade draft' ) );
	$third = $create_product( 'untouched draft' );
	$selection->add( $draft, $third->get_id(), '30.00' );
	$order = wc_create_order();
	$order->set_currency( 'USD' );
	$line_id = $order->add_product( wc_get_product( $first->get_id() ), 2 );
	$line = $order->get_item( $line_id );
	$line->add_meta_data( '_exin_upgrade_line', array( 'campaign_id' => $campaign, 'unit_price' => '70.00' ), true );
	$line->save();
	$order->add_meta_data( '_exin_upgrade_proof', 'saved-by-actual-v100', true );
	$order->calculate_totals( false );
	$order->set_status( 'completed' );
	$order->save();
	$golden = array( 'campaign' => $campaign, 'draft' => $draft, 'order' => $order->get_id(), 'order_state' => $order_state( $order->get_id() ), 'settings' => $repository->get( $campaign )['settings'], 'items' => array() );
	foreach ( array( array( $campaign, $first->get_id() ), array( $campaign, $second->get_id() ), array( $draft, $third->get_id() ) ) as [ $campaign_id, $product_id ] ) {
		$golden['items'][] = array( 'campaign' => $campaign_id, 'product' => $product_id, 'raw' => $raw( $campaign_id, $product_id ), 'state' => $state( $product_id ) );
	}
	update_option( 'exin_v101_upgrade_golden', $golden, false );
	$equal( true, \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled(), 'Old-release order uses actual HPOS datastore' );
} elseif ( 'verify' === $mode ) {
	$equal( '1.0.1', EXIN_BF_VERSION, 'A fresh PHP process loads actual v1.0.1 code' );
	$equal( '3', get_option( 'exin_bf_schema_version' ), 'Plugin bootstrap automatically migrates schema 2 to 3' );
	$golden = get_option( 'exin_v101_upgrade_golden' );
	$equal( true, is_array( $golden ), 'The old process durable golden record survives replacement' );
	foreach ( $golden['items'] as $index => $item ) {
		$equal( $item['raw'], $raw( $item['campaign'], $item['product'] ), 'Complete financial row bytes survive upgrade for fixture ' . $index );
		$equal( $item['state'], $state( $item['product'] ), 'All Woo financial and stock fields survive upgrade for fixture ' . $index );
	}
	$equal( 'active', $repository->get( $golden['campaign'] )['status'], 'Upgrade preserves active campaign state' );
	$equal( 'draft', $repository->get( $golden['draft'] )['status'], 'Upgrade never activates the saved draft' );
	$expected = $golden['settings'];
	unset( $expected['target_orders'], $expected['show_out_of_stock'] );
	$expected['shipping_subsidy_cap'] = null;
	$equal( $expected, $repository->get( $golden['campaign'] )['settings'], 'Only retired settings are removed and unsupported-currency cap is explicit' );
	$equal( $golden['order_state'], $order_state( $golden['order'] ), 'Existing actual HPOS order total lines and private metadata survive' );
	foreach ( array_slice( $golden['items'], 0, 2 ) as $index => $item ) {
		$processor->restore( $item['campaign'], $item['product'] );
		$equal( '100.00', PriceState::money( wc_get_product( $item['product'] )->get_price( 'edit' ) ), 'New processor restores old ownership or pending intent ' . $index );
		$equal( null, $repository->item( $item['campaign'], $item['product'] )['intent'], 'Recovered pending intent is durably cleared ' . $index );
		$equal( $item['state']['stock_quantity'], wc_get_product( $item['product'] )->get_stock_quantity(), 'Restore preserves stock ' . $index );
		$processor->restore( $item['campaign'], $item['product'] );
		$equal( '100.00', PriceState::money( wc_get_product( $item['product'] )->get_price( 'edit' ) ), 'Repeated upgraded restore is idempotent ' . $index );
	}
} else {
	throw new RuntimeException( 'Expected prepare or verify upgrade phase.' );
}
echo wp_json_encode( array( 'suite' => 'actual-v100-v101-upgrade', 'phase' => $mode, 'version' => EXIN_BF_VERSION, 'checks' => $checks, 'failures' => 0, 'hpos' => \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) ) . "\n";
