<?php
/** Real clean release-ZIP installation assertions, with no source plugin runtime. */

use Exin\BlackFriday\Application\PriceProcessor;
use Exin\BlackFriday\Application\ProductSelectionService;
use Exin\BlackFriday\Infrastructure\CampaignRepository;
use Exin\BlackFriday\Infrastructure\Logger;
use Exin\BlackFriday\Infrastructure\Migration;

global $wpdb;
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() || 'exin_test' !== DB_NAME || ! str_starts_with( $wpdb->prefix, 'exin_zip_' ) || '/srv/exin-package-wp/' !== ABSPATH ) {
	throw new RuntimeException( 'Package installation checks require their isolated WordPress tree and DB prefix.' );
}
$checks = 0;
$assert = static function ( bool $condition, string $message ) use ( &$checks ): void {
	++$checks;
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
	echo 'PASS ' . $message . "\n";
};
$assert( '1.0.0' === EXIN_BF_VERSION, 'ZIP plugin version is 1.0.0' );
$assert( '/srv/exin-package-wp/wp-content/plugins/exin-black-friday/' === EXIN_BF_PATH, 'Plugin runtime uses extracted ZIP path, not repository bind' );
$assert( str_starts_with( ( new ReflectionClass( CampaignRepository::class ) )->getFileName(), EXIN_BF_PATH ), 'Autoloaded source comes exclusively from installed ZIP' );
$assert( ! file_exists( EXIN_BF_PATH . 'tests' ) && ! file_exists( EXIN_BF_PATH . 'vendor' ) && ! file_exists( EXIN_BF_PATH . 'work' ), 'Installed ZIP excludes development and test artifacts' );
$assert( get_option( 'exin_bf_schema_version' ) === Migration::VERSION, 'WordPress activation created versioned schema' );
$assert( \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled(), 'Clean runtime uses real HPOS' );
$repository = new CampaignRepository();
$assert( null === $repository->current(), 'Clean database starts without campaign fixtures' );
$id = $repository->create( array( 'title' => 'EXIN ZIP verification', 'start_utc' => gmdate( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS ), 'end_utc' => gmdate( 'Y-m-d H:i:s', time() + 4 * DAY_IN_SECONDS ), 'status' => 'draft' ) );
$product = new WC_Product_Simple();
$product->set_name( 'EXIN ZIP installed product' );
$product->set_status( 'publish' );
$product->set_regular_price( '100.00' );
$product->set_manage_stock( true );
$product->set_stock_quantity( 10 );
$product->save();
$selection = new ProductSelectionService( $repository );
$item = $selection->add( $id, $product->get_id(), '40.00' );
$assert( array( '100.00', '90.00', '80.00', '70.00', '60.00', '50.00', '40.00' ) === $item['schedule'], 'Installed engine creates correct seven-day schedule' );
$repository->save( $id, array( 'status' => 'active' ) );
$processor = new PriceProcessor( $repository, new Logger() );
$processor->apply( $id, $product->get_id(), $repository->get( $id )['version'] );
$assert( '70.00' === wc_get_product( $product->get_id() )->get_price( 'edit' ), 'Installed engine applies current-day price through Woo CRUD' );
$html = do_shortcode( '[exin_bf_products limit="12"]' );
$assert( str_contains( $html, 'EXIN ZIP installed product' ), 'Installed shortcode renders actual selected product' );
$assert( ! str_contains( $html, 'floor_price' ) && ! str_contains( $html, 'snapshot' ) && ! str_contains( $html, 'schedule' ), 'Installed public output omits confidential records' );
$processor->restore( $id, $product->get_id() );
$processor->restore( $id, $product->get_id() );
$assert( '100.00' === wc_get_product( $product->get_id() )->get_price( 'edit' ), 'Installed engine safely restores once and repeatedly' );
$assert( 10 === wc_get_product( $product->get_id() )->get_stock_quantity(), 'Campaign price jobs preserve WooCommerce stock' );
$restored = $repository->item( $id, $product->get_id() );
$assert( null === $restored['expected'] && null === $restored['intent'] && 'completed' === $restored['status'], 'Successful restore clears owned price state' );
require_once ABSPATH . 'wp-admin/includes/plugin.php';
deactivate_plugins( 'exin-black-friday/exin-black-friday.php' );
$result = activate_plugin( 'exin-black-friday/exin-black-friday.php' );
$assert( ! is_wp_error( $result ) && is_plugin_active( 'exin-black-friday/exin-black-friday.php' ), 'Actual extracted ZIP deactivates and reactivates' );
echo 'EXIN_PACKAGE_INSTALL_RESULT ' . wp_json_encode( array( 'checks' => $checks, 'passed' => $checks, 'failed' => 0, 'wordpress' => get_bloginfo( 'version' ), 'woocommerce' => WC_VERSION, 'php' => PHP_VERSION, 'database' => $wpdb->db_version(), 'source' => 'extracted installable ZIP' ) ) . "\n";
