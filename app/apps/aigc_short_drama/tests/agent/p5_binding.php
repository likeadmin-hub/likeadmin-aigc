<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use app\common\service\app\aigc_short_drama\ShortDramaCanvasBindingService;
use app\common\service\app\aigc_short_drama\ShortDramaCanvasService;
use app\common\service\app\aigc_short_drama\ShortDramaCanvasWritebackService;
use think\facade\Db;

/**
 * P5 local acceptance: directly exercise the durable current-binding boundary.
 * The complete test is one rollback-only transaction against the current x_cn
 * schema; it calls no Provider, Worker, filesystem upload, or billing path.
 */
$tenant = 91550;
$owner = 92550;
$otherTenant = 91551;
$otherUser = 92551;
$now = time();

Db::startTrans();
try {
    foreach ([[$tenant, 'p5-owner'], [$otherTenant, 'p5-other']] as [$id, $sn]) {
        Db::name('tenant')->insert(['id' => $id, 'sn' => $sn, 'create_time' => $now]);
    }
    foreach ([[$owner, $tenant, 'p5-owner'], [$otherUser, $otherTenant, 'p5-other']] as [$id, $tenantId, $account]) {
        Db::name('user')->insert(['id' => $id, 'sn' => (string)$id, 'account' => $account, 'tenant_id' => $tenantId]);
    }

    $canvas = ShortDramaCanvasService::create($tenant, $owner, ['title' => 'P5 binding fixture']);
    $parent = Db::name('aigc_short_drama_project')->insertGetId([
        'tenant_id' => $tenant, 'user_id' => $owner, 'title' => 'P5 series',
        'multi_episode' => 1, 'episode_count' => 2, 'status' => 'draft',
        'create_time' => $now, 'update_time' => $now, 'delete_time' => 0,
    ]);
    $production = Db::name('aigc_short_drama_project')->insertGetId([
        'tenant_id' => $tenant, 'user_id' => $owner, 'title' => 'P5 episode workspace',
        'multi_episode' => 0, 'episode_count' => 1, 'status' => 'draft',
        'create_time' => $now, 'update_time' => $now, 'delete_time' => 0,
    ]);
    $episode = Db::name('aigc_short_drama_episode_task')->insertGetId([
        'tenant_id' => $tenant, 'user_id' => $owner, 'project_id' => $parent,
        'episode_number' => 1, 'production_project_id' => $production,
        'title' => 'P5 episode', 'status' => 'success', 'create_time' => $now,
        'update_time' => $now, 'delete_time' => 0,
    ]);
    $foreignProject = Db::name('aigc_short_drama_project')->insertGetId([
        'tenant_id' => $otherTenant, 'user_id' => $otherUser, 'title' => 'P5 foreign',
        'create_time' => $now, 'update_time' => $now, 'delete_time' => 0,
    ]);

    // D01/D09 fixture history: free-canvas origin must survive a later bind.
    $taskId = Db::name('aigc_short_drama_generation_task')->insertGetId([
        'tenant_id' => $tenant, 'user_id' => $owner, 'project_id' => 0,
        'canvas_id' => $canvas['id'], 'task_id' => 'p5-free-task', 'task_type' => 'canvas_image',
        'status' => 'success', 'create_time' => $now, 'update_time' => $now,
    ]);
    $assetId = Db::name('aigc_short_drama_asset')->insertGetId([
        'tenant_id' => $tenant, 'user_id' => $owner, 'project_id' => 0,
        'canvas_id' => $canvas['id'], 'task_id' => 'p5-free-task', 'asset_type' => 'canvas_image',
        'uri' => 'uploads/p5-free.png', 'storage_scope' => 'tenant', 'storage_engine' => 'local',
        'create_time' => $now, 'update_time' => $now, 'delete_time' => 0,
    ]);
    $beforeTask = Db::name('aigc_short_drama_generation_task')->where('id', $taskId)->field('project_id,canvas_id')->find();
    $beforeAsset = Db::name('aigc_short_drama_asset')->where('id', $assetId)->field('project_id,canvas_id')->find();
    agentCheck((int)$beforeTask['project_id'] === 0 && (int)$beforeTask['canvas_id'] === (int)$canvas['id'], 'D01 free canvas task remains short-drama scoped with project_id=0');
    agentCheck((int)$beforeAsset['project_id'] === 0 && (int)$beforeAsset['canvas_id'] === (int)$canvas['id'], 'D01 free canvas asset remains short-drama scoped with project_id=0');

    $unbound = ShortDramaCanvasBindingService::current($tenant, $owner, (int)$canvas['id']);
    agentCheck($unbound['bound'] === false && $unbound['binding_revision'] === 0, 'D10 unbound canvas has no implicit formal target');

    $rejected = false;
    try { ShortDramaCanvasBindingService::bind($tenant, $owner, ['canvas_id' => $canvas['id'], 'project_id' => $foreignProject]); }
    catch (Exception $e) { $rejected = $e->getMessage() === '绑定项目不存在或无权访问'; }
    agentCheck($rejected, 'D02 foreign project binding is rejected');

    $forged = false;
    try { ShortDramaCanvasBindingService::bind($tenant, $owner, ['canvas_id' => $canvas['id'], 'project_id' => $parent, 'production_project_id' => $foreignProject]); }
    catch (Exception $e) { $forged = $e->getMessage() === '制作项目必须由服务端根据剧集解析'; }
    agentCheck($forged, 'D03 client cannot forge a production project');

    $storyBinding = ShortDramaCanvasBindingService::bind($tenant, $owner, ['canvas_id' => $canvas['id'], 'project_id' => $parent]);
    agentCheck($storyBinding['bound'] && $storyBinding['project_id'] === $parent && $storyBinding['episode_id'] === 0 && $storyBinding['production_project_id'] === 0, 'D03 story project binding has no guessed production project');
    $episodeBinding = ShortDramaCanvasBindingService::bind($tenant, $owner, ['canvas_id' => $canvas['id'], 'project_id' => $parent, 'episode_id' => $episode]);
    agentCheck($episodeBinding['project_id'] === $parent && $episodeBinding['episode_id'] === $episode && $episodeBinding['production_project_id'] === $production && $episodeBinding['binding_revision'] === 2, 'D03 episode binding resolves the owned production project server-side');
    $replay = ShortDramaCanvasBindingService::bind($tenant, $owner, ['canvas_id' => $canvas['id'], 'project_id' => $parent, 'episode_id' => $episode]);
    agentCheck($replay['binding_revision'] === 2, 'D09 identical binding is idempotent');

    $story = ['id' => 7101, 'type' => 'text', 'title' => '故事设定与大纲', 'metadata' => [
        'content' => '真实故事正文', 'content_revision' => 3,
        'workflow_source_stage' => 'script', 'workflow_artifact' => 'story_setting',
    ]];
    $deleted = ['id' => 7102, 'type' => 'text', 'title' => '旧单集剧本', 'metadata' => [
        'content' => '已删除正文', 'content_revision' => 1,
        'workflow_source_stage' => 'script', 'workflow_artifact' => 'episode_script',
    ]];
    $unrelated = ['id' => 7103, 'type' => 'text', 'title' => '普通文本', 'metadata' => ['content' => '不应写回']];
    Db::name('aigc_short_drama_canvas')->where('id', $canvas['id'])->update([
        'nodes_json' => json_encode([$story, $deleted, $unrelated], JSON_UNESCAPED_UNICODE),
        'removed_node_ids_json' => '[7102]', 'graph_revision' => 4,
    ]);
    $available = ShortDramaCanvasWritebackService::sources($tenant, $owner, (int)$canvas['id']);
    agentCheck($available['binding']['binding_revision'] === 2 && $available['graph_revision'] === 4
        && count($available['sources']) === 1 && $available['sources'][0]['node_id'] === '7101'
        && $available['sources'][0]['content_revision'] === 3
        && $available['sources'][0]['content_hash'] === hash('sha256', '真实故事正文'),
        'D04 writeback source discovery is owner scoped, versioned, and excludes removed or unrelated nodes');
    $sourcesRejected = false;
    try { ShortDramaCanvasWritebackService::sources($otherTenant, $otherUser, (int)$canvas['id']); }
    catch (Exception $e) { $sourcesRejected = $e->getMessage() === '画布项目不存在或无权访问'; }
    agentCheck($sourcesRejected, 'D02 foreign tenant cannot discover writeback sources');

    $afterTask = Db::name('aigc_short_drama_generation_task')->where('id', $taskId)->field('project_id,canvas_id')->find();
    $afterAsset = Db::name('aigc_short_drama_asset')->where('id', $assetId)->field('project_id,canvas_id')->find();
    agentCheck($afterTask === $beforeTask && $afterAsset === $beforeAsset, 'D09 binding never rewrites free canvas task or asset history');

    $readRejected = false;
    try { ShortDramaCanvasBindingService::current($otherTenant, $otherUser, (int)$canvas['id']); }
    catch (Exception $e) { $readRejected = $e->getMessage() === '画布项目不存在或无权访问'; }
    agentCheck($readRejected, 'D02 foreign tenant cannot read binding');
} finally {
    Db::rollback();
}

echo "NOT_RUN D04-D08,D10 formal apply: source discovery is covered; preview, field mapping, and confirmed writeback remain unimplemented.\n";
