<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';

use app\common\service\app\aigc_short_drama\ShortDramaCanvasService as Canvas;
use think\facade\Db;

final class P3AssetVersionVideoProvider
{
    public static array $requests=[];
    public static function estimate(int $tenant, array $payload): array
    {
        return [
            'market_product_id' => 1, 'market_sku_id' => 1,
            'tenant_cost_points' => 0, 'user_charge_points' => 0,
            'usage_unit' => 'call', 'settlement_mode' => 'fixture',
        ];
    }
    public static function generate(int $tenant, int $user, array $payload): array
    {
        self::$requests[]=$payload;
        $task=Db::name('aigc_video_task')->insertGetId(['tenant_id'=>$tenant,'user_id'=>$user,'status'=>'success','provider_task_id'=>'p3-asset-version']);
        Db::name('aigc_video_result')->insert(['tenant_id'=>$tenant,'user_id'=>$user,'task_id'=>$task,'video_uri'=>'https://fixtures.invalid/output.mp4','storage_scope'=>'tenant','storage_engine'=>'oss','storage_domain'=>'https://fixtures.invalid']);
        return ['id'=>$task,'status'=>'success'];
    }
}
class_alias(P3AssetVersionVideoProvider::class,'app\\common\\service\\app\\aigc_video\\AigcVideoService');

Db::startTrans();
try {
    Db::name('tenant')->insert(['id'=>91001,'sn'=>'p3-asset-version','create_time'=>time(),'point_balance'=>100]);
    Db::name('user')->insert(['id'=>92001,'sn'=>92001,'account'=>'p3-asset-version','tenant_id'=>91001,'user_money'=>100]);
    $canvas=Canvas::create(91001,92001,['title'=>'P3 old asset version'])['id'];
    Canvas::save(91001,92001,['id'=>$canvas,'nodes'=>[['id'=>1,'type'=>'video','metadata'=>[]]]]);
    $assetBase=['tenant_id'=>91001,'user_id'=>92001,'project_id'=>0,'canvas_id'=>$canvas,'asset_type'=>'canvas_image','title'=>'old image version','cover_uri'=>'','storage_scope'=>'tenant','storage_engine'=>'oss','storage_domain'=>'https://fixtures.invalid','mime_type'=>'image/png','file_size'=>1,'width'=>1,'height'=>1,'duration'=>0,'checksum'=>'','meta_json'=>'{}','status'=>'ready','create_time'=>time(),'update_time'=>time(),'delete_time'=>0];
    $old=Db::name('aigc_short_drama_asset')->insertGetId($assetBase+['task_id'=>'canvas_run_old','uri'=>'https://fixtures.invalid/old-version.png']);
    $new=Db::name('aigc_short_drama_asset')->insertGetId($assetBase+['task_id'=>'canvas_run_new','title'=>'new image version','uri'=>'https://fixtures.invalid/new-version.png']);
    $request=['canvas_id'=>$canvas,'node_id'=>'1','type'=>'video','prompt'=>'只使用用户选择的旧版本','request_key'=>'old-version','reference_assets'=>[['type'=>'image','asset_id'=>$old,'url'=>'https://forged.invalid/newest.png','role'=>'first_frame_image']]];
    $quote=Canvas::quote(91001,92001,$request);
    $confirmed=Canvas::confirmQuote(91001,92001,['canvas_id'=>$canvas,'node_id'=>'1','quote_token'=>$quote['quote_token']]);
    $request['quote_token']=$confirmed['quote_token'];
    $run=Canvas::submitIdempotent(91001,92001,$request);
    $stored=json_decode((string)Db::name('aigc_short_drama_canvas_run')->where('id',$run['id'])->value('request_json'),true,512,JSON_THROW_ON_ERROR);
    agentCheck($run['status']==='success' && count(P3AssetVersionVideoProvider::$requests)===1,'M16 selected old asset version submits one video run');
agentCheck((int)$stored['reference_assets'][0]['asset_id']===$old && str_contains((string)$stored['reference_assets'][0]['url'],'old-version.png') && !str_contains((string)$stored['reference_assets'][0]['url'],'forged.invalid'),'M16 request snapshot resolves selected old asset identity rather than browser URL');
agentCheck((int)P3AssetVersionVideoProvider::$requests[0]['reference_assets'][0]['asset_id']===$old && str_contains((string)P3AssetVersionVideoProvider::$requests[0]['reference_assets'][0]['url'],'old-version.png'),'M16 Provider receives the user-selected old asset version');
$history=Db::name('aigc_short_drama_generation_task')->where(['tenant_id'=>91001,'user_id'=>92001,'task_id'=>'canvas_run_'.(int)$run['id']])->find();
agentCheck($history && json_decode((string)$history['input_asset_ids'],true)===[(int)$old],'M16 task history records the linked input asset identity');
    agentCheck(Db::name('aigc_short_drama_asset')->where(['id'=>$new,'delete_time'=>0,'status'=>'ready'])->count()===1,'M16 newer version remains separate and does not replace selected old asset');
    $foreign=Db::name('aigc_short_drama_asset')->insertGetId(array_replace($assetBase,['user_id'=>92002,'task_id'=>'foreign','uri'=>'https://fixtures.invalid/foreign.png']));
    $blocked=false;
    try { Canvas::submitIdempotent(91001,92001,array_replace($request,['request_key'=>'foreign-version','reference_assets'=>[['type'=>'image','asset_id'=>$foreign,'url'=>'https://forged.invalid/foreign.png']]])); }
    catch (\Exception $error) { $blocked=$error->getMessage()==='CANVAS_REFERENCE_UNAVAILABLE'; }
    agentCheck($blocked && count(P3AssetVersionVideoProvider::$requests)===1,'M16 foreign or forged asset selection is rejected before Provider submission');
} finally { Db::rollback(); }
echo "NOT_RUN browser asset picker and real Provider media generation; selected asset identity service boundary tested\n";
