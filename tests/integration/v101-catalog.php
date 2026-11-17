<?php
/** Real WooCommerce catalog, stock and presentation regressions; local DB only. */

use Exin\BlackFriday\Application\CatalogProductQuery;
use Exin\BlackFriday\Application\PriceProcessor;
use Exin\BlackFriday\Application\ProductSelectionService;
use Exin\BlackFriday\Infrastructure\CampaignRepository;
use Exin\BlackFriday\Infrastructure\Logger;
use Exin\BlackFriday\Integration\CampaignContext;
use Exin\BlackFriday\Integration\CampaignProductIndex;
use Exin\BlackFriday\Integration\PublicProductQuery;
use Exin\BlackFriday\Integration\Storefront;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() || 'exin_test' !== DB_NAME || ! class_exists( CatalogProductQuery::class ) ) {
	throw new RuntimeException( 'Catalog checks require the isolated local exin_test runtime.' );
}
add_filter( 'action_scheduler_allow_async_request_runner', '__return_false' );
add_filter( 'pre_wp_mail', '__return_true' );
$checks = 0;
$results = array();
$assert = static function ( $condition, string $message ) use ( &$checks ): void {
	++$checks;
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};
$case = static function ( string $name, callable $callback ) use ( &$results ): void {
	try {
		$callback();
		$results[] = array( 'test' => $name, 'passed' => true );
		echo 'PASS ' . $name . "\n";
	} catch ( Throwable $error ) {
		$results[] = array( 'test' => $name, 'passed' => false, 'error' => $error->getMessage() );
		echo 'FAIL ' . $name . ': ' . $error->getMessage() . "\n";
	}
};
$old_currency = get_option( 'woocommerce_currency' );
$old_precision = get_option( 'woocommerce_price_num_decimals' );
$old_hide = get_option( 'woocommerce_hide_out_of_stock_items' );
update_option( 'woocommerce_currency', 'USD' );
update_option( 'woocommerce_price_num_decimals', 2 );
update_option( 'woocommerce_hide_out_of_stock_items', 'no' );
$prefix = 'exin-cat-' . substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 8 );
$repo = new CampaignRepository();
$selection = new ProductSelectionService( $repo );
$processor = new PriceProcessor( $repo, new Logger() );
$catalog = new CatalogProductQuery();
$query = new PublicProductQuery( $repo );
$index = new CampaignProductIndex();
$campaign_id = $repo->create( array( 'title' => $prefix, 'start_utc' => gmdate( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS ), 'end_utc' => gmdate( 'Y-m-d H:i:s', time() + 4 * DAY_IN_SECONDS ), 'status' => 'draft' ) );
$products = array();
$make = static function ( string $label, string $floor = '60.00', int $sales = 0, array $options = array(), string $base = '100.00' ) use ( &$products, $prefix, $selection, $campaign_id ): WC_Product_Simple {
	$product = new WC_Product_Simple();
	$product->set_name( $prefix . ' ' . $label );
	$product->set_sku( $prefix . '-' . sanitize_title( $label ) );
	$product->set_status( 'publish' );
	$product->set_regular_price( $base );
	$product->set_manage_stock( true );
	$product->set_stock_quantity( 12 );
	$product->set_total_sales( $sales );
	$product->save();
	$selection->add( $campaign_id, $product->get_id(), $floor, $options );
	$products[ $label ] = $product;
	return $product;
};

