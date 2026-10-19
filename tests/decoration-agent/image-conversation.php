<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);require $root.'/runtime-loader.php';(new AigcRuntimeApp($root))->initialize();
set_exception_handler(static function(Throwable $error): void {fwrite(STDERR,$error->getMessage().PHP_EOL);exit(1);});
use think\facade\Db;
use app\common\service\decorate\agent\{DecorationAgentService as S,DecorationAgentCatalog as C,DecorationAgentOperations as O,DecorationAgentDesignContext as Design,DecorationAgentDecision as Decision};
use app\common\service\decorate\DecorateTemplateService as T;
$checks=0;
function imageConversationCheck(bool $ok,string $label): void {global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
Db::startTrans();try{
 request()->tenantId=1;$template=T::add(1,['name'=>'image conversation isolated','terminal'=>'mobile']);$page=T::pageLists(1,$template['id'],'mobile')[0];$base=['data'=>[C::create('banner','hero')],'meta'=>[],'name'=>'image conversation','new_page'=>false];$page=T::savePage(1,['template_id'=>$template['id'],'id'=>$page['id'],'terminal'=>'mobile','name'=>$base['name'],'data'=>S::json($base['data']),'meta'=>'[]']);$session=S::createSession(1,1,['template_id'=>$template['id'],'page_id'=>$page['id']]);
 $input=['scope'=>'page','selected_id'=>'','sources'=>['generate'],'attachments'=>[],'image_model_selection'=>[],'budget_points'=>25];$ctx=['base'=>$base,'candidate'=>$base,'changes'=>[],'facts'=>[],'observations'=>[],'receipts'=>[]];
 $newRun=static fn()=>(int)Db::name('decorate_agent_run')->insertGetId(['tenant_id'=>1,'admin_id'=>1,'session_id'=>$session['id'],'status'=>'running','input_json'=>S::json($input),'context_json'=>S::json($ctx),'result_json'=>'{}','events_json'=>'[]','idempotency_key'=>'image-conversation:'.bin2hex(random_bytes(8))]);$run=$newRun();
 $tools=['kind'=>'tools','reply'=>'准备主视觉','question'=>'','warnings'=>[]];$final=['kind'=>'final','reply'=>'配图已安排','question'=>'','warnings'=>[]];
 $request=O::apply($base,[['type'=>'request_image','widget_id'=>'hero','field'=>'content.data.0.image','prompt'=>'violet glass studio']],['scope'=>'page'])['image_requests'];
 $persist=new ReflectionMethod(S::class,'persistDecision');$persist->setAccessible(true);
 // One tools turn contains two requests for the same pending position; one final turn follows.
 $persist->invokeArgs(null,[1,1,$run,$session,$input,&$ctx,$tools,[$request[0],$request[0]]]);
 $slot=Db::name('decorate_agent_image_slot')->where('run_id',$run)->find();$candidate=(int)Db::name('decorate_agent_run')->where('id',$run)->value('candidate_id');
 imageConversationCheck($candidate>0&&$slot['status']==='queued'&&$ctx['changes']===[],'image-only tools creates one candidate without widget edits');
 $ack=$ctx['observations'][0];imageConversationCheck(($ack['tool']??'')==='request_image'&&($ack['result']['slot_id']??0)===$slot['id']&&($ack['result']['status']??'')==='queued','new queued image returns durable acknowledgement');
 imageConversationCheck(!str_contains(S::json($ack),'url'),'acknowledgement contains no signed or provider URL');
 $again=end($ctx['observations']);imageConversationCheck(Db::name('decorate_agent_image_slot')->where('run_id',$run)->count()===1&&($again['result']['slot_id']??0)===$slot['id']&&($again['result']['reused']??false),'repeated request acknowledges existing pending slot without another image');
 Db::name('decorate_agent_image_slot')->where('id',$slot['id'])->update(['status'=>'submitting']);
 $persist->invokeArgs(null,[1,1,$run,$session,$input,&$ctx,$final,[]]);imageConversationCheck(Db::name('decorate_agent_run')->where('id',$run)->value('status')==='waiting_images'&&Db::name('decorate_agent_candidate')->where('id',$candidate)->value('status')==='waiting_images','empty final after image-only tools continues waiting for its image');
 imageConversationCheck(S::decode(Db::name('decorate_agent_run')->where('id',$run)->value('context_json'))['observations']===$ctx['observations'],'image acknowledgements survive durable model-context recovery');
 imageConversationCheck(str_contains(Design::guide()['image_tasks']??'','不要重复请求')&&str_contains(Decision::prompt(),'不需要重复请求'),'model guidance explains queued tasks need no repeated request');
 $asset=['image_uri'=>'uploads/aigc_image/fixture/conversation.png','storage_scope'=>'tenant','storage_engine'=>'local','storage_domain'=>'','url'=>'/uploads/aigc_image/fixture/conversation.png'];$task=(int)Db::name('ai_app_task')->insertGetId(['tenant_id'=>1,'user_id'=>0,'app_code'=>'system_default','business_table'=>'decorate_agent_image_slot','business_id'=>$slot['id'],'status'=>'success']);$receipt=(int)Db::name('ai_consumption_log')->insertGetId(['tenant_id'=>1,'user_id'=>0,'app_task_id'=>$task,'app_code'=>'system_default','protocol'=>'image_generate','run_status'=>'success','billing_status'=>'settled','response_summary'=>S::json(['images'=>[$asset]])]);Db::name('decorate_agent_image_slot')->where('id',$slot['id'])->update(['consumption_id'=>$receipt,'status'=>'running']);
 $process=new ReflectionMethod(S::class,'processImages');$process->setAccessible(true);$process->invoke(null,Db::name('decorate_agent_run')->where('id',$run)->find(),$input);
 $completed=S::candidate(1,1,$candidate);imageConversationCheck(Db::name('decorate_agent_run')->where('id',$run)->value('status')==='completed'&&$completed['data'][0]['content']['data'][0]['image']===$asset['url']&&isset($completed['data'][0]['_decoration_asset_refs']),'settled fixture completes the waiting run and durable image hydration');
 $advice=$newRun();$adviceCtx=['base'=>$base,'candidate'=>$base,'changes'=>[],'facts'=>[],'observations'=>[],'receipts'=>[]];$before=Db::name('decorate_agent_candidate')->count();$persist->invokeArgs(null,[1,1,$advice,$session,$input,&$adviceCtx,$final,[]]);imageConversationCheck(Db::name('decorate_agent_run')->where('id',$advice)->value('status')==='completed'&&(int)Db::name('decorate_agent_run')->where('id',$advice)->value('candidate_id')===0&&Db::name('decorate_agent_candidate')->count()===$before,'pure advice still completes without creating a candidate');
 imageConversationCheck((int)Db::name('decorate_agent_session')->where('id',$session['id'])->value('active_candidate_id')===$candidate,'advice preserves the previous active candidate');
}finally{Db::rollback();}
echo "image conversation: $checks checks passed (DB rolled back, no provider calls)\n";
