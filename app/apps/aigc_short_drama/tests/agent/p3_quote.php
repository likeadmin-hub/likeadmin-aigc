<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use think\facade\Db;
use app\common\service\power\MarketVideoRuntimeService as Runtime;
Db::startTrans();
try {
    $product=Db::name('power_market_product')->insertGetId(['product_code'=>'isolated-p3-quote','resource_type'=>'model','model_type'=>'video','name'=>'Isolated video quote','source_code'=>'isolated-agent-test','upstream_resource_key'=>'isolated-p3-video','upstream_model_code'=>'isolated-p3-video','upstream_channel_code'=>'isolated-channel','source_payload'=>json_encode(['market_metadata'=>['supported_asset_types'=>['image','video'],'capabilities'=>['max_reference_images'=>3,'max_reference_videos'=>3,'max_reference_assets'=>2]]]),'status'=>1]);
    $sku=Db::name('power_market_sku')->insertGetId(['product_id'=>$product,'sku_key'=>'video-call','title'=>'Isolated video call','usage_unit'=>'call','sale_points'=>2,'status'=>1,'sale_status'=>1,'locked_params'=>'{"duration":5}']);
    $selection=['market_sku_id'=>$sku,'duration'=>5];
    $before=[Db::name('ai_app_task')->count(),Db::name('ai_consumption_log')->count()];
    $assets=[['type'=>'image','url'=>'https://fixtures.invalid/one.png'],['type'=>'image','url'=>'https://fixtures.invalid/two.png']];
    $quote=Runtime::quote(91001,$selection+['reference_assets'=>$assets]);
    agentCheck($quote['market_sku_id']===$sku && $quote['user_charge_points']>=0,'public quote accepts references at the model total limit');
    $assets[]=['type'=>'image','url'=>'https://fixtures.invalid/three.png'];
    $rejected=false;
    try {Runtime::quote(91001,$selection+['reference_assets'=>$assets]);}
    catch (Exception $error) {$rejected=str_contains($error->getMessage(),'at most 2 reference assets');}
    agentCheck($rejected,'public quote rejects complete reference set above combined model limit');
    $rejected=false;
    try {Runtime::quote(91001,$selection+['reference_assets'=>[['type'=>'audio','url'=>'https://fixtures.invalid/voice.mp3']]]);}
    catch (Exception $error) {$rejected=str_contains($error->getMessage(),'does not support reference audio') || str_contains($error->getMessage(),'没有可用的市场计费 SKU');}
    agentCheck($rejected,'public quote rejects unsupported modality for selected model');
    $plain=Runtime::quote(91001,$selection);
    agentCheck($plain['market_sku_id']===$sku,'ordinary no-reference price request remains compatible');
    agentCheck($before===[Db::name('ai_app_task')->count(),Db::name('ai_consumption_log')->count()],'successful and rejected quotes create no task or billing ledger');
} finally {Db::rollback();}
echo "NOT_RUN HTTP/UI quote, remote Provider and full reserve lifecycle; isolated public quote service tested\n";
