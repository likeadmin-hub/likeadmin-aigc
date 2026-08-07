<?php

namespace app\common\service\app\aigc_product_promo_video;

use app\common\model\app\App;
use app\common\model\app\aigc_product_promo_video\AigcProductPromoVideoConfig;
use app\common\model\app\aigc_product_promo_video\AigcProductPromoVideoResult;
use app\common\model\app\aigc_product_promo_video\AigcProductPromoVideoTask;
use app\common\model\app\aigc_product_promo_video\AigcProductPromoVideoType;
use app\common\model\app\aigc_video\AigcVideoResult;
use app\common\model\app\aigc_video\AigcVideoTask;
use app\common\service\app\AppAccessService;
use app\common\service\app\AppDisplayConfigService;
use app\common\service\app\AppRegistryService;
use app\common\service\app\aigc_llm\AigcLlmService;
use app\common\service\app\aigc_video\AigcVideoService;
use app\common\service\ai\MarketAppGateService;
use app\common\service\FileService;
use app\common\service\point\PointService;
use Exception;

class AigcProductPromoVideoService
{
    public const APP_CODE = 'aigc_product_promo_video';
    public const VIDEO_APP_CODE = 'aigc_video';
    public const LLM_APP_CODE = 'aigc_llm';

    private const DEFAULT_PROMPT_TEMPLATE = '基于用户上传的产品图片生成一条电商产品宣传视频。保持产品主体、品牌元素、材质、颜色和关键结构稳定，结合视频类型要求规划镜头运动、转场节奏、光影氛围和卖点呈现，输出适合详情页、短视频投放和种草传播的高质量宣传视频。{type_prompt}{user_prompt}';
    private const DEFAULT_NEGATIVE_PROMPT = '产品变形，主体缺失，文字错乱，水印，低清晰度，严重模糊，镜头抖动，画面撕裂，比例异常，无关物体，品牌元素错误';

    private const DEFAULT_TYPES = [
        'product' => ['name' => '产品宣传', 'description' => '聚焦产品亮点与购买吸引力', 'prompt' => '视频需要围绕产品核心卖点展开，使用干净高级的商业镜头语言突出外观、材质、功能和购买理由。', 'sort' => 100],
        'creative' => ['name' => '创意应用', 'description' => '强调创意镜头和视觉记忆点', 'prompt' => '视频需要加入更强的创意镜头、动态构图和视觉记忆点，让产品呈现更有传播感。', 'sort' => 90],
        'feature' => ['name' => '功能展示', 'description' => '突出功能卖点和使用方式', 'prompt' => '视频需要强调产品功能、使用方式、细节特写和场景价值，让用户快速理解卖点。', 'sort' => 80],
        'unboxing' => ['name' => '开箱体验', 'description' => '呈现拆箱过程与上手体验', 'prompt' => '视频需要营造开箱、上手、展示细节的体验感，突出包装、质感和初次使用的吸引力。', 'sort' => 70],
        'story' => ['name' => '场景故事', 'description' => '适合生活方式和种草场景', 'prompt' => '视频需要将产品放入真实使用场景和轻故事氛围中，突出生活方式、情绪价值和种草转化。', 'sort' => 60],
    ];

    public static function config(int $tenantId): array
    {
        $row = AigcProductPromoVideoConfig::where('tenant_id', $tenantId)->findOrEmpty();
        $data = $row->isEmpty() ? self::defaults() : array_merge(self::defaults(), $row->toArray());
        $data = self::sanitizeConfig($data);
        $data['market_enabled'] = 1;
        $optionConfig = self::marketOptionConfig($tenantId);
        $data['option_config'] = $optionConfig;
        $data['spec_options'] = self::buildSpecOptions($optionConfig);
        $data['ratio_options'] = self::buildRatioOptions($data['spec_options'], $data['default_channel'], $data['default_quality']);
        $data['duration_options'] = self::buildDurationOptions($data['spec_options'], $data['default_ratio'], $data['default_channel'], $data['default_quality']);
        $data['types'] = self::typeLists($tenantId, true);
        $data['dependencies'] = self::dependencies($tenantId, $optionConfig);
        if ($row->isEmpty()) {
            self::saveConfigSnapshot($tenantId, $data, $row);
        }
        // Prices are owned by market SKUs and exposed on each concrete spec.
        unset($data['unit_price'], $data['price_matrix']);
        return AppDisplayConfigService::appendToConfig($tenantId, self::APP_CODE, $data);
    }

    public static function saveConfig(int $tenantId, array $params): void
    {
        AppDisplayConfigService::saveFromConfigPayload($tenantId, self::APP_CODE, $params);
        $current = self::config($tenantId);
        $configJson = is_array($params['config_json'] ?? null) ? $params['config_json'] : ($current['config_json'] ?? []);
        $data = [
            'tenant_id' => $tenantId,
            'status' => array_key_exists('status', $params) ? (int)$params['status'] : (int)$current['status'],
            'market_enabled' => 1,
            'default_channel' => self::normalizeChannelCode((string)($params['default_channel'] ?? $configJson['channel'] ?? $current['default_channel'] ?? '')),
            'default_quality' => trim((string)($params['default_quality'] ?? $configJson['quality'] ?? $current['default_quality'] ?? '')),
            'default_ratio' => trim((string)($params['default_ratio'] ?? $configJson['ratio'] ?? $current['default_ratio'] ?? '')),
            'default_duration' => 0,
            // Retain the legacy column for schema compatibility; it is no longer
            // accepted as an application-level price override.
            'unit_price' => 0,
            'prompt_template' => self::normalizeTemplate((string)($params['prompt_template'] ?? $current['prompt_template'])),
            'negative_prompt' => trim((string)($params['negative_prompt'] ?? $current['negative_prompt'])),
            'price_matrix' => [],
            'config_json' => self::normalizeConfigJson($configJson),
            'update_time' => time(),
        ];
        $data['config_json']['market_enabled'] = $data['market_enabled'];
        $data = self::alignDefaultsToOptionConfig($data, self::marketOptionConfig($tenantId));
        $row = AigcProductPromoVideoConfig::where('tenant_id', $tenantId)->findOrEmpty();
        if ($row->isEmpty()) {
            $data['create_time'] = time();
            AigcProductPromoVideoConfig::create($data);
            return;
        }
        $row->save($data);
    }

    public static function typeLists(int $tenantId, bool $onlyEnabled = false): array
    {
        self::ensureDefaultTypes($tenantId);
        $query = AigcProductPromoVideoType::where('tenant_id', $tenantId)->where('delete_time', 0)->order('sort', 'desc')->order('id', 'asc');
        if ($onlyEnabled) {
            $query->where('status', 1);
        }
        $rows = $query->select()->toArray();
        foreach ($rows as &$row) {
            $row['cover_url'] = self::imageUrl((string)($row['cover_image'] ?? ''));
            $row['is_builtin'] = (int)($row['is_builtin'] ?? 0);
        }
        return $rows;
    }

    public static function saveType(int $tenantId, array $params): array
    {
        self::ensureDefaultTypes($tenantId);
        $id = (int)($params['id'] ?? 0);
        $code = self::normalizeTypeCode((string)($params['code'] ?? ''));
        if ($id <= 0 && $code === '') {
            $code = 'custom_' . time();
        }
        $row = $id > 0
            ? AigcProductPromoVideoType::where(['tenant_id' => $tenantId, 'id' => $id])->where('delete_time', 0)->findOrEmpty()
            : AigcProductPromoVideoType::where(['tenant_id' => $tenantId, 'code' => $code])->where('delete_time', 0)->findOrEmpty();
        if ($row->isEmpty() && AigcProductPromoVideoType::where(['tenant_id' => $tenantId, 'code' => $code])->where('delete_time', 0)->count() > 0) {
            throw new Exception('类型标识已存在');
        }
        if (!$row->isEmpty()) {
            $code = (string)$row['code'];
        }
        $name = mb_substr(trim((string)($params['name'] ?? '')), 0, 80);
        if ($name === '') {
            throw new Exception('请输入类型名称');
        }
        $data = [
            'tenant_id' => $tenantId,
            'code' => $code,
            'name' => $name,
            'description' => mb_substr(trim((string)($params['description'] ?? '')), 0, 200),
            'prompt' => mb_substr(trim((string)($params['prompt'] ?? '')), 0, 1000),
            'cover_image' => trim((string)($params['cover_image'] ?? '')),
            'status' => (int)($params['status'] ?? 1) ? 1 : 0,
            'sort' => (int)($params['sort'] ?? 0),
            'update_time' => time(),
        ];
        if ($row->isEmpty()) {
            $data['is_builtin'] = 0;
            $data['delete_time'] = 0;
            $data['create_time'] = time();
            $row = AigcProductPromoVideoType::create($data);
        } else {
            $row->save($data);
        }
        return $row->toArray();
    }

