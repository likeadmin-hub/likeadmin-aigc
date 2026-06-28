<?php

namespace app\common\service\app\aigc_product_image;

use app\common\model\app\App;
use app\common\model\app\aigc_image\AigcImageTask;
use app\common\model\app\aigc_product_image\AigcProductImageConfig;
use app\common\model\app\aigc_product_image\AigcProductImageResult;
use app\common\model\app\aigc_product_image\AigcProductImageSceneCategory;
use app\common\model\app\aigc_product_image\AigcProductImageSceneTemplate;
use app\common\model\app\aigc_product_image\AigcProductImageTask;
use app\common\service\app\AppAccessService;
use app\common\service\app\AppDisplayConfigService;
use app\common\service\app\AppRegistryService;
use app\common\service\app\aigc_image\AigcImageChannelService;
use app\common\service\app\aigc_image\AigcImageService;
use app\common\service\FileService;
use app\common\service\membership\MembershipService;
use app\common\service\point\PointService;
use app\common\service\storage\StorageConfigService;
use Exception;

class AigcProductImageService
{
    public const APP_CODE = 'aigc_product_image';
    public const IMAGE_APP_CODE = 'aigc_image';

    private const DEFAULT_PROMPT_TEMPLATE = '基于商品图片和{scene_label}场景参考，保持商品主体结构、材质、颜色和品牌特征准确，重组背景、布光和陈列关系，生成适合电商主图、详情页或广告投放的高质感商品图。输出尺寸为{width}×{height}。';
    private const DEFAULT_NEGATIVE_PROMPT = '商品变形，主体缺失，文字乱码，水印，低清晰度，过度曝光，背景杂乱，比例异常';

    public static function config(int $tenantId): array
    {
        $row = AigcProductImageConfig::where('tenant_id', $tenantId)->findOrEmpty();
        $data = $row->isEmpty() ? self::defaults() : array_merge(self::defaults(), $row->toArray());
        $data = self::sanitizeConfig($data);
        $data['option_config'] = AigcImageChannelService::userConfig($tenantId);
        $data['size_options'] = self::supportedSizeOptions($data['option_config'], $data['config_json']);
        $data['default_size_key'] = self::normalizeSupportedSizeKey($data['default_size_key'], $data['option_config'], $data['config_json']);
        $data['dependencies'] = self::dependencies($tenantId);
        return AppDisplayConfigService::appendToConfig($tenantId, self::APP_CODE, $data);
    }

    public static function saveConfig(int $tenantId, array $params): void
    {
        AppDisplayConfigService::saveFromConfigPayload($tenantId, self::APP_CODE, $params);
        $current = self::config($tenantId);
        $configJson = is_array($params['config_json'] ?? null) ? $params['config_json'] : ($current['config_json'] ?? []);
        $optionConfig = AigcImageChannelService::userConfig($tenantId);
        $data = [
            'tenant_id' => $tenantId,
            'status' => array_key_exists('status', $params) ? (int)$params['status'] : (int)$current['status'],
            'default_size_key' => self::normalizeSupportedSizeKey($params['default_size_key'] ?? $current['default_size_key'], $optionConfig, $configJson),
            'prompt_template' => self::normalizeTemplate((string)($params['prompt_template'] ?? $current['prompt_template'])),
            'negative_prompt' => trim((string)($params['negative_prompt'] ?? $current['negative_prompt'])),
            'config_json' => self::normalizeConfigJson($configJson),
            'update_time' => time(),
        ];
        $row = AigcProductImageConfig::where('tenant_id', $tenantId)->findOrEmpty();
        if ($row->isEmpty()) {
            $data['create_time'] = time();
            AigcProductImageConfig::create($data);
            return;
        }
        $row->save($data);
    }

