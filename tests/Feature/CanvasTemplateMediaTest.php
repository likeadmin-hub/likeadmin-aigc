<?php
require dirname(__DIR__,2).'/vendor/autoload.php'; (new think\App())->initialize();
use app\common\service\app\aigc_canvas\{CanvasTemplateMedia as Media,CanvasTemplateMediaStorage as Storage,CanvasTemplateService as Templates,AigcCanvasService as Canvas};
use app\common\service\CanvasPortalService as Portal;
use app\common\model\file\TenantFile;
use think\facade\Db;
$n=0;
$check=static function($ok,$why)use(&$n){if(!$ok)throw new RuntimeException($why);$n++;};
$copy=static fn($url,$type,$row)=>['url'=>'https://cdn.example.test/'.basename($url),'file'=>['uri'=>'templates/'.basename($url),'storage_scope'=>'tenant','storage_engine'=>'local','storage_domain'=>'','checksum'=>str_repeat('a',64)],'mimeType'=>$type.'/test'];
$raw=['mediaItems'=>[['id'=>'a','url'=>'https://source.test/a.png'],['id'=>'b','url'=>'https://source.test/b.png']],'activeMediaId'=>'b','taskId'=>'secret','referenceVideos'=>[['url'=>'https://source.test/ref.mp4','asset_id'=>99]],'referenceAudios'=>[['url'=>'https://source.test/ref.mp3']],'mentions'=>[['id'=>'asset-99','source'=>'asset','asset_id'=>99,'name'=>'示例','type'=>'image','url'=>'https://source.test/ref.png']]];
$metadata=Media::capture('image',$raw,$copy);
$check(count($metadata['mediaItems'])===2 && str_ends_with($metadata['image'],'b.png'),'Collection active item lost');
$check(count($metadata['referenceVideos'])===1 && count($metadata['referenceAudios'])===1 && count($metadata['mentions'])===1,'External references lost');
$check(!isset($metadata['taskId'],$metadata['mentions'][0]['asset_id'],$metadata['referenceVideos'][0]['asset_id']),'Source ownership leaked');
$check(Media::capture('image',['mediaItems'=>[],'url'=>'https://old.test/a.png'],$copy)['mediaItems']===[],'Explicit empty collection resurrected');
$hydrated=Media::hydrate($metadata,static fn($f)=>'https://fresh.test/'.$f['uri']);
$check(str_starts_with($hydrated['image'],'https://fresh.test/'),'Signed URLs not refreshed');
$graph=['nodes'=>[['id'=>'n','type'=>'image','metadata'=>$metadata]],'edges'=>[]];
$a=Templates::instantiate($graph);$b=Templates::instantiate($graph);
$check($a['nodes'][0]['metadata']['activeMediaId']!==$b['nodes'][0]['metadata']['activeMediaId'],'Media identity not regenerated');
$db=config('database.connections.mysql');
$check(PHP_SAPI==='cli' && $db['hostname']==='127.0.0.1' && in_array($db['database'],['x_cn','z_cn'],true),'Local rollback only');
request()->tenantId=1;request()->source=app\common\enum\AdminTerminalEnum::TENANT;
$png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aDVkAAAAASUVORK5CYII=');
$relative='uploads/canvas-template-test-'.bin2hex(random_bytes(5)).'.png';$path=public_path().$relative;
Db::startTrans();
try{
 file_put_contents($path,$png);
 TenantFile::create(['tenant_id'=>1,'cid'=>0,'type'=>10,'name'=>'Template fixture','uri'=>$relative,'storage_scope'=>'tenant','storage_engine'=>'local','storage_domain'=>'','source'=>1,'source_id'=>1999999760,'create_time'=>time()]);
 $source=Canvas::createProject(1,1999999760,['name'=>'Media fixture','nodes'=>[['id'=>'img','type'=>'image','title'=>'示例','position'=>['x'=>0,'y'=>0],'metadata'=>['image'=>$relative,'url'=>$relative,'status'=>'success','taskId'=>'old-task','referenceImages'=>[['url'=>$relative,'role'=>'first_frame_image']]]]],'edges'=>[]]);
 $state=Portal::sectionEditor('cards');$card=['id'=>'test-retained-media','section'=>'template','title'=>'媒体验收','enabled'=>1,'refresh_workflow'=>true,'source_project_id'=>$source['id'],'source_page_id'=>'canvas_1'];
 Portal::saveSection('cards',['revision'=>$state['revision'],'config'=>['cards'=>[$card]]]);
 unlink($path);
 $project=Templates::apply(1,1999999761,['template_id'=>$card['id'],'request_id'=>'media-fixture-request-0001']);
 $m=$project['nodes'][0]['metadata'];
 $check(count($m['mediaItems'])===1 && $m['url']!==$relative && $m['status']==='success','Published media not instantiated');
 $check(!isset($m['taskId']) && isset($m['referenceImages'][0]['template_file']),'Task copied or reference lost');
 $wire=app\common\service\open_platform\OpenSafeHttp::get($m['url'],20971520,15);
 $check(hash('sha256',$wire['body'])===hash('sha256',$png),'Template does not own a durable byte-identical copy');
 $reload=Canvas::projectDetail(1,1999999761,$project['id']);
 $check($reload['nodes'][0]['metadata']['mediaItems'][0]['url']===$m['url'],'Reload lost media');
 try{(new Storage(2))->copy($relative,'image');throw new RuntimeException('Cross-tenant media accepted');}catch(InvalidArgumentException $e){$check(true,'isolated');}
 try{(new Storage(1))->copy('http://127.0.0.1/private','image');throw new RuntimeException('Private network accepted');}catch(InvalidArgumentException $e){$check(true,'isolated');}
 echo "PASS $n collection, reference, identity, independent storage, reload and tenant-isolation checks; database rolled back\n";
}finally{Db::rollback();if(is_file($path))unlink($path);}
