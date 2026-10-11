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
 $edited=K::update($t,$u,$k['id'],['name'=>'edited','daily_limit'=>15,'scopes'=>['assets']]);check($edited['name']==='edited'&&(float)$edited['daily_limit']===15.&&!isset($edited['key_hash']),'Key metadata edit or disclosure wrong');
 check(S::table('key')->where('id',$k['id'])->value('key_hash')===$db['key_hash'],'Editing changed credentials');
 try{K::update($t,$u+1,$k['id'],['name'=>'other']);throw new RuntimeException('Cross-user key edit');}catch(app\common\service\open_platform\OpenException $e){check($e->httpStatus===404,'Wrong owner rejection');}
 $denied=false;try{app\common\service\open_platform\OpenMarketBilling::quote($t,['kind'=>'market','billable'=>false,'product_id'=>1,'role'=>'resource'],[]);}catch(app\common\service\open_platform\OpenException $e){$denied=$e->httpStatus===403;}check($denied,'Free market operation bypassed allowlist');
 $rotated=K::rotate($t,$u,$k['id']);check($rotated['key']!==$k['key'],'Rotation reused key');
 check((int)S::table('key')->where('id',$k['id'])->value('status')===-1,'Old key not revoked');
 try{K::revoke($t,$u,$k['id'],1);throw new RuntimeException('Revoked key reactivated');}catch(app\common\service\open_platform\OpenException $e){check($e->httpStatus===404,'Wrong revocation error');}
 $cipher=V::seal('private replay body');check(V::open($cipher)==='private replay body','Replay encryption');
 $bytes=base64_decode($cipher);$bytes[strlen($bytes)-1]=chr(ord($bytes[strlen($bytes)-1])^1);
 $rejected=false;try{V::open(base64_encode($bytes));}catch(RuntimeException $e){$rejected=true;}check($rejected,'Accepted tampered encryption');
 $limits=S::defaults();$limits['rpm']=2;S::table('setting')->where('tenant_id',$t)->update(['config'=>S::encode($limits)]);$rateKey=S::table('key')->where('id',$rotated['id'])->find();app\common\service\open_platform\OpenRateLimiter::admit($rateKey);app\common\service\open_platform\OpenRateLimiter::admit($rateKey);$blocked=false;try{app\common\service\open_platform\OpenRateLimiter::admit($rateKey);}catch(app\common\service\open_platform\OpenException $e){$blocked=$e->httpStatus===429;}check($blocked,'Replay traffic bypassed durable rate limit');check((int)S::table('rate')->where(['tenant_id'=>$t,'key_id'=>0])->value('calls')===2,'Tenant aggregate counter wrong');
 $uri='uploads/open-fixture-'.bin2hex(random_bytes(5)).'.png';$nativeFile=app\common\model\file\TenantFile::create(['tenant_id'=>$t,'source_id'=>$u,'source'=>app\common\enum\FileEnum::SOURCE_USER,'type'=>10,'uri'=>$uri,'name'=>'fixture.png','storage_scope'=>'tenant','storage_engine'=>'local','storage_domain'=>'','create_time'=>time()]);$material=app\common\service\open_platform\OpenMaterials::resolve($t,$u,['reference_images'=>[$uri]]);check($material['reference_images'][0]===$uri,'Own local material was rejected');$denied=false;try{app\common\service\open_platform\OpenMaterials::resolve($t,$u+1,['reference_images'=>[$uri]]);}catch(app\common\service\open_platform\OpenException $e){$denied=$e->httpStatus===404;}check($denied,'Cross-user local material accepted');
 echo "PASS transactional key isolation, rotation, defaults and authenticated replay encryption\n";
}finally{Db::rollback();}
