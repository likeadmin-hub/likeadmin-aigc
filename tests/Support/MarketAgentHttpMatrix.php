<?php
/** Native stream and Agent APIs, private login/SQL/supplier only. */
function runMarketAgentHttp($f,$api,$peer,$suffix,&$token,$imageChannel,array &$report): void
{
    $db=think\facade\Db::class;$t=$f->tenant;$u=$f->user;$script=dirname(__DIR__).'/market-generation-http.php';
    $check=fn($ok,$why)=>MarketWorkerFixture::check((bool)$ok,$why);
    $product=function($code)use($f){$p=app\common\model\power\PowerMarketProduct::create(['product_code'=>$f->prefix.$code,'name'=>'Private '.$code,'resource_type'=>'model','model_type'=>'text','source_code'=>'isolated-fixture','upstream_resource_key'=>$f->prefix.$code,'upstream_model_code'=>$code,'upstream_channel_code'=>'fixture','source_payload'=>['category_code'=>'text','market_metadata'=>['protocol'=>'openai_chat','max_output_tokens'=>8000,'context_window'=>128000]],'status'=>1]);app\common\model\power\PowerMarketSku::create(['product_id'=>$p['id'],'sku_key'=>$f->prefix.$code,'title'=>'Private text','locked_params'=>[],'usage_unit'=>'token','usage_unit_size'=>1000,'upstream_price'=>1,'sale_points'=>1,'sale_status'=>1,'status'=>1]);return (int)$p['id'];};
    $text=$product('synthetic-stream-text');$agent=$product('synthetic-agent-text');
    app\common\service\app\aigc_llm\AigcLlmService::saveConfig($t,['status'=>1,'config_json'=>['agent_text_model'=>'market_text_'.$agent]]);
    if(getenv('AIGC_HTTP_AGENT_DEVICE_MATRIX')==='1') {
        require __DIR__.'/MarketAgentDeviceHttpMatrix.php';$report['devices']=[];
        runMarketAgentDeviceHttp($f,$api,$peer,$suffix,$token,$agent,$report['devices']);
        $report['text_reservations']=(int)$db::name('ai_consumption_log')->whereIn('product_id',[$text,$agent])->count();
        $report['text_settlements']=(int)$db::name('ai_consumption_log')->whereIn('product_id',[$text,$agent])->where('billing_status','settled')->count();return;
    }
    if(getenv('AIGC_HTTP_DRAMA_WORKFLOW_MATRIX')==='1') {
        require __DIR__.'/MarketDramaWorkflowHttpMatrix.php';$report['workflow']=[];
        runMarketDramaWorkflowHttp($f,$api,$peer,$suffix,$token,$imageChannel,$text,$report['workflow']);
        $report['text_reservations']=(int)$db::name('ai_consumption_log')->whereIn('product_id',[$text,$agent])->count();
        $report['text_settlements']=(int)$db::name('ai_consumption_log')->whereIn('product_id',[$text,$agent])->where('billing_status','settled')->count();return;
    }
    if(getenv('AIGC_HTTP_AGENT_MEDIA_MATRIX')==='1') {
        require __DIR__.'/MarketAgentMediaHttpMatrix.php';$report['media']=[];
        runMarketAgentMediaHttp($f,$api,$peer,$suffix,$token,$imageChannel,$text,$agent,$report['media']);
        $report['text_reservations']=(int)$db::name('ai_consumption_log')->whereIn('product_id',[$text,$agent])->count();
        $report['text_settlements']=(int)$db::name('ai_consumption_log')->whereIn('product_id',[$text,$agent])->where('billing_status','settled')->count();return;
    }
    if(getenv('AIGC_HTTP_AGENT_ADMISSION_PROBE')==='1') {
        require __DIR__.'/MarketAgentAdmissionProbe.php';
        runMarketAgentAdmissionProbe($f,$api,$peer,$suffix,$token,$report);
        $report['text_reservations']=0;$report['text_settlements']=0;return;
    }
    if(getenv('AIGC_HTTP_AGENT_SPECIAL_ONLY')==='1') {
        require __DIR__.'/MarketAgentSpecialHttpMatrix.php';$report['special']=[];
        runMarketAgentSpecialHttp($f,$api,$peer,$suffix,$token,$agent,$report['special']);
        $report['text_reservations']=(int)$db::name('ai_consumption_log')->whereIn('product_id',[$text,$agent])->count();
        $report['text_settlements']=(int)$db::name('ai_consumption_log')->whereIn('product_id',[$text,$agent])->where('billing_status','settled')->count();return;
    }
    $events=function($raw){$out=[];foreach(preg_split('/\r?\n\r?\n/',trim($raw))as$block){if(preg_match('/event: ([^\r\n]+).*?data: ([^\r\n]+)/s',$block,$m))$out[]=['event'=>$m[1],'data'=>json_decode($m[2],true)];}return $out;};
    $stream=function($base,$path,$p,$auth=null)use($token,$events){$curl=curl_init($base.'/api/app.'.$path);curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($p),CURLOPT_HTTPHEADER=>['Content-Type: application/json','token: '.($auth??$token)],CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>14]);$raw=curl_exec($curl);$error=curl_error($curl);curl_close($curl);if($error)throw new RuntimeException($error);return $events((string)$raw);};
    $pair=function($params)use($api,$peer,$suffix,$token,$events){$multi=curl_multi_init();$handles=[];foreach([$api,$peer]as$i=>$base){$c=curl_init($base.'/api/app.aigc_llm.chat/stream'.$suffix);curl_setopt_array($c,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($params[$i]),CURLOPT_HTTPHEADER=>['Content-Type: application/json','token: '.$token],CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>14]);curl_multi_add_handle($multi,$c);$handles[]=$c;}do{curl_multi_exec($multi,$active);if($active)curl_multi_select($multi,.01);}while($active);$out=[];foreach($handles as$c){$out[]=$events((string)curl_multi_getcontent($c));curl_multi_remove_handle($multi,$c);curl_close($c);}curl_multi_close($multi);return $out;};
    $find=fn($rows,$event)=>array_values(array_filter($rows,fn($r)=>$r['event']===$event));
    $calls=fn()=>count(array_filter(array_map(fn($line)=>json_decode($line,true),file($f->dir.'/deep-calls.jsonl')?:[]),fn($r)=>$r['kind']==='agent_text'));
    $p=['client_conversation_id'=>'client-conversation-intent-0001','session_id'=>0,'content'=>'Synthetic stream original','model_code'=>'market_text_'.$text,'request_id'=>'stream-original-request-0001'];
    [$first,$same]=$pair([$p,$p]);$a=$find($first,'message')[0]['data'];$b=$find($same,'message')[0]['data'];
    $check($a['assistant_message_id']===$b['assistant_message_id']&&count($find($first,'done'))===1&&count($find($same,'done'))===1&&$calls()===1,'Concurrent stream duplicate or missing terminal');
    $report['same_key_parallel_stream_once']=true;$old=$db::name('aigc_llm_message')->where('id',$a['assistant_message_id'])->find();$before=$calls();
    $conflict=$stream($peer,'aigc_llm.chat/stream'.$suffix,array_replace($p,['content'=>'Changed content']));$check(count($find($conflict,'error'))===1&&$calls()===$before,'Changed text request was dispatched');$report['changed_payload_refused']=true;
    $db::name('power_market_product')->where('id',$text)->update(['status'=>0]);$replay=$stream($api,'aigc_llm.chat/stream'.$suffix,$p);$check(count($find($replay,'done'))===1&&$calls()===$before,'Frozen text replay resolved current catalogue');$db::name('power_market_product')->where('id',$text)->update(['status'=>1]);$report['completed_replay_after_model_unshelf']=true;
    $next=$stream($api,'aigc_llm.chat/stream'.$suffix,array_replace($p,['session_id'=>$old['session_id'],'request_id'=>'stream-history-request-0002','regenerate_message_id'=>$a['parent_user_message_id']]));$check(count($find($next,'done'))===1&&$find($next,'message')[0]['data']['assistant_message_id']!==$a['assistant_message_id'],'Text regenerate did not create a new attempt');$check($old===$db::name('aigc_llm_message')->where('id',$old['id'])->find(),'Text history rewrote old message');$report['text_history_new_attempt_old_row_unchanged']=true;
    $drop=array_replace($p,['request_id'=>'stream-disconnect-request-0003','content'=>'Synthetic lost reader']);
    $curl=curl_init($api.'/api/app.aigc_llm.chat/stream'.$suffix);curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($drop),CURLOPT_HTTPHEADER=>['Content-Type: application/json','token: '.$token],CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT_MS=>150]);curl_exec($curl);$lost=curl_errno($curl);curl_close($curl);
    $reconnected=$stream($peer,'aigc_llm.chat/stream'.$suffix,$drop);$check($lost===CURLE_OPERATION_TIMEDOUT&&count($find($reconnected,'done'))===1,'Disconnected text generation did not complete through local follow');$report['disconnected_reader_local_follow_completes']=true;
    [$left,$right]=$pair([array_replace($p,['request_id'=>'stream-parallel-request-0004']),array_replace($p,['request_id'=>'stream-parallel-request-0005'])]);
    $check($find($left,'message')[0]['data']['assistant_message_id']!==$find($right,'message')[0]['data']['assistant_message_id']&&count($find($left,'done'))===1&&count($find($right,'done'))===1,'Independent text intentions merged');$report['independent_stream_sessions_concurrent']=true;
    $other=$u+1;$db::name('user')->insert(['id'=>$other,'tenant_id'=>$t,'sn'=>$other,'account'=>$f->prefix.'other','nickname'=>'Private other','password'=>create_password('Private-fixture-pass-2026',config('project.unique_identification')),'user_money'=>100,'create_time'=>time(),'update_time'=>time()]);
    $otherToken=dataHttp($peer,'/api/login/account'.$suffix,['account'=>$f->prefix.'other','password'=>'Private-fixture-pass-2026','scene'=>1,'terminal'=>4])['token'];
    $foreign=$stream($peer,'aigc_llm.chat/stream'.$suffix,$p,$otherToken);$check($find($foreign,'message')[0]['data']['assistant_message_id']!==$a['assistant_message_id'],'Text request key crossed users');$report['same_text_key_authenticated_owners_isolated']=true;
    $before=$calls();$incomplete=$stream($api,'aigc_llm.chat/stream'.$suffix,array_replace($p,['request_id'=>'stream-incomplete-request-0006','content'=>'Synthetic incomplete stream']));$msg=$find($incomplete,'message')[0]['data'];$partial=$db::name('aigc_llm_message')->where('id',$msg['assistant_message_id'])->find();$usage=json_decode($partial['token_usage_json'],true);$check($partial['content']!==''&&($usage['consumption_id']??0)>0&&($usage['billing']['billing_status']??'')==='settlement_failed','Incomplete text output/receipt was lost');
    $again=$stream($peer,'aigc_llm.chat/stream'.$suffix,array_replace($p,['request_id'=>'stream-incomplete-request-0006','content'=>'Synthetic incomplete stream']));$check($calls()===$before+1&&count($find($again,'error'))===1&&$find($again,'error')[0]['data']['terminal']===false,'Incomplete text was retried or falsely finalized');$report['incomplete_text_retained_no_automatic_retry']=true;
    $post=fn($action,$params)=>dataHttp($api,'/api/app.aigc_llm.agent/'.$action.$suffix,$params,$token);
    $get=fn($action,$params)=>dataHttp($api,'/api/app.aigc_llm.agent/'.$action.$suffix.'&'.http_build_query($params),[],$token,'GET');
    $input=['request_id'=>'agent-document-request-0001','session_id'=>0,'content'=>'Synthetic document please','preferences'=>['mode'=>'ask','models'=>['image'=>$imageChannel,'text'=>'market_text_'.$agent]]];
    $jsonPair=function($bodies)use($api,$peer,$suffix,$token){$multi=curl_multi_init();$handles=[];foreach([$api,$peer]as$i=>$base){$c=curl_init($base.'/api/app.aigc_llm.agent/submit'.$suffix);curl_setopt_array($c,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($bodies[$i]),CURLOPT_HTTPHEADER=>['Content-Type: application/json','token: '.$token],CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20]);curl_multi_add_handle($multi,$c);$handles[]=$c;}do{curl_multi_exec($multi,$active);if($active)curl_multi_select($multi,.01);}while($active);$out=[];foreach($handles as$c){$r=json_decode((string)curl_multi_getcontent($c),true);if(($r['code']??0)!==1)throw new RuntimeException('Agent concurrent submit failed: '.($r['msg']??''));$out[]=$r['data'];curl_multi_remove_handle($multi,$c);curl_close($c);}curl_multi_close($multi);return $out;};
    [$one,$same]=$jsonPair([$input,$input]);$check($one['id']===$same['id'],'Agent submit replay duplicated run');
    $changed=callHttp($peer,'/api/app.aigc_llm.agent/submit'.$suffix,array_replace($input,['preferences'=>['mode'=>'auto','models'=>['image'=>$imageChannel]]]),$token);
    $report['agent_changed_preferences_refused']=($changed['code']??1)!==1;$check($report['agent_changed_preferences_refused'],'Agent same request ID accepted changed execution preferences');
    [$independentA,$independentB]=$jsonPair([array_replace($input,['request_id'=>'agent-parallel-request-0003']),array_replace($input,['request_id'=>'agent-parallel-request-0004'])]);$check($independentA['id']!==$independentB['id'],'Different Agent intentions merged');$report['agent_same_key_and_distinct_key_parallel']=true;
    $f->start($script,'fixture_agent_runner','agent');
    MarketWorkerFixture::wait(fn()=>$db::name('aigc_llm_agent_run')->whereIn('id',[$one['id'],$independentA['id'],$independentB['id']])->where('status','<>','success')->count()===0,'Agent document run did not finish',35);$f->stop('agent');
    $doc=$get('detail',['run_id'=>$one['id']]);$artifacts=$get('artifacts',['session_id'=>$one['session_id']]);$check($doc['reply']!==''&&count($artifacts)===1,'Agent document/result writeback missing');
    $before=$calls();$sse=$stream($peer,'aigc_llm.agent/stream'.$suffix,['run_id'=>$one['id']]);$check(count($find($sse,'run.snapshot'))===1&&count($find($sse,'done'))===1&&$calls()===$before,'Agent stream replay generated');$report['agent_worker_document_and_readonly_sse']=true;
    $imageInput=array_replace($input,['request_id'=>'agent-image-plan-request-0002','content'=>'Synthetic image plan']);$planned=$post('submit',$imageInput);$f->start($script,'fixture_agent_runner','agent');
    MarketWorkerFixture::wait(fn()=>$db::name('aigc_llm_agent_run')->where('id',$planned['id'])->value('status')==='awaiting_confirmation','Agent image plan was not saved',35);$f->stop('agent');
    $plan=$get('detail',['run_id'=>$planned['id']]);$check(count($plan['steps'])===1,'Agent plan step missing');$confirmed=$post('confirm',['run_id'=>$plan['id'],'approval_hash'=>$plan['approval_hash']]);$post('confirm',['run_id'=>$plan['id'],'approval_hash'=>$plan['approval_hash']]);
    $f->start($script,'fixture_agent_runner','agent');MarketWorkerFixture::wait(fn()=>(int)$db::name('aigc_llm_agent_step')->where('run_id',$planned['id'])->value('task_id')>0&&$db::name('aigc_llm_agent_run')->where('id',$planned['id'])->value('status')==='waiting_tasks','Agent native task not bound',30);$f->stop('agent',true);
    $f->start($script,'aigc_image');MarketWorkerFixture::wait(fn()=>$db::name('aigc_llm_agent_step')->where('run_id',$planned['id'])->value('task_id')&&$db::name('aigc_image_task')->where('id',$db::name('aigc_llm_agent_step')->where('run_id',$planned['id'])->value('task_id'))->value('status')==='success','Native image not completed while Agent offline',30);$f->start($script,'fixture_agent_runner','agent');
    MarketWorkerFixture::wait(fn()=>$db::name('aigc_llm_agent_run')->where('id',$planned['id'])->value('status')==='success','Agent image result did not finish',35);$f->stop('agent');$f->stop();
    $done=$get('detail',['run_id'=>$planned['id']]);$images=$get('artifacts',['session_id'=>$planned['session_id']]);$check(count($images)===1&&$done['steps'][0]['status']==='success'&&count($done['steps'][0]['artifact_ids'])===1,'Agent image artifact or durable result link missing');$report['agent_image_plan_confirm_native_worker_artifact']=true;
    $history=$get('messages',['session_id'=>$done['session_id']]);$check(count($history)===2,'Agent native message history missing');$again=$post('submit',$imageInput);$check($again['id']===$done['id'],'Agent completed replay duplicated run');
    $oldRun=$db::name('aigc_llm_agent_run')->where('id',$one['id'])->find();$reuse=$post('submit',array_replace($input,['request_id'=>'agent-history-request-0005','session_id'=>$one['session_id']]));$f->start($script,'fixture_agent_runner','agent');MarketWorkerFixture::wait(fn()=>$db::name('aigc_llm_agent_run')->where('id',$reuse['id'])->value('status')==='success','Agent historical new attempt incomplete',35);$f->stop('agent');$check($reuse['id']!==$one['id']&&$oldRun===$db::name('aigc_llm_agent_run')->where('id',$one['id'])->find(),'Agent historical reuse overwrote old run');$report['agent_history_new_run_old_row_preserved']=true;
    $foreignRun=callHttp($peer,'/api/app.aigc_llm.agent/submit'.$suffix,$input,$otherToken);$check(($foreignRun['code']??0)===1&&$foreignRun['data']['id']!==$one['id'],'Agent request key crossed authenticated users');
    $denied=callHttp($peer,'/api/app.aigc_llm.agent/detail'.$suffix.'&run_id='.$one['id'],[],$otherToken,'GET');$check(($denied['code']??1)!==1,'Foreign Agent detail leaked');$f->start($script,'fixture_agent_runner','agent');MarketWorkerFixture::wait(fn()=>$db::name('aigc_llm_agent_run')->where('id',$foreignRun['data']['id'])->value('status')==='success','Other owner Agent did not complete',35);$f->stop('agent');$report['agent_authenticated_owner_isolation']=true;
    dataHttp($api,'/api/app.aigc_llm.session/delete'.$suffix,['session_id'=>$old['session_id']],$token);$before=$calls();$deleted=$stream($peer,'aigc_llm.chat/stream'.$suffix,$p);$check(count($find($deleted,'error'))===1&&$calls()===$before,'Deleted text session recreated');$report['deleted_session_retry_not_regenerated']=true;
    $beforeRows=$db::name('aigc_llm_agent_run')->order('id')->select()->toArray();$f->start($script,'fixture_agent_runner','agent');usleep(400000);$f->stop('agent');$check($beforeRows===$db::name('aigc_llm_agent_run')->order('id')->select()->toArray(),'Agent restart rewrote completed runs');$report['agent_history_sse_restart_preserved']=true;
    $report['text_reservations']=(int)$db::name('ai_consumption_log')->where('app_code','aigc_llm')->count();
    $report['text_settlements']=(int)$db::name('ai_consumption_log')->where('app_code','aigc_llm')->where('billing_status','settled')->count();
    if(getenv('AIGC_HTTP_CANVAS_AGENT_MATRIX')==='1') {
        require __DIR__.'/MarketCanvasAgentHttpMatrix.php';
        $report['canvas_agents']=[];
        runMarketCanvasAgentHttp($f,$api,$peer,$suffix,$token,$imageChannel,$text,$agent,$report['canvas_agents']);
        if(getenv('AIGC_HTTP_AGENT_SPECIAL_MATRIX')==='1') {
            require __DIR__.'/MarketAgentSpecialHttpMatrix.php';$report['special']=[];
            runMarketAgentSpecialHttp($f,$api,$peer,$suffix,$token,$agent,$report['special']);
        }
        $report['text_reservations']=(int)$db::name('ai_consumption_log')->whereIn('product_id',[$text,$agent])->count();
        $report['text_settlements']=(int)$db::name('ai_consumption_log')->whereIn('product_id',[$text,$agent])->where('billing_status','settled')->count();
    }
}
