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
use app\common\service\app\aigc_video\AigcVideoChannelService;
use app\common\service\app\aigc_video\AigcVideoService;
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
        $optionConfig = AigcVideoChannelService::userConfig($tenantId);
        if ((float)$data['unit_price'] <= 0) {
            $data['unit_price'] = self::defaultSecondUnitPrice($optionConfig, $data);
        }
        $data['option_config'] = $optionConfig;
        $data['spec_options'] = self::buildSpecOptions($optionConfig, (float)$data['unit_price']);
        $data['ratio_options'] = self::buildRatioOptions($data['spec_options'], $data['default_channel'], $data['default_quality']);
        $data['duration_options'] = self::buildDurationOptions($data['spec_options'], $data['default_ratio'], $data['default_channel'], $data['default_quality']);
        $data['types'] = self::typeLists($tenantId, true);
        $data['dependencies'] = self::dependencies($tenantId);
        if ($row->isEmpty()) {
            self::saveConfigSnapshot($tenantId, $data, $row);
        }
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
            'default_channel' => self::normalizeCode((string)($params['default_channel'] ?? $configJson['channel'] ?? $current['default_channel'] ?? '')),
            'default_quality' => trim((string)($params['default_quality'] ?? $configJson['quality'] ?? $current['default_quality'] ?? '')),
            'default_ratio' => trim((string)($params['default_ratio'] ?? $configJson['ratio'] ?? $current['default_ratio'] ?? '')),
            'default_duration' => 0,
            'unit_price' => round(max(0, (float)($params['unit_price'] ?? $current['unit_price'] ?? 0)), 2),
            'prompt_template' => self::normalizeTemplate((string)($params['prompt_template'] ?? $current['prompt_template'])),
            'negative_prompt' => trim((string)($params['negative_prompt'] ?? $current['negative_prompt'])),
            'price_matrix' => self::normalizePriceMatrix($current['price_matrix'] ?? []),
            'config_json' => self::normalizeConfigJson($configJson),
            'update_time' => time(),
        ];
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
        $videoEstimate = AigcVideoService::estimate($tenantId, $prepared['video_payload']);
        return self::buildEstimate($prepared, $videoEstimate);
    }

    public static function generate(int $tenantId, int $userId, array $params): array
    {
        self::assertAvailable($tenantId);
        $prepared = self::prepareGeneratePayload($tenantId, $params, true);
        $videoEstimate = AigcVideoService::estimate($tenantId, $prepared['video_payload']);
        $estimate = self::buildEstimate($prepared, $videoEstimate);
        PointService::assertCanConsumeAmounts($tenantId, $userId, (float)$estimate['tenant_cost_points'], (float)$estimate['user_charge_points']);
        $result = AigcVideoService::generateWithBillingOverride($tenantId, $userId, $prepared['video_payload'], [
            'tenant_cost_points' => $estimate['tenant_cost_points'],
            'user_charge_points' => $estimate['user_charge_points'],
        ]);
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

    public static function dependencies(int $tenantId = 0): array
    {
        $installed = App::where(['code' => self::VIDEO_APP_CODE, 'status' => AppRegistryService::STATUS_INSTALLED])->count() > 0;
        $tenantEnabled = $tenantId <= 0 ? true : AppAccessService::tenantCanUse($tenantId, self::VIDEO_APP_CODE);
        $channels = [];
        try {
            $channels = AigcVideoService::config($tenantId)['option_config']['channels'] ?? [];
        } catch (Exception) {
            $channels = [];
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
        $videoPayload = [
            'prompt' => $finalPrompt,
            'negative_prompt' => (string)($params['negative_prompt'] ?? $config['negative_prompt']),
            'reference_images' => array_values(array_filter([$sourceImage])),
            'reference_assets' => $sourceImage !== '' ? [['type' => 'image', 'uri' => $sourceImage]] : [],
            'channel' => $spec['channel'],
            'quality' => $spec['quality'],
            'ratio' => $spec['ratio'],
            'duration' => $spec['duration'],
            'quantity' => 1,
            'style' => 'product_promo_video',
        ];
        if (!empty($spec['mode'])) {
            $videoPayload['mode'] = $spec['mode'];
        }
        $resolved = AigcVideoChannelService::resolveSelection($tenantId, $videoPayload);
        return [
            'source_image' => $sourceImage,
            'type' => $type,
            'user_prompt' => $userPrompt,
            'unit_price' => round(max(0, (float)($config['unit_price'] ?? 0)), 2),
            'video_payload' => array_merge($videoPayload, [
                'channel' => (string)$resolved['channel']['code'],
                'quality' => (string)$resolved['spec']['quality'],
                'ratio' => (string)($videoPayload['ratio'] ?: $resolved['spec']['ratio']),
                'duration' => (int)$videoPayload['duration'],
            ]),
            'width' => (int)$resolved['spec']['width'],
            'height' => (int)$resolved['spec']['height'],
            'quality_label' => (string)($resolved['spec']['quality_label'] ?? $resolved['spec']['quality']),
            'size_key' => (string)($videoPayload['ratio'] ?: $resolved['spec']['ratio']),
            'config' => $config,
        ];
    }

    private static function buildEstimate(array $prepared, array $videoEstimate): array
    {
        $tenantCost = round((float)($videoEstimate['tenant_cost_points'] ?? $videoEstimate['platform_unit_cost'] ?? 0), 2);
        $duration = max(1, (int)($prepared['video_payload']['duration'] ?? 0));
        $userUnitPrice = round((float)$prepared['unit_price'], 2);
        $userPrice = round($userUnitPrice * $duration, 2);
        return array_merge($videoEstimate, [
            'quantity' => 1,
            'target_width' => $prepared['width'],
            'target_height' => $prepared['height'],
            'size_key' => $prepared['size_key'],
            'platform_unit_cost' => $tenantCost,
            'tenant_unit_price' => $userUnitPrice,
            'unit_price' => $userUnitPrice,
            'duration' => $duration,
            'tenant_cost_points' => $tenantCost,
            'user_charge_points' => $userPrice,
            'display_points' => $userPrice,
        ]);
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
            'unit_price' => $prepared['unit_price'],
            'tenant_cost_points' => $estimate['tenant_cost_points'],
            'user_charge_points' => $estimate['user_charge_points'],
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
        $task->tenant_cost_points = number_format((float)($videoTask['tenant_cost_points'] ?? $task['tenant_cost_points'] ?? 0), 2, '.', '');
        $task->user_charge_points = number_format((float)($videoTask['user_charge_points'] ?? $task['user_charge_points'] ?? 0), 2, '.', '');
        $task->update_time = time();
        $task->save();
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

    private static function buildSpecOptions(array $optionConfig, float $unitPrice = 0): array
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
                            'base_unit_price' => self::specSecondUnitPrice($channel, $ratio, (int)$duration),
                            'unit_price' => round(max(0, $unitPrice), 2),
                            'user_price' => round(max(0, $unitPrice) * max(1, (int)$duration), 2),
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
        $options = array_values(array_filter(array_map('intval', $channel['duration_options'] ?? [])));
        if (!$options) {
            $duration = (int)($quality['duration'] ?? 0);
            $options = [$duration > 0 ? $duration : 5];
        }
        return array_values(array_unique($options));
    }

    private static function defaultSecondUnitPrice(array $optionConfig, array $config): float
    {
        $default = $optionConfig['defaults'] ?? [];
        $targetChannel = (string)($config['default_channel'] ?? $config['config_json']['channel'] ?? $default['channel'] ?? '');
        $targetQuality = (string)($config['default_quality'] ?? $config['config_json']['quality'] ?? $default['quality'] ?? '');
        $targetRatio = (string)($config['default_ratio'] ?? $config['config_json']['ratio'] ?? $default['ratio'] ?? '');
        $targetDuration = (int)($config['default_duration'] ?? $config['config_json']['duration'] ?? $default['duration'] ?? 0);
        $fallback = 0.0;
        foreach (($optionConfig['channels'] ?? []) as $channel) {
            foreach (($channel['qualities'] ?? []) as $quality) {
                foreach (($quality['ratios'] ?? []) as $ratio) {
                    $durations = self::durationOptionsForSpec($channel, $quality, $ratio);
                    $duration = $targetDuration > 0 && in_array($targetDuration, $durations, true) ? $targetDuration : (int)($durations[0] ?? 0);
                    $price = self::specSecondUnitPrice($channel, $ratio, $duration);
                    if ($price <= 0) {
                        continue;
                    }
                    if ($fallback <= 0) {
                        $fallback = $price;
                    }
                    $channelCode = (string)($channel['code'] ?? '');
                    $qualityValue = (string)($quality['value'] ?? '');
                    $ratioValue = (string)($ratio['value'] ?? $ratio['ratio'] ?? '');
                    if (($targetChannel === '' || $channelCode === $targetChannel)
                        && ($targetQuality === '' || $qualityValue === $targetQuality)
                        && ($targetRatio === '' || $ratioValue === $targetRatio)
                    ) {
                        return $price;
                    }
                }
            }
        }
        return round(max(0, $fallback), 2);
    }

    private static function specSecondUnitPrice(array $channel, array $spec, int $duration = 0): float
    {
        $price = (float)($spec['tenant_unit_price'] ?? $spec['user_price'] ?? 0);
        if ($price <= 0) {
            return 0.0;
        }
        $channelCode = (string)($channel['code'] ?? '');
        if (self::isSecondBillingChannel($channelCode)) {
            return round($price, 2);
        }
        $duration = max(1, $duration ?: (int)($spec['duration'] ?? 0));
        return round($price / $duration, 2);
    }

    private static function isSecondBillingChannel(string $channelCode): bool
    {
        return in_array($channelCode, ['happy_horse', 'wan', 'seedance2_pro'], true);
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
                'channel' => self::normalizeCode((string)($item['channel'] ?? '')),
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
        $data['default_channel'] = self::normalizeCode((string)($data['default_channel'] ?? ''));
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
        return $data;
    }

    private static function normalizeConfigJson(array $config): array
    {
        return [
            'channel' => self::normalizeCode((string)($config['channel'] ?? '')),
            'quality' => trim((string)($config['quality'] ?? '')),
            'ratio' => trim((string)($config['ratio'] ?? '')),
            'duration' => max(0, (int)($config['duration'] ?? 0)),
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

    private static function normalizeTypeCode(string $code): string
    {
        return preg_replace('/[^a-zA-Z0-9_\-]/', '', trim($code)) ?: '';
    }
}
