<?php
/** Rollback-only native fault injection; every supplier address is fenced to loopback. */
require dirname(__DIR__,2).'/vendor/autoload.php';(new think\App())->initialize();
use think\facade\Db;
use app\common\service\open_platform\{OpenStore as S,OpenManagement as M,OpenImagePricing as P,OpenCallContext as C,OpenRecovery as Recovery,OpenVault as V,OpenSubmission as N,OpenException};
use app\common\service\app\aigc_product_multi_angle\AigcProductMultiAngleService as F;
use app\common\service\power\MarketImageModelRuntimeService as Runtime;
use app\common\model\app\aigc_product_multi_angle\AigcProductMultiAngleTask as ParentTask;
use app\common\model\app\aigc_image\AigcImageTask as Image;
use app\common\model\ai\AiConsumptionLog as Consumption;
use app\common\model\file\TenantFile;
use app\common\enum\FileEnum;
function check($ok,$why){if(!$ok)throw new RuntimeException($why);}
$db=config('database.connections.mysql');check(PHP_SAPI==='cli'&&$db['hostname']==='127.0.0.1'&&$db['database']==='z_cn','Local rollback only');
request()->tenantId=1;request()->userId=1;
$wallets=fn()=>[Db::name('user')->where(['tenant_id'=>1,'id'=>1])->value('user_money'),Db::name('tenant')->where('id',1)->value('point_balance')];$before=$wallets();
$armed='';$fired=false;$after=0;$fault=null;$laneEntered=false;
Db::listen(static function($sql)use(&$armed,&$fired,&$after,&$fault,&$laneEntered){
    if($armed==='')return;if(str_contains($sql,'GET_LOCK('))$laneEntered=true;if($fired){$after++;return;}
    $hit=$armed==='reserve'&&str_starts_with($sql,'INSERT INTO `la_ai_consumption_log`')
        ||$armed==='receipt'&&str_starts_with($sql,'UPDATE `la_ai_consumption_log`')&&str_contains($sql,'`upstream_task_id`')
        ||$armed==='provider-rejection'&&$laneEntered&&str_starts_with($sql,'SELECT')&&str_contains($sql,'`la_open_platform_request`');
    if($hit){$fired=true;throw $fault;}
});
function receipt(array $budget): string {
    $rid='req_fixture_'.bin2hex(random_bytes(16));S::table('request')->insert(['request_id'=>$rid,'tenant_id'=>1,'user_id'=>1,'key_id'=>0,'kind'=>'tool','app_code'=>F::APP_CODE,'method'=>'POST','path'=>'/api/v1/tools/'.F::APP_CODE.'/tasks','status'=>'accepted','billing_status'=>'pending','active'=>1,'budget_user'=>$budget['user'],'budget_tenant'=>$budget['tenant'],'lease_token'=>'','lease_expire'=>0,'consumption_ids'=>'[]','snapshot'=>S::encode($budget['snapshot']+['_execution_version'=>2,'dispatch_started'=>true,'native_materialized'=>true]),'response_blob'=>'','payload_hash'=>hash('sha256',$rid)]+S::timestamp());C::enter($rid);return $rid;
}
function children(string $rid): array {
    $row=S::table('request')->where('request_id',$rid)->find();$parent=ParentTask::findOrEmpty((int)$row['tool_task_id']);check(!$parent->isEmpty(),'Missing pre-reservation parent');return [$row,$parent,Image::findOrEmpty((int)$parent['image_task_id'])];
}
function recover(string $rid): array {
    C::leave();S::table('request')->where('request_id',$rid)->update(['lease_token'=>'fixture-worker','lease_expire'=>time()+300]);Recovery::refresh(S::table('request')->where('request_id',$rid)->find());return S::table('request')->where('request_id',$rid)->find();
}
Db::startTrans();try{
    $source=Db::name('update_source')->where('status',1)->order('id','desc')->find();check($source,'Local source missing');unset($source['id']);$source['name']='opf-image-authority-loopback';$source['base_url']=$source['online_base_url']='http://127.0.0.1:1';$source['license_key']=$source['online_license_key']='fixture';$source['dev_mode']=0;$sourceId=Db::name('update_source')->insertGetId($source);check(app\common\service\update\UpdateSourceClient::getSource()['active_base_url']==='http://127.0.0.1:1','Supplier not isolated');
    foreach([0,1] as $tenant){$settings=S::settings($tenant);$settings['apps']=array_values(array_unique(array_merge($settings['apps'],[F::APP_CODE,'aigc_image'])));M::persistSettings($tenant,true,$settings);}
    F::saveConfig(1,['default_channel'=>'market_image_model:188','default_quality'=>'1k','default_ratio'=>'1:1','unit_price'=>11]);
    $uri='uploads/opf-image-authority-'.bin2hex(random_bytes(8)).'.png';TenantFile::create(['tenant_id'=>1,'source_id'=>1,'source'=>FileEnum::SOURCE_USER,'type'=>10,'uri'=>$uri,'name'=>'fixture.png','storage_scope'=>'tenant','storage_engine'=>'local','storage_domain'=>'','create_time'=>time()]);
    $budget=P::multiAngleBudget(request(),['image'=>$uri,'views'=>'front,side']);$recipe=S::json(V::open($budget['snapshot']['native_prepared_blob']));$payload=$recipe['prepared']['image_payload'];
    foreach(['disconnect','lease'] as $kind){
        $rid=receipt($budget);$fault=$kind==='lease'?new OpenException('recovery_lease_lost','Fixture owner lost',409):new PDOException('Fixture server has gone away');if($fault instanceof PDOException)$fault->errorInfo=['HY000',2006,'Fixture server has gone away'];$armed='reserve';$fired=false;$after=0;
        try{F::generate(1,1,[]);throw new RuntimeException('Reached supplier');}catch(Throwable $e){check($e===$fault&&$fired&&$after===0,'Native child authority fault: '.get_class($e).' '.$e->getMessage().' fired='.(int)$fired.' later_sql='.$after);}finally{$armed='';}
        [$row,$parent,$child]=children($rid);check($child['status']==='running'&&!S::json($row['consumption_ids']),'Stale native catch changed child or reservation survived rollback');
        $done=recover($rid);check($done['status']==='failed'&&$done['billing_status']==='none'&&(int)$done['active']===0&&(float)$done['budget_user']===0.,'Never-reserved durable child did not close');check(Image::findOrEmpty($child['id'])['status']==='failed','Worker did not classify unreserved child');
    }
    // Reserve commits its business binding before the separate native task ID save.
    foreach(['reserved','submitting'] as $state){
        $rid=receipt($budget);$fault=new PDOException('Fixture server has gone away');$fault->errorInfo=['HY000',2013,'Fixture lost connection'];$armed='reserve';$fired=false;$after=0;try{F::generate(1,1,[]);}catch(Throwable $e){check($e===$fault,'Unexpected child fixture failure');}finally{$armed='';}
        [$row,$parent,$child]=children($rid);$reservation=Runtime::reserve(1,1,'generate',(string)$child['id'],$payload,$payload,1,F::APP_CODE,'aigc_image_task',['user_unit_price'=>11]);$cid=$reservation['consumption_id'];check((int)$child['app_task_id']===0,'Fixture did not interrupt binding save');if($state==='submitting')N::start($cid);
        $done=recover($rid);$native=Image::findOrEmpty($child['id']);check((int)$native['app_task_id']===$reservation['app_task_id'],'Recovery lost atomic business binding');
        $c=Consumption::findOrEmpty($cid);if($state==='reserved')check($done['status']==='failed'&&$done['billing_status']==='refunded'&&(int)$done['active']===0&&(float)$done['actual_user']===0.&&$c['billing_status']==='refunded','Never-submitted reservation was not released');else check((int)$done['active']===1&&$native['status']==='running'&&$c['run_status']==='submitting'&&$c['billing_status']==='reserved','Unknown submission was canceled or repeated');
    }
    Db::name('update_source')->where('id',$sourceId)->update(['base_url'=>'http://127.0.0.1:19873','online_base_url'=>'http://127.0.0.1:19873']);check(app\common\service\update\UpdateSourceClient::getSource()['active_base_url']==='http://127.0.0.1:19873','Supplier fixture fence lost');
    foreach(['receipt','provider-rejection'] as $phase){
        $rid=receipt($budget);$reservation=Runtime::reserve(1,1,'generate','0',$payload,$payload,1,F::APP_CODE,'aigc_image_task',['user_unit_price'=>11]);$cid=$reservation['consumption_id'];$c=Consumption::findOrEmpty($cid);$price=S::json($c['price_snapshot']);$price['model_code']=$phase==='receipt'?'opf-image-authority':'opf-image-authority-reject';$price['market_metadata']=[];$c->save(['price_snapshot'=>$price]);
        $fault=new PDOException('Fixture server has gone away');$fault->errorInfo=['HY000',2006,'Fixture server has gone away'];$armed=$phase;$fired=false;$after=0;$laneEntered=false;
        try{Runtime::submit($cid,$payload);throw new RuntimeException('Runtime swallowed connection loss');}catch(Throwable $e){check($e===$fault&&$fired&&$after===0,'Runtime error handler or advisory lane issued SQL after disconnect');}finally{$armed='';}
        check(Consumption::findOrEmpty($cid)['run_status']==='submitting','Authority loss was incorrectly classified as supplier failure');C::leave();
    }
    echo "PASS native PDO and lease faults escape without later SQL, unreserved children close, atomic reservation binding recovers, reserved-only refunds, unknown acceptance remains held, accepted response and provider-rejection lane disconnects retire; loopback and rollback only\n";
}finally{$armed='';C::leave();Db::rollback();}
check($wallets()===$before,'Rollback changed wallets');
