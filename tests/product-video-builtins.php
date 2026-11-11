<?php
/** Rollback-only, temporary authored catalog. No network, OSS writes or model submissions. */
require dirname(__DIR__).'/vendor/autoload.php';(new think\App())->initialize();
use app\common\service\app\aigc_product_video\{ProductVideoService as S,ProductVideoJobs as J,ProductVideoPolicy as P,ProductVideoTemplates as T,ProductVideoBuiltins as B,ProductVideoBuiltinCatalog as C};
use app\common\service\app\AppCaseService as Cases;
use app\common\service\storage\{StorageConfigService,StorageSignedUrlService};
use think\facade\Db;
set_exception_handler(static function(Throwable $e):void{fwrite(STDERR,'FAIL '.get_class($e).': '.$e->getMessage().' at '.$e->getFile().':'.$e->getLine().PHP_EOL);exit(1);});
if(getenv('PRODUCT_VIDEO_LOCAL_TEST')!=='x_cn'||config('database.connections.mysql.database')!=='x_cn')throw new RuntimeException('Explicit local test opt-in required');
$checks=0;function yes(bool $ok,string $why):void{global $checks;$checks++;if(!$ok)throw new RuntimeException($why);}
function reject(callable $call,string $why):void{try{$call();}catch(Exception $e){yes(true,$why);return;}throw new RuntimeException($why);}
$dir=sys_get_temp_dir().'/pv_builtin_'.bin2hex(random_bytes(8));mkdir($dir,0700);Db::startTrans();
try{C::withDirectory($dir,static function()use($dir){
 $target=600009;$user=600009;$storage=StorageConfigService::getEffectiveConfig(1);$engine=$storage['default'];$domain=rtrim($storage['engine'][$engine]['domain']??'','/');
 yes(in_array($engine,['aliyun','qcloud','qiniu'],true)&&$domain!=='','Local public cloud fixture configured');
 Db::name('tenant')->insert(['id'=>$target,'sn'=>'pv_builtin_fixture','name'=>'Builtin rollback fixture','create_time'=>time(),'update_time'=>time()]);
 foreach(Db::name('tenant_app')->where('tenant_id',1)->select()->toArray() as $grant){unset($grant['id']);$grant['tenant_id']=$target;Db::name('tenant_app')->insert($grant);}
 $model=S::models(1,'video')['models'][0];$model=S::models(1,'video',$model['id'])['models'][0];$spec=$model['specs'][0];
 $uri='uploads/builtin_fixture_'.bin2hex(random_bytes(6)).'.png';$mediaUri=str_replace('.png','.mp4',$uri);
 $asset=['uri'=>$uri,'reference_slot_id'=>'builtin_image_1','mention_name'=>'图片1','storage_scope'=>$storage['scope'],'storage_engine'=>$engine,'storage_domain'=>$domain];
 Db::name('tenant_file')->insert(['tenant_id'=>1,'source_id'=>1,'source'=>1,'type'=>10,'name'=>'shared fixture','uri'=>$uri,'storage_scope'=>$storage['scope'],'storage_engine'=>$engine,'storage_domain'=>$domain,'create_time'=>time(),'update_time'=>time(),'delete_time'=>0]);
 $body=P::body(['images'=>[$asset],'product_info'=>'测试商品的已确认特征','prompt'=>'展示 @图片1 的商品细节','product_url'=>'https://www.amazon.com/dp/B012345678','market_region'=>'日本','language'=>'日语','audio'=>null,'video_selection'=>['model_id'=>$model['id'],'quality'=>$spec['quality'],'ratio'=>$spec['ratio'],'duration'=>$spec['duration']]]);
 $project=S::save(1,1,['body'=>$body]);$job=J::table()->insertGetId(['tenant_id'=>1,'user_id'=>1,'project_id'=>$project['id'],'project_revision'=>1,'action'=>'video','status'=>'success','idempotency_key'=>'pv_builtin_'.bin2hex(random_bytes(12)),'input_hash'=>str_repeat('b',64),'request_json'=>P::encode(['snapshot'=>$project['body']]),'receipt_json'=>'{}','result_json'=>P::encode(['videos'=>[['uri'=>$mediaUri,'storage_scope'=>$storage['scope'],'storage_engine'=>$engine,'storage_domain'=>$domain]]]),'create_time'=>time(),'update_time'=>time()]);
 $normal=T::fromJob(0,$job,['title'=>'内置模板事务测试']);reject(fn()=>B::publish($normal['id'],[]),'Explicit public media consent required');
 reject(fn()=>T::detail($target,$normal['id'],true),'Other tenant private templates remain isolated');
 $jobs=J::table()->count();$builtin=B::publish($normal['id'],['confirm_public_media'=>true]);$id=$builtin['id'];
 yes(B::fromJob($job,['confirm_public_media'=>true])['id']===$id,'Record shortcut reuses an existing builtin');
 reject(fn()=>B::fromJob($job,[]),'Record shortcut requires explicit public media confirmation');
 yes($id!==$normal['id']&&$builtin['tenant_id']===0&&$builtin['is_builtin']===true,'Independent platform-owned template');
 yes($builtin['builtin_version']===1,'Initial source version');
 $row=Db::name('app_case')->where('id',$id)->find();yes((int)$row['source_task_id']===0&&(int)$row['source_result_id']===0,'Global record has no tenant job identity');
 $source=glob($dir.'/*.json');yes(count($source)===1,'Exactly one authored source definition');$raw=file_get_contents($source[0]);$entry=array_values(C::all())[0];
 foreach(['product_url','api_key','user_id','tenant_id','file_id','source_task_id',$model['id']] as $secret)yes(!str_contains($raw,'"'.$secret.'"')&&!str_contains($raw,'"'.$model['id'].'"'),'Source definition excludes installation identity '.$secret);
 yes($entry['configuration']['video_selection']['model_id']==='','Portable source maps market identity on installation');yes($entry['configuration']['images'][0]['uri']===$uri&&$entry['media']['uri']===$mediaUri,'OSS objects are referenced unchanged');
 $tenant=T::detail($target,$id,true);yes($tenant['cover_url']===$domain.'/'.$uri&&$tenant['media_url']===$domain.'/'.$mediaUri,'Cross-tenant managed URLs preserve original OSS');
 $admin=T::detail($target,$id);yes($admin['readonly']===true,'Tenant admin sees builtin as readonly');
 foreach([fn()=>T::save($target,['id'=>$id,'builtin_version'=>1]),fn()=>T::status($target,$id,0,['builtin_version'=>1]),fn()=>T::delete($target,$id,['builtin_version'=>1]),fn()=>Cases::saveByAppCodes($target,[P::APP],['id'=>$id,'title'=>'tamper'],P::APP),fn()=>Cases::setStatusByAppCodes($target,[P::APP],$id,0),fn()=>Cases::deleteByAppCodes($target,[P::APP],$id)] as $attack)reject($attack,'Readonly across product and shared gallery APIs');
 yes(in_array($id,array_column(T::lists($target,[],true)['lists'],'id')),'Other tenant public gallery includes global case');
 yes(in_array($id,array_column(Cases::listsByAppCodes($target,[P::APP],[],true),'id')),'Homepage gallery includes global case');
 yes(!in_array($id,array_column(Cases::listsByAppCodes($target,[P::APP],[],false),'id')),'Generic tenant admin gallery stays isolated');
 yes(Cases::detailByAppCodes($target,[P::APP],$id)['media_url']===$domain.'/'.$mediaUri,'Homepage detail uses shared storage reference');
 yes(StorageSignedUrlService::resolve($uri,'platform',$engine,$domain,$target)===$domain.'/'.$uri,'Shared public read does not use target tenant credentials');
 $files=Db::name('tenant_file')->count();$copy=T::remix($target,$user,$id);yes(Db::name('tenant_file')->count()===$files+1,'Only an ownership reference is created');
 yes($copy['body']['images'][0]['uri']===$uri&&$copy['body']['images'][0]['storage_scope']==='platform','Same object across tenants');
 foreach(['product_info','prompt','market_region','language','video_selection'] as $key)yes($copy['body'][$key]===$body[$key],'Complete creation setting restored '.$key);
 yes(empty($copy['body']['product_url']),'Import link is never replayed');T::remix($target,$user,$id);yes(Db::name('tenant_file')->count()===$files+1,'Repeat remix does not duplicate asset references');yes(J::table()->count()===$jobs,'Remix never submits a generation or charge');
 reject(fn()=>B::publish($normal['id'],['confirm_public_media'=>true]),'Stale publish rejected');$updated=B::publish($normal['id'],['confirm_public_media'=>true,'expected_version'=>1]);yes($updated['id']===$id&&$updated['builtin_version']===2&&count(glob($dir.'/*.json'))===1,'Republish updates one stable definition');
 $saved=T::save(0,['id'=>$id,'builtin_version'=>2,'title'=>'更新后的内置模板','configuration'=>['prompt'=>'新的商品展示脚本']]);yes($saved['builtin_version']===3&&T::detail($target,$id,true)['prompt']==='新的商品展示脚本','Platform edit updates source and all tenant reads');
 reject(fn()=>T::save(0,['id'=>$id,'builtin_version'=>2,'title'=>'stale']),'Optimistic concurrency rejects stale editor');
 // Source sync restores DB damage without copying another case or changing the template key.
 Db::name('app_case')->where('id',$id)->update(['title'=>'stale cache']);B::table()->where('case_id',$id)->update(['source_hash'=>'']);B::sync(true);yes(T::detail($target,$id,true)['title']==='更新后的内置模板','Update sync restores authoritative definition idempotently');
 foreach(['save','setStatus','delete'] as $action)reject(fn()=>Cases::$action(0,P::APP,...($action==='save'?[['id'=>$id,'title'=>'bypass']]:[$id,...($action==='setStatus'?[1]:[])])),'Generic platform mutations cannot bypass source owner');
 T::status(0,$id,0,['builtin_version'=>3]);reject(fn()=>T::detail($target,$id,true),'Disabled builtins hidden to every tenant');yes(C::mediaUrl($uri,$engine,$domain)!==null,'Disabled template retains old draft assets');T::status(0,$id,1,['builtin_version'=>4]);
 // All physical deletion paths are guarded for shared image and video references.
 $method=new ReflectionMethod(\app\common\service\ai\AiTaskOperationService::class,'deleteStoredFile');$method->setAccessible(true);$method->invoke(null,['local_uri'=>$mediaUri,'tenant_id'=>1,'storage_scope'=>$storage['scope'],'storage_engine'=>$engine,'storage_domain'=>$domain]);yes(true,'AI task physical deletion stops before storage driver');
 foreach([['tenant',\app\tenantapi\logic\FileLogic::class,'tenant_file'],['platform',\app\platformapi\logic\FileLogic::class,'file']] as [$label,$logic,$table]){
   $fileId=Db::name($table)->insertGetId(['source'=>0,'source_id'=>1,'type'=>10,'name'=>'pinned fixture','uri'=>$uri,'storage_scope'=>$storage['scope'],'storage_engine'=>$engine,'storage_domain'=>$domain,'create_time'=>time(),'update_time'=>time()]+($table==='tenant_file'?['tenant_id'=>1]:[]));
   $logic::delete(['ids'=>[$fileId]]);yes((int)Db::name($table)->where('id',$fileId)->value('delete_time')>0,$label.' file deletion soft-deletes only its record');
 }
 T::delete(0,$id,['builtin_version'=>5]);reject(fn()=>T::remix($target,$user,$id),'Deleted global template cannot replay');yes(C::mediaUrl($uri,$engine,$domain)===$domain.'/'.$uri,'Tombstone preserves objects still used by user drafts');yes(S::owned($target,$user,$copy['id'])['delete_time']===0,'Existing user draft survives global deletion');
 $retained=array_values(C::all())[0];$retained['configuration']['images'][0]['uri']='uploads/new-fixture.png';C::write($retained);yes(C::mediaUrl($uri,$engine,$domain)!==null,'A newer revision retains removed shared object pins');
 foreach([['uri'=>'https://host/item.png','storage_engine'=>$engine,'storage_domain'=>$domain],['uri'=>'../private.png','storage_engine'=>$engine,'storage_domain'=>$domain],['uri'=>'a.png?signature=secret','storage_engine'=>$engine,'storage_domain'=>$domain],['uri'=>'a.png','storage_engine'=>'local','storage_domain'=>$domain],['uri'=>'a.png','storage_engine'=>$engine,'storage_domain'=>'https://127.0.0.1']] as $bad)reject(fn()=>C::publicAsset($bad),'Local, signed or unsafe media cannot become builtin');
 yes(C::mediaUrl($uri,$engine,'https://another-bucket.example.com')===null,'Pinned references match exact storage coordinate, not just object path');
 $unrelated='uploads/pv_unrelated_'.bin2hex(random_bytes(6)).'.txt';file_put_contents(public_path().$unrelated,'local fixture');try{$method->invoke(null,['local_uri'=>$unrelated,'tenant_id'=>1,'storage_scope'=>'platform','storage_engine'=>'local','storage_domain'=>'']);yes(!is_file(public_path().$unrelated),'Unrelated managed local asset retains normal physical deletion');}finally{if(is_file(public_path().$unrelated))unlink(public_path().$unrelated);}
 $broken=$retained;$broken['model_identity']['upstream_resource_key']='missing-builtin-model';reject(fn()=>B::bodyFor($target,$broken),'Missing target market model never silently falls back');
 $private=new ReflectionMethod(B::class,'shared');$private->setAccessible(true);reject(fn()=>$private->invoke(null,['uri'=>'uploads/not-shared.png','storage_scope'=>'platform','storage_engine'=>'local','storage_domain'=>''],0),'Private or local source cannot be promoted');
 yes(!method_exists(\app\tenantapi\controller\app\aigc_product_video\CaseController::class,'builtin'),'Tenant has no builtin publish route');
 // An advanced template carries a fresh text plan across model-ID remapping as well.
 $advanced=$project['body'];$advanced['mode']='advanced';$advanced['plan']=['data'=>['summary'=>'展示已确认商品细节','duration'=>$spec['duration'],'shots'=>[['start'=>0,'end'=>$spec['duration']/2,'description'=>'展示商品轮廓'],['start'=>$spec['duration']/2,'end'=>$spec['duration'],'description'=>'展示商品细节']],'video_prompt'=>'展示 @图片1 的商品轮廓，然后展示商品细节。'],'input_key'=>P::planKey($advanced)];
 $advanced['prompt']=$advanced['plan']['data']['video_prompt'];
 $advancedJob=J::table()->insertGetId(['tenant_id'=>1,'user_id'=>1,'project_id'=>$project['id'],'project_revision'=>1,'action'=>'video','status'=>'success','idempotency_key'=>'pv_advanced_builtin_'.bin2hex(random_bytes(12)),'input_hash'=>str_repeat('c',64),'request_json'=>P::encode(['snapshot'=>$advanced]),'receipt_json'=>'{}','result_json'=>P::encode(['videos'=>[['uri'=>$mediaUri,'storage_scope'=>$storage['scope'],'storage_engine'=>$engine,'storage_domain'=>$domain]]]),'create_time'=>time(),'update_time'=>time()]);
 $advancedSource=T::fromJob(0,$advancedJob,['title'=>'高级模式内置事务测试']);$advancedBuiltin=B::publish($advancedSource['id'],['confirm_public_media'=>true]);$advancedCopy=T::remix($target,$user,$advancedBuiltin['id']);
 yes($advancedCopy['body']['mode']==='advanced'&&$advancedCopy['plan_fresh'],'Advanced one-click draft retains a fresh executable plan');
 yes($advancedCopy['body']['plan']['data']===$advanced['plan']['data'],'Advanced planning script and shot structure survive cross-tenant restoration');
 yes($advancedCopy['body']['video_selection']===$advanced['video_selection'],'Advanced plan keeps the exact market model and specification');
 $direct=T::save(0,['id'=>$advancedBuiltin['id'],'builtin_version'=>1,'configuration'=>['market_region'=>'新的市场']]);yes($direct['configuration']['plan']===null&&$direct['configuration']['prompt']!=='','An edited advanced description can use the native direct-script workflow without a fabricated AI plan');
 yes(T::detail($target,$advancedBuiltin['id'],true)['builtin_version']===2,'Advanced direct-script edit updates the portable definition');
 $adminImage=Db::name('file')->insertGetId(['source'=>0,'source_id'=>0,'type'=>10,'name'=>'shared admin image','uri'=>$uri,'storage_scope'=>'platform','storage_engine'=>$engine,'storage_domain'=>$domain,'create_time'=>time(),'update_time'=>time()]);
 $adminVideo=Db::name('file')->insertGetId(['source'=>0,'source_id'=>0,'type'=>20,'name'=>'shared admin preview','uri'=>$mediaUri,'storage_scope'=>'platform','storage_engine'=>$engine,'storage_domain'=>$domain,'create_time'=>time(),'update_time'=>time()]);
 $manualBody=$body;$manualBody['images']=[['file_id'=>$adminImage]];$manual=T::create(0,['title'=>'手动平台内置模板','configuration'=>$manualBody,'preview'=>['file_id'=>$adminVideo],'confirm_public_media'=>true]);
 yes($manual['is_builtin']&&$manual['tenant_id']===0,'Platform can manually author a portable builtin without a source user task');
 $manualCopy=T::remix($target,$user,$manual['id']);yes($manualCopy['body']['images'][0]['uri']===$uri,'Manually authored platform builtin reuses the approved public OSS object across tenants');


 });echo "PASS $checks platform builtin publication, cross-tenant remix, source updates and shared OSS protection checks\n";
}finally{Db::rollback();foreach(glob($dir.'/*') as $f)unlink($f);rmdir($dir);}