    public static function setTypeStatus(int $tenantId, array $params): void
    {
        $id = (int)($params['id'] ?? 0);
        $code = self::normalizeTypeCode((string)($params['code'] ?? ''));
        $query = AigcProductPromoVideoType::where('tenant_id', $tenantId)->where('delete_time', 0);
        $id > 0 ? $query->where('id', $id) : $query->where('code', $code);
        $row = $query->findOrEmpty();
        if ($row->isEmpty()) {
            throw new Exception('视频类型不存在');
        }
        $row->save(['status' => (int)($params['status'] ?? 1) ? 1 : 0, 'update_time' => time()]);
    }

    public static function deleteType(int $tenantId, array $params): void
    {
        $row = AigcProductPromoVideoType::where(['tenant_id' => $tenantId, 'id' => (int)($params['id'] ?? 0)])->where('delete_time', 0)->findOrEmpty();
        if ($row->isEmpty()) {
            throw new Exception('视频类型不存在');
        }
        if ((int)$row['is_builtin'] === 1) {
            throw new Exception('内置视频类型不允许删除');
        }
        $row->save(['delete_time' => time(), 'update_time' => time()]);
    }

    public static function estimate(int $tenantId, array $params): array
    {
        self::assertAvailable($tenantId);
        $prepared = self::prepareGeneratePayload($tenantId, $params, false);
        $videoEstimate = self::videoEstimate($tenantId, $prepared);
        return self::buildEstimate($prepared, $videoEstimate);
    }

    public static function generate(int $tenantId, int $userId, array $params): array
    {
        $idempotencyKey = trim((string)($params['idempotency_key'] ?? ''));
        MarketAppGateService::requireMarket(
            $tenantId,
            $userId,
            self::APP_CODE,
            $idempotencyKey
        );
        if ($idempotencyKey !== '') {
            $existing = AigcProductPromoVideoTask::where([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'idempotency_key' => $idempotencyKey,
                'delete_time' => 0,
            ])->order('id', 'asc')->findOrEmpty();
            if (!$existing->isEmpty()) {
                self::syncTaskFromVideoTask($existing);
                return [
                    'task_id' => (int)$existing['id'],
                    'video_task_id' => (int)$existing['video_task_id'],
                    'status' => (string)($existing['status'] ?: 'running'),
                    'error' => (string)($existing['error'] ?? ''),
                    'results' => self::taskDetail($tenantId, (int)$existing['id'], $userId)['results'] ?? [],
                ];
            }
        }
        self::assertAvailable($tenantId);
        $prepared = self::prepareGeneratePayload($tenantId, $params, true);
        $videoEstimate = self::videoEstimate($tenantId, $prepared);
        $estimate = self::buildEstimate($prepared, $videoEstimate);
        PointService::assertCanConsumeAmounts($tenantId, $userId, (float)$estimate['tenant_cost_points'], (float)$estimate['user_charge_points']);
        $result = AigcVideoService::generateMarket($tenantId, $userId, $prepared['video_payload'], self::APP_CODE);
        $videoTaskId = (int)($result['task_id'] ?? 0);
        if ($videoTaskId <= 0) {
            throw new Exception('产品宣传视频任务创建失败');
        }
        $task = self::createMappedTask($tenantId, $userId, $videoTaskId, $prepared, $estimate);
        self::syncTaskFromVideoTask($task);
        return [
            'task_id' => (int)$task['id'],
            'video_task_id' => $videoTaskId,
            'status' => (string)($task['status'] ?: 'running'),
            'error' => (string)($task['error'] ?? ''),
            'results' => self::taskDetail($tenantId, (int)$task['id'], $userId)['results'] ?? [],
            'estimate' => $estimate,
        ];
    }

    public static function writePrompt(int $tenantId, int $userId, array $params): array
    {
        $type = self::resolveType($tenantId, self::normalizeTypeCode((string)($params['video_type_code'] ?? $params['type_code'] ?? '')));
        $ratio = trim((string)($params['ratio'] ?? '9:16'));
        $duration = max(0, (int)($params['duration'] ?? 5));
        $hint = trim((string)($params['hint'] ?? $params['prompt'] ?? ''));
        $content = '请为电商产品宣传视频写一段可直接用于 AI 生视频的中文描述词。要求：突出产品卖点、镜头节奏、画面氛围、材质细节和转化导向；不要输出标题、序号和解释，只输出一段描述词。视频类型：' . ($type['name'] ?? '产品宣传') . '。比例：' . $ratio . '。时长：' . $duration . '秒。' . ($hint !== '' ? '用户补充：' . $hint : '');
        return self::generatePromptText($tenantId, $userId, $content);
    }

    public static function optimizePrompt(int $tenantId, int $userId, array $params): array
    {
        $prompt = trim((string)($params['prompt'] ?? ''));
        if ($prompt === '') {
            throw new Exception('请输入需要优化的描述词');
        }
        $type = self::resolveType($tenantId, self::normalizeTypeCode((string)($params['video_type_code'] ?? $params['type_code'] ?? '')));
        $content = '请优化下面的电商产品宣传视频 AI 生视频描述词，使其更清晰、更适合视频生成，补足镜头、节奏、光影、产品卖点和场景表达。不要输出标题、序号和解释，只输出优化后的描述词。视频类型：' . ($type['name'] ?? '产品宣传') . "。原描述词：\n" . $prompt;
        return self::generatePromptText($tenantId, $userId, $content);
    }

