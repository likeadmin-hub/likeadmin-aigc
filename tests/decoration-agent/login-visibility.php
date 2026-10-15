<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
require $root.'/runtime-loader.php';
(new AigcRuntimeApp($root))->initialize();
set_exception_handler(static function(Throwable $error):void{fwrite(STDERR,$error->getMessage()."\n".$error->getTraceAsString()."\n");exit(1);});

use think\facade\Db;
use app\common\service\decorate\agent\{DecorationAgentCatalog as C,DecorationAgentOperations as O,DecorationAgentService as S,DecorationAgentDecision as Decision,DecorationAgentDesignContext as D,DecorationAgentDesignService as Design,DecorationAgentMerge as Merge};
use app\common\service\decorate\{DecorateDataSourceService as Sources,DecorateTemplateService as Templates};

$checks=0;
function visibilityCheck(bool $ok,string $message):void{global $checks;if(!$ok)throw new RuntimeException($message);$checks++;}
function visibilityReject(callable $call,string $message):void{try{$call();}catch(InvalidArgumentException $error){visibilityCheck(true,$message);return;}throw new RuntimeException('Accepted: '.$message);}
function visibilityApply(array $page,$audience,array $extra=[],array $scope=['scope'=>'page']):array{
    return O::apply($page,[array_replace(['type'=>'set_visibility','widget_id'=>'private','audience'=>$audience],$extra)],$scope);
}
function visibleIds(array $payload,string $audience):array{
    $wire=Sources::applyToPage(['data'=>S::json($payload['data']),'meta'=>'[]'],['tenant_id'=>1,'user_id'=>0,'login_status'=>$audience]);
    return array_keys(O::index(S::decode($wire['data'])));
}

