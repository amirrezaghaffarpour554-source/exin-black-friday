<?php
/** Focused v1.2.0 financial integration checks; synthetic local WordPress only. */

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
	throw new RuntimeException( 'Real WooCommerce and the v1.2.0 source must be active.' );
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
	$item->set_name( 'EXIN120 ' . $name );
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
		'title' => 'EXIN120 ' . wp_generate_uuid4(), 'status' => 'draft', 'start_utc' => gmdate( 'Y-m-d H:i:s', time() + $offset ),
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
    $suite->test('Schema 3 upgrade and operational settings preserve a live campaign, prices, snapshots and version', static function () use ($suite,$campaign,$product,$selection,$activate,$processor,$repo,$raw_item,$price,$rest): void {
        $id=$campaign();$p=$product('upgrade');$selection->add($id,$p->get_id(),'70');$v=$activate($id);$processor->apply($id,$p->get_id(),$v);
        $before=$repo->get($id);$row=$raw_item($id,$p->get_id());$actual=$price($p);
        update_option('exin_bf_schema_version','3',false);\Exin\BlackFriday\Infrastructure\Migration::run();
        $suite->same('4',get_option('exin_bf_schema_version'),'Additive schema marker');$suite->same($before,$repo->get($id),'No campaign rerun during upgrade');$suite->same($row,$raw_item($id,$p->get_id()),'Durable price state untouched');$suite->same($actual,$price($p),'No Woo price write');
        $rest('campaign/'.$id.'/presentation',array('badge_text'=>'روز دختر','badge_color'=>'#123456','coupon_floor_policy'=>'protect'));
        $suite->same($v,$repo->get($id)['version'],'Operational controls do not invalidate scheduled financial work');$suite->same($row,$raw_item($id,$p->get_id()),'Operational save leaves financial row untouched');
        $processor->restore($id,$p->get_id());
    });
    $suite->test('Real mixed-cart coupons respect opt-in floors, stage refresh is visible and saved orders remain unchanged', static function () use ($suite,$campaign,$product,$selection,$activate,$processor,$repo,$rest,$shift): void {
        wc_load_cart();WC()->cart->empty_cart();update_option('woocommerce_calc_taxes','no');
        $id=$campaign(array('mode'=>'stepped','initial_discount'=>'10','final_discount'=>'30'));$p=$product('cart protected');$plain=$product('plain');
        $selection->add($id,$p->get_id(),'70');$v=$activate($id);$processor->apply($id,$p->get_id(),$v);
        $rest('campaign/'.$id.'/presentation',array('coupon_floor_policy'=>'protect','cashback_message_enabled'=>true,'cashback_message'=>'۴٪ کش‌بک مستقل خرید نقدی'));
        $key=WC()->cart->add_to_cart($p->get_id(),2);WC()->cart->add_to_cart($plain->get_id(),1);WC()->cart->calculate_totals();
        $order=wc_create_order();$order->add_product(wc_get_product($p->get_id()),2);$order->calculate_totals();$saved=$order->get_total();
        foreach(array('percent'=>'50','fixed_cart'=>'150') as $type=>$amount){
            $c=new WC_Coupon();$c->set_code('exin120-'.wp_generate_uuid4());$c->set_discount_type($type);$c->set_amount($amount);$c->save();
            WC()->cart->apply_coupon($c->get_code());WC()->cart->calculate_totals();
            $suite->same('180.00',wc_format_decimal(WC()->cart->get_cart()[$key]['line_total'],2),'Campaign lines excluded from '.$type.' distribution');
            $suite->same(true,WC()->cart->get_discount_total()>0,'Ordinary product still receives coupon');WC()->cart->remove_coupons();$c->delete(true);
        }
        $shift($id,-2*HOUR_IN_SECONDS-5);$processor->apply($id,$p->get_id(),$v);WC()->cart->calculate_totals();
        $cart=new \Exin\BlackFriday\Integration\Cart($repo);$data=$cart->data();
        $suite->same('140.00',wc_format_decimal(WC()->cart->get_cart()[$key]['line_total'],2),'Cart uses current stage');$suite->same(true,str_contains($data['price_change_message'],'cart protected'),'Buyer sees product price change');$suite->same('60.00',$data['savings'],'Savings use actual lines');$suite->same('۴٪ کش‌بک مستقل خرید نقدی',$data['cashback_message'],'Cashback is promotional text only');
        WC()->cart->cart_contents[$key]['line_total']='120.00';$errors=new WP_Error();$cart->blocks_errors($errors,WC()->cart);$suite->same(true,$errors->has_errors(),'Blocks blocks a third-party below-floor total');$errors=new WP_Error();$cart->checkout_errors(array(),$errors);$suite->same(true,$errors->has_errors(),'Classic checkout uses same floor validation');
        $suite->same($saved,wc_get_order($order->get_id())->get_total(),'Stage changes never rewrite a saved order');
        WC()->cart->empty_cart();$processor->restore($id,$p->get_id());
    });
    $suite->test('Bulk floors preserve independent exceptions and classify unpublished products as rejected', static function () use ($suite,$campaign,$product,$selection,$bulk,$repo,$activate,$processor,$price): void {
        $id=$campaign();$a=$product('exception');$b=$product('group');
        $selection->add($id,$a->get_id(),'85');$selection->add($id,$b->get_id(),'0',array('floor_policy'=>array('mode'=>'percent','value'=>'20')));
        $run=$bulk->start($id,array('scope'=>'selected','ids'=>array($a->get_id(),$b->get_id())),array('mode'=>'percent','value'=>'30'));
        do{$run=$bulk->step($id,$run['token']);}while(!$run['complete']);
        $suite->same('85.00',$repo->item($id,$a->get_id())['floor_price'],'Independent exception survives');$suite->same('70.00',$repo->item($id,$b->get_id())['floor_price'],'Group floor updates');$suite->same(1,$run['skipped'],'Unchanged counter');$suite->same(1,$run['updated'],'Updated counter');
        $p=$product('draft reject');$p->set_status('draft');$p->save();
        $run=$bulk->start($id,array('scope'=>'selected','ids'=>array($p->get_id()),'publication'=>'draft'),array('mode'=>'none'));do{$run=$bulk->step($id,$run['token']);}while(!$run['complete']);
        $parent=new WC_Product_Variable();$parent->set_name('EXIN120 variable');$parent->set_status('publish');$parent->save();
        $variation=new WC_Product_Variation();$variation->set_parent_id($parent->get_id());$variation->set_status('publish');$variation->set_regular_price('100');$variation->set_manage_stock(true);$variation->set_stock_quantity(5);$variation->save();
        $selection->add($id,$variation->get_id(),'98');$v=$activate($id);$processor->apply($id,$variation->get_id(),$v);
        $suite->same('98.00',$price($variation),'Variation floor limits an otherwise five-percent discount');$suite->same('unchanged',$processor->apply($id,$variation->get_id(),$v),'Variation duplicate is idempotent');
        $processor->restore($id,$variation->get_id());$suite->same('100.00',$price($variation),'Variation restores original amount');
        $suite->same(1,$run['rejected'],'Unpublished item safely rejected');$suite->same(0,$run['failed'],'Rejection is distinct from technical errors');$suite->same(null,$repo->item($id,$p->get_id()),'No unpublished price enrollment');
    });
    $suite->test('New products default to exclude, manual approval is idempotent and auto mode queues current-stage recovery', static function () use ($suite,$campaign,$product,$selection,$activate,$scheduler,$repo,$rest,$price): void {
        $id=$campaign();$seed=$product('seed');$selection->add($id,$seed->get_id(),'0');$v=$activate($id);$scheduler->batch($id,$v);
        $service=new \Exin\BlackFriday\Integration\NewProducts($repo);$p=$product('new arrival');$service->process($p->get_id());$suite->same(null,$repo->item($id,$p->get_id()),'No enrollment by default');
        $rules=array('filters'=>array('scope'=>'instock'),'policy'=>array('mode'=>'percent','value'=>'25'));
        $rest('campaign/'.$id.'/presentation',array('new_products'=>'manual','new_product_rules'=>$rules));$service->process($p->get_id());
        $suite->same($p->get_id(),$service->pending($id)['products'][0]['id'],'Manual review is visible');$suite->same(null,$repo->item($id,$p->get_id()),'Pending approval never changes price');
        $r=$service->approve($id,array($p->get_id()),true);$suite->same(true,$r['results'][0]['ok'],'Explicit approval enrolls');$suite->same(array(),$service->approve($id,array($p->get_id()),true)['results'],'Repeated approval cannot duplicate work');
        $scheduler->retry($id,$p->get_id(),$v,'apply',0);$suite->same('95.00',$price($p),'Existing retry processor applies current stage');
        $rest('campaign/'.$id.'/presentation',array('new_products'=>'auto'));$auto=$product('automatic');$service->process($auto->get_id());$service->process($auto->get_id());
        $suite->same('75.00',$repo->item($id,$auto->get_id())['floor_price'],'Auto mode uses saved group rules');$suite->same('100.00',$price($auto),'Enrollment itself does not synchronously change prices');
        $scheduler->retry($id,$auto->get_id(),$v,'apply',0);$suite->same('95.00',$price($auto),'Durable enrollment is processed without moving old cursor');
        $repo->save($id,array('status'=>'paused'));
    });
    $suite->test('Notification providers are opt-in and duplicate-safe; actual price audit is bounded and private', static function () use ($suite,$campaign,$product,$selection,$activate,$processor,$repo,$rest): void {
        $id=$campaign();$p=$product('audit');$selection->add($id,$p->get_id(),'70');$v=$activate($id);$processor->apply($id,$p->get_id(),$v);
        $n=new \Exin\BlackFriday\Integration\Notifications($repo);$events=array();$callback=static function($event,$payload)use(&$events){$events[]=$event;};add_action('exin_bf_notification',$callback,10,2);
        $n->stage($id,$v,0);$suite->same(array(),$events,'Notifications disabled by default');$rest('campaign/'.$id.'/presentation',array('notifications'=>array('enabled'=>true,'start'=>true,'stage'=>true,'error'=>true)));
        $n->stage($id,$v,0);$suite->same(array(),$events,'No provider readiness means no dispatch');add_filter('exin_bf_notification_provider_ready','__return_true');
        $n->stage($id,$v,0);$n->stage($id,$v,0);$n->stage($id,$v,1);$n->stage($id,$v,1);$suite->same(array('start','stage'),$events,'One delivery per event');update_option('exin_bf_notice_'.$id.'_start_'.$v.'_0',time()-100*DAY_IN_SECONDS,false);(new Logger())->prune(7);$n->stage($id,$v,0);$suite->same(array('start','stage'),$events,'Log retention cannot repeat a still-live campaign event');remove_action('exin_bf_notification',$callback,10);remove_filter('exin_bf_notification_provider_ready','__return_true');
        $log=new Logger();$history=$log->history($id);$prices=array_values(array_filter($history['rows'],static fn($r)=>$r['action']==='price_apply'));
        $suite->same('100.00',$prices[0]['before_price'],'Audit captures actual before price');$suite->same('95.00',$prices[0]['after_price'],'Audit captures actual after price');$suite->same('USD',$prices[0]['currency'],'Audit records currency');
        for($i=0;$i<26;$i++){$log->log('info','manager_smoke',array('campaign_id'=>$id));}$suite->same(25,count($log->history($id)['rows']),'One bounded audit page');$suite->same(true,$log->history($id)['has_more'],'History continuation');$processor->restore($id,$p->get_id());
    });
    $suite->test('One storefront smoke: bounded union, deduplication, optional store products, filters, badges and stage countdown', static function () use ($suite,$campaign,$product,$selection,$activate,$processor,$repo,$rest): void {
        $id=$campaign();$id2=$campaign();$a=$product('display A');$b=$product('display B');$other=$product('ordinary related');$hidden=$product('hidden');$hidden->set_catalog_visibility('hidden');$hidden->save();
        foreach(array($a,$b,$hidden) as $p){$selection->add($id,$p->get_id(),'70');}$selection->add($id2,$a->get_id(),'75');$v=$activate($id);$activate($id2);foreach(array($a,$b,$hidden)as$p){$processor->apply($id,$p->get_id(),$v);}
        $rest('campaign/'.$id.'/presentation',array('public_scope'=>'all','badge_text'=>'روز دختر','badge_position'=>'top-right','countdown_target'=>'stage'));
        $query=new \Exin\BlackFriday\Integration\PublicProductQuery($repo);$first=$query->query($repo->get($id),array('campaigns'=>'all','product_ids'=>array($a->get_id(),$b->get_id(),$hidden->get_id()),'limit'=>1,'page'=>1));$second=$query->query($repo->get($id),array('campaigns'=>'all','product_ids'=>array($a->get_id(),$b->get_id(),$hidden->get_id()),'limit'=>1,'page'=>2));
        $suite->same(2,$first['total'],'Union excludes hidden products and duplicates');$suite->same(2,$first['pages'],'SQL page count');$suite->same(true,$first['products'][0]->get_id()!==$second['products'][0]->get_id(),'Pages have distinct products');
        $filtered=$query->query($repo->get($id),array('product_ids'=>array($a->get_id(),$b->get_id()),'min_discount'=>'10','limit'=>12));$suite->same(0,$filtered['total'],'Discount filter');
        $extra=$query->query($repo->get($id),array('include_store'=>true,'product_ids'=>array($other->get_id()),'limit'=>12));$suite->same($other->get_id(),$extra['products'][0]->get_id(),'Other store products allowed explicitly');
        $a=wc_get_product($a->get_id());$a->set_upsell_ids(array($other->get_id()));$a->save();
        $before=get_page_by_path('black-friday');$before=$before?get_post($before->ID)->post_content:null;
        $html=do_shortcode('[exin_bf_products campaign_id="'.$id.'" limit="1" campaigns="single" filters="yes"][exin_bf_countdown campaign_id="'.$id.'" target="stage"][exin_bf_related campaign_id="'.$id.'" product_id="'.$a->get_id().'"]');
        $suite->same(true,str_contains($html,'روز دختر'),'Configured campaign label appears');$suite->same(true,str_contains($html,'data-target="stage"'),'Stage countdown renders');$suite->same(true,str_contains($html,'exin-bf-catalog-filters'),'Filters render inside shortcode');$suite->same(true,str_contains($html,'ordinary related'),'Standard Woo upsell relation renders');
        $suite->same(false,str_contains($html,'floor_price'),'No confidential floors in page output');$after=get_page_by_path('black-friday');$suite->same($before,$after?get_post($after->ID)->post_content:null,'Existing Elementor page untouched');
        $req=new WP_REST_Request('GET','/exin-bf/v1/time');$req->set_param('campaign_id',$id);$response=rest_do_request($req);$suite->same(true,isset($response->get_data()['stage_ends_at']),'Public endpoint exposes stage boundary only');
        foreach(array($id,$id2)as$cid){$repo->save($cid,array('status'=>'completed'));}$suite->same('',do_shortcode('[exin_bf_badge campaign_id="'.$id.'" product_id="'.$a->get_id().'"]'),'Expired/inactive badge is absent');
    });
} finally {
	foreach ( $campaigns as $id ) {
		$record = $repo->get( $id );
		if ( $record && 'archived' !== $record['status'] ) { $repo->save( $id, array( 'status' => $repo->has_ownership( $id ) ? 'paused' : 'completed' ) ); }
	}
	update_option( 'woocommerce_currency', $old_currency ); update_option( 'woocommerce_price_num_decimals', $old_precision ); wp_set_current_user( $old_user );
}
$failed = count( array_filter( $suite->results, static fn( $item ) => 'failed' === $item['status'] ) );
echo 'EXIN_V120_RESULT ' . wp_json_encode( array( 'suite' => 'v120-financial-integration', 'wordpress' => get_bloginfo( 'version' ), 'woocommerce' => WC_VERSION, 'php' => PHP_VERSION, 'database' => $GLOBALS['wpdb']->db_version(), 'hpos' => \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled(), 'tests' => count( $suite->results ), 'assertions' => $suite->assertions, 'passed' => count( $suite->results ) - $failed, 'failed' => $failed, 'results' => $suite->results ) ) . PHP_EOL;
if ( $failed ) { throw new RuntimeException( 'v120_financial_integration_failed' ); }
