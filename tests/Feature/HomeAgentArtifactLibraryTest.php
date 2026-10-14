<?php
// Local rollback fixtures only; no providers, billing or physical files.
require dirname(__DIR__,2).'/vendor/autoload.php';(new think\App())->initialize();
set_exception_handler(static function(Throwable $e){fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);});
use think\facade\Db;
use app\common\service\app\aigc_llm\agent\{AgentStore as S,AgentArtifactService as A};
use app\common\service\open_platform\OpenStore;
function check($ok,$message){if(!$ok)throw new RuntimeException($message);}
$db=config('database.connections.mysql');check(PHP_SAPI==='cli'&&$db['hostname']==='127.0.0.1'&&in_array($db['database'],['x_cn','z_cn'],true),'Local rollback only');
Db::startTrans();try{
 $tenant=1999999905;$user=$tenant;$file='of_'.bin2hex(random_bytes(16));$storage=['storage_scope'=>'platform','storage_engine'=>'local','storage_domain'=>''];
 OpenStore::table('file')->insert(['tenant_id'=>$tenant,'user_id'=>$user,'file_id'=>$file,'uri'=>'uploads/library-fixture.png','name'=>'fixture','mime'=>'image/png','size'=>1]+$storage+OpenStore::timestamp());
 for($i=0;$i<103;$i++)S::table('artifact')->insert(['tenant_id'=>$tenant,'user_id'=>$user,'session_id'=>$tenant+$i%3,'run_id'=>$tenant+$i,'step_id'=>0,'identity_hash'=>hash('sha256',$file.$i),'title'=>'fixture '.$i,'kind'=>$i%2?'video':'image','data'=>S::encode(['file_id'=>$file]),'favorite'=>0,'create_time'=>time()]+$storage);
 $first=A::lists($tenant,$user,0,0,true);check(count($first)===100,'Library page not bounded');
 $next=A::lists($tenant,$user,0,(int)end($first)['id'],true);check(count($next)===3,'Older works missing');check(!array_intersect(array_column($first,'id'),array_column($next,'id')),'Pagination duplicated works');
 check(count(A::lists($tenant,$user,$tenant))===35,'Session-scoped inline history changed');check(A::lists($tenant,$user,0)===[],'Legacy empty-session behavior changed');
 $videos=A::lists($tenant,$user,0,0,true,'video');check(count($videos)===51&&array_unique(array_column($videos,'kind'))===['video'],'Global kind filter incomplete');
 check(A::lists($tenant+1,$user,0,0,true)===[]&&A::lists($tenant,$user+1,0,0,true)===[],'Library crossed ownership boundary');
 echo "Home Agent artifact library passed: all conversations, legacy session scope, pagination, type filter and ownership; rolled back\n";
}finally{Db::rollback();}