    public static function taskLists(int $tenantId, int $userId = 0, array $params = []): array
    {
        self::refreshMappedTasks($tenantId, $userId);
        $query = AigcProductPromoVideoTask::alias('t')
            ->leftJoin('user u', 'u.id = t.user_id AND u.tenant_id = t.tenant_id')
            ->field('t.*,u.nickname user_nickname,u.account user_account,u.mobile user_mobile')
            ->where('t.tenant_id', $tenantId)
            ->where('t.delete_time', 0)
            ->order('t.id', 'desc');
        if ($userId > 0) {
            $query->where('t.user_id', $userId);
        }
        $status = trim((string)($params['status'] ?? ''));
        if ($status !== '') {
            $query->where('t.status', $status);
        }
        $typeCode = self::normalizeTypeCode((string)($params['type_code'] ?? $params['video_type_code'] ?? ''));
        if ($typeCode !== '') {
            $query->where('t.type_code', $typeCode);
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
        $pageNo = max(1, (int)($params['page_no'] ?? 1));
        $pageSize = max(1, min(100, (int)($params['page_size'] ?? 15)));
        $count = (int)(clone $query)->count();
        $rows = $query->limit(($pageNo - 1) * $pageSize, $pageSize)->select()->toArray();
        $rows = self::appendTaskResults($tenantId, $userId, $rows);
        return ['lists' => array_map([self::class, 'formatTaskRow'], $rows), 'count' => $count, 'page_no' => $pageNo, 'page_size' => $pageSize];
    }

    public static function taskDetail(int $tenantId, int $taskId, int $userId = 0): array
    {
        self::refreshMappedTasks($tenantId, $userId, $taskId);
        $query = AigcProductPromoVideoTask::where(['tenant_id' => $tenantId, 'id' => $taskId])->where('delete_time', 0);
        if ($userId > 0) {
            $query->where('user_id', $userId);
        }
        $task = $query->findOrEmpty();
        if ($task->isEmpty()) {
            throw new Exception('任务不存在');
        }
        $rows = self::appendTaskResults($tenantId, $userId, [$task->toArray()]);
        return self::formatTaskRow($rows[0] ?? []);
    }

    public static function resultLists(int $tenantId, int $userId, array $params = []): array
    {
        $params['status'] = $params['status'] ?? 'success';
        return self::taskLists($tenantId, $userId, $params);
    }

    public static function retryTask(int $tenantId, int $taskId): array
    {
        $task = AigcProductPromoVideoTask::where(['tenant_id' => $tenantId, 'id' => $taskId])->where('delete_time', 0)->findOrEmpty();
        if ($task->isEmpty()) {
            throw new Exception('任务不存在');
        }
        return self::generate($tenantId, (int)$task['user_id'], [
            'source_image' => $task['source_image'],
            'ratio' => $task['ratio'],
            'duration' => (int)$task['duration'],
            'video_type_code' => $task['type_code'],
            'prompt' => $task['user_prompt'],
        ]);
    }

    public static function deleteTask(int $tenantId, int $taskId, int $userId = 0): void
    {
        $query = AigcProductPromoVideoTask::where(['tenant_id' => $tenantId, 'id' => $taskId]);
        if ($userId > 0) {
            $query->where('user_id', $userId);
        }
        $task = $query->findOrEmpty();
        if ($task->isEmpty()) {
            throw new Exception('任务不存在');
        }
        if ((int)$task['video_task_id'] > 0) {
            AigcVideoService::deleteTask($tenantId, (int)$task['video_task_id'], $userId);
        }
        $task->save(['delete_time' => time(), 'update_time' => time()]);
        AigcProductPromoVideoResult::where(['tenant_id' => $tenantId, 'task_id' => $taskId])->update(['delete_time' => time(), 'update_time' => time()]);
    }

    public static function deleteResult(int $tenantId, int $resultId, int $userId = 0): void
    {
        $query = AigcProductPromoVideoResult::where(['tenant_id' => $tenantId, 'id' => $resultId]);
        if ($userId > 0) {
            $query->where('user_id', $userId);
        }
        $row = $query->findOrEmpty();
        if ($row->isEmpty()) {
            throw new Exception('作品不存在');
        }
        $row->save(['delete_time' => time(), 'update_time' => time()]);
    }

    public static function dependencies(int $tenantId = 0, array $optionConfig = []): array
    {
        $installed = App::where(['code' => self::VIDEO_APP_CODE, 'status' => AppRegistryService::STATUS_INSTALLED])->count() > 0;
        $tenantEnabled = $tenantId <= 0 ? true : AppAccessService::tenantCanUse($tenantId, self::VIDEO_APP_CODE);
        $channels = [];
        $channels = (array)($optionConfig['channels'] ?? []);
        if ($channels === []) {
            try {
                $channels = AigcVideoService::marketOptions($tenantId)['channels'] ?? [];
            } catch (Exception) {
                $channels = [];
            }
        }
        $item = [
            'app_code' => self::VIDEO_APP_CODE,
            'name' => 'AIGC生视频',
            'required_for' => '产品宣传视频生成',
            'installed' => $installed,
            'tenant_enabled' => $tenantEnabled,
            'channel_ready' => !empty($channels),
            'ready' => $installed && $tenantEnabled && !empty($channels),
            'message' => $installed ? ($tenantEnabled ? (!empty($channels) ? '可用' : '暂无可用视频通道') : '租户未开通或未上架') : '应用未安装或未启用',
        ];
        return ['items' => [$item], 'ready' => (bool)$item['ready']];
    }

    private static function assertAvailable(int $tenantId): void
    {
        if (AppAccessService::assertTenantCanUse($tenantId, self::APP_CODE) !== null) {
            throw new Exception('产品宣传视频应用未开通或未上架');
        }
        if (AppAccessService::assertTenantCanUse($tenantId, self::VIDEO_APP_CODE) !== null) {
            throw new Exception('AIGC生视频应用未开通或未上架');
        }
        $config = self::config($tenantId);
        if ((int)($config['status'] ?? 1) !== 1) {
            throw new Exception('产品宣传视频应用已停用');
        }
        if (empty($config['dependencies']['ready'])) {
            throw new Exception($config['dependencies']['items'][0]['message'] ?? 'AIGC生视频不可用');
        }
    }

    private static function prepareGeneratePayload(int $tenantId, array $params, bool $requireImage): array
    {
        $config = self::config($tenantId);
        $sourceImage = self::normalizeImage($params['source_image'] ?? $params['image'] ?? $params['source'] ?? '');
        if ($requireImage && $sourceImage === '') {
            throw new Exception('请上传产品图片');
        }
        $type = self::resolveType($tenantId, self::normalizeTypeCode((string)($params['video_type_code'] ?? $params['type_code'] ?? '')));
        $ratio = trim((string)($params['ratio'] ?? $config['default_ratio'] ?? $config['config_json']['ratio'] ?? ''));
        $duration = max(0, (int)($params['duration'] ?? $config['default_duration'] ?? $config['config_json']['duration'] ?? 0));
        $spec = self::resolvePricedSpec($config, $ratio, $duration);
        $userPrompt = mb_substr(trim((string)($params['prompt'] ?? $params['user_prompt'] ?? '')), 0, 2000);
        $finalPrompt = self::buildPrompt($config, $type, $userPrompt);
        $marketEnabled = true;
        $generationMethod = (string)($spec['generation_method'] ?? $spec['input_mode'] ?? 'image_reference');
        $referenceAsset = ['type' => 'image', 'uri' => $sourceImage];
        if ($generationMethod === 'image_to_video') {
            $referenceAsset['role'] = 'first_frame_image';
        }
        $videoPayload = [
            'prompt' => $finalPrompt,
            'negative_prompt' => (string)($params['negative_prompt'] ?? $config['negative_prompt']),
            'reference_images' => array_values(array_filter([$sourceImage])),
            'reference_assets' => $sourceImage !== '' ? [$referenceAsset] : [],
            'channel' => $spec['channel'],
            'quality' => $spec['quality'],
            'ratio' => $spec['ratio'],
            'duration' => $spec['duration'],
            'quantity' => 1,
            'style' => 'product_promo_video',
        ];
        $videoPayload = array_merge($videoPayload, [
            'model_id' => (string)($spec['model_id'] ?? $spec['channel']),
            'market_product_id' => (int)($spec['market_product_id'] ?? 0),
            'market_sku_id' => (int)($spec['market_sku_id'] ?? 0),
            'resource_type' => (string)($spec['resource_type'] ?? ''),
            'generation_method' => $generationMethod,
        ]);
        if (trim((string)($params['idempotency_key'] ?? '')) !== '') {
            $videoPayload['idempotency_key'] = trim((string)$params['idempotency_key']);
        }
        if (!empty($spec['mode'])) {
            $videoPayload['mode'] = $spec['mode'];
        }
        return [
            'source_image' => $sourceImage,
            'type' => $type,
            'user_prompt' => $userPrompt,
            'market_enabled' => $marketEnabled,
            'video_payload' => $videoPayload,
            'width' => (int)($spec['width'] ?? 0),
            'height' => (int)($spec['height'] ?? 0),
            'quality_label' => (string)($spec['quality_label'] ?? $spec['quality']),
            'size_key' => (string)$videoPayload['ratio'],
            'config' => $config,
        ];
    }

    private static function buildEstimate(array $prepared, array $videoEstimate): array
    {
        if ((string)($videoEstimate['settlement_mode'] ?? 'reserved') === 'actual_usage') {
            throw new Exception('产品宣传视频暂不支持按实际用量计费的视频 SKU');
        }
        $tenantCost = round((float)($videoEstimate['tenant_cost_points'] ?? 0), 2);
        $duration = max(1, (int)($prepared['video_payload']['duration'] ?? 0));
        $userPrice = round((float)($videoEstimate['user_charge_points'] ?? 0), 2);
        $userUnitPrice = round((float)($videoEstimate['user_unit_points'] ?? ($duration > 0 ? $userPrice / $duration : $userPrice)), 2);
        return array_merge($videoEstimate, [
            'quantity' => 1,
            'target_width' => $prepared['width'],
            'target_height' => $prepared['height'],
            'size_key' => $prepared['size_key'],
            'platform_unit_cost' => $videoEstimate['platform_unit_cost'] ?? $tenantCost,
            'tenant_unit_price' => $userUnitPrice,
            // Legacy task column: derived from the market total for history only.
            'unit_price' => round($userPrice / $duration, 2),
            'duration' => $duration,
            'tenant_cost_points' => $tenantCost,
            'user_charge_points' => $userPrice,
            'display_points' => $userPrice,
        ]);
    }

    private static function videoEstimate(int $tenantId, array $prepared): array
    {
        return AigcVideoService::estimate($tenantId, $prepared['video_payload']);
    }

    /**
     * The product-promo UI has a compact channel/quality/ratio contract. Map
     * market video options into that contract while retaining the exact SKU
     * required for submission and billing.
     */
    private static function marketOptionConfig(int $tenantId): array
    {
        $market = AigcVideoService::marketOptions($tenantId);
        $channels = [];
        foreach (array_merge((array)($market['models'] ?? []), (array)($market['applications'] ?? [])) as $option) {
            if (!is_array($option)) {
                continue;
            }
            $generationMethod = self::marketPromoGenerationMethod($option);
            $qualities = [];
            foreach ((array)($option['specs'] ?? []) as $spec) {
                if (!is_array($spec) || (int)($spec['market_sku_id'] ?? 0) <= 0) {
                    continue;
                }
                $resolution = (string)($spec['resolution'] ?? $spec['quality'] ?? '');
                if ($resolution === '') {
                    continue;
                }
                $selections = self::marketSpecSelections($option, $spec);
                if ($selections === []) {
                    continue;
                }
                // A SKU's selectable ratio/duration values are already exposed
                // in its spec. Verify the SKU once; its unit price is fixed.
                $quote = self::marketImageReferenceQuote($tenantId, $option, array_merge($spec, [
                    'ratio' => $selections[0]['ratio'],
                    'duration' => $selections[0]['duration'],
                ]));
                $status = 1;
                $unavailableReason = '';
                if ($generationMethod === '') {
                    $status = 0;
                    $unavailableReason = '该模型或应用不支持产品图片输入';
                } elseif ($quote === null) {
                    $status = 0;
                    $unavailableReason = '当前市场 SKU 暂无法按该规格报价';
                } elseif ((string)($quote['settlement_mode'] ?? 'reserved') === 'actual_usage') {
                    $status = 0;
                    $unavailableReason = '该 SKU 按实际用量计费，产品宣传视频暂不支持';
                }
                foreach ($selections as $selection) {
                    $ratio = $selection['ratio'];
                    $duration = $selection['duration'];
                    $inputMode = (string)($quote['generation_method'] ?? $generationMethod);
                    if ($inputMode === '') {
                        $inputMode = (string)($spec['input_mode'] ?? 'text_to_video');
                    }
                    $qualities[$resolution]['value'] = $resolution;
                    $qualities[$resolution]['label'] = strtoupper($resolution);
                    $qualities[$resolution]['ratios'][] = [
                        'value' => $ratio,
                        'ratio' => $ratio,
                        'label' => $ratio,
                        'duration' => $duration,
                        'market_product_id' => (int)($option['market_product_id'] ?? 0),
                        'market_sku_id' => (int)($quote['market_sku_id'] ?? $spec['market_sku_id']),
                        'model_id' => (string)($option['id'] ?? ''),
                        'resource_type' => (string)($option['resource_type'] ?? ''),
                        'platform_unit_cost' => (float)($quote['platform_unit_cost'] ?? $spec['platform_unit_cost'] ?? 0),
                        'tenant_unit_price' => (float)($quote['tenant_unit_price'] ?? $spec['tenant_unit_price'] ?? 0),
                        'usage_unit' => (string)($quote['usage_unit'] ?? $spec['usage_unit'] ?? ''),
                        'usage_unit_size' => (float)($quote['usage_unit_size'] ?? $spec['usage_unit_size'] ?? 1),
                        'input_mode' => $inputMode,
                        'generation_method' => (string)($quote['generation_method'] ?? $generationMethod),
                        'status' => $status,
                        'unavailable_reason' => $unavailableReason,
                    ];
                }
            }
            $channelCode = (string)($option['id'] ?? '');
            $hasUsableSpec = self::channelHasUsableSpec($qualities);
            $channels[] = [
                'code' => $channelCode,
                'name' => (string)($option['name'] ?? $channelCode),
                'qualities' => array_values($qualities),
                'resource_type' => (string)($option['resource_type'] ?? ''),
                'market_product_id' => (int)($option['market_product_id'] ?? 0),
                'app_code' => (string)($option['app_code'] ?? ''),
                'api_code' => (string)($option['api_code'] ?? ''),
                'status' => $hasUsableSpec ? 1 : 0,
                'unavailable_reason' => $hasUsableSpec ? '' : '该模型或应用当前没有可用于产品宣传视频的固定计费规格',
            ];
        }
        $usableChannels = array_values(array_filter($channels, static fn(array $channel): bool => (int)($channel['status'] ?? 0) === 1));
        $firstChannel = $usableChannels[0] ?? $channels[0] ?? [];
        $firstQuality = (array)(($firstChannel['qualities'] ?? [])[0] ?? []);
        foreach ((array)($firstChannel['qualities'] ?? []) as $quality) {
            if (self::qualityHasUsableSpec($quality)) {
                $firstQuality = $quality;
                break;
            }
        }
        $firstSpec = (array)(($firstQuality['ratios'] ?? [])[0] ?? []);
        foreach ((array)($firstQuality['ratios'] ?? []) as $spec) {
            if ((int)($spec['status'] ?? 0) === 1) {
                $firstSpec = $spec;
                break;
            }
        }
        return [
            'market_mode' => true,
            'channels' => $channels,
            'defaults' => [
                'channel' => (string)($firstChannel['code'] ?? ''),
                'quality' => (string)($firstQuality['value'] ?? ''),
                'ratio' => (string)($firstSpec['ratio'] ?? ''),
                'duration' => (int)($firstSpec['duration'] ?? 0),
            ],
        ];
    }

    private static function supportsImageReference(array $option): bool
    {
        return self::marketPromoGenerationMethod($option) !== '';
    }

    private static function channelHasUsableSpec(array $qualities): bool
    {
        foreach ($qualities as $quality) {
            if (self::qualityHasUsableSpec($quality)) {
                return true;
            }
        }
        return false;
    }

    private static function qualityHasUsableSpec(array $quality): bool
    {
        foreach ((array)($quality['ratios'] ?? []) as $ratio) {
            if ((int)($ratio['status'] ?? 0) === 1) {
                return true;
            }
        }
        return false;
    }

    /** @return array<int, array{ratio:string,duration:int}> */
    private static function marketSpecSelections(array $option, array $spec): array
    {
        $ratios = (array)($spec['ratio_options'] ?? []);
        if ($ratios === []) {
            $ratios = [(string)($spec['ratio'] ?? '')];
        }
        if ($ratios === [] || $ratios === ['']) {
            $ratios = (array)($option['ratio_options'] ?? []);
        }
        $ratios = array_values(array_filter(array_map(static function ($ratio): string {
            return is_array($ratio) ? (string)($ratio['value'] ?? $ratio['ratio'] ?? '') : (string)$ratio;
        }, $ratios)));

        $durations = array_values(array_filter(array_map('intval', (array)($spec['duration_options'] ?? []))));
        if ($durations === [] && (int)($spec['duration'] ?? 0) > 0) {
            $durations = [(int)$spec['duration']];
        }
        if ($durations === []) {
            $durations = array_values(array_filter(array_map('intval', (array)($option['duration_options'] ?? []))));
        }

        $selections = [];
        foreach ($ratios as $ratio) {
            foreach ($durations as $duration) {
                $selections[] = ['ratio' => $ratio, 'duration' => $duration];
            }
        }
        return $selections;
    }

    /**
     * Some product-level capability declarations cover multiple SKU variants.
     * Only expose a SKU when image-reference selection resolves back to that
     * exact SKU, otherwise quote and final submission could diverge.
     */
    private static function marketImageReferenceQuote(int $tenantId, array $option, array $spec): ?array
    {
        $skuId = (int)($spec['market_sku_id'] ?? 0);
        if ($skuId <= 0) {
            return null;
        }
        $generationMethod = self::marketPromoGenerationMethod($option);
        if ($generationMethod === '') {
            return null;
        }
        return [
            'market_product_id' => (int)($option['market_product_id'] ?? 0),
            'market_sku_id' => $skuId,
            'platform_unit_cost' => (float)($spec['platform_unit_cost'] ?? 0),
            'tenant_unit_price' => (float)($spec['tenant_unit_price'] ?? 0),
            'usage_unit' => (string)($spec['usage_unit'] ?? ''),
            'usage_unit_size' => (float)($spec['usage_unit_size'] ?? 1),
            'settlement_mode' => (string)($spec['settlement_mode'] ?? 'reserved'),
            'generation_method' => $generationMethod,
        ];
    }

    /** Select the strongest mode that can generate a product video from one image. */
    private static function marketPromoGenerationMethod(array $option): string
    {
        $modes = self::promoModes($option);
        foreach (['image_reference', 'image_to_video', 'omni_reference', 'text_to_video', 'video_edit', 'audio_reference'] as $mode) {
            if (in_array($mode, $modes, true)) {
                return $mode;
            }
        }
        $assetTypes = self::promoAssetTypes($option);
        if (in_array('image', $assetTypes, true) && (int)($option['max_reference_images'] ?? 0) > 0) {
            return 'image_reference';
        }
        if (in_array('video', $assetTypes, true) && (int)($option['max_reference_videos'] ?? 0) > 0) {
            return 'video_edit';
        }
        if (in_array('audio', $assetTypes, true) && (int)($option['max_reference_audios'] ?? 0) > 0) {
            return 'audio_reference';
        }
        return '';
    }

    /**
     * @return array<int, string>
     */
    private static function promoModes(array $option): array
    {
        $modes = [];
        $capabilities = self::arrayValue($option['capabilities'] ?? []);
        foreach ([
            (array)($option['input_modes'] ?? []),
            (array)($option['generation_modes'] ?? []),
            (array)($capabilities['input_modes'] ?? []),
            (array)($capabilities['generation_modes'] ?? []),
        ] as $source) {
            foreach ($source as $mode) {
                $value = is_array($mode) ? ($mode['value'] ?? $mode['code'] ?? $mode['mode'] ?? '') : $mode;
                $value = strtolower(trim((string)$value));
                $value = match ($value) {
                    't2v', 'text', 'text2video', 'text-to-video' => 'text_to_video',
                    'i2v', 'image', 'image2video', 'image-to-video', 'singleimage2video', 'single-image-to-video' => 'image_to_video',
                    'reference_image', 'image-reference' => 'image_reference',
                    'mixed2video', 'mixed-to-video' => 'omni_reference',
                    'video_reference', 'video-reference', 'video_to_video', 'video-to-video', 'videoedit2video', 'video2video', 'video-edit-to-video' => 'video_edit',
                    'audio2video', 'audio_to_video', 'audio-to-video', 'audio_reference', 'audio-reference' => 'audio_reference',
                    default => $value,
                };
                if (in_array($value, ['text_to_video', 'image_to_video', 'image_reference', 'omni_reference', 'video_edit', 'audio_reference'], true) && !in_array($value, $modes, true)) {
                    $modes[] = $value;
                }
            }
        }
        return $modes;
    }

    /**
     * @return array<int, string>
     */
    private static function promoAssetTypes(array $option): array
    {
        $capabilities = self::arrayValue($option['capabilities'] ?? []);
        $values = $option['supported_asset_types'] ?? $capabilities['supported_asset_types'] ?? [];
        return array_values(array_intersect(['image', 'video', 'audio'], array_map(static fn($value): string => strtolower((string)$value), (array)$values)));
    }

    /**
     * A provider switch must not persist the previous provider's model ID.
     * Keep a matching selection when possible; otherwise use the first SKU
     * that the new runtime has declared usable.
     */
    private static function alignDefaultsToOptionConfig(array $data, array $optionConfig): array
    {
        $channels = array_values(array_filter((array)($optionConfig['channels'] ?? []), 'is_array'));
        if ($channels === []) {
            throw new Exception('暂无支持产品图片参考输入的算力市场视频规格');
        }

        $channelCode = (string)($data['default_channel'] ?? $data['config_json']['channel'] ?? '');
        $channel = null;
        foreach ($channels as $item) {
            if ((string)($item['code'] ?? '') === $channelCode && self::channelHasUsableSpec((array)($item['qualities'] ?? []))) {
                $channel = $item;
                break;
            }
        }
        $channel ??= array_values(array_filter($channels, static fn(array $item): bool => self::channelHasUsableSpec((array)($item['qualities'] ?? []))))[0] ?? null;
        $channel ??= $channels[0];

        $qualities = array_values(array_filter((array)($channel['qualities'] ?? []), 'is_array'));
        if ($qualities === []) {
            throw new Exception('当前视频模型没有可用规格');
        }
        $qualityValue = (string)($data['default_quality'] ?? $data['config_json']['quality'] ?? '');
        $quality = null;
        foreach ($qualities as $item) {
            if ((string)($item['value'] ?? $item['quality'] ?? '') === $qualityValue && self::qualityHasUsableSpec($item)) {
                $quality = $item;
                break;
            }
        }
        $quality ??= array_values(array_filter($qualities, static fn(array $item): bool => self::qualityHasUsableSpec($item)))[0] ?? $qualities[0];

        $ratios = array_values(array_filter((array)($quality['ratios'] ?? []), 'is_array'));
        if ($ratios === []) {
            throw new Exception('当前视频规格没有可用比例');
        }
        $ratioValue = (string)($data['default_ratio'] ?? $data['config_json']['ratio'] ?? '');
        $ratio = null;
        foreach ($ratios as $item) {
            if ((string)($item['value'] ?? $item['ratio'] ?? '') === $ratioValue && (int)($item['status'] ?? 0) === 1) {
                $ratio = $item;
                break;
            }
        }
        $ratio ??= array_values(array_filter($ratios, static fn(array $item): bool => (int)($item['status'] ?? 0) === 1))[0] ?? $ratios[0];

        $data['default_channel'] = (string)($channel['code'] ?? '');
        $data['default_quality'] = (string)($quality['value'] ?? $quality['quality'] ?? '');
        $data['default_ratio'] = (string)($ratio['value'] ?? $ratio['ratio'] ?? '');
        $data['config_json']['channel'] = $data['default_channel'];
        $data['config_json']['quality'] = $data['default_quality'];
        $data['config_json']['ratio'] = $data['default_ratio'];
        return $data;
    }

    private static function createMappedTask(int $tenantId, int $userId, int $videoTaskId, array $prepared, array $estimate): AigcProductPromoVideoTask
    {
        $videoTask = AigcVideoTask::where(['tenant_id' => $tenantId, 'id' => $videoTaskId])->findOrEmpty();
        if ($videoTask->isEmpty()) {
            throw new Exception('生视频任务不存在');
        }
        $type = $prepared['type'];
        return AigcProductPromoVideoTask::create([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'video_task_id' => $videoTaskId,
            'app_task_id' => (int)($videoTask['app_task_id'] ?? 0),
            'consumption_id' => (int)($videoTask['consumption_id'] ?? 0),
            'market_product_id' => (int)($videoTask['market_product_id'] ?? 0),
            'market_sku_id' => (int)($videoTask['market_sku_id'] ?? 0),
            'pricing_snapshot' => (array)($videoTask['pricing_snapshot'] ?? []),
            'billing_status' => (int)($videoTask['consumption_id'] ?? 0) > 0 ? 'reserved' : 'none',
            'source_image' => $prepared['source_image'],
            'type_code' => (string)$type['code'],
            'type_name' => (string)$type['name'],
            'type_snapshot' => $type,
            'size_key' => $prepared['size_key'],
            'width' => $prepared['width'],
            'height' => $prepared['height'],
            'duration' => (int)$prepared['video_payload']['duration'],
            'prompt' => $prepared['video_payload']['prompt'],
            'negative_prompt' => $prepared['video_payload']['negative_prompt'],
            'user_prompt' => $prepared['user_prompt'],
            'channel' => (string)($videoTask['channel'] ?? $prepared['video_payload']['channel']),
            'quality' => (string)($videoTask['quality'] ?? $prepared['video_payload']['quality']),
            'quality_label' => $prepared['quality_label'],
            'ratio' => (string)($videoTask['ratio'] ?? $prepared['video_payload']['ratio']),
            'unit_price' => (float)($estimate['unit_price'] ?? 0),
            'tenant_cost_points' => $estimate['tenant_cost_points'],
            'user_charge_points' => $estimate['user_charge_points'],
            'idempotency_key' => trim((string)($prepared['video_payload']['idempotency_key'] ?? '')),
            'status' => 'running',
            'error' => '',
            'finish_time' => 0,
            'delete_time' => 0,
            'create_time' => time(),
            'update_time' => time(),
        ]);
    }

    private static function syncTaskFromVideoTask(AigcProductPromoVideoTask $task): void
    {
        $tenantId = (int)$task['tenant_id'];
        $userId = (int)$task['user_id'];
        $videoTaskId = (int)$task['video_task_id'];
        if ($videoTaskId <= 0) {
            return;
        }
        try {
            AigcVideoService::taskDetail($tenantId, $videoTaskId, $userId);
        } catch (\Throwable) {
        }
        $videoTask = AigcVideoTask::where('tenant_id', $tenantId)->where('id', $videoTaskId)->where('delete_time', 0)->findOrEmpty();
        if ($videoTask->isEmpty()) {
            return;
        }
        self::syncResultsFromVideoTask($task);
        $task->status = (string)($videoTask['status'] ?? 'running') ?: 'running';
        $task->error = trim((string)($videoTask['error'] ?? ''));
        $task->finish_time = (int)($videoTask['finish_time'] ?? 0);
        $task->app_task_id = (int)($videoTask['app_task_id'] ?? $task['app_task_id'] ?? 0);
        $task->consumption_id = (int)($videoTask['consumption_id'] ?? $task['consumption_id'] ?? 0);
        $task->market_product_id = (int)($videoTask['market_product_id'] ?? $task['market_product_id'] ?? 0);
        $task->market_sku_id = (int)($videoTask['market_sku_id'] ?? $task['market_sku_id'] ?? 0);
        $task->pricing_snapshot = (array)($videoTask['pricing_snapshot'] ?? $task['pricing_snapshot'] ?? []);
        $task->billing_status = self::marketBillingStatus((string)($videoTask['status'] ?? ''), (int)($videoTask['consumption_id'] ?? 0));
        $task->tenant_cost_points = number_format((float)($videoTask['tenant_cost_points'] ?? $task['tenant_cost_points'] ?? 0), 2, '.', '');
        $task->user_charge_points = number_format((float)($videoTask['user_charge_points'] ?? $task['user_charge_points'] ?? 0), 2, '.', '');
        $task->update_time = time();
        $task->save();
    }

    /** Called by the market result worker after the linked video task is reconciled. */
    public static function syncMarketVideoTask(int $tenantId, int $videoTaskId): void
    {
        if ($tenantId <= 0 || $videoTaskId <= 0) {
            return;
        }
        $rows = AigcProductPromoVideoTask::where([
            'tenant_id' => $tenantId,
            'video_task_id' => $videoTaskId,
            'delete_time' => 0,
        ])->select();
        foreach ($rows as $row) {
            self::syncTaskFromVideoTask($row);
        }
    }

    private static function marketBillingStatus(string $videoStatus, int $consumptionId): string
    {
        if ($consumptionId <= 0) {
            return 'none';
        }
        return match ($videoStatus) {
            'success' => 'settled',
            'failed', 'canceled' => 'refunded',
            default => 'reserved',
        };
    }

    private static function syncResultsFromVideoTask(AigcProductPromoVideoTask $task): void
    {
        $tenantId = (int)$task['tenant_id'];
        $userId = (int)$task['user_id'];
        $videoTaskId = (int)$task['video_task_id'];
        $videoResults = AigcVideoResult::where('tenant_id', $tenantId)
            ->where('task_id', $videoTaskId)
            ->where('delete_time', 0)
            ->where('video_uri', '<>', '')
            ->order('id', 'asc')
            ->select()
            ->toArray();
        foreach ($videoResults as $result) {
            $videoResultId = (int)($result['id'] ?? 0);
            $videoUri = trim((string)($result['video_uri'] ?? ''));
            if ($videoResultId <= 0 || $videoUri === '') {
                continue;
            }
            $exists = AigcProductPromoVideoResult::where(['tenant_id' => $tenantId, 'video_result_id' => $videoResultId])->findOrEmpty();
            if (!$exists->isEmpty()) {
                continue;
            }
            AigcProductPromoVideoResult::create([
                'tenant_id' => $tenantId,
                'task_id' => (int)$task['id'],
                'video_task_id' => $videoTaskId,
                'video_result_id' => $videoResultId,
                'user_id' => $userId,
                'source_image' => (string)$task['source_image'],
                'type_code' => (string)$task['type_code'],
                'type_name' => (string)$task['type_name'],
                'video_uri' => $videoUri,
                'cover_uri' => (string)($result['cover_uri'] ?? ''),
                'storage_scope' => (string)($result['storage_scope'] ?? 'tenant'),
                'storage_engine' => (string)($result['storage_engine'] ?? 'local'),
                'storage_domain' => (string)($result['storage_domain'] ?? ''),
                'width' => (int)($task['width'] ?: ($result['width'] ?? 0)),
                'height' => (int)($task['height'] ?: ($result['height'] ?? 0)),
                'delete_time' => 0,
                'create_time' => time(),
                'update_time' => time(),
            ]);
        }
    }

    private static function refreshMappedTasks(int $tenantId, int $userId = 0, int $taskId = 0): void
    {
        $query = AigcProductPromoVideoTask::where('tenant_id', $tenantId)->where('delete_time', 0);
        if ($userId > 0) {
            $query->where('user_id', $userId);
        }
        if ($taskId > 0) {
            $query->where('id', $taskId);
        } else {
            $query->whereIn('status', ['running', 'pending', 'success']);
        }
        foreach ($query->limit(20)->select() as $row) {
            self::syncTaskFromVideoTask($row);
        }
    }

    private static function appendTaskResults(int $tenantId, int $userId, array $rows): array
    {
        $taskIds = array_values(array_unique(array_filter(array_column($rows, 'id'))));
        $resultMap = [];
        if ($taskIds) {
            $query = AigcProductPromoVideoResult::where('tenant_id', $tenantId)
                ->where('delete_time', 0)
                ->where('video_uri', '<>', '')
                ->whereIn('task_id', $taskIds)
                ->order('id', 'asc');
            if ($userId > 0) {
                $query->where('user_id', $userId);
            }
            foreach ($query->select()->toArray() as $result) {
                $result['video_url'] = FileService::getFileUrlByStorage($result['video_uri'], $result['storage_scope'] ?? '', $result['storage_engine'] ?? '', $result['storage_domain'] ?? '');
                $result['cover_url'] = self::imageUrl((string)($result['cover_uri'] ?? ''));
                if (trim((string)$result['video_url']) === '') {
                    continue;
                }
                $result['download_url'] = $result['video_url'];
                $resultMap[(int)$result['task_id']][] = $result;
            }
        }
        foreach ($rows as &$row) {
            $results = $resultMap[(int)$row['id']] ?? [];
            $row['results'] = $results;
            $row['result_count'] = count($results);
            $first = $results[0] ?? [];
            $row['video_url'] = (string)($first['video_url'] ?? '');
            $row['cover_url'] = (string)($first['cover_url'] ?? '');
            $row['download_url'] = (string)($first['download_url'] ?? '');
            $row['video_uri'] = (string)($first['video_uri'] ?? '');
            $row['source_image_url'] = self::imageUrl((string)($row['source_image'] ?? ''));
            $row['source_image_urls'] = array_values(array_filter([$row['source_image_url']]));
        }
        return $rows;
    }

    private static function formatTaskRow(array $row): array
    {
        $row['task_id'] = (int)($row['id'] ?? 0);
        $row['video_task_id'] = (int)($row['video_task_id'] ?? 0);
        $row['duration_label'] = !empty($row['duration']) ? ((int)$row['duration'] . '秒') : '';
        $row['size_label'] = self::sizeLabel((string)($row['quality_label'] ?? $row['quality'] ?? ''), (string)($row['ratio'] ?? ''), (int)($row['width'] ?? 0), (int)($row['height'] ?? 0), (int)($row['duration'] ?? 0));
        $row['status_label'] = match ((string)($row['status'] ?? '')) {
            'success' => '已完成',
            'failed' => '失败',
            'partial_failed' => '部分失败',
            'canceled' => '已取消',
            'pending' => '排队中',
            default => '生成中',
        };
        return $row;
    }

    private static function resolveType(int $tenantId, string $code): array
    {
        self::ensureDefaultTypes($tenantId);
        $query = AigcProductPromoVideoType::where('tenant_id', $tenantId)->where('delete_time', 0)->where('status', 1);
        $code !== '' ? $query->where('code', $code) : $query->order('sort', 'desc')->order('id', 'asc');
        $row = $query->findOrEmpty();
        if ($row->isEmpty()) {
            throw new Exception('请选择可用的视频类型');
        }
        return $row->toArray();
    }

    private static function resolvePricedSpec(array $config, string $ratio, int $duration): array
    {
        $defaultChannel = (string)($config['default_channel'] ?? $config['config_json']['channel'] ?? '');
        $defaultQuality = (string)($config['default_quality'] ?? $config['config_json']['quality'] ?? '');
        $defaultDuration = (int)($config['default_duration'] ?? $config['config_json']['duration'] ?? 0);
        $candidates = [];
        foreach (($config['spec_options'] ?? []) as $channel) {
            foreach (($channel['qualities'] ?? []) as $quality) {
                foreach (($quality['ratios'] ?? []) as $spec) {
                    if ((int)($spec['status'] ?? 1) !== 1) {
                        continue;
                    }
                    $candidates[] = $spec;
                }
            }
        }
        foreach ($candidates as $item) {
            if ($ratio !== '' && (string)$item['ratio'] !== $ratio) {
                continue;
            }
            if ($duration > 0 && (int)$item['duration'] !== $duration) {
                continue;
            }
            if ($defaultChannel !== '' && (string)$item['channel'] !== $defaultChannel) {
                continue;
            }
            if ($defaultQuality !== '' && (string)$item['quality'] !== $defaultQuality) {
                continue;
            }
            return $item;
        }
        foreach ($candidates as $item) {
            if (($ratio === '' || (string)$item['ratio'] === $ratio) && ($duration <= 0 || (int)$item['duration'] === $duration)) {
                return $item;
            }
        }
        foreach ($candidates as $item) {
            if (($ratio === '' || (string)$item['ratio'] === $ratio) && ($defaultDuration <= 0 || (int)$item['duration'] === $defaultDuration)) {
                return $item;
            }
        }
        throw new Exception('当前比例和时长暂不可用，请联系管理员');
    }

    private static function buildPrompt(array $config, array $type, string $userPrompt): string
    {
        $typePrompt = trim((string)($type['prompt'] ?? ''));
        $userPrompt = trim($userPrompt);
        $template = self::normalizeTemplate((string)($config['prompt_template'] ?? ''));
        $text = strtr($template, [
            '{type_name}' => (string)($type['name'] ?? ''),
            '{type_prompt}' => $typePrompt !== '' ? '视频类型要求：' . $typePrompt . '。' : '',
            '{user_prompt}' => $userPrompt !== '' ? '用户描述词：' . $userPrompt . '。' : '',
        ]);
        if ($typePrompt !== '' && !str_contains($template, '{type_prompt}')) {
            $text .= ' 视频类型要求：' . $typePrompt . '。';
        }
        if ($userPrompt !== '' && !str_contains($template, '{user_prompt}')) {
            $text .= ' 用户描述词：' . $userPrompt . '。';
        }
        return trim($text);
    }

    private static function generatePromptText(int $tenantId, int $userId, string $content): array
    {
        if (AppAccessService::assertTenantCanUse($tenantId, self::LLM_APP_CODE) !== null) {
            throw new Exception('AIGC对话应用未开通，暂无法使用AI帮写');
        }
        $result = AigcLlmService::generateText($tenantId, $userId, [
            'content' => $content,
            'source_app_code' => self::APP_CODE,
            'source_type' => 'prompt_helper',
        ]);
        return [
            'prompt' => trim((string)($result['content'] ?? '')),
            'charge_points' => $result['charge_points'] ?? 0,
            'model_code' => $result['model_code'] ?? '',
        ];
    }

    private static function buildSpecOptions(array $optionConfig): array
    {
        $channels = [];
        foreach (($optionConfig['channels'] ?? []) as $channel) {
            $qualities = [];
            foreach (($channel['qualities'] ?? []) as $quality) {
                $ratios = [];
                foreach (($quality['ratios'] ?? []) as $ratio) {
                    $durations = self::durationOptionsForSpec($channel, $quality, $ratio);
                    foreach ($durations as $duration) {
                        $row = [
                            'channel' => (string)($channel['code'] ?? ''),
                            'channel_name' => (string)($channel['name'] ?? ''),
                            'quality' => (string)($quality['value'] ?? ''),
                            'quality_label' => (string)($quality['label'] ?? $quality['quality_label'] ?? $quality['value'] ?? ''),
                            'ratio' => (string)($ratio['value'] ?? $ratio['ratio'] ?? ''),
                            'ratio_label' => (string)($ratio['label'] ?? $ratio['ratio'] ?? $ratio['value'] ?? ''),
                            'duration' => (int)$duration,
                            'width' => (int)($ratio['width'] ?? 0),
                            'height' => (int)($ratio['height'] ?? 0),
                            'platform_unit_cost' => round((float)($ratio['platform_unit_cost'] ?? 0), 2),
                            'market_product_id' => (int)($ratio['market_product_id'] ?? 0),
                            'market_sku_id' => (int)($ratio['market_sku_id'] ?? 0),
                            'model_id' => (string)($ratio['model_id'] ?? $channel['code'] ?? ''),
                            'resource_type' => (string)($ratio['resource_type'] ?? ''),
                            'generation_method' => (string)($ratio['generation_method'] ?? $ratio['input_mode'] ?? 'image_reference'),
                            'tenant_unit_price' => round(max(0, (float)($ratio['tenant_unit_price'] ?? $ratio['user_price'] ?? 0)), 2),
                            'base_unit_price' => self::specSecondUnitPrice($ratio, (int)$duration),
                            'unit_price' => self::specSecondUnitPrice($ratio, (int)$duration),
                            'user_price' => round(max(0, (float)($ratio['tenant_unit_price'] ?? $ratio['user_price'] ?? 0)), 2),
                            'status' => (int)($ratio['status'] ?? $quality['status'] ?? $channel['status'] ?? 1) ? 1 : 0,
                            'priced' => 1,
                        ];
                        $ratios[] = $row;
                    }
                }
                $qualities[] = [
                    'value' => (string)($quality['value'] ?? ''),
                    'label' => (string)($quality['label'] ?? $quality['quality_label'] ?? $quality['value'] ?? ''),
                    'ratios' => $ratios,
                ];
            }
            $channels[] = ['code' => (string)($channel['code'] ?? ''), 'name' => (string)($channel['name'] ?? ''), 'qualities' => $qualities];
        }
        return $channels;
    }

    private static function buildRatioOptions(array $channels, string $channelCode = '', string $qualityValue = ''): array
    {
        $map = [];
        foreach ($channels as $channel) {
            if ($channelCode !== '' && (string)($channel['code'] ?? '') !== $channelCode) {
                continue;
            }
            foreach (($channel['qualities'] ?? []) as $quality) {
                if ($qualityValue !== '' && (string)($quality['value'] ?? '') !== $qualityValue) {
                    continue;
                }
                foreach (($quality['ratios'] ?? []) as $spec) {
                    if ((int)($spec['status'] ?? 1) !== 1) {
                        continue;
                    }
                    $ratio = (string)($spec['ratio'] ?? '');
                    if ($ratio !== '') {
                        $map[$ratio] = ['label' => (string)($spec['ratio_label'] ?? $ratio), 'value' => $ratio];
                    }
                }
            }
        }
        return array_values($map);
    }

    private static function buildDurationOptions(array $channels, string $ratio = '', string $channelCode = '', string $qualityValue = ''): array
    {
        $map = [];
        foreach ($channels as $channel) {
            if ($channelCode !== '' && (string)($channel['code'] ?? '') !== $channelCode) {
                continue;
            }
            foreach (($channel['qualities'] ?? []) as $quality) {
                if ($qualityValue !== '' && (string)($quality['value'] ?? '') !== $qualityValue) {
                    continue;
                }
                foreach (($quality['ratios'] ?? []) as $spec) {
                    if ((int)($spec['status'] ?? 1) !== 1) {
                        continue;
                    }
                    if ($ratio !== '' && (string)($spec['ratio'] ?? '') !== $ratio) {
                        continue;
                    }
                    $duration = (int)($spec['duration'] ?? 0);
                    if ($duration > 0) {
                        $map[$duration] = ['label' => $duration . '秒', 'value' => $duration];
                    }
                }
            }
        }
        ksort($map);
        return array_values($map);
    }

    private static function durationOptionsForSpec(array $channel, array $quality, array $ratio): array
    {
        if ((int)($ratio['market_sku_id'] ?? 0) > 0 && (int)($ratio['duration'] ?? 0) > 0) {
            return [(int)$ratio['duration']];
        }
        $options = array_values(array_filter(array_map('intval', $channel['duration_options'] ?? [])));
        if (!$options) {
            $duration = (int)($quality['duration'] ?? 0);
            $options = [$duration > 0 ? $duration : 5];
        }
        return array_values(array_unique($options));
    }

    private static function specSecondUnitPrice(array $spec, int $duration = 0): float
    {
        $price = (float)($spec['tenant_unit_price'] ?? $spec['user_price'] ?? 0);
        if ($price <= 0) {
            return 0.0;
        }
        $duration = max(1, $duration ?: (int)($spec['duration'] ?? 0));
        return round($price / $duration, 2);
    }

    private static function normalizePriceMatrix(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($value)) {
            return [];
        }
        $rows = [];
        foreach ($value as $item) {
            if (!is_array($item)) {
                continue;
            }
            $row = [
                'channel' => self::normalizeChannelCode((string)($item['channel'] ?? '')),
                'quality' => trim((string)($item['quality'] ?? '')),
                'ratio' => trim((string)($item['ratio'] ?? '')),
                'duration' => max(0, (int)($item['duration'] ?? 0)),
                'user_price' => round(max(0, (float)($item['user_price'] ?? $item['price'] ?? 0)), 2),
                'status' => (int)($item['status'] ?? 1) ? 1 : 0,
            ];
            if ($row['channel'] === '' || $row['quality'] === '' || $row['ratio'] === '' || $row['duration'] <= 0) {
                continue;
            }
            $rows[self::priceKey($row)] = $row;
        }
        return array_values($rows);
    }

