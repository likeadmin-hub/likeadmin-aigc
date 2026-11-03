<?php
/** Selected only by the owner-marked loopback media-boundary fixture. */
function marketAgentMediaBoundaryReply(array $input): array
{
    $all=json_encode($input['messages'],JSON_UNESCAPED_UNICODE);
    if(str_contains($all,'你负责阅读用户资料'))return [false,json_encode(['summary'=>'Synthetic source facts accumulated.','note'=>'Synthetic source segment retained.'])];
    if(empty($input['tools']))return [false,'{"action":"new","topic_id":0}'];
    $db=think\facade\Db::class;
    $run=$db::name('aigc_llm_agent_run')->where('status','running')->order('id','desc')->find();
    if(!$run)throw new RuntimeException('Private supplier has no active Agent run');
    $name='';$params=[];$content='隔离步骤已保存。';
    if(str_contains($run['content'],'Synthetic long read')){
        $jobs=json_decode($run['preferences'],true)['_long_reads']??[];
        if(!$jobs){$files=json_decode($run['attachments'],true);$name='read_long_asset';$params=['file_id'=>marketAgentReadingFile($files,$run['content'])];}
        $content=$jobs?'分段进度已保存。':'正在安排分段读取。';
    }elseif(str_contains($run['content'],'Synthetic transcription')){
        $steps=$db::name('aigc_llm_agent_step')->where('run_id',$run['id'])->select()->toArray();
        if(!$steps){$files=json_decode($run['attachments'],true);$name='plan_creation';$params=['tool_code'=>'audio_transcription','title'=>'Synthetic video audio transcription','parameters'=>['file_id'=>marketAgentReadingFile($files,$run['content'])]];}
        else $content='隔离转写结果已保留，可据此分析。';
    }elseif(str_contains($run['content'],'Synthetic speech')){
        if(!$db::name('aigc_llm_agent_step')->where('run_id',$run['id'])->count()){
            $name='plan_creation';$params=['tool_code'=>'speech_synthesis','title'=>'Synthetic maximum speech','parameters'=>['text'=>str_repeat('字',10000)]];
        }
    }else throw new RuntimeException('Unexpected boundary fixture user intent');
    $tool=$name===''?false:['index'=>0,'id'=>'call-media-boundary','type'=>'function','function'=>['name'=>$name,'arguments'=>json_encode(['payload'=>$params],JSON_UNESCAPED_UNICODE)]];
    return [$tool,$content];
}

/** Existing boundary fixtures have one source; mobile keeps the same mixed references. */
function marketAgentReadingFile(array $files,string $content): string
{
    if(getenv('AIGC_HTTP_AGENT_MOBILE_MEDIA_MATRIX')==='1'){
        if(!preg_match('/(?:long read|transcription) (pdf|docx|video|audio)/',$content,$m))throw new RuntimeException('Missing mobile source kind');
        foreach($files as$file){$kind=$m[1];if(in_array($kind,['pdf','docx'],true)?str_ends_with($file['name'],'.'.$kind):str_starts_with($file['mime']??'',$kind.'/'))return $file['file_id'];}
        throw new RuntimeException('Mobile source missing');
    }
    return $files[0]['file_id'];
}
