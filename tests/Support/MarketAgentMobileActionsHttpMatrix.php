<?php
/** Original normal login, multipart, plan edit/retry, queue and managed result; owned private state only. */
function runMarketAgentMobileActionsHttp($f,string $api,string $peer,string $suffix,string $token,string $imageChannel,int $text,array &$report): void
{
    $db=think\facade\Db::class;$script=dirname(__DIR__).'/market-generation-http.php';
    $check=fn($ok,$why)=>MarketWorkerFixture::check((bool)$ok,$why);
    $get=fn($action,$p,$auth=null)=>dataHttp($peer,'/api/app.aigc_llm.agent/'.$action.$suffix.'&'.http_build_query($p),[],$auth??$token,'GET');
    $post=fn($action,$p)=>dataHttp($api,'/api/app.aigc_llm.agent/'.$action.$suffix,$p,$token);
    $login=fn($terminal)=>dataHttp($peer,'/api/login/account'.$suffix,['account'=>$f->prefix,'password'=>'Private-fixture-pass-2026','scene'=>1,'terminal'=>$terminal])['token'];
    $tokens=['pc'=>$token,'h5'=>$login(3),'mp'=>$login(1)];
    $other=$f->user+1;$db::name('user')->insert(['id'=>$other,'tenant_id'=>$f->tenant,'sn'=>$other,'account'=>$f->prefix.'other','nickname'=>'Private other','password'=>create_password('Private-fixture-pass-2026',config('project.unique_identification')),'user_money'=>100,'create_time'=>time(),'update_time'=>time()]);
    $tokens['other']=dataHttp($peer,'/api/login/account'.$suffix,['account'=>$f->prefix.'other','password'=>'Private-fixture-pass-2026','scene'=>1,'terminal'=>3])['token'];
    $row=$db::name('power_market_product')->where('id',$text)->find();$source=json_decode($row['source_payload'],true);$source['market_metadata']['supports_vision']=true;$db::name('power_market_product')->where('id',$text)->update(['source_payload'=>json_encode($source)]);
    $post('preferences',['mode'=>'ask','models'=>['image'=>$imageChannel]]);
    $read=function($name)use($f){if(is_file($f->dir.'/mobile-failed.json'))throw new RuntimeException(file_get_contents($f->dir.'/mobile-failed.json'));return is_file($f->dir.'/'.$name)?json_decode(file_get_contents($f->dir.'/'.$name),true):null;};
    $wait=function($name,$seconds=120)use($read){$row=null;MarketWorkerFixture::wait(function()use($read,$name,&$row){return (bool)($row=$read($name));},'Mobile action phase missing: '.$name,$seconds);return $row;};
    $input=['api'=>$api,'api_peer'=>$peer,'tenant'=>$f->tenant,'account'=>$f->prefix,'tokens'=>$tokens];
    file_put_contents($f->dir.'/mobile-input.json',json_encode($input+['ui'=>getenv('AIGC_HTTP_AGENT_MOBILE_ACTIONS_UI')==='1'],JSON_THROW_ON_ERROR));
    $f->start($script,'fixture_agent_runner','agent');$f->start($script,'aigc_image','worker');
    try {
        if(getenv('AIGC_HTTP_AGENT_MOBILE_ACTIONS_UI')==='1') {
            file_put_contents($f->dir.'/mobile-fail-next','1');fwrite(STDERR,'MOBILE_AGENT_ACTIONS_UI_READY '.$f->dir.chr(10));$ui=$wait('mobile-ui-done.json',600);
            $runs=$db::name('aigc_llm_agent_run')->order('id')->select()->toArray();
            $check(count($runs)===2&&array_unique(array_column($runs,'status'))===['success'],'H5 UI did not finish retry and new history intentions');
            foreach($runs as$run)$check(count(json_decode($run['attachments'],true))===2,'H5 UI lost one mixed attachment');
            $check($db::name('aigc_llm_agent_step')->where('status','failed')->count()===1&&$db::name('aigc_llm_agent_step')->where('status','success')->count()===2,'UI retry did not preserve the failed attempt');
            $check($db::name('open_platform_file')->where('name','like','%b72-reference%')->count()===2,'UI history reuploaded original materials');
            $report['actual_h5_upload_edit_failure_refund_retry_confirm_result_history']=$ui;
        }
        $tokens['h5']=$login(3);$tokens['mp']=$login(1);$input['tokens']=$tokens;
        file_put_contents($f->dir.'/mobile-input.json',json_encode($input,JSON_THROW_ON_ERROR));
        file_put_contents($f->dir.'/mobile-fail-next','1');fwrite(STDERR,'MOBILE_AGENT_ACTIONS_READY '.$f->dir.chr(10));
        $first=$wait('mobile-first-done.json');$runId=(int)$first['run_id'];$view=$get('detail',['run_id'=>$runId]);
        $check($view['status']==='success'&&count($view['steps'])===2&&$view['steps'][0]['status']==='failed'&&$view['steps'][1]['status']==='success','Mobile original attempt/retry state wrong');
        $oldStep=$db::name('aigc_llm_agent_step')->where('id',$view['steps'][0]['id'])->find();$successfulStep=$db::name('aigc_llm_agent_step')->where('id',$view['steps'][1]['id'])->find();
        $failedLog=$db::name('ai_consumption_log')->where('app_task_id',$oldStep['task_id'])->find();$successLog=$db::name('ai_consumption_log')->where('app_task_id',$successfulStep['task_id'])->find();
        $check($failedLog['billing_status']==='refunded'&&$successLog['billing_status']==='settled'&&(float)$failedLog['actual_user_price']===0.0,'Retry did not wait for original media refund');
        $refs=json_decode($successfulStep['prepared'],true)['inputs']['reference_images']??[];
        $check($refs===[$first['attachment_ids'][0]],'Prepared image did not use the uploaded managed image identity');
        $assets=[];foreach($first['attachment_ids']as$id){$asset=$db::name('open_platform_file')->where(['tenant_id'=>$f->tenant,'user_id'=>$f->user,'file_id'=>$id])->find();$check($asset&&!empty($asset['storage_scope'])&&$asset['storage_engine']==='local'&&is_file($f->dir.'/public/'.$asset['uri']),'Uploaded material lacks original owned storage');$assets[]=$asset;}
        $check($assets[0]['mime']==='image/png'&&str_starts_with($assets[1]['mime'],'text/'),'Mixed original MIME lost');
        $beforeFiles=(int)$db::name('open_platform_file')->count();file_put_contents($f->dir.'/mobile-history-ready.json','true');$history=$wait('mobile-done.json');
        $new=$get('detail',['run_id'=>$history['history_run_id']]);$check($new['status']==='success'&&$new['id']!==$runId,'Reused material history did not create a new finished intention');
        $check(array_column($new['attachments'],'file_id')===$first['attachment_ids'],'History reuse lost managed references or order');
        $check($oldStep===$db::name('aigc_llm_agent_step')->where('id',$oldStep['id'])->find()&&$successfulStep===$db::name('aigc_llm_agent_step')->where('id',$successfulStep['id'])->find(),'History reuse rewrote old attempt rows');
        $check($failedLog===$db::name('ai_consumption_log')->where('id',$failedLog['id'])->find()&&$successLog===$db::name('ai_consumption_log')->where('id',$successLog['id'])->find(),'History reuse rewrote old frozen consumption');
        foreach($assets as$asset)$check($asset===$db::name('open_platform_file')->where('id',$asset['id'])->find(),'History reuse rewrote original uploaded material');
        $check($db::name('open_platform_file')->count()===$beforeFiles+1,'History reuse uploaded duplicate inputs instead of one new output');
        $refunds=$db::name('ai_consumption_log')->where('billing_status','refunded')->select()->toArray();
        $check(count($refunds)===(getenv('AIGC_HTTP_AGENT_MOBILE_ACTIONS_UI')==='1'?2:1),'Unexpected refunded media count');
        foreach($refunds as$log)$check($db::name('ai_app_task')->where('id',$log['app_task_id'])->value('status')==='failed','Refund counted a non-failed native task');
        $report=$history+$report+['mixed_upload_original_mime_storage_preserved'=>true,'references_reach_native_preparation'=>true,'failed_and_successful_attempts_billing_frozen'=>true,'managed_inputs_reused_without_reupload'=>true,'old_history_steps_and_consumption_unchanged'=>true,'refunded_media_submissions'=>count($refunds),'scope'=>'Private normal-login H5/MP wrappers, original multipart/plan/queue/result APIs; loopback suppliers only'];
    } finally { $f->stop('agent');$f->stop('worker'); }
}
