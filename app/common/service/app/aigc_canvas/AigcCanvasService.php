<?php

namespace app\common\service\app\aigc_canvas;

use app\common\model\app\App;
use app\common\model\app\TenantApp;
use app\common\model\app\aigc_canvas\AigcCanvasProject;
use app\common\model\app\aigc_canvas\AigcCanvasRun;
use app\common\service\app\AppAccessService;
use app\common\service\app\AppDisplayConfigService;
use app\common\service\app\AppRegistryService;
use app\common\service\FileService;
use app\common\service\app\aigc_image\AigcImageAssetService;
use app\common\service\app\aigc_image\AigcImageService;
use app\common\service\app\aigc_llm\AigcLlmService;
use app\common\service\app\aigc_video\AigcVideoReferenceAssetService;
use app\common\service\app\aigc_video\AigcVideoService;
use Exception;
use think\facade\Db;

class AigcCanvasService
{
    public const APP_CODE = 'aigc_canvas';
    public const IMAGE_APP_CODE = 'aigc_image';
    public const LLM_APP_CODE = 'aigc_llm';
    public const VIDEO_APP_CODE = 'aigc_video';

    public static function dependencies(int $tenantId = 0): array
    {
        $items = [
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
            'dependencies' => self::dependencies($tenantId),
            'image' => AigcImageService::config($tenantId),
            'video' => AigcVideoService::config($tenantId),
        ]);
    }

    public static function saveConfig(int $tenantId, array $params): void
    {
        AppDisplayConfigService::saveFromConfigPayload($tenantId, self::APP_CODE, $params);
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
        $query = AigcCanvasRun::where('tenant_id', $tenantId)->where('delete_time', 0);
        if ($userId > 0) {
            $query->where('user_id', $userId);
        }
        if (!empty($params['project_id'])) {
            $query->where('project_id', (int)$params['project_id']);
        }
        if (!empty($params['status'])) {
            $query->where('status', (string)$params['status']);
        }
        $limit = max(1, min(100, (int)($params['limit'] ?? 80)));
        return $query->order('id', 'desc')->limit($limit)->select()->toArray();
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
        }
        return [
            'run_total' => $runTotal,
            'run_user_total' => $runUserTotal,
            'run_success' => $success,
            'run_failed' => $failed,
            'image_run_total' => $image,
            'video_run_total' => $video,
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
        $started = microtime(true);
        $run = self::createRun($tenantId, $userId, $params, 'image', self::IMAGE_APP_CODE);
        try {
            $result = AigcImageService::generate($tenantId, $userId, self::normalizeImageParams($params, $tenantId, $userId));
            self::finishRun($run, $result, 'success', '', $started);
            return $result;
        } catch (Exception $e) {
            self::finishRun($run, [], 'failed', $e->getMessage(), $started);
            throw $e;
        }
    }

    public static function generateVideo(int $tenantId, int $userId, array $params): array
    {
        self::assertDependencyReady($tenantId, self::VIDEO_APP_CODE);
        $started = microtime(true);
        $run = self::createRun($tenantId, $userId, $params, 'video', self::VIDEO_APP_CODE);
        try {
            $result = AigcVideoService::generate($tenantId, $userId, self::normalizeVideoParams($params, $tenantId, $userId));
            self::finishRun($run, $result, (($result['status'] ?? '') === 'failed') ? 'failed' : 'success', (string)($result['error'] ?? ''), $started);
            return $result;
        } catch (Exception $e) {
            self::finishRun($run, [], 'failed', $e->getMessage(), $started);
            throw $e;
        }
    }

    public static function generateText(int $tenantId, int $userId, array $params): array
    {
        self::assertDependencyReady($tenantId, self::LLM_APP_CODE);
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
        return AigcVideoService::taskDetail($tenantId, $taskId, $userId);
    }

    public static function imageTaskDetail(int $tenantId, int $userId, int $taskId): array
    {
        self::assertDependencyReady($tenantId, self::IMAGE_APP_CODE);
        return AigcImageService::taskDetail($tenantId, $taskId, $userId);
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
        $data = [
            'id' => (int)$row['id'],
            'name' => (string)($row['name'] ?? '未命名项目'),
            'thumbnail' => self::formatThumbnail((string)($row['thumbnail'] ?? '')),
            'node_count' => $nodeCount,
            'edge_count' => $edgeCount,
            'createdAt' => ((int)($row['create_time'] ?? 0)) * 1000,
            'updatedAt' => ((int)($row['update_time'] ?? 0)) * 1000,
            'create_time' => (int)($row['create_time'] ?? 0),
            'update_time' => (int)($row['update_time'] ?? 0),
            'tenant_id' => (int)($row['tenant_id'] ?? 0),
            'user_id' => (int)($row['user_id'] ?? 0),
            'sort' => (int)($row['sort'] ?? 0),
            'status' => (int)($row['status'] ?? 1),
        ];
        if ($full) {
            $data['nodes'] = self::normalizeList($row['nodes_json'] ?? []);
            $data['edges'] = self::normalizeList($row['edges_json'] ?? []);
            $data['viewport'] = self::normalizeViewport($row['viewport_json'] ?? []);
        }
        return $data;
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
        $run->save([
            'source_task_id' => (int)($result['task_id'] ?? 0),
            'status' => $status,
            'result_json' => self::sanitizeRunPayload($result),
            'error' => $error,
            'duration_ms' => (int)round((microtime(true) - $started) * 1000),
            'update_time' => time(),
            'finish_time' => time(),
        ]);
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
            'quantity' => max(1, (int)($params['quantity'] ?? 1)),
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
