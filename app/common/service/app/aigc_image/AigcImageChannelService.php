<?php

namespace app\common\service\app\aigc_image;

use app\common\model\app\aigc_image\AigcImageChannel;
use app\common\model\app\aigc_image\AigcImageChannelSpec;
use Exception;

class AigcImageChannelService
{
    public const QUANTITY_OPTIONS = [1, 2, 3, 4];
    public const DEFAULT_REFERENCE_LIMIT = 4;

    public static function userConfig(int $tenantId): array
    {
        $channels = self::effectiveChannels($tenantId, true);
        $defaults = self::defaults($channels);
        return [
            'channels' => self::sanitizeChannels($channels, false),
            'defaults' => $defaults,
            'quantity_options' => self::quantityOptions($channels),
            'max_reference_images' => self::maxReferenceImages($channels),
        ];
    }

    public static function estimate(int $tenantId, array $params): array
    {
        $resolved = self::resolveSelection($tenantId, $params);
        $quantity = self::normalizeQuantity($params['quantity'] ?? 1);
        self::assertChannelQuantity($resolved['channel'], $quantity);
        $tenantCost = self::formatPoints((float)$resolved['spec']['platform_unit_cost'] * $quantity);
        $userCharge = self::formatPoints((float)$resolved['spec']['tenant_unit_price'] * $quantity);
        return [
            'channel' => $resolved['channel']['code'],
            'channel_name' => $resolved['channel']['name'],
            'quality' => $resolved['spec']['quality'],
            'quality_label' => $resolved['spec']['quality_label'],
            'ratio' => $resolved['spec']['ratio'],
            'width' => (int)$resolved['spec']['width'],
            'height' => (int)$resolved['spec']['height'],
            'quantity' => $quantity,
            'platform_unit_cost' => self::formatPoints((float)$resolved['spec']['platform_unit_cost']),
            'tenant_unit_price' => self::formatPoints((float)$resolved['spec']['tenant_unit_price']),
            'tenant_cost_points' => $tenantCost,
            'user_charge_points' => $userCharge,
        ];
    }

    public static function resolveSelection(int $tenantId, array $params): array
    {
        $channels = self::effectiveChannels($tenantId, true);
        if (empty($channels)) {
            throw new Exception('暂无可用生图通道');
        }
        $defaults = self::defaults($channels);
        $channelCode = (string)($params['channel'] ?? $defaults['channel']);
        $quality = (string)($params['quality'] ?? $defaults['quality']);
        $ratio = (string)($params['ratio'] ?? $defaults['ratio']);

        foreach ($channels as $channel) {
            if ($channel['code'] !== $channelCode) {
                continue;
            }
            foreach ($channel['qualities'] as $qualityItem) {
                if ($qualityItem['value'] !== $quality) {
                    continue;
                }
                foreach ($qualityItem['ratios'] as $ratioItem) {
                    if ($ratioItem['value'] === $ratio) {
                        return [
                            'channel' => $channel,
                            'spec' => $ratioItem,
                        ];
                    }
                }
                throw new Exception('当前分辨率不支持所选比例');
            }
            throw new Exception('当前通道不支持所选分辨率');
        }
        throw new Exception('生图通道不可用');
    }

    public static function platformLists(): array
    {
        $channels = AigcImageChannel::where('tenant_id', 0)->order(['sort' => 'desc', 'id' => 'asc'])->select()->toArray();
        $specs = AigcImageChannelSpec::where('tenant_id', 0)->order(['sort' => 'desc', 'id' => 'asc'])->select()->toArray();
        $grouped = [];
        foreach ($specs as $spec) {
            $grouped[$spec['channel_code']][] = $spec;
        }
        foreach ($channels as &$channel) {
            $channel['config_json'] = self::maskSecretConfig($channel['config_json'] ?? []);
            $channel['specs'] = $grouped[$channel['code']] ?? [];
        }
        return $channels;
    }

