<?php
/** Existing mobile upload/controllers -> native long read/STT -> original billing/history. */
function runMarketAgentMobileMediaHttp($f,string $api,string $peer,string $suffix,string $token,int $text,array &$report): void
{
    $db=think\facade\Db::class;$check=fn($ok,$why)=>MarketWorkerFixture::check((bool)$ok,$why);
    require_once __DIR__.'/MarketAgentMediaSources.php';marketAgentMediaSources($f);
    $docText=str_repeat('Synthetic DOCX fact. Written consent required. ',350);
    $zip=new ZipArchive();$check($zip->open($f->dir.'/source.docx',ZipArchive::CREATE)===true,'Cannot create synthetic DOCX');
    $zip->addFromString('[Content_Types].xml','<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
    $zip->addFromString('_rels/.rels','<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
    $zip->addFromString('word/document.xml','<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>'.$docText.'</w:t></w:r></w:p></w:body></w:document>');$zip->close();
    $product=$db::name('power_market_product')->where('id',$text)->find();$source=json_decode($product['source_payload'],true);$source['market_metadata']['supports_vision']=true;$db::name('power_market_product')->where('id',$text)->update(['source_payload'=>json_encode($source)]);
    $stt=app\common\model\power\PowerMarketProduct::create(['product_code'=>$f->prefix.'mobile-stt','name'=>'Private mobile STT','resource_type'=>'app_api','model_type'=>'audio','source_code'=>'isolated-fixture','upstream_resource_key'=>$f->prefix.'mobile-stt','upstream_app_code'=>'voice_tts','upstream_api_code'=>'stt','status'=>1,'source_payload'=>['market_metadata'=>[]]]);
    app\common\model\power\PowerMarketSku::create(['product_id'=>$stt['id'],'sku_key'=>$f->prefix.'mobile-stt','title'=>'Private STT','usage_unit'=>'per_call','usage_unit_size'=>1,'upstream_price'=>1,'sale_points'=>1,'locked_params'=>[],'selectable_params'=>[],'sale_status'=>1,'status'=>1]);
    $login=fn($terminal)=>dataHttp($peer,'/api/login/account'.$suffix,['account'=>$f->prefix,'password'=>'Private-fixture-pass-2026','scene'=>1,'terminal'=>$terminal])['token'];
    $other=$f->user+1;$db::name('user')->insert(['id'=>$other,'tenant_id'=>$f->tenant,'sn'=>$other,'account'=>$f->prefix.'other','nickname'=>'Private other','password'=>create_password('Private-fixture-pass-2026',config('project.unique_identification')),'user_money'=>100,'create_time'=>time(),'update_time'=>time()]);
    $tokens=['h5'=>$login(3),'mp'=>$login(1),'other'=>dataHttp($peer,'/api/login/account'.$suffix,['account'=>$f->prefix.'other','password'=>'Private-fixture-pass-2026','scene'=>1,'terminal'=>3])['token']];
    $files=[];foreach(['pdf'=>'boundary.pdf','docx'=>'source.docx','video'=>'boundary.mp4','audio'=>'synthetic.wav']as$kind=>$name){$path=$f->dir.'/'.$name;$files[$kind]=['path'=>$path,'name'=>'b74-source.'.pathinfo($name,PATHINFO_EXTENSION),'mime'=>(new finfo(FILEINFO_MIME_TYPE))->file($path),'size'=>filesize($path),'sha256'=>hash_file('sha256',$path),'bytes'=>base64_encode(file_get_contents($path))];}
    $input=['api'=>$api,'api_peer'=>$peer,'tenant'=>$f->tenant,'account'=>$f->prefix,'tokens'=>$tokens,'ui'=>getenv('AIGC_HTTP_AGENT_MOBILE_ACTIONS_UI')==='1','files'=>$files];
    file_put_contents($f->dir.'/mobile-input.json',json_encode($input,JSON_THROW_ON_ERROR));
    $wait=function($name,$seconds=180)use($f){$row=null;MarketWorkerFixture::wait(function()use($f,$name,&$row){if(is_file($f->dir.'/mobile-failed.json'))throw new RuntimeException(file_get_contents($f->dir.'/mobile-failed.json'));return is_file($f->dir.'/'.$name)&&($row=json_decode(file_get_contents($f->dir.'/'.$name),true));},'Mobile media phase missing: '.$name,$seconds);return $row;};
    $snapshot=function()use($db){$out=[];foreach(['aigc_llm_agent_run','aigc_llm_agent_step','aigc_llm_agent_event','aigc_llm_message','ai_consumption_log','open_platform_file']as$table)$out[$table]=$db::name($table)->order('id')->select()->toArray();return $out;};
    $f->start(dirname(__DIR__).'/market-generation-http.php','fixture_agent_runner','agent');
    try{
        $uiRows=[];
        if($input['ui']){fwrite(STDERR,'MOBILE_AGENT_MEDIA_UI_READY '.$f->dir.chr(10));$report['actual_h5_existing_upload_read_transcribe_history']=$wait('mobile-ui-done.json',600);$uiRows=$snapshot();$check(count($uiRows['aigc_llm_agent_run'])===6,'UI must complete five workflows and one explicit history reuse');}
        $input['tokens']['h5']=$login(3);$input['tokens']['mp']=$login(1);$input['ui']=false;
        file_put_contents($f->dir.'/mobile-input.json',json_encode($input,JSON_THROW_ON_ERROR));fwrite(STDERR,'MOBILE_AGENT_MEDIA_READY '.$f->dir.chr(10));
        $host=$wait('mobile-done.json',300);$rows=$snapshot();
        foreach($uiRows as$table=>$old)foreach($old as$row)$check(in_array($row,$rows[$table],true),'Host stage rewrote UI history '.$table);
        $check(count($host['run_ids'])===10,'Host did not complete all five history pairs');
        $check(count($rows['aigc_llm_agent_run'])===count($uiRows['aigc_llm_agent_run']??[])+10,'Unexpected mobile intention count');
        $check(count($rows['open_platform_file'])===(empty($uiRows)?4:8),'Managed input history reuploaded original files');
        $validated=[];
        foreach($db::name('aigc_llm_agent_run')->order('id')->select()->toArray()as$run){
            $check($run['status']==='success','Mobile source workflow not successful');
            $attachments=json_decode($run['attachments'],true);$check(count($attachments)===4,'Mixed mobile attachments lost');
            foreach($attachments as$i=>$asset){$file=array_values($files)[$i];$stored=$db::name('open_platform_file')->where(['tenant_id'=>$f->tenant,'user_id'=>$f->user,'file_id'=>$asset['file_id']])->find();$check($stored&&$stored['mime']===$file['mime']&&(int)$stored['size']===$file['size']&&$stored['storage_scope']!==''&&$stored['storage_engine']==='local'&&hash_file('sha256',$f->dir.'/public/'.$stored['uri'])===$file['sha256'],'Mobile upload lost source bytes/MIME/storage/owner/order');}
            $params=json_decode($run['preferences'],true);
            if(str_contains($run['content'],'long read')){
                preg_match('/long read (pdf|docx|video)/',$run['content'],$m);$kind=$m[1];$index=['pdf'=>0,'docx'=>1,'video'=>2][$kind];$file=$attachments[$index]['file_id'];$job=$params['_long_reads'][$file]??[];
                $check(($job['status']??'')==='completed','Mobile long read missing full cursor');
                $chunks=array_map(fn($r)=>json_decode($r,true),$db::name('aigc_llm_agent_event')->where(['run_id'=>$run['id'],'event_type'=>'reading.chunk.'.$file])->order('id')->column('payload'));
                if($kind==='pdf'){foreach([1,2,3]as$page)$check(substr_count(implode('',array_column($chunks,'source_text')),'Synthetic page '.$page.'.')===1,'Mobile PDF source page lost/duplicated');$check(array_column($chunks,'offset')===[0,2],'Mobile PDF decoder batch mismatch');}
                elseif($kind==='docx')$check(implode('',array_column($chunks,'source_text'))===trim($docText),'Mobile DOCX extraction lost original text');
                else $check(array_column($chunks,'offset')===[0,30,60]&&(float)$job['offset']===65.0,'Mobile video sampling missed a segment');
                $validated[]=['run_id'=>$run['id'],'source'=>$kind,'segments'=>count($chunks),'status'=>'passed'];
            }else{
                $step=$db::name('aigc_llm_agent_step')->where('run_id',$run['id'])->find();$result=json_decode($step['result']??'',true);$log=$db::name('ai_consumption_log')->where('app_task_id',$step['task_id'])->find();
                $check($step['tool_code']==='audio_transcription'&&$step['status']==='success'&&str_contains(json_encode($result,JSON_UNESCAPED_UNICODE),'隔离音频转写正文。')&&$log['billing_status']==='settled'&&(float)$log['actual_user_price']===(float)$log['reserved_user_price'],'Mobile original STT result/frozen settlement missing');
                $validated[]=['run_id'=>$run['id'],'source'=>str_contains($run['content'],'transcription video')?'video_audio':'audio','app_task_id'=>$step['task_id'],'status'=>'passed'];
            }
        }
        $wires=array_map(fn($l)=>json_decode($l,true),file($f->dir.'/mobile-stt-wires.jsonl')?:[]);$check(count($wires)===$db::name('aigc_llm_agent_step')->count(),'STT transport submitted more than once per native step');
        foreach($wires as$wire)$check($wire['probe']['has_audio']&&!$wire['probe']['has_video']&&str_starts_with($wire['mime'],'audio/'),'STT did not receive real decoded audio bytes');
        $videoTranscriptions=count(array_filter($validated,fn($r)=>$r['source']==='video_audio'));
        $check(count(array_filter($wires,fn($w)=>$w['probe']['duration']>64))===$videoTranscriptions,'Video STT lost full audio duration');
        $calls=array_map(fn($l)=>json_decode($l,true),file($f->dir.'/deep-calls.jsonl')?:[]);$visual=[];
        foreach($calls as$call)if($call['kind']==='agent_text'&&str_contains(json_encode($call['input']['messages'],JSON_UNESCAPED_UNICODE),'你负责阅读用户资料')){
            $walk=function($v)use(&$walk){if(!is_array($v))return 0;$n=($v['type']??'')==='image_url'?1:0;foreach($v as$x)if(is_array($x))$n+=$walk($x);return $n;};$n=$walk($call['input']['messages']);if($n){$check(str_contains(json_encode($call['input']['messages'],JSON_UNESCAPED_SLASHES),'data:image/jpeg;base64,'),'Mobile visual bytes lost');$visual[]=$n;}
        }
        $check(count($visual)===count(array_filter($validated,fn($r)=>$r['source']==='pdf'))*2+count(array_filter($validated,fn($r)=>$r['source']==='video'))*3,'Visual batch count differs from original reading checkpoints');
        $report+=['host'=>$host,'verified_runs'=>$validated,'native_visual_counts'=>$visual,'stt_real_multipart_once_per_step'=>true,'stt_audio_durations'=>array_column(array_column($wires,'probe'),'duration'),'all_uploaded_bytes_mime_storage_owner_order_verified'=>true,'old_ui_rows_unchanged'=>true,'scope'=>'Owned H5/MP host wrappers and optional actual H5 picker; original decoders/worker/billing/history, loopback suppliers only'];
    }finally{$f->stop('agent');}
}
