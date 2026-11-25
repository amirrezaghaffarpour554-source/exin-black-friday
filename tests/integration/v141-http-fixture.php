<?php
/** Local-only 10,000-product fixture for real HTTP release auditing. */
if(!defined('WP_CLI')||!WP_CLI||DB_NAME!=='exin_test'||wp_get_environment_type()!=='local'||$GLOBALS['wpdb']->prefix!=='exin_v141_')throw new RuntimeException('Dedicated exin_v141_ fixture required');
global $wpdb;
if(get_option('exin_v141_fixture')){echo wp_json_encode(get_option('exin_v141_fixture')).PHP_EOL;return;}
update_option('woocommerce_currency','USD');update_option('woocommerce_price_num_decimals',2);update_option('woocommerce_enable_coupons','yes');
switch_theme('twentytwentyone');
$home=wp_insert_post(['post_type'=>'page','post_status'=>'publish','post_title'=>'Release home','post_content'=>'[products limit="24" columns="4"]']);update_option('show_on_front','page');update_option('page_on_front',$home);
WC_Install::create_pages();
$r=new Exin\BlackFriday\Infrastructure\CampaignRepository();$plan=Exin\BlackFriday\Domain\DiscountPlan::normalize(['mode'=>'custom','interval'=>'custom','points'=>[['offset_minutes'=>0,'kind'=>'percent','value'=>'10'],['offset_minutes'=>60,'kind'=>'percent','value'=>'80']],'duration_minutes'=>10080],2);
$cid=$r->create(['title'=>'v141 audit 10k / 1750','start_utc'=>gmdate('Y-m-d H:i:s',time()-5400),'end_utc'=>gmdate('Y-m-d H:i:s',time()+5*DAY_IN_SECONDS),'timezone'=>'UTC','days'=>7,'strategy'=>'linear','power'=>2,'rounding'=>'half_up','settings'=>['currency'=>'USD','precision'=>2,'discount_plan'=>$plan,'manual_floor_catalog'=>true,'include_store_products'=>true]]);
$festival=wp_insert_post(['post_type'=>'page','post_status'=>'publish','post_title'=>'Release festival','post_content'=>'[exin_bf_products campaign_id="'.$cid.'" per_page="24"]']);
$p=new WC_Product_Simple();$p->set_name('Release product 0');$p->set_status('publish');$p->set_regular_price('100');$p->set_stock_status('instock');$p->save();$pid=$p->get_id();(new Exin\BlackFriday\Application\ProductSelectionService($r))->add($cid,$pid,'0');
$template=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.$wpdb->prefix.'exin_bf_items WHERE campaign_id=%d AND product_id=%d',$cid,$pid),ARRAY_A);unset($template['id']);$first=$pid;$last=$pid;
$wpdb->query('START TRANSACTION');
for($n=1;$n<10000;$n++){
 $wpdb->insert($wpdb->posts,['post_title'=>'Release product '.$n,'post_name'=>'release-product-'.$n,'post_type'=>'product','post_status'=>'publish','post_author'=>1,'post_date'=>gmdate('Y-m-d H:i:s'),'post_date_gmt'=>gmdate('Y-m-d H:i:s')]);$last=(int)$wpdb->insert_id;
 $wpdb->query($wpdb->prepare("INSERT INTO {$wpdb->postmeta} (post_id,meta_key,meta_value) VALUES (%d,'_price','100'),(%d,'_regular_price','100'),(%d,'_stock_status','instock'),(%d,'_manage_stock','no'),(%d,'_sku',%s)",$last,$last,$last,$last,$last,'RELEASE-'.$n));
 $wpdb->insert($wpdb->wc_product_meta_lookup,['product_id'=>$last,'sku'=>'RELEASE-'.$n,'min_price'=>100,'max_price'=>100,'stock_status'=>'instock','virtual'=>0,'downloadable'=>0,'onsale'=>0,'tax_status'=>'taxable','tax_class'=>'']);
 $row=$template;$row['product_id']=$last;$row['floor_price']=$n%10===0?'0.00':'60.00';$row['schedule']=wp_json_encode(Exin\BlackFriday\Domain\DiscountPlan::schedule('100.00',$row['floor_price'],$r->get($cid)));if($n<1750)$wpdb->insert($wpdb->prefix.'exin_bf_items',$row);
}
$term=get_term_by('slug','simple','product_type');$wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$wpdb->term_relationships} (object_id,term_taxonomy_id,term_order) SELECT ID,%d,0 FROM {$wpdb->posts} WHERE post_type='product'",$term->term_taxonomy_id));
$wpdb->query('COMMIT');wp_update_term_count_now([$term->term_taxonomy_id],'product_type');WC_Cache_Helper::get_transient_version('product',true);$parent=new WC_Product_Variable();$parent->set_name('v141 variable');$parent->set_status('publish');$parent->save();
$selection=new Exin\BlackFriday\Application\ProductSelectionService($r);
foreach(['0','60']as$floor){$v=new WC_Product_Variation();$v->set_parent_id($parent->get_id());$v->set_regular_price('100');$v->set_status('publish');$v->save();$selection->add($cid,$v->get_id(),$floor);}
WC_Product_Variable::sync($parent->get_id());
$cards=$wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE post_type='product' ORDER BY ID ASC LIMIT 62");$cards=array_merge($cards,$wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE post_type='product' ORDER BY ID ASC LIMIT 62 OFFSET 2000"));$cards[]=$parent->get_id();$content='';foreach(array_chunk($cards,16)as$ids)$content.='[products ids="'.implode(',',$ids).'" limit="16" columns="4"]';wp_update_post(['ID'=>$home,'post_content'=>$content]);
$r->save($cid,['status'=>'active']);
for($n=0;$n<10;$n++){$order=wc_create_order();$order->add_product(wc_get_product($first+1),1);$order->set_billing_email('release@example.invalid');$order->set_payment_method('bacs');$order->calculate_totals();$order->update_status('processing');}
$fixture=['campaign_id'=>$cid,'first_product'=>$first,'last_product'=>$last,'products'=>10001,'positive_floors'=>1576,'missing_floors'=>176,'selected'=>1752,'cards'=>125,'widgets'=>8,'festival_page'=>$festival,'home_page'=>$home,'shop_page'=>wc_get_page_id('shop'),'cart_page'=>wc_get_page_id('cart'),'checkout_page'=>wc_get_page_id('checkout')];
update_option('permalink_structure','/%postname%/');flush_rewrite_rules(false);update_option('exin_bf_last_tick',gmdate('Y-m-d H:i:s'),false);
update_option('exin_v141_fixture',$fixture,false);echo wp_json_encode($fixture).PHP_EOL;
