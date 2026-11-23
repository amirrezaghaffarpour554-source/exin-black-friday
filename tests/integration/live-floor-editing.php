<?php
/** Focused real Woo/MariaDB checks. Never run against a store database. */
use Exin\BlackFriday\Application\ProductSelectionService;
use Exin\BlackFriday\Application\PriceProcessor;
use Exin\BlackFriday\Application\PriceState;
use Exin\BlackFriday\Domain\DiscountPlan;
use Exin\BlackFriday\Infrastructure\CampaignRepository;
use Exin\BlackFriday\Infrastructure\Logger;
use Exin\BlackFriday\Integration\Cart;

if (!defined('WP_CLI') || !WP_CLI || DB_NAME !== 'exin_test' || wp_get_environment_type() !== 'local') throw new RuntimeException('Isolated local DB required');
$start=microtime(true); $checks=0;
$assert=static function($ok,$why)use(&$checks){++$checks;if(!$ok)throw new RuntimeException($why);};
$r=new CampaignRepository();$s=new ProductSelectionService($r);global $wpdb;
$preserved=static function()use($wpdb){return $wpdb->get_results('SELECT * FROM '.$wpdb->prefix.'exin_bf_campaigns WHERE id IN (2,4)',ARRAY_A);};
$legacy=$preserved();
$plan=DiscountPlan::normalize(['mode'=>'custom','interval'=>'custom','points'=>[['offset_minutes'=>0,'kind'=>'percent','value'=>'10'],['offset_minutes'=>60,'kind'=>'percent','value'=>'80']],'duration_minutes'=>180],2);
$c=['title'=>'Isolated live floor editing','start_utc'=>gmdate('Y-m-d H:i:s',time()+3600),'end_utc'=>gmdate('Y-m-d H:i:s',time()+14400),'timezone'=>'UTC','days'=>2,'strategy'=>'linear','power'=>2,'rounding'=>'half_up','settings'=>['discount_plan'=>$plan,'existing_sale_policy'=>'preserve','shipping_threshold'=>'37.99','free_shipping'=>false]];
$id=$r->create($c);
$make=static function($name){$p=new WC_Product_Simple();$p->set_name($name);$p->set_status('publish');$p->set_regular_price('100');$p->set_stock_status('instock');$p->save();return $p->get_id();};
$pid=$make('Live floor target');$s->add($id,$pid,'0');$untouched=$make('Live floor ordinary');$s->add($id,$untouched,'0');
// 1,738 selected products, exactly 344 pre-existing positive floors; real SQL pagination.
$template=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.$wpdb->prefix.'exin_bf_items WHERE campaign_id=%d AND product_id=%d',$id,$pid),ARRAY_A);
$wpdb->query('START TRANSACTION');
for($n=2;$n<1738;$n++){
 $wpdb->insert($wpdb->posts,['post_title'=>'Live floor fixture '.$n,'post_type'=>'product','post_status'=>'publish']);$other=(int)$wpdb->insert_id;
 $row=$template;$row['product_id']=$other;$row['floor_price']=$n<346?'70.00':'0';unset($row['id']);$wpdb->insert($wpdb->prefix.'exin_bf_items',$row);
}
$wpdb->query('COMMIT');
$others=static function()use($wpdb,$id,$pid){return $wpdb->get_results($wpdb->prepare('SELECT * FROM '.$wpdb->prefix.'exin_bf_items WHERE campaign_id=%d AND product_id<>%d ORDER BY product_id',$id,$pid),ARRAY_A);};$beforeOthers=$others();
$assert($r->count_items($id)===1738,'1738 selected fixture');
$assert($r->manager_items($id,1,'','all','','set')['count']===344,'344 positive floors');
$assert($r->manager_items($id,2,'','all','','missing')['count']===1394,'Missing floor filter full count');
$assert(count($r->manager_items($id,2,'','all','','missing')['items'])===25,'Bounded filtered pagination');
$r->save($id,['status'=>'scheduled']);$before=$r->get($id);$item=$r->item($id,$pid);
$assert($s->update_floor($id,$pid,'60.00',['expected_floor_price'=>'0.00'])==='scheduled','Scheduled floor accepted');
$assert(PriceState::money(wc_get_product($pid)->get_price('edit'))==='100.00','Scheduled price unchanged');
$assert($r->get($id)===$before,'Scheduled campaign unchanged');
$assert($r->item($id,$pid)['snapshot']===$item['snapshot']&&$r->item($id,$pid)['schedule']===$item['schedule'],'Immutable snapshot and plan retained');
$admins=get_users(['role'=>'administrator','number'=>1,'fields'=>'ID']);wp_set_current_user((int)$admins[0]);$nonce=wp_create_nonce('wp_rest');
$save=static function($floor,$expected)use($id,$pid,$nonce){$req=new WP_REST_Request('POST','/exin-bf/v1/admin/campaign/'.$id.'/bulk');$req->set_header('X-WP-Nonce',$nonce);$req->set_header('Content-Type','application/json');$req->set_body(wp_json_encode(['action'=>'floors','amount_unit'=>'store','items'=>[['product_id'=>$pid,'floor_price'=>$floor,'expected_floor_price'=>$expected]]]));return rest_do_request($req)->get_data();};
foreach(['',0,-1,null,'abc','1e2','100.01']as$bad){$reply=$save($bad,'60.00');$assert(empty($reply['results'][0]['ok'])&&$r->item($id,$pid)['floor_price']==='60.00','Invalid floor rejected: '.wp_json_encode($bad));}
$r->save($id,['status'=>'active','start_utc'=>gmdate('Y-m-d H:i:s',time()-5400),'end_utc'=>gmdate('Y-m-d H:i:s',time()+5400)]);$before=$r->get($id);
$reply=$save('75','60.00');$assert(!empty($reply['results'][0]['ok'])&&$reply['results'][0]['pricing']==='changed','Active REST positive floor changed');
$assert(PriceState::money(wc_get_product($pid)->get_price('edit'))==='75.00','Current stage80% clamped to75, not first stage90');
$assert($r->get($id)===$before&&$others()===$beforeOthers,'Campaign and all1737 other item rows unchanged');
$assert($preserved()===$legacy,'Legacy campaign2/4 fixture rows unchanged');
$assert(wc_get_product($untouched)->get_price()==='100.00','Missing floor ordinary price remains visible');
$old=$r->item($id,$pid);$reply=$save('85','60.00');$assert(empty($reply['results'][0]['ok'])&&$r->item($id,$pid)===$old,'Stale CAS fails without overwriting');
$reject=static function($operation)use($assert){$failed=false;try{$operation();}catch(Throwable $e){$failed=true;}$assert($failed,'Unsafe edit must reject');};
$reject(fn()=>$s->update_floor($id,$untouched+999999,'65'));
$reject(fn()=>$s->add($id,$untouched,'65'));$reject(fn()=>$s->remove($id,$pid));$reject(fn()=>$s->update($id,$pid,['featured'=>1]));
foreach(['completed','archived','paused']as$status){$r->save($id,['status'=>$status]);$reject(fn()=>$s->update_floor($id,$pid,'80',['expected_floor_price'=>'75.00']));}
$r->save($id,['status'=>'active','settings'=>$before['settings']+['restoring'=>true]]);$reject(fn()=>$s->update_floor($id,$pid,'80',['expected_floor_price'=>'75.00']));$r->save($id,['settings'=>$before['settings']]);
// Real independent processes, one shared original value; existing GET_LOCK serializes writes.
$barrier=sys_get_temp_dir().'/exin-floor-'.wp_generate_uuid4();$children=[];
foreach(['80','85']as$value){$env=getenv();$env['EXIN_FLOOR_RACE']=wp_json_encode([$id,$pid,'75.00',$value,$barrier]);$proc=proc_open([getcwd().'/scripts/php-v101.sh',getcwd().'/work/runtime/wp-cli.phar','--path='.ABSPATH,'--allow-root','eval-file',__DIR__.'/live-floor-race.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,getcwd(),$env);fclose($pipes[0]);$children[]=[$proc,$pipes];}
touch($barrier);$outcomes=[];
foreach($children as[$proc,$pipes]){$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($proc);$assert($code===0,'Race process '.$err);preg_match('/FLOOR_RACE (\S+)/',$out,$match);$outcomes[]=$match[1]??$out;}unlink($barrier);sort($outcomes);
$assert($outcomes===['floor_price_changed','saved'],'Exactly one concurrent update succeeds');wp_cache_flush();
$current=$r->item($id,$pid)['floor_price'];$assert(in_array($current,['80.00','85.00'],true),'Winning floor retained');
$assert(PriceState::money(wc_get_product($pid)->get_price('edit'))===$current,'Winning current stage price consistent');
$args=[$id,$pid,$r->get($id)['version'],'apply',0];$assert(count(as_get_scheduled_actions(['hook'=>'exin_bf_retry','args'=>$args,'status'=>'pending','per_page'=>10],'ids'))===1,'Retry coalesced to target, no campaign job fanout');
$processor=new PriceProcessor($r,new Logger());$assert($processor->apply($id,$pid,$r->get($id)['version'],0)==='stage_changed','Old stage job skipped');
$single=new WP_REST_Request('POST','/exin-bf/v1/admin/campaign/'.$id.'/item/'.$pid);$single->set_header('X-WP-Nonce',$nonce);$single->set_header('Content-Type','application/json');$single->set_body(wp_json_encode(['action'=>'update','amount_unit'=>'store','floor_price'=>'90','expected_floor_price'=>$current]));$singleReply=rest_do_request($single);
$assert($singleReply->get_status()===200&&$singleReply->get_data()['item']['floor_price']==='90.00','Single-item floor-only REST route');$current='90.00';
$assert(PriceState::money(wc_get_product($pid)->get_price('edit'))===$current,'Single-item immediate current price');
$cart=new WC_Cart();$cart->cart_contents=['target'=>['product_id'=>$pid,'variation_id'=>0,'quantity'=>1,'data'=>wc_get_product($pid),'line_total'=>'1']];$guard=new Cart($r);$assert(count($guard->floor_errors($cart))===1,'Checkout enforces changed floor');$guard->refresh($cart);$assert((float)$cart->cart_contents['target']['data']->get_price()>=(float)$current,'Cart refresh current floor');
$assert($others()===$beforeOthers,'No unrelated item or floor writes after concurrency');
$parent=new WC_Product_Variable();$parent->set_name('Live floor variation parent');$parent->set_status('publish');$parent->save();
$v=new WC_Product_Variation();$v->set_parent_id($parent->get_id());$v->set_regular_price('100');$v->set_status('publish');$v->save();WC_Product_Variable::sync($parent->get_id());
$vc=$before;unset($vc['id']);$vc['status']='draft';$vid=$r->create($vc);$s->add($vid,$v->get_id(),'0');$r->save($vid,['status'=>'active']);
$cached=wc_get_product($parent->get_id())->get_variation_prices();$assert((float)$cached['price'][$v->get_id()]===100.0,'No-floor variation ordinary range');
$cacheVersion=get_option('exin_bf_cache_version');$s->update_floor($vid,$v->get_id(),'65',['expected_floor_price'=>'0.00']);
$assert((float)wc_get_product($parent->get_id())->get_variation_prices()['price'][$v->get_id()]===65.0,'Active add updates cached parent range');
$assert($cacheVersion!==get_option('exin_bf_cache_version'),'Relevant cache invalidated');
$s->update_floor($vid,$v->get_id(),'78',['expected_floor_price'=>'65.00']);
$assert((float)wc_get_product($v->get_id())->get_price()===78.0,'Existing variation floor change visible');
$calls=0;$fail=static function($conflict,$product)use(&$calls,$v){if($product->get_id()===$v->get_id()&&++$calls===2)throw new RuntimeException('Simulated pricing interruption');return $conflict;};
add_filter('exin_bf_dynamic_pricing_conflict',$fail,10,2);$outcome=$s->update_floor($vid,$v->get_id(),'79',['expected_floor_price'=>'78.00']);remove_filter('exin_bf_dynamic_pricing_conflict',$fail);
$assert($outcome==='pending'&&$r->item($vid,$v->get_id())['floor_price']==='79.00','Interrupted pricing reports committed floor plus pending retry');
$assert((float)wc_get_product($v->get_id())->get_price()===79.0,'View fail-closed while retry pending');
(new Exin\BlackFriday\Application\Scheduler($r,$processor,new Logger()))->retry($vid,$v->get_id(),$r->get($vid)['version'],'apply',0);
$assert(PriceState::money(wc_get_product($v->get_id())->get_price('edit'))==='79.00','Existing retry recovers only target price');
$processor->restore($vid,$v->get_id());$assert(PriceState::money(wc_get_product($v->get_id())->get_price('edit'))==='100.00','Original variation price restored');
echo 'LIVE_FLOOR_RESULT '.wp_json_encode(['passed'=>$checks,'failed'=>0,'selected_products'=>1738,'initial_positive_floors'=>344,'concurrent_writers'=>2,'seconds'=>round(microtime(true)-$start,3),'production_accessed'=>false]).PHP_EOL;
