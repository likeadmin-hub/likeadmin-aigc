<?php

namespace app\common\service\app\aigc_product_suite;

use app\common\model\app\App;
use app\common\model\app\aigc_image\AigcImageResult;
use app\common\model\app\aigc_image\AigcImageTask;
use app\common\model\app\aigc_product_suite\AigcProductSuiteConfig;
use app\common\model\app\aigc_product_suite\AigcProductSuiteModule;
use app\common\model\app\aigc_product_suite\AigcProductSuiteResult;
use app\common\model\app\aigc_product_suite\AigcProductSuiteTask;
use app\common\service\app\AppAccessService;
use app\common\service\app\AppDisplayConfigService;
use app\common\service\app\AppRegistryService;
use app\common\service\app\aigc_image\AigcImageChannelService;
use app\common\service\app\aigc_image\AigcImageService;
use app\common\service\app\aigc_llm\AigcLlmService;
use app\common\service\FileService;
use app\common\service\point\PointService;
use Exception;

class AigcProductSuiteService
{
    public const APP_CODE = 'aigc_product_suite';
    public const IMAGE_APP_CODE = 'aigc_image';
    public const LLM_APP_CODE = 'aigc_llm';

    private const DEFAULT_PROMPT_TEMPLATE = '基于用户上传的商品图生成指定模块的电商商品套图图片。保持商品主体、品牌元素、材质纹理、颜色、比例和关键结构稳定，结合平台、目标市场、语言和模块要求组织画面，输出可用于商品详情页、主图和投放素材的高质量视觉图。';
    private const DEFAULT_NEGATIVE_PROMPT = '主体缺失，主体变形，比例错误，结构错误，材质丢失，文字错乱，新增水印，新增无关物体，低清晰度，模糊，边缘破损，色偏';

    private const DEFAULT_MODULES = [
        'hero' => ['name' => '首屏主视觉', 'description' => '适合详情页首屏和主视觉展示', 'prompt' => '构建强吸引力首屏主视觉，突出商品完整外观、核心卖点和购买吸引力，画面信息层级清晰。', 'sort' => 160],
        'core-benefit' => ['name' => '核心卖点图', 'description' => '聚焦最重要的转化卖点', 'prompt' => '围绕商品核心利益点组织画面，强化功能价值、材质优势或使用收益，适合详情页重点卖点模块。', 'sort' => 150],
        'usage-scene' => ['name' => '使用场景图', 'description' => '呈现商品真实使用情境', 'prompt' => '将商品放入合理使用场景，突出使用方式、适用人群和场景价值，保持商品主体准确可信。', 'sort' => 140],
        'multi-angle' => ['name' => '多角度图', 'description' => '展示商品结构和立体感', 'prompt' => '展示商品不同角度的结构信息、轮廓比例和细节关系，适合补充用户对商品外观的理解。', 'sort' => 130],
        'mood-scene' => ['name' => '场景氛围图', 'description' => '强化生活方式和情绪价值', 'prompt' => '营造符合平台和目标市场审美的氛围场景，突出商品调性、生活方式和视觉记忆点。', 'sort' => 120],
        'detail' => ['name' => '商品细节图', 'description' => '强调材质、工艺和局部卖点', 'prompt' => '聚焦商品局部细节、材质纹理、结构设计和工艺品质，画面干净并保持细节可读。', 'sort' => 110],
        'compare' => ['name' => '对比图', 'description' => '表达使用前后或同类优势', 'prompt' => '通过清晰对比关系表达商品优势，可以呈现使用前后、普通产品对比或功能差异，信息表达直观。', 'sort' => 100],
        'size-spec' => ['name' => '尺寸规格图', 'description' => '呈现尺寸、容量或规格信息', 'prompt' => '围绕商品尺寸、容量、重量或规格信息生成可读性强的展示图，布局清楚，避免文字错乱。', 'sort' => 90],
        'spec-sheet' => ['name' => '详细规格参数表', 'description' => '展示参数和关键信息', 'prompt' => '生成参数说明型商品图，突出规格、材质、型号、容量等关键信息，版式规整并适合电商详情页。', 'sort' => 80],
        'accessories' => ['name' => '配件/赠品图', 'description' => '展示包装、配件和赠品', 'prompt' => '展示商品配件、包装内容或赠品组合，画面应有清晰陈列关系和完整套装感。', 'sort' => 70],
        'craft-process' => ['name' => '工艺制作图', 'description' => '表现制作工艺和品质感', 'prompt' => '表达商品制作工艺、品质标准或生产细节，营造可靠、专业和高品质的视觉感受。', 'sort' => 60],
        'series-display' => ['name' => '系列展示图', 'description' => '适合多款式和系列陈列', 'prompt' => '以系列陈列方式展示商品的多颜色、多规格或多款式信息，保持视觉统一和商品辨识度。', 'sort' => 50],
        'ingredient' => ['name' => '商品成分图', 'description' => '适合成分、材料和配方表达', 'prompt' => '围绕商品成分、材料、配方或原料优势组织画面，适合食品、美妆、日化等品类。', 'sort' => 40],
        'usage-guide' => ['name' => '使用建议图', 'description' => '说明使用步骤和建议', 'prompt' => '表达商品使用步骤、操作建议或搭配建议，画面流程清楚，帮助用户快速理解使用方法。', 'sort' => 30],
        'after-sales' => ['name' => '售后保障图', 'description' => '表达保障、服务和信任感', 'prompt' => '围绕售后服务、品质保障、物流配送或安全承诺构建信任型商品图，信息清晰可信。', 'sort' => 20],
        'purchase-guide' => ['name' => '购买引导图', 'description' => '强化购买理由和行动转化', 'prompt' => '生成购买引导型商品图，突出购买理由、适用场景、促销感或行动号召，适合详情页收尾转化。', 'sort' => 10],
    ];

    private const PLATFORMS = [
        '1688' => '1688',
        'amazon' => 'Amazon',
        'taobao-tmall' => '淘宝/天猫',
        'temu' => 'Temu',
        'tiktok-shop' => 'TikTok Shop',
        'pinduoduo' => '拼多多',
        'douyin-shop' => '抖音小店',
        'ozon' => 'Ozon',
        'independent' => '独立站',
        'shopee' => 'Shopee',
        'alibaba-international' => '阿里巴巴国际站',
        'aliexpress' => 'AliExpress',
        'jd' => '京东',
    ];

