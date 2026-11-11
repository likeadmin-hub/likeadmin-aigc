<?php
require dirname(__DIR__).'/vendor/autoload.php';
use app\common\service\app\aigc_story_commerce\{StoryCommercePolicy as P,StoryCommerceWorker as W,StoryCommercePrompts};
function scCheck(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
function scReject(callable $f,string $why):void{try{$f();}catch(Throwable){return;}throw new RuntimeException($why);}
$b=P::body(['images'=>[['uri'=>'uploads/product.png','reference_slot_id'=>'product_1']], 'product_info'=>'带分隔位的收纳包','requirements'=>'出门前找钥匙的误会','style_id'=>'1','video_selection'=>['model_id'=>'native','quality'=>'720p','ratio'=>'9:16','duration'=>10],
    'genre_snapshot'=>['name'=>'喜剧短剧','instruction'=>'误会、行动、化解'],'style_snapshot'=>['name'=>'都市写实','prompt'=>'自然光，生活环境']]);
$script=['summary'=>'找钥匙的误会','characters'=>[['name'=>'同伴','appearance'=>'同一件灰色外套']], 'shots'=>[['start'=>0,'end'=>4,'action'=>'出门前翻找钥匙','dialogue'=>'','product_point'=>'','source_slots'=>[]],['start'=>4,'end'=>10,'action'=>'打开收纳包分隔位取出钥匙','dialogue'=>'原来在这里','product_point'=>'已确认的分隔位','source_slots'=>['product_1']]],'ending'=>'带好物品出门'];
$b['script']=P::script($script,10,$b);$b['script_input_key']=P::inputKey($b);$prompt=P::prompt($b);
foreach(['喜剧短剧','都市写实','原来在这里','4-10秒','带分隔位的收纳包'] as $text)scCheck(str_contains($prompt,$text),'Final prompt lost '.$text);
foreach(['story_type','style_id','requirements','language','market_region','audio','product_info'] as $key){$changed=$b;$changed[$key]=$key==='audio'?false:'changed';scReject(fn()=>P::prompt($changed),'Changed input must invalidate script: '.$key);}
$changed=$b;$changed['video_selection']['duration']=15;scReject(fn()=>P::prompt($changed),'Duration mismatch must block');
$changed=$script;$changed['shots'][1]['start']=5;scReject(fn()=>P::script($changed,10,$b),'Gaps must be rejected');
$changed=$script;$changed['shots'][1]['source_slots']=['removed'];scReject(fn()=>P::script($changed,10,$b),'Deleted references must be rejected');
$silent=$b;$silent['audio']=false;scReject(fn()=>P::script($script,10,$silent),'Speech must not silently survive muted mode');
$signed=$b;$signed['images'][0]['url']='https://example.test/signed?expires=2';scCheck(P::inputKey($signed)===P::inputKey($b),'Signed URL changes must not stale script');
scCheck(!array_key_exists('mode',$b),'Unified workflow must not keep normal/advanced modes');
$row=['action'=>'analysis','request_json'=>P::encode(['snapshot'=>$b])];$r=W::parse($row,['content'=>P::encode(['product_info'=>'收纳包','script'=>$script])]);scCheck($r['script']['shots'][1]['dialogue']==='原来在这里','Analysis parser lost editable dialogue');
scReject(fn()=>W::parse($row,['content'=>'not json']),'Malformed result must not be accepted');
$root=dirname(__DIR__);$app=$root.'/app/apps/'.P::APP;$manifest=json_decode(file_get_contents($app.'/manifest.json'),true,512,JSON_THROW_ON_ERROR);scCheck(count($manifest['frontend_entries'])===4,'All implemented terminals must be declared');
foreach(json_decode(file_get_contents($app.'/api_schema.json'),true)['apis'] as $api){preg_match('/^app\.aigc_story_commerce\.([a-z_]+)\/([A-Za-z]+)$/',$api['api_path'],$match);scCheck(count($match)===3,'Route scope');$terminal=['user'=>'api','tenant_admin'=>'tenantapi','platform_admin'=>'platformapi'][$api['scene']];$class='app\\'.$terminal.'\\controller\\app\\'.P::APP.'\\'.ucfirst($match[1]).'Controller';scCheck(method_exists($class,$match[2]),'Declared method missing: '.$class.'::'.$match[2]);}
foreach(['config','project','job','quote'] as $table)scCheck(str_contains(file_get_contents($root.'/public/install/db/like.sql'),'la_'.P::APP.'_'.$table),'Missing fresh-install table '.$table);
scCheck(file_get_contents($root.'/upgrade/upgrade_20261111_aigc_story_commerce.sql')===file_get_contents($root.'/public/upgrade/upgrade_20261111_aigc_story_commerce.sql'),'Upgrade source drift');
scCheck(str_contains(StoryCommercePrompts::system(),'visual_style')&&str_contains(StoryCommercePrompts::system(),'genre'),'Analysis must receive both real creative controls');
$method=new ReflectionMethod(app\common\service\power\MarketVideoRuntimeService::class,'requiresDurableResultStorage');
foreach(['aigc_story_commerce','aigc_product_video','aigc_motion_sync','aigc_short_drama'] as $code)scCheck($method->invoke(null,$code),'Final works must enter durable storage: '.$code);
$mentions=$script;$mentions['shots'][0]['action']='展示 @图片1';scCheck(P::script($mentions,10,$b)['shots'][0]['source_slots']===['product_1'],'Text mentions must bind to real stable slots');
$mentions['shots'][0]['action']='展示 @图片99';scReject(fn()=>P::script($mentions,10,$b),'Unknown image ordinal must be rejected');
$plain=$b;$plain['script']='开门时找不到钥匙，拿起 @图片1 的商品后发现钥匙就在旁边，自然收尾。';
scCheck(P::scriptValue($plain['script'],10,$plain)===$plain['script'],'Text scripts must remain user-editable');
scCheck(str_contains(P::prompt($plain),$plain['script']),'Generation must use the exact confirmed text script');
scCheck(P::body($plain)['script']===$plain['script'],'Persisted text must survive normalization');
scReject(fn()=>P::scriptValue('展示 @图片9',10,$plain),'Text scripts cannot reference absent images');
echo "PASS story script continuity, input freshness, silent mode, stable references, analysis parse, independent routes and installation source parity\n";
