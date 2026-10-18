<?php
// Standalone transport contract regression; no database or provider request.
require dirname(__DIR__,2).'/vendor/autoload.php';
use app\common\service\power\MarketTextModelRuntimeService as Runtime;
function check($ok,string $message): void { if(!$ok)throw new RuntimeException($message); }
function method(string $name): ReflectionMethod {$m=new ReflectionMethod(Runtime::class,$name);$m->setAccessible(true);return $m;}
$generation=method('generationParams');$payload=method('requestPayload');$parse=method('parseSseBlock');$apply=method('applyStreamEvent');$normalize=method('normalizeUsage');
foreach(['openai_chat','openai_responses','anthropic_messages'] as $protocol){
    $model=['model_code'=>'fixture','protocols'=>$protocol,'default_params'=>[],'params_schema'=>[]];
    $params=$generation->invoke(null,$model,[]);
    $body=$payload->invoke(null,$protocol,$model,[['role'=>'user','content'=>'test']],'',128,$params);
    check($protocol==='openai_chat'?($body['stream_options']['include_usage']??false)===true:!isset($body['stream_options']),'Usage option violates selected protocol');
}
$retry=method('compatibleGenerationParams')->invoke(null,'unknown parameter: stream_options',['stream_options'=>['include_usage'=>true]]);
check($retry!==null&&!isset($retry['params']['stream_options']),'Usage option cannot be removed for incompatible provider');
$state=['content'=>'','reasoning'=>'','usage'=>[],'request_id'=>'','emitted_request_id'=>'','tool_calls'=>[]];$emitted=[];
$emit=static function($type,$data)use(&$emitted){if($type==='delta')$emitted[]=$data['delta'];};
foreach([
    ['choices'=>[['delta'=>['content'=>'完成'],'finish_reason'=>null]],'usage'=>null],
    ['choices'=>[['delta'=>[],'finish_reason'=>'stop']]],
    ['choices'=>[],'usage'=>['prompt_tokens'=>120,'completion_tokens'=>8,'total_tokens'=>128]],
] as $json){$event=$parse->invoke(null,'data: '.json_encode($json));check($event!==null,'SSE usage chunk discarded');$apply->invokeArgs(null,[$event,&$state,$emit]);}
check($state['content']==='完成'&&$emitted===['完成'],'Usage chunk changed visible text');
check($normalize->invoke(null,$state['usage'])['total_tokens']===128,'Final usage-only chunk lost');
$state['usage']=[];
foreach([
    ['type'=>'message_start','message'=>['id'=>'fixture','usage'=>['input_tokens'=>123,'output_tokens'=>0]]],
    ['type'=>'message_delta','delta'=>['stop_reason'=>'end_turn'],'usage'=>['output_tokens'=>17]],
    ['type'=>'message_stop'],
] as $json){$event=$parse->invoke(null,'data: '.json_encode($json));if($event)$apply->invokeArgs(null,[$event,&$state,$emit]);}
$usage=$normalize->invoke(null,$state['usage']);
check($usage['prompt_tokens']===123&&$usage['completion_tokens']===17&&$usage['total_tokens']===140,'Partial usage replaced earlier token counters');
check($normalize->invoke(null,[])['provider_reported']===false,'Missing usage was invented');
echo "Market text usage stream passed: protocol-specific request, usage-only tail, incremental usage, compatibility and no guessed tokens\n";

$settle=method('canSettleUsageFromSnapshot');$same=['input'=>['platform_price'=>1,'tenant_price'=>2],'output'=>['platform_price'=>1,'tenant_price'=>2]];
$unequal=['input'=>['platform_price'=>1,'tenant_price'=>2],'output'=>['platform_price'=>3,'tenant_price'=>4]];
foreach([
 [['prompt_tokens'=>10,'completion_tokens'=>2,'total_tokens'=>12],true,true],
 [['input_tokens'=>10,'output_tokens'=>0],true,true],
 [['prompt_tokens'=>10,'total_tokens'=>12],false,false],
 [['completion_tokens'=>2],false,false],
 [['prompt_tokens'=>10,'completion_tokens'=>2,'total_tokens'=>15],false,false],
 [['prompt_tokens'=>10,'completion_tokens'=>2,'total_tokens'=>0],false,false],
 [['prompt_tokens'=>10,'completion_tokens'=>-1],false,false],
 [['prompt_tokens'=>10,'completion_tokens'=>'bad'],false,false],
 [['total_tokens'=>12],true,false],
 [[],false,false],
] as [$raw,$equalExpected,$unequalExpected]){
 $u=$normalize->invoke(null,$raw);check($settle->invoke(null,$same,$u)===$equalExpected&&$settle->invoke(null,$unequal,$u)===$unequalExpected,'Incomplete/inconsistent counters settled or valid pricing regressed');
 check($normalize->invoke(null,$u)===$u,'Normalization lost usage field provenance');
}
echo "Usage completeness passed: missing/invalid counters, explicit zero, totals consistency and equal-price total-only compatibility\n";
$units=$same;$units['input']['usage_unit_size']=1000;$units['output']['usage_unit_size']=1000000;
check(!$settle->invoke(null,$units,$normalize->invoke(null,['total_tokens'=>12])),'Different effective token rates settled from total only');