    private static function priceKey(array $item): string
    {
        return implode('|', [(string)($item['channel'] ?? ''), (string)($item['quality'] ?? ''), (string)($item['ratio'] ?? ''), (int)($item['duration'] ?? 0)]);
    }

    private static function saveConfigSnapshot(int $tenantId, array $data, AigcProductPromoVideoConfig $row): void
    {
        $payload = [
            'tenant_id' => $tenantId,
            'status' => (int)($data['status'] ?? 1),
            'market_enabled' => 1,
            'default_channel' => (string)($data['default_channel'] ?? ''),
            'default_quality' => (string)($data['default_quality'] ?? ''),
            'default_ratio' => (string)($data['default_ratio'] ?? ''),
            'default_duration' => (int)($data['default_duration'] ?? 0),
            'unit_price' => round(max(0, (float)($data['unit_price'] ?? 0)), 2),
            'prompt_template' => (string)($data['prompt_template'] ?? self::DEFAULT_PROMPT_TEMPLATE),
            'negative_prompt' => (string)($data['negative_prompt'] ?? self::DEFAULT_NEGATIVE_PROMPT),
            'price_matrix' => self::normalizePriceMatrix($data['price_matrix'] ?? []),
            'config_json' => self::normalizeConfigJson($data['config_json'] ?? []),
            'update_time' => time(),
        ];
        if ($row->isEmpty()) {
            $payload['create_time'] = time();
            AigcProductPromoVideoConfig::create($payload);
            return;
        }
        $row->save($payload);
    }