    public static function categoryLists(int $tenantId, array $params = []): array
    {
        self::ensureDefaultCategories($tenantId);
        $query = AigcProductImageSceneCategory::where('tenant_id', $tenantId)->order(['sort' => 'desc', 'id' => 'asc']);
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
        $query = AigcProductImageSceneCategory::where(['tenant_id' => $tenantId, 'code' => $code]);
        if ($id > 0) {
            $query->where('id', '<>', $id);
        }
        if (!$query->findOrEmpty()->isEmpty()) {
            throw new Exception('分类标识已存在');
        }
        if ($id > 0) {
            $row = AigcProductImageSceneCategory::where(['tenant_id' => $tenantId, 'id' => $id])->findOrEmpty();
            if ($row->isEmpty()) {
                throw new Exception('分类不存在');
            }
            $row->save($data);
            return $row->toArray();
        }
        $data['create_time'] = time();
        return AigcProductImageSceneCategory::create($data)->toArray();
    }

    public static function setCategoryStatus(int $tenantId, int $id, int $status): void
    {
        $row = AigcProductImageSceneCategory::where(['tenant_id' => $tenantId, 'id' => $id])->findOrEmpty();
        if ($row->isEmpty()) {
            throw new Exception('分类不存在');
        }
        $row->save(['status' => $status ? 1 : 0, 'update_time' => time()]);
    }

    public static function deleteCategory(int $tenantId, int $id): void
    {
        $row = AigcProductImageSceneCategory::where(['tenant_id' => $tenantId, 'id' => $id])->findOrEmpty();
        if ($row->isEmpty()) {
            throw new Exception('分类不存在');
        }
        $templateCount = AigcProductImageSceneTemplate::where(['tenant_id' => $tenantId, 'category_id' => $id])->count();
        if ($templateCount > 0) {
            throw new Exception('该分类下存在场景模板，无法删除');
        }
        $row->delete();
    }

    public static function templateLists(int $tenantId, array $params = []): array
    {
        self::ensureDefaultCategories($tenantId);
        self::ensureDefaultTemplates($tenantId);
        $query = AigcProductImageSceneTemplate::alias('t')
            ->leftJoin('aigc_product_image_scene_category c', 'c.id = t.category_id AND c.tenant_id = t.tenant_id')
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
        $row = AigcProductImageSceneTemplate::alias('t')
            ->leftJoin('aigc_product_image_scene_category c', 'c.id = t.category_id AND c.tenant_id = t.tenant_id')
            ->field('t.*,c.name category_name,c.code category_code')
            ->where('t.tenant_id', $tenantId)
            ->where('t.id', $id)
            ->where('t.delete_time', 0)
            ->findOrEmpty();
        if ($row->isEmpty()) {
            throw new Exception('场景模板不存在');
        }
        return self::formatTemplate($row->toArray());
    }

    public static function saveTemplate(int $tenantId, array $params): array
    {
        self::ensureDefaultCategories($tenantId);
        $id = (int)($params['id'] ?? 0);
        $categoryId = (int)($params['category_id'] ?? 0);
        $category = AigcProductImageSceneCategory::where(['tenant_id' => $tenantId, 'id' => $categoryId])->findOrEmpty();
        if ($category->isEmpty()) {
            throw new Exception('请选择场景分类');
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
            $row = AigcProductImageSceneTemplate::where(['tenant_id' => $tenantId, 'id' => $id])->where('delete_time', 0)->findOrEmpty();
            if ($row->isEmpty()) {
                throw new Exception('场景模板不存在');
            }
            $row->save($data);
            return self::templateDetail($tenantId, $id);
        }
        $data['delete_time'] = 0;
        $data['create_time'] = time();
        $row = AigcProductImageSceneTemplate::create($data);
        return self::templateDetail($tenantId, (int)$row['id']);
    }

    public static function setTemplateStatus(int $tenantId, int $id, int $status): void
    {
        $row = AigcProductImageSceneTemplate::where(['tenant_id' => $tenantId, 'id' => $id])->where('delete_time', 0)->findOrEmpty();
        if ($row->isEmpty()) {
            throw new Exception('场景模板不存在');
        }
        $row->save(['status' => $status ? 1 : 0, 'update_time' => time()]);
    }

