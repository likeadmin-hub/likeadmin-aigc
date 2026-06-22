<?php

namespace app\common\service\app\aigc_hairstyle;

use app\common\model\app\aigc_hairstyle\AigcHairstyleConfig;
use app\common\model\app\aigc_image\AigcImageBilling;
use app\common\model\app\aigc_image\AigcImageResult;
use app\common\model\app\aigc_image\AigcImageTask;
use app\common\model\app\App;
use app\common\service\app\AppAccessService;
use app\common\service\app\AppDisplayConfigService;
use app\common\service\app\AppRegistryService;
use app\common\service\app\aigc_image\AigcImageChannelService;
use app\common\service\app\aigc_image\AigcImageService;
use app\common\service\FileService;
use Exception;

class AigcHairstyleService
{
    public const APP_CODE = 'aigc_hairstyle';
    public const IMAGE_APP_CODE = 'aigc_image';

    public const OPERATION_HAIR_STYLE = 'hair_style';
    public const OPERATION_HAIR_COLOR = 'hair_color';
    public const OPERATION_HAIR_STYLE_COLOR = 'hair_style_color';

    public const OPERATION_LABELS = [
        self::OPERATION_HAIR_STYLE => '仅换发型',
        self::OPERATION_HAIR_COLOR => '仅换发色',
        self::OPERATION_HAIR_STYLE_COLOR => '发型+发色',
    ];

    private const DEFAULT_PROMPT_TEMPLATE = '基于{person_image}中的人物主体，参考{reference_image}中的发型轮廓、长度、卷度、刘海与发色表现，执行{operation}操作。仅调整头发区域，尽量保持人物五官、脸型、肤色、姿态和画面光线自然一致。{user_prompt}输出真实自然、适合预览与内容创作场景的 AI 换发型结果。';
    private const DEFAULT_NEGATIVE_PROMPT = '面部变形，五官改变，身份不一致，皮肤异常，发丝糊成一团，低清晰度，多余肢体，文字，水印';

    public static function config(int $tenantId): array
    {
        $row = AigcHairstyleConfig::where('tenant_id', $tenantId)->findOrEmpty();
        $data = $row->isEmpty() ? self::defaults() : array_merge(self::defaults(), $row->toArray());
        $data['operation_options'] = self::operationOptions();
        $data['option_config'] = AigcImageChannelService::userConfig($tenantId);
        return AppDisplayConfigService::appendToConfig($tenantId, self::APP_CODE, self::sanitizeConfig($data));
    }

    public static function saveConfig(int $tenantId, array $params): void
    {
        AppDisplayConfigService::saveFromConfigPayload($tenantId, self::APP_CODE, $params);
        $current = self::config($tenantId);
        $operation = self::normalizeOperation($params['default_operation'] ?? $current['default_operation']);
        $data = [
            'tenant_id' => $tenantId,
            'status' => array_key_exists('status', $params) ? (int)$params['status'] : (int)$current['status'],
            'default_operation' => $operation,
            'prompt_template' => self::normalizeTemplate((string)($params['prompt_template'] ?? $current['prompt_template'])),
            'negative_prompt' => trim((string)($params['negative_prompt'] ?? $current['negative_prompt'])),
            'config_json' => self::normalizeConfigJson($params['config_json'] ?? $current['config_json'] ?? []),
            'update_time' => time(),
        ];

        $row = AigcHairstyleConfig::where('tenant_id', $tenantId)->findOrEmpty();
        if ($row->isEmpty()) {
            $data['create_time'] = time();
            AigcHairstyleConfig::create($data);
            return;
        }
        $row->save($data);
    }

    public static function estimate(int $tenantId, array $params): array
    {
        self::assertAvailable($tenantId);
        return AigcImageService::estimate($tenantId, self::buildImagePayload($tenantId, $params, false));
    }

    public static function generate(int $tenantId, int $userId, array $params): array
    {
        self::assertAvailable($tenantId);
        $payload = self::buildImagePayload($tenantId, $params, true);
        return AigcImageService::generate($tenantId, $userId, $payload);
    }

    public static function taskLists(int $tenantId, int $userId, array $params = []): array
    {
        return self::filterHairstyleTasks(AigcImageService::taskLists($tenantId, $userId, array_merge($params, [
            'style' => 'hairstyle',
        ])));
    }

    public static function taskDetail(int $tenantId, int $taskId, int $userId = 0): array
    {
        $task = AigcImageService::taskDetail($tenantId, $taskId, $userId);
        if (($task['style'] ?? '') !== 'hairstyle') {
            throw new Exception('任务不存在');
        }
        $task['reference_image_urls'] = self::imageUrls($task['reference_images'] ?? []);
        $task['operation_label'] = self::operationLabelFromPrompt((string)($task['prompt'] ?? ''));
        return $task;
    }

    public static function retryTask(int $tenantId, int $taskId): array
    {
        self::taskDetail($tenantId, $taskId, 0);
        return AigcImageService::retryTask($tenantId, $taskId);
    }

