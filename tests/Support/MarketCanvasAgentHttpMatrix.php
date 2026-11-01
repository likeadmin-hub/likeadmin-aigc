<?php
/** Two native Agent workspaces through authenticated HTTP and their original workers. */
function runMarketCanvasAgentHttp($f,string $api,string $peer,string $suffix,string $token,string $image,int $text,int $agent,array &$report): void
{
    $db=think\facade\Db::class;$t=$f->tenant;$u=$f->user;$script=dirname(__DIR__).'/market-generation-http.php';
    $check=fn($ok,$why)=>MarketWorkerFixture::check((bool)$ok,$why);
    $post=fn($path,$p)=>dataHttp($api,'/api/app.'.$path.$suffix,$p,$token);
    $get=fn($path,$p)=>dataHttp($peer,'/api/app.'.$path.$suffix.'&'.http_build_query($p),[],$token,'GET');
    $calls=fn()=>count(file($f->dir.'/deep-calls.jsonl')?:[]);
    $pair=function(string $path,array $bodies)use($api,$peer,$suffix,$token):array {
        $multi=curl_multi_init();$handles=[];
        foreach([$api,$peer]as$i=>$base){$c=curl_init($base.'/api/app.'.$path.$suffix);curl_setopt_array($c,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($bodies[$i]),CURLOPT_HTTPHEADER=>['Content-Type: application/json','token: '.$token],CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20]);curl_multi_add_handle($multi,$c);$handles[]=$c;}
        do{curl_multi_exec($multi,$active);if($active)curl_multi_select($multi,.01);}while($active);
        $out=[];foreach($handles as$c){$r=json_decode((string)curl_multi_getcontent($c),true);$out[]=$r;curl_multi_remove_handle($multi,$c);curl_close($c);}curl_multi_close($multi);return $out;
    };
    $same=function(string $path,array $p)use($pair,$check):array {
        [$a,$b]=$pair($path,[$p,$p]);$check(($a['code']??0)===1&&($b['code']??0)===1,'Concurrent '.$path.' rejected: '.json_encode([$a,$b]));$check($a['data']===$b['data'],'Concurrent '.$path.' returned different identities');return $a['data'];
    };
    $waitAgent=function(int $id,string $status='success')use($db,$f,$script):void {
        $f->start($script,'fixture_agent_runner','agent');
        MarketWorkerFixture::wait(fn()=>$db::name('aigc_llm_agent_run')->where('id',$id)->value('status')===$status,'Canvas Agent did not reach '.$status,35);$f->stop('agent');
    };
    $input=['request_id'=>'canvas-entry-concurrent-0001','content'=>'Synthetic page text','preferences'=>['mode'=>'ask','models'=>['image'=>$image,'text'=>'market_text_'.$agent]]];
    $projects=(int)$db::name('aigc_canvas_project')->count();$entry=$same('aigc_canvas.conversation/start',$input);
    $check((int)$db::name('aigc_canvas_project')->count()===$projects+1,'Concurrent canvas entry created an orphan project');
    $report['canvas_first_message_same_key_one_project_run']=true;
    $bad=callHttp($peer,'/api/app.aigc_canvas.conversation/start'.$suffix,array_replace($input,['preferences'=>['mode'=>'auto']]),$token);
    $check(($bad['code']??1)!==1,'Canvas entry changed preferences accepted');$report['canvas_entry_changed_parameters_refused']=true;
    $scope=['project_id'=>$entry['project_id'],'page_id'=>$entry['page_id']];$waitAgent((int)$entry['run_id']);
    $page=$get('aigc_canvas.project/detail',['id'=>$entry['project_id']]);
    $check(count($page['nodes'])===1&&$page['nodes'][0]['metadata']['content']==='隔离画布文字正文。','Canvas text tool did not persist its node');
    $firstNode=$page['nodes'][0];$oldRun=$db::name('aigc_llm_agent_run')->where('id',$entry['run_id'])->find();$before=$calls();
    $check($post('aigc_canvas.conversation/start',$input)===$entry&&$calls()===$before,'Completed canvas entry replay submitted again');
    $messages=$get('aigc_canvas.conversation/messages',$scope+['session_id'=>$entry['session_id']]);
    $check(count($messages)===2,'Canvas native conversation history missing');$report['canvas_text_tool_writeback_history_replay']=true;
    $home=callHttp($peer,'/api/app.aigc_llm.agent/detail'.$suffix.'&run_id='.$entry['run_id'],[],$token,'GET');
    $wrong=callHttp($peer,'/api/app.aigc_canvas.conversation/detail'.$suffix.'&'.http_build_query(array_replace($scope,['page_id'=>'missing','run_id'=>$entry['run_id']])),[],$token,'GET');
    $check(($home['code']??1)!==1&&($wrong['code']??1)!==1,'Canvas run escaped workspace scope');$report['canvas_home_and_page_scopes_isolated']=true;
    $next=$post('aigc_canvas.conversation/submit',$scope+['session_id'=>$entry['session_id'],'request_id'=>'canvas-history-image-0002','content'=>'Synthetic image plan','preferences'=>$input['preferences']]);
    $waitAgent((int)$next['id'],'awaiting_confirmation');$plan=$get('aigc_canvas.conversation/detail',$scope+['run_id'=>$next['id']]);
    $confirm=$scope+['run_id'=>$next['id'],'approval_hash'=>$plan['approval_hash']];$post('aigc_canvas.conversation/confirm',$confirm);$post('aigc_canvas.conversation/confirm',$confirm);
    $f->start($script,'fixture_agent_runner','agent');
    MarketWorkerFixture::wait(fn()=>$db::name('aigc_llm_agent_run')->where('id',$next['id'])->value('status')==='waiting_tasks','Canvas image not bound before restart',35);$f->stop('agent',true);
    $f->start($script,'aigc_image');$f->start($script,'fixture_agent_runner','agent');
    MarketWorkerFixture::wait(fn()=>$db::name('aigc_llm_agent_run')->where('id',$next['id'])->value('status')==='success','Canvas image did not recover',35);$f->stop('agent');$f->stop();
    $page=$get('aigc_canvas.project/detail',['id'=>$entry['project_id']]);$artifacts=$get('aigc_canvas.conversation/artifacts',$scope+['session_id'=>$entry['session_id']]);
    $check(count($artifacts)===1&&count($page['nodes'])===2&&$page['nodes'][0]===$firstNode,'Canvas artifact or original text node changed');
    $check($page['nodes'][1]['metadata']['agentArtifactId']===$artifacts[0]['id']&&!empty($page['nodes'][1]['metadata']['url']),'Canvas artifact link missing');
    $check($oldRun===$db::name('aigc_llm_agent_run')->where('id',$entry['run_id'])->find(),'Historical canvas reuse rewrote original run');
    $report['canvas_image_confirm_worker_restart_node_artifact']=true;$report['canvas_history_new_run_old_result_unchanged']=true;
    $rows=$db::name('aigc_canvas_project')->where('id',$entry['project_id'])->find();$f->start($script,'fixture_agent_runner','agent');usleep(300000);$f->stop('agent');
    $check($rows===$db::name('aigc_canvas_project')->where('id',$entry['project_id'])->find(),'Idle Agent restart rewrote canvas result');$report['canvas_idle_restart_no_duplicate_nodes']=true;

    // No workflow execution is needed for the legacy text boundary; Neuron is exercised separately below.
    $cfg=['default_text_model_id'=>'market_text_'.$text,'canvas_agent'=>['enabled'=>true,'execution_enabled'=>true,'dialogue_engine'=>'legacy','workflow'=>['enabled'=>false]]];
    if(!$db::name('aigc_short_drama_config')->where('tenant_id',$t)->count())$db::name('aigc_short_drama_config')->insert(['tenant_id'=>$t,'status'=>1,'config_json'=>'{}','create_time'=>time(),'update_time'=>time()]);
    $db::name('aigc_short_drama_config')->where('tenant_id',$t)->update(['config_json'=>json_encode($cfg)]);
    $canvas=$post('aigc_short_drama.canvas/create',['title'=>'Private Agent dialogue']);
    $canvas=$post('aigc_short_drama.canvas/save',['id'=>$canvas['id'],'expected_revision'=>$canvas['graph_revision'],'nodes'=>[['id'=>'1','type'=>'text','title'=>'Source','position'=>['x'=>0,'y'=>0],'metadata'=>['content'=>'原始文字','prompt'=>'原始提示词','content_revision'=>1]]],'edges'=>[],'viewport'=>[]]);
    $c=(int)$canvas['id'];$thread=$post('aigc_short_drama.canvas_agent/createThread',['canvas_id'=>$c,'request_key'=>'drama-thread-one','title'=>'Private conversation']);
    $body=['canvas_id'=>$c,'thread_id'=>$thread['id'],'request_key'=>'drama-parallel-message-0001','content'=>'Synthetic drama reply','selected_node_ids'=>['1'],'base_revision'=>(int)$canvas['graph_revision'],'preferences'=>['generation_mode'=>'manual']];
    $ack=$same('aigc_short_drama.canvas_agent/send',$body);$report['drama_same_key_parallel_one_run_outbox']=true;
    $bad=callHttp($peer,'/api/app.aigc_short_drama.canvas_agent/send'.$suffix,array_replace($body,['preferences'=>['generation_mode'=>'auto']]),$token);
    $check(($bad['code']??1)!==1,'Short drama changed generation preferences accepted');
    $badAuto=callHttp($peer,'/api/app.aigc_short_drama.canvas_agent/send'.$suffix,array_replace($body,['workflow_auto'=>true]),$token);
    $report['drama_changed_auto_intent_refused']=($badAuto['code']??1)!==1;
    $f->start($script,'fixture_drama_agent_runner','drama');
    MarketWorkerFixture::wait(fn()=>$db::name('aigc_short_drama_canvas_agent_run')->where('id',$ack['run_id'])->value('status')==='success','Short drama reply did not complete',35);$f->stop('drama');
    $run=$db::name('aigc_short_drama_canvas_agent_run')->where('id',$ack['run_id'])->find();$before=$calls();
    $messages=$get('aigc_short_drama.canvas_agent/messages',['canvas_id'=>$c,'thread_id'=>$thread['id']]);
    $check(count($messages)===2&&$post('aigc_short_drama.canvas_agent/send',$body)===$ack&&$calls()===$before,'Short drama local history replay generated');
    $report['drama_worker_reply_local_history_replay']=true;
    $patch=['canvas_id'=>$c,'expected_revision'=>$canvas['graph_revision'],'request_key'=>'drama-writeback-0001','operations'=>[['op'=>'apply_agent_text','node_id'=>'1','run_id'=>(string)$ack['run_id']]]];
    $patched=$post('aigc_short_drama.canvas/patch',$patch);
    $check($patched['nodes'][0]['metadata']['content']==='隔离文字回复🙂','Short drama selected text writeback failed');
    $check($post('aigc_short_drama.canvas/patch',$patch)===$patched,'Short drama graph retry changed original mutation');$report['drama_selected_text_atomic_writeback_replay']=true;
    $again=$post('aigc_short_drama.canvas_agent/send',array_replace($body,['request_key'=>'drama-history-message-0002','base_revision'=>$patched['graph_revision']]));
    $thread2=$post('aigc_short_drama.canvas_agent/createThread',['canvas_id'=>$c,'request_key'=>'drama-thread-two','title'=>'Other conversation']);
    $other=$post('aigc_short_drama.canvas_agent/send',array_replace($body,['thread_id'=>$thread2['id'],'request_key'=>'drama-parallel-message-0003','base_revision'=>$patched['graph_revision']]));
    $f->start($script,'fixture_drama_agent_runner','drama_a');$f->start($script,'fixture_drama_agent_runner','drama_b');
    MarketWorkerFixture::wait(fn()=>$db::name('aigc_short_drama_canvas_agent_run')->whereIn('id',[$again['run_id'],$other['run_id']])->where('status','<>','success')->count()===0,'Concurrent short drama workers incomplete',35);$f->stop('drama_a');$f->stop('drama_b');
    $check($run===$db::name('aigc_short_drama_canvas_agent_run')->where('id',$ack['run_id'])->find(),'Short drama history overwrote old run');$report['drama_independent_threads_workers_history_new_attempt']=true;
    $cfg['canvas_agent']['dialogue_engine']='neuron_v1';$db::name('aigc_short_drama_config')->where('tenant_id',$t)->update(['config_json'=>json_encode($cfg)]);
    $neuron=$post('aigc_short_drama.canvas_agent/send',array_replace($body,['request_key'=>'drama-neuron-message-0004','base_revision'=>$patched['graph_revision']]));
    $f->start($script,'fixture_drama_agent_runner','drama');MarketWorkerFixture::wait(fn()=>$db::name('aigc_short_drama_canvas_agent_run')->where('id',$neuron['run_id'])->value('status')==='success','Neuron short drama decision incomplete',35);$f->stop('drama');
    $check((int)$db::name('aigc_short_drama_canvas_agent_event')->where(['run_id'=>$neuron['run_id'],'kind'=>'agent.decision'])->count()===1,'Neuron decision was not committed');$report['drama_neuron_shared_runtime_decision_writeback']=true;
    $login=dataHttp($peer,'/api/login/account'.$suffix,['account'=>$f->prefix,'password'=>'Private-fixture-pass-2026','scene'=>1,'terminal'=>4]);
    $reopen=dataHttp($peer,'/api/app.aigc_canvas.project/detail'.$suffix.'&id='.$entry['project_id'],[],$login['token'],'GET');
    $drama=dataHttp($peer,'/api/app.aigc_short_drama.canvas/current'.$suffix.'&id='.$c,[],$login['token'],'GET');
    $check($reopen['nodes']===$page['nodes']&&$drama['nodes']===$patched['nodes'],'Normal-login Agent project reopen lost stored nodes');$report['both_projects_new_login_reopen_results']=true;
    $foreign=dataHttp($peer,'/api/login/account'.$suffix,['account'=>$f->prefix.'other','password'=>'Private-fixture-pass-2026','scene'=>1,'terminal'=>4])['token'];
    foreach(['aigc_canvas.conversation/detail'.$suffix.'&'.http_build_query($scope+['run_id'=>$entry['run_id']]),'aigc_short_drama.canvas_agent/messages'.$suffix.'&canvas_id='.$c.'&thread_id='.$thread['id']]as$path)$check((callHttp($peer,'/api/app.'.$path,[],$foreign,'GET')['code']??1)!==1,'Foreign Agent history leaked');
    $report['both_agents_authenticated_owner_isolation']=true;
    $beforeRows=$db::name('aigc_short_drama_canvas_agent_run')->order('id')->select()->toArray();$f->start($script,'fixture_drama_agent_runner','drama');usleep(300000);$f->stop('drama');$check($beforeRows===$db::name('aigc_short_drama_canvas_agent_run')->order('id')->select()->toArray(),'Idle drama restart rewrote completed turns');$report['drama_idle_restart_preserves_terminal']=true;
    $legacyIdentity=['thread'=>$thread['id'],'content'=>$body['content'],'nodes'=>['1'],'revision'=>$body['base_revision'],'selection'=>['preferences'=>['generation_mode'=>'manual'],'skill_id'=>0,'skill_version'=>0]];
    $legacyHash=hash('sha256',json_encode($legacyIdentity,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    $db::name('aigc_short_drama_canvas_agent_run')->where('id',$ack['run_id'])->update(['request_hash'=>$legacyHash]);
    $before=$calls();$check($post('aigc_short_drama.canvas_agent/send',$body)===$ack&&$calls()===$before,'Pre-upgrade short drama request hash could not replay');
    $db::name('aigc_short_drama_canvas_agent_run')->where('id',$ack['run_id'])->update(['request_hash'=>$run['request_hash']]);$report['drama_legacy_request_identity_replay']=true;
    $check($report['drama_changed_auto_intent_refused'],'Short drama same key accepted a different workflow_auto intent');
}
