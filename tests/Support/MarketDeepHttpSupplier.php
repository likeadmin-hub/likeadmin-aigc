<?php
/** Loopback supplier receipts only; deterministic synthetic output, never real model inference. */
function marketDeepSupplier(string $path,string $dir): bool
{
    if(str_starts_with($path,'/uploads/')) {
        $file=realpath($dir.'/public'.$path);$root=realpath($dir.'/public/uploads');
        if(!$file||!$root||!str_starts_with($file,$root.'/')){http_response_code(404);return true;}
        header('Content-Type: application/octet-stream');readfile($file);return true;
    }
    if(preg_match('~^/fixture/export\.(wav|midi|mp4)$~',$path,$m)) {
        $file=$dir.'/'.(['wav'=>'synthetic.wav','midi'=>'synthetic.midi','mp4'=>'synthetic.mp4'][$m[1]]);
        header('Content-Type: application/octet-stream');readfile($file);return true;
    }
    if(($_SERVER['HTTP_AUTHORIZATION']??'')!=='Bearer fixture-no-real-key')return false;
    $input=json_decode(file_get_contents('php://input'),true)?:[];
    $record=function(string $kind)use($dir,$input){file_put_contents($dir.'/deep-calls.jsonl',json_encode(['kind'=>$kind,'input'=>$input])."\n",FILE_APPEND|LOCK_EX);};
    if($path==='/api/v1/apps/voice_tts/tts_live') {
        $record('tts');echo json_encode(['code'=>1,'data'=>['task_id'=>'deep-tts-1','status'=>'running']]);return true;
    }
    if($path==='/api/v1/tasks/deep-tts-1') {
        echo json_encode(['code'=>1,'data'=>['status'=>'success','audio_url'=>'http://'.$_SERVER['HTTP_HOST'].'/fixture/result.wav']]);return true;
    }
    if($path==='/api/v1/chat/completions') {
        $record('text');$last=$input['messages'][count($input['messages'])-1]['content']??'';
        $content='图片是无隐私的彩色色块，保持色块与背景一致。';
        if(is_string($last)) {
            $segments=[];foreach([1,2]as$i)$segments[]=['title'=>'色块镜头'.$i,'duration'=>2,'prompt'=>"0-1秒：同一彩色色块向右移动。\n1-2秒：延续动作，保持背景。",'image_indices'=>[0],'start_state'=>'色块在左侧','end_state'=>'色块在右侧','continuity'=>'同一色块与背景'];
            $content=json_encode(['prompt'=>'四秒色块移动','continuity'=>'同一色块与背景','segments'=>$segments],JSON_UNESCAPED_UNICODE);
        }
        echo json_encode(['id'=>'deep-chat-1','object'=>'chat.completion','choices'=>[['index'=>0,'message'=>['role'=>'assistant','content'=>$content],'finish_reason'=>'stop']],'usage'=>['prompt_tokens'=>20,'completion_tokens'=>30,'total_tokens'=>50]],JSON_UNESCAPED_UNICODE);return true;
    }
    if(preg_match('~^/api/v1/apps/music_generation/(voice_clone|wav|midi|mp4|vox|timing|upload_audio|persona)$~',$path,$m)) {
        $op=$m[1];$record('music_'.$op);
        if(in_array($op,['wav','midi','vox'],true)){echo json_encode(['code'=>1,'data'=>['task_id'=>'deep-export-'.$op,'status'=>'processing']]);return true;}
        $result=['status'=>'completed'];
        if($op==='voice_clone')$result['voice_id']='deep-owned-voice';
        elseif($op==='upload_audio')$result['audio_id']='deep-uploaded-audio';
        elseif($op==='persona')$result['persona_id']='deep-owned-persona';
        elseif($op==='timing')$result['data']=['segments'=>[['start'=>0,'end'=>1,'text'=>'Synthetic']]];
        else $result['file_url']='https://private-media.example.test/fixture/export.mp4';
        echo json_encode(['code'=>1,'data'=>['result'=>$result]]);return true;
    }
    if(preg_match('~^/api/v1/tasks/deep-export-(wav|midi|vox)$~',$path,$m)) {
        $result=['status'=>'completed'];
        if($m[1]==='vox')$result['audio_id']='deep-vox-id';else$result['file_url']='https://private-media.example.test/fixture/export.'.$m[1];
        echo json_encode(['code'=>1,'data'=>['result'=>$result]]);return true;
    }
    return false;
}
