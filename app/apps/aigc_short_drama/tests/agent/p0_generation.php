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
    public static string $knownFailure = '';
    public static ?Closure $beforeReturn = null;
    public static function submit(string $type, int $tenant, int $user, array $input): array {
        if (self::$knownFailure !== '') throw new RuntimeException(self::$knownFailure);
        $receipt = count(self::$received) + 1;
        self::$received[] = [$type, $tenant, $user, $input];
        PointService::consumeBusinessAmountsInCurrentTransaction($tenant, $user, 1, 2, 'p0-mock-' . $receipt, 'P0 synthetic provider');
        if (self::$loseResponse) throw new RuntimeException('Simulated accepted request with lost response');
        $callback=self::$beforeReturn;self::$beforeReturn=null;
        if ($callback) $callback();
        $billing = ['tenant_cost_points' => 1, 'user_charge_points' => 2, 'billing_status' => 'settled'];
        if ($type === 'text') return ['content' => 'Synthetic response '.($input['prompt']??''), 'provider' => 'p0-mock', 'billing' => $billing];
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
class P0Video {
    public static function estimate($t,$p): array { return ['market_product_id'=>1,'market_sku_id'=>1,'tenant_cost_points'=>1,'user_charge_points'=>2,'usage_unit'=>'call','settlement_mode'=>'reserved']; }
    public static function generate($t,$u,$p) { return P0Provider::submit('video',$t,$u,$p); }
}
class P0Music { public static function generate($t,$u,$p) { return P0Provider::submit('audio',$t,$u,$p); } }
foreach (['P0Text' => 'aigc_llm\\AigcLlmService', 'P0Image' => 'aigc_image\\AigcImageService', 'P0Video' => 'aigc_video\\AigcVideoService', 'P0Music' => 'aigc_music\\AigcMusicService'] as $fixture => $service) {
    class_alias($fixture, 'app\\common\\service\\app\\' . $service);
}
function p0SubmitIdempotent(array $request): array {
    if (($request['type'] ?? '') === 'video' && empty($request['quote_token'])) {
        $quote=Canvas::quote(91001,92001,$request);
        $confirmed=Canvas::confirmQuote(91001,92001,['canvas_id'=>$request['canvas_id'],'node_id'=>(string)$request['node_id'],'quote_token'=>$quote['quote_token']]);
        $request['quote_token']=$confirmed['quote_token'];
    }
    return Canvas::submitIdempotent(91001,92001,$request);
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
        if ($node['type']==='video') $request+=['model_code'=>'synthetic-video','resolution'=>'720p','reference_assets'=>[
            ['type'=>'image','uri'=>'https://fixtures.invalid/first.png','role'=>'first_frame_image'],
            ['type'=>'image','uri'=>'https://fixtures.invalid/last.png','role'=>'last_frame_image'],
        ]];
        if ($node['type']==='video' && $submitMethod==='submitIdempotent') {
            $quote=Canvas::quote(91001,92001,$request);
            $confirmed=Canvas::confirmQuote(91001,92001,['canvas_id'=>$doc['id'],'node_id'=>(string)$node['id'],'quote_token'=>$quote['quote_token']]);
            $request['quote_token']=$confirmed['quote_token'];
        }
        $result=Canvas::$submitMethod(91001,92001,$request);
        agentCheck($result['status']==='success','B03 synthetic '.$node['type'].' submission completes');
        if ($submitMethod==='submitIdempotent') {
            $replayed=true;
            for ($i=0;$i<10;$i++) $replayed=$replayed && Canvas::$submitMethod(91001,92001,$request)['id']===$result['id'];
            agentCheck($replayed,'idempotent Canvas adapter replays same '.$node['type'].' run ten times');
            if ($node['type']==='video') {
                $snapshot=Db::name('aigc_short_drama_canvas_run')->where('id',$result['id'])->value('request_json');
                $beforeCalls=count(P0Provider::$received);
                $swapped=$request['reference_assets'];
                [$swapped[0]['role'],$swapped[1]['role']]=[$swapped[1]['role'],$swapped[0]['role']];
                foreach (['model'=>['model_code'=>'synthetic-other'],'resolution'=>['resolution'=>'1080p'],
                    'reference'=>['reference_assets'=>[$request['reference_assets'][0]]],
                    'roles'=>['reference_assets'=>$swapped],
                    'order'=>['reference_assets'=>array_reverse($request['reference_assets'])]] as $field=>$change) {
                    $conflict=false;
                    try {Canvas::submitIdempotent(91001,92001,array_replace($request,$change));}
                    catch (RuntimeException $error) {$conflict=$error->getMessage()==='IDEMPOTENCY_CONFLICT';}
                    agentCheck($conflict && count(P0Provider::$received)===$beforeCalls,'changed '.$field.' cannot reuse generation key or reach downstream');
                }
                agentCheck(Db::name('aigc_short_drama_canvas_run')->where('id',$result['id'])->value('request_json')===$snapshot,'conflicting model/reference requests cannot overwrite frozen snapshot');
            }
        }
        Canvas::runDetail(91001,92001,$result['id']);
        Canvas::runDetail(91001,92001,$result['id']);
        if ($node['type']==='text') {
            $expected='Synthetic response Synthetic only';
            agentCheck(!array_key_exists('status',$result['result']) && $result['result']['content']===$expected,'M13 fixture returns synchronous text content without a downstream status');
            $fresh=Canvas::runDetail(91001,92001,$result['id']);
            agentCheck($fresh['status']==='success' && $fresh['progress']===100 && $fresh['result']['content']===$expected,'M13 detail reads retain completed text and full progress');
            $history=Db::name('aigc_short_drama_generation_task')->where('task_id','canvas_run_'.$result['id'])->find();
            agentCheck($history['status']==='success' && (int)$history['progress']===100 && json_decode($history['result_json'],true)['content']===$expected,'M13 history retains synchronous text result without requiring async status');
            if ($submitMethod==='submitIdempotent') {
                $beforeRead=Canvas::current(91001,92001,$doc['id']);
                Canvas::runDetail(91001,92001,$result['id']);
                $afterRead=Canvas::current(91001,92001,$doc['id']);
                $meta=$afterRead['nodes'][0]['metadata'];
                agentCheck($meta['content']===$expected && $meta['status']==='success' && (int)$meta['projected_generation_id']===$result['id'],'M13 completed text is projected onto the original node');
                agentCheck($beforeRead['nodes']===$afterRead['nodes'] && $beforeRead['graph_revision']===$afterRead['graph_revision'],'M13 repeated reads do not append content versions or mutate graph');
            }
        }
    }
    agentCheck(count(P0Provider::$received)===4,'B03 downstream accepted exactly four independent calls; polling accepts none');
    agentCheck((float)Db::name('tenant')->where('id',91001)->value('point_balance')===96.0,'B03 real tenant PointService charges once per mock call');
    agentCheck((float)Db::name('user')->where('id',92001)->value('user_money')===92.0,'B03 real user PointService charges once per mock call');
    agentCheck(Db::name('tenant_point_log')->where('tenant_id',91001)->count()===4,'B03 tenant ledger has four charges');
    agentCheck(Db::name('user_account_log')->where('user_id',92001)->count()===4,'B03 user ledger has four charges');
    agentCheck(Db::name('aigc_short_drama_generation_task')->where(['canvas_id'=>$doc['id'],'project_id'=>0])->count()===4,'B03 four short-drama projections retain free-canvas ownership');
    agentCheck(Db::name('aigc_short_drama_asset')->where(['canvas_id'=>$doc['id'],'project_id'=>0])->count()===3,'B03 three media assets; repeated reads do not duplicate assets');
    if ($submitMethod==='submitIdempotent') {
        $knownUnavailable=Canvas::create(91001,92001,['title'=>'Known unavailable model fixture'])['id'];
        Canvas::save(91001,92001,['id'=>$knownUnavailable,'nodes'=>[['id'=>1,'type'=>'text','metadata'=>[]]]]);
        $knownRequest=['canvas_id'=>$knownUnavailable,'node_id'=>'1','type'=>'text','prompt'=>'Known unavailable model fixture','request_key'=>'known-model-unavailable'];
        $beforeKnownCalls=count(P0Provider::$received);
        $beforeKnownBalances=[(float)Db::name('tenant')->where('id',91001)->value('point_balance'),(float)Db::name('user')->where('id',92001)->value('user_money')];
        P0Provider::$knownFailure='model_not_found: synthetic unavailable model';
        $knownFailure=Canvas::submitIdempotent(91001,92001,$knownRequest);
        P0Provider::$knownFailure='';
        agentCheck($knownFailure['status']==='failed' && $knownFailure['error']==='当前选择的模型暂不可用，请切换模型后重新提交','explicit upstream model rejection is a terminal actionable failure');
        $knownIntent=Db::name('aigc_short_drama_canvas_generation_intent')->where(['canvas_id'=>$knownUnavailable,'request_key'=>'known-model-unavailable'])->find();
        agentCheck($knownIntent['state']==='failed' && $knownIntent['error_code']==='UPSTREAM_MODEL_UNAVAILABLE','explicit model rejection never enters reconciliation');
        agentCheck(count(P0Provider::$received)===$beforeKnownCalls && $beforeKnownBalances===[(float)Db::name('tenant')->where('id',91001)->value('point_balance'),(float)Db::name('user')->where('id',92001)->value('user_money')],'terminal model rejection neither reaches Provider receipt nor changes balances');
        $knownReplay=Canvas::submitIdempotent(91001,92001,$knownRequest);
        agentCheck($knownReplay['id']===$knownFailure['id'] && $knownReplay['status']==='failed','known unavailable model replay returns the same failed run');
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
        P0Provider::$loseResponse=false;
        foreach (['text','image','video','audio'] as $type) {
            $deleted=Canvas::create(91001,92001,['title'=>'Deleted during '.$type.' submit']);
            Canvas::save(91001,92001,['id'=>$deleted['id'],'expected_revision'=>0,'nodes'=>[['id'=>1,'type'=>$type,'metadata'=>[]]]]);
            P0Provider::$beforeReturn=static function () use ($deleted): void {
                $current=Canvas::current(91001,92001,$deleted['id']);
                Canvas::save(91001,92001,['id'=>$deleted['id'],'expected_revision'=>$current['graph_revision'],'nodes'=>[],'removed_node_ids'=>['1']]);
            };
            $run=p0SubmitIdempotent(['canvas_id'=>$deleted['id'],'node_id'=>'1','type'=>$type,'prompt'=>'Deleted fixture','request_key'=>'delete-during-submit']);
            agentCheck($run['status']==='success' && Canvas::current(91001,92001,$deleted['id'])['nodes']===[],$type.' completion after in-flight deletion never resurrects the node');
            agentCheck(Db::name('aigc_short_drama_generation_task')->where('canvas_id',$deleted['id'])->count()===1,$type.' deleted-node completion remains in actual short-drama history');
            if ($type!=='text') agentCheck(Db::name('aigc_short_drama_asset')->where('canvas_id',$deleted['id'])->count()===1,$type.' deleted-node completion retains owned media asset');

            $race=Canvas::create(91001,92001,['title'=>'Out-of-order '.$type.' submit']);
            Canvas::save(91001,92001,['id'=>$race['id'],'expected_revision'=>0,'nodes'=>[['id'=>1,'type'=>$type,'metadata'=>[]]]]);
            $newRun=null;
            P0Provider::$beforeReturn=static function () use ($race,$type,&$newRun): void {
                $newRun=p0SubmitIdempotent(['canvas_id'=>$race['id'],'node_id'=>'1','type'=>$type,'prompt'=>'New fixture','request_key'=>'new-request']);
            };
            $oldRun=p0SubmitIdempotent(['canvas_id'=>$race['id'],'node_id'=>'1','type'=>$type,'prompt'=>'Old fixture','request_key'=>'old-request']);
            $current=Canvas::current(91001,92001,$race['id']);$meta=$current['nodes'][0]['metadata'];
            $actual=$type==='text'?$meta['content']:$meta['url'];
            $expected=$type==='text'?$newRun['result']['content']:$newRun['results'][0]['url'];
            agentCheck($oldRun['status']==='success' && $actual===$expected && (int)$meta['active_generation_id']===$newRun['id'],$type.' actual Canvas adapter preserves later request when first response arrives last');
            agentCheck(Db::name('aigc_short_drama_generation_task')->where('canvas_id',$race['id'])->count()===2,$type.' both out-of-order completions remain in actual task history');
            if ($type!=='text') agentCheck(Db::name('aigc_short_drama_asset')->where('canvas_id',$race['id'])->count()===2,$type.' both out-of-order media versions retain owned asset records');
        }
        agentCheck(count(P0Provider::$received)===17 && Db::name('tenant_point_log')->where('tenant_id',91001)->count()===17 && Db::name('user_account_log')->where('user_id',92001)->count()===17,'late/deleted completion history projection never adds a second generation charge');
        agentCheck((float)Db::name('tenant')->where('id',91001)->value('point_balance')===83.0 && (float)Db::name('user')->where('id',92001)->value('user_money')===66.0,'complete lifecycle mock cost matches authoritative balances');
    }
} finally { Db::rollback(); }
echo "NOT_RUN actual Provider adapters, HTTP auth, browser generation and physical file transfer\n";
