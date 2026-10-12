<?php
/** Loopback submission only; native task/receipt writes roll back, no provider or storage use. */
require dirname(__DIR__,2).'/vendor/autoload.php';(new think\App())->initialize();
use think\facade\Db;
use app\common\service\open_platform\OpenStore as S;
use app\common\service\open_platform\OpenCallContext as C;
use app\common\service\open_platform\OpenMusicReceipt as Receipt;
use app\common\service\open_platform\OpenVault as Vault;
use app\common\service\update\UpdateSourceClient as Source;
use app\common\service\app\aigc_music\XhadminAigcMusicProvider as Provider;
use app\common\service\app\aigc_music\AigcMusicGenerateRequest as Request;
use app\common\model\app\aigc_music\AigcMusicTask as Task;
function check($ok,$why){if(!$ok)throw new RuntimeException($why);}
$db=config('database.connections.mysql');check($db['hostname']==='127.0.0.1'&&$db['database']==='z_cn','Local rollback fixture only');
Db::startTrans();try{
    $source=Db::name('update_source')->where('status',1)->order('id','desc')->find();check($source,'No local source record');unset($source['id']);
    Db::name('update_source')->insert(array_replace($source,['name'=>'opf-loopback-music','base_url'=>'http://127.0.0.1:19873','online_base_url'=>'http://127.0.0.1:19873','license_key'=>'fixture-server-key','online_license_key'=>'fixture-server-key','dev_mode'=>0,'ssl_verify'=>0,'create_time'=>time(),'update_time'=>time()]));
    check(Source::getSource()['active_base_url']==='http://127.0.0.1:19873','Refuse any real upstream call');
    $wallets=fn()=>[(string)Db::name('user')->where('id',1)->value('user_money'),(string)Db::name('tenant')->where('id',1)->value('point_balance')];$before=$wallets();
    $make=static function($path){
        $rid='req_music_receipt_'.bin2hex(random_bytes(12));S::table('request')->insert(['request_id'=>$rid,'tenant_id'=>1,'user_id'=>1,'key_id'=>0,'kind'=>'tool','app_code'=>'aigc_music','method'=>'POST','path'=>'/api/v1/tools/aigc_music/tasks','status'=>'running','billing_status'=>'pending','active'=>1,'budget_user'=>65,'budget_tenant'=>5,'consumption_ids'=>'[]','snapshot'=>'{}','response_blob'=>'','payload_hash'=>hash('sha256',$rid)]+S::timestamp());C::enter($rid);
        $task=Task::create(['tenant_id'=>1,'user_id'=>1,'app_code'=>'aigc_music','action'=>'music_generation','title'=>'fixture','prompt'=>'synthetic fixture','lyrics'=>'','channel'=>'fixture','quality'=>'fixture','duration'=>15,'quantity'=>1,'tenant_cost_points'=>5,'user_charge_points'=>65,'provider'=>'xhadmin','model'=>'fixture','idempotency_key'=>bin2hex(random_bytes(16)),'billing_status'=>'prechecked','status'=>'running','delete_time'=>0,'create_time'=>time(),'update_time'=>time()]);
        $req=new Request('fixture','synthetic fixture','','','','',15,1,['instrumental'=>true],[],[],['tenant_id'=>1,'user_id'=>1,'_native_task_id'=>(int)$task['id'],'create_path'=>$path,'query_path'=>'/api/v1/music-query-failure','poll_attempts'=>0]);return [$rid,$task,$req];
    };
    [$rid,$task,$request]=$make('/api/v1/music-receipt');$provider=new Provider();$result=$provider->generate($request);check($result->success&&$result->providerTaskId==='fixture-music-receipt','Loopback submit failed');
    $row=S::table('request')->where('request_id',$rid)->find();$snapshot=S::json($row['snapshot']);check($row['upstream_task_id']==='fixture-music-receipt'&&$snapshot['native_music_receipt_blob']!==''&&!str_contains(S::encode($snapshot),'private fixture receipt'),'Provider receipt not encrypted before native task save');
    check(Task::where('id',$task['id'])->value('provider_task_id')==='','Test did not interrupt before native ID save');C::leave();
    check(!Receipt::restore(1,2,$task['id'])&&!Receipt::restore(2,1,$task['id']),'Foreign receipt restored');
    check(Receipt::restore(1,1,$task['id'])&&!Receipt::restore(1,1,$task['id']),'Owned restore missing or not idempotent');
    $native=Task::findOrEmpty($task['id']);check($native['provider_task_id']==='fixture-music-receipt'&&$native['provider_payload']['submit']['data']['receipt']==='private fixture receipt','Task handoff lost receipt');
    C::enter($rid);$denied=false;try{Receipt::capture(1,1,$task['id'],'different-upstream',[]);}catch(app\common\service\open_platform\OpenException $e){$denied=$e->httpStatus===409;}check($denied,'Original upstream identity overwritten');
    $transient=false;try{$provider->fetchResult('fixture-music-receipt',$request);}catch(Throwable){$transient=true;}check($transient&&Task::where('id',$task['id'])->value('status')==='running','Temporary query failure became terminal generation failure');
    [$unknown,$unknownTask,$unknownRequest]=$make('/api/v1/music-no-task');$uncertain=false;try{$provider->generate($unknownRequest);}catch(app\common\service\open_platform\OpenException $e){$uncertain=$e->errorType==='upstream_uncertain';}check($uncertain&&Task::where('id',$unknownTask['id'])->value('status')==='running','Unknown submission returned a definitive failure');
    [$rejected,$rejectedTask,$rejectedRequest]=$make('/api/v1/music-reject');$reject=$provider->generate($rejectedRequest);check(!$reject->success&&app\common\service\open_platform\OpenTextLifecycle::rejectionConfirmed(),'Explicit rejection incorrectly held as uncertain');
    C::leave();$plain=$provider->fetchResult('fixture-music-receipt',$request);check(!$plain->success,'Existing non-API provider behavior changed');
    check($wallets()===$before,'Receipt recovery changed wallets');
    echo "PASS loopback music receipt precedes native save, encrypted owned ID restoration, immutable upstream identity, unknown admission and temporary query retention, explicit rejection, ordinary provider behavior and no charges\n";
}finally{C::leave();Db::rollback();}
