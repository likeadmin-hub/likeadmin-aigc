<?php
declare(strict_types=1);
namespace app\common\service\power {
 /** Paid network boundary only. Everything else uses the real worker and rollback DB. */
 final class MarketTextModelRuntimeService {
  public static int $calls=0;public static array $context=[];
  public static function resolveModel(int $tenant,$selection,bool $vision=false):array{return ['id'=>999,'market_sku_id'=>999,'supports_vision'=>true,'input'=>['platform_price'=>100,'usage_unit_size'=>1000],'output'=>['platform_price'=>100,'usage_unit_size'=>1000]];}
  public static function estimateTokens(string $text):int{return (int)ceil(strlen($text)/3);}
  public static function generate(int $tenant,int $user,array $p,?callable $event=null,bool $admin=false):array{
   self::$calls++;self::$context=json_decode(explode("\n用户需求：",$p['content'])[0],true);$ops=[];
   for($i=0;$i<6;$i++){$ops[]=['type'=>'add_component','name'=>'banner','ref'=>'banner'.$i];$ops[]=['type'=>'request_image','widget_id'=>'@banner'.$i,'field'=>'content.data.0.image','prompt'=>'独立素材'.$i];}
   return ['content_source'=>'content','content'=>json_encode(['kind'=>'final','reply'=>'已安排六张素材','operations'=>$ops],JSON_UNESCAPED_UNICODE),'billing'=>['billing_status'=>'settled']];
  }
 }
}
namespace {
 $root=dirname(__DIR__,2);require $root.'/runtime-loader.php';(new \AigcRuntimeApp($root))->initialize();
 set_exception_handler(static function(\Throwable $e):void{fwrite(STDERR,$e->getMessage().PHP_EOL.$e->getTraceAsString());exit(1);});
 use think\facade\Db;
 use app\common\service\decorate\agent\{DecorationAgentService as S,DecorationAgentCatalog as C};
 use app\common\service\decorate\DecorateTemplateService as T;
 use app\common\service\power\MarketTextModelRuntimeService as Text;
 $checks=0;function workerCheck(bool $ok,string $label):void{global $checks;if(!$ok)throw new \RuntimeException($label);$checks++;}
 Db::startTrans();try{
  request()->tenantId=1;$template=T::add(1,['name'=>'unlimited isolated fixture','terminal'=>'mobile']);$page=T::pageLists(1,$template['id'],'mobile')[0];$session=S::createSession(1,1,['template_id'=>$template['id'],'page_id'=>$page['id']]);
  $before=S::hash(S::payload(T::pageDetail(1,['template_id'=>$template['id'],'id'=>$page['id']])));
  $p=['session_id'=>$session['id'],'message'=>'生成六个独立素材位置','catalog_hash'=>C::hash(),'scope'=>'page','sources'=>['content','generate'],'model_selection'=>['id'=>999],'image_model_selection'=>['market_product_id'=>999,'market_sku_id'=>999],'budget_points'=>0,'idempotency_key'=>'unlimited:'.bin2hex(random_bytes(6))];
  $run=S::start(1,1,$p);$row=Db::name('decorate_agent_run')->where('id',$run['id'])->find();$frozen=S::decode($row['input_json']);workerCheck(($frozen['execution_policy']??'')==='unlimited_v1','start freezes server policy with zero budget');
  Db::name('decorate_agent_run')->where('id',$run['id'])->update(['model_calls'=>6,'tool_calls'=>40]);
  $step=new \ReflectionMethod(S::class,'step');$step->setAccessible(true);$step->invoke(null,$run['id'],'fixture-no-network');$after=S::run(1,1,$run['id']);
  workerCheck(Text::$calls===1&&$after['model_calls']===7,'seventh model call actually reaches provider boundary');
  workerCheck($after['tool_calls']===52,'twelve tools execute after old forty threshold');
  workerCheck($after['status']==='waiting_images'&&count($after['image_slots'])===6,'sixth image is persisted beyond old five limit');
  workerCheck(Text::$context['execution_limits']['remaining_tools']===null&&Text::$context['execution_policy']['budget_points']===null&&Text::$context['generation']['image_limit']===null,'provider receives explicit unlimited context not zero allowance');
  workerCheck(S::hash(S::payload(T::pageDetail(1,['template_id'=>$template['id'],'id'=>$page['id']])))===$before,'unlimited worker still only changes candidate');
  $ctx=S::decode(Db::name('decorate_agent_run')->where('id',$run['id'])->value('context_json'));
  $clone=static function(array $input,array $context,array $extra=[])use($row):int{$copy=$row;unset($copy['id']);return (int)Db::name('decorate_agent_run')->insertGetId(array_replace($copy,['status'=>'running','model_calls'=>6,'tool_calls'=>40,'input_json'=>S::json($input),'context_json'=>S::json($context),'idempotency_key'=>'unlimited-guard:'.bin2hex(random_bytes(6))],$extra));};
  $legacy=$frozen;unset($legacy['execution_policy']);$legacy['budget_points']=25;$old=$clone($legacy,$ctx);$step->invoke(null,$old,'fixture');workerCheck(S::run(1,1,$old)['status']==='limited'&&Text::$calls===1,'legacy frozen model cap preserved');
  $pendingCtx=$ctx;$pendingCtx['calling']=true;$pending=$clone($frozen,$pendingCtx);$step->invoke(null,$pending,'fixture');workerCheck(S::run(1,1,$pending)['status']==='needs_review'&&Text::$calls===1,'unknown provider attempt never replays despite unlimited policy');
  $cancel=$clone($frozen,$ctx,['cancel_requested'=>1]);$step->invoke(null,$cancel,'fixture');workerCheck(S::run(1,1,$cancel)['status']==='canceled'&&Text::$calls===1,'manual stop takes precedence over unlimited policy');
 }finally{Db::rollback();}
 echo "unlimited-worker: $checks checks passed (real worker and DB rollback; paid network mocked)\n";
}
