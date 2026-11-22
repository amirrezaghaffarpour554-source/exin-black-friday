<?php
require __DIR__.'/v140-readiness-common.php';
use Exin\BlackFriday\Application\AdaptiveAnalytics;
use Exin\BlackFriday\Application\Scheduler;
use Exin\BlackFriday\Infrastructure\CampaignRepository;
$env=json_decode(getenv('EXIN_READY_CONTEXT'),true,512,JSON_THROW_ON_ERROR);$role=getenv('EXIN_READY_ROLE');$until=microtime(true)+30;while(!file_exists($env['gate'])){ready_assert(microtime(true)<$until,'Start barrier timeout');usleep(10000);}
$report=ready_measure(static function()use($role,$env){$r=new CampaignRepository();$a=new AdaptiveAnalytics($r);global$wpdb;
 if($role==='kill'){
  add_filter('query',static function($sql)use($env){if(preg_match('/^DELETE FROM .*exin_bf_adaptive_events/i',$sql)){file_put_contents($env['kill_ready'],'checkpoint committed, before ack');while(true)usleep(10000);}return$sql;});
  ActionScheduler_QueueRunner::instance()->process_action($env['kill_action'],'EXIN kill checkpoint test');return false;
 }
 if($role==='pricing')return ready_drain('exin_bf_batch',100);
 if($role==='analytics'){for($i=0;$i<40;$i++){$a->wake();ready_drain('exin_bf_adaptive_analytics',1);usleep(100000);}return ['passes'=>40];}
 if($role==='updates'){foreach($env['orders']as$oid){$o=wc_get_order($oid);$o->update_status('pending');$o->update_status('completed');$a->enqueue($oid,$o);$a->enqueue($oid,$o);}$o=wc_get_order($env['refund_order']);$lid=array_key_first($o->get_items());$line=$o->get_item($lid);$amount=wc_format_decimal((float)$line->get_total()/2,2);$ref=wc_create_refund(['order_id'=>$o->get_id(),'amount'=>$amount,'line_items'=>[$lid=>['qty'=>1,'refund_total'=>$amount]],'refund_payment'=>false,'restock_items'=>false]);ready_assert(!is_wp_error($ref),'Concurrent refund failed');for($i=0;$i<3;$i++)$a->enqueue($o->get_id(),$o);return ['updated_orders'=>count($env['orders']),'refunded_units'=>1,'refund_finished_unix'=>microtime(true)];}
 $offset=(int)$role*40;$expected_units=0;$expected_orders=0;
 for($i=1;$i<=40;$i++){$pid=$env['products'][$offset+$i-1];$o=wc_create_order();$o->set_currency('USD');$lid=$o->add_product(wc_get_product($pid),2);$line=$o->get_item($lid);$line->add_meta_data('_exin_bf_campaign_id',$env['campaign'],true);$line->add_meta_data('_exin_bf_product_id',$pid,true);$line->save();$o->update_meta_data('_exin_bf_campaign_'.$env['campaign'],'1');$o->calculate_totals();$o->save();$o->update_status('completed');$o->update_status('pending');$o->update_status($i%10===0?'cancelled':'completed');
  if($i%10!==0){$units=2;if($i%7===0){$amount=wc_format_decimal((float)$line->get_total()/2,2);$ref=wc_create_refund(['order_id'=>$o->get_id(),'amount'=>$amount,'line_items'=>[$lid=>['qty'=>1,'refund_total'=>$amount]],'refund_payment'=>false,'restock_items'=>false]);ready_assert(!is_wp_error($ref),'Refund failed');$units--;}$expected_units+=$units;$expected_orders++;}
  $a->enqueue($o->get_id(),$o);$a->enqueue($o->get_id(),$o);
  if($i%13===0){if($i%10!==0){$expected_units-=2;$expected_orders--;}$o->delete(true);}
 }
 return ['created_orders'=>40,'expected_units'=>$expected_units,'expected_counted_orders'=>$expected_orders];
});echo 'READY_CHILD '.wp_json_encode(['role'=>$role,'measurement'=>$report]).PHP_EOL;
