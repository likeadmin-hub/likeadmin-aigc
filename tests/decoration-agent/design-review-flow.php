<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);require $root.'/runtime-loader.php';(new AigcRuntimeApp($root))->initialize();
set_exception_handler(static function(Throwable $error): void {fwrite(STDERR,$error->getMessage().PHP_EOL.$error->getTraceAsString());exit(1);});
use think\facade\Db;
use app\common\enum\FileEnum;
use app\common\service\decorate\agent\{DecorationAgentAcceptance as A,DecorationAgentService as S,DecorationAgentDesignService as D,DecorationAgentCatalog as C,DecorationAgentOperations as O,DecorationAgentMediaService as M};
use app\common\service\decorate\DecorateTemplateService as T;
use app\common\service\power\MarketTextModelRuntimeService as Text;
$checks=0;function reviewFlowCheck(bool $ok,string $label): void {global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
function reviewFlowReject(callable $call,string $label): void {try{$call();}catch(Exception $error){reviewFlowCheck(true,$label);return;}throw new RuntimeException('accepted: '.$label);}
$paths=[];Db::startTrans();
try {
 request()->tenantId=1;
 $template=T::add(1,['name'=>'design acceptance isolated','terminal'=>'mobile']);$page=T::pageLists(1,$template['id'],'mobile')[0];
 $session=S::createSession(1,1,['template_id'=>$template['id'],'page_id'=>$page['id']]);
 $draft=S::payload(T::pageDetail(1,['template_id'=>$template['id'],'id'=>$page['id']]));
 $makePng=static function(int $color,int $compression=6)use(&$paths): array {
  $uri='uploads/design_acceptance_'.bin2hex(random_bytes(8)).'.png';$path=public_path().$uri;$im=imagecreatetruecolor(3,4);imagefill($im,0,0,$color);imagepng($im,$path,$compression);imagedestroy($im);$paths[]=$path;
  return ['image_uri'=>$uri,'storage_scope'=>'tenant','storage_engine'=>'local','storage_domain'=>'','width'=>3,'height'=>4,'sha256'=>hash_file('sha256',$path),'url'=>'/'.$uri];
 };
 $asset=$makePng(0x112244);$screenshot=$makePng(0x223355);
 $newFile=static fn(array $a,int $tenant=1,int $source=FileEnum::SOURCE_ADMIN)=>(int)Db::name('tenant_file')->insertGetId(['tenant_id'=>$tenant,'source'=>$source,'type'=>FileEnum::IMAGE_TYPE,'uri'=>$a['image_uri'],'storage_scope'=>$a['storage_scope'],'storage_engine'=>'local','storage_domain'=>'']);
 $fileId=$newFile($screenshot);$sameFile=$newFile($asset);$otherTenant=$newFile($screenshot,2);$userFile=$newFile($screenshot,1,FileEnum::SOURCE_USER);
 $designRun=(int)Db::name('decorate_agent_run')->insertGetId(['tenant_id'=>1,'admin_id'=>1,'session_id'=>$session['id'],'status'=>'completed','input_json'=>S::json(['design_first'=>true]),'context_json'=>'{}','result_json'=>'{}','events_json'=>'[]','idempotency_key'=>'acceptance-design:'.bin2hex(random_bytes(8))]);
 $slot=(int)Db::name('decorate_agent_image_slot')->insertGetId(['tenant_id'=>1,'admin_id'=>1,'session_id'=>$session['id'],'run_id'=>$designRun,'candidate_id'=>0,'slot_key'=>'design:page','status'=>'success','request_json'=>S::json(['purpose'=>'design']),'result_json'=>S::json(['status'=>'success','billing_status'=>'settled','images'=>[$asset]])]);
 $imageTask=(int)Db::name('ai_app_task')->insertGetId(['idempotency_key'=>'design-acceptance-image:'.bin2hex(random_bytes(8)),'task_no'=>'design-acceptance-image:'.bin2hex(random_bytes(8)),'tenant_id'=>1,'user_id'=>0,'app_code'=>'system_default','business_table'=>'decorate_agent_image_slot','business_id'=>$slot,'status'=>'success']);
 $imageReceipt=(int)Db::name('ai_consumption_log')->insertGetId(['consume_no'=>'design-acceptance-image:'.bin2hex(random_bytes(8)),'tenant_id'=>1,'user_id'=>0,'app_task_id'=>$imageTask,'app_code'=>'system_default','protocol'=>'image_generate','run_status'=>'success','billing_status'=>'settled','response_summary'=>S::json(['images'=>[$asset]])]);
 Db::name('decorate_agent_image_slot')->where('id',$slot)->update(['consumption_id'=>$imageReceipt]);
 $design=['status'=>'approved','revision'=>1,'slot_id'=>$slot,'prompt'=>'fixture design','blueprint'=>[],'asset'=>array_intersect_key($asset,array_flip(['image_uri','storage_scope','storage_engine','storage_domain','width','height','sha256']))];
 $hashMethod=new ReflectionMethod(D::class,'designHash');$hashMethod->setAccessible(true);$design['hash']=$hashMethod->invoke(null,$design);
 Db::name('decorate_agent_run')->where('id',$designRun)->update(['context_json'=>S::json(['design'=>$design])]);
 Db::name('decorate_agent_session')->where('id',$session['id'])->update(['preferences'=>S::json(['_design_state'=>['run_id'=>$designRun,'revision'=>1]])]);
 $payload=$draft;$payload['data']=[C::create('rich-text','intro')];$payload['_decoration_design']=['run_id'=>$designRun,'revision'=>1,'hash'=>$design['hash']];
 $candidateId=(int)Db::name('decorate_agent_candidate')->insertGetId(['tenant_id'=>1,'admin_id'=>1,'session_id'=>$session['id'],'run_id'=>$designRun,'status'=>'ready','revision'=>1,'base_hash'=>S::hash($draft),'base_json'=>S::json($draft),'payload_json'=>S::json($payload),'changes_json'=>'[]']);
 $candidate=Db::name('decorate_agent_candidate')->where('id',$candidateId)->find();
 $models=array_values(array_filter(Text::textOptions(1),fn($m)=>!empty($m['supports_vision'])));
 $ordinaryInput=['message'=>'按已确认图还原页面','catalog_hash'=>C::hash(),'scope'=>'page','selected_id'=>'','sources'=>['content','tenant'],'model_selection'=>['id'=>$models[0]['id']],'image_model_selection'=>[],'image_strategy'=>'existing_first','attachments'=>[],'requires_vision'=>true,'design_first'=>false,'budget_points'=>25];
 $implementation=(int)Db::name('decorate_agent_run')->insertGetId(['tenant_id'=>1,'admin_id'=>1,'session_id'=>$session['id'],'status'=>'completed','input_json'=>S::json($ordinaryInput),'context_json'=>'{}','result_json'=>'{}','events_json'=>'[]','idempotency_key'=>'review-flow-implementation:'.bin2hex(random_bytes(8))]);Db::name('decorate_agent_candidate')->where('id',$candidateId)->update(['run_id'=>$implementation]);
 $p=['candidate_id'=>$candidateId,'revision'=>1,'candidate_hash'=>S::hash($payload),'expected_hash'=>S::hash($draft),'design_run_id'=>$designRun,'design_hash'=>$design['hash'],'screenshot_file_id'=>$fileId,'budget_points'=>25,'idempotency_key'=>'fixture-review'];

 // Exercise the real queue entry point without submitting any provider work.
 reviewFlowCheck(count($models)>=2,'fixture has two tenant-enabled vision models');
 $alternative=Text::resolveModel(1,['id'=>$models[1]['id']],true);
 $selected=['id'=>(int)$alternative['id'],'sku_id'=>(int)$alternative['market_sku_id']];
 Db::startTrans();
 try {
  $override=$p;$override['idempotency_key']='fixture-review-model';$override['model_selection']=$selected;
  $switched=S::reviewDesign(1,1,$override);
  $switchedRow=Db::name('decorate_agent_run')->where('id',$switched['id'])->find();$switchedInput=S::decode($switchedRow['input_json']);
  reviewFlowCheck((int)$switchedInput['model_selection']['id']===$selected['id']&&(int)$switchedInput['model_selection']['sku_id']===$selected['sku_id'],'explicit review model and active input SKU are frozen');
  reviewFlowCheck($switchedInput['sources']===$ordinaryInput['sources']&&$switchedInput['image_model_selection']===$ordinaryInput['image_model_selection']&&$switchedInput['scope']===$ordinaryInput['scope']&&$switchedInput['budget_points']===25,'model override preserves image authorization, sources, scope and budget');
  reviewFlowCheck(S::decode($switchedRow['context_json'])['approved_design']['hash']===$design['hash']&&S::decode(Db::name('decorate_agent_run')->where('id',$implementation)->value('input_json'))===$ordinaryInput,'override preserves frozen design and original implementation input');
  $same=$override;$same['model_selection']=array_map('strval',$selected);
  reviewFlowCheck(S::reviewDesign(1,1,$same)['id']===$switched['id'],'numeric and string model identities replay the same run');
  $same['model_selection']=['id'=>$selected['id']];
  reviewFlowCheck(S::reviewDesign(1,1,$same)['id']===$switched['id'],'id-only replay keeps its frozen SKU');
  $different=$override;$different['model_selection']=['id'=>(int)$models[0]['id']];
  reviewFlowReject(fn()=>S::reviewDesign(1,1,$different),'same key cannot change the effective model');
  $different['model_selection']=['id'=>$selected['id'],'sku_id'=>$selected['sku_id']+999999];
  reviewFlowReject(fn()=>S::reviewDesign(1,1,$different),'same key cannot change the effective SKU');
  Db::startTrans();
  try {
   Db::name('power_market_sku')->where('id',$selected['sku_id'])->update(['status'=>0]);
   $fresh=$override;$fresh['idempotency_key']='fixture-review-disabled-sku';
   reviewFlowReject(fn()=>S::reviewDesign(1,1,$fresh),'new key cannot ignore a disabled requested input SKU');
   reviewFlowCheck(S::reviewDesign(1,1,$override)['id']===$switched['id'],'exact replay retains frozen identity after SKU disable');
  } finally {Db::rollback();}
  Db::name('power_market_product')->where('id',$selected['id'])->update(['status'=>0]);
  reviewFlowCheck(S::reviewDesign(1,1,$override)['id']===$switched['id'],'exact replay does not re-resolve a delisted model');
  $omitted=$override;unset($omitted['model_selection']);
  reviewFlowCheck(S::reviewDesign(1,1,$omitted)['id']===$switched['id'],'legacy omitted selection replays frozen run after model delisting');
  $fresh=$override;$fresh['idempotency_key']='fixture-review-delisted';
  reviewFlowReject(fn()=>S::reviewDesign(1,1,$fresh),'new key cannot queue a delisted model');
  $unknown=$override;$unknown['sources']=['generate'];
  reviewFlowReject(fn()=>S::reviewDesign(1,1,$unknown),'idempotent replay also enforces API whitelist');
 } finally {Db::rollback();}
 foreach([null,[],['id'=>true],['id'=>1.5],['id'=>-1],['id'=>0],['id'=>'1e2'],['id'=>$selected['id'],'sku_id'=>0],['id'=>$selected['id'],'product_id'=>$selected['id']]] as $badSelection){
  $bad=$p;$bad['model_selection']=$badSelection;
  reviewFlowReject(fn()=>S::reviewDesign(1,1,$bad),'strict review selection rejects malformed or unknown fields');
 }
 $bad=$p;$bad['model_selection']=['id'=>$selected['id'],'sku_id'=>999999999];
 reviewFlowReject(fn()=>S::reviewDesign(1,1,$bad),'invalid SKU cannot fall through resolveModel id matching');
 $bad['model_selection']=['id'=>(int)$models[0]['id'],'sku_id'=>$selected['sku_id']];
 reviewFlowReject(fn()=>S::reviewDesign(1,1,$bad),'another model SKU cannot select a conflicting model');
 $nonVision=array_values(array_filter(Text::textOptions(1),fn($m)=>empty($m['supports_vision'])));
 if($nonVision){$bad['model_selection']=['id'=>(int)$nonVision[0]['id']];reviewFlowReject(fn()=>S::reviewDesign(1,1,$bad),'nonvision selection is rejected before queueing');}
 foreach(['sources'=>['generate'],'image_model_selection'=>['id'=>1],'endpoint'=>'https://invalid.example'] as $field=>$value){$bad=$p;$bad[$field]=$value;reviewFlowReject(fn()=>S::reviewDesign(1,1,$bad),'review API rejects unauthorized top-level override');}
 $bad=$p;$bad['model_selection']=$selected;$bad['budget_points']=0;
 reviewFlowReject(fn()=>S::reviewDesign(1,1,$bad),'explicit model retains budget preflight');

 $estimateReferences=new ReflectionMethod(S::class,'visionReferenceCount');$estimateReferences->setAccessible(true);
 reviewFlowCheck($estimateReferences->invoke(null,['attachments'=>[]],['approved_design'=>['run_id'=>$designRun]])===1&&$estimateReferences->invoke(null,['attachments'=>[['uses'=>['layout']]]],['approved_design'=>['run_id'=>$designRun]])===2,'budget counts actual implementation one-image and review two-image references');
 $review=S::reviewDesign(1,1,$p);$again=S::reviewDesign(1,1,$p);
 reviewFlowCheck($review['id']===$again['id']&&$review['candidate_id']===$candidateId,'review queues once for the actual existing candidate');
 Db::startTrans();
 try {
  $legacyInput=S::decode(Db::name('decorate_agent_run')->where('id',$review['id'])->value('input_json'));$legacyInput['model_selection']['id']=(string)$legacyInput['model_selection']['id'];
  Db::name('decorate_agent_run')->where('id',$review['id'])->update(['input_json'=>S::json($legacyInput)]);
  Db::name('power_market_product')->where('id',(int)$legacyInput['model_selection']['id'])->update(['status'=>0]);
  reviewFlowCheck(S::reviewDesign(1,1,$p)['id']===$review['id'],'old omitted request replays original string-ID model without availability lookup');
  $explicit=$p;$explicit['model_selection']=$legacyInput['model_selection'];
  reviewFlowCheck(S::reviewDesign(1,1,$explicit)['id']===$review['id'],'explicit same identity matches legacy string-ID storage');
 } finally {Db::rollback();}
 $run=Db::name('decorate_agent_run')->where('id',$review['id'])->find();$ctx=S::decode($run['context_json']);$input=S::decode($run['input_json']);
 reviewFlowCheck($ctx['candidate']===$payload&&$ctx['base']===$draft&&$ctx['visual_review']['screenshot_file_id']===$fileId,'review freezes candidate, original base and real screenshot tuple');
 $contextMethod=new ReflectionMethod(S::class,'modelContext');$contextMethod->setAccessible(true);$contextCopy=$ctx;$modelContext=$contextMethod->invokeArgs(null,[1,1,$session,$input,&$contextCopy,false]);
 reviewFlowCheck(array_key_first($modelContext)==='visual_review_target'&&$modelContext['visual_review_target']['image_dimensions']['actual_capture']['width_px']===3&&str_contains($input['message'],'批准图片优先'),'real queued review puts image target/dimensions first and does not replay blueprint as visual specification');
 reviewFlowCheck(\app\common\service\decorate\agent\DecorationAgentReviewContext::expandContracts($modelContext['related_contracts'])===[\app\common\service\decorate\agent\DecorationAgentDesignContext::contract('rich-text')]&&$modelContext['page']===$payload,'actual modelContext uses exact current-tree contract projection and preserves original candidate');
 reviewFlowCheck($contextCopy['approved_design']===$ctx['approved_design']&&$contextCopy['visual_review']===$ctx['visual_review']&&isset($contextCopy['trusted_materials'],$contextCopy['approved_material_urls']),'projection retains authoritative raw approval/evidence and trusted source context');
 Db::startTrans();
 try {
  $tiny=$input;$tiny['budget_points']=0.001;Db::name('decorate_agent_run')->where('id',$review['id'])->update(['input_json'=>S::json($tiny)]);
  $step=new ReflectionMethod(S::class,'step');$step->setAccessible(true);$step->invoke(null,$review['id'],'fixture-no-provider');
  $stopped=Db::name('decorate_agent_run')->where('id',$review['id'])->find();$stoppedCtx=S::decode($stopped['context_json']);$preflight=$stoppedCtx['budget_preflight'];
  $copy=$stoppedCtx;$tiny['remaining_budget_points']=0.001;$wireContext=$contextMethod->invokeArgs(null,[1,1,$session,$tiny,&$copy,false]);
  $estimateText=S::json($wireContext)."\n用户需求：".$tiny['message'].\app\common\service\decorate\agent\DecorationAgentDecision::prompt().str_repeat('v',12000);
  $textEstimate=new ReflectionMethod(S::class,'textEstimate');$textEstimate->setAccessible(true);$resolved=Text::resolveModel(1,$tiny['model_selection'],true);
  reviewFlowCheck($stopped['status']==='budget_exceeded'&&(int)$stopped['model_calls']===0&&Db::name('ai_app_task')->where(['business_table'=>'decorate_agent_run','business_id'=>$review['id']])->count()===0,'real worker rejects insufficient projected budget before provider/task creation');
  reviewFlowCheck($preflight['projection']==='visual_review_v1'&&$preflight['input_tokens']===Text::estimateTokens($estimateText)&&$preflight['estimated_points']===$textEstimate->invoke(null,$resolved,$estimateText)&&$preflight['vision_reference_count']===2,'durable preflight uses exact projected content plus unchanged system prompt and two-image worker formula');
 } finally {Db::rollback();}

 reviewFlowCheck(!str_contains($run['context_json'],'data:image/')&&$ctx['approved_design']['hash']===$design['hash'],'only immutable identities persist, not vision bytes');
 $makeTextReceipt=static function(int $runId): int {$task=(int)Db::name('ai_app_task')->insertGetId(['task_no'=>'review-flow-text:'.bin2hex(random_bytes(8)),'idempotency_key'=>'review-flow-text:'.bin2hex(random_bytes(8)),'tenant_id'=>1,'user_id'=>0,'app_code'=>'system_default','business_table'=>'decorate_agent_run','business_id'=>$runId,'status'=>'success']);return (int)Db::name('ai_consumption_log')->insertGetId(['consume_no'=>'review-flow-text:'.bin2hex(random_bytes(8)),'tenant_id'=>1,'user_id'=>0,'app_task_id'=>$task,'app_code'=>'system_default','protocol'=>'openai_responses','run_status'=>'success','billing_status'=>'settled','actual_tenant_cost'=>1]);};
 $ctx['receipts']=[['consumption_id'=>$makeTextReceipt($review['id'])]];$ctx['last_result']=['content'=>'{"kind":"final","operations":[]}','content_source'=>'content','billing'=>['billing_status'=>'settled']];Db::name('decorate_agent_run')->where('id',$review['id'])->update(['context_json'=>S::json($ctx),'status'=>'running']);
 $checksList=array_map(fn($category)=>['category'=>$category,'passed'=>true,'detail'=>'明确核对对应区域','evidence'=>['fixture依据']],['layout','typography','color','assets','icons','interaction']);
 $decision=['kind'=>'final','reply'=>'本版视觉对照完成，交互仍需实际点击验证。','question'=>'','warnings'=>[],'acceptance'=>['passed'=>true,'checks'=>$checksList,'summary'=>'fixture compare']];
 $persist=new ReflectionMethod(S::class,'persistDecision');$persist->setAccessible(true);$persist->invokeArgs(null,[1,1,$review['id'],$session,$input,&$ctx,$decision,[]]);
 $final=S::run(1,1,$review['id']);$current=S::candidate(1,1,$candidateId);
 reviewFlowCheck($final['status']==='completed'&&$final['acceptance']['passed']===true&&$current['revision']===1,'no-edit visual pass binds exact original candidate without revision drift');
 reviewFlowCheck($current['acceptance']['review_run_id']===$review['id']&&S::session(1,1,$session['id'])['candidates'][0]['acceptance']['passed']===true,'candidate and session recover the same safe report');
 reviewFlowCheck(str_contains($final['assistant_reply'],'模型视觉对照')&&$final['assistant_reply']!==$decision['reply'],'final SSE reply comes from authoritative acceptance report');
 $blocked=['data'=>[C::create('banner','evidence-blocked')],'meta'=>[]];$blocked['data'][0]['content']['data'][0]['image']='https://fixture.invalid/'.$screenshot['image_uri'].'?signature=fresh';
 reviewFlowReject(fn()=>D::assertNoReferenceImages($blocked,null,[],1),'screenshot aliases remain forbidden across later ordinary sessions');
 reviewFlowCheck(!array_filter(M::search(1,'',['tenant'],[],50),fn($m)=>($m['file_id']??0)===$fileId),'actual review evidence excluded from cross-session material search');
 reviewFlowReject(fn()=>M::validateAttachments(1,[['file_id'=>$fileId,'uses'=>['asset']]]),'evidence file cannot be reclassified as a material');
 $repairP=$p;$repairP['idempotency_key']='fixture-review-repair';$repair=S::reviewDesign(1,1,$repairP);$repairRow=Db::name('decorate_agent_run')->where('id',$repair['id'])->find();$repairCtx=S::decode($repairRow['context_json']);$repairInput=S::decode($repairRow['input_json']);
 $changed=O::apply($repairCtx['candidate'],[['type'=>'update_component','widget_id'=>'intro','fields'=>['content.text'=>'修复后需重新截图']]],['scope'=>'page']);$repairCtx['candidate']=$changed['candidate'];$repairCtx['changes']=$changed['changes'];$repairCtx['receipts']=[['consumption_id'=>$makeTextReceipt($repair['id'])]];$repairCtx['last_result']=['content'=>'{"kind":"final","operations":[{"type":"update_component"}]}','billing'=>['billing_status'=>'settled']];Db::name('decorate_agent_run')->where('id',$repair['id'])->update(['context_json'=>S::json($repairCtx),'status'=>'running']);
 $persist->invokeArgs(null,[1,1,$repair['id'],$session,$repairInput,&$repairCtx,$decision,[]]);$fixed=S::candidate(1,1,$candidateId);
 reviewFlowCheck($fixed['revision']===2&&$fixed['acceptance']['passed']===false,'repair invalidates acceptance despite model passed=true');
 reviewFlowCheck(str_contains(S::run(1,1,$repair['id'])['assistant_reply'],'尚未通过'),'repair publishes honest incomplete verification outcome');
 $repairSlot=(int)Db::name('decorate_agent_image_slot')->insertGetId(['tenant_id'=>1,'admin_id'=>1,'session_id'=>$session['id'],'run_id'=>$repair['id'],'candidate_id'=>$candidateId,'slot_key'=>'intro:styles.background_image','status'=>'queued','request_json'=>'{}','result_json'=>'{}']);
 $slots=S::candidate(1,1,$candidateId)['image_slots'];reviewFlowCheck(count($slots)===1&&(int)$slots[0]['id']===$repairSlot&&(int)$slots[0]['run_id']===$repair['id']&&$slots[0]['remaining_budget_points']===24.0,'candidate returns repair image slots with their actual run budget');
 reviewFlowReject(fn()=>S::apply(1,1,['candidate_id'=>$candidateId,'revision'=>2,'idempotency_key'=>'review-flow-unsafe-apply']),'apply rejects repaired version without fresh screenshot review');
 reviewFlowCheck(S::hash(S::payload(T::pageDetail(1,['template_id'=>$template['id'],'id'=>$page['id']])))===S::hash($draft),'reviews and repairs never modify the draft');
}finally{Db::rollback();foreach($paths as $path)if(is_file($path))unlink($path);}
echo "design review flow: $checks checks passed (DB rolled back, no provider calls)\n";
