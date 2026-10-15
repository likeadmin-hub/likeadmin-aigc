<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/vendor/autoload.php';
use app\common\service\app\aigc_llm\agent\{MarketNeuronProvider as P,AgentContextCapacityExceeded,AgentFunctionTool};
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\{UserMessage,AssistantMessage};
function check(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
function denied(callable $work):void{try{$work();}catch(AgentContextCapacityExceeded){return;}throw new RuntimeException('Expected explicit capacity rejection');}

check(P::inputBudget([])===24000,'Missing metadata fallback changed');
check(P::inputBudget(['context_window'=>128000])===119808,'Context did not reserve normal output');
check(P::inputBudget(['context_window'=>32000,'max_input_tokens'=>12000])===12000,'Input-only limit ignored');
check(P::inputBudget(['context_window'=>100,'max_output_tokens'=>8192])===1,'Invalid capacity became unbounded');
$old='必须保留乙项目例外，仅书面授权后可海外投放。'.str_repeat('word ',17000).'最终更正：截止日为7月15日。';
$rows=[['role'=>'user','content'=>$old],['role'=>'assistant','content'=>'已理解，接下来要检查什么？'],['role'=>'user','content'=>'按这些要求核对。']];
check(P::fitContext($rows,'rules',[],119808)===$rows,'Fitting evidence modified');
denied(fn()=>P::fitContext($rows,'rules',[],100));
$wire=[];$executed=0;
$tool=new AgentFunctionTool('lookup','offline lookup',['type'=>'object','properties'=>[]],function()use(&$executed){$executed++;return ['source'=>'后文更正：7月15日，旧日期失效。'];});
$provider=new P('fixture',function($request)use(&$wire){
 $wire[]=$request;
 return count($wire)===1?['tool_calls'=>[['id'=>'source','function'=>['name'=>'lookup','arguments'=>'{"payload":{}}']]],'usage'=>['prompt_tokens'=>20000,'completion_tokens'=>100]]:['content'=>'offline fixture done'];
},null,119808);
$agent=(new Agent())->setThreadId('context-fidelity')->setContextWindow(PHP_INT_MAX)->setAiProvider($provider)->setTools([$tool]);
$agent->chat([new UserMessage($old),new AssistantMessage($rows[1]['content']),new UserMessage($rows[2]['content'])]);
check(count($wire)===2&&$executed===1,'Tool loop changed or replayed');
foreach($wire as $request)check($request['messages'][0]['content']===$old,'Framework/provider silently dropped older conditions');
check(end($wire[1]['messages'])['role']==='tool'&&str_contains(end($wire[1]['messages'])['content'],'7月15日'),'Tool result missing after long turn');
$calls=0;$small=new P('fixture',function()use(&$calls){$calls++;return ['content'=>'must not run'];},null,100);
denied(fn()=>$small->chat(new UserMessage($old),new AssistantMessage('old'),new UserMessage('continue')));
check($calls===0,'Overflow reached model transport');
$image=[['role'=>'user','content'=>[['type'=>'text','text'=>'请看图'],['type'=>'image_url','image_url'=>['url'=>'data:image/png;base64,'.str_repeat('A',200000)]]]]];
check(P::fitContext($image,'rules',[],2000)===$image,'Vision bytes mistaken for text or image mutated');
denied(fn()=>P::fitContext($image,'rules',[],1000));
denied(fn()=>P::fitContext([['role'=>'user','content'=>'short']],str_repeat('规则',1000),[],100));
denied(fn()=>P::fitContext([['role'=>'user','content'=>'short']],'rules',[['description'=>str_repeat('参数',1000)]],100));
echo "PASS full old conditions, actual Neuron history/tool loop, model-specific capacity, system/tools/vision admission; zero external calls\n";
