<?php

namespace app\common\service\app\aigc_canvas;

use app\common\model\app\App;
use app\common\model\app\TenantApp;
use app\common\model\app\TenantAppConfig;
use app\common\model\app\aigc_canvas\AigcCanvasProject;
use app\common\model\app\aigc_canvas\AigcCanvasRun;
use app\common\model\app\aigc_short_drama\AigcShortDramaAsset;
use app\common\service\app\AppAccessService;
use app\common\service\app\AppDisplayConfigService;
use app\common\service\app\AppRegistryService;
use app\common\service\FileService;
use app\common\service\app\aigc_image\AigcImageAssetService;
use app\common\service\app\aigc_image\AigcImageService;
use app\common\service\app\aigc_llm\AigcLlmService;
use app\common\service\app\aigc_music\AigcMusicService;
use app\common\service\app\aigc_video\AigcVideoReferenceAssetService;
use app\common\service\app\aigc_video\AigcVideoService;
use Exception;
use think\facade\Db;

class AigcCanvasService
{
    public const APP_CODE = 'aigc_canvas';
    public const IMAGE_APP_CODE = 'aigc_image';
    public const LLM_APP_CODE = 'aigc_llm';
    public const MUSIC_APP_CODE = 'aigc_music';
    public const VIDEO_APP_CODE = 'aigc_video';
    public const SHORT_DRAMA_APP_CODE = 'aigc_short_drama';

    public static function dependencies(int $tenantId = 0): array
    {
        $items = [
            self::dependencyItem($tenantId, self::MUSIC_APP_CODE, 'AI音乐', '音乐生成'),
            self::dependencyItem($tenantId, self::LLM_APP_CODE, 'AIGC对话', '文本生成'),
            self::dependencyItem($tenantId, self::IMAGE_APP_CODE, 'AIGC生图', '图片生成'),
            self::dependencyItem($tenantId, self::VIDEO_APP_CODE, 'AIGC视频', '视频生成'),
        ];
        return [
            'items' => $items,
            'ready' => count(array_filter($items, fn($item) => !empty($item['ready']))) === count($items),
        ];
    }

    public static function config(int $tenantId): array
    {
        return AppDisplayConfigService::appendToConfig($tenantId, self::APP_CODE, [
            'app_code' => self::APP_CODE,
            'name' => '无限画布',
            'storage' => 'backend',
            'proxy_enabled' => true,
            'text' => self::textConfig($tenantId),
            'agent' => self::agentConfig($tenantId),
            'dependencies' => self::dependencies($tenantId),
            'agent_dependencies' => [
                'short_drama' => self::dependencyItem($tenantId, self::SHORT_DRAMA_APP_CODE, 'AI短剧', '剧本、角色和分镜 Agent'),
            ],
            'image' => AigcImageService::config($tenantId),
            'video' => AigcVideoService::config($tenantId),
            'music' => AigcMusicService::config($tenantId),
        ]);
    }

    public static function saveConfig(int $tenantId, array $params): void
    {
        AppDisplayConfigService::saveFromConfigPayload($tenantId, self::APP_CODE, $params);
        if (isset($params['agent']) && is_array($params['agent'])) {
            self::saveAgentConfig($tenantId, $params['agent']);
        }
    }

    public static function agentConfig(int $tenantId): array
    {
        $text = self::textConfig($tenantId);
        $models = array_values((array)($text['models'] ?? []));
        $defaultModel = (string)($text['defaults']['model'] ?? ($models[0]['code'] ?? ''));
        $saved = self::savedAgentConfig($tenantId);
        $savedModel = (string)($saved['router_model_code'] ?? '');
        $modelCodes = array_map(fn($model) => (string)($model['code'] ?? ''), $models);
        $routerModelCode = $savedModel !== '' && in_array($savedModel, $modelCodes, true)
            ? $savedModel
            : $defaultModel;
        return [
            'router_enabled' => (bool)($saved['router_enabled'] ?? true),
            'router_model_code' => $routerModelCode,
            'router_models' => $models,
            'router_available' => !empty($text['enabled']) && $routerModelCode !== '',
            'message' => !empty($text['enabled']) ? '可用' : (string)($text['message'] ?? '暂无可用 Agent 模型'),
        ];
    }

    private static function saveAgentConfig(int $tenantId, array $params): void
    {
        $current = AppDisplayConfigService::detail($tenantId, self::APP_CODE);
        $extra = is_array($current['extra'] ?? null) ? $current['extra'] : [];
        $extra['agent'] = [
            'router_enabled' => (bool)($params['router_enabled'] ?? true),
            'router_model_code' => trim((string)($params['router_model_code'] ?? '')),
        ];
        AppDisplayConfigService::save($tenantId, self::APP_CODE, array_merge($current, [
            'extra' => $extra,
        ]));
    }

    private static function savedAgentConfig(int $tenantId): array
    {
        $row = TenantAppConfig::where([
            'tenant_id' => $tenantId,
            'app_code' => self::APP_CODE,
        ])->findOrEmpty();
        if ($row->isEmpty()) {
            return [];
        }
        $extra = is_array($row['extra'] ?? null) ? $row['extra'] : [];
        return is_array($extra['agent'] ?? null) ? $extra['agent'] : [];
    }

