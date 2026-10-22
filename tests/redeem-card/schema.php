<?php
$root=dirname(__DIR__,2);$file='20261021_redeem_card.sql';$sql=file_get_contents($root.'/upgrade/'.$file);
if($sql!==file_get_contents($root.'/public/upgrade/'.$file))throw new RuntimeException('Public/private upgrade SQL differ');
preg_match_all('/CREATE TABLE IF NOT EXISTS `la_redeem_card[^`]*` \(.*?\) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;/s',$sql,$matches);
if(count($matches[0])!==4)throw new RuntimeException('Four shared tables required');
foreach(['public/install/db/like.sql','app/platformapi/db/tenantData.sql'] as $path){$content=file_get_contents($root.'/'.$path);foreach($matches[0] as $ddl)if(!str_contains($content,$ddl))throw new RuntimeException('Schema drift: '.$path);}
foreach(['generate','batches','lists','records','disable','options'] as $action){foreach(['upgrade/'.$file,'public/install/db/like.sql','app/platformapi/db/tenantData.sql'] as $path)if(!str_contains(file_get_contents($root.'/'.$path),'finance.redeem_card/'.$action))throw new RuntimeException('Menu missing: '.$path.' '.$action);}
if(!str_contains(file_get_contents($root.'/app/tenantapi/logic/auth/MenuLogic.php'),'$hasRedeemCard'))throw new RuntimeException('Split menu sync trigger missing');
echo "PASS redeem-card schema: 4 tables identical across install/new tenant/both upgrades; 6 permissions and split sync present\n";
