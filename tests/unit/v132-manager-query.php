<?php
/** Short query-construction check; fake DB captures SQL, no database writes. */
define('EXIN_BF_TESTING',true);
define('ARRAY_A','ARRAY_A');
require dirname(__DIR__,2).'/autoload.php';
$wpdb=new class {
 public string $prefix='wp_';public string $posts='wp_posts';public string $postmeta='wp_postmeta';public array $queries=[];
 public function prepare($sql,...$args){$i=0;return preg_replace_callback('/%[isd]/',static function($m)use(&$i,$args){$v=$args[$i++];return $m[0]==='%d'?(string)(int)$v:($m[0]==='%i'?'`'.$v.'`':"'".str_replace("'","''",$v)."'");},$sql);}
 public function esc_like($v){return addcslashes($v,'_%\\');}
 public function get_results($sql,$format){$this->queries[]=$sql;return [];}
 public function get_var($sql){$this->queries[]=$sql;return 0;}
};
$repo=new Exin\BlackFriday\Infrastructure\CampaignRepository();$checks=0;
$check=static function($ok)use(&$checks){++$checks;if(!$ok)throw new RuntimeException('Query check failed: '.$checks);};
foreach(['all','instock','outofstock']as$stock){
 $wpdb->queries=[];$repo->manager_items(7,2,'SKU-42',$stock,'included');[$page,$count]=$wpdb->queries;
 $where=substr($page,strpos($page,' FROM '));$where=substr($where,0,strpos($where,' ORDER BY'));
 $check($count==='SELECT COUNT(*)'.$where);
 $check(str_contains($page,'LIMIT 25 OFFSET 25'));
 $check(str_contains($where,"i.campaign_id=7")&&str_contains($where,"i.status='included'")&&str_contains($where,'%SKU-42%'));
 $check($stock==='all'?!str_contains($where,'_stock_status'):str_contains($where,'p.post_parent')&&str_contains($where,"qty.meta_key='_stock'"));
 if($stock==='outofstock')$check(str_contains($where,' AND NOT (EXISTS'));
}
echo "PASS $checks manager query checks: combined filters, count/page parity, shared variation stock and read-only calls\n";
