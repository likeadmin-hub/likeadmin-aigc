<?php
declare(strict_types=1);
require __DIR__.'/../../vendor/autoload.php';
use app\common\service\decorate\agent\DecorationAgentExecutionBudget as B;
use app\common\service\decorate\agent\DecorationAgentDecision as D;
$checks=0;
function verifyBudget(bool $ok,string $message):void {global $checks;if(!$ok)throw new RuntimeException($message);$checks++;}
// Live run740 read six contracts/sources, then returned 18 valid edits. The
// old cumulative 20-call ceiling stopped mid-decision and produced no candidate.
$used=0;
foreach([6,18] as $batch){
    $decision=D::parse(json_encode(['kind'=>'tools','operations'=>array_fill(0,$batch,['type'=>'catalog'])]));
    verifyBudget(count($decision['operations'])===$batch,'each live-sized decision stays valid');
    foreach($decision['operations'] as $op){verifyBudget(B::allowsNext($used),'query phase must leave capacity for the valid edit phase');$used++;}
}
verifyBudget(B::context($used)['remaining_tools']===16,'model sees cumulative remaining capacity');
for(;$used<40;$used++)verifyBudget(B::allowsNext($used),'bounded remaining calls permitted');
verifyBudget(!B::allowsNext($used),'41st tool must never execute');
verifyBudget(B::context(99)['remaining_tools']===0,'restored oversized counters cannot replenish budget');
verifyBudget(B::context(-1)['used_tools']===0,'invalid negative counters cannot enlarge budget');
$used=6;$context=['tool_calls'=>6,'candidate'=>['preserved'=>true]];
for($i=0;$i<6;$i++)verifyBudget(B::consume($used,$context),'same-batch query consumes one tool');
verifyBudget(B::context($context['tool_calls'])['remaining_tools']===28,'get_context within the same decision sees current counters');
verifyBudget($context['candidate']===['preserved'=>true],'counter sync does not touch candidate');
$used=40;$before=$context;verifyBudget(!B::consume($used,$context)&&$used===40&&$context===$before,'denied call neither executes nor mutates context');
try{D::parse(json_encode(['kind'=>'tools','operations'=>array_fill(0,21,['type'=>'catalog'])]));throw new RuntimeException('per-decision limit expanded');}catch(InvalidArgumentException $e){verifyBudget(true,'single response still capped at20');}
echo "execution-budget: $checks checks passed\n";