    private static function defaults(): array
    {
        return [
            'status' => 1,
            'market_enabled' => 1,
            'default_channel' => '',
            'default_quality' => '',
            'default_ratio' => '',
            'default_duration' => 0,
            'unit_price' => 0,
            'prompt_template' => self::DEFAULT_PROMPT_TEMPLATE,
            'negative_prompt' => self::DEFAULT_NEGATIVE_PROMPT,
            'price_matrix' => [],
            'config_json' => [],
        ];
    }

    private static function sanitizeConfig(array $data): array
    {
        $data['status'] = (int)($data['status'] ?? 1);
        $data['market_enabled'] = 1;
        $data['default_channel'] = self::normalizeChannelCode((string)($data['default_channel'] ?? ''));
        $data['default_quality'] = trim((string)($data['default_quality'] ?? ''));
        $data['default_ratio'] = trim((string)($data['default_ratio'] ?? ''));
        $data['default_duration'] = max(0, (int)($data['default_duration'] ?? 0));
        $data['prompt_template'] = self::normalizeTemplate((string)($data['prompt_template'] ?? self::DEFAULT_PROMPT_TEMPLATE));
        $data['negative_prompt'] = trim((string)($data['negative_prompt'] ?? self::DEFAULT_NEGATIVE_PROMPT));
        $data['price_matrix'] = self::normalizePriceMatrix($data['price_matrix'] ?? []);
        $data['unit_price'] = round(max(0, (float)($data['unit_price'] ?? 0)), 2);
        $data['config_json'] = is_array($data['config_json'] ?? null) ? self::normalizeConfigJson($data['config_json']) : [];
        $data['config_json']['channel'] = $data['default_channel'] ?: ($data['config_json']['channel'] ?? '');
        $data['config_json']['quality'] = $data['default_quality'] ?: ($data['config_json']['quality'] ?? '');
        $data['config_json']['ratio'] = $data['default_ratio'] ?: ($data['config_json']['ratio'] ?? '');
        $data['config_json']['duration'] = $data['default_duration'] ?: ($data['config_json']['duration'] ?? 0);
        $data['config_json']['market_enabled'] = $data['market_enabled'];
        return $data;
    }

