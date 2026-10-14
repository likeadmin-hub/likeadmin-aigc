<?php
require dirname(__DIR__,2).'/vendor/autoload.php';
use app\common\service\app\aigc_llm\agent\{AgentCreationContext as Context,MarketNeuronProvider,AgentFunctionTool};
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Enums\SourceType;
function check($ok,$message){if(!$ok)throw new RuntimeException($message);}
$selected=Context::selectedModel(['channels'=>[
 ['id'=>'wrong','name'=>'Unselected model','default_duration'=>10],
 ['id'=>'chosen','name'=>'Chosen model','duration_options'=>[3,5],'skus'=>['private pricing']],
]],'chosen');
check($selected['name']==='Chosen model'&&!isset($selected['skus']),'Wrong or excessive model context');
check(Context::selectedModel(['channels'=>[]],'missing')['available']===false,'Unavailable selection silently replaced');
check(Context::selectedModel(['channels'=>[['id'=>'first','name'=>'First']]],'')['available']===false,'Empty preference selected the first model');
foreach(['已为你规划好生成方案，回复「确认」后开始。','已保存的生成方案','暂时无法实际提交生成队列'] as $reply)check(Context::unsupportedPlanClaim($reply),'Missed false plan claim');
check(!Context::unsupportedPlanClaim('请提供视频时长。'),'Clarification mistaken for a plan');
$requests=[];$planned=false;
$transport=function($p)use(&$requests,&$planned){
 $requests[]=$p;
 if(count($requests)===1)return ['content'=>'已保存的生成方案，回复确认后开始。'];
 if(!$planned)return ['tool_calls'=>[['id'=>'plan-1','function'=>['name'=>'plan_creation','arguments'=>'{"payload":{"prompt":"rotate"}}']]]];
 return ['content'=>'方案已保存，请通过界面确认。'];
};
// Capture by reference: real successful tool execution clears the guard.
$guard=function($text)use(&$planned){return !$planned&&Context::unsupportedPlanClaim($text)?'No saved plan; call the tool.':null;};
$provider=new MarketNeuronProvider('chosen-text',$transport,$guard);
$tool=new AgentFunctionTool('plan_creation','Save a plan',['type'=>'object','properties'=>['prompt'=>['type'=>'string']]],function($payload)use(&$planned){$planned=true;return ['planned'=>true,'requires_confirmation'=>true];});
$state=(new Agent())->setThreadId('planning-repair')->setAiProvider($provider)->setTools([$tool])->chat(new UserMessage('Make a video'));
check($planned&&count($requests)===3,'Repair failed to execute and complete the real tool loop');
check($requests[1]['tool_choice']==='required'&&$requests[1]['model_selection']==='chosen-text','Repair changed model or failed to require tools');
check(str_contains($state->getMessage()->getContent(),'方案已保存'),'Successful plan not returned');
$count=0;$provider=new MarketNeuronProvider('chosen',function()use(&$count){$count++;return ['content'=>'已保存的生成方案'];},static fn($text)=>Context::unsupportedPlanClaim($text)?'Repair':null);
$reply=$provider->chat(new UserMessage('video'))->message()->getContent();
check($count===2&&str_contains($reply,'尚未创建'),'Repair unbounded or fictional plan leaked');
$count=0;$provider=new MarketNeuronProvider('chosen',function()use(&$count){$count++;return ['content'=>'你好'];},static fn($text)=>Context::unsupportedPlanClaim($text)?'Repair':null);
$provider->chat(new UserMessage('hello'));check($count===1,'Ordinary chat retried');
$old=(new UserMessage('Use this image'))->addContent(new ImageContent('https://example.com/owned.png',SourceType::URL));
$rows=MarketNeuronProvider::messages([$old,new UserMessage('Now rotate slowly')]);
check($rows[0]['content'][1]['image_url']['url']==='https://example.com/owned.png'&&$rows[1]['content']==='Now rotate slowly','History image missing or assigned to wrong message');
$history=Context::dialogue([
 ['role'=>'assistant','content'=>'truncated orphan'],
 ['role'=>'user','content'=>'15秒电视机视频'],['role'=>'assistant','content'=>'请上传参考图'],
 ['role'=>'user','content'=>'这是参考图 of_owned'],['role'=>'user','content'=>'写可爱画风提示词'],
]);
check(count($history)===3&&str_contains($history[2]['content'],'of_owned')&&str_contains($history[2]['content'],'可爱画风'),'Failed reply normalization lost current input or reference');
$provider=new MarketNeuronProvider('chosen',static fn()=>['content'=>'保持电视机角色，写15秒可爱风视频提示词。']);
$state=(new Agent())->setThreadId('failed-turn-continuation')->setAiProvider($provider)->chat(array_map(static fn($r)=>$r['role']==='user'?new UserMessage($r['content']):new \NeuronAI\Chat\Messages\AssistantMessage($r['content']),$history));
check(str_contains($state->getMessage()->getContent(),'电视机'),'Real Neuron history rejected continuation after missing reply');
$called=false;$tool=new AgentFunctionTool('validate','Validate',['type'=>'object','required'=>['tool_code'],'properties'=>['tool_code'=>['type'=>'string']],'additionalProperties'=>false],function()use(&$called){$called=true;return [];});
$tool([]);check(!$called,'Malformed model tool arguments reached handler');
echo "Home Agent planning passed: selected model, bounded repair, real Neuron tools, chat and historical images; no provider calls\n";
