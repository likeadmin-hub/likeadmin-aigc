<?php
require dirname(__DIR__,2).'/vendor/autoload.php';
(new think\App())->initialize();
use think\facade\Db;
use app\common\service\open_platform\OpenStore as S;
use app\common\service\open_platform\OpenKeyService as K;
use app\common\service\open_platform\OpenVault as V;
$c=config('database.connections.mysql');if($c['hostname']!=='127.0.0.1'||$c['database']!=='z_cn')throw new RuntimeException('Local DB required');
function check($ok,$why){if(!$ok)throw new RuntimeException($why);}
Db::startTrans();
try{
 $t=987654321;$u=123456789;
 check(S::settings($t)['enabled']===false,'New tenants must default disabled');
 S::table('setting')->insert(['tenant_id'=>$t,'enabled'=>1,'config'=>S::encode(S::defaults())]+S::timestamp());
 $k=K::create($t,$u,['name'=>'fixture','daily_limit'=>20,'ip_allowlist'=>['127.0.0.1/32']]);
 check(!isset($k['key_hash'])&&strlen($k['key'])>64,'Key disclosure policy');
 $db=S::table('key')->where('id',$k['id'])->find();check($db['key_hash']===hash('sha256',$k['key']),'Key digest mismatch');
 check(strpos(S::encode(K::lists($t,$u)),$k['key'])===false,'Key leaked by list');
 check(K::lists($t,$u+1)===[],'Cross-user key leaked');
 $rotated=K::rotate($t,$u,$k['id']);check($rotated['key']!==$k['key'],'Rotation reused key');
 check((int)S::table('key')->where('id',$k['id'])->value('status')===-1,'Old key not revoked');
 try{K::revoke($t,$u,$k['id'],1);throw new RuntimeException('Revoked key reactivated');}catch(app\common\service\open_platform\OpenException $e){check($e->httpStatus===404,'Wrong revocation error');}
 $cipher=V::seal('private replay body');check(V::open($cipher)==='private replay body','Replay encryption');
 $bytes=base64_decode($cipher);$bytes[strlen($bytes)-1]=chr(ord($bytes[strlen($bytes)-1])^1);
 $rejected=false;try{V::open(base64_encode($bytes));}catch(RuntimeException $e){$rejected=true;}check($rejected,'Accepted tampered encryption');
 echo "PASS transactional key isolation, rotation, defaults and authenticated replay encryption\n";
}finally{Db::rollback();}
