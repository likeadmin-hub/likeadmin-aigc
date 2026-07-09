<?php

namespace app\common\service\app\aigc_music;

use app\common\model\app\aigc_music\AigcMusicChannel;
use app\common\model\app\aigc_music\AigcMusicChannelSpec;
use app\common\service\app\ChannelSpecPricingSchemaService;
use Exception;
use think\facade\Db;

class AigcMusicChannelService
{
    public const DEFAULT_UNIT_SECONDS = 30;
    public const DEFAULT_MAX_DURATION = 600;

    public static function userConfig(int $tenantId): array
    {
        $channels = self::effectiveChannels($tenantId, true);
        return [
            'channels' => self::sanitizeChannels($channels, false),
            'defaults' => self::defaults($channels),
            'max_duration' => self::DEFAULT_MAX_DURATION,
            'export_types' => AigcMusicService::exportTypes(),
        ];
    }

    public static function estimate(int $tenantId, array $params): array
    {
        $selection = self::resolveSelection($tenantId, $params);
        $duration = AigcMusicService::normalizeDuration($params['duration'] ?? $selection['spec']['unit_seconds']);
        $unitSeconds = max(1, (int)$selection['spec']['unit_seconds']);
        $quantity = max(1, (int)ceil($duration / $unitSeconds));
        return [
            'channel' => $selection['channel']['code'],
            'channel_name' => $selection['channel']['name'],
            'quality' => $selection['spec']['quality'],
            'quality_label' => $selection['spec']['quality_label'],
            'ratio' => $selection['spec']['ratio'],
            'unit_seconds' => $unitSeconds,
            'duration' => $duration,
            'quantity' => $quantity,
            'platform_unit_cost' => self::formatPoints((float)$selection['spec']['platform_unit_cost']),
            'tenant_unit_price' => self::formatPoints((float)$selection['spec']['tenant_unit_price']),
            'tenant_cost_points' => self::formatPoints((float)$selection['spec']['platform_unit_cost'] * $quantity),
            'user_charge_points' => self::formatPoints((float)$selection['spec']['tenant_unit_price'] * $quantity),
        ];
    }

    public static function resolveSelection(int $tenantId, array $params): array
    {
        $channels = self::effectiveChannels($tenantId, true);
        if (empty($channels)) {
            throw new Exception('暂无可用音乐生成通道');
        }
        $channelCode = (string)($params['channel'] ?? $channels[0]['code']);
        $quality = (string)($params['quality'] ?? '');
        foreach ($channels as $channel) {
            if ($channel['code'] !== $channelCode) {
                continue;
            }
            foreach ($channel['specs'] as $spec) {
                if ($quality === '' || $spec['quality'] === $quality) {
                    return ['channel' => $channel, 'spec' => $spec];
                }
            }
            throw new Exception('当前音乐通道未配置可用价格');
        }
        throw new Exception('音乐生成通道不可用');
    }

    public static function platformLists(): array
    {
        self::ensurePricingSchema();
        return self::effectiveChannels(0, false);
    }

    public static function savePlatform(array $params): void
    {
        if (isset($params['quality']) || isset($params['unit_seconds']) || ($params['type'] ?? '') === 'spec') {
            self::savePlatformSpec($params);
            return;
        }
        $code = self::normalizeCode((string)($params['code'] ?? ''));
        $data = [
            'tenant_id' => 0,
            'code' => $code,
            'name' => trim((string)($params['name'] ?? '')),
            'provider' => trim((string)($params['provider'] ?? 'mock')) ?: 'mock',
            'model' => trim((string)($params['model'] ?? 'music_generation')) ?: 'music_generation',
            'config_json' => self::normalizeJson($params['config_json'] ?? []),
            'status' => (int)($params['status'] ?? 1),
            'sort' => (int)($params['sort'] ?? 0),
            'update_time' => time(),
        ];
        if ($data['name'] === '') {
            throw new Exception('请输入通道名称');
        }
        self::saveRow(AigcMusicChannel::class, ['tenant_id' => 0, 'code' => $code], $data);
    }

