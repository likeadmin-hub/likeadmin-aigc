<?php
/** Owner-marked, normal-login PC/H5/MP sessions and actual mobile request wrappers. */
function runMarketAgentDeviceHttp($f,string $api,string $peer,string $suffix,string $token,string $imageChannel,int $text,array &$report): void
{
    $db=think\facade\Db::class;$script=dirname(__DIR__).'/market-generation-http.php';
    $check=fn($ok,$why)=>MarketWorkerFixture::check((bool)$ok,$why);
    $request=function($action,$p=[],$auth=null,$method='POST')use($api,$peer,$suffix,$token){
        $path='/api/app.aigc_llm.agent/'.$action.$suffix;
        if($method==='GET')$path.='&'.http_build_query($p);
        $r=callHttp($method==='GET'?$peer:$api,$path,$method==='GET'?[]:$p,$auth??$token,$method);
        if(($r['code']??0)!==1)throw new RuntimeException('Device '.$action.': '.($r['msg']??'unknown'));
        return $r['data']??[];
    };
    $login=fn($terminal)=>dataHttp($peer,'/api/login/account'.$suffix,['account'=>$f->prefix,'password'=>'Private-fixture-pass-2026','scene'=>1,'terminal'=>$terminal])['token'];
    $tokens=['pc'=>$token,'h5'=>$login(3),'mp'=>$login(1)];
    $check(count(array_unique($tokens))===3,'Terminal logins share a token');
    foreach([4,3,1]as$terminal)$check($db::name('user_session')->where(['tenant_id'=>$f->tenant,'user_id'=>$f->user,'terminal'=>$terminal])->count()===1,'Normal terminal login missing');
    $counts=fn()=>[(int)$db::name('aigc_llm_agent_run')->count(),(int)$db::name('ai_app_task')->count(),(int)$db::name('ai_consumption_log')->count(),count(file($f->dir.'/submissions.jsonl'))];
    $finish=function($id,$status)use($f,$script,$db){
        $f->start($script,'fixture_agent_runner','agent');$f->start($script,'aigc_image','worker');
        try{MarketWorkerFixture::wait(fn()=>$db::name('aigc_llm_agent_run')->where('id',$id)->value('status')===$status,'Device Agent did not reach '.$status,60);}
        catch(Throwable $e){fwrite(STDERR,'Device state: '.json_encode($db::name('aigc_llm_agent_run')->where('id',$id)->field('id,status,phase,error')->find(),JSON_UNESCAPED_UNICODE).chr(10));throw $e;}
        finally{$f->stop('agent');$f->stop('worker');}
    };
    $p=['session_id'=>0,'request_id'=>'device-image-intent-original-0001','content'=>'Synthetic image plan','preferences'=>['mode'=>'ask','models'=>['text'=>'market_text_'.$text,'image'=>$imageChannel]]];
    $first=$request('submit',$p);$id=(int)$first['id'];$before=$counts();
    foreach($tokens as$auth)$check($request('submit',$p,$auth)['id']===$id&&$counts()===$before,'Device replay created a second run');
    $finish($id,'awaiting_confirmation');$old=$request('detail',['run_id'=>$id],$tokens['h5'],'GET');$before=$counts();
    foreach($tokens as$auth){
        $view=$request('detail',['run_id'=>$id],$auth,'GET');
        $check($view['approval_hash']===$old['approval_hash']&&$view['steps']===$old['steps']&&$counts()===$before,'Device read changed pending proposal');
    }
    $step=$old['steps'][0];$new=$request('editPlan',['run_id'=>$id,'step_id'=>$step['id'],'approval_hash'=>$old['approval_hash'],'changes'=>['prompt'=>'Synthetic device revised image']]);
    $check($new['approval_hash']!==$old['approval_hash'],'Revised plan kept old approval');$before=$counts();
    $stale=callHttp($peer,'/api/app.aigc_llm.agent/confirm'.$suffix,['run_id'=>$id,'approval_hash'=>$old['approval_hash']],$tokens['h5']);
    $check(($stale['code']??1)!==1&&$counts()===$before,'Old-device approval dispatched generation');
    $request('preferences',['mode'=>'ask','models'=>['image'=>$imageChannel]]);
    if(getenv('AIGC_HTTP_AGENT_DEVICE_UI')==='1') {
        file_put_contents($f->dir.'/mobile-input.json',json_encode(['api'=>$api,'api_peer'=>$peer,'ui'=>true,'tenant'=>$f->tenant,'account'=>$f->prefix,'session_id'=>$first['session_id'],'run_id'=>$id],JSON_THROW_ON_ERROR));
        fwrite(STDERR,'MOBILE_AGENT_UI_READY '.$f->dir.chr(10));
        $interactive=getenv('AIGC_HTTP_AGENT_DEVICE_UI_DRAFT')==='1';
        if($interactive){$f->start($script,'fixture_agent_runner','agent');$f->start($script,'aigc_image','worker');}
        try{MarketWorkerFixture::wait(fn()=>is_file($f->dir.'/mobile-ui-done.json'),'Private mobile UI observation not finished',600);}
        finally{if($interactive){$f->stop('agent');$f->stop('worker');}}
        if($interactive){
            $newRuns=$db::name('aigc_llm_agent_run')->where('id','<>',$id)->select()->toArray();
            $check(count($newRuns)===1&&$newRuns[0]['status']==='success'&&$request('detail',['run_id'=>$id],null,'GET')['approval_hash']===$new['approval_hash'],'UI history reuse did not complete a separate new intention');
            $check($request('detail',['run_id'=>$id],null,'GET')['status']==='awaiting_confirmation','UI history reuse changed original pending intent');
            $report['isolated_h5_original_login_reuse_submit_confirm_result']=true;
        }else $check($counts()===$before,'Read-only mobile UI dispatched a task');
    }
    $tokens['h5']=$login(3);$tokens['mp']=$login(1);
    foreach($tokens as$auth)$check($request('detail',['run_id'=>$id],$auth,'GET')['approval_hash']===$new['approval_hash'],'Fresh terminal login lost proposal revision');
    $read=function($name)use($f){if(is_file($f->dir.'/mobile-failed.json'))throw new RuntimeException(file_get_contents($f->dir.'/mobile-failed.json'));return is_file($f->dir.'/'.$name)?json_decode(file_get_contents($f->dir.'/'.$name),true):null;};
    $wait=function($name)use($read){$row=null;MarketWorkerFixture::wait(function()use($read,$name,&$row){return (bool)($row=$read($name));},'Mobile device phase missing: '.$name,120);return $row;};
    file_put_contents($f->dir.'/mobile-input.json',json_encode(['api'=>$api,'api_peer'=>$peer,'tenant'=>$f->tenant,'tokens'=>$tokens,'session_id'=>$first['session_id'],'run_id'=>$id,'approval_hash'=>$new['approval_hash'],'submit'=>$p],JSON_THROW_ON_ERROR));
    fwrite(STDERR,'MOBILE_AGENT_FIXTURE_READY '.$f->dir."\n");
    $mobile=$wait('mobile-confirmed.json');$check($mobile['run_id']===$id,'Mobile confirmed wrong run');$finish($id,'success');
    $saved=$db::name('aigc_llm_agent_step')->where('run_id',$id)->select()->toArray();
    $logs=$db::name('ai_consumption_log')->whereIn('app_task_id',array_column($saved,'task_id'))->select()->toArray();
    $art=$request('artifacts',['session_id'=>$first['session_id']],$tokens['mp'],'GET');
    $check(count($saved)===1&&count($art)===1&&$art[0]['kind']==='image'&&!empty($art[0]['file_id']),'First cross-device media result incomplete');
    $product=$db::name('power_market_product')->where('model_type','image')->find();$db::name('power_market_product')->where('id',$product['id'])->update(['status'=>0]);
    file_put_contents($f->dir.'/mobile-ready.json',json_encode(['first_artifact'=>$art[0]['id']]));
    $wait('mobile-reopened.json');$before=$counts();
    $check($request('submit',$p,$tokens['mp'])['id']===$id&&$counts()===$before,'Unshelved old device replay regenerated');
    $db::name('power_market_product')->where('id',$product['id'])->update(['status'=>1]);
    file_put_contents($f->dir.'/mobile-history-ready.json','true');$history=$wait('mobile-history.json');$next=(int)$history['run_id'];$check($next!==$id,'Mobile history reused old request identity');
    $finish($next,'awaiting_confirmation');file_put_contents($f->dir.'/mobile-plan-ready.json','true');$wait('mobile-history-confirmed.json');$finish($next,'success');
    $check($saved===$db::name('aigc_llm_agent_step')->where('run_id',$id)->select()->toArray(),'History reuse rewrote original step');
    $check($logs===$db::name('ai_consumption_log')->whereIn('app_task_id',array_column($saved,'task_id'))->select()->toArray(),'History reuse rewrote frozen consumption');
    $before=$counts();$cancel=$request('submit',array_replace($p,['request_id'=>'device-image-cancel-0003']));$finish((int)$cancel['id'],'awaiting_confirmation');
    $cancelPlan=$request('detail',['run_id'=>$cancel['id']],$tokens['h5'],'GET');$request('stop',['run_id'=>$cancel['id']],$tokens['mp']);$beforeCancel=$counts();
    $refused=callHttp($peer,'/api/app.aigc_llm.agent/confirm'.$suffix,['run_id'=>$cancel['id'],'approval_hash'=>$cancelPlan['approval_hash']],$tokens['pc']);
    $check(($refused['code']??1)!==1&&$counts()===$beforeCancel,'Canceled device proposal later dispatched');
    file_put_contents($f->dir.'/mobile-done-ready.json','true');$mobileDone=$wait('mobile-done.json');
    $report=$mobileDone+$report+['normal_terminal_logins'=>[4,3,1],'same_request_across_devices_once'=>true,'pending_reads_never_dispatch'=>true,'stale_approval_after_pc_edit_rejected'=>true,'fresh_h5_mp_login_revision_preserved'=>true,'mobile_history_new_intent_old_step_and_frozen_consumption_unchanged'=>true,'canceled_plan_reconfirmation_refused'=>true,'scope'=>'Normal login HTTP on isolated tables and loopback suppliers; actual UniApp wrappers/controller on host, no physical device certification'];
}
