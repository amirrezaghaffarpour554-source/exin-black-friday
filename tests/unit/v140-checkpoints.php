<?php
/** Actual bounded reconciliation and frontend guard, with a read/write-capturing DB double. */
define('EXIN_BF_TESTING',true);define('ARRAY_A','ARRAY_A');require dirname(__DIR__,2).'/autoload.php';
class WC_Product {}
class WC_Order {public function get_items($type){return [];}public function get_id(){return 1;}}
$cache=[];$generation='1';$calls=0;
function get_option($key,$default=false){global$generation;return str_contains($key,'report_generation')?$generation:$default;}
function get_transient($key){global$cache;return$cache[$key]??false;}
function set_transient($key,$value,$seconds){global$cache;$cache[$key]=$value;return true;}
function delete_option($key){return true;}
function wc_get_order_statuses(){return ['wc-completed'=>'Completed','wc-cancelled'=>'Cancelled'];}
function wc_get_orders($query){global$calls;$calls++;if($query['limit']!==100)throw new RuntimeException('Unbounded scan');return$query['page']===1?array_fill(0,100,new WC_Order()):[];}
function wp_doing_cron(){return false;}
function doing_action($hook){return false;}
function apply_filters($hook,$value){return$value;}
$wpdb=new class{public string$prefix='wp_';public int$deletes=0;public int$reads=0;public bool$fail=false;public function prepare($q,...$args){return$q;}public function query($q){$this->deletes++;return$this->fail?false:1;}public function get_col($q){return[];}public function get_var($q){$this->reads++;return null;}};
$checks=0;$check=static function($ok)use(&$checks){$checks++;if(!$ok)throw new RuntimeException('Checkpoint check '.$checks);};
$service=new Exin\BlackFriday\Application\SmartAdaptive(new Exin\BlackFriday\Infrastructure\CampaignRepository());$method=(new ReflectionClass($service))->getMethod('refresh');
$c=['id'=>42,'version'=>1,'start_utc'=>gmdate('Y-m-d H:i:s',time()-86400),'settings'=>['discount_plan'=>['adaptive'=>['window_hours'=>24]]]];
$check($method->invoke($service,$c)===false);$check($calls===1);$check($wpdb->deletes===1);
$check($method->invoke($service,$c)===true);$check($calls===2);$check($wpdb->deletes===1);
$check($method->invoke($service,$c)===true);$check($calls===2);
$generation='refund-generation';$check($method->invoke($service,$c)===false);$check($wpdb->deletes===2);
$generation='failure-generation';$wpdb->fail=true;$failed=false;try{$method->invoke($service,$c);}catch(RuntimeException$e){$failed=true;}$check($failed);
$old_calls=$calls;$failed=false;try{$service->price($c,['product_id'=>5],new WC_Product(),0,true);}catch(DomainException$e){$failed=$e->getMessage()==='adaptive_waiting_for_worker';}$check($failed);$check($calls===$old_calls);$check($wpdb->reads===1);
$capacity=new Exin\BlackFriday\Application\AutomaticSelection(new Exin\BlackFriday\Infrastructure\CampaignRepository());$plan=Exin\BlackFriday\Domain\DiscountPlan::normalize(['mode'=>'smart_adaptive','interval'=>'hourly','duration_minutes'=>60,'seed'=>'private-capacity-140'],2);$budget=['start_utc'=>'2026-11-22 00:00:00','end_utc'=>'2026-11-22 01:00:00','timezone'=>'UTC','settings'=>['discount_plan'=>$plan,'precision'=>2]];$capacity->capacity($budget,1);$check(true);$failed=false;try{$capacity->capacity($budget,3000);}catch(DomainException$e){$failed=$e->getMessage()==='adaptive_capacity_exceeded';}$check($failed);
echo 'EXIN_V140_CHECKPOINTS '.json_encode(['passed'=>$checks,'failed'=>0,'real_database'=>false,'frontend_analytics_calls'=>0]).PHP_EOL;
