<?php

namespace app\common\service\app\aigc_short_drama;

use app\common\model\app\aigc_short_drama\AigcShortDramaConfig;
use app\common\model\app\aigc_short_drama\AigcShortDramaAgentRun;
use app\common\model\app\aigc_short_drama\AigcShortDramaAgentStepLog;
use app\common\model\app\aigc_short_drama\AigcShortDramaAsset;
use app\common\model\app\aigc_short_drama\AigcShortDramaGenerationTask;
use app\common\model\app\aigc_short_drama\AigcShortDramaInspiration;
use app\common\model\app\aigc_short_drama\AigcShortDramaPlanVersion;
use app\common\model\app\aigc_short_drama\AigcShortDramaPublishedWork;
use app\common\model\app\aigc_short_drama\AigcShortDramaProject;
use app\common\model\app\aigc_short_drama\AigcShortDramaScriptTask;
use app\common\model\app\aigc_short_drama\AigcShortDramaStoryboard;
use app\common\model\app\aigc_short_drama\AigcShortDramaStyle;
use app\common\model\app\aigc_short_drama\AigcShortDramaSubject;
use app\common\model\ai\AiConsumptionLog;
use app\common\model\tenant\Tenant;
use app\common\model\user\User;
use app\common\service\app\aigc_image\AigcImageChannelService;
use app\common\service\app\aigc_image\AigcImageService;
use app\common\service\app\aigc_music\AigcMusicService;
use app\common\service\app\aigc_digital_human\AigcDigitalHumanService;
use app\common\service\power\MarketTextModelRuntimeService;
use app\common\service\power\MarketImageModelRuntimeService;
use app\common\service\power\MarketMusicAppRuntimeService;
use app\common\service\power\MarketNanoBananaAppRuntimeService;
use app\common\service\power\MarketVideoAppRuntimeService;
use app\common\service\power\MarketVideoModelRuntimeService;
use app\common\service\app\aigc_video\AigcVideoChannelService;
use app\common\service\app\aigc_video\AigcVideoService;
use app\common\service\app\AppDisplayConfigService;
use app\common\service\FileService;
use app\common\service\point\PointService;
use app\common\service\storage\Driver as StorageDriver;
use app\common\service\storage\StorageConfigService;
use Exception;
use think\facade\Db;
use think\facade\Log;

class AigcShortDramaService
{
    public const APP_CODE = 'aigc_short_drama';
    public const STATUS_PENDING = 'pending';
    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELED = 'canceled';
    public const PROJECT_STATUS_PLANNING = 'planning';
    public const PROJECT_STATUS_PLAN_REVIEW = 'plan_review';
    public const PROJECT_STATUS_ASSET_GENERATING = 'asset_generating';
    public const PROJECT_STATUS_VIDEO_GENERATING = 'video_generating';
    public const PROJECT_STATUS_PUBLISH_REVIEWING = 'publish_reviewing';
    public const PROJECT_STATUS_PUBLISHED = 'published';
    public const LLM_APP_CODE = 'power_market_text';
    public const IMAGE_APP_CODE = 'aigc_image';
    public const VIDEO_APP_CODE = 'aigc_video';
    public const MUSIC_APP_CODE = 'aigc_music';

    private const SAFE_ERROR = '生成失败，请稍后重试';
    private const DEFAULT_IMAGE = 'resource/image/common/menu_generator.png';
    private const SCRIPT_PLAN_STALE_SECONDS = 600;
    private const SCRIPT_PLAN_STREAM_RECOVER_SECONDS = 45;
    private const SCRIPT_PLAN_STALE_ERROR = '剧本生成连接已中断，请重试';
    private const SCRIPT_PLAN_STREAM_FLUSH_SECONDS = 2;
    private const SCRIPT_PLAN_FIXED_MODEL_CODES = ['qwen36plus'];
    private const LEGACY_PUBLIC_SUBJECT_NAMES = ['清冷师妹', '赛艇少年'];

    public static function config(int $tenantId): array
    {
        $config = self::publicConfig($tenantId);
        $config['dependencies'] = self::dependencies($tenantId);
        return AppDisplayConfigService::appendToConfig($tenantId, self::APP_CODE, $config);
    }

    public static function adminStat(int $tenantId = 0): array
    {
        $countRows = static function (string $modelClass, string $status = '') use ($tenantId): int {
            $query = $modelClass::where('delete_time', 0);
            if ($tenantId > 0) {
                $query->where('tenant_id', $tenantId);
            }
            if ($status !== '') {
                $query->where('status', $status);
            }
            return (int)$query->count();
        };

        return [
            'project_total' => $countRows(AigcShortDramaProject::class),
            'script_task_total' => $countRows(AigcShortDramaScriptTask::class),
            'storyboard_total' => $countRows(AigcShortDramaStoryboard::class),
            'generation_task_total' => $countRows(AigcShortDramaGenerationTask::class),
            'generation_task_success' => $countRows(AigcShortDramaGenerationTask::class, self::STATUS_SUCCESS),
            'generation_task_failed' => $countRows(AigcShortDramaGenerationTask::class, self::STATUS_FAILED),
        ];
    }

    /**
     * Platform-only tenant aggregates. This intentionally contains no project or task details.
     */
    public static function adminTenantStatLists(array $params = []): array
    {
        $tenantId = max(0, (int)($params['tenant_id'] ?? 0));
        $pageNo = max(1, (int)($params['page_no'] ?? 1));
        $pageSize = min(100, max(1, (int)($params['page_size'] ?? 15)));
        $stats = [];

        $mergeGroupedRows = static function (string $modelClass, string $statKey) use (&$stats, $tenantId): void {
            $query = $modelClass::where('delete_time', 0)->where('tenant_id', '>', 0);
            if ($tenantId > 0) {
                $query->where('tenant_id', $tenantId);
            }
            $rows = $query
                ->field('tenant_id, COUNT(*) AS total, MAX(update_time) AS last_activity_time')
                ->group('tenant_id')
                ->select()
                ->toArray();
            foreach ($rows as $row) {
                $id = (int)($row['tenant_id'] ?? 0);
                if ($id <= 0) {
                    continue;
                }
                if (!isset($stats[$id])) {
                    $stats[$id] = [
                        'tenant_id' => $id,
                        'project_total' => 0,
                        'script_task_total' => 0,
                        'storyboard_total' => 0,
                        'generation_task_total' => 0,
                        'generation_task_success' => 0,
                        'generation_task_failed' => 0,
                        'last_activity_time' => 0,
                    ];
                }
                $stats[$id][$statKey] = (int)($row['total'] ?? 0);
                $stats[$id]['last_activity_time'] = max(
                    (int)$stats[$id]['last_activity_time'],
                    (int)($row['last_activity_time'] ?? 0)
                );
            }
        };

        $mergeGroupedRows(AigcShortDramaProject::class, 'project_total');
        $mergeGroupedRows(AigcShortDramaScriptTask::class, 'script_task_total');
        $mergeGroupedRows(AigcShortDramaStoryboard::class, 'storyboard_total');
        $mergeGroupedRows(AigcShortDramaGenerationTask::class, 'generation_task_total');

        foreach ([self::STATUS_SUCCESS => 'generation_task_success', self::STATUS_FAILED => 'generation_task_failed'] as $status => $statKey) {
            $query = AigcShortDramaGenerationTask::where('delete_time', 0)
                ->where('tenant_id', '>', 0)
                ->where('status', $status);
            if ($tenantId > 0) {
                $query->where('tenant_id', $tenantId);
            }
            foreach ($query->field('tenant_id, COUNT(*) AS total')->group('tenant_id')->select()->toArray() as $row) {
                $id = (int)($row['tenant_id'] ?? 0);
                if ($id > 0 && isset($stats[$id])) {
                    $stats[$id][$statKey] = (int)($row['total'] ?? 0);
                }
            }
        }

        $tenantRows = empty($stats)
            ? []
            : Tenant::whereIn('id', array_keys($stats))->field('id,name,sn')->select()->toArray();
        $tenants = [];
        foreach ($tenantRows as $row) {
            $tenants[(int)$row['id']] = $row;
        }
        foreach ($stats as $id => &$row) {
            $row['tenant_name'] = (string)($tenants[$id]['name'] ?? '');
            $row['tenant_sn'] = (string)($tenants[$id]['sn'] ?? '');
        }
        unset($row);

        $rows = array_values($stats);
        usort($rows, static function (array $left, array $right): int {
            $byActivity = (int)$right['last_activity_time'] <=> (int)$left['last_activity_time'];
            return $byActivity !== 0 ? $byActivity : ((int)$right['tenant_id'] <=> (int)$left['tenant_id']);
        });
        $count = count($rows);

        return [
            'lists' => array_slice($rows, ($pageNo - 1) * $pageSize, $pageSize),
            'count' => $count,
            'page_no' => $pageNo,
            'page_size' => $pageSize,
        ];
    }

    public static function repairLegacyPromptData(int $projectId = 0, string $taskId = '', int $limit = 0, bool $apply = false): array
    {
        $result = [
            'storyboards_scanned' => 0,
            'storyboards_repaired' => 0,
            'script_tasks_scanned' => 0,
            'script_tasks_repaired' => 0,
            'plan_versions_scanned' => 0,
            'plan_versions_repaired' => 0,
        ];
        $limit = max(0, min(10000, $limit));
        $taskId = trim($taskId);
        $storyboardQuery = AigcShortDramaStoryboard::where('delete_time', 0)->order(['project_id' => 'asc', 'task_id' => 'asc', 'sort' => 'asc', 'id' => 'asc']);
        if ($projectId > 0) {
            $storyboardQuery->where('project_id', $projectId);
        }
        if ($taskId !== '') {
            $storyboardQuery->where('task_id', $taskId);
        }
        if ($limit > 0) {
            $storyboardQuery->limit($limit);
        }
        foreach ($storyboardQuery->select()->toArray() as $index => $row) {
            $result['storyboards_scanned']++;
            $next = self::normalizeLegacyStoryboardPromptData($row, $index);
            $changes = self::legacyStoryboardPromptChanges($row, $next);
            if (empty($changes)) {
                continue;
            }
            $result['storyboards_repaired']++;
            if ($apply) {
                $changes['update_time'] = time();
                AigcShortDramaStoryboard::where('id', (int)$row['id'])->update($changes);
            }
        }

        $normalizeJsonRows = static function (string $modelClass, string $jsonField) use ($projectId, $taskId, $limit, $apply, &$result): void {
            $query = $modelClass::where('delete_time', 0)->order('id', 'asc');
            if ($projectId > 0) {
                $query->where('project_id', $projectId);
            }
            if ($taskId !== '') {
                $query->where('task_id', $taskId);
            }
            if ($limit > 0) {
                $query->limit($limit);
            }
            foreach ($query->select()->toArray() as $row) {
                $counter = $jsonField === 'result_json' ? 'script_tasks' : 'plan_versions';
                $result[$counter . '_scanned']++;
                $plan = self::jsonDecode((string)($row[$jsonField] ?? ''));
                if (empty($plan)) {
                    continue;
                }
                if ($jsonField === 'plan_json' && empty($plan['storyboard'])) {
                    $plan['storyboard'] = self::jsonDecode((string)($row['storyboard_json'] ?? ''));
                }
                $next = self::normalizeLegacyPromptPlanData($plan);
                if ($next === $plan) {
                    continue;
                }
                $result[$counter . '_repaired']++;
                if (!$apply) {
                    continue;
                }
                $changes = [$jsonField => self::jsonEncode($next), 'update_time' => time()];
                if ($jsonField === 'plan_json') {
                    $changes['storyboard_json'] = self::jsonEncode((array)($next['storyboard'] ?? []));
                    $changes['title'] = self::localizeGenerationPromptText((string)($row['title'] ?? ''));
                }
                $modelClass::where('id', (int)$row['id'])->update($changes);
            }
        };
        $normalizeJsonRows(AigcShortDramaScriptTask::class, 'result_json');
        $normalizeJsonRows(AigcShortDramaPlanVersion::class, 'plan_json');
        return $result;
    }

    public static function dependencies(int $tenantId = 0): array
    {
        $groups = self::dependencyModelGroups($tenantId);
        $script = self::modelGroupByKey($groups, 'script_plan');
        $vision = self::modelGroupByKey($groups, 'vision_describe');
        $image = self::modelGroupByKey($groups, 'image');
        $video = self::modelGroupByKey($groups, 'video');
        $scriptModel = self::configuredScriptPlanModel($tenantId, [], false);
        $visionModel = self::configuredVisionModel($tenantId, [], false);
        $scriptItem = self::marketDependencyItem('剧本策划文本模型', '用于故事扩写、剧本策划与分镜文本生成', '模型 API', (array)($script['options'] ?? []));
        $scriptItem['channel_ready'] = !empty($scriptModel);
        $scriptItem['ready'] = !empty($script['options']) && !empty($scriptModel);
        $scriptItem['message'] = empty($script['options'])
            ? '暂无租户可用的文本模型'
            : (empty($scriptModel) ? '请上架或选择 Qwen3.6-Plus 作为剧本固定模型' : '已固定为 ' . (string)$scriptModel['name']);
        $items = [
            $scriptItem,
            [
                'resource_type' => 'model_api',
                'resource_type_label' => '模型 API',
                'name' => '视觉文本模型',
                'required_for' => '用于参考图理解与主体、场景描述生成',
                'available' => !empty($vision['options']) && !empty($visionModel),
                'channel_ready' => !empty($visionModel),
                'ready' => !empty($vision['options']) && !empty($visionModel),
                'channel_count' => count((array)($vision['options'] ?? [])),
                'message' => empty($vision['options'])
                    ? '暂无租户可用的视觉文本模型'
                    : (empty($visionModel) ? '请在下方选择固定视觉文本模型' : '已固定为 ' . (string)$visionModel['name']),
            ],
            self::marketDependencyItem('图片模型/API', '用于主体图、场景图和分镜图生成', '模型 API / 应用 API', (array)($image['options'] ?? []), 'mixed'),
            self::marketDependencyItem('视频模型/API', '用于短剧分镜视频生成', '模型 API / 应用 API', (array)($video['options'] ?? []), 'mixed'),
            MarketMusicAppRuntimeService::availability($tenantId),
        ];
        return [
            'items' => $items,
            'ready' => count(array_filter($items, fn($item) => !empty($item['ready']))) === count($items),
        ];
    }

    public static function voiceLists(int $tenantId, int $userId, string $source = ''): array
    {
        $rows = AigcDigitalHumanService::voiceLists($tenantId, $userId, $source);
        $rows = array_values(array_filter($rows, static function (array $row): bool {
            $status = (string)($row['status'] ?? 'ready');
            return $status === '' || $status === 'ready';
        }));
        return self::sanitizeUtf8Payload($rows);
    }

    public static function adminPublicVoiceLists(int $tenantId, array $params = []): array
    {
        $rows = AigcDigitalHumanService::voiceLists($tenantId, 0, 'official');
        $keyword = trim((string)($params['keyword'] ?? ''));
        $status = trim((string)($params['status'] ?? ''));
        if ($keyword !== '') {
            $rows = array_values(array_filter($rows, static function (array $row) use ($keyword): bool {
                $haystack = implode(' ', [
                    (string)($row['name'] ?? ''),
                    (string)($row['provider_asset_id'] ?? ''),
                    (string)($row['audio_uri'] ?? ''),
                ]);
                return stripos($haystack, $keyword) !== false;
            }));
        }
        if ($status !== '') {
            $rows = array_values(array_filter($rows, static fn(array $row): bool => (string)($row['status'] ?? 'ready') === $status));
        }
        $pageNo = max(1, (int)($params['page_no'] ?? 1));
        $pageSize = min(100, max(1, (int)($params['page_size'] ?? 15)));
        $count = count($rows);
        $rows = array_slice($rows, ($pageNo - 1) * $pageSize, $pageSize);
        return self::sanitizeUtf8Payload([
            'lists' => $rows,
            'count' => $count,
            'page_no' => $pageNo,
            'page_size' => $pageSize,
        ]);
    }

    public static function saveAdminPublicVoice(int $tenantId, array $params): array
    {
        return self::sanitizeUtf8Payload(AigcDigitalHumanService::savePublicVoice($tenantId, $params));
    }

    public static function deleteAdminPublicVoice(int $tenantId, int $id): void
    {
        AigcDigitalHumanService::deletePublicVoice($tenantId, $id);
    }

    public static function saveConfig(int $tenantId, array $params): void
    {
        AppDisplayConfigService::saveFromConfigPayload($tenantId, self::APP_CODE, $params);
        $current = self::publicConfig($tenantId);
        $config = $current;
        unset($config['status'], $config['display_config'], $config['model_groups']);

        if (array_key_exists('script_plan_points', $params)) {
            unset($config['script_plan_points']);
        }
        if (isset($params['background']) && is_array($params['background'])) {
            $config['background'] = self::normalizeBackgroundConfig($params['background'], (array)($current['background'] ?? []));
        }
        if (isset($params['ratios']) && is_array($params['ratios'])) {
            $config['ratios'] = self::normalizeRatioConfig($params['ratios'], (array)($current['ratios'] ?? []));
        }
        if (array_key_exists('prompt_max_length', $params)) {
            $config['prompt_max_length'] = max(0, min(200000, (int)$params['prompt_max_length']));
        }
        if (array_key_exists('script_plan_model_id', $params)) {
            $scriptModelId = trim((string)$params['script_plan_model_id']);
            if ($scriptModelId === '') {
                unset($config['script_plan_model_id'], $config['script_plan_model_selection']);
            } else {
                $scriptModel = MarketTextModelRuntimeService::resolveModel($tenantId, $scriptModelId, false);
                $config['script_plan_model_id'] = (string)$scriptModel['id'];
                $config['script_plan_model_selection'] = self::marketModelSnapshot($scriptModel);
            }
        }
        if (array_key_exists('vision_model_id', $params)) {
            $visionModelId = trim((string)$params['vision_model_id']);
            if ($visionModelId === '') {
                unset($config['vision_model_id'], $config['vision_model_selection']);
            } else {
                $visionModel = MarketTextModelRuntimeService::resolveModel($tenantId, $visionModelId, true);
                $config['vision_model_id'] = (string)$visionModel['id'];
                $config['vision_model_selection'] = self::marketModelSnapshot($visionModel);
            }
        }
        if (isset($params['storyboard_rules']) && is_array($params['storyboard_rules'])) {
            $config['storyboard_rules'] = self::normalizeStoryboardRules($params['storyboard_rules']);
        }
        if (isset($params['export_watermark']) && is_array($params['export_watermark'])) {
            $config['export_watermark'] = self::normalizeExportWatermarkConfig($params['export_watermark']);
        }
        // Short-drama prices are owned by its LLM, image, and video dependencies.
        // Clear legacy overrides so future dependency price changes take effect immediately.
        $config['price_config'] = [];
        unset($config['script_plan_points']);

        $data = [
            'tenant_id' => $tenantId,
            'status' => array_key_exists('status', $params) ? (int)$params['status'] : (int)($current['status'] ?? 1),
            'config_json' => self::jsonEncode($config),
            'update_time' => time(),
        ];
        $row = AigcShortDramaConfig::where('tenant_id', $tenantId)->findOrEmpty();
        if ($row->isEmpty()) {
            $data['create_time'] = time();
            AigcShortDramaConfig::create($data);
            return;
        }
        $row->save($data);
    }

    public static function adminProjectLists(int $tenantId, array $params = []): array
    {
        $query = AigcShortDramaProject::alias('p')
            ->leftJoin('user u', 'u.id = p.user_id AND u.tenant_id = p.tenant_id')
            ->field('p.*,u.nickname user_nickname,u.account user_account,u.mobile user_mobile')
            ->where('p.delete_time', 0)
            ->order(['p.update_time' => 'desc', 'p.id' => 'desc']);
        if ($tenantId > 0) {
            $query->where('p.tenant_id', $tenantId);
        }

        $status = trim((string)($params['status'] ?? ''));
        if ($status !== '') {
            $query->where('p.status', $status);
        }
        $keyword = trim((string)($params['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->whereLike('p.title|p.prompt', '%' . $keyword . '%');
        }
        $userKeyword = trim((string)($params['user_keyword'] ?? ''));
        if ($userKeyword !== '') {
            $query->where(function ($query) use ($userKeyword) {
                $query->whereLike('u.nickname', '%' . $userKeyword . '%')
                    ->whereOrLike('u.account', '%' . $userKeyword . '%')
                    ->whereOrLike('u.mobile', '%' . $userKeyword . '%');
                if (ctype_digit($userKeyword)) {
                    $query->whereOr('p.user_id', (int)$userKeyword);
                }
            });
        }

        $pageNo = max(1, (int)($params['page_no'] ?? 1));
        $pageSize = min(100, max(1, (int)($params['page_size'] ?? 15)));
        $count = (int)(clone $query)->count();
        $rows = $query->limit(($pageNo - 1) * $pageSize, $pageSize)->select()->toArray();

        return self::sanitizeUtf8Payload([
            'lists' => array_map([self::class, 'formatAdminProject'], $rows),
            'count' => $count,
            'page_no' => $pageNo,
            'page_size' => $pageSize,
        ]);
    }

    public static function adminScriptTaskLists(int $tenantId, array $params = []): array
    {
        $query = AigcShortDramaScriptTask::alias('t')
            ->leftJoin('aigc_short_drama_project p', 'p.id = t.project_id AND p.tenant_id = t.tenant_id AND p.delete_time = 0')
            ->leftJoin('user u', 'u.id = t.user_id AND u.tenant_id = t.tenant_id')
            ->field('t.*,p.title project_title,p.cover_url project_cover_url,p.ratio project_ratio,p.episode_count project_episode_count,p.status project_status,u.nickname user_nickname,u.account user_account,u.mobile user_mobile')
            ->where('t.delete_time', 0)
            ->order(['t.create_time' => 'desc', 't.id' => 'desc']);
        if ($tenantId > 0) {
            $query->where('t.tenant_id', $tenantId);
        }

        $status = trim((string)($params['status'] ?? ''));
        $keyword = trim((string)($params['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->whereLike('t.task_id|t.prompt|p.title', '%' . $keyword . '%');
        }
        $userKeyword = trim((string)($params['user_keyword'] ?? ''));
        if ($userKeyword !== '') {
            $query->where(function ($query) use ($userKeyword) {
                $query->whereLike('u.nickname', '%' . $userKeyword . '%')
                    ->whereOrLike('u.account', '%' . $userKeyword . '%')
                    ->whereOrLike('u.mobile', '%' . $userKeyword . '%');
                if (ctype_digit($userKeyword)) {
                    $query->whereOr('t.user_id', (int)$userKeyword);
                }
            });
        }
        $start = strtotime((string)($params['start_time'] ?? $params['create_start_time'] ?? '')) ?: 0;
        $end = strtotime((string)($params['end_time'] ?? $params['create_end_time'] ?? '')) ?: 0;
        if ($start > 0) {
            $query->where('t.create_time', '>=', $start);
        }
        if ($end > 0) {
            $query->where('t.create_time', '<=', $end + 86399);
        }

        $pageNo = max(1, (int)($params['page_no'] ?? 1));
        $pageSize = min(100, max(1, (int)($params['page_size'] ?? 15)));
        $count = (int)(clone $query)->count();
        $rows = $query->limit(($pageNo - 1) * $pageSize, $pageSize)->select()->toArray();
        foreach ($rows as &$row) {
            $row = self::recoverPartialStreamScriptPlanTask((int)($row['tenant_id'] ?? $tenantId), (int)($row['user_id'] ?? 0), $row);
        }
        unset($row);

        return self::sanitizeUtf8Payload([
            'lists' => array_map([self::class, 'formatAdminScriptTask'], $rows),
            'count' => $count,
            'page_no' => $pageNo,
            'page_size' => $pageSize,
        ]);
    }

    public static function adminSubjectTaskLists(int $tenantId, array $params = []): array
    {
        return self::adminPlanItemLists($tenantId, $params, 'subjects');
    }

    public static function adminSceneTaskLists(int $tenantId, array $params = []): array
    {
        return self::adminPlanItemLists($tenantId, $params, 'locations');
    }

    public static function adminGenerationTaskLists(int $tenantId, array $params = []): array
    {
        $query = AigcShortDramaGenerationTask::alias('g')
            ->leftJoin('aigc_short_drama_project p', 'p.id = g.project_id AND p.tenant_id = g.tenant_id AND p.delete_time = 0')
            ->leftJoin('user u', 'u.id = g.user_id AND u.tenant_id = g.tenant_id')
            ->field('g.*,p.title project_title,p.cover_url project_cover_url,p.ratio project_ratio,p.status project_status,u.nickname user_nickname,u.account user_account,u.mobile user_mobile')
            ->where('g.delete_time', 0)
            ->order(['g.create_time' => 'desc', 'g.id' => 'desc']);
        if ($tenantId > 0) {
            $query->where('g.tenant_id', $tenantId);
        }

        $taskType = trim((string)($params['task_type'] ?? $params['task_types'] ?? ''));
        if ($taskType !== '') {
            $types = array_values(array_filter(array_map('trim', explode(',', $taskType))));
            if (!empty($types)) {
                $query->whereIn('g.task_type', $types);
            }
        }
        $status = trim((string)($params['status'] ?? ''));
        if ($status !== '') {
            $query->where('g.status', $status);
        }
        $keyword = trim((string)($params['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->whereLike('g.task_id|g.shot_id|g.error_msg|p.title', '%' . $keyword . '%');
        }
        $projectKeyword = trim((string)($params['project_keyword'] ?? ''));
        if ($projectKeyword !== '') {
            $query->whereLike('p.title', '%' . $projectKeyword . '%');
        }
        $userKeyword = trim((string)($params['user_keyword'] ?? ''));
        if ($userKeyword !== '') {
            $query->where(function ($query) use ($userKeyword) {
                $query->whereLike('u.nickname', '%' . $userKeyword . '%')
                    ->whereOrLike('u.account', '%' . $userKeyword . '%')
                    ->whereOrLike('u.mobile', '%' . $userKeyword . '%');
                if (ctype_digit($userKeyword)) {
                    $query->whereOr('g.user_id', (int)$userKeyword);
                }
            });
        }
        $start = strtotime((string)($params['start_time'] ?? $params['create_start_time'] ?? '')) ?: 0;
        $end = strtotime((string)($params['end_time'] ?? $params['create_end_time'] ?? '')) ?: 0;
        if ($start > 0) {
            $query->where('g.create_time', '>=', $start);
        }
        if ($end > 0) {
            $query->where('g.create_time', '<=', $end + 86399);
        }

        $pageNo = max(1, (int)($params['page_no'] ?? 1));
        $pageSize = min(100, max(1, (int)($params['page_size'] ?? 15)));
        $count = (int)(clone $query)->count();
        $rows = $query->limit(($pageNo - 1) * $pageSize, $pageSize)->select()->toArray();

        return self::sanitizeUtf8Payload([
            'lists' => array_map([self::class, 'formatAdminGenerationTask'], $rows),
            'count' => $count,
            'page_no' => $pageNo,
            'page_size' => $pageSize,
        ]);
    }

    public static function adminGenerationTaskDetail(int $tenantId, array $params = []): array
    {
        $id = (int)($params['id'] ?? 0);
        $taskId = trim((string)($params['task_id'] ?? ''));
        if ($id <= 0 && $taskId === '') {
            throw new Exception('Task ID is required');
        }

        $query = AigcShortDramaGenerationTask::alias('g')
            ->leftJoin('aigc_short_drama_project p', 'p.id = g.project_id AND p.tenant_id = g.tenant_id AND p.delete_time = 0')
            ->leftJoin('user u', 'u.id = g.user_id AND u.tenant_id = g.tenant_id')
            ->field('g.*,p.title project_title,p.cover_url project_cover_url,p.ratio project_ratio,p.status project_status,u.nickname user_nickname,u.account user_account,u.mobile user_mobile')
            ->where('g.delete_time', 0);
        if ($tenantId > 0) {
            $query->where('g.tenant_id', $tenantId);
        }
        if ($id > 0) {
            $query->where('g.id', $id);
        }
        if ($taskId !== '') {
            $query->where('g.task_id', $taskId);
        }

        $task = $query->findOrEmpty();
        if ($task->isEmpty()) {
            throw new Exception('Generation task not found');
        }
        return self::sanitizeUtf8Payload(self::formatAdminGenerationTask($task->toArray()));
    }

    public static function adminStoryboardLists(int $tenantId, array $params = []): array
    {
        $query = AigcShortDramaStoryboard::alias('s')
            ->leftJoin('aigc_short_drama_project p', 'p.id = s.project_id AND p.tenant_id = s.tenant_id AND p.delete_time = 0')
            ->leftJoin('aigc_short_drama_script_task t', 't.task_id = s.task_id AND t.tenant_id = s.tenant_id AND t.delete_time = 0')
            ->leftJoin('user u', 'u.id = s.user_id AND u.tenant_id = s.tenant_id')
            ->field('s.*,p.title project_title,p.cover_url project_cover_url,p.ratio project_ratio,t.status task_status,t.progress task_progress,u.nickname user_nickname,u.account user_account,u.mobile user_mobile')
            ->where('s.delete_time', 0);
        if ($tenantId > 0) {
            $query->where('s.tenant_id', $tenantId);
        }

        $status = trim((string)($params['status'] ?? ''));
        $keyword = trim((string)($params['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->whereLike('s.shot_id|s.scene_name|s.visual_description|s.dialogue|p.title|s.task_id', '%' . $keyword . '%');
        }
        $userKeyword = trim((string)($params['user_keyword'] ?? ''));
        if ($userKeyword !== '') {
            $query->where(function ($query) use ($userKeyword) {
                $query->whereLike('u.nickname', '%' . $userKeyword . '%')
                    ->whereOrLike('u.account', '%' . $userKeyword . '%')
                    ->whereOrLike('u.mobile', '%' . $userKeyword . '%');
                if (ctype_digit($userKeyword)) {
                    $query->whereOr('s.user_id', (int)$userKeyword);
                }
            });
        }
        $start = strtotime((string)($params['start_time'] ?? $params['create_start_time'] ?? '')) ?: 0;
        $end = strtotime((string)($params['end_time'] ?? $params['create_end_time'] ?? '')) ?: 0;
        if ($start > 0) {
            $query->where('s.create_time', '>=', $start);
        }
        if ($end > 0) {
            $query->where('s.create_time', '<=', $end + 86399);
        }

        $generationStatus = trim((string)($params['generation_status'] ?? $status));
        if ($generationStatus === '') {
            $rows = $query
                ->order(['s.create_time' => 'desc', 's.id' => 'desc'])
                ->select()
                ->toArray();
            $lists = array_map([self::class, 'formatAdminStoryboard'], $rows);
            return self::paginateAdminRows(self::sortAdminRowsByGenerationTime($lists), $params);
        }

        $rows = $query->order(['s.create_time' => 'desc', 's.id' => 'desc'])->select()->toArray();
        $lists = array_map([self::class, 'formatAdminStoryboard'], $rows);
        $lists = array_values(array_filter($lists, static function (array $item) use ($generationStatus): bool {
            return self::adminRowEffectiveStatus($item) === $generationStatus;
        }));
        return self::paginateAdminRows(self::sortAdminRowsByGenerationTime($lists), $params);
    }

    public static function adminInspirationLists(int $tenantId, array $params = []): array
    {
        $query = AigcShortDramaInspiration::where('delete_time', 0)
            ->whereIn('tenant_id', [0, $tenantId])
            ->order(['sort' => 'desc', 'id' => 'desc']);

        $status = trim((string)($params['status'] ?? ''));
        if ($status !== '') {
            $query->where('status', (int)$status);
        }
        $keyword = trim((string)($params['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->whereLike('title|prompt', '%' . $keyword . '%');
        }

        $pageNo = max(1, (int)($params['page_no'] ?? 1));
        $pageSize = min(100, max(1, (int)($params['page_size'] ?? 15)));
        $count = (int)(clone $query)->count();
        $rows = $query->limit(($pageNo - 1) * $pageSize, $pageSize)->select()->toArray();

        return self::sanitizeUtf8Payload([
            'lists' => array_map([self::class, 'formatAdminInspiration'], $rows),
            'count' => $count,
            'page_no' => $pageNo,
            'page_size' => $pageSize,
        ]);
    }

    public static function setInspirationStatus(int $tenantId, int $id, int $status): void
    {
        $row = AigcShortDramaInspiration::where([
            'id' => $id,
            'delete_time' => 0,
        ])->whereIn('tenant_id', [0, $tenantId])->findOrEmpty();
        if ($row->isEmpty()) {
            throw new Exception('灵感作品不存在');
        }
        $row->save([
            'status' => $status ? 1 : 0,
            'update_time' => time(),
        ]);
    }

    public static function adminSubjectLists(int $tenantId, array $params = []): array
    {
        $query = AigcShortDramaSubject::where([
            'tenant_id' => $tenantId,
            'user_id' => 0,
            'source' => 'public',
            'delete_time' => 0,
        ])->order(['sort' => 'desc', 'id' => 'desc']);

        $status = trim((string)($params['status'] ?? ''));
        if ($status !== '') {
            $query->where('status', (int)$status);
        }
        $keyword = trim((string)($params['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->whereLike('name|description', '%' . $keyword . '%');
        }
        foreach (['category', 'gender', 'age_stage'] as $field) {
            $value = trim((string)($params[$field] ?? ''));
            if ($value !== '') {
                $query->where($field, $value);
            }
        }

        $pageNo = max(1, (int)($params['page_no'] ?? 1));
        $pageSize = min(100, max(1, (int)($params['page_size'] ?? 15)));
        $count = (int)(clone $query)->count();
        $rows = $query->limit(($pageNo - 1) * $pageSize, $pageSize)->select()->toArray();

        return self::sanitizeUtf8Payload([
            'lists' => array_map([self::class, 'formatAdminSubject'], $rows),
            'count' => $count,
            'page_no' => $pageNo,
            'page_size' => $pageSize,
        ]);
    }

    public static function saveAdminSubject(int $tenantId, array $params): array
    {
        $id = (int)($params['id'] ?? 0);
        $name = mb_substr(trim((string)($params['name'] ?? '')), 0, 80, 'UTF-8');
        if ($name === '') {
            throw new Exception('请输入主体名称');
        }
        $time = time();
        $data = [
            'tenant_id' => $tenantId,
            'user_id' => 0,
            'name' => $name,
            'image' => mb_substr(trim((string)($params['image'] ?? '')), 0, 500, 'UTF-8'),
            'description' => mb_substr(trim((string)($params['description'] ?? '')), 0, 500, 'UTF-8'),
            'category' => mb_substr(trim((string)($params['category'] ?? 'character')), 0, 40, 'UTF-8'),
            'gender' => mb_substr(trim((string)($params['gender'] ?? 'unknown')), 0, 20, 'UTF-8'),
            'age_stage' => mb_substr(trim((string)($params['age_stage'] ?? 'unknown')), 0, 30, 'UTF-8'),
            'source' => 'public',
            'status' => (int)($params['status'] ?? 1) ? 1 : 0,
            'sort' => (int)($params['sort'] ?? 0),
            'update_time' => $time,
        ];
        if ($id > 0) {
            $row = AigcShortDramaSubject::where([
                'id' => $id,
                'tenant_id' => $tenantId,
                'user_id' => 0,
                'source' => 'public',
                'delete_time' => 0,
            ])->findOrEmpty();
            if ($row->isEmpty()) {
                throw new Exception('主体不存在');
            }
            $row->save($data);
            return self::formatAdminSubject(array_merge($row->toArray(), $data));
        }

        $data['create_time'] = $time;
        $data['delete_time'] = 0;
        $row = AigcShortDramaSubject::create($data);
        return self::formatAdminSubject($row->toArray());
    }

    public static function setAdminSubjectStatus(int $tenantId, int $id, int $status): void
    {
        $row = AigcShortDramaSubject::where([
            'id' => $id,
            'tenant_id' => $tenantId,
            'user_id' => 0,
            'source' => 'public',
            'delete_time' => 0,
        ])->findOrEmpty();
        if ($row->isEmpty()) {
            throw new Exception('主体不存在');
        }
        $row->save([
            'status' => $status ? 1 : 0,
            'update_time' => time(),
        ]);
    }

    public static function deleteAdminSubject(int $tenantId, int $id): void
    {
        $row = AigcShortDramaSubject::where([
            'id' => $id,
            'tenant_id' => $tenantId,
            'user_id' => 0,
            'source' => 'public',
            'delete_time' => 0,
        ])->findOrEmpty();
        if ($row->isEmpty()) {
            throw new Exception('主体不存在');
        }
        $row->save([
            'delete_time' => time(),
            'update_time' => time(),
        ]);
    }

    public static function subjectLibraryLists(int $tenantId, int $userId, array $params = []): array
    {
        $scope = (string)($params['scope'] ?? $params['source'] ?? 'public');
        $scope = $scope === 'user' ? 'user' : 'public';
        $query = AigcShortDramaSubject::where([
            'source' => $scope === 'user' ? 'user' : 'public',
            'delete_time' => 0,
        ])->order(['sort' => 'desc', 'id' => 'desc']);

        if ($scope === 'user') {
            $query->where([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'status' => 1,
            ]);
        } else {
            $query->where('user_id', 0)
                ->whereIn('tenant_id', [0, $tenantId])
                ->where('status', 1)
                ->whereNotIn('name', self::LEGACY_PUBLIC_SUBJECT_NAMES);
        }

        $category = self::normalizeSubjectLibraryCategory((string)($params['category'] ?? ''));
        if ($category !== '') {
            $query->where('category', $category);
        }
        $keyword = trim((string)($params['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->whereLike('name|description', '%' . $keyword . '%');
        }

        $pageNo = max(1, (int)($params['page_no'] ?? 1));
        $pageSize = min(100, max(1, (int)($params['page_size'] ?? 30)));
        $count = (int)(clone $query)->count();
        $rows = $query->limit(($pageNo - 1) * $pageSize, $pageSize)->select()->toArray();
        $subjectIds = array_map(static fn($row) => (int)($row['id'] ?? 0), $rows);
        $threeViewMap = self::latestSubjectLibraryAssets($tenantId, $userId, $subjectIds, 'three_view');

        return self::sanitizeUtf8Payload([
            'lists' => array_map(static function ($row) use ($threeViewMap) {
                return self::formatSubjectLibrary($row, (array)($threeViewMap[(int)$row['id']] ?? []));
            }, $rows),
            'count' => $count,
            'page_no' => $pageNo,
            'page_size' => $pageSize,
        ]);
    }

    public static function saveSubjectLibrary(int $tenantId, int $userId, array $params): array
    {
        $id = (int)($params['id'] ?? 0);
        $name = mb_substr(trim((string)($params['name'] ?? '')), 0, 80, 'UTF-8');
        if ($name === '') {
            throw new Exception('请输入主体名称');
        }
        $image = mb_substr(trim((string)($params['image'] ?? $params['raw_image'] ?? '')), 0, 500, 'UTF-8');
        $description = mb_substr(trim((string)($params['description'] ?? $params['prompt'] ?? '')), 0, 500, 'UTF-8');
        $category = self::normalizeSubjectLibraryCategory((string)($params['category'] ?? 'character')) ?: 'character';
        $time = time();
        $data = [
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'name' => $name,
            'image' => $image,
            'description' => $description,
            'category' => $category,
            'gender' => mb_substr(trim((string)($params['gender'] ?? 'unknown')), 0, 20, 'UTF-8'),
            'age_stage' => mb_substr(trim((string)($params['age_stage'] ?? 'unknown')), 0, 30, 'UTF-8'),
            'source' => 'user',
            'status' => (int)($params['status'] ?? 1) ? 1 : 0,
            'sort' => (int)($params['sort'] ?? 0),
            'update_time' => $time,
        ];

        if ($id > 0) {
            $row = self::findUserSubject($tenantId, $userId, $id, true);
            $row->save($data);
            return self::formatSubjectLibrary(array_merge($row->toArray(), $data), self::latestSubjectLibraryAsset($tenantId, $userId, $id, 'three_view'));
        }

        $row = AigcShortDramaSubject::create($data + [
            'create_time' => $time,
            'delete_time' => 0,
        ]);
        return self::formatSubjectLibrary($row->toArray(), []);
    }

    public static function deleteSubjectLibrary(int $tenantId, int $userId, int $id): void
    {
        $row = self::findUserSubject($tenantId, $userId, $id, true);
        $row->save([
            'delete_time' => time(),
            'update_time' => time(),
        ]);
    }

    public static function describeSubjectImage(int $tenantId, int $userId, array $params): array
    {
        $image = trim((string)($params['image'] ?? $params['uri'] ?? $params['url'] ?? ''));
        if ($image === '') {
            throw new Exception('请先上传参考图片');
        }
        $category = self::normalizeSubjectLibraryCategory((string)($params['category'] ?? 'character')) ?: 'character';
        $content = $category === 'scene'
            ? implode("\n", [
                '请根据参考图片提炼场景创作描述，只输出一段中文提示词。',
                '不要输出标题、编号、Markdown、JSON 或解释。',
                '描述空间类型、关键物件、光线、氛围、材质和镜头构图。',
                '不要包含图片上传、参考图、模型参数、系统规则等内部说明。',
                '适合用于短剧场景图生成，语言自然简洁。',
            ])
            : implode("\n", [
                '请根据参考图片提炼主体创作描述，只输出一段中文提示词。',
                '不要输出标题、编号、Markdown、JSON 或解释。',
                '描述主体外貌、发型、服装、姿态、气质、材质和可识别特征。',
                '不要包含参考图、一致性、三视图、T姿态、模型参数等内部说明。',
                '适合用于短剧主体图生成，语言自然简洁。',
            ]);
        if ($category !== 'scene') {
            $content .= "\n" . implode("\n", [
                '如果主体是人物，请保留年龄段、性别气质、脸型、发型、服装和显著特征。',
                '如果主体是物品或道具，请描述形状、材质、颜色、磨损、纹理和功能感。',
                '不要编造图片中不存在的品牌、文字、水印或复杂背景。',
                '不要把固定生成规则写进提示词。',
                '不要出现“保持一致”“参考图”“三视图”“T姿态”等内部控制语。',
            ]);
        }
        $visionModel = self::configuredVisionModel($tenantId, self::publicConfig($tenantId));
        if ($visionModel === []) {
            throw new Exception('暂无可用的视觉文本模型，请在短剧基础配置中选择支持视觉的文本模型');
        }
        $result = MarketTextModelRuntimeService::generate($tenantId, $userId, [
            'action_code' => 'short_drama_subject_describe',
            'content' => $content,
            'reference_images' => [$image],
            'requires_vision' => true,
            // Image understanding is an application-level capability. Do not allow
            // a request parameter to bypass the tenant's fixed visual model.
            'model_selection' => $visionModel,
        ]);
        $prompt = trim((string)($result['content'] ?? ''));
        $prompt = preg_replace('/^[`"\']+|[`"\']+$/u', '', $prompt) ?? $prompt;
        $prompt = preg_replace('/^\s*[-*#>\d.?]+/u', '', $prompt) ?? $prompt;
        $prompt = preg_replace('/\s+/u', ' ', $prompt) ?? $prompt;
        if ($category !== 'scene') {
            $prompt = self::cleanSubjectDescribePrompt($prompt);
        }
        return self::sanitizeUtf8Payload([
            'prompt' => trim($prompt),
            'model_code' => (string)($result['model_code'] ?? ''),
            'channel_code' => (string)($result['channel_code'] ?? ''),
        ]);
    }

    private static function cleanSubjectDescribePrompt(string $prompt): string
    {
        $prompt = preg_replace([
            '/[,，、。；;]?\s*(?:参考图|一致性|固定规则|内部规则|模型参数)[^,，、。；;]*(?:[,，、。；;]|$)/u',
            '/[,，、。；;]?\s*(?:三视图|T姿态|负面词|系统提示)[^,，、。；;]*(?:[,，、。；;]|$)/u',
        ], '', $prompt) ?? $prompt;
        $blockedPhrases = [
            '参考图', '保持一致', '一致性', '固定规则', '内部规则', '系统提示', '模型参数',
            '三视图', 'T姿态', '负面词', 'provider', 'schema', 'json', 'markdown',
        ];
        $rawParts = preg_split('/[,，、。；;！!？?\n]+/u', $prompt) ?: [$prompt];
        $parts = [];
        foreach ($rawParts as $part) {
            $text = trim($part);
            if ($text === '') {
                continue;
            }
            $blocked = false;
            foreach ($blockedPhrases as $phrase) {
                if (mb_stripos($text, $phrase, 0, 'UTF-8') !== false) {
                    $blocked = true;
                    break;
                }
            }
            if (!$blocked) {
                $parts[] = $text;
            }
        }
        $parts = array_values(array_unique($parts));
        if (count($parts) > 1) {
            $style = array_shift($parts);
            $cleaned = $style . '。' . implode('。', $parts) . '。';
            return preg_replace('/。{2,}/u', '。', $cleaned) ?? $cleaned;
        }
        if (!empty($parts)) {
            $cleaned = $parts[0];
            return str_ends_with($cleaned, '。') ? $cleaned : $cleaned . '。';
        }
        return trim($prompt);
    }

    public static function createSubjectLibraryGeneration(int $tenantId, int $userId, array $params): array
    {
        $taskType = self::normalizeGenerationTaskType((string)($params['task_type'] ?? $params['type'] ?? 'subject_image'));
        if (!in_array($taskType, ['subject_image', 'three_view'], true)) {
            throw new Exception('Unsupported subject generation type');
        }
        $params = self::normalizeShortDramaImageChannelParams($tenantId, $params);
        $subjectId = (int)($params['subject_id'] ?? $params['item_id'] ?? 0);
        if (in_array($taskType, ['subject_image', 'three_view'], true)) {
            if ($subjectId <= 0) {
                $draftName = trim((string)($params['subject_name'] ?? $params['item_name'] ?? ''));
                $draft = self::saveSubjectLibrary($tenantId, $userId, [
                    'name' => $draftName !== '' ? $draftName : 'Draft subject',
                    'image' => (string)($params['image'] ?? $params['reference_image'] ?? ''),
                    'description' => (string)($params['subject_prompt'] ?? $params['prompt'] ?? ''),
                    'category' => (string)($params['category'] ?? 'character'),
                    'status' => 0,
                ]);
                $subjectId = (int)$draft['id'];
            }
            $subject = self::findUserSubject($tenantId, $userId, $subjectId, true)->toArray();
            $params['subject_id'] = $subjectId;
            $params['item_id'] = $subjectId;
            $params['subject_name'] = (string)($subject['name'] ?? $params['subject_name'] ?? '');
            $params['subject_prompt'] = (string)($params['subject_prompt'] ?? $params['prompt'] ?? $subject['description'] ?? '');
        }
        if ($taskType === 'three_view') {
            $subject = self::findUserSubject($tenantId, $userId, $subjectId, true)->toArray();
            $sourceImage = self::firstReferenceImageFromParams($params);
            if ($sourceImage === '') {
                $sourceImage = trim((string)($subject['image'] ?? ''));
            }
            if ($sourceImage === '') {
                throw new Exception('请先生成或上传主体图');
            }
            $params['view_mode'] = 'three_view';
            $params['reference_image'] = $sourceImage;
            $params['reference_images'] = array_values(array_filter(array_merge(
                (array)($params['reference_images'] ?? []),
                [$sourceImage]
            )));
            if (is_array($params['params'] ?? null)) {
                $params['params']['reference_image'] = $sourceImage;
                $params['params']['reference_images'] = $params['reference_images'];
            }
        }

        $config = self::publicConfig($tenantId);
        $billing = self::estimateImageGenerationBilling($tenantId, $taskType, [], $config, $params);
        $localTaskId = self::makeTaskId('sd_subject');

        Db::startTrans();
        try {
            $generation = self::createGenerationTaskRecord($tenantId, $userId, 0, '', $taskType, $localTaskId, self::STATUS_PENDING, [
                'source_app_code' => self::isMarketImageSelection($params) ? 'power_market_image' : self::IMAGE_APP_CODE,
                'provider' => 'pending',
                'request' => [
                    'shot' => [],
                    'params' => $params,
                ],
                'pricing' => $billing,
                'billing_status' => ((float)$billing['tenant_cost_points'] > 0 || (float)$billing['user_charge_points'] > 0) ? 'reserved' : 'none',
                'tenant_cost_points' => $billing['tenant_cost_points'],
                'user_charge_points' => $billing['user_charge_points'],
            ]);
            if (!self::isMarketImageSelection($params) && ((float)$billing['tenant_cost_points'] > 0 || (float)$billing['user_charge_points'] > 0)) {
                PointService::consumeBusinessAmountsInCurrentTransaction($tenantId, $userId, (float)$billing['tenant_cost_points'], (float)$billing['user_charge_points'], $localTaskId, 'AI short drama subject generation reserve', [
                    'app_code' => self::APP_CODE,
                    'task_id' => $localTaskId,
                    'project_id' => 0,
                    'subject_id' => $subjectId,
                    'task_type' => $taskType,
                ]);
            }
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e instanceof Exception ? $e : new Exception(self::SAFE_ERROR);
        }
        self::runImageGenerationTask($tenantId, $userId, $generation->toArray(), [], $params, $billing);
        $generation = self::findGenerationTask($tenantId, $userId, $localTaskId);
        return self::formatGenerationTask($generation->toArray(), true);
    }

    public static function subjectImageHistory(int $tenantId, int $userId, int $subjectId): array
    {
        self::findUserSubject($tenantId, $userId, $subjectId, true);
        return self::subjectLibraryAssetHistory($tenantId, $userId, $subjectId, 'subject_image');
    }

    public static function registerSubjectImageAsset(int $tenantId, int $userId, array $params): array
    {
        $subjectId = (int)($params['subject_id'] ?? $params['item_id'] ?? 0);
        if ($subjectId <= 0) {
            $draftName = trim((string)($params['subject_name'] ?? $params['item_name'] ?? ''));
            $draft = self::saveSubjectLibrary($tenantId, $userId, [
                'name' => $draftName !== '' ? $draftName : 'Draft subject',
                'image' => (string)($params['uri'] ?? $params['url'] ?? ''),
                'description' => (string)($params['subject_prompt'] ?? $params['prompt'] ?? ''),
                'category' => (string)($params['category'] ?? 'character'),
                'status' => 0,
            ]);
            $subjectId = (int)$draft['id'];
        }
        $subject = self::findUserSubject($tenantId, $userId, $subjectId, true);
        $uri = FileService::setFileUrl((string)($params['uri'] ?? $params['url'] ?? ''));
        if ($uri === '') {
            throw new Exception('Asset file is required');
        }
        $storedFile = self::storageInfoForUploadedFile($tenantId, $uri);
        $meta = is_array($params['meta'] ?? null) ? $params['meta'] : [];
        $sourceTaskId = trim((string)($params['source_task_id'] ?? $params['script_task_id'] ?? $meta['source_task_id'] ?? $meta['project_task_id'] ?? $params['task_id'] ?? ''));
        if ($sourceTaskId !== '') {
            $meta['source_task_id'] = $sourceTaskId;
            $meta['project_task_id'] = $sourceTaskId;
        }
        $time = time();
        $asset = AigcShortDramaAsset::create([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'project_id' => 0,
            'task_id' => (string)($params['task_id'] ?? ''),
            'shot_id' => '',
            'asset_type' => 'subject_image',
            'title' => mb_substr(trim((string)($params['title'] ?? $subject['name'] ?? '')), 0, 120, 'UTF-8'),
            'uri' => $uri,
            'cover_uri' => FileService::setFileUrl((string)($params['cover_uri'] ?? $params['cover_url'] ?? '')),
            'storage_scope' => (string)($params['storage_scope'] ?? $storedFile['storage_scope'] ?? 'tenant'),
            'storage_engine' => (string)($params['storage_engine'] ?? $storedFile['storage_engine'] ?? 'local'),
            'storage_domain' => (string)($params['storage_domain'] ?? $storedFile['storage_domain'] ?? ''),
            'mime_type' => mb_substr(trim((string)($params['mime_type'] ?? 'image/png')), 0, 120, 'UTF-8'),
            'file_size' => (int)($params['file_size'] ?? 0),
            'width' => (int)($params['width'] ?? 0),
            'height' => (int)($params['height'] ?? 0),
            'duration' => 0,
            'checksum' => mb_substr(trim((string)($params['checksum'] ?? '')), 0, 100, 'UTF-8'),
            'meta_json' => self::jsonEncode([
                'source' => (string)($params['source'] ?? 'pc_subject_library_upload'),
                'subject_id' => (string)$subjectId,
                'item_id' => (string)$subjectId,
                'subject_name' => (string)($subject['name'] ?? $params['subject_name'] ?? ''),
                'item_name' => (string)($subject['name'] ?? $params['item_name'] ?? ''),
                'category' => (string)($subject['category'] ?? $params['category'] ?? 'character'),
                'prompt' => self::localizeGenerationPromptText((string)($params['prompt'] ?? $params['subject_prompt'] ?? $subject['description'] ?? '')),
            ]),
            'status' => 'ready',
            'create_time' => $time,
            'update_time' => $time,
            'delete_time' => 0,
        ]);
        return [
            'subject_id' => $subjectId,
            'asset' => self::formatAsset($asset->toArray()),
        ];
    }

    public static function selectSubjectImageAsset(int $tenantId, int $userId, array $params): array
    {
        $subjectId = (int)($params['subject_id'] ?? $params['item_id'] ?? 0);
        $assetId = (int)($params['asset_id'] ?? 0);
        if ($subjectId <= 0 || $assetId <= 0) {
            throw new Exception('Please select a subject image');
        }
        $subject = self::findUserSubject($tenantId, $userId, $subjectId, true);
        $asset = AigcShortDramaAsset::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'project_id' => 0,
            'id' => $assetId,
            'asset_type' => 'subject_image',
            'status' => 'ready',
            'delete_time' => 0,
        ])->findOrEmpty();
        if ($asset->isEmpty()) {
            throw new Exception('Subject image does not exist');
        }
        $meta = self::jsonDecode((string)($asset['meta_json'] ?? ''));
        $metaSubjectId = (int)($meta['subject_id'] ?? $meta['item_id'] ?? 0);
        if ($metaSubjectId > 0 && $metaSubjectId !== $subjectId) {
            throw new Exception('Subject image does not belong to this subject');
        }
        $subject->save([
            'image' => (string)$asset['uri'],
            'update_time' => time(),
        ]);
        return [
            'subject' => self::formatSubjectLibrary(array_merge($subject->toArray(), [
                'image' => (string)$asset['uri'],
            ]), self::latestSubjectLibraryAsset($tenantId, $userId, $subjectId, 'three_view')),
            'asset' => self::formatAsset($asset->toArray()),
        ];
    }

    public static function subjectThreeViewHistory(int $tenantId, int $userId, int $subjectId): array
    {
        self::findUserSubject($tenantId, $userId, $subjectId, false);
        return self::subjectLibraryAssetHistory($tenantId, $userId, $subjectId, 'three_view');
    }

    private static function subjectLibraryAssetHistory(int $tenantId, int $userId, int $subjectId, string $taskType): array
    {
        $rows = AigcShortDramaGenerationTask::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'project_id' => 0,
            'task_type' => $taskType,
            'delete_time' => 0,
        ])->order(['id' => 'desc'])->limit(50)->select()->toArray();
        $lists = [];
        $seenAssetIds = [];
        foreach ($rows as $row) {
            $request = self::jsonDecode((string)($row['request_json'] ?? ''));
            $requestParams = (array)($request['params'] ?? []);
            if ((int)($requestParams['subject_id'] ?? $requestParams['item_id'] ?? 0) !== $subjectId) {
                continue;
            }
            $task = self::formatGenerationTask($row, true);
            $task['assets'] = self::generationTaskOutputAssets($tenantId, $userId, $task);
            foreach ($task['assets'] as $asset) {
                $seenAssetIds[(int)($asset['id'] ?? 0)] = true;
            }
            $lists[] = $task;
        }
        $assetRows = AigcShortDramaAsset::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'project_id' => 0,
            'asset_type' => $taskType,
            'status' => 'ready',
            'delete_time' => 0,
        ])->order(['id' => 'desc'])->limit(100)->select()->toArray();
        foreach ($assetRows as $assetRow) {
            $assetId = (int)($assetRow['id'] ?? 0);
            if ($assetId <= 0 || isset($seenAssetIds[$assetId])) {
                continue;
            }
            $meta = self::jsonDecode((string)($assetRow['meta_json'] ?? ''));
            if ((int)($meta['subject_id'] ?? $meta['item_id'] ?? 0) !== $subjectId) {
                continue;
            }
            $asset = self::formatAsset($assetRow);
            $lists[] = [
                'task_id' => (string)($asset['task_id'] ?? ('asset_' . $assetId)),
                'task_type' => $taskType,
                'status' => self::STATUS_SUCCESS,
                'subject_id' => $subjectId,
                'item_id' => $subjectId,
                'assets' => [$asset],
                'output_assets' => [$asset],
                'created_at' => (string)($asset['created_at'] ?? ''),
            ];
        }
        return self::sanitizeUtf8Payload([
            'lists' => $lists,
            'count' => count($lists),
        ]);
    }

    public static function adminStyleLists(int $tenantId, array $params = []): array
    {
        self::seedTenantStylesFromPublic($tenantId);

        $query = AigcShortDramaStyle::where([
            'tenant_id' => $tenantId,
            'delete_time' => 0,
        ])->order(['sort' => 'desc', 'id' => 'desc']);

        $status = trim((string)($params['status'] ?? ''));
        if ($status !== '') {
            $query->where('status', (int)$status);
        }
        $keyword = trim((string)($params['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->whereLike('name|description', '%' . $keyword . '%');
        }

        $pageNo = max(1, (int)($params['page_no'] ?? 1));
        $pageSize = min(100, max(1, (int)($params['page_size'] ?? 15)));
        $count = (int)(clone $query)->count();
        $rows = $query->limit(($pageNo - 1) * $pageSize, $pageSize)->select()->toArray();

        return self::sanitizeUtf8Payload([
            'lists' => array_map([self::class, 'formatAdminStyle'], $rows),
            'count' => $count,
            'page_no' => $pageNo,
            'page_size' => $pageSize,
        ]);
    }

    public static function saveAdminStyle(int $tenantId, array $params): array
    {
        $id = (int)($params['id'] ?? 0);
        $name = mb_substr(trim((string)($params['name'] ?? '')), 0, 80, 'UTF-8');
        if ($name === '') {
            throw new Exception('请输入画风名');
        }
        $time = time();
        $data = [
            'tenant_id' => $tenantId,
            'name' => $name,
            'image' => mb_substr(trim((string)($params['image'] ?? '')), 0, 500, 'UTF-8'),
            'description' => mb_substr(trim((string)($params['description'] ?? '')), 0, 500, 'UTF-8'),
            'is_new' => (int)($params['is_new'] ?? 0) ? 1 : 0,
            'status' => (int)($params['status'] ?? 1) ? 1 : 0,
            'sort' => (int)($params['sort'] ?? 0),
            'update_time' => $time,
        ];
        if ($id > 0) {
            $row = AigcShortDramaStyle::where([
                'id' => $id,
                'tenant_id' => $tenantId,
                'delete_time' => 0,
            ])->findOrEmpty();
            if ($row->isEmpty()) {
                throw new Exception('画风不存在');
            }
            $row->save($data);
            return self::formatAdminStyle(array_merge($row->toArray(), $data));
        }

        $data['create_time'] = $time;
        $data['delete_time'] = 0;
        $row = AigcShortDramaStyle::create($data);
        return self::formatAdminStyle($row->toArray());
    }

    public static function setAdminStyleStatus(int $tenantId, int $id, int $status): void
    {
        $row = AigcShortDramaStyle::where([
            'id' => $id,
            'tenant_id' => $tenantId,
            'delete_time' => 0,
        ])->findOrEmpty();
        if ($row->isEmpty()) {
            throw new Exception('画风不存在');
        }
        $row->save([
            'status' => $status ? 1 : 0,
            'update_time' => time(),
        ]);
    }

    public static function deleteAdminStyle(int $tenantId, int $id): void
    {
        $row = AigcShortDramaStyle::where([
            'id' => $id,
            'tenant_id' => $tenantId,
            'delete_time' => 0,
        ])->findOrEmpty();
        if ($row->isEmpty()) {
            throw new Exception('画风不存在');
        }
        $row->save([
            'delete_time' => time(),
            'update_time' => time(),
        ]);
    }

    private static function seedTenantStylesFromPublic(int $tenantId): void
    {
        if ($tenantId <= 0) {
            return;
        }
        if (AigcShortDramaStyle::where([
            'tenant_id' => $tenantId,
            'delete_time' => 0,
        ])->count() > 0) {
            return;
        }

        $publicRows = AigcShortDramaStyle::where([
            'tenant_id' => 0,
            'delete_time' => 0,
        ])
            ->order(['sort' => 'desc', 'id' => 'asc'])
            ->select()
            ->toArray();
        if (!$publicRows) {
            return;
        }

        $time = time();
        $seedRows = [];
        foreach ($publicRows as $row) {
            $seedRows[] = [
                'tenant_id' => $tenantId,
                'name' => (string)$row['name'],
                'image' => (string)($row['image'] ?? ''),
                'description' => (string)($row['description'] ?? ''),
                'is_new' => (int)($row['is_new'] ?? 0) ? 1 : 0,
                'status' => (int)($row['status'] ?? 1) ? 1 : 0,
                'sort' => (int)($row['sort'] ?? 0),
                'create_time' => $time,
                'update_time' => $time,
                'delete_time' => 0,
            ];
        }
        AigcShortDramaStyle::insertAll($seedRows);
    }

    public static function home(int $tenantId, int $userId): array
    {
        $config = self::publicConfig($tenantId);
        $userModelGroups = array_merge(
            self::userScriptModelGroups((array)($config['model_groups'] ?? []), (array)($config['models'] ?? [])),
            self::userCreationModelGroups((array)($config['model_groups'] ?? []), (array)($config['models'] ?? []))
        );
        return self::sanitizeUtf8Payload([
            'user' => [
                'points' => self::userPoints($userId),
                'vip_enabled' => false,
            ],
            'app_status' => [
                'enabled' => (int)($config['status'] ?? 1) === 1,
                'message' => '',
            ],
            'background' => $config['background'],
            'ratios' => $config['ratios'],
            'prompt_max_length' => (int)($config['prompt_max_length'] ?? 20000),
            'models' => (array)($userModelGroups[0]['options'] ?? []),
            'model_groups' => $userModelGroups,
            'vision_model_configured' => !empty(self::configuredVisionModel($tenantId, $config, false)),
            'subjects' => self::subjectOptions($tenantId, $userId),
            'styles' => self::styleOptions($tenantId),
            'recent_projects' => $userId > 0 ? self::projectLists($tenantId, $userId, ['page_no' => 1, 'page_size' => 4])['lists'] : [],
            'inspirations' => self::inspirationLists($tenantId, ['page_no' => 1, 'page_size' => 15])['lists'],
        ]);
    }

    public static function projectLists(int $tenantId, int $userId, array $params = []): array
    {
        $pageNo = max(1, (int)($params['page_no'] ?? 1));
        $pageSize = min(50, max(1, (int)($params['page_size'] ?? 10)));
        $query = AigcShortDramaProject::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'delete_time' => 0,
        ]);
        $keyword = trim((string)($params['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->whereLike('title|prompt', '%' . $keyword . '%');
        }
        $count = (int)(clone $query)->count();
        $rows = $query
            ->order(['update_time' => 'desc', 'id' => 'desc'])
            ->page($pageNo, $pageSize)
            ->select()
            ->toArray();
        return self::sanitizeUtf8Payload([
            'lists' => array_map([self::class, 'formatProject'], $rows),
            'count' => $count,
            'page_no' => $pageNo,
            'page_size' => $pageSize,
        ]);
    }

    public static function projectDetail(int $tenantId, int $userId, int $projectId): array
    {
        $project = self::findProject($tenantId, $userId, $projectId);
        $row = $project->toArray();
        $data = self::formatProject($row);
        $data['prompt'] = (string)($row['prompt'] ?? '');
        $data['multi_episode'] = (int)($row['multi_episode'] ?? 0) === 1;
        $data['current_version'] = self::currentPlanVersion($tenantId, $userId, $projectId);
        $data['versions'] = self::planVersions($tenantId, $userId, $projectId);
        $data['assets'] = self::projectAssets($tenantId, $userId, $projectId);
        $data['generation_tasks'] = self::projectGenerationTasks($tenantId, $userId, $projectId);
        $data['published_works'] = self::projectPublishedWorks($tenantId, $userId, $projectId);
        return self::sanitizeUtf8Payload($data);
    }

    public static function renameProject(int $tenantId, int $userId, int $projectId, string $title): array
    {
        $title = trim($title);
        if ($title === '') {
            throw new Exception('请输入项目标');
        }
        $project = self::findProject($tenantId, $userId, $projectId);
        $project->save([
            'title' => mb_substr($title, 0, 120),
            'update_time' => time(),
        ]);
        return self::formatProject($project->toArray());
    }

    public static function saveProjectGenerationSettings(int $tenantId, int $userId, array $params): array
    {
        $projectId = (int)($params['project_id'] ?? $params['id'] ?? 0);
        $project = self::findProject($tenantId, $userId, $projectId);
        $projectRow = $project->toArray();
        $incoming = is_array($params['generation_settings'] ?? null)
            ? (array)$params['generation_settings']
            : (is_array($params['settings'] ?? null) ? (array)$params['settings'] : $params);
        $settings = self::mergeProjectGenerationSettings(
            self::projectGenerationSettingsFromRow($projectRow),
            self::normalizeProjectGenerationSettings($incoming)
        );
        $ratio = self::normalizeGenerationRatio((string)($settings['ratio'] ?? $settings['aspect_ratio'] ?? $projectRow['ratio'] ?? ''));
        if ($ratio !== '') {
            $settings['ratio'] = $ratio;
            $settings['aspect_ratio'] = $ratio;
        }
        $update = [
            'generation_settings_json' => self::jsonEncode($settings),
            'update_time' => time(),
        ];
        if ($ratio !== '') {
            $update['ratio'] = $ratio;
        }
        $project->save($update);
        return self::formatProject(array_merge($projectRow, $update));
    }

    public static function projectCoverOptions(int $tenantId, int $userId, int $projectId): array
    {
        $project = self::findProject($tenantId, $userId, $projectId);
        $projectRow = $project->toArray();
        $coverUri = trim((string)($projectRow['cover_url'] ?? ''));
        $hasManualCover = $coverUri !== '';
        $assets = self::projectCoverCandidateAssets($tenantId, $userId, $projectId);
        $selectedAssetId = 0;

        if ($hasManualCover) {
            foreach ($assets as $asset) {
                if ((string)($asset['uri'] ?? '') === $coverUri) {
                    $selectedAssetId = (int)$asset['id'];
                    break;
                }
            }
        }

        if ($selectedAssetId <= 0 && !empty($assets)) {
            $selectedAssetId = (int)$assets[0]['id'];
        }

        return self::sanitizeUtf8Payload([
            'lists' => array_map(static function ($asset) use ($selectedAssetId) {
                $formatted = self::formatAsset($asset);
                $formatted['selected'] = (int)$asset['id'] === $selectedAssetId;
                $formatted['shot_title'] = (string)($asset['shot_title'] ?? '');
                $formatted['shot_sort'] = (int)($asset['shot_sort'] ?? 0);
                return $formatted;
            }, $assets),
            'selected_asset_id' => $selectedAssetId,
            'project' => self::formatProject($projectRow),
        ]);
    }

    public static function updateProjectCover(int $tenantId, int $userId, int $projectId, int $assetId): array
    {
        if ($assetId <= 0) {
            throw new Exception('请选择项目封面');
        }
        $project = self::findProject($tenantId, $userId, $projectId);
        foreach (self::projectCoverCandidateAssets($tenantId, $userId, $projectId) as $candidate) {
            if ((int)($candidate['id'] ?? 0) !== $assetId) {
                continue;
            }
            $project->save([
                'cover_url' => (string)($candidate['uri'] ?? ''),
                'update_time' => time(),
            ]);
            return self::formatProject(array_merge($project->toArray(), ['cover_url' => (string)($candidate['uri'] ?? '')]));
        }
        throw new Exception('Cover image does not exist or is not available');

    }

    public static function deleteProject(int $tenantId, int $userId, int $projectId): array
    {
        $project = self::findProject($tenantId, $userId, $projectId);
        $time = time();
        Db::startTrans();
        try {
            $project->save([
                'delete_time' => $time,
                'update_time' => $time,
            ]);
            $condition = [
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'project_id' => $projectId,
                'delete_time' => 0,
            ];
            foreach ([
                AigcShortDramaScriptTask::class,
                AigcShortDramaStoryboard::class,
                AigcShortDramaAsset::class,
                AigcShortDramaGenerationTask::class,
                AigcShortDramaPlanVersion::class,
                AigcShortDramaAgentRun::class,
                AigcShortDramaAgentStepLog::class,
                AigcShortDramaPublishedWork::class,
            ] as $modelClass) {
                $modelClass::where($condition)->update([
                    'delete_time' => $time,
                    'update_time' => $time,
                ]);
            }
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }

        return ['id' => $projectId];
    }

    public static function inspirationLists(int $tenantId, array $params = []): array
    {
        $pageNo = max(1, (int)($params['page_no'] ?? 1));
        $pageSize = min(50, max(1, (int)($params['page_size'] ?? 20)));
        $query = AigcShortDramaInspiration::where('status', 1)
            ->where('delete_time', 0)
            ->whereIn('tenant_id', [0, $tenantId]);

        $keyword = trim((string)($params['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->whereLike('title|prompt', '%' . $keyword . '%');
        }
        $ratio = trim((string)($params['ratio'] ?? ''));
        if ($ratio !== '') {
            $query->whereLike('config_json', '%"ratio":"' . addslashes($ratio) . '"%');
        }
        $count = (int)(clone $query)->count();
        $rows = $query
            ->order(['sort' => 'desc', 'id' => 'desc'])
            ->page($pageNo, $pageSize)
            ->select()
            ->toArray();

        return self::sanitizeUtf8Payload([
            'lists' => array_map([self::class, 'formatInspiration'], $rows),
            'count' => $count,
            'page_no' => $pageNo,
            'page_size' => $pageSize,
        ]);
    }

    public static function inspirationDetail(int $tenantId, int $id): array
    {
        $row = AigcShortDramaInspiration::where('id', $id)
            ->where('status', 1)
            ->where('delete_time', 0)
            ->whereIn('tenant_id', [0, $tenantId])
            ->findOrEmpty();
        if ($row->isEmpty()) {
            throw new Exception('灵感素材不存在或已禁用');
        }
        return self::formatInspiration($row->toArray(), true);
    }

    public static function createScriptPlan(int $tenantId, int $userId, array $params): array
    {
        $prompt = trim((string)($params['prompt'] ?? ''));
        if ($prompt === '') {
            throw new Exception('请输入故事灵感');
        }
        $config = self::publicConfig($tenantId);
        $promptMaxLength = (int)($config['prompt_max_length'] ?? 20000);
        if ($promptMaxLength > 0 && mb_strlen($prompt, 'UTF-8') > $promptMaxLength) {
            throw new Exception('故事灵感过长，请控制在 ' . $promptMaxLength . ' 字以内');
        }
        self::checkSensitivePrompt($prompt);

        $request = self::normalizeCreateRequest($params, $config);
        $request['prompt'] = $prompt;
        $request['storyboard_rules'] = self::normalizeStoryboardRules((array)($config['storyboard_rules'] ?? []));
        $request['storyboard_target_rule'] = self::storyboardTargetRule($prompt, $request);
        $projectRatio = self::normalizeGenerationRatio((string)($request['ratio'] ?? ''));
        $selectedModels = self::resolveSelectedModels($tenantId, $request, $config);
        $request['model_selections'] = self::modelSelectionsSnapshot($selectedModels);
        $request['model_id'] = (string)($selectedModels['script_plan']['id'] ?? $request['model_id'] ?? '');
        $generationSettings = self::projectGenerationSettingsFromRequest($projectRatio, $request, $selectedModels);
        $title = self::makeTitle($prompt);
        $taskId = self::makeTaskId();
        $agentRunId = self::makeTaskId('sd_agent');
        $time = time();

        Db::startTrans();
        try {
            $project = AigcShortDramaProject::create([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'title' => $title,
                'prompt' => $prompt,
                'ratio' => $projectRatio,
                'multi_episode' => $request['multi_episode'] ? 1 : 0,
                'episode_count' => $request['episode_count'],
                'target_duration_seconds' => $request['target_duration_seconds'],
                'input_asset_ids' => self::jsonEncode($request['input_asset_ids']),
                'generation_settings_json' => self::jsonEncode($generationSettings),
                'cover_url' => '',
                'status' => self::PROJECT_STATUS_PLANNING,
                'last_task_id' => $taskId,
                'current_agent_run_id' => $agentRunId,
                'create_time' => $time,
                'update_time' => $time,
                'delete_time' => 0,
            ]);

            AigcShortDramaScriptTask::create([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'project_id' => (int)$project['id'],
                'task_id' => $taskId,
                'status' => self::STATUS_PENDING,
                'progress' => 0,
                'current_step' => '等待剧本策划',
                'prompt' => $prompt,
                'request_json' => self::jsonEncode($request),
                'config_snapshot' => self::jsonEncode([
                    'model_id' => $request['model_id'],
                    'model_selections' => $request['model_selections'],
                    'style_id' => $request['style_id'],
                    'storyboard_rules' => $request['storyboard_rules'],
                    'storyboard_target_rule' => $request['storyboard_target_rule'],
                    'provider' => (string)($selectedModels['script_plan']['provider'] ?? ''),
                ]),
                'pricing_snapshot' => self::jsonEncode([]),
                'result_json' => self::jsonEncode([]),
                'error' => '',
                'billing_status' => 'none',
                'tenant_cost_points' => '0.00',
                'user_charge_points' => '0.00',
                'provider' => (string)($selectedModels['script_plan']['provider'] ?? ''),
                'provider_request_id' => '',
                'provider_task_id' => '',
                'idempotency_key' => sha1($tenantId . '|' . $userId . '|' . $prompt . '|' . microtime(true)),
                'retry_count' => 0,
                'started_at' => 0,
                'finished_at' => 0,
                'create_time' => $time,
                'update_time' => $time,
                'delete_time' => 0,
            ]);
            self::createGenerationTaskRecord($tenantId, $userId, (int)$project['id'], '', 'script_plan', $taskId, self::STATUS_PENDING, [
                'provider' => (string)($selectedModels['script_plan']['provider'] ?? ''),
                'source_app_code' => self::LLM_APP_CODE,
                'model_snapshot' => $selectedModels['script_plan'] ?? [],
                'request' => $request,
                'result' => [],
                'pricing' => [],
                'billing_status' => 'none',
                'tenant_cost_points' => 0,
                'user_charge_points' => 0,
                'started_at' => 0,
                'finished_at' => 0,
            ]);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            Log::write('AI short drama task create failed: ' . $e->getMessage());
            throw new Exception(self::SAFE_ERROR);
        }

        return [
            'project_id' => (int)$project['id'],
            'task_id' => $taskId,
            'status' => self::STATUS_PENDING,
            'redirect_url' => '/ai/short-drama/plan?project_id=' . (int)$project['id'] . '&task_id=' . $taskId,
        ];
    }

    public static function scriptPlanDetail(int $tenantId, int $userId, string $taskId, int $projectId = 0): array
    {
        $task = self::findTask($tenantId, $userId, $taskId, $projectId);
        $taskData = self::recoverPartialStreamScriptPlanTask($tenantId, $userId, $task->toArray());
        $taskData = self::recoverCompletedScriptPlanTask($tenantId, $userId, $taskData);
        $taskData = self::recoverStaleScriptPlanTask($tenantId, $userId, $taskData);
        $taskData = self::recoverFailedScriptPlanTaskForStream($tenantId, $userId, $taskData);
        return self::formatTask($taskData, true);
    }

    private static function scriptPlanStreamState(string $streamContent, string $providerRequestId = '', string $error = ''): array
    {
        return [
            '__stream_content' => $streamContent,
            '__stream_length' => mb_strlen($streamContent, 'UTF-8'),
            '__stream_updated_at' => time(),
            '__provider_request_id' => $providerRequestId,
            '__error' => $error,
        ];
    }

    private static function persistScriptPlanStreamState(int $tenantId, int $userId, string $taskId, string $streamContent, string $providerRequestId = '', string $error = ''): void
    {
        AigcShortDramaScriptTask::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'task_id' => $taskId,
            'delete_time' => 0,
        ])->update([
            'result_json' => self::jsonEncode(self::scriptPlanStreamState($streamContent, $providerRequestId, $error)),
            'provider_request_id' => $providerRequestId,
            'update_time' => time(),
        ]);
    }

    private static function syncScriptPlanGenerationTask(int $tenantId, int $userId, int $projectId, string $taskId, string $status, array $request, array $model, array $extra = []): void
    {
        $row = self::createGenerationTaskRecord($tenantId, $userId, $projectId, '', 'script_plan', $taskId, $status, [
            'provider' => (string)($model['provider'] ?? $extra['provider'] ?? ''),
            'source_app_code' => self::LLM_APP_CODE,
            'model_snapshot' => $model,
            'request' => $request,
            'result' => is_array($extra['result'] ?? null) ? $extra['result'] : [],
            'pricing' => is_array($extra['pricing'] ?? null) ? $extra['pricing'] : [],
            'billing_status' => (string)($extra['billing_status'] ?? 'none'),
            'tenant_cost_points' => (float)($extra['tenant_cost_points'] ?? 0),
            'user_charge_points' => (float)($extra['user_charge_points'] ?? 0),
            'provider_request_id' => (string)($extra['provider_request_id'] ?? ''),
            'started_at' => (int)($extra['started_at'] ?? 0),
            'finished_at' => (int)($extra['finished_at'] ?? 0),
        ]);
        $update = [
            'progress' => (int)($extra['progress'] ?? ($status === self::STATUS_SUCCESS ? 100 : 0)),
            'error_msg' => (string)($extra['error_msg'] ?? ''),
            'error_code' => (string)($extra['error_code'] ?? ''),
            'update_time' => time(),
        ];
        AigcShortDramaGenerationTask::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'task_id' => (string)$row['task_id'],
            'delete_time' => 0,
        ])->update($update);
    }

    private static function recoverPartialStreamScriptPlanTask(int $tenantId, int $userId, array $taskData): array
    {
        $status = (string)($taskData['status'] ?? '');
        if (!in_array($status, [self::STATUS_RUNNING, self::STATUS_FAILED], true)) {
            return $taskData;
        }

        $state = self::jsonDecode((string)($taskData['result_json'] ?? ''));
        $streamContent = (string)($state['__stream_content'] ?? '');
        if ($streamContent === '') {
            return $taskData;
        }
        if ($status === self::STATUS_RUNNING) {
            $time = time();
            $streamUpdatedAt = (int)($state['__stream_updated_at'] ?? 0);
            $lastHeartbeatAt = max((int)($taskData['started_at'] ?? 0), (int)($taskData['update_time'] ?? 0), $streamUpdatedAt);
            if ($lastHeartbeatAt <= 0 || $time - $lastHeartbeatAt < self::SCRIPT_PLAN_STREAM_RECOVER_SECONDS) {
                return $taskData;
            }
        } else {
            $error = (string)($taskData['error'] ?? '');
            $recoverableError = str_contains($error, '解析')
                || str_contains(strtolower($error), 'parse')
                || str_contains($error, '质检未通过')
                || $error === self::SCRIPT_PLAN_STALE_ERROR;
            if (!$recoverableError) {
                return $taskData;
            }
        }

        $taskId = (string)($taskData['task_id'] ?? '');
        $projectId = (int)($taskData['project_id'] ?? 0);
        if ($taskId === '' || $projectId <= 0) {
            return $taskData;
        }

        $project = AigcShortDramaProject::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'id' => $projectId,
        ])->findOrEmpty();
        if ($project->isEmpty()) {
            return $taskData;
        }

        try {
            $request = self::jsonDecode((string)($taskData['request_json'] ?? ''));
            $config = self::jsonDecode((string)($taskData['config_snapshot'] ?? ''));
            $modelSelections = is_array($config['model_selections'] ?? null) ? (array)$config['model_selections'] : [];
            $scriptModel = is_array($modelSelections['script_plan'] ?? null) ? (array)$modelSelections['script_plan'] : [];
            if (empty($scriptModel) && !empty($config['provider'])) {
                $scriptModel = ['provider' => (string)$config['provider'], 'name' => (string)($config['model_id'] ?? '')];
            }
            $prompt = (string)($taskData['prompt'] ?? $request['prompt'] ?? '');
            $payload = self::decodeLlmJsonObject($streamContent);
            $result = self::reviewAndRepairPlanResult(self::enhancePlanResult(self::normalizeGeneratedPlanResult($payload, $prompt, $request, (string)($project['title'] ?? ''))));
            if (!self::planResultHasContent($result)) {
                return $taskData;
            }
        } catch (\Throwable $e) {
            Log::write('AI short drama partial stream recovery failed: task=' . $taskId . ' error=' . $e->getMessage());
            return $taskData;
        }

        $time = time();
        $agentRunId = self::resolveScriptPlanAgentRunId($tenantId, $project->toArray(), $taskId);
        $providerRequestId = (string)($taskData['provider_request_id'] ?? $state['__provider_request_id'] ?? '');
        MarketTextModelRuntimeService::settleRecoveredAppTask((int)($taskData['app_task_id'] ?? 0), $streamContent, $providerRequestId);
        $billing = self::scriptPlanBillingFromUsage($tenantId, $userId, $providerRequestId, $scriptModel, $taskData);

        Db::startTrans();
        try {
            AigcShortDramaScriptTask::where([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'task_id' => $taskId,
            ])->update([
                'status' => self::STATUS_SUCCESS,
                'progress' => 100,
                'current_step' => '剧本策划完成',
                'request_json' => self::jsonEncode($request),
                'config_snapshot' => self::jsonEncode($config),
                'pricing_snapshot' => self::jsonEncode($billing),
                'result_json' => self::jsonEncode($result),
                'error' => '',
                'billing_status' => (string)($billing['billing_status'] ?? 'none'),
                'tenant_cost_points' => (float)($billing['tenant_cost_points'] ?? 0),
                'user_charge_points' => (float)($billing['user_charge_points'] ?? 0),
                'provider' => (string)($scriptModel['provider'] ?? $taskData['provider'] ?? ''),
                'provider_request_id' => $providerRequestId,
                'finished_at' => (int)($taskData['finished_at'] ?? 0) > 0 ? (int)$taskData['finished_at'] : $time,
                'update_time' => $time,
            ]);

            self::createAgentRunRecord($tenantId, $userId, $projectId, $agentRunId, $taskId, 'initial_plan_recovered', $request, $result, $scriptModel, self::STATUS_SUCCESS, $time);
            $version = self::createPlanVersion($tenantId, $userId, $projectId, $taskId, $agentRunId, 'agent_initial', $result, 0, true, $time);
            self::createGenerationTaskRecord($tenantId, $userId, $projectId, '', 'script_plan', $taskId, self::STATUS_SUCCESS, [
                'provider' => (string)($scriptModel['provider'] ?? $taskData['provider'] ?? ''),
                'source_app_code' => self::LLM_APP_CODE,
                'model_snapshot' => $scriptModel,
                'request' => $request,
                'result' => ['version_id' => (int)$version['id'], 'recovered_from_stream' => true],
                'pricing' => $billing,
                'billing_status' => (string)($billing['billing_status'] ?? 'none'),
                'tenant_cost_points' => (float)($billing['tenant_cost_points'] ?? 0),
                'user_charge_points' => (float)($billing['user_charge_points'] ?? 0),
                'provider_request_id' => $providerRequestId,
                'started_at' => (int)($taskData['started_at'] ?? 0),
                'finished_at' => (int)($taskData['finished_at'] ?? 0) > 0 ? (int)$taskData['finished_at'] : $time,
            ]);
            AigcShortDramaProject::where([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'id' => $projectId,
            ])->update([
                'status' => self::PROJECT_STATUS_PLAN_REVIEW,
                'current_version_id' => (int)$version['id'],
                'current_agent_run_id' => $agentRunId,
                'last_task_id' => $taskId,
                'update_time' => $time,
            ]);
            self::replaceStoryboard($tenantId, $userId, $projectId, $taskId, $result['storyboard']);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            Log::write('AI short drama partial stream recovery save failed: task=' . $taskId . ' error=' . $e->getMessage());
            return $taskData;
        }

        Log::write('AI short drama script task recovered from partial stream: task=' . $taskId . ' shots=' . count((array)($result['storyboard'] ?? [])));
        $taskData['status'] = self::STATUS_SUCCESS;
        $taskData['progress'] = 100;
        $taskData['current_step'] = '剧本策划完成';
        $taskData['result_json'] = self::jsonEncode($result);
        $taskData['error'] = '';
        $taskData['billing_status'] = (string)($billing['billing_status'] ?? 'none');
        $taskData['tenant_cost_points'] = (float)($billing['tenant_cost_points'] ?? 0);
        $taskData['user_charge_points'] = (float)($billing['user_charge_points'] ?? 0);
        $taskData['provider_request_id'] = $providerRequestId;
        $taskData['finished_at'] = (int)($taskData['finished_at'] ?? 0) > 0 ? (int)$taskData['finished_at'] : $time;
        $taskData['update_time'] = $time;
        return $taskData;
    }

    private static function scriptPlanBillingFromUsage(int $tenantId, int $userId, string $providerRequestId, array $model, array $taskData): array
    {
        $usage = [];
        if ($providerRequestId !== '') {
            $usage = Db::name('ai_consumption_log')
                ->where([
                    'tenant_id' => $tenantId,
                    'user_id' => $userId,
                    'upstream_request_id' => $providerRequestId,
                ])
                ->order('id', 'desc')
                ->find() ?: [];
        }
        $price = self::jsonDecode((string)($usage['price_snapshot'] ?? ''));
        return [
            'source_app_code' => self::LLM_APP_CODE,
            'model_code' => (string)($usage['model_code'] ?? $model['model_code'] ?? ''),
            'channel_code' => (string)($usage['api_code'] ?? $model['channel_code'] ?? ''),
            'provider_model' => (string)($model['provider_model'] ?? $model['model_code'] ?? ''),
            'billing_status' => (string)($usage['billing_status'] ?? $taskData['billing_status'] ?? 'none'),
            'tenant_cost_points' => self::formatBillingPoints((float)($usage['tenant_cost_points'] ?? $taskData['tenant_cost_points'] ?? 0)),
            'user_charge_points' => self::formatBillingPoints((float)($usage['user_charge_points'] ?? $taskData['user_charge_points'] ?? 0)),
            'provider_request_id' => $providerRequestId,
            'price' => $price,
        ];
    }

    private static function recoverStaleScriptPlanTask(int $tenantId, int $userId, array $taskData): array
    {
        if ((string)($taskData['status'] ?? '') !== self::STATUS_RUNNING) {
            return $taskData;
        }
        if (self::planResultHasContent(self::jsonDecode((string)($taskData['result_json'] ?? '')))) {
            return $taskData;
        }

        $time = time();
        $startedAt = (int)($taskData['started_at'] ?? 0);
        $updatedAt = (int)($taskData['update_time'] ?? 0);
        $lastHeartbeatAt = max($startedAt, $updatedAt);
        if ($lastHeartbeatAt <= 0 || $time - $lastHeartbeatAt < self::SCRIPT_PLAN_STALE_SECONDS) {
            return $taskData;
        }

        AigcShortDramaScriptTask::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'task_id' => (string)($taskData['task_id'] ?? ''),
            'status' => self::STATUS_RUNNING,
            'delete_time' => 0,
        ])->update([
            'status' => self::STATUS_FAILED,
            'progress' => 0,
            'current_step' => '剧本策划失败',
            'error' => self::SCRIPT_PLAN_STALE_ERROR,
            'finished_at' => $time,
            'update_time' => $time,
        ]);
        $request = self::jsonDecode((string)($taskData['request_json'] ?? ''));
        $config = self::jsonDecode((string)($taskData['config_snapshot'] ?? ''));
        $modelSelections = is_array($config['model_selections'] ?? null) ? (array)$config['model_selections'] : [];
        $model = is_array($modelSelections['script_plan'] ?? null) ? (array)$modelSelections['script_plan'] : [];
        self::syncScriptPlanGenerationTask($tenantId, $userId, (int)($taskData['project_id'] ?? 0), (string)($taskData['task_id'] ?? ''), self::STATUS_FAILED, $request, $model, [
            'provider' => (string)($taskData['provider'] ?? ''),
            'provider_request_id' => (string)($taskData['provider_request_id'] ?? ''),
            'progress' => 0,
            'error_msg' => self::SCRIPT_PLAN_STALE_ERROR,
            'error_code' => 'stream_stale',
            'started_at' => (int)($taskData['started_at'] ?? 0),
            'finished_at' => $time,
        ]);
        MarketTextModelRuntimeService::failAppTask((int)($taskData['app_task_id'] ?? 0), self::SCRIPT_PLAN_STALE_ERROR, 'stream_stale');
        Log::write('AI short drama script task stale failed: task=' . (string)($taskData['task_id'] ?? '') . ' project=' . (int)($taskData['project_id'] ?? 0));

        $taskData['status'] = self::STATUS_FAILED;
        $taskData['progress'] = 0;
        $taskData['current_step'] = '剧本策划失败';
        $taskData['error'] = self::SCRIPT_PLAN_STALE_ERROR;
        $taskData['finished_at'] = $time;
        $taskData['update_time'] = $time;
        return $taskData;
    }

    private static function recoverFailedScriptPlanTaskForStream(int $tenantId, int $userId, array $taskData): array
    {
        if ((string)($taskData['status'] ?? '') !== self::STATUS_FAILED) {
            return $taskData;
        }
        if ((string)($taskData['error'] ?? '') === self::SCRIPT_PLAN_STALE_ERROR) {
            return $taskData;
        }
        $error = (string)($taskData['error'] ?? '');
        $recoverableFailed = str_contains($error, 'uk_agent_run') || str_contains($error, 'Duplicate entry') || str_contains($error, 'SQLSTATE');
        if (!$recoverableFailed) {
            return $taskData;
        }
        if ((int)($taskData['retry_count'] ?? 0) > 0) {
            return $taskData;
        }
        if (self::planResultHasContent(self::jsonDecode((string)($taskData['result_json'] ?? '')))) {
            return $taskData;
        }

        $retryCount = (int)($taskData['retry_count'] ?? 0) + 1;
        $time = time();
        AigcShortDramaScriptTask::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'task_id' => (string)($taskData['task_id'] ?? ''),
        ])->update([
            'status' => self::STATUS_PENDING,
            'progress' => 0,
            'current_step' => '等待剧本策划',
            'error' => '',
            'retry_count' => $retryCount,
            'finished_at' => 0,
            'update_time' => $time,
        ]);
        Log::write('AI short drama script task auto-recovered for retry: task=' . (string)($taskData['task_id'] ?? '') . ' project=' . (int)($taskData['project_id'] ?? 0));

        $taskData['status'] = self::STATUS_PENDING;
        $taskData['progress'] = 0;
        $taskData['current_step'] = '等待剧本策划';
        $taskData['error'] = '';
        $taskData['retry_count'] = $retryCount;
        $taskData['finished_at'] = 0;
        $taskData['update_time'] = $time;
        return $taskData;
    }

    private static function recoverCompletedScriptPlanTask(int $tenantId, int $userId, array $taskData): array
    {
        $status = (string)($taskData['status'] ?? '');
        $error = (string)($taskData['error'] ?? '');
        $recoverableFailed = $status === self::STATUS_FAILED
            && (str_contains($error, 'uk_agent_run') || str_contains($error, 'Duplicate entry') || str_contains($error, 'SQLSTATE'));
        $taskId = (string)($taskData['task_id'] ?? '');
        $projectId = (int)($taskData['project_id'] ?? 0);
        $result = self::jsonDecode((string)($taskData['result_json'] ?? ''));
        $hasRecoverableResult = self::planResultHasContent($result);
        if (in_array($status, [self::STATUS_SUCCESS, self::STATUS_CANCELED], true) || ($status === self::STATUS_FAILED && !$recoverableFailed && !$hasRecoverableResult)) {
            return $taskData;
        }

        if (!self::planResultHasContent($result) && $taskId !== '' && $projectId > 0) {
            $version = AigcShortDramaPlanVersion::where([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'project_id' => $projectId,
                'task_id' => $taskId,
                'is_current' => 1,
                'delete_time' => 0,
            ])->order(['version_no' => 'desc', 'id' => 'desc'])->findOrEmpty();
            if (!$version->isEmpty()) {
                $result = self::jsonDecode((string)($version['plan_json'] ?? ''));
            }
        }
        if (!self::planResultHasContent($result) && $recoverableFailed && $projectId > 0) {
            $version = AigcShortDramaPlanVersion::where([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'project_id' => $projectId,
                'is_current' => 1,
                'delete_time' => 0,
            ])->order(['version_no' => 'desc', 'id' => 'desc'])->findOrEmpty();
            if (!$version->isEmpty()) {
                $result = self::jsonDecode((string)($version['plan_json'] ?? ''));
            }
        }

        if (!self::planResultHasContent($result)) {
            return $taskData;
        }

        $time = time();
        AigcShortDramaScriptTask::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'task_id' => $taskId,
            'delete_time' => 0,
        ])->update([
            'status' => self::STATUS_SUCCESS,
            'progress' => 100,
            'current_step' => '剧本策划完成',
            'result_json' => self::jsonEncode($result),
            'error' => '',
            'finished_at' => (int)($taskData['finished_at'] ?? 0) > 0 ? (int)$taskData['finished_at'] : $time,
            'update_time' => $time,
        ]);

        $taskData['status'] = self::STATUS_SUCCESS;
        $taskData['progress'] = 100;
        $taskData['current_step'] = '剧本策划完成';
        $taskData['result_json'] = self::jsonEncode($result);
        $taskData['error'] = '';
        $taskData['finished_at'] = (int)($taskData['finished_at'] ?? 0) > 0 ? (int)$taskData['finished_at'] : $time;
        $taskData['update_time'] = $time;
        return $taskData;
    }

    private static function planResultHasContent(array $result): bool
    {
        return trim((string)($result['title'] ?? '')) !== ''
            || trim((string)($result['story_outline'] ?? '')) !== ''
            || !empty($result['script_lines'])
            || !empty($result['subjects'])
            || !empty($result['locations'])
            || !empty($result['scenes'])
            || !empty($result['storyboard']);
    }

    public static function streamScriptPlan(int $tenantId, int $userId, array $params, callable $emit): array
    {
        $taskId = trim((string)($params['task_id'] ?? ''));
        $projectId = (int)($params['project_id'] ?? 0);
        if ($taskId === '') {
            throw new Exception('任务不存在');
        }
        $task = self::findTask($tenantId, $userId, $taskId, $projectId);
        $taskData = self::recoverPartialStreamScriptPlanTask($tenantId, $userId, $task->toArray());
        $taskData = self::recoverCompletedScriptPlanTask($tenantId, $userId, $taskData);
        $taskData = self::recoverStaleScriptPlanTask($tenantId, $userId, $taskData);
        $status = (string)($taskData['status'] ?? '');
        if ($status === self::STATUS_SUCCESS) {
            $result = self::formatTask($taskData, true);
            $emit('done', $result);
            return $result;
        }
        if (in_array($status, [self::STATUS_FAILED, self::STATUS_CANCELED], true)) {
            $result = self::formatTask($taskData, false);
            $emit('error', ['message' => (string)($result['error'] ?? '任务失败'), 'task' => $result]);
            return $result;
        }
        if ($status === self::STATUS_RUNNING) {
            $result = self::formatTask($taskData, false);
            $emit('task', $result);
            return $result;
        }
        if (!in_array($status, [self::STATUS_PENDING, self::STATUS_QUEUED], true)) {
            $result = self::formatTask($taskData, false);
            $emit('task', $result);
            return $result;
        }

        return self::executeScriptPlanTask($tenantId, $userId, $taskData, $emit);
    }

    private static function executeScriptPlanTask(int $tenantId, int $userId, array $taskData, callable $emit): array
    {
        $taskId = (string)$taskData['task_id'];
        $projectId = (int)$taskData['project_id'];
        $project = self::findProject($tenantId, $userId, $projectId);
        $request = self::jsonDecode((string)($taskData['request_json'] ?? ''));
        $prompt = trim((string)($taskData['prompt'] ?? $request['prompt'] ?? ''));
        if ($prompt === '') {
            throw new Exception('请输入故事灵感');
        }

        $config = self::publicConfig($tenantId);
        $selectedModels = self::resolveSelectedModels($tenantId, $request, $config);
        if (empty($request['storyboard_rules']) || !is_array($request['storyboard_rules'])) {
            $request['storyboard_rules'] = self::normalizeStoryboardRules((array)($config['storyboard_rules'] ?? []));
        }
        if (empty($request['storyboard_target_rule']) || !is_array($request['storyboard_target_rule'])) {
            $request['storyboard_target_rule'] = self::storyboardTargetRule($prompt, $request);
        }
        $request['model_selections'] = self::modelSelectionsSnapshot($selectedModels);
        $request['model_id'] = (string)($selectedModels['script_plan']['id'] ?? $request['model_id'] ?? '');
        $agentRunId = self::resolveScriptPlanAgentRunId($tenantId, $project->toArray(), $taskId);
        $time = time();
        AigcShortDramaScriptTask::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'task_id' => $taskId,
        ])->update([
            'status' => self::STATUS_RUNNING,
            'progress' => 10,
            'current_step' => '剧本策划',
            'started_at' => (int)($taskData['started_at'] ?? 0) > 0 ? (int)$taskData['started_at'] : $time,
            'update_time' => $time,
        ]);
        self::syncScriptPlanGenerationTask($tenantId, $userId, $projectId, $taskId, self::STATUS_RUNNING, $request, $selectedModels['script_plan'] ?? [], [
            'provider' => (string)($selectedModels['script_plan']['provider'] ?? ''),
            'progress' => 10,
            'started_at' => (int)($taskData['started_at'] ?? 0) > 0 ? (int)$taskData['started_at'] : $time,
        ]);
        $emit('stage', [
            'status' => self::STATUS_RUNNING,
            'progress' => 10,
            'current_step' => '剧本策划',
        ]);

        $lastHeartbeatAt = 0;
        $lastStreamFlushAt = 0;
        $streamContent = '';
        $persistedProviderRequestId = '';

        try {
            $generation = self::generateScriptPlanResult(
                $tenantId,
                $userId,
                $prompt,
                $request,
                (string)$project['title'],
                $selectedModels['script_plan'] ?? [],
                static function (string $event, array $data) use ($emit, $tenantId, $userId, $taskId, &$lastHeartbeatAt, &$lastStreamFlushAt, &$streamContent, &$persistedProviderRequestId) {
                    $now = time();
                    if ($event === 'app_task') {
                        $appTaskId = (int)($data['app_task_id'] ?? 0);
                        if ($appTaskId > 0) {
                            $scriptTask = AigcShortDramaScriptTask::where([
                                'tenant_id' => $tenantId,
                                'user_id' => $userId,
                                'task_id' => $taskId,
                            ])->findOrEmpty();
                            if (!$scriptTask->isEmpty()) {
                                MarketTextModelRuntimeService::bindBusinessTask($appTaskId, 'aigc_short_drama_script_task', (int)$scriptTask['id']);
                                $scriptTask->save(['app_task_id' => $appTaskId, 'update_time' => $now]);
                            }
                        }
                    }
                    if ($event === 'provider_request') {
                        $providerRequestId = trim((string)($data['provider_request_id'] ?? ''));
                        if ($providerRequestId !== '' && $providerRequestId !== $persistedProviderRequestId) {
                            $persistedProviderRequestId = $providerRequestId;
                            AigcShortDramaScriptTask::where([
                                'tenant_id' => $tenantId,
                                'user_id' => $userId,
                                'task_id' => $taskId,
                            ])->update([
                                'provider_request_id' => $providerRequestId,
                                'update_time' => $now,
                            ]);
                            AigcShortDramaGenerationTask::where([
                                'tenant_id' => $tenantId,
                                'user_id' => $userId,
                                'task_id' => $taskId,
                                'delete_time' => 0,
                            ])->update([
                                'provider_request_id' => $providerRequestId,
                                'update_time' => $now,
                            ]);
                        }
                    }
                    if ($event === 'delta') {
                        $streamContent .= (string)($data['delta'] ?? $data['content'] ?? '');
                    }
                    if ($event === 'stage') {
                        $stageProgress = min(99, max(20, (int)($data['progress'] ?? 20)));
                        $stageStep = trim((string)($data['current_step'] ?? '剧本策划'));
                        if ($stageStep === '') {
                            $stageStep = '剧本策划';
                        }
                        AigcShortDramaScriptTask::where([
                            'tenant_id' => $tenantId,
                            'user_id' => $userId,
                            'task_id' => $taskId,
                            'status' => self::STATUS_RUNNING,
                        ])->update([
                            'progress' => $stageProgress,
                            'current_step' => $stageStep,
                            'update_time' => $now,
                        ]);
                        AigcShortDramaGenerationTask::where([
                            'tenant_id' => $tenantId,
                            'user_id' => $userId,
                            'task_id' => $taskId,
                            'status' => self::STATUS_RUNNING,
                            'delete_time' => 0,
                        ])->update([
                            'progress' => $stageProgress,
                            'update_time' => $now,
                        ]);
                    }
                    if (in_array($event, ['delta', 'provider_request', 'heartbeat'], true) && $now - $lastHeartbeatAt >= 8) {
                        $lastHeartbeatAt = $now;
                        AigcShortDramaScriptTask::where([
                            'tenant_id' => $tenantId,
                            'user_id' => $userId,
                            'task_id' => $taskId,
                            'status' => self::STATUS_RUNNING,
                        ])->update([
                            'progress' => 20,
                            'current_step' => '剧本策划',
                            'update_time' => $now,
                        ]);
                        AigcShortDramaGenerationTask::where([
                            'tenant_id' => $tenantId,
                            'user_id' => $userId,
                            'task_id' => $taskId,
                            'status' => self::STATUS_RUNNING,
                            'delete_time' => 0,
                        ])->update([
                            'progress' => 20,
                            'update_time' => $now,
                        ]);
                    }
                    if ($streamContent !== '' && ($now - $lastStreamFlushAt >= self::SCRIPT_PLAN_STREAM_FLUSH_SECONDS || $event === 'provider_request')) {
                        $lastStreamFlushAt = $now;
                        self::persistScriptPlanStreamState($tenantId, $userId, $taskId, $streamContent, $persistedProviderRequestId);
                    }
                    $emit($event, $data);
                }
            );
            $marketAppTaskId = (int)($generation['llm']['app_task_id'] ?? 0);
            if ($marketAppTaskId > 0) {
                MarketTextModelRuntimeService::bindBusinessTask($marketAppTaskId, 'aigc_short_drama_script_task', (int)($taskData['id'] ?? 0));
                AigcShortDramaScriptTask::where(['tenant_id' => $tenantId, 'user_id' => $userId, 'task_id' => $taskId])->update(['app_task_id' => $marketAppTaskId, 'update_time' => time()]);
            }
            $result = $generation['result'];
            $projectRatio = self::normalizeGenerationRatio((string)($project['ratio'] ?? '')) ?: self::normalizeGenerationRatio((string)($request['ratio'] ?? ''));
            if ($projectRatio !== '') {
                $result['generation_settings'] = is_array($result['generation_settings'] ?? null) ? $result['generation_settings'] : [];
                $result['generation_settings']['aspect_ratio'] = $projectRatio;
                $result['generation_settings']['ratio'] = $projectRatio;
            }
            $billing = self::scriptPlanBillingFromLlm($generation, $prompt, $result, $selectedModels['script_plan'] ?? []);
            $finishTime = time();

            Db::startTrans();
            try {
                AigcShortDramaScriptTask::where([
                    'tenant_id' => $tenantId,
                    'user_id' => $userId,
                    'task_id' => $taskId,
                ])->update([
                    'status' => self::STATUS_SUCCESS,
                    'progress' => 100,
                    'current_step' => '剧本策划完成',
                    'request_json' => self::jsonEncode($request),
                    'config_snapshot' => self::jsonEncode([
                        'model_id' => $request['model_id'] ?? '',
                        'model_selections' => $request['model_selections'],
                        'style_id' => $request['style_id'] ?? '',
                        'storyboard_rules' => $request['storyboard_rules'],
                        'storyboard_target_rule' => $request['storyboard_target_rule'] ?? [],
                        'provider' => (string)($selectedModels['script_plan']['provider'] ?? ''),
                    ]),
                    'pricing_snapshot' => self::jsonEncode($billing),
                    'result_json' => self::jsonEncode($result),
                    'error' => '',
                    'billing_status' => (string)($billing['billing_status'] ?? (((float)$billing['tenant_cost_points'] > 0 || (float)$billing['user_charge_points'] > 0) ? 'deducted' : 'none')),
                    'tenant_cost_points' => $billing['tenant_cost_points'],
                    'user_charge_points' => $billing['user_charge_points'],
                    'provider' => (string)($selectedModels['script_plan']['provider'] ?? $generation['provider'] ?? ''),
                    'provider_request_id' => (string)($billing['provider_request_id'] ?? ''),
                    'finished_at' => $finishTime,
                    'update_time' => $finishTime,
                ]);

                $isRevisionPlan = (string)($request['source'] ?? '') === 'revision';
                self::createAgentRunRecord($tenantId, $userId, $projectId, $agentRunId, $taskId, $isRevisionPlan ? 'agent_revision' : 'initial_plan', $request, $result, $selectedModels['script_plan'] ?? [], self::STATUS_SUCCESS, $finishTime);
                $version = self::createPlanVersion($tenantId, $userId, $projectId, $taskId, $agentRunId, $isRevisionPlan ? 'agent_revision' : 'agent_initial', $result, $isRevisionPlan ? (int)($project['current_version_id'] ?? 0) : 0, true, $finishTime);
                self::createGenerationTaskRecord($tenantId, $userId, $projectId, '', 'script_plan', $taskId, self::STATUS_SUCCESS, [
                    'provider' => (string)($selectedModels['script_plan']['provider'] ?? ''),
                    'source_app_code' => self::LLM_APP_CODE,
                    'model_snapshot' => $selectedModels['script_plan'] ?? [],
                    'request' => $request,
                    'result' => ['version_id' => (int)$version['id']],
                    'pricing' => $billing,
                    'billing_status' => (string)($billing['billing_status'] ?? (((float)$billing['tenant_cost_points'] > 0 || (float)$billing['user_charge_points'] > 0) ? 'deducted' : 'none')),
                    'tenant_cost_points' => $billing['tenant_cost_points'],
                    'user_charge_points' => $billing['user_charge_points'],
                    'provider_request_id' => (string)($billing['provider_request_id'] ?? ''),
                    'started_at' => (int)($taskData['started_at'] ?? 0) > 0 ? (int)$taskData['started_at'] : $time,
                    'finished_at' => $finishTime,
                ]);
                AigcShortDramaProject::where([
                    'tenant_id' => $tenantId,
                    'user_id' => $userId,
                    'id' => $projectId,
                ])->update([
                    'status' => self::PROJECT_STATUS_PLAN_REVIEW,
                    'current_version_id' => (int)$version['id'],
                    'current_agent_run_id' => $agentRunId,
                    'last_task_id' => $taskId,
                    'ratio' => $projectRatio !== '' ? $projectRatio : (string)($project['ratio'] ?? ''),
                    'update_time' => $finishTime,
                ]);
                self::replaceStoryboard($tenantId, $userId, $projectId, $taskId, $result['storyboard']);
                Db::commit();
            } catch (\Throwable $e) {
                Db::rollback();
                throw $e;
            }

            $task = self::findTask($tenantId, $userId, $taskId, $projectId);
            $formatted = self::formatTask($task->toArray(), true);
            $emit('done', $formatted);
            return $formatted;
        } catch (\Throwable $e) {
            $message = self::scriptPlanProviderError($e->getMessage());
            Log::write('AI short drama script stream failed: task=' . $taskId . ' project=' . $projectId . ' error=' . $e->getMessage());
            if ($streamContent !== '') {
                self::persistScriptPlanStreamState($tenantId, $userId, $taskId, $streamContent, $persistedProviderRequestId, $message);
            }
            AigcShortDramaScriptTask::where([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'task_id' => $taskId,
            ])->update([
                'status' => self::STATUS_FAILED,
                'progress' => 0,
                'current_step' => '剧本策划失败',
                'error' => $message,
                'result_json' => self::jsonEncode(self::scriptPlanStreamState($streamContent, $persistedProviderRequestId, $message)),
                'finished_at' => time(),
                'update_time' => time(),
            ]);
            self::syncScriptPlanGenerationTask($tenantId, $userId, $projectId, $taskId, self::STATUS_FAILED, $request, $selectedModels['script_plan'] ?? [], [
                'provider' => (string)($selectedModels['script_plan']['provider'] ?? ''),
                'provider_request_id' => $persistedProviderRequestId,
                'progress' => 0,
                'error_msg' => $message,
                'error_code' => str_contains($e->getMessage(), '解析') || str_contains(strtolower($e->getMessage()), 'json') ? 'parse_failed' : 'provider_failed',
                'started_at' => (int)($taskData['started_at'] ?? 0) > 0 ? (int)$taskData['started_at'] : $time,
                'finished_at' => time(),
                'result' => self::scriptPlanStreamState($streamContent, $persistedProviderRequestId, $message),
            ]);
            $task = self::findTask($tenantId, $userId, $taskId, $projectId);
            $formatted = self::formatTask($task->toArray(), false);
            $emit('error', ['message' => $message, 'task' => $formatted]);
            return $formatted;
        }
    }

    public static function saveStoryboard(int $tenantId, int $userId, array $params): array
    {
        $taskId = trim((string)($params['task_id'] ?? ''));
        if ($taskId === '') {
            throw new Exception('任务不存在');
        }
        $task = self::findTask($tenantId, $userId, $taskId);
        if (($task['status'] ?? '') === self::STATUS_CANCELED) {
            throw new Exception('Task has been canceled');
        }

        $replaceStoryboard = !empty($params['replace_storyboard']) || !empty($params['replace']);
        $shots = [];
        if (isset($params['storyboard']) && is_array($params['storyboard'])) {
            $shots = $params['storyboard'];
        } elseif (isset($params['shots']) && is_array($params['shots'])) {
            $shots = $params['shots'];
        } else {
            $shots[] = $params;
        }
        if (!$shots) {
            throw new Exception('请提交分镜内');
        }

        $updated = [];
        Db::startTrans();
        try {
            foreach (array_values($shots) as $index => $shotPayload) {
                $shotPayload = (array)$shotPayload;
                $shotId = trim((string)($shotPayload['shot_id'] ?? ''));
                if ($shotId === '') {
                    $shotId = (string)($index + 1);
                    $shotPayload['shot_id'] = $shotId;
                }
                $data = self::editableShotData($shotPayload);
                $data = array_merge($data, self::rebuildEditableShotPrompts($tenantId, $userId, $task, array_merge($shotPayload, $data)));
                $data['update_time'] = time();
                if ($replaceStoryboard) {
                    $updated[] = self::formatShot(array_merge([
                        'shot_id' => $shotId,
                        'sort' => $index + 1,
                        'act' => (string)($shotPayload['act'] ?? ''),
                        'scene_name' => (string)($shotPayload['scene_name'] ?? ''),
                        'time_of_day' => (string)($shotPayload['time_of_day'] ?? ''),
                        'interior_exterior' => in_array(($shotPayload['interior_exterior'] ?? 'exterior'), ['interior', 'exterior'], true) ? $shotPayload['interior_exterior'] : 'exterior',
                    ], $data));
                    continue;
                }
                $shot = AigcShortDramaStoryboard::where([
                    'tenant_id' => $tenantId,
                    'user_id' => $userId,
                    'task_id' => $taskId,
                    'shot_id' => $shotId,
                    'delete_time' => 0,
                ])->findOrEmpty();
                if ($shot->isEmpty()) {
                    throw new Exception('分镜不存在');
                }
                $shot->save(self::filterStoryboardWritableData($data));
                $updated[] = self::formatShot(array_merge($shot->toArray(), $data));
            }
            if ($replaceStoryboard) {
                self::replaceStoryboard($tenantId, $userId, (int)$task['project_id'], $taskId, $updated);
            }
            self::syncTaskResultStoryboard($task, $updated, $replaceStoryboard);
            $task = self::findTask($tenantId, $userId, $taskId);
            self::createUserEditVersionFromTask($tenantId, $userId, $task, $updated);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e instanceof Exception ? $e : new Exception('保存失败，请稍后重试');
        }

        return [
            'shot' => $updated[0] ?? [],
            'shots' => $updated,
        ];
    }

    public static function insertStoryboardShot(int $tenantId, int $userId, array $params): array
    {
        $projectId = (int)($params['project_id'] ?? 0);
        $taskId = trim((string)($params['task_id'] ?? ''));
        $afterShotId = trim((string)($params['after_shot_id'] ?? ''));
        if ($projectId <= 0 || $taskId === '') {
            throw new Exception('项目任务不存在');
        }
        $task = self::findTask($tenantId, $userId, $taskId, $projectId);
        if (($task['status'] ?? '') === self::STATUS_CANCELED) {
            throw new Exception('任务已取消，不能新增分镜');
        }

        Db::startTrans();
        try {
            $rows = self::activeStoryboardRows($tenantId, $userId, $projectId, $taskId);
            $insertIndex = 0;
            if ($afterShotId !== '') {
                $insertIndex = null;
                foreach ($rows as $index => $row) {
                    if ((string)($row['shot_id'] ?? '') === $afterShotId) {
                        $insertIndex = $index + 1;
                        break;
                    }
                }
                if ($insertIndex === null) {
                    throw new Exception('插入位置不存在');
                }
            }
            $newShotId = self::makeStoryboardShotId($tenantId, $taskId);
            $time = time();
            $newShot = AigcShortDramaStoryboard::create(self::filterStoryboardWritableData([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'project_id' => $projectId,
                'task_id' => $taskId,
                'shot_id' => $newShotId,
                'title' => '',
                'visual_description' => '',
                'composition' => '',
                'camera_movement' => '',
                'shot_type' => '',
                'angle' => '',
                'action' => '',
                'result' => '',
                'atmosphere' => '',
                'image_prompt' => '',
                'video_prompt' => '',
                'bgm_prompt' => '',
                'sound_effect' => '',
                'scene_ref_id' => '',
                'subject_ref_ids' => self::jsonEncode([]),
                'voice_role' => '',
                'dialogue' => '',
                'frame_type' => 'normal',
                'recommended_duration_seconds' => 3,
                'selected_image_asset_id' => 0,
                'selected_video_asset_id' => 0,
                'sort' => $insertIndex + 1,
                'create_time' => $time,
                'update_time' => $time,
                'delete_time' => 0,
            ]));
            $orderedShotIds = array_values(array_map(static fn(array $row): string => (string)($row['shot_id'] ?? ''), $rows));
            array_splice($orderedShotIds, $insertIndex, 0, [$newShotId]);
            self::reorderStoryboardShots($tenantId, $userId, $projectId, $taskId, $orderedShotIds);
            $response = self::syncStoryboardStructureAndResponse($tenantId, $userId, $task, $newShotId);
            Db::commit();
            return $response;
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e instanceof Exception ? $e : new Exception('新增分镜失败，请稍后重试');
        }
    }

    public static function copyStoryboardShot(int $tenantId, int $userId, array $params): array
    {
        $projectId = (int)($params['project_id'] ?? 0);
        $taskId = trim((string)($params['task_id'] ?? ''));
        $shotId = trim((string)($params['shot_id'] ?? ''));
        if ($projectId <= 0 || $taskId === '' || $shotId === '') {
            throw new Exception('请选择要复制的分镜');
        }
        $task = self::findTask($tenantId, $userId, $taskId, $projectId);
        if (($task['status'] ?? '') === self::STATUS_CANCELED) {
            throw new Exception('任务已取消，不能复制分镜');
        }

        Db::startTrans();
        try {
            $rows = self::activeStoryboardRows($tenantId, $userId, $projectId, $taskId);
            $source = [];
            $sourceIndex = null;
            foreach ($rows as $index => $row) {
                if ((string)($row['shot_id'] ?? '') === $shotId) {
                    $source = $row;
                    $sourceIndex = $index;
                    break;
                }
            }
            if ($sourceIndex === null) {
                throw new Exception('分镜不存在');
            }
            $newShotId = self::makeStoryboardShotId($tenantId, $taskId);
            $time = time();
            unset($source['id']);
            $source['tenant_id'] = $tenantId;
            $source['user_id'] = $userId;
            $source['project_id'] = $projectId;
            $source['task_id'] = $taskId;
            $source['shot_id'] = $newShotId;
            $source['sort'] = $sourceIndex + 2;
            $source['selected_image_asset_id'] = self::resolveCopiedStoryboardAssetId(
                $tenantId,
                $userId,
                $projectId,
                $shotId,
                'shot_image',
                (int)($params['selected_image_asset_id'] ?? $params['image_asset_id'] ?? 0),
                (int)($source['selected_image_asset_id'] ?? 0)
            );
            $source['selected_video_asset_id'] = self::resolveCopiedStoryboardAssetId(
                $tenantId,
                $userId,
                $projectId,
                $shotId,
                'shot_video',
                (int)($params['selected_video_asset_id'] ?? $params['video_asset_id'] ?? 0),
                (int)($source['selected_video_asset_id'] ?? 0)
            );
            $source['create_time'] = $time;
            $source['update_time'] = $time;
            $source['delete_time'] = 0;
            AigcShortDramaStoryboard::create(self::filterStoryboardWritableData($source));

            $orderedShotIds = array_values(array_map(static fn(array $row): string => (string)($row['shot_id'] ?? ''), $rows));
            array_splice($orderedShotIds, $sourceIndex + 1, 0, [$newShotId]);
            self::reorderStoryboardShots($tenantId, $userId, $projectId, $taskId, $orderedShotIds);
            $response = self::syncStoryboardStructureAndResponse($tenantId, $userId, $task, $newShotId);
            Db::commit();
            return $response;
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e instanceof Exception ? $e : new Exception('复制分镜失败，请稍后重试');
        }
    }

    public static function deleteStoryboardShot(int $tenantId, int $userId, array $params): array
    {
        $projectId = (int)($params['project_id'] ?? 0);
        $taskId = trim((string)($params['task_id'] ?? ''));
        $shotId = trim((string)($params['shot_id'] ?? ''));
        if ($projectId <= 0 || $taskId === '' || $shotId === '') {
            throw new Exception('请选择要删除的分镜');
        }
        $task = self::findTask($tenantId, $userId, $taskId, $projectId);
        if (($task['status'] ?? '') === self::STATUS_CANCELED) {
            throw new Exception('任务已取消，不能删除分镜');
        }

        Db::startTrans();
        try {
            $rows = self::activeStoryboardRows($tenantId, $userId, $projectId, $taskId);
            if (count($rows) <= 1) {
                throw new Exception('至少保留一个分镜');
            }
            $deleteIndex = null;
            foreach ($rows as $index => $row) {
                if ((string)($row['shot_id'] ?? '') === $shotId) {
                    $deleteIndex = $index;
                    break;
                }
            }
            if ($deleteIndex === null) {
                throw new Exception('分镜不存在');
            }
            $time = time();
            AigcShortDramaStoryboard::where([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'project_id' => $projectId,
                'task_id' => $taskId,
                'shot_id' => $shotId,
                'delete_time' => 0,
            ])->update([
                'delete_time' => $time,
                'update_time' => $time,
            ]);
            $remainingShotIds = [];
            foreach ($rows as $row) {
                $currentShotId = (string)($row['shot_id'] ?? '');
                if ($currentShotId !== '' && $currentShotId !== $shotId) {
                    $remainingShotIds[] = $currentShotId;
                }
            }
            self::reorderStoryboardShots($tenantId, $userId, $projectId, $taskId, $remainingShotIds);
            $activeShotId = $remainingShotIds[min($deleteIndex, count($remainingShotIds) - 1)] ?? ($remainingShotIds[0] ?? '');
            $response = self::syncStoryboardStructureAndResponse($tenantId, $userId, $task, $activeShotId);
            Db::commit();
            return $response;
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e instanceof Exception ? $e : new Exception('删除分镜失败，请稍后重试');
        }
    }

    public static function saveVisualPlan(int $tenantId, int $userId, array $params): array
    {
        $taskId = trim((string)($params['task_id'] ?? ''));
        $projectId = (int)($params['project_id'] ?? 0);
        if ($taskId === '') {
            throw new Exception('任务不存在');
        }
        $task = self::findTask($tenantId, $userId, $taskId, $projectId);
        $result = self::jsonDecode((string)$task['result_json']);
        if (empty($result) || !is_array($result)) {
            throw new Exception('剧本计划不存在');
        }

        $previousSubjectIds = self::planItemIds((array)($result['subjects'] ?? []));
        $previousLocationIds = self::planItemIds((array)($result['locations'] ?? $result['scenes'] ?? []));
        $subjects = self::normalizeEditablePlanItems((array)($params['subjects'] ?? $result['subjects'] ?? []), 'subject');
        $locations = self::normalizeEditablePlanItems((array)($params['locations'] ?? $result['locations'] ?? $result['scenes'] ?? []), 'location');
        $removedSubjectIds = isset($params['subjects'])
            ? array_values(array_diff($previousSubjectIds, self::planItemIds($subjects)))
            : [];
        $removedLocationIds = isset($params['locations'])
            ? array_values(array_diff($previousLocationIds, self::planItemIds($locations)))
            : [];
        if (isset($params['subjects'])) {
            $result['subjects'] = $subjects;
        }
        if (isset($params['locations'])) {
            $result['locations'] = $locations;
            $result['scenes'] = $locations;
        }

        $subjectIds = array_values(array_filter(array_map(static fn($item) => (string)($item['id'] ?? ''), (array)($result['subjects'] ?? []))));
        if (isset($result['storyboard']) && is_array($result['storyboard'])) {
            foreach ($result['storyboard'] as &$shot) {
                if (!is_array($shot)) {
                    continue;
                }
                $refs = array_values(array_intersect(
                    array_map('strval', (array)($shot['subject_ref_ids'] ?? [])),
                    $subjectIds
                ));
                $shot['subject_ref_ids'] = $refs;
            }
            unset($shot);
        }
        $result = self::reviewAndRepairPlanResult(self::enhancePlanResult($result));

        Db::startTrans();
        try {
            $versionUpdate = [
                'plan_json' => self::jsonEncode($result),
                'story_bible_json' => self::jsonEncode(self::storyBibleFromResult($result)),
                'continuity_json' => self::jsonEncode(self::continuityFromResult($result)),
                'update_time' => time(),
            ];
            $version = AigcShortDramaPlanVersion::where([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'project_id' => (int)$task['project_id'],
                'task_id' => $taskId,
                'delete_time' => 0,
            ])->findOrEmpty();
            if ($version->isEmpty()) {
                $project = self::findProject($tenantId, $userId, (int)$task['project_id']);
                $version = self::createPlanVersion(
                    $tenantId,
                    $userId,
                    (int)$task['project_id'],
                    $taskId,
                    'visual_edit_' . time() . '_' . (int)$task['project_id'],
                    'user_edit',
                    $result,
                    (int)($project['current_version_id'] ?? 0),
                    true,
                    time()
                );
                AigcShortDramaProject::where([
                    'tenant_id' => $tenantId,
                    'user_id' => $userId,
                    'id' => (int)$task['project_id'],
                ])->update([
                    'current_version_id' => (int)$version['id'],
                    'update_time' => time(),
                ]);
            } else {
                $version->save($versionUpdate);
            }
            $task->save([
                'result_json' => self::jsonEncode($result),
                'update_time' => time(),
            ]);
            self::softDeleteRemovedVisualItems($tenantId, $userId, (int)$task['project_id'], $taskId, $removedSubjectIds, $removedLocationIds);
            foreach ((array)($result['storyboard'] ?? []) as $shot) {
                if (!is_array($shot)) {
                    continue;
                }
                $shotId = (string)($shot['shot_id'] ?? $shot['id'] ?? '');
                if ($shotId === '') {
                    continue;
                }
                $updateData = self::filterStoryboardWritableData([
                    'subject_ref_ids' => self::jsonEncode(array_values(array_map('strval', (array)($shot['subject_ref_ids'] ?? [])))),
                    'update_time' => time(),
                ]);
                AigcShortDramaStoryboard::where([
                    'tenant_id' => $tenantId,
                    'user_id' => $userId,
                    'task_id' => $taskId,
                    'shot_id' => $shotId,
                    'delete_time' => 0,
                ])->update($updateData);
            }
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e instanceof Exception ? $e : new Exception('保存失败，请稍后重试');
        }

        return self::formatTask(array_merge($task->toArray(), [
            'result_json' => self::jsonEncode($result),
        ]), true);
    }

    private static function normalizeEditablePlanItems(array $items, string $prefix): array
    {
        $result = [];
        foreach (array_values($items) as $index => $item) {
            $item = is_array($item) ? $item : ['name' => (string)$item];
            $name = trim((string)($item['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $description = trim((string)($item['description'] ?? $item['prompt'] ?? ''));
            $visualPrompt = trim((string)($item['visual_prompt'] ?? $item['image_prompt'] ?? $item['prompt'] ?? $description));
            $data = [
                'id' => trim((string)($item['id'] ?? '')) ?: $prefix . '_' . ($index + 1),
                'name' => mb_substr($name, 0, 80, 'UTF-8'),
                'description' => mb_substr($description !== '' ? $description : $visualPrompt, 0, 500, 'UTF-8'),
                'visual_prompt' => mb_substr($visualPrompt, 0, 1000, 'UTF-8'),
            ];
            foreach (['image', 'raw_image', 'voice_id', 'voice_name', 'voice_label', 'voice_source', 'category', 'model_id', 'model_name', 'three_view_image', 'three_view_raw_image'] as $key) {
                if (array_key_exists($key, $item)) {
                    $data[$key] = is_numeric($item[$key]) ? (int)$item[$key] : mb_substr(trim((string)$item[$key]), 0, 500, 'UTF-8');
                }
            }
            $result[] = $data;
        }
        return $result;
    }

    private static function planItemIds(array $items): array
    {
        return array_values(array_unique(array_filter(array_map(static function ($item): string {
            return is_array($item) ? trim((string)($item['id'] ?? '')) : '';
        }, $items))));
    }

    private static function softDeleteRemovedVisualItems(int $tenantId, int $userId, int $projectId, string $taskId, array $subjectIds, array $locationIds): void
    {
        $groups = [
            [
                'ids' => array_values(array_filter(array_map('strval', $subjectIds))),
                'types' => ['subject_image', 'three_view'],
                'keys' => ['subject_id', 'item_id'],
            ],
            [
                'ids' => array_values(array_filter(array_map('strval', $locationIds))),
                'types' => ['scene_image'],
                'keys' => ['scene_id', 'item_id'],
            ],
        ];
        $time = time();
        $deletedTaskIds = [];
        foreach ($groups as $group) {
            if (empty($group['ids'])) {
                continue;
            }
            $idMap = array_fill_keys($group['ids'], true);
            $taskRows = AigcShortDramaGenerationTask::where([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'project_id' => $projectId,
                'delete_time' => 0,
            ])->whereIn('task_type', $group['types'])
                ->select();
            foreach ($taskRows as $row) {
                $request = self::jsonDecode((string)($row['request_json'] ?? ''));
                $params = (array)($request['params'] ?? []);
                $nested = is_array($params['params'] ?? null) ? (array)$params['params'] : [];
                $matched = false;
                foreach ($group['keys'] as $key) {
                    $value = (string)($params[$key] ?? $nested[$key] ?? '');
                    if ($value !== '' && isset($idMap[$value])) {
                        $matched = true;
                        break;
                    }
                }
                if (!$matched) {
                    continue;
                }
                $deletedTaskIds[] = (string)$row['task_id'];
                $row->save([
                    'delete_time' => $time,
                    'update_time' => $time,
                ]);
            }

            $assetRows = AigcShortDramaAsset::where([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'project_id' => $projectId,
                'delete_time' => 0,
            ])->whereIn('asset_type', $group['types'])
                ->select();
            foreach ($assetRows as $row) {
                $meta = self::jsonDecode((string)($row['meta_json'] ?? ''));
                $matched = in_array((string)$row['task_id'], $deletedTaskIds, true);
                if (!$matched) {
                    foreach ($group['keys'] as $key) {
                        $value = (string)($meta[$key] ?? '');
                        if ($value !== '' && isset($idMap[$value])) {
                            $matched = true;
                            break;
                        }
                    }
                }
                if (!$matched) {
                    continue;
                }
                $row->save([
                    'delete_time' => $time,
                    'update_time' => $time,
                ]);
            }
        }
    }

    private static function normalizeRevisionTarget(array $params, array $plan): array
    {
        $type = trim((string)($params['target_type'] ?? $params['target'] ?? ''));
        $id = trim((string)($params['target_id'] ?? $params['id'] ?? ''));
        if ($type === '' && $id === '') {
            return [];
        }
        $typeMap = [
            'subject' => 'subject',
            'subjects' => 'subject',
            'role' => 'subject',
            'character' => 'subject',
            'location' => 'location',
            'scene' => 'location',
            'locations' => 'location',
            'storyboard' => 'shot',
            'shot' => 'shot',
            'image_prompt' => 'image_prompt',
            'video_prompt' => 'video_prompt',
            'music_prompt' => 'music_prompt',
            'bgm_prompt' => 'music_prompt',
            'story_outline' => 'story_outline',
            'script' => 'story_outline',
        ];
        $normalizedType = $typeMap[$type] ?? '';
        if ($normalizedType === '') {
            throw new Exception('不支持的局部修改目');
        }
        $payload = [];
        if ($normalizedType === 'subject') {
            $payload = self::planItemByExactId((array)($plan['subjects'] ?? []), $id);
        } elseif ($normalizedType === 'location') {
            $payload = self::planItemByExactId((array)($plan['locations'] ?? $plan['scenes'] ?? []), $id);
        } elseif (in_array($normalizedType, ['shot', 'image_prompt', 'video_prompt', 'music_prompt'], true)) {
            foreach ((array)($plan['storyboard'] ?? []) as $shot) {
                if (is_array($shot) && (string)($shot['shot_id'] ?? $shot['id'] ?? '') === $id) {
                    $payload = $shot;
                    break;
                }
            }
        } elseif ($normalizedType === 'story_outline') {
            $payload = [
                'story_outline' => (string)($plan['story_outline'] ?? ''),
                'script_lines' => array_slice((array)($plan['script_lines'] ?? []), 0, 30),
            ];
        }
        if ($normalizedType !== 'story_outline' && empty($payload)) {
            throw new Exception('局部修改目标不存在');
        }
        return [
            'type' => $normalizedType,
            'id' => $id,
            'payload' => $payload,
            'rule' => 'Only modify this target and fields directly required for consistency. Preserve all other subjects, locations, storyboard order, story outline, and unrelated prompts.',
        ];
    }

    private static function protectRevisionTargetResult(array $result, array $request): array
    {
        $target = is_array($request['revision_target'] ?? null) ? (array)$request['revision_target'] : [];
        $base = is_array($request['revision_base_result'] ?? null) ? (array)$request['revision_base_result'] : [];
        $type = (string)($target['type'] ?? '');
        $id = (string)($target['id'] ?? '');
        if ($type === '' || empty($base)) {
            return $result;
        }

        $merged = self::stripPlanRuntimeFields(self::enhancePlanResult($base));
        $result = self::enhancePlanResult($result);
        if ($type === 'subject') {
            $item = self::planItemByExactId((array)($result['subjects'] ?? []), $id);
            if (!empty($item)) {
                $merged['subjects'] = self::replacePlanItemById((array)($merged['subjects'] ?? []), $id, $item);
            }
        } elseif ($type === 'location') {
            $item = self::planItemByExactId((array)($result['locations'] ?? $result['scenes'] ?? []), $id);
            if (!empty($item)) {
                $locations = self::replacePlanItemById((array)($merged['locations'] ?? $merged['scenes'] ?? []), $id, $item);
                $merged['locations'] = $locations;
                $merged['scenes'] = $locations;
            }
        } elseif ($type === 'shot') {
            $shot = self::planShotById((array)($result['storyboard'] ?? []), $id);
            if (!empty($shot)) {
                $merged['storyboard'] = self::replacePlanShotById((array)($merged['storyboard'] ?? []), $id, $shot);
            }
        } elseif ($type === 'image_prompt' || $type === 'video_prompt' || $type === 'music_prompt') {
            $shot = self::planShotById((array)($result['storyboard'] ?? []), $id);
            if (!empty($shot)) {
                $fields = $type === 'image_prompt'
                    ? ['image_prompt', 'image_negative_prompt']
                    : ($type === 'video_prompt' ? ['video_prompt', 'video_negative_prompt'] : ['bgm_prompt', 'sound_effect']);
                $merged['storyboard'] = self::replacePlanShotFieldsById((array)($merged['storyboard'] ?? []), $id, $shot, $fields);
            }
        } elseif ($type === 'story_outline') {
            foreach (['title', 'type_judgement', 'core_theme', 'story_outline', 'script_lines'] as $field) {
                if (array_key_exists($field, $result)) {
                    $merged[$field] = $result[$field];
                }
            }
        }

        $merged['revision_target'] = [
            'type' => $type,
            'id' => $id,
            'protected' => true,
        ];
        return $merged;
    }

    private static function replacePlanItemById(array $items, string $id, array $replacement): array
    {
        foreach ($items as $index => $item) {
            if (is_array($item) && (string)($item['id'] ?? '') === $id) {
                $items[$index] = array_merge($item, $replacement, ['id' => $id]);
                return array_values($items);
            }
        }
        return array_values($items);
    }

    private static function planShotById(array $storyboard, string $id): array
    {
        foreach ($storyboard as $shot) {
            if (is_array($shot) && (string)($shot['shot_id'] ?? $shot['id'] ?? '') === $id) {
                return $shot;
            }
        }
        return [];
    }

    private static function replacePlanShotById(array $storyboard, string $id, array $replacement): array
    {
        foreach ($storyboard as $index => $shot) {
            if (is_array($shot) && (string)($shot['shot_id'] ?? $shot['id'] ?? '') === $id) {
                $storyboard[$index] = array_merge($shot, $replacement, ['shot_id' => (string)($shot['shot_id'] ?? $id)]);
                return array_values($storyboard);
            }
        }
        return array_values($storyboard);
    }

    private static function replacePlanShotFieldsById(array $storyboard, string $id, array $source, array $fields): array
    {
        foreach ($storyboard as $index => $shot) {
            if (!is_array($shot) || (string)($shot['shot_id'] ?? $shot['id'] ?? '') !== $id) {
                continue;
            }
            foreach ($fields as $field) {
                if (array_key_exists($field, $source)) {
                    $shot[$field] = $source[$field];
                }
            }
            $storyboard[$index] = $shot;
            return array_values($storyboard);
        }
        return array_values($storyboard);
    }

    public static function message(int $tenantId, int $userId, array $params): array
    {
        $taskId = trim((string)($params['task_id'] ?? ''));
        $message = trim((string)($params['message'] ?? ''));
        if ($taskId === '' || $message === '') {
            throw new Exception('请输入修改意');
        }
        if (mb_strlen($message, 'UTF-8') > 2000) {
            throw new Exception('修改意见过长，请缩短后重');
        }
        self::checkSensitivePrompt($message);
        $task = self::findTask($tenantId, $userId, $taskId);
        $project = self::findProject($tenantId, $userId, (int)$task['project_id']);
        $previousResult = self::jsonDecode((string)$task['result_json']);
        if (!self::planResultHasContent($previousResult)) {
            throw new Exception('当前剧本还未生成完成，暂不能修改');
        }
        $request = self::jsonDecode((string)$task['request_json']);
        if (array_key_exists('multi_episode', $params)) {
            $request['multi_episode'] = (bool)$params['multi_episode'];
            $request['episode_count'] = min(100, max(1, (int)($params['episode_count'] ?? ($request['multi_episode'] ? 3 : 1))));
        }
        if (is_array($params['model_selections'] ?? null)) {
            $request['model_selections'] = (array)$params['model_selections'];
        }
        if (array_key_exists('model_id', $params)) {
            $request['model_id'] = trim((string)$params['model_id']);
        }
        if (array_key_exists('style_id', $params)) {
            $request['style_id'] = trim((string)$params['style_id']);
        }
        if (array_key_exists('subject_ids', $params)) {
            $request['subject_ids'] = array_values(array_filter((array)$params['subject_ids']));
        }
        if (array_key_exists('subject_mentions', $params)) {
            $request['subject_mentions'] = array_values(array_filter((array)$params['subject_mentions']));
        }
        $config = self::publicConfig($tenantId);
        $selectedModels = self::resolveSelectedModels($tenantId, $request, $config);
        if (empty($request['storyboard_rules']) || !is_array($request['storyboard_rules'])) {
            $request['storyboard_rules'] = self::normalizeStoryboardRules((array)($config['storyboard_rules'] ?? []));
        }
        $newTaskId = self::makeTaskId('sd_plan_revision');
        $agentRunId = self::makeTaskId('sd_agent_revision');
        $time = time();
        $revisionBaseResult = [
            'title' => (string)($previousResult['title'] ?? ''),
            'type_judgement' => (string)($previousResult['type_judgement'] ?? ''),
            'core_theme' => (string)($previousResult['core_theme'] ?? ''),
            'story_outline' => (string)($previousResult['story_outline'] ?? ''),
            'script_lines' => array_slice((array)($previousResult['script_lines'] ?? []), 0, 30),
            'music_plan' => is_array($previousResult['music_plan'] ?? null) ? $previousResult['music_plan'] : [],
            'art_style' => is_array($previousResult['art_style'] ?? null) ? $previousResult['art_style'] : [],
            'subjects' => array_slice((array)($previousResult['subjects'] ?? []), 0, 50),
            'locations' => array_slice((array)($previousResult['locations'] ?? $previousResult['scenes'] ?? []), 0, 50),
            'storyboard' => array_slice((array)($previousResult['storyboard'] ?? []), 0, 80),
        ];
        $revisionTarget = self::normalizeRevisionTarget($params, $previousResult);
        $request['revision_message'] = $message;
        $request['revision_base_task_id'] = $taskId;
        $request['revision_base_result'] = $revisionBaseResult;
        if (!empty($revisionTarget)) {
            $request['revision_target'] = $revisionTarget;
        }
        $request['source'] = 'revision';
        $request['model_selections'] = self::modelSelectionsSnapshot($selectedModels);
        $request['model_id'] = (string)($selectedModels['script_plan']['id'] ?? $request['model_id'] ?? '');
        $prompt = trim((string)($task['prompt'] ?? $request['prompt'] ?? $project['prompt'] ?? ''));
        if (empty($request['storyboard_target_rule']) || !is_array($request['storyboard_target_rule'])) {
            $request['storyboard_target_rule'] = self::storyboardTargetRule(
                $prompt,
                $request,
                (array)($previousResult['locations'] ?? $previousResult['scenes'] ?? [])
            );
        }
        if ($prompt === '') {
            throw new Exception('原始剧本灵感不存在，请重新创建');
        }

        Db::startTrans();
        try {
            AigcShortDramaScriptTask::create([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'project_id' => (int)$task['project_id'],
                'task_id' => $newTaskId,
                'parent_task_id' => $taskId,
                'status' => self::STATUS_PENDING,
                'progress' => 0,
                'current_step' => '等待重新生成剧本',
                'prompt' => $prompt,
                'request_json' => self::jsonEncode($request),
                'config_snapshot' => self::jsonEncode([
                    'model_id' => $request['model_id'] ?? '',
                    'model_selections' => $request['model_selections'],
                    'style_id' => $request['style_id'] ?? '',
                    'storyboard_rules' => $request['storyboard_rules'],
                    'storyboard_target_rule' => $request['storyboard_target_rule'],
                    'provider' => (string)($selectedModels['script_plan']['provider'] ?? ''),
                    'revision' => true,
                ]),
                'pricing_snapshot' => self::jsonEncode([]),
                'result_json' => self::jsonEncode([]),
                'error' => '',
                'billing_status' => 'none',
                'tenant_cost_points' => '0.00',
                'user_charge_points' => '0.00',
                'provider' => (string)($selectedModels['script_plan']['provider'] ?? ''),
                'provider_request_id' => '',
                'provider_task_id' => '',
                'idempotency_key' => sha1($tenantId . '|' . $userId . '|' . $newTaskId),
                'create_time' => $time,
                'update_time' => $time,
                'started_at' => 0,
                'finished_at' => 0,
                'delete_time' => 0,
            ]);
            AigcShortDramaProject::where([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'id' => (int)$task['project_id'],
            ])->update([
                'last_task_id' => $newTaskId,
                'status' => self::PROJECT_STATUS_PLANNING,
                'current_agent_run_id' => $agentRunId,
                'update_time' => $time,
            ]);
            self::createGenerationTaskRecord($tenantId, $userId, (int)$task['project_id'], '', 'script_plan', $newTaskId, self::STATUS_PENDING, [
                'provider' => (string)($selectedModels['script_plan']['provider'] ?? ''),
                'source_app_code' => self::LLM_APP_CODE,
                'model_snapshot' => $selectedModels['script_plan'] ?? [],
                'request' => $request,
                'result' => [],
                'pricing' => [],
                'billing_status' => 'none',
                'tenant_cost_points' => 0,
                'user_charge_points' => 0,
                'started_at' => 0,
                'finished_at' => 0,
            ]);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            Log::write('AI short drama revision task create failed: ' . $e->getMessage());
            throw new Exception(self::SAFE_ERROR);
        }

        return [
            'message_id' => 'msg_' . $time,
            'task_id' => $newTaskId,
            'status' => self::STATUS_PENDING,
        ];

        $request = self::jsonDecode((string)$task['request_json']);
        $request['revision_message'] = $message;
        $request['source'] = 'revision';
        $prompt = trim((string)$task['prompt']) . "\nRevision instructions: " . $message;

        $project = self::findProject($tenantId, $userId, (int)$task['project_id']);
        $config = self::publicConfig($tenantId);
        $selectedModels = self::resolveSelectedModels($tenantId, $request, $config);
        $generation = self::generateScriptPlanResult($tenantId, $userId, $prompt, $request, (string)$project['title'], $selectedModels['script_plan'] ?? []);
        $result = $generation['result'];
        $projectRatio = self::normalizeGenerationRatio((string)($project['ratio'] ?? '')) ?: self::normalizeGenerationRatio((string)($request['ratio'] ?? ''));
        if ($projectRatio !== '') {
            $request['ratio'] = $projectRatio;
            $result['generation_settings'] = is_array($result['generation_settings'] ?? null) ? $result['generation_settings'] : [];
            $result['generation_settings']['aspect_ratio'] = $projectRatio;
            $result['generation_settings']['ratio'] = $projectRatio;
        }
        $billing = self::scriptPlanBillingFromLlm($generation, $prompt, $result, $selectedModels['script_plan'] ?? []);
        $newTaskId = self::makeTaskId('sd_plan_revision');
        $agentRunId = self::makeTaskId('sd_agent_revision');
        $time = time();
        Db::startTrans();
        try {
            AigcShortDramaScriptTask::create([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'project_id' => (int)$task['project_id'],
                'task_id' => $newTaskId,
                'parent_task_id' => $taskId,
                'status' => self::STATUS_SUCCESS,
                'progress' => 100,
                'current_step' => '修改完成',
                'prompt' => $prompt,
                'request_json' => self::jsonEncode($request),
                'config_snapshot' => (string)$task['config_snapshot'],
                'pricing_snapshot' => self::jsonEncode(array_merge($billing, ['revision' => true])),
                'result_json' => self::jsonEncode($result),
                'billing_status' => (string)($billing['billing_status'] ?? 'none'),
                'tenant_cost_points' => $billing['tenant_cost_points'] ?? '0.00',
                'user_charge_points' => $billing['user_charge_points'] ?? '0.00',
                'provider' => (string)($selectedModels['script_plan']['provider'] ?? $generation['provider'] ?? ''),
                'provider_request_id' => (string)($billing['provider_request_id'] ?? ''),
                'provider_task_id' => '',
                'idempotency_key' => sha1($tenantId . '|' . $userId . '|' . $newTaskId),
                'create_time' => $time,
                'update_time' => $time,
                'started_at' => $time,
                'finished_at' => $time,
                'delete_time' => 0,
            ]);
            AigcShortDramaProject::where([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'id' => (int)$task['project_id'],
            ])->update([
                'last_task_id' => $newTaskId,
                'status' => self::PROJECT_STATUS_PLAN_REVIEW,
                'current_agent_run_id' => $agentRunId,
                'ratio' => $projectRatio !== '' ? $projectRatio : (string)($project['ratio'] ?? ''),
                'update_time' => $time,
            ]);
            self::createAgentRunRecord($tenantId, $userId, (int)$task['project_id'], $agentRunId, $newTaskId, 'agent_revision', $request, $result, $selectedModels['script_plan'] ?? [], self::STATUS_SUCCESS, $time);
            $parentVersionId = (int)($project['current_version_id'] ?? 0);
            $version = self::createPlanVersion($tenantId, $userId, (int)$task['project_id'], $newTaskId, $agentRunId, 'agent_revision', $result, $parentVersionId, true, $time);
            AigcShortDramaProject::where([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'id' => (int)$task['project_id'],
            ])->update([
                'current_version_id' => (int)$version['id'],
                'update_time' => $time,
            ]);
            self::replaceStoryboard($tenantId, $userId, (int)$task['project_id'], $newTaskId, $result['storyboard']);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            Log::write('AI short drama revision failed: ' . $e->getMessage());
            throw new Exception(self::SAFE_ERROR);
        }

        return [
            'message_id' => 'msg_' . $time,
            'task_id' => $newTaskId,
            'status' => self::STATUS_SUCCESS,
        ];
    }

    public static function retry(int $tenantId, int $userId, string $taskId): array
    {
        $task = self::findTask($tenantId, $userId, $taskId);
        if (($task['status'] ?? '') !== self::STATUS_FAILED) {
            throw new Exception('当前任务不需要重');
        }
        return self::createScriptPlan($tenantId, $userId, self::jsonDecode((string)$task['request_json']));
    }

    public static function cancel(int $tenantId, int $userId, string $taskId): array
    {
        $task = self::findTask($tenantId, $userId, $taskId);
        if (in_array((string)$task['status'], [self::STATUS_SUCCESS, self::STATUS_FAILED, self::STATUS_CANCELED], true)) {
            return self::formatTask($task->toArray(), false);
        }
        $task->save([
            'status' => self::STATUS_CANCELED,
            'error' => 'Task has been canceled',
            'progress' => 0,
            'finished_at' => time(),
            'update_time' => time(),
        ]);
        return self::formatTask($task->toArray(), false);
    }

    public static function registerAsset(int $tenantId, int $userId, array $params): array
    {
        $projectId = (int)($params['project_id'] ?? 0);
        $project = self::findProject($tenantId, $userId, $projectId);
        $assetType = self::normalizeAssetType((string)($params['asset_type'] ?? $params['type'] ?? 'reference_image'));
        $uri = FileService::setFileUrl((string)($params['uri'] ?? $params['url'] ?? ''));
        if ($uri === '') {
            throw new Exception('Asset file is required');
        }
        $storedFile = self::storageInfoForUploadedFile($tenantId, $uri);
        $meta = is_array($params['meta'] ?? null) ? $params['meta'] : [];
        $meta = array_merge($meta, [
            'source' => (string)($meta['source'] ?? $params['source'] ?? 'pc_upload'),
            'project_id' => (string)$projectId,
            'task_id' => (string)($params['task_id'] ?? ''),
            'shot_id' => (string)($params['shot_id'] ?? ''),
            'asset_type' => $assetType,
            'title' => (string)($params['title'] ?? $meta['title'] ?? ''),
        ]);
        $time = time();
        $asset = AigcShortDramaAsset::create([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'project_id' => $projectId,
            'task_id' => (string)($params['task_id'] ?? ''),
            'shot_id' => (string)($params['shot_id'] ?? ''),
            'asset_type' => $assetType,
            'title' => mb_substr(trim((string)($params['title'] ?? '')), 0, 120, 'UTF-8'),
            'uri' => $uri,
            'cover_uri' => FileService::setFileUrl((string)($params['cover_uri'] ?? $params['cover_url'] ?? '')),
            'storage_scope' => (string)($params['storage_scope'] ?? $storedFile['storage_scope'] ?? 'tenant'),
            'storage_engine' => (string)($params['storage_engine'] ?? $storedFile['storage_engine'] ?? 'local'),
            'storage_domain' => (string)($params['storage_domain'] ?? $storedFile['storage_domain'] ?? ''),
            'mime_type' => mb_substr(trim((string)($params['mime_type'] ?? '')), 0, 120, 'UTF-8'),
            'file_size' => (int)($params['file_size'] ?? 0),
            'width' => (int)($params['width'] ?? 0),
            'height' => (int)($params['height'] ?? 0),
            'duration' => (float)($params['duration'] ?? 0),
            'checksum' => mb_substr(trim((string)($params['checksum'] ?? '')), 0, 100, 'UTF-8'),
            'meta_json' => self::jsonEncode($meta),
            'status' => 'ready',
            'create_time' => $time,
            'update_time' => $time,
            'delete_time' => 0,
        ]);
        self::touchProject($project, ['update_time' => $time]);
        return self::formatAsset($asset->toArray());
    }

    public static function extractVideoLastFrame(int $tenantId, int $userId, array $params): array
    {
        $projectId = (int)($params['project_id'] ?? 0);
        $taskId = trim((string)($params['task_id'] ?? ''));
        $targetShotId = trim((string)($params['shot_id'] ?? ''));
        $assetId = (int)($params['asset_id'] ?? 0);
        if ($projectId <= 0 || $taskId === '' || $targetShotId === '' || $assetId <= 0) {
            throw new Exception('请选择上一分镜视频');
        }

        $project = self::findProject($tenantId, $userId, $projectId);
        $shot = AigcShortDramaStoryboard::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'project_id' => $projectId,
            'task_id' => $taskId,
            'shot_id' => $targetShotId,
            'delete_time' => 0,
        ])->findOrEmpty();
        if ($shot->isEmpty()) {
            throw new Exception('当前分镜不存在');
        }

        $sourceAsset = AigcShortDramaAsset::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'project_id' => $projectId,
            'task_id' => $taskId,
            'id' => $assetId,
            'asset_type' => 'shot_video',
            'status' => 'ready',
            'delete_time' => 0,
        ])->findOrEmpty();
        if ($sourceAsset->isEmpty()) {
            throw new Exception('上一分镜视频不存在或还未生成完成');
        }

        $sourceRow = $sourceAsset->toArray();
        $previousShotId = '';
        $shotRows = AigcShortDramaStoryboard::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'project_id' => $projectId,
            'task_id' => $taskId,
            'delete_time' => 0,
        ])->order(['sort' => 'asc', 'id' => 'asc'])->field('shot_id')->select()->toArray();
        foreach ($shotRows as $index => $row) {
            if ((string)($row['shot_id'] ?? '') === $targetShotId) {
                $previousShotId = $index > 0 ? (string)($shotRows[$index - 1]['shot_id'] ?? '') : '';
                break;
            }
        }
        if ($previousShotId === '') {
            throw new Exception('当前分镜没有上一分镜可引');
        }
        if ((string)($sourceRow['shot_id'] ?? '') !== $previousShotId) {
            throw new Exception('只能提取上一分镜的视频尾');
        }

        $existing = self::existingExtractedVideoTailFrame($tenantId, $userId, $projectId, $taskId, $targetShotId, $assetId);
        if (!empty($existing)) {
            return self::formatAsset($existing);
        }

        $ffmpeg = self::resolveFfmpegBinary();
        if ($ffmpeg === '') {
            throw new Exception('服务器未配置 FFmpeg，无法提取上一分镜视频尾帧');
        }

        $workDir = runtime_path() . 'short_drama_tail_frame_' . $tenantId . '_' . $projectId . '_' . time() . '_' . random_int(1000, 9999) . DIRECTORY_SEPARATOR;
        if (!is_dir($workDir)) {
            @mkdir($workDir, 0775, true);
        }
        if (!is_dir($workDir) || !is_writable($workDir)) {
            throw new Exception('上一分镜视频尾帧提取失败，请改用本地上传或上一分镜');
        }

        try {
            $sourcePath = self::localPublicFilePath((string)($sourceRow['uri'] ?? ''));
            if ($sourcePath === '') {
                $sourcePath = self::downloadVideoForFfmpeg($sourceRow, $workDir, 1);
            }
            $framePath = $workDir . 'last_frame.jpg';
            self::extractLastFrameWithFfmpeg($ffmpeg, $sourcePath, $framePath);
            $imageSize = @getimagesize($framePath) ?: [];
            $stored = self::storeInternalAssetFile($tenantId, $framePath, 'uploads/aigc_short_drama/frames/' . date('Ymd'));
            $time = time();
            $meta = [
                'role' => 'previous_video_tail',
                'source' => 'pc_storyboard_step',
                'source_asset_id' => $assetId,
                'source_shot_id' => (string)($sourceRow['shot_id'] ?? ''),
                'target_shot_id' => $targetShotId,
                'project_task_id' => $taskId,
            ];
            $asset = AigcShortDramaAsset::create([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'project_id' => $projectId,
                'task_id' => $taskId,
                'shot_id' => $targetShotId,
                'asset_type' => 'reference_image',
                'title' => '上一分镜视频尾帧',
                'uri' => $stored['uri'],
                'cover_uri' => '',
                'storage_scope' => (string)($stored['storage_scope'] ?? 'tenant'),
                'storage_engine' => (string)($stored['storage_engine'] ?? 'local'),
                'storage_domain' => (string)($stored['storage_domain'] ?? ''),
                'mime_type' => (string)($stored['mime_type'] ?? 'image/jpeg'),
                'file_size' => (int)($stored['file_size'] ?? 0),
                'width' => (int)($imageSize[0] ?? 0),
                'height' => (int)($imageSize[1] ?? 0),
                'duration' => 0,
                'checksum' => is_file($framePath) ? (hash_file('sha256', $framePath) ?: '') : '',
                'meta_json' => self::jsonEncode($meta),
                'status' => 'ready',
                'create_time' => $time,
                'update_time' => $time,
                'delete_time' => 0,
            ]);
            self::touchProject($project, ['update_time' => $time]);
            return self::formatAsset($asset->toArray());
        } catch (Exception $e) {
            Log::write('AI short drama previous video tail frame failed: ' . $e->getMessage(), 'error');
            throw new Exception('上一分镜视频尾帧提取失败，请改用本地上传或上一分镜');
        } finally {
            self::removeRuntimeDirectory($workDir);
        }
    }

    public static function selectStoryboardAsset(int $tenantId, int $userId, array $params): array
    {
        $projectId = (int)($params['project_id'] ?? 0);
        $taskId = trim((string)($params['task_id'] ?? ''));
        $shotId = trim((string)($params['shot_id'] ?? ''));
        $assetId = (int)($params['asset_id'] ?? 0);
        $assetType = self::normalizeAssetType((string)($params['asset_type'] ?? ''));
        if ($projectId <= 0 || $taskId === '' || $shotId === '' || $assetId <= 0) {
            throw new Exception('请选择要确认的分镜素材');
        }
        if (!in_array($assetType, ['shot_image', 'shot_video'], true)) {
            throw new Exception('只能确认分镜图片或视');
        }

        $project = self::findProject($tenantId, $userId, $projectId);
        $shot = AigcShortDramaStoryboard::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'project_id' => $projectId,
            'task_id' => $taskId,
            'shot_id' => $shotId,
            'delete_time' => 0,
        ])->findOrEmpty();
        if ($shot->isEmpty()) {
            throw new Exception('分镜不存在');
        }

        $asset = AigcShortDramaAsset::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'project_id' => $projectId,
            'shot_id' => $shotId,
            'id' => $assetId,
            'asset_type' => $assetType,
            'status' => 'ready',
            'delete_time' => 0,
        ])->findOrEmpty();
        if ($asset->isEmpty()) {
            throw new Exception('素材不存在或还未生成完成');
        }

        $field = $assetType === 'shot_video' ? 'selected_video_asset_id' : 'selected_image_asset_id';
        $time = time();
        $shot->save([
            $field => $assetId,
            'update_time' => $time,
        ]);
        self::touchProject($project, ['update_time' => $time]);

        $shotData = self::formatShot(array_merge($shot->toArray(), [$field => $assetId]));
        $shotData[$assetType === 'shot_video' ? 'selected_video_asset' : 'selected_image_asset'] = self::formatAsset($asset->toArray());
        return [
            'shot' => $shotData,
            'asset' => self::formatAsset($asset->toArray()),
        ];
    }
    private static function storageInfoForUploadedFile(int $tenantId, string $uri): array
    {
        $uri = ltrim(trim($uri), '/');
        if ($uri === '') {
            return [];
        }
        foreach (['tenant_file', 'file'] as $table) {
            try {
                $query = Db::name($table)->where('uri', $uri);
                if ($table === 'tenant_file') {
                    $query->where('tenant_id', $tenantId);
                }
                $row = $query->where(function ($query) {
                    $query->whereNull('delete_time')->whereOr('delete_time', 0);
                })->order('id', 'desc')->find();
            } catch (\Throwable) {
                $row = null;
            }
            if (!$row) {
                continue;
            }
            return [
                'storage_scope' => (string)($row['storage_scope'] ?? ''),
                'storage_engine' => (string)($row['storage_engine'] ?? ''),
                'storage_domain' => (string)($row['storage_domain'] ?? ''),
            ];
        }
        return [];
    }

    private static function existingExtractedVideoTailFrame(int $tenantId, int $userId, int $projectId, string $taskId, string $targetShotId, int $sourceAssetId): array
    {
        $rows = AigcShortDramaAsset::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'project_id' => $projectId,
            'task_id' => $taskId,
            'shot_id' => $targetShotId,
            'asset_type' => 'reference_image',
            'status' => 'ready',
            'delete_time' => 0,
        ])->order(['id' => 'desc'])->limit(20)->select()->toArray();
        foreach ($rows as $row) {
            $meta = self::jsonDecode((string)($row['meta_json'] ?? ''));
            if (
                (string)($meta['role'] ?? '') === 'previous_video_tail'
                && (int)($meta['source_asset_id'] ?? 0) === $sourceAssetId
            ) {
                return $row;
            }
        }
        return [];
    }

    private static function extractLastFrameWithFfmpeg(string $ffmpeg, string $sourcePath, string $framePath): void
    {
        $ffmpegCmd = $ffmpeg === 'ffmpeg' ? 'ffmpeg' : escapeshellarg($ffmpeg);
        $commands = [
            $ffmpegCmd . ' -y -sseof -0.1 -i ' . escapeshellarg($sourcePath) . ' -frames:v 1 -update 1 ' . escapeshellarg($framePath) . ' 2>&1',
            $ffmpegCmd . ' -y -sseof -1 -i ' . escapeshellarg($sourcePath) . ' -frames:v 1 -update 1 ' . escapeshellarg($framePath) . ' 2>&1',
        ];
        $lastOutput = [];
        foreach ($commands as $cmd) {
            $output = [];
            @\exec($cmd, $output, $code);
            if ($code === 0 && is_file($framePath) && filesize($framePath) > 0) {
                return;
            }
            $lastOutput = $output;
        }
        Log::write('AI short drama FFmpeg tail frame output: ' . implode("\n", (array)$lastOutput), 'error');
        throw new Exception('FFmpeg tail frame extraction failed');
    }

    private static function storeInternalAssetFile(int $tenantId, string $filePath, string $saveDir): array
    {
        if ($filePath === '' || !is_file($filePath)) {
            throw new Exception('Internal asset file missing');
        }
        $config = StorageConfigService::getEffectiveConfig($tenantId);
        $driver = new StorageDriver($config);
        $driver->setUploadFileByReal($filePath);
        $saveDir = trim($saveDir, '/');
        if (!$driver->upload($saveDir)) {
            throw new Exception((string)$driver->getError());
        }
        $fileInfo = (array)$driver->getFileInfo();
        $fileSize = (int)($fileInfo['size'] ?? 0);
        if ($fileSize <= 0) {
            $fileSize = (int)(filesize($filePath) ?: 0);
        }
        return [
            'uri' => $saveDir . '/' . $driver->getFileName(),
            'storage_scope' => (string)($config['scope'] ?? 'tenant'),
            'storage_engine' => (string)($config['default'] ?? 'local'),
            'storage_domain' => StorageConfigService::getEffectiveDomain($tenantId),
            'mime_type' => (string)($fileInfo['mime'] ?? 'image/jpeg'),
            'file_size' => $fileSize,
        ];
    }

    private static function removeRuntimeDirectory(string $dir): void
    {
        if ($dir === '' || !is_dir($dir) || !str_starts_with(str_replace('\\', '/', $dir), str_replace('\\', '/', runtime_path()))) {
            return;
        }
        $items = @scandir($dir);
        if (!is_array($items)) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                self::removeRuntimeDirectory($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    public static function assetLists(int $tenantId, int $userId, array $params = []): array
    {
        $projectId = (int)($params['project_id'] ?? 0);
        $sourceTaskId = trim((string)($params['source_task_id'] ?? $params['script_task_id'] ?? $params['task_id'] ?? ''));
        $assetType = (string)($params['asset_type'] ?? '');
        if ($projectId > 0) {
            self::findProject($tenantId, $userId, $projectId);
            return self::sanitizeUtf8Payload([
                'lists' => self::projectAssets($tenantId, $userId, $projectId, $assetType, $sourceTaskId),
            ]);
        }

        $pageSize = min(200, max(1, (int)($params['page_size'] ?? 100)));
        $query = AigcShortDramaAsset::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'delete_time' => 0,
        ]);
        if ($assetType !== '') {
            $query->where('asset_type', self::normalizeAssetType($assetType));
        } else {
            $query->whereIn('asset_type', [
                'subject_image',
                'three_view',
                'scene_image',
                'shot_image',
                'shot_video',
                'bgm_audio',
                'final_video',
                'export_package',
                'reference_image',
            ]);
        }
        if ($sourceTaskId !== '') {
            $query->where('task_id', $sourceTaskId);
        }
        $rows = $query->where('status', 'ready')->order(['id' => 'desc'])->limit($pageSize)->select()->toArray();
        return self::sanitizeUtf8Payload([
            'lists' => array_map([self::class, 'formatAsset'], $rows),
        ]);
    }

    public static function deleteAsset(int $tenantId, int $userId, array $params): void
    {
        $assetId = (int)($params['id'] ?? $params['asset_id'] ?? 0);
        if ($assetId <= 0) {
            throw new Exception('请选择要删除的资产');
        }
        $asset = AigcShortDramaAsset::where([
            'id' => $assetId,
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'delete_time' => 0,
        ])->findOrEmpty();
        if ($asset->isEmpty()) {
            throw new Exception('资产不存在或已删除');
        }

        $row = $asset->toArray();
        $time = time();
        AigcShortDramaAsset::where([
            'id' => $assetId,
            'tenant_id' => $tenantId,
            'user_id' => $userId,
        ])->update([
            'delete_time' => $time,
            'update_time' => $time,
        ]);

        $assetType = (string)($row['asset_type'] ?? '');
        if (in_array($assetType, ['shot_image', 'shot_video'], true)) {
            $field = $assetType === 'shot_video' ? 'selected_video_asset_id' : 'selected_image_asset_id';
            AigcShortDramaStoryboard::where([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'project_id' => (int)($row['project_id'] ?? 0),
                $field => $assetId,
                'delete_time' => 0,
            ])->update([
                $field => 0,
                'update_time' => $time,
            ]);
        }

        if ((int)($row['project_id'] ?? 0) > 0) {
            self::refreshProjectGenerationStatus($tenantId, $userId, (int)$row['project_id']);
        }
    }

    public static function estimateShotGenerationTask(int $tenantId, int $userId, array $params): array
    {
        $projectId = (int)($params['project_id'] ?? 0);
        $taskId = trim((string)($params['task_id'] ?? ''));
        $shotId = trim((string)($params['shot_id'] ?? ''));
        $taskType = self::normalizeGenerationTaskType((string)($params['task_type'] ?? $params['type'] ?? 'shot_image'));
        $project = self::findProject($tenantId, $userId, $projectId);
        if ($taskId === '') {
            $taskId = (string)($project['last_task_id'] ?? '');
        }
        $shot = in_array($taskType, ['export_video', 'export_package', 'subject_image', 'scene_image', 'three_view', 'bgm_audio'], true) && $shotId === ''
            ? null
            : self::findShot($tenantId, $userId, $projectId, $taskId, $shotId);
        if ($taskType === 'shot_video' || self::normalizeGenerationMode($params) === 'video_generate') {
            $params = self::sanitizeVideoGenerationParams($params);
            $params = self::prepareMarketShortDramaVideoParams($tenantId, $params, $shot ? $shot->toArray() : []);
            return self::estimateMarketVideoGenerationBilling($tenantId, $params);
        }
        if ($taskType === 'bgm_audio') {
            return self::estimateMarketBgmAudioGenerationBilling($tenantId, $params, self::currentProjectPlanRaw($tenantId, $userId, $projectId));
        }
        $config = self::publicConfig($tenantId);
        $billing = self::estimateGenerationBilling($taskType, $shot ? $shot->toArray() : [], $config, $params);
        if ($taskType === 'shot_video' || self::normalizeGenerationMode($params) === 'video_generate') {
            $billing['effective_video_params'] = self::shortDramaVideoEffectiveParams($params);
        }
        return $billing;
    }

    public static function createShotGenerationTask(int $tenantId, int $userId, array $params): array
    {
        $projectId = (int)($params['project_id'] ?? 0);
        $taskId = trim((string)($params['task_id'] ?? ''));
        $shotId = trim((string)($params['shot_id'] ?? ''));
        $taskType = self::normalizeGenerationTaskType((string)($params['task_type'] ?? $params['type'] ?? 'shot_image'));
        $project = self::findProject($tenantId, $userId, $projectId);
        $projectRatio = self::normalizeGenerationRatio((string)($project['ratio'] ?? ''));
        $requestRatio = self::requestGenerationRatio($params);
        $nestedParams = is_array($params['params'] ?? null) ? (array)$params['params'] : [];
        $requestSource = (string)($params['source'] ?? $nestedParams['source'] ?? '');
        $allowProjectRatioUpdate = in_array($requestSource, ['script_plan'], true);
        if ($requestRatio !== '' && ($projectRatio === '' || $allowProjectRatioUpdate)) {
            $projectRatio = $requestRatio;
            AigcShortDramaProject::where([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'id' => $projectId,
            ])->update([
                'ratio' => $projectRatio,
                'update_time' => time(),
            ]);
        }
        if ($projectRatio !== '') {
            $params['ratio'] = $projectRatio;
            $params['aspect_ratio'] = $projectRatio;
            if (is_array($params['params'] ?? null)) {
                $params['params']['ratio'] = $projectRatio;
                $params['params']['aspect_ratio'] = $projectRatio;
            }
        }
        if (in_array($taskType, ['subject_image', 'scene_image', 'three_view', 'shot_image'], true)) {
            $params = self::normalizeShortDramaImageChannelParams($tenantId, $params);
        }
        if ($taskType === 'three_view') {
            $sourceImage = self::firstReferenceImageFromParams($params);
            if ($sourceImage === '') {
                throw new Exception('请先生成或上传主体图');
            }
            $params['reference_image'] = $sourceImage;
            $params['reference_images'] = array_values(array_filter(array_unique(array_merge(
                (array)($params['reference_images'] ?? []),
                [$sourceImage]
            ))));
            if (is_array($params['params'] ?? null)) {
                $params['params']['reference_image'] = $sourceImage;
                $params['params']['reference_images'] = $params['reference_images'];
            }
            $params = self::appendThreeViewReferenceAssetIds($tenantId, $userId, $projectId, $params, $sourceImage);
        }
        if ($shotId === '' && !in_array($taskType, ['export_video', 'export_package', 'subject_image', 'scene_image', 'three_view', 'bgm_audio'], true)) {
            throw new Exception('请选择分镜');
        }
        if ($taskId === '') {
            $taskId = (string)($project['last_task_id'] ?? '');
        }
        $shot = in_array($taskType, ['export_video', 'export_package', 'subject_image', 'scene_image', 'three_view', 'bgm_audio'], true) && $shotId === ''
            ? null
            : self::findShot($tenantId, $userId, $projectId, $taskId, $shotId);
        $shotPayload = $shot ? $shot->toArray() : [];
        if ($taskType === 'shot_video' || self::normalizeGenerationMode($params) === 'video_generate') {
            $params = self::sanitizeVideoGenerationParams($params);
            $params = self::prepareMarketShortDramaVideoParams($tenantId, $params, $shotPayload);
        }
        $config = self::publicConfig($tenantId);
        $billing = $taskType === 'bgm_audio'
            ? self::estimateMarketBgmAudioGenerationBilling($tenantId, $params, self::currentProjectPlanRaw($tenantId, $userId, $projectId))
            : ($taskType === 'shot_video'
                ? self::estimateMarketVideoGenerationBilling($tenantId, $params)
                : (self::isImageGenerationTask($taskType)
                ? self::estimateImageGenerationBilling($tenantId, $taskType, $shotPayload, $config, $params)
                : self::estimateGenerationBilling($taskType, $shotPayload, $config, $params)));
        $localTaskId = self::makeTaskId('sd_gen');
        $time = time();
        Db::startTrans();
        try {
            $generation = self::createGenerationTaskRecord($tenantId, $userId, $projectId, $shotId, $taskType, $localTaskId, self::STATUS_PENDING, [
                'source_task_id' => $taskId,
                'source_app_code' => $taskType === 'shot_video' ? 'power_market_video' : ($taskType === 'bgm_audio' ? 'power_market_music_api' : (in_array($taskType, ['export_video', 'export_package'], true) ? self::APP_CODE : (self::isNanoBananaImageSelection($params) ? 'power_market_nano_banana_api' : (self::isMarketImageSelection($params) ? 'power_market_image' : self::IMAGE_APP_CODE)))),
                'provider' => 'pending',
                'request' => [
                    'shot' => $shot ? self::formatShot($shot->toArray()) : [],
                    'params' => $params,
                ],
                'pricing' => $billing,
                'billing_status' => ((float)$billing['tenant_cost_points'] > 0 || (float)$billing['user_charge_points'] > 0) ? 'reserved' : 'none',
                'tenant_cost_points' => $billing['tenant_cost_points'],
                'user_charge_points' => $billing['user_charge_points'],
            ]);
            if ($taskType !== 'bgm_audio' && $taskType !== 'shot_video' && !self::isMarketImageSelection($params) && ((float)$billing['tenant_cost_points'] > 0 || (float)$billing['user_charge_points'] > 0)) {
                PointService::consumeBusinessAmountsInCurrentTransaction($tenantId, $userId, (float)$billing['tenant_cost_points'], (float)$billing['user_charge_points'], $localTaskId, 'AI short drama generation reserve', [
                    'app_code' => self::APP_CODE,
                    'task_id' => $localTaskId,
                    'project_id' => $projectId,
                    'shot_id' => $shotId,
                    'task_type' => $taskType,
                ]);
            }
            self::updateProjectGenerationSettingsFromTask($project, $taskType, $params);
            self::touchProject($project, [
                'status' => in_array($taskType, ['shot_video', 'export_video', 'export_package'], true) ? self::PROJECT_STATUS_VIDEO_GENERATING : self::PROJECT_STATUS_ASSET_GENERATING,
                'update_time' => $time,
            ]);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e instanceof Exception ? $e : new Exception(self::SAFE_ERROR);
        }
        if ($taskType === 'shot_video') {
            self::runVideoGenerationTask($tenantId, $userId, $generation->toArray(), $shotPayload, $params, $billing);
            $generation = self::findGenerationTask($tenantId, $userId, $localTaskId);
        } elseif ($taskType === 'export_video') {
            self::runExportVideoTask($tenantId, $userId, $generation->toArray(), $params, $billing);
            $generation = self::findGenerationTask($tenantId, $userId, $localTaskId);
        } elseif ($taskType === 'export_package') {
            self::runExportPackageTask($tenantId, $userId, $generation->toArray(), $params, $billing);
            $generation = self::findGenerationTask($tenantId, $userId, $localTaskId);
        } elseif ($taskType === 'bgm_audio') {
            self::runBgmAudioGenerationTask($tenantId, $userId, $generation->toArray(), $params, $billing);
            $generation = self::findGenerationTask($tenantId, $userId, $localTaskId);
        } else {
            self::runImageGenerationTask($tenantId, $userId, $generation->toArray(), $shotPayload, $params, $billing);
            $generation = self::findGenerationTask($tenantId, $userId, $localTaskId);
        }
        return self::formatGenerationTask($generation->toArray(), true);
    }

    public static function streamGenerationTask(int $tenantId, int $userId, array $params, callable $emit): array
    {
        $mode = self::normalizeGenerationMode($params);
        if ($mode !== '') {
            $params['mode'] = $mode;
        }
        $taskType = self::normalizeGenerationTaskType((string)($params['task_type'] ?? $params['type'] ?? 'shot_image'));
        $emit('thinking', [
            'message' => '正在思考...',
            'mode' => $mode,
            'task_type' => $taskType,
        ]);
        foreach (self::generationStreamMessages($taskType, $mode) as $message) {
            $emit('message_delta', ['text' => $message]);
        }

        try {
            $task = self::createShotGenerationTask($tenantId, $userId, $params);
            $emit('task', [
                'message' => self::isTerminalStatus((string)($task['status'] ?? ''))
                    ? '任务已完'
                    : '任务已提交，正在生成',
                'task' => $task,
            ]);
            $assets = self::generationTaskOutputAssets($tenantId, $userId, $task);
            if (!empty($assets)) {
                $emit('asset', [
                    'message' => '结果已生',
                    'assets' => $assets,
                ]);
            }
            $emit('done', [
                'message' => '已进入后台生成流程，请稍候查看结',
                'task' => $task,
            ]);
            return $task;
        } catch (Exception $e) {
            $emit('error', ['message' => self::friendlyGenerationError($e->getMessage())]);
            throw $e;
        }
    }

    public static function generationTaskDetail(int $tenantId, int $userId, string $taskId): array
    {
        $task = self::findGenerationTask($tenantId, $userId, $taskId);
        return self::formatGenerationTask($task->toArray(), true);
    }

    public static function generationTaskLists(int $tenantId, int $userId, array $params = []): array
    {
        $projectId = (int)($params['project_id'] ?? 0);
        $sourceTaskId = trim((string)($params['source_task_id'] ?? $params['script_task_id'] ?? $params['task_id'] ?? ''));
        if ($projectId > 0) {
            self::findProject($tenantId, $userId, $projectId);
        }
        $query = AigcShortDramaGenerationTask::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'delete_time' => 0,
        ])->order(['id' => 'desc']);
        if ($projectId > 0) {
            $query->where('project_id', $projectId);
        }
        if ($sourceTaskId !== '') {
            $query->where('source_task_id', $sourceTaskId);
        }
        $taskType = trim((string)($params['task_type'] ?? $params['task_types'] ?? ''));
        if ($taskType !== '') {
            $types = array_values(array_filter(array_map('trim', explode(',', $taskType))));
            $query->whereIn('task_type', $types);
        }
        $status = trim((string)($params['status'] ?? ''));
        if ($status !== '') {
            $query->where('status', $status);
        }
        $rows = $query->limit(min(100, max(1, (int)($params['page_size'] ?? 100))))->select()->toArray();
        $lists = [];
        foreach ($rows as $row) {
            $lists[] = self::formatGenerationTask($row, true);
        }
        return self::sanitizeUtf8Payload([
            'lists' => $lists,
            'count' => count($lists),
        ]);
    }

    public static function retryGenerationTask(int $tenantId, int $userId, string $taskId): array
    {
        $task = self::findGenerationTask($tenantId, $userId, $taskId);
        if ((string)$task['status'] !== self::STATUS_FAILED) {
            throw new Exception('当前生成任务不可重试');
        }
        $newTaskId = self::makeTaskId('sd_gen_retry');
        $time = time();
        $row = $task->toArray();
        unset($row['id']);
        $isMarketImageTask = self::isMarketImageSelection(self::jsonDecode((string)($row['request_json'] ?? ''))['params'] ?? [])
            || (int)($row['market_sku_id'] ?? 0) > 0
            || (int)($row['consumption_id'] ?? 0) > 0;
        $billing = self::jsonDecode((string)($row['pricing_snapshot'] ?? ''));
        $hasBilling = (float)($billing['tenant_cost_points'] ?? 0) > 0 || (float)($billing['user_charge_points'] ?? 0) > 0;
        if ($hasBilling && !$isMarketImageTask) {
            PointService::assertCanConsumeAmounts($tenantId, $userId, (float)$billing['tenant_cost_points'], (float)$billing['user_charge_points']);
        }
        Db::startTrans();
        try {
            $generation = AigcShortDramaGenerationTask::create(array_merge($row, [
                'task_id' => $newTaskId,
                'parent_task_id' => (string)$row['task_id'],
                'status' => self::STATUS_PENDING,
                'progress' => 0,
                'billing_status' => $isMarketImageTask ? 'none' : ($hasBilling ? 'reserved' : 'none'),
                'app_task_id' => 0,
                'consumption_id' => 0,
                'error_code' => '',
                'error_msg' => '',
                'operator_error' => '',
                'retry_count' => (int)$row['retry_count'] + 1,
                'started_at' => 0,
                'finished_at' => 0,
                'create_time' => $time,
                'update_time' => $time,
                'delete_time' => 0,
            ]));
            if ($hasBilling && !$isMarketImageTask) {
                PointService::consumeBusinessAmountsInCurrentTransaction($tenantId, $userId, (float)$billing['tenant_cost_points'], (float)$billing['user_charge_points'], $newTaskId, 'AI short drama generation retry reserve', [
                    'app_code' => self::APP_CODE,
                    'task_id' => $newTaskId,
                    'project_id' => (int)$row['project_id'],
                    'shot_id' => (string)($row['shot_id'] ?? ''),
                    'task_type' => (string)$row['task_type'],
                ]);
            }
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e instanceof Exception ? $e : new Exception(self::SAFE_ERROR);
        }
        $request = self::jsonDecode((string)($generation['request_json'] ?? ''));
        $shot = (array)($request['shot'] ?? []);
        $params = (array)($request['params'] ?? []);
        if ((string)$generation['task_type'] === 'shot_video') {
            self::runVideoGenerationTask($tenantId, $userId, $generation->toArray(), $shot, $params, $billing);
        } elseif ((string)$generation['task_type'] === 'export_video') {
            self::runExportVideoTask($tenantId, $userId, $generation->toArray(), $params, $billing);
        } elseif ((string)$generation['task_type'] === 'export_package') {
            self::runExportPackageTask($tenantId, $userId, $generation->toArray(), $params, $billing);
        } elseif ((string)$generation['task_type'] === 'bgm_audio') {
            self::runBgmAudioGenerationTask($tenantId, $userId, $generation->toArray(), $params, $billing);
        } else {
            self::runImageGenerationTask($tenantId, $userId, $generation->toArray(), $shot, $params, $billing);
        }
        $generation = self::findGenerationTask($tenantId, $userId, $newTaskId);
        return self::formatGenerationTask($generation->toArray(), true);
    }

    public static function cancelGenerationTask(int $tenantId, int $userId, string $taskId): array
    {
        $task = self::findGenerationTask($tenantId, $userId, $taskId);
        if (in_array((string)$task['status'], [self::STATUS_SUCCESS, self::STATUS_FAILED, self::STATUS_CANCELED], true)) {
            return self::formatGenerationTask($task->toArray(), true);
        }
        $consumptionId = (int)($task['consumption_id'] ?? 0);
        if ($consumptionId > 0) {
            if ((string)$task['task_type'] === 'bgm_audio') {
                MarketMusicAppRuntimeService::cancel($consumptionId);
            } elseif ((string)$task['task_type'] === 'shot_video') {
                $request = self::jsonDecode((string)$task['request_json']);
                self::marketVideoRuntime((array)($request['video_params'] ?? $request['params'] ?? []))::cancel($consumptionId);
            } else {
                self::isNanoBananaImageSelection(self::jsonDecode((string)$task['request_json'])['params'] ?? [])
                    ? MarketNanoBananaAppRuntimeService::cancel($consumptionId)
                    : MarketImageModelRuntimeService::cancel($consumptionId);
            }
            $task->save([
                'status' => self::STATUS_CANCELED,
                'billing_status' => 'refunded',
                'error_code' => 'canceled',
                'error_msg' => 'Task canceled',
                'finished_at' => time(),
                'update_time' => time(),
            ]);
            self::refreshProjectGenerationStatus($tenantId, $userId, (int)$task['project_id']);
            return self::formatGenerationTask($task->toArray(), true);
        }
        $time = time();
        Db::startTrans();
        try {
            $refundStatus = (string)$task['billing_status'];
            if ($refundStatus === 'reserved') {
                PointService::refundBusinessAmountsInCurrentTransaction($tenantId, $userId, (float)$task['tenant_cost_points'], (float)$task['user_charge_points'], $taskId . '-refund', 'AI short drama generation canceled', [
                    'app_code' => self::APP_CODE,
                    'task_id' => $taskId,
                ]);
                $refundStatus = 'refunded';
            }
            $task->save([
                'status' => self::STATUS_CANCELED,
                'billing_status' => $refundStatus,
                'error_msg' => 'Task canceled',
                'finished_at' => $time,
                'update_time' => $time,
            ]);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e instanceof Exception ? $e : new Exception(self::SAFE_ERROR);
        }
        return self::formatGenerationTask($task->toArray(), true);
    }

    public static function submitPublishedWork(int $tenantId, int $userId, array $params): array
    {
        $projectId = (int)($params['project_id'] ?? 0);
        $project = self::findProject($tenantId, $userId, $projectId);
        $title = mb_substr(trim((string)($params['title'] ?? $project['title'] ?? '')), 0, 120, 'UTF-8');
        if ($title === '') {
            throw new Exception('Title is required');
        }
        $videoAssetId = (int)($params['video_asset_id'] ?? 0);
        $localVideoUri = FileService::setFileUrl((string)($params['video_uri'] ?? $params['video_url'] ?? ''));
        if ($videoAssetId <= 0 && $localVideoUri === '') {
            throw new Exception('Video asset is required');
        }
        $time = time();
        $work = AigcShortDramaPublishedWork::create([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'project_id' => $projectId,
            'final_video_asset_id' => $videoAssetId,
            'cover_asset_id' => (int)($params['cover_asset_id'] ?? 0),
            'title' => $title,
            'intro' => mb_substr(trim((string)($params['intro'] ?? '')), 0, 500, 'UTF-8'),
            'script_description' => trim((string)($params['script_description'] ?? '')),
            'social_link' => mb_substr(trim((string)($params['social_link'] ?? '')), 0, 500, 'UTF-8'),
            'cover_uri' => FileService::setFileUrl((string)($params['cover_uri'] ?? $params['cover_url'] ?? '')),
            'video_uri' => $localVideoUri,
            'activity_tags_json' => self::jsonEncode(array_values(array_filter((array)($params['activity_tags'] ?? [])))),
            'audit_status' => 'reviewing',
            'audit_reason' => '',
            'status' => 0,
            'submitted_at' => $time,
            'audited_at' => 0,
            'create_time' => $time,
            'update_time' => $time,
            'delete_time' => 0,
        ]);
        self::touchProject($project, [
            'status' => self::PROJECT_STATUS_PUBLISH_REVIEWING,
            'publish_id' => (int)$work['id'],
            'final_video_asset_id' => $videoAssetId,
            'update_time' => $time,
        ]);
        return self::formatPublishedWork($work->toArray());
    }

    public static function publishedWorkDetail(int $tenantId, int $userId, int $id): array
    {
        $work = AigcShortDramaPublishedWork::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'id' => $id,
            'delete_time' => 0,
        ])->findOrEmpty();
        if ($work->isEmpty()) {
            throw new Exception('发布作品不存在');
        }
        return self::formatPublishedWork($work->toArray());
    }

    private static function createAgentRunRecord(int $tenantId, int $userId, int $projectId, string $agentRunId, string $taskId, string $runType, array $request, array $result, array $model, string $status, int $time): void
    {
        $data = [
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'project_id' => $projectId,
            'agent_run_id' => $agentRunId,
            'task_id' => $taskId,
            'run_type' => $runType,
            'status' => $status,
            'input_summary' => mb_substr((string)($request['prompt'] ?? ''), 0, 500, 'UTF-8'),
            'request_json' => self::jsonEncode($request),
            'output_summary' => mb_substr((string)($result['story_outline'] ?? ''), 0, 500, 'UTF-8'),
            'output_version_id' => 0,
            'model_json' => self::jsonEncode($model),
            'error_code' => '',
            'error_msg' => '',
            'started_at' => $time,
            'finished_at' => $time,
            'update_time' => $time,
            'delete_time' => 0,
        ];
        $row = AigcShortDramaAgentRun::where([
            'tenant_id' => $tenantId,
            'agent_run_id' => $agentRunId,
            'delete_time' => 0,
        ])->findOrEmpty();
        if ($row->isEmpty()) {
            AigcShortDramaAgentRun::create($data + ['create_time' => $time]);
        } else {
            $row->save($data);
            AigcShortDramaAgentStepLog::where([
                'tenant_id' => $tenantId,
                'agent_run_id' => $agentRunId,
                'delete_time' => 0,
            ])->update([
                'delete_time' => $time,
                'update_time' => $time,
            ]);
        }
        $reviewReport = self::normalizeReviewReport((array)($result['review_report'] ?? []));
        $storyboardDiagnostics = self::storyboardBreakingDiagnostics(
            (array)($result['storyboard'] ?? []),
            (array)($result['locations'] ?? $result['scenes'] ?? []),
            $request,
            (string)($request['prompt'] ?? ''),
            (array)($result['storyboard_breaking_diagnostics'] ?? $reviewReport['storyboard_breaking_diagnostics'] ?? [])
        );
        foreach (self::workflowSteps($status, $reviewReport) as $index => $step) {
            $stepKey = (string)($step['key'] ?? ('step_' . ($index + 1)));
            $output = [];
            if ($stepKey === 'plan_reviewer') {
                $output = [
                    'issue_count' => (int)($reviewReport['issue_count'] ?? 0),
                    'blocking_count' => (int)($reviewReport['blocking_count'] ?? 0),
                    'code_repair_count' => (int)($reviewReport['code_repair_count'] ?? 0),
                    'llm_repair_used' => (bool)($reviewReport['llm_repair_used'] ?? false),
                ];
            } elseif ($stepKey === 'storyboard_preparer' || $stepKey === 'storyboard_breaker') {
                $output = [
                    'storyboard_count' => count((array)($result['storyboard'] ?? [])),
                    'matched_rule_code' => (string)($storyboardDiagnostics['matched_rule_code'] ?? ''),
                    'matched_rule_label' => (string)($storyboardDiagnostics['matched_rule_label'] ?? ''),
                    'target_min_shots' => (int)($storyboardDiagnostics['target_min_shots'] ?? 0),
                    'target_max_shots' => (int)($storyboardDiagnostics['target_max_shots'] ?? 0),
                    'actual_shot_count' => (int)($storyboardDiagnostics['actual_shot_count'] ?? count((array)($result['storyboard'] ?? []))),
                    'intensity_level' => (string)($storyboardDiagnostics['intensity_level'] ?? ''),
                    'timeline_override' => (bool)($storyboardDiagnostics['timeline_override'] ?? false),
                ];
            }
            AigcShortDramaAgentStepLog::create([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'project_id' => $projectId,
                'agent_run_id' => $agentRunId,
                'step_key' => $stepKey,
                'step_name' => (string)($step['title'] ?? ''),
                'status' => (string)($step['status'] ?? $status),
                'input_json' => self::jsonEncode($index === 0 ? $request : []),
                'output_json' => self::jsonEncode($output),
                'error_msg' => '',
                'started_at' => $time,
                'finished_at' => $time,
                'sort' => $index + 1,
                'create_time' => $time,
                'update_time' => $time,
                'delete_time' => 0,
            ]);
        }
    }

    private static function resolveScriptPlanAgentRunId(int $tenantId, array $project, string $taskId): string
    {
        $agentRunId = trim((string)($project['current_agent_run_id'] ?? ''));
        if ($agentRunId === '') {
            return self::makeTaskId('sd_agent');
        }
        $row = AigcShortDramaAgentRun::where([
            'tenant_id' => $tenantId,
            'agent_run_id' => $agentRunId,
            'delete_time' => 0,
        ])->findOrEmpty();
        if ($row->isEmpty()) {
            return $agentRunId;
        }
        $rowTaskId = (string)($row['task_id'] ?? '');
        return $rowTaskId === '' || $rowTaskId === $taskId ? $agentRunId : self::makeTaskId('sd_agent');
    }

    private static function createPlanVersion(int $tenantId, int $userId, int $projectId, string $taskId, string $agentRunId, string $versionType, array $result, int $parentVersionId = 0, bool $current = true, int $time = 0): AigcShortDramaPlanVersion
    {
        $time = $time ?: time();
        $existing = AigcShortDramaPlanVersion::where([
            'tenant_id' => $tenantId,
            'project_id' => $projectId,
            'task_id' => $taskId,
            'agent_run_id' => $agentRunId,
            'version_type' => $versionType,
            'delete_time' => 0,
        ])->findOrEmpty();
        if (!$existing->isEmpty()) {
            if ($current) {
                AigcShortDramaPlanVersion::where([
                    'tenant_id' => $tenantId,
                    'project_id' => $projectId,
                    'delete_time' => 0,
                ])->update(['is_current' => 0, 'update_time' => $time]);
            }
            $existing->save([
                'parent_version_id' => $parentVersionId,
                'title' => (string)($result['title'] ?? ''),
                'story_bible_json' => self::jsonEncode(self::storyBibleFromResult($result)),
                'continuity_json' => self::jsonEncode(self::continuityFromResult($result)),
                'plan_json' => self::jsonEncode($result),
                'storyboard_json' => self::jsonEncode((array)($result['storyboard'] ?? [])),
                'is_current' => $current ? 1 : 0,
                'status' => 'ready',
                'update_time' => $time,
            ]);
            AigcShortDramaAgentRun::where([
                'tenant_id' => $tenantId,
                'project_id' => $projectId,
                'agent_run_id' => $agentRunId,
            ])->update([
                'output_version_id' => (int)$existing['id'],
                'update_time' => $time,
            ]);
            return $existing;
        }
        $versionNo = (int)AigcShortDramaPlanVersion::where([
            'tenant_id' => $tenantId,
            'project_id' => $projectId,
            'delete_time' => 0,
        ])->max('version_no') + 1;
        if ($current) {
            AigcShortDramaPlanVersion::where([
                'tenant_id' => $tenantId,
                'project_id' => $projectId,
                'delete_time' => 0,
            ])->update(['is_current' => 0, 'update_time' => $time]);
        }
        $version = AigcShortDramaPlanVersion::create([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'project_id' => $projectId,
            'task_id' => $taskId,
            'agent_run_id' => $agentRunId,
            'parent_version_id' => $parentVersionId,
            'version_no' => $versionNo,
            'version_type' => $versionType,
            'title' => (string)($result['title'] ?? ''),
            'story_bible_json' => self::jsonEncode(self::storyBibleFromResult($result)),
            'continuity_json' => self::jsonEncode(self::continuityFromResult($result)),
            'plan_json' => self::jsonEncode($result),
            'storyboard_json' => self::jsonEncode((array)($result['storyboard'] ?? [])),
            'is_current' => $current ? 1 : 0,
            'status' => 'ready',
            'create_time' => $time,
            'update_time' => $time,
            'delete_time' => 0,
        ]);
        AigcShortDramaAgentRun::where([
            'tenant_id' => $tenantId,
            'project_id' => $projectId,
            'agent_run_id' => $agentRunId,
        ])->update([
            'output_version_id' => (int)$version['id'],
            'update_time' => $time,
        ]);
        return $version;
    }

    private static function createUserEditVersionFromTask(int $tenantId, int $userId, AigcShortDramaScriptTask $task, array $updated): void
    {
        $result = self::jsonDecode((string)$task['result_json']);
        if (!$result) {
            return;
        }
        $project = self::findProject($tenantId, $userId, (int)$task['project_id']);
        $time = time();
        $agentRunId = 'user_edit_' . str_replace('.', '', (string)microtime(true)) . '_' . (int)$task['project_id'] . '_' . random_int(1000, 9999);
        $version = self::createPlanVersion($tenantId, $userId, (int)$task['project_id'], (string)$task['task_id'], $agentRunId, 'user_edit', $result, (int)($project['current_version_id'] ?? 0), true, $time);
        AigcShortDramaProject::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'id' => (int)$task['project_id'],
        ])->update([
            'current_version_id' => (int)$version['id'],
            'update_time' => $time,
        ]);
    }

    private static function createGenerationTaskRecord(int $tenantId, int $userId, int $projectId, string $shotId, string $taskType, string $taskId, string $status, array $payload): AigcShortDramaGenerationTask
    {
        $payload = self::sanitizeUtf8Payload($payload);
        $time = time();
        $data = [
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'project_id' => $projectId,
            'shot_id' => $shotId,
            'task_id' => $taskId,
            'parent_task_id' => (string)($payload['parent_task_id'] ?? ''),
            'source_task_id' => (string)($payload['source_task_id'] ?? ''),
            'source_app_code' => (string)($payload['source_app_code'] ?? ''),
            'task_type' => $taskType,
            'status' => $status,
            'progress' => $status === self::STATUS_SUCCESS ? 100 : 0,
            'provider' => (string)($payload['provider'] ?? 'pending'),
            'provider_task_id' => (string)($payload['provider_task_id'] ?? ''),
            'provider_request_id' => (string)($payload['provider_request_id'] ?? ''),
            'model_json' => self::jsonEncode(is_array($payload['model_snapshot'] ?? null) ? $payload['model_snapshot'] : []),
            'request_json' => self::jsonEncode(is_array($payload['request'] ?? null) ? $payload['request'] : []),
            'result_json' => self::jsonEncode(is_array($payload['result'] ?? null) ? $payload['result'] : []),
            'input_asset_ids' => self::jsonEncode(is_array($payload['input_asset_ids'] ?? null) ? $payload['input_asset_ids'] : []),
            'output_asset_ids' => self::jsonEncode(is_array($payload['output_asset_ids'] ?? null) ? $payload['output_asset_ids'] : []),
            'pricing_snapshot' => self::jsonEncode(is_array($payload['pricing'] ?? null) ? $payload['pricing'] : []),
            'billing_status' => (string)($payload['billing_status'] ?? 'none'),
            'tenant_cost_points' => (float)($payload['tenant_cost_points'] ?? 0),
            'user_charge_points' => (float)($payload['user_charge_points'] ?? 0),
            'idempotency_key' => sha1($tenantId . '|' . $userId . '|' . $taskId),
            'retry_count' => (int)($payload['retry_count'] ?? 0),
            'error_code' => '',
            'error_msg' => '',
            'operator_error' => '',
            'safety_status' => 'pending',
            'started_at' => (int)($payload['started_at'] ?? 0),
            'finished_at' => (int)($payload['finished_at'] ?? 0),
            'update_time' => $time,
            'delete_time' => 0,
        ];
        $row = AigcShortDramaGenerationTask::where([
            'tenant_id' => $tenantId,
            'task_id' => $taskId,
            'delete_time' => 0,
        ])->findOrEmpty();
        if ($row->isEmpty()) {
            return AigcShortDramaGenerationTask::create($data + ['create_time' => $time]);
        }
        $row->save($data);
        return AigcShortDramaGenerationTask::where([
            'tenant_id' => $tenantId,
            'task_id' => $taskId,
            'delete_time' => 0,
        ])->findOrEmpty();
    }

    private static function runImageGenerationTask(int $tenantId, int $userId, array $generation, array $shot, array $params, array $billing): void
    {
        $taskId = (string)$generation['task_id'];
        $projectId = (int)$generation['project_id'];
        $time = time();
        AigcShortDramaGenerationTask::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'task_id' => $taskId,
        ])->update([
            'status' => self::STATUS_RUNNING,
            'progress' => 15,
            'started_at' => $time,
            'update_time' => $time,
        ]);

        try {
            $plan = self::currentProjectPlanRaw($tenantId, $userId, $projectId);
            $projectRatio = self::projectGenerationRatio($tenantId, $userId, $projectId, $plan);
            if ($projectRatio !== '') {
                $params['ratio'] = $projectRatio;
                $params['aspect_ratio'] = $projectRatio;
                if (is_array($params['params'] ?? null)) {
                    $params['params']['ratio'] = $projectRatio;
                    $params['params']['aspect_ratio'] = $projectRatio;
                }
            }
            $taskType = (string)($generation['task_type'] ?? 'shot_image');
            $imageParams = self::shortDramaImageParams($shot, $params, $taskType, $plan);
            if ($taskType === 'shot_image') {
                $shotReferenceContext = self::mergeShotReferenceContext($shot, $params);
                $references = self::shotReferenceAssets($tenantId, $userId, $projectId, $shotReferenceContext, $plan);
                $explicitReferences = self::generationInputReferenceAssets($tenantId, $userId, $projectId, $params, $shot);
                $references = self::mergeReferencePayloads($references, $explicitReferences);
                if (self::isNoSubjectShot($shotReferenceContext)) {
                    $references = self::filterNoSubjectReferencePayload($references);
                }
                $references = self::limitShortDramaImageReferences($tenantId, $imageParams, $params, $shot, $references);
                $imageParams['reference_assets'] = $references['reference_assets'];
                $imageParams['reference_images'] = $references['reference_images'];
                $imageParams['input_asset_ids'] = $references['input_asset_ids'];
                if (!empty($references['input_asset_ids'])) {
                    AigcShortDramaGenerationTask::where([
                        'tenant_id' => $tenantId,
                        'user_id' => $userId,
                        'task_id' => $taskId,
                    ])->update([
                        'input_asset_ids' => self::jsonEncode($references['input_asset_ids']),
                        'update_time' => time(),
                    ]);
                }
            } else {
                $references = self::generationInputReferenceAssets($tenantId, $userId, $projectId, $params, $shot);
                if ($taskType === 'scene_image') {
                    $references = self::emptyReferencePayload();
                }
                $references = self::limitShortDramaImageReferences($tenantId, $imageParams, $params, $shot, $references);
                $imageParams['reference_assets'] = $references['reference_assets'];
                $imageParams['reference_images'] = $references['reference_images'];
                $imageParams['input_asset_ids'] = $references['input_asset_ids'];
                if (!empty($references['input_asset_ids'])) {
                    AigcShortDramaGenerationTask::where([
                        'tenant_id' => $tenantId,
                        'user_id' => $userId,
                        'task_id' => $taskId,
                    ])->update([
                        'input_asset_ids' => self::jsonEncode($references['input_asset_ids']),
                        'update_time' => time(),
                    ]);
                }
            }
            AigcShortDramaGenerationTask::where([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'task_id' => $taskId,
            ])->update([
                'request_json' => self::jsonEncode([
                    'shot' => self::formatShot($shot),
                    'params' => $params,
                    'image_params' => $imageParams,
                ]),
                'input_asset_ids' => self::jsonEncode((array)($imageParams['input_asset_ids'] ?? [])),
                'update_time' => time(),
            ]);
            $imageParams = self::sanitizeUtf8Payload($imageParams);
            if (self::isNanoBananaImageSelection($params)) {
                self::runMarketNanoBananaGenerationTask($tenantId, $userId, $generation, $params, $imageParams, $billing);
                return;
            }
            if (self::isMarketImageSelection($params)) {
                self::runMarketImageGenerationTask($tenantId, $userId, $generation, $params, $imageParams, $billing);
                return;
            }
            $imageResult = AigcImageService::generateWithBillingOverride($tenantId, $userId, $imageParams, [
                'tenant_cost_points' => 0,
                'user_charge_points' => 0,
            ]);
            $results = (array)($imageResult['results'] ?? []);
            if (($imageResult['status'] ?? '') === self::STATUS_FAILED) {
                throw new Exception((string)($imageResult['error'] ?? '生图失败'));
            }
            if (empty($results)) {
                AigcShortDramaGenerationTask::where([
                    'tenant_id' => $tenantId,
                    'user_id' => $userId,
                    'task_id' => $taskId,
                ])->update([
                    'status' => self::STATUS_RUNNING,
                    'progress' => 35,
                    'provider' => (string)($imageParams['channel'] ?? ''),
                    'provider_task_id' => (string)($imageResult['task_id'] ?? ''),
                    'result_json' => self::jsonEncode([
                        'image_task_id' => (int)($imageResult['task_id'] ?? 0),
                        'message' => '生图任务已提交，等待服务商返回结',
                    ]),
                    'update_time' => time(),
                ]);
                return;
            }

            $assetIds = [];
            foreach ($results as $index => $result) {
                if (!is_array($result)) {
                    continue;
                }
                $uri = (string)($result['image_uri'] ?? '');
                if ($uri === '') {
                    continue;
                }
                $assetType = self::generationAssetType((string)($generation['task_type'] ?? 'shot_image'));
                $result = self::normalizeShortDramaImageResultRatio($tenantId, $userId, $result, $assetType, (string)($imageParams['ratio'] ?? ''));
                $uri = (string)($result['image_uri'] ?? $uri);
                $asset = AigcShortDramaAsset::create([
                    'tenant_id' => $tenantId,
                    'user_id' => $userId,
                    'project_id' => $projectId,
                    'task_id' => $taskId,
                    'shot_id' => (string)($generation['shot_id'] ?? ''),
                    'asset_type' => $assetType,
                    'title' => '短剧图片' . ((int)$index + 1),
                    'uri' => $uri,
                    'cover_uri' => '',
                    'storage_scope' => (string)($result['storage_scope'] ?? 'tenant'),
                    'storage_engine' => (string)($result['storage_engine'] ?? 'local'),
                    'storage_domain' => (string)($result['storage_domain'] ?? ''),
                    'mime_type' => 'image/png',
                    'file_size' => 0,
                    'width' => (int)($result['width'] ?? 0),
                    'height' => (int)($result['height'] ?? 0),
                    'duration' => 0,
                    'checksum' => '',
                    'meta_json' => self::jsonEncode(self::generationImageAssetMeta($generation, $params, $imageParams, [
                        'image_task_id' => (int)($imageResult['task_id'] ?? 0),
                        'image_result_id' => (int)($result['id'] ?? 0),
                    ])),
                    'status' => 'ready',
                    'create_time' => time(),
                    'update_time' => time(),
                    'delete_time' => 0,
                ]);
                $assetIds[] = (int)$asset['id'];
            }
            if (empty($assetIds)) {
                throw new Exception('生图结果保存失败');
            }

            AigcShortDramaGenerationTask::where([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'task_id' => $taskId,
            ])->update([
                'status' => self::STATUS_SUCCESS,
                'progress' => 100,
                'provider' => (string)($imageParams['channel'] ?? ''),
                'provider_task_id' => (string)($imageResult['task_id'] ?? ''),
                'result_json' => self::jsonEncode([
                    'image_task_id' => (int)($imageResult['task_id'] ?? 0),
                    'asset_ids' => $assetIds,
                ]),
                'output_asset_ids' => self::jsonEncode($assetIds),
                'billing_status' => ((float)$billing['tenant_cost_points'] > 0 || (float)$billing['user_charge_points'] > 0) ? 'deducted' : 'none',
                'finished_at' => time(),
                'update_time' => time(),
            ]);
        } catch (\Throwable $e) {
            Db::startTrans();
            try {
                $refundStatus = ((float)$billing['tenant_cost_points'] > 0 || (float)$billing['user_charge_points'] > 0) ? 'refunded' : 'none';
                if ((string)($generation['billing_status'] ?? '') === 'reserved') {
                    PointService::refundBusinessAmountsInCurrentTransaction($tenantId, $userId, (float)$billing['tenant_cost_points'], (float)$billing['user_charge_points'], $taskId . '-refund', 'AI short drama image generation failed', [
                        'app_code' => self::APP_CODE,
                        'task_id' => $taskId,
                        'project_id' => $projectId,
                    ]);
                }
                AigcShortDramaGenerationTask::where([
                    'tenant_id' => $tenantId,
                    'user_id' => $userId,
                    'task_id' => $taskId,
                ])->update([
                    'status' => self::STATUS_FAILED,
                    'progress' => 0,
                    'billing_status' => $refundStatus,
                    'error_code' => 'image_generation_failed',
                    'error_msg' => self::friendlyGenerationError($e->getMessage()),
                    'operator_error' => mb_substr($e->getMessage() ?: self::SAFE_ERROR, 0, 1000, 'UTF-8'),
                    'finished_at' => time(),
                    'update_time' => time(),
                ]);
                Db::commit();
            } catch (\Throwable $rollbackError) {
                Db::rollback();
                Log::write('AI short drama image generation rollback failed: ' . $rollbackError->getMessage());
            }
            Log::write('AI short drama image generation failed: ' . $e->getMessage());
        }
    }

    private static function syncImageGenerationTask(int $tenantId, int $userId, array $generation): void
    {
        if (in_array((string)($generation['task_type'] ?? ''), ['shot_video', 'export_video', 'export_package'], true)) {
            return;
        }
        $taskStatus = (string)($generation['status'] ?? '');
        if (in_array($taskStatus, [self::STATUS_SUCCESS, self::STATUS_CANCELED], true)
            && (string)($generation['billing_status'] ?? '') !== 'pending_usage') {
            return;
        }
        if (self::markGenerationSuccessFromExistingAssets($tenantId, $userId, $generation)) {
            return;
        }
        if ((int)($generation['consumption_id'] ?? 0) > 0) {
            $request = self::jsonDecode((string)($generation['request_json'] ?? ''));
            if (self::isNanoBananaImageSelection((array)($request['params'] ?? []))) {
                self::syncMarketNanoBananaGenerationTask($tenantId, $userId, $generation);
            } else {
                self::syncMarketImageGenerationTask($tenantId, $userId, $generation);
            }
            return;
        }
        $result = self::jsonDecode((string)($generation['result_json'] ?? ''));
        $imageTaskId = (int)($result['image_task_id'] ?? $generation['provider_task_id'] ?? 0);
        $recoverableFailed = $taskStatus === self::STATUS_FAILED && $imageTaskId > 0;
        if (!in_array($taskStatus, [self::STATUS_PENDING, self::STATUS_QUEUED, self::STATUS_RUNNING], true) && !$recoverableFailed) {
            return;
        }
        if ($imageTaskId <= 0) {
            return;
        }
        try {
            $imageTask = AigcImageService::taskDetail($tenantId, $imageTaskId, $userId);
        } catch (\Throwable $e) {
            Log::write('AI short drama image task sync failed: ' . $e->getMessage());
            return;
        }
        $status = (string)($imageTask['status'] ?? '');
        if (self::isProviderFailedStatus($status)) {
            if ($taskStatus === self::STATUS_FAILED) {
                return;
            }
            $billing = self::jsonDecode((string)($generation['pricing_snapshot'] ?? ''));
            self::failGenerationTaskWithRefund($tenantId, $userId, $generation, $billing, 'image_generation_failed', 'AI short drama image generation failed', new Exception((string)($imageTask['error'] ?? self::SAFE_ERROR)));
            return;
        }
        if (!self::isProviderSuccessStatus($status)) {
            return;
        }
        $existing = AigcShortDramaAsset::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'project_id' => (int)$generation['project_id'],
            'task_id' => (string)$generation['task_id'],
            'asset_type' => self::generationAssetType((string)($generation['task_type'] ?? 'shot_image')),
            'delete_time' => 0,
        ])->column('id');
        $assetIds = array_map('intval', (array)$existing);
        if (empty($assetIds)) {
            $assetIds = self::registerImageResultsAsAssets($tenantId, $userId, $generation, (array)($imageTask['results'] ?? []), $imageTaskId);
        }
        if (empty($assetIds)) {
            return;
        }
        AigcShortDramaGenerationTask::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'task_id' => (string)$generation['task_id'],
        ])->update([
            'status' => self::STATUS_SUCCESS,
            'progress' => 100,
            'result_json' => self::jsonEncode([
                'image_task_id' => $imageTaskId,
                'asset_ids' => $assetIds,
            ]),
            'output_asset_ids' => self::jsonEncode($assetIds),
            'billing_status' => ((float)($generation['tenant_cost_points'] ?? 0) > 0 || (float)($generation['user_charge_points'] ?? 0) > 0)
                ? ((string)($generation['billing_status'] ?? '') === 'refunded' ? 'refunded' : 'deducted')
                : 'none',
            'finished_at' => time(),
            'update_time' => time(),
        ]);
        self::refreshProjectGenerationStatus($tenantId, $userId, (int)$generation['project_id']);
    }

    /**
     * Market image tasks deliberately do not create an aigc_image_task. The
     * short-drama generation task remains the business owner of the result.
     */
    private static function runMarketImageGenerationTask(int $tenantId, int $userId, array $generation, array $params, array $imageParams, array $billing): void
    {
        $taskId = (string)$generation['task_id'];
        try {
            $reserve = MarketImageModelRuntimeService::reserve($tenantId, $userId, (string)$generation['task_type'], $taskId, $params, $imageParams, 1);
            AigcShortDramaGenerationTask::where([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'task_id' => $taskId,
            ])->update([
                'app_task_id' => (int)$reserve['app_task_id'],
                'consumption_id' => (int)$reserve['consumption_id'],
                'market_product_id' => (int)($reserve['market_snapshot']['product_id'] ?? 0),
                'market_sku_id' => (int)($reserve['market_snapshot']['sku_id'] ?? 0),
                'model_json' => self::jsonEncode($reserve['market_snapshot']),
                'pricing_snapshot' => self::jsonEncode($billing),
                'billing_status' => (string)($billing['settlement_mode'] ?? '') === 'actual_usage' ? 'pending_usage' : 'reserved',
                'tenant_cost_points' => (float)($billing['tenant_cost_points'] ?? 0),
                'user_charge_points' => (float)($billing['user_charge_points'] ?? 0),
                'provider' => self::isNanoBananaImageSelection(self::jsonDecode((string)$generation['request_json'])['params'] ?? []) ? 'power_market_app_api' : 'power_market',
                'progress' => 30,
                'update_time' => time(),
            ]);
            $business = self::findGenerationTask($tenantId, $userId, $taskId);
            MarketImageModelRuntimeService::linkBusinessTask((int)$reserve['app_task_id'], (int)$business['id']);
            $result = MarketImageModelRuntimeService::submit((int)$reserve['consumption_id'], $imageParams);
            if ((string)($result['status'] ?? '') === self::STATUS_FAILED) {
                throw new Exception('图片模型生成失败');
            }
            self::persistMarketImageTaskResult($tenantId, $userId, $taskId, $result, $imageParams);
        } catch (\Throwable $e) {
            $current = self::findGenerationTask($tenantId, $userId, $taskId)->toArray();
            $consumptionId = (int)($current['consumption_id'] ?? 0);
            if ($consumptionId > 0) {
                MarketImageModelRuntimeService::fail($consumptionId, $e->getMessage(), 'short_drama_image_failed');
            }
            self::failMarketImageGenerationTask($tenantId, $userId, $current, $e);
        }
    }

    private static function runMarketNanoBananaGenerationTask(int $tenantId, int $userId, array $generation, array $params, array $imageParams, array $billing): void
    {
        $taskId = (string)$generation['task_id'];
        try {
            $reserve = MarketNanoBananaAppRuntimeService::reserve($tenantId, $userId, (string)$generation['task_type'], $taskId, $params, $imageParams, 1);
            AigcShortDramaGenerationTask::where(['tenant_id' => $tenantId, 'user_id' => $userId, 'task_id' => $taskId])->update([
                'app_task_id' => (int)$reserve['app_task_id'], 'consumption_id' => (int)$reserve['consumption_id'],
                'market_product_id' => (int)($reserve['market_snapshot']['product_id'] ?? 0), 'market_sku_id' => (int)($reserve['market_snapshot']['sku_id'] ?? 0),
                'model_json' => self::jsonEncode($reserve['market_snapshot']), 'pricing_snapshot' => self::jsonEncode($billing), 'billing_status' => (string)($billing['settlement_mode'] ?? '') === 'actual_usage' ? 'pending_usage' : 'reserved',
                'tenant_cost_points' => (float)($billing['tenant_cost_points'] ?? 0), 'user_charge_points' => (float)($billing['user_charge_points'] ?? 0),
                'provider' => 'power_market_app_api', 'progress' => 30, 'update_time' => time(),
            ]);
            $business = self::findGenerationTask($tenantId, $userId, $taskId);
            MarketNanoBananaAppRuntimeService::linkBusinessTask((int)$reserve['app_task_id'], (int)$business['id']);
            $result = MarketNanoBananaAppRuntimeService::submit((int)$reserve['consumption_id'], $imageParams);
            self::persistMarketImageTaskResult($tenantId, $userId, $taskId, $result, $imageParams);
        } catch (\Throwable $e) {
            $current = self::findGenerationTask($tenantId, $userId, $taskId)->toArray();
            if ((int)($current['consumption_id'] ?? 0) > 0) MarketNanoBananaAppRuntimeService::fail((int)$current['consumption_id'], $e->getMessage(), 'short_drama_nano_banana_failed');
            self::failMarketImageGenerationTask($tenantId, $userId, $current, $e);
        }
    }

    private static function syncMarketNanoBananaGenerationTask(int $tenantId, int $userId, array $generation): void
    {
        $consumptionId = (int)($generation['consumption_id'] ?? 0);
        if ($consumptionId <= 0 || in_array((string)($generation['status'] ?? ''), [self::STATUS_FAILED, self::STATUS_CANCELED], true)
            || ((string)($generation['status'] ?? '') === self::STATUS_SUCCESS && (string)($generation['billing_status'] ?? '') !== 'pending_usage')) return;
        try {
            $result = MarketNanoBananaAppRuntimeService::refresh($consumptionId);
            $request = self::jsonDecode((string)($generation['request_json'] ?? ''));
            self::persistMarketImageTaskResult($tenantId, $userId, (string)$generation['task_id'], $result, (array)($request['image_params'] ?? []));
        } catch (\Throwable $e) {
            MarketNanoBananaAppRuntimeService::fail($consumptionId, $e->getMessage(), 'short_drama_nano_banana_refresh_failed');
            self::failMarketImageGenerationTask($tenantId, $userId, $generation, $e);
        }
    }

    private static function syncMarketImageGenerationTask(int $tenantId, int $userId, array $generation): void
    {
        $consumptionId = (int)($generation['consumption_id'] ?? 0);
        if ($consumptionId <= 0 || in_array((string)($generation['status'] ?? ''), [self::STATUS_SUCCESS, self::STATUS_FAILED, self::STATUS_CANCELED], true)) {
            return;
        }
        try {
            $result = MarketImageModelRuntimeService::refresh($consumptionId);
            $request = self::jsonDecode((string)($generation['request_json'] ?? ''));
            $imageParams = (array)($request['image_params'] ?? []);
            self::persistMarketImageTaskResult($tenantId, $userId, (string)$generation['task_id'], $result, $imageParams);
        } catch (\Throwable $e) {
            MarketImageModelRuntimeService::fail($consumptionId, $e->getMessage(), 'short_drama_image_refresh_failed');
            self::failMarketImageGenerationTask($tenantId, $userId, $generation, $e);
        }
    }

    private static function persistMarketImageTaskResult(int $tenantId, int $userId, string $taskId, array $result, array $imageParams): void
    {
        $generation = self::findGenerationTask($tenantId, $userId, $taskId);
        $row = $generation->toArray();
        $status = (string)($result['status'] ?? self::STATUS_RUNNING);
        if ($status === self::STATUS_FAILED) {
            self::failMarketImageGenerationTask($tenantId, $userId, $row, new Exception('图片模型生成失败'));
            return;
        }
        $providerTaskId = (string)($result['provider_task_id'] ?? '');
        $providerRequestId = (string)($result['provider_request_id'] ?? '');
        $images = (array)($result['images'] ?? []);
        if ($images === []) {
            $generation->save([
                'status' => self::STATUS_RUNNING,
                'progress' => 45,
                'provider' => 'power_market',
                'provider_task_id' => $providerTaskId,
                'provider_request_id' => $providerRequestId,
                'result_json' => self::jsonEncode(['message' => '图片模型任务已提交，等待结果']),
                'update_time' => time(),
            ]);
            return;
        }
        $assetIds = self::registerMarketImageResultsAsAssets($tenantId, $userId, $row, $images, $imageParams);
        if ($assetIds === []) {
            throw new Exception('生图结果保存失败');
        }
        $pricing = self::jsonDecode((string)($row['pricing_snapshot'] ?? ''));
        $consumption = (int)($row['consumption_id'] ?? 0) > 0 ? AiConsumptionLog::where('id', (int)$row['consumption_id'])->findOrEmpty() : null;
        $billingStatus = !$consumption || $consumption->isEmpty()
            ? (((float)($pricing['tenant_cost_points'] ?? 0) > 0 || (float)($pricing['user_charge_points'] ?? 0) > 0) ? 'deducted' : 'none')
            : ((string)$consumption['billing_status'] === 'settled' ? 'deducted' : ((string)$consumption['billing_status'] === 'pending_usage' ? 'pending_usage' : (string)$consumption['billing_status']));
        $generation->save([
            'status' => self::STATUS_SUCCESS,
            'progress' => 100,
            'provider' => self::isNanoBananaImageSelection(self::jsonDecode((string)$generation['request_json'])['params'] ?? []) ? 'power_market_app_api' : 'power_market',
            'provider_task_id' => $providerTaskId,
            'provider_request_id' => $providerRequestId,
            'result_json' => self::jsonEncode(['market_consumption_id' => (int)$row['consumption_id'], 'asset_ids' => $assetIds]),
            'output_asset_ids' => self::jsonEncode($assetIds),
            'billing_status' => $billingStatus,
            'finished_at' => time(),
            'update_time' => time(),
        ]);
        self::selectGeneratedStoryboardAsset($tenantId, $userId, $row, $assetIds);
        self::refreshProjectGenerationStatus($tenantId, $userId, (int)$row['project_id']);
    }

    private static function registerMarketImageResultsAsAssets(int $tenantId, int $userId, array $generation, array $results, array $imageParams): array
    {
        $assetIds = [];
        foreach ($results as $index => $result) {
            if (!is_array($result) || (string)($result['image_uri'] ?? '') === '') {
                continue;
            }
            $assetType = self::generationAssetType((string)($generation['task_type'] ?? 'shot_image'));
            $result = self::normalizeShortDramaImageResultRatio($tenantId, $userId, $result, $assetType, (string)($imageParams['ratio'] ?? ''));
            $asset = AigcShortDramaAsset::create([
                'tenant_id' => $tenantId, 'user_id' => $userId, 'project_id' => (int)$generation['project_id'], 'task_id' => (string)$generation['task_id'],
                'shot_id' => (string)($generation['shot_id'] ?? ''), 'asset_type' => $assetType, 'title' => '短剧图片' . ((int)$index + 1),
                'uri' => (string)$result['image_uri'], 'cover_uri' => '',
                'storage_scope' => (string)($result['storage_scope'] ?? 'tenant'), 'storage_engine' => (string)($result['storage_engine'] ?? ''), 'storage_domain' => (string)($result['storage_domain'] ?? ''),
                'mime_type' => 'image/png', 'file_size' => 0, 'width' => (int)($result['width'] ?? 0), 'height' => (int)($result['height'] ?? 0), 'duration' => 0, 'checksum' => '',
                'meta_json' => self::jsonEncode(self::generationImageAssetMeta($generation, [], $imageParams, ['consumption_id' => (int)($generation['consumption_id'] ?? 0)])),
                'status' => 'ready', 'create_time' => time(), 'update_time' => time(), 'delete_time' => 0,
            ]);
            $assetIds[] = (int)$asset['id'];
        }
        return $assetIds;
    }

    private static function failMarketImageGenerationTask(int $tenantId, int $userId, array $generation, \Throwable $e): void
    {
        if ($generation === []) {
            return;
        }
        $billingStatus = 'refunded';
        $consumptionId = (int)($generation['consumption_id'] ?? 0);
        if ($consumptionId > 0) {
            $consumption = AiConsumptionLog::where('id', $consumptionId)->findOrEmpty();
            if (!$consumption->isEmpty() && (string)$consumption['billing_status'] === 'settled') {
                $billingStatus = 'deducted';
            }
        }
        AigcShortDramaGenerationTask::where([
            'tenant_id' => $tenantId, 'user_id' => $userId, 'task_id' => (string)$generation['task_id'],
        ])->update([
            'status' => self::STATUS_FAILED, 'progress' => 0, 'billing_status' => $billingStatus, 'error_code' => 'market_image_failed',
            'error_msg' => self::friendlyGenerationError($e->getMessage()), 'operator_error' => mb_substr($e->getMessage(), 0, 1000, 'UTF-8'),
            'finished_at' => time(), 'update_time' => time(),
        ]);
        self::refreshProjectGenerationStatus($tenantId, $userId, (int)($generation['project_id'] ?? 0));
    }

    private static function registerImageResultsAsAssets(int $tenantId, int $userId, array $generation, array $results, int $imageTaskId): array
    {
        $assetIds = [];
        $request = self::jsonDecode((string)($generation['request_json'] ?? ''));
        $requestParams = (array)($request['params'] ?? []);
        $requestImageParams = (array)($request['image_params'] ?? []);
        $imageParams = [
            'prompt' => (string)($requestImageParams['prompt'] ?? $requestParams['prompt'] ?? $requestParams['image_prompt'] ?? $requestParams['visual_prompt'] ?? ''),
            'channel' => (string)($requestImageParams['channel'] ?? $generation['provider'] ?? ''),
        ];
        foreach ($results as $index => $result) {
            if (!is_array($result)) {
                continue;
            }
            $uri = (string)($result['image_uri'] ?? '');
            if ($uri === '') {
                continue;
            }
            $assetType = self::generationAssetType((string)($generation['task_type'] ?? 'shot_image'));
            $targetRatio = (string)($requestImageParams['ratio'] ?? $requestParams['ratio'] ?? $requestParams['aspect_ratio'] ?? '');
            $result = self::normalizeShortDramaImageResultRatio($tenantId, $userId, $result, $assetType, $targetRatio);
            $uri = (string)($result['image_uri'] ?? $uri);
            $asset = AigcShortDramaAsset::create([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'project_id' => (int)$generation['project_id'],
                'task_id' => (string)$generation['task_id'],
                'shot_id' => (string)($generation['shot_id'] ?? ''),
                'asset_type' => $assetType,
                'title' => '短剧图片' . ((int)$index + 1),
                'uri' => $uri,
                'cover_uri' => '',
                'storage_scope' => (string)($result['storage_scope'] ?? 'tenant'),
                'storage_engine' => (string)($result['storage_engine'] ?? 'local'),
                'storage_domain' => (string)($result['storage_domain'] ?? ''),
                'mime_type' => 'image/png',
                'file_size' => 0,
                'width' => (int)($result['width'] ?? 0),
                'height' => (int)($result['height'] ?? 0),
                'duration' => 0,
                'checksum' => '',
                'meta_json' => self::jsonEncode(self::generationImageAssetMeta($generation, $requestParams, $imageParams, [
                    'image_task_id' => $imageTaskId,
                    'image_result_id' => (int)($result['id'] ?? 0),
                ])),
                'status' => 'ready',
                'create_time' => time(),
                'update_time' => time(),
                'delete_time' => 0,
            ]);
            $assetIds[] = (int)$asset['id'];
        }
        return $assetIds;
    }

    private static function normalizeShortDramaImageResultRatio(int $tenantId, int $userId, array $result, string $assetType, string $targetRatio): array
    {
        if ($assetType !== 'shot_image') {
            return $result;
        }
        $targetRatio = self::normalizeGenerationRatio($targetRatio);
        if ($targetRatio === '' || !preg_match('/^(\d+):(\d+)$/', $targetRatio, $matches)) {
            return $result;
        }
        $ratioWidth = max(1, (int)$matches[1]);
        $ratioHeight = max(1, (int)$matches[2]);
        $target = $ratioWidth / $ratioHeight;
        $sourceWidth = (int)($result['width'] ?? 0);
        $sourceHeight = (int)($result['height'] ?? 0);
        if ($sourceWidth <= 0 || $sourceHeight <= 0) {
            return $result;
        }
        $actual = $sourceWidth / $sourceHeight;
        if (abs($actual - $target) / $target <= 0.015) {
            return $result;
        }

        $uri = (string)($result['image_uri'] ?? $result['uri'] ?? '');
        if ($uri === '') {
            return $result;
        }
        $url = (string)($result['image_url'] ?? $result['url'] ?? '');
        if ($url === '') {
            $url = FileService::getFileUrlByStorage(
                $uri,
                (string)($result['storage_scope'] ?? 'tenant'),
                (string)($result['storage_engine'] ?? 'local'),
                (string)($result['storage_domain'] ?? '')
            );
        }
        if ($url === '') {
            return $result;
        }

        $tmpPath = '';
        try {
            $context = stream_context_create([
                'http' => [
                    'timeout' => 25,
                    'follow_location' => 1,
                    'ignore_errors' => true,
                    'header' => "User-Agent: LikeAdminShortDrama/1.0\r\n",
                ],
            ]);
            $content = @file_get_contents($url, false, $context);
            if ($content === false || $content === '') {
                return $result;
            }
            $source = @imagecreatefromstring($content);
            if (!$source) {
                return $result;
            }
            $cropWidth = $sourceWidth;
            $cropHeight = $sourceHeight;
            $srcX = 0;
            $srcY = 0;
            if ($actual < $target) {
                $cropHeight = max(1, min($sourceHeight, (int)round($sourceWidth / $target)));
                $srcY = max(0, (int)floor(($sourceHeight - $cropHeight) / 2));
            } else {
                $cropWidth = max(1, min($sourceWidth, (int)round($sourceHeight * $target)));
                $srcX = max(0, (int)floor(($sourceWidth - $cropWidth) / 2));
            }
            if ($cropWidth <= 0 || $cropHeight <= 0 || ($cropWidth === $sourceWidth && $cropHeight === $sourceHeight)) {
                imagedestroy($source);
                return $result;
            }
            $targetImage = imagecreatetruecolor($cropWidth, $cropHeight);
            imagealphablending($targetImage, false);
            imagesavealpha($targetImage, true);
            imagecopy($targetImage, $source, 0, 0, $srcX, $srcY, $cropWidth, $cropHeight);
            imagedestroy($source);

            $tmp = tempnam(sys_get_temp_dir(), 'sd_ratio_');
            if ($tmp === false) {
                imagedestroy($targetImage);
                return $result;
            }
            $tmpPath = $tmp . '.png';
            @rename($tmp, $tmpPath);
            imagepng($targetImage, $tmpPath, 9);
            imagedestroy($targetImage);

            $stored = self::storeInternalAssetFile($tenantId, $tmpPath, 'uploads/aigc_image/' . date('Ymd'));
            return array_merge($result, [
                'image_uri' => $stored['uri'],
                'uri' => $stored['uri'],
                'storage_scope' => $stored['storage_scope'],
                'storage_engine' => $stored['storage_engine'],
                'storage_domain' => $stored['storage_domain'],
                'width' => $cropWidth,
                'height' => $cropHeight,
                'ratio_normalized' => true,
                'original_image_uri' => $uri,
                'original_width' => $sourceWidth,
                'original_height' => $sourceHeight,
            ]);
        } catch (\Throwable $e) {
            Log::write('AI short drama image ratio normalize failed: ' . $e->getMessage());
            return $result;
        } finally {
            if ($tmpPath !== '' && is_file($tmpPath)) {
                @unlink($tmpPath);
            }
        }
    }

    private static function generationImageAssetMeta(array $generation, array $params, array $imageParams, array $extra = []): array
    {
        $nested = is_array($params['params'] ?? null) ? (array)$params['params'] : [];
        $value = static function (string $key) use ($params, $nested): string {
            return trim((string)($params[$key] ?? $nested[$key] ?? ''));
        };
        $first = static function (...$values): string {
            foreach ($values as $val) {
                $current = trim((string)$val);
                if ($current !== '') {
                    return $current;
                }
            }
            return '';
        };

        $meta = $extra;
        $sourceTaskId = $first($generation['source_task_id'] ?? '', $value('project_task_id'), $value('source_task_id'));
        if ($sourceTaskId !== '') {
            $meta['source_task_id'] = $sourceTaskId;
            $meta['project_task_id'] = $sourceTaskId;
        }
        foreach (['subject_id', 'scene_id', 'item_id', 'subject_name', 'scene_name', 'item_name', 'category', 'view_mode'] as $key) {
            $current = $value($key);
            if ($current !== '') {
                $meta[$key] = $current;
            }
        }
        if (empty($meta['item_id'])) {
            if (!empty($meta['subject_id'])) {
                $meta['item_id'] = (string)$meta['subject_id'];
            } elseif (!empty($meta['scene_id'])) {
                $meta['item_id'] = (string)$meta['scene_id'];
            }
        }
        $prompt = $first($imageParams['prompt'] ?? '', $value('prompt'), $value('image_prompt'), $value('visual_prompt'));
        if ($prompt !== '') {
            $meta['prompt'] = self::normalizeFinalProviderPrompt($prompt);
        }
        $model = $first($imageParams['channel'] ?? '', $value('model_id'), $value('model_name'));
        if ($model !== '') {
            $meta['model'] = $model;
        }
        return $meta;
    }

    private static function syncGenerationTask(int $tenantId, int $userId, array $generation): void
    {
        $taskType = (string)($generation['task_type'] ?? '');
        if ($taskType === 'shot_video') {
            self::syncVideoGenerationTask($tenantId, $userId, $generation);
            return;
        }
        if (in_array($taskType, ['export_video', 'export_package'], true)) {
            return;
        }
        if ($taskType === 'bgm_audio') {
            self::syncBgmAudioGenerationTask($tenantId, $userId, $generation);
            return;
        }
        self::syncImageGenerationTask($tenantId, $userId, $generation);
    }

    private static function runVideoGenerationTask(int $tenantId, int $userId, array $generation, array $shot, array $params, array $billing): void
    {
        $taskId = (string)$generation['task_id'];
        AigcShortDramaGenerationTask::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'task_id' => $taskId,
        ])->update([
            'status' => self::STATUS_RUNNING,
            'progress' => 15,
            'started_at' => time(),
            'update_time' => time(),
        ]);

        try {
            $videoParams = self::marketShortDramaVideoParams($tenantId, $userId, (int)$generation['project_id'], $shot, $params);
            AigcShortDramaGenerationTask::where([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'task_id' => $taskId,
            ])->update([
                'request_json' => self::jsonEncode([
                    'shot' => self::formatShot($shot),
                    'params' => $params,
                    'video_params' => $videoParams,
                ]),
                'input_asset_ids' => self::jsonEncode((array)($videoParams['input_asset_ids'] ?? [])),
                'update_time' => time(),
            ]);

            self::runMarketVideoGenerationTask($tenantId, $userId, $generation, $videoParams, $billing);
        } catch (\Throwable $e) {
            self::failMarketVideoGenerationTask($tenantId, $userId, $generation, $e);
        }
    }

    private static function syncVideoGenerationTask(int $tenantId, int $userId, array $generation): void
    {
        if (!in_array((string)($generation['status'] ?? ''), [self::STATUS_PENDING, self::STATUS_QUEUED, self::STATUS_RUNNING], true)) {
            return;
        }
        if (self::markGenerationSuccessFromExistingAssets($tenantId, $userId, $generation)) {
            return;
        }
        if ((int)($generation['consumption_id'] ?? 0) > 0) {
            self::syncMarketVideoGenerationTask($tenantId, $userId, $generation);
            return;
        }
        $result = self::jsonDecode((string)($generation['result_json'] ?? ''));
        $videoTaskId = (int)($result['video_task_id'] ?? $generation['provider_task_id'] ?? 0);
        if ($videoTaskId <= 0) {
            return;
        }
        try {
            $videoTask = AigcVideoService::taskDetail($tenantId, $videoTaskId, $userId);
        } catch (\Throwable $e) {
            Log::write('AI short drama video task sync failed: ' . $e->getMessage());
            return;
        }
        $status = (string)($videoTask['status'] ?? '');
        if (self::isProviderFailedStatus($status)) {
            $billing = self::jsonDecode((string)($generation['pricing_snapshot'] ?? ''));
            self::failGenerationTaskWithRefund($tenantId, $userId, $generation, $billing, 'video_generation_failed', 'AI short drama video generation failed', new Exception((string)($videoTask['error'] ?? self::SAFE_ERROR)));
            return;
        }
        if (!self::isProviderSuccessStatus($status)) {
            return;
        }
        $existing = AigcShortDramaAsset::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'project_id' => (int)$generation['project_id'],
            'task_id' => (string)$generation['task_id'],
            'asset_type' => 'shot_video',
            'delete_time' => 0,
        ])->column('id');
        $assetIds = array_map('intval', (array)$existing);
        if (empty($assetIds)) {
            $request = self::jsonDecode((string)($generation['request_json'] ?? ''));
            $assetIds = self::registerVideoResultsAsAssets($tenantId, $userId, $generation, (array)($videoTask['results'] ?? []), $videoTaskId, (array)($request['video_params'] ?? []));
        }
        if (empty($assetIds)) {
            return;
        }
        AigcShortDramaGenerationTask::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'task_id' => (string)$generation['task_id'],
        ])->update([
            'status' => self::STATUS_SUCCESS,
            'progress' => 100,
            'result_json' => self::jsonEncode([
                'video_task_id' => $videoTaskId,
                'asset_ids' => $assetIds,
            ]),
            'output_asset_ids' => self::jsonEncode($assetIds),
            'billing_status' => ((float)($generation['tenant_cost_points'] ?? 0) > 0 || (float)($generation['user_charge_points'] ?? 0) > 0)
                ? ((string)($generation['billing_status'] ?? '') === 'refunded' ? 'refunded' : 'deducted')
                : 'none',
            'finished_at' => time(),
            'update_time' => time(),
        ]);
        self::refreshProjectGenerationStatus($tenantId, $userId, (int)$generation['project_id']);
    }

    /** Market video tasks do not create an aigc_video_task; the short-drama task owns the generated asset. */
    private static function runMarketVideoGenerationTask(int $tenantId, int $userId, array $generation, array $videoParams, array $billing): void
    {
        $taskId = (string)$generation['task_id'];
        $selection = self::marketVideoSelection($videoParams);
        $runtime = self::marketVideoRuntime($selection);
        try {
            $reserve = $runtime::reserve($tenantId, $userId, self::APP_CODE, 'shot_video', 'aigc_short_drama_generation_task', $taskId, $selection, $videoParams);
            AigcShortDramaGenerationTask::where(['tenant_id' => $tenantId, 'user_id' => $userId, 'task_id' => $taskId])->update([
                'app_task_id' => (int)$reserve['app_task_id'], 'consumption_id' => (int)$reserve['consumption_id'],
                'market_product_id' => (int)($reserve['market_snapshot']['product_id'] ?? 0), 'market_sku_id' => (int)($reserve['market_snapshot']['sku_id'] ?? 0),
                'model_json' => self::jsonEncode($reserve['market_snapshot']), 'pricing_snapshot' => self::jsonEncode($billing), 'billing_status' => (string)($billing['settlement_mode'] ?? '') === 'actual_usage' ? 'pending_usage' : 'reserved',
                'tenant_cost_points' => (float)($billing['tenant_cost_points'] ?? 0), 'user_charge_points' => (float)($billing['user_charge_points'] ?? 0),
                'provider' => str_contains((string)($selection['model_id'] ?? ''), 'market_video_app:') ? 'power_market_app_api' : 'power_market', 'progress' => 30, 'update_time' => time(),
            ]);
            $business = self::findGenerationTask($tenantId, $userId, $taskId);
            $runtime::linkBusinessTask((int)$reserve['app_task_id'], (int)$business['id']);
            $result = $runtime::submit((int)$reserve['consumption_id'], $videoParams);
            self::persistMarketVideoTaskResult($tenantId, $userId, $taskId, $result, $videoParams);
        } catch (\Throwable $e) {
            $current = self::findGenerationTask($tenantId, $userId, $taskId)->toArray();
            if ((int)($current['consumption_id'] ?? 0) > 0) {
                try { $runtime::fail((int)$current['consumption_id'], $e->getMessage(), 'short_drama_video_failed'); } catch (\Throwable) {}
            }
            self::failMarketVideoGenerationTask($tenantId, $userId, $current, $e);
        }
    }

    private static function syncMarketVideoGenerationTask(int $tenantId, int $userId, array $generation): void
    {
        $consumptionId = (int)($generation['consumption_id'] ?? 0);
        if ($consumptionId <= 0 || in_array((string)($generation['status'] ?? ''), [self::STATUS_FAILED, self::STATUS_CANCELED], true)
            || ((string)($generation['status'] ?? '') === self::STATUS_SUCCESS && (string)($generation['billing_status'] ?? '') !== 'pending_usage')) return;
        try {
            $request = self::jsonDecode((string)($generation['request_json'] ?? ''));
            $videoParams = (array)($request['video_params'] ?? []);
            $result = self::marketVideoRuntime(self::marketVideoSelection($videoParams))::refresh($consumptionId);
            self::persistMarketVideoTaskResult($tenantId, $userId, (string)$generation['task_id'], $result, $videoParams);
        } catch (\Throwable $e) {
            self::marketVideoRuntime(self::marketVideoSelection(self::jsonDecode((string)$generation['request_json'])['video_params'] ?? []))::fail($consumptionId, $e->getMessage(), 'short_drama_video_refresh_failed');
            self::failMarketVideoGenerationTask($tenantId, $userId, $generation, $e);
        }
    }

    public static function refreshMarketVideoTasks(int $limit = 20): int
    {
        $rows = AigcShortDramaGenerationTask::where('task_type', 'shot_video')
            ->where('consumption_id', '>', 0)
            ->whereIn('status', [self::STATUS_PENDING, self::STATUS_QUEUED, self::STATUS_RUNNING, self::STATUS_SUCCESS])
            ->where('delete_time', 0)
            ->order('id', 'asc')
            ->limit(max(1, $limit))
            ->select()
            ->toArray();
        foreach ($rows as $row) {
            try {
                self::syncMarketVideoGenerationTask((int)$row['tenant_id'], (int)$row['user_id'], $row);
            } catch (\Throwable $e) {
                Log::write('Short drama market video refresh failed: ' . $e->getMessage());
            }
        }
        return count($rows);
    }

    /**
     * Worker-only entry point. Reads never invoke supplier refreshes; this
     * resolves one business task that is already linked to a consumption row.
     */
    public static function refreshMarketGenerationTask(int $generationId): void
    {
        $generation = AigcShortDramaGenerationTask::where('id', $generationId)
            ->where('consumption_id', '>', 0)
            ->where('delete_time', 0)
            ->findOrEmpty();
        if ($generation->isEmpty()) {
            return;
        }
        $row = $generation->toArray();
        $tenantId = (int)$row['tenant_id'];
        $userId = (int)$row['user_id'];
        if ((string)$row['task_type'] === 'shot_video') {
            self::syncMarketVideoGenerationTask($tenantId, $userId, $row);
            return;
        }
        if ((string)$row['task_type'] === 'bgm_audio') {
            self::syncMarketBgmAudioGenerationTask($tenantId, $userId, $row);
            return;
        }
        $request = self::jsonDecode((string)($row['request_json'] ?? ''));
        if (self::isNanoBananaImageSelection((array)($request['params'] ?? []))) {
            self::syncMarketNanoBananaGenerationTask($tenantId, $userId, $row);
            return;
        }
        self::syncMarketImageGenerationTask($tenantId, $userId, $row);
    }

    /** Refresh result delivery and late usage reports for every market media task. */
    public static function refreshMarketUsageTasks(int $limit = 20): int
    {
        $rows = AigcShortDramaGenerationTask::where('consumption_id', '>', 0)
            ->whereIn('task_type', ['subject_image', 'scene_image', 'three_view', 'shot_image', 'bgm_audio'])
            ->whereIn('status', [self::STATUS_PENDING, self::STATUS_QUEUED, self::STATUS_RUNNING, self::STATUS_SUCCESS])
            ->where('delete_time', 0)
            ->order('id', 'asc')
            ->limit(max(1, $limit))
            ->select()
            ->toArray();
        foreach ($rows as $row) {
            try {
                if ((string)$row['task_type'] === 'bgm_audio') {
                    self::syncMarketBgmAudioGenerationTask((int)$row['tenant_id'], (int)$row['user_id'], $row);
                } else {
                    $request = self::jsonDecode((string)($row['request_json'] ?? ''));
                    if (self::isNanoBananaImageSelection((array)($request['params'] ?? []))) {
                        self::syncMarketNanoBananaGenerationTask((int)$row['tenant_id'], (int)$row['user_id'], $row);
                    } else {
                        self::syncMarketImageGenerationTask((int)$row['tenant_id'], (int)$row['user_id'], $row);
                    }
                }
            } catch (\Throwable $e) {
                Log::write('Short drama market usage refresh failed: ' . $e->getMessage());
            }
        }
        return count($rows);
    }

    private static function persistMarketVideoTaskResult(int $tenantId, int $userId, string $taskId, array $result, array $videoParams): void
    {
        $generation = self::findGenerationTask($tenantId, $userId, $taskId); $row = $generation->toArray(); $status = (string)($result['status'] ?? self::STATUS_RUNNING);
        if ($status === self::STATUS_FAILED) { self::failMarketVideoGenerationTask($tenantId, $userId, $row, new Exception((string)($result['error_msg'] ?? $result['error'] ?? '视频模型生成失败'))); return; }
        $taskNo = (string)($result['provider_task_id'] ?? ''); $requestNo = (string)($result['provider_request_id'] ?? ''); $videos = (array)($result['videos'] ?? []);
        if ($videos === []) { $generation->save(['status' => self::STATUS_RUNNING, 'progress' => 45, 'provider' => 'power_market', 'provider_task_id' => $taskNo, 'provider_request_id' => $requestNo, 'result_json' => self::jsonEncode(['message' => '视频模型任务已提交，等待结果']), 'update_time' => time()]); return; }
        $assetIds = self::registerVideoResultsAsAssets($tenantId, $userId, $row, $videos, 0, $videoParams);
        if ($assetIds === []) throw new Exception('视频结果保存失败');
        $pricing = self::jsonDecode((string)($row['pricing_snapshot'] ?? ''));
        $consumption = (int)($row['consumption_id'] ?? 0) > 0 ? AiConsumptionLog::where('id', (int)$row['consumption_id'])->findOrEmpty() : null;
        $billingStatus = !$consumption || $consumption->isEmpty()
            ? (((float)($pricing['tenant_cost_points'] ?? 0) > 0 || (float)($pricing['user_charge_points'] ?? 0) > 0) ? 'deducted' : 'none')
            : ((string)$consumption['billing_status'] === 'settled' ? 'deducted' : ((string)$consumption['billing_status'] === 'pending_usage' ? 'pending_usage' : (string)$consumption['billing_status']));
        $generation->save(['status' => self::STATUS_SUCCESS, 'progress' => 100, 'provider' => 'power_market', 'provider_task_id' => $taskNo, 'provider_request_id' => $requestNo, 'result_json' => self::jsonEncode(['market_consumption_id' => (int)$row['consumption_id'], 'asset_ids' => $assetIds]), 'output_asset_ids' => self::jsonEncode($assetIds), 'billing_status' => $billingStatus, 'finished_at' => time(), 'update_time' => time()]);
        self::selectGeneratedStoryboardAsset($tenantId, $userId, $row, $assetIds);
        self::refreshProjectGenerationStatus($tenantId, $userId, (int)$row['project_id']);
    }

    private static function failMarketVideoGenerationTask(int $tenantId, int $userId, array $generation, \Throwable $e): void
    {
        if ($generation === []) return;
        $status = 'refunded'; $consumptionId = (int)($generation['consumption_id'] ?? 0);
        if ($consumptionId > 0) { $consumption = AiConsumptionLog::where('id', $consumptionId)->findOrEmpty(); if (!$consumption->isEmpty() && (string)$consumption['billing_status'] === 'settled') $status = 'deducted'; }
        AigcShortDramaGenerationTask::where(['tenant_id' => $tenantId, 'user_id' => $userId, 'task_id' => (string)$generation['task_id']])->update(['status' => self::STATUS_FAILED, 'progress' => 0, 'billing_status' => $status, 'error_code' => 'video_generation_failed', 'error_msg' => self::friendlyGenerationError($e->getMessage()), 'operator_error' => mb_substr($e->getMessage() ?: self::SAFE_ERROR, 0, 1000, 'UTF-8'), 'finished_at' => time(), 'update_time' => time()]);
        self::refreshProjectGenerationStatus($tenantId, $userId, (int)($generation['project_id'] ?? 0));
    }

    private static function registerVideoResultsAsAssets(int $tenantId, int $userId, array $generation, array $results, int $videoTaskId, array $videoParams = []): array
    {
        $assetIds = [];
        foreach ($results as $index => $result) {
            if (!is_array($result)) {
                continue;
            }
            $uri = (string)($result['video_uri'] ?? $result['video_url'] ?? '');
            if ($uri === '') {
                continue;
            }
            $asset = AigcShortDramaAsset::create([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'project_id' => (int)$generation['project_id'],
                'task_id' => (string)$generation['task_id'],
                'shot_id' => (string)($generation['shot_id'] ?? ''),
                'asset_type' => 'shot_video',
                'title' => '分镜视频' . ((int)$index + 1),
                'uri' => $uri,
                'cover_uri' => (string)($result['cover_uri'] ?? ''),
                'storage_scope' => (string)($result['storage_scope'] ?? 'tenant'),
                'storage_engine' => (string)($result['storage_engine'] ?? 'local'),
                'storage_domain' => (string)($result['storage_domain'] ?? ''),
                'mime_type' => 'video/mp4',
                'file_size' => (int)($result['file_size'] ?? 0),
                'width' => (int)($result['width'] ?? 0),
                'height' => (int)($result['height'] ?? 0),
                'duration' => (float)($result['duration'] ?? $videoParams['duration'] ?? 0),
                'checksum' => '',
                'meta_json' => self::jsonEncode([
                    'video_task_id' => $videoTaskId,
                    'video_result_id' => (int)($result['id'] ?? 0),
                    'prompt' => self::normalizeFinalProviderPrompt((string)($videoParams['prompt'] ?? '')),
                    'model' => (string)($videoParams['channel'] ?? ''),
                    'input_asset_ids' => (array)($videoParams['input_asset_ids'] ?? []),
                ]),
                'status' => 'ready',
                'create_time' => time(),
                'update_time' => time(),
                'delete_time' => 0,
            ]);
            $assetIds[] = (int)$asset['id'];
        }
        return $assetIds;
    }

    private static function runBgmAudioGenerationTask(int $tenantId, int $userId, array $generation, array $params, array $billing): void
    {
        $taskId = (string)$generation['task_id'];
        $projectId = (int)$generation['project_id'];
        AigcShortDramaGenerationTask::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'task_id' => $taskId,
        ])->update([
            'status' => self::STATUS_RUNNING,
            'progress' => 15,
            'provider' => 'short_drama_bgm',
            'started_at' => time(),
            'update_time' => time(),
        ]);

        try {
            $plan = self::currentProjectPlanRaw($tenantId, $userId, $projectId);
            $music = self::bgmAudioRequest($tenantId, $params, $plan);
            self::runMarketBgmAudioGenerationTask($tenantId, $userId, $generation, $params, $music, $billing);
            return;
            AigcShortDramaGenerationTask::where([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'task_id' => $taskId,
            ])->update([
                'request_json' => self::jsonEncode([
                    'params' => $params,
                    'music_prompt' => $music['prompt'],
                    'duration_seconds' => $music['duration_seconds'],
                ]),
                'progress' => 35,
                'provider' => $music['provider'],
                'update_time' => time(),
            ]);

            $asset = AigcShortDramaAsset::create([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'project_id' => $projectId,
                'task_id' => $taskId,
                'shot_id' => '',
                'asset_type' => 'bgm_audio',
                'title' => '背景音乐',
                'uri' => (string)$music['audio_uri'],
                'cover_uri' => '',
                'storage_scope' => (string)$music['storage_scope'],
                'storage_engine' => (string)$music['storage_engine'],
                'storage_domain' => (string)$music['storage_domain'],
                'mime_type' => (string)$music['mime_type'],
                'file_size' => (int)$music['file_size'],
                'width' => 0,
                'height' => 0,
                'duration' => (float)$music['duration_seconds'],
                'checksum' => '',
                'meta_json' => self::jsonEncode([
                    'prompt' => (string)$music['prompt'],
                    'provider' => (string)$music['provider'],
                    'model' => (string)$music['model'],
                    'source' => (string)$music['source'],
                ]),
                'status' => 'ready',
                'create_time' => time(),
                'update_time' => time(),
                'delete_time' => 0,
            ]);
            $assetId = (int)$asset['id'];
            AigcShortDramaGenerationTask::where([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'task_id' => $taskId,
            ])->update([
                'status' => self::STATUS_SUCCESS,
                'progress' => 100,
                'provider' => (string)$music['provider'],
                'result_json' => self::jsonEncode([
                    'asset_ids' => [$assetId],
                    'bgm_audio_asset_id' => $assetId,
                    'music_prompt' => (string)$music['prompt'],
                ]),
                'output_asset_ids' => self::jsonEncode([$assetId]),
                'billing_status' => ((float)$billing['tenant_cost_points'] > 0 || (float)$billing['user_charge_points'] > 0) ? 'deducted' : 'none',
                'finished_at' => time(),
                'update_time' => time(),
            ]);
            self::refreshProjectGenerationStatus($tenantId, $userId, $projectId);
        } catch (\Throwable $e) {
            self::failGenerationTaskWithRefund($tenantId, $userId, $generation, $billing, 'bgm_audio_failed', 'AI short drama background music generation failed', $e);
        }
    }

    private static function estimateMarketBgmAudioGenerationBilling(int $tenantId, array $params, array $plan): array
    {
        return MarketMusicAppRuntimeService::quote($tenantId, $params);
    }

    private static function marketBgmPayload(array $music, array $params): array
    {
        $nested = is_array($params['params'] ?? null) ? (array)$params['params'] : [];
        $musicPlan = is_array($params['music_plan'] ?? null)
            ? (array)$params['music_plan']
            : (is_array($nested['music_plan'] ?? null) ? (array)$nested['music_plan'] : []);
        return [
            'title' => trim((string)($musicPlan['music_title'] ?? $params['title'] ?? $nested['title'] ?? '短剧背景音乐')),
            'prompt' => (string)($music['prompt'] ?? ''),
            'genre' => trim((string)($musicPlan['style'] ?? $params['genre'] ?? $nested['genre'] ?? '')),
            'mood' => trim((string)($musicPlan['mood_curve'] ?? $params['mood'] ?? $nested['mood'] ?? '')),
            'instruments' => implode('、', self::stringList($musicPlan['instruments'] ?? [])),
            'duration' => max(5, min(600, (int)ceil((float)($music['duration_seconds'] ?? 60)))),
        ];
    }

    private static function runMarketBgmAudioGenerationTask(int $tenantId, int $userId, array $generation, array $params, array $music, array $billing): void
    {
        $taskId = (string)$generation['task_id'];
        try {
            $payload = self::marketBgmPayload($music, $params);
            AigcShortDramaGenerationTask::where([
                'tenant_id' => $tenantId, 'user_id' => $userId, 'task_id' => $taskId,
            ])->update([
                'request_json' => self::jsonEncode([
                    'params' => $params,
                    'music_prompt' => $music['prompt'],
                    'duration_seconds' => $music['duration_seconds'],
                    'music_payload' => $payload,
                ]),
                'update_time' => time(),
            ]);
            $reserve = MarketMusicAppRuntimeService::reserve($tenantId, $userId, $taskId, $params, $payload);
            AigcShortDramaGenerationTask::where([
                'tenant_id' => $tenantId, 'user_id' => $userId, 'task_id' => $taskId,
            ])->update([
                'app_task_id' => (int)$reserve['app_task_id'], 'consumption_id' => (int)$reserve['consumption_id'],
                'market_product_id' => (int)($reserve['market_snapshot']['product_id'] ?? 0), 'market_sku_id' => (int)($reserve['market_snapshot']['sku_id'] ?? 0),
                'model_json' => self::jsonEncode($reserve['market_snapshot']), 'pricing_snapshot' => self::jsonEncode($billing),
                'billing_status' => (string)($billing['settlement_mode'] ?? '') === 'actual_usage' ? 'pending_usage' : 'reserved', 'tenant_cost_points' => (float)($billing['tenant_cost_points'] ?? 0), 'user_charge_points' => (float)($billing['user_charge_points'] ?? 0),
                'provider' => 'power_market', 'progress' => 35, 'update_time' => time(),
            ]);
            $business = self::findGenerationTask($tenantId, $userId, $taskId);
            MarketMusicAppRuntimeService::linkBusinessTask((int)$reserve['app_task_id'], (int)$business['id']);
            $result = MarketMusicAppRuntimeService::submit((int)$reserve['consumption_id'], $payload);
            self::persistMarketBgmAudioTaskResult($tenantId, $userId, $taskId, $result, $music);
        } catch (\Throwable $e) {
            $current = self::findGenerationTask($tenantId, $userId, $taskId)->toArray();
            $consumptionId = (int)($current['consumption_id'] ?? 0);
            if ($consumptionId > 0) {
                MarketMusicAppRuntimeService::fail($consumptionId, $e->getMessage(), 'short_drama_bgm_failed');
            }
            self::failMarketBgmAudioGenerationTask($tenantId, $userId, $current, $e);
        }
    }

    private static function syncMarketBgmAudioGenerationTask(int $tenantId, int $userId, array $generation): void
    {
        $consumptionId = (int)($generation['consumption_id'] ?? 0);
        if ($consumptionId <= 0 || in_array((string)($generation['status'] ?? ''), [self::STATUS_FAILED, self::STATUS_CANCELED], true)
            || ((string)($generation['status'] ?? '') === self::STATUS_SUCCESS && (string)($generation['billing_status'] ?? '') !== 'pending_usage')) {
            return;
        }
        try {
            $request = self::jsonDecode((string)($generation['request_json'] ?? ''));
            $music = ['prompt' => (string)($request['music_prompt'] ?? ''), 'duration_seconds' => (float)($request['duration_seconds'] ?? 0)];
            $result = MarketMusicAppRuntimeService::refresh($consumptionId);
            self::persistMarketBgmAudioTaskResult($tenantId, $userId, (string)$generation['task_id'], $result, $music);
        } catch (\Throwable $e) {
            MarketMusicAppRuntimeService::fail($consumptionId, $e->getMessage(), 'short_drama_bgm_refresh_failed');
            self::failMarketBgmAudioGenerationTask($tenantId, $userId, $generation, $e);
        }
    }

    private static function persistMarketBgmAudioTaskResult(int $tenantId, int $userId, string $taskId, array $result, array $music): void
    {
        $generation = self::findGenerationTask($tenantId, $userId, $taskId);
        $row = $generation->toArray();
        if ((string)($result['status'] ?? '') === self::STATUS_FAILED) {
            self::failMarketBgmAudioGenerationTask($tenantId, $userId, $row, new Exception('背景音乐生成失败'));
            return;
        }
        $items = (array)($result['items'] ?? []);
        if ($items === []) {
            $generation->save(['status' => self::STATUS_RUNNING, 'progress' => 45, 'provider' => 'power_market', 'provider_task_id' => (string)($result['provider_task_id'] ?? ''), 'result_json' => self::jsonEncode(['message' => '背景音乐任务已提交，等待应用 API 返回结果']), 'update_time' => time()]);
            return;
        }
        $item = (array)$items[0];
        $item['provider_task_id'] = (string)($result['provider_task_id'] ?? '');
        $assetId = self::registerBgmMusicResultAsAsset($tenantId, $userId, $row, $music, $item, 0);
        $consumption = (int)($row['consumption_id'] ?? 0) > 0 ? AiConsumptionLog::where('id', (int)$row['consumption_id'])->findOrEmpty() : null;
        $billingStatus = !$consumption || $consumption->isEmpty()
            ? 'none'
            : ((string)$consumption['billing_status'] === 'settled' ? 'deducted' : ((string)$consumption['billing_status'] === 'pending_usage' ? 'pending_usage' : (string)$consumption['billing_status']));
        $generation->save([
            'status' => self::STATUS_SUCCESS, 'progress' => 100, 'provider' => 'power_market', 'provider_task_id' => (string)($result['provider_task_id'] ?? ''),
            'result_json' => self::jsonEncode(['market_consumption_id' => (int)$row['consumption_id'], 'asset_ids' => [$assetId], 'bgm_audio_asset_id' => $assetId, 'music_prompt' => (string)($music['prompt'] ?? '')]),
            'output_asset_ids' => self::jsonEncode([$assetId]), 'billing_status' => $billingStatus, 'finished_at' => time(), 'update_time' => time(),
        ]);
        self::refreshProjectGenerationStatus($tenantId, $userId, (int)$row['project_id']);
    }

    private static function failMarketBgmAudioGenerationTask(int $tenantId, int $userId, array $generation, \Throwable $e): void
    {
        if ($generation === []) return;
        $billingStatus = 'refunded';
        $consumptionId = (int)($generation['consumption_id'] ?? 0);
        if ($consumptionId > 0) {
            $consumption = AiConsumptionLog::where('id', $consumptionId)->findOrEmpty();
            if (!$consumption->isEmpty() && (string)$consumption['billing_status'] === 'settled') $billingStatus = 'deducted';
        }
        AigcShortDramaGenerationTask::where(['tenant_id' => $tenantId, 'user_id' => $userId, 'task_id' => (string)$generation['task_id']])->update([
            'status' => self::STATUS_FAILED, 'progress' => 0, 'billing_status' => $billingStatus, 'error_code' => 'market_bgm_failed',
            'error_msg' => self::friendlyGenerationError($e->getMessage()), 'operator_error' => mb_substr($e->getMessage(), 0, 1000, 'UTF-8'), 'finished_at' => time(), 'update_time' => time(),
        ]);
        self::refreshProjectGenerationStatus($tenantId, $userId, (int)($generation['project_id'] ?? 0));
    }

    private static function syncBgmAudioGenerationTask(int $tenantId, int $userId, array $generation): void
    {
        if (!in_array((string)($generation['status'] ?? ''), [self::STATUS_PENDING, self::STATUS_QUEUED, self::STATUS_RUNNING], true)) {
            return;
        }
        if (self::markGenerationSuccessFromExistingAssets($tenantId, $userId, $generation)) {
            return;
        }
        if ((int)($generation['consumption_id'] ?? 0) > 0) {
            self::syncMarketBgmAudioGenerationTask($tenantId, $userId, $generation);
            return;
        }
        $result = self::jsonDecode((string)($generation['result_json'] ?? ''));
        $musicTaskId = (int)($result['music_task_id'] ?? $generation['provider_task_id'] ?? 0);
        if ($musicTaskId <= 0) {
            return;
        }
        try {
            $musicTask = AigcMusicService::taskDetail($tenantId, $musicTaskId, $userId);
        } catch (\Throwable $e) {
            Log::write('AI short drama music task sync failed: ' . $e->getMessage());
            return;
        }
        $status = (string)($musicTask['status'] ?? '');
        if (self::isProviderFailedStatus($status)) {
            $billing = self::jsonDecode((string)($generation['pricing_snapshot'] ?? ''));
            self::failGenerationTaskWithRefund($tenantId, $userId, $generation, $billing, 'bgm_audio_failed', 'AI short drama background music generation failed', new Exception((string)($musicTask['error'] ?? self::SAFE_ERROR)));
            return;
        }
        if (!self::isProviderSuccessStatus($status)) {
            return;
        }
        $musicResults = (array)($musicTask['results'] ?? []);
        if (empty($musicResults) || !is_array($musicResults[0])) {
            return;
        }
        $request = self::jsonDecode((string)($generation['request_json'] ?? ''));
        $music = [
            'prompt' => (string)($request['music_prompt'] ?? ''),
            'duration_seconds' => (float)($request['duration_seconds'] ?? $musicTask['duration'] ?? 0),
        ];
        $assetId = self::registerBgmMusicResultAsAsset($tenantId, $userId, $generation, $music, (array)$musicResults[0], $musicTaskId);
        AigcShortDramaGenerationTask::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'task_id' => (string)$generation['task_id'],
        ])->update([
            'status' => self::STATUS_SUCCESS,
            'progress' => 100,
            'provider' => self::MUSIC_APP_CODE,
            'provider_task_id' => (string)$musicTaskId,
            'result_json' => self::jsonEncode([
                'music_task_id' => $musicTaskId,
                'asset_ids' => [$assetId],
                'bgm_audio_asset_id' => $assetId,
                'music_prompt' => (string)$music['prompt'],
            ]),
            'output_asset_ids' => self::jsonEncode([$assetId]),
            'billing_status' => 'none',
            'finished_at' => time(),
            'update_time' => time(),
        ]);
        self::refreshProjectGenerationStatus($tenantId, $userId, (int)$generation['project_id']);
    }

    private static function registerBgmMusicResultAsAsset(int $tenantId, int $userId, array $generation, array $music, array $result, int $musicTaskId): int
    {
        $existing = AigcShortDramaAsset::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'project_id' => (int)$generation['project_id'],
            'task_id' => (string)$generation['task_id'],
            'asset_type' => 'bgm_audio',
            'delete_time' => 0,
        ])->value('id');
        if ((int)$existing > 0) {
            return (int)$existing;
        }
        $uri = trim((string)($result['audio_uri'] ?? ''));
        $storageScope = (string)($result['storage_scope'] ?? 'tenant');
        $storageEngine = (string)($result['storage_engine'] ?? 'local');
        $storageDomain = (string)($result['storage_domain'] ?? '');
        $fileSize = (int)($result['file_size'] ?? 0);
        if ($uri === '') {
            $audioUrl = trim((string)($result['audio_url'] ?? $result['url'] ?? $result['download_url'] ?? ''));
            if ($audioUrl !== '') {
                $audioMeta = self::persistBgmAudio($audioUrl);
                $uri = (string)$audioMeta['uri'];
                $fileSize = (int)($audioMeta['file_size'] ?? 0);
                $storageScope = 'tenant';
                $storageEngine = 'local';
                $storageDomain = (string)(StorageConfigService::getEffectiveDomain($tenantId) ?: '');
            }
        }
        if ($uri === '') {
            throw new Exception('音乐应用未返回可用背景音乐');
        }
        $asset = AigcShortDramaAsset::create([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'project_id' => (int)$generation['project_id'],
            'task_id' => (string)$generation['task_id'],
            'shot_id' => '',
            'asset_type' => 'bgm_audio',
            'title' => (string)($result['title'] ?? '背景音乐'),
            'uri' => $uri,
            'cover_uri' => (string)($result['cover_uri'] ?? ''),
            'storage_scope' => $storageScope,
            'storage_engine' => $storageEngine,
            'storage_domain' => $storageDomain,
            'mime_type' => (string)($result['mime_type'] ?? self::audioMimeType($uri)),
            'file_size' => $fileSize,
            'width' => 0,
            'height' => 0,
            'duration' => (float)($result['duration'] ?? $music['duration_seconds'] ?? 0),
            'checksum' => '',
            'meta_json' => self::jsonEncode([
                'prompt' => (string)($music['prompt'] ?? ''),
                'provider' => (int)($generation['consumption_id'] ?? 0) > 0 ? 'power_market' : self::MUSIC_APP_CODE,
                'model' => (string)($result['model'] ?? ''),
                'source' => (int)($generation['consumption_id'] ?? 0) > 0 ? 'power_market_app_api' : self::MUSIC_APP_CODE,
                'music_task_id' => $musicTaskId,
                'consumption_id' => (int)($generation['consumption_id'] ?? 0),
                'music_result_id' => (int)($result['id'] ?? 0),
                'provider_task_id' => (string)($result['provider_task_id'] ?? ''),
            ]),
            'status' => 'ready',
            'create_time' => time(),
            'update_time' => time(),
            'delete_time' => 0,
        ]);
        return (int)$asset['id'];
    }

    private static function bgmAudioRequest(int $tenantId, array $params, array $plan): array
    {
        $nested = is_array($params['params'] ?? null) ? (array)$params['params'] : [];
        $musicPlan = is_array($params['music_plan'] ?? null)
            ? (array)$params['music_plan']
            : (is_array($nested['music_plan'] ?? null) ? (array)$nested['music_plan'] : (array)($plan['music_plan'] ?? []));
        $prompt = trim((string)($params['music_prompt'] ?? $nested['music_prompt'] ?? $musicPlan['global_bgm_prompt'] ?? ''));
        if ($prompt === '') {
            $prompt = (string)(self::normalizeMusicPlan([], (array)($plan['storyboard'] ?? []), (array)($plan['duration_stats'] ?? []), (array)($plan['art_style'] ?? []), (string)($plan['story_outline'] ?? ''))['global_bgm_prompt'] ?? '');
        }
        $duration = (float)($params['duration_seconds'] ?? $nested['duration_seconds'] ?? $musicPlan['duration_seconds'] ?? $plan['duration_stats']['estimated_total_seconds'] ?? 0);
        $duration = max(15, min(600, $duration > 0 ? $duration : 60));
        $audioUrl = trim((string)($params['audio_uri'] ?? $params['audio_url'] ?? $nested['audio_uri'] ?? $nested['audio_url'] ?? ''));
        $audioMeta = $audioUrl === '' ? ['uri' => '', 'file_size' => 0] : self::persistBgmAudio($audioUrl);
        return [
            'prompt' => $prompt,
            'duration_seconds' => $duration,
            'provider' => 'power_market',
            'model' => '',
            'source' => $audioUrl === '' ? 'power_market_app_api' : 'provided_audio',
            'audio_uri' => (string)$audioMeta['uri'],
            'storage_scope' => 'tenant',
            'storage_engine' => 'local',
            'storage_domain' => (string)StorageConfigService::getEffectiveDomain($tenantId),
            'mime_type' => self::audioMimeType((string)$audioMeta['uri']),
            'file_size' => (int)($audioMeta['file_size'] ?? 0),
        ];
    }

    private static function persistBgmAudio(string $audioUrl): array
    {
        $localUri = FileService::setFileUrl($audioUrl);
        if (!str_starts_with($audioUrl, 'http://') && !str_starts_with($audioUrl, 'https://')) {
            $path = self::localPublicFilePath($localUri);
            return [
                'uri' => $localUri,
                'file_size' => $path !== '' && is_file($path) ? (filesize($path) ?: 0) : 0,
            ];
        }
        $date = date('Ymd');
        $dir = public_path() . 'uploads/aigc_short_drama/' . $date . '/';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        if (!is_dir($dir) || !is_writable($dir)) {
            throw new Exception('背景音乐存储目录不可写，请检查服务器存储配置');
        }
        $path = strtolower((string)(parse_url($audioUrl, PHP_URL_PATH) ?: ''));
        $ext = pathinfo($path, PATHINFO_EXTENSION);
        if (!in_array($ext, ['mp3', 'wav', 'm4a', 'aac', 'ogg', 'flac'], true)) {
            $ext = 'mp3';
        }
        $filename = 'bgm_' . date('His') . '_' . random_int(1000, 9999) . '.' . $ext;
        $target = $dir . $filename;
        $context = stream_context_create(['http' => ['timeout' => 60], 'https' => ['timeout' => 60]]);
        $read = @fopen($audioUrl, 'rb', false, $context);
        if (!$read) {
            throw new Exception('背景音乐下载失败，请稍后重试');
        }
        $write = @fopen($target, 'wb');
        if (!$write) {
            fclose($read);
            throw new Exception('背景音乐保存失败，请稍后重试');
        }
        stream_copy_to_stream($read, $write);
        fclose($read);
        fclose($write);
        if (!is_file($target) || filesize($target) <= 0) {
            throw new Exception('背景音乐文件为空，请重新生成');
        }
        return [
            'uri' => 'uploads/aigc_short_drama/' . $date . '/' . $filename,
            'file_size' => filesize($target) ?: 0,
        ];
    }

    private static function audioMimeType(string $uri): string
    {
        $path = strtolower((string)(parse_url($uri, PHP_URL_PATH) ?: $uri));
        if (str_ends_with($path, '.wav')) {
            return 'audio/wav';
        }
        if (str_ends_with($path, '.m4a')) {
            return 'audio/mp4';
        }
        if (str_ends_with($path, '.ogg')) {
            return 'audio/ogg';
        }
        return 'audio/mpeg';
    }

    private static function runExportVideoTask(int $tenantId, int $userId, array $generation, array $params, array $billing): void
    {
        $taskId = (string)$generation['task_id'];
        $projectId = (int)$generation['project_id'];
        AigcShortDramaGenerationTask::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'task_id' => $taskId,
        ])->update([
            'status' => self::STATUS_RUNNING,
            'progress' => 20,
            'started_at' => time(),
            'update_time' => time(),
        ]);

        try {
            $assets = self::orderedShotVideoAssets($tenantId, $userId, $projectId);
            if (empty($assets)) {
                throw new Exception('暂无可导出的分镜视频，请先生成分镜视');
            }
            $ffmpeg = self::resolveFfmpegBinary();
            if ($ffmpeg === '') {
                throw new Exception('服务器未配置视频合成组件，请安装 FFmpeg 后重');
            }
            $bgmAsset = self::readyBgmAudioAsset($tenantId, $userId, $projectId);
            $nestedParams = is_array($params['params'] ?? null) ? (array)$params['params'] : [];
            $watermarkEnabled = array_key_exists('watermark_enabled', $params)
                ? (bool)$params['watermark_enabled']
                : (array_key_exists('watermark_enabled', $nestedParams) ? (bool)$nestedParams['watermark_enabled'] : true);
            $watermark = $watermarkEnabled
                ? self::normalizeExportWatermarkConfig((array)(self::publicConfig($tenantId)['export_watermark'] ?? []))
                : ['enabled' => false];
            $watermark['enabled'] = $watermarkEnabled && (bool)($watermark['enabled'] ?? true);
            $export = self::concatShotVideos($tenantId, $projectId, $assets, $ffmpeg, $bgmAsset, $watermark);
            $inputAssetIds = array_values(array_map(static fn(array $item): int => (int)$item['id'], $assets));
            if (!empty($bgmAsset)) {
                $inputAssetIds[] = (int)$bgmAsset['id'];
            }
            $asset = AigcShortDramaAsset::create([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'project_id' => $projectId,
                'task_id' => $taskId,
                'shot_id' => '',
                'asset_type' => 'final_video',
                'title' => '短剧成片',
                'uri' => $export['uri'],
                'cover_uri' => '',
                'storage_scope' => (string)(StorageConfigService::getEffectiveConfig($tenantId)['scope'] ?? 'platform'),
                'storage_engine' => 'local',
                'storage_domain' => StorageConfigService::getEffectiveDomain($tenantId),
                'mime_type' => 'video/mp4',
                'file_size' => (int)($export['file_size'] ?? 0),
                'width' => 0,
                'height' => 0,
                'duration' => (float)array_sum(array_map(static fn(array $item): float => (float)($item['duration'] ?? 0), $assets)),
                'checksum' => (string)($export['checksum'] ?? ''),
                'meta_json' => self::jsonEncode([
                    'source_asset_ids' => $inputAssetIds,
                    'bgm_asset_id' => (int)($bgmAsset['id'] ?? 0),
                    'bgm_volume' => empty($bgmAsset) ? 0 : 0.18,
                    'ffmpeg' => basename($ffmpeg),
                    'watermark_enabled' => $watermarkEnabled,
                ]),
                'status' => 'ready',
                'create_time' => time(),
                'update_time' => time(),
                'delete_time' => 0,
            ]);
            $assetId = (int)$asset['id'];
            AigcShortDramaProject::where([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'id' => $projectId,
            ])->update([
                'timeline_json' => self::jsonEncode([
                    'type' => empty($bgmAsset) ? 'ffmpeg_concat' : 'ffmpeg_concat_with_bgm',
                    'asset_ids' => $inputAssetIds,
                    'final_asset_id' => $assetId,
                    'bgm_asset_id' => (int)($bgmAsset['id'] ?? 0),
                ]),
                'final_video_asset_id' => $assetId,
                'status' => self::PROJECT_STATUS_PUBLISH_REVIEWING,
                'update_time' => time(),
            ]);
            AigcShortDramaGenerationTask::where([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'task_id' => $taskId,
            ])->update([
                'status' => self::STATUS_SUCCESS,
                'progress' => 100,
                'provider' => 'ffmpeg',
                'result_json' => self::jsonEncode([
                    'asset_ids' => [$assetId],
                    'final_video_asset_id' => $assetId,
                    'bgm_audio_asset_id' => (int)($bgmAsset['id'] ?? 0),
                ]),
                'input_asset_ids' => self::jsonEncode($inputAssetIds),
                'output_asset_ids' => self::jsonEncode([$assetId]),
                'billing_status' => ((float)$billing['tenant_cost_points'] > 0 || (float)$billing['user_charge_points'] > 0) ? 'deducted' : 'none',
                'finished_at' => time(),
                'update_time' => time(),
            ]);
            self::refreshProjectGenerationStatus($tenantId, $userId, (int)$generation['project_id']);
        } catch (\Throwable $e) {
            self::failGenerationTaskWithRefund($tenantId, $userId, $generation, $billing, 'export_video_failed', 'AI short drama export failed', $e);
        }
    }

    private static function runExportPackageTask(int $tenantId, int $userId, array $generation, array $params, array $billing): void
    {
        $taskId = (string)$generation['task_id'];
        $projectId = (int)$generation['project_id'];
        AigcShortDramaGenerationTask::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'task_id' => $taskId,
        ])->update([
            'status' => self::STATUS_RUNNING,
            'progress' => 20,
            'started_at' => time(),
            'update_time' => time(),
        ]);

        $workDir = runtime_path() . 'short_drama_export_package_' . $tenantId . '_' . $projectId . '_' . time() . DIRECTORY_SEPARATOR;
        try {
            if (!class_exists(\ZipArchive::class)) {
                throw new Exception('服务器未启用 ZIP 打包组件，请安装 ZipArchive 后重');
            }
            if (!is_dir($workDir)) {
                @mkdir($workDir, 0775, true);
            }
            if (!is_dir($workDir) || !is_writable($workDir)) {
                throw new Exception('导出缓存目录不可写，请检查服务器存储配置');
            }
            $assets = self::orderedShotVideoAssets($tenantId, $userId, $projectId);
            if (empty($assets)) {
                throw new Exception('暂无可导出的分镜素材，请先生成或选择素材');
            }
            $bgmAsset = self::readyBgmAudioAsset($tenantId, $userId, $projectId);
            $nestedParams = is_array($params['params'] ?? null) ? (array)$params['params'] : [];
            $watermarkEnabled = array_key_exists('watermark_enabled', $params)
                ? (bool)$params['watermark_enabled']
                : (array_key_exists('watermark_enabled', $nestedParams) ? (bool)$nestedParams['watermark_enabled'] : true);
            $zipPath = $workDir . 'short_drama_shots_' . $projectId . '_' . date('His') . '_' . random_int(1000, 9999) . '.zip';
            $zip = new \ZipArchive();
            if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                throw new Exception('导出压缩包创建失败，请稍后重');
            }
            $manifestShots = [];
            $inputAssetIds = [];
            foreach ($assets as $index => $asset) {
                $inputAssetIds[] = (int)$asset['id'];
                $localPath = self::assetLocalOrDownloadedPath($asset, $workDir, (int)$index + 1);
                $ext = self::exportAssetExtension($asset, $localPath);
                $zipName = sprintf('shots/%03d_%s.%s', (int)$index + 1, self::safeZipSegment((string)($asset['timeline_shot_id'] ?? $asset['shot_id'] ?? $asset['id'])), $ext);
                $zip->addFile($localPath, $zipName);
                $manifestShots[] = [
                    'index' => (int)$index + 1,
                    'shot_id' => (string)($asset['timeline_shot_id'] ?? $asset['shot_id'] ?? ''),
                    'asset_id' => (int)$asset['id'],
                    'asset_type' => (string)($asset['asset_type'] ?? ''),
                    'file' => $zipName,
                    'duration' => (float)($asset['duration'] ?? 0),
                ];
            }
            $bgmZipName = '';
            if (!empty($bgmAsset)) {
                $inputAssetIds[] = (int)$bgmAsset['id'];
                $bgmPath = self::assetLocalOrDownloadedPath($bgmAsset, $workDir, 0);
                $bgmZipName = 'bgm/background_music.' . self::exportAssetExtension($bgmAsset, $bgmPath);
                $zip->addFile($bgmPath, $bgmZipName);
            }
            $manifest = [
                'project_id' => $projectId,
                'source_task_id' => (string)($generation['source_task_id'] ?? ''),
                'generation_task_id' => $taskId,
                'watermark_enabled' => $watermarkEnabled,
                'generated_at' => date('c'),
                'shots' => $manifestShots,
                'bgm' => $bgmZipName,
            ];
            $zip->addFromString('manifest.json', self::jsonEncode($manifest));
            if (!$zip->close()) {
                throw new Exception('导出压缩包写入失败，请稍后重');
            }
            if (!is_file($zipPath) || filesize($zipPath) <= 0) {
                throw new Exception('导出压缩包为空，请稍后重');
            }
            $stored = self::storeInternalAssetFile($tenantId, $zipPath, 'uploads/aigc_short_drama/exports/' . date('Ymd'));
            $asset = AigcShortDramaAsset::create([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'project_id' => $projectId,
                'task_id' => $taskId,
                'shot_id' => '',
                'asset_type' => 'export_package',
                'title' => '短剧分镜素材',
                'uri' => (string)$stored['uri'],
                'cover_uri' => '',
                'storage_scope' => (string)$stored['storage_scope'],
                'storage_engine' => (string)$stored['storage_engine'],
                'storage_domain' => (string)$stored['storage_domain'],
                'mime_type' => 'application/zip',
                'file_size' => (int)$stored['file_size'],
                'width' => 0,
                'height' => 0,
                'duration' => (float)array_sum(array_map(static fn(array $item): float => (float)($item['duration'] ?? 0), $assets)),
                'checksum' => hash_file('sha256', $zipPath) ?: '',
                'meta_json' => self::jsonEncode([
                    'source_asset_ids' => $inputAssetIds,
                    'bgm_asset_id' => (int)($bgmAsset['id'] ?? 0),
                    'watermark_enabled' => $watermarkEnabled,
                    'manifest' => $manifest,
                ]),
                'status' => 'ready',
                'create_time' => time(),
                'update_time' => time(),
                'delete_time' => 0,
            ]);
            $assetId = (int)$asset['id'];
            AigcShortDramaGenerationTask::where([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'task_id' => $taskId,
            ])->update([
                'status' => self::STATUS_SUCCESS,
                'progress' => 100,
                'provider' => 'zip',
                'result_json' => self::jsonEncode([
                    'asset_ids' => [$assetId],
                    'export_package_asset_id' => $assetId,
                    'bgm_audio_asset_id' => (int)($bgmAsset['id'] ?? 0),
                ]),
                'input_asset_ids' => self::jsonEncode($inputAssetIds),
                'output_asset_ids' => self::jsonEncode([$assetId]),
                'billing_status' => ((float)$billing['tenant_cost_points'] > 0 || (float)$billing['user_charge_points'] > 0) ? 'deducted' : 'none',
                'finished_at' => time(),
                'update_time' => time(),
            ]);
            self::refreshProjectGenerationStatus($tenantId, $userId, $projectId);
        } catch (\Throwable $e) {
            self::failGenerationTaskWithRefund($tenantId, $userId, $generation, $billing, 'export_package_failed', 'AI short drama package export failed', $e);
        } finally {
            self::removeRuntimeDirectory($workDir);
        }
    }

    private static function failGenerationTaskWithRefund(int $tenantId, int $userId, array $generation, array $billing, string $errorCode, string $refundTitle, \Throwable $e): void
    {
        Db::startTrans();
        try {
            $taskId = (string)$generation['task_id'];
            $refundStatus = ((float)($billing['tenant_cost_points'] ?? 0) > 0 || (float)($billing['user_charge_points'] ?? 0) > 0) ? 'refunded' : 'none';
            if ((string)($generation['billing_status'] ?? '') === 'reserved') {
                PointService::refundBusinessAmountsInCurrentTransaction($tenantId, $userId, (float)($billing['tenant_cost_points'] ?? 0), (float)($billing['user_charge_points'] ?? 0), $taskId . '-refund', $refundTitle, [
                    'app_code' => self::APP_CODE,
                    'task_id' => $taskId,
                    'project_id' => (int)($generation['project_id'] ?? 0),
                ]);
            }
            AigcShortDramaGenerationTask::where([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'task_id' => $taskId,
            ])->update([
                'status' => self::STATUS_FAILED,
                'progress' => 0,
                'billing_status' => $refundStatus,
                'error_code' => $errorCode,
                'error_msg' => self::friendlyGenerationError($e->getMessage()),
                'operator_error' => mb_substr($e->getMessage() ?: self::SAFE_ERROR, 0, 1000, 'UTF-8'),
                'finished_at' => time(),
                'update_time' => time(),
            ]);
            Db::commit();
            self::refreshProjectGenerationStatus($tenantId, $userId, (int)($generation['project_id'] ?? 0));
        } catch (\Throwable $rollbackError) {
            Db::rollback();
            Log::write('AI short drama generation rollback failed: ' . $rollbackError->getMessage());
        }
        Log::write('AI short drama generation task failed: ' . $e->getMessage());
    }

    private static function shortDramaVideoParams(int $tenantId, int $userId, int $projectId, array $shot, array $params): array
    {
        $params = self::normalizeShortDramaVideoChannelParams($tenantId, $params);
        $plan = self::currentProjectPlanRaw($tenantId, $userId, $projectId);
        $projectRatio = (string)AigcShortDramaProject::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'id' => $projectId,
            'delete_time' => 0,
        ])->value('ratio');
        $ratioSource = self::normalizeGenerationRatio($projectRatio)
            ?: self::requestGenerationRatio($params)
            ?: self::normalizeGenerationRatio((string)($plan['generation_settings']['aspect_ratio'] ?? $plan['generation_settings']['ratio'] ?? ''))
            ?: '9:16';
        $params['ratio'] = trim($ratioSource) ?: '9:16';
        $params['aspect_ratio'] = $params['ratio'];
        $params = self::prepareShortDramaVideoGenerationParams($tenantId, ['ratio' => $projectRatio], $shot, $params);
        $channel = trim((string)($params['model_id'] ?? $params['channel'] ?? $params['video_model_id'] ?? ''));
        $ratio = trim((string)($params['resolved_ratio'] ?? $params['ratio'] ?? '')) ?: '9:16';
        $explicitReferences = self::generationInputReferenceAssets($tenantId, $userId, $projectId, $params, $shot);
        $explicitReferences = self::limitShortDramaVideoReferences($tenantId, $channel, $params, $shot, $explicitReferences);
        // 视频生成只接收本次首尾帧与用 @ 指定参考；不自动提交主体、场景、三视图等辅助图。
        $references = $explicitReferences;
        $requestedDuration = max(3, min(15, (int)round((float)($params['duration'] ?? $shot['recommended_duration_seconds'] ?? 5))));
        $duration = self::normalizeShortDramaVideoDuration($tenantId, $channel, (array)$references['reference_assets'], $requestedDuration);
        $videoPromptParams = array_merge($params, [
            'duration' => $duration,
            'has_first_frame_image' => !empty($explicitReferences['first_frame_image']),
            'has_last_frame_image' => !empty($explicitReferences['last_frame_image']),
            'reference_assets' => (array)($references['reference_assets'] ?? []),
            'input_asset_ids' => (array)($references['input_asset_ids'] ?? []),
            'first_frame_image' => (string)($references['first_frame_image'] ?? ''),
            'last_frame_image' => (string)($references['last_frame_image'] ?? ''),
        ]);
        $prompt = self::buildShotVideoPrompt($shot, $videoPromptParams, $plan);
        $prompt = self::normalizeFinalProviderPrompt($prompt);
        $videoParams = [
            'prompt' => $prompt,
            'negative_prompt' => self::shotVideoNegativePrompt(self::mergeShotReferenceContext($shot, $params), self::isNoSubjectShot(self::mergeShotReferenceContext($shot, $params))),
            'style' => 'general',
            'channel' => $channel,
            'ratio' => $ratio,
            'requested_ratio' => (string)($params['requested_ratio'] ?? $ratioSource),
            'resolved_ratio' => $ratio,
            'ratio_fallback' => $params['ratio_fallback'] ?? [],
            'duration' => $duration,
            'quantity' => 1,
            'reference_assets' => $references['reference_assets'],
            // Keep video references in the structured list only. The generic video
            // service also reads legacy image fields, which can double-count refs.
            'reference_images' => [],
            'input_asset_ids' => $references['input_asset_ids'],
        ];
        $quality = trim((string)($params['quality'] ?? ''));
        if ($quality !== '') {
            $videoParams['quality'] = $quality;
        }
        return $videoParams;
    }

    private static function prepareShortDramaVideoGenerationParams(int $tenantId, array $project, array $shot, array $params): array
    {
        $params = self::normalizeShortDramaVideoChannelParams($tenantId, $params);
        $channel = trim((string)($params['model_id'] ?? $params['channel'] ?? $params['video_model_id'] ?? ''));
        if ($channel === '') {
            $videoConfig = AigcVideoChannelService::userConfig($tenantId);
            $channel = (string)($videoConfig['defaults']['channel'] ?? '');
            foreach (['model_id', 'video_model_id', 'channel', 'channel_code'] as $key) {
                $params[$key] = $channel;
            }
        }
        $projectRatio = self::normalizeGenerationRatio((string)($project['ratio'] ?? ''));
        $requestedRatio = $projectRatio ?: self::requestGenerationRatio($params);
        if ($requestedRatio === '') {
            $requestedRatio = '9:16';
        }
        $requestedDuration = max(3, min(15, (int)round((float)($params['duration'] ?? $shot['recommended_duration_seconds'] ?? 5))));
        $duration = self::normalizeShortDramaVideoDuration($tenantId, $channel, [], $requestedDuration);
        $selection = AigcVideoChannelService::resolveNearestCompatibleRatioSelection($tenantId, array_merge($params, [
            'channel' => $channel,
            'ratio' => $requestedRatio,
            'duration' => $duration,
        ]));
        $resolvedRatio = trim((string)($selection['resolved_ratio'] ?? '')) ?: $requestedRatio;
        $fallbackApplied = !empty($selection['ratio_fallback']);
        $fallback = [
            'applied' => $fallbackApplied,
            'reason' => $fallbackApplied ? 'closest_supported_ratio' : '',
            'message' => $fallbackApplied
                ? sprintf('当前模型不支持 %s 的 %d 秒视频，已自动按最接近的 %s 生成。', $requestedRatio, $duration, $resolvedRatio)
                : '',
        ];

        $params['duration'] = $duration;
        $params['ratio'] = $resolvedRatio;
        $params['aspect_ratio'] = $resolvedRatio;
        $params['requested_ratio'] = $requestedRatio;
        $params['resolved_ratio'] = $resolvedRatio;
        $params['ratio_fallback'] = $fallback;
        if (is_array($params['params'] ?? null)) {
            $params['params']['duration'] = $duration;
            $params['params']['ratio'] = $resolvedRatio;
            $params['params']['aspect_ratio'] = $resolvedRatio;
            $params['params']['requested_ratio'] = $requestedRatio;
            $params['params']['resolved_ratio'] = $resolvedRatio;
            $params['params']['ratio_fallback'] = $fallback;
        }
        return $params;
    }

    private static function shortDramaVideoEffectiveParams(array $params): array
    {
        $fallback = is_array($params['ratio_fallback'] ?? null) ? $params['ratio_fallback'] : [];
        return [
            'channel' => (string)($params['channel'] ?? $params['model_id'] ?? $params['video_model_id'] ?? ''),
            'quality' => (string)($params['quality'] ?? $params['resolution'] ?? ''),
            'duration' => (int)($params['duration'] ?? 0),
            'requested_ratio' => (string)($params['requested_ratio'] ?? $params['ratio'] ?? ''),
            'resolved_ratio' => (string)($params['resolved_ratio'] ?? $params['ratio'] ?? ''),
            'ratio_fallback' => !empty($fallback['applied']),
            'fallback_message' => (string)($fallback['message'] ?? ''),
        ];
    }

    private static function normalizeShortDramaVideoDuration(int $tenantId, string $channelCode, array $referenceAssets, int $requestedDuration): int
    {
        $duration = max(1, $requestedDuration);
        try {
            $config = AigcVideoChannelService::userConfig($tenantId);
            if ($channelCode === '') {
                $channelCode = (string)($config['defaults']['channel'] ?? '');
            }
            foreach ((array)($config['channels'] ?? []) as $channel) {
                if ((string)($channel['code'] ?? '') !== $channelCode) {
                    continue;
                }
                $options = AigcVideoChannelService::durationOptionsForAssets((array)$channel, $referenceAssets);
                if (empty($options) || in_array($duration, $options, true)) {
                    return $duration;
                }
                $options = array_values(array_unique(array_filter(array_map('intval', $options))));
                sort($options);
                if (empty($options)) {
                    return $duration;
                }
                $closest = (int)$options[0];
                foreach ($options as $option) {
                    $option = (int)$option;
                    $currentDiff = abs($option - $duration);
                    $closestDiff = abs($closest - $duration);
                    if ($currentDiff < $closestDiff || ($currentDiff === $closestDiff && $option > $closest)) {
                        $closest = $option;
                    }
                }
                return $closest;
            }
        } catch (\Throwable) {
            return max(3, min(15, $duration));
        }
        return $duration;
    }

    private static function videoReferenceImageLimit(int $tenantId, string $channelCode): int
    {
        $limit = max(1, AigcVideoChannelService::DEFAULT_REFERENCE_LIMIT);
        try {
            $config = AigcVideoChannelService::userConfig($tenantId);
            if ($channelCode === '') {
                $channelCode = (string)($config['defaults']['channel'] ?? '');
            }
            foreach ((array)($config['channels'] ?? []) as $channel) {
                if ((string)($channel['code'] ?? '') !== $channelCode) {
                    continue;
                }
                $imageLimit = max(0, (int)($channel['max_reference_images'] ?? 0));
                $assetLimit = max(0, (int)($channel['max_reference_assets'] ?? 0));
                if ($imageLimit > 0 && $assetLimit > 0) {
                    return max(1, min($imageLimit, $assetLimit));
                }
                if ($imageLimit > 0) {
                    return max(1, $imageLimit);
                }
                if ($assetLimit > 0) {
                    return max(1, $assetLimit);
                }
                return $limit;
            }
        } catch (\Throwable) {
            return $limit;
        }
        return $limit;
    }

    private static function imageReferenceImageLimit(int $tenantId, array $imageParams): int
    {
        $limit = max(1, AigcImageChannelService::DEFAULT_REFERENCE_LIMIT);
        try {
            $selection = AigcImageChannelService::resolveSelection($tenantId, $imageParams);
            $channelLimit = max(0, (int)($selection['channel']['max_reference_images'] ?? 0));
            return $channelLimit > 0 ? $channelLimit : $limit;
        } catch (\Throwable) {
            return $limit;
        }
    }

    private static function limitShortDramaImageReferences(int $tenantId, array $imageParams, array $params, array $shot, array $payload): array
    {
        $limit = self::imageReferenceImageLimit($tenantId, $imageParams);
        $assets = array_values((array)($payload['reference_assets'] ?? []));
        if ($limit <= 0 || count($assets) <= $limit) {
            return $payload;
        }

        $nested = is_array($params['params'] ?? null) ? (array)$params['params'] : [];
        $inputIds = array_values(array_map('intval', (array)($payload['input_asset_ids'] ?? [])));
        $explicitReferenceIds = array_flip(array_map('intval', array_merge(
            self::normalizeIdList($params['input_asset_ids'] ?? []),
            self::normalizeIdList($nested['input_asset_ids'] ?? []),
            self::normalizeIdList($params['reference_asset_ids'] ?? []),
            self::normalizeIdList($nested['reference_asset_ids'] ?? [])
        )));
        $selectedSubjectIds = array_flip(array_map('strval', array_merge(
            self::normalizeStringList($params['selected_subject_ids'] ?? []),
            self::normalizeStringList($nested['selected_subject_ids'] ?? [])
        )));
        $selectedSceneIds = array_flip(array_map('strval', array_merge(
            self::normalizeStringList($params['selected_scene_ids'] ?? []),
            self::normalizeStringList($nested['selected_scene_ids'] ?? [])
        )));
        $shotContext = self::mergeShotReferenceContext($shot, $params);
        $shotSceneId = (string)($shotContext['scene_ref_id'] ?? $shotContext['scene_ref'] ?? $shotContext['location_id'] ?? '');
        $shotSubjectIds = array_flip(array_map('strval', self::explicitShotSubjectRefTokens($shotContext)));

        $items = [];
        foreach ($assets as $index => $asset) {
            if (!is_array($asset)) {
                continue;
            }
            $id = (int)($asset['id'] ?? ($inputIds[$index] ?? 0));
            if ($id > 0 && empty($asset['id'])) {
                $asset['id'] = $id;
            }
            $items[] = [
                'asset' => $asset,
                'id' => $id,
                'index' => $index,
                'group' => self::shortDramaReferenceGroupKey($asset),
                'priority' => self::shortDramaImageReferencePriority(
                    $asset,
                    $id,
                    $explicitReferenceIds,
                    $selectedSubjectIds,
                    $selectedSceneIds,
                    $shotSceneId,
                    $shotSubjectIds
                ),
            ];
        }
        usort($items, static function (array $a, array $b): int {
            if ($a['priority'] !== $b['priority']) {
                return $a['priority'] <=> $b['priority'];
            }
            return $a['index'] <=> $b['index'];
        });

        $uniqueItems = [];
        $seenGroups = [];
        foreach ($items as $item) {
            $group = (string)$item['group'];
            if ($group !== '' && isset($seenGroups[$group])) {
                continue;
            }
            if ($group !== '') {
                $seenGroups[$group] = true;
            }
            $uniqueItems[] = $item;
        }

        $limited = self::emptyReferencePayload();
        foreach (array_slice($uniqueItems, 0, $limit) as $item) {
            self::appendReferenceAsset($limited, (array)$item['asset']);
        }
        return $limited;
    }

    private static function shortDramaImageReferencePriority(array $asset, int $id, array $explicitReferenceIds, array $selectedSubjectIds, array $selectedSceneIds, string $shotSceneId, array $shotSubjectIds): int
    {
        $assetType = (string)($asset['asset_type'] ?? '');
        $meta = (array)($asset['meta'] ?? []);
        $subjectId = (string)($meta['subject_id'] ?? $meta['subject_ref_id'] ?? $meta['character_id'] ?? $meta['item_id'] ?? '');
        $sceneId = (string)($meta['scene_id'] ?? $meta['scene_ref_id'] ?? $meta['location_id'] ?? $meta['item_id'] ?? '');
        if ($id > 0 && isset($explicitReferenceIds[$id])) {
            return 10;
        }
        if (($assetType === 'scene_image' || $sceneId !== '') && $sceneId !== '') {
            if (isset($selectedSceneIds[$sceneId])) {
                return 30;
            }
            if ($sceneId === $shotSceneId) {
                return 35;
            }
            return 95;
        }
        if ($subjectId !== '') {
            $matchedSubject = isset($selectedSubjectIds[$subjectId]) || isset($shotSubjectIds[$subjectId]);
            if ($assetType === 'three_view') {
                return $matchedSubject ? 40 : 80;
            }
            if ($assetType === 'subject_image') {
                return $matchedSubject ? 60 : 90;
            }
        }
        if ($assetType === 'three_view') {
            return 85;
        }
        if ($assetType === 'subject_image') {
            return 92;
        }
        return $assetType === 'reference_image' ? 20 : 100;
    }

    private static function shortDramaReferenceGroupKey(array $asset): string
    {
        $assetType = (string)($asset['asset_type'] ?? '');
        $meta = (array)($asset['meta'] ?? []);
        $subjectId = trim((string)($meta['subject_id'] ?? $meta['subject_ref_id'] ?? $meta['character_id'] ?? ''));
        if ($subjectId === '' && in_array($assetType, ['subject_image', 'three_view'], true)) {
            $subjectId = trim((string)($meta['item_id'] ?? ''));
        }
        if ($subjectId !== '') {
            return 'subject:' . $subjectId;
        }
        $sceneId = trim((string)($meta['scene_id'] ?? $meta['scene_ref_id'] ?? $meta['location_id'] ?? ''));
        if ($sceneId === '' && $assetType === 'scene_image') {
            $sceneId = trim((string)($meta['item_id'] ?? ''));
        }
        if ($sceneId !== '') {
            return 'scene:' . $sceneId;
        }
        return '';
    }

    private static function limitShortDramaVideoReferences(int $tenantId, string $channelCode, array $params, array $shot, array $payload): array
    {
        $limit = self::videoReferenceImageLimit($tenantId, $channelCode);
        $assets = array_values((array)($payload['reference_assets'] ?? []));
        if ($limit <= 0 || count($assets) <= $limit) {
            return $payload;
        }
        $nested = is_array($params['params'] ?? null) ? (array)$params['params'] : [];
        $inputIds = array_values(array_map('intval', (array)($payload['input_asset_ids'] ?? [])));
        $firstFrameId = (int)($params['first_frame_asset_id'] ?? $nested['first_frame_asset_id'] ?? 0);
        $lastFrameId = (int)($params['last_frame_asset_id'] ?? $nested['last_frame_asset_id'] ?? 0);
        $explicitReferenceIds = array_flip(array_map('intval', array_merge(
            self::normalizeIdList($params['reference_asset_ids'] ?? []),
            self::normalizeIdList($nested['reference_asset_ids'] ?? [])
        )));
        $selectedSubjectIds = array_flip(array_map('strval', array_merge(
            self::normalizeStringList($params['selected_subject_ids'] ?? []),
            self::normalizeStringList($nested['selected_subject_ids'] ?? [])
        )));
        $selectedSceneIds = array_flip(array_map('strval', array_merge(
            self::normalizeStringList($params['selected_scene_ids'] ?? []),
            self::normalizeStringList($nested['selected_scene_ids'] ?? [])
        )));
        $shotContext = self::mergeShotReferenceContext($shot, $params);
        $shotSceneId = (string)($shotContext['scene_ref_id'] ?? $shotContext['scene_ref'] ?? $shotContext['location_id'] ?? '');
        $shotSubjectIds = array_flip(array_map('strval', self::explicitShotSubjectRefTokens($shotContext)));
        $items = [];
        foreach ($assets as $index => $asset) {
            if (!is_array($asset)) {
                continue;
            }
            $id = (int)($asset['id'] ?? ($inputIds[$index] ?? 0));
            if ($id > 0 && empty($asset['id'])) {
                $asset['id'] = $id;
            }
            $items[] = [
                'asset' => $asset,
                'id' => $id,
                'index' => $index,
                'priority' => self::shortDramaVideoReferencePriority(
                    $asset,
                    $id,
                    $firstFrameId,
                    $lastFrameId,
                    $explicitReferenceIds,
                    $selectedSubjectIds,
                    $selectedSceneIds,
                    $shotSceneId,
                    $shotSubjectIds
                ),
            ];
        }
        usort($items, static function (array $a, array $b): int {
            if ($a['priority'] !== $b['priority']) {
                return $a['priority'] <=> $b['priority'];
            }
            return $a['index'] <=> $b['index'];
        });
        $limited = self::emptyReferencePayload();
        foreach (array_slice($items, 0, $limit) as $item) {
            self::appendReferenceAsset($limited, (array)$item['asset']);
        }
        $keptIds = array_flip(array_map('intval', (array)$limited['input_asset_ids']));
        if ($firstFrameId > 0 && isset($keptIds[$firstFrameId]) && !empty($payload['first_frame_image'])) {
            $limited['first_frame_image'] = (string)$payload['first_frame_image'];
        }
        if ($lastFrameId > 0 && isset($keptIds[$lastFrameId]) && !empty($payload['last_frame_image'])) {
            $limited['last_frame_image'] = (string)$payload['last_frame_image'];
        }
        return $limited;
    }

    private static function shortDramaVideoReferencePriority(array $asset, int $id, int $firstFrameId, int $lastFrameId, array $explicitReferenceIds, array $selectedSubjectIds, array $selectedSceneIds, string $shotSceneId, array $shotSubjectIds): int
    {
        if ($id > 0 && $id === $firstFrameId) {
            return 10;
        }
        if ($id > 0 && $id === $lastFrameId) {
            return 20;
        }
        $assetType = (string)($asset['asset_type'] ?? '');
        $meta = (array)($asset['meta'] ?? []);
        $subjectId = (string)($meta['subject_id'] ?? $meta['subject_ref_id'] ?? $meta['character_id'] ?? $meta['item_id'] ?? '');
        $sceneId = (string)($meta['scene_id'] ?? $meta['scene_ref_id'] ?? $meta['location_id'] ?? $meta['item_id'] ?? '');
        $isExplicit = ($id > 0 && isset($explicitReferenceIds[$id]))
            || ($subjectId !== '' && isset($selectedSubjectIds[$subjectId]))
            || ($sceneId !== '' && isset($selectedSceneIds[$sceneId]));
        if ($isExplicit) {
            if ($assetType === 'three_view') {
                return 30;
            }
            if ($assetType === 'subject_image' || $subjectId !== '') {
                return 31;
            }
            return ($assetType === 'scene_image' || $sceneId !== '') ? 32 : 33;
        }
        if ($subjectId !== '' && isset($shotSubjectIds[$subjectId])) {
            return $assetType === 'three_view' ? 60 : 70;
        }
        if ($sceneId !== '' && $sceneId === $shotSceneId) {
            return 80;
        }
        if ($assetType === 'three_view') {
            return 90;
        }
        if ($assetType === 'subject_image') {
            return 95;
        }
        return $assetType === 'scene_image' ? 100 : 98;
    }

    private static function mergeShotReferenceContext(array $shot, array $params): array
    {
        $nested = is_array($params['params'] ?? null) ? (array)$params['params'] : [];
        $context = array_merge($nested, $params);
        foreach ($shot as $key => $value) {
            $empty = $value === null || $value === '' || $value === [] || $value === '[]';
            if (!$empty) {
                $context[$key] = $value;
            }
        }
        return $context;
    }

    private static function explicitShotSubjectRefTokens(array $shot): array
    {
        $tokens = self::splitPlanRefTokens($shot['subject_ref_ids'] ?? $shot['subject_refs'] ?? $shot['character_ids'] ?? []);
        $tokens = array_merge($tokens, self::selectedSubjectRefTokens($shot));
        return array_values(array_filter($tokens, static function (string $token): bool {
            $token = trim($token);
            return $token !== '' && !in_array(mb_strtolower($token, 'UTF-8'), ['[]', 'null', 'none', '', '无人', '无人'], true);
        }));
    }

    private static function selectedSubjectRefTokens(array $shot): array
    {
        $nested = is_array($shot['params'] ?? null) ? (array)$shot['params'] : [];
        $tokens = array_merge(
            self::normalizeStringList($shot['selected_subject_ids'] ?? []),
            self::normalizeStringList($nested['selected_subject_ids'] ?? [])
        );
        foreach (array_merge(
            is_array($shot['selected_mentions'] ?? null) ? (array)$shot['selected_mentions'] : [],
            is_array($nested['selected_mentions'] ?? null) ? (array)$nested['selected_mentions'] : []
        ) as $mention) {
            if (!is_array($mention) || (string)($mention['type'] ?? '') !== 'subject') {
                continue;
            }
            $id = trim((string)($mention['id'] ?? ''));
            if ($id !== '') {
                $tokens[] = $id;
            }
        }
        return array_values(array_unique(array_filter(array_map('strval', $tokens))));
    }

    private static function isNoSubjectShot(array $shot): bool
    {
        if (!empty(self::selectedSubjectRefTokens($shot))) {
            return false;
        }
        $shotType = trim((string)($shot['shot_type'] ?? $shot['frame_type'] ?? $shot['type'] ?? ''));
        if ($shotType !== '' && self::shotTypeForcesNoSubject($shotType)) {
            return true;
        }
        if (self::isPropOnlyShot($shot)) {
            return true;
        }
        return empty(self::explicitShotSubjectRefTokens($shot));
    }

    private static function shotTypeForcesNoSubject(string $shotType): bool
    {
        return self::containsAnyKeyword($shotType, ['空镜', '转场镜头', '转场', '环境镜头', '场景镜头']);
    }

    private static function isPropOnlyShot(array $shot): bool
    {
        $shotType = trim((string)($shot['shot_type'] ?? $shot['frame_type'] ?? $shot['type'] ?? ''));
        if (!self::containsAnyKeyword($shotType, ['道具', '物品', '物件', '细节', '关键特写'])) {
            return false;
        }
        $visibleText = self::joinPromptParts([
            (string)($shot['image_prompt'] ?? ''),
            (string)($shot['visual_description'] ?? $shot['description'] ?? ''),
            (string)($shot['action'] ?? ''),
            (string)($shot['composition'] ?? ''),
        ]);
        return trim($visibleText) !== '' && !self::shotTextImpliesVisibleSubject($visibleText);
    }

    private static function shotTextImpliesVisibleSubject(string $text): bool
    {
        if (trim($text) === '') {
            return false;
        }
        return preg_match('/(@\S+|人物|角色|人像|肖像|男人|女人|男生|女生|女孩|男孩|老人|孩子|母亲|父亲|妈妈|爸爸|学生|老师|医生|队员|骑手|船员|脸|面部|身体|手|眼神|头发|服装|穿着|青蛙人|怪人|人形|person|people|human|character|face|hand|body)/iu', $text) === 1;
    }

    private static function filterNoSubjectReferencePayload(array $payload): array
    {
        $filtered = self::emptyReferencePayload();
        $ids = array_values(array_map('intval', (array)($payload['input_asset_ids'] ?? [])));
        foreach ((array)($payload['reference_assets'] ?? []) as $index => $asset) {
            if (!is_array($asset) || (string)($asset['asset_type'] ?? '') !== 'scene_image') {
                continue;
            }
            if (!empty($ids[$index]) && empty($asset['id'])) {
                $asset['id'] = $ids[$index];
            }
            self::appendReferenceAsset($filtered, $asset);
        }
        return $filtered;
    }

    private static function filterReferencePayloadByAssetTypes(array $payload, array $assetTypes): array
    {
        $allowed = array_flip(array_map('strval', $assetTypes));
        $filtered = self::emptyReferencePayload();
        $ids = array_values(array_map('intval', (array)($payload['input_asset_ids'] ?? [])));
        foreach ((array)($payload['reference_assets'] ?? []) as $index => $asset) {
            if (!is_array($asset)) {
                continue;
            }
            $assetType = (string)($asset['asset_type'] ?? '');
            if (!isset($allowed[$assetType])) {
                continue;
            }
            if (!empty($ids[$index]) && empty($asset['id'])) {
                $asset['id'] = $ids[$index];
            }
            self::appendReferenceAsset($filtered, $asset);
        }
        if (!empty($payload['first_frame_image'])) {
            $filtered['first_frame_image'] = (string)$payload['first_frame_image'];
        }
        if (!empty($payload['last_frame_image'])) {
            $filtered['last_frame_image'] = (string)$payload['last_frame_image'];
        }
        return $filtered;
    }

    private static function shotReferenceAssets(int $tenantId, int $userId, int $projectId, $shot, array $plan = []): array
    {
        $shotData = is_array($shot) ? $shot : [];
        $locations = (array)($plan['scenes'] ?? $plan['locations'] ?? []);
        $subjects = (array)($plan['subjects'] ?? []);
        $noSubjectShot = self::isNoSubjectShot($shotData);
        $sceneRefId = (string)($shotData['scene_ref_id'] ?? $shotData['scene_ref'] ?? $shotData['location_id'] ?? '');
        $subjectRefsRaw = $shotData['subject_ref_ids'] ?? $shotData['subject_refs'] ?? $shotData['character_ids'] ?? [];
        if (is_string($subjectRefsRaw)) {
            $rawSubjectRefs = $subjectRefsRaw;
            $subjectRefsRaw = self::jsonDecode($subjectRefsRaw);
            if (empty($subjectRefsRaw) && trim($rawSubjectRefs) !== '') {
                $subjectRefsRaw = preg_split('/[,，、；;\s]+/u', $rawSubjectRefs) ?: [];
            }
        }
        $subjectRefIds = array_values(array_filter(array_map('strval', (array)$subjectRefsRaw)));
        if (!empty($locations)) {
            $sceneRefId = self::resolveShotSceneRef($shotData, $locations);
        }
        if ($noSubjectShot) {
            $subjectRefIds = [];
        } elseif (!empty($subjects)) {
            $subjectRefIds = self::resolveShotSubjectRefs(array_merge($shotData, [
                'subject_ref_ids' => $subjectRefIds,
            ]), $subjects);
        }
        $assets = [];
        $images = [];
        $ids = [];
        $seen = [];
        $seenPlanKeys = [];
        $appendAsset = static function (array $asset, string $planKey = '') use (&$assets, &$images, &$ids, &$seen, &$seenPlanKeys): void {
            $id = (int)($asset['id'] ?? 0);
            $uri = (string)($asset['uri'] ?? '');
            $url = (string)($asset['url'] ?? '');
            if ($url === '' && $uri !== '') {
                $url = FileService::getFileUrlByStorage(
                    $uri,
                    (string)($asset['storage_scope'] ?? 'tenant'),
                    (string)($asset['storage_engine'] ?? 'local'),
                    (string)($asset['storage_domain'] ?? '')
                );
            }
            if ($planKey !== '' && isset($seenPlanKeys[$planKey])) {
                return;
            }
            $key = $id > 0 ? 'id:' . $id : 'url:' . $url;
            if ($url === '' || isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            if ($planKey !== '') {
                $seenPlanKeys[$planKey] = true;
            }
            $assets[] = [
                'id' => $id,
                'type' => 'image',
                'asset_type' => (string)($asset['asset_type'] ?? ''),
                'uri' => $uri,
                'url' => $url,
                'name' => (string)($asset['title'] ?? $asset['name'] ?? ''),
                'meta' => (array)($asset['meta'] ?? []),
            ];
            $images[] = $url;
            if ($id > 0) {
                $ids[] = $id;
            }
        };

        $taskRows = AigcShortDramaGenerationTask::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'project_id' => $projectId,
            'status' => self::STATUS_SUCCESS,
            'delete_time' => 0,
        ])->whereIn('task_type', ['subject_image', 'three_view', 'scene_image'])
            ->order(['id' => 'desc'])
            ->select()
            ->toArray();
        foreach ($taskRows as $row) {
            $request = self::jsonDecode((string)($row['request_json'] ?? ''));
            $params = (array)($request['params'] ?? []);
            $taskType = (string)($row['task_type'] ?? '');
            $matched = false;
            $matchedId = '';
            if ($taskType === 'scene_image') {
                $matchedId = self::resolvePlanItemRefFromPayload($params, $locations, [
                    'scene_id',
                    'item_id',
                    'scene_ref_id',
                    'scene_name',
                    'item_name',
                ]);
                $matched = $sceneRefId !== '' && $matchedId === $sceneRefId;
            } elseif (in_array($taskType, ['subject_image', 'three_view'], true)) {
                $matchedId = self::resolvePlanItemRefFromPayload($params, $subjects, [
                    'subject_id',
                    'item_id',
                    'subject_ref_id',
                    'subject_name',
                    'item_name',
                ]);
                $matched = !empty($subjectRefIds) && in_array($matchedId, $subjectRefIds, true);
            }
            if (!$matched) {
                continue;
            }
            foreach (self::generationTaskAssets($row) as $asset) {
                $appendAsset($asset, $matchedId === '' ? '' : $taskType . ':' . $matchedId);
            }
        }
        $metaReferences = self::planReferenceAssetsByMeta($tenantId, $userId, $projectId, $sceneRefId, $subjectRefIds, $subjects, $locations);
        $metaReferenceIds = (array)($metaReferences['input_asset_ids'] ?? []);
        foreach ((array)($metaReferences['reference_assets'] ?? []) as $index => $asset) {
            if (is_array($asset)) {
                $id = (int)($metaReferenceIds[$index] ?? 0);
                if ($id > 0 && empty($asset['id'])) {
                    $asset['id'] = $id;
                }
                $meta = (array)($asset['meta'] ?? []);
                $assetType = (string)($asset['asset_type'] ?? '');
                $matchedId = in_array($assetType, ['subject_image', 'three_view'], true)
                    ? self::resolvePlanItemRefFromPayload($meta, $subjects, ['subject_id', 'item_id', 'subject_ref_id', 'subject_name', 'item_name'])
                    : self::resolvePlanItemRefFromPayload($meta, $locations, ['scene_id', 'item_id', 'scene_ref_id', 'scene_name', 'item_name']);
                $appendAsset($asset, $matchedId === '' ? '' : $assetType . ':' . $matchedId);
            }
        }

        return [
            'reference_assets' => array_slice($assets, 0, 12),
            'reference_images' => array_slice($images, 0, 12),
            'input_asset_ids' => array_slice(array_values(array_unique($ids)), 0, 12),
        ];
    }

    private static function generationInputReferenceAssets(int $tenantId, int $userId, int $projectId, array $params, array $shot = []): array
    {
        $nested = is_array($params['params'] ?? null) ? (array)$params['params'] : [];
        $isVideoGenerate = self::normalizeGenerationMode($params) === 'video_generate';
        $assetIds = $isVideoGenerate ? [] : array_merge(
            self::normalizeIdList($params['input_asset_ids'] ?? []),
            self::normalizeIdList($nested['input_asset_ids'] ?? []),
            self::normalizeIdList($params['reference_asset_ids'] ?? []),
            self::normalizeIdList($nested['reference_asset_ids'] ?? [])
        );
        $selectedAssetIds = $isVideoGenerate ? array_merge(
            self::normalizeIdList($params['reference_asset_ids'] ?? []),
            self::normalizeIdList($nested['reference_asset_ids'] ?? [])
        ) : [];
        $firstFrameId = (int)($params['first_frame_asset_id'] ?? $nested['first_frame_asset_id'] ?? 0);
        $lastFrameId = (int)($params['last_frame_asset_id'] ?? $nested['last_frame_asset_id'] ?? 0);
        if ($firstFrameId > 0) {
            array_unshift($assetIds, $firstFrameId);
        } elseif ($isVideoGenerate) {
            $latest = self::latestShotImageAsset($tenantId, $userId, $projectId, (string)($shot['shot_id'] ?? $params['shot_id'] ?? ''));
            if (!empty($latest['id'])) {
                $firstFrameId = (int)$latest['id'];
                array_unshift($assetIds, $firstFrameId);
            }
        }
        if ($lastFrameId > 0) {
            $assetIds[] = $lastFrameId;
        }

        $payload = self::referencePayloadFromAssetIds($tenantId, $userId, $projectId, $assetIds);
        if (!$isVideoGenerate) {
            $selectedPayload = self::selectedPlanReferenceAssets($tenantId, $userId, $projectId, $params);
            $payload = self::mergeReferencePayloads($payload, $selectedPayload);
        } else {
            $selectedPayload = self::referencePayloadFromAssetIds($tenantId, $userId, $projectId, $selectedAssetIds);
            $payload = self::mergeReferencePayloads($payload, $selectedPayload);
        }
        $explicitImages = array_values(array_unique(array_filter(array_map('strval', array_merge(
            self::normalizeStringList($params['reference_images'] ?? []),
            self::normalizeStringList($nested['reference_images'] ?? []),
            self::normalizeStringList($params['image_urls'] ?? []),
            self::normalizeStringList($nested['image_urls'] ?? [])
        )))));
        if (!$isVideoGenerate) {
            foreach ($explicitImages as $image) {
                $url = preg_match('/^https?:\/\//i', $image) ? $image : self::fileUrl($image);
                if ($url === '' || in_array($url, $payload['reference_images'], true)) {
                    continue;
                }
                $payload['reference_assets'][] = [
                    'type' => 'image',
                    'asset_type' => 'reference_image',
                    'uri' => $image,
                    'url' => $url,
                    'name' => '',
                ];
                $payload['reference_images'][] = $url;
            }
        }
        $assetById = [];
        foreach ($payload['reference_assets'] as $index => $asset) {
            $id = (int)($payload['input_asset_ids'][$index] ?? 0);
            if ($id > 0) {
                $assetById[$id] = $asset;
            }
        }
        if ($firstFrameId > 0 && isset($assetById[$firstFrameId])) {
            $payload['first_frame_image'] = (string)($assetById[$firstFrameId]['url'] ?? '');
        }
        if ($lastFrameId > 0 && isset($assetById[$lastFrameId])) {
            $payload['last_frame_image'] = (string)($assetById[$lastFrameId]['url'] ?? '');
        }
        return $payload;
    }

    private static function planReferenceAssetsByMeta(int $tenantId, int $userId, int $projectId, string $sceneRefId, array $subjectRefIds, array $subjects = [], array $locations = []): array
    {
        $subjectSet = array_flip(array_filter(array_map('strval', $subjectRefIds)));
        $sceneRefId = trim($sceneRefId);
        if ($sceneRefId === '' && empty($subjectSet)) {
            return self::emptyReferencePayload();
        }

        $rows = AigcShortDramaAsset::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'project_id' => $projectId,
            'status' => 'ready',
            'delete_time' => 0,
        ])->whereIn('asset_type', ['subject_image', 'three_view', 'scene_image'])
            ->order(['id' => 'desc'])
            ->select()
            ->toArray();

        $payload = self::emptyReferencePayload();
        $seenPlanKeys = [];
        foreach ($rows as $row) {
            $assetType = (string)($row['asset_type'] ?? '');
            $meta = self::jsonDecode((string)($row['meta_json'] ?? ''));
            $matchedId = '';
            if ($assetType === 'scene_image') {
                $matchedId = self::resolvePlanItemRefFromPayload($meta, $locations, ['scene_id', 'item_id', 'scene_ref_id', 'scene_name', 'item_name']);
                if ($matchedId === '') {
                    $matchedId = trim((string)($meta['scene_id'] ?? $meta['item_id'] ?? ''));
                }
                if ($sceneRefId === '' || $matchedId !== $sceneRefId) {
                    continue;
                }
            } elseif (in_array($assetType, ['subject_image', 'three_view'], true)) {
                $matchedId = self::resolvePlanItemRefFromPayload($meta, $subjects, ['subject_id', 'item_id', 'subject_ref_id', 'subject_name', 'item_name']);
                if ($matchedId === '') {
                    $matchedId = trim((string)($meta['subject_id'] ?? $meta['item_id'] ?? ''));
                }
                if ($matchedId === '' || !isset($subjectSet[$matchedId])) {
                    continue;
                }
            } else {
                continue;
            }
            $planKey = $assetType . ':' . $matchedId;
            if (isset($seenPlanKeys[$planKey])) {
                continue;
            }
            $seenPlanKeys[$planKey] = true;
            self::appendReferenceAsset($payload, self::formatAsset($row));
        }
        return $payload;
    }

    private static function sanitizeVideoGenerationParams(array $params): array
    {
        $nested = is_array($params['params'] ?? null) ? (array)$params['params'] : [];
        $firstFrameId = (int)($params['first_frame_asset_id'] ?? $nested['first_frame_asset_id'] ?? 0);
        $lastFrameId = (int)($params['last_frame_asset_id'] ?? $nested['last_frame_asset_id'] ?? 0);
        $inputReferenceIds = array_values(array_diff(array_values(array_unique(array_filter(array_merge(
            self::normalizeIdList($params['input_asset_ids'] ?? []),
            self::normalizeIdList($nested['input_asset_ids'] ?? [])
        )))), array_filter([$firstFrameId, $lastFrameId])));
        $referenceAssetIds = array_values(array_unique(array_filter(array_merge(
            $inputReferenceIds,
            self::normalizeIdList($params['reference_asset_ids'] ?? []),
            self::normalizeIdList($nested['reference_asset_ids'] ?? [])
        ))));
        $selectedSubjectIds = array_values(array_unique(array_filter(array_map('strval', array_merge(
            self::normalizeStringList($params['selected_subject_ids'] ?? []),
            self::normalizeStringList($nested['selected_subject_ids'] ?? [])
        )))));
        $selectedSceneIds = array_values(array_unique(array_filter(array_map('strval', array_merge(
            self::normalizeStringList($params['selected_scene_ids'] ?? []),
            self::normalizeStringList($nested['selected_scene_ids'] ?? [])
        )))));
        $selectedMentions = array_values(array_filter(array_merge(
            is_array($params['selected_mentions'] ?? null) ? (array)$params['selected_mentions'] : [],
            is_array($nested['selected_mentions'] ?? null) ? (array)$nested['selected_mentions'] : []
        ), static fn($item) => is_array($item)));
        $mentionPrompts = array_values(array_unique(array_filter(array_map('strval', array_merge(
            self::normalizeStringList($params['mention_prompts'] ?? []),
            self::normalizeStringList($nested['mention_prompts'] ?? [])
        )))));
        $inputAssetIds = array_values(array_unique(array_filter(array_merge([$firstFrameId, $lastFrameId], $referenceAssetIds))));

        $params['input_asset_ids'] = $inputAssetIds;
        $params['reference_asset_ids'] = $referenceAssetIds;
        $params['selected_subject_ids'] = $selectedSubjectIds;
        $params['selected_scene_ids'] = $selectedSceneIds;
        $params['selected_mentions'] = $selectedMentions;
        $params['mention_prompts'] = $mentionPrompts;

        if (isset($params['params']) && is_array($params['params'])) {
            $params['params']['input_asset_ids'] = $inputAssetIds;
            $params['params']['reference_asset_ids'] = $referenceAssetIds;
            $params['params']['selected_subject_ids'] = $selectedSubjectIds;
            $params['params']['selected_scene_ids'] = $selectedSceneIds;
            $params['params']['selected_mentions'] = $selectedMentions;
            $params['params']['mention_prompts'] = $mentionPrompts;
        }
        return self::sanitizeUtf8Payload($params);
    }

    private static function referencePayloadFromAssetIds(int $tenantId, int $userId, int $projectId, array $assetIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $assetIds))));
        if (empty($ids)) {
            return self::emptyReferencePayload();
        }
        $rows = AigcShortDramaAsset::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'project_id' => $projectId,
            'status' => 'ready',
            'delete_time' => 0,
        ])->whereIn('id', $ids)->select()->toArray();
        $byId = [];
        foreach ($rows as $row) {
            $byId[(int)$row['id']] = $row;
        }
        $payload = self::emptyReferencePayload();
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                self::appendReferenceAsset($payload, $byId[$id]);
            }
        }
        return $payload;
    }

    private static function selectedPlanReferenceAssets(int $tenantId, int $userId, int $projectId, array $params): array
    {
        $nested = is_array($params['params'] ?? null) ? (array)$params['params'] : [];
        $subjectIds = array_values(array_unique(array_filter(array_map('strval', array_merge(
            self::normalizeStringList($params['selected_subject_ids'] ?? []),
            self::normalizeStringList($nested['selected_subject_ids'] ?? [])
        )))));
        $sceneIds = array_values(array_unique(array_filter(array_map('strval', array_merge(
            self::normalizeStringList($params['selected_scene_ids'] ?? []),
            self::normalizeStringList($nested['selected_scene_ids'] ?? [])
        )))));
        if (empty($subjectIds) && empty($sceneIds)) {
            return self::emptyReferencePayload();
        }
        $payload = self::emptyReferencePayload();
        $seenPlanKeys = [];
        $taskRows = AigcShortDramaGenerationTask::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'project_id' => $projectId,
            'status' => self::STATUS_SUCCESS,
            'delete_time' => 0,
        ])->whereIn('task_type', ['subject_image', 'three_view', 'scene_image'])
            ->order(['id' => 'desc'])
            ->select()
            ->toArray();
        foreach ($taskRows as $row) {
            $request = self::jsonDecode((string)($row['request_json'] ?? ''));
            $requestParams = (array)($request['params'] ?? []);
            $taskType = (string)($row['task_type'] ?? '');
            $matched = false;
            $matchedId = '';
            if ($taskType === 'scene_image') {
                $matchedId = (string)($requestParams['scene_id'] ?? $requestParams['item_id'] ?? '');
                $matched = in_array($matchedId, $sceneIds, true);
            } elseif (in_array($taskType, ['subject_image', 'three_view'], true)) {
                $matchedId = (string)($requestParams['subject_id'] ?? $requestParams['item_id'] ?? '');
                $matched = in_array($matchedId, $subjectIds, true);
            }
            if (!$matched) {
                continue;
            }
            $planKey = $matchedId === '' ? '' : $taskType . ':' . $matchedId;
            if ($planKey !== '' && isset($seenPlanKeys[$planKey])) {
                continue;
            }
            foreach (self::generationTaskAssets($row) as $asset) {
                if ($planKey !== '') {
                    $asset['meta'] = array_merge((array)($asset['meta'] ?? []), [
                        in_array($taskType, ['subject_image', 'three_view'], true) ? 'subject_id' : 'scene_id' => $matchedId,
                    ]);
                }
                self::appendReferenceAsset($payload, $asset);
                if ($planKey !== '') {
                    $seenPlanKeys[$planKey] = true;
                    break;
                }
            }
        }
        if (!empty($subjectIds)) {
            $payload = self::mergeReferencePayloads($payload, self::planReferenceAssetsByMeta($tenantId, $userId, $projectId, '', $subjectIds));
        }
        foreach ($sceneIds as $sceneId) {
            $payload = self::mergeReferencePayloads($payload, self::planReferenceAssetsByMeta($tenantId, $userId, $projectId, (string)$sceneId, []));
        }
        return $payload;
    }

    private static function emptyReferencePayload(): array
    {
        return [
            'reference_assets' => [],
            'reference_images' => [],
            'input_asset_ids' => [],
            'first_frame_image' => '',
            'last_frame_image' => '',
        ];
    }

    private static function appendReferenceAsset(array &$payload, array $asset): void
    {
        $id = (int)($asset['id'] ?? 0);
        $uri = (string)($asset['uri'] ?? '');
        $url = (string)($asset['url'] ?? '');
        if ($url === '' && $uri !== '') {
            $url = FileService::getFileUrlByStorage(
                $uri,
                (string)($asset['storage_scope'] ?? 'tenant'),
                (string)($asset['storage_engine'] ?? 'local'),
                (string)($asset['storage_domain'] ?? '')
            );
        }
        if ($url === '') {
            return;
        }
        $seen = array_flip(array_map(static fn($item) => (string)($item['url'] ?? ''), (array)$payload['reference_assets']));
        if (isset($seen[$url])) {
            return;
        }
        $payload['reference_assets'][] = [
            'id' => $id,
            'type' => 'image',
            'asset_type' => (string)($asset['asset_type'] ?? ''),
            'uri' => $uri,
            'url' => $url,
            'name' => (string)($asset['title'] ?? $asset['name'] ?? ''),
            'meta' => (array)($asset['meta'] ?? []),
        ];
        $payload['reference_images'][] = $url;
        if ($id > 0) {
            $payload['input_asset_ids'][] = $id;
        }
    }

    private static function mergeReferencePayloads(array ...$payloads): array
    {
        $merged = self::emptyReferencePayload();
        foreach ($payloads as $payload) {
            foreach ((array)($payload['reference_assets'] ?? []) as $index => $asset) {
                if (!is_array($asset)) {
                    continue;
                }
                $id = (int)((array)($payload['input_asset_ids'] ?? []))[$index] ?? 0;
                if ($id > 0 && empty($asset['id'])) {
                    $asset['id'] = $id;
                }
                self::appendReferenceAsset($merged, $asset);
            }
            foreach ((array)($payload['reference_images'] ?? []) as $url) {
                $url = trim((string)$url);
                if ($url === '' || in_array($url, $merged['reference_images'], true)) {
                    continue;
                }
                $merged['reference_assets'][] = [
                    'type' => 'image',
                    'asset_type' => 'reference_image',
                    'uri' => '',
                    'url' => $url,
                    'name' => '',
                ];
                $merged['reference_images'][] = $url;
            }
            foreach ((array)($payload['input_asset_ids'] ?? []) as $id) {
                $id = (int)$id;
                if ($id > 0 && !in_array($id, $merged['input_asset_ids'], true)) {
                    $merged['input_asset_ids'][] = $id;
                }
            }
            if (empty($merged['first_frame_image']) && !empty($payload['first_frame_image'])) {
                $merged['first_frame_image'] = (string)$payload['first_frame_image'];
            }
            if (empty($merged['last_frame_image']) && !empty($payload['last_frame_image'])) {
                $merged['last_frame_image'] = (string)$payload['last_frame_image'];
            }
        }
        $merged['reference_assets'] = array_slice($merged['reference_assets'], 0, 16);
        $merged['reference_images'] = array_slice($merged['reference_images'], 0, 16);
        $merged['input_asset_ids'] = array_slice(array_values(array_unique(array_map('intval', $merged['input_asset_ids']))), 0, 16);
        return $merged;
    }

    private static function normalizeIdList($value): array
    {
        if (is_string($value)) {
            $value = self::jsonDecode($value) ?: explode(',', $value);
        }
        return array_values(array_filter(array_map('intval', (array)$value)));
    }

    private static function normalizeStringList($value): array
    {
        if (is_string($value)) {
            $decoded = self::jsonDecode($value);
            $value = !empty($decoded) ? $decoded : explode(',', $value);
        }
        return array_values(array_filter(array_map(static fn($item) => trim((string)$item), (array)$value)));
    }

    private static function orderedShotVideoAssets(int $tenantId, int $userId, int $projectId): array
    {
        $shots = AigcShortDramaStoryboard::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'project_id' => $projectId,
            'delete_time' => 0,
        ])->order(['sort' => 'asc', 'id' => 'asc'])->select()->toArray();
        if (empty($shots)) {
            return [];
        }
        $assets = [];
        foreach ($shots as $index => $shot) {
            $shotId = (string)($shot['shot_id'] ?? $shot['id'] ?? ($index + 1));
            $asset = self::selectedTimelineAssetForShot($tenantId, $userId, $projectId, $shot, 'shot_video');
            if (empty($asset)) {
                $asset = self::selectedTimelineAssetForShot($tenantId, $userId, $projectId, $shot, 'shot_image');
            }
            if (empty($asset)) {
                throw new Exception('当前分镜缺少可导出的图片或视频：' . $shotId);
            }
            $asset['duration'] = max(1, (float)($asset['duration'] ?? 0) ?: (float)($shot['recommended_duration_seconds'] ?? 3));
            $asset['timeline_shot_id'] = $shotId;
            $assets[] = $asset;
        }
        return $assets;
    }

    private static function selectedTimelineAssetForShot(int $tenantId, int $userId, int $projectId, array $shot, string $assetType): array
    {
        $shotId = (string)($shot['shot_id'] ?? '');
        $selectedId = (int)($assetType === 'shot_video' ? ($shot['selected_video_asset_id'] ?? 0) : ($shot['selected_image_asset_id'] ?? 0));
        $where = [
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'project_id' => $projectId,
            'shot_id' => $shotId,
            'asset_type' => $assetType,
            'status' => 'ready',
            'delete_time' => 0,
        ];
        if ($selectedId > 0) {
            $selected = AigcShortDramaAsset::where([
                'id' => $selectedId,
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'project_id' => $projectId,
                'asset_type' => $assetType,
                'status' => 'ready',
                'delete_time' => 0,
            ])->findOrEmpty();
            if (!$selected->isEmpty()) {
                return $selected->toArray();
            }
        }
        $asset = AigcShortDramaAsset::where($where)->order(['id' => 'desc'])->findOrEmpty();
        return $asset->isEmpty() ? [] : $asset->toArray();
    }
    private static function readyBgmAudioAsset(int $tenantId, int $userId, int $projectId): array
    {
        $asset = AigcShortDramaAsset::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'project_id' => $projectId,
            'asset_type' => 'bgm_audio',
            'status' => 'ready',
            'delete_time' => 0,
        ])->order(['id' => 'desc'])->findOrEmpty();
        return $asset->isEmpty() ? [] : $asset->toArray();
    }

    private static function resolveFfmpegBinary(): string
    {
        if (!function_exists('exec')) {
            return '';
        }
        $runtimeBinary = dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'ffmpeg' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . (DIRECTORY_SEPARATOR === '\\' ? 'ffmpeg.exe' : 'ffmpeg');
        $candidates = array_values(array_filter([
            (string)env('ffmpeg_binary', ''),
            (string)env('ffmpeg.binary', ''),
            getenv('FFMPEG_BINARY') ?: '',
            $runtimeBinary,
            'ffmpeg',
        ]));
        foreach ($candidates as $candidate) {
            $cmd = $candidate === 'ffmpeg'
                ? 'ffmpeg -version'
                : escapeshellarg($candidate) . ' -version';
            @\exec($cmd . ' 2>&1', $output, $code);
            if ($code === 0) {
                return $candidate;
            }
        }
        return '';
    }

    private static function concatShotVideos(int $tenantId, int $projectId, array $assets, string $ffmpeg, array $bgmAsset = [], array $watermark = []): array
    {
        $paths = [];
        $workDir = runtime_path() . 'short_drama_export_' . $tenantId . '_' . $projectId . '_' . time() . DIRECTORY_SEPARATOR;
        if (!is_dir($workDir)) {
            @mkdir($workDir, 0775, true);
        }
        $ffmpegCmd = ($ffmpeg === 'ffmpeg' ? 'ffmpeg' : escapeshellarg($ffmpeg));
        foreach ($assets as $index => $asset) {
            if ((string)($asset['asset_type'] ?? '') === 'shot_image') {
                $imagePath = self::localPublicFilePath((string)($asset['uri'] ?? ''));
                if ($imagePath === '') {
                    $imagePath = self::downloadImageForFfmpeg($asset, $workDir, (int)$index + 1);
                }
                $path = self::imageClipForFfmpeg($imagePath, $workDir, $ffmpegCmd, (int)$index + 1, (float)($asset['duration'] ?? 3));
            } else {
                $path = self::localPublicFilePath((string)($asset['uri'] ?? ''));
                if ($path === '') {
                    $path = self::downloadVideoForFfmpeg($asset, $workDir, (int)$index + 1);
                }
            }
            $paths[] = $path;
        }
        $date = date('Ymd');
        $dir = public_path() . 'uploads/aigc_short_drama/' . $date . '/';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        if (!is_dir($dir) || !is_writable($dir)) {
            throw new Exception('成片输出目录不可写，请检查服务器存储配置');
        }
        $listPath = $workDir . 'concat.txt';
        $list = implode("\n", array_map(static function (string $path): string {
            return "file '" . str_replace("'", "'\\''", str_replace('\\', '/', $path)) . "'";
        }, $paths));
        file_put_contents($listPath, $list);
        $filename = 'final_' . $projectId . '_' . date('His') . '_' . random_int(1000, 9999) . '.mp4';
        $outputPath = $dir . $filename;
        $concatPath = empty($bgmAsset) ? $outputPath : $workDir . 'concat_output.mp4';
        $output = [];
        $cmd = $ffmpegCmd . ' -y -f concat -safe 0 -i ' . escapeshellarg($listPath) . ' -c copy ' . escapeshellarg($concatPath) . ' 2>&1';
        @\exec($cmd, $output, $code);
        if ($code !== 0 || !is_file($concatPath)) {
            $cmd = $ffmpegCmd . ' -y -f concat -safe 0 -i ' . escapeshellarg($listPath) . ' -c:v libx264 -c:a aac -movflags +faststart ' . escapeshellarg($concatPath) . ' 2>&1';
            @\exec($cmd, $output, $code);
        }
        if ($code !== 0 || !is_file($concatPath)) {
            Log::write('AI short drama FFmpeg export failed: ' . implode("\n", (array)$output));
            throw new Exception('最终视频导出失败，请检查分镜视频格式或 FFmpeg 配置');
        }
        if (!empty($bgmAsset)) {
            $audioPath = self::localPublicFilePath((string)($bgmAsset['uri'] ?? ''));
            if ($audioPath === '') {
                $audioPath = self::downloadAudioForFfmpeg($bgmAsset, $workDir);
            }
            $mixOutput = [];
            $cmd = $ffmpegCmd
                . ' -y -i ' . escapeshellarg($concatPath)
                . ' -stream_loop -1 -i ' . escapeshellarg($audioPath)
                . ' -filter_complex ' . escapeshellarg('[1:a]volume=0.18[bgm];[0:a][bgm]amix=inputs=2:duration=first:dropout_transition=0[a]')
                . ' -map 0:v:0 -map ' . escapeshellarg('[a]')
                . ' -c:v copy -c:a aac -shortest -movflags +faststart ' . escapeshellarg($outputPath) . ' 2>&1';
            @\exec($cmd, $mixOutput, $mixCode);
            if ($mixCode !== 0 || !is_file($outputPath)) {
                $mixOutput = [];
                $cmd = $ffmpegCmd
                    . ' -y -i ' . escapeshellarg($concatPath)
                    . ' -stream_loop -1 -i ' . escapeshellarg($audioPath)
                    . ' -filter_complex ' . escapeshellarg('[1:a]volume=0.18[bgm]')
                    . ' -map 0:v:0 -map ' . escapeshellarg('[bgm]')
                    . ' -c:v copy -c:a aac -shortest -movflags +faststart ' . escapeshellarg($outputPath) . ' 2>&1';
                @\exec($cmd, $mixOutput, $mixCode);
            }
            if ($mixCode !== 0 || !is_file($outputPath)) {
                Log::write('AI short drama FFmpeg BGM mix failed: ' . implode("\n", (array)$mixOutput));
                throw new Exception('最终视频混入背景音乐失败，请检查音频格式或重新生成背景音乐');
            }
        }
        if (!empty($watermark['enabled'])) {
            $watermarkPath = $workDir . 'watermarked_output.mp4';
            if (self::applyExportWatermark($tenantId, $ffmpegCmd, $outputPath, $watermarkPath, $watermark, $workDir)) {
                @copy($watermarkPath, $outputPath);
            }
        }
        $uri = 'uploads/aigc_short_drama/' . $date . '/' . $filename;
        return [
            'uri' => $uri,
            'file_size' => filesize($outputPath) ?: 0,
            'checksum' => hash_file('sha256', $outputPath) ?: '',
        ];
    }

    private static function applyExportWatermark(int $tenantId, string $ffmpegCmd, string $inputPath, string $outputPath, array $watermark, string $workDir): bool
    {
        if (!is_file($inputPath)) {
            return false;
        }
        $type = (string)($watermark['type'] ?? 'text');
        $opacity = max(0.05, min(1, (float)($watermark['opacity'] ?? 0.35)));
        $marginX = max(0, min(300, (int)($watermark['margin_x'] ?? 24)));
        $marginY = max(0, min(300, (int)($watermark['margin_y'] ?? 24)));
        $output = [];
        if ($type === 'image') {
            $imagePath = self::watermarkImageLocalPath($tenantId, (string)($watermark['image'] ?? ''), $workDir);
            if ($imagePath === '') {
                return false;
            }
            $filter = sprintf('[1:v]format=rgba,colorchannelmixer=aa=%0.3F[wm];[0:v][wm]overlay=x=%d:y=H-h-%d', $opacity, $marginX, $marginY);
            $cmd = $ffmpegCmd
                . ' -y -i ' . escapeshellarg($inputPath)
                . ' -i ' . escapeshellarg($imagePath)
                . ' -filter_complex ' . escapeshellarg($filter)
                . ' -c:a copy -movflags +faststart ' . escapeshellarg($outputPath) . ' 2>&1';
            @\exec($cmd, $output, $code);
            if ($code === 0 && is_file($outputPath) && filesize($outputPath) > 0) {
                return true;
            }
            Log::write('AI short drama image watermark failed: ' . implode("\n", (array)$output));
            throw new Exception('视频水印处理失败，请检查水印配');
        }
        $text = trim((string)($watermark['text'] ?? ''));
        if ($text === '') {
            return false;
        }
        $safeText = str_replace(["\\", ":", "'", "%", "\n", "\r"], ["\\\\", "\\:", "\\'", "\\%", ' ', ' '], $text);
        $filter = sprintf("drawtext=text='%s':fontcolor=white@%0.3F:fontsize=28:x=%d:y=h-th-%d", $safeText, $opacity, $marginX, $marginY);
        $cmd = $ffmpegCmd
            . ' -y -i ' . escapeshellarg($inputPath)
            . ' -vf ' . escapeshellarg($filter)
            . ' -c:a copy -movflags +faststart ' . escapeshellarg($outputPath) . ' 2>&1';
        @\exec($cmd, $output, $code);
        if ($code === 0 && is_file($outputPath) && filesize($outputPath) > 0) {
            return true;
        }
        Log::write('AI short drama text watermark failed: ' . implode("\n", (array)$output));
        throw new Exception('视频水印处理失败，请检查水印配');
    }

    private static function watermarkImageLocalPath(int $tenantId, string $uri, string $workDir): string
    {
        $uri = trim($uri);
        if ($uri === '') {
            return '';
        }
        $localPath = self::localPublicFilePath($uri);
        if ($localPath !== '') {
            return $localPath;
        }
        if (str_starts_with($uri, 'http://') || str_starts_with($uri, 'https://')) {
            $url = $uri;
        } else {
            $storageConfig = StorageConfigService::getEffectiveConfig($tenantId);
            $url = FileService::getFileUrlByStorage(
                $uri,
                (string)($storageConfig['scope'] ?? ''),
                (string)($storageConfig['default'] ?? ''),
                StorageConfigService::getEffectiveDomain($tenantId)
            );
        }
        if ($url === '') {
            return '';
        }
        $ext = strtolower(pathinfo((string)(parse_url($url, PHP_URL_PATH) ?: $url), PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            $ext = 'png';
        }
        $target = $workDir . 'watermark_logo.' . $ext;
        $context = stream_context_create(['http' => ['timeout' => 30], 'https' => ['timeout' => 30]]);
        $read = @fopen($url, 'rb', false, $context);
        if (!$read) {
            return '';
        }
        $write = @fopen($target, 'wb');
        if (!$write) {
            fclose($read);
            return '';
        }
        stream_copy_to_stream($read, $write);
        fclose($read);
        fclose($write);
        return is_file($target) && filesize($target) > 0 ? $target : '';
    }

    private static function assetLocalOrDownloadedPath(array $asset, string $workDir, int $index): string
    {
        $path = self::localPublicFilePath((string)($asset['uri'] ?? ''));
        if ($path !== '') {
            return $path;
        }
        $assetType = (string)($asset['asset_type'] ?? '');
        if ($assetType === 'bgm_audio' || str_starts_with((string)($asset['mime_type'] ?? ''), 'audio/')) {
            return self::downloadAudioForFfmpeg($asset, $workDir);
        }
        if ($assetType === 'shot_video' || str_starts_with((string)($asset['mime_type'] ?? ''), 'video/')) {
            return self::downloadVideoForFfmpeg($asset, $workDir, $index);
        }
        return self::downloadImageForFfmpeg($asset, $workDir, $index);
    }

    private static function exportAssetExtension(array $asset, string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($ext !== '' && strlen($ext) <= 8) {
            return $ext;
        }
        $mime = strtolower((string)($asset['mime_type'] ?? ''));
        if (str_contains($mime, 'mp4')) {
            return 'mp4';
        }
        if (str_contains($mime, 'mpeg') || str_contains($mime, 'mp3')) {
            return 'mp3';
        }
        if (str_contains($mime, 'png')) {
            return 'png';
        }
        if (str_contains($mime, 'webp')) {
            return 'webp';
        }
        return str_starts_with($mime, 'video/') ? 'mp4' : 'jpg';
    }

    private static function safeZipSegment(string $value): string
    {
        $value = preg_replace('/[^A-Za-z0-9_\-]+/', '_', $value) ?: '';
        return trim($value, '_') ?: 'shot';
    }

    private static function localPublicFilePath(string $uri): string
    {
        $path = $uri;
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            $path = (string)(parse_url($path, PHP_URL_PATH) ?: '');
        }
        $path = ltrim(str_replace('\\', '/', $path), '/');
        if (!str_starts_with($path, 'uploads/') && !str_starts_with($path, 'resource/')) {
            return '';
        }
        $fullPath = public_path() . $path;
        return is_file($fullPath) ? $fullPath : '';
    }

    private static function downloadVideoForFfmpeg(array $asset, string $workDir, int $index): string
    {
        $url = FileService::getFileUrlByStorage(
            (string)($asset['uri'] ?? ''),
            (string)($asset['storage_scope'] ?? ''),
            (string)($asset['storage_engine'] ?? ''),
            (string)($asset['storage_domain'] ?? '')
        );
        if ($url === '') {
            throw new Exception('分镜视频文件不可用，请重新生成分镜视');
        }
        $target = $workDir . sprintf('input_%03d.mp4', $index);
        $context = stream_context_create(['http' => ['timeout' => 60], 'https' => ['timeout' => 60]]);
        $read = @fopen($url, 'rb', false, $context);
        if (!$read) {
            throw new Exception('分镜视频下载失败，请稍后重试');
        }
        $write = @fopen($target, 'wb');
        if (!$write) {
            fclose($read);
            throw new Exception('分镜视频缓存失败，请稍后重试');
        }
        stream_copy_to_stream($read, $write);
        fclose($read);
        fclose($write);
        if (!is_file($target) || filesize($target) <= 0) {
            throw new Exception('分镜视频文件为空，请重新生成分镜视频');
        }
        return $target;
    }

    private static function downloadImageForFfmpeg(array $asset, string $workDir, int $index): string
    {
        $url = FileService::getFileUrlByStorage(
            (string)($asset['uri'] ?? ''),
            (string)($asset['storage_scope'] ?? ''),
            (string)($asset['storage_engine'] ?? ''),
            (string)($asset['storage_domain'] ?? '')
        );
        if ($url === '') {
            throw new Exception('分镜图片文件不可用，请重新选择或生成分镜图');
        }
        $path = (string)(parse_url($url, PHP_URL_PATH) ?: '');
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            $ext = 'png';
        }
        $target = $workDir . sprintf('image_%03d.%s', $index, $ext);
        $context = stream_context_create(['http' => ['timeout' => 60], 'https' => ['timeout' => 60]]);
        $read = @fopen($url, 'rb', false, $context);
        if (!$read) {
            throw new Exception('分镜图片下载失败，请稍后重试');
        }
        $write = @fopen($target, 'wb');
        if (!$write) {
            fclose($read);
            throw new Exception('分镜图片缓存失败，请稍后重试');
        }
        stream_copy_to_stream($read, $write);
        fclose($read);
        fclose($write);
        if (!is_file($target) || filesize($target) <= 0) {
            throw new Exception('分镜图片文件为空，请重新选择或生成分镜图');
        }
        return $target;
    }

    private static function imageClipForFfmpeg(string $imagePath, string $workDir, string $ffmpegCmd, int $index, float $duration): string
    {
        $duration = max(1, min(60, $duration));
        $target = $workDir . sprintf('image_clip_%03d.mp4', $index);
        $output = [];
        $cmd = $ffmpegCmd
            . ' -y -loop 1 -t ' . escapeshellarg((string)$duration)
            . ' -i ' . escapeshellarg($imagePath)
            . ' -vf ' . escapeshellarg('scale=trunc(iw/2)*2:trunc(ih/2)*2,format=yuv420p')
            . ' -r 30 -an -c:v libx264 -movflags +faststart '
            . escapeshellarg($target) . ' 2>&1';
        @\exec($cmd, $output, $code);
        if ($code !== 0 || !is_file($target)) {
            Log::write('AI short drama image clip export failed: ' . implode("\n", (array)$output));
            throw new Exception('分镜图片转视频片段失败，请检查 FFmpeg 配置或重新生成图片');
        }
        return $target;
    }

    private static function downloadAudioForFfmpeg(array $asset, string $workDir): string
    {
        $url = FileService::getFileUrlByStorage(
            (string)($asset['uri'] ?? ''),
            (string)($asset['storage_scope'] ?? ''),
            (string)($asset['storage_engine'] ?? ''),
            (string)($asset['storage_domain'] ?? '')
        );
        if ($url === '') {
            throw new Exception('背景音乐文件不可用，请重新生成背景音');
        }
        $target = $workDir . 'bgm_audio_' . random_int(1000, 9999) . '.mp3';
        $context = stream_context_create(['http' => ['timeout' => 60], 'https' => ['timeout' => 60]]);
        $read = @fopen($url, 'rb', false, $context);
        if (!$read) {
            throw new Exception('背景音乐下载失败，请稍后重试');
        }
        $write = @fopen($target, 'wb');
        if (!$write) {
            fclose($read);
            throw new Exception('背景音乐缓存失败，请稍后重试');
        }
        stream_copy_to_stream($read, $write);
        fclose($read);
        fclose($write);
        if (!is_file($target) || filesize($target) <= 0) {
            throw new Exception('背景音乐文件为空，请重新生成背景音乐');
        }
        return $target;
    }

    private static function markGenerationSuccess(int $tenantId, int $userId, string $taskId, array $result, array $assetIds, array $billing, string $provider = ''): void
    {
        $task = AigcShortDramaGenerationTask::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'task_id' => $taskId,
        ])->findOrEmpty();
        AigcShortDramaGenerationTask::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'task_id' => $taskId,
        ])->update([
            'status' => self::STATUS_SUCCESS,
            'progress' => 100,
            'provider' => $provider,
            'result_json' => self::jsonEncode($result),
            'output_asset_ids' => self::jsonEncode($assetIds),
            'billing_status' => ((float)($billing['tenant_cost_points'] ?? 0) > 0 || (float)($billing['user_charge_points'] ?? 0) > 0) ? 'deducted' : 'none',
            'error_code' => '',
            'error_msg' => '',
            'finished_at' => time(),
            'update_time' => time(),
        ]);
        if (!$task->isEmpty()) {
            self::refreshProjectGenerationStatus($tenantId, $userId, (int)$task['project_id']);
        }
    }

    private static function selectGeneratedStoryboardAsset(int $tenantId, int $userId, array $task, array $assetIds): void
    {
        $taskType = (string)($task['task_type'] ?? '');
        if (!in_array($taskType, ['shot_image', 'shot_video'], true)) {
            return;
        }
        $assetId = (int)($assetIds[0] ?? 0);
        $projectId = (int)($task['project_id'] ?? 0);
        $shotId = (string)($task['shot_id'] ?? '');
        if ($assetId <= 0 || $projectId <= 0 || $shotId === '') {
            return;
        }
        $field = $taskType === 'shot_video' ? 'selected_video_asset_id' : 'selected_image_asset_id';
        $updateData = self::filterStoryboardWritableData([
            $field => $assetId,
            'update_time' => time(),
        ]);
        AigcShortDramaStoryboard::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'project_id' => $projectId,
            'shot_id' => $shotId,
            'delete_time' => 0,
        ])->update($updateData);
    }

    private static function failGenerationTask(int $tenantId, int $userId, array $generation, array $billing, string $errorCode, string $friendlyError, string $refundRemark): void
    {
        $taskId = (string)$generation['task_id'];
        $projectId = (int)$generation['project_id'];
        Db::startTrans();
        try {
            $refundStatus = ((float)($billing['tenant_cost_points'] ?? 0) > 0 || (float)($billing['user_charge_points'] ?? 0) > 0) ? 'refunded' : 'none';
            if ((string)($generation['billing_status'] ?? '') === 'reserved') {
                PointService::refundBusinessAmountsInCurrentTransaction($tenantId, $userId, (float)($billing['tenant_cost_points'] ?? 0), (float)($billing['user_charge_points'] ?? 0), $taskId . '-refund', $refundRemark, [
                    'app_code' => self::APP_CODE,
                    'task_id' => $taskId,
                    'project_id' => $projectId,
                    'task_type' => (string)($generation['task_type'] ?? ''),
                ]);
            }
            AigcShortDramaGenerationTask::where([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'task_id' => $taskId,
            ])->update([
                'status' => self::STATUS_FAILED,
                'progress' => 0,
                'billing_status' => $refundStatus,
                'error_code' => $errorCode,
                'error_msg' => mb_substr($friendlyError ?: self::SAFE_ERROR, 0, 500, 'UTF-8'),
                'operator_error' => mb_substr($friendlyError ?: self::SAFE_ERROR, 0, 1000, 'UTF-8'),
                'finished_at' => time(),
                'update_time' => time(),
            ]);
            Db::commit();
            self::refreshProjectGenerationStatus($tenantId, $userId, $projectId);
        } catch (\Throwable $e) {
            Db::rollback();
            Log::write('AI short drama generation failure rollback failed: ' . $e->getMessage());
        }
        Log::write('AI short drama generation task failed: ' . $taskId . ' ' . $friendlyError);
    }

    private static function friendlyGenerationError(string $message): string
    {
        $lower = strtolower($message);
        foreach (['api_key', 'authorization', 'bearer ', 'secret', 'stack trace', 'trace:'] as $needle) {
            if ($needle !== '' && str_contains($lower, $needle)) {
                return self::SAFE_ERROR;
            }
        }
        return mb_substr($message !== '' ? $message : self::SAFE_ERROR, 0, 500, 'UTF-8');
    }

    private static function requestGenerationRatio(array $params): string
    {
        $nested = is_array($params['params'] ?? null) ? (array)$params['params'] : [];
        foreach ([
            $params['ratio'] ?? null,
            $params['aspect_ratio'] ?? null,
            $params['image_ratio'] ?? null,
            $params['video_ratio'] ?? null,
            $nested['ratio'] ?? null,
            $nested['aspect_ratio'] ?? null,
            $nested['image_ratio'] ?? null,
            $nested['video_ratio'] ?? null,
        ] as $value) {
            $ratio = self::normalizeGenerationRatio((string)($value ?? ''));
            if ($ratio !== '') {
                return $ratio;
            }
        }
        return '';
    }

    private static function projectGenerationRatio(int $tenantId, int $userId, int $projectId, array $plan = []): string
    {
        if ($projectId <= 0) {
            return self::normalizeGenerationRatio((string)($plan['generation_settings']['aspect_ratio'] ?? $plan['generation_settings']['ratio'] ?? ''));
        }
        $projectRatio = (string)AigcShortDramaProject::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'id' => $projectId,
            'delete_time' => 0,
        ])->value('ratio');
        return self::normalizeGenerationRatio($projectRatio)
            ?: self::normalizeGenerationRatio((string)($plan['generation_settings']['aspect_ratio'] ?? $plan['generation_settings']['ratio'] ?? ''));
    }

    private static function normalizeGenerationRatio(string $ratio): string
    {
        $ratio = trim(str_replace(['', ''], ':', $ratio));
        if ($ratio === '') {
            return '';
        }
        if (preg_match('/(\d{1,4})\s*:\s*(\d{1,4})/u', $ratio, $matches)) {
            $width = max(1, (int)$matches[1]);
            $height = max(1, (int)$matches[2]);
            return $width . ':' . $height;
        }
        return '';
    }

    private static function firstPromptField(array $source, array $keys): string
    {
        foreach ($keys as $key) {
            $value = self::cleanPromptRatioText((string)($source[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    }

    private static function stripNoSubjectConstraints(string $text): string
    {
        $lines = preg_split('/\R/u', $text) ?: [$text];
        $blockedPatterns = [
            '/不要(?:出现)?(?:任何)?(?:人物|人类|角色|脸|面部|身体|肖像|人像)/u',
            '/(?:禁止|避免)(?:出现)?(?:人物|人类|角色|脸|面部|身体|肖像|人像)/u',
            '/(?:无|没有)(?:人物|人类|角色|脸|面部|身体|肖像|人像)/u',
            '/\b(no|without)\s+(people|person|human|character|face|body|portrait)s?\b/i',
        ];
        $result = [];
        foreach ($lines as $line) {
            $line = trim((string)$line);
            if ($line === '') {
                continue;
            }
            foreach ($blockedPatterns as $pattern) {
                if (preg_match($pattern, $line)) {
                    continue 2;
                }
            }
            $result[] = $line;
        }
        return implode("\n", array_values(array_unique($result)));
    }

    private static function sanitizeCharacterNegativePrompt(string $negativePrompt): string
    {
        $negativePrompt = trim(self::stripNoSubjectConstraints($negativePrompt));
        return $negativePrompt !== ''
            ? $negativePrompt
            : '低质量、模糊、脸部变形、五官漂移、年龄漂移、服装变化、多余手指、多余肢体、文字、水印、拼贴、多宫格、档案卡、说明文字、色卡、参数栏、局部特写、半张脸、裁切脸';
    }

    private static function defaultSubjectNegativePrompt(bool $isProp): string
    {
        return $isProp
            ? '低质量、模糊、人物、人类、角色、手、脸、身体、肖像、模特、穿戴效果、文字、水印、拼贴、多宫格、档案卡、说明文字、色卡、参数栏'
            : '低质量、模糊、脸部变形、五官漂移、年龄漂移、服装变化、多余手指、多余肢体、文字、水印、拼贴、多宫格、档案卡、说明文字、色卡、参数栏、局部特写、半张脸、裁切脸';
    }

    private static function subjectGenerationNegativePrompt(array $shot, array $params, array $plan, bool $isThreeView): string
    {
        $nested = is_array($params['params'] ?? null) ? (array)$params['params'] : [];
        $subject = self::planItemById((array)($plan['subjects'] ?? []), (string)($params['subject_id'] ?? $nested['subject_id'] ?? $params['item_id'] ?? $nested['item_id'] ?? ''));
        $subjectName = (string)($params['subject_name'] ?? $nested['subject_name'] ?? $params['item_name'] ?? $nested['item_name'] ?? $subject['name'] ?? '');
        $isProp = self::isObjectLikeSubject($shot, array_merge($params, [
            'subject_name' => $subjectName,
        ]), $plan, $subject);
        $keys = $isThreeView
            ? ['three_view_negative_prompt', 'negative_prompt', 'main_negative_prompt']
            : ['main_negative_prompt', 'negative_prompt', 'three_view_negative_prompt'];
        $negativePrompt = self::firstPromptField(array_merge($subject, $params), $keys);
        if ($negativePrompt === '') {
            $negativePrompt = self::defaultSubjectNegativePrompt($isProp);
        }
        return $isProp ? $negativePrompt : self::sanitizeCharacterNegativePrompt($negativePrompt);
    }

    private static function sceneGenerationNegativePrompt(array $scene): string
    {
        $negativePrompt = self::firstPromptField($scene, ['scene_negative_prompt', 'negative_prompt']);
        return $negativePrompt !== ''
            ? $negativePrompt
            : '低质量、模糊、人物、人类、角色、肖像、脸、身体、剪影、文字、水印、标志、拼贴、多宫格、说明文字、色卡、参数栏';
    }

    private static function shotImageNegativePrompt(array $shot, bool $noSubjectShot): string
    {
        $negativePrompt = self::firstPromptField($shot, ['image_negative_prompt', 'negative_prompt']);
        if ($noSubjectShot) {
            return self::ensureNoSubjectImageNegativePrompt($negativePrompt);
        }
        return self::sanitizeCharacterNegativePrompt($negativePrompt);
    }

    private static function ensureNoSubjectImageNegativePrompt(string $negativePrompt): string
    {
        $fallback = '低质量、模糊、人物、人类、角色、肖像、脸、身体、剪影、文字、水印、标志、拼贴、多宫格、说明文字、色卡、参数栏';
        $negativePrompt = trim($negativePrompt);
        if ($negativePrompt === '') {
            return $fallback;
        }
        $parts = preg_split('/[，、,;\s]+/u', $negativePrompt) ?: [];
        $set = [];
        foreach ($parts as $part) {
            $part = trim((string)$part);
            if ($part !== '') {
                $set[$part] = true;
            }
        }
        foreach (['人物', '人类', '角色', '肖像', '脸', '身体', '剪影'] as $required) {
            if (!isset($set[$required])) {
                $parts[] = $required;
                $set[$required] = true;
            }
        }
        return implode('、', array_values(array_unique(array_filter(array_map('trim', $parts)))));
    }

    private static function shotVideoNegativePrompt(array $shot, bool $noSubjectShot): string
    {
        $negativePrompt = self::firstPromptField($shot, ['video_negative_prompt']);
        $negativePrompt = $negativePrompt !== ''
            ? $negativePrompt
            : ($noSubjectShot
                ? '低质量、模糊、闪烁、场景漂移、人物、人类、角色、脸、身体、肖像、文字、水印'
                : '低质量、模糊、闪烁、脸部漂移、服装变化、场景变化、多余角色、文字、水印');
        $negativePrompt = $noSubjectShot ? $negativePrompt : self::sanitizeCharacterNegativePrompt($negativePrompt);
        return self::adaptShotVideoNegativePrompt($negativePrompt, $shot);
    }

    private static function adaptShotVideoNegativePrompt(string $negativePrompt, array $shot): string
    {
        $positiveText = implode(' ', array_map('strval', [
            $shot['visual_description'] ?? '',
            $shot['action'] ?? '',
            $shot['result'] ?? '',
            $shot['image_prompt'] ?? '',
            $shot['video_prompt'] ?? '',
            $shot['sound_effect'] ?? '',
        ]));
        $remove = [];
        $lowerPositiveText = strtolower($positiveText);
        if (
            str_contains($positiveText, '闪烁')
            || str_contains($positiveText, '闪动')
            || str_contains($positiveText, '闪光')
            || str_contains($positiveText, '警告')
            || str_contains($positiveText, '报错')
            || str_contains($lowerPositiveText, 'error')
        ) {
            $remove['闪烁'] = true;
        }
        if (
            str_contains($positiveText, '文字')
            || str_contains($positiveText, '字幕')
            || str_contains($positiveText, '代码')
            || str_contains($positiveText, '屏幕')
            || str_contains($positiveText, '弹窗')
            || str_contains($positiveText, '警告')
            || str_contains($lowerPositiveText, 'error')
        ) {
            $remove['文字'] = true;
        }
        $parts = preg_split('/[、,，]+/u', $negativePrompt) ?: (preg_split('/[、,，]+/', $negativePrompt) ?: []);
        $filtered = [];
        foreach ($parts as $part) {
            $part = trim((string)$part);
            if ($part === '水') {
                $part = '水印';
            }
            if ($part === '' || isset($remove[$part])) {
                continue;
            }
            $filtered[] = $part;
        }
        $filtered = array_values(array_unique($filtered));
        return !empty($filtered) ? implode('、', $filtered) : trim($negativePrompt);
    }

    private static function shortDramaImageParams(array $shot, array $params, string $taskType = 'shot_image', array $plan = []): array
    {
        $channel = trim((string)($params['model_id'] ?? $params['channel'] ?? $params['image_model_id'] ?? ''));
        $ratio = self::requestGenerationRatio($params)
            ?: self::normalizeGenerationRatio((string)($plan['generation_settings']['aspect_ratio'] ?? $plan['generation_settings']['ratio'] ?? ''))
            ?: '9:16';
        $prompt = match ($taskType) {
            'subject_image', 'three_view' => self::buildSubjectImagePrompt($shot, $params, $plan),
            'scene_image' => self::buildSceneImagePrompt($shot, $params, $plan),
            default => self::buildShotImagePrompt($shot, $params, $plan),
        };
        $prompt = $taskType === 'shot_image'
            ? self::normalizeFinalProviderPrompt($prompt)
            : self::localizeGenerationPromptText(self::cleanPromptRatioText($prompt), (string)($shot['visual_description'] ?? $shot['description'] ?? ''));
        $noSubjectShot = $taskType === 'shot_image' && self::isNoSubjectShot(self::mergeShotReferenceContext($shot, $params));
        $sceneParams = is_array($params['params'] ?? null) ? (array)$params['params'] : [];
        $sceneForNegative = self::planItemById((array)($plan['scenes'] ?? $plan['locations'] ?? []), (string)($params['scene_id'] ?? $sceneParams['scene_id'] ?? $params['item_id'] ?? $sceneParams['item_id'] ?? ''));
        $negativePrompt = match ($taskType) {
            'subject_image', 'three_view' => self::subjectGenerationNegativePrompt($shot, $params, $plan, $taskType === 'three_view'),
            'scene_image' => self::sceneGenerationNegativePrompt($sceneForNegative),
            default => self::shotImageNegativePrompt(self::mergeShotReferenceContext($shot, $params), $noSubjectShot),
        };
        $imageParams = [
            'prompt' => $prompt,
            'negative_prompt' => $negativePrompt,
            'style' => 'general',
            'channel' => $channel,
            'ratio' => $ratio,
            'quantity' => 1,
        ];
        $quality = trim((string)($params['quality'] ?? ''));
        if ($quality !== '') {
            $imageParams['quality'] = $quality;
        }
        return $imageParams;
    }

    private static function buildSubjectImagePrompt(array $shot, array $params, array $plan): string
    {
        $nested = is_array($params['params'] ?? null) ? (array)$params['params'] : [];
        $subject = self::planItemById((array)($plan['subjects'] ?? []), (string)($params['subject_id'] ?? $nested['subject_id'] ?? $params['item_id'] ?? $nested['item_id'] ?? ''));
        $subjectName = (string)($params['subject_name'] ?? $nested['subject_name'] ?? $params['item_name'] ?? $nested['item_name'] ?? $subject['name'] ?? '');
        $isThreeView = (string)($params['view_mode'] ?? '') === 'three_view' || (string)($nested['view_mode'] ?? '') === 'three_view';
        $explicitSubjectDescription = self::cleanPromptRatioText((string)($params['subject_description'] ?? $nested['subject_description'] ?? $params['item_description'] ?? $nested['item_description'] ?? ''));
        $explicitSubjectPrompt = self::cleanPromptRatioText((string)($params['subject_prompt'] ?? $nested['subject_prompt'] ?? $params['visual_prompt'] ?? $nested['visual_prompt'] ?? $params['image_prompt'] ?? $nested['image_prompt'] ?? $params['prompt'] ?? $nested['prompt'] ?? ''));
        $subjectDescription = $explicitSubjectDescription !== ''
            ? $explicitSubjectDescription
            : (($explicitSubjectPrompt !== '' && !$isThreeView) ? '' : self::cleanPromptRatioText((string)($subject['description'] ?? '')));
        $plannedSubjectPrompt = self::firstPromptField($subject, $isThreeView
            ? ['three_view_prompt', 'image_prompt', 'main_image_prompt', 'visual_prompt']
            : ['main_image_prompt', 'image_prompt', 'visual_prompt']);
        $subjectVisualPrompt = $explicitSubjectPrompt !== ''
            ? $explicitSubjectPrompt
            : $plannedSubjectPrompt;
        $viewPrompt = $isThreeView ? self::cleanPromptRatioText((string)($params['prompt'] ?? $nested['prompt'] ?? $params['user_prompt'] ?? $nested['user_prompt'] ?? '')) : '';
        $isObject = self::isObjectLikeSubject($shot, array_merge($params, [
            'subject_name' => $subjectName,
            'subject_description' => $subjectDescription,
            'subject_prompt' => $subjectVisualPrompt,
        ]), $plan, $subject);
        $taskIntro = $isObject ? '生成一张短剧关键物品统一参考图' : '生成一张短剧主体统一参考图';
        $viewRule = $isObject
            ? ($isThreeView
                ? '在一张干净的物品三视图或多角度设定图中展示正面、侧面、背面、俯视或关键细节，保持同一个物品的材质、颜色、磨损痕迹、尺寸比例和标志性细节。只呈现物品本身，不出现人物、手、脸、身体、模特、穿戴效果'
                : '单个物品主图，物品独立清晰摆放，材质、颜色、磨损痕迹和标志性细节可用于后续分镜保持一致。只生成一张统一参考图，不要拼贴、多宫格、海报、档案卡、说明文字、色卡、参数栏。不要出现人物、手、脸、身体、模特或穿戴效果')
            : ($isThreeView
                ? '在一张干净的角色三视图设定图中展示正面、侧面和背面，保持同一张脸、同一服装、同一体态比例'
                : '正面单主体标准参考图，只生成一个角色，人物居中，完整保留头部、脸部、发型、服装和上半身或全身比例，角色身份清晰，可用于后续分镜保持一致。不要局部特写、半张脸、裁切脸部、拼贴、多宫格、海报、档案卡、说明文字、色卡、参数栏、额外人物');
        $parts = [
            $taskIntro,
            $subjectName,
            (!$isThreeView && $explicitSubjectPrompt !== '') ? '本次生成必须严格以用户当前输入的主体提示词为唯一外貌依据；如果当前提示词与旧主体描述、历史图片或历史资产冲突，必须以当前提示词为准' : '',
            $subjectDescription,
            $subjectVisualPrompt,
            $viewPrompt,
            self::cleanPromptRatioText((string)($plan['art_style']['visual_description'] ?? '')),
            $viewRule,
        ];
        return self::localizeGenerationPromptText(self::joinPromptParts($parts), self::joinPromptParts([
            $taskIntro,
            $subjectName,
            $subjectDescription,
            $subjectVisualPrompt,
        ]));
    }

    private static function normalizeSubjectCategory(array $subject): string
    {
        $raw = mb_strtolower(trim((string)($subject['category'] ?? $subject['type'] ?? $subject['subject_type'] ?? '')), 'UTF-8');
        $name = mb_strtolower((string)($subject['name'] ?? ''), 'UTF-8');
        $description = mb_strtolower(self::joinPromptParts([
            (string)($subject['description'] ?? ''),
            (string)($subject['visual_prompt'] ?? ''),
            (string)($subject['image_prompt'] ?? ''),
        ]), 'UTF-8');
        $text = mb_strtolower(self::joinPromptParts([
            (string)($subject['name'] ?? ''),
            (string)($subject['description'] ?? ''),
            (string)($subject['visual_prompt'] ?? ''),
            (string)($subject['image_prompt'] ?? ''),
        ]), 'UTF-8');

        if (in_array($raw, ['character', 'characters', 'human', 'person', 'role', 'actor', 'actress', 'people', '人物', '角色', '人类', '真人', '男', '女', '男生', '女生'], true)) {
            return 'character';
        }
        if (in_array($raw, ['prop', 'props', 'object', 'item', 'tool', 'symbol', 'artifact', '道具', '物品', '器物', '符号', '特殊意象'], true)) {
            return 'prop';
        }
        if (in_array($raw, ['animal', 'pet', 'creature', '动物', '宠物', '非人角色'], true)) {
            return 'animal';
        }

        $characterNameKeywords = ['主角', '配角', '母亲', '父亲', '妈妈', '爸爸', '男生', '女生', '男人', '女人', '男子', '女子', '女孩', '男孩', '少年', '少女', '老人', '孩子', '学生', '老师', '医生', '队员', '骑手', '船员', '安娜贝尔', '科尔'];
        $objectNameKeywords = ['旧书', '书本', '', '围巾', '羊绒围巾', '信件', '日记', '钥匙', '戒指', '项链', '照片', '玩偶', '手机', '盒子', '杯子', '门票', '徽章', '道具', '物品', '物件', '器物', '符号'];
        if (self::containsAnyKeyword($name, $characterNameKeywords)) {
            return 'character';
        }
        if (self::containsAnyKeyword($name, $objectNameKeywords)) {
            return 'prop';
        }

        $characterKeywords = ['人物', '角色', '人类', '真人', '男', '女', '男生', '女生', '男人', '女人', '母亲', '父亲', '妈妈', '爸爸', '学生', '队员', '性格', '眼神', '', '面容', '五官', '头发', '发型', '身材', '体', '', '手指', '穿着', '身穿', '服装', '衣服', '队服', '气质', '表情', '年龄', '年轻', '老年', '温柔', '文静', '健硕', '高挑', '矮小', '白人', '亚洲'];
        $objectKeywords = ['旧书', '书本', '', '围巾', '羊绒围巾', '道具', '物品', '物件', '信件', '日记', '钥匙', '戒指', '项链', '照片', '玩偶', '手机', '盒子', '杯子', '', '', '门票', '徽章', '符号', '材质', '磨损', '形状', '尺寸', '流苏', '封面', '边缘'];
        $characterScore = self::keywordScore($text, $characterKeywords);
        $objectScore = self::keywordScore($text, $objectKeywords);

        if ($characterScore >= 2 && $characterScore >= $objectScore) {
            return 'character';
        }
        if ($characterScore >= 3) {
            return 'character';
        }
        if ($objectScore >= 2 && $objectScore > $characterScore) {
            return 'prop';
        }
        if ($objectScore >= 1 && $characterScore === 0 && $description !== '') {
            return 'prop';
        }
        return 'character';
    }

    private static function containsAnyKeyword(string $text, array $keywords): bool
    {
        foreach ($keywords as $keyword) {
            if ($keyword !== '' && mb_stripos($text, $keyword, 0, 'UTF-8') !== false) {
                return true;
            }
        }
        return false;
    }

    private static function keywordScore(string $text, array $keywords): int
    {
        $score = 0;
        foreach ($keywords as $keyword) {
            if ($keyword !== '' && mb_stripos($text, $keyword, 0, 'UTF-8') !== false) {
                $score++;
            }
        }
        return $score;
    }

    private static function isObjectLikeSubject(array $shot, array $params = [], array $plan = [], array $subject = []): bool
    {
        $nested = is_array($params['params'] ?? null) ? (array)$params['params'] : [];
        if (!$subject) {
            $subject = self::planItemById((array)($plan['subjects'] ?? []), (string)($params['subject_id'] ?? $nested['subject_id'] ?? $params['item_id'] ?? $nested['item_id'] ?? ''));
        }
        $candidate = array_merge($shot, $subject, [
            'category' => $params['category'] ?? $nested['category'] ?? $subject['category'] ?? '',
            'type' => $params['type'] ?? $nested['type'] ?? $subject['type'] ?? '',
            'subject_type' => $params['subject_type'] ?? $nested['subject_type'] ?? $subject['subject_type'] ?? '',
            'name' => $params['subject_name'] ?? $nested['subject_name'] ?? $params['item_name'] ?? $nested['item_name'] ?? $subject['name'] ?? '',
            'description' => $params['subject_description'] ?? $nested['subject_description'] ?? $params['item_description'] ?? $nested['item_description'] ?? $subject['description'] ?? '',
            'visual_prompt' => $params['subject_prompt'] ?? $nested['subject_prompt'] ?? $params['visual_prompt'] ?? $nested['visual_prompt'] ?? $params['prompt'] ?? $nested['prompt'] ?? $subject['visual_prompt'] ?? '',
        ]);
        return self::normalizeSubjectCategory($candidate) === 'prop';
    }

    private static function buildSceneImagePrompt(array $shot, array $params, array $plan): string
    {
        $nested = is_array($params['params'] ?? null) ? (array)$params['params'] : [];
        $scenes = (array)($plan['scenes'] ?? $plan['locations'] ?? []);
        $scene = self::planItemById($scenes, (string)($params['scene_id'] ?? $nested['scene_id'] ?? $params['item_id'] ?? $nested['item_id'] ?? ''));
        $sceneName = (string)($scene['name'] ?? $params['scene_name'] ?? $nested['scene_name'] ?? $params['item_name'] ?? $nested['item_name'] ?? $shot['scene_name'] ?? '');
        $sceneDescription = self::cleanPromptRatioText((string)($params['scene_description'] ?? $nested['scene_description'] ?? $params['item_description'] ?? $nested['item_description'] ?? $scene['description'] ?? ''));
        $plannedScenePrompt = self::firstPromptField($scene, ['scene_image_prompt', 'image_prompt', 'visual_prompt']);
        $sceneVisualPrompt = self::cleanPromptRatioText((string)($params['scene_prompt'] ?? $nested['scene_prompt'] ?? $params['visual_prompt'] ?? $nested['visual_prompt'] ?? $params['image_prompt'] ?? $nested['image_prompt'] ?? $plannedScenePrompt));
        $parts = [
            '生成一张短剧场景统一参考图',
            $sceneName,
            $sceneDescription,
            $sceneVisualPrompt,
            (string)($shot['time_of_day'] ?? ''),
            (string)($shot['interior_exterior'] ?? ''),
            self::cleanPromptRatioText((string)($plan['art_style']['visual_description'] ?? '')),
            '只生成环境建立图，可复用为场景参考，不要人物，不要角色，不要肖像，不要字幕',
        ];
        return self::localizeGenerationPromptText(self::joinPromptParts($parts), self::joinPromptParts([
            '生成一张短剧场景统一参考图',
            $sceneName,
            $sceneDescription,
            $sceneVisualPrompt,
        ]));
    }

    private static function buildShotImagePrompt(array $shot, array $params, array $plan): string
    {
        $context = self::shotPromptContext($shot, $params, $plan);
        $flexiblePrompt = self::shotFlexibleImagePrompt($context['shot'], $params);
        $flexiblePrompt = self::separateSubjectNamesInText($flexiblePrompt, (array)($context['raw_subject_names'] ?? $context['subject_names']));
        return self::composeShotProviderPrompt(
            $flexiblePrompt,
            self::shotImageFixedPrompt($context, $flexiblePrompt)
        );
    }

    private static function shotPromptContext(array $shot, array $params, array $plan): array
    {
        $nested = is_array($params['params'] ?? null) ? (array)$params['params'] : [];
        $promptShot = $shot;
        foreach ([
            'title' => ['shot_title'],
            'scene_name' => [],
            'time_of_day' => [],
            'interior_exterior' => [],
            'visual_description' => ['shot_description', 'description'],
            'composition' => [],
            'shot_type' => [],
            'angle' => [],
            'action' => [],
            'result' => [],
            'atmosphere' => [],
            'image_prompt' => [],
            'video_prompt' => [],
            'scene_ref_id' => ['scene_ref', 'location_id'],
            'camera_movement' => [],
            'dialogue' => [],
            'voice_role' => [],
            'bgm_prompt' => [],
        ] as $key => $aliases) {
            if (trim((string)($promptShot[$key] ?? '')) !== '') {
                continue;
            }
            $value = $params[$key] ?? $nested[$key] ?? null;
            foreach ($aliases as $alias) {
                if ($value !== null && trim((string)$value) !== '') {
                    break;
                }
                $value = $params[$alias] ?? $nested[$alias] ?? null;
            }
            if ($value !== null && trim((string)$value) !== '') {
                $promptShot[$key] = $value;
            }
        }
        if (empty($promptShot['subject_ref_ids'])) {
            $promptShot['subject_ref_ids'] = $params['subject_ref_ids'] ?? $nested['subject_ref_ids'] ?? $params['subject_refs'] ?? $nested['subject_refs'] ?? $params['character_ids'] ?? $nested['character_ids'] ?? [];
        }
        if (empty($promptShot['subject_ref_ids'])) {
            $promptShot['subject_ref_ids'] = $params['selected_subject_ids'] ?? $nested['selected_subject_ids'] ?? [];
        }
        $subjects = (array)($plan['subjects'] ?? []);
        $locations = (array)($plan['scenes'] ?? $plan['locations'] ?? []);
        $sceneRefId = self::resolveShotSceneRef($promptShot, $locations);
        $scene = self::planItemByExactId($locations, $sceneRefId);
        if (empty($scene)) {
            $scene = self::planItemById($locations, $sceneRefId);
        }
        $noSubjectShot = self::isNoSubjectShot($promptShot);
        $subjectRefTokens = self::splitPlanRefTokens($promptShot['subject_ref_ids'] ?? $promptShot['subject_refs'] ?? $promptShot['character_ids'] ?? []);
        $hasSubjectRefs = !$noSubjectShot && !empty($subjectRefTokens);
        $visibleSubjectIds = $hasSubjectRefs ? self::resolveShotSubjectRefs($promptShot, $subjects) : [];
        $subjectNames = [];
        foreach ($visibleSubjectIds as $subjectId) {
            $subject = self::planItemByExactId($subjects, (string)$subjectId);
            $name = trim((string)($subject['name'] ?? ''));
            if ($name !== '') {
                $subjectNames[] = $name;
            }
        }

        $promptImpliesSubject = !$noSubjectShot && self::shotPromptImpliesVisibleSubject($promptShot, $params);
        if ($promptImpliesSubject && empty($subjectNames)) {
            $subjectNames[] = '当前可见主体';
        }
        $noSubjectContext = ($noSubjectShot && !$promptImpliesSubject) || (empty($visibleSubjectIds) && !$promptImpliesSubject);

        return [
            'shot' => $promptShot,
            'plan' => $plan,
            'subjects' => $subjects,
            'locations' => $locations,
            'scene' => $scene,
            'scene_ref_id' => $sceneRefId,
            'no_subject_shot' => $noSubjectContext,
            'has_subject_refs' => !$noSubjectContext && (!empty($visibleSubjectIds) || $promptImpliesSubject),
            'visible_subject_ids' => $visibleSubjectIds,
            'raw_subject_names' => array_values(array_unique($subjectNames)),
            'subject_names' => self::subjectDisplayNames($subjectNames),
            'subject_reference_text' => self::shotSubjectReferenceText($subjects, $visibleSubjectIds, $subjectNames),
        ];
    }

    private static function shotPromptImpliesVisibleSubject(array $shot, array $params): bool
    {
        $nested = is_array($params['params'] ?? null) ? (array)$params['params'] : [];
        if (!empty($params['selected_subject_ids']) || !empty($nested['selected_subject_ids'])) {
            return true;
        }
        foreach (array_merge((array)($params['selected_mentions'] ?? []), (array)($nested['selected_mentions'] ?? [])) as $mention) {
            if (is_array($mention) && (string)($mention['type'] ?? '') === 'subject') {
                return true;
            }
        }
        $text = implode(' ', array_map('strval', [
            $params['message'] ?? '',
            $nested['message'] ?? '',
            $params['video_prompt'] ?? '',
            $nested['video_prompt'] ?? '',
            $params['visible_prompt'] ?? '',
            $nested['visible_prompt'] ?? '',
            $shot['video_prompt'] ?? '',
            $shot['visual_description'] ?? '',
            $shot['description'] ?? '',
            $shot['action'] ?? '',
            $shot['result'] ?? '',
            $shot['composition'] ?? '',
        ]));
        if (trim($text) === '') {
            return false;
        }
        return preg_match('/(@\S+|人物|角色|主体|人像|肖像|男人|女人|男生|女生|女孩|男孩|老人|孩子|母亲|父亲|妈妈|爸爸|学生|老师|医生|队员|骑手|船员|脸|面部|身体|手|眼神|头发|服装|穿着|青蛙人|怪人|人形|person|people|human|character|face|hand|body)/iu', $text) === 1;
    }

    private static function shotFlexibleImagePrompt(array $shot, array $params): string
    {
        $nested = is_array($params['params'] ?? null) ? (array)$params['params'] : [];
        $prompt = self::firstNonEmptyString(
            $params['image_prompt'] ?? '',
            $nested['image_prompt'] ?? '',
            $params['visible_prompt'] ?? '',
            $nested['visible_prompt'] ?? '',
            $params['message'] ?? '',
            $nested['message'] ?? '',
            $params['prompt'] ?? '',
            $nested['prompt'] ?? '',
            $shot['image_prompt'] ?? ''
        );
        $prompt = self::cleanShotFlexiblePromptText($prompt, [
            (string)($shot['video_prompt'] ?? ''),
            (string)($shot['bgm_prompt'] ?? ''),
            (string)($params['bgm_prompt'] ?? ''),
        ]);
        if ($prompt === '') {
            $fallback = self::buildShotImagePromptFallback($shot);
            if ($fallback !== '') {
                $prompt = $fallback;
            }
        }
        return $prompt;
    }

    private static function shotFlexibleVideoPrompt(array $shot, array $params): string
    {
        $nested = is_array($params['params'] ?? null) ? (array)$params['params'] : [];
        $prompt = self::firstNonEmptyString(
            $params['video_prompt'] ?? '',
            $nested['video_prompt'] ?? '',
            $params['visible_prompt'] ?? '',
            $nested['visible_prompt'] ?? '',
            $params['message'] ?? '',
            $nested['message'] ?? '',
            $params['prompt'] ?? '',
            $nested['prompt'] ?? '',
            $shot['video_prompt'] ?? ''
        );
        $prompt = self::cleanShotFlexiblePromptText($prompt, [
            (string)($shot['image_prompt'] ?? ''),
            (string)($shot['bgm_prompt'] ?? ''),
            (string)($params['bgm_prompt'] ?? ''),
        ]);
        if ($prompt === '') {
            $fallback = self::buildShotVideoPromptFallback($shot);
            if ($fallback !== '') {
                $prompt = $fallback;
            }
        }
        return $prompt;
    }

    private static function separateSubjectNamesInText(string $text, array $subjectNames): string
    {
        $names = array_values(array_unique(array_filter(array_map(static fn($name): string => trim((string)$name), $subjectNames))));
        if (count($names) <= 1 || trim($text) === '') {
            return $text;
        }
        $joined = implode('', $names);
        if ($joined !== '') {
            $text = str_replace($joined, implode('、', $names), $text);
        }
        return $text;
    }

    private static function subjectDisplayNames(array $subjectNames): array
    {
        $names = array_values(array_unique(array_filter(array_map(static fn($name): string => trim((string)$name), $subjectNames))));
        if (count($names) <= 1) {
            return $names;
        }
        foreach ($names as $index => &$name) {
            if ($index < count($names) - 1) {
                $name .= '、';
            }
        }
        unset($name);
        $names[count($names) - 1] .= '（' . count($names) . '个彼此独立的主体，分别参考各自主体参考图；各主体之间保留清晰空间边界和独立轮廓；不要融合、合体、附着、共享肢体、脸、服装、盔甲、尾巴或机械结构）';
        return $names;
    }

    private static function shotSubjectReferenceText(array $subjects, array $visibleSubjectIds, array $subjectNames): string
    {
        $names = array_values(array_unique(array_filter(array_map(static fn($name): string => trim((string)$name), $subjectNames))));
        if (empty($names)) {
            return '';
        }
        if (count($names) === 1) {
            return $names[0] . '（独立主体，严格参考对应主体参考图）';
        }
        $parts = [];
        foreach ($names as $index => $name) {
            $subject = self::planItemByExactId($subjects, (string)($visibleSubjectIds[$index] ?? ''));
            $category = self::normalizeSubjectCategory($subject);
            $label = $category === 'prop' ? '道具主体' : '角色主体';
            $parts[] = ($index + 1) . '. ' . $name . '（' . $label . '，只参考自己的主体参考图）';
        }
        return '本镜头包含' . count($names) . '个彼此独立的主体：' . implode('；', $parts)
            . '。必须把它们画成不同个体，分别保持各自外貌、服装、材质和轮廓；不要融合成一个人，不要共享肢体、脸、服装、盔甲、尾巴或机械结构。';
    }

    private static function shotImageFixedPrompt(array $context, string $flexiblePrompt): array
    {
        $shot = (array)$context['shot'];
        $scene = (array)$context['scene'];
        $mentionsComposition = self::flexiblePromptMentionsComposition($flexiblePrompt);
        $mentionsStyle = self::flexiblePromptMentionsStyle($flexiblePrompt);
        $sceneText = self::shotImagePromptInlineParts([
            (string)($scene['name'] ?? ''),
            (string)($shot['scene_name'] ?? ''),
            (string)($shot['time_of_day'] ?? ''),
            (string)($shot['interior_exterior'] ?? ''),
        ]);
        $compositionText = self::shotImagePromptInlineParts([
            self::cleanShotImagePromptText((string)($shot['composition'] ?? '')),
            (string)($shot['shot_type'] ?? ''),
            (string)($shot['angle'] ?? ''),
        ]);
        $actionText = self::shotImagePromptInlineParts([
            self::cleanShotImagePromptText((string)($shot['action'] ?? '')),
            self::cleanShotImagePromptText((string)($shot['result'] ?? '')),
        ]);
        $actionText = self::separateSubjectNamesInText($actionText, (array)($context['raw_subject_names'] ?? $context['subject_names']));
        $styleText = self::cleanPromptRatioText((string)($context['plan']['art_style']['visual_description'] ?? ''));
        $fixedRule = !empty($context['no_subject_shot'])
            ? '固定要求：保持场景布局、光线和美术风格一致，画面中不要出现人物、角色、脸、身体、肖像。画面清晰，无文字、水印、字幕'
            : '固定要求：保持角色身份、服装、道具、场景布局、光线和美术风格一致。画面清晰，无文字、水印、字幕';
        return [
            self::promptLine('当前绑定主体', !empty($context['no_subject_shot']) ? '空镜，无可见主体' : implode('', (array)$context['subject_names'])),
            self::promptLine('当前绑定场景', $sceneText),
            !$mentionsComposition ? self::promptLine('构图', $compositionText) : '',
            empty($context['no_subject_shot']) ? self::promptLine('动作结果', $actionText) : '',
            self::promptLine('氛围', self::cleanShotImagePromptText((string)($shot['atmosphere'] ?? ''))),
            !$mentionsStyle ? self::promptLine('整体风格', $styleText) : '',
            $fixedRule,
        ];
    }

    private static function cleanShotFlexiblePromptText(string $prompt, array $removeLines = []): string
    {
        $originalPrompt = trim($prompt);
        $removeSet = [];
        foreach ($removeLines as $line) {
            $line = trim(self::cleanPromptRatioText((string)$line));
            if ($line !== '') {
                $removeSet[$line] = true;
            }
        }
        $prompt = self::cleanPromptRatioText($prompt);
        $lines = preg_split('/\R/u', $prompt);
        if ($lines === false) {
            $lines = preg_split('/\R/', $prompt);
        }
        if ($lines === false || empty($lines)) {
            $lines = [$prompt];
        }
        $result = [];
        foreach ($lines as $line) {
            $line = trim((string)$line);
            if ($line === '' || isset($removeSet[$line])) {
                continue;
            }
            $line = preg_replace('/^\s*[-*•\d\.、\)）]+\s*/u', '', $line) ?? $line;
            $line = preg_replace('/^\s*["\']?(?:image_prompt|video_prompt|visual_prompt|prompt|生图提示词|图像提示词|视频提示词|生视频提示词|视频生成提示词|用户画面描述|用户运动描述)["\']?\s*[：:]\s*/iu', '', $line) ?? $line;
            $line = preg_replace('/[，、]{2,}/u', '', $line) ?? $line;
            $line = self::trimPromptText($line);
            if ($line !== '') {
                $result[] = $line;
            }
        }
        $cleaned = implode("\n", array_values(array_unique($result)));
        if ($cleaned !== '') {
            return $cleaned;
        }
        $fallback = self::cleanPromptRatioText($originalPrompt);
        return self::trimPromptText($fallback !== '' ? $fallback : $originalPrompt);
    }

    private static function trimPromptText(string $text): string
    {
        $text = trim($text);
        return preg_replace('/^[,，、。；;：:\'\"]+|[,，、。；;：:\'\"]+$/u', '', $text) ?? $text;
    }

    private static function promptLine(string $label, string $value): string
    {
        $value = trim($value);
        return $value === '' ? '' : $label . '：' . $value;
    }

    private static function cleanShotImagePromptText(string $prompt, array $removeLines = []): string
    {
        $removeSet = [];
        foreach ($removeLines as $line) {
            $line = trim((string)$line);
            if ($line !== '') {
                $removeSet[$line] = true;
            }
        }
        $prompt = self::cleanPromptRatioText($prompt);
        $lines = preg_split('/\R/u', $prompt) ?: [];
        $blockedPatterns = [
            '/^\s*(背景音乐情绪|背景音乐|BGM|音乐|对白或配音提示|对白或旁白提示|对白|配音提示|旁白提示|时长目标|Duration target)\s*[：:]/iu',
            '/^\s*(运镜调度|运镜|镜头运动|Camera movement)\s*[：:]/iu',
            '/^\s*(0-3秒|3-6秒|0-3s|3-6s)\s*[：:]/iu',
            '/^\s*(景别|角度|构图设计|构图|可见动作|动作|画面结果|结果|氛围|场景|时间)\s*[：:]\s*$/u',
            '/^\s*(镜头目的|剧情作用|剧情推进|叙事目的|叙事功能|情绪目标|表演重点|导演意图|剪辑点|转场|下一拍|单一视觉任务|视觉任务)\s*[：:]/u',
            '/(完成本镜头|推动剧情|进入下一拍|承接上一镜|为后续[^，。；\n]{0,20}铺垫|表现人物内心|暗示人物心理|预示后续|呼应前文|埋下伏笔|制造悬念|增强戏剧冲突|交代剧情|用于视频生成|用于后续生成)/u',
        ];
        $result = [];
        foreach ($lines as $line) {
            $line = trim((string)$line);
            if ($line === '' || isset($removeSet[$line])) {
                continue;
            }
            foreach ($blockedPatterns as $pattern) {
                if (preg_match($pattern, $line)) {
                    continue 2;
                }
            }
            $line = self::stripShotImagePromptFieldLabel($line);
            $line = self::stripShotImagePlanningPhrases($line);
            if ($line === '' || self::isInvisibleShotImageNarrativeLine($line)) {
                continue;
            }
            $result[] = $line;
        }
        return implode("\n", array_values(array_unique($result)));
    }

    private static function stripShotImagePromptFieldLabel(string $line): string
    {
        $fieldLabels = 'image_prompt|visual_prompt|prompt|画面提示词|生图提示词|图像提示词|分镜画面|画面描述|视觉描述|视觉画面|主体|当前主体|场景|当前场景|构图设计|构图|景别|角度|可见动作|动作|画面结果|结果|氛围|整体风格|风格';
        $line = preg_replace('/^\s*[-*•\d\.、\)）]+\s*/u', '', $line) ?? $line;
        $line = preg_replace('/^\s*["\']?(?:' . $fieldLabels . ')["\']?\s*[：:]\s*/iu', '', $line) ?? $line;
        $line = preg_replace('/([，。；;、])\s*(?:' . $fieldLabels . ')\s*[：:]\s*/iu', '$1', $line) ?? $line;
        return self::trimPromptText($line);
    }

    private static function stripShotImagePlanningPhrases(string $line): string
    {
        $line = preg_replace('/(?:完成)?本镜头(?:的)?(?:单一)?视觉任务/u', '', $line) ?? $line;
        $line = preg_replace('/(?:推动剧情(?:进入下一拍)?|进入下一拍)/u', '', $line) ?? $line;
        $line = preg_replace('/(?:精准|清楚)?传达情绪升级/u', '呈现清晰可见的表情或动作', $line) ?? $line;
        $line = preg_replace('/(?:为了|用于|以便)[^，。；\n]{0,24}(?:后续生成|剧情推进|下一拍|转场)/u', '', $line) ?? $line;
        $line = preg_replace('/[，、]{2,}/u', '', $line) ?? $line;
        return self::trimPromptText($line);
    }

    private static function isInvisibleShotImageNarrativeLine(string $line): bool
    {
        $hasInvisibleNarrative = preg_match('/(内心|心理|想起|意识到|明白|决定|回忆起|知道|认为|感到|感觉到|听到|闻到|旁白|台词|对白|配音|BGM|背景音乐)/iu', $line) === 1;
        if (!$hasInvisibleNarrative) {
            return false;
        }
        return preg_match('/(画面|镜头|场景|背景|光|阴影|构图|特写|近景|中景|远景|表情|眼神|动作|姿态|服装|道具|脸|手|身体|站|坐|走|跑|看|望|拿|握|打开|推|拉|哭|笑|皱眉|低头|转身|凝视|出现)/u', $line) !== 1;
    }

    private static function shotImagePromptVisibleLength(string $prompt): int
    {
        $text = preg_replace('/\s+/u', '', $prompt) ?? '';
        return mb_strlen($text, 'UTF-8');
    }

    private static function buildShotImagePromptFallback(array $shot): string
    {
        return self::cleanShotImagePromptText(self::joinPromptParts([
            (string)($shot['visual_description'] ?? ''),
            (string)($shot['action'] ?? ''),
            (string)($shot['scene_name'] ?? ''),
            (string)($shot['composition'] ?? ''),
            (string)($shot['atmosphere'] ?? ''),
        ]));
    }

    private static function filterShotPromptVisibleSubjects(string $prompt, array $subjects, array $visibleSubjectIds): string
    {
        $visibleSet = array_flip(array_values(array_unique(array_map('strval', $visibleSubjectIds))));
        $visibleNames = [];
        $offscreenNames = [];
        foreach ($subjects as $subject) {
            if (!is_array($subject)) {
                continue;
            }
            $id = (string)($subject['id'] ?? '');
            $names = array_values(array_filter(array_map(static fn($value): string => trim((string)$value), [
                $subject['name'] ?? '',
                $subject['title'] ?? '',
                $subject['standard_name'] ?? '',
                $subject['display_name'] ?? '',
                $subject['item_name'] ?? '',
                $subject['role_name'] ?? '',
            ]), static fn(string $value): bool => mb_strlen($value, 'UTF-8') >= 2));
            if ($id !== '' && isset($visibleSet[$id])) {
                $visibleNames = array_merge($visibleNames, $names);
            } else {
                $offscreenNames = array_merge($offscreenNames, $names);
            }
        }
        $visibleNames = array_values(array_unique($visibleNames));
        $offscreenNames = array_values(array_unique($offscreenNames));
        if (empty($offscreenNames)) {
            return $prompt;
        }

        $lines = preg_split('/\R/u', $prompt) ?: [$prompt];
        $filtered = [];
        foreach ($lines as $line) {
            $line = trim((string)$line);
            if ($line === '') {
                continue;
            }
            $hasOffscreen = false;
            foreach ($offscreenNames as $name) {
                if ($name !== '' && str_contains($line, $name)) {
                    $hasOffscreen = true;
                    break;
                }
            }
            if (!$hasOffscreen) {
                $filtered[] = $line;
                continue;
            }
            $hasVisible = false;
            foreach ($visibleNames as $name) {
                if ($name !== '' && str_contains($line, $name)) {
                    $hasVisible = true;
                    break;
                }
            }
            if (!$hasVisible) {
                continue;
            }
            foreach ($offscreenNames as $name) {
                $line = preg_replace('/(?:和|与|及|、)?\s*' . preg_quote($name, '/') . '\s*(?:和|与|及|、)?/u', '', $line) ?? $line;
            }
            $line = self::trimPromptText(preg_replace('/[，、]{2,}/u', '', $line) ?? $line);
            if ($line !== '') {
                $filtered[] = $line;
            }
        }
        return implode("\n", array_values(array_unique($filtered)));
    }

    private static function shotImagePromptInlineParts(array $parts): string
    {
        return implode('', array_values(array_unique(array_filter(array_map(static function ($part): string {
            return trim((string)$part);
        }, $parts)))));
    }

    private static function readablePromptInlineParts(array $parts): string
    {
        $result = '';
        foreach (array_values(array_unique(array_filter(array_map(static function ($part): string {
            return trim((string)$part);
        }, $parts)))) as $part) {
            if ($result === '') {
                $result = $part;
                continue;
            }
            $result .= preg_match('/[。！？；;，,]$/u', $result) === 1 ? $part : '，' . $part;
        }
        return $result;
    }

    private static function buildShotVideoPrompt(array $shot, array $params, array $plan): string
    {
        $duration = (int)($params['duration'] ?? $shot['recommended_duration_seconds'] ?? 3);
        $duration = max(1, $duration);
        $context = self::shotPromptContext($shot, $params, $plan);
        $flexiblePrompt = self::shotFlexibleVideoPrompt($context['shot'], $params);
        $flexiblePrompt = self::separateSubjectNamesInText($flexiblePrompt, (array)($context['raw_subject_names'] ?? $context['subject_names']));
        $readablePrompt = self::normalizeReadableShotVideoPrompt($flexiblePrompt, $context['shot']);
        $noSubjectShot = !empty($context['no_subject_shot']);
        return self::buildTaggedSingleShotVideoPrompt($context['shot'], array_merge($context, [
            'duration' => $duration,
            'director_prompt' => $readablePrompt,
            'reference_assets' => (array)($params['reference_assets'] ?? []),
            'input_asset_ids' => (array)($params['input_asset_ids'] ?? []),
            'first_frame_rule' => !empty($params['has_first_frame_image'])
                ? ($noSubjectShot
                    ? '首帧约束：以当前首帧为开场，不改变构图、场景、光线和色彩'
                    : '首帧约束：以当前首帧为开场，不改变人物脸、发型、服装、道具、构图、场景和光线')
                : '',
            'last_frame_rule' => !empty($params['has_last_frame_image']) ? '尾帧约束：自然过渡到尾帧目标画面' : '',
        ]));
    }

    private static function shotVideoFixedPrompt(array $context, array $params, int $duration, string $flexiblePrompt): array
    {
        $shot = (array)$context['shot'];
        $scene = (array)$context['scene'];
        $mentionsComposition = self::flexiblePromptMentionsComposition($flexiblePrompt);
        $mentionsCamera = self::flexiblePromptMentionsCamera($flexiblePrompt);
        $mentionsStyle = self::flexiblePromptMentionsStyle($flexiblePrompt);
        $sceneText = self::shotImagePromptInlineParts([
            (string)($scene['name'] ?? ''),
            (string)($shot['scene_name'] ?? ''),
            (string)($shot['time_of_day'] ?? ''),
            (string)($shot['interior_exterior'] ?? ''),
        ]);
        $compositionText = self::shotImagePromptInlineParts([
            self::cleanShotVideoPromptText((string)($shot['composition'] ?? '')),
            (string)($shot['shot_type'] ?? ''),
            (string)($shot['angle'] ?? ''),
        ]);
        $actionText = self::shotImagePromptInlineParts([
            self::cleanShotVideoPromptText((string)($shot['action'] ?? '')),
            self::cleanShotVideoPromptText((string)($shot['result'] ?? '')),
        ]);
        $actionText = self::separateSubjectNamesInText($actionText, (array)($context['raw_subject_names'] ?? $context['subject_names']));
        $styleText = self::cleanPromptRatioText((string)($context['plan']['art_style']['visual_description'] ?? ''));
        $noSubjectShot = !empty($context['no_subject_shot']);
        $fixedRule = $noSubjectShot
            ? '固定要求：画面中无角色出现，全程无人说话。保持场景、光线和美术风格一致，画面中不要出现人物、角色、脸、身体、肖像，无字幕、无BGM背景音乐、无水印、无文字'
            : '固定要求：保持角色身份、脸部、发型、服装、道具、场景、光线和美术风格一致，无新增角色，无字幕、无BGM背景音乐、无水印、无文字';
        $firstFrameRule = '';
        if (!empty($params['has_first_frame_image'])) {
            $firstFrameRule = $noSubjectShot
                ? '首帧约束：以当前首帧为开场，不改变构图、场景、光线和色彩'
                : '首帧约束：以当前首帧为开场，不改变人物脸、发型、服装、道具、构图、场景和光线';
        }
        return [
            self::promptLine('当前绑定主体', $noSubjectShot ? '空镜，无可见主体' : implode('', (array)$context['subject_names'])),
            self::promptLine('当前绑定场景', $sceneText),
            !$mentionsComposition ? self::promptLine('构图', $compositionText) : '',
            !$mentionsCamera ? self::promptLine('运镜', self::cleanShotVideoPromptText((string)($shot['camera_movement'] ?? ''))) : '',
            !$noSubjectShot ? self::promptLine('动作结果', $actionText) : '',
            self::promptLine('氛围', self::cleanShotVideoPromptText((string)($shot['atmosphere'] ?? ''))),
            !$mentionsStyle ? self::promptLine('整体风格', $styleText) : '',
            self::promptLine('视频时长', (string)max(1, $duration) . ''),
            self::buildShotDialogueText($shot, $noSubjectShot),
            $firstFrameRule,
            !empty($params['has_last_frame_image']) ? '尾帧约束：自然过渡到尾帧目标画面' : '',
            $fixedRule,
        ];
    }

    private static function flexiblePromptMentionsComposition(string $prompt): bool
    {
        return self::flexiblePromptMatches($prompt, '/(构图|景别|角度|视角|近景|中景|远景|全景|大全景|特写|极近特写|俯拍|仰拍|平视|侧面|正面|背面|过肩|三分法|对称构图|中心构图|低角度|高角度|广角|长焦|鱼眼|medium shot|close[- ]?up|wide shot|long shot|full shot|eye level|low angle|high angle|over[- ]?the[- ]?shoulder|rule of thirds)/iu');
    }

    private static function flexiblePromptMentionsCamera(string $prompt): bool
    {
        return self::flexiblePromptMatches($prompt, '/(运镜|镜头运动|镜头|固定镜头|推镜头|推近|推进|拉镜头|拉远|后退|横摇|摇镜|上摇|下摇|跟拍|追拍|手持|环绕|旋转|移动镜头|俯冲|升降|变焦|zoom|pan|tilt|tracking|handheld)/iu');
    }

    private static function flexiblePromptMentionsStyle(string $prompt): bool
    {
        return self::flexiblePromptMatches($prompt, '/(风格|美术|光线|光影|灯光|色调|色彩|冷色|暖色|暗黑|明亮|电影感|写实|胶片|颗粒|质感|赛博|复古|卡通|动漫|水彩|油画|国风|氛围|高对比|低饱和|高饱和|style|lighting|cinematic|realistic|film|grain|tone|color|warm|cold|dark|bright)/iu');
    }

    private static function flexiblePromptMatches(string $prompt, string $pattern): bool
    {
        $prompt = trim($prompt);
        if ($prompt === '') {
            return false;
        }
        $matched = @preg_match($pattern, $prompt);
        if ($matched === 1) {
            return true;
        }
        $lastDelimiter = strrpos($pattern, '/');
        if ($lastDelimiter === false) {
            return false;
        }
        $fallbackPattern = substr($pattern, 0, $lastDelimiter + 1) . str_replace('u', '', substr($pattern, $lastDelimiter + 1));
        return @preg_match($fallbackPattern, $prompt) === 1;
    }

    private static function composeShotProviderPrompt(string $flexiblePrompt, array $fixedParts): string
    {
        return self::normalizeFinalProviderPrompt(self::joinPromptParts(array_merge([
            $flexiblePrompt,
        ], $fixedParts)));
    }

    private static function normalizeFinalProviderPrompt(string $prompt): string
    {
        $prompt = self::cleanPromptRatioText($prompt);
        $prompt = self::localizeGenerationPromptText($prompt);
        $prompt = preg_replace('/[ \t]+/u', ' ', $prompt) ?? $prompt;
        $prompt = preg_replace('/[，、]{2,}/u', '', $prompt) ?? $prompt;
        $prompt = preg_replace('/。{2,}/u', '', $prompt) ?? $prompt;
        $prompt = preg_replace('/\n{3,}/u', "\n\n", $prompt) ?? $prompt;
        return self::trimPromptText($prompt);
    }

    private static function firstNonEmptyString(...$values): string
    {
        foreach ($values as $value) {
            $current = trim((string)$value);
            if ($current !== '') {
                return $current;
            }
        }
        return '';
    }

    private static function buildReadableShotVideoPrompt(array $shot, int $index = 0, array $timeline = []): string
    {
        $duration = max(1, (int)round((float)($shot['recommended_duration_seconds'] ?? $shot['duration'] ?? 3)));
        $timeRange = self::readableShotTimeRange($shot, $index, $timeline, $duration);
        $primaryVisual = self::cleanShotVideoPromptText((string)($shot['visual_description'] ?? ''));
        if ($primaryVisual === '') {
            $primaryVisual = self::cleanShotVideoPromptText((string)($shot['image_prompt'] ?? ''));
        }
        $content = self::readablePromptInlineParts([
            $primaryVisual,
            self::cleanShotVideoPromptText((string)($shot['action'] ?? '')),
            self::cleanShotVideoPromptText((string)($shot['result'] ?? '')),
        ]);
        if ($content === '') {
            $content = self::cleanShotVideoPromptText((string)($shot['description'] ?? ''));
        }
        if ($content === '') {
            $content = self::isNoSubjectShot($shot) ? '空镜环境保持稳定，呈现当前场景的光线、空间和氛围变化' : '当前可见主体完成一个清晰可见的动作变化';
        }

        return self::joinPromptParts([
            '分镜' . self::readableShotId($shot, $index) . '：' . $timeRange,
            self::promptLine('景别', self::normalizeShotTypeLabel((string)($shot['shot_type'] ?? '')) ?: '普通画面'),
            self::promptLine('构图', (string)($shot['composition'] ?? '') ?: '按当前画面主体稳定构'),
            self::promptLine('运镜手法', (string)($shot['camera_movement'] ?? '') ?: '固定镜头'),
            self::promptLine('画面内容', $content),
            self::promptLine('声音', self::readableShotSoundText($shot)),
        ]);
    }

    private static function normalizeReadableShotVideoPrompt(string $prompt, array $shot, int $index = 0, array $timeline = []): string
    {
        $prompt = trim(self::cleanShotVideoPromptText($prompt));
        $values = self::readableShotVideoPromptValues($prompt);
        $shotId = self::readableShotId($shot, $index);
        $duration = max(1, (int)round((float)($shot['recommended_duration_seconds'] ?? $shot['duration'] ?? 3)));
        $timeRange = self::readableShotTimeRange($shot, $index, $timeline, $duration);
        $primaryVisual = self::cleanShotVideoPromptText((string)($shot['visual_description'] ?? ''));
        if ($primaryVisual === '') {
            $primaryVisual = self::cleanShotVideoPromptText((string)($shot['image_prompt'] ?? ''));
        }
        $fallbackContent = self::readablePromptInlineParts([
            $primaryVisual,
            self::cleanShotVideoPromptText((string)($shot['action'] ?? '')),
            self::cleanShotVideoPromptText((string)($shot['result'] ?? '')),
        ]);
        if ($fallbackContent === '') {
            $fallbackContent = self::cleanShotVideoPromptText((string)($shot['description'] ?? ''));
        }
        if ($fallbackContent === '') {
            $fallbackContent = self::isNoSubjectShot($shot) ? '空镜环境保持稳定，呈现当前场景的光线、空间和氛围变化' : '当前可见主体完成一个清晰可见的动作变化';
        }

        $content = (string)($values['画面内容'] ?? '');
        if (self::isGenericReadableShotContent($content)) {
            $content = '';
        }
        if ($content === '' && $prompt !== '' && empty($values)) {
            $content = $prompt;
        }
        $shotType = self::normalizeShotTypeLabel((string)($values['景别'] ?? $shot['shot_type'] ?? '普通画面'));
        if (in_array($shotType, ['普通画面'], true) && trim((string)($shot['shot_type'] ?? '')) !== '') {
            $shotType = self::normalizeShotTypeLabel((string)$shot['shot_type']);
        }
        $composition = (string)($values['构图'] ?? $shot['composition'] ?? '按当前画面主体稳定构');
        if (preg_match('/^按当前画面主体稳定构/u', $composition) === 1 && trim((string)($shot['composition'] ?? '')) !== '') {
            $composition = (string)$shot['composition'];
        }
        $camera = (string)($values['运镜手法'] ?? $values['运镜'] ?? $shot['camera_movement'] ?? '固定镜头');
        if ($camera === '固定镜头' && trim((string)($shot['camera_movement'] ?? '')) !== '') {
            $camera = (string)$shot['camera_movement'];
        }
        $sound = (string)($values['声音'] ?? self::readableShotSoundText($shot));
        if (str_contains($sound, '画面中') && !str_contains($sound, '；')) {
            $sound = self::readableShotSoundText($shot);
        }

        return self::joinPromptParts([
            '分镜' . $shotId . '：' . ((string)($values['分镜'] ?? '') ?: $timeRange),
            self::promptLine('景别', $shotType),
            self::promptLine('构图', $composition),
            self::promptLine('运镜手法', $camera),
            self::promptLine('画面内容', $content !== '' ? $content : $fallbackContent),
            self::promptLine('声音', $sound),
        ]);
    }

    private static function isGenericReadableShotContent(string $content): bool
    {
        $content = trim($content);
        return $content === ''
            || preg_match('/^分镜[一二三四五六七八九十百千万\d]+(?:\s*[：:、\-].*)?$/u', $content) === 1
            || preg_match('/^当前分镜画面自然呈现。?$/u', $content) === 1;
    }

    private static function normalizeShotTypeLabel(string $shotType): string
    {
        $shotType = trim($shotType);
        return preg_match('/^普通画[~～。.\s]*$/u', $shotType) === 1 ? '普通画面' : $shotType;
    }

    private static function readableShotVideoPromptValues(string $prompt): array
    {
        $values = [];
        $skipStaleShotBlock = false;
        foreach (preg_split('/\R/u', trim($prompt)) ?: [] as $line) {
            $line = trim((string)$line);
            if ($line === '') {
                continue;
            }
            if (preg_match('/^分镜\s*([一二三四五六七八九十百千万\d]*)\s*[：:]\s*(\d{1,2}:\d{2}\s*[-–—]\s*\d{1,2}:\d{2}.*)$/u', $line, $matches)) {
                $values['分镜'] = trim((string)$matches[2]);
                continue;
            }
            if (preg_match('/^(景别|构图|运镜手法|运镜(?!手法)|画面内容|声音)\s*[：:]\s*(.*)$/u', $line, $matches)) {
                $label = (string)$matches[1] === '运镜' ? '运镜手法' : (string)$matches[1];
                $value = trim((string)$matches[2]);
                if ($value === '') {
                    continue;
                }
                if ($label === '画面内容' && self::isGenericReadableShotContent($value)) {
                    $skipStaleShotBlock = true;
                    continue;
                }
                if ($skipStaleShotBlock && $label !== '画面内容') {
                    continue;
                }
                $skipStaleShotBlock = false;
                $values[$label] = $value;
                continue;
            }
            if (preg_match('/^(景别|构图|运镜手法|运镜(?!手法)|画面内容|声音)\s*(.+)$/u', $line, $matches)) {
                $label = (string)$matches[1] === '运镜' ? '运镜手法' : (string)$matches[1];
                $value = trim((string)$matches[2]);
                if ($value === '') {
                    continue;
                }
                if ($label === '画面内容' && self::isGenericReadableShotContent($value)) {
                    $skipStaleShotBlock = true;
                    continue;
                }
                if ($skipStaleShotBlock && $label !== '画面内容') {
                    continue;
                }
                $skipStaleShotBlock = false;
                $values[$label] = $value;
                continue;
            }
            if ($skipStaleShotBlock) {
                continue;
            }
        }
        return $values;
    }

    private static function readableShotVideoPromptHasRequiredColumns(string $prompt): bool
    {
        $values = self::readableShotVideoPromptValues($prompt);
        foreach (['分镜', '景别', '构图', '运镜手法', '画面内容', '声音'] as $key) {
            if (trim((string)($values[$key] ?? '')) === '') {
                return false;
            }
        }
        return true;
    }

    private static function readableShotId(array $shot, int $index = 0): string
    {
        $shotId = trim((string)($shot['shot_id'] ?? $shot['id'] ?? ''));
        return $shotId !== '' ? $shotId : (string)($index + 1);
    }

    private static function readableShotTimeRange(array $shot, int $index, array $timeline, int $duration): string
    {
        $range = trim((string)($shot['time_range'] ?? ''));
        if ($range !== '') {
            return $range;
        }
        $start = isset($timeline['start_seconds'])
            ? (int)round((float)$timeline['start_seconds'])
            : max(0, (int)($shot['start_seconds'] ?? 0));
        $end = isset($shot['end_seconds'])
            ? max($start + 1, (int)round((float)$shot['end_seconds']))
            : $start + $duration;
        return self::formatReadableTimecode($start) . '-' . self::formatReadableTimecode($end);
    }

    private static function formatReadableTimecode(int $seconds): string
    {
        $seconds = max(0, $seconds);
        $minutes = intdiv($seconds, 60);
        $remaining = $seconds % 60;
        return str_pad((string)$minutes, 2, '0', STR_PAD_LEFT) . ':' . str_pad((string)$remaining, 2, '0', STR_PAD_LEFT);
    }

    private static function readableShotSoundText(array $shot): string
    {
        $soundEffect = trim((string)($shot['sound_effect'] ?? ''));
        $soundPrefix = $soundEffect !== ''
            ? self::trimPromptText($soundEffect) . '；'
            : '轻微环境声；';
        if (self::isNoSubjectShot($shot)) {
            return $soundPrefix . '画面中无角色出现，全程无人说话';
        }
        $dialogue = trim((string)($shot['dialogue'] ?? ''));
        if ($dialogue === '') {
            return $soundPrefix . '画面中所有角色全程不说话';
        }
        $voiceRole = trim((string)($shot['voice_role'] ?? ''));
        if (str_contains($voiceRole, '旁白') || str_contains($voiceRole, '画外')) {
            return $soundPrefix . '画外音响起：' . $dialogue . ($voiceRole !== '' ? '（音色：' . $voiceRole . '）' : '');
        }
        return $soundPrefix . ($voiceRole !== '' ? $voiceRole : '角色') . '开口说：“' . $dialogue . '”';
    }

    private static function buildTaggedSingleShotVideoPrompt(array $shot, array $context): string
    {
        $duration = max(1, (int)($context['duration'] ?? $shot['recommended_duration_seconds'] ?? 3));
        $durationMs = self::shotDurationMilliseconds($duration);
        $noSubjectShot = !empty($context['no_subject_shot']);
        $locationText = self::buildShotLocationTags(
            $shot,
            (array)($context['locations'] ?? []),
            (array)($context['reference_assets'] ?? []),
            (array)($context['input_asset_ids'] ?? [])
        );
        if ($locationText === '') {
            $locationText = trim((string)($shot['scene_name'] ?? $shot['act'] ?? '当前分镜场景'));
        }
        $roleText = $noSubjectShot ? '' : self::buildShotRoleTags(
            $shot,
            (array)($context['subjects'] ?? []),
            (array)($context['visible_subject_ids'] ?? []),
            (array)($context['reference_assets'] ?? []),
            (array)($context['input_asset_ids'] ?? [])
        );
        $subjectNameText = implode('', array_values(array_filter(array_map('strval', (array)($context['subject_names'] ?? [])))));
        $shootTarget = $noSubjectShot ? '空镜环境' : ($roleText !== '' ? $roleText : ($subjectNameText !== '' ? $subjectNameText : '当前可见角色'));
        $directorPrompt = trim((string)($context['director_prompt'] ?? ''));
        if ($directorPrompt === '') {
            $directorPrompt = self::normalizeReadableShotVideoPrompt((string)($shot['video_prompt'] ?? ''), $shot);
        }
        $dialogueText = preg_match('/(?:^|\R)\s*声音\s*[：:]/u', $directorPrompt) === 1
            ? ''
            : self::buildShotDialogueText($shot, $noSubjectShot);
        $consistencyRule = $noSubjectShot
            ? '固定要求：保持场景、光线和美术风格一致，画面中不要出现人物、角色、脸、身体、肖像，无字幕、无BGM背景音乐、无水印、无文字'
            : '固定要求：保持人物身份、脸部、发型、服装、道具、场景、光线和美术风格一致，无新增角色，无字幕、无BGM背景音乐、无水印、无文字';
        if (!$noSubjectShot && count((array)($context['raw_subject_names'] ?? $context['subject_names'] ?? [])) > 1) {
            $consistencyRule .= ' 多主体规则：每个主体必须保持独立个体和清晰边界，不要融合、合体、附着、共享肢体、脸、服装、盔甲、尾巴或机械结构。';
        }
        $parts = [
            $directorPrompt,
            '当前绑定场景：' . $locationText,
            $noSubjectShot ? '当前绑定主体：空镜，无可见主体' : '当前绑定主体：' . $shootTarget,
            '<duration-ms>' . $durationMs . '</duration-ms>',
            $dialogueText,
            (string)($context['first_frame_rule'] ?? ''),
            (string)($context['last_frame_rule'] ?? ''),
            (string)($context['consistency_rule'] ?? $consistencyRule),
        ];
        return preg_replace('/。{2,}/u', '', self::joinPromptParts($parts)) ?? self::joinPromptParts($parts);
    }

    private static function buildShotLocationTags(array $shot, array $locations, array $referenceAssets, array $inputAssetIds): string
    {
        $sceneRef = self::resolveShotSceneRef($shot, $locations);
        $location = self::planItemById($locations, $sceneRef);
        $locationName = trim((string)($location['name'] ?? $location['title'] ?? $shot['scene_name'] ?? $sceneRef));
        $asset = self::findPromptReferenceAsset($referenceAssets, ['scene_image'], $sceneRef, $locationName, [
            'scene_id',
            'scene_ref_id',
            'location_id',
            'item_id',
        ], [
            'scene_name',
            'location_name',
            'item_name',
            'name',
        ]);
        if (!empty($asset)) {
            $tagId = self::resolvePromptAssetTagId($asset);
            if (!empty($tagId)) {
                return self::promptAssetTag('location', self::promptTagLabel($sceneRef, 'L', $locationName ?: '场景'), $tagId);
            }
        }
        return $locationName !== '' ? $locationName : '当前分镜场景';
    }

    private static function buildShotRoleTags(array $shot, array $subjects, array $visibleSubjectIds, array $referenceAssets, array $inputAssetIds): string
    {
        $tags = [];
        foreach (array_values(array_unique(array_filter(array_map('strval', $visibleSubjectIds)))) as $subjectId) {
            $subject = self::planItemById($subjects, $subjectId);
            if (empty($subject)) {
                continue;
            }
            $subjectName = trim((string)($subject['name'] ?? $subject['title'] ?? $subjectId));
            $asset = self::findPromptReferenceAsset($referenceAssets, ['subject_image', 'three_view'], $subjectId, $subjectName, [
                'subject_id',
                'subject_ref_id',
                'character_id',
                'item_id',
            ], [
                'subject_name',
                'character_name',
                'item_name',
                'name',
            ]);
            if (!empty($asset)) {
                $tagId = self::resolvePromptAssetTagId($asset);
                if (!empty($tagId)) {
                    $tags[] = self::promptAssetTag('role', self::promptTagLabel($subjectId, 'R', $subjectName ?: '角色'), $tagId);
                    continue;
                }
            }
            if ($subjectName !== '') {
                $tags[] = $subjectName;
            }
        }
        return implode('；', array_values(array_unique(array_filter($tags))));
    }

    private static function findPromptReferenceAsset(array $referenceAssets, array $assetTypes, string $refId, string $name, array $idKeys, array $nameKeys): array
    {
        $normalizedName = self::normalizePlanMatchText($name);
        foreach ($referenceAssets as $asset) {
            if (!is_array($asset)) {
                continue;
            }
            $assetType = (string)($asset['asset_type'] ?? '');
            if (!in_array($assetType, $assetTypes, true)) {
                continue;
            }
            $meta = (array)($asset['meta'] ?? []);
            foreach ($idKeys as $key) {
                $candidate = trim((string)($meta[$key] ?? ''));
                if ($candidate !== '' && $refId !== '' && $candidate === $refId) {
                    return $asset;
                }
            }
            foreach ($nameKeys as $key) {
                $candidateName = self::normalizePlanMatchText((string)($meta[$key] ?? ''));
                if ($candidateName !== '' && $normalizedName !== '' && ($candidateName === $normalizedName || str_contains($candidateName, $normalizedName) || str_contains($normalizedName, $candidateName))) {
                    return $asset;
                }
            }
            $assetName = self::normalizePlanMatchText((string)($asset['name'] ?? ''));
            if ($assetName !== '' && $normalizedName !== '' && ($assetName === $normalizedName || str_contains($assetName, $normalizedName) || str_contains($normalizedName, $assetName))) {
                return $asset;
            }
        }
        return [];
    }

    private static function resolvePromptAssetTagId(array $asset): array
    {
        $meta = (array)($asset['meta'] ?? []);
        $providerKeys = [
            'pippit_asset_id',
            'material_pippit_asset_id',
            'provider_asset_id',
            'provider_material_id',
            'material_asset_id',
            'external_asset_id',
            'asset_id',
        ];
        foreach ($providerKeys as $key) {
            $value = trim((string)($asset[$key] ?? $meta[$key] ?? ''));
            if ($value !== '') {
                return ['attr' => 'data-material-pippit-asset-id', 'value' => $value];
            }
        }
        foreach (['pippit', 'provider', 'material'] as $key) {
            $nested = is_array($meta[$key] ?? null) ? (array)$meta[$key] : [];
            $value = trim((string)($nested['asset_id'] ?? $nested['id'] ?? ''));
            if ($value !== '') {
                return ['attr' => 'data-material-pippit-asset-id', 'value' => $value];
            }
        }
        $systemId = (int)($asset['id'] ?? 0);
        if ($systemId > 0) {
            return ['attr' => 'data-short-drama-asset-id', 'value' => (string)$systemId];
        }
        return [];
    }

    private static function promptAssetTag(string $tagName, string $label, array $tagId): string
    {
        $attr = preg_replace('/[^a-z0-9_\-:]/i', '', (string)($tagId['attr'] ?? '')) ?: 'data-short-drama-asset-id';
        $value = htmlspecialchars((string)($tagId['value'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $label = htmlspecialchars($label, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return '<' . $tagName . ' ' . $attr . '="' . $value . '">' . $label . '</' . $tagName . '>';
    }

    private static function promptTagLabel(string $refId, string $prefix, string $fallback): string
    {
        if (preg_match('/(\d+)$/', $refId, $matches)) {
            return $prefix . (string)$matches[1];
        }
        return trim($fallback) !== '' ? trim($fallback) : $prefix . '1';
    }

    private static function buildShotDialogueText(array $shot, bool $noSubjectShot): string
    {
        if ($noSubjectShot) {
            return '画面中无角色出现，全程无人说话';
        }
        $dialogue = trim((string)($shot['dialogue'] ?? ''));
        if ($dialogue === '') {
            return '全程无人说话';
        }
        $voiceRole = trim((string)($shot['voice_role'] ?? ''));
        return $voiceRole !== ''
            ? '台词' . $voiceRole . '：' . $dialogue . '”'
            : '台词：' . $dialogue . '”';
    }

    private static function shotDurationMilliseconds(int $seconds): int
    {
        return max(1, $seconds) * 1000;
    }

    private static function buildDefaultVideoPrompt(array $shot): string
    {
        return self::buildReadableShotVideoPrompt($shot);
    }

    private static function cleanShotVideoPromptText(string $prompt, array $removeLines = []): string
    {
        $originalPrompt = trim($prompt);
        $removeSet = [];
        foreach ($removeLines as $line) {
            $line = trim((string)$line);
            if ($line !== '') {
                $removeSet[$line] = true;
            }
        }
        $prompt = self::cleanPromptRatioText($prompt);
        $lines = preg_split('/\R/u', $prompt) ?: [];
        $blockedPatterns = [
            '/^\s*(video_prompt|视频提示词|生视频提示词|视频生成提示词|生成视频片段|视频片段|导演说明|分镜说明|镜头目的|剧情作用|叙事目的|情绪目标)\s*[：:]/iu',
            '/^\s*(当前绑定主体|当前绑定场景|动作结果|固定要求|首帧约束|尾帧约束|视频时长|整体风格|氛围)\s*[：:]?/u',
            '/^\s*<duration-ms>\s*\d+\s*<\/duration-ms>\s*$/iu',
            '/^\s*<\/?(?:role|location)(?:\s+[^>]*)?>.*$/iu',
            '/(情绪升级|推动剧情|视觉任务|本镜头|下一拍|做出反应|生成视频片段|承接上一镜|为后续[^，。；\n]{0,20}铺垫|交代剧情|用于视频生成)/u',
        ];
        $result = [];
        foreach ($lines as $line) {
            $line = trim((string)$line);
            if ($line === '' || isset($removeSet[$line])) {
                continue;
            }
            foreach ($blockedPatterns as $pattern) {
                if (preg_match($pattern, $line)) {
                    continue 2;
                }
            }
            $line = preg_replace('/^\s*[-*•\d\.、\)）]+\s*/u', '', $line) ?? $line;
            $line = preg_replace('/^\s*["\']?(?:video_prompt|视频提示词|生视频提示词|视频生成提示词|动作指令|首帧状态|中间动作|结束状态|镜头运动|环境运动)["\']?\s*[：:]\s*/iu', '', $line) ?? $line;
            $line = preg_replace('/[，、]{2,}/u', '', $line) ?? $line;
            $line = self::trimPromptText($line);
            if ($line !== '') {
                $result[] = $line;
            }
        }
        if (!empty($result)) {
            return implode("\n", array_values(array_unique($result)));
        }
        return $originalPrompt;
    }

    private static function buildShotVideoPromptFallback(array $shot): string
    {
        return self::cleanShotVideoPromptText(self::joinPromptParts([
            (string)($shot['visual_description'] ?? ''),
            (string)($shot['action'] ?? ''),
            (string)($shot['camera_movement'] ?? ''),
            (string)($shot['composition'] ?? ''),
            (string)($shot['atmosphere'] ?? ''),
            (string)($shot['result'] ?? ''),
        ]));
    }

    private static function joinPromptParts(array $parts): string
    {
        return implode("\n", array_values(array_filter(array_map(static fn($part) => trim((string)$part), $parts))));
    }

    private static function planItemByExactId(array $items, string $id): array
    {
        if ($id === '') {
            return [];
        }
        foreach ($items as $item) {
            if (is_array($item) && (string)($item['id'] ?? '') === $id) {
                return $item;
            }
        }
        return [];
    }

    private static function normalizePlanMatchText(string $text): string
    {
        $text = mb_strtolower(trim($text), 'UTF-8');
        return preg_replace('/[\s\p{P}\p{S}]+/u', '', $text) ?: '';
    }

    private static function splitPlanRefTokens($value): array
    {
        if (is_array($value)) {
            $tokens = [];
            foreach ($value as $item) {
                $tokens = array_merge($tokens, self::splitPlanRefTokens($item));
            }
            return array_values(array_unique(array_filter($tokens)));
        }
        $text = trim((string)$value);
        if ($text === '') {
            return [];
        }
        $decoded = self::jsonDecode($text);
        if (!empty($decoded)) {
            return self::splitPlanRefTokens($decoded);
        }
        return array_values(array_unique(array_filter(array_map('trim', preg_split('/[\s,，、；;|\/]+/u', $text) ?: []))));
    }

    private static function planItemIdByToken(array $items, string $token): string
    {
        $token = trim($token);
        if ($token === '') {
            return '';
        }
        foreach ($items as $item) {
            if (is_array($item) && (string)($item['id'] ?? '') === $token) {
                return (string)$item['id'];
            }
        }
        $normalizedToken = self::normalizePlanMatchText($token);
        if ($normalizedToken === '') {
            return '';
        }
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $id = (string)($item['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $names = array_filter(array_map('strval', [
                $item['name'] ?? '',
                $item['title'] ?? '',
                $item['standard_name'] ?? '',
                $item['display_name'] ?? '',
                $item['item_name'] ?? '',
                $item['role_name'] ?? '',
            ]));
            foreach ($names as $name) {
                $normalizedName = self::normalizePlanMatchText($name);
                if ($normalizedName !== '' && ($normalizedName === $normalizedToken || str_contains($normalizedName, $normalizedToken) || str_contains($normalizedToken, $normalizedName))) {
                    return $id;
                }
            }
        }
        return '';
    }

    private static function resolvePlanItemRefFromPayload(array $payload, array $items, array $keys): string
    {
        foreach ($keys as $key) {
            foreach (self::splitPlanRefTokens($payload[$key] ?? '') as $token) {
                $id = self::planItemIdByToken($items, $token);
                if ($id !== '') {
                    return $id;
                }
            }
        }
        return '';
    }

    private static function resolveShotSceneRef(array $shot, array $locations): string
    {
        $sceneRef = trim((string)($shot['scene_ref_id'] ?? $shot['scene_ref'] ?? $shot['location_id'] ?? ''));
        if (!empty(self::planItemByExactId($locations, $sceneRef))) {
            return $sceneRef;
        }
        $sceneName = trim((string)($shot['scene_name'] ?? ''));
        if ($sceneName === '') {
            $sceneName = $sceneRef;
        }
        if ($sceneName === '') {
            return $sceneRef;
        }
        foreach ($locations as $location) {
            if (!is_array($location)) {
                continue;
            }
            $locationName = trim((string)($location['name'] ?? $location['location'] ?? ''));
            if ($locationName !== '' && (str_contains($locationName, $sceneName) || str_contains($sceneName, $locationName))) {
                return (string)($location['id'] ?? $sceneRef);
            }
        }
        return $sceneRef;
    }

    private static function resolveShotSubjectRefs(array $shot, array $subjects): array
    {
        $subjectRefs = $shot['subject_ref_ids'] ?? $shot['subject_refs'] ?? $shot['character_ids'] ?? [];
        if (empty(self::splitPlanRefTokens($subjectRefs))) {
            $subjectRefs = self::selectedSubjectRefTokens($shot);
        }
        $resolvedRefs = [];
        foreach (self::splitPlanRefTokens($subjectRefs) as $token) {
            $id = self::planItemIdByToken($subjects, $token);
            if ($id !== '') {
                $resolvedRefs[] = $id;
            }
        }
        $resolvedRefs = array_values(array_unique($resolvedRefs));
        if (!empty($resolvedRefs)) {
            return $resolvedRefs;
        }
        if (is_string($subjectRefs)) {
            $rawSubjectRefs = $subjectRefs;
            $subjectRefs = self::jsonDecode($subjectRefs);
            if (empty($subjectRefs) && trim($rawSubjectRefs) !== '') {
                $subjectRefs = preg_split('/[,，、；;\s]+/u', $rawSubjectRefs) ?: [];
            }
        }
        $validIds = array_values(array_filter(array_map(static fn($subject): string => is_array($subject) ? (string)($subject['id'] ?? '') : '', $subjects)));
        $validSet = array_flip($validIds);
        $nameToId = [];
        foreach ($subjects as $subject) {
            if (!is_array($subject)) {
                continue;
            }
            $id = (string)($subject['id'] ?? '');
            $name = trim((string)($subject['name'] ?? ''));
            if ($id !== '' && $name !== '') {
                $nameToId[$name] = $id;
            }
        }
        $refs = array_values(array_unique(array_filter(array_map('strval', (array)$subjectRefs))));
        $refs = array_values(array_filter(array_map(static function (string $id) use ($validSet, $nameToId): string {
            if (isset($validSet[$id])) {
                return $id;
            }
            return (string)($nameToId[$id] ?? '');
        }, $refs)));
        if (!empty($refs)) {
            return $refs;
        }

        $haystack = implode("\n", array_map('strval', [
            $shot['title'] ?? '',
            $shot['visual_description'] ?? '',
            $shot['action'] ?? '',
            $shot['result'] ?? '',
            $shot['dialogue'] ?? '',
            $shot['image_prompt'] ?? '',
            $shot['video_prompt'] ?? '',
            $shot['message'] ?? '',
            $shot['prompt'] ?? '',
            $shot['user_prompt'] ?? '',
        ]));
        $matched = [];
        foreach ($subjects as $subject) {
            if (!is_array($subject)) {
                continue;
            }
            $id = (string)($subject['id'] ?? '');
            $name = trim((string)($subject['name'] ?? ''));
            $quotedName = preg_quote($name, '/');
            $isNegated = $name !== '' && (
                preg_match('/' . $quotedName . '[^，。；、\n]{0,12}(不在画面|不在镜头|未出现|没有出现|不出现|不入镜)/u', $haystack)
                || preg_match('/(不在画面|不在镜头|未出现|没有出现|不出现|不入镜)[^，。；、\n]{0,12}' . $quotedName . '/u', $haystack)
            );
            if ($id !== '' && $name !== '' && !$isNegated && str_contains($haystack, $name)) {
                $matched[] = $id;
            }
        }
        $matched = array_values(array_unique($matched));
        if (!empty($matched)) {
            return $matched;
        }

        $groupMentioned = preg_match('/(情侣|夫妻|两人|二人|两个人|一对|双方|男女|couple|lovers|man and woman|boy and girl)/iu', $haystack) === 1;
        if (!$groupMentioned) {
            return [];
        }
        $characterIds = [];
        foreach ($subjects as $subject) {
            if (!is_array($subject)) {
                continue;
            }
            $id = (string)($subject['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $text = implode(' ', array_map('strval', [
                $subject['name'] ?? '',
                $subject['type'] ?? '',
                $subject['category'] ?? '',
                $subject['description'] ?? '',
            ]));
            $isProp = preg_match('/(道具|物品|物件|符号|场景|prop|object|item|symbol)/iu', $text) === 1;
            $isCharacter = preg_match('/(角色|人物|主角|配角|男|女|人|man|woman|boy|girl|person|character)/iu', $text) === 1;
            if (!$isProp && ($isCharacter || count($subjects) <= 3)) {
                $characterIds[] = $id;
            }
        }
        return array_values(array_unique($characterIds));
    }

    private static function planItemById(array $items, string $id): array
    {
        foreach ($items as $item) {
            if (is_array($item) && $id !== '' && (string)($item['id'] ?? '') === $id) {
                return $item;
            }
        }
        foreach ($items as $item) {
            if (is_array($item)) {
                return $item;
            }
        }
        return [];
    }

    private static function currentPlanVersion(int $tenantId, int $userId, int $projectId): array
    {
        $row = AigcShortDramaPlanVersion::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'project_id' => $projectId,
            'is_current' => 1,
            'delete_time' => 0,
        ])->order(['version_no' => 'desc', 'id' => 'desc'])->findOrEmpty();
        if (!$row->isEmpty()) {
            self::cleanStoredStoryboardForVersionRow($tenantId, $userId, $projectId, $row->toArray());
            $row = AigcShortDramaPlanVersion::where([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'project_id' => $projectId,
                'is_current' => 1,
                'delete_time' => 0,
            ])->order(['version_no' => 'desc', 'id' => 'desc'])->findOrEmpty();
        }
        return $row->isEmpty() ? [] : self::formatPlanVersion($row->toArray(), true);
    }

    private static function planVersions(int $tenantId, int $userId, int $projectId): array
    {
        $rows = AigcShortDramaPlanVersion::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'project_id' => $projectId,
            'delete_time' => 0,
        ])->order(['version_no' => 'desc', 'id' => 'desc'])->select()->toArray();
        return array_map(static fn($row) => self::formatPlanVersion($row, false), $rows);
    }

    private static function storyboardImageAssetRows(int $tenantId, int $userId, int $projectId): array
    {
        $shotRows = AigcShortDramaStoryboard::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'project_id' => $projectId,
            'delete_time' => 0,
        ])->field('shot_id,scene_name,sort')
            ->order(['sort' => 'asc', 'id' => 'asc'])
            ->select()
            ->toArray();

        $shotOrder = [];
        $shotMeta = [];
        foreach ($shotRows as $index => $shot) {
            $shotId = (string)($shot['shot_id'] ?? '');
            if ($shotId === '') {
                continue;
            }
            $shotOrder[$shotId] = $index;
            $shotMeta[$shotId] = [
                'shot_title' => (string)($shot['scene_name'] ?? ''),
                'shot_sort' => (int)($shot['sort'] ?? 0),
            ];
        }

        $assets = AigcShortDramaAsset::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'project_id' => $projectId,
            'asset_type' => 'shot_image',
            'status' => 'ready',
            'delete_time' => 0,
        ])->order(['id' => 'desc'])->select()->toArray();

        foreach ($assets as &$asset) {
            $shotId = (string)($asset['shot_id'] ?? '');
            $asset['shot_order'] = $shotOrder[$shotId] ?? PHP_INT_MAX;
            $asset['shot_title'] = $shotMeta[$shotId]['shot_title'] ?? (string)($asset['title'] ?? '');
            $asset['shot_sort'] = $shotMeta[$shotId]['shot_sort'] ?? 0;
        }
        unset($asset);

        usort($assets, static function ($left, $right) {
            $leftShot = (int)($left['shot_order'] ?? PHP_INT_MAX);
            $rightShot = (int)($right['shot_order'] ?? PHP_INT_MAX);
            if ($leftShot !== $rightShot) {
                return $leftShot <=> $rightShot;
            }
            return (int)($right['id'] ?? 0) <=> (int)($left['id'] ?? 0);
        });

        return $assets;
    }

    private static function projectCoverCandidateAssets(int $tenantId, int $userId, int $projectId): array
    {
        $shotRows = AigcShortDramaStoryboard::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'project_id' => $projectId,
            'delete_time' => 0,
        ])->field('shot_id,scene_name,sort,selected_image_asset_id')
            ->order(['sort' => 'asc', 'id' => 'asc'])
            ->select()
            ->toArray();

        $shotOrder = [];
        $shotMeta = [];
        $selectedIds = [];
        foreach ($shotRows as $index => $shot) {
            $shotId = (string)($shot['shot_id'] ?? '');
            if ($shotId === '') {
                continue;
            }
            $shotOrder[$shotId] = $index;
            $shotMeta[$shotId] = [
                'shot_title' => (string)($shot['scene_name'] ?? ''),
                'shot_sort' => (int)($shot['sort'] ?? 0),
            ];
            $selectedId = (int)($shot['selected_image_asset_id'] ?? 0);
            if ($selectedId > 0) {
                $selectedIds[] = $selectedId;
            }
        }
        $selectedIds = array_values(array_unique($selectedIds));

        $assets = [];
        $seen = [];
        if (!empty($selectedIds)) {
            $selectedAssets = AigcShortDramaAsset::where([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'project_id' => $projectId,
                'delete_time' => 0,
            ])->whereIn('id', $selectedIds)->select()->toArray();
            foreach ($selectedAssets as $asset) {
                self::appendProjectCoverCandidate($assets, $seen, $asset, $shotOrder, $shotMeta, $selectedIds);
            }
        }

        $shotAssets = AigcShortDramaAsset::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'project_id' => $projectId,
            'asset_type' => 'shot_image',
            'delete_time' => 0,
        ])->where('uri', '<>', '')
            ->order(['id' => 'desc'])
            ->select()
            ->toArray();
        foreach ($shotAssets as $asset) {
            self::appendProjectCoverCandidate($assets, $seen, $asset, $shotOrder, $shotMeta, $selectedIds);
        }

        usort($assets, static function ($left, $right) {
            $leftShot = (int)($left['shot_order'] ?? PHP_INT_MAX);
            $rightShot = (int)($right['shot_order'] ?? PHP_INT_MAX);
            if ($leftShot !== $rightShot) {
                return $leftShot <=> $rightShot;
            }
            $leftSelected = (int)($left['cover_selected'] ?? 0);
            $rightSelected = (int)($right['cover_selected'] ?? 0);
            if ($leftSelected !== $rightSelected) {
                return $rightSelected <=> $leftSelected;
            }
            return (int)($right['id'] ?? 0) <=> (int)($left['id'] ?? 0);
        });

        return $assets;
    }

    private static function appendProjectCoverCandidate(array &$assets, array &$seen, array $asset, array $shotOrder, array $shotMeta, array $selectedIds): void
    {
        $id = (int)($asset['id'] ?? 0);
        if ($id <= 0 || isset($seen[$id]) || !self::isProjectCoverImageAsset($asset)) {
            return;
        }
        $shotId = (string)($asset['shot_id'] ?? '');
        if ($shotId === '' || !array_key_exists($shotId, $shotOrder)) {
            return;
        }
        $asset['shot_order'] = $shotOrder[$shotId] ?? PHP_INT_MAX;
        $asset['shot_title'] = $shotMeta[$shotId]['shot_title'] ?? (string)($asset['title'] ?? '');
        $asset['shot_sort'] = $shotMeta[$shotId]['shot_sort'] ?? 0;
        $asset['cover_selected'] = in_array($id, $selectedIds, true) ? 1 : 0;
        $assets[] = $asset;
        $seen[$id] = true;
    }

    private static function isProjectCoverImageAsset(array $asset): bool
    {
        if (trim((string)($asset['uri'] ?? '')) === '') {
            return false;
        }
        if ((string)($asset['asset_type'] ?? '') !== 'shot_image') {
            return false;
        }
        if ((string)($asset['status'] ?? '') !== 'ready') {
            return false;
        }
        $mimeType = strtolower((string)($asset['mime_type'] ?? ''));
        if ($mimeType !== '' && (str_starts_with($mimeType, 'video/') || str_starts_with($mimeType, 'audio/'))) {
            return false;
        }
        return true;
    }

    private static function firstStoryboardImageAsset(int $tenantId, int $userId, int $projectId): array
    {
        $assets = self::storyboardImageAssetRows($tenantId, $userId, $projectId);
        return $assets[0] ?? [];
    }

    private static function firstProjectShotImageAsset(int $tenantId, int $userId, int $projectId): array
    {
        $assets = self::projectCoverCandidateAssets($tenantId, $userId, $projectId);
        return $assets[0] ?? [];
    }

    private static function projectCoverData(array $row): array
    {
        $coverUri = trim((string)($row['cover_url'] ?? ''));
        $fallbackUri = self::fallbackCoverUrl();
        $assets = self::projectCoverCandidateAssets(
            (int)($row['tenant_id'] ?? 0),
            (int)($row['user_id'] ?? 0),
            (int)($row['id'] ?? 0)
        );
        if ($coverUri !== '' && $coverUri !== $fallbackUri) {
            foreach ($assets as $asset) {
                if ((string)($asset['uri'] ?? '') !== $coverUri) {
                    continue;
                }
                $formatted = self::formatAsset($asset);
                return [
                    'asset_id' => (int)($asset['id'] ?? 0),
                    'uri' => (string)($asset['uri'] ?? ''),
                    'url' => (string)($formatted['url'] ?? ''),
                ];
            }
        }

        $asset = $assets[0] ?? [];
        if (!empty($asset)) {
            $formatted = self::formatAsset($asset);
            return [
                'asset_id' => (int)($asset['id'] ?? 0),
                'uri' => (string)($asset['uri'] ?? ''),
                'url' => (string)($formatted['url'] ?? ''),
            ];
        }

        return [
            'asset_id' => 0,
            'uri' => '',
            'url' => '',
        ];
    }

    private static function projectAssets(int $tenantId, int $userId, int $projectId, string $assetType = '', string $sourceTaskId = ''): array
    {
        $query = AigcShortDramaAsset::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'project_id' => $projectId,
            'delete_time' => 0,
        ]);
        if ($assetType !== '') {
            $query->where('asset_type', self::normalizeAssetType($assetType));
        }
        $rows = $query->order(['id' => 'desc'])->select()->toArray();
        if ($sourceTaskId !== '') {
            $generationTaskIds = AigcShortDramaGenerationTask::where([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'project_id' => $projectId,
                'source_task_id' => $sourceTaskId,
                'delete_time' => 0,
            ])->column('task_id');
            $generationTaskMap = array_fill_keys(array_map('strval', (array)$generationTaskIds), true);
            $rows = array_values(array_filter($rows, static function (array $row) use ($sourceTaskId, $generationTaskMap): bool {
                if ((string)($row['task_id'] ?? '') === $sourceTaskId) {
                    return true;
                }
                if (isset($generationTaskMap[(string)($row['task_id'] ?? '')])) {
                    return true;
                }
                $meta = self::jsonDecode((string)($row['meta_json'] ?? ''));
                $metaSourceTaskId = (string)($meta['source_task_id'] ?? $meta['project_task_id'] ?? '');
                return $metaSourceTaskId === $sourceTaskId;
            }));
        }
        return array_map([self::class, 'formatAsset'], $rows);
    }

    private static function projectGenerationTasks(int $tenantId, int $userId, int $projectId, string $sourceTaskId = ''): array
    {
        $query = AigcShortDramaGenerationTask::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'project_id' => $projectId,
            'delete_time' => 0,
        ]);
        if ($sourceTaskId !== '') {
            $query->where('source_task_id', $sourceTaskId);
        }
        $rows = $query->order(['id' => 'desc'])->limit(100)->select()->toArray();
        return array_map(static fn($row) => self::formatGenerationTask($row, false), $rows);
    }

    private static function projectPublishedWorks(int $tenantId, int $userId, int $projectId): array
    {
        $rows = AigcShortDramaPublishedWork::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'project_id' => $projectId,
            'delete_time' => 0,
        ])->order(['id' => 'desc'])->select()->toArray();
        return array_map([self::class, 'formatPublishedWork'], $rows);
    }

    private static function currentProjectPlanRaw(int $tenantId, int $userId, int $projectId): array
    {
        $row = AigcShortDramaPlanVersion::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'project_id' => $projectId,
            'is_current' => 1,
            'delete_time' => 0,
        ])->order(['version_no' => 'desc', 'id' => 'desc'])->findOrEmpty();
        if ($row->isEmpty()) {
            return [];
        }
        self::cleanStoredStoryboardForVersionRow($tenantId, $userId, $projectId, $row->toArray());
        $row = AigcShortDramaPlanVersion::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'project_id' => $projectId,
            'is_current' => 1,
            'delete_time' => 0,
        ])->order(['version_no' => 'desc', 'id' => 'desc'])->findOrEmpty();
        if ($row->isEmpty()) {
            return [];
        }
        return self::enhancePlanResult(self::jsonDecode((string)($row['plan_json'] ?? '')));
    }

    private static function enhancePlanResult(array $plan): array
    {
        $subjects = [];
        foreach ((array)($plan['subjects'] ?? []) as $index => $subject) {
            if (!is_array($subject)) {
                continue;
            }
            $visual = trim((string)($subject['visual_prompt'] ?? $subject['description'] ?? ''));
            $category = self::normalizeSubjectCategory(array_merge($subject, [
                'visual_prompt' => $visual,
            ]));
            $isProp = $category === 'prop';
            $defaultMainPrompt = self::joinPromptParts([
                $isProp ? '生成一张短剧关键物品统一参考图' : '生成一张短剧人物主体统一参考图',
                (string)($subject['name'] ?? ''),
                (string)($subject['description'] ?? ''),
                $visual,
                (string)($plan['art_style']['visual_description'] ?? ''),
                $isProp
                    ? '单个物品独立清晰展示，固定材质、颜色、形状、磨损痕迹、尺寸比例和标志性细节。不要出现人物、手、脸、身体、模特或穿戴效果'
                    : '单个角色居中清晰展示，固定脸型、发型、服装、体态、年龄感和气质，可用于后续分镜保持一致'
            ]);
            $defaultThreeViewPrompt = self::joinPromptParts([
                $isProp ? '生成一张短剧关键物品多角度设定图' : '生成一张短剧人物主体三视图设定图',
                (string)($subject['name'] ?? ''),
                $visual,
                $isProp
                    ? '展示同一个物品的正面、侧面、背面、俯视或关键细节，保持材质、颜色、形状和磨损一致。只呈现物品本身，不出现人物、手、脸、身体、模特或穿戴效果'
                    : '展示同一个角色的正面、侧面和背面，保持同一张脸、同一发型、同一服装、同一体态比例和气质'
            ]);
            $defaultMainNegative = self::defaultSubjectNegativePrompt($isProp);
            $defaultThreeNegative = $isProp
                ? self::defaultSubjectNegativePrompt(true)
                : '低质量、模糊、换脸、换装、年龄漂移、比例错误、多余手指、多余肢体、文字、水印、拼贴、档案卡、色卡、参数栏';
            $mainImagePrompt = self::localizeGenerationPromptText((string)($subject['main_image_prompt'] ?? $subject['image_prompt'] ?? $defaultMainPrompt), $visual);
            $threeViewPrompt = self::localizeGenerationPromptText((string)($subject['three_view_prompt'] ?? $defaultThreeViewPrompt), $visual);
            $mainNegativePrompt = $isProp
                ? self::localizeGenerationPromptText((string)($subject['main_negative_prompt'] ?? $subject['negative_prompt'] ?? $defaultMainNegative))
                : self::sanitizeCharacterNegativePrompt(self::localizeGenerationPromptText((string)($subject['main_negative_prompt'] ?? $subject['negative_prompt'] ?? $defaultMainNegative)));
            $threeViewNegativePrompt = $isProp
                ? self::localizeGenerationPromptText((string)($subject['three_view_negative_prompt'] ?? $subject['negative_prompt'] ?? $defaultThreeNegative))
                : self::sanitizeCharacterNegativePrompt(self::localizeGenerationPromptText((string)($subject['three_view_negative_prompt'] ?? $subject['negative_prompt'] ?? $defaultThreeNegative)));
            $subjects[] = array_merge($subject, [
                'id' => (string)($subject['id'] ?? ('subject_' . ($index + 1))),
                'category' => $category,
                'role_label' => (string)($subject['role_label'] ?? $subject['description'] ?? ''),
                'appearance_lock' => (string)($subject['appearance_lock'] ?? $visual),
                'face_lock' => (string)($subject['face_lock'] ?? ''),
                'hair_lock' => (string)($subject['hair_lock'] ?? ''),
                'outfit_lock' => (string)($subject['outfit_lock'] ?? ''),
                'temperament' => (string)($subject['temperament'] ?? ''),
                'visual_prompt' => $visual,
                'main_image_prompt' => $mainImagePrompt,
                'three_view_prompt' => $threeViewPrompt,
                'main_negative_prompt' => $mainNegativePrompt,
                'three_view_negative_prompt' => $threeViewNegativePrompt,
                'image_prompt' => $mainImagePrompt,
                'negative_prompt' => $mainNegativePrompt,
            ]);
        }

        $locations = [];
        foreach ((array)($plan['scenes'] ?? $plan['locations'] ?? []) as $index => $location) {
            if (!is_array($location)) {
                continue;
            }
            $visual = trim((string)($location['visual_prompt'] ?? $location['description'] ?? ''));
            $defaultScenePrompt = self::joinPromptParts([
                '生成一张短剧场景统一参考图',
                (string)($location['name'] ?? ''),
                (string)($location['description'] ?? ''),
                $visual,
                (string)($plan['art_style']['visual_description'] ?? ''),
                '环境建立图，固定空间结构、光线、色彩和氛围，可复用为后续分镜场景参考。不要人物、角色、文字、水印'
            ]);
            $sceneImagePrompt = self::localizeGenerationPromptText((string)($location['scene_image_prompt'] ?? $location['image_prompt'] ?? $defaultScenePrompt), $visual);
            $sceneNegativePrompt = self::localizeGenerationPromptText((string)($location['scene_negative_prompt'] ?? $location['negative_prompt'] ?? '人物、人类、角色、肖像、脸、身体、文字、水印、标志、拼贴、多宫格'));
            $locations[] = array_merge($location, [
                'id' => (string)($location['id'] ?? ('location_' . ($index + 1))),
                'location' => (string)($location['location'] ?? $location['name'] ?? ''),
                'layout_lock' => (string)($location['layout_lock'] ?? $location['description'] ?? ''),
                'lighting_lock' => (string)($location['lighting_lock'] ?? $location['time_of_day'] ?? ''),
                'color_palette' => (string)($location['color_palette'] ?? ''),
                'visual_prompt' => $visual,
                'scene_image_prompt' => $sceneImagePrompt,
                'scene_negative_prompt' => $sceneNegativePrompt,
                'image_prompt' => $sceneImagePrompt,
                'negative_prompt' => $sceneNegativePrompt,
            ]);
        }

        $storyboard = [];
        foreach ((array)($plan['storyboard'] ?? []) as $index => $shot) {
            if (!is_array($shot)) {
                continue;
            }
            $sceneRef = self::resolveShotSceneRef($shot, $locations);
            $subjectRefs = self::resolveShotSubjectRefs($shot, $subjects);
            $shot = array_merge($shot, [
                'title' => (string)($shot['title'] ?? ('分镜' . ($index + 1))),
                'shot_type' => (string)($shot['shot_type'] ?? $shot['frame_type'] ?? ''),
                'angle' => (string)($shot['angle'] ?? ''),
                'action' => (string)($shot['action'] ?? $shot['visual_description'] ?? ''),
                'result' => (string)($shot['result'] ?? ''),
                'atmosphere' => (string)($shot['atmosphere'] ?? ''),
                'scene_ref_id' => $sceneRef,
                'subject_ref_ids' => $subjectRefs,
            ]);
            if (trim((string)($shot['image_prompt'] ?? '')) === '') {
                $shot['image_prompt'] = self::buildShotImagePrompt($shot, [], ['subjects' => $subjects, 'locations' => $locations]);
            }
            if (trim((string)($shot['video_prompt'] ?? '')) === '') {
                $shot['video_prompt'] = self::buildDefaultVideoPrompt($shot);
            }
            $noSubjectShot = self::isNoSubjectShot($shot);
            if ($noSubjectShot && !empty($shot['subject_ref_ids'])) {
                $shot['subject_ref_ids'] = [];
            }
            if ($noSubjectShot && trim((string)($shot['image_prompt'] ?? '')) !== '') {
                $filteredImagePrompt = self::filterShotPromptVisibleSubjects((string)$shot['image_prompt'], $subjects, []);
                if ($filteredImagePrompt !== '') {
                    $shot['image_prompt'] = $filteredImagePrompt;
                }
            }
            $shot['image_negative_prompt'] = self::shotImageNegativePrompt($shot, $noSubjectShot);
            $shot['video_negative_prompt'] = self::shotVideoNegativePrompt($shot, $noSubjectShot);
            if (!$noSubjectShot) {
                $shot['video_prompt'] = self::stripNoSubjectConstraints((string)$shot['video_prompt']);
            }
            $storyboard[] = $shot;
        }

        $plan['subjects'] = $subjects;
        $plan['locations'] = $locations;
        $plan['scenes'] = $locations;
        $storyboard = self::sortStoryboardBySceneAndOrder($storyboard);
        $elapsedSeconds = 0;
        foreach ($storyboard as $index => $shot) {
            $storyboard[$index]['video_prompt'] = self::normalizeReadableShotVideoPrompt(
                (string)($shot['video_prompt'] ?? ''),
                $shot,
                $index,
                ['start_seconds' => $elapsedSeconds]
            );
            $elapsedSeconds += max(1, (int)round((float)($shot['recommended_duration_seconds'] ?? 3)));
        }
        $plan['storyboard'] = $storyboard;
        $plan['storyboard_breaking_diagnostics'] = self::storyboardBreakingDiagnostics(
            $storyboard,
            $locations,
            [],
            '',
            (array)($plan['storyboard_breaking_diagnostics'] ?? [])
        );
        $durationStats = (array)($plan['duration_stats'] ?? self::durationStats($storyboard, count($locations)));
        $plan['music_plan'] = self::normalizeMusicPlan((array)($plan['music_plan'] ?? []), $storyboard, $durationStats, (array)($plan['art_style'] ?? []), (string)($plan['story_outline'] ?? ''));
        $plan['agents'] = self::logicalAgentDefinitions();
        if (empty($plan['review_report']) || !is_array($plan['review_report'])) {
            $plan['review_report'] = self::reviewPlanResult($plan);
        } else {
            $plan['review_report'] = self::normalizeReviewReport((array)$plan['review_report']);
        }
        $plan['workflow_steps'] = self::workflowSteps(self::STATUS_SUCCESS, (array)$plan['review_report']);
        $plan['export_plan'] = [
            'type' => 'storyboard_concat',
            'asset_type' => 'final_video',
            'order_by' => 'storyboard.sort',
            'requires' => ['shot_video'],
        ];
        return $plan;
    }

    private static function logicalAgentDefinitions(): array
    {
        return [
            ['key' => 'script_planner', 'title' => '剧本策划'],
            ['key' => 'subject_extractor', 'title' => '主体提取'],
            ['key' => 'scene_extractor', 'title' => '场景提取'],
            ['key' => 'storyboard_breaker', 'title' => '分镜拆解'],
            ['key' => 'image_prompt_builder', 'title' => '生图提示'],
            ['key' => 'video_prompt_builder', 'title' => '生视频提示词'],
            ['key' => 'music_prompt_builder', 'title' => '背景音乐提示'],
            ['key' => 'final_exporter', 'title' => '成片导出'],
        ];
    }

    private static function storyboardNumericOrder($value): int
    {
        $number = (int)$value;
        if ($number > 0) {
            return $number;
        }
        if (preg_match('/\d+/', (string)$value, $matches)) {
            return max(0, (int)$matches[0]);
        }
        return 0;
    }

    private static function storyboardSortOrder(array $shot, int $index): int
    {
        $sort = self::storyboardNumericOrder($shot['sort'] ?? 0);
        if ($sort > 0) {
            return $sort;
        }
        $shotId = self::storyboardNumericOrder($shot['shot_id'] ?? $shot['id'] ?? 0);
        return $shotId > 0 ? $shotId : $index + 1;
    }

    private static function sortStoryboardBySceneAndOrder(array $storyboard): array
    {
        $items = [];
        foreach (array_values($storyboard) as $index => $shot) {
            if (!is_array($shot)) {
                continue;
            }
            $items[] = [
                'index' => $index,
                'scene_order' => self::storyboardNumericOrder($shot['scene_order'] ?? $shot['story_order'] ?? 0),
                'shot_order' => self::storyboardSortOrder($shot, $index),
                'shot' => $shot,
            ];
        }
        usort($items, static function (array $a, array $b): int {
            if ($a['scene_order'] > 0 && $b['scene_order'] > 0 && $a['scene_order'] !== $b['scene_order']) {
                return $a['scene_order'] <=> $b['scene_order'];
            }
            if ($a['shot_order'] !== $b['shot_order']) {
                return $a['shot_order'] <=> $b['shot_order'];
            }
            return $a['index'] <=> $b['index'];
        });
        return array_values(array_map(static fn(array $item): array => $item['shot'], $items));
    }

    private static function workflowStepDefinitions(): array
    {
        return [
            ['key' => 'script_planner', 'title' => '剧本策划'],
            ['key' => 'plan_reviewer', 'title' => '计划质检'],
            ['key' => 'subject_preparer', 'title' => '主体整理'],
            ['key' => 'scene_preparer', 'title' => '场景整理'],
            ['key' => 'storyboard_preparer', 'title' => '分镜整理'],
            ['key' => 'prompt_preparer', 'title' => '提示词整'],
            ['key' => 'asset_generator', 'title' => '资产生成'],
            ['key' => 'final_exporter', 'title' => '成片导出'],
        ];
    }

    private static function workflowSteps(string $status, array $reviewReport = []): array
    {
        $steps = self::workflowStepDefinitions();
        $reviewStatus = (string)($reviewReport['status'] ?? '');
        return array_map(static function (array $step, int $index) use ($status, $reviewStatus, $reviewReport): array {
            $stepStatus = 'pending';
            if ($status === self::STATUS_SUCCESS) {
                $stepStatus = 'success';
            } elseif ($status === self::STATUS_FAILED) {
                $stepStatus = $index === 0 ? 'failed' : 'pending';
            } else {
                $stepStatus = $index === 0 ? 'running' : 'pending';
            }
            if ($step['key'] === 'plan_reviewer' && $reviewStatus === 'failed') {
                $stepStatus = 'failed';
            }
            if ($step['key'] === 'plan_reviewer' && in_array($reviewStatus, ['passed', 'repaired'], true)) {
                $stepStatus = 'success';
            }
            $summary = '';
            if ($step['key'] === 'plan_reviewer' && !empty($reviewReport)) {
                $summary = '问题 ' . (int)($reviewReport['issue_count'] ?? 0)
                    . '，阻断 ' . (int)($reviewReport['blocking_count'] ?? 0)
                    . '，代码修复 ' . (int)($reviewReport['code_repair_count'] ?? 0);
            }
            return $step + [
                'status' => $stepStatus,
                'summary' => $summary,
            ];
        }, $steps, array_keys($steps));
    }

    private static function reviewAndRepairPlanResult(array $plan, bool $allowCodeRepair = true, bool $llmRepairUsed = false): array
    {
        $review = self::reviewPlanResult($plan);
        $codeRepairCount = 0;
        if ($allowCodeRepair && (int)$review['issue_count'] > 0) {
            [$plan, $codeRepairCount] = self::codeRepairPlanResult($plan);
            $plan = self::enhancePlanResult($plan);
            $review = self::reviewPlanResult($plan);
        }
        $review['code_repair_count'] = $codeRepairCount;
        $review['llm_repair_used'] = $llmRepairUsed;
        if ($llmRepairUsed && (int)$review['blocking_count'] === 0) {
            $review['status'] = 'repaired';
        } elseif ($codeRepairCount > 0 && (int)$review['blocking_count'] === 0) {
            $review['status'] = 'repaired';
        }
        $plan['review_report'] = self::normalizeReviewReport($review);
        $plan['workflow_steps'] = self::workflowSteps(self::STATUS_SUCCESS, $plan['review_report']);
        return $plan;
    }

    private static function reviewPlanResult(array $plan): array
    {
        $issues = [];
        $subjects = (array)($plan['subjects'] ?? []);
        $locations = (array)($plan['locations'] ?? $plan['scenes'] ?? []);
        $storyboard = (array)($plan['storyboard'] ?? []);
        $subjectIds = array_values(array_filter(array_map(static fn($item): string => is_array($item) ? (string)($item['id'] ?? '') : '', $subjects)));
        $locationIds = array_values(array_filter(array_map(static fn($item): string => is_array($item) ? (string)($item['id'] ?? '') : '', $locations)));

        if (empty($subjects)) {
            $issues[] = self::planReviewIssue('subjects.empty', 'blocking', 'subjects', '缺少主体列表');
        }
        if (empty($locations)) {
            $issues[] = self::planReviewIssue('locations.empty', 'blocking', 'locations', '缺少场景列表');
        }
        if (empty($storyboard)) {
            $issues[] = self::planReviewIssue('storyboard.empty', 'blocking', 'storyboard', '缺少分镜列表');
        }
        $storyboardDiagnostics = self::storyboardBreakingDiagnostics(
            $storyboard,
            $locations,
            [],
            '',
            (array)($plan['storyboard_breaking_diagnostics'] ?? [])
        );

        foreach ($storyboard as $index => $shot) {
            if (!is_array($shot)) {
                $issues[] = self::planReviewIssue('storyboard.invalid_item', 'blocking', 'storyboard.' . $index, '分镜不是有效对象');
                continue;
            }
            $path = 'storyboard.' . $index;
            $shotId = (string)($shot['shot_id'] ?? ($index + 1));
            foreach (['visual_description', 'composition', 'camera_movement', 'image_prompt', 'video_prompt'] as $field) {
                if (trim((string)($shot[$field] ?? '')) === '') {
                    $issues[] = self::planReviewIssue('shot.' . $field . '.empty', 'blocking', $path . '.' . $field, '分镜 ' . $shotId . ' 缺少 ' . $field);
                }
            }
            $sceneRef = trim((string)($shot['scene_ref_id'] ?? ''));
            if ($sceneRef === '' || !in_array($sceneRef, $locationIds, true)) {
                $issues[] = self::planReviewIssue('shot.scene_ref.invalid', 'blocking', $path . '.scene_ref_id', '分镜 ' . $shotId . ' 场景引用不存在');
            }
            foreach (self::splitPlanRefTokens($shot['subject_ref_ids'] ?? []) as $subjectRef) {
                if ($subjectRef !== '' && !in_array($subjectRef, $subjectIds, true)) {
                    $issues[] = self::planReviewIssue('shot.subject_ref.invalid', 'warning', $path . '.subject_ref_ids', '分镜 ' . $shotId . ' 包含不存在的主体引用');
                }
            }
            $duration = (float)($shot['recommended_duration_seconds'] ?? 0);
            if ($duration < 2 || $duration > 5) {
                $issues[] = self::planReviewIssue('shot.duration.invalid', 'warning', $path . '.recommended_duration_seconds', '分镜 ' . $shotId . ' 时长应在 2-5 ');
            }
            $noSubjectShot = self::isNoSubjectShot($shot);
            $imagePrompt = (string)($shot['image_prompt'] ?? '');
            $videoPrompt = (string)($shot['video_prompt'] ?? '');
            $imageNegative = (string)($shot['image_negative_prompt'] ?? '');
            $videoNegative = (string)($shot['video_negative_prompt'] ?? '');
            if (self::hasPlanningPhrases($imagePrompt)) {
                $issues[] = self::planReviewIssue('shot.image_prompt.planning_phrase', 'warning', $path . '.image_prompt', '生图提示词包含策划话');
            }
            if (self::hasPlanningPhrases($videoPrompt)) {
                $issues[] = self::planReviewIssue('shot.video_prompt.planning_phrase', 'warning', $path . '.video_prompt', '生视频提示词包含策划话术');
            }
            if (!self::readableShotVideoPromptHasRequiredColumns($videoPrompt)) {
                $issues[] = self::planReviewIssue('shot.video_prompt.missing_readable_columns', 'warning', $path . '.video_prompt', '生视频提示词缺少栏目化导演稿字段');
            }
            if (!$noSubjectShot && self::hasNoSubjectNegativeTerms($imageNegative . ' ' . $videoNegative . ' ' . $videoPrompt)) {
                $issues[] = self::planReviewIssue('shot.subject_negative.conflict', 'warning', $path, '有人镜头包含不要人物/不要脸等冲突约束');
            }
            if ($noSubjectShot && !self::hasNoSubjectNegativeTerms($imageNegative . ' ' . $videoNegative)) {
                $issues[] = self::planReviewIssue('shot.empty_negative.missing_people_block', 'warning', $path, '空镜缺少人物负向约束');
            }
        }
        if (!empty($storyboard) && empty($storyboardDiagnostics['timeline_override'])) {
            $actualShotCount = (int)($storyboardDiagnostics['actual_shot_count'] ?? count($storyboard));
            $targetMinShots = (int)($storyboardDiagnostics['target_min_shots'] ?? 0);
            $targetMaxShots = (int)($storyboardDiagnostics['target_max_shots'] ?? 0);
            if ($targetMinShots > 0 && $actualShotCount < $targetMinShots) {
                $issues[] = self::planReviewIssue(
                    'storyboard.shot_count_under_range',
                    // Coverage repair already adds the missing shots where it
                    // can. A remaining count mismatch is a quality hint for
                    // editing, not a reason to discard a complete script and
                    // make the user wait for another full-model repair pass.
                    'warning',
                    'storyboard',
                    '实际分镜数量低于命中档位最小范'
                );
            }
            if ($targetMaxShots > 0 && $actualShotCount > $targetMaxShots) {
                $issues[] = self::planReviewIssue('storyboard.shot_count_over_range', 'warning', 'storyboard', '实际分镜数量高于命中档位建议范围');
            }
            $intensityLevel = (string)($storyboardDiagnostics['intensity_level'] ?? '');
            $missingEstablishing = self::missingEstablishingSceneIds($storyboard, $locations);
            if (!empty($missingEstablishing)) {
                $issues[] = self::planReviewIssue('storyboard.missing_establishing_shot', 'warning', 'storyboard', '存在场景缺少建立镜头');
            }
            if (in_array($intensityLevel, ['standard', 'detailed', 'cinematic_detailed'], true) && !self::storyboardHasShotKind($storyboard, 'reaction')) {
                $issues[] = self::planReviewIssue('storyboard.missing_reaction_shot', 'warning', 'storyboard', '标准或精细拆镜缺少明显反应镜');
            }
            if (count($locations) > 1 && in_array($intensityLevel, ['standard', 'detailed', 'cinematic_detailed'], true) && !self::storyboardHasShotKind($storyboard, 'transition')) {
                $issues[] = self::planReviewIssue('storyboard.missing_transition_shot', 'warning', 'storyboard', '多场景拆镜缺少明显转场镜');
            }
            if (in_array($intensityLevel, ['detailed', 'cinematic_detailed'], true) && !self::storyboardHasShotKind($storyboard, 'closeup')) {
                $issues[] = self::planReviewIssue('storyboard.missing_key_closeup', 'warning', 'storyboard', '精细拆镜缺少关键特写或细节镜');
            }
        }

        return self::buildReviewReport($issues, $storyboardDiagnostics);
    }

    private static function codeRepairPlanResult(array $plan): array
    {
        $repairCount = 0;
        $subjects = array_values(array_filter((array)($plan['subjects'] ?? []), 'is_array'));
        $locations = array_values(array_filter((array)($plan['locations'] ?? $plan['scenes'] ?? []), 'is_array'));
        $subjectIds = array_values(array_filter(array_map(static fn(array $item): string => (string)($item['id'] ?? ''), $subjects)));
        $locationIds = array_values(array_filter(array_map(static fn(array $item): string => (string)($item['id'] ?? ''), $locations)));
        $fallbackLocationId = (string)($locationIds[0] ?? '');
        foreach ((array)($plan['storyboard'] ?? []) as $index => $shot) {
            if (!is_array($shot)) {
                continue;
            }
            if ($fallbackLocationId !== '' && !in_array((string)($shot['scene_ref_id'] ?? ''), $locationIds, true)) {
                $shot['scene_ref_id'] = $fallbackLocationId;
                $repairCount++;
            }
            $refs = array_values(array_intersect(self::splitPlanRefTokens($shot['subject_ref_ids'] ?? []), $subjectIds));
            if ($refs !== (array)($shot['subject_ref_ids'] ?? [])) {
                $shot['subject_ref_ids'] = $refs;
                $repairCount++;
            }
            $duration = (float)($shot['recommended_duration_seconds'] ?? 0);
            if ($duration < 2 || $duration > 5) {
                $shot['recommended_duration_seconds'] = max(2, min(5, $duration > 0 ? $duration : 3));
                $repairCount++;
            }
            $noSubjectShot = self::isNoSubjectShot($shot);
            if ($noSubjectShot && !empty($shot['subject_ref_ids'])) {
                $shot['subject_ref_ids'] = [];
                $repairCount++;
            }
            // The planner occasionally omits presentation fields while still
            // returning a complete story. Those fields have deterministic
            // fallbacks and must not discard an otherwise usable plan.
            $visualDescription = trim((string)($shot['visual_description'] ?? ''));
            if ($visualDescription === '') {
                $visualDescription = self::joinPromptParts([
                    (string)($shot['action'] ?? ''),
                    (string)($shot['result'] ?? ''),
                    (string)($shot['title'] ?? ''),
                    (string)($shot['scene_name'] ?? ''),
                ]);
                if ($visualDescription !== '') {
                    $shot['visual_description'] = mb_substr($visualDescription, 0, 2000, 'UTF-8');
                    $repairCount++;
                }
            }
            if (trim((string)($shot['composition'] ?? '')) === '') {
                $shot['composition'] = '主体与场景层次清晰，保持视觉重心稳定';
                $repairCount++;
            }
            if (trim((string)($shot['camera_movement'] ?? '')) === '') {
                $shot['camera_movement'] = '固定镜头，主体动作自然变化';
                $repairCount++;
            }
            $imagePrompt = self::cleanShotImagePromptText((string)($shot['image_prompt'] ?? ''));
            if ($imagePrompt === '') {
                $imagePrompt = self::buildShotImagePrompt($shot, [], [
                    'subjects' => $subjects,
                    'locations' => $locations,
                ]);
                $repairCount++;
            }
            if ($noSubjectShot) {
                $filteredImagePrompt = self::filterShotPromptVisibleSubjects($imagePrompt, $subjects, []);
                if ($filteredImagePrompt !== '') {
                    $imagePrompt = $filteredImagePrompt;
                }
            }
            if ($imagePrompt !== (string)($shot['image_prompt'] ?? '')) {
                $shot['image_prompt'] = $imagePrompt;
                $repairCount++;
            }
            $videoPrompt = self::normalizeReadableShotVideoPrompt((string)($shot['video_prompt'] ?? ''), $shot, $index);
            if (!$noSubjectShot) {
                $videoPrompt = self::stripNoSubjectConstraints($videoPrompt);
            }
            if ($videoPrompt !== (string)($shot['video_prompt'] ?? '')) {
                $shot['video_prompt'] = $videoPrompt;
                $repairCount++;
            }
            $shot['image_negative_prompt'] = self::shotImageNegativePrompt($shot, $noSubjectShot);
            $shot['video_negative_prompt'] = self::shotVideoNegativePrompt($shot, $noSubjectShot);
            $plan['storyboard'][$index] = $shot;
        }
        return [$plan, $repairCount];
    }

    private static function planReviewIssue(string $code, string $severity, string $path, string $message): array
    {
        return [
            'code' => $code,
            'severity' => $severity,
            'path' => $path,
            'message' => $message,
        ];
    }

    private static function buildReviewReport(array $issues, array $storyboardDiagnostics = []): array
    {
        $issues = array_slice(array_values($issues), 0, 80);
        $blockingCount = count(array_filter($issues, static fn(array $issue): bool => (string)($issue['severity'] ?? '') === 'blocking'));
        return [
            'status' => $blockingCount > 0 ? 'failed' : 'passed',
            'issue_count' => count($issues),
            'blocking_count' => $blockingCount,
            'code_repair_count' => 0,
            'llm_repair_used' => false,
            'storyboard_breaking_diagnostics' => $storyboardDiagnostics,
            'issues' => $issues,
        ];
    }

    private static function normalizeReviewReport(array $report): array
    {
        $status = (string)($report['status'] ?? 'passed');
        if (!in_array($status, ['passed', 'repaired', 'failed'], true)) {
            $status = ((int)($report['blocking_count'] ?? 0) > 0) ? 'failed' : 'passed';
        }
        return [
            'status' => $status,
            'issue_count' => (int)($report['issue_count'] ?? count((array)($report['issues'] ?? []))),
            'blocking_count' => (int)($report['blocking_count'] ?? 0),
            'code_repair_count' => (int)($report['code_repair_count'] ?? 0),
            'llm_repair_used' => (bool)($report['llm_repair_used'] ?? false),
            'storyboard_breaking_diagnostics' => is_array($report['storyboard_breaking_diagnostics'] ?? null)
                ? (array)$report['storyboard_breaking_diagnostics']
                : [],
            'issues' => array_slice((array)($report['issues'] ?? []), 0, 80),
        ];
    }

    private static function hasPlanningPhrases(string $text): bool
    {
        return preg_match('/(本镜头|推动剧情|情绪升级|视觉任务|下一拍|生成视频片段|做出反应|镜头编号|参考已提供|角色完成一个单一动作|画面任务)/u', $text) === 1;
    }

    private static function videoPromptHasRequiredMuteConstraints(string $text): bool
    {
        return str_contains($text, '无字') && str_contains($text, '无BGM背景音乐');
    }

    private static function hasNoSubjectNegativeTerms(string $text): bool
    {
        return preg_match('/(不要人物|不要角色|不要脸|不要身体|不要肖像|无人物|无人|无角色|禁止人物|禁止角色|禁止人类)/u', $text) === 1;
    }

    private static function stripPlanRuntimeFields(array $plan): array
    {
        unset($plan['agents'], $plan['workflow_steps'], $plan['review_report'], $plan['export_plan']);
        return $plan;
    }

    private static function latestShotImageAsset(int $tenantId, int $userId, int $projectId, string $shotId): array
    {
        $row = AigcShortDramaAsset::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'project_id' => $projectId,
            'shot_id' => $shotId,
            'asset_type' => 'shot_image',
            'delete_time' => 0,
        ])->order(['id' => 'desc'])->findOrEmpty();
        return $row->isEmpty() ? [] : $row->toArray();
    }

    private static function findGenerationTask(int $tenantId, int $userId, string $taskId): AigcShortDramaGenerationTask
    {
        $task = AigcShortDramaGenerationTask::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'task_id' => $taskId,
            'delete_time' => 0,
        ])->findOrEmpty();
        if ($task->isEmpty()) {
            throw new Exception('生成任务不存在');
        }
        return $task;
    }

    private static function findShot(int $tenantId, int $userId, int $projectId, string $taskId, string $shotId): AigcShortDramaStoryboard
    {
        $query = AigcShortDramaStoryboard::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'project_id' => $projectId,
            'shot_id' => $shotId,
            'delete_time' => 0,
        ]);
        if ($taskId !== '') {
            $query->where('task_id', $taskId);
        }
        $shot = $query->order(['id' => 'desc'])->findOrEmpty();
        if ($shot->isEmpty()) {
            throw new Exception('分镜不存在');
        }
        return $shot;
    }

    private static function touchProject(AigcShortDramaProject $project, array $data): void
    {
        $project->save($data);
    }

    private static function projectGenerationSettingsFromRow(array $row): array
    {
        $settings = self::jsonDecode((string)($row['generation_settings_json'] ?? ''));
        $ratio = self::normalizeGenerationRatio((string)($settings['ratio'] ?? $settings['aspect_ratio'] ?? $row['ratio'] ?? ''));
        if ($ratio !== '') {
            $settings['ratio'] = $ratio;
            $settings['aspect_ratio'] = $ratio;
        }
        return $settings;
    }

    private static function generationSettingModel(array $data, string $kind): array
    {
        $prefix = $kind === 'video' ? 'video_' : 'image_';
        $modelId = trim((string)($data['model_id'] ?? $data[$prefix . 'model_id'] ?? $data['id'] ?? $data['value'] ?? $data['channel_code'] ?? $data['channel'] ?? ''));
        $channelCode = trim((string)($data['channel_code'] ?? $data['channel'] ?? $data['value'] ?? $modelId));
        $resolution = trim((string)($data['resolution'] ?? $data['quality'] ?? $data['default_quality'] ?? ''));
        $ratio = self::normalizeGenerationRatio((string)($data['ratio'] ?? $data['aspect_ratio'] ?? $data['default_ratio'] ?? ''));
        $result = [];
        foreach ([
            'model_id' => $modelId,
            $prefix . 'model_id' => $modelId,
            'id' => trim((string)($data['id'] ?? $modelId)),
            'value' => trim((string)($data['value'] ?? $modelId)),
            'channel_code' => $channelCode,
            'channel' => $channelCode,
            'model_name' => trim((string)($data['model_name'] ?? $data['name'] ?? '')),
            'name' => trim((string)($data['name'] ?? $data['model_name'] ?? '')),
            'resolution' => $resolution,
            'quality' => $resolution,
            'ratio' => $ratio,
            'aspect_ratio' => $ratio,
        ] as $key => $value) {
            if ($value !== '') {
                $result[$key] = $value;
            }
        }
        $duration = (int)($data['duration'] ?? $data['duration_seconds'] ?? 0);
        if ($kind === 'video' && $duration > 0) {
            $result['duration'] = $duration;
            $result['duration_seconds'] = $duration;
        }
        return $result;
    }

    private static function normalizeProjectGenerationSettings(array $settings): array
    {
        $normalized = [];
        $ratio = self::normalizeGenerationRatio((string)($settings['ratio'] ?? $settings['aspect_ratio'] ?? ''));
        if ($ratio !== '') {
            $normalized['ratio'] = $ratio;
            $normalized['aspect_ratio'] = $ratio;
        }
        $imageSource = is_array($settings['image'] ?? null) ? (array)$settings['image'] : $settings;
        $image = self::generationSettingModel($imageSource, 'image');
        if (!empty($image)) {
            if (empty($image['ratio']) && $ratio !== '') {
                $image['ratio'] = $ratio;
                $image['aspect_ratio'] = $ratio;
            }
            $normalized['image'] = $image;
        }
        $videoSource = is_array($settings['video'] ?? null) ? (array)$settings['video'] : $settings;
        $video = self::generationSettingModel($videoSource, 'video');
        if (!empty($video)) {
            if (empty($video['ratio']) && $ratio !== '') {
                $video['ratio'] = $ratio;
                $video['aspect_ratio'] = $ratio;
            }
            $normalized['video'] = $video;
        }
        return $normalized;
    }

    private static function mergeProjectGenerationSettings(array $base, array $incoming): array
    {
        foreach (['ratio', 'aspect_ratio'] as $key) {
            if (!empty($incoming[$key])) {
                $base[$key] = $incoming[$key];
            }
        }
        foreach (['image', 'video'] as $key) {
            if (!empty($incoming[$key]) && is_array($incoming[$key])) {
                $base[$key] = array_merge((array)($base[$key] ?? []), $incoming[$key]);
            }
        }
        return $base;
    }

    private static function projectGenerationSettingsFromRequest(string $ratio, array $request, array $selectedModels): array
    {
        $requestSelections = is_array($request['model_selections'] ?? null) ? (array)$request['model_selections'] : [];
        $requestImageSelection = is_array($requestSelections['image'] ?? null) ? (array)$requestSelections['image'] : [];
        $requestVideoSelection = is_array($requestSelections['video'] ?? null) ? (array)$requestSelections['video'] : [];
        $settings = self::normalizeProjectGenerationSettings([
            'ratio' => $ratio,
            'image' => array_merge((array)($selectedModels['image'] ?? []), [
                'model_id' => $request['image_model_id'] ?? $requestImageSelection['id'] ?? '',
                'resolution' => $request['image_resolution'] ?? $request['resolution'] ?? $request['quality'] ?? '',
                'ratio' => $ratio,
            ]),
            'video' => array_merge((array)($selectedModels['video'] ?? []), [
                'model_id' => $request['video_model_id'] ?? $requestVideoSelection['id'] ?? '',
                'resolution' => $request['video_resolution'] ?? '',
                'duration' => $request['video_duration'] ?? 0,
                'ratio' => $ratio,
            ]),
        ]);
        return $settings;
    }

    private static function updateProjectGenerationSettingsFromTask(AigcShortDramaProject $project, string $taskType, array $params): void
    {
        if (!in_array($taskType, ['subject_image', 'scene_image', 'three_view', 'shot_image', 'shot_video'], true)) {
            return;
        }
        $projectRow = $project->toArray();
        $ratio = self::normalizeGenerationRatio((string)($params['ratio'] ?? $params['aspect_ratio'] ?? $projectRow['ratio'] ?? ''));
        $isVideo = $taskType === 'shot_video' || self::normalizeGenerationMode($params) === 'video_generate';
        $modelData = [
            'model_id' => $params['model_id'] ?? ($isVideo ? ($params['video_model_id'] ?? '') : ($params['image_model_id'] ?? '')),
            'image_model_id' => $params['image_model_id'] ?? $params['model_id'] ?? '',
            'video_model_id' => $params['video_model_id'] ?? $params['model_id'] ?? '',
            'channel_code' => $params['channel_code'] ?? $params['channel'] ?? $params['model_id'] ?? '',
            'channel' => $params['channel'] ?? $params['channel_code'] ?? $params['model_id'] ?? '',
            'model_name' => $params['model_name'] ?? '',
            'resolution' => $params['resolution'] ?? $params['quality'] ?? '',
            'quality' => $params['quality'] ?? $params['resolution'] ?? '',
            'duration' => $params['duration'] ?? 0,
            'ratio' => $ratio,
        ];
        $incoming = ['ratio' => $ratio];
        $incoming[$isVideo ? 'video' : 'image'] = $modelData;
        $settings = self::mergeProjectGenerationSettings(
            self::projectGenerationSettingsFromRow($projectRow),
            self::normalizeProjectGenerationSettings($incoming)
        );
        $update = ['generation_settings_json' => self::jsonEncode($settings)];
        if ($ratio !== '') {
            $update['ratio'] = $ratio;
        }
        $project->save($update);
    }

    private static function isProviderSuccessStatus(string $status): bool
    {
        return in_array(strtolower(trim($status)), [
            self::STATUS_SUCCESS,
            'succeeded',
            'completed',
            'complete',
            'done',
            'finished',
            'finish',
            'ready',
            'ok',
            '1',
        ], true);
    }

    private static function isProviderFailedStatus(string $status): bool
    {
        return in_array(strtolower(trim($status)), [
            self::STATUS_FAILED,
            'fail',
            'error',
            'errored',
            'exception',
            self::STATUS_CANCELED,
            'cancelled',
        ], true);
    }

    private static function markGenerationSuccessFromExistingAssets(int $tenantId, int $userId, array $generation): bool
    {
        $taskId = (string)($generation['task_id'] ?? '');
        $projectId = (int)($generation['project_id'] ?? 0);
        if ($taskId === '' || $projectId <= 0) {
            return false;
        }
        $assetType = (string)($generation['task_type'] ?? '') === 'shot_video'
            ? 'shot_video'
            : self::generationAssetType((string)($generation['task_type'] ?? 'shot_image'));
        $assetIds = array_map('intval', AigcShortDramaAsset::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'project_id' => $projectId,
            'task_id' => $taskId,
            'asset_type' => $assetType,
            'delete_time' => 0,
        ])->column('id'));
        $assetIds = array_values(array_filter($assetIds));
        if (empty($assetIds)) {
            return false;
        }
        AigcShortDramaGenerationTask::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'task_id' => $taskId,
        ])->whereIn('status', [self::STATUS_PENDING, self::STATUS_QUEUED, self::STATUS_RUNNING, self::STATUS_FAILED])->update([
            'status' => self::STATUS_SUCCESS,
            'progress' => 100,
            'result_json' => self::jsonEncode([
                'asset_ids' => $assetIds,
                'message' => '已根据输出资产同步完',
            ]),
            'output_asset_ids' => self::jsonEncode($assetIds),
            'billing_status' => ((float)($generation['tenant_cost_points'] ?? 0) > 0 || (float)($generation['user_charge_points'] ?? 0) > 0) ? 'deducted' : 'none',
            'error_code' => '',
            'error_msg' => '',
            'finished_at' => time(),
            'update_time' => time(),
        ]);
        self::refreshProjectGenerationStatus($tenantId, $userId, $projectId);
        return true;
    }

    private static function refreshProjectGenerationStatus(int $tenantId, int $userId, int $projectId): void
    {
        if ($projectId <= 0) {
            return;
        }
        $activeCount = AigcShortDramaGenerationTask::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'project_id' => $projectId,
            'delete_time' => 0,
        ])->whereIn('status', [self::STATUS_PENDING, self::STATUS_QUEUED, self::STATUS_RUNNING])->count();
        if ($activeCount > 0) {
            return;
        }
        AigcShortDramaProject::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'id' => $projectId,
            'delete_time' => 0,
        ])->whereIn('status', [self::PROJECT_STATUS_ASSET_GENERATING, self::PROJECT_STATUS_VIDEO_GENERATING])->update([
            'status' => 'planned',
            'update_time' => time(),
        ]);
    }

    private static function formatPlanVersion(array $row, bool $detail = false): array
    {
        $data = [
            'id' => (int)$row['id'],
            'version_no' => (int)$row['version_no'],
            'version_type' => (string)$row['version_type'],
            'title' => (string)$row['title'],
            'task_id' => (string)$row['task_id'],
            'agent_run_id' => (string)$row['agent_run_id'],
            'parent_version_id' => (int)$row['parent_version_id'],
            'is_current' => (int)$row['is_current'] === 1,
            'status' => self::publicTaskStatus((string)$row['status']),
            'created_at' => self::timeText($row['create_time'] ?? 0),
        ];
        if ($detail) {
            $data['story_bible'] = self::jsonDecode((string)($row['story_bible_json'] ?? ''));
            $data['continuity'] = self::jsonDecode((string)($row['continuity_json'] ?? ''));
            $data['plan'] = self::enhancePlanResult(self::jsonDecode((string)($row['plan_json'] ?? '')));
            $data['plan'] = self::applyStoryboardSelectionState(
                (int)$row['tenant_id'],
                (int)$row['user_id'],
                (int)$row['project_id'],
                (string)$row['task_id'],
                $data['plan']
            );
            $data['storyboard'] = (array)($data['plan']['storyboard'] ?? self::jsonDecode((string)($row['storyboard_json'] ?? '')));
        }
        return $data;
    }

    private static function assetReferenceMeta(array $row, array $meta): array
    {
        $assetType = (string)($row['asset_type'] ?? '');
        if (!in_array($assetType, ['subject_image', 'three_view', 'scene_image'], true)) {
            return $meta;
        }
        $hasSubjectRef = !empty($meta['subject_id']) || !empty($meta['subject_ref_id']) || !empty($meta['item_id']);
        $hasSceneRef = !empty($meta['scene_id']) || !empty($meta['scene_ref_id']) || !empty($meta['location_id']) || !empty($meta['item_id']);
        if (($assetType === 'scene_image' && $hasSceneRef) || ($assetType !== 'scene_image' && $hasSubjectRef)) {
            return $meta;
        }

        $taskId = (string)($row['task_id'] ?? '');
        if ($taskId === '') {
            return $meta;
        }
        $requestJson = (string)AigcShortDramaGenerationTask::where([
            'tenant_id' => (int)($row['tenant_id'] ?? 0),
            'user_id' => (int)($row['user_id'] ?? 0),
            'project_id' => (int)($row['project_id'] ?? 0),
            'task_id' => $taskId,
            'delete_time' => 0,
        ])->value('request_json');
        if ($requestJson === '') {
            return $meta;
        }

        $request = self::jsonDecode($requestJson);
        $params = is_array($request['params'] ?? null) ? (array)$request['params'] : [];
        $nested = is_array($params['params'] ?? null) ? (array)$params['params'] : [];
        $source = array_merge($nested, $params);
        $keys = $assetType === 'scene_image'
            ? ['scene_id', 'scene_ref_id', 'location_id', 'item_id', 'scene_name', 'item_name']
            : ['subject_id', 'subject_ref_id', 'character_id', 'item_id', 'subject_name', 'item_name'];
        foreach ($keys as $key) {
            $value = trim((string)($source[$key] ?? ''));
            if ($value !== '' && empty($meta[$key])) {
                $meta[$key] = $value;
            }
        }
        if (empty($meta['item_id'])) {
            $meta['item_id'] = (string)($meta[$assetType === 'scene_image' ? 'scene_id' : 'subject_id'] ?? '');
        }
        return $meta;
    }

    private static function formatAsset(array $row): array
    {
        $uri = (string)($row['uri'] ?? '');
        $coverUri = (string)($row['cover_uri'] ?? '');
        $meta = self::assetReferenceMeta($row, self::localizeGenerationTaskPayload(self::jsonDecode((string)($row['meta_json'] ?? ''))));
        return self::sanitizeUtf8Payload([
            'id' => (int)$row['id'],
            'project_id' => (int)$row['project_id'],
            'task_id' => (string)$row['task_id'],
            'shot_id' => (string)($row['shot_id'] ?? ''),
            'asset_type' => (string)$row['asset_type'],
            'title' => (string)$row['title'],
            'uri' => $uri,
            'url' => FileService::getFileUrlByStorage($uri, (string)$row['storage_scope'], (string)$row['storage_engine'], (string)$row['storage_domain']),
            'cover_url' => $coverUri === '' ? '' : FileService::getFileUrlByStorage($coverUri, (string)$row['storage_scope'], (string)$row['storage_engine'], (string)$row['storage_domain']),
            'mime_type' => (string)$row['mime_type'],
            'file_size' => (int)$row['file_size'],
            'width' => (int)$row['width'],
            'height' => (int)$row['height'],
            'duration' => (float)$row['duration'],
            'status' => (string)$row['status'],
            'meta' => $meta,
            'created_at' => self::timeText($row['create_time'] ?? 0),
        ]);
    }

    private static function formatGenerationTask(array $row, bool $detail = false): array
    {
        $data = [
            'id' => (int)$row['id'],
            'project_id' => (int)$row['project_id'],
            'shot_id' => (string)($row['shot_id'] ?? ''),
            'task_id' => (string)$row['task_id'],
            'parent_task_id' => (string)$row['parent_task_id'],
            'source_task_id' => (string)$row['source_task_id'],
            'source_app_code' => (string)$row['source_app_code'],
            'task_type' => (string)$row['task_type'],
            'status' => (string)$row['status'],
            'status_label' => self::taskStatusLabel((string)$row['status']),
            'status_tag' => self::taskStatusTag((string)$row['status']),
            'progress' => (int)$row['progress'],
            'provider' => (string)$row['provider'],
            'provider_task_id' => (string)$row['provider_task_id'],
            'billing_status' => (string)$row['billing_status'],
            'tenant_cost_points' => (float)$row['tenant_cost_points'],
            'user_charge_points' => (float)$row['user_charge_points'],
            'safety_status' => (string)$row['safety_status'],
            'retry_count' => (int)$row['retry_count'],
            'error_code' => (string)$row['error_code'],
            'error_msg' => (string)$row['error_msg'],
            'created_at' => self::timeText($row['create_time'] ?? 0),
            'update_time' => self::timeText($row['update_time'] ?? 0),
            'updated_at' => self::timeText($row['update_time'] ?? 0),
            'finished_at' => self::timeText($row['finished_at'] ?? 0),
            'input_asset_ids' => self::jsonDecode((string)($row['input_asset_ids'] ?? '')),
            'output_asset_ids' => self::jsonDecode((string)($row['output_asset_ids'] ?? '')),
        ];
        if ($detail) {
            $data['model'] = self::jsonDecode((string)($row['model_json'] ?? ''));
            $data['request'] = self::localizeGenerationTaskPayload(self::jsonDecode((string)($row['request_json'] ?? '')));
            $data['result'] = self::localizeGenerationTaskPayload(self::jsonDecode((string)($row['result_json'] ?? '')));
            $data['pricing'] = self::jsonDecode((string)($row['pricing_snapshot'] ?? ''));
            $data['input_assets'] = self::generationTaskInputAssets($row);
            $data['assets'] = self::generationTaskAssets($row);
            $data['output_assets'] = $data['assets'];
        }
        return self::sanitizeUtf8Payload($data);
    }

    private static function formatPublishedWork(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'tenant_id' => (int)$row['tenant_id'],
            'project_id' => (int)$row['project_id'],
            'final_video_asset_id' => (int)$row['final_video_asset_id'],
            'cover_asset_id' => (int)$row['cover_asset_id'],
            'title' => (string)$row['title'],
            'intro' => (string)$row['intro'],
            'script_description' => (string)$row['script_description'],
            'social_link' => (string)$row['social_link'],
            'cover_url' => self::fileUrl((string)$row['cover_uri']),
            'video_url' => self::fileUrl((string)$row['video_uri']),
            'activity_tags' => self::jsonDecode((string)($row['activity_tags_json'] ?? '')),
            'audit_status' => (string)$row['audit_status'],
            'audit_reason' => (string)$row['audit_reason'],
            'status' => (int)$row['status'],
            'submitted_at' => self::timeText($row['submitted_at'] ?? 0),
            'audited_at' => self::timeText($row['audited_at'] ?? 0),
            'created_at' => self::timeText($row['create_time'] ?? 0),
        ];
    }

    private static function storyBibleFromResult(array $result): array
    {
        return [
            'title' => (string)($result['title'] ?? ''),
            'story_outline' => (string)($result['story_outline'] ?? ''),
            'subjects' => (array)($result['subjects'] ?? []),
            'locations' => (array)($result['locations'] ?? []),
            'scenes' => (array)($result['scenes'] ?? $result['locations'] ?? []),
            'art_style' => (array)($result['art_style'] ?? []),
            'generation_settings' => (array)($result['generation_settings'] ?? []),
        ];
    }

    private static function continuityFromResult(array $result): array
    {
        return [
            'subjects' => array_values(array_map(static fn($item) => [
                'id' => (string)($item['id'] ?? ''),
                'name' => (string)($item['name'] ?? ''),
                'description' => (string)($item['description'] ?? ''),
                'visual_prompt' => self::localizeGenerationPromptText((string)($item['visual_prompt'] ?? ''), (string)($item['description'] ?? '')),
            ], (array)($result['subjects'] ?? []))),
            'locations' => array_values(array_map(static fn($item) => [
                'id' => (string)($item['id'] ?? ''),
                'name' => (string)($item['name'] ?? ''),
                'description' => (string)($item['description'] ?? ''),
                'visual_prompt' => self::localizeGenerationPromptText((string)($item['visual_prompt'] ?? ''), (string)($item['description'] ?? '')),
            ], (array)($result['locations'] ?? []))),
            'scenes' => array_values(array_map(static fn($item) => [
                'id' => (string)($item['id'] ?? ''),
                'name' => (string)($item['name'] ?? ''),
                'description' => (string)($item['description'] ?? ''),
                'visual_prompt' => self::localizeGenerationPromptText((string)($item['visual_prompt'] ?? ''), (string)($item['description'] ?? '')),
                'image_prompt' => self::localizeGenerationPromptText((string)($item['image_prompt'] ?? ''), (string)($item['description'] ?? '')),
            ], (array)($result['scenes'] ?? $result['locations'] ?? []))),
            'quality_check' => (array)($result['quality_check'] ?? []),
        ];
    }

    private static function normalizeAssetType(string $type): string
    {
        return in_array($type, [
            'script_document',
            'reference_image',
            'reference_video',
            'subject_image',
            'scene_image',
            'shot_image',
            'shot_video',
            'three_view',
            'bgm_audio',
            'timeline_project',
            'final_video',
            'export_package',
        ], true) ? $type : 'reference_image';
    }

    private static function normalizeGenerationTaskType(string $type): string
    {
        $type = match ($type) {
            'image_edit', 'image_generate' => 'shot_image',
            'video_generate' => 'shot_video',
            default => $type,
        };
        return in_array($type, ['script_plan', 'subject_image', 'scene_image', 'shot_image', 'shot_video', 'three_view', 'bgm_audio', 'export_video', 'export_package'], true)
            ? $type
            : 'shot_image';
    }

    private static function normalizeGenerationMode(array $params): string
    {
        $mode = trim((string)($params['mode'] ?? $params['generation_mode'] ?? ''));
        $type = trim((string)($params['task_type'] ?? $params['type'] ?? ''));
        if ($mode === '' && in_array($type, ['image_edit', 'image_generate', 'video_generate'], true)) {
            $mode = $type;
        }
        return in_array($mode, ['image_edit', 'image_generate', 'video_generate'], true) ? $mode : '';
    }

    private static function generationAssetType(string $taskType): string
    {
        return match ($taskType) {
            'subject_image', 'scene_image', 'shot_image', 'shot_video', 'three_view', 'bgm_audio', 'final_video' => $taskType,
            'export_video' => 'final_video',
            'export_package' => 'export_package',
            default => 'shot_image',
        };
    }

    private static function estimateGenerationBilling(string $taskType, array $shot, array $config, array $params = []): array
    {
        $modelBilling = self::generationModelBilling($taskType, $shot, $config, $params);
        if (!empty($modelBilling)) {
            return $modelBilling;
        }
        $priceKey = match ($taskType) {
            'shot_video' => 'short_drama_shot_video',
            'three_view' => 'short_drama_three_view',
            'bgm_audio' => 'short_drama_bgm_audio',
            'export_video' => 'short_drama_export_video',
            'export_package' => 'short_drama_export_package',
            default => 'short_drama_shot_image',
        };
        $configured = [];
        foreach ((array)($config['price_config'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $groupKey = (string)($item['group_key'] ?? $item['key'] ?? '');
            $id = (string)($item['id'] ?? $item['value'] ?? $item['task_type'] ?? '');
            if ($groupKey === $priceKey || $id === $priceKey || $id === $taskType) {
                $configured = $item;
                break;
            }
        }
        $duration = max(1, (float)($shot['recommended_duration_seconds'] ?? 1));
        $quantity = $taskType === 'shot_video' ? $duration : 1;
        $tenantUnit = (float)($configured['tenant_cost_points'] ?? $configured['tenant_points'] ?? 0);
        $userUnit = (float)($configured['user_charge_points'] ?? $configured['user_points'] ?? 0);
        return [
            'billing_unit' => $taskType === 'shot_video' ? 'seconds' : 'task',
            'quantity' => $quantity,
            'tenant_unit_points' => self::formatUnitPrice($tenantUnit),
            'user_unit_points' => self::formatUnitPrice($userUnit),
            'tenant_cost_points' => self::formatBillingPoints($tenantUnit * $quantity),
            'user_charge_points' => self::formatBillingPoints($userUnit * $quantity),
        ];
    }

    private static function estimateImageGenerationBilling(int $tenantId, string $taskType, array $shot, array $config, array $params): array
    {
        if (self::isImageGenerationTask($taskType)) {
            try {
                if (self::isNanoBananaImageSelection($params)) {
                    return MarketNanoBananaAppRuntimeService::quote($tenantId, $params, max(1, (int)($params['quantity'] ?? 1)));
                }
                return MarketImageModelRuntimeService::quote($tenantId, $params, max(1, (int)($params['quantity'] ?? 1)));
            } catch (\Throwable $e) {
                throw new Exception($e->getMessage());
            }
        }
        return self::estimateGenerationBilling($taskType, $shot, $config, $params);
    }

    private static function isImageGenerationTask(string $taskType): bool
    {
        return in_array($taskType, ['subject_image', 'scene_image', 'three_view', 'shot_image'], true);
    }

    private static function isMarketImageSelection(array $params): bool
    {
        if (self::isNanoBananaImageSelection($params)) {
            return true;
        }
        $nested = is_array($params['params'] ?? null) ? (array)$params['params'] : [];
        foreach (['market_sku_id', 'sku_id', 'image_model_id', 'model_id', 'channel', 'channel_code'] as $key) {
            $value = (string)($params[$key] ?? $nested[$key] ?? '');
            if (str_starts_with($value, 'market_sku:') || str_starts_with($value, 'market_image_model:') || ($key === 'market_sku_id' && (int)$value > 0)) {
                return true;
            }
        }
        return false;
    }

    private static function isNanoBananaImageSelection(array $params): bool
    {
        return MarketNanoBananaAppRuntimeService::isSelection($params);
    }

    private static function generationModelBilling(string $taskType, array $shot, array $config, array $params): array
    {
        $groupKey = match ($taskType) {
            'shot_video' => 'video',
            'shot_image', 'subject_image', 'scene_image', 'three_view' => 'image',
            default => '',
        };
        if ($groupKey === '') {
            return [];
        }
        $modelCode = self::generationModelCode($params, $groupKey);
        $group = self::modelGroupByKey((array)($config['model_groups'] ?? []), $groupKey);
        $options = array_values(array_filter((array)($group['options'] ?? []), 'is_array'));
        if (empty($options)) {
            return [];
        }
        $option = null;
        foreach ($options as $item) {
            $codes = [
                (string)($item['id'] ?? ''),
                (string)($item['value'] ?? ''),
                (string)($item['channel'] ?? ''),
                (string)($item['channel_code'] ?? ''),
                (string)($item['model_code'] ?? ''),
            ];
            if ($modelCode !== '' && in_array($modelCode, $codes, true)) {
                $option = $item;
                break;
            }
        }
        if ($option === null && $modelCode === '') {
            $option = $options[0];
        }
        if ($option === null) {
            return [];
        }
        $billingUnit = (string)($option['billing_unit'] ?? ($groupKey === 'video' ? 'video_spec' : 'image_spec'));
        $tenantUnit = (float)($option['platform_unit_cost'] ?? 0);
        $userUnit = (float)($option['tenant_unit_price'] ?? $option['user_charge_points'] ?? 0);
        if ($tenantUnit <= 0 && $userUnit <= 0) {
            return [];
        }
        $quantity = max(1, (int)($params['quantity'] ?? 1));
        if ($taskType === 'shot_video' && str_contains(strtolower($billingUnit), 'second')) {
            $quantity = max(1, (float)($params['duration'] ?? $params['duration_seconds'] ?? $shot['recommended_duration_seconds'] ?? $shot['duration'] ?? 1));
        }
        return [
            'billing_unit' => $billingUnit,
            'quantity' => $quantity,
            'tenant_unit_points' => self::formatUnitPrice($tenantUnit),
            'user_unit_points' => self::formatUnitPrice($userUnit),
            'tenant_cost_points' => self::formatBillingPoints($tenantUnit * $quantity),
            'user_charge_points' => self::formatBillingPoints($userUnit * $quantity),
            'model_id' => (string)($option['id'] ?? $option['value'] ?? $option['channel_code'] ?? ''),
            'model_name' => (string)($option['name'] ?? ''),
            'price_source' => 'model_config',
        ];
    }

    private static function generationModelCode(array $params, string $groupKey): string
    {
        $nested = is_array($params['params'] ?? null) ? (array)$params['params'] : [];
        $keys = $groupKey === 'video'
            ? ['video_model_id', 'model_id', 'channel', 'channel_code']
            : ['image_model_id', 'model_id', 'channel', 'channel_code'];
        foreach ($keys as $key) {
            $value = trim((string)($params[$key] ?? $nested[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    }

    private static function normalizeShortDramaImageChannelParams(int $tenantId, array $params): array
    {
        $nested = is_array($params['params'] ?? null) ? (array)$params['params'] : [];
        $requested = '';
        foreach (['image_model_id', 'model_id', 'channel', 'channel_code'] as $key) {
            $value = trim((string)($params[$key] ?? $nested[$key] ?? ''));
            if ($value !== '') {
                $requested = $value;
                break;
            }
        }
        if ($requested === '') {
            $options = array_merge(MarketImageModelRuntimeService::options($tenantId), MarketNanoBananaAppRuntimeService::options($tenantId));
            $requested = (string)($options[0]['id'] ?? '');
            if ($requested === '') {
                throw new Exception('暂无租户已上架的图片模型规格');
            }
        }

        if (str_starts_with($requested, 'market_sku:') || str_starts_with($requested, 'market_image_model:') || str_starts_with($requested, 'market_nano_banana:')) {
            foreach (['model_id', 'image_model_id', 'channel', 'channel_code'] as $key) {
                $params[$key] = $requested;
            }
            if (is_array($params['params'] ?? null)) {
                foreach (['model_id', 'image_model_id', 'channel', 'channel_code'] as $key) {
                    $params['params'][$key] = $requested;
                }
            }
            return $params;
        }
        throw new Exception('短剧生图仅支持租户已上架的算力市场图片模型或 nano-banana 应用 API，请重新选择');
    }

    private static function normalizeShortDramaVideoChannelParams(int $tenantId, array $params): array
    {
        $nested = is_array($params['params'] ?? null) ? (array)$params['params'] : [];
        $requested = '';
        foreach (['video_model_id', 'model_id', 'channel', 'channel_code'] as $key) {
            $value = trim((string)($params[$key] ?? $nested[$key] ?? ''));
            if ($value !== '') {
                $requested = $value;
                break;
            }
        }
        if ($requested === '') {
            return $params;
        }

        try {
            $config = AigcVideoChannelService::userConfig($tenantId);
            $channels = (array)($config['channels'] ?? []);
        } catch (\Throwable) {
            throw new Exception('暂无可用视频模型，请先在后台配置');
        }

        $matched = [];
        foreach ($channels as $channel) {
            if (!is_array($channel)) {
                continue;
            }
            $codes = array_filter([
                (string)($channel['code'] ?? ''),
                (string)($channel['name'] ?? ''),
                (string)($channel['model'] ?? ''),
            ]);
            if (in_array($requested, $codes, true)) {
                $matched = $channel;
                break;
            }
        }
        if (empty($matched)) {
            throw new Exception('当前视频模型不可用，请重新选择');
        }

        $code = (string)($matched['code'] ?? '');
        if ($code === '') {
            throw new Exception('当前视频模型不可用，请重新选择');
        }
        foreach (['model_id', 'video_model_id', 'channel', 'channel_code'] as $key) {
            $params[$key] = $code;
        }
        if (is_array($params['params'] ?? null)) {
            foreach (['model_id', 'video_model_id', 'channel', 'channel_code'] as $key) {
                $params['params'][$key] = $code;
            }
        }
        return self::normalizeShortDramaChannelQuality($params, self::channelPriceSummary($matched));
    }

    private static function normalizeShortDramaChannelQuality(array $params, array $summary): array
    {
        $nested = is_array($params['params'] ?? null) ? (array)$params['params'] : [];
        $requested = trim((string)($params['quality'] ?? $params['resolution'] ?? $nested['quality'] ?? $nested['resolution'] ?? ''));
        $options = array_values(array_filter(array_map('strval', (array)($summary['quality_options'] ?? []))));
        if (empty($options)) {
            return $params;
        }
        $quality = in_array($requested, $options, true)
            ? $requested
            : (string)($summary['default_quality'] ?? $options[0]);
        if ($quality === '' || !in_array($quality, $options, true)) {
            $quality = (string)$options[0];
        }
        foreach (['quality', 'resolution'] as $key) {
            $params[$key] = $quality;
        }
        if (is_array($params['params'] ?? null)) {
            foreach (['quality', 'resolution'] as $key) {
                $params['params'][$key] = $quality;
            }
        }
        return $params;
    }

    private static function firstReferenceImageFromParams(array $params): string
    {
        $nested = is_array($params['params'] ?? null) ? (array)$params['params'] : [];
        foreach (['reference_image', 'image', 'raw_image'] as $key) {
            $value = trim((string)($params[$key] ?? $nested[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }
        foreach ([(array)($params['reference_images'] ?? []), (array)($nested['reference_images'] ?? [])] as $images) {
            foreach ($images as $image) {
                $value = trim((string)$image);
                if ($value !== '') {
                    return $value;
                }
            }
        }
        return '';
    }

    private static function appendThreeViewReferenceAssetIds(int $tenantId, int $userId, int $projectId, array $params, string $sourceImage): array
    {
        $nested = is_array($params['params'] ?? null) ? (array)$params['params'] : [];
        $ids = array_values(array_unique(array_filter(array_merge(
            self::normalizeIdList($params['input_asset_ids'] ?? []),
            self::normalizeIdList($nested['input_asset_ids'] ?? []),
            self::normalizeIdList($params['reference_asset_ids'] ?? []),
            self::normalizeIdList($nested['reference_asset_ids'] ?? [])
        ))));
        if (empty($ids)) {
            $matchedId = self::matchSubjectImageAssetId($tenantId, $userId, $projectId, $params, $sourceImage);
            if ($matchedId > 0) {
                $ids[] = $matchedId;
            }
        }
        if (empty($ids)) {
            return $params;
        }
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $params['input_asset_ids'] = $ids;
        $params['reference_asset_ids'] = $ids;
        if (!is_array($params['params'] ?? null)) {
            $params['params'] = [];
        }
        $params['params']['input_asset_ids'] = $ids;
        $params['params']['reference_asset_ids'] = $ids;
        return $params;
    }

    private static function matchSubjectImageAssetId(int $tenantId, int $userId, int $projectId, array $params, string $sourceImage): int
    {
        $nested = is_array($params['params'] ?? null) ? (array)$params['params'] : [];
        $subjectId = trim((string)($params['subject_id'] ?? $params['item_id'] ?? $nested['subject_id'] ?? $nested['item_id'] ?? ''));
        $sourceUri = FileService::setFileUrl($sourceImage);
        $rows = AigcShortDramaAsset::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'project_id' => $projectId,
            'asset_type' => 'subject_image',
            'status' => 'ready',
            'delete_time' => 0,
        ])->order('id', 'desc')->limit(80)->select()->toArray();
        foreach ($rows as $row) {
            $meta = self::jsonDecode((string)($row['meta_json'] ?? ''));
            $assetSubjectId = trim((string)($meta['subject_id'] ?? $meta['item_id'] ?? ''));
            if ($subjectId !== '' && $assetSubjectId !== '' && $assetSubjectId !== $subjectId) {
                continue;
            }
            $uri = (string)($row['uri'] ?? '');
            $url = FileService::getFileUrlByStorage(
                $uri,
                (string)($row['storage_scope'] ?? 'tenant'),
                (string)($row['storage_engine'] ?? 'local'),
                (string)($row['storage_domain'] ?? '')
            );
            if (in_array($sourceImage, [$uri, $url], true) || ($sourceUri !== '' && $sourceUri === $uri)) {
                return (int)$row['id'];
            }
        }
        return 0;
    }

    private static function modelGroupByKey(array $groups, string $groupKey): array
    {
        foreach ($groups as $group) {
            if (!is_array($group)) {
                continue;
            }
            $keys = [
                strtolower((string)($group['key'] ?? '')),
                strtolower((string)($group['type'] ?? '')),
                strtolower((string)($group['app_code'] ?? '')),
            ];
            if (in_array($groupKey, $keys, true)) {
                return $group;
            }
        }
        return [];
    }

    private static function publicConfig(int $tenantId): array
    {
        $default = [
            'status' => 1,
            'background' => [
                'type' => 'video',
                'items' => [
                    ['url' => 'https://aigclikeadmin.oss-cn-shenzhen.aliyuncs.com/uploads/video/20260702/20260702030949da9924540.mp4', 'poster_url' => ''],
                    ['url' => 'https://aigclikeadmin.oss-cn-shenzhen.aliyuncs.com/uploads/video/20260601/20260601112012e02b49571.mov', 'poster_url' => ''],
                ],
            ],
            'ratios' => [
                ['label' => '9:16', 'width' => 9, 'height' => 16],
                ['label' => '16:9', 'width' => 16, 'height' => 9],
                ['label' => '3:4', 'width' => 3, 'height' => 4],
                ['label' => '4:3', 'width' => 4, 'height' => 3],
                ['label' => '21:9', 'width' => 21, 'height' => 9],
                ['label' => '1:1', 'width' => 1, 'height' => 1],
            ],
            'prompt_max_length' => 20000,
            'models' => [
                [
                    'id' => 'script-planner-default',
                    'name' => '剧本策划模型',
                    'description' => '用于故事扩写、剧本策划与分镜文本生成',
                    'image' => self::fileUrl(self::DEFAULT_IMAGE),
                    'enabled' => true,
                    'sort' => 10,
                ],
            ],
            'price_config' => [],
            'storyboard_rules' => self::defaultStoryboardRules(),
            'export_watermark' => self::defaultExportWatermarkConfig(),
        ];
        $default['models'][0]['name'] = '剧本策划模型';
        $default['models'][0]['description'] = '用于故事扩写、剧本策划与分镜文本生成';
        $row = AigcShortDramaConfig::whereIn('tenant_id', [$tenantId, 0])
            ->orderRaw('tenant_id = ' . (int)$tenantId . ' desc')
            ->findOrEmpty();
        if ($row->isEmpty()) {
            $default['background'] = self::formatBackgroundConfig($default['background']);
            $default['price_config'] = [];
            $default['export_watermark'] = self::normalizeExportWatermarkConfig((array)$default['export_watermark']);
            $default['model_groups'] = self::dependencyModelGroups($tenantId);
            $scriptModel = self::configuredScriptPlanModel($tenantId, $default, false);
            $default['script_plan_model_id'] = (string)($scriptModel['id'] ?? '');
            $default['script_plan_model_selection'] = $scriptModel === [] ? [] : self::marketModelSnapshot($scriptModel);
            $default['vision_model_id'] = '';
            $default['vision_model_selection'] = [];
            return $default;
        }
        $json = self::jsonDecode((string)$row['config_json']);
        $config = array_merge($default, $json);
        unset($config['script_plan_points']);
        $config['status'] = (int)$row['status'];
        $config['background'] = self::formatBackgroundConfig((array)($config['background'] ?? $default['background']));
        $config['models'] = array_values(array_map(static function (array $model): array {
            if ((string)($model['id'] ?? '') === 'script-planner-default') {
                $model['name'] = '剧本策划模型';
                $model['description'] = '用于故事扩写、剧本策划与分镜文本生成';
            }
            $model['image'] = self::fileUrl((string)($model['image'] ?? self::DEFAULT_IMAGE));
            $model['enabled'] = (bool)($model['enabled'] ?? true);
            return $model;
        }, (array)$config['models']));
        $config['price_config'] = [];
        $config['model_groups'] = self::dependencyModelGroups($tenantId);
        $scriptModel = self::configuredScriptPlanModel($tenantId, $config, false);
        $config['script_plan_model_id'] = (string)($scriptModel['id'] ?? '');
        $config['script_plan_model_selection'] = $scriptModel === [] ? [] : self::marketModelSnapshot($scriptModel);
        $visionModel = self::configuredVisionModel($tenantId, $config, false);
        $config['vision_model_id'] = (string)($visionModel['id'] ?? '');
        $config['vision_model_selection'] = $visionModel === [] ? [] : self::marketModelSnapshot($visionModel);
        $config['prompt_max_length'] = max(0, min(200000, (int)($config['prompt_max_length'] ?? 20000)));
        $config['storyboard_rules'] = self::normalizeStoryboardRules((array)($config['storyboard_rules'] ?? []));
        $config['export_watermark'] = self::normalizeExportWatermarkConfig((array)($config['export_watermark'] ?? []));
        return $config;
    }

    private static function userScriptModelGroups(array $groups, array $fallbackModels = []): array
    {
        $scriptGroup = [];
        foreach ($groups as $group) {
            if (($group['key'] ?? '') === 'script_plan') {
                $scriptGroup = $group;
                break;
            }
        }
        if (empty($scriptGroup)) {
            $scriptGroup = [
                'key' => 'script_plan',
                'label' => '剧本策划模型',
                'type' => 'llm',
                'description' => '用于故事扩写、剧本策划与分镜文本生成',
                'options' => $fallbackModels,
                'default' => (string)($fallbackModels[0]['id'] ?? ''),
            ];
        }
        $scriptGroup['label'] = '剧本策划模型';
        $scriptGroup['description'] = '用于故事扩写、剧本策划与分镜文本生成';
        $scriptGroup['label'] = (string)($scriptGroup['label'] ?? '剧本策划模型');
        $scriptGroup['description'] = (string)($scriptGroup['description'] ?? '用于故事扩写、剧本策划与分镜文本生成');
        $scriptGroup['options'] = array_values(array_filter(
            array_map([self::class, 'formatUserModelOption'], (array)($scriptGroup['options'] ?? [])),
            static fn(array $item): bool => $item['id'] !== ''
        ));
        $scriptGroup['default'] = (string)($scriptGroup['default'] ?? ($scriptGroup['options'][0]['id'] ?? ''));
        unset($scriptGroup['app_code'], $scriptGroup['provider'], $scriptGroup['provider_model'], $scriptGroup['channel_code']);
        return [$scriptGroup];
    }

    private static function userCreationModelGroups(array $groups, array $fallbackModels = []): array
    {
        $visibleGroups = [];
        foreach ($groups as $group) {
            $key = (string)($group['key'] ?? '');
            if ($key === '' || in_array($key, ['script_plan', 'vision_describe'], true)) {
                continue;
            }
            $group['options'] = array_values(array_filter((array)($group['options'] ?? []), static function ($option): bool {
                return is_array($option) && (string)($option['id'] ?? $option['value'] ?? $option['channel_code'] ?? '') !== '';
            }));
            if (!empty($group['options'])) {
                $visibleGroups[] = $group;
            }
        }
        return $visibleGroups;
    }

    private static function formatUserModelOption(array $model): array
    {
        $id = (string)($model['id'] ?? $model['value'] ?? $model['model_code'] ?? '');
        return [
            'id' => $id,
            'value' => $id,
            'name' => (string)($model['name'] ?? $id),
            'description' => (string)($model['description'] ?? ''),
            'image' => self::fileUrl((string)($model['image'] ?? self::DEFAULT_IMAGE)),
            'enabled' => (bool)($model['enabled'] ?? true),
            'sort' => (int)($model['sort'] ?? 0),
        ];
    }

    private static function dependencyModelGroups(int $tenantId): array
    {
        $textGroups = MarketTextModelRuntimeService::modelGroups($tenantId);
        $imageGroup = MarketImageModelRuntimeService::modelGroup($tenantId);
        $imageGroup['options'] = array_merge((array)($imageGroup['options'] ?? []), MarketNanoBananaAppRuntimeService::options($tenantId));
        $imageGroup['default'] = (string)($imageGroup['options'][0]['id'] ?? '');
        return [
            $textGroups[0] ?? self::llmModelGroup($tenantId),
            $textGroups[1] ?? [],
            $imageGroup,
            self::marketVideoModelGroup($tenantId),
        ];
    }

    /** @param array<int, array<string, mixed>> $options */
    private static function marketDependencyItem(string $name, string $requiredFor, string $resourceLabel, array $options, string $resourceType = 'model_api'): array
    {
        $count = count($options);
        return [
            'resource_type' => $resourceType,
            'resource_type_label' => $resourceLabel,
            'name' => $name,
            'required_for' => $requiredFor,
            'available' => $count > 0,
            'channel_ready' => $count > 0,
            'ready' => $count > 0,
            'channel_count' => $count,
            'message' => $count > 0 ? '算力市场已上架 ' . $count . ' 个可用资源' : '暂无租户可用的算力市场资源',
        ];
    }

    /** @return array<string, mixed> */
    private static function configuredScriptPlanModel(int $tenantId, array $config = [], bool $strict = true): array
    {
        if ($config === []) {
            $row = AigcShortDramaConfig::whereIn('tenant_id', [$tenantId, 0])
                ->orderRaw('tenant_id = ' . (int)$tenantId . ' desc')
                ->findOrEmpty();
            $config = $row->isEmpty() ? [] : self::jsonDecode((string)$row['config_json']);
        }
        $groups = (array)($config['model_groups'] ?? []);
        if (empty($groups)) {
            $groups = self::dependencyModelGroups($tenantId);
        }
        $scriptGroup = self::modelGroupByKey($groups, 'script_plan');
        $options = array_values((array)($scriptGroup['options'] ?? []));
        if (empty($options)) {
            if ($strict) {
                throw new Exception('暂无可用的剧本策划模型，请在算力市场上架 Qwen3.6-Plus');
            }
            return [];
        }
        $selection = $config['script_plan_model_id'] ?? $config['script_plan_model_selection'] ?? '';
        $selected = self::matchModelOption($options, $selection);
        if ($selected !== []) {
            return $selected;
        }
        if ($selection !== '' && $strict) {
            throw new Exception('已配置的剧本固定模型已下架或不可用，请在短剧基础配置中重新选择 Qwen3.6-Plus');
        }
        foreach ($options as $option) {
            if (self::isFixedScriptPlanModel($option)) {
                return $option;
            }
        }
        if ($strict) {
            throw new Exception('暂无可用的 Qwen3.6-Plus 剧本策划模型，请在算力市场上架后重试');
        }
        return [];
    }

    private static function isFixedScriptPlanModel(array $model): bool
    {
        foreach (['id', 'value', 'name', 'model_code', 'provider_model', 'channel_code'] as $key) {
            $value = strtolower((string)($model[$key] ?? ''));
            $normalized = preg_replace('/[^a-z0-9]+/', '', $value) ?: '';
            if (in_array($normalized, self::SCRIPT_PLAN_FIXED_MODEL_CODES, true)) {
                return true;
            }
        }
        return false;
    }

    /** @return array<string, mixed> */
    private static function configuredVisionModel(int $tenantId, array $config = [], bool $strict = true): array
    {
        if ($config === []) {
            $row = AigcShortDramaConfig::whereIn('tenant_id', [$tenantId, 0])
                ->orderRaw('tenant_id = ' . (int)$tenantId . ' desc')
                ->findOrEmpty();
            $config = $row->isEmpty() ? [] : self::jsonDecode((string)$row['config_json']);
        }
        $selection = $config['vision_model_id'] ?? $config['vision_model_selection'] ?? '';
        $selectedId = is_array($selection)
            ? (string)($selection['id'] ?? $selection['product_id'] ?? $selection['model_code'] ?? '')
            : trim((string)$selection);
        if ($selectedId === '') {
            return [];
        }
        try {
            return MarketTextModelRuntimeService::resolveModel($tenantId, $selectedId, true);
        } catch (\Throwable $e) {
            if ($strict) {
                throw new Exception('已配置的视觉文本模型已下架或不可用，请在短剧基础配置中重新选择');
            }
            return [];
        }
    }

    /** @return array<string, mixed> */
    private static function marketModelSnapshot(array $model): array
    {
        return [
            'id' => (string)($model['id'] ?? ''),
            'product_id' => (int)($model['product_id'] ?? 0),
            'name' => (string)($model['name'] ?? ''),
            'model_code' => (string)($model['model_code'] ?? ''),
            'channel_code' => (string)($model['channel_code'] ?? ''),
            'protocol' => (string)($model['protocol'] ?? ''),
            'supports_vision' => !empty($model['supports_vision']),
        ];
    }

    private static function marketVideoModelGroup(int $tenantId): array
    {
        $models = MarketVideoModelRuntimeService::options($tenantId);
        $apps = MarketVideoAppRuntimeService::options($tenantId);
        $options = array_merge($models, $apps);
        return [
            'key' => 'video',
            'label' => '视频模型',
            'app_code' => 'power_market_video',
            'type' => 'video',
            'description' => '通过算力市场视频模型和应用 API 生成短剧分镜视频',
            'options' => $options,
            'default' => (string)($options[0]['id'] ?? ''),
        ];
    }

    private static function estimateMarketVideoGenerationBilling(int $tenantId, array $params): array
    {
        $selection = self::marketVideoSelection($params);
        return self::marketVideoRuntime($selection)::quote($tenantId, $selection + $params);
    }

    private static function marketVideoSelection(array $params): array
    {
        $nested = is_array($params['params'] ?? null) ? (array)$params['params'] : [];
        $selection = array_merge($nested, $params);
        if (empty($selection['model_id']) && !empty($selection['video_model_id'])) $selection['model_id'] = $selection['video_model_id'];
        if (empty($selection['resolution']) && !empty($selection['quality'])) $selection['resolution'] = $selection['quality'];
        $value = (string)($selection['model_id'] ?? $selection['channel'] ?? '');
        if (!str_starts_with($value, 'market_video_model:') && !str_starts_with($value, 'market_video_app:') && empty($selection['market_sku_id']) && empty($selection['market_product_id'])) {
            throw new Exception('请选择算力市场视频模型');
        }
        return $selection;
    }

    private static function marketVideoRuntime(array $selection): string
    {
        $value = implode('|', array_map('strval', [$selection['resource_type'] ?? '', $selection['model_id'] ?? '', $selection['channel'] ?? '']));
        return str_contains($value, 'app_api') || str_contains($value, 'market_video_app:') ? MarketVideoAppRuntimeService::class : MarketVideoModelRuntimeService::class;
    }

    /** Prepares request details without consulting the legacy video channel/spec tables. */
    private static function prepareMarketShortDramaVideoParams(int $tenantId, array $params, array $shot): array
    {
        $selection = self::marketVideoSelection($params);
        $requestedDuration = (int)($params['duration'] ?? $shot['recommended_duration_seconds'] ?? 5);
        $runtime = self::marketVideoRuntime($selection);
        $params['duration'] = max(1, min(60, $runtime::effectiveDuration($tenantId, $selection, $requestedDuration)));
        $params['model_id'] = (string)($selection['model_id'] ?? $selection['channel'] ?? '');
        $params['video_model_id'] = $params['model_id'];
        $params['resolution'] = (string)($selection['resolution'] ?? $selection['quality'] ?? '');
        $params['quality'] = $params['resolution'];
        return $params;
    }

    private static function marketShortDramaVideoParams(int $tenantId, int $userId, int $projectId, array $shot, array $params): array
    {
        $params = self::prepareMarketShortDramaVideoParams($tenantId, $params, $shot);
        $plan = self::currentProjectPlanRaw($tenantId, $userId, $projectId);
        $projectRatio = (string)AigcShortDramaProject::where(['tenant_id' => $tenantId, 'user_id' => $userId, 'id' => $projectId, 'delete_time' => 0])->value('ratio');
        $ratio = self::normalizeGenerationRatio($projectRatio) ?: self::requestGenerationRatio($params) ?: '9:16';
        $references = self::generationInputReferenceAssets($tenantId, $userId, $projectId, $params, $shot);
        $prompt = self::normalizeFinalProviderPrompt(self::buildShotVideoPrompt($shot, array_merge($params, ['duration' => (int)$params['duration'], 'reference_assets' => (array)$references['reference_assets'], 'has_first_frame_image' => !empty($references['first_frame_image']), 'has_last_frame_image' => !empty($references['last_frame_image'])]), $plan));
        return [
            'prompt' => $prompt,
            'negative_prompt' => self::shotVideoNegativePrompt($shot, self::isNoSubjectShot($shot)),
            'model_id' => (string)$params['model_id'], 'video_model_id' => (string)$params['model_id'],
            'market_product_id' => (int)($params['market_product_id'] ?? 0), 'market_sku_id' => (int)($params['market_sku_id'] ?? 0),
            'resolution' => (string)$params['resolution'], 'quality' => (string)$params['resolution'], 'duration' => (int)$params['duration'],
            'ratio' => $ratio, 'quantity' => 1, 'reference_assets' => (array)$references['reference_assets'],
            'reference_images' => (array)$references['reference_images'], 'input_asset_ids' => (array)$references['input_asset_ids'],
        ];
    }

    private static function llmModelGroup(int $tenantId): array
    {
        $models = [];
        try {
            $groups = MarketTextModelRuntimeService::modelGroups($tenantId);
            $models = (array)(($groups[0] ?? [])['options'] ?? []);
        } catch (\Throwable) {
            $models = [];
        }
        return [
            'key' => 'script_plan',
            'label' => '剧本策划模型',
            'app_code' => self::LLM_APP_CODE,
            'type' => 'llm',
            'description' => '用于故事扩写、剧本策划与分镜文本生成',
            'options' => $models,
            'default' => (string)($models[0]['id'] ?? ''),
        ];
    }

    private static function channelModelGroup(int $tenantId, string $appCode, string $key, string $type, string $label): array
    {
        $channels = [];
        try {
            $config = $appCode === self::IMAGE_APP_CODE
                ? AigcImageChannelService::userConfig($tenantId)
                : AigcVideoChannelService::userConfig($tenantId);
            $channels = (array)($config['channels'] ?? []);
        } catch (\Throwable) {
            $channels = [];
        }
        $options = [];
        foreach ($channels as $channel) {
            $code = (string)($channel['code'] ?? '');
            if ($code === '') {
                continue;
            }
            $summary = self::channelPriceSummary((array)$channel);
            $tenantUnitPrice = self::formatPrice(max(
                (float)$summary['platform_unit_cost'],
                (float)($summary['tenant_unit_price'] ?? $summary['platform_unit_cost'])
            ));
            $options[] = [
                'id' => $code,
                'value' => $code,
                'app_code' => $appCode,
                'type' => $type,
                'channel' => $code,
                'channel_code' => $code,
                'name' => (string)($channel['name'] ?? $code),
                'description' => $summary['description'],
                'provider' => (string)($channel['provider'] ?? ''),
                'provider_model' => (string)($channel['model'] ?? ''),
                'billing_unit' => $type === 'video' ? 'video_spec' : 'image_spec',
                'platform_unit_cost' => $summary['platform_unit_cost'],
                'tenant_unit_price' => $tenantUnitPrice,
                'spec_count' => $summary['spec_count'],
                'default_quality' => (string)($summary['default_quality'] ?? ''),
                'quality_options' => (array)($summary['quality_options'] ?? []),
                'resolution_options' => (array)($summary['resolution_options'] ?? []),
                'default_ratio' => (string)($summary['default_ratio'] ?? ''),
                'ratio_options' => (array)($summary['ratio_options'] ?? []),
                'max_reference_images' => max(0, (int)($channel['max_reference_images'] ?? 0)),
                'max_reference_assets' => max(0, (int)($channel['max_reference_assets'] ?? 0)),
                'image' => self::fileUrl(self::DEFAULT_IMAGE),
                'enabled' => true,
                'sort' => (int)($channel['sort'] ?? 0),
            ];
        }
        usort($options, fn(array $a, array $b) => (int)$b['sort'] <=> (int)$a['sort']);
        return [
            'key' => $key,
            'label' => $label,
            'app_code' => $appCode,
            'type' => $type,
            'description' => $type === 'video' ? '通过 AIGC 视频通道生成短剧分镜视频' : '通过 AIGC 生图通道生成主体图、场景图和分镜图',
            'options' => $options,
            'default' => (string)($options[0]['id'] ?? ''),
        ];
    }

    private static function channelPriceSummary(array $channel): array
    {
        $specs = [];
        $resolutionOptions = [];
        $qualityOptions = [];
        foreach ((array)($channel['qualities'] ?? []) as $quality) {
            if (!is_array($quality)) {
                continue;
            }
            $qualityValue = trim((string)($quality['value'] ?? $quality['quality'] ?? ''));
            $qualityLabel = trim((string)($quality['label'] ?? $quality['resolution'] ?? $qualityValue));
            $qualityRatios = [];
            foreach ((array)($quality['ratios'] ?? []) as $ratio) {
                if (!is_array($ratio)) {
                    continue;
                }
                $ratioValue = trim((string)($ratio['value'] ?? $ratio['ratio'] ?? ''));
                if ($ratioValue !== '' && !in_array($ratioValue, $qualityRatios, true)) {
                    $qualityRatios[] = $ratioValue;
                }
                $specs[] = $ratio;
            }
            if ($qualityValue !== '' && !in_array($qualityValue, $qualityOptions, true)) {
                $qualityOptions[] = $qualityValue;
                $resolutionLabel = self::shortDramaResolutionLabel(
                    (string)($quality['resolution'] ?? '') ?: ($qualityLabel !== '' ? $qualityLabel : $qualityValue)
                );
                if ($resolutionLabel !== '') {
                    $resolutionOptions[] = [
                        'value' => $qualityValue,
                        'label' => $resolutionLabel,
                        'ratio_options' => $qualityRatios,
                    ];
                }
            }
        }
        $ratioOptions = [];
        foreach ($specs as $spec) {
            $value = trim((string)($spec['value'] ?? $spec['ratio'] ?? ''));
            if ($value !== '' && !in_array($value, $ratioOptions, true)) {
                $ratioOptions[] = $value;
            }
        }
        $platformPrices = array_values(array_filter(array_map(fn(array $item) => (float)($item['platform_unit_cost'] ?? 0), $specs), fn(float $value) => $value > 0));
        $tenantPrices = array_values(array_filter(array_map(fn(array $item) => (float)($item['tenant_unit_price'] ?? 0), $specs), fn(float $value) => $value > 0));
        $platform = empty($platformPrices) ? 0 : min($platformPrices);
        $tenant = empty($tenantPrices) ? $platform : min($tenantPrices);
        $count = count($specs);
        return [
            'platform_unit_cost' => self::formatPrice($platform),
            'tenant_unit_price' => self::formatPrice(max($platform, $tenant)),
            'spec_count' => $count,
            'default_quality' => (string)($resolutionOptions[0]['value'] ?? $qualityOptions[0] ?? $specs[0]['quality'] ?? ''),
            'quality_options' => $qualityOptions,
            'resolution_options' => $resolutionOptions,
            'default_ratio' => (string)($specs[0]['value'] ?? $specs[0]['ratio'] ?? ''),
            'ratio_options' => $ratioOptions,
            'description' => $count > 0
                ? '可用规格 ' . $count . ' 个，成本 ' . self::formatPrice($platform) . ' 点起'
                : '暂无可用规格',
        ];
    }

    private static function llmPriceDescription(array $model): string
    {
        $input = (string)($model['platform_input_unit_cost'] ?? $model['platform_unit_cost'] ?? '0');
        $output = (string)($model['platform_output_unit_cost'] ?? $model['platform_unit_cost'] ?? '0');
        return '成本：输入 ' . $input . ' 点/百万Token，输出 ' . $output . ' 点/百万Token';
    }

    private static function normalizePriceConfig(array $payload): array
    {
        $items = [];
        foreach ($payload as $item) {
            if (!is_array($item)) {
                continue;
            }
            $groupKey = trim((string)($item['group_key'] ?? $item['key'] ?? ''));
            $id = trim((string)($item['id'] ?? $item['value'] ?? $item['model_code'] ?? $item['channel_code'] ?? ''));
            if ($groupKey === '' || $id === '') {
                continue;
            }
            $row = [
                'group_key' => $groupKey,
                'id' => $id,
                'type' => trim((string)($item['type'] ?? '')),
                'app_code' => trim((string)($item['app_code'] ?? '')),
                'name' => trim((string)($item['name'] ?? '')),
            ];
            if ($groupKey === 'script_plan') {
                $row['tenant_input_unit_price'] = self::formatUnitPrice(max(0, (float)($item['tenant_input_unit_price'] ?? 0)));
                $row['tenant_output_unit_price'] = self::formatUnitPrice(max(0, (float)($item['tenant_output_unit_price'] ?? 0)));
            } else {
                $row['tenant_unit_price'] = self::formatPrice(max(0, (float)($item['tenant_unit_price'] ?? 0)));
            }
            $items[$groupKey . '|' . $id] = $row;
        }
        return array_values($items);
    }

    private static function priceConfigMap(array $priceConfig): array
    {
        $map = [];
        foreach ($priceConfig as $item) {
            if (!is_array($item)) {
                continue;
            }
            $groupKey = (string)($item['group_key'] ?? $item['key'] ?? '');
            $id = (string)($item['id'] ?? $item['value'] ?? $item['model_code'] ?? $item['channel_code'] ?? '');
            if ($groupKey !== '' && $id !== '') {
                $map[$groupKey . '|' . $id] = $item;
            }
        }
        return $map;
    }

    private static function floorPriceConfig(array $priceConfig, array $groups): array
    {
        $costMap = [];
        foreach ($groups as $group) {
            if (!is_array($group)) {
                continue;
            }
            $groupKey = (string)($group['key'] ?? '');
            if ($groupKey === '') {
                continue;
            }
            foreach ((array)($group['options'] ?? []) as $option) {
                if (!is_array($option)) {
                    continue;
                }
                $id = (string)($option['id'] ?? $option['value'] ?? $option['model_code'] ?? $option['channel_code'] ?? '');
                if ($id === '') {
                    continue;
                }
                $costMap[$groupKey . '|' . $id] = [
                    'platform_unit_cost' => (float)($option['platform_unit_cost'] ?? 0),
                    'platform_input_unit_cost' => (float)($option['platform_input_unit_cost'] ?? $option['platform_unit_cost'] ?? 0),
                    'platform_output_unit_cost' => (float)($option['platform_output_unit_cost'] ?? $option['platform_unit_cost'] ?? 0),
                ];
            }
        }

        foreach ($priceConfig as &$item) {
            if (!is_array($item)) {
                continue;
            }
            $key = (string)($item['group_key'] ?? '') . '|' . (string)($item['id'] ?? '');
            $cost = $costMap[$key] ?? null;
            if ($cost === null) {
                continue;
            }
            if (($item['group_key'] ?? '') === 'script_plan') {
                $item['tenant_input_unit_price'] = self::formatUnitPrice(max(
                    (float)$cost['platform_input_unit_cost'],
                    (float)($item['tenant_input_unit_price'] ?? 0)
                ));
                $item['tenant_output_unit_price'] = self::formatUnitPrice(max(
                    (float)$cost['platform_output_unit_cost'],
                    (float)($item['tenant_output_unit_price'] ?? 0)
                ));
                continue;
            }
            $item['tenant_unit_price'] = self::formatPrice(max(
                (float)$cost['platform_unit_cost'],
                (float)($item['tenant_unit_price'] ?? 0)
            ));
        }
        unset($item);

        return $priceConfig;
    }

    private static function resolveSelectedModels(int $tenantId, array $request, array $config = []): array
    {
        $groups = (array)($config['model_groups'] ?? []);
        if (empty($groups)) {
            $groups = self::dependencyModelGroups($tenantId);
        }
        $selected = [];
        $selections = (array)($request['model_selections'] ?? []);
        foreach ($groups as $group) {
            $key = (string)($group['key'] ?? '');
            $options = (array)($group['options'] ?? []);
            if ($key === '' || empty($options)) {
                continue;
            }
            if ($key === 'script_plan') {
                $wanted = $selections[$key] ?? ($request['model_id'] ?? '');
                $selected[$key] = self::matchModelOption($options, $wanted)
                    ?: self::configuredScriptPlanModel($tenantId, $config, false);
                continue;
            }
            $wanted = $selections[$key] ?? '';
            $selected[$key] = self::matchModelOption($options, $wanted) ?: $options[0];
        }
        return $selected;
    }

    private static function matchModelOption(array $options, $wanted): array
    {
        $value = is_array($wanted)
            ? (string)($wanted['id'] ?? $wanted['value'] ?? $wanted['model_code'] ?? $wanted['channel'] ?? $wanted['channel_code'] ?? '')
            : (string)$wanted;
        if ($value === '') {
            return [];
        }
        foreach ($options as $option) {
            if (in_array($value, [
                (string)($option['id'] ?? ''),
                (string)($option['value'] ?? ''),
                (string)($option['model_code'] ?? ''),
                (string)($option['channel'] ?? ''),
                (string)($option['channel_code'] ?? ''),
            ], true)) {
                return $option;
            }
        }
        return [];
    }

    private static function modelSelectionsSnapshot(array $selectedModels): array
    {
        $snapshot = [];
        foreach ($selectedModels as $key => $model) {
            $snapshot[$key] = [
                'app_code' => (string)($model['app_code'] ?? ''),
                'type' => (string)($model['type'] ?? ''),
                'id' => (string)($model['id'] ?? ''),
                'name' => (string)($model['name'] ?? ''),
                'model_code' => (string)($model['model_code'] ?? ''),
                'channel_code' => (string)($model['channel_code'] ?? ''),
                'provider_model' => (string)($model['provider_model'] ?? ''),
                'platform_unit_cost' => (string)($model['platform_unit_cost'] ?? ''),
                'tenant_unit_price' => (string)($model['tenant_unit_price'] ?? ''),
            ];
        }
        return $snapshot;
    }

    private static function estimateScriptPlanBilling(string $prompt, array $result, array $model): array
    {
        if (empty($model)) {
            return [
                'billing_unit' => 'tokens_1m',
                'tenant_cost_points' => '0.00',
                'user_charge_points' => '0.00',
                'prompt_tokens' => 0,
                'completion_tokens' => 0,
                'price' => [],
            ];
        }
        $promptTokens = MarketTextModelRuntimeService::estimateTokens($prompt);
        $completionTokens = MarketTextModelRuntimeService::estimateTokens(self::jsonEncode($result));
        $platformInput = (float)($model['platform_input_unit_cost'] ?? $model['platform_unit_cost'] ?? 0);
        $platformOutput = (float)($model['platform_output_unit_cost'] ?? $model['platform_unit_cost'] ?? 0);
        $tenantInput = (float)($model['tenant_input_unit_price'] ?? $model['tenant_unit_price'] ?? 0);
        $tenantOutput = (float)($model['tenant_output_unit_price'] ?? $model['tenant_unit_price'] ?? 0);
        $tenantCost = ($promptTokens * $platformInput + $completionTokens * $platformOutput) / 1000000;
        $userCharge = ($promptTokens * $tenantInput + $completionTokens * $tenantOutput) / 1000000;
        return [
            'source_app_code' => self::LLM_APP_CODE,
            'model_code' => (string)($model['model_code'] ?? $model['code'] ?? ''),
            'channel_code' => (string)($model['channel_code'] ?? ''),
            'provider_model' => (string)($model['provider_model'] ?? ''),
            'billing_unit' => 'tokens_1m',
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'tenant_cost_points' => self::formatBillingPoints($tenantCost),
            'user_charge_points' => self::formatBillingPoints($userCharge),
            'price' => [
                'platform_input_unit_cost' => self::formatUnitPrice($platformInput),
                'platform_output_unit_cost' => self::formatUnitPrice($platformOutput),
                'tenant_input_unit_price' => self::formatUnitPrice($tenantInput),
                'tenant_output_unit_price' => self::formatUnitPrice($tenantOutput),
            ],
        ];
    }

    private static function subjectOptions(int $tenantId, int $userId): array
    {
        $rows = AigcShortDramaSubject::where('status', 1)
            ->where('delete_time', 0)
            ->where(function ($query) use ($tenantId, $userId) {
                $query->whereIn('tenant_id', [0, $tenantId])
                    ->where(function ($inner) use ($userId) {
                        $inner->where('source', 'public')->whereOr('user_id', $userId);
                    });
            })
            ->order(['sort' => 'desc', 'id' => 'desc'])
            ->limit(50)
            ->select()
            ->toArray();
        $rows = array_values(array_filter($rows, static function (array $row): bool {
            return (string)($row['source'] ?? '') !== 'public'
                || !in_array((string)($row['name'] ?? ''), self::LEGACY_PUBLIC_SUBJECT_NAMES, true);
        }));
        return array_map(static function (array $row): array {
            return [
                'id' => (string)$row['id'],
                'name' => (string)$row['name'],
                'image' => self::fileUrl((string)$row['image']),
                'source' => (string)$row['source'],
                'category' => (string)($row['category'] ?? 'character'),
                'gender' => (string)($row['gender'] ?? 'unknown'),
                'age_stage' => (string)($row['age_stage'] ?? 'unknown'),
            ];
        }, $rows);
    }

    private static function styleOptions(int $tenantId): array
    {
        $rows = AigcShortDramaStyle::where('status', 1)
            ->where('delete_time', 0)
            ->whereIn('tenant_id', [0, $tenantId])
            ->order(['sort' => 'desc', 'tenant_id' => 'desc', 'id' => 'desc'])
            ->limit(200)
            ->select()
            ->toArray();

        $seenNames = [];
        $uniqueRows = [];
        foreach ($rows as $row) {
            $name = (string)($row['name'] ?? '');
            if ($name === '' || isset($seenNames[$name])) {
                continue;
            }
            $seenNames[$name] = true;
            $uniqueRows[] = $row;
            if (count($uniqueRows) >= 100) {
                break;
            }
        }

        return array_map(static function (array $row): array {
            return [
                'id' => (string)$row['id'],
                'name' => (string)$row['name'],
                'image' => self::fileUrl((string)$row['image']),
                'description' => (string)($row['description'] ?? ''),
                'is_new' => (bool)$row['is_new'],
                'sort' => (int)$row['sort'],
            ];
        }, $rows);
    }

    private static function normalizeCreateRequest(array $params, array $config): array
    {
        $targetDurationSeconds = min(7200, max(0, (int)($params['target_duration_seconds'] ?? $params['target_duration'] ?? 0)));
        if ($targetDurationSeconds <= 0) {
            $targetDurationSeconds = min(7200, max(0, self::durationHintToSeconds(
                self::extractUserTextDurationHint((string)($params['prompt'] ?? ''))
            )));
        }
        return [
            'prompt' => '',
            'ratio' => self::requestGenerationRatio($params),
            'multi_episode' => (bool)($params['multi_episode'] ?? false),
            'episode_count' => min(100, max(1, (int)($params['episode_count'] ?? ((bool)($params['multi_episode'] ?? false) ? 3 : 1)))),
            'target_duration_seconds' => $targetDurationSeconds,
            'model_id' => trim((string)($params['model_id'] ?? 'script-planner-default')),
            'model_selections' => is_array($params['model_selections'] ?? null) ? $params['model_selections'] : [],
            'style_id' => trim((string)($params['style_id'] ?? '')),
            'subject_ids' => array_values(array_filter((array)($params['subject_ids'] ?? []))),
            'subject_mentions' => array_values(array_filter((array)($params['subject_mentions'] ?? []))),
            'input_asset_ids' => array_values(array_filter(array_map('intval', (array)($params['input_asset_ids'] ?? $params['asset_ids'] ?? [])))),
            'source' => trim((string)($params['source'] ?? 'home')),
            'inspiration_id' => (int)($params['inspiration_id'] ?? 0),
        ];
    }

    private static function generateScriptPlanResult(int $tenantId, int $userId, string $prompt, array $request, string $title, array $model, ?callable $onEvent = null): array
    {
        if (empty($model)) {
            throw new Exception('暂无可用的剧本策划模型，请在算力市场上架文本模型');
        }

        $modelCode = (string)($model['model_code'] ?? $model['id'] ?? $model['value'] ?? '');
        try {
            $llmParams = [
                'content' => self::buildScriptPlanPrompt($prompt, $request, $title),
                'system_prompt' => self::scriptPlanSystemPrompt(),
                'model_selection' => $model,
                'model_config' => ['max_tokens' => 8192, 'enable_thinking' => false],
                'source_app_code' => self::APP_CODE,
                'source_type' => 'script_plan',
                'source_id' => $title,
            ];
            $llmResult = $onEvent
                ? MarketTextModelRuntimeService::generate($tenantId, $userId, $llmParams, $onEvent)
                : MarketTextModelRuntimeService::generate($tenantId, $userId, $llmParams);
        } catch (Exception $e) {
            Log::write('AI short drama script planning model failed: ' . $e->getMessage());
            throw new Exception(self::scriptPlanProviderError($e->getMessage()));
        }

        if ($onEvent) {
            $onEvent('stage', [
                'status' => self::STATUS_RUNNING,
                'progress' => 80,
                'current_step' => '整理剧本结构',
            ]);
        }

        $rawContent = trim((string)($llmResult['content'] ?? ''));
        $payload = self::decodeLlmJsonObject($rawContent);
        $result = self::reviewAndRepairPlanResult(self::enhancePlanResult(self::normalizeGeneratedPlanResult($payload, $prompt, $request, $title)));
        if (!empty($request['revision_target']) && is_array($request['revision_target'])) {
            $result = self::reviewAndRepairPlanResult(self::enhancePlanResult(self::protectRevisionTargetResult($result, $request)));
        }
        $repairLlmResult = [];
        if ((int)($result['review_report']['blocking_count'] ?? 0) > 0) {
            if ($onEvent) {
                $onEvent('stage', [
                    'status' => self::STATUS_RUNNING,
                    'progress' => 90,
                    'current_step' => '优化剧本结构',
                ]);
            }
            $repairLlmResult = self::repairScriptPlanResultWithLlm($tenantId, $userId, $prompt, $request, $title, $model, $result, (int)($llmResult['app_task_id'] ?? 0), $onEvent);
            $repairPayload = self::decodeLlmJsonObject(trim((string)($repairLlmResult['content'] ?? '')));
            $result = self::reviewAndRepairPlanResult(
                self::enhancePlanResult(self::normalizeGeneratedPlanResult($repairPayload, $prompt, $request, $title)),
                true,
                true
            );
            if (!empty($request['revision_target']) && is_array($request['revision_target'])) {
                $result = self::reviewAndRepairPlanResult(self::enhancePlanResult(self::protectRevisionTargetResult($result, $request)), true, true);
            }
            if ((int)($result['review_report']['blocking_count'] ?? 0) > 0) {
                Log::write('AI short drama plan repair failed: ' . self::jsonEncode($result['review_report']));
                throw new Exception('剧本计划质检未通过，请调整灵感描述后重');
            }
        }

        return [
            'result' => $result,
            'llm' => $llmResult,
            'repair_llm' => $repairLlmResult,
            'provider' => (string)($model['provider'] ?? ''),
            'raw_content' => $rawContent,
        ];
    }

    private static function repairScriptPlanResultWithLlm(int $tenantId, int $userId, string $prompt, array $request, string $title, array $model, array $plan, int $parentAppTaskId = 0, ?callable $onEvent = null): array
    {
        $reviewReport = (array)($plan['review_report'] ?? []);
        $diagnostics = (array)($reviewReport['storyboard_breaking_diagnostics'] ?? $plan['storyboard_breaking_diagnostics'] ?? []);
        $underShotRange = false;
        foreach ((array)($reviewReport['issues'] ?? []) as $issue) {
            if (is_array($issue) && (string)($issue['code'] ?? '') === 'storyboard.shot_count_under_range') {
                $underShotRange = true;
                break;
            }
        }
        $storyboardRepairRule = $underShotRange
            ? self::joinPromptParts([
                '本次阻断原因是分镜数量低于命中档位最小范围，允许并且必须新增分镜',
                '命中档位' . (string)($diagnostics['matched_rule_label'] ?? ''),
                '目标分镜数量：至少 ' . (int)($diagnostics['target_min_shots'] ?? 0)
                    . (((int)($diagnostics['target_max_shots'] ?? 0) > 0) ? ('，最多 ' . (int)$diagnostics['target_max_shots']) : '，不设上限'),
                '新增分镜只能细拆原有剧情、动作、反应、线索、转场和反转，不得增加新的故事主线、人物关系或结局',
                '返回 storyboard 必须是补足后的完整数组，不是增量补丁；shot_id 从 1 开始重新顺序编号',
            ])
            : '非分镜数量不足的问题，不要重排或扩写分镜，只修复质检指出的字段';
        $repairPrompt = self::joinPromptParts([
            '请只修复下面短剧计划 JSON 的质检问题，返回一个完整 JSON 对象',
            '禁止改写故事主线、人物关系、关键场景和核心剧情；只修复质检问题',
            $storyboardRepairRule,
            '必须保留原有 title、story_outline、script_lines、subjects、locations 的主要内容',
            '质检报告' . self::jsonEncode($reviewReport),
            '原始用户灵感' . $prompt,
            '计划 JSON' . self::jsonEncode(self::stripPlanRuntimeFields($plan)),
        ]);
        try {
            $repairHeartbeat = $onEvent === null ? null : static function (string $event, array $data) use ($onEvent): void {
                // The repair response is a second JSON document. Its text must never be
                // appended to the primary stream, but its heartbeat keeps the root task alive.
                if ($event === 'heartbeat') {
                    $onEvent('heartbeat', $data);
                }
            };
            return MarketTextModelRuntimeService::generate($tenantId, $userId, [
                'content' => $repairPrompt,
                'system_prompt' => '你是短剧计划 JSON 质检修复器。只返回合法 JSON，不要 Markdown，不要解释',
                'model_selection' => $model,
                'model_config' => ['max_tokens' => 8192, 'enable_thinking' => false],
                'action_code' => 'script_plan_repair',
                'parent_app_task_id' => $parentAppTaskId,
            ], $repairHeartbeat);
        } catch (Exception $e) {
            Log::write('AI short drama script plan repair model failed: ' . $e->getMessage());
            throw new Exception(self::scriptPlanProviderError($e->getMessage()));
        }
    }

    private static function scriptPlanProviderError(string $message): string
    {
        $lower = strtolower($message);
        if (str_contains($lower, 'sqlstate') || str_contains($lower, 'integrity constraint') || str_contains($lower, 'duplicate entry')) {
            return self::SAFE_ERROR;
        }
        if (str_contains($lower, 'bad request') || str_contains($lower, 'invalid request')) {
            return '文本模型 API 请求不兼容，请检查算力市场模型配置';
        }
        if (str_contains($message, '点数') || str_contains($message, '余额') || str_contains($lower, 'quota')) {
            return '文本模型额度不足，请检查算力市场模型状态与点数余额';
        }
        if (str_contains($lower, 'api key') || str_contains($lower, 'invalid') || str_contains($lower, 'disabled')) {
            return '文本模型调用异常，请检查算力市场模型状态';
        }
        return $message !== '' ? $message : self::SAFE_ERROR;
    }

    private static function buildScriptPlanPrompt(string $prompt, array $request, string $title): string
    {
        $styleDetail = self::styleDetail((string)($request['style_id'] ?? ''));
        $selectedStyleName = (string)($styleDetail['name'] ?? '');
        $selectedStylePrompt = (string)($styleDetail['prompt'] ?? '');
        $userTextStyleHint = self::extractUserTextStyleHint($prompt);
        $userTextDurationHint = self::extractUserTextDurationHint($prompt);
        $userTextTimePeriodHint = self::extractUserTextTimePeriodHint($prompt);
        $selectedDurationHint = self::selectedDurationHint($request);
        $timelineSegments = self::extractTimelineSegments($prompt);
        $targetDurationSeconds = self::planningTargetDurationSeconds($prompt, $request);
        $recommendedCountHint = self::recommendedStoryboardCountHint($prompt, $request);
        $storyboardRules = self::storyboardRulesFromRequest($request);
        $storyboardTargetRule = self::storyboardTargetRule($prompt, $request);
        $timelineOverride = !empty($timelineSegments) && $selectedDurationHint === '';
        $breakingIntensityInstruction = self::storyboardBreakingIntensityInstruction($storyboardRules, $timelineOverride);
        $styleSource = $selectedStyleName !== '' || $selectedStylePrompt !== ''
            ? 'selected'
            : ($userTextStyleHint !== '' ? 'user_text' : 'default');
        $durationSource = $selectedDurationHint !== ''
            ? 'selected'
            : (!empty($timelineSegments) ? 'timeline' : ($userTextDurationHint !== '' ? 'user_text' : 'default'));
        $context = [
            'title_hint' => $title,
            'user_prompt' => $prompt,
            'multi_episode' => (bool)($request['multi_episode'] ?? false),
            'episode_count' => (int)($request['episode_count'] ?? 1),
            'target_duration_seconds' => (int)($request['target_duration_seconds'] ?? 0),
            'effective_target_duration_seconds' => $targetDurationSeconds,
            'selected_style_id' => (string)($request['style_id'] ?? ''),
            'selected_style_name' => $selectedStyleName,
            'selected_style_prompt' => $selectedStylePrompt,
            'selected_duration_hint' => $selectedDurationHint,
            'user_text_style_hint' => $userTextStyleHint,
            'user_text_duration_hint' => $userTextDurationHint,
            'user_text_time_period_hint' => $userTextTimePeriodHint,
            'timeline_segments' => $timelineSegments,
            'timeline_total_seconds' => self::timelineTotalSeconds($timelineSegments),
            'style_source' => $styleSource,
            'duration_source' => $durationSource,
            'style_priority_rule' => 'selected style config overrides conflicting style words in user_prompt; user_prompt style/time/duration hints are used only when selected config is empty; aspect ratio is not part of script planning.',
            'recommended_storyboard_count_hint' => $recommendedCountHint,
            'storyboard_complexity_rules' => $storyboardRules,
            'storyboard_target_rule' => $storyboardTargetRule,
            'storyboard_complexity_instruction' => 'When there is no explicit target duration or timeline, first judge story complexity from plot structure, scene count, content form, suspense/reversal/dream elements, and action/emotion density. Then choose the matching storyboard_complexity_rules row and keep shot_count inside its min/max range unless the selected row has max_shots=0, which means no upper limit.',
            'storyboard_breaking_intensity_instruction' => $breakingIntensityInstruction,
            'storyboard_quality_gate' => [
                'must_cover_every_location' => true,
                'minimum_shots_per_location' => $timelineOverride ? 1 : ($targetDurationSeconds > 0 ? 3 : 2),
                'minimum_total_shots' => self::minimumStoryboardShotCount($prompt, $request, 0),
                'target_rule_min_shots' => (int)($storyboardTargetRule['min_shots'] ?? 0),
                'target_rule_max_shots' => (int)($storyboardTargetRule['max_shots'] ?? 0),
                'duration_rule' => $timelineOverride
                    ? 'Follow timeline_segments strictly. Total storyboard duration must equal timeline_total_seconds exactly.'
                    : 'Every shot is 2-5 seconds. Total storyboard duration should approach effective_target_duration_seconds when it is greater than 0.',
            ],
            'script_agent_contract' => [
                'role' => 'film short planning agent, script doctor, visual director, and AI video storyboard planner',
                'goal' => 'turn any user inspiration into a complete Chinese script planning document directly usable for AI video creation',
                'must_preserve' => 'key characters, events, scenes, props, emotions, conflicts, twists, ending, style requirements, and special imagery from user_prompt',
                'must_infer' => 'story type, emotional tone, central conflict, character relationships, key imagery, hidden theme, and ending meaning',
                'must_output' => 'title, type judgement, core theme, complete beginning-to-ending plot, executable art style, ordered scene list, and scene-based storyboard script',
            ],
            'subject_mentions' => (array)($request['subject_mentions'] ?? []),
            'revision_message' => (string)($request['revision_message'] ?? ''),
            'revision_base_task_id' => (string)($request['revision_base_task_id'] ?? ''),
            'revision_base_result' => is_array($request['revision_base_result'] ?? null) ? $request['revision_base_result'] : [],
            'revision_target' => is_array($request['revision_target'] ?? null) ? $request['revision_target'] : [],
        ];
        $schema = [
            'title' => '短剧标题，简体中',
            'type_judgement' => '中文影视类型判断，例如悬疑短片、梦境惊悚、爱情短片、广告片、MV、情绪短片等',
            'core_theme' => '中文核心主题一句话，说明故事真正表达的主题',
            'opening_feedback' => 'professional planning feedback for the user idea, within 80 Chinese characters',
            'planning_steps' => ['中文策划步骤1', '中文策划步骤2', '中文策划步骤3'],
            'story_outline' => '中文完整剧情概述，从开始、发展、冲突、转折、高潮到结尾写清楚，300-800个中文字',
            'script_lines' => ['中文剧情段落1：按剧情顺序写完整故事，不写镜头术语', '中文剧情段落2：继续承接剧情直到结尾'],
            'music_plan' => [
                'music_title' => '中文背景音乐名称',
                'global_bgm_prompt' => '完整中文背景音乐生成提示词，纯音乐，无歌词无人声，包含情绪曲线、节奏、乐器、氛围、时长',
                'style' => '中文音乐风格',
                'mood_curve' => '中文情绪变化，例如开场悬疑-中段紧张-结尾释然',
                'bpm' => '建议速度，例如 80-96 BPM',
                'instruments' => ['中文乐器1', '中文乐器2'],
                'duration_seconds' => 60,
                'negative_prompt' => '不要歌词，不要人声，不要突兀鼓点，不要版权旋',
            ],
            'art_style' => [
                'base_style' => '中文画风名称',
                'visual_description' => '中文整体视觉风格描述。不要包含比例、画布尺寸或横竖屏词',
                'color_tone' => '中文色彩基调',
                'lighting_design' => '中文光影设计',
                'camera_texture' => '中文镜头质感',
                'atmosphere_keywords' => ['中文氛围关键词1', '中文氛围关键词2'],
            ],
            'subjects' => [
                [
                    'id' => 'subject_1',
                    'name' => '中文主体名称',
                    'description' => '中文主体性格、用途和叙事功能',
                    'visual_prompt' => '中文主体生图一致性描述。人物写固定外貌、服装、体态、气质；道具/物品只写材质、颜色、形状、磨损痕迹、尺寸比例、标志性细节，不写脸、服装、体态',
                    'main_image_prompt' => '中文主体主图提示词。人物写单个角色标准参考图，包含外貌、发型、服装、体态、气质和可用于分镜一致性的细节；物品写单个物品独立参考图，只包含材质、颜色、形状、磨损、尺寸比例和标志性细节',
                    'three_view_prompt' => '中文主体三视图提示词。人物写同一角色正面、侧面、背面三视图，保持同一张脸、同一服装、同一体态；物品写同一物品多角度设定图，保持材质、颜色、形状和磨损一致，不出现人物穿戴效果',
                    'main_negative_prompt' => '中文主体主图负向词。人物不得包含不要人物、不要脸、不要身体；物品必须禁止人物、手、脸、身体、模特、穿戴效果、文字、水印',
                    'three_view_negative_prompt' => '中文主体三视图负向词。人物禁止换脸、换装、比例错误、文字、水印；物品禁止人物、手、脸、身体、模特、穿戴效果、文字、水印',
                    'category' => 'character | animal | prop | symbol。分类按主体本身判断：人物即使携带书、围巾、钥匙或穿着特殊服装也必须归为 character；只有主体本身是旧书、围巾、信件、钥匙、照片等独立物品时才归为 prop',
                    'appearance_lock' => '中文固定视觉细节；道具/物品写材质、形状、颜色、磨损和标志性细节',
                    'outfit_lock' => '仅人物填写固定服装细节；道具/物品必须为空',
                    'temperament' => '仅人物填写表情气质；道具/物品写叙事气质或为空',
                    'negative_prompt' => '人物：不要换脸、不要换装、不要文字、不要水印；道具/物品：不要人物、不要手、不要脸、不要身体、不要模特、不要穿戴效果、不要文字、不要水印',
                ],
            ],
            'locations' => [
                [
                    'id' => 'location_1',
                    'story_order' => 1,
                    'story_phase' => '开端',
                    'name' => '中文场景名称',
                    'description' => '中文场景描述，包含剧情作用、主要视觉元素、情绪氛围',
                    'visual_prompt' => '中文场景生图一致性描述，包含空间结构、光线、色彩和氛围',
                    'scene_image_prompt' => '中文场景图提示词，只描述空间结构、主要环境元素、光线、色彩和氛围，不写人物主体，不写角色一致性',
                    'scene_negative_prompt' => '中文场景图负向词，禁止人物、人类、角色、脸、身体、肖像、文字、水印',
                    'location' => '中文具体地点',
                    'time_light' => '中文时间和光线锁',
                    'spatial_layout' => '中文稳定空间结构',
                    'atmosphere' => 'scene atmosphere',
                    'color_palette' => 'stable color palette',
                    'negative_prompt' => '不要人物，不要角色，不要文字，不要水印',
                ],
            ],
            'storyboard' => [
                [
                    'shot_id' => '1',
                    'act' => '第一幕：中文场景｜中文时间 室内或室外',
                    'scene_order' => 1,
                    'scene_name' => '中文场景',
                    'time_of_day' => '中文时间',
                    'interior_exterior' => 'exterior',
                    'visual_description' => '中文画面可见内容描述',
                    'composition' => '中文构图设计',
                    'camera_movement' => '中文运镜调度',
                    'shot_type' => '中文画面类型，例如普通画面、空镜、人物特写、道具特写、动作镜头、转场镜头、情绪镜头',
                    'angle' => 'eye level',
                    'action' => '中文单一清晰动作',
                    'result' => '中文动作结果',
                    'atmosphere' => '中文镜头氛围',
                    'voice_role' => '',
                    'dialogue' => '',
                    'frame_type' => 'normal',
                    'recommended_duration_seconds' => 3,
                    'scene_ref_id' => 'location_1',
                    'subject_ref_ids' => ['subject_1'],
                    'image_prompt' => '80-180字中文画面生图指令，必须包含可见主体、动作表情、绑定场景、构图景别、光线氛围、风格质感；禁止包含“本镜头、推动剧情、情绪升级、视觉任务、下一拍、分镜、镜头编号、参考已提供”等策划话术；只描述当前画面可见内容，不写英文',
                    'image_negative_prompt' => '中文分镜图负向词。有主体时不要禁止人物、脸、身体、肖像；空镜 subject_ref_ids 为空时必须禁止人物、角色、脸、身体、肖像、文字、水印',
                    'video_prompt' => '前端可见的栏目化单分镜导演提示词，必须按固定6行输出：分镜{shot_id}｜0:00-00:05\n景别：{shot_type}\n构图：{composition}\n运镜手法：{camera_movement}\n画面内容：{visual_description + action + result}\n声音：{dialogue / voice_role / sound_effect / silence_rule}。禁止写 <location>、<role>、<duration-ms> 等后端执行标签；禁止包含“情绪升级、推动剧情、视觉任务、本镜头、下一拍、做出反应、生成视频片段”等策划话术；不写英文',
                    'video_negative_prompt' => '中文分镜视频负向词。有主体时禁止闪烁、脸部漂移、服装变化、场景漂移、多余角色、文字、水印，但绝不能写不要人物、不要角色、不要脸、不要身体、不要肖像；空镜 subject_ref_ids 为空时可以禁止人物、角色、脸、身体、肖像',
                    'bgm_prompt' => '中文分镜背景音乐提示，承接全局音乐方案',
                    'sound_effect' => '中文音效提示',
                ],
            ],
        ];

        return "Create a short-drama planning document that can enter the image/video generation workflow.\n"
            . "Requirements:\n"
            . "1. You are not a generic continuation assistant. Work as a film script generation and cinematic breakdown agent.\n"
            . "2. Preserve the user's original inspiration. Keep all key characters, events, scenes, props, emotions, conflicts, reversals, ending, style requirements, and special imagery. Do not replace it with another story.\n"
            . "3. Precisely infer user intent: type_judgement, emotional tone, central conflict, character relationships, key imagery, hidden theme, and ending meaning. Put the concise result into type_judgement, core_theme, opening_feedback, and story_outline.\n"
            . "4. story_outline and script_lines are the screenplay content area. They must be a complete beginning-to-ending plot summary based on the decomposed story, not shot text and not prompt text. Write the opening, development, conflict, turning point, climax, and ending clearly. Use cinematic narrative language, but do not put camera terms in script_lines. script_lines must contain only story prose and must not include act titles, scene headers, storyboard titles, type_judgement labels, core_theme labels, subject/role lists, or any field-name style labels such as ''幕：', '类型判断', '核心主题', '出场角色'.\n"
            . "5. Expand cinematically only where needed. Short input needs story logic, emotional progression, visual details, and rhythm. Complete input should keep its original structure and only improve film execution.\n"
            . "6. Translate style names into executable visual language: base_style, visual_description, color_tone, lighting_design, camera_texture, atmosphere_keywords, subject visual locks, scene visual locks, image_prompt, and video_prompt.\n"
            . "6A. Subject category is mandatory and must describe the subject itself, not accessories it carries or wears. Use character only for visible human characters, animal for animals/non-human creatures, prop for books, scarves, letters, keys, photos, objects, tools, vehicles, and physical symbols, and symbol for abstract/signature imagery. If a human character holds a book, wears a scarf, carries a key, or has clothing/props in the visual_prompt, the category must still be character. If a subject is prop/object, never describe face, outfit, body shape, hairstyle, expression, or person identity; describe only material, color, shape, wear, scale, pattern, and fixed details, and add negative constraints against people, hands, faces, bodies, models, and wearing effects.\n"
            . "6B. Prompt fields must be separated by usage and must not all repeat the same text. subjects.main_image_prompt is for one confirmed subject reference image; subjects.three_view_prompt is for character three-view or object multi-angle design; locations.scene_image_prompt is for a reusable empty environment reference; storyboard.image_prompt is for a single still keyframe; storyboard.video_prompt is for 2-5 second motion from the first frame. Negative prompts must also be separated: character subject prompts and storyboard shots with visible subject_ref_ids must never include constraints like 不要人物、不要角色、不要脸、不要身体、不要肖' empty shots or scenes may include those constraints.\n"
            . "7. Extract stable subjects before storyboard. Include main characters, important supporting characters, character variants, monsters, animals, non-human roles, key props, symbolic marks, and special imagery when present. Every recurring character, prop, and place must keep one stable id.\n"
            . "8. Extract stable locations before storyboard and sort them by story_order, which must match the plot chronology. Each location description must include story function, main visual elements, and emotional atmosphere. Do not list scenes by visual preference; list them by when the story happens.\n"
            . "9. Treat ordered locations as the structure of the storyboard. First split acts by main space, location, or story phase, such as bedroom, street, rainy rooftop, dream forest, lab, memory fragment, or another concrete space. For each location, generate the shots that happen inside that scene before moving to the next scene. Each location can have only one act group, and every storyboard item bound to the same scene_ref_id must reuse the exact same act title in the format '第N幕：场景'时间 室内/室外'. Never create duplicate act titles for the same scene, such as one with time/location suffix and one without. The act order, locations.story_order, storyboard.scene_order, and storyboard.scene_ref_id must stay aligned.\n"
            . "10. Storyboard must cover the complete process: opening, development, conflict, turn, climax, and ending. If user_prompt contains a reversal, preserve and strengthen it in both script_lines and storyboard.\n"
            . "11. Split storyboard by AI video shot granularity, not by literary paragraph. Each shot must have exactly one clear visual task: establish space, show a character, show a key prop, show a reaction, push one action, create suspense, intensify emotion, reveal information, complete a transition, or present a reversal.\n"
            . "12. Do not pack complex actions into one shot. If one plot sentence contains multiple actions, split them into consecutive shots. For example, 'she opens the door, sees the monster, turns and runs' must become separate shots: hand touches doorknob, door slowly opens, monster appears behind door, protagonist terrified close-up, protagonist turns and runs.\n"
            . "13. Important emotions and reversals must be decomposed into multiple short shots. Climax, truth reveal, key prop appearance, breakdown, relationship change, and reversal nodes must not be summarized in one shot.\n"
            . "14. recommended_duration_seconds must be 2, 3, 4, or 5 only. Normal action shots: 2-3 seconds. Emotional close-ups: 3-4 seconds. Establishing shots: 3-5 seconds. Climax/reveal shots: 4-5 seconds.\n"
            . "15. There is no fixed storyboard count by text length. Never use 8 as the default answer. If target duration or timeline exists, follow the duration/timeline rule. Otherwise storyboard_target_rule is authoritative: storyboard.length must be at least storyboard_target_rule.min_shots and must not exceed storyboard_target_rule.max_shots when max_shots is greater than 0. Judge the story complexity first, then apply storyboard_complexity_rules and storyboard_breaking_intensity_instruction: simple talking-head/advertising/single-scene content uses light splitting, ordinary short films use standard splitting, complex dream/suspense/reversal films use detailed splitting, and complex multi-scene plots use cinematic detailed splitting. Do not compress key shots just to keep the output short, and do not split meaningless filler shots just to pad count. Use recommended_storyboard_count_hint only as a pacing reference.\n"
            . "16. Each shot expresses one visible action or one visual information task and binds exactly one scene_ref_id from locations. subject_ref_ids must include only subjects visible in the current image frame; never include characters, props, animals, symbols, or locations that do not appear on screen. image_prompt must not mention any off-screen character, prop, object, or location, and each image_prompt may describe only one core visible action.\n"
            . "16A. Storyboard quality gate: every location in locations must appear in storyboard at least once, and normally at least 2-4 shots per location. If effective_target_duration_seconds is set, distribute shots across all ordered locations until the total duration is close to that target. Never stop after only the first one or two locations when later locations exist.\n"
            . "16B. If timeline_segments is not empty and selected_duration_hint is empty, the user has provided a finished timecoded script. Storyboard must strictly follow timeline_segments: use one storyboard item per time segment by default; split a segment only when it is longer than 5 seconds; the sum of recommended_duration_seconds must equal timeline_total_seconds exactly; do not expand a 30-second timecoded script into a longer film.\n"
            . "17. Each storyboard item must include Simplified Chinese visual_description, composition, camera_movement, voice_role, dialogue, shot_type, frame_type, image_prompt, image_negative_prompt, video_prompt, video_negative_prompt, and recommended_duration_seconds. visual_description must describe only visible people, objects, actions, spatial relations, light, and atmosphere in the frame; it must not be a planning note or abstract function sentence, and must never use phrases such as “主要角色出现、人物状态、情绪开始推进、角色完成一个单一动作、推动剧情、画面任务、镜头结果明确' shot_type must be the user-facing visual type, such as 普通画面、空镜、人物特写、道具特写、动作镜头、转场镜头、情绪镜' frame_type is only the machine value normal or lip_sync. composition must state shot size, angle, and framing method in Chinese, such as 远景、近景特写、极近特写、俯拍、仰拍、对称构图、三分法构图或过肩视' camera_movement must state movement in Chinese, such as 固定镜头、缓慢推镜头、拉镜头、横摇、跟拍、手持晃动、甩镜头、环绕或俯冲. image_prompt must be an 80-180 Chinese-character image generation instruction, not a planning note. It must include visible subject, action/expression, bound scene, composition/shot size, lighting/mood, and style/texture; describe only current visible frame content; do not include English prompt words; do not use planning phrases such as “本镜头、推动剧情、情绪升级、视觉任务、下一拍、分镜、镜头编号、参考已提供' image_negative_prompt must match whether the shot has visible subjects: if subject_ref_ids is empty or shot_type is 空镜, prohibit people; otherwise do not prohibit people, face, body, or portrait. video_prompt is a user-visible single-shot director note and must be exactly 6 labeled lines: 分镜{shot_id}：{start_time}-{end_time}, 景别：{shot_type}, 构图：{composition}, 运镜手法：{camera_movement}, 画面内容：{visible subject/action/result only}, 声音：{dialogue/voice_role/sound_effect/silence}. It must describe only the current single shot, mention only visible subject_ref_ids, and never include backend execution tags such as <location>, <role>, or <duration-ms>, because those tags are generated only by backend from real bound assets. Empty shots must have no character action in 画面内容 and 声音 must clearly say no one speaks. Do not use planning phrases such as “情绪升级、推动剧情、视觉任务、本镜头、下一拍、做出反应、生成视频片段' video_negative_prompt must not include 不要人物、不要角色、不要脸、不要身体、不要肖'when subject_ref_ids is not empty and shot_type is not 空镜.\n"
            . "18. frame_type can only be normal or lip_sync. interior_exterior can only be interior or exterior.\n"
            . "19. User-facing fields must be Simplified Chinese: title, type_judgement, core_theme, opening_feedback, planning_steps, story_outline, script_lines, music_plan, art_style, subjects.name, subjects.description, subjects.visual_prompt, subjects.main_image_prompt, subjects.three_view_prompt, subjects.main_negative_prompt, subjects.three_view_negative_prompt, locations.name, locations.description, locations.visual_prompt, locations.scene_image_prompt, locations.scene_negative_prompt, storyboard.act, storyboard.scene_name, storyboard.time_of_day, storyboard.visual_description, storyboard.composition, storyboard.camera_movement, storyboard.shot_type, storyboard.action, storyboard.result, storyboard.atmosphere, storyboard.image_prompt, storyboard.image_negative_prompt, storyboard.video_prompt, storyboard.video_negative_prompt, voice_role, dialogue, bgm_prompt, sound_effect. Do not return English generation prompts to the frontend. storyboard.act is the only place for act/scene group titles; never duplicate storyboard.act content into script_lines.\n"
            . "20. Script planning does not receive or decide aspect ratio. Do not write ratio, portrait/landscape, vertical screen, horizontal screen, or canvas size into art_style.visual_description or any user-facing planning field.\n"
            . "21. If selected_style_name or selected_style_prompt is not empty, art_style, subject visual locks, location visual locks, image_prompt, and video_prompt must follow the selected style. Conflicting style words in user_prompt may affect plot mood only, not the visual style authority.\n"
            . "22. If no style is selected, derive the visual style from user_text_style_hint and user_text_time_period_hint; if they are empty, use a neutral short-drama visual style.\n"
            . "23. If selected_duration_hint or user_text_duration_hint is not empty, treat it as an approximate pacing target, not an exact stopwatch value: storyboard total should usually land within about 90%-110% of the target unless story logic clearly needs a slight deviation. Choose shot count from plot density and scene coverage; do not hard-code a fixed number of storyboard items just to hit the target. Otherwise use the default short-drama pacing.\n"
            . "24. music_plan.global_bgm_prompt is required. It must be a complete Chinese prompt for generating one full-film instrumental BGM track. It must include style, mood curve, tempo, instruments, atmosphere, duration target, and negative constraints. No lyrics or human voice unless the user explicitly asks.\n"
            . "25. Every storyboard item must include non-empty Chinese bgm_prompt and sound_effect. bgm_prompt should describe this shot's musical emotion while staying consistent with music_plan.global_bgm_prompt.\n"
            . "26. Revision mode: if revision_message is not empty, use revision_base_result as the previous complete script version and regenerate a new full script plan by applying revision_message. Preserve all unaffected plot, subjects, scenes, style locks, music plan, and storyboard logic from revision_base_result. Only change the parts requested by revision_message, but still return a complete new JSON object with all fields, not a diff, not a partial patch, and not an explanation. If revision_target is provided, only modify that target and directly related consistency fields; keep unrelated subjects, locations, storyboard order, story outline, and prompts unchanged.\n"
            . "27. Before returning, silently self-check: original key elements preserved, script content is a complete beginning-to-ending plot, locations are chronologically ordered, storyboard is grouped under ordered scenes with no duplicate act title for the same scene_ref_id, each location has enough concrete shots, each shot has one visible visual task, no complex action is packed into one shot, no abstract placeholder visual_description, important emotion/reversal is split into multiple shots, no fixed 8-shot default is used, subjects extracted, selected/user style respected, and AI video production executable.\n"
            . "28. Return one JSON object only. Do not return Markdown, code fences, explanations, or analysis process.\n"
            . "Context: " . self::jsonEncode($context) . "\n"
            . "JSON schema: " . self::jsonEncode($schema);
    }

    private static function scriptPlanSystemPrompt(): string
    {
        return <<<'PROMPT'
You are a professional film short planning agent, script doctor, visual director, continuity supervisor, AI video storyboard planner, and background music planner. You are not a generic writing assistant.
Return valid JSON only. Do not return Markdown, code fences, explanations, or analysis process.

Preserve the user's original inspiration and all key characters, events, scenes, props, emotions, conflicts, reversals, ending, style requirements, and special imagery. Infer story type, emotional tone, conflict, relationships, key imagery, theme, and ending meaning, then transform them into a complete Chinese planning document usable for AI video creation.

story_outline and script_lines must be a complete beginning-to-ending plot summary, not shot text. script_lines must contain only story prose; never include act titles, scene headers, storyboard titles, type labels, core-theme labels, or role lists.

User-facing planning, subject, scene, storyboard, music_plan, main_image_prompt, three_view_prompt, scene_image_prompt, image_prompt, video_prompt, negative prompts, bgm_prompt, and sound_effect fields must be Simplified Chinese.

Subject category must describe the subject itself: a human holding or wearing props remains character, and only a standalone object is prop.

Prompt fields must be split by actual generation purpose and must not all repeat one generic prompt: subject main image, subject three-view/object multi-angle, scene image, storyboard still image, and storyboard video each need their own prompt and negative prompt.

Character subject prompts and storyboard shots with visible subject_ref_ids must never include negative constraints such as 不要人物、不要角色、不要脸、不要身体、不要肖像. Empty shots and scene reference images may include those constraints.

Every storyboard.video_prompt must be a user-visible six-line single-shot director note using labels 分镜、景别、构图、运镜手法、画面内容、声音, and must never include backend execution tags such as <location>, <role>, or <duration-ms>.

Translate style names into concrete visual language: color, lighting, texture, composition, atmosphere, rhythm, and emotional tone.

Build the storyboard from stable locations: locations are ordered by story chronology, acts are scene-based, each shot binds exactly one scene_ref_id, and subject_ref_ids contain only visible subjects in that shot. Every storyboard item bound to the same scene_ref_id must reuse one identical act title; never split one scene into duplicate act groups.

storyboard.visual_description must describe visible people, objects, actions, spatial relations, light, and atmosphere, never abstract planning phrases like “主要角色出现、情绪开始推进、角色完成一个单一动作、推动剧情”.

storyboard.image_prompt is a Chinese image generation instruction, not a planning explanation, and must not contain English prompt words. It must describe only visible content in the current bound scene, include only visible subject_ref_ids, avoid off-screen characters or props, and use one core visible action per prompt. Never write planning phrases such as “本镜头、推动剧情、情绪升级、视觉任务、下一拍、分镜、镜头编号、参考已提供” in image_prompt.

storyboard.video_prompt is not the final provider prompt; it is the frontend director note only. It must keep the exact six labels, describe only the current single shot, mention only visible subject_ref_ids, and never use planning phrases such as “情绪升级、推动剧情、视觉任务、本镜头、下一拍、做出反应、生成视频片段”. Empty shots must have no character action in 画面内容 and 声音 must clearly say no one speaks.

Split storyboard by AI video generation granularity, not literary paragraphs: every shot is 2-5 seconds and has exactly one clear visual task such as establishing space, showing a character, showing a prop, showing a reaction, pushing one action, creating suspense, intensifying emotion, revealing information, completing a transition, or presenting a reversal. Do not pack complex actions into one shot. Important emotions, reveals, climax, and reversals must be split into multiple consecutive shots. The storyboard must cover opening, development, conflict, turn, climax, and ending.

Explicit selected style and duration config override conflicting user text hints. Use user text style/time/duration hints only when selected config is empty. Aspect ratio is not part of script planning.

There is no fixed storyboard count by text length; never use 8 as the default. When no target duration or timeline exists, judge story complexity and follow the tenant-configured storyboard complexity rules and storyboard breaking intensity from context: light for simple talking-head/advertising/single-scene content, standard for ordinary short films, detailed for complex dream/suspense/reversal films, and cinematic detailed for complex multi-scene plots. Timeline segments without selected duration override storyboard intensity ranges and must not be expanded.

Always keep the flat storyboard[] output structure; never output nested scene_id + shots[] data. All content must come from the user input and reasonable expansion, with stable Chinese characters, subjects, locations, image prompts, video prompts, and one complete full-film instrumental BGM prompt. Do not return English image or video generation prompts.
PROMPT;
    }

    private static function decodeLlmJsonObject(string $content): array
    {
        $content = trim($content);
        $content = preg_replace('/^```(?:json)?\s*/i', '', $content) ?? $content;
        $content = preg_replace('/\s*```$/', '', $content) ?? $content;
        $start = strpos($content, '{');
        $end = strrpos($content, '}');
        if ($start === false) {
            throw new Exception('AI 返回内容格式异常，请重试');
        }
        $data = [];
        if ($end !== false && $end > $start) {
            $json = substr($content, $start, $end - $start + 1);
            $data = json_decode($json, true);
            if (!is_array($data)) {
                $repaired = preg_replace('/,\s*([}\]])/', '$1', $json) ?? $json;
                $repaired = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $repaired) ?? $repaired;
                $data = json_decode($repaired, true);
            }
        }
        if (!is_array($data) || empty($data)) {
            $data = self::decodePartialLlmJsonObject($content);
        }
        if (!is_array($data)) {
            Log::write('AI short drama script plan JSON parse failed: ' . json_last_error_msg() . ' excerpt=' . mb_substr($content, 0, 800, 'UTF-8'));
            throw new Exception('AI 返回内容解析失败，请重试');
        }
        return $data;
    }

    private static function decodePartialLlmJsonObject(string $content): array
    {
        $content = trim($content);
        $content = preg_replace('/^```(?:json)?\s*/i', '', $content) ?? $content;
        $content = preg_replace('/\s*```$/', '', $content) ?? $content;
        $start = strpos($content, '{');
        if ($start === false) {
            return [];
        }
        $content = substr($content, $start);

        $payload = [];
        foreach (['title', 'type_judgement', 'core_theme', 'opening_feedback', 'story_outline'] as $key) {
            $value = self::extractJsonStringField($content, $key);
            if ($value !== '') {
                $payload[$key] = $value;
            }
        }

        foreach (['planning_steps', 'script_lines', 'music_plan', 'art_style', 'subjects', 'locations'] as $key) {
            $value = self::extractCompleteJsonValue($content, $key);
            if (is_array($value)) {
                $payload[$key] = $value;
            }
        }

        $storyboard = self::extractCompleteObjectsFromJsonArray($content, 'storyboard');
        if (!empty($storyboard)) {
            $payload['storyboard'] = $storyboard;
        }

        if (!empty($payload['storyboard']) && !empty($payload['subjects']) && !empty($payload['locations'])) {
            Log::write('AI short drama script plan JSON was truncated; recovered complete fields and ' . count($payload['storyboard']) . ' storyboard items.');
            return $payload;
        }
        return [];
    }

    private static function extractJsonStringField(string $content, string $key): string
    {
        if (!preg_match('/"' . preg_quote($key, '/') . '"\s*:\s*"((?:\\\\.|[^"\\\\])*)"/su', $content, $matches)) {
            return '';
        }
        $decoded = json_decode('"' . $matches[1] . '"', true);
        return is_string($decoded) ? trim($decoded) : trim(stripslashes($matches[1]));
    }

    private static function extractCompleteJsonValue(string $content, string $key)
    {
        $offset = self::jsonValueOffset($content, $key);
        if ($offset < 0) {
            return null;
        }
        $json = self::readCompleteJsonValue($content, $offset);
        if ($json === '') {
            return null;
        }
        $decoded = json_decode($json, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }

    private static function jsonValueOffset(string $content, string $key): int
    {
        if (!preg_match('/"' . preg_quote($key, '/') . '"\s*:/u', $content, $matches, PREG_OFFSET_CAPTURE)) {
            return -1;
        }
        $offset = (int)$matches[0][1] + strlen($matches[0][0]);
        $length = strlen($content);
        while ($offset < $length && ctype_space($content[$offset])) {
            $offset++;
        }
        return $offset < $length ? $offset : -1;
    }

    private static function readCompleteJsonValue(string $content, int $offset): string
    {
        $length = strlen($content);
        if ($offset < 0 || $offset >= $length) {
            return '';
        }
        $first = $content[$offset];
        if ($first === '{' || $first === '[') {
            $close = $first === '{' ? '}' : ']';
            $depth = 0;
            $inString = false;
            $escaped = false;
            for ($i = $offset; $i < $length; $i++) {
                $char = $content[$i];
                if ($inString) {
                    if ($escaped) {
                        $escaped = false;
                    } elseif ($char === '\\') {
                        $escaped = true;
                    } elseif ($char === '"') {
                        $inString = false;
                    }
                    continue;
                }
                if ($char === '"') {
                    $inString = true;
                    continue;
                }
                if ($char === $first) {
                    $depth++;
                } elseif ($char === $close) {
                    $depth--;
                    if ($depth === 0) {
                        return substr($content, $offset, $i - $offset + 1);
                    }
                }
            }
            return '';
        }
        if ($first === '"') {
            $escaped = false;
            for ($i = $offset + 1; $i < $length; $i++) {
                $char = $content[$i];
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === '"') {
                    return substr($content, $offset, $i - $offset + 1);
                }
            }
        }
        return '';
    }

    private static function extractCompleteObjectsFromJsonArray(string $content, string $key): array
    {
        $offset = self::jsonValueOffset($content, $key);
        if ($offset < 0 || ($content[$offset] ?? '') !== '[') {
            return [];
        }
        $length = strlen($content);
        $objects = [];
        $inString = false;
        $escaped = false;
        $depth = 0;
        $objectStart = -1;
        for ($i = $offset + 1; $i < $length; $i++) {
            $char = $content[$i];
            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === '"') {
                    $inString = false;
                }
                continue;
            }
            if ($char === '"') {
                $inString = true;
                continue;
            }
            if ($char === '{') {
                if ($depth === 0) {
                    $objectStart = $i;
                }
                $depth++;
                continue;
            }
            if ($char === '}') {
                if ($depth > 0) {
                    $depth--;
                    if ($depth === 0 && $objectStart >= 0) {
                        $json = substr($content, $objectStart, $i - $objectStart + 1);
                        $decoded = json_decode($json, true);
                        if (is_array($decoded)) {
                            $objects[] = $decoded;
                        }
                        $objectStart = -1;
                    }
                }
                continue;
            }
            if ($char === ']' && $depth === 0) {
                break;
            }
        }
        return $objects;
    }

    private static function normalizeGeneratedPlanResult(array $payload, string $prompt, array $request, string $title): array
    {
        $normalizedTitle = trim((string)($payload['title'] ?? $title));
        $typeJudgement = trim((string)($payload['type_judgement'] ?? $payload['type'] ?? ''));
        $coreTheme = trim((string)($payload['core_theme'] ?? $payload['theme'] ?? ''));
        $openingFeedback = trim((string)($payload['opening_feedback'] ?? ''));
        $planningSteps = self::stringList($payload['planning_steps'] ?? []);
        $storyOutline = trim((string)($payload['story_outline'] ?? ''));
        $scriptLines = self::stringList($payload['script_lines'] ?? []);
        if (empty($scriptLines) && $storyOutline !== '') {
            $scriptLines = self::stringList(preg_split('/(?<=[。！？!])\s*/u', $storyOutline) ?: []);
        }
        if ($storyOutline === '') {
            $storyOutline = mb_substr(trim($prompt), 0, 500, 'UTF-8');
        }
        if (empty($scriptLines) && $storyOutline !== '') {
            $scriptLines = [$storyOutline];
        }
        // These are presentation fields, not evidence that the generated
        // characters, locations and storyboard are usable. Some otherwise
        // valid models omit them when a JSON response is truncated.
        if ($openingFeedback === '') {
            $openingFeedback = '已根据灵感完成故事主线、人物关系和分镜策划。';
        }
        if (empty($planningSteps)) {
            $planningSteps = ['梳理故事主线与冲突', '确定人物和场景设定', '拆分可执行分镜'];
        }
        $artStyle = is_array($payload['art_style'] ?? null) ? (array)$payload['art_style'] : [];
        $subjects = self::normalizeGeneratedNamedItems((array)($payload['subjects'] ?? []), 'subject');
        $locations = self::normalizeGeneratedNamedItems((array)($payload['locations'] ?? []), 'location');
        $storyboard = self::normalizeGeneratedStoryboard((array)($payload['storyboard'] ?? []));
        $styleMeta = self::scriptPlanPriorityMeta($prompt, $request);
        $storyboardRepair = self::repairStoryboardCoverage($storyboard, $locations, $subjects, $prompt, $request, $storyOutline);
        $storyboard = $storyboardRepair['storyboard'];
        $durationRepair = self::balanceStoryboardDuration($storyboard, $locations, $subjects, $prompt, $request, $storyOutline);
        $storyboard = $durationRepair['storyboard'];
        $storyboardRepair['issues_fixed'] = array_values(array_unique(array_merge(
            (array)($storyboardRepair['issues_fixed'] ?? []),
            (array)($durationRepair['issues_fixed'] ?? [])
        )));
        $durationStats = self::durationStats($storyboard, count($locations));
        $storyboardDiagnostics = self::storyboardBreakingDiagnostics($storyboard, $locations, $request, $prompt);
        $musicPlan = self::normalizeMusicPlan((array)($payload['music_plan'] ?? []), $storyboard, $durationStats, $artStyle, $prompt);

        if ($normalizedTitle === '' || empty($scriptLines) || empty($subjects) || empty($locations) || empty($storyboard)) {
            throw new Exception('AI 剧本策划结果不完整，请重');
        }

        $modelSelections = is_array($request['model_selections'] ?? null) ? (array)$request['model_selections'] : [];
        $scriptModel = is_array($modelSelections['script_plan'] ?? null) ? (array)$modelSelections['script_plan'] : [];
        $scriptModelName = (string)(($scriptModel['name'] ?? '') ?: ($request['model_id'] ?? ''));
        $aspectRatio = self::normalizeGenerationRatio((string)($request['ratio'] ?? ''));

        return [
            'title' => mb_substr($normalizedTitle, 0, 120, 'UTF-8'),
            'created_at' => date('Y-m-d H:i:s'),
            'type_judgement' => mb_substr($typeJudgement, 0, 120, 'UTF-8'),
            'core_theme' => mb_substr($coreTheme, 0, 300, 'UTF-8'),
            'opening_feedback' => mb_substr($openingFeedback, 0, 500, 'UTF-8'),
            'planning_steps' => array_slice($planningSteps, 0, 8),
            'story_outline' => $storyOutline !== '' ? $storyOutline : mb_substr($prompt, 0, 500, 'UTF-8'),
            'script_lines' => array_slice($scriptLines, 0, 30),
            'music_plan' => $musicPlan,
            'art_style' => [
                'base_style' => trim((string)($artStyle['base_style'] ?? $styleMeta['selected_style_name'])),
                'visual_description' => self::cleanArtStyleDescription((string)($artStyle['visual_description'] ?? '')),
                'color_tone' => trim((string)($artStyle['color_tone'] ?? '')),
                'lighting_design' => trim((string)($artStyle['lighting_design'] ?? $artStyle['light_design'] ?? '')),
                'camera_texture' => trim((string)($artStyle['camera_texture'] ?? $artStyle['lens_texture'] ?? '')),
                'atmosphere_keywords' => self::stringList($artStyle['atmosphere_keywords'] ?? []),
            ],
            'subjects' => $subjects,
            'locations' => $locations,
            'storyboard' => $storyboard,
            'storyboard_breaking_diagnostics' => $storyboardDiagnostics,
            'duration_stats' => $durationStats,
            'generation_settings' => [
                'model' => $scriptModelName,
                'mode' => 'script_plan',
                'aspect_ratio' => $aspectRatio,
                'ratio' => $aspectRatio,
                'recommend_three_view' => true,
                'style_source' => $styleMeta['style_source'],
                'duration_source' => $styleMeta['duration_source'],
                'selected_style_name' => $styleMeta['selected_style_name'],
            ],
            'quality_check' => [
                'character_consistency' => 'Subject definitions are preserved for consistency.',
                'location_consistency' => 'Location definitions are preserved for consistency.',
                'timeline_consistency' => 'Storyboard is organized in shot order.',
                'generation_feasibility' => '已包含分镜画面、构图设计、运镜调度、提示词和时长字段',
                'issues_fixed' => (array)($storyboardRepair['issues_fixed'] ?? []),
            ],
        ];
    }

    private static function normalizeGeneratedNamedItems(array $items, string $prefix): array
    {
        $result = [];
        foreach (array_values($items) as $index => $item) {
            $item = is_array($item) ? $item : ['name' => (string)$item];
            $name = trim((string)($item['name'] ?? ''));
            $description = trim((string)($item['description'] ?? ''));
            if ($name === '' || $description === '') {
                continue;
            }
            $category = $prefix === 'subject' ? self::normalizeSubjectCategory($item) : '';
            $row = [
                'id' => trim((string)($item['id'] ?? '')) ?: $prefix . '_' . ($index + 1),
                'story_order' => max(1, (int)($item['story_order'] ?? $item['scene_order'] ?? ($index + 1))),
                'story_phase' => mb_substr(trim((string)($item['story_phase'] ?? '')), 0, 80, 'UTF-8'),
                'name' => mb_substr($name, 0, 80, 'UTF-8'),
                'description' => mb_substr($description, 0, 500, 'UTF-8'),
                'visual_prompt' => mb_substr(self::localizeGenerationPromptText(trim((string)($item['visual_prompt'] ?? '')), $description), 0, 1000, 'UTF-8'),
            ];
            if ($prefix === 'subject') {
                $row['category'] = $category;
            }
            $result[] = $row;
        }
        if ($prefix === 'location') {
            usort($result, static function (array $left, array $right): int {
                $leftOrder = (int)($left['story_order'] ?? 0);
                $rightOrder = (int)($right['story_order'] ?? 0);
                if ($leftOrder !== $rightOrder) {
                    return $leftOrder <=> $rightOrder;
                }
                return strcmp((string)($left['id'] ?? ''), (string)($right['id'] ?? ''));
            });
        }
        return $result;
    }

    private static function cleanArtStyleDescription(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }
        $text = preg_replace('/(?:recommended\s+aspect\s+ratio|aspect\s+ratio|ratio)\s*[:：]?\s*\d+\s*:\s*\d+[,，、；;\s]*/iu', '', $text) ?? $text;
        $text = preg_replace('/(?:^|[,，、。；;\s])\d+\s*:\s*\d+\s*(?:竖屏|横屏|纵向|横向|portrait|landscape|vertical|horizontal)?[,，、。；;\s]*/iu', '$1', $text) ?? $text;
        $text = preg_replace('/(?:竖屏|横屏|纵向画面|横向画面|portrait\s+canvas|landscape\s+canvas|vertical\s+screen|horizontal\s+screen)[,，、。；;\s]*/iu', '', $text) ?? $text;
        return self::trimPromptText($text);
    }

    private static function cleanPromptRatioText(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }
        $patterns = [
            '/(?:推荐)?(?:画面)?(?:比例|画幅|尺寸|构图比例|aspect\s*ratio|ratio)\s*[：:]?\s*\d+\s*:\s*\d+\s*(?:竖屏|横屏|纵向|横向|portrait|landscape|vertical|horizontal|构图|画面)?[,，、。；;\s]*/iu',
            '/\b\d+\s*:\s*\d+\s*(?:竖屏|横屏|纵向|横向|portrait|landscape|vertical|horizontal|构图|画面|画幅|比例)[,，、。；;\s]*/iu',
            '/(?:竖屏构图|横屏构图|竖屏画面|横屏画面|纵向画面|横向画面|portrait\s+canvas|landscape\s+canvas|vertical\s+screen|horizontal\s+screen)[,，、。；;\s]*/iu',
            '/^\s*\d+\s*:\s*\d+\s*$/mu',
        ];
        foreach ($patterns as $pattern) {
            $text = preg_replace($pattern, '', $text) ?? $text;
        }
        $text = preg_replace('/[,，、。；;]\s*([,，、。；;])+/u', '$1', $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;
        return self::trimPromptText($text);
    }

    private static function hasLatinPromptText(string $text): bool
    {
        return preg_match('/[A-Za-z]/', $text) === 1;
    }

    private static function localizeGenerationPromptText(string $text, string $fallback = ''): string
    {
        $text = trim($text);
        $fallback = trim($fallback);
        if ($fallback !== '' && self::hasLatinPromptText($fallback)) {
            $fallback = self::localizeGenerationPromptText($fallback);
        }
        if ($text === '') {
            return $fallback;
        }
        if (!self::hasLatinPromptText($text)) {
            return $text;
        }
        $map = [
            'close-up of a young woman\'s hand opening a window latch' => '年轻女性的手打开窗闩的近景特',
            'close-up of young woman\'s hand opening a window latch' => '年轻女性的手打开窗闩的近景特',
            'static wide shot of an empty old room' => '静态远景空镜，展示空置的旧房间',
            'morning sunlight streaming through window' => '清晨阳光透过窗户洒入室内',
            'illuminating an old wooden chair and a potted plant' => '照亮一把旧木椅和一盆绿',
            'dust particles dancing in light beams' => '尘埃微粒在光束中缓慢浮动',
            'white sheer curtain blowing gently inward by the wind' => '白色薄纱窗帘被风轻轻向内吹动',
            'soft morning light on skin' => '柔和清晨光照在皮肤上',
            'detailed texture of hand and fabric' => '手部和布料纹理细腻清',
            'warm beige and wood tones' => '暖米色和木质棕色',
            'cinematic lighting' => '电影感光',
            'high resolution' => '高分辨率',
            'film grain' => '胶片颗粒',
            'rule-of-thirds' => '三分法构',
            'rule of thirds' => '三分法构',
            'chair and plant as focal points' => '以木椅和绿植作为视觉焦点',
            'eye level' => '平视角度',
            'extreme close-up' => '极近特写',
            'medium close-up' => '中近',
            'close-up' => '近景特写',
            'close up' => '近景特写',
            'medium shot' => '中景',
            'wide shot' => '远景',
            'long shot' => '远景',
            'establishing shot' => '环境建立镜头',
            'two-shot' => '双人镜头',
            'two shot' => '双人镜头',
            'over-the-shoulder' => '过肩镜头',
            'over the shoulder' => '过肩镜头',
            'static shot' => '固定镜头',
            'static' => '固定镜头',
            'slow push-in' => '缓慢推进',
            'push in' => '向前推进',
            'pull back' => '向后拉开',
            'tracking shot' => '跟拍镜头',
            'tracking' => '跟拍',
            'handheld' => '手持镜头',
            'pan left' => '向左横摇',
            'pan right' => '向右横摇',
            'tilt up' => '上摇',
            'tilt down' => '下摇',
            'pan' => '横摇',
            'tilt' => '俯仰摇镜',
            'young woman' => '年轻女',
            'young man' => '年轻男',
            'woman' => '女',
            'man' => '男',
            'mother' => '母亲',
            'empty old room' => '空置的旧房间',
            'empty room' => '空房',
            'old room' => '旧房',
            'exteriorexterior' => '室外',
            'interiorinterior' => '室内',
            'exterior exterior' => '室外',
            'interior interior' => '室内',
            'exterior' => '室外',
            'interior' => '室内',
            'window latch' => '窗闩',
            'window' => '窗户',
            'sheer curtain' => '薄纱窗帘',
            'curtain' => '窗帘',
            'old wooden chair' => '旧木',
            'wooden chair' => '木椅',
            'potted plant' => '盆栽绿植',
            'plant' => '绿植',
            'sunlight' => '阳光',
            'morning light' => '清晨光线',
            'morning' => '清晨',
            'soft light' => '柔和光线',
            'warm light' => '暖光',
            'light beams' => '光束',
            'dust particles' => '尘埃微粒',
            'fabric' => '布料',
            'hand' => '手部',
            'skin' => '皮肤',
            'texture' => '纹理',
            'cinematic' => '电影',
            'natural light' => '自然',
            'soft diffused light' => '柔和漫射',
            'shallow depth of field' => '浅景',
            'clean composition' => '干净构图',
            'warm atmosphere' => '温暖氛围',
            'nostalgic atmosphere' => '怀旧氛',
            'quiet atmosphere' => '静谧氛围',
            'no people' => '不要出现人物',
            'no characters' => '不要出现角色',
            'no portraits' => '不要出现肖像',
            'no subtitles' => '不要字幕',
            'no text' => '不要文字',
            'no watermark' => '不要水印',
            'low quality' => '低质',
            'blurry' => '模糊',
            'flicker' => '闪烁',
            'face drift' => '脸部漂移',
            'changed outfit' => '服装变化',
            'changed scene' => '场景变化',
            'extra characters' => '多余角色',
            'different face' => '不同',
            'different outfit' => '不同服装',
            'age drift' => '年龄漂移',
            'extra limbs' => '多余肢体',
            'extra fingers' => '多余手指',
            'distorted face' => '脸部变形',
            'people' => '人物',
            'person' => '人物',
            'human' => '人类',
            'characters' => '角色',
            'character' => '角色',
            'portraits' => '肖像',
            'portrait' => '肖像',
            'silhouette' => '剪影',
            'watermark' => '水印',
            'text' => '文字',
            'logo' => '标志',
        ];
        uksort($map, static fn($left, $right): int => strlen($right) <=> strlen($left));
        $translated = $text;
        foreach ($map as $source => $target) {
            $pattern = '/' . str_replace('\\-', '[-–—]', preg_quote($source, '/')) . '/iu';
            $translated = preg_replace($pattern, $target, $translated) ?? $translated;
        }
        $translated = str_replace(['、', ', ', ',', '; ', ';', '. ', '.'], ['', '', '', '', '', '', ''], $translated);
        $translated = preg_replace('/[ \t]*([:：])[ \t]*/u', '$1', $translated) ?? $translated;
        $translated = preg_replace('/(室内)\1+/u', '$1', $translated) ?? $translated;
        $translated = preg_replace('/(室外)\1+/u', '$1', $translated) ?? $translated;
        $translated = preg_replace('/[ \t]+/u', '', $translated) ?? $translated;
        $translated = preg_replace('/\R{3,}/u', "\n\n", $translated) ?? $translated;
        $translated = self::trimPromptText($translated);
        if (self::hasLatinPromptText($translated)) {
            $cleaned = preg_replace("/[A-Za-z][A-Za-z0-9'_-]*/u", '', $translated) ?? $translated;
            $cleaned = preg_replace('/[ \t]+/u', '', $cleaned) ?? $cleaned;
            $cleaned = preg_replace('/\R{3,}/u', "\n\n", $cleaned) ?? $cleaned;
            $cleaned = self::trimPromptText($cleaned);
            if ($cleaned !== '' && preg_match('/[\x{4e00}-\x{9fff}]/u', $cleaned)) {
                return $cleaned;
            }
            return $fallback !== '' ? $fallback : '按当前分镜内容生成电影感画面，保持场景、光线、构图和氛围一致';
        }
        return $translated !== '' ? $translated : ($fallback !== '' ? $fallback : $text);
    }

    private static function normalizeLegacyStoryboardPromptData(array $shot, int $index = 0): array
    {
        $shot = self::localizeLegacyPromptFields($shot, [
            'scene_name', 'visual_description', 'composition', 'camera_movement', 'action',
            'result', 'atmosphere',
        ]);
        $imagePrompt = trim((string)($shot['image_prompt'] ?? ''));
        if ($imagePrompt !== '') {
            $shot['image_prompt'] = self::normalizeFinalProviderPrompt($imagePrompt);
        }
        $videoPrompt = trim((string)($shot['video_prompt'] ?? ''));
        $shot['video_prompt'] = self::normalizeFinalProviderPrompt(
            self::normalizeReadableShotVideoPrompt(
                self::localizeGenerationPromptText($videoPrompt, (string)($shot['visual_description'] ?? '')),
                $shot,
                $index
            )
        );
        return $shot;
    }

    private static function normalizeLegacyPromptPlanData(array $plan): array
    {
        foreach (['subjects', 'locations', 'scenes'] as $collection) {
            foreach ((array)($plan[$collection] ?? []) as $index => $item) {
                if (!is_array($item)) {
                    continue;
                }
                $plan[$collection][$index] = self::localizeLegacyPromptFields($item, [
                    'name', 'location', 'description', 'visual_prompt', 'main_image_prompt', 'three_view_prompt',
                    'scene_image_prompt', 'image_prompt', 'negative_prompt', 'main_negative_prompt',
                    'three_view_negative_prompt', 'scene_negative_prompt', 'appearance_lock', 'face_lock',
                    'hair_lock', 'outfit_lock', 'layout_lock', 'lighting_lock', 'color_palette',
                ]);
            }
        }
        foreach ((array)($plan['storyboard'] ?? []) as $index => $shot) {
            if (is_array($shot)) {
                $plan['storyboard'][$index] = self::normalizeLegacyStoryboardPromptData($shot, $index);
            }
        }
        if (is_array($plan['art_style'] ?? null)) {
            $plan['art_style'] = self::localizeLegacyPromptFields($plan['art_style'], [
                'visual_description', 'base_style', 'palette', 'lighting', 'texture',
            ]);
        }
        return $plan;
    }

    private static function localizeLegacyPromptFields(array $data, array $fields): array
    {
        foreach ($fields as $field) {
            $value = trim((string)($data[$field] ?? ''));
            if ($value !== '') {
                $data[$field] = self::localizeGenerationPromptText($value);
            }
        }
        return $data;
    }

    private static function legacyStoryboardPromptChanges(array $current, array $next): array
    {
        $changes = [];
        foreach ([
            'scene_name', 'visual_description', 'composition', 'camera_movement', 'action',
            'result', 'atmosphere', 'image_prompt', 'video_prompt',
        ] as $field) {
            $before = (string)($current[$field] ?? '');
            $after = (string)($next[$field] ?? '');
            if ($before !== $after) {
                $changes[$field] = $after;
            }
        }
        return $changes;
    }

    private static function localizeGenerationTaskPayload(array $payload, bool $preserveProviderPrompt = false): array
    {
        $preserveProviderPrompt = $preserveProviderPrompt
            || isset($payload['image_task_id'])
            || isset($payload['video_task_id'])
            || isset($payload['image_result_id'])
            || isset($payload['video_result_id']);
        $promptKeys = [
            'prompt' => true,
            'image_prompt' => true,
            'video_prompt' => true,
            'visual_prompt' => true,
            'subject_prompt' => true,
            'scene_prompt' => true,
            'negative_prompt' => true,
            'music_prompt' => true,
            'prompt_summary' => true,
        ];
        $fallback = trim((string)($payload['visual_description'] ?? $payload['description'] ?? $payload['action'] ?? ''));
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = self::localizeGenerationTaskPayload($value, $preserveProviderPrompt || in_array((string)$key, ['image_params', 'video_params'], true));
                continue;
            }
            if (is_string($value) && isset($promptKeys[(string)$key])) {
                $payload[$key] = $preserveProviderPrompt
                    ? self::normalizeFinalProviderPrompt($value)
                    : self::localizeGenerationPromptText($value, $fallback);
                if (!$preserveProviderPrompt && (string)$key === 'video_prompt') {
                    $payload[$key] = self::normalizeReadableShotVideoPrompt((string)$payload[$key], $payload);
                }
            }
        }
        return $payload;
    }

    private static function normalizeMusicPlan(array $musicPlan, array $storyboard, array $durationStats = [], array $artStyle = [], string $prompt = ''): array
    {
        $shotPrompts = array_values(array_filter(array_map(static fn($shot): string => trim((string)($shot['bgm_prompt'] ?? '')), $storyboard)));
        $duration = (float)($musicPlan['duration_seconds'] ?? $durationStats['estimated_total_seconds'] ?? 0);
        $duration = max(15, min(600, $duration > 0 ? $duration : 60));
        $style = trim((string)($musicPlan['style'] ?? $artStyle['base_style'] ?? '短剧配乐'));
        $moodCurve = trim((string)($musicPlan['mood_curve'] ?? '开场铺垫悬念，中段逐步紧张，结尾释放情绪'));
        $bpm = trim((string)($musicPlan['bpm'] ?? '80-96 BPM'));
        $instruments = self::stringList($musicPlan['instruments'] ?? []);
        if (empty($instruments)) {
            $instruments = ['钢琴', '低频弦乐', '轻打击乐', '氛围合成'];
        }
        $globalPrompt = trim((string)($musicPlan['global_bgm_prompt'] ?? $musicPlan['prompt'] ?? ''));
        if ($globalPrompt === '') {
            $globalPrompt = self::joinPromptParts([
                '生成一条适用于整部短剧的纯背景音乐，不要歌词，不要人声，不要对白采样',
                '音乐风格：' . $style,
                '情绪曲线：' . $moodCurve,
                '节奏速度：' . $bpm,
                '主要乐器：' . implode('、', $instruments),
                '时长目标：约' . (int)$duration . '秒，可循环但不要明显断点',
                !empty($shotPrompts) ? '分镜情绪参考：' . implode('', array_slice($shotPrompts, 0, 8)) : '',
                $prompt !== '' ? '故事氛围参考：' . mb_substr($prompt, 0, 180, 'UTF-8') : '',
                '负面要求：不要歌词，不要人声，不要版权旋律，不要突兀鼓点，不要压过对白',
            ]);
        }

        return [
            'music_title' => mb_substr(trim((string)($musicPlan['music_title'] ?? '短剧背景音乐')), 0, 80, 'UTF-8'),
            'global_bgm_prompt' => mb_substr($globalPrompt, 0, 2000, 'UTF-8'),
            'style' => mb_substr($style, 0, 120, 'UTF-8'),
            'mood_curve' => mb_substr($moodCurve, 0, 300, 'UTF-8'),
            'bpm' => mb_substr($bpm, 0, 40, 'UTF-8'),
            'instruments' => array_slice($instruments, 0, 12),
            'duration_seconds' => $duration,
            'negative_prompt' => mb_substr(trim((string)($musicPlan['negative_prompt'] ?? '不要歌词，不要人声，不要版权旋律，不要突兀鼓点，不要压过对白')), 0, 500, 'UTF-8'),
        ];
    }

    private static function normalizeGeneratedStoryboard(array $items): array
    {
        $result = [];
        $elapsedSeconds = 0;
        foreach (array_values($items) as $index => $item) {
            if (!is_array($item)) {
                continue;
            }
            $visualDescription = trim((string)($item['visual_description'] ?? ''));
            if ($visualDescription === '') {
                $visualDescription = self::joinPromptParts([
                    (string)($item['action'] ?? ''),
                    (string)($item['result'] ?? ''),
                    (string)($item['image_prompt'] ?? ''),
                    (string)($item['title'] ?? ''),
                    (string)($item['scene_name'] ?? ''),
                ]);
            }
            if ($visualDescription === '') {
                continue;
            }
            $frameType = (string)($item['frame_type'] ?? 'normal');
            $interiorExterior = (string)($item['interior_exterior'] ?? 'exterior');
            $subjectRefs = (array)($item['subject_ref_ids'] ?? $item['subject_refs'] ?? $item['character_ids'] ?? []);
            $bgmPrompt = trim((string)($item['bgm_prompt'] ?? ''));
            if ($bgmPrompt === '') {
                $bgmPrompt = '承接全片纯音乐氛围，突出本镜头的情绪变化' . mb_substr($visualDescription, 0, 120, 'UTF-8');
            }
            $soundEffect = trim((string)($item['sound_effect'] ?? ''));
            if ($soundEffect === '') {
                $soundEffect = '根据画面动作加入轻微环境声和动作音效，保持自然不过度';
            }
            $durationSeconds = max(2, min(5, (float)($item['recommended_duration_seconds'] ?? 3)));
            $shot = [
                'shot_id' => trim((string)($item['shot_id'] ?? '')) ?: (string)($index + 1),
                'act' => trim((string)($item['act'] ?? '')) ?: '',
                'scene_order' => max(1, (int)($item['scene_order'] ?? $item['story_order'] ?? 1)),
                'title' => mb_substr(trim((string)($item['title'] ?? '')), 0, 120, 'UTF-8'),
                'scene_name' => mb_substr(trim((string)($item['scene_name'] ?? '')), 0, 100, 'UTF-8'),
                'time_of_day' => mb_substr(trim((string)($item['time_of_day'] ?? '')), 0, 40, 'UTF-8'),
                'interior_exterior' => in_array($interiorExterior, ['interior', 'exterior'], true) ? $interiorExterior : 'exterior',
                'visual_description' => mb_substr($visualDescription, 0, 2000, 'UTF-8'),
                'composition' => mb_substr(trim((string)($item['composition'] ?? '')) ?: '主体与场景层次清晰，保持视觉重心稳定', 0, 500, 'UTF-8'),
                'camera_movement' => mb_substr(trim((string)($item['camera_movement'] ?? '')) ?: '固定镜头，主体动作自然变化', 0, 500, 'UTF-8'),
                'shot_type' => mb_substr(trim((string)($item['shot_type'] ?? '')), 0, 80, 'UTF-8'),
                'angle' => mb_substr(trim((string)($item['angle'] ?? '')), 0, 80, 'UTF-8'),
                'action' => mb_substr(trim((string)($item['action'] ?? '')), 0, 1000, 'UTF-8'),
                'result' => mb_substr(trim((string)($item['result'] ?? '')), 0, 500, 'UTF-8'),
                'atmosphere' => mb_substr(trim((string)($item['atmosphere'] ?? '')), 0, 300, 'UTF-8'),
                'image_prompt' => self::localizeGenerationPromptText(trim((string)($item['image_prompt'] ?? '')), $visualDescription),
                'bgm_prompt' => mb_substr($bgmPrompt, 0, 500, 'UTF-8'),
                'sound_effect' => mb_substr($soundEffect, 0, 500, 'UTF-8'),
                'scene_ref_id' => mb_substr(trim((string)($item['scene_ref_id'] ?? $item['scene_ref'] ?? $item['location_id'] ?? '')), 0, 80, 'UTF-8'),
                'subject_ref_ids' => array_values(array_filter(array_map('strval', $subjectRefs))),
                'voice_role' => mb_substr(trim((string)($item['voice_role'] ?? '')), 0, 100, 'UTF-8'),
                'dialogue' => mb_substr(trim((string)($item['dialogue'] ?? '')), 0, 1000, 'UTF-8'),
                'frame_type' => in_array($frameType, ['normal', 'lip_sync'], true) ? $frameType : 'normal',
                'recommended_duration_seconds' => $durationSeconds,
            ];
            $shot['video_prompt'] = self::normalizeReadableShotVideoPrompt(
                self::localizeGenerationPromptText(trim((string)($item['video_prompt'] ?? '')), $visualDescription),
                $shot,
                $index,
                ['start_seconds' => $elapsedSeconds]
            );
            $result[] = $shot;
            $elapsedSeconds += (int)round($durationSeconds);
        }
        return $result;
    }

    private static function stringList($items): array
    {
        if (is_string($items)) {
            $items = preg_split('/\r?\n/u', $items) ?: [];
        }
        $result = [];
        foreach ((array)$items as $item) {
            $text = trim((string)$item);
            if ($text !== '') {
                $result[] = $text;
            }
        }
        return array_values($result);
    }

    private static function durationStats(array $storyboard, int $sceneCount): array
    {
        $total = 0;
        foreach ($storyboard as $shot) {
            $total += (float)($shot['recommended_duration_seconds'] ?? 0);
        }
        return [
            'scene_count' => $sceneCount,
            'shot_count' => count($storyboard),
            'estimated_total_seconds' => $total,
            'average_shot_duration_seconds' => round($total / max(1, count($storyboard)), 2),
        ];
    }

    private static function storyboardActTitle(array $location, array $shot = []): string
    {
        $sceneOrder = max(1, (int)($location['story_order'] ?? $shot['scene_order'] ?? 1));
        $sceneName = trim((string)($location['name'] ?? $shot['scene_name'] ?? '场景'));
        $timeOfDay = trim((string)($shot['time_of_day'] ?? $location['time_light'] ?? ''));
        $interiorExterior = (string)($shot['interior_exterior'] ?? '');
        if (!in_array($interiorExterior, ['interior', 'exterior'], true)) {
            $interiorExterior = self::inferInteriorExterior($sceneName . ' ' . (string)($location['description'] ?? ''));
        }
        $space = $interiorExterior === 'interior' ? '室内' : '室外';
        $suffix = trim($sceneName . ' ' . $timeOfDay . ' ' . $space);
        return '' . $sceneOrder . '幕：' . ($suffix !== '' ? $suffix : $sceneName);
    }

    private static function storyboardDedupeKey(array $shot): string
    {
        $text = self::joinPromptParts([
            (string)($shot['visual_description'] ?? ''),
            (string)($shot['composition'] ?? ''),
            (string)($shot['camera_movement'] ?? ''),
        ]);
        $text = mb_strtolower($text, 'UTF-8');
        $text = preg_replace('/[\s,，。；;!！?？（）()《》“”‘’\-—_]+/u', '', $text) ?? $text;
        return mb_substr($text, 0, 180, 'UTF-8');
    }

    private static function isManualStoryboardShotId(string $shotId): bool
    {
        return $shotId !== '' && strpos($shotId, 'manual_') === 0;
    }

    private static function isGenericStoryboardVisual(string $text): bool
    {
        $text = trim($text);
        if ($text === '') {
            return true;
        }
        foreach ([
            '主要角色出现',
            '主要角色出现',
            '人物状',
            '人物状态与场景压力形成对照',
            '情绪开始推',
            '建立空间关系',
            '画面突出场景的主要视觉元',
            '承接剧情',
            '画面承接剧情',
            '镜头聚焦',
            '里的关键细节或道',
            '揭示本场景的压力',
            '凝视场景中的异常细节',
            '角色完成一个单一动作',
            '把剧情从',
            '推向下一',
            '镜头结果明确',
            '完成该时间段内的画面任务',
            '推动剧情',
            '视觉任务',
            '本镜',
        ] as $keyword) {
            if (mb_stripos($text, $keyword, 0, 'UTF-8') !== false) {
                return true;
            }
        }
        return false;
    }

    private static function isHighConfidenceGenericStoryboardVisual(string $text): bool
    {
        $text = trim($text);
        if ($text === '') {
            return true;
        }
        foreach ([
            '主要角色出现',
            '主要角色出现',
            '人物状',
            '人物状态与场景压力形成对照',
            '情绪开始推',
            '建立空间关系',
            '画面突出场景的主要视觉元',
            '承接剧情',
            '画面承接剧情',
            '镜头聚焦',
            '里的关键细节或道',
            '揭示本场景的压力',
            '凝视场景中的异常细节',
            '角色完成一个单一动作',
            '把剧情从',
            '推向下一',
            '镜头结果明确',
            '完成该时间段内的画面任务',
            '推动剧情',
            '视觉任务',
            '本镜',
        ] as $keyword) {
            if (mb_stripos($text, $keyword, 0, 'UTF-8') !== false) {
                return true;
            }
        }
        return self::isGenericStoryboardVisual($text);
    }

    private static function cleanStoredStoryboardForVersionRow(int $tenantId, int $userId, int $projectId, array $versionRow): void
    {
        $taskId = trim((string)($versionRow['task_id'] ?? ''));
        if ($taskId === '') {
            return;
        }
        $transStarted = false;
        try {
            $task = AigcShortDramaScriptTask::where([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'project_id' => $projectId,
                'task_id' => $taskId,
                'delete_time' => 0,
            ])->findOrEmpty();
            if (!$task->isEmpty()) {
                self::cleanStoredStoryboardForTaskData($task->toArray());
                $versionRow = AigcShortDramaPlanVersion::where([
                    'tenant_id' => $tenantId,
                    'user_id' => $userId,
                    'project_id' => $projectId,
                    'task_id' => $taskId,
                    'delete_time' => 0,
                ])->order(['version_no' => 'desc', 'id' => 'desc'])->findOrEmpty()->toArray();
            }
            $plan = self::jsonDecode((string)($versionRow['plan_json'] ?? ''));
            $cleanup = self::cleanStoryboardResultData($tenantId, $userId, $projectId, $taskId, $plan);
            if (empty($cleanup['changed'])) {
                return;
            }
            $cleanResult = (array)$cleanup['result'];
            $removedShotIds = array_values(array_unique(array_filter(array_map('strval', (array)($cleanup['removed_shot_ids'] ?? [])))));
            $time = time();
            Db::startTrans();
            $transStarted = true;
            AigcShortDramaPlanVersion::where([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'project_id' => $projectId,
                'task_id' => $taskId,
                'delete_time' => 0,
            ])->update([
                'plan_json' => self::jsonEncode($cleanResult),
                'storyboard_json' => self::jsonEncode((array)($cleanResult['storyboard'] ?? [])),
                'story_bible_json' => self::jsonEncode(self::storyBibleFromResult($cleanResult)),
                'continuity_json' => self::jsonEncode(self::continuityFromResult($cleanResult)),
                'update_time' => $time,
            ]);
            if (!empty($removedShotIds)) {
                AigcShortDramaStoryboard::where([
                    'tenant_id' => $tenantId,
                    'user_id' => $userId,
                    'project_id' => $projectId,
                    'task_id' => $taskId,
                    'delete_time' => 0,
                ])->whereIn('shot_id', $removedShotIds)->update([
                    'delete_time' => $time,
                    'update_time' => $time,
                ]);
            }
            Db::commit();
            $transStarted = false;
        } catch (\Throwable $e) {
            if ($transStarted) {
                Db::rollback();
            }
            Log::write('AI short drama storyboard cleanup skipped for version: ' . $e->getMessage(), 'error');
        }
    }

    private static function cleanStoredStoryboardForTaskData(array $taskData): array
    {
        $tenantId = (int)($taskData['tenant_id'] ?? 0);
        $userId = (int)($taskData['user_id'] ?? 0);
        $projectId = (int)($taskData['project_id'] ?? 0);
        $taskId = (string)($taskData['task_id'] ?? '');
        if ($tenantId <= 0 || $userId <= 0 || $projectId <= 0 || $taskId === '') {
            return $taskData;
        }
        $result = self::jsonDecode((string)($taskData['result_json'] ?? ''));
        $cleanup = self::cleanStoryboardResultData($tenantId, $userId, $projectId, $taskId, $result);
        if (empty($cleanup['changed'])) {
            return $taskData;
        }

        $cleanResult = (array)$cleanup['result'];
        $removedShotIds = array_values(array_unique(array_filter(array_map('strval', (array)($cleanup['removed_shot_ids'] ?? [])))));
        $keptShots = array_values(array_filter((array)($cleanResult['storyboard'] ?? []), 'is_array'));
        $time = time();

        Db::startTrans();
        try {
            AigcShortDramaScriptTask::where([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'project_id' => $projectId,
                'task_id' => $taskId,
                'delete_time' => 0,
            ])->update([
                'result_json' => self::jsonEncode($cleanResult),
                'update_time' => $time,
            ]);
            AigcShortDramaPlanVersion::where([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'project_id' => $projectId,
                'task_id' => $taskId,
                'delete_time' => 0,
            ])->update([
                'plan_json' => self::jsonEncode($cleanResult),
                'storyboard_json' => self::jsonEncode((array)($cleanResult['storyboard'] ?? [])),
                'story_bible_json' => self::jsonEncode(self::storyBibleFromResult($cleanResult)),
                'continuity_json' => self::jsonEncode(self::continuityFromResult($cleanResult)),
                'update_time' => $time,
            ]);
            if (!empty($removedShotIds)) {
                AigcShortDramaStoryboard::where([
                    'tenant_id' => $tenantId,
                    'user_id' => $userId,
                    'project_id' => $projectId,
                    'task_id' => $taskId,
                    'delete_time' => 0,
                ])->whereIn('shot_id', $removedShotIds)->update([
                    'delete_time' => $time,
                    'update_time' => $time,
                ]);
            }
            foreach ($keptShots as $index => $shot) {
                $shotId = (string)($shot['shot_id'] ?? $shot['id'] ?? '');
                if ($shotId === '') {
                    continue;
                }
                AigcShortDramaStoryboard::where([
                    'tenant_id' => $tenantId,
                    'user_id' => $userId,
                    'project_id' => $projectId,
                    'task_id' => $taskId,
                    'shot_id' => $shotId,
                    'delete_time' => 0,
                ])->update(self::filterStoryboardWritableData([
                    'act' => (string)($shot['act'] ?? ''),
                    'scene_name' => (string)($shot['scene_name'] ?? ''),
                    'time_of_day' => (string)($shot['time_of_day'] ?? ''),
                    'interior_exterior' => in_array(($shot['interior_exterior'] ?? ''), ['interior', 'exterior'], true) ? $shot['interior_exterior'] : 'exterior',
                    'sort' => $index + 1,
                    'update_time' => $time,
                ]));
            }
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            Log::write('AI short drama storyboard cleanup failed: task=' . $taskId . ' error=' . $e->getMessage(), 'error');
            return $taskData;
        }

        $taskData['result_json'] = self::jsonEncode($cleanResult);
        $taskData['update_time'] = $time;
        Log::write('AI short drama storyboard cleaned: task=' . $taskId . ' removed=' . count($removedShotIds) . ' kept=' . count($keptShots));
        return $taskData;
    }

    private static function cleanStoryboardResultData(int $tenantId, int $userId, int $projectId, string $taskId, array $result): array
    {
        $storyboard = array_values(array_filter((array)($result['storyboard'] ?? []), 'is_array'));
        if (empty($storyboard)) {
            return ['result' => $result, 'changed' => false, 'removed_shot_ids' => []];
        }

        $locations = array_values(array_filter((array)($result['locations'] ?? $result['scenes'] ?? []), 'is_array'));
        $locationById = [];
        $locationByName = [];
        foreach ($locations as $index => $location) {
            $id = (string)($location['id'] ?? ('location_' . ($index + 1)));
            $locationById[$id] = $location;
            $name = trim((string)($location['name'] ?? ''));
            if ($name !== '') {
                $locationByName[$name] = $id;
            }
        }

        $rowsByShot = [];
        if ($tenantId > 0 && $userId > 0 && $projectId > 0 && $taskId !== '') {
            $rows = AigcShortDramaStoryboard::where([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'project_id' => $projectId,
                'task_id' => $taskId,
                'delete_time' => 0,
            ])->select()->toArray();
            foreach ($rows as $row) {
                $shotId = (string)($row['shot_id'] ?? '');
                if ($shotId !== '') {
                    $rowsByShot[$shotId] = $row;
                }
            }
        }

        $changed = false;
        $removedShotIds = [];
        $cleaned = [];
        $seenByScene = [];
        foreach ($storyboard as $index => $shot) {
            $shotId = (string)($shot['shot_id'] ?? $shot['id'] ?? '');
            $isManualShot = self::isManualStoryboardShotId($shotId);
            if (!$isManualShot && self::isHighConfidenceGenericStoryboardVisual((string)($shot['visual_description'] ?? ''))) {
                if ($shotId !== '') {
                    $removedShotIds[] = $shotId;
                }
                $changed = true;
                continue;
            }

            $originalShot = $shot;
            $sceneRef = trim((string)($shot['scene_ref_id'] ?? ''));
            $sceneName = trim((string)($shot['scene_name'] ?? ''));
            if (($sceneRef === '' || !isset($locationById[$sceneRef])) && $sceneName !== '') {
                $sceneRef = (string)($locationByName[$sceneName] ?? '');
            }
            if (($sceneRef === '' || !isset($locationById[$sceneRef])) && !empty($locations)) {
                $sceneOrder = max(1, (int)($shot['scene_order'] ?? 1));
                $location = $locations[min(count($locations) - 1, $sceneOrder - 1)] ?? $locations[0];
                $sceneRef = (string)($location['id'] ?? '');
            }
            $location = $sceneRef !== '' && isset($locationById[$sceneRef]) ? $locationById[$sceneRef] : [];
            if (!empty($location)) {
                $shot['scene_ref_id'] = $sceneRef;
                $shot['scene_name'] = (string)($location['name'] ?? $sceneName);
                $shot['scene_order'] = max(1, (int)($location['story_order'] ?? $shot['scene_order'] ?? ($index + 1)));
                if (trim((string)($shot['time_of_day'] ?? '')) === '' && trim((string)($location['time_light'] ?? '')) !== '') {
                    $shot['time_of_day'] = (string)$location['time_light'];
                }
                if (!in_array(($shot['interior_exterior'] ?? ''), ['interior', 'exterior'], true)) {
                    $shot['interior_exterior'] = self::inferInteriorExterior((string)($location['name'] ?? '') . ' ' . (string)($location['description'] ?? ''));
                }
                $shot['act'] = self::storyboardActTitle($location, $shot);
            }

            if ($shot !== $originalShot) {
                $changed = true;
            }

            $sceneKey = (string)($shot['scene_ref_id'] ?? $shot['scene_name'] ?? 'scene_' . $index);
            $dedupeKey = self::storyboardDedupeKey($shot);
            $canDedupeShot = $dedupeKey !== '' && !$isManualShot;
            if ($canDedupeShot && isset($seenByScene[$sceneKey][$dedupeKey])) {
                $existingIndex = (int)$seenByScene[$sceneKey][$dedupeKey];
                $existingShotId = (string)($cleaned[$existingIndex]['shot_id'] ?? '');
                if (self::storyboardStoredAssetScore($shotId, $rowsByShot) > self::storyboardStoredAssetScore($existingShotId, $rowsByShot)) {
                    if ($existingShotId !== '') {
                        $removedShotIds[] = $existingShotId;
                    }
                    $cleaned[$existingIndex] = $shot;
                } elseif ($shotId !== '') {
                    $removedShotIds[] = $shotId;
                }
                $changed = true;
                continue;
            }
            if ($canDedupeShot) {
                $seenByScene[$sceneKey][$dedupeKey] = count($cleaned);
            }
            $cleaned[] = $shot;
        }

        if (!$changed) {
            return ['result' => $result, 'changed' => false, 'removed_shot_ids' => []];
        }

        $result['storyboard'] = array_values($cleaned);
        $result['duration_stats'] = self::durationStats($result['storyboard'], count($locations));
        $result['music_plan'] = self::normalizeMusicPlan(
            (array)($result['music_plan'] ?? []),
            $result['storyboard'],
            (array)$result['duration_stats'],
            (array)($result['art_style'] ?? []),
            (string)($result['story_outline'] ?? '')
        );
        return [
            'result' => $result,
            'changed' => true,
            'removed_shot_ids' => array_values(array_unique(array_filter($removedShotIds))),
        ];
    }

    private static function storyboardStoredAssetScore(string $shotId, array $rowsByShot): int
    {
        if ($shotId === '' || !isset($rowsByShot[$shotId])) {
            return 0;
        }
        $row = (array)$rowsByShot[$shotId];
        return ((int)($row['selected_video_asset_id'] ?? 0) > 0 ? 2 : 0)
            + ((int)($row['selected_image_asset_id'] ?? 0) > 0 ? 1 : 0);
    }

    private static function sceneVisualDetails(array $location): array
    {
        $description = trim((string)($location['description'] ?? ''));
        $visualPrompt = trim((string)($location['visual_prompt'] ?? ''));
        $text = self::joinPromptParts([$description, $visualPrompt]);
        $parts = preg_split('/[。；;\n\r]+/u', $text) ?: [];
        $details = [];
        foreach ($parts as $part) {
            $part = self::trimPromptText($part);
            if ($part !== '') {
                $details[] = $part;
            }
        }
        if (empty($details)) {
            $details[] = trim((string)($location['name'] ?? '场景')) . '的空间、光线和主要视觉元素';
        }
        return array_values(array_unique($details));
    }

    private static function subjectVisualLabelList(array $subjects, int $limit = 3): string
    {
        $names = [];
        foreach (array_slice($subjects, 0, $limit) as $subject) {
            $name = trim((string)($subject['name'] ?? ''));
            if ($name !== '') {
                $names[] = $name;
            }
        }
        return empty($names) ? '主要角色' : implode('', $names);
    }

    private static function repairStoryboardCoverage(array $storyboard, array $locations, array $subjects, string $prompt, array $request, string $storyOutline = ''): array
    {
        if (empty($locations)) {
            return ['storyboard' => $storyboard, 'issues_fixed' => []];
        }

        $locations = array_values($locations);
        $locationById = [];
        $locationByName = [];
        foreach ($locations as $index => $location) {
            $id = (string)($location['id'] ?? ('location_' . ($index + 1)));
            $locationById[$id] = $location;
            $locationByName[(string)($location['name'] ?? '')] = $id;
        }

        $grouped = [];
        foreach ($locations as $location) {
            $grouped[(string)$location['id']] = [];
        }

        foreach (array_values($storyboard) as $shot) {
            if (!is_array($shot)) {
                continue;
            }
            $sceneRef = trim((string)($shot['scene_ref_id'] ?? ''));
            $sceneName = trim((string)($shot['scene_name'] ?? ''));
            if ($sceneRef === '' || !isset($locationById[$sceneRef])) {
                $sceneRef = (string)($locationByName[$sceneName] ?? '');
            }
            if ($sceneRef === '' || !isset($locationById[$sceneRef])) {
                $sceneOrder = max(1, (int)($shot['scene_order'] ?? 1));
                $sceneRef = (string)($locations[min(count($locations) - 1, $sceneOrder - 1)]['id'] ?? $locations[0]['id']);
            }
            $location = $locationById[$sceneRef] ?? $locations[0];
            $shot['scene_ref_id'] = $sceneRef;
            $shot['scene_name'] = trim((string)($shot['scene_name'] ?? '')) ?: (string)($location['name'] ?? '');
            $shot['scene_order'] = max(1, (int)($location['story_order'] ?? 1));
            if (self::isGenericStoryboardVisual((string)($shot['visual_description'] ?? ''))) {
                continue;
            }
            $grouped[$sceneRef][] = $shot;
        }

        $timelineSegments = self::extractTimelineSegments($prompt);
        if (!empty($timelineSegments) && self::selectedDurationHint($request) === '') {
            return self::repairStoryboardByTimeline($storyboard, $locations, $subjects, $timelineSegments, $storyOutline);
        }

        $issuesFixed = [];
        $appended = 0;
        $removedDuplicates = 0;
        $final = [];
        $targetMinimumPerScene = self::planningTargetDurationSeconds($prompt, $request) > 0 ? 3 : 2;
        $targetTotalShots = self::minimumStoryboardShotCount($prompt, $request, count($locations));
        $sceneShotCounts = [];

        foreach ($locations as $index => $location) {
            $sceneRef = (string)($location['id'] ?? ('location_' . ($index + 1)));
            $shots = array_values((array)($grouped[$sceneRef] ?? []));
            $sceneTimeOfDay = '';
            foreach ($shots as $candidateShot) {
                $candidateTime = trim((string)($candidateShot['time_of_day'] ?? ''));
                if ($candidateTime !== '') {
                    $sceneTimeOfDay = $candidateTime;
                    break;
                }
            }
            $sceneInteriorExterior = '';
            foreach ($shots as $candidateShot) {
                $candidateSpace = (string)($candidateShot['interior_exterior'] ?? '');
                if (in_array($candidateSpace, ['interior', 'exterior'], true)) {
                    $sceneInteriorExterior = $candidateSpace;
                    break;
                }
            }
            if (!in_array($sceneInteriorExterior, ['interior', 'exterior'], true)) {
                $sceneInteriorExterior = self::inferInteriorExterior((string)($location['name'] ?? '') . ' ' . (string)($location['description'] ?? ''));
            }
            $seenKeys = [];
            $deduped = [];
            foreach ($shots as $shot) {
                $shot['scene_ref_id'] = $sceneRef;
                $shot['scene_name'] = (string)($location['name'] ?? $shot['scene_name'] ?? '');
                $shot['scene_order'] = max(1, (int)($location['story_order'] ?? ($index + 1)));
                $shot['time_of_day'] = $sceneTimeOfDay !== '' ? $sceneTimeOfDay : (string)($shot['time_of_day'] ?? '');
                $shot['interior_exterior'] = $sceneInteriorExterior;
                $shot['act'] = self::storyboardActTitle($location, $shot);
                $key = self::storyboardDedupeKey($shot);
                if ($key !== '' && isset($seenKeys[$key])) {
                    $removedDuplicates++;
                    continue;
                }
                $seenKeys[$key] = true;
                $deduped[] = $shot;
            }
            $shots = $deduped;
            $targetForScene = max($targetMinimumPerScene, count($shots));
            while (count($shots) < $targetForScene) {
                $shots[] = self::supplementalStoryboardShot($location, $subjects, count($shots) + 1, count($final) + count($shots) + 1, $storyOutline);
                $appended++;
            }
            foreach ($shots as $shot) {
                $final[] = $shot;
            }
            $sceneShotCounts[$sceneRef] = count($shots);
        }

        $locationCount = count($locations);
        $appendCursor = 0;
        while (count($final) < $targetTotalShots && $locationCount > 0) {
            $location = (array)$locations[$appendCursor % $locationCount];
            $sceneRef = (string)($location['id'] ?? ('location_' . (($appendCursor % $locationCount) + 1)));
            $sceneShotCounts[$sceneRef] = (int)($sceneShotCounts[$sceneRef] ?? 0) + 1;
            $final[] = self::supplementalStoryboardShot($location, $subjects, $sceneShotCounts[$sceneRef], count($final) + 1, $storyOutline);
            $appended++;
            $appendCursor++;
        }

        foreach ($final as $index => &$shot) {
            $shot['shot_id'] = (string)($index + 1);
            $shot['title'] = trim((string)($shot['title'] ?? '')) ?: ('分镜' . ($index + 1));
        }
        unset($shot);

        if ($appended > 0) {
            $issuesFixed[] = '模型返回的分镜未覆盖全部场景或数量不足，已按场景顺序补齐 ' . $appended . ' 个镜头';
        }
        if ($removedDuplicates > 0) {
            $issuesFixed[] = '已合并同一场景内重复或高度相似的分镜 ' . $removedDuplicates . ' 个';
        }

        return [
            'storyboard' => $final,
            'issues_fixed' => array_values(array_unique($issuesFixed)),
        ];
    }

    private static function repairStoryboardByTimeline(array $storyboard, array $locations, array $subjects, array $timelineSegments, string $storyOutline = ''): array
    {
        $sourceShots = array_values(array_filter($storyboard, 'is_array'));
        $sourceIndex = 0;
        $final = [];
        $issuesFixed = [];

        foreach (array_values($timelineSegments) as $segmentIndex => $segment) {
            $durationParts = self::splitTimelineDuration((int)($segment['duration_seconds'] ?? 0));
            foreach ($durationParts as $partIndex => $duration) {
                $source = is_array($sourceShots[$sourceIndex] ?? null) ? $sourceShots[$sourceIndex] : [];
                $sourceIndex++;
                $shot = self::timelineStoryboardShot(
                    $source,
                    $locations,
                    $subjects,
                    $segment,
                    $segmentIndex,
                    $partIndex,
                    count($final) + 1,
                    (float)$duration,
                    $storyOutline
                );
                $final[] = $shot;
            }
        }

        $targetSeconds = self::timelineTotalSeconds($timelineSegments);
        $actualSeconds = array_reduce($final, static fn(float $total, array $shot): float => $total + (float)($shot['recommended_duration_seconds'] ?? 0), 0.0);
        if (!empty($final) && abs($actualSeconds - $targetSeconds) > 0.001) {
            $delta = $targetSeconds - $actualSeconds;
            $lastIndex = count($final) - 1;
            $final[$lastIndex]['recommended_duration_seconds'] = max(0.1, (float)$final[$lastIndex]['recommended_duration_seconds'] + $delta);
        }

        if (count($storyboard) !== count($final) || abs($actualSeconds - $targetSeconds) > 0.001) {
            $issuesFixed[] = '检测到明确时间码，已按用户时间轴重新对齐分镜，总时长锁定为 ' . $targetSeconds . ' 秒';
        }

        return [
            'storyboard' => $final,
            'issues_fixed' => $issuesFixed,
        ];
    }

    private static function shortDramaResolutionLabel(string $value): string
    {
        $value = strtoupper(trim($value));
        if (preg_match('/(?:^|[^A-Z0-9])(2160P|1440P|1080P|720P|480P|[1-9][0-9]*K)(?:$|[^A-Z0-9])/', $value, $matches)) {
            return match ($matches[1]) {
                '2160P' => '4K',
                '1440P' => '2K',
                default => $matches[1],
            };
        }
        return '';
    }

    private static function balanceStoryboardDuration(array $storyboard, array $locations, array $subjects, string $prompt, array $request, string $storyOutline = ''): array
    {
        $storyboard = array_values(array_filter($storyboard, 'is_array'));
        $targetSeconds = self::planningTargetDurationSeconds($prompt, $request);
        if (empty($storyboard) || $targetSeconds <= 0) {
            return ['storyboard' => $storyboard, 'issues_fixed' => []];
        }

        $minSeconds = (int)ceil($targetSeconds * 0.9);
        $maxSeconds = (int)floor($targetSeconds * 1.1);
        $currentSeconds = self::storyboardDurationSeconds($storyboard);
        $issuesFixed = [];

        if ($currentSeconds < $minSeconds) {
            $needed = $minSeconds - $currentSeconds;
            $indices = self::durationExpansionOrder($storyboard);
            foreach ($indices as $index) {
                if ($needed <= 0) {
                    break;
                }
                $duration = (float)($storyboard[$index]['recommended_duration_seconds'] ?? 3);
                $room = max(0, 5 - $duration);
                if ($room <= 0) {
                    continue;
                }
                $add = min($room, $needed);
                $storyboard[$index]['recommended_duration_seconds'] = $duration + $add;
                $needed -= $add;
            }

            $locationCount = count($locations);
            $appendCursor = 0;
            while ($needed > 0 && $locationCount > 0) {
                $location = (array)$locations[$appendCursor % $locationCount];
                $duration = min(5, max(2, $needed));
                $storyboard[] = self::supplementalStoryboardShot(
                    $location,
                    $subjects,
                    self::nextSceneShotIndex($storyboard, (string)($location['id'] ?? '')),
                    count($storyboard) + 1,
                    $storyOutline
                );
                $lastIndex = count($storyboard) - 1;
                $storyboard[$lastIndex]['recommended_duration_seconds'] = (float)$duration;
                $needed -= $duration;
                $appendCursor++;
            }
            $issuesFixed[] = '已按目标时长区间动态调整分镜时长，避免成片时长与用户要求偏差过大';
        } elseif ($currentSeconds > $maxSeconds) {
            $excess = $currentSeconds - $maxSeconds;
            $indices = array_reverse(self::durationExpansionOrder($storyboard));
            foreach ($indices as $index) {
                if ($excess <= 0) {
                    break;
                }
                $duration = (float)($storyboard[$index]['recommended_duration_seconds'] ?? 3);
                $room = max(0, $duration - 2);
                if ($room <= 0) {
                    continue;
                }
                $cut = min($room, $excess);
                $storyboard[$index]['recommended_duration_seconds'] = $duration - $cut;
                $excess -= $cut;
            }
            $issuesFixed[] = '已按目标时长区间动态压缩分镜时长，避免成片时长过长';
        }

        foreach ($storyboard as $index => &$shot) {
            $shot['shot_id'] = (string)($index + 1);
            $shot['title'] = trim((string)($shot['title'] ?? '')) ?: ('分镜' . ($index + 1));
        }
        unset($shot);

        return [
            'storyboard' => $storyboard,
            'issues_fixed' => $issuesFixed,
        ];
    }

    private static function storyboardDurationSeconds(array $storyboard): float
    {
        $total = 0.0;
        foreach ($storyboard as $shot) {
            if (is_array($shot)) {
                $total += (float)($shot['recommended_duration_seconds'] ?? 0);
            }
        }
        return $total;
    }

    private static function durationExpansionOrder(array $storyboard): array
    {
        $weighted = [];
        foreach ($storyboard as $index => $shot) {
            if (!is_array($shot)) {
                continue;
            }
            $text = implode(' ', [
                (string)($shot['shot_type'] ?? ''),
                (string)($shot['visual_description'] ?? ''),
                (string)($shot['action'] ?? ''),
                (string)($shot['result'] ?? ''),
            ]);
            $score = 0;
            if (self::shotMatchesKind($shot, 'establishing')) {
                $score += 4;
            }
            if (self::shotMatchesKind($shot, 'reaction') || self::shotMatchesKind($shot, 'closeup')) {
                $score += 3;
            }
            if (str_contains($text, '转场') || str_contains($text, '揭示') || str_contains($text, '签约') || str_contains($text, '定格')) {
                $score += 2;
            }
            $weighted[] = ['index' => $index, 'score' => $score];
        }
        usort($weighted, static fn(array $a, array $b): int => ($b['score'] <=> $a['score']) ?: ($a['index'] <=> $b['index']));
        return array_map(static fn(array $item): int => (int)$item['index'], $weighted);
    }

    private static function nextSceneShotIndex(array $storyboard, string $sceneRef): int
    {
        if ($sceneRef === '') {
            return count($storyboard) + 1;
        }
        $count = 0;
        foreach ($storyboard as $shot) {
            if (is_array($shot) && (string)($shot['scene_ref_id'] ?? '') === $sceneRef) {
                $count++;
            }
        }
        return $count + 1;
    }

    private static function splitTimelineDuration(int $duration): array
    {
        $duration = max(1, $duration);
        if ($duration <= 5) {
            return [$duration];
        }
        $parts = [];
        $remaining = $duration;
        while ($remaining > 5) {
            $next = ($remaining - 5) === 1 ? 4 : 5;
            $parts[] = $next;
            $remaining -= $next;
        }
        if ($remaining > 0) {
            $parts[] = $remaining;
        }
        return $parts;
    }

    private static function timelineStoryboardShot(array $source, array $locations, array $subjects, array $segment, int $segmentIndex, int $partIndex, int $globalIndex, float $duration, string $storyOutline = ''): array
    {
        $segmentText = trim((string)($segment['text'] ?? ''));
        $timeRange = (string)($segment['time_range'] ?? '');
        $location = self::timelineLocationForSegment($segmentText, $source, $locations, $segmentIndex);
        $sceneName = trim((string)($source['scene_name'] ?? '')) ?: (string)($location['name'] ?? '场景');
        $sceneOrder = max(1, (int)($location['story_order'] ?? ($segmentIndex + 1)));
        $sceneRef = (string)($location['id'] ?? ('location_' . $sceneOrder));
        $subjectRefs = self::resolveShotSubjectRefs($source + [
            'visual_description' => $segmentText,
        ], $subjects);
        if (empty($subjectRefs)) {
            $subjectRefs = self::resolveShotSubjectRefs(['visual_description' => $segmentText], $subjects);
        }
        $visual = trim((string)($source['visual_description'] ?? ''));
        if ($visual === '' || self::isGenericStoryboardVisual($visual)) {
            $visual = self::timelineSegmentVisualText($segmentText, $storyOutline);
        }
        if ($partIndex > 0) {
            $visual .= '（延续该时间段的动作与情绪，' . ($partIndex + 1) . '个镜头。）';
        }
        $title = trim((string)($source['title'] ?? '')) ?: ('分镜' . $globalIndex . ' ' . $timeRange);

        return [
            'shot_id' => (string)$globalIndex,
            'act' => self::storyboardActTitle($location, [
                'scene_order' => $sceneOrder,
                'scene_name' => $sceneName,
                'time_of_day' => trim((string)($source['time_of_day'] ?? self::timelineTimeOfDay($segmentText))),
                'interior_exterior' => in_array(($source['interior_exterior'] ?? ''), ['interior', 'exterior'], true)
                    ? $source['interior_exterior']
                    : self::inferInteriorExterior($sceneName . ' ' . $segmentText),
            ]),
            'scene_order' => $sceneOrder,
            'title' => mb_substr($title, 0, 120, 'UTF-8'),
            'scene_name' => $sceneName,
            'time_of_day' => trim((string)($source['time_of_day'] ?? self::timelineTimeOfDay($segmentText))),
            'interior_exterior' => in_array(($source['interior_exterior'] ?? ''), ['interior', 'exterior'], true)
                ? $source['interior_exterior']
                : self::inferInteriorExterior($sceneName . ' ' . $segmentText),
            'visual_description' => mb_substr($visual, 0, 2000, 'UTF-8'),
            'composition' => mb_substr(trim((string)($source['composition'] ?? self::timelineComposition($segmentText))), 0, 500, 'UTF-8'),
            'camera_movement' => mb_substr(trim((string)($source['camera_movement'] ?? self::timelineCameraMovement($segmentText))), 0, 500, 'UTF-8'),
            'shot_type' => mb_substr(trim((string)($source['shot_type'] ?? self::timelineShotType($segmentText))), 0, 80, 'UTF-8'),
            'angle' => mb_substr(trim((string)($source['angle'] ?? '平视')), 0, 80, 'UTF-8'),
            'action' => mb_substr(trim((string)($source['action'] ?? $visual)), 0, 1000, 'UTF-8'),
            'result' => mb_substr(trim((string)($source['result'] ?? '画面停留在该时间段的清晰动作结果或空间状态上')), 0, 500, 'UTF-8'),
            'atmosphere' => mb_substr(trim((string)($source['atmosphere'] ?? '温柔、自然、克')), 0, 300, 'UTF-8'),
            'image_prompt' => self::localizeGenerationPromptText(trim((string)($source['image_prompt'] ?? ($visual . '，保持主体和场景统一，无文字水印'))), $visual),
            'video_prompt' => self::localizeGenerationPromptText(trim((string)($source['video_prompt'] ?? ('生成' . $duration . '秒视频：起始状态为' . $visual . '；中间保持一个核心动作自然变化，环境有轻微运动；结束状态对齐时间码' . $timeRange . '的画面结果，保持场景、光线和美术风格一致，无字幕、无BGM背景音乐、水印、文字'))), $visual),
            'bgm_prompt' => mb_substr(trim((string)($source['bgm_prompt'] ?? '承接全片音乐氛围，保持该时间段的情绪温度')), 0, 500, 'UTF-8'),
            'sound_effect' => mb_substr(trim((string)($source['sound_effect'] ?? '保留自然环境声和轻微动作音效')), 0, 500, 'UTF-8'),
            'scene_ref_id' => $sceneRef,
            'subject_ref_ids' => $subjectRefs,
            'voice_role' => mb_substr(trim((string)($source['voice_role'] ?? '')), 0, 100, 'UTF-8'),
            'dialogue' => mb_substr(trim((string)($source['dialogue'] ?? self::timelineDialogue($segmentText))), 0, 1000, 'UTF-8'),
            'frame_type' => in_array(($source['frame_type'] ?? 'normal'), ['normal', 'lip_sync'], true) ? (string)($source['frame_type'] ?? 'normal') : 'normal',
            'recommended_duration_seconds' => $duration,
        ];
    }

    private static function timelineLocationForSegment(string $segmentText, array $source, array $locations, int $segmentIndex): array
    {
        $sceneRef = (string)($source['scene_ref_id'] ?? $source['scene_ref'] ?? $source['location_id'] ?? '');
        $sceneName = (string)($source['scene_name'] ?? '');
        $resolved = self::resolveShotSceneRef([
            'scene_ref_id' => $sceneRef,
            'scene_name' => $sceneName !== '' ? $sceneName : $segmentText,
            'visual_description' => $segmentText,
        ], $locations);
        $location = self::planItemByExactId($locations, $resolved);
        if (!empty($location)) {
            return $location;
        }
        foreach ($locations as $location) {
            $name = trim((string)($location['name'] ?? ''));
            if ($name !== '' && str_contains($segmentText, $name)) {
                return $location;
            }
        }
        return (array)($locations[min(max(0, count($locations) - 1), $segmentIndex)] ?? $locations[0] ?? []);
    }

    private static function timelineSegmentVisualText(string $segmentText, string $storyOutline = ''): string
    {
        $text = trim(preg_replace('/【([^】]+)】/u', '$1', $segmentText) ?? $segmentText);
        $text = preg_replace('/字幕\s*[：:]/u', '字幕', $text) ?? $text;
        if ($text === '') {
            $text = $storyOutline !== '' ? mb_substr($storyOutline, 0, 160, 'UTF-8') : '按照用户时间码呈现该段剧情画面';
        }
        return $text;
    }

    private static function timelineTimeOfDay(string $segmentText): string
    {
        foreach (['清晨', '早晨', '上午', '中午', '午后', '下午', '傍晚', '黄昏', '夜晚', '深夜'] as $keyword) {
            if (str_contains($segmentText, $keyword)) {
                return $keyword;
            }
        }
        return '';
    }

    private static function timelineComposition(string $segmentText): string
    {
        if (str_contains($segmentText, '特写') || str_contains($segmentText, '细节')) {
            return '特写构图，突出手部、表情或关键细节';
        }
        if (str_contains($segmentText, '中景')) {
            return '中景构图，人物与环境关系清晰';
        }
        if (str_contains($segmentText, '对坐')) {
            return '对称或双人构图，突出两人关系';
        }
        return '自然生活化构图，画面干净柔和';
    }

    private static function timelineCameraMovement(string $segmentText): string
    {
        if (str_contains($segmentText, '移动镜头')) {
            return '缓慢移动镜头，顺着动作自然过渡';
        }
        return '固定镜头或轻微推镜头，保持生活感和克制节奏';
    }

    private static function timelineShotType(string $segmentText): string
    {
        if (str_contains($segmentText, '字幕')) {
            return '字幕画面';
        }
        if (str_contains($segmentText, '特写') || str_contains($segmentText, '细节')) {
            return '道具特写';
        }
        if (str_contains($segmentText, '移动镜头')) {
            return '动作镜头';
        }
        return '普通画面';
    }

    private static function timelineDialogue(string $segmentText): string
    {
        if (!str_contains($segmentText, '字幕')) {
            return '';
        }
        if (preg_match_all("/[\"“”‘’']([^\"“”‘’']+)[\"“”‘’']/u", $segmentText, $matches)) {
            return implode("\n", (array)$matches[1]);
        }
        return '';
    }

    private static function supplementalStoryboardShot(array $location, array $subjects, int $sceneShotIndex, int $globalIndex, string $storyOutline = ''): array
    {
        $sceneName = (string)($location['name'] ?? '场景');
        $sceneDescription = (string)($location['description'] ?? '');
        $sceneOrder = max(1, (int)($location['story_order'] ?? 1));
        $sceneRef = (string)($location['id'] ?? ('location_' . $sceneOrder));
        $subjectIds = array_values(array_filter(array_map(static fn($subject): string => (string)($subject['id'] ?? ''), array_slice($subjects, 0, 3))));
        $details = self::sceneVisualDetails($location);
        $primaryDetail = $details[0] ?? ($sceneName . '的空间关');
        $secondaryDetail = $details[1] ?? $primaryDetail;
        $thirdDetail = $details[2] ?? $secondaryDetail;
        $subjectLabel = self::subjectVisualLabelList($subjects);
        $slot = (($sceneShotIndex - 1) % 5) + 1;

        $templates = [
            1 => [
                'title' => $sceneName . '空间建立',
                'visual' => $sceneName . '的远景空镜：' . $primaryDetail . '。光线落在空间主要物件和动线上，环境层次清楚',
                'composition' => '远景或大全景，稳定构图，先交代空间层次和主体位置',
                'camera' => '固定镜头或缓慢推镜头，节奏克制',
                'type' => '空镜',
                'duration' => 4,
            ],
            2 => [
                'title' => $sceneName . '人物状',
                'visual' => $subjectLabel . '位于' . $sceneName . '的中景画面，人物站位贴近' . $secondaryDetail . '，眼神或身体方向指向本场景的关键动线',
                'composition' => '中景，三分法构图，角色与环境同时可见',
                'camera' => '轻微跟拍或缓慢横移，保持角色动作清晰',
                'type' => '普通画面',
                'duration' => 3,
            ],
            3 => [
                'title' => $sceneName . '关键信息',
                'visual' => '近景聚焦' . $sceneName . '中的关键细节' . $thirdDetail . '。背景保留少量空间轮廓，让观众能判断细节所在位置',
                'composition' => '近景或道具特写，视觉焦点集中，背景适度虚化',
                'camera' => '缓慢推镜头，强化信息揭示',
                'type' => '道具特写',
                'duration' => 3,
            ],
            4 => [
                'title' => $sceneName . '情绪反应',
                'visual' => $subjectLabel . '' . $sceneName . '短暂停下，视线落' . $primaryDetail . '附近的细节，表情、手部姿态和身体重心清楚可见',
                'composition' => '人物特写或半身近景，眼神和面部反应作为画面中心',
                'camera' => '固定镜头，轻微推近，保留情绪停顿',
                'type' => '情绪镜头',
                'duration' => 4,
            ],
            5 => [
                'title' => $sceneName . '动作推进',
                'visual' => $subjectLabel . '沿着' . $sceneName . '的空间动线移动或伸手触碰关键物件，动作从准备到完成清晰可见，画面停在动作结果上',
                'composition' => '中近景，动作方向清晰，画面留出转场空间',
                'camera' => '跟拍或拉镜头，动作结束后自然转场',
                'type' => '动作镜头',
                'duration' => 3,
            ],
        ];
        $template = $templates[$slot];
        $visual = mb_substr($template['visual'], 0, 2000, 'UTF-8');
        $templateSubjectRefs = $slot === 1 ? [] : $subjectIds;
        $templateNoSubjectShot = self::isNoSubjectShot([
            'shot_type' => $template['type'],
            'visual_description' => $visual,
            'action' => $visual,
            'composition' => $template['composition'],
            'subject_ref_ids' => $templateSubjectRefs,
        ]);
        if ($templateNoSubjectShot) {
            $templateSubjectRefs = [];
        }

        return [
            'shot_id' => (string)$globalIndex,
            'act' => self::storyboardActTitle($location, [
                'scene_order' => $sceneOrder,
                'scene_name' => $sceneName,
                'interior_exterior' => self::inferInteriorExterior($sceneName . ' ' . $sceneDescription),
            ]),
            'scene_order' => $sceneOrder,
            'title' => $template['title'],
            'scene_name' => $sceneName,
            'time_of_day' => '',
            'interior_exterior' => self::inferInteriorExterior($sceneName . ' ' . $sceneDescription),
            'visual_description' => $visual,
            'composition' => $template['composition'],
            'camera_movement' => $template['camera'],
            'shot_type' => $template['type'],
            'angle' => '平视',
            'action' => $visual,
            'result' => '画面停留在清晰可见的动作、表情或关键细节上',
            'atmosphere' => mb_substr((string)($location['story_phase'] ?? '') . ' ' . $sceneDescription, 0, 300, 'UTF-8'),
            'image_prompt' => $visual . ($templateNoSubjectShot ? '，保持场景布局、光线和美术风格一致，画面清晰，无文字水印' : '，保持本剧主体和场景统一，画面清晰，无文字水印'),
            'video_prompt' => $templateNoSubjectShot
                ? '生成' . (int)$template['duration'] . '秒视频：起始状态为' . $visual . '；中间只表现环境光影或空间元素的轻微运动，镜头按' . $template['camera'] . '自然变化；结束状态停留在清晰稳定的场景画面，保持场景、光线和美术风格一致，无人物、无字幕、无BGM背景音乐、水印、文字'
                : '生成' . (int)$template['duration'] . '秒视频：起始状态为' . $visual . '；中间只表现一个核心动作和表情变化，环境有轻微运动，镜头按' . $template['camera'] . '自然变化；结束状态停留在清晰可见的动作结果上，保持人物身份、服装、道具、场景、光线和美术风格一致，无新增角色，无字幕、无BGM背景音乐、水印、文字',
            'bgm_prompt' => '承接全片背景音乐，在' . $sceneName . '中突出本镜头的情绪变化，纯音乐，无歌词无人声',
            'sound_effect' => '加入' . $sceneName . '匹配的轻微环境声和动作音效，保持自然不过度',
            'scene_ref_id' => $sceneRef,
            'subject_ref_ids' => $templateSubjectRefs,
            'voice_role' => '',
            'dialogue' => '',
            'frame_type' => 'normal',
            'recommended_duration_seconds' => (float)$template['duration'],
        ];
    }

    private static function inferInteriorExterior(string $text): string
    {
        foreach (['室内', '房间', '教室', '餐厅', '家中', '理发', '咨询', '实验', '办公', '卧室', '走廊'] as $keyword) {
            if (mb_stripos($text, $keyword, 0, 'UTF-8') !== false) {
                return 'interior';
            }
        }
        return 'exterior';
    }

    private static function scriptPlanBillingFromLlm(array $generation, string $prompt, array $result, array $model): array
    {
        $llm = (array)($generation['llm'] ?? []);
        $usage = (array)($llm['usage'] ?? []);
        $billing = (array)($llm['billing'] ?? []);
        $usageBilling = (array)($usage['billing'] ?? []);
        $repairLlm = (array)($generation['repair_llm'] ?? []);
        $repairUsage = (array)($repairLlm['usage'] ?? []);
        $repairBilling = (array)($repairLlm['billing'] ?? []);
        $repairUsageBilling = (array)($repairUsage['billing'] ?? []);
        $tenantCost = self::formatBillingPoints((float)($billing['tenant_cost_points'] ?? 0) + (float)($repairBilling['tenant_cost_points'] ?? 0));
        $userCharge = self::formatBillingPoints((float)($billing['user_charge_points'] ?? 0) + (float)($repairBilling['user_charge_points'] ?? 0));
        $promptTokens = (int)($usage['prompt_tokens'] ?? MarketTextModelRuntimeService::estimateTokens($prompt));
        $completionTokens = (int)($usage['completion_tokens'] ?? MarketTextModelRuntimeService::estimateTokens(self::jsonEncode($result)));
        $repairPromptTokens = (int)($repairUsage['prompt_tokens'] ?? 0);
        $repairCompletionTokens = (int)($repairUsage['completion_tokens'] ?? 0);

        return [
            'source_app_code' => self::LLM_APP_CODE,
            'model_code' => (string)($llm['model_code'] ?? $model['model_code'] ?? $model['code'] ?? ''),
            'channel_code' => (string)($llm['channel_code'] ?? $model['channel_code'] ?? ''),
            'provider_model' => (string)($model['provider_model'] ?? $model['model'] ?? ''),
            'billing_unit' => (string)($billing['billing_unit'] ?? 'tokens_1m'),
            'prompt_tokens' => $promptTokens + $repairPromptTokens,
            'completion_tokens' => $completionTokens + $repairCompletionTokens,
            'tenant_cost_points' => $tenantCost,
            'user_charge_points' => $userCharge,
            'billing_status' => (string)($repairBilling['billing_status'] ?? $billing['billing_status'] ?? (((float)$tenantCost > 0 || (float)$userCharge > 0) ? 'deducted' : 'none')),
            'provider_request_id' => (string)($repairUsageBilling['provider_request_id'] ?? $repairLlm['provider_request_id'] ?? $usageBilling['provider_request_id'] ?? $llm['provider_request_id'] ?? ''),
            'price' => (array)($usageBilling['price'] ?? []),
            'repair' => empty($repairLlm) ? [] : [
                'used' => true,
                'prompt_tokens' => $repairPromptTokens,
                'completion_tokens' => $repairCompletionTokens,
                'tenant_cost_points' => self::formatBillingPoints((float)($repairBilling['tenant_cost_points'] ?? 0)),
                'user_charge_points' => self::formatBillingPoints((float)($repairBilling['user_charge_points'] ?? 0)),
                'provider_request_id' => (string)($repairUsageBilling['provider_request_id'] ?? $repairLlm['provider_request_id'] ?? ''),
            ],
        ];
    }

    private static function replaceStoryboard(int $tenantId, int $userId, int $projectId, string $taskId, array $storyboard): void
    {
        AigcShortDramaStoryboard::where([
            'tenant_id' => $tenantId,
            'task_id' => $taskId,
        ])->update(['delete_time' => time(), 'update_time' => time()]);
        $time = time();
        foreach (array_values($storyboard) as $index => $shot) {
            AigcShortDramaStoryboard::create(self::filterStoryboardWritableData(array_merge(self::editableShotData($shot), [
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'project_id' => $projectId,
                'task_id' => $taskId,
                'shot_id' => (string)($shot['shot_id'] ?? ($index + 1)),
                'act' => (string)($shot['act'] ?? ''),
                'scene_name' => (string)($shot['scene_name'] ?? ''),
                'time_of_day' => (string)($shot['time_of_day'] ?? ''),
                'interior_exterior' => in_array(($shot['interior_exterior'] ?? 'exterior'), ['interior', 'exterior'], true) ? $shot['interior_exterior'] : 'exterior',
                'sort' => $index + 1,
                'create_time' => $time,
                'update_time' => $time,
                'delete_time' => 0,
            ])));
        }
    }

    private static function activeStoryboardRows(int $tenantId, int $userId, int $projectId, string $taskId): array
    {
        return AigcShortDramaStoryboard::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'project_id' => $projectId,
            'task_id' => $taskId,
            'delete_time' => 0,
        ])->order(['sort' => 'asc', 'id' => 'asc'])->select()->toArray();
    }

    private static function makeStoryboardShotId(int $tenantId, string $taskId): string
    {
        for ($i = 0; $i < 10; $i++) {
            $shotId = 'manual_' . date('ymdHis') . random_int(1000, 9999);
            $exists = AigcShortDramaStoryboard::where([
                'tenant_id' => $tenantId,
                'task_id' => $taskId,
                'shot_id' => $shotId,
            ])->count() > 0;
            if (!$exists) {
                return $shotId;
            }
        }
        return 'manual_' . substr(md5($taskId . microtime(true) . random_int(1000, 9999)), 0, 24);
    }

    private static function latestStoryboardAssetId(int $tenantId, int $userId, int $projectId, string $shotId, string $assetType): int
    {
        if ($shotId === '' || !in_array($assetType, ['shot_image', 'shot_video'], true)) {
            return 0;
        }
        $asset = AigcShortDramaAsset::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'project_id' => $projectId,
            'shot_id' => $shotId,
            'asset_type' => $assetType,
            'status' => 'ready',
            'delete_time' => 0,
        ])->order(['id' => 'desc'])->findOrEmpty();
        return $asset->isEmpty() ? 0 : (int)$asset['id'];
    }

    private static function resolveCopiedStoryboardAssetId(int $tenantId, int $userId, int $projectId, string $sourceShotId, string $assetType, int $requestedAssetId = 0, int $selectedAssetId = 0): int
    {
        foreach ([$requestedAssetId, $selectedAssetId] as $assetId) {
            if ($assetId <= 0) {
                continue;
            }
            $exists = AigcShortDramaAsset::where([
                'id' => $assetId,
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'project_id' => $projectId,
                'asset_type' => $assetType,
                'status' => 'ready',
                'delete_time' => 0,
            ])->count() > 0;
            if ($exists) {
                return $assetId;
            }
        }
        return self::latestStoryboardAssetId($tenantId, $userId, $projectId, $sourceShotId, $assetType);
    }

    private static function reorderStoryboardShots(int $tenantId, int $userId, int $projectId, string $taskId, array $shotIds): void
    {
        $time = time();
        foreach (array_values(array_filter(array_map('strval', $shotIds))) as $index => $shotId) {
            AigcShortDramaStoryboard::where([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'project_id' => $projectId,
                'task_id' => $taskId,
                'shot_id' => $shotId,
                'delete_time' => 0,
            ])->update([
                'sort' => $index + 1,
                'update_time' => $time,
            ]);
        }
    }

    private static function syncStoryboardStructureAndResponse(int $tenantId, int $userId, AigcShortDramaScriptTask $task, string $activeShotId): array
    {
        $projectId = (int)$task['project_id'];
        $taskId = (string)$task['task_id'];
        $shots = array_map([self::class, 'formatShot'], self::activeStoryboardRows($tenantId, $userId, $projectId, $taskId));
        $result = self::jsonDecode((string)$task['result_json']);
        if (!is_array($result)) {
            $result = [];
        }
        $result['storyboard'] = $shots;
        $time = time();
        $task->save([
            'result_json' => self::jsonEncode($result),
            'update_time' => $time,
        ]);

        $freshTask = self::findTask($tenantId, $userId, $taskId, $projectId);
        self::createUserEditVersionFromTask($tenantId, $userId, $freshTask, $shots);

        $withSelection = self::applyStoryboardSelectionState($tenantId, $userId, $projectId, $taskId, ['storyboard' => $shots]);
        $shots = array_values((array)($withSelection['storyboard'] ?? $shots));
        $activeShot = [];
        foreach ($shots as $shot) {
            if ((string)($shot['shot_id'] ?? '') === $activeShotId) {
                $activeShot = $shot;
                break;
            }
        }
        if (empty($activeShot) && !empty($shots)) {
            $activeShot = $shots[0];
            $activeShotId = (string)($activeShot['shot_id'] ?? '');
        }
        self::touchProject(self::findProject($tenantId, $userId, $projectId), ['update_time' => $time]);
        return [
            'shot' => $activeShot,
            'shots' => $shots,
            'active_shot_id' => $activeShotId,
        ];
    }

    private static function syncTaskResultStoryboard(AigcShortDramaScriptTask $task, array $updated, bool $replace = false): void
    {
        $result = self::jsonDecode((string)$task['result_json']);
        if (!isset($result['storyboard']) || !is_array($result['storyboard'])) {
            return;
        }
        if ($replace) {
            $result['storyboard'] = array_values($updated);
        } else {
            foreach ($result['storyboard'] as &$shot) {
                foreach ($updated as $updatedShot) {
                    if ((string)($shot['shot_id'] ?? '') === (string)($updatedShot['shot_id'] ?? '')) {
                        $shot = array_merge($shot, $updatedShot);
                    }
                }
            }
            unset($shot);
        }
        $result = self::reviewAndRepairPlanResult(self::enhancePlanResult($result));
        $task->save([
            'result_json' => self::jsonEncode($result),
            'update_time' => time(),
        ]);
    }

    private static function findProject(int $tenantId, int $userId, int $projectId): AigcShortDramaProject
    {
        $project = AigcShortDramaProject::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'id' => $projectId,
            'delete_time' => 0,
        ])->findOrEmpty();
        if ($project->isEmpty()) {
            throw new Exception('项目不存在');
        }
        return $project;
    }

    private static function findTask(int $tenantId, int $userId, string $taskId, int $projectId = 0): AigcShortDramaScriptTask
    {
        $query = AigcShortDramaScriptTask::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'task_id' => $taskId,
            'delete_time' => 0,
        ]);
        if ($projectId > 0) {
            $query->where('project_id', $projectId);
        }
        $task = $query->findOrEmpty();
        if ($task->isEmpty()) {
            throw new Exception('任务不存在');
        }
        return $task;
    }

    private static function formatTask(array $task, bool $withResult): array
    {
        if ($withResult && (string)($task['status'] ?? '') === self::STATUS_SUCCESS) {
            $task = self::cleanStoredStoryboardForTaskData($task);
        }
        $request = self::jsonDecode((string)($task['request_json'] ?? ''));
        $status = (string)($task['status'] ?? '');
        $storedResult = self::jsonDecode((string)($task['result_json'] ?? ''));
        $streamContent = (string)($storedResult['__stream_content'] ?? '');
        $streamUpdatedAt = (int)($storedResult['__stream_updated_at'] ?? 0);
        $result = $withResult && ($task['status'] ?? '') === self::STATUS_SUCCESS ? $storedResult : null;
        $project = AigcShortDramaProject::where([
            'tenant_id' => (int)$task['tenant_id'],
            'user_id' => (int)$task['user_id'],
            'id' => (int)$task['project_id'],
            'delete_time' => 0,
        ])->field('title,ratio,generation_settings_json')->findOrEmpty();
        $projectTitle = $project->isEmpty() ? '' : (string)($project['title'] ?? '');
        $projectRatio = $project->isEmpty() ? '' : (string)($project['ratio'] ?? '');
        $projectGenerationSettings = $project->isEmpty() ? [] : self::projectGenerationSettingsFromRow($project->toArray());
        if (empty($projectGenerationSettings['image'] ?? []) && !empty($request)) {
            $requestSelections = is_array($request['model_selections'] ?? null) ? (array)$request['model_selections'] : [];
            $projectGenerationSettings = self::mergeProjectGenerationSettings(
                $projectGenerationSettings,
                self::projectGenerationSettingsFromRequest($projectRatio !== '' ? $projectRatio : (string)($request['ratio'] ?? ''), $request, $requestSelections)
            );
        }
        if ($result && empty($result['storyboard'])) {
            $result['storyboard'] = self::storyboardRows((int)$task['tenant_id'], (string)$task['task_id']);
        }
        if ($result) {
            $result = self::enhancePlanResult($result);
            $result = self::applyStoryboardSelectionState(
                (int)$task['tenant_id'],
                (int)$task['user_id'],
                (int)$task['project_id'],
                (string)$task['task_id'],
                $result
            );
            if ($projectTitle !== '') {
                $result['title'] = $projectTitle;
            }
        }
        $streamContent = self::sanitizeUtf8String($streamContent);
        $response = [
            'project_id' => (int)$task['project_id'],
            'project_title' => $projectTitle,
            'project_ratio' => $projectRatio,
            'generation_settings' => $projectGenerationSettings,
            'project_generation_settings' => $projectGenerationSettings,
            'task_id' => (string)$task['task_id'],
            'status' => self::publicTaskStatus((string)$task['status']),
            'progress' => (int)$task['progress'],
            'current_step' => (string)$task['current_step'],
            'steps' => self::taskSteps((string)$task['status']),
            'workflow_steps' => self::workflowSteps((string)$task['status'], (array)($result['review_report'] ?? [])),
            'prompt' => (string)($task['prompt'] ?? ''),
            'ratio' => $projectRatio !== '' ? $projectRatio : (string)($request['ratio'] ?? ''),
            'result' => $result,
            'stream_content' => $streamContent,
            'stream_length' => mb_strlen($streamContent, 'UTF-8'),
            'stream_updated_at' => $streamUpdatedAt,
            'error' => in_array($status, [self::STATUS_FAILED, self::STATUS_CANCELED], true)
                ? self::scriptPlanProviderError((string)($task['error'] ?? ''))
                : '',
        ];
        return self::sanitizeUtf8Payload($response);
    }

    private static function taskSteps(string $status): array
    {
        $agents = self::logicalAgentDefinitions();
        if ($status === self::STATUS_SUCCESS) {
            return array_map(static fn(array $agent): array => $agent + ['status' => 'success'], $agents);
        }
        if ($status === self::STATUS_FAILED) {
            return array_map(static function (array $agent, int $index): array {
                return $agent + ['status' => $index === 0 ? 'failed' : 'pending'];
            }, $agents, array_keys($agents));
        }
        return array_map(static function (array $agent, int $index): array {
            return $agent + ['status' => $index === 0 ? 'running' : 'pending'];
        }, $agents, array_keys($agents));
    }

    private static function storyboardRows(int $tenantId, string $taskId): array
    {
        $rows = AigcShortDramaStoryboard::where([
            'tenant_id' => $tenantId,
            'task_id' => $taskId,
            'delete_time' => 0,
        ])->order(['sort' => 'asc', 'id' => 'asc'])->select()->toArray();
        return array_map([self::class, 'formatShot'], $rows);
    }

    private static function editableShotData(array $payload): array
    {
        $frameType = (string)($payload['frame_type'] ?? 'normal');
        return [
            'title' => mb_substr(trim((string)($payload['title'] ?? '')), 0, 120, 'UTF-8'),
            'visual_description' => mb_substr(trim((string)($payload['visual_description'] ?? '')), 0, 2000, 'UTF-8'),
            'composition' => mb_substr(trim((string)($payload['composition'] ?? '')), 0, 500, 'UTF-8'),
            'camera_movement' => mb_substr(trim((string)($payload['camera_movement'] ?? '')), 0, 500, 'UTF-8'),
            'shot_type' => mb_substr(trim((string)($payload['shot_type'] ?? '')), 0, 80, 'UTF-8'),
            'angle' => mb_substr(trim((string)($payload['angle'] ?? '')), 0, 80, 'UTF-8'),
            'action' => mb_substr(trim((string)($payload['action'] ?? '')), 0, 1000, 'UTF-8'),
            'result' => mb_substr(trim((string)($payload['result'] ?? '')), 0, 500, 'UTF-8'),
            'atmosphere' => mb_substr(trim((string)($payload['atmosphere'] ?? '')), 0, 300, 'UTF-8'),
            'image_prompt' => self::localizeGenerationPromptText(trim((string)($payload['image_prompt'] ?? '')), trim((string)($payload['visual_description'] ?? ''))),
            'video_prompt' => self::localizeGenerationPromptText(trim((string)($payload['video_prompt'] ?? '')), trim((string)($payload['visual_description'] ?? ''))),
            'bgm_prompt' => mb_substr(trim((string)($payload['bgm_prompt'] ?? '')), 0, 500, 'UTF-8'),
            'sound_effect' => mb_substr(trim((string)($payload['sound_effect'] ?? '')), 0, 500, 'UTF-8'),
            'scene_ref_id' => mb_substr(trim((string)($payload['scene_ref_id'] ?? $payload['scene_ref'] ?? $payload['location_id'] ?? '')), 0, 80, 'UTF-8'),
            'subject_ref_ids' => self::jsonEncode(array_values(array_filter(array_map('strval', (array)($payload['subject_ref_ids'] ?? $payload['subject_refs'] ?? $payload['character_ids'] ?? []))))),
            'voice_role' => mb_substr(trim((string)($payload['voice_role'] ?? '')), 0, 100, 'UTF-8'),
            'dialogue' => mb_substr(trim((string)($payload['dialogue'] ?? '')), 0, 1000, 'UTF-8'),
            'frame_type' => in_array($frameType, ['normal', 'lip_sync'], true) ? $frameType : 'normal',
            'recommended_duration_seconds' => min(5, max(2, (float)($payload['recommended_duration_seconds'] ?? 3))),
        ];
    }

    private static function filterStoryboardWritableData(array $data): array
    {
        $columns = self::storyboardWritableColumns();
        if (empty($columns)) {
            return self::sanitizeStoryboardWritableData($data);
        }
        return self::sanitizeStoryboardWritableData(array_intersect_key($data, $columns));
    }

    private static function sanitizeStoryboardWritableData(array $data): array
    {
        $limits = [
            'task_id' => 64,
            'shot_id' => 40,
            'act' => 160,
            'title' => 120,
            'scene_name' => 100,
            'time_of_day' => 40,
            'interior_exterior' => 20,
            'visual_description' => 2000,
            'composition' => 500,
            'camera_movement' => 500,
            'shot_type' => 80,
            'angle' => 80,
            'action' => 1000,
            'result' => 500,
            'atmosphere' => 300,
            'image_prompt' => 2000,
            'video_prompt' => 3000,
            'bgm_prompt' => 500,
            'sound_effect' => 500,
            'scene_ref_id' => 80,
            'subject_ref_ids' => 1000,
            'voice_role' => 100,
            'dialogue' => 1000,
            'frame_type' => 20,
        ];
        foreach ($data as $key => $value) {
            if (!is_string($value)) {
                continue;
            }
            $value = trim(self::sanitizeUtf8String($value));
            $limit = (int)($limits[$key] ?? 0);
            if ($limit > 0) {
                $value = mb_substr($value, 0, $limit, 'UTF-8');
            }
            $data[$key] = $value;
        }
        return $data;
    }

    private static function storyboardWritableColumns(): array
    {
        static $columns = null;
        if ($columns !== null) {
            return $columns;
        }
        try {
            $table = (new AigcShortDramaStoryboard())->getTable();
            $table = str_replace('`', '``', $table);
            $rows = Db::query("SHOW COLUMNS FROM `{$table}`");
            $columns = array_fill_keys(array_filter(array_column($rows, 'Field')), true);
        } catch (\Throwable $e) {
            $columns = [];
        }
        return $columns;
    }

    private static function rebuildEditableShotPrompts(int $tenantId, int $userId, AigcShortDramaScriptTask $task, array $shot): array
    {
        $plan = self::currentProjectPlanRaw($tenantId, $userId, (int)$task['project_id']);
        $promptShot = $shot;
        $promptShot['image_prompt'] = '';
        $promptShot['video_prompt'] = '';
        return [
            'image_prompt' => self::buildShotImagePrompt($promptShot, [], $plan),
            'video_prompt' => self::buildReadableShotVideoPrompt($promptShot),
        ];
    }

    private static function formatShot(array $row): array
    {
        return self::sanitizeUtf8Payload([
            'shot_id' => (string)($row['shot_id'] ?? ''),
            'sort' => (int)($row['sort'] ?? 0),
            'act' => (string)($row['act'] ?? ''),
            'title' => (string)($row['title'] ?? ''),
            'scene_name' => (string)($row['scene_name'] ?? ''),
            'time_of_day' => (string)($row['time_of_day'] ?? ''),
            'interior_exterior' => (string)($row['interior_exterior'] ?? 'exterior'),
            'visual_description' => (string)($row['visual_description'] ?? ''),
            'composition' => (string)($row['composition'] ?? ''),
            'camera_movement' => (string)($row['camera_movement'] ?? ''),
            'shot_type' => (string)($row['shot_type'] ?? ''),
            'angle' => (string)($row['angle'] ?? ''),
            'action' => (string)($row['action'] ?? ''),
            'result' => (string)($row['result'] ?? ''),
            'atmosphere' => (string)($row['atmosphere'] ?? ''),
            'image_prompt' => self::localizeGenerationPromptText((string)($row['image_prompt'] ?? ''), (string)($row['visual_description'] ?? '')),
            'video_prompt' => self::normalizeReadableShotVideoPrompt(
                self::localizeGenerationPromptText((string)($row['video_prompt'] ?? ''), (string)($row['visual_description'] ?? '')),
                $row,
                max(0, (int)($row['sort'] ?? 1) - 1)
            ),
            'bgm_prompt' => (string)($row['bgm_prompt'] ?? ''),
            'sound_effect' => (string)($row['sound_effect'] ?? ''),
            'scene_ref_id' => (string)($row['scene_ref_id'] ?? ''),
            'subject_ref_ids' => self::jsonDecode((string)($row['subject_ref_ids'] ?? '')),
            'selected_image_asset_id' => (int)($row['selected_image_asset_id'] ?? 0),
            'selected_video_asset_id' => (int)($row['selected_video_asset_id'] ?? 0),
            'selected_image_asset' => [],
            'selected_video_asset' => [],
            'voice_role' => (string)($row['voice_role'] ?? ''),
            'dialogue' => (string)($row['dialogue'] ?? ''),
            'frame_type' => (string)($row['frame_type'] ?? 'normal'),
            'recommended_duration_seconds' => (float)($row['recommended_duration_seconds'] ?? 3),
        ]);
    }

    private static function applyStoryboardSelectionState(int $tenantId, int $userId, int $projectId, string $taskId, array $result): array
    {
        if (empty($result['storyboard']) || !is_array($result['storyboard'])) {
            return $result;
        }
        $rows = AigcShortDramaStoryboard::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'project_id' => $projectId,
            'task_id' => $taskId,
            'delete_time' => 0,
        ])->select()->toArray();
        $selectionByShot = [];
        $assetIds = [];
        foreach ($rows as $row) {
            $shotId = (string)($row['shot_id'] ?? '');
            if ($shotId === '') {
                continue;
            }
            $imageId = (int)($row['selected_image_asset_id'] ?? 0);
            $videoId = (int)($row['selected_video_asset_id'] ?? 0);
            $selectionByShot[$shotId] = [
                'selected_image_asset_id' => $imageId,
                'selected_video_asset_id' => $videoId,
            ];
            if ($imageId > 0) {
                $assetIds[] = $imageId;
            }
            if ($videoId > 0) {
                $assetIds[] = $videoId;
            }
        }
        $assetsById = [];
        $assetIds = array_values(array_unique(array_filter($assetIds)));
        if (!empty($assetIds)) {
            $assets = AigcShortDramaAsset::where([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'project_id' => $projectId,
                'status' => 'ready',
                'delete_time' => 0,
            ])->whereIn('id', $assetIds)->select()->toArray();
            foreach ($assets as $asset) {
                $assetsById[(int)$asset['id']] = self::formatAsset($asset);
            }
        }
        foreach ($result['storyboard'] as &$shot) {
            if (!is_array($shot)) {
                continue;
            }
            $shotId = (string)($shot['shot_id'] ?? $shot['id'] ?? '');
            $selection = $selectionByShot[$shotId] ?? [
                'selected_image_asset_id' => 0,
                'selected_video_asset_id' => 0,
            ];
            $imageId = (int)$selection['selected_image_asset_id'];
            $videoId = (int)$selection['selected_video_asset_id'];
            $shot['selected_image_asset_id'] = $imageId;
            $shot['selected_video_asset_id'] = $videoId;
            $shot['selected_image_asset'] = $imageId > 0 ? ($assetsById[$imageId] ?? []) : [];
            $shot['selected_video_asset'] = $videoId > 0 ? ($assetsById[$videoId] ?? []) : [];
        }
        unset($shot);
        return $result;
    }

    private static function formatProject(array $row): array
    {
        $status = (string)$row['status'];
        $cover = self::projectCoverData($row);
        $generationSettings = self::projectGenerationSettingsFromRow($row);
        return [
            'id' => (int)$row['id'],
            'title' => (string)$row['title'],
            'cover_url' => (string)$cover['url'],
            'cover_asset_id' => (int)$cover['asset_id'],
            'status' => $status,
            'status_label' => self::projectStatusLabel($status),
            'updated_at' => self::timeText($row['update_time'] ?? 0),
            'ratio' => (string)$row['ratio'],
            'multi_episode' => (int)($row['multi_episode'] ?? 0) === 1,
            'episode_count' => (int)$row['episode_count'],
            'target_duration_seconds' => (int)($row['target_duration_seconds'] ?? 0),
            'task_id' => (string)($row['last_task_id'] ?? ''),
            'current_version_id' => (int)($row['current_version_id'] ?? 0),
            'current_agent_run_id' => (string)($row['current_agent_run_id'] ?? ''),
            'generation_settings' => $generationSettings,
            'final_video_asset_id' => (int)($row['final_video_asset_id'] ?? 0),
            'publish_id' => (int)($row['publish_id'] ?? 0),
        ];
    }

    private static function formatInspiration(array $row, bool $detail = false): array
    {
        $config = self::jsonDecode((string)($row['config_json'] ?? ''));
        $author = self::jsonDecode((string)($row['author_json'] ?? ''));
        if (!empty($author['avatar'])) {
            $author['avatar'] = self::fileUrl((string)$author['avatar']);
        }
        $data = [
            'id' => (int)$row['id'],
            'title' => (string)$row['title'],
            'video_url' => self::fileUrl((string)$row['video_url']),
            'video' => self::fileUrl((string)$row['video_url']),
            'cover_url' => self::fileUrl((string)$row['cover_url']),
            'width' => (int)$row['width'],
            'height' => (int)$row['height'],
            'duration' => (float)$row['duration'],
            'prompt' => (string)$row['prompt'],
            'author' => $author ?: ['id' => 0, 'nickname' => 'AI短剧', 'avatar' => ''],
            'config' => $config,
            'date' => self::timeText($row['create_time'] ?? 0, 'Y-m-d'),
            'created_at' => self::timeText($row['create_time'] ?? 0, 'Y-m-d'),
        ];
        if (!$detail) {
            $data['config'] = [
                'ratio' => (string)($config['ratio'] ?? ''),
                'multi_episode' => (bool)($config['multi_episode'] ?? false),
                'style_name' => (string)($config['style_name'] ?? ''),
                'model_name' => (string)($config['model_name'] ?? ''),
            ];
        }
        return $data;
    }

    private static function adminPlanItemLists(int $tenantId, array $params, string $itemKey): array
    {
        $query = AigcShortDramaPlanVersion::alias('v')
            ->leftJoin('aigc_short_drama_project p', 'p.id = v.project_id AND p.tenant_id = v.tenant_id AND p.delete_time = 0')
            ->leftJoin('aigc_short_drama_script_task t', 't.task_id = v.task_id AND t.tenant_id = v.tenant_id AND t.delete_time = 0')
            ->leftJoin('user u', 'u.id = v.user_id AND u.tenant_id = v.tenant_id')
            ->field('v.*,p.title project_title,p.cover_url project_cover_url,p.ratio project_ratio,t.status task_status,t.progress task_progress,u.nickname user_nickname,u.account user_account,u.mobile user_mobile')
            ->where('v.tenant_id', $tenantId)
            ->where('v.is_current', 1)
            ->where('v.delete_time', 0);

        $status = trim((string)($params['status'] ?? ''));
        if ($status !== '') {
            $query->where('t.status', $status);
        }
        $keyword = trim((string)($params['keyword'] ?? ''));
        if ($keyword !== '' && !in_array($itemKey, ['subjects', 'locations'], true)) {
            $query->whereLike('v.title|p.title|v.task_id', '%' . $keyword . '%');
        }
        $userKeyword = trim((string)($params['user_keyword'] ?? ''));
        if ($userKeyword !== '') {
            $query->where(function ($query) use ($userKeyword) {
                $query->whereLike('u.nickname', '%' . $userKeyword . '%')
                    ->whereOrLike('u.account', '%' . $userKeyword . '%')
                    ->whereOrLike('u.mobile', '%' . $userKeyword . '%');
                if (ctype_digit($userKeyword)) {
                    $query->whereOr('v.user_id', (int)$userKeyword);
                }
            });
        }
        $start = strtotime((string)($params['start_time'] ?? $params['create_start_time'] ?? '')) ?: 0;
        $end = strtotime((string)($params['end_time'] ?? $params['create_end_time'] ?? '')) ?: 0;
        if ($start > 0) {
            $query->where('v.create_time', '>=', $start);
        }
        if ($end > 0) {
            $query->where('v.create_time', '<=', $end + 86399);
        }

        $versions = $query->order(['v.create_time' => 'desc', 'v.id' => 'desc'])->select()->toArray();
        $lists = [];
        foreach ($versions as $version) {
            $storyBible = self::jsonDecode((string)($version['story_bible_json'] ?? ''));
            $continuity = self::jsonDecode((string)($version['continuity_json'] ?? ''));
            $plan = self::jsonDecode((string)($version['plan_json'] ?? ''));
            $items = self::mergeAdminPlanItems(
                (array)($continuity[$itemKey] ?? []),
                (array)($storyBible[$itemKey] ?? []),
                (array)($plan[$itemKey] ?? ($itemKey === 'locations' ? ($plan['scenes'] ?? []) : []))
            );
            $seenItemIds = [];
            foreach ($items as $index => $item) {
                if (!is_array($item)) {
                    continue;
                }
                $row = self::formatAdminPlanItem($version, $item, $itemKey, (int)$index);
                $seenItemIds[(string)($row['item_id'] ?? '')] = true;
                if ($status !== '' && self::adminRowEffectiveStatus($row) !== $status) {
                    continue;
                }
                if ($keyword !== '' && !self::adminPlanItemMatchesKeyword($row, $keyword)) {
                    continue;
                }
                $lists[] = $row;
            }
            if (empty($items)) {
                $fallbackIndex = 0;
                foreach (self::manualPlanItemFallbackItems($version, $itemKey, array_keys($seenItemIds)) as $item) {
                    $row = self::formatAdminPlanItem($version, $item, $itemKey, $fallbackIndex++);
                    if ($status !== '' && self::adminRowEffectiveStatus($row) !== $status) {
                        continue;
                    }
                    if ($keyword !== '' && !self::adminPlanItemMatchesKeyword($row, $keyword)) {
                        continue;
                    }
                    $lists[] = $row;
                }
            }
        }

        return self::paginateAdminRows(self::sortAdminRowsByGenerationTime($lists), $params);
    }

    private static function mergeAdminPlanItems(array ...$groups): array
    {
        $items = [];
        foreach ($groups as $group) {
            foreach ($group as $index => $item) {
                if (!is_array($item)) {
                    continue;
                }
                $id = trim((string)($item['id'] ?? ''));
                $key = $id !== '' ? $id : 'index_' . $index;
                $items[$key] = array_merge((array)($items[$key] ?? []), $item);
            }
        }
        return array_values($items);
    }

    private static function manualPlanItemFallbackItems(array $version, string $itemKey, array $existingIds): array
    {
        $types = $itemKey === 'subjects' ? ['subject_image', 'three_view'] : ['scene_image'];
        $idKey = $itemKey === 'subjects' ? 'subject_id' : 'scene_id';
        $existing = array_fill_keys(array_map('strval', $existingIds), true);
        $rows = AigcShortDramaAsset::where([
            'tenant_id' => (int)$version['tenant_id'],
            'user_id' => (int)$version['user_id'],
            'project_id' => (int)$version['project_id'],
            'delete_time' => 0,
        ])->whereIn('asset_type', $types)
            ->order(['id' => 'desc'])
            ->select()
            ->toArray();

        $items = [];
        foreach ($rows as $row) {
            $meta = self::jsonDecode((string)($row['meta_json'] ?? ''));
            $itemId = (string)($meta[$idKey] ?? $meta['item_id'] ?? '');
            if ($itemId === '' || isset($existing[$itemId]) || isset($items[$itemId])) {
                continue;
            }
            $name = (string)($meta[$itemKey === 'subjects' ? 'subject_name' : 'scene_name'] ?? $meta['item_name'] ?? $row['title'] ?? '');
            $prompt = (string)($meta['visual_prompt'] ?? $meta['image_prompt'] ?? $meta['prompt'] ?? '');
            $item = [
                'id' => $itemId,
                'name' => $name !== '' ? $name : $itemId,
                'description' => $prompt,
                'visual_prompt' => $prompt,
            ];
            if ($itemKey === 'subjects') {
                if ((string)($row['asset_type'] ?? '') === 'three_view') {
                    $item['three_view_image'] = self::fileUrl((string)($row['uri'] ?? ''));
                    $item['three_view_raw_image'] = (string)($row['uri'] ?? '');
                } else {
                    $item['image'] = self::fileUrl((string)($row['uri'] ?? ''));
                    $item['raw_image'] = (string)($row['uri'] ?? '');
                }
            }
            $items[$itemId] = $item;
        }
        return array_values($items);
    }

    private static function adminPlanItemMatchesKeyword(array $row, string $keyword): bool
    {
        $haystack = self::sanitizeUtf8String(implode(' ', [
            (string)($row['item_id'] ?? ''),
            (string)($row['name'] ?? ''),
            (string)($row['description'] ?? ''),
            (string)($row['visual_prompt'] ?? ''),
            (string)($row['project_title'] ?? ''),
            (string)($row['task_id'] ?? ''),
        ]));
        $keyword = self::sanitizeUtf8String($keyword);
        return $keyword !== '' && mb_stripos($haystack, $keyword, 0, 'UTF-8') !== false;
    }

    private static function adminRowEffectiveStatus(array $row): string
    {
        return (string)($row['generation_task']['status'] ?? $row['status'] ?? $row['task_status'] ?? '');
    }

    private static function sortAdminRowsByGenerationTime(array $rows): array
    {
        usort($rows, static function (array $left, array $right): int {
            $leftTime = self::adminRowGenerationTimestamp($left);
            $rightTime = self::adminRowGenerationTimestamp($right);
            if ($leftTime === $rightTime) {
                return self::adminRowSortableId($right) <=> self::adminRowSortableId($left);
            }
            return $rightTime <=> $leftTime;
        });
        return $rows;
    }

    private static function adminRowGenerationTimestamp(array $row): int
    {
        $candidates = [
            $row['generation_time_ts'] ?? 0,
            $row['generation_task']['generation_time_ts'] ?? 0,
            $row['generation_task']['create_time'] ?? '',
            $row['generation_time'] ?? '',
            $row['create_time'] ?? '',
            $row['update_time'] ?? '',
        ];
        foreach ($candidates as $value) {
            if (is_numeric($value) && (int)$value > 0) {
                return (int)$value;
            }
            if (is_string($value) && $value !== '') {
                $time = strtotime($value) ?: 0;
                if ($time > 0) {
                    return $time;
                }
            }
        }
        return 0;
    }

    private static function adminRowSortableId(array $row): int
    {
        $id = (string)($row['id'] ?? '');
        if (is_numeric($id)) {
            return (int)$id;
        }
        if (preg_match('/^\d+/', $id, $matches)) {
            return (int)$matches[0];
        }
        return 0;
    }

    private static function paginateAdminRows(array $rows, array $params = []): array
    {
        $pageNo = max(1, (int)($params['page_no'] ?? 1));
        $pageSize = min(100, max(1, (int)($params['page_size'] ?? 15)));
        $count = count($rows);
        return self::sanitizeUtf8Payload([
            'lists' => array_slice($rows, ($pageNo - 1) * $pageSize, $pageSize),
            'count' => $count,
            'page_no' => $pageNo,
            'page_size' => $pageSize,
        ]);
    }

    private static function formatAdminScriptTask(array $row): array
    {
        $status = (string)$row['status'];
        $result = self::jsonDecode((string)($row['result_json'] ?? ''));
        return [
            'id' => (int)$row['id'],
            'tenant_id' => (int)$row['tenant_id'],
            'project_id' => (int)$row['project_id'],
            'project_title' => (string)($row['project_title'] ?? $result['title'] ?? ''),
            'project_cover_url' => self::fileUrl((string)($row['project_cover_url'] ?? '')),
            'project_ratio' => (string)($row['project_ratio'] ?? ''),
            'project_episode_count' => (int)($row['project_episode_count'] ?? 0),
            'project_status' => (string)($row['project_status'] ?? ''),
            'project_status_label' => self::projectStatusLabel((string)($row['project_status'] ?? '')),
            'task_id' => (string)$row['task_id'],
            'parent_task_id' => (string)$row['parent_task_id'],
            'status' => $status,
            'status_label' => self::taskStatusLabel($status),
            'status_tag' => self::taskStatusTag($status),
            'progress' => (int)$row['progress'],
            'current_step' => (string)($row['current_step'] ?? ''),
            'prompt' => (string)($row['prompt'] ?? ''),
            'provider' => (string)($row['provider'] ?? ''),
            'provider_request_id' => (string)($row['provider_request_id'] ?? ''),
            'provider_task_id' => (string)($row['provider_task_id'] ?? ''),
            'error' => (string)($row['error'] ?? ''),
            'billing_status' => (string)($row['billing_status'] ?? ''),
            'tenant_cost_points' => (float)($row['tenant_cost_points'] ?? 0),
            'user_charge_points' => (float)($row['user_charge_points'] ?? 0),
            'user_id' => (int)$row['user_id'],
            'user_nickname' => (string)($row['user_nickname'] ?? ''),
            'user_account' => (string)($row['user_account'] ?? ''),
            'user_mobile' => (string)($row['user_mobile'] ?? ''),
            'started_at' => self::timeText($row['started_at'] ?? 0),
            'finished_at' => self::timeText($row['finished_at'] ?? 0),
            'generation_time' => self::timeText($row['create_time'] ?? 0),
            'generation_time_ts' => (int)($row['create_time'] ?? 0),
            'create_time' => self::timeText($row['create_time'] ?? 0),
            'update_time' => self::timeText($row['update_time'] ?? 0),
        ];
    }

    private static function formatAdminPlanItem(array $version, array $item, string $itemKey, int $index): array
    {
        $status = (string)($version['task_status'] ?? $version['status'] ?? '');
        $itemId = (string)($item['id'] ?? ($itemKey === 'subjects' ? 'subject_' : 'location_') . ($index + 1));
        $generationBundle = self::adminPlanItemGenerationBundle($version, $itemId, $itemKey);
        return array_merge([
            'id' => (int)$version['id'] . '-' . $itemId,
            'version_id' => (int)$version['id'],
            'project_id' => (int)$version['project_id'],
            'project_title' => (string)($version['project_title'] ?? $version['title'] ?? ''),
            'project_cover_url' => self::fileUrl((string)($version['project_cover_url'] ?? '')),
            'project_ratio' => (string)($version['project_ratio'] ?? ''),
            'task_id' => (string)$version['task_id'],
            'item_type' => $itemKey === 'subjects' ? 'subject' : 'scene',
            'item_id' => $itemId,
            'name' => (string)($item['name'] ?? ''),
            'description' => (string)($item['description'] ?? ''),
            'visual_prompt' => self::localizeGenerationPromptText((string)($item['visual_prompt'] ?? ''), (string)($item['description'] ?? '')),
            'status' => $status,
            'status_label' => self::taskStatusLabel($status),
            'status_tag' => self::taskStatusTag($status),
            'progress' => (int)($version['task_progress'] ?? 0),
            'user_id' => (int)$version['user_id'],
            'user_nickname' => (string)($version['user_nickname'] ?? ''),
            'user_account' => (string)($version['user_account'] ?? ''),
            'user_mobile' => (string)($version['user_mobile'] ?? ''),
            'generation_time' => self::timeText($version['create_time'] ?? 0),
            'generation_time_ts' => (int)($version['create_time'] ?? 0),
            'create_time' => self::timeText($version['create_time'] ?? 0),
            'update_time' => self::timeText($version['update_time'] ?? 0),
        ], $generationBundle);
    }

    private static function adminPlanItemGenerationBundle(array $version, string $itemId, string $itemKey): array
    {
        $types = $itemKey === 'subjects' ? ['subject_image', 'three_view'] : ['scene_image'];
        $matchKey = $itemKey === 'subjects' ? 'subject_id' : 'scene_id';
        $rows = AigcShortDramaGenerationTask::where([
            'tenant_id' => (int)$version['tenant_id'],
            'user_id' => (int)$version['user_id'],
            'project_id' => (int)$version['project_id'],
            'source_task_id' => (string)$version['task_id'],
            'delete_time' => 0,
        ])->whereIn('task_type', $types)
            ->order(['create_time' => 'desc', 'id' => 'desc'])
            ->select()
            ->toArray();

        $tasks = [];
        $assets = [];
        $latestCreateTime = 0;
        foreach ($rows as $row) {
            $request = self::jsonDecode((string)($row['request_json'] ?? ''));
            $params = (array)($request['params'] ?? []);
            if ((string)($params[$matchKey] ?? '') !== $itemId) {
                continue;
            }
            $taskRow = $row;
            $latestCreateTime = max($latestCreateTime, (int)($taskRow['create_time'] ?? 0));
            $taskAssets = self::generationTaskAssets($taskRow);
            $task = self::formatGenerationTask($taskRow, true);
            $task['generation_time'] = self::timeText($taskRow['create_time'] ?? 0);
            $task['generation_time_ts'] = (int)($taskRow['create_time'] ?? 0);
            $task['assets'] = $taskAssets;
            $task['output_assets'] = $taskAssets;
            $task['asset_count'] = count($taskAssets);
            $tasks[] = $task;
            foreach ($taskAssets as $asset) {
                $assets[(string)$asset['id']] = $asset;
            }
        }

        $assets = array_values($assets);
        foreach (self::manualPlanItemAssets($version, $itemId, $itemKey) as $asset) {
            $assets[(string)$asset['id']] = $asset;
        }
        $assets = array_values($assets);
        $firstAsset = $assets[0] ?? [];
        $generationTime = $latestCreateTime > 0 ? $latestCreateTime : (int)($version['create_time'] ?? 0);
        return [
            'generation_tasks' => $tasks,
            'generation_task' => $tasks[0] ?? [],
            'generation_time' => self::timeText($generationTime),
            'generation_time_ts' => $generationTime,
            'assets' => $assets,
            'output_assets' => $assets,
            'asset_count' => count($assets),
            'cover_url' => (string)($firstAsset['url'] ?? ''),
            'image_url' => (string)($firstAsset['url'] ?? ''),
        ];
    }

    private static function manualPlanItemAssets(array $version, string $itemId, string $itemKey): array
    {
        $types = $itemKey === 'subjects' ? ['subject_image', 'three_view'] : ['scene_image'];
        $rows = AigcShortDramaAsset::where([
            'tenant_id' => (int)$version['tenant_id'],
            'user_id' => (int)$version['user_id'],
            'project_id' => (int)$version['project_id'],
            'delete_time' => 0,
        ])->whereIn('asset_type', $types)
            ->order(['id' => 'desc'])
            ->select()
            ->toArray();

        $assets = [];
        foreach ($rows as $row) {
            $meta = self::jsonDecode((string)($row['meta_json'] ?? ''));
            $matchedId = (string)($meta[$itemKey === 'subjects' ? 'subject_id' : 'scene_id'] ?? $meta['item_id'] ?? '');
            if ($matchedId !== $itemId) {
                continue;
            }
            $assets[] = self::formatAsset($row);
        }
        return $assets;
    }

    private static function formatAdminStoryboard(array $row): array
    {
        $generationTask = self::latestAdminGenerationTask((int)$row['tenant_id'], (int)$row['user_id'], (int)$row['project_id'], (string)$row['shot_id']);
        $assets = self::shotAssets((int)$row['tenant_id'], (int)$row['user_id'], (int)$row['project_id'], (string)$row['shot_id']);
        $status = (string)($generationTask['status'] ?? $row['task_status'] ?? '');
        $progress = (int)($generationTask['progress'] ?? $row['task_progress'] ?? 0);
        $generationTaskId = (string)($generationTask['task_id'] ?? '');
        $hasCurrentTaskAsset = $generationTaskId !== '' && count(array_filter($assets, static function (array $asset) use ($generationTaskId): bool {
            return (string)($asset['task_id'] ?? '') === $generationTaskId;
        })) > 0;
        if (self::isProviderSuccessStatus($status)) {
            $status = self::STATUS_SUCCESS;
            $progress = 100;
        }
        if ($hasCurrentTaskAsset) {
            $status = self::STATUS_SUCCESS;
            $progress = 100;
        }
        if ($status === '' && !empty($assets)) {
            $status = self::STATUS_SUCCESS;
            $progress = 100;
        }
        if ($status === self::STATUS_SUCCESS || (!empty($assets) && !self::isActiveGenerationStatus($status))) {
            $status = self::STATUS_SUCCESS;
            $progress = 100;
        }
        if (!empty($generationTask) && $status === self::STATUS_SUCCESS) {
            $generationTask['status'] = self::STATUS_SUCCESS;
            $generationTask['status_label'] = self::taskStatusLabel(self::STATUS_SUCCESS);
            $generationTask['status_tag'] = self::taskStatusTag(self::STATUS_SUCCESS);
            $generationTask['progress'] = 100;
            if (empty($generationTask['assets']) && !empty($assets)) {
                $currentTaskAssets = $generationTaskId === '' ? [] : array_values(array_filter($assets, static function (array $asset) use ($generationTaskId): bool {
                    return (string)($asset['task_id'] ?? '') === $generationTaskId;
                }));
                if (!empty($currentTaskAssets)) {
                    $generationTask['assets'] = $currentTaskAssets;
                    $generationTask['output_assets'] = $currentTaskAssets;
                    $generationTask['asset_count'] = count($currentTaskAssets);
                }
            }
        }
        $generationTime = (int)($generationTask['generation_time_ts'] ?? 0);
        if ($generationTime <= 0) {
            $generationTime = (int)($row['create_time'] ?? 0);
        }
        return [
            'id' => (int)$row['id'],
            'tenant_id' => (int)$row['tenant_id'],
            'project_id' => (int)$row['project_id'],
            'project_title' => (string)($row['project_title'] ?? ''),
            'project_cover_url' => self::fileUrl((string)($row['project_cover_url'] ?? '')),
            'project_ratio' => (string)($row['project_ratio'] ?? ''),
            'task_id' => (string)$row['task_id'],
            'shot_id' => (string)($row['shot_id'] ?? ''),
            'act' => (string)$row['act'],
            'title' => (string)($row['title'] ?? ''),
            'scene_name' => (string)($row['scene_name'] ?? ''),
            'time_of_day' => (string)($row['time_of_day'] ?? ''),
            'interior_exterior' => (string)($row['interior_exterior'] ?? ''),
            'visual_description' => (string)($row['visual_description'] ?? ''),
            'composition' => (string)($row['composition'] ?? ''),
            'camera_movement' => (string)($row['camera_movement'] ?? ''),
            'shot_type' => (string)($row['shot_type'] ?? ''),
            'angle' => (string)($row['angle'] ?? ''),
            'action' => (string)($row['action'] ?? ''),
            'result' => (string)($row['result'] ?? ''),
            'atmosphere' => (string)($row['atmosphere'] ?? ''),
            'image_prompt' => self::localizeGenerationPromptText((string)($row['image_prompt'] ?? ''), (string)($row['visual_description'] ?? '')),
            'video_prompt' => self::normalizeReadableShotVideoPrompt(
                self::localizeGenerationPromptText((string)($row['video_prompt'] ?? ''), (string)($row['visual_description'] ?? '')),
                $row,
                max(0, (int)($row['sort'] ?? 1) - 1)
            ),
            'bgm_prompt' => (string)($row['bgm_prompt'] ?? ''),
            'sound_effect' => (string)($row['sound_effect'] ?? ''),
            'scene_ref_id' => (string)($row['scene_ref_id'] ?? ''),
            'subject_ref_ids' => self::jsonDecode((string)($row['subject_ref_ids'] ?? '')),
            'voice_role' => (string)($row['voice_role'] ?? ''),
            'dialogue' => (string)($row['dialogue'] ?? ''),
            'frame_type' => (string)($row['frame_type'] ?? ''),
            'recommended_duration_seconds' => (float)($row['recommended_duration_seconds'] ?? 0),
            'sort' => (int)($row['sort'] ?? 0),
            'status' => $status,
            'status_label' => self::taskStatusLabel($status),
            'status_tag' => self::taskStatusTag($status),
            'progress' => $progress,
            'generation_task' => $generationTask,
            'asset_count' => count($assets),
            'assets' => $assets,
            'input_assets' => $assets,
            'cover_url' => (string)($assets[0]['url'] ?? ''),
            'user_id' => (int)$row['user_id'],
            'user_nickname' => (string)($row['user_nickname'] ?? ''),
            'user_account' => (string)($row['user_account'] ?? ''),
            'user_mobile' => (string)($row['user_mobile'] ?? ''),
            'generation_time' => self::timeText($generationTime),
            'generation_time_ts' => $generationTime,
            'create_time' => self::timeText($row['create_time'] ?? 0),
            'update_time' => self::timeText($row['update_time'] ?? 0),
        ];
    }

    private static function generationTaskAssets(array $row): array
    {
        $assetIds = array_values(array_filter(array_map('intval', self::jsonDecode((string)($row['output_asset_ids'] ?? '')))));
        $query = AigcShortDramaAsset::where([
            'tenant_id' => (int)$row['tenant_id'],
            'user_id' => (int)$row['user_id'],
            'project_id' => (int)$row['project_id'],
            'task_id' => (string)$row['task_id'],
            'delete_time' => 0,
        ]);
        if (!empty($assetIds)) {
            $query->whereIn('id', $assetIds);
        }
        $rows = $query->order(['id' => 'desc'])->select()->toArray();
        return array_map([self::class, 'formatAsset'], $rows);
    }

    private static function generationTaskInputAssets(array $row): array
    {
        $assetIds = array_values(array_filter(array_map('intval', self::jsonDecode((string)($row['input_asset_ids'] ?? '')))));
        if (empty($assetIds)) {
            return [];
        }
        $rows = AigcShortDramaAsset::where([
            'tenant_id' => (int)$row['tenant_id'],
            'user_id' => (int)$row['user_id'],
            'project_id' => (int)$row['project_id'],
            'status' => 'ready',
            'delete_time' => 0,
        ])->whereIn('id', $assetIds)
            ->orderRaw('FIELD(id,' . implode(',', $assetIds) . ')')
            ->select()
            ->toArray();
        return array_map([self::class, 'formatAsset'], $rows);
    }

    private static function formatAdminGenerationTask(array $row): array
    {
        $assets = self::generationTaskAssets($row);
        $first = $assets[0] ?? [];
        $status = (string)$row['status'];
        return [
            'id' => (int)$row['id'],
            'project_id' => (int)$row['project_id'],
            'project_title' => (string)($row['project_title'] ?? ''),
            'project_cover_url' => self::fileUrl((string)($row['project_cover_url'] ?? '')),
            'project_ratio' => (string)($row['project_ratio'] ?? ''),
            'project_status' => (string)($row['project_status'] ?? ''),
            'task_id' => (string)$row['task_id'],
            'parent_task_id' => (string)($row['parent_task_id'] ?? ''),
            'source_task_id' => (string)($row['source_task_id'] ?? ''),
            'task_type' => (string)$row['task_type'],
            'shot_id' => (string)($row['shot_id'] ?? ''),
            'status' => $status,
            'status_label' => self::taskStatusLabel($status),
            'status_tag' => self::taskStatusTag($status),
            'progress' => (int)$row['progress'],
            'provider' => (string)$row['provider'],
            'provider_task_id' => (string)$row['provider_task_id'],
            'billing_status' => (string)$row['billing_status'],
            'tenant_cost_points' => (float)$row['tenant_cost_points'],
            'user_charge_points' => (float)$row['user_charge_points'],
            'error_code' => (string)$row['error_code'],
            'error_msg' => (string)$row['error_msg'],
            'assets' => $assets,
            'input_assets' => self::generationTaskInputAssets($row),
            'output_assets' => $assets,
            'asset_count' => count($assets),
            'image_url' => (string)($first['asset_type'] ?? '') === 'shot_video' ? '' : (string)($first['url'] ?? ''),
            'video_url' => in_array((string)($first['asset_type'] ?? ''), ['shot_video', 'final_video'], true) ? (string)($first['url'] ?? '') : '',
            'final_video_url' => (string)($first['asset_type'] ?? '') === 'final_video' ? (string)($first['url'] ?? '') : '',
            'user_id' => (int)$row['user_id'],
            'user_nickname' => (string)($row['user_nickname'] ?? ''),
            'user_account' => (string)($row['user_account'] ?? ''),
            'user_mobile' => (string)($row['user_mobile'] ?? ''),
            'started_at' => self::timeText($row['started_at'] ?? 0),
            'finished_at' => self::timeText($row['finished_at'] ?? 0),
            'generation_time' => self::timeText($row['create_time'] ?? 0),
            'generation_time_ts' => (int)($row['create_time'] ?? 0),
            'create_time' => self::timeText($row['create_time'] ?? 0),
            'update_time' => self::timeText($row['update_time'] ?? 0),
        ];
    }

    private static function latestAdminGenerationTask(int $tenantId, int $userId, int $projectId, string $shotId): array
    {
        $row = AigcShortDramaGenerationTask::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'project_id' => $projectId,
            'shot_id' => $shotId,
            'delete_time' => 0,
        ])->whereIn('task_type', ['subject_image', 'scene_image', 'shot_image', 'shot_video'])
            ->order(['create_time' => 'desc', 'id' => 'desc'])
            ->findOrEmpty();
        if ($row->isEmpty()) {
            return [];
        }
        $generation = $row->toArray();
        $taskRow = $generation;
        $task = self::formatGenerationTask($taskRow, false);
        $assets = self::generationTaskAssets($taskRow);
        $task['generation_time'] = self::timeText($taskRow['create_time'] ?? 0);
        $task['generation_time_ts'] = (int)($taskRow['create_time'] ?? 0);
        $task['assets'] = $assets;
        $task['input_assets'] = self::generationTaskInputAssets($taskRow);
        $task['output_assets'] = $assets;
        $task['asset_count'] = count($assets);
        return $task;
    }

    private static function shotAssets(int $tenantId, int $userId, int $projectId, string $shotId): array
    {
        $rows = AigcShortDramaAsset::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'project_id' => $projectId,
            'shot_id' => $shotId,
            'delete_time' => 0,
        ])->whereIn('asset_type', ['subject_image', 'scene_image', 'shot_image', 'shot_video'])
            ->order(['id' => 'desc'])
            ->limit(20)
            ->select()
            ->toArray();
        return array_map([self::class, 'formatAsset'], $rows);
    }

    private static function formatAdminProject(array $row): array
    {
        $status = (string)$row['status'];
        return [
            'id' => (int)$row['id'],
            'tenant_id' => (int)$row['tenant_id'],
            'title' => (string)$row['title'],
            'prompt' => (string)($row['prompt'] ?? ''),
            'cover_url' => self::fileUrl((string)$row['cover_url']),
            'status' => $status,
            'status_label' => self::projectStatusLabel($status),
            'status_tag' => self::projectStatusTag($status),
            'ratio' => (string)$row['ratio'],
            'multi_episode' => (int)$row['multi_episode'],
            'episode_count' => (int)$row['episode_count'],
            'task_id' => (string)($row['last_task_id'] ?? ''),
            'user_id' => (int)$row['user_id'],
            'user_nickname' => (string)($row['user_nickname'] ?? ''),
            'user_account' => (string)($row['user_account'] ?? ''),
            'user_mobile' => (string)($row['user_mobile'] ?? ''),
            'create_time' => self::timeText($row['create_time'] ?? 0),
            'update_time' => self::timeText($row['update_time'] ?? 0),
            'updated_at' => self::timeText($row['update_time'] ?? 0),
        ];
    }

    private static function projectStatusLabel(string $status): string
    {
        return [
            'draft' => '草稿',
            'planning' => '策划',
            'plan_review' => '剧本确认',
            'planned' => '已策',
            'asset_generating' => '素材生成',
            'video_generating' => '视频生成',
            'publish_reviewing' => '发布审核',
            'published' => '已发',
            'failed' => '已失',
            'canceled' => '已取',
        ][$status] ?? ($status ?: '-');
    }

    private static function projectStatusTag(string $status): string
    {
        return [
            'draft' => 'info',
            'planning' => 'warning',
            'plan_review' => 'warning',
            'planned' => 'success',
            'asset_generating' => 'warning',
            'video_generating' => 'warning',
            'publish_reviewing' => 'warning',
            'published' => 'success',
            'failed' => 'danger',
            'canceled' => 'info',
        ][$status] ?? 'info';
    }

    private static function taskStatusLabel(string $status): string
    {
        return [
            self::STATUS_PENDING => '待处',
            self::STATUS_QUEUED => '排队',
            self::STATUS_RUNNING => '生成',
            self::STATUS_SUCCESS => '已完',
            self::STATUS_FAILED => '已失',
            self::STATUS_CANCELED => '已取',
            'processing' => '处理',
            'generating' => '生成',
            'completed' => '已完',
            'ready' => '已生',
        ][$status] ?? ($status ?: '-');
    }

    private static function isTerminalStatus(string $status): bool
    {
        return in_array($status, [self::STATUS_SUCCESS, self::STATUS_FAILED, self::STATUS_CANCELED], true);
    }

    private static function isActiveGenerationStatus(string $status): bool
    {
        return in_array($status, [self::STATUS_PENDING, self::STATUS_QUEUED, self::STATUS_RUNNING, 'processing', 'generating'], true);
    }

    private static function generationStreamMessages(string $taskType, string $mode): array
    {
        if ($mode === 'image_edit') {
            return ['已收到修改要求，正在基于当前分镜图重新生成'];
        }
        if ($mode === 'image_generate' || $taskType === 'shot_image') {
            return ['正在结合角色、场景和分镜描述生成新画面'];
        }
        if ($mode === 'video_generate' || $taskType === 'shot_video') {
            return ['正在以当前分镜作为首帧生成视频'];
        }
        if ($taskType === 'bgm_audio') {
            return ['正在根据整片音乐方案生成背景音乐'];
        }
        return ['任务已提交，正在处理'];
    }

    private static function generationTaskOutputAssets(int $tenantId, int $userId, array $task): array
    {
        $projectId = (int)($task['project_id'] ?? 0);
        $taskId = (string)($task['task_id'] ?? '');
        $assetIds = array_values(array_filter(array_map('intval', (array)($task['output_asset_ids'] ?? []))));
        if ($taskId === '') {
            return [];
        }
        $query = AigcShortDramaAsset::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'project_id' => $projectId,
            'task_id' => $taskId,
            'delete_time' => 0,
        ]);
        if (!empty($assetIds)) {
            $query->whereIn('id', $assetIds);
        }
        return array_map([self::class, 'formatAsset'], $query->order(['id' => 'desc'])->select()->toArray());
    }

    private static function publicTaskStatus(string $status): string
    {
        return $status === self::STATUS_QUEUED ? self::STATUS_PENDING : $status;
    }

    private static function taskStatusTag(string $status): string
    {
        return [
            self::STATUS_PENDING => 'info',
            self::STATUS_QUEUED => 'info',
            self::STATUS_RUNNING => 'warning',
            self::STATUS_SUCCESS => 'success',
            self::STATUS_FAILED => 'danger',
            self::STATUS_CANCELED => 'info',
            'ready' => 'success',
        ][$status] ?? 'info';
    }

    private static function formatAdminInspiration(array $row): array
    {
        $data = self::formatInspiration($row, false);
        $data['tenant_id'] = (int)$row['tenant_id'];
        $data['status'] = (int)$row['status'];
        $data['sort'] = (int)$row['sort'];
        $data['duration'] = (float)$row['duration'];
        $data['create_time'] = self::timeText($row['create_time'] ?? 0);
        $data['update_time'] = self::timeText($row['update_time'] ?? 0);
        return $data;
    }

    private static function formatAdminSubject(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'tenant_id' => (int)$row['tenant_id'],
            'user_id' => (int)$row['user_id'],
            'name' => (string)$row['name'],
            'image' => self::fileUrl((string)($row['image'] ?? '')),
            'raw_image' => (string)($row['image'] ?? ''),
            'description' => (string)($row['description'] ?? ''),
            'category' => (string)($row['category'] ?? 'character'),
            'gender' => (string)($row['gender'] ?? 'unknown'),
            'age_stage' => (string)($row['age_stage'] ?? 'unknown'),
            'source' => (string)($row['source'] ?? 'public'),
            'status' => (int)$row['status'],
            'sort' => (int)$row['sort'],
            'create_time' => self::timeText($row['create_time'] ?? 0),
            'update_time' => self::timeText($row['update_time'] ?? 0),
        ];
    }

    private static function normalizeSubjectLibraryCategory(string $category): string
    {
        $category = trim($category);
        $map = [
            'person' => 'character',
            'people' => 'character',
            'subject' => 'character',
            'role' => 'character',
            '人物' => 'character',
            '角色' => 'character',
            '场景' => 'scene',
            'scene' => 'scene',
            'location' => 'scene',
            'environment' => 'scene',
        ];
        return $map[$category] ?? (in_array($category, ['character', 'scene'], true) ? $category : '');
    }

    private static function findUserSubject(int $tenantId, int $userId, int $id, bool $includeHidden = false): AigcShortDramaSubject
    {
        $query = AigcShortDramaSubject::where([
            'id' => $id,
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'source' => 'user',
            'delete_time' => 0,
        ]);
        if (!$includeHidden) {
            $query->where('status', 1);
        }
        $row = $query->findOrEmpty();
        if ($row->isEmpty()) {
            throw new Exception('Subject does not exist');
        }
        return $row;
    }

    private static function formatSubjectLibrary(array $row, array $threeViewAsset = []): array
    {
        $data = self::formatAdminSubject($row);
        $data['scope'] = (string)($row['source'] ?? '') === 'user' ? 'user' : 'public';
        $data['prompt'] = (string)($row['description'] ?? '');
        $data['three_view_asset'] = $threeViewAsset;
        $data['three_view_url'] = (string)($threeViewAsset['url'] ?? '');
        return $data;
    }

    private static function latestSubjectLibraryAssets(int $tenantId, int $userId, array $subjectIds, string $taskType): array
    {
        $subjectIds = array_values(array_unique(array_filter(array_map('intval', $subjectIds))));
        if (empty($subjectIds)) {
            return [];
        }
        $rows = AigcShortDramaGenerationTask::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'project_id' => 0,
            'task_type' => $taskType,
            'status' => self::STATUS_SUCCESS,
            'delete_time' => 0,
        ])->order(['id' => 'desc'])->select()->toArray();
        $result = [];
        foreach ($rows as $row) {
            $request = self::jsonDecode((string)($row['request_json'] ?? ''));
            $params = (array)($request['params'] ?? []);
            $subjectId = (int)($params['subject_id'] ?? $params['item_id'] ?? 0);
            if ($subjectId <= 0 || !in_array($subjectId, $subjectIds, true) || isset($result[$subjectId])) {
                continue;
            }
            $assets = self::generationTaskAssets($row);
            if (!empty($assets)) {
                $result[$subjectId] = $assets[0];
            }
        }
        return $result;
    }

    private static function latestSubjectLibraryAsset(int $tenantId, int $userId, int $subjectId, string $taskType): array
    {
        $assets = self::latestSubjectLibraryAssets($tenantId, $userId, [$subjectId], $taskType);
        return (array)($assets[$subjectId] ?? []);
    }

    private static function formatAdminStyle(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'tenant_id' => (int)$row['tenant_id'],
            'name' => (string)$row['name'],
            'image' => self::fileUrl((string)($row['image'] ?? '')),
            'raw_image' => (string)($row['image'] ?? ''),
            'description' => (string)($row['description'] ?? ''),
            'is_new' => (int)$row['is_new'],
            'status' => (int)$row['status'],
            'sort' => (int)$row['sort'],
            'create_time' => self::timeText($row['create_time'] ?? 0),
            'update_time' => self::timeText($row['update_time'] ?? 0),
        ];
    }

    private static function normalizeBackgroundConfig(array $payload, array $fallback): array
    {
        $type = in_array(($payload['type'] ?? 'video'), ['video', 'image'], true) ? $payload['type'] : 'video';
        $items = [];
        foreach ((array)($payload['items'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $url = trim((string)($item['url'] ?? ''));
            if ($url === '') {
                continue;
            }
            $items[] = [
                'url' => $url,
                'poster_url' => trim((string)($item['poster_url'] ?? '')),
            ];
        }
        return [
            'type' => $type,
            'items' => $items ?: (array)($fallback['items'] ?? []),
        ];
    }

    private static function normalizeRatioConfig(array $payload, array $fallback): array
    {
        $ratios = [];
        foreach ($payload as $item) {
            if (!is_array($item)) {
                continue;
            }
            $label = trim((string)($item['label'] ?? ''));
            $width = max(1, (int)($item['width'] ?? 0));
            $height = max(1, (int)($item['height'] ?? 0));
            if ($label === '') {
                $label = $width . ':' . $height;
            }
            $ratios[] = compact('label', 'width', 'height');
        }
        return $ratios ?: $fallback;
    }

    private static function defaultExportWatermarkConfig(): array
    {
        return [
            'enabled' => true,
            'type' => 'text',
            'text' => '',
            'image' => '',
            'opacity' => 0.35,
            'margin_x' => 24,
            'margin_y' => 24,
        ];
    }

    private static function normalizeExportWatermarkConfig(array $payload): array
    {
        $default = self::defaultExportWatermarkConfig();
        $type = in_array((string)($payload['type'] ?? $default['type']), ['text', 'image'], true)
            ? (string)($payload['type'] ?? $default['type'])
            : $default['type'];
        $opacity = (float)($payload['opacity'] ?? $default['opacity']);
        $opacity = max(0.05, min(1, $opacity));
        return [
            'enabled' => array_key_exists('enabled', $payload) ? (bool)$payload['enabled'] : $default['enabled'],
            'type' => $type,
            'text' => mb_substr(trim((string)($payload['text'] ?? $default['text'])), 0, 80, 'UTF-8'),
            'image' => trim((string)($payload['image'] ?? $default['image'])),
            'opacity' => $opacity,
            'margin_x' => max(0, min(300, (int)($payload['margin_x'] ?? $default['margin_x']))),
            'margin_y' => max(0, min(300, (int)($payload['margin_y'] ?? $default['margin_y']))),
        ];
    }

    private static function normalizeModelConfig(array $payload, array $fallback): array
    {
        $models = [];
        foreach ($payload as $item) {
            if (!is_array($item)) {
                continue;
            }
            $id = trim((string)($item['id'] ?? ''));
            $name = trim((string)($item['name'] ?? ''));
            if ($id === '' || $name === '') {
                continue;
            }
            $models[] = [
                'id' => $id,
                'name' => $name,
                'description' => trim((string)($item['description'] ?? '')),
                'image' => trim((string)($item['image'] ?? self::DEFAULT_IMAGE)),
                'enabled' => (bool)($item['enabled'] ?? true),
                'sort' => (int)($item['sort'] ?? 0),
            ];
        }
        return $models ?: $fallback;
    }

    private static function formatBackgroundConfig(array $background): array
    {
        $items = [];
        foreach ((array)($background['items'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $url = trim((string)($item['url'] ?? ''));
            if ($url === '') {
                continue;
            }
            $items[] = [
                'url' => self::fileUrl($url),
                'poster_url' => self::fileUrl(trim((string)($item['poster_url'] ?? ''))),
            ];
        }
        return [
            'type' => in_array(($background['type'] ?? 'video'), ['video', 'image'], true) ? $background['type'] : 'video',
            'items' => $items,
        ];
    }

    private static function checkSensitivePrompt(string $prompt): void
    {
        $blocked = ['api_key', 'system prompt', 'system_prompt', 'secret key'];
        foreach ($blocked as $word) {
            if (stripos($prompt, $word) !== false) {
                throw new Exception('内容不符合创作规范，请调整后重试');
            }
        }
    }

    private static function userPoints(int $userId): float
    {
        if ($userId <= 0) {
            return 0;
        }
        try {
            $user = User::where('id', $userId)->findOrEmpty();
            return $user->isEmpty() ? 0 : (float)($user['user_money'] ?? 0);
        } catch (\Throwable) {
            return 0;
        }
    }

    private static function makeTaskId(string $prefix = 'sd_plan'): string
    {
        return $prefix . '_' . date('YmdHis') . random_int(1000, 9999);
    }

    private static function makeTitle(string $prompt): string
    {
        $text = preg_replace('/\s+/u', '', $prompt) ?: 'Short drama plan';
        $base = mb_substr((string)$text, 0, 12, 'UTF-8');
        return $base . ' short drama';
    }

    private static function scriptPlanPriorityMeta(string $prompt, array $request): array
    {
        $styleDetail = self::styleDetail((string)($request['style_id'] ?? ''));
        $selectedStyleName = (string)($styleDetail['name'] ?? '');
        $selectedStylePrompt = (string)($styleDetail['prompt'] ?? '');
        $userTextStyleHint = self::extractUserTextStyleHint($prompt);
        $userTextDurationHint = self::extractUserTextDurationHint($prompt);
        $selectedDurationHint = self::selectedDurationHint($request);

        return [
            'selected_style_name' => $selectedStyleName,
            'selected_style_prompt' => $selectedStylePrompt,
            'user_text_style_hint' => $userTextStyleHint,
            'user_text_duration_hint' => $userTextDurationHint,
            'user_text_time_period_hint' => self::extractUserTextTimePeriodHint($prompt),
            'selected_duration_hint' => $selectedDurationHint,
            'style_source' => $selectedStyleName !== '' || $selectedStylePrompt !== '' ? 'selected' : ($userTextStyleHint !== '' ? 'user_text' : 'default'),
            'duration_source' => $selectedDurationHint !== '' ? 'selected' : (!empty(self::extractTimelineSegments($prompt)) ? 'timeline' : ($userTextDurationHint !== '' ? 'user_text' : 'default')),
        ];
    }

    private static function selectedDurationHint(array $request): string
    {
        $targetDuration = (int)($request['target_duration_seconds'] ?? 0);
        if ($targetDuration > 0) {
            return $targetDuration . ' seconds';
        }
        $episodeCount = (int)($request['episode_count'] ?? 1);
        if (!empty($request['multi_episode']) || $episodeCount > 1) {
            return 'multi_episode, episode_count=' . max(1, $episodeCount);
        }
        return '';
    }

    private static function defaultStoryboardRules(): array
    {
        return [
            [
                'code' => 'ordinary_short',
                'label' => '普通短',
                'description' => '常规剧情短片，有完整起承转合但悬疑、反转或跨场景复杂度不高',
                'min_shots' => 20,
                'max_shots' => 35,
                'sort' => 20,
                'enabled' => true,
            ],
            [
                'code' => 'suspense_twist_dream',
                'label' => '复杂梦境 / 悬疑 / 反转短片',
                'description' => '包含梦境、悬疑铺垫、误导、真相揭露、强反转或高密度情绪变化',
                'min_shots' => 30,
                'max_shots' => 40,
                'sort' => 30,
                'enabled' => true,
            ],
            [
                'code' => 'simple_single_scene',
                'label' => '简单口播 / 广告 / 单场景内容',
                'description' => '以口播、产品广告、单一空间或单一动作链为主，剧情结构简单',
                'min_shots' => 8,
                'max_shots' => 18,
                'sort' => 10,
                'enabled' => true,
            ],
            [
                'code' => 'complex_multi_scene',
                'label' => '复杂多场景剧',
                'description' => '跨多个地点或时空，角色关系、动作链、情绪转折和关键线索较多',
                'min_shots' => 40,
                'max_shots' => 0,
                'sort' => 40,
                'enabled' => true,
            ],
        ];
    }

    private static function normalizeStoryboardRules(array $rules): array
    {
        $defaults = self::defaultStoryboardRules();
        if (empty($rules)) {
            return $defaults;
        }

        $defaultMap = [];
        foreach ($defaults as $default) {
            $defaultMap[(string)$default['code']] = $default;
        }

        $normalized = [];
        foreach ($rules as $index => $item) {
            if (!is_array($item)) {
                continue;
            }
            $code = trim((string)($item['code'] ?? ''));
            if ($code === '') {
                $code = 'custom_' . ($index + 1);
            }
            $base = $defaultMap[$code] ?? [];
            $label = mb_substr(trim((string)($item['label'] ?? $base['label'] ?? $code)), 0, 60, 'UTF-8');
            if ($label === '') {
                $label = $code;
            }
            $minShots = max(1, min(300, (int)($item['min_shots'] ?? $base['min_shots'] ?? 1)));
            $maxShots = max(0, min(500, (int)($item['max_shots'] ?? $base['max_shots'] ?? 0)));
            if ($maxShots > 0 && $maxShots < $minShots) {
                $maxShots = $minShots;
            }
            $normalized[$code] = [
                'code' => $code,
                'label' => $label,
                'description' => mb_substr(trim((string)($item['description'] ?? $base['description'] ?? '')), 0, 500, 'UTF-8'),
                'min_shots' => $minShots,
                'max_shots' => $maxShots,
                'sort' => (int)($item['sort'] ?? $base['sort'] ?? (($index + 1) * 10)),
                'enabled' => (bool)($item['enabled'] ?? $base['enabled'] ?? true),
            ];
        }

        foreach ($defaults as $default) {
            $code = (string)$default['code'];
            if (!isset($normalized[$code])) {
                $normalized[$code] = $default;
            }
        }
        $normalized = array_values(array_filter($normalized, static fn(array $item): bool => !empty($item['enabled'])));
        if (empty($normalized)) {
            $normalized = $defaults;
        }
        usort($normalized, static fn(array $a, array $b): int => ((int)($a['sort'] ?? 0) <=> (int)($b['sort'] ?? 0)));
        return $normalized;
    }

    private static function storyboardRulesFromRequest(array $request): array
    {
        return self::normalizeStoryboardRules((array)($request['storyboard_rules'] ?? []));
    }

    private static function storyboardTargetRule(string $prompt, array $request, array $locations = []): array
    {
        if (!empty(self::extractTimelineSegments($prompt)) && self::selectedDurationHint($request) === '') {
            return [];
        }
        if (self::planningTargetDurationSeconds($prompt, $request) > 0 || !empty($request['multi_episode']) || (int)($request['episode_count'] ?? 1) > 1) {
            return [];
        }

        $stored = is_array($request['storyboard_target_rule'] ?? null) ? (array)$request['storyboard_target_rule'] : [];
        if ((int)($stored['min_shots'] ?? 0) > 0) {
            return [
                'code' => (string)($stored['code'] ?? ''),
                'label' => (string)($stored['label'] ?? ''),
                'description' => (string)($stored['description'] ?? ''),
                'min_shots' => max(1, (int)($stored['min_shots'] ?? 1)),
                'max_shots' => max(0, (int)($stored['max_shots'] ?? 0)),
                'sort' => (int)($stored['sort'] ?? 0),
                'enabled' => true,
            ];
        }

        $rules = self::storyboardRulesFromRequest($request);
        return self::storyboardMatchedRule($rules, [], $locations, $prompt);
    }

    private static function storyboardRuleRangeLabel(array $rule): string
    {
        $min = max(1, (int)($rule['min_shots'] ?? 1));
        $max = max(0, (int)($rule['max_shots'] ?? 0));
        if ($max <= 0) {
            return $min . '+ shots';
        }
        if ($max === $min) {
            return $min . ' shots';
        }
        return $min . '-' . $max . ' shots';
    }

    private static function storyboardBreakingIntensityInstruction(array $rules, bool $timelineOverride = false): array
    {
        $items = [];
        foreach ($rules as $rule) {
            $level = self::storyboardRuleIntensityLevel($rule);
            $items[] = [
                'code' => (string)($rule['code'] ?? ''),
                'label' => (string)($rule['label'] ?? ''),
                'range' => self::storyboardRuleRangeLabel($rule),
                'intensity_level' => $level,
                'instruction' => self::storyboardIntensityInstructionText($level),
            ];
        }
        return [
            'timeline_override' => $timelineOverride,
            'timeline_priority_rule' => $timelineOverride
                ? 'Timeline segments are authoritative. Do not expand the total duration or add extra shots just to satisfy a complexity range.'
                : 'When no authoritative timeline is provided, judge story complexity first, then choose one matching storyboard rule and split shots by its intensity.',
            'output_contract' => 'Keep the existing flat storyboard[] structure. Do not output nested scene_id + shots[] data.',
            'global_rule' => 'Do not add new plot or change character relationships. Each storyboard item expresses one core visible action or one visual information task.',
            'rules' => $items,
        ];
    }

    private static function storyboardRuleIntensityLevel(array $rule): string
    {
        $code = (string)($rule['code'] ?? '');
        $label = (string)($rule['label'] ?? '');
        $haystack = strtolower($code . ' ' . $label);
        $min = (int)($rule['min_shots'] ?? 0);
        $max = (int)($rule['max_shots'] ?? 0);
        if (str_contains($haystack, 'simple') || str_contains($haystack, 'single') || str_contains($label, '口播') || str_contains($label, '广告')) {
            return 'lightweight';
        }
        if (str_contains($haystack, 'suspense') || str_contains($haystack, 'twist') || str_contains($haystack, 'dream') || str_contains($label, '悬疑') || str_contains($label, '反转') || str_contains($label, '梦境')) {
            return 'detailed';
        }
        if (str_contains($haystack, 'complex') || str_contains($haystack, 'multi') || str_contains($label, '多场') || $min >= 40 || $max === 0) {
            return 'cinematic_detailed';
        }
        if ($min >= 30) {
            return 'detailed';
        }
        if ($min >= 20) {
            return 'standard';
        }
        return 'lightweight';
    }

    private static function storyboardIntensityInstructionText(string $level): string
    {
        $map = [
            'lightweight' => 'Light split. Use one establishing shot per scene, make only key information points independent shots, merge dialogue and reaction when appropriate, and do not force a reaction shot for every line.',
            'standard' => 'Standard split. Key actions become independent shots, important dialogue includes speaking and reaction shots, emotion changes use close-up or medium close-up, and scene endings use a transition or emotional hold.',
            'detailed' => 'Detailed split. Key props, clues, misdirection, reveal-before/reveal-after beats, and character reactions must be separated; important emotions should use consecutive close-up/detail shots.',
            'cinematic_detailed' => 'Cinematic detailed split. Each location needs an establishing shot, location changes need transition shots, relationship changes need reaction shots, and climax/ending should be split into consecutive shot groups.',
        ];
        return $map[$level] ?? $map['standard'];
    }

    private static function storyboardBreakingDiagnostics(array $storyboard, array $locations = [], array $request = [], string $prompt = '', array $existing = []): array
    {
        $rules = self::storyboardRulesFromRequest($request);
        $timelineOverride = !empty(self::extractTimelineSegments($prompt)) && self::selectedDurationHint($request) === '';
        if (array_key_exists('timeline_override', $existing)) {
            $timelineOverride = (bool)$existing['timeline_override'];
        }
        $targetRule = self::storyboardTargetRule($prompt, $request, $locations);
        $ruleHint = !empty($targetRule) ? [
            'matched_rule_code' => (string)($targetRule['code'] ?? ''),
            'matched_rule_label' => (string)($targetRule['label'] ?? ''),
            'target_min_shots' => (int)($targetRule['min_shots'] ?? 0),
            'target_max_shots' => (int)($targetRule['max_shots'] ?? 0),
        ] : [];
        $rule = self::storyboardMatchedRule($rules, $storyboard, $locations, $prompt, array_merge($ruleHint, $existing));
        $actual = count(array_filter($storyboard, 'is_array'));
        $min = max(1, (int)($rule['min_shots'] ?? 1));
        $max = max(0, (int)($rule['max_shots'] ?? 0));
        return [
            'matched_rule_code' => (string)($rule['code'] ?? ''),
            'matched_rule_label' => (string)($rule['label'] ?? ''),
            'target_min_shots' => $min,
            'target_max_shots' => $max,
            'actual_shot_count' => $actual,
            'intensity_level' => self::storyboardRuleIntensityLevel($rule),
            'timeline_override' => $timelineOverride,
            'range_status' => self::storyboardRangeStatus($actual, $min, $max, $timelineOverride),
        ];
    }

    private static function storyboardMatchedRule(array $rules, array $storyboard, array $locations = [], string $prompt = '', array $existing = []): array
    {
        $existingCode = (string)($existing['matched_rule_code'] ?? '');
        if ($existingCode !== '') {
            if ((int)($existing['target_min_shots'] ?? 0) > 0) {
                return [
                    'code' => $existingCode,
                    'label' => (string)($existing['matched_rule_label'] ?? $existingCode),
                    'min_shots' => (int)($existing['target_min_shots'] ?? 1),
                    'max_shots' => (int)($existing['target_max_shots'] ?? 0),
                    'sort' => 0,
                    'enabled' => true,
                ];
            }
            foreach ($rules as $rule) {
                if ((string)($rule['code'] ?? '') === $existingCode) {
                    return $rule;
                }
            }
        }
        $level = self::inferStoryboardIntensityLevel($prompt, $locations);
        foreach ($rules as $rule) {
            if (self::storyboardRuleIntensityLevel($rule) === $level) {
                return $rule;
            }
        }
        $actual = count(array_filter($storyboard, 'is_array'));
        if ($actual > 0 && $prompt === '' && empty($locations)) {
            foreach ($rules as $rule) {
                $min = max(1, (int)($rule['min_shots'] ?? 1));
                $max = max(0, (int)($rule['max_shots'] ?? 0));
                if ($actual >= $min && ($max <= 0 || $actual <= $max)) {
                    return $rule;
                }
            }
        }
        return $rules[0] ?? [
            'code' => 'ordinary_short',
            'label' => '普通短',
            'min_shots' => 20,
            'max_shots' => 35,
            'sort' => 20,
            'enabled' => true,
        ];
    }

    private static function inferStoryboardIntensityLevel(string $prompt, array $locations = []): string
    {
        $text = mb_strtolower($prompt, 'UTF-8');
        $locationCount = count(array_filter($locations, 'is_array'));
        if ($locationCount >= 4 || self::containsAnyKeyword($text, ['多场', '跨时', '多地', '追', '群像', '史诗'])) {
            return 'cinematic_detailed';
        }
        if (self::containsAnyKeyword($text, ['悬疑', '反转', '梦境', '线索', '误导', '真相', '惊悚', '谜团'])) {
            return 'detailed';
        }
        if (self::containsAnyKeyword($text, ['口播', '广告', '产品', '单场', '介绍', '讲解'])) {
            return 'lightweight';
        }
        return 'standard';
    }

    private static function storyboardRangeStatus(int $actual, int $min, int $max, bool $timelineOverride): string
    {
        if ($timelineOverride) {
            return 'timeline_override';
        }
        if ($actual < $min) {
            return 'under_range';
        }
        if ($max > 0 && $actual > $max) {
            return 'over_range';
        }
        return 'in_range';
    }

    private static function storyboardShotCountUnderRangeSeverity(int $actual, int $min, array $storyboard, array $locations): string
    {
        $locationCount = count(array_filter($locations, 'is_array'));
        $viableMinimum = max(20, $locationCount > 0 ? $locationCount * 2 : 0);
        if ($min > 30 && $actual >= $viableMinimum && self::storyboardCoversEveryLocation($storyboard, $locations)) {
            return 'warning';
        }
        return 'blocking';
    }

    private static function storyboardCoversEveryLocation(array $storyboard, array $locations): bool
    {
        $sceneIds = array_values(array_filter(array_map(static fn($item): string => is_array($item) ? (string)($item['id'] ?? '') : '', $locations)));
        if (empty($sceneIds)) {
            return true;
        }
        $covered = [];
        foreach ($storyboard as $shot) {
            if (!is_array($shot)) {
                continue;
            }
            $sceneRef = (string)($shot['scene_ref_id'] ?? '');
            if ($sceneRef !== '') {
                $covered[$sceneRef] = true;
            }
        }
        foreach ($sceneIds as $sceneId) {
            if (empty($covered[$sceneId])) {
                return false;
            }
        }
        return true;
    }

    private static function storyboardHasShotKind(array $storyboard, string $kind): bool
    {
        foreach ($storyboard as $shot) {
            if (!is_array($shot)) {
                continue;
            }
            if (self::shotMatchesKind($shot, $kind)) {
                return true;
            }
        }
        return false;
    }

    private static function missingEstablishingSceneIds(array $storyboard, array $locations): array
    {
        $sceneIds = array_values(array_filter(array_map(static fn($item): string => is_array($item) ? (string)($item['id'] ?? '') : '', $locations)));
        if (empty($sceneIds)) {
            return [];
        }
        $hasEstablishing = array_fill_keys($sceneIds, false);
        foreach ($storyboard as $shot) {
            if (!is_array($shot)) {
                continue;
            }
            $sceneRef = (string)($shot['scene_ref_id'] ?? '');
            if ($sceneRef !== '' && isset($hasEstablishing[$sceneRef]) && self::shotMatchesKind($shot, 'establishing')) {
                $hasEstablishing[$sceneRef] = true;
            }
        }
        return array_keys(array_filter($hasEstablishing, static fn(bool $exists): bool => !$exists));
    }

    private static function shotMatchesKind(array $shot, string $kind): bool
    {
        $text = implode(' ', [
            (string)($shot['title'] ?? ''),
            (string)($shot['shot_type'] ?? ''),
            (string)($shot['visual_description'] ?? ''),
            (string)($shot['composition'] ?? ''),
            (string)($shot['camera_movement'] ?? ''),
            (string)($shot['action'] ?? ''),
            (string)($shot['result'] ?? ''),
            (string)($shot['image_prompt'] ?? ''),
            (string)($shot['video_prompt'] ?? ''),
        ]);
        $keywords = [
            'establishing' => ['建立', '远景', '全景', '大全', '环境', '空间', '场景', '开', '外景', '俯瞰', '航拍'],
            'reaction' => ['反应', '看向', '回望', '愣住', '震惊', '惊讶', '沉默', '皱眉', '流泪', '眼神', '表情', '近景', '特写'],
            'transition' => ['转场', '切到', '切换', '过渡', '淡入', '淡出', '黑场', '移至', '穿过', '离开', '进入', '跟随', '摇移'],
            'closeup' => ['特写', '近景', '极近', '细节', '道具', '手部', '眼神', '表情', '局'],
        ];
        return self::containsAnyKeyword($text, $keywords[$kind] ?? []);
    }

    private static function planningTargetDurationSeconds(string $prompt, array $request): int
    {
        $targetDuration = (int)($request['target_duration_seconds'] ?? 0);
        if ($targetDuration > 0) {
            return max(1, min(3600, $targetDuration));
        }
        $timelineSeconds = self::timelineTotalSeconds(self::extractTimelineSegments($prompt));
        if ($timelineSeconds > 0) {
            return $timelineSeconds;
        }
        return self::durationHintToSeconds(self::extractUserTextDurationHint($prompt));
    }

    private static function minimumStoryboardShotCount(string $prompt, array $request, int $sceneCount = 0): int
    {
        $timelineSegments = self::extractTimelineSegments($prompt);
        if (!empty($timelineSegments) && self::selectedDurationHint($request) === '') {
            $count = 0;
            foreach ($timelineSegments as $segment) {
                $count += count(self::splitTimelineDuration((int)($segment['duration_seconds'] ?? 0)));
            }
            return max(1, $count);
        }

        $targetDuration = self::planningTargetDurationSeconds($prompt, $request);
        if ($targetDuration > 0) {
            return max($sceneCount > 0 ? $sceneCount * 3 : 1, (int)ceil($targetDuration / 5));
        }

        $targetRule = self::storyboardTargetRule($prompt, $request);
        $base = max(1, (int)($targetRule['min_shots'] ?? 0));
        if ($base <= 1) {
            $rules = self::storyboardRulesFromRequest($request);
            $base = min(array_map(static fn(array $rule): int => max(1, (int)($rule['min_shots'] ?? 1)), $rules));
        }
        if ($sceneCount > 0) {
            $base = max($base, $sceneCount * 2);
        }
        return $base;
    }

    private static function recommendedStoryboardCountHint(string $prompt, array $request): string
    {
        $timelineSegments = self::extractTimelineSegments($prompt);
        if (!empty($timelineSegments) && self::selectedDurationHint($request) === '') {
            $shotCount = self::minimumStoryboardShotCount($prompt, $request, 0);
            return $shotCount . ' shots based on user-provided timecode segments. Follow timeline_segments strictly and keep total duration exactly ' . self::timelineTotalSeconds($timelineSegments) . ' seconds.';
        }

        $targetDuration = self::planningTargetDurationSeconds($prompt, $request);
        if ($targetDuration > 0) {
            $min = max(1, (int)ceil($targetDuration / 5));
            $max = max($min, (int)ceil($targetDuration / 2));
            return $min . '-' . $max . ' shots based on target duration, distributed by ordered scenes and one visual task per shot. Each shot lasts 2-5 seconds. Do not round, compress, or pad the result to 8.';
        }

        $episodeCount = (int)($request['episode_count'] ?? 1);
        if (!empty($request['multi_episode']) || $episodeCount > 1) {
            return 'multi-episode story: split by episode sections and ordered scenes. Use AI video granularity: 2-5 seconds per shot, one visual task per shot, and no single fixed total count.';
        }

        $targetRule = self::storyboardTargetRule($prompt, $request);
        if (!empty($targetRule)) {
            return 'no explicit duration or timeline: selected complexity rule is '
                . (string)($targetRule['label'] ?? $targetRule['code'] ?? 'story')
                . ', required storyboard range is ' . self::storyboardRuleRangeLabel($targetRule)
                . '. storyboard.length must be at least ' . (int)($targetRule['min_shots'] ?? 1)
                . (((int)($targetRule['max_shots'] ?? 0) > 0) ? (' and no more than ' . (int)$targetRule['max_shots']) : ' with no upper limit')
                . '. Split by plot complexity and one visible task per shot. Do not use text length as the deciding factor.';
        }

        $parts = [];
        foreach (self::storyboardRulesFromRequest($request) as $rule) {
            $parts[] = (string)($rule['label'] ?? $rule['code'] ?? 'story') . ': ' . self::storyboardRuleRangeLabel($rule) . '. ' . (string)($rule['description'] ?? '');
        }
        return 'no explicit duration or timeline: judge plot complexity first, then choose one matching range from storyboard_complexity_rules. ' . implode(' ', $parts) . ' Split ordinary actions into 2-3 second shots, emotional close-ups into 3-4 second shots, establishing shots into 3-5 second shots, and climax/reveal shots into 4-5 second shots. Do not use text length as the deciding factor.';
    }

    private static function extractTimelineSegments(string $prompt): array
    {
        if (preg_match_all('/([0-9]{1,3})\s*-\s*([0-9]{1,3})\s*(?:s|S)(?![0-9])/u', $prompt, $secondMatches, PREG_OFFSET_CAPTURE)) {
            $segments = [];
            $count = count($secondMatches[0]);
            for ($index = 0; $index < $count; $index++) {
                $start = (int)$secondMatches[1][$index][0];
                $end = (int)$secondMatches[2][$index][0];
                if ($end <= $start) {
                    continue;
                }
                $textStart = $secondMatches[0][$index][1] + strlen($secondMatches[0][$index][0]);
                $textEnd = $index + 1 < $count ? $secondMatches[0][$index + 1][1] : strlen($prompt);
                $text = trim(substr($prompt, $textStart, max(0, $textEnd - $textStart)));
                $segments[] = [
                    'index' => count($segments) + 1,
                    'time_range' => $secondMatches[0][$index][0],
                    'start_seconds' => $start,
                    'end_seconds' => $end,
                    'duration_seconds' => $end - $start,
                    'text' => mb_substr($text, 0, 1200, 'UTF-8'),
                ];
            }
            if (!empty($segments)) {
                return $segments;
            }
        }
        if (!preg_match_all('/(?<!\d)(\d{1,2}):([0-5]\d)\s*[-\x{2013}\x{2014}~～至到]\s*(\d{1,2}):([0-5]\d)(?!\d)/u', $prompt, $matches, PREG_OFFSET_CAPTURE)) {
            return [];
        }
        $segments = [];
        $count = count($matches[0]);
        for ($index = 0; $index < $count; $index++) {
            $start = ((int)$matches[1][$index][0] * 60) + (int)$matches[2][$index][0];
            $end = ((int)$matches[3][$index][0] * 60) + (int)$matches[4][$index][0];
            if ($end <= $start) {
                continue;
            }
            $textStart = $matches[0][$index][1] + strlen($matches[0][$index][0]);
            $textEnd = $index + 1 < $count ? $matches[0][$index + 1][1] : strlen($prompt);
            $text = trim(substr($prompt, $textStart, max(0, $textEnd - $textStart)));
            $segments[] = [
                'index' => count($segments) + 1,
                'time_range' => $matches[0][$index][0],
                'start_seconds' => $start,
                'end_seconds' => $end,
                'duration_seconds' => $end - $start,
                'text' => mb_substr($text, 0, 1200, 'UTF-8'),
            ];
        }
        return $segments;
    }

    private static function timelineTotalSeconds(array $segments): int
    {
        $total = 0;
        foreach ($segments as $segment) {
            $total = max($total, (int)($segment['end_seconds'] ?? 0));
        }
        return $total;
    }

    private static function durationHintToSeconds(string $hint): int
    {
        $hint = trim($hint);
        if (preg_match('/([0-9]+)\s*(?:\xE5\x88\x86\xE9\x92\x9F|\xE5\x88\x86)\s*([0-9]+)?\s*(?:\xE7\xA7\x92|s|S)?/', $hint, $match)) {
            return ((int)$match[1] * 60) + (int)($match[2] ?? 0);
        }
        if (preg_match('/([0-9]+)\s*(?:\xE7\xA7\x92|s|S)/', $hint, $match)) {
            return (int)$match[1];
        }
        return 0;
        $hint = strtr($hint, [
            '０' => '0', '１' => '1', '２' => '2', '３' => '3', '４' => '4',
            '５' => '5', '６' => '6', '７' => '7', '８' => '8', '９' => '9',
        ]);
        if (preg_match('/([0-9]+)\s*(?:分钟|分)\s*([0-9]+)?\s*(?:秒|s|S)?/u', $hint, $match)) {
            return ((int)$match[1] * 60) + (int)($match[2] ?? 0);
        }
        if (preg_match('/([0-9]+)\s*(?:秒|s|S)/u', $hint, $match)) {
            return (int)$match[1];
        }
        if ($hint === '') {
            return 0;
        }
        $hint = strtr($hint, [
            '０' => '0', '１' => '1', '２' => '2', '３' => '3', '４' => '4',
            '５' => '5', '６' => '6', '７' => '7', '８' => '8', '９' => '9',
        ]);
        if (preg_match('/([0-9]+)\s*(?:分|分钟)\s*([0-9]+)?\s*(?:秒|s|S)?/u', $hint, $match)) {
            return ((int)$match[1] * 60) + (int)($match[2] ?? 0);
        }
        if (preg_match('/([0-9]+)\s*(?:秒|s|S)/u', $hint, $match)) {
            return (int)$match[1];
        }
        if (preg_match('/([一二三四五六七八九十百两]+)\s*分钟/u', $hint, $match)) {
            return self::chineseNumberToInt((string)$match[1]) * 60;
        }
        if (preg_match('/([一二三四五六七八九十百两]+)\s*秒/u', $hint, $match)) {
            return self::chineseNumberToInt((string)$match[1]);
        }
        return 0;
    }

    private static function chineseNumberToInt(string $text): int
    {
        $map = ['零' => 0, '一' => 1, '二' => 2, '两' => 2, '三' => 3, '四' => 4, '五' => 5, '六' => 6, '七' => 7, '八' => 8, '九' => 9];
        if ($text === '') {
            return 10;
        }
        if (str_contains($text, '百')) {
            [$hundredText, $rest] = array_pad(explode('百', $text, 2), 2, '');
            return max(1, $map[$hundredText] ?? 1) * 100 + self::chineseNumberToInt($rest);
        }
        if (str_contains($text, '十')) {
            [$tenText, $oneText] = array_pad(explode('十', $text, 2), 2, '');
            $ten = $tenText === '' ? 1 : (int)($map[$tenText] ?? 0);
            return $ten * 10 + (int)($map[$oneText] ?? 0);
        }
        return (int)($map[$text] ?? 0);
    }

    private static function extractUserTextStyleHint(string $prompt): string
    {
        $keywords = [
            '赛博朋克', '蒸汽朋克', '伪灾', '感官惊悚', '温柔怪诞', '怪诞', '儿童视角', '写实', '现实主义', '电影', '胶片',
            '短剧', '悬疑', '惊悚', '恐', '喜剧', '甜宠', '虐恋', '都市', '校园', '国风', '古风',
            '武侠', '仙侠', '玄幻', '科幻', '二次', '日漫', '韩漫', '水墨', '黑白电影',
            '复古', '港风', '邵氏', '岩井俊二', '新海', '宫崎', '纪录', '现实题材'
        ];
        $matches = [];
        foreach ($keywords as $keyword) {
            if (mb_stripos($prompt, $keyword, 0, 'UTF-8') !== false) {
                $matches[] = $keyword;
            }
        }
        if (preg_match_all('/(?:风格|画风|影调|美术|质感|镜头气质|视觉)\s*(?:为|是|：|:)?\s*([^\n，。；;、]{2,24})/u', $prompt, $found)) {
            foreach ((array)$found[1] as $item) {
                $matches[] = trim((string)$item);
            }
        }
        $matches = array_values(array_unique(array_filter($matches)));
        return mb_substr(implode('', $matches), 0, 160, 'UTF-8');
    }

    private static function extractUserTextDurationHint(string $prompt): string
    {
        if (preg_match('/([0-9]+)\s*(?:\xE5\x88\x86\xE9\x92\x9F|\xE5\x88\x86)(?!\xE9\x95\x9C|\xE6\xAE\xB5|\xE9\x9B\x86)/', $prompt, $match)) {
            return mb_substr(trim((string)$match[0]), 0, 80, 'UTF-8');
        }
        if (preg_match('/([0-9]+)\s*(?:\xE7\xA7\x92|s|S)(?![0-9])/', $prompt, $match)) {
            return mb_substr(trim((string)$match[0]), 0, 80, 'UTF-8');
        }
        return '';
        if (preg_match('/([0-9０-９]+)\s*(?:分钟|分)(?!镜|段|集)/u', $prompt, $match)) {
            return mb_substr(trim((string)$match[0]), 0, 80, 'UTF-8');
        }
        $patterns = [
            '/(?:时长|总时长|片长|视频时长|每集时长)\s*(?:约|大约|大概|控制在|控制|为|是|：|:)?\s*([0-9０-９]+)\s*(?:分|分钟)\s*([0-9０-９]+)?\s*(?:秒|s|S)?/u',
            '/(?:时长|总时长|片长|视频时长|每集时长)\s*(?:约|大约|大概|控制在|控制|为|是|：|:)?\s*([0-9０-９]+)\s*(?:秒|s|S)/u',
            '/([0-9０-９]+)\s*(?:分|分钟)\s*([0-9０-９]+)\s*(?:秒|s|S)/u',
            '/(?:约|大约|大概|控制)\s*([0-9０-９]+)\s*(?:分|分钟)\s*(?:左右|以内|上下)?/u',
            '/([0-9０-９]+)\s*(?:秒|s|S)/u',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $prompt, $match)) {
                return mb_substr(trim((string)$match[0]), 0, 80, 'UTF-8');
            }
        }
        if (preg_match('/([一二三四五六七八九十百两]+)\s*(?:分钟|秒)/u', $prompt, $match)) {
            return mb_substr(trim((string)$match[0]), 0, 80, 'UTF-8');
        }
        return '';
    }

    private static function extractUserTextTimePeriodHint(string $prompt): string
    {
        $keywords = [
            '现代', '当代', '古代', '古装', '民国', '未来', '近未', '末世', '90年代', '80年代',
            '70年代', '00年代', '上世', '明朝', '唐朝', '宋朝', '清朝', '校园', '都市', '乡村',
            '江湖', '宫廷', '职场', '上州', '纽约', '民国时期'
        ];
        $matches = [];
        foreach ($keywords as $keyword) {
            if (mb_stripos($prompt, $keyword, 0, 'UTF-8') !== false) {
                $matches[] = $keyword;
            }
        }
        if (preg_match_all('/(?:年代|时代|时期|背景)\s*(?:为|是|：|:)?\s*([^\n，。；;、]{2,24})/u', $prompt, $found)) {
            foreach ((array)$found[1] as $item) {
                $matches[] = trim((string)$item);
            }
        }
        $matches = array_values(array_unique(array_filter($matches)));
        return mb_substr(implode('', $matches), 0, 160, 'UTF-8');
    }

    private static function styleDetail(string $styleId): array
    {
        if ($styleId === '') {
            return [
                'id' => '',
                'name' => '',
                'prompt' => '',
            ];
        }
        $row = AigcShortDramaStyle::where('id', (int)$styleId)->findOrEmpty();
        if ($row->isEmpty()) {
            return [
                'id' => '',
                'name' => '',
                'prompt' => '',
            ];
        }
        return [
            'id' => (string)$row['id'],
            'name' => (string)$row['name'],
            'prompt' => trim((string)($row['description'] ?? '')),
        ];
    }

    private static function styleName(string $styleId): string
    {
        return (string)(self::styleDetail($styleId)['name'] ?? '');
    }

    private static function fallbackCoverUrl(): string
    {
        return 'resource/image/common/menu_generator.png';
    }

    private static function fileUrl(string $uri): string
    {
        if ($uri === '') {
            return '';
        }
        return FileService::getFileUrl($uri);
    }

    private static function timeText($time, string $format = 'Y-m-d H:i:s'): string
    {
        if (is_string($time)) {
            $text = trim($time);
            if ($text === '') {
                return '';
            }
            if (!ctype_digit($text)) {
                $timestamp = strtotime($text);
                return $timestamp ? date($format, $timestamp) : $text;
            }
            $time = (int)$text;
        }
        $time = (int)$time;
        return $time > 0 ? date($format, $time) : '';
    }

    private static function jsonDecode(string $json): array
    {
        if ($json === '') {
            return [];
        }
        $data = json_decode($json, true);
        return is_array($data) ? $data : [];
    }

    private static function jsonEncode(array $data): string
    {
        $json = json_encode(self::sanitizeUtf8Payload($data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        return is_string($json) ? $json : '[]';
    }

    private static function sanitizeUtf8Payload(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::sanitizeUtf8Payload($value);
                continue;
            }
            if (is_string($value)) {
                $data[$key] = self::sanitizeUtf8String($value);
            }
        }
        return $data;
    }

    private static function sanitizeUtf8String(string $value): string
    {
        if ($value === '') {
            return '';
        }
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value) ?? $value;
        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if (!is_string($encoded)) {
            return '';
        }
        $decoded = json_decode($encoded, true);
        if (!is_string($decoded)) {
            return '';
        }
        $decoded = str_replace("\xEF\xBF\xBD", '', $decoded);
        return preg_replace('/[\x{10000}-\x{10FFFF}]/u', '', $decoded) ?? $decoded;
    }

    private static function formatPrice(float $value): string
    {
        return number_format(max(0, $value), 2, '.', '');
    }

    private static function formatUnitPrice(float $value): string
    {
        return number_format(max(0, $value), 4, '.', '');
    }

    private static function formatBillingPoints(float $value): string
    {
        if ($value > 0 && $value < 0.01) {
            $value = 0.01;
        }
        return self::formatPrice($value);
    }
}
