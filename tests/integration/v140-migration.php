<?php
/** Compare populated v1.3.2 financial records after the real additive migration. */
use Exin\BlackFriday\Application\PriceState;
use Exin\BlackFriday\Infrastructure\Migration;
if(!defined('WP_CLI')||!WP_CLI||'exin_test'!==DB_NAME||'local'!==wp_get_environment_type())throw new RuntimeException('Isolated fixture only');
global $wpdb;
$path=getenv('EXIN140_BASELINE_PATH')?:dirname(__DIR__,2).'/work/v140/v132-populated.json';
$before=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
if($before['schema']!=='6')throw new RuntimeException('Expected actual v1.3.2 schema');
$checks=0;
foreach(array(1,2)as$run){
 Migration::run();
 foreach($before['tables']as$table=>$rows){$after=$wpdb->get_results('SELECT * FROM '.$wpdb->prefix.'exin_bf_'.$table.' ORDER BY 1,2',ARRAY_A);if($rows!==$after)throw new RuntimeException('RELEASE BLOCKED: existing '.$table.' changed');$checks++;}
 foreach($before['prices']as$id=>$state){if($state!==PriceState::capture(wc_get_product($id)))throw new RuntimeException('RELEASE BLOCKED: product repriced');$checks++;}
}
if(get_option('exin_bf_schema_version')!==\Exin\BlackFriday\Infrastructure\Migration::VERSION)throw new RuntimeException('Missing additive schema');
echo 'EXIN_V140_MIGRATION '.wp_json_encode(array('passed'=>$checks,'failed'=>0,'baseline'=>'actual v1.3.2 ZIP, schema 6','schema'=>\Exin\BlackFriday\Infrastructure\Migration::VERSION,'campaign_2'=>'synthetic campaign 2 unchanged','production_accessed'=>false)).PHP_EOL;
