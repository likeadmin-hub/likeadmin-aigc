<?php

namespace app\common\service\app\aigc_style_transfer;

use app\common\model\app\App;
use app\common\model\app\aigc_image\AigcImageTask;
use app\common\model\app\aigc_style_transfer\AigcStyleTransferConfig;
use app\common\model\app\aigc_style_transfer\AigcStyleTransferResult;
use app\common\model\app\aigc_style_transfer\AigcStyleTransferStyleCategory;
use app\common\model\app\aigc_style_transfer\AigcStyleTransferStyleTemplate;
use app\common\model\app\aigc_style_transfer\AigcStyleTransferTask;
use app\common\service\app\AppAccessService;
use app\common\service\app\AppDisplayConfigService;
use app\common\service\app\AppRegistryService;
use app\common\service\app\aigc_image\AigcImageChannelService;
use app\common\service\app\aigc_image\AigcImageService;
use app\common\service\FileService;
use app\common\service\point\PointService;
use app\common\service\storage\StorageConfigService;
use Exception;

class AigcStyleTransferService
{
    public const APP_CODE = 'aigc_style_transfer';
    public const IMAGE_APP_CODE = 'aigc_image';

    public const SIZE_OPTIONS = [
        ['key' => 'custom', 'label' => '自定义', 'width' => 800, 'height' => 800],
        ['key' => '1:1', 'label' => '1:1(800*800)', 'width' => 800, 'height' => 800],
        ['key' => '2:3', 'label' => '2:3(800*1200)', 'width' => 800, 'height' => 1200],
        ['key' => '3:2', 'label' => '3:2(1200*800)', 'width' => 1200, 'height' => 800],
        ['key' => '3:4', 'label' => '3:4(750*1000)', 'width' => 750, 'height' => 1000],
        ['key' => '4:3', 'label' => '4:3(1000*750)', 'width' => 1000, 'height' => 750],
        ['key' => '9:16', 'label' => '9:16(900*1600)', 'width' => 900, 'height' => 1600],
        ['key' => '16:9', 'label' => '16:9(1600*900)', 'width' => 1600, 'height' => 900],
        ['key' => 'taobao-jd-pdd', 'label' => '淘宝/京东/拼多多(800*800)', 'width' => 800, 'height' => 800],
        ['key' => 'xianyu', 'label' => '闲鱼(800*800)', 'width' => 800, 'height' => 800],
        ['key' => 'meituan', 'label' => '美团(800*800)', 'width' => 800, 'height' => 800],
        ['key' => 'xiaohongshu', 'label' => '小红书(1242*1660)', 'width' => 1242, 'height' => 1660],
        ['key' => 'ebay', 'label' => 'Ebay(1600*1600)', 'width' => 1600, 'height' => 1600],
        ['key' => 'amazon', 'label' => 'Amazon(2000*2000)', 'width' => 2000, 'height' => 2000],
        ['key' => 'aliexpress-temu', 'label' => 'AliExpress/Temu(800*800)', 'width' => 800, 'height' => 800],
        ['key' => 'lazada-shopee', 'label' => 'Lazada/Shopee(1080*1080)', 'width' => 1080, 'height' => 1080],
        ['key' => 'poshmark', 'label' => 'Poshmark(1080*1080)', 'width' => 1080, 'height' => 1080],
        ['key' => 'depop', 'label' => 'Depop(1280*1280)', 'width' => 1280, 'height' => 1280],
        ['key' => 'shopify', 'label' => 'Shopify(2048*2048)', 'width' => 2048, 'height' => 2048],
        ['key' => 'mercado-libre', 'label' => 'Mercado Libre(1200*1200)', 'width' => 1200, 'height' => 1200],
        ['key' => 'vinted', 'label' => 'Vinted(800*600)', 'width' => 800, 'height' => 600],
        ['key' => 'mercari', 'label' => 'Mercari(1080*1080)', 'width' => 1080, 'height' => 1080],
    ];

    private const DEFAULT_PROMPT_TEMPLATE = '基于用户上传原图和{style_label}风格模板，将原图转化为目标风格。保持主体结构、人物/商品特征、关键颜色和画面构图稳定，参考风格模板的材质、笔触、色调、光影与氛围，生成高质量图片风格化结果。输出尺寸为{width}×{height}。{template_prompt}{user_prompt}';
    private const DEFAULT_NEGATIVE_PROMPT = '主体变形，主体缺失，五官错误，手部错误，文字乱码，水印，低清晰度，过度曝光，背景杂乱，比例异常';
    private const DEFAULT_PRICE_PACKAGE_NAMES = ['标准风格化', '高清风格化'];

