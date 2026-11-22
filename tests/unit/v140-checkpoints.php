<?php
/** Pricing reads only a validated snapshot; unavailable data preserves safe amounts. */
define('EXIN_BF_TESTING',true);define('ARRAY_A','ARRAY_A');require dirname(__DIR__,2).'/autoload.php';
class WC_Product {public function get_stock_managed_by_id(){return 5;}public function managing_stock(){return false;}}
function wc_get_product($id){return new WC_Product();}
function get_option($k,$default=false){return$default;}
function wp_json_encode($v){return json_encode($v);}
function wc_get_orders($q){throw new RuntimeException('Pricing scanned orders');}
function wp_doing_cron(){return false;}
function doing_action($hook){return false;}
function apply_filters($hook,$value){return$value;}
$wpdb=new class{public string$prefix='wp_';public string$last_error='';public array$queries=[];public bool$previous=false;public function prepare($q,...$args){return$q;}public function get_var($q){$this->queries[]=$q;return$this->previous&&str_contains($q,'stage<')?json_encode(['intensity_bps'=>2000]):null;}public function get_row($q,$format){$this->queries[]=$q;return null;}public function insert(){throw new RuntimeException('Fabricated a decision without data');}};
$repo=new Exin\BlackFriday\Infrastructure\CampaignRepository();$service=new Exin\BlackFriday\Application\SmartAdaptive($repo);$checks=0;$same=static function($want,$got)use(&$checks){$checks++;if($want!==$got)throw new RuntimeException('Check '.$checks);};
$plan=Exin\BlackFriday\Domain\DiscountPlan::normalize(['mode'=>'smart_adaptive','interval'=>'hourly','duration_minutes'=>10080,'seed'=>'safe-snapshot-unit'],2);
$c=['id'=>42,'version'=>1,'start_utc'=>'2026-11-22 00:00:00','end_utc'=>'2026-11-29 00:00:00','timezone'=>'UTC','settings'=>['discount_plan'=>$plan,'precision'=>2]];
$item=['product_id'=>5,'base_price'=>'100.00','floor_price'=>'50.00','expected'=>['price'=>'92.00'],'snapshot'=>['price'=>'80.00']];
$same('92.00',$service->price($c,$item,new WC_Product(),1,true));$wpdb->previous=true;$same('90.00',$service->price($c,$item,new WC_Product(),1,true));
$wpdb->previous=false;$item['expected']['price']='1.00';$same('100.00',$service->price($c,$item,new WC_Product(),1,true));
foreach($wpdb->queries as$q){$same(false,str_contains($q,'SUM('));$same(false,str_contains($q,'adaptive_sales'));}
$capacity=new Exin\BlackFriday\Application\AutomaticSelection($repo);$budget=$c;$budget['settings']['discount_plan']=Exin\BlackFriday\Domain\DiscountPlan::normalize(['mode'=>'smart_adaptive','interval'=>'hourly','duration_minutes'=>60,'seed'=>'private-capacity-140'],2);$budget['end_utc']='2026-11-22 01:00:00';$capacity->capacity($budget,1);$same(true,true);$failed=false;try{$capacity->capacity($budget,3000);}catch(DomainException$e){$failed=true;}$same(true,$failed);
echo 'EXIN_V140_CHECKPOINTS '.json_encode(['passed'=>$checks,'failed'=>0,'real_database'=>false,'pricing_order_scans'=>0,'pricing_aggregates'=>0]).PHP_EOL;