    private const COUNTRIES = [
        'us' => '美国',
        'europe' => '欧洲',
        'china' => '中国',
        'russia' => '俄罗斯',
        'sea' => '东南亚',
        'spain' => '西班牙',
        'germany' => '德国',
        'japan' => '日本',
        'brazil' => '巴西',
        'malaysia' => '马来西亚',
    ];

    private const LANGUAGES = [
        'zh' => '简体中文',
        'zh-hant' => '繁体中文',
        'en' => '英文',
        'es' => '西班牙文',
        'ru' => '俄文',
        'de' => '德文',
        'pt' => '葡萄牙文',
        'ms' => '马来文',
        'ja' => '日文',
        'none' => '不生成文字',
    ];

    public static function config(int $tenantId): array
    {
        $row = AigcProductSuiteConfig::where('tenant_id', $tenantId)->findOrEmpty();
        $data = $row->isEmpty() ? self::defaults() : array_merge(self::defaults(), $row->toArray());
        $data = self::sanitizeConfig($data);
        $optionConfig = AigcImageChannelService::userConfig($tenantId);
        $data['option_config'] = $optionConfig;
        $data['spec_options'] = self::buildSpecOptions($optionConfig);
        $data['modules'] = self::moduleLists($tenantId, true);
        $data['platforms'] = self::optionList(self::PLATFORMS);
        $data['countries'] = self::optionList(self::COUNTRIES);
        $data['languages'] = self::optionList(self::LANGUAGES);
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
            'unit_price' => round(max(0, (float)($params['unit_price'] ?? $current['unit_price'] ?? 0)), 2),
            'prompt_template' => self::normalizeTemplate((string)($params['prompt_template'] ?? $current['prompt_template'])),
            'negative_prompt' => trim((string)($params['negative_prompt'] ?? $current['negative_prompt'])),
            'config_json' => self::normalizeConfigJson(array_merge($configJson, [
                'platform' => $params['default_platform'] ?? $params['platform'] ?? $configJson['platform'] ?? $current['config_json']['platform'] ?? 'amazon',
                'country' => $params['default_country'] ?? $params['country'] ?? $configJson['country'] ?? $current['config_json']['country'] ?? 'us',
                'language' => $params['default_language'] ?? $params['language'] ?? $configJson['language'] ?? $current['config_json']['language'] ?? 'en',
            ])),
            'update_time' => time(),
        ];
        $row = AigcProductSuiteConfig::where('tenant_id', $tenantId)->findOrEmpty();
        if ($row->isEmpty()) {
            $data['create_time'] = time();
            AigcProductSuiteConfig::create($data);
            return;
        }
        $row->save($data);
    }

    public static function priceDetail(int $tenantId): array
    {
        $config = self::config($tenantId);
        return [
            'channels' => self::buildSpecOptions($config['option_config'] ?? []),
            'unit_price' => round((float)($config['unit_price'] ?? 0), 2),
            'default_channel' => (string)($config['default_channel'] ?? ''),
            'default_quality' => (string)($config['default_quality'] ?? ''),
            'default_ratio' => (string)($config['default_ratio'] ?? ''),
        ];
    }

    public static function savePrice(int $tenantId, array $params): void
    {
        $current = self::config($tenantId);
        self::saveConfig($tenantId, [
            'status' => $current['status'],
            'default_channel' => $params['default_channel'] ?? $current['default_channel'],
            'default_quality' => $params['default_quality'] ?? $current['default_quality'],
            'default_ratio' => $params['default_ratio'] ?? $current['default_ratio'],
            'unit_price' => $params['unit_price'] ?? $current['unit_price'],
            'prompt_template' => $current['prompt_template'],
            'negative_prompt' => $current['negative_prompt'],
            'config_json' => [
                'channel' => $params['default_channel'] ?? $current['default_channel'],
                'quality' => $params['default_quality'] ?? $current['default_quality'],
                'ratio' => $params['default_ratio'] ?? $current['default_ratio'],
                'platform' => $current['config_json']['platform'] ?? 'amazon',
                'country' => $current['config_json']['country'] ?? 'us',
                'language' => $current['config_json']['language'] ?? 'en',
            ],
        ]);
    }

    public static function moduleLists(int $tenantId, bool $onlyEnabled = false): array
    {
        self::ensureDefaultModules($tenantId);
        $query = AigcProductSuiteModule::where('tenant_id', $tenantId)->where('delete_time', 0)->order('sort', 'desc')->order('id', 'asc');
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

    public static function saveModule(int $tenantId, array $params): array
    {
        self::ensureDefaultModules($tenantId);
        $id = (int)($params['id'] ?? 0);
        $code = self::normalizeModuleCode((string)($params['code'] ?? ''));
        $row = $id > 0 ? AigcProductSuiteModule::where(['tenant_id' => $tenantId, 'id' => $id])->where('delete_time', 0)->findOrEmpty() : AigcProductSuiteModule::where(['tenant_id' => $tenantId, 'code' => $code])->where('delete_time', 0)->findOrEmpty();
        if ($id <= 0 && $code === '') {
            $code = 'custom_' . time();
        }
        if ($row->isEmpty() && AigcProductSuiteModule::where(['tenant_id' => $tenantId, 'code' => $code])->where('delete_time', 0)->count() > 0) {
            throw new Exception('模块标识已存在');
        }
        if (!$row->isEmpty()) {
            $code = (string)$row['code'];
        }
        $name = mb_substr(trim((string)($params['name'] ?? '')), 0, 80);
        if ($name === '') {
            throw new Exception('请输入模块名称');
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
            $row = AigcProductSuiteModule::create($data);
        } else {
            $row->save($data);
        }
        return $row->toArray();
    }

    public static function setModuleStatus(int $tenantId, array $params): void
    {
        $code = self::normalizeModuleCode((string)($params['code'] ?? ''));
        $id = (int)($params['id'] ?? 0);
        $query = AigcProductSuiteModule::where('tenant_id', $tenantId)->where('delete_time', 0);
        $id > 0 ? $query->where('id', $id) : $query->where('code', $code);
        $row = $query->findOrEmpty();
        if ($row->isEmpty()) {
            throw new Exception('模块选项不存在');
        }
        $row->save(['status' => (int)($params['status'] ?? 1) ? 1 : 0, 'update_time' => time()]);
    }

    public static function deleteModule(int $tenantId, array $params): void
    {
        $id = (int)($params['id'] ?? 0);
        $row = AigcProductSuiteModule::where(['tenant_id' => $tenantId, 'id' => $id])->where('delete_time', 0)->findOrEmpty();
        if ($row->isEmpty()) {
            throw new Exception('模块选项不存在');
        }
        if ((int)$row['is_builtin'] === 1) {
            throw new Exception('内置模块选项不允许删除');
        }
        $row->save(['delete_time' => time(), 'update_time' => time()]);
    }

    public static function estimate(int $tenantId, array $params): array
    {
        self::assertAvailable($tenantId);
        $prepared = self::prepareGeneratePayload($tenantId, $params, false);
        $singleEstimate = AigcImageService::estimate($tenantId, $prepared['image_payload']);
        return self::buildEstimate($prepared, $singleEstimate);
    }

    public static function optimizePrompt(int $tenantId, int $userId, array $params): array
    {
        $prompt = trim((string)($params['prompt'] ?? ''));
        if ($prompt === '') {
            throw new Exception('请先输入核心卖点');
        }
        $platform = self::normalizeChoice((string)($params['platform'] ?? 'amazon'), self::PLATFORMS, 'amazon');
        $country = self::normalizeChoice((string)($params['country'] ?? 'us'), self::COUNTRIES, 'us');
        $language = self::normalizeChoice((string)($params['language'] ?? 'en'), self::LANGUAGES, 'en');
        $content = '请优化下面的电商商品套图核心卖点描述，使其更清晰、更适合 AI 生图，补足商品卖点、材质、使用场景、目标市场和详情页转化表达。不要输出标题、序号和解释，只输出优化后的描述词。平台：'
            . self::PLATFORMS[$platform] . '。目标市场：' . self::COUNTRIES[$country] . '。输出语言：' . self::LANGUAGES[$language] . "。原描述：\n" . $prompt;
        return self::generatePromptText($tenantId, $userId, $content);
    }

    public static function generate(int $tenantId, int $userId, array $params): array
    {
        self::assertAvailable($tenantId);
        $prepared = self::prepareGeneratePayload($tenantId, $params, true);
        $singleEstimate = AigcImageService::estimate($tenantId, $prepared['image_payload']);
        $estimate = self::buildEstimate($prepared, $singleEstimate);
        PointService::assertCanConsumeAmounts($tenantId, $userId, (float)$estimate['tenant_cost_points'], (float)$estimate['user_charge_points']);
        $batchNo = 'product_suite_' . date('YmdHis') . '_' . substr(md5($tenantId . '_' . $userId . '_' . microtime(true)), 0, 8);
        $imageTaskIds = [];
        foreach ($prepared['modules'] as $module) {
            $payload = array_merge($prepared['image_payload'], [
                'prompt' => self::buildPrompt($prepared['config'], $module, $prepared['user_prompt'], $prepared),
                'reference_images' => $prepared['product_images'],
            ]);
            $result = AigcImageService::generateWithBillingOverride($tenantId, $userId, $payload, [
                'tenant_cost_points' => $estimate['platform_unit_cost'],
                'user_charge_points' => $prepared['unit_price'],
            ]);
            $imageTaskId = (int)($result['task_id'] ?? 0);
            if ($imageTaskId > 0) {
                $imageTaskIds[] = $imageTaskId;
            }
        }
        if (!$imageTaskIds) {
            throw new Exception('AI商品套图任务创建失败');
        }
        $task = self::createBatchTask($tenantId, $userId, $batchNo, $imageTaskIds, $prepared, $estimate);
        self::syncTaskFromImageTask($task);
        return [
            'task_id' => (int)$task['id'],
            'batch_no' => $batchNo,
            'image_task_ids' => $imageTaskIds,
            'status' => (string)($task['status'] ?: 'running'),
            'error' => (string)($task['error'] ?? ''),
            'results' => self::taskDetail($tenantId, (int)$task['id'], $userId)['results'] ?? [],
            'estimate' => $estimate,
        ];
    }

    public static function taskLists(int $tenantId, int $userId = 0, array $params = []): array
    {
        self::refreshMappedTasks($tenantId, $userId);
        $query = AigcProductSuiteTask::alias('t')
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
        $optionCode = self::normalizeModuleCode((string)($params['module_code'] ?? ''));
        if ($optionCode !== '') {
            $query->whereLike('t.module_codes', '%"' . $optionCode . '"%');
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
        $query = AigcProductSuiteTask::where(['tenant_id' => $tenantId, 'id' => $taskId])->where('delete_time', 0);
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
        $task = AigcProductSuiteTask::where(['tenant_id' => $tenantId, 'id' => $taskId])->where('delete_time', 0)->findOrEmpty();
        if ($task->isEmpty()) {
            throw new Exception('任务不存在');
        }
        return self::generate($tenantId, (int)$task['user_id'], [
            'product_images' => $task['product_images'],
            'module_codes' => $task['module_codes'],
            'platform' => $task['platform'],
            'country' => $task['country'],
            'language' => $task['language'],
            'ratio' => $task['ratio'],
            'prompt' => $task['user_prompt'] ?? '',
        ]);
    }

    public static function deleteTask(int $tenantId, int $taskId, int $userId = 0): void
    {
        $query = AigcProductSuiteTask::where(['tenant_id' => $tenantId, 'id' => $taskId]);
        if ($userId > 0) {
            $query->where('user_id', $userId);
        }
        $task = $query->findOrEmpty();
        if ($task->isEmpty()) {
            throw new Exception('任务不存在');
        }
        foreach (self::taskImageIds($task->toArray()) as $imageTaskId) {
            AigcImageService::deleteTask($tenantId, $imageTaskId, $userId);
        }
        $task->save(['delete_time' => time(), 'update_time' => time()]);
        AigcProductSuiteResult::where(['tenant_id' => $tenantId, 'task_id' => $taskId])->update(['delete_time' => time()]);
    }

    public static function deleteResult(int $tenantId, int $resultId, int $userId = 0): void
    {
        $query = AigcProductSuiteResult::where(['tenant_id' => $tenantId, 'id' => $resultId]);
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
        $imageInstalled = App::where(['code' => self::IMAGE_APP_CODE, 'status' => AppRegistryService::STATUS_INSTALLED])->count() > 0;
        $imageTenantEnabled = $tenantId <= 0 ? true : AppAccessService::tenantCanUse($tenantId, self::IMAGE_APP_CODE);
        $channels = [];
        try {
            $channels = AigcImageService::config($tenantId)['option_config']['channels'] ?? [];
        } catch (Exception) {
            $channels = [];
        }
        $imageItem = [
            'app_code' => self::IMAGE_APP_CODE,
            'name' => 'AIGC生图',
            'required_for' => 'AI商品套图生成',
            'installed' => $imageInstalled,
            'tenant_enabled' => $imageTenantEnabled,
            'channel_ready' => !empty($channels),
            'ready' => $imageInstalled && $imageTenantEnabled && !empty($channels),
            'message' => $imageInstalled ? ($imageTenantEnabled ? (!empty($channels) ? '可用' : '暂无可用生图通道') : '租户未开通或未上架') : '应用未安装或未启用',
        ];
        $llmInstalled = App::where(['code' => self::LLM_APP_CODE, 'status' => AppRegistryService::STATUS_INSTALLED])->count() > 0;
        $llmTenantEnabled = $tenantId <= 0 ? true : AppAccessService::tenantCanUse($tenantId, self::LLM_APP_CODE);
        $llmChannels = [];
        try {
            $llmChannels = AigcLlmService::config($tenantId)['option_config']['channels'] ?? [];
        } catch (Exception) {
            $llmChannels = [];
        }
        $llmItem = [
            'app_code' => self::LLM_APP_CODE,
            'name' => 'AIGC对话',
            'required_for' => '核心卖点AI优化',
            'installed' => $llmInstalled,
            'tenant_enabled' => $llmTenantEnabled,
            'channel_ready' => !empty($llmChannels),
            'ready' => $llmInstalled && $llmTenantEnabled && !empty($llmChannels),
            'message' => $llmInstalled ? ($llmTenantEnabled ? (!empty($llmChannels) ? '可用' : '暂无可用对话模型') : '租户未开通或未上架') : '应用未安装或未启用',
        ];
        return ['items' => [$imageItem, $llmItem], 'ready' => (bool)$imageItem['ready']];
    }

    private static function assertAvailable(int $tenantId): void
    {
        if (AppAccessService::assertTenantCanUse($tenantId, self::APP_CODE) !== null) {
            throw new Exception('AI商品套图应用未开通或未上架');
        }
        if (AppAccessService::assertTenantCanUse($tenantId, self::IMAGE_APP_CODE) !== null) {
            throw new Exception('AIGC生图应用未开通或未上架');
        }
        $config = self::config($tenantId);
        if ((int)($config['status'] ?? 1) !== 1) {
            throw new Exception('AI商品套图应用已停用');
        }
    }

    private static function prepareGeneratePayload(int $tenantId, array $params, bool $requireImage): array
    {
        $config = self::config($tenantId);
        $images = self::normalizeImages($params['product_images'] ?? $params['source_images'] ?? $params['source_image'] ?? $params['image'] ?? '');
        if ($requireImage && !$images) {
            throw new Exception('请上传商品图');
        }
        if (count($images) > 3) {
            throw new Exception('AI商品套图最多上传3张商品图');
        }
        $moduleCodes = self::normalizeModuleCodes($params['module_codes'] ?? $params['modules'] ?? []);
        if (!$moduleCodes) {
            throw new Exception('请选择模块选项');
        }
        $modules = self::resolveModules($tenantId, $moduleCodes);
        $channel = (string)($config['default_channel'] ?: ($config['config_json']['channel'] ?? ''));
        $quality = (string)($config['default_quality'] ?: ($config['config_json']['quality'] ?? ''));
        $ratio = trim((string)($params['ratio'] ?? $params['ratio_code'] ?? $config['default_ratio'] ?? $config['config_json']['ratio'] ?? ''));
        $platform = self::normalizeChoice((string)($params['platform'] ?? $config['config_json']['platform'] ?? 'amazon'), self::PLATFORMS, 'amazon');
        $country = self::normalizeChoice((string)($params['country'] ?? $params['region'] ?? $config['config_json']['country'] ?? 'us'), self::COUNTRIES, 'us');
        $language = self::normalizeChoice((string)($params['language'] ?? $config['config_json']['language'] ?? 'en'), self::LANGUAGES, 'en');
        $userPrompt = mb_substr(trim((string)($params['prompt'] ?? $params['description'] ?? '')), 0, 1000);
        if ($requireImage && $userPrompt === '') {
            throw new Exception('请输入核心卖点');
        }
        $imagePayload = [
            'prompt' => self::buildPrompt($config, $modules[0] ?? [], $userPrompt, [
                'platform' => $platform,
                'country' => $country,
                'language' => $language,
            ]),
            'negative_prompt' => (string)($params['negative_prompt'] ?? $config['negative_prompt']),
            'reference_images' => $images,
            'channel' => $channel,
            'quality' => $quality,
            'ratio' => $ratio,
            'quantity' => 1,
            'style' => 'product_suite',
        ];
        $resolved = AigcImageChannelService::resolveSelection($tenantId, $imagePayload);
        $unitPrice = round(max(0, (float)($config['unit_price'] ?? 0)), 2);
        return [
            'product_images' => $images,
            'platform' => $platform,
            'country' => $country,
            'language' => $language,
            'module_codes' => array_values(array_column($modules, 'code')),
            'modules' => $modules,
            'config' => $config,
            'user_prompt' => $userPrompt,
            'unit_price' => $unitPrice,
            'image_payload' => array_merge($imagePayload, [
                'channel' => (string)$resolved['channel']['code'],
                'quality' => (string)$resolved['spec']['quality'],
                'ratio' => (string)$resolved['spec']['ratio'],
            ]),
            'width' => (int)$resolved['spec']['width'],
            'height' => (int)$resolved['spec']['height'],
            'quality_label' => (string)($resolved['spec']['quality_label'] ?? $resolved['spec']['quality']),
            'size_key' => (string)$resolved['spec']['ratio'],
        ];
    }

    private static function buildEstimate(array $prepared, array $singleEstimate): array
    {
        $quantity = max(1, count($prepared['modules']));
        $platformUnitCost = round((float)($singleEstimate['platform_unit_cost'] ?? 0), 2);
        $userUnitPrice = round((float)$prepared['unit_price'], 2);
        return array_merge($singleEstimate, [
            'quantity' => $quantity,
            'module_count' => $quantity,
            'target_width' => $prepared['width'],
            'target_height' => $prepared['height'],
            'size_key' => $prepared['size_key'],
            'modules' => $prepared['modules'],
            'module_codes' => $prepared['module_codes'],
            'platform_unit_cost' => $platformUnitCost,
            'tenant_unit_price' => $userUnitPrice,
            'unit_price' => $userUnitPrice,
            'tenant_cost_points' => round($platformUnitCost * $quantity, 2),
            'user_charge_points' => round($userUnitPrice * $quantity, 2),
            'display_points' => round($userUnitPrice * $quantity, 2),
        ]);
    }

    private static function createBatchTask(int $tenantId, int $userId, string $batchNo, array $imageTaskIds, array $prepared, array $estimate): AigcProductSuiteTask
    {
        $firstImageTask = AigcImageTask::where(['tenant_id' => $tenantId, 'id' => $imageTaskIds[0]])->findOrEmpty();
        $data = [
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'batch_no' => $batchNo,
            'image_task_id' => (int)$imageTaskIds[0],
            'image_task_ids' => $imageTaskIds,
            'product_images' => $prepared['product_images'],
            'platform' => $prepared['platform'],
            'country' => $prepared['country'],
            'language' => $prepared['language'],
            'module_codes' => $prepared['module_codes'],
            'module_snapshot' => $prepared['modules'],
            'size_key' => $prepared['size_key'],
            'width' => $prepared['width'],
            'height' => $prepared['height'],
            'prompt' => self::buildBatchPrompt($prepared['config'], $prepared['modules'], $prepared['user_prompt'], $prepared),
            'user_prompt' => $prepared['user_prompt'],
            'negative_prompt' => $prepared['image_payload']['negative_prompt'],
            'channel' => (string)($firstImageTask['channel'] ?? $prepared['image_payload']['channel']),
            'quality' => (string)($firstImageTask['quality'] ?? $prepared['image_payload']['quality']),
            'quality_label' => $prepared['quality_label'],
            'ratio' => (string)($firstImageTask['ratio'] ?? $prepared['image_payload']['ratio']),
            'quantity' => count($prepared['modules']),
            'unit_price' => $prepared['unit_price'],
            'tenant_cost_points' => $estimate['tenant_cost_points'],
            'user_charge_points' => $estimate['user_charge_points'],
            'status' => 'running',
            'error' => '',
            'finish_time' => 0,
            'delete_time' => 0,
            'create_time' => time(),
            'update_time' => time(),
        ];
        return AigcProductSuiteTask::create($data);
    }

    private static function syncTaskFromImageTask(AigcProductSuiteTask $task): void
    {
        $imageTaskIds = self::taskImageIds($task->toArray());
        if (!$imageTaskIds) {
            return;
        }
        $tenantId = (int)$task['tenant_id'];
        $userId = (int)$task['user_id'];
        foreach ($imageTaskIds as $imageTaskId) {
            try {
                AigcImageService::taskDetail($tenantId, $imageTaskId, $userId);
            } catch (\Throwable) {
            }
        }
        $imageTasks = AigcImageTask::where('tenant_id', $tenantId)->whereIn('id', $imageTaskIds)->where('delete_time', 0)->select()->toArray();
        if (!$imageTasks) {
            return;
        }
        $syncSummary = self::syncResultsFromImageTask($task);
        $statuses = array_map(static fn($row) => (string)($row['status'] ?? ''), $imageTasks);
        $successCount = count(array_filter($statuses, static fn($status) => $status === 'success'));
        $failedCount = count(array_filter($statuses, static fn($status) => in_array($status, ['failed', 'canceled'], true)));
        $resultCount = (int)($syncSummary['result_count'] ?? 0);
        $missingResultCount = (int)($syncSummary['missing_result_count'] ?? 0);
        if ($resultCount >= count($imageTaskIds) && $successCount === count($imageTaskIds)) {
            $task->status = 'success';
        } elseif ($resultCount > 0 && $successCount + $failedCount === count($imageTaskIds)) {
            $task->status = 'partial_failed';
        } elseif (($failedCount === count($imageTaskIds)) || ($successCount + $failedCount === count($imageTaskIds) && $resultCount === 0)) {
            $task->status = 'failed';
        } elseif (in_array('pending', $statuses, true)) {
            $task->status = 'pending';
        } else {
            $task->status = 'running';
        }
        $errors = array_values(array_filter(array_map(static fn($row) => trim((string)($row['error'] ?? '')), $imageTasks)));
        if ($missingResultCount > 0 && in_array((string)$task->status, ['failed', 'partial_failed'], true)) {
            $errors[] = '生成任务未返回可用作品，请重新生成';
        }
        $finishTimes = array_filter(array_map(static fn($row) => (int)($row['finish_time'] ?? 0), $imageTasks));
        $task->error = implode('；', array_unique($errors));
        $task->finish_time = in_array((string)$task->status, ['success', 'failed', 'canceled', 'partial_failed'], true) ? max($finishTimes ?: [time()]) : 0;
        $task->tenant_cost_points = number_format(array_sum(array_map(static fn($row) => (float)($row['tenant_cost_points'] ?? 0), $imageTasks)), 2, '.', '');
        $task->user_charge_points = number_format(array_sum(array_map(static fn($row) => (float)($row['user_charge_points'] ?? 0), $imageTasks)), 2, '.', '');
        $task->update_time = time();
        $task->save();
    }

    private static function syncResultsFromImageTask(AigcProductSuiteTask $task): array
    {
        $tenantId = (int)$task['tenant_id'];
        $userId = (int)$task['user_id'];
        $productImages = self::normalizeImages($task['product_images'] ?? []);
        $sourceImage = (string)($productImages[0] ?? '');
        $modules = is_array($task['module_snapshot'] ?? null) ? array_values($task['module_snapshot']) : [];
        $resultCount = 0;
        $missingResultCount = 0;
        foreach (self::taskImageIds($task->toArray()) as $index => $imageTaskId) {
            $imageTask = AigcImageTask::where('tenant_id', $tenantId)->where('id', $imageTaskId)->where('delete_time', 0)->findOrEmpty();
            if ($imageTask->isEmpty() || (string)$imageTask['status'] !== 'success') {
                continue;
            }
            $hasUsableResult = false;
            $imageResults = AigcImageResult::where('tenant_id', $tenantId)
                ->where('task_id', $imageTaskId)
                ->where('delete_time', 0)
                ->where('image_uri', '<>', '')
                ->order('id', 'asc')
                ->select()
                ->toArray();
            foreach ($imageResults as $result) {
                $imageResultId = (int)($result['id'] ?? 0);
                if ($imageResultId <= 0) {
                    continue;
                }
                $imageUri = trim((string)($result['image_uri'] ?? ''));
                if ($imageUri === '') {
                    continue;
                }
                $hasUsableResult = true;
                $exists = AigcProductSuiteResult::where(['tenant_id' => $tenantId, 'image_result_id' => $imageResultId])->findOrEmpty();
                if (!$exists->isEmpty()) {
                    if ((string)$exists['image_uri'] === '') {
                        $exists->save([
                            'task_id' => (int)$task['id'],
                            'image_task_id' => $imageTaskId,
                            'user_id' => $userId,
                            'source_image' => $sourceImage,
                            'module_code' => (string)($modules[$index]['code'] ?? ''),
                            'module_name' => (string)($modules[$index]['name'] ?? ''),
                            'image_uri' => $imageUri,
                            'storage_scope' => (string)($result['storage_scope'] ?? 'tenant'),
                            'storage_engine' => (string)($result['storage_engine'] ?? 'local'),
                            'storage_domain' => (string)($result['storage_domain'] ?? ''),
                            'width' => (int)($result['width'] ?? 0),
                            'height' => (int)($result['height'] ?? 0),
                        ]);
                    }
                    continue;
                }
                $emptyMapped = AigcProductSuiteResult::where([
                    'tenant_id' => $tenantId,
                    'task_id' => (int)$task['id'],
                    'image_task_id' => $imageTaskId,
                ])->where('image_uri', '')->findOrEmpty();
                if (!$emptyMapped->isEmpty()) {
                    $emptyMapped->save([
                        'image_result_id' => $imageResultId,
                        'user_id' => $userId,
                        'source_image' => $sourceImage,
                        'module_code' => (string)($modules[$index]['code'] ?? ''),
                        'module_name' => (string)($modules[$index]['name'] ?? ''),
                        'image_uri' => $imageUri,
                        'storage_scope' => (string)($result['storage_scope'] ?? 'tenant'),
                        'storage_engine' => (string)($result['storage_engine'] ?? 'local'),
                        'storage_domain' => (string)($result['storage_domain'] ?? ''),
                        'width' => (int)($result['width'] ?? 0),
                        'height' => (int)($result['height'] ?? 0),
                    ]);
                    continue;
                }
                AigcProductSuiteResult::create([
                    'tenant_id' => $tenantId,
                    'task_id' => (int)$task['id'],
                    'image_task_id' => $imageTaskId,
                    'image_result_id' => $imageResultId,
                    'user_id' => $userId,
                    'source_image' => $sourceImage,
                    'module_code' => (string)($modules[$index]['code'] ?? ''),
                    'module_name' => (string)($modules[$index]['name'] ?? ''),
                    'image_uri' => $imageUri,
                    'storage_scope' => (string)($result['storage_scope'] ?? 'tenant'),
                    'storage_engine' => (string)($result['storage_engine'] ?? 'local'),
                    'storage_domain' => (string)($result['storage_domain'] ?? ''),
                    'width' => (int)($result['width'] ?? 0),
                    'height' => (int)($result['height'] ?? 0),
                    'delete_time' => 0,
                    'create_time' => time(),
                    'update_time' => time(),
                ]);
            }
            if ($hasUsableResult) {
                $resultCount++;
            } else {
                $missingResultCount++;
            }
        }
        return [
            'result_count' => $resultCount,
            'missing_result_count' => $missingResultCount,
        ];
    }

    private static function refreshMappedTasks(int $tenantId, int $userId = 0, int $taskId = 0): void
    {
        $query = AigcProductSuiteTask::where('tenant_id', $tenantId)->where('delete_time', 0);
        if ($userId > 0) {
            $query->where('user_id', $userId);
        }
        if ($taskId > 0) {
            $query->where('id', $taskId);
        } else {
            $query->whereIn('status', ['running', 'pending', 'success', 'partial_failed']);
        }
        foreach ($query->limit(20)->select() as $row) {
            self::syncTaskFromImageTask($row);
        }
    }

    private static function appendTaskResults(int $tenantId, int $userId, array $rows): array
    {
        $taskIds = array_values(array_unique(array_filter(array_column($rows, 'id'))));
        $resultMap = [];
        if ($taskIds) {
            $query = AigcProductSuiteResult::where('tenant_id', $tenantId)
                ->where('delete_time', 0)
                ->where('image_uri', '<>', '')
                ->whereIn('task_id', $taskIds)
                ->order('id', 'asc');
            if ($userId > 0) {
                $query->where('user_id', $userId);
            }
            foreach ($query->select()->toArray() as $result) {
                $result['image_url'] = FileService::getFileUrlByStorage($result['image_uri'], $result['storage_scope'] ?? '', $result['storage_engine'] ?? '', $result['storage_domain'] ?? '');
                if (trim((string)$result['image_url']) === '') {
                    continue;
                }
                $result['download_url'] = $result['image_url'];
                $result['source_image_url'] = self::imageUrl((string)($result['source_image'] ?? ''));
                $resultMap[(int)$result['task_id']][] = $result;
            }
        }
        foreach ($rows as &$row) {
            $results = $resultMap[(int)$row['id']] ?? [];
            $row['results'] = $results;
            $row['result_count'] = count($results);
            $productImages = self::normalizeImages($row['product_images'] ?? []);
            $row['product_image_urls'] = array_values(array_filter(array_map([self::class, 'imageUrl'], $productImages)));
            $row['source_image_urls'] = $row['product_image_urls'];
            $row['source_image_url'] = (string)($row['product_image_urls'][0] ?? '');
            $first = $results[0] ?? [];
            $row['image_url'] = (string)($first['image_url'] ?? '');
            $row['download_url'] = (string)($first['download_url'] ?? '');
            $row['image_uri'] = (string)($first['image_uri'] ?? '');
        }
        return $rows;
    }

    private static function formatTaskRow(array $row): array
    {
        $row['task_id'] = (int)($row['id'] ?? 0);
        $row['image_task_id'] = (int)($row['image_task_id'] ?? 0);
        $row['image_task_ids'] = self::taskImageIds($row);
        $row['product_images'] = self::normalizeImages($row['product_images'] ?? []);
        $row['module_names'] = array_values(array_filter(array_map(static fn($item) => (string)($item['name'] ?? ''), is_array($row['module_snapshot'] ?? null) ? $row['module_snapshot'] : [])));
        $row['platform_label'] = self::PLATFORMS[(string)($row['platform'] ?? '')] ?? (string)($row['platform'] ?? '');
        $row['country_label'] = self::COUNTRIES[(string)($row['country'] ?? '')] ?? (string)($row['country'] ?? '');
        $row['language_label'] = self::LANGUAGES[(string)($row['language'] ?? '')] ?? (string)($row['language'] ?? '');
        $row['size_label'] = self::sizeLabel((string)($row['quality_label'] ?? $row['quality'] ?? ''), (string)($row['ratio'] ?? ''), (int)($row['width'] ?? 0), (int)($row['height'] ?? 0));
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

    private static function resolveModules(int $tenantId, array $codes): array
    {
        self::ensureDefaultModules($tenantId);
        $rows = AigcProductSuiteModule::where('tenant_id', $tenantId)->where('delete_time', 0)->where('status', 1)->whereIn('code', $codes)->select()->toArray();
        $map = [];
        foreach ($rows as $row) {
            $map[(string)$row['code']] = [
                'id' => (int)$row['id'],
                'code' => (string)$row['code'],
                'name' => (string)$row['name'],
                'description' => (string)($row['description'] ?? ''),
                'prompt' => (string)($row['prompt'] ?? ''),
            ];
        }
        $resolved = [];
        foreach ($codes as $code) {
            if (isset($map[$code])) {
                $resolved[] = $map[$code];
            }
        }
        if (!$resolved || count($resolved) !== count($codes)) {
            throw new Exception('请选择可用模块选项');
        }
        return $resolved;
    }

    private static function buildPrompt(array $config, array $module, string $userPrompt = '', array $context = []): string
    {
        $template = self::normalizeTemplate((string)($config['prompt_template'] ?? ''));
        $moduleName = trim((string)($module['name'] ?? ''));
        $modulePrompt = trim((string)($module['prompt'] ?? ''));
        $platform = self::PLATFORMS[(string)($context['platform'] ?? '')] ?? '';
        $country = self::COUNTRIES[(string)($context['country'] ?? '')] ?? '';
        $language = self::LANGUAGES[(string)($context['language'] ?? '')] ?? '';
        $parts = [$template];
        if ($platform !== '') {
            $parts[] = '目标平台：' . $platform . '。';
        }
        if ($country !== '') {
            $parts[] = '目标国家/地区：' . $country . '。';
        }
        if ($language !== '') {
            $parts[] = '画面文案语言：' . $language . '。';
        }
        if ($moduleName !== '') {
            $parts[] = '目标模块：' . $moduleName . '。';
        }
        if ($modulePrompt !== '') {
            $parts[] = '模块要求：' . $modulePrompt;
        }
        if ($userPrompt !== '') {
            $parts[] = '核心卖点：' . $userPrompt;
        }
        return trim(implode(' ', $parts));
    }

    private static function buildBatchPrompt(array $config, array $modules, string $userPrompt = '', array $context = []): string
    {
        $names = implode('、', array_map(static fn($item) => (string)($item['name'] ?? ''), $modules));
        $base = self::buildPrompt($config, [], $userPrompt, $context);
        return trim($base . ($names !== '' ? ' 生成模块：' . $names . '。' : ''));
    }

    private static function ensureDefaultModules(int $tenantId): void
    {
        foreach (self::DEFAULT_MODULES as $code => $item) {
            $exists = AigcProductSuiteModule::where(['tenant_id' => $tenantId, 'code' => $code])->where('delete_time', 0)->count() > 0;
            if ($exists) {
                continue;
            }
            AigcProductSuiteModule::create([
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

    private static function buildSpecOptions(array $optionConfig): array
    {
        $channels = [];
        foreach (($optionConfig['channels'] ?? []) as $channel) {
            $qualities = [];
            foreach (($channel['qualities'] ?? []) as $quality) {
                $qualities[] = [
                    'value' => (string)($quality['value'] ?? ''),
                    'label' => (string)($quality['label'] ?? $quality['quality_label'] ?? $quality['value'] ?? ''),
                    'ratios' => array_map(static fn($ratio) => [
                        'value' => (string)($ratio['value'] ?? $ratio['ratio'] ?? ''),
                        'label' => (string)($ratio['label'] ?? $ratio['ratio'] ?? $ratio['value'] ?? ''),
                        'ratio' => (string)($ratio['ratio'] ?? $ratio['value'] ?? ''),
                        'width' => (int)($ratio['width'] ?? 0),
                        'height' => (int)($ratio['height'] ?? 0),
                        'platform_unit_cost' => round((float)($ratio['platform_unit_cost'] ?? 0), 2),
                    ], $quality['ratios'] ?? []),
                ];
            }
            $channels[] = ['code' => (string)$channel['code'], 'name' => (string)$channel['name'], 'qualities' => $qualities];
        }
        return $channels;
    }

    private static function saveConfigSnapshot(int $tenantId, array $data, AigcProductSuiteConfig $row): void
    {
        $payload = [
            'tenant_id' => $tenantId,
            'status' => (int)($data['status'] ?? 1),
            'default_channel' => (string)($data['default_channel'] ?? ''),
            'default_quality' => (string)($data['default_quality'] ?? ''),
            'default_ratio' => (string)($data['default_ratio'] ?? ''),
            'unit_price' => round(max(0, (float)($data['unit_price'] ?? 0)), 2),
            'prompt_template' => (string)($data['prompt_template'] ?? self::DEFAULT_PROMPT_TEMPLATE),
            'negative_prompt' => (string)($data['negative_prompt'] ?? self::DEFAULT_NEGATIVE_PROMPT),
            'config_json' => self::normalizeConfigJson($data['config_json'] ?? []),
            'update_time' => time(),
        ];
        if ($row->isEmpty()) {
            $payload['create_time'] = time();
            AigcProductSuiteConfig::create($payload);
            return;
        }
        $row->save($payload);
    }

    private static function defaults(): array
    {
        return ['status' => 1, 'default_channel' => '', 'default_quality' => '', 'default_ratio' => '', 'unit_price' => 0, 'prompt_template' => self::DEFAULT_PROMPT_TEMPLATE, 'negative_prompt' => self::DEFAULT_NEGATIVE_PROMPT, 'config_json' => self::normalizeConfigJson([])];
    }

    private static function sanitizeConfig(array $data): array
    {
        $data['status'] = (int)($data['status'] ?? 1);
        $data['default_channel'] = self::normalizeCode((string)($data['default_channel'] ?? ''));
        $data['default_quality'] = trim((string)($data['default_quality'] ?? ''));
        $data['default_ratio'] = trim((string)($data['default_ratio'] ?? ''));
        $data['unit_price'] = round(max(0, (float)($data['unit_price'] ?? 0)), 2);
        $data['prompt_template'] = self::normalizeTemplate((string)($data['prompt_template'] ?? self::DEFAULT_PROMPT_TEMPLATE));
        $data['negative_prompt'] = trim((string)($data['negative_prompt'] ?? self::DEFAULT_NEGATIVE_PROMPT));
        $data['config_json'] = is_array($data['config_json'] ?? null) ? self::normalizeConfigJson($data['config_json']) : [];
        $data['config_json']['channel'] = $data['default_channel'] ?: ($data['config_json']['channel'] ?? '');
        $data['config_json']['quality'] = $data['default_quality'] ?: ($data['config_json']['quality'] ?? '');
        $data['config_json']['ratio'] = $data['default_ratio'] ?: ($data['config_json']['ratio'] ?? '');
        $data['default_platform'] = $data['config_json']['platform'] ?? 'amazon';
        $data['default_country'] = $data['config_json']['country'] ?? 'us';
        $data['default_language'] = $data['config_json']['language'] ?? 'en';
        return $data;
    }

    private static function normalizeConfigJson(array $config): array
    {
        return [
            'channel' => self::normalizeCode((string)($config['channel'] ?? '')),
            'quality' => trim((string)($config['quality'] ?? '')),
            'ratio' => trim((string)($config['ratio'] ?? '')),
            'platform' => self::normalizeChoice((string)($config['platform'] ?? 'amazon'), self::PLATFORMS, 'amazon'),
            'country' => self::normalizeChoice((string)($config['country'] ?? 'us'), self::COUNTRIES, 'us'),
            'language' => self::normalizeChoice((string)($config['language'] ?? 'en'), self::LANGUAGES, 'en'),
        ];
    }

    private static function normalizeImages(mixed $value): array
    {
        if (is_string($value)) {
            $value = $value !== '' ? [$value] : [];
        }
        if (!is_array($value)) {
            return [];
        }
        $images = [];
        foreach ($value as $item) {
            $image = trim((string)(is_array($item) ? ($item['uri'] ?? $item['url'] ?? $item['image'] ?? '') : $item));
            if ($image !== '') {
                $images[] = $image;
            }
        }
        return array_values($images);
    }

    private static function normalizeModuleCodes(mixed $value): array
    {
        if (is_string($value)) {
            $value = $value !== '' ? explode(',', $value) : [];
        }
        if (!is_array($value)) {
            return [];
        }
        return array_values(array_unique(array_filter(array_map(static fn($item) => self::normalizeModuleCode((string)$item), $value))));
    }

    private static function taskImageIds(array $task): array
    {
        $ids = [];
        if (!empty($task['image_task_ids']) && is_array($task['image_task_ids'])) {
            $ids = array_map('intval', $task['image_task_ids']);
        }
        if (!empty($task['image_task_id'])) {
            $ids[] = (int)$task['image_task_id'];
        }
        return array_values(array_unique(array_filter($ids)));
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

    private static function sizeLabel(string $qualityLabel, string $ratio, int $width, int $height): string
    {
        $meta = [];
        if ($qualityLabel !== '') {
            $meta[] = $qualityLabel;
        }
        if ($ratio !== '') {
            $meta[] = $ratio;
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

    private static function normalizeModuleCode(string $code): string
    {
        return preg_replace('/[^a-zA-Z0-9_\-]/', '', trim($code)) ?: '';
    }

    private static function normalizeChoice(string $value, array $options, string $default): string
    {
        $value = trim($value);
        return array_key_exists($value, $options) ? $value : $default;
    }

    private static function optionList(array $options): array
    {
        $items = [];
        foreach ($options as $value => $label) {
            $items[] = ['value' => $value, 'label' => $label];
        }
        return $items;
    }

    private static function generatePromptText(int $tenantId, int $userId, string $content): array
    {
        if (AppAccessService::assertTenantCanUse($tenantId, self::LLM_APP_CODE) !== null) {
            throw new Exception('AIGC对话应用未开通，暂无法使用AI优化');
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
}
