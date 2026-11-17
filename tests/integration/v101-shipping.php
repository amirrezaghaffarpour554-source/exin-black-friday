<?php
/** Real WooCommerce checks for the v1.0.1 cart-wide shipping subsidy. */

use Exin\BlackFriday\Application\ProductSelectionService;
use Exin\BlackFriday\Domain\Money;
use Exin\BlackFriday\Infrastructure\CampaignRepository;
use Exin\BlackFriday\Integration\Shipping;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() || 'exin_test' !== DB_NAME ) {
	throw new RuntimeException( 'Shipping tests require the isolated local exin_test database.' );
}
if ( ! class_exists( 'WooCommerce' ) || ! class_exists( Shipping::class ) ) {
	throw new RuntimeException( 'Activate WooCommerce and EXIN before shipping integration tests.' );
}

add_filter( 'action_scheduler_allow_async_request_runner', '__return_false' );
add_filter( 'pre_wp_mail', '__return_true' );
wc_load_cart();
$saved_options = array();
foreach ( array( 'woocommerce_currency', 'woocommerce_price_num_decimals', 'woocommerce_calc_taxes', 'woocommerce_shipping_tax_class', 'woocommerce_tax_based_on' ) as $option ) {
	$saved_options[ $option ] = get_option( $option, null );
}
$tax_slug = 'exin-v101-shipping-' . substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 8 );
$tax_class = WC_Tax::create_tax_class( 'EXIN V101 Shipping ' . $tax_slug, $tax_slug );
if ( is_wp_error( $tax_class ) ) {
	throw new RuntimeException( 'Could not register the isolated WooCommerce shipping tax class.' );
}
update_option( 'woocommerce_currency', 'USD' );
update_option( 'woocommerce_price_num_decimals', 2 );
update_option( 'woocommerce_calc_taxes', 'yes' );
update_option( 'woocommerce_shipping_tax_class', $tax_slug );
update_option( 'woocommerce_tax_based_on', 'shipping' );
WC()->customer->set_shipping_country( 'US' );
WC()->customer->set_shipping_state( 'CA' );
WC()->customer->set_shipping_postcode( '90210' );
WC()->customer->set_is_vat_exempt( false );

$checks = 0;
$results = array();
$assert = static function ( bool $condition, string $message ) use ( &$checks ): void {
	++$checks;
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};
$test = static function ( string $name, callable $callback ) use ( &$results ): void {
	try {
		$callback();
		$results[] = array( 'test' => $name, 'passed' => true );
		echo "PASS {$name}\n";
	} catch ( Throwable $error ) {
		$results[] = array( 'test' => $name, 'passed' => false, 'error' => $error->getMessage() );
		echo "FAIL {$name}: {$error->getMessage()}\n";
	}
};
$repository = new CampaignRepository();
$selection = new ProductSelectionService( $repository );
$shipping = new Shipping( $repository );
$zone = new WC_Shipping_Zone();
$zone->set_zone_name( 'EXIN V101 Shipping ' . wp_generate_uuid4() );
$zone->set_zone_order( 0 );
$zone->add_location( 'AQ', 'country' );
$zone->save();
$instance_id = $zone->add_shipping_method( 'flat_rate' );
update_option( 'woocommerce_flat_rate_' . $instance_id . '_settings', array( 'title' => 'EXIN fixture flat', 'tax_status' => 'taxable', 'cost' => '20' ) );
$tax_id = WC_Tax::_insert_tax_rate( array( 'tax_rate_country' => 'US', 'tax_rate_state' => 'CA', 'tax_rate' => '10.0000', 'tax_rate_name' => 'EXIN V101 tax', 'tax_rate_priority' => 1, 'tax_rate_compound' => 0, 'tax_rate_shipping' => 1, 'tax_rate_order' => 0, 'tax_rate_class' => $tax_slug ) );
WC_Cache_Helper::invalidate_cache_group( 'taxes' );

