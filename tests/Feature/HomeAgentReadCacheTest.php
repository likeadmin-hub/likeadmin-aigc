<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/vendor/autoload.php';
use app\common\service\app\aigc_llm\agent\{AgentReadCache as C,AgentFunctionTool,MarketNeuronProvider};
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\UserMessage;
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$reads=0;$read=static function()use(&$reads){return ++$reads;};
for($i=0;$i<10;$i++)C::remember('document',$read);
check($reads===10,'Unscoped callers must not retain data');
$reads=0;C::scope(static function()use($read){for($i=0;$i<10;$i++)check(C::remember('document',$read)===1,'Repeated read changed');});
check($reads===1,'Document parsing not reused');
C::scope(static function()use($read){check(C::remember('document',$read)===2,'Scope leaked across jobs');
 C::scope(static function()use($read){check(C::remember('document',$read)===3,'Nested scope reused another run');});
 check(C::remember('document',$read)===2,'Nested scope corrupted parent');
});
try{C::scope(static function()use($read){C::remember('document',$read);throw new RuntimeException('stop');});}catch(RuntimeException){}
check(C::remember('document',$read)===5,'Exception retained private data');
C::scope(static function(){
 try{C::remember('failure',static function(){throw new RuntimeException('offline');});}catch(RuntimeException){}
 check(C::remember('failure',static fn()=>'recovered')==='recovered','Failure was cached');
 C::remember('tenant:1:user:1',static fn()=>'private');
 check(C::remember('tenant:2:user:1',static fn()=>'other')==='other','Owner key collision');
 for($i=0;$i<34;$i++)C::remember('entry:'.$i,static fn()=>'value');
 check(C::remember('entry:0',static fn()=>'fresh')==='fresh','Entry bound not enforced');
 C::remember('large',static fn()=>str_repeat('a',4194305));
 check(C::remember('large',static fn()=>'fresh')==='fresh','Oversized result cached');
 for($i=0;$i<5;$i++)C::remember('mb:'.$i,static fn()=>str_repeat('a',1048576));
 check(C::remember('mb:0',static fn()=>'fresh')==='fresh','Byte bound not enforced');
});
$schema=['type'=>'object','properties'=>[]];$calls=0;$executed=0;
$tool=new AgentFunctionTool('read_session_context','read',$schema,static function()use(&$executed){return ['read'=>++$executed];},12);
$provider=new MarketNeuronProvider('fixture',static function()use(&$calls){
 $n=++$calls;
 return $n<=5?['tool_calls'=>[['id'=>'read-'.$n,'function'=>['name'=>'read_session_context','arguments'=>'{"payload":{}}']]]]:['content'=>'done'];
});
$agent=(new Agent())->setThreadId('read-budget-fixture')->setAiProvider($provider)->setTools([$tool]);
check($agent->chat(new UserMessage('read'))->getMessage()->getContent()==='done'&&$executed===5,'Fifth read prevented completion');
$generation=new AgentFunctionTool('plan_creation','generate',$schema,static fn()=>[]);
check($generation->getMaxRuns()===4&&$tool->getMaxRuns()===12,'Generation/read budgets changed unexpectedly');
echo "Read cache passed: 10 repeated loads -> 1; scope cleanup, nesting, bounds, failed reads, five actual Neuron reads and generation cap\n";
