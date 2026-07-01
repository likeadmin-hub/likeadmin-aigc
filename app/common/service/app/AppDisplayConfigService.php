<?php

namespace app\common\service\app;

use app\common\model\app\App;
use app\common\model\app\TenantAppConfig;
use app\common\service\FileService;
use Throwable;

class AppDisplayConfigService
{
    public const DEFAULT_APP_CODES = [
        'aigc_image',
        'aigc_video',
        'aigc_digital_human',
        'aigc_canvas',
        'aigc_llm',
        'image_human',
        'smart_clip',
        'aigc_hairstyle',
        'aigc_fitting',
        'aigc_product_image',
        'aigc_photo_restore',
        'aigc_model_wear',
        'aigc_background_removal',
        'aigc_image_translate',
        'aigc_one_click_cleanup',
        'aigc_product_suite',
        'aigc_product_multi_angle',
        'aigc_fashion_lookbook',
        'aigc_product_promo_video',
        'aigc_action_transfer',
        'aigc_person_replacement',
        'aigc_outpaint',
        'aigc_local_redraw',
        'aigc_style_transfer',
    ];

    private const DEFAULTS = [
        'aigc_image' => [
            'title' => '图片生成',
            'description' => '输入提示词或参考图，快速生成高质量图片。',
            'sort' => 100,
        ],
        'aigc_video' => [
            'title' => '视频生成',
            'description' => '一键生成动态镜头与叙事短片。',
            'sort' => 95,
        ],
        'aigc_digital_human' => [
            'title' => '数字人视频',
            'description' => '形象、音色与文案组合生成数字人视频。',
            'sort' => 90,
        ],
        'aigc_canvas' => [
            'title' => '无限画布',
            'description' => '在画布中编排多节点 AI 创作流程。',
            'sort' => 85,
        ],
        'aigc_llm' => [
            'title' => 'AIGC 对话',
            'description' => '多轮上下文大模型对话工作台。',
            'sort' => 80,
        ],
        'image_human' => [
            'title' => '全驱数字人',
            'description' => '上传人物图片与参考音频生成全驱动数字人视频。',
            'sort' => 75,
        ],
        'smart_clip' => [
            'title' => 'AI视频剪辑',
            'description' => '上传视频或从作品带入素材，选择模板完成智能混剪。',
            'sort' => 70,
        ],
        'aigc_hairstyle' => [
            'title' => 'AI换发型',
            'description' => '上传人物原图和发型参考图，一键生成自然换发型效果。',
            'sort' => 68,
        ],
        'aigc_fitting' => [
            'title' => 'AI试衣',
            'description' => '上传服装图，选择模特或自定义模特生成真实试衣效果。',
            'sort' => 66,
        ],
        'aigc_product_image' => [
            'title' => 'AI商品图',
            'description' => '上传商品图后选择场景模板或自定义场景，生成适合电商展示的商品图。',
            'sort' => 64,
        ],
        'aigc_photo_restore' => [
            'title' => '老照片修复',
            'description' => '上传老照片后选择修复类型、模型和比例，生成清晰自然的修复作品。',
            'sort' => 63,
        ],
        'aigc_model_wear' => [
            'title' => '模特穿戴',
            'description' => '上传模特图和穿戴图，选择生成质量与比例，生成自然的穿戴效果图。',
            'sort' => 62,
        ],
        'aigc_background_removal' => [
            'title' => '图片去背景',
            'description' => '上传图片后智能抠图，快速生成干净透明背景素材。',
            'sort' => 61,
        ],
        'aigc_image_translate' => [
            'title' => '图片翻译',
            'description' => '识别并翻译图片中的文字，保留原有画面排版风格。',
            'sort' => 60,
        ],
        'aigc_one_click_cleanup' => [
            'title' => '一键消除',
            'description' => '批量清理图片中的水印、文字、贴纸和干扰元素。',
            'sort' => 59,
        ],
        'aigc_product_suite' => [
            'title' => 'AI商品套图',
            'description' => '上传商品图后一键生成多张电商场景套图。',
            'sort' => 58,
        ],
        'aigc_product_multi_angle' => [
            'title' => '商品多角度',
            'description' => '基于商品图生成正面、侧面、背面等多角度展示图。',
            'sort' => 57,
        ],
        'aigc_fashion_lookbook' => [
            'title' => '服饰套图',
            'description' => '上传服饰和模特图，生成适合电商陈列的服饰套图。',
            'sort' => 56,
        ],
        'aigc_product_promo_video' => [
            'title' => '产品宣传视频',
            'description' => '上传产品图片并选择脚本类型，生成产品宣传短视频。',
            'sort' => 55,
        ],
        'aigc_action_transfer' => [
            'title' => '动作迁移',
            'description' => '上传参考人物图片和参考视频，将视频动作迁移到人物形象。',
            'sort' => 54,
        ],
        'aigc_person_replacement' => [
            'title' => '动作替换',
            'description' => '上传参考人物图片和输入视频，将视频人物替换为目标形象。',
            'sort' => 53,
        ],
        'aigc_outpaint' => [
            'title' => '无缝扩图',
            'description' => '上传图片后智能延展画面，生成自然连续的扩图结果。',
            'sort' => 52,
        ],
        'aigc_local_redraw' => [
            'title' => '局部重绘',
            'description' => '上传图片并选择局部区域，按提示词重绘指定内容。',
            'sort' => 51,
        ],
        'aigc_style_transfer' => [
            'title' => '图片风格化',
            'description' => '上传图片并选择风格模板，生成统一风格化作品。',
            'sort' => 50,
        ],
    ];