    public static function savePlatformSpec(array $params): void
    {
        self::ensurePricingSchema();
        $channelCode = self::normalizeCode((string)($params['channel_code'] ?? $params['code'] ?? ''));
        self::assertPlatformChannel($channelCode);
        $unitSeconds = max(1, (int)($params['unit_seconds'] ?? $params['quality'] ?? self::DEFAULT_UNIT_SECONDS));
        $data = [
            'tenant_id' => 0,
            'channel_code' => $channelCode,
            'quality' => (string)$unitSeconds,
            'quality_label' => trim((string)($params['quality_label'] ?? ($unitSeconds . '秒音乐'))),
            'ratio' => 'duration',
            'unit_seconds' => $unitSeconds,
            'upstream_unit_cost' => self::formatPoints((float)($params['upstream_unit_cost'] ?? 0)),
            'platform_unit_cost' => self::formatPoints((float)($params['platform_unit_cost'] ?? 0)),
            'tenant_unit_price' => self::formatPoints((float)($params['tenant_unit_price'] ?? $params['platform_unit_cost'] ?? 0)),
            'upstream_cost_text' => trim((string)($params['upstream_cost_text'] ?? '')),
            'cost_source_url' => trim((string)($params['cost_source_url'] ?? '')),
            'provider_params_json' => ['unit_seconds' => $unitSeconds],
            'status' => (int)($params['status'] ?? 1),
            'sort' => (int)($params['sort'] ?? 0),
            'update_time' => time(),
        ];
        self::saveRow(AigcMusicChannelSpec::class, [
            'tenant_id' => 0,
            'channel_code' => $channelCode,
            'quality' => (string)$unitSeconds,
            'ratio' => 'duration',
        ], $data);
    }

    public static function batchSavePlatformSpecs(array $params): void
    {
        $specs = $params['specs'] ?? $params['items'] ?? [];
        if (!is_array($specs) || empty($specs)) {
            throw new Exception('请选择要保存的规格');
        }
        Db::transaction(function () use ($specs) {
            foreach ($specs as $spec) {
                if (is_array($spec)) {
                    self::savePlatformSpec($spec);
                }
            }
        });
    }

    public static function deletePlatform(array $params): void
    {
        throw new Exception('内置通道和规格不允许删除');
    }

    public static function statusPlatform(array $params): void
    {
        $model = (($params['type'] ?? '') === 'spec') ? AigcMusicChannelSpec::class : AigcMusicChannel::class;
        $row = $model::where(['tenant_id' => 0, 'id' => (int)($params['id'] ?? 0)])->findOrEmpty();
        if ($row->isEmpty()) {
            throw new Exception('记录不存在');
        }
        $row->save(['status' => (int)($params['status'] ?? 1), 'update_time' => time()]);
    }

    public static function tenantLists(int $tenantId): array
    {
        return self::sanitizeChannels(self::effectiveChannels($tenantId, false), true);
    }

    public static function saveTenant(int $tenantId, array $params): void
    {
        if (isset($params['quality']) || isset($params['unit_seconds']) || ($params['type'] ?? '') === 'spec') {
            self::saveTenantSpec($tenantId, $params);
            return;
        }
        $code = self::normalizeCode((string)($params['code'] ?? ''));
        $platform = self::assertPlatformChannel($code);
        self::saveRow(AigcMusicChannel::class, ['tenant_id' => $tenantId, 'code' => $code], [
            'tenant_id' => $tenantId,
            'code' => $code,
            'name' => trim((string)($params['name'] ?? $platform['name'])),
            'provider' => $platform['provider'],
            'model' => $platform['model'],
            'config_json' => [],
            'status' => (int)($params['status'] ?? 1),
            'sort' => (int)($params['sort'] ?? $platform['sort']),
            'update_time' => time(),
        ]);
    }

