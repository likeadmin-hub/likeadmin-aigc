<?php
/** Native long-reading, confirmed STT and default-voice TTS with private receipts only. */
function runMarketAgentSpecialHttp($f,string $api,string $peer,string $suffix,string $token,int $text,array &$report): void
{
    if (getenv('AIGC_HTTP_AGENT_MEDIA_BOUNDARY_MATRIX') === '1') {
        require __DIR__.'/MarketAgentMediaBoundaryHttpMatrix.php';
        runMarketAgentMediaBoundaryHttp($f,$api,$peer,$suffix,$token,$text,$report);
        return;
    }
    $db=think\facade\Db::class;$script=dirname(__DIR__).'/market-generation-http.php';$t=$f->tenant;$u=$f->user;
    $check=fn($ok,$why)=>MarketWorkerFixture::check((bool)$ok,$why);
    $post=fn($action,$p)=>dataHttp($api,'/api/app.aigc_llm.agent/'.$action.$suffix,$p,$token);
    $get=fn($action,$p)=>dataHttp($peer,'/api/app.aigc_llm.agent/'.$action.$suffix.'&'.http_build_query($p),[],$token,'GET');
    $count=fn($kind)=>count(array_filter(array_map(fn($l)=>json_decode($l,true),file($f->dir.'/deep-calls.jsonl')?:[]),fn($r)=>$r['kind']===$kind));
    $upload=function(string $path,string $name,string $mime)use($api,$suffix,$token,$check,$db,$t,$u):array {
        $c=curl_init($api.'/api/app.aigc_llm.agent/upload'.$suffix);curl_setopt_array($c,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>['file'=>new CURLFile($path,$mime,$name)],CURLOPT_HTTPHEADER=>['token: '.$token],CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20]);$r=json_decode((string)curl_exec($c),true);curl_close($c);$check(($r['code']??0)===1,'Agent HTTP asset upload failed: '.json_encode($r));
        $row=$db::name('open_platform_file')->where(['tenant_id'=>$t,'user_id'=>$u,'file_id'=>$r['data']['file_id']])->find();
        $check($row&&(int)$row['size']===filesize($path)&&$row['mime']===(new finfo(FILEINFO_MIME_TYPE))->file($path)&&$row['storage_scope']!==''&&$row['storage_engine']==='local','Moved upload lost MIME/size/owner/storage facts');return $r['data'];
    };
    $finish=function(int $id,string $status='success')use($f,$script,$db):void {
        $f->start($script,'fixture_agent_runner','agent');
        try{MarketWorkerFixture::wait(fn()=>$db::name('aigc_llm_agent_run')->where('id',$id)->value('status')===$status,'Dedicated Agent run did not reach '.$status,45);}
        catch(Throwable $error){fwrite(STDERR,'Dedicated run state: '.json_encode($db::name('aigc_llm_agent_run')->where('id',$id)->field('id,status,phase,error')->find(),JSON_UNESCAPED_UNICODE)."\n");throw $error;}
        finally{$f->stop('agent');}
    };
    $input=['session_id'=>0,'preferences'=>['mode'=>'ask','models'=>['text'=>'market_text_'.$text]]];
    file_put_contents($f->dir.'/long-source.txt',str_repeat('隔离资料事实。',2200));
    $doc=$upload($f->dir.'/long-source.txt','private-long-source.txt','text/plain');
    $p=$input+['request_id'=>'agent-long-reading-http-0001','content'=>'Synthetic long read','attachments'=>[$doc['file_id']]];
    $run=$post('submit',$p);$check($post('submit',$p)['id']===$run['id'],'Long read admission duplicated run');$finish((int)$run['id']);
    $done=$get('detail',['run_id'=>$run['id']]);$check(count($done['readings'])===1&&$done['readings'][0]['status']==='completed'&&$done['readings'][0]['offset']===15400,'Long read did not consume full source');
    $segments=(int)$db::name('aigc_llm_agent_event')->where(['run_id'=>$run['id'],'event_type'=>'reading.chunk.'.$doc['file_id']])->count();$check($segments>=2,'Long read did not persist segment evidence');
    $before=$count('agent_text');$old=$db::name('aigc_llm_agent_run')->where('id',$run['id'])->find();$finish((int)$run['id']);
    $check($old===$db::name('aigc_llm_agent_run')->where('id',$run['id'])->find()&&$count('agent_text')===$before&&$post('submit',$p)['id']===$run['id'],'Completed long read restarted or recharged');
    $check(count($get('messages',['session_id'=>$run['session_id']]))===2,'Long read native message history missing');$report['long_read_http_upload_full_segments_history_no_reread']=true;$report['long_read_segments']=$segments;

    $products=[];
    foreach(['stt','tts','tts_async']as$code) {
        $product=app\common\model\power\PowerMarketProduct::create(['product_code'=>$f->prefix.'agent-'.$code,'name'=>'Private '.$code,'resource_type'=>'app_api','model_type'=>'audio','source_code'=>'isolated-fixture','upstream_resource_key'=>$f->prefix.'agent-'.$code,'upstream_app_code'=>'voice_tts','upstream_api_code'=>$code,'status'=>1,'source_payload'=>['market_metadata'=>[]]]);
        app\common\model\power\PowerMarketSku::create(['product_id'=>$product['id'],'sku_key'=>$f->prefix.'agent-'.$code,'title'=>'Private '.$code,'usage_unit'=>$code==='stt'?'per_call':'character','usage_unit_size'=>$code==='stt'?1:1000,'upstream_price'=>1,'sale_points'=>1,'locked_params'=>[],'selectable_params'=>[],'sale_status'=>1,'status'=>1]);$products[]=(int)$product['id'];
    }
    $audio=$upload($f->dir.'/synthetic.wav','private-synthetic.wav','audio/wav');
    $transcribe=$input+['request_id'=>'agent-transcription-http-0002','content'=>'Synthetic transcription','attachments'=>[$audio['file_id']]];
    $plan=$post('submit',$transcribe);$finish((int)$plan['id'],'awaiting_confirmation');$detail=$get('detail',['run_id'=>$plan['id']]);
    $check(count($detail['steps'])===1&&$detail['steps'][0]['tool_code']==='audio_transcription','Transcription plan missing');
    $confirmation=['run_id'=>$plan['id'],'approval_hash'=>$detail['approval_hash']];$post('confirm',$confirmation);$post('confirm',$confirmation);$finish((int)$plan['id']);
    $step=$db::name('aigc_llm_agent_step')->where(['run_id'=>$plan['id'],'tool_code'=>'audio_transcription'])->find();
    $result=json_decode($step['result'],true);$check($step['status']==='success'&&str_contains(json_encode($result,JSON_UNESCAPED_UNICODE),'隔离音频转写正文。')&&$count('agent_stt')===1,'Transcription native result missing or submitted twice');
    $before=$count('agent_text');$check($post('submit',$transcribe)['id']===$plan['id']&&count($get('messages',['session_id'=>$plan['session_id']]))===2&&$count('agent_stt')===1&&$count('agent_text')===$before,'Transcription history replay regenerated');$report['stt_upload_plan_confirm_resume_answer_history_once']=true;

    app\common\service\app\aigc_llm\agent\AgentMediaReader::command(['/usr/bin/ffmpeg','-nostdin','-y','-v','error','-i',$f->dir.'/synthetic.wav','-codec:a','libmp3lame',$f->dir.'/agent-speech.mp3']);
    foreach(['short','long']as$length) {
        $p=$input+['request_id'=>'agent-speech-'.$length.'-http-0003','content'=>'Synthetic speech '.$length];$plan=$post('submit',$p);$finish((int)$plan['id'],'awaiting_confirmation');$detail=$get('detail',['run_id'=>$plan['id']]);
        $step=$detail['steps'][0]??[];$check(($step['tool_code']??'')==='speech_synthesis'&&($step['params']['characters']??0)===($length==='long'?501:7),'Default speech routing or character quote changed');
        $confirmation=['run_id'=>$plan['id'],'approval_hash'=>$detail['approval_hash']];$post('confirm',$confirmation);$post('confirm',$confirmation);$finish((int)$plan['id']);
        $art=$get('artifacts',['session_id'=>$plan['session_id']]);$check(count($art)===1&&$art[0]['kind']==='audio'&&!empty($art[0]['file_id']),'Speech audio artifact not collected');
        $asset=$db::name('open_platform_file')->where(['tenant_id'=>$t,'user_id'=>$u,'file_id'=>$art[0]['file_id']])->find();
        $check($asset&&$asset['mime']==='audio/mpeg'&&is_file($f->dir.'/public/'.$asset['uri']),'Speech managed MP3 bytes/storage missing');
        $native=$db::name('aigc_llm_agent_step')->where('run_id',$plan['id'])->value('task_id');
        $view=app\common\service\ai\GenerationTaskResultService::read((int)$native,$t,$u);
        $report['tts_'.$length.'_unified_result_metadata']=$view['status']==='success'&&count($view['results'])===1&&$view['results'][0]['mime_type']==='audio/mpeg'&&$view['results'][0]['file_size']===(int)$asset['size'];
        $check($report['tts_'.$length.'_unified_result_metadata'],'Speech unified result lost MIME or byte size');
        $before=$count('agent_text');$check($post('submit',$p)['id']===$plan['id']&&$count($length==='long'?'agent_tts_async':'agent_tts')===1&&$count('agent_text')===$before,'Speech completed replay resubmitted');
        $report['tts_'.$length.'_default_voice_confirm_mp3_history_once']=true;
    }
    $report['reservations']=(int)$db::name('ai_consumption_log')->whereIn('product_id',$products)->count();$report['settlements']=(int)$db::name('ai_consumption_log')->whereIn('product_id',$products)->where('billing_status','settled')->count();
    $check($report['reservations']===3&&$report['settlements']===3,'Dedicated audio billing did not settle each original reservation exactly once');
    foreach($db::name('ai_consumption_log')->whereIn('product_id',$products)->select()->toArray()as$row)$check($row['run_status']==='success'&&(float)$row['actual_user_price']===(float)$row['reserved_user_price']&&(float)$row['actual_tenant_cost']===(float)$row['reserved_tenant_cost'],'Dedicated audio final charge differs from its original fixed-unit reservation');
    $report['native_billing_three_original_fixed_reservations_settled']=true;
}
