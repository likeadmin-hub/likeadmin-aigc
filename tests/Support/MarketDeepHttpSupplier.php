<?php
/** Loopback supplier receipts only; deterministic synthetic output, never real model inference. */
function marketDeepSupplier(string $path,string $dir): bool
{
    if(getenv('AIGC_HTTP_AGENT_SPECIAL_MATRIX')==='1'&&$path==='/fixture/agent-speech.mp3'){header('Content-Type: audio/mpeg');readfile($dir.'/agent-speech.mp3');return true;}
    if(str_starts_with($path,'/uploads/')) {
        $file=realpath($dir.'/public'.$path);$root=realpath($dir.'/public/uploads');
        if(!$file||!$root||!str_starts_with($file,$root.'/')){http_response_code(404);return true;}
        header('Content-Type: application/octet-stream');readfile($file);return true;
    }
    if($path==='/fixture/clip.mp4') { if(is_file($dir.'/clip-download-fail')){http_response_code(503);echo 'fixture unavailable';return true;}header('Content-Type: video/mp4');readfile($dir.'/synthetic.mp4');return true;}
    if(preg_match('~^/fixture/export\.(wav|midi|mp4)$~',$path,$m)) {
        if(is_file($dir.'/export-fail-'.$m[1])){http_response_code(503);echo 'fixture unavailable';return true;}
        $file=$dir.'/'.(['wav'=>'synthetic.wav','midi'=>'synthetic.midi','mp4'=>'synthetic.mp4'][$m[1]]);
        header('Content-Type: application/octet-stream');readfile($file);return true;
    }
    if(preg_match('~^/fixture/song-[1-9][0-9]*-[ab]\.wav$~',$path)){header('Content-Type: audio/wav');readfile($dir.'/synthetic.wav');return true;}
    if(($_SERVER['HTTP_AUTHORIZATION']??'')!=='Bearer fixture-no-real-key')return false;
    $input=json_decode(file_get_contents('php://input'),true)?:[];
    $record=function(string $kind)use($dir,$input){file_put_contents($dir.'/deep-calls.jsonl',json_encode(['kind'=>$kind,'input'=>$input])."\n",FILE_APPEND|LOCK_EX);};
    if(getenv('AIGC_HTTP_AGENT_SPECIAL_MATRIX')==='1') {
        if($path==='/api/v1/apps/voice_tts/stt'){
            if(getenv('AIGC_HTTP_AGENT_MEDIA_BOUNDARY_MATRIX')==='1'||getenv('AIGC_HTTP_AGENT_MOBILE_MEDIA_MATRIX')==='1'){
                $file=$_FILES['audio']['tmp_name']??'';
                if(!is_uploaded_file($file))throw new RuntimeException('Private STT did not receive real multipart audio');
                $wire=['sha256'=>hash_file('sha256',$file),'mime'=>(new finfo(FILEINFO_MIME_TYPE))->file($file),'probe'=>app\common\service\app\aigc_llm\agent\AgentMediaReader::probe($file)];
                file_put_contents($dir.'/agent-stt-wire.json',json_encode($wire));
                if(getenv('AIGC_HTTP_AGENT_MOBILE_MEDIA_MATRIX')==='1')file_put_contents($dir.'/mobile-stt-wires.jsonl',json_encode($wire).chr(10),FILE_APPEND|LOCK_EX);
            }
            $record('agent_stt');echo json_encode(['code'=>1,'data'=>['text'=>'隔离音频转写正文。','language'=>'zh','duration'=>1]],JSON_UNESCAPED_UNICODE);return true;
        }
        if(in_array($path,['/api/v1/apps/voice_tts/tts','/api/v1/apps/voice_tts/tts_async'],true)) {
            $async=str_ends_with($path,'tts_async');$record($async?'agent_tts_async':'agent_tts');
            $id='agent-tts-async-1';
            if($async&&getenv('AIGC_HTTP_AGENT_MEDIA_BOUNDARY_MATRIX')==='1'){
                $calls=array_map(fn($line)=>json_decode($line,true),file($dir.'/deep-calls.jsonl'));
                $id='agent-tts-async-'.count(array_filter($calls,fn($row)=>$row['kind']==='agent_tts_async'));
            }
            echo json_encode(['code'=>1,'data'=>$async?['task_id'=>$id,'status'=>'pending']:['result'=>['audio_url'=>'https://private-media.example.test/fixture/agent-speech.mp3']]]);return true;
        }
        if(preg_match('~^/api/v1/tasks/(agent-tts-async-[1-9][0-9]*)$~',$path,$match)){echo json_encode(['code'=>1,'data'=>['task_id'=>$match[1],'status'=>'success','result'=>['audio_url'=>'https://private-media.example.test/fixture/agent-speech.mp3']]]);return true;}
    }
    if($path==='/api/v1/apps/voice_tts/tts_live') {
        $record('tts');echo json_encode(['code'=>1,'data'=>['task_id'=>'deep-tts-1','status'=>'running']]);return true;
    }
    if($path==='/api/v1/tasks/deep-tts-1') {
        echo json_encode(['code'=>1,'data'=>['status'=>'success','audio_url'=>'http://'.$_SERVER['HTTP_HOST'].'/fixture/result.wav']]);return true;
    }
    if(preg_match('~^/api/v1/apps/smart_clip/(realman_broadcast|broadcast_mixcut|news_mixcut)$~',$path,$m)) {
        $record('clip_'.$m[1]);$calls=array_map(fn($line)=>json_decode($line,true),file($dir.'/deep-calls.jsonl'));$n=count(array_filter($calls,fn($c)=>$c['kind']==='clip_'.$m[1]));
        echo json_encode(['code'=>1,'data'=>['task_id'=>'recovery-clip-'.$m[1].'-'.$n,'status'=>'running']]);return true;
    }
    if(str_starts_with($path,'/api/v1/tasks/recovery-clip-')) {
        $id=basename($path);file_put_contents($dir.'/clip-queries.jsonl',json_encode(['id'=>$id])."\n",FILE_APPEND|LOCK_EX);
        if(is_file($dir.'/clip-hold-'.$id)){
            file_put_contents($dir.'/clip-query-entered-'.$id,'1');$until=microtime(true)+20;
            while(is_file($dir.'/clip-hold-'.$id)&&microtime(true)<$until){clearstatcache();usleep(100000);}
        }
        if(is_file($dir.'/clip-reject-'.$id)){echo json_encode(['code'=>1,'data'=>['status'=>'failed','error'=>'Synthetic rejection']]);return true;}
        echo json_encode(['code'=>1,'data'=>['status'=>'success','video_url'=>'http://'.$_SERVER['HTTP_HOST'].'/fixture/clip.mp4','duration'=>1]]);return true;
    }
    if($path==='/api/v1/apps/music_generation/create'){
        $record('song_create');$calls=array_map(fn($line)=>json_decode($line,true),file($dir.'/deep-calls.jsonl'));$n=count(array_filter($calls,fn($c)=>$c['kind']==='song_create'));
        echo json_encode(['code'=>1,'data'=>['task_id'=>'song-task-'.$n,'status'=>'running']]);return true;
    }
    if($path==='/api/v1/apps/music_generation/query'&&preg_match('/^song-task-([1-9][0-9]*)$/D',$_GET['task_id']??'',$m)){
        $songs=[];foreach(['a','b']as$letter)$songs[]=['id'=>'song-audio-'.$m[1].'-'.$letter,'title'=>'Synthetic original '.$letter,'audio_url'=>'http://'.$_SERVER['HTTP_HOST'].'/fixture/song-'.$m[1].'-'.$letter.'.wav','duration'=>1,'lyrics'=>'彩色色块随节奏移动'];
        echo json_encode(['code'=>1,'data'=>['status'=>'success','items'=>$songs]]);return true;
    }
    if($path==='/api/v1/chat/completions') {
        if(getenv('AIGC_HTTP_AGENT_MATRIX')==='1'&&in_array($input['model']??'', ['synthetic-stream-text','synthetic-agent-text'],true)){require_once __DIR__.'/MarketAgentHttpSupplier.php';return marketAgentTextSupplier($input,$dir);}
        $record('text');$last=$input['messages'][count($input['messages'])-1]['content']??'';
        $content='图片是无隐私的彩色色块，保持色块与背景一致。';
        if(is_string($last)) {
            $segments=[];foreach([1,2]as$i)$segments[]=['title'=>'色块镜头'.$i,'duration'=>2,'prompt'=>"0-1秒：同一彩色色块向右移动。\n1-2秒：延续动作，保持背景。",'image_indices'=>[0],'start_state'=>'色块在左侧','end_state'=>'色块在右侧','continuity'=>'同一色块与背景'];
            $content=json_encode(['prompt'=>'四秒色块移动','continuity'=>'同一色块与背景','segments'=>$segments],JSON_UNESCAPED_UNICODE);
        }
        header('Content-Type: text/event-stream');
        echo 'data: '.json_encode(['id'=>'deep-chat-1','choices'=>[['index'=>0,'delta'=>['role'=>'assistant','content'=>$content],'finish_reason'=>null]]],JSON_UNESCAPED_UNICODE)."\n\n";
        echo 'data: '.json_encode(['id'=>'deep-chat-1','choices'=>[['index'=>0,'delta'=>new stdClass(),'finish_reason'=>'stop']],'usage'=>['prompt_tokens'=>20,'completion_tokens'=>30,'total_tokens'=>50]])."\n\ndata: [DONE]\n\n";return true;
    }
    if(preg_match('~^/api/v1/apps/music_generation/(voice_clone|wav|midi|mp4|vox|timing|upload_audio|persona|lyrics|mashup_lyrics|style)$~',$path,$m)) {
        $op=$m[1];$record('music_'.$op);
        if(in_array($op,['wav','midi','vox'],true)){echo json_encode(['code'=>1,'data'=>['task_id'=>'deep-export-'.$op.'-'.count(array_filter(array_map(fn($line)=>json_decode($line,true),file($dir.'/deep-calls.jsonl')),fn($row)=>$row['kind']==='music_'.$op)),'status'=>'processing']]);return true;}
        $result=['status'=>'completed'];
        if($op==='voice_clone')$result['voice_id']='deep-owned-voice';
        elseif($op==='upload_audio')$result['audio_id']='deep-uploaded-audio';
        elseif($op==='persona')$result['persona_id']='deep-owned-persona';
        elseif($op==='timing')$result['data']=['segments'=>[['start'=>0,'end'=>1,'text'=>'Synthetic']]];
        elseif(in_array($op,['lyrics','mashup_lyrics','style'],true))$result['data']=['text'=>'彩色色块随节奏移动','upsampled_tags'=>'ambient instrumental','title'=>'Synthetic words'];
        else $result['file_url']='https://private-media.example.test/fixture/export.mp4';
        echo json_encode(['code'=>1,'data'=>['result'=>$result]]);return true;
    }
    if(preg_match('~^/api/v1/tasks/deep-export-(wav|midi|vox)(?:-(\d+))?$~',$path,$m)) {
        $counter=$dir.'/query-'.$m[1].'-'.($m[2]??1);$n=is_file($counter)?(int)file_get_contents($counter):0;file_put_contents($counter,(string)($n+1));
        if($n===0){echo json_encode(['code'=>1,'data'=>['status'=>'processing']]);return true;}
        $result=['status'=>is_file($dir.'/export-terminal-fail-'.$m[1])?'failed':'completed'];
        if($m[1]==='vox')$result['audio_id']='deep-vox-id';else$result['file_url']='https://private-media.example.test/fixture/export.'.$m[1];
        echo json_encode(['code'=>1,'data'=>['result'=>$result]]);return true;
    }
    return false;
}