$product = static function ( string $name ): WC_Product_Simple {
	$p = new WC_Product_Simple();
	$p->set_name( 'EXIN V101 SHIPPING ' . $name );
	$p->set_status( 'publish' );
	$p->set_regular_price( '100.00' );
	$p->set_manage_stock( true );
	$p->set_stock_quantity( 20 );
	$p->save();
	return $p;
};
$selected = $product( 'selected' );
$second = $product( 'second' );
$ordinary = $product( 'ordinary' );
$campaign_id = $repository->create( array(
	'title' => 'EXIN V101 shipping',
	'start_utc' => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ),
	'end_utc' => gmdate( 'Y-m-d H:i:s', time() + 7 * DAY_IN_SECONDS - HOUR_IN_SECONDS ),
	'status' => 'draft',
	'settings' => array( 'free_shipping' => true, 'shipping_threshold' => '70.00', 'shipping_subsidy_cap' => '15.00', 'shipping_instances' => array( $instance_id ) ),
) );
$selection->add( $campaign_id, $selected->get_id(), '60.00' );
$selection->add( $campaign_id, $second->get_id(), '60.00' );
$repository->save( $campaign_id, array( 'status' => 'active' ) );
$line = static fn( WC_Product $p, string $total, int $quantity = 1 ): array => array( 'product_id' => $p->get_id(), 'variation_id' => 0, 'data' => $p, 'quantity' => $quantity, 'line_total' => $total, 'line_subtotal' => $total );
$package = static fn( array $contents ): array => array( 'contents' => $contents, 'contents_cost' => array_sum( array_column( $contents, 'line_total' ) ), 'destination' => array( 'country' => 'AQ', 'state' => '', 'postcode' => '', 'city' => '', 'address' => '', 'address_2' => '' ) );
$rates = static function ( string $base = '20.00', ?array $taxes = null ) use ( $instance_id ): array {
	$taxes = $taxes ?? WC_Tax::calc_shipping_tax( $base, WC_Tax::get_shipping_tax_rates() );
	return array(
		'flat_rate:' . $instance_id => new WC_Shipping_Rate( 'flat_rate:' . $instance_id, 'Authorized flat', $base, $taxes, 'flat_rate', $instance_id ),
		'flat_rate:999999' => new WC_Shipping_Rate( 'flat_rate:999999', 'Unselected flat', '30.00', array(), 'flat_rate', 999999 ),
		'local_pickup:999998' => new WC_Shipping_Rate( 'local_pickup:999998', 'Other method', '4.00', array(), 'local_pickup', 999998 ),
	);
};
$cost = static fn( array $value ): string => (string) $value[ 'flat_rate:' . $instance_id ]->get_cost();
$calculate = static function ( array $packages, string $base = '20.00', ?array $taxes = null ) use ( $shipping, $rates ): array {
	$decorated = $shipping->packages( $packages );
	$results = array();
	foreach ( $decorated as $key => $one ) {
		$results[ $key ] = $shipping->rates( $rates( $base, $taxes ), $one );
	}
	return array( $decorated, $results );
};
$settings = static function ( array $changes ) use ( $repository, $campaign_id ): void {
	$current = $repository->get( $campaign_id );
	$repository->save( $campaign_id, array( 'settings' => array_replace( $current['settings'], $changes ) ) );
};

