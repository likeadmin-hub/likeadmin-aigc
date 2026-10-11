<?php
require dirname(__DIR__,2).'/vendor/autoload.php';(new think\App())->initialize();
use app\common\service\open_platform\OpenDirectory as D;use app\common\service\open_platform\OpenStore as S;use app\common\service\open_platform\OpenContracts as C;use think\facade\Db;
function verify($ok,$why){if(!$ok)throw new RuntimeException($why);}
foreach(['/api/v1/models','/api/v1/apps','/api/v1/tasks','/api/v1/user/balance','/api/v1/user/usage'] as $i=>$path){$contract=['code'=>'fixture.directory.'.$i,'kind'=>'market','method'=>'GET','path'=>$path,'product_id'=>0,'config'=>['executor'=>'directory','role'=>'resource','billable'=>false,'input'=>['type'=>'object'],'example'=>[],'responses'=>['200'=>['description'=>'Read operation']]]];verify(C::validate($contract)['path']===$path,'Read adapter contract rejected');}
$sku=['sku_key'=>'input_token','usage_unit'=>'token','usage_unit_size'=>1000000,'sale_status'=>1,'sale_points'=>80];$product=['upstream_model_code'=>'published-model','upstream_channel_code'=>'','skus'=>[$sku,$sku+['sku_key'=>'output_token']]];
$out=D::filter('/api/v1/models',[['id'=>'published-model','pricing'=>['fixed_points'=>999]],['id'=>'hidden-model','pricing'=>['fixed_points'=>999]]],[$product]);verify(count($out)===1&&$out[0]['pricing']['per_1k_input']===80.,'Model permission or retail price leak');
$app=['upstream_app_code'=>'source-app','upstream_api_code'=>'generate'];$out=D::filter('/api/v1/apps',[['code'=>'source-app','apis'=>[['code'=>'generate'],['code'=>'internal']]],['code'=>'other','apis'=>[['code'=>'generate']]]],[$app]);verify(count($out)===1&&count($out[0]['apis'])===1,'Application API filtering failed');
$c=config('database.connections.mysql');verify($c['hostname']==='127.0.0.1'&&$c['database']==='z_cn','Local DB required');
Db::startTrans();try{
 $t=987654325;$u=123456784;$key=['tenant_id'=>$t,'user_id'=>$u,'scopes'=>'[]'];
 $user=Db::name('user')->insertGetId(['tenant_id'=>$t,'sn'=>random_int(10000000,99999999),'account'=>'open-directory-'.bin2hex(random_bytes(5)),'nickname'=>'directory fixture','user_money'=>123.45,'delete_time'=>null,'create_time'=>time(),'update_time'=>time()]);$key['user_id']=$user;
 $wire=D::execute($key,'/api/v1/user/balance',[]);verify(S::json($wire['body'])['data']['available_points']===123.45,'Shared upstream wallet exposed');
 $base=['tenant_id'=>$t,'user_id'=>$user,'key_id'=>0,'method'=>'POST','path'=>'/api/v1/tasks','kind'=>'market','app_code'=>'','contract_id'=>0,'payload_hash'=>str_repeat('a',64),'status'=>'success','billing_status'=>'settled','active'=>0,'consumption_ids'=>'[]','snapshot'=>S::encode(['contract'=>['model'=>'published-model','task_type'=>'image']]),'response_blob'=>'','actual_user'=>4,'create_time'=>time(),'update_time'=>time()];
 foreach([['tenant_id'=>$t,'user_id'=>$user,'request_id'=>'req_'.bin2hex(random_bytes(16)),'upstream_task_id'=>'own-task'],['tenant_id'=>$t,'user_id'=>$user+1,'request_id'=>'req_'.bin2hex(random_bytes(16)),'upstream_task_id'=>'other-user'],['tenant_id'=>$t+1,'user_id'=>$user,'request_id'=>'req_'.bin2hex(random_bytes(16)),'upstream_task_id'=>'other-tenant']] as $owner)S::table('request')->insert($owner+$base);
 $wire=D::execute($key,'/api/v1/tasks',['type'=>'image']);$data=S::json($wire['body'])['data'];verify($data['total']===1&&$data['lists'][0]['task_id']==='own-task'&&(float)$data['lists'][0]['actual_points']===4.,'Cross-owner tasks or wrong local charge');
 $wire=D::execute($key,'/api/v1/user/usage',[]);$data=S::json($wire['body'])['data'];verify($data['total_calls']===1&&(float)$data['total_points']===4.,'Cross-owner usage exposed');
 echo "PASS fixed read contracts, filtered model/app catalog, own wallet, tasks and historical usage\n";
}finally{Db::rollback();}