    public static function deleteTemplate(int $tenantId, int $id): void
    {
        $row = AigcProductImageSceneTemplate::where(['tenant_id' => $tenantId, 'id' => $id])->where('delete_time', 0)->findOrEmpty();
        if ($row->isEmpty()) {
            throw new Exception('场景模板不存在');
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
        $prepared = self::prepareGeneratePayload($tenantId, $params, true, $userId);
        $imageEstimate = AigcImageService::estimate($tenantId, $prepared['image_payload']);
        $estimate = self::buildEstimate($prepared, $imageEstimate);
        PointService::assertCanConsumeAmounts($tenantId, $userId, (float)$estimate['tenant_cost_points'], (float)$estimate['user_charge_points']);
        $imageResult = AigcImageService::generateWithBillingOverride($tenantId, $userId, $prepared['image_payload'], [
            'tenant_cost_points' => $estimate['tenant_cost_points'],
            'user_charge_points' => $estimate['user_charge_points'],
        ]);
        $imageTaskId = (int)($imageResult['task_id'] ?? 0);
        if ($imageTaskId <= 0) {
            throw new Exception('商品图任务创建失败');
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
        if (!empty($params['sync_running'])) {
            self::refreshMappedTasks($tenantId, $userId);
        }
        $query = AigcProductImageTask::alias('t')
            ->leftJoin('user u', 'u.id = t.user_id AND u.tenant_id = t.tenant_id')
            ->leftJoin('aigc_product_image_scene_template s', 's.id = t.template_id AND s.tenant_id = t.tenant_id')
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
        $sceneMode = trim((string)($params['scene_mode'] ?? ''));
        if ($sceneMode !== '') {
            $query->where('t.scene_mode', $sceneMode);
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
        $query = AigcProductImageTask::where(['tenant_id' => $tenantId, 'id' => $taskId])->where('delete_time', 0);
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
        $task = AigcProductImageTask::where(['tenant_id' => $tenantId, 'id' => $taskId])->where('delete_time', 0)->findOrEmpty();
        if ($task->isEmpty()) {
            throw new Exception('任务不存在');
        }
        return self::generate($tenantId, (int)$task['user_id'], [
            'product_image' => $task['product_image'],
            'scene_mode' => $task['scene_mode'],
            'template_id' => (int)$task['template_id'],
            'custom_scene_image' => $task['custom_scene_image'],
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
        $query = AigcProductImageTask::where(['tenant_id' => $tenantId, 'id' => $taskId]);
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
        AigcProductImageResult::where(['tenant_id' => $tenantId, 'task_id' => $taskId])->update(['delete_time' => time()]);
    }

    public static function deleteResult(int $tenantId, int $resultId, int $userId = 0): void
    {
        $query = AigcProductImageResult::where(['tenant_id' => $tenantId, 'id' => $resultId]);
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
            'required_for' => '商品图生成',
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
        $task = AigcProductImageTask::where('delete_time', 0);
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
        $query = AigcProductImageTask::where('delete_time', 0);
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
            throw new Exception('AI商品图应用未开通或未上架');
        }
        if (AppAccessService::assertTenantCanUse($tenantId, self::IMAGE_APP_CODE) !== null) {
            throw new Exception('AIGC生图应用未开通或未上架');
        }
        $config = self::config($tenantId);
        if ((int)($config['status'] ?? 1) !== 1) {
            throw new Exception('AI商品图应用已停用');
        }
    }

    private static function prepareGeneratePayload(int $tenantId, array $params, bool $requireImages, int $userId = 0): array
    {
        $config = self::config($tenantId);
        $configJson = is_array($config['config_json'] ?? null) ? $config['config_json'] : [];
        $productImage = self::normalizeImage($params['product_image'] ?? $params['image'] ?? '');
        if ($requireImages && $productImage === '') {
            throw new Exception('请上传商品图');
        }
        $sceneMode = (string)($params['scene_mode'] ?? 'template') === 'custom' ? 'custom' : 'template';
        $template = null;
        $sceneImage = '';
        $sceneLabel = '自定义场景';
        if ($sceneMode === 'template') {
            $templateId = (int)($params['template_id'] ?? 0);
            if ($requireImages && $templateId <= 0) {
                throw new Exception('请选择场景模板');
            }
            if ($templateId > 0) {
                $template = self::templateDetail($tenantId, $templateId);
                if ($requireImages && (int)($template['status'] ?? 1) !== 1) {
                    throw new Exception('场景模板已停用');
                }
                if ($requireImages && (int)($template['vip'] ?? 0) === 1) {
                    self::assertVipTemplateAllowed($tenantId, $userId);
                }
                $sceneImage = (string)($template['image'] ?? '');
                $sceneLabel = (string)($template['name'] ?? '场景模板');
            }
        } else {
            $sceneImage = self::normalizeImage($params['custom_scene_image'] ?? '');
            if ($requireImages && $sceneImage === '') {
                throw new Exception('请上传自定义场景图');
            }
        }
        $channel = (string)($params['channel'] ?? $configJson['channel'] ?? '');
        $quality = (string)($params['quality'] ?? $configJson['quality'] ?? '');
        $size = self::resolveSize($params, (string)$config['default_size_key'], $tenantId, $channel, $quality);
        $userPrompt = trim((string)($params['prompt'] ?? $params['user_prompt'] ?? ''));
        $prompt = self::renderPrompt((string)$config['prompt_template'], [
            'scene_label' => $sceneLabel,
            'width' => $size['width'],
            'height' => $size['height'],
            'user_prompt' => $userPrompt,
            'template_prompt' => (string)($template['prompt'] ?? ''),
        ]);
        $ratio = self::resolveSupportedRatio($tenantId, (string)$size['ratio'], $channel, $quality);
        $referenceImages = array_values(array_filter(array_unique([$productImage, $sceneImage])));
        return [
            'scene_mode' => $sceneMode,
            'template' => $template,
            'template_id' => (int)($template['id'] ?? $params['template_id'] ?? 0),
            'custom_scene_image' => $sceneMode === 'custom' ? $sceneImage : '',
            'product_image' => $productImage,
            'scene_image' => $sceneImage,
            'size_key' => $size['key'],
            'width' => (int)$size['width'],
            'height' => (int)$size['height'],
            'user_prompt' => $userPrompt,
            'unit_price' => self::unitPrice($configJson),
            'image_payload' => [
                'prompt' => $prompt,
                'negative_prompt' => (string)($params['negative_prompt'] ?? $config['negative_prompt']),
                'reference_images' => $referenceImages,
                'channel' => $channel,
                'quality' => $quality,
                'ratio' => $ratio,
                'quantity' => 1,
                'style' => 'product_image',
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
            'platform_unit_cost' => round($tenantUnitCost, 2),
            'tenant_unit_price' => round($userUnitPrice, 2),
            'tenant_cost_points' => round($tenantUnitCost, 2),
            'user_charge_points' => round($userUnitPrice, 2),
            'display_points' => round($userUnitPrice, 2),
        ]);
    }

    private static function upsertTaskFromImageTask(int $tenantId, int $userId, int $imageTaskId, array $prepared, array $estimate): AigcProductImageTask
    {
        $imageTask = AigcImageTask::where(['tenant_id' => $tenantId, 'id' => $imageTaskId])->findOrEmpty();
        if ($imageTask->isEmpty()) {
            throw new Exception('生图任务不存在');
        }
        $row = AigcProductImageTask::where(['tenant_id' => $tenantId, 'image_task_id' => $imageTaskId])->findOrEmpty();
        $data = [
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'image_task_id' => $imageTaskId,
            'image_task_ids' => [$imageTaskId],
            'product_image' => $prepared['product_image'],
            'scene_mode' => $prepared['scene_mode'],
            'template_id' => $prepared['template_id'],
            'custom_scene_image' => $prepared['custom_scene_image'],
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
            return AigcProductImageTask::create($data);
        }
        $row->save($data);
        return $row;
    }

    private static function syncTaskFromImageTask(AigcProductImageTask $task): void
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

    private static function syncResultsFromImageTask(AigcProductImageTask $task): void
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
                $exists = AigcProductImageResult::where(['tenant_id' => $tenantId, 'image_result_id' => $imageResultId])->findOrEmpty();
                if (!$exists->isEmpty()) {
                    continue;
                }
                AigcProductImageResult::create([
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
        $query = AigcProductImageTask::where('tenant_id', $tenantId)->where('delete_time', 0);
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
            $query = AigcProductImageResult::where('tenant_id', $tenantId)
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
            $row['product_image_url'] = self::imageUrl((string)($row['product_image'] ?? ''));
            $row['custom_scene_image_url'] = self::imageUrl((string)($row['custom_scene_image'] ?? ''));
        }
        return $rows;
    }

    private static function formatTaskRow(array $row): array
    {
        $row['task_id'] = (int)($row['id'] ?? 0);
        $row['image_task_id'] = (int)($row['image_task_id'] ?? 0);
        $row['image_task_ids'] = self::taskImageIds($row);
        $row['scene_mode_label'] = ($row['scene_mode'] ?? '') === 'custom' ? '自定义场景' : '场景模板';
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
        $row['vip'] = (int)($row['vip'] ?? 0);
        $row['image_url'] = self::imageUrl((string)($row['image'] ?? ''));
        return $row;
    }

    private static function assertVipTemplateAllowed(int $tenantId, int $userId): void
    {
        $membership = $userId > 0 ? MembershipService::status($tenantId, $userId) : [];
        if (($membership['member_status'] ?? MembershipService::MEMBER_NONE) !== MembershipService::MEMBER_ACTIVE) {
            throw new Exception('该场景模板为 VIP 专属，请开通会员后使用');
        }
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
        ];
    }

    private static function ensureDefaultCategories(int $tenantId): void
    {
        if (AigcProductImageSceneCategory::where('tenant_id', $tenantId)->count() > 0) {
            return;
        }
        foreach (self::defaultCategories() as $index => $item) {
            AigcProductImageSceneCategory::create([
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
        if (AigcProductImageSceneTemplate::where('tenant_id', $tenantId)->where('delete_time', 0)->count() > 0) {
            return;
        }
        $categories = AigcProductImageSceneCategory::where('tenant_id', $tenantId)->column('id', 'code');
        foreach (self::defaultTemplates() as $index => $item) {
            $categoryId = (int)($categories[$item['category_code']] ?? 0);
            if ($categoryId <= 0) {
                continue;
            }
            AigcProductImageSceneTemplate::create([
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
            ['code' => 'overview', 'name' => '目录总览'],
            ['code' => 'beauty', 'name' => '美容个护'],
            ['code' => 'food', 'name' => '食品饮品'],
            ['code' => 'shoes', 'name' => '鞋子'],
            ['code' => 'bags', 'name' => '箱包'],
            ['code' => 'phone', 'name' => '手机'],
            ['code' => 'digital', 'name' => '数码产品'],
            ['code' => 'computer', 'name' => '电脑&周边'],
            ['code' => 'appliance', 'name' => '家用电器'],
            ['code' => 'daily', 'name' => '生活百货'],
            ['code' => 'pet', 'name' => '宠物用品'],
            ['code' => 'mother-baby', 'name' => '母婴亲子'],
            ['code' => 'home', 'name' => '家居家装'],
        ];
    }

    private static function defaultTemplates(): array
    {
        return [
            ['category_code' => 'beauty', 'name' => '柔光白底', 'image' => 'https://unsplash.com/photos/7XAYt9xX73s/download?force=true&w=640'],
            ['category_code' => 'beauty', 'name' => '叶影留白', 'image' => 'https://unsplash.com/photos/QeYnt0Zsz7M/download?force=true&w=640', 'vip' => 1],
            ['category_code' => 'food', 'name' => '纸感静物', 'image' => 'https://unsplash.com/photos/-s6awWWQgUY/download?force=true&w=640'],
            ['category_code' => 'computer', 'name' => '窗光桌面', 'image' => 'https://images.unsplash.com/photo-1517705008128-361805f42e86?auto=format&fit=crop&w=640&q=80', 'vip' => 1],
            ['category_code' => 'digital', 'name' => '石纹台面', 'image' => 'https://images.unsplash.com/photo-1524758631624-e2822e304c36?auto=format&fit=crop&w=640&q=80'],
            ['category_code' => 'shoes', 'name' => '柔米展台', 'image' => 'https://unsplash.com/photos/MK7gTkCBAnU/download?force=true&w=640', 'vip' => 1],
            ['category_code' => 'bags', 'name' => '暖金展台', 'image' => 'https://unsplash.com/photos/dlEzfHZm5PE/download?force=true&w=640'],
            ['category_code' => 'digital', 'name' => '多层展台', 'image' => 'https://unsplash.com/photos/XMQrrz88O1o/download?force=true&w=640', 'vip' => 1],
            ['category_code' => 'food', 'name' => '暖橙橱窗', 'image' => 'https://images.unsplash.com/photo-1500530855697-b586d89ba3ee?auto=format&fit=crop&w=640&q=80'],
            ['category_code' => 'home', 'name' => '拱影展台', 'image' => 'https://images.unsplash.com/photo-1519741497674-611481863552?auto=format&fit=crop&w=640&q=80'],
        ];
    }

    private static function renderPrompt(string $template, array $data): string
    {
        $extra = trim((string)($data['user_prompt'] ?? ''));
        $templatePrompt = trim((string)($data['template_prompt'] ?? ''));
        return trim(strtr(self::normalizeTemplate($template), [
            '{scene_label}' => (string)($data['scene_label'] ?: '商品图场景'),
            '{width}' => (string)$data['width'],
            '{height}' => (string)$data['height'],
            '{user_prompt}' => $extra !== '' ? '用户补充要求：' . $extra . '。' : '',
            '{template_prompt}' => $templatePrompt !== '' ? '模板要求：' . $templatePrompt . '。' : '',
        ]) . ($extra !== '' && !str_contains($template, '{user_prompt}') ? ' 用户补充要求：' . $extra . '。' : ''));
    }

    private static function resolveSize(array $params, string $defaultKey, int $tenantId, string $channel = '', string $quality = ''): array
    {
        $options = self::supportedSizeOptions(AigcImageChannelService::userConfig($tenantId), [
            'channel' => $channel,
            'quality' => $quality,
        ]);
        $key = self::normalizeSizeKey($params['size_key'] ?? $defaultKey);
        $option = self::sizeOption($key, $options) ?: ($options[0] ?? null);
        if (!$option) {
            throw new Exception('当前模型暂无可用图片尺寸');
        }
        return [
            'key' => (string)$option['key'],
            'ratio' => (string)($option['ratio'] ?? $option['key']),
            'width' => (int)$option['width'],
            'height' => (int)$option['height'],
        ];
    }

    private static function resolveRatio(int $width, int $height, string $fallback = ''): string
    {
        if ($fallback !== '') {
            return $fallback;
        }
        $gcd = self::gcd(max(1, $width), max(1, $height));
        return (int)($width / $gcd) . ':' . (int)($height / $gcd);
    }

    private static function resolveSupportedRatio(int $tenantId, string $desired, string $channelCode = '', string $qualityValue = ''): string
    {
        $config = AigcImageChannelService::userConfig($tenantId);
        $allowed = self::supportedRatios($config, $channelCode, $qualityValue);
        if (!$allowed) {
            return $desired ?: (string)($config['defaults']['ratio'] ?? '1:1');
        }
        if (isset($allowed[$desired])) {
            return $desired;
        }
        throw new Exception('当前模型不支持该图片尺寸，请选择支持的尺寸');
    }

    private static function supportedSizeOptions(array $optionConfig, array $configJson = []): array
    {
        $configJson = self::normalizeConfigJson($configJson);
        $options = [];
        foreach (self::supportedRatioRows($optionConfig, (string)$configJson['channel'], (string)$configJson['quality']) as $ratio) {
            $value = (string)($ratio['value'] ?? $ratio['ratio'] ?? '');
            if ($value === '' || isset($options[$value])) {
                continue;
            }
            $dimension = self::ratioDimension($ratio);
            $options[$value] = [
                'key' => $value,
                'label' => (string)($ratio['label'] ?? $ratio['ratio'] ?? $value),
                'ratio' => (string)($ratio['ratio'] ?? $value),
                'width' => $dimension['width'],
                'height' => $dimension['height'],
            ];
        }
        return array_values($options);
    }

    private static function normalizeSupportedSizeKey(mixed $key, array $optionConfig, array $configJson = []): string
    {
        $key = self::normalizeSizeKey($key);
        $options = self::supportedSizeOptions($optionConfig, $configJson);
        foreach ($options as $item) {
            if ((string)$item['key'] === $key) {
                return $key;
            }
        }
        return (string)($options[0]['key'] ?? '1:1');
    }

    private static function supportedRatios(array $config, string $channelCode = '', string $qualityValue = ''): array
    {
        $allowed = [];
        foreach (self::supportedRatioRows($config, $channelCode, $qualityValue) as $ratio) {
            $value = (string)($ratio['value'] ?? $ratio['ratio'] ?? '');
            if ($value !== '') {
                $allowed[$value] = true;
            }
        }
        return $allowed;
    }

    private static function supportedRatioRows(array $config, string $channelCode = '', string $qualityValue = ''): array
    {
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
                        $allowed[$value] = $ratio;
                    }
                }
            }
        }
        return array_values($allowed);
    }

    private static function ratioDimension(array $ratio): array
    {
        $width = (int)($ratio['width'] ?? 0);
        $height = (int)($ratio['height'] ?? 0);
        if ($width > 0 && $height > 0) {
            return ['width' => $width, 'height' => $height];
        }
        $value = (string)($ratio['ratio'] ?? $ratio['value'] ?? '');
        if (preg_match('/^(\d+):(\d+)$/', $value, $match)) {
            return ['width' => max(1, (int)$match[1]), 'height' => max(1, (int)$match[2])];
        }
        return ['width' => 1, 'height' => 1];
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
        $key = trim((string)$key);
        return $key !== '' ? $key : '1:1';
    }

    private static function sizeOption(string $key, array $options): ?array
    {
        foreach ($options as $item) {
            if ((string)$item['key'] === $key) {
                return $item;
            }
        }
        return null;
    }

    private static function sizeLabel(string $key, int $width, int $height): string
    {
        if ($key !== '') {
            return $width > 0 && $height > 0 && !in_array($key, ['auto'], true)
                ? $key . '(' . $width . '*' . $height . ')'
                : $key;
        }
        return $width > 0 && $height > 0 ? $width . '*' . $height : '';
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
        if (is_array($image)) {
            $image = $image['uri'] ?? $image['url'] ?? $image['image'] ?? '';
        }
        $image = trim((string)$image);
        if ($image === '' || str_starts_with($image, 'data:image/')) {
            return $image;
        }
        if (!str_starts_with($image, 'http://') && !str_starts_with($image, 'https://')) {
            return ltrim($image, '/');
        }
        $path = ltrim((string)(parse_url($image, PHP_URL_PATH) ?: ''), '/');
        if ($path !== '' && (str_starts_with($path, 'uploads/') || str_starts_with($path, 'resource/'))) {
            return $path;
        }
        return $image;
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
