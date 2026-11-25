<?php
if(DB_NAME!=='exin_test'||wp_get_environment_type()!=='local')throw new RuntimeException('local only');$r=new Exin\BlackFriday\Infrastructure\CampaignRepository();(new Exin\BlackFriday\Application\Scheduler($r,new Exin\BlackFriday\Application\PriceProcessor($r,new Exin\BlackFriday\Infrastructure\Logger()),new Exin\BlackFriday\Infrastructure\Logger()))->recover();
