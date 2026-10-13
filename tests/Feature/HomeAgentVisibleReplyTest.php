<?php
require dirname(__DIR__,2).'/vendor/autoload.php';
use app\common\service\app\aigc_llm\agent\{MarketNeuronProvider as P,AgentFunctionTool};
use app\common\service\power\MarketTextModelRuntimeService as Runtime;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\UserMessage;
function check($ok,$message){if(!$ok)throw new RuntimeException($message);}
$state=['content'=>'','reasoning'=>'','usage'=>[],'request_id'=>'','emitted_request_id'=>'','tool_calls'=>[]];$visible=[];
$parse=new ReflectionMethod(Runtime::class,'parseSseBlock');$apply=new ReflectionMethod(Runtime::class,'applyStreamEvent');
foreach([['reasoning_content'=>'Internal analysis'],['content'=>'请上传参考图。']] as $delta){
 $event=$parse->invoke(null,'data: '.json_encode(['choices'=>[['delta'=>$delta]]]));
 $apply->invokeArgs(null,[$event,&$state,static function($event,$data)use(&$visible){if($event==='delta')$visible[]=$data['delta'];}]);
}
check($visible===['请上传参考图。']&&$state['content']==='请上传参考图。','Reasoning leaked through live SSE');
$executed=0;$calls=0;$requests=[];
$provider=new P('fixture',function($request)use(&$calls,&$requests){
 $requests[]=$request;
 return ++$calls===1?['content'=>'Internal analysis','content_source'=>'reasoning','tool_calls'=>[['id'=>'lookup-1','function'=>['name'=>'lookup','arguments'=>'{}']]],'usage'=>['prompt_tokens'=>12,'completion_tokens'=>8]]:['content'=>'请上传参考图。','content_source'=>'content'];
});
$tool=new AgentFunctionTool('lookup','Read task state',['type'=>'object','properties'=>[]],function()use(&$executed){$executed++;return ['available'=>true];});
$reply=(new Agent())->setThreadId('visible-reply-fixture')->setAiProvider($provider)->setTools([$tool])->chat(new UserMessage('创作视频'));
check($executed===1&&$calls===2&&$reply->getMessage()->getContent()==='请上传参考图。','Reasoning-only tool turn broke continuation');
check(!str_contains(json_encode($requests[1]),'Internal analysis'),'Reasoning fallback entered subsequent model history');
$safe=P::visibleResult(['content'=>'Internal analysis','content_source'=>'reasoning','tool_calls'=>[['id'=>'x']],'usage'=>['completion_tokens'=>8]]);
check($safe['content']===''&&$safe['usage']['completion_tokens']===8,'Filtering changed usage or retained analysis');
foreach(['Hello, how can I help?','先介绍方案，再执行生成。'] as $text)check(P::visibleResult(['content'=>$text])['content']===$text,'Normal English/Chinese reply was eaten');
$failed=false;try{P::visibleResult(['content'=>'Internal analysis','content_source'=>'reasoning']);}catch(RuntimeException $e){$failed=$e->getMessage()==='模型未返回正式回复，请稍后重试';}
check($failed,'Reasoning-only final response displayed as success');
echo "Home Agent visible replies passed: SSE separation, reasoning-only tool continuation, history, usage and natural replies\n";
