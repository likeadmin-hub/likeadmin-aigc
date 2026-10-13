<?php
// Local rollback-only configuration fixtures. No media uploads or provider calls.
require dirname(__DIR__,2).'/vendor/autoload.php';(new think\App())->initialize();
use think\facade\Db;
use app\common\service\app\AppDisplayConfigService as Display;
set_exception_handler(static function(Throwable $e){fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);});
function check($ok,$message){if(!$ok)throw new RuntimeException($message);}
$db=config('database.connections.mysql');check(PHP_SAPI==='cli'&&$db['hostname']==='127.0.0.1'&&in_array($db['database'],['x_cn','z_cn'],true),'Local rollback test only');
Db::startTrans();try{
 $tenant=1999999904;$app='aigc_fitting';
 $saved=Display::save($tenant,$app,['title'=>'试衣展示','cover_uri'=>'https://example.test/cover.jpg','skill_preview_images'=>['https://example.test/image.jpg'],'skill_preview_videos'=>['https://example.test/demo.mp4'],'extra'=>['legacy'=>'keep','private_test'=>'do-not-expose'],'is_recommend'=>1]);
 check(count($saved['skill_preview_images'])===1&&count($saved['skill_preview_videos'])===1,'Preview media was not saved');
 Display::save($tenant,$app,['description'=>'改描述']);$saved=Display::detail($tenant,$app);
 check($saved['extra']['legacy']==='keep'&&(int)$saved['is_recommend']===1&&count($saved['skill_preview_image_urls'])===1,'Existing display settings or omitted media lost');
 $public=Display::skillPreview($tenant,$app);check($public['cover_url']==='https://example.test/cover.jpg'&&$public['skill_preview_video_urls']===['https://example.test/demo.mp4'],'Public media missing');
 check(!isset($public['extra'])&&!str_contains(json_encode($public),'do-not-expose'),'Private display extras exposed');
 check(Display::skillPreview($tenant+1,$app)['skill_preview_video_urls']===[],'Tenant preview isolation failed');
 foreach([['javascript:alert(1)'],['data:image/png;base64,a'],['//example.test/a.jpg'],['../private.jpg'],['file:///tmp/a.jpg'],array_fill(0,6,'uploads/test.png'),'invalid'] as $bad){
  try{Display::save($tenant,$app,['skill_preview_images'=>$bad]);throw new LogicException('Invalid media accepted');}catch(InvalidArgumentException $e){}
 }
 Display::save($tenant,$app,['skill_preview_images'=>[],'skill_preview_videos'=>[]]);$saved=Display::detail($tenant,$app);
 check($saved['skill_preview_images']===[]&&$saved['skill_preview_video_urls']===[]&&$saved['extra']['legacy']==='keep','Clear media lost unrelated config');
 echo "Agent skill display passed: media roundtrip, old-field preservation, public whitelist, tenant isolation and invalid URLs; rolled back\n";
}finally{Db::rollback();}
