<?php
/** Focused v1.3.0 financial integration checks; synthetic local WordPress only. */

use Exin\BlackFriday\Application\BulkSelectionService;
use Exin\BlackFriday\Application\PriceProcessor;
use Exin\BlackFriday\Application\PriceState;
use Exin\BlackFriday\Application\ProductSelectionService;
use Exin\BlackFriday\Application\Scheduler;
use Exin\BlackFriday\Domain\DiscountPlan;
use Exin\BlackFriday\Infrastructure\CampaignRepository;
use Exin\BlackFriday\Infrastructure\Logger;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() || 'exin_test' !== DB_NAME ) {
	throw new RuntimeException( 'Run only in the isolated local exin_test fixture.' );
}
if ( ! class_exists( 'WooCommerce' ) || ! class_exists( BulkSelectionService::class ) ) {
	throw new RuntimeException( 'Real WooCommerce and the v1.3.0 source must be active.' );
}
add_filter( 'action_scheduler_allow_async_request_runner', '__return_false' );
add_filter( 'pre_wp_mail', '__return_true' );

$suite = new class() {
	public int $assertions = 0;
	public array $results = array();
	public function same( $expected, $actual, string $message ): void {
		++$this->assertions;
		if ( $expected !== $actual ) {
			throw new RuntimeException( $message . '; expected ' . wp_json_encode( $expected ) . ', got ' . wp_json_encode( $actual ) );
		}
	}
	public function test( string $name, callable $operation ): void {
		if ( getenv( 'EXIN_TEST_FILTER' ) && ! preg_match( getenv( 'EXIN_TEST_FILTER' ), $name ) ) { return; }
		try {
			$operation();
			$this->results[] = array( 'test' => $name, 'status' => 'passed' );
			echo 'PASS ' . $name . PHP_EOL;
		} catch ( Throwable $error ) {
			$this->results[] = array( 'test' => $name, 'status' => 'failed', 'error' => $error->getMessage() );
			echo 'FAIL ' . $name . ': ' . $error->getMessage() . PHP_EOL;
		}
	}
};
$repo = new CampaignRepository();
$selection = new ProductSelectionService( $repo );
$processor = new PriceProcessor( $repo, new Logger() );
$scheduler = new Scheduler( $repo, $processor, new Logger() );
$bulk = new BulkSelectionService( $repo );
$old_currency = get_option( 'woocommerce_currency' );
$old_precision = get_option( 'woocommerce_price_num_decimals' );
$old_user = get_current_user_id();
$administrators = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
if ( ! $administrators ) { throw new RuntimeException( 'A local fixture administrator is required.' ); }
wp_set_current_user( (int) $administrators[0] );
update_option( 'woocommerce_currency', 'USD' );
update_option( 'woocommerce_price_num_decimals', 2 );
$campaigns = array();
$nonce = wp_create_nonce( 'wp_rest' );
$rest = static function ( string $path, array $body = array(), int $status = 200 ) use ( $suite, $nonce ): array {
	$request = new WP_REST_Request( 'POST', '/exin-bf/v1/admin/' . $path );
	$request->set_header( 'X-WP-Nonce', $nonce );
	$request->set_header( 'Content-Type', 'application/json' );
	$request->set_body( wp_json_encode( $body ) );
	$response = rest_do_request( $request );
	$suite->same( $status, $response->get_status(), 'REST ' . $path . ': ' . wp_json_encode( $response->get_data() ) );
	return $response->get_data();
};
$product = static function ( string $name, string $price = '100.00' ): WC_Product_Simple {
	$item = new WC_Product_Simple();
	$item->set_name( 'EXIN130 ' . $name );
	$item->set_status( 'publish' );
	$item->set_virtual( true );
	$item->set_regular_price( $price );
	$item->set_manage_stock( true );
	$item->set_stock_quantity( 20 );
	$item->save();
	return $item;
};
$campaign = static function ( array $plan = array(), int $offset = -5, int $priority = 0 ) use ( $repo, &$campaigns ): int {
	$plan = DiscountPlan::normalize( array_replace( array( 'mode' => 'fixed_percent', 'initial_discount' => '5', 'steps' => 3, 'interval' => 'hourly' ), $plan ), wc_get_price_decimals() );
	$data = array(
		'title' => 'EXIN130 ' . wp_generate_uuid4(), 'status' => 'draft', 'start_utc' => gmdate( 'Y-m-d H:i:s', time() + $offset ),
		'timezone' => 'Asia/Tehran', 'days' => str_starts_with($plan['mode'],'auto_') ? (int)ceil($plan['duration_minutes']/1440) : $plan['steps'], 'strategy' => 'linear', 'power' => 2, 'rounding' => 'half_up',
		'settings' => array( 'discount_plan' => $plan, 'priority' => $priority, 'existing_sale_policy' => 'preserve', 'campaign_type' => 'general' ),
	);
	$data['end_utc'] = DiscountPlan::end( $data );
	$id = $repo->create( $data );
	$campaigns[] = $id;
	return $id;
};
$activate = static function ( int $id ) use ( $repo ): int {
	$settings = $repo->get( $id )['settings'];
	$settings['started_at'] = gmdate( 'Y-m-d H:i:s' );
	$repo->save( $id, array( 'status' => 'active', 'settings' => $settings ) );
	return $repo->get( $id )['version'];
};
$shift = static function ( int $id, int $offset ) use ( $repo ): void {
	$record = $repo->get( $id );
	$record['start_utc'] = gmdate( 'Y-m-d H:i:s', time() + $offset );
	$repo->save( $id, array( 'start_utc' => $record['start_utc'], 'end_utc' => DiscountPlan::end( $record ) ) );
};
$price = static fn( WC_Product $item ): string => PriceState::money( wc_get_product( $item->get_id() )->get_price( 'edit' ) );
$raw_item = static function ( int $id, int $pid ): array {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE campaign_id=%d AND product_id=%d', $wpdb->prefix . 'exin_bf_items', $id, $pid ), ARRAY_A );
};

