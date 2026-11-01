<?php
/** Only the owner-marked HTTP fixture may replace supplier output. */
function marketAgentTextSupplier(array $input,string $dir): bool
{
    file_put_contents($dir.'/deep-calls.jsonl',json_encode(['kind'=>'agent_text','input'=>$input])."\n",FILE_APPEND|LOCK_EX);
    $agent=($input['model']??'')==='synthetic-agent-text';$tool=false;$content='隔离文字回复🙂';
    if($agent&&!empty($input['tools'])&&!array_filter($input['messages'],fn($m)=>$m['role']==='tool')){
        $image=str_contains(json_encode($input['messages'],JSON_UNESCAPED_UNICODE),'Synthetic image plan');
        $name=$image?'plan_creation':'create_document';$params=$image?['tool_code'=>'aigc_image','title'=>'Synthetic image','parameters'=>['prompt'=>'Synthetic image plan','quality'=>'1k','ratio'=>'1:1']]:['title'=>'Synthetic Agent document','content'=>'隔离 Agent 文档正文。'];
        $tool=['index'=>0,'id'=>'call-synthetic','type'=>'function','function'=>['name'=>$name,'arguments'=>json_encode(['payload'=>$params],JSON_UNESCAPED_UNICODE)]];
    }elseif($agent&&empty($input['tools']))$content='{"action":"new","topic_id":0}';
    elseif($agent)$content='隔离 Agent 已保存方案或文档。';
    header('Content-Type: text/event-stream');
    $id='agent-'.count(file($dir.'/deep-calls.jsonl'));
    foreach($tool?[['tool_calls'=>[$tool]]]:array_map(fn($text)=>['content'=>$text],mb_str_split($content,3))as$delta){echo 'data: '.json_encode(['id'=>$id,'choices'=>[['index'=>0,'delta'=>$delta,'finish_reason'=>null]]],JSON_UNESCAPED_UNICODE)."\n\n";@ob_flush();flush();usleep(60000);}
    if(str_contains(json_encode($input['messages']), 'Synthetic incomplete stream')){echo 'data: '.json_encode(['error'=>['message'=>'Synthetic interrupted stream']])."\n\n";return true;}
    echo 'data: '.json_encode(['id'=>$id,'choices'=>[['index'=>0,'delta'=>new stdClass(),'finish_reason'=>$tool?'tool_calls':'stop']],'usage'=>['prompt_tokens'=>20,'completion_tokens'=>30,'total_tokens'=>50]])."\n\ndata: [DONE]\n\n";
    return true;
}
