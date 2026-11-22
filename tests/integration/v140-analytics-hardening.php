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

$analytics = new \Exin\BlackFriday\Application\AdaptiveAnalytics($repo);
$old_activate=$activate;$activate=static function(int$id)use($old_activate,$analytics):int{$v=$old_activate($id);$analytics->run($id);return$v;};
$smart=static function(array $extra=array(),int $offset=-172805)use($campaign):int{return$campaign(array_replace(array('mode'=>'smart_adaptive','duration_minutes'=>10080,'interval'=>'hourly','seed'=>'exin-smart-test-140','adaptive'=>array('expected_units_day'=>100,'cooldown_minutes'=>30,'exploration_bps'=>0)),$extra),$offset);};
$make_order=static function(int$id,WC_Product$p,int$qty=1,string$status='completed'):WC_Order{
 $o=wc_create_order();$o->set_currency('USD');$o->set_date_created(time()-3600);$line_id=$o->add_product(wc_get_product($p->get_id()),$qty);$line=$o->get_item($line_id);$line->add_meta_data('_exin_bf_campaign_id',$id,true);$line->add_meta_data('_exin_bf_product_id',$p->get_id(),true);$line->save();$o->update_meta_data('_exin_bf_campaign_'.$id,'1');$o->calculate_totals();$o->save();$o->update_status($status);return wc_get_order($o->get_id());
};
$history=static function(int$id,int$pid,int$intensity=2000)use($repo):void{global$wpdb;$c=$repo->get($id);$stage=DiscountPlan::index($c,time())-1;$d=array('intensity_bps'=>$intensity,'changed_at'=>time()-7200,'metrics'=>array('units'=>0,'seconds'=>86400),'direction'=>0,'price'=>DiscountPlan::automatic_price('100','50',$c,$stage,$pid,true,true,true,$intensity));$wpdb->insert($wpdb->prefix.'exin_bf_adaptive_decisions',array('campaign_id'=>$id,'version'=>$c['version'],'product_id'=>$pid,'stage'=>$stage,'decision'=>wp_json_encode($d),'created_at'=>gmdate('Y-m-d H:i:s',time()-7200)));};

