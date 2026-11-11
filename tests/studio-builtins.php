<?php
/** Transactional cross-installation source replay; measured fixtures only, no network or model calls. */
require dirname(__DIR__).'/vendor/autoload.php';(new think\App())->initialize();
use think\facade\{Db,Cache};
use app\common\model\app\AppCase;
use app\common\service\app\{StudioBuiltinTemplates as B,StudioBuiltinCatalog as C,AppCaseService,SharedInspirationMedia};
use app\common\service\app\aigc_story_commerce\{StoryCommerceService as S,StoryCommercePolicy as P,StoryCommerceCatalog as SC,StoryCommerceTemplates as ST};
use app\common\service\app\aigc_motion_sync\{MotionSyncService as M,MotionSyncTemplates as MT};
use app\common\service\app\aigc_micro_video\MicroVideoMedia;
use app\common\service\power\MarketMotionSyncRuntimeService;
use app\common\service\storage\{StorageConfigService,StorageSignedUrlService};
set_exception_handler(static function(Throwable $e){fwrite(STDERR,'FAIL '.get_class($e).': '.$e->getMessage().' at '.basename($e->getFile()).':'.$e->getLine().PHP_EOL);exit(1);});
if(getenv('STUDIO_BUILTIN_LOCAL_TEST')!=='x_cn'||config('database.connections.mysql.database')!=='x_cn')throw new RuntimeException('Explicit local test opt-in required');
$checks=0;function check(bool $v,string $why):void{global $checks;$checks++;if(!$v)throw new RuntimeException($why);}
function rejects(callable $call,string $why):void{try{$call();}catch(Throwable){check(true,$why);return;}throw new RuntimeException($why);}
$root=sys_get_temp_dir().'/studio_builtin_'.bin2hex(random_bytes(8));mkdir($root,0700);foreach(['motion','story','site-b'] as $d)mkdir($root.'/'.$d,0700);$cacheKeys=[];
Db::startTrans();
try{C::withDirectory(M::APP,$root.'/motion',static function()use($root,&$cacheKeys){C::withDirectory(S::APP,$root.'/story',static function()use($root,&$cacheKeys){
    $target=600011;$user=600011;$now=time();$storage=StorageConfigService::getEffectiveConfig(1);$engine=$storage['default'];$domain=rtrim($storage['engine'][$engine]['domain']??'','/');
    check(in_array($engine,['aliyun','qcloud','qiniu'],true)&&$domain!=='','Public cloud fixture configured');
    Db::name('tenant')->insert(['id'=>$target,'sn'=>'studio_builtin_fixture','name'=>'Portable fixture','create_time'=>$now,'update_time'=>$now]);
    foreach(Db::name('tenant_app')->where('tenant_id',1)->select()->toArray() as $g){unset($g['id']);$g['tenant_id']=$target;Db::name('tenant_app')->insert($g);}
    $prefix='uploads/studio_builtin_'.bin2hex(random_bytes(7));$coords=['storage_scope'=>$storage['scope'],'storage_engine'=>$engine,'storage_domain'=>$domain];
    $image=['uri'=>$prefix.'.png','reference_slot_id'=>'product_slot','name'=>'reviewed image']+$coords;
    $video=['uri'=>$prefix.'.mp4','name'=>'reviewed motion']+$coords;$preview=['uri'=>$prefix.'_preview.mp4']+$coords;
    $receipts=[];foreach([[$image,10],[$video,20],[$preview,20]] as [$asset,$type])$receipts[$asset['uri']]=Db::name('tenant_file')->insertGetId(['tenant_id'=>1,'source'=>1,'source_id'=>1,'type'=>$type,'name'=>'reviewed fixture','uri'=>$asset['uri'],'create_time'=>$now,'update_time'=>$now,'delete_time'=>0]+$coords);
    $model=SC::models(1)[0];$model=SC::models(1,(string)$model['id'])[0];$spec=$model['specs'][0];
    $longRule=str_repeat('保持剧情因果。',180);$genres=S::settings(1)['genres'];foreach($genres as &$g)if($g['code']==='comedy')$g['instruction']=$longRule;unset($g);S::setup(1,['genres'=>$genres]);
    $body=P::body(['images'=>[$image],'style_id'=>P::NO_STYLE,'product_info'=>'已确认的商品','requirements'=>'胶片质感','script'=>'胶片风格，围绕 @图片1 讲述一个小误会。','video_selection'=>['model_id'=>$model['id'],'quality'=>$spec['quality'],'ratio'=>$spec['ratio'],'duration'=>$spec['duration']]]);
    $project=S::save(1,1,['body'=>$body,'confirm_script'=>true]);$body=P::json(S::owned(1,1,$project['id'])['body_json']);
    $storyJob=S::table('job')->insertGetId(['tenant_id'=>1,'user_id'=>1,'project_id'=>$project['id'],'project_revision'=>1,'action'=>'video','status'=>'success','idempotency_key'=>'story_builtin_'.bin2hex(random_bytes(10)),'input_hash'=>str_repeat('a',64),'request_json'=>P::encode(['snapshot'=>$body]),'receipt_json'=>'{}','result_json'=>P::encode(['videos'=>[$preview+['video_uri'=>$preview['uri']]]]),'create_time'=>$now,'update_time'=>$now]);
    // Cache already measured source media, just as an existing completed motion job does.
    $im=MicroVideoMedia::validateImages(1,1,[$image],1)[0];$imageFacts=['width'=>320,'height'=>320,'file_size'=>3000,'mime_type'=>'image/png'];
    $key='motion_image_facts:'.hash('sha256',json_encode([1,1,$im]));$cacheKeys[]=$key;Cache::set($key,$imageFacts,120);
    $videoFacts=['width'=>320,'height'=>320,'file_size'=>4800,'mime_type'=>'video/mp4','duration'=>3.2];
    $key='owned_video_probe_v2:'.hash('sha256',json_encode([1,1,$receipts[$video['uri']],$video['uri'],$now,$coords]));$cacheKeys[]=$key;Cache::set($key,$videoFacts,120);
    $models=array_values(array_filter(MarketMotionSyncRuntimeService::options(1),static fn($m)=>!empty($m['available'])));check((bool)$models,'Callable motion fixture model');$mm=$models[0];
    $motionBody=M::body(['image'=>$image,'video'=>$video,'model_key'=>$mm['model_key'],'quality'=>$mm['specs'][0]['quality'],'orientation'=>'video','prompt'=>'保持人物和动作一致']);
    $mp=M::save(1,1,['body'=>$motionBody]);$motionBody=M::json(M::owned(1,1,$mp['id'])['body_json']);
    $motionJob=M::table('task')->insertGetId(['tenant_id'=>1,'user_id'=>1,'project_id'=>$mp['id'],'project_revision'=>1,'idempotency_key'=>'motion_builtin_'.bin2hex(random_bytes(10)),'status'=>'success','prompt'=>$motionBody['prompt'],'request_json'=>M::encode(['body'=>$motionBody]),'create_time'=>$now,'update_time'=>$now]);
    M::table('result')->insert(['tenant_id'=>1,'user_id'=>1,'task_id'=>$motionJob,'asset_id'=>0,'video_uri'=>$preview['uri'],'duration'=>3.2,'width'=>320,'height'=>320,'create_time'=>$now]+$coords);
    $money=Db::name('user')->where('id',1)->value('user_money');$points=Db::name('tenant')->where('id',1)->value('point_balance');
    foreach([[S::APP,ST::class,$storyJob],[M::APP,MT::class,$motionJob]] as [$app,$templates,$job]){
        rejects(fn()=>B::fromJob($app,$job,[]),'Explicit public media consent is mandatory');
        rejects(fn()=>B::fromJob($app,$job,['confirm_public_media'=>true],$target),'Tenant cannot publish another tenant job');
        $builtin=B::fromJob($app,$job,['confirm_public_media'=>true,'title'=>'租户确认内置 '.$app],1);$id=$builtin['id'];
        check($builtin['is_builtin']&&$builtin['tenant_id']===0&&$builtin['builtin_version']===1,'Independent platform template with stable version');
        check(B::fromJob($app,$job,['confirm_public_media'=>true],1)['id']===$id,'Repeated record action is idempotent');
        $link=B::info($app,$id);$sourceId=(int)$link['source_case_id'];rejects(fn()=>B::publish($app,$sourceId,['confirm_public_media'=>true],$target),'Tenant cannot publish another tenant template');
        check($templates::detail(1,$sourceId)['published_builtin_version']===1,'Source tenant can see the version for a controlled update');
        $entries=C::all($app);check(count($entries)===1,'One authored definition per source job');$entry=array_values($entries)[0];$text=json_encode($entry);
        foreach(['file_id','user_id','tenant_id','job_id','quote_token','api_key'] as $field)check(!str_contains($text,'"'.$field.'"'),'Portable source excludes '.$field);
        if($app===S::APP)check($entry['configuration']['video_selection']['model_id']===''&&$entry['configuration']['script']===$body['script'],'Story preserves exact script and remaps the local model');
        else check($entry['configuration']['video']['duration']===3.2&&$entry['configuration']['orientation']==='video','Motion preserves measured video, orientation and quality');
        $row=AppCase::where('id',$id)->find();check((int)$row['source_task_id']===0,'No source-site task identity on a global case');
        rejects(fn()=>$templates::save($target,['id'=>$id,'title'=>'租户不能改内置']),'Tenant cannot maintain a platform definition');
        rejects(fn()=>AppCaseService::setStatus(0,$app,$id,0),'Generic case manager cannot bypass source versioning');
        $copy=$templates::remix($target,$user,$id);check($copy['id']>0,'Other tenant receives an independent draft');
        if($app===S::APP)check(str_contains($copy['body']['genre_snapshot']['instruction'],$longRule),'A different site retains the full configured narrative rule instead of truncating it');
        $draftService=$app===M::APP?M::class:S::class;
        $resaved=$draftService::save($target,$user,['id'=>$copy['id'],'revision'=>$copy['revision'],'body'=>$copy['body'],'confirm_script'=>true]);
        check($resaved['revision']===$copy['revision']+1,'Redacted PC/mobile draft round-trips without source-site file IDs');
        $listed=AppCaseService::lists($target,$app,['page_no'=>1],true);check(in_array($id,array_column($listed['lists'],'id'),true),'Shared inspiration gallery includes the enabled builtin');
        $url=StorageSignedUrlService::resolve($image['uri'],'platform',$engine,$domain,$target);check($url===$domain.'/'.$image['uri'],'Public source origin resolves without target credentials');
        $updated=$templates::save(0,['id'=>$id,'title'=>'内置修订版','builtin_version'=>1]);check($updated['builtin_version']===2,'Platform metadata edit increments the source revision');
        rejects(fn()=>$templates::status(0,$id,0,['builtin_version'=>1]),'Stale source revision cannot overwrite an edit');
        $templates::status(0,$id,0,['builtin_version'=>2]);rejects(fn()=>$templates::detail($target,$id,true),'Disabled builtin is not publicly reusable');
        $templates::status(0,$id,1,['builtin_version'=>3]);
        // Replay only the authored JSON into a clean mapping: source upload IDs are absent.
        $latest=array_values(C::all($app))[0];$site=$root.'/site-b';$file=$site.'/'.$latest['key'].'.json';file_put_contents($file,json_encode($latest));
        AppCase::where('id',$id)->delete();B::table()->where(['app_code'=>$app,'case_id'=>$id])->delete();
        C::withDirectory($app,$site,static function()use($app,$templates,$target,$user,$image,$video,$preview,$latest,$id,$engine,$domain){
            B::sync($app,true);$link=B::table()->where(['app_code'=>$app,'builtin_key'=>$latest['key']])->find();$newId=(int)$link['case_id'];check($newId!==$id,'Independent installation creates its own case ID');
            B::sync($app,true);check(B::table()->where(['app_code'=>$app,'builtin_key'=>$latest['key']])->count()===1,'Repeat installation does not duplicate definitions');
            Db::name('tenant_file')->where('tenant_id',1)->whereIn('uri',[$image['uri'],$video['uri'],$preview['uri']])->update(['delete_time'=>time()]);
            $restored=$templates::remix($target,$user,$newId);check($restored['id']>0,'Imported source remains reusable after original upload records disappear');
            $bad=$latest;$bad['model_identity']['upstream_resource_key']='missing-reviewed-model';rejects(fn()=>B::bodyFor($app,$target,$bad),'Missing model never silently falls back');
            $templates::delete(0,$newId,['builtin_version'=>4]);rejects(fn()=>$templates::detail($target,$newId,true),'Delete tombstone removes public reuse');
            check(SharedInspirationMedia::url($image['uri'],$engine,$domain)===$domain.'/'.$image['uri'],'Deleted definitions keep shared object protection');
        });unlink($file);
        Db::name('tenant_file')->where('tenant_id',1)->whereIn('uri',[$image['uri'],$video['uri'],$preview['uri']])->update(['delete_time'=>0]);
    }
    check(Db::name('user')->where('id',1)->value('user_money')===$money&&Db::name('tenant')->where('id',1)->value('point_balance')===$points,'Publication and remix do not charge either payer');
    foreach(C::APPS as $app){check(method_exists('app\\tenantapi\\controller\\app\\'.$app.'\\CaseController','builtinFromJob'),'Tenant API declares the own-record publication endpoint');check(!method_exists('app\\platformapi\\controller\\app\\'.$app.'\\CaseController','builtinFromJob'),'Platform records do not expose the relocated action');}
    foreach([['uri'=>'a.png','storage_engine'=>'local','storage_domain'=>''],['uri'=>'a.png?signature=secret','storage_engine'=>$engine,'storage_domain'=>$domain],['uri'=>'a.png','storage_engine'=>$engine,'storage_domain'=>'https://127.0.0.1']] as $bad)rejects(fn()=>C::publicAsset($bad),'Local, signed or unsafe objects are not portable');
});});echo "PASS $checks portable studio source, model identity, complete remix, platform permissions, idempotent replay, versioning, source-independent media and object protection checks; no network/provider calls\n";
}finally{Db::rollback();foreach($cacheKeys as $key)Cache::delete($key);foreach(['motion','story','site-b'] as $d){foreach(glob($root.'/'.$d.'/*') as $f)unlink($f);rmdir($root.'/'.$d);}rmdir($root);}
