<?php
declare(strict_types=1);
require __DIR__.'/../../vendor/autoload.php';
use app\common\service\decorate\agent\DecorationAgentDecision;
function check($ok,$message){if(!$ok)throw new RuntimeException($message);}
$d=DecorationAgentDecision::parse('{"kind":"final","operations":[{"type":"update_component","widget_id":"n","fields":{"content.limit":3}}]}');
check($d['kind']==='final' && count($d['operations'])===1,'simple task emits one decision');
$d=DecorationAgentDecision::parse("```json\n{\"kind\":\"clarify\",\"question\":\"修改哪个导航？\"}\n```");
check($d['question']==='修改哪个导航？','clarification retained');
foreach(['{"kind":"execute_shell"}','{"kind":"final","operations":"bad"}','{"kind":"clarify"}'] as $json){try{DecorationAgentDecision::parse($json);throw new RuntimeException('accepted invalid decision');}catch(InvalidArgumentException $e){}}
try{DecorationAgentDecision::parse(json_encode(['kind'=>'tools','operations'=>array_fill(0,21,['type'=>'catalog'])]));throw new RuntimeException('tool cap ignored');}catch(InvalidArgumentException $e){}
check(str_contains(DecorationAgentDecision::prompt(),'参考材料'),'reference trust boundary in system prompt');
echo "decision: 7 checks passed\n";
