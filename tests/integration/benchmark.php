<?php
/** Repeatable real CRUD/batch/page benchmark. Isolated local DB only. */
use Exin\BlackFriday\Application\PriceProcessor;
use Exin\BlackFriday\Application\ProductSelectionService;
use Exin\BlackFriday\Application\Scheduler;
use Exin\BlackFriday\Infrastructure\CampaignRepository;
use Exin\BlackFriday\Infrastructure\Logger;
use Exin\BlackFriday\Integration\PublicProductQuery;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || wp_get_environment_type() !== 'local' || DB_NAME !== 'exin_test' ) {
	throw new RuntimeException( 'Benchmark requires isolated local DB.' );
}
add_filter( 'action_scheduler_allow_async_request_runner', '__return_false' );
add_filter( 'pre_wp_mail', '__return_true' );
$repository = new CampaignRepository();
$logger = new Logger();
$selection = new ProductSelectionService( $repository );
$processor = new PriceProcessor( $repository, $logger );
$scheduler = new Scheduler( $repository, $processor, $logger );
$reports = array();
foreach ( array( 100, 1000 ) as $size ) {
	$campaign_id = $repository->create( array( 'title' => 'EXIN TEST benchmark ' . $size, 'start_utc' => gmdate( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS ), 'end_utc' => gmdate( 'Y-m-d H:i:s', time() + 4 * DAY_IN_SECONDS ), 'status' => 'draft' ) );
	$start = microtime( true );
	$q_start = $GLOBALS['wpdb']->num_queries;
	for ( $i = 0; $i < $size; ++$i ) {
		$product = new WC_Product_Simple();
		$product->set_name( sprintf( 'EXIN TEST benchmark %d-%04d', $campaign_id, $i ) );
		$product->set_status( 'publish' );
		$product->set_regular_price( '100.00' );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( 20 );
		$product->save();
		$selection->add( $campaign_id, $product->get_id(), '40.00' );
	}
	$fixture_seconds = microtime( true ) - $start;
	$fixture_queries = $GLOBALS['wpdb']->num_queries - $q_start;
	$repository->save( $campaign_id, array( 'status' => 'active' ) );
	$campaign = $repository->get( $campaign_id );
	$version = $campaign['version'];
	$after = 0;
	$batch_times = array();
	$batch_queries = 0;
	$start = microtime( true );
	while ( true ) {
		$rows = $repository->batch( $campaign_id, $after, 25 );
		if ( ! $rows ) {
			break;
		}
		$bstart = microtime( true );
		$qstart = $GLOBALS['wpdb']->num_queries;
		$scheduler->batch( $campaign_id, $version, $after, 'apply' );
		$batch_queries += $GLOBALS['wpdb']->num_queries - $qstart;
		$batch_times[] = microtime( true ) - $bstart;
		$after = end( $rows )['product_id'];
	}
	$apply_seconds = microtime( true ) - $start;
	$start = microtime( true );
	$qstart = $GLOBALS['wpdb']->num_queries;
	$query = new PublicProductQuery( $repository );
	$result = $query->query( $campaign, array( 'limit' => 12, 'page' => 1 ) );
	$page_seconds = microtime( true ) - $start;
	$page_queries = $GLOBALS['wpdb']->num_queries - $qstart;
	$start = microtime( true );
	$qstart = $GLOBALS['wpdb']->num_queries;
	$shortcode_html = do_shortcode( '[exin_bf_products limit="12" pagination="yes"]' );
	$shortcode_seconds = microtime( true ) - $start;
	$shortcode_queries = $GLOBALS['wpdb']->num_queries - $qstart;
	// A separate real HTTP request has its own WordPress request cache. Site URL
	// stays unchanged: Host matches the configured local site while port80 is
	// reached from inside the container rather than the host's forwarded8880.
	$coming_soon = get_option( 'woocommerce_coming_soon', 'no' );
	update_option( 'woocommerce_coming_soon', 'no' );
	$page_id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'EXIN benchmark ' . $campaign_id, 'post_content' => '[exin_bf_products limit="12" pagination="yes"]' ), true );
	if ( is_wp_error( $page_id ) ) {
		throw new RuntimeException( 'Benchmark public page creation failed.' );
	}
	try {
		$start = microtime( true );
		$http = wp_remote_get( 'http://127.0.0.1/?page_id=' . $page_id, array( 'headers' => array( 'Host' => 'localhost:8880' ), 'timeout' => 30, 'redirection' => 0 ) );
		$http_seconds = microtime( true ) - $start;
		$http_code = (int) wp_remote_retrieve_response_code( $http );
		$http_html = wp_remote_retrieve_body( $http );
	} finally {
		wp_delete_post( $page_id, true );
		update_option( 'woocommerce_coming_soon', $coming_soon );
	}
	$first = $repository->batch( $campaign_id, 0, 1 )[0];
	$last = $repository->batch( $campaign_id, $after - 1, 1 )[0];
	$first_name = wc_get_product( $first['product_id'] )->get_name();
	$verified = ( count( $result['products'] ) === 12 && $result['total'] === $size && wc_get_product( $first['product_id'] )->get_price( 'edit' ) == '70.00' && wc_get_product( $last['product_id'] )->get_price( 'edit' ) == '70.00' && str_contains( $shortcode_html, $first_name ) && 200 === $http_code && str_contains( $http_html, $first_name ) );
	if ( ! $verified ) {
		throw new RuntimeException( 'Benchmark result verification failed.' );
	}
	$reports[] = array( 'products' => $size, 'fixture_seconds' => round( $fixture_seconds, 4 ), 'fixture_queries' => $fixture_queries, 'apply_seconds' => round( $apply_seconds, 4 ), 'batches' => count( $batch_times ), 'batch_size' => 25, 'max_batch_seconds' => round( max( $batch_times ), 4 ), 'mean_batch_seconds' => round( array_sum( $batch_times ) / count( $batch_times ), 4 ), 'apply_queries' => $batch_queries, 'first_page_seconds' => round( $page_seconds, 4 ), 'first_page_queries' => $page_queries, 'shortcode_render_seconds' => round( $shortcode_seconds, 4 ), 'shortcode_render_queries' => $shortcode_queries, 'http_page_seconds' => round( $http_seconds, 4 ), 'http_status' => $http_code, 'http_response_bytes' => strlen( $http_html ), 'peak_memory_bytes' => memory_get_peak_usage( true ), 'verified' => $verified );
	echo 'EXIN_BENCHMARK_CASE ' . wp_json_encode( end( $reports ) ) . "\n";
}
echo 'EXIN_BENCHMARK_RESULT ' . wp_json_encode( array( 'wordpress' => get_bloginfo( 'version' ), 'woocommerce' => WC_VERSION, 'php' => PHP_VERSION, 'database' => $GLOBALS['wpdb']->db_version(), 'object_cache' => wp_using_ext_object_cache() ? 'external' : 'WordPress request memory', 'cases' => $reports ), JSON_PRETTY_PRINT ) . "\n";
