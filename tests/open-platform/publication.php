<?php
require dirname(__DIR__,2).'/vendor/autoload.php';(new think\App())->initialize();
use think\facade\Db;
use app\common\service\open_platform\OpenStore as S;
use app\common\service\open_platform\OpenContracts as C;
use app\common\service\open_platform\OpenDocuments as D;
use app\common\service\open_platform\OpenVerification as V;
function check($ok,$why){if(!$ok)throw new RuntimeException($why);}
function rejected(callable $fn,string $type){try{$fn();}catch(app\common\service\open_platform\OpenException $e){check($e->errorType===$type,'Wrong publication rejection: '.$e->errorType);return;}throw new RuntimeException('Unverified publication accepted');}
$db=config('database.connections.mysql');check($db['hostname']==='127.0.0.1'&&$db['database']==='z_cn','Local rollback fixtures only');
Db::startTrans();try{
 $code='publish_fixture_'.bin2hex(random_bytes(5));
 $draft=C::save(['code'=>$code,'kind'=>'tool','method'=>'GET','path'=>'/api/v1/tools/aigc_image/capabilities','config'=>['role'=>'resource','input'=>['type'=>'object','properties'=>['limit'=>['type'=>'integer']]],'example'=>['limit'=>1],'responses'=>['200'=>['description'=>'OK','content'=>['application/json'=>['schema'=>['type'=>'object','required'=>['data'],'properties'=>['data'=>['type'=>'object']]]]]]],'verification'=>['evidence'=>'administrator says passed','verified_at'=>time()]]]);
 $doc=D::save(0,['slug'=>$code,'title'=>'Fixture','content'=>'Fixture published integration flow','contract_code'=>$code]);D::publish(0,$doc['id']);
 rejected(fn()=>C::publish($draft['id']),'unverified_contract');
 $rid='req_'.bin2hex(random_bytes(16));$row=['request_id'=>$rid,'tenant_id'=>1,'user_id'=>1,'key_id'=>0,'kind'=>'tool','app_code'=>'aigc_image','method'=>'GET','path'=>$draft['path'],'contract_id'=>$draft['id'],'status'=>'success','billing_status'=>'none','active'=>0,'budget_user'=>0,'budget_tenant'=>0,'actual_user'=>0,'actual_tenant'=>0,'snapshot'=>S::encode(['contract_digest'=>V::digest($draft)]),'consumption_ids'=>'[]','payload_hash'=>app\common\service\open_platform\OpenFiles::fingerprint(['limit'=>1]),'response_blob'=>app\common\service\open_platform\OpenVault::seal('{"data":{}}'),'response_code'=>200,'response_type'=>'application/json']+S::timestamp();$row['id']=S::table('request')->insertGetId($row);
 $input=$draft;$input['config']['verification']=['request_id'=>$rid];$draft=C::save($input);
 $changed=$draft;$changed['config']['example']['limit']=2;
 rejected(fn()=>V::assertReceipt($changed),'verification_mismatch');
 S::table('request')->where('id',$row['id'])->update(['snapshot'=>S::encode(['contract_digest'=>V::digest($changed)])]);rejected(fn()=>V::assertReceipt($changed),'verification_example_mismatch');
 S::table('request')->where('id',$row['id'])->update(['snapshot'=>S::encode(['contract_digest'=>V::digest($draft)]),'active'=>1,'billing_status'=>'pending_usage']);rejected(fn()=>C::publish($draft['id']),'unverified_contract');
 S::table('request')->where('id',$row['id'])->update(['active'=>0,'billing_status'=>'none']);$published=C::publish($draft['id']);
 check($published['status']==='published'&&$published['config']['verification']['request_id']===$rid,'Verified receipt not bound to publication');
 check($published['config']['verification']['contract_digest']===V::digest($published),'Verification metadata changed executable fingerprint');
 S::table('request')->where('id',$row['id'])->update(['response_blob'=>app\common\service\open_platform\OpenVault::seal('{"data":[]}')]);rejected(fn()=>V::assertReceipt($published),'invalid_request');
 $object=$published;$object['config']['example']=['metadata'=>(object)[]];$object['config']['input']=['type'=>'object','properties'=>['metadata'=>['type'=>'object']]];
 $restored=C::decodeConfig(S::encode($object['config']));check($restored['example']['metadata'] instanceof \stdClass,'Stored contract lost empty object example');check(V::digest($object)===V::digest(array_replace($object,['config'=>S::encode($object['config'])])),'Contract digest changed across storage');

 echo "PASS publication rejects claimed evidence, changed contracts/examples and unresolved bills; exact gateway receipt binds published version\n";
}finally{Db::rollback();}
