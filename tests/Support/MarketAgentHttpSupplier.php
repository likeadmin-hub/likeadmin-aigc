<?php
/** Only the owner-marked HTTP fixture may replace supplier output. */
function marketAgentTextSupplier(array $input,string $dir): bool
{
    file_put_contents($dir.'/deep-calls.jsonl',json_encode(['kind'=>'agent_text','input'=>$input])."\n",FILE_APPEND|LOCK_EX);
    $agent=($input['model']??'')==='synthetic-agent-text';$tool=false;$content='隔离文字回复🙂';
    $all=implode("\n",array_map(fn($m)=>is_string($m['content']??null)?$m['content']:json_encode($m['content']??[],JSON_UNESCAPED_UNICODE),$input['messages']));
    $reading=str_contains($all,'你负责阅读用户资料');
    if($agent&&!empty($input['tools'])&&!array_filter($input['messages'],fn($m)=>$m['role']==='tool')){
        $image=str_contains(json_encode($input['messages'],JSON_UNESCAPED_UNICODE),'Synthetic image plan');
        $page=str_contains(json_encode($input['messages']), 'Synthetic page text');
        $name=$page?'edit_canvas_page':($image?'plan_creation':'create_document');$params=$page?['action'=>'add_node','type'=>'text','title'=>'Synthetic page text','content'=>'隔离画布文字正文。']:($image?['tool_code'=>'aigc_image','title'=>'Synthetic image','parameters'=>['prompt'=>'Synthetic image plan','quality'=>'1k','ratio'=>'1:1']]:['title'=>'Synthetic Agent document','content'=>'隔离 Agent 文档正文。']);
        if(str_contains($all,'Synthetic long read')) {
            if(str_contains($all,'"status":"completed"'))$name='';
            else {preg_match('/of_[a-f0-9]{32}/',$all,$m);$name='read_long_asset';$params=['file_id'=>$m[0]??''];}
        } elseif(str_contains($all,'Synthetic transcription')) {
            if(str_contains($all,'本轮已有真实转写结果'))$name='';
            else {preg_match('/of_[a-f0-9]{32}/',$all,$m);$name='plan_creation';$params=['tool_code'=>'audio_transcription','title'=>'Synthetic transcription','parameters'=>['file_id'=>$m[0]??'']];}
        } elseif(str_contains($all,'Synthetic speech')) {
            $name='plan_creation';$params=['tool_code'=>'speech_synthesis','title'=>'Synthetic speech','parameters'=>['text'=>str_contains($all,'Synthetic speech long')?str_repeat('字',501):'隔离配音正文。']];
        }
        $users=array_values(array_filter($input['messages'],fn($m)=>$m['role']==='user'));$current=end($users);$current=is_string($current['content']??null)?$current['content']:json_encode($current['content']??[]);
        if(preg_match('/Synthetic media (video|music)/',$current,$match)) {
            $music=$match[1]==='music';$name='plan_creation';$params=['tool_code'=>$music?'aigc_music':'aigc_video','title'=>'Synthetic media '.$match[1],'parameters'=>$music?['prompt'=>'Synthetic instrumental blocks','title'=>'Synthetic instrumental','duration'=>5,'custom'=>false,'instrumental'=>true]:['prompt'=>'Synthetic video blocks','duration'=>1,'resolution'=>'720P','ratio'=>'1:1']];
        }
        $tool=['index'=>0,'id'=>'call-synthetic','type'=>'function','function'=>['name'=>$name,'arguments'=>json_encode(['payload'=>$params],JSON_UNESCAPED_UNICODE)]];
        if($name==='')$tool=false;
    }elseif($agent&&empty($input['tools']))$content='{"action":"new","topic_id":0}';
    elseif($agent)$content='隔离 Agent 已保存方案或文档。';
    if($reading)$content=json_encode(['summary'=>'隔离长资料累计摘要。','note'=>'隔离资料分段笔记。'],JSON_UNESCAPED_UNICODE);
    if(!$agent&&str_contains(json_encode($input['messages'],JSON_UNESCAPED_UNICODE),'统一对话决策'))$content=json_encode(['reply_markdown'=>'隔离短剧中文回复。','action'=>['name'=>'respond','arguments'=>new stdClass()],'remember'=>[]],JSON_UNESCAPED_UNICODE);
    if(!$agent&&str_contains($all,'Synthetic drama media sequence'))$content='隔离媒体方案。<canvas-actions>'.json_encode(['nodes'=>[['type'=>'image','title'=>'Synthetic storyboard','prompt'=>'Synthetic storyboard blocks','key'=>'board'],['type'=>'video','title'=>'Synthetic shot','prompt'=>'Synthetic moving blocks','key'=>'shot','depends_on'=>['board']]]],JSON_UNESCAPED_UNICODE).'</canvas-actions>';
    if(getenv('AIGC_HTTP_AGENT_MEDIA_BOUNDARY_MATRIX')==='1') {require_once __DIR__.'/MarketAgentMediaBoundaryHttpSupplier.php';[$tool,$content]=marketAgentMediaBoundaryReply($input);}
    if(getenv('AIGC_HTTP_DRAMA_WORKFLOW_MATRIX')==='1') {require_once __DIR__.'/MarketDramaWorkflowHttpSupplier.php';$content=marketDramaWorkflowReply($dir);}
    if(getenv('AIGC_HTTP_AGENT_MOBILE_ACTIONS_MATRIX')==='1') {require_once __DIR__.'/MarketAgentMobileActionsHttpSupplier.php';[$tool,$content]=marketAgentMobileActionsReply($input);}
    if(getenv('AIGC_HTTP_AGENT_MOBILE_COMPLETION_MATRIX')==='1') {
        $run=think\facade\Db::name('aigc_llm_agent_run')->where('status','running')->order('id','desc')->find();
        if(empty($input['tools'])){ $tool=false;$content='{"action":"new","topic_id":0}'; }
        elseif($run){
            $document=str_contains($run['content'],'document');
            $done=$document?think\facade\Db::name('aigc_llm_agent_artifact')->where('run_id',$run['id'])->count():think\facade\Db::name('aigc_llm_agent_step')->where('run_id',$run['id'])->count();
            if($done){$tool=false;$content='隔离移动作品已保存。';}
            else {
                $images=[];foreach(json_decode($run['attachments'],true)?:[]as$asset)if(str_starts_with($asset['mime']??'','image/'))$images[]=$asset['file_id'];
                $params=$document?['title'=>'Synthetic mobile document','content'=>'隔离移动文档正文。已核对受管素材。']:['tool_code'=>'aigc_image','title'=>'Synthetic mobile image','parameters'=>['prompt'=>$run['content'],'quality'=>'1k','ratio'=>'1:1','reference_images'=>$images]];
                $tool=['index'=>0,'id'=>'call-mobile-completion','type'=>'function','function'=>['name'=>$document?'create_document':'plan_creation','arguments'=>json_encode(['payload'=>$params],JSON_UNESCAPED_UNICODE)]];
            }
        }
    }
    header('Content-Type: text/event-stream');
    $id='agent-'.count(file($dir.'/deep-calls.jsonl'));
    foreach($tool?[['tool_calls'=>[$tool]]]:array_map(fn($text)=>['content'=>$text],mb_str_split($content,(getenv('AIGC_HTTP_DRAMA_WORKFLOW_MATRIX')==='1'||getenv('AIGC_HTTP_AGENT_MEDIA_BOUNDARY_MATRIX')==='1'||getenv('AIGC_HTTP_AGENT_MOBILE_ACTIONS_MATRIX')==='1'||getenv('AIGC_HTTP_AGENT_MOBILE_COMPLETION_MATRIX')==='1')?256:3))as$delta){echo 'data: '.json_encode(['id'=>$id,'choices'=>[['index'=>0,'delta'=>$delta,'finish_reason'=>null]]],JSON_UNESCAPED_UNICODE)."\n\n";@ob_flush();flush();usleep(60000);}
    if(str_contains(json_encode($input['messages']), 'Synthetic incomplete stream')){echo 'data: '.json_encode(['error'=>['message'=>'Synthetic interrupted stream']])."\n\n";return true;}
    echo 'data: '.json_encode(['id'=>$id,'choices'=>[['index'=>0,'delta'=>new stdClass(),'finish_reason'=>$tool?'tool_calls':'stop']],'usage'=>['prompt_tokens'=>20,'completion_tokens'=>30,'total_tokens'=>50]])."\n\ndata: [DONE]\n\n";
    return true;
}
