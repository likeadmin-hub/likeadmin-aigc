<?php
declare(strict_types=1);
require __DIR__.'/../../vendor/autoload.php';
use app\common\service\decorate\agent\DecorationAgentDecision as D;
use app\common\service\decorate\agent\DecorationAgentOperations as O;
use app\common\service\decorate\agent\DecorationAgentCatalog as C;
use app\common\service\decorate\agent\DecorationAgentService as S;
$checks=0;
function check($ok,$message){global $checks;if(!$ok)throw new RuntimeException($message);$checks++;}
function invalidDecision(string $json): void {try{D::parse($json);throw new RuntimeException('accepted invalid decision');}catch(InvalidArgumentException $e){check(true,'invalid rejected');}}
$d=D::parse('{"kind":"final","operations":[{"type":"update_component","widget_id":"n","fields":{"content.limit":3}}]}');
check($d['kind']==='final' && count($d['operations'])===1,'simple task emits one decision');
$d=D::parse("```json\n{\"kind\":\"clarify\",\"question\":\"修改哪个导航？\"}\n```");
check($d['question']==='修改哪个导航？','clarification retained');
foreach(['{"kind":"execute_shell"}','{"kind":"final","operations":"bad"}','{"kind":"clarify"}'] as $json)invalidDecision($json);
invalidDecision(json_encode(['kind'=>'tools','operations'=>array_fill(0,21,['type'=>'catalog'])]));
check(str_contains(D::prompt(),'参考材料'),'reference trust boundary in system prompt');
// Actual live-model alias/fields that previously consumed all six decision rounds.
$fixture=['reply'=>'准备将页面标题改为灵感工作室，并使用浅蓝导航背景。','kind'=>'final','operations'=>[['op'=>'configure_page','fields'=>['content.title'=>'灵感工作室','content.nav_bg_color'=>'#EDF4FF']]]];
$decision=D::parse(json_encode($fixture,JSON_UNESCAPED_UNICODE));
check($decision['operations'][0]['type']==='configure_page','live op alias normalized for orchestration');
check(!isset($decision['operations'][0]['op']),'canonical operation uses type');
check(isset(C::pageMeta()['content_fields']['nav_bg_color']),'navigation background belongs to page-meta contract');
$page=['data'=>[C::create('news','n')],'meta'=>[C::pageMeta()['defaults']],'name'=>'原页面'];
$applied=O::apply($page,$decision['operations'],['scope'=>'page']);
check($applied['candidate']['meta'][0]['content']['title']==='灵感工作室','live alias fixture applies title');
check($applied['candidate']['meta'][0]['content']['nav_bg_color']==='#EDF4FF','live alias fixture applies navigation color');
check($applied['candidate']['data']===$page['data'],'page-meta edits preserve widgets');
foreach([['type'=>'catalog'],['op'=>'catalog'],['type'=>'catalog','op'=>'configure_page'],['type'=>null,'op'=>'catalog']] as $op){$d=D::parse(json_encode(['kind'=>'tools','operations'=>[$op]]));check($d['operations'][0]['type']==='catalog','engine-compatible type precedence');}
foreach([[],['type'=>''],['op'=>''],['type'=>'   '],['op'=>4],['type'=>false,'op'=>'catalog'],['type'=>[]],['op'=>null]] as $op)invalidDecision(json_encode(['kind'=>'tools','operations'=>[$op]]));
$failure=new ReflectionMethod(S::class,'decisionFailureLimitReached');$failure->setAccessible(true);$context=[];
check(!$failure->invokeArgs(null,[&$context,'装修操作无效']),'first invalid result allows one correction');
$restored=json_decode(json_encode($context),true);
check($failure->invokeArgs(null,[&$restored,'装修操作无效']),'second identical invalid result stops paid automatic rounds');
check(!$failure->invokeArgs(null,[&$restored,'模型未返回有效装修方案']),'different parse error has its own bounded correction');
check(str_contains(D::prompt(),'{"type":"configure_page","fields":{"content.title":"灵感工作室","content.nav_bg_color":"#EDF4FF"}}'),'prompt includes exact operation schema example');
echo "decision: $checks checks passed\n";
