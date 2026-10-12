<?php
declare(strict_types=1);
chdir(dirname(__DIR__,2));require 'vendor/autoload.php';(new think\App())->initialize();config(['enabled'=>true],'decoration_agent');request()->tenantId=1;
use think\facade\Db;
use app\common\service\decorate\DecorateTemplateService as T;
use app\common\service\decorate\agent\DecorationAgentService as S;
use app\common\service\decorate\agent\DecorationAgentCatalog as C;
require 'tests/decoration-agent/evaluate-result.php';
$fixtures=json_decode(file_get_contents('tests/decoration-agent/evaluation-fixtures.json'),true,512,JSON_THROW_ON_ERROR)['cases'];
if(getenv('DECORATION_EVAL_PAID')!=='1')throw new RuntimeException('Set DECORATION_EVAL_PAID=1 only after authorizing real model test costs');
$templateId=(int)getenv('DECORATION_EVAL_TEMPLATE');if($templateId<=0)throw new RuntimeException('Choose a dedicated inactive test template with DECORATION_EVAL_TEMPLATE');
$verifyTemplate=Db::name('decorate_template')->where(['id'=>$templateId,'tenant_id'=>1])->find();if(!$verifyTemplate||(int)($verifyTemplate['is_active']??$verifyTemplate['status']??0)===1)throw new RuntimeException('The evaluation template must be inactive');
$limit=(int)($argv[1]??1);$start=(int)($argv[2]??0);$modelId=(int)($argv[3]??2);$evidenceFile='/tmp/decoration-agent-real-evaluation-'.$modelId.'.json';
$evidenceFile='/tmp/decoration-agent-real-evaluation-'.$templateId.'-'.$modelId.'.json';
$evidence=is_file($evidenceFile)?json_decode(file_get_contents($evidenceFile),true):['started_at'=>date(DATE_ATOM),'template_id'=>$templateId,'model_id'=>$modelId,'cases'=>[],'points_cap'=>200,'image_provider_submissions'=>0];
if(($evidence['template_id']??0)!==$templateId||($evidence['model_id']??0)!==$modelId)throw new RuntimeException('Evidence does not match the fixed model/template');
set_exception_handler(function($e){fwrite(STDERR,$e->getMessage().'\n');exit(1);});
$baseline=Db::name('decorate_page')->where(['tenant_id'=>1,'id'=>1])->find();$baselineHash=hash('sha256',S::json($baseline));
$step=new ReflectionMethod(S::class,'step');$step->setAccessible(true);
$spent=array_sum(array_column($evidence['cases'],'spent_points'));
for($index=$start;$index<min(count($fixtures),$start+$limit);$index++){
 $fixture=$fixtures[$index];$id=$fixture['id'];if(in_array($id,array_column(array_filter($evidence['cases'],fn($c)=>!empty($c['passed'])),'fixture_id'),true)){echo "SKIP already recorded $id\n";continue;}
 $record=['fixture_id'=>$id,'task'=>$fixture['task'],'model_id'=>$modelId,'started_at'=>date(DATE_ATOM),'real_provider'=>true,'applied'=>false,'image_provider_submitted'=>false];
 try{
  if($id==='allowed-link'){ $linkPage=T::addPage(1,['template_id'=>$templateId,'terminal'=>'mobile','name'=>'创作页面','page_code'=>'eval_create_'.bin2hex(random_bytes(6))]);$fixture['expected'][0]['value']='/pages/diy/diy';$fixture['expected'][]=['kind'=>'equals','path'=>'candidate.data.0.content.link.query.code','value'=>$linkPage['page_code']];$record['fixture_adapter']='Real isolated catalog custom-page path+query replaces unavailable static /pages/create/create';$record['link_page_id']=$linkPage['id']; }
  $page=T::addPage(1,['template_id'=>$templateId,'terminal'=>'mobile','name'=>'Eval '.$id,'page_code'=>'eval_'.substr(md5($id.microtime()),0,16)]);
  $page=T::savePage(1,['template_id'=>$templateId,'id'=>$page['id'],'terminal'=>'mobile','name'=>$fixture['initial']['name'],'data'=>S::json($fixture['initial']['data']),'meta'=>S::json($fixture['initial']['meta'])]);
  $record['page_id']=(int)$page['id'];$session=S::createSession(1,1,['template_id'=>$templateId,'page_id'=>$page['id']]);$record['session_id']=(int)$session['id'];
  $imageCase=(bool)array_filter($fixture['expected'],fn($a)=>str_starts_with($a['kind'],'image_request'));
  $params=['session_id'=>$session['id'],'message'=>$fixture['task'],'model_selection'=>['id'=>$modelId],'scope'=>$fixture['scope']['scope'],'selected_id'=>$fixture['scope']['selected_id']??'','catalog_hash'=>C::hash(),'budget_points'=>100,'sources'=>$imageCase?['generate']:['tenant','content','upload'],'image_strategy'=>$imageCase?'generate_first':'existing_first','image_model_selection'=>$imageCase?['product_id'=>195,'market_sku_id'=>720]:[],'attachments'=>[],'idempotency_key'=>'eval:'.$id.':'.bin2hex(random_bytes(6))];
  $quote=S::quote(1,1,$params);$record['quote']=$quote;
  if($spent+(float)$quote['text_estimated_points']>200){echo "STOP CAP $id spent=$spent nextQuote=".$quote['text_estimated_points']."\n";break;}
  echo "START $id page=".$page['id']." quote=".$quote['text_estimated_points']." cumulative=$spent\n";flush();
  $run=S::start(1,1,$params);$record['run_id']=$run['id'];
  for($loop=0;$loop<7;$loop++){
   $run=S::run(1,1,(int)$run['id']);
   if(!in_array($run['status'],['queued','running'],true))break;
   if($spent+(float)$run['spent_points']>190){S::cancel(1,1,(int)$run['id']);$step->invoke(null,(int)$run['id'],'evaluation');break;}
   try{$step->invoke(null,(int)$run['id'],'evaluation');}catch(Throwable $e){$record['step_error']=$e->getMessage();break;}
   $run=S::run(1,1,(int)$run['id']);echo "STEP $id calls=".$run['model_calls']." status=".$run['status']." points=".$run['spent_points']."\n";flush();
  }
  $run=S::run(1,1,(int)$run['id']);$record['status']=$run['status'];$record['model_calls']=$run['model_calls'];$record['spent_points']=$run['spent_points'];$record['run']=$run;
  $candidate=$run['candidate_id']?S::candidate(1,1,$run['candidate_id']):null;
  $row=Db::name('decorate_agent_run')->where('id',$run['id'])->find();$context=S::decode($row['context_json']);$record['receipts']=$context['receipts']??[];
  $record['candidate']=$candidate;$requests=array_map(fn($slot)=>$slot['request'],$run['image_slots']);
  if($candidate)$record['evaluation']=evaluateDecorationAgentFixture($fixture,['candidate'=>$candidate,'image_requests'=>$requests]);
  $record['passed']=!empty($record['evaluation']['passed'])&&in_array($run['status'],['completed','waiting_images'],true);
  if($run['status']==='waiting_images'||in_array($run['status'],['queued','running'],true)){S::cancel(1,1,(int)$run['id']);$step->invoke(null,(int)$run['id'],'evaluation');$record['after_cancel']=S::run(1,1,(int)$run['id'])['status'];}
  if($candidate)S::discard(1,1,(int)$candidate['id']);
  $unchanged=S::payload(T::pageDetail(1,['template_id'=>$templateId,'id'=>$page['id']]))===S::payload($page);$record['draft_unchanged']=$unchanged;if(!$unchanged)$record['passed']=false;
  $spent+=(float)$record['spent_points'];
 }catch(Throwable $e){$record['error']=$e->getMessage();$record['passed']=false;$record['spent_points']=$record['spent_points']??0;}
 if(!empty($record['run_id'])){ $pending=S::run(1,1,(int)$record['run_id']);if(in_array($pending['status'],['queued','running','waiting_images'],true)){S::cancel(1,1,(int)$record['run_id']);$step->invoke(null,(int)$record['run_id'],'evaluation');$record['after_cancel']=S::run(1,1,(int)$record['run_id'])['status'];}}
 $record['finished_at']=date(DATE_ATOM);$evidence['cases'][]=$record;$evidence['spent_points']=$spent;$evidence['existing_page1_unchanged']=$baselineHash===hash('sha256',S::json(Db::name('decorate_page')->where(['tenant_id'=>1,'id'=>1])->find()));
 file_put_contents($evidenceFile,S::json($evidence));echo "RESULT $id ".($record['passed']?'PASS':'FAIL')." status=".($record['status']??'error')." points=".$record['spent_points']." total=$spent ".($record['error']??$record['step_error']??'')."\n";flush();
 if($spent>=190){echo "STOP cumulative budget guard total=$spent\n";break;}
}
echo "EVIDENCE $evidenceFile cases=".count($evidence['cases'])." passed=".count(array_filter($evidence['cases'],fn($case)=>$case['passed']))." spent=$spent\n";
