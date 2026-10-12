<?php
require dirname(__DIR__,2).'/vendor/autoload.php';(new think\App())->initialize();
use think\facade\Db;
use app\common\service\open_platform\OpenStore as S;
use app\common\service\open_platform\OpenContracts as C;
use app\common\service\open_platform\OpenContractPreview as P;
use app\common\service\open_platform\OpenVault as V;
function check($ok,$why){if(!$ok)throw new RuntimeException($why);}
function reject(callable $fn){try{$fn();}catch(app\common\service\open_platform\OpenException $e){check($e->httpStatus===403,'Wrong draft verification rejection');return;}throw new RuntimeException('Unbound preview accepted');}
$c=config('database.connections.mysql');check($c['hostname']==='127.0.0.1'&&$c['database']==='z_cn','Local rollback fixtures only');
Db::startTrans();try{
 $code='preview_fixture_'.bin2hex(random_bytes(5));$draft=C::save(['code'=>$code,'kind'=>'tool','method'=>'GET','path'=>'/api/v1/tools/aigc_image/capabilities','config'=>['role'=>'resource','input'=>['type'=>'object','properties'=>['limit'=>['type'=>'integer']]],'example'=>['limit'=>1],'responses'=>['200'=>['description'=>'OK']]]]);
 $key=['id'=>0,'tenant_id'=>987654327,'user_id'=>123456787,'name'=>'preview fixture','status'=>1,'key_hash'=>hash('sha256',$code),'prefix'=>'fixture','scopes'=>'[]','ip_allowlist'=>'[]']+S::timestamp();$key['id']=S::table('key')->insertGetId(array_diff_key($key,['id'=>true]));$ticket=P::issue($draft['id'],$key['id']);$path=$ticket['path'];
 $resolve=fn($token,$k,$params,$idem)=>P::resolve($token,$k,'GET',$path,$params,$idem);
 $op=$resolve($ticket['preview_token'],$key,['limit'=>'1'],$ticket['idempotency_key']);check($op['contract_id']===$draft['id']&&$op['controller']==='Config'&&$op['action']==='detail','Draft did not resolve fixed executable registry');
 reject(fn()=>$resolve($ticket['preview_token'],array_replace($key,['user_id'=>$key['user_id']+1]),['limit'=>1],$ticket['idempotency_key']));
 reject(fn()=>$resolve($ticket['preview_token'],$key,['limit'=>2],$ticket['idempotency_key']));reject(fn()=>$resolve($ticket['preview_token'],$key,['limit'=>1],'different-key'));
 $claims=S::json(V::open($ticket['preview_token']));$claims['expires_at']=time()-1;reject(fn()=>$resolve(V::seal(S::encode($claims)),$key,['limit'=>1],$ticket['idempotency_key']));
 $claims['expires_at']=time()+10;$claims['contract_digest']='changed';reject(fn()=>$resolve(V::seal(S::encode($claims)),$key,['limit'=>1],$ticket['idempotency_key']));
 reject(fn()=>$resolve('invalid-proof',$key,['limit'=>1],$ticket['idempotency_key']));
 check(C::detail($draft['id'])['status']==='draft','Issuing verification prematurely published draft');
 $file=tempnam(sys_get_temp_dir(),'open-preview-upload-');file_put_contents($file,'reviewed upload');
 try{
  $upload=new \think\file\UploadedFile($file,'asset.txt','text/plain',UPLOAD_ERR_OK,true);
  $metadata=['sha256'=>hash_file('sha256',$file),'name'=>$upload->getOriginalName(),'size'=>$upload->getSize(),'mime'=>$upload->getMime()];
  $draft=C::save(['code'=>$code.'.upload','kind'=>'market','method'=>'POST','path'=>'/api/v1/upload','product_id'=>2,'config'=>['executor'=>'multipart','billable'=>false,'role'=>'resource','input'=>['type'=>'object','properties'=>['file'=>['type'=>'string','format'=>'binary'],'quantity'=>['type'=>'integer'],'options'=>['type'=>'object','properties'=>['speed'=>['type'=>'number']]]],'required'=>['file','quantity']],'example'=>['file'=>'asset.txt','quantity'=>1,'options'=>['speed'=>1.5]],'example_files'=>['file'=>$metadata],'responses'=>['200'=>['description'=>'OK']]]]);
  $ticket=P::issue($draft['id'],$key['id']);$op=P::resolve($ticket['preview_token'],$key,'POST','/api/v1/upload',['quantity'=>'1','options'=>'{"speed":1.5}'],$ticket['idempotency_key'],['file'=>$upload]);
  check($op['executor']==='multipart'&&$ticket['content_type']==='multipart/form-data','Multipart preview changed executor');
 }finally{@unlink($file);}
 echo "PASS exact draft example, typed GET, multipart content and typed fields, fixed executor, account/idempotency/expiry/digest binding and no publication\n";
}finally{Db::rollback();}
