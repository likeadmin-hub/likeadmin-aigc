<?php
/** Normal-login private HTTP; original decoders, Agent, billing and result services. */
function runMarketAgentMediaBoundaryHttp($f,string $api,string $peer,string $suffix,string &$token,int $text,array &$report): void
{
    $db=think\facade\Db::class;$t=$f->tenant;$u=$f->user;$script=dirname(__DIR__).'/market-generation-http.php';
    $check=fn($ok,$why)=>MarketWorkerFixture::check((bool)$ok,$why);
    $request=function($action,$p=[],string $method='POST')use($api,$peer,$suffix,&$token){
        $path='/api/app.aigc_llm.agent/'.$action.$suffix;
        if($method==='GET')$path.='&'.http_build_query($p);
        $r=callHttp($method==='GET'?$peer:$api,$path,$method==='GET'?[]:$p,$token,$method);
        if(($r['code']??0)!==1)throw new RuntimeException('Boundary HTTP '.$action.': '.($r['msg']??'unknown'));
        return $r['data']??[];
    };
    $pair=function($action,$p)use($api,$peer,$suffix,&$token,$check){
        $multi=curl_multi_init();$handles=[];
        foreach([$api,$peer]as$base){$c=curl_init($base.'/api/app.aigc_llm.agent/'.$action.$suffix);curl_setopt_array($c,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($p),CURLOPT_HTTPHEADER=>['Content-Type: application/json','Host: private-media.example.test','token: '.$token],CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20]);curl_multi_add_handle($multi,$c);$handles[]=$c;}
        do{curl_multi_exec($multi,$active);if($active)curl_multi_select($multi,.01);}while($active);
        $out=[];foreach($handles as$c){$r=json_decode((string)curl_multi_getcontent($c),true);$check(($r['code']??0)===1,'Boundary concurrent '.$action.' refused: '.json_encode($r));$out[]=$r['data'];curl_multi_remove_handle($multi,$c);curl_close($c);}curl_multi_close($multi);
        $check($out[0]['id']===$out[1]['id'],'Boundary concurrent '.$action.' changed run identity');return $out[0];
    };
    $calls=fn($kind)=>array_values(array_filter(array_map(fn($line)=>json_decode($line,true),file($f->dir.'/deep-calls.jsonl')?:[]),fn($row)=>$row['kind']===$kind));
    $counts=fn()=>[(int)$db::name('ai_app_task')->count(),(int)$db::name('ai_consumption_log')->count(),count($calls('agent_text')),count($calls('agent_stt')),count($calls('agent_tts_async'))];
    $login=function()use($peer,$suffix,$f,&$token){$token=dataHttp($peer,'/api/login/account'.$suffix,['account'=>$f->prefix,'password'=>'Private-fixture-pass-2026','scene'=>1,'terminal'=>4])['token'];};
    $upload=function($path,$name,$mime)use($api,$suffix,&$token,$check,$db,$t,$u){
        $c=curl_init($api.'/api/app.aigc_llm.agent/upload'.$suffix);curl_setopt_array($c,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>['file'=>new CURLFile($path,$mime,$name)],CURLOPT_HTTPHEADER=>['token: '.$token],CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20]);$r=json_decode((string)curl_exec($c),true);curl_close($c);$check(($r['code']??0)===1,'Boundary upload failed: '.json_encode($r));
        $row=$db::name('open_platform_file')->where(['tenant_id'=>$t,'user_id'=>$u,'file_id'=>$r['data']['file_id']])->find();
        $check($row&&(int)$row['size']===filesize($path)&&$row['mime']===(new finfo(FILEINFO_MIME_TYPE))->file($path)&&$row['storage_engine']==='local'&&$row['storage_scope']!=='','Boundary upload lost original file facts');return $r['data'];
    };
    $tick=function()use($f,$script){
        @unlink($f->dir.'/agent-once-finished');$f->start($script,'fixture_agent_once','agent');
        try{MarketWorkerFixture::wait(fn()=>is_file($f->dir.'/agent-once-finished'),'One original Agent claim did not complete',45);}
        finally{$f->stop('agent');}
    };
    $finish=function($id,$status='success')use($f,$script,$db){
        $f->start($script,'fixture_agent_runner','agent');
        try{MarketWorkerFixture::wait(fn()=>$db::name('aigc_llm_agent_run')->where('id',$id)->value('status')===$status,'Boundary run did not reach '.$status,70);}catch(Throwable $e){fwrite(STDERR,'Boundary state: '.json_encode($db::name('aigc_llm_agent_run')->where('id',$id)->field('id,status,phase,error')->find(),JSON_UNESCAPED_UNICODE).chr(10));throw $e;}
        finally{$f->stop('agent');}
    };
    $detail=fn($id)=>$request('detail',['run_id'=>$id],'GET');
    $chunks=fn($id,$file)=>array_map(fn($row)=>json_decode($row,true),$db::name('aigc_llm_agent_event')->where(['run_id'=>$id,'event_type'=>'reading.chunk.'.$file])->order('id')->column('payload'));
    $product=$db::name('power_market_product')->where('id',$text)->find();$source=json_decode($product['source_payload'],true);$source['market_metadata']['supports_vision']=true;
    $db::name('power_market_product')->where('id',$text)->update(['source_payload'=>json_encode($source)]);
    $audioProducts=[];
    foreach(['stt','tts','tts_async']as$code){
        $p=app\common\model\power\PowerMarketProduct::create(['product_code'=>$f->prefix.'boundary-'.$code,'name'=>'Private boundary '.$code,'resource_type'=>'app_api','model_type'=>'audio','source_code'=>'isolated-fixture','upstream_resource_key'=>$f->prefix.'boundary-'.$code,'upstream_app_code'=>'voice_tts','upstream_api_code'=>$code,'status'=>1,'source_payload'=>['market_metadata'=>[]]]);
        app\common\model\power\PowerMarketSku::create(['product_id'=>$p['id'],'sku_key'=>$f->prefix.'boundary-'.$code,'title'=>'Private boundary audio','usage_unit'=>$code==='stt'?'per_call':'character','usage_unit_size'=>$code==='stt'?1:1000,'upstream_price'=>1,'sale_points'=>1,'locked_params'=>[],'selectable_params'=>[],'sale_status'=>1,'status'=>1]);$audioProducts[]=(int)$p['id'];
    }
    $input=['session_id'=>0,'preferences'=>['mode'=>'ask','models'=>['text'=>'market_text_'.$text]]];

    // Three real PDF pages exercise two decoder batches, including page rasters.
    $objects=[1=>'<< /Type /Catalog /Pages 2 0 R >>',2=>'<< /Type /Pages /Kids [4 0 R 6 0 R 8 0 R] /Count 3 >>',3=>'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>'];
    foreach([1,2,3]as$n){$id=2+2*$n;$stream='BT /F1 18 Tf 60 720 Td (Synthetic page '.$n.'. Written consent required.) Tj ET';$objects[$id]='<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R >> >> /Contents '.($id+1).' 0 R >>';$objects[$id+1]="<< /Length ".strlen($stream)." >>\nstream\n$stream\nendstream";}
    ksort($objects);$pdf="%PDF-1.4\n";$offsets=[];foreach($objects as$id=>$object){$offsets[]=strlen($pdf);$pdf.=$id." 0 obj\n".$object."\nendobj\n";}$xref=strlen($pdf);$pdf.="xref\n0 10\n0000000000 65535 f \n";foreach($offsets as$offset)$pdf.=sprintf('%010d 00000 n ',$offset)."\n";$pdf.="trailer\n<< /Size 10 /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF";
    file_put_contents($f->dir.'/boundary.pdf',$pdf);
    app\common\service\app\aigc_llm\agent\AgentMediaReader::command(['/usr/bin/ffmpeg','-nostdin','-y','-v','error','-f','lavfi','-i','color=c=red:s=32x32:r=1','-f','lavfi','-i','sine=frequency=440:sample_rate=16000','-t','65','-c:v','libx264','-pix_fmt','yuv420p','-c:a','aac','-threads','1',$f->dir.'/boundary.mp4'],45);
    app\common\service\app\aigc_llm\agent\AgentMediaReader::command(['/usr/bin/ffmpeg','-nostdin','-y','-v','error','-i',$f->dir.'/synthetic.wav','-codec:a','libmp3lame',$f->dir.'/agent-speech.mp3']);
    $pdfAsset=$upload($f->dir.'/boundary.pdf','private-boundary.pdf','application/pdf');$videoAsset=$upload($f->dir.'/boundary.mp4','private-boundary.mp4','video/mp4');

    foreach(['pdf'=>[$pdfAsset,3,[0,2]],'video'=>[$videoAsset,65,[0,30,60]]]as$kind=>[$asset,$total,$expectedOffsets]){
        $p=$input+['request_id'=>'boundary-long-'.$kind.'-0001','content'=>'Synthetic long read '.$kind.' boundary','attachments'=>[$asset['file_id']]];
        $run=$pair('submit',$p);$id=(int)$run['id'];
        for($i=0;$i<5&&count($chunks($id,$asset['file_id']))<1;$i++)$tick();
        $first=$chunks($id,$asset['file_id']);$check(count($first)===1,'First reading checkpoint missing');$before=$counts();
        $stopped=$request('stop',['run_id'=>$id]);$check($stopped['status']==='canceled'&&$counts()===$before,'Checkpoint stop submitted work');
        $login();$paused=$detail($id);$check($paused['readings'][0]['offset']===$expectedOffsets[1],'Fresh login lost saved reading cursor');
        $pair('resumeReading',['run_id'=>$id]);$check($counts()===$before,'Resume dispatched before original worker');$finish($id);
        $done=$detail($id);$saved=$chunks($id,$asset['file_id']);
        $check($done['readings'][0]['status']==='completed'&&(float)$done['readings'][0]['offset']===(float)$total&&array_column($saved,'offset')===$expectedOffsets,'Reading did not cover each original page/time range exactly once');
        $check($first[0]===$saved[0],'Resume rewrote an earlier source segment');
        $before=$counts();$check($request('submit',$p)['id']===$id&&$counts()===$before,'Completed reading replay generated');
        $next=$p;$next['session_id']=$run['session_id'];$next['request_id']='boundary-long-'.$kind.'-history-0002';$new=$pair('submit',$next);$check($new['id']!==$id,'History reading did not create a new intent');$finish((int)$new['id']);
        $check(array_column($chunks($new['id'],$asset['file_id']),'offset')===$expectedOffsets&&$chunks($id,$asset['file_id'])===$saved,'New reading changed old source checkpoints');
        $report[$kind.'_normal_upload_checkpoint_stop_new_login_resume_history']=true;$report[$kind.'_segment_offsets']=$expectedOffsets;
    }
    $visionCalls=array_filter($calls('agent_text'),fn($row)=>str_contains(json_encode($row['input']['messages'],JSON_UNESCAPED_UNICODE),'你负责阅读用户资料'));
    $check(count($visionCalls)===10,'PDF/video batch count changed');
    foreach($visionCalls as$row){$encoded=json_encode($row['input']['messages'],JSON_UNESCAPED_SLASHES);$check(str_contains($encoded,'data:image/jpeg;base64,'),'A visual reading segment lost actual decoded page/frame bytes');}
    $report['pdf_rasters_and_video_frames_reach_declared_vision_model']=true;

    $p=$input+['request_id'=>'boundary-video-stt-0001','content'=>'Synthetic transcription video boundary','attachments'=>[$videoAsset['file_id']]];
    $stt=$pair('submit',$p);$finish((int)$stt['id'],'awaiting_confirmation');$plan=$detail($stt['id']);$confirm=['run_id'=>$stt['id'],'approval_hash'=>$plan['approval_hash']];$pair('confirm',$confirm);$finish((int)$stt['id']);
    $step=$db::name('aigc_llm_agent_step')->where(['run_id'=>$stt['id'],'tool_code'=>'audio_transcription'])->find();$wire=json_decode(file_get_contents($f->dir.'/agent-stt-wire.json'),true);
    $check($step['status']==='success'&&$wire['probe']['has_audio']&&!$wire['probe']['has_video']&&$wire['probe']['duration']>64&&count($calls('agent_stt'))===1,'Video audio was not decoded/submitted once');
    $before=$counts();$check($request('submit',$p)['id']===$stt['id']&&$counts()===$before,'Transcription replay generated');$report['video_audio_real_multipart_confirm_analysis_history_once']=true;

    $speechInput=$input+['request_id'=>'boundary-speech-maximum-0001','content'=>'Synthetic speech maximum boundary'];
    $speech=$pair('submit',$speechInput);$finish((int)$speech['id'],'awaiting_confirmation');$plan=$detail($speech['id']);$step=$plan['steps'][0];
    $check($step['params']['characters']===10000&&$step['params']['execution_mode']==='async','Maximum speech did not select original async service');
    $before=$counts();$bad=callHttp($api,'/api/app.aigc_llm.agent/editPlan'.$suffix,['run_id'=>$speech['id'],'step_id'=>$step['id'],'approval_hash'=>$plan['approval_hash'],'changes'=>['text'=>str_repeat('字',10001)]],$token);
    $check(($bad['code']??1)!==1&&$counts()===$before,'10001 characters crossed quote/submit boundary');
    $pair('confirm',['run_id'=>$speech['id'],'approval_hash'=>$plan['approval_hash']]);$finish((int)$speech['id']);
    $oldStep=$db::name('aigc_llm_agent_step')->where('id',$step['id'])->find();$oldLog=$db::name('ai_consumption_log')->where('app_task_id',$oldStep['task_id'])->find();
    $check((float)$oldLog['quantity']===10000.0&&(float)$oldLog['actual_user_price']===10.0&&$oldLog['billing_status']==='settled','Maximum speech billing differs from its frozen character count');
    $history=$speechInput;$history['session_id']=$speech['session_id'];$history['request_id']='boundary-speech-history-0002';$next=$pair('submit',$history);$finish((int)$next['id'],'awaiting_confirmation');$nextPlan=$detail($next['id']);$pair('confirm',['run_id'=>$next['id'],'approval_hash'=>$nextPlan['approval_hash']]);
    $tick();$accepted=$db::name('aigc_llm_agent_step')->where('run_id',$next['id'])->find();$check((int)$accepted['task_id']>0,'Second speech has no durable accepted task');
    $request('stop',['run_id'=>$next['id']]);$finish((int)$next['id'],'canceled');
    $accepted=$db::name('aigc_llm_agent_step')->where('id',$accepted['id'])->find();$check($accepted['status']==='success'&&count($calls('agent_tts_async'))===2,'Stopped accepted speech lost result or resubmitted');
    foreach([$speech,$next]as$r){
        $arts=$request('artifacts',['session_id'=>$r['session_id']],'GET');$check(count($arts)>=1,'Speech history artifact missing');
        $s=$db::name('aigc_llm_agent_step')->where('run_id',$r['id'])->find();$view=app\common\service\ai\GenerationTaskResultService::read((int)$s['task_id'],$t,$u);
        $check($view['status']==='success'&&count($view['results'])===1&&$view['results'][0]['mime_type']==='audio/mpeg'&&is_file($f->dir.'/public/'.$view['results'][0]['uri']),'Speech managed canonical result missing');
    }
    foreach($calls('agent_tts_async')as$row)$check(mb_strlen($row['input']['text'])===10000&&$row['input']['format']==='mp3','Maximum speech text changed on the wire');
    $check($oldStep===$db::name('aigc_llm_agent_step')->where('id',$oldStep['id'])->find()&&$oldLog===$db::name('ai_consumption_log')->where('id',$oldLog['id'])->find(),'New speech intent changed completed history');
    $report['maximum_10000_async_complete_and_10001_rejected']=true;$report['accepted_speech_stop_restart_retains_paid_result_no_resubmit']=true;
    $canceled=$pair('submit',$input+['request_id'=>'boundary-speech-cancel-plan-0003','content'=>'Synthetic speech canceled before confirmation']);$finish((int)$canceled['id'],'awaiting_confirmation');$before=$counts();$request('stop',['run_id'=>$canceled['id']]);
    $check($detail($canceled['id'])['status']==='canceled'&&$counts()===$before,'Unconfirmed speech stop submitted');$report['unconfirmed_speech_stop_no_native_charge']=true;
    $login();$before=$counts();$check($request('submit',$speechInput)['id']===$speech['id']&&$counts()===$before,'New-login speech replay generated');
    $check(count($request('messages',['session_id'=>$speech['session_id']],'GET'))>=4,'New-login speech history missing');
    $other=$u+1;$check((int)$db::name('user')->where('id',$other)->count()===0,'Foreign fixture owner already exists');
    $db::name('user')->insert(['id'=>$other,'tenant_id'=>$t,'sn'=>$other,'account'=>$f->prefix.'other','nickname'=>'Private second owner','password'=>create_password('Private-fixture-pass-2026',config('project.unique_identification')),'user_money'=>100,'create_time'=>time(),'update_time'=>time()]);
    $otherToken=dataHttp($peer,'/api/login/account'.$suffix,['account'=>$f->prefix.'other','password'=>'Private-fixture-pass-2026','scene'=>1,'terminal'=>4])['token'];$before=$counts();
    $foreign=callHttp($peer,'/api/app.aigc_llm.agent/detail'.$suffix.'&run_id='.$speech['id'],[],$otherToken,'GET');
    $check(($foreign['code']??1)!==1&&$counts()===$before,'Foreign owner read a private run or dispatched work');$report['foreign_normal_login_run_read_rejected']=true;
    $logs=$db::name('ai_consumption_log')->whereIn('product_id',$audioProducts)->select()->toArray();
    $check(count($logs)===3&&array_unique(array_column($logs,'billing_status'))===['settled'],'Original audio reservations did not each settle once');
    $report['audio_original_reservations']=count($logs);$report['audio_original_settlements']=3;$report['normal_login_history_and_native_result_reuse']=true;
}
