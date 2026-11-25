<?php
$root=getenv('EXIN_NATIVE_WP');$repo=dirname(__DIR__,2);$variant=getenv('EXIN_RELEASE_VARIANT')?:'candidate';putenv('WORDPRESS_TABLE_PREFIX=exin_release_');
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if($path&&$path!=='/'&&is_file($root.$path)&&!str_ends_with($path,'.php'))return false;
define('WP_HOME','http://'.$_SERVER['HTTP_HOST']);define('WP_SITEURL',WP_HOME);define('SAVEQUERIES',true);
if($variant!=='candidate'){
 $GLOBALS['wp_filter']['option_active_plugins'][0][]=['function'=>static fn($plugins)=>array_values(array_filter($plugins,static fn($plugin)=>$plugin!=='exin-black-friday/exin-black-friday.php')),'accepted_args'=>1];
 if($variant==='base')$GLOBALS['wp_filter']['muplugins_loaded'][0][]=['function'=>static function()use($repo){require $repo.'/work/performance/base/exin-black-friday.php';},'accepted_args'=>0];
}
$started=hrtime(true);$usage=getrusage();$id=$_SERVER['HTTP_X_EXIN_AUDIT_ID']??'';
$audit_logger=static function()use($started,$usage,$id,$variant,$repo){global$wpdb;$queries=$wpdb->queries??[];$sql=array_column($queries,0);$plugin=array_values(array_filter($queries,static fn($q)=>str_contains($q[0],'exin_bf_')||str_contains($q[2]??'','Exin\\BlackFriday')));$r=getrusage();$cpu=($r['ru_utime.tv_sec']-$usage['ru_utime.tv_sec']+$r['ru_stime.tv_sec']-$usage['ru_stime.tv_sec'])*1000+($r['ru_utime.tv_usec']-$usage['ru_utime.tv_usec']+$r['ru_stime.tv_usec']-$usage['ru_stime.tv_usec'])/1000;$row=['id'=>$id,'variant'=>$variant,'plugin_version'=>defined('EXIN_BF_VERSION')?EXIN_BF_VERSION:null,'floor_guard_source'=>class_exists('Exin\\BlackFriday\\Application\\FloorGuard')?(new ReflectionClass('Exin\\BlackFriday\\Application\\FloorGuard'))->getFileName():null,'uri'=>$_SERVER['REQUEST_URI'],'status'=>http_response_code(),'server_ms'=>(hrtime(true)-$started)/1e6,'cpu_ms'=>$cpu,'queries'=>count($sql),'plugin_queries'=>count($plugin),'duplicates'=>count($sql)-count(array_unique($sql)),'writes'=>count(preg_grep('/^(INSERT|UPDATE|DELETE|REPLACE)/i',$sql)),'locks'=>count(preg_grep('/GET_LOCK/',$sql)),'peak_bytes'=>memory_get_peak_usage(true),'last_php_message'=>error_get_last()['message']??null];if($id)file_put_contents($repo.'/work/release-audit/http-requests.jsonl',json_encode($row)."\n",FILE_APPEND|LOCK_EX);};
$GLOBALS['wp_filter']['shutdown'][PHP_INT_MAX][]=['function'=>$audit_logger,'accepted_args'=>0];
if($path&&is_file($root.$path)){require $root.$path;return;}
if($path&&is_dir($root.$path)&&is_file(rtrim($root.$path,'/').'/index.php')){require rtrim($root.$path,'/').'/index.php';return;}
require $root.'/index.php';
