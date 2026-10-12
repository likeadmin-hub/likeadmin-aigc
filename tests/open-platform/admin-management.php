<?php
require dirname(__DIR__,2).'/vendor/autoload.php';(new think\App())->initialize();
use think\facade\Db;
use app\common\service\open_platform\{OpenManagement as M,OpenStore as S,OpenException};
function verifyAdmin($ok,$message){if(!$ok)throw new RuntimeException($message);}
$c=config('database.connections.mysql');verifyAdmin(PHP_SAPI==='cli'&&$c['hostname']==='127.0.0.1'&&$c['database']==='z_cn','Local rollback test only');
$tag='admin_'.bin2hex(random_bytes(5));$id=1999999996;
Db::startTrans();try{
 verifyAdmin(!Db::name('tenant')->where('id',$id)->find(),'Fixture tenant collision');
 Db::name('tenant')->insert(['id'=>$id,'sn'=>$tag,'name'=>$tag,'create_time'=>time(),'update_time'=>time()]);
 M::persistSettings($id,false,['rpm'=>9,'concurrency'=>3,'user_concurrency'=>4,'tenant_concurrency'=>8,'tenant_enabled'=>false,'models'=>['188'],'apps'=>[],'pages'=>[['id'=>'docs','visible'=>true]],'features'=>['keys'=>false]]);
 $config=S::table('setting')->where('tenant_id',$id)->value('config');
 $on=M::setTenantAuthorization($id,true);verifyAdmin($on['authorized']&&!$on['enabled']&&!$on['tenant_enabled'],'Direct grant bypassed tenant pause');
 verifyAdmin(S::table('setting')->where('tenant_id',$id)->value('config')===$config,'Direct switch changed quotas or selected capabilities');
 M::setTenantAuthorization($id,false);verifyAdmin(!S::settings($id)['authorized'],'Direct disable failed');
 verifyAdmin(S::table('setting')->where('tenant_id',$id)->value('config')===$config,'Direct disable changed config');
 foreach([0,-1,1999999997] as $missing){try{M::setTenantAuthorization($missing,true);throw new RuntimeException('Invalid tenant allowed');}catch(OpenException $e){verifyAdmin($e->httpStatus===404,'Wrong invalid tenant status');}}
 foreach(['false','true',null,[],2] as $input){try{M::authorizationInput($input);throw new RuntimeException('Invalid toggle accepted');}catch(OpenException){}}
 verifyAdmin(M::authorizationInput('0')===false&&M::authorizationInput(true)===true,'Boolean parsing changed intent');
 $created=[];
 for($i=0;$i<23;$i++)$created[]=S::table('contract')->insertGetId(['code'=>$tag.'.'.$i,'method'=>'POST','path'=>'/api/v1/'.$tag.'/'.$i,'version'=>1,'status'=>$i===0?'published':'draft','kind'=>'market','product_id'=>0,'config'=>S::encode(['title'=>'可读名称 '.$tag.' '.$i,'input'=>['type'=>'object','properties'=>[]],'billing'=>[]])]+S::timestamp());
 $page=M::adminContracts(0,true,['keyword'=>$tag,'page_size'=>10,'page_no'=>1]);$second=M::adminContracts(0,true,['keyword'=>$tag,'page_size'=>10,'page_no'=>2]);
 verifyAdmin($page['count']===23&&count($page['lists'])===10&&count($second['lists'])===10&&!array_intersect(array_column($page['lists'],'id'),array_column($second['lists'],'id')),'Contract pagination duplicate/missing');
 verifyAdmin(M::adminContracts(0,true,['keyword'=>$tag,'status'=>'published'])['count']===1,'Contract status search failed');
 verifyAdmin(M::adminContracts(0,true,['keyword'=>'可读名称 '.$tag])['count']===23,'Readable title search failed');
 $last=M::adminContracts(0,true,['keyword'=>$tag,'page_size'=>10,'page_no'=>100]);verifyAdmin($last['page_no']===3&&count($last['lists'])===3,'Out-of-range contract page not clamped');
 $tenant=M::adminContracts($id,false,['keyword'=>$tag]);verifyAdmin($tenant['count']===1&&$tenant['lists'][0]['status']==='published','Tenant saw platform drafts');
 for($i=0;$i<22;$i++)S::table('document')->insert(['tenant_id'=>$id,'slug'=>$tag.'_'.$i,'title'=>'补充指南 '.$tag,'category'=>'指南','content'=>'safe','contract_code'=>'','version'=>1,'status'=>'draft','sort'=>$i]+S::timestamp());
 $docs=M::adminDocuments($id,['keyword'=>$tag,'page_no'=>2,'page_size'=>20]);verifyAdmin($docs['count']===22&&count($docs['lists'])===2,'Document pagination failed');
 verifyAdmin(M::adminDocuments(1,['keyword'=>$tag])['count']===0,'Foreign tenant docs leaked');
 // Existing internal recovery detail must still receive its durable snapshot.
 $template=['method'=>'POST','path'=>'/api/v1/admin_fixture','app_code'=>'aigc_image','kind'=>'tool','status'=>'success','billing_status'=>'none','response_blob'=>'','payload_hash'=>hash('sha256',$tag),'create_time'=>time(),'update_time'=>time()];$template['request_id']='req_'.$tag;$template['tenant_id']=$id;$template['user_id']=1;$template['key_id']=1;$template['idempotency_hash']=null;$template['snapshot']=S::encode(['private'=>'SECRET_TOKEN','point_events'=>[]]);$template['consumption_ids']='[]';$template['error_message']='SECRET_TOKEN';$template['error_code']='fixture';$template['tool_task_id']='777';$template['upstream_task_id']='remote777';S::table('request')->insert($template);
 verifyAdmin(M::requests($id,null,['task_id'=>'777','path'=>$template['path'],'user_id'=>1])['count']===1,'Request link filters failed');
 verifyAdmin(isset(M::detail($id,null,$template['request_id'])['snapshot']['private']),'Internal recovery snapshot was removed');
 $admin=M::adminRequestDetail($id,$template['request_id'],true);verifyAdmin(!str_contains(S::encode($admin),'SECRET_TOKEN')&&isset($admin['trace']['nodes']),'Admin trace projection missing or leaked snapshot');
 try{M::adminRequestDetail(1,$template['request_id'],false);throw new RuntimeException('Foreign tenant request seen');}catch(OpenException $e){verifyAdmin($e->httpStatus===404,'Wrong foreign request status');}
 echo "PASS direct grant preserves config and tenant pause, strict input, contract/document pagination and search, scoped request detail\n";
}finally{Db::rollback();}
