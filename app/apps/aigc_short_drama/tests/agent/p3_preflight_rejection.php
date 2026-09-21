<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use think\facade\Db;
use app\common\service\power\MarketVideoRuntimeService as Market;
use app\common\service\app\aigc_short_drama\ShortDramaCanvasService as Canvas;
use app\common\service\app\aigc_short_drama\canvas_agent\GenerationIntentService as Intent;

// Replace only the video application boundary; actual market reserve and
// PointService run against synthetic balances. No Provider submit exists here.
class P3PreflightVideo {
    public static int $sku=0;
    public static int $calls=0;
    public static bool $unknown=false;
    public static function generate($tenant,$user,$payload): array {
        self::$calls++;
        if (self::$unknown) throw new RuntimeException('simulated response lost after submit');
        Market::reserve($tenant,$user,'aigc_short_drama','video','aigc_video_task','p3-preflight',
            ['market_sku_id'=>self::$sku,'duration'=>5],['prompt'=>'synthetic','duration'=>5]);
        throw new LogicException('Fixture must reject before any Provider call');
    }
}
$realApp=($argv[1]??'')==='real-app';
if (!$realApp) class_alias(P3PreflightVideo::class,'app\\common\\service\\app\\aigc_video\\AigcVideoService');
Db::startTrans();
try {
    Db::name('tenant')->insert(['id'=>91001,'sn'=>'p3-preflight','create_time'=>time(),'point_balance'=>0]);
    Db::name('user')->insert(['id'=>92001,'sn'=>92001,'account'=>'p3-preflight','tenant_id'=>91001,'user_money'=>100]);
    $product=Db::name('power_market_product')->insertGetId(['product_code'=>'isolated-p3-preflight','resource_type'=>'model','model_type'=>'video','name'=>'Synthetic video','source_code'=>'isolated-agent-test','upstream_resource_key'=>'isolated-p3-preflight','upstream_model_code'=>'isolated-p3-preflight','upstream_channel_code'=>'isolated-channel','status'=>1]);
    P3PreflightVideo::$sku=Db::name('power_market_sku')->insertGetId(['product_id'=>$product,'sku_key'=>'video-call','title'=>'Synthetic video','usage_unit'=>'call','sale_points'=>2,'status'=>1,'sale_status'=>1,'locked_params'=>'{"duration":5}']);
    $canvas=Canvas::create(91001,92001,['title'=>'P3 known preflight failure'])['id'];
    Canvas::save(91001,92001,['id'=>$canvas,'nodes'=>[['id'=>1,'type'=>'video','metadata'=>[]]]]);
    $counts=static fn():array=>[Db::name('ai_app_task')->count(),Db::name('ai_consumption_log')->count(),Db::name('tenant_point_log')->where('tenant_id',91001)->count(),Db::name('user_account_log')->where('user_id',92001)->count()];
    $before=$counts();
    $params=['canvas_id'=>$canvas,'node_id'=>'1','type'=>'video','prompt'=>'Synthetic only','request_key'=>'p3-shortage','model_id'=>'market_video_model:'.$product,'duration'=>5];
    $result=Canvas::submitIdempotent(91001,92001,$params);
    agentCheck($result['status']==='failed' && str_contains($result['error'],'租户') && str_contains($result['error'],'不足'),'M15 canvas exposes explicit failed status and tenant shortage');
    $intent=Db::name(Intent::TABLE)->where(['canvas_id'=>$canvas,'request_key'=>'p3-shortage'])->find();
    agentCheck($intent['state']==='failed' && $intent['error_code']==='PRE_SUBMISSION_REJECTED' && (int)$intent['lease_until']===0,'known rejection releases lease without entering reconciliation');
    agentCheck($before===$counts(),'rejected canvas request creates no market task, consumption or point logs');
    agentCheck((float)Db::name('tenant')->where('id',91001)->value('point_balance')===0.0 && (float)Db::name('user')->where('id',92001)->value('user_money')===100.0,'both balances unchanged');
    for ($i=0;$i<3;$i++) {
        $again=Canvas::submitIdempotent(91001,92001,$params);
        agentCheck($again['status']==='failed' && $again['id']===$result['id'],'replay returns the same failed run '.$i);
    }
    if ($realApp) {
        $videoRows=Db::name('aigc_video_task')->where(['tenant_id'=>91001,'user_id'=>92001])->select()->toArray();
        agentCheck(count($videoRows)===1 && $videoRows[0]['status']==='failed' && (int)$videoRows[0]['consumption_id']===0,'real video application retains one failed uncharged task and replay never recreates it');
    } else agentCheck(P3PreflightVideo::$calls===1,'failed request replay does not redispatch');
    $history=Db::name('aigc_short_drama_generation_task')->where(['tenant_id'=>91001,'user_id'=>92001,'task_id'=>'canvas_run_'.$result['id']])->find();
    agentCheck($history && $history['status']==='failed','short-drama task history retains failure');
    $fresh=Canvas::current(91001,92001,$canvas);
    agentCheck(count($fresh['runs'])===1 && $fresh['runs'][0]['id']===$result['id'] && $fresh['runs'][0]['status']==='failed'
        && $fresh['runs'][0]['error']===$result['error'],'refresh projection restores authoritative failure and its reason');
    $again=Canvas::current(91001,92001,$canvas);
    agentCheck($again['runs']===$fresh['runs'] && $again['graph_revision']===$fresh['graph_revision'] && $before===$counts(),'repeated failure reads neither resubmit nor mutate graph or billing');
    foreach ([[91002,92001],[91001,92002]] as [$otherTenant,$otherUser]) {
        $denied=false;
        try {Canvas::runDetail($otherTenant,$otherUser,(int)$result['id']);}
        catch (Exception $error) {$denied=str_contains($error->getMessage(),'无权访问');}
        agentCheck($denied,'failed run remains inaccessible outside its tenant/user scope');
    }
    if (!$realApp) {
    P3PreflightVideo::$unknown=true;
    $params['request_key']='p3-unknown';
    $unknown=Canvas::submitIdempotent(91001,92001,$params);
    agentCheck($unknown['status']==='needs_reconciliation','unclassified error still retains unknown outcome');
    agentCheck($before===$counts(),'unknown mock does not invent debit or refund');
    }
} finally {Db::rollback();}
echo $realApp
    ? "NOT_RUN HTTP/UI/Provider submission; actual Canvas/video app/market/PointService preflight path tested\n"
    : "NOT_RUN real video app/HTTP/UI/Provider submission; actual Canvas/Market reserve/PointService with isolated app boundary\n";
