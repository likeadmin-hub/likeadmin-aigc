<?php

namespace app\common\service\app\aigc_canvas;

use app\common\model\app\aigc_canvas\AigcCanvasProject;
use app\common\model\app\aigc_canvas\AigcCanvasRun;
use app\common\model\app\aigc_canvas\AigcCanvasAsset;
use app\common\model\ai\AiConsumptionLog;
use app\common\service\FileService;
use app\common\service\app\aigc_canvas\agent\billing\CanvasAgentEntitlementService;
use app\common\service\app\aigc_canvas\agent\billing\CanvasAgentQueuePolicyService;
use app\common\service\app\aigc_canvas\agent\billing\CanvasAgentUsageLedgerService;
use app\common\service\app\aigc_canvas\agent\memory\BrandMemoryService;
use app\common\service\app\aigc_canvas\agent\model\CanvasGenerationPricingService;
use app\common\service\app\aigc_canvas\agent\prompt\PromptSubmissionService;
use app\common\service\power\MarketImageModelRuntimeService;
use app\common\service\power\MarketMusicAppRuntimeService;
use app\common\service\power\MarketNanoBananaAppRuntimeService;
use app\common\service\power\MarketVideoRuntimeService;
use Exception;

class AigcCanvasAgentService
{
    public static function createScriptPlan(int $tenantId, int $userId, array $params): array
    {
        return self::createCanvasScriptText($tenantId, $userId, $params, 'canvas_script_plan');
    }

