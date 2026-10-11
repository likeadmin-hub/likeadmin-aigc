<?php
/** Full installer and repeated upgrade validation in a disposable local schema. */
require dirname(__DIR__,2).'/vendor/autoload.php';(new think\App())->initialize();
use think\facade\Db;
use app\common\service\open_platform\OpenStore as S;
use app\common\service\open_platform\OpenDocuments as D;
function check($ok,$why){if(!$ok)throw new RuntimeException($why);}
$original=config('database');$mysql=$original['connections']['mysql'];check(PHP_SAPI==='cli'&&$mysql['hostname']==='127.0.0.1'&&$mysql['database']==='z_cn','Verified local database only');
$name='open_platform_fixture_'.bin2hex(random_bytes(6));
$pdo=new PDO('mysql:host='.$mysql['hostname'].';port='.($mysql['hostport']?:3306).';charset=utf8mb4',$mysql['username'],$mysql['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::MYSQL_ATTR_MULTI_STATEMENTS=>true]);
$created=false;$root=dirname(__DIR__,2);
try{
 $pdo->exec('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');$created=true;$pdo->exec('USE `'.$name.'`');
 $sql=file_get_contents($root.'/public/install/db/like.sql');check(!preg_match('/^\s*(?:USE\b|(?:DROP|CREATE)\s+DATABASE\b)/im',$sql),'Installer must not change schema selection');$pdo->exec($sql);
 $tables=$pdo->query("SHOW TABLES LIKE 'la_open_platform_%'")->fetchAll(PDO::FETCH_COLUMN);sort($tables);check(count($tables)===9,'Fresh installer is missing open-platform tables');
 $before=[];foreach($tables as $table)$before[$table]=$pdo->query('SHOW CREATE TABLE `'.$table.'`')->fetch(PDO::FETCH_NUM)[1];
 $migrations=glob($root.'/app/apps/open_platform/migrations/*.sql');sort($migrations);check(count($migrations)===4,'Migration baseline changed; update acceptance coverage');
 for($i=0;$i<2;$i++)foreach($migrations as $file)$pdo->exec(file_get_contents($file));
 foreach($tables as $table)check($pdo->query('SHOW CREATE TABLE `'.$table.'`')->fetch(PDO::FETCH_NUM)[1]===$before[$table],'Repeated migration changed installed schema');
 foreach(['quickstart','auth-limits','tasks-billing','assets-webhooks'] as $slug){$q=$pdo->prepare('SELECT content FROM la_open_platform_document WHERE tenant_id=0 AND slug=? AND status=?');$q->execute(['open-platform-'.$slug,'published']);$rows=$q->fetchAll(PDO::FETCH_COLUMN);check(count($rows)===1&&trim($rows[0])===trim(file_get_contents($root.'/app/apps/open_platform/docs/'.$slug.'.md')),'Guide seed changed or duplicated: '.$slug);}
 $test=$original;$test['default']='open_platform_fixture';$test['connections']['open_platform_fixture']=array_replace($mysql,['database'=>$name]);Db::setConfig($test);
 check(S::settings(123456)['enabled']===false,'New tenant unexpectedly enabled API calling');check(S::settings(123456)['apps']===[]&&S::settings(123456)['models']===[],'New tenant inherited callable abilities');
 check(count(D::publicLists(123456))===4,'New tenant did not inherit published integration guides');
 echo "PASS full fresh installer, 9 tables, repeated 4 migrations, exact guide seeds and closed new tenant\n";
}finally{
 Db::setConfig($original);
 if($created)$pdo->exec('DROP DATABASE `'.$name.'`');
}