    public static function appendToConfig(int $tenantId, string $appCode, array $config): array
    {
        $config['display_config'] = self::detail($tenantId, $appCode);
        return $config;
    }

    public static function detail(int $tenantId, string $appCode): array
    {
        $appCode = self::normalizeAppCode($appCode);
        $default = self::defaultConfig($appCode);
        try {
            $row = TenantAppConfig::where([
                'tenant_id' => $tenantId,
                'app_code' => $appCode,
            ])->findOrEmpty();
        } catch (Throwable $e) {
            return self::withUrls($default);
        }

        if ($row->isEmpty()) {
            return self::withUrls($default);
        }

        $data = array_merge($default, array_filter($row->toArray(), static fn($value) => $value !== null));
        $data['title'] = trim((string)($data['title'] ?? '')) ?: $default['title'];
        $data['description'] = trim((string)($data['description'] ?? '')) ?: $default['description'];
        $data['cover_uri'] = (string)($data['cover_uri'] ?? $default['cover_uri']);
        $data['icon_uri'] = (string)($data['icon_uri'] ?? '');
        $data['virtual_use_count'] = (string)($data['virtual_use_count'] ?? '');
        $data['sort'] = (int)($data['sort'] ?? $default['sort']);
        $data['status'] = (int)($data['status'] ?? 1);
        $data['extra'] = is_array($data['extra'] ?? null) ? $data['extra'] : [];
        return self::withUrls($data);
    }

    public static function lists(int $tenantId, array $appCodes = self::DEFAULT_APP_CODES): array
    {
        $rows = [];
        foreach ($appCodes as $appCode) {
            $rows[] = self::detail($tenantId, $appCode);
        }
        usort($rows, static function ($left, $right) {
            $sortCompare = (int)($right['sort'] ?? 0) <=> (int)($left['sort'] ?? 0);
            if ($sortCompare !== 0) {
                return $sortCompare;
            }
            return strcmp((string)($left['app_code'] ?? ''), (string)($right['app_code'] ?? ''));
        });
        return $rows;
    }

    public static function map(int $tenantId, array $appCodes = self::DEFAULT_APP_CODES): array
    {
        $map = [];
        foreach (self::lists($tenantId, $appCodes) as $item) {
            $map[$item['app_code']] = $item;
        }
        return $map;
    }

    public static function saveFromConfigPayload(int $tenantId, string $appCode, array $params): void
    {
        if (!isset($params['display_config']) || !is_array($params['display_config'])) {
            return;
        }
        self::save($tenantId, $appCode, $params['display_config']);
    }

