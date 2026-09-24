<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use think\facade\Db;
use app\common\service\power\MarketVideoRuntimeService as Runtime;
Db::startTrans();
try {
    Db::name('tenant')->insert(['id'=>91001,'sn'=>'p3-reserve','create_time'=>time(),'point_balance'=>100]);
    Db::name('user')->insert(['id'=>92001,'sn'=>92001,'account'=>'p3-reserve','tenant_id'=>91001,'user_money'=>100]);
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
    $balances=static fn():array=>[(float)Db::name('tenant')->where('id',91001)->value('point_balance'),(float)Db::name('user')->where('id',92001)->value('user_money')];
    $initial=$balances();
    $knownPayload=Db::name('power_market_product')->where('id',$product)->value('source_payload');
    Db::name('power_market_product')->where('id',$product)->update(['source_payload'=>json_encode(['market_metadata'=>['supported_asset_types'=>['image']]])]);
    foreach (['quote','reserve'] as $entry) {
        $rejected=false;
        try {
            $unknownRequest=['duration'=>5,'reference_assets'=>array_slice($assets,0,1)];
            if ($entry==='quote') Runtime::quote(91001,$selection+$unknownRequest);
            else Runtime::reserve(91001,92001,'aigc_short_drama','video','aigc_short_drama_generation_task','p3-unknown-capacity',$selection,$unknownRequest);
        } catch (Exception $error) {$rejected=str_contains($error->getMessage(),'reference limit is unavailable');}
        agentCheck($rejected,'M10 public '.$entry.' rejects model with unknown reference capacity');
        agentCheck($balances()===$initial && $before===[Db::name('ai_app_task')->count(),Db::name('ai_consumption_log')->count()],'M10 rejected '.$entry.' creates no reservation or balance change');
    }
    Db::name('power_market_product')->where('id',$product)->update(['source_payload'=>$knownPayload]);
    foreach (['combined-limit'=>$assets,'unsupported-audio'=>[['type'=>'audio','url'=>'https://fixtures.invalid/voice.mp3']]] as $case=>$references) {
        $rejected=false;
        try {Runtime::reserve(91001,92001,'aigc_short_drama','video','aigc_short_drama_generation_task','p3-'.$case,$selection,['duration'=>5,'reference_assets'=>$references]);}
        catch (Exception $error) {
            $rejected=$case==='combined-limit'
                ? str_contains($error->getMessage(),'at most 2 reference assets')
                : str_contains($error->getMessage(),'does not support reference audio');
        }
        agentCheck($rejected,'public reserve rejects '.$case.' using selected model');
        agentCheck($before===[Db::name('ai_app_task')->count(),Db::name('ai_consumption_log')->count()] && $balances()===$initial,'rejected '.$case.' leaves tasks, ledger and balances unchanged');
    }
    $request=['duration'=>5,'reference_assets'=>array_slice($assets,0,2)];
    agentCheck((float)$quote['tenant_cost_points']>0 && (float)$quote['user_charge_points']>0,'insufficient-balance fixture is billable for both tenant and user');
    foreach (['tenant','user'] as $shortage) {
        // Synthetic fixture balances only; all mutations are rolled back.
        Db::name('tenant')->where('id',91001)->update(['point_balance'=>$shortage==='tenant'?0:100]);
        Db::name('user')->where('id',92001)->update(['user_money'=>$shortage==='user'?0:100]);
        $beforeReject=$balances();
        $message='';
        try {Runtime::reserve(91001,92001,'aigc_short_drama','video','aigc_short_drama_generation_task','p3-shortage-'.$shortage,$selection,$request);}
        catch (Exception $error) {$message=$error->getMessage();}
        agentCheck(str_contains($message,'不足') && (str_contains($message,'租户')===($shortage==='tenant')),'public reserve clearly rejects '.$shortage.' shortage');
        agentCheck($balances()===$beforeReject && $before===[Db::name('ai_app_task')->count(),Db::name('ai_consumption_log')->count()],'rejected '.$shortage.' shortage does not charge or create orphan task/consumption');
    }
    Db::name('tenant')->where('id',91001)->update(['point_balance'=>100]);
    Db::name('user')->where('id',92001)->update(['user_money'=>100]);
    $reserve=Runtime::reserve(91001,92001,'aigc_short_drama','video','aigc_short_drama_generation_task','p3-valid',$selection,$request);
    agentCheck($reserve['app_task_id']>0 && $reserve['consumption_id']>0,'valid reference boundary reserves through actual public service');
    $reservedBalances=$balances();
    agentCheck(abs($reservedBalances[0]-($initial[0]-(float)$quote['tenant_cost_points']))<0.00001 && abs($reservedBalances[1]-($initial[1]-(float)$quote['user_charge_points']))<0.00001,'valid reserve changes synthetic balances by quoted amounts only');
    $again=Runtime::reserve(91001,92001,'aigc_short_drama','video','aigc_short_drama_generation_task','p3-valid',$selection,$request);
    agentCheck($again['consumption_id']===$reserve['consumption_id'] && $again['app_task_id']===$reserve['app_task_id'] && $balances()===$reservedBalances,'same reservation replay preserves IDs and never charges twice');
    agentCheck([Db::name('ai_app_task')->count(),Db::name('ai_consumption_log')->count()]===[$before[0]+1,$before[1]+1],'one valid reservation creates exactly one task and consumption');
} finally {Db::rollback();}
echo "NOT_RUN HTTP/UI, remote Provider, submit/settle/refund lifecycle; isolated public quote/reserve tested\n";