    private static function normalizeConfigJson(array $config): array
    {
        return [
            'channel' => self::normalizeChannelCode((string)($config['channel'] ?? '')),
            'quality' => trim((string)($config['quality'] ?? '')),
            'ratio' => trim((string)($config['ratio'] ?? '')),
            'duration' => max(0, (int)($config['duration'] ?? 0)),
            'market_enabled' => 1,
        ];
    }

    private static function ensureDefaultTypes(int $tenantId): void
    {
        foreach (self::DEFAULT_TYPES as $code => $item) {
            $exists = AigcProductPromoVideoType::where(['tenant_id' => $tenantId, 'code' => $code])->where('delete_time', 0)->findOrEmpty();
            if (!$exists->isEmpty()) {
                continue;
            }
            AigcProductPromoVideoType::create([
                'tenant_id' => $tenantId,
                'code' => $code,
                'name' => $item['name'],
                'description' => $item['description'],
                'prompt' => $item['prompt'],
                'cover_image' => '',
                'status' => 1,
                'sort' => $item['sort'],
                'is_builtin' => 1,
                'delete_time' => 0,
                'create_time' => time(),
                'update_time' => time(),
            ]);
        }
    }

    private static function normalizeImage(mixed $value): string
    {
        return trim((string)(is_array($value) ? ($value['uri'] ?? $value['url'] ?? $value['image'] ?? '') : $value));
    }

