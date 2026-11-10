<?php
require dirname(__DIR__).'/vendor/autoload.php';(new think\App())->initialize();
use app\common\service\app\aigc_product_video\{ProductVideoTemplates as T,ProductVideoService as S,ProductVideoJobs as J,ProductVideoPolicy as P};
use think\facade\Db;
if(getenv('PRODUCT_VIDEO_LOCAL_TEST')!=='x_cn'||config('database.connections.mysql.database')!=='x_cn')throw new RuntimeException('Explicit local opt-in required');
set_exception_handler(static function(Throwable $e){fwrite(STDERR,'FAIL '.$e->getMessage().' at '.$e->getFile().':'.$e->getLine().PHP_EOL);exit(1);});
$n=0;function check(bool $ok,string $message){global $n;$n++;if(!$ok)throw new RuntimeException($message);}function rejectAdmin(callable $call,string $message){try{$call();}catch(InvalidArgumentException){check(true,$message);return;}throw new RuntimeException($message);}
$dir=public_path().'uploads/pv_admin_'.bin2hex(random_bytes(6));mkdir($dir,0700,true);Db::startTrans();
try{
 $uri='uploads/'.basename($dir).'/product.png';file_put_contents($dir.'/product.png',base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII='));
 $metadata=['tenant_id'=>1,'source'=>0,'source_id'=>0,'storage_scope'=>'platform','storage_engine'=>'local','storage_domain'=>'http://localhost','create_time'=>time(),'update_time'=>time(),'delete_time'=>0];
 $file=Db::name('tenant_file')->insertGetId(['type'=>10,'name'=>'product','uri'=>$uri]+$metadata);$video=Db::name('tenant_file')->insertGetId(['type'=>20,'name'=>'preview','uri'=>'uploads/pv_admin_preview.mp4']+$metadata);
 $m=S::models(1,'video')['models'][0];$m=S::models(1,'video',$m['id'])['models'][0];$spec=$m['specs'][0];
 $body=P::body(['images'=>[['file_id'=>$file,'uri'=>'spoof.png','storage_domain'=>'https://attacker.example.com']],'prompt'=>'展示 @图片1 的商品细节','product_info'=>'手动填写的商品特征','market_region'=>'英国','language'=>'英语','video_selection'=>['model_id'=>$m['id'],'quality'=>$spec['quality'],'ratio'=>$spec['ratio'],'duration'=>$spec['duration']]]);
 $params=['title'=>'手动模板事务测试','configuration'=>$body,'preview'=>['file_id'=>$video]];
 $template=T::create(1,$params);check($template['source_job_id']===0&&!$template['is_builtin'],'Tenant can create a manual template without generating a job');
 check($template['configuration']['images'][0]['uri']===$uri&&$template['cover_url']==='http://localhost/'.$uri,'Admin media authority ignores spoofed URI and browser storage metadata');
 check($template['configuration']['images'][0]['mention_name']==='图片1','Manual authoring retains the canonical image mention identity');
 check($template['media_url']==='http://localhost/uploads/pv_admin_preview.mp4','Preview video uses managed storage coordinates');
 check($template['configuration']['market_region']==='英国'&&$template['configuration']['language']==='英语','Manual audience configuration saved');
 $copy=T::remix(1,1,$template['id']);check($copy['body']['images'][0]['uri']===$uri&&$copy['body']['prompt']===$body['prompt'],'Manual admin template grants authorized image references to the user draft');
 rejectAdmin(fn()=>T::create(2,$params),'Admin media is isolated by tenant');rejectAdmin(fn()=>T::create(1,['title'=>'bad','configuration'=>array_replace($body,['images'=>[['uri'=>$uri]]]),'preview'=>['file_id'=>$video]]),'Unverified raw URLs are rejected');
 $bad=$params;$bad['preview']=['file_id'=>$file];rejectAdmin(fn()=>T::create(1,$bad),'Images cannot be used as preview videos');
 $insert=function($status,$action='video')use($copy,$body){return J::table()->insertGetId(['tenant_id'=>1,'user_id'=>1,'project_id'=>$copy['id'],'project_revision'=>1,'action'=>$action,'status'=>$status,'idempotency_key'=>'admin_'.bin2hex(random_bytes(12)),'input_hash'=>str_repeat('a',64),'request_json'=>P::encode(['snapshot'=>$body,'quote'=>['model_name'=>'测试视频模型']]),'receipt_json'=>'{}','result_json'=>P::encode(['videos'=>[['video_uri'=>'uploads/pv_admin_preview.mp4','storage_scope'=>'platform','storage_engine'=>'local','storage_domain'=>'http://localhost']]]),'create_time'=>time(),'update_time'=>time()]);};
 $success=$insert('success');$queued=$insert('queued');$unknown=$insert('unknown');$helper=$insert('success','product');
 $provider='real-provider-'.bin2hex(random_bytes(8));$consumption=Db::name('ai_consumption_log')->insertGetId(['consume_no'=>'pv_admin_'.bin2hex(random_bytes(8)),'app_task_id'=>900001,'tenant_id'=>1,'user_id'=>1,'app_code'=>P::APP,'upstream_task_id'=>$provider,'upstream_request_id'=>'request-fixture','model_code'=>'native-video-model','create_time'=>time(),'update_time'=>time()]);J::table()->where('id',$success)->update(['app_task_id'=>900001,'consumption_id'=>$consumption]);
 $detail=T::adminDetail(1,$success);check($detail['app_task_id']===900001&&$detail['upstream_task_id']===$provider&&$detail['id']===$success,'Record, system and upstream task identities are separately and truthfully displayed');check($detail['model_name']==='测试视频模型','Frozen model label retained');
 foreach([(string)$success,'900001',$provider,'展示 @图片1'] as $keyword){$list=J::lists(1,0,['keyword'=>$keyword],true);check(in_array($success,array_column($list['lists'],'id')),'Search matches actual task identity or description');}
 check(J::lists(2,0,['keyword'=>$provider],true)['count']===0,'Task search preserves tenant boundary');
 $list=J::lists(1,0,['keyword'=>$provider,'status'=>'success','mode'=>'normal','user_id'=>1,'start_time'=>time()-60,'end_time'=>time()+60],true);check($list['count']===1&&$list['lists'][0]['result']['videos'][0]['video_url']!=='','Filtered list includes a managed video preview and creation settings');
 rejectAdmin(fn()=>J::adminDelete(1,$queued),'Queued tasks cannot be removed');rejectAdmin(fn()=>J::adminDelete(1,$unknown),'Uncertain tasks cannot be removed');rejectAdmin(fn()=>J::adminDelete(2,$success),'Deletion preserves tenant boundary');rejectAdmin(fn()=>J::adminDelete(1,$helper),'Helpers cannot be managed as video records');
 J::adminDelete(0,$success);check(J::lists(1,0,['keyword'=>$provider],true)['count']===0,'Platform may soft-delete a completed video record');check(Db::name('ai_consumption_log')->where('id',$consumption)->count()===1,'Task deletion retains billing and upstream audit history');check(is_file($dir.'/product.png')&&T::detail(1,$template['id'])['id']===$template['id'],'Task deletion preserves material files and authored templates');
 echo "PASS $n manual template authoring, managed admin media, task identity/search/preview and safe deletion checks\n";
}finally{Db::rollback();foreach(glob($dir.'/*') as $f)unlink($f);rmdir($dir);}
