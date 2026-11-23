<?php
/** Child for the isolated concurrent floor-write test. */
if (!defined('WP_CLI') || !WP_CLI || DB_NAME !== 'exin_test' || wp_get_environment_type() !== 'local') throw new RuntimeException('Isolated local DB required');
[$id,$pid,$expected,$floor,$barrier]=json_decode(getenv('EXIN_FLOOR_RACE'),true);
$deadline=microtime(true)+10;
while(!file_exists($barrier)&&microtime(true)<$deadline)usleep(10000);
$s=new Exin\BlackFriday\Application\ProductSelectionService(new Exin\BlackFriday\Infrastructure\CampaignRepository());
for($attempt=0;$attempt<40;$attempt++){
 try{$s->update_floor($id,$pid,$floor,['expected_floor_price'=>$expected]);echo "FLOOR_RACE saved\n";return;}
 catch(Throwable $e){if($e->getMessage()==='lock_busy'){usleep(50000);continue;}echo 'FLOOR_RACE '.$e->getMessage().PHP_EOL;return;}
}
throw new RuntimeException('Race lock retry exhausted');
