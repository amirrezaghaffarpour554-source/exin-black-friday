<?php
/** Seed financial records using the real retained v1.3.2 ZIP, before migration. */
use Exin\BlackFriday\Infrastructure\CampaignRepository;
use Exin\BlackFriday\Infrastructure\Logger;
use Exin\BlackFriday\Application\ProductSelectionService;
use Exin\BlackFriday\Application\PriceProcessor;
use Exin\BlackFriday\Application\PriceState;
use Exin\BlackFriday\Domain\DiscountPlan;
if(!defined('WP_CLI')||!WP_CLI||DB_NAME!=='exin_test'||wp_get_environment_type()!=='local'||EXIN_BF_VERSION!=='1.3.2')throw new RuntimeException('Actual v1.3.2 in isolated DB required');
global $wpdb;
if(get_option('exin_bf_schema_version')!=='6')throw new RuntimeException('Expected v1.3.2 schema');
$repo=new CampaignRepository();$selection=new ProductSelectionService($repo);$processor=new PriceProcessor($repo,new Logger());$products=[];
update_option('woocommerce_currency','USD');update_option('woocommerce_price_num_decimals',2);
for($n=0;$n<2;$n++){
 $plan=DiscountPlan::normalize(['mode'=>'fixed_percent','initial_discount'=>'5','steps'=>7],2);
 $c=['title'=>'v132 populated migration '.$n,'start_utc'=>gmdate('Y-m-d H:i:s',time()-3600),'timezone'=>'Asia/Tehran','days'=>7,'strategy'=>'linear','power'=>2,'rounding'=>'half_up','settings'=>['discount_plan'=>$plan,'existing_sale_policy'=>'preserve']];$c['end_utc']=DiscountPlan::end($c);$id=$repo->create($c);
 foreach(['61.23','73.45']as$i=>$floor){$p=new WC_Product_Simple();$p->set_name('v132 preserved floor '.$n.'-'.$i);$p->set_status('publish');$p->set_virtual(true);$p->set_regular_price('100');$p->set_manage_stock(true);$p->set_stock_quantity(17);if($i===0)$p->set_sale_price('80');$p->save();$selection->add($id,$p->get_id(),$floor);$repo->put_item($id,$p->get_id(),['floor_override'=>$i===0?1:0]);$products[]=$p;}
 $repo->save($id,['status'=>'active']);$processor->apply($id,$products[count($products)-2]->get_id(),$repo->get($id)['version']);
}
$before=['schema'=>get_option('exin_bf_schema_version')];foreach(['campaigns','items','audit','turns','locks']as$t)$before['tables'][$t]=$wpdb->get_results('SELECT * FROM '.$wpdb->prefix.'exin_bf_'.$t.' ORDER BY 1,2',ARRAY_A);
foreach($products as$p)$before['prices'][$p->get_id()]=PriceState::capture(wc_get_product($p->get_id()));
$path=getenv('EXIN140_BASELINE_PATH')?:dirname(__DIR__,2).'/work/v140/v132-populated.json';file_put_contents($path,wp_json_encode($before));echo 'SEEDED real v1.3.2 populated campaigns, floor overrides, snapshots, audit and ownership'.PHP_EOL;
