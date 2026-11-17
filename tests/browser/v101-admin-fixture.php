<?php
/** Real HTTP admin fixture. Run with wp eval-file only in the isolated local DB. */

use Exin\BlackFriday\Application\PriceState;
use Exin\BlackFriday\Domain\Jalali;
use Exin\BlackFriday\Infrastructure\CampaignRepository;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() || 'exin_test' !== DB_NAME || ! class_exists( CampaignRepository::class ) ) {
	throw new RuntimeException( 'HTTP admin fixtures require the isolated local exin_test database.' );
}
$host = wp_parse_url( home_url(), PHP_URL_HOST );
if ( ! in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true ) ) {
	throw new RuntimeException( 'HTTP admin fixtures require a loopback WordPress URL.' );
}
add_filter( 'action_scheduler_allow_async_request_runner', '__return_false' );
add_filter( 'pre_wp_mail', '__return_true' );
$mode = $args[0] ?? 'prepare';
if ( 'verify' === $mode ) {
	$fixture = get_option( 'exin_v101_http_admin_fixture' );
	if ( ! is_array( $fixture ) || empty( $fixture['snapshots'] ) ) {
		throw new RuntimeException( 'Prepare the isolated HTTP fixture first.' );
	}
	foreach ( $fixture['snapshots'] as $id => $before ) {
		$product = wc_get_product( $id );
		if ( ! $product || $before['prices'] !== PriceState::capture( $product ) || $before['stock'] !== $product->get_stock_quantity() ) {
			throw new RuntimeException( 'Draft/scheduled admin workflow changed Woo prices or stock: ' . $id );
		}
	}
	echo wp_json_encode( array( 'verified_products' => count( $fixture['snapshots'] ), 'unchanged_prices_and_stock' => true ) ) . "\n";
	return;
}
if ( 'prepare' !== $mode ) {
	throw new RuntimeException( 'Unsupported fixture operation.' );
}
update_option( 'woocommerce_currency', 'IRR' );
update_option( 'woocommerce_price_num_decimals', 0 );
update_option( 'timezone_string', 'Asia/Tehran' );
$prefix = 'exin-ui-' . substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 8 );
$password = wp_generate_password( 32, true );
$user = get_user_by( 'login', 'exin_v101_http_ui' );
if ( $user ) {
	$user_id = $user->ID;
	wp_set_password( $password, $user_id );
	$user->set_role( 'administrator' );
} else {
	$user_id = wp_insert_user( array( 'user_login' => 'exin_v101_http_ui', 'user_pass' => $password, 'user_email' => 'exin-http-ui@example.invalid', 'role' => 'administrator' ) );
	if ( is_wp_error( $user_id ) ) {
		throw new RuntimeException( 'Cannot create the isolated browser user.' );
	}
}

