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

$smart=static function(array $extra=array(),int $offset=-172805)use($campaign):int{return$campaign(array_replace(array('mode'=>'smart_adaptive','duration_minutes'=>10080,'interval'=>'hourly','seed'=>'exin-smart-test-140','adaptive'=>array('expected_units_day'=>100,'cooldown_minutes'=>30,'exploration_bps'=>0)),$extra),$offset);};
$make_order=static function(int$id,WC_Product$p,int$qty=1,string$status='completed'):WC_Order{
 $o=wc_create_order();$o->set_currency('USD');$o->set_date_created(time()-3600);$line_id=$o->add_product(wc_get_product($p->get_id()),$qty);$line=$o->get_item($line_id);$line->add_meta_data('_exin_bf_campaign_id',$id,true);$line->add_meta_data('_exin_bf_product_id',$p->get_id(),true);$line->save();$o->update_meta_data('_exin_bf_campaign_'.$id,'1');$o->calculate_totals();$o->save();$o->update_status($status);return wc_get_order($o->get_id());
};
$history=static function(int$id,int$pid,int$intensity=2000)use($repo):void{global$wpdb;$c=$repo->get($id);$stage=DiscountPlan::index($c,time())-1;$d=array('intensity_bps'=>$intensity,'changed_at'=>time()-7200,'metrics'=>array('units'=>0,'seconds'=>86400),'direction'=>0,'price'=>DiscountPlan::automatic_price('100','50',$c,$stage,$pid,true,true,true,$intensity));$wpdb->insert($wpdb->prefix.'exin_bf_adaptive_decisions',array('campaign_id'=>$id,'version'=>$c['version'],'product_id'=>$pid,'stage'=>$stage,'decision'=>wp_json_encode($d),'created_at'=>gmdate('Y-m-d H:i:s',time()-7200)));};
try {
$suite->test('Smart cold start reuses immutable snapshot; retries freeze decisions; expiry restores prior sale',static function()use($suite,$smart,$product,$selection,$activate,$processor,$repo,$price,$scheduler,$shift):void{
 global$wpdb;$id=$smart();$p=$product('smart cold');$p->set_sale_price('80');$p->set_date_on_sale_to(time()+900000);$p->save();$before=PriceState::capture(wc_get_product($p->get_id()));$selection->add($id,$p->get_id(),'50');$v=$activate($id);$suite->same('changed',$processor->apply($id,$p->get_id(),$v),'Initial decision applies');$suite->same('95.00',$price($p),'Cold start uses regular headroom');$snapshot=$repo->item($id,$p->get_id())['snapshot'];$d=$wpdb->get_var($wpdb->prepare('SELECT decision FROM %i WHERE campaign_id=%d',$wpdb->prefix.'exin_bf_adaptive_decisions',$id));$operation=$repo->item($id,$p->get_id())['operation_id'];$suite->same('unchanged',$processor->apply($id,$p->get_id(),$v),'Duplicate replay unchanged');$suite->same($d,$wpdb->get_var($wpdb->prepare('SELECT decision FROM %i WHERE campaign_id=%d',$wpdb->prefix.'exin_bf_adaptive_decisions',$id)),'Committed decision immutable');$suite->same($operation,$repo->item($id,$p->get_id())['operation_id'],'No duplicate write');
 $shift($id,-604805);$scheduler->stage($id,$v,time()-1);$c=$repo->get($id);$scheduler->batch($id,$c['version'],0,'restore');$suite->same($before,PriceState::capture(wc_get_product($p->get_id())),'Natural expiry restores original sale');$suite->same($snapshot,$repo->item($id,$p->get_id())['snapshot'],'Original snapshot retained');
});
$suite->test('Real sales drive increase decrease and floor protection; refund status and deletion invalidate checkpoints',static function()use($suite,$smart,$product,$selection,$activate,$processor,$repo,$price,$make_order,$history,$finish):void{
 global$wpdb;
 foreach(array('weak'=>array(1000,1,'87.50'),'strong'=>array(5,100,'95.00'))as$kind=>[$stock,$qty,$expected]){
  $id=$smart();$p=$product('smart '.$kind);$p->set_stock_quantity($stock);$p->save();$selection->add($id,$p->get_id(),'50');if($kind==='weak'){$settings=$repo->get($id)['settings'];$settings['order_statuses']=array('wc-completed');$repo->save($id,array('settings'=>$settings));}$orders=array();for($i=0;$i<3;$i++)$orders[]=$make_order($id,$p,$qty);$p=wc_get_product($p->get_id());$p->set_stock_quantity($stock);$p->save();$v=$activate($id);$history($id,$p->get_id());$processor->apply($id,$p->get_id(),$v);$suite->same($expected,$price($p),'Sales and actual stock select direction '.$kind);$suite->same(3,(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE campaign_id=%d',$wpdb->prefix.'exin_bf_adaptive_sales',$id)),'Valid checkpoints recorded');
  $refund=wc_create_refund(array('order_id'=>$orders[0]->get_id(),'amount'=>$orders[0]->get_total(),'line_items'=>array(array_key_first($orders[0]->get_items())=>array('qty'=>$qty,'refund_total'=>$orders[0]->get_total())),'refund_payment'=>false,'restock_items'=>false));$suite->same(false,is_wp_error($refund),'Real refund saved');(new \Exin\BlackFriday\Integration\Orders($repo))->invalidate($refund->get_id());$suite->same(false,(bool)get_option('exin_bf_adaptive_error_global'),'Refund entity resolves to parent without poisoning metrics');$orders[1]->update_status('cancelled');$orders[2]->update_status('failed');$suite->same(0,(int)$wpdb->get_var($wpdb->prepare('SELECT COALESCE(SUM(units),0) FROM %i WHERE campaign_id=%d',$wpdb->prefix.'exin_bf_adaptive_sales',$id)),'Refund and invalid statuses remove units');
  $processor->apply($id,$p->get_id(),$v);$suite->same($expected,$price($p),'Same-stage replay does not reroll after refund');$orders[2]=wc_get_order($orders[2]->get_id());$orders[2]->update_status('completed');$suite->same($qty,(int)$wpdb->get_var($wpdb->prepare('SELECT SUM(units) FROM %i WHERE campaign_id=%d',$wpdb->prefix.'exin_bf_adaptive_sales',$id)),'Status recovery adds only valid sale');$orders[2]->delete(true);$suite->same(0,(int)$wpdb->get_var($wpdb->prepare('SELECT COALESCE(SUM(units),0) FROM %i WHERE campaign_id=%d',$wpdb->prefix.'exin_bf_adaptive_sales',$id)),'Hard delete removes sale');$finish($id);
 }
});
$suite->test('Shared-stock variations leave discounts when sold out and restore the same decision on restock',static function()use($suite,$smart,$product,$selection,$activate,$processor,$repo,$price,$finish):void{
 $id=$smart();$parent=new WC_Product_Variable();$parent->set_name('EXIN140 parent');$parent->set_status('publish');$parent->set_manage_stock(true);$parent->set_stock_quantity(5);$parent->save();$p=new WC_Product_Variation();$p->set_parent_id($parent->get_id());$p->set_status('publish');$p->set_regular_price('100');$p->set_manage_stock(false);$p->save();$selection->add($id,$p->get_id(),'50');$v=$activate($id);$processor->apply($id,$p->get_id(),$v);$suite->same('95.00',$price($p),'Variation gets cold start');$parent=wc_get_product($parent->get_id());$parent->set_stock_quantity(0);$parent->save();$processor->apply($id,$p->get_id(),$v);$suite->same('100.00',$price($p),'Shared stock withdrawal removes discount');$parent=wc_get_product($parent->get_id());$parent->set_stock_quantity(5);$parent->save();$processor->apply($id,$p->get_id(),$v);$suite->same('95.00',$price($p),'Restock reuses committed stage decision');$finish($id);
});
$suite->test('External prices remain protected; scheduler delay commits only current stage; private preview has no future seed',static function()use($suite,$smart,$product,$selection,$activate,$processor,$repo,$price,$scheduler,$rest,$finish):void{
 global$wpdb;$id=$smart();$p=$product('smart delayed');$selection->add($id,$p->get_id(),'50');$v=$activate($id);$scheduler->stage($id,$v,time()-1);$scheduler->batch($id,$v);$suite->same(array('48'),$wpdb->get_col($wpdb->prepare('SELECT stage FROM %i WHERE campaign_id=%d',$wpdb->prefix.'exin_bf_adaptive_decisions',$id)),'Delayed execution skips old stages');$preview=$rest('campaign/'.$id.'/simulation');$suite->same(null,$preview['next_stage'],'No future decisions');$req=new WP_REST_Request('GET','/exin-bf/v1/time');$req->set_param('campaign_id',$id);$public=rest_do_request($req)->get_data();$suite->same(null,$public['stage_ends_at'],'Public countdown never promises next smart discount');$suite->same(false,str_contains(wp_json_encode($public),'seed'),'No public seed');$suite->same(false,str_contains(wp_json_encode($preview),'exin-smart-test-140'),'Private seed not in preview');$p=wc_get_product($p->get_id());$p->set_sale_price('77');$p->save();$suite->same('error',$processor->apply($id,$p->get_id(),$v),'External conflict detected');$suite->same('77.00',$price($p),'External price is not overwritten');$suite->same('conflict',$repo->item($id,$p->get_id())['status'],'Conflict is durable');
});
$suite->test('Explicit draft conversion preserves all old floors and selections; live settings cannot opt in',static function()use($suite,$campaign,$product,$selection,$repo,$rest,$raw_item,$activate,$price):void{
 $id=$campaign();$p=$product('smart draft preservation');$selection->add($id,$p->get_id(),'68.12');$before=$raw_item($id,$p->get_id());$c=$repo->get($id);$body=array('id'=>$id,'title'=>$c['title'],'days'=>7,'start_local'=>(new DateTimeImmutable('+1 hour',new DateTimeZone('Asia/Tehran')))->format('Y-m-d\TH:i'),'timezone'=>'Asia/Tehran','settings'=>array('discount_plan'=>array('mode'=>'smart_adaptive','interval'=>'hourly','adaptive'=>array('preset'=>'balanced'))));$new=$rest('campaign',$body)['campaign'];$suite->same($before,$raw_item($id,$p->get_id()),'Floor override schedule and snapshot bytes unchanged');$suite->same('smart_adaptive',$new['settings']['discount_plan']['mode'],'Only explicit draft opt-in');$suite->same('100.00',$price($p),'Draft edit never reprices');$rest('campaign/'.$id.'/action',array('action'=>'schedule','confirm_activate'=>true));$suite->same('scheduled',$repo->get($id)['status'],'Actual smart readiness and scheduler activation route');$rest('campaign',$body,400);$suite->same($before,$raw_item($id,$p->get_id()),'Rejected active edit preserves data');
});

$suite->test('Actual smart price can peak safely before final day and corrupt floors fail before writes',static function()use($suite,$smart,$product,$selection,$activate,$processor,$repo,$price,$history,$make_order,$finish):void{
 $id=$smart(array('adaptive'=>array('preset'=>'aggressive','expected_units_day'=>100,'cooldown_minutes'=>30,'exploration_bps'=>0)));$p=$product('smart exact floor');$p->set_stock_quantity(10000);$p->save();$selection->add($id,$p->get_id(),'50');for($i=0;$i<3;$i++)$make_order($id,$p,1);$v=$activate($id);$history($id,$p->get_id(),9500);$processor->apply($id,$p->get_id(),$v);$suite->same('50.00',$price($p),'Can reach floor on day three, without a final-day guarantee');$repo->put_item($id,$p->get_id(),array('floor_price'=>'0'));$failed=false;try{$processor->apply($id,$p->get_id(),$v);}catch(InvalidArgumentException$e){$failed=true;}$suite->same(true,$failed,'Corrupt zero floor blocks even a committed decision');$suite->same('50.00',$price($p),'Invalid inputs never write');$repo->put_item($id,$p->get_id(),array('floor_price'=>'50.00'));$finish($id);
});
$suite->test('Smart prices reuse coupon floor protection and do not rewrite completed order totals',static function()use($suite,$smart,$product,$selection,$activate,$processor,$repo,$rest,$finish):void{
 wc_load_cart();WC()->cart->empty_cart();update_option('woocommerce_calc_taxes','no');$id=$smart();$p=$product('smart coupon');$plain=$product('smart plain');$selection->add($id,$p->get_id(),'70');$v=$activate($id);$processor->apply($id,$p->get_id(),$v);$rest('campaign/'.$id.'/presentation',array('coupon_floor_policy'=>'protect'));
 $key=WC()->cart->add_to_cart($p->get_id(),2);WC()->cart->add_to_cart($plain->get_id(),1);WC()->cart->calculate_totals();$order=wc_create_order();$order->add_product(wc_get_product($p->get_id()),2);$order->calculate_totals();$saved=$order->get_total();$coupon=new WC_Coupon();$coupon->set_code('exin140-'.wp_generate_uuid4());$coupon->set_discount_type('percent');$coupon->set_amount('80');$coupon->save();WC()->cart->apply_coupon($coupon->get_code());WC()->cart->calculate_totals();$suite->same('194.00',wc_format_decimal(WC()->cart->get_cart()[$key]['line_total'],2),'Campaign lines retain smart price under protected coupon');$suite->same(true,WC()->cart->get_discount_total()>0,'Ordinary products still accept coupon');WC()->cart->cart_contents[$key]['line_total']='100.00';$errors=new WP_Error();(new \Exin\BlackFriday\Integration\Cart($repo))->blocks_errors($errors,WC()->cart);$suite->same(true,$errors->has_errors(),'External checkout incentives cannot bypass opted-in floor checks');$suite->same($saved,wc_get_order($order->get_id())->get_total(),'Saved totals remain immutable');WC()->cart->empty_cart();$coupon->delete(true);$finish($id);
});
$suite->test('Live smart enrollment uses one coalesced wake-up and preserves old floors until worker evaluation',static function()use($suite,$smart,$product,$selection,$activate,$processor,$repo,$price,$scheduler,$finish):void{
 $id=$smart();$a=$product('smart existing member');$b=$product('smart new member');$selection->add($id,$a->get_id(),'50');$v=$activate($id);$processor->apply($id,$a->get_id(),$v);$old=$repo->item($id,$a->get_id());$dates=$repo->get($id);$selection->add($id,$b->get_id(),'60');$selection->apply_current($id,$b->get_id());$suite->same(1,get_option('exin_bf_auto_stock_'.$id),'One shared dirty flag');$suite->same('100.00',$price($b),'Enrollment request performs no adaptive analysis or price write');$suite->same(false,(bool)as_has_scheduled_action('exin_bf_retry',array($id,$b->get_id(),$v,'apply',0),Scheduler::GROUP),'No independent per-product wake-up');$scheduler->tick();$scheduler->batch($id,$v);$suite->same('96.00',$price($b),'Existing worker applies current conservative decision');$after=$repo->item($id,$a->get_id());foreach(array('floor_price','floor_override','snapshot','expected','operation_id')as$key)$suite->same($old[$key],$after[$key],'Old financial state stays unchanged: '.$key);foreach(array('version','start_utc','end_utc')as$key)$suite->same($dates[$key],$repo->get($id)[$key],'Campaign timing/version unchanged');$finish($id);
});
$suite->test('Two concurrent workers commit one decision and one financial price',static function()use($suite,$smart,$product,$selection,$activate,$repo,$price,$finish):void{
 global$wpdb;$id=$smart();$p=$product('smart race');$selection->add($id,$p->get_id(),'50');$v=$activate($id);$root=dirname(__DIR__,2);$gate=sys_get_temp_dir().'/exin140-race-'.wp_generate_uuid4();@unlink($gate);$env=getenv();$env['EXIN140_CID']=(string)$id;$env['EXIN140_PID']=(string)$p->get_id();$env['EXIN140_VERSION']=(string)$v;$env['EXIN140_GATE']=$gate;$env['EXIN140_WP_ROOT']=ABSPATH;
 $cmd=getenv('EXIN_NATIVE_WP')?array($root.'/scripts/php-v101.sh',$root.'/work/runtime/wp-cli.phar','--path='.getenv('EXIN_NATIVE_WP'),'--allow-root','eval-file',$root.'/tests/integration/v140-race.php'):array(PHP_BINARY,$root.'/tests/integration/v140-race.php');$pipes=array();$processes=array();for($i=0;$i<2;$i++){$processes[$i]=proc_open($cmd,array(0=>array('pipe','r'),1=>array('pipe','w'),2=>array('pipe','w')),$pipes[$i],null,$env);fclose($pipes[$i][0]);}file_put_contents($gate,'go');$results=array();foreach($processes as$i=>$process){$out=stream_get_contents($pipes[$i][1]);$err=stream_get_contents($pipes[$i][2]);fclose($pipes[$i][1]);fclose($pipes[$i][2]);$suite->same(0,proc_close($process),'Concurrent child exit: '.$err);preg_match('/RACE_RESULT:(changed|unchanged)/',$out,$match);$results[]=$match[1]??$out;}sort($results);$suite->same(array('changed','unchanged'),$results,'One write and one deterministic replay');$suite->same(1,(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE campaign_id=%d',$wpdb->prefix.'exin_bf_adaptive_decisions',$id)),'Unique persisted decision');wp_cache_flush();$suite->same('95.00',$price($p),'Concurrent price respects floor');$finish($id);@unlink($gate);
});
} finally {
	foreach ( $campaigns as $id ) {
		$record = $repo->get( $id );
		if ( $record && 'archived' !== $record['status'] ) { $repo->save( $id, array( 'status' => $repo->has_ownership( $id ) ? 'paused' : 'completed' ) ); }
	}
	update_option( 'woocommerce_currency', $old_currency ); update_option( 'woocommerce_price_num_decimals', $old_precision ); wp_set_current_user( $old_user );
}
$failed = count( array_filter( $suite->results, static fn( $item ) => 'failed' === $item['status'] ) );
echo 'EXIN_V140_RESULT ' . wp_json_encode( array( 'suite' => 'v140-smart-integration', 'wordpress' => get_bloginfo( 'version' ), 'woocommerce' => WC_VERSION, 'php' => PHP_VERSION, 'database' => $GLOBALS['wpdb']->db_version(), 'hpos' => \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled(), 'tests' => count( $suite->results ), 'assertions' => $suite->assertions, 'passed' => count( $suite->results ) - $failed, 'failed' => $failed, 'results' => $suite->results ) ) . PHP_EOL;
if ( $failed ) { throw new RuntimeException( 'v140_financial_integration_failed' ); }
