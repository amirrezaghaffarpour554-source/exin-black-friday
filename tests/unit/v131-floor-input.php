<?php
// Short check of the actual REST floor validator; no WordPress database or writes.
define('EXIN_BF_TESTING', true);
require dirname(__DIR__, 2) . '/autoload.php';
function get_woocommerce_currency(){return 'USD';}
function wc_get_price_decimals(){return 2;}
$class=new ReflectionClass(Exin\BlackFriday\Rest\AdminController::class);
$controller=$class->newInstanceWithoutConstructor();
$method=$class->getMethod('validated_floor');
$c=['settings'=>['discount_plan'=>['mode'=>'auto_progressive']]];
foreach(['','0','۰','-1','abc','1,23'] as $bad){
 try{$method->invoke($controller,$c,1,$bad,'store');throw new RuntimeException('Invalid floor accepted');}
 catch(InvalidArgumentException $expected){}
}
if($method->invoke($controller,$c,1,'۱۲٫۳۴','store')!=='12.34')throw new RuntimeException('Valid floor changed');
echo "PASS actual floor validator: 6 invalid inputs rejected, positive localized input accepted; no writes\n";
