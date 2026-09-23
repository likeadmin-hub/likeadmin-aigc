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
        'workflow_formal_content_hash' => hash('sha256', '真实故事正文'),
        'workflow_formal_fields' => ['title' => '新剧名', 'type_judgement' => '悬疑',
            'core_theme' => '亲情与真相', 'story_outline' => '新故事梗概'],
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
        && $available['sources'][0]['content_hash'] === hash('sha256', '真实故事正文')
        && count($available['sources'][0]['writeback_fields']) === 4,
        'D04 writeback source discovery is owner scoped, versioned, and excludes removed or unrelated nodes');
    $sourcesRejected = false;
    try { ShortDramaCanvasWritebackService::sources($otherTenant, $otherUser, (int)$canvas['id']); }
    catch (Exception $e) { $sourcesRejected = $e->getMessage() === '画布项目不存在或无权访问'; }
    agentCheck($sourcesRejected, 'D02 foreign tenant cannot discover writeback sources');

    $episodePreviewRejected = false;
    try { ShortDramaCanvasWritebackService::previewStory($tenant, $owner, [
        'canvas_id' => $canvas['id'], 'source_node_id' => 7101, 'target_field' => 'story_outline',
    ]); }
    catch (Exception $e) { $episodePreviewRejected = str_contains($e->getMessage(), '故事项目'); }
    agentCheck($episodePreviewRejected, 'D04 story preview cannot use an episode production binding');

    $previewProject = Db::name('aigc_short_drama_project')->insertGetId([
        'tenant_id' => $tenant, 'user_id' => $owner, 'title' => 'P5 preview series',
        'multi_episode' => 1, 'episode_count' => 2, 'status' => 'plan_review',
        'last_task_id' => 'p5-preview-task', 'create_time' => $now, 'update_time' => $now, 'delete_time' => 0,
    ]);
    Db::name('aigc_short_drama_script_task')->insert([
        'tenant_id' => $tenant, 'user_id' => $owner, 'project_id' => $previewProject,
        'task_id' => 'p5-preview-task', 'status' => 'success',
        'request_json' => json_encode(['workflow_variant' => 'story_outline_v2', 'multi_episode' => 1,
            'episode_count' => 2, 'multi_episode_stage' => 'story'], JSON_UNESCAPED_UNICODE),
        'result_json' => json_encode(['title' => '旧剧名', 'story_outline' => '旧故事梗概'], JSON_UNESCAPED_UNICODE),
        'create_time' => $now, 'update_time' => $now, 'delete_time' => 0,
    ]);
    ShortDramaCanvasBindingService::bind($tenant, $owner, [
        'canvas_id' => $canvas['id'], 'project_id' => $previewProject,
    ]);
    $previewRequest = ['canvas_id' => $canvas['id'], 'source_node_id' => 7101,
        'target_field' => 'story_outline'];
    $preview = ShortDramaCanvasWritebackService::previewStory($tenant, $owner, $previewRequest);
    agentCheck($preview['source']['content'] === '新故事梗概'
        && $preview['target']['content'] === '旧故事梗概'
        && $preview['target']['project_id'] === $previewProject
        && $preview['can_apply'] === true,
        'D04 story field preview shows actual owned source and current formal target without applying');
    Db::name('aigc_short_drama_script_task')->where('task_id', 'p5-preview-task')->update([
        'result_json' => json_encode(['title' => '旧剧名', 'story_outline' => '目标已经修改'], JSON_UNESCAPED_UNICODE),
    ]);
    $targetChanged = ShortDramaCanvasWritebackService::previewStory($tenant, $owner, $previewRequest);
    agentCheck($targetChanged['preview_hash'] !== $preview['preview_hash'],
        'D05 target text changes invalidate the preview fingerprint');
    $staleRejected = false;
    try { ShortDramaCanvasWritebackService::applyStory($tenant, $owner, $previewRequest + [
        'preview_hash' => $preview['preview_hash'], 'confirm' => '1',
    ]); }
    catch (Exception $e) { $staleRejected = str_contains($e->getMessage(), 'VERSION_CONFLICT'); }
    agentCheck($staleRejected, 'D05 stale target preview cannot overwrite formal story');
    $story['metadata']['content'] = '来源也已修改';
    $story['metadata']['content_revision'] = 4;
    $story['metadata']['workflow_formal_content_hash'] = hash('sha256', '来源也已修改');
    $story['metadata']['workflow_formal_fields']['story_outline'] = '新修订故事梗概';
    Db::name('aigc_short_drama_canvas')->where('id', $canvas['id'])->update([
        'nodes_json' => json_encode([$story, $deleted, $unrelated], JSON_UNESCAPED_UNICODE),
        'graph_revision' => 5,
    ]);
    $sourceChanged = ShortDramaCanvasWritebackService::previewStory($tenant, $owner, $previewRequest);
    agentCheck($sourceChanged['preview_hash'] !== $targetChanged['preview_hash'],
        'D05 source text and content revision changes invalidate the preview fingerprint');
    Db::name('aigc_short_drama_project')->where('id', $previewProject)->update(['current_version_id' => 7]);
    $versionChanged = ShortDramaCanvasWritebackService::previewStory($tenant, $owner, $previewRequest);
    agentCheck($versionChanged['preview_hash'] !== $sourceChanged['preview_hash']
        && $versionChanged['target']['project_version_id'] === 7,
        'D05 formal project version changes invalidate the preview fingerprint');
    ShortDramaCanvasBindingService::bind($tenant, $owner, [
        'canvas_id' => $canvas['id'], 'project_id' => $parent,
    ]);
    ShortDramaCanvasBindingService::bind($tenant, $owner, [
        'canvas_id' => $canvas['id'], 'project_id' => $previewProject,
    ]);
    $rebound = ShortDramaCanvasWritebackService::previewStory($tenant, $owner, $previewRequest);
    agentCheck($rebound['preview_hash'] !== $versionChanged['preview_hash']
        && $rebound['binding']['binding_revision'] > $versionChanged['binding']['binding_revision'],
        'D05 rebinding invalidates an earlier preview even if the final target is unchanged');
    $withoutConfirm = false;
    try { ShortDramaCanvasWritebackService::applyStory($tenant, $owner, $previewRequest + [
        'preview_hash' => $rebound['preview_hash'],
    ]); }
    catch (Exception $e) { $withoutConfirm = str_contains($e->getMessage(), '逐项确认'); }
    agentCheck($withoutConfirm, 'D04 writeback requires explicit confirmation after preview');
    $applied = ShortDramaCanvasWritebackService::applyStory($tenant, $owner, $previewRequest + [
        'preview_hash' => $rebound['preview_hash'], 'confirm' => '1',
    ]);
    $savedRequest = json_decode((string)Db::name('aigc_short_drama_script_task')->where('task_id', 'p5-preview-task')->value('request_json'), true);
    agentCheck($applied['applied'] === true && $applied['draft_version'] === 1
        && ($savedRequest['_story_draft']['result']['story_outline'] ?? '') === '新修订故事梗概'
        && ($savedRequest['_story_draft']['result']['title'] ?? '') === '旧剧名',
        'D04 confirmed story field writes only the selected field into existing formal draft');
    $replayed = ShortDramaCanvasWritebackService::applyStory($tenant, $owner, $previewRequest + [
        'preview_hash' => $rebound['preview_hash'], 'confirm' => '1',
    ]);
    $replayRequest = json_decode((string)Db::name('aigc_short_drama_script_task')->where('task_id', 'p5-preview-task')->value('request_json'), true);
    agentCheck($replayed === $applied && ($replayRequest['_story_draft']['version'] ?? 0) === 1,
        'D09 replayed apply returns original receipt without a second draft mutation');
    $postApplyPreview = ShortDramaCanvasWritebackService::previewStory($tenant, $owner, $previewRequest);
    agentCheck($postApplyPreview['preview_hash'] !== $rebound['preview_hash'],
        'D05 draft version increment invalidates the pre-apply preview');
    $story['metadata']['content'] = '手工改过但未重新生成正式字段';
    Db::name('aigc_short_drama_canvas')->where('id', $canvas['id'])->update([
        'nodes_json' => json_encode([$story, $deleted, $unrelated], JSON_UNESCAPED_UNICODE),
    ]);
    $editedProseRejected = false;
    try { ShortDramaCanvasWritebackService::previewStory($tenant, $owner, $previewRequest); }
    catch (Exception $e) { $editedProseRejected = str_contains($e->getMessage(), '不适合正式写回'); }
    agentCheck($editedProseRejected, 'D04 edited free prose cannot reuse stale structured formal fields');
    $episodeSource = ['id' => 7104, 'type' => 'text', 'title' => '第一集剧本', 'metadata' => [
        'content' => '第一集真实剧本', 'content_revision' => 1,
        'workflow_source_stage' => 'script', 'workflow_artifact' => 'episode_script',
        'workflow_formal_content_hash' => hash('sha256', '第一集真实剧本'),
        'workflow_formal_fields' => ['episode_number' => 1, 'title' => '雨夜录音',
            'story_outline' => '林夏在雨夜发现录音线索', 'scene_script' => '雨夜办公室，林夏播放录音。'],
    ]];
    Db::name('aigc_short_drama_canvas')->where('id', $canvas['id'])->update([
        'nodes_json' => json_encode([$story, $episodeSource, $deleted, $unrelated], JSON_UNESCAPED_UNICODE),
    ]);
    $outlineProject = Db::name('aigc_short_drama_project')->insertGetId([
        'tenant_id' => $tenant, 'user_id' => $owner, 'title' => 'P5 outline series',
        'multi_episode' => 1, 'episode_count' => 2, 'status' => 'plan_review',
        'last_task_id' => 'p5-outline-task', 'create_time' => $now, 'update_time' => $now, 'delete_time' => 0,
    ]);
    Db::name('aigc_short_drama_script_task')->insert([
        'tenant_id' => $tenant, 'user_id' => $owner, 'project_id' => $outlineProject,
        'task_id' => 'p5-outline-task', 'status' => 'success',
        'request_json' => json_encode(['workflow_variant' => 'story_outline_v2', 'multi_episode' => 1,
            'episode_count' => 2, 'multi_episode_stage' => 'episodes'], JSON_UNESCAPED_UNICODE),
        'result_json' => json_encode(['episodes' => [
            ['episode_number' => 1, 'title' => '旧第一集', 'story_outline' => '旧第一集大纲', 'conflict_point' => '旧冲突', 'ending_hook' => '旧悬念'],
            ['episode_number' => 2, 'title' => '旧第二集', 'story_outline' => '旧第二集大纲', 'conflict_point' => '旧冲突2', 'ending_hook' => '旧悬念2'],
        ]], JSON_UNESCAPED_UNICODE),
        'create_time' => $now, 'update_time' => $now, 'delete_time' => 0,
    ]);
    ShortDramaCanvasBindingService::bind($tenant, $owner, [
        'canvas_id' => $canvas['id'], 'project_id' => $outlineProject,
    ]);
    $outlineRequest = ['canvas_id' => $canvas['id'], 'source_node_id' => 7104, 'target_field' => 'story_outline'];
    $outlinePreview = ShortDramaCanvasWritebackService::previewEpisode($tenant, $owner, $outlineRequest);
    agentCheck($outlinePreview['source']['episode_number'] === 1
        && $outlinePreview['source']['content'] === '林夏在雨夜发现录音线索'
        && $outlinePreview['target']['content'] === '旧第一集大纲',
        'D04 episode preview maps exact source episode number and field');
    $originalOutlineResult = (string)Db::name('aigc_short_drama_script_task')->where('task_id', 'p5-outline-task')->value('result_json');
    $changedOutlineResult = json_decode($originalOutlineResult, true);
    $changedOutlineResult['episodes'][0]['story_outline'] = '目标大纲已经变化';
    Db::name('aigc_short_drama_script_task')->where('task_id', 'p5-outline-task')->update([
        'result_json' => json_encode($changedOutlineResult, JSON_UNESCAPED_UNICODE),
    ]);
    $outlineConflict = false;
    try { ShortDramaCanvasWritebackService::applyEpisode($tenant, $owner, $outlineRequest + [
        'preview_hash' => $outlinePreview['preview_hash'], 'confirm' => '1',
    ]); }
    catch (Exception $e) { $outlineConflict = str_contains($e->getMessage(), 'VERSION_CONFLICT'); }
    agentCheck($outlineConflict, 'D05 episode target change rejects stale preview before write');
    Db::name('aigc_short_drama_script_task')->where('task_id', 'p5-outline-task')->update([
        'result_json' => $originalOutlineResult,
    ]);
    $outlineApplied = ShortDramaCanvasWritebackService::applyEpisode($tenant, $owner, $outlineRequest + [
        'preview_hash' => $outlinePreview['preview_hash'], 'confirm' => '1',
    ]);
    $outlineSaved = json_decode((string)Db::name('aigc_short_drama_script_task')->where('task_id', 'p5-outline-task')->value('request_json'), true);
    agentCheck($outlineApplied['episode_number'] === 1 && $outlineApplied['draft_version'] === 1
        && ($outlineSaved['_story_draft']['result']['episodes'][0]['story_outline'] ?? '') === '林夏在雨夜发现录音线索'
        && ($outlineSaved['_story_draft']['result']['episodes'][1]['story_outline'] ?? '') === '旧第二集大纲',
        'D04 episode apply updates only the chosen outline cell and retains other episode');
    $outlineReplay = ShortDramaCanvasWritebackService::applyEpisode($tenant, $owner, $outlineRequest + [
        'preview_hash' => $outlinePreview['preview_hash'], 'confirm' => '1',
    ]);
    agentCheck($outlineReplay === $outlineApplied,
        'D09 repeated episode apply is idempotent');

    $shotSource = ['id' => 7105, 'type' => 'image', 'title' => '第一镜分镜图', 'metadata' => [
        'prompt' => '雨夜办公室，林夏查看一盘旧录音带。', 'content_revision' => 2,
        'workflow_source_stage' => 'storyboard', 'workflow_artifact' => 'storyboard',
    ]];
    Db::name('aigc_short_drama_canvas')->where('id', $canvas['id'])->update([
        'nodes_json' => json_encode([$story, $episodeSource, $shotSource, $deleted, $unrelated], JSON_UNESCAPED_UNICODE),
    ]);
    Db::name('aigc_short_drama_project')->where('id', $production)->update(['last_task_id' => 'p5-shot-task']);
    Db::name('aigc_short_drama_script_task')->insert([
        'tenant_id' => $tenant, 'user_id' => $owner, 'project_id' => $production,
        'task_id' => 'p5-shot-task', 'status' => 'success', 'request_json' => '{}',
        'result_json' => json_encode(['title' => '第一集', 'storyboard' => [
            ['shot_id' => 'shot-1', 'title' => '旧镜头', 'visual_description' => '旧画面描述'],
        ]], JSON_UNESCAPED_UNICODE),
        'create_time' => $now, 'update_time' => $now, 'delete_time' => 0,
    ]);
    Db::name('aigc_short_drama_storyboard')->insert([
        'tenant_id' => $tenant, 'user_id' => $owner, 'project_id' => $production,
        'task_id' => 'p5-shot-task', 'shot_id' => 'shot-1', 'title' => '旧镜头',
        'visual_description' => '旧画面描述', 'composition' => '远景',
        'subject_ref_ids' => '[]', 'sort' => 1, 'create_time' => $now,
        'update_time' => $now, 'delete_time' => 0,
    ]);
    ShortDramaCanvasBindingService::bind($tenant, $owner, [
        'canvas_id' => $canvas['id'], 'project_id' => $parent, 'episode_id' => $episode,
    ]);
    $shotSources = ShortDramaCanvasWritebackService::sources($tenant, $owner, (int)$canvas['id']);
    agentCheck(count($shotSources['shot_targets']) === 1
        && $shotSources['shot_targets'][0]['shot_id'] === 'shot-1'
        && count(array_filter($shotSources['sources'], static fn(array $source): bool =>
            $source['node_id'] === '7105' && $source['writeback_fields'] === ['visual_description'])) === 1,
        'D06 real storyboard source and owned formal shot are discoverable after episode binding');
    $shotRequest = ['canvas_id' => $canvas['id'], 'source_node_id' => 7105, 'target_shot_id' => 'shot-1'];
    $shotPreview = ShortDramaCanvasWritebackService::previewShot($tenant, $owner, $shotRequest);
    agentCheck($shotPreview['source']['content'] === '雨夜办公室，林夏查看一盘旧录音带。'
        && $shotPreview['target']['content'] === '旧画面描述'
        && $shotPreview['target']['project_id'] === $production
        && $shotPreview['prompt_policy'] === 'rebuild_from_shot_fields',
        'D06 shot preview compares selected source with selected formal visual description');
    $shotConfirmRejected = false;
    try { ShortDramaCanvasWritebackService::applyShot($tenant, $owner, $shotRequest + [
        'preview_hash' => $shotPreview['preview_hash'],
    ]); }
    catch (Exception $e) { $shotConfirmRejected = str_contains($e->getMessage(), '逐镜确认'); }
    agentCheck($shotConfirmRejected, 'D06 shot writeback requires explicit confirmation');
    Db::name('aigc_short_drama_storyboard')->where('project_id', $production)->where('shot_id', 'shot-1')
        ->update(['visual_description' => '目标镜头已修改']);
    $shotStaleRejected = false;
    try { ShortDramaCanvasWritebackService::applyShot($tenant, $owner, $shotRequest + [
        'preview_hash' => $shotPreview['preview_hash'], 'confirm' => '1',
    ]); }
    catch (Exception $e) { $shotStaleRejected = str_contains($e->getMessage(), 'VERSION_CONFLICT'); }
    agentCheck($shotStaleRejected, 'D07 changed formal shot rejects stale preview');
    Db::name('aigc_short_drama_storyboard')->where('project_id', $production)->where('shot_id', 'shot-1')
        ->update(['visual_description' => '旧画面描述']);
    $shotApplied = ShortDramaCanvasWritebackService::applyShot($tenant, $owner, $shotRequest + [
        'preview_hash' => $shotPreview['preview_hash'], 'confirm' => '1',
    ]);
    $savedShot = Db::name('aigc_short_drama_storyboard')->where('project_id', $production)->where('shot_id', 'shot-1')->find();
    agentCheck($shotApplied['applied'] === true && $savedShot['visual_description'] === '雨夜办公室，林夏查看一盘旧录音带。'
        && $savedShot['composition'] === '远景' && $savedShot['image_prompt'] !== ''
        && $savedShot['video_prompt'] !== '',
        'D06 selected visual description is saved and prompts are rebuilt without clearing other shot fields');
    $shotReplay = ShortDramaCanvasWritebackService::applyShot($tenant, $owner, $shotRequest + [
        'preview_hash' => $shotPreview['preview_hash'], 'confirm' => '1',
    ]);
    agentCheck($shotReplay === $shotApplied, 'D09 repeated shot apply returns original receipt');
    $foreignPreviewRejected = false;
    try { ShortDramaCanvasWritebackService::previewStory($otherTenant, $otherUser, $previewRequest); }
    catch (Exception $e) { $foreignPreviewRejected = $e->getMessage() === '画布项目不存在或无权访问'; }
    agentCheck($foreignPreviewRejected, 'D02 foreign tenant cannot preview formal writeback');

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

echo "NOT_RUN D06-D08,D10 remaining: shot adapter and browser confirmation against a real editable target; story and episode fields are covered by rollback-only local behavior checks.\n";
