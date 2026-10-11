<?php
/** Isolated transaction fixtures; no real generation or point deductions. */
require dirname(__DIR__).'/vendor/autoload.php';
(new \think\App())->initialize();
use app\common\service\app\aigc_video\VideoEffectService as Effects;
use app\common\service\app\aigc_video\AigcVideoService as Video;
use app\common\model\app\aigc_video\AigcVideoConfig as Config;
use app\common\service\power\MarketVideoRuntimeService as Runtime;
use think\facade\Db;
$check=static function($ok,$message){if(!$ok)throw new RuntimeException($message);};
$tenant=2000000001;
$check(Config::whereIn('tenant_id',[$tenant,$tenant+1])->count()===0,'Fixture IDs occupied');
Db::startTrans();
try {
    $rows=Effects::defaults();
    foreach($rows as &$row){$row['enabled']=true;$row['product_ids']=[7];$row['poster']='/uploads/fixture.webp';$row['video']='/uploads/fixture.mp4';}unset($row);
    Video::saveConfig($tenant,['config_json'=>['video_effects'=>$rows]]);
    $check(Effects::catalog($tenant)===$rows,'Config reload');
    $check(Effects::publicCatalog(Effects::catalog($tenant+1))===[],'Tenant isolation');
    Video::saveConfig($tenant,['config_json'=>[]]);
    $check(Effects::catalog($tenant)===$rows,'Legacy config save preserves effects');
    $payloadMethod=new ReflectionMethod(Runtime::class,'modelPayload');$payloadMethod->setAccessible(true);
    foreach($rows as $row){
        $params=['prompt'=>'A black headset on a pedestal.','effect_id'=>$row['id'],'generation_method'=>'text_to_video','duration'=>5,'ratio'=>'16:9','quality'=>'720p'];
        $effect=Effects::resolve(Effects::catalog($tenant),$params,7);
        $request=Effects::compile($params,$effect);
        $payload=$payloadMethod->invoke(null,['model_code'=>'wan3.0-video','channel_code'=>'isolated'],$request,'effect-fixture');
        $check(str_contains(json_encode($payload),$effect['instruction']),'Effect reaches provider payload');
        $check($params['prompt']==='A black headset on a pedestal.','Original prompt unchanged');
    }
    echo "PASS: config persistence, tenant isolation, legacy writes, all three provider payloads; no generation submitted\n";
} finally {Db::rollback();}
$check(Config::where('tenant_id',$tenant)->count()===0,'Fixture rollback');
echo "PASS: all fixture changes rolled back\n";
