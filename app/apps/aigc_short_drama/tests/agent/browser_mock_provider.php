<?php
declare(strict_types=1);
if (!defined('SHORT_DRAMA_BROWSER_MOCK_PROVIDER')) throw new RuntimeException('Mock provider requires local acceptance router');
use think\facade\Db;
use app\common\service\point\PointService;
final class BrowserMockProvider {
    public static function submit(string $type,int $tenant,int $user,array $input): array {
        if ($tenant!==94011 || $user!==95011) throw new RuntimeException('Mock provider outside fixture ownership');
        if (($input[$type==='text'?'model_code':'channel']??'')!=='local-acceptance-browser-mock') throw new RuntimeException('Only explicit synthetic model is allowed');
        return Db::transaction(function () use ($type,$tenant,$user): array {
        $receipt=Db::name('aigc_short_drama_test_provider_receipt')->insertGetId(['tenant_id'=>$tenant,'user_id'=>$user,'canvas_id'=>0,'intent_id'=>0,'create_time'=>time()]);
        PointService::consumeBusinessAmountsInCurrentTransaction($tenant,$user,1,2,'browser-mock-'.$receipt,'Local acceptance browser mock');
        if ($type==='text') return ['content'=>'Synthetic browser result','provider'=>'local-acceptance-mock'];
        $table=['image'=>'aigc_image','video'=>'aigc_video','audio'=>'aigc_music'][$type];
        $id=Db::name($table.'_task')->insertGetId(['tenant_id'=>$tenant,'user_id'=>$user,'status'=>'success']);
        $column=['image'=>'image_uri','video'=>'video_uri','audio'=>'audio_uri'][$type];
        Db::name($table.'_result')->insert(['tenant_id'=>$tenant,'user_id'=>$user,'task_id'=>$id,$column=>'uploads/fixtures/browser-'.$receipt,'storage_scope'=>'tenant','storage_engine'=>'local']);
        return ['id'=>$id,'status'=>'success'];
        });
    }
}
class BrowserMockText {public static function generateText($t,$u,$p){return BrowserMockProvider::submit('text',$t,$u,$p);}}
class BrowserMockImage {public static function generate($t,$u,$p){return BrowserMockProvider::submit('image',$t,$u,$p);} public static function syncMarketTaskResult(...$unused){}}
class BrowserMockVideo {public static function generate($t,$u,$p){return BrowserMockProvider::submit('video',$t,$u,$p);}}
class BrowserMockAudio {public static function generate($t,$u,$p){return BrowserMockProvider::submit('audio',$t,$u,$p);}}
foreach (['BrowserMockText'=>'aigc_llm\\AigcLlmService','BrowserMockImage'=>'aigc_image\\AigcImageService','BrowserMockVideo'=>'aigc_video\\AigcVideoService','BrowserMockAudio'=>'aigc_music\\AigcMusicService'] as $mock=>$service) class_alias($mock,'app\\common\\service\\app\\'.$service);