    /**
     * Normalize capability payloads that may be stored as JSON or arrays.
     * Market metadata is persisted in both forms across existing tenants.
     */
    private static function arrayValue(mixed $value): array
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

    private static function imageUrl(string $uri): string
    {
        if ($uri === '') {
            return '';
        }
        if (preg_match('/^https?:\/\//', $uri) || str_starts_with($uri, 'data:image/') || str_starts_with($uri, 'blob:')) {
            return $uri;
        }
        return FileService::getFileUrl($uri);
    }

    private static function sizeLabel(string $qualityLabel, string $ratio, int $width, int $height, int $duration = 0): string
    {
        $meta = [];
        if ($qualityLabel !== '') {
            $meta[] = $qualityLabel;
        }
        if ($ratio !== '') {
            $meta[] = $ratio;
        }
        if ($duration > 0) {
            $meta[] = $duration . '秒';
        }
        if ($width > 0 && $height > 0) {
            $meta[] = $width . '*' . $height;
        }
        return implode(' ', $meta);
    }

    private static function normalizeTemplate(string $template): string
    {
        $template = trim($template);
        return $template !== '' ? $template : self::DEFAULT_PROMPT_TEMPLATE;
    }

    private static function normalizeCode(string $code): string
    {
        return preg_replace('/[^a-zA-Z0-9_\-]/', '', trim($code)) ?: '';
    }

    private static function normalizeChannelCode(string $code): string
    {
        $code = trim($code);
        if (preg_match('/^market_video_model:\d+$/', $code) === 1
            || preg_match('/^market_video_app:(?:[A-Za-z0-9_-]+:)?\d+$/', $code) === 1) {
            return $code;
        }
        return self::normalizeCode($code);
    }

    private static function normalizeTypeCode(string $code): string
    {
        return preg_replace('/[^a-zA-Z0-9_\-]/', '', trim($code)) ?: '';
    }
}