    public static function scriptPlanDetail(int $tenantId, int $userId, array $params): array
    {
        $taskId = (int)($params['task_id'] ?? $params['id'] ?? 0);
        if ($taskId <= 0) {
            return [];
        }
        $run = AigcCanvasRun::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'source_task_id' => $taskId,
            'run_type' => 'text',
            'delete_time' => 0,
        ])->order('id', 'desc')->findOrEmpty();
        if ($run->isEmpty()) {
            return [];
        }
        $result = self::arrayValue($run['result_json'] ?? []);
        return [
            'task_id' => (string)$taskId,
            'status' => (string)$run['status'],
            'content' => (string)($result['content'] ?? ''),
            'result' => $result,
            'error' => (string)$run['error'],
            'create_time' => (int)$run['create_time'],
            'update_time' => (int)$run['update_time'],
        ];
    }

    public static function streamScriptPlan(int $tenantId, int $userId, array $params, callable $emit): array
    {
        return self::createCanvasScriptText($tenantId, $userId, $params, 'canvas_script_stream', $emit);
    }

    public static function listSubjects(int $tenantId, int $userId, array $params): array
    {
        return self::assetLists($tenantId, $userId, $params + ['asset_type' => 'subject']);
    }

    public static function saveSubject(int $tenantId, int $userId, array $params): array
    {
        $params = self::withCanvasSource($params, 'canvas_subject_save');
        $params['asset_type'] = 'subject';
        $params['title'] = (string)($params['name'] ?? $params['title'] ?? '未命名主体');
        $asset = self::upsertCanvasAsset($tenantId, $userId, $params);
        self::upsertProjectNode($tenantId, $userId, (int)($params['project_id'] ?? 0), [
            'node_id' => (string)($params['node_id'] ?? ('subject_' . (int)$asset['id'])),
            'type' => 'image',
            'variant' => 'subject',
            'title' => (string)($asset['title'] ?? ''),
            'metadata' => [
                'asset_id' => (int)$asset['id'],
                'name' => (string)($params['name'] ?? $asset['title'] ?? ''),
                'description' => (string)($params['description'] ?? ''),
                'image' => (string)($asset['url'] ?? ''),
                'url' => (string)($asset['url'] ?? ''),
                'status' => 'success',
            ],
        ]);
        return $asset;
    }

    public static function describeSubject(int $tenantId, int $userId, array $params): array
    {
        $image = trim((string)($params['image'] ?? $params['image_url'] ?? $params['url'] ?? ''));
        if ($image === '') {
            throw new Exception('请先上传或选择主体图片');
        }
        $prompt = trim((string)($params['prompt'] ?? ''));
        if ($prompt === '') {
            $prompt = '请分析这张主体参考图，提炼主体外观、服饰/材质、风格、可用于生图的一致性提示词。';
        }
        $result = AigcCanvasService::generateText($tenantId, $userId, [
            'prompt' => $prompt,
            'reference_images' => [$image],
            'source_type' => 'canvas_subject_describe',
            'source_app_code' => AigcCanvasService::APP_CODE,
            'business_table' => 'aigc_canvas_run',
            'project_id' => (int)($params['project_id'] ?? 0),
            'node_id' => (string)($params['node_id'] ?? ''),
        ]);
        $content = (string)($result['content'] ?? '');
        return [
            'description' => $content,
            'prompt' => $content,
            'result' => $result,
            'task_id' => (string)($result['task_id'] ?? $result['consumption_id'] ?? ''),
        ];
    }

    public static function generateSubject(int $tenantId, int $userId, array $params): array
    {
        $params = self::withCanvasSource($params, 'canvas_subject_generate');
        $params['prompt'] = trim((string)($params['prompt'] ?? $params['description'] ?? $params['name'] ?? ''));
        if ($params['prompt'] === '') {
            throw new Exception('请输入主体生成提示词');
        }
        $result = AigcCanvasService::generateImage($tenantId, $userId, $params);
        $formatted = self::formatCanvasGenerationResult('image', $result);
        $formatted['subject_id'] = (int)($params['subject_id'] ?? $params['id'] ?? 0);
        return $formatted;
    }

    public static function saveStoryboard(int $tenantId, int $userId, array $params): array
    {
        return self::saveCanvasStructuredNode($tenantId, $userId, $params, 'storyboardGroup', 'canvas_storyboard_save', '分镜组', [
            'storyboard' => self::arrayValue($params['storyboard'] ?? $params['shots'] ?? []),
            'shots' => self::arrayValue($params['shots'] ?? $params['storyboard'] ?? []),
        ]);
    }

    public static function saveVisualPlan(int $tenantId, int $userId, array $params): array
    {
        return self::saveCanvasStructuredNode($tenantId, $userId, $params, 'script', 'canvas_visual_plan_save', '视觉方案', [
            'visual_plan' => self::arrayValue($params['visual_plan'] ?? $params['plan'] ?? $params),
        ]);
    }

    public static function insertStoryboardShot(int $tenantId, int $userId, array $params): array
    {
        $shots = self::projectStoryboardShots($tenantId, $userId, $params);
        $shot = self::normalizeStoryboardShot($params['shot'] ?? $params);
        $index = max(0, min(count($shots), (int)($params['index'] ?? $params['sort'] ?? count($shots))));
        array_splice($shots, $index, 0, [$shot]);
        return self::saveStoryboard($tenantId, $userId, array_merge($params, ['shots' => $shots]));
    }

    public static function copyStoryboardShot(int $tenantId, int $userId, array $params): array
    {
        $shots = self::projectStoryboardShots($tenantId, $userId, $params);
        $index = max(0, (int)($params['index'] ?? $params['sort'] ?? 0));
        if (!isset($shots[$index])) {
            throw new Exception('分镜不存在');
        }
        $copy = $shots[$index];
        $copy['id'] = 'shot_' . time() . '_' . mt_rand(1000, 9999);
        $copy['title'] = (string)($copy['title'] ?? '分镜') . ' 副本';
        array_splice($shots, $index + 1, 0, [$copy]);
        return self::saveStoryboard($tenantId, $userId, array_merge($params, ['shots' => $shots]));
    }

    public static function deleteStoryboardShot(int $tenantId, int $userId, array $params): array
    {
        $shots = self::projectStoryboardShots($tenantId, $userId, $params);
        $index = (int)($params['index'] ?? $params['sort'] ?? -1);
        $shotId = (string)($params['shot_id'] ?? $params['id'] ?? '');
        $shots = array_values(array_filter($shots, static function (array $shot, int $i) use ($index, $shotId): bool {
            if ($shotId !== '' && (string)($shot['id'] ?? $shot['shot_id'] ?? '') === $shotId) {
                return false;
            }
            return $index < 0 || $i !== $index;
        }, ARRAY_FILTER_USE_BOTH));
        return self::saveStoryboard($tenantId, $userId, array_merge($params, ['shots' => $shots]));
    }

    public static function createGeneration(int $tenantId, int $userId, array $params): array
    {
        self::assertGenerationCanSubmit($tenantId, $userId, $params);
        $mode = self::generationMode($params);
        $result = $mode === 'video'
            ? AigcCanvasService::generateVideo($tenantId, $userId, $params)
            : AigcCanvasService::generateImage($tenantId, $userId, $params);
        return self::formatCanvasGenerationResult($mode, $result);
    }

    public static function estimateGeneration(int $tenantId, int $userId, array $params): array
    {
        $mode = self::generationMode($params);
        $toolCode = $mode === 'video' ? 'generate_video' : 'generate_image';
        $pricing = CanvasGenerationPricingService::estimate($tenantId, $toolCode, $params);
        $entitlement = CanvasAgentEntitlementService::precheck($tenantId, $userId, $pricing);
        $queue = CanvasAgentQueuePolicyService::resolve($pricing, $params);
        $ledger = CanvasAgentUsageLedgerService::preview($tenantId, $userId, $toolCode, $pricing, $queue, $params);
        return [
            'mode' => $mode,
            'tool_code' => $toolCode,
            'available' => !empty($pricing['available']),
            'can_submit' => !empty($entitlement['can_submit']),
            'message' => (string)($entitlement['message'] ?? $pricing['message'] ?? ''),
            'estimated_points' => (float)($pricing['estimated_points'] ?? $pricing['user_charge_points'] ?? 0),
            'estimated_time' => (int)($pricing['estimated_time'] ?? ($mode === 'video' ? 90 : 45)),
            'requires_confirmation' => !empty($pricing['requires_confirmation']),
            'entitlement' => $entitlement,
            'queue' => $queue,
            'ledger' => $ledger,
            'pricing' => $pricing,
        ];
    }

    public static function generationDetail(int $tenantId, int $userId, array $params): array
    {
        $taskId = (int)($params['task_id'] ?? $params['id'] ?? 0);
        if ($taskId <= 0) {
            return [];
        }
        $row = self::canvasGenerationConsumption($tenantId, $userId, $taskId);
        if (empty($row)) {
            return [];
        }
        $detail = match ((string)($row['action_code'] ?? '')) {
            'video_generate' => AigcCanvasService::videoTaskDetail($tenantId, $userId, $taskId),
            'music_generate' => AigcCanvasService::musicTaskDetail($tenantId, $userId, $taskId),
            default => AigcCanvasService::imageTaskDetail($tenantId, $userId, $taskId),
        };
        return self::formatCanvasGenerationResult(self::modeFromConsumption($row), $detail + ['task_id' => $taskId]);
    }

    public static function generationLists(int $tenantId, int $userId, array $params): array
    {
        $pageNo = max(1, (int)($params['page_no'] ?? 1));
        $pageSize = max(1, min(50, (int)($params['page_size'] ?? 15)));
        $query = AiConsumptionLog::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'app_code' => AigcCanvasService::APP_CODE,
            'provider' => 'power_market',
        ])->whereIn('action_code', ['generate', 'video_generate', 'music_generate']);
        $count = (int)(clone $query)->count();
        $rows = $query->order('id', 'desc')
            ->limit(($pageNo - 1) * $pageSize, $pageSize)
            ->select()
            ->toArray();
        return [
            'lists' => array_map(static fn(array $row): array => self::formatCanvasGenerationConsumption($row), $rows),
            'count' => $count,
            'page_no' => $pageNo,
            'page_size' => $pageSize,
        ];
    }

    public static function retryGeneration(int $tenantId, int $userId, array $params): array
    {
        $taskId = (int)($params['task_id'] ?? $params['id'] ?? 0);
        $consumption = self::canvasGenerationConsumption($tenantId, $userId, $taskId);
        if ($consumption === []) {
            throw new Exception('Generation task not found');
        }
        $type = self::modeFromConsumption($consumption);
        $runType = $type === 'audio' ? 'music' : $type;
        $run = AigcCanvasRun::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'run_type' => $runType,
            'source_task_id' => $taskId,
            'delete_time' => 0,
        ])->order('id', 'desc')->findOrEmpty();
        if ($run->isEmpty()) {
            throw new Exception('Original generation parameters are unavailable');
        }
        $input = self::arrayValue($run['params_json'] ?? []);
        if ($input === []) {
            throw new Exception('Original generation parameters are unavailable');
        }
        $input['retry_of_task_id'] = $taskId;
        $input['prompt_mode'] = 'retry';
        $input['request_id'] = trim((string)($params['request_id'] ?? '')) ?: 'retry_' . $taskId . '_' . time();
        if ($runType === 'image') {
            $input = PromptSubmissionService::withTrustedRetrySnapshot($input);
        } elseif ($runType === 'video') {
            $input = \app\common\service\app\aigc_canvas\agent\prompt\VideoPromptSubmissionService::withTrustedRetrySnapshot($input);
        }
        $result = match ($runType) {
            'video' => AigcCanvasService::generateVideo($tenantId, $userId, $input),
            'music' => AigcCanvasService::generateMusic($tenantId, $userId, $input),
            default => AigcCanvasService::generateImage($tenantId, $userId, $input),
        };
        return self::formatCanvasGenerationResult($type, $result);

        throw new Exception('请基于原提示词重新发起无限画布生成任务');
    }

    public static function cancelGeneration(int $tenantId, int $userId, array $params): array
    {
        $taskId = (int)($params['task_id'] ?? $params['id'] ?? 0);
        $row = self::canvasGenerationConsumption($tenantId, $userId, $taskId);
        if (empty($row)) {
            return [];
        }
        match ((string)($row['action_code'] ?? '')) {
            'video_generate' => MarketVideoRuntimeService::cancel($taskId),
            'music_generate' => MarketMusicAppRuntimeService::cancel($taskId),
            default => (string)($row['protocol'] ?? '') === 'application_api'
                ? MarketNanoBananaAppRuntimeService::cancel($taskId)
                : MarketImageModelRuntimeService::cancel($taskId),
        };
        return self::formatCanvasGenerationConsumption(self::canvasGenerationConsumption($tenantId, $userId, $taskId));
    }

    public static function deleteGeneration(int $tenantId, int $userId, array $params): void
    {
        // Canvas generation history is owned by aigc_canvas_run / ai_consumption_log.
        // There is no short-drama generation_task row to delete in the market-native flow.
    }

    public static function streamGeneration(int $tenantId, int $userId, array $params, callable $emit): array
    {
        $result = self::createGeneration($tenantId, $userId, $params);
        $emit('task', $result);
        $emit('done', $result);
        return $result;
    }

    private static function generationMode(array $params): string
    {
        $value = strtolower(trim(implode(' ', array_map('strval', [
            $params['mode'] ?? '',
            $params['media_type'] ?? '',
            $params['task_type'] ?? '',
            $params['type'] ?? '',
            $params['generation_method'] ?? '',
            $params['video_model_id'] ?? '',
        ]))));
        if (str_contains($value, 'video') || str_contains($value, '视频') || str_contains($value, 'start_end') || str_contains($value, 'multi_frame')) {
            return 'video';
        }
        if (!empty($params['first_frame_image']) || !empty($params['last_frame_image']) || !empty($params['reference_video'])) {
            return 'video';
        }
        return 'image';
    }

    private static function assertGenerationCanSubmit(int $tenantId, int $userId, array $params): void
    {
        $estimate = self::estimateGeneration($tenantId, $userId, $params);
        if (!empty($estimate['can_submit']) || empty($estimate['available'])) {
            return;
        }
        $message = trim((string)($estimate['message'] ?? ''));
        if ($message === '') {
            $message = '积分不足，无法提交生成任务';
        }
        throw new Exception($message);
    }

    private static function canvasGenerationConsumption(int $tenantId, int $userId, int $taskId): array
    {
        if ($taskId <= 0) {
            return [];
        }
        $query = AiConsumptionLog::where([
            'id' => $taskId,
            'tenant_id' => $tenantId,
            'app_code' => AigcCanvasService::APP_CODE,
            'provider' => 'power_market',
        ])->whereIn('action_code', ['generate', 'video_generate', 'music_generate']);
        if ($userId > 0) {
            $query->where('user_id', $userId);
        }
        $row = $query->findOrEmpty();
        return $row->isEmpty() ? [] : $row->toArray();
    }

    private static function modeFromConsumption(array $row): string
    {
        return match ((string)($row['action_code'] ?? '')) {
            'video_generate' => 'video',
            'music_generate' => 'audio',
            default => 'image',
        };
    }

    private static function formatCanvasGenerationResult(string $mode, array $result): array
    {
        $assets = [];
        foreach ((array)($result['images'] ?? []) as $item) {
            $assets[] = self::formatGenerationAsset('image', $item);
        }
        foreach ((array)($result['videos'] ?? []) as $item) {
            $assets[] = self::formatGenerationAsset('video', $item);
        }
        foreach ((array)($result['items'] ?? $result['audios'] ?? []) as $item) {
            $assets[] = self::formatGenerationAsset('audio', $item);
        }
        return [
            'task_id' => (string)($result['task_id'] ?? ''),
            'id' => (int)($result['task_id'] ?? 0),
            'mode' => $mode,
            'task_type' => $mode,
            'status' => (string)($result['status'] ?? 'running'),
            'progress' => (string)($result['status'] ?? '') === 'success' ? 100 : 0,
            'assets' => $assets,
            'output_assets' => $assets,
            'error' => (string)($result['error'] ?? ''),
            'provider_task_id' => (string)($result['provider_task_id'] ?? ''),
        ];
    }

    private static function formatGenerationAsset(string $type, array $item): array
    {
        $url = (string)($item['url'] ?? $item['image_url'] ?? $item['video_url'] ?? $item['audio_url'] ?? $item['uri'] ?? $item['image_uri'] ?? $item['video_uri'] ?? $item['audio_uri'] ?? '');
        return [
            'type' => $type,
            'asset_type' => $type,
            'url' => $url,
            'uri' => $url,
            'width' => (int)($item['width'] ?? 0),
            'height' => (int)($item['height'] ?? 0),
            'duration' => (float)($item['duration'] ?? 0),
            'storage_scope' => (string)($item['storage_scope'] ?? ''),
            'storage_engine' => (string)($item['storage_engine'] ?? ''),
            'storage_domain' => (string)($item['storage_domain'] ?? ''),
        ];
    }

    private static function formatCanvasGenerationConsumption(array $row): array
    {
        $summary = self::arrayValue($row['response_summary'] ?? []);
        return [
            'task_id' => (string)($row['id'] ?? ''),
            'id' => (int)($row['id'] ?? 0),
            'mode' => self::modeFromConsumption($row),
            'task_type' => self::modeFromConsumption($row),
            'status' => (string)($row['run_status'] ?? ''),
            'progress' => in_array((string)($row['run_status'] ?? ''), ['success', 'failed', 'canceled'], true) ? 100 : 0,
            'assets' => (array)($summary['images'] ?? $summary['videos'] ?? $summary['items'] ?? []),
            'error' => (string)($row['error_message'] ?? ''),
            'provider_task_id' => (string)($row['upstream_task_id'] ?? ''),
            'create_time' => (int)($row['create_time'] ?? 0),
            'update_time' => (int)($row['update_time'] ?? 0),
        ];
    }

    private static function arrayValue($value): array
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

    public static function subjectImageHistory(int $tenantId, int $userId, array $params): array
    {
        return self::assetLists($tenantId, $userId, $params + ['asset_type' => 'subject_image']);
    }

    public static function registerSubjectImage(int $tenantId, int $userId, array $params): array
    {
        $params = self::withCanvasSource($params, 'canvas_subject_register_image');
        $params['asset_type'] = (string)($params['asset_type'] ?? 'subject_image');
        return self::upsertCanvasAsset($tenantId, $userId, $params);
    }

    public static function selectSubjectImage(int $tenantId, int $userId, array $params): array
    {
        return self::selectCanvasAsset($tenantId, $userId, $params, 'canvas_subject_select_image');
    }

    public static function subjectThreeViewHistory(int $tenantId, int $userId, array $params): array
    {
        return self::assetLists($tenantId, $userId, $params + ['asset_type' => 'subject_three_view']);
    }

    public static function registerAsset(int $tenantId, int $userId, array $params): array
    {
        $params = self::withCanvasSource($params, 'canvas_asset_register');
        $projectId = (int)($params['project_id'] ?? $params['canvas_project_id'] ?? 0);
        if ($projectId > 0) {
            $project = AigcCanvasProject::where([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'id' => $projectId,
                'delete_time' => 0,
            ])->findOrEmpty();
            if ($project->isEmpty()) {
                throw new Exception('项目不存在');
            }
        }

        $assetType = self::normalizeAssetType((string)($params['asset_type'] ?? $params['type'] ?? 'reference_image'));
        $uri = FileService::setFileUrl((string)($params['uri'] ?? $params['url'] ?? ''));
        if ($uri === '') {
            throw new Exception('Asset file is required');
        }
        $coverUri = FileService::setFileUrl((string)($params['cover_uri'] ?? $params['cover_url'] ?? ''));
        $meta = is_array($params['meta'] ?? null) ? $params['meta'] : [];
        $meta = array_merge($meta, [
            'source' => (string)($meta['source'] ?? $params['source'] ?? 'aigc_canvas'),
            'source_app_code' => AigcCanvasService::APP_CODE,
            'source_type' => (string)($params['source_type'] ?? 'canvas_asset_register'),
            'canvas_project_id' => (string)$projectId,
            'canvas_node_id' => (string)($params['canvas_node_id'] ?? $params['node_id'] ?? ''),
            'asset_type' => $assetType,
            'title' => (string)($params['title'] ?? $meta['title'] ?? ''),
            'preview_url' => (string)($params['preview_url'] ?? $meta['preview_url'] ?? ''),
            'cover_preview_url' => (string)($params['cover_preview_url'] ?? $meta['cover_preview_url'] ?? ''),
        ]);
        $isRemoteUri = preg_match('/^https?:\/\//i', $uri) === 1;
        $time = time();
        $asset = AigcCanvasAsset::create([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'project_id' => $projectId,
            'task_id' => (string)($params['task_id'] ?? ''),
            'shot_id' => (string)($params['shot_id'] ?? ''),
            'asset_type' => $assetType,
            'title' => mb_substr(trim((string)($params['title'] ?? '')), 0, 120, 'UTF-8'),
            'uri' => $uri,
            'cover_uri' => $coverUri,
            'storage_scope' => (string)($params['storage_scope'] ?? 'tenant'),
            'storage_engine' => (string)($params['storage_engine'] ?? ($isRemoteUri ? '' : 'local')),
            'storage_domain' => (string)($params['storage_domain'] ?? ''),
            'mime_type' => mb_substr(trim((string)($params['mime_type'] ?? '')), 0, 120, 'UTF-8'),
            'file_size' => (int)($params['file_size'] ?? 0),
            'width' => (int)($params['width'] ?? 0),
            'height' => (int)($params['height'] ?? 0),
            'duration' => (float)($params['duration'] ?? 0),
            'checksum' => mb_substr(trim((string)($params['checksum'] ?? '')), 0, 100, 'UTF-8'),
            'meta_json' => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status' => 'ready',
            'create_time' => $time,
            'update_time' => $time,
            'delete_time' => 0,
        ]);

        return self::formatAsset($asset->toArray());
    }

    public static function selectAsset(int $tenantId, int $userId, array $params): array
    {
        return self::selectCanvasAsset($tenantId, $userId, $params, 'canvas_asset_select');
    }

    public static function scriptMessage(int $tenantId, int $userId, array $params): array
    {
        return self::createCanvasScriptText($tenantId, $userId, $params, 'canvas_script_message');
    }

    public static function retryScript(int $tenantId, int $userId, array $params): array
    {
        throw new Exception('请基于原提示词重新发起无限画布脚本生成');
    }

    public static function cancelScript(int $tenantId, int $userId, array $params): array
    {
        return self::scriptPlanDetail($tenantId, $userId, $params);
    }

    private static function createCanvasScriptText(int $tenantId, int $userId, array $params, string $sourceType, ?callable $emit = null): array
    {
        $prompt = self::resolveScriptPrompt($params);
        if ($prompt === '') {
            throw new Exception('请输入脚本需求');
        }

        $payload = self::withCanvasSource($params, $sourceType);
        $payload['prompt'] = $prompt;
        $payload['content'] = $prompt;
        $payload['source_type'] = $sourceType;
        $payload['source_app_code'] = AigcCanvasService::APP_CODE;
        $payload['business_table'] = 'aigc_canvas_run';
        $payload['system_prompt'] = (string)($params['system_prompt'] ?? self::canvasScriptSystemPrompt());

        $streamContent = '';
        $onEvent = null;
        if ($emit) {
            $onEvent = static function (string $event, array $data) use ($emit, &$streamContent): void {
                if ($event === 'delta') {
                    $delta = (string)($data['delta'] ?? $data['content'] ?? '');
                    if ($delta !== '') {
                        $streamContent .= $delta;
                    }
                }
                $emit($event, $data);
            };
        }

        $result = $emit
            ? AigcCanvasService::streamText($tenantId, $userId, $payload, $onEvent)
            : AigcCanvasService::generateText($tenantId, $userId, $payload);

        $formatted = self::formatCanvasScriptTextResult($result, $streamContent);
        if ($emit) {
            $emit('task', $formatted);
            $emit('done', $formatted);
        }
        return $formatted;
    }

    private static function resolveScriptPrompt(array $params): string
    {
        foreach (['prompt', 'content', 'message', 'requirement', 'topic', 'title', 'script'] as $key) {
            $value = trim((string)($params[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }
        $messages = $params['messages'] ?? [];
        if (is_array($messages)) {
            $parts = [];
            foreach ($messages as $message) {
                if (is_array($message)) {
                    $content = trim((string)($message['content'] ?? $message['message'] ?? ''));
                    if ($content !== '') {
                        $parts[] = $content;
                    }
                }
            }
            if ($parts !== []) {
                return implode("\n", $parts);
            }
        }
        return '';
    }

    private static function canvasScriptSystemPrompt(): string
    {
        return '你是无限画布脚本策划 Agent。请基于用户需求生成可直接用于画布节点、分镜、图片/视频生成的结构化中文内容，保持清晰、可执行、不过度解释。';
    }

    private static function formatCanvasScriptTextResult(array $result, string $streamContent = ''): array
    {
        $content = trim((string)($result['content'] ?? $result['text'] ?? $result['answer'] ?? ''));
        if ($content === '') {
            $content = trim($streamContent);
        }
        $taskId = (int)($result['task_id'] ?? $result['consumption_id'] ?? 0);
        return [
            'task_id' => (string)$taskId,
            'id' => $taskId,
            'status' => (string)($result['status'] ?? 'success'),
            'content' => $content,
            'result' => [
                'content' => $content,
                'raw' => $result,
            ],
            'error' => (string)($result['error'] ?? ''),
            'provider' => (string)($result['provider'] ?? 'power_market'),
            'model_code' => (string)($result['model_code'] ?? ''),
            'consumption_id' => (int)($result['consumption_id'] ?? $taskId),
            'app_task_id' => (int)($result['app_task_id'] ?? 0),
        ];
    }

    private static function assetLists(int $tenantId, int $userId, array $params): array
    {
        $pageNo = max(1, (int)($params['page_no'] ?? 1));
        $pageSize = max(1, min(50, (int)($params['page_size'] ?? 15)));
        $query = AigcCanvasAsset::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'delete_time' => 0,
        ]);
        $projectId = (int)($params['project_id'] ?? 0);
        if ($projectId > 0) {
            $query->where('project_id', $projectId);
        }
        $assetType = trim((string)($params['asset_type'] ?? ''));
        if ($assetType !== '') {
            $types = $assetType === 'subject'
                ? ['subject', 'subject_image', 'subject_three_view']
                : [$assetType];
            $query->whereIn('asset_type', $types);
        }
        $keyword = trim((string)($params['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->whereLike('title', '%' . $keyword . '%');
        }
        $subjectId = (int)($params['subject_id'] ?? $params['item_id'] ?? 0);
        if ($subjectId > 0) {
            $query->whereLike('meta_json', '%"subject_id":"' . $subjectId . '"%');
        }
        $count = (int)(clone $query)->count();
        $rows = $query->order('id', 'desc')
            ->limit(($pageNo - 1) * $pageSize, $pageSize)
            ->select()
            ->toArray();
        return [
            'lists' => array_map([self::class, 'formatAsset'], $rows),
            'count' => $count,
            'page_no' => $pageNo,
            'page_size' => $pageSize,
        ];
    }

    private static function upsertCanvasAsset(int $tenantId, int $userId, array $params): array
    {
        $id = (int)($params['id'] ?? $params['asset_id'] ?? 0);
        $uri = FileService::setFileUrl((string)($params['uri'] ?? $params['url'] ?? $params['image'] ?? $params['image_url'] ?? ''));
        $assetType = self::normalizeAssetType((string)($params['asset_type'] ?? $params['type'] ?? 'reference_image'));
        $meta = is_array($params['meta'] ?? null) ? $params['meta'] : [];
        $meta = array_merge($meta, [
            'source_app_code' => AigcCanvasService::APP_CODE,
            'source_type' => (string)($params['source_type'] ?? 'canvas_asset'),
            'subject_id' => (string)($params['subject_id'] ?? $params['item_id'] ?? ''),
            'canvas_project_id' => (string)($params['project_id'] ?? $params['canvas_project_id'] ?? ''),
            'canvas_node_id' => (string)($params['node_id'] ?? $params['canvas_node_id'] ?? ''),
            'description' => (string)($params['description'] ?? $meta['description'] ?? ''),
        ]);
        $isRemoteUri = preg_match('/^https?:\/\//i', $uri) === 1;
        $data = [
            'project_id' => (int)($params['project_id'] ?? $params['canvas_project_id'] ?? 0),
            'task_id' => (string)($params['task_id'] ?? ''),
            'shot_id' => (string)($params['shot_id'] ?? ''),
            'asset_type' => $assetType,
            'title' => mb_substr(trim((string)($params['title'] ?? $params['name'] ?? '')), 0, 120, 'UTF-8'),
            'uri' => $uri,
            'cover_uri' => FileService::setFileUrl((string)($params['cover_uri'] ?? $params['cover_url'] ?? '')),
            'storage_scope' => (string)($params['storage_scope'] ?? 'tenant'),
            'storage_engine' => (string)($params['storage_engine'] ?? ($isRemoteUri ? '' : 'local')),
            'storage_domain' => (string)($params['storage_domain'] ?? ''),
            'mime_type' => mb_substr(trim((string)($params['mime_type'] ?? '')), 0, 120, 'UTF-8'),
            'file_size' => (int)($params['file_size'] ?? 0),
            'width' => (int)($params['width'] ?? 0),
            'height' => (int)($params['height'] ?? 0),
            'duration' => (float)($params['duration'] ?? 0),
            'checksum' => mb_substr(trim((string)($params['checksum'] ?? '')), 0, 100, 'UTF-8'),
            'meta_json' => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status' => (string)($params['status'] ?? 'ready'),
            'update_time' => time(),
        ];
        if ($id > 0) {
            $asset = AigcCanvasAsset::where([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'id' => $id,
                'delete_time' => 0,
            ])->findOrEmpty();
            if (!$asset->isEmpty()) {
                $asset->save($data);
                return self::formatAsset($asset->toArray());
            }
        }
        $data += [
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'create_time' => time(),
            'delete_time' => 0,
        ];
        $asset = AigcCanvasAsset::create($data);
        $formatted = self::formatAsset($asset->toArray());
        if ($projectId > 0 && self::shouldMergeBrandMemory($assetType, $params)) {
            BrandMemoryService::mergeAsset($tenantId, $userId, $projectId, $formatted, $params + [
                'asset_type' => $assetType,
                'source' => 'canvas_asset_register',
            ]);
        }

        return $formatted;
    }

    private static function selectCanvasAsset(int $tenantId, int $userId, array $params, string $sourceType): array
    {
        $assetId = (int)($params['asset_id'] ?? $params['id'] ?? $params['image_asset_id'] ?? $params['video_asset_id'] ?? 0);
        if ($assetId <= 0) {
            throw new Exception('请选择素材');
        }
        $asset = AigcCanvasAsset::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'id' => $assetId,
            'delete_time' => 0,
        ])->findOrEmpty();
        if ($asset->isEmpty()) {
            throw new Exception('素材不存在');
        }
        $row = $asset->toArray();
        $meta = self::arrayValue($row['meta_json'] ?? []);
        $meta['selected'] = true;
        $meta['source_type'] = $sourceType;
        if (!empty($params['subject_id'])) {
            $meta['subject_id'] = (string)$params['subject_id'];
        }
        $asset->save([
            'meta_json' => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'update_time' => time(),
        ]);
        return self::formatAsset($asset->toArray());
    }

    private static function saveCanvasStructuredNode(int $tenantId, int $userId, array $params, string $variant, string $sourceType, string $title, array $metadata): array
    {
        $params = self::withCanvasSource($params, $sourceType);
        $node = self::upsertProjectNode($tenantId, $userId, (int)($params['project_id'] ?? 0), [
            'node_id' => (string)($params['node_id'] ?? $params[$variant . '_node_id'] ?? ''),
            'type' => $variant === 'storyboardGroup' ? 'text' : 'text',
            'variant' => $variant,
            'title' => (string)($params['title'] ?? $title),
            'metadata' => $metadata + [
                'status' => 'success',
                'source_type' => $sourceType,
                'content' => (string)($params['content'] ?? $params['prompt'] ?? ''),
            ],
        ]);
        return [
            'project_id' => (int)($params['project_id'] ?? 0),
            'node' => $node,
            'storyboard' => (array)($node['metadata']['storyboard'] ?? []),
            'shots' => (array)($node['metadata']['shots'] ?? []),
            'visual_plan' => (array)($node['metadata']['visual_plan'] ?? []),
        ];
    }

    private static function upsertProjectNode(int $tenantId, int $userId, int $projectId, array $node): array
    {
        if ($projectId <= 0) {
            return self::formatCanvasNode($node);
        }
        $project = AigcCanvasProject::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'id' => $projectId,
            'delete_time' => 0,
        ])->findOrEmpty();
        if ($project->isEmpty()) {
            throw new Exception('项目不存在');
        }
        $nodes = self::arrayValue($project['nodes_json'] ?? []);
        $formatted = self::formatCanvasNode($node);
        $found = false;
        foreach ($nodes as $index => $item) {
            if (is_array($item) && (string)($item['id'] ?? '') === (string)$formatted['id']) {
                $nodes[$index] = array_replace_recursive($item, $formatted);
                $found = true;
                break;
            }
        }
        if (!$found) {
            $nodes[] = $formatted;
        }
        $project->save([
            'nodes_json' => $nodes,
            'update_time' => time(),
        ]);
        return $formatted;
    }

    private static function formatCanvasNode(array $node): array
    {
        $variant = (string)($node['variant'] ?? 'text');
        $type = (string)($node['type'] ?? ($variant === 'subject' ? 'image' : 'text'));
        $id = trim((string)($node['node_id'] ?? $node['id'] ?? ''));
        if ($id === '') {
            $id = $variant . '_' . time() . '_' . mt_rand(1000, 9999);
        }
        $metadata = is_array($node['metadata'] ?? null) ? $node['metadata'] : [];
        $metadata['variant'] = $variant;
        return [
            'id' => $id,
            'type' => $type,
            'title' => (string)($node['title'] ?? '画布节点'),
            'position' => is_array($node['position'] ?? null) ? $node['position'] : ['x' => 120, 'y' => 120],
            'width' => (int)($node['width'] ?? 280),
            'height' => (int)($node['height'] ?? 180),
            'metadata' => $metadata,
        ];
    }

    private static function projectStoryboardShots(int $tenantId, int $userId, array $params): array
    {
        $incoming = self::arrayValue($params['shots'] ?? $params['storyboard'] ?? []);
        if ($incoming !== []) {
            return array_map([self::class, 'normalizeStoryboardShot'], $incoming);
        }
        $projectId = (int)($params['project_id'] ?? 0);
        if ($projectId <= 0) {
            return [];
        }
        $project = AigcCanvasProject::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'id' => $projectId,
            'delete_time' => 0,
        ])->findOrEmpty();
        if ($project->isEmpty()) {
            return [];
        }
        foreach (self::arrayValue($project['nodes_json'] ?? []) as $node) {
            if (!is_array($node)) {
                continue;
            }
            $metadata = is_array($node['metadata'] ?? null) ? $node['metadata'] : (is_array($node['data'] ?? null) ? $node['data'] : []);
            if ((string)($metadata['variant'] ?? $node['variant'] ?? '') !== 'storyboardGroup') {
                continue;
            }
            $shots = self::arrayValue($metadata['shots'] ?? $metadata['storyboard'] ?? []);
            return array_map([self::class, 'normalizeStoryboardShot'], $shots);
        }
        return [];
    }

    private static function normalizeStoryboardShot($value): array
    {
        $shot = is_array($value) ? $value : [];
        if (empty($shot['id']) && empty($shot['shot_id'])) {
            $shot['id'] = 'shot_' . time() . '_' . mt_rand(1000, 9999);
        }
        $shot['title'] = (string)($shot['title'] ?? $shot['name'] ?? '分镜');
        return $shot;
    }

    private static function withCanvasSource(array $params, string $source): array
    {
        $meta = is_array($params['meta'] ?? null) ? $params['meta'] : [];
        $params['source_app_code'] = AigcCanvasService::APP_CODE;
        $params['source_type'] = (string)($params['source_type'] ?? $source);
        $params['meta'] = array_merge($meta, [
            'source_app_code' => AigcCanvasService::APP_CODE,
            'source_type' => (string)$params['source_type'],
            'canvas_project_id' => (string)($params['canvas_project_id'] ?? ''),
            'canvas_id' => (string)($params['canvas_id'] ?? ''),
            'canvas_node_id' => (string)($params['canvas_node_id'] ?? $params['node_id'] ?? ''),
        ]);
        return $params;
    }

    private static function normalizeAssetType(string $type): string
    {
        return in_array($type, [
            'reference_image',
            'reference_video',
            'reference_audio',
            'logo',
            'brand_logo',
            'brand_asset',
            'subject',
            'subject_image',
            'subject_three_view',
            'scene_image',
            'shot_image',
            'shot_video',
        ], true) ? $type : 'reference_image';
    }

    private static function shouldMergeBrandMemory(string $assetType, array $params): bool
    {
        return !empty($params['write_brand_memory'])
            || !empty($params['brand_url'])
            || !empty($params['official_url'])
            || !empty($params['reference_url'])
            || in_array($assetType, ['brand_logo', 'logo', 'brand_asset'], true);
    }

    public static function formatAsset(array $row): array
    {
        $uri = (string)($row['uri'] ?? '');
        $coverUri = (string)($row['cover_uri'] ?? '');
        $storageScope = (string)($row['storage_scope'] ?? '');
        $storageEngine = (string)($row['storage_engine'] ?? '');
        $storageDomain = (string)($row['storage_domain'] ?? '');
        $meta = json_decode((string)($row['meta_json'] ?? ''), true);
        return [
            'id' => (int)($row['id'] ?? 0),
            'project_id' => (int)($row['project_id'] ?? 0),
            'task_id' => (string)($row['task_id'] ?? ''),
            'shot_id' => (string)($row['shot_id'] ?? ''),
            'asset_type' => (string)($row['asset_type'] ?? ''),
            'title' => (string)($row['title'] ?? ''),
            'uri' => $uri,
            'url' => $uri === '' ? '' : FileService::getFileUrlByStorage($uri, $storageScope, $storageEngine, $storageDomain),
            'preview_url' => (string)($meta['preview_url'] ?? ''),
            'cover_url' => $coverUri === '' ? '' : FileService::getFileUrlByStorage($coverUri, $storageScope, $storageEngine, $storageDomain),
            'cover_preview_url' => (string)($meta['cover_preview_url'] ?? ''),
            'mime_type' => (string)($row['mime_type'] ?? ''),
            'file_size' => (int)($row['file_size'] ?? 0),
            'width' => (int)($row['width'] ?? 0),
            'height' => (int)($row['height'] ?? 0),
            'duration' => (float)($row['duration'] ?? 0),
            'status' => (string)($row['status'] ?? 'ready'),
            'meta' => is_array($meta) ? $meta : [],
            'created_at' => (int)($row['create_time'] ?? 0),
        ];
    }
}
