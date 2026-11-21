<?php
/** Focused automatic-plan arithmetic and timing checks, outside WordPress. */
declare(strict_types=1);
define('EXIN_BF_TESTING',true);require dirname(__DIR__,2).'/autoload.php';
use Exin\BlackFriday\Domain\DiscountPlan;
use Exin\BlackFriday\Domain\Money;
$checks=0;$cases=0;$same=static function($expected,$actual)use(&$checks){++$checks;if($expected!==$actual){throw new RuntimeException(json_encode(array('expected'=>$expected,'actual'=>$actual)));}};
$campaign=static function(string$mode,array$extra=array()):array{$plan=DiscountPlan::normalize(array_replace(array('mode'=>'auto_'.$mode,'interval'=>'hourly','steps'=>3,'seed'=>'exin-test-130'),$extra),2);$c=array('start_utc'=>'2026-11-21 00:00:00','timezone'=>'Asia/Tehran','days'=>$plan['steps'],'strategy'=>'linear','power'=>2,'rounding'=>'half_up','settings'=>array('precision'=>2,'discount_plan'=>$plan));$c['end_utc']=DiscountPlan::end($c);return$c;};
foreach(array('progressive'=>array('95.00','72.50','50.00'),'descending'=>array('50.00','72.50','95.00'))as$mode=>$prices){$c=$campaign($mode);$same($prices,DiscountPlan::schedule('100','50',$c));++$cases;}
foreach(array('progressive','descending','rotation','random','wave','hybrid')as$mode){$c=$campaign($mode,array('steps'=>7));foreach(array('half_up','floor','ceil')as$round){$c['rounding']=$round;foreach(DiscountPlan::boundaries($c)as$stage=>$date){foreach(array(true,false)as$selected){$p=DiscountPlan::automatic_price('123.45','87.65',$c,$stage,112,$selected);$m=Money::parse($p,2);$same(true,$m>=8765&&$m<=12345);}}}++$cases;}
$c=$campaign('random');$same(DiscountPlan::automatic_price('100','50',$c,1,77),DiscountPlan::automatic_price('100','50',$c,1,77));$same('100.00',DiscountPlan::automatic_price('100','50',$c,1,77,false));++$cases;
$c=$campaign('hybrid');$same('95.00',DiscountPlan::automatic_price('100','50',$c,0,1,false));$same('87.50',DiscountPlan::automatic_price('100','50',$c,1,1,false));$same('50.00',DiscountPlan::automatic_price('100','50',$c,1,1,true));++$cases;
$c=$campaign('wave',array('steps'=>5,'wave_period'=>4));$same(array('95.00','72.50','50.00','72.50','95.00'),DiscountPlan::schedule('100','50',$c));++$cases;
$c=$campaign('descending',array('descending_end'=>'regular'));$same('100.00',DiscountPlan::schedule('100','50',$c)[2]);$same('100.00',DiscountPlan::automatic_price('100','50',$c,1,1,true,true,false));++$cases;
foreach(array('daily'=>1440,'hourly'=>120,'flash'=>5)as$interval=>$minutes){$c=$campaign('progressive',array('interval'=>$interval,'interval_minutes'=>$minutes));$dates=DiscountPlan::boundaries($c);$same($minutes*60,strtotime($dates[1].' UTC')-strtotime($dates[0].' UTC'));$same(2,DiscountPlan::index($c,strtotime($dates[2].' UTC')+1));++$cases;}
foreach(array(array('interval'=>'flash','interval_minutes'=>1),array('intensity_min'=>'101'),array('intensity_max'=>'101'),array('seed'=>'x'),array('selection_value'=>101),array('interval'=>'hourly','interval_minutes'=>61))as$bad){$hit=false;try{$campaign('random',$bad);}catch(InvalidArgumentException$e){$hit=true;}$same(true,$hit);}foreach(array('0','101','-1')as$floor){$hit=false;try{DiscountPlan::schedule('100',$floor,$campaign('progressive'));}catch(InvalidArgumentException$e){$hit=true;}$same(true,$hit);}++$cases;
$c=$campaign('descending',array('interval'=>'daily','last_day'=>'all_floor'));$same('50.00',DiscountPlan::schedule('100','50',$c)[2]);$same('disabled',$campaign('rotation')['settings']['discount_plan']['last_day']);++$cases;
echo 'EXIN_V130_PLAN_RESULT '.json_encode(array('cases'=>$cases,'assertions'=>$checks,'passed'=>$cases,'failed'=>0)).PHP_EOL;