    public static function deleteTask(int $tenantId, int $taskId, int $userId = 0): void
    {
        self::taskDetail($tenantId, $taskId, $userId);
        AigcImageService::deleteTask($tenantId, $taskId, $userId);
    }

    public static function resultLists(int $tenantId, int $userId, string $status = ''): array
    {
        return self::filterHairstyleTasks(AigcImageService::resultLists($tenantId, $userId, 0, $status, 'hairstyle'));
    }

    public static function operationOptions(): array
    {
        $options = [];
        foreach (self::OPERATION_LABELS as $value => $label) {
            $options[] = ['value' => $value, 'label' => $label];
        }
        return $options;
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
            'required_for' => '图片生成',
            'installed' => $installed,
            'tenant_enabled' => $tenantEnabled,
            'channel_ready' => !empty($channels),
            'ready' => $installed && $tenantEnabled && !empty($channels),
            'message' => $installed ? ($tenantEnabled ? (!empty($channels) ? '可用' : '暂无可用通道') : '租户未开通或未上架') : '应用未安装或未启用',
        ];
        return [
            'items' => [$item],
            'ready' => (bool)$item['ready'],
        ];
    }

    public static function stat(int $tenantId = 0): array
    {
        $task = AigcImageTask::where('style', 'hairstyle')->where('delete_time', 0);
        $result = AigcImageResult::alias('r')
            ->join('aigc_image_task t', 't.id = r.task_id AND t.tenant_id = r.tenant_id')
            ->where('t.style', 'hairstyle')
            ->where('t.delete_time', 0)
            ->where('r.delete_time', 0);
        $billing = AigcImageBilling::alias('b')
            ->join('aigc_image_task t', 't.id = b.task_id AND t.tenant_id = b.tenant_id')
            ->where('t.style', 'hairstyle')
            ->where('t.delete_time', 0);
        if ($tenantId > 0) {
            $task->where('tenant_id', $tenantId);
            $result->where('r.tenant_id', $tenantId);
            $billing->where('b.tenant_id', $tenantId);
        }
        return [
            'task_total' => (int)(clone $task)->count(),
            'task_success' => (int)(clone $task)->where('status', 'success')->count(),
            'task_failed' => (int)(clone $task)->where('status', 'failed')->count(),
            'result_total' => (int)(clone $result)->count(),
            'tenant_cost_points' => round((float)(clone $billing)->sum('b.tenant_cost_points'), 2),
            'user_charge_points' => round((float)(clone $billing)->sum('b.user_charge_points'), 2),
            'dependencies' => self::dependencies($tenantId),
        ];
    }

    public static function tenantUsageLists(array $params = []): array
    {
        $tenantId = (int)($params['tenant_id'] ?? 0);
        $query = AigcImageTask::where('style', 'hairstyle')->where('delete_time', 0);
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

    private static function filterHairstyleTasks(array $rows): array
    {
        $wrap = isset($rows['lists']) && is_array($rows['lists']);
        $list = $wrap ? $rows['lists'] : $rows;
        $filtered = array_values(array_filter($list, function ($row) {
            return is_array($row) && ($row['style'] ?? '') === 'hairstyle';
        }));
        foreach ($filtered as &$row) {
            $row['reference_image_urls'] = self::imageUrls($row['reference_images'] ?? []);
            $row['operation_label'] = self::operationLabelFromPrompt((string)($row['prompt'] ?? ''));
        }
        unset($row);
        if ($wrap) {
            $rows['lists'] = $filtered;
            $rows['count'] = count($filtered);
            return $rows;
        }
        return $filtered;
    }

    private static function imageUrls(mixed $images): array
    {
        $images = is_array($images) ? $images : [];
        $urls = [];
        foreach ($images as $image) {
            $image = trim((string)$image);
            if ($image === '') {
                continue;
            }
            $urls[] = FileService::getFileUrl($image);
        }
        return array_values(array_filter($urls));
    }

    private static function operationLabelFromPrompt(string $prompt): string
    {
        foreach (self::OPERATION_LABELS as $label) {
            if ($label !== '' && str_contains($prompt, $label)) {
                return $label;
            }
        }
        return '';
    }

    private static function assertAvailable(int $tenantId): void
    {
        if (AppAccessService::assertTenantCanUse($tenantId, self::APP_CODE) !== null) {
            throw new Exception('AI换发型应用未开通或未上架');
        }
        if (AppAccessService::assertTenantCanUse($tenantId, self::IMAGE_APP_CODE) !== null) {
            throw new Exception('AIGC生图应用未开通或未上架');
        }
        $config = self::config($tenantId);
        if ((int)($config['status'] ?? 1) !== 1) {
            throw new Exception('AI换发型应用已停用');
        }
    }

    private static function buildImagePayload(int $tenantId, array $params, bool $requireImages): array
    {
        $config = self::config($tenantId);
        $configJson = is_array($config['config_json'] ?? null) ? $config['config_json'] : [];
        $personImage = trim((string)($params['person_image'] ?? $params['image'] ?? ''));
        $referenceImage = trim((string)($params['reference_image'] ?? ''));
        if ($requireImages && $personImage === '') {
            throw new Exception('请上传人物原图');
        }
        if ($requireImages && $referenceImage === '') {
            throw new Exception('请上传发型参考图');
        }
        $operation = self::normalizeOperation($params['operation'] ?? $config['default_operation'] ?? self::OPERATION_HAIR_STYLE_COLOR);
        $userPrompt = trim((string)($params['prompt'] ?? $params['user_prompt'] ?? ''));
        $prompt = self::renderPrompt(
            (string)($config['prompt_template'] ?? self::DEFAULT_PROMPT_TEMPLATE),
            $operation,
            $userPrompt
        );
        return [
            'prompt' => $prompt,
            'negative_prompt' => (string)($params['negative_prompt'] ?? $config['negative_prompt'] ?? self::DEFAULT_NEGATIVE_PROMPT),
            'reference_images' => array_values(array_filter([$personImage, $referenceImage])),
            'channel' => (string)($params['channel'] ?? $configJson['channel'] ?? ''),
            'quality' => (string)($params['quality'] ?? $configJson['quality'] ?? ''),
            'ratio' => (string)($params['ratio'] ?? $configJson['ratio'] ?? ''),
            'quantity' => max(1, min(4, (int)($params['quantity'] ?? $configJson['quantity'] ?? 1))),
            'style' => 'hairstyle',
        ];
    }

    private static function renderPrompt(string $template, string $operation, string $userPrompt): string
    {
        $template = self::normalizeTemplate($template);
        $extra = $userPrompt !== '' ? '用户补充要求：' . $userPrompt . '。' : '';
        return strtr($template, [
            '{person_image}' => '人物原图',
            '{reference_image}' => '发型参考图',
            '{operation}' => self::OPERATION_LABELS[$operation] ?? self::OPERATION_LABELS[self::OPERATION_HAIR_STYLE_COLOR],
            '{user_prompt}' => $extra,
        ]);
    }

    private static function normalizeOperation(mixed $operation): string
    {
        $operation = (string)$operation;
        return array_key_exists($operation, self::OPERATION_LABELS) ? $operation : self::OPERATION_HAIR_STYLE_COLOR;
    }

    private static function normalizeTemplate(string $template): string
    {
        $template = trim($template);
        return $template !== '' ? $template : self::DEFAULT_PROMPT_TEMPLATE;
    }

    private static function normalizeConfigJson(mixed $config): array
    {
        $config = is_array($config) ? $config : [];
        return [
            'channel' => trim((string)($config['channel'] ?? '')),
            'quality' => trim((string)($config['quality'] ?? '')),
            'ratio' => trim((string)($config['ratio'] ?? '')),
            'quantity' => max(1, min(4, (int)($config['quantity'] ?? 1))),
            'person_examples' => self::normalizeExampleImages($config['person_examples'] ?? []),
            'hairstyle_examples' => self::normalizeExampleImages($config['hairstyle_examples'] ?? []),
        ];
    }

    private static function normalizeExampleImages(mixed $items): array
    {
        $items = is_array($items) ? $items : [];
        $normalized = [];
        foreach ($items as $index => $item) {
            if (is_string($item)) {
                $image = trim($item);
                $name = '示例图' . ((int)$index + 1);
            } else {
                $item = is_array($item) ? $item : [];
                $image = trim((string)($item['image'] ?? $item['url'] ?? $item['uri'] ?? ''));
                $name = trim((string)($item['name'] ?? '示例图' . ((int)$index + 1)));
            }
            if ($image === '') {
                continue;
            }
            $normalized[] = [
                'id' => md5($image),
                'name' => $name !== '' ? $name : '示例图' . (count($normalized) + 1),
                'image' => $image,
            ];
            if (count($normalized) >= 12) {
                break;
            }
        }
        return $normalized;
    }

    private static function sanitizeConfig(array $data): array
    {
        $data['status'] = (int)($data['status'] ?? 1);
        $data['default_operation'] = self::normalizeOperation($data['default_operation'] ?? self::OPERATION_HAIR_STYLE_COLOR);
        $data['prompt_template'] = self::normalizeTemplate((string)($data['prompt_template'] ?? ''));
        $data['negative_prompt'] = trim((string)($data['negative_prompt'] ?? self::DEFAULT_NEGATIVE_PROMPT));
        $data['config_json'] = self::normalizeConfigJson($data['config_json'] ?? []);
        return $data;
    }

    private static function defaults(): array
    {
        return [
            'id' => 0,
            'tenant_id' => 0,
            'status' => 1,
            'default_operation' => self::OPERATION_HAIR_STYLE_COLOR,
            'prompt_template' => self::DEFAULT_PROMPT_TEMPLATE,
            'negative_prompt' => self::DEFAULT_NEGATIVE_PROMPT,
            'config_json' => self::normalizeConfigJson([]),
            'create_time' => 0,
            'update_time' => 0,
        ];
    }
}
