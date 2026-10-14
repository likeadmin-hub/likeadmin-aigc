<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);require $root.'/runtime-loader.php';(new AigcRuntimeApp($root))->initialize();
set_exception_handler(static function(Throwable $error): void {fwrite(STDERR,$error->getMessage().PHP_EOL.$error->getTraceAsString());exit(1);});
use think\facade\Db;
use app\common\service\decorate\agent\{DecorationAgentService as S,DecorationAgentDesignService as D,DecorationAgentCatalog as C,DecorationAgentMediaService as M,DecorationAgentAssetReferences as R};
use app\common\service\decorate\DecorateTemplateService as T;
use app\common\service\power\MarketTextModelRuntimeService as Text;
$checks=0;function designFirstCheck(bool $ok,string $label): void {global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
function designDenied(callable $operation,string $label): void {try{$operation();throw new LogicException('not rejected: '.$label);}catch(RuntimeException|InvalidArgumentException $error){designFirstCheck(true,$label);}}
$directory=public_path().'uploads/fixture-design-'.bin2hex(random_bytes(8));mkdir($directory,0755,true);$path=$directory.'/design.png';copy(public_path().'resource/image/tenantapi/default/nav01.png',$path);
Db::startTrans();try{
 request()->tenantId=1;$models=array_values(array_filter(Text::textOptions(1),fn($m)=>!empty($m['supports_vision'])));designFirstCheck(count($models)>0,'available visual model discovered without provider call');$images=M::options(1);$image=$images[0];
 $template=T::add(1,['name'=>'design approval isolated','terminal'=>'mobile']);$page=T::pageLists(1,$template['id'],'mobile')[0];$session=S::createSession(1,1,['template_id'=>$template['id'],'page_id'=>$page['id']]);
 $p=['session_id'=>$session['id'],'message'=>'设计整个移动创作工作室页面','catalog_hash'=>C::hash(),'design_first'=>true,'scope'=>'page','sources'=>['generate','content','tenant'],'model_selection'=>['id'=>$models[0]['id']],'image_model_selection'=>['market_product_id'=>$image['market_product_id'],'market_sku_id'=>$image['skus'][0]['market_sku_id']],'budget_points'=>100,'idempotency_key'=>'design-fixture:'.bin2hex(random_bytes(8))];
 $run=S::start(1,1,$p);designFirstCheck($run['candidate_id']===0&&$run['design_draft']['revision']===1&&$run['phase']==='design','opt-in creates design phase and no candidate');
 $row=Db::name('decorate_agent_run')->where('id',$run['id'])->find();$input=S::decode($row['input_json']);$ctx=S::decode($row['context_json']);
 foreach(['add_component','replace_page','configure_page','request_image'] as $type)designDenied(fn()=>D::assertOperation($input,$ctx,['type'=>$type]),'preapproval blocks '.$type);
 $request=D::request(['prompt'=>'可实现的完整页面UI布局','blueprint'=>['regions'=>[['role'=>'主视觉','components'=>['banner']]]]],$input,['selected_model'=>$image]);
 designDenied(fn()=>D::request(['prompt'=>'完整页面','blueprint'=>['regions'=>[]],'reference_material_ids'=>['not-approved']],$input,['selected_model'=>$image],[],1),'unapproved material reference ID rejected');
 if($image['supports_reference_images']??false){$builtin=M::builtinMaterials()[0];$request=D::request(['prompt'=>'依据真实素材完整页面','blueprint'=>['regions'=>[['role'=>'主视觉','components'=>['banner']]]],'reference_material_ids'=>[$builtin['id']]],$input,['selected_model'=>$image],[$builtin],1);designFirstCheck(!str_contains(S::json($request['design_materials']),'http'),'selected material references persist immutable identity without delivery URL');}
 D::persist(1,1,$run['id'],$ctx,['kind'=>'tools','reply'=>'准备设计稿','question'=>'','warnings'=>[]],$request);$slot=Db::name('decorate_agent_image_slot')->where('run_id',$run['id'])->find();designFirstCheck((int)$slot['candidate_id']===0&&S::decode($slot['request_json'])['purpose']==='design','design image slot has no DIY candidate');
 if(!empty($request['design_materials']))designFirstCheck(str_starts_with(D::materialReferences(1,$slot)[0],'data:image/'),'selected real material is delivered as actual image reference bytes');
 $asset=['image_uri'=>substr($path,strlen(public_path())),'storage_scope'=>'tenant','storage_engine'=>'local','storage_domain'=>'','url'=>'/'.substr($path,strlen(public_path()))];$task=(int)Db::name('ai_app_task')->insertGetId(['tenant_id'=>1,'user_id'=>0,'app_code'=>'system_default','business_table'=>'decorate_agent_image_slot','business_id'=>$slot['id'],'status'=>'success']);$receipt=(int)Db::name('ai_consumption_log')->insertGetId(['tenant_id'=>1,'user_id'=>0,'app_task_id'=>$task,'app_code'=>'system_default','protocol'=>'image_generate','run_status'=>'success','billing_status'=>'settled','response_summary'=>S::json(['images'=>[$asset]])]);Db::name('decorate_agent_image_slot')->where('id',$slot['id'])->update(['consumption_id'=>$receipt,'status'=>'running']);
 $process=new ReflectionMethod(S::class,'processImages');$process->setAccessible(true);$process->invoke(null,Db::name('decorate_agent_run')->where('id',$run['id'])->find(),$input);$ready=S::run(1,1,$run['id']);
 designFirstCheck($ready['status']==='awaiting_design_confirmation'&&$ready['candidate_id']===0&&$ready['design_draft']['width']===300,'stored design awaits approval without hydration');designFirstCheck(strlen($ready['design_draft']['hash'])===64,'immutable design version has stable hash');
 designFirstCheck(M::search(1,'',['tenant'],[],50)===[]||!array_filter(M::search(1,'',['tenant'],[],50),fn($m)=>($m['slot_id']??0)===$slot['id']),'whole-page design excluded from generated material search');
 designFirstCheck(R::designAsset((int)$slot['id'],2,1)===null&&R::designAsset((int)$slot['id'],1,2)===null,'design asset ownership is tenant and admin exact');
 $plain=$p;$plain['design_first']=false;$plain['idempotency_key'].=':plain';designDenied(fn()=>S::start(1,1,$plain),'ordinary start cannot bypass waiting approval');
 $confirm=['run_id'=>$run['id'],'revision'=>1,'design_hash'=>$ready['design_draft']['hash'],'idempotency_key'=>'approve-fixture','budget_points'=>100];
 Db::startTrans();try{S::cancel(1,1,$run['id']);designFirstCheck(S::run(1,1,$run['id'])['status']==='canceled','awaiting design cancellation is terminal');designDenied(fn()=>S::confirmDesign(1,1,$confirm),'canceled ready design cannot be approved');}finally{Db::rollback();}
 designDenied(fn()=>S::confirmDesign(2,1,$confirm),'cross-tenant approval rejected');$stale=$confirm;$stale['revision']=2;designDenied(fn()=>S::confirmDesign(1,1,$stale),'stale revision rejected');
 $implementation=S::confirmDesign(1,1,$confirm);$again=S::confirmDesign(1,1,$confirm);designFirstCheck($again['id']===$implementation['id']&&$implementation['phase']==='implementation','duplicate approval reuses one implementation run');
 $frozen=S::decode(Db::name('decorate_agent_run')->where('id',$implementation['id'])->value('context_json'))['approved_design'];$read=D::referenceBytes(1,1,$frozen);designFirstCheck($read['sha256']===$frozen['asset']['sha256'],'approved design vision reads authoritative frozen bytes');
 $blocked=['data'=>[C::create('banner','blocked')],'meta'=>[]];$blocked['data'][0]['content']['data'][0]['image']='https://storage.invalid/'.$asset['image_uri'].'?signature=changed';designDenied(fn()=>D::assertNoReferenceImages($blocked,$frozen),'design URI and different signed URL aliases cannot be DIY material');
 designDenied(fn()=>D::approvedForRun(1,1,$implementation['id'],(int)$slot['id']+1),'arbitrary design reference rejected');
 S::cancel(1,1,$implementation['id']);$adjust=$p;$adjust['design_parent_run_id']=$run['id'];$adjust['idempotency_key'].=':adjust';$next=S::start(1,1,$adjust);designFirstCheck($next['design_draft']['revision']===2,'explicit design adjustment increments revision');designDenied(fn()=>S::confirmDesign(1,1,$confirm),'older approved design becomes unconfirmable');
 S::cancel(1,1,$next['id']);designFirstCheck(S::run(1,1,$next['id'])['design_draft']['status']==='canceled','cancel invalidates design approval');
}finally{Db::rollback();unlink($path);rmdir($directory);}
echo "design first: $checks checks passed (DB rolled back, no provider calls)\n";