    public static function savePlatform(array $params): void
    {
        if (isset($params['quality']) || isset($params['ratio']) || ($params['type'] ?? '') === 'spec') {
            self::savePlatformSpec($params);
            return;
        }
        $code = self::normalizeCode((string)($params['code'] ?? ''));
        $data = [
            'tenant_id' => 0,
            'code' => $code,
            'name' => trim((string)($params['name'] ?? '')),
            'provider' => trim((string)($params['provider'] ?? 'mock')) ?: 'mock',
            'model' => trim((string)($params['model'] ?? 'mock-image')) ?: 'mock-image',
            'max_reference_images' => max(0, (int)($params['max_reference_images'] ?? self::DEFAULT_REFERENCE_LIMIT)),
            'config_json' => self::normalizePlatformChannelConfig($params['config_json'] ?? [], AigcImageChannel::where(['tenant_id' => 0, 'code' => $code])->value('config_json') ?: []),
            'status' => (int)($params['status'] ?? 1),
            'sort' => (int)($params['sort'] ?? 0),
            'update_time' => time(),
        ];
        if ($data['name'] === '') {
            throw new Exception('请输入通道名称');
        }
        self::updateBuiltInRow(AigcImageChannel::class, ['tenant_id' => 0, 'code' => $code], $data, '内置通道不存在');
    }

    public static function savePlatformSpec(array $params): void
    {
        $channelCode = self::normalizeCode((string)($params['channel_code'] ?? $params['code'] ?? ''));
        self::assertPlatformChannel($channelCode);
        $quality = strtolower(trim((string)($params['quality'] ?? '')));
        $ratio = trim((string)($params['ratio'] ?? ''));
        if ($quality === '' || $ratio === '') {
            throw new Exception('请选择分辨率和比例');
        }
        $data = [
            'tenant_id' => 0,
            'channel_code' => $channelCode,
            'quality' => $quality,
            'quality_label' => trim((string)($params['quality_label'] ?? strtoupper($quality))),
            'ratio' => $ratio,
            'width' => max(0, (int)($params['width'] ?? 0)),
            'height' => max(0, (int)($params['height'] ?? 0)),
            'platform_unit_cost' => self::formatPoints((float)($params['platform_unit_cost'] ?? 0)),
            'tenant_unit_price' => self::formatPoints((float)($params['tenant_unit_price'] ?? $params['platform_unit_cost'] ?? 0)),
            'provider_params_json' => self::normalizeJson($params['provider_params_json'] ?? []),
            'status' => (int)($params['status'] ?? 1),
            'sort' => (int)($params['sort'] ?? 0),
            'update_time' => time(),
        ];
        self::updateBuiltInRow(AigcImageChannelSpec::class, [
            'tenant_id' => 0,
            'channel_code' => $channelCode,
            'quality' => $quality,
            'ratio' => $ratio,
        ], $data, '内置规格不存在');
    }

    public static function deletePlatform(array $params): void
    {
        throw new Exception('内置通道和规格不允许删除');
    }

    public static function statusPlatform(array $params): void
    {
        $model = (($params['type'] ?? '') === 'spec') ? AigcImageChannelSpec::class : AigcImageChannel::class;
        $row = $model::where(['tenant_id' => 0, 'id' => (int)($params['id'] ?? 0)])->findOrEmpty();
        if ($row->isEmpty()) {
            throw new Exception('记录不存在');
        }
        $row->save([
            'status' => (int)($params['status'] ?? 1),
            'update_time' => time(),
        ]);
    }

    public static function tenantLists(int $tenantId): array
    {
        return self::sanitizeChannels(self::effectiveChannels($tenantId, false), true);
    }

    public static function saveTenant(int $tenantId, array $params): void
    {
        if (isset($params['quality']) || isset($params['ratio']) || ($params['type'] ?? '') === 'spec') {
            self::saveTenantSpec($tenantId, $params);
            return;
        }
        $code = self::normalizeCode((string)($params['code'] ?? ''));
        $platform = self::assertPlatformChannel($code);
        $data = [
            'tenant_id' => $tenantId,
            'code' => $code,
            'name' => trim((string)($params['name'] ?? $platform['name'])),
            'provider' => $platform['provider'],
            'model' => $platform['model'],
            'max_reference_images' => (int)$platform['max_reference_images'],
            'config_json' => [],
            'status' => (int)($params['status'] ?? 1),
            'sort' => (int)($params['sort'] ?? $platform['sort']),
            'update_time' => time(),
        ];
        self::saveRow(AigcImageChannel::class, ['tenant_id' => $tenantId, 'code' => $code], $data);
    }

