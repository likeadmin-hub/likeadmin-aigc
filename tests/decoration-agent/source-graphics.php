<?php
declare(strict_types=1);
namespace app\common\service\power {
    final class MarketImageModelRuntimeService {
        public static int $quotes=0;
        public static function options(int $tenant):array{return [];}
        public static function quote(...$args):array{self::$quotes++;throw new \RuntimeException('Unexpected image runtime quote');}
        public static function reserve(...$args):array{throw new \RuntimeException('Unexpected reserve/provider');}
    }
}
namespace {
$root=dirname(__DIR__,2);require $root.'/runtime-loader.php';(new \AigcRuntimeApp($root))->initialize();
set_exception_handler(static function(\Throwable $e):void{fwrite(STDERR,$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);});
use think\facade\Db;
use app\common\enum\FileEnum;
use app\common\service\decorate\agent\{DecorationAgentOperations as O,DecorationAgentService as S,DecorationAgentCatalog as C,DecorationAgentDesignContext as D,DecorationAgentAssetReferences as Assets};
use app\common\service\decorate\{DecorateDataSourceService as Sources,DecorateTemplateService as T};
use app\common\service\power\MarketImageModelRuntimeService as Image;
$checks=0;
function graphicsCheck(bool $ok,string $why):void{global $checks;if(!$ok)throw new \RuntimeException($why);$checks++;}
function graphicsReject(callable $call,string $why,string $expected=''):void{try{$call();}catch(\InvalidArgumentException|\RuntimeException $e){if($expected!==''&&!str_contains($e->getMessage(),$expected))throw $e;graphicsCheck(true,$why);return;}throw new \RuntimeException('Accepted: '.$why);}
$entry=['app_code'=>'aigc_image','entry_key'=>'aigc_image'];
$graphic=$entry+['mode'=>'image','image'=>''];
$widget=C::create('app-collection','apps');$widget['content']['data_mode']='source';$widget['content']['source_key']='app_entries';$widget['content']['source_params']=['terminal'=>'uniapp'];$widget['content']['source_graphics']=[$graphic];
$base=['data'=>[$widget],'meta'=>[],'name'=>'source graphics','new_page'=>false];
$op=['type'=>'request_image','widget_id'=>'apps','field'=>'content.source_graphics.0.image','prompt'=>'one independent application icon'];
$scope=['scope'=>'page','source_graphic_entries'=>[$entry]];
$requested=O::apply($base,[$op],$scope)['image_requests'][0];
graphicsCheck(($requested['source_graphic_target']['app_code']??'')==='aigc_image'&&($requested['source_graphic_target']['entry_key']??'')==='aigc_image','a renderable image request freezes the real source identity');
graphicsReject(fn()=>O::apply($base,[$op],['scope'=>'page']),'a pure engine cannot invent current tenant entries');
foreach(['source','icon','unknown'] as $mode){$bad=$base;$bad['data'][0]['content']['source_graphics'][0]['mode']=$mode;graphicsReject(fn()=>O::apply($bad,[$op],$scope),'unused graphic modes cannot request paid images');}
foreach([['source_key'=>'article'],['data_mode'=>'manual'],['source_graphics'=>[$graphic+['entry_key'=>'not-live']]]] as $change){$bad=$base;$bad['data'][0]['content']=array_replace($widget['content'],$change);if(isset($change['source_graphics']))$bad['data'][0]['content']['source_graphics'][0]['entry_key']='not-live';graphicsReject(fn()=>O::apply($bad,[$op],$scope),'only the actual app_entries source tuple can request images');}
foreach([
    [$graphic,$graphic],array_fill(0,51,$graphic),[$entry+['mode'=>'bogus']],[$entry+['mode'=>'icon','icon'=>'invented']],
    [$graphic+['title'=>'fake name']],[$graphic+['link'=>['path'=>'/fake']]],[$graphic+['status'=>1]],
    [['mode'=>'image','image'=>'']],
] as $rows)graphicsReject(fn()=>O::apply(['data'=>[C::create('app-collection','apps')],'meta'=>[]],[['type'=>'update_component','widget_id'=>'apps','fields'=>['content.source_graphics'=>$rows]]],['scope'=>'page']),'source graphic rows enforce bounded unique identities and declared presentation fields');
graphicsReject(fn()=>O::apply($base,[['type'=>'edit_list','widget_id'=>'apps','field'=>'source_graphics','action'=>'copy','index'=>0]],$scope),'copy cannot silently create a duplicate source tuple');
graphicsReject(fn()=>O::apply($base,[['type'=>'update_component','widget_id'=>'apps','fields'=>['content.source_graphics.0.image'=>'https://unapproved.invalid/anything.png']]],$scope),'new declared image leaves retain the normal approved-asset whitelist');
$configured=O::apply(['data'=>[C::create('app-collection','apps')],'meta'=>[]],[['type'=>'edit_list','widget_id'=>'apps','field'=>'source_graphics','action'=>'add','item'=>$entry+['mode'=>'icon','icon'=>'photo']]],['scope'=>'page'])['candidate'];
graphicsCheck($configured['data'][0]['content']['source_key']==='app_entries'&&$configured['data'][0]['content']['data_mode']==='hybrid','graphics edits do not rewrite the source binding');
graphicsCheck(isset($configured['data'][0]['content']['source_graphics'][0]['id']),'row identity remains server-assigned');
graphicsCheck(in_array('content.source_graphics.*.image',array_column(D::contract('app-collection')['image_slots'],'field'),true),'knowledge exposes the conditional real image leaf');

$fixtureDir=public_path().'uploads/source-graphics-fixture-'.bin2hex(random_bytes(5));
Db::startTrans();try{
    request()->tenantId=1;
    $live=Sources::resolve('app_entries',['terminal'=>'uniapp'],1,['terminal'=>'uniapp']);
    graphicsCheck(count(array_filter($live,fn($v)=>($v['app_code']??'')==='aigc_image'&&($v['entry_key']??'')==='aigc_image'))===1,'fixture uses an actual currently installed app tuple');
    $template=T::add(1,['name'=>'source graphics isolated','terminal'=>'mobile']);$page=T::pageLists(1,$template['id'],'mobile')[0];
    $page=T::savePage(1,['template_id'=>$template['id'],'id'=>$page['id'],'terminal'=>'mobile','name'=>$base['name'],'data'=>S::json($base['data']),'meta'=>'[]']);$base=S::payload($page);
    $session=S::createSession(1,1,['template_id'=>$template['id'],'page_id'=>$page['id']]);
    $input=['scope'=>'page','selected_id'=>'','sources'=>['tenant','generate'],'attachments'=>[],'image_model_selection'=>[],'image_strategy'=>'existing_first','budget_points'=>25];
    $apply=new \ReflectionMethod(S::class,'applyVisualOperation');
    $real=$apply->invoke(null,$base,$op,['scope'=>'page','sources'=>['tenant'],'attachments'=>[]],[],1);
    graphicsCheck(isset($real['image_requests'][0]['source_graphic_target']),'real service injects current authoritative entries for an Agent request');
    $generation=new \ReflectionMethod(S::class,'generationContext');$ctx=['candidate'=>$base];$slots=$generation->invoke(null,1,$input,$ctx)['rendered_slots'];
    graphicsCheck(in_array($op['field'],array_column($slots,'field'),true),'generation context includes a live image-mode target');
    $hidden=$base;$hidden['data'][0]['content']['source_graphics'][0]['mode']='source';$ctx['candidate']=$hidden;$slots=$generation->invoke(null,1,$input,$ctx)['rendered_slots'];
    graphicsCheck(!in_array($op['field'],array_column($slots,'field'),true),'generation/extraction options do not advertise an unused graphic');
    mkdir($fixtureDir,0777,true);copy($root.'/public/resource/image/tenantapi/default/banner002.png',$fixtureDir.'/result.png');
    $uri=ltrim(str_replace(public_path(),'',$fixtureDir),'/').'/result.png';$asset=['image_uri'=>$uri,'storage_scope'=>'tenant','storage_engine'=>'local','storage_domain'=>'','url'=>'/'.$uri];
    $run=(int)Db::name('decorate_agent_run')->insertGetId(['tenant_id'=>1,'admin_id'=>1,'session_id'=>$session['id'],'status'=>'waiting_images','input_json'=>S::json($input),'context_json'=>'{}','result_json'=>'{}','events_json'=>'[]','idempotency_key'=>'source-graphics:'.bin2hex(random_bytes(8))]);
    $candidate=(int)Db::name('decorate_agent_candidate')->insertGetId(['tenant_id'=>1,'admin_id'=>1,'session_id'=>$session['id'],'run_id'=>$run,'status'=>'ready','revision'=>1,'base_json'=>S::json($base),'base_hash'=>S::hash($base),'payload_json'=>S::json($base),'changes_json'=>'[]']);Db::name('decorate_agent_run')->where('id',$run)->update(['candidate_id'=>$candidate]);
    $request=$real['image_requests'][0];$slot=(int)Db::name('decorate_agent_image_slot')->insertGetId(['tenant_id'=>1,'admin_id'=>1,'session_id'=>$session['id'],'run_id'=>$run,'candidate_id'=>$candidate,'slot_key'=>'apps:'.$op['field'],'status'=>'running','request_json'=>S::json($request),'result_json'=>'{}']);
    $task=(int)Db::name('ai_app_task')->insertGetId(['tenant_id'=>1,'user_id'=>0,'app_code'=>'system_default','business_table'=>'decorate_agent_image_slot','business_id'=>$slot,'status'=>'success']);
    $receipt=(int)Db::name('ai_consumption_log')->insertGetId(['tenant_id'=>1,'user_id'=>0,'app_task_id'=>$task,'app_code'=>'system_default','protocol'=>'image_generate','run_status'=>'success','billing_status'=>'settled','response_summary'=>S::json(['images'=>[$asset]])]);Db::name('decorate_agent_image_slot')->where('id',$slot)->update(['consumption_id'=>$receipt]);
    $slotRow=Db::name('decorate_agent_image_slot')->where('id',$slot)->find();$complete=new \ReflectionMethod(S::class,'completeImage');
    $complete->invoke(null,$slotRow,['status'=>'success','billing_status'=>'settled','images'=>[$asset]]);
    $completed=S::decode(Db::name('decorate_agent_candidate')->where('id',$candidate)->value('payload_json'));
    $ref=$completed['data'][0]['_decoration_asset_refs'][$op['field']]??[];
    graphicsCheck(($ref['kind']??'')==='slot'&&($ref['id']??0)===$slot&&($ref['expected_value']??'')==='/'.$uri,'actual completion stores managed identity for the new optional list leaf');
    graphicsCheck($completed['data'][0]['content']['source_graphics'][0]['image']==='/'.$uri&&$completed['data'][0]['content']['source_key']==='app_entries','hydration changes only the authorized graphic and retains the source');
    graphicsCheck(Assets::resolveUrls($completed,2)===[],'foreign tenant cannot resolve the generated graphic');
    $applied=S::apply(1,1,['candidate_id'=>$candidate,'idempotency_key'=>'source-graphics-apply:'.$candidate]);
    graphicsCheck(S::payload($applied['page'])['data'][0]['_decoration_asset_refs'][$op['field']]===$ref,'explicit apply persists the same new-leaf managed ref');
    $undo=S::rollback(1,1,['checkpoint_id'=>$applied['checkpoint_id'],'idempotency_key'=>'source-graphics-undo:'.$candidate]);
    graphicsCheck(S::hash(S::payload($undo['page']))===S::hash($base),'rollback removes the applied result without resurrecting its source-graphic image');
    Db::name('decorate_agent_candidate')->where('id',$candidate)->update(['status'=>'ready']);
    $hydrate=new \ReflectionMethod(S::class,'hydrate');
    foreach(['tuple','mode','binding','row_id'] as $change){
        $moved=$base;
        if($change==='tuple')$moved['data'][0]['content']['source_graphics'][0]['entry_key']='changed';
        if($change==='mode')$moved['data'][0]['content']['source_graphics'][0]['mode']='source';
        if($change==='binding')$moved['data'][0]['content']['source_key']='article';
        if($change==='row_id')$moved['data'][0]['content']['source_graphics'][0]['id']='different-row';
        Db::name('decorate_agent_candidate')->where('id',$candidate)->update(['payload_json'=>S::json($moved)]);
        $hydrate->invoke(null,$slotRow,$asset['url'],$asset);
        graphicsCheck(S::decode(Db::name('decorate_agent_candidate')->where('id',$candidate)->value('payload_json'))===$moved,'late result cannot hit an equal-empty but different '.$change);
    }
    $second=array_values(array_filter($live,fn($v)=>($v['app_code']??'')==='aigc_llm'))[0];
    $reordered=$base;$reordered['data'][0]['content']['source_graphics']=[$second?array_intersect_key($second,array_flip(['app_code','entry_key']))+['mode'=>'image','image'=>'']:[], $graphic];
    Db::name('decorate_agent_candidate')->where('id',$candidate)->update(['payload_json'=>S::json($reordered)]);
    $hydrate->invoke(null,$slotRow,$asset['url'],$asset);
    graphicsCheck(S::decode(Db::name('decorate_agent_candidate')->where('id',$candidate)->value('payload_json'))===$reordered,'reordering two live equal-empty rows cannot redirect the pending image');
    Db::name('decorate_agent_run')->where('id',$run)->update(['status'=>'completed']);Db::name('decorate_agent_image_slot')->where('id',$slot)->update(['status'=>'failed']);
    $beforeSlots=Db::name('decorate_agent_image_slot')->where('run_id',$run)->count();
    graphicsReject(fn()=>S::retryImage(1,1,['slot_id'=>$slot]),'retry cannot silently choose a different live row after reordering','应用图形位置');
    graphicsCheck(Db::name('decorate_agent_image_slot')->where('run_id',$run)->count()===$beforeSlots,'target mismatch creates no retry attempt');
    $adminFile=(int)Db::name('tenant_file')->insertGetId(['tenant_id'=>1,'source'=>FileEnum::SOURCE_ADMIN,'type'=>FileEnum::IMAGE_TYPE,'uri'=>$uri,'storage_scope'=>'tenant','storage_engine'=>'local','storage_domain'=>'']);
    graphicsReject(fn()=>S::replaceImage(1,1,['slot_id'=>$slot,'file_id'=>$adminFile]),'manual replacement cannot overwrite a changed source tuple','应用图形位置');
    $process=new \ReflectionMethod(S::class,'processImages');
    Db::startTrans();try{
        Db::name('decorate_agent_candidate')->where('id',$candidate)->update(['payload_json'=>S::json($base)]);Db::name('decorate_agent_image_slot')->where('id',$slot)->update(['status'=>'queued','consumption_id'=>0]);Db::name('decorate_agent_run')->where('id',$run)->update(['status'=>'waiting_images']);
        Db::name('app')->where('code','aigc_image')->update(['status'=>'disabled']);
        $process->invoke(null,Db::name('decorate_agent_run')->where('id',$run)->find(),$input);
        graphicsCheck(Image::$quotes===0&&Db::name('decorate_agent_image_slot')->where('id',$slot)->value('status')==='failed','application disable after queue is observed before any quote/reservation');
    }finally{Db::rollback();}
    Db::name('decorate_agent_candidate')->where('id',$candidate)->update(['payload_json'=>S::json($hidden)]);Db::name('decorate_agent_image_slot')->where('id',$slot)->update(['status'=>'queued','consumption_id'=>0]);
    $process->invoke(null,Db::name('decorate_agent_run')->where('id',$run)->find(),$input);
    graphicsCheck(Image::$quotes===0&&(int)Db::name('decorate_agent_image_slot')->where('id',$slot)->value('consumption_id')===0,'a no-longer-rendered queued target is stopped before price/reserve/provider');
}finally{Db::rollback();if(is_file($fixtureDir.'/result.png'))unlink($fixtureDir.'/result.png');if(is_dir($fixtureDir))rmdir($fixtureDir);}
echo "source graphics: $checks checks passed (real DB rollback, fixture removed, no provider)\n";
}
