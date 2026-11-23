<?php
/** Independent writer for the isolated approval race. */
if(!defined('WP_CLI')||!WP_CLI||DB_NAME!=='exin_test'||wp_get_environment_type()!=='local')throw new RuntimeException('Isolated DB required');
[$id,$pid,$token,$user,$barrier]=json_decode(getenv('EXIN_RECOVERY_RACE'),true);wp_set_current_user($user);$deadline=microtime(true)+10;while(!file_exists($barrier)&&microtime(true)<$deadline)usleep(10000);
$service=new Exin\BlackFriday\Application\ConflictRecovery(new Exin\BlackFriday\Infrastructure\CampaignRepository());
for($n=0;$n<40;$n++){try{$result=$service->approve($id,$pid,$token);echo 'RECOVERY_RACE '.$result['pricing'].PHP_EOL;return;}catch(Throwable$e){if($e->getMessage()==='lock_busy'){usleep(50000);continue;}throw$e;}}
throw new RuntimeException('Race lock retry exhausted');
