<?php
/** Destructive uninstall check, guarded to a separate temporary table prefix. */
use Exin\BlackFriday\Infrastructure\CampaignRepository;
use Exin\BlackFriday\Infrastructure\Migration;

global $wpdb;
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || wp_get_environment_type() !== 'local' || DB_NAME !== 'exin_test' || ! preg_match( '/^exin_uninstall_[0-9]+_$/', $wpdb->prefix ) ) {
	throw new RuntimeException( 'Uninstall test requires fresh isolated exin_uninstall_<timestamp>_ tables.' );
}
$repo = new CampaignRepository();
if ( null !== $repo->current() ) {
	throw new RuntimeException( 'Clean-uninstall fixture must start without previous campaigns or owned price state.' );
}
$campaign_id = $repo->create( array( 'title' => 'EXIN TEST uninstall' ) );
$product = new WC_Product_Simple();
$product->set_name( 'EXIN TEST uninstall survivor' );
$product->set_status( 'publish' );
$product->set_regular_price( '100' );
$product->save();
$order = wc_create_order();
$order->add_product( $product, 1 );
$order->save();
update_option( 'exin_bf_delete_data', true, false );
define( 'WP_UNINSTALL_PLUGIN', 'exin-black-friday/exin-black-friday.php' );
include EXIN_BF_PATH . 'uninstall.php';
$checks = 0;
foreach ( array( 'campaigns', 'items', 'locks', 'audit' ) as $suffix ) {
	$table = $wpdb->prefix . 'exin_bf_' . $suffix;
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table ) {
		throw new RuntimeException( 'Explicit clean uninstall left table ' . $suffix );
	}
	++$checks;
}
if ( ! wc_get_product( $product->get_id() ) || ! wc_get_order( $order->get_id() ) ) {
	throw new RuntimeException( 'Plugin uninstall removed WooCommerce product/order' );
}
$checks += 2;
if ( get_option( 'exin_bf_schema_version' ) !== false ) { throw new RuntimeException( 'Schema marker remains' ); }
++ $checks;
echo 'EXIN_UNINSTALL_RESULT ' . wp_json_encode( array( 'checks' => $checks, 'passed' => $checks, 'failed' => 0, 'prefix' => $wpdb->prefix ) ) . "\n";
