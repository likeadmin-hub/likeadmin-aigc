<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use app\common\service\app\aigc_short_drama\ShortDramaCanvasService;
use think\facade\Db;

/**
 * Temporary tenant-1 browser acceptance data in the existing local database.
 * Run `prepare`, exercise the PC UI, then run `cleanup <canvas_id>`.
 * Never use this against a remote database or an existing customer canvas.
 */
const P5_BROWSER_TENANT = 1;
const P5_BROWSER_USER = 1;
const P5_BROWSER_PREFIX = '[P5B]';

$action = (string)($argv[1] ?? '');
if (!in_array($action, ['prepare', 'cleanup'], true)) {
    throw new RuntimeException('Usage: p5_browser_fixture.php prepare|cleanup [canvas_id]');
}

if ($action === 'prepare') {
    $stamp = date('YmdHis') . '-' . bin2hex(random_bytes(3));
    $marker = P5_BROWSER_PREFIX . $stamp;
    $now = time();
    $result = Db::transaction(static function () use ($marker, $stamp, $now): array {
        $canvas = ShortDramaCanvasService::create(P5_BROWSER_TENANT, P5_BROWSER_USER, ['title' => $marker . ' 画布']);
        $canvasId = (int)$canvas['id'];
        $parentId = Db::name('aigc_short_drama_project')->insertGetId([
            'tenant_id' => P5_BROWSER_TENANT, 'user_id' => P5_BROWSER_USER,
            'title' => $marker . ' 项目', 'multi_episode' => 1, 'episode_count' => 1,
            'status' => 'plan_review', 'create_time' => $now, 'update_time' => $now, 'delete_time' => 0,
        ]);
        $productionId = Db::name('aigc_short_drama_project')->insertGetId([
            'tenant_id' => P5_BROWSER_TENANT, 'user_id' => P5_BROWSER_USER,
            'title' => $marker . ' 剧集', 'multi_episode' => 0, 'episode_count' => 1,
            'status' => 'plan_review', 'create_time' => $now, 'update_time' => $now, 'delete_time' => 0,
        ]);
        $taskId = 'p5_browser_' . $stamp;
        $episodeId = Db::name('aigc_short_drama_episode_task')->insertGetId([
            'tenant_id' => P5_BROWSER_TENANT, 'user_id' => P5_BROWSER_USER,
            'project_id' => $parentId, 'episode_number' => 1,
            'production_project_id' => $productionId, 'title' => $marker . ' 第一集',
            'status' => 'success', 'create_time' => $now, 'update_time' => $now, 'delete_time' => 0,
        ]);
        Db::name('aigc_short_drama_project')->where('id', $productionId)->update(['last_task_id' => $taskId]);
        Db::name('aigc_short_drama_script_task')->insert([
            'tenant_id' => P5_BROWSER_TENANT, 'user_id' => P5_BROWSER_USER,
            'project_id' => $productionId, 'task_id' => $taskId, 'status' => 'success',
            'request_json' => '{}',
            'result_json' => json_encode(['title' => $marker . ' 第一集', 'storyboard' => [
                ['shot_id' => 'fixture-shot-1', 'title' => '原镜头', 'visual_description' => '验收前的画面描述'],
            ]], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'create_time' => $now, 'update_time' => $now, 'delete_time' => 0,
        ]);
        Db::name('aigc_short_drama_storyboard')->insert([
            'tenant_id' => P5_BROWSER_TENANT, 'user_id' => P5_BROWSER_USER,
            'project_id' => $productionId, 'task_id' => $taskId,
            'shot_id' => 'fixture-shot-1', 'title' => '原镜头',
            'visual_description' => '验收前的画面描述', 'composition' => '远景',
            'subject_ref_ids' => '[]', 'sort' => 1,
            'create_time' => $now, 'update_time' => $now, 'delete_time' => 0,
        ]);
        $source = ['id' => 7105, 'type' => 'image', 'title' => '验收分镜图',
            'x' => 240, 'y' => 180, 'width' => 300, 'height' => 300,
            'metadata' => ['prompt' => '雨夜办公室里，主角低头查看一盘旧录音带。',
                'content_revision' => 1, 'workflow_source_stage' => 'storyboard',
                'workflow_artifact' => 'storyboard']];
        Db::name('aigc_short_drama_canvas')->where('id', $canvasId)->update([
            'nodes_json' => json_encode([$source], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'removed_node_ids_json' => '[]', 'graph_revision' => 2,
        ]);
        return ['canvas_id' => $canvasId, 'parent_project_id' => $parentId,
            'episode_id' => $episodeId, 'production_project_id' => $productionId,
            'task_id' => $taskId, 'marker' => $marker];
    });
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), PHP_EOL;
    exit(0);
}

$canvasId = (int)($argv[2] ?? 0);
if ($canvasId <= 0) throw new RuntimeException('Cleanup requires an exact canvas ID');
$removed = Db::transaction(static function () use ($canvasId): array {
    $canvas = Db::name('aigc_short_drama_canvas')->where([
        'id' => $canvasId, 'tenant_id' => P5_BROWSER_TENANT, 'user_id' => P5_BROWSER_USER,
    ])->lock(true)->find();
    if (!$canvas || !str_starts_with((string)$canvas['title'], P5_BROWSER_PREFIX)) {
        throw new RuntimeException('Refusing cleanup: canvas is not a P5 browser fixture');
    }
    $marker = substr((string)$canvas['title'], 0, -strlen(' 画布'));
    $parent = Db::name('aigc_short_drama_project')->where([
        'tenant_id' => P5_BROWSER_TENANT, 'user_id' => P5_BROWSER_USER,
        'title' => $marker . ' 项目',
    ])->lock(true)->find();
    $production = Db::name('aigc_short_drama_project')->where([
        'tenant_id' => P5_BROWSER_TENANT, 'user_id' => P5_BROWSER_USER,
        'title' => $marker . ' 剧集',
    ])->lock(true)->find();
    if (!$parent || !$production || !str_starts_with((string)$production['last_task_id'], 'p5_browser_')) {
        throw new RuntimeException('Refusing cleanup: fixture ownership cannot be proven');
    }
    $parentId = (int)$parent['id'];
    $productionId = (int)$production['id'];
    $taskId = (string)$production['last_task_id'];
    $scope = ['tenant_id' => P5_BROWSER_TENANT, 'user_id' => P5_BROWSER_USER];
    Db::name('aigc_short_drama_plan_version')->where($scope + ['project_id' => $productionId])->delete();
    Db::name('aigc_short_drama_agent_step_log')->where($scope + ['project_id' => $productionId])->delete();
    Db::name('aigc_short_drama_agent_run')->where($scope + ['project_id' => $productionId])->delete();
    Db::name('aigc_short_drama_storyboard')->where($scope + ['project_id' => $productionId, 'task_id' => $taskId])->delete();
    Db::name('aigc_short_drama_script_task')->where($scope + ['project_id' => $productionId, 'task_id' => $taskId])->delete();
    Db::name('aigc_short_drama_episode_task')->where($scope + ['project_id' => $parentId, 'production_project_id' => $productionId])->delete();
    Db::name('aigc_short_drama_canvas_binding')->where($scope + ['canvas_id' => $canvasId])->delete();
    Db::name('aigc_short_drama_project')->where($scope)->whereIn('id', [$parentId, $productionId])->delete();
    Db::name('aigc_short_drama_canvas')->where($scope + ['id' => $canvasId])->delete();
    return ['canvas_id' => $canvasId, 'parent_project_id' => $parentId,
        'production_project_id' => $productionId, 'task_id' => $taskId];
});
echo json_encode(['cleaned' => $removed], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), PHP_EOL;