request()->tenantId=1;
$private=C::create('account-quota','private');
$container=C::create('card','container');$container['children']=[$private];
$base=['data'=>[$container,C::create('rich-text','public')],'meta'=>[],'name'=>'visibility fixture','new_page'=>false];
$logged=visibilityApply($base,'logged_in');
visibilityCheck($logged['candidate']['data'][0]['children'][0]['visibility']===['mode'=>'all','rules'=>[['field'=>'login_status','operator'=>'eq','value'=>'logged_in']]],'writes the actual runtime operator key on a nested component');
visibilityCheck(!isset($base['data'][0]['children'][0]['visibility']),'candidate operation does not mutate its source');
visibilityCheck($logged['candidate']['data'][0]['children'][0]['content']===$private['content'],'account data source and real values are preserved');
visibilityCheck(visibleIds($logged['candidate'],'guest')===['container','public'],'guest runtime filters the private nested component');
visibilityCheck(visibleIds($logged['candidate'],'logged_in')===['container','private','public'],'logged-in runtime keeps the real source component');
visibilityCheck($logged['image_requests']===[]&&$logged['changes'][0]['type']==='set_visibility','visibility is a candidate change without image generation');
$guest=visibilityApply($logged['candidate'],'guest')['candidate'];
visibilityCheck(visibleIds($guest,'guest')===['container','private','public']&&visibleIds($guest,'logged_in')===['container','public'],'guest-only audience uses the same runtime rule');
$all=visibilityApply($guest,'all')['candidate'];
visibilityCheck($all['data'][0]['children'][0]['visibility']===['mode'=>'all','rules'=>[]]&&visibleIds($all,'guest')===visibleIds($all,'logged_in'),'all clears only the previously controlled login condition');
foreach([true,false,1,0,null,[],['logged_in'],'admin','LOGGED_IN',' logged_in'] as $invalid)visibilityReject(fn()=>visibilityApply($base,$invalid),'strict audience type and enum');
foreach([['fields'=>['visibility'=>[]]],['visibility'=>[]],['rules'=>[]],['mode'=>'any'],['unexpected'=>true]] as $extra)visibilityReject(fn()=>visibilityApply($base,'all',$extra),'unknown operation fields cannot write arbitrary root data');
foreach([
    ['mode'=>'all','rules'=>[['field'=>'membership_status','operator'=>'eq','value'=>'active']]],
    ['mode'=>'any','rules'=>[['field'=>'login_status','operator'=>'eq','value'=>'logged_in'],['field'=>'path','operator'=>'eq','value'=>'/private']]],
    ['mode'=>'all','rules'=>[['field'=>'login_status','operator'=>'neq','value'=>'guest']]],
    ['mode'=>'all','rules'=>[['field'=>'login_status','op'=>'neq','value'=>'guest']]],
    ['mode'=>'all','rules'=>[['field'=>'login_status','operator'=>'eq','value'=>'admin']]],
    ['mode'=>'all','rules'=>[['field'=>'login_status','operator'=>'eq','value'=>'guest','future'=>true]]],
    ['mode'=>'unknown','rules'=>[]],['mode'=>'all','rules'=>'invalid'],['mode'=>'all','rules'=>[],'future_policy'=>true],
] as $existing){
    $restricted=$base;$restricted['data'][0]['children'][0]['visibility']=$existing;
    foreach(['all','guest','logged_in'] as $audience)visibilityReject(fn()=>visibilityApply($restricted,$audience),'complex or malformed restrictions cannot be replaced or cleared');
}
$inheritedRule=['mode'=>'all','rules'=>[['field'=>'membership_status','operator'=>'eq','value'=>'active']]];
foreach([false,true] as $nullRoot){
    $inherited=$base;$inherited['data'][0]['children'][0]['content']['visibility']=$inheritedRule;
    if($nullRoot)$inherited['data'][0]['children'][0]['visibility']=null;
    visibilityCheck(!Sources::passesVisibility($inheritedRule,['membership_status'=>'none']),'legacy effective content restriction really denies the runtime context');
    foreach(['all','guest','logged_in'] as $audience)visibilityReject(fn()=>visibilityApply($inherited,$audience),'legacy content restrictions cannot be shadowed by a new root rule');
}
$legacyContent=$base;$legacyContent['data'][0]['children'][0]['content']['visibility']=['rules'=>[['field'=>'login_status','operator'=>'eq','value'=>'guest']]];
$migrated=visibilityApply($legacyContent,'logged_in')['candidate'];
visibilityCheck(visibleIds($migrated,'guest')===['container','public']&&visibleIds($migrated,'logged_in')===['container','private','public'],'simple effective content login rule can migrate to the runtime-priority root');
visibilityCheck($migrated['data'][0]['children'][0]['content']===$legacyContent['data'][0]['children'][0]['content'],'migration preserves legacy content and source fields');
$legacy=$base;$legacy['data'][0]['children'][0]['visibility']=['mode'=>'any','rules'=>[['field'=>'login_status','op'=>'eq','value'=>'guest']]];
visibilityCheck(visibilityApply($legacy,'logged_in')['candidate']['data'][0]['children'][0]['visibility']['rules'][0]===['field'=>'login_status','operator'=>'eq','value'=>'logged_in'],'a single historical PC eq rule is safely canonicalized');
visibilityCheck(visibilityApply($base,'logged_in',[],['scope'=>'selected','selected_id'=>'container'])['candidate']===$logged['candidate'],'selected container may control its nested component');
visibilityReject(fn()=>visibilityApply($base,'logged_in',[],['scope'=>'selected','selected_id'=>'public']),'selected scope cannot control a sibling subtree');
visibilityReject(fn()=>visibilityApply($base,'logged_in',['widget_id'=>'container'],['scope'=>'selected','selected_id'=>'private']),'selected child cannot control its parent');
visibilityReject(fn()=>visibilityApply($base,'logged_in',['widget_id'=>'missing']),'missing component is rejected');
visibilityReject(fn()=>O::apply($base,[['type'=>'update_component','widget_id'=>'private','fields'=>['visibility'=>['mode'=>'all','rules'=>[]]]]],['scope'=>'page']),'ordinary fields still cannot write arbitrary component roots');
$concurrent=$logged['candidate'];$concurrent['data'][0]['children'][0]['visibility']=['mode'=>'all','rules'=>[['field'=>'login_status','operator'=>'eq','value'=>'guest']]];
visibilityCheck(Merge::merge($logged['candidate'],$concurrent,$all)['conflicts']!==[],'concurrent login edits remain real merge conflicts');
try{Design::assertOperation(['design_first'=>true],['phase'=>'design'],['type'=>'set_visibility']);throw new RuntimeException('design gate bypass');}catch(RuntimeException $error){visibilityCheck($error->getMessage()!=='design gate bypass','design approval still gates visibility edits');}

