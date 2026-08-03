<?php

namespace app\common\service\app\aigc_canvas;

use app\common\enum\FileEnum;
use app\common\model\app\TenantAppConfig;
use app\common\model\app\aigc_canvas\AigcCanvasAsset;
use app\common\model\app\aigc_canvas\AigcCanvasProject;
use app\common\model\app\aigc_canvas\AigcCanvasRun;
use app\common\model\ai\AiConsumptionLog;
use app\common\model\file\TenantFile;
use app\common\service\app\AppDisplayConfigService;
use app\common\service\FileService;
use app\common\service\app\aigc_canvas\agent\model\CanvasModelRouterService;
use app\common\service\app\aigc_canvas\agent\prompt\PromptSubmissionService;
use app\common\service\app\aigc_canvas\agent\prompt\PromptSubmissionException;
use app\common\service\app\aigc_canvas\agent\prompt\ProviderSubmissionValidator;
use app\common\service\power\MarketImageModelRuntimeService;
use app\common\service\power\MarketMusicAppRuntimeService;
use app\common\service\power\MarketNanoBananaAppRuntimeService;
use app\common\service\power\MarketTextModelRuntimeService;
use app\common\service\power\MarketVideoAppRuntimeService;
use app\common\service\power\MarketVideoModelRuntimeService;
use app\common\service\storage\Driver as StorageDriver;
use app\common\service\storage\StorageConfigService;
use Exception;
use think\facade\Db;

class AigcCanvasService
{
    public const APP_CODE = 'aigc_canvas';
    private const LEGACY_TEXT_REPLACEMENTS = [
        "\u{74A7}\u{52EA}\u{9A87}\u{7459}\u{55DB}\u{E576}" => '资产视频',
        '璧勪骇鍥剧墖' => '资产图片',
        '鍓湰' => '副本',
        '宸茶嚜鍔ㄤ繚瀛?' => '已自动保存',
    ];

    public static function resourceStatus(int $tenantId = 0): array
    {
        $text = self::textConfig($tenantId);
        $market = CanvasModelRouterService::marketOverview($tenantId);
        $textModels = array_values((array)($text['models'] ?? []));
        $defaultModel = (string)($text['defaults']['model'] ?? ($textModels[0]['code'] ?? ''));
        $defaultModelName = $defaultModel;
        foreach ($textModels as $model) {
            if ((string)($model['code'] ?? '') === $defaultModel) {
                $defaultModelName = (string)($model['name'] ?? $model['code'] ?? $defaultModel);
                break;
            }
        }

        $items = [
            self::resourceStatusItem(
                'Agent 文本模型',
                '用于意图理解、任务规划与文本节点生成',
                '模型 API',
                $textModels,
                'model_api',
                empty($textModels)
                    ? (string)($text['message'] ?? '暂无租户可用的文本模型')
                    : ($defaultModelName !== '' ? '已配置为 ' . $defaultModelName : '已配置 ' . count($textModels) . ' 个')
            ),
            self::resourceStatusItem(
                '图片模型 API',
                '用于图片节点、局部重绘和参考图生成',
                '算力市场模型 API',
                (array)($market['image']['options'] ?? []),
                'model_api'
            ),
            self::resourceStatusItem(
                '视频模型API/应用API',
                '用于视频节点和图生视频生成',
                '算力市场模型API和应用API',
                (array)($market['video']['options'] ?? []),
                'mixed'
            ),
            self::resourceStatusItem(
                '音乐生成应用 API',
                '用于音频节点、配乐和音乐生成',
                '算力市场应用 API',
                (array)($market['music']['options'] ?? []),
                'app_api'
            ),
        ];

        return [
            'items' => $items,
            'ready' => count(array_filter($items, fn($item) => !empty($item['ready']))) === count($items),
            'mode' => 'power_market',
            'mode_label' => '算力市场模型 API 和应用 API',
        ];
    }

    /**
     * Platform dependency view backed by the same live market data used by
     * the tenant configuration and generation runtime.
     */
    public static function dependencies(int $tenantId = 0): array
    {
        $status = self::resourceStatus($tenantId);
        $items = array_map(static function (array $item): array {
            $available = !empty($item['available']);
            return array_merge($item, [
                'installed' => $available,
                'channel_ready' => $available,
            ]);
        }, (array)($status['items'] ?? []));

        return [
            'items' => $items,
            'ready' => !empty($status['ready']),
            'mode' => (string)($status['mode'] ?? 'power_market'),
        ];
    }