$auto=static function(string$mode,array$extra=array(),int$offset=-5)use($campaign):int{return$campaign(array_replace(array('mode'=>'auto_'.$mode,'interval'=>'hourly','steps'=>3,'seed'=>'exin-tests-130','selection_mode'=>'count','selection_value'=>1),$extra),$offset);};
$finish=static function(int$id)use($repo,$processor):void{foreach($repo->items($id,1000)as$row){$processor->restore($id,$row['product_id']);}$repo->save($id,array('status'=>'completed'));};
try {
$suite->test('Inline bulk floors preserve prices, reject invalid and live edits, and stock search stays paged',static function()use($suite,$campaign,$product,$selection,$repo,$rest,$price,$activate):void{
$id=$campaign();$a=$product('floor SKU');$a->set_sku('EXIN131-unique');$a->save();$b=$product('empty');$b->set_stock_quantity(0);$b->save();foreach(array($a,$b)as$p){$selection->add($id,$p->get_id(),'50');}
$result=$rest('campaign/'.$id.'/bulk',array('action'=>'floors','items'=>array(array('product_id'=>$a->get_id(),'floor_price'=>'65.00'),array('product_id'=>$b->get_id(),'floor_price'=>'999'))));$suite->same(true,$result['results'][0]['ok'],'Valid floor saved');$suite->same(false,$result['results'][1]['ok'],'Unsafe floor rejected');$suite->same('65.00',$repo->item($id,$a->get_id())['floor_price'],'Floor persisted');$suite->same('50.00',$repo->item($id,$b->get_id())['floor_price'],'Old floor preserved on failure');$suite->same('100.00',$price($a),'Woo price unchanged');
$page=$repo->manager_items($id,1,'EXIN131-unique');$suite->same(1,$page['count'],'SKU search');$suite->same($a->get_id(),$page['items'][0]['product_id'],'Correct product');$suite->same(2,$repo->manager_items($id,1,'')['count'],'Selected unavailable member remains in manager');$suite->same(1,$repo->manager_items($id,1,(string)$a->get_id())['count'],'ID search');$suite->same(array(),$repo->manager_items($id,2,'')['items'],'Pagination bounded');
$activate($id);$rest('campaign/'.$id.'/bulk',array('action'=>'floors','items'=>array(array('product_id'=>$a->get_id(),'floor_price'=>'60'))),400);$suite->same('65.00',$repo->item($id,$a->get_id())['floor_price'],'Live financial lock preserved');
});
} finally {
	foreach ( $campaigns as $id ) {
		$record = $repo->get( $id );
		if ( $record && 'archived' !== $record['status'] ) { $repo->save( $id, array( 'status' => $repo->has_ownership( $id ) ? 'paused' : 'completed' ) ); }
	}
	update_option( 'woocommerce_currency', $old_currency ); update_option( 'woocommerce_price_num_decimals', $old_precision ); wp_set_current_user( $old_user );
}
$failed = count( array_filter( $suite->results, static fn( $item ) => 'failed' === $item['status'] ) );
echo 'EXIN_V130_RESULT ' . wp_json_encode( array( 'suite' => 'v131-floor-integration', 'wordpress' => get_bloginfo( 'version' ), 'woocommerce' => WC_VERSION, 'php' => PHP_VERSION, 'database' => $GLOBALS['wpdb']->db_version(), 'hpos' => \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled(), 'tests' => count( $suite->results ), 'assertions' => $suite->assertions, 'passed' => count( $suite->results ) - $failed, 'failed' => $failed, 'results' => $suite->results ) ) . PHP_EOL;
if ( $failed ) { throw new RuntimeException( 'v131_floor_integration_failed' ); }