    public static function saveTenantSpec(int $tenantId, array $params): void
    {
        $channelCode = self::normalizeCode((string)($params['channel_code'] ?? $params['code'] ?? ''));
        $quality = strtolower(trim((string)($params['quality'] ?? '')));
        $ratio = trim((string)($params['ratio'] ?? ''));
        $platform = AigcImageChannelSpec::where([
            'tenant_id' => 0,
            'channel_code' => $channelCode,
            'quality' => $quality,
            'ratio' => $ratio,
        ])->findOrEmpty();
        if ($platform->isEmpty()) {
            throw new Exception('平台未开放该规格');
        }
        $data = [
            'tenant_id' => $tenantId,
            'channel_code' => $channelCode,
            'quality' => $quality,
            'quality_label' => $platform['quality_label'],
            'ratio' => $ratio,
            'width' => (int)$platform['width'],
            'height' => (int)$platform['height'],
            'platform_unit_cost' => (string)$platform['platform_unit_cost'],
            'tenant_unit_price' => self::formatPoints((float)($params['tenant_unit_price'] ?? $platform['tenant_unit_price'] ?? $platform['platform_unit_cost'])),
            'provider_params_json' => [],
            'status' => (int)($params['status'] ?? 1),
            'sort' => (int)($params['sort'] ?? $platform['sort']),
            'update_time' => time(),
        ];
        self::saveRow(AigcImageChannelSpec::class, [
            'tenant_id' => $tenantId,
            'channel_code' => $channelCode,
            'quality' => $quality,
            'ratio' => $ratio,
        ], $data);
    }