    public static function config(int $tenantId): array
    {
        $resourceStatus = self::resourceStatus($tenantId);
        $text = self::textConfig($tenantId);
        $marketRouter = CanvasModelRouterService::marketOverview($tenantId);
        return AppDisplayConfigService::appendToConfig($tenantId, self::APP_CODE, [
            'app_code' => self::APP_CODE,
            'name' => '无限画布',
            'storage' => 'backend',
            'proxy_enabled' => true,
            'text' => $text,
            'agent' => self::agentConfig($tenantId),
            'agent_performance' => self::agentTurnMetrics($tenantId),
            'agent_health' => self::agentLoopHealth($tenantId),
            'resource_status' => $resourceStatus,
            'market_router' => $marketRouter,
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
            'agent_loop_enabled' => !array_key_exists('agent_loop_enabled', $saved) || !empty($saved['agent_loop_enabled']),
            'skill_binding_mode_enabled' => !array_key_exists('skill_binding_mode_enabled', $saved) || !empty($saved['skill_binding_mode_enabled']),
            'agent_intent_router_enabled' => !empty($saved['agent_intent_router_enabled']),
            'agent_generic_capability_fallback_enabled' => !array_key_exists('agent_generic_capability_fallback_enabled', $saved) || !empty($saved['agent_generic_capability_fallback_enabled']),
            // This is intentionally tenant-scoped so visual understanding can be
            // rolled back independently of the Agent loop and generation stack.
            'visual_enrichment_enabled' => !array_key_exists('visual_enrichment_enabled', $saved) || !empty($saved['visual_enrichment_enabled']),
            'agent_stream_enabled' => !array_key_exists('agent_stream_enabled', $saved) || !empty($saved['agent_stream_enabled']),
            'agent_loop_max_iterations' => max(1, min(6, (int)($saved['agent_loop_max_iterations'] ?? 6))),
            'agent_loop_timeout_seconds' => max(15, min(300, (int)($saved['agent_loop_timeout_seconds'] ?? 180))),
            'agent_loop_auto_fallback_enabled' => !array_key_exists('agent_loop_auto_fallback_enabled', $saved) || !empty($saved['agent_loop_auto_fallback_enabled']),
            'agent_loop_fallback_p95_ms' => max(2000, min(60000, (int)($saved['agent_loop_fallback_p95_ms'] ?? 15000))),
            'agent_loop_fallback_min_samples' => max(10, min(200, (int)($saved['agent_loop_fallback_min_samples'] ?? 20))),
            'router_model_code' => $routerModelCode,
            'router_models' => $models,
            'router_available' => !empty($text['enabled']) && $routerModelCode !== '',
            'onboarding' => self::normalizeOnboardingConfig($saved['onboarding'] ?? []),
            'message' => !empty($text['enabled']) ? '可用' : (string)($text['message'] ?? '暂无可用 Agent 模型'),
        ];
    }

    private static function saveAgentConfig(int $tenantId, array $params): void
    {
        $current = AppDisplayConfigService::detail($tenantId, self::APP_CODE);
        $extra = is_array($current['extra'] ?? null) ? $current['extra'] : [];
        $agent = is_array($extra['agent'] ?? null) ? $extra['agent'] : [];
        $routerModelCode = trim((string)($params['router_model_code'] ?? ''));
        if ($routerModelCode !== '') {
            $modelCodes = array_map(
                static fn(array $model): string => (string)($model['code'] ?? ''),
                array_values((array)(self::textConfig($tenantId)['models'] ?? []))
            );
            if (!in_array($routerModelCode, $modelCodes, true)) {
                throw new Exception('所选 Agent 文本模型未上架或当前租户不可用');
            }
        }
        $agent['router_enabled'] = (bool)($params['router_enabled'] ?? true);
        $agent['router_model_code'] = $routerModelCode;
        $agent['agent_loop_enabled'] = !isset($params['agent_loop_enabled']) || !empty($params['agent_loop_enabled']);
        $agent['skill_binding_mode_enabled'] = !isset($params['skill_binding_mode_enabled']) || !empty($params['skill_binding_mode_enabled']);
        $agent['agent_intent_router_enabled'] = !empty($params['agent_intent_router_enabled']);
        $agent['agent_generic_capability_fallback_enabled'] = !isset($params['agent_generic_capability_fallback_enabled']) || !empty($params['agent_generic_capability_fallback_enabled']);
        $agent['visual_enrichment_enabled'] = !isset($params['visual_enrichment_enabled']) || !empty($params['visual_enrichment_enabled']);
        $agent['agent_stream_enabled'] = !isset($params['agent_stream_enabled']) || !empty($params['agent_stream_enabled']);
        $agent['agent_loop_max_iterations'] = max(1, min(6, (int)($params['agent_loop_max_iterations'] ?? 6)));
        $agent['agent_loop_timeout_seconds'] = max(15, min(300, (int)($params['agent_loop_timeout_seconds'] ?? 180)));
        $agent['agent_loop_auto_fallback_enabled'] = !isset($params['agent_loop_auto_fallback_enabled']) || !empty($params['agent_loop_auto_fallback_enabled']);
        $agent['agent_loop_fallback_p95_ms'] = max(2000, min(60000, (int)($params['agent_loop_fallback_p95_ms'] ?? 15000)));
        $agent['agent_loop_fallback_min_samples'] = max(10, min(200, (int)($params['agent_loop_fallback_min_samples'] ?? 20)));
        if (isset($params['onboarding']) && is_array($params['onboarding'])) {
            $agent['onboarding'] = self::normalizeOnboardingConfig($params['onboarding']);
        }
        $extra['agent'] = $agent;
        AppDisplayConfigService::save($tenantId, self::APP_CODE, array_merge($current, [
            'extra' => $extra,
        ]));
    }

    /**
     * Rolling operational signals for the Agent Loop rollout. This deliberately
     * exposes aggregate timings only, never supplier/SKU information.
     */
    public static function agentTurnMetrics(int $tenantId, int $days = 7): array
    {
        $firstStatusAvailable = true;
        $queryRows = static function (string $fields) use ($tenantId, $days): array {
            return Db::name('aigc_canvas_agent_turn')
                ->where('tenant_id', $tenantId)
                ->where('delete_time', 0)
                // Health decisions must be based on the new Agent Loop only.
                // Legacy compatibility turns are tracked separately and must not
                // either hide or perpetuate the health of the loop being rolled out.
                ->whereIn('execution_mode', ['agent_loop', 'agent_loop_probe'])
                ->where('create_time', '>=', time() - max(1, min(30, $days)) * 86400)
                ->field($fields)
                ->limit(5000)
                ->select()
                ->toArray();
        };
        try {
            $rows = $queryRows('status,execution_mode,iteration_count,create_time,first_status_at_ms,first_token_at,started_at_ms,first_token_at_ms,completed_at');
        } catch (\Throwable) {
            try {
                // Existing tenants receive the additive timing field through the
                // app migration. Keep their existing token metrics readable until
                // that migration has been applied.
                $rows = $queryRows('status,execution_mode,iteration_count,create_time,first_token_at,started_at_ms,first_token_at_ms,completed_at');
                $firstStatusAvailable = false;
            } catch (\Throwable) {
                return ['available' => false, 'window_days' => $days, 'total' => 0];
            }
        }
        $firstStatus = [];
        $firstToken = [];
        $iterations = [];
        $modeCounts = [];
        $success = 0;
        $failed = 0;
        foreach ($rows as $row) {
            $status = (string)($row['status'] ?? '');
            $mode = (string)($row['execution_mode'] ?? 'unknown');
            $modeCounts[$mode] = ($modeCounts[$mode] ?? 0) + 1;
            if ($status === 'success') {
                $success++;
            } elseif ($status === 'failed') {
                $failed++;
            }
            $created = (int)($row['create_time'] ?? 0);
            $token = (int)($row['first_token_at'] ?? 0);
            $startedMs = (int)($row['started_at_ms'] ?? 0);
            $statusMs = (int)($row['first_status_at_ms'] ?? 0);
            $tokenMs = (int)($row['first_token_at_ms'] ?? 0);
            if ($startedMs > 0 && $statusMs >= $startedMs) {
                $firstStatus[] = max(0, $statusMs - $startedMs);
            }
            if ($startedMs > 0 && $tokenMs >= $startedMs) {
                $firstToken[] = max(0, $tokenMs - $startedMs);
            } elseif ($created > 0 && $token >= $created) {
                $firstToken[] = max(0, ($token - $created) * 1000);
            }
            $iterations[] = max(0, (int)($row['iteration_count'] ?? 0));
        }
        sort($firstStatus, SORT_NUMERIC);
        sort($firstToken, SORT_NUMERIC);
        $total = count($rows);
        $terminalTotal = $success + $failed;
        $firstStatusCount = count($firstStatus);
        $firstTokenCount = count($firstToken);
        $firstStatusP95 = $firstStatusCount > 0 ? $firstStatus[(int)max(0, ceil($firstStatusCount * 0.95) - 1)] : 0;
        $p95 = $firstTokenCount > 0 ? $firstToken[(int)max(0, ceil($firstTokenCount * 0.95) - 1)] : 0;
        return [
            'available' => true,
            'window_days' => $days,
            'total' => $total,
            'terminal_total' => $terminalTotal,
            'success_total' => $success,
            'failed_total' => $failed,
            'success_rate' => $terminalTotal > 0 ? round($success * 100 / $terminalTotal, 2) : 0,
            'first_status_available' => $firstStatusAvailable,
            'first_status_sample_count' => $firstStatusCount,
            'first_status_p95_ms' => $firstStatusP95,
            'first_status_avg_ms' => $firstStatusCount > 0 ? (int)round(array_sum($firstStatus) / $firstStatusCount) : 0,
            'first_token_sample_count' => $firstTokenCount,
            'first_token_p95_ms' => $p95,
            'first_token_avg_ms' => $firstTokenCount > 0 ? (int)round(array_sum($firstToken) / $firstTokenCount) : 0,
            'avg_iterations' => $total > 0 ? round(array_sum($iterations) / $total, 2) : 0,
            'execution_modes' => $modeCounts,
        ];
    }

    public static function agentLoopHealth(int $tenantId, ?array $config = null): array
    {
        $config = $config ?: self::agentConfig($tenantId);
        $metrics = self::agentTurnMetrics($tenantId);
        $minimum = (int)($config['agent_loop_fallback_min_samples'] ?? 20);
        $limit = (int)($config['agent_loop_fallback_p95_ms'] ?? 15000);
        $samples = (int)($metrics['first_token_sample_count'] ?? 0);
        $p95 = (int)($metrics['first_token_p95_ms'] ?? 0);
        if (empty($metrics['available']) || $samples < $minimum) {
            return [
                'status' => 'warming',
                'fallback' => false,
                'message' => '样本不足，继续收集新 Agent 回合数据。',
                'metrics' => $metrics,
            ];
        }
        $slow = $p95 > $limit;
        $unstable = (int)($metrics['terminal_total'] ?? 0) >= $minimum && (float)($metrics['success_rate'] ?? 100) < 50;
        if ($slow || $unstable) {
            return [
                'status' => 'degraded',
                // The compatibility router does not make an upstream text model
                // faster. Falling back because of latency therefore hides the
                // new Agent and prevents it from collecting recovery samples.
                // Reserve automatic fallback for proven execution instability.
                'fallback' => $unstable && !empty($config['agent_loop_auto_fallback_enabled']),
                'requires_attention' => $slow,
                'message' => $slow
                    ? '首响应延迟超过灰度阈值，保持 Agent Loop 并记录诊断数据。'
                    : '回合成功率低于灰度阈值，已切换到兼容链路。',
                'metrics' => $metrics,
            ];
        }
        return ['status' => 'healthy', 'fallback' => false, 'message' => '新 Agent Loop 运行正常。', 'metrics' => $metrics];
    }

    public static function onboardingConfig(int $tenantId): array
    {
        $saved = self::savedAgentConfig($tenantId);
        return self::normalizeOnboardingConfig($saved['onboarding'] ?? []);
    }

    private static function normalizeOnboardingConfig($config): array
    {
        $config = is_array($config) ? $config : [];
        $agentName = trim((string)($config['agent_name'] ?? ''));
        $welcomeText = trim((string)($config['welcome_text'] ?? ''));
        $capabilityKeys = self::normalizeStringList($config['capability_skill_keys'] ?? []);
        $quickPrompts = self::normalizeStringList($config['quick_prompts'] ?? []);
        return [
            'agent_name' => mb_substr($agentName !== '' ? $agentName : 'Canvas Agent', 0, 80, 'UTF-8'),
            'welcome_text' => mb_substr($welcomeText !== '' ? $welcomeText : '你好，我可以帮你快速生成图片、海报、视频、脚本和画布内容。', 0, 500, 'UTF-8'),
            'capability_skill_keys' => array_slice(array_values(array_unique($capabilityKeys)), 0, 12),
            'quick_prompts' => array_slice(array_values(array_unique($quickPrompts)), 0, 12),
            'show_on_empty_thread' => !isset($config['show_on_empty_thread']) || !empty($config['show_on_empty_thread']),
            'allow_llm_rewrite' => !empty($config['allow_llm_rewrite']),
        ];
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
        $name = trim(self::repairLegacyProjectText((string)($params['name'] ?? '')));
        if ($name === '') {
            $name = '未命名项目';
        }
        $project = AigcCanvasProject::create([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'name' => mb_substr($name, 0, 120),
            'thumbnail' => self::normalizeThumbnail((string)($params['thumbnail'] ?? ''), $tenantId, $userId),
            'nodes_json' => self::repairLegacyProjectText(self::normalizeList($params['nodes'] ?? [])),
            'edges_json' => self::repairLegacyProjectText(self::normalizeList($params['edges'] ?? [])),
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
            'nodes_json' => self::repairLegacyProjectText(self::normalizeList($params['nodes'] ?? [])),
            'edges_json' => self::repairLegacyProjectText(self::normalizeList($params['edges'] ?? [])),
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
            $name = trim(self::repairLegacyProjectText((string)$params['name']));
            if ($name !== '') {
                $data['name'] = mb_substr($name, 0, 120);
            }
        }
        $project->save($data);
        return self::formatProject($project->toArray(), true);
    }

    public static function renameProject(int $tenantId, int $userId, int $id, string $name): array
    {
        $name = trim(self::repairLegacyProjectText($name));
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
        ] + self::agentOperationStats($tenantId);
    }

    private static function agentOperationStats(int $tenantId = 0): array
    {
        $empty = [
            'agent_run_total' => 0,
            'agent_run_success' => 0,
            'agent_run_failed' => 0,
            'agent_success_rate' => 0,
            'agent_clarification_total' => 0,
            'agent_clarification_rate' => 0,
            'agent_clarification_completion_rate' => 0,
            'agent_cancellation_rate' => 0,
            'agent_skill_switch_rate' => 0,
            'skill_hit_stats' => [],
            'agent_step_stats' => [],
            'agent_tool_stats' => [],
            'failure_reasons' => [],
            'recent_failures' => [],
        ];
        try {
            $agentRunQuery = Db::name('aigc_canvas_agent_run')->where('delete_time', 0);
            $stepQuery = Db::name('aigc_canvas_agent_step');
            $toolQuery = Db::name('aigc_canvas_agent_tool_call')->where('delete_time', 0);
            $canvasRunQuery = Db::name('aigc_canvas_run')->where('delete_time', 0);
            if ($tenantId > 0) {
                $agentRunQuery->where('tenant_id', $tenantId);
                $stepQuery->where('tenant_id', $tenantId);
                $toolQuery->where('tenant_id', $tenantId);
                $canvasRunQuery->where('tenant_id', $tenantId);
            }

            $agentRuns = (clone $agentRunQuery)
                ->field('status,count(*) as total')
                ->group('status')
                ->select()
                ->toArray();
            $agentTotal = 0;
            $agentSuccess = 0;
            $agentFailed = 0;
            foreach ($agentRuns as $row) {
                $count = (int)($row['total'] ?? 0);
                $agentTotal += $count;
                if ((string)($row['status'] ?? '') === 'success') {
                    $agentSuccess += $count;
                }
                if ((string)($row['status'] ?? '') === 'failed') {
                    $agentFailed += $count;
                }
            }
            $skillAnalytics = self::agentSkillAnalytics($tenantId);

            $stepStats = (clone $stepQuery)
                ->field('step_type,status,count(*) as total')
                ->group('step_type,status')
                ->order(['step_type' => 'asc'])
                ->select()
                ->toArray();
            $toolStats = (clone $toolQuery)
                ->field('tool_code,status,count(*) as total')
                ->fieldRaw('AVG(CASE WHEN finished_at > started_at AND started_at > 0 THEN (finished_at - started_at) ELSE NULL END) AS avg_seconds')
                ->group('tool_code,status')
                ->order(['tool_code' => 'asc'])
                ->select()
                ->toArray();
            $failureReasons = (clone $canvasRunQuery)
                ->where('status', 'failed')
                ->where('error', '<>', '')
                ->field('LEFT(error, 120) as reason,count(*) as total')
                ->group('reason')
                ->order('total', 'desc')
                ->limit(10)
                ->select()
                ->toArray();
            $recentFailures = (clone $canvasRunQuery)
                ->where('status', 'failed')
                ->field('id,run_type,prompt,error,update_time,source_app_code,source_task_id')
                ->order('update_time', 'desc')
                ->limit(8)
                ->select()
                ->toArray();

            return [
                'agent_run_total' => $agentTotal,
                'agent_run_success' => $agentSuccess,
                'agent_run_failed' => $agentFailed,
                'agent_success_rate' => $agentTotal > 0 ? round($agentSuccess / $agentTotal * 100, 2) : 0,
                'agent_clarification_total' => (int)($skillAnalytics['clarification_total'] ?? 0),
                'agent_clarification_rate' => (float)($skillAnalytics['clarification_rate'] ?? 0),
                'agent_clarification_completion_rate' => (float)($skillAnalytics['clarification_completion_rate'] ?? 0),
                'agent_cancellation_rate' => (float)($skillAnalytics['cancellation_rate'] ?? 0),
                'agent_skill_switch_rate' => (float)($skillAnalytics['skill_switch_rate'] ?? 0),
                'skill_hit_stats' => (array)($skillAnalytics['skill_hit_stats'] ?? []),
                'agent_step_stats' => array_map([self::class, 'formatStatRow'], $stepStats),
                'agent_tool_stats' => array_map([self::class, 'formatToolStatRow'], $toolStats),
                'failure_reasons' => array_map([self::class, 'formatStatRow'], $failureReasons),
                'recent_failures' => array_map([self::class, 'formatRecentFailure'], $recentFailures),
            ];
        } catch (\Throwable) {
            return $empty;
        }
    }

    private static function agentSkillAnalytics(int $tenantId = 0): array
    {
        $query = Db::name('aigc_canvas_agent_message')
            ->where('delete_time', 0)
            ->where('role', 'assistant');
        if ($tenantId > 0) {
            $query->where('tenant_id', $tenantId);
        }
        $rows = $query
            ->field('id,thread_id,status,content_json,create_time')
            ->order('id', 'asc')
            ->limit(5000)
            ->select()
            ->toArray();
        $stats = [];
        $total = 0;
        $clarificationTotal = 0;
        $clarificationCompleted = 0;
        $canceledTotal = 0;
        $skillSwitches = 0;
        $lastSkillByThread = [];
        $pendingClarificationByThread = [];
        foreach ($rows as $row) {
            $json = self::normalizeRunPayload($row['content_json'] ?? []);
            $decision = is_array($json['task_decision'] ?? null) ? $json['task_decision'] : [];
            $skillKey = trim((string)($json['skill_key'] ?? $decision['selected_skill_key'] ?? ''));
            $skillCode = trim((string)($json['skill_code'] ?? ''));
            if ($skillKey === '' && $skillCode === '') {
                continue;
            }
            $key = $skillKey !== '' ? $skillKey : $skillCode;
            if ($key === '' || $key === 'chat') {
                continue;
            }
            if (!isset($stats[$key])) {
                $stats[$key] = [
                    'skill_key' => $skillKey,
                    'skill_code' => $skillCode,
                    'total' => 0,
                    'success_total' => 0,
                    'failed_total' => 0,
                    'clarification_total' => 0,
                    'confidence_sum' => 0,
                    'confidence_count' => 0,
                    'recent_time' => 0,
                ];
            }
            $stats[$key]['total']++;
            $total++;
            $threadId = (int)($row['thread_id'] ?? 0);
            if ($threadId > 0 && isset($lastSkillByThread[$threadId]) && $lastSkillByThread[$threadId] !== $key) {
                $skillSwitches++;
            }
            if ($threadId > 0) {
                $lastSkillByThread[$threadId] = $key;
            }
            if ((string)($row['status'] ?? '') === 'success') {
                $stats[$key]['success_total']++;
            }
            if ((string)($row['status'] ?? '') === 'failed') {
                $stats[$key]['failed_total']++;
            }
            $needsClarification = (string)($json['next_action'] ?? '') === 'clarify'
                || trim((string)($json['clarify_question'] ?? '')) !== ''
                || !empty($json['missing_slots']);
            if ($needsClarification) {
                $stats[$key]['clarification_total']++;
                $clarificationTotal++;
                if ($threadId > 0) {
                    $pendingClarificationByThread[$threadId] = true;
                }
            } elseif ($threadId > 0 && !empty($pendingClarificationByThread[$threadId]) && (string)($row['status'] ?? '') === 'success') {
                $clarificationCompleted++;
                unset($pendingClarificationByThread[$threadId]);
            }
            if ((string)($row['status'] ?? '') === 'canceled') {
                $canceledTotal++;
            }
            if (isset($json['confidence']) && is_numeric($json['confidence'])) {
                $stats[$key]['confidence_sum'] += (float)$json['confidence'];
                $stats[$key]['confidence_count']++;
            }
            $stats[$key]['recent_time'] = max((int)$stats[$key]['recent_time'], (int)($row['create_time'] ?? 0));
        }
        $items = array_values(array_map(static function (array $item): array {
            $total = max(1, (int)$item['total']);
            return [
                'skill_key' => (string)($item['skill_key'] ?: $item['skill_code']),
                'skill_code' => (string)$item['skill_code'],
                'total' => (int)$item['total'],
                'success_total' => (int)$item['success_total'],
                'failed_total' => (int)$item['failed_total'],
                'clarification_total' => (int)$item['clarification_total'],
                'success_rate' => round((int)$item['success_total'] / $total * 100, 2),
                'clarification_rate' => round((int)$item['clarification_total'] / $total * 100, 2),
                'avg_confidence' => (int)$item['confidence_count'] > 0
                    ? round((float)$item['confidence_sum'] / (int)$item['confidence_count'], 4)
                    : 0,
                'recent_time' => (int)$item['recent_time'],
            ];
        }, $stats));
        usort($items, static fn(array $a, array $b): int => ((int)$b['total'] <=> (int)$a['total']) ?: ((int)$b['recent_time'] <=> (int)$a['recent_time']));

        return [
            'clarification_total' => $clarificationTotal,
            'clarification_rate' => $total > 0 ? round($clarificationTotal / $total * 100, 2) : 0,
            'clarification_completion_rate' => $clarificationTotal > 0 ? round($clarificationCompleted / $clarificationTotal * 100, 2) : 0,
            'cancellation_rate' => $total > 0 ? round($canceledTotal / $total * 100, 2) : 0,
            'skill_switch_rate' => $total > 1 ? round($skillSwitches / ($total - 1) * 100, 2) : 0,
            'skill_hit_stats' => array_slice($items, 0, 20),
        ];
    }

    private static function formatAgentTraceRow(array $row, bool $detail = false): array
    {
        $data = [
            'id' => (int)($row['id'] ?? 0),
            'tenant_id' => (int)($row['tenant_id'] ?? 0),
            'user_id' => (int)($row['user_id'] ?? 0),
            'project_id' => (int)($row['project_id'] ?? 0),
            'thread_id' => (int)($row['thread_id'] ?? 0),
            'request_id' => (string)($row['request_id'] ?? ''),
            'agent_code' => (string)($row['agent_code'] ?? ''),
            'parent_run_id' => (int)($row['parent_run_id'] ?? 0),
            'sub_agent_code' => (string)($row['sub_agent_code'] ?? ''),
            'depth' => (int)($row['depth'] ?? 0),
            'sequence' => (int)($row['sequence'] ?? 0),
            'status' => (string)($row['status'] ?? ''),
            'error' => (string)($row['error'] ?? ''),
            'user_nickname' => (string)($row['user_nickname'] ?? ''),
            'user_account' => (string)($row['user_account'] ?? ''),
            'user_mobile' => (string)($row['user_mobile'] ?? ''),
            'create_time' => (int)($row['create_time'] ?? 0),
            'update_time' => (int)($row['update_time'] ?? 0),
            'duration_seconds' => max(0, (int)($row['update_time'] ?? 0) - (int)($row['create_time'] ?? 0)),
        ];
        if ($detail) {
            $data['input_json'] = self::normalizeRunPayload($row['input_json'] ?? []);
            $data['output_json'] = self::normalizeRunPayload($row['output_json'] ?? []);
        }
        return $data;
    }

    private static function formatAgentStep(array $row): array
    {
        return [
            'id' => (int)($row['id'] ?? 0),
            'agent_code' => (string)($row['agent_code'] ?? ''),
            'step_type' => (string)($row['step_type'] ?? ''),
            'status' => (string)($row['status'] ?? ''),
            'input_json' => self::normalizeRunPayload($row['input_json'] ?? []),
            'output_json' => self::normalizeRunPayload($row['output_json'] ?? []),
            'create_time' => (int)($row['create_time'] ?? 0),
        ];
    }

    private static function formatAgentToolCall(array $row): array
    {
        return [
            'id' => (int)($row['id'] ?? 0),
            'tool_code' => (string)($row['tool_code'] ?? ''),
            'request_id' => (string)($row['request_id'] ?? ''),
            'status' => (string)($row['status'] ?? ''),
            'input_json' => self::normalizeRunPayload($row['input_json'] ?? []),
            'output_json' => self::normalizeRunPayload($row['output_json'] ?? []),
            'error' => (string)($row['error'] ?? ''),
            'error_code' => (string)($row['error_code'] ?? ''),
            'provider_task_id' => (string)($row['provider_task_id'] ?? ''),
            'retry_count' => (int)($row['retry_count'] ?? 0),
            'duration_seconds' => max(0, (int)($row['finished_at'] ?? 0) - (int)($row['started_at'] ?? 0)),
            'create_time' => (int)($row['create_time'] ?? 0),
            'update_time' => (int)($row['update_time'] ?? 0),
        ];
    }

    private static function formatWorkspaceAction(array $row): array
    {
        return [
            'id' => (int)($row['id'] ?? 0),
            'action_type' => (string)($row['action_type'] ?? ''),
            'status' => (string)($row['status'] ?? ''),
            'payload_json' => self::normalizeRunPayload($row['payload_json'] ?? []),
            'error' => (string)($row['error'] ?? ''),
            'create_time' => (int)($row['create_time'] ?? 0),
            'update_time' => (int)($row['update_time'] ?? 0),
        ];
    }

    private static function agentTraceToolCalls(int $tenantId, array $run): array
    {
        try {
            $requestId = trim((string)($run['request_id'] ?? ''));
            if ($requestId !== '') {
                try {
                    $rows = Db::name('aigc_canvas_agent_tool_call')
                        ->where('tenant_id', $tenantId)
                        ->where('request_id', $requestId)
                        ->where('delete_time', 0)
                        ->order('id', 'asc')
                        ->limit(100)
                        ->select()
                        ->toArray();
                    if (!empty($rows)) {
                        return array_map([self::class, 'formatAgentToolCall'], $rows);
                    }
                } catch (\Throwable) {
                }
            }
            $query = Db::name('aigc_canvas_agent_tool_call')
                ->where('tenant_id', $tenantId)
                ->where('delete_time', 0);
            if ($requestId !== '') {
                $query->whereLike('input_json', '%' . $requestId . '%');
            } else {
                $query->where([
                    'user_id' => (int)($run['user_id'] ?? 0),
                    'project_id' => (int)($run['project_id'] ?? 0),
                    'thread_id' => (int)($run['thread_id'] ?? 0),
                ])->whereBetween('create_time', [
                    max(0, (int)($run['create_time'] ?? 0) - 5),
                    max((int)($run['update_time'] ?? 0), (int)($run['create_time'] ?? 0)) + 60,
                ]);
            }
            return array_map([self::class, 'formatAgentToolCall'], $query->order('id', 'asc')->limit(100)->select()->toArray());
        } catch (\Throwable) {
            return [];
        }
    }

    private static function agentTraceWorkspaceActions(int $tenantId, array $run): array
    {
        try {
            return array_map([self::class, 'formatWorkspaceAction'], Db::name('aigc_canvas_agent_workspace_action')
                ->where([
                    'tenant_id' => $tenantId,
                    'user_id' => (int)($run['user_id'] ?? 0),
                    'project_id' => (int)($run['project_id'] ?? 0),
                    'thread_id' => (int)($run['thread_id'] ?? 0),
                    'delete_time' => 0,
                ])
                ->whereBetween('create_time', [
                    max(0, (int)($run['create_time'] ?? 0) - 5),
                    max((int)($run['update_time'] ?? 0), (int)($run['create_time'] ?? 0)) + 120,
                ])
                ->order('id', 'asc')
                ->limit(100)
                ->select()
                ->toArray());
        } catch (\Throwable) {
            return [];
        }
    }

    private static function formatStatRow(array $row): array
    {
        $row['total'] = (int)($row['total'] ?? 0);
        return $row;
    }

    private static function formatToolStatRow(array $row): array
    {
        $row['total'] = (int)($row['total'] ?? 0);
        $row['avg_seconds'] = isset($row['avg_seconds']) ? round((float)$row['avg_seconds'], 2) : 0;
        return $row;
    }

    private static function formatRecentFailure(array $row): array
    {
        return [
            'id' => (int)($row['id'] ?? 0),
            'run_type' => (string)($row['run_type'] ?? ''),
            'prompt' => mb_substr(trim((string)($row['prompt'] ?? '')), 0, 120, 'UTF-8'),
            'error' => mb_substr(trim((string)($row['error'] ?? '')), 0, 200, 'UTF-8'),
            'update_time' => (int)($row['update_time'] ?? 0),
            'source_app_code' => (string)($row['source_app_code'] ?? ''),
            'source_task_id' => (int)($row['source_task_id'] ?? 0),
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

    public static function agentTraceLists(int $tenantId, array $params = []): array
    {
        try {
            $query = Db::name('aigc_canvas_agent_run')->alias('r')
                ->leftJoin('user u', 'u.id = r.user_id AND u.tenant_id = r.tenant_id')
                ->field('r.*,u.nickname user_nickname,u.account user_account,u.mobile user_mobile')
                ->where('r.tenant_id', $tenantId)
                ->where('r.delete_time', 0);

            $status = trim((string)($params['status'] ?? ''));
            if ($status !== '' && $status !== 'all') {
                $query->where('r.status', $status);
            }
            $requestId = trim((string)($params['request_id'] ?? ''));
            if ($requestId !== '') {
                $query->whereLike('r.request_id', '%' . $requestId . '%');
            }
            $projectId = (int)($params['project_id'] ?? 0);
            if ($projectId > 0) {
                $query->where('r.project_id', $projectId);
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

            $pageNo = max(1, (int)($params['page_no'] ?? 1));
            $pageSize = max(1, min(100, (int)($params['page_size'] ?? 15)));
            $count = (int)(clone $query)->count();
            $rows = $query
                ->order('r.id', 'desc')
                ->limit(($pageNo - 1) * $pageSize, $pageSize)
                ->select()
                ->toArray();

            return [
                'lists' => array_map([self::class, 'formatAgentTraceRow'], $rows),
                'count' => $count,
                'page_no' => $pageNo,
                'page_size' => $pageSize,
            ];
        } catch (\Throwable) {
            return ['lists' => [], 'count' => 0, 'page_no' => 1, 'page_size' => 15];
        }
    }

    public static function agentTraceDetail(int $tenantId, int $id): array
    {
        $row = Db::name('aigc_canvas_agent_run')
            ->where(['tenant_id' => $tenantId, 'id' => $id, 'delete_time' => 0])
            ->find();
        if (empty($row)) {
            throw new Exception('Agent Trace 不存在');
        }
        $detail = self::formatAgentTraceRow($row, true);
        $detail['steps'] = array_map([self::class, 'formatAgentStep'], Db::name('aigc_canvas_agent_step')
            ->where(['tenant_id' => $tenantId, 'run_id' => $id])
            ->order('id', 'asc')
            ->select()
            ->toArray());
        $detail['tool_calls'] = self::agentTraceToolCalls($tenantId, $row);
        $detail['workspace_actions'] = self::agentTraceWorkspaceActions($tenantId, $row);
        $detail['turns'] = self::agentTraceTurns($tenantId, $row);
        $childRuns = Db::name('aigc_canvas_agent_run')
            ->where(['tenant_id' => $tenantId, 'parent_run_id' => $id, 'delete_time' => 0])
            ->order('sequence', 'asc')
            ->order('id', 'asc')
            ->select()
            ->toArray();
        $detail['sub_agents'] = array_map([self::class, 'formatAgentTraceRow'], $childRuns);
        return $detail;
    }

    private static function agentTraceTurns(int $tenantId, array $run): array
    {
        $requestId = trim((string)($run['request_id'] ?? ''));
        if ($requestId === '') {
            return [];
        }
        $fields = 'id,status,execution_mode,iteration_count,started_at_ms,first_status_at_ms,first_token_at_ms,completed_at_ms,fallback_reason,error,create_time';
        try {
            $rows = Db::name('aigc_canvas_agent_turn')
                ->where([
                    'tenant_id' => $tenantId,
                    'user_id' => (int)($run['user_id'] ?? 0),
                    'thread_id' => (int)($run['thread_id'] ?? 0),
                    'request_id' => $requestId,
                    'delete_time' => 0,
                ])
                ->field($fields)
                ->order('id', 'asc')
                ->limit(12)
                ->select()
                ->toArray();
        } catch (\Throwable) {
            try {
                $rows = Db::name('aigc_canvas_agent_turn')
                    ->where([
                        'tenant_id' => $tenantId,
                        'user_id' => (int)($run['user_id'] ?? 0),
                        'thread_id' => (int)($run['thread_id'] ?? 0),
                        'request_id' => $requestId,
                        'delete_time' => 0,
                    ])
                    ->field('id,status,execution_mode,iteration_count,started_at_ms,first_token_at_ms,completed_at_ms,fallback_reason,error,create_time')
                    ->order('id', 'asc')
                    ->limit(12)
                    ->select()
                    ->toArray();
            } catch (\Throwable) {
                return [];
            }
        }

        return array_map(static function (array $item): array {
            $started = (int)($item['started_at_ms'] ?? 0);
            $firstStatus = (int)($item['first_status_at_ms'] ?? 0);
            $firstToken = (int)($item['first_token_at_ms'] ?? 0);
            $completed = (int)($item['completed_at_ms'] ?? 0);
            return [
                'id' => (int)($item['id'] ?? 0),
                'status' => (string)($item['status'] ?? ''),
                'execution_mode' => (string)($item['execution_mode'] ?? ''),
                'iteration_count' => (int)($item['iteration_count'] ?? 0),
                'first_status_ms' => $started > 0 && $firstStatus >= $started ? $firstStatus - $started : null,
                'first_token_ms' => $started > 0 && $firstToken >= $started ? $firstToken - $started : null,
                'duration_ms' => $started > 0 && $completed >= $started ? $completed - $started : null,
                'fallback_reason' => (string)($item['fallback_reason'] ?? ''),
                'error' => (string)($item['error'] ?? ''),
                'create_time' => (int)($item['create_time'] ?? 0),
            ];
        }, $rows);
    }

    public static function generateImage(int $tenantId, int $userId, array $params): array
    {
        $params = self::prepareMentionParams($params);
        $modelSelectionExplicit = self::hasExplicitImageModelSelection($params);
        $params['__request_user_id'] = $userId;
        $params = PromptSubmissionService::prepareImageRequest($tenantId, $params);
        $params = CanvasModelRouterService::applyToToolInput($tenantId, 'generate_image', $params);
        $started = microtime(true);
        $run = self::createRun($tenantId, $userId, $params, 'image', 'power_market_image');
        try {
            $attempts = self::imageMarketAttemptInputs($tenantId, $params, $modelSelectionExplicit);
            if ($attempts === []) {
                throw new Exception('暂无可用的算力市场图片模型资源');
            }

            $errors = [];
            $lastError = null;
            foreach ($attempts as $index => $attemptParams) {
                try {
                    if (empty($attemptParams['market_sku_id']) && empty($attemptParams['sku_id'])) {
                        throw new Exception('暂无可用的算力市场图片模型资源');
                    }
                    if ($index > 0) {
                        $run->save([
                            'params_json' => self::sanitizeRunPayload($attemptParams),
                            'update_time' => time(),
                        ]);
                    }
                    // A fallback may use a different SKU with different ratio and
                    // reference-image capabilities, so its preflight cannot reuse
                    // the first channel's result.
                    $attemptParams['preflight'] = ProviderSubmissionValidator::validateImage($tenantId, $attemptParams);
                    $result = self::generateMarketImage($tenantId, $userId, $run, $attemptParams);
                    $result = self::withImageSubmissionSnapshot($result, $attemptParams);
                    if ($index > 0) {
                        $result['fallback_used'] = true;
                        $result['fallback_message'] = '已自动切换到可用的算力市场图片模型';
                    }
                    self::finishRun($run, $result, self::normalizeRunStatus((string)($result['status'] ?? ''), $result), (string)($result['error'] ?? ''), $started);
                    return $result;
                } catch (PromptSubmissionException $e) {
                    if ($index < count($attempts) - 1) {
                        $errors[] = self::imageMarketAttemptMessage($attemptParams, $e->getMessage());
                        $lastError = $e;
                        continue;
                    }
                    throw $e;
                } catch (Exception $e) {
                    $message = self::marketImageErrorMessage($e->getMessage());
                    $errors[] = self::imageMarketAttemptMessage($attemptParams, $message);
                    $lastError = new Exception($message);
                    if (!self::shouldTryNextImageMarket($e->getMessage()) || $index >= count($attempts) - 1) {
                        break;
                    }
                }
            }

            $message = self::imageMarketFallbackErrorMessage($errors, $lastError);
            throw new Exception($message);
        } catch (PromptSubmissionException $e) {
            self::finishRun($run, [], 'failed', $e->getMessage(), $started);
            throw $e;
        } catch (Exception $e) {
            $message = self::marketImageErrorMessage($e->getMessage());
            self::finishRun($run, [], 'failed', $message, $started);
            throw new Exception($message);
        }
    }

    private static function generateMarketImage(int $tenantId, int $userId, AigcCanvasRun $run, array $params): array
    {
        $request = self::normalizeImageParams($params, $tenantId, $userId);
        if (is_array($params['provider_params'] ?? null)) {
            $request['provider_params'] = (array)$params['provider_params'];
        }
        $runtime = MarketNanoBananaAppRuntimeService::isSelection($params)
            ? MarketNanoBananaAppRuntimeService::class
            : MarketImageModelRuntimeService::class;
        $reserve = $runtime::reserve(
            $tenantId,
            $userId,
            'generate',
            (string)$run['id'],
            $params,
            $request,
            (int)$request['quantity'],
            self::APP_CODE,
            'aigc_canvas_run'
        );
        $runtime::linkBusinessTask((int)$reserve['app_task_id'], (int)$run['id']);
        $result = $runtime::submit((int)$reserve['consumption_id'], $request);
        return self::formatMarketImageResult((int)$reserve['consumption_id'], $reserve, $result);
    }

    private static function imageMarketAttemptInputs(int $tenantId, array $params, bool $modelSelectionExplicit = false): array
    {
        $attempts = [];
        $seen = [];
        $requestedRatio = self::normalizedImageAspectRatio((string)($params['ratio'] ?? $params['size'] ?? $params['aspect_ratio'] ?? ''));
        $push = static function (array $candidate) use (&$attempts, &$seen): void {
            $skuId = (int)($candidate['market_sku_id'] ?? $candidate['sku_id'] ?? 0);
            $productId = (int)($candidate['market_product_id'] ?? 0);
            $key = $skuId > 0 ? 'sku:' . $skuId : 'product:' . $productId . ':' . (string)($candidate['channel'] ?? $candidate['model_id'] ?? '');
            if ($key === 'product:0:' || isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            $attempts[] = $candidate;
        };

        $push($params);
        // A user-selected model is also a billing and capability choice. Do not
        // silently submit the same request to another model when it is unavailable.
        if ($modelSelectionExplicit) {
            return $attempts;
        }
        try {
            foreach (MarketImageModelRuntimeService::options($tenantId) as $option) {
                if (!is_array($option)) {
                    continue;
                }
                $candidate = $params;
                $candidate['channel'] = (string)($option['id'] ?? '');
                $candidate['model_id'] = (string)($option['id'] ?? '');
                $candidate['market_product_id'] = (int)($option['market_product_id'] ?? 0);
                unset($candidate['market_sku_id'], $candidate['sku_id']);
                if (is_array($option['skus'] ?? null) && $option['skus'] !== []) {
                    $sku = self::fallbackImageSkuForRatio((array)$option, $requestedRatio);
                    // Do not submit a different aspect ratio just because a fallback
                    // channel is available. The original request must remain intact.
                    if ($sku === []) {
                        continue;
                    }
                    $candidate['market_sku_id'] = (int)($sku['market_sku_id'] ?? 0);
                    $candidate['sku_id'] = (int)($sku['market_sku_id'] ?? 0);
                }
                $push(CanvasModelRouterService::applyToToolInput($tenantId, 'generate_image', $candidate));
            }
        } catch (\Throwable) {
        }
        return $attempts;
    }

    private static function hasExplicitImageModelSelection(array $params): bool
    {
        // Agent requests always send this flag, including false for a project default.
        // Older/direct callers remain compatible: an explicit model/SKU locks the task.
        if (array_key_exists('model_selection_explicit', $params)) {
            return filter_var($params['model_selection_explicit'], FILTER_VALIDATE_BOOL);
        }
        if (!empty($params['market_sku_id']) || !empty($params['sku_id']) || !empty($params['image_sku_id']) || !empty($params['market_product_id'])) {
            return true;
        }
        foreach (['channel', 'model', 'model_id', 'image_model_id'] as $key) {
            if (trim((string)($params[$key] ?? '')) !== '') {
                return true;
            }
        }
        return false;
    }

    /** Select a fallback SKU that can honor the user-requested aspect ratio. */
    private static function fallbackImageSkuForRatio(array $option, string $requestedRatio): array
    {
        $skus = array_values(array_filter((array)($option['skus'] ?? []), 'is_array'));
        if ($skus === []) {
            return [];
        }
        if ($requestedRatio === '') {
            return (array)$skus[0];
        }
        foreach ($skus as $sku) {
            $ratios = array_values(array_filter(array_map(
                static fn($value): string => self::normalizedImageAspectRatio(
                    is_array($value) ? (string)($value['value'] ?? $value['ratio'] ?? '') : (string)$value
                ),
                (array)($sku['ratio_options'] ?? [])
            )));
            // An SKU without a fixed ratio forwards the requested ratio to the provider.
            if ($ratios === [] || in_array($requestedRatio, $ratios, true)) {
                return (array)$sku;
            }
        }
        return [];
    }

    private static function normalizedImageAspectRatio(string $ratio): string
    {
        $ratio = trim($ratio);
        if (preg_match('/^(\d+(?:\.\d+)?)\s*(?::|x|X|\/)\s*(\d+(?:\.\d+)?)$/', $ratio, $matches) !== 1) {
            return $ratio;
        }
        $width = (float)$matches[1];
        $height = (float)$matches[2];
        if ($width <= 0 || $height <= 0) {
            return $ratio;
        }
        return rtrim(rtrim((string)$width, '0'), '.') . ':' . rtrim(rtrim((string)$height, '0'), '.');
    }

    private static function shouldTryNextImageMarket(string $message): bool
    {
        $message = trim($message);
        if ($message === '') {
            return false;
        }
        foreach (['暂无可用渠道', '无可用渠道', '可用渠道', '渠道不可用', 'no available channel', 'channel unavailable'] as $needle) {
            if (stripos($message, $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    private static function imageMarketAttemptMessage(array $params, string $message): string
    {
        $parts = array_values(array_filter([
            (string)($params['channel'] ?? $params['model_id'] ?? ''),
            !empty($params['market_product_id']) ? 'product#' . (int)$params['market_product_id'] : '',
        ]));
        return ($parts === [] ? '图片模型' : implode(' / ', $parts)) . '：' . $message;
    }

    private static function imageMarketFallbackErrorMessage(array $errors, ?Exception $lastError): string
    {
        $errors = array_values(array_filter(array_unique(array_map('trim', $errors))));
        if ($errors === []) {
            return $lastError ? $lastError->getMessage() : '算力市场图片生成失败';
        }
        return '算力市场图片模型均暂不可用：' . implode('；', array_slice($errors, 0, 3));
    }

    private static function formatMarketImageResult(int $consumptionId, array $reserve, array $result): array
    {
        $items = [];
        foreach ((array)($result['images'] ?? []) as $index => $row) {
            if (!is_array($row)) {
                continue;
            }
            $rawUrl = (string)($row['image_url'] ?? $row['url'] ?? $row['file_url'] ?? $row['image_uri'] ?? '');
            $url = self::formatRunUrl($rawUrl, $row);
            if ($url === '') {
                continue;
            }
            $items[] = array_merge($row, [
                'id' => (int)($row['id'] ?? ($index + 1)),
                'url' => $url,
                'image_url' => $url,
            ]);
        }
        $snapshot = (array)($reserve['market_snapshot'] ?? []);
        $status = (string)($result['status'] ?? (empty($items) ? 'running' : 'success'));
        return [
            'task_id' => $consumptionId,
            'provider_task_id' => (string)($result['provider_task_id'] ?? ''),
            'provider_request_id' => (string)($result['provider_request_id'] ?? ''),
            'status' => $status,
            'error' => $status === 'success' ? '' : (string)($result['error'] ?? ''),
            'market_product_id' => (int)($snapshot['product_id'] ?? 0),
            'market_sku_id' => (int)($snapshot['sku_id'] ?? 0),
            'images' => $items,
            'results' => $items,
        ];
    }

    private static function marketImageErrorMessage(string $message): string
    {
        $message = trim($message);
        if (str_contains($message, '暂无可用渠道') || str_contains($message, '可用渠道')) {
            return '算力市场图片模型上游渠道不可用，请重新同步该模型资源，或改用已上架的图片应用 API';
        }
        return $message !== '' ? $message : '算力市场图片生成失败';
    }

    public static function generateVideo(int $tenantId, int $userId, array $params): array
    {
        $params = self::prepareMentionParams($params);
        $params['__request_user_id'] = $userId;
        $params = \app\common\service\app\aigc_canvas\agent\prompt\VideoPromptSubmissionService::prepareVideoRequest($tenantId, $params);
        $params = CanvasModelRouterService::applyToToolInput($tenantId, 'generate_video', $params);
        $started = microtime(true);
        $run = self::createRun($tenantId, $userId, $params, 'video', 'power_market_video');
        try {
            if (!self::hasMarketVideoSelection($params)) {
                throw new Exception('暂无可用的算力市场视频模型API或应用API资源');
            }
            $result = self::withVideoSubmissionSnapshot(self::generateMarketVideo($tenantId, $userId, $run, $params), $params);
            self::finishRun($run, $result, self::normalizeRunStatus((string)($result['status'] ?? ''), $result), (string)($result['error'] ?? ''), $started);
            return $result;
        } catch (Exception $e) {
            self::finishRun($run, [], 'failed', $e->getMessage(), $started);
            throw $e;
        }
    }

    public static function generateMusic(int $tenantId, int $userId, array $params): array
    {
        $params = self::prepareMentionParams($params);
        $params = CanvasModelRouterService::applyToToolInput($tenantId, 'generate_music', $params);
        $started = microtime(true);
        $run = self::createRun($tenantId, $userId, $params, 'music', 'power_market_music');
        try {
            if (empty($params['market_sku_id']) && empty($params['sku_id'])) {
                throw new Exception('暂无可用的算力市场音乐生成 API 资源');
            }
            $result = self::generateMarketMusic($tenantId, $userId, $run, $params);
            self::finishRun($run, $result, self::normalizeRunStatus((string)($result['status'] ?? ''), $result), (string)($result['error'] ?? ''), $started);
            return $result;
        } catch (Exception $e) {
            self::finishRun($run, [], 'failed', $e->getMessage(), $started);
            throw $e;
        }
    }

    private static function generateMarketVideo(int $tenantId, int $userId, AigcCanvasRun $run, array $params): array
    {
        $request = self::normalizeVideoParams($params, $tenantId, $userId);
        if (is_array($params['provider_params'] ?? null)) {
            $request['provider_params'] = (array)$params['provider_params'];
        }
        $runtime = self::marketVideoRuntime($params);
        $reserve = $runtime::reserve(
            $tenantId,
            $userId,
            self::APP_CODE,
            'video_generate',
            'aigc_canvas_run',
            (string)$run['id'],
            $params,
            $request
        );
        $runtime::linkBusinessTask((int)$reserve['app_task_id'], (int)$run['id']);
        $result = $runtime::submit((int)$reserve['consumption_id'], $request);
        return self::formatMarketVideoResult((int)$reserve['consumption_id'], $reserve, $result);
    }

    private static function formatMarketVideoResult(int $consumptionId, array $reserve, array $result): array
    {
        $items = [];
        foreach ((array)($result['videos'] ?? []) as $index => $row) {
            if (!is_array($row)) {
                continue;
            }
            $rawUrl = (string)($row['video_url'] ?? $row['url'] ?? $row['file_url'] ?? $row['video_uri'] ?? '');
            $url = self::formatRunUrl($rawUrl, $row);
            if ($url === '') {
                continue;
            }
            $items[] = array_merge($row, [
                'id' => (int)($row['id'] ?? ($index + 1)),
                'url' => $url,
                'video_url' => $url,
                'file_url' => $url,
            ]);
        }
        $snapshot = (array)($reserve['market_snapshot'] ?? []);
        $status = (string)($result['status'] ?? (empty($items) ? 'running' : 'success'));
        return [
            'task_id' => $consumptionId,
            'provider_task_id' => (string)($result['provider_task_id'] ?? ''),
            'provider_request_id' => (string)($result['provider_request_id'] ?? ''),
            'status' => $status,
            'error' => (string)($result['error'] ?? $result['error_msg'] ?? ''),
            'market_product_id' => (int)($snapshot['product_id'] ?? 0),
            'market_sku_id' => (int)($snapshot['sku_id'] ?? 0),
            'videos' => $items,
            'results' => $items,
        ];
    }

    private static function generateMarketMusic(int $tenantId, int $userId, AigcCanvasRun $run, array $params): array
    {
        $request = self::normalizeMusicParams($params);
        if (is_array($params['provider_params'] ?? null)) {
            $request['provider_params'] = (array)$params['provider_params'];
        }
        $reserve = MarketMusicAppRuntimeService::reserve(
            $tenantId,
            $userId,
            (string)$run['id'],
            $params,
            $request,
            self::APP_CODE,
            'music_generate',
            'aigc_canvas_run'
        );
        MarketMusicAppRuntimeService::linkBusinessTask((int)$reserve['app_task_id'], (int)$run['id']);
        $result = MarketMusicAppRuntimeService::submit((int)$reserve['consumption_id'], $request);
        return self::formatMarketMusicResult((int)$reserve['consumption_id'], $reserve, $result);
    }

    private static function formatMarketMusicResult(int $consumptionId, array $reserve, array $result): array
    {
        $items = [];
        foreach ((array)($result['items'] ?? []) as $index => $row) {
            if (!is_array($row)) {
                continue;
            }
            $rawUrl = (string)($row['audio_url'] ?? $row['url'] ?? $row['file_url'] ?? $row['audio_uri'] ?? '');
            $url = self::formatRunUrl($rawUrl, $row);
            if ($url === '') {
                continue;
            }
            $items[] = array_merge($row, [
                'id' => (int)($row['id'] ?? ($index + 1)),
                'url' => $url,
                'audio_url' => $url,
                'file_url' => $url,
            ]);
        }
        $snapshot = (array)($reserve['market_snapshot'] ?? []);
        $status = (string)($result['status'] ?? (empty($items) ? 'running' : 'success'));
        return [
            'task_id' => $consumptionId,
            'provider_task_id' => (string)($result['provider_task_id'] ?? ''),
            'provider_request_id' => (string)($result['provider_request_id'] ?? ''),
            'status' => $status,
            'error' => (string)($result['error'] ?? ''),
            'market_product_id' => (int)($snapshot['product_id'] ?? 0),
            'market_sku_id' => (int)($snapshot['sku_id'] ?? 0),
            'items' => $items,
            'audios' => $items,
            'results' => $items,
        ];
    }

    public static function generateText(int $tenantId, int $userId, array $params): array
    {
        $params = self::prepareMentionParams($params);
        $started = microtime(true);
        $run = self::createRun($tenantId, $userId, $params, 'text', 'power_market_text');
        try {
            $referenceImages = self::normalizeReferenceImages($params, $tenantId, $userId, false);
            $prompt = self::resolveTextPrompt($params, $referenceImages);
            if ($prompt === '') {
                throw new Exception('请填写系统提示词或补充输入，或连接文本节点/参考图');
            }
            $llmParams = [
                'content' => $prompt,
                'system_prompt' => self::resolveTextSystemPrompt($params),
                'model_code' => (string)($params['model_code'] ?? $params['model'] ?? ''),
                'reference_images' => $referenceImages,
                'source_app_code' => self::APP_CODE,
                'source_type' => 'canvas_text',
                'source_id' => (string)($params['node_id'] ?? ''),
            ];
            foreach (['max_tokens', 'enable_thinking', 'request_timeout_seconds'] as $key) {
                if (array_key_exists($key, $params)) {
                    $llmParams[$key] = $params[$key];
                }
            }
            if (!empty($params['tools']) && is_array($params['tools'])) {
                $llmParams['tools'] = $params['tools'];
            }
            if (isset($params['tool_choice']) && (is_string($params['tool_choice']) || is_array($params['tool_choice']))) {
                $llmParams['tool_choice'] = $params['tool_choice'];
            }
            if (self::validTextResponseFormat($params['response_format'] ?? null)) {
                $llmParams['response_format'] = $params['response_format'];
            }
            $result = self::llmText($tenantId, $userId, $llmParams);
            if (empty($result['task_id']) && !empty($result['consumption_id'])) {
                $result['task_id'] = (int)$result['consumption_id'];
            }
            self::finishRun($run, $result, 'success', '', $started);
            return $result;
        } catch (Exception $e) {
            self::finishRun($run, [], 'failed', $e->getMessage(), $started);
            throw $e;
        }
    }

    public static function streamText(int $tenantId, int $userId, array $params, ?callable $onEvent = null): array
    {
        $params = self::prepareMentionParams($params);
        $started = microtime(true);
        $run = self::createRun($tenantId, $userId, $params, 'text', 'power_market_text');
        try {
            $referenceImages = self::normalizeReferenceImages($params, $tenantId, $userId, false);
            $prompt = self::resolveTextPrompt($params, $referenceImages);
            if ($prompt === '') {
                throw new Exception('请填写系统提示词或补充输入，或连接文本节点/参考图');
            }
            $llmParams = [
                'content' => $prompt,
                'system_prompt' => self::resolveTextSystemPrompt($params),
                'model_code' => (string)($params['model_code'] ?? $params['model'] ?? ''),
                'reference_images' => $referenceImages,
                'source_app_code' => self::APP_CODE,
                'source_type' => (string)($params['source_type'] ?? 'canvas_text'),
                'source_id' => (string)($params['node_id'] ?? $params['source_id'] ?? ''),
            ];
            foreach (['max_tokens', 'enable_thinking', 'request_timeout_seconds'] as $key) {
                if (array_key_exists($key, $params)) {
                    $llmParams[$key] = $params[$key];
                }
            }
            if (!empty($params['tools']) && is_array($params['tools'])) {
                $llmParams['tools'] = $params['tools'];
            }
            if (isset($params['tool_choice']) && (is_string($params['tool_choice']) || is_array($params['tool_choice']))) {
                $llmParams['tool_choice'] = $params['tool_choice'];
            }
            if (self::validTextResponseFormat($params['response_format'] ?? null)) {
                $llmParams['response_format'] = $params['response_format'];
            }
            $result = self::llmText($tenantId, $userId, $llmParams, $onEvent);
            if (empty($result['task_id']) && !empty($result['consumption_id'])) {
                $result['task_id'] = (int)$result['consumption_id'];
            }
            self::finishRun($run, $result, 'success', '', $started);
            return $result;
        } catch (Exception $e) {
            self::finishRun($run, [], 'failed', $e->getMessage(), $started);
            throw $e;
        }
    }

    public static function videoTaskDetail(int $tenantId, int $userId, int $taskId): array
    {
        $marketDetail = self::marketVideoTaskDetail($tenantId, $userId, $taskId);
        self::syncRunsForSourceTask($tenantId, 'video', $taskId, $marketDetail);
        return $marketDetail;
    }

    public static function musicTaskDetail(int $tenantId, int $userId, int $taskId): array
    {
        $marketDetail = self::marketMusicTaskDetail($tenantId, $userId, $taskId);
        self::syncRunsForSourceTask($tenantId, 'music', $taskId, $marketDetail);
        return $marketDetail;
    }

    public static function imageTaskDetail(int $tenantId, int $userId, int $taskId): array
    {
        $marketDetail = self::marketImageTaskDetail($tenantId, $userId, $taskId);
        self::syncRunsForSourceTask($tenantId, 'image', $taskId, $marketDetail);
        return $marketDetail;
    }

    private static function marketImageTaskDetail(int $tenantId, int $userId, int $consumptionId): array
    {
        $query = AiConsumptionLog::where([
            'id' => $consumptionId,
            'tenant_id' => $tenantId,
            'app_code' => self::APP_CODE,
            'action_code' => 'generate',
            'provider' => 'power_market',
        ]);
        $query->whereIn('protocol', ['image_generate', 'application_api']);
        if ($userId > 0) {
            $query->where('user_id', $userId);
        }
        $consumption = $query->findOrEmpty();
        if ($consumption->isEmpty()) {
            return [];
        }
        $result = (string)$consumption['protocol'] === 'application_api'
            ? MarketNanoBananaAppRuntimeService::refresh($consumptionId)
            : MarketImageModelRuntimeService::refresh($consumptionId);
        return self::formatMarketImageResult($consumptionId, [
            'market_snapshot' => (array)$consumption['price_snapshot'],
        ], $result + [
            'error' => (string)($consumption['error_message'] ?? ''),
        ]);
    }

    private static function marketMusicTaskDetail(int $tenantId, int $userId, int $consumptionId): array
    {
        $query = AiConsumptionLog::where([
            'id' => $consumptionId,
            'tenant_id' => $tenantId,
            'app_code' => self::APP_CODE,
            'action_code' => 'music_generate',
            'provider' => 'power_market',
            'protocol' => 'application_api',
        ]);
        if ($userId > 0) {
            $query->where('user_id', $userId);
        }
        $consumption = $query->findOrEmpty();
        if ($consumption->isEmpty()) {
            return [];
        }
        $result = MarketMusicAppRuntimeService::refresh($consumptionId);
        return self::formatMarketMusicResult($consumptionId, [
            'market_snapshot' => (array)$consumption['price_snapshot'],
        ], $result + [
            'error' => (string)($consumption['error_message'] ?? ''),
        ]);
    }

    private static function marketVideoTaskDetail(int $tenantId, int $userId, int $consumptionId): array
    {
        $query = AiConsumptionLog::where([
            'id' => $consumptionId,
            'tenant_id' => $tenantId,
            'app_code' => self::APP_CODE,
            'action_code' => 'video_generate',
            'provider' => 'power_market',
        ]);
        if ($userId > 0) {
            $query->where('user_id', $userId);
        }
        $consumption = $query->findOrEmpty();
        if ($consumption->isEmpty()) {
            return [];
        }
        $snapshot = (array)$consumption['price_snapshot'];
        $runtime = self::marketVideoRuntime($snapshot);
        $result = $runtime::refresh($consumptionId);
        return self::formatMarketVideoResult($consumptionId, [
            'market_snapshot' => $snapshot,
        ], $result + [
            'error' => (string)($consumption['error_message'] ?? ''),
        ]);
    }

    public static function clearAllBusinessData(): void
    {
        AigcCanvasProject::where('id', '>', 0)->delete();
        AigcCanvasRun::where('id', '>', 0)->delete();
    }

    private static function resourceStatusItem(string $name, string $requiredFor, string $resourceLabel, array $options, string $resourceType = 'model_api', string $message = ''): array
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
            'message' => $message !== '' ? $message : ($count > 0 ? '算力市场已上架 ' . $count . ' 个可用资源' : '暂无租户可用的算力市场资源'),
        ];
    }

    private static function marketVideoRuntime(array $selection): string
    {
        $value = implode('|', array_map('strval', [
            $selection['resource_type'] ?? '',
            $selection['model_id'] ?? '',
            $selection['channel'] ?? '',
        ]));
        return str_contains($value, 'app_api') || str_contains($value, 'market_video_app:')
            ? MarketVideoAppRuntimeService::class
            : MarketVideoModelRuntimeService::class;
    }

    private static function hasMarketVideoSelection(array $selection): bool
    {
        if (!empty($selection['market_sku_id']) || !empty($selection['sku_id']) || !empty($selection['market_product_id'])) {
            return true;
        }
        foreach (['channel', 'model_id', 'video_model_id'] as $key) {
            $value = (string)($selection[$key] ?? '');
            if (str_starts_with($value, 'market_video_model:') || str_starts_with($value, 'market_video_app:')) {
                return true;
            }
        }
        return false;
    }

    private static function textConfig(int $tenantId): array
    {
        try {
            $groups = MarketTextModelRuntimeService::modelGroups($tenantId);
            $models = [];
            foreach ($groups as $group) {
                foreach ((array)($group['options'] ?? []) as $option) {
                    if (!is_array($option)) {
                        continue;
                    }
                    $code = (string)($option['id'] ?? $option['product_id'] ?? $option['model_code'] ?? '');
                    if ($code === '') {
                        continue;
                    }
                    $models[$code] = [
                        'code' => $code,
                        'name' => (string)($option['name'] ?? $option['model_code'] ?? $code),
                        'description' => (string)($option['description'] ?? ''),
                        'display_icon' => (string)($option['display_icon'] ?? ''),
                        'model_code' => (string)($option['model_code'] ?? ''),
                        'market_product_id' => (int)($option['product_id'] ?? 0),
                        'market_sku_id' => (int)($option['market_sku_id'] ?? 0),
                        'sku_id' => (int)($option['sku_id'] ?? $option['market_sku_id'] ?? 0),
                        'market_input_sku_id' => (int)($option['market_input_sku_id'] ?? 0),
                        'market_output_sku_id' => (int)($option['market_output_sku_id'] ?? 0),
                        'market_input_sku_key' => (string)($option['market_input_sku_key'] ?? ''),
                        'market_output_sku_key' => (string)($option['market_output_sku_key'] ?? ''),
                        'price_source' => (string)($option['price_source'] ?? 'power_market_text_model'),
                        'supports_vision' => !empty($option['supports_vision']),
                        'provider' => 'power_market',
                    ];
                }
            }
            $models = array_values($models);
            return [
                'enabled' => $models !== [],
                'models' => $models,
                'defaults' => ['model' => (string)($models[0]['code'] ?? '')],
                'message' => $models !== [] ? '可用' : '暂无租户可用的算力市场文本模型资源',
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

    public static function llmText(int $tenantId, int $userId, array $params, ?callable $onEvent = null): array
    {
        $params['source_app_code'] = (string)($params['source_app_code'] ?? self::APP_CODE);
        $params['business_table'] = (string)($params['business_table'] ?? 'aigc_canvas_run');
        $params['action_code'] = (string)($params['action_code'] ?? $params['source_type'] ?? 'canvas_text');
        $selection = self::textModelSelection($params);
        if ($selection !== []) {
            $params['model_selection'] = $selection;
        }
        return MarketTextModelRuntimeService::generate($tenantId, $userId, $params, $onEvent);
    }

    private static function textModelSelection(array $params): array
    {
        $selection = [];
        foreach (['id', 'product_id', 'model_code', 'model_id', 'model', 'market_product_id', 'market_sku_id', 'sku_id', 'market_input_sku_id', 'market_output_sku_id'] as $key) {
            $value = $params[$key] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            $selection[$key] = $value;
        }
        if (empty($selection['product_id']) && !empty($selection['market_product_id'])) {
            $selection['product_id'] = (int)$selection['market_product_id'];
        }
        if (empty($selection['id']) && !empty($selection['product_id'])) {
            $selection['id'] = (string)$selection['product_id'];
        }
        if (empty($selection['model_code'])) {
            $selection['model_code'] = (string)($selection['model_id'] ?? $selection['model'] ?? '');
        }
        return array_filter($selection, static fn($value) => $value !== '' && $value !== null);
    }

    private static function validTextResponseFormat($value): bool
    {
        return is_array($value) && !empty($value['type']) && is_string($value['type']);
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
        return is_array($value) ? array_values(self::repairLegacyProjectText($value)) : [];
    }

    /**
     * Historic canvas payloads contain a small set of values that were saved
     * after a GBK/UTF-8 conversion mismatch. Repair only those verified values
     * while preserving arbitrary user prompts and URLs.
     *
     * @param mixed $value
     * @return mixed
     */
    private static function repairLegacyProjectText($value)
    {
        if (is_string($value)) {
            $value = str_replace(
                array_keys(self::LEGACY_TEXT_REPLACEMENTS),
                array_values(self::LEGACY_TEXT_REPLACEMENTS),
                $value
            );
            return self::repairUtf8DecodedAsGb18030($value);
        }
        if (!is_array($value)) {
            return $value;
        }
        foreach ($value as $key => $item) {
            $value[$key] = self::repairLegacyProjectText($item);
        }
        return $value;
    }

    /**
     * Applies the legacy canvas text compatibility rules to user-visible API
     * payloads that are stored outside a project snapshot.
     *
     * @param mixed $value
     * @return mixed
     */
    public static function repairLegacyCanvasText($value)
    {
        return self::repairLegacyProjectText($value);
    }

    /**
     * Repair historic text whose UTF-8 bytes were decoded as GBK/GB18030.
     *
     * The conversion is intentionally gated by the non-printable/private-use
     * characters produced by that failure. This prevents ordinary Chinese
     * prompts from being reinterpreted on every project read.
     */
    private static function repairUtf8DecodedAsGb18030(string $value): string
    {
        if ($value === '' || !function_exists('iconv') || !function_exists('mb_check_encoding') || !function_exists('mb_convert_encoding')) {
            return $value;
        }

        $parts = preg_split('/([\x{3002}\x{FF0C}\x{FF1B}\x{FF1A}\x{FF01}\x{FF1F}\r\n]+)/u', $value, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (!is_array($parts) || count($parts) === 1) {
            return self::repairUtf8DecodedAsGb18030Fragment($value);
        }

        foreach ($parts as $index => $part) {
            if ($index % 2 === 0) {
                $parts[$index] = self::repairUtf8DecodedAsGb18030Fragment($part);
            }
        }
        return implode('', $parts);
    }

    private static function repairUtf8DecodedAsGb18030Fragment(string $value): string
    {
        $legacyMarkers = preg_match_all('/[\x{0080}-\x{009F}\x{20AC}\x{E000}-\x{F8FF}]/u', $value, $matches);
        if ($legacyMarkers === false || $legacyMarkers < 2) {
            return $value;
        }

        $singleByteMap = self::legacyGb18030SingleByteMap();
        $candidate = '';
        foreach (preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $character) {
            $candidate .= $singleByteMap[$character]
                ?? (@iconv('UTF-8', 'GB18030//IGNORE', $character) ?: '');
        }
        if (!is_string($candidate) || $candidate === '') {
            return $value;
        }

        if (!mb_check_encoding($candidate, 'UTF-8')) {
            $candidate = @mb_convert_encoding($candidate, 'UTF-8', 'UTF-8');
        }
        if (!is_string($candidate) || $candidate === '' || !mb_check_encoding($candidate, 'UTF-8')) {
            return $value;
        }

        $candidateMarkers = preg_match_all('/[\x{0080}-\x{009F}\x{20AC}\x{E000}-\x{F8FF}]/u', $candidate, $matches);
        if ($candidateMarkers === false || $candidateMarkers >= $legacyMarkers) {
            return $value;
        }

        return $candidate;
    }

    private static function legacyGb18030SingleByteMap(): array
    {
        static $map = null;
        if (is_array($map)) {
            return $map;
        }

        $map = [];
        foreach (range(0x80, 0xFF) as $byte) {
            $decoded = @mb_convert_encoding(chr($byte), 'UTF-8', 'GB18030');
            if (is_string($decoded) && $decoded !== '' && preg_match('/^.$/us', $decoded)) {
                $map[$decoded] = chr($byte);
            }
        }
        return $map;
    }

    private static function normalizeViewport($value): array
    {
        if (!is_array($value)) {
            return ['x' => 100, 'y' => 50, 'zoom' => 0.82];
        }
        return [
            'x' => (float)($value['x'] ?? 100),
            'y' => (float)($value['y'] ?? 50),
            // PC canvas uses `k`; accept it so older clients do not silently reset zoom.
            'zoom' => (float)($value['zoom'] ?? $value['k'] ?? 0.82),
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
        // Existing installations can use DATETIME while newer installs use an
        // integer timestamp. Normalize both forms before returning project data.
        $createTime = self::normalizeRunTime($row['create_time'] ?? 0);
        $updateTime = self::normalizeRunTime($row['update_time'] ?? 0);
        if ($updateTime <= 0) {
            $updateTime = $createTime;
        }
        if ($createTime <= 0) {
            $createTime = $updateTime;
        }
        $generatedThumbnail = self::firstGeneratedProjectImageThumbnail($nodes);
        $data = [
            'id' => (int)$row['id'],
            'name' => self::repairLegacyProjectText((string)($row['name'] ?? '未命名项目')),
            'thumbnail' => self::formatThumbnail($generatedThumbnail ?: (string)($row['thumbnail'] ?? '')),
            'node_count' => $nodeCount,
            'edge_count' => $edgeCount,
            'createdAt' => $createTime * 1000,
            'updatedAt' => $updateTime * 1000,
            'lastSavedAt' => $updateTime * 1000,
            'create_time' => $createTime,
            'update_time' => $updateTime,
            'last_saved_at' => $updateTime,
            'tenant_id' => (int)($row['tenant_id'] ?? 0),
            'user_id' => (int)($row['user_id'] ?? 0),
            'sort' => (int)($row['sort'] ?? 0),
            'status' => (int)($row['status'] ?? 1),
        ];
        if ($full) {
            $nodes = self::repairLegacyProjectText(self::normalizeList($row['nodes_json'] ?? []));
            $data['nodes'] = self::repairProjectNodesFromRuns(
                (int)($row['tenant_id'] ?? 0),
                (int)($row['id'] ?? 0),
                $nodes
            );
            $data['edges'] = self::repairLegacyProjectText(self::normalizeList($row['edges_json'] ?? []));
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
        $params = self::repairLegacyProjectText(self::normalizeRunPayload($row['params_json'] ?? []));
        $result = self::repairLegacyProjectText(self::normalizeRunPayload($row['result_json'] ?? []));
        $runType = (string)($row['run_type'] ?? '');
        if ($syncSource) {
            $result = self::syncSourceRunResult($row, $runType, $result);
        }
        $result = self::repairLegacyProjectText($result);
        $data = self::repairLegacyProjectText($row);
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
        $data['prompt'] = (string)($data['prompt'] ?? $params['prompt'] ?? $params['content'] ?? '');
        $data['project_name'] = (string)($data['project_name'] ?? '');
        $data['user_nickname'] = (string)($data['user_nickname'] ?? '');
        $data['user_account'] = (string)($data['user_account'] ?? '');
        $data['user_mobile'] = (string)($data['user_mobile'] ?? '');
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

    private static function projectRegisteredAssets(int $tenantId, int $userId, int $projectId): array
    {
        if ($projectId <= 0) {
            return [];
        }
        $rows = AigcCanvasAsset::where([
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

        return array_map(
            static fn(array $row): array => self::repairLegacyProjectText(AigcCanvasAgentService::formatAsset($row)),
            $rows
        );
    }

    private static function normalizeThumbnail(string $thumbnail, int $tenantId, int $userId, string $fallback = ''): string
    {
        $thumbnail = trim($thumbnail);
        if ($thumbnail === '') {
            return '';
        }
        if (str_starts_with($thumbnail, 'data:image/')) {
            $asset = self::persistCanvasImageAsset($thumbnail, $tenantId, $userId);
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
        $params = self::repairLegacyProjectText($params);
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
        $referenceAssets = self::normalizeReferenceAssets(array_merge($params, [
            'reference_images' => $referenceImages,
        ]));
        return [
            'prompt' => trim((string)($params['prompt'] ?? '')),
            'negative_prompt' => (string)($params['negative_prompt'] ?? ''),
            'reference_images' => $referenceImages,
            'reference_assets' => $referenceAssets,
            'style' => (string)($params['style'] ?? 'general'),
            'channel' => (string)($params['channel'] ?? $params['model'] ?? ''),
            'quality' => (string)($params['quality'] ?? $params['resolution'] ?? ''),
            'resolution' => (string)($params['resolution'] ?? $params['quality'] ?? ''),
            'ratio' => (string)($params['ratio'] ?? ''),
            'duration' => (int)($params['duration'] ?? 0),
            'mode' => (string)($params['mode'] ?? $params['videoMode'] ?? ''),
            'generation_method' => (string)($params['generation_method'] ?? $params['generationMethod'] ?? ''),
            'generate_audio' => array_key_exists('generateAudio', $params)
                ? (bool)$params['generateAudio']
                : null,
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
                $stored = self::persistCanvasImageAsset($image, $tenantId, $userId);
                $image = (string)($stored['uri'] ?? '');
            }
            if ($image !== '' && !in_array($image, $normalized, true)) {
                $normalized[] = $image;
            }
        }
        return $normalized;
    }

    private static function normalizeReferenceAssets(array $params, int $max = 15): array
    {
        $assets = [];
        foreach ((array)($params['reference_assets'] ?? []) as $asset) {
            if (is_array($asset)) {
                $normalized = self::normalizeReferenceAssetItem($asset);
                if ($normalized !== []) {
                    $assets[] = $normalized;
                }
            }
        }
        foreach (['image' => 'reference_image', 'first_frame_image' => 'first_frame_image', 'last_frame_image' => 'last_frame_image'] as $key => $role) {
            $value = trim((string)($params[$key] ?? ''));
            if ($value !== '') {
                $normalized = self::normalizeReferenceAssetItem([
                    'type' => 'image',
                    'uri' => $value,
                    'url' => $value,
                    'role' => $role,
                ]);
                if ($normalized !== []) {
                    $assets[] = $normalized;
                }
            }
        }
        foreach (['video_urls' => 'video', 'audio_urls' => 'audio'] as $key => $type) {
            foreach ((array)($params[$key] ?? []) as $url) {
                $normalized = self::normalizeReferenceAssetItem([
                    'type' => $type,
                    'uri' => $url,
                    'url' => $url,
                ]);
                if ($normalized !== []) {
                    $assets[] = $normalized;
                }
            }
        }
        return array_slice(self::uniqueReferenceAssets($assets), 0, $max);
    }

    private static function normalizeReferenceAssetItem(array $asset): array
    {
        $type = strtolower(trim((string)($asset['type'] ?? $asset['media_type'] ?? 'image')));
        $type = match ($type) {
            'img', 'picture', 'photo' => 'image',
            'movie', 'mp4' => 'video',
            'sound', 'voice', 'music' => 'audio',
            default => $type,
        };
        if (!in_array($type, ['image', 'video', 'audio'], true)) {
            return [];
        }
        $uri = trim((string)($asset['uri'] ?? $asset['url'] ?? $asset['path'] ?? ''));
        $url = trim((string)($asset['url'] ?? $asset['uri'] ?? $asset['path'] ?? ''));
        if ($uri === '' && $url === '') {
            return [];
        }
        $normalized = [
            'type' => $type,
            'uri' => $uri !== '' ? $uri : $url,
            'url' => $url !== '' ? $url : $uri,
            'name' => trim((string)($asset['name'] ?? '')),
        ];
        $role = trim((string)($asset['role'] ?? ''));
        $allowedRoles = match ($type) {
            'video' => ['reference_video'],
            'audio' => ['reference_audio'],
            default => ['reference_image', 'first_frame_image', 'last_frame_image'],
        };
        if (in_array($role, $allowedRoles, true)) {
            $normalized['role'] = $role;
        }
        $generationMethod = strtolower(trim((string)($asset['generation_method'] ?? '')));
        if (in_array($generationMethod, ['omni_reference', 'start_end', 'multi_frame'], true)) {
            $normalized['generation_method'] = $generationMethod;
        }
        foreach (['duration', 'start', 'end'] as $key) {
            if (isset($asset[$key]) && is_numeric($asset[$key])) {
                $normalized[$key] = max(0, (float)$asset[$key]);
            }
        }
        return $normalized;
    }

    /**
     * Crop a canvas image in managed storage when the browser cannot export it
     * because an external image server does not expose CORS headers.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     * @throws Exception
     */
    public static function cropImage(int $tenantId, int $userId, array $params): array
    {
        $sourceUrl = trim((string)($params['source_url'] ?? $params['url'] ?? ''));
        if ($sourceUrl === '') {
            throw new Exception('缺少待裁剪图片');
        }
        if (!function_exists('imagecreatefromstring') || !function_exists('imagepng')) {
            throw new Exception('服务器未安装 GD 图片处理扩展');
        }

        $content = self::readCanvasCropImageContent($sourceUrl);
        if ($content === '') {
            throw new Exception('无法读取待裁剪图片');
        }
        $imageInfo = @getimagesizefromstring($content);
        if (!$imageInfo || !in_array((int)($imageInfo[2] ?? 0), [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP], true)) {
            throw new Exception('仅支持 JPG、PNG、GIF 或 WebP 图片裁剪');
        }
        $source = @imagecreatefromstring($content);
        if (!$source) {
            throw new Exception('待裁剪图片解析失败');
        }

        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        if ($sourceWidth <= 0 || $sourceHeight <= 0 || $sourceWidth * $sourceHeight > 100000000) {
            imagedestroy($source);
            throw new Exception('图片尺寸超出裁剪范围');
        }
        $x = max(0, (int)round((float)($params['x'] ?? 0)));
        $y = max(0, (int)round((float)($params['y'] ?? 0)));
        $width = max(1, (int)round((float)($params['width'] ?? 0)));
        $height = max(1, (int)round((float)($params['height'] ?? 0)));
        if ($x >= $sourceWidth || $y >= $sourceHeight) {
            imagedestroy($source);
            throw new Exception('裁剪区域超出图片范围');
        }
        $width = min($width, $sourceWidth - $x);
        $height = min($height, $sourceHeight - $y);

        $cropped = imagecreatetruecolor($width, $height);
        if (!$cropped) {
            imagedestroy($source);
            throw new Exception('裁剪图片创建失败');
        }
        imagealphablending($cropped, false);
        imagesavealpha($cropped, true);
        $transparent = imagecolorallocatealpha($cropped, 0, 0, 0, 127);
        imagefilledrectangle($cropped, 0, 0, $width, $height, $transparent);
        imagecopyresampled($cropped, $source, 0, 0, $x, $y, $width, $height, $width, $height);
        imagedestroy($source);

        $tmp = tempnam(sys_get_temp_dir(), 'aigc_canvas_crop_');
        if ($tmp === false) {
            imagedestroy($cropped);
            throw new Exception('裁剪临时文件创建失败');
        }
        $tmpPath = $tmp . '.png';
        @rename($tmp, $tmpPath);
        $saved = imagepng($cropped, $tmpPath, 6);
        imagedestroy($cropped);
        if (!$saved) {
            @unlink($tmpPath);
            throw new Exception('裁剪图片保存失败');
        }
        try {
            $stored = self::uploadCanvasLocalFile($tmpPath, $tenantId, $userId, FileEnum::IMAGE_TYPE);
        } finally {
            @unlink($tmpPath);
        }
        return [
            'uri' => $stored['uri'],
            'url' => FileService::getFileUrlByStorage(
                $stored['uri'],
                $stored['storage_scope'],
                $stored['storage_engine'],
                $stored['storage_domain']
            ),
            'width' => $width,
            'height' => $height,
            'mime_type' => 'image/png',
            'storage_scope' => $stored['storage_scope'],
            'storage_engine' => $stored['storage_engine'],
            'storage_domain' => $stored['storage_domain'],
        ];
    }

    private static function withImageSubmissionSnapshot(array $result, array $params): array
    {
        $keys = [
            'prompt', 'compiled_prompt', 'prompt_spec_json', 'creative_spec_json', 'prompt_hash', 'compiler_version',
            'prompt_mode', 'evidence_ids', 'claim_ids', 'preflight', 'ratio', 'quantity', 'reference_images',
            'reference_assets', 'channel', 'model_id', 'market_product_id', 'market_sku_id', 'sku_id',
            'target_element_id', 'section_key', 'section_index', 'batch_id', 'request_id',
            'original_user_request', 'prompt_enrichment', 'prompt_language',
        ];
        $snapshot = array_intersect_key($params, array_flip($keys));
        return $result + [
            'preflight' => (array)($params['preflight'] ?? []),
            'compiled_prompt' => (string)($params['compiled_prompt'] ?? $params['prompt'] ?? ''),
            'prompt_hash' => (string)($params['prompt_hash'] ?? ''),
            'compiler_version' => (string)($params['compiler_version'] ?? ''),
            'prompt_spec_json' => (array)($params['prompt_spec_json'] ?? []),
            'creative_spec_json' => (array)($params['creative_spec_json'] ?? []),
            'evidence_ids' => array_values((array)($params['evidence_ids'] ?? [])),
            'claim_ids' => array_values((array)($params['claim_ids'] ?? [])),
            'submitted_input' => $snapshot,
        ];
    }

    private static function withVideoSubmissionSnapshot(array $result, array $params): array
    {
        $keys = [
            'prompt', 'compiled_prompt', 'prompt_spec_json', 'creative_spec_json', 'prompt_hash', 'compiler_version',
            'prompt_mode', 'video_prompt_mode', 'evidence_ids', 'claim_ids', 'preflight', 'ratio', 'duration', 'quantity',
            'reference_images', 'reference_assets', 'video_urls', 'audio_urls', 'channel', 'model_id', 'market_product_id',
            'market_sku_id', 'sku_id', 'target_element_id', 'request_id', 'camera_movement', 'motion', 'continuity_constraints',
        ];
        $snapshot = array_intersect_key($params, array_flip($keys));
        return $result + [
            'preflight' => (array)($params['preflight'] ?? []),
            'compiled_prompt' => (string)($params['compiled_prompt'] ?? $params['prompt'] ?? ''),
            'prompt_hash' => (string)($params['prompt_hash'] ?? ''),
            'compiler_version' => (string)($params['compiler_version'] ?? ''),
            'prompt_spec_json' => (array)($params['prompt_spec_json'] ?? []),
            'creative_spec_json' => (array)($params['creative_spec_json'] ?? []),
            'evidence_ids' => array_values((array)($params['evidence_ids'] ?? [])),
            'submitted_input' => $snapshot,
        ];
    }

    /**
     * Rotate and mirror a canvas image in managed storage when browser canvas export
     * is unavailable because the source image does not allow CORS access.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     * @throws Exception
     */
    public static function rotateImage(int $tenantId, int $userId, array $params): array
    {
        $sourceUrl = trim((string)($params['source_url'] ?? $params['url'] ?? ''));
        if ($sourceUrl === '') {
            throw new Exception('缺少待旋转图片');
        }
        if (!function_exists('imagecreatefromstring') || !function_exists('imagepng') || !function_exists('imagerotate')) {
            throw new Exception('服务器未安装 GD 图片处理扩展');
        }

        $content = self::readCanvasCropImageContent($sourceUrl);
        if ($content === '') {
            throw new Exception('无法读取待旋转图片');
        }
        $imageInfo = @getimagesizefromstring($content);
        if (!$imageInfo || !in_array((int)($imageInfo[2] ?? 0), [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP], true)) {
            throw new Exception('仅支持 JPG、PNG、GIF 或 WebP 图片旋转');
        }
        $source = @imagecreatefromstring($content);
        if (!$source) {
            throw new Exception('待旋转图片解析失败');
        }
        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        if ($sourceWidth <= 0 || $sourceHeight <= 0 || $sourceWidth * $sourceHeight > 100000000) {
            imagedestroy($source);
            throw new Exception('图片尺寸超出旋转范围');
        }

        $angle = ((int)round(((float)($params['angle'] ?? 0)) / 90) * 90) % 360;
        if ($angle < 0) {
            $angle += 360;
        }
        $flipX = filter_var($params['flip_x'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $flipY = filter_var($params['flip_y'] ?? false, FILTER_VALIDATE_BOOLEAN);
        imagealphablending($source, false);
        imagesavealpha($source, true);
        if ($flipX && function_exists('imageflip')) {
            imageflip($source, IMG_FLIP_HORIZONTAL);
        }
        if ($flipY && function_exists('imageflip')) {
            imageflip($source, IMG_FLIP_VERTICAL);
        }

        $output = $source;
        if ($angle !== 0) {
            $transparent = imagecolorallocatealpha($source, 0, 0, 0, 127);
            // GD uses counter-clockwise angles; the editor exposes clockwise turns.
            $output = imagerotate($source, 360 - $angle, $transparent);
            imagedestroy($source);
            if (!$output) {
                throw new Exception('图片旋转失败');
            }
        }
        imagealphablending($output, false);
        imagesavealpha($output, true);
        $outputWidth = imagesx($output);
        $outputHeight = imagesy($output);

        $tmp = tempnam(sys_get_temp_dir(), 'aigc_canvas_rotate_');
        if ($tmp === false) {
            imagedestroy($output);
            throw new Exception('旋转临时文件创建失败');
        }
        $tmpPath = $tmp . '.png';
        @rename($tmp, $tmpPath);
        $saved = imagepng($output, $tmpPath, 6);
        imagedestroy($output);
        if (!$saved) {
            @unlink($tmpPath);
            throw new Exception('旋转图片保存失败');
        }
        try {
            $stored = self::uploadCanvasLocalFile($tmpPath, $tenantId, $userId, FileEnum::IMAGE_TYPE);
        } finally {
            @unlink($tmpPath);
        }
        return [
            'uri' => $stored['uri'],
            'url' => FileService::getFileUrlByStorage(
                $stored['uri'],
                $stored['storage_scope'],
                $stored['storage_engine'],
                $stored['storage_domain']
            ),
            'width' => $outputWidth,
            'height' => $outputHeight,
            'mime_type' => 'image/png',
            'storage_scope' => $stored['storage_scope'],
            'storage_engine' => $stored['storage_engine'],
            'storage_domain' => $stored['storage_domain'],
        ];
    }

    private static function readCanvasCropImageContent(string $sourceUrl): string
    {
        $maxBytes = 20 * 1024 * 1024;
        if (str_starts_with($sourceUrl, 'data:image/')) {
            if (!preg_match('/^data:image\/[a-zA-Z0-9.+-]+;base64,(.+)$/', $sourceUrl, $matches)) {
                return '';
            }
            $content = base64_decode($matches[1], true);
            return is_string($content) && strlen($content) <= $maxBytes ? $content : '';
        }
        $parts = parse_url($sourceUrl);
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        if (in_array($scheme, ['http', 'https'], true)) {
            $host = strtolower(trim((string)($parts['host'] ?? '')));
            $port = (int)($parts['port'] ?? 0);
            if ($host === '' || in_array($host, ['localhost', 'localhost.localdomain'], true) || ($port > 0 && !in_array($port, [80, 443], true)) || !self::isCanvasCropPublicHost($host)) {
                return '';
            }
            $context = stream_context_create([
                'http' => [
                    'timeout' => 20,
                    'follow_location' => 0,
                    'max_redirects' => 0,
                    'ignore_errors' => false,
                    'header' => "User-Agent: LikeAdminAigcCanvas/1.0\r\nAccept: image/*\r\n",
                ],
            ]);
            $content = @file_get_contents($sourceUrl, false, $context, 0, $maxBytes + 1);
            return is_string($content) && strlen($content) <= $maxBytes ? $content : '';
        }
        if (str_contains($sourceUrl, "\0") || str_contains($sourceUrl, '..')) {
            return '';
        }
        $publicRoot = realpath(public_path());
        $path = realpath(public_path() . ltrim($sourceUrl, '/'));
        if (!$publicRoot || !$path || !str_starts_with($path, $publicRoot . DIRECTORY_SEPARATOR) || !is_file($path) || filesize($path) > $maxBytes) {
            return '';
        }
        $content = @file_get_contents($path);
        return is_string($content) ? $content : '';
    }

    private static function isCanvasCropPublicHost(string $host): bool
    {
        $ips = [];
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips[] = $host;
        } else {
            $records = @dns_get_record($host, DNS_A | DNS_AAAA);
            foreach ((array)$records as $record) {
                $ip = (string)($record['ip'] ?? $record['ipv6'] ?? '');
                if ($ip !== '') {
                    $ips[] = $ip;
                }
            }
        }
        foreach (array_values(array_filter((array)($params['reference_images'] ?? $params['image_urls'] ?? []))) as $image) {
            $normalized = self::normalizeReferenceAssetItem([
                'type' => 'image',
                'uri' => $image,
                'url' => $image,
                'role' => 'reference_image',
            ]);
            if ($normalized !== []) {
                $assets[] = $normalized;
            }
        }
        if (!$ips) {
            return false;
        }
        foreach (array_unique($ips) as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return false;
            }
        }
        return true;
    }

    private static function persistCanvasImageAsset(string $url, int $tenantId, int $userId = 0): array
    {
        if (!str_starts_with($url, 'data:image/')) {
            return ['uri' => $url, 'width' => 0, 'height' => 0, 'stored' => false];
        }
        if (!preg_match('/^data:image\/([a-zA-Z0-9.+-]+);base64,(.+)$/', $url, $matches)) {
            throw new Exception('画布图片格式错误');
        }
        $content = base64_decode($matches[2], true);
        if ($content === false || $content === '') {
            throw new Exception('画布图片解析失败');
        }
        $ext = strtolower((string)$matches[1]);
        $ext = $ext === 'jpeg' ? 'jpg' : $ext;
        $ext = in_array($ext, ['jpg', 'png', 'webp', 'gif'], true) ? $ext : 'png';
        $tmp = tempnam(sys_get_temp_dir(), 'aigc_canvas_');
        if ($tmp === false) {
            throw new Exception('画布图片临时文件创建失败');
        }
        $tmpPath = $tmp . '.' . $ext;
        @rename($tmp, $tmpPath);
        file_put_contents($tmpPath, $content);
        $size = @getimagesize($tmpPath) ?: [];
        try {
            $stored = self::uploadCanvasLocalFile($tmpPath, $tenantId, $userId, FileEnum::IMAGE_TYPE);
        } finally {
            @unlink($tmpPath);
        }
        return [
            'uri' => $stored['uri'],
            'width' => (int)($size[0] ?? 0),
            'height' => (int)($size[1] ?? 0),
            'stored' => true,
            'storage_scope' => $stored['storage_scope'],
            'storage_engine' => $stored['storage_engine'],
            'storage_domain' => $stored['storage_domain'],
        ];
    }

    private static function uploadCanvasLocalFile(string $filePath, int $tenantId, int $userId, int $fileType): array
    {
        $config = StorageConfigService::getEffectiveConfig($tenantId);
        $saveDir = 'uploads/aigc_canvas/' . date('Ymd');
        $driver = new StorageDriver($config);
        $driver->setUploadFileByReal($filePath);
        if (!$driver->upload($saveDir)) {
            throw new Exception($driver->getError() ?: '画布文件保存失败');
        }
        $uri = $saveDir . '/' . str_replace('\\', '/', $driver->getFileName());
        $scope = (string)($config['scope'] ?? 'tenant');
        $engine = (string)($config['default'] ?? 'local');
        $domain = (string)StorageConfigService::getEffectiveDomain($tenantId);
        TenantFile::create([
            'tenant_id' => $tenantId,
            'cid' => 0,
            'type' => $fileType,
            'name' => basename($uri),
            'uri' => $uri,
            'storage_scope' => $scope,
            'storage_engine' => $engine,
            'storage_domain' => $domain,
            'source' => FileEnum::SOURCE_USER,
            'source_id' => $userId,
            'create_time' => time(),
        ]);
        return [
            'uri' => $uri,
            'storage_scope' => $scope,
            'storage_engine' => $engine,
            'storage_domain' => $domain,
        ];
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

    private static function resolveTextSystemPrompt(array $params): string
    {
        $systemPrompt = trim((string)($params['system_prompt'] ?? ''));
        if ((string)($params['output_contract'] ?? '') !== 'final_only') {
            return $systemPrompt;
        }

        $finalOnlyPolicy = '你正在为无限画布的文本节点生成内容。只输出一份可直接使用的最终结果，不要解释、分析过程、多个版本、标题、前后缀或 Markdown 包装。';
        return $systemPrompt === '' ? $finalOnlyPolicy : $systemPrompt . "\n\n" . $finalOnlyPolicy;
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
