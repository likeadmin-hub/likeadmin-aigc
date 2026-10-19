<?php
declare(strict_types=1);
require __DIR__.'/../../vendor/autoload.php';
use app\common\service\decorate\agent\DecorationAgentExecutionPolicy as P;
use app\common\service\decorate\agent\DecorationAgentExecutionBudget as B;
$checks=0;
function unlimitedCheck(bool $ok,string $label): void {global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
$new=['execution_policy'=>P::UNLIMITED,'budget_points'=>0];$legacy=['budget_points'=>25];
unlimitedCheck(P::unlimited($new)&&!P::unlimited($legacy),'only newly frozen policy is unlimited');
unlimitedCheck(P::allowsModel($new,10000)&&!P::allowsModel($legacy,6),'model cumulative cap removed only for new runs');
unlimitedCheck(P::allowsImage($new,10000)&&!P::allowsImage($legacy,5),'image cumulative cap removed only for new runs');
unlimitedCheck(P::allowsCost($new,10000,10000)&&!P::allowsCost($legacy,24,2),'new runs ignore cumulative budget while legacy retains it');
unlimitedCheck(P::remaining($new,10000)===null&&P::remaining($legacy,24)===1.0,'unlimited represented as null rather than infinity');
$used=40;$context=['candidate'=>['unchanged'=>true]];
for($i=0;$i<100;$i++)unlimitedCheck(B::consume($used,$context,true),'unlimited tools continue past old40 cap');
unlimitedCheck($used===140&&$context['tool_calls']===140&&$context['candidate']===['unchanged'=>true],'usage counters continue without changing candidate');
$limits=B::context($used,true);unlimitedCheck($limits['remaining_tools']===null&&$limits['run_tools']===null&&$limits['decision_operations']===20,'batch shape limit is preserved');
unlimitedCheck(json_decode(json_encode($limits),true)===$limits,'policy metadata remains JSON safe');
echo "unlimited-execution: $checks checks passed\n";