try {
$suite->test('Pricing missing or invalid snapshots holds safe prices and never scans orders',static function()use($suite,$smart,$product,$selection,$old_activate,$processor,$repo,$price,$analytics,$shift,$finish):void{
 global$wpdb;$id=$smart();$p=$product('unavailable snapshot');$p->set_sale_price('80');$p->save();$selection->add($id,$p->get_id(),'50');$v=$old_activate($id);$scans=0;$filter=static function($q)use(&$scans){$scans++;return$q;};add_filter('woocommerce_order_query_args',$filter);$processor->apply($id,$p->get_id(),$v);$suite->same('80.00',$price($p),'Initial safe sale preserved until analytics ready');$suite->same(0,(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE campaign_id=%d',$wpdb->prefix.'exin_bf_adaptive_decisions',$id)),'No fabricated cold decision');$analytics->run($id);$processor->apply($id,$p->get_id(),$v);$suite->same('95.00',$price($p),'Validated metrics enable original strategy');$shift($id,-49*3600-5);$wpdb->update($wpdb->prefix.'exin_bf_adaptive_metrics',['validated_revision'=>0],['campaign_id'=>$id,'product_id'=>$p->get_id()]);$processor->apply($id,$p->get_id(),$v);$suite->same('95.00',$price($p),'Last committed safe price held');$suite->same(0,$scans,'No Woo order scans even during analytics recovery');remove_filter('woocommerce_order_query_args',$filter);$finish($id);
});
$suite->test('Durable outbox retries a failed checkpoint transaction without counting twice',static function()use($suite,$smart,$product,$selection,$old_activate,$processor,$repo,$analytics,$make_order,$finish):void{
 global$wpdb;$id=$smart();$p=$product('checkpoint retry');$selection->add($id,$p->get_id(),'50');$o=$make_order($id,$p,2);$old_activate($id);$analytics->run($id);$observer=new Exin\BlackFriday\Integration\Orders($repo);for($i=0;$i<3;$i++)$observer->invalidate($o->get_id());$suite->same(1,(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE campaign_id=%d',$wpdb->prefix.'exin_bf_adaptive_events',$id)),'Repeated delivery coalesced');$suite->same(null,$analytics->snapshot($repo->get($id),$p->get_id()),'Pending event invalidates snapshot immediately');$injected=false;$filter=static function($q)use(&$injected,$wpdb){if(!$injected&&str_starts_with($q,'INSERT INTO `'.$wpdb->prefix.'exin_bf_adaptive_sales`')){$injected=true;return 'INVALID_SQL_ISOLATED_CHECKPOINT';}return$q;};$old=$wpdb->suppress_errors(true);add_filter('query',$filter);$failed=false;try{$analytics->run($id);}catch(RuntimeException$e){$failed=true;}remove_filter('query',$filter);$wpdb->suppress_errors($old);$suite->same(true,$injected&&$failed,'Injected storage failure observed');$suite->same(1,(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE campaign_id=%d',$wpdb->prefix.'exin_bf_adaptive_events',$id)),'Event not acknowledged after rollback');$analytics->run($id);$suite->same(2,(int)$wpdb->get_var($wpdb->prepare('SELECT SUM(units) FROM %i WHERE campaign_id=%d',$wpdb->prefix.'exin_bf_adaptive_sales',$id)),'Retry counted current order once');$suite->same(0,(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE campaign_id=%d',$wpdb->prefix.'exin_bf_adaptive_events',$id)),'Successful revision acknowledged');$suite->same(2,$analytics->snapshot($repo->get($id),$p->get_id())['units'],'Only validated snapshot published');$finish($id);
});
$suite->test('Only inconsistent checkpoints trigger bounded recovery; normal expiry never rebuilds sales',static function()use($suite,$smart,$product,$selection,$old_activate,$repo,$analytics,$make_order,$finish):void{
 global$wpdb;$id=$smart();$p=$product('bounded recovery');$selection->add($id,$p->get_id(),'50');$a=$make_order($id,$p);$b=$make_order($id,$p);$old_activate($id);$analytics->run($id);$wpdb->delete($wpdb->prefix.'exin_bf_adaptive_sales',['campaign_id'=>$id,'order_id'=>$a->get_id()]);update_option('exin_bf_adaptive_error_'.$id,'isolated_inconsistency',false);$analytics->run($id);$suite->same(2,(int)$wpdb->get_var($wpdb->prepare('SELECT SUM(units) FROM %i WHERE campaign_id=%d',$wpdb->prefix.'exin_bf_adaptive_sales',$id)),'Missing checkpoint recovered');$recoveries=0;$broad_deletes=0;$filter=static function($q)use(&$recoveries,&$broad_deletes){if(str_contains($q,') recovery WHERE'))$recoveries++;if(str_starts_with($q,'DELETE')&&str_contains($q,'adaptive_sales')&&!str_contains($q,'order_id'))$broad_deletes++;return$q;};add_filter('query',$filter);$wpdb->update($wpdb->prefix.'exin_bf_adaptive_metrics',['valid_until'=>'1970-01-01 00:00:00'],['campaign_id'=>$id]);$analytics->run($id);$analytics->run($id);remove_filter('query',$filter);$suite->same(0,$recoveries,'Routine snapshot expiry does not enumerate Woo orders');$suite->same(0,$broad_deletes,'No full-window checkpoint deletes');$suite->same(2,$analytics->snapshot($repo->get($id),$p->get_id())['units'],'Expired metrics rebuilt from durable checkpoints only');$finish($id);
});
$suite->test('Deleted orders and stale repeated events never resurrect counted sales',static function()use($suite,$smart,$product,$selection,$old_activate,$repo,$analytics,$make_order,$finish):void{
 global$wpdb;$id=$smart();$p=$product('deleted event');$selection->add($id,$p->get_id(),'50');$o=$make_order($id,$p,2);$old_activate($id);$analytics->run($id);$oid=$o->get_id();$stale=clone$o;$o->delete(true);$analytics->run($id);$suite->same(0,(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE campaign_id=%d',$wpdb->prefix.'exin_bf_adaptive_sales',$id)),'Deletion drained');$analytics->enqueue($oid,$stale);$analytics->run($id);$suite->same(0,(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE campaign_id=%d',$wpdb->prefix.'exin_bf_adaptive_sales',$id)),'Fresh Woo state overrides a stale producer object');$finish($id);
});
} finally {
	foreach ( $campaigns as $id ) {
		$record = $repo->get( $id );
		if ( $record && 'archived' !== $record['status'] ) { $repo->save( $id, array( 'status' => $repo->has_ownership( $id ) ? 'paused' : 'completed' ) ); }
	}
	update_option( 'woocommerce_currency', $old_currency ); update_option( 'woocommerce_price_num_decimals', $old_precision ); wp_set_current_user( $old_user );
}
$failed = count( array_filter( $suite->results, static fn( $item ) => 'failed' === $item['status'] ) );
echo 'EXIN_HARDENING_RESULT ' . wp_json_encode( array( 'suite' => 'v140-analytics-hardening', 'wordpress' => get_bloginfo( 'version' ), 'woocommerce' => WC_VERSION, 'php' => PHP_VERSION, 'database' => $GLOBALS['wpdb']->db_version(), 'hpos' => \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled(), 'tests' => count( $suite->results ), 'assertions' => $suite->assertions, 'passed' => count( $suite->results ) - $failed, 'failed' => $failed, 'results' => $suite->results ) ) . PHP_EOL;
if ( $failed ) { throw new RuntimeException( 'v140_financial_integration_failed' ); }