    public static function projectLists(int $tenantId, int $userId = 0, array $params = []): array
    {
        $query = AigcCanvasProject::where('tenant_id', $tenantId)->where('delete_time', 0);
        if ($userId > 0) {
            $query->where('user_id', $userId);
        }
        $keyword = trim((string)($params['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->whereLike('name', '%' . $keyword . '%');
        }
        $limit = max(1, min(100, (int)($params['limit'] ?? 60)));
        $rows = $query
            ->field([
                'id',
                'tenant_id',
                'user_id',
                'name',
                'thumbnail',
                'nodes_json',
                'sort',
                'status',
                'create_time',
                'update_time',
            ])
            ->fieldRaw("IF(JSON_VALID(COALESCE(nodes_json, '[]')), JSON_LENGTH(COALESCE(nodes_json, '[]')), 0) AS node_count")
            ->fieldRaw("IF(JSON_VALID(COALESCE(edges_json, '[]')), JSON_LENGTH(COALESCE(edges_json, '[]')), 0) AS edge_count")
            ->order(['sort' => 'desc', 'update_time' => 'desc', 'id' => 'desc'])
            ->limit($limit)
            ->select()
            ->toArray();
        return array_map([self::class, 'formatProject'], $rows);
    }

    public static function projectDetail(int $tenantId, int $userId, int $id): array
    {
        $project = self::projectQuery($tenantId, $userId, $id)->findOrEmpty();
        if ($project->isEmpty()) {
            throw new Exception('项目不存在');
        }
        return self::formatProject($project->toArray(), true);
    }

    public static function createProject(int $tenantId, int $userId, array $params): array
    {
        $name = trim((string)($params['name'] ?? ''));
        if ($name === '') {
            $name = '未命名项目';
        }
        $project = AigcCanvasProject::create([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'name' => mb_substr($name, 0, 120),
            'thumbnail' => self::normalizeThumbnail((string)($params['thumbnail'] ?? ''), $tenantId, $userId),
            'nodes_json' => self::normalizeList($params['nodes'] ?? []),
            'edges_json' => self::normalizeList($params['edges'] ?? []),
            'viewport_json' => self::normalizeViewport($params['viewport'] ?? []),
            'sort' => (int)($params['sort'] ?? 0),
            'status' => 1,
            'create_time' => time(),
            'update_time' => time(),
            'delete_time' => 0,
        ]);
        return self::formatProject($project->toArray(), true);
    }

    public static function saveProject(int $tenantId, int $userId, array $params): array
    {
        $id = (int)($params['id'] ?? 0);
        $project = self::projectQuery($tenantId, $userId, $id)->findOrEmpty();
        if ($project->isEmpty()) {
            throw new Exception('项目不存在');
        }
        $data = [
            'nodes_json' => self::normalizeList($params['nodes'] ?? []),
            'edges_json' => self::normalizeList($params['edges'] ?? []),
            'viewport_json' => self::normalizeViewport($params['viewport'] ?? []),
            'thumbnail' => self::normalizeThumbnail(
                (string)($params['thumbnail'] ?? $project['thumbnail'] ?? ''),
                $tenantId,
                $userId,
                (string)($project['thumbnail'] ?? '')
            ),
            'update_time' => time(),
        ];
        if (isset($params['name'])) {
            $name = trim((string)$params['name']);
            if ($name !== '') {
                $data['name'] = mb_substr($name, 0, 120);
            }
        }
        $project->save($data);
        return self::formatProject($project->toArray(), true);
    }

    public static function renameProject(int $tenantId, int $userId, int $id, string $name): array
    {
        $name = trim($name);
        if ($name === '') {
            throw new Exception('请输入项目名称');
        }
        $project = self::projectQuery($tenantId, $userId, $id)->findOrEmpty();
        if ($project->isEmpty()) {
            throw new Exception('项目不存在');
        }
        $project->save([
            'name' => mb_substr($name, 0, 120),
            'update_time' => time(),
        ]);
        return self::formatProject($project->toArray(), true);
    }

    public static function duplicateProject(int $tenantId, int $userId, int $id): array
    {
        $project = self::projectQuery($tenantId, $userId, $id)->findOrEmpty();
        if ($project->isEmpty()) {
            throw new Exception('项目不存在');
        }
        $data = $project->toArray();
        return self::createProject($tenantId, $userId, [
            'name' => ($data['name'] ?? '未命名项目') . ' (副本)',
            'thumbnail' => $data['thumbnail'] ?? '',
            'nodes' => $data['nodes_json'] ?? [],
            'edges' => $data['edges_json'] ?? [],
            'viewport' => $data['viewport_json'] ?? [],
        ]);
    }

    public static function deleteProject(int $tenantId, int $userId, int $id): void
    {
        $project = self::projectQuery($tenantId, $userId, $id)->findOrEmpty();
        if ($project->isEmpty()) {
            throw new Exception('项目不存在');
        }
        $project->save([
            'delete_time' => time(),
            'update_time' => time(),
        ]);
        AigcCanvasRun::where(['tenant_id' => $tenantId, 'project_id' => $id])->update([
            'delete_time' => time(),
            'update_time' => time(),
        ]);
    }

    public static function adminDeleteProject(int $tenantId, int $id): void
    {
        self::deleteProject($tenantId, 0, $id);
    }

    public static function clearProjects(int $tenantId, int $userId = 0): int
    {
        $query = AigcCanvasProject::where('tenant_id', $tenantId)->where('delete_time', 0);
        if ($userId > 0) {
            $query->where('user_id', $userId);
        }
        $ids = $query->column('id');
        if (empty($ids)) {
            return 0;
        }
        AigcCanvasProject::whereIn('id', $ids)->update(['delete_time' => time(), 'update_time' => time()]);
        AigcCanvasRun::where('tenant_id', $tenantId)->whereIn('project_id', $ids)->update(['delete_time' => time(), 'update_time' => time()]);
        return count($ids);
    }

    public static function runLists(int $tenantId, int $userId = 0, array $params = []): array
    {
        $query = AigcCanvasRun::alias('r')
            ->leftJoin('user u', 'u.id = r.user_id AND u.tenant_id = r.tenant_id')
            ->leftJoin('aigc_canvas_project p', 'p.id = r.project_id AND p.tenant_id = r.tenant_id')
            ->field('r.*,u.nickname user_nickname,u.account user_account,u.mobile user_mobile,p.name project_name')
            ->where('r.tenant_id', $tenantId)
            ->where('r.delete_time', 0);
        if ($userId > 0) {
            $query->where('r.user_id', $userId);
        }
        if (!empty($params['project_id'])) {
            $query->where('r.project_id', (int)$params['project_id']);
        }
        $runType = trim((string)($params['run_type'] ?? ''));
        if ($runType !== '') {
            $query->where('r.run_type', $runType);
        }
        $status = trim((string)($params['status'] ?? ''));
        if ($status !== '' && $status !== 'all') {
            $query->where('r.status', $status);
        }
        $taskId = (int)($params['task_id'] ?? 0);
        if ($taskId > 0) {
            $query->where(function ($query) use ($taskId) {
                $query->where('r.id', $taskId)->whereOr('r.source_task_id', $taskId);
            });
        }
        $sourceTaskId = (int)($params['source_task_id'] ?? 0);
        if ($sourceTaskId > 0) {
            $query->where('r.source_task_id', $sourceTaskId);
        }
        $userKeyword = trim((string)($params['user_keyword'] ?? ''));
        if ($userKeyword !== '') {
            $query->where(function ($query) use ($userKeyword) {
                $query->whereLike('u.nickname', '%' . $userKeyword . '%')
                    ->whereOrLike('u.account', '%' . $userKeyword . '%')
                    ->whereOrLike('u.mobile', '%' . $userKeyword . '%');
                if (ctype_digit($userKeyword)) {
                    $query->whereOr('r.user_id', (int)$userKeyword);
                }
            });
        }
        $usePage = isset($params['page_no']) || isset($params['page_size']);
        $pageNo = max(1, (int)($params['page_no'] ?? 1));
        $pageSize = max(1, min(100, (int)($params['page_size'] ?? 15)));
        $count = $usePage ? (int)(clone $query)->count() : 0;
        if ($usePage) {
            $query->limit(($pageNo - 1) * $pageSize, $pageSize);
        } else {
            $query->limit(max(1, min(100, (int)($params['limit'] ?? 80))));
        }
        $rows = array_map(static fn(array $row): array => self::formatRun($row, true), $query->order('r.id', 'desc')->select()->toArray());
        if ($usePage) {
            return [
                'lists' => $rows,
                'count' => $count,
                'page_no' => $pageNo,
                'page_size' => $pageSize,
            ];
        }
        return $rows;
    }

    public static function runDetail(int $tenantId, int $id): array
    {
        $run = AigcCanvasRun::where(['tenant_id' => $tenantId, 'id' => $id])->where('delete_time', 0)->findOrEmpty();
        if ($run->isEmpty()) {
            throw new Exception('任务不存在');
        }
        $runType = (string)$run['run_type'];
        $sourceTaskId = (int)$run['source_task_id'];
        if ($sourceTaskId <= 0) {
            throw new Exception('源任务不存在，无法查询');
        }
        if ($runType === 'image') {
            $detail = self::imageTaskDetail($tenantId, 0, $sourceTaskId);
        } elseif ($runType === 'video') {
            $detail = self::videoTaskDetail($tenantId, 0, $sourceTaskId);
        } elseif ($runType === 'music') {
            $detail = self::musicTaskDetail($tenantId, 0, $sourceTaskId);
        } else {
            throw new Exception('该任务类型不支持查询');
        }
        $status = (string)($detail['status'] ?? $run['status']);
        $now = time();
        $finishTime = in_array($status, ['success', 'failed', 'canceled'], true) ? ((int)$run['finish_time'] ?: $now) : (int)$run['finish_time'];
        $run->save([
            'status' => $status,
            'result_json' => self::sanitizeRunPayload($detail),
            'error' => (string)($detail['error'] ?? $detail['fail_reason'] ?? $run['error'] ?? ''),
            'update_time' => $now,
            'finish_time' => $finishTime,
        ]);
        $data = $run->toArray();
        $data['source_detail'] = $detail;
        return self::formatRun($data);
    }

    public static function stat(int $tenantId = 0): array
    {
        $runQuery = AigcCanvasRun::where('delete_time', 0);
        if ($tenantId > 0) {
            $runQuery->where('tenant_id', $tenantId);
        }
        $runs = (clone $runQuery)->field('status,run_type,count(*) as total')->group('status,run_type')->select()->toArray();
        $runTotal = 0;
        $success = 0;
        $failed = 0;
        $image = 0;
        $video = 0;
        $music = 0;
        $runUserTotal = (int)((clone $runQuery)->distinct(true)->count('user_id') ?: 0);
        $recentRunTime = (int)((clone $runQuery)->max('update_time') ?: 0);
        foreach ($runs as $row) {
            $count = (int)$row['total'];
            $runTotal += $count;
            if (($row['status'] ?? '') === 'success') {
                $success += $count;
            }
            if (($row['status'] ?? '') === 'failed') {
                $failed += $count;
            }
            if (($row['run_type'] ?? '') === 'image') {
                $image += $count;
            }
            if (($row['run_type'] ?? '') === 'video') {
                $video += $count;
            }
            if (($row['run_type'] ?? '') === 'music') {
                $music += $count;
            }
        }
        return [
            'run_total' => $runTotal,
            'run_user_total' => $runUserTotal,
            'run_success' => $success,
            'run_failed' => $failed,
            'image_run_total' => $image,
            'video_run_total' => $video,
            'music_run_total' => $music,
            'recent_run_time' => $recentRunTime,
            'dependencies' => self::dependencies($tenantId),
        ];
    }

    public static function tenantUsageLists(array $params = []): array
    {
        $tenantId = (int)($params['tenant_id'] ?? 0);
        $query = AigcCanvasRun::where('delete_time', 0);
        if ($tenantId > 0) {
            $query->where('tenant_id', $tenantId);
        }
        return $query
            ->field('tenant_id,count(*) as run_total,count(distinct user_id) as run_user_total,max(update_time) as last_run_time')
            ->fieldRaw("SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) AS run_success")
            ->fieldRaw("SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS run_failed")
            ->group('tenant_id')
            ->order('last_run_time', 'desc')
            ->limit(100)
            ->select()
            ->toArray();
    }

    public static function generateImage(int $tenantId, int $userId, array $params): array
    {
        self::assertDependencyReady($tenantId, self::IMAGE_APP_CODE);
        $params = self::prepareMentionParams($params);
        $started = microtime(true);
        $run = self::createRun($tenantId, $userId, $params, 'image', self::IMAGE_APP_CODE);
        try {
            $result = AigcImageService::generate($tenantId, $userId, self::normalizeImageParams($params, $tenantId, $userId));
            self::finishRun($run, $result, self::normalizeRunStatus((string)($result['status'] ?? ''), $result), (string)($result['error'] ?? ''), $started);
            return $result;
        } catch (Exception $e) {
            self::finishRun($run, [], 'failed', $e->getMessage(), $started);
            throw $e;
        }
    }

    public static function generateVideo(int $tenantId, int $userId, array $params): array
    {
        self::assertDependencyReady($tenantId, self::VIDEO_APP_CODE);
        $params = self::prepareMentionParams($params);
        $started = microtime(true);
        $run = self::createRun($tenantId, $userId, $params, 'video', self::VIDEO_APP_CODE);
        try {
            $result = AigcVideoService::generate($tenantId, $userId, self::normalizeVideoParams($params, $tenantId, $userId));
            self::finishRun($run, $result, self::normalizeRunStatus((string)($result['status'] ?? ''), $result), (string)($result['error'] ?? ''), $started);
            return $result;
        } catch (Exception $e) {
            self::finishRun($run, [], 'failed', $e->getMessage(), $started);
            throw $e;
        }
    }

    public static function generateMusic(int $tenantId, int $userId, array $params): array
    {
        self::assertDependencyReady($tenantId, self::MUSIC_APP_CODE);
        $params = self::prepareMentionParams($params);
        $started = microtime(true);
        $run = self::createRun($tenantId, $userId, $params, 'music', self::MUSIC_APP_CODE);
        try {
            $result = AigcMusicService::generate($tenantId, $userId, self::normalizeMusicParams($params));
            self::finishRun($run, $result, self::normalizeRunStatus((string)($result['status'] ?? ''), $result), (string)($result['error'] ?? ''), $started);
            return $result;
        } catch (Exception $e) {
            self::finishRun($run, [], 'failed', $e->getMessage(), $started);
            throw $e;
        }
    }

    public static function generateText(int $tenantId, int $userId, array $params): array
    {
        self::assertDependencyReady($tenantId, self::LLM_APP_CODE);
        $params = self::prepareMentionParams($params);
        $started = microtime(true);
        $run = self::createRun($tenantId, $userId, $params, 'text', self::LLM_APP_CODE);
        try {
            $referenceImages = self::normalizeReferenceImages($params, $tenantId, $userId, false);
            $prompt = self::resolveTextPrompt($params, $referenceImages);
            if ($prompt === '') {
                throw new Exception('请填写系统提示词或补充输入，或连接文本节点/参考图');
            }
            $result = AigcLlmService::generateText($tenantId, $userId, [
                'content' => $prompt,
                'system_prompt' => (string)($params['system_prompt'] ?? ''),
                'model_code' => (string)($params['model_code'] ?? $params['model'] ?? ''),
                'reference_images' => $referenceImages,
                'source_app_code' => self::APP_CODE,
                'source_type' => 'canvas_text',
                'source_id' => (string)($params['node_id'] ?? ''),
                'tools' => is_array($params['tools'] ?? null) ? $params['tools'] : [],
                'tool_choice' => $params['tool_choice'] ?? null,
                'response_format' => is_array($params['response_format'] ?? null) ? $params['response_format'] : [],
            ]);
            self::finishRun($run, $result, 'success', '', $started);
            return $result;
        } catch (Exception $e) {
            self::finishRun($run, [], 'failed', $e->getMessage(), $started);
            throw $e;
        }
    }

    public static function streamText(int $tenantId, int $userId, array $params, ?callable $onEvent = null): array
    {
        self::assertDependencyReady($tenantId, self::LLM_APP_CODE);
        $params = self::prepareMentionParams($params);
        $started = microtime(true);
        $run = self::createRun($tenantId, $userId, $params, 'text', self::LLM_APP_CODE);
        try {
            $referenceImages = self::normalizeReferenceImages($params, $tenantId, $userId, false);
            $prompt = self::resolveTextPrompt($params, $referenceImages);
            if ($prompt === '') {
                throw new Exception('请填写系统提示词或补充输入，或连接文本节点/参考图');
            }
            $result = AigcLlmService::streamText($tenantId, $userId, [
                'content' => $prompt,
                'system_prompt' => (string)($params['system_prompt'] ?? ''),
                'model_code' => (string)($params['model_code'] ?? $params['model'] ?? ''),
                'reference_images' => $referenceImages,
                'source_app_code' => self::APP_CODE,
                'source_type' => (string)($params['source_type'] ?? 'canvas_text'),
                'source_id' => (string)($params['node_id'] ?? $params['source_id'] ?? ''),
                'tools' => is_array($params['tools'] ?? null) ? $params['tools'] : [],
                'tool_choice' => $params['tool_choice'] ?? null,
                'response_format' => is_array($params['response_format'] ?? null) ? $params['response_format'] : [],
            ], $onEvent);
            self::finishRun($run, $result, 'success', '', $started);
            return $result;
        } catch (Exception $e) {
            self::finishRun($run, [], 'failed', $e->getMessage(), $started);
            throw $e;
        }
    }

    public static function videoTaskDetail(int $tenantId, int $userId, int $taskId): array
    {
        self::assertDependencyReady($tenantId, self::VIDEO_APP_CODE);
        $detail = AigcVideoService::taskDetail($tenantId, $taskId, $userId);
        self::syncRunsForSourceTask($tenantId, 'video', $taskId, $detail);
        return $detail;
    }

    public static function musicTaskDetail(int $tenantId, int $userId, int $taskId): array
    {
        self::assertDependencyReady($tenantId, self::MUSIC_APP_CODE);
        $detail = AigcMusicService::taskDetail($tenantId, $taskId, $userId);
        self::syncRunsForSourceTask($tenantId, 'music', $taskId, $detail);
        return $detail;
    }

    public static function imageTaskDetail(int $tenantId, int $userId, int $taskId): array
    {
        self::assertDependencyReady($tenantId, self::IMAGE_APP_CODE);
        $detail = AigcImageService::taskDetail($tenantId, $taskId, $userId);
        self::syncRunsForSourceTask($tenantId, 'image', $taskId, $detail);
        return $detail;
    }

    public static function clearAllBusinessData(): void
    {
        AigcCanvasProject::where('id', '>', 0)->delete();
        AigcCanvasRun::where('id', '>', 0)->delete();
    }

    private static function dependencyItem(int $tenantId, string $appCode, string $name, string $requiredFor): array
    {
        $installed = App::where(['code' => $appCode, 'status' => AppRegistryService::STATUS_INSTALLED])->count() > 0;
        $tenantEnabled = $tenantId <= 0 ? true : AppAccessService::tenantCanUse($tenantId, $appCode);
        $config = [];
        try {
            if ($appCode === self::IMAGE_APP_CODE) {
                $config = AigcImageService::config($tenantId);
            } elseif ($appCode === self::VIDEO_APP_CODE) {
                $config = AigcVideoService::config($tenantId);
            } elseif ($appCode === self::MUSIC_APP_CODE) {
                $config = AigcMusicService::config($tenantId);
            } else {
                $config = AigcLlmService::config($tenantId);
            }
        } catch (Exception) {
            $config = [];
        }
        $channels = $config['option_config']['channels'] ?? $config['models'] ?? [];
        return [
            'app_code' => $appCode,
            'name' => $name,
            'required_for' => $requiredFor,
            'installed' => $installed,
            'tenant_enabled' => $tenantEnabled,
            'channel_ready' => !empty($channels),
            'ready' => $installed && $tenantEnabled && !empty($channels),
            'message' => $installed ? ($tenantEnabled ? (!empty($channels) ? '可用' : '暂无可用通道') : '租户未开通或未上架') : '应用未安装或未启用',
        ];
    }

    private static function textConfig(int $tenantId): array
    {
        try {
            self::assertDependencyReady($tenantId, self::LLM_APP_CODE);
            $config = AigcLlmService::config($tenantId);
            return [
                'enabled' => true,
                'models' => $config['option_config']['models'] ?? [],
                'defaults' => $config['option_config']['defaults'] ?? [],
                'message' => '可用',
            ];
        } catch (Exception $e) {
            return [
                'enabled' => false,
                'models' => [],
                'defaults' => [],
                'message' => $e->getMessage(),
            ];
        }
    }

    private static function assertDependencyReady(int $tenantId, string $appCode): void
    {
        $dependency = self::dependencyItem($tenantId, $appCode, $appCode, '');
        if (!$dependency['ready']) {
            throw new Exception($dependency['message']);
        }
    }

    private static function projectQuery(int $tenantId, int $userId, int $id)
    {
        $query = AigcCanvasProject::where(['tenant_id' => $tenantId, 'id' => $id])->where('delete_time', 0);
        if ($userId > 0) {
            $query->where('user_id', $userId);
        }
        return $query;
    }

    private static function normalizeList($value): array
    {
        return is_array($value) ? array_values($value) : [];
    }

    private static function normalizeViewport($value): array
    {
        if (!is_array($value)) {
            return ['x' => 100, 'y' => 50, 'zoom' => 0.82];
        }
        return [
            'x' => (float)($value['x'] ?? 100),
            'y' => (float)($value['y'] ?? 50),
            'zoom' => (float)($value['zoom'] ?? 0.82),
        ];
    }

    private static function formatProject(array $row, bool $full = false): array
    {
        $nodeCount = array_key_exists('node_count', $row)
            ? max(0, (int)$row['node_count'])
            : count(self::normalizeList($row['nodes_json'] ?? []));
        $edgeCount = array_key_exists('edge_count', $row)
            ? max(0, (int)$row['edge_count'])
            : count(self::normalizeList($row['edges_json'] ?? []));
        $nodes = self::normalizeList($row['nodes_json'] ?? []);
        $nodeActivityTime = self::latestProjectNodeTime($nodes);
        $createTime = (int)($row['create_time'] ?? 0);
        $updateTime = (int)($row['update_time'] ?? 0);
        if ($updateTime <= 0) {
            $updateTime = $nodeActivityTime ?: $createTime;
        }
        if ($createTime <= 0) {
            $createTime = $updateTime ?: $nodeActivityTime;
        }
        $generatedThumbnail = self::firstGeneratedProjectImageThumbnail($nodes);
        $data = [
            'id' => (int)$row['id'],
            'name' => (string)($row['name'] ?? '未命名项目'),
            'thumbnail' => self::formatThumbnail($generatedThumbnail ?: (string)($row['thumbnail'] ?? '')),
            'node_count' => $nodeCount,
            'edge_count' => $edgeCount,
            'createdAt' => $createTime * 1000,
            'updatedAt' => $updateTime * 1000,
            'create_time' => $createTime,
            'update_time' => $updateTime,
            'tenant_id' => (int)($row['tenant_id'] ?? 0),
            'user_id' => (int)($row['user_id'] ?? 0),
            'sort' => (int)($row['sort'] ?? 0),
            'status' => (int)($row['status'] ?? 1),
        ];
        if ($full) {
            $data['nodes'] = self::repairProjectNodesFromRuns(
                (int)($row['tenant_id'] ?? 0),
                (int)($row['id'] ?? 0),
                self::normalizeList($row['nodes_json'] ?? [])
            );
            $data['edges'] = self::normalizeList($row['edges_json'] ?? []);
            $data['viewport'] = self::normalizeViewport($row['viewport_json'] ?? []);
            $data['registered_assets'] = self::projectRegisteredAssets((int)$row['tenant_id'], (int)$row['user_id'], (int)$row['id']);
        }
        return $data;
    }

    private static function firstGeneratedProjectImageThumbnail(array $nodes): string
    {
        $candidates = [];
        foreach ($nodes as $index => $node) {
            if (!is_array($node) || (string)($node['type'] ?? '') !== 'image') {
                continue;
            }
            $metadata = is_array($node['metadata'] ?? null) ? $node['metadata'] : [];
            $url = self::nodeMetadataMediaUrl('image', $metadata);
            if ($url === '') {
                continue;
            }
            $source = strtolower((string)($metadata['source'] ?? $metadata['mediaSource'] ?? ''));
            if (empty($metadata['generatedAt']) && !in_array($source, ['generated', 'agent'], true)) {
                continue;
            }
            $time = (float)($metadata['generatedAt'] ?? $metadata['createdAt'] ?? $metadata['updatedAt'] ?? 0);
            $candidates[] = [
                'url' => $url,
                'time' => $time > 0 ? $time : $index,
                'index' => $index,
            ];
        }
        if (empty($candidates)) {
            return '';
        }
        usort($candidates, static function (array $left, array $right): int {
            $timeCompare = $left['time'] <=> $right['time'];
            return $timeCompare !== 0 ? $timeCompare : ($left['index'] <=> $right['index']);
        });
        return (string)($candidates[0]['url'] ?? '');
    }

    private static function repairProjectNodesFromRuns(int $tenantId, int $projectId, array $nodes): array
    {
        if ($tenantId <= 0 || $projectId <= 0 || empty($nodes)) {
            return $nodes;
        }

        $nodeIds = [];
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            $type = (string)($node['type'] ?? '');
            $id = trim((string)($node['id'] ?? ''));
            if ($id !== '' && in_array($type, ['image', 'video', 'audio'], true)) {
                $nodeIds[] = $id;
            }
        }
        $nodeIds = array_values(array_unique($nodeIds));
        if (empty($nodeIds)) {
            return $nodes;
        }

        $runs = AigcCanvasRun::where([
            'tenant_id' => $tenantId,
            'project_id' => $projectId,
            'delete_time' => 0,
        ])
            ->whereIn('node_id', $nodeIds)
            ->whereIn('run_type', ['image', 'video', 'music'])
            ->order('id', 'desc')
            ->limit(300)
            ->select()
            ->toArray();

        $runByNode = [];
        foreach ($runs as $run) {
            $nodeId = trim((string)($run['node_id'] ?? ''));
            if ($nodeId === '' || isset($runByNode[$nodeId])) {
                continue;
            }
            $formatted = self::formatRun($run, true);
            if ((string)($formatted['status'] ?? '') !== 'success') {
                continue;
            }
            $runType = (string)($formatted['run_type'] ?? $run['run_type'] ?? '');
            $url = (string)($formatted['file_url'] ?? $formatted['image_url'] ?? $formatted['video_url'] ?? $formatted['audio_url'] ?? '');
            if ($url === '') {
                continue;
            }
            $runByNode[$nodeId] = [
                'run_type' => $runType,
                'url' => $url,
                'source_task_id' => (int)($formatted['source_task_id'] ?? 0),
                'update_time' => (int)($formatted['update_time'] ?? time()),
            ];
        }
        if (empty($runByNode)) {
            return $nodes;
        }

        $changed = false;
        foreach ($nodes as $index => $node) {
            if (!is_array($node)) {
                continue;
            }
            $nodeId = trim((string)($node['id'] ?? ''));
            $type = (string)($node['type'] ?? '');
            $run = $runByNode[$nodeId] ?? null;
            if (!$run || !self::runTypeMatchesNodeType((string)$run['run_type'], $type)) {
                continue;
            }
            $metadata = is_array($node['metadata'] ?? null) ? $node['metadata'] : [];
            if (self::nodeMetadataMediaUrl($type, $metadata) !== '') {
                continue;
            }

            $url = (string)$run['url'];
            if ($type === 'image') {
                $metadata['image'] = $url;
                $metadata['url'] = $url;
            } elseif ($type === 'video') {
                $metadata['url'] = $url;
            } else {
                $metadata['url'] = $url;
            }
            if (empty($metadata['taskId']) && !empty($run['source_task_id'])) {
                $metadata['taskId'] = (string)$run['source_task_id'];
            }
            $metadata['status'] = 'success';
            $metadata['errorDetails'] = '';
            $metadata['error'] = '';
            $metadata['pending'] = false;
            $metadata['mediaSource'] = $metadata['mediaSource'] ?? 'generated';
            $metadata['generatedAt'] = $metadata['generatedAt'] ?? ($run['update_time'] * 1000);
            $nodes[$index]['metadata'] = $metadata;
            $changed = true;
        }

        if ($changed) {
            $project = AigcCanvasProject::where([
                'tenant_id' => $tenantId,
                'id' => $projectId,
                'delete_time' => 0,
            ])->findOrEmpty();
            if (!$project->isEmpty()) {
                $project->save([
                    'nodes_json' => $nodes,
                    'update_time' => time(),
                ]);
            }
        }

        return $nodes;
    }

    private static function runTypeMatchesNodeType(string $runType, string $nodeType): bool
    {
        return ($runType === 'image' && $nodeType === 'image')
            || ($runType === 'video' && $nodeType === 'video')
            || ($runType === 'music' && $nodeType === 'audio');
    }

    private static function nodeMetadataMediaUrl(string $type, array $metadata): string
    {
        $keys = $type === 'image'
            ? ['image', 'url', 'image_url', 'preview_url', 'thumbnail', 'cover']
            : ($type === 'video'
                ? ['url', 'video_url', 'video', 'file_url']
                : ['url', 'audio_url', 'audio', 'file_url']);
        foreach ($keys as $key) {
            $url = trim((string)($metadata[$key] ?? ''));
            if ($url !== '') {
                return $url;
            }
        }
        return '';
    }

    private static function formatRun(array $row, bool $syncSource = false): array
    {
        $params = self::normalizeRunPayload($row['params_json'] ?? []);
        $result = self::normalizeRunPayload($row['result_json'] ?? []);
        $runType = (string)($row['run_type'] ?? '');
        if ($syncSource) {
            $result = self::syncSourceRunResult($row, $runType, $result);
        }
        $data = $row;
        $data['id'] = (int)($row['id'] ?? 0);
        $data['task_id'] = $data['id'];
        $data['tenant_id'] = (int)($row['tenant_id'] ?? 0);
        $data['user_id'] = (int)($row['user_id'] ?? 0);
        $data['project_id'] = (int)($row['project_id'] ?? 0);
        $data['source_task_id'] = (int)($row['source_task_id'] ?? 0);
        $data['status'] = (string)($result['status'] ?? $data['status'] ?? '');
        $data['provider_task_id'] = (string)($result['provider_task_id'] ?? ($data['source_task_id'] ?: ''));
        $data['params_json'] = $params;
        $data['result_json'] = $result;
        $data['prompt'] = (string)($row['prompt'] ?? $params['prompt'] ?? $params['content'] ?? '');
        $data['project_name'] = (string)($row['project_name'] ?? '');
        $data['user_nickname'] = (string)($row['user_nickname'] ?? '');
        $data['user_account'] = (string)($row['user_account'] ?? '');
        $data['user_mobile'] = (string)($row['user_mobile'] ?? '');
        $data['duration_ms'] = (int)($row['duration_ms'] ?? 0);
        $data['create_time'] = (int)($row['create_time'] ?? 0);
        $data['update_time'] = (int)($row['update_time'] ?? 0);
        $data['finish_time'] = (int)($row['finish_time'] ?? 0);
        $data['source_create_time'] = self::normalizeRunTime($result['create_time'] ?? 0);
        $data['source_update_time'] = self::normalizeRunTime($result['update_time'] ?? 0);
        $data['display_create_time'] = $data['source_create_time'] ?: $data['create_time'];
        $data['display_update_time'] = $data['source_update_time'] ?: $data['update_time'];
        $data['results'] = self::runResultItems($runType, $result);
        $data['result_count'] = count($data['results']);
        $first = $data['results'][0] ?? [];
        $data['image_url'] = (string)($first['image_url'] ?? '');
        $data['video_url'] = (string)($first['video_url'] ?? '');
        $data['audio_url'] = (string)($first['audio_url'] ?? '');
        $data['file_url'] = (string)($first['file_url'] ?? $data['audio_url'] ?? $data['video_url'] ?? $data['image_url'] ?? '');
        $data['image_urls'] = $runType === 'image'
            ? array_values(array_filter(array_map(static fn(array $item): string => (string)($item['image_url'] ?? $item['url'] ?? ''), $data['results'])))
            : [];
        return $data;
    }

    private static function syncSourceRunResult(array $row, string $runType, array $currentResult): array
    {
        $sourceTaskId = (int)($row['source_task_id'] ?? ($currentResult['task_id'] ?? 0));
        if ($sourceTaskId <= 0 || !in_array($runType, ['image', 'video', 'music'], true)) {
            return $currentResult;
        }

        $currentResults = self::runResultItems($runType, $currentResult);
        $currentStatus = (string)($row['status'] ?? ($currentResult['status'] ?? ''));
        if ($currentResults && $currentStatus === 'success') {
            return $currentResult;
        }

        try {
            if ($runType === 'image') {
                $detail = self::imageTaskDetail((int)$row['tenant_id'], 0, $sourceTaskId);
            } elseif ($runType === 'video') {
                $detail = self::videoTaskDetail((int)$row['tenant_id'], 0, $sourceTaskId);
            } else {
                $detail = self::musicTaskDetail((int)$row['tenant_id'], 0, $sourceTaskId);
            }
        } catch (\Throwable) {
            return $currentResult;
        }

        $detailResults = self::runResultItems($runType, $detail);
        $detailStatus = (string)($detail['status'] ?? '');
        if (!$detailResults && $detailStatus === '') {
            return $currentResult;
        }

        $now = time();
        $nextStatus = $detailStatus !== '' ? $detailStatus : $currentStatus;
        $finishTime = in_array($nextStatus, ['success', 'failed', 'canceled'], true)
            ? ((int)($row['finish_time'] ?? 0) ?: $now)
            : (int)($row['finish_time'] ?? 0);
        AigcCanvasRun::where(['tenant_id' => (int)$row['tenant_id'], 'id' => (int)$row['id']])->update([
            'status' => $nextStatus,
            'result_json' => self::sanitizeRunPayload($detail),
            'error' => (string)($detail['error'] ?? $detail['fail_reason'] ?? $row['error'] ?? ''),
            'update_time' => $now,
            'finish_time' => $finishTime,
        ]);

        $detail['status'] = $nextStatus;
        return $detail;
    }

    private static function syncRunsForSourceTask(int $tenantId, string $runType, int $sourceTaskId, array $detail): void
    {
        if ($tenantId <= 0 || $sourceTaskId <= 0 || !in_array($runType, ['image', 'video', 'music'], true)) {
            return;
        }
        $status = (string)($detail['status'] ?? '');
        $hasResults = !empty(self::runResultItems($runType, $detail));
        if ($status === '' && !$hasResults) {
            return;
        }
        if ($status === '') {
            $status = 'success';
        }
        $now = time();
        $isTerminal = in_array($status, ['success', 'failed', 'canceled'], true);
        $rows = AigcCanvasRun::where([
            'tenant_id' => $tenantId,
            'run_type' => $runType,
            'source_task_id' => $sourceTaskId,
            'delete_time' => 0,
        ])->select();
        foreach ($rows as $run) {
            $run->save([
                'status' => $status,
                'result_json' => self::sanitizeRunPayload($detail),
                'error' => (string)($detail['error'] ?? $detail['fail_reason'] ?? ''),
                'update_time' => $now,
                'finish_time' => $isTerminal ? ((int)$run['finish_time'] ?: $now) : (int)$run['finish_time'],
            ]);
        }
    }

    private static function normalizeRunPayload($value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && trim($value) !== '') {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }

    private static function runResultItems(string $runType, array $payload): array
    {
        $keys = $runType === 'image'
            ? ['image_url', 'image_uri', 'image', 'url', 'result_url']
            : ($runType === 'video'
                ? ['video_url', 'video_uri', 'video', 'file_url', 'file_uri', 'url', 'result_url']
                : ['audio_url', 'audio_uri', 'file_url', 'file_uri', 'url', 'result_url']);
        $source = !empty($payload['results']) && is_array($payload['results']) ? ['results' => $payload['results']] : $payload;
        $urls = self::collectRunUrls($source, $keys);
        $items = [];
        foreach ($urls as $index => $url) {
            if ($runType === 'image') {
                $items[] = ['id' => $index + 1, 'image_url' => $url, 'url' => $url];
            } elseif ($runType === 'video') {
                $items[] = ['id' => $index + 1, 'video_url' => $url, 'file_url' => $url, 'url' => $url];
            } else {
                $items[] = ['id' => $index + 1, 'audio_url' => $url, 'file_url' => $url, 'url' => $url];
            }
        }
        return $items;
    }

    private static function collectRunUrls($value, array $keys): array
    {
        $urls = [];
        $walker = function ($item) use (&$walker, &$urls, $keys) {
            if (is_string($item)) {
                return;
            }
            if (!is_array($item)) {
                return;
            }
            foreach ($keys as $key) {
                $url = trim((string)($item[$key] ?? ''));
                if ($url !== '' && !str_starts_with($url, '[')) {
                    $urls[] = self::formatRunUrl($url, $item);
                }
            }
            foreach ($item as $child) {
                if (is_array($child)) {
                    $walker($child);
                }
            }
        };
        $walker($value);
        return array_values(array_unique(array_filter($urls)));
    }

    private static function formatRunUrl(string $url, array $item = []): string
    {
        if ($url === '' || str_starts_with($url, 'http://') || str_starts_with($url, 'https://') || str_starts_with($url, 'data:')) {
            return $url;
        }
        if (!empty($item['storage_scope']) || !empty($item['storage_engine']) || !empty($item['storage_domain'])) {
            return FileService::getFileUrlByStorage(
                $url,
                (string)($item['storage_scope'] ?? ''),
                (string)($item['storage_engine'] ?? ''),
                (string)($item['storage_domain'] ?? '')
            );
        }
        return FileService::getFileUrl($url);
    }

    private static function normalizeRunTime($value): int
    {
        if (is_numeric($value)) {
            $time = (int)$value;
            return $time > 100000000000 ? (int)floor($time / 1000) : $time;
        }
        if (is_string($value) && trim($value) !== '') {
            $time = strtotime($value);
            return $time === false ? 0 : $time;
        }
        return 0;
    }

    private static function latestProjectNodeTime(array $nodes): int
    {
        $latest = 0;
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            $metadata = is_array($node['metadata'] ?? null) ? $node['metadata'] : [];
            foreach ([
                $node['updatedAt'] ?? 0,
                $node['createdAt'] ?? 0,
                $node['update_time'] ?? 0,
                $node['create_time'] ?? 0,
                $metadata['updatedAt'] ?? 0,
                $metadata['generatedAt'] ?? 0,
                $metadata['createdAt'] ?? 0,
                $metadata['update_time'] ?? 0,
                $metadata['create_time'] ?? 0,
            ] as $value) {
                $latest = max($latest, self::normalizeRunTime($value));
            }
        }
        return $latest;
    }

