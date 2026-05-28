<?php

namespace app\common\service\app;

use app\common\model\app\App;
use app\common\model\app\TenantAppConfig;
use app\common\service\FileService;

class AppDisplayConfigService
{
    public const DEFAULT_APP_CODES = [
        'aigc_image',
        'aigc_video',
        'aigc_digital_human',
        'aigc_canvas',
        'aigc_llm',
        'image_human',
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
        $row = TenantAppConfig::where([
            'tenant_id' => $tenantId,
            'app_code' => $appCode,
        ])->findOrEmpty();

        if ($row->isEmpty()) {
            return self::withUrls($default);
        }

        $data = array_merge($default, array_filter($row->toArray(), static fn($value) => $value !== null));
        $data['title'] = trim((string)($data['title'] ?? '')) ?: $default['title'];
        $data['description'] = trim((string)($data['description'] ?? '')) ?: $default['description'];
        $data['cover_uri'] = (string)($data['cover_uri'] ?? $default['cover_uri']);
        $data['icon_uri'] = (string)($data['icon_uri'] ?? $default['icon_uri']);
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
        $icon = FileService::setFileUrl((string)($params['icon_uri'] ?? $params['icon_url'] ?? $params['icon'] ?? $current['icon_uri'] ?? ''));
        $data = [
            'tenant_id' => $tenantId,
            'app_code' => $appCode,
            'title' => mb_substr(trim((string)($params['title'] ?? $current['title'] ?? '')), 0, 80),
            'description' => mb_substr(trim((string)($params['description'] ?? $current['description'] ?? '')), 0, 500),
            'cover_uri' => $cover,
            'icon_uri' => $icon,
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
        $app = App::where('code', $appCode)->findOrEmpty();
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
        if (!$app->isEmpty()) {
            $appData = $app->toArray();
            $data['title'] = $data['title'] ?: trim((string)($appData['name'] ?? ''));
            $data['description'] = $data['description'] ?: trim((string)($appData['description'] ?? ''));
            $data['cover_uri'] = (string)($appData['cover'] ?? '');
            $data['icon_uri'] = (string)($appData['icon'] ?? '');
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
