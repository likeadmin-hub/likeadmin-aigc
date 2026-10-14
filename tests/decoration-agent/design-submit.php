<?php
declare(strict_types=1);
namespace app\common\service\power {
 /** Only the paid runtime boundary is replaced; real worker, adapter and database paths execute. */
 final class MarketImageModelRuntimeService {
  public static int $reserved=0,$submitted=0;public static array $asset=[];public static bool $complete=false;public static array $requests=[];
  public static function options(int $tenant): array {return [['market_product_id'=>999,'name'=>'fixture image','ratio_options'=>['9:16'],'supports_reference_images'=>true,'max_reference_images'=>5,'skus'=>[['market_sku_id'=>999,'tenant_unit_price'=>1]]]];}
  public static function quote(int $tenant,array $selection,int $quantity,array $override,bool $admin): array {return ['tenant_cost_points'=>1,'settlement_mode'=>'reserved'];}
  public static function reserve(int $tenant,int $user,string $action,string $business,array $selection,array $request,int $quantity,string $app,string $table,array $override,bool $admin): array {
   self::$reserved++;$db=\think\facade\Db::class;$key='design-submit:'.bin2hex(random_bytes(8));
   $task=(int)$db::name('ai_app_task')->insertGetId(['tenant_id'=>$tenant,'user_id'=>$user,'app_code'=>$app,'business_table'=>$table,'business_id'=>(int)$business,'status'=>'running','task_no'=>$key,'idempotency_key'=>$key]);
   $id=(int)$db::name('ai_consumption_log')->insertGetId(['consume_no'=>$key,'tenant_id'=>$tenant,'user_id'=>$user,'app_code'=>$app,'app_task_id'=>$task,'protocol'=>'image_generate','run_status'=>'reserved','billing_status'=>'reserved','reserved_tenant_cost'=>1,'price_snapshot'=>'{}']);return ['consumption_id'=>$id];
  }
  public static function submit(int $id,array $request,bool $admin): void {self::$submitted++;self::$requests[]=$request;\think\facade\Db::name('ai_consumption_log')->where('id',$id)->update(['run_status'=>'running','upstream_task_id'=>'fixture-native-image']);}
  public static function refresh(int $id): void {
   if(!self::$complete)return;$row=\think\facade\Db::name('ai_consumption_log')->where('id',$id)->find();
   \think\facade\Db::name('ai_app_task')->where('id',$row['app_task_id'])->update(['status'=>'success']);
   \think\facade\Db::name('ai_consumption_log')->where('id',$id)->update(['run_status'=>'success','billing_status'=>'settled','actual_tenant_cost'=>1,'response_summary'=>json_encode(['images'=>[self::$asset]],JSON_UNESCAPED_SLASHES)]);
  }
 }
}
namespace {
 $root=dirname(__DIR__,2);require $root.'/runtime-loader.php';(new \AigcRuntimeApp($root))->initialize();
 set_exception_handler(static function(\Throwable $error): void {fwrite(STDERR,$error->getMessage().PHP_EOL.$error->getTraceAsString());exit(1);});
 use think\facade\Db;
 use app\common\service\decorate\agent\{DecorationAgentService as S,DecorationAgentDesignService as D,DecorationAgentCatalog as C,DecorationAgentMediaService as M};
 use app\common\service\decorate\DecorateTemplateService as T;
 use app\common\service\power\MarketImageModelRuntimeService as Runtime;
 $checks=0;function submitCheck(bool $ok,string $label): void {global $checks;if(!$ok)throw new \RuntimeException($label);$checks++;}
 $uri='uploads/design_submit_'.bin2hex(random_bytes(8)).'.png';$path=public_path().$uri;copy(public_path().'resource/image/tenantapi/default/nav01.png',$path);
 Db::startTrans();try {
  request()->tenantId=1;$template=T::add(1,['name'=>'design submission isolated','terminal'=>'mobile']);$page=T::pageLists(1,$template['id'],'mobile')[0];$session=S::createSession(1,1,['template_id'=>$template['id'],'page_id'=>$page['id']]);
  $input=['design_first'=>true,'scope'=>'page','sources'=>['generate','content'],'attachments'=>[],'image_model_selection'=>[],'budget_points'=>25];
  $runId=(int)Db::name('decorate_agent_run')->insertGetId(['tenant_id'=>1,'admin_id'=>1,'session_id'=>$session['id'],'candidate_id'=>0,'status'=>'waiting_images','input_json'=>S::json($input),'context_json'=>'{}','events_json'=>'[]','result_json'=>'{}','idempotency_key'=>'design-submit:'.bin2hex(random_bytes(8))]);
  $builtin=M::builtinMaterials()[0];$selected=[['id'=>$builtin['id'],'kind'=>'builtin','source'=>'content','uri'=>$builtin['uri']]];
  $slotId=(int)Db::name('decorate_agent_image_slot')->insertGetId(['tenant_id'=>1,'admin_id'=>1,'session_id'=>$session['id'],'run_id'=>$runId,'candidate_id'=>0,'slot_key'=>'design:page','status'=>'queued','request_json'=>S::json(['purpose'=>'design','prompt'=>'完整UI设计稿','blueprint'=>['regions'=>[]],'ratio'=>'9:16','design_materials'=>$selected]),'result_json'=>'{}']);
  $ctx=['phase'=>'design','design'=>['status'=>'generating','hash'=>'','revision'=>1,'slot_id'=>$slotId,'prompt'=>'完整UI设计稿','blueprint'=>['regions'=>[],'selected_materials'=>$selected]]];
  Db::name('decorate_agent_run')->where('id',$runId)->update(['context_json'=>S::json($ctx)]);Db::name('decorate_agent_session')->where('id',$session['id'])->update(['preferences'=>S::json(['_design_state'=>['run_id'=>$runId,'revision'=>1]])]);
  $process=new \ReflectionMethod(S::class,'processImages');$process->setAccessible(true);
  $negative=static function(array $runChanges=[],array $slotChanges=[],?array $state=null)use($process,$input,$runId,$slotId,$session): void {
   Db::startTrans();try{if($runChanges)Db::name('decorate_agent_run')->where('id',$runId)->update($runChanges);if($slotChanges)Db::name('decorate_agent_image_slot')->where('id',$slotId)->update($slotChanges);if($state!==null)Db::name('decorate_agent_session')->where('id',$session['id'])->update(['preferences'=>S::json(['_design_state'=>$state])]);$before=Runtime::$reserved;$process->invoke(null,Db::name('decorate_agent_run')->where('id',$runId)->find(),$input);submitCheck(Runtime::$reserved===$before,'invalid/canceled/ordinary owner guard stops before reserve');}finally{Db::rollback();}
  };
  $negative([],[],['run_id'=>$runId+1,'revision'=>2]);
  $negative([],[],['run_id'=>$runId,'revision'=>2]);
  $negative([],['slot_key'=>'banner:content.data.0.image']);
  $negative(['cancel_requested'=>1]);
  $negative(['input_json'=>S::json(array_replace($input,['design_first'=>false]))]);
  $negative([],['candidate_id'=>999]);
  $negative([],['session_id'=>$session['id']+1]);
  $negative([],['request_json'=>S::json(['prompt'=>'ordinary missing candidate'])]);
  $process->invoke(null,Db::name('decorate_agent_run')->where('id',$runId)->find(),$input);
  $slot=Db::name('decorate_agent_image_slot')->where('id',$slotId)->find();
  submitCheck(Runtime::$reserved===1&&Runtime::$submitted===1&&(int)$slot['consumption_id']>0&&$slot['status']==='running','actual candidate-zero design crosses real worker reserve and native submit guard exactly once');
  submitCheck(Db::name('decorate_agent_candidate')->where('session_id',$session['id'])->count()===0,'design submission never creates a DIY candidate');
  $process->invoke(null,Db::name('decorate_agent_run')->where('id',$runId)->find(),$input);submitCheck(Runtime::$reserved===1&&Runtime::$submitted===1,'running design polls without another reservation or submit');
  Runtime::$asset=['image_uri'=>$uri,'storage_scope'=>'tenant','storage_engine'=>'local','storage_domain'=>''];Runtime::$complete=true;
  $process->invoke(null,Db::name('decorate_agent_run')->where('id',$runId)->find(),$input);$ready=S::run(1,1,$runId);
  submitCheck($ready['status']==='awaiting_design_confirmation'&&$ready['candidate_id']===0&&strlen($ready['design_draft']['hash'])===64,'real adapter poll and completion store design approval result without hydration');
  submitCheck(Runtime::$reserved===1&&Runtime::$submitted===1,'poll completion never repeats reserve or submit');
  $model=array_values(array_filter(\app\common\service\power\MarketTextModelRuntimeService::textOptions(1),fn($m)=>!empty($m['supports_vision'])))[0];
  $p=['session_id'=>$session['id'],'message'=>'只调整底部灵感卡，保持上一版主视觉和入口','catalog_hash'=>C::hash(),'design_first'=>true,'design_parent_run_id'=>$runId,'scope'=>'page','sources'=>['generate','content'],'model_selection'=>['id'=>$model['id']],'image_model_selection'=>['market_product_id'=>999,'market_sku_id'=>999],'budget_points'=>25,'idempotency_key'=>'design-revision:'.bin2hex(random_bytes(8))];
  $quote=S::quote(1,1,$p);submitCheck(($quote['vision_reference_count']??0)===1,'revision quote includes the actual parent design image count');
  $child=S::start(1,1,$p);$childRow=Db::name('decorate_agent_run')->where('id',$child['id'])->find();$childCtx=S::decode($childRow['context_json']);$childInput=S::decode($childRow['input_json']);
  submitCheck($childCtx['design']['parent_reference']['hash']===$ready['design_draft']['hash']&&!str_contains(S::json($childCtx),'data:image/'),'child freezes actual latest parent identity without signed URL or image bytes');
  $references=new \ReflectionMethod(S::class,'modelReferences');$references->setAccessible(true);$actualRefs=$references->invoke(null,1,1,$child['id'],$childInput,$childCtx);
  submitCheck(count($actualRefs)===1&&hash('sha256',base64_decode(explode(',',$actualRefs[0],2)[1]))===hash_file('sha256',$path),'actual text-model reference is the successful parent image bytes');
  $request=D::request(['prompt'=>'仅修底部灵感卡','blueprint'=>['regions'=>[['components'=>['banner']]]]],$childInput,['selected_model'=>Runtime::options(1)[0]],D::approvedMaterials(1,$childCtx['design']['parent_reference'],$childInput['sources']),1,[],$childCtx['design']['parent_reference']);
  D::persist(1,1,$child['id'],$childCtx,['kind'=>'tools','reply'=>'按上一版调整','question'=>'','warnings'=>[]],$request);
  Runtime::$complete=false;$process->invoke(null,Db::name('decorate_agent_run')->where('id',$child['id'])->find(),$childInput);$wire=end(Runtime::$requests);
  submitCheck(count($wire['reference_images'])===2&&hash('sha256',base64_decode(explode(',',$wire['reference_images'][0],2)[1]))===hash_file('sha256',$path),'real revised image submit survives Media filtering with authoritative parent bytes');
  submitCheck(hash('sha256',base64_decode(explode(',',$wire['reference_images'][1],2)[1]))===hash_file('sha256',public_path().$builtin['uri']),'previously selected true material persists alongside the parent UI image');
  submitCheck(str_contains($wire['prompt'],'上一版')&&Db::name('decorate_agent_candidate')->where('session_id',$session['id'])->count()===0,'revised design prompt preserves reference role without creating a DIY candidate');


  $deny=static function(callable $call,string $label): void {try{$call();}catch(\Exception $error){submitCheck(true,$label);return;}throw new \RuntimeException('accepted: '.$label);};
  $limited=Runtime::options(1)[0];$limited['max_reference_images']=1;
  $deny(fn()=>D::request(['prompt'=>'底部调整','blueprint'=>['regions'=>[['components'=>['banner']]]]],$childInput,['selected_model'=>$limited],D::approvedMaterials(1,$childCtx['design']['parent_reference'],$childInput['sources']),1,[],$childCtx['design']['parent_reference']),'parent plus selected material count respects selected image-model reference limit');
  $unsupported=Runtime::options(1)[0];$unsupported['supports_reference_images']=false;
  $deny(fn()=>D::request(['prompt'=>'底部调整','blueprint'=>['regions'=>[['components'=>['banner']]]]],$childInput,['selected_model'=>$unsupported],D::approvedMaterials(1,$childCtx['design']['parent_reference'],$childInput['sources']),1,[],$childCtx['design']['parent_reference']),'non-reference image model cannot silently discard parent');
  Db::startTrans();try{Db::name('decorate_agent_run')->where('id',$runId)->update(['cancel_requested'=>1]);$deny(fn()=>D::parentReferenceBytes(1,1,$child['id']),'canceled parent cannot be read by a newer revision');}finally{Db::rollback();}
  Db::startTrans();try{Db::name('decorate_agent_session')->where('id',$session['id'])->update(['preferences'=>S::json(['_design_state'=>['run_id'=>$child['id']+1,'revision'=>3]])]);$deny(fn()=>D::parentReferenceBytes(1,1,$child['id']),'superseded child cannot keep using an older parent image');}finally{Db::rollback();}
  $deny(fn()=>D::parentReferenceBytes(2,1,$child['id']),'foreign tenant cannot read parent reference');$deny(fn()=>D::parentReferenceBytes(1,2,$child['id']),'foreign admin cannot read parent reference');
  $bytes=file_get_contents($path);try{file_put_contents($path,file_get_contents(public_path().$builtin['uri']));$deny(fn()=>D::parentReferenceBytes(1,1,$child['id']),'actual parent byte mutation invalidates immutable image reference');}finally{file_put_contents($path,$bytes);}
  Db::startTrans();try{$failed=$childCtx;$failed['design']['blueprint']=['regions'=>[['components'=>['banner']]]];unset($failed['design']['asset']);Db::name('decorate_agent_run')->where('id',$child['id'])->update(['status'=>'failed','context_json'=>S::json($failed)]);$state=D::initialState(1,1,S::session(1,1,$session['id']),array_replace($childInput,['design_parent_run_id'=>$child['id']]));submitCheck($state['parent_reference']===null&&$state['prior_blueprint']===$failed['design']['blueprint'],'failed parent without an asset preserves planning only and supplies no fake image');}finally{Db::rollback();}
 }finally{Db::rollback();unlink($path);}
 echo "design submit: $checks checks passed (real DB rollback, runtime provider boundary mocked)\n";
}
