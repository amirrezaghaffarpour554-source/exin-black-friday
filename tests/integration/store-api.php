<?php
/** Real Woo Store API cart, shipping, coupon and offline checkout controllers. */
use Exin\BlackFriday\Application\PriceProcessor;
use Exin\BlackFriday\Application\ProductSelectionService;
use Exin\BlackFriday\Infrastructure\CampaignRepository;
use Exin\BlackFriday\Infrastructure\Logger;

global $wpdb;
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || wp_get_environment_type() !== 'local' || DB_NAME !== 'exin_test' || $wpdb->prefix !== 'exin_store_api_' ) {
	throw new RuntimeException( 'Store API tests require isolated exin_store_api_ tables.' );
}
add_filter( 'pre_wp_mail', '__return_true' );
add_filter( 'action_scheduler_allow_async_request_runner', '__return_false' );
update_option( 'woocommerce_currency', 'USD' );
update_option( 'woocommerce_price_num_decimals', 2 );
wc_load_cart();
WC()->cart->empty_cart();
$checks = 0;
$assert = static function ( bool $condition, string $message ) use ( &$checks ): void {
	++$checks;
	if ( ! $condition ) { throw new RuntimeException( $message ); }
	echo 'PASS ' . $message . "\n";
};
$nonce = wp_create_nonce( 'wc_store_api' );
$call = static function ( string $route, array $body, bool $allow_error = false ) use ( $nonce ): WP_REST_Response {
	$request = new WP_REST_Request( 'POST', '/wc/store/v1/' . $route );
	$request->set_header( 'Nonce', $nonce );
	$request->set_header( 'Content-Type', 'application/json' );
	$request->set_body( wp_json_encode( $body ) );
	$response = rest_do_request( $request );
	if ( $response->get_status() >= 400 && ! $allow_error ) {
		throw new RuntimeException( 'Store API ' . $route . ' failed: ' . wp_json_encode( $response->get_data() ) );
	}
	$response->set_data( json_decode( wp_json_encode( $response->get_data() ), true, 512, JSON_THROW_ON_ERROR ) );
	return $response;
};
$repo = new CampaignRepository();
$selection = new ProductSelectionService( $repo );
$processor = new PriceProcessor( $repo, new Logger() );
$campaign_id = $repo->create( array( 'title' => 'EXIN TEST actual Store API', 'start_utc' => gmdate( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS ), 'end_utc' => gmdate( 'Y-m-d H:i:s', time() + 4 * DAY_IN_SECONDS ) ) );
$product = new WC_Product_Simple();
$product->set_name( 'EXIN TEST Store API physical selected' );
$product->set_status( 'publish' );
$product->set_regular_price( '100.00' );
$product->set_manage_stock( true );
$product->set_stock_quantity( 10 );
$product->save();
$selection->add( $campaign_id, $product->get_id(), '40.00' );
$zone = new WC_Shipping_Zone( 0 );
$instance_id = $zone->add_shipping_method( 'flat_rate' );
update_option( 'woocommerce_flat_rate_' . $instance_id . '_settings', array( 'enabled' => 'yes', 'title' => 'EXIN TEST Flat Rate', 'cost' => '12.00', 'tax_status' => 'none', 'type' => 'class' ) );
$settings = $repo->get( $campaign_id )['settings'];
$settings['free_shipping'] = true;
$settings['shipping_threshold'] = '70.00';
$settings['shipping_subsidy_cap'] = '12.00';
$settings['shipping_instances'] = array( $instance_id );
$repo->save( $campaign_id, array( 'status' => 'active', 'settings' => $settings ) );
$processor->apply( $campaign_id, $product->get_id(), 1 );
update_option( 'woocommerce_bacs_settings', array( 'enabled' => 'yes', 'title' => 'Local BACS', 'description' => 'Offline fixture', 'instructions' => '' ) );
WC()->payment_gateways()->payment_gateways()['bacs']->enabled = 'yes';
$response = $call( 'cart/add-item', array( 'id' => $product->get_id(), 'quantity' => 1 ) );
$assert( $response->get_status() === 201, 'Store API added real selected product' );
$cart = $response->get_data();
$cart_key = $cart['items'][0]['key'];
$assert( $cart['items'][0]['prices']['price'] === '7000', 'Blocks cart uses current campaign price' );
foreach ( array( 'floor_price', 'snapshot', 'operation_id', 'schedule' ) as $private ) {
	$assert( ! str_contains( wp_json_encode( $cart ), $private ), 'Blocks cart omits private field ' . $private );
}
$billing = array( 'first_name' => 'Local', 'last_name' => 'Fixture', 'company' => '', 'address_1' => '123 Test', 'address_2' => '', 'city' => 'Beverly Hills', 'state' => 'CA', 'postcode' => '90210', 'country' => 'US', 'email' => 'local@example.invalid', 'phone' => '5555550100' );
$shipping = $billing;
unset( $shipping['email'] );
$cart = $call( 'cart/update-customer', array( 'billing_address' => $billing, 'shipping_address' => $shipping ) )->get_data();
$rates = $cart['shipping_rates'][0]['shipping_rates'];
$rate_id = 'flat_rate:' . $instance_id;
$matching = array_values( array_filter( $rates, static fn( $rate ) => $rate['rate_id'] === $rate_id ) );
$assert( count( $matching ) === 1 && (int) $matching[0]['price'] === 0, 'Blocks actual flat-rate shipping free exactly at threshold' );
$call( 'cart/select-shipping-rate', array( 'package_id' => 0, 'rate_id' => $rate_id ) );
$capped_settings = $settings;
$capped_settings['shipping_subsidy_cap'] = '5.00';
$version_before_cap = $repo->get( $campaign_id )['version'];
$repo->save( $campaign_id, array( 'settings' => $capped_settings ) );
$cart = $call( 'cart/update-item', array( 'key' => $cart_key, 'quantity' => 1 ) )->get_data();
$assert( $cart['totals']['total_shipping'] === '700' && $repo->get( $campaign_id )['version'] === $version_before_cap, 'Blocks cart invalidates shipping cache for a capped subsidy without a pricing-version change' );
$repo->save( $campaign_id, array( 'settings' => $settings ) );
$cart = $call( 'cart/update-item', array( 'key' => $cart_key, 'quantity' => 1 ) )->get_data();
$assert( $cart['totals']['total_shipping'] === '0', 'Blocks cart restores the full subsidy after a cap-only edit' );
$coupon = new WC_Coupon();
$coupon->set_code( 'exin-store-api-' . $campaign_id );
$coupon->set_discount_type( 'fixed_cart' );
$coupon->set_amount( '10' );
$coupon->save();
$cart = $call( 'cart/apply-coupon', array( 'code' => $coupon->get_code() ) )->get_data();
$assert( $cart['totals']['total_shipping'] === '1200' && $cart['totals']['total_discount'] === '1000', 'Blocks coupon below threshold restores original shipping' );
$cart = $call( 'cart/remove-coupon', array( 'code' => $coupon->get_code() ) )->get_data();
$assert( $cart['totals']['total_shipping'] === '0', 'Blocks coupon removal restores eligible free shipping' );
$cart = $call( 'cart/update-item', array( 'key' => $cart_key, 'quantity' => 2 ) )->get_data();
$assert( $cart['totals']['total_items'] === '14000' && $cart['totals']['total_shipping'] === '0', 'Blocks quantity change recalculates totals and shipping' );
$call( 'cart/update-item', array( 'key' => $cart_key, 'quantity' => 1 ) );
$checkout_body = array( 'billing_address' => $billing, 'shipping_address' => $shipping, 'payment_method' => 'bacs', 'payment_data' => array(), 'customer_note' => '', 'create_account' => false );
$pending = wc_create_order( array( 'status' => 'pending' ) );
if ( is_wp_error( $pending ) ) {
	throw new RuntimeException( 'Could not create the real pending-order fixture.' );
}
$pending_line_id = $pending->add_product( wc_get_product( $product->get_id() ), 1 );
$pending->calculate_totals( false );
$pending->save();
$pending_amounts = array( $pending->get_total(), $pending->get_item( $pending_line_id )->get_subtotal(), $pending->get_item( $pending_line_id )->get_total() );
$refresh_cart_product = static function () use ( $cart_key, $product ): void {
	// Separate HTTP requests reload their product objects. Rehydrate that real DB object here too.
	$contents = WC()->cart->get_cart();
	$contents[ $cart_key ]['data'] = wc_get_product( $product->get_id() );
	WC()->cart->set_cart_contents( $contents );
};
try {
	$sold_out = wc_get_product( $product->get_id() );
	$sold_out->set_stock_quantity( 0 );
	$sold_out->set_stock_status( 'outofstock' );
	$sold_out->save();
	$refresh_cart_product();
	$rejected = $call( 'checkout', $checkout_body, true );
	$error_body = wp_json_encode( $rejected->get_data() );
	$stock_error = str_contains( $error_body, 'woocommerce_rest_product_out_of_stock' ) || str_contains( $error_body, 'woocommerce_rest_product_partially_out_of_stock' );
	$assert( in_array( $rejected->get_status(), array( 400, 409 ), true ) && $stock_error, 'Blocks checkout rejects a selected item that sells out after cart insertion' );
	$pending = wc_get_order( $pending->get_id() );
	$assert( $pending->has_status( 'pending' ) && $pending_amounts === array( $pending->get_total(), $pending->get_item( $pending_line_id )->get_subtotal(), $pending->get_item( $pending_line_id )->get_total() ), 'Stock-out rejection preserves saved pending order amounts' );
} finally {
	$restored = wc_get_product( $product->get_id() );
	$restored->set_stock_quantity( 10 );
	$restored->set_stock_status( 'instock' );
	$restored->save();
	$refresh_cart_product();
}
$checkout = $call( 'checkout', $checkout_body );
$data = $checkout->get_data();
$assert( $checkout->get_status() === 200 && $data['payment_result']['payment_status'] === 'success', 'Blocks controller submits real offline BACS checkout' );
$order = wc_get_order( $data['order_id'] );
$assert( $order instanceof WC_Order && $order->get_total() === '70.00', 'Blocks saved order preserves real current price and free shipping' );
$items = $order->get_items();
$item = reset( $items );
$assert( (int) $item->get_meta( '_exin_bf_campaign_id' ) === $campaign_id && (int) $item->get_meta( '_exin_bf_product_id' ) === $product->get_id(), 'Actual Blocks controller persists campaign attribution' );
$assert( wc_get_product( $product->get_id() )->get_stock_quantity() === 9, 'Woo checkout reduced stock through normal lifecycle' );
$order->set_status( 'processing' );
$order->save();
$report = ( new Exin\BlackFriday\Integration\Orders( $repo ) )->analytics( $campaign_id );
$assert( $report['orders'] === 1 && $report['item_revenue'] === '70.00', 'Actual Blocks order appears once in HPOS campaign report' );
echo 'EXIN_STORE_API_RESULT ' . wp_json_encode( array( 'checks' => $checks, 'passed' => $checks, 'failed' => 0, 'woocommerce' => WC_VERSION, 'php' => PHP_VERSION, 'payment' => 'offline BACS fixture', 'boundary' => 'Real Woo REST controllers via rest_do_request; no live payment gateway or browser Blocks UI.' ) ) . "\n";
