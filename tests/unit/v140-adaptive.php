<?php
/** Focused pure policy and exact floor checks. */
define('EXIN_BF_TESTING',true);require dirname(__DIR__,2).'/autoload.php';
use Exin\BlackFriday\Domain\AdaptivePolicy;
use Exin\BlackFriday\Domain\DiscountPlan;
use Exin\BlackFriday\Domain\Money;
$checks=0;$same=static function($expected,$actual)use(&$checks){$checks++;if($expected!==$actual)throw new RuntimeException(json_encode(array($expected,$actual)));};
$p=AdaptivePolicy::normalize(array('expected_units_day'=>100,'exploration_bps'=>0));$start=1700000000;$now=$start+172800;$end=$start+604800;
$previous=array('intensity_bps'=>2000,'changed_at'=>$start,'metrics'=>array('units'=>0),'direction'=>0);
$metrics=array('units'=>1,'orders'=>3,'stock'=>10000);
$d=AdaptivePolicy::decide($p,$metrics,$previous,$now,$start,$end,0);$same(3000,$d['intensity_bps']);
$d=AdaptivePolicy::decide($p,array('units'=>200,'orders'=>10,'stock'=>2),$previous,$now,$start,$end,0);$same(1000,$d['intensity_bps']);
$d=AdaptivePolicy::decide($p,array('units'=>100,'orders'=>10,'stock'=>1000),$previous,$now,$start,$end,0);$same(2000,$d['intensity_bps']);$same('hold',$d['reason']);
$d=AdaptivePolicy::decide($p,array('units'=>0,'orders'=>0,'stock'=>1000),null,$now,$start,$end,100);$same('cold_start',$d['reason']);$same(1000,$d['intensity_bps']);$same($now,$d['changed_at']);
$d=AdaptivePolicy::decide($p,$metrics,array_replace($previous,array('changed_at'=>$now-10)),$now,$start,$end,0);$same('cooldown',$d['reason']);
$d=AdaptivePolicy::decide($p,$metrics,array_replace($previous,array('direction'=>1,'metrics'=>array('units'=>10,'seconds'=>86400))),$now,$start,$end,0);$same('no_observed_gain',$d['reason']);$same(2000,$d['intensity_bps']);
foreach(array('conservative','balanced','aggressive')as$preset){
 $params=AdaptivePolicy::normalize(array('preset'=>$preset));
 $plan=DiscountPlan::normalize(array('mode'=>'smart_adaptive','duration_minutes'=>10080,'interval'=>'hourly','adaptive'=>array('preset'=>$preset),'seed'=>'private-test-140'),2);
 $c=array('settings'=>array('discount_plan'=>$plan,'precision'=>2),'start_utc'=>'2026-11-22 00:00:00','timezone'=>'Asia/Tehran','days'=>7,'rounding'=>'half_up');$c['end_utc']=DiscountPlan::end($c);
 foreach(array(0,1,167)as$stage){foreach(array('half_up','floor','ceil')as$r){$c['rounding']=$r;foreach(array(0,1000,$params['maximum_bps'])as$intensity){$price=DiscountPlan::automatic_price('123456789012.34','100000000000.01',$c,$stage,1,true,true,true,$intensity);$m=Money::parse($price,2);$same(true,$m>=10000000000001&&$m<=12345678901234);$same($price,DiscountPlan::automatic_price('123456789012.34','100000000000.01',$c,$stage,1,true,true,true,$intensity));}}}
 $same('disabled',$plan['last_day']);$same(168,DiscountPlan::stage_count($c));
 $d=AdaptivePolicy::decide($params,$metrics,$previous,$now,$start,$end,100000);$same(true,abs($d['intensity_bps']-2000)<=$params['max_change_bps']);
}
foreach(array(array('maximum_bps'=>10001),array('preset'=>'unknown'),array('expected_units_day'=>0),array('cooldown_minutes'=>1))as$bad){$hit=false;try{AdaptivePolicy::normalize($bad);}catch(InvalidArgumentException$e){$hit=true;}$same(true,$hit);}
$hit=false;try{DiscountPlan::normalize(array('mode'=>'smart_adaptive','last_day'=>'all_floor','seed'=>'forbidden-floor-test'),2);}catch(InvalidArgumentException$e){$hit=true;}$same(true,$hit);
$hit=false;try{AdaptivePolicy::decide($p,array('units'=>-1,'orders'=>3,'stock'=>50),null,$now,$start,$end,0);}catch(DomainException$e){$hit=true;}$same(true,$hit);
$hit=false;try{AdaptivePolicy::decide($p,$metrics,array('intensity_bps'=>99999,'changed_at'=>$now),$now,$start,$end,0);}catch(DomainException$e){$hit=true;}$same(true,$hit);
foreach(array(0,8)as$precision){$plan=DiscountPlan::normalize(array('mode'=>'smart_adaptive','duration_minutes'=>10080,'interval'=>'hourly','seed'=>'precision-check-140'),$precision);$c=array('settings'=>array('precision'=>$precision,'discount_plan'=>$plan),'timezone'=>'UTC','start_utc'=>'2026-11-22 00:00:00');$c['end_utc']=DiscountPlan::end($c);$same(Money::format(Money::parse('95',$precision),$precision),DiscountPlan::automatic_price('100','50',$c,0));$same(Money::format(Money::parse('100',$precision),$precision),DiscountPlan::automatic_price('100','100',$c,167));$same(Money::format(Money::parse('100',$precision),$precision),DiscountPlan::automatic_price('100','50',$c,167,1,true,true,true,0));}
echo 'EXIN_V140_UNIT '.json_encode(array('assertions'=>$checks,'passed'=>$checks,'failed'=>0)).PHP_EOL;
