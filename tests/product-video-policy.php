<?php
require dirname(__DIR__).'/vendor/autoload.php';
use app\common\service\app\aigc_product_video\{ProductVideoPolicy as P,ProductVideoWorker as W};
function checkProductPolicy(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);}
function rejectsProductPolicy(callable $call,string $message): void {try{$call();}catch(Throwable){return;}throw new RuntimeException($message);}
$plan=['summary'=>'展示杯身与使用场景','shots'=>[['start'=>0,'end'=>2,'description'=>'杯子完整外观'],['start'=>2,'end'=>5,'description'=>'材质特写']]];
checkProductPolicy(P::plan($plan,5)['duration']===5,'Valid continuous plan rejected');
rejectsProductPolicy(fn()=>P::plan($plan,6),'Duration mismatch must block generation');
$gap=$plan;$gap['shots'][1]['start']=3;rejectsProductPolicy(fn()=>P::plan($gap,5),'Gap must be rejected');
$body=P::body(['mode'=>'advanced','images'=>[['uri'=>'uploads/product.png','url'=>'https://example.test/a?sig=old']],'video_selection'=>['model_id'=>'video_1','quality'=>'720p','ratio'=>'9:16','duration'=>5]]);
$body['plan']=['input_key'=>P::planKey($body),'data'=>$plan];
checkProductPolicy(str_contains(P::prompt($body),'0-2秒'),'Plan prompt must include actual shot timings');
$changed=$body;$changed['video_selection']['ratio']='16:9';rejectsProductPolicy(fn()=>P::prompt($changed),'Ratio changes must invalidate plan');
$signed=$body;$signed['images'][0]['url']='https://example.test/a?sig=new';checkProductPolicy(P::planKey($signed)===P::planKey($body),'Signed URL rotation must not invalidate authored plan');
$changed=$body;$changed['images'][0]['uri']='uploads/other.png';checkProductPolicy(P::planKey($changed)!==P::planKey($body),'Product replacement must invalidate plan');
$request=['quote'=>['user_charge_points'=>5],'images'=>[['uri'=>'uploads/x.png','url'=>'https://test/a?expires=1']]];$new=$request;$new['images'][0]['url']='https://test/a?expires=2';checkProductPolicy(P::requestKey($request)===P::requestKey($new),'Ephemeral signatures must not change quotes');
$row=['action'=>'plan','request_json'=>P::encode(['duration'=>5])];checkProductPolicy(count(W::parse($row,['content'=>P::encode($plan)])['shots'])===2,'Plan receipt parser lost shots');
$row=['action'=>'reference','request_json'=>P::encode(['video'=>['duration'=>5]])];$result=W::parse($row,['content'=>P::encode($plan+['style'=>'平稳推近','audio'=>'轻音乐'])]);checkProductPolicy($result['style']==='平稳推近','Reference observations must survive');
$invalid=$plan;$invalid['shots'][1]['end']=9;rejectsProductPolicy(fn()=>W::parse($row,['content'=>P::encode($invalid)]),'Actual chapters must stay inside media duration');
$manifest=json_decode(file_get_contents(dirname(__DIR__).'/app/apps/aigc_product_video/manifest.json'),true,512,JSON_THROW_ON_ERROR);
checkProductPolicy($manifest['code']===P::APP && count($manifest['frontend_entries'])===3,'Independent application entries missing');
$schema=file_get_contents(dirname(__DIR__).'/app/apps/aigc_product_video/migrations/install.sql');$install=file_get_contents(dirname(__DIR__).'/public/install/db/like.sql');
foreach(['config','project','job','quote'] as $table)checkProductPolicy(str_contains($schema,'la_aigc_product_video_'.$table)&&str_contains($install,'la_aigc_product_video_'.$table),'Fresh/application schema parity missing');
echo "PASS product video plan, stale state, signed media, receipt and install contracts\n";