// Real persistence/apply/rollback under an outer rolled-back transaction. No
// worker, text model, provider, published page or existing business row is used.
Db::startTrans();
try{
    $template=Templates::add(1,['name'=>'login visibility isolated','terminal'=>'mobile']);
    $page=Templates::pageLists(1,(int)$template['id'],'mobile')[0];
    $saved=Templates::savePage(1,['template_id'=>$template['id'],'id'=>$page['id'],'terminal'=>'mobile','name'=>$base['name'],'data'=>S::json($base['data']),'meta'=>$page['draft_meta']]);
    $before=S::payload($saved);$session=S::createSession(1,1,['template_id'=>$template['id'],'page_id'=>$page['id']]);
    $sessionRow=Db::name('decorate_agent_session')->where('id',$session['id'])->find();
    $run=(int)Db::name('decorate_agent_run')->insertGetId(['tenant_id'=>1,'admin_id'=>1,'session_id'=>$session['id'],'status'=>'running','input_json'=>'{}','context_json'=>'{}','events_json'=>'[]','result_json'=>'{}','idempotency_key'=>'visibility:'.bin2hex(random_bytes(8))]);
    $parsed=Decision::parse(S::json(['reply'=>'调整已生成候选，尚未应用或发布。','kind'=>'final','operations'=>[['type'=>'set_visibility','widget_id'=>'private','audience'=>'logged_in']]]));
    $edit=O::apply($before,$parsed['operations'],['scope'=>'page']);
    $ctx=['base'=>$before,'candidate'=>$edit['candidate'],'changes'=>$edit['changes'],'facts'=>[],'observations'=>[],'receipts'=>[]];
    $persist=new ReflectionMethod(S::class,'persistDecision');$args=[1,1,$run,$sessionRow,['sources'=>[]],&$ctx,$parsed,[]];$persist->invokeArgs(null,$args);
    $candidateId=(int)Db::name('decorate_agent_run')->where('id',$run)->value('candidate_id');
    visibilityCheck($candidateId>0&&S::candidate(1,1,$candidateId)['status']==='ready','a no-apply edit produces a durable ready candidate');
    visibilityCheck(S::hash(S::payload(Templates::pageDetail(1,['template_id'=>$template['id'],'id'=>$page['id']])))===S::hash($before),'candidate generation does not apply to the draft');
    $payload=S::decode(Db::name('decorate_agent_candidate')->where('id',$candidateId)->value('payload_json'));
    visibilityCheck(visibleIds($payload,'guest')===['container','public'],'reloaded candidate retains real guest filtering');
    $applied=S::apply(1,1,['candidate_id'=>$candidateId,'idempotency_key'=>'visibility-apply:'.$candidateId]);
    visibilityCheck(visibleIds(S::payload($applied['page']),'guest')===['container','public'],'explicit apply persists the visibility root');
    $checkpoint=Db::name('decorate_agent_checkpoint')->where('id',$applied['checkpoint_id'])->find();
    visibilityCheck(S::decode($checkpoint['after_json'])['data'][0]['children'][0]['visibility']===$logged['candidate']['data'][0]['children'][0]['visibility'],'checkpoint records the same nested visibility rule');
    $undo=S::rollback(1,1,['checkpoint_id'=>$applied['checkpoint_id'],'idempotency_key'=>'visibility-undo:'.$candidateId]);
    visibilityCheck(S::hash(S::payload($undo['page']))===S::hash($before),'rollback restores the original audience and source payload');
}finally{Db::rollback();}

$detail=D::contract('account-quota');
visibilityCheck(($detail['visibility_operation']['audiences']??[])===['all','guest','logged_in'],'full component knowledge exposes the bounded visibility operation');
visibilityCheck(($detail['visibility_operation']['runtime_rule_operator']??'')==='operator','knowledge uses the real runtime operator key');
visibilityCheck(str_contains(D::guide()['candidate_workflow']??'','候选')&&str_contains(Decision::prompt(),'不应用'),'model-facing guidance distinguishes candidate changes from applying');
echo "login visibility: $checks checks passed (real DB transaction rolled back; no provider)\n";
