<?php

namespace app\common\service\app\aigc_video;

use app\common\model\app\aigc_video\AigcVideoChannel;
use app\common\model\app\aigc_video\AigcVideoChannelSpec;
use app\common\service\app\ChannelSpecPricingSchemaService;
use Exception;
use think\facade\Db;

class AigcVideoChannelService
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
            'max_reference_assets' => self::maxReferenceAssets($channels),
        ];
    }

    public static function estimate(int $tenantId, array $params): array
    {
        $resolved = self::resolveSelection($tenantId, $params);
        $quantity = self::normalizeQuantity($params['quantity'] ?? 1);
        self::assertChannelQuantity($resolved['channel'], $quantity);
        $duration = self::normalizeGenerateDuration($resolved['channel'], AigcVideoReferenceAssetService::normalize($params), $params['duration'] ?? null);
        $billingMultiplier = self::billingMultiplier($resolved['channel'], $duration);
        $platformUnitCost = (float)$resolved['spec']['platform_unit_cost'] * $billingMultiplier;
        $tenantUnitPrice = (float)$resolved['spec']['tenant_unit_price'] * $billingMultiplier;
        $tenantCost = self::formatPoints($platformUnitCost * $quantity);
        $userCharge = self::formatPoints($tenantUnitPrice * $quantity);
        return [
            'channel' => $resolved['channel']['code'],
            'channel_name' => $resolved['channel']['name'],
            'quality' => $resolved['spec']['quality'],
            'quality_label' => $resolved['spec']['quality_label'],
            'ratio' => $resolved['spec']['ratio'],
            'width' => (int)$resolved['spec']['width'],
            'height' => (int)$resolved['spec']['height'],
            'quantity' => $quantity,
            'duration' => $duration,
            'platform_unit_cost' => self::formatPoints($platformUnitCost),
            'tenant_unit_price' => self::formatPoints($tenantUnitPrice),
            'tenant_cost_points' => $tenantCost,
            'user_charge_points' => $userCharge,
        ];
    }

    public static function resolveSelection(int $tenantId, array $params): array
    {
        $channels = self::effectiveChannels($tenantId, true);
        if (empty($channels)) {
            throw new Exception('暂无可用视频通道');
        }
        $defaults = self::defaults($channels);
        $channelCode = (string)($params['channel'] ?? $defaults['channel']);
        $quality = (string)($params['quality'] ?? $defaults['quality']);
        $ratio = (string)($params['ratio'] ?? $defaults['ratio']);
        $pricingVariant = '';

        foreach ($channels as $channel) {
            if ($channel['code'] !== $channelCode) {
                continue;
            }
            $pricingVariant = self::pricingVariantFromParams($channel['code'], $params);
            foreach ($channel['qualities'] as $qualityItem) {
                if (!self::qualityMatches($qualityItem, $quality)) {
                    continue;
                }
                foreach ($qualityItem['ratios'] as $ratioItem) {
                    if ($ratioItem['value'] === $ratio) {
                        $duration = self::normalizeDurationValue($params['duration'] ?? $defaults['duration'] ?? 0);
                        $matchedSpec = self::matchSpecForDuration($channel, $qualityItem, $ratio, $duration, $pricingVariant);
                        if (!empty($matchedSpec)) {
                            return [
                                'channel' => $channel,
                                'spec' => $matchedSpec,
                            ];
                        }
                        return [
                            'channel' => $channel,
                            'spec' => $ratioItem,
                        ];
                    }
                }
                throw new Exception('当前时长不支持所选比例');
            }
            throw new Exception('当前通道不支持所选时长');
        }
        throw new Exception('视频通道不可用');
    }

    public static function platformLists(): array
    {
        self::ensurePricingSchema();
        $channels = AigcVideoChannel::where('tenant_id', 0)->order(['sort' => 'desc', 'id' => 'asc'])->select()->toArray();
        $specs = AigcVideoChannelSpec::where('tenant_id', 0)->order(['sort' => 'desc', 'id' => 'asc'])->select()->toArray();
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
            'model' => trim((string)($params['model'] ?? 'mock-video')) ?: 'mock-video',
            'max_reference_images' => max(0, (int)($params['max_reference_images'] ?? self::DEFAULT_REFERENCE_LIMIT)),
            'config_json' => self::normalizePlatformChannelConfig($params['config_json'] ?? [], AigcVideoChannel::where(['tenant_id' => 0, 'code' => $code])->value('config_json') ?: []),
            'status' => (int)($params['status'] ?? 1),
            'sort' => (int)($params['sort'] ?? 0),
            'update_time' => time(),
        ];
        if ($data['name'] === '') {
            throw new Exception('请输入通道名称');
        }
        self::updateBuiltInRow(AigcVideoChannel::class, ['tenant_id' => 0, 'code' => $code], $data, '内置通道不存在');
    }

    public static function savePlatformSpec(array $params): void
    {
        self::ensurePricingSchema();
        $channelCode = self::normalizeCode((string)($params['channel_code'] ?? $params['code'] ?? ''));
        self::assertPlatformChannel($channelCode);
        $quality = strtolower(trim((string)($params['quality'] ?? '')));
        $ratio = trim((string)($params['ratio'] ?? ''));
        if ($quality === '' || $ratio === '') {
            throw new Exception('请选择视频时长和比例');
        }
        $upstreamUnitCost = array_key_exists('upstream_unit_cost', $params)
            ? (float)$params['upstream_unit_cost']
            : (float)self::currentPlatformSpecValue($channelCode, $quality, $ratio, 'upstream_unit_cost', 0);
        if ($upstreamUnitCost < 0) {
            throw new Exception('上游成本不能小于0');
        }
        $data = [
            'tenant_id' => 0,
            'channel_code' => $channelCode,
            'quality' => $quality,
            'quality_label' => trim((string)($params['quality_label'] ?? strtoupper($quality))),
            'ratio' => $ratio,
            'width' => max(0, (int)($params['width'] ?? 0)),
            'height' => max(0, (int)($params['height'] ?? 0)),
            'upstream_unit_cost' => self::formatPoints($upstreamUnitCost),
            'platform_unit_cost' => self::formatPoints((float)($params['platform_unit_cost'] ?? 0)),
            'tenant_unit_price' => self::formatPoints((float)($params['tenant_unit_price'] ?? $params['platform_unit_cost'] ?? 0)),
            'upstream_cost_text' => trim((string)($params['upstream_cost_text'] ?? '')),
            'cost_source_url' => trim((string)($params['cost_source_url'] ?? '')),
            'provider_params_json' => self::normalizeJson($params['provider_params_json'] ?? []),
            'status' => (int)($params['status'] ?? 1),
            'sort' => (int)($params['sort'] ?? 0),
            'update_time' => time(),
        ];
        self::updateBuiltInRow(AigcVideoChannelSpec::class, [
            'tenant_id' => 0,
            'channel_code' => $channelCode,
            'quality' => $quality,
            'ratio' => $ratio,
        ], $data, '内置规格不存在');
    }

    public static function batchSavePlatformSpecs(array $params): void
    {
        $specs = $params['specs'] ?? $params['items'] ?? [];
        if (!is_array($specs) || empty($specs)) {
            throw new Exception('请选择要保存的规格');
        }
        Db::transaction(function () use ($specs) {
            foreach ($specs as $spec) {
                if (!is_array($spec)) {
                    throw new Exception('规格参数格式错误');
                }
                self::updatePlatformSpecPatch($spec);
            }
        });
    }

    public static function deletePlatform(array $params): void
    {
        throw new Exception('内置通道和规格不允许删除');
    }

    public static function statusPlatform(array $params): void
    {
        $model = (($params['type'] ?? '') === 'spec') ? AigcVideoChannelSpec::class : AigcVideoChannel::class;
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
        self::saveRow(AigcVideoChannel::class, ['tenant_id' => $tenantId, 'code' => $code], $data);
    }

    public static function saveTenantSpec(int $tenantId, array $params): void
    {
        $channelCode = self::normalizeCode((string)($params['channel_code'] ?? $params['code'] ?? ''));
        $quality = strtolower(trim((string)($params['quality'] ?? '')));
        $ratio = trim((string)($params['ratio'] ?? ''));
        $platform = AigcVideoChannelSpec::where([
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
            'upstream_unit_cost' => (string)($platform['upstream_unit_cost'] ?? '0.00'),
            'platform_unit_cost' => (string)$platform['platform_unit_cost'],
            'tenant_unit_price' => self::formatPoints((float)($params['tenant_unit_price'] ?? $platform['tenant_unit_price'] ?? $platform['platform_unit_cost'])),
            'upstream_cost_text' => (string)($platform['upstream_cost_text'] ?? ''),
            'cost_source_url' => (string)($platform['cost_source_url'] ?? ''),
            'provider_params_json' => [],
            'status' => (int)($params['status'] ?? 1),
            'sort' => (int)($params['sort'] ?? $platform['sort']),
            'update_time' => time(),
        ];
        self::saveRow(AigcVideoChannelSpec::class, [
            'tenant_id' => $tenantId,
            'channel_code' => $channelCode,
            'quality' => $quality,
            'ratio' => $ratio,
        ], $data);
    }

    public static function batchSaveTenantSpecs(int $tenantId, array $params): void
    {
        $specs = $params['specs'] ?? $params['items'] ?? [];
        if (!is_array($specs) || empty($specs)) {
            throw new Exception('请选择要保存的规格');
        }
        Db::transaction(function () use ($tenantId, $specs) {
            foreach ($specs as $spec) {
                if (!is_array($spec)) {
                    throw new Exception('规格参数格式错误');
                }
                $price = $spec['tenant_unit_price'] ?? null;
                if ($price !== null && (float)$price < 0) {
                    throw new Exception('用户售价不能小于0');
                }
                $payload = [
                    'type' => 'spec',
                    'channel_code' => $spec['channel_code'] ?? '',
                    'quality' => $spec['quality'] ?? '',
                    'ratio' => $spec['ratio'] ?? '',
                    'tenant_unit_price' => $price,
                    'status' => $spec['status'] ?? $spec['tenant_status'] ?? 1,
                ];
                if (array_key_exists('sort', $spec)) {
                    $payload['sort'] = $spec['sort'];
                }
                self::saveTenantSpec($tenantId, $payload);
            }
        });
    }

    private static function effectiveChannels(int $tenantId, bool $onlyEnabled): array
    {
        self::ensurePricingSchema();
        $platformChannels = AigcVideoChannel::where('tenant_id', 0)->order(['sort' => 'desc', 'id' => 'asc'])->select()->toArray();
        $tenantChannels = $tenantId > 0 ? AigcVideoChannel::where('tenant_id', $tenantId)->select()->toArray() : [];
        $tenantChannelMap = array_column($tenantChannels, null, 'code');

        $platformSpecs = AigcVideoChannelSpec::where('tenant_id', 0)->order(['sort' => 'desc', 'id' => 'asc'])->select()->toArray();
        $tenantSpecs = $tenantId > 0 ? AigcVideoChannelSpec::where('tenant_id', $tenantId)->select()->toArray() : [];
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
            $config = self::normalizeJson($platformChannel['config_json'] ?? []);
            $channelSpecRows = $specsByChannel[$platformChannel['code']] ?? [];
            $configDurationOptions = self::channelDurationOptions($config);
            $specDurationOptions = self::durationOptionsFromSpecs($platformChannel['code'], $channelSpecRows);
            $durationOptions = !empty($configDurationOptions) ? $configDurationOptions : $specDurationOptions;
            $dynamicDuration = !empty($durationOptions);
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
                'config_json' => $config,
                'quantity_options' => self::channelQuantityOptions($platformChannel),
                'duration_options' => [],
                'videoedit_duration_options' => self::channelVideoEditDurationOptions($config),
                'supported_asset_types' => self::supportedAssetTypes($config),
                'max_reference_videos' => max(0, (int)($config['max_reference_videos'] ?? 0)),
                'max_reference_audios' => max(0, (int)($config['max_reference_audios'] ?? 0)),
                'max_reference_assets' => max(0, (int)($config['max_reference_assets'] ?? 0)),
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
                    'upstream_unit_cost' => self::formatPoints((float)($platformSpec['upstream_unit_cost'] ?? 0)),
                    'platform_unit_cost' => self::formatPoints((float)$platformSpec['platform_unit_cost']),
                    'tenant_unit_price' => self::formatPoints((float)($tenantSpec['tenant_unit_price'] ?? $platformSpec['tenant_unit_price'] ?? $platformSpec['platform_unit_cost'])),
                    'platform_gross_margin_points' => self::formatPoints((float)$platformSpec['platform_unit_cost'] - (float)($platformSpec['upstream_unit_cost'] ?? 0)),
                    'tenant_gross_margin_points' => self::formatPoints((float)($tenantSpec['tenant_unit_price'] ?? $platformSpec['tenant_unit_price'] ?? $platformSpec['platform_unit_cost']) - (float)$platformSpec['platform_unit_cost']),
                    'upstream_cost_text' => (string)($platformSpec['upstream_cost_text'] ?? ''),
                    'cost_source_url' => (string)($platformSpec['cost_source_url'] ?? ''),
                    'provider_params_json' => $platformSpec['provider_params_json'] ?? [],
                    'status' => $specStatus,
                    'platform_status' => (int)$platformSpec['status'],
                    'tenant_status' => (int)($tenantSpec['status'] ?? 1),
                    'sort' => (int)($tenantSpec['sort'] ?? $platformSpec['sort']),
                ];
                $spec = array_merge($spec, self::specPresentation($platformSpec));
                $channel['specs'][] = $spec;
                $qualityKey = $dynamicDuration
                    ? strtolower($spec['resolution'] ?: $spec['quality'])
                    : $spec['quality'];
                if (!isset($channel['qualities'][$qualityKey])) {
                    $qualityValue = $dynamicDuration ? ($spec['resolution'] ?: $spec['quality']) : $spec['quality'];
                    $channel['qualities'][$qualityKey] = [
                        'value' => $qualityValue,
                        'label' => $dynamicDuration ? ($spec['resolution'] ?: $spec['quality_label']) : $spec['quality_label'],
                        'resolution' => $spec['resolution'],
                        'duration' => $dynamicDuration ? '' : $spec['duration'],
                        'ratios' => [],
                    ];
                }
                $ratioExists = false;
                foreach ($channel['qualities'][$qualityKey]['ratios'] as $existingRatio) {
                    if (($existingRatio['value'] ?? '') === $spec['value']) {
                        $ratioExists = true;
                        break;
                    }
                }
                if (!$ratioExists) {
                    $channel['qualities'][$qualityKey]['ratios'][] = $spec;
                }
            }
            $channel['duration_options'] = $durationOptions;
            if (!empty($channel['videoedit_duration_options'])) {
                $channel['videoedit_duration_options'] = array_values(array_intersect(
                    self::channelVideoEditDurationOptions($config),
                    $channel['duration_options']
                ));
            }
            $channel['qualities'] = array_values($channel['qualities']);
            if (!$onlyEnabled || !empty($channel['qualities'])) {
                $channels[] = $channel;
            }
        }
        return $channels;
    }

    private static function updatePlatformSpecPatch(array $params): void
    {
        self::ensurePricingSchema();
        $channelCode = self::normalizeCode((string)($params['channel_code'] ?? $params['code'] ?? ''));
        self::assertPlatformChannel($channelCode);
        $quality = strtolower(trim((string)($params['quality'] ?? '')));
        $ratio = trim((string)($params['ratio'] ?? ''));
        if ($quality === '' || $ratio === '') {
            throw new Exception('请选择视频时长和比例');
        }
        $row = AigcVideoChannelSpec::where([
            'tenant_id' => 0,
            'channel_code' => $channelCode,
            'quality' => $quality,
            'ratio' => $ratio,
        ])->findOrEmpty();
        if ($row->isEmpty()) {
            throw new Exception('内置规格不存在');
        }
        $data = ['update_time' => time()];
        if (array_key_exists('platform_unit_cost', $params)) {
            if ((float)$params['platform_unit_cost'] < 0) {
                throw new Exception('平台定价不能小于0');
            }
            $data['platform_unit_cost'] = self::formatPoints((float)$params['platform_unit_cost']);
        }
        if (array_key_exists('upstream_unit_cost', $params)) {
            if ((float)$params['upstream_unit_cost'] < 0) {
                throw new Exception('上游成本不能小于0');
            }
            $data['upstream_unit_cost'] = self::formatPoints((float)$params['upstream_unit_cost']);
        }
        if (array_key_exists('upstream_cost_text', $params)) {
            $data['upstream_cost_text'] = trim((string)$params['upstream_cost_text']);
        }
        if (array_key_exists('cost_source_url', $params)) {
            $data['cost_source_url'] = trim((string)$params['cost_source_url']);
        }
        if (array_key_exists('status', $params)) {
            $data['status'] = (int)$params['status'] === 1 ? 1 : 0;
        }
        if (array_key_exists('sort', $params)) {
            $data['sort'] = (int)$params['sort'];
        }
        if (count($data) === 1) {
            throw new Exception('没有可保存的规格内容');
        }
        $row->save($data);
    }

    private static function currentPlatformSpecValue(string $channelCode, string $quality, string $ratio, string $field, $default = null)
    {
        if ($channelCode === '' || $quality === '' || $ratio === '') {
            return $default;
        }
        $row = AigcVideoChannelSpec::where([
            'tenant_id' => 0,
            'channel_code' => $channelCode,
            'quality' => $quality,
            'ratio' => $ratio,
        ])->findOrEmpty();
        if ($row->isEmpty()) {
            return $default;
        }
        return $row[$field] ?? $default;
    }

    private static function defaults(array $channels): array
    {
        $channel = $channels[0] ?? [];
        foreach ($channels as $candidate) {
            if (($candidate['code'] ?? '') === 'happy_horse') {
                $channel = $candidate;
                break;
            }
        }
        $quality = $channel['qualities'][0] ?? [];
        $ratio = $quality['ratios'][0] ?? [];
        return [
            'channel' => $channel['code'] ?? '',
            'quality' => $quality['value'] ?? '',
            'ratio' => $ratio['ratio'] ?? '',
            'duration' => $channel['duration_options'][0] ?? 0,
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

    private static function maxReferenceAssets(array $channels): int
    {
        $max = self::DEFAULT_REFERENCE_LIMIT;
        foreach ($channels as $channel) {
            $channelMax = (int)($channel['max_reference_assets'] ?? 0);
            if ($channelMax <= 0) {
                $channelMax = (int)$channel['max_reference_images']
                    + (int)($channel['max_reference_videos'] ?? 0)
                    + (int)($channel['max_reference_audios'] ?? 0);
            }
            $max = min($max, max(0, $channelMax));
        }
        return $max;
    }

    private static function supportedAssetTypes(array $config): array
    {
        $types = $config['supported_asset_types'] ?? ['image'];
        if (!is_array($types)) {
            $types = ['image'];
        }
        $types = array_values(array_unique(array_filter(array_map(static function ($type) {
            $type = strtolower(trim((string)$type));
            return in_array($type, ['image', 'video', 'audio'], true) ? $type : '';
        }, $types))));
        return $types ?: ['image'];
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
        return self::QUANTITY_OPTIONS;
    }

    private static function channelVideoEditDurationOptions(array $config): array
    {
        if (!empty($config['videoedit_duration_options']) && is_array($config['videoedit_duration_options'])) {
            $options = array_values(array_unique(array_filter(array_map('intval', $config['videoedit_duration_options']))));
            sort($options);
            return $options;
        }
        return [];
    }

    private static function channelDurationOptions(array $config): array
    {
        if (!empty($config['duration_options']) && is_array($config['duration_options'])) {
            $options = array_values(array_unique(array_filter(array_map('intval', $config['duration_options']))));
            sort($options);
            return $options;
        }
        return [];
    }

    private static function durationOptionsFromSpecs(string $channelCode, array $specs): array
    {
        if (self::isSecondBillingChannel($channelCode)) {
            return [];
        }
        $options = [];
        foreach ($specs as $spec) {
            $duration = self::explicitSpecDurationValue($spec);
            if ($duration > 0) {
                $options[] = $duration;
            }
        }
        $options = array_values(array_unique($options));
        sort($options);
        return $options;
    }

    private static function matchSpecForDuration(array $channel, array $qualityItem, string $ratio, int $duration, string $pricingVariant = ''): array
    {
        if (empty($channel['duration_options']) || $duration <= 0) {
            return [];
        }
        $resolution = self::normalizeResolution($qualityItem['resolution'] ?? $qualityItem['value'] ?? '');
        $fallbackSpec = [];
        foreach ($channel['specs'] as $spec) {
            if (($spec['ratio'] ?? '') !== $ratio) {
                continue;
            }
            if (self::normalizeResolution($spec['resolution'] ?? '') !== $resolution) {
                continue;
            }
            if (!self::specMatchesPricingVariant($spec, $pricingVariant)) {
                continue;
            }
            $specDuration = self::explicitSpecDurationValue($spec);
            if ($specDuration === $duration) {
                return $spec;
            }
            if ($specDuration <= 0 && empty($fallbackSpec)) {
                $fallbackSpec = $spec;
            }
        }
        if (!empty($fallbackSpec)) {
            return $fallbackSpec;
        }
        throw new Exception('当前通道不支持所选时长');
    }

    private static function pricingVariantFromParams(string $channelCode, array $params): string
    {
        if ($channelCode !== 'seedance') {
            return '';
        }
        $explicit = strtolower(trim((string)($params['_pricing_variant'] ?? $params['pricing_variant'] ?? '')));
        if (in_array($explicit, ['with_video', 'without_video'], true)) {
            return $explicit;
        }
        foreach (AigcVideoReferenceAssetService::normalize($params) as $asset) {
            if (($asset['type'] ?? '') === 'video') {
                return 'with_video';
            }
        }
        return 'without_video';
    }

    private static function specMatchesPricingVariant(array $spec, string $pricingVariant): bool
    {
        if ($pricingVariant === '') {
            return true;
        }
        $params = self::normalizeJson($spec['provider_params_json'] ?? []);
        $specVariant = strtolower(trim((string)($params['_pricing_variant'] ?? $params['pricing_variant'] ?? '')));
        return $specVariant === '' || $specVariant === $pricingVariant;
    }

    private static function qualityMatches(array $qualityItem, string $quality): bool
    {
        if ((string)($qualityItem['value'] ?? '') === $quality) {
            return true;
        }
        $selectedResolution = self::normalizeResolution($quality);
        if ($selectedResolution !== '' && $selectedResolution === self::normalizeResolution($qualityItem['resolution'] ?? '')) {
            return true;
        }
        $selectedDuration = self::normalizeDurationValue($quality);
        if ($selectedDuration > 0 && $selectedDuration === self::normalizeDurationValue($qualityItem['duration'] ?? '')) {
            return true;
        }
        return false;
    }

    private static function normalizeDurationValue($value): int
    {
        if (is_numeric($value)) {
            return max(0, (int)$value);
        }
        $duration = self::normalizeDuration($value);
        return (int)($duration ? preg_replace('/\D+/', '', $duration) : 0);
    }

    private static function explicitSpecDurationValue(array $spec): int
    {
        $providerParams = self::normalizeJson($spec['provider_params_json'] ?? []);
        if (array_key_exists('duration', $providerParams)) {
            return self::normalizeDurationValue($providerParams['duration']);
        }
        if (array_key_exists('duration', $spec)) {
            return self::normalizeDurationValue($spec['duration']);
        }
        return self::normalizeExplicitDurationText($spec['quality'] ?? $spec['quality_label'] ?? '');
    }

    private static function normalizeExplicitDurationText($value): int
    {
        $raw = trim((string)$value);
        if ($raw === '') {
            return 0;
        }
        if (preg_match('/(\d+)\s*(?:s|秒)\b/i', $raw, $matched)) {
            return (int)$matched[1];
        }
        if (preg_match('/_(\d+)(?:\D*)$/', $raw, $matched)) {
            return (int)$matched[1];
        }
        if (preg_match('/^\d+$/', $raw)) {
            return (int)$raw;
        }
        return 0;
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

    public static function normalizeGenerateDuration(array $channel, array $assets, $duration = null): int
    {
        $options = self::durationOptionsForAssets($channel, $assets);
        $value = (int)($duration ?? 0);
        if ($value <= 0) {
            return $options[0] ?? 5;
        }
        if (!in_array($value, $options, true)) {
            $min = $options[0] ?? 0;
            $max = $options ? $options[count($options) - 1] : 0;
            $message = $min === $max ? "{$min}秒" : "{$min}-{$max}秒";
            throw new Exception('当前通道不支持该视频时长，请选择' . $message);
        }
        return $value;
    }

    public static function durationOptionsForAssets(array $channel, array $assets = []): array
    {
        $hasVideo = false;
        foreach ($assets as $asset) {
            if (($asset['type'] ?? '') === 'video') {
                $hasVideo = true;
                break;
            }
        }
        $options = $hasVideo && !empty($channel['videoedit_duration_options'])
            ? $channel['videoedit_duration_options']
            : ($channel['duration_options'] ?? []);
        $options = array_values(array_unique(array_filter(array_map('intval', (array)$options))));
        sort($options);
        return $options ?: [5];
    }

    private static function billingMultiplier(array $channel, int $duration): float
    {
        return self::isSecondBillingChannel((string)($channel['code'] ?? '')) ? max(1, $duration) : 1;
    }

    private static function isSecondBillingChannel(string $channelCode): bool
    {
        return in_array($channelCode, ['happy_horse', 'wan'], true);
    }

    private static function assertPlatformChannel(string $code): array
    {
        $channel = AigcVideoChannel::where(['tenant_id' => 0, 'code' => $code])->findOrEmpty();
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
                foreach ($channel['specs'] as &$spec) {
                    unset($spec['provider_params_json']);
                }
                foreach ($channel['qualities'] as &$quality) {
                    foreach ($quality['ratios'] as &$ratio) {
                        unset($ratio['provider_params_json']);
                    }
                }
            }
        }
        return $channels;
    }

    private static function specPresentation(array $spec): array
    {
        $params = self::normalizeJson($spec['provider_params_json'] ?? []);
        $resolution = self::normalizeResolution($params['resolution'] ?? $params['size'] ?? $spec['quality_label'] ?? $spec['quality'] ?? '');
        $duration = self::normalizeDuration($params['duration'] ?? $spec['quality_label'] ?? $spec['quality'] ?? '');
        return [
            'resolution' => $resolution,
            'duration' => $duration,
        ];
    }

    private static function normalizeResolution($value): string
    {
        $raw = strtoupper(trim((string)$value));
        if (preg_match('/(1080P|720P|480P|4K|2K|1K|\d+K)/', $raw, $matches)) {
            return $matches[1];
        }
        return '';
    }

    private static function normalizeDuration($value): string
    {
        $raw = trim((string)$value);
        if (preg_match('/(?:^|_|\s|·)(\d+)\s*(?:S|秒)(?:$|[^\w])/i', $raw, $matches)
            || preg_match('/(?:^|_|\s|·)(\d+)(?:$|_|\s|·)/i', $raw, $matches)
        ) {
            return ((int)$matches[1]) . '秒';
        }
        return '';
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

    private static function ensurePricingSchema(): void
    {
        ChannelSpecPricingSchemaService::ensure('aigc_video_channel_spec');
    }

    private static function formatPoints(float $points): string
    {
        $value = rtrim(rtrim(number_format($points, 4, '.', ''), '0'), '.');
        return $value === '' ? '0' : $value;
    }
}
