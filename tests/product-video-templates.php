<?php
// Local transactional integration: no model submissions or changes outside rollback fixtures.
require dirname(__DIR__).'/vendor/autoload.php';
$app=new think\App();$app->initialize();
set_exception_handler(static function(Throwable $e):void{fwrite(STDERR,'FAIL '.get_class($e).': '.$e->getMessage().PHP_EOL);exit(1);});
use app\common\service\app\aigc_product_video\{ProductVideoService as S,ProductVideoJobs as J,ProductVideoPolicy as P,ProductVideoTemplates as T};
use app\common\model\app\AppCase;
use think\facade\Db;
if(getenv('PRODUCT_VIDEO_LOCAL_TEST')!=='x_cn'||config('database.connections.mysql.database')!=='x_cn')throw new RuntimeException('Explicit local test opt-in required');
$checks=0;function yes(bool $ok,string $why):void{global $checks;$checks++;if(!$ok)throw new RuntimeException($why);}
function reject(callable $call,string $why):void{try{$call();}catch(InvalidArgumentException){yes(true,$why);return;}throw new RuntimeException($why);}
$dir=public_path().'uploads/product_video_template_test_'.bin2hex(random_bytes(6));mkdir($dir,0700,true);
Db::startTrans();
try{
 $uri='uploads/'.basename($dir).'/item.png';file_put_contents($dir.'/item.png',base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII='));
 Db::name('tenant_file')->insert(['tenant_id'=>1,'source_id'=>1,'source'=>1,'type'=>10,'name'=>'template-fixture','uri'=>$uri,'storage_scope'=>'tenant','storage_engine'=>'local','storage_domain'=>'','create_time'=>time(),'update_time'=>time()]);
 $model=S::models(1,'video')['models'][0];$m=S::models(1,'video',$model['id'])['models'][0];$s=$m['specs'][0];
 $body=P::body(['images'=>[['uri'=>$uri,'reference_slot_id'=>'template_image_1','mention_name'=>'图片1']],'product_url'=>'https://www.amazon.com/dp/B012345678?tag=ignored','product_info'=>'自己的商品资料','prompt'=>'展示 @图片1 的轮廓及细节','market_region'=>'英国','language'=>'英语','audio'=>null,'video_selection'=>['model_id'=>$model['id'],'quality'=>$s['quality'],'ratio'=>$s['ratio'],'duration'=>$s['duration']]]);
 $project=S::save(1,1,['body'=>$body]);$request=['snapshot'=>$project['body'],'selection'=>['model_id'=>$model['id']],'provider_api_key'=>'must-not-leak'];
 $insert=fn($action,$status)=>J::table()->insertGetId(['tenant_id'=>1,'user_id'=>1,'project_id'=>$project['id'],'project_revision'=>1,'action'=>$action,'status'=>$status,'idempotency_key'=>'pv_template_'.bin2hex(random_bytes(12)),'input_hash'=>str_repeat('b',64),'request_json'=>P::encode($request),'receipt_json'=>'{}','result_json'=>P::encode(['videos'=>[['video_uri'=>'uploads/template-fixture.mp4','storage_scope'=>'tenant','storage_engine'=>'local','storage_domain'=>'']]]),'create_time'=>time(),'update_time'=>time()]);
 $id=$insert('video','success');$helper=$insert('product','success');$pending=$insert('video','queued');
 reject(fn()=>T::fromJob(1,$helper,[]),'Helpers cannot become templates');reject(fn()=>T::fromJob(1,$pending,[]),'Queued video cannot become a template');reject(fn()=>T::fromJob(2,$id,[]),'Job tenant isolation');
 $admin=J::lists(1,0,['group'=>'helper','page_size'=>50],true);yes(count(array_diff(array_column($admin['lists'],'action'),['video']))===0,'Admin cannot opt into helper jobs');yes(in_array($helper,array_column(J::lists(1,1,['group'=>'helper','page_size'=>50])['lists'],'id')),'User helper polling remains available');
 $detail=T::adminDetail(1,$id);yes($detail['configuration']['prompt']===$body['prompt'],'Details use frozen submission');yes(!str_contains(P::encode($detail),'must-not-leak'),'No provider credentials in admin detail');
 S::save(1,1,['id'=>$project['id'],'revision'=>1,'body'=>array_replace($body,['prompt'=>'后来修改的表单'])]);
 $case=T::fromJob(1,$id,['title'=>'本地事务灵感测试']);yes($case['configuration']['prompt']===$body['prompt'],'Publication never reads mutable project');yes($case['configuration']['product_info']===$body['product_info'],'Product facts roundtrip');yes(!array_key_exists('product_url',$case['configuration']),'Imported source link is not a template configuration');yes($case['configuration']['market_region']==='英国'&&$case['configuration']['language']==='英语','Audience roundtrip');
 yes($case['configuration']['images'][0]['reference_slot_id']==='template_image_1','Stable material slots');yes($case['configuration']['images'][0]['mention_name']==='图片1','Mention identity retained');yes($case['configuration']['video_selection']===$body['video_selection'],'Exact model specification roundtrip');yes($case['media_url']!==''&&$case['cover_url']!=='','Managed gallery URLs');$shared=\app\common\service\app\AppCaseService::detail(1,P::APP,$case['id']);yes($shared['media_url']===$case['media_url']&&count($shared['reference_image_urls'])===1,'Shared gallery preserves product-specific storage metadata');
 yes(T::fromJob(1,$id,['title'=>'本地事务灵感测试'])['id']===$case['id'],'Setting same record twice reuses template');reject(fn()=>T::detail(2,$case['id'],true),'Public template tenant isolation');reject(fn()=>T::save(2,['id'=>$case['id']]),'Admin template tenant isolation');
 $snapshot=AppCase::where('id',$case['id'])->find()->toArray()['config_json'];yes(!str_contains(P::encode($snapshot),'must-not-leak'),'Stored case has no receipt credentials');yes(!array_key_exists('product_url',$snapshot['generation_request']['advanced_params']['product_video']),'New stored templates omit source links');
 // Previously published templates may contain a link. Reads and migration must remove it too.
 $legacy=$snapshot;$legacy['generation_request']['advanced_params']['product_video']['product_url']='https://www.amazon.com/dp/B012345678';
 Db::name('app_case')->where('id',$case['id'])->update(['config_json'=>P::encode($legacy)]);
 yes(!array_key_exists('product_url',T::detail(1,$case['id'])['configuration']),'Legacy links are omitted even before migration');
 for($i=0;$i<2;$i++)\app\common\service\database\SqlMigrationExecutor::execute(file_get_contents(dirname(__DIR__).'/app/apps/aigc_product_video/migrations/20261110_zzzz_template_links.sql'),(string)config('database.connections.mysql.prefix'));
 $clean=AppCase::where('id',$case['id'])->find()->toArray()['config_json'];yes(!array_key_exists('product_url',$clean['generation_request']['advanced_params']['product_video']),'Repeatable migration cleans stored template links');yes($clean['generation_request']['advanced_params']['product_video']['prompt']===$body['prompt'],'Migration preserves template creative content');
 $before=J::table()->count();$same=T::remix(1,1,$case['id']);yes($same['id']!==$project['id']&&$same['revision']===1,'Remix creates new independent draft');yes($same['body']['prompt']===$body['prompt']&&P::hash($same['body']['video_selection'])===P::hash($body['video_selection']),'Complete remix configuration');yes(J::table()->count()===$before,'Remix never submits a job');yes($same['body']['product_url']==='','Remix clears the product link');yes(P::json(S::owned(1,1,$project['id'])['body_json'])['product_url']===$body['product_url'],'Original import draft retains its source link');yes(S::owned(1,1,$project['id'])['revision']===2,'Original draft untouched');
 // Remove original-user ownership temporarily to exercise trusted published-media grant.
 Db::name('tenant_file')->where(['tenant_id'=>1,'uri'=>$uri])->update(['source_id'=>987654]);$grant=T::remix(1,1,$case['id']);yes($grant['body']['images'][0]['uri']===$uri,'Published same-tenant material can be reused by another user');yes(Db::name('tenant_file')->where(['tenant_id'=>1,'uri'=>$uri,'source_id'=>1])->count()===1,'Owned file projection created once');T::remix(1,1,$case['id']);yes(Db::name('tenant_file')->where(['tenant_id'=>1,'uri'=>$uri,'source_id'=>1])->count()===1,'Material grant idempotent');
 reject(fn()=>T::save(1,['id'=>$case['id'],'configuration'=>['images'=>[['uri'=>'uploads/other-user.png']]]]),'Admin cannot inject foreign files');
 $updated=T::save(1,['id'=>$case['id'],'title'=>'编辑后的灵感','sort'=>3,'configuration'=>['prompt'=>'新的镜头描述','market_region'=>'日本','language'=>'日语']]);yes($updated['configuration']['prompt']==='新的镜头描述'&&$updated['sort']===3,'Editable template config and sort');
 // Explicit tail roles cannot be silently promoted to a first frame when editing.
 $frameBody=T::snapshot(array_replace($body,['reference_mode'=>'start_end','images'=>[['uri'=>$uri,'role'=>'last_frame_image']]]));yes($frameBody['images'][0]['role']==='last_frame_image','Snapshot retains explicit frame identity');
 reject(fn()=>P::frames($frameBody['images']),'Tail-only snapshot remains invalid instead of promoting its role');
 // Advanced plans preserve their timed script and remain immediately usable after replay.
 $advanced=$project['body'];$advanced['mode']='advanced';$advanced['plan']=['data'=>['summary'=>'商品镜头','duration'=>(int)$s['duration'],'shots'=>[['start'=>0,'end'=>1,'description'=>'完整轮廓'],['start'=>1,'end'=>(int)$s['duration'],'description'=>'细节展示']],'video_prompt'=>'按所选时长展示商品'],'input_key'=>''];$advanced['plan']['input_key']=P::planKey($advanced);
 $advanced['prompt']='按所选时长展示商品';$originalJob=J::owned(1,1,$id);J::table()->where('id',$id)->update(['request_json'=>P::encode(['snapshot'=>$advanced])]);
 $advancedCase=T::fromJob(1,$id,['title'=>'高级事务模板']);$advancedDraft=T::remix(1,1,$advancedCase['id']);yes($advancedDraft['plan_fresh'],'Advanced plan remains fresh across case storage and file ownership normalization');yes(str_contains(P::prompt($advancedDraft['body']),'按所选时长展示商品'),'Advanced replay uses original timed script');
 J::table()->where('id',$id)->update(['request_json'=>$originalJob['request_json']]);T::fromJob(1,$id,['title'=>'本地事务灵感测试']);
 T::status(1,$case['id'],0);reject(fn()=>T::remix(1,1,$case['id']),'Disabled template cannot remix');yes(!in_array($case['id'],array_column(T::lists(1,[],true)['lists'],'id')),'Disabled template hidden in gallery');T::status(1,$case['id'],1);
 $bad=$updated['configuration'];$bad['video_selection']['model_id']='retired-model';reject(fn()=>T::save(1,['id'=>$case['id'],'configuration'=>$bad]),'Unavailable model rejected without silent fallback');
 Db::name('tenant_file')->where(['tenant_id'=>1,'uri'=>$uri])->update(['delete_time'=>time()]);reject(fn()=>T::remix(1,1,$case['id']),'Unavailable source image rejects remix');
 T::delete(1,$case['id']);reject(fn()=>T::detail(1,$case['id'],true),'Deleted template hidden');yes(J::owned(1,1,$id)['status']==='success','Template deletion preserves source video');
 echo "PASS $checks product-video inspiration lifecycle, immutable configuration and isolation checks\n";
}finally{Db::rollback();foreach(glob($dir.'/*') as $file)unlink($file);rmdir($dir);}
