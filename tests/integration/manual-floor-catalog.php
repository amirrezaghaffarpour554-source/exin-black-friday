<?php
/** Isolated manual-floor catalogue regression; uses the existing 1738-product fixture. */
require __DIR__.'/v140-readiness-common.php';
use Exin\BlackFriday\Infrastructure\CampaignRepository;
use Exin\BlackFriday\Infrastructure\Logger;
use Exin\BlackFriday\Application\ProductSelectionService;
use Exin\BlackFriday\Application\BulkSelectionService;
use Exin\BlackFriday\Application\CatalogProductQuery;
use Exin\BlackFriday\Application\PriceProcessor;
use Exin\BlackFriday\Application\PriceState;
use Exin\BlackFriday\Domain\DiscountPlan;
global$wpdb;$r=new CampaignRepository();$selection=new ProductSelectionService($r);$processor=new PriceProcessor($r,new Logger());
$prior=static function()use($wpdb){$out=[];foreach(['campaigns','items']as$t)$out[$t]=$wpdb->get_results('SELECT * FROM '.$wpdb->prefix.'exin_bf_'.$t.' WHERE '.($t==='campaigns'?'id':'campaign_id').' IN (2,4) ORDER BY 1,2',ARRAY_A);return$out;};$before=$prior();
$p=new WC_Product_Simple();$p->set_name('Manual-floor no discount');$p->set_status('publish');$p->set_regular_price('100');$p->set_sale_price('80');$p->set_manage_stock(true);$p->set_stock_quantity(20);$p->save();$original=PriceState::capture($p);
$plan=DiscountPlan::normalize(['mode'=>'auto_hybrid','duration_minutes'=>10080,'interval'=>'hourly','interval_minutes'=>120,'seed'=>'manual-floor-safe'],2);$c=['title'=>'Explicit manual-floor test','start_utc'=>gmdate('Y-m-d H:i:s',time()-172805),'timezone'=>'UTC','days'=>7,'strategy'=>'linear','power'=>2,'rounding'=>'half_up','settings'=>['discount_plan'=>$plan,'existing_sale_policy'=>'preserve','manual_floor_catalog'=>true,'currency'=>'USD','precision'=>2]];$c['end_utc']=DiscountPlan::end($c);$id=$r->create($c);$r->save($id,['status'=>'active']);$c=$r->get($id);
$q=new CatalogProductQuery();$missing=$q->search('',1,['missing_floor_campaign'=>$id,'stock'=>'instock','type'=>'simple']);ready_assert($missing['total']>=1738&&count($missing['products'])<=20,'Unbounded or incorrect missing floor pagination');
$page=new Exin\BlackFriday\Integration\CatalogPage($r);$bench=ready_measure(static function()use($page,$c){return$page->query([$c],['include_store'=>true,'limit'=>25,'page'=>1]);});ready_assert($bench['result']['total']>=1738,'Store products without floors absent');ready_assert(PriceState::capture(wc_get_product($p->get_id()))===$original,'No-floor price changed');
foreach(['percent','amount','shared','previous','none']as$mode){$failed=false;try{BulkSelectionService::resolve_floor($r,$c,$p->get_id(),'100',['mode'=>$mode,'value'=>'10']);}catch(DomainException$e){$failed=true;}ready_assert($failed,'Automatic floor accepted');}
$floor=BulkSelectionService::resolve_floor($r,$c,$p->get_id(),'100',['mode'=>'independent','floors'=>[$p->get_id()=>'60']]);$selection->add($id,$p->get_id(),$floor);$processor->apply($id,$p->get_id(),$c['version']);$price=(float)wc_get_product($p->get_id())->get_price('edit');ready_assert($price>=60&&$price<=100,'Floor violation');$stage=DiscountPlan::index($c,time());ready_assert($stage>0,'Fixture did not test current stage');$processor->restore($id,$p->get_id());ready_assert(PriceState::capture(wc_get_product($p->get_id()))===$original,'Restore mismatch');ready_assert($before===$prior(),'Campaign 2 or 4 changed');$r->save($id,['status'=>'completed']);
echo 'MANUAL_FLOOR_RESULT '.wp_json_encode(['result'=>'PASS','catalog_products'=>$missing['total'],'floor_bound'=>true,'manual_only'=>true,'prior_campaigns_2_4_preserved'=>true,'restore'=>true,'catalog_benchmark'=>$bench]).PHP_EOL;
