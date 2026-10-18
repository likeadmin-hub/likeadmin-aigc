<?php
// Local transactions only: no model calls, media downloads or physical-file changes.
require dirname(__DIR__, 2).'/vendor/autoload.php';
(new think\App())->initialize();
use think\facade\Db;
use app\common\service\WorkspaceAssetService as W;
use app\common\service\WorkspaceCanvasAssetService as C;
$db = config('database.connections.mysql');
if (PHP_SAPI !== 'cli' || $db['hostname'] !== '127.0.0.1' || !in_array($db['database'], ['x_cn','z_cn'], true)) throw new RuntimeException('Local rollback only');
$checks = 0;
$check = static function ($ok, $message) use (&$checks) { if (!$ok) throw new RuntimeException($message); $checks++; };
Db::startTrans();
try {
    $tenant = 1999999752; $user = $tenant;
    $owner = ['tenant_id'=>$tenant,'user_id'=>$user];
    $run = Db::name('aigc_canvas_run')->insertGetId($owner+[
        'project_id'=>0,'run_type'=>'image','status'=>'success','prompt'=>'Library fixture',
        'params_json'=>json_encode(['ratio'=>'16:9','api_key'=>'must-not-leak']),
        'result_json'=>json_encode(['results'=>[['image_url'=>'https://fixtures.invalid/one.png'],['image_url'=>'https://fixtures.invalid/two.png']], 'reference_assets'=>[['url'=>'https://fixtures.invalid/input.png']]]),
        'create_time'=>time(),'delete_time'=>0,
    ]);
    $items = C::lists($tenant,$user,1);
    $check(count($items['lists']) === 2 && !$items['has_more'], 'Every generated output appears');
    $refs = array_column($items['lists'], 'ref');
    $check(count(array_unique($refs)) === 2, 'Output references are independent');
    $check(!str_contains(json_encode($items), 'api_key') && !str_contains(json_encode($items), 'input.png'), 'Only output presentation data is exposed');
    $check(C::lists($tenant+1,$user,1)['lists'] === [] && C::lists($tenant,$user+1,1)['lists'] === [], 'Tenant and account isolation');
    W::update($tenant,$user,['refs'=>[$refs[0]],'favorite'=>1]);
    $check(count(W::read($tenant,$user)['supplemental']) === 1, 'Favorite survives reload');
    W::update($tenant,$user,['refs'=>[$refs[0]],'action'=>'trash']);
    $check(C::owned($tenant,$user,$refs[1]) !== null && (int)Db::name('aigc_canvas_run')->where('id',$run)->value('delete_time') === 0, 'Deleting one output cannot remove siblings or graph execution');
    W::update($tenant,$user,['refs'=>[$refs[0]],'action'=>'restore']);
    $check((int)W::read($tenant,$user)['states'][0]['trashed_at'] === 0, 'Output restore works');
    foreach (['canvas:task:'.$run.':99','canvas:task:'.$run,'image:result:1:1'] as $invalid) {
        try { W::update($tenant,$user,['refs'=>[$invalid],'favorite'=>1]); throw new RuntimeException('Invalid output accepted'); }
        catch (InvalidArgumentException $e) { $checks++; }
    }
    $asset = Db::name('aigc_canvas_asset')->insertGetId($owner+['project_id'=>0,'task_id'=>'','asset_type'=>'reference_video','title'=>'Registered video','uri'=>'https://fixtures.invalid/video.mp4','status'=>'ready','meta_json'=>'{}','create_time'=>time(),'delete_time'=>0]);
    $ref = 'canvas:result:'.$asset;
    $check(C::lists($tenant,$user,1,'result')['lists'][0]['ref'] === $ref, 'Registered media uses its real asset identity');
    W::update($tenant,$user,['refs'=>[$ref],'favorite'=>1,'action'=>'trash']);
    $check(C::lists($tenant,$user,1,'result')['lists'] === [], 'Registered trash removes it from live lists');
    $saved = array_values(array_filter(W::read($tenant,$user)['supplemental'], fn($a)=>$a['ref']===$ref));
    $check($saved[0]['raw']['url'] === 'https://fixtures.invalid/video.mp4', 'Trash keeps playable URL');
    W::update($tenant,$user,['refs'=>[$ref],'action'=>'restore']);
    $check(count(C::lists($tenant,$user,1,'result')['lists']) === 1, 'Registered restore works');
    echo "PASS $checks canvas output, source, media, state and ownership checks; rolled back\n";
} finally { Db::rollback(); }
