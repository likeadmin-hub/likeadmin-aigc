<?php
require dirname(__DIR__,2).'/vendor/autoload.php';(new think\App())->initialize();
use think\facade\Db;
use app\common\service\app\aigc_music\{AigcMusicService as Music,XhadminAigcMusicProvider};
use app\common\model\app\aigc_music\{AigcMusicPersona,AigcMusicResult,AigcMusicTask};
use app\common\service\open_platform\OpenException;
function check($ok,$why){if(!$ok)throw new RuntimeException($why);}
function denied(callable $fn,string $type){try{$fn();}catch(OpenException $e){check($e->errorType===$type,'Wrong reference rejection');return;}throw new RuntimeException('Invalid reference accepted');}
$db=config('database.connections.mysql');check($db['hostname']==='127.0.0.1'&&$db['database']==='z_cn','Local rollback only');
Db::startTrans();try{
    $persona=AigcMusicPersona::create(['tenant_id'=>1,'user_id'=>1,'name'=>'Synthetic upstream style','prompt_json'=>['provider_persona_id'=>'persona_fixture_owned'],'status'=>1,'delete_time'=>0,'create_time'=>time(),'update_time'=>time()]);
    denied(fn()=>Music::referenceBindings(1,1,['persona_id'=>(int)$persona['id']]),'resource_unavailable');
    AigcMusicTask::create(['tenant_id'=>1,'user_id'=>1,'provider'=>'market_music_operation','action'=>'persona','consumption_id'=>2147483647,'status'=>'success','billing_status'=>'settled','result_json'=>['persona_id'=>(int)$persona['id'],'provider_persona_id'=>'persona_fixture_owned'],'delete_time'=>0,'create_time'=>time(),'update_time'=>time()]);
    $persona->save(['prompt_json'=>['provider_persona_id'=>'persona_fixture_owned','consumption_id'=>2147483647]]);
    $forged=AigcMusicPersona::create(['tenant_id'=>1,'user_id'=>1,'name'=>'Forged local wrapper','prompt_json'=>$persona['prompt_json'],'status'=>1,'delete_time'=>0,'create_time'=>time(),'update_time'=>time()]);
    denied(fn()=>Music::referenceBindings(1,1,['persona_id'=>(int)$forged['id']]),'resource_unavailable');
    $result=AigcMusicResult::create(['tenant_id'=>1,'user_id'=>1,'task_id'=>0,'title'=>'Synthetic owned audio','result_json'=>['id'=>'audio_fixture_owned'],'delete_time'=>0,'create_time'=>time()]);
    AigcMusicResult::create(['tenant_id'=>1,'user_id'=>2,'task_id'=>0,'title'=>'Foreign audio','result_json'=>['id'=>'audio_fixture_foreign'],'delete_time'=>0,'create_time'=>time()]);
    $task=AigcMusicTask::create(['tenant_id'=>1,'user_id'=>1,'provider'=>'market_music_operation','action'=>'upload_audio','status'=>'success','result_json'=>['reference_audio_id'=>'audio_fixture_uploaded'],'delete_time'=>0,'create_time'=>time(),'update_time'=>time()]);
    $params=['persona_id'=>(int)$persona['id'],'audio_id'=>'audio_fixture_owned'];$bindings=Music::referenceBindings(1,1,$params);
    check($bindings['provider_persona_id']==='persona_fixture_owned'&&$bindings['audio_id']==='audio_fixture_owned','Owned resource binding lost');
    check(Music::referenceBindings(1,1,['audio_id'=>'audio_fixture_uploaded'])['audio_id']==='audio_fixture_uploaded','Upstream uploaded reference unavailable');
    foreach([[1,2],[2,1]] as [$tenant,$user])denied(fn()=>Music::referenceBindings($tenant,$user,$params),'not_found');
    denied(fn()=>Music::referenceBindings(1,1,['audio_id'=>'audio_fixture_foreign']),'not_found');
    denied(fn()=>Music::referenceBindings(1,2,['audio_id'=>'audio_fixture_uploaded']),'not_found');
    denied(fn()=>Music::referenceBindings(1,1,['persona_id'=>'persona_raw_provider_id']),'invalid_request');
    denied(fn()=>Music::referenceBindings(1,1,['voice_clone_id'=>1]),'capability_unavailable');
    $persona->save(['prompt_json'=>['provider_persona_id'=>'persona_changed_after_admission']]);
    $request=(new ReflectionMethod(Music::class,'buildRequest'))->invoke(null,$task,['resource_bindings'=>$bindings,'channel'=>['config_json'=>[]],'spec'=>[]],['title'=>'','prompt'=>'Synthetic melody','lyrics'=>'','genre'=>'','mood'=>'','instruments'=>'','persona_id'=>$persona['id'],'provider_persona_id'=>'forged_client_id','audio_id'=>'audio_fixture_foreign']);
    $payload=(new ReflectionMethod(XhadminAigcMusicProvider::class,'buildPayload'))->invoke(new XhadminAigcMusicProvider(),$request,['extra_payload'=>[]]);
    check($payload['persona_id']==='persona_fixture_owned'&&$payload['audio_id']==='audio_fixture_owned','Frozen binding changed or client override reached supplier');
    echo "PASS owned generated/uploaded audio and singer style resolve to supplier IDs; cross-owner aliases denied; admission binding survives edits and rejects client override without supplier calls\n";
}finally{Db::rollback();}
