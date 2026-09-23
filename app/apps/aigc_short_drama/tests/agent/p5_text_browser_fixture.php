<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use app\common\service\app\aigc_short_drama\ShortDramaCanvasService;
use think\facade\Db;

/** Disposable tenant-1 data for story/episode browser acceptance in existing x_cn. */
const P5_TEXT_TENANT = 1;
const P5_TEXT_USER = 1;
const P5_TEXT_PREFIX = '[P5T]';

$action = (string)($argv[1] ?? '');
if (!in_array($action, ['prepare', 'episodes', 'cleanup'], true)) {
    throw new RuntimeException('Usage: p5_text_browser_fixture.php prepare|episodes|cleanup [canvas_id]');
}

if ($action === 'prepare') {
    $stamp = date('YmdHis') . '-' . bin2hex(random_bytes(3));
    $marker = P5_TEXT_PREFIX . $stamp;
    $taskId = 'p5_text_' . $stamp;
    $now = time();
    $result = Db::transaction(static function () use ($marker, $taskId, $now): array {
        $canvas = ShortDramaCanvasService::create(P5_TEXT_TENANT, P5_TEXT_USER, ['title' => $marker . ' 画布']);
        $canvasId = (int)$canvas['id'];
        $projectId = Db::name('aigc_short_drama_project')->insertGetId([
            'tenant_id' => P5_TEXT_TENANT, 'user_id' => P5_TEXT_USER,
            'title' => $marker . ' 项目', 'multi_episode' => 1, 'episode_count' => 2,
            'status' => 'plan_review', 'last_task_id' => $taskId,
            'create_time' => $now, 'update_time' => $now, 'delete_time' => 0,
        ]);
        Db::name('aigc_short_drama_script_task')->insert([
            'tenant_id' => P5_TEXT_TENANT, 'user_id' => P5_TEXT_USER,
            'project_id' => $projectId, 'task_id' => $taskId, 'status' => 'success',
            'request_json' => json_encode(['workflow_variant' => 'story_outline_v2',
                'multi_episode' => 1, 'episode_count' => 2,
                'multi_episode_stage' => 'story'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'result_json' => json_encode(['title' => '旧剧名', 'type_judgement' => '旧类型',
                'core_theme' => '旧主题', 'story_outline' => '旧故事梗概'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'create_time' => $now, 'update_time' => $now, 'delete_time' => 0,
        ]);
        $storyText = 'Agent 故事设定';
        $episodeText = 'Agent 第一集剧本';
        $nodes = [
            ['id' => 7201, 'type' => 'text', 'title' => '验收故事设定',
                'x' => 160, 'y' => 160, 'width' => 300, 'height' => 260,
                'metadata' => ['content' => $storyText, 'content_revision' => 1,
                    'workflow_source_stage' => 'script', 'workflow_artifact' => 'story_setting',
                    'workflow_formal_content_hash' => hash('sha256', $storyText),
                    'workflow_formal_fields' => ['title' => '验收新剧名', 'type_judgement' => '悬疑',
                        'core_theme' => '寻找真相', 'story_outline' => 'Agent 生成的新故事梗概']]],
            ['id' => 7202, 'type' => 'text', 'title' => '验收第一集剧本',
                'x' => 540, 'y' => 160, 'width' => 300, 'height' => 260,
                'metadata' => ['content' => $episodeText, 'content_revision' => 1,
                    'workflow_source_stage' => 'script', 'workflow_artifact' => 'episode_script',
                    'workflow_formal_content_hash' => hash('sha256', $episodeText),
                    'workflow_formal_fields' => ['episode_number' => 1, 'title' => '第一集新标题',
                        'story_outline' => 'Agent 生成的第一集大纲', 'scene_script' => '第一集分场正文']]],
        ];
        Db::name('aigc_short_drama_canvas')->where('id', $canvasId)->update([
            'nodes_json' => json_encode($nodes, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'removed_node_ids_json' => '[]', 'graph_revision' => 2,
        ]);
        return ['canvas_id' => $canvasId, 'project_id' => $projectId,
            'task_id' => $taskId, 'marker' => $marker];
    });
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), PHP_EOL;
    exit(0);
}

$canvasId = (int)($argv[2] ?? 0);
if ($canvasId <= 0) throw new RuntimeException('Exact fixture canvas ID required');
$result = Db::transaction(static function () use ($action, $canvasId): array {
    $scope = ['tenant_id' => P5_TEXT_TENANT, 'user_id' => P5_TEXT_USER];
    $canvas = Db::name('aigc_short_drama_canvas')->where($scope + ['id' => $canvasId])->lock(true)->find();
    if (!$canvas || !str_starts_with((string)$canvas['title'], P5_TEXT_PREFIX)
        || !str_ends_with((string)$canvas['title'], ' 画布')) {
        throw new RuntimeException('Refusing operation on a non-fixture canvas');
    }
    $marker = substr((string)$canvas['title'], 0, -strlen(' 画布'));
    $project = Db::name('aigc_short_drama_project')->where($scope + ['title' => $marker . ' 项目'])->lock(true)->find();
    if (!$project || !str_starts_with((string)$project['last_task_id'], 'p5_text_')) {
        throw new RuntimeException('Refusing operation without matching fixture project');
    }
    $projectId = (int)$project['id'];
    $taskId = (string)$project['last_task_id'];
    if ($action === 'episodes') {
        $task = Db::name('aigc_short_drama_script_task')->where($scope + [
            'project_id' => $projectId, 'task_id' => $taskId,
        ])->lock(true)->find();
        if (!$task) throw new RuntimeException('Fixture task missing');
        $request = json_decode((string)$task['request_json'], true);
        if (($request['multi_episode_stage'] ?? '') !== 'story') {
            throw new RuntimeException('Fixture is not at story stage');
        }
        unset($request['_story_draft'], $request['_canvas_writeback_receipts']);
        $request['multi_episode_stage'] = 'episodes';
        $episodes = [
            ['episode_number' => 1, 'title' => '旧第一集', 'story_outline' => '旧第一集大纲',
                'conflict_point' => '旧冲突', 'ending_hook' => '旧悬念'],
            ['episode_number' => 2, 'title' => '旧第二集', 'story_outline' => '旧第二集大纲',
                'conflict_point' => '旧冲突二', 'ending_hook' => '旧悬念二'],
        ];
        Db::name('aigc_short_drama_script_task')->where('id', $task['id'])->update([
            'request_json' => json_encode($request, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'result_json' => json_encode(['episodes' => $episodes], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'update_time' => time(),
        ]);
        return ['advanced_to' => 'episodes', 'canvas_id' => $canvasId, 'project_id' => $projectId];
    }
    Db::name('aigc_short_drama_canvas_binding')->where($scope + ['canvas_id' => $canvasId])->delete();
    Db::name('aigc_short_drama_script_task')->where($scope + ['project_id' => $projectId, 'task_id' => $taskId])->delete();
    Db::name('aigc_short_drama_project')->where($scope + ['id' => $projectId])->delete();
    Db::name('aigc_short_drama_canvas')->where($scope + ['id' => $canvasId])->delete();
    return ['cleaned' => ['canvas_id' => $canvasId, 'project_id' => $projectId, 'task_id' => $taskId]];
});
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), PHP_EOL;