// A real registered custom status is needed on the later, independent HTTP requests.
$mu_path = WPMU_PLUGIN_DIR . '/exin-v101-http-ui-status.php';
wp_mkdir_p( WPMU_PLUGIN_DIR );
$mu_code = <<<'PHP'
<?php
if ( ! defined( 'ABSPATH' ) || 'local' !== wp_get_environment_type() || ! defined( 'DB_NAME' ) || 'exin_test' !== DB_NAME ) { return; }
add_action( 'init', static function () { register_post_status( 'wc-exin-ui-paid', array( 'label' => 'EXIN isolated UI paid', 'public' => true, 'show_in_admin_all_list' => true, 'show_in_admin_status_list' => true ) ); } );
add_filter( 'wc_order_statuses', static function ( $statuses ) { $statuses['wc-exin-ui-paid'] = 'EXIN isolated UI paid'; return $statuses; } );
PHP;
if ( false === file_put_contents( $mu_path, $mu_code ) ) {
	throw new RuntimeException( 'Cannot register the isolated HTTP custom status.' );
}
$category = wp_insert_term( $prefix, 'product_cat', array( 'slug' => $prefix ) );
if ( is_wp_error( $category ) ) {
	throw new RuntimeException( 'Cannot create the isolated product category.' );
}
if ( ! taxonomy_exists( 'product_brand' ) ) {
	register_taxonomy( 'product_brand', array( 'product' ), array( 'label' => 'Product brand', 'public' => true ) );
}
$brand = wp_insert_term( $prefix, 'product_brand', array( 'slug' => $prefix ) );
$attribute_slug = 'exin_ui_color';
$taxonomy = 'pa_' . $attribute_slug;
$attribute_id = wc_attribute_taxonomy_id_by_name( $attribute_slug );
if ( ! $attribute_id ) {
	$attribute_id = wc_create_attribute( array( 'name' => 'EXIN UI Color', 'slug' => $attribute_slug, 'type' => 'select', 'order_by' => 'menu_order' ) );
}
if ( ! taxonomy_exists( $taxonomy ) ) {
	register_taxonomy( $taxonomy, array( 'product' ), array( 'label' => 'EXIN UI Color', 'public' => true ) );
}
$blue = wp_insert_term( $prefix . ' Blue', $taxonomy, array( 'slug' => $prefix . '-blue' ) );
$red = wp_insert_term( $prefix . ' Red', $taxonomy, array( 'slug' => $prefix . '-red' ) );
if ( is_wp_error( $brand ) || is_wp_error( $attribute_id ) || is_wp_error( $blue ) || is_wp_error( $red ) ) {
	throw new RuntimeException( 'Cannot create the isolated catalog metadata.' );
}
$products = array();
$attach = static function ( WC_Product $product ) use ( $category, $brand, $blue, $taxonomy, &$products ): void {
	wp_set_object_terms( $product->get_id(), array( (int) $category['term_id'] ), 'product_cat' );
	wp_set_object_terms( $product->get_id(), array( (int) $brand['term_id'] ), 'product_brand' );
	wp_set_object_terms( $product->get_id(), array( (int) $blue['term_id'] ), $taxonomy );
	$products[] = $product->get_id();
};
for ( $number = 1; $number <= 28; ++$number ) {
	$product = new WC_Product_Simple();
	$product->set_name( $prefix . ' Simple ' . $number );
	$product->set_sku( $prefix . '-simple-' . $number );
	$product->set_status( 'publish' );
	$product->set_regular_price( '1000000' );
	$product->set_manage_stock( true );
	$product->set_stock_quantity( 15 );
	$product->save();
	$attach( $product );
}
$simple_ids = $products;
$parent = new WC_Product_Variable();
$parent->set_name( $prefix . ' Variable parent' );
$parent->set_sku( $prefix . '-parent' );
$parent->set_status( 'publish' );
$attribute = new WC_Product_Attribute();
$attribute->set_id( (int) $attribute_id );
$attribute->set_name( $taxonomy );
$attribute->set_options( array( (int) $blue['term_id'], (int) $red['term_id'] ) );
$attribute->set_visible( true );
$attribute->set_variation( true );
$parent->set_attributes( array( $attribute ) );
$parent->save();
$attach( $parent );
$variations = array();
foreach ( array( $prefix . '-blue', $prefix . '-red' ) as $index => $term ) {
	$variation = new WC_Product_Variation();
	$variation->set_parent_id( $parent->get_id() );
	$variation->set_status( 'publish' );
	$variation->set_sku( $prefix . '-variation-' . $index );
	$variation->set_attributes( array( $taxonomy => $term ) );
	$variation->set_regular_price( '900000' );
	$variation->set_manage_stock( true );
	$variation->set_stock_quantity( 0 === $index ? 10 : 0 );
	$variation->save();
	$products[] = $variation->get_id();
	$variations[] = $variation->get_id();
}
WC_Product_Variable::sync( $parent->get_id() );
$snapshots = array();
foreach ( $products as $id ) {
	$product = wc_get_product( $id );
	$snapshots[ $id ] = array( 'prices' => PriceState::capture( $product ), 'stock' => $product->get_stock_quantity() );
}
$repository = new CampaignRepository();
$future = new DateTimeImmutable( '+10 days', new DateTimeZone( 'Asia/Tehran' ) );
$starts = $future->setTime( 23, 59 )->setTimezone( new DateTimeZone( 'UTC' ) );
$campaign_id = $repository->create( array( 'title' => $prefix, 'start_utc' => $starts->format( 'Y-m-d H:i:s' ), 'end_utc' => $starts->modify( '+7 days' )->format( 'Y-m-d H:i:s' ) ) );
update_option( 'exin_v101_http_admin_fixture', array( 'campaign_id' => $campaign_id, 'snapshots' => $snapshots ), false );
echo wp_json_encode( array( 'campaign_id' => $campaign_id, 'prefix' => $prefix, 'simple_ids' => $simple_ids, 'parent_id' => $parent->get_id(), 'variation_ids' => $variations, 'category_id' => (int) $category['term_id'], 'brand_slug' => $prefix, 'attribute_taxonomy' => $taxonomy, 'attribute_slug' => $prefix . '-blue', 'date_jalali' => Jalali::from_local( $future->format( 'Y-m-d H:i:s' ) )['date'], 'username' => 'exin_v101_http_ui', 'password' => $password ) ) . "\n";
