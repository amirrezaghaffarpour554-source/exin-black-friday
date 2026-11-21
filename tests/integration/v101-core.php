<?php
/** Real WordPress/WooCommerce v1.0.1 draft, migration and report regressions. */

use Exin\BlackFriday\Application\DraftCampaignService;
use Exin\BlackFriday\Application\PriceState;
use Exin\BlackFriday\Application\ProductSelectionService;
use Exin\BlackFriday\Domain\Timeline;
use Exin\BlackFriday\Infrastructure\CampaignRepository;
use Exin\BlackFriday\Infrastructure\Logger;
use Exin\BlackFriday\Infrastructure\Migration;
use Exin\BlackFriday\Integration\Orders;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! function_exists( 'wp_get_environment_type' ) || 'local' !== wp_get_environment_type() || ! defined( 'DB_NAME' ) || 'exin_test' !== DB_NAME ) {
	throw new RuntimeException( 'Core regressions require the isolated local exin_test database.' );
}
if ( ! class_exists( 'WooCommerce' ) || ! class_exists( DraftCampaignService::class ) ) {
	throw new RuntimeException( 'Activate real WooCommerce and EXIN v1.0.1 first.' );
}
add_filter( 'action_scheduler_allow_async_request_runner', '__return_false' );
add_filter( 'pre_wp_mail', '__return_true' );

$suite = new class() {
	public int $assertions = 0;
	public array $results = array();
	public function equal( $expected, $actual, string $message ): void {
		++$this->assertions;
		if ( $expected !== $actual ) {
			throw new RuntimeException( $message . '; expected ' . wp_json_encode( $expected ) . ', received ' . wp_json_encode( $actual ) );
		}
	}
	public function truth( bool $condition, string $message ): void {
		$this->equal( true, $condition, $message );
	}
	public function rejects( callable $callback, string $code ): void {
		try {
			$callback();
		} catch ( Throwable $error ) {
			$this->truth( str_contains( $error->getMessage(), $code ), 'Expected failure code ' . $code );
			return;
		}
		$this->truth( false, 'Expected failure code ' . $code );
	}
	public function test( string $name, callable $callback ): void {
		if ( getenv( 'EXIN_TEST_FILTER' ) && ! preg_match( getenv( 'EXIN_TEST_FILTER' ), $name ) ) { return; }
		$start = microtime( true );
		try {
			$callback();
			$this->results[] = array( 'test' => $name, 'status' => 'passed', 'seconds' => round( microtime( true ) - $start, 4 ) );
			echo 'PASS ' . $name . "\n";
		} catch ( Throwable $error ) {
			$this->results[] = array( 'test' => $name, 'status' => 'failed', 'error' => $error->getMessage(), 'seconds' => round( microtime( true ) - $start, 4 ) );
			echo 'FAIL ' . $name . ': ' . $error->getMessage() . "\n";
		}
	}
};
$started = microtime( true );
$old_currency = get_option( 'woocommerce_currency' );
$old_precision = get_option( 'woocommerce_price_num_decimals' );
update_option( 'woocommerce_currency', 'USD' );
update_option( 'woocommerce_price_num_decimals', 2 );
Migration::run();
$repository = new CampaignRepository();
$selection = new ProductSelectionService( $repository );
$drafts = new DraftCampaignService( $repository, new Logger() );
$orders = new Orders( $repository );

