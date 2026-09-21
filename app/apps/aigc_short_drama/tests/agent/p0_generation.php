<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
use think\facade\Db;
use app\common\service\point\PointService;
use app\common\service\app\aigc_short_drama\ShortDramaCanvasService as Canvas;
use app\common\service\app\AppAccessService;

/** Test-only downstream boundary. Actual Canvas and PointService are NOT mocked.
 * This does not certify real provider adapters, files, quotes or callbacks. */
class P0Provider {
    public static array $received = [];
    public static bool $loseResponse = false;
    public static function submit(string $type, int $tenant, int $user, array $input): array {
        $receipt = count(self::$received) + 1;
        self::$received[] = [$type, $tenant, $user, $input];
        PointService::consumeBusinessAmountsInCurrentTransaction($tenant, $user, 1, 2, 'p0-mock-' . $receipt, 'P0 synthetic provider');
        if (self::$loseResponse) throw new RuntimeException('Simulated accepted request with lost response');
        $billing = ['tenant_cost_points' => 1, 'user_charge_points' => 2, 'billing_status' => 'settled'];
        if ($type === 'text') return ['content' => 'Synthetic response', 'provider' => 'p0-mock', 'billing' => $billing];
        $table = ['image' => 'aigc_image', 'video' => 'aigc_video', 'audio' => 'aigc_music'][$type];
        $fields = array_flip(array_column(Db::query('SHOW COLUMNS FROM `la_' . $table . '_task`'), 'Field'));
        $id = Db::name($table . '_task')->insertGetId(array_intersect_key(['tenant_id' => $tenant, 'user_id' => $user, 'status' => 'success', 'provider_task_id' => 'mock-' . $receipt] + $billing, $fields));
        $column = ['image' => 'image_uri', 'video' => 'video_uri', 'audio' => 'audio_uri'][$type];
        Db::name($table . '_result')->insert(['tenant_id' => $tenant, 'user_id' => $user, 'task_id' => $id, $column => 'https://fixtures.invalid/p0-' . $receipt, 'storage_scope' => 'tenant', 'storage_engine' => 'local']);
        return ['id' => $id, 'status' => 'success'];
    }
}
class P0Text { public static function generateText($t,$u,$p) { return P0Provider::submit('text',$t,$u,$p); } }
class P0Image { public static function generate($t,$u,$p) { return P0Provider::submit('image',$t,$u,$p); } public static function syncMarketTaskResult(...$unused) {} }
class P0Video { public static function generate($t,$u,$p) { return P0Provider::submit('video',$t,$u,$p); } }
class P0Music { public static function generate($t,$u,$p) { return P0Provider::submit('audio',$t,$u,$p); } }
foreach (['P0Text' => 'aigc_llm\\AigcLlmService', 'P0Image' => 'aigc_image\\AigcImageService', 'P0Video' => 'aigc_video\\AigcVideoService', 'P0Music' => 'aigc_music\\AigcMusicService'] as $fixture => $service) {
    class_alias($fixture, 'app\\common\\service\\app\\' . $service);
}
Db::startTrans();
try {
    $submitMethod=($argv[1]??'')==='idempotent'?'submitIdempotent':'submit';
    foreach ([91001,91002] as $id) Db::name('tenant')->insert(['id'=>$id,'sn'=>'p0-'.$id,'create_time'=>time(),'point_balance'=>100]);
    foreach ([[92001,91001],[92002,91001],[92003,91002]] as [$id,$tenant]) Db::name('user')->insert(['id'=>$id,'sn'=>$id,'account'=>'p0-'.$id,'tenant_id'=>$tenant,'user_money'=>100]);
    Db::name('app')->insert(['code'=>'aigc_short_drama','status'=>'installed']);
    Db::name('app')->insert(['code'=>'aigc_canvas','status'=>'disabled']);
    Db::name('tenant_app')->insert(['tenant_id'=>91001,'app_code'=>'aigc_short_drama','buy_status'=>'paid','enable_status'=>'enabled','shelf_status'=>'on','expire_time'=>time()+3600]);
    agentCheck(AppAccessService::tenantCanUse(91001,'aigc_short_drama'), 'B05 short-drama remains available with canvas app disabled');
    agentCheck(!AppAccessService::tenantCanUse(91001,'aigc_canvas'), 'B05 independent canvas app disabled');
    $doc = Canvas::create(91001,92001,['title'=>'P0 synthetic generation']);
    $nodes=[];
    foreach (['text','image','video','audio'] as $i=>$type) $nodes[]=['id'=>$i+1,'type'=>$type,'metadata'=>[]];
    Canvas::save(91001,92001,['id'=>$doc['id'],'nodes'=>$nodes]);
    foreach ([['node_id' => '999', 'type' => 'text', 'error' => 'NODE_NOT_FOUND'], ['node_id' => '1', 'type' => 'video', 'error' => 'NODE_TYPE_MISMATCH']] as $invalid) {
        $rejected = false;
        try { Canvas::$submitMethod(91001,92001,['canvas_id'=>$doc['id'],'node_id'=>$invalid['node_id'],'type'=>$invalid['type'],'prompt'=>'Must not reach provider','request_key'=>'invalid-'.$invalid['node_id']]); }
        catch (Exception $e) { $rejected = str_starts_with($e->getMessage(), $invalid['error']); }
        agentCheck($rejected, 'invalid generation rejected: ' . $invalid['error']);
    }
    agentCheck(count(P0Provider::$received) === 0 && Db::name('aigc_short_drama_canvas_run')->where('canvas_id', $doc['id'])->count() === 0, 'invalid node requests create no run, provider submission or charge');
    foreach ($nodes as $node) {
        $request=['canvas_id'=>$doc['id'],'node_id'=>(string)$node['id'],'type'=>$node['type'],'prompt'=>'Synthetic only','request_key'=>'generation-'.$node['id']];
        $result=Canvas::$submitMethod(91001,92001,$request);
        agentCheck($result['status']==='success','B03 synthetic '.$node['type'].' submission completes');
        if ($submitMethod==='submitIdempotent') {
            $replayed=true;
            for ($i=0;$i<10;$i++) $replayed=$replayed && Canvas::$submitMethod(91001,92001,$request)['id']===$result['id'];
            agentCheck($replayed,'idempotent Canvas adapter replays same '.$node['type'].' run ten times');
        }
        Canvas::runDetail(91001,92001,$result['id']);
        Canvas::runDetail(91001,92001,$result['id']);
    }
    agentCheck(count(P0Provider::$received)===4,'B03 downstream accepted exactly four independent calls; polling accepts none');
    agentCheck((float)Db::name('tenant')->where('id',91001)->value('point_balance')===96.0,'B03 real tenant PointService charges once per mock call');
    agentCheck((float)Db::name('user')->where('id',92001)->value('user_money')===92.0,'B03 real user PointService charges once per mock call');
    agentCheck(Db::name('tenant_point_log')->where('tenant_id',91001)->count()===4,'B03 tenant ledger has four charges');
    agentCheck(Db::name('user_account_log')->where('user_id',92001)->count()===4,'B03 user ledger has four charges');
    agentCheck(Db::name('aigc_short_drama_generation_task')->where(['canvas_id'=>$doc['id'],'project_id'=>0])->count()===4,'B03 four short-drama projections retain free-canvas ownership');
    agentCheck(Db::name('aigc_short_drama_asset')->where(['canvas_id'=>$doc['id'],'project_id'=>0])->count()===3,'B03 three media assets; repeated reads do not duplicate assets');
    if ($submitMethod==='submitIdempotent') {
        P0Provider::$loseResponse=true;
        $request=['canvas_id'=>$doc['id'],'node_id'=>'1','type'=>'text','prompt'=>'Lost response fixture','request_key'=>'lost-response'];
        $unknown=Canvas::submitIdempotent(91001,92001,$request);
        agentCheck($unknown['status']==='needs_reconciliation','Canvas adapter preserves unknown external outcome');
        $replay=Canvas::submitIdempotent(91001,92001,$request);
        agentCheck($replay['id']===$unknown['id'] && count(P0Provider::$received)===5,'Canvas replay does not resubmit unknown paid request');
        agentCheck((float)Db::name('tenant')->where('id',91001)->value('point_balance')===95.0 && (float)Db::name('user')->where('id',92001)->value('user_money')===90.0,'unknown outcome does not invent refund or duplicate charge');
        $request['prompt']='changed';$conflict=false;
        try {Canvas::submitIdempotent(91001,92001,$request);} catch (RuntimeException $error) {$conflict=$error->getMessage()==='IDEMPOTENCY_CONFLICT';}
        agentCheck($conflict && count(P0Provider::$received)===5,'same generation key with changed input rejects before downstream');
    }
} finally { Db::rollback(); }
echo "NOT_RUN actual Provider adapters, HTTP auth, browser generation and physical file transfer\n";
