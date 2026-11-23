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
    $suite->test('Automatic progressive descending and wave use regular-price headroom and floor; late and duplicate writes do not compound',static function()use($suite,$auto,$product,$selection,$activate,$processor,$repo,$shift,$price,$finish):void{
        foreach(array('progressive'=>array('95.00','50.00'),'descending'=>array('50.00','95.00'),'wave'=>array('95.00','50.00'))as$mode=>$expected){
            $id=$auto($mode);$p=$product($mode);$p->set_sale_price('80');$p->set_date_on_sale_from(time()-3600);$p->set_date_on_sale_to(time()+86400);$p->save();$before=PriceState::capture(wc_get_product($p->get_id()));$selection->add($id,$p->get_id(),'50');$v=$activate($id);
            $processor->apply($id,$p->get_id(),$v);$suite->same($expected[0],$price($p),'Automatic initial price is relative to regular, not prior sale');$snapshot=$repo->item($id,$p->get_id())['snapshot'];$operation=$repo->item($id,$p->get_id())['operation_id'];
            $suite->same('unchanged',$processor->apply($id,$p->get_id(),$v),'Duplicate does not write again');$suite->same($operation,$repo->item($id,$p->get_id())['operation_id'],'Operation unchanged on replay');
            $shift($id,-2*HOUR_IN_SECONDS-5);$processor->apply($id,$p->get_id(),$v);$suite->same($expected[1],$price($p),'Late callback applies current stage directly');$suite->same($snapshot,$repo->item($id,$p->get_id())['snapshot'],'Snapshot is immutable');$finish($id);$suite->same($before,PriceState::capture(wc_get_product($p->get_id())),'Final restore includes prior sale schedule');$processor->restore($id,$p->get_id());$suite->same($before,PriceState::capture(wc_get_product($p->get_id())),'Restore replay is idempotent');
        }
    });
    $suite->test('Rotation is balanced and featured-first; an expired special turn clears the old sale and returns to regular',static function()use($suite,$auto,$product,$selection,$activate,$processor,$repo,$shift,$price,$finish):void{
        $id=$auto('rotation',array('steps'=>4));$products=array();for($i=0;$i<4;$i++){$p=$product('rotation '.$i);$p->set_sale_price('80');$p->save();$products[]=$p;$selection->add($id,$p->get_id(),'50',array('featured'=>$i===0,'priority'=>10));}$zero=$product('rotation zero headroom');$selection->add($id,$zero->get_id(),'100',array('featured'=>true,'priority'=>0));$v=$activate($id);$seen=array();
        for($stage=0;$stage<4;$stage++){$shift($id,-$stage*HOUR_IN_SECONDS-5);$discounted=array();foreach($products as$p){$processor->apply($id,$p->get_id(),$v);$suite->same('100.00',PriceState::money(wc_get_product($p->get_id())->get_regular_price('edit')),'Regular price is never rewritten');if($price($p)==='50.00'){$discounted[]=$p->get_id();}else{$suite->same('100.00',$price($p),'Off-turn price is regular, not saved sale');$suite->same('',wc_get_product($p->get_id())->get_sale_price('edit'),'Off-turn old sale is suppressed');}}
            $suite->same(1,count($discounted),'Exactly one special member per stage');if($stage===0){$suite->same($products[0]->get_id(),$discounted[0],'Featured item has first priority');}$seen[]=$discounted[0];}
        $suite->same(4,count(array_unique($seen)),'Every eligible product participates before a repeat');$processor->apply($id,$zero->get_id(),$v);$suite->same('100.00',$price($zero),'Zero-headroom product stays regular and cannot monopolize the rotation');$finish($id);foreach($products as$p){$suite->same('80.00',$price($p),'Prior sale restored at campaign end');}
    });
    $suite->test('Random preview equals real pricing, seed and cohort survive replay, and delayed scheduler skips missed stages',static function()use($suite,$auto,$product,$selection,$activate,$processor,$scheduler,$repo,$rest,$shift,$price,$finish):void{
        global $wpdb;$id=$auto('random',array('steps'=>5,'selection_value'=>2));$products=array();for($i=0;$i<4;$i++){$p=$product('random '.$i);$products[]=$p;$selection->add($id,$p->get_id(),'50');}
        $preview=$rest('campaign/'.$id.'/simulation');$suite->same(1,$preview['page'],'Automatic preview is paged');$suite->same(0,$preview['rejected_on_page'],'All valid members preview');$expected=array();foreach($preview['samples']as$s){$expected[$s['product_id']]=$s['schedule'][0];}$v=$activate($id);$scheduler->batch($id,$v);
        foreach($products as$p){$suite->same($expected[$p->get_id()],$price($p),'Random preview and runtime agree');}$run=$repo->get($id)['settings']['last_run'];$scheduler->batch($id,$v);$suite->same($run,$repo->get($id)['settings']['last_run'],'Duplicate batch cannot reroll or recount');
        $cohort=$wpdb->get_col($wpdb->prepare('SELECT product_id FROM %i WHERE campaign_id=%d AND stage=0 AND product_id>0 ORDER BY product_id',$wpdb->prefix.'exin_bf_turns',$id));$suite->same(2,count($cohort),'Exactly two selected members');
        $shift($id,-4*HOUR_IN_SECONDS-5);$scheduler->batch($id,$v);$stages=$wpdb->get_col($wpdb->prepare('SELECT DISTINCT stage FROM %i WHERE campaign_id=%d ORDER BY stage',$wpdb->prefix.'exin_bf_turns',$id));$suite->same(array('0','4'),$stages,'Late job does not replay missed financial stages');
        $before=$wpdb->get_results($wpdb->prepare('SELECT product_id FROM %i WHERE campaign_id=%d AND stage=4 ORDER BY product_id',$wpdb->prefix.'exin_bf_turns',$id),ARRAY_A);$processor->apply($id,$products[0]->get_id(),$v);$suite->same($before,$wpdb->get_results($wpdb->prepare('SELECT product_id FROM %i WHERE campaign_id=%d AND stage=4 ORDER BY product_id',$wpdb->prefix.'exin_bf_turns',$id),ARRAY_A),'Current cohort immutable on direct retry');$finish($id);
    });
    $suite->test('Hybrid specials replace the progressive base and rotate back to the current base without stacking',static function()use($suite,$auto,$product,$selection,$activate,$processor,$repo,$shift,$price,$finish):void{
        $id=$auto('hybrid');$a=$product('hybrid featured');$b=$product('hybrid second');$selection->add($id,$a->get_id(),'50',array('featured'=>true));$selection->add($id,$b->get_id(),'50');$v=$activate($id);foreach(array($a,$b)as$p){$processor->apply($id,$p->get_id(),$v);}$suite->same('50.00',$price($a),'Special is a single floor-bounded decision');$suite->same('95.00',$price($b),'Non-special receives progressive base');
        $shift($id,-HOUR_IN_SECONDS-5);foreach(array($a,$b)as$p){$processor->apply($id,$p->get_id(),$v);}$suite->same('87.50',$price($a),'Expired special returns to the new stage base');$suite->same('50.00',$price($b),'Next member gets special, never base plus special');$finish($id);
    });
    $suite->test('Flash is capacity guarded and last-day policies default off, apply only to allowed floors, and retain turn history',static function()use($suite,$auto,$product,$selection,$activate,$processor,$repo,$shift,$price,$finish):void{
        $id=$auto('progressive',array('interval'=>'flash','interval_minutes'=>5));$service=new \Exin\BlackFriday\Application\AutomaticSelection($repo);$hit=false;try{$service->capacity($repo->get($id),1000);}catch(DomainException$e){$hit=true;}$suite->same(true,$hit,'Too-small flash interval rejected using bounded batch budget');$suite->same('disabled',$repo->get($id)['settings']['discount_plan']['last_day'],'Last-day override defaults disabled');
        foreach(array('all_floor','remaining_floor')as$policy){$id=$auto('rotation',array('interval'=>'daily','steps'=>3,'last_day'=>$policy));$products=array();for($i=0;$i<3;$i++){$p=$product('last '.$policy.' '.$i);$products[]=$p;$selection->add($id,$p->get_id(),'50',array('featured'=>$i===0));}$v=$activate($id);foreach($products as$p){$processor->apply($id,$p->get_id(),$v);}$shift($id,-2*DAY_IN_SECONDS-5);foreach($products as$p){$processor->apply($id,$p->get_id(),$v);}
            if($policy==='all_floor'){foreach($products as$p){$suite->same('50.00',$price($p),'All eligible products reach their floor on last day');}}else{$suite->same('100.00',$price($products[0]),'Previously served special is excluded from remaining override');$suite->same('50.00',$price($products[1]),'Unserved member reaches floor');$suite->same('50.00',$price($products[2]),'Unserved member reaches floor');}$finish($id);}

        $id=$auto('rotation',array('interval'=>'hourly','steps'=>3,'last_day'=>'remaining_floor'));$left=$product('last-day stable A');$right=$product('last-day stable B');foreach(array($left,$right)as$p){$selection->add($id,$p->get_id(),'50');}$v=$activate($id);foreach(array($left,$right)as$p){$processor->apply($id,$p->get_id(),$v);}$shift($id,-HOUR_IN_SECONDS-5);foreach(array($left,$right)as$p){$processor->apply($id,$p->get_id(),$v);$suite->same('50.00',$price($p),'Last-day remaining set is stable after its first special application');}$finish($id);
    });
    $suite->test('Bulk floors and exceptions work with automatic stages; stock withdrawal and restock coalesce through existing scheduler',static function()use($suite,$auto,$product,$selection,$activate,$scheduler,$repo,$rest,$price,$finish):void{
        $id=$auto('progressive',array(),-HOUR_IN_SECONDS-5);$parent=new WC_Product_Variable();$parent->set_name('EXIN130 shared stock');$parent->set_status('publish');$parent->set_manage_stock(true);$parent->set_stock_quantity(5);$parent->save();$p=new WC_Product_Variation();$p->set_parent_id($parent->get_id());$p->set_status('publish');$p->set_regular_price('100');$p->set_manage_stock(false);$p->save();$q=$product('stock exception');$selection->add($id,$p->get_id(),'50');
        $job=$rest('campaign/'.$id.'/selection-jobs',array('filters'=>array('scope'=>'selected','ids'=>array($p->get_id(),$q->get_id())),'floor_policy'=>array('mode'=>'shared','value'=>'70','floors'=>array($q->get_id()=>'80'))))['job'];$job=$rest('campaign/'.$id.'/selection-jobs/'.$job['token'])['job'];$suite->same(1,$job['added'],'One draft automatic new member');$v=$activate($id);$scheduler->batch($id,$v);$suite->same('89.00',$price($q),'Group exception floor 80 gives stage-two price 89');$suite->same('72.50',$price($p),'Existing manual floor unchanged by draft bulk');$rest('campaign/'.$id.'/selection-jobs',array('filters'=>array('scope'=>'selected','ids'=>array($q->get_id())),'floor_policy'=>array('mode'=>'shared','value'=>'70')),400);$suite->same('80.00',$repo->item($id,$q->get_id())['floor_price'],'Active membership request cannot change floors');
        $stock=wc_get_product($parent->get_id());$stock->set_stock_quantity(0);$stock->save();$suite->same(1,get_option('exin_bf_auto_stock_'.$id),'Stock change sets one coalesced flag');$scheduler->tick();$scheduler->batch($id,$v);$suite->same('100.00',$price($p),'Out-of-stock product exits its campaign discount');$suite->same('sold_out',$repo->item($id,$p->get_id())['status'],'Unavailable state recorded');
        $stock=wc_get_product($parent->get_id());$stock->set_stock_quantity(5);$stock->save();$scheduler->tick();$scheduler->batch($id,$v);$suite->same('72.50',$price($p),'Restock receives current, not first, stage');$stock=wc_get_product($parent->get_id());$stock->set_stock_quantity(4);$stock->save();$suite->same(false,get_option('exin_bf_auto_stock_'.$id),'Positive-to-positive stock changes do not sweep the whole campaign');$finish($id);
    });

    $suite->test('Seven-day Flash derives 2016 stages; paged preview, bounded scheduler, late pricing and final restore',static function()use($suite,$rest,$repo,$product,$selection,$scheduler,$processor,$activate,$shift,$raw_item,$price,&$campaigns):void{
        global $wpdb;
        $body=array('title'=>'EXIN130 duration fix','days'=>7,'start_local'=>(new DateTimeImmutable('+1 hour',new DateTimeZone('Asia/Tehran')))->format('Y-m-d\TH:i'),'timezone'=>'Asia/Tehran','settings'=>array('discount_plan'=>array('mode'=>'auto_random','interval'=>'flash','interval_minutes'=>5,'steps'=>3,'selection_mode'=>'count','selection_value'=>1,'last_day'=>'remaining_floor')));
        $c=$rest('campaign',$body)['campaign'];$id=$c['id'];$campaigns[]=$id;
        $suite->same(7,$c['days'],'Campaign duration remains seven days');$suite->same(2016,$c['settings']['discount_plan']['steps'],'Server derives stages and ignores submitted stage count');$suite->same(604800,strtotime($c['end_utc'].' UTC')-strtotime($c['start_utc'].' UTC'),'End stays seven days after start');
        $p=$product('duration financial');$p->set_sale_price('80');$p->set_date_on_sale_from(time()-3600);$p->set_date_on_sale_to(time()+900000);$p->save();$before=PriceState::capture(wc_get_product($p->get_id()));$selection->add($id,$p->get_id(),'50');$snapshot=$repo->item($id,$p->get_id())['snapshot'];
        $preview=$rest('campaign/'.$id.'/simulation',array('stage_start'=>2004));$suite->same(2016,$preview['total_stages'],'Preview reports total stages');$suite->same(12,count($preview['stages']),'Only requested starts returned');$suite->same(12,count($preview['samples'][0]['schedule']),'Only twelve prices built per member');$suite->same(null,$preview['next_stage'],'Last preview page terminates');$suite->same(2004,$preview['samples'][0]['details'][0]['stage'],'Preview accepts stages above 366');$suite->same($before,PriceState::capture(wc_get_product($p->get_id())),'Preview never reprices');$suite->same(array(),$repo->item($id,$p->get_id())['schedule'],'No per-product 2016-stage schedule stored');
        $filtered=$rest('campaign/'.$id.'/simulation',array('filters'=>array('scope'=>'selected','ids'=>array($p->get_id())),'floor_policy'=>array('mode'=>'shared','value'=>'50')));$suite->same(12,count($filtered['stages']),'Selection preview also limits boundary allocation');$suite->same(12,count($filtered['samples'][0]['schedule']),'Selection preview limits each sample to twelve prices');
        $v=$activate($id);$shift($id,-1500*300-5);$scheduler->stage($id,$v,time()-1);$scheduler->batch($id,$v);$current=$repo->get($id);$suite->same(1500,DiscountPlan::index($current,time()),'Late scheduler catches up directly above stage 366');$suite->same(DiscountPlan::automatic_price('100','50',$current,1500,$p->get_id()),$price($p),'Current price uses exact floor-bounded arithmetic');
        $jobs=$wpdb->get_col($wpdb->prepare("SELECT args FROM %i WHERE hook='exin_bf_stage' AND status='pending'",$wpdb->prefix.'actionscheduler_actions'));$own=array_values(array_filter(array_map(static fn($args)=>json_decode($args,true),$jobs),static fn($args)=>(int)($args[0]??0)===$id));$suite->same(1,count($own),'Exactly one future stage job, not 2016 jobs');$suite->same(strtotime(DiscountPlan::stage_start($current,1501).' UTC'),$own[0][2],'Next job is the next actual boundary');
        $stages=$wpdb->get_col($wpdb->prepare('SELECT DISTINCT stage FROM %i WHERE campaign_id=%d ORDER BY stage',$wpdb->prefix.'exin_bf_turns',$id));$suite->same(array('1500'),$stages,'Skipped stages create no cohorts or product jobs');$row=$raw_item($id,$p->get_id());$suite->same('unchanged',$processor->apply($id,$p->get_id(),$v),'Duplicate high-stage callback cannot stack');$suite->same($row,$raw_item($id,$p->get_id()),'Replay preserves snapshot and operation');
        $shift($id,-1728*300-5);$scheduler->batch($id,$v);$suite->same(DiscountPlan::automatic_price('100','50',$repo->get($id),1728,$p->get_id(),true,false),$price($p),'Previously served member keeps its ordinary random price, without the remaining-floor override');$suite->same($snapshot,$repo->item($id,$p->get_id())['snapshot'],'Original snapshot survives all stage changes');
        $shift($id,-604805);$scheduler->stage($id,$v,time()-1);$end=$repo->get($id);$scheduler->batch($id,$end['version'],0,'restore');$suite->same($before,PriceState::capture(wc_get_product($p->get_id())),'True campaign end restores original sale and sale dates');
    });
    $suite->test('Long automatic plans enforce capacity and exact last-day policy without building all stages',static function()use($suite,$auto,$repo,$product,$selection,$activate,$processor,$shift,$price,$finish):void{
        global $wpdb;
        $id=$auto('rotation',array('duration_minutes'=>10080,'interval'=>'flash','interval_minutes'=>11,'last_day'=>'remaining_floor'));
        $service=new \Exin\BlackFriday\Application\AutomaticSelection($repo);$service->capacity($repo->get($id),1);$suite->same(918,DiscountPlan::stage_count($repo->get($id)),'Partial final interval plus exact last-day boundary');
        $a=$product('remaining served');$b=$product('remaining unserved');$selection->add($id,$a->get_id(),'50',array('featured'=>true));$selection->add($id,$b->get_id(),'50');$v=$activate($id);$shift($id,-785*660-5);foreach(array($a,$b)as$p){$processor->apply($id,$p->get_id(),$v);}$suite->same('50.00',$price($a),'Served member receives its floor in stage 785');
        $shift($id,-6*DAY_IN_SECONDS);$c=$repo->get($id);$suite->same(786,DiscountPlan::index($c,time()),'Last day inserts exact intermediate stage');$preview=$service->preview($c,1,786);foreach($preview['samples']as$row){if($row['product_id']===$b->get_id()){$suite->same('50.00',$row['schedule'][0],'Previously unserved member previews floor');}}
        foreach(array($a,$b)as$p){$processor->apply($id,$p->get_id(),$v);}$suite->same('50.00',$price($b),'Remaining member gets its floor at exact final-day start');$suite->same(false,$service->remaining($c,$a->get_id(),787),'Remaining set excludes earlier applied special');$suite->same(true,$service->remaining($c,$b->get_id(),787),'Remaining set stays frozen despite a last-day special');$finish($id);
        $large=$auto('rotation',array('duration_minutes'=>10080,'interval'=>'flash','interval_minutes'=>5,'selection_value'=>125));$service->capacity($repo->get($large),1);$hit=false;try{$service->capacity($repo->get($large),125);}catch(DomainException$e){$hit=$e->getMessage()==='automatic_cohort_capacity_exceeded';}$suite->same(true,$hit,'Special entries plus stage markers exceed the ledger storage budget');$hit=false;try{$service->capacity($repo->get($large),1000);}catch(DomainException$e){$hit=$e->getMessage()==='automatic_flash_capacity_exceeded';}$suite->same(true,$hit,'Five-minute processing budget still rejects oversized membership');
        $column=$wpdb->get_row($wpdb->prepare('SHOW COLUMNS FROM %i LIKE %s',$wpdb->prefix.'exin_bf_turns','stage'),ARRAY_A);$suite->same(true,(bool)preg_match('/^int(?:\(\d+\))? unsigned$/',$column['Type']),'Stage ledger widens beyond unsigned-smallint capacity');$wpdb->insert($wpdb->prefix.'exin_bf_turns',array('campaign_id'=>$large,'version'=>1,'stage'=>100000,'product_id'=>0));$suite->same('100000',$wpdb->get_var($wpdb->prepare('SELECT stage FROM %i WHERE campaign_id=%d',$wpdb->prefix.'exin_bf_turns',$large)),'High automatic stage persists without truncation');
    });
    $suite->test('Schema 4 upgrade preserves an active manual plan and its prices byte-for-byte; automatic seed is saved only on explicit opt-in',static function()use($suite,$campaign,$product,$selection,$activate,$processor,$repo,$rest,$raw_item,$price,$finish,&$campaigns):void{
        $id=$campaign(array('mode'=>'custom','points'=>array(array('offset_minutes'=>0,'kind'=>'percent','value'=>'5'),array('offset_minutes'=>60,'kind'=>'percent','value'=>'10')),'duration_minutes'=>120));$p=$product('manual migration');$selection->add($id,$p->get_id(),'70');$v=$activate($id);$processor->apply($id,$p->get_id(),$v);$before=$repo->get($id);$row=$raw_item($id,$p->get_id());$saved=$price($p);
        update_option('exin_bf_schema_version','4',false);\Exin\BlackFriday\Infrastructure\Migration::run();$suite->same(\Exin\BlackFriday\Infrastructure\Migration::VERSION,get_option('exin_bf_schema_version'),'Only additive stage-ledger schema');$suite->same($before,$repo->get($id),'Manual campaign settings, version and dates byte-for-byte unchanged');$suite->same($row,$raw_item($id,$p->get_id()),'Manual financial ownership untouched');$suite->same($saved,$price($p),'Migration never reprices');$suite->same(false,DiscountPlan::automatic($repo->get($id)),'No implicit automatic opt-in');
        $body=array('title'=>'EXIN130 explicit auto','start_local'=>(new DateTimeImmutable('+1 hour',new DateTimeZone('Asia/Tehran')))->format('Y-m-d\TH:i'),'timezone'=>'Asia/Tehran','settings'=>array('discount_plan'=>array('mode'=>'auto_random','interval'=>'flash','interval_minutes'=>'۵','steps'=>'۳','intensity_min'=>'۱۰','intensity_max'=>'۹۰')));$created=$rest('campaign',$body)['campaign'];$campaigns[]=$created['id'];$seed=$created['settings']['discount_plan']['seed'];$suite->same(true,strlen($seed)>=8,'Stored seed generated on explicit automatic selection');$body['id']=$created['id'];$again=$rest('campaign',$body)['campaign'];$suite->same($seed,$again['settings']['discount_plan']['seed'],'Repeated settings save does not regenerate the seed');$suite->same(5,$again['settings']['discount_plan']['interval_minutes'],'Persian Flash minutes normalize safely');$ready_product=$product('ready automatic');$selection->add($created['id'],$ready_product->get_id(),'70');$scheduled=$rest('campaign/'.$created['id'].'/action',array('action'=>'schedule','confirm_activate'=>true));$suite->same('scheduled',$repo->get($created['id'])['status'],'Real activation readiness accepts the automatic on-demand plan');$suite->same('100.00',$price($ready_product),'Scheduling a future automatic campaign never reprices early');
        $draft_id=$campaign();$draft_product=$product('draft automatic switch');$selection->add($draft_id,$draft_product->get_id(),'70');$draft_row=$raw_item($draft_id,$draft_product->get_id());$body['id']=$draft_id;$switched=$rest('campaign',$body)['campaign'];$suite->same($draft_row,$raw_item($draft_id,$draft_product->get_id()),'Future automatic plan change leaves floor and snapshot untouched');$suite->same(true,DiscountPlan::automatic($switched),'Only explicit draft selection opts into the new engine');$suite->same('100.00',$price($draft_product),'Plan edit alone does not price a product');$finish($id);
    });
} finally {
	foreach ( $campaigns as $id ) {
		$record = $repo->get( $id );
		if ( $record && 'archived' !== $record['status'] ) { $repo->save( $id, array( 'status' => $repo->has_ownership( $id ) ? 'paused' : 'completed' ) ); }
	}
	update_option( 'woocommerce_currency', $old_currency ); update_option( 'woocommerce_price_num_decimals', $old_precision ); wp_set_current_user( $old_user );
}
$failed = count( array_filter( $suite->results, static fn( $item ) => 'failed' === $item['status'] ) );
echo 'EXIN_V130_RESULT ' . wp_json_encode( array( 'suite' => 'v130-financial-integration', 'wordpress' => get_bloginfo( 'version' ), 'woocommerce' => WC_VERSION, 'php' => PHP_VERSION, 'database' => $GLOBALS['wpdb']->db_version(), 'hpos' => \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled(), 'tests' => count( $suite->results ), 'assertions' => $suite->assertions, 'passed' => count( $suite->results ) - $failed, 'failed' => $failed, 'results' => $suite->results ) ) . PHP_EOL;
if ( $failed ) { throw new RuntimeException( 'v130_financial_integration_failed' ); }