    public static function saveTenantSpec(int $tenantId, array $params): void
    {
        $channelCode = self::normalizeCode((string)($params['channel_code'] ?? $params['code'] ?? ''));
        $quality = (string)max(1, (int)($params['quality'] ?? $params['unit_seconds'] ?? self::DEFAULT_UNIT_SECONDS));
        $platform = AigcMusicChannelSpec::where([
            'tenant_id' => 0,
            'channel_code' => $channelCode,
            'quality' => $quality,
            'ratio' => 'duration',
        ])->findOrEmpty();
        if ($platform->isEmpty()) {
            throw new Exception('平台未开放该规格');
        }
        self::saveRow(AigcMusicChannelSpec::class, [
            'tenant_id' => $tenantId,
            'channel_code' => $channelCode,
            'quality' => $quality,
            'ratio' => 'duration',
        ], [
            'tenant_id' => $tenantId,
            'channel_code' => $channelCode,
            'quality' => $quality,
            'quality_label' => $platform['quality_label'],
            'ratio' => 'duration',
            'unit_seconds' => (int)$platform['unit_seconds'],
            'upstream_unit_cost' => (string)($platform['upstream_unit_cost'] ?? '0.00'),
            'platform_unit_cost' => (string)$platform['platform_unit_cost'],
            'tenant_unit_price' => self::formatPoints((float)($params['tenant_unit_price'] ?? $platform['tenant_unit_price'] ?? $platform['platform_unit_cost'])),
            'upstream_cost_text' => (string)($platform['upstream_cost_text'] ?? ''),
            'cost_source_url' => (string)($platform['cost_source_url'] ?? ''),
            'provider_params_json' => [],
            'status' => (int)($params['status'] ?? 1),
            'sort' => (int)($params['sort'] ?? $platform['sort']),
            'update_time' => time(),
        ]);
    }

    public static function batchSaveTenantSpecs(int $tenantId, array $params): void
    {
        $specs = $params['specs'] ?? $params['items'] ?? [];
        if (!is_array($specs) || empty($specs)) {
            throw new Exception('请选择要保存的规格');
        }
        Db::transaction(function () use ($tenantId, $specs) {
            foreach ($specs as $spec) {
                if (is_array($spec)) {
                    self::saveTenantSpec($tenantId, $spec);
                }
            }
        });
    }

    private static function effectiveChannels(int $tenantId, bool $onlyEnabled): array
    {
        self::ensurePricingSchema();
        $platformChannels = AigcMusicChannel::where('tenant_id', 0)->order(['sort' => 'desc', 'id' => 'asc'])->select()->toArray();
        $tenantChannels = $tenantId > 0 ? AigcMusicChannel::where('tenant_id', $tenantId)->select()->toArray() : [];
        $tenantChannelMap = array_column($tenantChannels, null, 'code');
        $platformSpecs = AigcMusicChannelSpec::where('tenant_id', 0)->order(['sort' => 'desc', 'id' => 'asc'])->select()->toArray();
        $tenantSpecs = $tenantId > 0 ? AigcMusicChannelSpec::where('tenant_id', $tenantId)->select()->toArray() : [];
        $tenantSpecMap = [];
        foreach ($tenantSpecs as $spec) {
            $tenantSpecMap[$spec['channel_code'] . '|' . $spec['quality'] . '|' . $spec['ratio']] = $spec;
        }
        $specsByChannel = [];
        foreach ($platformSpecs as $spec) {
            $specsByChannel[$spec['channel_code']][] = $spec;
        }
        $channels = [];
        foreach ($platformChannels as $platformChannel) {
            $override = $tenantChannelMap[$platformChannel['code']] ?? [];
            $status = (int)$platformChannel['status'] === 1 && (int)($override['status'] ?? 1) === 1 ? 1 : 0;
            if ($onlyEnabled && !$status) {
                continue;
            }
            $channel = [
                'id' => (int)$platformChannel['id'],
                'tenant_override_id' => (int)($override['id'] ?? 0),
                'code' => $platformChannel['code'],
                'value' => $platformChannel['code'],
                'name' => $override['name'] ?? $platformChannel['name'],
                'label' => $override['name'] ?? $platformChannel['name'],
                'provider' => $platformChannel['provider'],
                'model' => $platformChannel['model'],
                'status' => $status,
                'platform_status' => (int)$platformChannel['status'],
                'tenant_status' => (int)($override['status'] ?? 1),
                'sort' => (int)($override['sort'] ?? $platformChannel['sort']),
                'config_json' => self::normalizeJson($platformChannel['config_json'] ?? []),
                'specs' => [],
            ];
            foreach ($specsByChannel[$platformChannel['code']] ?? [] as $platformSpec) {
                $tenantSpec = $tenantSpecMap[$platformSpec['channel_code'] . '|' . $platformSpec['quality'] . '|' . $platformSpec['ratio']] ?? [];
                $specStatus = (int)$platformSpec['status'] === 1 && (int)($tenantSpec['status'] ?? 1) === 1 ? 1 : 0;
                if ($onlyEnabled && !$specStatus) {
                    continue;
                }
                $channel['specs'][] = [
                    'id' => (int)$platformSpec['id'],
                    'tenant_override_id' => (int)($tenantSpec['id'] ?? 0),
                    'channel_code' => $platformSpec['channel_code'],
                    'quality' => $platformSpec['quality'],
                    'quality_label' => $platformSpec['quality_label'],
                    'ratio' => $platformSpec['ratio'],
                    'unit_seconds' => (int)($platformSpec['unit_seconds'] ?? self::DEFAULT_UNIT_SECONDS),
                    'upstream_unit_cost' => self::formatPoints((float)($platformSpec['upstream_unit_cost'] ?? 0)),
                    'platform_unit_cost' => self::formatPoints((float)$platformSpec['platform_unit_cost']),
                    'tenant_unit_price' => self::formatPoints((float)($tenantSpec['tenant_unit_price'] ?? $platformSpec['tenant_unit_price'] ?? $platformSpec['platform_unit_cost'])),
                    'upstream_cost_text' => (string)($platformSpec['upstream_cost_text'] ?? ''),
                    'cost_source_url' => (string)($platformSpec['cost_source_url'] ?? ''),
                    'provider_params_json' => self::normalizeJson($platformSpec['provider_params_json'] ?? []),
                    'status' => $specStatus,
                    'platform_status' => (int)$platformSpec['status'],
                    'tenant_status' => (int)($tenantSpec['status'] ?? 1),
                    'sort' => (int)($tenantSpec['sort'] ?? $platformSpec['sort']),
                ];
            }
            if (!$onlyEnabled || !empty($channel['specs'])) {
                $channels[] = $channel;
            }
        }
        return $channels;
    }

