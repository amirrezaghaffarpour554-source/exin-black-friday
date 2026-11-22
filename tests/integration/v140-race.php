<?php
/** Invoked only by the isolated two-process pricing check. */
if(!defined('ABSPATH')&&PHP_SAPI==='cli'&&getenv('EXIN140_WP_ROOT')){define('DOING_CRON',true);require rtrim(getenv('EXIN140_WP_ROOT'),'/').'/wp-load.php';}
if(PHP_SAPI!=='cli'||DB_NAME!=='exin_test'||wp_get_environment_type()!=='local')throw new RuntimeException('Local only');
$gate=getenv('EXIN140_GATE');$limit=microtime(true)+10;
while(!file_exists($gate)){if(microtime(true)>$limit)throw new RuntimeException('Race barrier timeout');usleep(10000);}
$r=new Exin\BlackFriday\Infrastructure\CampaignRepository();$p=new Exin\BlackFriday\Application\PriceProcessor($r,new Exin\BlackFriday\Infrastructure\Logger());
for($attempt=0;$attempt<50;$attempt++){
 try{$result=$p->apply((int)getenv('EXIN140_CID'),(int)getenv('EXIN140_PID'),(int)getenv('EXIN140_VERSION'));echo 'RACE_RESULT:'.$result.PHP_EOL;return;}
 catch(RuntimeException $error){if($error->getMessage()!=='lock_busy')throw $error;usleep(100000);}
}
throw new RuntimeException('Contention did not recover');
