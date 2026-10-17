<?php
// Local rollback fixtures; no model, file, worker or billing operations.
require dirname(__DIR__, 2).'/vendor/autoload.php'; (new think\App())->initialize();
use think\facade\Db;
use app\common\service\app\aigc_canvas\{AigcCanvasService as Canvas, CanvasAssetService as Assets};
$checks = 0;
$check = static function ($ok, $message) use (&$checks) { if (!$ok) throw new RuntimeException($message); $checks++; };
$reject = static function (callable $call) use ($check) { try { $call(); } catch (Exception $e) { $check(true, 'rejected'); return; } throw new RuntimeException('Expected rejection'); };
$db = config('database.connections.mysql');
$check(PHP_SAPI === 'cli' && $db['hostname'] === '127.0.0.1' && in_array($db['database'], ['x_cn','z_cn'], true), 'Local rollback only');
Db::startTrans();
try {
    $t = 1999999773; $u = $t;
    $rows = [];
    for ($i = 1; $i <= 105; $i++) $rows[] = ['tenant_id'=>$t,'user_id'=>$u,'name'=>'Pagination '.$i,'nodes_json'=>'[]','edges_json'=>'[]','viewport_json'=>'{}','thumbnail'=>'','sort'=>0,'status'=>1,'create_time'=>$i,'update_time'=>$i,'delete_time'=>0];
    Db::name('aigc_canvas_project')->insertAll($rows);
    $a = Canvas::projectLists($t,$u,['page_no'=>1,'page_size'=>100]);
    $b = Canvas::projectLists($t,$u,['page_no'=>2,'page_size'=>100]);
    $check($a['count'] === 105 && count($a['lists']) === 100 && count($b['lists']) === 5, 'All projects must be pageable');
    $check(!array_intersect(array_column($a['lists'],'id'),array_column($b['lists'],'id')), 'Pages overlap');
    $check($b['lists'][4]['name'] === 'Pagination 1', 'Oldest project missing');
    $check(count(Canvas::projectLists($t,$u,['limit'=>100])) === 100, 'Legacy array response changed');
    $check(count(Canvas::projectLists($t,0,[])) === 60, 'Legacy admin default changed');
    $check(Canvas::projectLists($t+1,$u,['page_no'=>1])['count'] === 0 && Canvas::projectLists($t,$u+1,['page_no'=>1])['count'] === 0, 'Ownership crossed');
    $check(Canvas::projectLists($t,$u,['keyword'=>'Pagination 1','page_no'=>1,'page_size'=>2])['count'] === 17, 'Search count must cover all pages');
    $check(Canvas::projectLists($t,$u,['page_no'=>3,'page_size'=>100])['lists'] === [], 'Out-of-range page must be empty');
    $project = $a['lists'][0]['id'];
    $input = ['project_id'=>$project,'node_id'=>'text-node','asset_type'=>'text','title'=>'Text snapshot','content'=>"第一行\n第二行",'uri'=>'https://untrusted.invalid/text','storage_domain'=>'https://untrusted.invalid','meta'=>['api_key'=>'not persisted']];
    $asset = Assets::registerAsset($t,$u,$input);
    $same = Assets::registerAsset($t,$u,$input);
    $check($asset['id'] === $same['id'] && Db::name('aigc_canvas_asset')->where('project_id',$project)->count() === 1, 'Retry duplicated text');
    $check($asset['mime_type'] === 'text/plain' && $asset['uri'] === '' && $asset['url'] === '', 'Text accepted external storage');
    $check($asset['meta']['content'] === $input['content'] && !isset($asset['meta']['api_key']), 'Snapshot or private field filtering failed');
    $reload = Canvas::projectDetail($t,$u,$project)['registered_assets'];
    $check(count($reload) === 1 && $reload[0]['meta']['content'] === $input['content'], 'Fresh project read cannot restore text');
    $changed = Assets::registerAsset($t,$u,array_replace($input,['content'=>'Updated text']));
    $check($changed['id'] === $asset['id'] && $changed['meta']['content'] === 'Updated text', 'Re-add did not update the original asset');
    $reject(fn()=>Assets::registerAsset($t+1,$u,$input));
    $reject(fn()=>Assets::registerAsset($t,$u+1,$input));
    $reject(fn()=>Assets::registerAsset($t,$u,array_replace($input,['content'=>'  '])));
    $reject(fn()=>Assets::registerAsset($t,$u,array_replace($input,['content'=>['bad']])));
    $reject(fn()=>Assets::registerAsset($t,$u,array_replace($input,['content'=>str_repeat('文',200001)])));
    $reject(fn()=>Assets::registerAsset($t,$u,array_replace($input,['node_id'=>''])));
    $reject(fn()=>Assets::registerAsset($t,$u,['project_id'=>$project,'asset_type'=>'reference_image']));
    Canvas::deleteProject($t,$u,$project);
    $reject(fn()=>Assets::registerAsset($t,$u,$input));
    $check(Canvas::projectLists($t,$u,['page_no'=>1])['count'] === 104, 'Deleted project leaked into pages');
    echo "PASS $checks text durability, retry, ownership and project pagination checks; rolled back\n";
} finally { Db::rollback(); }