$product = static function ( string $label, string $price = '100.00' ): WC_Product_Simple {
	$item = new WC_Product_Simple();
	$item->set_name( 'EXIN v101 CORE ' . $label );
	$item->set_sku( 'exin-v101-core-' . wp_generate_uuid4() );
	$item->set_status( 'publish' );
	$item->set_virtual( true );
	$item->set_regular_price( $price );
	$item->set_manage_stock( true );
	$item->set_stock_quantity( 20 );
	$item->save();
	return $item;
};
$new_draft = static function ( string $label ) use ( $repository, $selection, $product ): array {
	$id = $repository->create( array( 'title' => 'EXIN v101 CORE ' . $label ) );
	$first = $product( $label . ' first' );
	$second = $product( $label . ' second', '200.00' );
	$selection->add( $id, $first->get_id(), '40.00' );
	$selection->add( $id, $second->get_id(), '100.00' );
	return array( $id, $first, $second );
};
$raw_item = static function ( int $campaign_id, int $product_id ): array {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE campaign_id=%d AND product_id=%d', $wpdb->prefix . 'exin_bf_items', $campaign_id, $product_id ), ARRAY_A );
};
$report_campaign = static function ( string $label, array $statuses = array( 'processing', 'completed', 'exin-ready' ) ) use ( $new_draft, $repository ): array {
	[ $id, $first, $second ] = $new_draft( $label );
	$settings = $repository->get( $id )['settings'];
	$settings['order_statuses'] = $statuses;
	$repository->save( $id, array( 'status' => 'active', 'start_utc' => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ), 'end_utc' => gmdate( 'Y-m-d H:i:s', time() + 6 * DAY_IN_SECONDS ), 'settings' => $settings ) );
	return array( $id, $first, $second );
};
$new_order = static function ( string $status, array $lines ) use ( $orders ): WC_Order {
	$order = wc_create_order();
	if ( is_wp_error( $order ) ) {
		throw new RuntimeException( 'Real WooCommerce order creation failed.' );
	}
	$order->set_currency( get_woocommerce_currency() );
	foreach ( $lines as [ $item_product, $quantity ] ) {
		$item_id = $order->add_product( $item_product, $quantity );
		$item = $order->get_item( $item_id );
		$orders->classic_item( $item, 'v101-core-' . $item_product->get_id(), array( 'product_id' => $item_product->get_id(), 'variation_id' => 0, 'data' => $item_product ), $order );
		$item->save();
	}
	$order->calculate_totals( false );
	$order->set_status( $status );
	$order->save();
	return $order;
};
$custom_status_filter = static function ( array $statuses ): array {
	$statuses['wc-exin-ready'] = 'EXIN isolated custom status';
	return $statuses;
};
register_post_status( 'wc-exin-ready', array( 'label' => 'EXIN isolated custom status', 'public' => true, 'show_in_admin_all_list' => true, 'show_in_admin_status_list' => true ) );
add_filter( 'wc_order_statuses', $custom_status_filter );

