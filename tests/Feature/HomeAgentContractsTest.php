<?php
require dirname(__DIR__,2).'/vendor/autoload.php';
use app\common\service\app\aigc_llm\agent\{AgentStore,AgentToolContext,AgentCapabilityCatalog,AgentArtifactService,AgentFunctionTool,MarketNeuronProvider};
use app\common\service\open_platform\OpenSchema;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\UserMessage;
function check($ok,$message){if(!$ok)throw new RuntimeException($message);}
foreach([['mode'=>'unknown'],['mode'=>'']] as $input){try{AgentStore::validatePreferences($input);throw new RuntimeException('Invalid preferences accepted');}catch(InvalidArgumentException){}}
$p=AgentStore::validatePreferences(['mode'=>'auto','budget'=>12.34,'models'=>['text'=>'market:1','image'=>'channel:2']]);check($p['models']['image']==='channel:2','preference identity');
foreach([[],['budget'=>0],['budget'=>1],['budget'=>-1],['budget'=>INF]] as $legacy){$auto=AgentStore::validatePreferences(['mode'=>'auto']+$legacy);check(!array_key_exists('budget',$auto),'Retired budget leaked into new run');foreach([0,30,110,1000001] as $total)check(!AgentStore::requiresConfirmation($auto,$total),'Direct generation requires a budget');}
check(AgentStore::requiresConfirmation(AgentStore::validatePreferences([]),0),'Default ask mode must confirm even free tasks');
foreach(['aigc_image','aigc_video'] as $code){OpenSchema::validateRequest(['prompt'=>'创作测试'],AgentCapabilityCatalog::schema($code),true);try{OpenSchema::validateRequest(['prompt'=>'test','tenant_id'=>99],AgentCapabilityCatalog::schema($code),true);throw new RuntimeException('Owner override accepted');}catch(\app\common\service\open_platform\OpenException){}}
check(AgentToolContext::image(1,2)===null,'no implicit native price override');
check(AgentStore::requiresConfirmation(['mode'=>'ask','budget'=>100],20),'consultation always confirms');
check(!AgentStore::requiresConfirmation(['mode'=>'auto','budget'=>20],20),'auto executes within budget');
check(!AgentStore::requiresConfirmation(['mode'=>'auto','budget'=>20],21),'Retired budget still stops an explicitly automatic run');
foreach(['aigc_short_drama','aigc_canvas','aigc_geo','unknown'] as $code){try{AgentCapabilityCatalog::schema($code);throw new RuntimeException('Studio/unknown tool accepted');}catch(InvalidArgumentException){}}
$called=0;
$tool=new AgentFunctionTool('make_plan','Prepare a plan',['type'=>'object','properties'=>['prompt'=>['type'=>'string']],'required'=>['prompt']],function($payload)use(&$called){$called++;check($payload['prompt']==='poster','payload passed');return ['planned'=>true,'requires_confirmation'=>true];});
$provider=new MarketNeuronProvider('chosen',function($params)use(&$called){if(!$called)return ['tool_calls'=>[['id'=>'t1','function'=>['name'=>'make_plan','arguments'=>'{"payload":{"prompt":"poster"}}']]]];check(str_contains($params['messages'][2]['content'],'requires_confirmation'),'tool output retained');return ['content'=>'请确认方案'];});
$state=(new Agent())->setThreadId('home-contract-test')->setAiProvider($provider)->setTools([$tool])->chat([new UserMessage('poster')]);
check($called===1&&$state->getMessage()->getContent()==='请确认方案','real Neuron wrapper loop');
$path=tempnam(sys_get_temp_dir(),'agent-doc-test');
try{AgentArtifactService::writeDocx($path,'测试 <标题>','正文 & 内容');$zip=new ZipArchive();check($zip->open($path)===true,'docx opens');$xml=$zip->getFromName('word/document.xml');check(simplexml_load_string($xml)!==false,'valid OOXML');check(str_contains($xml,'&lt;标题&gt;')&&str_contains($xml,'&amp;'),'escaped document');check($zip->locateName('[Content_Types].xml')!==false,'docx types');$zip->close();}finally{@unlink($path);}
echo "Home Agent contracts passed\n";