try {
	$test( 'Registered WooCommerce shipping tax fixture resolves a positive ten-percent rate', static function () use ( $assert, $tax_id, $tax_slug ): void {
		$assert( in_array( $tax_slug, WC_Tax::get_tax_class_slugs(), true ), 'Shipping tax class was not registered with WooCommerce.' );
		$tax_rates = WC_Tax::get_shipping_tax_rates();
		$assert( isset( $tax_rates[ $tax_id ] ) && '10.0000' === wc_format_decimal( $tax_rates[ $tax_id ]['rate'], 4 ), 'Actual ten-percent shipping tax rate was not resolved.' );
		$base_taxes = WC_Tax::calc_shipping_tax( '20.00', $tax_rates );
		$assert( isset( $base_taxes[ $tax_id ] ) && 200 === Money::parse( wc_format_decimal( $base_taxes[ $tax_id ], 2 ), 2 ), 'The original 20.00 shipping cost did not carry a real 2.00 tax.' );
	} );
	$test( 'Ordinary and mixed carts cannot qualify using ordinary totals', static function () use ( $assert, $calculate, $cost, $package, $line, $ordinary, $selected, $shipping ): void {
		[, $result] = $calculate( array( $package( array( $line( $ordinary, '1000.00' ) ) ) ) );
		$assert( '20.00' === $cost( $result[0] ), 'Ordinary cart received campaign subsidy.' );
		$assert( array() === $shipping->warnings(), 'A normal nonqualifying package generated a false configuration warning.' );
		[, $result] = $calculate( array( $package( array( $line( $selected, '69.99' ), $line( $ordinary, '1000.00' ) ) ) ) );
		$assert( '20.00' === $cost( $result[0] ), 'Mixed-cart ordinary totals incorrectly qualified.' );
	} );
	$test( 'At and above threshold customer pays only shipping above one cap', static function () use ( $assert, $calculate, $cost, $package, $line, $selected, $instance_id, $tax_id ): void {
		foreach ( array( '70.00', '100.00' ) as $total ) {
			[, $result] = $calculate( array( $package( array( $line( $selected, $total ) ) ) ) );
			$assert( '5.00' === $cost( $result[0] ), 'Above-cap customer remainder is incorrect.' );
			$assert( '30.00' === (string) $result[0]['flat_rate:999999']->get_cost(), 'Unselected flat rate was changed.' );
			$assert( '4.00' === (string) $result[0]['local_pickup:999998']->get_cost(), 'Non-flat method was changed.' );
			$assert( WC_Tax::calc_shipping_tax( '5.00', WC_Tax::get_shipping_tax_rates() ) === $result[0][ 'flat_rate:' . $instance_id ]->get_taxes(), 'Remaining taxes differ from WooCommerce calculation.' );
			$remaining_taxes = $result[0][ 'flat_rate:' . $instance_id ]->get_taxes();
			$assert( isset( $remaining_taxes[ $tax_id ] ) && 50 === Money::parse( wc_format_decimal( $remaining_taxes[ $tax_id ], 2 ), 2 ), 'Customer remainder did not retain the actual 0.50 tax after the subsidy.' );
		}
	} );
	$test( 'Shipping at or below cap becomes zero without negative tax', static function () use ( $assert, $calculate, $cost, $package, $line, $selected, $instance_id ): void {
		foreach ( array( '12.00', '15.00', '0.00' ) as $base ) {
			[, $result] = $calculate( array( $package( array( $line( $selected, '100.00' ) ) ) ), $base );
			$assert( '0.00' === $cost( $result[0] ), 'At/below cap was not free.' );
			$assert( 0.0 === (float) array_sum( $result[0][ 'flat_rate:' . $instance_id ]->get_taxes() ), 'Zero shipping retained tax.' );
		}
	} );
	$test( 'Zero threshold has no minimum but still requires a campaign quantity', static function () use ( $assert, $settings, $calculate, $cost, $package, $line, $selected, $ordinary ): void {
		$settings( array( 'shipping_threshold' => '0.00' ) );
		[, $result] = $calculate( array( $package( array( $line( $selected, '0.00' ) ) ) ) );
		$assert( '5.00' === $cost( $result[0] ), 'A zero minimum rejected a real fully discounted campaign line.' );
		[, $result] = $calculate( array( $package( array( $line( $selected, '100.00', 0 ), $line( $ordinary, '100.00' ) ) ) ) );
		$assert( '20.00' === $cost( $result[0] ), 'A removed/zero-quantity campaign line qualified.' );
		$settings( array( 'shipping_threshold' => '70.00' ) );
	} );
	$test( 'Coupon, quantity and removal recalculate eligibility', static function () use ( $assert, $calculate, $cost, $package, $line, $selected ): void {
		foreach ( array( array( '69.99', 1, '20.00' ), array( '140.00', 2, '5.00' ), array( '0.00', 0, '20.00' ) ) as $case ) {
			[, $result] = $calculate( array( $package( array( $line( $selected, $case[0], $case[1] ) ) ) ) );
			$assert( $case[2] === $cost( $result[0] ), 'Updated cart eligibility was stale.' );
		}
	} );
	$test( 'Multiple packages share a single cart cap and alternative rates do not multiply it', static function () use ( $assert, $calculate, $cost, $package, $line, $selected, $second ): void {
		[, $result] = $calculate( array( 'A' => $package( array( $line( $selected, '100.00' ) ) ), 'B' => $package( array( $line( $second, '100.00' ) ) ) ) );
		$assert( '12.50' === $cost( $result['A'] ) && '12.50' === $cost( $result['B'] ), 'Packages did not receive their shared budgets.' );
		$assert( 1500 === 4000 - Money::parse( $cost( $result['A'] ), 2 ) - Money::parse( $cost( $result['B'] ), 2 ), 'Cart-wide subsidy exceeded or lost the total cap.' );
	} );
	$test( 'Changing another package invalidates the first package allocation cache', static function () use ( $assert, $calculate, $package, $line, $selected, $second ): void {
		$one = $package( array( $line( $selected, '100.00' ) ) );
		[$single] = $calculate( array( 'A' => $one ) );
		[$multiple] = $calculate( array( 'A' => $one, 'B' => $package( array( $line( $second, '100.00' ) ) ) ) );
		$assert( $single['A']['contents'] === $multiple['A']['contents'], 'Cache fixture changed the first package itself.' );
		$assert( $single['A']['exin_bf_allocation_version'] !== $multiple['A']['exin_bf_allocation_version'], 'Woo package cache did not observe its changed cap share.' );
		$assert( ! isset( $multiple['A']['exin_bf_subsidy_budget'] ), 'Raw private subsidy budget was exposed in package payload.' );
	} );
	$test( 'Repeated rate filtering is idempotent and disabling restores original rate', static function () use ( $assert, $shipping, $rates, $cost, $package, $line, $selected, $settings ): void {
		$decorated = $shipping->packages( array( $package( array( $line( $selected, '100.00' ) ) ) ) );
		$original = $rates();
		$first = $shipping->rates( $original, $decorated[0] );
		$second = $shipping->rates( $first, $decorated[0] );
		$assert( '5.00' === $cost( $first ) && '5.00' === $cost( $second ), 'A repeated filter granted a second subsidy.' );
		$assert( '20.00' === $cost( $original ), 'The caller\'s original rate was mutated instead of cloned.' );
		$settings( array( 'free_shipping' => false ) );
		$assert( '20.00' === $cost( $shipping->rates( $second, $decorated[0] ) ), 'Disabled policy retained our previous clone.' );
		$settings( array( 'free_shipping' => true ) );
	} );
	$test( 'Unknown package context, unsupported tax and unrounded base fail closed', static function () use ( $assert, $shipping, $rates, $cost, $calculate, $package, $line, $selected ): void {
		$assert( '20.00' === $cost( $shipping->rates( $rates(), $package( array( $line( $selected, '100.00' ) ) ) ) ), 'An unallocated package received a cap.' );
		[, $result] = $calculate( array( $package( array( $line( $selected, '100.00' ) ) ) ), '20.00', array( 999999 => '2.00' ) );
		$assert( '20.00' === $cost( $result[0] ), 'Unverifiable tax IDs were overwritten.' );
		[, $result] = $calculate( array( $package( array( $line( $selected, '100.00' ) ) ) ), '20.001', array() );
		$assert( '20.001' === $cost( $result[0] ), 'Unreliable base precision was silently changed.' );
		$assert( count( $shipping->warnings() ) > 0, 'Unsupported conditions lacked an administrative warning.' );
	} );
	$test( 'Zero cap and unknown-currency default never invent a shipping discount', static function () use ( $assert, $settings, $calculate, $cost, $package, $line, $selected ): void {
		$settings( array( 'shipping_subsidy_cap' => '0.00' ) );
		[, $result] = $calculate( array( $package( array( $line( $selected, '100.00' ) ) ) ) );
		$assert( '20.00' === $cost( $result[0] ), 'A zero cap granted a subsidy.' );
		$settings( array( 'shipping_subsidy_cap' => null ) );
		[, $result] = $calculate( array( $package( array( $line( $selected, '100.00' ) ) ) ) );
		$assert( '20.00' === $cost( $result[0] ), 'Unsupported USD-to-toman conversion was invented.' );
		$settings( array( 'shipping_subsidy_cap' => '15.00' ) );
	} );
	$test( 'A changed store currency and external price edit are ineligible', static function () use ( $assert, $calculate, $cost, $package, $line, $selected ): void {
		update_option( 'woocommerce_currency', 'EUR' );
		[, $result] = $calculate( array( $package( array( $line( $selected, '100.00' ) ) ) ) );
		$assert( '20.00' === $cost( $result[0] ), 'Changed currency reinterpreted stored thresholds.' );
		update_option( 'woocommerce_currency', 'USD' );
		$selected->set_regular_price( '110.00' );
		$selected->save();
		[, $result] = $calculate( array( $package( array( $line( $selected, '110.00' ) ) ) ) );
		$assert( '20.00' === $cost( $result[0] ), 'An externally changed campaign price received a subsidy.' );
		$selected->set_regular_price( '100.00' );
		$selected->save();
	} );
	$test( 'WooCommerce session-rate cache cannot retain a full cap after package split', static function () use ( $assert, $shipping, $package, $line, $selected, $second, $instance_id ): void {
		update_option( 'woocommerce_calc_taxes', 'no' );
		WC()->customer->set_shipping_country( 'AQ' );
		WC()->customer->set_shipping_state( '' );
		WC()->customer->set_shipping_postcode( '' );
		$callback = array( $shipping, 'rates' );
		add_filter( 'woocommerce_package_rates', $callback, 100, 2 );
		try {
			WC()->session->__unset( 'shipping_for_package_A' );
			WC()->session->__unset( 'shipping_for_package_B' );
			$one = $package( array( $line( $selected, '100.00' ) ) );
			$first = WC()->shipping()->calculate_shipping( $shipping->packages( array( 'A' => $one ) ) );
			$rate_key = 'flat_rate:' . $instance_id;
			$assert( isset( $first['A']['rates'][ $rate_key ] ), 'Actual flat-rate fixture was not resolved by Woo shipping zones.' );
			$assert( '5.00' === (string) $first['A']['rates'][ $rate_key ]->get_cost(), 'First single package did not use one whole cap.' );
			$split = WC()->shipping()->calculate_shipping( $shipping->packages( array( 'A' => $one, 'B' => $package( array( $line( $second, '100.00' ) ) ) ) ) );
			$assert( '12.50' === (string) $split['A']['rates'][ $rate_key ]->get_cost(), 'Woo reused a stale single-package rebate from its session.' );
			$assert( '12.50' === (string) $split['B']['rates'][ $rate_key ]->get_cost(), 'Second package did not receive the second shared budget.' );
			$assert( 20 === wc_get_product( $selected->get_id() )->get_stock_quantity() && 20 === wc_get_product( $second->get_id() )->get_stock_quantity(), 'Shipping calculation changed campaign stock.' );
			$assert( '20' === get_option( 'woocommerce_flat_rate_' . $instance_id . '_settings' )['cost'], 'Shipping subsidy permanently changed its method settings.' );
		} finally {
			remove_filter( 'woocommerce_package_rates', $callback, 100 );
			update_option( 'woocommerce_calc_taxes', 'yes' );
		}
	} );
	$test( 'A deleted configured method cannot subsidize an externally retained rate', static function () use ( $assert, $zone, $instance_id, $calculate, $cost, $package, $line, $selected, $shipping ): void {
		$assert( true === $zone->delete_shipping_method( $instance_id ), 'Could not remove the isolated method fixture.' );
		[, $result] = $calculate( array( $package( array( $line( $selected, '100.00' ) ) ) ) );
		$assert( '20.00' === $cost( $result[0] ), 'A deleted instance still received a shipping subsidy.' );
		$assert( count( $shipping->warnings() ) > 0, 'Deleted method lacked a clear administrative warning.' );
	} );
} finally {
	WC_Tax::_delete_tax_rate( $tax_id );
	WC_Tax::delete_tax_class_by( 'slug', $tax_slug );
	$zone->delete();
	foreach ( $saved_options as $option => $value ) {
		if ( null === $value ) {
			delete_option( $option );
		} else {
			update_option( $option, $value );
		}
	}
}
$failures = count( array_filter( $results, static fn( array $result ): bool => ! $result['passed'] ) );
echo 'EXIN_V101_SHIPPING_INTEGRATION_RESULT ' . wp_json_encode( array( 'tests' => count( $results ), 'assertions' => $checks, 'passed' => count( $results ) - $failures, 'failed' => $failures, 'wordpress' => get_bloginfo( 'version' ), 'woocommerce' => WC_VERSION, 'php' => PHP_VERSION, 'results' => $results ) ) . "\n";
if ( $failures ) {
	exit( 1 );
}
