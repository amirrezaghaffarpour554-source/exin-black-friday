<?php
/** Verify the actual staging ZIP loads without changing existing synthetic finances. */
use Exin\BlackFriday\Application\PriceState;
if(!defined('WP_CLI')||!WP_CLI||DB_NAME!=='exin_test'||wp_get_environment_type()!=='local')throw new RuntimeException('Isolated DB only');
global$wpdb;$root=dirname(__DIR__,2);$file=$root.'/work/v140/package-before.json';$capture=[];
foreach(['campaigns','items','audit','turns','locks']as$table)$capture['tables'][$table]=$wpdb->get_results('SELECT * FROM '.$wpdb->prefix.'exin_bf_'.$table.' ORDER BY 1,2',ARRAY_A);
foreach($wpdb->get_col('SELECT DISTINCT product_id FROM '.$wpdb->prefix.'exin_bf_items')as$id){$p=wc_get_product($id);if($p)$capture['prices'][(int)$id]=PriceState::capture($p);}
if(getenv('EXIN140_PACKAGE_PHASE')==='capture'){file_put_contents($file,wp_json_encode($capture));echo 'CAPTURED isolated pre-ZIP financial state'.PHP_EOL;return;}
$before=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);
if($before!==$capture)throw new RuntimeException('RELEASE BLOCKED: ZIP load changed financial data');
$path=(new ReflectionClass(Exin\BlackFriday\Application\SmartAdaptive::class))->getFileName();
if(EXIN_BF_VERSION!=='1.4.0'||!str_contains($path,'/package/exin-black-friday/'))throw new RuntimeException('Actual ZIP class not loaded');
if(get_option('exin_bf_schema_version')!=='7')throw new RuntimeException('Schema mismatch');
echo 'EXIN_V140_INSTALLED '.wp_json_encode(['version'=>EXIN_BF_VERSION,'schema'=>'7','result'=>'passed','tables_preserved'=>count($capture['tables']),'product_price_snapshots_preserved'=>count($capture['prices']),'class_loaded_from_installed_zip'=>true]).PHP_EOL;