    public static function config(int $tenantId): array
    {
        $row = AigcStyleTransferConfig::where('tenant_id', $tenantId)->findOrEmpty();
        $data = $row->isEmpty() ? self::defaults() : array_merge(self::defaults(), $row->toArray());
        $data = self::sanitizeConfig($data);
        $optionConfig = AigcImageChannelService::userConfig($tenantId);
        [$data['config_json']['price_packages'], $priceChanged] = self::ensurePricePackages(
            $data['config_json']['price_packages'] ?? [],
            $optionConfig,
            (float)($data['config_json']['unit_price'] ?? 8)
        );
        if ($priceChanged) {
            self::saveConfigSnapshot($tenantId, $data, $row);
        }
        $data['option_config'] = $optionConfig;
        $data['price_packages'] = self::buildPricePackages($optionConfig, $data['config_json']['price_packages'] ?? []);
        $data['price_options'] = $data['price_packages'];
        $data['size_options'] = self::SIZE_OPTIONS;
        $data['dependencies'] = self::dependencies($tenantId);
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
            'default_size_key' => self::normalizeSizeKey($params['default_size_key'] ?? $current['default_size_key']),
            'prompt_template' => self::normalizeTemplate((string)($params['prompt_template'] ?? $current['prompt_template'])),
            'negative_prompt' => trim((string)($params['negative_prompt'] ?? $current['negative_prompt'])),
            'config_json' => self::normalizeConfigJson($configJson),
            'update_time' => time(),
        ];
        $row = AigcStyleTransferConfig::where('tenant_id', $tenantId)->findOrEmpty();
        if ($row->isEmpty()) {
            $data['create_time'] = time();
            AigcStyleTransferConfig::create($data);
            return;
        }
        $row->save($data);
    }

    public static function priceDetail(int $tenantId): array
    {
        $config = self::config($tenantId);
        return [
            'channels' => self::buildPackageSourceOptions($config['option_config'] ?? []),
            'packages' => $config['price_packages'] ?? [],
            'price_config' => $config['config_json']['price_packages'] ?? [],
        ];
    }

    public static function savePrice(int $tenantId, array $params): void
    {
        $current = self::config($tenantId);
        $configJson = is_array($current['config_json'] ?? null) ? $current['config_json'] : [];
        $configJson['price_packages'] = self::normalizePricePackages($params['packages'] ?? $params['price_config'] ?? $params['items'] ?? []);
        self::saveConfig($tenantId, [
            'status' => $current['status'],
            'default_size_key' => $current['default_size_key'],
            'prompt_template' => $current['prompt_template'],
            'negative_prompt' => $current['negative_prompt'],
            'config_json' => $configJson,
            'display_config' => $current['display_config'] ?? [],
        ]);
    }

    public static function categoryLists(int $tenantId, array $params = []): array
    {
        self::ensureDefaultCategories($tenantId);
        $query = AigcStyleTransferStyleCategory::where('tenant_id', $tenantId)->order(['sort' => 'desc', 'id' => 'asc']);
        if (!empty($params['only_enabled'])) {
            $query->where('status', 1);
        }
        $keyword = trim((string)($params['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->whereLike('name', '%' . $keyword . '%');
        }
        return $query->select()->toArray();
    }

    public static function saveCategory(int $tenantId, array $params): array
    {
        $id = (int)($params['id'] ?? 0);
        $code = self::normalizeCode((string)($params['code'] ?? ''));
        $name = trim((string)($params['name'] ?? ''));
        if ($name === '') {
            throw new Exception('请输入分类名称');
        }
        if ($code === '') {
            $code = self::normalizeCode(preg_replace('/\s+/', '-', strtolower((string)($params['key'] ?? '')))) ?: ('category-' . time());
        }
        $data = [
            'tenant_id' => $tenantId,
            'code' => $code,
            'name' => $name,
            'sort' => (int)($params['sort'] ?? 0),
            'status' => (int)($params['status'] ?? 1),
            'update_time' => time(),
        ];
        $query = AigcStyleTransferStyleCategory::where(['tenant_id' => $tenantId, 'code' => $code]);
        if ($id > 0) {
            $query->where('id', '<>', $id);
        }
        if (!$query->findOrEmpty()->isEmpty()) {
            throw new Exception('分类标识已存在');
        }
        if ($id > 0) {
            $row = AigcStyleTransferStyleCategory::where(['tenant_id' => $tenantId, 'id' => $id])->findOrEmpty();
            if ($row->isEmpty()) {
                throw new Exception('分类不存在');
            }
            $row->save($data);
            return $row->toArray();
        }
        $data['create_time'] = time();
        return AigcStyleTransferStyleCategory::create($data)->toArray();
    }

    public static function setCategoryStatus(int $tenantId, int $id, int $status): void
    {
        $row = AigcStyleTransferStyleCategory::where(['tenant_id' => $tenantId, 'id' => $id])->findOrEmpty();
        if ($row->isEmpty()) {
            throw new Exception('分类不存在');
        }
        $row->save(['status' => $status ? 1 : 0, 'update_time' => time()]);
    }

    public static function deleteCategory(int $tenantId, int $id): void
    {
        $row = AigcStyleTransferStyleCategory::where(['tenant_id' => $tenantId, 'id' => $id])->findOrEmpty();
        if ($row->isEmpty()) {
            throw new Exception('分类不存在');
        }
        $templateCount = AigcStyleTransferStyleTemplate::where(['tenant_id' => $tenantId, 'category_id' => $id])->count();
        if ($templateCount > 0) {
            throw new Exception('该分类下存在风格模板，无法删除');
        }
        $row->delete();
    }

    public static function templateLists(int $tenantId, array $params = []): array
    {
        self::ensureDefaultCategories($tenantId);
        self::ensureDefaultTemplates($tenantId);
        $query = AigcStyleTransferStyleTemplate::alias('t')
            ->leftJoin('aigc_style_transfer_style_category c', 'c.id = t.category_id AND c.tenant_id = t.tenant_id')
            ->field('t.*,c.name category_name,c.code category_code')
            ->where('t.tenant_id', $tenantId)
            ->where('t.delete_time', 0)
            ->order(['t.sort' => 'desc', 't.id' => 'asc']);
        if (!empty($params['only_enabled'])) {
            $query->where('t.status', 1);
            $query->where('c.status', 1);
        }
        $categoryId = (int)($params['category_id'] ?? 0);
        if ($categoryId > 0) {
            $query->where('t.category_id', $categoryId);
        }
        $categoryCode = trim((string)($params['category_code'] ?? ''));
        if ($categoryCode !== '' && $categoryCode !== 'overview') {
            $query->where('c.code', $categoryCode);
        }
        $keyword = trim((string)($params['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->whereLike('t.name', '%' . $keyword . '%');
        }
        $rows = $query->select()->toArray();
        return array_map([self::class, 'formatTemplate'], $rows);
    }

    public static function templateDetail(int $tenantId, int $id): array
    {
        $row = AigcStyleTransferStyleTemplate::alias('t')
            ->leftJoin('aigc_style_transfer_style_category c', 'c.id = t.category_id AND c.tenant_id = t.tenant_id')
            ->field('t.*,c.name category_name,c.code category_code')
            ->where('t.tenant_id', $tenantId)
            ->where('t.id', $id)
            ->where('t.delete_time', 0)
            ->findOrEmpty();
        if ($row->isEmpty()) {
            throw new Exception('风格模板不存在');
        }
        return self::formatTemplate($row->toArray());
    }

    public static function saveTemplate(int $tenantId, array $params): array
    {
        self::ensureDefaultCategories($tenantId);
        $id = (int)($params['id'] ?? 0);
        $categoryId = (int)($params['category_id'] ?? 0);
        $category = AigcStyleTransferStyleCategory::where(['tenant_id' => $tenantId, 'id' => $categoryId])->findOrEmpty();
        if ($category->isEmpty()) {
            throw new Exception('请选择风格分类');
        }
        $name = trim((string)($params['name'] ?? ''));
        $image = trim((string)($params['image'] ?? $params['image_uri'] ?? ''));
        if ($name === '') {
            throw new Exception('请输入模板名称');
        }
        if ($image === '') {
            throw new Exception('请上传模板图');
        }
        $data = [
            'tenant_id' => $tenantId,
            'category_id' => $categoryId,
            'name' => $name,
            'image' => $image,
            'prompt' => trim((string)($params['prompt'] ?? '')),
            'vip' => (int)($params['vip'] ?? 0) ? 1 : 0,
            'sort' => (int)($params['sort'] ?? 0),
            'status' => (int)($params['status'] ?? 1),
            'update_time' => time(),
        ];
        if ($id > 0) {
            $row = AigcStyleTransferStyleTemplate::where(['tenant_id' => $tenantId, 'id' => $id])->where('delete_time', 0)->findOrEmpty();
            if ($row->isEmpty()) {
                throw new Exception('风格模板不存在');
            }
            $row->save($data);
            return self::templateDetail($tenantId, $id);
        }
        $data['delete_time'] = 0;
        $data['create_time'] = time();
        $row = AigcStyleTransferStyleTemplate::create($data);
        return self::templateDetail($tenantId, (int)$row['id']);
    }

    public static function setTemplateStatus(int $tenantId, int $id, int $status): void
    {
        $row = AigcStyleTransferStyleTemplate::where(['tenant_id' => $tenantId, 'id' => $id])->where('delete_time', 0)->findOrEmpty();
        if ($row->isEmpty()) {
            throw new Exception('风格模板不存在');
        }
        $row->save(['status' => $status ? 1 : 0, 'update_time' => time()]);
    }

    public static function deleteTemplate(int $tenantId, int $id): void
    {
        $row = AigcStyleTransferStyleTemplate::where(['tenant_id' => $tenantId, 'id' => $id])->where('delete_time', 0)->findOrEmpty();
        if ($row->isEmpty()) {
            throw new Exception('风格模板不存在');
        }
        $row->save(['delete_time' => time(), 'update_time' => time()]);
    }

    public static function estimate(int $tenantId, array $params): array
    {
        self::assertAvailable($tenantId);
        $prepared = self::prepareGeneratePayload($tenantId, $params, false);
        $imageEstimate = AigcImageService::estimate($tenantId, $prepared['image_payload']);
        return self::buildEstimate($prepared, $imageEstimate);
    }

    public static function generate(int $tenantId, int $userId, array $params): array
    {
        self::assertAvailable($tenantId);
        $prepared = self::prepareGeneratePayload($tenantId, $params, true);
        $imageEstimate = AigcImageService::estimate($tenantId, $prepared['image_payload']);
        $estimate = self::buildEstimate($prepared, $imageEstimate);
        PointService::assertCanConsumeAmounts($tenantId, $userId, (float)$estimate['tenant_cost_points'], (float)$estimate['user_charge_points']);
        $imageResult = AigcImageService::generateWithBillingOverride($tenantId, $userId, $prepared['image_payload'], [
            'tenant_cost_points' => $estimate['tenant_cost_points'],
            'user_charge_points' => $estimate['user_charge_points'],
        ]);
        $imageTaskId = (int)($imageResult['task_id'] ?? 0);
        if ($imageTaskId <= 0) {
            throw new Exception('风格化任务创建失败');
        }
        $task = self::upsertTaskFromImageTask($tenantId, $userId, $imageTaskId, $prepared, $estimate);
        self::syncTaskFromImageTask($task);
        return [
            'task_id' => (int)$task['id'],
            'image_task_id' => $imageTaskId,
            'status' => (string)($task['status'] ?: 'running'),
            'error' => (string)($task['error'] ?? ''),
            'results' => self::taskDetail($tenantId, (int)$task['id'], $userId)['results'] ?? [],
            'estimate' => $estimate,
        ];
    }

    public static function taskLists(int $tenantId, int $userId = 0, array $params = []): array
    {
        self::refreshMappedTasks($tenantId, $userId);
        $query = AigcStyleTransferTask::alias('t')
            ->leftJoin('user u', 'u.id = t.user_id AND u.tenant_id = t.tenant_id')
            ->leftJoin('aigc_style_transfer_style_template s', 's.id = t.template_id AND s.tenant_id = t.tenant_id')
            ->field('t.*,u.nickname user_nickname,u.account user_account,u.mobile user_mobile,s.name template_name')
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
        $styleMode = trim((string)($params['style_mode'] ?? ''));
        if ($styleMode !== '') {
            $query->where('t.style_mode', $styleMode);
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
        return [
            'lists' => array_map([self::class, 'formatTaskRow'], $rows),
            'count' => $count,
            'page_no' => $pageNo,
            'page_size' => $pageSize,
        ];
    }

    public static function taskDetail(int $tenantId, int $taskId, int $userId = 0): array
    {
        self::refreshMappedTasks($tenantId, $userId, $taskId);
        $query = AigcStyleTransferTask::where(['tenant_id' => $tenantId, 'id' => $taskId])->where('delete_time', 0);
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
        $task = AigcStyleTransferTask::where(['tenant_id' => $tenantId, 'id' => $taskId])->where('delete_time', 0)->findOrEmpty();
        if ($task->isEmpty()) {
            throw new Exception('任务不存在');
        }
        return self::generate($tenantId, (int)$task['user_id'], [
            'source_image' => $task['source_image'],
            'style_mode' => $task['style_mode'],
            'template_id' => (int)$task['template_id'],
            'style_image' => $task['style_image'],
            'size_key' => $task['size_key'],
            'width' => (int)$task['width'],
            'height' => (int)$task['height'],
            'prompt' => $task['user_prompt'],
            'channel' => $task['channel'],
            'quality' => $task['quality'],
            'ratio' => $task['ratio'],
            'quantity' => (int)$task['quantity'],
        ]);
    }

    public static function deleteTask(int $tenantId, int $taskId, int $userId = 0): void
    {
        $query = AigcStyleTransferTask::where(['tenant_id' => $tenantId, 'id' => $taskId]);
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
        AigcStyleTransferResult::where(['tenant_id' => $tenantId, 'task_id' => $taskId])->update(['delete_time' => time()]);
    }

    public static function deleteResult(int $tenantId, int $resultId, int $userId = 0): void
    {
        $query = AigcStyleTransferResult::where(['tenant_id' => $tenantId, 'id' => $resultId]);
        if ($userId > 0) {
            $query->where('user_id', $userId);
        }
        $row = $query->findOrEmpty();
        if ($row->isEmpty()) {
            throw new Exception('作品不存在');
        }
        $row->save(['delete_time' => time()]);
    }

    public static function dependencies(int $tenantId = 0): array
    {
        $installed = App::where(['code' => self::IMAGE_APP_CODE, 'status' => AppRegistryService::STATUS_INSTALLED])->count() > 0;
        $tenantEnabled = $tenantId <= 0 ? true : AppAccessService::tenantCanUse($tenantId, self::IMAGE_APP_CODE);
        $channels = [];
        try {
            $imageConfig = AigcImageService::config($tenantId);
            $channels = $imageConfig['option_config']['channels'] ?? [];
        } catch (Exception) {
            $channels = [];
        }
        $item = [
            'app_code' => self::IMAGE_APP_CODE,
            'name' => 'AIGC生图',
            'required_for' => '风格化生成',
            'installed' => $installed,
            'tenant_enabled' => $tenantEnabled,
            'channel_ready' => !empty($channels),
            'ready' => $installed && $tenantEnabled && !empty($channels),
            'message' => $installed ? ($tenantEnabled ? (!empty($channels) ? '可用' : '暂无可用生图通道') : '租户未开通或未上架') : '应用未安装或未启用',
        ];
        return ['items' => [$item], 'ready' => (bool)$item['ready']];
    }

    public static function stat(int $tenantId = 0): array
    {
        $task = AigcStyleTransferTask::where('delete_time', 0);
        if ($tenantId > 0) {
            $task->where('tenant_id', $tenantId);
        }
        return [
            'task_total' => (int)(clone $task)->count(),
            'task_success' => (int)(clone $task)->where('status', 'success')->count(),
            'task_failed' => (int)(clone $task)->where('status', 'failed')->count(),
            'result_total' => (int)(clone $task)->where('status', 'success')->sum('quantity'),
            'tenant_cost_points' => round((float)(clone $task)->sum('tenant_cost_points'), 2),
            'user_charge_points' => round((float)(clone $task)->sum('user_charge_points'), 2),
            'dependencies' => self::dependencies($tenantId),
        ];
    }

    public static function tenantUsageLists(array $params = []): array
    {
        $tenantId = (int)($params['tenant_id'] ?? 0);
        $query = AigcStyleTransferTask::where('delete_time', 0);
        if ($tenantId > 0) {
            $query->where('tenant_id', $tenantId);
        }
        return $query
            ->field('tenant_id,count(*) as task_total,count(distinct user_id) as user_total,max(update_time) as last_task_time')
            ->fieldRaw("SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) AS task_success")
            ->fieldRaw("SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS task_failed")
            ->group('tenant_id')
            ->order('last_task_time', 'desc')
            ->limit(100)
            ->select()
            ->toArray();
    }

    private static function assertAvailable(int $tenantId): void
    {
        if (AppAccessService::assertTenantCanUse($tenantId, self::APP_CODE) !== null) {
            throw new Exception('图片风格化应用未开通或未上架');
        }
        if (AppAccessService::assertTenantCanUse($tenantId, self::IMAGE_APP_CODE) !== null) {
            throw new Exception('AIGC生图应用未开通或未上架');
        }
        $config = self::config($tenantId);
        if ((int)($config['status'] ?? 1) !== 1) {
            throw new Exception('图片风格化应用已停用');
        }
    }

    private static function prepareGeneratePayload(int $tenantId, array $params, bool $requireImages): array
    {
        $config = self::config($tenantId);
        $configJson = is_array($config['config_json'] ?? null) ? $config['config_json'] : [];
        $sourceImage = self::normalizeImage($params['source_image'] ?? $params['image'] ?? '');
        if ($requireImages && $sourceImage === '') {
            throw new Exception('请上传图片');
        }
        $styleMode = 'template';
        $template = null;
        $styleImage = '';
        $styleLabel = '风格模板';
        $templateId = (int)($params['template_id'] ?? 0);
        if ($requireImages && $templateId <= 0) {
            throw new Exception('请选择风格模板');
        }
        if ($templateId > 0) {
            $template = self::templateDetail($tenantId, $templateId);
            if ($requireImages && (int)($template['status'] ?? 1) !== 1) {
                throw new Exception('风格模板已停用');
            }
            $styleImage = (string)($template['image'] ?? '');
            $styleLabel = (string)($template['name'] ?? '风格模板');
        }
        $size = self::resolveSize($params, (string)$config['default_size_key']);
        $userPrompt = trim((string)($params['prompt'] ?? $params['user_prompt'] ?? ''));
        $prompt = self::renderPrompt((string)$config['prompt_template'], [
            'style_label' => $styleLabel,
            'width' => $size['width'],
            'height' => $size['height'],
            'user_prompt' => $userPrompt,
            'template_prompt' => (string)($template['prompt'] ?? ''),
        ]);
        $package = self::resolvePricePackage($config['config_json']['price_packages'] ?? [], $params, $config);
        $channel = (string)$package['channel'];
        $quality = (string)$package['quality'];
        $ratio = self::resolvePackageRatio($package, self::resolveSupportedRatio($tenantId, (int)$size['width'], (int)$size['height'], (string)($params['ratio'] ?? $configJson['ratio'] ?? ''), $channel, $quality));
        $referenceImages = array_values(array_filter(array_unique([$sourceImage, $styleImage])));
        return [
            'style_mode' => $styleMode,
            'template' => $template,
            'price_package' => $package,
            'template_id' => (int)($template['id'] ?? $params['template_id'] ?? 0),
            'style_image' => $styleImage,
            'source_image' => $sourceImage,
            'size_key' => $size['key'],
            'width' => (int)$size['width'],
            'height' => (int)$size['height'],
            'user_prompt' => $userPrompt,
            'unit_price' => round(max(0, (float)$package['unit_price']), 2),
            'image_payload' => [
                'prompt' => $prompt,
                'negative_prompt' => (string)($params['negative_prompt'] ?? $config['negative_prompt']),
                'reference_images' => $referenceImages,
                'channel' => $channel,
                'quality' => $quality,
                'ratio' => $ratio,
                'quantity' => 1,
                'style' => 'style_transfer',
            ],
        ];
    }

    private static function buildEstimate(array $prepared, array $imageEstimate): array
    {
        $tenantUnitCost = (float)($imageEstimate['platform_unit_cost'] ?? 0);
        $userUnitPrice = (float)$prepared['unit_price'];
        return array_merge($imageEstimate, [
            'quantity' => 1,
            'target_width' => $prepared['width'],
            'target_height' => $prepared['height'],
            'size_key' => $prepared['size_key'],
            'price_package' => $prepared['price_package'],
            'price_package_code' => $prepared['price_package']['code'] ?? '',
            'price_package_name' => $prepared['price_package']['name'] ?? '',
            'platform_unit_cost' => round($tenantUnitCost, 2),
            'tenant_unit_price' => round($userUnitPrice, 2),
            'tenant_cost_points' => round($tenantUnitCost, 2),
            'user_charge_points' => round($userUnitPrice, 2),
            'display_points' => round($userUnitPrice, 2),
        ]);
    }

    private static function upsertTaskFromImageTask(int $tenantId, int $userId, int $imageTaskId, array $prepared, array $estimate): AigcStyleTransferTask
    {
        $imageTask = AigcImageTask::where(['tenant_id' => $tenantId, 'id' => $imageTaskId])->findOrEmpty();
        if ($imageTask->isEmpty()) {
            throw new Exception('生图任务不存在');
        }
        $row = AigcStyleTransferTask::where(['tenant_id' => $tenantId, 'image_task_id' => $imageTaskId])->findOrEmpty();
        $data = [
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'image_task_id' => $imageTaskId,
            'image_task_ids' => [$imageTaskId],
            'source_image' => $prepared['source_image'],
            'style_mode' => $prepared['style_mode'],
            'template_id' => $prepared['template_id'],
            'style_image' => $prepared['style_image'],
            'size_key' => $prepared['size_key'],
            'width' => $prepared['width'],
            'height' => $prepared['height'],
            'prompt' => $prepared['image_payload']['prompt'],
            'negative_prompt' => $prepared['image_payload']['negative_prompt'],
            'user_prompt' => $prepared['user_prompt'],
            'channel' => $imageTask['channel'],
            'quality' => $imageTask['quality'],
            'ratio' => $imageTask['ratio'],
            'quantity' => 1,
            'tenant_cost_points' => $estimate['tenant_cost_points'],
            'user_charge_points' => $estimate['user_charge_points'],
            'status' => (string)($imageTask['status'] ?: 'running'),
            'error' => (string)$imageTask['error'],
            'finish_time' => (int)$imageTask['finish_time'],
            'update_time' => time(),
        ];
        if ($row->isEmpty()) {
            $data['delete_time'] = 0;
            $data['create_time'] = time();
            return AigcStyleTransferTask::create($data);
        }
        $row->save($data);
        return $row;
    }

    private static function syncTaskFromImageTask(AigcStyleTransferTask $task): void
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
        $imageTasks = AigcImageTask::where('tenant_id', $tenantId)
            ->whereIn('id', $imageTaskIds)
            ->where('delete_time', 0)
            ->select()
            ->toArray();
        if (!$imageTasks) {
            return;
        }
        $statuses = array_map(static fn($row) => (string)($row['status'] ?? ''), $imageTasks);
        if (in_array('failed', $statuses, true)) {
            $task->status = 'failed';
        } elseif (count(array_filter($statuses, static fn($status) => $status === 'success')) === count($imageTaskIds)) {
            $task->status = 'success';
        } else {
            $task->status = 'running';
        }
        $errors = array_values(array_filter(array_map(static fn($row) => trim((string)($row['error'] ?? '')), $imageTasks)));
        $finishTimes = array_filter(array_map(static fn($row) => (int)($row['finish_time'] ?? 0), $imageTasks));
        $task->error = implode('；', array_unique($errors));
        $task->finish_time = in_array((string)$task->status, ['success', 'failed', 'canceled'], true) ? max($finishTimes ?: [time()]) : 0;
        $task->tenant_cost_points = number_format(array_sum(array_map(static fn($row) => (float)($row['tenant_cost_points'] ?? 0), $imageTasks)), 2, '.', '');
        $task->user_charge_points = number_format(array_sum(array_map(static fn($row) => (float)($row['user_charge_points'] ?? 0), $imageTasks)), 2, '.', '');
        $task->update_time = time();
        $task->save();
        self::syncResultsFromImageTask($task);
    }

    private static function syncResultsFromImageTask(AigcStyleTransferTask $task): void
    {
        if ((string)$task['status'] !== 'success') {
            return;
        }
        $tenantId = (int)$task['tenant_id'];
        $userId = (int)$task['user_id'];
        $storage = StorageConfigService::getEffectiveConfig($tenantId);
        foreach (self::taskImageIds($task->toArray()) as $imageTaskId) {
            try {
                $imageTask = AigcImageService::taskDetail($tenantId, $imageTaskId, $userId);
            } catch (\Throwable) {
                continue;
            }
            foreach (($imageTask['results'] ?? []) as $result) {
                $imageResultId = (int)($result['id'] ?? 0);
                if ($imageResultId <= 0) {
                    continue;
                }
                $exists = AigcStyleTransferResult::where(['tenant_id' => $tenantId, 'image_result_id' => $imageResultId])->findOrEmpty();
                if (!$exists->isEmpty()) {
                    continue;
                }
                AigcStyleTransferResult::create([
                    'tenant_id' => $tenantId,
                    'task_id' => (int)$task['id'],
                    'image_task_id' => $imageTaskId,
                    'image_result_id' => $imageResultId,
                    'user_id' => $userId,
                    'image_uri' => (string)($result['image_uri'] ?? ''),
                    'storage_scope' => (string)($result['storage_scope'] ?? $storage['scope'] ?? 'tenant'),
                    'storage_engine' => (string)($result['storage_engine'] ?? $storage['default'] ?? 'local'),
                    'storage_domain' => (string)($result['storage_domain'] ?? StorageConfigService::getEffectiveDomain($tenantId)),
                    'width' => (int)($task['width'] ?: ($result['width'] ?? 0)),
                    'height' => (int)($task['height'] ?: ($result['height'] ?? 0)),
                    'delete_time' => 0,
                    'create_time' => time(),
                ]);
            }
        }
    }

    private static function refreshMappedTasks(int $tenantId, int $userId = 0, int $taskId = 0): void
    {
        $query = AigcStyleTransferTask::where('tenant_id', $tenantId)->where('delete_time', 0);
        if ($userId > 0) {
            $query->where('user_id', $userId);
        }
        if ($taskId > 0) {
            $query->where('id', $taskId);
        } else {
            $query->whereIn('status', ['running', 'pending']);
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
            $query = AigcStyleTransferResult::where('tenant_id', $tenantId)
                ->where('delete_time', 0)
                ->whereIn('task_id', $taskIds)
                ->order('id', 'asc');
            if ($userId > 0) {
                $query->where('user_id', $userId);
            }
            foreach ($query->select()->toArray() as $result) {
                $result['image_url'] = FileService::getFileUrlByStorage(
                    $result['image_uri'],
                    $result['storage_scope'] ?? '',
                    $result['storage_engine'] ?? '',
                    $result['storage_domain'] ?? ''
                );
                $resultMap[(int)$result['task_id']][] = $result;
            }
        }
        foreach ($rows as &$row) {
            $results = $resultMap[(int)$row['id']] ?? [];
            $row['results'] = $results;
            $row['result_count'] = count($results);
            $first = $results[0] ?? [];
            $row['image_url'] = (string)($first['image_url'] ?? '');
            $row['image_uri'] = (string)($first['image_uri'] ?? '');
            $row['source_image_url'] = self::imageUrl((string)($row['source_image'] ?? ''));
            $row['style_image_url'] = self::imageUrl((string)($row['style_image'] ?? ''));
        }
        return $rows;
    }

    private static function formatTaskRow(array $row): array
    {
        $row['task_id'] = (int)($row['id'] ?? 0);
        $row['image_task_id'] = (int)($row['image_task_id'] ?? 0);
        $row['image_task_ids'] = self::taskImageIds($row);
        $row['style_mode_label'] = '风格模板';
        $row['size_label'] = self::sizeLabel((string)($row['size_key'] ?? 'custom'), (int)($row['width'] ?? 0), (int)($row['height'] ?? 0));
        $row['status_label'] = match ((string)($row['status'] ?? '')) {
            'success' => '已完成',
            'failed' => '失败',
            'canceled' => '已取消',
            default => '生成中',
        };
        return $row;
    }

    private static function formatTemplate(array $row): array
    {
        $row['image_url'] = self::imageUrl((string)($row['image'] ?? ''));
        return $row;
    }

    private static function defaults(): array
    {
        return [
            'status' => 1,
            'default_size_key' => '1:1',
            'prompt_template' => self::DEFAULT_PROMPT_TEMPLATE,
            'negative_prompt' => self::DEFAULT_NEGATIVE_PROMPT,
            'config_json' => self::normalizeConfigJson([]),
        ];
    }

    private static function sanitizeConfig(array $data): array
    {
        $data['status'] = (int)($data['status'] ?? 1);
        $data['default_size_key'] = self::normalizeSizeKey($data['default_size_key'] ?? '1:1');
        $data['prompt_template'] = self::normalizeTemplate((string)($data['prompt_template'] ?? self::DEFAULT_PROMPT_TEMPLATE));
        $data['negative_prompt'] = trim((string)($data['negative_prompt'] ?? self::DEFAULT_NEGATIVE_PROMPT));
        $data['config_json'] = self::normalizeConfigJson($data['config_json'] ?? []);
        return $data;
    }

    private static function normalizeConfigJson(mixed $value): array
    {
        $value = is_array($value) ? $value : [];
        return [
            'channel' => trim((string)($value['channel'] ?? '')),
            'quality' => trim((string)($value['quality'] ?? '')),
            'ratio' => trim((string)($value['ratio'] ?? '')),
            'unit_price' => max(0, round((float)($value['unit_price'] ?? 8), 2)),
            'price_packages' => self::normalizePricePackages($value['price_packages'] ?? []),
        ];
    }

    private static function normalizePricePackages(mixed $config): array
    {
        $items = [];
        if (!is_array($config)) {
            return [];
        }
        foreach (array_values($config) as $index => $item) {
            if (!is_array($item)) {
                continue;
            }
            $channel = trim((string)($item['channel'] ?? $item['channel_code'] ?? ''));
            $quality = trim((string)($item['quality'] ?? ''));
            if ($channel === '' || $quality === '') {
                continue;
            }
            $code = self::normalizePackageCode((string)($item['code'] ?? $item['package_code'] ?? ''));
            if ($code === '') {
                $code = 'package_' . ($index + 1);
            }
            $items[$code] = [
                'code' => $code,
                'name' => mb_substr(trim((string)($item['name'] ?? '价格包' . ($index + 1))), 0, 80),
                'channel' => $channel,
                'quality' => $quality,
                'quality_label' => mb_substr(trim((string)($item['quality_label'] ?? '')), 0, 80),
                'unit_price' => round(max(0, (float)($item['unit_price'] ?? $item['tenant_unit_price'] ?? 0)), 2),
                'status' => (int)($item['status'] ?? $item['tenant_status'] ?? 1) ? 1 : 0,
                'sort' => (int)($item['sort'] ?? (100 - $index)),
            ];
        }
        return array_values($items);
    }

    private static function buildPricePackages(array $optionConfig, array $priceConfig): array
    {
        $sourceMap = self::packageSourceMap($optionConfig);
        $packages = [];
        foreach (self::normalizePricePackages($priceConfig) as $item) {
            $key = self::qualityKey((string)$item['channel'], (string)$item['quality']);
            $source = $sourceMap[$key] ?? [];
            $ratios = $source['ratios'] ?? [];
            if (!$ratios) {
                continue;
            }
            $packages[] = [
                'code' => (string)$item['code'],
                'name' => (string)$item['name'],
                'channel' => (string)$item['channel'],
                'channel_name' => (string)($source['channel_name'] ?? $item['channel']),
                'quality' => (string)$item['quality'],
                'quality_label' => (string)($item['quality_label'] ?: ($source['quality_label'] ?? $item['quality'])),
                'unit_price' => round((float)$item['unit_price'], 2),
                'status' => (int)($item['status'] ?? 1),
                'sort' => (int)($item['sort'] ?? 0),
                'ratios' => $ratios,
            ];
        }
        usort($packages, static fn($left, $right) => ((int)($right['sort'] ?? 0) <=> (int)($left['sort'] ?? 0)) ?: strcmp((string)$left['code'], (string)$right['code']));
        return $packages;
    }

    private static function buildPackageSourceOptions(array $optionConfig): array
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
            $channels[] = [
                'code' => (string)$channel['code'],
                'name' => (string)$channel['name'],
                'qualities' => $qualities,
            ];
        }
        return $channels;
    }

    private static function resolvePricePackage(array $priceConfig, array $params, array $config): array
    {
        $packages = self::buildPricePackages($config['option_config'] ?? [], $priceConfig);
        $enabled = array_values(array_filter($packages, static fn($item) => (int)($item['status'] ?? 1) === 1));
        if (!$enabled) {
            throw new Exception('请先配置可用生成质量');
        }
        $code = self::normalizePackageCode((string)($params['price_package'] ?? $params['package_code'] ?? ''));
        if ($code === '') {
            $channel = trim((string)($params['channel'] ?? $config['config_json']['channel'] ?? ''));
            $quality = trim((string)($params['quality'] ?? $config['config_json']['quality'] ?? ''));
            foreach ($enabled as $item) {
                if (($channel === '' || $item['channel'] === $channel) && ($quality === '' || $item['quality'] === $quality)) {
                    return $item;
                }
            }
            return $enabled[0];
        }
        foreach ($enabled as $item) {
            if ((string)$item['code'] === $code) {
                return $item;
            }
        }
        throw new Exception('请选择可用生成质量');
    }

    private static function ensurePricePackages(array $priceConfig, array $optionConfig, float $legacyUnitPrice): array
    {
        $normalized = self::normalizePricePackages($priceConfig);
        $sourceMap = self::packageSourceMap($optionConfig);
        $packages = [];
        foreach ($normalized as $item) {
            $key = self::qualityKey((string)$item['channel'], (string)$item['quality']);
            if (!isset($sourceMap[$key])) {
                continue;
            }
            $source = $sourceMap[$key];
            $item['quality_label'] = (string)($item['quality_label'] ?: ($source['quality_label'] ?? $item['quality']));
            $packages[] = $item;
        }
        if ($packages) {
            return [array_values($packages), count($packages) !== count($normalized)];
        }
        $sourceItems = array_values($sourceMap);
        if (!$sourceItems) {
            return [[], false];
        }
        foreach (array_slice($sourceItems, 0, 2) as $index => $source) {
            $packages[] = [
                'code' => 'default_' . ($index + 1),
                'name' => self::DEFAULT_PRICE_PACKAGE_NAMES[$index] ?? ('价格包' . ($index + 1)),
                'channel' => (string)$source['channel'],
                'quality' => (string)$source['quality'],
                'quality_label' => (string)$source['quality_label'],
                'unit_price' => round(max(0, $legacyUnitPrice > 0 ? $legacyUnitPrice : (float)$source['default_unit_price']), 2),
                'status' => 1,
                'sort' => 100 - $index,
            ];
        }
        return [$packages, true];
    }

    private static function packageSourceMap(array $optionConfig): array
    {
        $map = [];
        foreach (($optionConfig['channels'] ?? []) as $channel) {
            foreach (($channel['qualities'] ?? []) as $quality) {
                $qualityValue = (string)($quality['value'] ?? '');
                if ($qualityValue === '') {
                    continue;
                }
                $ratios = [];
                foreach (($quality['ratios'] ?? []) as $ratio) {
                    $ratios[] = [
                        'value' => (string)($ratio['value'] ?? $ratio['ratio'] ?? ''),
                        'label' => (string)($ratio['label'] ?? $ratio['ratio'] ?? $ratio['value'] ?? ''),
                        'ratio' => (string)($ratio['ratio'] ?? $ratio['value'] ?? ''),
                        'width' => (int)($ratio['width'] ?? 0),
                        'height' => (int)($ratio['height'] ?? 0),
                        'platform_unit_cost' => round((float)($ratio['platform_unit_cost'] ?? 0), 2),
                    ];
                }
                if (!$ratios) {
                    continue;
                }
                $map[self::qualityKey((string)$channel['code'], $qualityValue)] = [
                    'channel' => (string)$channel['code'],
                    'channel_name' => (string)$channel['name'],
                    'quality' => $qualityValue,
                    'quality_label' => (string)($quality['label'] ?? $quality['quality_label'] ?? $qualityValue),
                    'default_unit_price' => (float)($ratios[0]['platform_unit_cost'] ?? 0),
                    'ratios' => $ratios,
                ];
            }
        }
        return $map;
    }

    private static function saveConfigSnapshot(int $tenantId, array $data, AigcStyleTransferConfig $row): void
    {
        $payload = [
            'tenant_id' => $tenantId,
            'status' => (int)($data['status'] ?? 1),
            'default_size_key' => self::normalizeSizeKey($data['default_size_key'] ?? '1:1'),
            'prompt_template' => self::normalizeTemplate((string)($data['prompt_template'] ?? self::DEFAULT_PROMPT_TEMPLATE)),
            'negative_prompt' => trim((string)($data['negative_prompt'] ?? self::DEFAULT_NEGATIVE_PROMPT)),
            'config_json' => self::normalizeConfigJson($data['config_json'] ?? []),
            'update_time' => time(),
        ];
        if ($row->isEmpty()) {
            $payload['create_time'] = time();
            AigcStyleTransferConfig::create($payload);
            return;
        }
        $row->save($payload);
    }

    private static function resolvePackageRatio(array $package, string $ratio): string
    {
        $ratios = $package['ratios'] ?? [];
        $values = array_values(array_filter(array_map(static fn($item) => (string)($item['value'] ?? $item['ratio'] ?? ''), $ratios)));
        if (!$values) {
            return $ratio;
        }
        if ($ratio !== '' && in_array($ratio, $values, true)) {
            return $ratio;
        }
        return $values[0];
    }

    private static function qualityKey(string $channel, string $quality): string
    {
        return $channel . '|' . $quality;
    }

    private static function normalizePackageCode(string $code): string
    {
        return preg_replace('/[^a-zA-Z0-9_\-]/', '', trim($code)) ?: '';
    }

    private static function ensureDefaultCategories(int $tenantId): void
    {
        if (AigcStyleTransferStyleCategory::where('tenant_id', $tenantId)->count() > 0) {
            return;
        }
        foreach (self::defaultCategories() as $index => $item) {
            AigcStyleTransferStyleCategory::create([
                'tenant_id' => $tenantId,
                'code' => $item['code'],
                'name' => $item['name'],
                'sort' => 100 - $index,
                'status' => 1,
                'create_time' => time(),
                'update_time' => time(),
            ]);
        }
    }

    private static function ensureDefaultTemplates(int $tenantId): void
    {
        if (AigcStyleTransferStyleTemplate::where('tenant_id', $tenantId)->where('delete_time', 0)->count() > 0) {
            return;
        }
        $categories = AigcStyleTransferStyleCategory::where('tenant_id', $tenantId)->column('id', 'code');
        foreach (self::defaultTemplates() as $index => $item) {
            $categoryId = (int)($categories[$item['category_code']] ?? 0);
            if ($categoryId <= 0) {
                continue;
            }
            AigcStyleTransferStyleTemplate::create([
                'tenant_id' => $tenantId,
                'category_id' => $categoryId,
                'name' => $item['name'],
                'image' => $item['image'],
                'prompt' => $item['prompt'] ?? '',
                'vip' => (int)($item['vip'] ?? 0),
                'sort' => 100 - $index,
                'status' => 1,
                'delete_time' => 0,
                'create_time' => time(),
                'update_time' => time(),
            ]);
        }
    }

    private static function defaultCategories(): array
    {
        return [
            ['code' => 'overview', 'name' => '全部风格'],
            ['code' => 'portrait', 'name' => '人像风格'],
            ['code' => 'art', 'name' => '艺术绘画'],
            ['code' => 'ecommerce', 'name' => '电商质感'],
            ['code' => 'future', 'name' => '未来科技'],
        ];
    }

    private static function defaultTemplates(): array
    {
        return [
            ['category_code' => 'ecommerce', 'name' => '写实增强', 'image' => 'https://images.unsplash.com/photo-1515886657613-9f3515b0c78f?auto=format&fit=crop&w=640&q=80', 'prompt' => '增强摄影质感、皮肤/材质细节和自然光影，画面保持真实高级。'],
            ['category_code' => 'art', 'name' => '莫奈花园', 'image' => 'https://images.unsplash.com/photo-1490750967868-88aa4486c946?auto=format&fit=crop&w=640&q=80', 'prompt' => '参考印象派柔和笔触、花园色块和温柔光感，形成油画氛围。'],
            ['category_code' => 'ecommerce', 'name' => '中国红', 'image' => 'https://images.unsplash.com/photo-1513289950934-91b4a1f1a8ee?auto=format&fit=crop&w=640&q=80', 'prompt' => '增强东方红金配色、节庆氛围和视觉张力，保持主体清晰。'],
            ['category_code' => 'future', 'name' => '赛博机械', 'image' => 'https://images.unsplash.com/photo-1519608487953-e999c86e7455?auto=format&fit=crop&w=640&q=80', 'prompt' => '加入霓虹、金属、机械和未来科技细节，形成赛博视觉。', 'vip' => 1],
            ['category_code' => 'portrait', 'name' => '玩偶手办', 'image' => 'https://images.unsplash.com/photo-1555685812-4b943f1cb0eb?auto=format&fit=crop&w=640&q=80', 'prompt' => '转换为玩偶手办质感，强化立体塑形、柔和材质和精致陈列。'],
            ['category_code' => 'portrait', 'name' => '动画电影', 'image' => 'https://images.unsplash.com/photo-1534447677768-be436bb09401?auto=format&fit=crop&w=640&q=80', 'prompt' => '强化动画电影镜头感、层次、氛围光和角色主视觉效果。', 'vip' => 1],
            ['category_code' => 'art', 'name' => '国风水墨', 'image' => 'https://images.unsplash.com/photo-1519681393784-d120267933ba?auto=format&fit=crop&w=640&q=80', 'prompt' => '用留白、水墨、宣纸纹理和东方笔意重绘画面，保持主体辨识度。'],
        ];
    }

    private static function renderPrompt(string $template, array $data): string
    {
        $extra = trim((string)($data['user_prompt'] ?? ''));
        $templatePrompt = trim((string)($data['template_prompt'] ?? ''));
        return trim(strtr(self::normalizeTemplate($template), [
            '{style_label}' => (string)($data['style_label'] ?: '目标风格'),
            '{scene_label}' => (string)($data['style_label'] ?: '目标风格'),
            '{width}' => (string)$data['width'],
            '{height}' => (string)$data['height'],
            '{user_prompt}' => $extra !== '' ? '用户补充要求：' . $extra . '。' : '',
            '{template_prompt}' => $templatePrompt !== '' ? '模板要求：' . $templatePrompt . '。' : '',
        ]) . ($extra !== '' && !str_contains($template, '{user_prompt}') ? ' 用户补充要求：' . $extra . '。' : ''));
    }

    private static function resolveSize(array $params, string $defaultKey): array
    {
        $key = self::normalizeSizeKey($params['size_key'] ?? $defaultKey);
        $option = self::sizeOption($key);
        $width = (int)($params['width'] ?? $option['width']);
        $height = (int)($params['height'] ?? $option['height']);
        $width = max(256, min(4096, $width));
        $height = max(256, min(4096, $height));
        if ($key !== 'custom') {
            $width = (int)$option['width'];
            $height = (int)$option['height'];
        } else {
            foreach (self::SIZE_OPTIONS as $item) {
                if ($item['key'] !== 'custom' && (int)$item['width'] === $width && (int)$item['height'] === $height) {
                    $key = (string)$item['key'];
                    break;
                }
            }
        }
        return ['key' => $key, 'width' => $width, 'height' => $height];
    }

    private static function resolveRatio(int $width, int $height, string $fallback = ''): string
    {
        if ($fallback !== '') {
            return $fallback;
        }
        $gcd = self::gcd(max(1, $width), max(1, $height));
        return (int)($width / $gcd) . ':' . (int)($height / $gcd);
    }

    private static function resolveSupportedRatio(int $tenantId, int $width, int $height, string $fallback = '', string $channelCode = '', string $qualityValue = ''): string
    {
        $desired = self::resolveRatio($width, $height, '');
        $config = AigcImageChannelService::userConfig($tenantId);
        $defaults = $config['defaults'] ?? [];
        $allowed = [];
        $defaults = $config['defaults'] ?? [];
        $channelCode = $channelCode !== '' ? $channelCode : (string)($defaults['channel'] ?? '');
        $qualityValue = $qualityValue !== '' ? $qualityValue : (string)($defaults['quality'] ?? '');
        foreach (($config['channels'] ?? []) as $channel) {
            if ($channelCode !== '' && (string)($channel['code'] ?? '') !== $channelCode) {
                continue;
            }
            foreach (($channel['qualities'] ?? []) as $quality) {
                if ($qualityValue !== '' && (string)($quality['value'] ?? '') !== $qualityValue) {
                    continue;
                }
                foreach (($quality['ratios'] ?? []) as $ratio) {
                    $value = (string)($ratio['value'] ?? $ratio['ratio'] ?? '');
                    if ($value !== '') {
                        $allowed[$value] = true;
                    }
                }
            }
        }
        if ($fallback !== '' && isset($allowed[$fallback])) {
            return $fallback;
        }
        if (isset($allowed[$desired])) {
            return $desired;
        }
        $desiredValue = self::ratioValue($desired);
        $bestRatio = '';
        $bestDiff = PHP_FLOAT_MAX;
        foreach (array_keys($allowed) as $ratio) {
            $value = self::ratioValue($ratio);
            if ($value <= 0) {
                continue;
            }
            $diff = abs($value - $desiredValue);
            if ($diff < $bestDiff) {
                $bestDiff = $diff;
                $bestRatio = $ratio;
            }
        }
        return $bestRatio ?: (string)($defaults['ratio'] ?? '1:1');
    }

    private static function ratioValue(string $ratio): float
    {
        if (!str_contains($ratio, ':')) {
            return 0;
        }
        [$w, $h] = array_map('floatval', explode(':', $ratio, 2));
        return $w > 0 && $h > 0 ? $w / $h : 0;
    }

    private static function gcd(int $a, int $b): int
    {
        while ($b !== 0) {
            $t = $b;
            $b = $a % $b;
            $a = $t;
        }
        return max(1, $a);
    }

    private static function normalizeSizeKey(mixed $key): string
    {
        $key = (string)$key;
        foreach (self::SIZE_OPTIONS as $item) {
            if ($item['key'] === $key) {
                return $key;
            }
        }
        return '1:1';
    }

    private static function sizeOption(string $key): array
    {
        foreach (self::SIZE_OPTIONS as $item) {
            if ($item['key'] === $key) {
                return $item;
            }
        }
        return self::SIZE_OPTIONS[1];
    }

    private static function sizeLabel(string $key, int $width, int $height): string
    {
        foreach (self::SIZE_OPTIONS as $item) {
            if ($item['key'] === $key && $key !== 'custom') {
                return (string)$item['label'];
            }
        }
        return '自定义(' . $width . '*' . $height . ')';
    }

    private static function normalizeTemplate(string $template): string
    {
        $template = trim($template);
        return $template !== '' ? $template : self::DEFAULT_PROMPT_TEMPLATE;
    }

    private static function normalizeCode(string $value): string
    {
        return strtolower(preg_replace('/[^a-zA-Z0-9_-]+/', '', trim($value)) ?? '');
    }

    private static function normalizeImage(mixed $image): string
    {
        return trim((string)$image);
    }

    private static function imageUrl(string $image): string
    {
        if ($image === '') {
            return '';
        }
        if (str_starts_with($image, 'http://') || str_starts_with($image, 'https://') || str_starts_with($image, 'data:image/')) {
            return $image;
        }
        return FileService::getFileUrl($image);
    }

    private static function unitPrice(array $configJson): float
    {
        return max(0, round((float)($configJson['unit_price'] ?? 8), 2));
    }

    private static function taskImageIds(array $task): array
    {
        $ids = $task['image_task_ids'] ?? [];
        if (is_string($ids) && $ids !== '') {
            $decoded = json_decode($ids, true);
            $ids = is_array($decoded) ? $decoded : [$ids];
        }
        if (!is_array($ids)) {
            $ids = [];
        }
        if ((int)($task['image_task_id'] ?? 0) > 0) {
            array_unshift($ids, (int)$task['image_task_id']);
        }
        return array_values(array_unique(array_filter(array_map('intval', $ids))));
    }
}
