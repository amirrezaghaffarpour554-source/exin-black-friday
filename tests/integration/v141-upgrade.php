<?php
/** Cross-process active upgrade/rollback preservation; local synthetic data only. */
use Exin\BlackFriday\Application\{ProductSelectionService,PriceProcessor,PriceState};
use Exin\BlackFriday\Infrastructure\{CampaignRepository,Migration,Logger};
use Exin\BlackFriday\Domain\DiscountPlan;
if(!defined('WP_CLI')||!WP_CLI||DB_NAME!=='exin_test'||wp_get_environment_type()!=='local')throw new RuntimeException('Isolated local database required');
$phase=getenv('EXIN_UPGRADE_PHASE')?:'';
if($phase==='install_loader'){
 $mu=WP_CONTENT_DIR.'/mu-plugins';if(!is_dir($mu))mkdir($mu,0700,true);
 $loader=<<<'LOADER'
<?php
// Synthetic upgrade fixture only; no other WordPress prefix is affected.
global $wpdb;
if($wpdb->prefix==='exin_upgrade_v141_'&&DB_NAME==='exin_test'&&wp_get_environment_type()==='local'){
 $root=getenv('EXIN_UPGRADE_ROOT');
 if($root&&in_array(realpath($root),[realpath('/workspace/exin-black-friday'),realpath('/workspace/exin-black-friday/work/v141/base')],true))require_once $root.'/exin-black-friday.php';
}
LOADER;
 file_put_contents($mu.'/exin-upgrade-v141.php',$loader);echo "UPGRADE_LOADER_READY\n";return;
}
global $wpdb;if($wpdb->prefix!=='exin_upgrade_v141_')throw new RuntimeException('Dedicated upgrade prefix required');
$checks=0;$assert=static function($ok,$why)use(&$checks){++$checks;if(!$ok)throw new RuntimeException($why);};
$assert(Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled(),'Real HPOS required');
$r=new CampaignRepository();$selection=new ProductSelectionService($r);$processor=new PriceProcessor($r,new Logger());$manifest=getcwd().'/work/v141/upgrade-fixture.json';$results=getcwd().'/work/v141/upgrade-results.json';
$capture=static function(array$fixture, bool $hashes_only=true)use($wpdb){
 $out=[];foreach(['campaigns','items']as$table)$out[$table]=$wpdb->get_results('SELECT * FROM '.$wpdb->prefix.'exin_bf_'.$table.' ORDER BY 1,2',ARRAY_A);
 $ids=array_map('intval',$fixture['product_ids']);$posts=implode(',',$ids);$out['product_posts']=$wpdb->get_results("SELECT * FROM {$wpdb->posts} WHERE ID IN ($posts) ORDER BY ID",ARRAY_A);$out['product_metadata']=$wpdb->get_results("SELECT * FROM {$wpdb->postmeta} WHERE post_id IN ($posts) ORDER BY meta_id",ARRAY_A);
 $oid=(int)$fixture['order_id'];foreach(['wc_orders'=>'id','wc_order_addresses'=>'order_id','wc_order_operational_data'=>'order_id','wc_orders_meta'=>'order_id']as$table=>$column)$out[$table]=$wpdb->get_results('SELECT * FROM '.$wpdb->prefix.$table.' WHERE '.$column.'='.$oid.' ORDER BY 1',ARRAY_A);
 $out['order_items']=$wpdb->get_results("SELECT * FROM {$wpdb->prefix}woocommerce_order_items WHERE order_id=$oid ORDER BY order_item_id",ARRAY_A);$out['order_item_metadata']=$wpdb->get_results("SELECT m.* FROM {$wpdb->prefix}woocommerce_order_itemmeta m JOIN {$wpdb->prefix}woocommerce_order_items i ON i.order_item_id=m.order_item_id WHERE i.order_id=$oid ORDER BY meta_id",ARRAY_A);
 $out['store_settings']=$wpdb->get_results("SELECT option_name,option_value FROM {$wpdb->options} WHERE option_name IN ('woocommerce_currency','woocommerce_price_num_decimals','woocommerce_custom_orders_table_enabled','woocommerce_shipping_cost_requires_address','woocommerce_calc_taxes') OR option_name LIKE 'exin_bf_auto_stock_%' ORDER BY option_name",ARRAY_A);
 if(!$hashes_only)return$out;
 $hashes=[];foreach($out as$key=>$value)$hashes[$key]=hash('sha256',serialize($value));return$hashes;
};
if($phase==='prepare'){
 $assert(EXIN_BF_VERSION==='1.4.0','Fixture must be created by exact optimized baseline');$baseline=shell_exec('git rev-parse ac7e206bd4135ec646f7d3b12144f82408b4accd');$assert(trim((string)$baseline)==='ac7e206bd4135ec646f7d3b12144f82408b4accd','Baseline Git object verified');
 Migration::run();update_option('woocommerce_currency','USD');update_option('woocommerce_price_num_decimals',2);update_option('woocommerce_calc_taxes','yes');update_option('woocommerce_shipping_cost_requires_address','yes');
 $plan=DiscountPlan::normalize(['mode'=>'custom','interval'=>'custom','points'=>[['offset_minutes'=>0,'kind'=>'percent','value'=>'10'],['offset_minutes'=>60,'kind'=>'percent','value'=>'30']],'duration_minutes'=>10080],2);
 $seed=['title'=>'Upgrade preserved campaign','start_utc'=>gmdate('Y-m-d H:i:s',time()-5400),'end_utc'=>gmdate('Y-m-d H:i:s',time()+599400),'timezone'=>'UTC','days'=>7,'strategy'=>'linear','power'=>2,'rounding'=>'half_up','settings'=>['discount_plan'=>$plan,'existing_sale_policy'=>'preserve','currency'=>'USD','precision'=>2,'free_shipping'=>true,'shipping_threshold'=>'123.45','shipping_subsidy_cap'=>'7.89','shipping_instances'=>[2,4],'coupon_floor_policy'=>'block','notifications'=>['enabled'=>false,'stage'=>false],'manual_floor_catalog'=>true,'fixture_sentinel'=>'preserve every setting']];
 $campaigns=[];for($n=0;$n<4;$n++)$campaigns[]=$r->create($seed);$id=$campaigns[3];$products=[];$parents=[];$positive=[];$missing=[];
 for($n=0;$n<72;$n++){
  if($n>=70){$parent=new WC_Product_Variable();$parent->set_name('Upgrade parent '.$n);$parent->set_status('publish');$parent->set_manage_stock(true);$parent->set_stock_quantity(22);$parent->save();$parents[]=$parent->get_id();$p=new WC_Product_Variation();$p->set_parent_id($parent->get_id());$p->set_manage_stock(false);}else{$p=new WC_Product_Simple();$p->set_name('Upgrade product '.$n);$p->set_manage_stock(true);$p->set_stock_quantity(11+$n);}
  $p->set_status('publish');$p->set_regular_price('100.00');$p->set_sku('UPGRADE-'.wp_generate_uuid4());$p->set_stock_status('instock');
  if($n===8){$p->set_sale_price('90.00');$p->set_date_on_sale_from(time()+86400);$p->set_date_on_sale_to(time()+172800);}if($n===9){$p->set_sale_price('95.00');$p->set_date_on_sale_from(time()-86400);$p->set_date_on_sale_to(time()+172800);}
  $p->save();$pid=$p->get_id();$products[]=$pid;$floor=$n%4===0?'0':('45.'.str_pad((string)($n%100),2,'0',STR_PAD_LEFT));$selection->add($id,$pid,$floor,['priority'=>$n,'featured'=>$n%7===0,'floor_override'=>1]);if($floor==='0')$missing[]=$pid;else$positive[]=$pid;
  if($n>=70)WC_Product_Variable::sync($p->get_parent_id());
 }
 $r->save($campaigns[1],['status'=>'active']);$r->save($id,['status'=>'active']);$campaign=$r->get($id);foreach(array_slice($positive,0,3)as$pid)$processor->apply($id,$pid,$campaign['version']);
 $pending=$positive[3];$raw=PriceState::capture(wc_get_product($pending));$target=$raw;$target['price']='70.00';$target['sale']='70.00';$target['from']=null;$target['to']=null;$intent=['kind'=>'apply','before'=>$raw,'target'=>$target,'version'=>$campaign['version'],'created_at'=>gmdate('Y-m-d H:i:s')];$r->put_item($id,$pending,['intent'=>$intent,'operation_id'=>wp_generate_uuid4(),'status'=>'pending','retry_state'=>['version'=>$campaign['version'],'mode'=>'apply','attempt'=>1,'next_at'=>time()+3600,'running'=>true,'exhausted'=>false]]);PriceState::write(wc_get_product($pending),$target);
 $conflict=$positive[4];$p=wc_get_product($conflict);$p->set_regular_price('115.00');$p->save();$r->put_item($id,$conflict,['status'=>'conflict','conflict'=>'external_price_changed']);
 $settings=$r->get($id)['settings'];$settings['last_run']=['version'=>$campaign['version'],'stage'=>1,'mode'=>'apply','changed'=>3,'unchanged'=>7,'skipped'=>2,'errors'=>1,'after'=>$products[27],'complete'=>false,'wake_after'=>$products[17],'pending_results'=>[$pending=>'errors',$conflict=>'errors']];$r->save($id,['settings'=>$settings]);update_option('exin_bf_auto_stock_'.$id,1,false);
 $order=wc_create_order(['status'=>'pending']);$order->set_payment_method('bacs');$order->set_currency('USD');$order->add_product(wc_get_product($products[5]),2);$order->set_shipping_total('3.21');$order->update_meta_data('_exin_upgrade_preservation',['campaign_id'=>$id,'floor'=>'45.05','sentinel'=>'historical order unchanged']);$order->calculate_totals();$order->save();
 $fixture=['campaign_id'=>$id,'campaign_ids'=>$campaigns,'selected_products'=>$products,'product_ids'=>array_merge($products,$parents),'positive_products'=>$positive,'missing_products'=>$missing,'pending_product'=>$pending,'order_id'=>$order->get_id(),'version'=>$campaign['version']];// Settle normal Woo shutdown work before recording the installed baseline.
 // Deferred parent sync replaces identical _price metadata rows (new meta_id);
 // comparison remains strict and includes every parent/variation row afterward.
 file_put_contents(getcwd().'/work/v141/upgrade-before-drain.json',wp_json_encode($capture($fixture,false),JSON_PRETTY_PRINT));WC_Post_Data::do_deferred_product_sync();$fixture['hashes']=$capture($fixture);file_put_contents(getcwd().'/work/v141/upgrade-before-data.json',wp_json_encode($capture($fixture,false),JSON_PRETTY_PRINT));file_put_contents($manifest,wp_json_encode($fixture,JSON_PRETTY_PRINT));
 $assert(count($products)===72&&count($positive)===54&&count($missing)===18,'72 actual selected Woo products, 54 manual/18 missing floors');$assert($r->item($id,$pending)['intent']!==null&&$r->item($id,$positive[0])['expected']!==null,'Owned and interrupted financial states seeded');
 $phaseResult=['phase'=>$phase,'passed'=>$checks,'failed'=>0,'version'=>EXIN_BF_VERSION,'schema'=>get_option('exin_bf_schema_version')];file_put_contents($results,wp_json_encode([$phaseResult],JSON_PRETTY_PRINT));echo 'UPGRADE_PHASE '.wp_json_encode($phaseResult).PHP_EOL;return;
}
$assert(in_array($phase,['verify_new','verify_old'],true),'Known verification phase');$fixture=json_decode(file_get_contents($manifest),true);$assert(EXIN_BF_VERSION===($phase==='verify_new'?'1.4.1':'1.4.0'),'Expected distinct source bootstrapped in this process');
$current=$capture($fixture);foreach($fixture['hashes']as$key=>$hash){if(($current[$key]??null)!==$hash){file_put_contents(getcwd().'/work/v141/upgrade-after-data.json',wp_json_encode($capture($fixture,false),JSON_PRETTY_PRINT));}$assert(($current[$key]??null)===$hash,'Unchanged raw state after '.$phase.': '.$key);}
Migration::run();$assert($capture($fixture)===$fixture['hashes'],'Versioned migration no-op preserves complete populated financial/order state');
$c=$r->get($fixture['campaign_id']);$assert($c['status']==='active'&&$c['version']===$fixture['version'],'Active status/version unchanged');$assert($r->count_items($c['id'])===72,'All 72 selections remain');$assert(count($r->item($c['id'],$fixture['pending_product'])['intent'])>0,'Pending intent remains unmodified and unsettled');$assert(get_option('exin_bf_schema_version')==='6','Schema unchanged across upgrade/rollback');
$phaseResult=['phase'=>$phase,'passed'=>$checks,'failed'=>0,'version'=>EXIN_BF_VERSION,'schema'=>get_option('exin_bf_schema_version'),'selected_products'=>72,'positive_floors'=>54,'missing_floors'=>18,'raw_hashes'=>$fixture['hashes']];$history=json_decode(file_get_contents($results),true);$history[]=$phaseResult;file_put_contents($results,wp_json_encode($history,JSON_PRETTY_PRINT));echo 'UPGRADE_PHASE '.wp_json_encode($phaseResult).PHP_EOL;
