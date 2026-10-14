<?php
declare(strict_types=1);
namespace app\common\service\power {
 /** Only the paid runtime boundary is replaced; real worker, adapter and database paths execute. */
 final class MarketImageModelRuntimeService {
  public static int $reserved=0,$submitted=0;public static array $asset=[];public static bool $complete=false;
  public static function quote(int $tenant,array $selection,int $quantity,array $override,bool $admin): array {return ['tenant_cost_points'=>1,'settlement_mode'=>'reserved'];}
  public static function reserve(int $tenant,int $user,string $action,string $business,array $selection,array $request,int $quantity,string $app,string $table,array $override,bool $admin): array {
   self::$reserved++;$db=\think\facade\Db::class;$key='design-submit:'.bin2hex(random_bytes(8));
   $task=(int)$db::name('ai_app_task')->insertGetId(['tenant_id'=>$tenant,'user_id'=>$user,'app_code'=>$app,'business_table'=>$table,'business_id'=>(int)$business,'status'=>'running','task_no'=>$key,'idempotency_key'=>$key]);
   $id=(int)$db::name('ai_consumption_log')->insertGetId(['consume_no'=>$key,'tenant_id'=>$tenant,'user_id'=>$user,'app_code'=>$app,'app_task_id'=>$task,'protocol'=>'image_generate','run_status'=>'reserved','billing_status'=>'reserved','reserved_tenant_cost'=>1,'price_snapshot'=>'{}']);return ['consumption_id'=>$id];
  }
  public static function submit(int $id,array $request,bool $admin): void {self::$submitted++;\think\facade\Db::name('ai_consumption_log')->where('id',$id)->update(['run_status'=>'running','upstream_task_id'=>'fixture-native-image']);}
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
 use app\common\service\decorate\agent\DecorationAgentService as S;
 use app\common\service\decorate\DecorateTemplateService as T;
 use app\common\service\power\MarketImageModelRuntimeService as Runtime;
 $checks=0;function submitCheck(bool $ok,string $label): void {global $checks;if(!$ok)throw new \RuntimeException($label);$checks++;}
 $uri='uploads/design_submit_'.bin2hex(random_bytes(8)).'.png';$path=public_path().$uri;copy(public_path().'resource/image/tenantapi/default/nav01.png',$path);
 Db::startTrans();try {
  request()->tenantId=1;$template=T::add(1,['name'=>'design submission isolated','terminal'=>'mobile']);$page=T::pageLists(1,$template['id'],'mobile')[0];$session=S::createSession(1,1,['template_id'=>$template['id'],'page_id'=>$page['id']]);
  $input=['design_first'=>true,'scope'=>'page','sources'=>['generate'],'attachments'=>[],'image_model_selection'=>[],'budget_points'=>25];
  $runId=(int)Db::name('decorate_agent_run')->insertGetId(['tenant_id'=>1,'admin_id'=>1,'session_id'=>$session['id'],'candidate_id'=>0,'status'=>'waiting_images','input_json'=>S::json($input),'context_json'=>'{}','events_json'=>'[]','result_json'=>'{}','idempotency_key'=>'design-submit:'.bin2hex(random_bytes(8))]);
  $slotId=(int)Db::name('decorate_agent_image_slot')->insertGetId(['tenant_id'=>1,'admin_id'=>1,'session_id'=>$session['id'],'run_id'=>$runId,'candidate_id'=>0,'slot_key'=>'design:page','status'=>'queued','request_json'=>S::json(['purpose'=>'design','prompt'=>'完整UI设计稿','blueprint'=>['regions'=>[]],'ratio'=>'9:16']),'result_json'=>'{}']);
  $ctx=['phase'=>'design','design'=>['status'=>'generating','hash'=>'','revision'=>1,'slot_id'=>$slotId,'prompt'=>'完整UI设计稿','blueprint'=>['regions'=>[]]]];
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
 }finally{Db::rollback();unlink($path);}
 echo "design submit: $checks checks passed (real DB rollback, runtime provider boundary mocked)\n";
}