try {
	$first = $make( 'Featured first', '80.00', 0, array( 'featured' => true, 'priority' => 10 ) );
	$second = $make( 'Featured second', '90.00', 0, array( 'featured' => true, 'priority' => 20 ) );
	$discount = $make( 'Largest discount', '20.00' );
	$seller = $make( 'Best seller', '60.00', 15 );
	$older = $make( 'Older enrollment', '60.00', 5 );
	// Use real enrollment time, avoiding mutation of the immutable stored snapshot.
	usleep( 1100000 );
	$newer = $make( 'Newer enrollment', '60.00', 5 );
	$precise_low = $make( 'Rounded percentage low', '9202.00', 0, array(), '10000.00' );
	$precise_high = $make( 'Rounded percentage high', '0.92', 0, array(), '1.00' );
	$exhausted = $make( 'Exhausted' );
	$backorder = $make( 'Backorder' );
	$unpriced = $make( 'No purchasable price' );
	for ( $number = 0; $number < 14; ++$number ) {
		$make( sprintf( 'Page filler %02d', $number ), '100.00' );
	}
	$category_parent = wp_insert_term( $prefix . ' Main', 'product_cat', array( 'slug' => $prefix . '-main' ) );
	$category_child = wp_insert_term( $prefix . ' Child', 'product_cat', array( 'slug' => $prefix . '-child', 'parent' => $category_parent['term_id'] ) );
	if ( ! taxonomy_exists( 'product_brand' ) ) {
		register_taxonomy( 'product_brand', array( 'product' ), array( 'label' => 'Product brand', 'public' => true ) );
	}
	$brand = wp_insert_term( $prefix . ' Brand', 'product_brand', array( 'slug' => $prefix . '-brand' ) );
	$attribute_slug = 'exin_cat_mat';
	$attribute_name = 'pa_' . $attribute_slug;
	$attribute_id = wc_attribute_taxonomy_id_by_name( $attribute_slug );
	if ( ! $attribute_id ) {
		$attribute_id = wc_create_attribute( array( 'name' => 'EXIN catalog material', 'slug' => $attribute_slug, 'type' => 'select', 'order_by' => 'menu_order' ) );
	}
	if ( ! taxonomy_exists( $attribute_name ) ) {
		register_taxonomy( $attribute_name, array( 'product' ), array( 'label' => 'Catalog material', 'public' => true ) );
	}
	$blue = wp_insert_term( $prefix . ' Blue', $attribute_name, array( 'slug' => $prefix . '-blue' ) );
	$red = wp_insert_term( $prefix . ' Red', $attribute_name, array( 'slug' => $prefix . '-red' ) );
	$parent = new WC_Product_Variable();
	$parent->set_name( $prefix . ' Variable parent' );
	$parent->set_sku( $prefix . '-parent' );
	$parent->set_status( 'publish' );
	$parent->set_manage_stock( true );
	$parent->set_stock_quantity( 5 );
	$attribute = new WC_Product_Attribute();
	$attribute->set_id( (int) $attribute_id );
	$attribute->set_name( $attribute_name );
	$attribute->set_options( array( $blue['term_id'], $red['term_id'] ) );
	$attribute->set_visible( true );
	$attribute->set_variation( true );
	$parent->set_attributes( array( $attribute ) );
	$parent->save();
	$make_variation = static function ( string $label, string $value, bool $own_stock ) use ( &$products, $parent, $prefix, $attribute_name, $selection, $campaign_id ): WC_Product_Variation {
		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $parent->get_id() );
		$variation->set_status( 'publish' );
		$variation->set_sku( $prefix . '-' . $label );
		$variation->set_attributes( array( $attribute_name => $value ) );
		$variation->set_regular_price( '100.00' );
		$variation->set_manage_stock( $own_stock );
		if ( $own_stock ) {
			$variation->set_stock_quantity( 5 );
		}
		$variation->save();
		$selection->add( $campaign_id, $variation->get_id(), '80.00' );
		$products[ $label ] = $variation;
		return $variation;
	};
	$inherited = $make_variation( 'inherited-blue', $prefix . '-blue', false );
	$independent = $make_variation( 'independent-red', $prefix . '-red', true );
	$ordinary = new WC_Product_Simple();
	$ordinary->set_name( $prefix . ' Ordinary excluded' );
	$ordinary->set_sku( $prefix . '-ordinary' );
	$ordinary->set_status( 'publish' );
	$ordinary->set_regular_price( '100.00' );
	$ordinary->save();
	$unsupported = array();
	foreach ( array( new WC_Product_Grouped(), new WC_Product_External() ) as $product ) {
		$product->set_name( $prefix . ' AAA unsupported ' . $product->get_type() );
		$product->set_status( 'publish' );
		$product->set_regular_price( '100.00' );
		$product->save();
		$unsupported[] = $product->get_id();
	}
	foreach ( array( $first, $parent, $ordinary ) as $product ) {
		wp_set_object_terms( $product->get_id(), array( (int) $category_child['term_id'] ), 'product_cat' );
		wp_set_object_terms( $product->get_id(), array( (int) $brand['term_id'] ), 'product_brand' );
	}
	$repo->save( $campaign_id, array( 'status' => 'active' ) );
	$campaign = $repo->get( $campaign_id );
	foreach ( $products as $product ) {
		$processor->apply( $campaign_id, $product->get_id(), $campaign['version'] );
	}

	$case( 'Catalog picker searches SKU and supports bounded server pagination', static function () use ( $catalog, $prefix, $first, $unsupported, $products, $assert ) {
		$sku = $catalog->search( $first->get_sku(), 1 );
		$assert( array_column( $sku['products'], 'id' ) === array( $first->get_id() ), 'SKU lookup selected the wrong product' );
		$page_one = $catalog->search( $prefix, 1 );
		$page_two = $catalog->search( $prefix, 2 );
		$assert( count( $page_one['products'] ) === 20 && $page_one['has_more'], 'First catalog page is not bounded to twenty' );
		$assert( count( $page_two['products'] ) > 0, 'Second catalog page is missing' );
		$assert( ! array_intersect( array_column( $page_one['products'], 'id' ), array_column( $page_two['products'], 'id' ) ), 'Catalog pages overlap' );
		$assert( $page_one['total'] === $page_two['total'], 'Filtered total is inconsistent between pages' );
		$assert( $page_one['total'] === count( $products ) + 2, 'Unsupported product types polluted the filtered total' );
		$assert( ! array_intersect( $unsupported, array_merge( array_column( $page_one['products'], 'id' ), array_column( $page_two['products'], 'id' ) ) ), 'Unsupported product type leaked into the picker' );
	} );
	$case( 'Catalog types expose variable parents as navigation and variations independently', static function () use ( $catalog, $prefix, $parent, $inherited, $independent, $assert ) {
		$parents = $catalog->search( $prefix, 1, array( 'type' => 'variable' ) );
		$assert( count( $parents['products'] ) === 1 && $parents['products'][0]['id'] === $parent->get_id(), 'Variable filter returned nonparents' );
		$assert( ! $parents['products'][0]['selectable'] && $parents['products'][0]['has_variations'], 'Variable parent can be enrolled without choosing variations' );
		$children = $catalog->search( '', 1, array( 'parent_id' => $parent->get_id() ) );
		$assert( count( $children['products'] ) === 2 && ! array_diff( array( $inherited->get_id(), $independent->get_id() ), array_column( $children['products'], 'id' ) ), 'Parent navigation missed sellable variations' );
		$assert( $children['products'][0]['selectable'] && $children['products'][1]['selectable'], 'Variation cannot be independently selected' );
	} );
	$case( 'Category descendants and combined brand/attribute filters match exact variations', static function () use ( $catalog, $parent, $inherited, $independent, $category_parent, $attribute_name, $prefix, $assert ) {
		$filtered = $catalog->search( '', 1, array( 'category' => $prefix . '-main', 'parent_id' => $parent->get_id(), 'tax_filters' => array( 'product_brand' => $prefix . '-brand', $attribute_name => $prefix . '-blue' ) ) );
		$assert( array_column( $filtered['products'], 'id' ) === array( $inherited->get_id() ), 'Combined inherited taxonomy filters included the red variation' );
		$assert( $filtered['total'] === 1, 'Combined filter count differs from selected result' );
		$empty = $catalog->search( '', 1, array( 'tax_filters' => array( 'post_tag' => 'invalid' ) ) );
		$assert( $empty['total'] === 0, 'Unregistered catalog filter widened the query' );
		$injection = $catalog->search( "' OR 1=1 --", 1 );
		$assert( 0 === $injection['total'], 'SQL search payload widened the query' );
	} );
	$case( 'Picker metadata lists actual registered category brand and attribute values', static function () use ( $catalog, $attribute_name, $prefix, $assert ) {
		$metadata = $catalog->filters();
		$assert( in_array( $prefix . '-main', array_column( $metadata['categories'], 'slug' ), true ), 'Real category is missing' );
		$taxonomies = array_column( $metadata['taxonomies'], 'name' );
		$assert( in_array( 'product_brand', $taxonomies, true ) && in_array( $attribute_name, $taxonomies, true ), 'Brand or registered attribute discovery is missing' );
	} );
	$case( 'Campaign order uses featured manual priority exact discount sales and enrollment', static function () use ( $index, $campaign, $first, $second, $discount, $seller, $older, $newer, $precise_low, $precise_high, $assert ) {
		$ids = $index->ids( $campaign );
		$assert( array_slice( $ids, 0, 4 ) === array( $first->get_id(), $second->get_id(), $discount->get_id(), $seller->get_id() ), 'Default featured/discount/sales priorities are unstable' );
		$positions = array_flip( $ids );
		$assert( $positions[ $newer->get_id() ] < $positions[ $older->get_id() ], 'Equal sales/discount did not prefer newer enrollment' );
		$assert( $positions[ $precise_high->get_id() ] < $positions[ $precise_low->get_id() ], 'Ranking rounded valid discount percentages before comparison' );
		$assert( $index->ids( $campaign, false, 'priority' ) === $ids, 'Legacy priority alias changed sorting behavior' );
	} );
	$case( 'Category slug and featured shortcode return only selected products', static function () use ( $query, $campaign, $category_parent, $first, $ordinary, $prefix, $repo, $assert ) {
		$result = $query->query( $campaign, array( 'category' => $prefix . '-main', 'limit' => 48 ) );
		$ids = array_map( static fn( $product ) => $product->get_id(), $result['products'] );
		$assert( in_array( $first->get_id(), $ids, true ) && ! in_array( $ordinary->get_id(), $ids, true ), 'A normal catalog product leaked into the campaign category' );
		$html = ( new Storefront( $repo ) )->render( 'products', array( 'featured' => 'yes', 'limit' => 48, 'orderby' => 'campaign_priority' ) );
		$assert( str_contains( $html, $first->get_name() ) && ! str_contains( $html, $ordinary->get_name() ), 'Featured shortcode did not restrict its source' );
		$assert( ! preg_match( '/floor_price|snapshot|operation_id|retry_state|schedule\s*:/', $html ), 'Private financial fields leaked into public HTML' );
	} );
	$case( 'Standard Woo loop templates preserve list markup columns and caller loop context', static function () use ( $repo, $inherited, $assert ) {
		$had_loop = array_key_exists( 'woocommerce_loop', $GLOBALS );
		$old_loop = $GLOBALS['woocommerce_loop'] ?? null;
		$sentinel = array( 'name' => 'caller-loop', 'columns' => 7, 'loop' => 99, 'custom' => 'preserve' );
		$GLOBALS['woocommerce_loop'] = $sentinel;
		try {
			$html = ( new Storefront( $repo ) )->render( 'products', array( 'limit' => 48, 'columns' => 3 ) );
			$assert( (bool) preg_match( '/<ul\b[^>]*class="[^"]*exin-bf-grid/', $html ), 'Default WooCommerce list wrapper was replaced' );
			$assert( (bool) preg_match( '/<li\b[^>]*class="[^"]*exin-bf-variation/', $html ), 'Variation card does not match the default list container' );
			$assert( str_contains( $html, 'columns-3' ), 'WooCommerce loop properties do not reflect requested columns' );
			$assert( $GLOBALS['woocommerce_loop'] === $sentinel, 'Caller WooCommerce loop properties leaked after rendering' );
		} finally {
			if ( $had_loop ) {
				$GLOBALS['woocommerce_loop'] = $old_loop;
			} else {
				unset( $GLOBALS['woocommerce_loop'] );
			}
		}
	} );
	$case( 'Div-based theme loop templates retain valid variation markup and restore context after errors', static function () use ( $repo, $query, $campaign, $assert ) {
		$directory = EXIN_BF_PATH . 'tests/fixtures/v101-theme/';
		$locate = static function ( $template, $name ) use ( $directory ) {
			return in_array( $name, array( 'loop/loop-start.php', 'loop/loop-end.php' ), true ) ? $directory . basename( $name ) : $template;
		};
		$card = static function ( $template, $slug, $name ) use ( $directory ) {
			return 'content' === $slug && 'product' === $name ? $directory . 'content-product.php' : $template;
		};
		$fail = static function ( $template, $slug, $name ) use ( $directory ) {
			return 'content' === $slug && 'product' === $name ? $directory . 'failing-product.php' : $template;
		};
		$had_loop = array_key_exists( 'woocommerce_loop', $GLOBALS );
		$old_loop = $GLOBALS['woocommerce_loop'] ?? null;
		$old_post = $GLOBALS['post'] ?? null;
		$old_product = $GLOBALS['product'] ?? null;
		$sentinel = array( 'name' => 'nested-caller', 'columns' => 6, 'loop' => 101, 'custom' => 'unchanged' );
		$GLOBALS['woocommerce_loop'] = $sentinel;
		add_filter( 'wc_get_template', $locate, 999, 2 );
		add_filter( 'wc_get_template_part', $card, 999, 3 );
		try {
			$html = ( new Storefront( $repo ) )->render( 'products', array( 'limit' => 48, 'columns' => 3 ) );
			$count = count( $query->query( $campaign, array( 'limit' => 48 ) )['products'] );
			$assert( str_contains( $html, 'exin-test-theme-grid' ) && str_contains( $html, 'data-test-columns="3"' ), 'Active theme loop-start template or columns were ignored' );
			$assert( str_contains( $html, 'exin-test-theme-card' ), 'Active theme simple product card was replaced' );
			$assert( (bool) preg_match( '/<div\b[^>]*class="[^"]*exin-bf-variation/', $html ) && ! preg_match( '/<li\b[^>]*class="[^"]*exin-bf-variation/', $html ), 'Variation tag is invalid for a div-based theme loop' );
			$assert( str_contains( $html, '<!-- exin-test-loop-count:' . $count . ' -->' ), 'Theme loop-end was not evaluated with the completed item count' );
			$assert( $GLOBALS['woocommerce_loop'] === $sentinel, 'Theme override leaked its temporary loop properties' );
			remove_filter( 'wc_get_template_part', $card, 999 );
			add_filter( 'wc_get_template_part', $fail, 999, 3 );
			$before_buffers = ob_get_level();
			$before_badges = $GLOBALS['wp_filter']['woocommerce_before_shop_loop_item_title']->callbacks ?? array();
			$before_stock = $GLOBALS['wp_filter']['woocommerce_after_shop_loop_item_title']->callbacks ?? array();
			$failed_html = ( new Storefront( $repo ) )->render( 'products', array( 'limit' => 48 ) );
			$assert( '' === $failed_html, 'A theme exception escaped or exposed internal details' );
			$assert( $GLOBALS['woocommerce_loop'] === $sentinel && ( $GLOBALS['post'] ?? null ) === $old_post && ( $GLOBALS['product'] ?? null ) === $old_product, 'An exception leaked loop or product/post globals' );
			$assert( ob_get_level() === $before_buffers && ( $GLOBALS['wp_filter']['woocommerce_before_shop_loop_item_title']->callbacks ?? array() ) === $before_badges && ( $GLOBALS['wp_filter']['woocommerce_after_shop_loop_item_title']->callbacks ?? array() ) === $before_stock, 'An exception leaked buffers or temporary hooks' );
		} finally {
			remove_filter( 'wc_get_template', $locate, 999 );
			remove_filter( 'wc_get_template_part', $card, 999 );
			remove_filter( 'wc_get_template_part', $fail, 999 );
			if ( $had_loop ) {
				$GLOBALS['woocommerce_loop'] = $old_loop;
			} else {
				unset( $GLOBALS['woocommerce_loop'] );
			}
		}
	} );
	$case( 'Stock exhaustion invalidates cached IDs before pagination and restock returns membership', static function () use ( $exhausted, $query, $campaign, $assert ) {
		$before = $query->query( $campaign, array( 'limit' => 48 ) );
		$exhausted->set_stock_quantity( 0 );
		$exhausted->save();
		$after = $query->query( $campaign, array( 'limit' => 48, 'show_out_of_stock' => true ) );
		$ids = array_map( static fn( $product ) => $product->get_id(), $after['products'] );
		$assert( $after['total'] === $before['total'] - 1 && ! in_array( $exhausted->get_id(), $ids, true ), 'Cached exhausted product remained visible or counted' );
		$exhausted->set_stock_quantity( 3 );
		$exhausted->save();
		$restocked = $query->query( $campaign, array( 'limit' => 48 ) );
		$assert( $restocked['total'] === $before['total'], 'Restocked valid membership did not return' );
		$assert( 3 === wc_get_product( $exhausted->get_id() )->get_stock_quantity(), 'Listing read mutated stock' );
	} );
	$case( 'Backordered and unpurchasable products leave every campaign page', static function () use ( $backorder, $unpriced, $query, $campaign, $assert ) {
		$before = $query->query( $campaign, array( 'limit' => 48 ) );
		$backorder->set_backorders( 'yes' );
		$backorder->set_stock_quantity( 0 );
		$backorder->save();
		$unpriced->set_regular_price( '' );
		$unpriced->set_sale_price( '' );
		$unpriced->set_price( '' );
		$unpriced->save();
		$after = $query->query( $campaign, array( 'limit' => 1, 'page' => 1 ) );
		$assert( $after['total'] === $before['total'] - 2 && $after['pages'] === $after['total'], 'Unavailable products were removed after rather than before paging' );
		$all_ids = array();
		for ( $page = 1; $page <= $after['pages']; ++$page ) {
			$result = $query->query( $campaign, array( 'limit' => 1, 'page' => $page ) );
			$assert( count( $result['products'] ) === 1, 'Filtered paging produced an empty page' );
			$all_ids[] = $result['products'][0]->get_id();
		}
		$assert( count( array_unique( $all_ids ) ) === count( $all_ids ) && ! in_array( $backorder->get_id(), $all_ids, true ) && ! in_array( $unpriced->get_id(), $all_ids, true ), 'Filtered pages contain duplicates or unavailable products' );
	} );
	$case( 'Inherited and independent variation stock use the correct Woo stock owner', static function () use ( $parent, $inherited, $independent, $catalog, $query, $campaign, $assert ) {
		$parent->set_stock_quantity( 0 );
		$parent->save();
		$available = $catalog->search( '', 1, array( 'parent_id' => $parent->get_id(), 'stock' => 'instock' ) );
		$picker_ids = array_column( $available['products'], 'id' );
		$assert( in_array( $independent->get_id(), $picker_ids, true ) && ! in_array( $inherited->get_id(), $picker_ids, true ), 'Stock picker ignored variation inheritance before pagination' );
		$ids = array_map( static fn( $product ) => $product->get_id(), $query->query( $campaign, array( 'limit' => 48 ) )['products'] );
		$assert( in_array( $independent->get_id(), $ids, true ) && ! in_array( $inherited->get_id(), $ids, true ), 'Independent positive child stock was replaced by exhausted parent stock' );
		$parent->set_stock_quantity( 5 );
		$parent->save();
	} );
	$case( 'Featured and numeric priority updates invalidate cached ordering', static function () use ( $selection, $campaign_id, $campaign, $index, $discount, $assert ) {
		$index->ids( $campaign );
		$selection->update( $campaign_id, $discount->get_id(), array( 'featured' => true, 'priority' => 0 ) );
		$assert( $index->ids( $campaign )[0] === $discount->get_id(), 'Selection update left a stale public index' );
	} );
	$case( 'Product taxonomy mutation refreshes indexes while ordinary blog tags do not', static function () use ( $prefix, $assert ) {
		$generation = get_option( 'exin_bf_catalog_generation', '1' );
		wp_insert_term( $prefix . ' Blog tag', 'post_tag' );
		$assert( get_option( 'exin_bf_catalog_generation', '1' ) === $generation, 'An unrelated blog tag invalidated the campaign catalog' );
		wp_insert_term( $prefix . ' Product category', 'product_cat' );
		$assert( get_option( 'exin_bf_catalog_generation', '1' ) !== $generation, 'A changed product category left metadata cached' );
	} );
	$case( 'A changed store currency fails closed instead of reinterpreting campaign units', static function () use ( $index, $campaign, $assert ) {
		update_option( 'woocommerce_currency', 'EUR' );
		try {
			$assert( array() === $index->ids( $campaign ), 'Currency-mismatched campaign remained available' );
		} finally {
			update_option( 'woocommerce_currency', 'USD' );
		}
	} );
} finally {
	// Restore owned fixture prices; external test edits remain explicit conflicts.
	update_option( 'woocommerce_currency', 'USD' );
	foreach ( $products as $product ) {
		$processor->restore( $campaign_id, $product->get_id() );
	}
	update_option( 'woocommerce_currency', $old_currency );
	update_option( 'woocommerce_price_num_decimals', $old_precision );
	update_option( 'woocommerce_hide_out_of_stock_items', $old_hide );
}
$failed = count( array_filter( $results, static fn( $result ) => ! $result['passed'] ) );
echo 'EXIN_V101_CATALOG_RESULT ' . wp_json_encode( array( 'tests' => count( $results ), 'assertions' => $checks, 'passed' => count( $results ) - $failed, 'failed' => $failed, 'wordpress' => get_bloginfo( 'version' ), 'woocommerce' => WC_VERSION, 'php' => PHP_VERSION, 'object_cache' => wp_using_ext_object_cache(), 'results' => $results ), JSON_PRETTY_PRINT ) . "\n";
if ( $failed ) {
	WP_CLI::halt( 1 );
}
