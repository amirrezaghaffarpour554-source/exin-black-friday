<?php
/** Focused v1.2.2 financial integration checks; synthetic local WordPress only. */

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
	throw new RuntimeException( 'Real WooCommerce and the v1.2.2 source must be active.' );
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
	$item->set_name( 'EXIN122 ' . $name );
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
		'title' => 'EXIN122 ' . wp_generate_uuid4(), 'status' => 'draft', 'start_utc' => gmdate( 'Y-m-d H:i:s', time() + $offset ),
		'timezone' => 'Asia/Tehran', 'days' => $plan['steps'], 'strategy' => 'linear', 'power' => 2, 'rounding' => 'half_up',
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

try {
    $suite->test('Live additions are bounded, honor current-stage and exception floors, and preserve every prior row and campaign plan', static function()use($suite,$campaign,$product,$selection,$activate,$scheduler,$repo,$rest,$raw_item,$price):void{
        $new=array();for($i=0;$i<11;$i++){$new[]=$product('new '.$i);}
        $parent=new WC_Product_Variable();$parent->set_name('EXIN122 variable');$parent->set_status('publish');$parent->save();
        $variation=new WC_Product_Variation();$variation->set_parent_id($parent->get_id());$variation->set_status('publish');$variation->set_regular_price('100');$variation->set_manage_stock(true);$variation->set_stock_quantity(5);$variation->save();$new[]=$variation;
        $id=$campaign(array('mode'=>'stepped','initial_discount'=>'10','final_discount'=>'30'),-2*HOUR_IN_SECONDS-5);$old=$product('existing seed');$selection->add($id,$old->get_id(),'90');$v=$activate($id);$scheduler->batch($id,$v);
        $before=$repo->get($id);$row=$raw_item($id,$old->get_id());$old_price=$price($old);$ids=array_merge(array($old->get_id()),array_map(static fn($p)=>$p->get_id(),$new));
        $policy=array('mode'=>'shared','value'=>'70','floors'=>array($new[0]->get_id()=>'85'));
        $preview=$rest('campaign/'.$id.'/simulation',array('filters'=>array('scope'=>'selected','ids'=>$ids),'floor_policy'=>$policy));$suite->same($before,$repo->get($id),'Preview does not save live settings');$suite->same($row,$raw_item($id,$old->get_id()),'Preview leaves old row byte-for-byte');
        $job=$rest('campaign/'.$id.'/selection-jobs',array('filters'=>array('scope'=>'selected','ids'=>$ids),'floor_policy'=>$policy))['job'];
        $job=$rest('campaign/'.$id.'/selection-jobs/'.$job['token'])['job'];$suite->same(10,$job['processed'],'At most ten live candidates per request');$suite->same(false,(bool)$job['complete'],'Second batch remains resumable');
        do{$job=$rest('campaign/'.$id.'/selection-jobs/'.$job['token'])['job'];}while(!$job['complete']);
        $suite->same(array(12,0,1,0,0),array($job['added'],$job['updated'],$job['skipped'],$job['rejected'],$job['failed']),'New rows only; existing row is skipped');
        foreach($new as$i=>$p){$suite->same($i===0?'85.00':'70.00',$price($p),'Uses stage three immediately and respects the floor');$suite->same('100.00',$repo->item($id,$p->get_id())['snapshot']['regular'],'Immutable original snapshot');}
        $suite->same(1,$repo->item($id,$new[0]->get_id())['floor_override'],'Per-product exception persists');$suite->same($row,$raw_item($id,$old->get_id()),'Existing floor, snapshot, intent and expected fingerprint unchanged');$suite->same($old_price,$price($old),'Existing product price is unchanged');
        $after=$repo->get($id);foreach(array('version','start_utc','end_utc','days','strategy','power','rounding','status')as$key){$suite->same($before[$key],$after[$key],'Preserves campaign '.$key);}
        unset($before['settings']['last_run'],$after['settings']['last_run']);$suite->same($before['settings'],$after['settings'],'All existing settings and selection rules are unchanged');
        $suite->same($job,$rest('campaign/'.$id.'/selection-jobs/'.$job['token'])['job'],'A completed batch does not replay prices or counters');
    });
    $suite->test('Missing zero oversized and competing floors are rejected before live membership or price writes',static function()use($suite,$campaign,$product,$selection,$activate,$processor,$repo,$rest,$price):void{
        $id=$campaign();$activate($id);$items=array();foreach(array('missing','zero','over','owned','rival','stock','valid')as$name){$items[$name]=$product($name);}
        $items['stock']->set_stock_quantity(0);$items['stock']->save();
        $other=$campaign(array(),-5,99);$selection->add($other,$items['owned']->get_id(),'70');$selection->add($other,$items['rival']->get_id(),'70');$v=$activate($other);$processor->apply($other,$items['owned']->get_id(),$v);$owned_price=$price($items['owned']);
        $floors=array();foreach($items as$name=>$p){if($name!=='missing'){$floors[$p->get_id()]=$name==='zero'?'0':($name==='over'?'200':'75');}}
        $job=$rest('campaign/'.$id.'/selection-jobs',array('filters'=>array('scope'=>'selected','ids'=>array_map(static fn($p)=>$p->get_id(),array_values($items))),'floor_policy'=>array('mode'=>'independent','floors'=>$floors)))['job'];
        $job=$rest('campaign/'.$id.'/selection-jobs/'.$job['token'])['job'];$suite->same(1,$job['added'],'Only the valid new product is added');$suite->same(6,$job['rejected'],'Missing, zero, over-base, owner, rival and unavailable stock are rejected');
        foreach($items as$name=>$p){if($name!=='valid'){$suite->same(null,$repo->item($id,$p->get_id()),'Rejected '.$name.' has no membership');}}
        $suite->same($owned_price,$price($items['owned']),'Other financial owner is untouched');$suite->same('100.00',$price($items['rival']),'Other pending live winner is untouched');$suite->same('100.00',$price($items['stock']),'Unavailable product cannot halt a live batch or receive a discount');$suite->same('95.00',$price($items['valid']),'Valid item uses the existing first stage');
        $job=$rest('campaign/'.$id.'/selection-jobs',array('filters'=>array('scope'=>'selected','ids'=>array($items['missing']->get_id())),'floor_policy'=>array('mode'=>'none')))['job'];$job=$rest('campaign/'.$id.'/selection-jobs/'.$job['token'])['job'];$suite->same(1,$job['rejected'],'An omitted floor policy cannot enroll a live product');
        $rest('campaign/'.$id.'/selection-jobs',array('filters'=>array('scope'=>'selected','ids'=>array($items['missing']->get_id())),'floor_policy'=>array('mode'=>'shared','value'=>'-1')),400);
        $expired=$product('expired batch');$job=$rest('campaign/'.$id.'/selection-jobs',array('filters'=>array('scope'=>'selected','ids'=>array($expired->get_id())),'floor_policy'=>array('mode'=>'shared','value'=>'70')))['job'];$repo->save($id,array('end_utc'=>gmdate('Y-m-d H:i:s',time()-1)));$rest('campaign/'.$id.'/selection-jobs/'.$job['token'],array(),400);$suite->same(null,$repo->item($id,$expired->get_id()),'Expiry between job creation and step is rechecked');
    });
    $suite->test('Single-item pause resume and grouped removal restore owned prices and preserve external edits',static function()use($suite,$campaign,$product,$selection,$activate,$processor,$repo,$rest,$price):void{
        $id=$campaign();$a=$product('pause');$b=$product('external');$control=$product('control');foreach(array($a,$b,$control)as$p){$selection->add($id,$p->get_id(),'70');}$v=$activate($id);foreach(array($a,$b,$control)as$p){$processor->apply($id,$p->get_id(),$v);}
        $before=$repo->get($id);$snapshot=$repo->item($id,$a->get_id())['snapshot'];
        $rest('campaign/'.$id.'/item/'.$a->get_id(),array('action'=>'pause'));$suite->same('100.00',$price($a),'Pause actually stops the discount and restores original price');$suite->same('paused',$repo->item($id,$a->get_id())['status'],'Only this membership is paused');
        $rest('campaign/'.$id.'/item/'.$a->get_id(),array('action'=>'resume'));$suite->same('95.00',$price($a),'Resume uses current campaign stage');$suite->same($snapshot,$repo->item($id,$a->get_id())['snapshot'],'Resume does not recapture the discounted price');
        $external=wc_get_product($b->get_id());$external->set_sale_price('87');$external->set_price('87');$external->save();
        $rest('campaign/'.$id.'/selection-jobs',array('action'=>'remove','filters'=>array('scope'=>'selected','ids'=>array($a->get_id()))),400);
        $job=$rest('campaign/'.$id.'/selection-jobs',array('action'=>'remove','confirm_remove'=>true,'filters'=>array('scope'=>'selected','ids'=>array($a->get_id(),$b->get_id()))))['job'];$job=$rest('campaign/'.$id.'/selection-jobs/'.$job['token'])['job'];
        $suite->same(1,$job['removed'],'Owned line is safely removed');$suite->same(1,$job['rejected'],'Externally changed line is held for resolution');$suite->same('100.00',$price($a),'Removal restores its original amount');$suite->same('removed',$repo->item($id,$a->get_id())['status'],'Removed line will not be repriced by a duplicate callback');$suite->same('87.00',$price($b),'External price is never overwritten');$suite->same('95.00',$price($control),'Other campaign products remain discounted');$suite->same('active',$repo->get($id)['status'],'Whole campaign remains active');$suite->same($before['version'],$repo->get($id)['version'],'No version bump or campaign restart');
        $processor->apply($id,$a->get_id(),$v);$suite->same('100.00',$price($a),'Delayed duplicate job cannot reapply a removed discount');
    });
    $suite->test('Interrupted live enrollment resumes the same reserved product without recapturing or duplicating membership',static function()use($suite,$campaign,$product,$activate,$bulk,$repo,$price):void{
        $id=$campaign();$activate($id);$p=$product('interruption');$job=$bulk->start($id,array('scope'=>'selected','ids'=>array($p->get_id())),array('mode'=>'shared','value'=>'80'));
        $hit=false;$interrupt=static function(string$sql)use(&$hit):string{if(!$hit&&str_contains($sql,'exin_bf_audit')&&str_contains($sql,"'product_included'")){$hit=true;throw new RuntimeException('injected_request_interruption');}return$sql;};add_filter('query',$interrupt);
        try{$failed=$bulk->step($id,$job['token']);}finally{remove_filter('query',$interrupt);}
        $suite->same(true,$hit,'Interrupted after membership write, before price application');$suite->same('failed',$failed['status'],'The batch stops visibly instead of spinning');$suite->same(false,(bool)$failed['complete'],'Same token remains resumable');$suite->same(0,$failed['processed'],'Unfinished reservation does not advance the cursor');
        $snapshot=$repo->item($id,$p->get_id())['snapshot'];$suite->same('100.00',$price($p),'Interrupted enrollment does not guess a price');
        $done=$bulk->step($id,$job['token']);$suite->same(true,(bool)$done['complete'],'Resume completes the reserved product');$suite->same(1,$done['added'],'One membership is counted');$suite->same(1,$done['processed'],'Product is counted once');$suite->same('95.00',$price($p),'Resume applies the real current stage');$suite->same($snapshot,$repo->item($id,$p->get_id())['snapshot'],'Original snapshot survives the interrupted request');$suite->same($done,$bulk->step($id,$job['token']),'Repeated completion is idempotent');
    });
} finally {
	foreach ( $campaigns as $id ) {
		$record = $repo->get( $id );
		if ( $record && 'archived' !== $record['status'] ) { $repo->save( $id, array( 'status' => $repo->has_ownership( $id ) ? 'paused' : 'completed' ) ); }
	}
	update_option( 'woocommerce_currency', $old_currency ); update_option( 'woocommerce_price_num_decimals', $old_precision ); wp_set_current_user( $old_user );
}
$failed = count( array_filter( $suite->results, static fn( $item ) => 'failed' === $item['status'] ) );
echo 'EXIN_V122_RESULT ' . wp_json_encode( array( 'suite' => 'v122-financial-integration', 'wordpress' => get_bloginfo( 'version' ), 'woocommerce' => WC_VERSION, 'php' => PHP_VERSION, 'database' => $GLOBALS['wpdb']->db_version(), 'hpos' => \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled(), 'tests' => count( $suite->results ), 'assertions' => $suite->assertions, 'passed' => count( $suite->results ) - $failed, 'failed' => $failed, 'results' => $suite->results ) ) . PHP_EOL;
if ( $failed ) { throw new RuntimeException( 'v122_financial_integration_failed' ); }