    private static function effectiveChannels(int $tenantId, bool $onlyEnabled): array
    {
        $platformChannels = AigcImageChannel::where('tenant_id', 0)->order(['sort' => 'desc', 'id' => 'asc'])->select()->toArray();
        $tenantChannels = $tenantId > 0 ? AigcImageChannel::where('tenant_id', $tenantId)->select()->toArray() : [];
        $tenantChannelMap = array_column($tenantChannels, null, 'code');

        $platformSpecs = AigcImageChannelSpec::where('tenant_id', 0)->order(['sort' => 'desc', 'id' => 'asc'])->select()->toArray();
        $tenantSpecs = $tenantId > 0 ? AigcImageChannelSpec::where('tenant_id', $tenantId)->select()->toArray() : [];
        $tenantSpecMap = [];
        foreach ($tenantSpecs as $spec) {
            $tenantSpecMap[self::specKey($spec)] = $spec;
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
                'max_reference_images' => (int)$platformChannel['max_reference_images'],
                'status' => $status,
                'platform_status' => (int)$platformChannel['status'],
                'tenant_status' => (int)($override['status'] ?? 1),
                'sort' => (int)($override['sort'] ?? $platformChannel['sort']),
                'config_json' => $platformChannel['config_json'] ?? [],
                'quantity_options' => self::channelQuantityOptions($platformChannel),
                'qualities' => [],
                'specs' => [],
            ];
            foreach ($specsByChannel[$platformChannel['code']] ?? [] as $platformSpec) {
                $tenantSpec = $tenantSpecMap[self::specKey($platformSpec)] ?? [];
                $specStatus = (int)$platformSpec['status'] === 1 && (int)($tenantSpec['status'] ?? 1) === 1 ? 1 : 0;
                if ($onlyEnabled && !$specStatus) {
                    continue;
                }
                $spec = [
                    'id' => (int)$platformSpec['id'],
                    'tenant_override_id' => (int)($tenantSpec['id'] ?? 0),
                    'channel_code' => $platformSpec['channel_code'],
                    'quality' => $platformSpec['quality'],
                    'value' => $platformSpec['ratio'],
                    'label' => $platformSpec['ratio'],
                    'quality_label' => $platformSpec['quality_label'],
                    'ratio' => $platformSpec['ratio'],
                    'width' => (int)$platformSpec['width'],
                    'height' => (int)$platformSpec['height'],
                    'platform_unit_cost' => self::formatPoints((float)$platformSpec['platform_unit_cost']),
                    'tenant_unit_price' => self::formatPoints((float)($tenantSpec['tenant_unit_price'] ?? $platformSpec['tenant_unit_price'] ?? $platformSpec['platform_unit_cost'])),
                    'provider_params_json' => $platformSpec['provider_params_json'] ?? [],
                    'status' => $specStatus,
                    'platform_status' => (int)$platformSpec['status'],
                    'tenant_status' => (int)($tenantSpec['status'] ?? 1),
                    'sort' => (int)($tenantSpec['sort'] ?? $platformSpec['sort']),
                ];
                $channel['specs'][] = $spec;
                $qualityKey = $spec['quality'];
                if (!isset($channel['qualities'][$qualityKey])) {
                    $channel['qualities'][$qualityKey] = [
                        'value' => $qualityKey,
                        'label' => $spec['quality_label'],
                        'ratios' => [],
                    ];
                }
                $channel['qualities'][$qualityKey]['ratios'][] = $spec;
            }
            $channel['qualities'] = array_values($channel['qualities']);
            if (!$onlyEnabled || !empty($channel['qualities'])) {
                $channels[] = $channel;
            }
        }
        return $channels;
    }

    private static function defaults(array $channels): array
    {
        $channel = $channels[0] ?? [];
        $quality = $channel['qualities'][0] ?? [];
        $ratio = $quality['ratios'][0] ?? [];
        return [
            'channel' => $channel['code'] ?? '',
            'quality' => $quality['value'] ?? '',
            'ratio' => $ratio['ratio'] ?? '',
            'quantity' => 1,
        ];
    }

    private static function maxReferenceImages(array $channels): int
    {
        $max = self::DEFAULT_REFERENCE_LIMIT;
        foreach ($channels as $channel) {
            $max = min($max, max(0, (int)$channel['max_reference_images']));
        }
        return $max;
    }

    private static function quantityOptions(array $channels): array
    {
        $options = [];
        foreach ($channels as $channel) {
            $options = array_merge($options, $channel['quantity_options'] ?? self::channelQuantityOptions($channel));
        }
        $options = array_values(array_unique(array_map('intval', $options)));
        sort($options);
        return $options ?: self::QUANTITY_OPTIONS;
    }

    private static function channelQuantityOptions(array $channel): array
    {
        $config = self::normalizeJson($channel['config_json'] ?? []);
        if (!empty($config['quantity_options']) && is_array($config['quantity_options'])) {
            $options = array_values(array_filter(array_map('intval', $config['quantity_options'])));
            return $options ?: self::QUANTITY_OPTIONS;
        }
        if (in_array((string)($channel['provider'] ?? ''), ['xhadmin', 'xhadmin_gpt_image_2', 'gpt_image_2_openaim'], true)) {
            return [1];
        }
        return self::QUANTITY_OPTIONS;
    }

    public static function normalizeQuantity($quantity): int
    {
        $quantity = (int)$quantity;
        if (!in_array($quantity, self::QUANTITY_OPTIONS, true)) {
            throw new Exception('生成数量不支持');
        }
        return $quantity;
    }

    public static function assertChannelQuantity(array $channel, int $quantity): void
    {
        $options = $channel['quantity_options'] ?? self::channelQuantityOptions($channel);
        if (!in_array($quantity, array_map('intval', $options), true)) {
            throw new Exception('当前通道不支持该生成数量');
        }
    }

    private static function assertPlatformChannel(string $code): array
    {
        $channel = AigcImageChannel::where(['tenant_id' => 0, 'code' => $code])->findOrEmpty();
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

    private static function mergeMaskedJson($value, $origin): array
    {
        $data = self::normalizeJson($value);
        $origin = self::normalizeJson($origin);
        foreach ($data as $key => $item) {
            if (is_string($item) && str_contains($item, '*') && array_key_exists($key, $origin)) {
                $data[$key] = $origin[$key];
            }
        }
        return $data;
    }

    private static function normalizePlatformChannelConfig($value, $origin): array
    {
        $data = self::mergeMaskedJson($value, $origin);
        foreach (['api_key', 'token', 'secret_key', 'access_key'] as $key) {
            unset($data[$key]);
        }
        return $data;
    }

    private static function maskSecretConfig(array $config): array
    {
        foreach (['api_key', 'token', 'secret_key', 'access_key'] as $key) {
            if (!empty($config[$key]) && is_string($config[$key])) {
                $config[$key] = '******' . substr($config[$key], -4);
            }
        }
        return $config;
    }

    private static function sanitizeChannels(array $channels, bool $forTenantAdmin): array
    {
        foreach ($channels as &$channel) {
            unset($channel['config_json']);
            if (!$forTenantAdmin) {
                unset($channel['provider'], $channel['model']);
            }
            foreach ($channel['specs'] as &$spec) {
                unset($spec['provider_params_json']);
            }
            foreach ($channel['qualities'] as &$quality) {
                foreach ($quality['ratios'] as &$ratio) {
                    unset($ratio['provider_params_json']);
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

    private static function updateBuiltInRow(string $modelClass, array $where, array $data, string $missingMessage): void
    {
        $row = $modelClass::where($where)->findOrEmpty();
        if ($row->isEmpty()) {
            throw new Exception($missingMessage);
        }
        $row->save($data);
    }

    private static function specKey(array $spec): string
    {
        return $spec['channel_code'] . '|' . $spec['quality'] . '|' . $spec['ratio'];
    }

    private static function formatPoints(float $points): string
    {
        return number_format($points, 2, '.', '');
    }
}