    public static function save(int $tenantId, string $appCode, array $params): array
    {
        $appCode = self::normalizeAppCode($appCode);
        $current = self::detail($tenantId, $appCode);
        $cover = FileService::setFileUrl((string)($params['cover_uri'] ?? $params['cover_url'] ?? $params['cover'] ?? $current['cover_uri'] ?? ''));
        $data = [
            'tenant_id' => $tenantId,
            'app_code' => $appCode,
            'title' => mb_substr(trim((string)($params['title'] ?? $current['title'] ?? '')), 0, 80),
            'description' => mb_substr(trim((string)($params['description'] ?? $current['description'] ?? '')), 0, 500),
            'cover_uri' => $cover,
            'icon_uri' => (string)($current['icon_uri'] ?? ''),
            'virtual_use_count' => mb_substr(trim((string)($params['virtual_use_count'] ?? $params['virtualUseCount'] ?? $current['virtual_use_count'] ?? '')), 0, 50),
            'sort' => (int)($params['sort'] ?? $current['sort'] ?? 0),
            'status' => (int)($params['status'] ?? $current['status'] ?? 1),
            'extra' => is_array($params['extra'] ?? null) ? $params['extra'] : (array)($current['extra'] ?? []),
            'update_time' => time(),
        ];
        if ($data['title'] === '') {
            $data['title'] = self::defaultConfig($appCode)['title'];
        }

        $row = TenantAppConfig::where([
            'tenant_id' => $tenantId,
            'app_code' => $appCode,
        ])->findOrEmpty();
        if ($row->isEmpty()) {
            $data['create_time'] = time();
            TenantAppConfig::create($data);
        } else {
            $row->save($data);
        }

        return self::detail($tenantId, $appCode);
    }

    private static function defaultConfig(string $appCode): array
    {
        try {
            $app = App::where('code', $appCode)->findOrEmpty();
        } catch (Throwable $e) {
            $app = null;
        }
        $local = self::DEFAULTS[$appCode] ?? [
            'title' => $appCode,
            'description' => '',
            'sort' => 0,
        ];
        $data = [
            'id' => 0,
            'tenant_id' => 0,
            'app_code' => $appCode,
            'title' => $local['title'],
            'description' => $local['description'],
            'cover_uri' => '',
            'icon_uri' => '',
            'virtual_use_count' => '',
            'sort' => (int)$local['sort'],
            'status' => 1,
            'extra' => [],
            'create_time' => 0,
            'update_time' => 0,
        ];
        if ($app !== null && !$app->isEmpty()) {
            $appData = $app->toArray();
            $manifest = is_array($appData['manifest_json'] ?? null) ? $appData['manifest_json'] : [];
            $manifestMeta = is_array($manifest['meta'] ?? null) ? $manifest['meta'] : [];
            if (!isset(self::DEFAULTS[$appCode])) {
                $data['title'] = trim((string)($appData['name'] ?? $manifest['name'] ?? $data['title'])) ?: $data['title'];
                $data['description'] = trim((string)($appData['description'] ?? $manifest['description'] ?? $data['description'])) ?: $data['description'];
            }
            $data['cover_uri'] = trim((string)($appData['cover'] ?? $manifest['cover'] ?? $manifestMeta['cover'] ?? $data['cover_uri']));
            $data['sort'] = (int)($appData['sort'] ?? $manifestMeta['sort'] ?? $manifest['sort'] ?? $data['sort']);
        }
        return $data;
    }

    private static function withUrls(array $data): array
    {
        $data['cover_url'] = !empty($data['cover_uri']) ? FileService::getFileUrl((string)$data['cover_uri']) : '';
        $data['icon_url'] = !empty($data['icon_uri']) ? FileService::getFileUrl((string)$data['icon_uri']) : '';
        return $data;
    }

    private static function normalizeAppCode(string $appCode): string
    {
        $appCode = strtolower(trim($appCode));
        return preg_match('/^[a-z0-9_]+$/', $appCode) ? $appCode : '';
    }
}