try {
	$suite->test( 'Core regressions run on the exact requested WordPress WooCommerce PHP and HPOS matrix', static function () use ( $suite ): void {
		$suite->equal( '7.1.4', get_bloginfo( 'version' ), 'Official WordPress target version' );
		$suite->equal( '11.3.0', WC_VERSION, 'Official WooCommerce target version' );
		$suite->equal( 8, PHP_MAJOR_VERSION, 'Requested PHP major version' );
		$suite->equal( 2, PHP_MINOR_VERSION, 'Requested PHP minor version' );
		$suite->truth( \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled(), 'HPOS is the real active order datastore' );
	} );

	$suite->test( 'Draft date and strategy edits atomically replan selected products without changing Woo prices or snapshots', static function () use ( $suite, $new_draft, $raw_item, $repository, $drafts ): void {
		[ $id, $first, $second ] = $new_draft( 'replan' );
		$before = $repository->get( $id );
		$first_before = $raw_item( $id, $first->get_id() );
		$second_before = $raw_item( $id, $second->get_id() );
		$price_before = PriceState::capture( $first );
		$result = $drafts->save( $id, array( 'start_utc' => '2026-12-01 20:30:00', 'days' => 5, 'strategy' => 'progressive', 'power' => 3, 'rounding' => 'half_up' ) );
		$first_after = $repository->item( $id, $first->get_id() );
		$suite->equal( array( '100.00', '99.06', '92.50', '74.69', '40.00' ), $first_after['schedule'], 'Exact five-day cubic schedule' );
		$suite->equal( 5, count( $repository->item( $id, $second->get_id() )['schedule'] ), 'Every selected item was replanned' );
		$suite->equal( '100.00', $repository->item( $id, $second->get_id() )['schedule'][4], 'Second floor remains exact' );
		$suite->equal( 'draft', $result['status'], 'Saving never activates the draft' );
		$suite->equal( (int) $before['version'] + 1, $result['version'], 'New plan receives one new durable version' );
		$suite->equal( '2026-12-06 20:30:00', $result['end_utc'], 'End uses five local calendar days' );
		$suite->equal( $first_before['snapshot'], $raw_item( $id, $first->get_id() )['snapshot'], 'First snapshot bytes remain immutable' );
		$suite->equal( $second_before['snapshot'], $raw_item( $id, $second->get_id() )['snapshot'], 'Second snapshot bytes remain immutable' );
		$suite->equal( $price_before, PriceState::capture( wc_get_product( $first->get_id() ) ), 'No WooCommerce price field changed' );
		$suite->equal( null, $first_after['expected'], 'Draft acquires no financial ownership' );
		$suite->equal( null, $first_after['intent'], 'Draft creates no financial write intent' );
		$date_only = $drafts->save( $id, array( 'start_utc' => '2026-12-02 20:30:00' ) );
		$suite->equal( $first_after['schedule'], $repository->item( $id, $first->get_id() )['schedule'], 'Date-only edit preserves price amounts' );
		$suite->equal( Timeline::end( '2026-12-02 20:30:00', 5, 'Asia/Tehran' ), $date_only['end_utc'], 'Date-only edit recalculates the end boundary' );
	} );

	$suite->test( 'Failed audit insert rolls back every draft row and its campaign version', static function () use ( $suite, $new_draft, $raw_item, $repository, $drafts ): void {
		global $wpdb;
		[ $id, $first, $second ] = $new_draft( 'audit rollback' );
		$before = $repository->get( $id );
		$rows = array( $raw_item( $id, $first->get_id() ), $raw_item( $id, $second->get_id() ) );
		$price = PriceState::capture( $first );
		$hit = false;
		$audit_table = $wpdb->prefix . 'exin_bf_audit';
		$filter = static function ( string $sql ) use ( &$hit, $audit_table ): string {
			if ( preg_match( '/^\s*INSERT\s+INTO\s+`?' . preg_quote( $audit_table, '/' ) . '`?(?:\s|\()/i', $sql ) ) {
				$hit = true;
				return 'INSERT INTO `' . $audit_table . '_v101_unavailable` (`id`) VALUES (1)';
			}
			return $sql;
		};
		$previous = $wpdb->suppress_errors( true );
		add_filter( 'query', $filter );
		try {
			$suite->rejects( static fn() => $drafts->save( $id, array( 'days' => 3, 'strategy' => 'progressive', 'power' => 2 ) ), 'audit_write_failed' );
		} finally {
			remove_filter( 'query', $filter );
			$wpdb->suppress_errors( $previous );
		}
		$suite->truth( $hit, 'The real audit INSERT failure was injected' );
		$suite->equal( $before, $repository->get( $id ), 'Campaign data and version rolled back' );
		$suite->equal( $rows[0], $raw_item( $id, $first->get_id() ), 'First complete plugin row rolled back' );
		$suite->equal( $rows[1], $raw_item( $id, $second->get_id() ), 'Second complete plugin row rolled back' );
		$suite->equal( $price, PriceState::capture( wc_get_product( $first->get_id() ) ), 'Audit failure cannot change Woo prices' );
	} );

	$suite->test( 'Connection loss exactly at COMMIT fails closed and cannot report an unsaved draft as saved', static function () use ( $suite, $new_draft, $raw_item, $repository, $drafts ): void {
		global $wpdb;
		[ $id, $first, $second ] = $new_draft( 'commit reconnect' );
		$before = $repository->get( $id );
		$rows = array( $raw_item( $id, $first->get_id() ), $raw_item( $id, $second->get_id() ) );
		$hit = false;
		$filter = static function ( string $sql ) use ( &$hit ): string {
			global $wpdb;
			if ( ! $hit && 'COMMIT' === strtoupper( trim( $sql ) ) ) {
				$hit = true;
				$wpdb->close();
				if ( ! $wpdb->db_connect( false ) ) {
					throw new RuntimeException( 'v101_test_reconnect_failed' );
				}
			}
			return $sql;
		};
		$previous = $wpdb->suppress_errors( true );
		add_filter( 'query', $filter, PHP_INT_MAX );
		try {
			$suite->rejects( static fn() => $drafts->save( $id, array( 'days' => 4 ) ), 'draft_transaction_connection_lost' );
		} finally {
			remove_filter( 'query', $filter, PHP_INT_MAX );
			$wpdb->suppress_errors( $previous );
		}
		$suite->truth( $hit, 'A real connection close/reconnect occurred at the COMMIT boundary' );
		$suite->equal( $before, $repository->get( $id ), 'Closed transaction did not publish its campaign state' );
		$suite->equal( $rows[0], $raw_item( $id, $first->get_id() ), 'First old schedule survives lost commit connection' );
		$suite->equal( $rows[1], $raw_item( $id, $second->get_id() ), 'Second old schedule survives lost commit connection' );
		$suite->equal( '100.00', PriceState::money( wc_get_product( $first->get_id() )->get_price( 'edit' ) ), 'No real product price changed during connection recovery' );
	} );

	$suite->test( 'Disconnect before the second item update cannot replay an autocommitted partial draft', static function () use ( $suite, $new_draft, $raw_item, $repository, $drafts ): void {
		global $wpdb;
		[ $id, $first, $second ] = $new_draft( 'mid-update disconnect' );
		$before = $repository->get( $id );
		$rows = array( $raw_item( $id, $first->get_id() ), $raw_item( $id, $second->get_id() ) );
		$retries = $wpdb->reconnect_retries;
		$reporting = error_reporting();
		$updates = 0;
		$guarded = false;
		$table = $wpdb->prefix . 'exin_bf_items';
		$filter = static function ( string $sql ) use ( &$updates, &$guarded, $table ): string {
			global $wpdb;
			if ( preg_match( '/^\s*UPDATE\s+`?' . preg_quote( $table, '/' ) . '`?\s/i', $sql ) && 2 === ++$updates ) {
				$guarded = 0 === $wpdb->reconnect_retries;
				$wpdb->close();
			}
			return $sql;
		};
		$die_handler = static fn() => static function (): void { throw new RuntimeException( 'v101_expected_connection_abort' ); };
		$die_filters = array( 'wp_die_handler', 'wp_die_ajax_handler', 'wp_die_json_handler', 'wp_die_jsonp_handler', 'wp_die_xmlrpc_handler' );
		foreach ( $die_filters as $hook ) { add_filter( $hook, $die_handler, PHP_INT_MAX ); }
		add_filter( 'query', $filter, PHP_INT_MAX );
		try {
			$suite->rejects( static fn() => $drafts->save( $id, array( 'days' => 4 ) ), 'v101_expected_connection_abort' );
		} finally {
			remove_filter( 'query', $filter, PHP_INT_MAX );
			foreach ( $die_filters as $hook ) { remove_filter( $hook, $die_handler, PHP_INT_MAX ); }
			if ( ! $wpdb->db_connect( false ) ) { throw new RuntimeException( 'v101_test_reconnect_failed' ); }
		}
		$suite->truth( 2 === $updates && $guarded, 'First update executed and connection was closed before the second guarded update' );
		$suite->equal( $retries, $wpdb->reconnect_retries, 'Core reconnect retries restored after the aborted callback' );
		$suite->equal( $reporting, error_reporting(), 'PHP error reporting restored after the failed connection probe' );
		$suite->equal( $before, $repository->get( $id ), 'Campaign version and settings remain unchanged' );
		$suite->equal( $rows[0], $raw_item( $id, $first->get_id() ), 'First already-written row rolled back on lost connection' );
		$suite->equal( $rows[1], $raw_item( $id, $second->get_id() ), 'Second row was not replayed outside the lost transaction' );
		$suite->equal( '100.00', PriceState::money( wc_get_product( $first->get_id() )->get_price( 'edit' ) ), 'Real WooCommerce price remains untouched' );
	} );

	$suite->test( 'Active campaigns and drafts with owned prices reject financial replanning', static function () use ( $suite, $new_draft, $repository, $drafts ): void {
		[ $id, $first ] = $new_draft( 'immutable active' );
		$repository->save( $id, array( 'status' => 'active' ) );
		$before = $repository->get( $id );
		$suite->rejects( static fn() => $drafts->save( $id, array( 'days' => 2 ) ), 'campaign_not_draft' );
		$suite->equal( $before, $repository->get( $id ), 'Active campaign is unchanged' );
		$repository->save( $id, array( 'status' => 'draft' ) );
		$repository->put_item( $id, $first->get_id(), array( 'expected' => PriceState::capture( $first ) ) );
		$suite->rejects( static fn() => $drafts->save( $id, array( 'days' => 2 ) ), 'draft_contains_owned_prices' );
		$suite->equal( 7, $repository->get( $id )['days'], 'Owned draft retains its original duration' );
		$repository->put_item( $id, $first->get_id(), array( 'expected' => null ) );
	} );

	$suite->test( 'Legacy schema migration removes retired settings and preserves snapshot intent retry and prices byte for byte', static function () use ( $suite, $repository, $selection, $product, $raw_item ): void {
		global $wpdb;
		update_option( 'woocommerce_currency', 'IRR' );
		update_option( 'woocommerce_price_num_decimals', 0 );
		try {
			$id = $repository->create( array( 'title' => 'EXIN v101 CORE legacy migration', 'start_utc' => gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ), 'end_utc' => gmdate( 'Y-m-d H:i:s', time() + 7 * DAY_IN_SECONDS + HOUR_IN_SECONDS ) ) );
			$item_product = $product( 'legacy IRR', '1000000' );
			$selection->add( $id, $item_product->get_id(), '400000' );
			$settings = $repository->get( $id )['settings'];
			unset( $settings['shipping_subsidy_cap'] );
			$settings['target_orders'] = 500;
			$settings['show_out_of_stock'] = true;
			$settings['legacy_preserved_value'] = 'untouched';
			$wpdb->update( $wpdb->prefix . 'exin_bf_campaigns', array( 'settings' => wp_json_encode( $settings ), 'status' => 'scheduled' ), array( 'id' => $id ) );
			$before_price = PriceState::capture( $item_product );
			$target = array_replace( $before_price, array( 'sale' => '700000', 'price' => '700000' ) );
			$repository->put_item( $id, $item_product->get_id(), array( 'status' => 'pending', 'expected' => $before_price, 'intent' => array( 'kind' => 'apply', 'before' => $before_price, 'target' => $target, 'version' => 1 ), 'retry_state' => array( 'version' => 1, 'mode' => 'apply', 'attempt' => 1, 'next_at' => time() + 60, 'exhausted' => false, 'running' => false ) ) );
			$before = $raw_item( $id, $item_product->get_id() );
			update_option( 'exin_bf_schema_version', '2', false );
			Migration::run();
			$after_campaign = $repository->get( $id );
			$after = $raw_item( $id, $item_product->get_id() );
			$suite->equal( Migration::VERSION, get_option( 'exin_bf_schema_version' ), 'Schema marker advances only to the tested version' );
			$suite->truth( ! array_key_exists( 'target_orders', $after_campaign['settings'] ), 'Legacy goal is removed from stored settings' );
			$suite->truth( ! array_key_exists( 'show_out_of_stock', $after_campaign['settings'] ), 'Legacy unavailable-product override is removed' );
			$suite->equal( '1500000', $after_campaign['settings']['shipping_subsidy_cap'], 'Default subsidy is exactly 150000 toman in IRR store units' );
			$suite->equal( 'untouched', $after_campaign['settings']['legacy_preserved_value'], 'Unrelated settings are preserved' );
			$suite->equal( 'scheduled', $after_campaign['status'], 'Migration does not activate a campaign' );
			$suite->equal( $before, $after, 'Every financial item field remains byte-for-byte unchanged' );
			$suite->equal( $before_price, PriceState::capture( wc_get_product( $item_product->get_id() ) ), 'Migration performs no Woo price write' );
			Migration::run();
			$suite->equal( $after_campaign, $repository->get( $id ), 'Repeated migration is idempotent' );
			$suite->equal( $after, $raw_item( $id, $item_product->get_id() ), 'Repeated migration preserves every item field' );
		} finally {
			update_option( 'woocommerce_currency', 'USD' );
			update_option( 'woocommerce_price_num_decimals', 2 );
		}
	} );

	$suite->test( 'A failed legacy-settings SELECT cannot falsely advance the migration version marker', static function () use ( $suite ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'exin_bf_campaigns';
		$hit = false;
		$filter = static function ( string $sql ) use ( &$hit, $table ): string {
			if ( preg_match( '/^\s*SELECT\s+id\s*,\s*settings\s+FROM\s+`?' . preg_quote( $table, '/' ) . '`?(?:\s|$)/i', $sql ) ) {
				$hit = true;
				return 'SELECT id, settings FROM `' . $table . '_v101_unavailable`';
			}
			return $sql;
		};
		update_option( 'exin_bf_schema_version', '2', false );
		$previous = $wpdb->suppress_errors( true );
		add_filter( 'query', $filter );
		try {
			$suite->rejects( static fn() => Migration::run(), 'settings_upgrade_read_failed' );
			$suite->truth( $hit, 'A real migration SELECT failed against the guarded local schema' );
			$suite->equal( '2', get_option( 'exin_bf_schema_version' ), 'Unfinished migration retains its previous durable marker' );
		} finally {
			remove_filter( 'query', $filter );
			$wpdb->suppress_errors( $previous );
			Migration::run();
		}
	} );

	$suite->test( 'Reports include registered custom sales status and count a multi-item order once while excluding nonsale statuses', static function () use ( $suite, $report_campaign, $new_order, $repository, $orders ): void {
		$requested = array( 'processing', 'completed', 'exin-ready', 'pending', 'failed', 'cancelled', 'refunded', 'checkout-draft' );
		[ $id, $first, $second ] = $report_campaign( 'custom report statuses', $requested );
		$new_order( 'exin-ready', array( array( $first, 2 ), array( $second, 1 ) ) );
		$new_order( 'processing', array( array( $first, 1 ) ) );
		$new_order( 'completed', array( array( $first, 3 ) ) );
		foreach ( array( 'pending', 'failed', 'cancelled', 'refunded' ) as $status ) {
			$new_order( $status, array( array( $first, 1 ) ) );
		}
		$orders->invalidate_campaign( $id );
		$report = $orders->analytics( $id );
		$suite->equal( 3, $report['orders'], 'Three eligible orders, not four attributed line items' );
		$suite->equal( 3, $report['top_products'][0]['orders'], 'Per-product distinct order count is real HPOS data' );
		$suite->equal( $second->get_id(), $report['least_products'][0]['product_id'], 'Least sellers use actual unit totals' );
		$suite->equal( 7, $report['units'], 'All eligible line quantities are reported' );
		$suite->equal( '800.00', $report['item_revenue'], 'Revenue uses real saved campaign item totals' );
		$suite->equal( '800.00', $report['order_revenue'], 'Related order totals are reported separately' );
		$suite->truth( in_array( 'wc-exin-ready', $report['allowed_statuses'], true ), 'Dynamic registered custom status is supported' );
		$suite->equal( array(), array_values( array_intersect( $report['allowed_statuses'], array( 'wc-pending', 'wc-failed', 'wc-cancelled', 'wc-refunded', 'wc-checkout-draft' ) ) ), 'Nonsale statuses cannot be forced into the report' );
		$suite->truth( ! array_key_exists( 'target_orders', $report ) && ! array_key_exists( 'progress', $report ), 'No retired goal or progress field is exposed' );
		$suite->equal( 'active', $repository->get( $id )['status'], 'Report generation does not alter campaign lifecycle' );
	} );

	$suite->test( 'Real partial line refund reduces campaign revenue and units without removing the remaining sale', static function () use ( $suite, $report_campaign, $new_order, $orders ): void {
		[ $id, $first ] = $report_campaign( 'partial refund' );
		$order = $new_order( 'processing', array( array( $first, 2 ) ) );
		$line_id = array_key_first( $order->get_items( 'line_item' ) );
		$refund = wc_create_refund( array( 'order_id' => $order->get_id(), 'amount' => '100.00', 'reason' => 'EXIN isolated partial refund', 'line_items' => array( $line_id => array( 'qty' => 1, 'refund_total' => '100.00', 'refund_tax' => array() ) ), 'refund_payment' => false, 'restock_items' => false ) );
		$suite->truth( $refund instanceof WC_Order_Refund, 'WooCommerce created an actual partial refund' );
		$orders->invalidate_campaign( $id );
		$report = $orders->analytics( $id );
		$suite->equal( 0, $report['least_products'][0]['units'], 'Unsold products participate in least-seller report' );
		$suite->equal( 1, $report['orders'], 'Order with remaining campaign sale stays counted once' );
		$suite->equal( 1, $report['units'], 'Refunded quantity is deducted' );
		$suite->equal( '100.00', $report['item_revenue'], 'Refunded campaign line amount is deducted' );
		$suite->equal( '100.00', $report['order_revenue'], 'Real net order total is deducted separately' );
	} );

	$suite->test( 'A fully cash-refunded order is excluded even if its status is later changed back to processing', static function () use ( $suite, $report_campaign, $new_order, $orders ): void {
		[ $id, $first ] = $report_campaign( 'full cash refund' );
		$order = $new_order( 'processing', array( array( $first, 2 ) ) );
		$line_id = array_key_first( $order->get_items( 'line_item' ) );
		$refund = wc_create_refund( array( 'order_id' => $order->get_id(), 'amount' => '200.00', 'reason' => 'EXIN isolated full cash refund', 'refund_payment' => false, 'restock_items' => false ) );
		$suite->truth( $refund instanceof WC_Order_Refund, 'WooCommerce created an actual full cash refund' );
		$order = wc_get_order( $order->get_id() );
		$order->update_status( 'processing', 'EXIN isolated status-change regression' );
		$suite->equal( 0.0, (float) $order->get_qty_refunded_for_item( $line_id ), 'Cash-only fixture intentionally has no per-line refunded quantity' );
		$suite->equal( 'processing', wc_get_order( $order->get_id() )->get_status(), 'Fixture remains in an otherwise allowed reporting status' );
		$orders->invalidate_campaign( $id );
		$report = $orders->analytics( $id );
		$suite->equal( 0, $report['orders'], 'Fully refunded order cannot increase sale count' );
		$suite->equal( 0, $report['units'], 'Fully refunded order contributes no units' );
		$suite->equal( '0.00', $report['item_revenue'], 'Fully refunded order contributes no campaign revenue' );
	} );

	$suite->test( 'Reporting-settings invalidation changes cached statistics without changing pricing version or snapshot', static function () use ( $suite, $report_campaign, $new_order, $repository, $orders, $raw_item ): void {
		[ $id, $first ] = $report_campaign( 'report cache settings', array( 'processing' ) );
		$new_order( 'processing', array( array( $first, 1 ) ) );
		$new_order( 'exin-ready', array( array( $first, 2 ) ) );
		$orders->invalidate_campaign( $id );
		$initial = $orders->analytics( $id );
		$suite->equal( '100.00', $initial['item_revenue'], 'Initial complete report is cached for processing sales' );
		$before = $repository->get( $id );
		$financial = $raw_item( $id, $first->get_id() );
		$generation = get_option( 'exin_bf_report_generation_' . $id );
		$settings = $before['settings'];
		$settings['order_statuses'] = array( 'exin-ready' );
		$repository->save( $id, array( 'settings' => $settings ) );
		$orders->invalidate_campaign( $id );
		$updated = $orders->analytics( $id );
		$suite->truth( $generation !== get_option( 'exin_bf_report_generation_' . $id ), 'Report generation token changes explicitly' );
		$suite->equal( '200.00', $updated['item_revenue'], 'Cached report refreshes for the new custom-status policy' );
		$suite->equal( 2, $updated['units'], 'New status policy uses its actual quantities' );
		$suite->equal( $before['version'], $repository->get( $id )['version'], 'Reporting edit does not invalidate price jobs' );
		$suite->equal( $financial, $raw_item( $id, $first->get_id() ), 'Reporting edit preserves every financial item field' );
		$suite->equal( '100.00', PriceState::money( wc_get_product( $first->get_id() )->get_price( 'edit' ) ), 'Reporting edit never changes a real product price' );
	} );
} finally {
	remove_filter( 'wc_order_statuses', $custom_status_filter );
	update_option( 'woocommerce_currency', $old_currency );
	update_option( 'woocommerce_price_num_decimals', $old_precision );
}

$failed = count( array_filter( $suite->results, static fn( array $result ): bool => 'failed' === $result['status'] ) );
$result = array( 'suite' => 'v101-core', 'wordpress' => get_bloginfo( 'version' ), 'woocommerce' => WC_VERSION, 'php' => PHP_VERSION, 'database' => $GLOBALS['wpdb']->db_version(), 'hpos' => \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled(), 'tests' => count( $suite->results ), 'assertions' => $suite->assertions, 'passed' => count( $suite->results ) - $failed, 'failed' => $failed, 'seconds' => round( microtime( true ) - $started, 4 ), 'results' => $suite->results );
echo 'EXIN_V101_CORE_RESULT ' . wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
if ( $failed ) {
	exit( 1 );
}
