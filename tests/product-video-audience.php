<?php
/** Local-only input -> analysis result -> editable fields -> video payload, without provider calls. */
require dirname(__DIR__).'/vendor/autoload.php';(new think\App())->initialize();
set_exception_handler(static function(Throwable $e):void{fwrite(STDERR,'FAIL '.get_class($e).': '.$e->getMessage().PHP_EOL);exit(1);});
use app\common\service\app\aigc_product_video\{ProductVideoService as S,ProductVideoJobs as J,ProductVideoPolicy as P,ProductVideoWorker as W};
use think\facade\Db;
if(getenv('PRODUCT_VIDEO_LOCAL_TEST')!=='x_cn'||config('database.connections.mysql.database')!=='x_cn')throw new RuntimeException('Explicit local test opt-in required');
$count=0;function audienceCheck(bool $ok,string $message):void{global $count;$count++;if(!$ok)throw new RuntimeException($message);}
$dir=public_path().'uploads/product_video_audience_test_'.bin2hex(random_bytes(5));mkdir($dir,0700,true);Db::startTrans();
try{
 $uri='uploads/'.basename($dir).'/sample.png';file_put_contents($dir.'/sample.png',base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII='));
 Db::name('tenant_file')->insert(['tenant_id'=>1,'source'=>1,'source_id'=>1,'type'=>10,'name'=>'audience-fixture','uri'=>$uri,'storage_scope'=>'tenant','storage_engine'=>'local','storage_domain'=>'','create_time'=>time(),'update_time'=>time(),'delete_time'=>0]);
 $summary=S::models(1,'video')['models'][0];$model=S::models(1,'video',$summary['id'])['models'][0];$spec=array_values(array_filter($model['specs'],static fn($s)=>(int)$s['duration']>=2))[0];
 foreach([['美国','英语'],['日本','日语']] as [$region,$language]){
  $body=P::body(['mode'=>'advanced','images'=>[['uri'=>$uri]],'market_region'=>$region,'language'=>$language,'product_info'=>'原先填写的资料','prompt'=>'原先填写的描述','video_selection'=>['model_id'=>$model['id'],'quality'=>$spec['quality'],'ratio'=>$spec['ratio'],'duration'=>$spec['duration']]]);
  $project=S::save(1,1,['body'=>$body]);$prepared=J::prepare(1,1,['project_id'=>$project['id'],'revision'=>1,'action'=>'analysis']);$analysis=$prepared['request']['plan_request'];$content=P::json($analysis['content']);
  audienceCheck($content['market_region']===$region&&$content['language']===$language,'Both audience choices enter the real analysis request');
  audienceCheck((int)$content['duration']===(int)$spec['duration'],'Analysis receives the current generation duration');
  audienceCheck(str_contains($analysis['system'],'language')&&str_contains($analysis['system'],'market_region'),'Analysis instructions use both audience values');
  $reply=['product_profile'=>['product_name'=>'测试随行杯','colors'=>'白色','structure'=>'圆柱杯身'],'product_ideas'=>[],'summary'=>'先全貌后细节','creative_brief'=>['subject'=>'图片中的随行杯','style'=>'清晰自然'],'shots'=>[['start'=>0,'end'=>1,'action'=>'展示杯身全貌'],['start'=>1,'end'=>(int)$spec['duration'],'action'=>'展示杯盖细节']]];
  $result=W::parse(['action'=>'plan','request_json'=>P::encode($analysis)],['content'=>P::encode($reply)]);
  $id=J::table()->insertGetId(['tenant_id'=>1,'user_id'=>1,'project_id'=>$project['id'],'project_revision'=>1,'action'=>'analysis','status'=>'success','idempotency_key'=>'audience_'.bin2hex(random_bytes(12)),'input_hash'=>str_repeat('a',64),'request_json'=>P::encode($prepared['request']),'receipt_json'=>'{}','result_json'=>P::encode($result),'create_time'=>time(),'update_time'=>time()]);
  $filled=S::adopt(1,1,['job_id'=>$id,'revision'=>1]);
  audienceCheck($filled['body']['product_info']===$result['product_info'],'Completed analysis fills product information directly');
  audienceCheck($filled['body']['prompt']===$result['video_prompt']&&$filled['body']['plan']['data']['video_prompt']===$result['video_prompt'],'Completed analysis fills the actual description and its plan from one result');
  audienceCheck($filled['body']['market_region']===$region&&$filled['body']['language']===$language,'Autofill preserves chosen audience');
  $video=J::prepare(1,1,['project_id'=>$project['id'],'revision'=>2,'action'=>'video']);$method=new ReflectionMethod(W::class,'mediaRequest');$payload=$method->invoke(null,'video',$video['request']);
  audienceCheck(str_contains($payload['prompt'],'表达语言：'.$language)&&str_contains($payload['prompt'],'销售地区：'.$region),'Both audience choices reach the final provider-facing video prompt');
  audienceCheck(str_contains($payload['prompt'],$result['video_prompt']),'Final video uses the exact visible description');
  foreach(['market_region'=>'其他地区','language'=>'其他语言'] as $key=>$value){$changed=$filled['body'];$changed[$key]=$value;audienceCheck(P::planKey($changed)!==P::planKey($filled['body']),'Audience edits invalidate old analysis');try{P::prompt($changed);throw new RuntimeException('Stale analysis was accepted');}catch(InvalidArgumentException){audienceCheck(true,'Stale audience cannot submit old analysis');}}
 }
 echo "PASS $count audience, direct autofill and final video payload checks; no provider calls\n";
}finally{Db::rollback();foreach(glob($dir.'/*') as $file)unlink($file);rmdir($dir);}