    private static function defaults(array $channels): array
    {
        $channel = $channels[0] ?? [];
        $spec = $channel['specs'][0] ?? [];
        return [
            'channel' => $channel['code'] ?? '',
            'quality' => $spec['quality'] ?? (string)self::DEFAULT_UNIT_SECONDS,
            'ratio' => 'duration',
            'duration' => (int)($spec['unit_seconds'] ?? self::DEFAULT_UNIT_SECONDS),
            'quantity' => 1,
        ];
    }

    private static function assertPlatformChannel(string $code): array
    {
        $channel = AigcMusicChannel::where(['tenant_id' => 0, 'code' => $code])->findOrEmpty();
        if ($channel->isEmpty()) {
            throw new Exception('平台通道不存在');
        }
        return $channel->toArray();
    }

    private static function normalizeCode(string $code): string
    {
        $code = strtolower(trim($code));
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $code)) {
            throw new Exception('通道编码必须为小写字母、数字或下划线');
        }
        return $code;
    }

    private static function normalizeJson($value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && trim($value) !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return [];
    }

    private static function sanitizeChannels(array $channels, bool $forTenantAdmin): array
    {
        foreach ($channels as &$channel) {
            unset($channel['config_json']);
            if (!$forTenantAdmin) {
                unset($channel['provider'], $channel['model']);
                foreach ($channel['specs'] as &$spec) {
                    unset($spec['provider_params_json']);
                }
            }
        }
        return $channels;
    }

    private static function saveRow(string $modelClass, array $where, array $data): void
    {
        $row = $modelClass::where($where)->findOrEmpty();
        if ($row->isEmpty()) {
            $data['create_time'] = time();
            $modelClass::create($data);
            return;
        }
        $row->save($data);
    }

    private static function ensurePricingSchema(): void
    {
        ChannelSpecPricingSchemaService::ensure('aigc_music_channel_spec', '每单位上游成本');
    }

    private static function formatPoints(float $points): string
    {
        return number_format($points, 2, '.', '');
    }
}