    private static function projectRegisteredAssets(int $tenantId, int $userId, int $projectId): array
    {
        if ($projectId <= 0) {
            return [];
        }
        $rows = AigcShortDramaAsset::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'project_id' => $projectId,
            'delete_time' => 0,
        ])
            ->where('status', '<>', 'deleted')
            ->order('id', 'desc')
            ->limit(200)
            ->select()
            ->toArray();

        return array_map(static fn(array $row): array => AigcCanvasAgentService::formatAsset($row), $rows);
    }

    private static function normalizeThumbnail(string $thumbnail, int $tenantId, int $userId, string $fallback = ''): string
    {
        $thumbnail = trim($thumbnail);
        if ($thumbnail === '') {
            return '';
        }
        if (str_starts_with($thumbnail, 'data:image/')) {
            $asset = AigcImageAssetService::persistGeneratedImage($thumbnail, $tenantId, $userId);
            return (string)($asset['uri'] ?? '');
        }
        return strlen($thumbnail) > 500 ? $fallback : $thumbnail;
    }

    private static function formatThumbnail(string $thumbnail): string
    {
        if ($thumbnail === '') {
            return '';
        }
        if (str_starts_with($thumbnail, 'data:')) {
            return '';
        }
        return FileService::getFileUrl($thumbnail);
    }

    private static function createRun(int $tenantId, int $userId, array $params, string $type, string $sourceApp): AigcCanvasRun
    {
        self::ensureRunSchema();
        return AigcCanvasRun::create([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'project_id' => (int)($params['project_id'] ?? 0),
            'node_id' => (string)($params['node_id'] ?? ''),
            'run_type' => $type,
            'source_app_code' => $sourceApp,
            'source_task_id' => 0,
            'status' => 'running',
            'prompt' => (string)($params['prompt'] ?? ''),
            'params_json' => self::sanitizeRunPayload($params),
            'result_json' => [],
            'error' => '',
            'duration_ms' => 0,
            'create_time' => time(),
            'update_time' => time(),
            'finish_time' => 0,
            'delete_time' => 0,
        ]);
    }

    private static function finishRun(AigcCanvasRun $run, array $result, string $status, string $error, float $started): void
    {
        $isTerminal = in_array($status, ['success', 'failed', 'canceled'], true);
        $run->save([
            'source_task_id' => (int)($result['task_id'] ?? 0),
            'status' => $status,
            'result_json' => self::sanitizeRunPayload($result),
            'error' => $error,
            'duration_ms' => (int)round((microtime(true) - $started) * 1000),
            'update_time' => time(),
            'finish_time' => $isTerminal ? time() : 0,
        ]);
    }

    private static function normalizeRunStatus(string $status, array $result): string
    {
        $status = strtolower(trim($status));
        if (in_array($status, ['failed', 'error'], true)) {
            return 'failed';
        }
        if (in_array($status, ['running', 'queued', 'pending', 'processing', 'loading'], true)) {
            return 'running';
        }
        return !empty($result['results']) ? 'success' : ($status ?: 'success');
    }

    private static function prepareMentionParams(array $params): array
    {
        $mentions = self::normalizeMentions($params['selected_mentions'] ?? []);
        $mentionPrompts = self::normalizeStringList($params['mention_prompts'] ?? []);
        foreach ($mentions as $mention) {
            $prompt = trim((string)($mention['prompt'] ?? ''));
            if ($prompt !== '') {
                $name = trim((string)($mention['name'] ?? ''));
                $mentionPrompts[] = $name !== '' ? $name . ': ' . $prompt : $prompt;
            }
        }
        $mentionPrompts = array_values(array_unique(array_filter(array_map('trim', $mentionPrompts))));

        $referenceImages = array_values(array_filter((array)($params['reference_images'] ?? $params['image_urls'] ?? [])));
        $referenceAssets = is_array($params['reference_assets'] ?? null) ? (array)$params['reference_assets'] : [];
        foreach ($mentions as $mention) {
            $image = self::mentionReferenceImage($mention);
            if ($image !== '' && !in_array($image, $referenceImages, true)) {
                $referenceImages[] = $image;
            }
            $asset = self::mentionReferenceAsset($mention);
            if (!empty($asset)) {
                $referenceAssets[] = $asset;
            }
        }

        $params['selected_mentions'] = $mentions;
        $params['mention_prompts'] = $mentionPrompts;
        $params['reference_images'] = $referenceImages;
        if (!empty($referenceAssets)) {
            $params['reference_assets'] = self::uniqueReferenceAssets($referenceAssets);
        }
        if (!empty($mentionPrompts)) {
            $basePrompt = trim((string)($params['prompt'] ?? $params['content'] ?? ''));
            $params['prompt'] = self::mergePromptWithMentionPrompts($basePrompt, $mentionPrompts);
        }
        return $params;
    }

    private static function normalizeMentions($value): array
    {
        $items = is_array($value) ? $value : [];
        $result = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $type = strtolower(trim((string)($item['type'] ?? 'text')));
            if (!in_array($type, ['text', 'image', 'video', 'audio', 'asset'], true)) {
                $type = 'text';
            }
            $name = mb_substr(trim((string)($item['name'] ?? '')), 0, 120, 'UTF-8');
            if ($name === '') {
                continue;
            }
            $normalized = [
                'id' => mb_substr(trim((string)($item['id'] ?? '')), 0, 180, 'UTF-8'),
                'type' => $type,
                'source' => mb_substr(trim((string)($item['source'] ?? 'mention')), 0, 60, 'UTF-8'),
                'name' => $name,
                'prompt' => trim((string)($item['prompt'] ?? '')),
                'url' => trim((string)($item['url'] ?? '')),
                'asset_id' => $item['asset_id'] ?? '',
                'node_id' => mb_substr(trim((string)($item['node_id'] ?? '')), 0, 120, 'UTF-8'),
                'role' => mb_substr(trim((string)($item['role'] ?? '')), 0, 80, 'UTF-8'),
                'mime_type' => mb_substr(trim((string)($item['mime_type'] ?? '')), 0, 120, 'UTF-8'),
                'asset_type' => mb_substr(trim((string)($item['asset_type'] ?? '')), 0, 120, 'UTF-8'),
            ];
            $result[] = $normalized;
        }
        return $result;
    }

    private static function normalizeStringList($value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [$value];
        }
        if (!is_array($value)) {
            return [];
        }
        return array_values(array_filter(array_map(static fn($item): string => trim((string)$item), $value)));
    }

    private static function mentionReferenceImage(array $mention): string
    {
        $url = trim((string)($mention['url'] ?? ''));
        if ($url === '') {
            return '';
        }
        if (($mention['type'] ?? '') === 'image') {
            return $url;
        }
        if (($mention['type'] ?? '') !== 'asset') {
            return '';
        }
        $assetType = strtolower((string)($mention['asset_type'] ?? $mention['role'] ?? ''));
        $mimeType = strtolower((string)($mention['mime_type'] ?? ''));
        return (str_contains($assetType, 'image') || str_starts_with($mimeType, 'image/') || (!str_contains($assetType, 'video') && !str_starts_with($mimeType, 'video/'))) ? $url : '';
    }

    private static function mentionReferenceAsset(array $mention): array
    {
        $url = trim((string)($mention['url'] ?? ''));
        if ($url === '') {
            return [];
        }
        $assetType = strtolower((string)($mention['asset_type'] ?? $mention['role'] ?? ''));
        $mimeType = strtolower((string)($mention['mime_type'] ?? ''));
        $type = '';
        if (($mention['type'] ?? '') === 'video' || str_contains($assetType, 'video') || str_starts_with($mimeType, 'video/')) {
            $type = 'video';
        } elseif (($mention['type'] ?? '') === 'audio' || str_contains($assetType, 'audio') || str_starts_with($mimeType, 'audio/')) {
            $type = 'audio';
        } elseif (($mention['type'] ?? '') === 'image' || ($mention['type'] ?? '') === 'asset') {
            $type = 'image';
        }
        if ($type === '') {
            return [];
        }
        return [
            'type' => $type,
            'uri' => $url,
            'url' => $url,
            'name' => (string)($mention['name'] ?? ''),
        ];
    }

    private static function uniqueReferenceAssets(array $assets): array
    {
        $result = [];
        $seen = [];
        foreach ($assets as $asset) {
            if (!is_array($asset)) {
                continue;
            }
            $type = trim((string)($asset['type'] ?? $asset['media_type'] ?? 'image'));
            $url = trim((string)($asset['uri'] ?? $asset['url'] ?? $asset['path'] ?? ''));
            if ($url === '') {
                continue;
            }
            $key = $type . '|' . $url;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $result[] = $asset;
        }
        return $result;
    }

    private static function mergePromptWithMentionPrompts(string $prompt, array $mentionPrompts): string
    {
        $lines = array_values(array_filter(array_map('trim', $mentionPrompts)));
        if (empty($lines)) {
            return trim($prompt);
        }
        $referenceText = "引用参考：\n" . implode("\n", array_map(static fn($line) => '- ' . $line, $lines));
        $prompt = trim($prompt);
        return $prompt === '' ? $referenceText : $prompt . "\n\n" . $referenceText;
    }

    private static function normalizeImageParams(array $params, int $tenantId, int $userId): array
    {
        return [
            'prompt' => trim((string)($params['prompt'] ?? '')),
            'negative_prompt' => (string)($params['negative_prompt'] ?? ''),
            'reference_images' => self::normalizeReferenceImages($params, $tenantId, $userId),
            'style' => (string)($params['style'] ?? 'general'),
            'channel' => (string)($params['channel'] ?? $params['model'] ?? ''),
            'quality' => (string)($params['quality'] ?? ''),
            'ratio' => (string)($params['ratio'] ?? $params['size'] ?? ''),
            'quantity' => max(1, (int)($params['quantity'] ?? 1)),
        ];
    }

    private static function normalizeVideoParams(array $params, int $tenantId, int $userId): array
    {
        $referenceImages = self::normalizeReferenceImages($params, $tenantId, $userId);
        $referenceAssets = AigcVideoReferenceAssetService::normalize(array_merge($params, [
            'reference_images' => $referenceImages,
        ]));
        return [
            'prompt' => trim((string)($params['prompt'] ?? '')),
            'negative_prompt' => (string)($params['negative_prompt'] ?? ''),
            'reference_images' => $referenceImages,
            'reference_assets' => $referenceAssets,
            'style' => (string)($params['style'] ?? 'general'),
            'channel' => (string)($params['channel'] ?? $params['model'] ?? ''),
            'quality' => (string)($params['quality'] ?? $params['duration'] ?? $params['seconds'] ?? ''),
            'ratio' => (string)($params['ratio'] ?? ''),
            'duration' => (int)($params['duration'] ?? 0),
            'mode' => (string)($params['mode'] ?? $params['videoMode'] ?? ''),
            'generation_method' => (string)($params['generation_method'] ?? $params['generationMethod'] ?? ''),
            'first_frame_image' => (string)($params['first_frame_image'] ?? ''),
            'last_frame_image' => (string)($params['last_frame_image'] ?? ''),
            'quantity' => max(1, (int)($params['quantity'] ?? 1)),
        ];
    }

    private static function normalizeMusicParams(array $params): array
    {
        $referenceAssets = is_array($params['reference_assets'] ?? null) ? (array)$params['reference_assets'] : [];
        $audioUrl = trim((string)($params['audio_url'] ?? $params['reference_audio'] ?? ''));
        foreach ($referenceAssets as $asset) {
            if (!is_array($asset)) {
                continue;
            }
            $type = strtolower(trim((string)($asset['type'] ?? $asset['media_type'] ?? '')));
            $url = trim((string)($asset['uri'] ?? $asset['url'] ?? $asset['path'] ?? ''));
            if ($audioUrl === '' && $type === 'audio' && $url !== '') {
                $audioUrl = $url;
            }
        }
        return [
            'prompt' => trim((string)($params['prompt'] ?? $params['content'] ?? '')),
            'lyrics' => trim((string)($params['lyrics'] ?? $params['lyric'] ?? '')),
            'style' => (string)($params['style'] ?? ''),
            'genre' => (string)($params['genre'] ?? $params['style'] ?? ''),
            'title' => (string)($params['title'] ?? ''),
            'custom' => $params['custom'] ?? false,
            'instrumental' => $params['instrumental'] ?? false,
            'vocal_gender' => (string)($params['vocal_gender'] ?? ''),
            'language' => (string)($params['language'] ?? ''),
            'duration' => (int)($params['duration'] ?? 30),
            'channel' => (string)($params['channel'] ?? $params['model'] ?? ''),
            'quality' => (string)($params['quality'] ?? ''),
            'audio_url' => $audioUrl,
            'reference_audio' => $audioUrl,
            'reference_asset_id' => (int)($params['reference_asset_id'] ?? 0),
        ];
    }

    private static function normalizeReferenceImages(array $params, int $tenantId, int $userId, bool $persistInlineImages = true): array
    {
        $images = array_values(array_filter((array)($params['reference_images'] ?? $params['image_urls'] ?? [])));
        foreach (['image', 'first_frame_image', 'last_frame_image'] as $key) {
            $value = trim((string)($params[$key] ?? ''));
            if ($value !== '' && !in_array($value, $images, true)) {
                $images[] = $value;
            }
        }
        $normalized = [];
        foreach (array_slice($images, 0, 12) as $image) {
            $image = trim((string)$image);
            if ($image === '') {
                continue;
            }
            if ($persistInlineImages && str_starts_with($image, 'data:image/')) {
                $stored = AigcImageAssetService::persistGeneratedImage($image, $tenantId, $userId);
                $image = (string)($stored['uri'] ?? '');
            }
            if ($image !== '' && !in_array($image, $normalized, true)) {
                $normalized[] = $image;
            }
        }
        return $normalized;
    }

    private static function resolveTextPrompt(array $params, array $referenceImages): string
    {
        $prompt = trim((string)($params['prompt'] ?? $params['content'] ?? ''));
        if ($prompt !== '') {
            return $prompt;
        }
        $instruction = trim((string)($params['system_prompt'] ?? ''));
        if ($instruction !== '') {
            return $instruction;
        }
        return $referenceImages ? '请根据参考图片生成内容。' : '';
    }

    private static function ensureRunSchema(): void
    {
        static $checked = false;
        if ($checked) {
            return;
        }
        $checked = true;
        try {
            $table = str_replace('`', '``', (new AigcCanvasRun())->db()->getTable());
            $columns = Db::query("SHOW COLUMNS FROM `" . $table . "` WHERE Field IN ('params_json', 'result_json')");
            $sql = [];
            foreach ($columns as $column) {
                $field = (string)($column['Field'] ?? '');
                $type = strtolower((string)($column['Type'] ?? ''));
                if (in_array($field, ['params_json', 'result_json'], true) && !str_contains($type, 'longtext')) {
                    $comment = $field === 'params_json' ? '调用参数' : '执行结果';
                    $sql[] = "MODIFY COLUMN `" . $field . "` longtext COMMENT '" . $comment . "'";
                }
            }
            if ($sql) {
                Db::execute('ALTER TABLE `' . $table . '` ' . implode(', ', $sql));
            }
        } catch (Exception $e) {
            // Schema migration may be unavailable in restricted runtimes; sanitized logs still keep inserts small.
        }
    }

    private static function sanitizeRunPayload(array $payload): array
    {
        $result = self::truncateRunPayload($payload);
        foreach (['reference_images', 'image_urls'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                $result[$key . '_count'] = count($payload[$key]);
                $result[$key] = array_map([self::class, 'summarizeRunImage'], array_slice($payload[$key], 0, 12));
            }
        }
        foreach (['image', 'first_frame_image', 'last_frame_image'] as $key) {
            if (isset($payload[$key]) && is_string($payload[$key])) {
                $result[$key] = self::summarizeRunImage($payload[$key]);
            }
        }
        return $result;
    }

    private static function truncateRunPayload($value, int $depth = 0)
    {
        if ($depth > 5) {
            return '[depth_limited]';
        }
        if (is_array($value)) {
            $result = [];
            foreach ($value as $key => $item) {
                $result[$key] = self::truncateRunPayload($item, $depth + 1);
            }
            return $result;
        }
        if (is_string($value)) {
            if (str_starts_with($value, 'data:image/')) {
                return '[inline_image:' . strlen($value) . ' bytes]';
            }
            return mb_strlen($value, 'UTF-8') > 2000 ? mb_substr($value, 0, 2000, 'UTF-8') . '...' : $value;
        }
        return $value;
    }

    private static function summarizeRunImage(string $image): string
    {
        $image = trim($image);
        if ($image === '') {
            return '';
        }
        if (str_starts_with($image, 'data:image/')) {
            return '[inline_image:' . strlen($image) . ' bytes]';
        }
        return strlen($image) > 500 ? substr($image, 0, 500) . '...' : $image;
    }
}
